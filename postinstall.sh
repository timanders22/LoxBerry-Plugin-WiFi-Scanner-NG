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

exit 0
