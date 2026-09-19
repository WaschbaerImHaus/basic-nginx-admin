<?php
declare(strict_types=1);

/**
 * Wertobjekt: das über die Oberfläche gepflegte nginx-Snippet eines vHosts.
 *
 * Geprüft wird gegen eine Positivliste: Unbekannte Direktiven gelten als verboten.
 * Wichtigste Prüfung ist die Klammerbilanz – ohne sie könnte eine schließende
 * Klammer den umgebenden server-Block beenden und danach ein eigener server-Block
 * mit beliebigen Direktiven folgen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 09:28
 */

namespace VhostAdmin\Value;

final class NginxSnippet
{
	/** Direktiven, die unverändert erlaubt sind. */
	private const ALLOWED = [
		'client_max_body_size', 'expires', 'add_header', 'more_set_headers', 'charset',
		'autoindex', 'index', 'error_page', 'rewrite', 'return', 'try_files',
		'limit_rate', 'limit_rate_after',
	];

	/** Präfixe, unter denen alle Direktiven erlaubt sind. */
	private const ALLOWED_PREFIXES = ['gzip', 'proxy_'];

	/** Einzige Direktive, die einen Block öffnen darf. */
	private const BLOCK_DIRECTIVE = 'location';

	private const MAX_BYTES = 65536;
	private const MAX_DEPTH = 2;

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Prüft den Text und erzeugt das Wertobjekt.
	 *
	 * @throws \InvalidArgumentException mit Zeilennummer und beanstandeter Direktive
	 */
	public static function fromString(string $text): self
	{
		if (strlen($text) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Das Snippet ist größer als 64 KB.');
		}
		$depth = 0;
		$lines = preg_split('/\R/', $text) ?: [];
		foreach ($lines as $index => $line) {
			$number = $index + 1;
			$trimmed = trim($line);
			if ($trimmed === '' || str_starts_with($trimmed, '#')) {
				continue;
			}
			if (str_starts_with($trimmed, '}')) {
				if ($depth === 0) {
					throw new \InvalidArgumentException(
						"Zeile $number: schließende Klammer ohne geöffneten Block – das würde den server-Block verlassen."
					);
				}
				$depth--;
				$trimmed = trim(substr($trimmed, 1));
				if ($trimmed === '') {
					continue;
				}
			}
			$directive = strtolower((string)(preg_split('/[\s;{]/', $trimmed, 2)[0] ?? ''));
			if ($directive === '') {
				continue;
			}
			$opensBlock = str_ends_with($trimmed, '{');
			if ($opensBlock) {
				if ($directive !== self::BLOCK_DIRECTIVE) {
					throw new \InvalidArgumentException(
						"Zeile $number: nur \"location\" darf einen Block öffnen, nicht \"$directive\"."
					);
				}
				$depth++;
				if ($depth > self::MAX_DEPTH) {
					throw new \InvalidArgumentException(
						"Zeile $number: Blöcke dürfen höchstens " . self::MAX_DEPTH . " Ebenen tief verschachtelt sein."
					);
				}
				continue;
			}
			if (!self::isAllowed($directive)) {
				throw new \InvalidArgumentException("Zeile $number: Direktive \"$directive\" ist nicht erlaubt.");
			}
		}
		if ($depth !== 0) {
			throw new \InvalidArgumentException('Es wurde ein Block geöffnet, aber nicht geschlossen.');
		}
		return new self($text);
	}

	/**
	 * Enthält das Snippet außer Leerraum nichts?
	 */
	public function isEmpty(): bool
	{
		return trim($this->value) === '';
	}

	/**
	 * Der geprüfte Text, wie er in die Datei geschrieben wird.
	 */
	public function __toString(): string
	{
		return $this->value;
	}

	/**
	 * Steht die Direktive auf der Positivliste oder unter einem erlaubten Präfix?
	 */
	private static function isAllowed(string $directive): bool
	{
		if (in_array($directive, self::ALLOWED, true)) {
			return true;
		}
		foreach (self::ALLOWED_PREFIXES as $prefix) {
			if (str_starts_with($directive, $prefix)) {
				return true;
			}
		}
		return false;
	}
}
