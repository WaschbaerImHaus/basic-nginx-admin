<?php
declare(strict_types=1);

/**
 * Wertobjekt: IPv4-/IPv6-Adresse mit optionaler Netzmaske für nginx "allow".
 *
 * Die Maske wird als Ganzzahl normalisiert (10.0.0.0/08 → 10.0.0.0/8).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:39
 */

namespace VhostAdmin\Value;

final class Cidr
{
	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Erzeugt die Adresse (mit optionaler Maske) aus einer Eingabe.
	 *
	 * @throws \InvalidArgumentException bei ungültiger Adresse oder Maske
	 */
	public static function fromString(string $raw): self
	{
		$raw = trim($raw);
		$parts = explode('/', $raw);
		if (count($parts) > 2) {
			throw new \InvalidArgumentException("Ungültige IP/CIDR: $raw");
		}
		[$ip, $bits] = array_pad($parts, 2, null);
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$max = 32;
		} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$max = 128;
		} else {
			throw new \InvalidArgumentException("Ungültige IP/CIDR: $raw");
		}
		if ($bits === null) {
			return new self($ip);
		}
		if ($bits === '' || !ctype_digit($bits) || (int)$bits > $max) {
			throw new \InvalidArgumentException("Ungültige Netzmaske: $raw");
		}
		return new self($ip . '/' . (int)$bits);
	}

	/**
	 * Der validierte Wert als Zeichenkette (z. B. für Konfigurationstexte).
	 */
	public function __toString(): string
	{
		return $this->value;
	}
}
