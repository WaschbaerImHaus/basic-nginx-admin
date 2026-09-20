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
- Verzeichnisstruktur je vHost: `web/` (Docroot, mit Unterordner `web/<subdir>`), `conf/` (Snippet über die Oberfläche mit Positivliste geprüft), `cert/` (Symlinks auf `/etc/letsencrypt/live/<domain>/`), `private/` (nicht ausgeliefert), `logs/` (`access.log`/`error.log` je vHost, Rotation täglich/14 Tage); `VhostLayout` als einzige Quelle für Pfade und Soll-Rechte
- `vhost migrate-layout` bringt bestehende vHosts auf die neue Struktur (Zwischenordner `.web-migrating`, bricht bei Kollisionen ab, keine Datenverluste bei Abbruch); `vhost fix-permissions [name]` setzt die Soll-Rechte laut `VhostLayout` erneut

## Offen

- `www.`-Alias für Domains (heute nur als separate Domain mit eigenem Docroot möglich)
- PHP-FPM für normale vHosts (heute nur statische Dateien)
- Backup/Export der Datenbank und Docroots
- Basic-Auth für die Oberfläche selbst, falls sie einmal im LAN erreichbar sein soll
- IPv6-Änderungen zur Laufzeit (die IPv6-Erkennung läuft nur beim Rendern)
- Log-Anzeige in der Oberfläche (heute nur per SSH/CLI einsehbar) – bewusst zurückgestellt
- Eigene, selbst hochgeladene Zertifikate in `cert/` statt ausschließlich Let's-Encrypt-Symlinks – bewusst zurückgestellt
