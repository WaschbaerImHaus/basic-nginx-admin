# Honigtopf: Vorschläge annehmen – Entwurf

Stand: 2026-10-15 · Autor: Kurt Ingwer · Status: im Gespräch freigegeben („passt so“), schriftliche Durchsicht ausstehend

## 1. Ziel

Die Kachel „Was sich als Nächstes lohnt“ auf der Honigtopf-Ansicht (bienchen.mfsvr.de) zeigt
Vorschläge, die `Honeypot\Suggestions` aus den Zahlen ableitet. Bisher setzte sie nur eine
offene Claude-Sitzung um; seit deren Ende (2026-09-27) ist nichts dazugekommen.

Neu: Neben jedem umsetzbaren Vorschlag steht ein Knopf **„annehmen“**. Angenommene Vorschläge
setzt ein Lauf ab dem **folgenden Tag** um – bis live (Build, Installation, Push). Unterbricht das
Nutzungslimit die Arbeit, geht sie nach dessen Rücksetzung in derselben Sitzung weiter.

## 2. Entscheidungen des Nutzers (2026-10-15)

| Frage | Entscheidung |
|---|---|
| Wie weit geht der Lauf? | **Bis live**: umsetzen, Tests, Build, Installation, Smoke-Test, Push |
| Wann? | Der Rechner ist nachts bis 8 Uhr aus → Beginn frühestens **09:17**, `Persistent=true` (holt nach) |
| Limits | „nur ein 20-Euro-Account“: Umsetzungen können durch das Nutzungslimit unterbrochen werden und müssen ~5 h später **fortgesetzt** werden (6.) |
| Entwurf | „passt so“ (Ansicht, Speicher, Lauf, Sicherheit wie in 3.–6.) |

## 3. Vorschläge mit Identität

`Suggestions::forReport()` liefert heute `list<string>`. Neu liefert es `list<Suggestion>`:

| Feld | Inhalt |
|---|---|
| `kind` | Art, heute nur `decoy` („Weitere Köder“) oder `none` („Keine …“) |
| `params` | geprüfte Parameter, bei `decoy`: bis zu 3 Pfade, aufsteigend sortiert |
| `text` | der bisherige Markdown-Text (nur zur Anzeige) |
| `key` | `kind` + `:` + sha1 der sortierten Parameter – bleibt über Zeiträume gleich, auch wenn sich die Zahlen ändern |

Ein Knopf erscheint nur bei Arten, für die es eine Umsetzungsvorlage gibt (`Suggestion::isActionable()`),
also nicht bei `none`.

**Pfadprüfung (`Suggestion::cleanPath`)**: Ein Pfad aus dem Log kommt nur in `params`, wenn er auf
`^/[A-Za-z0-9._~/-]{1,120}$` passt. Andere Pfade bleiben im Text sichtbar, sind aber nicht Teil des
umsetzbaren Vorschlags. Bleibt kein Pfad übrig, ist der Vorschlag nicht umsetzbar.

## 4. Ansicht

- Je Vorschlag nach Status:
  - noch nicht angenommen: Knopf **annehmen**
  - `angenommen`: „angenommen – wird am TT.MM. umgesetzt“ + Knopf **zurücknehmen**
  - `in Arbeit`: „wird gerade umgesetzt“
  - `pausiert`: „pausiert (Nutzungslimit), geht um HH:MM weiter“
  - `umgesetzt`: „umgesetzt in Build N“
  - `gescheitert`: „gescheitert: <Grund>“ + Knopf **erneut annehmen**
  - `verschoben`: „verschoben auf TT.MM.: <Grund>“
- Knöpfe sind Formulare mit `method="post"`. Die Ansicht beantwortet POST nur, wenn
  - `Sec-Fetch-Site` fehlt oder `same-origin` ist und `Origin` (falls gesendet) dem eigenen Host entspricht, **und**
  - das Feld `token` = `hash_hmac('sha256', key, geheimnis)` ist (`hash_equals`).
  Danach Weiterleitung (303) auf dieselbe Ansicht (Post/Redirect/Get).
- Die Ansicht liegt hinter dem Verzeichnisschutz des Nutzers; der Knopf ändert daran nichts.
- Angenommen wird der **Schlüssel** samt `kind`/`params`, die die Ansicht selbst aus dem Bericht
  berechnet – nie Werte aus dem Formular außer `key`, `aktion`, `token`. Ein unbekannter Schlüssel
  (nicht unter den aktuell angezeigten Vorschlägen) wird abgelehnt.

## 5. Speicher

- Eigener Ordner `private/honeypot-accepted/` (`<owner>:<poolgruppe>`, `2770`), darin
  `accepted.sqlite` (`660`) und `secret` (32 Zufallsbytes, `640`, legt der Installer an).
  Nicht unter `private/honeypot/`: `install-dashboard.sh` setzt dort alles auf `640`.
- Die Auswertungs-Datenbank `private/honeypot/honeypot.sqlite` bleibt **nur lesbar** für die Ansicht.
- Die Ansicht setzt vor dem Schreiben `umask(0007)`, damit Journaldateien für den Lauf (Benutzer
  `user`) lesbar und schreibbar bleiben. Journalmodus `DELETE`, nicht WAL.
- Tabelle `accepted`:

| Spalte | Inhalt |
|---|---|
| `key` | Primärschlüssel |
| `kind`, `params` | Art und Parameter (JSON) |
| `text` | Anzeigetext zum Zeitpunkt der Annahme (nur Anzeige, nie Arbeitsanweisung) |
| `accepted_at` | Zeitpunkt der Annahme |
| `due` | Tag der Umsetzung (Annahmetag + 1, Europe/Berlin) |
| `status` | `angenommen`, `in Arbeit`, `pausiert`, `umgesetzt`, `gescheitert`, `verschoben`, `zurückgenommen` |
| `session_id` | Claude-Sitzung zum Fortsetzen |
| `base` | Commit, auf dem die Umsetzung begann |
| `resume_at` | frühester Zeitpunkt der Fortsetzung |
| `resumes` | Zahl der Fortsetzungen |
| `started_at` | Beginn der Umsetzung |
| `result` | Grund bzw. Build-Nummer |
| `updated_at` | letzte Änderung |

- Klasse `Honeypot\AcceptedStore` (lesen, annehmen, zurücknehmen, fällige holen, Status setzen).

## 6. Lauf (stündlich, mit Pausen für das Nutzungslimit)

**Randbedingung des Nutzers (2026-10-15):** „das ist nur ein 20-Euro-Account – Umsetzungen können
durch Limits unterbrochen werden und müssen dann 5 h später fortgesetzt werden.“ Daraus folgt:
angefangene Arbeit bleibt erhalten, wird in **derselben Claude-Sitzung** fortgesetzt, und es läuft
immer nur **eine** Umsetzung.

**Zeitplan:** `vhost-admin-accepted.timer` stündlich um `:17`, `Persistent=true`; Dienst als Benutzer
`user` (Besitzer des Repos und der Claude-Anmeldung), Sperre per `flock` (nie zwei Läufe zugleich).
Umgebung aus `/etc/default/vhost-admin-accepted`: `REPO=/home/user/vhost-admin`,
`WORKTREE=/home/user/vhost-admin-auto`, `CLAUDE=/home/user/.local/bin/claude`, Ansichtshost,
`START_HOUR=9` (neue Umsetzungen beginnen frühestens um diese Stunde). Skript `honeypot/apply-accepted.php`.

**Je Lauf genau eine Aktion:**

1. Gibt es einen Eintrag `pausiert` mit `resume_at <= jetzt` → **fortsetzen** (Schritt 5 mit `--resume`).
2. Sonst, wenn kein Eintrag `in Arbeit`/`pausiert` existiert und es nach `START_HOUR` ist: den ältesten
   fälligen Eintrag (`angenommen` oder `verschoben`, `due <= heute`) **beginnen**.
3. Sonst nichts tun.

**Beginnen:**

4. `git fetch`; eigene Arbeitskopie per `git worktree add $WORKTREE -b auto/<key8> origin/main`.
   Die Arbeitskopie des Nutzers (`$REPO`) wird dabei nicht angefasst – eine laufende Sitzung des
   Nutzers stört die Umsetzung nicht und umgekehrt. `base` = `origin/main` merken.
5. Claude aufrufen, im Worktree:
   `timeout 7200 claude -p <auftrag> --output-format json --model opus --allowedTools <liste>`;
   beim Fortsetzen `--resume <session_id>` mit dem festen Auftrag „Setze die unterbrochene Aufgabe
   fort; prüfe zuerst `git status` und den Stand der Tests.“ `session_id` aus der JSON-Antwort speichern.
   - Auftrag aus fester Vorlage je Art (`honeypot/templates/decoy.md`), Pfade nur im markierten Datenblock.
   - Erlaubt: Lesen/Suchen, Schreiben/Bearbeiten, `phpunit`, `php`, `git status/diff/log/add/commit`.
     **Kein** `sudo`, **kein** `git push`, **kein** `build.sh`, kein Netz.
   - Die Vorlage verlangt: TDD, Regeln aus `.claude/CLAUDE.md` (Köder: nur erfundene Werte, nie
     Besuchereingaben mitschreiben, nichts nachladen), fertige Arbeit **committen**, `src/build.txt`
     nicht ändern; keine Superpowers-Skills, keine Rückfragen.
6. **Limit erkannt** (JSON `is_error` bzw. Abbruch mit einer Meldung, die ein Nutzungslimit nennt):
   Rücksetzzeit aus der Meldung lesen (Unix-Zeit nach `|` oder Uhrzeit „resets …“), sonst jetzt + 5 h;
   dazu 10 min Puffer → Status `pausiert`, `resume_at` setzen, Worktree bleibt stehen. Die Erkennung
   steht in einer eigenen Klasse (`Honeypot\ClaudeOutcome`) mit Tests aus echten Meldungstexten.
7. Anderer Abbruch (Zeitlimit, Fehler ohne Limit) → wie Fehler (Schritt 10).

**Abschließen** (Claude meldet Erfolg, kein Limit):

8. Prüfen ohne Vertrauen in die Antwort: mindestens ein neuer Commit seit `base`, Arbeitsbaum sauber,
   `src/build.txt` unverändert. Hat sich `origin/main` inzwischen bewegt: `git rebase origin/main`
   (Konflikt → Fehler).
9. Im Worktree: `./build.sh --no-push` (Tests, Buildnummer, Paket, Commit „Build N“), dann
   `sudo ./install.sh` und `sudo ./debugging/smoke-test.sh` **aus dem Worktree**. Erfolg →
   `git push origin HEAD:main`, Status `umgesetzt`, `result = N`; Arbeitskopie des Nutzers per
   `git pull --ff-only` nachziehen, wenn sie sauber auf `main` steht (sonst stehen lassen);
   Worktree und Branch entfernen.

**Fehler:**

10. Nichts pushen. Wurde schon installiert: den Stand von `origin/main` wieder installieren (frischer
    Worktree). Worktree auf `origin/main` zurücksetzen, Eintrag in `BUGS.md` „Offen“ (Bug-Nummer,
    „Honigtopf-Vorschlag <key>“, Grund, Fundstellen), `./build.sh --no-push`, `git push origin HEAD:main`;
    Worktree und Branch entfernen. Status `gescheitert`, `result` = Grund (höchstens 300 Zeichen).

**Grenzen:** je Claude-Aufruf 2 h; höchstens 6 Fortsetzungen; ist eine Umsetzung nach 3 Tagen nicht
fertig → Fehler „nicht fertig geworden“. Protokoll je Lauf nach `/var/log/vhost-admin/accepted.log`
(rotiert, 14 Tage).

**Hinweis Kontingent:** Lauf und Sitzungen des Nutzers teilen sich dasselbe Nutzungslimit. Eine
Umsetzung ab 09:17 verbraucht daher Kontingent aus dem Fenster, in dem der Nutzer selbst arbeitet;
`START_HOUR` verschiebt den Beginn.

## 7. Sicherheit

- Pfade stammen von Scannern (Fremde). Sie erreichen den Auftrag nur geprüft (3.) und als Daten (6.5).
  Der Anzeigetext erreicht den Lauf nie.
- Claude im Lauf hat weder `sudo` noch Push noch `build.sh`; das macht das Skript nach eigener Prüfung.
- POST nur gleiche Herkunft + HMAC-Token; nur angezeigte Schlüssel; Verzeichnisschutz davor.
- Die Ansicht bekommt Schreibrecht nur auf `private/honeypot-accepted/`.
- Immer nur eine Umsetzung zugleich; Grenzen siehe 6.

## 8. Tests

- `Suggestion`: Schlüssel stabil bei anderen Zahlen; Pfadprüfung (Leerzeichen, Anführungszeichen,
  Zeilenumbruch, Länge); `none` nicht umsetzbar.
- `AcceptedStore`: annehmen → `due` = morgen; zurücknehmen; erneut annehmen nach `gescheitert`;
  Auswahl „fortsetzen vor beginnen“, nie zwei zugleich, `START_HOUR`.
- `ClaudeOutcome`: Erfolg, Limit mit Unix-Zeit, Limit mit Uhrzeit, Limit ohne Zeit (+5 h), anderer Fehler,
  kaputtes JSON.
- Ansicht: Knopf nur bei umsetzbaren Vorschlägen; POST ohne/mit falschem Token, fremde Herkunft,
  unbekannter Schlüssel → abgelehnt; Text mit `<script>` maskiert; Anzeige von `pausiert` mit Uhrzeit.
- `apply-accepted.php` mit Attrappen für Claude, git und Installer: Limit → `pausiert`, nächster Lauf
  vor `resume_at` tut nichts, danach `--resume` mit derselben Sitzung; Erfolg → Build, Install, Push,
  `umgesetzt`; Testfehler → kein Push, BUGS.md, `gescheitert`; Auftrag enthält nur geprüfte Pfade
  im Datenblock; Arbeitskopie des Nutzers bleibt unberührt.
- Smoke-Test: POST auf die Ansicht ohne Token → 403.

## 9. Nicht Teil dieses Entwurfs

Weitere Vorschlagsarten (je neue Vorlage nötig), Benachrichtigung per Mail, Ablehnen-Knopf.
