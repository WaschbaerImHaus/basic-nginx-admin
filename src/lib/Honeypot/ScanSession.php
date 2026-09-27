<?php
declare(strict_types=1);

/**
 * Eine Scanner-Sitzung: zusammenhängende Anfragen eines Werkzeugs an einem Tag.
 *
 * Ohne Client-Adresse (alles kommt als 10.200.0.1) ist das die kleinste Einheit, die
 * sich einem Werkzeug zuordnen lässt: gleiche Kennung (oder gleiche Gruppe
 * durchgewechselter Kennungen), keine Pause über 30 Minuten. Der Fingerabdruck ist die
 * Menge der gesuchten Pfade – die wechselt ein Werkzeug viel seltener als seine Kennung.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:40
 */

namespace Honeypot;

final class ScanSession
{
	/**
	 * @param list<string> $agents Kennungen, sortiert
	 * @param list<string> $paths  gesuchte Pfade ohne Abfrage, einmalig, sortiert
	 */
	public function __construct(
		public readonly string $date,
		public readonly string $start,
		public readonly string $end,
		public readonly int $requests,
		public readonly array $agents,
		public readonly array $paths,
	) {
	}

	/** Minute des Tages, zu der die Sitzung begann (03:10 => 190). */
	public function startMinute(): int
	{
		return (int)substr($this->start, 0, 2) * 60 + (int)substr($this->start, 3, 2);
	}

	/** @return array{date: string, start: string, end: string, requests: int, agents: list<string>, paths: list<string>} */
	public function toArray(): array
	{
		return [
			'date' => $this->date, 'start' => $this->start, 'end' => $this->end,
			'requests' => $this->requests, 'agents' => $this->agents, 'paths' => $this->paths,
		];
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self
	{
		return new self(
			(string)($data['date'] ?? ''),
			(string)($data['start'] ?? ''),
			(string)($data['end'] ?? ''),
			(int)($data['requests'] ?? 0),
			array_values(array_map(strval(...), (array)($data['agents'] ?? []))),
			array_values(array_map(strval(...), (array)($data['paths'] ?? []))),
		);
	}
}
