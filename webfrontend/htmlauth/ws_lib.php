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

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */
function ws_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home && is_dir('/opt/loxberry')) {
        $home = '/opt/loxberry';
    }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(dirname(dirname(__DIR__)));
    }
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(basename(dirname(__DIR__)), 'wifiscanner') as $cand) {
            if (is_dir($home . '/config/plugins/' . $cand)) {
                $dir = $cand;
                break;
            }
        }
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
    $ok = @file_put_contents($p['config'], $txt) !== false;
    if ($ok) {
        @chmod($p['config'], 0600);
        @copy($p['config'], $p['backup']);   // Sicherung ausserhalb des Plugin-Ordners
    }
    return $ok;
}

/** Cron-Verknuepfung neu setzen (gleiche Namen wie mqtt_listener.pl). */
function ws_cron_apply($enabled, $minutes)
{
    $p = ws_paths();
    if ($p['crondir'] === '') {
        return;
    }
    $pname = 'wifi_scanner';
    foreach (array('cron.01min', 'cron.03min', 'cron.05min', 'cron.10min', 'cron.15min', 'cron.30min', 'cron.hourly') as $d) {
        @unlink($p['crondir'] . '/' . $d . '/' . $pname);
    }
    if ((string) $enabled !== '1') {
        return;
    }
    $target = $p['bindir'] . '/check.pl';
    $dir = ((int) $minutes === 60) ? 'cron.hourly' : 'cron.' . sprintf('%02d', (int) $minutes) . 'min';
    @symlink($target, $p['crondir'] . '/' . $dir . '/' . $pname);
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

/** Laeuft der MQTT-Listener? */
function ws_listener_running()
{
    $out = array();
    @exec('pgrep -f mqtt_listener.pl 2>/dev/null', $out);
    return count($out) > 0 ? (int) $out[0] : 0;
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
function ws_log_tail($file, $max = 300)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $lines = preg_split('/\R/', (string) @file_get_contents($file));
    $lines = array_values(array_filter($lines, function ($l) { return trim($l) !== ''; }));
    $lines = array_slice($lines, -$max);
    return array_reverse($lines);
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
