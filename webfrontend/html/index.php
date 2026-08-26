<?php
/**
 * WifiScanner NG - Endpunkt fuer den Miniserver
 *
 * Neu in 3.1.12. Bis dahin konnte Loxone das Plugin ausschliesslich ueber
 * MQTT steuern; wer kein Gateway faehrt, hatte gar keinen Rueckkanal.
 *
 * Diese Datei liegt im UNANGEMELDETEN Bereich - der Miniserver kann sich
 * nicht anmelden. Geschuetzt wird sie durch das Merkwort der Anlage:
 *
 *   Abfragend, ohne Merkwort:
 *     /plugins/wifi_ng/index.php                  Antwortzeile fuer Loxone
 *     /plugins/wifi_ng/index.php?json=1           dasselbe als JSON
 *
 *   Ausloesend, NUR mit Merkwort:
 *     ...?token=<TOKEN>&aktion=scan               Sofort-Scan
 *     ...?token=<TOKEN>&aktion=enable&wert=0|1    periodisches Suchen
 *     ...?token=<TOKEN>&aktion=interval&wert=<n>  Takt in Minuten
 *     ...?token=<TOKEN>&aktion=mode&wert=0|1|2    Suchweg
 *     ...?token=<TOKEN>&aktion=listener           Listener neu starten
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
    $ws_p = ws_paths();

    if ($ws_aktion === 'scan') {
        $bin = $ws_p['bindir'] . '/check.pl';
        if (!is_file($bin)) {
            ws_ende(500, 'WIFI;OK=0;ERR=CHECK_FEHLT');
        }
        @exec('nohup perl ' . escapeshellarg($bin) . ' > /dev/null 2>&1 &');
        ws_ende(200, 'WIFI;OK=1;AKTION=scan');
    }

    if ($ws_aktion === 'listener') {
        $pid = ws_listener_restart();
        ws_ende($pid ? 200 : 500,
                ws_endpunkt_zeile('WIFI', array('OK' => $pid ? 1 : 0,
                                                'AKTION' => 'listener', 'PID' => (int) $pid)));
    }

    /* Die drei schreibenden Aktionen. Der Wert wird gegen dieselbe Pruefung
     * gehalten wie ein Feld der Oberflaeche - eine zweite Wahrheit ueber
     * zulaessige Werte gibt es nicht. */
    $ws_ziel = array('enable' => 'BASE.ENABLED', 'interval' => 'BASE.CRON');
    if (isset($ws_ziel[$ws_aktion]) || $ws_aktion === 'mode') {
        if ($ws_cfg === array()) {
            ws_ende(500, 'WIFI;OK=0;ERR=KEINE_KONFIG');
        }
        $neu = $ws_cfg;
        if ($ws_aktion === 'mode') {
            if ($ws_wert === '0') { $neu['BASE.FRITZBOX_ENABLE'] = '1'; $neu['BASE.ACTIVE_SCAN'] = '1'; }
            elseif ($ws_wert === '1') { $neu['BASE.FRITZBOX_ENABLE'] = '1'; $neu['BASE.ACTIVE_SCAN'] = '0'; }
            elseif ($ws_wert === '2') { $neu['BASE.FRITZBOX_ENABLE'] = '0'; $neu['BASE.ACTIVE_SCAN'] = '1'; }
            else { ws_ende(400, 'WIFI;OK=0;ERR=WERT'); }
        } else {
            $schluessel = $ws_ziel[$ws_aktion];
            if (ws_wert_pruefen($schluessel, $ws_wert) !== '') {
                ws_ende(400, 'WIFI;OK=0;ERR=WERT');
            }
            $neu[$schluessel] = $ws_wert;
        }
        if (!ws_config_write($neu)) {
            ws_ende(500, 'WIFI;OK=0;ERR=SCHREIBEN');
        }
        ws_cron_apply(ws_cfg($neu, 'BASE.ENABLED', '0'), ws_cfg($neu, 'BASE.CRON', '3'));
        ws_listener_restart();
        ws_ende(200, ws_endpunkt_zeile('WIFI', array('OK' => 1,
                                                     'AKTION' => $ws_aktion, 'WERT' => $ws_wert)));
    }

    ws_ende(400, 'WIFI;OK=0;ERR=AKTION');
}

/* ---------------- Abfragende Aufrufe ----------------
 * Ohne Merkwort erreichbar: sie loesen nichts aus und verraten nichts, was
 * nicht ohnehin am Miniserver steht. Das Merkwort selbst kommt hier NIE vor. */

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

$ws_fritz  = (string) ws_cfg($ws_cfg, 'BASE.FRITZBOX_ENABLE', '0');
$ws_aktiv  = (string) ws_cfg($ws_cfg, 'BASE.ACTIVE_SCAN', '0');
$ws_mode   = ($ws_fritz === '1' && $ws_aktiv === '1') ? 0 : (($ws_fritz === '1') ? 1 : 2);

$ws_felder = array(
    'OK'       => $ws_frisch ? (int) ($ws_z['ok'] === 1) : 0,
    'ALTER'    => $ws_alter,
    'ZAEHLER'  => isset($ws_z['zaehler']) ? (int) $ws_z['zaehler'] : -1,
    'PERSONEN' => count($ws_pers),
    'DA'       => $ws_da,
    'ENABLED'  => (int) (ws_cfg($ws_cfg, 'BASE.ENABLED', '0') === '1'),
    'MODE'     => $ws_mode,
    'INTERVAL' => (int) ws_cfg($ws_cfg, 'BASE.CRON', '3'),
    'LISTENER' => ws_listener_running() ? 1 : 0,
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
