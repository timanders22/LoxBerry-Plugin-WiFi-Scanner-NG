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

# ---------- Inhaltsprobe ----------
#
# Ob eine Datei als Sicherung taugt, entscheidet ihr INHALT, nicht ihre
# Groesse. Bis 3.2.7 stand hier "[ -s datei ]": eine abgeschnittene
# Konfiguration ist nicht leer, bestand die Probe und wurde ueber die heile
# Zweitschrift kopiert. Gemessen am 18.09.2026 in WSL
# (Pruefung-WiFi-Scanner-NG-3.2.8/Pruefstaende/messe_welle2.sh, Faelle C1-C3):
# vor TOKEN= abgeschnitten, mitten in [USER2] abgeschnitten, Abo-Datei
# abgeschnitten - dreimal war die heile Zweitschrift danach fort.
#
# Heil ist eine Config::Simple-Datei, wenn sie mit einem Zeilenumbruch endet
# (ein Abbruch mitten in einer Zeile laesst ihn fast immer weg), jede Zeile
# leer, Kommentar, [Abschnitt] oder SCHLUESSEL=Wert ist, [BASE] da ist und
# USERS=n zu n vollstaendigen Abschnitten [USER1]..[USERn] mit NAME und MACS
# passt. Nicht erkannt wird ein Schnitt genau an einer Zeilengrenze innerhalb
# von [BASE] - dafuer gibt es die Probe auf das Merkwort in ws_darf_ersetzen.
#
# Gleichlautend in postinstall.sh; beide Stellen zusammen pflegen.
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
# Die Abo-Datei fuer das MQTT-Gateway: ein Thema je Zeile. Heil heisst hier
# nur: mindestens ein Thema, und die letzte Zeile ist vollstaendig.
ws_abo_heil() {
    [ -f "$1" ] && [ -r "$1" ] || return 1
    [ "$(tail -c 1 "$1" 2>/dev/null | wc -l)" -eq 1 ] || return 1
    grep -q '[^[:space:]]' "$1" 2>/dev/null
}
ws_heil() {   # ws_heil cfg|abo <datei>
    case "$1" in
        cfg) ws_cfg_heil "$2" ;;
        abo) ws_abo_heil "$2" ;;
        *)   return 1 ;;
    esac
}
ws_token() {
    sed -n 's/^[[:space:]]*TOKEN[[:space:]]*=[[:space:]]*//p' "$1" 2>/dev/null | head -n 1 | tr -d '[:space:]'
}
# Pruefsummen der mitgelieferten Vorgaben - dieselben wie in postinstall.sh.
ws_vorgabe() {
    case "$1" in
        cfg) echo "30bcb5717482b3b0aa670ce91a023ab7100fac5db98d0e271e879555a541fc3a" ;;
        abo) echo "8e8f8a5e3c6ba7c6fbfe7d6fed9f81f663067d3964501f51ad051cd65d6d5d98" ;;
    esac
}
ws_sha() { sha256sum "$1" 2>/dev/null | cut -d" " -f1; }
# Darf <quelle> den vorhandenen Stand <ziel> (Zweitschrift oder Sicherung)
# ersetzen? Nur wenn die Quelle heil ist, und nie so, dass Inhalt verloren
# geht:
#   - traegt das Ziel ein Merkwort und die Quelle keines, bleibt das Ziel;
#   - ist die Quelle zeichengenau die mitgelieferte Vorgabe, das Ziel aber
#     nicht, bleibt das Ziel. So sieht die Konfiguration aus, wenn ein
#     frueherer Update-Versuch nach dem Kopierschritt des Installers
#     abbrach und das Update jetzt erneut laeuft (Fall D9).
# Ein Ziel, das selbst nicht heil ist, darf eine heile Quelle immer ersetzen.
ws_darf_ersetzen() {   # ws_darf_ersetzen cfg|abo <quelle> <ziel>
    ws_heil "$1" "$2" || return 1
    [ -f "$3" ] || return 0
    ws_heil "$1" "$3" || return 0
    if [ "$1" = "cfg" ] && [ -n "$(ws_token "$3")" ] && [ -z "$(ws_token "$2")" ]; then
        return 1
    fi
    if [ "$(ws_sha "$2")" = "$(ws_vorgabe "$1")" ] && [ "$(ws_sha "$3")" != "$(ws_vorgabe "$1")" ]; then
        return 1
    fi
    return 0
}

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
#
# DIE ALTE SICHERUNG FAELLT ERST, WENN DIE NEUE STEHT.
#
# Bis 3.2.7 stand hier "rm -rf $SICHER", danach mkdir und zwei "cp -p -r".
# Gemessen am 18.09.2026 in WSL (Bestand-2026-09-18/klasse-D, und
# Pruefung-WiFi-Scanner-NG-3.2.8/Pruefstaende/messe_welle2.sh, Faelle D1/D2):
# bricht ein Upgrade nach purge_installation ab und wird es erneut
# angestossen, ist die Sicherung die EINZIGE Abschrift der Konfiguration -
# und der zweite Lauf loeschte sie, bevor er feststellte, dass es nichts
# Neues mehr zu sichern gab (config/plugins/<x>/ ist ja schon fort). Von 12
# Dateien mit Merkwort blieb eine uebrig. Unter "ulimit -f 0" (jeder
# Schreibvorgang scheitert, wie auf einer vollen Karte) dasselbe.
#
# Jetzt nach dem Muster aus GardenaSmartSystem (preupgrade.sh): neu in
# <sicherung>.neu, jede Datei der Konfiguration byteweise nachgesehen, dann
# umbenennen; die alte faellt erst danach. Und: eine Sicherung mit heiler
# wifi_scanner.cfg wird nie durch eine ohne ersetzt.
SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"
NEU="$SICHER.neu"
KONF="$ARGV5/config/plugins/$ARGV3"
echo "<INFO> Creating backup folder for upgrading"
# $NEU ist nur der Rest eines frueheren, abgebrochenen Laufs dieses Blocks.
rm -rf "$NEU" 2>/dev/null
mkdir -p "$NEU/config" "$NEU/log" 2>/dev/null
chmod 0700 "$NEU" 2>/dev/null

SICHER_OK=0
if [ -d "$KONF" ]; then
    echo "<INFO> Backing up existing config files"
    if cp -p -r "$KONF/." "$NEU/config/" 2>/dev/null; then
        CP_RC=0
    else
        CP_RC=$?
    fi
    # Die Wirkung pruefen, nicht den Rueckgabewert allein (CLAUDE.md, 2).
    ABWEICHEND=$( { cd "$KONF" && find . -type f | while IFS= read -r f; do
                      cmp -s "$f" "$NEU/config/$f" || printf '%s ' "${f#./}"
                  done; } 2>/dev/null || echo "(Konfiguration nicht lesbar)" )
    if [ "$CP_RC" -eq 0 ] && [ -z "$ABWEICHEND" ]; then
        SICHER_OK=1
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT vollstaendig sichern"
        echo "<WARNING> (cp Rueckgabewert $CP_RC; nicht in der Sicherung: ${ABWEICHEND:-keine})."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden."
fi
# Liegt schon eine Sicherung, entscheidet der Inhalt wie bei der Zweitschrift
# (ws_darf_ersetzen): eine abgeschnittene wifi_scanner.cfg (Fall D10) oder die
# blosse Vorgabe aus einem abgebrochenen frueheren Versuch (Fall D9) ersetzt
# keine Sicherung mit echten Einstellungen.
if [ "$SICHER_OK" = "1" ] && [ -d "$SICHER" ] \
        && ! ws_darf_ersetzen cfg "$NEU/config/wifi_scanner.cfg" "$SICHER/config/wifi_scanner.cfg"; then
    SICHER_OK=0
    echo "<WARNING> Die jetzige wifi_scanner.cfg traegt weniger als die der bisherigen"
    echo "<WARNING> Sicherung (unvollstaendig, ohne Merkwort oder nur die Vorgabe)."
fi

if [ "$SICHER_OK" = "1" ]; then
    echo "<INFO> Backing up existing log files"
    cp -p -r "$ARGV5/log/plugins/$ARGV3/." "$NEU/log/" 2>/dev/null \
        || echo "<WARNING> Die Protokolle liessen sich nicht vollstaendig sichern."
    rm -rf "$SICHER.alt" 2>/dev/null
    if [ -d "$SICHER" ]; then mv "$SICHER" "$SICHER.alt" 2>/dev/null; fi
    if mv "$NEU" "$SICHER" 2>/dev/null; then
        rm -rf "$SICHER.alt" 2>/dev/null
        echo "<OK> Einstellungen fuer das Update gesichert."
    else
        if [ -d "$SICHER.alt" ] && [ ! -e "$SICHER" ]; then mv "$SICHER.alt" "$SICHER" 2>/dev/null; fi
        rm -rf "$NEU" 2>/dev/null
        echo "<WARNING> Die neue Sicherung liess sich nicht an ihren Platz bringen."
        echo "<WARNING> Platz und Rechte in $ARGV5/data/plugins pruefen."
    fi
else
    rm -rf "$NEU" 2>/dev/null
    if [ -d "$SICHER" ]; then
        echo "<WARNING> Die bisherige Sicherung unter $SICHER bleibt unangetastet."
    fi
fi

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
# Entschieden wird nach Inhalt (ws_darf_ersetzen oben), geschrieben
# unteilbar: erst <zweitschrift>.neu, nachgesehen, dann umbenannt. Ein
# "cp -p" direkt auf die Zweitschrift kappt sie beim Oeffnen - bricht es
# danach ab, ist weder die alte noch eine neue da (dieselbe Bauart wie oben).
netz_zweitschrift() {   # netz_zweitschrift cfg|abo <datei>
    quelle="$NETZ_CFG/$2"
    ziel="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$2"
    [ -f "$quelle" ] || return 0
    if ! ws_darf_ersetzen "$1" "$quelle" "$ziel"; then
        if [ -f "$ziel" ]; then
            echo "<WARNING> $2 traegt weniger als die Zweitschrift daneben (unvollstaendig,"
            echo "<WARNING> ohne Merkwort oder nur die Vorgabe) - die Zweitschrift bleibt, wie sie ist."
        else
            echo "<WARNING> $2 ist nicht vollstaendig - keine Zweitschrift angelegt."
        fi
        return 0
    fi
    rm -f "$ziel.neu" 2>/dev/null
    if cp -p "$quelle" "$ziel.neu" 2>/dev/null && chmod 0600 "$ziel.neu" 2>/dev/null \
            && cmp -s "$quelle" "$ziel.neu" && mv -f "$ziel.neu" "$ziel" 2>/dev/null; then
        echo "<OK> Zweitschrift von $2 angelegt."
    else
        rm -f "$ziel.neu" 2>/dev/null
        echo "<WARNING> Die Zweitschrift von $2 liess sich nicht schreiben; die bisherige bleibt."
    fi
}
netz_zweitschrift abo mqtt_subscriptions.cfg
netz_zweitschrift cfg wifi_scanner.cfg

exit 0
