<?php
declare(strict_types=1);

/**
 * Wandelt die Statistikdateien der regionalen Registries (RIR) in die Nachschlagetabelle
 * für NetworkRegistry um.
 *
 * Quellformat je Zeile: registry|cc|type|start|value|date|status[|weitere]
 *
 * Die eine Falle des Formats: Bei `ipv4` ist `value` die **Anzahl der Adressen**, bei
 * `ipv6` die **Präfixlänge**. Wer beides gleich liest, macht aus einem /32 einen Block
 * von 32 Adressen – die Zuordnung wäre dann fast immer leer und der Fehler fiele nicht
 * auf, weil „nichts gefunden" wie ein normales Ergebnis aussieht.
 *
 * Zielformat je Zeile: <start-hex32> <ende-hex32> <land> <registry> <netz>
 * Adressen als 32 Hexzeichen, IPv4 als IPv4-mapped IPv6 – damit liegen beide Familien
 * in einer Tabelle und der Vergleich bleibt ein Zeichenkettenvergleich (128 Bit passen
 * in keine PHP-Ganzzahl).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 09:10
 */

namespace Honeypot;

final class RirTable
{
	/** Nur vergebene Blöcke haben einen Inhaber; freie und reservierte nicht. */
	private const USABLE_STATUS = ['allocated', 'assigned'];

	/**
	 * Wandelt eine Quellzeile um; null, wenn sie keinen vergebenen Adressblock beschreibt.
	 */
	public function convert(string $line): ?string
	{
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			return null;
		}
		$parts = explode('|', $line);
		// Kopfzeile ("2|ripencc|...") hat an Position 2 keine Blockart.
		if (count($parts) < 7) {
			return null;
		}
		[$registry, $country, $type, $start, $value, , $status] = $parts;
		if (!in_array($status, self::USABLE_STATUS, true)) {
			return null;
		}
		// Summenzeilen tragen "*" als Land und als Startadresse; ohne Land gibt es
		// keinen Inhaber, den man nennen könnte.
		if ($country === '' || $country === '*' || $start === '*') {
			return null;
		}

		$range = match ($type) {
			'ipv4' => $this->ipv4Range($start, (int)$value),
			'ipv6' => $this->ipv6Range($start, (int)$value),
			default => null,
		};
		if ($range === null) {
			return null;
		}
		[$first, $last, $network] = $range;
		return sprintf("%s %s %s %s %s\n", bin2hex($first), bin2hex($last), $country, $registry, $network);
	}

	/**
	 * Wandelt alle Zeilen um und übergibt jede Ausgabezeile an $write.
	 *
	 * @param iterable<string> $lines
	 * @param callable(string): void $write
	 * @return int Zahl der übernommenen Blöcke
	 */
	public function convertAll(iterable $lines, callable $write): int
	{
		$used = 0;
		foreach ($lines as $line) {
			$converted = $this->convert($line);
			if ($converted !== null) {
				$write($converted);
				$used++;
			}
		}
		return $used;
	}

	/**
	 * Anfang, Ende und Bezeichnung eines IPv4-Blocks aus Startadresse und Anzahl.
	 *
	 * @return ?array{string, string, string}
	 */
	private function ipv4Range(string $start, int $count): ?array
	{
		$first = ip2long($start);
		if ($first === false || $count < 1 || $first + $count - 1 > 0xFFFFFFFF) {
			return null;
		}
		$lastLong = $first + $count - 1;
		// Ein CIDR gibt es nur, wenn die Anzahl eine Zweierpotenz ist UND der Anfang
		// darauf ausgerichtet ist. Nicht jeder vergebene Block erfüllt das; dann ist
		// der Bereich die einzige richtige Angabe.
		$isCidr = ($count & ($count - 1)) === 0 && ($first % $count) === 0;
		$network = $isCidr
			? $start . '/' . (32 - (int)round(log($count, 2)))
			: $start . '–' . long2ip($lastLong);
		return [
			self::mapped((string)inet_pton($start)),
			self::mapped((string)inet_pton(long2ip($lastLong))),
			$network,
		];
	}

	/**
	 * Anfang, Ende und Bezeichnung eines IPv6-Blocks aus Startadresse und Präfixlänge.
	 *
	 * Das Ende entsteht bitweise, nicht über die Hexstellen: Eine Präfixlänge wie /35
	 * liegt nicht auf einer Vierergrenze, und ein Ende „bis zur nächsten Hexstelle"
	 * läge um den Faktor acht daneben.
	 *
	 * @return ?array{string, string, string}
	 */
	private function ipv6Range(string $start, int $prefix): ?array
	{
		$packed = @inet_pton($start);
		if ($packed === false || strlen($packed) !== 16 || $prefix < 1 || $prefix > 128) {
			return null;
		}
		$last = $packed;
		for ($bit = $prefix; $bit < 128; $bit++) {
			$index = intdiv($bit, 8);
			$last[$index] = chr(ord($last[$index]) | (1 << (7 - $bit % 8)));
		}
		return [$packed, $last, $start . '/' . $prefix];
	}

	/**
	 * Vier Bytes IPv4 als 16 Bytes IPv4-mapped IPv6; IPv6 bleibt unverändert.
	 */
	private static function mapped(string $packed): string
	{
		return strlen($packed) === 4 ? str_repeat("\0", 10) . "\xff\xff" . $packed : $packed;
	}
}
