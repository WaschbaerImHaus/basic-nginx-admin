# Bugs

## Offen

(keine)

## Behoben

| Datum | Bug | Ursache | Lösung |
|---|---|---|---|
| 2026-09-17 | Änderungen (IP-Freigabe, Schutz aus, neuer localhost-Port) wurden direkt nach dem CLI-Aufruf noch nicht wirksam | `systemctl reload nginx` kehrt zurück, bevor der Master die alten Worker ersetzt hat | `Nginx\SystemdReloader` wartet nach dem Reload, bis die alten Worker-PIDs verschwunden sind (max. 5 s) |
| 2026-09-17 | `debugging/smoke-test.sh` brach bei der 444-Prüfung (unbekannter Host) unter `set -e` vorzeitig ab, obwohl der erwartete Status erreicht wurde | `curl` beendet sich bei vom Server geschlossener Verbindung (nginx-Code 444) mit Exit-Status 52; unter `set -e` bricht das die Zeile trotz korrekt geschriebenem `%{http_code}` ab | `expect()` fängt den Exit-Status von `curl` mit `\|\| true` ab und prüft danach nur den geschriebenen HTTP-Code |
| 2026-09-17 | `debugging/smoke-test.sh` meldete bei größeren Antworten gelegentlich einen Fehler, obwohl die gesuchte Zeichenkette vorhanden war | `curl \| grep -q` unter `set -o pipefail`: `grep -q` beendet sich nach dem ersten Treffer und schließt die Pipe, `curl` kann dadurch mit SIGPIPE abbrechen, was `pipefail` als Fehler des gesamten Kommandos wertet | Umgestellt auf `grep -q ... <<<"$(curl ...)"` (Kommandosubstitution statt Pipe an `grep`) |
| 2026-09-17 | `debugging/smoke-test.sh` ließ bei einem Fehlschlag Test-vHosts, Docroots und das Cookie-Tempfile zurück, was den nächsten Lauf blockierte | Aufräumen stand nur am Skriptende, `set -e` beendet das Skript aber schon bei der ersten fehlgeschlagenen Prüfung vorher | `cleanup()` läuft jetzt über `trap cleanup EXIT` bei jedem Skriptende (Erfolg, Fehler, Signal) und zusätzlich einmal vor den eigentlichen Prüfungen, um Reste eines abgebrochenen Vorlaufs zu entfernen |
