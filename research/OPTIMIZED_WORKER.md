# OPTIMIZED_WORKER – was ich über vhost-admin weiß und wie ich hier arbeite

Stand: 2026-09-17

## Das Projekt in drei Sätzen
Ein LXC-Verwaltungswerkzeug: nginx-vHosts als Datensätze in SQLite, aus denen `Nginx\ConfigRenderer` deterministisch Konfigurationsdateien erzeugt. Alles Schreibende läuft über das root-CLI `vhost`; die PHP-Oberfläche (www-data) liest nur und ruft das CLI per sudoers. Sicherheit kommt aus strenger Validierung der Wertobjekte, nicht aus Vertrauen in die Oberfläche.

## Arbeitsweise, die sich bewährt hat
- Erst Tests (PHPUnit, ohne root, Temp-Verzeichnisse, Fakes für Reload/certbot), dann Code.
- nginx-Ausgaben zeichengenau testen – Abweichungen in Whitespace sind sonst unsichtbar.
- Nach jeder Installation `sudo ./debugging/smoke-test.sh` laufen lassen: der ursprüngliche Ende-zu-Ende-Test dieser Art hat den asynchronen-Reload-Bug gefunden (siehe BUGS.md); beim Schreiben der jetzigen Skriptfassung hat der Testlauf selbst zusätzlich zwei Fehler im Skript zutage gefördert (curl-Exit 52 bei HTTP 444 unter `set -e`, SIGPIPE bei `curl | grep -q` unter `pipefail`).
- Nie das CLI weichspülen, damit die Oberfläche „einfacher“ wird: die Oberfläche ist unprivilegiert, das CLI ist die Sicherheitsgrenze.

## Fallstricke
- `nginx -s reload` ist asynchron → `SystemdReloader` wartet auf alte Worker.
- `jq` ist auf diesem LXC nicht installiert; für künftige JSON-Ausgaben (z. B. ein `--json` am CLI, siehe OPTIMIZE.md) eher PHP selbst (`json_encode`) oder Python einplanen statt auf `jq` zu setzen.
- PHP-FPM-Socket heißt `/run/php/php-fpm.sock` (versionsunabhängiger Link).
- `crypt()` mit `$6$` funktioniert mit libxcrypt; bcrypt wäre in nginx nicht garantiert.
- Bash-Skripte hier laufen mit `set -euo pipefail`: `curl` gegen einen absichtlich schließenden Host (nginx 444) liefert Exit-Status 52 trotz korrekt geschriebenem `%{http_code}`, und `curl | grep -q` kann durch die früh schließende Pipe SIGPIPE auslösen – beides muss gezielt abgefangen werden (siehe `debugging/smoke-test.sh`), sonst bricht das Skript an Stellen ab, die eigentlich korrekt sind.

## Offene Fragen an den Nutzer (bei Gelegenheit)
- Sollen Domains einen `www.`-Alias bekommen?
- Wird PHP in normalen vHosts gebraucht?

## Was ich am 2026-09-19/20 gelernt habe
- `conf/` braucht Leserecht für `www-data` (Gruppe `www-data`, Modus `0750`), sonst kann die Oberfläche das gespeicherte Snippet nicht ins Textfeld laden – sie schreibt dort trotzdem nie selbst, nur das CLI tut das.
- Die Klammerbilanz ist die eigentliche Sicherheitsprüfung eines nginx-Snippets, nicht die Positivliste allein: eine rein zeilenweise Prüfung (nur das erste Wort jeder Zeile gegen die Liste) ist umgehbar, z. B. mit `expires 1d; root /etc;` in einer Zeile – erst ein echter Tokenizer über `;`/`{`/`}` (mit Anführungszeichen/Kommentaren) verhindert, dass eine schließende Klammer den umgebenden `server`-Block verlässt und danach beliebige Direktiven folgen.
- `realpath()` in Löschpfaden kann Symlinks selbst zum Angriffsweg machen: löst man erst auf und vergleicht dann, folgt man einem untergeschobenen Symlink auf ein fremdes Ziel, statt den Symlink selbst als verboten zu erkennen – die Prüfung muss vor der Auflösung ansetzen (`is_link()` zuerst).
- Ein Reload, der auf das Verschwinden der alten Worker wartet, blockiert sich selbst, wenn er aus genau der HTTP-Anfrage heraus läuft, die noch vom alten Worker bedient wird: der Worker kann sich erst beenden, wenn die Antwort auf diese Anfrage geschrieben ist, das Warten läuft also planmäßig bis zum Timeout, ohne etwas zu gewinnen.
- Ein Abnahmetest darf nur prüfen, was tatsächlich zugesichert ist – nicht, was intuitiv wünschenswert wäre. „Snippet wirkt sofort per HTTP nach dem POST" war nie zugesichert (nginx übernimmt asynchron); der Smoke-Test wurde deshalb auf die deterministisch prüfbare Kette (Datei geschrieben, Flash ok, verbotene Direktive abgelehnt, fünf Ordner mit Soll-Rechten vorhanden) zurückgeschnitten, statt eine nicht zugesicherte Eigenschaft immer aufwendiger nachzujagen.
