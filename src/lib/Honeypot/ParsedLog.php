<?php
declare(strict_types=1);

/**
 * Ergebnis der Zerlegung einer Logdatei: Einträge nach Kalendertag, plus die Zahl der
 * Zeilen, die sich nicht lesen liessen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 15:05
 */

namespace Honeypot;

final class ParsedLog
{
	/**
	 * @param array<string, list<LogEntry>> $days Datum (Y-m-d) => Einträge
	 * @param int $unreadable Zeilen, die nicht im Combined-Format standen
	 * @param array<string, int> $local Datum => übersprungene Aufrufe vom Rechner selbst
	 */
	public function __construct(
		public readonly array $days,
		public readonly int $unreadable,
		public readonly array $local = [],
	) {
	}
}
