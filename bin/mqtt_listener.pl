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

use strict;
use warnings;

use LoxBerry::System;
use LoxBerry::Log;
use LoxBerry::IO;
use Net::MQTT::Simple;
use Config::Simple;

# Name used for the cron symlinks - er muss mit ws_cron_apply() in
# webfrontend/html/ws_lib.php uebereinstimmen. (Bis 3.1.11 verwies dieser
# Kommentar auf webfrontend/htmlauth/index.cgi; die Datei gibt es seit 2.5
# nicht mehr, die Oberflaeche ist PHP.)
my $pname = "wifi_scanner";

my $cfgfile = "$lbpconfigdir/wifi_scanner.cfg";

my $log = LoxBerry::Log->new(name => 'mqtt_listener', addtime => 1);
LOGSTART "WifiScanner MQTT listener starting";

# Allow unencrypted connection with credentials
$ENV{MQTT_SIMPLE_ALLOW_INSECURE_LOGIN} = 1;

my $mqttcred = LoxBerry::IO::mqtt_connectiondetails();
if (!$mqttcred || !$mqttcred->{brokeraddress}) {
    LOGCRIT "No MQTT Gateway configured on this LoxBerry - listener exits.";
    LOGEND "Beendet.";
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
            my $cfg = cfg_lesen();
            return if (!$cfg);
            $cfg->param("BASE.FRITZBOX_ENABLE", $fritz);
            $cfg->param("BASE.ACTIVE_SCAN", $active);
            cfg_speichern($cfg) or return;
            LOGOK "Mode set: FRITZBOX_ENABLE=$fritz ACTIVE_SCAN=$active";
            publish_status();
            trigger_scan("");
        } else {
            LOGERR "Unknown mode '$payload' - allowed: 0/both, 1/fritzbox, 2/ping";
        }
    }
    elsif ($cmd eq "interval") {
        if ($payload =~ /^(1|3|5|10|15|30|60)$/) {
            my $cfg = cfg_lesen();
            return if (!$cfg);
            $cfg->param("BASE.CRON", $payload);
            cfg_speichern($cfg) or return;
            update_cron($cfg->param("BASE.ENABLED"), $payload);
            LOGOK "Scan interval set to $payload minute(s)";
            publish_status();
        } else {
            LOGERR "Invalid interval '$payload' - allowed: 1,3,5,10,15,30,60";
        }
    }
    elsif ($cmd eq "enable") {
        if ($payload =~ /^(0|1)$/) {
            my $cfg = cfg_lesen();
            return if (!$cfg);
            $cfg->param("BASE.ENABLED", $payload);
            cfg_speichern($cfg) or return;
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
# Die Konfiguration lesen - mit Pruefung.
#
# Config::Simple->new gibt undef zurueck, wenn die Datei fehlt oder nicht
# lesbar ist. Bis 3.1.11 wurde der Rueckgabewert an vier Stellen nicht
# geprueft; faellt der Aufruf genau in den Augenblick, in dem die Oberflaeche
# ihre Nebendatei umbenennt, starb der Dauerlaeufer an einem
# "Can't call method param on an undefined value" - und stand bis zum
# naechsten Neustart still.
# ---------------------------------------------------------------------------
sub cfg_lesen
{
    my $cfg = Config::Simple->new($cfgfile);
    if (!$cfg) {
        LOGERR "Konfiguration nicht lesbar: $cfgfile - Befehl wird uebergangen.";
        return undef;
    }
    return $cfg;
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
    # Vorgaben der umask da. Seit 3.1.12 steht ein Merkwort darin - eine
    # Konfiguration, die einen Augenblick lang fuer alle lesbar ist, ist
    # ein Leck, kein Schoenheitsfehler.
    my @st = stat($cfgfile);
    chmod(@st ? ($st[2] & 07777) : 0600, $tmp);
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
    my @arg = ();
    if (defined $mode && $mode ne "") {
        my ($fritz, $active) = parse_mode($mode);
        if (defined $fritz) {
            # $mode hat das verankerte Muster bestanden - trotzdem als
            # eigenes Argument, nicht in eine Befehlszeile eingesetzt.
            @arg = ('--mode', $mode);
        } else {
            LOGERR "Ignoring unknown scan mode override '$mode'";
        }
    }
    LOGINF "Triggering scan " . (@arg ? join(' ', @arg) : '(ohne Modus)');
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
        open(STDIN,  '<', '/dev/null');
        open(STDOUT, '>', '/dev/null');
        open(STDERR, '>', '/dev/null');
        # exec als LISTE, nicht als String: ein String mit Leerzeichen geht
        # durch /bin/sh, und ein Leerzeichen im Installationspfad zerlegte
        # den Aufruf.
        exec('perl', "$lbpbindir/check.pl", @arg);
        exit 1;
    }
}

# ---------------------------------------------------------------------------
# Die Cron-Verknuepfung setzen.
#
# Bis 3.1.11 stand hier siebenmal unlink und danach "ln -s" als Zeichenkette.
# Das ist der Rueckbau dessen, was ws_cron_apply() in der Oberflaeche
# ausdruecklich anders macht und dort in zehn Zeilen begruendet: der gewaehlte
# Takt wird UEBERSCHRIEBEN, nicht erst geloescht und neu angelegt. Faellt der
# System-Cron in das Fenster dazwischen, faellt der Lauf aus.
#
# Beide Stellen tun jetzt dasselbe. Ein Widerspruch in der eigenen
# Dokumentation ist eine Fehlerquelle.
# ---------------------------------------------------------------------------
sub update_cron
{
    my ($enabled, $cron) = @_;
    $cron = 3 if (!defined $cron || $cron !~ /^[0-9]+$/ || $cron <= 0);
    $enabled = "0" if (!defined $enabled || $enabled eq "");

    my $behalten = ($cron == 60) ? 'cron.hourly' : sprintf('cron.%02dmin', $cron);
    my @ordner = ('cron.01min', 'cron.03min', 'cron.05min', 'cron.10min',
                  'cron.15min', 'cron.30min', 'cron.hourly');

    foreach my $d (@ordner) {
        next if ("$enabled" eq "1" && $d eq $behalten);   # wird gleich ueberschrieben
        unlink("$lbhomedir/system/cron/$d/$pname");
    }
    return if ("$enabled" ne "1");

    my $ziel = "$lbhomedir/system/cron/$behalten/$pname";
    my $quelle = "$lbpbindir/check.pl";
    # "ln -sfn" ersetzt einen bestehenden Verweis unteilbar. Listenform, also
    # ohne Shell - ein Leerzeichen im Pfad zerlegte den Aufruf sonst.
    my $rc = system('ln', '-sfn', $quelle, $ziel);
    if ($rc != 0) {
        unlink($ziel);
        symlink($quelle, $ziel) or LOGERR "Cron-Verknuepfung nicht anlegbar: $ziel";
    }
    LOGDEB "Cron-Verknuepfung: $ziel";
}

sub publish_status
{
    my $cfg = cfg_lesen();
    return if (!$cfg);
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
    # Dass DIESER Prozess laeuft, ist die einzige Aussage, die er ueber sich
    # selbst treffen kann. Der Gegenwert - die 0, wenn er nicht mehr laeuft -
    # kommt aus check.pl, das alle paar Minuten nachsieht.
    $mqtt->retain("wifi_ng/status/listener", 1);
    LOGDEB "Published status: mode=$mode interval=$cron enabled=$enabled";
}
