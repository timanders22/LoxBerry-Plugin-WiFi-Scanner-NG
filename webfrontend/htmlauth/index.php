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

// Der Reiter kommt aus einem abgesendeten Formular (activetab) oder aus der
// Adresse (?tab=...). Letzteres brauchen die Reiter, seit sie echte Verweise
// sind - siehe die Reiterleiste weiter unten.
$ws_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung. Die Namen
 * standen bis 2.5.1 an zwei Stellen: in diesem Muster und weiter unten im
 * Feld $ws_reiter; die Flaechen-ids kamen als dritte dazu. Wer einen Reiter
 * ergaenzt und eine davon vergisst, bekommt keinen Fehler, sondern eine
 * Seite, die nach jedem Absenden auf Einstellungen zurueckspringt. Die
 * Beschriftungen brauchen ws_t() und kommen weiter unten dazu. */
$ws_reiter_ids = array('settings', 'loxone', 'test', 'log');
$ws_tab = preg_match('/^tab-(' . implode('|', $ws_reiter_ids) . ')$/', $ws_wunsch)
    ? $ws_wunsch : 'tab-' . $ws_reiter_ids[0];

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
            ws_listener_stop();
            @exec('nohup perl ' . escapeshellarg($ws_p['bindir'] . '/mqtt_listener.pl') . ' > /dev/null 2>&1 &');
        }
    } else {
        $ws_error = sprintf(ws_t('FEHLER.CONFIG_SCHREIBEN'), ws_e($ws_p['config']));
    }
}

$ws_cfg = ws_config_read();
$ws_users = ws_users($ws_cfg);

// ---------- Loxone-Vorlage herunterladen (Hausstandard) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage']) && function_exists('ws_vorlage')) {
    list($ws_vname, $ws_vinhalt) = ws_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $ws_vname . '"');
    echo $ws_vinhalt;
    exit;
}
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
    LBWeb::lbheader(ws_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}
$ws_host = ws_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$ws_dir = ws_e($ws_p['plugin']);
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 200px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: top; }
.sm-tbl th { background: #f0f0f0; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($ws_saved) { ?><div class="sm-alert sm-ok"><b><?php echo ws_t('MELD.GESPEICHERT'); ?></b> <?php echo ws_t('MELD.GESPEICHERT_ZUSATZ'); ?></div><?php } ?>
<?php if ($ws_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo ws_t('ALLG.FEHLER'); ?></b> <?= $ws_error ?></div><?php } ?>

<?php
$ws_pid = ws_listener_running();
$ws_cron = ws_cron_current();
$ws_ein = ws_cfg($ws_cfg, 'BASE.ENABLED', '0') === '1';
?>
<div class="sm-alert sm-info">
<?php echo ws_t('KOPF.SCAN'); ?>: <b><?= $ws_ein ? ws_t('ALLG.EIN') : ws_t('ALLG.AUS') ?></b><?php if ($ws_ein) { ?>, <?php printf(ws_t('KOPF.ALLE_MINUTEN'), '<b>' . ws_e(ws_cfg($ws_cfg, 'BASE.CRON', '3')) . '</b>'); ?><?php } ?>
&middot; <?php echo ws_t('KOPF.ZEITPLAN'); ?>: <?= $ws_cron !== '' ? ws_e($ws_cron) : '<b>' . ws_t('KOPF.KEINE_VERKNUEPFUNG') . '</b>' ?>
&middot; <?php echo ws_t('KOPF.LISTENER'); ?>: <?= $ws_pid ? ws_t('ALLG.LAEUFT') : '<b>' . ws_t('ALLG.LAEUFT_NICHT') . '</b>' ?>
&middot; <?php echo ws_t('KOPF.WEG'); ?>: <b><?= ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') === '1' ? 'UDP' : 'MQTT' ?></b>
<?php if (!function_exists('ws_hs_autostart')) { function ws_hs_autostart() { $h = getenv('LBHOMEDIR') ?: '/opt/loxberry'; $g = $h . '/config/system/general.json'; if (!is_file($g)) { return null; } $j = json_decode((string) @file_get_contents($g), true); if (!is_array($j) || !isset($j['Mqtt'])) { return null; } return !empty($j['Mqtt']['Gatewayautostart']); } } if (ws_hs_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo ws_t('KOPF.W_AUTOSTART'); ?></div><?php } ?>
&middot; <?php echo ws_t('KOPF.PERSONEN'); ?>: <b><?= count(ws_users($ws_cfg)) ?></b>
</div>

<?php
/*
 * Die Reiter sind echte Verweise, keine <div>. Vorher stand hier
 * <div class="sm-tab" data-pane="..."> - und weil alle Flaechen bis zum Lauf
 * des JavaScripts auf display:none stehen, war die Seite ohne JavaScript
 * vollstaendig leer. Jetzt setzt der Server die Klasse sm-active an Reiter
 * UND Flaeche; das JavaScript spart nur noch den Seitenaufbau.
 */
$ws_beschriftung = array(
    'settings' => 'REITER.EINSTELLUNGEN', 'loxone' => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',          'log'    => 'REITER.LOG',
);
$ws_reiter = array();
foreach ($ws_reiter_ids as $ws_i) {
    $ws_reiter['tab-' . $ws_i] = isset($ws_beschriftung[$ws_i])
        ? ws_t($ws_beschriftung[$ws_i]) : $ws_i;
}
?>
<div class="sm-tabs">
<?php foreach ($ws_reiter as $ws_id => $ws_bez) { ?>
    <a class="sm-tab<?php echo $ws_tab === $ws_id ? ' sm-active' : ''; ?>"
       data-pane="<?php echo ws_e($ws_id); ?>"
       href="index.php?tab=<?php echo ws_e(substr($ws_id, 4)); ?>"><?php echo $ws_bez; ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane<?php echo $ws_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo ws_t('EINST.H_PERSONEN'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;">
<?php printf(ws_t('EINST.HINT_PERSONEN'), '<span class="sm-mono">aa:bb:cc:dd:ee:ff</span>'); ?>
</div>
<table class="sm-tbl">
<tr><th style="width:30%;"><?php echo ws_t('ALLG.NAME'); ?></th><th><?php echo ws_t('EINST.SP_ADRESSEN'); ?></th><th style="width:24%;"><?php echo ws_t('ALLG.MQTT_THEMA'); ?></th></tr>
<?php foreach ($ws_users as $u) { ?>
<tr>
    <td><input data-role="none" type="text" name="username[]" value="<?= ws_e($u['name']) ?>" placeholder="<?php echo ws_t('EINST.PH_NAME'); ?>"></td>
    <td><input data-role="none" type="text" name="macs[]" value="<?= ws_e($u['macs']) ?>" placeholder="aa:bb:cc:dd:ee:ff; 192.168.1.44"></td>
    <td class="sm-small" style="padding-top:14px;"><?= $u['name'] !== '' ? 'wifi_ng/' . ws_e(ws_topic_name($u['name'])) : '&mdash;' ?></td>
</tr>
<?php } ?>
</table>
<h2><?php echo ws_t('ALLG.H_VORLAGE'); ?></h2>
<div class="sm-hinweis"><?php echo ws_t('ALLG.H_VORLAGE_TEXT'); ?></div>
<form action="index.php" method="post" style="margin-bottom:14px;">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn" type="submit" style="background:#546e7a;"><?php echo ws_t('ALLG.K_VORLAGE'); ?></button>
</form>

<h2><?php echo ws_t('EINST.H_ZEITPLAN'); ?></h2>
<div class="sm-row">
<div>
    <label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1" <?= $ws_ein ? 'checked' : '' ?>> <?php echo ws_t('EINST.L_PERIODISCH'); ?></label>
    <div class="sm-small"><?php echo ws_t('EINST.HINT_PERIODISCH'); ?></div>
</div>
<div>
    <label><?php echo ws_t('EINST.L_TAKT'); ?></label>
    <select data-role="none" name="cron">
    <?php foreach (array('1' => ws_t('TAKT.MIN01'), '3' => ws_t('TAKT.MIN03'), '5' => ws_t('TAKT.MIN05'),
                         '10' => ws_t('TAKT.MIN10'), '15' => ws_t('TAKT.MIN15'), '30' => ws_t('TAKT.MIN30'),
                         '60' => ws_t('TAKT.STUENDLICH')) as $v => $t) { ?>
        <option value="<?= $v ?>" <?= (string) ws_cfg($ws_cfg, 'BASE.CRON', '3') === $v ? 'selected' : '' ?>><?= $t ?></option>
    <?php } ?>
    </select>
    <div class="sm-small"><?php echo ws_t('EINST.HINT_TAKT'); ?></div>
</div>
</div>

<h2><?php echo ws_t('EINST.H_SUCHE'); ?></h2>
<div class="sm-row">
<div>
    <label class="sm-check"><input data-role="none" type="checkbox" name="fritz_enable" value="1" <?= ws_cfg($ws_cfg, 'BASE.FRITZBOX_ENABLE', '0') === '1' ? 'checked' : '' ?>> <?php echo ws_t('EINST.L_FRITZ'); ?></label>
    <div class="sm-small"><?php echo ws_t('EINST.HINT_FRITZ'); ?></div>
</div>
<div>
    <label class="sm-check"><input data-role="none" type="checkbox" name="active_scan" value="1" <?= ws_cfg($ws_cfg, 'BASE.ACTIVE_SCAN', '0') === '1' ? 'checked' : '' ?>> <?php echo ws_t('EINST.L_AKTIV'); ?></label>
    <div class="sm-small"><?php echo ws_t('EINST.HINT_AKTIV'); ?></div>
</div>
</div>
<div class="sm-small" style="margin-top:6px;">
<?php echo ws_t('EINST.HINT_MODUS0'); ?>
</div>

<div class="sm-row">
<div>
    <label><?php echo ws_t('EINST.L_FRITZ_ADR'); ?></label>
    <input data-role="none" type="text" name="fritzbox" value="<?= ws_e(ws_cfg($ws_cfg, 'BASE.FRITZBOX', 'fritz.box')) ?>">
</div>
<div>
    <label><?php echo ws_t('EINST.L_FRITZ_PORT'); ?></label>
    <input data-role="none" type="number" name="fritzbox_port" value="<?= ws_e(ws_cfg($ws_cfg, 'BASE.FRITZBOX_PORT', '49443')) ?>">
</div>
</div>

<div class="sm-row">
<div>
    <label><?php echo ws_t('EINST.L_BEFEHL'); ?></label>
    <select data-role="none" name="ping_cmd">
        <option value="0" <?= (string) ws_cfg($ws_cfg, 'BASE.PING_CMD', '0') === '0' ? 'selected' : '' ?>><?php echo ws_t('EINST.OPT_ARPING'); ?></option>
        <option value="1" <?= (string) ws_cfg($ws_cfg, 'BASE.PING_CMD', '0') === '1' ? 'selected' : '' ?>><?php echo ws_t('EINST.OPT_PING'); ?></option>
    </select>
</div>
<div>
    <label class="sm-check" style="margin-top:34px;"><input data-role="none" type="checkbox" name="use_cache" value="1" <?= ws_cfg($ws_cfg, 'BASE.USE_CACHE', '1') === '1' ? 'checked' : '' ?>> <?php echo ws_t('EINST.L_CACHE'); ?></label>
    <div class="sm-small"><?php echo ws_t('EINST.HINT_CACHE'); ?></div>
</div>
</div>

<h2><?php echo ws_t('EINST.H_WEG'); ?></h2>
<div class="sm-row">
<div>
    <label><?php echo ws_t('EINST.L_UEBERTRAGUNG'); ?></label>
    <select data-role="none" name="out_way">
        <option value="mqtt" <?= ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') !== '1' ? 'selected' : '' ?>><?php echo ws_t('EINST.OPT_MQTT'); ?></option>
        <option value="udp" <?= ws_cfg($ws_cfg, 'BASE.UDP_ENABLE', '0') === '1' ? 'selected' : '' ?>><?php echo ws_t('EINST.OPT_UDP'); ?></option>
    </select>
    <div class="sm-small"><?php echo ws_t('EINST.HINT_UEBERTRAGUNG'); ?></div>
</div>
<div>
    <label><?php echo ws_t('EINST.L_UDPPORT'); ?></label>
    <input data-role="none" type="number" name="udpport" value="<?= ws_e(ws_cfg($ws_cfg, 'BASE.PORT', '7007')) ?>">
</div>
</div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?php echo ws_t('ALLG.SPEICHERN'); ?></button>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo ws_t('LEGENDE.SPEICHERN'); ?></span>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $ws_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<h2><?php echo ws_t('REITER.LOXONE'); ?></h2>
<div class="sm-small">
<?php echo ws_t('LOX.EINLEITUNG'); ?>
</div>

<div class="sm-step"><b><?php echo ws_t('LOX.S1_TITEL'); ?></b><br>
<?php printf(ws_t('LOX.S1_TEXT'), '<span class="sm-mono">MQTT Gateway</span>'); ?>
<?php $ws_broker = ws_mqtt_broker(); ?>
<?php if ($ws_broker !== '') { ?>
<?php echo ws_t('LOX.S1_BROKER'); ?>: <span class="sm-mono"><?= ws_e($ws_broker) ?></span>.
<?php } else { ?>
<b><?php echo ws_t('LOX.S1_KEIN_BROKER'); ?></b>
<?php } ?>
<?php printf(ws_t('LOX.S1_ABO'), '<span class="sm-mono">wifi_ng/#</span>',
      '<span class="sm-mono">config/mqtt_subscriptions.cfg</span>'); ?>
</div>

<div class="sm-step"><b><?php echo ws_t('LOX.S2_TITEL'); ?></b><br>
<?php echo ws_t('LOX.S2_TEXT'); ?>
<table class="sm-tbl">
<tr><th><?php echo ws_t('ALLG.THEMA'); ?></th><th><?php echo ws_t('ALLG.BEDEUTUNG'); ?></th><th><?php echo ws_t('ALLG.WERTE'); ?></th></tr>
<?php foreach (ws_users($ws_cfg) as $u) { ?>
<tr><td class="sm-mono">wifi_ng/<?= ws_e(ws_topic_name($u['name'])) ?></td><td><?php echo ws_t('LOX.ANWESENHEIT'); ?> <?= ws_e($u['name']) ?></td><td>0 / 1</td></tr>
<?php } ?>
<tr><td class="sm-mono">wifi_ng/status/mode</td><td><?php echo ws_t('LOX.T_MODE'); ?></td><td><?php echo ws_t('LOX.V_MODE'); ?></td></tr>
<tr><td class="sm-mono">wifi_ng/status/interval</td><td><?php echo ws_t('LOX.T_INTERVAL'); ?></td><td><?php echo ws_t('ALLG.MINUTEN'); ?></td></tr>
<tr><td class="sm-mono">wifi_ng/status/enabled</td><td><?php echo ws_t('LOX.T_ENABLED'); ?></td><td>0 / 1</td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo ws_t('LOX.S3_TITEL'); ?></b><br>
<?php echo ws_t('LOX.S3_TEXT'); ?>
<table class="sm-tbl">
<tr><th><?php echo ws_t('ALLG.THEMA'); ?></th><th><?php echo ws_t('LOX.SP_NUTZLAST'); ?></th><th><?php echo ws_t('LOX.SP_WIRKUNG'); ?></th></tr>
<tr><td class="sm-mono">wifi_ng/cmd/scan</td><td><?php echo ws_t('LOX.N_SCAN'); ?></td><td><?php echo ws_t('LOX.W_SCAN'); ?></td></tr>
<tr><td class="sm-mono">wifi_ng/cmd/mode</td><td>0/both, 1/fritzbox, 2/ping</td><td><?php echo ws_t('LOX.W_MODE'); ?></td></tr>
<tr><td class="sm-mono">wifi_ng/cmd/interval</td><td>1, 3, 5, 10, 15, 30, 60</td><td><?php echo ws_t('LOX.W_INTERVAL'); ?></td></tr>
<tr><td class="sm-mono">wifi_ng/cmd/enable</td><td>0 <?php echo ws_t('ALLG.ODER'); ?> 1</td><td><?php echo ws_t('LOX.W_ENABLE'); ?></td></tr>
</table>
<?php printf(ws_t('LOX.S3_FUSS'), '<span class="sm-mono">mqtt_listener.pl</span>'); ?>
</div>

<div class="sm-step"><b><?php echo ws_t('LOX.S4_TITEL'); ?></b><br>
<?php echo ws_t('LOX.S4_TEXT'); ?>
</div>

<div class="sm-step"><b><?php echo ws_t('LOX.S5_TITEL'); ?></b><br>
<?php printf(ws_t('LOX.S5_TEXT'),
    '<span class="sm-mono">Name:0</span>', '<span class="sm-mono">Name:1</span>',
    '<span class="sm-mono">' . ws_e(ws_cfg($ws_cfg, 'BASE.PORT', '7007')) . '</span>',
    '<span class="sm-mono">Name:\v</span>'); ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?php echo $ws_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<h2><?php echo ws_t('REITER.TEST'); ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo ws_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo ws_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo ws_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo ws_t('TEST.H_ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="ws_test.php?status" target="_blank"><?php echo ws_t('TEST.K_STATUS'); ?></a>
<a class="sm-btn sm-b-lesen" href="ws_test.php?topics" target="_blank"><?php echo ws_t('TEST.K_THEMEN'); ?></a>
</div>

<h3 class="sm-h3"><?php echo ws_t('TEST.H_TECHNIK'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="ws_test.php?diag" target="_blank"><?php echo ws_t('TEST.K_DIAG'); ?></a>
<a class="sm-btn sm-b-technik" href="ws_test.php?config" target="_blank"><?php echo ws_t('TEST.K_CONFIG'); ?></a>
</div>

<h3 class="sm-h3"><?php echo ws_t('TEST.H_AKTION'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion" href="ws_test.php?scan" target="_blank"><?php echo ws_t('TEST.K_SCAN'); ?></a>
<a class="sm-btn sm-b-aktion" href="ws_test.php?restart" target="_blank"><?php echo ws_t('TEST.K_RESTART'); ?></a>
</div>

<div class="sm-small" style="margin-top:14px;">
<?php echo ws_t('TEST.ERKLAERUNG'); ?>
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-pane<?php echo $ws_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo ws_t('LOG.H_TITEL'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;">
<?php echo ws_t('LOG.HINT_OBEN'); ?>
<?php echo ws_t('ALLG.DATEI'); ?>: <span class="sm-mono"><?= $ws_log_file !== '' ? ws_e($ws_log_file) : ws_t('LOG.KEINE_DATEI') ?></span><br>
<?php echo ws_t('LOG.HINT_LOGLEVEL'); ?>
<?php if ($ws_listener_log !== '') { ?><br><?php printf(ws_t('LOG.HINT_LISTENER'), '<span class="sm-mono">' . ws_e($ws_listener_log) . '</span>'); ?><?php } ?>
</div>
<?php if ($ws_log_lines) { ?>
<div class="sm-log"><?= ws_e(implode("\n", $ws_log_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo ws_t('LOG.KEINE_EINTRAEGE'); ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($ws_tab) ?>);
})();
</script>
<?php
if ($ws_frame) {
    LBWeb::lbfooter();
}
