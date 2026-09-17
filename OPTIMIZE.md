# Optimierungsvorschläge

- `VhostService::render` schreibt drei Dateien und lädt nginx neu; bei vielen Änderungen hintereinander (Skripte) könnte ein „Batch-Modus“ Reloads sparen (`renderAll` macht das bereits für die Installation).
- `SystemdReloader` pollt bis 5 s mit 50-ms-Schritten; ein `inotify` auf `/run/nginx.pid` wäre eleganter, lohnt aber erst bei häufigen Aufrufen.
- `index.php` mischt Template und Hilfsfunktionen; ein kleines Template-Objekt würde die Datei halbieren.
- Die Oberfläche liest die Datenbank direkt und schreibt über das CLI – bei Wachstum wäre eine JSON-Schnittstelle des CLI (`--json`) sauberer als Text-Flashes.
- `Config::fromArray` mit `new self(...array)` ist elegant, aber die Reihenfolge der Konstruktorparameter ist implizit an die Schlüsselnamen gebunden; bei Erweiterung Named Arguments beibehalten.

## Kleinbefunde aus den Reviews

- `VhostService::own()` versucht `chown` auf den konfigurierten Besitzer und fängt einen Fehlschlag mit `@`-Unterdrückung still ab (Fallback auf die Gruppe) – ein falsch konfigurierter `wwwOwner` fällt dadurch nicht auf, sondern wird nur stillschweigend anders behandelt.
- `Database::pdo()` legt das Datenbankverzeichnis mit `mkdir($dir, 0770, true)` an, prüft den Rückgabewert aber nicht; schlägt `mkdir` fehl (z. B. Rechte), scheitert erst die nachfolgende `PDO`-Verbindung mit einer weniger aussagekräftigen Fehlermeldung.
- `VhostRepository::insert()` prüft Namenskollisionen per vorherigem `SELECT` (`byName()`) statt den `UNIQUE`-Constraint-Fehler der Datenbank abzufangen – ein theoretisches Race zwischen Prüfung und `INSERT` bleibt offen (in der Praxis unkritisch, da nur das root-CLI schreibt und nicht parallel läuft).
- `Cli/Application.php` importiert `use VhostAdmin\Vhost;`, verwendet die Klasse im Datei-Inhalt aber nicht – toter Import.
- `Web\CommandRunner::run()` schreibt zuerst das komplette `stdin` (`fwrite($pipes[0], $stdin)`) und liest erst danach `stdout`/`stderr` – bei sehr großer CLI-Ausgabe könnte der Kindprozess blockieren, weil sein Ausgabepuffer voll läuft, während der Elternprozess noch mit dem Schreiben von `stdin` beschäftigt ist (theoretischer Deadlock; bei den kurzen `vhost`-Ausgaben in der Praxis nicht relevant).
