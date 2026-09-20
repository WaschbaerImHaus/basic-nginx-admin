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
 * Token-Anfang ("$atTokenStart"): nginx behandelt "#" (Kommentarbeginn) sowie '"'
 * und "'" (Zeichenkette) nur dann als Sonderzeichen, wenn sie am Anfang eines
 * Tokens stehen (Textbeginn, nach Leerraum oder nach ; { }). Mitten in einem Wort
 * sind es für nginx gewöhnliche Zeichen, und alles danach bleibt aktive
 * Konfiguration. Ein Tokenizer, der stattdessen jedes "#"/'"'/"'" immer als
 * Sonderzeichen behandelt (wie die vorige Fassung), kann dadurch getäuscht
 * werden: Er hält einen Teil des Textes für einen Kommentar bzw. eine
 * Zeichenkette und prüft ihn deshalb gar nicht gegen die Positivliste, während
 * nginx genau diesen Teil als eigene, unter Umständen verbotene Direktive
 * ausführt. Statt den nginx-Tokenizer bei "#"/'"'/"'" exakt nachzubilden (inkl.
 * Fortsetzung der Prüfung ab der echten Wortgrenze), wählt diese Klasse die
 * strengere, einfachere Variante: Jedes "#", '"' oder "'", das nicht am Anfang
 * eines Tokens steht, führt zur Ablehnung. Das lehnt einzelne, in der Praxis
 * seltene Direktivenwerte mit eingebettetem "#"/Anführungszeichen ohne
 * umschließende Wortgrenze ab, vermeidet dafür aber jedes Risiko einer erneut
 * abweichenden Nachbildung des nginx-Tokenizers.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 10:34
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
		// Stehen wir am Anfang eines Tokens (Textbeginn, nach Leerraum oder nach ; { })?
		// Nur dort dürfen "#", '"' und "'" ihre Sonderbedeutung entfalten (siehe Klassen-DocBlock).
		$atTokenStart = true;

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
				$atTokenStart = false;
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
				if (!$atTokenStart) {
					throw new \InvalidArgumentException(
						"Zeile $line: ein Anführungszeichen ist nur am Anfang eines Tokens erlaubt, nicht mitten im Wort."
					);
				}
				$inQuote = $char;
				$token .= $char;
				$tokenHasContent = true;
				$atTokenStart = false;
				$pos++;
				continue;
			}

			// Kommentar: ab # bis zum Zeilenende (nur am Anfang eines Tokens, siehe DocBlock)
			if ($char === '#') {
				if (!$atTokenStart) {
					throw new \InvalidArgumentException(
						"Zeile $line: \"#\" ist nur am Anfang eines Tokens ein Kommentarzeichen, nicht mitten im Wort."
					);
				}
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
						$parts = preg_split('/[\s]/', $token, 2);
						$directive = strtolower((string)($parts[0] ?? ''));
						$argument = trim((string)($parts[1] ?? ''));
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
								if ($directive === 'proxy_pass') {
									self::assertProxyPassTargetAllowed($argument, $tokenLine);
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
				// Nach ; { } beginnt zwingend ein neues Token.
				$atTokenStart = true;
			} else {
				// Token-Text sammeln; Zeile des Token-Anfangs merken (nur bei echtem Inhalt)
				$isWhitespace = $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
				if (!$tokenHasContent && !$isWhitespace) {
					$tokenLine = $line;
					$tokenHasContent = true;
				}
				$token .= $char;
				// Leerraum beendet das aktuelle (Teil-)Token; jedes andere Zeichen befindet
				// sich entweder schon mitten im Token oder beginnt gerade erst eins.
				$atTokenStart = $isWhitespace;
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
	 * I3 (Abschlussreview 2026-09-20): proxy_pass bleibt erlaubt, das Ziel darf aber
	 * nicht auf den eigenen Rechner zeigen. Die Oberfläche selbst läuft auf
	 * 127.0.0.1:8080 ohne eigene Anmeldung; ein proxy_pass auf sie würde die
	 * Oberfläche über jede beliebige öffentliche Domain erreichbar machen, weil
	 * nginx den Port beim server_name-Vergleich abschneidet.
	 *
	 * @throws \InvalidArgumentException mit Zeilennummer und Grund
	 */
	private static function assertProxyPassTargetAllowed(string $argument, int $line): void
	{
		if ($argument === '') {
			throw new \InvalidArgumentException("Zeile $line: \"proxy_pass\" ohne Ziel.");
		}
		// Eine Variable im Ziel (z.B. $backend) steht erst zur Laufzeit fest und lässt
		// sich hier nicht prüfen – deshalb sicherheitshalber ablehnen statt durchlassen.
		if (str_contains($argument, '$')) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"proxy_pass\" mit einer Variable im Ziel ist nicht erlaubt, das Ziel muss beim Speichern feststehen."
			);
		}
		$target = $argument;
		if (($target[0] === '"' || $target[0] === "'") && strlen($target) >= 2 && str_ends_with($target, $target[0])) {
			$target = substr($target, 1, -1);
		}
		if (stripos($target, 'unix:') === 0) {
			throw new \InvalidArgumentException("Zeile $line: \"proxy_pass\" auf einen Unix-Socket ist nicht erlaubt.");
		}
		$host = self::proxyPassTargetHost($target);
		if ($host !== null && self::isOwnMachineHost($host)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"proxy_pass\" auf den eigenen Rechner (\"$host\") ist nicht erlaubt – "
				. 'das würde die Oberfläche über diese Domain erreichbar machen.'
			);
		}
	}

	/**
	 * Host-Teil eines proxy_pass-Ziels, auch ohne Schema (z.B. "127.0.0.1:8080").
	 */
	private static function proxyPassTargetHost(string $target): ?string
	{
		if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $target)) {
			$target = 'http://' . $target;
		}
		$host = parse_url($target, PHP_URL_HOST);
		return is_string($host) && $host !== '' ? $host : null;
	}

	/**
	 * Zeigt der Host auf "diesen Rechner" (Loopback oder unspezifiziert)?
	 */
	private static function isOwnMachineHost(string $host): bool
	{
		$host = strtolower(trim($host, '[]'));
		if ($host === 'localhost' || $host === '0.0.0.0' || $host === '::' || $host === '::1') {
			return true;
		}
		return str_starts_with($host, '127.');
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
