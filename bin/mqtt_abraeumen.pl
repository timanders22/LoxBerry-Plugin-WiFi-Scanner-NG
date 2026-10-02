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
use Net::MQTT::Simple;

my $PRAEFIX = 'wifi_ng/';
my $art = shift(@ARGV);
$art = '' if (!defined $art);
my @themen = @ARGV;

if ($art !~ /^--(strich|strich-wenn-da|leeren-alle)$/
    || ($art eq '--leeren-alle' && @themen) || ($art ne '--leeren-alle' && !@themen)) {
    print "AUFRUF: --strich THEMA... | --strich-wenn-da THEMA... | --leeren-alle\n";
    exit 3;
}
foreach my $t (@themen) {
    if (index($t, $PRAEFIX) != 0 || length($t) <= length($PRAEFIX) || $t =~ /[+#\x00-\x1f]/) {
        print "ABGEWIESEN $t (nur Themen unter $PRAEFIX)\n";
        exit 3;
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

my @rest = ();
my $echo_zuletzt = 0;

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
} else {
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
