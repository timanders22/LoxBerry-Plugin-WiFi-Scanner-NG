#!/usr/bin/perl

# Copyright 2026
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.

##########################################################################
# MQTT command listener for the WifiScanner plugin
#
# Subscribes to wifi_ng/cmd/# and allows controlling the plugin
# from Loxone (via the LoxBerry MQTT Gateway):
#
#   wifi_ng/cmd/scan      -> trigger an immediate scan
#                                payload optional: mode override (see below)
#   wifi_ng/cmd/mode      -> set the query mode (persisted in config)
#                                0|both     = Fritzbox + active scan
#                                1|fritzbox = Fritzbox query only
#                                2|ping     = active scan (arping/ping) only
#   wifi_ng/cmd/interval  -> set scan interval in minutes (1,3,5,10,15,30,60)
#   wifi_ng/cmd/enable    -> 0/1 enable or disable periodic scanning
#
# Current state is published retained to wifi_ng/status/#
##########################################################################

use LoxBerry::System;
use LoxBerry::Log;
use LoxBerry::IO;
use Net::MQTT::Simple;
use Config::Simple;
use strict;
use warnings;

# Name used for the cron symlinks (must match webfrontend/htmlauth/index.cgi)
my $pname = "wifi_scanner";

my $cfgfile = "$lbpconfigdir/wifi_scanner.cfg";

my $log = LoxBerry::Log->new ( name => 'mqtt_listener' , addtime => 1, );
LOGSTART "WifiScanner MQTT listener starting";

# Allow unencrypted connection with credentials
$ENV{MQTT_SIMPLE_ALLOW_INSECURE_LOGIN} = 1;

my $mqttcred = LoxBerry::IO::mqtt_connectiondetails();
if (!$mqttcred) {
    LOGCRIT "No MQTT Gateway configured on this LoxBerry - listener exits.";
    exit 1;
}

my $mqtt = Net::MQTT::Simple->new($mqttcred->{brokeraddress});
if ($mqttcred->{brokeruser} and $mqttcred->{brokerpass}) {
    $mqtt->login($mqttcred->{brokeruser}, $mqttcred->{brokerpass});
}

LOGINF "Connected to MQTT broker $mqttcred->{brokeraddress}, subscribing wifi_ng/cmd/#";

publish_status();

$mqtt->run(
    "wifi_ng/cmd/#" => \&handle_command,
);

LOGEND "WifiScanner MQTT listener stopped";
exit 0;

##########################################################################
# Command handling
##########################################################################

sub handle_command
{
    my ($topic, $payload) = @_;
    $payload = "" if (!defined $payload);
    $payload =~ s/^\s+|\s+$//g;

    my ($cmd) = $topic =~ m{^wifi_ng/cmd/(.+)$};
    return if (!$cmd);

    LOGINF "Received command '$cmd' with payload '$payload'";

    if ($cmd eq "scan") {
        trigger_scan($payload);
    }
    elsif ($cmd eq "mode") {
        my ($fritz, $active) = parse_mode($payload);
        if (defined $fritz) {
            my $cfg = new Config::Simple($cfgfile);
            $cfg->param("BASE.FRITZBOX_ENABLE", $fritz);
            $cfg->param("BASE.ACTIVE_SCAN", $active);
            cfg_speichern($cfg);
            LOGOK "Mode set: FRITZBOX_ENABLE=$fritz ACTIVE_SCAN=$active";
            publish_status();
            trigger_scan("");
        } else {
            LOGERR "Unknown mode '$payload' - allowed: 0/both, 1/fritzbox, 2/ping";
        }
    }
    elsif ($cmd eq "interval") {
        if ($payload =~ /^(1|3|5|10|15|30|60)$/) {
            my $cfg = new Config::Simple($cfgfile);
            $cfg->param("BASE.CRON", $payload);
            cfg_speichern($cfg);
            update_cron($cfg->param("BASE.ENABLED"), $payload);
            LOGOK "Scan interval set to $payload minute(s)";
            publish_status();
        } else {
            LOGERR "Invalid interval '$payload' - allowed: 1,3,5,10,15,30,60";
        }
    }
    elsif ($cmd eq "enable") {
        if ($payload =~ /^(0|1)$/) {
            my $cfg = new Config::Simple($cfgfile);
            $cfg->param("BASE.ENABLED", $payload);
            cfg_speichern($cfg);
            update_cron($payload, $cfg->param("BASE.CRON"));
            LOGOK "Periodic scanning " . ($payload ? "enabled" : "disabled");
            publish_status();
        } else {
            LOGERR "Invalid enable payload '$payload' - allowed: 0 or 1";
        }
    }
    else {
        LOGERR "Unknown command '$cmd'";
    }
}

# Returns (FRITZBOX_ENABLE, ACTIVE_SCAN) or (undef, undef)
sub parse_mode
{
    my ($mode) = @_;
    return (1, 1) if ($mode =~ /^(0|both|all|full)$/i);
    return (1, 0) if ($mode =~ /^(1|fritz|fritzbox)$/i);
    return (0, 1) if ($mode =~ /^(2|ping|scan|active)$/i);
    return (undef, undef);
}

# ---------------------------------------------------------------------------
# Die Konfiguration unteilbar speichern.
#
# $cfg->save() schreibt unmittelbar in wifi_scanner.cfg - kuerzen und neu
# fuellen. Faellt genau in dieses Fenster der Cron-Lauf von check.pl, liest
# der eine halbe oder leere Datei und arbeitet mit Vorgabewerten weiter.
#
# Config::Simple kann das Ziel selbst waehlen: erst in eine Nebendatei
# schreiben lassen, dann umbenennen. rename() ist im selben Dateisystem
# unteilbar - der Leser sieht entweder die alte oder die neue Datei.
# ---------------------------------------------------------------------------
sub cfg_speichern
{
    my ($cfg) = @_;
    my $tmp = "$cfgfile.tmp.$$";
    if (!$cfg->write($tmp)) {
        LOGERR "Konfiguration liess sich nicht schreiben: $tmp";
        return 0;
    }
    # Rechte der Zieldatei uebernehmen, sonst steht sie nachher mit den
    # Vorgaben der umask da.
    my @st = stat($cfgfile);
    chmod($st[2] & 07777, $tmp) if @st;
    if (!rename($tmp, $cfgfile)) {
        LOGERR "Konfiguration liess sich nicht umbenennen: $tmp";
        unlink($tmp);
        return 0;
    }
    return 1;
}

sub trigger_scan
{
    my ($mode) = @_;
    my $modearg = "";
    if ($mode ne "") {
        my ($fritz, $active) = parse_mode($mode);
        if (defined $fritz) {
            $modearg = "--mode $mode";
        } else {
            LOGERR "Ignoring unknown scan mode override '$mode'";
        }
    }
    LOGINF "Triggering scan $modearg";
    # Ohne diese Zeile bleibt nach jedem angestossenen Scan ein Zombie in der
    # Prozessliste stehen: der Vater ruft weder waitpid auf noch ignoriert er
    # das Kindsignal. Bei einem Dauerlaeufer, den man ueber MQTT beliebig oft
    # anstossen kann, summiert sich das.
    #
    # 'IGNORE' statt eines eigenen Handlers, weil hier nichts vom Ergebnis
    # des Kindes abhaengt - check.pl schreibt sein Ergebnis selbst weg.
    local $SIG{CHLD} = 'IGNORE';
    my $pid = fork();
    if (!defined $pid) {
        LOGERR "Fork failed: $!";
        return;
    }
    if ($pid == 0) {
        open STDIN,  "</dev/null";
        open STDOUT, ">/dev/null";
        open STDERR, ">/dev/null";
        exec("$lbpbindir/check.pl $modearg");
        exit 0;
    }
}

sub update_cron
{
    my ($enabled, $cron) = @_;
    $cron = 3 if (!$cron);
    $enabled = "0" if (!defined $enabled || $enabled eq "");

    # Unlink all existing Cronjobs
    unlink ("$lbhomedir/system/cron/cron.01min/$pname");
    unlink ("$lbhomedir/system/cron/cron.03min/$pname");
    unlink ("$lbhomedir/system/cron/cron.05min/$pname");
    unlink ("$lbhomedir/system/cron/cron.10min/$pname");
    unlink ("$lbhomedir/system/cron/cron.15min/$pname");
    unlink ("$lbhomedir/system/cron/cron.30min/$pname");
    unlink ("$lbhomedir/system/cron/cron.hourly/$pname");

    if ($enabled eq "1") {
        if ($cron == 60) {
            system ("ln -s $lbpbindir/check.pl $lbhomedir/system/cron/cron.hourly/$pname");
        } else {
            my $number = sprintf("%02d", $cron);
            system ("ln -s $lbpbindir/check.pl $lbhomedir/system/cron/cron.".$number."min/$pname");
        }
    }
}

sub publish_status
{
    my $cfg = new Config::Simple($cfgfile);
    my $fritz   = $cfg->param("BASE.FRITZBOX_ENABLE") // 0;
    my $active  = $cfg->param("BASE.ACTIVE_SCAN") // 0;
    my $cron    = $cfg->param("BASE.CRON") // "";
    my $enabled = $cfg->param("BASE.ENABLED") // 0;

    my $mode;
    if ($fritz and $active)  { $mode = 0; }
    elsif ($fritz)           { $mode = 1; }
    else                     { $mode = 2; }

    $mqtt->retain("wifi_ng/status/mode", $mode);
    $mqtt->retain("wifi_ng/status/interval", $cron);
    $mqtt->retain("wifi_ng/status/enabled", $enabled);
    LOGDEB "Published status: mode=$mode interval=$cron enabled=$enabled";
}
