<?php
declare(strict_types=1);

/**
 * Löst einen Reload aus und bestätigt, dass nginx ihn übernommen hat.
 *
 * Hintergrund (BUGS.md, „Reload wird verschluckt"): Gemessen wurde ein Reload, nach
 * dem der Worker unverändert alt blieb – die Oberfläche meldete Erfolg, nginx lieferte
 * weiter die alte Konfiguration aus. Das ist nicht nur lästig: Verschluckt werden kann
 * auch der Reload, der einen Benutzer entfernt oder einen Schutz einschaltet.
 *
 * Bestätigt ist ein Reload, sobald ein Worker läuft, den es vorher nicht gab – nginx
 * startet für jede neu gelesene Konfiguration eine neue Worker-Generation. Das tritt
 * sofort ein, auch wenn die alten Worker noch laufende Anfragen zu Ende bringen (bei
 * Aufrufen aus der Oberfläche die eigene). Bleibt es aus, wird der Reload wiederholt,
 * höchstens dreimal.
 *
 * Die Abhängigkeiten sind Funktionen, damit sich das Verhalten ohne nginx testen lässt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:10
 */

namespace VhostAdmin\Nginx;

final class ReloadConfirmation
{
	private const ATTEMPTS = 3;
	private const WAIT_SECONDS = 1.5;
	private const STEP_SECONDS = 0.05;

	/**
	 * @param \Closure(): void        $trigger löst den Reload aus (wirft bei Fehler)
	 * @param \Closure(): list<int>   $workers PIDs der aktuellen Worker
	 * @param \Closure(float): void   $sleep   wartet so viele Sekunden
	 */
	public function __construct(
		private readonly \Closure $trigger,
		private readonly \Closure $workers,
		private readonly \Closure $sleep,
	) {
	}

	/**
	 * @throws ReloadNotConfirmedException wenn auch der letzte Versuch keine neue Generation startet
	 */
	public function run(): void
	{
		$known = array_fill_keys(($this->workers)(), true);
		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			($this->trigger)();
			for ($waited = 0.0; $waited < self::WAIT_SECONDS; $waited += self::STEP_SECONDS) {
				foreach (($this->workers)() as $pid) {
					if (!isset($known[$pid])) {
						return;
					}
				}
				($this->sleep)(self::STEP_SECONDS);
			}
			// Was bis hier auftauchte und wieder verschwand, zählt beim nächsten Versuch
			// nicht als neu; neu ist nur, was nach dem nächsten Auslösen erscheint.
			foreach (($this->workers)() as $pid) {
				$known[$pid] = true;
			}
		}
		throw new ReloadNotConfirmedException(
			'nginx hat die neue Konfiguration nach ' . self::ATTEMPTS . ' Reloads nicht übernommen '
			. '(keine neue Worker-Generation). Bitte erneut versuchen oder "systemctl restart nginx".'
		);
	}
}
