<?php
declare(strict_types=1);

/**
 * Wertobjekt: ein gültiger, öffentlicher Domainname.
 *
 * Kleingeschrieben, höchstens 253 Zeichen, Labels nach RFC 1123, TLD nur
 * Buchstaben. localhost-Varianten sind ausgeschlossen, dafür gibt es Port.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 14:10
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
	 * Ist das eine Hauptdomain (z. B. example.com) und keine Unterdomain
	 * (z. B. shop.example.com)?
	 *
	 * Nur eine Hauptdomain hat sinnvollerweise eine www-Entsprechung: "www.example.com"
	 * ist üblich, "www.shop.example.com" nicht.
	 *
	 * Die Unterscheidung braucht eigentlich die Public Suffix List – ohne sie wäre
	 * "example.co.uk" fälschlich eine Unterdomain. Statt diese Liste (über 9000
	 * Einträge, wöchentlich gepflegt) mitzuschleppen, deckt SECOND_LEVEL die geläufigen
	 * zweiteiligen Endungen ab. Ein Irrtum kostet hier nichts Ernstes: Der Schalter
	 * wird dann nicht angeboten, die Domain funktioniert unverändert.
	 */
	public static function isMainDomain(string $name): bool
	{
		$labels = explode('.', strtolower(trim($name)));
		$count = count($labels);
		if ($count < 2) {
			return false;
		}
		$secondLevel = $count >= 3 ? $labels[$count - 2] : '';
		$suffixLabels = in_array($secondLevel, self::SECOND_LEVEL, true) ? 3 : 2;
		return $count === $suffixLabels;
	}

	/**
	 * Zweite Ebene geläufiger zweiteiliger Endungen: example.co.uk, example.com.br,
	 * example.ac.at. Keine vollständige Liste, siehe isMainDomain().
	 */
	private const SECOND_LEVEL = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'mil', 'sch', 'or', 'ne', 'go'];

	/**
	 * Der validierte Wert als Zeichenkette (z. B. für Konfigurationstexte).
	 */
	public function __toString(): string
	{
		return $this->value;
	}
}
