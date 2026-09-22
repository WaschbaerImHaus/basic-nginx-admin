<?php
declare(strict_types=1);

/**
 * Tests für die Passworterzeugung.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 09:30
 */

namespace Tests\Value;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\Password;

final class PasswordTest extends TestCase
{
	public function testHasTheRequestedLength(): void
	{
		self::assertSame(20, strlen(Password::generate()->value));
		self::assertSame(20, Password::LENGTH);
	}

	/**
	 * Jede Zeichenart muss vorkommen – sonst wäre "erzeugt sicher" nur meistens wahr.
	 */
	public function testContainsEveryCharacterClass(): void
	{
		// Mehrfach prüfen: ein einzelner Lauf könnte zufällig passen.
		for ($i = 0; $i < 50; $i++) {
			$value = Password::generate()->value;
			self::assertMatchesRegularExpression('/[A-Z]/', $value, $value);
			self::assertMatchesRegularExpression('/[a-z]/', $value, $value);
			self::assertMatchesRegularExpression('/[0-9]/', $value, $value);
			self::assertMatchesRegularExpression('/[@=#+.,_:;-]/', $value, $value);
		}
	}

	public function testUsesOnlyAllowedCharacters(): void
	{
		for ($i = 0; $i < 50; $i++) {
			$value = Password::generate()->value;
			self::assertSame(1, preg_match('/^[A-Za-z0-9@=#+.,_:;-]{20}$/', $value), $value);
		}
	}

	public function testEachPasswordIsDifferent(): void
	{
		$seen = [];
		for ($i = 0; $i < 200; $i++) {
			$seen[] = Password::generate()->value;
		}
		self::assertCount(200, array_unique($seen), 'Passwörter dürfen sich nicht wiederholen');
	}

	/**
	 * Die Pflichtzeichen dürfen nicht immer an denselben Stellen stehen – sonst wäre
	 * die Position jeder Zeichenart bekannt.
	 */
	public function testRequiredCharactersAreNotAlwaysInTheSamePlace(): void
	{
		$positionsOfDigit = [];
		for ($i = 0; $i < 100; $i++) {
			$value = Password::generate()->value;
			$positionsOfDigit[] = (int)preg_match('/[0-9]/', $value, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
		}
		self::assertGreaterThan(3, count(array_unique($positionsOfDigit)), 'Ziffern stehen zu oft an derselben Stelle');
	}
}
