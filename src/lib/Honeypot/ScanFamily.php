<?php
declare(strict_types=1);

/**
 * Eine Scanner-Familie: Sitzungen mit fast gleicher Pfadliste – mit hoher
 * Wahrscheinlichkeit dasselbe Werkzeug, auch an verschiedenen Tagen und mit
 * wechselnder Kennung.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:40
 */

namespace Honeypot;

final class ScanFamily
{
	/** Streuung der Startzeiten, bis zu der von „meist gegen" die Rede ist (Minuten). */
	private const REGULAR_SPREAD = 60;

	/** @param list<ScanSession> $sessions nach Beginn sortiert */
	public function __construct(public readonly array $sessions)
	{
	}

	/** @return list<string> */
	public function dates(): array
	{
		$dates = array_values(array_unique(array_map(static fn(ScanSession $s): string => $s->date, $this->sessions)));
		sort($dates);
		return $dates;
	}

	/** Kam die Familie an mehr als einem Tag? */
	public function recurring(): bool
	{
		return count($this->dates()) > 1;
	}

	/** @return list<string> alle benutzten Kennungen, sortiert */
	public function agents(): array
	{
		$agents = array_values(array_unique(array_merge(...array_map(static fn(ScanSession $s): array => $s->agents, $this->sessions))));
		sort($agents);
		return $agents;
	}

	public function requests(): int
	{
		return array_sum(array_map(static fn(ScanSession $s): int => $s->requests, $this->sessions));
	}

	/**
	 * Kern der Familie: Pfade, die in mindestens der Hälfte ihrer Sitzungen vorkamen.
	 *
	 * @return list<string>
	 */
	public function commonPaths(): array
	{
		$seen = [];
		foreach ($this->sessions as $session) {
			foreach ($session->paths as $path) {
				$seen[$path] = ($seen[$path] ?? 0) + 1;
			}
		}
		$half = count($this->sessions) / 2;
		$common = array_keys(array_filter($seen, static fn(int $n): bool => $n >= $half));
		$common = array_map(strval(...), $common);
		sort($common);
		return $common;
	}

	/**
	 * Wann die Familie kommt: „einmal um 03:00", „meist gegen 03:10" (Startzeiten liegen
	 * höchstens eine Stunde um die mittlere Zeit) oder „zu wechselnden Zeiten".
	 *
	 * Gemittelt wird auf dem Zifferblatt (Kreismittel), sonst läge das Mittel von 23:55
	 * und 00:05 bei zwölf Uhr mittags.
	 */
	public function timePattern(): string
	{
		$minutes = array_map(static fn(ScanSession $s): int => $s->startMinute(), $this->sessions);
		if (count($minutes) === 1) {
			return 'einmal um ' . self::clock($minutes[0]);
		}
		$x = $y = 0.0;
		foreach ($minutes as $minute) {
			$angle = $minute / 1440 * 2 * M_PI;
			$x += cos($angle);
			$y += sin($angle);
		}
		$mean = (int)round(fmod(atan2($y, $x) / (2 * M_PI) * 1440 + 1440, 1440)) % 1440;
		foreach ($minutes as $minute) {
			$distance = abs($minute - $mean);
			if (min($distance, 1440 - $distance) > self::REGULAR_SPREAD) {
				return 'zu wechselnden Zeiten';
			}
		}
		return 'meist gegen ' . self::clock($mean);
	}

	private static function clock(int $minute): string
	{
		return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
	}
}
