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
use VhostAdmin\Value\Password;
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
	 * Deckt das Zertifikat den Nebennamen der www-Umleitung ab?
	 *
	 * Über das CLI, weil /etc/letsencrypt für www-data nicht lesbar ist.
	 */
	public function certificateCoversAlias(Vhost $vhost): bool
	{
		if ($vhost->aliasName() === null || !$vhost->ssl) {
			return true;
		}
		[$code, $output] = $this->runner->run(['cert-covers', $vhost->name]);
		return $code === 0 && trim($output) === 'ja';
	}

	/**
	 * Die fertige nginx-Konfiguration dieses vHosts als ein Text.
	 *
	 * Über das CLI, weil conf/ root gehört und die Oberfläche dort nicht hineinsieht.
	 */
	public function effectiveConfig(Vhost $vhost): string
	{
		[$code, $output] = $this->runner->run(['show', $vhost->name], null, false);
		return $code === 0 ? $output : '';
	}

	/**
	 * Vorschlag für ein neues Passwort; wird im Formular angezeigt und genau so
	 * hinterlegt.
	 */
	public function suggestedPassword(): string
	{
		return Password::generate()->value;
	}

	/**
	 * Zuletzt gesetztes Passwort dieses vHosts, einmalig zum Anzeigen.
	 *
	 * Wird beim Lesen verbraucht: Ein Neuladen der Seite soll es nicht erneut zeigen.
	 *
	 * @return ?array{user: string, password: string}
	 */
	public function takeCredential(Vhost $vhost): ?array
	{
		$credential = $this->session['credential'] ?? null;
		if (!is_array($credential) || ($credential['vhost'] ?? null) !== $vhost->name) {
			return null;
		}
		unset($this->session['credential']);
		return ['user' => (string)$credential['user'], 'password' => (string)$credential['password']];
	}

	/**
	 * Abgelehnter Direktiven-Entwurf dieses vHosts, falls es einen gibt.
	 *
	 * Wird beim Lesen verbraucht (wie die Meldung): nach dem Anzeigen im Textfeld darf
	 * er die gespeicherte Fassung nicht noch einmal überdecken.
	 */
	public function draft(Vhost $vhost): ?string
	{
		$draft = $this->session['draft'] ?? null;
		if (!is_array($draft) || ($draft['name'] ?? null) !== $vhost->name) {
			return null;
		}
		unset($this->session['draft']);
		return (string)$draft['text'];
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
		// Jeder Formularwert steht hinter "--": Das CLI liest ihn dann nie als Option,
		// auch wenn er mit "--" beginnt. Beabsichtigte Optionen stehen davor.
		$line = static fn(string $command, array $values, array $options = []): array
			=> array_merge([$command], $options, ['--'], $values);
		return match ($action) {
			'create' => [
				'args' => $line('add', [$field('domain')], $field('subdir') !== '' ? ['--subdir', $field('subdir')] : []),
				'stdin' => null,
			],
			'protect' => ['args' => $line('protect', [$name, $field('state')]), 'stdin' => null],
			'user_add' => ['args' => $line('user-add', [$name, $field('username')]), 'stdin' => (string)($post['password'] ?? '') . "\n"],
			// Passwort neu setzen: derselbe Befehl, das Passwort erzeugt handlePost().
			'user_reset' => ['args' => $line('user-add', [$name, $field('username')]), 'stdin' => (string)($post['password'] ?? '') . "\n"],
			'user_del' => ['args' => $line('user-del', [$name, $field('username')]), 'stdin' => null],
			'ip_add' => ['args' => $line('ip-add', [$name, $field('cidr')]), 'stdin' => null],
			'ip_del' => ['args' => $line('ip-del', [$name, $field('cidr')]), 'stdin' => null],
			'ssl' => ['args' => $line('ssl', [$name, $field('state')]), 'stdin' => null],
			'cert_extend' => ['args' => $line('cert-extend', [$name]), 'stdin' => null],
			'php' => ['args' => $line('php', [$name, $field('state')]), 'stdin' => null],
			'www' => ['args' => $line('www', [$name, $field('mode')]), 'stdin' => null],
			// Leeres Feld = Docroot ist web/ selbst; das CLI erwartet dann kein Argument.
			'subdir' => [
				'args' => $line('subdir', array_merge([$name], $field('subdir') !== '' ? [$field('subdir')] : [])),
				'stdin' => null,
			],
			'remove' => ['args' => $line('remove', [$name]), 'stdin' => null],
			'restore' => ['args' => $line('restore', [$name]), 'stdin' => null],
			'email' => ['args' => $line('set', ['le_email', $field('le_email')]), 'stdin' => null],
			'conf' => ['args' => $line('conf', [$name]), 'stdin' => (string)($post['snippet'] ?? '')],
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
		// Beim Zurücksetzen gibt es kein Eingabefeld, aus dem ein Passwort käme – es
		// entsteht hier und wird danach einmal angezeigt.
		if ($action === 'user_reset') {
			$post['password'] = Password::generate()->value;
		}
		$command = self::commandFor($action, $post);
		if ($command === null) {
			return '/';
		}
		[$code, $output] = $this->runner->run($command['args'], $command['stdin']);
		// Abgelehnte Direktiven aufbewahren, damit der eingegebene Text beim nächsten
		// Seitenaufruf wieder im Textfeld steht. Ohne das wäre nach einem Tippfehler in
		// Zeile 3 die ganze Eingabe verloren.
		if ($action === 'conf') {
			if ($code === 0) {
				unset($this->session['draft']);
			} else {
				$this->session['draft'] = [
					'name' => (string)($post['name'] ?? ''),
					'text' => (string)($post['snippet'] ?? ''),
				];
			}
		}
		// Ein gesetztes Passwort einmal anzeigen: Es ist nirgends nachlesbar, in der
		// htpasswd-Datei steht nur der Hash.
		if (($action === 'user_add' || $action === 'user_reset') && $code === 0) {
			$this->session['credential'] = [
				'vhost' => (string)($post['name'] ?? ''),
				'user' => trim((string)($post['username'] ?? '')),
				'password' => (string)($post['password'] ?? ''),
			];
		}
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
