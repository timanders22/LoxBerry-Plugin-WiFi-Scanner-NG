#!/usr/bin/perl
##########################################################################
# WifiScanner NG - zurueckbehaltene MQTT-Themen abraeumen, MIT Nachlesen
#
# Neu im Durchgangsbau vom 02.10.2026 (Bauliste C3, M2, I6; Entscheidungen
# 3, 5, 8 und 26 des Hausherrn). Aufrufer: check.pl (Umstieg der
# Umlautthemen), die Oberflaeche (ausgetragene Person, Wechsel auf UDP) und
# uninstall/uninstall (Deinstallation).
#
#   perl mqtt_abraeumen.pl --strich THEMA ...
#        einmal "-" retained: die Person ist ausgetragen, ihr Thema bleibt
#        mit "keine Aussage" stehen (Entscheidung 5/8)
#   perl mqtt_abraeumen.pl --strich-wenn-da THEMA ...
#        dasselbe, aber nur, wo noch ein ANDERER Wert zurueckbehalten liegt
#        (so entsteht kein Thema, das es nie gab)
#   perl mqtt_abraeumen.pl --leeren-alle
#        alle zurueckbehaltenen Themen unter wifi_ng/ leeren (Deinstallation,
#        Wechsel von MQTT auf UDP - Entscheidung 3 und 26)
#   perl mqtt_abraeumen.pl --leeren THEMA ...
#        nur diese Themen leeren - und nur, wo etwas zurueckbehalten liegt
#        (seit 3.2.15: das retained status/listener der Vorfassungen nach dem
#        Update, postupgrade.sh)
#   perl mqtt_abraeumen.pl --status
#        status/mode, /interval und /enabled aus der Konfiguration senden
#        (retained; ein leerer Takt ohne Retain). Seit 3.2.15 (Gruppe 14, E7):
#        den Listener, der das bis 3.2.14 tat, gibt es nicht mehr; check.pl
#        sendet dieselben Zustaende je Lauf, dieser Aufruf nach jeder Aenderung
#        (Oberflaeche, Endpunkt, Befehlsbruecke - ueber bin/ws_auftrag.sh status).
#        Steht der Weg auf UDP, wird nichts gesendet. Ausgabe "GESENDET mode=..
#        interval=.. enabled=.." bzw. "UDP ...", Rueckgabe 0, 2 = kein Gateway
#        oder Konfiguration nicht lesbar.
#
# Nur Themen unter wifi_ng/ werden angefasst (Entscheidung 3: nur eigene).
#
# NACHLESEN statt Rueckgabewert (Regeln/07): nach dem Senden fragt eine NEUE
# Verbindung den Broker, was unter den Themen zurueckbehalten liegt. Damit
# "nichts zurueckbehalten" nicht mit "nicht verbunden" verwechselt wird, geht
# dabei ein Echo hinaus - eine nicht zurueckbehaltene Nachricht auf einem
# eigenen Thema AUSSERHALB von wifi_ng/ (das Gateway hoert dort nicht mit).
# Erst wenn das Echo zurueck ist, gilt die Antwort; der Broker liefert die
# zurueckbehaltenen Werte eines Abonnements vor jeder spaeteren Nachricht
# derselben Verbindung.
#
# Ausgabe je Thema eine Zeile (BESTAETIGT, OFFEN, NICHTS, ABGEWIESEN), am
# Ende "ERGEBNIS bestaetigt=<n> offen=<m>".
# Rueckgabe: 0 alles bestaetigt oder nichts zu tun, 1 nicht alles bestaetigt,
#            2 Broker nicht befragbar (kein Gateway, kein Echo), 3 Aufruf falsch.
##########################################################################

use strict;
use warnings;

use LoxBerry::IO;
use LoxBerry::System;
use Net::MQTT::Simple;

my $PRAEFIX = 'wifi_ng/';
my $art = shift(@ARGV);
$art = '' if (!defined $art);
my @themen = @ARGV;

my $ohne_themen = ($art eq '--leeren-alle' || $art eq '--status');
if ($art !~ /^--(strich|strich-wenn-da|leeren-alle|leeren|status)$/
    || ($ohne_themen && @themen) || (!$ohne_themen && !@themen)) {
    print "AUFRUF: --strich THEMA... | --strich-wenn-da THEMA... | --leeren THEMA... | --leeren-alle | --status\n";
    exit 3;
}
foreach my $t (@themen) {
    if (index($t, $PRAEFIX) != 0 || length($t) <= length($PRAEFIX) || $t =~ /[+#\x00-\x1f]/) {
        print "ABGEWIESEN $t (nur Themen unter $PRAEFIX)\n";
        exit 3;
    }
}

# --status: die Konfiguration lesen (nur [BASE], einfache Werte - kein
# Config::Simple; die vier Schluessel tragen nie Komma oder Backslash).
my %cfg = ();
if ($art eq '--status') {
    my $datei = (defined $lbpconfigdir && $lbpconfigdir ne '') ? "$lbpconfigdir/wifi_scanner.cfg" : '';
    if ($datei eq '') {
        # Rueckfall ohne Auskunft von LoxBerry::System: vom eigenen Ablageort
        # <wurzel>/bin/plugins/<ordner>/ auf <wurzel>/config/plugins/<ordner>/.
        my $ich = $0;
        $ich =~ s{/[^/]*$}{};
        my ($wurzel, $ordner) = $ich =~ m{^(.*)/bin/plugins/([^/]+)$};
        $datei = (defined $ordner) ? "$wurzel/config/plugins/$ordner/wifi_scanner.cfg" : '';
    }
    my $fh;
    if ($datei eq '' || !open($fh, '<', $datei)) {
        print "KEINE_KONFIG - " . ($datei ne '' ? $datei : '(Ort unbekannt)') . " nicht lesbar\n";
        exit 2;
    }
    my $ab = '';
    while (my $z = <$fh>) {
        $z =~ s/[\r\n]+$//;
        next if ($z =~ /^\s*([;#]|$)/);
        if ($z =~ /^\s*\[([^\]]+)\]\s*$/) { $ab = uc($1); next; }
        next if ($ab ne 'BASE');
        if ($z =~ /^\s*([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/) { $cfg{uc($1)} = $2; }
    }
    close($fh);
    if (defined $cfg{UDP_ENABLE} && $cfg{UDP_ENABLE} eq '1') {
        print "UDP - der Weg steht auf UDP, ueber MQTT wird nichts gesendet\n";
        exit 0;
    }
}

$ENV{MQTT_SIMPLE_ALLOW_INSECURE_LOGIN} = 1;
my $cred = LoxBerry::IO::mqtt_connectiondetails();
if (!$cred || !$cred->{brokeraddress}) {
    print "KEIN_GATEWAY - auf diesem LoxBerry ist kein MQTT-Gateway eingerichtet\n";
    print "ERGEBNIS bestaetigt=0 offen=" . scalar(@themen) . "\n";
    exit 2;
}

sub verbinden
{
    my $m = Net::MQTT::Simple->new($cred->{brokeraddress});
    if ($cred->{brokeruser} and $cred->{brokerpass}) {
        $m->login($cred->{brokeruser}, $cred->{brokerpass});
    }
    return $m;
}

# Was liegt unter diesen Filtern zurueckbehalten? Rueckgabe: (\%wert, Echo da?)
sub lesen
{
    my (@filter) = @_;
    my %wert = ();
    my $angekommen = 0;
    my $echo = 'wifi_ng_echo/' . $$ . '-' . time() . '-' . int(rand(1000000));
    my $m = verbinden();
    my @paare = ();
    foreach my $f (@filter) {
        push(@paare, $f => sub { my ($t, $w, $r) = @_; $wert{$t} = $w if ($r); });
    }
    push(@paare, $echo => sub { $angekommen = 1; });
    my $ok = eval { $m->subscribe(@paare); $m->publish($echo, 'echo'); 1 };
    if ($ok) {
        my $bis = time() + 6;
        while (!$angekommen && time() < $bis) {
            eval { $m->tick(0.5); };
        }
    }
    eval { $m->disconnect(); };
    return (\%wert, $angekommen);
}

sub senden
{
    my ($wert, @ziele) = @_;
    my $m = verbinden();
    foreach my $t (@ziele) {
        eval { $m->retain($t, $wert); };
    }
    # Net::MQTT::Simple puffert - erst abwarten, dann trennen (wie check.pl).
    if ($m->can('tick')) {
        eval { $m->tick(0) for (1 .. 3); };
    }
    eval { $m->disconnect(); };
}

# E7 (Gruppe 14): zweite Kopie der Modusregel aus check.pl - der Reiter Test
# vergleicht beide Bloecke zwischen den Marken.
# MODUSREGEL-ANFANG
sub modus_aus_cfg
{
    my ($f, $a) = @_;
    $f = (defined $f && "$f" eq '1') ? 1 : 0;
    $a = (defined $a && "$a" eq '1') ? 1 : 0;
    return 0 if ($f && $a);
    return 1 if ($f);
    return 2 if ($a);
    return -1;
}
# MODUSREGEL-ENDE

if ($art eq '--status') {
    my $modus = modus_aus_cfg($cfg{FRITZBOX_ENABLE}, $cfg{ACTIVE_SCAN});
    my $takt = defined $cfg{CRON} ? $cfg{CRON} : '';
    my $ein = (defined $cfg{ENABLED} && $cfg{ENABLED} eq '1') ? 1 : 0;
    my $m = verbinden();
    eval {
        $m->retain($PRAEFIX . 'status/mode', $modus);
        # Ein LEERER Wert nie retained - eine leere Nutzlast mit Retain loescht (Regeln/07).
        if ($takt ne '') { $m->retain($PRAEFIX . 'status/interval', $takt); }
        else             { $m->publish($PRAEFIX . 'status/interval', $takt); }
        $m->retain($PRAEFIX . 'status/enabled', $ein);
    };
    if ($m->can('tick')) {
        eval { $m->tick(0) for (1 .. 3); };
    }
    eval { $m->disconnect(); };
    print "GESENDET mode=$modus interval=$takt enabled=$ein\n";
    exit 0;
}

my @rest = ();
my $echo_zuletzt = 0;

if ($art eq '--leeren') {
    # Nur leeren, wo etwas zurueckbehalten liegt.
    my ($w, $ok) = lesen(@themen);
    if (!$ok) {
        print "KEIN_ECHO - der Broker hat nicht geantwortet\n";
        print "ERGEBNIS bestaetigt=0 offen=" . scalar(@themen) . "\n";
        exit 2;
    }
    my @ziel = ();
    foreach my $t (@themen) {
        if (defined $w->{$t} && $w->{$t} ne '') {
            push(@ziel, $t);
        } else {
            print "NICHTS $t (nichts zurueckbehalten)\n";
        }
    }
    if (!@ziel) {
        print "ERGEBNIS bestaetigt=0 offen=0\n";
        exit 0;
    }
    @themen = @ziel;
    @rest = @ziel;
    $echo_zuletzt = 1;
    for my $runde (1 .. 3) {
        last if (!@rest);
        senden('', @rest);
        my ($w2, $ok2) = lesen(@rest);
        $echo_zuletzt = $ok2;
        next if (!$ok2);
        @rest = grep { defined $w2->{$_} && $w2->{$_} ne '' } @rest;
    }
}

if ($art eq '--strich-wenn-da') {
    my ($w, $ok) = lesen(@themen);
    if (!$ok) {
        print "KEIN_ECHO - der Broker hat nicht geantwortet\n";
        print "ERGEBNIS bestaetigt=0 offen=" . scalar(@themen) . "\n";
        exit 2;
    }
    my @ziel = ();
    foreach my $t (@themen) {
        if (defined $w->{$t} && $w->{$t} ne '-') {
            push(@ziel, $t);
        } else {
            print "NICHTS $t (" . (defined $w->{$t} ? 'steht schon auf -' : 'nichts zurueckbehalten') . ")\n";
        }
    }
    if (!@ziel) {
        print "ERGEBNIS bestaetigt=0 offen=0\n";
        exit 0;
    }
    @themen = @ziel;
    $art = '--strich';
}

if ($art eq '--strich') {
    @rest = @themen;
    for my $runde (1 .. 3) {
        senden('-', @rest);
        my ($w, $ok) = lesen(@rest);
        $echo_zuletzt = $ok;
        next if (!$ok);
        @rest = grep { !defined $w->{$_} || $w->{$_} ne '-' } @rest;
        last if (!@rest);
    }
} elsif ($art eq '--leeren-alle') {
    my ($w, $ok) = lesen($PRAEFIX . '#');
    if (!$ok) {
        print "KEIN_ECHO - der Broker hat nicht geantwortet\n";
        print "ERGEBNIS bestaetigt=0 offen=0\n";
        exit 2;
    }
    @themen = sort { $a cmp $b } grep { index($_, $PRAEFIX) == 0 } keys %{$w};
    print "GEFUNDEN " . scalar(@themen) . " zurueckbehaltene Themen unter $PRAEFIX\n";
    @rest = @themen;
    $echo_zuletzt = 1;
    for my $runde (1 .. 3) {
        last if (!@rest);
        senden('', @rest);
        my ($w2, $ok2) = lesen($PRAEFIX . '#');
        $echo_zuletzt = $ok2;
        next if (!$ok2);
        @rest = grep { defined $w2->{$_} } @rest;
    }
}

my %offen = map { $_ => 1 } @rest;
my $bestaetigt = 0;
foreach my $t (@themen) {
    if ($offen{$t}) {
        print "OFFEN $t\n";
    } else {
        print "BESTAETIGT $t\n";
        $bestaetigt++;
    }
}
print "ERGEBNIS bestaetigt=$bestaetigt offen=" . scalar(@rest) . "\n";
exit 0 if (!@rest);
exit($echo_zuletzt ? 1 : 2);
