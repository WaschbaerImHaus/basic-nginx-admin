# vhost-admin – Projektwissen

nginx-vHost-Verwaltung für Ubuntu-LXCs: PHP 8.5/SQLite-Oberfläche auf 127.0.0.1:8080, CLI `vhost` (root), Let's Encrypt per certbot-Webroot.

## Befehle
- Tests: `phpunit` (Wurzelverzeichnis; PHPUnit aus dem Ubuntu-Paket, aktuell 13.0.0)
- Build + Push: `./build.sh` (nur aus der Hauptsitzung; `--no-push` zum Probieren)
- Installation/Update auf dem LXC: `sudo ./install.sh [--owner BENUTZER]` (Wrapper, ruft `src/install.sh` auf)
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
- localhost-Host anlegen: `sudo vhost add-local <port> [--subdir DIR]` (die Oberfläche kann das absichtlich nicht)
- Snippet setzen, Rechte reparieren, alte vHosts nachziehen: `sudo vhost conf <name>` (stdin), `sudo vhost fix-permissions [name]`, `sudo vhost migrate-layout`

## Struktur
- `src/lib/VhostAdmin/` – Klassen (Namespace `VhostAdmin`, Autoloader `src/bootstrap.php`)
  - `Config` Pfade/Ports (inkl. `backupDir`) · `Database` PDO/Schema · `Value/*` validierende Wertobjekte, u. a. `Value\NginxSnippet` (Positivliste erlaubter Direktiven, Tokenizer über `;`/`{`/`}`, prüft insbesondere die Klammerbilanz) · `Vhost` Entität (hat **keine** Pfadmethoden mehr) · `VhostKind` Enum · `VhostRepository` SQL
  - `VhostLayout` – einzige Quelle für die Pfade **und** Soll-Rechte je vHost (`web/`, `conf/`, `cert/`, `private/`, `logs/`); liefert `DirectorySpec`-Objekte (Pfad, Owner, Gruppe, Modus, Beschreibung)
  - `Migration\LayoutMigrator` – bringt vHosts aus der alten flachen Struktur auf `web/conf/cert/private/logs`; verschiebt über den Zwischenordner `.web-migrating`, bricht bei Kollisionen mit Ausnahme ab statt zu überschreiben
  - `Nginx\ConfigRenderer` reine Textausgabe (nutzt `VhostLayout`) · `Nginx\ReloaderInterface`/`Nginx\SystemdReloader` Reload nach Rendern
  - `Ssl\CertbotInterface`/`Ssl\CertbotClient` Zertifikatsbeschaffung
  - `VhostService` Anwendungsfälle (verbindet Repository, Renderer, Reloader, certbot, Layout)
  - `Cli\Application` CLI (u. a. `conf`, `fix-permissions`, `migrate-layout`) · `Web\AdminPage` Oberfläche · `Web\CommandRunner` ruft das CLI per `proc_open`/sudo aus der Oberfläche auf
- `src/public/index.php` – Template der Oberfläche (Docroot `/var/www/localhost-8080/web`)
- `src/etc/` – nginx-, sudoers-, certbot-, logrotate-Dateien; `src/install.sh` – eigentlicher Installer (`install.sh` im Wurzelverzeichnis ist nur ein Wrapper darauf)
- `tests/` – PHPUnit (237 Tests); `tests/Support/` – TempDir, FakeReloader, FakeCertbot
- Installationsziel: `/opt/vhost-admin` (Code), `/usr/local/sbin/vhost`, `/var/lib/vhost-admin/vhosts.sqlite`, `/etc/nginx/auth`

## Regeln (zusätzlich zur globalen CLAUDE.md)
- Tests laufen ohne root: Pfade über `Config::fromArray`, Reload/certbot über Fakes.
- nginx-Blöcke in `ConfigRenderer` sind mit 4 Leerzeichen eingerückt (nginx-Konvention); PHP-Code mit Tabs.
- Schreibende Aktionen nur über das CLI; `www-data` darf per sudoers ausschließlich `/usr/local/sbin/vhost`.
- Jeder neue Host startet gesperrt (Schutz ohne Benutzer/IP). Freigabe: IP **oder** Login (`satisfy any`).
- Testhilfsmethoden für CLI-Läufe heißen `runCli()`, nicht `run()` – `PHPUnit\Framework\TestCase::run()` ist seit PHPUnit 13 `final` und würde kollidieren.
- Pfade kommen ausschließlich aus `VhostLayout`, nie aus `Vhost` selbst oder frei zusammengesetzt. `www-data` darf ausschließlich in `web/` schreiben; `conf/`, `cert/`, `logs/` gehören root (Rechte-Tabelle in der Spec vom 2026-09-18).
- Das Snippet in `conf/custom.conf` wird nie von Hand gepflegt, sondern ausschließlich über die Oberfläche; `NginxSnippet` prüft gegen eine Positivliste inklusive Klammerbilanz.

## Bewusste Abweichungen von der globalen CLAUDE.md
- Kein Windows/ARM-Cross-Build, kein Windows-Setup, keine `.pid`: Linux-spezifische nginx/systemd/sudoers-Verwaltung ohne eigenen Daemon.
- Domains binden öffentlich (0.0.0.0:80/443) – Nutzerentscheidung vom 2026-09-17, weil Let's Encrypt und Webauftritt es erfordern. localhost-Hosts und Oberfläche binden nur 127.0.0.1.
- PHP-Projekt: gepusht werden nur vollständige Builds (`build.sh`).
