<?php
declare(strict_types=1);

/**
 * Lädt die Statistikdateien der fünf regionalen Registries (RIR) und baut daraus die
 * Nachschlagetabelle für Netz und Land.
 *
 * Warum diese Quelle: Sie ist amtlich (die Registries führen sie selbst), frei ohne
 * Konto oder Lizenzschlüssel, und sie liefert Netzblock, Land und Registry aus einer
 * Datei. Standortdatenbanken behaupten mehr (Stadt, Koordinaten), als aus einer
 * Adresse ableitbar ist; die Zuteilung ist die belastbarere Auskunft.
 *
 * Dies ist der EINZIGE Teil des Honigtopfs, der Dateien aus dem Netz holt, und er läuft
 * als eigener Dienst (vhost-admin-networks.timer, wöchentlich). Die tägliche Auswertung
 * und die Ansicht schlagen ausschliesslich in der lokalen Tabelle nach.
 *
 * Aufruf: sudo php honeypot/update-networks.php [--out=<datei>]
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 09:30
 */

// Im Projektverzeichnis liegt der Autoloader unter src/, in der Installation daneben.
$bootstrap = dirname(__DIR__) . '/src/bootstrap.php';
require is_file($bootstrap) ? $bootstrap : dirname(__DIR__) . '/bootstrap.php';

use Honeypot\RirTable;

const DEFAULT_OUT = '/var/lib/vhost-admin/networks.txt';

/**
 * Die Statistikdateien der fünf Registries. ARIN nur in der erweiterten Fassung – die
 * kurze lässt Blöcke weg.
 */
const SOURCES = [
	'ripencc' => 'https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-latest',
	'arin' => 'https://ftp.arin.net/pub/stats/arin/delegated-arin-extended-latest',
	'apnic' => 'https://ftp.apnic.net/stats/apnic/delegated-apnic-latest',
	'lacnic' => 'https://ftp.lacnic.net/pub/stats/lacnic/delegated-lacnic-latest',
	'afrinic' => 'https://ftp.afrinic.net/stats/afrinic/delegated-afrinic-latest',
];

/**
 * Lädt eine Datei in eine lokale Ablage und liefert deren Pfad.
 */
function fetch(string $url, string $target): bool
{
	$command = sprintf(
		'curl -sSfL --max-time 300 --retry 2 --retry-delay 5 -o %s %s 2>&1',
		escapeshellarg($target),
		escapeshellarg($url)
	);
	exec($command, $output, $code);
	if ($code !== 0) {
		fwrite(STDERR, "  Fehlgeschlagen: $url\n  " . implode("\n  ", $output) . "\n");
		return false;
	}
	return true;
}

// ---------------------------------------------------------------------------

$out = DEFAULT_OUT;
foreach (array_slice($argv, 1) as $argument) {
	if (str_starts_with($argument, '--out=')) {
		$out = substr($argument, strlen('--out='));
	}
}

$directory = dirname($out);
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
	fwrite(STDERR, "Kann Zielverzeichnis nicht anlegen: $directory\n");
	exit(1);
}

$work = (string)tempnam(sys_get_temp_dir(), 'rir-');
// Geschrieben wird erst in eine Nebendatei und am Ende umbenannt: Die Auswertung darf
// nie eine halb geschriebene Tabelle lesen.
$target = $out . '.neu';
$handle = fopen($target, 'w');
if ($handle === false) {
	fwrite(STDERR, "Kann nicht schreiben: $target\n");
	exit(1);
}

$table = new RirTable();
$total = 0;
$failed = [];
foreach (SOURCES as $name => $url) {
	echo "== $name\n";
	if (!fetch($url, $work)) {
		$failed[] = $name;
		continue;
	}
	$source = fopen($work, 'r');
	if ($source === false) {
		$failed[] = $name;
		continue;
	}
	$lines = (static function ($source): \Generator {
		while (($line = fgets($source)) !== false) {
			yield $line;
		}
	})($source);
	$used = $table->convertAll($lines, static function (string $line) use ($handle): void {
		fwrite($handle, $line);
	});
	fclose($source);
	$total += $used;
	echo "   $used Blöcke\n";
}
fclose($handle);
@unlink($work);

// Eine Tabelle ohne alle fünf Registries wäre lückenhaft, aber brauchbar – eine leere
// nicht. Sie würde die vorhandene ersetzen und jede Herkunft verschwinden lassen.
if ($total === 0) {
	@unlink($target);
	fwrite(STDERR, "Keine Blöcke gelesen - die vorhandene Tabelle bleibt unveraendert.\n");
	exit(1);
}
if ($failed !== []) {
	fwrite(STDERR, 'Hinweis: nicht erreichbar: ' . implode(', ', $failed)
		. " - die Tabelle ist entsprechend lueckenhaft.\n");
}

if (!rename($target, $out)) {
	fwrite(STDERR, "Kann Tabelle nicht an ihren Platz bringen: $out\n");
	exit(1);
}
@chmod($out, 0644);
printf("Tabelle geschrieben: %s (%d Blöcke, %.1f MB)\n", $out, $total, filesize($out) / 1048576);
