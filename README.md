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

| Host | Docroot |
|---|---|
| `example.com` | `/var/www/example.com/` (oder `/var/www/example.com/<unterordner>/`) |
| `localhost:3000` | `/var/www/localhost-3000/` |

Beim Anlegen entsteht eine bunte `index.html` („200“), sofern noch keine liegt. Die Ordner gehören dem bei der Installation gewählten Benutzer (Gruppe `www-data`).

## Verzeichnisschutz

Jeder neue Host ist zunächst gesperrt: Schutz aktiv, aber ohne Benutzer und ohne IP – niemand kommt hinein. Freigabe: eine IP/ein Netz **oder** ein gültiger Login genügt. Oder den Schutz ganz abschalten. Der Pfad `/.well-known/acme-challenge/` bleibt immer frei, damit certbot arbeiten kann.

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
sudo vhost remove <name> [--purge]                           # --purge löscht auch /var/www/<name>
sudo vhost protect <name> on|off
printf 'passwort\n' | sudo vhost user-add <name> <user>
sudo vhost user-del <name> <user>
sudo vhost ip-add <name> <ip|cidr>
sudo vhost ip-del <name> <ip|cidr>
sudo vhost ssl <name> on|off
sudo vhost set le_email <adresse>
sudo vhost render [name]                                     # nginx-Dateien neu schreiben
```

localhost-Hosts lassen sich nur über die Kommandozeile anlegen; die Oberfläche listet sie nur.

## Umzug auf einen anderen Server

`/var/lib/vhost-admin`, `/var/www` und `/etc/letsencrypt` mitnehmen, dann `sudo ./install.sh` – die nginx-Konfigurationen werden aus der Datenbank neu erzeugt.

## Entwicklung

- Tests: `phpunit` (147 Tests, Stand nach den Sicherheits-Fix-Wellen vom 2026-09-17)
- Build (Tests, Buildnummer, `build/vhost-admin.tar.gz`, Commit, Push): `./build.sh`
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
- Projektwissen für die Weiterentwicklung: `.claude/CLAUDE.md`, offene Punkte in `FEATURES.md`/`OPTIMIZE.md`/`BUGS.md`
