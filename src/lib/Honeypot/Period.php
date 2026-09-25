<?php
declare(strict_types=1);

/**
 * Wertobjekt: ein Auswertungszeitraum von Tag bis Tag, beide eingeschlossen.
 *
 * Die Grenzen kommen in der Ansicht aus der Adresszeile. Deshalb nur echte
 * Kalenderdaten im Format JJJJ-MM-TT: „2026-02-30" darf nicht still zum 2. März werden,
 * und ein Zeitraum über Jahrzehnte würde eine teure Anfrage an die Datenbank, ohne dass
 * dort Daten liegen könnten.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

namespace Honeypot;

final class Period
{
	/** Längster zulässiger Zeitraum in Tagen – gut drei Jahre. */
	public const MAX_DAYS = 1200;

	private function __construct(public readonly string $from, public readonly string $to)
	{
	}

	/**
	 * Ein einzelner Tag.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Datum
	 */
	public static function day(string $date): self
	{
		$date = self::checked($date);
		return new self($date, $date);
	}

	/**
	 * Von Tag bis Tag; vertauschte Grenzen werden getauscht.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Datum oder zu langem Zeitraum
	 */
	public static function between(string $from, string $to): self
	{
		$from = self::checked($from);
		$to = self::checked($to);
		if ($from > $to) {
			[$from, $to] = [$to, $from];
		}
		$period = new self($from, $to);
		if ($period->length() > self::MAX_DAYS) {
			throw new \InvalidArgumentException('Zeitraum zu lang (höchstens ' . self::MAX_DAYS . ' Tage).');
		}
		return $period;
	}

	/**
	 * Vorgefertigte Zeiträume, gerechnet bis einschliesslich $today.
	 */
	public static function preset(string $name, string $today): ?self
	{
		$end = self::date($today);
		return match ($name) {
			'heute' => self::day($today),
			'gestern' => self::day($end->modify('-1 day')->format('Y-m-d')),
			'7' => self::between($end->modify('-6 days')->format('Y-m-d'), $today),
			'30' => self::between($end->modify('-29 days')->format('Y-m-d'), $today),
			'monat' => self::between($end->format('Y-m-01'), $today),
			default => null,
		};
	}

	public function isSingleDay(): bool
	{
		return $this->from === $this->to;
	}

	/** Zahl der Tage, beide Grenzen eingeschlossen. */
	public function length(): int
	{
		return (int)self::date($this->from)->diff(self::date($this->to))->days + 1;
	}

	/**
	 * Der gleich lange Zeitraum direkt davor – der faire Vergleich: eine Woche gegen
	 * die Woche davor, nicht gegen einen einzelnen Tag.
	 */
	public function previous(): self
	{
		$length = $this->length();
		$to = self::date($this->from)->modify('-1 day');
		$from = $to->modify('-' . ($length - 1) . ' days');
		return new self($from->format('Y-m-d'), $to->format('Y-m-d'));
	}

	/** Die $count Tage unmittelbar vor diesem Zeitraum. */
	public function before(int $count): self
	{
		$to = self::date($this->from)->modify('-1 day');
		return new self($to->modify('-' . max(0, $count - 1) . ' days')->format('Y-m-d'), $to->format('Y-m-d'));
	}

	public function contains(string $date): bool
	{
		return $date >= $this->from && $date <= $this->to;
	}

	/**
	 * Alle Tage des Zeitraums, aufsteigend.
	 *
	 * @return list<string>
	 */
	public function days(): array
	{
		$days = [];
		for ($day = self::date($this->from); $day->format('Y-m-d') <= $this->to; $day = $day->modify('+1 day')) {
			$days[] = $day->format('Y-m-d');
		}
		return $days;
	}

	/** Zur Anzeige: „25.09.2026" oder „01.09. – 25.09.2026". */
	public function label(): string
	{
		$from = self::date($this->from);
		$to = self::date($this->to);
		if ($this->isSingleDay()) {
			return $to->format('d.m.Y');
		}
		$start = $from->format('Y') === $to->format('Y') ? $from->format('d.m.') : $from->format('d.m.Y');
		return $start . ' – ' . $to->format('d.m.Y');
	}

	/**
	 * Prüft ein Datum streng: Format UND Kalender. DateTime allein würde "2026-02-30"
	 * klaglos in den 2. März umrechnen.
	 */
	private static function checked(string $date): string
	{
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException("Kein gültiges Datum: $date");
		}
		return $date;
	}

	private static function date(string $date): \DateTimeImmutable
	{
		return new \DateTimeImmutable(self::checked($date) . ' 12:00:00', new \DateTimeZone('UTC'));
	}
}
