<?php
declare(strict_types=1);

/**
 * Wertobjekt: TCP-Port für einen localhost-Host.
 *
 * 1–65535; 80, 443 und der Port der Verwaltungsoberfläche sind reserviert.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:33
 */

namespace VhostAdmin\Value;

final class Port
{
	private function __construct(public readonly int $value)
	{
	}

	/**
	 * Erzeugt den Port aus einer Eingabe.
	 *
	 * @param int $adminPort Port der Oberfläche, der nicht vergeben werden darf
	 * @throws \InvalidArgumentException bei ungültigem oder reserviertem Port
	 */
	public static function fromString(string $raw, int $adminPort = 8080): self
	{
		$raw = trim($raw);
		if ($raw === '' || !ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 65535) {
			throw new \InvalidArgumentException("Ungültiger Port: $raw");
		}
		$port = (int)$raw;
		if (in_array($port, [80, 443, $adminPort], true)) {
			throw new \InvalidArgumentException("Port $port ist reserviert");
		}
		return new self($port);
	}

	public function __toString(): string
	{
		return (string)$this->value;
	}
}
