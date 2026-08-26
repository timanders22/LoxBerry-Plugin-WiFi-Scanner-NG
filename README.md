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

## Version 3.2.0 — Merkwort, eigener Endpunkt, Lebenszeichen

Aus einer Zeile-für-Zeile-Durchsicht am 26.08.2026. Was **gemessen** wurde,
steht dabei; was nur gelesen wurde, ist als solches gekennzeichnet.

### Neu

**Ein Merkwort für die Anlage.** Bis 3.1.11 stand im Quelltext und im Warntext
am Sicherungsknopf, die Sicherungsdatei trage „das Aktionstoken" — der
Baustein war wörtlich aus einem anderen Plugin übernommen, das Merkwort aber
nicht mitgekommen. In derselben Sprachdatei stand deshalb an einer Stelle
*„Die Datei enthält Ihre Zugangsdaten"* und an der anderen *„Das Plugin
speichert keine Zugangsdaten"*. Recht hatte die zweite. Jetzt gibt es
`BASE.TOKEN`, beim ersten Öffnen der Oberfläche einmal erzeugt.

**Ein Wachposten gegen fremde Absender.** `htmlauth/` schützt gegen den
unangemeldeten Aufruf, nicht dagegen, dass der Browser eines angemeldeten
Bedieners ein Formular abschickt, das auf einer fremden Seite steht. Bis
3.1.11 genügte ein `<img src=".../ws_test.php?restart">`, um den Dienst neu
zu starten. Jetzt trägt jedes Formular ein aus dem Merkwort abgeleitetes
Merkmal, und **eine** zentrale Prüfung vor allen Handlern entwaffnet einen
POST ohne gültiges Merkmal — damit ist jeder künftig ergänzte Handler
mitgeschützt.

**Ein eigener Endpunkt für Loxone** unter `webfrontend/html/`. Wer kein
MQTT-Gateway fährt, hatte bisher gar keinen Rückkanal. Die fertigen Adressen
stehen im Reiter *Einbindung in Loxone* zum Abschreiben:

```
/plugins/wifi_ng/index.php                      Antwortzeile, ohne Merkwort
/plugins/wifi_ng/index.php?json=1               dasselbe als JSON
...?token=<TOKEN>&aktion=scan                   Sofort-Scan
...?token=<TOKEN>&aktion=enable&wert=0|1        periodisches Suchen
...?token=<TOKEN>&aktion=interval&wert=<n>      Takt in Minuten
...?token=<TOKEN>&aktion=mode&wert=0|1|2        Suchweg
...?selftest=1&token=<TOKEN>                    Selbsttest
```

**Ein Lebenszeichen.** Das ist bei einem Anwesenheitsplugin der wichtigste
Zugewinn. Ein virtueller Eingang behält seinen letzten Wert — retained sogar
über jeden Neustart des Miniservers hinweg. Stirbt `check.pl` oder fällt der
Cron aus, steht in Loxone weiter die Anwesenheit vom Zeitpunkt des Ausfalls.
Das ist keine fehlende Auskunft, das ist eine Falschaussage, und niemand
merkt sie. Neu gehen deshalb hinaus:

| Thema | Bedeutung |
|---|---|
| `wifi_ng/status/ok` | 1 = der letzte Lauf hat wirklich gemessen |
| `wifi_ng/status/ts` | Zeitstempel des letzten Laufs (Unix-Sekunden) |
| `wifi_ng/status/zaehler` | läuft 0…999 um — erkennt einen stehenden Takt auch dann, wenn die Uhr gesprungen ist |
| `wifi_ng/status/listener` | ob der MQTT-Listener läuft, von `check.pl` **gemessen**, nicht vom Listener behauptet |

Über UDP gehen `wifi_ok:` und `wifi_ts:` mit hinaus. **Legen Sie `OK` mit auf
eine Überwachung** — ein festgefrorenes „alle zu Hause" sieht sonst genauso
aus wie ein richtiges.

**Wer gerade da ist, steht in der Oberfläche.** `check.pl` legt sein Ergebnis
als Abbild unter `data/plugins/<ordner>/zustand.json` ab; der Reiter
*Einstellungen* zeigt es als Kachelreihe, mit dem Weg, über den die Person
gefunden wurde.

**Zugangsdaten für die Fritz!Box** (`FRITZBOX_USER`, `FRITZBOX_PASS`), **ab
Werk leer** — dann verhält sich das Plugin wie bisher und fragt die Box ohne
Anmeldung. In der Anzeige (`?config`) sind Merkwort und Kennwort maskiert.

> **Am Gerät gemessen (26.08.2026), FRITZ!Box 7690 mit FRITZ!OS 8.25:** die
> Box beantwortet `GetSpecificHostEntry` **ohne Anmeldung**. `tr64desc.xml`
> kommt mit HTTP 200, der Dienst `Hosts1` wird angeboten, und für eine
> erfundene MAC antwortet die Box mit `714 NoSuchEntryInArray` — sie hat die
> Anfrage also verarbeitet. `GetHostNumberOfEntries` liefert ebenfalls
> (49 bekannte Hosts).
>
> Die Gegenprobe an derselben Box macht das erst belastbar:
> `DeviceInfo#GetInfo` und `DeviceConfig#GetPersistentData` antworten mit
> **401 Unauthorized**. Die Box *kann* abweisen — beim Hosts-Dienst tut sie
> es nur nicht.
>
> Die beiden Felder werden hier also **nicht gebraucht**. Sie bleiben
> trotzdem eingebaut: sie kosten leer nichts, und sie greifen dort, wo eine
> andere Box oder eine andere Einstellung den Zugriff doch schließt. Der
> Hinweis darauf erscheint dann im Protokoll, und zwar **einmal** und nicht
> je Gerät — sagt die Box 401, sagt sie es für alle.

**Ein Wächter für den MQTT-Listener.** `check.pl` startet ihn nach, wenn MQTT
der gewählte Weg ist und nachweislich keiner läuft. Fail safe: im Zweifel
passiert nichts.

**Eine Selbstprüfung im Reiter Test** — zehn Fragen mit drei Ausgängen (ja,
nein, *hier konnte nichts gemessen werden*). Ein Strich zählt nicht als
bestanden und wird in der Bilanz getrennt genannt.

### Behoben

**Der Sichern-Knopf lieferte keine Datei.** Der Download-Block stand hinter
`LBWeb::lbheader()`; der Kopf war damit schon geschrieben. Statt einer Datei
bekam man eine HTML-Seite mit zwei *„headers already sent"*-Warnungen und dem
JSON mittendrin. Am laufenden Webserver gemessen — und drei Prüfwerkzeuge
hatten das Plugin dafür grün gemeldet, weil sie den Bauplan prüfen und nicht
die Wirkung an einer Seite mit Rahmen.

**Befehlseinschleusung über das Adressfeld.** `check.pl` hielt alles, was
nicht wie eine MAC-Adresse aussah, für eine IP-Adresse und setzte es
unverändert in `system("sudo arping … $ip")` ein — einen String, also über
`/bin/sh`. Ein `$(befehl)` im Adressfeld wurde ausgeführt. Jeder Aufruf läuft
jetzt als Liste ohne Shell, und jede Adresse wird an beiden Enden geprüft.

**Das Zurückspielen nahm jeden Wert an.** Die Prüfung sah nur die
*Schlüssel* an. Eine Sicherung, die einen Zeilenumbruch und einen fremden
Abschnitt in den Wert von `BASE.FRITZBOX` legte, wurde übernommen und mit
„Gespeichert." quittiert; danach stand der fremde Abschnitt in der
Konfigurationsdatei. Jetzt wird jeder Wert geprüft, und eine Datei mit auch
nur einem falschen Wert ändert **gar nichts**.

**Nach dem Zurückspielen stimmte weder Meldung noch Anzeige.** Zeitplan und
Listener wurden nicht nachgezogen, während die Meldung behauptete, beides sei
geschehen; die Formularfelder zeigten weiter die alten Werte; und die eigens
gebaute Meldung „N Werte übernommen" wurde nie angezeigt.

**Die Warnung zur Sicherungsdatei hatte keinen Kasten.** Das HTML benutzte
`class="sm-warnung"`, das Stylesheet kannte nur `sm-warn`. Die einzige
Warnung des Plugins stand als nackter Fließtext da.

**Bei aktivem Scan wurde zweimal gesendet, und das erste Mal falsch.** War die
Fritz!Box-Abfrage eingeschaltet *und* der aktive Scan, und wurde dabei
mindestens eine Person gefunden, sendete das Skript zuerst das halbe Ergebnis
— alle, die die Box nicht kannte, als `0` — und gleich darauf das richtige.
In Loxone kam damit bei jedem Lauf eine 0-nach-1-Flanke an, die es nie gab.

**Die Zweitschriften überlebten die Deinstallation.**
`config/plugins/<ordner>.backup.wifi_scanner.cfg` und
`.backup.mqtt_subscriptions.cfg` blieben liegen — mit den Namen und
MAC-Adressen aller überwachten Personen, also einer Anwesenheitsliste des
Haushalts. `uninstall` überschreibt und löscht jetzt alle drei
Sicherungsdateien und zählt nach.

**`File::HomeDir` wurde geladen, ohne benutzt zu werden** — und das zugehörige
Paket stand nicht in `dpkg/apt`. Ohne das Modul wäre `check.pl` beim Start
gestorben, vor der ersten Protokollzeile. Aufgelöst nicht durch Nachtragen
des Pakets, sondern durch Streichen der `use`-Zeile; dasselbe für
`LWP::Simple`, `Cwd` und `POSIX`. Umgekehrt fehlte **`net-tools`**, obwohl
`check.pl` `arp` aufruft — auf Debian 12/13 ist es nicht mehr ab Werk dabei.

**`update_cron()` im Listener war der Rückbau dessen, was die Oberfläche
ausdrücklich anders macht**: siebenmal `unlink`, dann `ln -s` als
Zeichenkette. Beide Stellen überschreiben den gewählten Takt jetzt mit
`ln -sfn`. Ebenso `postinstall.sh`: dort fehlte das Warten auf das Ende des
alten Listeners, das `postupgrade.sh` seit 2.5.2 hat.

**`?scan` und `?restart` waren Aktionen über GET-Verweise.** Sie sitzen jetzt
als POST mit Merkmal im Reiter Test; `ws_test.php` fragt nur noch ab.

**`use strict` in `check.pl`** — 2.5.2 hatte es zurückgestellt, weil es keinen
Prüfaufbau gab. Den gibt es inzwischen (`Werkzeuge/perl_attrappe`), und die
Prüfung ist in beide Richtungen geeicht: mit `use strict` wird ein
Tippfehler in einem Variablennamen zum Compile-Fehler, ohne bleibt er eine
Warnung, und die Datei gilt als *syntax OK*.

**Weiteres:** Log-Kappung (ab 500 kB die letzten 200 Zeilen — `log/plugins`
liegt auf einer Ramdisk); nicht blockierende Sperre gegen zwei gleichzeitige
Läufe (ein übersprungener Lauf ist **kein** Fehler); `Config::Simple->new`
wird an allen Stellen auf `undef` geprüft; Zeitschranke für den
Fritz!Box-Abruf; eine gescheiterte Box-Abfrage bricht den Lauf nicht mehr ab,
sondern überlässt das Ergebnis dem aktiven Scan.

### Oberfläche

Der Hausstandard ist nachgezogen: `.sm-seite` statt `.sm-pane`, `?form=`
statt `?tab=`, die fehlenden Klassen `.sm-warnung`, `.sm-breit`,
`.sm-kacheln`, `.sm-an`/`.sm-aus`, `.sm-feld`, `.sm-hilfe`, `.sm-pre`;
Auswahlfelder mit selbst gezeichnetem Pfeil; `data-role="none"` auch an den
Verweisknöpfen; die Personentabelle in `.sm-breit`, weil sie Eingabefelder
trägt.

Die Personenzeilen tragen **ausgeschriebene Indizes und den ursprünglichen
Abschnittsnamen**; gelöscht wird über einen Haken. Bis 3.1.11 wurde eine
Zeile ohne Namen oder ohne Adresse stillschweigend verworfen — wer nur den
Namen berichtigen wollte und ihn dabei kurz leerte, verlor die ganze
Adressliste ohne eine einzige Meldung. Neu ist außerdem eine Meldung, wenn
dieselbe Adresse bei zwei Personen steht: dann gilt die zweite immer als
anwesend, sobald die erste zu Hause ist.

**Der Abo-Hinweis richtet sich nach der Gateway-Fassung.**
`Mqtt.Gatewayversion` wird aus `general.json` gelesen — bei V1 steht der
Pflichtsatz *„Ohne diesen Eintrag kommt am Miniserver nichts an"*, bei V2 der
Hinweis, dass dort nichts einzutragen ist, und wenn die Fassung nicht lesbar
ist, **beide**: einen von beiden zu behaupten wäre für die Hälfte der Anlagen
falsch.

**Das Symbol** folgt der Hausvorgabe: flache Scheibe statt Farbverlauf, keine
Schriftzüge (bei 64 px war davon ein grauer Balken übrig), Strichstärken über
14 Einheiten.

### Ort der Bibliothek

`ws_lib.php` liegt jetzt unter `webfrontend/html/`, nicht mehr unter
`webfrontend/htmlauth/`. Installiert sind das zwei getrennte Bäume, und ein
`require` über `..` trifft nur das ausgepackte Archiv; die Oberfläche holt
die Bibliothek über eine Kandidatenliste.

### Was nicht geprüft werden konnte

Die Frage nach der Fritz!Box ist inzwischen **am Gerät gemessen** (siehe
oben). Offen bleibt einer:

* **Unter welchem Benutzer der LoxBerry-Cron `check.pl` startet.** Davon
  hängt ab, wie weit die behobene Befehlseinschleusung gereicht hätte — an
  der Korrektur ändert es nichts.

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
