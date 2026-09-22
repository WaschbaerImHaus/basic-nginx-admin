<?php
declare(strict_types=1);

/**
 * Kennzahlen eines Kalendertages für einen Honigtopf-vHost.
 *
 * Der Bericht ist zugleich das Austauschformat: Die tägliche Auswertung läuft als root
 * (nur root darf die Logs lesen) und legt ihn als JSON ab; die Ansicht liest
 * ausschliesslich diese Fassung. Was hier nicht hineinkommt, ist dort für immer weg.
 *
 * Es gibt bewusst keine Kennzahl auf Basis der Client-Adresse: Vor diesem Rechner sitzt
 * eine Adressumsetzung, jede Anfrage von aussen erscheint als 10.200.0.1.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:20
 */

namespace Honeypot;

final class DayReport implements \JsonSerializable
{
	/**
	 * Obergrenze der Ereignisliste. Ein Tag bringt hier einige hundert auffällige
	 * Anfragen; die Grenze schützt vor einem Scan, der die Datei aufbläht.
	 */
	public const EVENT_LIMIT = 5000;

	/** Ab so vielen Anfragen gilt eine gleiche Häufigkeit als Muster, nicht als Zufall. */
	private const ROTATION_THRESHOLD = 5;

	/**
	 * @param array<string|int, int> $status    Antwortcode => Anzahl
	 * @param array<string, int>     $hours     Stunde (00–23) => Anzahl
	 * @param array<string, int>     $agents    Kennung => Anzahl, absteigend
	 * @param array<int, list<string>> $rotating Häufigkeit => Kennungen gleicher Häufigkeit
	 * @param array<string, int>     $notFound  Pfad => Anzahl, absteigend
	 * @param array<string, int>     $loot      Beutegruppe => Anzahl
	 * @param array<string, int>     $methods   Verb => Anzahl
	 * @param array<string, int>     $probeKinds Art der Dienstsondierung => Anzahl
	 * @param array<string, int>     $probes    Rohtext der Sondierung => Anzahl
	 * @param list<int>              $gaps      Sekunden zwischen robots.txt und /admin
	 * @param array<string, int>     $logins    Benutzername => Fehlversuche
	 * @param list<array<string, string>> $events auffällige Anfragen für die Detailansicht
	 */
	public function __construct(
		public readonly string $date,
		public readonly bool $complete,
		public readonly int $requests,
		public readonly array $status,
		public readonly array $hours,
		public readonly array $agents,
		public readonly array $rotating,
		public readonly array $notFound,
		public readonly array $loot,
		public readonly array $methods,
		public readonly int $probeCount,
		public readonly array $probeKinds,
		public readonly array $probes,
		public readonly int $robots,
		public readonly int $admin,
		public readonly int $robotsThenAdmin,
		public readonly array $gaps,
		public readonly array $logins,
		public readonly int $unreadable,
		public readonly array $events,
	) {
	}

	/**
	 * Rechnet die Kennzahlen eines Tages aus.
	 *
	 * @param list<LogEntry>     $entries    Einträge genau dieses Kalendertages
	 * @param int                $unreadable nicht lesbare Zeilen
	 * @param array<string, int> $logins     Fehlversuche aus dem error.log
	 * @param bool               $complete   ist der Tag abgeschlossen (rotierte Datei)?
	 */
	public static function fromEntries(
		string $date,
		array $entries,
		int $unreadable,
		array $logins,
		bool $complete
	): self {
		$classifier = new LootClassifier();
		$status = $methods = $agents = $notFound = $probeKinds = $probes = [];
		$hours = array_fill_keys(array_map(static fn(int $h): string => sprintf('%02d', $h), range(0, 23)), 0);
		$loot = array_fill_keys(array_keys(LootClassifier::GROUPS), 0);
		$probeCount = $robots = $admin = $robotsThenAdmin = 0;
		$gaps = $events = [];
		$lastRobots = null;

		foreach ($entries as $entry) {
			$status[$entry->status] = ($status[$entry->status] ?? 0) + 1;
			$hours[$entry->hour()] = ($hours[$entry->hour()] ?? 0) + 1;
			$agents[$entry->agent] = ($agents[$entry->agent] ?? 0) + 1;
			$methods[$entry->method] = ($methods[$entry->method] ?? 0) + 1;

			$kind = $entry->probeKind();
			if ($kind !== null) {
				$probeCount++;
				$probeKinds[$kind] = ($probeKinds[$kind] ?? 0) + 1;
				$raw = substr($entry->request, 0, 80);
				$probes[$raw] = ($probes[$raw] ?? 0) + 1;
			}

			$group = null;
			if ($entry->status === '404') {
				$notFound[$entry->path] = ($notFound[$entry->path] ?? 0) + 1;
				$group = $classifier->classify($entry->path);
				if ($group !== null) {
					$loot[$group]++;
				}
			}

			if ($entry->path === '/robots.txt') {
				$robots++;
				$lastRobots = $entry->timestamp;
			}
			// Der ausgeschlossene Pfad NACH dem Lesen der robots.txt ist die schärfste
			// Aussage dieser Seite: ein bewusster Verstoss. Ohne vorheriges Lesen ist es
			// blosses Raten. Der Abstand trennt „wertet aus" von „ruft alles ab".
			if (str_starts_with($entry->path, '/admin')) {
				$admin++;
				if ($lastRobots !== null) {
					$robotsThenAdmin++;
					$gaps[] = $entry->timestamp - $lastRobots;
				}
			}

			if ($entry->isNoteworthy() && count($events) < self::EVENT_LIMIT) {
				$events[] = [
					'time' => $entry->time,
					'method' => $entry->method,
					'path' => $entry->path,
					'request' => substr($entry->request, 0, 120),
					'status' => $entry->status,
					'agent' => $entry->agent,
					'group' => $group ?? '',
					'probe' => $kind ?? '',
				];
			}
		}

		arsort($agents);
		arsort($notFound);
		arsort($methods);
		arsort($probeKinds);
		arsort($probes);
		arsort($logins);

		return new self(
			$date, $complete, count($entries), $status, $hours, $agents,
			self::rotatingAgents($agents), $notFound, $loot, $methods,
			$probeCount, $probeKinds, $probes,
			$robots, $admin, $robotsThenAdmin, $gaps, $logins, $unreadable, $events
		);
	}

	/**
	 * Kennungen mit exakt gleicher Häufigkeit.
	 *
	 * Vier angebliche Browser mit je genau 95 Anfragen sind ein Werkzeug, das seine
	 * Kennung durchwechselt. Diese Erkennung braucht keine Liste bekannter Bots – und
	 * findet gerade die, die auf keiner Liste stehen.
	 *
	 * @param array<string, int> $agents
	 * @return array<int, list<string>>
	 */
	private static function rotatingAgents(array $agents): array
	{
		$byCount = [];
		foreach ($agents as $agent => $count) {
			if ($count < self::ROTATION_THRESHOLD) {
				continue;
			}
			$byCount[$count][] = (string)$agent;
		}
		return array_filter($byCount, static fn(array $list): bool => count($list) >= 2);
	}

	/**
	 * Sondierungen (404) dieses Tages – die Zahl, auf die es ankommt.
	 */
	public function probing(): int
	{
		return $this->status['404'] ?? 0;
	}

	/**
	 * Kennungen in drei Klassen, nach Verhalten statt nach einer Liste bekannter Bots.
	 *
	 * „tarnt sich" sind die Kennungen, die in einer Gruppe gleicher Häufigkeit stehen –
	 * vier angebliche Browser mit je genau 95 Anfragen sind ein Werkzeug, das
	 * durchwechselt. „nennt nichts" schickt gar keine Kennung. Alles andere nennt sich
	 * beim Namen, ob ehrlich (libredtail, Forschungsscanner) oder schlicht ungetarnt.
	 *
	 * @return array<string, int> Klasse => Anfragen
	 */
	public function agentClasses(): array
	{
		$disguised = [];
		foreach ($this->rotating as $list) {
			foreach ($list as $agent) {
				$disguised[$agent] = true;
			}
		}
		$out = ['nennt sich' => 0, 'tarnt sich' => 0, 'nennt nichts' => 0];
		foreach ($this->agents as $agent => $count) {
			$key = match (true) {
				$agent === '-' || trim((string)$agent) === '' => 'nennt nichts',
				isset($disguised[$agent]) => 'tarnt sich',
				default => 'nennt sich',
			};
			$out[$key] += $count;
		}
		return $out;
	}

	/**
	 * Welche Klasse gehört zu dieser Kennung?
	 */
	public function agentClass(string $agent): string
	{
		foreach ($this->rotating as $list) {
			if (in_array($agent, $list, true)) {
				return 'tarnt sich';
			}
		}
		return $agent === '-' || trim($agent) === '' ? 'nennt nichts' : 'nennt sich';
	}

	/**
	 * Kürzester gemessener Abstand robots.txt → /admin, null ohne Messung.
	 */
	public function shortestGap(): ?int
	{
		return $this->gaps === [] ? null : min($this->gaps);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return [
			'date' => $this->date,
			'complete' => $this->complete,
			'requests' => $this->requests,
			'status' => $this->status,
			'hours' => $this->hours,
			'agents' => $this->agents,
			'rotating' => $this->rotating,
			'notFound' => $this->notFound,
			'loot' => $this->loot,
			'methods' => $this->methods,
			'probeCount' => $this->probeCount,
			'probeKinds' => $this->probeKinds,
			'probes' => $this->probes,
			'robots' => $this->robots,
			'admin' => $this->admin,
			'robotsThenAdmin' => $this->robotsThenAdmin,
			'gaps' => $this->gaps,
			'logins' => $this->logins,
			'unreadable' => $this->unreadable,
			'events' => $this->events,
		];
	}

	/**
	 * Liest den Bericht aus seiner gespeicherten Fassung. Fehlende Felder bekommen einen
	 * leeren Wert – ein älterer Bericht soll die Ansicht nicht zerlegen.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		/** @var array<int, list<string>> $rotating */
		$rotating = [];
		foreach ((array)($data['rotating'] ?? []) as $count => $list) {
			$rotating[(int)$count] = array_values(array_map(strval(...), (array)$list));
		}
		return new self(
			(string)($data['date'] ?? ''),
			(bool)($data['complete'] ?? false),
			(int)($data['requests'] ?? 0),
			(array)($data['status'] ?? []),
			(array)($data['hours'] ?? []),
			(array)($data['agents'] ?? []),
			$rotating,
			(array)($data['notFound'] ?? []),
			(array)($data['loot'] ?? []),
			(array)($data['methods'] ?? []),
			(int)($data['probeCount'] ?? 0),
			(array)($data['probeKinds'] ?? []),
			(array)($data['probes'] ?? []),
			(int)($data['robots'] ?? 0),
			(int)($data['admin'] ?? 0),
			(int)($data['robotsThenAdmin'] ?? 0),
			array_values(array_map(intval(...), (array)($data['gaps'] ?? []))),
			(array)($data['logins'] ?? []),
			(int)($data['unreadable'] ?? 0),
			array_values((array)($data['events'] ?? [])),
		);
	}
}
