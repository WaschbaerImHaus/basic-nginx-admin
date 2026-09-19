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
		'gzip', 'gzip_types', 'gzip_min_length', 'gzip_comp_level', 'gzip_proxied',
		'gzip_vary', 'gzip_disable', 'gzip_buffers', 'gzip_http_version', 'gzip_static',
	];

	/**
	 * Präfixe, unter denen alle Direktiven erlaubt sind.
	 * proxy_* umfasst etwa 60 legitime Direktiven, eröffnet keinen Ausbruchsweg
	 * und unbekannte Namen lehnt nginx -t ohnehin ab.
	 */
	private const ALLOWED_PREFIXES = ['proxy_'];

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
	 * Tokenizer: Durchläuft den Text zeichenweise, respektiert Anführungszeichen,
	 * Kommentare und Escapes, und validiert jede Anweisung auf Direktivenliste,
	 * Klammern und Verschachtelungstiefe.
	 *
	 * @throws \InvalidArgumentException mit Zeilennummer und beanstandeter Direktive
	 */
	public static function fromString(string $text): self
	{
		if (strlen($text) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Das Snippet ist größer als 64 KB.');
		}

		$len = strlen($text);
		$pos = 0;
		$line = 1;
		$depth = 0;
		$token = '';
		$tokenLine = 1;
		$inQuote = null;
		$escaped = false;
		$tokenHasContent = false;

		while ($pos < $len) {
			$char = $text[$pos];

			// Zeichenweise durchgehen; Zeilennummern mitlaufen
			if ($char === "\n") {
				$line++;
			}

			// Innerhalb von Anführungszeichen: nur Escapes beachten
			if ($inQuote !== null) {
				$token .= $char;
				$tokenHasContent = true;
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === $inQuote) {
					$inQuote = null;
				}
				$pos++;
				continue;
			}

			// Außerhalb von Anführungszeichen
			if ($char === '"' || $char === "'") {
				$inQuote = $char;
				$token .= $char;
				$tokenHasContent = true;
				$pos++;
				continue;
			}

			// Kommentar: ab # bis zum Zeilenende (nur außerhalb von Anführungszeichen)
			if ($char === '#') {
				while ($pos < $len && $text[$pos] !== "\n") {
					$pos++;
				}
				continue;
			}

			// Anweisung beenden bei Semikolon oder Block-Öffner/Schließer
			if ($char === ';' || $char === '{' || $char === '}') {
				$token = trim($token);

				if ($char === '}') {
					// Schließende Klammer: zuerst prüfen, ob noch Token offen ist
					if ($token !== '') {
						throw new \InvalidArgumentException(
							"Zeile $tokenLine: unvollständige Anweisung vor schließender Klammer (fehlendes Semikolon?)."
						);
					}
					// Tiefe verringern und prüfen
					if ($depth === 0) {
						throw new \InvalidArgumentException(
							"Zeile $line: schließende Klammer ohne geöffneten Block – das würde den server-Block verlassen."
						);
					}
					$depth--;
				} else {
					// Semikolon oder Block-Öffner: Token prüfen
					if ($token !== '') {
						$directive = strtolower((string)(preg_split('/[\s]/', $token, 2)[0] ?? ''));
						if ($directive !== '') {
							if ($char === '{') {
								// Block-Direktive
								if ($directive !== self::BLOCK_DIRECTIVE) {
									throw new \InvalidArgumentException(
										"Zeile $tokenLine: nur \"location\" darf einen Block öffnen, nicht \"$directive\"."
									);
								}
								$depth++;
								if ($depth > self::MAX_DEPTH) {
									throw new \InvalidArgumentException(
										"Zeile $tokenLine: Blöcke dürfen höchstens " . self::MAX_DEPTH . " Ebenen tief verschachtelt sein."
									);
								}
							} else {
								// Normale Direktive (Semikolon)
								if (!self::isAllowed($directive)) {
									throw new \InvalidArgumentException("Zeile $tokenLine: Direktive \"$directive\" ist nicht erlaubt.");
								}
							}
						}
					} elseif ($char === ';') {
						// Leeres Semikolon (nur Leerraum voraus) – das ist akzeptabel
					} elseif ($char === '{' && $token === '') {
						// Öffnende Klammer ohne Direktive: Fehler
						throw new \InvalidArgumentException(
							"Zeile $line: öffnende Klammer ohne Direktive (nur \"location\" ist erlaubt)."
						);
					}
				}
				$token = '';
				$tokenHasContent = false;
			} else {
				// Token-Text sammeln; Zeile des Token-Anfangs merken (nur bei echtem Inhalt)
				if (!$tokenHasContent && $char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
					$tokenLine = $line;
					$tokenHasContent = true;
				}
				$token .= $char;
			}

			$pos++;
		}

		// Am Ende: offene Anführungszeichen prüfen
		if ($inQuote !== null) {
			throw new \InvalidArgumentException(
				"Zeile $tokenLine: offenes Anführungszeichen, nicht geschlossen."
			);
		}

		// Unabgeschlossene Anweisung (kein abschließendes Semikolon)
		if (trim($token) !== '') {
			throw new \InvalidArgumentException(
				"Zeile $tokenLine: unvollständige Anweisung – fehlendes Semikolon."
			);
		}

		// Offene Blöcke
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
