<?php
declare(strict_types=1);

/**
 * Wertobjekt: der Pfad, auf den der Verzeichnisschutz beschränkt ist.
 *
 * Ohne Pfad (null) gilt der Schutz für die ganze Seite. Mit Pfad nur für ihn und alles
 * darunter: /admin schützt /admin, /admin/ und /admin/x.php, nicht aber /administrator.
 *
 * Der Pfad landet als regulärer Ausdruck in der nginx-Konfiguration (map auf $uri).
 * Erlaubt sind deshalb nur Zeichen ohne Bedeutung für nginx oder den Ausdruck – jedes
 * Semikolon, jede Klammer, jedes $ wäre ein Weg, Direktiven einzuschleusen oder den
 * Schutz auszuhebeln. Prozentkodierung ist ebenfalls ausgeschlossen: nginx vergleicht
 * mit dem bereits dekodierten und normalisierten $uri.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 21:20
 */

namespace VhostAdmin\Value;

final class ProtectPath
{
	/** Segmente aus Buchstaben, Ziffern und . _ ~ - (RFC 3986 „unreserved"). */
	private const SEGMENT = '/^[A-Za-z0-9._~-]+$/D';
	private const MAX_LENGTH = 200;

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Liest einen Pfad; null bei leerer Eingabe oder "/" (= ganze Seite).
	 *
	 * @throws \InvalidArgumentException bei unzulässigen Zeichen oder Segmenten
	 */
	public static function fromString(string $raw): ?self
	{
		$trimmed = trim($raw);
		if (strlen($trimmed) > self::MAX_LENGTH) {
			throw new \InvalidArgumentException('Pfad zu lang (höchstens ' . self::MAX_LENGTH . ' Zeichen).');
		}
		// Führende und abschliessende Schrägstriche gehören nicht zum Namen; "/" allein
		// heisst: ganze Seite.
		$inner = trim($trimmed, '/');
		if ($inner === '') {
			return null;
		}
		foreach (explode('/', $inner) as $segment) {
			if ($segment === '') {
				throw new \InvalidArgumentException("Doppelter Schrägstrich im Pfad: $raw");
			}
			if ($segment === '.' || $segment === '..') {
				throw new \InvalidArgumentException("Pfad darf kein \".\" oder \"..\" enthalten: $raw");
			}
			if (preg_match(self::SEGMENT, $segment) !== 1) {
				throw new \InvalidArgumentException(
					"Unzulässiges Zeichen im Pfad: $raw (erlaubt: Buchstaben ohne Umlaute, Ziffern, . _ ~ -)"
				);
			}
		}
		return new self('/' . $inner);
	}

	/**
	 * Schlüssel für eine nginx-map als regulärer Ausdruck: der Pfad selbst oder alles
	 * darunter, aber kein längerer Name mit demselben Anfang.
	 */
	public function pattern(): string
	{
		// Aus den erlaubten Zeichen hat nur der Punkt im Ausdruck eine Bedeutung.
		return '~^' . str_replace('.', '\\.', $this->value) . '(?:/|$)';
	}
}
