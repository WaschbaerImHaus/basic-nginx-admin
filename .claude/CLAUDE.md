# vhost-admin – Projektwissen

nginx-vHost-Verwaltung für Ubuntu-LXCs: PHP 8.5/SQLite-Oberfläche auf 127.0.0.1:8080, CLI `vhost` (root), Let's Encrypt per certbot-Webroot.

## Befehle
- Tests: `phpunit` (Wurzelverzeichnis; PHPUnit aus dem Ubuntu-Paket, aktuell 13.0.0)
- Build + Push: `./build.sh` (nur aus der Hauptsitzung; `--no-push` zum Probieren)
- Installation/Update auf dem LXC: `sudo ./install.sh [--owner BENUTZER]` (Wrapper, ruft `src/install.sh` auf)
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
- localhost-Host anlegen: `sudo vhost add-local <port> [--subdir DIR]` (die Oberfläche kann das absichtlich nicht)
- Snippet setzen, Rechte reparieren, alte vHosts nachziehen: `sudo vhost conf <name>` (stdin), `sudo vhost fix-permissions [name]`, `sudo vhost migrate-layout`
- PHP pro vHost: `sudo vhost php <name> on|off` (eigener FPM-Pool als `web<id>`)

## Struktur
- `src/lib/VhostAdmin/` – Klassen (Namespace `VhostAdmin`, Autoloader `src/bootstrap.php`)
  - `Config` Pfade/Ports (inkl. `backupDir`) · `Database` PDO/Schema · `Value/*` validierende Wertobjekte, u. a. `Value\NginxSnippet` (Positivliste erlaubter Direktiven, Tokenizer über `;`/`{`/`}` mit nginx-treuer Behandlung von `#`/`"`/`'` **nur am Tokenanfang**, prüft die Klammerbilanz und lehnt `proxy_pass` auf den eigenen Rechner ab – per Adressvergleich, nicht per Zeichenkette) · `Vhost` Entität (hat **keine** Pfadmethoden mehr) · `VhostKind` Enum · `VhostRepository` SQL
  - `VhostLayout` – einzige Quelle für die Pfade **und** Soll-Rechte je vHost (`web/`, `conf/`, `cert/`, `private/`, `logs/`); liefert `DirectorySpec`-Objekte (Pfad, Owner, Gruppe, Modus, Beschreibung)
  - `Migration\LayoutMigrator` – bringt vHosts aus der alten flachen Struktur auf `web/conf/cert/private/logs`; verschiebt über den Zwischenordner `.web-migrating`, bricht bei Kollisionen mit Ausnahme ab statt zu überschreiben
  - `Php\PoolRenderer` php-fpm-Pool je vHost · `Php\FpmReloaderInterface`/`Php\SystemdFpmReloader` Reload von php-fpm · `Php\SystemUsersInterface`/`Php\SystemUsers` Systembenutzer `web<id>` anlegen
  - `Value\SnippetScope` Pfadgrenzen eines vHosts für die Snippet-Prüfung (`isInside()` löst `..` rein rechnerisch auf, ohne realpath – die Datei muss nicht existieren)
  - `Nginx\ConfigRenderer` reine Textausgabe (nutzt `VhostLayout`) · `Nginx\ReloaderInterface`/`Nginx\SystemdReloader` Reload nach Rendern
  - `Ssl\CertbotInterface`/`Ssl\CertbotClient` Zertifikatsbeschaffung
  - `VhostService` Anwendungsfälle (verbindet Repository, Renderer, Reloader, certbot, Layout)
  - `Cli\Application` CLI (u. a. `conf`, `fix-permissions`, `migrate-layout`) · `Web\AdminPage` Oberfläche · `Web\CommandRunner` ruft das CLI per `proc_open`/sudo aus der Oberfläche auf
- `src/public/index.php` – Template der Oberfläche (Docroot `/var/www/localhost-8080/web`)
- `src/etc/` – nginx-, sudoers-, certbot-, logrotate-Dateien; `src/install.sh` – eigentlicher Installer (`install.sh` im Wurzelverzeichnis ist nur ein Wrapper darauf)
- `tests/` – PHPUnit (354 Tests, Stand 2026-09-20 nach PHP/Wrapper); `tests/Support/` – TempDir, FakeReloader, FakeCertbot
- Installationsziel: `/opt/vhost-admin` (Code), `/usr/local/sbin/vhost`, `/var/lib/vhost-admin/vhosts.sqlite`, `/etc/nginx/auth`

## Regeln (zusätzlich zur globalen CLAUDE.md)
- Tests laufen ohne root: Pfade über `Config::fromArray`, Reload/certbot über Fakes.
- nginx-Blöcke in `ConfigRenderer` sind mit 4 Leerzeichen eingerückt (nginx-Konvention); PHP-Code mit Tabs.
- Schreibende Aktionen nur über das CLI; `www-data` darf per sudoers ausschließlich `/usr/local/sbin/vhost`.
- Jeder neue Host startet gesperrt (Schutz ohne Benutzer/IP). Freigabe: IP **oder** Login (`satisfy any`).
- Testhilfsmethoden für CLI-Läufe heißen `runCli()`, nicht `run()` – `PHPUnit\Framework\TestCase::run()` ist seit PHPUnit 13 `final` und würde kollidieren.
- Pfade kommen ausschließlich aus `VhostLayout`, nie aus `Vhost` selbst oder frei zusammengesetzt. `www-data` darf ausschließlich in `web/` schreiben; `conf/`, `cert/`, `logs/` gehören root (Rechte-Tabelle in der Spec vom 2026-09-18).
- **PHP läuft je vHost unter eigener Kennung `web<id>`, nie als `www-data`.** `www-data` darf per sudoers das CLI als root aufrufen; Website-PHP in diesem Pool wäre root auf dem Rechner. Diese Trennung nie aufweichen – der Smoke-Test prüft sie.
- Reihenfolge beim Einschalten von PHP ist zwingend: Systembenutzer zuerst, dann Pool-Datei. Fehlt der Benutzer, verweigert php-fpm den Start des **gesamten** Dienstes ("Unable to find user"), womit auch alle anderen Hosts kein PHP mehr hätten. `enablePhp()` nimmt bei jedem Fehler alles zurück.
- Der generierte `server`-Block enthält bewusst **kein** `location / { … }`; `try_files` steht auf Server-Ebene. Sonst scheitert ein vom Nutzer eingefügtes eigenes `location /` an "duplicate location" – belegt am 2026-09-20.
- `ssl_certificate` zeigt direkt nach `/etc/letsencrypt/live`, **nicht** über die Symlinks in `cert/`: eine defekte Symlink-Kette würde nginx am Start hindern. `cert/` ist reine Sichtbarkeit.
- Pfade in nginx-Includes immer absolut (`fastcgi_params` über `Config::$fastcgiParams`): einen relativen Pfad löst nginx je nach Aufrufweg unterschiedlich auf.
- `NginxSnippet` prüft gegen eine **Sperrliste** (seit 2026-09-20), nicht mehr gegen eine Positivliste. Erlaubt ist alles, was nicht aus dem vHost herausführt; Pfade werden gegen `VhostLayout::snippetScope()` geprüft. Neue Sperren immer dort ergänzen, nie im Renderer.
- `NginxSnippet` bildet den nginx-Tokenizer nach. Jede Abweichung von nginx ist eine potenzielle Lücke: `#`/`"`/`'` wirken nur am Tokenanfang, und Hostvergleiche laufen **immer** über `inet_pton()`/Namensauflösung, nie über Zeichenketten – dieselbe Adresse hat zu viele Schreibweisen (`2130706433`, `0177.0.0.1`, `::ffff:0:0`).
- Das Snippet in `conf/custom.conf` wird nie von Hand gepflegt, sondern ausschließlich über die Oberfläche; `NginxSnippet` prüft gegen eine Positivliste inklusive Klammerbilanz.

## Bewusste Abweichungen von der globalen CLAUDE.md
- Kein Windows/ARM-Cross-Build, kein Windows-Setup, keine `.pid`: Linux-spezifische nginx/systemd/sudoers-Verwaltung ohne eigenen Daemon.
- Domains binden öffentlich (0.0.0.0:80/443) – Nutzerentscheidung vom 2026-09-17, weil Let's Encrypt und Webauftritt es erfordern. localhost-Hosts und Oberfläche binden nur 127.0.0.1.
- PHP-Projekt: gepusht werden nur vollständige Builds (`build.sh`).
