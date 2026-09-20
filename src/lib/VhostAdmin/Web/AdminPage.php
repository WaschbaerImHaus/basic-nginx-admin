<?php
declare(strict_types=1);

/**
 * Logik der Verwaltungsoberfläche: Formularaktionen, CSRF, Flash-Meldungen, Lesezugriffe.
 *
 * Schreibende Aktionen laufen ausschließlich über das CLI (CommandRunner);
 * die Oberfläche selbst schreibt nie in Datenbank oder Dateisystem.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 19:13
 */

namespace VhostAdmin\Web;

use VhostAdmin\Config;
use VhostAdmin\Vhost;
use VhostAdmin\Ssl\ReachabilityChecker;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;

final class AdminPage
{
	/** @var array<string, mixed> */
	private array $session;

	/**
	 * @param array<string, mixed> $session Sitzungsdaten (per Referenz, z. B. $_SESSION)
	 */
	public function __construct(
		private readonly VhostRepository $repository,
		private readonly CommandRunner $runner,
		private readonly Config $config,
		private readonly VhostLayout $layout,
		private readonly ReachabilityChecker $reachability,
		array &$session,
	) {
		$this->session = &$session;
	}

	/**
	 * Erreichbarkeit der übergebenen vHosts über den ACME-Pfad.
	 *
	 * Läuft nur beim Aufbau der Seite (Nutzervorgabe vom 2026-09-20: nicht dauernd
	 * prüfen). Die Abfragen laufen parallel, damit die Seite nicht bei jeder Domain
	 * nacheinander auf einen Zeitablauf wartet.
	 *
	 * @param list<Vhost> $vhosts
	 * @return array<string, \VhostAdmin\Ssl\ReachabilityResult>
	 */
	public function reachability(array $vhosts): array
	{
		return $this->reachability->check($vhosts);
	}

	/**
	 * Systembenutzer, unter dem PHP dieses vHosts läuft (für die Anzeige).
	 */
	public function phpUser(Vhost $vhost): string
	{
		return $this->layout->phpUser($vhost);
	}

	/**
	 * CSRF-Token der Sitzung; wird beim ersten Zugriff erzeugt.
	 */
	public function csrfToken(): string
	{
		if (!isset($this->session['csrf']) || !is_string($this->session['csrf'])) {
			$this->session['csrf'] = bin2hex(random_bytes(16));
		}
		return $this->session['csrf'];
	}

	/**
	 * Prüft ein übermitteltes CSRF-Token gegen das der Sitzung.
	 */
	public function isValidCsrf(string $token): bool
	{
		return $token !== '' && hash_equals($this->csrfToken(), $token);
	}

	/**
	 * Übersetzt eine Formularaktion in CLI-Argumente (und stdin für Passwörter).
	 *
	 * @param array<string, mixed> $post
	 * @return ?array{args: list<string>, stdin: ?string} null bei unbekannter Aktion
	 */
	public static function commandFor(string $action, array $post): ?array
	{
		$field = static fn(string $key): string => trim((string)($post[$key] ?? ''));
		$name = $field('name');
		return match ($action) {
			'create' => [
				'args' => array_merge(['add', $field('domain')], $field('subdir') !== '' ? ['--subdir', $field('subdir')] : []),
				'stdin' => null,
			],
			'protect' => ['args' => ['protect', $name, $field('state')], 'stdin' => null],
			'user_add' => ['args' => ['user-add', $name, $field('username')], 'stdin' => (string)($post['password'] ?? '') . "\n"],
			'user_del' => ['args' => ['user-del', $name, $field('username')], 'stdin' => null],
			'ip_add' => ['args' => ['ip-add', $name, $field('cidr')], 'stdin' => null],
			'ip_del' => ['args' => ['ip-del', $name, $field('cidr')], 'stdin' => null],
			'ssl' => ['args' => ['ssl', $name, $field('state')], 'stdin' => null],
			'php' => ['args' => ['php', $name, $field('state')], 'stdin' => null],
			'remove' => ['args' => ['remove', $name], 'stdin' => null],
			'email' => ['args' => ['set', 'le_email', $field('le_email')], 'stdin' => null],
			'conf' => ['args' => ['conf', $name], 'stdin' => (string)($post['snippet'] ?? '')],
			default => null,
		};
	}

	/**
	 * Ziel nach einer Aktion: Detailseite des vHosts, nach Anlegen die neue Domain, sonst Übersicht.
	 *
	 * @param array<string, mixed> $post
	 */
	public static function redirectTarget(string $action, int $exitCode, array $post): string
	{
		if ($action === 'create') {
			return $exitCode === 0 ? '/?v=' . rawurlencode(strtolower(trim((string)($post['domain'] ?? '')))) : '/';
		}
		if (in_array($action, ['remove', 'email'], true)) {
			return '/';
		}
		return '/?v=' . rawurlencode(trim((string)($post['name'] ?? '')));
	}

	/**
	 * Führt die Aktion aus, merkt sich das Ergebnis als Flash und liefert das Redirect-Ziel.
	 *
	 * @param array<string, mixed> $post
	 */
	public function handlePost(array $post): string
	{
		$action = (string)($post['action'] ?? '');
		$command = self::commandFor($action, $post);
		if ($command === null) {
			return '/';
		}
		[$code, $output] = $this->runner->run($command['args'], $command['stdin']);
		$this->session['flash'] = [
			$code === 0 ? 'ok' : 'err',
			$output !== '' ? $output : ($code === 0 ? 'Erledigt.' : "Fehler (Exit $code)"),
		];
		return self::redirectTarget($action, $code, $post);
	}

	/**
	 * Flash-Meldung abholen und löschen.
	 *
	 * @return ?array{0: string, 1: string}
	 */
	public function takeFlash(): ?array
	{
		$flash = $this->session['flash'] ?? null;
		unset($this->session['flash']);
		return is_array($flash) ? $flash : null;
	}

	/**
	 * Alle vHosts (aus dem Repository).
	 *
	 * @return list<Vhost>
	 */
	public function vhosts(): array
	{
		return $this->repository->all();
	}

	/**
	 * Einzelner vHost nach Name, oder null, wenn unbekannt.
	 */
	public function vhost(string $name): ?Vhost
	{
		return $this->repository->byName($name);
	}

	/**
	 * Schutz-Benutzer eines vHosts.
	 *
	 * @return list<array{username: string, hash: string}>
	 */
	public function users(Vhost $vhost): array
	{
		return $this->repository->users($vhost->id);
	}

	/**
	 * Freigegebene IPs eines vHosts.
	 *
	 * @return list<string>
	 */
	public function ips(Vhost $vhost): array
	{
		return $this->repository->ips($vhost->id);
	}

	/**
	 * Hinterlegte Let's-Encrypt-E-Mail-Adresse, falls gesetzt.
	 */
	public function letsEncryptEmail(): ?string
	{
		return $this->repository->setting('le_email');
	}

	/**
	 * Aktuelles nginx-Snippet des vHosts für die Anzeige im Textfeld.
	 *
	 * Die Oberfläche liest die Datei nur; geschrieben wird sie ausschließlich vom
	 * root-CLI. Ist sie nicht lesbar, bleibt das Feld leer.
	 */
	public function snippet(Vhost $vhost): string
	{
		// Nicht aus der Datei: conf/ gehört root und ist für www-data nicht zugänglich
		// (Nutzervorgabe vom 2026-09-20 – bearbeitet wird ausschliesslich über den Admin).
		// Der Text kommt deshalb über das CLI, das als root liest. Ohne Beschneiden,
		// damit Einrückung und Leerzeilen im Textfeld erhalten bleiben.
		[$code, $output] = $this->runner->run(['conf-show', $vhost->name], null, false);
		return $code === 0 ? $output : '';
	}
}
