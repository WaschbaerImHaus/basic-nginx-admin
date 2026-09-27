<?php
declare(strict_types=1);

/**
 * nginx hat einen Reload auch nach Wiederholung nicht mit einer neuen
 * Worker-Generation bestätigt. Ob die neue Konfiguration gilt, ist offen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:10
 */

namespace VhostAdmin\Nginx;

final class ReloadNotConfirmedException extends \RuntimeException
{
}
