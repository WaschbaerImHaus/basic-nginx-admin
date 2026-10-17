# Features

Der Honigtopf ist seit 2026-10-15 ein eigenes Projekt (`git@bitbucket.org:von-null/honeypot.git`,
lokal `~/honeypot`); seine Features – implementierte wie offene – stehen dort in `FEATURES.md`.

## Implementiert

- **PROXY-Protokoll für einen vorgeschalteten Tunnel** (2026-10-16, Nutzerauftrag): `vhost set proxy_protocol on <ip[,ip]> [http https] | off`, Standard aus. Öffentliche Hosts lauschen zusätzlich auf 8081/8444 mit `proxy_protocol` (80/443 unverändert), `realip.conf` (übernimmt die von Hand geschriebene) glaubt die Client-Adresse nur dem Tunnel; `port_in_redirect off` und `SERVER_PORT` 80/443 für PHP. Rücknahme bei Reload-Fehler, Smoke-Test-Abschnitt. Den `stream`-Block auf dem Tunnelserver setzt der Nutzer (README).

- **Verzeichnisschutz: Bereich je Benutzer statt je Domain** (2026-09-27, Nutzerwunsch): Jeder Benutzer hat einen eigenen Pfad (leer = ganze Seite); geschützt ist jeder Pfad, den ein Benutzer hat. In einen Bereich darf, wessen Pfad ihn abdeckt (ganze Seite überall, `/admin` auch in `/admin/intern`, nicht in `/administrator`). Je Bereich eine htpasswd-Datei, nginx wählt sie per `map $uri` (`auth_basic_user_file` mit Variable). Oberfläche: Feld „Bereich" beim Anlegen und je Benutzer „Bereich setzen"; CLI `user-add --path`, `user-path`. Ein bisheriger Domain-Pfad wird beim Update auf alle Benutzer übertragen; `protect-path` verweist auf den neuen Befehl.

- **Verzeichnisschutz nur für einen Pfad** (2026-09-25, Nutzerwunsch): optionaler Pfad je vHost, in der Oberfläche unter „Verzeichnisschutz“ oder per `vhost protect-path <name> [pfad]`; leer = ganze Seite. `/admin` schützt `/admin`, `/admin/` und alles darunter – auch PHP –, nicht aber `/administrator`. Umgesetzt ohne `location`-Block über eine Realm-Variable (`geo` für IP-Freigaben, `map` auf den normalisierten `$uri`); IP-Freigabe oder Login gelten wie bisher. `vhost list` zeigt den Pfad (`schutz:/admin`).

- **Zertifikat um den www-Namen erweitern** (2026-09-22): `vhost cert-extend <name>` und ein Knopf in der Oberfläche tragen `www.<domain>` in ein bereits bestehendes Zertifikat nach. Nötig, weil `ssl on` certbot bei vorhandenem Zertifikat bewusst überspringt – wer die www-Umleitung erst nach der Ausstellung einschaltet, hätte sonst dauerhaft ein Zertifikat ohne den umgeleiteten Namen.

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
- **HTTP/3 je Host abschaltbar** (2026-10-17, Nutzerwunsch): `vhost http3 <name> on|off`, Standard an (Spalte `vhosts.http3`). Aus = kein `listen 443 quic`, kein `http3 on`, kein `Alt-Svc` – für den Honigtopf, der die echte Client-Adresse über das PROXY-Protokoll braucht (QUIC kann es nicht)

## Offen

**Nutzervorgabe vom 2026-09-27:** „Der nginx admin ist an sich schon sehr komplett. Neue
Features sind da erstmal nicht wirklich geplant.“

- **Verwaltung:** keine neuen Features ohne ausdrücklichen Wunsch, nur Pflege (Bugs, Sicherheit).
  Die Liste unter „Zurückgestellt“ ist ein Merkzettel und wird nicht von selbst abgearbeitet.

## Zurückgestellt (nur auf ausdrücklichen Wunsch)

- Mehrere PHP-Versionen pro Host wählbar machen (aktuell immer die höchste installierte).
- Pool-Kennwerte (`pm.max_children` u.a.) pro Host einstellbar; derzeit für alle gleich (`ondemand`, 10).
- `rewrites.conf` als eigenes Textfeld in der Oberfläche (die Datei wird schon eingebunden, gepflegt wird sie per Hand).
- gzip-Vorgaben zentral in `nginx.conf` setzen.
- Systembenutzer eines gelöschten vHosts aufräumen (bleibt derzeit absichtlich bestehen, siehe SECURITY_RISKS.md).

- Backup/Export der Datenbank und Docroots
- Basic-Auth für die Oberfläche selbst, falls sie einmal im LAN erreichbar sein soll
- PROXY-Protokoll für öffentliche Hosts (echte Client-Adressen durch den WireGuard-Tunnel, beschrieben in der README des Projekts honeypot) – braucht zuerst die Einrichtung auf dem Tunnelserver durch den Nutzer; die LXC-Seite gehört in den Renderer
- `default_server` auf Port 443 mit `ssl_reject_handshake` – Entscheidung des Nutzers, weil IP-Scanner dann aus dem Honigtopf verschwinden (SECURITY_RISKS.md)
- IPv6-Änderungen zur Laufzeit (die IPv6-Erkennung läuft nur beim Rendern)
- Log-Anzeige in der Oberfläche (heute nur per SSH/CLI einsehbar) – bewusst zurückgestellt
- Eigene, selbst hochgeladene Zertifikate in `cert/` statt ausschließlich Let's-Encrypt-Symlinks – bewusst zurückgestellt
