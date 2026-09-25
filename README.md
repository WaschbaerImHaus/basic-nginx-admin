# vhost-admin

Verwaltung von nginx-vHosts auf einem Ubuntu-LXC: Domains anlegen, Verzeichnisschutz mit Benutzern und IP-Freigaben, Let's-Encrypt-Zertifikate – über eine kleine Web-Oberfläche (nur lokal erreichbar) oder die Kommandozeile.

## Installation

```bash
git clone git@github.com:WaschbaerImHaus/basic-nginx-admin.git
cd basic-nginx-admin
sudo ./install.sh              # Besitzer der Web-Ordner = aufrufender Benutzer
sudo ./install.sh --owner max  # oder ein anderer Benutzer
```

Der Installer richtet nginx, PHP-FPM, certbot und PHPUnit ein, legt die Oberfläche unter `/var/www/localhost-8080` ab, erlaubt `www-data` per sudoers genau den Befehl `/usr/local/sbin/vhost` und ersetzt die nginx-Standardseite durch einen Catch-all, der unbekannte Hostnamen abweist. Das Skript ist mehrfach ausführbar (Update).

Alternativ aus dem Build-Paket: `tar xzf vhost-admin.tar.gz && sudo vhost-admin/install.sh`.

## Oberfläche

`http://127.0.0.1:8080` – nur vom Server selbst erreichbar. Von außen per SSH-Tunnel: `ssh -L 8080:127.0.0.1:8080 <server>`.

- **Übersicht:** alle vHosts mit Docroot, Schutz- und HTTPS-Status; Formular „Neue Domain“ (optional mit Unterordner als Docroot); Einstellung der Let's-Encrypt-E-Mail.
- **Detailseite:** Verzeichnisschutz ein-/ausschalten, Benutzer anlegen (oder Passwort neu setzen) und entfernen, IPs/Netze freigeben, HTTPS ein-/ausschalten, vHost entfernen (Dateien bleiben erhalten).

Schreibende Aktionen laufen nie direkt in der Oberfläche, sondern immer über das CLI `vhost` (per sudoers), abgesichert mit CSRF-Token.

## Aufbau

Die Oberfläche nutzt die volle Fensterbreite. Links steht dauerhaft die Liste aller
vHosts mit ihrem Zustand – erreichbar, HTTPS, Verzeichnisschutz, PHP – sodass ein
Wechsel zwischen Hosts einen Klick kostet. Rechts der Arbeitsbereich: auf breiten
Fenstern der Direktiven-Editor links und die Schalter daneben, auf schmalen
untereinander.

Maschinenwerte (Domains, Pfade, Direktiven) stehen durchgehend in Festbreitenschrift.
Schriften kommen ausschliesslich vom System: eine lokale Verwaltungsoberfläche soll
keine Anfragen ins Netz auslösen. Helles und dunkles Farbschema richten sich nach der
Einstellung des Betriebssystems.

Der Editor für die eigenen Direktiven hat Zeilennummern, füllt gut die halbe
Fensterhöhe und lässt sich an der unteren Kante grösser ziehen. Der Tabulator rückt
ein, statt den Fokus weiterzusetzen. Wird eine Direktive abgelehnt, bleibt der
eingegebene Text erhalten, die beanstandete Zeile wird in den Zeilennummern rot
markiert und der Editor springt dorthin – man korrigiert die eine Zeile, statt alles
neu zu tippen.

## Pfade

| Host | Struktur |
|---|---|
| `example.com` | `/var/www/example.com/` mit `web/` (Docroot, mit Unterordner `web/<unterordner>/`), `conf/`, `cert/`, `private/`, `logs/` |
| `localhost:3000` | `/var/www/localhost-3000/` mit denselben fünf Ordnern |

Beim Anlegen entsteht in `web/` eine bunte `index.html` („200“), sofern noch keine liegt. `web/` gehört dem bei der Installation gewählten Benutzer und der Gruppe `www-data` (`www-data` darf ausschließlich hier schreiben); `conf/`, `cert/`, `logs/` gehören root, `private/` dem Benutzer allein.

## Aufbau der generierten Konfiguration

Der Generator schreibt einen vollständigen `server`-Block und lässt am Ende Platz für
eigene Direktiven – wie bei ISPConfig. Vorgegeben sind `listen`, `server_name`, `root`,
`index`, `try_files`, die Logpfade, der Verzeichnisschutz, die Sperre für versteckte
Dateien, `favicon.ico`, `robots.txt`, der ACME-Pfad und der PHP-Block. Bei Hosts mit
Zertifikat kommen HTTP/2, HTTP/3 (QUIC mit `Alt-Svc`) und die TLS-Einstellungen dazu;
Port 80 leitet dann per `return 301` auf HTTPS um.

Der eigene Bereich steht als Letztes im Block:

```
    # >>>>
    # ab hier eigene Direktiven (Oberfläche: "Eigene Direktiven")
    include /var/www/<domain>/conf/*.conf;
    # <<<<
```

Alles in `[domain]/conf/` wird dort eingebunden – das Textfeld der Oberfläche schreibt
`custom.conf`, eine selbst angelegte `rewrites.conf` kommt ebenso mit hinein.

Absichtlich **kein** generiertes `location / { … }`: `try_files` steht stattdessen auf
Server-Ebene. So lässt sich ein eigenes `location /` einsetzen, ohne dass nginx mit
`duplicate location "/"` abbricht – genau der Fall, der beim Kopieren einer fremden
Konfiguration auftritt.

## Eigene nginx-Direktiven

Auf der Detailseite eines vHosts gibt es unter „Eigene nginx-Direktiven“ ein Textfeld.
Geprüft wird gegen eine **Sperrliste**: erlaubt ist alles, was nicht aus dem vHost
herausführt. Ein Fragment aus der Konfiguration eines anderen Projekts – mit `if`,
`location`, `limit_except`, `fastcgi_*`, `deny`, `try_files`, `sub_filter` und so weiter –
lässt sich damit einsetzen, ohne es umzuschreiben.

Abgelehnt wird (jeweils mit Zeilennummer und Grund):

| Direktive | Grund |
|---|---|
| `root`, `alias` | Pfad muss innerhalb von `/var/www/<domain>/` liegen |
| `include` | nur Dateien in `[domain]/conf/` |
| `access_log`, `error_log` | nur Dateien in `[domain]/logs/` (oder `off`) |
| `auth_basic`, `auth_basic_user_file`, `satisfy`, `allow` | Verzeichnisschutz und IP-Freigaben verwaltet die Oberfläche; `auth_basic off` bzw. `allow` in einem `location` würden ihn aufheben |
| `listen`, `server_name` | Bindung und Name des Hosts; mit `listen` könnte ein localhost-Host öffentlich werden |
| `proxy_pass`, `fastcgi_pass`, `uwsgi_pass`, `scgi_pass`, `grpc_pass`, `memcached_pass` | Ziel darf nicht dieser Rechner sein (die Oberfläche läuft ohne eigene Anmeldung); als Unix-Socket nur der eigene FPM-Socket des Hosts |
| `fastcgi_param SCRIPT_FILENAME`/`DOCUMENT_ROOT` | Pfad muss im vHost liegen, sonst liesse sich fremder PHP-Code ausführen |
| `dav_methods`, `dav_access` | Schreibzugriff über HTTP |
| `perl*`, `lua*`, `*_by_lua*`, `js_*`, `load_module` | Code-Ausführung im nginx-Prozess |
| `ssl_certificate` und Verwandte | Zertifikate verwaltet die Oberfläche |

Eine unbekannte Direktive ist erlaubt und wird an nginx weitergegeben. Schlägt `nginx -t`
fehl, bleibt die bisherige Fassung aktiv und die Meldung erscheint im roten Kasten.
Gespeichert wird das Snippet in `[domain]/conf/custom.conf`.

Unter dem Editor steht die **fertige Konfiguration**: der erzeugte `server`-Block, der
Verzeichnisschutz und die eigenen Direktiven zu einem Text zusammengesetzt, mit
Zeilennummern und hervorgehobenem eigenem Abschnitt. nginx liest diese Teile aus
mehreren Dateien; um zu sehen, was am Ende gilt, musste man sie bisher auf dem Server
einzeln nachschlagen. Auf der Kommandozeile: `sudo vhost show <name>`.

**Bearbeitet wird ausschliesslich über die Oberfläche bzw. `vhost conf`.** `[domain]/conf/`
gehört root (`root:<besitzer> 0750`, die Datei `0640`): der Besitzer der Website darf den
Text lesen, aber nicht ändern, und keine eigenen Dateien dort anlegen. Auch `www-data` hat
keinen Zugriff mehr – die Oberfläche holt den Text über `vhost conf-show <name>`, das als
root liest. Damit gibt es genau einen Weg, auf dem nginx-Direktiven entstehen, und jede
Änderung läuft durch die Prüfung.

## PHP

Jeder vHost kann PHP ausliefern – über einen **eigenen php-fpm-Pool mit eigenem
Systembenutzer** (`web<id>`, Schema wie bei ISPConfig). Umschalten auf der Detailseite
oder mit `sudo vhost php <name> on|off`.

Warum ein eigener Pool je Host und nicht der mitgelieferte: der läuft als `www-data`, und
`www-data` darf per sudoers das `vhost`-CLI als root aufrufen. PHP einer Website in diesem
Pool wäre damit root auf dem Rechner. Mit eigener Kennung je Host kommt Website-PHP an
diese Rechte nicht heran – geprüft wird das im Smoke-Test.

- Pool: `/etc/php/<version>/fpm/pool.d/vhost-<name>.conf`, Socket
  `/run/php/vhost-<name>.sock` (nur `www-data` darf ihn öffnen)
- `open_basedir` grenzt PHP auf `web/`, `private/` und ein eigenes `tmp/` ein; die Dateien
  anderer Hosts, die Datenbank und die Oberfläche sind unerreichbar
- PHP-Fehler landen in `[domain]/logs/php.log`, nie im Browser
- Ist PHP aus, werden `.php`-Anfragen mit 404 abgewiesen – niemals als Text ausgeliefert,
  sonst stünde der Quellcode samt Zugangsdaten offen
- Beim Abschalten bleibt der Systembenutzer bestehen: von PHP angelegte Dateien könnten
  ihm noch gehören, und eine später neu angelegte Kennung könnte dieselbe UID bekommen

## Logdateien

Jeder vHost schreibt nach `[domain]/logs/access.log` und `[domain]/logs/error.log`. Rotation täglich, 14 Tage Aufbewahrung (`/etc/logrotate.d/vhost-admin`, per `install.sh` eingerichtet).

## Verzeichnisschutz

Jeder neue Host ist zunächst gesperrt: Schutz aktiv, aber ohne Benutzer und ohne IP – niemand kommt hinein. Freigabe: eine IP/ein Netz **oder** ein gültiger Login genügt. Oder den Schutz ganz abschalten. Der Pfad `/.well-known/acme-challenge/` bleibt immer frei, damit certbot arbeiten kann.

Solange der offene Punkt „Reload wird verschluckt“ (`BUGS.md`) nicht behoben ist, kann ein Reload – selten, aber möglich – wirkungslos bleiben; das betrifft auch sicherheitsrelevante Änderungen (Schutz einschalten, Benutzer entfernen, IP-Freigabe zurücknehmen). Solche Änderungen deshalb zur Sicherheit mit `sudo vhost render <name>` bestätigen.

## vHost entfernen

Entfernen läuft in zwei Stufen, damit ein Fehlgriff nicht sofort endgültig ist:

1. Nach der Rückfrage wird der vHost **sofort gesperrt** – der Symlink in
   `sites-enabled` verschwindet, nginx wird neu geladen und antwortet auf den Namen
   nicht mehr (444). Der Eintrag, die Konfiguration, die Benutzer und alle Dateien
   bleiben bestehen.
2. **60 Minuten später** verschwindet der Eintrag endgültig. Bis dahin steht in der
   Oberfläche der Knopf „Entfernen zurücknehmen", auf der Kommandozeile
   `sudo vhost restore <name>`.

Die Dateien unter `/var/www/<domain>/` werden dabei nie gelöscht – auch nicht nach
Ablauf der Frist. Wer auch die Dateien loswerden will, nimmt
`sudo vhost remove <name> --purge` (sofort und endgültig, aus der Oberfläche gesperrt).
`--now` entfernt sofort, lässt die Dateien aber liegen.

Das endgültige Entfernen erledigt der systemd-Timer `vhost-admin-purge.timer` (alle zehn
Minuten, von `install.sh` eingerichtet); von Hand geht es mit `sudo vhost purge-due`.
Die Frist lässt sich über `removalGraceMinutes` in `Config` ändern.

## Sicherheit, Browser-Cache und Komprimierung

Der generierte Block bringt Voreinstellungen mit, die für jede Seite sinnvoll sind:

**Sicherheitskopfzeilen** (auf Server-Ebene, alle mit `always`, damit sie auch bei 401 und
Fehlerseiten gesendet werden): `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN` und
`server_tokens off` (die Antwort nennt nur noch `nginx`, nicht mehr die Version). Bei Hosts
mit Zertifikat zusätzlich `Strict-Transport-Security: max-age=15552000` – 180 Tage, ohne
`includeSubDomains` und ohne `preload`.

> **Zu HSTS:** Die Kopfzeile wirkt im Browser bis zum Ablauf nach. Wird HTTPS für eine
> Domain später abgeschaltet, bleibt die Seite für wiederkehrende Besucher unerreichbar –
> auch über HTTP. Mit `sudo vhost set hsts off` lässt sich das für alle Hosts abschalten
> (schreibt die Konfiguration neu); bereits ausgelieferte Kopfzeilen laufen trotzdem erst
> ab.

Nicht vorgegeben sind `Content-Security-Policy` und `Permissions-Policy`: die lassen sich
nicht sinnvoll pauschal setzen, ohne Seiten zu zerlegen. Sie gehören in die eigenen
Direktiven.

**Komprimierung:** `gzip` ist in der `nginx.conf` von Ubuntu aktiv, komprimiert ohne
`gzip_types` aber ausschliesslich `text/html`. Der Block ergänzt CSS, JavaScript, JSON, XML,
SVG, WASM und Schriften im TTF/OTF-Format, dazu `gzip_vary`, `gzip_comp_level 5`,
`gzip_min_length 256` und `gzip_static`. Schon komprimierte Formate (JPEG, PNG, WOFF2)
stehen absichtlich nicht drin – sie erneut zu packen kostet nur Rechenzeit.

**Browser-Cache:** CSS/JS 7 Tage, Bilder/Schriften/Medien/Archive 30 Tage, HTML/JSON/XML/TXT
`no-cache` (der Browser fragt nach, bekommt bei unveränderter Datei aber ein billiges 304
über den ETag). Ohne `immutable`: das gilt nur für Dateien mit Fingerabdruck im Namen, auf
einer gewöhnlichen `style.css` würde es Änderungen wochenlang verstecken.

> **Eine nginx-Falle, die hier wichtig ist:** Ein `add_header` in einem `location`-Block
> verwirft **alle** geerbten `add_header` des `server`-Blocks – die Sicherheitskopfzeilen
> wären in genau diesem Block dann weg. Der Cache wird deshalb über `expires` gesetzt, das
> diesen Nebeneffekt nicht hat. Wer in eigenen Direktiven ein `add_header` in einem
> `location` verwendet, muss die gewünschten Kopfzeilen dort wiederholen.
>
> Und: Cache-Regeln überschreiben lassen sich nicht mit einem weiteren regulären Ausdruck –
> bei denen gewinnt der erste Treffer, und die generierten stehen vorher. Wirksam sind ein
> genauer Pfad (`location = /x.css`) oder ein Präfix mit Vorrang (`location ^~ /assets/`).

## www-Umleitung

Eine Hauptdomain wird immer unter einem der beiden Namen ausgeliefert, der andere leitet
mit 301 dorthin um. **Voreingestellt ist die Auslieferung ohne `www`.** Es gibt keine
Einstellung „keine Umleitung": Einer der beiden Namen liefert aus, der andere zeigt
dorthin.

```
sudo vhost www <name> bare|www
```

Das gilt nur für **Hauptdomains**. Für eine Unterdomain wie `shop.example.com` gibt es
keinen Schalter – `www.shop.example.com` ist nicht üblich und existiert in aller Regel
gar nicht. Die Unterscheidung trifft `DomainName::isMainDomain()`; sie kennt die
geläufigen zweiteiligen Endungen (`example.co.uk` ist eine Hauptdomain), aber nicht die
vollständige Public Suffix List.

Beide Namen zeigen auf denselben Docroot – ein Verzeichnis `www.<domain>` wird nie
angelegt.

Ohne Zertifikat bekommt der Nebenname einen eigenen Port-80-Block, der mit 301 umleitet
und den ACME-Pfad mitbringt. Mit Zertifikat nimmt der Port-80-Block beide Namen und
leitet unmittelbar auf den kanonischen über HTTPS um; auf Port 443 leitet der Nebenname
weiter.

> **Zum Zertifikat:** Es muss beide Namen abdecken, sonst bekommt ein Aufruf von
> `https://www.<domain>` einen Zertifikatsfehler, *bevor* die Umleitung greift. Deshalb
> beantragt `vhost ssl <name> on` den Nebennamen mit – **aber nur, wenn er erreichbar
> ist.** certbot prüft jeden angegebenen Namen einzeln; einer ohne DNS-Eintrag lässt den
> gesamten Antrag scheitern, auch für den Hauptnamen. Da die Umleitung voreingestellt
> ist, beträfe das sonst jede Domain, für die es kein `www` gibt. Ist der Nebenname nicht
> erreichbar, gilt das Zertifikat nur für den Hauptnamen und der Aufruf sagt das.

> **Nachträglich erweitern:** Wer die Umleitung erst einschaltet, wenn das Zertifikat
> schon da ist, braucht `vhost cert-extend <name>` (in der Oberfläche der Knopf
> „Zertifikat um www.… erweitern“). `vhost ssl <name> on` überspringt certbot bei
> vorhandenem Zertifikat bewusst und würde den Namen nie nachtragen. Vor dem Antrag
> werden beide Namen einzeln geprüft; ist einer nicht erreichbar, bricht der Befehl ab,
> ohne einen Fehlversuch bei Let's Encrypt zu verbrauchen.

## Erreichbarkeit

Jeder vHost hat unter `web/.well-known/acme-challenge/vhost-admin-health` eine Datei mit
einer eigenen Kennung. Beim Aufruf der Oberfläche wird für jede Domain
`http://<domain>/.well-known/acme-challenge/vhost-admin-health` abgefragt und der Inhalt
verglichen; das Ergebnis steht in der Übersicht und auf der Detailseite als grüner oder
roter Punkt (der Text im Tooltip sagt, was fehlt). Geprüft wird nur beim Seitenaufruf,
nicht laufend, und alle Domains parallel.

Das ist genau der Weg, den Let's Encrypt für die `http-01`-Prüfung nimmt. Damit deckt ein
Test beides ab: ob die Domain überhaupt aus dem Internet erreichbar ist, und ob eine
Zertifikatsausstellung gelingen würde. Mögliche Ergebnisse:

| Anzeige | Bedeutung |
|---|---|
| **erreichbar** | Kennung kam zurück – DNS, Port 80 und der ACME-Pfad stimmen |
| **DNS fehlt** | Der Name löst nicht auf |
| **nicht erreichbar** | Name löst auf, aber Port 80 antwortet nicht (Firewall, falsche IP, Dienst aus) |
| **fremder Server** | Es antwortet ein Server, aber nicht dieser – der DNS-Eintrag zeigt auf einen anderen Rechner |
| **entfällt** | localhost-Host, absichtlich nie von aussen erreichbar |

Auf der Kommandozeile: `sudo vhost check-acme [name]` (Exit-Status 1, wenn eine Domain
nicht erreichbar ist – damit auch für eine Überwachung brauchbar).

Grenze des Tests: Die Anfrage kommt von diesem Server. Läuft sie über die eigene Leitung
zurück, kann sie gelingen, obwohl ein fremdes Netz den Port nicht erreicht. „erreichbar"
ist deshalb ein starkes Indiz, keine Garantie. „fremder Server" dagegen ist verlässlich:
dann antwortet nachweislich nicht dieser Rechner.

### Passwörter

Passwörter werden erzeugt, nicht eingegeben. Das Formular zeigt ein fertiges an – 20
Zeichen aus Gross- und Kleinbuchstaben, Ziffern und `@=#+.,_-:;`, gezogen aus dem
Zufallsgenerator des Betriebssystems – und genau dieses wird hinterlegt. Damit fällt die
häufigste Schwachstelle eines Verzeichnisschutzes weg, das zu einfache Passwort.

Nach dem Anlegen erscheint das Passwort einmal gross und markierbar. Danach ist es nicht
mehr auslesbar: in der htpasswd-Datei steht nur der Hash. Neben jedem Benutzer steht
dafür **Passwort neu** – ein Klick erzeugt eines, hinterlegt es und zeigt es an; das
bisherige gilt ab dann nicht mehr.

Auf der Kommandozeile bleibt jedes beliebige Passwort möglich:

```
printf 'meins\n' | sudo vhost user-add <name> <benutzer>
```

## Let's Encrypt

1. E-Mail-Adresse in den Einstellungen hinterlegen.
2. Domain per DNS auf den Server zeigen lassen; Port 80 muss aus dem Internet erreichbar sein.
3. Auf der Detailseite „Zertifikat holen & HTTPS einschalten“.

HTTP wird danach auf HTTPS umgeleitet; die Verlängerung übernimmt der certbot-Timer, nginx wird per Deploy-Hook neu geladen.

## Kommandozeile

```
sudo vhost list
sudo vhost add <domain> [--subdir DIR] [--no-protect]
sudo vhost add-local <port> [--subdir DIR] [--no-protect]   # nur 127.0.0.1
sudo vhost remove <name>                                     # sperrt sofort, entfernt nach 60 Min.
sudo vhost restore <name>                                    # Entfernen zurücknehmen
sudo vhost remove <name> --now                               # sofort entfernen (Dateien bleiben)
sudo vhost remove <name> --purge                             # sofort entfernen, auch /var/www/<name>
sudo vhost purge-due                                         # abgelaufene Vormerkungen aufräumen
sudo vhost protect <name> on|off
printf 'passwort\n' | sudo vhost user-add <name> <user>
sudo vhost protect-path <name> [pfad]                         # Schutz nur für diesen Pfad (leer = ganze Seite)
sudo vhost user-del <name> <user>
sudo vhost ip-add <name> <ip|cidr>
sudo vhost ip-del <name> <ip|cidr>
sudo vhost php <name> on|off                                  # eigener FPM-Pool an/aus
sudo vhost check-acme [name]                                  # Erreichbarkeit pruefen
sudo vhost ssl <name> on|off
sudo vhost cert-extend <name>                                 # www.<domain> ins bestehende Zertifikat nachtragen
sudo vhost set le_email <adresse>
sudo vhost set hsts on|off                                   # HSTS wirkt im Browser nach
sudo vhost render [name]                                     # nginx-Dateien neu schreiben
sudo vhost conf <name>                                        # nginx-Snippet per stdin (leer = entfernen)
sudo vhost conf-show <name>                                   # aktuelles Snippet ausgeben
sudo vhost show <name>                                        # fertige Konfiguration als ein Text
sudo vhost fix-permissions [name]                             # Rechte laut VhostLayout wiederherstellen
sudo vhost migrate-layout                                     # alte vHosts (ohne web/conf/cert/private/logs) nachziehen
```

localhost-Hosts lassen sich nur über die Kommandozeile anlegen; die Oberfläche listet sie nur.

## Honigtopf

`honeypot/` enthält die statischen Dateien für einen Host, der als Honigtopf dient
(Startseite, `robots.txt`, selbst entworfenes Favicon als SVG und ICO, eine schlichte
Seite unter `/admin/`). Ablegen mit:

```
sudo ./honeypot/install-site.sh <vhost-name>
```

Die `robots.txt` gibt nur `/` und das Favicon frei und nennt `/admin/` ausdrücklich als
ausgeschlossen. Wer darüber hinausgeht, tut das absichtlich – und `/robots.txt` wird
protokolliert, damit genau das sichtbar wird.

`honeypot/analyse.php` wertet `access.log` und `error.log` des laufenden Tages und des
Vortags aus und schreibt einen Bericht nach `research/honeypot/<datum>.md`: Sondierungen
nach Beutegruppen, Kennungen (inklusive Erkennung durchgewechselter User-Agents),
Anfragen die gar kein HTTP waren, das robots.txt-Signal, Anmeldeversuche, Tagesverlauf –
und daraus abgeleitete Vorschläge, welche Werkzeuge in der Ansicht lohnen.

Der systemd-Timer `vhost-admin-honeypot.timer` startet das täglich um 07:20, kurz nach
der Logrotation. Welche Hosts ausgewertet werden, steht in
`/etc/default/vhost-admin-honeypot`:

```
HONEYPOT_HOSTS="mfsvr.de"
```

Der Entwurf der Ansicht selbst liegt in
`docs/specs/2026-09-22-honeypot-dashboard.md`.

## Umzug auf einen anderen Server

`/var/lib/vhost-admin`, `/var/www` und `/etc/letsencrypt` mitnehmen, dann `sudo ./install.sh` – die nginx-Konfigurationen werden aus der Datenbank neu erzeugt. `install.sh` sichert vorhandene Hosts zuvor automatisch nach `/var/backups/` und ruft danach `vhost migrate-layout` auf, das jeden noch nicht umgestellten vHost auf die aktuelle Struktur (`web/conf/cert/private/logs`) bringt – auch ein frisch mitgenommener alter Stand landet also automatisch in der neuen Struktur.

### Kollision während der Migration

`vhost migrate-layout` verschiebt den bisherigen Inhalt eines vHosts erst in den Zwischenordner `<domain>/.web-migrating`, bevor er atomar zu `<domain>/web` umbenannt wird. Findet sich dabei im Zwischenordner (oder am Ziel) bereits ein gleichnamiger Eintrag, bricht die Migration mit einer Meldung ab, die den betroffenen Pfad nennt – nichts Vorhandenes wird stillschweigend überschrieben. Nach dem Auflösen der Kollision (die störende Datei im Zwischenordner oder im Basisordner entfernen oder umbenennen) setzt ein erneutes `sudo vhost migrate-layout` genau dort fort, wo der vorige Lauf stehen geblieben ist – bereits verschobene Einträge werden nicht erneut angefasst. Vor der Migration liegt ohnehin eine Sicherung unter `/var/backups/` (Tar-Archiv je Lauf, siehe `LayoutMigrator::backup()`).

## Entwicklung

- Tests: `phpunit` (261 Tests, Stand nach dem Abschlussreview vom 2026-09-20)
- Build (Tests, Buildnummer, `build/vhost-admin.tar.gz`, Commit, Push): `./build.sh`
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
- Projektwissen für die Weiterentwicklung: `.claude/CLAUDE.md`, offene Punkte in `FEATURES.md`/`OPTIMIZE.md`/`BUGS.md`

## Honigtopf-Ansicht

Ein vHost kann eine Ansicht bekommen, die zeigt, was an Angriffen und Scans auf einem
anderen (dem „Honigtopf") ankommt – je Kalendertag, mit Detailansichten.

```bash
sudo vhost php <ansichtshost> on                              # die Ansicht ist PHP
sudo vhost protect <ansichtshost> on                          # sie gehoert nicht ins Netz
sudo ./honeypot/install-dashboard.sh <ansichtshost> <honigtopf-host> [weitere ...]
```

Das Skript legt die Ansicht in den Docroot, die Auswertungsklassen nach
`private/honeypot-lib/`, trägt die Hosts in `/etc/default/vhost-admin-honeypot` ein und
wertet einmal aus. Danach läuft die Auswertung täglich um 07:20
(`vhost-admin-honeypot.timer`); ein Lauf von Hand:

```bash
sudo php /opt/vhost-admin/honeypot/analyse.php --dashboard=<ansichtshost> <honigtopf-host>
```

**Warum zwei Schritte statt einer Live-Ansicht:** Die Logs gehören root und sollen für
den Webserver unlesbar bleiben. Die Auswertung rechnet deshalb als root und legt nur das
Ergebnis dort ab, wo die Ansicht es lesen darf (`private/honeypot/`).

**Was die Ansicht zeigt:** Tagesbilanz mit Vortagsvergleich, Tagesverlauf, wonach gesucht
wurde (Beutegruppen statt einzelner Pfade), womit gesucht wurde (nennt sich / tarnt sich /
nennt nichts), das robots.txt-Signal, Anfragen die gar kein Webzugriff waren,
Anmeldeversuche und daraus abgeleitete Vorschläge. Dahinter jeweils die vollständige
Liste mit Filtern.

**Was sie bewusst nicht zeigt:** alles, was auf der Client-Adresse beruhen würde. Vor
diesem Rechner sitzt eine Adressumsetzung; jede Anfrage von aussen erscheint im Log als
`10.200.0.1`, ein `X-Forwarded-For` liegt nicht an. „Eindeutige Besucher", Herkunftsländer
oder Top-Angreifer wären deshalb erfunden. Unterschieden wird nach Verhalten.

### Echte Client-Adressen durch den WireGuard-Tunnel

Kommt der Verkehr über einen WireGuard-Tunnel mit Adressumsetzung, steht in jedem Log
die Tunneladresse (hier `10.200.0.1`). Ein `X-Forwarded-For` lässt sich auf dem
Tunnelserver **nicht** einfach hinzufügen: WireGuard arbeitet auf IP-Ebene und sieht nie
eine HTTP-Anfrage, und bei HTTPS ist der Inhalt verschlüsselt – einen Header setzen
könnte nur, wer das Zertifikat hat.

**Empfohlen: PROXY-Protokoll.** Der Tunnelserver reicht die TCP-Verbindung unverändert
durch (TLS bleibt Ende-zu-Ende, Zertifikate bleiben im LXC) und stellt ihr eine Zeile mit
der echten Adresse voran. Auf dem Tunnelserver ersetzt ein nginx-`stream`-Block die
bisherige Portweiterleitung für TCP 80 und 443 (die DNAT-Regeln für diese beiden Ports
dort entfernen, sonst kommt der Verkehr am Proxy vorbei):

```nginx
# /etc/nginx/nginx.conf auf dem Tunnelserver, auf oberster Ebene (nicht in http {})
stream {
    server {
        listen 80;
        proxy_pass <LXC-Adresse im Tunnel>:8081;
        proxy_protocol on;
    }
    server {
        listen 443;
        proxy_pass <LXC-Adresse im Tunnel>:8444;
        proxy_protocol on;
    }
}
```

`<LXC-Adresse im Tunnel>` ist dieselbe Adresse, auf die heute die DNAT-Regel zeigt. Im LXC
braucht nginx dafür eigene Ports mit `proxy_protocol` (80/443 bleiben unverändert für das
lokale Netz, denn eine Verbindung ohne PROXY-Zeile würde auf einem solchen Port abgewiesen)
und die Anweisung, der Zeile nur vom Tunnel zu glauben:

```nginx
listen 8444 ssl proxy_protocol;     # je öffentlichem Host, zusätzlich zu 443
set_real_ip_from 10.200.0.1;        # nur dem Tunnel glauben – sonst könnte jeder im
real_ip_header proxy_protocol;      # lokalen Netz eine beliebige Adresse vortäuschen
```

Diese LXC-Seite erzeugt vhost-admin noch nicht; sie gehört in den Renderer, weil die
Konfiguration generiert wird. HTTP/3 (UDP 443) kann kein PROXY-Protokoll; bleibt dort die
alte Weiterleitung, kommen QUIC-Anfragen weiter mit der Tunneladresse an.

**Alternative ohne Proxy:** auf dem Tunnelserver nur DNAT, kein MASQUERADE – dann bleibt
die Quelladresse erhalten, für alle Protokolle. Dafür müssen die Antworten des LXC über
den Tunnel zurück statt über den Heimrouter, was auf der Heimseite Policy-Routing
(connmark) verlangt. Sauberer auf IP-Ebene, aber deutlich fummeliger.

Die Honigtopf-Auswertung muss für beide Wege nicht angepasst werden: Sobald im Log eine
öffentliche Adresse steht, wird sie als Gegenstelle mit Netz und Land ausgewertet.

## Verzeichnisschutz für einen Pfad

Ohne Angabe schützt der Verzeichnisschutz die ganze Seite. Mit einem Pfad nur ihn und
alles darunter:

```bash
sudo vhost protect-path example.com /admin    # /admin, /admin/, /admin/x.php – nicht /administrator
sudo vhost protect-path example.com           # wieder die ganze Seite
```

IP-Freigaben und Benutzer gelten wie bisher: Wer von einer freigegebenen Adresse kommt,
braucht keine Anmeldung. Der Pfad darf nur Buchstaben (ohne Umlaute), Ziffern und
`. _ ~ -` enthalten – er landet als regulärer Ausdruck in der nginx-Konfiguration.

Technisch hängt die Anmeldung an einer Variablen statt an einem `location`-Block; so
ist PHP unterhalb des Pfads mitgeschützt, und Umwege wie `//admin` oder
`/x/../admin` führen nicht vorbei. Eine Grenze bleibt: Eigene `rewrite`- oder
`return`-Direktiven wirken in nginx vor der Anmeldung und können einen Pfad umlenken,
bevor er geprüft wird.
