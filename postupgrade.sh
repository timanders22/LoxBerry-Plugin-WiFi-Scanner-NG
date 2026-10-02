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
SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"
if [ -d "$SICHER" ]; then
    echo "<INFO> Copy back existing config files"
    # I2 (Durchgang 02.10.2026): jede Datei erst nach <datei>.neu, mit cmp
    # nachgesehen, dann umbenannt - wie preupgrade.sh es beim Sichern tut. Die
    # Sicherung faellt nur, wenn JEDE Datei byteweise angekommen ist. Bis 3.2.9
    # kopierte cp direkt ueber die Konfiguration und loeschte die Sicherung ohne
    # Pruefung: bei voller Karte (ulimit -f 0) stand danach eine Konfiguration
    # mit 0 Byte da, und die Sicherung war fort (Installer-Pruefer Nr. 2, U2).
    WS_ZIEL="$ARGV5/config/plugins/$ARGV3"
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

    echo "<INFO> Copy back existing log files"
    mkdir -p "$ARGV5/log/plugins/$ARGV3"
    cp -p -r "$SICHER/log/." "$ARGV5/log/plugins/$ARGV3/" 2>/dev/null

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

# Restart the MQTT command listener with the new version
LISTENER=$ARGV5/bin/plugins/$ARGV3/mqtt_listener.pl
# c1: die Konfiguration, aus der der Uebertragungsweg gelesen wird - die
# zurueckgespielte, denn dieser Teil laeuft nach dem Zurueckspielen.
WS_CFG=$ARGV5/config/plugins/$ARGV3/wifi_scanner.cfg

# ---------------------------------------------------------------------------
# c1 (02.10.2026): Steht der Uebertragungsweg auf UDP?
#
# Nur der Wert 1 fuer UDP_ENABLE im Abschnitt [BASE] zaehlt - dieselbe Lesart
# wie der Waechter in check.pl ("// 0") und die Oberflaeche
# (ws_cfg(..., '0') === '1'). Fehlt die Datei oder ist sie nicht lesbar, gilt
# MQTT, und der Listener startet wie bisher. Bis 3.2.9 fragte diese Stelle den
# Weg gar nicht: bei UDP startete der Listener trotzdem und sendete
# status/mode, /interval und /enabled retained - genau die Themen, die der
# Wechsel auf UDP abraeumt. Die mitgelieferte Vorgabe steht auf UDP.
# ---------------------------------------------------------------------------
ws_weg_udp() {
    [ -r "$1" ] || return 1
    ws_udp=$(awk '
        /^[ \t]*\[/ { im_base = ($0 ~ /^[ \t]*\[BASE\][ \t\r]*$/); next }
        im_base && /^[ \t]*UDP_ENABLE[ \t]*=/ {
            w = $0; sub(/^[^=]*=/, "", w); gsub(/[ \t\r"]/, "", w); v = w
        }
        END { print v }' "$1" 2>/dev/null)
    [ "$ws_udp" = "1" ]
}

# Gehoert diese Prozessnummer unserem Listener?
#
# argv[0] ist ein perl, argv[1] ist ZEICHENGENAU unser Pfad, und es gibt kein
# drittes Argument. Bis 3.2.6 stand hier "head -2 | grep -qxF <pfad>", also
# ohne die Frage, WER die Datei in der Hand hat. Gemessen am 18.09.2026 in
# WSL (Pruefung-WiFi-Scanner-NG-3.2.7/Pruefstaende/messe_koeder.sh, Fall K4):
# ein Koeder "tail <listenerpfad> -f" - GNU tail vertauscht Option und
# Dateiname, der Pfad steht damit in argv[1] - wurde von dieser Stelle
# beendet ("Koeder 3103609: IST TOT"). Dieses Skript laeuft als root.
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

if [ -f "$LISTENER" ]; then
    echo "<INFO> Restarting WifiScanner MQTT listener"
    chmod +x "$LISTENER"
    for D in /proc/[0-9]*; do
        P=${D#/proc/}
        if ws_ist_listener "$P"; then
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
    # c1: der alte wird in jedem Fall angehalten (oben), ein neuer nur bei MQTT.
    if ws_weg_udp "$WS_CFG"; then
        echo "<INFO> MQTT-Listener nicht gestartet: der Uebertragungsweg steht auf UDP."
    else
        nohup perl "$LISTENER" > /dev/null 2>&1 &
    fi
fi

# ---------- Die Marke faellt HIER, nach dem Start ----------
#
# postupgrade.sh ist das letzte Hakenskript dieser Linie: es gibt weder
# postroot.sh noch preroot.sh (Reihenfolge nach Regeln/06: preroot,
# preinstall, preupgrade, postinstall, postupgrade, postroot).
#
# NACH dem Start, nicht davor: zwischen dem Entfernen und dem Augenblick, in
# dem der neue Listener dasteht, saehe ein Systemstart oder ein Aufruf von
# daemon/daemon weder die Marke noch einen laufenden Listener - und startete
# einen eigenen. An Chromecast4lox 1.3.10 ist diese Reihenfolge gemessen
# (Regeln/06 und klasse-G/Befundliste.md, Abschnitt 4: 400 Waechterlaeufe im
# Abstand von 0,02 s, umgekehrte Reihenfolge je vier Dienste).
#
# Entfernt wird immer, auch wenn der Start unterblieb - sonst sperrte die
# Marke den Systemstart und die Oberflaeche eine Stunde lang. Bei einer
# Erstinstallation gibt es sie gar nicht; "rm -f" schweigt dann.
rm -f "$ARGV5/data/plugins/$ARGV3.upgrade_laeuft" 2>/dev/null

# Exit with Status 0
exit 0
