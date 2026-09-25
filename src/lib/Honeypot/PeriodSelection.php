<?php
declare(strict_types=1);

/**
 * Welchen Zeitraum die Ansicht zeigt – aus den Parametern der Adresszeile.
 *
 * Drei Wege, vom genauesten zum gröbsten: „von"/„bis" (ein Bereich), „tag" (ein Tag
 * aus dem Kalender), „zeitraum" (Schnellwahl wie „letzte 7 Tage"). Alles davon ist
 * Fremdeingabe: Ein ungültiger Wert führt zur Voreinstellung, nie zu einem Fehler.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 22:55
 */

namespace Honeypot;

final class PeriodSelection
{
	/** Schnellwahl: Name in der Adresszeile => Beschriftung. */
	public const PRESETS = [
		'heute' => 'heute',
		'gestern' => 'gestern',
		'7' => '7 Tage',
		'30' => '30 Tage',
		'monat' => 'dieser Monat',
	];

	/**
	 * @param string $preset Name der Schnellwahl, leer bei festem Tag oder Bereich
	 */
	private function __construct(public readonly Period $period, public readonly string $preset)
	{
	}

	/**
	 * @param array<string, mixed> $query Parameter der Adresszeile ($_GET)
	 * @param ?array{0: string, 1: string} $bounds erster und letzter Tag mit Daten
	 * @param string $today heutiges Datum JJJJ-MM-TT
	 */
	public static function fromQuery(array $query, ?array $bounds, string $today): self
	{
		$from = self::text($query, 'von');
		$to = self::text($query, 'bis');
		if ($from !== '' || $to !== '') {
			$period = self::attempt(static fn(): Period => Period::between($from !== '' ? $from : $to, $to !== '' ? $to : $from));
			if ($period !== null) {
				return new self($period, '');
			}
		}
		$day = self::text($query, 'tag');
		if ($day !== '') {
			$period = self::attempt(static fn(): Period => Period::day($day));
			if ($period !== null) {
				return new self($period, '');
			}
		}
		$preset = self::text($query, 'zeitraum');
		if (isset(self::PRESETS[$preset])) {
			$period = self::attempt(static fn(): ?Period => Period::preset($preset, $today));
			if ($period !== null) {
				return new self($period, $preset);
			}
		}
		// Voreinstellung: der jüngste Tag mit Daten. „Heute" ist bis zur ersten
		// Auswertung des Tages leer und wäre ein schlechter Einstieg.
		return new self(Period::day($bounds[1] ?? $today), '');
	}

	/**
	 * Die Parameter, die genau diesen Zeitraum wieder herstellen – für Verweise
	 * innerhalb der Ansicht. Die Schnellwahl bleibt als Name stehen, damit sie
	 * relativ bleibt: Ein Lesezeichen auf „7 Tage" zeigt morgen die sieben Tage bis morgen.
	 *
	 * @return array<string, string>
	 */
	public function query(): array
	{
		if ($this->preset !== '') {
			return ['zeitraum' => $this->preset];
		}
		if ($this->period->isSingleDay()) {
			return ['tag' => $this->period->from];
		}
		return ['von' => $this->period->from, 'bis' => $this->period->to];
	}

	/** Ein Parameter als Zeichenkette; Felder (tag[]=…) zählen als nicht angegeben. */
	private static function text(array $query, string $name): string
	{
		$value = $query[$name] ?? '';
		return is_string($value) ? $value : '';
	}

	/** Baut einen Zeitraum oder liefert null, wenn die Eingabe ungültig ist. */
	private static function attempt(callable $build): ?Period
	{
		try {
			return $build();
		} catch (\InvalidArgumentException) {
			return null;
		}
	}
}
