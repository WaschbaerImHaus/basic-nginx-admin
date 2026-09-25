<?php
declare(strict_types=1);

/**
 * Tests der Anreicherung von Gegenstellen um Adresse, Netz, Land und Rückwärtsnamen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 10:00
 */

namespace Tests\Honeypot;

use Honeypot\NetworkRegistry;
use Honeypot\Peer;
use Honeypot\PeerResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDns;
use Tests\Support\TempDir;

final class PeerResolverTest extends TestCase
{
	private string $dir;
	private FakeDns $dns;
	private PeerResolver $resolver;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		$first = str_pad(bin2hex((string)inet_pton('::ffff:192.0.2.0')), 32, '0', STR_PAD_LEFT);
		$last = str_pad(bin2hex((string)inet_pton('::ffff:192.0.2.255')), 32, '0', STR_PAD_LEFT);
		file_put_contents($this->dir . '/networks.txt', "$first $last US arin 192.0.2.0/24\n");
		$this->dns = new FakeDns();
		$this->resolver = new PeerResolver($this->dns, new NetworkRegistry($this->dir . '/networks.txt'));
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testResolvesANameToAddressNetworkCountryAndReverseName(): void
	{
		$this->dns->addresses['about.censys.io'] = '192.0.2.10';
		$this->dns->reverse['192.0.2.10'] = 'scanner-7.censys.io';

		$peers = $this->resolver->resolve([new Peer('about.censys.io', Peer::SELF_DECLARED, 5)]);

		self::assertSame('192.0.2.10', $peers[0]->address);
		self::assertSame('192.0.2.0/24', $peers[0]->network?->network);
		self::assertSame('US', $peers[0]->network?->country);
		self::assertSame('arin', $peers[0]->network?->registry);
		self::assertSame('scanner-7.censys.io', $peers[0]->reverse);
	}

	/**
	 * Steht schon eine Adresse da, wäre eine Vorwärtsauflösung sinnlose Arbeit.
	 */
	public function testDoesNotLookUpAnAddressThatIsAlreadyOne(): void
	{
		$this->dns->reverse['192.0.2.7'] = 'host.example';
		$peers = $this->resolver->resolve([new Peer('192.0.2.7', Peer::PAYLOAD, 1)]);

		self::assertSame('192.0.2.7', $peers[0]->address);
		self::assertSame('US', $peers[0]->network?->country);
		self::assertNotContains('A 192.0.2.7', $this->dns->calls);
	}

	/**
	 * Ein Name ohne Eintrag, ein Netz ohne Tabellenzeile: Die Gegenstelle bleibt in der
	 * Liste, nur ohne Angaben. Nichts erfinden – eine falsche Herkunft ist schlimmer
	 * als eine fehlende.
	 */
	public function testKeepsAPeerThatCannotBeResolvedButFillsNothingIn(): void
	{
		$peers = $this->resolver->resolve([new Peer('gibtsnicht.example', Peer::SELF_DECLARED, 2)]);

		self::assertCount(1, $peers);
		self::assertNull($peers[0]->address);
		self::assertNull($peers[0]->network);
		self::assertNull($peers[0]->reverse);
		self::assertSame(2, $peers[0]->requests, 'die Zahl bleibt');
	}

	public function testAnAddressOutsideTheTableKeepsItsAddressButHasNoNetwork(): void
	{
		$peers = $this->resolver->resolve([new Peer('203.0.113.9', Peer::PAYLOAD, 1)]);
		self::assertSame('203.0.113.9', $peers[0]->address);
		self::assertNull($peers[0]->network);
	}

	/**
	 * Jeder Name wird einmal gefragt, auch wenn er mehrfach in der Liste steht – DNS
	 * ist der langsamste Teil der Auswertung.
	 */
	public function testAsksForEveryNameOnlyOnce(): void
	{
		$this->dns->addresses['doppelt.example'] = '192.0.2.5';
		$this->resolver->resolve([
			new Peer('doppelt.example', Peer::SELF_DECLARED, 3),
			new Peer('doppelt.example', Peer::PAYLOAD, 1),
		]);
		self::assertSame(1, count(array_filter($this->dns->calls, static fn(string $c): bool => $c === 'A doppelt.example')));
	}

	/**
	 * Die Auswertung reichert mehrere Tage in einem Lauf an, und dieselben Scanner
	 * tauchen Tag für Tag auf. Ohne Zwischenspeicher über die Aufrufe hinweg ginge
	 * jeder Name je Tag erneut ins DNS.
	 */
	public function testRemembersAnswersAcrossSeveralCalls(): void
	{
		$this->dns->addresses['taeglich.example'] = '192.0.2.9';
		$this->resolver->resolve([new Peer('taeglich.example', Peer::SELF_DECLARED, 1)]);
		$this->resolver->resolve([new Peer('taeglich.example', Peer::SELF_DECLARED, 4)]);

		self::assertSame(
			['A taeglich.example', 'PTR 192.0.2.9'],
			$this->dns->calls,
			'beim zweiten Tag darf nichts mehr gefragt werden'
		);
	}

	/**
	 * Auch ein erfolgloses Nachschlagen wird behalten – sonst würde eine unbekannte
	 * Stelle bei jedem Tag erneut die ganze Tabelle durchlaufen.
	 */
	public function testRemembersThatAnAddressIsNotInTheTable(): void
	{
		$this->resolver->resolve([new Peer('203.0.113.9', Peer::PAYLOAD, 1)]);
		$this->resolver->resolve([new Peer('203.0.113.9', Peer::PAYLOAD, 2)]);
		self::assertSame(['PTR 203.0.113.9'], $this->dns->calls);
	}
}
