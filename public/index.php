<?php
declare(strict_types=1);

require '/opt/vhost-admin/lib/db.php';

session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function run_vhost(array $args, ?string $stdin = null): array
{
    $cmd = array_merge(['sudo', '-n', cfg()['vhost_bin']], $args);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [1, 'Konnte vhost nicht starten.'];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), trim((string)$out)];
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Ungültiges Formular-Token.');
    }
    set_time_limit(180);
    $a = (string)($_POST['action'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $p = fn(string $k): string => trim((string)($_POST[$k] ?? ''));
    $stdin = null;
    switch ($a) {
        case 'create':
            $args = ['add', $p('domain')];
            if ($p('subdir') !== '') {
                array_push($args, '--subdir', $p('subdir'));
            }
            break;
        case 'protect':
            $args = ['protect', $name, $p('state')];
            break;
        case 'user_add':
            $args = ['user-add', $name, $p('username')];
            $stdin = (string)($_POST['password'] ?? '') . "\n";
            break;
        case 'user_del':
            $args = ['user-del', $name, $p('username')];
            break;
        case 'ip_add':
            $args = ['ip-add', $name, $p('cidr')];
            break;
        case 'ip_del':
            $args = ['ip-del', $name, $p('cidr')];
            break;
        case 'ssl':
            $args = ['ssl', $name, $p('state')];
            break;
        case 'remove':
            $args = ['remove', $name];
            break;
        case 'email':
            $args = ['set', 'le_email', $p('le_email')];
            break;
        default:
            redirect('/');
    }
    [$rc, $out] = run_vhost($args, $stdin);
    $_SESSION['flash'] = [$rc === 0 ? 'ok' : 'err', $out !== '' ? $out : ($rc === 0 ? 'Erledigt.' : "Fehler (Exit $rc)")];
    if ($a === 'create' && $rc === 0) {
        redirect('/?v=' . rawurlencode(strtolower($p('domain'))));
    }
    if (in_array($a, ['create', 'remove', 'email'], true)) {
        redirect('/');
    }
    redirect('/?v=' . rawurlencode($name));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$email = setting('le_email');
$view = null;
if (isset($_GET['v'])) {
    $view = vhost_by_name((string)$_GET['v']);
    if (!$view) {
        http_response_code(404);
    }
}

function form(string $action, array $fields, string $label, string $class = '', string $confirm = ''): string
{
    global $csrf;
    $h = '<form method="post" class="inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ')"' : '') . '>';
    $h .= '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return $h . '<button class="' . h($class) . '">' . h($label) . '</button></form>';
}

function badge(bool $on, string $yes, string $no): string
{
    return '<span class="badge ' . ($on ? 'on' : 'off') . '">' . h($on ? $yes : $no) . '</span>';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>vhost-admin<?= $view ? ' – ' . h($view['name']) : '' ?></title>
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
  form.inline { display:inline; margin:0; }
  form.row { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
  input[type=text], input[type=password], input[type=email] { padding:.45rem .6rem; border:1px solid #cfd4da; border-radius:6px; font:inherit; min-width:12rem; }
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
  <?php if ($view): ?><span class="muted">/ <?= h($view['name']) ?></span><?php endif ?>
</header>

<?php if ($flash): ?>
  <div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
<?php endif ?>

<?php if (isset($_GET['v']) && !$view): ?>
  <div class="card">Unbekannter vHost: <code><?= h($_GET['v']) ?></code></div>

<?php elseif ($view): $isDomain = $view['kind'] === 'domain'; $users = vhost_users((int)$view['id']); $ips = vhost_ips((int)$view['id']); ?>
  <div class="card">
    <dl>
      <dt>Typ</dt><dd><?= $isDomain ? 'Domain (öffentlich)' : '<span class="badge local">localhost</span> nur lokal auf 127.0.0.1:' . h($view['port']) ?></dd>
      <dt>Basisordner</dt><dd><code><?= h(vh_basedir($view)) ?></code></dd>
      <dt>Docroot</dt><dd><code><?= h(vh_docroot($view)) ?></code></dd>
      <dt>Aufruf</dt><dd><a href="<?= h($isDomain ? ($view['ssl'] ? 'https' : 'http') . '://' . $view['name'] : 'http://localhost:' . $view['port']) ?>/" target="_blank"><?= h($isDomain ? $view['name'] : 'localhost:' . $view['port']) ?></a></dd>
      <dt>Angelegt</dt><dd><?= h($view['created_at']) ?> UTC</dd>
    </dl>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Verzeichnisschutz <?= badge((bool)$view['protect'], 'aktiv', 'aus') ?></h2>
      <?php if ($view['protect']): ?>
        <p class="muted">Zugriff nur mit freigegebener IP <em>oder</em> Benutzer/Passwort. Ohne Einträge ist der Ordner komplett gesperrt.</p>
        <?= form('protect', ['name' => $view['name'], 'state' => 'off'], 'Schutz abschalten', 'danger', 'Verzeichnisschutz wirklich abschalten? Der Docroot ist dann frei erreichbar.') ?>
      <?php else: ?>
        <p class="muted">Der Docroot ist ohne Anmeldung erreichbar.</p>
        <?= form('protect', ['name' => $view['name'], 'state' => 'on'], 'Schutz einschalten', 'primary') ?>
      <?php endif ?>

      <h2 style="margin-top:1.25rem">Benutzer</h2>
      <?php if ($users): ?>
        <table><?php foreach ($users as $u): ?>
          <tr><td><code><?= h($u['username']) ?></code></td>
              <td style="text-align:right"><?= form('user_del', ['name' => $view['name'], 'username' => $u['username']], 'entfernen', 'small danger', 'Benutzer ' . $u['username'] . ' entfernen?') ?></td></tr>
        <?php endforeach ?></table>
      <?php else: ?><p class="muted">Keine Benutzer.</p><?php endif ?>
      <form method="post" class="row" style="margin-top:.5rem">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="user_add"><input type="hidden" name="name" value="<?= h($view['name']) ?>">
        <input type="text" name="username" placeholder="Benutzername" required autocomplete="off">
        <input type="password" name="password" placeholder="Passwort" required autocomplete="new-password">
        <button class="primary">Anlegen / Passwort setzen</button>
      </form>

      <h2 style="margin-top:1.25rem">Freigegebene IPs</h2>
      <?php if ($ips): ?>
        <table><?php foreach ($ips as $ip): ?>
          <tr><td><code><?= h($ip['cidr']) ?></code></td>
              <td style="text-align:right"><?= form('ip_del', ['name' => $view['name'], 'cidr' => $ip['cidr']], 'entfernen', 'small danger', 'IP ' . $ip['cidr'] . ' entfernen?') ?></td></tr>
        <?php endforeach ?></table>
      <?php else: ?><p class="muted">Keine IPs.</p><?php endif ?>
      <form method="post" class="row" style="margin-top:.5rem">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="ip_add"><input type="hidden" name="name" value="<?= h($view['name']) ?>">
        <input type="text" name="cidr" placeholder="IP oder CIDR, z.B. 203.0.113.5 oder 10.0.0.0/8" required style="min-width:20rem">
        <button class="primary">Freigeben</button>
      </form>
    </div>

    <div>
      <?php if ($isDomain): ?>
      <div class="card">
        <h2>Let's Encrypt <?= badge((bool)$view['ssl'], 'HTTPS aktiv', 'aus') ?></h2>
        <?php if ($view['ssl']): ?>
          <p class="muted">HTTP wird auf HTTPS umgeleitet. Verlängerung übernimmt der certbot-Timer automatisch.</p>
          <?= form('ssl', ['name' => $view['name'], 'state' => 'off'], 'HTTPS abschalten', 'danger', 'HTTPS für ' . $view['name'] . ' abschalten? Das Zertifikat bleibt gespeichert.') ?>
        <?php elseif (!$email): ?>
          <p class="muted">Bitte zuerst auf der Übersicht eine Let's-Encrypt-E-Mail hinterlegen.</p>
          <button disabled>Zertifikat holen</button>
        <?php else: ?>
          <p class="muted">Die Domain muss per DNS auf diesen Server zeigen und Port 80 muss aus dem Internet erreichbar sein. Der Vorgang dauert einige Sekunden.</p>
          <?= form('ssl', ['name' => $view['name'], 'state' => 'on'], 'Zertifikat holen & HTTPS einschalten', 'primary') ?>
        <?php endif ?>
      </div>
      <?php endif ?>

      <div class="card">
        <h2>Entfernen</h2>
        <p class="muted">Entfernt nginx-Konfiguration und Datenbankeintrag. Die Dateien unter <code><?= h(vh_basedir($view)) ?></code> bleiben erhalten.</p>
        <?= form('remove', ['name' => $view['name']], 'vHost entfernen', 'danger', $view['name'] . ' wirklich entfernen?') ?>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <h2>vHosts</h2>
    <table>
      <tr><th>Name</th><th>Docroot</th><th>Schutz</th><th>HTTPS</th></tr>
      <tr>
        <td>localhost:<?= h(cfg()['admin_port']) ?> <span class="badge local">lokal</span> <span class="muted">diese Oberfläche</span></td>
        <td><code><?= h(__DIR__) ?></code></td>
        <td><span class="muted">–</span></td>
        <td><span class="muted">–</span></td>
      </tr>
      <?php foreach (vhost_all() as $v): ?>
      <tr>
        <td><a href="/?v=<?= h(rawurlencode($v['name'])) ?>"><?= h($v['name']) ?></a>
            <?php if ($v['kind'] === 'localhost'): ?> <span class="badge local">lokal</span><?php endif ?></td>
        <td><code><?= h(vh_docroot($v)) ?></code></td>
        <td><?= badge((bool)$v['protect'], 'aktiv', 'aus') ?></td>
        <td><?= $v['kind'] === 'domain' ? badge((bool)$v['ssl'], 'aktiv', 'aus') : '<span class="muted">–</span>' ?></td>
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
