<?php
declare(strict_types=1);

/**
 * Tests der Zeilenzerlegung. Die Beispielzeilen stammen unverändert aus dem
 * access.log von mfsvr.de – erfundene Zeilen würden genau die Fälle verfehlen,
 * um die es hier geht.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:00
 */

namespace Tests\Honeypot;

use Honeypot\LogEntry;
use PHPUnit\Framework\TestCase;

final class LogEntryTest extends TestCase
{
	private const NORMAL = '10.200.0.1 - - [21/Sep/2026:15:56:14 +0200] "GET /ai/site-profile.json HTTP/1.1" 401 172 "-" "Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)"';

	public function testReadsAllFieldsOfACombinedLine(): void
	{
		$entry = LogEntry::fromLine(self::NORMAL);
		self::assertNotNull($entry);
		self::assertSame('2026-09-21', $entry->date);
		self::assertSame('15:56:14', $entry->time);
		self::assertSame('15', $entry->hour());
		self::assertSame('GET', $entry->method);
		self::assertSame('/ai/site-profile.json', $entry->path);
		self::assertSame('401', $entry->status);
		self::assertStringContainsString('CensysInspect', $entry->agent);
	}

	/**
	 * Das Datum muss aus der Zeile kommen, nicht aus dem Dateinamen: access.log.1
	 * enthält nach der Rotation um 06:20 Zeilen von zwei Kalendertagen.
	 */
	public function testDateComesFromTheLineItself(): void
	{
		$entry = LogEntry::fromLine(str_replace('21/Sep/2026', '22/Sep/2026', self::NORMAL));
		self::assertSame('2026-09-22', $entry?->date);
	}

	public function testRejectsALineThatIsNotInCombinedFormat(): void
	{
		self::assertNull(LogEntry::fromLine('irgendein Text'));
		self::assertNull(LogEntry::fromLine(''));
	}

	/**
	 * Sondierungen anderer Dienste: kein HTTP-Verb, dafür ein Bannertext oder Binärmüll.
	 */
	public function testRecognisesProbesThatAreNotHttpAtAll(): void
	{
		$ssh = LogEntry::fromLine('10.200.0.1 - - [22/Sep/2026:10:57:08 +0200] "SSH-2.0-Go" 400 150 "-" "-"');
		$binary = LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:22:35:41 +0200] "\x00\x00\x00\x08\x04\xD2\x16/" 400 150 "-" "-"');
		$empty = LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:18:18:07 +0200] "-" 400 150 "-" "-"');
		self::assertTrue($ssh?->isProbe(), 'SSH-Banner auf Port 443');
		self::assertTrue($binary?->isProbe(), 'Binärprotokoll');
		self::assertTrue($empty?->isProbe(), 'leere Anfragezeile');
		self::assertFalse(LogEntry::fromLine(self::NORMAL)?->isProbe());
	}

	/**
	 * CONNECT ist syntaktisch HTTP, sucht aber einen offenen Proxy – die Anfrage gehört
	 * in die Kachel „kein Webzugriff", nicht zu den gewöhnlichen Aufrufen.
	 */
	public function testConnectCountsAsAProbeAlthoughItLooksLikeHttp(): void
	{
		$entry = LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:20:35:35 +0200] "CONNECT ip.ifconfig.dpdns.org:443 HTTP/1.1" 400 150 "-" "-"');
		self::assertTrue($entry?->isProbe());
		self::assertSame('Proxy gesucht', $entry?->probeKind());
	}

	public function testNamesTheKindOfProbe(): void
	{
		$kinds = [
			'"SSH-2.0-Go" 400 150 "-" "-"' => 'SSH-Banner',
			'"MGLNDD_87.106.116.167_443" 400 150 "-" "-"' => 'Portscanner-Kennung',
			'"\x10\x00\x00" 400 150 "-" "-"' => 'Binärprotokoll',
			'"-" 400 150 "-" "-"' => 'leere Anfrage',
		];
		foreach ($kinds as $tail => $expected) {
			$entry = LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:20:35:35 +0200] ' . $tail);
			self::assertSame($expected, $entry?->probeKind(), $tail);
		}
	}

	/**
	 * Ein 400 mit gültigem Verb ist kein Protokollfremdling, sondern eine verstümmelte
	 * HTTP-Anfrage (z. B. zgrab). Sie darf die Kachel nicht auffüllen.
	 */
	public function testAMalformedHttpRequestIsNotAServiceProbe(): void
	{
		$entry = LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:16:34:24 +0200] "GET / HTTP/1.1" 400 248 "-" "Mozilla/5.0 zgrab/0.x"');
		self::assertFalse($entry?->isProbe());
	}

	/**
	 * Die Detailansicht kennt bei Zeiträumen nur die Rohzeile, nicht mehr jedes
	 * Ereignis. Die Art muss sich daraus allein bestimmen lassen.
	 */
	public function testNamesTheKindOfProbeFromTheRawRequestAlone(): void
	{
		self::assertSame('SSH-Banner', LogEntry::probeKindOf('SSH-2.0-Go'));
		self::assertSame('Proxy gesucht', LogEntry::probeKindOf('CONNECT x.example:443 HTTP/1.1'));
		self::assertSame('Binärprotokoll', LogEntry::probeKindOf('\\x16\\x03\\x01'));
		self::assertSame('leere Anfrage', LogEntry::probeKindOf('-'));
		self::assertNull(LogEntry::probeKindOf('GET / HTTP/1.1'), 'gewöhnliches HTTP');
	}
}
