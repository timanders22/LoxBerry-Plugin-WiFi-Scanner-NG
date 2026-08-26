<?php
/**
 * WifiScanner NG - Testseite hinter der Anmeldung
 *
 * Absichtlich unter htmlauth: Konfiguration, Cron- und Listener-Zustand
 * gehoeren nicht in den ungeschuetzten Bereich.
 *
 * Aufrufe:
 *   ?status   -> Kurzfassung: Modus, Intervall, Nutzer, MQTT-Themen
 *   ?topics   -> nur die MQTT-Themen, zum Abgleich mit dem MQTT Finder
 *   ?config   -> Konfiguration im Klartext (Geheimnisse maskiert)
 *   ?diag     -> Selbsttest: Cron, Listener, Broker, Werkzeuge, Perl-Module
 *
 * WAS HIER SEIT 3.1.12 NICHT MEHR STEHT: ?scan und ?restart.
 *
 * Beide loesten etwas aus - einen Suchlauf und einen Dienstneustart - und
 * beide waren als <a href> verlinkt. Ein <img src=".../ws_test.php?restart">
 * auf einer beliebigen fremden Seite genuegte, um sie auszuloesen, solange
 * der Bediener am LoxBerry angemeldet war: HTTP-Basic geht automatisch mit,
 * und SameSite greift dabei nicht. Die beiden Knoepfe sitzen jetzt im Reiter
 * Test der Oberflaeche und schicken einen POST mit Formularmerkmal.
 *
 * Diese Datei fragt nur noch ab. Sie loest nichts aus und schreibt nichts.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

/* Die Bibliothek liegt seit 3.1.12 im anderen Baum - dieselbe
 * Kandidatenliste wie in index.php. */
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
header('Content-Type: text/plain; charset=utf-8');
if ($ws_gefunden === '') {
    http_response_code(500);
    echo "ws_lib.php nicht gefunden.\nGesucht wurde in:\n  "
       . implode("\n  ", $ws_kandidaten) . "\n";
    exit;
}
require_once $ws_gefunden;

$p = ws_paths();
$cfg = ws_config_read(false);
$users = ws_users($cfg);
$zustand = ws_zustand_lesen();
$alter = ws_zustand_alter($zustand);
$frisch = ws_zustand_frisch($cfg, $zustand);

$fritz  = (string) ws_cfg($cfg, 'BASE.FRITZBOX_ENABLE', '0');
$active = (string) ws_cfg($cfg, 'BASE.ACTIVE_SCAN', '0');
$mode = ($fritz === '1' && $active === '1') ? ws_t('T.M_BEIDES')
      : (($fritz === '1') ? ws_t('T.M_FRITZ') : ws_t('T.M_SCAN'));

/** Eine Ueberschrift unterstreichen. strlen zaehlt Bytes - bei Umlauten
 *  waere die Linie zu lang, deshalb die Zeichenzahl in UTF-8. */
function ws_strich($text)
{
    $n = preg_match_all('/./u', (string) $text);
    return (string) $text . "\n" . str_repeat('=', $n > 0 ? $n : strlen((string) $text)) . "\n";
}

/* ---------- MQTT-Themen ---------- */
if (isset($_GET['topics'])) {
    echo ws_strich(ws_t('T.H_THEMEN')) . "\n";
    echo ws_t('T.THEMEN_ANWESENHEIT') . "\n";
    if (!$users) {
        echo '  (' . ws_t('T.KEINE_PERSONEN') . ")\n";
    }
    foreach ($users as $u) {
        printf("  wifi_ng/%-24s   [%s]\n", ws_topic_name($u['name']), $u['name']);
    }
    echo "\n" . ws_t('T.THEMEN_ZUSTAND') . "\n";
    foreach (ws_themen($cfg) as $th) {
        if (strpos($th[0], '/status/') === false) { continue; }
        printf("  %-30s %s\n", $th[0], $th[1]);
    }
    echo "\n" . ws_t('T.THEMEN_BEFEHLE') . "\n";
    foreach (ws_befehle() as $bf) {
        printf("  %-30s %s\n", $bf[0], $bf[1]);
    }
    exit;
}

/* ---------- Konfiguration ----------
 * Die Geheimnisse werden maskiert. Die FORM eines Geheimnisses darf
 * beurteilt werden - "steht ueberhaupt etwas darin, und ist es so lang wie
 * erwartet" -, der Wert nie angezeigt. Bis 3.1.11 gab es hier nichts zu
 * maskieren, weil das Plugin keine Zugangsdaten kannte; seit 3.1.12 stehen
 * das Merkwort und das Fritz!Box-Kennwort in derselben Datei. */
if (isset($_GET['config'])) {
    echo ws_strich(ws_t('T.H_KONFIG')) . ws_t('T.DATEI') . ': ' . $p['config'] . "\n\n";
    $geheim = ws_geheime_schluessel();
    foreach ($cfg as $k => $v) {
        printf("%-26s = %s\n", $k, in_array($k, $geheim, true) ? ws_maskieren($v) : $v);
    }
    echo "\n" . ws_t('T.KONFIG_HINWEIS') . "\n";
    exit;
}

/* ---------- Selbsttest ---------- */
if (isset($_GET['diag'])) {
    echo ws_strich(ws_t('T.H_DIAG')) . "\n";

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
    printf("   %-19s %s\n", 'MQTT Gateway:', $broker !== '' ? $broker : ws_t('T.NICHT_GEFUNDEN_KURZ'));
    $g = ws_mqtt_gateway_info();
    printf("   %-19s %s\n", ws_t('T.D_AUTOSTART') . ':',
        $g === null ? ws_t('ALLG.UNBEKANNT') : ($g['autostart'] ? ws_t('ALLG.EIN') : ws_t('ALLG.AUS')));
    printf("   %-19s %s\n\n", ws_t('T.D_FASSUNG') . ':',
        ws_mqtt_fassung() > 0 ? 'V' . ws_mqtt_fassung() : ws_t('ALLG.UNBEKANNT'));

    /* Der letzte Lauf. Das Alter wird zur LESEZEIT gerechnet, nicht beim
     * Schreiben eingefroren - ein festgehaltenes "Alter 0" kann einen toten
     * Dienst nicht von einer frischen Messung unterscheiden. */
    printf("3. %-19s %s\n", ws_t('T.D_LETZTER') . ':',
        $alter < 0 ? ws_t('T.NIE_GELAUFEN')
                   : sprintf(ws_t('T.VOR_MINUTEN'), (int) round($alter / 60))
                     . ' - ' . ($frisch ? ws_t('T.FRISCH') : ws_t('T.ZU_ALT')));
    printf("   %-19s %s\n", ws_t('T.D_ERGEBNIS') . ':',
        $zustand['ok'] === 1 ? ws_t('T.LAUF_OK')
                             : ($zustand['ok'] === 0 ? ws_t('T.LAUF_STOERUNG') : ws_t('T.LAUF_UNBEKANNT')));
    if ($zustand['fehler'] !== '') {
        printf("   %-19s %s\n", ws_t('ALLG.FEHLER'), $zustand['fehler']);
    }
    echo "\n";

    echo '4. ' . ws_t('T.D_WERKZEUGE') . ":\n";
    foreach (array('/usr/sbin/arping', '/usr/sbin/arp', '/usr/sbin/arp-scan', '/bin/ping') as $t) {
        echo '   ' . str_pad($t, 22) . (is_executable($t) ? ws_t('T.VORHANDEN') : ws_t('T.FEHLT')) . "\n";
    }

    echo "\n5. " . ws_t('T.D_MODULE') . ":\n";
    /* Die Liste ist die, die check.pl und mqtt_listener.pl wirklich laden.
     * Bis 3.1.11 fehlte hier File::HomeDir - und in dpkg/apt fehlte das
     * zugehoerige Paket. Beides ist in 3.1.12 aufgeloest: check.pl laedt das
     * Modul nicht mehr, weil es es nie benutzt hat. */
    if (!function_exists('exec')) {
        echo '   ' . ws_t('T.MODULE_KEIN_EXEC') . "\n";
    } else {
        foreach (array('Net::MQTT::Simple', 'Data::Validate::IP', 'Capture::Tiny',
                       'Config::Simple', 'XML::Simple', 'LWP::UserAgent', 'JSON') as $m) {
            $rc = 1;
            // exec() HAENGT an das Feld an, es ersetzt es nicht. Ohne das
            // Zuruecksetzen waechst $dummy mit jedem geprueften Modul weiter -
            // hier folgenlos, weil nur $rc benutzt wird, aber es liest sich wie
            // ein Fehler und waere in der naechsten Fassung einer.
            $dummy = array();
            @exec('perl -M' . escapeshellarg($m) . ' -e 1 2>/dev/null', $dummy, $rc);
            echo '   ' . str_pad($m, 22) . ($rc === 0 ? ws_t('T.VORHANDEN') : ws_t('T.FEHLT')) . "\n";
        }
    }

    echo "\n6. " . ws_t('T.D_PERSONEN') . ': ' . count($users) . "\n";
    foreach ($users as $u) {
        list($gut, $schlecht) = ws_adressen_zerlegen($u['macs']);
        $z = -1;
        if ($frisch) {
            foreach ($zustand['personen'] as $pz) {
                if (isset($pz['name']) && ws_topic_name($pz['name']) === ws_topic_name($u['name'])) {
                    $z = isset($pz['online']) ? (int) $pz['online'] : -1;
                }
            }
        }
        echo '   ' . str_pad($u['name'], 22)
           . sprintf(ws_t('T.N_ADRESSEN'), count($gut))
           . ($schlecht ? '  [' . ws_t('T.ABGEWIESEN') . ': ' . implode(' ', $schlecht) . ']' : '')
           . '  ' . ($z === 1 ? ws_t('T.Z_DA') : ($z === 0 ? ws_t('T.Z_WEG') : '?'))
           . ' -> wifi_ng/' . ws_topic_name($u['name']) . "\n";
    }
    $dopp = ws_doppelte_adressen($users);
    if ($dopp) {
        echo "\n   " . ws_t('T.D_DOPPELT') . ":\n";
        foreach ($dopp as $adr => $wer) {
            echo '   ' . str_pad($adr, 22) . implode(', ', $wer) . "\n";
        }
    }

    echo "\n7. " . ws_t('T.D_ENDPUNKT') . ":\n";
    echo '   ' . (ws_token($cfg) !== '' ? ws_t('T.TOKEN_DA') : ws_t('T.TOKEN_FEHLT')) . "\n";

    echo "\n" . ws_t('T.H_HINWEISE') . ":\n";
    echo '- ' . ws_t('T.HINW_CRON') . "\n";
    echo '- ' . ws_t('T.HINW_LISTENER') . "\n";
    echo '- ' . ws_t('T.HINW_ARPING') . "\n";
    exit;
}

/* ---------- Status (Vorgabe) ---------- */
echo ws_strich(ws_t('T.H_STATUS')) . "\n";
printf("%-18s %s, %s\n", ws_t('T.D_SCAN') . ':',
    ws_cfg($cfg, 'BASE.ENABLED', '0') === '1' ? ws_t('T.EIN') : ws_t('T.AUS'),
    sprintf(ws_t('T.ALLE_MINUTEN'), ws_cfg($cfg, 'BASE.CRON', '?')));
printf("%-18s %s\n", ws_t('T.D_MODUS') . ':', $mode);
printf("%-18s %s\n", 'Fritz!Box:', ws_cfg($cfg, 'BASE.FRITZBOX', '-') . ':' . ws_cfg($cfg, 'BASE.FRITZBOX_PORT', '-')
    . (ws_cfg($cfg, 'BASE.FRITZBOX_USER', '') !== '' ? ' (' . ws_t('T.MIT_ANMELDUNG') . ')' : ''));
printf("%-18s %s\n", ws_t('T.D_WEG') . ':', ws_cfg($cfg, 'BASE.UDP_ENABLE', '0') === '1'
    ? sprintf(ws_t('T.UDP_AN_PORT'), ws_cfg($cfg, 'BASE.PORT', '-')) : 'MQTT');
printf("%-18s %s\n", ws_t('T.D_LISTENER') . ':',
    ws_listener_running() ? ws_t('T.LAEUFT') : ws_t('T.LAEUFT_NICHT'));
printf("%-18s %s\n", 'Cron:',
    ws_cron_current() !== '' ? ws_cron_current() : ws_t('T.KEINE_VERKNUEPFUNG'));
printf("%-18s %s\n\n", ws_t('T.D_LETZTER') . ':',
    $alter < 0 ? ws_t('T.NIE_GELAUFEN') : sprintf(ws_t('T.VOR_MINUTEN'), (int) round($alter / 60)));
echo ws_t('T.D_PERSONEN') . ' (' . count($users) . "):\n";
foreach ($users as $u) {
    echo '  ' . str_pad($u['name'], 24) . $u['macs'] . "\n";
}
