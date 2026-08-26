#!/usr/bin/perl
##########################################################################
# Script zur Anwesenheitserkennung von WLAN-Geräten                      #
# an einer Fritz!Box in Verbindung mit einem Loxone Miniserver           #
##########################################################################

# Copyright 2018 Dominik Holland, dominik.holland@googlemail.com
#
# DIESE DATEI WURDE GEAENDERT (Apache License 2.0, Abschnitt 4 b).
# Sie stammt aus dem Plugin LoxBerry-Plugin-WifiScanner von Dominik Holland
# und ist fuer diese Fortfuehrung ueberarbeitet worden: MQTT-Ausgabe,
# Suche nach arping/arp/arp-scan in mehreren Verzeichnissen, Anpassungen an
# LoxBerry 3 und 4. Seit 3.0.0 lautet das MQTT-Thema wifi_ng/... statt
# wifiscanner/... - die vollstaendige Liste der Aenderungen steht in NOTICE.
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
# WAS SICH IN 3.1.12 GEAENDERT HAT - und warum
#
# 1. KEINE SHELL MEHR. Bis 3.1.11 stand hier
#
#        system("sudo $ARPING -W 0.2 -c 20 -C1 $ip $log_cmd")
#
#    mit EINEM String, also ueber /bin/sh. Und $ip war alles, was nicht wie
#    eine MAC-Adresse aussah - geprueft wurde es nirgends. Ein "$(befehl)"
#    im Adressfeld der Oberflaeche wurde von der Shell ausgefuehrt; ueber
#    eine zurueckgespielte Sicherungsdatei ging sogar das Semikolon durch.
#    Jeder Aufruf laeuft jetzt als LISTE (system(@cmd), kein sh), und jede
#    Adresse wird an BEIDEN Enden geprueft - beim Speichern in der
#    Oberflaeche und hier noch einmal, weil die Konfigurationsdatei auch von
#    Hand kommen kann.
#
# 2. use strict. Bis 3.1.11 stand nur -w im Shebang. Zurueckgestellt worden
#    war es 2.5.2 mit der Begruendung, es gebe keinen Pruefaufbau - den gibt
#    es inzwischen (Werkzeuge/perl_attrappe, "perl -I... -c").
#
# 3. EINMAL senden statt zweimal. Bis 3.1.11 sendete das Skript bei
#    eingeschalteter Fritz!Box-Abfrage UND aktivem Scan zuerst das halbe
#    Ergebnis (alle, die die Box nicht kannte, als 0) und gleich darauf das
#    richtige. In Loxone kam damit bei jedem Lauf eine 0-nach-1-Flanke an,
#    die es nie gab.
#
# 4. LEBENSZEICHEN. Ein virtueller Eingang behaelt seinen letzten Wert -
#    retained sogar ueber jeden Neustart des Miniservers hinweg. Stirbt
#    dieses Skript oder faellt der Cron aus, steht in Loxone weiter die
#    Anwesenheit vom Zeitpunkt des Ausfalls. Das ist keine fehlende
#    Auskunft, das ist eine Falschaussage. Deshalb gehen jetzt
#    wifi_ng/status/ts, /ok und /zaehler mit hinaus, und das Ergebnis wird
#    zusaetzlich als Abbild abgelegt, damit die Oberflaeche es zeigen kann.
#
# 5. SPERRE. Ein Lauf, der bei zwanzig Geraeten in arping haengt, kann den
#    naechsten ueberholen. Ein uebersprungener Lauf ist ausdruecklich KEIN
#    Fehler und wird nicht als Stoerung gemeldet.
##########################################################################

use strict;
use warnings;

use LoxBerry::System;
use LoxBerry::Log;
use LoxBerry::IO;

use LWP::UserAgent;
use XML::Simple;
use JSON;
use Getopt::Long;
use Config::Simple;
use Fcntl qw(:flock);
use open qw(:std :utf8);
use IO::Socket;
use Net::MQTT::Simple;
use Data::Validate::IP;
use Capture::Tiny qw/capture/;

# Entfallen sind LWP::Simple, File::HomeDir, Cwd und POSIX: keiner der vier
# Namen kam ausserhalb seiner use-Zeile im Skript vor. File::HomeDir war der
# gefaehrliche davon - das zugehoerige Paket libfile-homedir-perl stand nicht
# in dpkg/apt, und ohne das Modul waere das Skript beim Start gestorben, vor
# LOGSTART, also ohne eine einzige Protokollzeile.

# ---------------------------------------------------------------------------
# Wo liegen arp, arping und arp-scan?
#
# Fest eingetragen war /usr/sbin. Seit dem usr-merge in Debian 12/13 koennen
# die Programme auch unter /usr/bin liegen, und auf manchen Aufsetzungen ist
# /sbin nur ein Verweis. Gesucht wird deshalb der Reihe nach; gefunden wird
# der erste ausfuehrbare Treffer.
#
# WICHTIG: die sudoers-Zeilen des Plugins nennen weiterhin die Pfade unter
# /usr/sbin. Wird ein Programm anderswo gefunden, laesst sudo den Aufruf
# nicht zu - deshalb legt postinstall.sh in diesem Fall einen Verweis in
# /usr/sbin an.
sub werkzeug
{
    my ($name) = @_;
    for my $d ('/usr/sbin', '/sbin', '/usr/bin', '/bin') {
        my $p = "$d/$name";
        return $p if -x $p;
    }
    return "/usr/sbin/$name";   # Rueckfall: die Meldung von sudo ist aussagekraeftiger als nichts
}
our $ARP      = werkzeug('arp');
our $ARPING   = werkzeug('arping');
our $ARPSCAN  = werkzeug('arp-scan');

# ---------------------------------------------------------------------------
# Ein LWP-Benutzeragent, der die Zugangsdaten der Fritz!Box mitbringt.
#
# Neu in 3.1.12 und ab Werk WIRKUNGSLOS: stehen keine Zugangsdaten in der
# Konfiguration, verhaelt sich der Agent genau wie bisher - ohne Anmeldung.
#
# Der Umweg ueber eine Unterklasse statt $ua->credentials($netloc, $realm, ...)
# hat einen Grund: credentials() verlangt den REALM, und der ist bei AVM
# nicht ueber die Firmware-Staende hinweg gleich. get_basic_credentials wird
# fuer jeden Realm gefragt.
{
    package WS::UA;
    our @ISA = ('LWP::UserAgent');
    our $BENUTZER = '';
    our $KENNWORT = '';
    sub get_basic_credentials
    {
        my ($self, $realm, $uri, $isproxy) = @_;
        return (undef, undef) if ($BENUTZER eq '' && $KENNWORT eq '');
        return ($BENUTZER, $KENNWORT);
    }
}

##########################################################################
# Einstellungen lesen
##########################################################################

our $version = LoxBerry::System::pluginversion();

my $log = LoxBerry::Log->new(name => 'wifi_scanner', addtime => 1);

my %miniservers = LoxBerry::System::get_miniservers();

my $pcfg = Config::Simple->new("$lbpconfigdir/wifi_scanner.cfg");
if (!$pcfg) {
    # Ohne Konfiguration gibt es nichts zu tun, und Vorgabewerte waeren hier
    # eine Erfindung: das Skript wuesste nicht, wen es suchen soll.
    LOGSTART "Starting $0 Version $version";
    LOGCRIT "Konfiguration nicht lesbar: $lbpconfigdir/wifi_scanner.cfg";
    LOGEND "Abgebrochen.";
    exit 1;
}

my $udpport      = $pcfg->param("BASE.PORT")            // 7007;
my $fritz_enable = $pcfg->param("BASE.FRITZBOX_ENABLE") // 0;
my $fritz_host   = $pcfg->param("BASE.FRITZBOX")        // 'fritz.box';
my $fritz_port   = $pcfg->param("BASE.FRITZBOX_PORT")   // 49443;
my $fritz_user   = $pcfg->param("BASE.FRITZBOX_USER")   // '';
my $fritz_pass   = $pcfg->param("BASE.FRITZBOX_PASS")   // '';
my $active_scan  = $pcfg->param("BASE.ACTIVE_SCAN")     // 0;
our $ping_cmd    = $pcfg->param("BASE.PING_CMD")        // 0;
our $use_cache   = $pcfg->param("BASE.USE_CACHE")       // 1;
my $user_count   = $pcfg->param("BASE.USERS")           // 0;
my $udp_enable   = $pcfg->param("BASE.UDP_ENABLE")      // 0;
my $logmax       = $pcfg->param("BASE.LOGMAX")          // 500;

# Config::Simple gibt bei einem Wert mit Komma ein Feld zurueck. Hier kommt
# das nicht vor, aber ein Feld in einer Zahlenrechnung waere ein stiller
# Fehler - deshalb wird jeder Skalar auch als solcher behandelt.
for my $r (\$udpport, \$fritz_enable, \$fritz_host, \$fritz_port, \$fritz_user,
           \$fritz_pass, \$active_scan, \$ping_cmd, \$use_cache, \$user_count,
           \$udp_enable, \$logmax) {
    $$r = ref($$r) eq 'ARRAY' ? join(',', @{$$r}) : $$r;
}

# Commandline options
my $verbose = '';
my $mode = '';
GetOptions('verbose' => \$verbose,
           'mode=s'  => \$mode,
           'quiet'   => sub { $verbose = 0 });

LOGSTART "Starting $0 Version $version";

##########################################################################
# Sperre - zwei Laeufe auf denselben Dateien vertragen sich nicht
#
# Nicht blockierend: ein uebersprungener Lauf ist KEIN Fehler. Wer ihn als
# Stoerung meldete, liesse einen gestreckten Takt wie einen Ausfall aussehen.
##########################################################################

my $sperrdatei = "$lbpdatadir/check.lock";
mkdir($lbpdatadir) if (!-d $lbpdatadir);
my $sperre;
if (open($sperre, '>', $sperrdatei)) {
    if (!flock($sperre, LOCK_EX | LOCK_NB)) {
        LOGINF "Ein Suchlauf laeuft noch - dieser wird uebersprungen.";
        LOGEND "Uebersprungen.";
        exit 0;
    }
} else {
    LOGWARN "Sperrdatei nicht anlegbar ($sperrdatei) - es wird ohne Sperre gearbeitet.";
}

# Mode override via commandline (used by mqtt_listener.pl):
# 0|both = Fritzbox + active scan, 1|fritzbox = Fritzbox only, 2|ping = active scan only
if ($mode ne '') {
    if ($mode =~ /^(0|both|all|full)$/i) {
        $fritz_enable = 1; $active_scan = 1;
    } elsif ($mode =~ /^(1|fritz|fritzbox)$/i) {
        $fritz_enable = 1; $active_scan = 0;
    } elsif ($mode =~ /^(2|ping|scan|active)$/i) {
        $fritz_enable = 0; $active_scan = 1;
    } else {
        LOGERR "Unknown mode '$mode' - using configured defaults";
    }
    LOGINF "Mode override: FRITZBOX_ENABLE=$fritz_enable ACTIVE_SCAN=$active_scan";
}

if (!%miniservers) {
    lox_die("No Miniservers configured");
}

##########################################################################
# Personen und Adressen
#
# Die Adressen werden HIER geprueft, nicht nur in der Oberflaeche. Eine
# Konfigurationsdatei kann von Hand geschrieben, aus einer Sicherung
# zurueckgespielt oder aus einer aelteren Fassung uebernommen sein.
##########################################################################

LOGDEB "Reading configuration file";
my @users = ();
my $abgewiesen = 0;
for (my $i = 1; $i <= $user_count; $i++) {
    my %user;
    $user{NAME} = $pcfg->param("USER$i.NAME");
    $user{NAME} = '' if (!defined $user{NAME});
    $user{NAME} = join(',', @{$user{NAME}}) if (ref($user{NAME}) eq 'ARRAY');
    LOGDEB "Found config for $user{NAME}";

    my $input = $pcfg->param("USER$i.MACS");
    $input = '' if (!defined $input);
    $input = join(';', @{$input}) if (ref($input) eq 'ARRAY');

    my @ips = ();
    my @macs = ();
    foreach my $in (split(/[;,\s]+/, $input)) {
        next if ($in eq '');
        my $art = adresse_art($in);
        if ($art eq 'mac') {
            LOGDEB "Identified $in as MAC ADDRESS";
            push(@macs, lc $in);
        } elsif ($art ne '') {
            LOGDEB "Identified $in as $art";
            push(@ips, $in);
        } else {
            # Abweisen und MELDEN. Eine Adresse, die stillschweigend
            # verschwindet, schickt den Betreiber auf die Suche nach einem
            # Fehler, den er nicht sieht.
            LOGERR "Adresse abgewiesen bei $user{NAME}: '$in' ist weder MAC- noch "
                 . "IP-Adresse noch Rechnername - sie wird nicht gesucht.";
            $abgewiesen++;
        }
    }
    $user{MACS} = \@macs;
    $user{IPS} = \@ips;
    $user{ONLINE} = 0;
    $user{WEG} = '';
    push(@users, \%user);
}
my $anzahl = scalar(@users);

my $user_online = 0;
my $stoerung = '';

##########################################################################
# Weg 1: die Fritz!Box fragen
##########################################################################

if ($fritz_enable) {
    LOGINF "Establishing connection to the Router to check for mac addresses";
    # disable SSL checks. No signed certificate!
    $ENV{'PERL_LWP_SSL_VERIFY_HOSTNAME'} = 0;

    $WS::UA::BENUTZER = $fritz_user;
    $WS::UA::KENNWORT = $fritz_pass;
    if ($fritz_user ne '') {
        LOGINF "Anmeldung an der Fritz!Box als '$fritz_user' (Kennwort "
             . (length($fritz_pass) ? length($fritz_pass) . " Zeichen" : "LEER") . ")";
    } else {
        LOGDEB "Keine Zugangsdaten hinterlegt - Abfrage ohne Anmeldung wie bisher.";
    }

    my $ua = WS::UA->new;
    $ua->default_headers;
    $ua->ssl_opts(verify_hostname => 0, SSL_verify_mode => 0x00);
    # Ein Netzabruf ohne Zeitschranke kann den ganzen Lauf anhalten - und der
    # naechste Cron-Lauf steht dann vor der Sperre.
    $ua->timeout(15);

    my $resp_discover = $ua->get("https://$fritz_host:$fritz_port/tr64desc.xml");
    if (!$resp_discover->is_success) {
        # KEIN lox_die mehr: der aktive Scan kann die Antwort trotzdem
        # liefern, und ein Abbruch hier liesse die Anwesenheit in Loxone auf
        # dem alten Stand stehen, ohne dass jemand es merkt.
        $stoerung = 'Fritz!Box: ' . $resp_discover->status_line;
        LOGERR $stoerung;
        if ($resp_discover->code && $resp_discover->code == 401) {
            LOGERR "Die Box verlangt eine Anmeldung. Benutzername und Kennwort "
                 . "stehen in den Einstellungen unter 'Anmeldung an der Fritz!Box'.";
        }
        $fritz_enable = 0;
    }

    if ($fritz_enable) {
        my $discover = XMLin($resp_discover->decoded_content);
        LOGINF "$discover->{device}->{modelName} detected...";

        # Parse XML service response, get needed parameters for LAN host service
        my $control_url = "not set";
        my $service_type = "not set";
        my $service_command = "GetSpecificHostEntry"; # fixed command
        foreach my $s (@{$discover->{device}->{deviceList}->{device}->[0]->{serviceList}->{service}}) {
            if ("urn:LanDeviceHosts-com:serviceId:Hosts1" =~ m/.*\Q$s->{serviceId}\E.*/) {
                $control_url = $s->{controlURL};
                $service_type = $s->{serviceType};
            }
        }

        if ($control_url eq "not set" or $service_type eq "not set") {
            $stoerung = 'Fritz!Box: control URL/service type not found';
            LOGERR $stoerung;
        } else {
            $ua->default_header('SOAPACTION' => "$service_type#$service_command");

            my $abbruch = 0;
            for (my $i = 0; $i < $anzahl && !$abbruch; $i++) {
                my @macs = @{$users[$i]{MACS}};
                if (scalar(@macs) == 0) {
                    LOGINF "Skipping $users[$i]{NAME}. No mac addresses provided";
                    next;
                }
                LOGINF "Checking devices from User: $users[$i]{NAME}";
                foreach my $mac (@macs) {
                    my $init_request = <<"EOD";
            <?xml version="1.0" encoding="utf-8"?>
            <s:Envelope s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/" xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" >
                    <s:Header>
                    </s:Header>
                    <s:Body>
                            <u:$service_command xmlns:u="$service_type">
                                    <NewMACAddress>$mac</NewMACAddress>
                            </u:$service_command>
                    </s:Body>
            </s:Envelope>
EOD
                    my $init_url = "https://$fritz_host:$fritz_port$control_url";
                    my $resp_init = $ua->post($init_url,
                        Content_Type => 'text/xml; charset=utf-8', Content => $init_request);

                    # Der 401 kommt HIER, nicht beim ersten Abruf.
                    #
                    # Am 26.08.2026 an einer FRITZ!Box 7690 mit FRITZ!OS 8.25
                    # gemessen: tr64desc.xml antwortet ohne Anmeldung mit 200,
                    # und GetSpecificHostEntry ebenfalls - die Box verarbeitet
                    # die Anfrage und meldet 714 (NoSuchEntryInArray) fuer eine
                    # unbekannte MAC. Die Gegenprobe an derselben Box:
                    # DeviceInfo#GetInfo und DeviceConfig#GetPersistentData
                    # antworten mit 401. Die Box KANN also abweisen, sie tut es
                    # beim Hosts-Dienst nur nicht.
                    #
                    # Fuer Boxen oder Aufsetzungen, wo sie es doch tut, steht
                    # der Hinweis auf die Zugangsdaten hier. Gemeldet wird er
                    # EINMAL und nicht je Geraet: sagt die Box 401, sagt sie es
                    # fuer alle, und zwanzig gleiche Zeilen sind Laerm.
                    if ($resp_init->code && $resp_init->code == 401) {
                        $stoerung = 'Fritz!Box: 401 Unauthorized bei GetSpecificHostEntry';
                        LOGERR $stoerung;
                        # Der Ternaer gehoert in eine Variable, NICHT hinter
                        # LOGERR: LOGERR traegt den Prototyp ($), und der
                        # bindet staerker als "? :". Perl haette
                        # LOGERR($fritz_user ne '') aufgerufen und die beiden
                        # Texte in void context stehen lassen - im Protokoll
                        # staende eine 1. "perl -c" meldet das als
                        # "Useless use of a constant in void context";
                        # es ist keine Kosmetik, sondern eine Meldung, die
                        # sonst nie erschienen waere.
                        my $rat = ($fritz_user ne '')
                            ? "Die hinterlegten Zugangsdaten wurden abgewiesen. Benutzername "
                              . "und Kennwort stehen in den Einstellungen unter 'Anmeldung an "
                              . "der Fritz!Box'; in der Box selbst muss unter Heimnetz -> "
                              . "Netzwerk -> Netzwerkeinstellungen der Zugriff fuer Anwendungen "
                              . "zugelassen sein."
                            : "Diese Box verlangt fuer die Host-Liste eine Anmeldung. Tragen Sie "
                              . "in den Einstellungen unter 'Anmeldung an der Fritz!Box' einen "
                              . "Benutzernamen und das zugehoerige Kennwort ein.";
                        LOGERR $rat;
                        $abbruch = 1;
                        last;
                    }

                    my $response = $resp_init->decoded_content;
                    $response = '' if (!defined $response);
                    my $xml_mac_resp = eval { XMLin($response) };
                    if (!$xml_mac_resp) {
                        LOGERR "Antwort der Fritz!Box nicht lesbar fuer $mac (HTTP "
                             . ($resp_init->code // '?') . ")";
                        next;
                    }

                    if ($log->loglevel() >= 7) {
                        my $sichtbar = $response;
                        $sichtbar =~ s/&/&amp;/g;
                        $sichtbar =~ s/</&lt;/g;
                        LOGDEB "FritzBox Response:\n$sichtbar";
                    }

                    if (exists $xml_mac_resp->{'s:Body'}->{'s:Fault'}) {
                        my $ec = $xml_mac_resp->{'s:Body'}->{'s:Fault'}->{detail}->{UPnPError}->{errorCode};
                        if (defined $ec && $ec eq "714") {
                            LOGERR "Mac $mac not found in FritzBox Database!";
                        } else {
                            LOGERR "Fritz!Box meldet einen Fehler fuer $mac"
                                 . (defined $ec ? " (Code $ec)" : "");
                        }
                    }
                    if (exists $xml_mac_resp->{'s:Body'}->{'u:GetSpecificHostEntryResponse'}) {
                        my $r = $xml_mac_resp->{'s:Body'}->{'u:GetSpecificHostEntryResponse'};
                        my $aktiv  = defined $r->{NewActive} ? $r->{NewActive} : '0';
                        my $name   = defined $r->{NewHostName} ? $r->{NewHostName} : '?';
                        my $hostip = defined $r->{NewIPAddress} ? $r->{NewIPAddress} : '';
                        my $iftype = defined $r->{NewInterfaceType} ? $r->{NewInterfaceType} : '?';
                        if ($aktiv eq "1") {
                            LOGINF "Mac $mac ($name) is online with IP $hostip on $iftype";
                            $users[$i]{ONLINE} = 1;
                            $users[$i]{WEG} = 'fritzbox';
                            $user_online = 1;
                        } else {
                            LOGINF "Mac $mac ($name) is offline";
                        }
                        # Die von der Box gemeldete Adresse fuer den aktiven
                        # Scan merken - aber nur, wenn sie eine ist.
                        if ($hostip ne '' && adresse_art($hostip) =~ /^ip/) {
                            push(@{$users[$i]{IPS}}, $hostip);
                        }
                    }
                }
            }
        }
    }
} else {
    LOGINF "Ping devices without asking the Router first";
}

##########################################################################
# Weg 2: aktiv suchen
##########################################################################

if ($active_scan) {
    LOGDEB "Iterating over all users to do actives scans where needed";
    for (my $i = 0; $i < $anzahl; $i++) {
        if ($users[$i]{ONLINE}) {
            LOGDEB "Skipping $users[$i]{NAME}, because we already have a result";
            next;
        }
        LOGINF "Pinging Devices for user: $users[$i]{NAME}";

        my $gefunden = 0;
        foreach my $ip (@{$users[$i]{IPS}}) {
            if (ping($ip)) {
                $gefunden = 1;
                $users[$i]{ONLINE} = 1;
                $users[$i]{WEG} = 'ping';
                $user_online = 1;
                last;
            }
        }
        next if ($gefunden);

        foreach my $mac (@{$users[$i]{MACS}}) {
            LOGINF "Trying to get ip address for $mac";
            my $ip = mac2ip($mac);
            if ($ip ne "") {
                if (grep { $_ eq $ip } @{$users[$i]{IPS}}) {
                    LOGINF "Skipping $mac ($ip) as it was already scanned";
                    next;
                }
                push(@{$users[$i]{IPS}}, $ip);
            } else {
                # Keine IP-Adresse gefunden: arping kann auch eine MAC-Adresse
                # als Ziel nehmen.
                $ip = $mac;
            }
            if (ping($ip)) {
                $users[$i]{ONLINE} = 1;
                $users[$i]{WEG} = 'arping';
                $user_online = 1;
                last;
            }
        }
    }
}

##########################################################################
# EINMAL senden - erst jetzt, wenn das Ergebnis vollstaendig ist
##########################################################################

my $lauf_ok = ($stoerung eq '' && ($fritz_enable || $active_scan)) ? 1 : 0;
if (!$fritz_enable && !$active_scan) {
    $stoerung = 'Weder Fritz!Box-Abfrage noch aktiver Scan ist eingeschaltet.';
    LOGWARN $stoerung;
}

zustand_schreiben($lauf_ok, $stoerung, \@users);
sendFoundUsers(\@users, $lauf_ok);

if ($abgewiesen) {
    LOGWARN "$abgewiesen Adresse(n) wurden abgewiesen - siehe die Zeilen oben.";
}

##########################################################################
# Der Waechter fuer den MQTT-Listener
#
# Der Listener wird beim Hochfahren gestartet und danach nie wieder. Stirbt
# er zwischendurch - Broker weg, Netz weg, Speicher knapp -, ist die
# Steuerung aus Loxone bis zum naechsten Neustart stumm.
#
# Fail safe: gestartet wird nur, wenn MQTT ueberhaupt der gewaehlte Weg ist,
# das Skript wirklich da ist und nachweislich keiner laeuft. Im Zweifel
# passiert nichts.
##########################################################################

if (!$udp_enable) {
    my $skript = "$lbpbindir/mqtt_listener.pl";
    if (-f $skript && !listener_pid($skript)) {
        LOGWARN "Der MQTT-Listener lief nicht - er wird neu gestartet.";
        system('/bin/sh', '-c', "nohup perl '$skript' > /dev/null 2>&1 &");
    }
}

LOGEND "Operation finished sucessfully.";
log_kappen($logmax);
exit 0;

##########################################################################
# Unterprogramme
##########################################################################

# Rueckgabe: 'mac', 'ip4', 'ip6', 'host' - oder '' fuer "keine Adresse".
# Dieselbe Beurteilung wie ws_adresse_art() in der Oberflaeche.
sub adresse_art
{
    my ($a) = @_;
    return '' if (!defined $a);
    $a = "$a";
    return '' if ($a eq '' || length($a) > 255);
    return 'mac' if ($a =~ /^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/);
    my $v = Data::Validate::IP->new;
    return 'ip4' if ($v->is_ipv4($a));
    return 'ip6' if ($v->is_ipv6($a));
    # Rechnername nach RFC 1123, kein reiner Zahlname
    return 'host' if ($a !~ /^[0-9.]+$/
        && $a =~ /^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/);
    return '';
}

# Ein Programm OHNE Shell aufrufen und Rueckgabewert samt Ausgabe liefern.
# Kein String, keine Metazeichen, keine Umleitung - system() mit einer Liste
# startet das Programm unmittelbar.
sub lauf
{
    my (@cmd) = @_;
    my ($out, $err, $rc) = capture { system(@cmd); };
    return ($rc, defined $out ? $out : '', defined $err ? $err : '');
}

sub mac2ip
{
    my ($mac) = @_;
    return "" if (adresse_art($mac) ne 'mac');
    my $v = Data::Validate::IP->new;
    my $ip = "";

    if ($use_cache) {
        # Bis 3.1.11: `$ARP -e -n | grep $mac | cut -f 1 -d ' '` - eine
        # Shell-Zeile mit einem Wert aus der Konfiguration darin. Jetzt wird
        # die Tabelle gelesen und in Perl durchsucht.
        my ($rc, $out, $err) = lauf($ARP, '-e', '-n');
        if ($rc == 0) {
            foreach my $zeile (split(/\n/, $out)) {
                next if ($zeile !~ /\Q$mac\E/i);
                my @sp = split(/\s+/, $zeile);
                if (@sp && $v->is_ipv4($sp[0])) { $ip = $sp[0]; last; }
            }
        }
    }

    if (!$v->is_ipv4($ip)) {
        if ($use_cache) {
            LOGINF "Couldn't find mac in arp table (cache). Doing active scan";
        } else {
            LOGINF "Skipping to check arp table (cache). Doing active scan";
        }
        my ($rc, $out, $err) = lauf('sudo', $ARPSCAN, "--destaddr=$mac",
                                    '--localnet', '-N', '--ignoredups');
        $ip = "";
        foreach my $zeile (split(/\n/, $out)) {
            next if ($zeile !~ /\Q$mac\E/i);
            my @sp = split(/\s+/, $zeile);
            if (@sp && $v->is_ipv4($sp[0])) { $ip = $sp[0]; last; }
        }
        if ($v->is_ipv4($ip)) {
            if ($use_cache) {
                LOGINF "Found $ip, adding the mac to arp table (cache)";
                lauf('sudo', $ARP, '-s', $ip, $mac);
            } else {
                LOGDEB "Found $ip";
            }
        } else {
            my $grund = ($err ne '') ? $err : "arp-scan lieferte keinen Treffer";
            chomp($grund);
            LOGINF "Couldn't determine the ip address: $grund";
            $ip = "";
        }
    } else {
        LOGDEB "Found $ip";
    }
    return $ip;
}

sub ping
{
    my ($ziel) = @_;
    # Zweite Wache. Die erste steht beim Einlesen; diese hier faengt den
    # Fall ab, dass eine kuenftige Aenderung eine Adresse an der ersten
    # vorbeischleust.
    if (adresse_art($ziel) eq '') {
        LOGERR "Ping abgewiesen: '" . (defined $ziel ? $ziel : '') . "' ist keine Adresse.";
        return 0;
    }
    LOGINF "Ping $ziel";

    my @cmd;
    if ($ping_cmd == 0) {
        # Viele Anfragen, aber die Antwort kommt so schnell wie moeglich.
        @cmd = ('sudo', $ARPING, '-W', '0.2', '-c', '20', '-C1', $ziel);
    } elsif ($ping_cmd == 1) {
        @cmd = ('/bin/ping', '-i', '0.2', '-c', '3', $ziel);
    } else {
        LOGERR "Invalid ping cmd configuration";
        return 0;
    }

    my ($rc, $out, $err) = lauf(@cmd);
    if ($log->loglevel() >= 7) {
        chomp($out); chomp($err);
        LOGDEB "$cmd[0] $ziel -> rc=$rc\n$out$err";
    }
    if ($rc == 0) {
        LOGINF "Host $ziel is online";
        return 1;
    }
    LOGINF "Host $ziel is offline";
    return 0;
}

sub sendFoundUsers
{
    my ($users, $ok) = @_;
    my $jetzt = time();

    if ($udp_enable) {
        foreach my $ms (sort keys %miniservers) {
            my $sock = IO::Socket::INET->new(
                Proto    => 'udp',
                PeerPort => $udpport,
                PeerAddr => $miniservers{$ms}{IPAddress},
                Type     => SOCK_DGRAM
            );
            if (!$sock) {
                LOGERR "Could not create socket to $miniservers{$ms}{Name}: $!";
                next;
            }
            foreach my $u (@{$users}) {
                LOGOK "Sending Data '$u->{NAME}:$u->{ONLINE}' to $miniservers{$ms}{Name} "
                    . "IP: $miniservers{$ms}{IPAddress} Port:$udpport";
                $sock->send("$u->{NAME}:$u->{ONLINE}")
                    or LOGERR "Send error: $!";
            }
            # Der Zustand geht auch ueber UDP hinaus - sonst kann der
            # Miniserver auf diesem Weg nicht unterscheiden, ob niemand da
            # ist oder ob niemand mehr misst.
            $sock->send("wifi_ok:$ok")        or LOGERR "Send error: $!";
            $sock->send("wifi_ts:$jetzt")     or LOGERR "Send error: $!";
            $sock->close();
        }
        return;
    }

    ## MQTT publish
    # Allow unencrypted connection with credentials
    $ENV{MQTT_SIMPLE_ALLOW_INSECURE_LOGIN} = 1;

    my $mqttcred = LoxBerry::IO::mqtt_connectiondetails();

    # Ist auf dem LoxBerry kein MQTT-Gateway eingerichtet, liefert die
    # Funktion undef. Der unmittelbare Zugriff auf {brokeraddress} war
    # dann ein "Can't use an undefined value as a HASH reference" - das
    # Skript starb mitten im Lauf, ohne das Protokoll ordentlich zu
    # schliessen, und niemand sah den Grund.
    if (!$mqttcred || !$mqttcred->{brokeraddress}) {
        LOGERR "Kein MQTT-Gateway eingerichtet (System -> MQTT Gateway). "
             . "Die Anwesenheit wird nicht veroeffentlicht.";
        return;
    }

    my $mqtt = Net::MQTT::Simple->new($mqttcred->{brokeraddress});
    if ($mqttcred->{brokeruser} and $mqttcred->{brokerpass}) {
        $mqtt->login($mqttcred->{brokeruser}, $mqttcred->{brokerpass});
    }

    my $gesendet = 0;
    foreach my $u (@{$users}) {
        my $topic = "wifi_ng/" . mqtt_topic_name($u->{NAME});
        LOGOK "Sending '$u->{ONLINE}' to $topic on MQTT broker $mqttcred->{brokeraddress}";
        $mqtt->retain($topic, $u->{ONLINE});
        $gesendet++;
    }

    # Das Lebenszeichen. wifi_ng/status/ts geht bei JEDEM Durchgang hinaus,
    # auch unveraendert - ueber MQTT gibt es kein "Alter", nur einen
    # Zeitstempel, und der Miniserver rechnet selbst:
    #     Alter = (Loxone-Zeit + 1230768000) - ts
    $mqtt->retain("wifi_ng/status/ok", $ok);
    $mqtt->retain("wifi_ng/status/ts", $jetzt);
    $mqtt->retain("wifi_ng/status/zaehler", zaehler_lesen());

    # Ob der Listener laeuft, wird HIER gemessen und nicht vom Listener
    # selbst behauptet. Net::MQTT::Simple kennt keinen letzten Willen; ein
    # Dienst, der seinen eigenen Tod melden soll, ist ohnehin der falsche
    # Zeuge. Alle drei Minuten eine echte Messung ist besser.
    $mqtt->retain("wifi_ng/status/listener",
                  (-f "$lbpbindir/mqtt_listener.pl" && listener_pid("$lbpbindir/mqtt_listener.pl")) ? 1 : 0);
    $gesendet += 4;

    # Net::MQTT::Simple puffert. Ein disconnect() unmittelbar nach dem
    # letzten retain() kann die Nachrichten verwerfen - deshalb erst
    # abwarten, bis der Puffer draussen ist. can() davor, weil tick() in
    # aelteren Fassungen der Bibliothek fehlt; ein "Can't locate object
    # method" waere hier ein Abbruch nach dem Senden.
    if ($mqtt->can('tick')) {
        eval { $mqtt->tick(0) for (1 .. 3); };
    }
    $mqtt->disconnect();
    LOGOK "$gesendet Themen veroeffentlicht.";
}

# Ein MQTT-Thema darf weder Leerzeichen noch Umlaute enthalten. Der Name der
# Person wird deshalb umgeschrieben - dieselbe Ersetzung nutzt die Oberflaeche,
# damit dort steht, was tatsaechlich veroeffentlicht wird.
sub mqtt_topic_name
{
    my ($name) = @_;
    $name = "" if (!defined $name);
    my %umlaut = ("\x{e4}" => "ae", "\x{f6}" => "oe", "\x{fc}" => "ue",
                  "\x{c4}" => "Ae", "\x{d6}" => "Oe", "\x{dc}" => "Ue",
                  "\x{df}" => "ss");
    foreach my $u (keys %umlaut) {
        $name =~ s/\Q$u\E/$umlaut{$u}/g;
    }
    $name =~ s/[^A-Za-z0-9_-]+/_/g;
    $name =~ s/^_+|_+$//g;
    return $name;
}

# Der umlaufende Zaehler, 0..999.
#
# Er beantwortet die Frage "laeuft der Takt noch", die ein Zeitstempel nicht
# beantwortet: ein Raspberry ohne Echtzeituhr macht beim ersten
# Zeitabgleich einen Sprung, und ein Alter kann danach negativ oder
# stundenlang sein, obwohl alles in Ordnung ist. Ein Zaehler nicht.
sub zaehler_lesen
{
    my $alt = zustand_lesen();
    my $z = (ref($alt) eq 'HASH' && defined $alt->{zaehler}) ? int($alt->{zaehler}) : -1;
    return ($z < 0) ? 0 : (($z + 1) % 1000);
}

sub zustand_lesen
{
    my $f = "$lbpdatadir/zustand.json";
    return undef if (!-f $f);
    my $inhalt = '';
    if (open(my $fh, '<', $f)) {
        local $/;
        $inhalt = <$fh>;
        close($fh);
    }
    return undef if (!defined $inhalt || $inhalt eq '');
    my $j = eval { decode_json($inhalt) };
    return (ref($j) eq 'HASH') ? $j : undef;
}

# Das Ergebnis ablegen, damit die Oberflaeche es zeigen kann.
#
# Unteilbar: erst daneben schreiben, dann umbenennen. Die Oberflaeche liest
# dieselbe Datei, und eine halb geschriebene JSON-Datei waere fuer sie
# "noch nie gelaufen" - also genau die Aussage, die sie nicht treffen darf.
sub zustand_schreiben
{
    my ($ok, $fehler, $users) = @_;
    my @p = ();
    foreach my $u (@{$users}) {
        push(@p, { name => $u->{NAME}, online => int($u->{ONLINE}),
                   weg => ($u->{WEG} ne '' ? $u->{WEG} : 'keiner') });
    }
    my $daten = {
        ts       => time(),
        ok       => int($ok),
        zaehler  => zaehler_lesen(),
        fehler   => $fehler,
        weg      => ($udp_enable ? 'udp' : 'mqtt'),
        personen => \@p,
    };
    my $js = eval { JSON->new->canonical(1)->pretty(1)->encode($daten) };
    # json_encode gibt bei ungueltigem UTF-8 nichts Brauchbares zurueck -
    # dann wuerde eine leere Datei geschrieben und Erfolg gemeldet.
    if (!defined $js || $js eq '') {
        LOGERR "Zustandsabbild liess sich nicht erzeugen.";
        return 0;
    }
    mkdir($lbpdatadir) if (!-d $lbpdatadir);
    my $ziel = "$lbpdatadir/zustand.json";
    my $tmp = "$ziel.tmp.$$";
    if (!open(my $fh, '>', $tmp)) {
        LOGERR "Zustandsabbild nicht schreibbar: $tmp";
        return 0;
    } else {
        print $fh $js;
        close($fh);
    }
    chmod(0644, $tmp);
    if (!rename($tmp, $ziel)) {
        LOGERR "Zustandsabbild liess sich nicht umbenennen: $tmp";
        unlink($tmp);
        return 0;
    }
    return 1;
}

# Laeuft unser Listener? Argumentweise gegen den VOLLEN Pfad - "pgrep -f"
# traefe auch einen Editor mit offener Datei oder ein zweites Exemplar des
# Plugins.
sub listener_pid
{
    my ($skript) = @_;
    my $dh;
    return 0 if (!opendir($dh, '/proc'));
    my $treffer = 0;
    while (my $e = readdir($dh)) {
        next if ($e !~ /^[0-9]+$/);
        my $fh;
        next if (!open($fh, '<', "/proc/$e/cmdline"));
        local $/;
        my $roh = <$fh>;
        close($fh);
        next if (!defined $roh || $roh eq '');
        my @args = split(/\0/, $roh);
        if ((defined $args[0] && $args[0] eq $skript)
            || (defined $args[1] && $args[1] eq $skript)) {
            $treffer = $e;
            last;
        }
    }
    closedir($dh);
    return $treffer;
}

# Das Protokoll kappen.
#
# log/plugins liegt auf einer Ramdisk. Bei Loglevel 7 schreibt dieses Skript
# die vollstaendige Ausgabe jedes arping-Aufrufs mit - alle drei Minuten.
# Ueber die Grenze hinaus bleiben die letzten 200 Zeilen stehen; das genuegt,
# um den letzten Lauf nachzulesen, und ist die einzige Zahl, die nicht aus
# der Konfiguration kommt.
sub log_kappen
{
    my ($max_kb) = @_;
    $max_kb = 500 if (!defined $max_kb || $max_kb !~ /^[0-9]+$/ || $max_kb < 50);
    my $f = eval { $log->filename() };
    return if (!defined $f || $f eq '' || !-f $f);
    my $gr = -s $f;
    return if (!defined $gr || $gr <= $max_kb * 1024);

    my $fh;
    return if (!open($fh, '<', $f));
    my @zeilen = <$fh>;
    close($fh);
    return if (scalar(@zeilen) <= 200);
    my @behalten = @zeilen[-200 .. -1];

    my $tmp = "$f.tmp.$$";
    return if (!open($fh, '>', $tmp));
    print $fh "; gekuerzt am " . scalar(localtime()) . " - die Datei war "
            . int($gr / 1024) . " kB gross, die letzten 200 Zeilen sind geblieben.\n";
    print $fh @behalten;
    close($fh);
    if (!rename($tmp, $f)) { unlink($tmp); }
}

sub lox_die
{
    my ($grund) = @_;
    LOGCRIT $grund;
    LOGEND "Abgebrochen.";
    exit 1;
}
