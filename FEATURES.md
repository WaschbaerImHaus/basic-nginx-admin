# Features

## Implementiert

- Domains und localhost-Hosts mit Docroot unter `/var/www/`, optional mit Unterordner
- Bunte Startseite „200“ beim Anlegen
- Verzeichnisschutz je Host: Benutzer (SHA-512-crypt) und IP-Freigaben, Logik „IP oder Login“, ganz abschaltbar
- Let's Encrypt (certbot-Webroot) mit HTTP→HTTPS-Redirect und automatischer Verlängerung
- localhost-Hosts binden ausschließlich an 127.0.0.1/[::1]
- Web-Oberfläche auf 127.0.0.1:8080 mit CSRF-Schutz; schreibt nur über das CLI (sudoers)
- CLI `vhost` mit allen Funktionen; Passwörter per stdin
- Catch-all-Server, der unbekannte Hostnamen mit 444 abweist
- Installer `install.sh` (idempotent), Build-Paket `build/vhost-admin.tar.gz`

## Offen

- `www.`-Alias für Domains (heute nur als separate Domain mit eigenem Docroot möglich)
- PHP-FPM für normale vHosts (heute nur statische Dateien)
- Backup/Export der Datenbank und Docroots
- Basic-Auth für die Oberfläche selbst, falls sie einmal im LAN erreichbar sein soll
- IPv6-Änderungen zur Laufzeit (die IPv6-Erkennung läuft nur beim Rendern)
- Vom Nutzer gewünschte Verzeichnisstruktur je vHost (noch nicht umgesetzt): statt eines einzigen Docroots je vHost ein Ordner mit fester Unterstruktur –
  - `web/` – Docroot (heute die vHost-Wurzel selbst)
  - `conf/` – nginx-Snippet, das über die Oberfläche gepflegt wird; nur eine Positivliste erlaubter Direktiven zulassen, Fehler anzeigen, wenn `nginx -t` nach dem Einbinden fehlschlägt (Snippet dann nicht aktivieren)
  - `cert/` – Symlinks auf die Let's-Encrypt-Zertifikate (`/etc/letsencrypt/live/<domain>/...`), statt die Pfade in jeder Konfiguration einzeln zu verweisen
  - `private/` – nicht öffentlich ausgelieferte Dateien des vHosts
  - `logs/` – nginx-Zugriffs-/Fehlerlog dieses einen vHosts, Rechte `root:adm 0750`
