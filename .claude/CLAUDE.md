# vhost-admin – Projektwissen

nginx-vHost-Verwaltung für Ubuntu-LXCs: PHP 8.5/SQLite-Oberfläche auf 127.0.0.1:8080, CLI `vhost` (root), Let's Encrypt per certbot-Webroot.

## Befehle
- Tests: `phpunit` (Wurzelverzeichnis; PHPUnit aus dem Ubuntu-Paket, aktuell 13.0.0)
- Build + Push: `./build.sh` (nur aus der Hauptsitzung; `--no-push` zum Probieren)
- Installation/Update auf dem LXC: `sudo ./install.sh [--owner BENUTZER]` (Wrapper, ruft `src/install.sh` auf)
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
- localhost-Host anlegen: `sudo vhost add-local <port> [--subdir DIR]` (die Oberfläche kann das absichtlich nicht)

## Struktur
- `src/lib/VhostAdmin/` – Klassen (Namespace `VhostAdmin`, Autoloader `src/bootstrap.php`)
  - `Config` Pfade/Ports · `Database` PDO/Schema · `Value/*` validierende Wertobjekte · `Vhost` Entität · `VhostKind` Enum · `VhostRepository` SQL
  - `Nginx\ConfigRenderer` reine Textausgabe · `Nginx\ReloaderInterface`/`Nginx\SystemdReloader` Reload nach Rendern
  - `Ssl\CertbotInterface`/`Ssl\CertbotClient` Zertifikatsbeschaffung
  - `VhostService` Anwendungsfälle (verbindet Repository, Renderer, Reloader, certbot)
  - `Cli\Application` CLI · `Web\AdminPage` Oberfläche · `Web\CommandRunner` ruft das CLI per `proc_open`/sudo aus der Oberfläche auf
- `src/public/index.php` – Template der Oberfläche (Docroot `/var/www/localhost-8080`)
- `src/etc/` – nginx-, sudoers-, certbot-Dateien; `src/install.sh` – eigentlicher Installer (`install.sh` im Wurzelverzeichnis ist nur ein Wrapper darauf)
- `tests/` – PHPUnit (147 Tests); `tests/Support/` – TempDir, FakeReloader, FakeCertbot
- Installationsziel: `/opt/vhost-admin` (Code), `/usr/local/sbin/vhost`, `/var/lib/vhost-admin/vhosts.sqlite`, `/etc/nginx/auth`

## Regeln (zusätzlich zur globalen CLAUDE.md)
- Tests laufen ohne root: Pfade über `Config::fromArray`, Reload/certbot über Fakes.
- nginx-Blöcke in `ConfigRenderer` sind mit 4 Leerzeichen eingerückt (nginx-Konvention); PHP-Code mit Tabs.
- Schreibende Aktionen nur über das CLI; `www-data` darf per sudoers ausschließlich `/usr/local/sbin/vhost`.
- Jeder neue Host startet gesperrt (Schutz ohne Benutzer/IP). Freigabe: IP **oder** Login (`satisfy any`).
- Testhilfsmethoden für CLI-Läufe heißen `runCli()`, nicht `run()` – `PHPUnit\Framework\TestCase::run()` ist seit PHPUnit 13 `final` und würde kollidieren.

## Bewusste Abweichungen von der globalen CLAUDE.md
- Kein Windows/ARM-Cross-Build, kein Windows-Setup, keine `.pid`: Linux-spezifische nginx/systemd/sudoers-Verwaltung ohne eigenen Daemon.
- Domains binden öffentlich (0.0.0.0:80/443) – Nutzerentscheidung vom 2026-09-17, weil Let's Encrypt und Webauftritt es erfordern. localhost-Hosts und Oberfläche binden nur 127.0.0.1.
- PHP-Projekt: gepusht werden nur vollständige Builds (`build.sh`).
