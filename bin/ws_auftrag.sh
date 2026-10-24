#!/bin/bash
# WiFi-Scanner-NG - kurzer Auftragsprozess. NEU IN 3.2.15 (Gruppe 14, Entwurf 3.4, E1).
#   ws_auftrag.sh scan [both|fritzbox|ping]   ein Suchlauf (check.pl --auftrag [--mode <m>]), losgeloest
#   ws_auftrag.sh status                      status/mode, /interval, /enabled senden (mqtt_abraeumen.pl --status)
# Rueckgabe 0 = Auftrag gestartet, 1 = keine Installation/kein Skript, 2 = Aufruf falsch.
#
# EINE Stelle fuer Endpunkt, Oberflaeche, Befehlsbruecke und den Haus-Abnehmer (V3): ws_auftrag_starten() in
# webfrontend/html/ws_lib.php startet dieses Skript ueber proc_open (Feld, ohne Schale) und wartet nur, bis es
# zurueckkommt - Millisekunden. Der Suchlauf selbst (bis zu 20 arping je Geraet, Minuten) haengt danach an keinem
# Aufrufer: nicht an der Bruecke, nicht am Apache-Kind.
#
# Zuerst werden ALLE geerbten Deskriptoren ueber 2 geschlossen - wortgleich der Kopf von befehlsbruecke.sh
# (Gedaechtnis "Sperre vererbt sich an Kinder"; Muster Intercom ic_auftrag.sh). Bis 3.2.14 erbte der aus Apache
# gestartete check.pl die Deskriptoren des Apache-Kindes (eigener Fund F2).

EIGEN=$(readlink -f "$0" 2>/dev/null)
for fd in /proc/$$/fd/*; do
    n=${fd##*/}
    case "$n" in ''|*[!0-9]*) continue ;; esac
    [ "$n" -gt 2 ] || continue
    [ "$(readlink "$fd" 2>/dev/null)" = "$EIGEN" ] && continue   # bash liest das Skript ueber diesen
    eval "exec $n>&-" 2>/dev/null
done

ART="$1"
MODUS="${2:-}"
case "$ART" in
    scan) case "$MODUS" in ''|both|fritzbox|ping) ;; *) echo "Aufruf: ws_auftrag.sh scan [both|fritzbox|ping] | status" >&2; exit 2 ;; esac ;;
    status) [ -z "$MODUS" ] || { echo "Aufruf: ws_auftrag.sh status (ohne Modus)" >&2; exit 2; } ;;
    *) echo "Aufruf: ws_auftrag.sh scan [both|fritzbox|ping] | status" >&2; exit 2 ;;
esac
[ "$#" -le 2 ] || { echo "Aufruf: hoechstens zwei Argumente" >&2; exit 2; }

SELF=$(cd "$(dirname "$0")" 2>/dev/null && pwd -P)
if [ "$(basename "$(dirname "$SELF")")" = "plugins" ] && [ "$(basename "$(dirname "$(dirname "$SELF")")")" = "bin" ]; then
    BASE=$(cd "$SELF/../../.." 2>/dev/null && pwd -P)
elif [ -n "$LBHOMEDIR" ] && [ -n "$LBPPLUGINDIR" ]; then
    BASE="$LBHOMEDIR"
else
    echo "ws_auftrag.sh: keine LoxBerry-Installation erkannt - nichts getan." >&2
    exit 1
fi
PERL5INC="$BASE/libs/perllib"

if [ "$ART" = "scan" ]; then
    [ -f "$SELF/check.pl" ] || { echo "ws_auftrag.sh: check.pl fehlt" >&2; exit 1; }
    if [ -n "$MODUS" ]; then
        nohup perl -I "$PERL5INC" "$SELF/check.pl" --auftrag --mode "$MODUS" </dev/null >/dev/null 2>&1 &
    else
        nohup perl -I "$PERL5INC" "$SELF/check.pl" --auftrag </dev/null >/dev/null 2>&1 &
    fi
else
    [ -f "$SELF/mqtt_abraeumen.pl" ] || { echo "ws_auftrag.sh: mqtt_abraeumen.pl fehlt" >&2; exit 1; }
    if command -v timeout >/dev/null 2>&1; then
        nohup timeout -k 5 30 perl -I "$PERL5INC" "$SELF/mqtt_abraeumen.pl" --status </dev/null >/dev/null 2>&1 &
    else
        nohup perl -I "$PERL5INC" "$SELF/mqtt_abraeumen.pl" --status </dev/null >/dev/null 2>&1 &
    fi
fi
exit 0
