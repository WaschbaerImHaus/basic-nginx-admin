<?php
declare(strict_types=1);

/**
 * Ablage der Tagesberichte: <verzeichnis>/<host>/<datum>.json
 *
 * Geschrieben wird von der täglichen Auswertung (als root), gelesen von der Ansicht
 * (als Benutzer des php-fpm-Pools). Host und Datum kommen dort aus der Adresszeile und
 * werden hier streng geprüft: Die Ansicht läuft zwar mit open_basedir, aber darauf darf
 * sich diese Klasse nicht verlassen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:20
 */

namespace Honeypot;

final class ReportStore
{
	/** Ein Hostname, wie ihn ein vHost trägt – keine Pfadtrenner, keine Punkte allein. */
	private const HOST = '/^(?!\.)[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/D';
	private const DATE = '/^\d{4}-\d{2}-\d{2}$/D';

	public function __construct(private readonly string $dir)
	{
	}

	/**
	 * Legt den Bericht ab und überschreibt eine frühere Fassung desselben Tages – der
	 * laufende Tag wird bei jedem Lauf vollständiger.
	 *
	 * @throws \InvalidArgumentException bei unsicherem Host- oder Datumswert
	 * @throws \RuntimeException wenn sich die Datei nicht schreiben lässt
	 */
	public function save(string $host, DayReport $report): void
	{
		if (!self::isSafeHost($host) || !self::isSafeDate($report->date)) {
			throw new \InvalidArgumentException("Unsicherer Berichtspfad: $host / {$report->date}");
		}
		$dir = $this->dir . '/' . $host;
		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new \RuntimeException("Kann Berichtsordner nicht anlegen: $dir");
		}
		$file = $dir . '/' . $report->date . '.json';
		$json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (file_put_contents($file, $json) === false) {
			throw new \RuntimeException("Kann Bericht nicht schreiben: $file");
		}
		// Feste Rechte statt der Maske des aufrufenden Dienstes: Geschrieben wird als
		// root, gelesen vom Benutzer des php-fpm-Pools über die Gruppe des Ordners.
		// Ohne diesen Schritt läge die Datei je nach Maske für alle offen oder für die
		// Gruppe unlesbar.
		$group = filegroup($dir);
		if ($group !== false) {
			@chgrp($file, $group);
		}
		@chmod($file, 0640);
	}

	/**
	 * Berichtstage eines Hosts, neuester zuerst.
	 *
	 * @return list<string>
	 */
	public function days(string $host): array
	{
		if (!self::isSafeHost($host)) {
			return [];
		}
		$days = [];
		foreach ((array)glob($this->dir . '/' . $host . '/*.json') as $file) {
			$date = basename((string)$file, '.json');
			if (self::isSafeDate($date)) {
				$days[] = $date;
			}
		}
		rsort($days);
		return $days;
	}

	/**
	 * Hosts mit Berichten, alphabetisch.
	 *
	 * @return list<string>
	 */
	public function hosts(): array
	{
		$hosts = [];
		foreach ((array)glob($this->dir . '/*', GLOB_ONLYDIR) as $path) {
			$host = basename((string)$path);
			if (self::isSafeHost($host)) {
				$hosts[] = $host;
			}
		}
		sort($hosts);
		return $hosts;
	}

	/**
	 * Ein Tagesbericht, oder null, wenn es ihn nicht gibt.
	 */
	public function load(string $host, string $date): ?DayReport
	{
		if (!self::isSafeHost($host) || !self::isSafeDate($date)) {
			return null;
		}
		$file = $this->dir . '/' . $host . '/' . $date . '.json';
		if (!is_file($file)) {
			return null;
		}
		try {
			$data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		return is_array($data) ? DayReport::fromArray($data) : null;
	}

	/**
	 * Der jüngste Bericht eines Hosts.
	 */
	public function latest(string $host): ?DayReport
	{
		$days = $this->days($host);
		return $days === [] ? null : $this->load($host, $days[0]);
	}

	private static function isSafeHost(string $host): bool
	{
		return preg_match(self::HOST, $host) === 1;
	}

	private static function isSafeDate(string $date): bool
	{
		return preg_match(self::DATE, $date) === 1;
	}
}
