# LoxBerry-Plugin WifiScanner

Anwesenheitserkennung von WLAN-Geräten für den Loxone Miniserver — über die
Fritz!Box (TR-064) und/oder eine aktive Suche per arping bzw. ping.

Grundlage ist das Plugin von Dominik Holland
([Gagi2k/LoxBerry-Plugin-WifiScanner](https://github.com/Gagi2k/LoxBerry-Plugin-WifiScanner)),
Apache-Lizenz 2.0. Die Autorenangabe in `plugin.cfg` bleibt unverändert — LoxBerry
identifiziert das Plugin darüber.

## Version 2.5 — Oberfläche neu

- **Neue Oberfläche als `index.php`** mit vier Reitern: *Einstellungen*,
  *Einbindung in Loxone*, *Test*, *Logdateien*. Die alte Perl-CGI-Oberfläche mit
  HTML::Template entfällt.
- **Reiter „Einbindung in Loxone"** erklärt Schritt für Schritt, wie die Werte in
  den Miniserver kommen — samt Themenliste, Befehlstabelle und dem Hinweis auf die
  Ausschaltverzögerung, ohne die WLAN-Anwesenheit im Betrieb flattert.
- **Reiter „Test"** nach Hausstandard mit drei Gruppen und Legende. Die Diagnose
  prüft Zeitplan, MQTT-Listener, Broker, die Werkzeuge arping/arp/arp-scan und
  die benötigten Perl-Module.
- **MQTT ist die Voreinstellung**, UDP nur noch als Rückfallweg.
- **MQTT-Themen werden aus dem Namen gebildet, aber vorher gesäubert**: Umlaute
  umgeschrieben, Leer- und Sonderzeichen zu `_`. Vorher erzeugte ein Name wie
  „Anna Müller" ein unbrauchbares Thema.
- **Adressliste toleranter**: Semikolon, Komma oder Leerzeichen als Trennung.
- **Smartmatch entfernt.** `if ($ip ~~ @ips)` ist seit Perl 5.18 abgekündigt und
  ab Perl 5.42 ein Syntaxfehler. Ersetzt durch `grep`.
- **Einstellungen überstehen ein Update**: die Oberfläche legt eine
  Sicherungskopie außerhalb des Plugin-Ordners ab.
- Auto-Update abgeschaltet, solange es kein eigenes Repository gibt.

## Version 2.4

- Kompatibel mit LoxBerry 4 (PHP 7.4 und PHP 8)
- MQTT-Publish der Ergebnisse (`wifiscanner/<name>` = 0/1, retained)
- Steuerung aus Loxone per MQTT-Kommandos
- Fehlerkorrektur in `check.pl`: Im Fritz!Box-Zweig wurde `@{$user{IPS}}` statt
  `@{$users[$i]{IPS}}` gelesen. `%user` ist dort nicht in Gültigkeit, die Liste
  war also leer — die in der Konfiguration hinterlegten IP-Adressen gingen
  verloren, sobald die Fritz!Box einen Treffer meldete.
- Fehlende Paketabhängigkeiten in `dpkg/apt` ergänzt (`libnet-mqtt-simple-perl`,
  `libdata-validate-ip-perl`, `libcapture-tiny-perl`, `libconfig-simple-perl`,
  `libwww-perl`)

## Steuerung per MQTT aus Loxone

`bin/mqtt_listener.pl` startet beim Hochfahren und abonniert `wifiscanner/cmd/#`.
Über einen virtuellen Ausgang (LoxBerry MQTT Gateway) lassen sich folgende
Befehle senden — bitte **nicht retained**:

| Topic | Payload | Wirkung |
|---|---|---|
| `wifiscanner/cmd/scan` | leer oder Modus | Sofort-Scan, optional mit einmaligem Modus |
| `wifiscanner/cmd/mode` | `0`/`both`, `1`/`fritzbox`, `2`/`ping` | Modus dauerhaft umstellen, löst direkt einen Scan aus |
| `wifiscanner/cmd/interval` | `1`,`3`,`5`,`10`,`15`,`30`,`60` | Scan-Intervall in Minuten |
| `wifiscanner/cmd/enable` | `0` / `1` | periodisches Scannen aus/ein |

Zustand (retained): `wifiscanner/status/mode`, `wifiscanner/status/interval`,
`wifiscanner/status/enabled`.

## Abfrage-Modi

- `0` / `both` — Fritz!Box fragen, und nur wer dort fehlt, wird angepingt
- `1` / `fritzbox` — nur die Fritz!Box fragen
- `2` / `ping` — nur aktiv suchen

## Hinweise

- **arping** braucht Rootrechte; die Regel liegt in `sudoers/sudoers`.
- Ohne MQTT Gateway beendet sich der Listener sofort. Anwesenheitserkennung und
  Zeitplan laufen dann trotzdem, nur die Steuerung aus Loxone nicht.
- Die Konfiguration liegt weiterhin im Format von `Config::Simple`
  (`config/wifi_scanner.cfg`), damit `check.pl` und `mqtt_listener.pl`
  unverändert damit arbeiten.
- WLAN-Geräte tauchen kurz ab. Im Loxone-Projekt gehört hinter die
  Anwesenheit eine **Ausschaltverzögerung von 10 bis 15 Minuten**, sonst
  schaltet die Heizung ab, weil ein Telefon geschlafen hat.
