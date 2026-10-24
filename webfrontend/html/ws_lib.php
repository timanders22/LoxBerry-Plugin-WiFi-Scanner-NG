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
 * ausgepackte Archiv; installiert ergibt es einen leeren HTTP 500. Jeder
 * Einstiegspunkt sucht die Bibliothek deshalb selbst: htmlauth/index.php und
 * htmlauth/ws_test.php ueber eine Kandidatenliste mit der Auskunft von
 * LoxBerry ($LBHOMEDIR/$LBPPLUGINDIR) zuerst, html/index.php im eigenen
 * Verzeichnis. Bis 3.2.7 stand hier zusaetzlich eine Funktion
 * ws_lib_pfade() mit einer solchen Liste; aufgerufen hat sie niemand
 * (tote_helfer.py, und grep ueber den ganzen Plugin-Ordner).
 *
 * Der direkte Aufruf dieser Datei im Browser definiert nur Funktionen und gibt
 * nichts aus - sie enthaelt keine Anweisung ausserhalb einer Funktion.
 *
 * Die Konfiguration bleibt im Format von Config::Simple
 * (config/wifi_scanner.cfg), damit check.pl unveraendert weiterliest. Den
 * eigenen MQTT-Listener gibt es seit 3.2.15 nicht mehr (Gruppe 14) - Befehle
 * nimmt die gemeinsame Befehlsbruecke an (bin/befehlsbruecke.php), die ueber
 * ws_bb_tun()/ws_steuern() dieselbe Logik benutzt wie der Endpunkt.
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
 * config/plugins UND webfrontend UND config/system/general.json enthaelt.
 * Das trifft die uebliche Installation genauso wie eine an einem anderen
 * Ort - und es trifft auch den Fall, dass das Plugin noch als entpacktes
 * Archiv daliegt (dann findet es nichts und gibt einen Leerstring zurueck,
 * was der Aufrufer ohnehin abfangen muss).
 *
 * general.json gehoert seit 3.2.8 dazu (Regeln/06, "Eine Wurzelsuche ueber
 * config/plugins ... trifft auf einem Pruefrechner das Laufwerk selbst"):
 * ein LoxBerry hat sie immer, ein Rest aus Pruefstaenden nie. Gemessen am
 * 18.09.2026 in WSL (Pruefung-WiFi-Scanner-NG-3.2.8/Pruefstaende/
 * messe_welle2.sh, Fall H1): ein ausgepacktes Archiv unter einem Ordner mit
 * config/plugins und webfrontend, aufgerufen ohne LBHOMEDIR, schrieb seine
 * Konfiguration nach <fremder Ordner>/config/plugins/wifi_ng/.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
                && is_file($d . '/config/system/general.json')) {
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

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */
function ws_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    /* W16 (Gruppe 14): Anlagenpfade nur, wenn (a) LBHOMEDIR UND LBPPLUGINDIR ausdruecklich gesetzt sind
     * oder (b) diese Datei wirklich unter <wurzel>/webfrontend/html/plugins/<ordner>/ liegt (realpath-Vergleich,
     * Gleichstand BLE bl_paths). Bis 3.2.14 genuegte LBHOMEDIR allein - ein ausgepacktes Archiv steuerte dann die
     * Anlage (Pruefbericht, gemessen). Die Befehlsbruecke (Cron als loxberry, ohne LBPPLUGINDIR) bekommt ueber (b)
     * die Anlagenpfade. */
    $ws_env_home = (string) getenv('LBHOMEDIR');
    $ws_env_dir = (string) getenv('LBPPLUGINDIR');
    $home = '';
    if ($ws_env_home !== '' && $ws_env_dir !== '') {
        $home = $ws_env_home;
    } else {
        $ws_kand = $ws_env_home !== '' ? $ws_env_home : lb_wurzel_ermitteln();
        $ws_soll = $ws_kand !== '' ? realpath($ws_kand . '/webfrontend/html/plugins/' . basename(__DIR__)) : false;
        if ($ws_soll !== false && realpath(__DIR__) === $ws_soll) {
            $home = $ws_kand;
        }
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
    $dir = ($home !== '' && $ws_env_dir !== '') ? $ws_env_dir : basename(__DIR__);
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

/* ==================================================================
 * Die Marke "Aktualisierung laeuft"
 * ================================================================== */

/**
 * Wo liegt die Marke?
 *
 * NEBEN dem Datenordner, nicht darin: purge_installation loescht
 * data/plugins/<ordner>/ ohne jede Bedingung (Regeln/06). Der Punkt im Namen
 * ist der ganze Unterschied - "rm -rf .../<x>/" trifft den Nachbarn
 * "<x>.upgrade_laeuft" nicht.
 */
function ws_upgrade_marke()
{
    $p = ws_paths();
    return dirname($p['datadir']) . '/' . basename($p['datadir']) . '.upgrade_laeuft';
}

/**
 * Laeuft gerade eine Aktualisierung dieses Plugins?
 *
 * preupgrade.sh legt die Marke als Erstes an, postupgrade.sh - das letzte
 * Hakenskript dieser Linie - entfernt sie an seinem Ende.
 *
 * Aelter als eine Stunde, unlesbar oder kein Zeitpunkt: sie gilt NICHT. Eine
 * abgebrochene Installation darf die Oberflaeche nicht fuer immer stilllegen.
 * Ein paar Minuten "Zukunft" sind eine nachgestellte Uhr, keine Luege.
 *
 * WARUM ES SIE GIBT - am 18.09.2026 in WSL gemessen
 * (Pruefung-WiFi-Scanner-NG-3.2.7/Pruefstaende/messe_luecke.sh, Fall B1b):
 * in der Luecke zwischen purge_installation und postupgrade.sh steht in
 * config/plugins/<ordner>/wifi_scanner.cfg die mitgelieferte Vorgabe ohne
 * Merkwort. Wurde die Oberflaeche in dieser Zeit geoeffnet, erzeugte sie ein
 * neues Merkwort und schrieb - und ws_config_write() zog die Zweitschrift
 * config/plugins/<ordner>.wifi_scanner.backup mit. Gemessen wurde der
 * Uebergang von "TOKEN=ECHTESMERKWORT..., USERS=2, MACS=<erfunden>" auf
 * "TOKEN=5b09b7cf27a433e51ca1c2a9, USERS=0, MACS=" - in beiden Dateien.
 * postupgrade.sh holt danach nur die Konfiguration zurueck, nicht die
 * Zweitschrift; der Schaden ueberlebt das Upgrade (Fall C).
 */
function ws_upgrade_laeuft()
{
    $f = ws_upgrade_marke();
    if (!@is_file($f)) {
        return false;
    }
    $roh = @file_get_contents($f);
    if ($roh === false) {
        return false;
    }
    $roh = trim((string) $roh);
    if (!preg_match('/^[0-9]{1,12}$/', $roh)) {
        return false;
    }
    $alter = time() - (int) $roh;
    return ($alter > -300 && $alter < 3600);
}

/** Wie alt ist die Marke? -1 = keine oder unbrauchbar. Fuer den Reiter Test. */
function ws_upgrade_alter()
{
    $f = ws_upgrade_marke();
    if (!@is_file($f)) {
        return -1;
    }
    $roh = trim((string) @file_get_contents($f));
    if (!preg_match('/^[0-9]{1,12}$/', $roh)) {
        return -1;
    }
    return time() - (int) $roh;
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
    /* Nicht heilen, solange die Marke gilt: in der Luecke holt postinstall.sh
     * die Zweitschrift zurueck und postupgrade.sh die Sicherung. Eine
     * Selbstheilung dazwischen legte nur einen dritten Stand daneben. */
    if (!is_file($file) && $erzeugen && is_file($p['backup']) && !ws_upgrade_laeuft()) {
        ws_selbstheilung($p['backup'], $file);   // W17 (Gruppe 14): unteilbar, 0600, Ergebnis geprueft
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
    /* EIN Wachposten am Eingang, nicht an jedem Aufrufer.
     *
     * Solange die Marke gilt, wird NICHTS geschrieben - weder die
     * Konfiguration noch die Zweitschrift daneben. Die Oberflaeche faengt das
     * schon eine Ebene hoeher ab und sagt es dem Bediener; diese Zeile deckt
     * den unangemeldeten Endpunkt und jeden kuenftig ergaenzten Aufrufer mit.
     * Gemessen am 18.09.2026 (Fall B1b): ohne sie ueberschrieb ein einziger
     * Seitenaufruf in der Luecke die Zweitschrift mit den Vorgabewerten.
     *
     * Fail closed: im Zweifel nicht schreiben. Der Aufrufer meldet den
     * Fehlschlag, ein stiller Vorgabewert waere eine Annahme (CLAUDE.md 4). */
    if (ws_upgrade_laeuft()) {
        return false;
    }
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
    /* Die Zweitschrift ausserhalb des Plugin-Ordners - auf demselben Weg:
     * daneben schreiben, dann umbenennen.
     *
     * Bis 3.2.7 stand hier copy($p['config'], $p['backup']). copy() oeffnet
     * das Ziel mit O_TRUNC: die bisherige Zweitschrift ist sofort leer und
     * wird erst danach gefuellt. Gemessen am 18.09.2026 in WSL
     * (Pruefung-WiFi-Scanner-NG-3.2.8/Pruefstaende/messe_welle2.sh, Fall D6,
     * Schreiben scheitert wie auf einer vollen Karte): die Zweitschrift hatte
     * danach 0 Byte - weder der alte noch der neue Stand. Scheitert jetzt das
     * Schreiben, bleibt die bisherige unveraendert stehen.
     *
     * Die Konfiguration selbst ist in diesem Augenblick schon geschrieben;
     * ein Fehlschlag hier aendert den Rueckgabewert deshalb nicht. */
    $btmp = $p['backup'] . '.tmp.' . getmypid();
    $bh = @fopen($btmp, 'c');
    if ($bh !== false) {
        @chmod($btmp, 0600);
        $ok = @ftruncate($bh, 0) && @fwrite($bh, $txt) === strlen($txt) && @fflush($bh);
        @fclose($bh);
        if (!$ok || !@rename($btmp, $p['backup'])) {
            @unlink($btmp);
        }
    }
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

/** Cron-Verknuepfung neu setzen (Name wifi_scanner; bis 3.2.14 auch vom Listener gesetzt). */
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
    /* Unteilbar ersetzen OHNE Kindprozess (Gruppe 14, 3.4): Verweis daneben anlegen (<ziel>.tmp.<pid>), dann
     * rename() - genau das tat "ln -sfn" bis 3.2.14 als exec mit Schale. Die Befehlsbruecke ruft diese Funktion
     * im Dauerprozess; ein Kind erbte dort ihre Pfeifen. run-parts uebergeht den Namen mit Punkten. Rueckfall auf
     * loeschen und neu anlegen, falls rename scheitert. */
    $tmp = $ziel . '.tmp.' . getmypid();
    @unlink($tmp);
    if (!(@symlink($target, $tmp) && @rename($tmp, $ziel))) {
        @unlink($tmp);
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

/* Der Abschnitt "Der MQTT-Listener" (ws_ist_listener, ws_listener_alle, ws_listener_running, ws_listener_stop,
 * ws_listener_restart) ist mit 3.2.15 entfallen (Gruppe 14, E9) - siehe ws_listener_reste() fuer den Reiter Test. */

/* ==================================================================
 * MQTT-Gateway
 * ================================================================== */

/** MQTT-Zugangsdaten des Gateways (nur zur Anzeige, ohne Kennwort). */
function ws_mqtt_broker()
{
    /* W23 (Gruppe 14): seit LoxBerry 3 steht der Broker in config/system/general.json (Mqtt.Brokerhost, -port);
     * config/plugins/mqttgateway/mqtt.json gibt es dort nicht. Bis 3.2.14 stand deshalb auf jeder Anlage mit
     * LoxBerry 3/4 "Gefundener Broker: nicht gefunden" (Pruefbericht, gerendert). Rueckfall mqtt.json (LoxBerry 2). */
    $i = ws_mqtt_gateway_info();
    if ($i !== null) {
        return $i['broker'];
    }
    $p = ws_paths();
    $f = $p['home'] . '/config/plugins/mqttgateway/mqtt.json';
    if ($p['home'] === '' || !is_file($f)) {
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
    // W23 (Gruppe 14): der Broker aus demselben Block - EIN Lesen.
    $host = isset($j['Mqtt']['Brokerhost']) && is_scalar($j['Mqtt']['Brokerhost']) ? trim((string) $j['Mqtt']['Brokerhost']) : '';
    $port = isset($j['Mqtt']['Brokerport']) && is_numeric($j['Mqtt']['Brokerport']) ? (int) $j['Mqtt']['Brokerport'] : 1883;
    $info = array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($j['Mqtt']['Gatewayversion']) ? (int) $j['Mqtt']['Gatewayversion'] : 0,
        'broker'    => $host === '' ? '' : $host . ($port !== 1883 ? ':' . $port : ''),
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
 * Das Thema, das check.pl bis 3.2.9 aus diesem Namen gebildet hat (C3,
 * Durchgang 02.10.2026): dieselbe Ersetzung, aber auf den BYTES - die
 * Umlautliste griff dort nie. Aus "Jürgen" wurde wifi_ng/J_rgen. Gebraucht
 * beim Austragen einer Person (M2), damit auch dieses alte Thema "-" bekommt.
 */
function ws_topic_name_alt($name)
{
    $t = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $name);
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
    // O3 (Durchgang 02.10.2026): eine MAC-Adresse mit Bindestrichen
    // (Schreibweise von ipconfig /all) ist nie ein Rechnername. Bis 3.2.9 wurde
    // sie als "host" angenommen, gespeichert und nie gefunden - ohne Hinweis.
    if (ws_ist_mac_strich($a)) {
        return '';
    }
    // Rechnername nach RFC 1123: Buchstaben, Ziffern, Bindestrich, Punkt -
    // kein Bindestrich am Anfang oder Ende eines Teils, kein reiner Zahlname.
    if (preg_match('/^(?![0-9.]+$)[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?'
                   . '(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $a) === 1) {
        return 'host';
    }
    return '';
}

/** O3: eine MAC-Adresse mit Bindestrichen statt Doppelpunkten? */
function ws_ist_mac_strich($a)
{
    return preg_match('/^([0-9A-Fa-f]{2}-){5}[0-9A-Fa-f]{2}$/', (string) $a) === 1;
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
    // C4 (Durchgang 02.10.2026): zaehler gehoert dazu. Bis 3.2.9 fehlte der
    // Schluessel, und der Endpunkt meldete ZAEHLER=-1 bei laufendem Takt.
    $leer = array('ts' => 0, 'ok' => -1, 'zaehler' => -1, 'weg' => '', 'fehler' => '', 'personen' => array());
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
        'zaehler'  => isset($j['zaehler']) && is_numeric($j['zaehler']) ? (int) $j['zaehler'] : -1,
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
 * Frisch heisst: nicht aelter als das Dreifache des eingestellten Takts
 * (Entscheidung 4 des Hausherrn; Bauliste C5, Durchgang 02.10.2026). Bis
 * 3.2.9 galten mindestens 10 Minuten - bei einem Takt von 1 Minute wurde ein
 * Ausfall erst nach 10 statt nach 3 Minuten gemeldet (Pruefer code Nr. 6,
 * mqtt Nr. 12). Die Untergrenze ist entfallen; Hilfe und Feldtabelle nennen
 * die Schwelle.
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
    return $alter <= ws_frist_sekunden($cfg);
}

/** Die Schwelle fuer OK in Sekunden: das Dreifache des Takts (C5). */
function ws_frist_sekunden(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $takt = (int) ws_cfg($cfg, 'BASE.CRON', '3');
    if ($takt <= 0) { $takt = 3; }
    return $takt * 60 * 3;
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
        // E2 (Gruppe 14): eine Neuinstallation sendet ueber MQTT (bis 3.2.14: 1 = UDP). Ein Update behaelt den
        // Wert der Anlage; die Oberflaeche las den fehlenden Schluessel schon immer als 0.
        'BASE.UDP_ENABLE'      => '0',
        'BASE.PING_CMD'        => '0',
        'BASE.TOKEN'           => '',
        'BASE.LOGMAX'          => '500',
        // Gruppe 14 (2.7): alle ab Werk aus. ENDPUNKT_EIN beim Update nach dem Merkwort (ws_uebergang_wert).
        'BASE.ENDPUNKT_EIN'            => '0',
        'BASE.BEFEHLE_MQTT_EIN'        => '0',
        'BASE.BEFEHLE_EINGREIFEND_EIN' => '0',
        'BASE.HAUS_ABNEHMER'           => '0',
        'BASE.WECKPAKET'               => '0',
    );
}

/** O7 (Durchgang 02.10.2026): die Grenzen gelten beim Speichern UND beim
 *  Zurueckspielen - aus dieser einen Quelle. Bis 3.2.9 nahm die Oberflaeche
 *  einen Namen mit 69 Byte an, und die eigene Sicherung wurde danach
 *  abgewiesen (Pruefer Oberflaeche Nr. 10). */
function ws_name_max()
{
    return 64;
}

function ws_kennwort_max()
{
    return 128;
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
 * Fuer das Fritz!Box-Kennwort gilt seit dem Durchgang vom 02.10.2026 die
 * strengere Form aus ws_kennwort_anzeige() (O10).
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
 * O10 (Durchgang 02.10.2026): das Fritz!Box-Kennwort zeigt nur, DASS es
 * gesetzt ist, und wie lang es ist - kein einziges Zeichen daraus. Bis 3.2.9
 * standen die ersten drei Zeichen in ws_test.php?config (Pruefer Oberflaeche
 * Nr. 13). Regeln/05: ein Pruefknopf beurteilt die Form, nie den Wert.
 */
function ws_kennwort_anzeige($v)
{
    $v = (string) $v;
    if ($v === '') {
        return '';
    }
    $n = preg_match_all('/./us', $v);
    return sprintf(ws_t('T.GESETZT_ZEICHEN'), $n !== false && $n > 0 ? $n : strlen($v));
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
                      'BASE.USE_CACHE', 'BASE.UDP_ENABLE', 'BASE.PING_CMD',
                      'BASE.ENDPUNKT_EIN', 'BASE.BEFEHLE_MQTT_EIN', 'BASE.BEFEHLE_EINGREIFEND_EIN',
                      'BASE.HAUS_ABNEHMER', 'BASE.WECKPAKET');
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
        if (strlen($w) > ws_kennwort_max()) {
            return 'zu lang (hoechstens ' . ws_kennwort_max() . ' Zeichen)';
        }
        /* O5 (Durchgang 02.10.2026): Anfuehrungszeichen lassen sich im Format
         * von Config::Simple nicht so ablegen, dass beide Seiten (diese
         * Bibliothek und check.pl) sie unveraendert lesen - ein Paar am Rand
         * nimmt der Leser weg, und wie Config::Simple ein einzelnes behandelt,
         * ist nicht gemessen. Bis 3.2.9 wurden sie beim Speichern STILL
         * entfernt; die Anmeldung scheiterte dann mit 401 (Pruefer Oberflaeche
         * Nr. 6). Jetzt abgewiesen, mit Hinweis. */
        if (strpbrk($w, '"\'') !== false) {
            return 'Anfuehrungszeichen sind hier nicht moeglich';
        }
        /* E1 (Gruppe 14): Config::Simple liest mit parse_line('\s*,\s*', 0, ...) - ein Backslash ist dort
         * Fluchtzeichen, Leerraum am Komma faellt weg. check.pl laese "Ab\cd" als "Abcd" (401). Ein Komma
         * allein uebersteht join(',') in check.pl. */
        if (strpos($w, '\\') !== false || preg_match('/\s,|,\s/', $w) === 1) {
            return 'Backslash und Leerzeichen am Komma sind hier nicht moeglich (check.pl liest sie veraendert)';
        }
        return '';
    }
    if (preg_match('/^USER[0-9]+[.]NAME$/', $schluessel) === 1) {
        if ($w === '' || strlen($w) > ws_name_max()) {
            return 'Name fehlt oder ist zu lang (hoechstens ' . ws_name_max() . ' Byte)';
        }
        /* Frage 4 (Durchgang 02.10.2026, Entscheidung 19): Anfuehrungszeichen im
         * Namen werden beanstandet, nicht mehr still entfernt (bis 3.2.9 wurde aus
         * "O'Brien" beim Speichern still "OBrien"). */
        if (strpbrk($w, '"\'') !== false) {
            return 'Anfuehrungszeichen sind im Namen nicht moeglich';
        }
        // E10 (Gruppe 14, F1): wifi_ng/status/... und wifi_ng/befehl/... gehoeren dem Plugin und der Bruecke.
        if (ws_name_reserviert($w)) {
            return 'Thema darf nicht status oder befehl heissen';
        }
        // O4: aus dem Namen muss ein MQTT-Thema entstehen (nicht "???" oder "李雷").
        return ws_topic_name($w) !== '' ? '' : 'ergibt kein MQTT-Thema';
    }
    if (preg_match('/^USER[0-9]+[.]MACS$/', $schluessel) === 1) {
        list($gut, $schlecht) = ws_adressen_zerlegen($w);
        if ($schlecht && array_filter($schlecht, 'ws_ist_mac_strich')) {
            return 'MAC-Adresse mit Bindestrichen, bitte mit Doppelpunkten: '
                 . implode(' ', array_slice(array_values(array_filter($schlecht, 'ws_ist_mac_strich')), 0, 3));
        }
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
    $hinweise = array();
    $token_leer = false;
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
        /* O6 (Durchgang 02.10.2026, Klasse 10): ein LEERES Merkwort in der
         * Datei wird nicht uebernommen - das geltende bleibt. Bis 3.2.9 stand
         * danach TOKEN= leer, der naechste Seitenaufruf wuerfelte still ein
         * neues, und jede Adresse im Miniserver bekam 403 (Pruefer Oberflaeche
         * Nr. 9). */
        if ($k === 'BASE.TOKEN' && is_string($w) && trim($w) === '') {
            $token_leer = true;
            $hinweise[] = ws_t('EINST.SICH_TOKEN_LEER');
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
    if ($token_leer) {
        $neu['BASE.TOKEN'] = ws_token(ws_config_read(false));
    }
    /* O4 (Durchgang 02.10.2026): zwei Personen, aus denen dasselbe Thema
     * entsteht ("Anna B" und "Anna_B", "Jürgen" und "Juergen"), sind eine
     * Beanstandung - sonst schreiben beide retained auf ein Thema. */
    foreach (ws_themen_doppelt($neu) as $ws_dt => $ws_dn) {
        $mangel[] = sprintf(ws_t('EINST.SICH_THEMA_DOPPELT'), ws_e('wifi_ng/' . $ws_dt), ws_e(implode(', ', $ws_dn)));
    }
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
    $ergaenzt = array();
    foreach (array_keys(ws_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            /* Gruppe 14 (2.7): eine Sicherung aus einer aelteren Fassung kennt die neuen Schluessel nicht - fuer
             * sie gilt dieselbe Uebergangsregel wie im Update (ENDPUNKT_EIN nach Merkwort, UDP_ENABLE 1, die
             * uebrigen 0), mit Hinweis statt Beanstandung (Muster Heimkino hk_sicherung_lesen). Fehlt ein
             * ALTER Schluessel, bleibt es eine Beanstandung. */
            if (in_array($fk, ws_neue_schluessel(), true)) {
                $neu[$fk] = ws_uebergang_wert($fk, $neu);
                $ergaenzt[] = $fk . '=' . $neu[$fk];
                continue;
            }
            $fehlend[] = $fk;
        }
    }
    if ($ergaenzt) {
        $hinweise[] = sprintf(ws_t('EINST.SICH_ERGAENZT'), ws_e(implode(', ', $ergaenzt)));
    }
    if ($fehlend) {
        $mangel[] = sprintf(ws_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweise);
}

/**
 * O4: Themen, die aus zwei oder mehr Namen entstehen.
 * Rueckgabe: array(Thema => array(Name, Name, ...)), nur die mehrfachen.
 */
function ws_themen_doppelt(array $cfg)
{
    $wo = array();
    foreach ($cfg as $k => $v) {
        if (preg_match('/^USER[0-9]+[.]NAME$/', (string) $k) !== 1 || !is_scalar($v)) { continue; }
        $t = ws_topic_name((string) $v);
        if ($t === '') { continue; }
        $wo[$t][] = (string) $v;
    }
    $doppelt = array();
    foreach ($wo as $t => $namen) {
        if (count($namen) > 1) { $doppelt[$t] = $namen; }
    }
    return $doppelt;
}

/**
 * X-3 (Bauliste O7): welche Werte der geltenden Konfiguration bestuenden die
 * Pruefung beim Zurueckspielen NICHT? Nur die Schluesselnamen, nie die Werte.
 * "Einstellungen sichern" traegt sie als _warnung in die Datei, und der
 * Reiter zeigt sie gelb am Knopf.
 */
function ws_sicherung_warnungen(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $schlecht = array();
    foreach ($cfg as $k => $v) {
        $k = (string) $k;
        $bekannt = array_key_exists($k, ws_vorgaben()) || preg_match('/^USER[0-9]+[.](NAME|MACS)$/', $k) === 1;
        if (!$bekannt || ($k === 'BASE.TOKEN' && trim((string) $v) === '')) { continue; }
        if (ws_wert_pruefen($k, $v) !== '') { $schlecht[] = $k; }
    }
    foreach (ws_themen_doppelt($cfg) as $namen) {
        $schlecht[] = 'USER*.NAME (' . implode(', ', $namen) . ')';
    }
    return $schlecht;
}

/**
 * Den Abraeumer bin/mqtt_abraeumen.pl aufrufen (M2, I6; Durchgang 02.10.2026).
 * $art: --strich, --strich-wenn-da oder --leeren-alle. Rueckgabe:
 * array(Rueckgabewert, Ausgabezeilen) - 0 heisst "beim Broker bestaetigt".
 * Jedes Thema geht einzeln maskiert hinaus; der Abraeumer nimmt nur Themen
 * unter wifi_ng/ an.
 */
function ws_mqtt_aufraeumen($art, array $themen = array())
{
    $p = ws_paths();
    $helfer = $p['bindir'] . '/mqtt_abraeumen.pl';
    if (!in_array($art, array('--strich', '--strich-wenn-da', '--leeren-alle'), true)) {
        return array(3, array('unbekannte Art'));
    }
    if (!is_file($helfer)) {
        return array(9, array('fehlt: ' . $helfer));
    }
    $teile = array(is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 60 perl' : 'perl',
                   escapeshellarg($helfer), escapeshellarg($art));
    foreach ($themen as $t) {
        $teile[] = escapeshellarg((string) $t);
    }
    $aus = array();
    $rc = 9;
    @exec(implode(' ', $teile) . ' 2>&1', $aus, $rc);
    return array((int) $rc, $aus);
}

/* ---- C6 (Durchgang 02.10.2026): derselbe Befehl binnen 60 s ----
 * Der Endpunkt merkt sich den zuletzt ausgefuehrten schreibenden Befehl
 * (Art, Wert, Zeit) unter data/plugins/<ordner>/befehl_zuletzt.json. Nur ein
 * Aufruf mit gueltigem Merkwort schreibt diese Datei. */
function ws_befehl_datei()
{
    $p = ws_paths();
    return $p['datadir'] . '/befehl_zuletzt.json';
}

function ws_befehl_binnen($aktion, $wert, $sekunden = 60)
{
    $j = @json_decode((string) @file_get_contents(ws_befehl_datei()), true);
    if (!is_array($j) || !isset($j['aktion'], $j['wert'], $j['ts'])) {
        return false;
    }
    $alter = time() - (int) $j['ts'];
    return (string) $j['aktion'] === (string) $aktion && (string) $j['wert'] === (string) $wert
        && $alter >= 0 && $alter < $sekunden;
}

function ws_befehl_merken($aktion, $wert)
{
    $f = ws_befehl_datei();
    @mkdir(dirname($f), 0775, true);
    $tmp = $f . '.tmp.' . getmypid();
    $js = json_encode(array('aktion' => (string) $aktion, 'wert' => (string) $wert, 'ts' => time()));
    if ($js !== false && @file_put_contents($tmp, $js) === strlen($js)) {
        if (!@rename($tmp, $f)) { @unlink($tmp); }
    } else {
        @unlink($tmp);
    }
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
    /* X-3 (Bauliste O7): Werte, die das Zurueckspielen abwiese, werden genannt
     * (nur Namen, nie Werte). Die Datei bleibt vollstaendig. */
    $warn = ws_sicherung_warnungen($cfg);
    if ($warn) {
        $kopf['_warnung'] = 'Diese Werte wuerde das Zurueckspielen abweisen: ' . implode(', ', $warn);
    }
    return array($kopf, $aus);
}

/* ws_vorlage() (VirtualInHttp "WifiScanner Anwesenheit" an http://localhost, PollingTime 604800) ist mit 3.2.15
 * entfallen (E3, Regeln/07 R2_078): die Werte kommen ueber das MQTT-Gateway in einfache Virtuelle Eingaenge - die
 * Namen stehen in der Namenstabelle (ws_mqtt_namen()). W24 erledigt sich damit. */

/** O11: ein Kommentar der Vorlage hat hoechstens 40 Zeichen (Regeln/07). Ein
 *  laengerer - etwa mit einem langen Personennamen - wird gekuerzt und endet
 *  dann auf "…". */
function ws_kurz($s, $max = 40)
{
    $s = (string) $s;
    if (preg_match_all('/./us', $s) <= $max) {
        return $s;
    }
    preg_match('/^.{0,' . ($max - 1) . '}/us', $s, $m);
    return (isset($m[0]) ? $m[0] : '') . '…';
}

/**
 * Die Vorlage fuer einen VIRTUELLEN AUSGANG "WiFi-Scanner-NG Steuerung" (seit 3.2.14).
 *
 * Entwurf Welle 15 (Musterprojekt), Entscheidung 7: die vier Befehle, die der
 * Endpunkt seit 3.1.12 kann, als Importdatei - bis 3.2.13 standen nur die
 * Adressen im Reiter (Schritt "Ohne Gateway"), und Adresse, Befehle und
 * Merkwort mussten von Hand abgetippt werden. Gesteuert wird ueber HTTP an den
 * eigenen Endpunkt (Entscheidung 6), nicht ueber MQTT: der Endpunkt antwortet mit
 * Code und Grund und braucht weder Listener noch Gateway.
 *
 * Aufbau wie ein Original-Export aus Loxone Config 17.1 (templateType 3, Reihenfolge
 * der Felder wie VQ_DASHBOARD_STEUERUNG.xml). Sprachregel 13.10.2026 (Regeln/07):
 * Titel der Vorlage und der Befehle FEST deutsch, nur die Kommentare kommen aus der
 * Sprachdatei (hoechstens 40 Zeichen je Befehl). Die Pfade entstehen in
 * ws_endpunkt_pfad() - dieselbe Quelle wie die Adresstabelle im Reiter.
 *
 * WIFI_TAKT_SCHNELL: Ein = Takt 1 Minute, Aus = der beim Erzeugen eingestellte
 * Takt; ist er 1 (oder keiner der erlaubten), dann 3 (die Vorgabe) - sonst
 * schaltete "Aus" nichts zurueck. Die Datei traegt das Merkwort.
 */
function ws_vorlage_steuerung()
{
    $cfg = ws_config_read(false);
    $crlf = "\r\n";
    $x = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    /* Der Rechnername, unter dem der Bediener die Seite offen hat - so findet
     * ihn auch der Miniserver am ehesten; sonst der Name des Rechners. */
    $host = isset($_SERVER['HTTP_HOST'])
          ? preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) $_SERVER['HTTP_HOST']) : '';
    if ($host === '') {
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname());
    }
    if ($host === '') {
        $host = 'loxberry';
    }
    $takt = trim((string) ws_cfg($cfg, 'BASE.CRON', '3'));
    if ($takt === '1' || !in_array($takt, ws_takte(), true)) {
        $takt = '3';
    }
    $befehle = array(
        array('WIFI_SUCHE_JETZT', ws_t('VORLAGE.K_JETZT'), array('aktion' => 'scan'), null),
        array('WIFI_SUCHE_EIN', ws_t('VORLAGE.K_EIN'), array('aktion' => 'enable', 'wert' => '1'), null),
        array('WIFI_SUCHE_AUS', ws_t('VORLAGE.K_AUS'), array('aktion' => 'enable', 'wert' => '0'), null),
        array('WIFI_TAKT_SCHNELL', sprintf(ws_t('VORLAGE.K_SCHNELL'), $takt),
              array('aktion' => 'interval', 'wert' => '1'), array('aktion' => 'interval', 'wert' => $takt)),
    );
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="WiFi-Scanner-NG Steuerung" '
        . 'Comment="' . $x(sprintf(ws_t('VORLAGE.K_STEUERUNG'), date('d.m.Y'))) . '" '
        . 'Address="' . $x('http://' . $host) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($befehle as $b) {
        $o .= "\t" . '<VirtualOutCmd Title="' . $x($b[0]) . '" Comment="' . $x(ws_kurz($b[1])) . '" '
            . 'CmdOnMethod="GET" CmdOffMethod="GET" '
            . 'CmdOn="' . $x(ws_endpunkt_pfad($b[2], $cfg)) . '" CmdOnHTTP="" CmdOnPost="" '
            . 'CmdOff="' . ($b[3] === null ? '' : $x(ws_endpunkt_pfad($b[3], $cfg))) . '" CmdOffHTTP="" '
            . 'CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_wifiscanner_steuern.xml', $o);
}

/**
 * Die Themen, die das Plugin veroeffentlicht - EINE Quelle.
 *
 * Oberflaeche, Testseite und die Pruefzeile "stimmt die Themenliste mit dem
 * Sendecode ueberein" lesen alle hier. Angeglichen wird die Anleitung an den
 * Sendecode, nicht umgekehrt.
 *
 * Rueckgabe je Eintrag: array(Thema, Bedeutung, Werte, retained ja/nein).
 *
 * DAS VIERTE FELD IST NICHT SCHMUCK. Der Hausstandard seit 03.09.2026
 * (Regeln/07) unterscheidet drei Arten, und wer ein Thema anlegt, schreibt
 * in DIESE Tabelle, welche es ist:
 *
 *   Zustand              retained  - damit Loxone nach einem Neustart des
 *                                    Miniservers sofort den Stand hat
 *   Messwert mit Zeitbezug   nicht  - sonst erscheint ein alter Wert als
 *                                    aktuell
 *   Lebenszeichen            NIE    - retained zeigte es immer "lebt"
 *
 * Bis 3.2.3 stand hier ueberall true, und der Sendecode tat dasselbe. Am
 * 14.09.2026 lag "wifi_ng/status/listener 1" zurueckbehalten im Broker,
 * waehrend kein Cron lief, der es je auf 0 haette korrigieren koennen -
 * also genau die Falschaussage, gegen die das Lebenszeichen gebaut wurde.
 *
 * Und status/zaehler fehlte in dieser Liste ganz, obwohl check.pl es
 * sendet. Die Pruefzeile im Reiter Test hat das nicht gemerkt: sie sah nur
 * nach, ob jedes GELISTETE Thema auch gesendet wird, nicht ob jedes
 * GESENDETE gelistet ist. Sie prueft seit 3.2.4 beide Richtungen.
 */
function ws_themen(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $t = array();
    foreach (ws_users($cfg) as $u) {
        if ($u['name'] === '') { continue; }
        // Anwesenheit ist ein Zustand. Seit 3.2.15 -1 = keine Aussage (E5).
        $t[] = array('wifi_ng/' . ws_topic_name($u['name']),
                     ws_t('LOX.ANWESENHEIT') . ' ' . $u['name'], '-1 / 0 / 1', true);
    }
    // Zustaende - retained (seit 3.2.15 aus check.pl und mqtt_abraeumen.pl --status).
    $t[] = array('wifi_ng/status/mode',     ws_t('LOX.T_MODE'),     ws_t('LOX.V_MODE'), true);
    $t[] = array('wifi_ng/status/interval', ws_t('LOX.T_INTERVAL'), ws_t('ALLG.MINUTEN'), true);
    $t[] = array('wifi_ng/status/enabled',  ws_t('LOX.T_ENABLED'),  '0 / 1', true);
    // Das Lebenszeichen - nie retained. status/listener ist mit dem Listener entfallen (E9).
    $t[] = array('wifi_ng/status/ok',       ws_t('LOX.T_OK'),       '0 / 1', false);
    $t[] = array('wifi_ng/status/ts',       ws_t('LOX.T_TS'),       ws_t('LOX.V_TS'), false);
    $t[] = array('wifi_ng/status/zaehler',  ws_t('LOX.T_ZAEHLER'),  '0 ... 999', false);
    return $t;
}

/** Die Themen des Lebenszeichens - EINE Quelle fuer Sendecode und Anzeige. */
function ws_lebenszeichen()
{
    return array('wifi_ng/status/ok', 'wifi_ng/status/ts', 'wifi_ng/status/zaehler');
}

/* ==================================================================
 * Gruppe 14 (3.2.15): MQTT-Gateway, Befehlsbruecke, HTTP-Endpunkt hinter endpunkt_ein, V1/V3/V4
 *
 * Entwurf Musterprojekt/entwurf_gruppe14_ws.txt (2.4, 2.7, 3.3-3.10, 7), ENTSCHEIDUNGEN_GRUPPE14_WIFI.md E1-E20.
 * Der eigene MQTT-Listener (bin/mqtt_listener.pl) ist entfallen; seine Funktionen ws_ist_listener(),
 * ws_listener_alle(), ws_listener_running(), ws_listener_stop(), ws_listener_restart() und ws_befehle() mit ihm.
 * ================================================================== */

/** W30: der Hilfeverweis im Kopf - EINE Stelle (bis 3.2.14 zweimal die Startseite des Wikis). */
function ws_hilfe_url()
{
    return 'https://github.com/timanders22/LoxBerry-Plugin-WiFi-Scanner-NG';
}

/** Die mit 3.2.15 neuen Schluessel (2.7) - und UDP_ENABLE, dessen Vorgabe sich aendert. */
function ws_neue_schluessel()
{
    return array('BASE.ENDPUNKT_EIN', 'BASE.BEFEHLE_MQTT_EIN', 'BASE.BEFEHLE_EINGREIFEND_EIN',
                 'BASE.HAUS_ABNEHMER', 'BASE.WECKPAKET', 'BASE.UDP_ENABLE');
}

/**
 * Uebergangswert fuer einen Schluessel, der einer Konfiguration aus einer aelteren Fassung fehlt (Update,
 * zurueckgespielte Sicherung): ENDPUNKT_EIN = 1, wenn ein gueltiges Merkwort da ist (E6 - der Endpunkt bleibt fuer
 * bestehende Anlagen erreichbar, jetzt nur mit Merkwort), UDP_ENABLE = 1 (die alte Vorgabe), alle uebrigen 0.
 */
function ws_uebergang_wert($schluessel, array $cfg)
{
    if ($schluessel === 'BASE.ENDPUNKT_EIN') {
        return preg_match('/^[0-9a-f]{16,64}$/', ws_token($cfg)) === 1 ? '1' : '0';
    }
    return $schluessel === 'BASE.UDP_ENABLE' ? '1' : '0';
}

/**
 * E6: nimmt der HTTP-Endpunkt Aufrufe an? EINE Regel fuer Endpunkt, Oberflaeche und Reiter Test: steht
 * BASE.ENDPUNKT_EIN in der Konfiguration, gilt der Wert; fehlt er (Update von 3.2.14, bevor postupgrade.sh ihn
 * geschrieben hat), gilt ein gueltiges Merkwort als "an" - dieselbe Regel wie ws_wert_pruefen() fuer TOKEN.
 */
function ws_endpunkt_ein(array $cfg)
{
    if (array_key_exists('BASE.ENDPUNKT_EIN', $cfg)) {
        return (string) $cfg['BASE.ENDPUNKT_EIN'] === '1';
    }
    return preg_match('/^[0-9a-f]{16,64}$/', ws_token($cfg)) === 1;
}

/**
 * Die Modusregel (C8, E7): -1 keine Suche, 0 beides, 1 nur Fritz!Box, 2 nur Scan. Dieselbe Regel steht in Perl
 * zweimal (check.pl, mqtt_abraeumen.pl --status); ws_modusregel_pruefen() vergleicht.
 */
function ws_modus($fritz, $aktiv)
{
    $f = (string) $fritz === '1';
    $a = (string) $aktiv === '1';
    return ($f && $a) ? 0 : ($f ? 1 : ($a ? 2 : -1));
}

/** Wo liegt eine Datei unter bin/? Installiert bin/plugins/<ordner>/, im Archiv <wurzel>/bin/. '' = fehlt. */
function ws_bin_datei($name)
{
    $p = ws_paths();
    foreach (array($p['bindir'] . '/' . $name, dirname(dirname(__DIR__)) . '/bin/' . $name) as $k) {
        if (@is_file($k)) {
            return $k;
        }
    }
    return '';
}

/**
 * Ein Auftrag ueber bin/ws_auftrag.sh (3.4) - EINE Stelle fuer Endpunkt, Oberflaeche, Befehlsbruecke und V3.
 * proc_open mit Feld (ohne Schale), Ein-/Ausgaben auf /dev/null, warten nur, bis das Skript zurueckkommt
 * (Millisekunden): es startet check.pl --auftrag [--mode] bzw. mqtt_abraeumen.pl --status losgeloest und schliesst
 * vorher alle geerbten Deskriptoren. $art scan|status, $modus ''|both|fritzbox|ping (nur scan). Rueckgabe bool.
 */
function ws_auftrag_starten($art, $modus = '')
{
    $art = (string) $art;
    $modus = (string) $modus;
    if (!in_array($art, array('scan', 'status'), true) || !in_array($modus, array('', 'both', 'fritzbox', 'ping'), true)
        || ($art === 'status' && $modus !== '')) {
        return false;
    }
    $sh = ws_bin_datei('ws_auftrag.sh');
    if ($sh === '' || DIRECTORY_SEPARATOR === '\\' || !function_exists('proc_open')) {
        return false;
    }
    $kmd = array('/bin/bash', $sh, $art);
    if ($modus !== '') {
        $kmd[] = $modus;
    }
    $rohr = array();
    $pr = @proc_open($kmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'),
                                 2 => array('file', '/dev/null', 'w')), $rohr);
    if (!is_resource($pr)) {
        return false;
    }
    return proc_close($pr) === 0;
}

/**
 * EINE Logik fuer die ausloesenden Befehle (3.4): der Aktionsteil des Endpunkts (bis 3.2.14 in html/index.php) - der
 * Endpunkt gibt die Felder byte-gleich wie bisher aus, die Befehlsbruecke ruft dieselbe Funktion (Quelle 'mqtt').
 * $aktion scan|enable|interval|mode, $wert der Wert (bei scan der Suchweg fuer --mode: ''|both|fritzbox|ping).
 * Rueckgabe array(HTTP-Code, Felder) mit OK, AKTION, WERT, UNVERAENDERT, ERR wie bisher. Geaendert gegenueber 3.2.14
 * nur: nach dem Schreiben status/mode|interval|enabled ueber ws_auftrag_starten('status') statt Listener-Neustart, und
 * der Suchlauf ueber ws_auftrag_starten('scan') statt exec mit Schale (ERR=AUFTRAG_FEHLT, wenn er nicht startet).
 */
function ws_steuern($aktion, $wert, $quelle = 'http')
{
    $aktion = (string) $aktion;
    $wert = (string) $wert;
    if (ws_upgrade_laeuft()) {
        return array(503, array('OK' => 0, 'ERR' => 'UPGRADE'));
    }
    if ($aktion === 'scan') {
        if (ws_bin_datei('check.pl') === '') {
            return array(500, array('OK' => 0, 'ERR' => 'CHECK_FEHLT'));
        }
        /* C13: --auftrag - ein Befehl aus Loxone sucht auch bei ausgeschaltetem regelmaessigem Suchen. */
        if (!ws_auftrag_starten('scan', $wert)) {
            return array(500, array('OK' => 0, 'ERR' => 'AUFTRAG_FEHLT'));
        }
        return array(200, array('OK' => 1, 'AKTION' => 'scan'));
    }
    /* Die drei schreibenden Aktionen. Der Wert wird gegen dieselbe Pruefung gehalten wie ein Feld der
     * Oberflaeche - eine zweite Wahrheit ueber zulaessige Werte gibt es nicht. */
    $ziel = array('enable' => 'BASE.ENABLED', 'interval' => 'BASE.CRON');
    if (!isset($ziel[$aktion]) && $aktion !== 'mode') {
        return array(400, array('OK' => 0, 'ERR' => 'AKTION'));
    }
    $cfg = ws_config_read(false);
    if ($cfg === array()) {
        return array(500, array('OK' => 0, 'ERR' => 'KEINE_KONFIG'));
    }
    $neu = $cfg;
    if ($aktion === 'mode') {
        if ($wert === '0') { $neu['BASE.FRITZBOX_ENABLE'] = '1'; $neu['BASE.ACTIVE_SCAN'] = '1'; }
        elseif ($wert === '1') { $neu['BASE.FRITZBOX_ENABLE'] = '1'; $neu['BASE.ACTIVE_SCAN'] = '0'; }
        elseif ($wert === '2') { $neu['BASE.FRITZBOX_ENABLE'] = '0'; $neu['BASE.ACTIVE_SCAN'] = '1'; }
        else { return array(400, array('OK' => 0, 'ERR' => 'WERT')); }
    } else {
        if (ws_wert_pruefen($ziel[$aktion], $wert) !== '') {
            return array(400, array('OK' => 0, 'ERR' => 'WERT'));
        }
        $neu[$ziel[$aktion]] = $wert;
    }
    /* C6: steht der Wert schon so in der Konfiguration, wird nichts geschrieben - UNVERAENDERT=1. Derselbe Befehl
     * binnen 60 s tut gar nichts; kommt er spaeter wieder, wird nur der Zeitplan nachgezogen. */
    $gleich = true;
    foreach ($neu as $k => $v) {
        if (!array_key_exists($k, $cfg) || (string) $cfg[$k] !== (string) $v) {
            $gleich = false;
            break;
        }
    }
    if ($gleich) {
        if (!ws_befehl_binnen($aktion, $wert)) {
            ws_befehl_merken($aktion, $wert);
            ws_cron_apply(ws_cfg($neu, 'BASE.ENABLED', '0'), ws_cfg($neu, 'BASE.CRON', '3'));
        }
        return array(200, array('OK' => 1, 'AKTION' => $aktion, 'WERT' => $wert, 'UNVERAENDERT' => 1));
    }
    if (!ws_config_write($neu)) {
        return array(500, array('OK' => 0, 'ERR' => 'SCHREIBEN'));
    }
    ws_befehl_merken($aktion, $wert);
    ws_cron_apply(ws_cfg($neu, 'BASE.ENABLED', '0'), ws_cfg($neu, 'BASE.CRON', '3'));
    /* E7: die Zustaende sofort nachziehen (bei UDP sendet --status nichts). Ohne das stuende nach "Suche aus"
     * status/enabled fuer immer auf 1 - es laeuft ja kein Suchlauf mehr. */
    ws_auftrag_starten('status');
    return array(200, array('OK' => 1, 'AKTION' => $aktion, 'WERT' => $wert));
}

/* ---------------- Befehlsbruecke: die Seite der Linie (Entwurf 3.3-3.5) ---------------- */

/** Einstellungen fuer die Bruecke (ws_config_read() liest jedes Mal - ein Haken wirkt ohne Neustart). Praefix fest. */
function ws_bb_einstellungen()
{
    $c = ws_config_read(false);
    return array('praefix' => 'wifi_ng',
                 'annehmen' => ws_cfg($c, 'BASE.BEFEHLE_MQTT_EIN', '0') === '1',
                 'eingreifend' => ws_cfg($c, 'BASE.BEFEHLE_EINGREIFEND_EIN', '0') === '1',
                 'mqtt_ein' => true);
}

/**
 * Protokoll der Befehlsbruecke (und des Haus-Abnehmers): <logdir>/befehlsbruecke.log, angehaengt unter flock,
 * "YYYY-MM-DD HH:MM:SS STUFE text", ueber 500 kB auf die letzten 200 Zeilen gekappt (wie log_kappen in check.pl).
 * Der Reiter Logdateien listet die Datei ueber loglist_html() von selbst (W29). Kein Kennwort.
 */
function ws_bb_protokoll($stufe, $text)
{
    $p = ws_paths();
    if ($p['home'] === '' || !is_dir($p['logdir'])) {
        return;
    }
    $f = $p['logdir'] . '/befehlsbruecke.log';
    $zeile = date('Y-m-d H:i:s') . ' ' . strtoupper((string) $stufe) . ' '
           . str_replace(array("\r", "\n"), ' ', (string) $text) . "\n";
    $fh = @fopen($f, 'a+');
    if ($fh === false) {
        return;
    }
    if (@flock($fh, LOCK_EX)) {
        $st = @fstat($fh);
        if ($st && $st['size'] > 500 * 1024) {
            @fseek($fh, 0);
            $z = preg_split('/\n/', (string) @stream_get_contents($fh), -1, PREG_SPLIT_NO_EMPTY);
            @ftruncate($fh, 0);
            @fseek($fh, 0);
            @fwrite($fh, implode("\n", array_slice($z, -200)) . "\n");
        }
        @fseek($fh, 0, SEEK_END);
        @fwrite($fh, $zeile);
        @flock($fh, LOCK_UN);
    }
    @fclose($fh);
}

/**
 * Ein Befehl der Befehlsbruecke (tun() in bin/befehle_zuordnung.php). $name scan | modus | takt | suche, $wert der von
 * der Bruecke gepruefte Wert. Upgrade-Marke -> AKTUALISIERUNG; Name/Wert -> Aktion/Wert nach 3.2; dann ws_steuern(...,
 * 'mqtt'). Kein HTTP, der Suchlauf ueber den Auftragsprozess. Rueckgabe array(bool ok, Grund ASCII).
 */
function ws_bb_tun($name, $wert)
{
    $name = (string) $name;
    $wert = trim((string) $wert);
    if (ws_upgrade_laeuft()) {
        return array(false, 'AKTUALISIERUNG');
    }
    $karte = array(
        'scan'  => array('scan', array('jetzt' => '', 'beides' => 'both', 'fritzbox' => 'fritzbox', 'ping' => 'ping')),
        'modus' => array('mode', array('beides' => '0', 'fritzbox' => '1', 'ping' => '2')),
        'takt'  => array('interval', null),
        'suche' => array('enable', null),
    );
    if (!isset($karte[$name])) {
        return array(false, 'UNBEKANNT');
    }
    list($aktion, $werte) = $karte[$name];
    if ($werte !== null) {
        if (!array_key_exists($wert, $werte)) {
            return array(false, 'WERT');
        }
        $wert = $werte[$wert];
    }
    list($code, $felder) = ws_steuern($aktion, $wert, 'mqtt');
    if (!empty($felder['OK'])) {
        return array(true, '');
    }
    return array(false, isset($felder['ERR']) ? (string) $felder['ERR'] : 'FEHLER');
}

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN: kein Hauptteil). */
function ws_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $f = ws_bin_datei('befehlsbruecke.php');
    if ($f === '') {
        return false;
    }
    if (!defined('BB_NUR_LADEN')) {
        define('BB_NUR_LADEN', true);
    }
    require_once $f;
    return function_exists('bb_waechter');
}

/**
 * Direktstart bzw. -stopp der Bruecke nach dem Speichern (Bauart bl_/db_bruecke_schalten): befehlsbruecke.sh start
 * startet nur nach --soll, nie als root, nie waehrend eines Updates, nie doppelt; der Waechter in cron.01min startet
 * hoechstens alle 600 s neu. Rueckgabe array(''|'ein'|'aus'|'start_fehl'|'stop_fehl', Ausgabe).
 */
function ws_bruecke_schalten()
{
    if (DIRECTORY_SEPARATOR === '\\' || ws_paths()['home'] === '' || !ws_bruecke_laden()) {
        return array('', '');
    }
    $p = bb_pfade();
    if (!$p) {
        return array('', '');
    }
    $e = ws_bb_einstellungen();
    $laeuft = (bool) bb_dienste();
    if ($e['annehmen']) {
        if ($laeuft) {
            return array('', '');
        }
        list($rc, $aus) = bb_sh($p, 'start');
        return array(($rc === 0 && bb_dienste()) ? 'ein' : 'start_fehl', $aus);
    }
    if (!$laeuft) {
        return array('', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array(($rc === 0 && !bb_dienste()) ? 'aus' : 'stop_fehl', $aus);
}

/** Ein Satz zum Ergebnis von ws_bruecke_schalten() bzw. ws_haus_schalten() ('' = nichts zu sagen), unmaskiert. */
function ws_schalten_satz(array $r, $was = 'BEFEHL')
{
    /* Je Schluessel ein ausgeschriebener sprintf-Aufruf (sprachplatzhalter_pruefen.py zaehlt nur so). */
    $a = $r[1] !== '' ? trim((string) $r[1]) : '-';
    $h = $was === 'HAUS';
    switch ($r[0]) {
        case 'ein':
            return $h ? sprintf(ws_t('HAUS.M_GESTARTET'), $a) : sprintf(ws_t('BEFEHL.M_GESTARTET'), $a);
        case 'aus':
            return $h ? sprintf(ws_t('HAUS.M_ANGEHALTEN'), $a) : sprintf(ws_t('BEFEHL.M_ANGEHALTEN'), $a);
        case 'start_fehl':
            return $h ? sprintf(ws_t('HAUS.M_START_FEHL'), $a) : sprintf(ws_t('BEFEHL.M_START_FEHL'), $a);
        case 'stop_fehl':
            return $h ? sprintf(ws_t('HAUS.M_STOP_FEHL'), $a) : sprintf(ws_t('BEFEHL.M_STOP_FEHL'), $a);
    }
    return '';
}

/**
 * W22: ist die Befehlsbruecke bereit? Reine Regel (ohne Prozesszugriff, vom Pruefstand gedeckt): Haken an, der Dienst
 * laeuft argumentweise, seine zustand.json ist hoechstens BB_WACHE_GRENZE (180 s) alt und meldet ein Abo - dieselbe
 * Regel wie der Waechter der Stammfassung. Bis 3.2.14 meldete das Feld LISTENER nur, dass ein Prozess da war.
 */
function ws_bruecke_bereit_aus($haken, $laeuft, $zustand, $jetzt)
{
    if (!$haken || !$laeuft || !is_array($zustand) || !isset($zustand['ts'])) {
        return 0;
    }
    $alter = (int) $jetzt - (int) $zustand['ts'];
    return ($alter >= -60 && $alter <= 180 && !empty($zustand['abo'])) ? 1 : 0;
}

/** W22: dasselbe am laufenden System - EINE Funktion fuer Endpunkt (Feld LISTENER), Kopf und ws_test.php. */
function ws_bruecke_bereit(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $haken = ws_cfg($cfg, 'BASE.BEFEHLE_MQTT_EIN', '0') === '1';
    if (!$haken || DIRECTORY_SEPARATOR === '\\' || !ws_bruecke_laden()) {
        return 0;
    }
    $p = bb_pfade();
    if (!$p) {
        return 0;
    }
    return ws_bruecke_bereit_aus(true, (bool) bb_dienste(), bb_json_lesen($p['zustand']), time());
}

/** Kopf und Reiter Test: 'laeuft' | 'aus' (Haken) | 'steht'. */
function ws_bruecke_zustand(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    if (ws_cfg($cfg, 'BASE.BEFEHLE_MQTT_EIN', '0') !== '1') {
        return 'aus';
    }
    return ws_bruecke_bereit($cfg) ? 'laeuft' : 'steht';
}

/** Ein Hilfsskript unter bin/ mit bash aufrufen (Feld, ohne Schale). Rueckgabe array(rc, Ausgabe). */
function ws_sh_lauf($name, $befehl)
{
    $sh = ws_bin_datei($name);
    if ($sh === '' || DIRECTORY_SEPARATOR === '\\' || !function_exists('proc_open')) {
        return array(9, '');
    }
    $rohr = array();
    $pr = @proc_open(array('/bin/bash', $sh, (string) $befehl), array(0 => array('file', '/dev/null', 'r'),
                     1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $rohr);
    if (!is_resource($pr)) {
        return array(9, '');
    }
    $aus = trim((string) stream_get_contents($rohr[1]) . ' ' . (string) stream_get_contents($rohr[2]));
    fclose($rohr[1]);
    fclose($rohr[2]);
    return array((int) proc_close($pr), $aus);
}

/** V3: Haus-Abnehmer nach dem Speichern starten oder anhalten (ws_haus.sh). Rueckgabe wie ws_bruecke_schalten(). */
function ws_haus_schalten()
{
    if (DIRECTORY_SEPARATOR === '\\' || ws_paths()['home'] === '' || ws_bin_datei('ws_haus.sh') === '') {
        return array('', '');
    }
    $c = ws_config_read(false);
    list($rc0) = ws_sh_lauf('ws_haus.sh', 'status');
    $laeuft = ($rc0 === 0);
    if (ws_cfg($c, 'BASE.HAUS_ABNEHMER', '0') === '1') {
        if ($laeuft) {
            return array('', '');
        }
        list($rc, $aus) = ws_sh_lauf('ws_haus.sh', 'start');
        list($rc1) = ws_sh_lauf('ws_haus.sh', 'status');
        return array(($rc === 0 && $rc1 === 0) ? 'ein' : 'start_fehl', $aus);
    }
    if (!$laeuft) {
        return array('', '');
    }
    list($rc, $aus) = ws_sh_lauf('ws_haus.sh', 'stop');
    return array($rc === 0 ? 'aus' : 'stop_fehl', $aus);
}

/**
 * V3 im Reiter Test: array(Zustand, Text). 1 hoert und die Quelle lebt; 3 (gelb) die Quelle schweigt - es gilt nur
 * der eigene Takt (Nr. 15: "sagen es im Reiter Test"); 0 Haken an, aber der Abnehmer laeuft nicht; 2 Haken aus.
 */
function ws_haus_zustand(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    if (ws_cfg($cfg, 'BASE.HAUS_ABNEHMER', '0') !== '1') {
        return array(2, ws_t('TEST.P_HAUS_AUS'));
    }
    $p = ws_paths();
    $ab = @json_decode((string) @file_get_contents($p['datadir'] . '/haus/abnehmer.json'), true);
    $lebt_prozess = false;
    if (is_array($ab) && isset($ab['pid'])) {
        $c = @file_get_contents('/proc/' . (int) $ab['pid'] . '/cmdline');
        $lebt_prozess = ($c !== false && strpos($c, 'ws_haus.php') !== false);
    }
    if (!$lebt_prozess || !isset($ab['ts']) || time() - (int) $ab['ts'] > 180) {
        return array(0, ws_t('TEST.P_HAUS_TOT'));
    }
    $n = isset($ab['personen']) && is_array($ab['personen']) ? count($ab['personen']) : 0;
    $ll = isset($ab['letzter_lauf']['ts']) ? (int) $ab['letzter_lauf']['ts'] : 0;
    $zuletzt = isset($ab['quelle']['zuletzt']) ? (int) $ab['quelle']['zuletzt'] : 0;
    if (!empty($ab['quelle']['lebt'])) {
        return array(1, sprintf(ws_t('TEST.P_HAUS_OK'), $n, $ll > 0 ? date('H:i:s', $ll) : '—'));
    }
    return array(3, sprintf(ws_t('TEST.P_HAUS_SCHWEIGT'), $zuletzt > 0 ? (string) (time() - $zuletzt) : '—'));
}

/* ---------------- Namenstabelle und Befehle fuer Loxone (2.4, 3.8) ---------------- */

/** Gateway-Name eines Themas: "/", "%" und Leerzeichen werden "_" (wie build_vi_name des Gateways). EINE Stelle. */
function ws_vi_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $thema);
}

/**
 * Die Themen fuer Loxone - EINE Quelle fuer die Namenstabelle (Reiter Einbindung, Kopierknopf), die Thementabelle
 * (ws_test.php?topics) und die Pruefzeile. Aus ws_themen() und dazu status/befehle der Bruecke. Je Zeile: thema, name,
 * bedeutung, werte, retained, v2 ('N' = Noncached), haken (V2-Hakenliste des Musterprojekts), muster.
 * V2-Hakenliste (E11): je Person, status/mode, /interval, /enabled, /ok (Noncached), /zaehler - nicht status/ts (nur
 * BLE-Scanner NG liest es), nicht status/befehle, keine Quittungen.
 */
function ws_mqtt_namen(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $aus = array();
    foreach (ws_themen($cfg) as $th) {
        $ts = $th[0] === 'wifi_ng/status/ts';
        $aus[] = array('thema' => $th[0], 'name' => ws_vi_name($th[0]), 'bedeutung' => $th[1], 'werte' => $th[2],
                       'retained' => (bool) $th[3], 'v2' => $th[0] === 'wifi_ng/status/ok' ? 'N' : '',
                       'haken' => !$ts, 'muster' => !$ts);
    }
    $aus[] = array('thema' => 'wifi_ng/status/befehle', 'name' => ws_vi_name('wifi_ng/status/befehle'),
                   'bedeutung' => ws_t('LOX.T_BEFEHLE'), 'werte' => '-1 / 0 / 1', 'retained' => false, 'v2' => '',
                   'haken' => false, 'muster' => false);
    return $aus;
}

/** Pruefzeile Namenstabelle: jeder Name aus seinem Thema, keiner doppelt, V2-Haken wie im Musterprojekt. */
function ws_pruef_namen(?array $cfg = null, ?array $namen = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    if ($namen === null) { $namen = ws_mqtt_namen($cfg); }
    $falsch = array();
    $gesehen = array();
    $haken = 0;
    $nc = 0;
    foreach ($namen as $z) {
        if ($z['name'] !== ws_vi_name($z['thema']) || strpos($z['name'], 'wifi_ng_') !== 0) {
            $falsch[] = $z['name'];
        }
        if (isset($gesehen[$z['name']])) {
            $falsch[] = $z['name'] . ' (2x)';
        }
        $gesehen[$z['name']] = 1;
        $haken += $z['haken'] ? 1 : 0;
        $nc += $z['v2'] === 'N' ? 1 : 0;
    }
    if ($falsch) {
        return array(0, sprintf(ws_t('TEST.P_NAMEN_FALSCH'), ws_e(implode(', ', $falsch))));
    }
    return array(1, sprintf(ws_t('TEST.P_NAMEN_OK'), count($namen), $haken, $nc));
}

/** UDP-Eingang des MQTT-Gateways (general.json Mqtt.Udpinport, sonst 11884). */
function ws_udp_eingang()
{
    $h = ws_paths()['home'];
    $g = $h !== '' ? @json_decode((string) @file_get_contents($h . '/config/system/general.json'), true) : null;
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : array();
    $port = isset($m['Udpinport']) && is_numeric($m['Udpinport']) ? (int) $m['Udpinport'] : 0;
    return ($port >= 1 && $port <= 65535) ? $port : 11884;
}

/** Rechnername fuer /dev/udp/<LoxBerry>/<Port> - ohne Port aus der Adresszeile. */
function ws_host_udp()
{
    $h = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $h = preg_replace('/:\d+$/', '', preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $h));
    if ($h === '') {
        $h = preg_replace('/[^A-Za-z0-9.-]/', '', (string) @gethostname());
    }
    return $h !== '' ? $h : 'loxberry';
}

/**
 * Die Befehle fuer Loxone - EINE Quelle fuer die Tabelle im Reiter Einbindung und die Vorlage MQTT-Befehle (3.8).
 * Titel FEST deutsch ("WiFi-Scanner-NG: ...", Sprachregel 13.10.2026) - kein Titel gleicht einem Gateway-Eingang (die
 * heissen wifi_ng_...). "Takt schnell": Ein takt 1, Aus der eingestellte Takt, bei 1 (oder keinem erlaubten) 3 - wie
 * ws_vorlage_steuerung(). Je Zeile: titel, name, ein, aus (oder null), kommentar (Sprachschluessel), eingreifend, muster.
 */
function ws_mqtt_befehle(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $takt = trim((string) ws_cfg($cfg, 'BASE.CRON', '3'));
    if ($takt === '1' || !in_array($takt, ws_takte(), true)) {
        $takt = '3';
    }
    return array(
        array('titel' => 'WiFi-Scanner-NG: Suche jetzt', 'name' => 'scan', 'ein' => 'jetzt', 'aus' => null,
              'kommentar' => ws_t('VORLAGE.MK_JETZT'), 'eingreifend' => false, 'muster' => true),
        array('titel' => 'WiFi-Scanner-NG: Suche ein', 'name' => 'suche', 'ein' => '1', 'aus' => null,
              'kommentar' => ws_t('VORLAGE.MK_EIN'), 'eingreifend' => true, 'muster' => true),
        array('titel' => 'WiFi-Scanner-NG: Suche aus', 'name' => 'suche', 'ein' => '0', 'aus' => null,
              'kommentar' => ws_t('VORLAGE.MK_AUS'), 'eingreifend' => true, 'muster' => true),
        array('titel' => 'WiFi-Scanner-NG: Takt schnell', 'name' => 'takt', 'ein' => '1', 'aus' => $takt,
              'kommentar' => sprintf(ws_t('VORLAGE.MK_SCHNELL'), $takt), 'eingreifend' => false, 'muster' => true),
        array('titel' => 'WiFi-Scanner-NG: Suchweg Fritz!Box', 'name' => 'modus', 'ein' => 'fritzbox', 'aus' => null,
              'kommentar' => ws_t('VORLAGE.MK_FRITZ'), 'eingreifend' => false, 'muster' => false),
        array('titel' => 'WiFi-Scanner-NG: Suchweg Netzwerk-Suche', 'name' => 'modus', 'ein' => 'ping', 'aus' => null,
              'kommentar' => ws_t('VORLAGE.MK_PING'), 'eingreifend' => false, 'muster' => false),
        array('titel' => 'WiFi-Scanner-NG: Suchweg beides', 'name' => 'modus', 'ein' => 'beides', 'aus' => null,
              'kommentar' => ws_t('VORLAGE.MK_BEIDES'), 'eingreifend' => false, 'muster' => false),
    );
}

/**
 * Vorlage "MQTT-Befehle" (3.8): ein Virtueller Ausgang an /dev/udp/<LoxBerry>/<UDP-Eingang des Gateways>,
 * CloseAfterSend, je Zeile "publish wifi_ng/befehl/<name> <wert>" (nie retain). Aufbau wie die Ausfuhr aus Loxone
 * Config (Bauart bl_xml_virtual_out). Rueckgabe array(Dateiname, Inhalt).
 */
function ws_vorlage_mqtt_out(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $x = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="WiFi-Scanner-NG Befehle (MQTT)" Comment="'
        . $x(ws_kurz(sprintf(ws_t('VORLAGE.K_MQTT'), date('d.m.Y')))) . '" Address="'
        . $x('/dev/udp/' . ws_host_udp() . '/' . ws_udp_eingang()) . '" CmdInit="" CloseAfterSend="true" CmdSep=";">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (ws_mqtt_befehle($cfg) as $b) {
        $o .= "\t" . '<VirtualOutCmd Title="' . $x($b['titel']) . '" Comment="' . $x(ws_kurz($b['kommentar'])) . '" '
            . 'CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . $x('publish wifi_ng/befehl/' . $b['name'] . ' ' . $b['ein'])
            . '" CmdOnHTTP="" CmdOnPost="" CmdOff="'
            . ($b['aus'] === null ? '' : $x('publish wifi_ng/befehl/' . $b['name'] . ' ' . $b['aus']))
            . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_WiFi-Scanner-NG_MQTT.xml', $o);
}

/** Pruefzeile Vorlage MQTT-Befehle: laedt, jedes Thema unter wifi_ng/befehl/, kein Titel = Gateway-Name, kein "%". */
function ws_pruef_vorlage_mqtt(?array $cfg = null, $xml = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    if ($xml === null) { list(, $xml) = ws_vorlage_mqtt_out($cfg); }
    if (!function_exists('simplexml_load_string')) {
        return array(2, ws_t('TEST.P_VORLAGE_KEIN_PARSER'));
    }
    $alt = libxml_use_internal_errors(true);
    $s = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    if ($s === false) {
        return array(0, sprintf(ws_t('TEST.P_VORLAGE_KAPUTT'), 'VQ_WiFi-Scanner-NG_MQTT.xml'));
    }
    $namen = array();
    foreach (ws_mqtt_namen($cfg) as $z) { $namen[$z['name']] = 1; }
    $fehl = array();
    $n = 0;
    foreach ($s->VirtualOutCmd as $c) {
        $n++;
        $t = (string) $c['Title'];
        if (isset($namen[$t])) { $fehl[] = $t . ' = Gateway-Name'; }
        foreach (array('CmdOn', 'CmdOff') as $a) {
            $v = (string) $c[$a];
            if ($v !== '' && strpos($v, 'publish wifi_ng/befehl/') !== 0) { $fehl[] = $t . ' ' . $a; }
        }
        if (strpos((string) $c['Comment'], '%') !== false) { $fehl[] = $t . ' Comment %'; }
    }
    if ($fehl) {
        return array(0, ws_e(implode('; ', $fehl)));
    }
    return array(1, sprintf(ws_t('TEST.P_VORLAGE_MQTT_OK'), $n));
}

/* ---------------- Pruefzeilen (Reiter Test) ---------------- */

/**
 * V4 (E19): Sprachdateien deckungsgleich? (1) Schluessel in beide Richtungen ueber ALLE Abschnitte von language_* und
 * help_* (Vereinigung, keine feste Liste); (2) je gemeinsamem Schluessel gleiche Zahl der Platzhalter
 * (/%(?:\d+\$)?[sdfu]/) - sprintf mit zu wenigen Argumenten wirft unter PHP 8 ArgumentCountError. Nachbau von BLE
 * bl_sprachen_vergleich(). Rueckgabe array(bool, Text); hoechstens 12 Abweichungen genannt. $pfad nur fuer die Probe.
 */
function ws_sprachen_vergleich($pfad = null)
{
    if ($pfad === null) {
        $pp = ws_paths();
        $pfad = $pp['home'] !== '' ? $pp['home'] . '/templates/plugins/' . $pp['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
            $pfad = dirname(dirname(__DIR__)) . '/templates/lang';
        }
    }
    $abw = array();
    $abschnitte = 0;
    $schluessel = 0;
    foreach (array('language', 'help') as $art) {
        $de = @parse_ini_file($pfad . '/' . $art . '_de.ini', true, INI_SCANNER_RAW);
        $en = @parse_ini_file($pfad . '/' . $art . '_en.ini', true, INI_SCANNER_RAW);
        if (!is_array($de) || !is_array($en)) {
            $abw[] = $art . '_de/_en.ini unlesbar';
            continue;
        }
        foreach (array_unique(array_merge(array_keys($de), array_keys($en))) as $a) {
            $abschnitte++;
            $kd = isset($de[$a]) && is_array($de[$a]) ? $de[$a] : array();
            $ke = isset($en[$a]) && is_array($en[$a]) ? $en[$a] : array();
            foreach (array_diff(array_keys($kd), array_keys($ke)) as $k) { $abw[] = 'en: ' . $art . ' ' . $a . '.' . $k; }
            foreach (array_diff(array_keys($ke), array_keys($kd)) as $k) { $abw[] = 'de: ' . $art . ' ' . $a . '.' . $k; }
            foreach (array_intersect(array_keys($kd), array_keys($ke)) as $k) {
                $schluessel++;
                $zd = preg_match_all('/%(?:\d+\$)?[sdfu]/', (string) $kd[$k]);
                $ze = preg_match_all('/%(?:\d+\$)?[sdfu]/', (string) $ke[$k]);
                if ($zd !== $ze) { $abw[] = $a . '.' . $k . ' de=' . $zd . ' en=' . $ze; }
            }
        }
    }
    if ($abw) {
        return array(false, sprintf(ws_t('TEST.P_SPRACHEN_ABW'), count($abw), ws_e(implode(', ', array_slice($abw, 0, 12)))));
    }
    return array(true, sprintf(ws_t('TEST.P_SPRACHEN_OK'), $abschnitte, $schluessel));
}

/**
 * W21: wo liegt ein Werkzeug, und passt die sudoers-Regel? EINE Stelle fuer Reiter Test (Zeile Werkzeuge) und
 * ws_test.php?diag. Rueckgabe array(Fundort oder '', 'passt' | 'anders' | 'fehlt'). Gesucht wie check.pl werkzeug().
 */
function ws_werkzeug_lage($name, $wurzel = '')
{
    foreach (array('/usr/sbin', '/sbin', '/usr/bin', '/bin') as $d) {
        if (is_executable($wurzel . $d . '/' . $name)) {
            $ort = $d . '/' . $name;
            return array($ort, $ort === '/usr/sbin/' . $name ? 'passt' : 'anders');
        }
    }
    return array('', 'fehlt');
}

/**
 * E9: laeuft noch ein MQTT-Listener der Vorfassung, oder liegen seine Sperrdateien? argv[0] ein perl, argv[1]
 * zeichengenau <bindir>/mqtt_listener.pl, genau zwei Argumente (dieselbe Pruefung wie bis 3.2.14). Rueckgabe
 * array(PIDs, Sperrdateien).
 */
function ws_listener_reste()
{
    $p = ws_paths();
    $skript = $p['bindir'] . '/mqtt_listener.pl';
    $pids = array();
    foreach ((array) @scandir('/proc') as $e) {
        if (!ctype_digit((string) $e)) { continue; }
        $roh = @file_get_contents('/proc/' . $e . '/cmdline');
        if ($roh === false || $roh === '') { continue; }
        $a = explode("\0", rtrim($roh, "\0"));
        if (count($a) === 2 && preg_match('/^perl5?/', basename($a[0])) === 1 && $a[1] === $skript) {
            $pids[] = (int) $e;
        }
    }
    $sperren = array();
    foreach (array('.listener.lock', '.listener_start.lock') as $s) {
        $f = dirname($p['datadir']) . '/' . basename($p['datadir']) . $s;
        if (@is_file($f)) { $sperren[] = basename($f); }
    }
    return array($pids, $sperren);
}

/**
 * E7: stimmen die beiden Perl-Kopien der Modusregel ueberein, und geben sie dieselben vier Faelle wie ws_modus()?
 * Verglichen wird der Block zwischen "# MODUSREGEL-ANFANG" und "# MODUSREGEL-ENDE" (Leerraum zusammengezogen), dann die
 * return-Werte der Reihe nach gegen ws_modus() fuer 11, 10, 01, 00. Rueckgabe array(Zustand 1/0/2, Text).
 */
function ws_modusregel_pruefen($check = null, $abraeumen = null)
{
    $bl = array();
    foreach (array('check.pl' => $check, 'mqtt_abraeumen.pl' => $abraeumen) as $n => $q) {
        if ($q === null) {
            $f = ws_bin_datei($n);
            $q = $f !== '' ? (string) @file_get_contents($f) : '';
        }
        if (!preg_match('/# MODUSREGEL-ANFANG\n(.*?)# MODUSREGEL-ENDE/s', (string) $q, $m)) {
            return array(2, sprintf(ws_t('TEST.P_MODUS_FEHLT'), $n));
        }
        $bl[$n] = preg_replace('/\s+/', ' ', trim($m[1]));
    }
    if ($bl['check.pl'] !== $bl['mqtt_abraeumen.pl']) {
        return array(0, ws_t('TEST.P_MODUS_ABW'));
    }
    preg_match_all('/return (-?[0-9])/', $bl['check.pl'], $r);
    $soll = array(ws_modus('1', '1'), ws_modus('1', '0'), ws_modus('0', '1'), ws_modus('0', '0'));
    $ist = array_map('intval', $r[1]);
    if ($ist !== $soll) {
        return array(0, sprintf(ws_t('TEST.P_MODUS_PHP'), implode('/', $ist), implode('/', $soll)));
    }
    return array(1, sprintf(ws_t('TEST.P_MODUS_OK'), implode('/', $soll)));
}

/** E10 (eigener Fund F1): ergibt der Name das Thema wifi_ng/status oder wifi_ng/befehl? (gross/klein egal) */
function ws_name_reserviert($name)
{
    return in_array(strtolower(ws_topic_name((string) $name)), array('status', 'befehl'), true);
}

/**
 * E1/E10 im Bestand: Werte, die check.pl veraendert laese (Backslash, Leerzeichen am Komma im Fritz!Box-Zugang) und
 * Personen mit reserviertem Thema. Nur Schluessel und Namen, nie ein Wert. Keine stille Aenderung - der Reiter Test
 * sagt es (gelb).
 */
function ws_werte_hinweise(?array $cfg = null)
{
    if ($cfg === null) { $cfg = ws_config_read(false); }
    $aus = array();
    foreach (array('BASE.FRITZBOX_USER', 'BASE.FRITZBOX_PASS') as $k) {
        $w = (string) ws_cfg($cfg, $k, '');
        if (strpos($w, '\\') !== false || preg_match('/\s,|,\s/', $w) === 1) {
            $aus[] = sprintf(ws_t('TEST.P_WERTE_FRITZ'), $k);
        }
    }
    foreach (ws_users($cfg) as $u) {
        if ($u['name'] !== '' && ws_name_reserviert($u['name'])) {
            $aus[] = sprintf(ws_t('TEST.P_WERTE_NAME'), ws_e($u['name']));
        }
    }
    return $aus;
}

/**
 * W17: die Konfiguration aus der Zweitschrift wiederherstellen - wie ws_config_write(): Inhalt lesen, Kurzprobe
 * ([BASE] da), daneben schreiben (Rechte 0600 VOR dem Inhalt), Laenge pruefen, umbenennen. Scheitert es, wird nichts
 * angelegt und eine Zeile ins Protokoll geschrieben. Bis 3.2.14 stand hier @copy(): Rueckgabe ungeprueft, nicht
 * unteilbar, und die Datei mit Merkwort und Fritz!Box-Kennwort entstand mit den Rechten der umask (meist 0644).
 */
function ws_selbstheilung($quelle, $ziel)
{
    $inhalt = @file_get_contents($quelle);
    if ($inhalt === false || preg_match('/^\s*\[BASE\]\s*$/mi', (string) $inhalt) !== 1) {
        error_log('wifi_ng: Zweitschrift ' . basename($quelle) . ' nicht brauchbar - keine Selbstheilung.');
        return false;
    }
    @mkdir(dirname($ziel), 0775, true);
    $tmp = $ziel . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        error_log('wifi_ng: Selbstheilung - ' . basename($tmp) . ' nicht anlegbar.');
        return false;
    }
    @chmod($tmp, 0600);
    $ok = @ftruncate($fh, 0) && @fwrite($fh, $inhalt) === strlen($inhalt) && @fflush($fh);
    @fclose($fh);
    if (!$ok || !@rename($tmp, $ziel)) {
        @unlink($tmp);
        error_log('wifi_ng: Selbstheilung aus der Zweitschrift gescheitert - keine Konfiguration angelegt.');
        return false;
    }
    return true;
}
