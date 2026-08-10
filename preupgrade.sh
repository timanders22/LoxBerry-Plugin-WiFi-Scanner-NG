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
exit 0
