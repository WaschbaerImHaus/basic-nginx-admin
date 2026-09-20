<?php
declare(strict_types=1);

/**
 * Ergebnis eines ACME-Erreichbarkeitstests für eine Domain.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:10
 */

namespace VhostAdmin\Ssl;

final class ReachabilityResult
{
	/**
	 * @param ReachabilityStatus $status  Ergebnisart
	 * @param string             $message Satz für die Oberfläche bzw. das CLI, der sagt,
	 *                                    was zu tun ist
	 * @param ?int               $httpCode HTTP-Status, falls überhaupt geantwortet wurde
	 */
	public function __construct(
		public readonly ReachabilityStatus $status,
		public readonly string $message,
		public readonly ?int $httpCode = null,
	) {
	}

	public function isOk(): bool
	{
		return $this->status === ReachabilityStatus::Ok;
	}
}
