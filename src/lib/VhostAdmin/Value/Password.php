<?php
declare(strict_types=1);

/**
 * Erzeugt Passwörter für den Verzeichnisschutz.
 *
 * Die Oberfläche lässt keine selbst ausgedachten Passwörter mehr zu: Sie zeigt ein
 * erzeugtes an, das genau so hinterlegt wird. Das nimmt die häufigste Schwachstelle
 * eines Verzeichnisschutzes aus dem Spiel – ein zu einfaches Passwort.
 *
 * Gezogen wird aus dem Zufallsgenerator des Betriebssystems (random_int), nie aus
 * rand()/mt_rand(): deren Folge lässt sich aus wenigen Ausgaben vorhersagen.
 *
 * Auf der Kommandozeile bleibt jedes beliebige Passwort möglich
 * ("printf 'meins' | sudo vhost user-add <host> <benutzer>").
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 09:30
 */

namespace VhostAdmin\Value;

final class Password
{
	public const LENGTH = 20;

	private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	private const LOWER = 'abcdefghijklmnopqrstuvwxyz';
	private const DIGITS = '0123456789';
	/**
	 * Bewusst nur Zeichen, die sich überall ohne Umschweife eintippen lassen und in
	 * keiner der beteiligten Schichten eine Sonderrolle haben – die htpasswd-Datei
	 * trennt mit ":", das betrifft aber nur den Benutzernamen, nicht das Passwort.
	 */
	private const SPECIAL = '@=#+.,_-:;';

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Neues Passwort: 20 Zeichen, mindestens je ein Grossbuchstabe, Kleinbuchstabe,
	 * eine Ziffer und ein Sonderzeichen.
	 *
	 * Die vier Pflichtzeichen werden zuerst gezogen und danach mit dem Rest gemischt –
	 * würden sie einfach an feste Stellen gesetzt, wäre die Position jeder Zeichenart
	 * bekannt und das Passwort damit schwächer, als seine Länge vermuten lässt.
	 */
	public static function generate(): self
	{
		$classes = [self::UPPER, self::LOWER, self::DIGITS, self::SPECIAL];
		$all = implode('', $classes);

		$characters = [];
		foreach ($classes as $class) {
			$characters[] = self::pick($class);
		}
		for ($i = count($characters); $i < self::LENGTH; $i++) {
			$characters[] = self::pick($all);
		}
		self::shuffle($characters);

		return new self(implode('', $characters));
	}

	public function __toString(): string
	{
		return $this->value;
	}

	/**
	 * Ein zufälliges Zeichen aus $pool.
	 */
	private static function pick(string $pool): string
	{
		return $pool[random_int(0, strlen($pool) - 1)];
	}

	/**
	 * Fisher-Yates mit random_int; shuffle() von PHP taugt hier nicht, weil es den
	 * vorhersagbaren Generator benutzt.
	 *
	 * @param list<string> $characters
	 */
	private static function shuffle(array &$characters): void
	{
		for ($i = count($characters) - 1; $i > 0; $i--) {
			$j = random_int(0, $i);
			[$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
		}
	}
}
