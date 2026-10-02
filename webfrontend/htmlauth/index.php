<?php
/**
 * WifiScanner NG - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Loest die alte Perl-CGI-Oberflaeche mit HTML::Template ab.
 * Die Konfigurationsdatei bleibt im Config::Simple-Format,
 * damit check.pl und mqtt_listener.pl ohne Anpassung weiterlaufen.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * REIHENFOLGE IN DIESER DATEI - sie ist der Grund fuer drei Fehler, die in
 * 3.1.12 behoben wurden, und darf nicht umgestellt werden:
 *
 *   1. Bibliothek laden
 *   2. Konfiguration lesen, Vorgaben vervollstaendigen, Merkwort erzeugen
 *   3. WACHPOSTEN  (ein POST ohne gueltiges Merkmal wird hier entwaffnet)
 *   4. Reiterwahl
 *   5. Handler - darunter die beiden Downloads, die mit exit enden
 *   6. ERST JETZT LBWeb::lbheader()
 *   7. HTML
 *
 * Bis 3.1.11 stand der Sicherungs-Download hinter lbheader(). Der Kopf war
 * damit schon geschrieben, header('Content-Type: application/json') kam zu
 * spaet, und der Bediener bekam statt einer Datei eine HTML-Seite mit zwei
 * "headers already sent"-Warnungen und dem JSON mittendrin. Am laufenden
 * Webserver gemessen am 26.08.2026.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* ------------------------------------------------------------------
 * Die Bibliothek liegt seit 3.1.12 unter webfrontend/html/.
 *
 * Installiert sind das ZWEI getrennte Baeume:
 *   <home>/webfrontend/html/plugins/<ordner>/ws_lib.php
 *   <home>/webfrontend/htmlauth/plugins/<ordner>/index.php   (diese Datei)
 *
 * Ein require ueber '..' trifft nur das ausgepackte Archiv und ergibt
 * installiert einen leeren HTTP 500. Deshalb eine Kandidatenliste. Sie steht
 * hier ausgeschrieben, weil die Funktion, die sie sonst liefern wuerde, in
 * genau der Datei wohnt, die noch nicht geladen ist - das ist die eine
 * Doppelung, die sich nicht aufloesen laesst.
 * ------------------------------------------------------------------ */
$ws_kandidaten = array();
$ws_h = getenv('LBHOMEDIR');
$ws_pd = getenv('LBPPLUGINDIR');
if ($ws_h && $ws_pd) {
    $ws_kandidaten[] = $ws_h . '/webfrontend/html/plugins/' . $ws_pd . '/ws_lib.php';
}
$ws_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/html/plugins/' . basename(__DIR__) . '/ws_lib.php';
$ws_kandidaten[] = dirname(__DIR__) . '/html/ws_lib.php';
$ws_gefunden = '';
foreach ($ws_kandidaten as $ws_k) {
    if (is_file($ws_k)) { $ws_gefunden = $ws_k; break; }
}
if ($ws_gefunden === '') {
    /* Hier darf der Pfad in die Antwort: dieser Bereich ist angemeldet, und
     * ohne die Liste sucht der Betreiber im Dunkeln. */
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "WiFi Scanner NG: ws_lib.php nicht gefunden.\nGesucht wurde in:\n  "
       . implode("\n  ", $ws_kandidaten) . "\n";
    exit;
}
require_once $ws_gefunden;

$ws_p = ws_paths();
if ($ws_p['home']) {
    $ws_sdk_system = $ws_p['home'] . '/libs/phplib/loxberry_system.php';
    $ws_sdk_web = $ws_p['home'] . '/libs/phplib/loxberry_web.php';
    if (file_exists($ws_sdk_system)) {
        require_once $ws_sdk_system;
        require_once $ws_sdk_web;
    }
}

$ws_meldungen = array();
$ws_fehler = array();
$ws_hinweise = array();

/* ------------------------------------------------------------------
 * 1b. Laeuft gerade eine Aktualisierung?  (neu in 3.2.7)
 *
 * Diese Pruefung steht VOR Schritt 2, und das ist der ganze Punkt: Schritt 2
 * liest die Konfiguration, ergaenzt die Vorgaben und erzeugt bei einem GET
 * ein Merkwort, wenn keines dasteht - und ws_config_write() zieht dabei die
 * Zweitschrift config/plugins/<ordner>.wifi_scanner.backup mit.
 *
 * In der Luecke zwischen purge_installation und postupgrade.sh ist genau das
 * ein Schaden, und er ist gemessen (18.09.2026, WSL,
 * Pruefung-WiFi-Scanner-NG-3.2.7/Pruefstaende/messe_luecke.sh):
 *
 *   Fall B1b  ein einziger Seitenaufruf machte aus
 *             "TOKEN=ECHTESMERKWORT..., USERS=2, MACS=<erfunden>" in
 *             BEIDEN Dateien "TOKEN=5b09b7cf27a433e51ca1c2a9, USERS=0,
 *             MACS=".
 *   Fall B1c  postinstall.sh holte danach nichts mehr zurueck: seine Probe
 *             vergleicht die Pruefsumme mit der mitgelieferten Vorgabe, und
 *             die stimmte nach dem Schreiben nicht mehr. Die Zeile
 *             "<OK> wifi_scanner.cfg aus der Zweitschrift wiederhergestellt."
 *             blieb aus; in der Gegenprobe C2 ohne Seitenaufruf steht sie.
 *   Fall C    postupgrade.sh rettete die Konfiguration aus der
 *             upgrade_sicherung - die Zweitschrift daneben blieb kaputt.
 *             Genau aus ihr heilt ws_config_read() spaeter, und nur sie
 *             uebersteht eine Neuinstallation.
 *
 * Dass gesperrt wird, ist damit eine Messung und keine Vorsichtsregel
 * (Regeln/06: Intercom 2.2.11 sperrt, Sprachsteuerung 0.11.7 nicht).
 *
 * Aelter als eine Stunde oder unlesbar gilt die Marke nicht - eine
 * abgebrochene Installation darf die Seite nicht fuer immer stilllegen.
 * ------------------------------------------------------------------ */
if (ws_upgrade_laeuft()) {
    $ws_war_post = (isset($_SERVER['REQUEST_METHOD'])
                    && $_SERVER['REQUEST_METHOD'] === 'POST');
    if (class_exists('LBWeb', false)) {
        LBWeb::lbheader(ws_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
    }
    echo '<div class="smw">' . "\n"
       . '<h1>' . ws_e(ws_t('ALLG.TITEL')) . '</h1>' . "\n"
       . '<div style="border:2px solid #e0620d;background:#fff4ec;'
       . 'padding:12px 16px;margin:12px 0;border-radius:4px;">' . "\n"
       . '<b>' . ws_e(ws_t('HINWEIS.UPGRADE_LAEUFT')) . '</b><br>' . "\n"
       . ws_e(ws_t('HINWEIS.UPGRADE_LAEUFT_TEXT')) . "\n";
    if ($ws_war_post) {
        echo '<br><br>' . ws_e(ws_t('HINWEIS.UPGRADE_NICHT_GESPEICHERT')) . "\n";
    }
    echo '</div>' . "\n" . '</div>' . "\n";
    if (class_exists('LBWeb', false)) {
        LBWeb::lbfooter();
    }
    exit;
}

/* ------------------------------------------------------------------
 * 2. Konfiguration, Vorgaben, Merkwort
 *
 * Fehlt ein Schluessel, wird er EINMAL mit seiner Vorgabe geschrieben -
 * geprueft mit array_key_exists(), nicht mit isset(): ein Schluessel, der da
 * ist und leer, wurde bewusst geleert und bleibt in Ruhe.
 *
 * Das Merkwort ist die Ausnahme davon, und zwar mit Grund: es gibt keinen
 * Knopf, der es leert - nur einen, der ein neues erzeugt. Ein leeres Merkwort
 * ist deshalb kein gewollter Zustand, sondern der einer Anlage, die von einer
 * Fassung vor 3.1.12 kommt. Erzeugt wird es nur bei einem GET: ein POST, der
 * gerade am Wachposten scheitert, soll nicht nebenbei ein Merkwort anlegen.
 * ------------------------------------------------------------------ */
$ws_cfg = ws_config_read();
$ws_zu_schreiben = false;
foreach (ws_vorgaben() as $ws_vk => $ws_vv) {
    if (!array_key_exists($ws_vk, $ws_cfg)) {
        $ws_cfg[$ws_vk] = $ws_vv;
        $ws_zu_schreiben = true;
    }
}
/* Mit isset: unter der Kommandozeile gibt es REQUEST_METHOD nicht. Die
 * Verneinung muss dabei WAHR bleiben, wie bisher - deshalb '' als Ersatzwert
 * und nicht isset() && … (13.09.2026). */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST'
        && trim((string) $ws_cfg['BASE.TOKEN']) === '') {
    $ws_cfg['BASE.TOKEN'] = ws_token_erzeugen();
    $ws_zu_schreiben = true;
}
if ($ws_zu_schreiben && ws_config_write($ws_cfg)) {
    $ws_cfg = ws_config_read();
}
$ws_fmt = ws_formtoken($ws_cfg);

/* ------------------------------------------------------------------
 * 2b. Einmalmeldung (PRG) und Eingaben nach einer Beanstandung (X-2)
 *
 * O1/O2 (Durchgang 02.10.2026; Entscheidungen 16 und 19; Regeln/04): bis
 * 3.2.9 antwortete jeder POST mit 200 und der fertigen Seite. Neuladen
 * wiederholte Speichern, Suchlauf, Listener-Neustart und Zurueckspielen, und
 * nach "Neues Merkwort" stand beim Neuladen eine Fehlermeldung da, fuer die
 * der Bediener nichts konnte (Pruefer Oberflaeche Nr. 1). Jetzt endet jeder
 * POST mit 303 auf index.php?form=<reiter>; das Ergebnis reist in
 * data/plugins/<ordner>/einmalmeldung.json (0600, hoechstens 120 s alt, nur
 * beim GET gelesen und dabei geloescht). Die Downloads (Vorlage, Sicherung)
 * liefern weiter unmittelbar ihre Datei.
 * ------------------------------------------------------------------ */
$ws_war_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$ws_misslungen = array();   // O9: "Der Vorgang ist nicht gelungen"
$ws_eingaben = array();     // X-2: formular, werte, zeilen, falsch
$ws_flash_datei = $ws_p['datadir'] . '/einmalmeldung.json';
if (!$ws_war_post && is_file($ws_flash_datei)) {
    $ws_flash = @json_decode((string) @file_get_contents($ws_flash_datei), true);
    @unlink($ws_flash_datei);
    $ws_flash_alter = (is_array($ws_flash) && isset($ws_flash['ts'])) ? time() - (int) $ws_flash['ts'] : 9999;
    if ($ws_flash_alter >= -5 && $ws_flash_alter <= 120) {
        $ws_meldungen  = ws_flash_liste($ws_flash, 'meldungen');
        $ws_fehler     = ws_flash_liste($ws_flash, 'fehler');
        $ws_hinweise   = ws_flash_liste($ws_flash, 'hinweise');
        $ws_misslungen = ws_flash_liste($ws_flash, 'misslungen');
        if (isset($ws_flash['eingaben']) && is_array($ws_flash['eingaben'])) {
            $ws_eingaben = $ws_flash['eingaben'];
        }
    }
}

/** Eine Liste aus der Einmalmeldung - nur Zeichenketten. */
function ws_flash_liste($f, $k)
{
    $aus = array();
    if (is_array($f) && isset($f[$k]) && is_array($f[$k])) {
        foreach ($f[$k] as $z) {
            if (is_string($z)) { $aus[] = $z; }
        }
    }
    return $aus;
}

/** Nach jedem POST: Ergebnis ablegen (0600, unteilbar), dann 303 auf den Reiter. */
function ws_umleiten($tab)
{
    global $ws_meldungen, $ws_fehler, $ws_hinweise, $ws_misslungen, $ws_eingaben, $ws_flash_datei;
    $js = json_encode(array('ts' => time(), 'meldungen' => $ws_meldungen, 'fehler' => $ws_fehler,
                            'hinweise' => $ws_hinweise, 'misslungen' => $ws_misslungen,
                            'eingaben' => $ws_eingaben),
                      JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($js !== false) {
        @mkdir(dirname($ws_flash_datei), 0775, true);
        $tmp = $ws_flash_datei . '.tmp.' . getmypid();
        $fh = @fopen($tmp, 'c');
        if ($fh !== false) {
            @chmod($tmp, 0600);
            $ok = @ftruncate($fh, 0) && @fwrite($fh, $js) === strlen($js);
            @fclose($fh);
            if (!$ok || !@rename($tmp, $ws_flash_datei)) { @unlink($tmp); }
        }
    }
    header('Location: index.php?form=' . substr((string) $tab, 4), true, 303);
    header('Cache-Control: no-store');
    exit;
}

/** Ein Formularfeld als Zeichenkette ('' wenn es fehlt oder ein Feld ist). */
function ws_post_text($k)
{
    return (isset($_POST[$k]) && is_string($_POST[$k])) ? $_POST[$k] : '';
}

/* ---- X-2: Werte und Markierung nach einer Beanstandung (Regeln/04) ----
 * Bauform tb_fa/tb_fw/tb_fh/tb_fm (Spotpreis Tibber, Durchgang 01.10.2026). */
/** Ist dieses Formular das beanstandete? */
function ws_fa($formular)
{
    global $ws_eingaben;
    return is_array($ws_eingaben) && isset($ws_eingaben['formular']) && $ws_eingaben['formular'] === $formular;
}
/** Wert eines Feldes: nach einer Beanstandung die Eingabe, sonst der gespeicherte. */
function ws_fw($formular, $feld, $gespeichert)
{
    global $ws_eingaben;
    if (ws_fa($formular) && isset($ws_eingaben['werte'][$feld]) && is_string($ws_eingaben['werte'][$feld])) {
        return $ws_eingaben['werte'][$feld];
    }
    return is_scalar($gespeichert) ? (string) $gespeichert : '';
}
/** Haken: nach einer Beanstandung so, wie er abgeschickt wurde. */
function ws_fh($formular, $feld, $gespeichert)
{
    global $ws_eingaben;
    if (!ws_fa($formular)) { return (bool) $gespeichert; }
    return isset($ws_eingaben['werte'][$feld]) && $ws_eingaben['werte'][$feld] === '1';
}
/** Markierung eines beanstandeten Feldes (Attribute, schon maskiert). */
function ws_fm($feld)
{
    global $ws_eingaben;
    return (is_array($ws_eingaben) && isset($ws_eingaben['falsch']) && is_array($ws_eingaben['falsch'])
            && in_array($feld, $ws_eingaben['falsch'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
/** Die abgeschickten Werte eines Formulars fuer X-2 einsammeln - nur gueltiges
 *  UTF-8, hoechstens 2100 Zeichen je Feld, NIE das Fritz!Box-Kennwort. */
function ws_eingaben_sammeln($formular, array $felder, array $haken, array $falsch)
{
    $werte = array();
    foreach ($felder as $f) {
        if (isset($_POST[$f]) && is_string($_POST[$f]) && strlen($_POST[$f]) <= 2100
            && preg_match('//u', $_POST[$f])) {
            $werte[$f] = $_POST[$f];
        }
    }
    foreach ($haken as $f) {
        if (isset($_POST[$f])) { $werte[$f] = '1'; }
    }
    $zeilen = array();
    if ($formular === 'settings' && isset($_POST['username']) && is_array($_POST['username'])) {
        foreach ($_POST['username'] as $i => $n) {
            if (!is_string($n) || preg_match('/^[0-9]{1,3}$/', (string) $i) !== 1) { continue; }
            $m = (isset($_POST['macs'][$i]) && is_string($_POST['macs'][$i])) ? $_POST['macs'][$i] : '';
            if (strlen($n) > 2100 || strlen($m) > 2100 || !preg_match('//u', $n . $m)) { continue; }
            $zeilen[] = array('i' => (int) $i, 'name' => $n, 'macs' => $m,
                              'loeschen' => isset($_POST['uloeschen'][$i]) ? 1 : 0);
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'zeilen' => $zeilen,
                 'falsch' => array_values(array_unique($falsch)));
}

/**
 * M2 (Durchgang 02.10.2026, Entscheidungen 5 und 8): eine ausgetragene oder
 * umbenannte Person bekommt beim Speichern einmal "-" retained, mit Nachlesen
 * beim Broker. Bis 3.2.9 blieb ihr letzter Wert stehen; BLE-Scanner meldete
 * sie danach dauerhaft als anwesend (Pruefer mqtt Nr. 2, an der echten
 * BLE-Logik gemessen). Das bis 3.2.9 gesendete Umlautthema der Person
 * (wifi_ng/J_rgen, C3) bekommt "-" nur, wenn dort noch ein Wert liegt.
 * Rueckgabe: array('ok' => Saetze, 'nicht' => Saetze).
 */
function ws_ausgetragen_abraeumen(array $alt, array $neu)
{
    $bleibt = array();
    foreach (ws_users($neu) as $u) {
        if ($u['name'] !== '' && ws_topic_name($u['name']) !== '') {
            $bleibt['wifi_ng/' . ws_topic_name($u['name'])] = 1;
        }
    }
    $weg = array();
    $weg_alt = array();
    foreach (ws_users($alt) as $u) {
        if ($u['name'] === '') { continue; }
        $t = ws_topic_name($u['name']);
        if ($t !== '' && !isset($bleibt['wifi_ng/' . $t])) {
            $weg['wifi_ng/' . $t] = 1;
        }
        $ta = ws_topic_name_alt($u['name']);
        if ($ta !== '' && $ta !== $t && !isset($bleibt['wifi_ng/' . $ta])) {
            $weg_alt['wifi_ng/' . $ta] = 1;
        }
    }
    $erg = array('ok' => array(), 'nicht' => array());
    if ($weg) {
        list($rc, $aus) = ws_mqtt_aufraeumen('--strich', array_keys($weg));
        if ($rc === 0) {
            $erg['ok'][] = sprintf(ws_t('MELD.AUSGETRAGEN_OK'), ws_e(implode(', ', array_keys($weg))));
        } else {
            $erg['nicht'][] = sprintf(ws_t('MELD.AUSGETRAGEN_NICHT'), ws_e(implode(', ', array_keys($weg))),
                                      (int) $rc, ws_e(implode(' / ', array_slice($aus, -2))));
        }
    }
    if ($weg_alt) {
        list($rc, $aus) = ws_mqtt_aufraeumen('--strich-wenn-da', array_keys($weg_alt));
        if ($rc !== 0) {
            $erg['nicht'][] = sprintf(ws_t('MELD.AUSGETRAGEN_NICHT'), ws_e(implode(', ', array_keys($weg_alt))),
                                      (int) $rc, ws_e(implode(' / ', array_slice($aus, -2))));
        }
    }
    return $erg;
}

/**
 * I6 (Durchgang 02.10.2026, Entscheidungen 3 und 26): beim Wechsel von MQTT auf
 * UDP wird der Listener angehalten (er sendete sonst beim naechsten Start
 * status/mode, /interval und /enabled wieder retained), und die
 * zurueckbehaltenen Themen unter wifi_ng/ werden geleert, mit Nachlesen. Bis
 * 3.2.9 blieben sie mit dem letzten Wert stehen (Pruefer mqtt Nr. 5).
 */
function ws_wechsel_auf_udp()
{
    global $ws_meldungen, $ws_misslungen;
    ws_listener_stop();
    list($rc, $aus) = ws_mqtt_aufraeumen('--leeren-alle');
    $n = 0;
    foreach ($aus as $z) {
        if (preg_match('/^GEFUNDEN ([0-9]+)/', (string) $z, $m) === 1) { $n = (int) $m[1]; }
    }
    if ($rc === 0) {
        $ws_meldungen[] = sprintf(ws_t('MELD.UDP_ABGERAEUMT'), $n);
    } else {
        $ws_misslungen[] = sprintf(ws_t('MELD.UDP_NICHT_ABGERAEUMT'), (int) $rc,
                                   ws_e(implode(' / ', array_slice($aus, -2))));
    }
}

/* ------------------------------------------------------------------
 * 3. Der Wachposten
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht: HTTP-Basic geht automatisch mit, und SameSite
 * greift dabei nicht. Bis 3.1.11 genuegte ein <img src=".../ws_test.php?restart">
 * auf einer beliebigen Seite, um den Listener neu zu starten.
 *
 * EIN Wachposten, nicht acht Abfragen: faellt er durch, wird $_POST bis auf
 * den Reiter geleert. Damit ist jeder kuenftig ergaenzte Handler
 * mitgeschuetzt - einen einzelnen kann man beim Erweitern vergessen, einen
 * Wachposten am Eingang nicht.
 *
 * Fail closed: ohne hinterlegtes Merkwort gibt es nichts zu vergleichen, und
 * hash_equals('', '') waere wahr.
 * ------------------------------------------------------------------ */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($ws_fmt === '') {
        $ws_fehler[] = ws_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!ws_formtoken_ok($ws_cfg)) {
        $ws_fehler[] = ws_t('FEHLER.CSRF');
        error_log('wifi_ng: Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
    if ($ws_fehler) {
        $ws_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($ws_behalten !== null) { $_POST['activetab'] = $ws_behalten; }
    }
}

/* ------------------------------------------------------------------
 * 4. Aktiver Reiter
 *
 * Die Positivliste steht EINMAL und ausgeschrieben - so findet
 * hausstandard_pruefen.py sie, und die Kongruenz mit Leiste und Flaechen
 * prueft der Reiter Test nach.
 * ------------------------------------------------------------------ */
$ws_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$ws_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $ws_reiter, true)) {
    $ws_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $ws_reiter, true)) {
    $ws_tab = 'tab-' . (string) $_GET['form'];
}

/* ==================================================================
 * 5. Handler
 *
 * Jeder fasst nur seine eigenen Schluessel an und laedt den Bestand vorher.
 * Sonst stellte das Speichern der Einstellungen jedes Mal den
 * Uebertragungsweg zurueck.
 * ================================================================== */

/* ---------------- Einstellungen ----------------
 *
 * O2 (Durchgang 02.10.2026; Entscheidungen 16 und 19): bei einer Beanstandung
 * wird NICHTS gespeichert - auch nicht die uebrigen, richtigen Felder. Alle
 * Beanstandungen werden gesammelt, die Felder markiert, und die Eingaben
 * reisen mit der Einmalmeldung zurueck ins Formular (X-2); das Kennwort nie.
 * Nichts wird mehr geklemmt oder ersetzt: bis 3.2.9 wurde aus Port 70000
 * still 49443, aus Takt 7 still 3, und eine Person mit Tippfehler in der
 * Adresse verschwand aus der Konfiguration (Pruefer Oberflaeche Nr. 2, 7, 8).
 * Ausnahme nach Entscheidung 19: Leerraum am Rand wird still abgeschnitten.
 */
if (isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = ws_config_read();
    $ws_alt = $neu;
    $ws_mangel = array();
    $ws_falsch = array();

    $neu['BASE.ENABLED']         = isset($_POST['enabled']) ? '1' : '0';
    $neu['BASE.FRITZBOX_ENABLE'] = isset($_POST['fritz_enable']) ? '1' : '0';
    $neu['BASE.ACTIVE_SCAN']     = isset($_POST['active_scan']) ? '1' : '0';
    $neu['BASE.USE_CACHE']       = isset($_POST['use_cache']) ? '1' : '0';

    $ws_cron_ein = trim(ws_post_text('cron'));
    if (in_array($ws_cron_ein, ws_takte(), true)) {
        $neu['BASE.CRON'] = $ws_cron_ein;
    } else {
        $ws_mangel[] = sprintf(ws_t('EINST.B_TAKT'), ws_e($ws_cron_ein), ws_e(implode(', ', ws_takte())));
        $ws_falsch[] = 'cron';
    }
    $ws_ping = trim(ws_post_text('ping_cmd'));
    if ($ws_ping === '0' || $ws_ping === '1') {
        $neu['BASE.PING_CMD'] = $ws_ping;
    } else {
        $ws_mangel[] = sprintf(ws_t('EINST.B_BEFEHL'), ws_e($ws_ping));
        $ws_falsch[] = 'ping_cmd';
    }

    /* Namen und Adresslisten: nur Steuerzeichen und Anfuehrungszeichen raus,
     * wie bisher. preg_replace gibt bei ungueltigem UTF-8 null zurueck; ohne
     * den Rueckfall wuerde daraus ein leerer Wert. */
    $saeubern = function ($s) {
        $r = preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s);
        if ($r === null) { $r = preg_replace('/[\x00-\x1F\x7F"\']+/', '', (string) $s); }
        return trim((string) $r);
    };
    /* Frage 4 (Durchgang 02.10.2026): im NAMEN nur noch Steuerzeichen still
     * entfernen - Anfuehrungszeichen bleiben stehen und werden unten von
     * ws_wert_pruefen() beanstandet (Feld markiert, Eingabe zurueck). */
    $ohne_steuer = function ($s) {
        $r = preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $s);
        if ($r === null) { $r = preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $s); }
        return trim((string) $r);
    };

    $ws_fb = trim(ws_post_text('fritzbox'));
    if ($ws_fb === '' || ws_adresse_art($ws_fb) === '') {
        $ws_mangel[] = sprintf(ws_t('EINST.M_FRITZ_ADR'), ws_e($ws_fb));
        $ws_falsch[] = 'fritzbox';
    } else {
        $neu['BASE.FRITZBOX'] = $ws_fb;
    }
    $ws_fp = trim(ws_post_text('fritzbox_port'));
    if (ws_wert_pruefen('BASE.FRITZBOX_PORT', $ws_fp) === '') {
        $neu['BASE.FRITZBOX_PORT'] = $ws_fp;
    } else {
        $ws_mangel[] = sprintf(ws_t('EINST.B_PORT'), ws_e($ws_fp));
        $ws_falsch[] = 'fritzbox_port';
    }

    /* Zugangsdaten der Fritz!Box (ab Werk leer). O5: Anfuehrungszeichen werden
     * nicht mehr still entfernt, sondern abgewiesen (ws_wert_pruefen). O7: es
     * gelten dieselben Grenzen wie beim Zurueckspielen.
     *
     * Ein leeres Kennwortfeld LOESCHT nichts: der Browser fuellt ein
     * type="password"-Feld nicht mit dem Bestand. Zum Loeschen gibt es den
     * ausdruecklichen Haken daneben. */
    $ws_fu = trim(ws_post_text('fritzbox_user'));
    $ws_g = ws_wert_pruefen('BASE.FRITZBOX_USER', $ws_fu);
    if ($ws_g === '') {
        $neu['BASE.FRITZBOX_USER'] = $ws_fu;
    } else {
        $ws_mangel[] = sprintf(ws_t('EINST.B_FRITZ_USER'), ws_e($ws_g));
        $ws_falsch[] = 'fritzbox_user';
    }
    if (isset($_POST['fritz_pass_loeschen'])) {
        $neu['BASE.FRITZBOX_PASS'] = '';
    } else {
        $ws_pw = trim(ws_post_text('fritzbox_pass'));
        if ($ws_pw === '') {
            $neu['BASE.FRITZBOX_PASS'] = ws_cfg($ws_cfg, 'BASE.FRITZBOX_PASS', '');
        } else {
            $ws_g = ws_wert_pruefen('BASE.FRITZBOX_PASS', $ws_pw);
            if ($ws_g === '') {
                $neu['BASE.FRITZBOX_PASS'] = $ws_pw;
            } else {
                $ws_mangel[] = sprintf(ws_t('EINST.B_FRITZ_PASS'), ws_e($ws_g));
                $ws_falsch[] = 'fritzbox_pass';
            }
        }
    }

    // Uebertragungsweg und Merkwort wohnen anderswo - hier aus dem Bestand
    // uebernehmen, sonst stellte jedes Speichern UDP-Anlagen auf MQTT um.
    $neu['BASE.UDP_ENABLE'] = ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0');
    $neu['BASE.PORT']       = ws_cfg($ws_cfg, 'BASE.PORT', '7007');
    $neu['BASE.TOKEN']      = ws_cfg($ws_cfg, 'BASE.TOKEN', '');

    /* Personen.
     *
     * Die Formularfelder tragen AUSGESCHRIEBENE Indizes (username[0], ...).
     * Geloescht wird ueber den Haken, nicht durch Leeren des Namens.
     *
     * O2: eine Zeile mit ungueltiger Adresse, ohne Namen oder ohne Adresse ist
     * eine Beanstandung - sie wird markiert, nicht geloescht. O3: eine MAC mit
     * Bindestrichen wird ausdruecklich benannt. O4: gleiche oder leere
     * Themennamen ("Anna B"/"Anna_B", "???", "李雷") sind eine Beanstandung. */
    $ws_roh = array();
    $ws_themen_zeilen = array();
    $namen = isset($_POST['username']) && is_array($_POST['username']) ? $_POST['username'] : array();
    foreach ($namen as $i => $name) {
        if (!is_string($name) || isset($_POST['uloeschen'][$i])) {
            continue;   // ausdruecklich abgewaehlt
        }
        $name = $ohne_steuer($name);
        $liste = $saeubern(isset($_POST['macs'][$i]) && is_string($_POST['macs'][$i]) ? $_POST['macs'][$i] : '');
        if ($name === '' && $liste === '') {
            continue;   // leere Anlegezeile - kein Verlust, keine Meldung
        }
        $ws_wer = $name !== '' ? $name : ws_t('EINST.OHNE_NAME');
        list($gut, $schlecht) = ws_adressen_zerlegen($liste);
        $ws_strich = array_values(array_filter($schlecht, 'ws_ist_mac_strich'));
        $ws_sonst = array_values(array_diff($schlecht, $ws_strich));
        if ($ws_strich) {
            $ws_mangel[] = sprintf(ws_t('EINST.M_MAC_STRICH'), ws_e($ws_wer), ws_e(implode(', ', $ws_strich)));
            $ws_falsch[] = 'macs[' . (int) $i . ']';
        }
        if ($ws_sonst) {
            $ws_mangel[] = sprintf(ws_t('EINST.M_ADR_ABGEWIESEN'), ws_e($ws_wer), ws_e(implode(', ', $ws_sonst)));
            $ws_falsch[] = 'macs[' . (int) $i . ']';
        }
        if ($name === '') {
            $ws_mangel[] = sprintf(ws_t('EINST.M_OHNE_NAME'), ws_e(implode(', ', $gut)));
            $ws_falsch[] = 'username[' . (int) $i . ']';
            continue;
        }
        if (!$gut && !$schlecht) {
            $ws_mangel[] = sprintf(ws_t('EINST.M_OHNE_ADRESSE'), ws_e($name));
            $ws_falsch[] = 'macs[' . (int) $i . ']';
        }
        $ws_g = ws_wert_pruefen('USER1.NAME', $name);
        if ($ws_g !== '') {
            $ws_mangel[] = sprintf(ws_t('EINST.B_NAME'), ws_e($name), ws_e($ws_g));
            $ws_falsch[] = 'username[' . (int) $i . ']';
        }
        $ws_t = ws_topic_name($name);
        if ($ws_t !== '') {
            $ws_themen_zeilen[$ws_t][] = array((int) $i, $name);
        }
        $ws_roh[] = array('name' => $name, 'macs' => implode(';', $gut));
    }
    foreach ($ws_themen_zeilen as $ws_t => $ws_zl) {
        if (count($ws_zl) < 2) { continue; }
        $ws_nn = array();
        foreach ($ws_zl as $ws_z1) {
            $ws_nn[] = $ws_z1[1];
            $ws_falsch[] = 'username[' . $ws_z1[0] . ']';
        }
        $ws_mangel[] = sprintf(ws_t('EINST.M_THEMA_DOPPELT'), ws_e('wifi_ng/' . $ws_t), ws_e(implode(', ', $ws_nn)));
    }
    // Alte Personenabschnitte entfernen, danach neu durchnummerieren.
    foreach (array_keys($neu) as $k) {
        if (preg_match('/^USER[0-9]+[.]/', (string) $k) === 1) {
            unset($neu[$k]);
        }
    }
    $n = 0;
    foreach ($ws_roh as $u) {
        $n++;
        $neu['USER' . $n . '.NAME'] = $u['name'];
        $neu['USER' . $n . '.MACS'] = $u['macs'];
    }
    $neu['BASE.USERS'] = (string) $n;

    if ($ws_mangel) {
        $ws_fehler[] = ws_t('EINST.B_KOPF') . '<ul><li>' . implode('</li><li>', $ws_mangel) . '</li></ul>';
        $ws_eingaben = ws_eingaben_sammeln('settings',
            array('cron', 'ping_cmd', 'fritzbox', 'fritzbox_port', 'fritzbox_user'),
            array('enabled', 'fritz_enable', 'active_scan', 'use_cache', 'fritz_pass_loeschen'), $ws_falsch);
    } elseif (ws_config_write($neu)) {
        ws_cron_apply($neu['BASE.ENABLED'], $neu['BASE.CRON']);
        $ws_meldungen[] = ws_t('MELD.ZEITPLAN_NEU');
        /* Dieselbe Adresse bei zwei Personen ist ein Mangel, keine Sperre:
         * gespeichert wird, aber der Bediener erfaehrt es. */
        foreach (ws_doppelte_adressen(ws_users($neu)) as $ws_adr => $ws_wer) {
            $ws_hinweise[] = sprintf(ws_t('EINST.M_DOPPELT'), ws_e($ws_adr), ws_e(implode(', ', $ws_wer)));
        }
        /* O9: der Listener-Satz nur bei MQTT, und "neu gestartet" nur, wenn er
         * danach laeuft - sonst steht er unter "nicht gelungen" (bis 3.2.9 stand
         * der Erfolgssatz auch neben "MQTT-Listener: laeuft nicht"). */
        if (ws_cfg($neu, 'BASE.UDP_ENABLE', '0') !== '1') {
            $ws_ab = ws_ausgetragen_abraeumen($ws_alt, $neu);
            foreach ($ws_ab['ok'] as $ws_x) { $ws_meldungen[] = $ws_x; }
            foreach ($ws_ab['nicht'] as $ws_x) { $ws_misslungen[] = $ws_x; }
            $ws_lpid = ws_listener_restart();
            if ($ws_lpid) {
                $ws_meldungen[] = sprintf(ws_t('MELD.LISTENER_NEU'), (int) $ws_lpid);
            } else {
                $ws_misslungen[] = ws_t('MELD.LISTENER_NICHT');
            }
        }
        $ws_cfg = ws_config_read();
        $ws_fmt = ws_formtoken($ws_cfg);
    } else {
        $ws_fehler[] = sprintf(ws_t('FEHLER.CONFIG_SCHREIBEN'), ws_e($ws_p['config']));
    }
    $ws_tab = 'tab-settings';
}

/* ---------------- MQTT / Uebertragungsweg ----------------
 * O2: ein ungueltiger UDP-Port wird beanstandet, nicht geklemmt (bis 3.2.9
 * wurde aus 0 und 7007.9 still 7007). I6: Wechsel auf UDP raeumt ab. */
if (isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mqtt_save'])) {
    $neu = ws_config_read();
    $ws_war_udp = ws_cfg($neu, 'BASE.UDP_ENABLE', '0') === '1';
    $ws_mangel = array();
    $ws_falsch = array();
    $neu['BASE.UDP_ENABLE'] = (ws_post_text('out_way') === 'udp') ? '1' : '0';
    $ws_up = trim(ws_post_text('udpport'));
    if (ws_wert_pruefen('BASE.PORT', $ws_up) === '') {
        $neu['BASE.PORT'] = $ws_up;
    } else {
        $ws_mangel[] = sprintf(ws_t('EINST.B_UDPPORT'), ws_e($ws_up));
        $ws_falsch[] = 'udpport';
    }
    if ($ws_mangel) {
        $ws_fehler[] = ws_t('EINST.B_KOPF') . '<ul><li>' . implode('</li><li>', $ws_mangel) . '</li></ul>';
        $ws_eingaben = ws_eingaben_sammeln('mqtt', array('out_way', 'udpport'), array(), $ws_falsch);
    } elseif (ws_config_write($neu)) {
        $ws_meldungen[] = ws_t('MELD.WEG_GESPEICHERT');
        if ($neu['BASE.UDP_ENABLE'] === '1') {
            if (!$ws_war_udp) {
                ws_wechsel_auf_udp();
            }
        } else {
            $ws_lpid = ws_listener_restart();
            if ($ws_lpid) {
                $ws_meldungen[] = sprintf(ws_t('MELD.LISTENER_NEU'), (int) $ws_lpid);
            } else {
                $ws_misslungen[] = ws_t('MELD.LISTENER_NICHT');
            }
        }
        $ws_cfg = ws_config_read();
        $ws_fmt = ws_formtoken($ws_cfg);
    } else {
        $ws_fehler[] = sprintf(ws_t('FEHLER.CONFIG_SCHREIBEN'), ws_e($ws_p['config']));
    }
    $ws_tab = 'tab-mqtt';
}

/* ---------------- Merkwort neu erzeugen ----------------
 * Orange, weil es wirkt: jede Adresse, die im Miniserver mit dem alten
 * Merkwort eingetragen ist, bekommt danach 403. */
if (isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $neu = ws_config_read();
    $neu['BASE.TOKEN'] = ws_token_erzeugen();
    if (ws_config_write($neu)) {
        $ws_cfg = ws_config_read();
        $ws_fmt = ws_formtoken($ws_cfg);
        $ws_meldungen[] = ws_t('MELD.TOKEN_NEU');
    } else {
        $ws_fehler[] = sprintf(ws_t('FEHLER.CONFIG_SCHREIBEN'), ws_e($ws_p['config']));
    }
    $ws_tab = 'tab-loxone';
}

/* ---------------- Aktionen aus dem Reiter Test ----------------
 * Seit 3.1.12 POST statt eines Verweises: sie loesen etwas aus, und ein
 * <a href> tut das auf Zuruf jeder fremden Seite. */
if (isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aktion'])) {
    $ws_a = (string) $_POST['aktion'];
    if ($ws_a === 'scan') {
        $bin = $ws_p['bindir'] . '/check.pl';
        if (is_file($bin)) {
            // C13: --auftrag - sucht auch bei ausgeschaltetem regelmaessigem Suchen.
            @exec('nohup perl ' . escapeshellarg($bin) . ' --auftrag > /dev/null 2>&1 &');
            $ws_meldungen[] = ws_t('TEST.M_SCAN');
        } else {
            $ws_fehler[] = sprintf(ws_t('T.NICHT_GEFUNDEN'), 'check.pl', ws_e($bin));
        }
    } elseif ($ws_a === 'restart') {
        $ws_pid = ws_listener_restart();
        if ($ws_pid) {
            $ws_meldungen[] = sprintf(ws_t('TEST.M_RESTART'), (int) $ws_pid);
        } else {
            $ws_fehler[] = ws_t('T.LISTENER_TOT');
        }
    } else {
        $ws_fehler[] = ws_t('TEST.M_UNBEKANNT');
    }
    $ws_tab = 'tab-test';
}

/* ==================================================================
 * Die beiden Downloads - VOR lbheader(), weil sie eigene Kopfzeilen setzen
 * ================================================================== */

/* ---------------- Loxone-Vorlage ---------------- */
if (isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    list($ws_vname, $ws_vinhalt) = ws_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $ws_vname . '"');
    header('Content-Length: ' . strlen($ws_vinhalt));
    header('Cache-Control: no-store');
    echo $ws_vinhalt;
    exit;
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Merkwort. Ohne es stuenden
 * nach dem Zurueckspielen alle Felder richtig, und der Miniserver kaeme
 * trotzdem nicht an den Endpunkt; die Datei waere als Umzugshilfe wertlos.
 * Damit traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * Das Formularmerkmal gehoert ausdruecklich NICHT hinein - es wird aus dem
 * Merkwort abgeleitet und lebt eine Sitzung lang. */
if (isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ws_sichern'])) {
    list($ws_kopf, $ws_werte) = ws_sicherung_inhalt();
    $ws_js = json_encode(array_merge($ws_kopf, $ws_werte),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ws_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wifi_einstellungen_'
               . date('Ymd_His') . '.json"');
        header('Content-Length: ' . strlen($ws_js));
        header('Cache-Control: no-store');
        echo $ws_js;
        exit;
    }
    $ws_fehler[] = ws_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen.
 *
 * Und danach wird der Dienst NACHGEZOGEN. Bis 3.1.11 wurden Zeitplan und
 * Listener beim Zurueckspielen nicht angefasst, waehrend die Meldung
 * behauptete, beides sei geschehen. */
if (isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ws_zurueck'])) {
    if (!isset($_FILES['ws_sicherung']) || !is_array($_FILES['ws_sicherung'])
        || !isset($_FILES['ws_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ws_sicherung']['tmp_name'])) {
        $ws_fehler[] = ws_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ws_sicherung']['size'] > 65536) {
        $ws_fehler[] = ws_t('EINST.SICH_ZU_GROSS');
    } else {
        $ws_war_udp = ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') === '1';
        $ws_alt_r = ws_config_read();   // Frage 3: die Personen VOR dem Zurueckspielen
        list($ws_neu, $ws_mangel, $ws_n, $ws_sh) = ws_sicherung_lesen(
            (string) @file_get_contents($_FILES['ws_sicherung']['tmp_name']));
        if ($ws_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $ws_fehler[] = ws_t('EINST.SICH_ABGELEHNT') . ' ' . implode(' ', $ws_mangel);
        } elseif (ws_config_write($ws_neu)) {
            ws_cron_apply(ws_cfg($ws_neu, 'BASE.ENABLED', '0'), ws_cfg($ws_neu, 'BASE.CRON', '3'));
            $ws_meldungen[] = sprintf(ws_t('EINST.SICH_UEBERNOMMEN'), $ws_n);
            foreach ($ws_sh as $ws_x) { $ws_hinweise[] = $ws_x; }
            /* O9: der Listener-Satz nur bei MQTT und "nachgezogen" nur bei Erfolg;
             * I6: bringt die Sicherung den Wechsel auf UDP, wird abgeraeumt. */
            if (ws_cfg($ws_neu, 'BASE.UDP_ENABLE', '0') === '1') {
                if (!$ws_war_udp) {
                    ws_wechsel_auf_udp();
                }
            } else {
                /* Frage 3 (Durchgang 02.10.2026, Entscheidungen 5/8/16): traegt die
                 * Sicherung Personen aus oder benennt sie um, bekommen deren alte
                 * Themen einmal "-" retained - wie M2 beim Speichern. */
                $ws_ab = ws_ausgetragen_abraeumen($ws_alt_r, $ws_neu);
                foreach ($ws_ab['ok'] as $ws_x) { $ws_meldungen[] = $ws_x; }
                foreach ($ws_ab['nicht'] as $ws_x) { $ws_misslungen[] = $ws_x; }
                $ws_lpid = ws_listener_restart();
                if ($ws_lpid) {
                    $ws_meldungen[] = ws_t('EINST.SICH_DIENST_LAEUFT');
                } else {
                    $ws_misslungen[] = ws_t('EINST.SICH_DIENST_AUS');
                }
            }
            $ws_cfg = ws_config_read();
            $ws_fmt = ws_formtoken($ws_cfg);
        } else {
            $ws_fehler[] = ws_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
    $ws_tab = 'tab-settings';
}

/* ---------------- PRG (O1, Durchgang 02.10.2026) ----------------
 * Jeder POST endet hier mit 303 - auch einer, den der Wachposten abgewiesen
 * hat. Die beiden Downloads oben sind vorher mit exit ausgestiegen. */
if ($ws_war_post) {
    ws_umleiten($ws_tab);
}

/* ==================================================================
 * 6. Ab hier wird ausgegeben
 * ================================================================== */

$ws_users = ws_users($ws_cfg);
$ws_zeigen = array();
if (ws_fa('settings') && isset($ws_eingaben['zeilen']) && is_array($ws_eingaben['zeilen'])) {
    /* X-2: nach einer Beanstandung stehen die abgeschickten Zeilen da - auch
     * eine mit ungueltiger Adresse; sie ist markiert, nicht geloescht (O2). */
    foreach ($ws_eingaben['zeilen'] as $ws_ez) {
        if (!is_array($ws_ez) || !isset($ws_ez['i'], $ws_ez['name'], $ws_ez['macs'])) { continue; }
        $ws_zeigen[(int) $ws_ez['i']] = array('schluessel' => '', 'name' => (string) $ws_ez['name'],
                                              'macs' => (string) $ws_ez['macs'],
                                              'loeschen' => !empty($ws_ez['loeschen']));
    }
} else {
    foreach ($ws_users as $u) {
        $ws_zeigen[] = $u + array('loeschen' => false);
    }
}
// immer zwei leere Zeilen zum Anlegen anbieten
$ws_naechste = $ws_zeigen ? max(array_keys($ws_zeigen)) + 1 : 0;
$ws_zeigen[$ws_naechste] = array('schluessel' => '', 'name' => '', 'macs' => '', 'loeschen' => false);
$ws_zeigen[$ws_naechste + 1] = array('schluessel' => '', 'name' => '', 'macs' => '', 'loeschen' => false);

$ws_log_file = ws_log_file('wifi_scanner');
$ws_log_lines = ws_log_tail($ws_log_file);
$ws_listener_log = ws_log_file('mqtt_listener');

$ws_zustand = ws_zustand_lesen();
$ws_alter   = ws_zustand_alter($ws_zustand);
$ws_frisch  = ws_zustand_frisch($ws_cfg, $ws_zustand);
$ws_pid     = ws_listener_running();
$ws_cron    = ws_cron_current();
$ws_ein     = ws_cfg($ws_cfg, 'BASE.ENABLED', '0') === '1';
$ws_udp     = ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') === '1';
$ws_fassung = ws_mqtt_fassung();

// WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall ws_-Praefix.
$ws_frame = class_exists('LBWeb', false);
if ($ws_frame) {
    LBWeb::lbheader(ws_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}
/* O8: die Seite wird gepuffert; ws_formzeile_einsetzen() zaehlt am Ende die
 * Formulare im fertigen HTML und setzt die Zeile im Reiter Test ein. */
ob_start();
$ws_dir = ws_e($ws_p['plugin']);

/** Die Anwesenheit einer Person aus dem Abbild - -1, wenn unbekannt. */
function ws_person_zustand(array $z, $name, $frisch)
{
    if (!$frisch) { return -1; }
    foreach ($z['personen'] as $pz) {
        if (isset($pz['name']) && ws_topic_name($pz['name']) === ws_topic_name($name)) {
            return isset($pz['online']) ? (int) $pz['online'] : -1;
        }
    }
    return -1;
}
?>
<style>
/* ------------------------------------------------------------------
   Hausstandard. Uebernommen aus VORLAGE_hausstandard.css.html; eigene
   Ergaenzungen sind unten als solche gekennzeichnet.

   Die !important und die eigenen Hover-Farben sind Pflicht, kein
   Feinschliff: LoxBerry bringt jQuery Mobile mit, das JEDES <button> mit
   eigenem Hintergrund UND eigenen Hover-Regeln formatiert. Ohne sie steht
   weisse Schrift auf hellgrauem Grund und beim Ueberfahren weiss auf weiss.
   ------------------------------------------------------------------ */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3, .sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }

/* --- Reiter --- */
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
  padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; cursor: pointer; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }

/* --- Formularfelder --- */
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe, .sm-small { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap input[type=password], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 200px; }

/* Ein Auswahlfeld muss als solches erkennbar sein - ohne den selbst
   gezeichneten Pfeil sieht es aus wie ein Textfeld. Die Raute im SVG steht
   als %23, sonst endet die Datenadresse dort. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

/* --- Knoepfe --- */
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }

/* --- Kacheln, Legende, Zustandsfarben --- */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }

/* --- Tabellen, Hinweise, Text --- */
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Jede Tabelle mit Eingabefeldern kommt hier hinein: .sm-tbl hat width:100%
   und .sm-wrap eine feste Hoechstbreite - ohne den Ueberlauf ist die letzte
   Spalte auf einem schmalen Bildschirm nicht unbequem, sondern unerreichbar. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
/* Bis 3.1.11 stand hier .sm-warn, benutzt wurde im HTML .sm-warnung - die
   einzige Warnung des Plugins stand deshalb als nackter Fliesstext da. */
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em;
    padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
/* Eigene Ergaenzung (Durchgang 02.10.2026, X-2): ein beanstandetes Feld. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
</style>
<div class="sm-wrap">

<?php foreach ($ws_meldungen as $ws_m) { ?>
<div class="sm-alert sm-ok"><b><?php echo ws_e(ws_t('MELD.GESPEICHERT')); ?></b> <?= $ws_m ?></div>
<?php } ?>
<?php foreach ($ws_fehler as $ws_f) { ?>
<div class="sm-alert sm-err"><b><?php echo ws_e(ws_t('ALLG.FEHLER')); ?></b> <?= $ws_f ?></div>
<?php } ?>
<?php if ($ws_misslungen) { ?>
<div class="sm-alert sm-err"><b><?php echo ws_e(ws_t('MELD.NICHT_GELUNGEN')); ?></b><ul><li><?= implode('</li><li>', $ws_misslungen) ?></li></ul></div>
<?php } ?>
<?php foreach ($ws_hinweise as $ws_hw) { ?>
<div class="sm-warnung"><?= $ws_hw ?></div>
<?php } ?>

<div class="sm-alert sm-info">
<?php echo ws_e(ws_t('KOPF.SCAN')); ?>: <b><?= $ws_ein ? ws_e(ws_t('ALLG.EIN')) : ws_e(ws_t('ALLG.AUS')) ?></b><?php if ($ws_ein) { ?>, <?php printf(ws_t('KOPF.ALLE_MINUTEN'), '<b>' . ws_e(ws_cfg($ws_cfg, 'BASE.CRON', '3')) . '</b>'); ?><?php } ?>
· <?php echo ws_e(ws_t('KOPF.ZEITPLAN')); ?>: <?= $ws_cron !== '' ? ws_e($ws_cron) : '<b>' . ws_e(ws_t('KOPF.KEINE_VERKNUEPFUNG')) . '</b>' ?>
· <?php echo ws_e(ws_t('KOPF.LISTENER')); ?>: <?= $ws_pid ? ws_e(ws_t('ALLG.LAEUFT')) : '<b>' . ws_e(ws_t('ALLG.LAEUFT_NICHT')) . '</b>' ?>
· <?php echo ws_e(ws_t('KOPF.WEG')); ?>: <b><?= $ws_udp ? 'UDP' : 'MQTT' ?></b>
· <?php echo ws_e(ws_t('KOPF.PERSONEN')); ?>: <b><?= count($ws_users) ?></b>
</div>

<?php
/* Der Listener wird nur bei MQTT gebraucht. Bis 3.1.11 stand "laeuft nicht"
 * auch dann als Auffaelligkeit da, wenn UDP gewaehlt war - also im richtigen
 * Zustand. */
if (!$ws_udp && !$ws_pid) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?php echo ws_t('KOPF.W_LISTENER'); ?></div>
<?php }
if (!$ws_udp && ws_mqtt_autostart() === false) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?php echo ws_t('KOPF.W_AUTOSTART'); ?></div>
<?php }
if ($ws_alter >= 0 && !$ws_frisch) { ?>
<div class="sm-warnung"><?php printf(ws_t('KOPF.W_ALT'), '<b>' . (int) round($ws_alter / 60) . '</b>'); ?></div>
<?php } ?>

<div class="sm-tabs">
	<a class="sm-tab<?= $ws_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= ws_e(ws_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $ws_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt"><?= ws_e(ws_t('REITER.MQTT')) ?></a>
	<a class="sm-tab<?= $ws_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= ws_e(ws_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $ws_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= ws_e(ws_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $ws_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= ws_e(ws_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $ws_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ws_e(ws_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ws_e(ws_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ws_e(ws_t('LEGENDE.AKTION')) ?></span>
</div>

<h2><?= ws_e(ws_t('EINST.H_ZUSTAND')) ?></h2>
<?php if (!$ws_users) { ?>
<div class="sm-hinweis"><?= ws_t('EINST.Z_KEINE') ?></div>
<?php } elseif ($ws_alter < 0) { ?>
<div class="sm-hinweis"><?= ws_t('EINST.Z_NIE') ?></div>
<?php } else { ?>
<div class="sm-kacheln">
<?php foreach ($ws_users as $u) {
    if ($u['name'] === '') { continue; }
    $z = ws_person_zustand($ws_zustand, $u['name'], $ws_frisch); ?>
  <div class="sm-kachel"><?= ws_e($u['name']) ?>
    <b><span class="<?= $z === 1 ? 'sm-an' : ($z === 0 ? 'sm-aus' : '') ?>"><?=
      $z === 1 ? ws_e(ws_t('EINST.Z_DA')) : ($z === 0 ? ws_e(ws_t('EINST.Z_WEG')) : '—') ?></span></b>
  </div>
<?php } ?>
  <div class="sm-kachel"><?= ws_e(ws_t('EINST.Z_LETZTER')) ?>
    <b><?= $ws_alter >= 0 ? (int) round($ws_alter / 60) . ' ' . ws_e(ws_t('ALLG.MIN_KURZ')) : '—' ?></b>
  </div>
</div>
<div class="sm-hilfe"><?= ws_t('EINST.Z_ERKLAERUNG') ?>
<?php if ($ws_zustand['fehler'] !== '') { ?><br><b><?= ws_e(ws_t('ALLG.FEHLER')) ?></b> <?= ws_e($ws_zustand['fehler']) ?><?php } ?>
</div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= ws_e(ws_t('EINST.H_PERSONEN')) ?></h2>
<div class="sm-hilfe" style="margin-bottom:8px;">
<?php printf(ws_t('EINST.HINT_PERSONEN'), '<span class="sm-mono">aa:bb:cc:dd:ee:ff</span>'); ?>
</div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:26%;"><?= ws_e(ws_t('ALLG.NAME')) ?></th><th><?= ws_e(ws_t('EINST.SP_ADRESSEN')) ?></th><th style="width:22%;"><?= ws_e(ws_t('ALLG.MQTT_THEMA')) ?></th><th style="width:10%;"><?= ws_e(ws_t('EINST.SP_LOESCHEN')) ?></th></tr>
<?php foreach ($ws_zeigen as $ws_i => $u) { ?>
<tr>
    <td><input data-role="none" type="text" name="username[<?= (int) $ws_i ?>]" value="<?= ws_e($u['name']) ?>"<?= ws_fm('username[' . (int) $ws_i . ']') ?> placeholder="<?= ws_e(ws_t('EINST.PH_NAME')) ?>"><input data-role="none" type="hidden" name="uschluessel[<?= (int) $ws_i ?>]" value="<?= ws_e($u['schluessel']) ?>"></td>
    <td><input data-role="none" type="text" name="macs[<?= (int) $ws_i ?>]" value="<?= ws_e($u['macs']) ?>"<?= ws_fm('macs[' . (int) $ws_i . ']') ?> placeholder="aa:bb:cc:dd:ee:ff; 192.168.1.44"></td>
    <td class="sm-hilfe" style="padding-top:12px;"><?= $u['name'] !== '' ? 'wifi_ng/' . ws_e(ws_topic_name($u['name'])) : '—' ?></td>
    <td style="text-align:center;padding-top:12px;"><?php if ($u['name'] !== '') { ?><input data-role="none" type="checkbox" name="uloeschen[<?= (int) $ws_i ?>]" value="1" <?= !empty($u['loeschen']) ? 'checked' : '' ?>><?php } else { echo '—'; } ?></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= ws_t('EINST.HINT_LOESCHEN') ?></div>

<h2><?= ws_e(ws_t('EINST.H_ZEITPLAN')) ?></h2>
<div class="sm-row">
<div>
    <label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1" <?= ws_fh('settings', 'enabled', $ws_ein) ? 'checked' : '' ?>> <?= ws_e(ws_t('EINST.L_PERIODISCH')) ?></label>
    <div class="sm-hilfe"><?= ws_t('EINST.HINT_PERIODISCH') ?></div>
</div>
<div>
    <label><?= ws_e(ws_t('EINST.L_TAKT')) ?></label>
    <select data-role="none" name="cron"<?= ws_fm('cron') ?>>
    <?php foreach (array('1' => ws_t('TAKT.MIN01'), '3' => ws_t('TAKT.MIN03'), '5' => ws_t('TAKT.MIN05'),
                         '10' => ws_t('TAKT.MIN10'), '15' => ws_t('TAKT.MIN15'), '30' => ws_t('TAKT.MIN30'),
                         '60' => ws_t('TAKT.STUENDLICH')) as $v => $t) { ?>
        <option value="<?= ws_e($v) ?>" <?= ws_fw('settings', 'cron', ws_cfg($ws_cfg, 'BASE.CRON', '3')) === (string) $v ? 'selected' : '' ?>><?= ws_e($t) ?></option>
    <?php } ?>
    </select>
    <div class="sm-hilfe"><?= ws_t('EINST.HINT_TAKT') ?></div>
</div>
</div>

<h2><?= ws_e(ws_t('EINST.H_SUCHE')) ?></h2>
<div class="sm-row">
<div>
    <label class="sm-check"><input data-role="none" type="checkbox" name="fritz_enable" value="1" <?= ws_fh('settings', 'fritz_enable', ws_cfg($ws_cfg, 'BASE.FRITZBOX_ENABLE', '0') === '1') ? 'checked' : '' ?>> <?= ws_e(ws_t('EINST.L_FRITZ')) ?></label>
    <div class="sm-hilfe"><?= ws_t('EINST.HINT_FRITZ') ?></div>
</div>
<div>
    <label class="sm-check"><input data-role="none" type="checkbox" name="active_scan" value="1" <?= ws_fh('settings', 'active_scan', ws_cfg($ws_cfg, 'BASE.ACTIVE_SCAN', '0') === '1') ? 'checked' : '' ?>> <?= ws_e(ws_t('EINST.L_AKTIV')) ?></label>
    <div class="sm-hilfe"><?= ws_t('EINST.HINT_AKTIV') ?></div>
</div>
</div>
<div class="sm-hilfe" style="margin-top:6px;"><?= ws_t('EINST.HINT_MODUS0') ?></div>

<div class="sm-row">
<div>
    <label><?= ws_e(ws_t('EINST.L_FRITZ_ADR')) ?></label>
    <input data-role="none" type="text" name="fritzbox" value="<?= ws_e(ws_fw('settings', 'fritzbox', ws_cfg($ws_cfg, 'BASE.FRITZBOX', 'fritz.box'))) ?>"<?= ws_fm('fritzbox') ?>>
</div>
<div>
    <label><?= ws_e(ws_t('EINST.L_FRITZ_PORT')) ?></label>
    <input data-role="none" type="<?= ws_fm('fritzbox_port') !== '' ? 'text' : 'number' ?>" name="fritzbox_port" value="<?= ws_e(ws_fw('settings', 'fritzbox_port', ws_cfg($ws_cfg, 'BASE.FRITZBOX_PORT', '49443'))) ?>"<?= ws_fm('fritzbox_port') ?>>
</div>
</div>

<h3 class="sm-h3"><?= ws_e(ws_t('EINST.H_FRITZ_ANMELDUNG')) ?></h3>
<div class="sm-hilfe"><?= ws_t('EINST.HINT_FRITZ_ANMELDUNG') ?></div>
<div class="sm-row">
<div>
    <label><?= ws_e(ws_t('EINST.L_FRITZ_USER')) ?></label>
    <input data-role="none" type="text" name="fritzbox_user" value="<?= ws_e(ws_fw('settings', 'fritzbox_user', ws_cfg($ws_cfg, 'BASE.FRITZBOX_USER', ''))) ?>"<?= ws_fm('fritzbox_user') ?> autocomplete="off">
</div>
<div>
    <label><?= ws_e(ws_t('EINST.L_FRITZ_PASS')) ?></label>
    <input data-role="none" type="password" name="fritzbox_pass" value="" autocomplete="new-password"<?= ws_fm('fritzbox_pass') ?>
           placeholder="<?= ws_cfg($ws_cfg, 'BASE.FRITZBOX_PASS', '') !== '' ? ws_e(ws_t('EINST.PH_PASS_GESETZT')) : ws_e(ws_t('EINST.PH_PASS_LEER')) ?>">
    <label class="sm-check" style="margin-top:6px;"><input data-role="none" type="checkbox" name="fritz_pass_loeschen" value="1" <?= ws_fh('settings', 'fritz_pass_loeschen', false) ? 'checked' : '' ?>> <?= ws_e(ws_t('EINST.L_PASS_LOESCHEN')) ?></label>
</div>
</div>

<div class="sm-row">
<div>
    <label><?= ws_e(ws_t('EINST.L_BEFEHL')) ?></label>
    <select data-role="none" name="ping_cmd"<?= ws_fm('ping_cmd') ?>>
        <option value="0" <?= ws_fw('settings', 'ping_cmd', ws_cfg($ws_cfg, 'BASE.PING_CMD', '0')) === '0' ? 'selected' : '' ?>><?= ws_e(ws_t('EINST.OPT_ARPING')) ?></option>
        <option value="1" <?= ws_fw('settings', 'ping_cmd', ws_cfg($ws_cfg, 'BASE.PING_CMD', '0')) === '1' ? 'selected' : '' ?>><?= ws_e(ws_t('EINST.OPT_PING')) ?></option>
    </select>
</div>
<div>
    <label class="sm-check" style="margin-top:34px;"><input data-role="none" type="checkbox" name="use_cache" value="1" <?= ws_fh('settings', 'use_cache', ws_cfg($ws_cfg, 'BASE.USE_CACHE', '1') === '1') ? 'checked' : '' ?>> <?= ws_e(ws_t('EINST.L_CACHE')) ?></label>
    <div class="sm-hilfe"><?= ws_t('EINST.HINT_CACHE') ?></div>
</div>
</div>

<div class="sm-knopfreihe" style="margin-top:18px;">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?= ws_e(ws_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ws_e(ws_t('EINST.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= ws_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ws_t('EINST.SICH_WARNUNG') ?></div>
<?php $ws_x3 = ws_sicherung_warnungen($ws_cfg); if ($ws_x3) { ?>
<div class="sm-warnung"><?php printf(ws_t('EINST.SICH_X3'), ws_e(implode(', ', $ws_x3))); ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ws_sichern" value="1"><?= ws_e(ws_t('EINST.K_SICHERN')) ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="ws_sicherung" accept=".json" style="max-width:320px;">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ws_zurueck" value="1"><?= ws_e(ws_t('EINST.K_ZURUECK')) ?></button>
  </form>
</div>

<h2><?= ws_e(ws_t('ALLG.H_VORLAGE')) ?></h2>
<div class="sm-hinweis"><?= ws_t('ALLG.H_VORLAGE_TEXT') ?></div>
<div class="sm-knopfreihe">
  <!-- Eigenes Formular: ein Download darf nicht am Speichern haengen, und
       HTML verbietet Formulare im Formular. Bis 2.5.1 lag das versteckte
       Feld "vorlage" im Einstellungsformular - der Browser warf das innere
       Formular weg, und JEDES Speichern lieferte zusaetzlich die
       Vorlagendatei als Download aus. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="1"><?= ws_e(ws_t('ALLG.K_VORLAGE')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT / Uebertragungsweg ================= -->
<div class="sm-seite<?= $ws_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ws_e(ws_t('LEGENDE.SPEICHERN')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?= ws_e(ws_t('EINST.H_WEG')) ?></h2>
<div class="sm-row">
<div>
    <label><?= ws_e(ws_t('EINST.L_UEBERTRAGUNG')) ?></label>
    <select data-role="none" name="out_way">
        <option value="mqtt" <?= ws_fw('mqtt', 'out_way', $ws_udp ? 'udp' : 'mqtt') !== 'udp' ? 'selected' : '' ?>><?= ws_e(ws_t('EINST.OPT_MQTT')) ?></option>
        <option value="udp" <?= ws_fw('mqtt', 'out_way', $ws_udp ? 'udp' : 'mqtt') === 'udp' ? 'selected' : '' ?>><?= ws_e(ws_t('EINST.OPT_UDP')) ?></option>
    </select>
    <div class="sm-hilfe"><?= ws_t('EINST.HINT_UEBERTRAGUNG') ?></div>
</div>
<div>
    <label><?= ws_e(ws_t('EINST.L_UDPPORT')) ?></label>
    <input data-role="none" type="<?= ws_fm('udpport') !== '' ? 'text' : 'number' ?>" name="udpport" value="<?= ws_e(ws_fw('mqtt', 'udpport', ws_cfg($ws_cfg, 'BASE.PORT', '7007'))) ?>"<?= ws_fm('udpport') ?>>
</div>
</div>

<h2><?= ws_e(ws_t('MQTTR.H_GATEWAY')) ?></h2>
<table class="sm-tbl">
<tr><th style="width:34%;"><?= ws_e(ws_t('ALLG.BEDEUTUNG')) ?></th><th><?= ws_e(ws_t('ALLG.WERTE')) ?></th></tr>
<tr><td><?= ws_e(ws_t('MQTTR.BROKER')) ?></td><td><?php $ws_broker = ws_mqtt_broker();
    echo $ws_broker !== '' ? '<span class="sm-mono">' . ws_e($ws_broker) . '</span>'
       : '<b>' . ws_e(ws_t('T.NICHT_GEFUNDEN_KURZ')) . '</b>'; ?></td></tr>
<tr><td><?= ws_e(ws_t('MQTTR.AUTOSTART')) ?></td><td><?php $ws_as = ws_mqtt_autostart();
    echo $ws_as === null ? ws_e(ws_t('ALLG.UNBEKANNT'))
       : ($ws_as ? '<span class="sm-an">' . ws_e(ws_t('ALLG.EIN')) . '</span>'
                 : '<span class="sm-aus">' . ws_e(ws_t('ALLG.AUS')) . '</span>'); ?></td></tr>
<tr><td><?= ws_e(ws_t('MQTTR.FASSUNG')) ?></td><td><?= $ws_fassung > 0 ? 'V' . (int) $ws_fassung : ws_e(ws_t('ALLG.UNBEKANNT')) ?></td></tr>
</table>
<div class="sm-hinweis"><?= ws_abo_text() ?></div>

<div class="sm-knopfreihe" style="margin-top:18px;">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ws_e(ws_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $ws_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ws_e(ws_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ws_e(ws_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= ws_e(ws_t('REITER.LOXONE')) ?></h2>
<div class="sm-hilfe"><?= ws_t('LOX.EINLEITUNG') ?></div>

<div class="sm-step"><b><?= ws_e(ws_t('LOX.S1_TITEL')) ?></b><br>
<?php printf(ws_t('LOX.S1_TEXT'), '<span class="sm-mono">MQTT Gateway</span>'); ?>
<?php $ws_broker = ws_mqtt_broker(); ?>
<?php if ($ws_broker !== '') { ?>
<?= ws_e(ws_t('LOX.S1_BROKER')) ?>: <span class="sm-mono"><?= ws_e($ws_broker) ?></span>.
<?php } else { ?>
<b><?= ws_e(ws_t('LOX.S1_KEIN_BROKER')) ?></b>
<?php } ?>
<br><?= ws_abo_text() ?>
</div>

<div class="sm-step"><b><?= ws_e(ws_t('LOX.S2_TITEL')) ?></b><br>
<?= ws_t('LOX.S2_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ws_e(ws_t('ALLG.THEMA')) ?></th><th><?= ws_e(ws_t('ALLG.BEDEUTUNG')) ?></th><th><?= ws_e(ws_t('ALLG.WERTE')) ?></th><th style="width:13%;"><?= ws_e(ws_t('ALLG.RETAIN')) ?></th></tr>
<?php /* Die vierte Spalte ist Hausstandard (Regeln/07): wer ein Thema anlegt,
         schreibt in die Namenstabelle, ob es zurueckbehalten wird. Bis 3.2.3
         fuehrte ws_themen() die Angabe mit, zeigte sie aber nicht - und
         trug sie fuer die vier Lebenszeichen-Themen falsch. */ ?>
<?php foreach (ws_themen($ws_cfg) as $ws_th) { ?>
<tr><td class="sm-mono"><?= ws_e($ws_th[0]) ?></td><td><?= ws_e($ws_th[1]) ?></td><td><?= ws_e($ws_th[2]) ?></td><td><?= $ws_th[3] ? ws_e(ws_t('ALLG.RETAIN_JA')) : '<span class="sm-aus">' . ws_e(ws_t('ALLG.RETAIN_NEIN')) . '</span>' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= ws_t('LOX.RETAIN_ERKLAERUNG') ?></div>
</div>

<div class="sm-step"><b><?= ws_e(ws_t('LOX.S3_TITEL')) ?></b><br>
<?= ws_t('LOX.S3_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ws_e(ws_t('ALLG.THEMA')) ?></th><th><?= ws_e(ws_t('LOX.SP_NUTZLAST')) ?></th><th><?= ws_e(ws_t('LOX.SP_WIRKUNG')) ?></th></tr>
<?php foreach (ws_befehle() as $ws_bf) { ?>
<tr><td class="sm-mono"><?= ws_e($ws_bf[0]) ?></td><td><?= ws_e($ws_bf[1]) ?></td><td><?= ws_e($ws_bf[2]) ?></td></tr>
<?php } ?>
</table>
</div>
<?php printf(ws_t('LOX.S3_FUSS'), '<span class="sm-mono">mqtt_listener.pl</span>'); ?>
</div>

<div class="sm-step"><b><?= ws_e(ws_t('LOX.S6_TITEL')) ?></b><br>
<?= ws_t('LOX.S6_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:44%;"><?= ws_e(ws_t('LOX.SP_ADRESSE')) ?></th><th><?= ws_e(ws_t('LOX.SP_WIRKUNG')) ?></th></tr>
<tr><td class="sm-mono">http://<?= ws_e(ws_host()) ?><?= ws_e(ws_endpunkt_pfad(array(), $ws_cfg)) ?></td><td><?= ws_e(ws_t('LOX.E_ZEILE')) ?></td></tr>
<tr><td class="sm-mono">http://<?= ws_e(ws_host()) ?><?= ws_e(ws_endpunkt_pfad(array('aktion' => 'scan'), $ws_cfg)) ?></td><td><?= ws_e(ws_t('LOX.E_SCAN')) ?></td></tr>
<tr><td class="sm-mono">http://<?= ws_e(ws_host()) ?><?= ws_e(ws_endpunkt_pfad(array('aktion' => 'enable', 'wert' => '1'), $ws_cfg)) ?></td><td><?= ws_e(ws_t('LOX.E_ENABLE')) ?></td></tr>
<tr><td class="sm-mono">http://<?= ws_e(ws_host()) ?><?= ws_e(ws_endpunkt_pfad(array('aktion' => 'interval', 'wert' => '5'), $ws_cfg)) ?></td><td><?= ws_e(ws_t('LOX.E_INTERVAL')) ?></td></tr>
<tr><td class="sm-mono">http://<?= ws_e(ws_host()) ?><?= ws_e(ws_endpunkt_pfad(array('aktion' => 'mode', 'wert' => '0'), $ws_cfg)) ?></td><td><?= ws_e(ws_t('LOX.E_MODE')) ?></td></tr>
<tr><td class="sm-mono">http://<?= ws_e(ws_host()) ?><?= ws_e(ws_endpunkt_pfad(array('selftest' => '1'), $ws_cfg)) ?></td><td><?= ws_e(ws_t('LOX.E_SELFTEST')) ?></td></tr>
</table>
</div>
<?= ws_t('LOX.E_SUCHTEXT_HINWEIS') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:30%;"><?= ws_e(ws_t('ALLG.NAME')) ?></th><th style="width:28%;"><?= ws_e(ws_t('LOX.SP_SUCHTEXT')) ?></th><th><?= ws_e(ws_t('ALLG.BEDEUTUNG')) ?></th></tr>
<?php foreach (array('OK' => 'LOX.F_OK', 'ALTER' => 'LOX.F_ALTER', 'ZAEHLER' => 'LOX.F_ZAEHLER',
                     'PERSONEN' => 'LOX.F_PERSONEN', 'DA' => 'LOX.F_DA', 'ENABLED' => 'LOX.F_ENABLED',
                     'MODE' => 'LOX.F_MODE', 'INTERVAL' => 'LOX.F_INTERVAL',
                     'LISTENER' => 'LOX.F_LISTENER', 'CRON' => 'LOX.F_CRON') as $ws_fn => $ws_fs) { ?>
<tr><td class="sm-mono"><?= ws_e($ws_fn) ?></td><td class="sm-mono"><?= ws_e(ws_check($ws_fn)) ?></td><td><?= ws_e(ws_t($ws_fs)) ?></td></tr>
<?php } ?>
<?php foreach ($ws_users as $u) { if ($u['name'] === '') { continue; } $ws_fn = 'P_' . ws_topic_name($u['name']); ?>
<tr><td class="sm-mono"><?= ws_e($ws_fn) ?></td><td class="sm-mono"><?= ws_e(ws_check($ws_fn)) ?></td><td><?= ws_e(ws_t('LOX.ANWESENHEIT') . ' ' . $u['name']) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<div class="sm-step"><b><?= ws_e(ws_t('LOX.S4_TITEL')) ?></b><br>
<?= ws_t('LOX.S4_TEXT') ?>
</div>

<div class="sm-step"><b><?= ws_e(ws_t('LOX.S5_TITEL')) ?></b><br>
<?php printf(ws_t('LOX.S5_TEXT'),
    '<span class="sm-mono">Name:0</span>', '<span class="sm-mono">Name:1</span>',
    '<span class="sm-mono">' . ws_e(ws_cfg($ws_cfg, 'BASE.PORT', '7007')) . '</span>',
    '<span class="sm-mono">Name:\v</span>'); ?>
</div>

<h2><?= ws_e(ws_t('LOX.H_MERKWORT')) ?></h2>
<div class="sm-warnung"><?= ws_t('LOX.MERKWORT_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="1"><?= ws_e(ws_t('ALLG.K_VORLAGE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= ws_e(ws_t('LOX.K_MERKWORT_NEU')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $ws_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ws_e(ws_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ws_e(ws_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ws_e(ws_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= ws_e(ws_t('REITER.TEST')) ?></h2>

<h3 class="sm-h3"><?= ws_e(ws_t('TEST.H_SELBST')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:46%;"><?= ws_e(ws_t('TEST.SP_FRAGE')) ?></th><th style="width:8%;"></th><th><?= ws_e(ws_t('TEST.SP_BEFUND')) ?></th></tr>
<?php
/* Die Selbstpruefung. Jede Zeile hat DREI Ausgaenge: ja, nein und "hier
 * konnte nichts gemessen werden". Ein Strich ist kein Haken - er zaehlt in
 * der Zusammenfassung unten getrennt. */
$ws_z = array();
$ws_add = function ($frage, $ok, $text) use (&$ws_z) {
    $ws_z[] = array('frage' => $frage, 'ok' => (int) $ok, 'text' => (string) $text);
};

// 1. Konfiguration vollstaendig?
$ws_vorg = ws_vorgaben();
$ws_fehlend = array();
foreach ($ws_vorg as $ws_k => $ws_v) {
    if (!array_key_exists($ws_k, $ws_cfg)) { $ws_fehlend[] = $ws_k; }
}
$ws_add(ws_t('TEST.P_KONFIG'), $ws_fehlend ? 0 : 1,
    $ws_fehlend ? sprintf(ws_t('TEST.P_KONFIG_FEHLT'), count($ws_vorg) - count($ws_fehlend),
                          count($ws_vorg), ws_e(implode(', ', $ws_fehlend)))
                : sprintf(ws_t('TEST.P_KONFIG_OK'), count($ws_vorg)));

// 2. Merkwort hinterlegt?
$ws_tok = ws_token($ws_cfg);
$ws_add(ws_t('TEST.P_TOKEN'), $ws_tok !== '' ? 1 : 0,
    $ws_tok !== '' ? ws_maskieren($ws_tok) : ws_t('TEST.P_TOKEN_FEHLT'));

// 3. Passen Reiterleiste, Flaechen und Positivliste zusammen?
//    Gemessen in der EIGENEN Datei, gegen die Liste als Argument - eine
//    zweite Wahrheit ueber die Reiternamen gibt es damit nicht.
$ws_probe = function (array $soll, $datei) {
    $s = (string) @file_get_contents($datei);
    if ($s === '') { return array(2, 'index.php ' . ws_t('TEST.P_NICHT_LESBAR')); }
    $flaechen = array();
    if (preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $s, $y)) {
        $flaechen = $y[1];
    }
    $leiste = array();
    if (preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $s, $y2)) {
        $leiste = array_values(array_unique($y2[1]));
    }
    $fehlt = array_values(array_diff($soll, $flaechen));
    if ($fehlt) { return array(0, ws_t('TEST.P_REITER_FLAECHE') . ' ' . implode(', ', $fehlt)); }
    $ueber = array_values(array_diff($flaechen, $soll));
    if ($ueber) { return array(0, ws_t('TEST.P_REITER_UEBER') . ' ' . implode(', ', $ueber)); }
    $fehlt2 = array_values(array_diff($soll, $leiste));
    if ($fehlt2) { return array(0, ws_t('TEST.P_REITER_LEISTE') . ' ' . implode(', ', $fehlt2)); }
    $ohne = array();
    foreach ($soll as $id) {
        if (!preg_match('/\$ws_tab === \x27' . preg_quote($id, '/') . '\x27\s*\?\s*\x27 sm-active\x27/', $s)) {
            $ohne[] = $id;
        }
    }
    if ($ohne) { return array(0, ws_t('TEST.P_REITER_SERVER') . ' ' . implode(', ', $ohne)); }
    return array(1, sprintf(ws_t('TEST.P_REITER_OK'), count($soll)));
};
list($ws_ro, $ws_rt) = $ws_probe($ws_reiter, __FILE__);
$ws_add(ws_t('TEST.P_REITER'), $ws_ro, $ws_rt);

// 4. Tragen alle Formulare das Merkmal?
//    O8 (Durchgang 02.10.2026): gezaehlt wird JE FORMULAR am gerenderten HTML,
//    nicht am Quelltext. Bis 3.2.9 zaehlte die Zeile das Suchmuster in dieser
//    Datei - und damit sich selbst mit: einem Formular das Merkmal entnommen,
//    stand weiter ein Haken da (Pruefer Oberflaeche Nr. 11). Das Ergebnis setzt
//    ws_formzeile_einsetzen() am Ende ein, wenn die Seite fertig ist.
$ws_add(ws_t('TEST.P_FORM'), 9, '');

// 5. Stimmt die Themenliste mit dem Sendecode ueberein?
//    Gezaehlt wird gegen die publish/retain-Zeilen der beiden Perl-Skripte.
$ws_send = array();
foreach (array('check.pl', 'mqtt_listener.pl') as $ws_pl) {
    $ws_q = (string) @file_get_contents($ws_p['bindir'] . '/' . $ws_pl);
    if ($ws_q === '') { continue; }
    if (preg_match_all('/retain\s*\(\s*"([^"]*wifi_ng[^"]*)"/', $ws_q, $ws_m2)) {
        foreach ($ws_m2[1] as $ws_tt) { $ws_send[] = $ws_tt; }
    }
    if (preg_match_all('/"(wifi_ng\/[A-Za-z0-9_\/-]+)"/', $ws_q, $ws_m3)) {
        foreach ($ws_m3[1] as $ws_tt) { $ws_send[] = $ws_tt; }
    }
}
$ws_send = array_values(array_unique($ws_send));
if (!$ws_send) {
    $ws_add(ws_t('TEST.P_THEMEN'), 2, ws_t('TEST.P_THEMEN_KEINE'));
} else {
    $ws_soll_st = array();
    foreach (ws_themen($ws_cfg) as $ws_th) {
        if (strpos($ws_th[0], '/status/') !== false) { $ws_soll_st[] = $ws_th[0]; }
    }
    $ws_gesendet_st = array();
    foreach ($ws_send as $ws_tt) {
        if (strpos($ws_tt, '/status/') !== false) { $ws_gesendet_st[] = $ws_tt; }
    }
    /* BEIDE Richtungen. Bis 3.2.3 stand hier nur die erste, und sie hat
     * genau das uebersehen, wofuer sie da ist: wifi_ng/status/zaehler wurde
     * gesendet und stand in keiner Themenliste. Eine Pruefung, die nur
     * fragt "wird jedes gelistete Thema gesendet", ist gruen, solange die
     * Liste zu kurz ist - und je kuerzer die Liste, desto gruener. */
    $ws_luecke = array_values(array_diff($ws_soll_st, $ws_gesendet_st));
    $ws_ungelistet = array_values(array_diff($ws_gesendet_st, $ws_soll_st));
    $ws_add(ws_t('TEST.P_THEMEN'), ($ws_luecke || $ws_ungelistet) ? 0 : 1,
        $ws_luecke ? sprintf(ws_t('TEST.P_THEMEN_LUECKE'), ws_e(implode(', ', $ws_luecke)))
        : ($ws_ungelistet ? sprintf(ws_t('TEST.P_THEMEN_UNGELISTET'), ws_e(implode(', ', $ws_ungelistet)))
                          : sprintf(ws_t('TEST.P_THEMEN_OK'), count($ws_soll_st))));

    /* Und eine eigene Zeile fuer die Retain-Frage: geht jedes
     * Lebenszeichen-Thema wirklich OHNE Retain hinaus? Gemessen am
     * Sendecode, nicht an der Absicht. */
    $ws_lz_retained = array();
    foreach (array('check.pl', 'mqtt_listener.pl') as $ws_pl) {
        $ws_q = (string) @file_get_contents($ws_p['bindir'] . '/' . $ws_pl);
        if ($ws_q === '') { continue; }
        if (preg_match_all('/->\s*retain\s*\(\s*"([^"]+)"/', $ws_q, $ws_mr)) {
            foreach ($ws_mr[1] as $ws_tt) {
                if (in_array($ws_tt, ws_lebenszeichen(), true)) { $ws_lz_retained[] = $ws_tt; }
            }
        }
    }
    $ws_lz_retained = array_values(array_unique($ws_lz_retained));
    $ws_add(ws_t('TEST.P_RETAIN'), $ws_lz_retained ? 0 : 1,
        $ws_lz_retained ? sprintf(ws_t('TEST.P_RETAIN_FALSCH'), ws_e(implode(', ', $ws_lz_retained)))
                        : sprintf(ws_t('TEST.P_RETAIN_OK'), count(ws_lebenszeichen())));
}

/* 6. Trifft jede Befehlserkennung die richtige Stelle?
 *
 * Gemessen wird die WIRKUNG, nicht die Schreibweise: gebaut wird eine
 * Antwortzeile aus allen Feldnamen, und dann wird nachgesehen, an welcher
 * Stelle Loxone den Suchtext jedes Feldes fände. Loxone nimmt die ERSTE
 * Fundstelle.
 *
 * Der Unterschied ist nicht theoretisch. Ohne Trennzeichen fände der
 * Suchtext für "OK" zuerst die Stelle in "STATUS_OK=", und der Eingang
 * trüge lautlos den falschen Wert. MIT dem Semikolon kollidiert selbst
 * "Tim" und "Kurt-Tim" nicht, weil vor dem Namen ein ";" stehen muss.
 *
 * Beanstandet wird deshalb nur, was HEUTE falsch trifft - nicht, was ohne
 * das Trennzeichen falsch träfe. Eine erste Fassung dieser Prüfzeile
 * verglich die Feldnamen statt der Suchtexte und meldete für "Tim" neben
 * "Kurt-Tim" einen Fehler, den es nicht gab.
 */
$ws_felder_n = array('OK', 'ALTER', 'ZAEHLER', 'PERSONEN', 'DA', 'ENABLED',
                     'MODE', 'INTERVAL', 'LISTENER', 'CRON');
foreach ($ws_users as $u) {
    if ($u['name'] !== '') { $ws_felder_n[] = 'P_' . ws_topic_name($u['name']); }
}
$ws_paare = array();
foreach ($ws_felder_n as $ws_fn) { $ws_paare[$ws_fn] = '0'; }
$ws_zeile_probe = ws_endpunkt_zeile('WIFI', $ws_paare);
$ws_kollision = array();
$ws_ohne_trenner = array();
$ws_pos = 0;
foreach ($ws_felder_n as $ws_i2 => $ws_fn) {
    $ws_st = ws_check($ws_fn);
    // Das Stück, nach dem Loxone wirklich sucht: alles zwischen \i und \i\v
    $ws_such = substr($ws_st, 2, strlen($ws_st) - 2 - 4);
    if (strpos($ws_such, ';') !== 0) {
        $ws_ohne_trenner[] = $ws_fn;
    }
    $ws_treffer = strpos($ws_zeile_probe, $ws_such);
    // An welcher Stelle steht das Feld wirklich?
    $ws_soll_pos = strpos($ws_zeile_probe, ';' . $ws_fn . '=');
    if ($ws_treffer === false || $ws_treffer !== $ws_soll_pos) {
        $ws_kollision[] = $ws_fn;
    }
}
$ws_add(ws_t('TEST.P_SUCHTEXT'), ($ws_kollision || $ws_ohne_trenner) ? 0 : 1,
    $ws_kollision ? sprintf(ws_t('TEST.P_SUCHTEXT_KOLL'), ws_e(implode('; ', $ws_kollision)))
    : ($ws_ohne_trenner ? sprintf(ws_t('TEST.P_SUCHTEXT_TRENNER'), ws_e(implode(', ', $ws_ohne_trenner)))
                        : sprintf(ws_t('TEST.P_SUCHTEXT_OK'), count($ws_felder_n))));

// 7. Ist die Vorlage wohlgeformt?
list($ws_vn, $ws_vi) = ws_vorlage();
$ws_alt_xml = libxml_use_internal_errors(true);
$ws_xml_ok = simplexml_load_string($ws_vi) !== false;
libxml_clear_errors();
libxml_use_internal_errors($ws_alt_xml);
$ws_add(ws_t('TEST.P_VORLAGE'), function_exists('simplexml_load_string') ? ($ws_xml_ok ? 1 : 0) : 2,
    function_exists('simplexml_load_string')
        ? ($ws_xml_ok ? sprintf(ws_t('TEST.P_VORLAGE_OK'), substr_count($ws_vi, '<VirtualInHttpCmd'))
                      : ws_t('TEST.P_VORLAGE_KAPUTT'))
        : ws_t('TEST.P_VORLAGE_KEIN_PARSER'));

// 8. Arbeitet der Dienst noch?
$ws_add(ws_t('TEST.P_LAUF'), $ws_alter < 0 ? 2 : ($ws_frisch ? 1 : 0),
    $ws_alter < 0 ? ws_t('TEST.P_LAUF_NIE') : sprintf(ws_t('TEST.P_LAUF_ALT'), (int) round($ws_alter / 60)));

// 9. Doppelt vergebene Adressen?
$ws_dopp = ws_doppelte_adressen($ws_users);
$ws_add(ws_t('TEST.P_DOPPELT'), $ws_dopp ? 0 : 1,
    $ws_dopp ? ws_e(implode('; ', array_keys($ws_dopp))) : ws_t('TEST.P_DOPPELT_KEINE'));

// 10. Werkzeuge - wo liegen sie, und passt die sudoers-Regel?
//     I7 (Durchgang 02.10.2026): check.pl nimmt das erste ausfuehrbare unter
//     /usr/sbin, /sbin, /usr/bin, /bin und ruft es ueber sudo auf. Die sudoers-Regel
//     des Plugins nennt nur /usr/sbin/<werkzeug>; liegt es anderswo, weist sudo den
//     Aufruf ab. Bis 3.2.9 wollte postinstall.sh dafuer einen Verweis in /usr/sbin
//     anlegen - als loxberry ohne Wirkung (Installer-Pruefer Nr. 7). Jetzt sagt
//     diese Zeile, wo die Werkzeuge liegen.
$ws_wteile = array();
$ws_wschlecht = 0;
foreach (array('arping', 'arp', 'arp-scan') as $ws_w) {
    $ws_wort = '';
    foreach (array('/usr/sbin', '/sbin', '/usr/bin', '/bin') as $ws_wd) {
        if (is_executable($ws_wd . '/' . $ws_w)) { $ws_wort = $ws_wd . '/' . $ws_w; break; }
    }
    if ($ws_wort === '') {
        $ws_wteile[] = sprintf(ws_t('TEST.P_WZ_FEHLT'), ws_e($ws_w));
        $ws_wschlecht++;
    } elseif ($ws_wort === '/usr/sbin/' . $ws_w) {
        $ws_wteile[] = sprintf(ws_t('TEST.P_WZ_PASST'), ws_e($ws_wort));
    } else {
        $ws_wteile[] = sprintf(ws_t('TEST.P_WZ_ANDERS'), ws_e($ws_wort), ws_e('/usr/sbin/' . $ws_w));
        $ws_wschlecht++;
    }
}
if (is_executable('/bin/ping')) {
    $ws_wteile[] = sprintf(ws_t('TEST.P_WZ_PING'), '/bin/ping');
} else {
    $ws_wteile[] = sprintf(ws_t('TEST.P_WZ_FEHLT'), 'ping');
    $ws_wschlecht++;
}
$ws_add(ws_t('TEST.P_WERKZEUGE'), $ws_wschlecht ? 0 : 1, implode('<br>', $ws_wteile));

/* 11. Liegt eine Marke "Aktualisierung laeuft"?  (neu in 3.2.7)
 *
 * Diese Seite ist nur zu sehen, wenn die Marke NICHT gilt - sonst haette die
 * Sperre oben schon abgebrochen. Uebrig bleiben also zwei Faelle, und beide
 * sind einen Satz wert: gar keine Marke (der Normalfall) und eine, die
 * liegengeblieben ist, weil eine Installation abgebrochen wurde. Die zweite
 * ist kein Fehler, aber sie erklaert, warum der Listener zwischenzeitlich
 * nicht startete - und ohne diese Zeile sucht der Betreiber danach im
 * Dunkeln. CLAUDE.md, Abschnitt 6: zu jeder Regel gehoert das Werkzeug, das
 * sie findet.
 *
 * ZWEITE Quelle: der Ablageort wird angezeigt, damit eine liegengebliebene
 * Marke von Hand zu entfernen ist. */
$ws_marke_alter = ws_upgrade_alter();
$ws_add(ws_t('TEST.P_MARKE'), $ws_marke_alter < 0 ? 1 : 2,
    $ws_marke_alter < 0
        ? ws_t('TEST.P_MARKE_KEINE')
        : sprintf(ws_t('TEST.P_MARKE_ALT'), (int) round($ws_marke_alter / 60),
                  ws_e(ws_upgrade_marke())));

$ws_ja = 0; $ws_nein = 0; $ws_strich = 0;
foreach ($ws_z as $ws_zz) {
    if ($ws_zz['ok'] === 9) {
        // O8: Platzhalter - eingesetzt in ws_formzeile_einsetzen()
        echo '<tr><td>' . ws_e($ws_zz['frage']) . '</td><td style="text-align:center;"><!--WS_FORM_SYM--></td>'
           . '<td><!--WS_FORM_TEXT--></td></tr>' . "\n";
        continue;
    }
    if ($ws_zz['ok'] === 1) { $ws_ja++; } elseif ($ws_zz['ok'] === 0) { $ws_nein++; } else { $ws_strich++; }
    $ws_sym = $ws_zz['ok'] === 1 ? '<span class="sm-an">✓</span>'
            : ($ws_zz['ok'] === 0 ? '<span class="sm-aus">✗</span>' : '–');
    echo '<tr><td>' . ws_e($ws_zz['frage']) . '</td><td style="text-align:center;">' . $ws_sym
       . '</td><td>' . $ws_zz['text'] . "</td></tr>\n";
}
?>
</table>
</div>
<div class="sm-hilfe"><!--WS_BILANZ--></div>

<h3 class="sm-h3"><?= ws_e(ws_t('TEST.H_ANSEHEN')) ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-lesen" href="ws_test.php?status" target="_blank"><?= ws_e(ws_t('TEST.K_STATUS')) ?></a>
<a data-role="none" class="sm-btn sm-b-lesen" href="ws_test.php?topics" target="_blank"><?= ws_e(ws_t('TEST.K_THEMEN')) ?></a>
</div>

<h3 class="sm-h3"><?= ws_e(ws_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-technik" href="ws_test.php?diag" target="_blank"><?= ws_e(ws_t('TEST.K_DIAG')) ?></a>
<a data-role="none" class="sm-btn sm-b-technik" href="ws_test.php?config" target="_blank"><?= ws_e(ws_t('TEST.K_CONFIG')) ?></a>
</div>

<h3 class="sm-h3"><?= ws_e(ws_t('TEST.H_AKTION')) ?></h3>
<div class="sm-hilfe"><?= ws_t('TEST.HINT_AKTION') ?></div>
<div class="sm-knopfreihe">
  <!-- POST, nicht <a href>. Ein Verweis loest auf Zuruf jeder fremden Seite
       aus, sobald der Bediener am LoxBerry angemeldet ist. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="scan"><?= ws_e(ws_t('TEST.K_SCAN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= ws_e($ws_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="restart"><?= ws_e(ws_t('TEST.K_RESTART')) ?></button>
  </form>
</div>
<div class="sm-hilfe" style="margin-top:14px;"><?= ws_t('TEST.ERKLAERUNG') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $ws_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= ws_e(ws_t('LOG.H_TITEL')) ?></h2>
<div class="sm-hilfe" style="margin-bottom:8px;">
<?= ws_t('LOG.HINT_OBEN') ?>
<?= ws_e(ws_t('ALLG.DATEI')) ?>: <span class="sm-mono"><?= $ws_log_file !== '' ? ws_e($ws_log_file) : ws_e(ws_t('LOG.KEINE_DATEI')) ?></span><br>
<?= ws_t('LOG.HINT_LOGLEVEL') ?>
<?php if ($ws_listener_log !== '') { ?><br><?php printf(ws_t('LOG.HINT_LISTENER'), '<span class="sm-mono">' . ws_e($ws_listener_log) . '</span>'); ?><?php } ?>
</div>
<?php if ($ws_log_lines) { ?>
<div class="sm-log"><?= ws_e(implode("\n", $ws_log_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= ws_e(ws_t('LOG.KEINE_EINTRAEGE')) ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	<?php /* Kein ws_e() um json_encode: das gehoert in ein HTML-ATTRIBUT, nicht
	         in einen script-Block - dort ergaebe es &quot;tab-log&quot; und
	         waere ein Syntaxfehler. $ws_tab kommt aus der Positivliste. */ ?>
	zeige(<?= json_encode($ws_tab) ?>);
})();
</script>
<?php
echo ws_formzeile_einsetzen((string) ob_get_clean(), $ws_ja, $ws_nein, $ws_strich);
if ($ws_frame) {
    LBWeb::lbfooter();
}

/**
 * O8: die Zeile "Tragen alle Formulare das Merkmal?" am FERTIGEN HTML - jedes
 * Formular muss ein Merkmal mit 64 Hexzeichen tragen. Das Suchmuster ist
 * zusammengesetzt, damit diese Datei es nicht selbst enthaelt (CLAUDE.md 6).
 */
function ws_formzeile_einsetzen($html, $ja, $nein, $strich)
{
    $feld = '/name="f' . 'mt" value="[0-9a-f]{64}"/';
    $treffer = array();
    preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $treffer);
    $formen = isset($treffer[0]) ? $treffer[0] : array();
    $ohne = 0;
    foreach ($formen as $f) {
        if (preg_match($feld, $f) !== 1) { $ohne++; }
    }
    if (!$formen) {
        $sym = '–';
        $text = ws_t('TEST.P_FORM_KEINE');
        $strich++;
    } elseif ($ohne) {
        $sym = '<span class="sm-aus">✗</span>';
        $text = sprintf(ws_t('TEST.P_FORM_OHNE'), $ohne, count($formen));
        $nein++;
    } else {
        $sym = '<span class="sm-an">✓</span>';
        $text = sprintf(ws_t('TEST.P_FORM_ZAHL'), count($formen));
        $ja++;
    }
    return str_replace(array('<!--WS_FORM_SYM-->', '<!--WS_FORM_TEXT-->', '<!--WS_BILANZ-->'),
                       array($sym, $text, sprintf(ws_t('TEST.P_BILANZ'), $ja, $nein, $strich)), $html);
}
