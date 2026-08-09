#!/usr/bin/perl -w
##########################################################################
# Script zur Anwesenheitserkennung von WLAN-Geräten                      #
# an einer Fritz!Box 7490 in Verbindung mit einem                        #
# Loxone Miniserver                                                      #
# Version: 2016.02.27.15.09.14                                           #
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
# Modules
##########################################################################

use LoxBerry::System;
use LoxBerry::Log;

use LWP::Simple;
use LWP::UserAgent;
use XML::Simple;
use JSON qw( decode_json );
use Getopt::Long;
use Config::Simple;
use File::HomeDir;
use Cwd 'abs_path';
use open qw(:std :utf8);
use POSIX qw/ strftime /;
use IO::Socket;

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
# /usr/sbin an. Hier wird der gefundene Pfad nur benutzt, wenn er dem in
# sudoers eingetragenen entspricht.
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
use Net::MQTT::Simple;
use LoxBerry::IO;
use Data::Validate::IP;
use Capture::Tiny qw/capture/;

sub sendFoundUsers();
sub mqtt_topic_name($);
sub mac2ip($);
sub ping($);
sub lox_die($);

##########################################################################
# Read Settings
##########################################################################

# Version of this script
$version = LoxBerry::System::pluginversion();

my $log = LoxBerry::Log->new ( name => 'wifi_scanner' , addtime => 1, );

%miniservers = LoxBerry::System::get_miniservers();

my $pcfg            = new Config::Simple("$lbpconfigdir/wifi_scanner.cfg");
my $udpport         = $pcfg->param("BASE.PORT");
my $fritz_enable    = $pcfg->param("BASE.FRITZBOX_ENABLE");
my $ip              = $pcfg->param("BASE.FRITZBOX");
my $port            = $pcfg->param("BASE.FRITZBOX_PORT");
my $active_scan     = $pcfg->param("BASE.ACTIVE_SCAN");
my $ping_cmd        = $pcfg->param("BASE.PING_CMD");
my $use_cache       = $pcfg->param("BASE.USE_CACHE");
my $user_count      = $pcfg->param("BASE.USERS");
my $udp_enable      = $pcfg->param("BASE.UDP_ENABLE");


# Commandline options
my $verbose = '';
my $help = '';
my $mode = '';

GetOptions ('verbose' => \$verbose,
            'mode=s'  => \$mode,
            'quiet'   => sub { $verbose = 0 });

# Starting...
LOGSTART "Starting $0 Version $version";

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

if (! %miniservers) {
    lox_die "No Miniservers configured";
}

LOGDEB "Reading configuration file";
my @users = ();
for ($i=1;$i<=$user_count;$i++) {
    my %user;
    $user{NAME} = $pcfg->param("USER$i.NAME");
    LOGDEB "Found config for $user{NAME}";
    my $input = $pcfg->param("USER$i.MACS");
    my @input_splitted = split /;/, $input;

    my @ips = ();
    my @macs = ();
    LOGDEB "Identifing macs in $input";
    foreach my $in (@input_splitted) {
        if ($in =~ /^([A-fa-f0-9]{2}:){5}([A-fa-f0-9]{2})$/) {
            LOGDEB "Identified $in as MAC ADDRESS";
            $mac = lc $in;
            push(@macs, $mac);
        } else {
            LOGDEB "Identified $in as IP ADDRESS";
            push(@ips, $in);
        }
    }
    $user{MACS} = \@macs;
    $user{IPS} = \@ips;
    $user{ONLINE} = 0;
    push(@users, \%user);
}

my $user_online = 0;

if ($fritz_enable) {
    LOGINF "Establishing connection to the Router to check for mac addresses";
    # disable SSL checks. No signed certificate!
    $ENV{'PERL_LWP_SSL_VERIFY_HOSTNAME'} = 0;
    $ENV{HTTPS_DEBUG} = 1;

    # Discover Service Parameters
    my $ua = new LWP::UserAgent;
    $ua->default_headers;
    $ua->ssl_opts( verify_hostname => 0 ,SSL_verify_mode => 0x00);

    # Read all available services
    my $resp_discover = $ua->get("https://$ip:$port/tr64desc.xml");
    my $xml_discover;
    if ( $resp_discover->is_success ) {
        $xml_discover = $resp_discover->decoded_content;
    } else {
        lox_die $resp_discover->status_line;
    }
    my $discover = XMLin($xml_discover);
    LOGINF "$discover->{device}->{modelName} detected...";

    # Parse XML service response, get needed parameters for LAN host service
    my $control_url = "not set";
    my $service_type = "not set";
    my $service_command = "GetSpecificHostEntry"; # fixed command for requesting info of specific MAC
    foreach(@{$discover->{device}->{deviceList}->{device}->[0]->{serviceList}->{service}}) {
        if("urn:LanDeviceHosts-com:serviceId:Hosts1" =~ m/.*$_->{serviceId}.*/) {
            $control_url = $_->{controlURL};
            $service_type = $_->{serviceType};
        }
    }

    if ($control_url eq "not set" or $service_type eq "not set") {
        lox_die "control URL/service type not found. Cannot request host info!";
    }

    # Prepare request for query LAN host
    $ua->default_header( 'SOAPACTION' => "$service_type#$service_command" );

    for ($i=0;$i<$user_count;$i++) {
        my @macs = @{$users[$i]{MACS}};
        if (scalar(@macs) == 0) {
            LOGINF "Skipping $users[$i]{NAME}. No mac addresses provided";
            next;
        }

        LOGINF "Checking devices from User: $users[$i]{NAME}";
        foreach my $mac (@macs) {
            my $init_request = <<EOD;
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

            my $init_url = "https://$ip:$port$control_url";
            my $resp_init = $ua->post($init_url, Content_Type => 'text/xml; charset=utf-8', Content => $init_request);
            my $response = $resp_init->decoded_content;
            my $xml_mac_resp = XMLin($response);

            $response =~ s/&/&amp;/ig;
            $response =~ s/</&lt;/ig;

            LOGDEB "FritzBox Response:\n$response";

            if(exists $xml_mac_resp->{'s:Body'}->{'s:Fault'}) {
                if($xml_mac_resp->{'s:Body'}->{'s:Fault'}->{detail}->{UPnPError}->{errorCode} eq "714") {
                    LOGERR "Mac $mac not found in FritzBox Database!\n";
                }
            }
            if(exists $xml_mac_resp->{'s:Body'}->{'u:GetSpecificHostEntryResponse'})
            {
                my $active = $xml_mac_resp->{'s:Body'}->{'u:GetSpecificHostEntryResponse'}->{NewActive};
                my $name = $xml_mac_resp->{'s:Body'}->{'u:GetSpecificHostEntryResponse'}->{NewHostName};
                my $ip = $xml_mac_resp->{'s:Body'}->{'u:GetSpecificHostEntryResponse'}->{NewIPAddress};
                my $iftype =  $xml_mac_resp->{'s:Body'}->{'u:GetSpecificHostEntryResponse'}->{NewInterfaceType};
                if ($active eq "1") {
                    LOGINF "Mac $mac ($name) is online with IP $ip on $iftype";
                    $users[$i]{ONLINE} = 1;
                    $user_online = 1;
                } else {
                    LOGINF "Mac $mac ($name) is offline";
                }
                my @ips = @{$users[$i]{IPS}};
                push(@ips, $ip);
                $users[$i]{IPS} = \@ips;
            }
        }
    }
} else {
    LOGINF "Ping devices without asking the Router first";
}

if ($user_online || !$active_scan) {
    LOGDEB "Send infos about the online users";
    sendFoundUsers()
}

if ($active_scan) {
    LOGDEB "Iterating over all users to do actives scans where needed";
    for ($i=0;$i<$user_count;$i++) {
        my %user = %{$users[$i]};
        if ($user{ONLINE}) {
            LOGDEB "Skipping $user{NAME}, because we already have a result";
            next;
        }

        LOGINF "Pinging Devices for user: $user{NAME}";
        my @macs = @{$user{MACS}};
        my @ips = @{$user{IPS}};

        $log_cmd = ">> /dev/null 2>&1";
        if ($log->loglevel() >= 7) {
            my $logfile = $log->filename();
           # Der Pfad gehoert in Anfuehrungszeichen: er wird gleich in eine
           # Shell-Befehlszeile eingesetzt, und ein Leerzeichen darin waere
           # dort ein Trennzeichen statt eines Namensbestandteils.
           $log_cmd = ">> \"$logfile\" 2>&1";
        }

        $found = 0;
        # Check with ip addresses
        foreach my $ip (@ips) {
            if (ping($ip)) {
                $found = 1;
                $users[$i]{ONLINE} = 1;
                last;
            }
        }

        # If one of the device was found by pinging the IP addresses no need to check the mac addresses
        if ($found) {
            next;
        }

        # Check with mac addresses
        foreach my $mac (@macs) {
            LOGINF "Trying to get ip address for $mac";
            my $ip = mac2ip($mac);
            if (not $ip eq "") {
                if (grep { $_ eq $ip } @ips) {
                    LOGINF "Skipping $mac ($ip) as it was already scanned";
                    next;
                }
                push(@ips, $ip);
            } else {
                # If we couldn't determine an ip address try to scan the mac address instead
                $ip = $mac;
            }

            if (ping($ip)) {
                $users[$i]{ONLINE} = 1;
                last;
            }
        }
    }
    # send Data
    sendFoundUsers();
}
LOGEND "Operation finished sucessfully.";


sub sendFoundUsers()
{
    if ($udp_enable) {
        foreach my $ms (sort keys %miniservers) {
            # Send value
            my $sock = IO::Socket::INET->new(
                 Proto    => 'udp',
                 PeerPort => $udpport,
                 PeerAddr => $miniservers{$ms}{IPAddress},
                 Type        => SOCK_DGRAM
            ) or lox_die "Could not create socket: $!";

            for ($j=0;$j<$user_count;$j++) {
                LOGOK "Sending Data '$users[$j]{NAME}:$users[$j]{ONLINE}' to $miniservers{$ms}{Name} IP: $miniservers{$ms}{IPAddress} Port:$udpport";
                $sock->send("$users[$j]{NAME}:$users[$j]{ONLINE}") or lox_die "Send error: $!";
            }
            $sock->close();
        }
    } else {
        ##MQTT publish

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

        # Connect to broker
        my $mqtt = Net::MQTT::Simple->new($mqttcred->{brokeraddress});

        # Depending if authentication is required, login to the broker
        if($mqttcred->{brokeruser} and $mqttcred->{brokerpass}) {
            $mqtt->login($mqttcred->{brokeruser}, $mqttcred->{brokerpass});
        }

            for ($j=0;$j<$user_count;$j++) {
                my $topic = "wifi_ng/" . mqtt_topic_name($users[$j]{NAME});
                LOGOK "Sending '$users[$j]{ONLINE}' to $topic on MQTT broker $mqttcred->{brokeraddress}";
                $mqtt->retain($topic, $users[$j]{ONLINE}) or lox_die "Send error: $!";
                }
        $mqtt->disconnect();
    }
}

# Ein MQTT-Thema darf weder Leerzeichen noch Umlaute enthalten. Der Name der
# Person wird deshalb umgeschrieben - dieselbe Ersetzung nutzt die Oberflaeche,
# damit dort steht, was tatsaechlich veroeffentlicht wird.
sub mqtt_topic_name($)
{
    my $name = $_[0];
    $name = "" if (!defined $name);
    my %umlaut = ("\x{e4}" => "ae", "\x{f6}" => "oe", "\x{fc}" => "ue",
                  "\x{c4}" => "Ae", "\x{d6}" => "Oe", "\x{dc}" => "Ue",
                  "\x{df}" => "ss");
    foreach my $u (keys %umlaut) {
        $name =~ s/$u/$umlaut{$u}/g;
    }
    $name =~ s/[^A-Za-z0-9_-]+/_/g;
    $name =~ s/^_+|_+$//g;
    return $name;
}

sub mac2ip($)
{
    my $mac = $_[0];
    my $validator=Data::Validate::IP->new;
    my $ip = "";
    if ($use_cache) {
        $ip = `$ARP -e -n | grep $mac | cut -f 1 -d ' '`;
        chomp($ip);
    }
    if (!$validator->is_ipv4($ip)) {
        if ($use_cache) {
            LOGINF "Couldn't find mac in arp table (cache). Doing active scan";
        } else {
            LOGINF "Skipping to check arp table (cache). Doing active scan";
        }
        my $stderr;
        ($ip, $stderr) = capture {
          system ( "sudo $ARPSCAN --destaddr=$mac --localnet -N --ignoredups | grep $mac | cut -f 1" );
        };
        chomp($ip);
        if($validator->is_ipv4($ip)) {
            if ($use_cache) {
                LOGINF "Found $ip, adding the mac to arp table (cache)";
                system("sudo $ARP -s $ip $mac");
            } else {
                LOGDEB "Found $ip";
            }
        } else {
            LOGINF "Couldn't determine the mac address error: $stderr";
        }
    } else {
        LOGDEB "Found $ip";
    }

    return $ip;
}

sub ping($)
{
    my $ip = $_[0];
    LOGINF "Ping $ip";
    if ($ping_cmd == 0) {
        # This sends really a lot of request, but it makes sure we get an answer as fast as possible
        if (system("sudo $ARPING -W 0.2 -c 20 -C1 $ip $log_cmd") == 0) {
            LOGINF "Host $ip is online";
            return 1;
        }
    } elsif ($ping_cmd == 1) {
        if (system("/bin/ping -i 0.2 -c 3 $ip $log_cmd") == 0) {
            LOGINF "Host $ip is online";
            return 1;
        }
    } else {
        LOGERR "Invalid ping cmd configuration";
    }

    LOGINF "Host $ip is offline";
    return 0;
}

sub lox_die($)
{
    LOGCRIT $_[0];
    exit(1);
}
