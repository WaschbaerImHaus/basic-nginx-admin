<?php
declare(strict_types=1);

/**
 * Art eines vHosts: öffentliche Domain oder nur lokal erreichbarer Port.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:30
 */

namespace VhostAdmin;

enum VhostKind: string
{
	case Domain = 'domain';
	case Localhost = 'localhost';
}
