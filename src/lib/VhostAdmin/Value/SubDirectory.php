<?php
declare(strict_types=1);

/**
 * Wertobjekt: optionales Unterverzeichnis unterhalb des Basisordners eines vHosts.
 *
 * Jedes Segment beginnt mit Buchstabe, Ziffer oder Unterstrich; damit sind
 * ".", ".." und versteckte Verzeichnisse ausgeschlossen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:33
 */

namespace VhostAdmin\Value;

final class SubDirectory
{
	private const PATTERN = '~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~D';

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Erzeugt das Unterverzeichnis; leer oder nur Schrägstriche ergibt null.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Pfad
	 */
	public static function fromString(?string $raw): ?self
	{
		$path = trim((string)$raw, "/ \t");
		if ($path === '') {
			return null;
		}
		if (preg_match(self::PATTERN, $path) !== 1) {
			throw new \InvalidArgumentException("Ungültiges Unterverzeichnis: $raw");
		}
		return new self($path);
	}

	/**
	 * Die einzelnen Pfadsegmente in Reihenfolge.
	 *
	 * @return list<string>
	 */
	public function segments(): array
	{
		return explode('/', $this->value);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
