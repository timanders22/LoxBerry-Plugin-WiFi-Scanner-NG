<?php
/**
 * WiFi Scanner NG - Bruecke vom Dienst zur gemeinsamen Sprachausgabe (seit 3.2.17).
 * Aufruf: php ws_ansage.php <anlass> <0|1> <pluginordner> [name] [frist]. Anlaesse: 'ausfall'. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$ws_a = isset($argv[1]) ? (string) $argv[1] : '';
$ws_g = isset($argv[2]) ? (string) $argv[2] : '';
$ws_o = isset($argv[3]) ? (string) $argv[3] : '';
$ws_n = isset($argv[4]) ? (string) $argv[4] : '';
$ws_f = isset($argv[5]) ? (int) $argv[5] : 0;
if (!in_array($ws_a, array('ausfall'), true) || ($ws_g !== '0' && $ws_g !== '1') || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $ws_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$ws_h = getenv('LBHOMEDIR');
if (!$ws_h || !is_dir($ws_h . '/config/plugins')) {
    $ws_h = dirname(dirname(dirname(__DIR__)));
}
$ws_lib = $ws_h . '/webfrontend/html/plugins/' . $ws_o . '/ws_lib.php';
if (!is_file($ws_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $ws_h);
putenv('LBPPLUGINDIR=' . $ws_o);
require_once $ws_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) ws_ansage_flanke($ws_a, $ws_g === '1', $ws_n, $ws_f)) . "\n";
exit(0);
