<?php
declare(strict_types=1);

/**
 * Ergebnisarten des ACME-Erreichbarkeitstests.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:10
 */

namespace VhostAdmin\Ssl;

enum ReachabilityStatus: string
{
	/** Der Marker wurde über das Internet abgerufen – Let's Encrypt kann kommen. */
	case Ok = 'ok';

	/** Der Name löst nicht auf. */
	case DnsFailed = 'dns';

	/** Name löst auf, aber Port 80 antwortet nicht (Firewall, falsche IP, Dienst aus). */
	case Unreachable = 'unreachable';

	/**
	 * Es antwortet ein Server, aber nicht dieser: der Marker fehlt oder hat einen
	 * anderen Inhalt. Typisch, wenn die Domain per DNS auf einen anderen Rechner zeigt.
	 */
	case WrongServer = 'wrong-server';

	/** Der Test gilt nicht (localhost-Host ist nie aus dem Internet erreichbar). */
	case NotApplicable = 'n/a';

	/**
	 * Kurztext für die Oberfläche.
	 */
	public function label(): string
	{
		return match ($this) {
			self::Ok => 'erreichbar',
			self::DnsFailed => 'DNS fehlt',
			self::Unreachable => 'nicht erreichbar',
			self::WrongServer => 'fremder Server',
			self::NotApplicable => 'entfällt',
		};
	}
}
