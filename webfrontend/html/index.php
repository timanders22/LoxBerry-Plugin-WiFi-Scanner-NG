<?php
/**
 * WifiScanner NG - Endpunkt fuer den Miniserver
 *
 * Neu in 3.1.12. Bis dahin konnte Loxone das Plugin ausschliesslich ueber
 * MQTT steuern; wer kein Gateway faehrt, hatte gar keinen Rueckkanal.
 *
 * Diese Datei liegt im UNANGEMELDETEN Bereich - der Miniserver kann sich
 * nicht anmelden. Geschuetzt wird sie durch das Merkwort der Anlage und -
 * seit 3.2.15 (Gruppe 14, E6) - durch den Haken "HTTP-Endpunkt fuer Loxone
 * annehmen" (BASE.ENDPUNKT_EIN; neu ab Werk aus, beim Update an, wenn ein
 * Merkwort da ist). Ohne Haken antwortet jeder Aufruf 403 ERR=ENDPUNKT_AUS.
 *
 *   Abfragend, seit 3.2.15 ebenfalls NUR mit Merkwort (E4 des Pruefberichts):
 *     /plugins/wifi_ng/index.php?token=<TOKEN>          Antwortzeile fuer Loxone
 *     /plugins/wifi_ng/index.php?token=<TOKEN>&json=1   dasselbe als JSON
 *
 *   Ausloesend, NUR mit Merkwort:
 *     ...?token=<TOKEN>&aktion=scan               Sofort-Scan
 *     ...?token=<TOKEN>&aktion=enable&wert=0|1    periodisches Suchen
 *     ...?token=<TOKEN>&aktion=interval&wert=<n>  Takt in Minuten
 *     ...?token=<TOKEN>&aktion=mode&wert=0|1|2    Suchweg
 *     ...?token=<TOKEN>&aktion=listener           seit 3.2.15 409 ERR=ENTFALLEN (E8;
 *                                                 Befehle nimmt die Befehlsbruecke an)
 *
 *   Selbsttest:
 *     ...?selftest=1&token=<TOKEN>
 *
 * ZWEI GRUNDSAETZE, die hier nicht verhandelbar sind:
 *
 * 1. Diese Datei SCHREIBT NICHTS, solange kein gueltiges Merkwort vorliegt -
 *    auch nichts Harmloses. Deshalb ueberall ws_config_read(false): die
 *    Selbstheilung aus dem Zweitexemplar bleibt der angemeldeten Oberflaeche
 *    vorbehalten. Ein einziger abgewiesener Aufruf, der eine Konfiguration
 *    anlegt, ist eine Hintertuer.
 *
 * 2. Die Anfrage wird geprueft, BEVOR irgendetwas ueber den Zustand des
 *    Plugins ermittelt wird. Wer erst misst und dann prueft, verraet einem
 *    Fremden ueber die Antwortzeit, was drinsteht.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

/* Die Bibliothek liegt daneben. Der Rueckfall auf die Kandidatenliste ist
 * hier eigentlich unnoetig - beide Dateien liegen im selben Verzeichnis -
 * aber er kostet nichts und faengt den Fall ab, dass jemand nur diese Datei
 * kopiert hat. */
$ws_lib = __DIR__ . '/ws_lib.php';
if (!is_file($ws_lib)) {
    $ws_home = getenv('LBHOMEDIR');
    $ws_pdir = getenv('LBPPLUGINDIR');
    $ws_lib = ($ws_home && $ws_pdir)
        ? $ws_home . '/webfrontend/html/plugins/' . $ws_pdir . '/ws_lib.php' : '';
}
if ($ws_lib === '' || !is_file($ws_lib)) {
    /* Die durchsuchten Pfade gehoeren INS PROTOKOLL, nicht in die Antwort -
     * der Aufrufer hat sich noch nicht ausgewiesen. */
    error_log('wifi_ng: ws_lib.php nicht gefunden (gesucht: ' . __DIR__ . '/ws_lib.php)');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "WIFI;OK=0;ERR=LIB\n";
    exit;
}
require_once $ws_lib;

/* ------------------------------------------------------------------
 * Parameter EINMAL einsammeln.
 *
 * Nicht aus $_REQUEST: ist request_order leer, gilt variables_order und
 * damit sind Cookies dabei - ein Cookie namens "token" haette die Pruefung
 * gefuettert.
 *
 * Erst is_string(), dann alles andere: "?token[]=x" macht ein Feld, und
 * trim() darauf ist unter PHP 8 ein TypeError, also ein leerer HTTP 500.
 * ------------------------------------------------------------------ */
function ws_par($name, $max = 128)
{
    if (!isset($_GET[$name]) || !is_string($_GET[$name])) {
        return '';
    }
    $v = $_GET[$name];
    if (strlen($v) > $max) {
        return '';
    }
    return trim($v);
}

$ws_token_ein = ws_par('token', 128);
$ws_aktion    = ws_par('aktion', 32);
$ws_wert      = ws_par('wert', 32);
$ws_selftest  = isset($_GET['selftest']);
$ws_json      = isset($_GET['json']);

/* ------------------------------------------------------------------
 * Antworten
 * ------------------------------------------------------------------ */
function ws_ende($code, $zeile)
{
    http_response_code((int) $code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $zeile . "\n";
    exit;
}

/* ------------------------------------------------------------------
 * Merkwort pruefen.
 *
 * hash_equals('', '') ist WAHR - der leere Fall wird deshalb vor dem
 * Vergleich abgefangen, sonst stuende der Endpunkt offen, solange niemand
 * die Oberflaeche geoeffnet und damit ein Merkwort erzeugt hat.
 * ------------------------------------------------------------------ */
$ws_cfg  = ws_config_read(false);
$ws_soll = ws_token($ws_cfg);

function ws_token_ok($soll, $ist)
{
    return ($soll !== '' && is_string($ist) && $ist !== '' && hash_equals($soll, $ist));
}

/* ---------------- endpunkt_ein (Gruppe 14, E6) ----------------
 * Der GANZE Endpunkt steht hinter dem Haken "HTTP-Endpunkt fuer Loxone annehmen" (BASE.ENDPUNKT_EIN; Neuinstallation
 * aus, Update: an, wenn ein gueltiges Merkwort da ist - ws_endpunkt_ein()). Geprueft VOR Selbsttest, Aktion und
 * Abfrage, und bevor irgendein Zustand gelesen wird (Grundsatz 2 oben). Muster Heimkino/Sprachsteuerung. */
if (!ws_endpunkt_ein($ws_cfg)) {
    ws_ende(403, $ws_selftest ? 'SELFTEST;OK=0;ERR=ENDPUNKT_AUS' : 'WIFI;OK=0;ERR=ENDPUNKT_AUS');
}

/* ---------------- Selbsttest ----------------
 * Genau drei Antworten, kein Geraetekontakt, kein Schreibzugriff. Er darf
 * ausdruecklich KEINE Abkuerzung an der Sicherheit vorbei sein: ohne
 * gueltiges Merkwort antwortet er 403 wie jeder andere ausloesende Aufruf. */
if ($ws_selftest) {
    if ($ws_soll === '') {
        ws_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
    }
    if (!ws_token_ok($ws_soll, $ws_token_ein)) {
        ws_ende(403, 'SELFTEST;OK=0;ERR=TOKEN');
    }
    ws_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

/* ---------------- Ausloesende Aufrufe ----------------
 * Weissliste: was hier nicht steht, wird abgewiesen. */
if ($ws_aktion !== '') {
    if (!ws_token_ok($ws_soll, $ws_token_ein)) {
        error_log('wifi_ng: Endpunkt-Aufruf ohne gueltiges Merkwort, aktion='
                  . $ws_aktion . ', von '
                  . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
        ws_ende(403, 'WIFI;OK=0;ERR=TOKEN');
    }
    /* Waehrend einer Aktualisierung loest dieser Endpunkt nichts aus - neu in
     * 3.2.7. In der Luecke zwischen purge_installation und postupgrade.sh
     * steht hier die mitgelieferte Vorgabe; ein Schreibzugriff legte sie
     * samt Zweitschrift fest (gemessen, siehe ws_lib.php, ws_upgrade_laeuft).
     * EINE Stelle fuer alle ausloesenden Aufrufe. 503 heisst "vorlaeufig
     * nicht", nicht "kaputt" - der Grund steht in der Zeile (Regeln/07). */
    if (ws_upgrade_laeuft()) {
        ws_ende(503, 'WIFI;OK=0;ERR=UPGRADE');
    }
    /* E8 (Gruppe 14): den Listener gibt es seit 3.2.15 nicht mehr - eine Bestandsadresse bekommt einen sprechenden
     * Grund statt 400 ERR=AKTION. Befehle aus Loxone nimmt die Befehlsbruecke an. */
    if ($ws_aktion === 'listener') {
        ws_ende(409, 'WIFI;OK=0;AKTION=listener;ERR=ENTFALLEN');
    }
    /* EINE Logik (Gruppe 14, 3.4): ws_steuern() in ws_lib.php - dieselbe Funktion ruft die Befehlsbruecke. Codes und
     * Felder sind dieselben wie bis 3.2.14 (OK, AKTION, WERT, UNVERAENDERT, ERR); der Suchlauf geht ueber
     * bin/ws_auftrag.sh (ERR=AUFTRAG_FEHLT, wenn er nicht startet). */
    list($ws_code, $ws_f) = ws_steuern($ws_aktion, $ws_aktion === 'scan' ? '' : $ws_wert, 'http');
    ws_ende($ws_code, ws_endpunkt_zeile('WIFI', $ws_f));
}

/* ---------------- Abfragende Aufrufe ----------------
 * E4 des Pruefberichts / E6 (Gruppe 14): auch die Abfrage NUR mit Merkwort. Die Antwortzeile traegt die Namen der
 * Personen (P_<Name>) und wer zu Hause ist - bis 3.2.14 stand hier, sie verrate nichts, was nicht ohnehin am
 * Miniserver stehe; tatsaechlich verriet sie es jedem im Heimnetz. Die im Reiter Einbindung angezeigte Adresse traegt
 * das Merkwort (ws_endpunkt_pfad). Das Merkwort selbst kommt in der Antwort NIE vor. */
if (!ws_token_ok($ws_soll, $ws_token_ein)) {
    error_log('wifi_ng: Abfrage des Endpunkts ohne gueltiges Merkwort, von '
              . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
    ws_ende(403, 'WIFI;OK=0;ERR=TOKEN');
}

$ws_z      = ws_zustand_lesen();
$ws_alter  = ws_zustand_alter($ws_z);
$ws_frisch = ws_zustand_frisch($ws_cfg, $ws_z);
$ws_users  = ws_users($ws_cfg);

$ws_da = 0;
$ws_pers = array();
foreach ($ws_users as $u) {
    if ($u['name'] === '') { continue; }
    $thema = ws_topic_name($u['name']);
    $wert = -1;
    foreach ($ws_z['personen'] as $pz) {
        if (isset($pz['name']) && ws_topic_name($pz['name']) === $thema) {
            $wert = isset($pz['online']) ? (int) $pz['online'] : -1;
            break;
        }
    }
    /* Wer aus dem Abbild verschwunden ist, bekommt -1 - nicht gar keinen
     * Wert. Ein fehlendes Feld ist in Loxone Stillstand, und Stillstand
     * sieht aus wie "alles in Ordnung". */
    if (!$ws_frisch) { $wert = -1; }
    $ws_pers[$thema] = $wert;
    if ($wert === 1) { $ws_da++; }
}

/* C8: beide Wege aus heisst -1 ("keine Suche") - dieselbe Regel wie status/mode (ws_modus, E7). */
$ws_mode = ws_modus(ws_cfg($ws_cfg, 'BASE.FRITZBOX_ENABLE', '0'), ws_cfg($ws_cfg, 'BASE.ACTIVE_SCAN', '0'));

$ws_felder = array(
    'OK'       => $ws_frisch ? (int) ($ws_z['ok'] === 1) : 0,
    'ALTER'    => $ws_alter,
    'ZAEHLER'  => isset($ws_z['zaehler']) ? (int) $ws_z['zaehler'] : -1,
    'PERSONEN' => count($ws_pers),
    'DA'       => $ws_da,
    'ENABLED'  => (int) (ws_cfg($ws_cfg, 'BASE.ENABLED', '0') === '1'),
    'MODE'     => $ws_mode,
    'INTERVAL' => (int) ws_cfg($ws_cfg, 'BASE.CRON', '3'),
    /* W22/E8 (Gruppe 14): der Name bleibt (Suchtexte im Bestand), der Wert sagt jetzt "Steuerung aus Loxone bereit":
     * 1, wenn die Befehlsbruecke laeuft, abonniert hat und frisch ist (ws_bruecke_bereit) - nicht mehr nur "ein
     * Prozess ist da". */
    'LISTENER' => ws_bruecke_bereit($ws_cfg),
    'CRON'     => ws_cron_current() !== '' ? 1 : 0,
);
foreach ($ws_pers as $thema => $wert) {
    $ws_felder['P_' . $thema] = $wert;
}

if ($ws_json) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $js = json_encode(array('felder' => $ws_felder, 'stand' => $ws_z['ts'],
                            'fehler' => $ws_z['fehler']),
                      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    /* json_encode gibt bei ungueltigem UTF-8 false zurueck - dann wuerde
     * echo eine leere Antwort schreiben und Erfolg melden. */
    if ($js === false) {
        ws_ende(500, 'WIFI;OK=0;ERR=JSON');
    }
    echo $js;
    exit;
}

ws_ende(200, ws_endpunkt_zeile('WIFI', $ws_felder));
