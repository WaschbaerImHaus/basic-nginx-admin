<?php
declare(strict_types=1);

/**
 * Wertobjekt: ein gültiger, öffentlicher Domainname.
 *
 * Kleingeschrieben, höchstens 253 Zeichen, Labels nach RFC 1123, TLD nur
 * Buchstaben. localhost-Varianten sind ausgeschlossen, dafür gibt es Port.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:39
 */

namespace VhostAdmin\Value;

final class DomainName
{
	private const PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D';

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Erzeugt den Domainnamen aus einer Eingabe.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Namen
	 */
	public static function fromString(string $raw): self
	{
		$name = strtolower(trim($raw));
		if ($name === 'localhost' || str_starts_with($name, 'localhost:') || str_ends_with($name, '.localhost')) {
			throw new \InvalidArgumentException('localhost-Hosts nur per "vhost add-local <port>"');
		}
		if (strlen($name) > 253 || preg_match(self::PATTERN, $name) !== 1) {
			throw new \InvalidArgumentException("Ungültiger Domainname: $raw");
		}
		return new self($name);
	}

	/**
	 * Der validierte Wert als Zeichenkette (z. B. für Konfigurationstexte).
	 */
	public function __toString(): string
	{
		return $this->value;
	}
}
