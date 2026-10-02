#!/bin/sh
# WiFi Scanner NG - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# I1 (Durchgangsbau vom 02.10.2026; Entscheidung 1 vom 29.09.2026, X-1;
# Muster: Govee 0.9.24 und Abfahrts-Assistent 1.6.19). Der Installer ruft
# dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und
# VOR dem Kopieren von Konfiguration, Cron und Oberflaeche.
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschriften braucht
# postinstall.sh zum Zurueckspielen.
#
# Ohne Marke ist es eine NEUINSTALLATION. Bis 3.2.9 stand hier nur die leere
# Vorlage, und postinstall.sh holte liegengebliebene Zweitschriften einer
# frueheren Installation zurueck - samt altem Aktionsmerkwort,
# Fritz!Box-Kennwort und Anwesenheitsliste (Installer-Pruefer Nr. 1, Fall N1);
# fehlte die Konfiguration, tat die Selbstheilung der Oberflaeche dasselbe (Fall
# N1c). Jetzt gehen die drei Zweitschriften und eine liegengebliebene
# Update-Sicherung nach <name>.alt (0600 bzw. 0700), gemeldet mit genau einer
# <WARNING>. Die Selbstheilung liest .alt nie; die Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-wifi_ng}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in uninstall/uninstall: ohne config/plugins, data/plugins UND
# config/system/general.json wird nichts angefasst (Regeln/06).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

SB="$BASE/config/plugins/$PFOLDER"
BEISEITE=""
FEST=""
for ZIEL in "$SB.backup.wifi_scanner.cfg" "$SB.backup.mqtt_subscriptions.cfg" "$SB.wifi_scanner.backup" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
            if [ -d "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ]; then
                chmod 700 "$ZIEL.alt" 2>/dev/null
            elif [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ]; then
                chmod 600 "$ZIEL.alt" 2>/dev/null
            fi
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    WS_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && WS_TEXT="$WS_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && WS_TEXT="$WS_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$WS_TEXT"
fi
exit 0
