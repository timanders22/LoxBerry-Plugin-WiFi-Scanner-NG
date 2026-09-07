<?php
/**
 * WifiScanner - gemeinsame Hilfsfunktionen
 *
 * ORT: Diese Datei liegt seit 3.1.12 unter webfrontend/html/, nicht mehr unter
 * webfrontend/htmlauth/. Grund ist der neue Endpunkt fuer Loxone: installiert
 * liegen html/ und htmlauth/ in ZWEI getrennten Baeumen
 *
 *     <home>/webfrontend/html/plugins/<ordner>/
 *     <home>/webfrontend/htmlauth/plugins/<ordner>/
 *
 * Ein require aus dem einen in den anderen ueber '..' trifft nur das
 * ausgepackte Archiv; installiert ergibt es einen leeren HTTP 500. Die
 * Oberflaeche holt die Bibliothek deshalb ueber eine Kandidatenliste (siehe
 * ws_lib_pfade() weiter unten, benutzt von htmlauth/index.php).
 *
 * Der direkte Aufruf dieser Datei im Browser definiert nur Funktionen und gibt
 * nichts aus - sie enthaelt keine Anweisung ausserhalb einer Funktion.
 *
 * Die Konfiguration bleibt im Format von Config::Simple
 * (config/wifi_scanner.cfg), damit check.pl und mqtt_listener.pl
 * unveraendert weiterlesen koennen.
 *
 * Eigenes Variablen- und Funktionspraefix "ws_", weil LBWeb::lbheader()
 * SDK-Globale setzt und sonst Namen kollidieren.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

/* Die bedingten Definitionen stehen am ANFANG der Datei: PHP zieht eine
 * Funktionsdefinition, die in einem if-Block steht, nicht vor. Wer sie ans
 * Ende setzt, kann sie oben nicht aufrufen. */
if (!function_exists('ws_e')) {
    function ws_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* ==================================================================
 * Sprache
 *
 * Bis 2.5 gab es gar keine Uebersetzung: die Oberflaeche schrieb ihre
 * Texte unmittelbar auf Deutsch ins HTML. In templates/lang/ lagen zwar
 * help_de.ini und help_en.ini - die stammten aber aus der alten
 * Perl-CGI-Oberflaeche und fuellten <TMPL_VAR>-Platzhalter in
 * templates/help/help.html. Die PHP-Oberflaeche setzt keine
 * HTML::Template-Platzhalter ein; die beiden Dateien wurden also von
 * niemandem mehr gelesen.
 *
 * Seit 2.5.1 geht jeder sichtbare Text durch ws_t(). Englisch ist die
 * Rueckfallebene: fehlt ein Schluessel in der gewaehlten Sprache, wird
 * der englische genommen; fehlt auch der, kommt der Schluesselname selbst
 * heraus - Absicht, denn eine leere Seite verschweigt den Fehler, ein
 * sichtbares "EINST.L_TAKT" nicht.
 * ================================================================== */

function ws_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/** Text zu einem Schluessel 'ABSCHNITT.SCHLUESSEL'. */
function ws_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = ws_paths();
        // Installiert liegen die Sprachdateien unter
        // <home>/templates/plugins/<ordner>/lang/, im ausgepackten Archiv
        // neben dem Plugin. Die Datei liegt jetzt unter webfrontend/html/,
        // also sind es DREI Ebenen bis zum Plugin-Wurzelverzeichnis.
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . ws_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW gibt die Werte samt der Anfuehrungszeichen zurueck,
        // in die sie in der Datei stehen muessen.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}

/**
 * Die Kandidatenliste, ueber die ein Einstiegspunkt diese Datei findet.
 *
 * Der Aufrufer uebergibt sein eigenes __DIR__. Zurueck kommt eine Liste von
 * Pfaden in der Reihenfolge, in der sie zu versuchen sind - die Auskunft von
 * LoxBerry selbst zuerst.
 *
 * Diese Funktion steht hier, damit sie zusammen mit der Datei gepflegt wird,
 * die sie findet. Der Aufrufer, der sie noch nicht laden konnte, traegt
 * dieselbe Liste als kleinen Vorlauf bei sich - das ist die eine Doppelung,
 * die sich nicht aufloesen laesst.
 */
function ws_lib_pfade($eigenes_verzeichnis)
{
    $liste = array();
    $home = getenv('LBHOMEDIR');
    $pdir = getenv('LBPPLUGINDIR');
    if ($home && $pdir) {
        $liste[] = $home . '/webfrontend/html/plugins/' . $pdir . '/ws_lib.php';
    }
    // Installiert: <home>/webfrontend/htmlauth/plugins/<ordner>/ -> drei Ebenen
    $liste[] = dirname(dirname(dirname($eigenes_verzeichnis)))
             . '/html/plugins/' . basename($eigenes_verzeichnis) . '/ws_lib.php';
    // Ausgepacktes Archiv: webfrontend/htmlauth/ -> webfrontend/html/
    $liste[] = dirname($eigenes_verzeichnis) . '/html/ws_lib.php';
    // Danebenliegend (Endpunkt selbst)
    $liste[] = $eigenes_verzeichnis . '/ws_lib.php';
    return $liste;
}

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */
function ws_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home) {
        $home = lb_wurzel_ermitteln();
    }
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und hat Vorrang.
     *
     * Die frueheren Rueckfaelle trafen beide daneben: Installiert liegt diese
     * Datei unter webfrontend/html/plugins/<ordner>/, also ergab
     * basename(dirname(dirname(__DIR__))) den Wert "html" und
     * basename(dirname(__DIR__)) den Wert "plugins" - nie einen Plugin-Ordner.
     * Uebrig blieb immer der feste Name; eine Zweitinstallation
     * (wifi_ng_01) haette damit die Konfiguration der ersten benutzt.
     *
     * Jetzt wird der Ordner aus dem eigenen Ablageort genommen; der feste
     * Name greift nur, wo der ermittelte nachweislich keiner sein kann. */
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(__DIR__);
    }
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html'
        || $dir === 'htmlauth' || $dir === 'plugins' || $dir === 'webfrontend') {
        $dir = 'wifi_ng';
    }
    if ($home) {
        $p = array(
            'home'    => $home,
            'plugin'  => $dir,
            'config'  => $home . '/config/plugins/' . $dir . '/wifi_scanner.cfg',
            'backup'  => $home . '/config/plugins/' . $dir . '.wifi_scanner.backup',
            'bindir'  => $home . '/bin/plugins/' . $dir,
            'logdir'  => $home . '/log/plugins/' . $dir,
            'datadir' => $home . '/data/plugins/' . $dir,
            'crondir' => $home . '/system/cron',
        );
    } else {
        // Ausgepacktes Archiv: von webfrontend/html/ sind es zwei Ebenen.
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'    => '',
            'plugin'  => $dir,
            'config'  => $base . '/config/wifi_scanner.cfg',
            'backup'  => $base . '/config/wifi_scanner.backup',
            'bindir'  => $base . '/bin',
            'logdir'  => sys_get_temp_dir(),
            'datadir' => $base . '/data',
            'crondir' => '',
        );
    }
    return $p;
}

/**
 * Config::Simple-INI lesen.
 *
 * parse_ini_file ist hier NICHT brauchbar: In MACS trennt ein Semikolon die
 * Eintraege, und INI wertet das Semikolon als Kommentarzeichen - die Liste
 * waere ab dem ersten Semikolon abgeschnitten. Daher von Hand geparst.
 *
 * $erzeugen = false schaltet die Selbstheilung aus dem Zweitexemplar ab. Der
 * unangemeldete Endpunkt ruft die Funktion so auf: er darf lesen, aber nichts
 * anlegen und nichts zurueckschreiben - auch nichts Harmloses.
 */
function ws_config_read($erzeugen = true)
{
    $p = ws_paths();
    $out = array();
    $file = $p['config'];
    if (!is_file($file) && $erzeugen && is_file($p['backup'])) {
        @copy($p['backup'], $file);
    }
    if (!is_file($file)) {
        return $out;
    }
    $section = '';
    foreach (preg_split('/\R/', (string) @file_get_contents($file)) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === ';' || $t[0] === '#') {
            continue;
        }
        if ($t[0] === '[' && substr($t, -1) === ']') {
            $section = strtoupper(trim(substr($t, 1, -1)));
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            continue;
        }
        $key = strtoupper(trim(substr($t, 0, $pos)));
        $val = trim(substr($t, $pos + 1));
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        $out[$section . '.' . $key] = $val;
    }
    return $out;
}

/** Wert lesen, mit Vorgabe. */
function ws_cfg($cfg, $key, $default = '')
{
    return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
}

/**
 * Config::Simple-INI schreiben. Erzeugt genau das Format, das Config::Simple
 * auch selbst schreibt, damit die Perl-Seite unveraendert weiterliest.
 *
 * Jeder Wert wird VOR dem Schreiben gegen ws_wert_taugt() gehalten. Bis 3.1.11
 * ging hier alles durch, was der Aufrufer uebergab - ein Zeilenumbruch im Wert
 * erzeugte eine zusaetzliche INI-Zeile, eine eckige Klammer einen
 * zusaetzlichen Abschnitt. Gemessen am 26.08.2026 mit einer Sicherungsdatei,
 * die eine Zeile "[BOESE]" in den Wert von BASE.FRITZBOX legte: sie stand
 * danach so in der Konfiguration.
 */
function ws_config_write($cfg)
{
    $p = ws_paths();
    @mkdir(dirname($p['config']), 0775, true);

    $sections = array();
    foreach ($cfg as $full => $val) {
        $pos = strpos($full, '.');
        if ($pos === false) {
            continue;
        }
        if (!ws_wert_taugt($val)) {
            return false;   // fail closed - lieber gar nicht schreiben
        }
        $sec = substr($full, 0, $pos);
        $key = substr($full, $pos + 1);
        $sections[$sec][$key] = (string) $val;
    }
    // BASE zuerst, danach USER1..n in natuerlicher Reihenfolge
    uksort($sections, function ($a, $b) {
        if ($a === 'BASE') { return -1; }
        if ($b === 'BASE') { return 1; }
        return strnatcmp($a, $b);
    });

    $txt = "; Config::Simple 4.59\n; " . date('D M j H:i:s Y') . "\n\n";
    foreach ($sections as $sec => $keys) {
        $txt .= '[' . $sec . "]\n";
        foreach ($keys as $k => $v) {
            $txt .= $k . '=' . $v . "\n";
        }
        $txt .= "\n";
    }
    /* Erst daneben schreiben, dann umbenennen.
     *
     * Ein einfaches file_put_contents kuerzt die Datei und fuellt sie neu.
     * In genau dieses Fenster kann der Cron-Lauf von check.pl fallen oder
     * der MQTT-Listener - beide lesen dieselbe Datei. Sie bekaemen eine
     * halbe oder leere Konfiguration und arbeiteten mit Vorgabewerten
     * weiter. rename() ist im selben Dateisystem unteilbar.
     *
     * Die Rechte werden auf dem ANLEGEN gesetzt, nicht danach: sonst laege
     * die Konfiguration - und seit 3.1.12 steht ein Merkwort darin - einen
     * Augenblick lang mit den Vorgaben der umask da. */
    $tmp = $p['config'] . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@ftruncate($fh, 0) || @fwrite($fh, $txt) !== strlen($txt)) {
        @fclose($fh);
        @unlink($tmp);
        return false;
    }
    @fclose($fh);
    if (!@rename($tmp, $p['config'])) {
        @unlink($tmp);
        return false;
    }
    @copy($p['config'], $p['backup']);   // Sicherung ausserhalb des Plugin-Ordners
    @chmod($p['backup'], 0600);
    return true;
}

/**
 * Taugt dieser Wert fuer eine Config::Simple-Zeile?
 *
 * Die Datei ist zeilenorientiert. Ein Zeilenumbruch im Wert erzeugt eine
 * ZUSAETZLICHE Zeile, eine eckige Klammer am Zeilenanfang einen zusaetzlichen
 * Abschnitt. Geprueft wird deshalb der Wert, nicht der Schluessel - und ein
 * Feld oder Objekt aus einer JSON-Sicherung faellt hier ebenfalls durch,
 * bevor es unter PHP 8 als "Array" in die Datei geraet.
 */
function ws_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) {
        return false;
    }
    $s = (string) $v;
    if (strlen($s) > 4096) {
        return false;
    }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/* ==================================================================
 * Merkwort (Aktionstoken) und Formularmerkmal
 *
 * Neu in 3.1.12. Bis 3.1.11 stand im Quelltext und im Warntext am
 * Sicherungsknopf, die Sicherungsdatei trage "das Aktionstoken" - der
 * Baustein war woertlich aus einem anderen Plugin uebernommen, das Merkwort
 * aber nicht mitgekommen. In derselben Sprachdatei stand deshalb an einer
 * Stelle "Die Datei enthaelt Ihre Zugangsdaten" und an der anderen "Das
 * Plugin speichert keine Zugangsdaten". Recht hatte die zweite.
 *
 * Zwei verschiedene Dinge, die man nicht verwechseln darf:
 *
 *   BASE.TOKEN     das Merkwort der Anlage. Es steht in der Konfiguration,
 *                  schuetzt den Endpunkt unter webfrontend/html/ und GEHOERT
 *                  in die Sicherungsdatei - ohne es waeren nach einem Umzug
 *                  alle Felder richtig und der Miniserver kaeme trotzdem
 *                  nicht durch.
 *
 *   Formularmerkmal  wird aus BASE.TOKEN ABGELEITET, nie gespeichert, lebt
 *                  eine Sitzung lang und gehoert ausdruecklich NICHT in die
 *                  Sicherungsdatei.
 * ================================================================== */

/**
 * Ein neues Merkwort erzeugen.
 *
 * random_bytes ist die kryptografisch geeignete Quelle; faellt sie aus, wird
 * nicht stillschweigend auf rand() ausgewichen - ein vorhersagbares Merkwort
 * waere schlechter als gar keins.
 */
function ws_token_erzeugen()
{
    return bin2hex(random_bytes(12));
}

/** Das hinterlegte Merkwort, oder Leerstring. */
function ws_token(?array $cfg = null)
{
    if ($cfg === null) {
        $cfg = ws_config_read(false);
    }
    return trim((string) ws_cfg($cfg, 'BASE.TOKEN', ''));
}

/**
 * Merkmal gegen fremde Absender (Formulartoken).
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten bei einer Anfrage von aussen mit, und SameSite
 * greift bei HTTP-Basic nicht.
 *
 * Fail closed: ohne hinterlegtes Merkwort gibt es nichts zu vergleichen, und
 * hash_equals('', '') waere wahr.
 */
function ws_formtoken(?array $cfg = null)
{
    $grund = ws_token($cfg);
    if ($grund === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $grund);
}

function ws_formtoken_ok(?array $cfg = null)
{
    $soll = ws_formtoken($cfg);
    $ist = isset($_POST['fmt']) && is_string($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    return ($soll !== '' && hash_equals($soll, $ist));
}

/**
 * Der Pfad des Endpunkts, mit allen Parametern - fuer die Anzeige UND fuer
 * das Plugin selbst.
 *
 * Er entsteht an EINER Stelle. Bis 3.1.11 gab es den Endpunkt nicht; wer ihn
 * jetzt an sechs Stellen von Hand zusammensetzt (Adresstabelle, Knoepfe im
 * Reiter Test, Vorlage, Hilfe, beide Sprachdateien), bekommt genau die
 * Abweichungen, die der Anwender abtippt und die dann nicht funktionieren.
 */
function ws_endpunkt_pfad(array $werte = array(), ?array $cfg = null)
{
    $p = ws_paths();
    $teile = array('token=' . rawurlencode(ws_token($cfg)));
    foreach ($werte as $k => $v) {
        $teile[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return '/plugins/' . $p['plugin'] . '/index.php?' . implode('&', $teile);
}

/**
 * Der Suchtext fuer einen virtuellen Eingang in Loxone.
 *
 * Das SEMIKOLON gehoert dazu, und zwar aus einem gemessenen Grund: Loxone
 * nimmt die erste Fundstelle. Ohne Trennzeichen findet ein Suchtext "OK="
 * zuerst die Stelle in "STATUS_OK=", und der Eingang traegt lautlos den
 * falschen Wert. In der Antwortzeile steht vor JEDEM Feldnamen ein
 * Semikolon, auch vor dem ersten.
 *
 * Der Suchtext entsteht an EINER Stelle. Adresstabelle, Vorlage, Hilfe und
 * beide Sprachdateien rufen sie - wer ihn ausschreibt, hat ihn beim naechsten
 * Feld an vier Stellen zu aendern und vergisst die Sprachdateien.
 */
function ws_check($feld)
{
    return '\i;' . (string) $feld . '=\i\v';
}

/**
 * Die Antwortzeile des Endpunkts - EINE Quelle fuer Aufbau und Feldnamen.
 *
 * Rueckgabe: die fertige Zeile. Jeder Feldname wird mit einem Semikolon
 * eingeleitet, damit ws_check() darauf passt.
 */
function ws_endpunkt_zeile($kennung, array $felder)
{
    $z = (string) $kennung;
    foreach ($felder as $k => $v) {
        $z .= ';' . $k . '=' . $v;
    }
    return $z;
}

/**
 * Eine Adresse, die im Browser abgeschrieben werden soll.
 *
 * Eine Adresse fuer Programme (127.0.0.1) ist keine Adresse fuer Menschen.
 * Genommen wird der Rechnername, unter dem der Bediener die Seite gerade
 * offen hat - die Konfiguration wird dafuer nicht angefasst.
 */
function ws_host()
{
    $h = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    return $h !== '' ? $h : '<loxberry>';
}

/* ==================================================================
 * Zeitplan
 * ================================================================== */

/** Die Takte, die es gibt - EINE Quelle fuer Formular, Pruefung und Cron. */
function ws_takte()
{
    return array('1', '3', '5', '10', '15', '30', '60');
}

/** Cron-Verknuepfung neu setzen (gleiche Namen wie mqtt_listener.pl). */
function ws_cron_apply($enabled, $minutes)
{
    $p = ws_paths();
    if ($p['crondir'] === '') {
        return;
    }
    $pname = 'wifi_scanner';
    /* Die nicht gewaehlten Takte werden entfernt, der gewaehlte weiter unten
     * mit "ln -sfn" UEBERSCHRIEBEN statt geloescht und neu angelegt. Das
     * Zeitfenster zwischen unlink und symlink ist winzig, aber es gibt keinen
     * Grund, es offen zu lassen: faellt der System-Cron hinein, fehlt der
     * Verweis und der Lauf faellt aus. */
    $behalten = ((int) $minutes === 60) ? 'cron.hourly'
        : 'cron.' . str_pad((string) (int) $minutes, 2, '0', STR_PAD_LEFT) . 'min';
    foreach (ws_cron_ordner() as $d) {
        if ((string) $enabled === '1' && $d === $behalten) {
            continue;   // wird gleich ueberschrieben, nicht erst geloescht
        }
        @unlink($p['crondir'] . '/' . $d . '/' . $pname);
    }
    if ((string) $enabled !== '1') {
        return;
    }
    $target = $p['bindir'] . '/check.pl';
    $ziel = $p['crondir'] . '/' . $behalten . '/' . $pname;
    /* "ln -sfn" ersetzt einen bestehenden Verweis unteilbar (es legt daneben
     * an und benennt um). symlink() allein kann das nicht - es scheitert,
     * wenn das Ziel schon da ist, weshalb bisher erst geloescht werden
     * musste. Rueckfall auf den alten Weg, falls ln fehlt. */
    $aus = array(); $rc = 1;
    @exec('ln -sfn ' . escapeshellarg($target) . ' ' . escapeshellarg($ziel) . ' 2>/dev/null', $aus, $rc);
    if ($rc !== 0) {
        @unlink($ziel);
        @symlink($target, $ziel);
    }
}

/** Die Cron-Ordner, in denen eine Verknuepfung liegen kann. */
function ws_cron_ordner()
{
    return array('cron.01min', 'cron.03min', 'cron.05min', 'cron.10min',
                 'cron.15min', 'cron.30min', 'cron.hourly');
}

/** Welche Cron-Verknuepfung liegt gerade? */
function ws_cron_current()
{
    $p = ws_paths();
    if ($p['crondir'] === '') {
        return '';
    }
    foreach (ws_cron_ordner() as $d) {
        if (file_exists($p['crondir'] . '/' . $d . '/wifi_scanner')) {
            return $d;
        }
    }
    return '';
}

/* ==================================================================
 * Der MQTT-Listener
 * ================================================================== */

/**
 * Gehoert die PID unserem Listener?
 *
 * /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Verglichen wird
 * jedes der ersten beiden Argumente mit dem VOLLEN Pfad des Skripts - der
 * Listener wird immer so gestartet (Shebang oder "perl <pfad>").
 */
function ws_ist_listener($pid, $skript)
{
    $roh = @file_get_contents('/proc/' . (int) $pid . '/cmdline');
    if ($roh === false || $roh === '') {
        return false;
    }
    $args = explode("\0", $roh);
    return (isset($args[0]) && $args[0] === $skript)
        || (isset($args[1]) && $args[1] === $skript);
}

/**
 * PID des laufenden MQTT-Listeners, 0 wenn keiner laeuft.
 *
 * Bis 2.5 stand hier "pgrep -f mqtt_listener.pl". Das durchsucht die GANZE
 * Befehlszeile jedes Prozesses und trifft damit auch einen Editor, in dem
 * die Datei offen ist, oder ein zweites Exemplar des Plugins (LoxBerry
 * haengt bei einem Namenskonflikt 01, 02 ... an den Ordnernamen an). "ps -C"
 * und "killall" waeren keine Alternative: die vergleichen den comm-Namen,
 * der bei einem Skript mit Shebang "perl" lautet - die finden gar nichts.
 */
function ws_listener_running()
{
    $p = ws_paths();
    $skript = $p['bindir'] . '/mqtt_listener.pl';
    foreach ((array) @scandir('/proc') as $eintrag) {
        if (ctype_digit((string) $eintrag) && ws_ist_listener((int) $eintrag, $skript)) {
            return (int) $eintrag;
        }
    }
    return 0;
}

/** Listener beenden - gezielt ueber die PID, nicht ueber pkill -f. */
function ws_listener_stop()
{
    $pid = ws_listener_running();
    if (!$pid) {
        return 0;
    }
    @exec('kill ' . (int) $pid . ' 2>/dev/null');
    for ($i = 0; $i < 10 && ws_listener_running() === $pid; $i++) {
        usleep(300000);
    }
    if (ws_listener_running() === $pid) {
        @exec('kill -9 ' . (int) $pid . ' 2>/dev/null');
        usleep(300000);
    }
    return $pid;
}

/**
 * Listener neu starten. Erst anhalten, dann warten, dann starten.
 *
 * An drei Stellen wurde das bis 3.1.11 einzeln hingeschrieben, und an einer
 * davon (postinstall.sh) fehlte das Warten - zwei Listener beantworten jeden
 * Befehl doppelt. Jetzt gibt es eine Stelle.
 */
function ws_listener_restart()
{
    $p = ws_paths();
    $bin = $p['bindir'] . '/mqtt_listener.pl';
    if (!is_file($bin)) {
        return 0;
    }
    ws_listener_stop();
    @exec('nohup perl ' . escapeshellarg($bin) . ' > /dev/null 2>&1 &');
    usleep(800000);
    return ws_listener_running();
}

/* ==================================================================
 * MQTT-Gateway
 * ================================================================== */

/** MQTT-Zugangsdaten des Gateways (nur zur Anzeige, ohne Kennwort). */
function ws_mqtt_broker()
{
    $p = ws_paths();
    $f = $p['home'] . '/config/plugins/mqttgateway/mqtt.json';
    if (!is_file($f)) {
        return '';
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return '';
    }
    $host = isset($j['Main']['brokeraddress']) ? $j['Main']['brokeraddress'] : '';
    return (string) $host;
}

/**
 * Zustand und FASSUNG des LoxBerry-MQTT-Gateways.
 *
 * Die Fassung steht als Mqtt.Gatewayversion in general.json (ab Werk 1). Sie
 * entscheidet, was der Anwender eintragen muss:
 *
 *   V1  Das Abo (wifi_ng/#) wird von Hand eingetragen - ohne den Eintrag
 *       kommt am Miniserver nichts an. Das ist die haeufigste Fehlerursache.
 *   V2  Das Gateway erkennt die Themengruppe selbst; in den Subscriptions
 *       werden nur noch die gewuenschten Datenpunkte angehakt. Der
 *       LoxBerry-Kern schaltet die Eintragknoepfe dort ab
 *       (mqtt-gateway.cgi: FORM_DISABLE_BUTTONS = 1 if $gatewayversion == 2).
 *
 * Bis 3.1.11 stand der V1-Satz unbedingt da. Wer V2 faehrt, sucht danach
 * einen Eingabeplatz, den es nicht mehr gibt.
 *
 * Rueckgabe: null, wenn general.json nicht lesbar ist - sonst ein Feld mit
 * autostart (bool) und fassung (int, 0 = unbekannt). Bei 0 stehen BEIDE
 * Saetze da; einen von beiden zu behaupten waere fuer die Haelfte der
 * Anlagen falsch.
 *
 * Gelesen wird EINMAL - Autostart und Fassung stehen im selben Block.
 */
function ws_mqtt_gateway_info()
{
    static $info = false;
    if ($info !== false) {
        return $info;
    }
    $info = null;
    $p = ws_paths();
    if ($p['home'] === '') {
        return $info;
    }
    $g = $p['home'] . '/config/system/general.json';
    if (!is_file($g)) {
        return $info;
    }
    $j = @json_decode((string) @file_get_contents($g), true);
    if (!is_array($j) || !isset($j['Mqtt']) || !is_array($j['Mqtt'])) {
        return $info;
    }
    // Der Schluessel heisst Gatewayautostart, NICHT Autostart. Fuenf Plugins
    // haben den falschen Namen benutzt und die Warnung deshalb immer gezeigt.
    $auto = isset($j['Mqtt']['Gatewayautostart']) ? $j['Mqtt']['Gatewayautostart'] : '';
    $info = array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($j['Mqtt']['Gatewayversion']) ? (int) $j['Mqtt']['Gatewayversion'] : 0,
    );
    return $info;
}

/** Steht das Gateway auf Autostart? null = nicht feststellbar. */
function ws_mqtt_autostart()
{
    $i = ws_mqtt_gateway_info();
    return $i === null ? null : $i['autostart'];
}

/** Die gemessene Gateway-Fassung, 0 = nicht feststellbar. */
function ws_mqtt_fassung()
{
    $i = ws_mqtt_gateway_info();
    return $i === null ? 0 : (int) $i['fassung'];
}

/**
 * Der Abo-Hinweis in der Fassung, die zum Gateway passt.
 *
 * Er entsteht an EINER Stelle und wird an zweien gezeigt (Reiter MQTT und
 * Reiter Einbindung in Loxone). Wer nur eine davon verzweigen laesst,
 * bekommt unter V2 beide Saetze auf derselben Seite - genau das ist einem
 * anderen Plugin am 25.08.2026 passiert, und gefunden wurde es nicht durch
 * Lesen, sondern durch Messen.
 */
function ws_abo_text()
{
    $f = ws_mqtt_fassung();
    if ($f <= 0) {
        // Nicht auf 1 vorbelegen: bei unbekannter Fassung stehen BEIDE
        // Saetze da. Einen von beiden zu behaupten waere fuer die Haelfte
        // der Anlagen falsch.
        return ws_t('MQTTR.ABO_UNBEKANNT');
    }
    return ws_t($f >= 2 ? 'MQTTR.ABO_V2' : 'MQTTR.ABO_V1')
         . ' <span class="sm-mono">' . sprintf(ws_t('MQTTR.ABO_GEMESSEN'), (int) $f) . '</span>';
}

/* ==================================================================
 * Protokoll
 * ================================================================== */

/** Logdatei-Kandidaten (LoxBerry legt je nach Version unterschiedlich ab). */
function ws_log_file($name = 'wifi_scanner')
{
    $p = ws_paths();
    $cands = glob($p['logdir'] . '/' . $name . '*.log');
    if (!$cands) {
        return '';
    }
    usort($cands, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    return $cands[0];
}

/**
 * Die letzten $max Zeilen einer Datei, neueste zuerst.
 *
 * Bis 2.5.1 wurde die ganze Datei mit file_get_contents() eingelesen. Bei
 * Loglevel 7 mit vielen arping-Meldungen wird sie schnell gross - der
 * Hinweis auf den Speicher war berechtigt.
 *
 * Der vorgeschlagene Weg ueber exec("tail") ist aber der langsamste der
 * drei. An einer Datei an der Rotationsgrenze gemessen, PHP 7.4 und 8.1:
 *
 *   ganz einlesen            rund 0,3 ms   Spitze rund 1,4 MB
 *   exec("tail -n 300")      rund 1,9 ms   Spitze rund  75 kB
 *   rueckwaerts mit fseek    rund 0,05 ms  Spitze rund 125 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat - und er
 * braucht eine Shell, die man wieder absichern muesste.
 */
function ws_log_tail($file, $max = 300, $block = 8192)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $lines = array();
    while ($pos > 0 && count($lines) <= $max) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $lines = preg_split('/\R/', $puffer);
    }
    fclose($fp);
    $lines = array_values(array_filter(array_map('rtrim', $lines),
        function ($l) { return trim($l) !== ''; }));
    return array_slice(array_reverse($lines), 0, $max);
}

/* ==================================================================
 * Personen und Adressen
 * ================================================================== */

/** Nutzerliste aus der Konfiguration - mit dem Abschnittsnamen. */
function ws_users($cfg)
{
    $n = (int) ws_cfg($cfg, 'BASE.USERS', '0');
    $out = array();
    for ($i = 1; $i <= $n; $i++) {
        $out[] = array(
            'schluessel' => 'USER' . $i,
            'name'       => ws_cfg($cfg, 'USER' . $i . '.NAME', ''),
            'macs'       => ws_cfg($cfg, 'USER' . $i . '.MACS', ''),
        );
    }
    return $out;
}

/**
 * MQTT-Thema aus einem Namen bilden.
 * Leerzeichen und Sonderzeichen im Namen wuerden ein unbrauchbares Thema
 * ergeben - daher dieselbe Ersetzung wie in check.pl.
 */
function ws_topic_name($name)
{
    $t = (string) $name;
    $t = str_replace(array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'), array('ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'), $t);
    $t = preg_replace('/[^A-Za-z0-9_-]+/', '_', $t);
    return trim((string) $t, '_');
}

/**
 * Ist das eine brauchbare Geraeteadresse?
 *
 * Rueckgabe: 'mac', 'ip4', 'ip6', 'host' - oder '' fuer "nein".
 *
 * Bis 3.1.11 gab es diese Pruefung nicht: check.pl hielt alles, was nicht wie
 * eine MAC aussah, fuer eine IP-Adresse und setzte es unveraendert in
 * system("sudo arping ... $ip") ein. Ein "$(befehl)" im Adressfeld wurde von
 * der Shell ausgefuehrt. Geprueft wird jetzt an BEIDEN Enden - hier beim
 * Speichern und noch einmal in check.pl, weil die Konfigurationsdatei auch
 * von Hand oder aus einer Sicherung kommen kann.
 *
 * Die Pruefung weist ab, sie biegt nicht zurecht: Bindestriche in einer
 * MAC-Adresse werden nicht entfernt und nichts wird gross geschrieben. Eine
 * gueltige Kennung, die dabei still zerstoert wird, findet niemand wieder.
 */
function ws_adresse_art($a)
{
    $a = (string) $a;
    if ($a === '' || strlen($a) > 255) {
        return '';
    }
    if (preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $a) === 1) {
        return 'mac';
    }
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return 'ip4';
    }
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return 'ip6';
    }
    // Rechnername nach RFC 1123: Buchstaben, Ziffern, Bindestrich, Punkt -
    // kein Bindestrich am Anfang oder Ende eines Teils, kein reiner Zahlname.
    if (preg_match('/^(?![0-9.]+$)[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?'
                   . '(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $a) === 1) {
        return 'host';
    }
    return '';
}

/**
 * Eine Adressliste zerlegen und pruefen.
 *
 * Rueckgabe: array(gute Adressen[], abgewiesene[]). Die abgewiesenen werden
 * dem Bediener genannt - eine Zeile, die stillschweigend verschwindet,
 * schickt ihn auf die Suche nach einem Fehler, den er nicht sieht.
 */
function ws_adressen_zerlegen($liste)
{
    $roh = preg_split('/[;,\s]+/', (string) $liste);
    $gut = array();
    $schlecht = array();
    foreach ((array) $roh as $a) {
        $a = trim((string) $a);
        if ($a === '') {
            continue;
        }
        if (ws_adresse_art($a) === '') {
            $schlecht[] = $a;
            continue;
        }
        // MAC-Adressen klein vergleichen, aber in der Schreibweise des
        // Bedieners speichern - check.pl schreibt selbst klein.
        if (!in_array($a, $gut, true)) {
            $gut[] = $a;
        }
    }
    return array($gut, $schlecht);
}

/**
 * Dieselbe Adresse bei zwei Personen?
 *
 * Das ist ein realistischer Griff und waere sonst voellig still: die zweite
 * Person gilt dann immer als anwesend, sobald die erste zu Hause ist.
 * Gemeldet als Mangel, nicht als Sperre - gespeichert wird trotzdem.
 *
 * Rueckgabe: array(Adresse => array(Name, Name, ...)) nur fuer die
 * mehrfach vergebenen.
 */
function ws_doppelte_adressen(array $users)
{
    $wo = array();
    foreach ($users as $u) {
        if ((string) $u['name'] === '') {
            continue;
        }
        list($gut, $egal) = ws_adressen_zerlegen($u['macs']);
        foreach ($gut as $a) {
            $k = strtolower($a);
            if (!isset($wo[$k])) { $wo[$k] = array(); }
            if (!in_array($u['name'], $wo[$k], true)) {
                $wo[$k][] = $u['name'];
            }
        }
    }
    $doppelt = array();
    foreach ($wo as $a => $namen) {
        if (count($namen) > 1) {
            $doppelt[$a] = $namen;
        }
    }
    return $doppelt;
}

/* ==================================================================
 * Zustandsabbild
 *
 * check.pl legt nach jedem Lauf ab, was es gefunden hat. Bis 3.1.11 stand
 * das Ergebnis nur im Protokoll und im Broker; die Oberflaeche konnte die
 * Frage, die man tatsaechlich hat - wer ist gerade da, und woran wurde das
 * erkannt - gar nicht beantworten.
 *
 * Die Datei liegt unter data/, nicht unter config/: sie ist ein Zustand,
 * keine Einstellung, und sie muss kein Update ueberleben.
 * ================================================================== */

/** Pfad des Zustandsabbilds. */
function ws_zustand_datei()
{
    $p = ws_paths();
    return $p['datadir'] . '/zustand.json';
}

/**
 * Das Zustandsabbild lesen.
 *
 * Rueckgabe immer ein Feld mit denselben Schluesseln - fehlt die Datei oder
 * ist sie unbrauchbar, steht ts auf 0 und ok auf -1 ("nicht feststellbar").
 * Ein Strich ist kein Haken: "noch nie gelaufen" darf nicht wie "alles in
 * Ordnung" aussehen.
 */
function ws_zustand_lesen()
{
    $leer = array('ts' => 0, 'ok' => -1, 'weg' => '', 'fehler' => '', 'personen' => array());
    $f = ws_zustand_datei();
    if (!is_file($f)) {
        return $leer;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return $leer;
    }
    return array(
        'ts'       => isset($j['ts']) ? (int) $j['ts'] : 0,
        'ok'       => isset($j['ok']) ? (int) $j['ok'] : -1,
        'weg'      => isset($j['weg']) ? (string) $j['weg'] : '',
        'fehler'   => isset($j['fehler']) ? (string) $j['fehler'] : '',
        'personen' => isset($j['personen']) && is_array($j['personen']) ? $j['personen'] : array(),
    );
}

/**
 * Wie alt ist der letzte Lauf, in Sekunden? -1 = noch keiner.
 *
 * Gerechnet wird zur LESEZEIT, nicht beim Schreiben. Ein eingefrorenes
 * "Alter 0" kann einen toten Dienst nicht von einer frischen Messung
 * unterscheiden.
 */
function ws_zustand_alter(?array $z = null)
{
    if ($z === null) { $z = ws_zustand_lesen(); }
    return $z['ts'] > 0 ? max(0, time() - $z['ts']) : -1;
}

/**
 * Gilt der letzte Lauf als frisch?
 *
 * Frisch heisst: nicht aelter als das Dreifache des eingestellten Takts,
 * mindestens aber 10 Minuten. Der Zuschlag ist noetig, weil ein Lauf mit
 * zwanzig Geraeten und arping -c 20 laenger dauern kann als ein Takt.
 */
function ws_zustand_frisch(?array $cfg = null, ?array $z = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $alter = ws_zustand_alter($z);
    if ($alter < 0) {
        return false;
    }
    $takt = (int) ws_cfg($cfg, 'BASE.CRON', '3');
    if ($takt <= 0) { $takt = 3; }
    return $alter <= max(600, $takt * 60 * 3);
}

/* ==================================================================
 * Vorgaben, Sicherung, Vorlage
 * ================================================================== */

/**
 * Die Vorgaben des Abschnitts BASE - gemessen an der mitgelieferten
 * config/wifi_scanner.cfg und an den Stellen, die in index.php einen
 * Ersatzwert setzen.
 *
 * Neu in 3.1.12: TOKEN, FRITZBOX_USER, FRITZBOX_PASS. Alle drei stehen ab
 * Werk leer - eine neue Funktion aendert das Verhalten einer bestehenden
 * Anlage nicht von selbst. TOKEN wird beim ersten Oeffnen der Oberflaeche
 * einmal erzeugt, die beiden Fritz!Box-Felder bleiben leer, bis sie jemand
 * ausfuellt; leer heisst "wie bisher, ohne Anmeldung".
 */
function ws_vorgaben()
{
    return array(
        'BASE.PORT'            => '7007',
        'BASE.FRITZBOX_ENABLE' => '1',
        'BASE.FRITZBOX'        => 'fritz.box',
        'BASE.FRITZBOX_PORT'   => '49443',
        'BASE.FRITZBOX_USER'   => '',
        'BASE.FRITZBOX_PASS'   => '',
        'BASE.USERS'           => '0',
        'BASE.CRON'            => '3',
        'BASE.ENABLED'         => '0',
        'BASE.ACTIVE_SCAN'     => '1',
        'BASE.USE_CACHE'       => '1',
        'BASE.UDP_ENABLE'      => '1',
        'BASE.PING_CMD'        => '0',
        'BASE.TOKEN'           => '',
        'BASE.LOGMAX'          => '500',
    );
}

/** Welche Schluessel tragen ein Geheimnis? Fuer Anzeige und Protokoll. */
function ws_geheime_schluessel()
{
    return array('BASE.TOKEN', 'BASE.FRITZBOX_PASS');
}

/**
 * Einen Wert fuer die Anzeige maskieren.
 *
 * Die Form eines Geheimnisses darf beurteilt werden, der Wert nie angezeigt.
 * Deshalb die Laenge und die ersten drei Zeichen - das genuegt, um "steht
 * ueberhaupt etwas darin" und "ist es das erwartete" zu beantworten.
 */
function ws_maskieren($v)
{
    $v = (string) $v;
    if ($v === '') {
        return '';
    }
    return substr($v, 0, 3) . str_repeat('.', 3) . ' (' . strlen($v) . ')';
}

/**
 * Prueft einen EINZELNEN Konfigurationswert.
 *
 * Rueckgabe: '' wenn er taugt, sonst der Grund in Klartext (englisch, weil er
 * nur in Beanstandungen der Sicherungsdatei auftaucht und dort neben dem
 * uebersetzten Rahmensatz steht).
 *
 * Bis 3.1.11 pruefte ws_sicherung_lesen() nur die SCHLUESSEL. Eine Sicherung
 * mit BASE.CRON="abc" oder MACS="$(id)" wurde uebernommen und gemeldet:
 * "Gespeichert."
 */
function ws_wert_pruefen($schluessel, $wert)
{
    if (!ws_wert_taugt($wert)) {
        return 'unzulaessige Zeichen';
    }
    $w = (string) $wert;
    $schalter = array('BASE.FRITZBOX_ENABLE', 'BASE.ENABLED', 'BASE.ACTIVE_SCAN',
                      'BASE.USE_CACHE', 'BASE.UDP_ENABLE', 'BASE.PING_CMD');
    if (in_array($schluessel, $schalter, true)) {
        return preg_match('/^[01]$/', $w) === 1 ? '' : 'nur 0 oder 1';
    }
    if ($schluessel === 'BASE.CRON') {
        return in_array($w, ws_takte(), true) ? '' : 'nur ' . implode(', ', ws_takte());
    }
    if ($schluessel === 'BASE.PORT' || $schluessel === 'BASE.FRITZBOX_PORT') {
        return (preg_match('/^[0-9]{1,5}$/', $w) === 1 && (int) $w >= 1 && (int) $w <= 65535)
            ? '' : 'Port 1 bis 65535';
    }
    if ($schluessel === 'BASE.USERS') {
        return (preg_match('/^[0-9]{1,3}$/', $w) === 1 && (int) $w <= 200) ? '' : 'Anzahl 0 bis 200';
    }
    if ($schluessel === 'BASE.LOGMAX') {
        return (preg_match('/^[0-9]{1,6}$/', $w) === 1 && (int) $w >= 50) ? '' : 'mindestens 50 (kB)';
    }
    if ($schluessel === 'BASE.FRITZBOX') {
        return ws_adresse_art($w) !== '' ? '' : 'keine gueltige Adresse';
    }
    if ($schluessel === 'BASE.TOKEN') {
        return ($w === '' || preg_match('/^[0-9a-f]{16,64}$/', $w) === 1) ? '' : 'kein gueltiges Merkwort';
    }
    if ($schluessel === 'BASE.FRITZBOX_USER' || $schluessel === 'BASE.FRITZBOX_PASS') {
        return strlen($w) <= 128 ? '' : 'zu lang';
    }
    if (preg_match('/^USER[0-9]+[.]NAME$/', $schluessel) === 1) {
        return ($w !== '' && strlen($w) <= 64) ? '' : 'Name fehlt oder ist zu lang';
    }
    if (preg_match('/^USER[0-9]+[.]MACS$/', $schluessel) === 1) {
        list($gut, $schlecht) = ws_adressen_zerlegen($w);
        if ($schlecht) {
            return 'keine gueltige Adresse: ' . implode(' ', array_slice($schlecht, 0, 3));
        }
        return $gut ? '' : 'keine Adresse';
    }
    return '';
}

/**
 * Eine Sicherungsdatei einlesen - mit einer Besonderheit dieser Linie.
 *
 * Die Konfiguration hat NICHT nur feste Schluessel: neben [BASE] traegt sie
 * je erfasster Person einen Abschnitt [USER1], [USER2], ... mit NAME und
 * MACS. Eine feste Liste wuerde USER2.NAME als "fremd" abweisen - und damit
 * genau die Sicherung ablehnen, die man zurueckspielen will.
 *
 * Deshalb gilt ein Schluessel als bekannt, wenn er entweder in ws_vorgaben()
 * steht oder der Form USER<zahl>.NAME beziehungsweise USER<zahl>.MACS
 * entspricht. Alles andere ist eine Beanstandung.
 *
 * Neu in 3.1.12 wird auch jeder WERT geprueft, und eine Beanstandung heisst
 * nach wie vor: es wird GAR NICHTS uebernommen. Eine zur Haelfte uebernommene
 * Konfiguration ist schlimmer als die alte, und man sieht es ihr nicht an.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function ws_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ws_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = ws_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        /* Der lesbare Kopf der eigenen Sicherungsdatei (_hinweis, _stand)
         * wird UEBERGANGEN, nicht beanstandet.
         *
         * Beim ersten Messdurchgang am 26.08.2026 lehnte diese Funktion die
         * Datei ab, die ws_sicherung_inhalt() zwei Zeilen vorher selbst
         * erzeugt hatte - der Kopf stand nicht in der Liste der bekannten
         * Schluessel. Genau der Fall, vor dem der Absatz darueber warnt:
         * eine zu enge Liste weist die Sicherung ab, die man zurueckspielen
         * will. Gefunden hat es nicht das Lesen, sondern der Prueflauf mit
         * einer echten Sicherungsdatei. */
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        $ok = in_array($k, $bekannt, true)
              || preg_match('/^USER[0-9]+[.](NAME|MACS)$/', $k) === 1;
        if (!$ok) {
            $mangel[] = sprintf(ws_t('EINST.SICH_FREMD'), ws_e($k));
            continue;
        }
        $grund = ws_wert_pruefen($k, $w);
        if ($grund !== '') {
            $mangel[] = sprintf(ws_t('EINST.SICH_WERT'), ws_e($k), ws_e($grund));
            continue;
        }
        $neu[$k] = (string) $w;
        $anzahl++;
    }
    /* Die Personenabschnitte muessen zusammenpassen: BASE.USERS nennt die
     * Anzahl, und zu jeder Nummer bis dahin gehoert NAME und MACS. Fehlt
     * einer, faende check.pl eine Person ohne Adressen und meldete sie
     * jeden Lauf als abwesend. */
    if (!$mangel) {
        $n = (int) $neu['BASE.USERS'];
        for ($i = 1; $i <= $n; $i++) {
            if (!isset($neu['USER' . $i . '.NAME']) || !isset($neu['USER' . $i . '.MACS'])) {
                $mangel[] = sprintf(ws_t('EINST.SICH_USER_FEHLT'), $i);
            }
        }
        foreach (array_keys($neu) as $k) {
            if (preg_match('/^USER([0-9]+)[.]/', $k, $m) === 1 && (int) $m[1] > $n) {
                $mangel[] = sprintf(ws_t('EINST.SICH_USER_UEBER'), ws_e($k), $n);
            }
        }
    }
    if ($anzahl === 0) {
        $mangel[] = ws_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    foreach (array_keys(ws_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(ws_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/**
 * Der Inhalt der Sicherungsdatei.
 *
 * Vollstaendig aus den Vorgaben heraus: geschrieben werden ALLE Schluessel,
 * nicht nur die abweichenden. Sonst kaeme ein fehlender Schluessel beim
 * Zurueckspielen aus der Vorgabe, und das ist falsch, wenn der Anwender ihn
 * bewusst auf den Vorgabewert gesetzt hatte.
 *
 * Enthalten ist BASE.TOKEN - das Merkwort der Anlage. Ohne es stuenden nach
 * dem Zurueckspielen alle Felder richtig, und der Miniserver kaeme trotzdem
 * nicht an den Endpunkt; die Datei waere als Umzugshilfe wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * NICHT enthalten ist das Formularmerkmal - das lebt eine Sitzung lang und
 * hat in einer Datei nichts zu suchen.
 */
function ws_sicherung_inhalt()
{
    $cfg = ws_config_read(false);
    $aus = ws_vorgaben();
    foreach ($aus as $k => $v) {
        if (isset($cfg[$k])) {
            $aus[$k] = $cfg[$k];
        }
    }
    foreach ($cfg as $k => $v) {
        if (preg_match('/^USER[0-9]+[.](NAME|MACS)$/', (string) $k) === 1) {
            $aus[$k] = $v;
        }
    }
    $kopf = array(
        '_hinweis' => 'WiFi Scanner NG - Sicherung der Einstellungen. Enthaelt das '
                    . 'Merkwort der Anlage: wie ein Passwort behandeln.',
        '_stand'   => date('Y-m-d H:i:s'),
    );
    return array($kopf, $aus);
}

/** Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff (12.08.2026):
 *  VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus 604800 s,
 *  nur damit Loxone die richtig benannten Eingaenge anlegt - die Werte kommen
 *  vom MQTT-Gateway. Format wie Original-Export aus Loxone Config 17.1.
 *
 *  Seit 3.1.12 sind die Zustandsthemen mit dabei. Ohne sie musste man genau
 *  die Eingaenge von Hand anlegen, die beantworten, ob die Anwesenheit
 *  ueberhaupt noch gemessen wird.
 */
function ws_vorlage()
{
    $cfg = ws_config_read(false);
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="WifiScanner Anwesenheit" Comment="Erzeugt vom LoxBerry-Plugin WifiScanner-NG (' . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo wifi_ng/# noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach (ws_users($cfg) as $u) {
        if ($u['name'] === '') { continue; }
        $o .= ws_vorlage_zeile('wifi_ng_' . ws_topic_name($u['name']),
              'Anwesenheit ' . $u['name'] . ' (1 = da)', 0, 1);
    }
    /* Die Zustandsthemen. MinVal traegt hier ausdruecklich die Fehlwerte:
     * status_ok meldet -1 fuer "noch nie gelaufen", und mit MinVal="0"
     * zeigte Loxone dafuer eine 0 - also "Stoerung", was etwas anderes ist. */
    $o .= ws_vorlage_zeile('wifi_ng_status_mode',     'Abfrage-Modus: 0 = beides, 1 = nur Fritz!Box, 2 = nur Scan', 0, 2);
    $o .= ws_vorlage_zeile('wifi_ng_status_interval', 'Scan-Intervall in Minuten', 0, 60);
    $o .= ws_vorlage_zeile('wifi_ng_status_enabled',  'Periodischer Scan: 1 = ein', 0, 1);
    $o .= ws_vorlage_zeile('wifi_ng_status_ok',       'Letzter Lauf: 1 = gemessen, 0 = Stoerung, -1 = noch keiner', -1, 1);
    $o .= ws_vorlage_zeile('wifi_ng_status_ts',       'Zeitstempel des letzten Laufs (Unix-Sekunden)', 0, 2147483647);
    $o .= ws_vorlage_zeile('wifi_ng_status_listener', 'MQTT-Listener: 1 = verbunden, 0 = weg', 0, 1);
    $o .= '</VirtualInHttp>' . $crlf;
    return array('VI_wifiscanner.xml', $o);
}

/** Eine Befehlszeile der Vorlage. Entsteht an EINER Stelle. */
function ws_vorlage_zeile($titel, $bemerkung, $min, $max)
{
    $x = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    return "\t" . '<VirtualInHttpCmd Title="' . $x($titel) . '" '
         . 'Comment="' . $x($bemerkung) . '" Check=" " '
         . 'Signed="' . ($min < 0 ? 'true' : 'false') . '" Analog="true" '
         . 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" '
         . 'DefVal="0" MinVal="' . (int) $min . '" MaxVal="' . (int) $max . '" '
         . 'Unit="&lt;v&gt;" HintText=""/>' . "\r\n";
}

/**
 * Die Themen, die das Plugin veroeffentlicht - EINE Quelle.
 *
 * Oberflaeche, Testseite und die Pruefzeile "stimmt die Themenliste mit dem
 * Sendecode ueberein" lesen alle hier. Angeglichen wird die Anleitung an den
 * Sendecode, nicht umgekehrt.
 *
 * Rueckgabe je Eintrag: array(Thema, Sprachschluessel der Bedeutung, Werte,
 * retained ja/nein).
 */
function ws_themen(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $t = array();
    foreach (ws_users($cfg) as $u) {
        if ($u['name'] === '') { continue; }
        $t[] = array('wifi_ng/' . ws_topic_name($u['name']),
                     ws_t('LOX.ANWESENHEIT') . ' ' . $u['name'], '0 / 1', true);
    }
    $t[] = array('wifi_ng/status/mode',     ws_t('LOX.T_MODE'),     ws_t('LOX.V_MODE'), true);
    $t[] = array('wifi_ng/status/interval', ws_t('LOX.T_INTERVAL'), ws_t('ALLG.MINUTEN'), true);
    $t[] = array('wifi_ng/status/enabled',  ws_t('LOX.T_ENABLED'),  '0 / 1', true);
    $t[] = array('wifi_ng/status/ok',       ws_t('LOX.T_OK'),       '-1 / 0 / 1', true);
    $t[] = array('wifi_ng/status/ts',       ws_t('LOX.T_TS'),       ws_t('LOX.V_TS'), true);
    $t[] = array('wifi_ng/status/listener', ws_t('LOX.T_LISTENER'), '0 / 1', true);
    return $t;
}

/** Die Befehle, die der Listener entgegennimmt - EINE Quelle. */
function ws_befehle()
{
    return array(
        array('wifi_ng/cmd/scan',     ws_t('LOX.N_SCAN'),              ws_t('LOX.W_SCAN')),
        array('wifi_ng/cmd/mode',     '0/both, 1/fritzbox, 2/ping',    ws_t('LOX.W_MODE')),
        array('wifi_ng/cmd/interval', implode(', ', ws_takte()),       ws_t('LOX.W_INTERVAL')),
        array('wifi_ng/cmd/enable',   '0 ' . ws_t('ALLG.ODER') . ' 1', ws_t('LOX.W_ENABLE')),
    );
}
