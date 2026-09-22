<?php
declare(strict_types=1);

/**
 * Tägliche Auswertung der Logs eines Honigtopf-vHosts.
 *
 * Liest access.log und error.log des laufenden Tages sowie die des Vortags (logrotate
 * legt sie mit "delaycompress" als *.log.1 unkomprimiert ab) und schreibt einen Bericht
 * nach research/honeypot/<datum>.md. Der Bericht ist zugleich die Datenquelle für die
 * Ansicht unter bienchen.mfsvr.de – die Logs selbst gehören root und sollen für
 * www-data unlesbar bleiben.
 *
 * Aus den Beobachtungen leitet die Auswertung Vorschläge ab, welche Werkzeuge in der
 * Ansicht als Nächstes lohnen. Die Regeln stehen in suggestions() und sind bewusst an
 * Schwellen geknüpft: Ein Vorschlag erscheint, wenn die Daten ihn tragen, nicht weil er
 * grundsätzlich denkbar wäre.
 *
 * Aufruf: php honeypot/analyse.php <vhost-name> [weitere ...]
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 13:30
 */

const WWW_ROOT = '/var/www';
const REPORT_DIR = __DIR__ . '/../research/honeypot';

/**
 * Beutegruppen für gesuchte Pfade. Die Gruppe ist die Aussage, nicht der einzelne Pfad.
 *
 * @var array<string, string> Gruppenname => regulärer Ausdruck
 */
const LOOT = [
	'Zugangsdaten' => '/(\.env|wp-config|credential|secret|\.git\/config|id_rsa|\.aws|passwd)/i',
	'Konfiguration' => '/(web\.config|config\.(json|php|yml|yaml)|settings\.py|\.htaccess)/i',
	'Paketdateien' => '/(yarn\.lock|package(-lock)?\.json|composer\.(json|lock)|Gemfile)/i',
	'Entwicklungsreste' => '/(phpinfo|\/tests?\/|\/tmp\/|\.bak|\.old|\.swp|\/debug)/i',
	'Verwaltung' => '/(phpmyadmin|\/admin|\/manager|\/wp-admin|\/cpanel|\/solr)/i',
	'Sicherungen' => '/(\.sql|\.tar|\.gz|\.zip|backup|dump)/i',
];

/**
 * Eine Zeile aus dem Combined-Format zerlegen.
 *
 * @return ?array{ip: string, time: string, hour: string, request: string, method: string,
 *                 path: string, status: string, agent: string}
 */
function parseLine(string $line): ?array
{
	if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\S+) \S+ "[^"]*" "([^"]*)"/', $line, $m) !== 1) {
		return null;
	}
	$request = $m[3];
	$parts = explode(' ', $request);
	return [
		'ip' => $m[1],
		'time' => $m[2],
		'hour' => substr($m[2], 12, 2),
		'request' => $request,
		'method' => $parts[0] ?? '',
		'path' => $parts[1] ?? '',
		'status' => $m[4],
		'agent' => $m[5],
	];
}

/**
 * Zeilen einer Logdatei, auch aus der rotierten bzw. komprimierten Fassung.
 *
 * @return list<string>
 */
function readLog(string $path): array
{
	foreach ([$path, $path . '.gz'] as $candidate) {
		if (!is_file($candidate)) {
			continue;
		}
		$text = str_ends_with($candidate, '.gz')
			? (string)gzdecode((string)file_get_contents($candidate))
			: (string)file_get_contents($candidate);
		return array_values(array_filter(explode("\n", $text), static fn(string $l): bool => trim($l) !== ''));
	}
	return [];
}

/**
 * Kennzahlen eines Tages.
 *
 * @param list<string> $lines
 * @return array<string, mixed>
 */
function analyse(array $lines): array
{
	$stats = [
		'requests' => count($lines),
		'status' => [],
		'hours' => array_fill_keys(array_map(static fn(int $h): string => sprintf('%02d', $h), range(0, 23)), 0),
		'agents' => [],
		'notFound' => [],
		'loot' => array_fill_keys(array_keys(LOOT), 0),
		'methods' => [],
		'nonHttp' => [],
		'robots' => 0,
		'admin' => 0,
		'robotsThenAdmin' => 0,
		'unparsed' => 0,
	];
	$sawRobots = false;
	foreach ($lines as $line) {
		$entry = parseLine($line);
		if ($entry === null) {
			$stats['unparsed']++;
			continue;
		}
		$stats['status'][$entry['status']] = ($stats['status'][$entry['status']] ?? 0) + 1;
		$stats['hours'][$entry['hour']] = ($stats['hours'][$entry['hour']] ?? 0) + 1;
		$stats['agents'][$entry['agent']] = ($stats['agents'][$entry['agent']] ?? 0) + 1;
		$stats['methods'][$entry['method']] = ($stats['methods'][$entry['method']] ?? 0) + 1;

		// Kein gültiges HTTP-Verb: Sondierung eines anderen Dienstes, kein Webzugriff.
		if ($entry['method'] !== '' && preg_match('/^[A-Z]{3,8}$/', $entry['method']) !== 1) {
			$stats['nonHttp'][] = substr($entry['request'], 0, 60);
		}
		if ($entry['status'] === '404') {
			$stats['notFound'][$entry['path']] = ($stats['notFound'][$entry['path']] ?? 0) + 1;
			foreach (LOOT as $group => $pattern) {
				if (preg_match($pattern, $entry['path']) === 1) {
					$stats['loot'][$group]++;
					break;
				}
			}
		}
		if ($entry['path'] === '/robots.txt') {
			$stats['robots']++;
			$sawRobots = true;
		}
		if (str_starts_with($entry['path'], '/admin')) {
			$stats['admin']++;
			if ($sawRobots) {
				$stats['robotsThenAdmin']++;
			}
		}
	}
	arsort($stats['agents']);
	arsort($stats['notFound']);
	arsort($stats['methods']);
	return $stats;
}

/**
 * Werkzeuge, die sich getarnt haben: mehrere Kennungen mit genau gleicher Häufigkeit
 * stammen mit grosser Wahrscheinlichkeit aus einer Quelle, die durchwechselt.
 *
 * @param array<string, int> $agents
 * @return array<int, list<string>> Häufigkeit => Kennungen
 */
function rotatingAgents(array $agents): array
{
	$byCount = [];
	foreach ($agents as $agent => $count) {
		if ($count < 5) {
			continue;
		}
		$byCount[$count][] = $agent;
	}
	return array_filter($byCount, static fn(array $list): bool => count($list) >= 2);
}

/**
 * Anmeldeversuche aus dem error.log.
 *
 * @param list<string> $lines
 * @return array<string, int> Benutzername => Versuche
 */
function loginAttempts(array $lines): array
{
	$users = [];
	foreach ($lines as $line) {
		if (preg_match('/user "([^"]*)" (?:was not found|password mismatch)/', $line, $m) === 1) {
			$users[$m[1]] = ($users[$m[1]] ?? 0) + 1;
		}
	}
	arsort($users);
	return $users;
}

/**
 * Vorschläge für weitere Werkzeuge in der Ansicht – an Schwellen geknüpft, damit nur
 * erscheint, was die Daten auch tragen.
 *
 * @param array<string, mixed> $today
 * @param array<string, int>   $logins
 * @return list<string>
 */
function suggestions(array $today, array $logins, array $rotating): array
{
	$out = [];
	if ($today['loot']['Zugangsdaten'] >= 5) {
		$out[] = '**Köderdateien mit Kennung.** Es wurde ' . $today['loot']['Zugangsdaten']
			. '-mal nach Zugangsdaten gesucht. Eine `/.env` mit einer je Abruf eindeutigen, sonst '
			. 'nirgends gültigen Zeichenkette würde zeigen, ob und wo der Fund später benutzt wird. '
			. 'Wichtig: nur erfundene Werte, nichts, was irgendwo gilt.';
	}
	if (count($today['nonHttp']) >= 3) {
		$out[] = '**Eigene Kachel für Nicht-HTTP.** ' . count($today['nonHttp'])
			. ' Anfragen waren gar kein HTTP (SSH-Banner, Portscanner-Kennungen, Binärmüll). '
			. 'Die fallen bei jeder gewöhnlichen Auswertung hinten runter und sagen am meisten '
			. 'darüber aus, wonach auf Dienstebene gesucht wird.';
	}
	if ($rotating !== []) {
		$out[] = '**Kennungen nach Häufigkeit gruppieren.** Mehrere Kennungen kamen genau '
			. 'gleich oft vor (' . implode(', ', array_keys($rotating)) . ' Anfragen je Gruppe) – '
			. 'ein Werkzeug, das durchwechselt. Eine Gruppierung nach gleicher Häufigkeit erkennt '
			. 'das zuverlässiger als jede Liste bekannter Bots.';
	}
	if ($today['robots'] > 0 && $today['robotsThenAdmin'] > 0) {
		$out[] = '**Zeitabstand robots.txt → /admin/ messen.** ' . $today['robotsThenAdmin']
			. '-mal wurde nach dem Lesen der robots.txt der dort ausgeschlossene Pfad besucht. '
			. 'Der Abstand zwischen beiden Abrufen trennt „liest und wertet aus" von „ruft beides '
			. 'blind ab".';
	}
	if ($logins !== []) {
		$out[] = '**Versuchte Benutzernamen sammeln.** ' . count($logins)
			. ' verschiedene Namen wurden probiert. Eine Liste über Wochen zeigt, ob generisch '
			. 'geraten wird (admin, root) oder gezielt (Domainname, echte Namen).';
	}
	if (($today['status']['404'] ?? 0) >= 20) {
		$out[] = '**Sondierungspfade über Tage vergleichen.** ' . ($today['status']['404'] ?? 0)
			. ' Treffer ins Leere. Welche Pfade neu dazukommen, zeigt, welche Lücke gerade '
			. 'reihum ausprobiert wird – das ist die nützlichste Frühwarnung, die diese Seite liefern kann.';
	}
	if ($out === []) {
		$out[] = 'Keine. Die Zahlen des Tages tragen keinen der vorgesehenen Vorschläge.';
	}
	return $out;
}

/** Balken für die Stundenverteilung. */
function bar(int $value, int $max): string
{
	if ($max <= 0) {
		return '';
	}
	$width = (int)round($value / $max * 20);
	return str_repeat('█', $width) . ($value > 0 && $width === 0 ? '▏' : '');
}

// ---------------------------------------------------------------------------

$names = array_slice($argv, 1);
if ($names === []) {
	fwrite(STDERR, "Aufruf: php honeypot/analyse.php <vhost-name> [weitere ...]\n");
	exit(1);
}
if (!is_dir(REPORT_DIR) && !mkdir(REPORT_DIR, 0755, true) && !is_dir(REPORT_DIR)) {
	fwrite(STDERR, 'Kann Berichtsverzeichnis nicht anlegen: ' . REPORT_DIR . "\n");
	exit(1);
}

$date = date('Y-m-d');
$report = "# Honigtopf-Auswertung $date\n\n"
	. "Erzeugt von `honeypot/analyse.php`. Grundlage sind access.log und error.log des\n"
	. "laufenden Tages sowie die rotierten Fassungen des Vortags.\n";

foreach ($names as $name) {
	$logs = WWW_ROOT . '/' . $name . '/logs';
	if (!is_dir($logs)) {
		$report .= "\n## $name\n\nKein Logverzeichnis: `$logs`\n";
		continue;
	}
	$today = analyse(readLog($logs . '/access.log'));
	$yesterday = analyse(readLog($logs . '/access.log.1'));
	$logins = loginAttempts(array_merge(readLog($logs . '/error.log'), readLog($logs . '/error.log.1')));
	// Je Tag getrennt: Ein "+" auf beiden Listen wäre eine Vereinigung, keine Summe –
	// bei gleichen Kennungen gewänne stillschweigend der heutige Wert.
	$rotatingToday = rotatingAgents($today['agents']);
	$rotatingYesterday = rotatingAgents($yesterday['agents']);
	$rotating = $rotatingToday !== [] ? $rotatingToday : $rotatingYesterday;

	$delta = $yesterday['requests'] > 0
		? sprintf('%+d %%', (int)round(($today['requests'] - $yesterday['requests']) / $yesterday['requests'] * 100))
		: 'kein Vortagswert';

	$report .= "\n## $name\n\n";
	$report .= "| | heute | Vortag |\n|---|---:|---:|\n";
	$report .= "| Anfragen | {$today['requests']} | {$yesterday['requests']} |\n";
	$report .= '| Sondierungen (404) | ' . ($today['status']['404'] ?? 0) . ' | ' . ($yesterday['status']['404'] ?? 0) . " |\n";
	$report .= '| kein HTTP | ' . count($today['nonHttp']) . ' | ' . count($yesterday['nonHttp']) . " |\n";
	$report .= "| Veränderung | $delta | |\n\n";

	$report .= "### Wonach gesucht wurde\n\n";
	$loot = array_filter($today['loot']);
	arsort($loot);
	$report .= $loot === [] ? "Nichts aus den bekannten Gruppen.\n" : '';
	foreach ($loot as $group => $count) {
		$report .= sprintf("- %-18s %3d\n", $group, $count);
	}

	$report .= "\n### Meistgesuchte Pfade ins Leere\n\n";
	$report .= $today['notFound'] === [] ? "Keine.\n" : '';
	foreach (array_slice($today['notFound'], 0, 10, true) as $path => $count) {
		$report .= sprintf("- %3dx `%s`\n", $count, $path);
	}

	$report .= "\n### Kennungen\n\n";
	foreach (array_slice($today['agents'], 0, 8, true) as $agent => $count) {
		$report .= sprintf("- %4dx %s\n", $count, $agent === '-' ? '_(keine)_' : '`' . $agent . '`');
	}
	foreach (['heute' => $rotatingToday, 'Vortag' => $rotatingYesterday] as $label => $groups) {
		if ($groups === []) {
			continue;
		}
		$report .= "\nGleiche Häufigkeit, verschiedene Kennungen ($label) – dieselbe Quelle wechselt durch:\n\n";
		foreach ($groups as $count => $list) {
			$report .= '- je ' . $count . 'x: ' . count($list) . ' Kennungen, z. B. `'
				. substr((string)$list[0], 0, 60) . "`\n";
		}
	}

	if ($today['nonHttp'] !== []) {
		$report .= "\n### Was gar kein HTTP war\n\n";
		foreach (array_slice(array_unique($today['nonHttp']), 0, 8) as $raw) {
			$report .= '- `' . str_replace('`', "'", $raw) . "`\n";
		}
	}

	$report .= "\n### robots.txt-Signal\n\n";
	$report .= "- robots.txt geholt: {$today['robots']}\n";
	$report .= "- /admin besucht: {$today['admin']}\n";
	$report .= "- davon nach dem Lesen der robots.txt: {$today['robotsThenAdmin']}\n";

	if ($logins !== []) {
		$report .= "\n### Anmeldeversuche\n\n";
		foreach (array_slice($logins, 0, 10, true) as $user => $count) {
			$report .= sprintf("- %3dx `%s`\n", $count, $user === '' ? '(leer)' : $user);
		}
	}

	$report .= "\n### Tagesverlauf\n\n```\n";
	$max = max($today['hours'] ?: [0]);
	foreach ($today['hours'] as $hour => $count) {
		$report .= sprintf("%s  %4d  %s\n", $hour, $count, bar($count, $max));
	}
	$report .= "```\n";

	$report .= "\n### Vorschläge für die Ansicht\n\n";
	foreach (suggestions($today, $logins, $rotating) as $suggestion) {
		$report .= "- $suggestion\n";
	}
}

$target = REPORT_DIR . '/' . $date . '.md';
file_put_contents($target, $report);
echo "Bericht geschrieben: $target\n";
