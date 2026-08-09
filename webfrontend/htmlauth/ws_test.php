<?php
/**
 * WifiScanner - Testseite hinter der Anmeldung
 *
 * Absichtlich unter htmlauth: Konfiguration, Cron- und Listener-Zustand
 * gehoeren nicht in den ungeschuetzten Bereich.
 *
 * Aufrufe:
 *   ?status   -> Kurzfassung: Modus, Intervall, Nutzer, MQTT-Themen
 *   ?topics   -> nur die MQTT-Themen, zum Abgleich mit dem MQTT Finder
 *   ?config   -> Konfiguration im Klartext (ohne Zugangsdaten)
 *   ?diag     -> Selbsttest: Cron, Listener, Broker, Werkzeuge, Perl-Module
 *   ?scan     -> Sofort-Scan starten
 *   ?restart  -> MQTT-Listener neu starten
 */

require_once __DIR__ . '/ws_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$p = ws_paths();
$cfg = ws_config_read();
$users = ws_users($cfg);

$fritz  = (string) ws_cfg($cfg, 'BASE.FRITZBOX_ENABLE', '0');
$active = (string) ws_cfg($cfg, 'BASE.ACTIVE_SCAN', '0');
$mode = ($fritz === '1' && $active === '1') ? ws_t('T.M_BEIDES')
      : (($fritz === '1') ? ws_t('T.M_FRITZ') : ws_t('T.M_SCAN'));

/* ---------- Sofort-Scan ---------- */
if (isset($_GET['scan'])) {
    $bin = $p['bindir'] . '/check.pl';
    if (!is_file($bin)) {
        echo 'SCAN;OK=0;GRUND=' . sprintf(ws_t('T.NICHT_GEFUNDEN'), 'check.pl', $bin) . "\n";
        exit;
    }
    @exec('nohup perl ' . escapeshellarg($bin) . ' > /dev/null 2>&1 &');
    echo "SCAN;OK=1\n\n" . ws_t('T.SCAN_LAEUFT') . "\n";
    exit;
}

/* ---------- Listener neu starten ---------- */
if (isset($_GET['restart'])) {
    $bin = $p['bindir'] . '/mqtt_listener.pl';
    if (!is_file($bin)) {
        echo 'RESTART;OK=0;GRUND=' . sprintf(ws_t('T.NICHT_GEFUNDEN'), 'mqtt_listener.pl', $bin) . "\n";
        exit;
    }
    ws_listener_stop();
    usleep(400000);
    @exec('nohup perl ' . escapeshellarg($bin) . ' > /dev/null 2>&1 &');
    usleep(800000);
    $pid = ws_listener_running();
    echo 'RESTART;OK=' . ($pid ? 1 : 0) . ';PID=' . $pid . "\n";
    if (!$pid) {
        echo "\n" . ws_t('T.LISTENER_TOT') . "\n";
    }
    exit;
}

/* ---------- MQTT-Themen ---------- */
if (isset($_GET['topics'])) {
    $ws_ueber = ws_t('T.H_THEMEN');
    echo $ws_ueber . "\n" . str_repeat('=', strlen(strip_tags($ws_ueber))) . "\n\n";
    echo ws_t('T.THEMEN_ANWESENHEIT') . "\n";
    if (!$users) {
        echo '  (' . ws_t('T.KEINE_PERSONEN') . ")\n";
    }
    foreach ($users as $u) {
        printf("  wifi_ng/%-24s   [%s]\n", ws_topic_name($u['name']), $u['name']);
    }
    echo "\n" . ws_t('T.THEMEN_ZUSTAND') . "\n";
    printf("  %-30s %s\n", 'wifi_ng/status/mode',     ws_t('T.V_MODE'));
    printf("  %-30s %s\n", 'wifi_ng/status/interval', ws_t('T.V_INTERVAL'));
    printf("  %-30s %s\n", 'wifi_ng/status/enabled',  ws_t('T.V_ENABLED'));
    echo "\n" . ws_t('T.THEMEN_BEFEHLE') . "\n";
    printf("  %-30s %s\n", 'wifi_ng/cmd/scan',     ws_t('T.C_SCAN'));
    printf("  %-30s %s\n", 'wifi_ng/cmd/mode',     '0/both, 1/fritzbox, 2/ping');
    printf("  %-30s %s\n", 'wifi_ng/cmd/interval', '1, 3, 5, 10, 15, 30, 60');
    printf("  %-30s %s\n", 'wifi_ng/cmd/enable',   '0 / 1');
    exit;
}

/* ---------- Konfiguration ---------- */
if (isset($_GET['config'])) {
    echo ws_t('T.H_KONFIG') . "\n" . str_repeat('=', strlen(ws_t('T.H_KONFIG'))) . "\n"
       . ws_t('T.DATEI') . ': ' . $p['config'] . "\n\n";
    foreach ($cfg as $k => $v) {
        printf("%-26s = %s\n", $k, $v);
    }
    echo "\n" . ws_t('T.KONFIG_HINWEIS') . "\n";
    exit;
}

/* ---------- Selbsttest ---------- */
if (isset($_GET['diag'])) {
    echo ws_t('T.H_DIAG') . "\n" . str_repeat('=', strlen(ws_t('T.H_DIAG'))) . "\n\n";

    $cron = ws_cron_current();
    printf("1. %-19s %s\n", ws_t('T.D_SCAN') . ':',
        ws_cfg($cfg, 'BASE.ENABLED', '0') === '1' ? ws_t('T.EINGESCHALTET') : ws_t('T.AUS_GROSS'));
    printf("   %-19s %s\n", ws_t('T.D_CRON') . ':', $cron !== '' ? $cron : ws_t('T.KEINE_GEFUNDEN'));
    printf("   %-19s %s\n\n", ws_t('T.D_EINGESTELLT') . ':',
        sprintf(ws_t('T.ALLE_MINUTEN'), ws_cfg($cfg, 'BASE.CRON', '?')));

    $pid = ws_listener_running();
    printf("2. %-19s %s\n", ws_t('T.D_LISTENER') . ':',
        $pid ? sprintf(ws_t('T.LAEUFT_PID'), $pid) : ws_t('T.LAEUFT_NICHT_GROSS'));
    $broker = ws_mqtt_broker();
    printf("   %-19s %s\n\n", 'MQTT Gateway:', $broker !== '' ? $broker : ws_t('T.NICHT_GEFUNDEN_KURZ'));

    echo '3. ' . ws_t('T.D_WERKZEUGE') . ":\n";
    foreach (array('/usr/sbin/arping', '/usr/sbin/arp', '/usr/sbin/arp-scan', '/bin/ping') as $t) {
        echo '   ' . str_pad($t, 22) . (is_executable($t) ? ws_t('T.VORHANDEN') : ws_t('T.FEHLT')) . "\n";
    }

    echo "\n4. " . ws_t('T.D_MODULE') . ":\n";
    foreach (array('Net::MQTT::Simple', 'Data::Validate::IP', 'Capture::Tiny', 'Config::Simple', 'XML::Simple', 'LWP::UserAgent') as $m) {
        $rc = 1;
        // exec() HAENGT an das Feld an, es ersetzt es nicht. Ohne das
        // Zuruecksetzen waechst $dummy mit jedem geprueften Modul weiter -
        // hier folgenlos, weil nur $rc benutzt wird, aber es liest sich wie
        // ein Fehler und waere in der naechsten Fassung einer.
        $dummy = array();
        @exec('perl -M' . escapeshellarg($m) . ' -e 1 2>/dev/null', $dummy, $rc);
        echo '   ' . str_pad($m, 22) . ($rc === 0 ? ws_t('T.VORHANDEN') : ws_t('T.FEHLT')) . "\n";
    }

    echo "\n5. " . ws_t('T.D_PERSONEN') . ': ' . count($users) . "\n";
    foreach ($users as $u) {
        $eintraege = array_filter(array_map('trim', explode(';', $u['macs'])));
        echo '   ' . str_pad($u['name'], 22)
           . sprintf(ws_t('T.N_ADRESSEN'), count($eintraege))
           . ' -> wifi_ng/' . ws_topic_name($u['name']) . "\n";
    }

    echo "\n" . ws_t('T.H_HINWEISE') . ":\n";
    echo '- ' . ws_t('T.HINW_CRON') . "\n";
    echo '- ' . ws_t('T.HINW_LISTENER') . "\n";
    echo '- ' . ws_t('T.HINW_ARPING') . "\n";
    exit;
}

/* ---------- Status (Vorgabe) ---------- */
echo ws_t('T.H_STATUS') . "\n" . str_repeat('=', strlen(ws_t('T.H_STATUS'))) . "\n\n";
printf("%-18s %s, %s\n", ws_t('T.D_SCAN') . ':',
    ws_cfg($cfg, 'BASE.ENABLED', '0') === '1' ? ws_t('T.EIN') : ws_t('T.AUS'),
    sprintf(ws_t('T.ALLE_MINUTEN'), ws_cfg($cfg, 'BASE.CRON', '?')));
printf("%-18s %s\n", ws_t('T.D_MODUS') . ':', $mode);
printf("%-18s %s\n", 'Fritz!Box:', ws_cfg($cfg, 'BASE.FRITZBOX', '-') . ':' . ws_cfg($cfg, 'BASE.FRITZBOX_PORT', '-'));
printf("%-18s %s\n", ws_t('T.D_WEG') . ':', ws_cfg($cfg, 'BASE.UDP_ENABLE', '0') === '1'
    ? sprintf(ws_t('T.UDP_AN_PORT'), ws_cfg($cfg, 'BASE.PORT', '-')) : 'MQTT');
printf("%-18s %s\n", ws_t('T.D_LISTENER') . ':',
    ws_listener_running() ? ws_t('T.LAEUFT') : ws_t('T.LAEUFT_NICHT'));
printf("%-18s %s\n\n", 'Cron:',
    ws_cron_current() !== '' ? ws_cron_current() : ws_t('T.KEINE_VERKNUEPFUNG'));
echo ws_t('T.D_PERSONEN') . ' (' . count($users) . "):\n";
foreach ($users as $u) {
    echo '  ' . str_pad($u['name'], 24) . $u['macs'] . "\n";
}
