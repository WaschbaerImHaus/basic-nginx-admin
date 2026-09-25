<?php
declare(strict_types=1);

/**
 * Ablage fertiger Tagesauswertungen in SQLite.
 *
 * Nutzerwunsch vom 2026-09-25: Die Logs verschwinden nach 14 Tagen (logrotate); was
 * einmal ausgewertet ist, soll bleiben und nicht erneut ausgewertet werden müssen. Die
 * Ansicht wählt Tage und Zeiträume; ein Zeitraum ist die Summe seiner Tage.
 *
 * Aufbau:
 *   days    je Host und Tag die Einzelzahlen, dazu abgeschlossen/Fassung
 *   counts  je Host, Tag, Dimension (Status, Stunde, Kennung, Pfad …) ein Zähler –
 *           Zeiträume entstehen per SUM() … GROUP BY, ohne Tagesberichte zu laden
 *   gaps, events, peers  Listen des Tages
 *
 * `rank` hält die Reihenfolge des Tagesberichts fest. Ein einzelner Tag kommt dadurch
 * genau so zurück, wie er gespeichert wurde (geprüft per assertEquals).
 *
 * Geschrieben wird als root (nur root liest die Logs), gelesen von der Ansicht im
 * php-fpm-Pool – ausschliesslich über openReadOnly(). Die Datei bekommt 0640 und die
 * Gruppe ihres Ordners.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

namespace Honeypot;

final class ReportDatabase
{
	/**
	 * Dimensionen der Zähler und das Feld des Tagesberichts, aus dem sie stammen.
	 */
	private const DIMENSIONS = [
		'status' => 'status',
		'hour' => 'hours',
		'agent' => 'agents',
		'path' => 'notFound',
		'loot' => 'loot',
		'method' => 'methods',
		'probe_kind' => 'probeKinds',
		'probe' => 'probes',
		'login' => 'logins',
	];

	private const SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS days (
	host TEXT NOT NULL,
	date TEXT NOT NULL,
	complete INTEGER NOT NULL,
	version INTEGER NOT NULL,
	analysed_at TEXT NOT NULL,
	requests INTEGER NOT NULL,
	probe_count INTEGER NOT NULL,
	robots INTEGER NOT NULL,
	admin INTEGER NOT NULL,
	robots_then_admin INTEGER NOT NULL,
	unreadable INTEGER NOT NULL,
	PRIMARY KEY (host, date)
);
CREATE TABLE IF NOT EXISTS counts (
	host TEXT NOT NULL,
	date TEXT NOT NULL,
	dimension TEXT NOT NULL,
	item TEXT NOT NULL,
	value INTEGER NOT NULL,
	rank INTEGER NOT NULL,
	PRIMARY KEY (host, date, dimension, item)
);
CREATE INDEX IF NOT EXISTS counts_range ON counts (host, dimension, date);
CREATE TABLE IF NOT EXISTS gaps (
	host TEXT NOT NULL,
	date TEXT NOT NULL,
	seq INTEGER NOT NULL,
	seconds INTEGER NOT NULL,
	PRIMARY KEY (host, date, seq)
);
CREATE TABLE IF NOT EXISTS events (
	host TEXT NOT NULL,
	date TEXT NOT NULL,
	seq INTEGER NOT NULL,
	time TEXT NOT NULL,
	method TEXT NOT NULL,
	path TEXT NOT NULL,
	request TEXT NOT NULL,
	status TEXT NOT NULL,
	agent TEXT NOT NULL,
	grp TEXT NOT NULL,
	probe TEXT NOT NULL,
	PRIMARY KEY (host, date, seq)
);
CREATE TABLE IF NOT EXISTS peers (
	host TEXT NOT NULL,
	date TEXT NOT NULL,
	peer TEXT NOT NULL,
	kind TEXT NOT NULL,
	requests INTEGER NOT NULL,
	address TEXT,
	network TEXT,
	country TEXT,
	registry TEXT,
	reverse TEXT,
	PRIMARY KEY (host, date, peer)
);
SQL;

	private function __construct(private readonly \PDO $pdo, private readonly string $path)
	{
	}

	/**
	 * Öffnet die Datenbank zum Schreiben und legt das Schema an (für die Auswertung).
	 */
	public static function open(string $path): self
	{
		$pdo = new \PDO('sqlite:' . $path, null, null, [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_TIMEOUT => 10,
		]);
		$pdo->exec(self::SCHEMA);
		$db = new self($pdo, $path);
		$db->fixPermissions();
		return $db;
	}

	/**
	 * Öffnet die Datenbank nur lesend (für die Ansicht); null, wenn es sie nicht gibt.
	 * Selbst ein Fehler in der Ansicht kann so keine Auswertung verändern.
	 */
	public static function openReadOnly(string $path): ?self
	{
		if (!is_file($path) || !is_readable($path)) {
			return null;
		}
		try {
			$pdo = new \PDO('sqlite:' . $path, null, null, [
				\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
				\PDO::ATTR_TIMEOUT => 5,
				\Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
			]);
		} catch (\PDOException) {
			return null;
		}
		return new self($pdo, $path);
	}

	/**
	 * Legt einen Tagesbericht ab und ersetzt eine frühere Fassung desselben Tages
	 * vollständig.
	 *
	 * @param int $version Fassung der Auswertung, mit der der Bericht entstand
	 */
	public function save(string $host, DayReport $report, int $version): void
	{
		$this->pdo->beginTransaction();
		try {
			$this->delete($host, $report->date);
			$this->pdo->prepare(
				'INSERT INTO days (host, date, complete, version, analysed_at, requests, probe_count,'
				. ' robots, admin, robots_then_admin, unreadable) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
			)->execute([
				$host, $report->date, $report->complete ? 1 : 0, $version, date('c'), $report->requests,
				$report->probeCount, $report->robots, $report->admin, $report->robotsThenAdmin, $report->unreadable,
			]);

			$count = $this->pdo->prepare(
				'INSERT INTO counts (host, date, dimension, item, value, rank) VALUES (?,?,?,?,?,?)'
			);
			foreach (self::DIMENSIONS as $dimension => $field) {
				$rank = 0;
				foreach ($report->$field as $item => $value) {
					// Nullen werden nicht gespeichert (Stunden, Beutegruppen) – beim Laden
					// kommen sie aus der festen Liste zurück.
					if ((int)$value !== 0) {
						$count->execute([$host, $report->date, $dimension, (string)$item, (int)$value, $rank]);
					}
					$rank++;
				}
			}

			$gap = $this->pdo->prepare('INSERT INTO gaps (host, date, seq, seconds) VALUES (?,?,?,?)');
			foreach ($report->gaps as $seq => $seconds) {
				$gap->execute([$host, $report->date, $seq, $seconds]);
			}

			$event = $this->pdo->prepare(
				'INSERT INTO events (host, date, seq, time, method, path, request, status, agent, grp, probe)'
				. ' VALUES (?,?,?,?,?,?,?,?,?,?,?)'
			);
			foreach ($report->events as $seq => $e) {
				$event->execute([
					$host, $report->date, $seq, (string)($e['time'] ?? ''), (string)($e['method'] ?? ''),
					(string)($e['path'] ?? ''), (string)($e['request'] ?? ''), (string)($e['status'] ?? ''),
					(string)($e['agent'] ?? ''), (string)($e['group'] ?? ''), (string)($e['probe'] ?? ''),
				]);
			}

			$peer = $this->pdo->prepare(
				'INSERT INTO peers (host, date, peer, kind, requests, address, network, country, registry, reverse)'
				. ' VALUES (?,?,?,?,?,?,?,?,?,?)'
			);
			foreach ($report->peers as $p) {
				$peer->execute([
					$host, $report->date, $p->host, $p->kind, $p->requests, $p->address,
					$p->network?->network, $p->network?->country, $p->network?->registry, $p->reverse,
				]);
			}
			$this->pdo->commit();
		} catch (\Throwable $e) {
			$this->pdo->rollBack();
			throw $e;
		}
		$this->fixPermissions();
	}

	/**
	 * Der Bericht über einen Zeitraum – die Summe seiner Tage; null ohne Daten.
	 */
	public function load(string $host, Period $period): ?DayReport
	{
		$range = [$host, $period->from, $period->to];
		$totals = $this->one(
			'SELECT COUNT(*) AS days, MIN(complete) AS complete, SUM(requests) AS requests,'
			. ' SUM(probe_count) AS probe_count, SUM(robots) AS robots, SUM(admin) AS admin,'
			. ' SUM(robots_then_admin) AS robots_then_admin, SUM(unreadable) AS unreadable'
			. ' FROM days WHERE host = ? AND date BETWEEN ? AND ?',
			$range
		);
		if ($totals === null || (int)$totals['days'] === 0) {
			return null;
		}

		$counts = array_fill_keys(array_keys(self::DIMENSIONS), []);
		$rows = $this->all(
			'SELECT dimension, item, SUM(value) AS total FROM counts WHERE host = ? AND date BETWEEN ? AND ?'
			// Bei gleicher Summe gilt die Reihenfolge des Tagesberichts (rank) – so kommt
			// ein einzelner Tag genau so zurück, wie er gespeichert wurde.
			. " GROUP BY dimension, item ORDER BY dimension, total DESC, MIN(date || printf('%06d', rank)), item",
			$range
		);
		foreach ($rows as $row) {
			$counts[$row['dimension']][(string)$row['item']] = (int)$row['total'];
		}
		// Feste Listen mit allen Stunden und Beutegruppen, auch den leeren.
		$hours = array_fill_keys(array_map(static fn(int $h): string => sprintf('%02d', $h), range(0, 23)), 0);
		foreach ($counts['hour'] as $hour => $value) {
			$hours[sprintf('%02d', (int)$hour)] = $value;
		}
		$loot = array_fill_keys(array_keys(LootClassifier::GROUPS), 0);
		foreach ($counts['loot'] as $group => $value) {
			$loot[$group] = $value;
		}

		$gaps = array_map(
			static fn(array $row): int => (int)$row['seconds'],
			$this->all('SELECT seconds FROM gaps WHERE host = ? AND date BETWEEN ? AND ? ORDER BY date, seq', $range)
		);

		// Die jüngsten Ereignisse, chronologisch. Für einen einzelnen Tag sind das alle.
		$events = array_reverse(array_map(
			static fn(array $row): array => [
				'time' => $row['time'], 'method' => $row['method'], 'path' => $row['path'],
				'request' => $row['request'], 'status' => $row['status'], 'agent' => $row['agent'],
				'group' => $row['grp'], 'probe' => $row['probe'],
			],
			$this->all(
				'SELECT * FROM events WHERE host = ? AND date BETWEEN ? AND ? ORDER BY date DESC, seq DESC LIMIT '
				. DayReport::EVENT_LIMIT,
				$range
			)
		));

		// Gegenstellen über mehrere Tage: Anfragen addiert, Adresse und Netz vom jüngsten
		// Tag (SQLite nimmt die übrigen Spalten aus der Zeile mit MAX(date)).
		$peers = array_map(
			static fn(array $row): Peer => new Peer(
				(string)$row['peer'],
				(string)$row['kind'],
				(int)$row['total'],
				$row['address'] === null ? null : (string)$row['address'],
				$row['network'] === null ? null : new NetworkInfo(
					(string)$row['address'], (string)$row['network'], (string)$row['country'], (string)$row['registry']
				),
				$row['reverse'] === null ? null : (string)$row['reverse'],
			),
			$this->all(
				'SELECT peer, kind, SUM(requests) AS total, MAX(date) AS latest, address, network, country, registry, reverse'
				. ' FROM peers WHERE host = ? AND date BETWEEN ? AND ? GROUP BY peer ORDER BY total DESC, peer',
				$range
			)
		);

		return new DayReport(
			$period->from,
			(bool)$totals['complete'],
			(int)$totals['requests'],
			$counts['status'],
			$hours,
			$counts['agent'],
			DayReport::detectRotation($counts['agent']),
			$counts['path'],
			$loot,
			$counts['method'],
			(int)$totals['probe_count'],
			$counts['probe_kind'],
			$counts['probe'],
			(int)$totals['robots'],
			(int)$totals['admin'],
			(int)$totals['robots_then_admin'],
			$gaps,
			$counts['login'],
			(int)$totals['unreadable'],
			$events,
			$peers,
		);
	}

	/**
	 * Was über einen Tag gespeichert ist – für die Entscheidung, ob er erneut
	 * ausgewertet werden muss; null, wenn er fehlt.
	 *
	 * @return ?array{complete: bool, version: int, requests: int}
	 */
	public function info(string $host, string $date): ?array
	{
		$row = $this->one('SELECT complete, version, requests FROM days WHERE host = ? AND date = ?', [$host, $date]);
		return $row === null ? null : [
			'complete' => (bool)$row['complete'],
			'version' => (int)$row['version'],
			'requests' => (int)$row['requests'],
		];
	}

	/**
	 * Hosts mit Daten, alphabetisch.
	 *
	 * @return list<string>
	 */
	public function hosts(): array
	{
		return array_map(
			static fn(array $row): string => (string)$row['host'],
			$this->all('SELECT DISTINCT host FROM days ORDER BY host', [])
		);
	}

	/**
	 * Erster und letzter Tag mit Daten; null ohne Daten.
	 *
	 * @return ?array{0: string, 1: string}
	 */
	public function bounds(string $host): ?array
	{
		$row = $this->one('SELECT MIN(date) AS first, MAX(date) AS last FROM days WHERE host = ?', [$host]);
		return $row === null || $row['first'] === null ? null : [(string)$row['first'], (string)$row['last']];
	}

	/**
	 * Je Tag: Anfragen, Sondierungen (404), Anfragen ohne Webzugriff. Nur Tage mit Daten.
	 *
	 * @return array<string, array{requests: int, probing: int, probes: int}>
	 */
	public function dailyTotals(string $host, Period $period): array
	{
		$out = [];
		foreach ($this->all(
			"SELECT d.date, d.requests, d.probe_count, COALESCE(c.value, 0) AS probing FROM days d"
			. " LEFT JOIN counts c ON c.host = d.host AND c.date = d.date AND c.dimension = 'status' AND c.item = '404'"
			. ' WHERE d.host = ? AND d.date BETWEEN ? AND ? ORDER BY d.date',
			[$host, $period->from, $period->to]
		) as $row) {
			$out[(string)$row['date']] = [
				'requests' => (int)$row['requests'],
				'probing' => (int)$row['probing'],
				'probes' => (int)$row['probe_count'],
			];
		}
		return $out;
	}

	/**
	 * Je Tag die gesuchten Pfade (404) – für den Vergleich über Tage.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function dailyPaths(string $host, Period $period): array
	{
		$out = [];
		foreach ($this->all(
			"SELECT date, item, value FROM counts WHERE host = ? AND dimension = 'path' AND date BETWEEN ? AND ?"
			. ' ORDER BY date, rank',
			[$host, $period->from, $period->to]
		) as $row) {
			$out[(string)$row['date']][(string)$row['item']] = (int)$row['value'];
		}
		return $out;
	}

	/**
	 * Ereignisse eines Zeitraums, gefiltert und begrenzt – in der Datenbank, weil die
	 * Liste über Monate gross wird. Neueste zuerst.
	 *
	 * Die Suche ist wörtlich (instr, nicht LIKE): % und _ sind Zeichen, keine
	 * Platzhalter. Alle Werte gehen als Parameter an die Datenbank.
	 *
	 * @param string $what '' (alle), 'sondierung', 'dienst' oder 'falle'
	 * @return array{rows: list<array<string, string>>, total: int}
	 */
	public function events(string $host, Period $period, string $what, string $needle, int $limit): array
	{
		$where = 'host = ? AND date BETWEEN ? AND ?';
		$params = [$host, $period->from, $period->to];
		$where .= match ($what) {
			'sondierung' => " AND status = '404'",
			'dienst' => " AND probe <> ''",
			'falle' => " AND (path = '/robots.txt' OR substr(path, 1, 6) = '/admin')",
			default => '',
		};
		if ($needle !== '') {
			$where .= " AND instr(lower(path || ' ' || agent || ' ' || request), lower(?)) > 0";
			$params[] = $needle;
		}
		$total = (int)($this->one("SELECT COUNT(*) AS n FROM events WHERE $where", $params)['n'] ?? 0);
		$rows = $this->all(
			"SELECT date, time, method, path, request, status, agent, grp AS 'group', probe FROM events"
			. " WHERE $where ORDER BY date DESC, seq DESC LIMIT " . max(0, $limit),
			$params
		);
		return ['rows' => $rows, 'total' => $total];
	}

	/**
	 * Übernimmt die Tagesberichte der früheren JSON-Ablage, wo die Datenbank den Tag
	 * noch nicht kennt. Sie tragen Fassung 1.
	 *
	 * @return int Zahl der übernommenen Tage
	 */
	public function importJson(ReportStore $store): int
	{
		$imported = 0;
		foreach ($store->hosts() as $host) {
			foreach ($store->days($host) as $date) {
				if ($this->info($host, $date) !== null) {
					continue;
				}
				$report = $store->load($host, $date);
				if ($report !== null) {
					$this->save($host, $report, 1);
					$imported++;
				}
			}
		}
		return $imported;
	}

	/** Entfernt alles zu einem Tag aus allen Tabellen. */
	private function delete(string $host, string $date): void
	{
		foreach (['days', 'counts', 'gaps', 'events', 'peers'] as $table) {
			$this->pdo->prepare("DELETE FROM $table WHERE host = ? AND date = ?")->execute([$host, $date]);
		}
	}

	/**
	 * 0640 und die Gruppe des Ordners: Der Benutzer des php-fpm-Pools liest über die
	 * Gruppe. Ohne feste Rechte entstünde die Datei mit der Maske des Dienstes.
	 */
	private function fixPermissions(): void
	{
		if (!is_file($this->path)) {
			return;
		}
		$group = filegroup(dirname($this->path));
		if ($group !== false && filegroup($this->path) !== $group) {
			@chgrp($this->path, $group);
		}
		if (((fileperms($this->path) ?: 0) & 07777) !== 0640) {
			@chmod($this->path, 0640);
		}
	}

	/**
	 * @param list<mixed> $params
	 * @return list<array<string, mixed>>
	 */
	private function all(string $sql, array $params): array
	{
		$statement = $this->pdo->prepare($sql);
		$statement->execute($params);
		return $statement->fetchAll();
	}

	/**
	 * @param list<mixed> $params
	 * @return ?array<string, mixed>
	 */
	private function one(string $sql, array $params): ?array
	{
		$rows = $this->all($sql, $params);
		return $rows[0] ?? null;
	}
}
