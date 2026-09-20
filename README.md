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
sudo vhost user-del <name> <user>
sudo vhost ip-add <name> <ip|cidr>
sudo vhost ip-del <name> <ip|cidr>
sudo vhost php <name> on|off                                  # eigener FPM-Pool an/aus
sudo vhost check-acme [name]                                  # Erreichbarkeit pruefen
sudo vhost ssl <name> on|off
sudo vhost set le_email <adresse>
sudo vhost render [name]                                     # nginx-Dateien neu schreiben
sudo vhost conf <name>                                        # nginx-Snippet per stdin (leer = entfernen)
sudo vhost conf-show <name>                                   # aktuelles Snippet ausgeben
sudo vhost fix-permissions [name]                             # Rechte laut VhostLayout wiederherstellen
sudo vhost migrate-layout                                     # alte vHosts (ohne web/conf/cert/private/logs) nachziehen
```

localhost-Hosts lassen sich nur über die Kommandozeile anlegen; die Oberfläche listet sie nur.

## Umzug auf einen anderen Server

`/var/lib/vhost-admin`, `/var/www` und `/etc/letsencrypt` mitnehmen, dann `sudo ./install.sh` – die nginx-Konfigurationen werden aus der Datenbank neu erzeugt. `install.sh` sichert vorhandene Hosts zuvor automatisch nach `/var/backups/` und ruft danach `vhost migrate-layout` auf, das jeden noch nicht umgestellten vHost auf die aktuelle Struktur (`web/conf/cert/private/logs`) bringt – auch ein frisch mitgenommener alter Stand landet also automatisch in der neuen Struktur.

### Kollision während der Migration

`vhost migrate-layout` verschiebt den bisherigen Inhalt eines vHosts erst in den Zwischenordner `<domain>/.web-migrating`, bevor er atomar zu `<domain>/web` umbenannt wird. Findet sich dabei im Zwischenordner (oder am Ziel) bereits ein gleichnamiger Eintrag, bricht die Migration mit einer Meldung ab, die den betroffenen Pfad nennt – nichts Vorhandenes wird stillschweigend überschrieben. Nach dem Auflösen der Kollision (die störende Datei im Zwischenordner oder im Basisordner entfernen oder umbenennen) setzt ein erneutes `sudo vhost migrate-layout` genau dort fort, wo der vorige Lauf stehen geblieben ist – bereits verschobene Einträge werden nicht erneut angefasst. Vor der Migration liegt ohnehin eine Sicherung unter `/var/backups/` (Tar-Archiv je Lauf, siehe `LayoutMigrator::backup()`).

## Entwicklung

- Tests: `phpunit` (261 Tests, Stand nach dem Abschlussreview vom 2026-09-20)
- Build (Tests, Buildnummer, `build/vhost-admin.tar.gz`, Commit, Push): `./build.sh`
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
- Projektwissen für die Weiterentwicklung: `.claude/CLAUDE.md`, offene Punkte in `FEATURES.md`/`OPTIMIZE.md`/`BUGS.md`
