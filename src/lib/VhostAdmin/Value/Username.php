<?php
declare(strict_types=1);

/**
 * Wertobjekt: Benutzername für den Verzeichnisschutz (htpasswd).
 *
 * Kein Doppelpunkt (Trenner in htpasswd), keine Leerzeichen, 1–64 Zeichen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:39
 */

namespace VhostAdmin\Value;

final class Username
{
	private const PATTERN = '/^[A-Za-z0-9_.@-]{1,64}$/D';

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Erzeugt den Benutzernamen aus einer Eingabe.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Namen
	 */
	public static function fromString(string $raw): self
	{
		if (preg_match(self::PATTERN, $raw) !== 1) {
			throw new \InvalidArgumentException("Ungültiger Benutzername: $raw");
		}
		return new self($raw);
	}

	/**
	 * Der validierte Wert als Zeichenkette (z. B. für Konfigurationstexte).
	 */
	public function __toString(): string
	{
		return $this->value;
	}
}
