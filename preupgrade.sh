#!/bin/sh

ARGV0=$0 # Zero argument is shell command
ARGV1=$1 # First argument is temp folder during install
ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV4=$4 # Forth argument is Plugin version
ARGV5=$5 # Fifth argument is Base folder of LoxBerry
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

# ---------- Marke "Aktualisierung laeuft" ----------
#
# ALS ERSTES, vor jedem anderen Schritt - ab hier gilt die Anlage als in
# Arbeit. Sie liegt NEBEN dem Datenordner: purge_installation loescht
# data/plugins/<ordner>/ ohne jede Bedingung (Regeln/06), der Nachbar mit
# dem Punkt im Namen bleibt.
#
# WARUM DIESE LINIE SIE BRAUCHT - am 18.09.2026 in WSL gemessen
# (Pruefung-WiFi-Scanner-NG-3.2.7/Pruefstaende/messe_luecke.sh):
#
#   Fall B1b: wird die Oberflaeche in der Luecke geoeffnet, findet sie die
#   mitgelieferte Vorgabe ohne Merkwort, erzeugt eines und speichert - und
#   ws_config_write() zieht dabei die Zweitschrift
#   config/plugins/<ordner>.wifi_scanner.backup mit. Gemessen: aus
#   "TOKEN=ECHTESMERKWORT..., USERS=2, MACS=<erfundene Adresse>" wurde
#   "TOKEN=5b09b7cf27a433e51ca1c2a9, USERS=0, MACS=" - in der Datei UND in
#   der Zweitschrift. (Im Pruefstand standen ausschliesslich erfundene
#   Werte; eine Adresse gehoert auch als Beispiel in keine Quelldatei.)
#
#   Fall B1c: postinstall.sh holt danach NICHTS mehr zurueck. Seine Probe
#   vergleicht die Pruefsumme mit der mitgelieferten Vorgabe, und die stimmt
#   nach dem Schreiben nicht mehr. Gemessen: nur die Zeile
#   "<OK> mqtt_subscriptions.cfg aus der Zweitschrift wiederhergestellt.",
#   die fuer wifi_scanner.cfg blieb aus. In der Gegenprobe C2 (ohne
#   Seitenaufruf) steht sie.
#
#   Fall C: postupgrade.sh rettet wifi_scanner.cfg aus der
#   upgrade_sicherung - die Zweitschrift daneben bleibt aber kaputt. Genau
#   aus ihr heilt ws_config_read() spaeter, und nur sie uebersteht eine
#   Neuinstallation. Der Schaden ueberlebt das Upgrade also.
#
# Der Startweg daemon/daemon und die Oberflaeche lesen die Marke;
# postupgrade.sh - das letzte Hakenskript dieser Linie - entfernt sie
# NACH dem Start.
WS_MARKE="$LBHOMEDIR/data/plugins/${3:-wifi_ng}.upgrade_laeuft"
mkdir -p "$LBHOMEDIR/data/plugins" 2>/dev/null
date +%s > "$WS_MARKE" 2>/dev/null
if [ -s "$WS_MARKE" ]; then
    chmod 0644 "$WS_MARKE" 2>/dev/null
    echo "<OK> Start und Oberflaeche bis zum Ende der Installation gesperrt."
else
    echo "<WARNING> Die Marke $WS_MARKE liess sich nicht anlegen - der Listener"
    echo "<WARNING> kann waehrend der Installation starten, und ein Aufruf der"
    echo "<WARNING> Oberflaeche kann in dieser Zeit die Zweitschrift der"
    echo "<WARNING> Einstellungen ueberschreiben."
fi

# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg. Und /tmp ist fuer
# jeden lesbar - in der Konfiguration stehen MAC-Adressen und Namen der
# ueberwachten Personen, also eine Anwesenheitsliste des Haushalts.
# Geaendert am 10.08.2026.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"
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
