<?php
declare(strict_types=1);

/**
 * Tests der Umwandlung der RIR-Statistikdateien in die Nachschlagetabelle.
 *
 * Die Beispielzeilen sind unveränderte Ausschnitte aus delegated-ripencc-latest bzw.
 * delegated-arin-extended-latest. Das Format hat zwei Fallen, die beide hier geprüft
 * werden: Bei IPv4 steht die ANZAHL der Adressen, bei IPv6 die PRÄFIXLÄNGE.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 09:00
 */

namespace Tests\Honeypot;

use Honeypot\RirTable;
use PHPUnit\Framework\TestCase;

final class RirTableTest extends TestCase
{
	private RirTable $table;

	protected function setUp(): void
	{
		$this->table = new RirTable();
	}

	/** Zerlegt eine Ausgabezeile in ihre fünf Felder. */
	private function fields(string $line): array
	{
		return explode(' ', trim($line));
	}

	public function testConvertsAnIpv4BlockUsingTheAddressCount(): void
	{
		$line = $this->table->convert('ripencc|DE|ipv4|87.106.0.0|65536|20000101|allocated');
		self::assertNotNull($line);
		[$start, $end, $country, $registry, $network] = $this->fields($line);

		self::assertSame('DE', $country);
		self::assertSame('ripencc', $registry);
		self::assertSame('87.106.0.0/16', $network, '65536 Adressen sind ein /16');
		self::assertSame(bin2hex((string)inet_pton('::ffff:87.106.0.0')), $start);
		self::assertSame(bin2hex((string)inet_pton('::ffff:87.106.255.255')), $end);
	}

	/**
	 * Bei IPv6 ist das fünfte Feld die Präfixlänge, nicht die Anzahl. Wer es als Anzahl
	 * liest, bekommt aus einem /32 einen Block von 32 Adressen – also praktisch nichts.
	 */
	public function testConvertsAnIpv6BlockUsingThePrefixLength(): void
	{
		$line = $this->table->convert('ripencc|DE|ipv6|2a02:2e0::|32|20080603|allocated');
		[$start, $end, , , $network] = $this->fields((string)$line);

		self::assertSame('2a02:2e0::/32', $network);
		self::assertSame(bin2hex((string)inet_pton('2a02:2e0::')), $start);
		self::assertSame(bin2hex((string)inet_pton('2a02:2e0:ffff:ffff:ffff:ffff:ffff:ffff')), $end);
	}

	/**
	 * Eine Präfixlänge, die nicht auf einer Vierergrenze liegt, muss bitweise gerechnet
	 * werden. /35 lässt die Bits 35 bis 127 frei: Im dritten Blockpaar bleiben die
	 * oberen drei Bits fest, es läuft also nur bis 0x1fff – nicht bis 0xffff. Über die
	 * Hexstellen gerechnet käme das Ende um den Faktor acht zu hoch heraus.
	 */
	public function testHandlesAPrefixLengthThatIsNotAMultipleOfFour(): void
	{
		$line = $this->table->convert('apnic|JP|ipv6|2001:200::|35|19990813|allocated');
		[$start, $end, , ,] = $this->fields((string)$line);
		self::assertSame(bin2hex((string)inet_pton('2001:200:1fff:ffff:ffff:ffff:ffff:ffff')), $end);
		// Gegenrechnung: /35 sind 2^93 Adressen.
		self::assertSame(
			2 ** 93,
			(float)(hexdec(substr($end, 0, 12)) - hexdec(substr($start, 0, 12)) + 1) * 2 ** 80
		);
	}

	/**
	 * Nicht jeder IPv4-Block ist eine Zweierpotenz. Dann gibt es kein CIDR, und der
	 * Bereich muss trotzdem nachschlagbar bleiben.
	 */
	public function testKeepsABlockThatIsNotACleanCidr(): void
	{
		$line = $this->table->convert('arin|US|ipv4|192.0.2.0|768|19900101|allocated');
		[$start, $end, , , $network] = $this->fields((string)$line);

		self::assertSame('192.0.2.0–192.0.4.255', $network, 'kein CIDR, also der Bereich');
		self::assertSame(bin2hex((string)inet_pton('::ffff:192.0.2.0')), $start);
		self::assertSame(bin2hex((string)inet_pton('::ffff:192.0.4.255')), $end);
	}

	/**
	 * Freie und reservierte Blöcke gehören niemandem; sie als Herkunft auszugeben wäre
	 * eine Falschaussage. Ebenso die Kopf- und Summenzeilen der Datei.
	 */
	public function testSkipsEverythingThatIsNotAnAssignedBlock(): void
	{
		$skipped = [
			'2|ripencc|1790114399|167191|19700101|20260922|+0200' => 'Kopfzeile',
			'ripencc|*|ipv4|*|100462|summary' => 'Summenzeile',
			'ripencc||ipv4|5.2.0.0|8192|20110915|available' => 'frei',
			'arin|US|ipv4|192.168.0.0|65536|19900101|reserved' => 'reserviert',
			'ripencc|DE|asn|12345|1|20000101|allocated' => 'AS-Nummer, kein Adressblock',
			'# Kommentar' => 'Kommentar',
			'' => 'leere Zeile',
		];
		foreach ($skipped as $line => $why) {
			self::assertNull($this->table->convert((string)$line), $why);
		}
	}

	public function testAcceptsAssignedAsWellAsAllocated(): void
	{
		self::assertNotNull($this->table->convert('arin|US|ipv4|8.8.8.0|256|19920101|assigned'));
		self::assertNotNull($this->table->convert('arin|US|ipv4|8.8.9.0|256|19920101|allocated'));
	}

	/**
	 * Die erweiterte Fassung von ARIN hat zusätzliche Felder hinter dem Status. Sie darf
	 * nicht daran scheitern, sonst fehlte ein ganzer Kontinent.
	 */
	public function testReadsTheExtendedFormatWithItsExtraColumns(): void
	{
		$line = $this->table->convert('arin|US|ipv4|8.8.8.0|256|19920101|assigned|abc123|e-mail');
		self::assertSame('US', $this->fields((string)$line)[2]);
	}

	public function testConvertsAWholeFileAndCountsWhatItUsed(): void
	{
		$input = "2|arin|1|1|1|1|+0000\narin|*|ipv4|*|5|summary\n"
			. "arin|US|ipv4|8.8.8.0|256|19920101|assigned\n"
			. "arin|CA|ipv6|2001:db8::|32|20010101|allocated\n"
			. "arin||ipv4|1.0.0.0|256|19920101|available\n";
		$out = '';
		$used = $this->table->convertAll(explode("\n", $input), static function (string $line) use (&$out): void {
			$out .= $line;
		});
		self::assertSame(2, $used);
		self::assertSame(2, substr_count($out, "\n"));
		self::assertStringContainsString('US arin 8.8.8.0/24', $out);
		self::assertStringContainsString('CA arin 2001:db8::/32', $out);
	}
}
