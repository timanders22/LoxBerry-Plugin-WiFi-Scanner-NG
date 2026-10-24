<?php
/**
 * bin/ws_haus.php - WiFi-Scanner-NG als ABNEHMER von haus/anwesenheit/<person> (V3). NEU IN 3.2.15.
 *
 * Gruppe 14 / MQTT (Entwurf Musterprojekt/entwurf_gruppe14_ws.txt Abschnitt 7 V3, ENTSCHEIDUNGEN_GRUPPE14_WIFI.md 3 und
 * E15-E17; Regeln/07 Nr. 15, Anbieter BLE-Scanner NG 1.3.28). BLE wird nicht geaendert.
 *
 *   php ws_haus.php                Dienst (gestartet NUR ueber ws_haus.sh, nur bei HAUS_ABNEHMER=1)
 *   php ws_haus.php --soll         Rueckgabe 0 = soll laufen, 1 = soll nicht (Haken aus), 3 = defekt
 *   php ws_haus.php --waechter     ein Waechterlauf (cron/cron.01min ueber ws_haus.sh waechter)
 *   php ws_haus.php --selbsttest   Rechenkern ohne Broker: "N Faelle geprueft, M Fehlschlaege."
 *
 * Regeln (E17): ausgeloest wird NUR ein Wechsel 1 -> 0 einer Person (nicht retained); retained beim Anmelden ist der
 * Anfangsstand, "-" heisst keine Aussage und loest nie aus. Hoechstens ein Lauf je 60 s (auch ueber Neustarts: das
 * Abbild liegt unter data/). Quelle lebt, solange haus/anwesenheit/zaehler sich binnen 120 s aendert - sonst
 * "schweigt", kein Lauf, der eigene Takt gilt. Rueckkopplung: BLE fuehrt wifi_ng/<Name> in anwesend_gesamt zusammen;
 * ein Ausloeser binnen 120 s nach einem EIGENEN 1 -> 0 (aus zustand.json) wird uebergangen. Bei ausgeschalteter
 * regelmaessiger Suche (ENABLED=0) kein Lauf (E16). Der Lauf geht ueber ws_auftrag_starten('scan') - derselbe Weg wie
 * aktion=scan und die Bruecke. Dieser Abnehmer schreibt NICHTS unter haus/.
 *
 * Bauweise wie die Befehlsbruecke (Stammfassung in Bibliotheksform: bb_broker, bb_optionen, bb_programm, bb_umgebung,
 * bb_zeile_lesen, bb_dienste): mosquitto_sub ueber proc_open mit Feld, Kennwort nur in einer EIGENEN Optionsdatei
 * data/plugins/<ordner>/haus/mosquitto/ (0600/0700) - nicht in der der Bruecke, deren stop jedes mosquitto_sub mit
 * ihrer Optionsdatei beendet. Keine flock-Sperre in diesem Prozess (sie steht in ws_haus.sh), keine PID-Datei.
 */

if (!defined('BB_NUR_LADEN')) {
    define('BB_NUR_LADEN', true);
}
require_once __DIR__ . '/befehlsbruecke.php';
if (!function_exists('ws_config_read')) {
    $ws_h_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ws_h_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ws_lib.php';
    }
    $ws_h_kand[] = dirname(__DIR__) . '/webfrontend/html/ws_lib.php';
    foreach ($ws_h_kand as $ws_h_k) {
        if (is_file($ws_h_k)) { require_once $ws_h_k; break; }
    }
}

define('WS_HAUS_BREMSE', 60);
define('WS_HAUS_RUECK', 120);
define('WS_HAUS_SCHWEIGT', 120);
define('WS_HAUS_TAKT', 30);
define('WS_HAUS_WACHE_GRENZE', 180);
define('WS_HAUS_WACHE_BREMSE', 600);

/** Pfade: Ordner haus/ unter data/plugins/<ordner>/ (aus bb_pfade(), also aus dem eigenen Ablageort). */
function ws_haus_pfade()
{
    $p = bb_pfade();
    if (!$p) { return array(); }
    $d = $p['base'] . '/data/plugins/' . $p['ordner'] . '/haus';
    return array('base' => $p['base'], 'ordner' => $p['ordner'], 'bin' => $p['bin'], 'daten' => $d,
                 'optionen' => $d . '/mosquitto', 'abbild' => $d . '/abnehmer.json', 'waechter' => $d . '/waechter.json',
                 'sh' => $p['bin'] . '/ws_haus.sh', 'marke' => $p['marke'], 'general' => $p['general'],
                 'log' => $p['log']);
}

function ws_haus_neuer_zustand()
{
    return array('personen' => array(), 'jemand' => null,
                 'quelle' => array('zaehler' => null, 'zuletzt' => 0),
                 'letzter_lauf' => array('ts' => 0, 'person' => ''), 'eigen_weg' => 0, 'wlan' => null,
                 'uebergangen' => array('bremse' => 0, 'rueckkopplung' => 0, 'schweigt' => 0, 'suche_aus' => 0),
                 'laeufe' => 0);
}

/**
 * Rechenkern (vom Selbsttest gedeckt): eine Nachricht verarbeiten.
 * $ctx: 'suche_ein' (bool, ENABLED=1), 'wlan' (array Thema => online 0/1/-1 aus zustand.json oder null),
 *       'regeln' (array bremse/rueck/schweigt => bool; nur der Selbsttest schaltet sie fuer die Gegenproben aus).
 * Rueckgabe: 'lauf' | 'anfang' | 'bremse' | 'rueckkopplung' | 'schweigt' | 'suche_aus' | ''.
 */
function ws_haus_verarbeiten(array &$st, $retain, $thema, $wert, $jetzt, array $ctx)
{
    $r = isset($ctx['regeln']) ? $ctx['regeln'] : array();
    $regel = function ($k) use ($r) { return !isset($r[$k]) || $r[$k]; };
    // Eigene Wechsel 1 -> 0 aus dem Abbild von check.pl merken (Rueckkopplung).
    if (isset($ctx['wlan']) && is_array($ctx['wlan'])) {
        if (is_array($st['wlan'])) {
            foreach ($ctx['wlan'] as $t => $on) {
                if (isset($st['wlan'][$t]) && (int) $st['wlan'][$t] === 1 && (int) $on === 0) {
                    $st['eigen_weg'] = $jetzt;
                }
            }
        }
        $st['wlan'] = $ctx['wlan'];
    }
    $wert = trim((string) $wert);
    if ($thema === 'haus/anwesenheit/zaehler') {
        if ($st['quelle']['zaehler'] === null || $st['quelle']['zaehler'] !== $wert) {
            $st['quelle']['zuletzt'] = $jetzt;
        }
        $st['quelle']['zaehler'] = $wert;
        return '';
    }
    if ($thema === 'haus/anwesenheit/jemand') {
        $st['jemand'] = $wert;
        return '';
    }
    if (strpos($thema, 'haus/anwesenheit/') !== 0) {
        return '';
    }
    $person = substr($thema, strlen('haus/anwesenheit/'));
    if ($person === '' || strpos($person, '/') !== false) {
        return '';
    }
    $alt = isset($st['personen'][$person]) ? $st['personen'][$person] : null;
    $st['personen'][$person] = $wert;
    if ($retain) {
        return 'anfang';
    }
    if (!($alt === '1' && $wert === '0')) {
        return '';
    }
    if (empty($ctx['suche_ein'])) {
        $st['uebergangen']['suche_aus']++;
        return 'suche_aus';
    }
    if ($regel('schweigt') && ($st['quelle']['zuletzt'] <= 0 || $jetzt - $st['quelle']['zuletzt'] > WS_HAUS_SCHWEIGT)) {
        $st['uebergangen']['schweigt']++;
        return 'schweigt';
    }
    if ($regel('rueck') && $st['eigen_weg'] > 0 && $jetzt - $st['eigen_weg'] <= WS_HAUS_RUECK) {
        $st['uebergangen']['rueckkopplung']++;
        return 'rueckkopplung';
    }
    if ($regel('bremse') && $st['letzter_lauf']['ts'] > 0 && $jetzt - $st['letzter_lauf']['ts'] < WS_HAUS_BREMSE) {
        $st['uebergangen']['bremse']++;
        return 'bremse';
    }
    $st['letzter_lauf'] = array('ts' => $jetzt, 'person' => $person);
    $st['laeufe']++;
    return 'lauf';
}

/** Anwesenheit aus zustand.json von check.pl: Thema => online. null, wenn nicht lesbar. */
function ws_haus_wlan()
{
    if (!function_exists('ws_zustand_lesen')) { return null; }
    $z = ws_zustand_lesen();
    if ((int) $z['ts'] <= 0) { return null; }
    $aus = array();
    foreach ($z['personen'] as $pz) {
        if (isset($pz['name'])) { $aus[ws_topic_name($pz['name'])] = isset($pz['online']) ? (int) $pz['online'] : -1; }
    }
    return $aus;
}

function ws_haus_abbild(array $p, array $st, array $extra)
{
    $j = array('pid' => getmypid(), 'ts' => time()) + $extra + array(
        'quelle' => array('zaehler' => $st['quelle']['zaehler'], 'zuletzt' => $st['quelle']['zuletzt'],
                          'lebt' => $st['quelle']['zuletzt'] > 0 && time() - $st['quelle']['zuletzt'] <= WS_HAUS_SCHWEIGT),
        'personen' => $st['personen'], 'jemand' => $st['jemand'], 'letzter_lauf' => $st['letzter_lauf'],
        'uebergangen' => $st['uebergangen'], 'laeufe' => $st['laeufe']);
    bb_json_schreiben($p['abbild'], $j);
}

/** Soll der Abnehmer laufen? array(rc, grund): 0 ja, 1 Haken aus, 3 defekt. */
function ws_haus_soll()
{
    $p = ws_haus_pfade();
    if (!$p) { return array(3, 'keine LoxBerry-Installation erkannt'); }
    if (!function_exists('ws_config_read')) { return array(3, 'ws_lib.php nicht geladen'); }
    $c = ws_config_read(false);
    if (ws_cfg($c, 'BASE.HAUS_ABNEHMER', '0') !== '1') { return array(1, 'Haken aus'); }
    return array(0, 'Haken an');
}

function ws_haus_log($stufe, $text)
{
    if (function_exists('ws_bb_protokoll')) {
        ws_bb_protokoll($stufe, 'Haus-Abnehmer: ' . $text);
    }
}

$GLOBALS['ws_haus_halt'] = false;
function ws_haus_signal($sig)
{
    $GLOBALS['ws_haus_halt'] = true;
}

function ws_haus_dienst()
{
    $p = ws_haus_pfade();
    if (!$p) { fwrite(STDERR, "ws_haus: keine LoxBerry-Installation erkannt - nichts getan.\n"); return 1; }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) { fwrite(STDERR, "ws_haus: nicht als root starten.\n"); return 1; }
    list($soll, $grund) = ws_haus_soll();
    if ($soll !== 0) { fwrite(STDERR, 'ws_haus: ' . $grund . "\n"); return $soll; }
    $ich = getmypid();
    $skript = realpath(__FILE__);
    foreach (bb_dienste($skript === false ? __FILE__ : $skript) as $pid) {
        if ($pid < $ich) { ws_haus_log('WARN', 'laeuft schon (PID ' . $pid . ') - dieser Start endet.'); return 3; }
    }
    if (!is_dir($p['daten']) && !@mkdir($p['daten'], 0750, true)) { return 1; }
    foreach (bb_abos($p['optionen']) as $w) {
        if (function_exists('posix_kill')) { posix_kill($w, 15); }
    }
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, 'ws_haus_signal');
        pcntl_signal(SIGINT, 'ws_haus_signal');
        pcntl_signal(SIGHUP, SIG_IGN);
    }
    $st = ws_haus_neuer_zustand();
    $alt = bb_json_lesen($p['abbild']);
    if ($alt && isset($alt['letzter_lauf']['ts'])) {   // Bremse auch ueber Neustarts
        $st['letzter_lauf'] = array('ts' => (int) $alt['letzter_lauf']['ts'],
                                    'person' => isset($alt['letzter_lauf']['person']) ? (string) $alt['letzter_lauf']['person'] : '');
    }
    $extra = array('seit' => time(), 'abo' => false, 'fehler' => '');
    ws_haus_log('INFO', 'gestartet (PID ' . $ich . ').');
    while (!$GLOBALS['ws_haus_halt']) {
        list($soll) = ws_haus_soll();
        if ($soll !== 0) { ws_haus_log('INFO', 'Haken aus - der Abnehmer endet.'); break; }
        $b = bb_broker($p);
        $opt = bb_optionen($p, $b);
        $sub = bb_programm('mosquitto_sub');
        if ($opt === '' || $sub === '') {
            $extra['fehler'] = 'mosquitto_sub fehlt oder Optionsordner nicht schreibbar';
            ws_haus_abbild($p, $st, $extra);
            sleep(60);
            continue;
        }
        $kmd = array($sub, '-h', $b['host'], '-p', (string) $b['port'], '-V', 'mqttv5', '--retain-as-published',
                     '-F', '%r %t %x', '-t', 'haus/anwesenheit/+');
        $rohr = array();
        $proz = @proc_open($kmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'),
                                       2 => array('file', '/dev/null', 'w')), $rohr, null, bb_umgebung($opt));
        if (!is_resource($proz)) {
            $extra['fehler'] = 'mosquitto_sub liess sich nicht starten';
            ws_haus_abbild($p, $st, $extra);
            sleep(30);
            continue;
        }
        $extra['abo'] = true;
        $extra['fehler'] = '';
        stream_set_blocking($rohr[1], false);
        $puf = '';
        $naechst = 0;
        while (!$GLOBALS['ws_haus_halt']) {
            if (time() >= $naechst) {
                list($soll) = ws_haus_soll();
                if ($soll !== 0) { $GLOBALS['ws_haus_halt'] = true; break; }
                ws_haus_abbild($p, $st, $extra);
                $naechst = time() + WS_HAUS_TAKT;
            }
            $l = array($rohr[1]);
            $w = null;
            $x = null;
            $n = @stream_select($l, $w, $x, max(1, $naechst - time()));
            if ($n === false) { usleep(100000); continue; }
            if ($n === 0) { continue; }
            $t = fread($rohr[1], 65536);
            if ($t === false || $t === '') {
                if (feof($rohr[1])) { break; }
                continue;
            }
            $puf .= $t;
            while (($pos = strpos($puf, "\n")) !== false) {
                $zl = bb_zeile_lesen(rtrim(substr($puf, 0, $pos), "\r"));
                $puf = substr($puf, $pos + 1);
                if ($zl === null) { continue; }
                $wert = (string) @hex2bin($zl['hex']);
                $c = ws_config_read(false);
                $ctx = array('suche_ein' => ws_cfg($c, 'BASE.ENABLED', '0') === '1', 'wlan' => ws_haus_wlan());
                $erg = ws_haus_verarbeiten($st, $zl['retain'], $zl['thema'], $wert, time(), $ctx);
                if ($erg === 'lauf') {
                    $ok = function_exists('ws_auftrag_starten') ? ws_auftrag_starten('scan', '') : false;
                    ws_haus_log($ok ? 'INFO' : 'ERROR', $st['letzter_lauf']['person'] . ' 1 -> 0: Suchlauf '
                        . ($ok ? 'angestossen' : 'NICHT gestartet (ws_auftrag.sh)'));
                } elseif ($erg !== '' && $erg !== 'anfang') {
                    ws_haus_log('INFO', 'Ausloeser uebergangen: ' . $erg);
                }
                if ($erg !== '') { ws_haus_abbild($p, $st, $extra); }
            }
        }
        @proc_terminate($proz, 15);
        usleep(300000);
        $s = @proc_get_status($proz);
        if ($s && $s['running']) { @proc_terminate($proz, 9); }
        if (is_resource($rohr[1])) { @fclose($rohr[1]); }
        @proc_close($proz);
        $extra['abo'] = false;
        if (!$GLOBALS['ws_haus_halt']) { sleep(10); }
    }
    $extra['abo'] = false;
    ws_haus_abbild($p, $st, $extra);
    return 0;
}

/** ws_haus.sh start|stop ueber proc_open (Feld, ohne Schale). Rueckgabe array(rc, erste Zeile). */
function ws_haus_sh(array $p, $befehl)
{
    $rohr = array();
    $pr = @proc_open(array('/bin/bash', $p['sh'], $befehl), array(0 => array('file', '/dev/null', 'r'),
                     1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $rohr);
    if (!is_resource($pr)) { return array(1, 'ws_haus.sh nicht startbar'); }
    $aus = trim((string) stream_get_contents($rohr[1]) . ' ' . (string) stream_get_contents($rohr[2]));
    fclose($rohr[1]);
    fclose($rohr[2]);
    $rc = proc_close($pr);
    return array((int) $rc, $aus);
}

/** Ein Waechterlauf: Haken aus -> anhalten; an und tot oder stumm (Abbild aelter als 180 s) -> Neustart, hoechstens alle 600 s. */
function ws_haus_waechter()
{
    $p = ws_haus_pfade();
    if (!$p) { return 1; }
    if (!is_dir($p['daten'])) { @mkdir($p['daten'], 0750, true); }
    $sp = @fopen($p['daten'] . '/waechter.lock', 'c');
    if ($sp === false || !flock($sp, LOCK_EX | LOCK_NB)) { return 0; }
    $skript = realpath(__FILE__);
    $laeuft = (bool) bb_dienste($skript === false ? __FILE__ : $skript);
    list($soll) = ws_haus_soll();
    if ($soll !== 0) {
        if ($laeuft) { ws_haus_sh($p, 'stop'); }
        return 0;
    }
    $ab = bb_json_lesen($p['abbild']);
    $alter = ($ab && isset($ab['ts'])) ? time() - (int) $ab['ts'] : null;
    if ($laeuft && $alter !== null && $alter <= WS_HAUS_WACHE_GRENZE) { return 0; }
    $w = bb_json_lesen($p['waechter']);
    if (!$w) { $w = array(); }
    if (isset($w['letzter_start']) && time() - (int) $w['letzter_start'] < WS_HAUS_WACHE_BREMSE) { return 0; }
    if ($laeuft) { ws_haus_sh($p, 'stop'); }
    $w['letzter_start'] = time();
    bb_json_schreiben($p['waechter'], $w);
    list($rc, $aus) = ws_haus_sh($p, 'start');
    ws_haus_log($rc === 0 ? 'WARN' : 'ERROR', 'Waechter: ' . $aus);
    return $rc;
}

/** Rechenkern-Selbsttest: die Faelle (a)-(h) aus dem Entwurf 7 und die Gegenproben je Regel. */
function ws_haus_selbsttest()
{
    $n = 0;
    $f = 0;
    $pruef = function ($ok, $text) use (&$n, &$f) {
        $n++;
        if (!$ok) { $f++; echo "FEHL  $text\n"; } else { echo "ok    $text\n"; }
    };
    $an = array('suche_ein' => true, 'wlan' => null);
    $basis = function () {
        $st = ws_haus_neuer_zustand();
        ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '1', 1000, array('suche_ein' => true));
        ws_haus_verarbeiten($st, true, 'haus/anwesenheit/anna', '1', 1000, array('suche_ein' => true));
        ws_haus_verarbeiten($st, true, 'haus/anwesenheit/bert', '1', 1000, array('suche_ein' => true));
        return $st;
    };
    // (a) retained 1 beim Start -> kein Lauf
    $st = ws_haus_neuer_zustand();
    $pruef(ws_haus_verarbeiten($st, true, 'haus/anwesenheit/anna', '1', 1000, $an) === 'anfang', '(a) retained 1 beim Start: kein Lauf');
    // (b) 1 -> 0 -> genau ein Lauf
    $st = $basis();
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1010, $an) === 'lauf' && $st['laeufe'] === 1, '(b) 1 -> 0: genau ein Lauf');
    // (c) zweite Person binnen 60 s -> Bremse
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '2', 1030, $an);
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/bert', '0', 1040, $an) === 'bremse' && $st['laeufe'] === 1, '(c) zweite Person binnen 60 s: Bremse');
    $st2 = $basis();
    ws_haus_verarbeiten($st2, false, 'haus/anwesenheit/anna', '0', 1010, $an);
    ws_haus_verarbeiten($st2, false, 'haus/anwesenheit/zaehler', '2', 1030, $an);
    $pruef(ws_haus_verarbeiten($st2, false, 'haus/anwesenheit/bert', '0', 1040, $an + array('regeln' => array('bremse' => false))) === 'lauf',
           '(c) Gegenprobe ohne Bremse: Lauf');
    // (d) zaehler 125 s unveraendert, dann 1 -> 0 -> schweigt
    $st = $basis();
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1125, $an) === 'schweigt', '(d) Quelle 125 s still: schweigt, kein Lauf');
    $st = $basis();
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1125, $an + array('regeln' => array('schweigt' => false))) === 'lauf',
           '(d) Gegenprobe ohne Schweigeregel: Lauf');
    $st = ws_haus_neuer_zustand();
    ws_haus_verarbeiten($st, true, 'haus/anwesenheit/anna', '1', 1000, $an);
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1005, $an) === 'schweigt', '(d2) nie ein Zaehler gesehen: schweigt');
    // (e) eigener Lauf 1 -> 0 in zustand.json, BLE folgt binnen 5 s -> Rueckkopplung
    $st = $basis();
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '2', 1001, array('suche_ein' => true, 'wlan' => array('Anna' => 1)));
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '3', 1031, array('suche_ein' => true, 'wlan' => array('Anna' => 0)));
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1035, array('suche_ein' => true, 'wlan' => array('Anna' => 0))) === 'rueckkopplung',
           '(e) BLE folgt einem eigenen 1 -> 0 binnen 5 s: Rueckkopplung');
    $st = $basis();
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '2', 1001, array('suche_ein' => true, 'wlan' => array('Anna' => 1)));
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '3', 1031, array('suche_ein' => true, 'wlan' => array('Anna' => 0)));
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1035,
           array('suche_ein' => true, 'wlan' => array('Anna' => 0), 'regeln' => array('rueck' => false))) === 'lauf',
           '(e) Gegenprobe ohne Rueckkopplungsregel: Lauf');
    $st = $basis();
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '2', 1001, array('suche_ein' => true, 'wlan' => array('Anna' => 1)));
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '3', 1031, array('suche_ein' => true, 'wlan' => array('Anna' => 0)));
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/zaehler', '4', 1150, array('suche_ein' => true, 'wlan' => array('Anna' => 0)));
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1160, array('suche_ein' => true, 'wlan' => array('Anna' => 0))) === 'lauf',
           '(e2) 129 s nach dem eigenen 1 -> 0: wieder Lauf');
    // (f) "-" nach 1 -> kein Lauf; danach 0 aus "-" -> kein Lauf
    $st = $basis();
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '-', 1010, $an) === '', '(f) "-" nach 1: kein Lauf');
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1020, $an) === '', '(f) 0 nach "-": kein Lauf');
    // (h) ENABLED=0 -> kein Lauf (suche_aus)
    $st = $basis();
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1010, array('suche_ein' => false)) === 'suche_aus', '(h) Suche aus: kein Lauf');
    // 0 -> 1 und jemand
    $st = $basis();
    ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '0', 1010, $an);
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/anna', '1', 1080, $an) === '', '0 -> 1: kein Lauf');
    $pruef(ws_haus_verarbeiten($st, false, 'haus/anwesenheit/jemand', '0', 1090, $an) === '', 'jemand: nur angezeigt');
    $pruef(ws_haus_verarbeiten($st, false, 'haus/tag/x/da', '0', 1090, $an) === '', 'fremdes Thema: nichts');
    echo $n . ' Faelle geprueft, ' . $f . " Fehlschlaege.\n";
    return $f === 0 ? 0 : 1;
}

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $ws_h_a = isset($argv[1]) ? $argv[1] : '';
    if ($ws_h_a === '--selbsttest') { exit(ws_haus_selbsttest()); }
    if ($ws_h_a === '--soll') { list($ws_h_rc, $ws_h_g) = ws_haus_soll(); echo $ws_h_g . "\n"; exit($ws_h_rc); }
    if ($ws_h_a === '--waechter') { exit(ws_haus_waechter()); }
    if ($ws_h_a === '') { exit(ws_haus_dienst()); }
    fwrite(STDERR, "Aufruf: ws_haus.php [--soll|--waechter|--selbsttest]\n");
    exit(2);
}
