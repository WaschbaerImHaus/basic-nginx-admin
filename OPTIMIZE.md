# Optimierungsvorschläge

- `VhostService::render` schreibt drei Dateien und lädt nginx neu; bei vielen Änderungen hintereinander (Skripte) könnte ein „Batch-Modus“ Reloads sparen (`renderAll` macht das bereits für die Installation).
- `SystemdReloader` pollt bis 5 s mit 50-ms-Schritten; ein `inotify` auf `/run/nginx.pid` wäre eleganter, lohnt aber erst bei häufigen Aufrufen.
- `index.php` mischt Template und Hilfsfunktionen; ein kleines Template-Objekt würde die Datei halbieren.
- Die Oberfläche liest die Datenbank direkt und schreibt über das CLI – bei Wachstum wäre eine JSON-Schnittstelle des CLI (`--json`) sauberer als Text-Flashes.
- `Config::fromArray` mit `new self(...array)` ist elegant, aber die Reihenfolge der Konstruktorparameter ist implizit an die Schlüsselnamen gebunden; bei Erweiterung Named Arguments beibehalten.

## Kleinbefunde aus den Reviews

- `Database::pdo()` legt das Datenbankverzeichnis mit `mkdir($dir, 0770, true)` an, prüft den Rückgabewert aber nicht; schlägt `mkdir` fehl (z. B. Rechte), scheitert erst die nachfolgende `PDO`-Verbindung mit einer weniger aussagekräftigen Fehlermeldung.
- `VhostRepository::insert()` prüft Namenskollisionen per vorherigem `SELECT` (`byName()`) statt den `UNIQUE`-Constraint-Fehler der Datenbank abzufangen – ein theoretisches Race zwischen Prüfung und `INSERT` bleibt offen (in der Praxis unkritisch, da nur das root-CLI schreibt und nicht parallel läuft).
- `Cli/Application.php` importiert `use VhostAdmin\Vhost;`, verwendet die Klasse im Datei-Inhalt aber nicht – toter Import.
- `Web\CommandRunner::run()` schreibt zuerst das komplette `stdin` (`fwrite($pipes[0], $stdin)`) und liest erst danach `stdout`/`stderr` – bei sehr großer CLI-Ausgabe könnte der Kindprozess blockieren, weil sein Ausgabepuffer voll läuft, während der Elternprozess noch mit dem Schreiben von `stdin` beschäftigt ist (theoretischer Deadlock; bei den kurzen `vhost`-Ausgaben in der Praxis nicht relevant).
- `VhostService::own()` und `applySpec()` tun fachlich dasselbe für Dateien bzw. Verzeichnisse – zusammenführen.
- Die `SUDO_USER`-Verzweigung in `SystemdReloader::reload()` hat keine Testabdeckung (die Klasse ist laut Projektkonvention nicht unit-testbar); eine kleine Extraktion (`isUiCall()`) würde sie testbar machen.
- `/var/www/localhost-8080/logs/` bleibt leer, weil `src/etc/nginx-admin.conf` weiter nach `/var/log/nginx/vhost-admin.access.log`/`.error.log` schreibt – entweder auf `logs/access.log`/`error.log` umstellen oder als bewusst unbenutzt dokumentieren.
- `AdminPage`-Konstruktor-DocBlock dokumentiert `$layout` nicht (Bestandsstil, aus Task 8 zurückgestellt).
- Leere Anweisungen (`;;;`) akzeptiert `NginxSnippet::fromString()` klaglos; ob `nginx -t` das ebenfalls durchlässt, ist ungeprüft – im Zweifel greift die Rücknahme in `VhostService::setSnippet()`.
- `VhostService` ist mit dem Snippet-Teil (`setSnippet()`/`snippet()`) weiter gewachsen; beim nächsten Refactoring lohnt es, die Dateisystemoperationen (Verzeichnisse anlegen, Besitzer/Rechte setzen, Snippet schreiben) in eine eigene Klasse auszulagern.

## Nach PHP und neuem Wrapper (2026-09-20)

- `ConfigRenderer::serverConfig()` setzt den Text aus vielen Zeichenkettenstücken zusammen; eine kleine Hilfsklasse für „Direktive mit Einrückung“ oder eine Vorlagendatei je Block wäre lesbarer als die aktuelle Verkettung.
- `VhostService` ist mit acht Konstruktorabhängigkeiten und rund 800 Zeilen die größte Klasse. Die PHP-Anteile (`enablePhp`, `disablePhp`, `preparePhpDirectories`, `writePoolFile`) ließen sich als eigener `Php\PhpService` herausziehen.
- `Config` hat inzwischen 18 Eigenschaften; die FPM-bezogenen (`fpmPoolDir`, `fpmSocketDir`, `fpmService`, `fpmBinary`) gehören in ein eigenes Wertobjekt.
- `Config::phpFpmVersion()` durchsucht bei jedem `fromArray()` das Dateisystem; das Ergebnis ließe sich statisch merken.
- Die Pool-Kennwerte (`pm = ondemand`, `pm.max_children = 10`) stehen fest im `PoolRenderer`; sinnvoller wären Standardwerte aus `Config`.
- `NginxSnippet` ist mit Tokenizer und Sperrliste über 500 Zeilen lang. Der Tokenizer und die Regelprüfung sind zwei getrennte Aufgaben und könnten es auch im Code sein.
