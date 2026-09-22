# Features

## Implementiert

- **Honigtopf-Ansicht mit Detailansicht** (2026-09-22): Unter einem eigenen vHost (eingerichtet mit `honeypot/install-dashboard.sh <ansichtshost> <honigtopf-host>`) zeigt eine PHP-Seite je Kalendertag, was an Angriffen und Scans ankam: Tagesbilanz mit Vortagsvergleich, Tagesverlauf, Beutegruppen, Kennungsklassen, robots.txt-Signal, Dienstsondierungen, Anmeldeversuche, abgeleitete Vorschläge. Dahinter fünf Detailansichten (alle gesuchten Pfade mit Gruppenfilter, alle Kennungen, Rohdaten der Nicht-HTTP-Anfragen, Anmeldeversuche, Ereignisliste mit Filter und Suche). Die Ansicht rechnet nichts selbst: Sie liest die Tagesberichte, die die Auswertung als root nach `private/honeypot/` legt – die Logs selbst bleiben für den Webserver unlesbar. Keine Kennzahl auf Basis der Client-Adresse; vor dem Rechner sitzt eine Adressumsetzung, jede Anfrage von aussen erscheint als `10.200.0.1`.

- **Zertifikat um den www-Namen erweitern** (2026-09-22): `vhost cert-extend <name>` und ein Knopf in der Oberfläche tragen `www.<domain>` in ein bereits bestehendes Zertifikat nach. Nötig, weil `ssl on` certbot bei vorhandenem Zertifikat bewusst überspringt – wer die www-Umleitung erst nach der Ausstellung einschaltet, hätte sonst dauerhaft ein Zertifikat ohne den umgeleiteten Namen.

- **Honigtopf und tägliche Log-Auswertung** (2026-09-22): statische Honigtopf-Seite mit `robots.txt` und eigenem Favicon (`honeypot/`), tägliche Auswertung von `access.log` und `error.log` samt Vortagsvergleich (`honeypot/analyse.php`, systemd-Timer 07:20), Bericht nach `research/honeypot/<datum>.md` mit abgeleiteten Werkzeugvorschlägen. Entwurf der Ansicht: `docs/specs/2026-09-22-honeypot-dashboard.md`.

- **www-Umleitung je Hauptdomain** (2026-09-22): Voreingestellt wird ohne `www` ausgeliefert, `www.<domain>` leitet mit 301 dorthin um; umschaltbar mit `vhost www <name> bare|www`. Kein „aus“ – einer der beiden Namen liefert aus. Nur für Hauptdomains, nicht für Unterdomains. Beide Namen zeigen auf denselben Docroot; ein Verzeichnis `www.<domain>` entsteht nie. certbot beantragt den Nebennamen mit, **sofern er erreichbar ist** – sonst liesse ein Name ohne DNS-Eintrag den ganzen Antrag scheitern.

- **Fertige Konfiguration ansehen** (2026-09-22): `vhost show <name>` und ein Abschnitt in der Oberfläche zeigen den erzeugten `server`-Block mit eingesetztem Verzeichnisschutz und eigenen Direktiven als einen Text – mit Zeilennummern und hervorgehobenem eigenem Abschnitt. Systemdateien wie `fastcgi_params` bleiben als Verweis stehen.

- **Erzeugte Passwörter für den Verzeichnisschutz** (2026-09-22): Das Eingabefeld ist durch ein nur lesbares Feld mit einem erzeugten Passwort ersetzt (20 Zeichen, Gross-/Kleinbuchstaben, Ziffern, `@=#+.,_-:;`, aus `random_int`). Nach dem Anlegen wird es einmal gross angezeigt und ist danach nicht mehr auslesbar. Je Benutzer gibt es „Passwort neu“. Über das CLI bleibt jedes beliebige Passwort möglich.

- **Oberfläche neu gestaltet** (2026-09-21): volle Fensterbreite, dauerhafte vHost-Liste links mit Zustandsanzeige, Arbeitsbereich rechts. Der Direktiven-Editor hat Zeilennummern, füllt gut die halbe Fensterhöhe, ist grösser ziehbar, der Tabulator rückt ein. Abgelehnte Eingaben bleiben erhalten, die beanstandete Zeile wird markiert und angesprungen. Dunkles Farbschema nach Systemeinstellung, keine Schriften oder Skripte aus dem Netz.

- **Sicherheit, Cache und Komprimierung im Wrapper** (2026-09-20): `server_tokens off`, `nosniff`, `Referrer-Policy`, `X-Frame-Options`, bei HTTPS zusätzlich HSTS (180 Tage, abschaltbar mit `vhost set hsts off`). `gzip_types` für CSS/JS/JSON/XML/SVG/WASM/Schriften – vorher komprimierte nginx nur HTML. Browser-Cache über `expires` (CSS/JS 7 Tage, Medien 30 Tage, HTML `no-cache`), bewusst ohne `add_header`, weil das die geerbten Sicherheitskopfzeilen im jeweiligen `location`-Block verwerfen würde.

- **Entfernen mit Schonfrist** (2026-09-20): Die Rückfrage sagt jetzt, was geschieht; der vHost wird sofort gesperrt (nginx antwortet nicht mehr), der Eintrag verschwindet erst nach 60 Minuten. Bis dahin „Entfernen zurücknehmen" in der Oberfläche bzw. `vhost restore`. Endgültiges Aufräumen über den systemd-Timer `vhost-admin-purge.timer`. Dateien unter `/var/www` werden weiterhin nie gelöscht.

- **Erreichbarkeitstest über den ACME-Pfad** (2026-09-20): Marker mit eigener Kennung je vHost, Abfrage beim Aufruf der Oberfläche (parallel, nicht laufend), grün/rot in Übersicht und Detailseite. Vor der Zertifikatsausstellung verpflichtend – ohne erreichbaren ACME-Pfad wird `ssl on` abgelehnt, damit kein Fehlversuch gegen das Kontingent von Let's Encrypt zählt. CLI: `vhost check-acme [name]`.

- **Generierte Konfiguration im ISPConfig-Stil** (2026-09-20): viel vorgegeben, eigener Bereich am Ende des `server`-Blocks zwischen `# >>>>`/`# <<<<`. Zusätzlich Dotfile-Sperre, `favicon.ico`, `robots.txt`, HTTP/3 mit QUIC und `Alt-Svc` bei Hosts mit Zertifikat. `try_files` bewusst auf Server-Ebene statt in einem generierten `location /`, damit ein eingefügtes eigenes `location /` nicht mit "duplicate location" scheitert.
- **Sperrliste statt Positivliste für eigene Direktiven** (2026-09-20): erlaubt ist alles, was nicht aus dem vHost herausführt – ein Fragment aus einem anderen Projekt lässt sich per copy-paste einsetzen.
- **PHP pro vHost** (2026-09-20): eigener php-fpm-Pool mit eigenem Systembenutzer `web<id>`, `open_basedir` auf den vHost begrenzt, Fehlerlog in `[domain]/logs/php.log`, Schalter in der Oberfläche und `vhost php <name> on|off`. Ohne PHP werden `.php`-Dateien mit 404 abgewiesen statt als Text ausgeliefert.

- Domains und localhost-Hosts mit Docroot unter `/var/www/`, optional mit Unterordner
- Bunte Startseite „200“ beim Anlegen
- Verzeichnisschutz je Host: Benutzer (SHA-512-crypt) und IP-Freigaben, Logik „IP oder Login“, ganz abschaltbar
- Let's Encrypt (certbot-Webroot) mit HTTP→HTTPS-Redirect und automatischer Verlängerung
- localhost-Hosts binden ausschließlich an 127.0.0.1/[::1]
- Web-Oberfläche auf 127.0.0.1:8080 mit CSRF-Schutz; schreibt nur über das CLI (sudoers)
- CLI `vhost` mit allen Funktionen; Passwörter per stdin
- Catch-all-Server, der unbekannte Hostnamen mit 444 abweist
- Installer `install.sh` (idempotent), Build-Paket `build/vhost-admin.tar.gz`
- Verzeichnisstruktur je vHost: `web/` (Docroot, mit Unterordner `web/<subdir>`), `conf/` (Snippet über die Oberfläche mit Positivliste geprüft), `cert/` (Symlinks auf `/etc/letsencrypt/live/<domain>/`), `private/` (nicht ausgeliefert), `logs/` (`access.log`/`error.log` je vHost, Rotation täglich/14 Tage); `VhostLayout` als einzige Quelle für Pfade und Soll-Rechte
- `vhost migrate-layout` bringt bestehende vHosts auf die neue Struktur (Zwischenordner `.web-migrating`, bricht bei Kollisionen ab, keine Datenverluste bei Abbruch); `vhost fix-permissions [name]` setzt die Soll-Rechte laut `VhostLayout` erneut

## Offen

- Honigtopf-Ansicht unter `bienchen.mfsvr.de` nach der Spec bauen (die Spec liegt vor, die Datenquelle auch). Voraussetzung: `bienchen.mfsvr.de` muss per DNS auf diesen Server zeigen – tut es derzeit nicht.

- Mehrere PHP-Versionen pro Host wählbar machen (aktuell immer die höchste installierte).
- Pool-Kennwerte (`pm.max_children` u.a.) pro Host einstellbar; derzeit für alle gleich (`ondemand`, 10).
- `rewrites.conf` als eigenes Textfeld in der Oberfläche (die Datei wird schon eingebunden, gepflegt wird sie per Hand).
- gzip-Vorgaben zentral in `nginx.conf` setzen.
- Systembenutzer eines gelöschten vHosts aufräumen (bleibt derzeit absichtlich bestehen, siehe SECURITY_RISKS.md).

- `www.`-Alias für Domains (heute nur als separate Domain mit eigenem Docroot möglich)
- PHP-FPM für normale vHosts (heute nur statische Dateien)
- Backup/Export der Datenbank und Docroots
- Basic-Auth für die Oberfläche selbst, falls sie einmal im LAN erreichbar sein soll
- IPv6-Änderungen zur Laufzeit (die IPv6-Erkennung läuft nur beim Rendern)
- Log-Anzeige in der Oberfläche (heute nur per SSH/CLI einsehbar) – bewusst zurückgestellt
- Eigene, selbst hochgeladene Zertifikate in `cert/` statt ausschließlich Let's-Encrypt-Symlinks – bewusst zurückgestellt
