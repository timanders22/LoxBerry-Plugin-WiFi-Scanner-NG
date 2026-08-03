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
$mode = ($fritz === '1' && $active === '1') ? '0 (Fritz!Box + aktiver Scan)'
      : (($fritz === '1') ? '1 (nur Fritz!Box)' : '2 (nur aktiver Scan)');

/* ---------- Sofort-Scan ---------- */
if (isset($_GET['scan'])) {
    $bin = $p['bindir'] . '/check.pl';
    if (!is_file($bin)) {
        echo "SCAN;OK=0;GRUND=check.pl nicht gefunden ($bin)\n";
        exit;
    }
    @exec('nohup ' . escapeshellarg($bin) . ' > /dev/null 2>&1 &');
    echo "SCAN;OK=1\n\nDer Scan laeuft im Hintergrund. Das Ergebnis steht ein paar Sekunden\n";
    echo "spaeter im Reiter Logdateien und auf den MQTT-Themen.\n";
    exit;
}

/* ---------- Listener neu starten ---------- */
if (isset($_GET['restart'])) {
    $bin = $p['bindir'] . '/mqtt_listener.pl';
    if (!is_file($bin)) {
        echo "RESTART;OK=0;GRUND=mqtt_listener.pl nicht gefunden ($bin)\n";
        exit;
    }
    @exec('pkill -f mqtt_listener.pl 2>/dev/null');
    usleep(400000);
    @exec('nohup ' . escapeshellarg($bin) . ' > /dev/null 2>&1 &');
    usleep(800000);
    $pid = ws_listener_running();
    echo 'RESTART;OK=' . ($pid ? 1 : 0) . ';PID=' . $pid . "\n";
    if (!$pid) {
        echo "\nDer Listener laeuft nicht. Haeufigste Ursache: auf diesem LoxBerry ist\n";
        echo "kein MQTT Gateway eingerichtet - dann beendet sich der Listener sofort.\n";
        echo "Einzelheiten stehen im Log 'mqtt_listener'.\n";
    }
    exit;
}

/* ---------- MQTT-Themen ---------- */
if (isset($_GET['topics'])) {
    echo "MQTT-Themen des Plugins\n=======================\n\n";
    echo "Anwesenheit (retained, 0 = abwesend, 1 = anwesend):\n";
    if (!$users) {
        echo "  (noch keine Personen angelegt)\n";
    }
    foreach ($users as $u) {
        printf("  wifiscanner/%-24s   [%s]\n", ws_topic_name($u['name']), $u['name']);
    }
    echo "\nZustand (retained):\n";
    echo "  wifiscanner/status/mode        0 = beides, 1 = nur Fritz!Box, 2 = nur Scan\n";
    echo "  wifiscanner/status/interval    Scan-Intervall in Minuten\n";
    echo "  wifiscanner/status/enabled     0/1 periodisches Scannen\n";
    echo "\nBefehle aus Loxone (bitte NICHT retained senden):\n";
    echo "  wifiscanner/cmd/scan           leer oder Modus - loest einen Scan aus\n";
    echo "  wifiscanner/cmd/mode           0/both, 1/fritzbox, 2/ping\n";
    echo "  wifiscanner/cmd/interval       1, 3, 5, 10, 15, 30, 60\n";
    echo "  wifiscanner/cmd/enable         0 oder 1\n";
    exit;
}

/* ---------- Konfiguration ---------- */
if (isset($_GET['config'])) {
    echo "Konfiguration\n=============\nDatei: " . $p['config'] . "\n\n";
    foreach ($cfg as $k => $v) {
        printf("%-26s = %s\n", $k, $v);
    }
    echo "\nHinweis: Das Plugin speichert keine Zugangsdaten. Die Fritz!Box wird\n";
    echo "ueber TR-064 ohne Anmeldung nach Host-Eintraegen gefragt.\n";
    exit;
}

/* ---------- Selbsttest ---------- */
if (isset($_GET['diag'])) {
    echo "WIFISCANNER-DIAGNOSE\n====================\n\n";

    $cron = ws_cron_current();
    echo '1. Periodischer Scan: ' . (ws_cfg($cfg, 'BASE.ENABLED', '0') === '1' ? 'eingeschaltet' : 'AUS') . "\n";
    echo '   Cron-Verknuepfung:  ' . ($cron !== '' ? $cron : 'KEINE gefunden') . "\n";
    echo '   Eingestellt:        alle ' . ws_cfg($cfg, 'BASE.CRON', '?') . " Minuten\n\n";

    $pid = ws_listener_running();
    echo '2. MQTT-Listener:      ' . ($pid ? 'laeuft (PID ' . $pid . ')' : 'LAEUFT NICHT') . "\n";
    $broker = ws_mqtt_broker();
    echo '   MQTT Gateway:       ' . ($broker !== '' ? $broker : 'nicht gefunden') . "\n\n";

    echo "3. Werkzeuge:\n";
    foreach (array('/usr/sbin/arping', '/usr/sbin/arp', '/usr/sbin/arp-scan', '/bin/ping') as $t) {
        echo '   ' . str_pad($t, 22) . (is_executable($t) ? 'vorhanden' : 'FEHLT') . "\n";
    }

    echo "\n4. Perl-Module:\n";
    foreach (array('Net::MQTT::Simple', 'Data::Validate::IP', 'Capture::Tiny', 'Config::Simple', 'XML::Simple', 'LWP::UserAgent') as $m) {
        $rc = 1;
        @exec('perl -M' . escapeshellarg($m) . ' -e 1 2>/dev/null', $dummy, $rc);
        echo '   ' . str_pad($m, 22) . ($rc === 0 ? 'vorhanden' : 'FEHLT') . "\n";
    }

    echo "\n5. Personen: " . count($users) . "\n";
    foreach ($users as $u) {
        $eintraege = array_filter(array_map('trim', explode(';', $u['macs'])));
        echo '   ' . str_pad($u['name'], 22) . count($eintraege) . " Adresse(n) -> wifiscanner/" . ws_topic_name($u['name']) . "\n";
    }

    echo "\nHinweise:\n";
    echo "- Fehlt die Cron-Verknuepfung, obwohl der periodische Scan eingeschaltet ist:\n";
    echo "  einmal im Reiter Einstellungen speichern, das legt sie neu an.\n";
    echo "- Laeuft der Listener nicht, fehlt meist das MQTT Gateway. Ohne Gateway\n";
    echo "  funktionieren Anwesenheitserkennung und Cron trotzdem, nur die Steuerung\n";
    echo "  aus Loxone nicht.\n";
    echo "- arping braucht Rootrechte; die Regel dafuer liegt in sudoers/sudoers.\n";
    exit;
}

/* ---------- Status (Vorgabe) ---------- */
echo "WifiScanner - Status\n====================\n\n";
echo 'Periodischer Scan: ' . (ws_cfg($cfg, 'BASE.ENABLED', '0') === '1' ? 'ein' : 'aus')
    . ', alle ' . ws_cfg($cfg, 'BASE.CRON', '?') . " Minuten\n";
echo 'Abfrage-Modus:     ' . $mode . "\n";
echo 'Fritz!Box:         ' . ws_cfg($cfg, 'BASE.FRITZBOX', '-') . ':' . ws_cfg($cfg, 'BASE.FRITZBOX_PORT', '-') . "\n";
echo 'Weg zu Loxone:     ' . (ws_cfg($cfg, 'BASE.UDP_ENABLE', '0') === '1'
        ? 'UDP an Port ' . ws_cfg($cfg, 'BASE.PORT', '-') : 'MQTT') . "\n";
echo 'MQTT-Listener:     ' . (ws_listener_running() ? 'laeuft' : 'laeuft nicht') . "\n";
echo 'Cron:              ' . (ws_cron_current() !== '' ? ws_cron_current() : 'keine Verknuepfung') . "\n\n";
echo 'Personen (' . count($users) . "):\n";
foreach ($users as $u) {
    echo '  ' . str_pad($u['name'], 24) . $u['macs'] . "\n";
}
