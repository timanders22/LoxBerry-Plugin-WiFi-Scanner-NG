#!/bin/sh

ARGV0=$0 # Zero argument is shell command
ARGV1=$1 # First argument is temp folder during install
ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV4=$4 # Forth argument is Plugin version
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

# Die Sicherung liegt seit dem 10.08.2026 unter data/ statt unter /tmp: /tmp
# ist auf dem LoxBerry eine Ramdisk und ausserdem fuer jeden lesbar.
#
# Der alte Pfad benutzte ausserdem $ARGV1 - das ist NICHT der Arbeitsordner,
# sondern eine zehnstellige Zufallskennung des Installers.
SICHER="$ARGV5/data/plugins/$ARGV3/upgrade_sicherung"
if [ -d "$SICHER" ]; then
    echo "<INFO> Copy back existing config files"
    cp -p -r "$SICHER/config/." "$ARGV5/config/plugins/$ARGV3/" 2>/dev/null

    echo "<INFO> Copy back existing log files"
    mkdir -p "$ARGV5/log/plugins/$ARGV3"
    cp -p -r "$SICHER/log/." "$ARGV5/log/plugins/$ARGV3/" 2>/dev/null

    echo "<INFO> Remove backup folder"
    rm -rf "$SICHER"
else
    echo "<INFO> Keine Sicherung vorhanden - offenbar eine Erstinstallation."
fi

# Restart the MQTT command listener with the new version
LISTENER=$ARGV5/bin/plugins/$ARGV3/mqtt_listener.pl
if [ -f "$LISTENER" ]; then
    echo "<INFO> Restarting WifiScanner MQTT listener"
    chmod +x "$LISTENER"
    # Gezielt ueber die Befehlszeile, argumentweise: "pkill -f" traefe auch
    # einen Editor mit offener Datei oder ein zweites Exemplar des Plugins.
    for D in /proc/[0-9]*; do
        P=$(basename "$D")
        if tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null | head -2 | grep -qxF "$LISTENER"; then
            kill "$P" 2>/dev/null
            # Auf das Ende warten, bevor der neue startet. Bis 2.5.1 folgte
            # der Start unmittelbar - der alte Prozess haengt dann noch am
            # Broker, und zwei Listener beantworten dieselben Themen.
            for i in 1 2 3 4 5; do
                kill -0 "$P" 2>/dev/null || break
                sleep 1
            done
            kill -9 "$P" 2>/dev/null
        fi
    done
    nohup perl "$LISTENER" > /dev/null 2>&1 &
fi

# Exit with Status 0
exit 0
