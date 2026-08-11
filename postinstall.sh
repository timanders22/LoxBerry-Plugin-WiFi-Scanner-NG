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
if [ -f "$LISTENER" ]; then
    echo "<INFO> Starting WifiScanner MQTT listener"
    chmod +x "$LISTENER"
    # Gezielt ueber die Befehlszeile, argumentweise: "pkill -f" traefe auch
    # einen Editor mit offener Datei oder ein zweites Exemplar des Plugins.
    for D in /proc/[0-9]*; do
        P=$(basename "$D")
        if tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null | head -2 | grep -qxF "$LISTENER"; then
            kill "$P" 2>/dev/null
        fi
    done
    nohup "$LISTENER" > /dev/null 2>&1 &
fi

# Exit with Status 0
# ---------------------------------------------------------------------------
# Verweise fuer arp, arping und arp-scan
#
# Die sudoers-Datei dieses Plugins nennt die Pfade unter /usr/sbin. Seit dem
# usr-merge in Debian 12/13 koennen die Programme aber auch unter /usr/bin
# liegen - dann findet sudo den in der Regel eingetragenen Pfad nicht, und
# der Aufruf wird abgewiesen. Ein Verweis loest das, ohne die sudoers-Datei
# aufzuweichen: dort darf weiterhin genau ein Pfad je Programm stehen.
for W in arp arping arp-scan; do
    if [ ! -e "/usr/sbin/$W" ]; then
        ECHT=$(command -v "$W" 2>/dev/null)
        if [ -n "$ECHT" ]; then
            ln -sfn "$ECHT" "/usr/sbin/$W" 2>/dev/null \
                && echo "<OK> Verweis angelegt: /usr/sbin/$W -> $ECHT" \
                || echo "<INFO> Verweis /usr/sbin/$W liess sich nicht anlegen (nicht als root?)."
        else
            echo "<INFO> $W ist nicht installiert - das Paket steht in dpkg/apt."
        fi
    fi
done


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-wifi_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "mqtt_subscriptions.cfg" "8e8f8a5e3c6ba7c6fbfe7d6fed9f81f663067d3964501f51ad051cd65d6d5d98"
netz_zurueck "wifi_scanner.cfg" "65cd35264fe46964f963ba876c3905207946052c4e41bb5ffc8a11cebaec3e36"

exit 0
