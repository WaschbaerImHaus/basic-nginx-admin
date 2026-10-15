# Honigtopf aus vhost-admin auslagern – Entwurf

Stand: 2026-10-15 · Autor: Kurt Ingwer · Status: Richtung vom Nutzer vorgegeben, schriftliche Durchsicht ausstehend

## 1. Ziel

Nutzervorgabe (2026-10-15): „beides voneinander trennen. Der vhost admin soll nur verwalten.
honeypot soll die bienchenseite machen und die daten vom webserver analysieren.“
Repository: `git@bitbucket.org:von-null/honeypot.git`.

| Projekt | Aufgabe |
|---|---|
| **vhost-admin** (GitHub, wie bisher) | nur Verwaltung: vHosts, Zertifikate, PHP-Pools, Verzeichnisschutz, CLI `vhost`, Oberfläche |
| **honeypot** (neu, Bitbucket) | Honigtopf-Seite (Blumenwiese, Köder), Auswertung der Webserver-Logs, Ansicht bienchen.mfsvr.de, Netztabelle |

Der Code ist schon heute getrennt (`VhostAdmin\` und `Honeypot\` benutzen einander nicht; gemeinsam
ist nur der Autoloader). Gekoppelt sind Installer, Timer, Smoke-Test und Doku.

## 2. Neues Projekt `~/honeypot`

**Historie:** `git filter-repo` auf eine Kopie von vhost-admin, nur die Honigtopf-Pfade, mit
Umbenennung in die neue Struktur. Die Historie der Honigtopf-Dateien bleibt so erhalten.

**Struktur** (nach globaler CLAUDE.md – aller Quellcode unter `src/`):

| bisher (vhost-admin) | neu (honeypot) |
|---|---|
| `src/lib/Honeypot/` | `src/lib/Honeypot/` |
| `src/public/honeypot/` | `src/public/dashboard/` |
| `honeypot/analyse.php`, `update-networks.php`, `make-decoys.php`, `make-favicon.php` | `src/bin/` |
| `honeypot/install-dashboard.sh`, `install-site.sh` | `src/install-dashboard.sh`, `src/install-site.sh` |
| `honeypot/site/` | `src/site/` |
| `src/etc/vhost-admin-honeypot.*`, `vhost-admin-networks.*` | `src/etc/honeypot-analyse.*`, `honeypot-networks.*` |
| `tests/Honeypot/` (+ benötigte `tests/Support/`) | `tests/` |
| `debugging/meadow-check.sh`, Honigtopf-Teil von `smoke-test.sh` | `debugging/` |
| `docs/specs/2026-09-22-honeypot-dashboard.md`, Spec „Vorschläge annehmen“ | `docs/` |
| `research/honeypot/` | `research/` |

Neu: `src/bootstrap.php` (nur `Honeypot\`), `src/install.sh`, `build.sh`, `phpunit.xml`, `src/build.txt` (= 1),
Pflichtdokumente (README, BUGS, FEATURES, OPTIMIZE, MEMORY, SECURITY_*, `.claude/CLAUDE.md`, dev-log),
`claude.sh`. Honigtopf-Abschnitte aus README/FEATURES/BUGS/CLAUDE.md von vhost-admin wandern mit.

## 3. Betrieb nach der Trennung

| | bisher | neu |
|---|---|---|
| Installation | `/opt/vhost-admin/honeypot`, `/opt/vhost-admin/public/honeypot` | `/opt/honeypot` |
| Timer | `vhost-admin-honeypot.timer`, `vhost-admin-networks.timer` | `honeypot-analyse.timer`, `honeypot-networks.timer` (gleiche Zeitpläne) |
| Einstellungen | `/etc/default/vhost-admin-honeypot` | `/etc/default/honeypot` (wird beim ersten Install übernommen) |
| Netztabelle | `/var/lib/vhost-admin/networks.txt` | `/var/lib/honeypot/networks.txt` (wird übernommen) |
| Tagesnotizen | `/opt/vhost-admin/research/honeypot` | `/var/lib/honeypot/research` (wird übernommen) |
| Köder-Konfiguration | `/etc/nginx/conf.d/vhost-admin-decoy.conf` | `/etc/nginx/conf.d/honeypot-decoy.conf` (alte Datei wird ersetzt) |
| Daten | `/var/www/<ansicht>/private/honeypot*` | **unverändert** |

- Der Honigtopf benutzt vhost-admin nur noch über das CLI `vhost` (eigene Direktiven setzen,
  Rechte nachziehen, Schutzbereich `/admin`). Das ist seine einzige Abhängigkeit und steht in der README.
- `honeypot/src/install.sh` erledigt die Übernahme (Abschnitt 3) idempotent: neue Units an,
  alte `vhost-admin-honeypot.*`/`vhost-admin-networks.*` aus, Einstellungen/Tabelle/Notizen kopieren,
  danach `install-dashboard.sh` und `install-site.sh` wie bisher.
- **Reihenfolge auf dem Rechner:** erst honeypot installieren (neue Timer laufen), dann das
  bereinigte vhost-admin. Dessen Installer entfernt verbliebene Honigtopf-Reste unter `/opt/vhost-admin`
  und die alten Unit-Dateien, falls noch vorhanden – Daten unter `/var/www` fasst er nicht an.

## 4. vhost-admin danach

- Entfernt: `src/lib/Honeypot/`, `src/public/honeypot/`, `honeypot/`, `tests/Honeypot/`, die Honigtopf-
  Units, der Honigtopf-Teil von Installer und Smoke-Test, `debugging/meadow-check.sh`, der
  Honeypot-Autoloader, Honigtopf-Abschnitte der Doku (ersetzt durch einen Verweis auf das neue Repo).
- Bleibt: alles, was `Honeypot\Decoys` braucht, um eigene Direktiven zu setzen – also das CLI `vhost conf`
  mit seiner Sperrliste. Keine Sonderregeln für den Honigtopf in vhost-admin.
- `.claude/CLAUDE.md` von vhost-admin: Regel „Verwaltung: keine neuen Features“ bleibt; die Honigtopf-
  Regeln wandern ins neue Projekt.

## 5. Folgen für „Vorschläge annehmen“ und Langzeit-Vorschläge

Die Spec `2026-10-15-honigtopf-annehmen-design.md` gilt unverändert, aber für das Projekt **honeypot**:
`REPO=/home/user/honeypot`, `WORKTREE=/home/user/honeypot-auto`, Push nach Bitbucket, Freigabeliste der
Pfade auf die neue Struktur umgestellt (`src/lib/Honeypot/`, `src/public/dashboard/`, `src/bin/` ohne
`*.sh`, `src/site/`, `tests/`, `docs/`, `dev-log/`, `research/` und die Doku-Dateien). Ihr Plan wird
nach der Trennung im neuen Repo geschrieben.

## 6. Abnahme

- honeypot: `phpunit` grün, `build.sh` → Build 1 nach Bitbucket; auf dem Rechner zeigt bienchen.mfsvr.de
  dieselben Daten wie vorher, `honeypot-analyse.timer` läuft und schreibt neue Tage, Köder werden
  weiter ausgeliefert (Abruf von `/.env` mit `curl --resolve` liefert den Köder), Smoke-Test des Honigtopfs grün.
- vhost-admin: `phpunit` grün (ohne Honigtopf-Tests), Smoke-Test grün, Build 30 nach GitHub;
  `systemctl list-timers` zeigt keine `vhost-admin-honeypot`/`-networks` mehr.
- Kein Datenverlust: Zahl der Tage in `honeypot.sqlite` vorher = nachher (oder mehr).
