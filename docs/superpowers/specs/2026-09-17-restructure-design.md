# Design: Neustrukturierung von vhost-admin nach den globalen CLAUDE.md-Regeln

Datum: 2026-09-17
Autor: Kurt Ingwer
Status: vom Nutzer freigegeben (Chat, 2026-09-17)

## 1. Ziel

Das bestehende, funktionierende Projekt `vhost-admin` (nginx-vHost-Verwaltung mit PHP/SQLite-Oberfläche und `vhost`-CLI) wird ohne Verhaltensänderung so umgebaut, dass es den globalen Regeln aus `~/.claude/CLAUDE.md` entspricht:

- Projektstruktur mit `src/`, `tests/`, `build/`, `debugging/`, `claude-generated/`, `dev-log/`, `research/`
- objektorientierter, domain-getriebener PHP-Code mit englischen Bezeichnern, deutschen DocBlocks (Autor „Kurt Ingwer“, Zeitstempel der letzten Änderung) und Tabulator-Einrückung
- Test-Driven Development mit PHPUnit, Tests unter `tests/`
- Build-Prozess mit Buildnummer in `src/build.txt` und installierbarem Tarball in `build/`
- Pflichtdokumentation, Projekt-`.claude/CLAUDE.md`, Recherche-Ordner, Sicherheitsreview

Nginx-Ausgabe, Pfade (`/var/www/<domain>`, `/var/www/localhost-<port>`), CLI-Befehle und die Oberfläche auf 127.0.0.1:8080 bleiben identisch.

## 2. Entscheidungen des Nutzers

| Frage | Entscheidung |
|---|---|
| nginx-Bindung (CLAUDE.md: nur 127.0.0.1) | Echte Domains bleiben auf 0.0.0.0:80/443 (Let's Encrypt, Webauftritt); localhost-Hosts und Oberfläche nur 127.0.0.1 |
| dev-Branch | Kein produktiver Betrieb, Arbeit direkt auf `main` |
| Test-Framework | PHPUnit aus dem Ubuntu-Paket (`phpunit` 13) |
| Build-Artefakt | `build/vhost-admin.tar.gz` (src/ + install.sh), Buildnummer in `src/build.txt` |
| Architektur | Schlankes OOP mit Wertobjekten (Variante A) |

## 3. Projektstruktur

```
vhost-admin/
├── install.sh                 dünner Wrapper: exec "$(dirname "$0")/src/install.sh" "$@"
├── build.sh                   Tests → Buildnummer +1 → Tarball → Commit „Build N“ → Push
├── claude.sh                  Kopie aus ~/claude.sh (Abschnitt 7 der globalen CLAUDE.md)
├── phpunit.xml                Testkonfiguration (bootstrap tests/bootstrap.php)
├── .gitignore                 build/, *.sqlite, tests/.phpunit.cache
├── src/
│   ├── build.txt              Buildnummer, Start 1, bei jedem Build +1
│   ├── install.sh             Installer (installiert zusätzlich phpunit)
│   ├── bootstrap.php          spl_autoload_register für VhostAdmin\*
│   ├── lib/VhostAdmin/        Klassen (Abschnitt 4)
│   ├── bin/vhost              Bash-Wrapper → php bin/vhost.php
│   ├── bin/vhost.php          CLI-Einstieg
│   ├── public/index.php       Oberfläche, Docroot /var/www/localhost-8080
│   ├── templates/index.html   bunte Startseite „200“
│   └── etc/                   nginx-admin.conf, nginx-default.conf, sudoers, certbot-Hook
├── tests/                     PHPUnit-Tests (Abschnitt 5)
├── build/                     Tarball, gitignored
├── debugging/                 Debug-Skripte (zunächst leer, .gitkeep)
├── claude-generated/          sonstige von Claude erzeugte Dateien
├── dev-log/                   YYYY-MM-DD-dev.log
├── research/                  OPTIMIZED_WORKER.md, Recherche-Notizen
├── docs/superpowers/specs/    dieses Dokument
├── .claude/CLAUDE.md          Projektwissen und Abweichungen
└── README.md, BUGS.md, FEATURES.md, OPTIMIZE.md, MEMORY.md,
    SECURITY_RISKS.md, SECURITY_FIXED.md
```

Installationspfade auf dem Zielsystem bleiben: `/opt/vhost-admin` (lib, bin, templates, bootstrap), `/usr/local/sbin/vhost`, `/var/www/localhost-8080` (Oberfläche), `/var/lib/vhost-admin/vhosts.sqlite`, `/etc/nginx/auth`, `/etc/sudoers.d/vhost-admin`.

## 4. Code-Architektur (`src/lib/VhostAdmin/`)

Namespace `VhostAdmin`, PSR-4-artiger Autoloader in `src/bootstrap.php` (Klasse `VhostAdmin\Foo\Bar` → `lib/VhostAdmin/Foo/Bar.php`). Kein Composer.

| Klasse | Aufgabe | Abhängigkeiten |
|---|---|---|
| `Config` | Alle Pfade und Ports als `readonly`-Eigenschaften; `Config::defaults()` und `Config::fromArray()` (Tests zeigen auf Temp-Verzeichnisse) | – |
| `Database` | Öffnet PDO-SQLite, legt Schema an (`initSchema()`) | Config |
| `Value\DomainName` | Validiert Domain (Kleinschreibung, RFC-Regex, max. 253 Zeichen, keine localhost-Varianten) | – |
| `Value\Port` | 1–65535, nicht 80/443/Admin-Port | Config (Admin-Port) |
| `Value\SubDirectory` | optionaler Unterordner, Segmente `[A-Za-z0-9_][A-Za-z0-9_.-]*`, kein `..`, keine führenden `/` | – |
| `Value\Username` | `[A-Za-z0-9_.@-]{1,64}` | – |
| `Value\Cidr` | IPv4/IPv6 mit optionaler Maske (≤32 / ≤128), normalisiert | – |
| `VhostKind` (Enum) | `Domain`, `Localhost` | – |
| `Vhost` | Entität: id, name, kind, port, subdir, protect, ssl, createdAt; `slug()`, `baseDir()`, `docroot()`, `isLocal()` | Config |
| `VhostRepository` | `all()`, `byName()`, `insert()`, `delete()`, `setProtect()`, `setSsl()`, `users()`, `upsertUser()`, `deleteUser()`, `ips()`, `addIp()`, `deleteIp()`, `setting()`, `setSetting()` | Database, Config |
| `Nginx\ConfigRenderer` | `serverConfig(Vhost): string`, `authSnippet(Vhost, users, ips): string`, `htpasswd(users): string`; rein, keine I/O; IPv6-Flag wird übergeben | Config |
| `Nginx\ReloaderInterface` | `reload(): void` (wirft `RuntimeException`) | – |
| `Nginx\SystemdReloader` | `nginx -t`, `systemctl reload-or-restart nginx`, wartet bis alte Worker weg sind | – |
| `Ssl\CertbotInterface` | `obtain(string $domain, string $webroot, string $email): string` (Ausgabe), wirft bei Fehler | – |
| `Ssl\CertbotClient` | ruft `certbot certonly --webroot …` | – |
| `VhostService` | Anwendungsfälle: `createDomain`, `createLocal`, `remove(purge)`, `setProtection`, `addUser`, `removeUser`, `addIp`, `removeIp`, `enableSsl`, `disableSsl`, `render`, `renderAll`, `setLetsEncryptEmail`; schreibt Dateien, setzt Besitzer (tolerant ohne root), fixiert DB-Rechte | Repository, ConfigRenderer, ReloaderInterface, CertbotInterface, Config |
| `Cli\Application` | `parse(array $argv)` → Befehl, Positionsargumente, Optionen (`--k=v`, `--subdir v`, Flags); `run()` → Exit-Code; Passwort per stdin | VhostService, Repository |
| `Web\CommandRunner` | führt `sudo -n /usr/local/sbin/vhost …` per `proc_open` aus, optional stdin | Config |
| `Web\AdminPage` | Request-Verarbeitung: CSRF, POST-Aktionen → CommandRunner, Flash, Redirect; Lesezugriff über Repository; stellt Daten für das Template bereit | Repository, CommandRunner, Config |

`public/index.php` bleibt das HTML-Template (PHP-Alternativsyntax) und delegiert alles Logische an `AdminPage`. `bin/vhost.php` baut die Objekte zusammen (Composition Root) und ruft `Cli\Application::run()`.

Fehlerbehandlung: Wertobjekte werfen `InvalidArgumentException`, Service und Reloader `RuntimeException`; die CLI fängt `Throwable`, schreibt „Fehler: …“ nach stderr und liefert Exit 1 (Usage-Fehler: Exit 2). Die Oberfläche zeigt stdout+stderr des CLI als Flash.

## 5. Tests (`tests/`, PHPUnit)

- `tests/bootstrap.php`: lädt `src/bootstrap.php`, stellt Temp-Verzeichnis-Helfer bereit
- `Value/*Test.php`: gültige und ungültige Eingaben je Wertobjekt inkl. Grenzfällen (Länge 253/254, Port 0/65536/8080, `..`, führende `/`, Umlaut-Domain, IPv6-Maske 129)
- `VhostTest`: slug/baseDir/docroot für Domain, Domain mit Unterordner, localhost
- `Nginx/ConfigRendererTest`: erwartete Ausgabe für Domain ohne SSL, mit SSL, localhost; Auth-Snippet an/aus, mit IPs; htpasswd-Zeilen; mit und ohne IPv6
- `VhostRepositoryTest`: temporäre SQLite-Datei; anlegen, doppelt anlegen (Exception), Benutzer-Upsert, IP-Duplikat ignoriert, Cascade beim Löschen, Einstellungen setzen/löschen
- `VhostServiceTest`: Temp-`www_root`, Temp-`sites_*`/`auth_dir`, `FakeReloader`, `FakeCertbot` (aus `tests/Support/`): Ordner und index.html entstehen, bestehende index.html bleibt, Auth-Dateien werden geschrieben, Schutz aus → Snippet ohne `deny`, SSL ohne E-Mail → Exception und `ssl=0`, Certbot-Fehler → `ssl=0`, Certbot-Erfolg → `ssl=1` und 443-Block, Purge löscht nur unterhalb von `www_root`, Reloader wird pro Änderung genau einmal gerufen
- `Cli/ApplicationTest`: Parsing der Optionen, unbekannter Befehl → Exit 2, `help` → Exit 0, fehlendes Argument → Exit 1

Root-abhängige Operationen (chown/chgrp, systemd, certbot) werden in Tests nicht ausgeführt: Besitzerwechsel ist im Service tolerant (`@chown`, Fehler ignoriert, wenn nicht root), Reload und Certbot laufen über die Fakes.

## 6. Build (`build.sh`)

1. `phpunit` ausführen; bei Fehlschlag Abbruch ohne Buildnummer-Änderung
2. `src/build.txt` um 1 erhöhen
3. `build/vhost-admin.tar.gz` erzeugen (Inhalt: `src/`, `install.sh`, `README.md`)
4. `git add` (src/build.txt und alle Änderungen), Commit „Build N: …“, Push nach `origin main`

Der Push erfolgt ausschließlich aus der Hauptsitzung (nie aus Subagenten, globale CLAUDE.md Abschnitt 2/10). Zwischen Builds wird nicht gepusht (PHP-Projekt: nur vollständige Builds).

## 7. Dokumentation

Alle Dateien aus Abschnitt 8 der globalen CLAUDE.md werden angelegt und inhaltlich gefüllt:

- `README.md`: Endnutzerhandbuch (Installation, Oberfläche, CLI, Verzeichnisschutz, Let's Encrypt, Umzug)
- `BUGS.md`: Abschnitte „Offen“ und „Behoben“ (behoben: asynchroner nginx-Reload, der Änderungen erst verzögert wirksam machte)
- `FEATURES.md`: implementiert / offen (z. B. www-Alias, PHP in vHosts, Backup)
- `OPTIMIZE.md`: Optimierungsvorschläge
- `MEMORY.md`: Sitzungsverlauf und Entscheidungen
- `SECURITY_RISKS.md` / `SECURITY_FIXED.md`: Ergebnis des Sicherheitsreviews (Skill `security-review`, Plugin `security-guidance`)
- `dev-log/2026-09-17-dev.log`: Einträge mit Datum und Uhrzeit
- `research/OPTIMIZED_WORKER.md`: Projektwissen für die eigene Arbeit; `research/*.md`: Hintergrundrecherche (nginx-Auth, certbot-Webroot, PHP-FPM-Härtung)
- `.claude/CLAUDE.md`: Projektwissen, Befehle, Abweichungen (Abschnitt 8)
- `~/USER.md`: Chat-Schreibstil des Nutzers

## 8. Bewusste Abweichungen von der globalen CLAUDE.md

| Regel | Abweichung | Begründung |
|---|---|---|
| Windows/Linux × x86/ARM, Cross-Compilation, Windows-Setup | entfällt | nginx-Verwaltung mit sudoers/systemd ist Linux-spezifisch; PHP/Bash sind interpretiert und plattformneutral |
| `.pid` in `build/` | entfällt | kein eigener Daemon; nginx und PHP-FPM laufen unter systemd |
| Server binden nur 127.0.0.1 | Domains auf 0.0.0.0:80/443 | Nutzerentscheidung 2026-09-17: öffentliche Domains und Let's Encrypt erfordern öffentliche Bindung |

## 9. Nicht Teil dieses Umbaus

Keine neuen Funktionen (kein www-Alias, kein PHP in normalen vHosts, kein Backup). Solche Punkte landen in `FEATURES.md` unter „Offen“.
