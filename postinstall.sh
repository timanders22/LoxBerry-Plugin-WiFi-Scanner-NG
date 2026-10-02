#!/bin/sh

# Bashscript which is executed by bash *AFTER* complete installation is done
# (but *BEFORE* postupdate). Use with caution and remember, that all systems
# may be different! Better to do this in your own Pluginscript if possible.
#
# Exit code must be 0 if executed successfull.
#
# Will be executed as user "loxberry".
#
# We add 5 arguments when executing the script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
ARGV0=$0 # Zero argument is shell command
echo "<INFO> Command is: $ARGV0"

ARGV1=$1 # First argument is temp folder during install
echo "<INFO> Temporary folder is: $ARGV1"

ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
echo "<INFO> (Short) Name is: $ARGV2"

ARGV3=$3 # Third argument is Plugin installation folder
echo "<INFO> Installation folder is: $ARGV3"

ARGV4=$4 # Forth argument is Plugin version
echo "<INFO> Installation folder is: $ARGV4"

ARGV5=$5 # Fifth argument is Base folder of LoxBerry
echo "<INFO> Base folder is: $ARGV5"

# Start the MQTT command listener (also started at boot via daemon script)
LISTENER=$ARGV5/bin/plugins/$ARGV3/mqtt_listener.pl

# Gehoert diese Prozessnummer unserem Listener?
#
# argv[0] ist ein perl, argv[1] ist ZEICHENGENAU unser Pfad, und es gibt kein
# drittes Argument. Bis 3.2.6 stand hier "head -2 | grep -qxF <pfad>" - das
# fragt nicht, WER die Datei in der Hand hat. Gemessen am 18.09.2026 in WSL
# (Pruefung-WiFi-Scanner-NG-3.2.7/Pruefstaende/messe_koeder.sh): ein Koeder
# "tail <listenerpfad> -f" - GNU tail vertauscht Option und Dateiname, der
# Pfad steht damit in argv[1] - wurde von der gleichlautenden Stelle in
# postupgrade.sh beendet (Fall K4, "Koeder IST TOT"). Dieselbe Bauart stand
# in vier Dateien dieser Linie; alle vier sind in 3.2.7 umgestellt.
#
# Die Lesbarkeitsprobe zuerst: das "2>/dev/null" hing bis 3.2.6 an tr, nicht
# an der Umleitung - ein Prozess, der waehrenddessen endet, schrieb eine
# Schalenfehlermeldung in das Installationsprotokoll (13 Zeilen in einem
# einzigen Lauf gemessen, Fall K5).
ws_ist_listener() {
    [ -r "/proc/$1/cmdline" ] || return 1
    ws_n=0
    ws_treffer=0
    while IFS= read -r ws_arg; do
        ws_n=$((ws_n + 1))
        if [ "$ws_n" = 1 ]; then
            case "${ws_arg##*/}" in
                perl|perl5*) ;;
                *) return 1 ;;
            esac
        elif [ "$ws_n" = 2 ] && [ "$ws_arg" = "$LISTENER" ]; then
            ws_treffer=1
        fi
    done <<WS_ARGUMENTE
$( { tr '\0' '\n' < "/proc/$1/cmdline"; } 2>/dev/null )
WS_ARGUMENTE
    [ "$ws_treffer" = 1 ] && [ "$ws_n" = 2 ]
}

# I5 (Durchgang 02.10.2026): angehalten und gestartet wird erst am ENDE dieses
# Skripts, NACH dem Zurueckspielen. Bis 3.2.9 startete der Listener hier, vor
# dem Zurueckspielen und ohne Blick auf die Upgrade-Marke - er las die
# mitgelieferte Vorgabe und sendete status/mode, /interval und /enabled daraus
# retained (Installer-Pruefer Nr. 5, Faelle U1 und N1). Bei liegender Marke
# wird nur angehalten; postupgrade.sh startet ihn mit der zurueckgespielten
# Konfiguration (Regeln/06).
#
# Auf das Ende WARTEN, bevor ein neuer startet: der alte haengt sonst noch am
# Broker, und zwei Listener beantworten jeden Befehl doppelt.
ws_listener_anhalten() {
    for D in /proc/[0-9]*; do
        P=${D#/proc/}
        if ws_ist_listener "$P"; then
            kill "$P" 2>/dev/null
            for i in 1 2 3 4 5; do
                kill -0 "$P" 2>/dev/null || break
                sleep 1
            done
            kill -9 "$P" 2>/dev/null
        fi
    done
}
ws_listener_starten() {
    [ -f "$LISTENER" ] || return 0
    echo "<INFO> Starting WifiScanner MQTT listener"
    chmod +x "$LISTENER"
    nohup perl "$LISTENER" > /dev/null 2>&1 &
}

# I7 (Durchgang 02.10.2026): hier stand bis 3.2.9 ein Block, der fuer arp,
# arping und arp-scan Verweise in /usr/sbin anlegen sollte. postinstall.sh
# laeuft als loxberry; am Geraet hat er deshalb nie etwas angelegt, und als
# root angelegte Verweise blieben nach der Deinstallation liegen
# (Installer-Pruefer Nr. 7, Fall S1). Wo die Werkzeuge liegen und ob die
# sudoers-Regel passt, zeigt jetzt der Reiter Test.

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# nicht vollstaendig (Inhaltsprobe unten), oder sie ist zeichengenau die
# mitgelieferte Vorgabe (Pruefsumme unten). Der letzte Fall ist der
# eigentliche: genau so sieht die Datei nach dem Kopierschritt des
# Installers aus.
#
# Bis 3.2.7 hiess "nicht vollstaendig" nur "leer" ([ ! -s ]). Eine
# abgeschnittene Konfiguration ist nicht leer und stimmt nicht mit der
# Vorgabe ueberein - sie blieb stehen, die heile Zweitschrift daneben wurde
# nicht geholt. Gemessen am 18.09.2026 in WSL
# (Pruefung-WiFi-Scanner-NG-3.2.8/Pruefstaende/messe_welle2.sh, Fall C6).
# Umgekehrt wurde eine abgeschnittene Zweitschrift ungeprueft eingespielt
# (Fall C9). Geheilt wird jetzt nur aus einer heilen Zweitschrift, und der
# verdraengte Stand bleibt als <datei>.kaputt (0600) liegen.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
#
# Die Inhaltsprobe ist gleichlautend mit preupgrade.sh; beide Stellen
# zusammen pflegen.
ws_cfg_heil() {
    [ -f "$1" ] && [ -r "$1" ] || return 1
    [ "$(tail -c 1 "$1" 2>/dev/null | wc -l)" -eq 1 ] || return 1
    awk '
        /^[ \t]*$/ || /^[ \t]*[;#]/ { next }
        /^[ \t]*\[[^]]+\][ \t]*$/ {
            ab = $0; gsub(/[][ \t]/, "", ab); ab = toupper(ab); gesehen[ab] = 1; next
        }
        index($0, "=") > 1 {
            k = substr($0, 1, index($0, "=") - 1); gsub(/[ \t]/, "", k); k = toupper(k)
            v = substr($0, index($0, "=") + 1); gsub(/^[ \t]+|[ \t\r]+$/, "", v)
            if (ab == "BASE" && k == "USERS") { users = v }
            if (k == "NAME" || k == "MACS") { hat[ab SUBSEP k] = 1 }
            next
        }
        { kaputt = 1 }
        END {
            if (kaputt || !("BASE" in gesehen) || users !~ /^[0-9]+$/) { exit 1 }
            for (i = 1; i <= users + 0; i++) {
                if (!(("USER" i) SUBSEP "NAME" in hat) || !(("USER" i) SUBSEP "MACS" in hat)) { exit 1 }
            }
            exit 0
        }' "$1" 2>/dev/null
}
ws_abo_heil() {
    [ -f "$1" ] && [ -r "$1" ] || return 1
    [ "$(tail -c 1 "$1" 2>/dev/null | wc -l)" -eq 1 ] || return 1
    grep -q '[^[:space:]]' "$1" 2>/dev/null
}
ws_heil() {   # ws_heil cfg|abo <datei>
    case "$1" in
        cfg) ws_cfg_heil "$2" ;;
        abo) ws_abo_heil "$2" ;;
        *)   return 1 ;;
    esac
}
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-wifi_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {   # netz_zurueck <datei> <pruefsumme der vorgabe> cfg|abo
    datei=$1; soll=$2; art=$3
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    kaputt=0
    if [ ! -f "$ziel" ]; then
        verloren=1
    elif ! ws_heil "$art" "$ziel"; then
        verloren=1
        [ -s "$ziel" ] && kaputt=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    [ "$verloren" = "1" ] || return 0
    if ! ws_heil "$art" "$zweit"; then
        echo "<WARNING> $datei: die Zweitschrift ist selbst nicht vollstaendig und wird"
        echo "<WARNING> nicht eingespielt. Sie liegt unveraendert unter $zweit."
        return 0
    fi
    rm -f "$ziel.neu" 2>/dev/null
    if cp -p "$zweit" "$ziel.neu" 2>/dev/null && cmp -s "$zweit" "$ziel.neu"; then
        if [ "$kaputt" = "1" ]; then
            if cp -p "$ziel" "$ziel.kaputt" 2>/dev/null && chmod 0600 "$ziel.kaputt" 2>/dev/null; then
                echo "<WARNING> $datei war nicht vollstaendig; der bisherige Stand liegt als $datei.kaputt daneben."
            else
                rm -f "$ziel.neu" 2>/dev/null
                echo "<WARNING> $datei ist nicht vollstaendig, liess sich aber nicht beiseitelegen -"
                echo "<WARNING> nichts geaendert. Die Zweitschrift liegt unter $zweit."
                return 0
            fi
        fi
        if mv -f "$ziel.neu" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
            return 0
        fi
    fi
    rm -f "$ziel.neu" 2>/dev/null
    echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
    echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
}
# I1 (Durchgang 02.10.2026, Entscheidung 1): zurueckgespielt wird NUR bei
# liegender Upgrade-Marke - ohne Altersgrenze. Bis 3.2.9 fragte netz_zurueck
# die Marke nie ab und spielte bei jeder Neuinstallation die Zweitschriften
# einer frueheren Installation ein (Installer-Pruefer Nr. 1). preinstall.sh hat
# sie in diesem Fall schon nach .alt gelegt.
WS_MARKE="$NETZ_BASE/data/plugins/$NETZ_PDIR.upgrade_laeuft"
if [ -f "$WS_MARKE" ]; then
    netz_zurueck "mqtt_subscriptions.cfg" "8e8f8a5e3c6ba7c6fbfe7d6fed9f81f663067d3964501f51ad051cd65d6d5d98" abo
    netz_zurueck "wifi_scanner.cfg" "30bcb5717482b3b0aa670ce91a023ab7100fac5db98d0e271e879555a541fc3a" cfg
    # I5: nur anhalten - postupgrade.sh startet mit der zurueckgespielten Konfiguration.
    ws_listener_anhalten
else
    echo "<INFO> Neuinstallation - aus Zweitschriften wird nichts zurueckgespielt."
    ws_listener_anhalten
    ws_listener_starten
fi

exit 0
