#!/bin/bash
# WiFi-Scanner-NG - Haus-Abnehmer (V3) starten, anhalten, fragen, waechten. NEU IN 3.2.15 (Gruppe 14, Entwurf 7, E15).
#   ws_haus.sh start | stop | status | waechter
# Rueckgabe: status 0 = laeuft, 1 = steht; start/stop 0 = Wirkung nachgesehen oder Start mit Absicht unterlassen
# (Haken aus, Upgrade-Marke), 1 = nicht gelungen, 2 = Aufruf falsch, 3 = ein anderer start/stop haelt die Sperre;
# waechter = Rueckgabe von ws_haus.php --waechter. Aufruf immer mit "bash <pfad>".
#
# Bauart befehlsbruecke.sh (Stammfassung Werkzeuge/gemeinsam/): zuerst ALLE geerbten Deskriptoren ueber 2 schliessen,
# die flock-Sperre nur hier (der Dienst bekommt sie nicht mit: 8<&-), "laeuft" argumentweise (argv[0] ein php,
# argv[1] genau ws_haus.php, kein argv[2]), keine PID-Datei. Eigene Optionsdatei data/plugins/<ordner>/haus/mosquitto/
# - nicht die der Befehlsbruecke.

EIGEN=$(readlink -f "$0" 2>/dev/null)
for fd in /proc/$$/fd/*; do
    n=${fd##*/}
    case "$n" in ''|*[!0-9]*) continue ;; esac
    [ "$n" -gt 2 ] || continue
    [ "$(readlink "$fd" 2>/dev/null)" = "$EIGEN" ] && continue   # bash liest das Skript ueber diesen
    eval "exec $n>&-" 2>/dev/null
done

SELF=$(cd "$(dirname "$0")" 2>/dev/null && pwd -P)
SKRIPT="$SELF/ws_haus.php"
PNAME=$(basename "$SELF")
if [ "$(basename "$(dirname "$SELF")")" = "plugins" ] && [ "$(basename "$(dirname "$(dirname "$SELF")")")" = "bin" ]; then
    BASE=$(cd "$SELF/../../.." 2>/dev/null && pwd -P)
elif [ -n "$LBHOMEDIR" ] && [ -n "$LBPPLUGINDIR" ]; then
    BASE="$LBHOMEDIR"
    PNAME=$(basename "$LBPPLUGINDIR")
else
    echo "ws_haus.sh: keine LoxBerry-Installation erkannt - nichts getan." >&2
    exit 1
fi
DDIR="$BASE/data/plugins/$PNAME/haus"
LDIR="$BASE/log/plugins/$PNAME"
OPTDIR="$DDIR/mosquitto"
MARKE="$BASE/data/plugins/$PNAME.upgrade_laeuft"

ist_unser() {   # $1 = /proc/<pid>
    local -a a
    mapfile -d '' -t a 2>/dev/null < "$1/cmdline" || return 1
    [ "${#a[@]}" -eq 2 ] || return 1
    [ "${a[1]}" = "$SKRIPT" ] || return 1
    [[ "${a[0]}" =~ (^|/)php[0-9.]*$ ]] || return 1
    return 0
}
alle_pids() {
    local d
    for d in /proc/[0-9]*; do
        if ist_unser "$d"; then echo "${d#/proc/}"; fi
    done
}
abo_pids() {    # mosquitto_sub mit UNSERER Optionsdatei
    local d c x
    local -a e
    for d in /proc/[0-9]*; do
        c=""
        read -r c 2>/dev/null < "$d/comm"
        [ "$c" = "mosquitto_sub" ] || continue
        mapfile -d '' -t e 2>/dev/null < "$d/environ" || continue
        for x in "${e[@]}"; do
            if [ "$x" = "XDG_CONFIG_HOME=$OPTDIR" ]; then echo "${d#/proc/}"; break; fi
        done
    done
}
laeuft() { [ -n "$(alle_pids)" ]; }

PHP=$(command -v php 2>/dev/null)

case "$1" in
    status)
        if laeuft; then echo "Haus-Abnehmer aktiv (PID $(alle_pids | tr '\n' ' '))"; exit 0; fi
        echo "steht"; exit 1 ;;
    waechter)
        [ -n "$PHP" ] || { echo "php nicht gefunden." >&2; exit 1; }
        [ -f "$SKRIPT" ] || exit 1
        exec "$PHP" "$SKRIPT" --waechter ;;
    start|stop) ;;
    *) echo "Aufruf: ws_haus.sh start|stop|status|waechter" >&2; exit 2 ;;
esac

mkdir -p "$DDIR" "$LDIR" 2>/dev/null
exec 8>"$DDIR/dienst.lock" || { echo "Sperrdatei nicht anlegbar ($DDIR)."; exit 1; }

if [ "$1" = "stop" ]; then
    if ! flock -w 15 8; then echo "Ein anderer Start oder Halt haelt die Sperre."; exit 3; fi
    for p in $(alle_pids); do kill "$p" 2>/dev/null; done
    i=0
    while laeuft && [ $i -lt 10 ]; do sleep 1; i=$((i + 1)); done
    for p in $(alle_pids); do
        for k in $(pgrep -P "$p" 2>/dev/null); do kill -9 "$k" 2>/dev/null; done
        kill -9 "$p" 2>/dev/null
    done
    for k in $(abo_pids); do kill "$k" 2>/dev/null; done
    sleep 0.2
    for k in $(abo_pids); do kill -9 "$k" 2>/dev/null; done
    if laeuft || [ -n "$(abo_pids)" ]; then echo "Haus-Abnehmer laesst sich nicht anhalten."; exit 1; fi
    echo "Haus-Abnehmer angehalten."
    exit 0
fi

# start
if ! flock -n 8; then echo "Ein anderer Start oder Halt haelt die Sperre."; exit 3; fi
if [ -f "$MARKE" ]; then
    jetzt=$(date +%s); seit=$(cat "$MARKE" 2>/dev/null)
    case "$seit" in ''|*[!0-9]*) seit=0 ;; esac
    if [ "$seit" -le "$jetzt" ] && [ $((jetzt - seit)) -lt 3600 ]; then
        echo "Aktualisierung im Gang - der Haus-Abnehmer startet danach."; exit 0
    fi
fi
if laeuft; then echo "Haus-Abnehmer aktiv (PID $(alle_pids | tr '\n' ' '))."; exit 0; fi
if [ "$(id -u)" = "0" ]; then echo "Nicht als root starten." >&2; exit 1; fi
[ -n "$PHP" ] || { echo "php nicht gefunden."; exit 1; }
[ -f "$SKRIPT" ] || { echo "ws_haus.php fehlt."; exit 1; }
SOLL=$("$PHP" "$SKRIPT" --soll 2>&1); RC=$?
if [ "$RC" -eq 1 ]; then echo "Haken aus - kein Start."; exit 0; fi
if [ "$RC" -ne 0 ]; then echo "Kein Start: $SOLL"; exit 1; fi
nohup "$PHP" "$SKRIPT" >>"$LDIR/haus_start.log" 2>&1 </dev/null 8<&- &
sleep 1
if laeuft; then echo "Haus-Abnehmer gestartet (PID $(alle_pids | tr '\n' ' '))."; exit 0; fi
echo "Haus-Abnehmer endete sofort: $(tail -n 2 "$LDIR/haus_start.log" 2>/dev/null | tr '\n' ' ')"
exit 1
