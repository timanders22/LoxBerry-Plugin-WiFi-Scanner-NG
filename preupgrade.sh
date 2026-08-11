#!/bin/sh

ARGV0=$0 # Zero argument is shell command
ARGV1=$1 # First argument is temp folder during install
ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV4=$4 # Forth argument is Plugin version
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg. Und /tmp ist fuer
# jeden lesbar - in der Konfiguration stehen MAC-Adressen und Namen der
# ueberwachten Personen, also eine Anwesenheitsliste des Haushalts.
# Geaendert am 10.08.2026.
SICHER="$ARGV5/data/plugins/$ARGV3/upgrade_sicherung"
echo "<INFO> Creating backup folder for upgrading"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER/config" "$SICHER/log"
chmod 0700 "$SICHER" 2>/dev/null
#mkdir -p /tmp/$ARGV1\_upgrade/log
#mkdir -p /tmp/$ARGV1\_upgrade/files

echo "<INFO> Backing up existing config files"
cp -p -r "$ARGV5/config/plugins/$ARGV3/." "$SICHER/config/" 2>/dev/null

echo "<INFO> Backing up existing log files"
cp -p -r "$ARGV5/log/plugins/$ARGV3/." "$SICHER/log/" 2>/dev/null

# Exit with Status 0

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-wifi_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/mqtt_subscriptions.cfg" ]; then
    cp -p "$NETZ_CFG/mqtt_subscriptions.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.mqtt_subscriptions.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.mqtt_subscriptions.cfg" 2>/dev/null
fi
if [ -s "$NETZ_CFG/wifi_scanner.cfg" ]; then
    cp -p "$NETZ_CFG/wifi_scanner.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.wifi_scanner.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.wifi_scanner.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
