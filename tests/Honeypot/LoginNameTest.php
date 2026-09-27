<?php
declare(strict_types=1);

/**
 * Tests der Einordnung versuchter Benutzernamen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 13:10
 */

namespace Tests\Honeypot;

use Honeypot\LoginName;
use PHPUnit\Framework\TestCase;

final class LoginNameTest extends TestCase
{
	public function testGenericNamesAreGuessed(): void
	{
		foreach (['admin', 'Administrator', 'root', 'test', 'user', 'guest', 'ubuntu', 'wordpress', 'x', ''] as $name) {
			self::assertSame(LoginName::GUESSED, LoginName::classify($name, 'mfsvr.de'), $name);
		}
	}

	/** Gezielt: der Domainname oder sein Hauptteil, auch mit Zusatz. */
	public function testNamesFromTheDomainAreTargeted(): void
	{
		foreach (['mfsvr', 'mfsvr.de', 'admin@mfsvr.de', 'MFSVR-admin', 'bienchen'] as $name) {
			self::assertSame(LoginName::TARGETED, LoginName::classify($name, 'bienchen.mfsvr.de'), $name);
		}
	}

	/** Eine Köderkennung ist der stärkste Fall: Der Name stammt aus unserem Köder. */
	public function testDecoyNamesAreRecognised(): void
	{
		self::assertSame(LoginName::DECOY, LoginName::classify('deploy-0123456789', 'mfsvr.de'));
	}

	public function testEverythingElseIsUnclear(): void
	{
		self::assertSame(LoginName::OTHER, LoginName::classify('kmueller', 'mfsvr.de'));
	}
}
