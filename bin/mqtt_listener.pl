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
use Fcntl qw(:flock);

# Name used for the cron symlinks - er muss mit ws_cron_apply() in
# webfrontend/html/ws_lib.php uebereinstimmen. (Bis 3.1.11 verwies dieser
# Kommentar auf webfrontend/htmlauth/index.cgi; die Datei gibt es seit 2.5
# nicht mehr, die Oberflaeche ist PHP.)
my $pname = "wifi_scanner";

my $cfgfile = "$lbpconfigdir/wifi_scanner.cfg";

my $log = LoxBerry::Log->new(name => 'mqtt_listener', addtime => 1);
LOGSTART "WifiScanner MQTT listener starting";

# I4 (Durchgang 02.10.2026): Einzelinstanz-Sperre. daemon/daemon (Systemstart)
# und der Waechter in check.pl fragten bis 3.2.9 beide erst /proc und starteten
# dann - fielen sie zusammen, liefen zwei Listener, und jeder Befehl wirkte
# doppelt (Installer-Pruefer, Fall D1: 10 von 10 Runden). Ein zweites Exemplar
# endet jetzt hier still. Die Sperre liegt NEBEN dem Datenordner, den
# purge_installation loescht. Perl setzt FD_CLOEXEC: check.pl, das dieser
# Listener anstoesst, erbt sie nicht (Regeln: "Sperre vererbt sich an Kinder").
my $sperre;
my $sperrdatei = "$lbpdatadir.listener.lock";
if (!open($sperre, '>>', $sperrdatei)) {
    LOGWARN "Sperrdatei $sperrdatei nicht anlegbar - es wird ohne Einzelinstanz-Sperre gearbeitet.";
} elsif (!flock($sperre, LOCK_EX | LOCK_NB)) {
    LOGINF "Ein anderer MQTT-Listener dieses Plugins laeuft bereits - dieser beendet sich.";
    LOGEND "Beendet (zweites Exemplar).";
    exit 0;
}

# c1 (02.10.2026): nur bei MQTT. Steht der Uebertragungsweg auf UDP, wird der
# Listener nicht gebraucht und beendet sich, bevor er den Broker anspricht -
# gleich, wer ihn gestartet hat (Systemstart, Installation, Oberflaeche,
# Endpunkt, ein Aufruf von Hand). Bis 3.2.9 lief er auch bei UDP und sendete
# status/mode, /interval und /enabled retained. Dieselbe Lesart wie der
# Waechter in check.pl: nur "1" ist UDP; ist die Konfiguration nicht lesbar,
# geht es weiter wie bisher.
{
    my $c = Config::Simple->new($cfgfile);
    if ($c && skalar($c->param("BASE.UDP_ENABLE")) eq '1') {
        LOGINF "Der Uebertragungsweg steht auf UDP - der MQTT-Listener wird nicht gebraucht und beendet sich.";
        LOGEND "Beendet (Weg UDP).";
        exit 0;
    }
}

# C6: der zuletzt angenommene Befehl je Art (Wert, Zeit) - fuer "gleicher Wert
# binnen 60 s" (Entscheidung 19, X-7).
my %zuletzt = ();

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

##########################################################################
# Abonnieren und warten - mit erneuertem Abonnement nach jedem Wiederverbinden
#
# C10 (Durchgang 02.10.2026): bis 3.2.9 stand hier $mqtt->run(...). Net::MQTT::
# Simple 1.32-3LB, wie LoxBerry es mitliefert, verbindet nach einem Abriss
# selbst neu, abonniert aber nicht neu (am Gateway gemessen 16./17.09.2026,
# LoxBerry #1571; Pruefer mqtt Nr. 6, Fall E0). Der Listener lief danach taub
# weiter, und status/listener meldete 1.
#
# Jetzt eine eigene Schleife: aendert sich die Verbindung (neues Socket-Objekt
# oder neuer Zeitpunkt last_connect), wird das Abonnement erneuert -
# unabhaengig davon, ob die Bibliothek es selbst schon tut (dann kommt es
# doppelt an, was nichts schadet). Der Zustand geht alle 30 s nach
# data/plugins/<ordner>/listener.json; check.pl meldet status/listener=1 nur,
# wenn er frisch ist und "verbunden" und "abonniert" sagt.
##########################################################################

my %abos = ("wifi_ng/cmd/#" => \&handle_command);
my $abonniert_seit = 0;
my $herz_zuletzt = '';
$mqtt->subscribe(%abos);
$abonniert_seit = time() if ($mqtt->{socket});
my $gesehen = verbindungskennung();
publish_status();
herz();
my $herz_zeit = time();

while (1) {
    eval { $mqtt->tick(1); 1 } or LOGWARN "tick: $@";
    my $kennung = verbindungskennung();
    if ($mqtt->{socket} && $kennung ne $gesehen) {
        LOGWARN "Die Verbindung zum Broker wurde neu aufgebaut - das Abonnement wifi_ng/cmd/# wird erneuert.";
        $mqtt->subscribe(%abos);
        $abonniert_seit = time();
        $gesehen = verbindungskennung();
        publish_status();
        herz();
        $herz_zeit = time();
    }
    if (!$mqtt->{socket}) {
        $abonniert_seit = 0;
        select(undef, undef, undef, 0.5);
    }
    # Ein Wechsel (getrennt/verbunden) geht sofort hinaus, nicht erst nach 30 s.
    if (herz_stand() ne $herz_zuletzt) {
        herz();
        $herz_zeit = time();
    }
    if (time() - $herz_zeit >= 30) {
        herz();
        $herz_zeit = time();
    }
}

# Woran eine neue Verbindung zu erkennen ist: ein neues Socket-Objekt oder ein
# neuer Zeitpunkt der letzten Verbindung (beides Felder von Net::MQTT::Simple).
sub verbindungskennung
{
    my $s = $mqtt->{socket};
    return ($s ? "$s" : '') . '|' . (defined $mqtt->{last_connect} ? $mqtt->{last_connect} : '');
}

# Der Zustand, den herz() ablegt: verbunden/abonniert.
sub herz_stand
{
    return ($mqtt->{socket} ? 1 : 0) . '/' . ($abonniert_seit ? 1 : 0);
}

# Den eigenen Zustand ablegen (C10). Unteilbar: daneben schreiben, umbenennen.
sub herz
{
    mkdir($lbpdatadir) if (!-d $lbpdatadir);
    $herz_zuletzt = herz_stand();
    my $verbunden = $mqtt->{socket} ? 1 : 0;
    my $inhalt = sprintf('{"pid":%d,"verbunden":%d,"abonniert":%d,"seit":%d,"ts":%d}' . "\n",
                         $$, $verbunden, ($verbunden && $abonniert_seit) ? 1 : 0,
                         $abonniert_seit, time());
    my $ziel = "$lbpdatadir/listener.json";
    my $tmp = "$ziel.tmp.$$";
    if (open(my $fh, '>', $tmp)) {
        print $fh $inhalt;
        close($fh);
        rename($tmp, $ziel) or unlink($tmp);
    }
}

##########################################################################
# Command handling
##########################################################################

sub handle_command
{
    my ($topic, $payload, $retain) = @_;
    $payload = "" if (!defined $payload);
    $payload =~ s/^\s+|\s+$//g;

    my ($cmd) = $topic =~ m{^wifi_ng/cmd/(.+)$};
    return if (!$cmd);

    # C11 (Durchgang 02.10.2026): ein zurueckbehaltener Befehl wird nicht
    # ausgefuehrt. Bis 3.2.9 lief ein einmal retained gesendetes
    # "cmd/enable 0" bei JEDEM Start des Listeners erneut - und der startet bei
    # jedem Speichern und jedem Endpunkt-Befehl neu (Pruefer mqtt Nr. 9, Fall D).
    if ($retain) {
        LOGWARN "Zurueckbehaltener Befehl auf $topic ('$payload') verworfen - Befehle aus Loxone "
              . "nicht retained senden. Entfernen laesst er sich mit einer leeren Nachricht mit Retain.";
        return;
    }

    LOGINF "Received command '$cmd' with payload '$payload'";

    if ($cmd eq "scan") {
        # C9: ein ungueltiger Modus wird abgewiesen, es gibt keinen Lauf. Bis
        # 3.2.9 lief dann ein Suchlauf im eingestellten Modus (Pruefer code
        # Nr. 10) - stilles Zurechtbiegen nach Entscheidung 19.
        if ($payload ne "" && !defined((parse_mode($payload))[0])) {
            LOGERR "Unknown scan mode '$payload' - kein Suchlauf. Erlaubt: leer, 0/both, 1/fritzbox, 2/ping";
            return;
        }
        trigger_scan($payload);
    }
    elsif ($cmd eq "mode") {
        my ($fritz, $active) = parse_mode($payload);
        if (defined $fritz) {
            my $cfg = cfg_lesen();
            return if (!$cfg);
            my $wert = "$fritz$active";
            # C6 (Durchgang 02.10.2026, Entscheidung 19): ein unveraenderter Modus
            # schreibt nichts und stoesst keinen Suchlauf an. Bis 3.2.9 loeste
            # jedes cmd/mode einen vollen Lauf aus (sudo arping, bis 20 Pakete je
            # Geraet) - bei zyklischem Senden aus Loxone jedes Mal.
            if (skalar($cfg->param("BASE.FRITZBOX_ENABLE")) eq "$fritz"
                && skalar($cfg->param("BASE.ACTIVE_SCAN")) eq "$active") {
                if (gleich_binnen('mode', $wert)) {
                    LOGINF "Mode unchanged, derselbe Befehl binnen 60 s - nichts geschieht.";
                    return;
                }
                merken('mode', $wert);
                LOGINF "Mode unchanged (FRITZBOX_ENABLE=$fritz ACTIVE_SCAN=$active) - nichts geschrieben, kein Suchlauf.";
                publish_status();
                return;
            }
            $cfg->param("BASE.FRITZBOX_ENABLE", $fritz);
            $cfg->param("BASE.ACTIVE_SCAN", $active);
            cfg_speichern($cfg) or return;
            merken('mode', $wert);
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
            if (skalar($cfg->param("BASE.CRON")) eq $payload) {
                if (gleich_binnen('interval', $payload)) {
                    LOGINF "Scan interval unchanged, derselbe Befehl binnen 60 s - nichts geschieht.";
                    return;
                }
                merken('interval', $payload);
                update_cron(skalar($cfg->param("BASE.ENABLED")), $payload);
                LOGINF "Scan interval unchanged ($payload) - nichts geschrieben, Zeitplan nachgezogen.";
                publish_status();
                return;
            }
            $cfg->param("BASE.CRON", $payload);
            cfg_speichern($cfg) or return;
            update_cron($cfg->param("BASE.ENABLED"), $payload);
            merken('interval', $payload);
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
            if (skalar($cfg->param("BASE.ENABLED")) eq $payload) {
                if (gleich_binnen('enable', $payload)) {
                    LOGINF "Periodic scanning unchanged, derselbe Befehl binnen 60 s - nichts geschieht.";
                    return;
                }
                merken('enable', $payload);
                update_cron($payload, skalar($cfg->param("BASE.CRON")));
                LOGINF "Periodic scanning unchanged ($payload) - nichts geschrieben, Zeitplan nachgezogen.";
                publish_status();
                return;
            }
            $cfg->param("BASE.ENABLED", $payload);
            cfg_speichern($cfg) or return;
            update_cron($payload, $cfg->param("BASE.CRON"));
            merken('enable', $payload);
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

# C6: derselbe Befehl mit demselben Wert binnen 60 s? (Entscheidung 19, X-7)
sub gleich_binnen
{
    my ($art, $wert) = @_;
    my $z = $zuletzt{$art};
    return (ref($z) eq 'ARRAY' && $z->[0] eq $wert && time() - $z->[1] < 60) ? 1 : 0;
}

sub merken
{
    my ($art, $wert) = @_;
    $zuletzt{$art} = [$wert, time()];
}

# Config::Simple liefert bei einem Komma ein Feld - fuer den Vergleich ein Skalar.
sub skalar
{
    my ($v) = @_;
    $v = join(',', @{$v}) if (ref($v) eq 'ARRAY');
    return defined $v ? "$v" : '';
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
    # C13 (Durchgang 02.10.2026): ein Befehl ist ein Auftrag - check.pl sucht
    # dann auch bei ausgeschaltetem regelmaessigem Suchen.
    my @arg = ('--auftrag');
    if (defined $mode && $mode ne "") {
        my ($fritz, $active) = parse_mode($mode);
        if (defined $fritz) {
            # $mode hat das verankerte Muster bestanden - trotzdem als
            # eigenes Argument, nicht in eine Befehlszeile eingesetzt.
            push(@arg, '--mode', $mode);
        } else {
            LOGERR "Ignoring unknown scan mode override '$mode'";
        }
    }
    LOGINF "Triggering scan " . join(' ', @arg);
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
    elsif ($active)          { $mode = 2; }
    # C8 (Durchgang 02.10.2026): beide Wege aus heisst "keine Suche" (-1), nicht
    # "nur Scan". Bis 3.2.9 stand dann retained eine 2 da (Pruefer code Nr. 9).
    else                     { $mode = -1; }

    # Zustaende - sie gehoeren retained, damit Loxone nach einem Neustart
    # des Miniservers oder des Gateways sofort den Stand hat.
    $mqtt->retain("wifi_ng/status/mode", $mode);
    # Ein LEERER Wert geht nie retained hinaus: eine leere Nutzlast mit
    # retain loescht das Thema im Broker (Regeln/07). BASE.CRON fehlt auf
    # einer nicht eingerichteten Anlage - bis 3.2.4 verschwand das Thema
    # dann bei jedem Start des Listeners aus dem Broker.
    if ($cron ne "") { $mqtt->retain("wifi_ng/status/interval", $cron); }
    else             { $mqtt->publish("wifi_ng/status/interval", $cron); }
    $mqtt->retain("wifi_ng/status/enabled", $enabled);

    # Das Lebenszeichen dagegen OHNE Retain (seit 3.2.4).
    #
    # Dass DIESER Prozess laeuft, ist die einzige Aussage, die er ueber sich
    # selbst treffen kann. Der Gegenwert - die 0, wenn er nicht mehr laeuft -
    # kommt aus check.pl, das alle paar Minuten nachsieht. Zurueckbehalten
    # waere diese 1 eine Behauptung, die niemand mehr zuruecknimmt: laeuft
    # kein Cron, bleibt sie fuer immer stehen, und ein toter Listener sieht
    # aus wie ein lebender. Genau so lag sie am 14.09.2026 im Broker.
    $mqtt->publish("wifi_ng/status/listener", 1);
    LOGDEB "Published status: mode=$mode interval=$cron enabled=$enabled";
}
