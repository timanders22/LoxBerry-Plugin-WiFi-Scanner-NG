# WiFi Scanner NG

Anwesenheitserkennung für LoxBerry: Das Plugin prüft, ob die Geräte
bestimmter Personen im Netz erreichbar sind, und meldet „anwesend" oder
„abwesend" an den Miniserver.

Fortführung des [WifiScanner-Plugins von Dominik
Holland](https://github.com/Gagi2k/LoxBerry-Plugin-WifiScanner) (Apache
License 2.0). Herkunft und die vollständige Liste der Änderungen stehen in
[NOTICE](NOTICE).

## Umstieg auf 3.0.0 — bitte vor dem Update lesen

> **Diese Fassung heißt anders und wird deshalb nicht als Update angeboten.**

Ordner und MQTT-Thema hießen bisher `wifiscanner` — genauso wie im
Originalplugin. Wer beide installiert hatte, bekam die Anwesenheit zweier
Installationen unter denselben Themen gemeldet. Ab 3.0.0 heißt beides
`wifi_ng`.

Zwei Folgen:

1. **LoxBerry sieht ein anderes Plugin.** Die Kennung entsteht aus Autorname,
   E-Mail und Plugin-Name; mit dem neuen Namen ist das für LoxBerry ein
   neues Plugin. Eine vorhandene Installation bekommt dieses Update **nicht**
   angeboten — es ist einmal von Hand zu installieren. Ein Blick in die alte
   Oberfläche vor der Deinstallation lohnt sich, um die Personen- und
   Geräteliste abzuschreiben.
2. **Die MQTT-Themen wandern.** Aus

       wifiscanner/<Person>          wird    wifi_ng/<Person>
       wifiscanner/status/...        wird    wifi_ng/status/...
       wifiscanner/cmd/...           wird    wifi_ng/cmd/...

   Jeder virtuelle Eingang und jeder Ausgang im Miniserver, der auf diese
   Themen hört oder sendet, muss nachgezogen werden. Im MQTT Gateway ist das
   Abonnement `wifi_ng/#` einzutragen; das alte `wifiscanner/#` kann weg.

Das Repository heißt jetzt `LoxBerry-Plugin-WiFi-Scanner-NG`.

> **Hinweis zu dieser Datei.** Beim Bearbeiten der Fassung 2.5.2 habe ich
> diese README versehentlich überschrieben — ein Skript hat die Datei zum
> Schreiben geöffnet, bevor der Text feststand, und sie damit geleert. Eine
> Sicherung gab es nicht. Der vorliegende Text ist aus dem Quelltext, den
> Sprachdateien und der Konfigurationsvorlage neu geschrieben; er beschreibt
> den Stand von 2.5.2 vollständig. **Nicht wiederherstellbar waren die
> Änderungsnotizen zu den Fassungen 2.4 und 2.5** — sie standen nur hier.

## Wie gesucht wird

Zwei Wege, die sich ergänzen:

* **Fritz!Box fragen.** Der Router weiß ohnehin, wer angemeldet ist. Schnell
  und völlig lautlos — es geht kein einziges Paket an die Geräte.
* **Anpingen.** Nötig ohne Fritz!Box. Weckt Geräte unter Umständen aus dem
  Ruhezustand.

Beide zusammen sind der schonendste Weg: erst den Router fragen, und nur wer
dort fehlt, wird angepingt.

Zum Anpingen benutzt das Plugin `arping`, `arp` und `arp-scan` — für die
ersten beiden braucht es `sudo`, die Regeln dafür liefert das Plugin mit.
Seit 2.5.2 werden die Programme der Reihe nach in `/usr/sbin`, `/sbin`,
`/usr/bin` und `/bin` gesucht; liegt eines nicht dort, wo die `sudoers`-Regel
es erwartet, legt `postinstall.sh` einen Verweis in `/usr/sbin` an.

## Personen und Geräte

Je Person eine Zeile. Dazu gehören die **MAC-Adressen** (Form
`aa:bb:cc:dd:ee:ff`) und/oder **feste IP-Adressen** der Geräte dieser Person,
getrennt durch Semikolon, Komma oder Leerzeichen. Sobald eines der Geräte
erreichbar ist, gilt die Person als anwesend.

Der Name wird zum MQTT-Thema — Umlaute und Leerzeichen werden dabei ersetzt.
Zeilen ohne Namen oder ohne Adresse werden beim Speichern verworfen.

## Weg zum Miniserver

**MQTT** ist der Regelweg. Die Themen sind benannt und kommen *retained* an —
nach einem Neustart des Miniservers steht der letzte Stand sofort wieder da:

```
wifi_ng/<Person>        1 = anwesend, 0 = abwesend
```

**UDP** gibt es zusätzlich, für Aufbauten ohne MQTT-Gateway. Gesendet wird
`<Name>:<0|1>` an den in den Einstellungen gewählten Port (Vorgabe 7007).

## Steuerung per MQTT aus Loxone

Der Listener hört auf `wifi_ng/cmd/#`:

| Thema | Nutzlast | Wirkung |
|---|---|---|
| `wifi_ng/cmd/scan` | — oder ein Modus | Sofort einen Suchlauf starten |
| `wifi_ng/cmd/mode` | `0`/`both`, `1`/`fritzbox`, `2`/`ping` | Suchweg umstellen (wird gespeichert) |
| `wifi_ng/cmd/interval` | `1,3,5,10,15,30,60` | Takt in Minuten |
| `wifi_ng/cmd/enable` | `0` / `1` | Regelmäßiges Suchen an oder aus |

Der aktuelle Stand wird retained nach `wifi_ng/status/#` veröffentlicht
(`mode`, `interval`, `enabled`).

## Zeitplan

Kurze Abstände erkennen schneller, erzeugen aber mehr Netzverkehr. Drei bis
fünf Minuten sind ein guter Mittelweg. Ist das regelmäßige Suchen aus, scannt
das Plugin nur noch auf Befehl — aus Loxone per MQTT oder von Hand im Reiter
*Test*.

## Konfiguration

`config/plugins/<ordner>/wifi_scanner.cfg`, Abschnitt `[BASE]`:

| Schlüssel | Bedeutung |
|---|---|
| `FRITZBOX_ENABLE` | Router fragen (0/1) |
| `FRITZBOX`, `FRITZBOX_PORT` | Adresse und Port der Fritz!Box |
| `ACTIVE_SCAN` | Anpingen (0/1) |
| `USE_CACHE` | Gefundene IP-Adressen merken. Spart Suchläufe; bei häufig wechselnden Adressen besser aus |
| `CRON` | Takt in Minuten |
| `ENABLED` | Regelmäßiges Suchen (0/1) |
| `UDP_ENABLE`, `PORT` | UDP-Versand und Zielport |
| `USERS` | Anzahl der Personen; je Person ein Abschnitt `[USERn]` |

Beim Speichern legt das Plugin eine Kopie neben dem Konfigordner ab
(`config/plugins/<ordner>.wifi_scanner.backup`), damit die Einstellungen eine
Neuinstallation überstehen. Das Deinstallieren entfernt sie seit 2.5.2 wieder.

## Version 2.5.2 — nachgemessen und korrigiert

Fünfzehn Punkte aus einer Durchsicht. Elf trafen zu, zwei teilweise, zwei
nicht.

### Zutreffend und behoben

**Zombie-Prozesse.** `trigger_scan()` spaltet mit `fork()` ab, ohne `waitpid`
und ohne `$SIG{CHLD}`. Nach jedem über MQTT angestoßenen Scan blieb ein
`<defunct>` stehen — bei einem Dauerläufer, den man beliebig oft anstoßen
kann, summiert sich das.

**Absturz ohne MQTT-Gateway.** `mqtt_connectiondetails()` liefert `undef`,
wenn kein Gateway eingerichtet ist; der Zugriff auf `->{brokeraddress}` war
dann ein *„Can't use an undefined value as a HASH reference"* mitten im Lauf,
ohne dass das Protokoll ordentlich geschlossen wurde.

**Logpfad ohne Anführungszeichen** in der Shell-Umleitung.

**Konfiguration nicht atomar**, auf beiden Seiten: `$cfg->save()` in
`mqtt_listener.pl` (drei Stellen) und `file_put_contents()` in
`ws_config_write()`. Fällt der Cron-Lauf von `check.pl` in dieses Fenster,
liest er eine halbe Datei und arbeitet mit Vorgabewerten weiter. Beide
schreiben jetzt in eine Nebendatei und benennen um; die Rechte werden auf der
temporären Datei gesetzt, nicht danach.

**Zwei Listener beim Upgrade.** `kill` und der Start der neuen Instanz
standen unmittelbar hintereinander. Jetzt wird bis zu fünf Sekunden gewartet,
danach `kill -9`.

**Doppelte Listener beim Booten** und **`su` ohne ausdrückliche Shell** — der
`daemon` prüft jetzt argumentweise, ob schon einer läuft, und ruft
`su loxberry -s /bin/bash` auf.

**`exec()` ohne Interpreter** an drei Stellen — `perl` davorgesetzt.

**`$dummy` wurde nicht zurückgesetzt.** `exec()` hängt an, es ersetzt nicht.
Hier folgenlos, weil nur `$rc` benutzt wird — aber es liest sich wie ein
Fehler und wäre in der nächsten Fassung einer.

**Symlink nicht atomar.** Der gewählte Takt wird jetzt mit `ln -sfn`
überschrieben statt gelöscht und neu angelegt; nur die *anderen* Takte werden
entfernt.

**Starre Werkzeugpfade** — siehe oben unter „Wie gesucht wird".

**Verwaiste Sicherung nach der Deinstallation.** Gelöscht wird beim
Deinstallieren nur das Verzeichnis `config/plugins/<ordner>/`; die Kopie
daneben blieb liegen. Sie enthält zwar keine Passwörter, aber die
MAC-Adressen und Namen aller überwachten Personen — eine Anwesenheitsliste
des Haushalts.

### Teilweise

**Der Sicherungsort beim Upgrade.** Dass `/tmp` auf dem LoxBerry flüchtig
ist, stimmt. Die Begründung nicht: `$1` sei bereits ein absoluter Pfad, es
entstünde `/tmp//tmp/uploads/xyz_upgrade`. `$1` ist eine zehnstellige
Zufallskennung (`&generate(10)` in `plugininstall.pl`), der Pfad also
`/tmp/<kennung>_upgrade` — unschön, aber gültig. Der absolute Arbeitsordner
kommt als **sechstes** Argument.

**Log-Tail über `tail`.** Der Speicherhinweis war berechtigt, `tail` ist aber
der langsamste der drei Wege — rund 1,9 ms gegen 0,05 ms beim Rückwärtslesen
mit `fseek`, bei einem Zwanzigstel des Speichers gegenüber dem bisherigen
Weg. Umgestellt auf `fseek`.

### Was nicht zutraf

**`ARCHITECTURE="raspberry,x86"` verhindere die Installation auf
64-Bit-Systemen.** Der aktuelle Installer liest `SYSTEM.ARCHITECTURE` zwar
aus (`$parch` in `plugininstall.pl`), benutzt den Wert danach an **keiner
einzigen Stelle**. Auf `false` gesetzt wurde er trotzdem — der Eintrag war
unwahr, und ältere oder künftige Fassungen könnten ihn sehr wohl auswerten.

**Fehlendes `use strict` in `check.pl`.** Hier wurde bewusst nichts geändert.
`check.pl` ist geerbter Code mit rund 430 Zeilen und durchgehend globalen
Variablen; `use strict` nachzurüsten heißt, jede davon anzufassen. Das ist
keine Korrektur, sondern eine Umschreibung — und eine, deren Fehler sich erst
im Betrieb bei jemand anderem zeigen, weil es hier keinen Prüfaufbau für
einen echten Scan gibt. Der Hinweis ist richtig, aber er gehört zu einer
Überarbeitung mit Prüfmöglichkeit, nicht in eine Fehlerbehebung.

## Lizenz

Siehe [LICENSE](LICENSE).
