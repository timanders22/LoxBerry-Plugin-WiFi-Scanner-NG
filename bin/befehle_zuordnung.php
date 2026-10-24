<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie WiFi-Scanner-NG.
 *
 * Gruppe 14 / MQTT (3.2.15; Bauvorschrift wz_bild/GRUPPE14_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe14_ws.txt
 * Abschnitte 3.2-3.5, ENTSCHEIDUNGEN_GRUPPE14_WIFI.md E1, E3, E4; Muster vb_g12_db_bau/bin/befehle_zuordnung.php). Die
 * Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist
 * nur diese Datei. Sie ersetzt den eigenen Listener bin/mqtt_listener.pl (wifi_ng/cmd/#), der mit 3.2.15 entfaellt.
 *
 * Themen: wifi_ng/befehl/scan jetzt|beides|fritzbox|ping (Suchlauf; jetzt = eingestellter Suchweg) | modus beides|fritzbox|
 * ping (Suchweg dauerhaft) | takt 1|3|5|10|15|30|60 (Minuten) | suche 0|1 (regelmaessige Suche). Woerter statt Ziffern bei
 * scan/modus: der alte Listener las cmd/scan "1" als "nur Fritz!Box" - auf einem neuen Thema dieselbe Ziffer mit anderer
 * Bedeutung waere eine Falle.
 *
 * tun() ruft ws_bb_tun() in webfrontend/html/ws_lib.php: Upgrade-Marke, dann ws_steuern() - DIESELBE Logik wie der
 * HTTP-Endpunkt (Pruefung ueber ws_wert_pruefen(), Schreiben ueber ws_config_write() mit Zweitschrift, Zeitplan ohne
 * Kindprozess). Der Suchlauf geht ueber den kurzen Auftragsprozess bin/ws_auftrag.sh (schliesst geerbte Deskriptoren,
 * startet check.pl --auftrag losgeloest und endet) - er haengt nie an der Bruecke. Kein HTTP auf sich selbst.
 *
 * Haken: "Befehle ueber MQTT annehmen" (BEFEHLE_MQTT_EIN, ab Werk aus) schaltet die Bruecke, auch wenn die Werte ueber UDP
 * gehen (E4). suche ist eingreifend und braucht zusaetzlich "Eingreifende Befehle ueber MQTT annehmen"
 * (BEFEHLE_EINGREIFEND_EIN, ab Werk aus): "Suche aus" legt die Anwesenheit still (E3).
 *
 * Die Bibliothek liegt unter webfrontend/html/: installiert <wurzel>/webfrontend/html/plugins/<ordner>/ws_lib.php, im
 * Archiv bzw. Pruefstand ../webfrontend/html/ws_lib.php. ws_paths() erkennt "installiert" am eigenen Ablageort (W16) -
 * die Bruecke (Cron als loxberry, ohne LBPPLUGINDIR) bekommt die Anlagenpfade ohne Umgebung.
 */
if (!function_exists('ws_bb_tun')) {
    $ws_bb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ws_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ws_lib.php';
    }
    $ws_bb_kand[] = dirname(__DIR__) . '/webfrontend/html/ws_lib.php';
    foreach ($ws_bb_kand as $ws_bb_k) {
        if (is_file($ws_bb_k)) { require_once $ws_bb_k; break; }
    }
}
/* Ein Eintrag: Art, Titel, Kachel, Quittung. tun() reicht Name und Wert an ws_bb_tun(). */
$ws_bb_e = function ($name, $art, $titel, $kachel, $extra = array()) {
    return array_merge(array(
        'art' => $art, 'eingreifend' => false, 'titel' => 'WiFi-Scanner-NG ' . $titel . ' (MQTT)',
        'kachel' => $kachel, 'quittung' => 'ausgefuehrt',
        'tun' => function ($w, $t) use ($name) {
            return ws_bb_tun($name, (string) $w);
        }), $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'wifi_ng',
    'plugin'  => 'WiFi-Scanner-NG',
    'zweiter_haken' => 'befehle_eingreifend_ein',
    'geladen' => function_exists('ws_bb_tun') && function_exists('ws_config_read')
                 && function_exists('ws_bb_einstellungen') && function_exists('ws_bb_protokoll'),
    'einstellungen' => function () {
        return ws_bb_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        ws_bb_protokoll($stufe, $text);
    },
    'befehle' => array(
        // Vorgabesperre 60 s: ein gleicher Suchlauf binnen einer Minute bringt nichts Neues (check.lock ueberspringt ohnehin).
        // Quittung "uebergeben": angenommen heisst, der Auftragsprozess ist gestartet; das Ergebnis kommt ueber die Personen,
        // status/ok und status/zaehler.
        'scan'  => $ws_bb_e('scan', 'auswahl', 'Suchlauf', 'BEFEHL.K_SCAN', array(
            'werte' => array('jetzt', 'beides', 'fritzbox', 'ping'), 'quittung' => 'uebergeben')),
        'modus' => $ws_bb_e('modus', 'auswahl', 'Suchweg', 'BEFEHL.K_MODUS', array(
            'werte' => array('beides', 'fritzbox', 'ping'))),
        'takt'  => $ws_bb_e('takt', 'auswahl', 'Takt', 'BEFEHL.K_TAKT', array(
            'werte'       => array('1', '3', '5', '10', '15', '30', '60'),
            'sperre_s'    => 0,
            'begruendung' => 'WiFi-Scanner-NG: Takt schnell haengt an Alle weg und schaltet 1/3 bei jedem Wechsel; kommt '
                           . 'jemand binnen einer Minute zurueck und geht wieder, muss takt 1 erneut wirken. Ein gleicher '
                           . 'Wert schreibt ohnehin nichts (ws_steuern: unveraendert - nur Zeitplan nachziehen).',
        )),
        'suche' => $ws_bb_e('suche', 'schalter', 'Suche an/aus', 'BEFEHL.K_SUCHE', array(
            'eingreifend' => true,
        )),
    ),
);
