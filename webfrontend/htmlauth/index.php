<?php
/**
 * WifiScanner - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Loest die alte Perl-CGI-Oberflaeche mit HTML::Template ab.
 * Die Konfigurationsdatei bleibt unveraendert im Config::Simple-Format,
 * damit check.pl und mqtt_listener.pl ohne Anpassung weiterlaufen.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

require_once __DIR__ . '/ws_lib.php';

$ws_p = ws_paths();
if ($ws_p['home']) {
    $ws_sdk_system = $ws_p['home'] . '/libs/phplib/loxberry_system.php';
    $ws_sdk_web = $ws_p['home'] . '/libs/phplib/loxberry_web.php';
    if (file_exists($ws_sdk_system)) {
        require_once $ws_sdk_system;
        require_once $ws_sdk_web;
    }
}

$ws_saved = false;
$ws_error = '';
$ws_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

/* ================= Speichern ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $alt = ws_config_read();
    $neu = array();

    $neu['BASE.ENABLED']         = isset($_POST['enabled']) ? '1' : '0';
    $neu['BASE.CRON']            = in_array((string) ($_POST['cron'] ?? '3'), array('1', '3', '5', '10', '15', '30', '60'), true)
        ? (string) $_POST['cron'] : '3';
    $neu['BASE.FRITZBOX_ENABLE'] = isset($_POST['fritz_enable']) ? '1' : '0';
    $neu['BASE.ACTIVE_SCAN']     = isset($_POST['active_scan']) ? '1' : '0';
    $neu['BASE.USE_CACHE']       = isset($_POST['use_cache']) ? '1' : '0';
    $neu['BASE.PING_CMD']        = ((string) ($_POST['ping_cmd'] ?? '0') === '1') ? '1' : '0';
    $neu['BASE.UDP_ENABLE']      = ((string) ($_POST['out_way'] ?? 'mqtt') === 'udp') ? '1' : '0';

    // Eingaben nie hart filtern - nur Steuerzeichen und Anfuehrungszeichen raus.
    $saeubern = function ($s) {
        $s = preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s);
        return trim($s);
    };

    $neu['BASE.FRITZBOX']      = $saeubern($_POST['fritzbox'] ?? 'fritz.box');
    $neu['BASE.FRITZBOX_PORT'] = (string) (int) ($_POST['fritzbox_port'] ?? 49443);
    $neu['BASE.PORT']          = (string) (int) ($_POST['udpport'] ?? 7007);
    if ($neu['BASE.FRITZBOX'] === '')      { $neu['BASE.FRITZBOX'] = 'fritz.box'; }
    if ((int) $neu['BASE.FRITZBOX_PORT'] <= 0) { $neu['BASE.FRITZBOX_PORT'] = '49443'; }
    if ((int) $neu['BASE.PORT'] <= 0)      { $neu['BASE.PORT'] = '7007'; }

    // Personen: nur Zeilen mit Namen UND mindestens einer Adresse
    $namen = isset($_POST['username']) && is_array($_POST['username']) ? $_POST['username'] : array();
    $adr   = isset($_POST['macs']) && is_array($_POST['macs']) ? $_POST['macs'] : array();
    $n = 0;
    foreach ($namen as $i => $name) {
        $name = $saeubern($name);
        $liste = $saeubern($adr[$i] ?? '');
        // Trennzeichen vereinheitlichen: Komma und Leerraum werden zu Semikolon
        $liste = preg_replace('/[,\s]+/', ';', $liste);
        $liste = implode(';', array_filter(array_map('trim', explode(';', $liste)), 'strlen'));
        if ($name === '' || $liste === '') {
            continue;
        }
        $n++;
        $neu['USER' . $n . '.NAME'] = $name;
        $neu['USER' . $n . '.MACS'] = $liste;
    }
    $neu['BASE.USERS'] = (string) $n;

    if (ws_config_write($neu)) {
        ws_cron_apply($neu['BASE.ENABLED'], $neu['BASE.CRON']);
        $ws_saved = true;
        // Listener neu starten, damit er die geaenderte Konfiguration meldet
        if (is_file($ws_p['bindir'] . '/mqtt_listener.pl')) {
            @exec('pkill -f mqtt_listener.pl 2>/dev/null');
            @exec('nohup ' . escapeshellarg($ws_p['bindir'] . '/mqtt_listener.pl') . ' > /dev/null 2>&1 &');
        }
    } else {
        $ws_error = 'Die Konfigurationsdatei konnte nicht geschrieben werden: ' . ws_e($ws_p['config']);
    }
}

$ws_cfg = ws_config_read();
$ws_users = ws_users($ws_cfg);
if (!$ws_users) {
    $ws_users = array(array('name' => '', 'macs' => ''));
}
// immer zwei leere Zeilen zum Anlegen anbieten
$ws_users[] = array('name' => '', 'macs' => '');
$ws_users[] = array('name' => '', 'macs' => '');

$ws_log_file = ws_log_file('wifi_scanner');
$ws_log_lines = ws_log_tail($ws_log_file);
$ws_listener_log = ws_log_file('mqtt_listener');

// WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall ws_-Praefix.
$ws_frame = class_exists('LBWeb', false);
if ($ws_frame) {
    LBWeb::lbheader('Wifi Scanner', 'https://wiki.loxberry.de/', 'help.html');
}
$ws_host = ws_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$ws_dir = ws_e($ws_p['plugin']);
?>
<style>
.ws-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.ws-wrap, .ws-wrap * { text-shadow: none !important; }
.ws-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.ws-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.ws-wrap input[type=text], .ws-wrap input[type=number], .ws-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.ws-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.ws-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.ws-row { display: flex; gap: 12px; flex-wrap: wrap; }
.ws-row > div { flex: 1; min-width: 200px; }
.ws-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.ws-wrap .ws-btn, .ws-wrap a.ws-btn, .ws-wrap button { box-shadow: none !important; }
.ws-wrap a.ws-btn, .ws-wrap a.ws-btn:visited, .ws-wrap a.ws-btn:hover { color: #fff !important; text-decoration: none; }
.ws-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.ws-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.ws-err { background: #ffebee; border: 1px solid #ef9a9a; }
.ws-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.ws-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.ws-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.ws-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.ws-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.ws-tab.ws-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.ws-pane { display: none; padding-top: 4px; }
.ws-pane.ws-active { display: block; }
.ws-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.ws-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.ws-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.ws-tbl th, .ws-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: top; }
.ws-tbl th { background: #f0f0f0; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.ws-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.ws-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.ws-knopfreihe form { margin: 0; display: flex; }
.ws-knopfreihe .ws-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.ws-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.ws-legende span { display: inline-flex; align-items: center; gap: 6px; }
.ws-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.ws-btn.ws-b-lesen   { background: #6dac20; }
.ws-btn.ws-b-technik { background: #546e7a; }
.ws-btn.ws-b-aktion  { background: #e0620d; }
.ws-punkt.ws-b-lesen   { background: #6dac20; }
.ws-punkt.ws-b-technik { background: #546e7a; }
.ws-punkt.ws-b-aktion  { background: #e0620d; }
</style>
<div class="ws-wrap">

<?php if ($ws_saved) { ?><div class="ws-alert ws-ok"><b>Gespeichert.</b> Der Zeitplan wurde neu gesetzt und der MQTT-Listener neu gestartet.</div><?php } ?>
<?php if ($ws_error !== '') { ?><div class="ws-alert ws-err"><b>Fehler:</b> <?= $ws_error ?></div><?php } ?>

<?php
$ws_pid = ws_listener_running();
$ws_cron = ws_cron_current();
$ws_ein = ws_cfg($ws_cfg, 'BASE.ENABLED', '0') === '1';
?>
<div class="ws-alert ws-info">
Periodischer Scan: <b><?= $ws_ein ? 'ein' : 'aus' ?></b><?php if ($ws_ein) { ?>, alle <b><?= ws_e(ws_cfg($ws_cfg, 'BASE.CRON', '3')) ?></b> Minuten<?php } ?>
&middot; Zeitplan: <?= $ws_cron !== '' ? ws_e($ws_cron) : '<b>keine Verkn&uuml;pfung</b>' ?>
&middot; MQTT-Listener: <?= $ws_pid ? 'l&auml;uft' : '<b>l&auml;uft nicht</b>' ?>
&middot; Weg zu Loxone: <b><?= ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') === '1' ? 'UDP' : 'MQTT' ?></b>
&middot; Personen: <b><?= count(ws_users($ws_cfg)) ?></b>
</div>

<div class="ws-tabs">
    <div class="ws-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="ws-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="ws-tab" data-pane="tab-test">Test</div>
    <div class="ws-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="ws-pane" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Personen und Ger&auml;te</h2>
<div class="ws-small" style="margin-bottom:8px;">
Je Person eine Zeile. In das rechte Feld kommen die <b>MAC-Adressen</b> (Form <span class="ws-mono">aa:bb:cc:dd:ee:ff</span>)
und/oder <b>feste IP-Adressen</b> der Ger&auml;te dieser Person, getrennt durch Semikolon, Komma oder Leerzeichen.
Sobald eines der Ger&auml;te erreichbar ist, gilt die Person als anwesend.
Der Name wird zum MQTT-Thema &mdash; Umlaute und Leerzeichen werden dabei ersetzt.
Zeilen ohne Namen oder ohne Adresse werden beim Speichern verworfen.
</div>
<table class="ws-tbl">
<tr><th style="width:30%;">Name</th><th>MAC- und/oder IP-Adressen</th><th style="width:24%;">MQTT-Thema</th></tr>
<?php foreach ($ws_users as $u) { ?>
<tr>
    <td><input data-role="none" type="text" name="username[]" value="<?= ws_e($u['name']) ?>" placeholder="z. B. Anna"></td>
    <td><input data-role="none" type="text" name="macs[]" value="<?= ws_e($u['macs']) ?>" placeholder="aa:bb:cc:dd:ee:ff; 192.168.1.44"></td>
    <td class="ws-small" style="padding-top:14px;"><?= $u['name'] !== '' ? 'wifiscanner/' . ws_e(ws_topic_name($u['name'])) : '&mdash;' ?></td>
</tr>
<?php } ?>
</table>

<h2>Zeitplan</h2>
<div class="ws-row">
<div>
    <label class="ws-check"><input data-role="none" type="checkbox" name="enabled" value="1" <?= $ws_ein ? 'checked' : '' ?>> Periodisch scannen</label>
    <div class="ws-small">Ist das aus, scannt das Plugin nur noch auf Befehl &mdash; aus Loxone per MQTT oder von Hand im Reiter Test.</div>
</div>
<div>
    <label>Alle wie viel Minuten?</label>
    <select data-role="none" name="cron">
    <?php foreach (array('1' => 'jede Minute', '3' => 'alle 3 Minuten', '5' => 'alle 5 Minuten', '10' => 'alle 10 Minuten',
                         '15' => 'alle 15 Minuten', '30' => 'alle 30 Minuten', '60' => 'st&uuml;ndlich') as $v => $t) { ?>
        <option value="<?= $v ?>" <?= (string) ws_cfg($ws_cfg, 'BASE.CRON', '3') === $v ? 'selected' : '' ?>><?= $t ?></option>
    <?php } ?>
    </select>
    <div class="ws-small">Kurze Abst&auml;nde erkennen schneller, erzeugen aber mehr Netzverkehr. 3 bis 5 Minuten sind ein guter Mittelweg.</div>
</div>
</div>

<h2>Wie wird gesucht?</h2>
<div class="ws-row">
<div>
    <label class="ws-check"><input data-role="none" type="checkbox" name="fritz_enable" value="1" <?= ws_cfg($ws_cfg, 'BASE.FRITZBOX_ENABLE', '0') === '1' ? 'checked' : '' ?>> Fritz!Box fragen (TR-064)</label>
    <div class="ws-small">Schnell und v&ouml;llig lautlos: der Router wei&szlig; ohnehin, wer angemeldet ist.</div>
</div>
<div>
    <label class="ws-check"><input data-role="none" type="checkbox" name="active_scan" value="1" <?= ws_cfg($ws_cfg, 'BASE.ACTIVE_SCAN', '0') === '1' ? 'checked' : '' ?>> Aktiv suchen (arping/ping)</label>
    <div class="ws-small">Notwendig ohne Fritz!Box. Weckt Ger&auml;te unter Umst&auml;nden aus dem Ruhezustand.</div>
</div>
</div>
<div class="ws-small" style="margin-top:6px;">
Beide zusammen entsprechen dem Modus 0: erst den Router fragen, und nur wer dort fehlt, wird angepingt. Das ist die schonendste Reihenfolge.
</div>

<div class="ws-row">
<div>
    <label>Fritz!Box-Adresse</label>
    <input data-role="none" type="text" name="fritzbox" value="<?= ws_e(ws_cfg($ws_cfg, 'BASE.FRITZBOX', 'fritz.box')) ?>">
</div>
<div>
    <label>Fritz!Box-Port (TR-064 &uuml;ber HTTPS)</label>
    <input data-role="none" type="number" name="fritzbox_port" value="<?= ws_e(ws_cfg($ws_cfg, 'BASE.FRITZBOX_PORT', '49443')) ?>">
</div>
</div>

<div class="ws-row">
<div>
    <label>Befehl f&uuml;r die aktive Suche</label>
    <select data-role="none" name="ping_cmd">
        <option value="0" <?= (string) ws_cfg($ws_cfg, 'BASE.PING_CMD', '0') === '0' ? 'selected' : '' ?>>arping (zuverl&auml;ssiger)</option>
        <option value="1" <?= (string) ws_cfg($ws_cfg, 'BASE.PING_CMD', '0') === '1' ? 'selected' : '' ?>>ping (ohne Rootrechte)</option>
    </select>
</div>
<div>
    <label class="ws-check" style="margin-top:34px;"><input data-role="none" type="checkbox" name="use_cache" value="1" <?= ws_cfg($ws_cfg, 'BASE.USE_CACHE', '1') === '1' ? 'checked' : '' ?>> ARP-Tabelle als Zwischenspeicher nutzen</label>
    <div class="ws-small">Spart Suchl&auml;ufe. Bei h&auml;ufig wechselnden IP-Adressen besser aus.</div>
</div>
</div>

<h2>Weg zum Miniserver</h2>
<div class="ws-row">
<div>
    <label>&Uuml;bertragung</label>
    <select data-role="none" name="out_way">
        <option value="mqtt" <?= ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') !== '1' ? 'selected' : '' ?>>MQTT (empfohlen)</option>
        <option value="udp" <?= ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') === '1' ? 'selected' : '' ?>>UDP (alter Weg)</option>
    </select>
    <div class="ws-small">MQTT-Themen sind benannt und kommen retained an &mdash; nach einem Neustart des Miniservers steht der letzte Stand sofort wieder da. UDP nur, wenn kein MQTT Gateway vorhanden ist.</div>
</div>
<div>
    <label>UDP-Port (nur bei UDP)</label>
    <input data-role="none" type="number" name="udpport" value="<?= ws_e(ws_cfg($ws_cfg, 'BASE.PORT', '7007')) ?>">
</div>
</div>

<button data-role="none" class="ws-btn" type="submit" name="save" value="1">Speichern</button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="ws-pane" id="tab-loxone">
<h2>Einbindung in Loxone</h2>
<div class="ws-small">
Das Plugin meldet je Person <b>0</b> (abwesend) oder <b>1</b> (anwesend). Der empfohlene Weg ist MQTT:
die Werte werden retained ver&ouml;ffentlicht, stehen also nach jedem Neustart des Miniservers sofort wieder an.
</div>

<div class="ws-step"><b>Schritt 1: MQTT Gateway vorbereiten</b><br>
Das Plugin <span class="ws-mono">MQTT Gateway</span> muss auf dem LoxBerry installiert und eingerichtet sein.
<?php $ws_broker = ws_mqtt_broker(); ?>
<?php if ($ws_broker !== '') { ?>
Gefundener Broker: <span class="ws-mono"><?= ws_e($ws_broker) ?></span>.
<?php } else { ?>
<b>Es wurde noch kein MQTT Gateway gefunden</b> &mdash; ohne Gateway bleibt nur der UDP-Weg.
<?php } ?>
Im Gateway unter <i>Subscriptions</i> gen&uuml;gt der Eintrag <span class="ws-mono">wifiscanner/#</span>;
das Plugin bringt ihn in <span class="ws-mono">config/mqtt_subscriptions.cfg</span> bereits mit.
</div>

<div class="ws-step"><b>Schritt 2: Virtuelle Eing&auml;nge im Miniserver</b><br>
Im MQTT Finder des Gateways erscheinen die Themen, sobald der erste Scan gelaufen ist. Per Klick auf
<i>Add to Miniserver</i> entsteht der virtuelle Eingang automatisch. Diese Themen gibt es:
<table class="ws-tbl">
<tr><th>Thema</th><th>Bedeutung</th><th>Werte</th></tr>
<?php foreach (ws_users($ws_cfg) as $u) { ?>
<tr><td class="ws-mono">wifiscanner/<?= ws_e(ws_topic_name($u['name'])) ?></td><td>Anwesenheit <?= ws_e($u['name']) ?></td><td>0 / 1</td></tr>
<?php } ?>
<tr><td class="ws-mono">wifiscanner/status/mode</td><td>Abfrage-Modus</td><td>0 = beides, 1 = nur Fritz!Box, 2 = nur Scan</td></tr>
<tr><td class="ws-mono">wifiscanner/status/interval</td><td>Scan-Intervall</td><td>Minuten</td></tr>
<tr><td class="ws-mono">wifiscanner/status/enabled</td><td>periodischer Scan</td><td>0 / 1</td></tr>
</table>
</div>

<div class="ws-step"><b>Schritt 3: Steuerung aus Loxone (optional)</b><br>
&Uuml;ber virtuelle Ausg&auml;nge l&auml;sst sich das Plugin aus der Visualisierung heraus steuern.
<b>Wichtig: diese Befehle nicht retained senden</b> &mdash; sonst wiederholt der Broker sie bei jedem Verbindungsaufbau.
<table class="ws-tbl">
<tr><th>Thema</th><th>Nutzlast</th><th>Wirkung</th></tr>
<tr><td class="ws-mono">wifiscanner/cmd/scan</td><td>leer oder Modus</td><td>Sofort-Scan, optional mit einmaligem Modus</td></tr>
<tr><td class="ws-mono">wifiscanner/cmd/mode</td><td>0/both, 1/fritzbox, 2/ping</td><td>Modus dauerhaft umstellen, l&ouml;st direkt einen Scan aus</td></tr>
<tr><td class="ws-mono">wifiscanner/cmd/interval</td><td>1, 3, 5, 10, 15, 30, 60</td><td>Intervall in Minuten, Zeitplan wird neu gesetzt</td></tr>
<tr><td class="ws-mono">wifiscanner/cmd/enable</td><td>0 oder 1</td><td>periodisches Scannen aus/ein</td></tr>
</table>
Diese Befehle nimmt <span class="ws-mono">mqtt_listener.pl</span> entgegen. Der Dienst startet beim Hochfahren
des LoxBerry automatisch; im Reiter Test l&auml;sst er sich pr&uuml;fen und neu starten.
</div>

<div class="ws-step"><b>Sinnvolle Verwendung im Projekt</b><br>
Die Einzelwerte auf einen <i>ODER</i>-Baustein legen ergibt &bdquo;jemand ist zu Hause&ldquo;. Weil WLAN-Ger&auml;te
kurz abtauchen, sollte danach eine <b>Ausschaltverz&ouml;gerung von 10 bis 15 Minuten</b> stehen, bevor die
Abwesenheit etwas ausl&ouml;st &mdash; sonst schaltet die Heizung ab, weil ein Telefon kurz geschlafen hat.
</div>

<div class="ws-step"><b>Alter Weg: UDP</b><br>
Steht die &Uuml;bertragung auf UDP, sendet das Plugin an alle im LoxBerry eingetragenen Miniserver
Zeilen der Form <span class="ws-mono">Name:0</span> bzw. <span class="ws-mono">Name:1</span>
an Port <span class="ws-mono"><?= ws_e(ws_cfg($ws_cfg, 'BASE.PORT', '7007')) ?></span>.
Im Miniserver braucht es daf&uuml;r einen virtuellen UDP-Eingang je Person mit der Befehlserkennung
<span class="ws-mono">Name:\v</span>. Neue Anlagen sollten MQTT verwenden.
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="ws-pane" id="tab-test">
<h2>Test</h2>
<div class="ws-legende">
<span><i class="ws-punkt ws-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="ws-punkt ws-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="ws-punkt ws-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="ws-h3">Ansehen</h3>
<div class="ws-knopfreihe">
<a class="ws-btn ws-b-lesen" href="ws_test.php?status" target="_blank">Status anzeigen</a>
<a class="ws-btn ws-b-lesen" href="ws_test.php?topics" target="_blank">MQTT-Themen anzeigen</a>
</div>

<h3 class="ws-h3">Technische Auskunft</h3>
<div class="ws-knopfreihe">
<a class="ws-btn ws-b-technik" href="ws_test.php?diag" target="_blank">Diagnose (Selbsttest)</a>
<a class="ws-btn ws-b-technik" href="ws_test.php?config" target="_blank">Konfiguration anzeigen</a>
</div>

<h3 class="ws-h3">L&ouml;st etwas aus</h3>
<div class="ws-knopfreihe">
<a class="ws-btn ws-b-aktion" href="ws_test.php?scan" target="_blank">Sofort-Scan starten</a>
<a class="ws-btn ws-b-aktion" href="ws_test.php?restart" target="_blank">MQTT-Listener neu starten</a>
</div>

<div class="ws-small" style="margin-top:14px;">
&bull; <b>Status</b> zeigt Modus, Zeitplan, Weg zum Miniserver und die angelegten Personen.<br>
&bull; <b>MQTT-Themen</b> listet genau die Themen, die im MQTT Finder auftauchen m&uuml;ssen &mdash; zum Abgleich.<br>
&bull; <b>Diagnose</b> pr&uuml;ft Zeitplan, Listener, Broker, die Werkzeuge arping/arp/arp-scan und die ben&ouml;tigten Perl-Module.<br>
&bull; <b>Sofort-Scan</b> startet einen Durchlauf im Hintergrund; das Ergebnis steht kurz darauf im Log.<br>
&bull; <b>Listener neu starten</b> hilft, wenn die Steuerung aus Loxone nicht mehr reagiert.
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="ws-pane" id="tab-log">
<h2>Logdatei</h2>
<div class="ws-small" style="margin-bottom:8px;">
Neueste Eintr&auml;ge oben, h&ouml;chstens 300 Zeilen.
Datei: <span class="ws-mono"><?= $ws_log_file !== '' ? ws_e($ws_log_file) : 'noch keine vorhanden' ?></span><br>
Wie ausf&uuml;hrlich protokolliert wird, stellt man in der LoxBerry-Pluginverwaltung
&uuml;ber den Loglevel ein &mdash; ab Stufe 7 landet auch die Ausgabe von arping im Protokoll.
<?php if ($ws_listener_log !== '') { ?><br>Der MQTT-Listener schreibt getrennt nach <span class="ws-mono"><?= ws_e($ws_listener_log) ?></span>.<?php } ?>
</div>
<?php if ($ws_log_lines) { ?>
<div class="ws-log"><?= ws_e(implode("\n", $ws_log_lines)) ?></div>
<?php } else { ?>
<div class="ws-alert ws-info">Noch keine Eintr&auml;ge. Starte im Reiter Test einen Sofort-Scan.</div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.ws-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('ws-active', t.dataset.pane === id); });
        document.querySelectorAll('.ws-pane').forEach(function (p) { p.classList.toggle('ws-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($ws_tab) ?>);
})();
</script>
<?php
if ($ws_frame) {
    LBWeb::lbfooter();
}
