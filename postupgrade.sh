#!/bin/sh

ARGV0=$0 # Zero argument is shell command
ARGV1=$1 # First argument is temp folder during install
ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV4=$4 # Forth argument is Plugin version
ARGV5=$5 # Fifth argument is Base folder of LoxBerry
LBHOMEDIR="${LBHOMEDIR:-$5}"
# W4 (Gruppe 14): dieselbe Torbedingung wie preinstall.sh - ohne config/plugins, data/plugins UND
# config/system/general.json unter der Wurzel und mit einem Ordnernamen ohne Pfadtrenner wird nichts angefasst.
# Bis 3.2.14 rechneten die Skripte ohne Argumente mit Pfaden wie /data/plugins/.upgrade_sicherung (gemessen,
# Pruefbericht W4). plugininstall.pl uebergibt immer fuenf Argumente (Regeln/06) - das Tor gilt Handaufrufen.
BASE="${5:-$LBHOMEDIR}"
PDIR="${3:-wifi_ng}"
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts angefasst."
    exit 0
fi
case "$PDIR" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PDIR' - nichts angefasst."; exit 0 ;;
esac

# Die Sicherung liegt seit dem 10.08.2026 unter data/ statt unter /tmp: /tmp
# ist auf dem LoxBerry eine Ramdisk und ausserdem fuer jeden lesbar.
#
# Der alte Pfad benutzte ausserdem $ARGV1 - das ist NICHT der Arbeitsordner,
# sondern eine zehnstellige Zufallskennung des Installers.
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"
if [ -d "$SICHER" ]; then
    echo "<INFO> Copy back existing config files"
    # I2 (Durchgang 02.10.2026): jede Datei erst nach <datei>.neu, mit cmp
    # nachgesehen, dann umbenannt - wie preupgrade.sh es beim Sichern tut. Die
    # Sicherung faellt nur, wenn JEDE Datei byteweise angekommen ist. Bis 3.2.9
    # kopierte cp direkt ueber die Konfiguration und loeschte die Sicherung ohne
    # Pruefung: bei voller Karte (ulimit -f 0) stand danach eine Konfiguration
    # mit 0 Byte da, und die Sicherung war fort (Installer-Pruefer Nr. 2, U2).
    WS_ZIEL="$BASE/config/plugins/$PDIR"
    WS_FEHLT=""
    mkdir -p "$WS_ZIEL" 2>/dev/null
    for WS_Q in "$SICHER/config/"* "$SICHER/config/".[!.]*; do
        [ -e "$WS_Q" ] || continue
        WS_N=$(basename "$WS_Q")
        if [ ! -f "$WS_Q" ]; then
            WS_FEHLT="$WS_FEHLT $WS_N(keine Datei)"
            continue
        fi
        rm -f "$WS_ZIEL/$WS_N.neu" 2>/dev/null
        if cp -p "$WS_Q" "$WS_ZIEL/$WS_N.neu" 2>/dev/null && cmp -s "$WS_Q" "$WS_ZIEL/$WS_N.neu" \
                && mv -f "$WS_ZIEL/$WS_N.neu" "$WS_ZIEL/$WS_N" 2>/dev/null \
                && cmp -s "$WS_Q" "$WS_ZIEL/$WS_N"; then
            :
        else
            rm -f "$WS_ZIEL/$WS_N.neu" 2>/dev/null
            WS_FEHLT="$WS_FEHLT $WS_N"
        fi
    done

    # E6 (Gruppe 14): die Protokolle werden nicht mehr zurueckgespielt - log/plugins/<ordner> bleibt beim
    # Upgrade stehen; die alte Kopie ueberschrieb Zeilen, die zwischen preupgrade und postupgrade entstanden.
    # Eine Sicherung aus 3.2.14 traegt noch log/ - sie faellt mit dem Ordner unten.

    if [ -z "$WS_FEHLT" ]; then
        echo "<INFO> Remove backup folder"
        rm -rf "${SICHER:?}"
    else
        echo "<WARNING> Nicht zurueckgespielt:$WS_FEHLT - die Sicherung bleibt unter"
        echo "<WARNING> $SICHER liegen. Von dort laesst sich die Konfiguration von Hand"
        echo "<WARNING> zurueckkopieren; bitte vorher den Platz auf der Karte pruefen."
    fi
else
    echo "<INFO> Keine Sicherung vorhanden - offenbar eine Erstinstallation."
fi

# Gruppe 14 (3.2.15): den MQTT-Listener gibt es nicht mehr - hier wird nichts mehr neu gestartet (bis 3.2.14 der
# Neustartblock mit ws_ist_listener; sein Kommentar "laeuft als root" (W6) ist mit ihm entfallen - dieses Skript laeuft
# als loxberry, Regeln/06). Abgeraeumt wird der alte Listener in preupgrade.sh und postinstall.sh (E9).

WS_CFG="$BASE/config/plugins/$PDIR/wifi_scanner.cfg"

# Inhaltsprobe wie preupgrade.sh/postinstall.sh (dort gleichlautend; alle drei Stellen zusammen pflegen).
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
ws_token() {
    sed -n 's/^[[:space:]]*TOKEN[[:space:]]*=[[:space:]]*//p' "$1" 2>/dev/null | head -n 1 | tr -d '[:space:]'
}
# Wert eines Schluessels in [BASE] ('' wenn er fehlt) und ob er ueberhaupt dasteht.
ws_base_wert() {   # ws_base_wert <datei> <SCHLUESSEL>
    awk -v k="$2" '
        /^[ \t]*\[/ { im = ($0 ~ /^[ \t]*\[BASE\][ \t\r]*$/); next }
        im { s = $0; sub(/=.*/, "", s); gsub(/[ \t]/, "", s)
             if (toupper(s) == k) { w = $0; sub(/^[^=]*=/, "", w); gsub(/[ \t\r"]/, "", w); print w; exit } }' "$1" 2>/dev/null
}
ws_base_hat() {   # ws_base_hat <datei> <SCHLUESSEL>
    awk -v k="$2" '
        /^[ \t]*\[/ { im = ($0 ~ /^[ \t]*\[BASE\][ \t\r]*$/); next }
        im { s = $0; sub(/=.*/, "", s); gsub(/[ \t]/, "", s); if (toupper(s) == k) { f = 1; exit } }
        END { exit f ? 0 : 1 }' "$1" 2>/dev/null
}

# ---------- E6 (Gruppe 14): ENDPUNKT_EIN fuer bestehende Anlagen ----------
#
# Den HTTP-Endpunkt gibt es seit 3.2.15 nur noch hinter dem Haken BASE.ENDPUNKT_EIN (Neuinstallation: 0). Eine Anlage,
# die schon ein gueltiges Merkwort hat, benutzt den Endpunkt vielleicht - fuer sie wird ENDPUNKT_EIN=1 ausdruecklich
# geschrieben (hinter [BASE]), in die Konfiguration UND die beiden Zweitschriften. Hier und nicht in postinstall.sh:
# das Zurueckspielen aus der upgrade_sicherung (oben) laeuft NACH postinstall. Geschrieben wird in eine Nebendatei
# (umask 077), nachgesehen (heil, Merkwort gleich, genau eine Zeile mehr), dann umbenannt. Scheitert es, gilt die
# Lese-Regel in ws_endpunkt_ein() (fehlender Schluessel + gueltiges Merkwort = an) - im Verhalten kein Unterschied.
# Ohne gueltiges Merkwort wird nichts geschrieben (die Lese-Regel ergibt 0, die Oberflaeche traegt 0 nach).
WS_ERGAENZT=""
ws_endpunkt_ergaenzen() {   # ws_endpunkt_ergaenzen <datei>
    f=$1
    [ -f "$f" ] || return 0
    ws_base_hat "$f" ENDPUNKT_EIN && return 0
    tok=$(ws_token "$f")
    printf '%s' "$tok" | grep -Eq '^[0-9a-f]{16,64}$' || return 0
    ws_cfg_heil "$f" || { echo "<WARNING> $(basename "$f") ist nicht vollstaendig - ENDPUNKT_EIN nicht ergaenzt (es gilt die Lese-Regel)."; return 1; }
    n_vor=$(wc -l < "$f")
    rm -f "$f.neu" 2>/dev/null
    ( umask 077; awk '{ print } /^[ \t]*\[BASE\][ \t\r]*$/ && !x { print "ENDPUNKT_EIN=1"; x = 1 }' "$f" > "$f.neu" ) 2>/dev/null
    if ws_cfg_heil "$f.neu" && [ "$(ws_token "$f.neu")" = "$tok" ] && [ "$(wc -l < "$f.neu")" -eq $((n_vor + 1)) ] \
            && chmod 0600 "$f.neu" 2>/dev/null && mv -f "$f.neu" "$f" 2>/dev/null; then
        WS_ERGAENZT="$WS_ERGAENZT $(basename "$f")"
        return 0
    fi
    rm -f "$f.neu" 2>/dev/null
    echo "<WARNING> ENDPUNKT_EIN liess sich in $(basename "$f") nicht schreiben - es gilt die Lese-Regel (gleiches Verhalten)."
    return 1
}
ws_endpunkt_ergaenzen "$WS_CFG"
ws_endpunkt_ergaenzen "$BASE/config/plugins/$PDIR.backup.wifi_scanner.cfg"
ws_endpunkt_ergaenzen "$BASE/config/plugins/$PDIR.wifi_scanner.backup"
if [ -n "$WS_ERGAENZT" ]; then
    echo "<INFO> Konfiguration ergaenzt: ENDPUNKT_EIN=1 (bestehende Anlage mit Merkwort - der Endpunkt bleibt erreichbar, jetzt nur mit Merkwort):$WS_ERGAENZT"
fi
if [ "$(ws_base_wert "$WS_CFG" ENDPUNKT_EIN)" = "1" ] || { ! ws_base_hat "$WS_CFG" ENDPUNKT_EIN \
        && printf '%s' "$(ws_token "$WS_CFG")" | grep -Eq '^[0-9a-f]{16,64}$'; }; then
    echo "<INFO> HTTP-Endpunkt: seit 3.2.15 braucht auch die Abfrage ?token=... - die Adresse steht im Reiter Einbindung in Loxone (Bestehende Anlagen)."
fi

# ---------- E9 (Gruppe 14): retained status/listener der Vorfassungen leeren; Zustaende senden ----------
#
# Bis 3.2.3 ging wifi_ng/status/listener retained hinaus - ein Altwert kann im Broker liegen. Er wird einmal geleert,
# mit Lesen vorher und Nachlesen (mqtt_abraeumen.pl --leeren; nur Themen unter wifi_ng/). Danach status/mode,
# /interval und /enabled aus der zurueckgespielten Konfiguration (--status) - bis 3.2.14 sandte sie der Listener bei
# jedem Start; ohne das stuenden sie bei ausgeschalteter regelmaessiger Suche erst nach dem naechsten Speichern da.
# Nur, wenn der Weg nicht auf UDP steht. Laeuft als loxberry; das Broker-Kennwort liefert LoxBerry::IO im Prozess,
# nie in der Befehlszeile.
HELFER="$BASE/bin/plugins/$PDIR/mqtt_abraeumen.pl"
if command -v timeout >/dev/null 2>&1; then WS_T="timeout -k 5 30"; else WS_T=""; fi
if [ "$(ws_base_wert "$WS_CFG" UDP_ENABLE)" = "1" ]; then
    echo "<INFO> Der Weg steht auf UDP - ueber MQTT wird nichts geleert und nichts gesendet."
elif [ -f "$HELFER" ] && command -v perl >/dev/null 2>&1; then
    AUS=$($WS_T perl -I "$BASE/libs/perllib" "$HELFER" --leeren wifi_ng/status/listener < /dev/null 2>&1)
    RC=$?
    case "$RC" in
        0) if printf '%s\n' "$AUS" | grep -q '^BESTAETIGT'; then
               echo "<OK> Das zurueckbehaltene wifi_ng/status/listener der Vorfassung ist geleert (beim Broker nachgelesen)."
           else
               echo "<INFO> Im Broker lag kein zurueckbehaltenes wifi_ng/status/listener."
           fi ;;
        2) echo "<INFO> Der MQTT-Broker war nicht befragbar - ein altes wifi_ng/status/listener bleibt gegebenenfalls stehen." ;;
        *) echo "<WARNING> wifi_ng/status/listener liess sich nicht leeren (Rueckgabe $RC)."
           echo "<WARNING> Von Hand: LoxBerry -> MQTT Gateway -> wifi_ng/status/listener loeschen." ;;
    esac
    AUS=$($WS_T perl -I "$BASE/libs/perllib" "$HELFER" --status < /dev/null 2>&1)
    RC=$?
    if [ "$RC" = 0 ]; then
        echo "<INFO> MQTT: $(printf '%s\n' "$AUS" | tail -n 1)"
    else
        echo "<INFO> status/mode, /interval und /enabled nicht gesendet (Rueckgabe $RC) - sie kommen mit dem naechsten Suchlauf."
    fi
fi

# ---------- Die Marke faellt HIER, am Ende ----------
#
# postupgrade.sh ist das letzte Hakenskript dieser Linie: es gibt weder
# postroot.sh noch preroot.sh (Reihenfolge nach Regeln/06: preroot,
# preinstall, preupgrade, postinstall, postupgrade, postroot).
#
# Am ENDE: bis dahin starten weder die Befehlsbruecke noch der Haus-Abnehmer
# (ihre Waechter in cron.01min lesen die Marke). Den Listener und daemon/daemon,
# fuer die die Reihenfolge bis 3.2.14 galt, gibt es seit 3.2.15 nicht mehr.
#
# Entfernt wird immer - sonst sperrte die Marke die Bruecke und die
# Oberflaeche eine Stunde lang. Bei einer
# Erstinstallation gibt es sie gar nicht; "rm -f" schweigt dann.
rm -f "$BASE/data/plugins/$PDIR.upgrade_laeuft" 2>/dev/null

# Exit with Status 0
exit 0
