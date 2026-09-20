<?php
declare(strict_types=1);

/**
 * Verwaltungsoberfläche (Docroot /var/www/localhost-8080, nur 127.0.0.1:8080).
 *
 * Diese Datei ist das Template; alle Logik liegt in VhostAdmin\Web\AdminPage.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 19:13
 */

$bootstrap = is_file('/opt/vhost-admin/bootstrap.php') ? '/opt/vhost-admin/bootstrap.php' : dirname(__DIR__) . '/bootstrap.php';
require $bootstrap;

use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;
use VhostAdmin\Ssl\CurlAcmeReachability;
use VhostAdmin\Ssl\ReachabilityResult;
use VhostAdmin\Ssl\ReachabilityStatus;
use VhostAdmin\Ssl\ReachabilityChecker;
use VhostAdmin\Web\AdminPage;
use VhostAdmin\Web\CommandRunner;

session_start();
$config = Config::defaults();
$layout = new VhostLayout($config);
$page = new AdminPage(
	new VhostRepository(new Database($config)),
	new CommandRunner($config),
	$config,
	$layout,
	new ReachabilityChecker(new CurlAcmeReachability()),
	$_SESSION
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$page->isValidCsrf((string)($_POST['csrf'] ?? ''))) {
		http_response_code(403);
		exit('Ungültiges Formular-Token.');
	}
	set_time_limit(180);
	header('Location: ' . $page->handlePost($_POST));
	exit;
}

$csrf = $page->csrfToken();
$flash = $page->takeFlash();
$email = $page->letsEncryptEmail();
$view = isset($_GET['v']) ? $page->vhost((string)$_GET['v']) : null;
if (isset($_GET['v']) && $view === null) {
	http_response_code(404);
}

/**
 * HTML-Escaping für Ausgaben.
 */
function h(mixed $value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Kleines Inline-Formular mit einem Button (Aktion + versteckte Felder).
 *
 * @param array<string, string> $fields
 */
function form(string $action, array $fields, string $label, string $class = '', string $confirm = ''): string
{
	global $csrf;
	$html = '<form method="post" class="inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ')"' : '') . '>';
	$html .= '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">';
	foreach ($fields as $key => $value) {
		$html .= '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">';
	}
	return $html . '<button class="' . h($class) . '">' . h($label) . '</button></form>';
}

/**
 * Statuskennzeichen an/aus.
 */
function badge(bool $on, string $yes, string $no): string
{
	return '<span class="badge ' . ($on ? 'on' : 'off') . '">' . h($on ? $yes : $no) . '</span>';
}

/**
 * Erreichbarkeitsanzeige: grün, wenn Let's Encrypt den ACME-Pfad erreichen kann,
 * sonst rot. "entfällt" bleibt grau – ein localhost-Host soll nicht erreichbar sein.
 */
function reach(?ReachabilityResult $r): string
{
	if ($r === null) {
		return '<span class="muted">–</span>';
	}
	$class = match ($r->status) {
		ReachabilityStatus::Ok => 'on',
		ReachabilityStatus::NotApplicable => 'local',
		default => 'bad',
	};
	return '<span class="badge ' . $class . '" title="' . h($r->message) . '">' . h($r->status->label()) . '</span>';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>vhost-admin<?= $view ? ' – ' . h($view->name) : '' ?></title>
<style>
	:root { --bg:#f6f7f9; --card:#fff; --line:#e3e6ea; --txt:#1f2328; --mut:#6b7280; --acc:#2563eb; --ok:#15803d; --err:#b91c1c; }
	* { box-sizing:border-box; }
	body { margin:0; padding:1.5rem 1rem; background:var(--bg); color:var(--txt); font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
	main { max-width:960px; margin:0 auto; }
	header { display:flex; align-items:baseline; gap:1rem; margin-bottom:1.25rem; }
	header h1 { margin:0; font-size:1.4rem; }
	header a { color:var(--mut); text-decoration:none; }
	h2 { font-size:1.05rem; margin:0 0 .75rem; }
	.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; }
	table { width:100%; border-collapse:collapse; }
	th, td { text-align:left; padding:.5rem .4rem; border-bottom:1px solid var(--line); vertical-align:middle; }
	th { color:var(--mut); font-weight:600; font-size:.85rem; }
	tr:last-child td { border-bottom:0; }
	code { background:#eef0f3; padding:.1em .4em; border-radius:4px; font-size:.9em; }
	a { color:var(--acc); }
	.badge { display:inline-block; padding:.1em .55em; border-radius:999px; font-size:.78rem; font-weight:600; }
	.badge.on { background:#dcfce7; color:var(--ok); } .badge.off { background:#eef0f3; color:var(--mut); }
	.badge.local { background:#e0e7ff; color:#3730a3; }
	.badge.bad { background:#fee2e2; color:#b91c1c; }
	form.inline { display:inline; margin:0; }
	form.row { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
	input[type=text], input[type=password], input[type=email] { padding:.45rem .6rem; border:1px solid #cfd4da; border-radius:6px; font:inherit; min-width:12rem; }
	textarea { width:100%; padding:.5rem .6rem; border:1px solid #cfd4da; border-radius:6px; font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace; resize:vertical; }
	button { padding:.4rem .8rem; border:1px solid #cfd4da; border-radius:6px; background:#fff; font:inherit; cursor:pointer; }
	button.primary { background:var(--acc); border-color:var(--acc); color:#fff; }
	button.danger { color:var(--err); border-color:#f3c2c2; }
	button.small { padding:.15rem .5rem; font-size:.82rem; }
	.flash { padding:.75rem 1rem; border-radius:8px; margin-bottom:1rem; white-space:pre-wrap; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.85rem; }
	.flash.ok { background:#dcfce7; color:var(--ok); } .flash.err { background:#fee2e2; color:var(--err); }
	.muted { color:var(--mut); font-size:.88rem; }
	.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(20rem,1fr)); gap:1rem; }
	dl { display:grid; grid-template-columns:max-content 1fr; gap:.3rem 1rem; margin:0; }
	dt { color:var(--mut); } dd { margin:0; }
</style>
</head>
<body>
<main>
<header>
	<h1><a href="/">vhost-admin</a></h1>
	<?php if ($view): ?><span class="muted">/ <?= h($view->name) ?></span><?php endif ?>
</header>

<?php if ($flash): ?>
	<div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
<?php endif ?>

<?php if (isset($_GET['v']) && !$view): ?>
	<div class="card">Unbekannter vHost: <code><?= h($_GET['v']) ?></code></div>

<?php elseif ($view): $isDomain = !$view->isLocal(); $users = $page->users($view); $ips = $page->ips($view); $phpUser = $page->phpUser($view); $reach = $page->reachability([$view])[$view->name] ?? null; ?>
	<div class="card">
		<dl>
			<dt>Typ</dt><dd><?= $isDomain ? 'Domain (öffentlich)' : '<span class="badge local">localhost</span> nur lokal auf 127.0.0.1:' . h($view->port) ?></dd>
			<dt>Basisordner</dt><dd><code><?= h($layout->baseDir($view)) ?></code></dd>
			<dt>Docroot</dt><dd><code><?= h($layout->docroot($view)) ?></code></dd>
			<dt>Ordner</dt><dd><code><?= h($layout->webDir($view)) ?></code> (web), <code><?= h($layout->privateDir($view)) ?></code> (privat), <code><?= h($layout->logsDir($view)) ?></code> (Logs)</dd>
			<dt>Aufruf</dt><dd><a href="<?= h($isDomain ? ($view->ssl ? 'https' : 'http') . '://' . $view->name : 'http://localhost:' . $view->port) ?>/" target="_blank"><?= h($isDomain ? $view->name : 'localhost:' . $view->port) ?></a></dd>
			<dt>Angelegt</dt><dd><?= h($view->createdAt) ?> UTC</dd>
		</dl>
	</div>

	<div class="grid">
		<div class="card">
			<h2>Verzeichnisschutz <?= badge($view->protect, 'aktiv', 'aus') ?></h2>
			<?php if ($view->protect): ?>
				<p class="muted">Zugriff nur mit freigegebener IP <em>oder</em> Benutzer/Passwort. Ohne Einträge ist der Ordner komplett gesperrt.</p>
				<?= form('protect', ['name' => $view->name, 'state' => 'off'], 'Schutz abschalten', 'danger', 'Verzeichnisschutz wirklich abschalten? Der Docroot ist dann frei erreichbar.') ?>
			<?php else: ?>
				<p class="muted">Der Docroot ist ohne Anmeldung erreichbar.</p>
				<?= form('protect', ['name' => $view->name, 'state' => 'on'], 'Schutz einschalten', 'primary') ?>
			<?php endif ?>

			<h2 style="margin-top:1.25rem">Benutzer</h2>
			<?php if ($users): ?>
				<table><?php foreach ($users as $u): ?>
					<tr><td><code><?= h($u['username']) ?></code></td>
						<td style="text-align:right"><?= form('user_del', ['name' => $view->name, 'username' => $u['username']], 'entfernen', 'small danger', 'Benutzer ' . $u['username'] . ' entfernen?') ?></td></tr>
				<?php endforeach ?></table>
			<?php else: ?><p class="muted">Keine Benutzer.</p><?php endif ?>
			<form method="post" class="row" style="margin-top:.5rem">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="user_add"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<input type="text" name="username" placeholder="Benutzername" required autocomplete="off">
				<input type="password" name="password" placeholder="Passwort" required autocomplete="new-password">
				<button class="primary">Anlegen / Passwort setzen</button>
			</form>

			<h2 style="margin-top:1.25rem">Freigegebene IPs</h2>
			<?php if ($ips): ?>
				<table><?php foreach ($ips as $ip): ?>
					<tr><td><code><?= h($ip) ?></code></td>
						<td style="text-align:right"><?= form('ip_del', ['name' => $view->name, 'cidr' => $ip], 'entfernen', 'small danger', 'IP ' . $ip . ' entfernen?') ?></td></tr>
				<?php endforeach ?></table>
			<?php else: ?><p class="muted">Keine IPs.</p><?php endif ?>
			<form method="post" class="row" style="margin-top:.5rem">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="ip_add"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<input type="text" name="cidr" placeholder="IP oder CIDR, z.B. 203.0.113.5 oder 10.0.0.0/8" required style="min-width:20rem">
				<button class="primary">Freigeben</button>
			</form>
		</div>

		<div>
			<div class="card">
				<h2>PHP <?= badge($view->php, 'aktiv', 'aus') ?></h2>
				<?php if ($view->php): ?>
					<p class="muted">PHP läuft in einem eigenen Pool als Benutzer <code><?= h($phpUser) ?></code>; Fehler landen in <code>logs/php.log</code>. Nur dieser Host kommt an seine Dateien.</p>
					<?= form('php', ['name' => $view->name, 'state' => 'off'], 'PHP abschalten', 'danger', 'PHP für ' . $view->name . ' abschalten? .php-Dateien werden danach mit 404 abgewiesen.') ?>
				<?php else: ?>
					<p class="muted">Schaltet einen eigenen php-fpm-Pool mit eigenem Systembenutzer frei. Ohne PHP werden <code>.php</code>-Dateien mit 404 abgewiesen, nie als Text ausgeliefert.</p>
					<?= form('php', ['name' => $view->name, 'state' => 'on'], 'PHP einschalten', 'primary') ?>
				<?php endif ?>
			</div>

			<?php if ($isDomain): ?>
			<div class="card">
				<h2>Erreichbarkeit <?= reach($reach) ?></h2>
				<p class="muted"><?= h($reach?->message ?? 'Nicht geprüft.') ?></p>
				<p class="muted">Geprüft wird bei jedem Aufruf dieser Seite derselbe Pfad, den Let's Encrypt für die Ausstellung abfragt: <code>http://<?= h($view->name) ?>/.well-known/acme-challenge/</code>. Der Test läuft von diesem Server aus – „erreichbar“ ist damit ein starkes Indiz, aber keine Garantie für jedes fremde Netz.</p>
			</div>

			<div class="card">
				<h2>Let's Encrypt <?= badge($view->ssl, 'HTTPS aktiv', 'aus') ?></h2>
				<?php if ($view->ssl): ?>
					<p class="muted">HTTP wird auf HTTPS umgeleitet. Verlängerung übernimmt der certbot-Timer automatisch.</p>
					<?= form('ssl', ['name' => $view->name, 'state' => 'off'], 'HTTPS abschalten', 'danger', 'HTTPS für ' . $view->name . ' abschalten? Das Zertifikat bleibt gespeichert.') ?>
				<?php elseif (!$email): ?>
					<p class="muted">Bitte zuerst auf der Übersicht eine Let's-Encrypt-E-Mail hinterlegen.</p>
					<button disabled>Zertifikat holen</button>
				<?php elseif ($reach === null || !$reach->isOk()): ?>
					<p class="muted">Solange die Domain nicht erreichbar ist, würde die Ausstellung scheitern – und Let's Encrypt zählt jeden Fehlversuch gegen das Kontingent der Domain (fünf pro Stunde). Zuerst DNS und Port 80 klären.</p>
					<button disabled>Zertifikat holen</button>
				<?php else: ?>
					<p class="muted">Die Erreichbarkeit ist geprüft. Der Vorgang dauert einige Sekunden.</p>
					<?= form('ssl', ['name' => $view->name, 'state' => 'on'], 'Zertifikat holen & HTTPS einschalten', 'primary') ?>
				<?php endif ?>
			</div>
			<?php endif ?>

			<div class="card">
				<h2>Eigene nginx-Direktiven</h2>
				<p class="muted">Wird als <code><?= h($layout->confFile($view)) ?></code> gespeichert und in den server-Block eingebunden. Erlaubt sind unter anderem <code>client_max_body_size</code>, <code>expires</code>, <code>add_header</code>, <code>gzip*</code>, <code>rewrite</code>, <code>return</code>, <code>try_files</code>, <code>error_page</code>, <code>proxy_*</code> und <code>location</code>-Blöcke. Direktiven, die Docroot, Zertifikat oder Verzeichnisschutz betreffen, werden abgelehnt.</p>
				<form method="post">
					<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="conf"><input type="hidden" name="name" value="<?= h($view->name) ?>">
					<textarea name="snippet" rows="10" spellcheck="false"><?= h($page->snippet($view)) ?></textarea>
					<button class="primary">Übernehmen</button>
				</form>
				<p class="muted">Leeres Feld entfernt die Datei. Schlägt der nginx-Test fehl, bleibt die bisherige Fassung aktiv und die Meldung erscheint oben.</p>
			</div>

			<div class="card">
				<h2>Entfernen</h2>
				<p class="muted">Entfernt nginx-Konfiguration und Datenbankeintrag. Die Dateien unter <code><?= h($layout->baseDir($view)) ?></code> bleiben erhalten.</p>
				<?php if ($view->isPendingDeletion()): $due = $view->deletionDueAt($config->removalGraceMinutes); ?>
					<p><span class="badge bad">gesperrt</span> Dieser vHost wird nicht mehr ausgeliefert.</p>
					<p class="muted">Endgültig entfernt am <strong><?= h($due?->format('d.m.Y H:i')) ?> UTC</strong>. Bis dahin lässt sich das zurücknehmen; die Dateien unter <code><?= h($layout->baseDir($view)) ?></code> bleiben ohnehin erhalten.</p>
					<?= form('restore', ['name' => $view->name], 'Entfernen zurücknehmen', 'primary') ?>
				<?php else: ?>
					<p class="muted">Der vHost wird sofort gesperrt und erst nach <?= h($config->removalGraceMinutes) ?> Minuten endgültig entfernt. Bis dahin lässt sich das zurücknehmen. Dateien unter <code><?= h($layout->baseDir($view)) ?></code> werden nie gelöscht.</p>
					<?= form('remove', ['name' => $view->name], 'vHost entfernen', 'danger', $view->name . " wirklich entfernen?\n\nDer vHost wird sofort gesperrt und ist dann nicht mehr erreichbar. Endgültig entfernt wird er erst in " . $config->removalGraceMinutes . ' Minuten - bis dahin können Sie das zurücknehmen.') ?>
				<?php endif ?>
			</div>
		</div>
	</div>

<?php else: ?>
	<div class="card">
		<h2>vHosts</h2>
		<?php $vhosts = $page->vhosts(); $reachable = $page->reachability($vhosts); ?>
		<table>
			<tr><th>Name</th><th>Docroot</th><th>Schutz</th><th>PHP</th><th>HTTPS</th><th>Erreichbar</th></tr>
			<tr>
				<td>localhost:<?= h($config->adminPort) ?> <span class="badge local">lokal</span> <span class="muted">diese Oberfläche</span></td>
				<td><code><?= h(__DIR__) ?></code></td>
				<td><span class="muted">–</span></td>
				<td><span class="muted">–</span></td>
				<td><span class="muted">–</span></td>
				<td><span class="muted">–</span></td>
			</tr>
			<?php foreach ($vhosts as $v): ?>
			<tr>
				<td><a href="/?v=<?= h(rawurlencode($v->name)) ?>"><?= h($v->name) ?></a>
					<?php if ($v->isLocal()): ?> <span class="badge local">lokal</span><?php endif ?>
					<?php if ($v->isPendingDeletion()): ?> <span class="badge bad" title="Endgültig entfernt am <?= h($v->deletionDueAt($config->removalGraceMinutes)?->format('d.m.Y H:i')) ?> UTC">wird entfernt</span><?php endif ?></td>
				<td><code><?= h($layout->docroot($v)) ?></code></td>
				<td><?= badge($v->protect, 'aktiv', 'aus') ?></td>
				<td><?= badge($v->php, 'aktiv', 'aus') ?></td>
				<td><?= !$v->isLocal() ? badge($v->ssl, 'aktiv', 'aus') : '<span class="muted">–</span>' ?></td>
				<td><?= reach($reachable[$v->name] ?? null) ?></td>
			</tr>
			<?php endforeach ?>
		</table>
	</div>

	<div class="grid">
		<div class="card">
			<h2>Neue Domain</h2>
			<form method="post" class="row">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="create">
				<input type="text" name="domain" placeholder="example.com" required autocomplete="off" pattern="[A-Za-z0-9.-]+\.[A-Za-z]{2,}">
				<input type="text" name="subdir" placeholder="Unterordner (optional), z.B. public" autocomplete="off">
				<button class="primary">Anlegen</button>
			</form>
			<p class="muted">Legt <code>/var/www/&lt;domain&gt;/[unterordner]</code> mit einer Start-Seite an. Der Docroot ist zunächst gesperrt (Verzeichnisschutz ohne Benutzer/IP).<br>
			localhost-Hosts werden per CLI angelegt: <code>sudo vhost add-local &lt;port&gt;</code></p>
		</div>
		<div class="card">
			<h2>Einstellungen</h2>
			<form method="post" class="row">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="email">
				<label for="le_email" class="muted">Let's-Encrypt-E-Mail</label>
				<input type="email" id="le_email" name="le_email" value="<?= h($email) ?>" placeholder="admin@example.com">
				<button class="primary">Speichern</button>
			</form>
			<p class="muted">Wird bei Let's Encrypt für Ablauf-Warnungen registriert. Ohne Adresse ist der HTTPS-Schalter deaktiviert.</p>
		</div>
	</div>
<?php endif ?>
</main>
</body>
</html>
