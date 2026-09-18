# Design: Verzeichnisstruktur je vHost (web, conf, cert, private, logs)

Datum: 2026-09-18
Autor: Kurt Ingwer
Status: vom Nutzer freigegeben (Chat, 2026-09-18)
Vorgänger: `2026-09-17-restructure-design.md` (Umbau auf OOP, Build 1)

## 1. Ziel

Jeder vHost bekommt unterhalb von `/var/www/<slug>/` eine feste Unterstruktur statt eines flachen Docroots:

- `web/` – der ausgelieferte Docroot
- `conf/` – ein über die Oberfläche gepflegtes nginx-Snippet mit begrenztem Direktivenumfang (Vorbild ISPConfig)
- `cert/` – Symlinks auf die Let's-Encrypt-Zertifikate der Domain
- `private/` – Ablage außerhalb des Docroots
- `logs/` – Zugriffs- und Fehlerlog dieses einen vHosts

Bestehende Hosts werden beim Update automatisch migriert. Verhalten und Bedienung bleiben sonst unverändert.

## 2. Entscheidungen des Nutzers

| Frage | Entscheidung |
|---|---|
| Geltungsbereich | Alle Hosts – Domains, localhost-Hosts und die Verwaltungsoberfläche selbst |
| `--subdir` | Liegt künftig unterhalb von `web/`: Docroot = `web/<subdir>` |
| `conf/` | Kein Handbetrieb: ein Textfeld in der Oberfläche erzeugt die Datei; Positivliste erlaubter Direktiven; schlägt `nginx -t` fehl, wird die Meldung angezeigt und die Änderung **nicht** übernommen |
| Erlaubter Direktivenumfang | „Verhalten & Header“ (siehe Abschnitt 5) |
| `cert/` | Ausschließlich Symlinks auf `/etc/letsencrypt/live/<domain>/`, keine eigenen Zertifikate |
| `logs/` | nginx schreibt als root; der Domain-Besitzer darf lesen, nicht schreiben; Logrotate täglich, 14 Tage |
| Migration | Automatisch beim Update, mit vorheriger Sicherung als Tar-Archiv |
| Umsetzung | Eigene Klasse `VhostLayout` statt Pfadmethoden auf der Entität |

## 3. Verzeichnisse und Rechte

| Ordner | Inhalt | Besitzer:Gruppe | Modus | Wer schreibt |
|---|---|---|---|---|
| `web/` | Docroot (mit `--subdir`: `web/<subdir>`) | `<owner>:www-data` | `02775` | Nutzer und Anwendungen |
| `conf/` | `custom.conf` | `root:www-data` | `0750` | nur root über das CLI |
| `cert/` | Symlinks auf die Zertifikatsdateien | `root:<owner>` | `0750` | nur root |
| `private/` | beliebige nicht ausgelieferte Dateien | `<owner>:<owner>` | `0750` | nur der Nutzer |
| `logs/` | `access.log`, `error.log` | `root:<owner>` | `0750` | nur nginx (root) |

`<owner>` ist der bei der Installation gewählte Benutzer (`Config::$wwwOwner`).

**Begründung der Rechte:** `www-data` erhält Schreibzugriff ausschließlich auf `web/`. Ein dort untergeschobener Symlink kann damit weder das Snippet noch die Zertifikatsverweise noch die Logs erreichen. `conf/` ist für `www-data` **lesbar** (Gruppe), weil die Oberfläche das Snippet im Textfeld anzeigt; geschrieben wird es nur vom root-CLI. Die Datei `custom.conf` erhält `root:www-data 0640`.

Für `logs/` ist Schreibzugriff durch `www-data` ausgeschlossen: nginx öffnet die Logdateien als root, ein Symlink an dieser Stelle würde sonst beliebige Dateien überschreibbar machen.

## 4. Klasse `VhostAdmin\VhostLayout`

Neue Klasse, die als einzige Quelle für Pfade **und** Soll-Rechte dient. Grund für eine eigene Klasse statt Methoden auf `Vhost`: Service, Installer, Migration und der neue Befehl `fix-permissions` müssen dieselbe Rechte-Tabelle anwenden; liegt sie an einer Stelle, kann sie nicht auseinanderlaufen (genau ein solches Auseinanderlaufen war der kritische Befund vom 2026-09-17).

```
VhostLayout::__construct(Config $config)

baseDir(Vhost): string        /var/www/<slug>
webDir(Vhost): string         <base>/web
docroot(Vhost): string        <base>/web[/<subdir>]
confDir(Vhost): string        <base>/conf
confFile(Vhost): string       <base>/conf/custom.conf
certDir(Vhost): string        <base>/cert
privateDir(Vhost): string     <base>/private
logsDir(Vhost): string        <base>/logs
accessLog(Vhost): string      <base>/logs/access.log
errorLog(Vhost): string       <base>/logs/error.log
directories(Vhost): list<DirectorySpec>
needsMigration(Vhost): bool   true, wenn <base> existiert, aber <base>/web fehlt
```

`DirectorySpec` ist ein kleines unveränderliches Wertobjekt mit `path`, `owner`, `group`, `mode` und einer deutschen `description`. `directories()` liefert die fünf Ordner in fester Reihenfolge; der Docroot-Unterordner (`web/<subdir>`) wird zusätzlich angehängt, wenn `subdir` gesetzt ist.

`Vhost::baseDir()` und `Vhost::docroot()` entfallen. Alle Aufrufer (Renderer, Service, CLI, Oberfläche) beziehen ihre Pfade künftig aus `VhostLayout`. Die Entität behält `slug()`, `isLocal()` und ihre Daten.

## 5. Wertobjekt `VhostAdmin\Value\NginxSnippet`

Prüft den Text des Konfigurations-Snippets, bevor nginx ihn sieht.

**Erlaubte Direktiven (Positivliste):** `client_max_body_size`, `expires`, `add_header`, `more_set_headers`, `charset`, `autoindex`, `index`, `error_page`, `rewrite`, `return`, `try_files`, `limit_rate`, `limit_rate_after`, alle mit `gzip` beginnenden, alle mit `proxy_` beginnenden sowie `location` als einzige blockeröffnende Direktive.

**Abgelehnt** ist damit implizit alles andere, insbesondere `root`, `alias`, `listen`, `server_name`, `ssl_*`, `auth_*`, `satisfy`, `allow`, `deny`, `include`, `access_log`, `error_log`, `load_module`, `user`, `if`, `server`, `upstream`.

**Strukturprüfungen:**
- Klammerbilanz: darf nie negativ werden und muss am Ende null sein. Ohne diese Prüfung könnte ein `}` den umgebenden `server`-Block schließen und danach ein eigener `server`-Block mit beliebigen Direktiven folgen – der eigentliche Ausbruchsweg.
- Nur `location` öffnet Blöcke; Verschachtelung höchstens zwei Ebenen.
- Höchstens 64 KB.
- Kommentare (`#`) und Leerzeilen werden übersprungen.

**Fehlermeldungen** nennen Zeilennummer und beanstandete Direktive auf Deutsch, z. B. `Zeile 4: Direktive "root" ist nicht erlaubt`. Diese Meldung erscheint unverändert in der Oberfläche.

Die Prüfung ist bewusst eine Positivliste: Unbekannte Direktiven gelten als verboten, nicht als erlaubt.

## 6. Ablauf beim Speichern eines Snippets

1. Detailseite zeigt ein Textfeld mit dem Inhalt von `conf/custom.conf` (die Oberfläche liest die Datei).
2. Formular sendet den Text an `AdminPage`; diese ruft `vhost conf <name>` auf und übergibt den Text über **stdin** (wie das Passwort, nie als Argument).
3. Das CLI prüft mit `NginxSnippet`. Verstoß → Abbruch, es wird nichts geschrieben, die Meldung geht zurück (Exit 1).
4. Bestanden: bisherigen Dateiinhalt merken, neue Datei schreiben, `render()` aufrufen (dort läuft `nginx -t`).
5. Schlägt `nginx -t` fehl, wird die Snippet-Datei auf den vorherigen Stand zurückgesetzt und die nginx-Ausgabe durchgereicht. Die vorhandene Rücknahme in `render()` (Stand 2026-09-17) wird dafür um die Snippet-Datei erweitert.
6. Leerer Text entfernt die Datei.

## 7. Auswirkung auf den Renderer

`Nginx\ConfigRenderer` bezieht seine Pfade aus `VhostLayout`:

- `root` → `docroot()` (also `web/` bzw. `web/<subdir>`)
- `access_log` → `<base>/logs/access.log`, `error_log` → `<base>/logs/error.log` (statt `/var/log/nginx/<slug>.*.log`)
- neu: `include <base>/conf/*.conf;` im `server`-Block. Ein Glob ohne Treffer ist in nginx gültig, es braucht also keine Fallunterscheidung.
- Die ACME-Location behält `webDir()` als `root`, damit certbot weiterhin nach `web/.well-known/acme-challenge/` schreibt.

Bei aktivem HTTPS legt der Service Symlinks in `cert/` an: `fullchain.pem` und `privkey.pem` zeigen auf `/etc/letsencrypt/live/<domain>/`. Die `ssl_certificate`-Direktiven verweisen weiterhin direkt auf `/etc/letsencrypt/live/`, damit eine defekte Symlink-Kette den Start von nginx nicht verhindern kann; `cert/` dient der Sichtbarkeit.

## 8. Migration bestehender Installationen

Läuft in `src/install.sh` nach dem Ausrollen des Codes, einmalig je Host, über einen neuen CLI-Befehl `vhost migrate-layout`:

1. Ermitteln, welche Hosts migriert werden müssen (`VhostLayout::needsMigration()`), zusätzlich die Oberfläche `/var/www/localhost-8080`.
2. Ist die Liste leer, endet der Befehl ohne Änderung (idempotent).
3. Sicherung aller betroffenen Basisordner nach `/var/backups/vhost-admin-migration-<YYYYmmdd-HHMMSS>.tar.gz`, bevor etwas verschoben wird. Schlägt die Sicherung fehl, bricht die Migration ab.
4. Je Host: `web/` anlegen, vorhandenen Inhalt (außer den fünf Strukturordnern) mit `mv` hineinschieben, `conf/`, `cert/`, `private/`, `logs/` anlegen, Rechte aus `directories()` setzen.
5. Vorhandene Logs `/var/log/nginx/<slug>.access.log` und `<slug>.error.log` nach `logs/access.log` bzw. `logs/error.log` verschieben.
6. Die Oberfläche: `index.php` nach `/var/www/localhost-8080/web/`, `nginx-admin.conf` zeigt auf den neuen Pfad.
7. Abschließend `render()` für alle Hosts und ein Reload.

Der Wert `subdir` in der Datenbank bleibt unverändert; aus `/var/www/mfsvr.de/www/src` wird `/var/www/mfsvr.de/web/www/src`.

## 9. Logrotate

Neue Datei `/etc/logrotate.d/vhost-admin`, vom Installer abgelegt:

- Muster `/var/www/*/logs/*.log`
- täglich, 14 Generationen, komprimiert, `missingok`, `notifempty`
- `create 0640 root <owner>`
- `sharedscripts` mit `postrotate`: `nginx -s reopen`

## 10. Neue CLI-Befehle

| Befehl | Zweck |
|---|---|
| `vhost conf <name>` | Snippet aus stdin prüfen und setzen; leerer Text entfernt es |
| `vhost fix-permissions [name]` | Soll-Rechte aus `VhostLayout` neu setzen (alle Hosts oder einen) |
| `vhost migrate-layout` | Bestehende Hosts auf die neue Struktur bringen (idempotent) |

Die Usage-Ausgabe und `README.md` werden entsprechend ergänzt.

## 11. Tests

Zusätzlich zu den 147 bestehenden Tests:

- `VhostLayoutTest`: alle Pfadmethoden für Domain, Domain mit Unterordner und localhost-Host; `directories()` liefert die richtigen Rechte; `needsMigration()`.
- `Value\NginxSnippetTest`: jede erlaubte Direktive wird angenommen; jede der genannten verbotenen wird abgelehnt; **Ausbruchsversuch** `}` + eigener `server`-Block wird abgelehnt; unbalancierte Klammern; zu tiefe Verschachtelung; Überschreitung der Größe; Kommentare und Leerzeilen stören nicht; die Fehlermeldung nennt die richtige Zeilennummer.
- `ConfigRendererTest`: neue `root`-, `access_log`-, `error_log`-Zeilen und die `include`-Zeile, für alle bestehenden Fälle (Domain mit/ohne SSL, localhost, mit/ohne IPv6).
- `VhostServiceTest`: `create()` legt alle fünf Ordner mit den Soll-Rechten an; Snippet setzen, ersetzen, leeren; Rücknahme bei fehlgeschlagenem Reload stellt auch die Snippet-Datei wieder her; `cert/`-Symlinks bei `enableSsl()`.
- `MigrationTest`: alte Struktur in Temp-Verzeichnissen → nach der Migration liegen die Dateien in `web/`, die vier anderen Ordner existieren, Rechte stimmen, erneuter Lauf ändert nichts.
- `Cli\ApplicationTest`: `conf` liest stdin und meldet Fehler mit Zeilennummer; `fix-permissions`; `migrate-layout`.
- `Web\AdminPageTest`: die Aktion `conf` erzeugt die richtigen CLI-Argumente und reicht den Text über stdin.
- `debugging/smoke-test.sh`: ein gültiges Snippet über die Oberfläche setzen und seine Wirkung per HTTP prüfen (z. B. `add_header`), danach ein ungültiges senden und prüfen, dass es abgelehnt wird und das gültige weiter aktiv ist.

## 12. Nicht Teil dieser Änderung

Keine Log-Anzeige in der Oberfläche, kein Syntax-Highlighting im Textfeld, keine Versionierung der Snippets, keine eigenen Zertifikate in `cert/`, keine Änderung an Verzeichnisschutz, Let's Encrypt oder der Rechteverteilung der Datenbank.
