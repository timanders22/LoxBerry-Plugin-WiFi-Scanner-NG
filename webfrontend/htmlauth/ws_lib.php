<?php
/**
 * WifiScanner - gemeinsame Hilfsfunktionen fuer Oberflaeche und Testseite
 *
 * Die Konfiguration bleibt bewusst im Format von Config::Simple
 * (config/wifi_scanner.cfg), damit check.pl und mqtt_listener.pl
 * unveraendert weiterlesen koennen.
 *
 * Eigenes Variablen- und Funktionspraefix "ws_", weil LBWeb::lbheader()
 * SDK-Globale setzt und sonst Namen kollidieren.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

if (!function_exists('ws_e')) {
    function ws_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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
        // neben dem Plugin.
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
     * Datei unter webfrontend/htmlauth/plugins/<ordner>/, also ergab
     * basename(dirname(dirname(__DIR__))) den Wert "htmlauth" und
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
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'htmlauth' || $dir === 'plugins') {
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
            'crondir' => $home . '/system/cron',
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'    => '',
            'plugin'  => $dir,
            'config'  => $base . '/config/wifi_scanner.cfg',
            'backup'  => $base . '/config/wifi_scanner.backup',
            'bindir'  => $base . '/bin',
            'logdir'  => sys_get_temp_dir(),
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
 */
function ws_config_read()
{
    $p = ws_paths();
    $out = array();
    $file = $p['config'];
    if (!is_file($file) && is_file($p['backup'])) {
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
        $sec = substr($full, 0, $pos);
        $key = substr($full, $pos + 1);
        $sections[$sec][$key] = $val;
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
     * Die Rechte werden auf der TEMPORAEREN Datei gesetzt, nicht danach:
     * sonst laege die Konfiguration einen Augenblick lang mit den Vorgaben
     * der umask da. */
    $tmp = $p['config'] . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $txt) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $p['config'])) {
        @unlink($tmp);
        return false;
    }
    @copy($p['config'], $p['backup']);   // Sicherung ausserhalb des Plugin-Ordners
    return true;
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
    $behalten = ($minutes === 60) ? 'cron.hourly'
        : 'cron.' . str_pad((string) (int) $minutes, 2, '0', STR_PAD_LEFT) . 'min';
    foreach (array('cron.01min', 'cron.03min', 'cron.05min', 'cron.10min', 'cron.15min', 'cron.30min', 'cron.hourly') as $d) {
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

/** Welche Cron-Verknuepfung liegt gerade? */
function ws_cron_current()
{
    $p = ws_paths();
    if ($p['crondir'] === '') {
        return '';
    }
    foreach (array('cron.01min', 'cron.03min', 'cron.05min', 'cron.10min', 'cron.15min', 'cron.30min', 'cron.hourly') as $d) {
        if (file_exists($p['crondir'] . '/' . $d . '/wifi_scanner')) {
            return $d;
        }
    }
    return '';
}

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
    $skript = ws_paths()['bindir'] . '/mqtt_listener.pl';
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

/** Die letzten N Zeilen einer Datei, neueste zuerst. */
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

/** Nutzerliste aus der Konfiguration. */
function ws_users($cfg)
{
    $n = (int) ws_cfg($cfg, 'BASE.USERS', '0');
    $out = array();
    for ($i = 1; $i <= $n; $i++) {
        $out[] = array(
            'name' => ws_cfg($cfg, 'USER' . $i . '.NAME', ''),
            'macs' => ws_cfg($cfg, 'USER' . $i . '.MACS', ''),
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
    return trim($t, '_');
}
