<?php
declare(strict_types=1);

/**
 * Tägliche Auswertung der Logs eines Honigtopf-vHosts.
 *
 * Liest access.log und error.log samt der rotierten Fassungen, gruppiert alles nach
 * Kalendertag (logrotate schneidet morgens um 06:20, eine Datei enthält also zwei Tage)
 * und legt je Tag die fertige Auswertung in einer SQLite-Datenbank ab
 * (<ansichtshost>/private/honeypot/honeypot.sqlite, ohne Ansicht unter research/).
 * Fertige Tage werden nicht erneut ausgewertet (Nutzerwunsch vom 2026-09-25); die
 * Regeln stehen in Honeypot\Analyzer. Dazu ein Markdown-Bericht zum Lesen unter
 * research/honeypot/<datum>.md.
 *
 * Die Trennung ist keine Bequemlichkeit: Die Logs gehören root und sollen für den
 * Webserver unlesbar bleiben. Also rechnet dieser Dienst als root und legt nur das
 * Ergebnis dort ab, wo die Ansicht es lesen darf.
 *
 * Aufruf: php honeypot/analyse.php [--dashboard=<host>] <vhost-name> [weitere ...]
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

// Im Projektverzeichnis liegt der Autoloader unter src/, in der Installation
// (/opt/vhost-admin) flach daneben.
$bootstrap = dirname(__DIR__) . '/src/bootstrap.php';
require is_file($bootstrap) ? $bootstrap : dirname(__DIR__) . '/bootstrap.php';

use Honeypot\Analyzer;
use Honeypot\DayReport;
use Honeypot\LogParser;
use Honeypot\NetworkRegistry;
use Honeypot\PeerResolver;
use Honeypot\Period;
use Honeypot\ReportDatabase;
use Honeypot\ReportStore;
use Honeypot\Suggestions;
use Honeypot\SystemDns;

const WWW_ROOT = '/var/www';
/** Netz- und Ländertabelle; gebaut von honeypot/update-networks.php. */
const NETWORK_TABLE = '/var/lib/vhost-admin/networks.txt';
/** Name der Datenbank mit den fertigen Auswertungen. */
const DATABASE_FILE = 'honeypot.sqlite';
/** Aufbewahrte Fassungen je Log – muss zu "rotate" in src/etc/logrotate-vhost-admin passen. */
const LOG_GENERATIONS = 14;
const REPORT_DIR = __DIR__ . '/../research/honeypot';

/** Balken für die Stundenverteilung im Markdown-Bericht. */
function bar(int $value, int $max): string
{
	if ($max <= 0) {
		return '';
	}
	$width = (int)round($value / $max * 20);
	return str_repeat('█', $width) . ($value > 0 && $width === 0 ? '▏' : '');
}

/** Ein Tagesbericht als Markdown-Abschnitt. */
function section(DayReport $report, array $suggestions): string
{
	$out = "\n### {$report->date}" . ($report->complete ? '' : ' (laufender Tag)') . "\n\n";
	$out .= "- Anfragen: {$report->requests}\n";
	$out .= '- Sondierungen (404): ' . $report->probing() . "\n";
	$out .= "- kein Webzugriff: {$report->probeCount}\n";
	$out .= "- robots.txt geholt: {$report->robots}, /admin besucht: {$report->admin}, "
		. "davon nach der robots.txt: {$report->robotsThenAdmin}"
		. ($report->shortestGap() !== null ? ' (kürzester Abstand ' . $report->shortestGap() . ' s)' : '')
		. "\n";

	$loot = array_filter($report->loot);
	arsort($loot);
	if ($loot !== []) {
		$out .= "\n**Wonach gesucht wurde**\n\n";
		foreach ($loot as $group => $count) {
			$out .= sprintf("- %-18s %3d\n", $group, $count);
		}
	}

	if ($report->notFound !== []) {
		$out .= "\n**Meistgesuchte Pfade ins Leere**\n\n";
		foreach (array_slice($report->notFound, 0, 10, true) as $path => $count) {
			$out .= sprintf("- %3dx `%s`\n", $count, $path);
		}
	}

	$out .= "\n**Kennungen**\n\n";
	foreach (array_slice($report->agents, 0, 8, true) as $agent => $count) {
		$out .= sprintf("- %4dx %s\n", $count, $agent === '-' ? '_(keine)_' : '`' . $agent . '`');
	}
	if ($report->rotating !== []) {
		$out .= "\nGleiche Häufigkeit, verschiedene Kennungen – dieselbe Quelle wechselt durch:\n\n";
		foreach ($report->rotating as $count => $list) {
			$out .= '- je ' . $count . 'x: ' . count($list) . ' Kennungen, z. B. `'
				. substr((string)$list[0], 0, 60) . "`\n";
		}
	}

	if ($report->probes !== []) {
		$out .= "\n**Was gar kein Webzugriff war**\n\n";
		foreach (array_slice($report->probes, 0, 8, true) as $raw => $count) {
			$out .= sprintf("- %3dx `%s`\n", $count, str_replace('`', "'", (string)$raw));
		}
	}

	if ($report->logins !== []) {
		$out .= "\n**Anmeldeversuche**\n\n";
		foreach (array_slice($report->logins, 0, 10, true) as $user => $count) {
			$out .= sprintf("- %3dx `%s`\n", $count, $user === '' ? '(leer)' : $user);
		}
	}

	$out .= "\n**Tagesverlauf**\n\n```\n";
	$max = max($report->hours ?: [0]);
	foreach ($report->hours as $hour => $count) {
		$out .= sprintf("%s  %4d  %s\n", $hour, $count, bar($count, (int)$max));
	}
	$out .= "```\n";

	$out .= "\n**Vorschläge für die Ansicht**\n\n";
	foreach ($suggestions as $suggestion) {
		$out .= "- $suggestion\n";
	}
	return $out;
}

// ---------------------------------------------------------------------------

$names = [];
$dashboard = null;
foreach (array_slice($argv, 1) as $argument) {
	if (str_starts_with($argument, '--dashboard=')) {
		$dashboard = substr($argument, strlen('--dashboard='));
		continue;
	}
	$names[] = $argument;
}
if ($names === []) {
	fwrite(STDERR, "Aufruf: php honeypot/analyse.php [--dashboard=<host>] <vhost-name> [weitere ...]\n");
	exit(1);
}
if (!is_dir(REPORT_DIR) && !mkdir(REPORT_DIR, 0755, true) && !is_dir(REPORT_DIR)) {
	fwrite(STDERR, 'Kann Berichtsverzeichnis nicht anlegen: ' . REPORT_DIR . "\n");
	exit(1);
}

// Die Datenbank liegt bei der Ansicht (dort liest sie sie), sonst bei den Berichten.
$dataDir = REPORT_DIR;
if ($dashboard !== null) {
	$dataDir = WWW_ROOT . '/' . $dashboard . '/private/honeypot';
	if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
		fwrite(STDERR, "Kann Datenverzeichnis der Ansicht nicht anlegen: $dataDir\n");
		exit(1);
	}
}
$database = ReportDatabase::open($dataDir . '/' . DATABASE_FILE);
// Einmalige Übernahme der früheren JSON-Tagesberichte; idempotent, danach ohne Wirkung.
$imported = $database->importJson(new ReportStore($dataDir));
if ($imported > 0) {
	echo "$imported frühere Tagesberichte (JSON) übernommen.\n";
}

$parser = new LogParser();
$dns = new SystemDns();
$networks = new NetworkRegistry(NETWORK_TABLE);
if (!$networks->isAvailable()) {
	fwrite(STDERR, 'Hinweis: keine Netztabelle unter ' . NETWORK_TABLE
		. " - Gegenstellen erscheinen ohne Netz und Land (sudo php honeypot/update-networks.php).\n");
}
// Ein Resolver für den ganzen Lauf: Er merkt sich Antworten über alle Tage und Hosts.
$analyzer = new Analyzer($database, new PeerResolver($dns, $networks), $parser);
$suggester = new Suggestions();
$today = date('Y-m-d');

$markdown = "# Honigtopf-Auswertung $today\n\n"
	. "Erzeugt von `honeypot/analyse.php`. Grundlage sind access.log und error.log samt\n"
	. "der rotierten Fassungen; fertige Tage stehen in der Datenbank und werden nicht\n"
	. "erneut ausgewertet.\n";

foreach ($names as $name) {
	$logs = WWW_ROOT . '/' . $name . '/logs';
	if (!is_dir($logs)) {
		$markdown .= "\n## $name\n\nKein Logverzeichnis: `$logs`\n";
		continue;
	}
	// Alle vorhandenen Fassungen lesen (readFile() nimmt auch .gz). Das Lesen kostet
	// Millisekunden; teuer ist das Auswerten samt Nachschlagen, und das übernimmt der
	// Analyzer nur für Tage, die es brauchen.
	$lines = $parser->readFile($logs . '/access.log');
	$errorLines = $parser->readFile($logs . '/error.log');
	for ($generation = 1; $generation <= LOG_GENERATIONS; $generation++) {
		$lines = array_merge($lines, $parser->readFile($logs . '/access.log.' . $generation));
		$errorLines = array_merge($errorLines, $parser->readFile($logs . '/error.log.' . $generation));
	}
	// Existiert die letzte Fassung, die logrotate aufbewahrt, ist die davor schon
	// gelöscht – und mit ihr vermutlich der Anfang des ältesten Tages.
	$oldestMayBeCut = is_file($logs . '/access.log.' . LOG_GENERATIONS)
		|| is_file($logs . '/access.log.' . LOG_GENERATIONS . '.gz');

	// Eigene Namen und Adressen sind keine Gegenstelle. Der Portscanner MGLNDD etwa
	// schreibt die Adresse des ZIELS in seine Anfrage – das wären wir selbst.
	$ownNames = [$name, 'www.' . $name];
	foreach ([$name, 'www.' . $name] as $ownName) {
		$ownAddress = $dns->addressFor($ownName);
		if ($ownAddress !== null) {
			$ownNames[] = $ownAddress;
		}
	}

	$result = $analyzer->run($name, $lines, $errorLines, $ownNames, $today, $oldestMayBeCut);
	echo "$name: ausgewertet " . count($result['analysed']) . ', übersprungen ' . count($result['skipped'])
		. ', behalten ' . count($result['kept']) . "\n";

	$markdown .= "\n## $name\n";
	$yesterday = date('Y-m-d', strtotime('-1 day'));
	foreach ([$today, $yesterday] as $date) {
		$report = $database->load($name, Period::day($date));
		if ($report !== null) {
			$markdown .= section($report, $suggester->forReport($report));
		}
	}
}

$target = REPORT_DIR . '/' . $today . '.md';
file_put_contents($target, $markdown);
echo "Bericht geschrieben: $target\n";
echo 'Datenbank: ' . $dataDir . '/' . DATABASE_FILE . "\n";
