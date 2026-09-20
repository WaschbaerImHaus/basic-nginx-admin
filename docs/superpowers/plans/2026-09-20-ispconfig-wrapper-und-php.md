# ISPConfig-artiger Wrapper, Sperrliste und PHP pro vHost

Stand: 2026-09-20 · Auslöser: Nutzerwunsch, eine conf per copy-paste aus einem anderen
Projekt übernehmen zu können, plus "klar soll das auch php können".

## 1. Wrapper wie bei ISPConfig

Der generierte Teil wächst, der eigene Bereich rutscht ans Ende des `server`-Blocks,
zwischen Marker. Damit ist ein Fragment aus einer ISPConfig-conf direkt einsetzbar.

Neu im generierten Teil:
- `index index.html index.htm index.php`
- Dotfile-Sperre `location ~ /\.(?!well-known/) { deny all; access_log off; log_not_found off; }`
- `location = /favicon.ico` und `location = /robots.txt`, jeweils ohne Logging
- PHP-Block (siehe Abschnitt 3), nur wenn PHP für den Host eingeschaltet ist
- optionaler `include <conf>/rewrites[.]conf;` (Klammerform: Datei darf fehlen)
- am Ende: `# >>>>` / `# ab hier eigene Direktiven` / `include <conf>/*.conf;` / `# <<<<`

**Wichtige Änderung:** Das bisherige `location / { try_files $uri $uri/ =404; }` wird zu
einem `try_files $uri $uri/ =404;` auf Server-Ebene. Grund: Sonst kollidiert ein
eingefügtes eigenes `location / { … }` mit "duplicate location" – genau der Fall, den
copy-paste auslöst. ISPConfig lässt `location /` aus demselben Grund frei.

Nutzerentscheidung: "übernimm den wrapper so dass er zu diesem system passt." Also werden
die Blöcke der Beispiel-conf übernommen und auf unsere Pfade gezogen:

- HTTP/3 und QUIC bei Hosts mit Zertifikat: `http3 on;`, `listen 443 quic;` und der
  `Alt-Svc`-Header. Dieses nginx kann das (`nginx -V` zeigt `--with-http_v3_module`,
  Version 1.28.3), sonst würde `nginx -t` scheitern.
- `ssl_certificate` zeigt auf die Symlinks in `<basis>/cert/` statt direkt nach
  `/etc/letsencrypt/live/` – dafür ist der Ordner da.
- Ist PHP für den Host aus, wird wie bei ISPConfig `location ~ \.php$ { return 404; }`
  erzeugt, damit PHP-Dateien nicht im Klartext ausgeliefert werden.
- Nicht übernommen, weil ISPConfig-eigen: die `th_uafilter`-Abfrage, der
  `acme-challenge`-`break`-Block (unser `location ^~` erledigt das) und die
  `rewrite ^ https://`-Weiche im selben Block – wir leiten im eigenen :80-Block mit
  `return 301` um, was billiger ist als eine `if`-Auswertung pro Anfrage.
- gzip kommt nicht dazu: steht auch in der Beispiel-conf nicht und gehört nach `nginx.conf`.

## 2. Positivliste wird Sperrliste

`NginxSnippet` behält den nginx-treuen Tokenizer (ein Wortvergleich reicht nicht, siehe
C1 vom 2026-09-20), dreht aber die Liste um: erlaubt ist alles, was nicht ausdrücklich
gesperrt ist. Gesperrt bleibt nur, was aus dem vHost herausführt:

| Direktive | Regel |
|---|---|
| `root`, `alias` | Pfad muss innerhalb des Basisordners des vHosts liegen |
| `include` | nur Dateien in `<basis>/conf/` |
| `access_log`, `error_log` | nur Dateien in `<basis>/logs/` (oder `off`) |
| `auth_basic`, `auth_basic_user_file`, `satisfy` | ganz gesperrt – der Verzeichnisschutz wird über die Oberfläche verwaltet, `auth_basic off` würde ihn aufheben |
| `proxy_pass`, `fastcgi_pass`, `uwsgi_pass`, `scgi_pass`, `grpc_pass`, `memcached_pass` | Ziel darf nicht dieser Rechner sein (bestehende Adressprüfung); Unix-Socket nur der eigene FPM-Socket des Hosts |
| `fastcgi_param SCRIPT_FILENAME`/`DOCUMENT_ROOT` | Pfad muss im vHost liegen, sonst liesse sich der PHP-Code der Oberfläche ausführen |
| `dav_methods`, `dav_access` | Schreibzugriff über HTTP |
| `perl*`, `lua*`, `*_by_lua*`, `js_*`, `load_module` | Code-Ausführung im nginx-Prozess |

Ehrlich dazu: Eine Sperrliste ist prinzipiell schwächer als eine Positivliste – nginx hat
hunderte Direktiven, und Module bringen weitere mit. Zweite Verteidigungslinie bleibt
`nginx -t` mit Rücknahme (schon vorhanden). Das gehört so in SECURITY_RISKS.md.

## 3. PHP-FPM pro vHost mit eigenem Benutzer

**Warum nicht der vorhandene Pool:** Der läuft als `www-data`, und `www-data` darf per
sudoers `/usr/local/sbin/vhost` als root aufrufen. PHP einer öffentlichen Domain in diesem
Pool = root auf dem LXC. Deshalb pro vHost ein eigener Pool mit eigenem Benutzer; die
Oberfläche behält ihren Pool als `www-data`.

- Systembenutzer `web<id>` (id aus der Datenbank – kurz, stabil, gültig; ISPConfig-Schema),
  `--system --no-create-home --shell /usr/sbin/nologin`, eigene Gruppe
- Pool `/etc/php/<ver>/fpm/pool.d/vhost-<slug>.conf`, Socket `/run/php/vhost-<slug>.sock`,
  `listen.owner = www-data` (nginx muss verbinden), `user`/`group = web<id>`
- `php_admin_value[open_basedir]` auf Basisordner + eigenes tmp – PHP kommt nicht an die
  Dateien anderer Hosts und nicht an die der Oberfläche
- `php_admin_value[error_log]` in `<basis>/logs/php.log`
- Neue Spalte `vhosts.php` (0/1), CLI `vhost php <domain> on|off`, Schalter in der Oberfläche
- Eigener `FpmReloaderInterface` + `SystemdFpmReloader` nach dem Muster des nginx-Reloaders

## 4. Rechte

`web/` wechselt bei eingeschaltetem PHP von `<wwwOwner>:www-data 02775` auf
`web<id>:www-data 02750`: PHP schreibt als eigener Benutzer, nginx liest über die Gruppe,
`www-data` verliert das Schreibrecht (strenger als heute). Beim Einschalten von PHP wird
`web/` samt Inhalt umgeschrieben – **vorher Sicherung wie bei der Layout-Migration**
(`LayoutMigrator::backup()` wiederverwenden), weil das echte Inhalte anfasst.

## 5. Reihenfolge (TDD, Tests zuerst)

1. `NginxSnippet`: Sperrliste statt Positivliste, Pfadprüfungen gegen den vHost
2. `ConfigRenderer`: neuer Wrapper, Marker, `try_files` auf Server-Ebene
3. Schema + `Vhost`/`VhostRepository`: Spalte `php`
4. `Php\PoolRenderer`, `Php\SystemdFpmReloader`, Benutzeranlage in `VhostService`
5. `VhostLayout`: Rechte für `web/` bei PHP, Sicherung beim Umschalten
6. CLI `php on|off` + Oberflächenschalter
7. `install.sh`: eigener Pool für die Oberfläche trennen, php-fpm-Version ermitteln
8. Doku (README, FEATURES, BUGS, SECURITY_RISKS, .claude/CLAUDE.md, dev-log), Build 3

## Offen / bewusst nicht enthalten
- gzip-Vorgaben, `rewrites[.]conf` als eigenes Feld in der Oberfläche (die Datei wird
  eingebunden, gepflegt wird sie vorerst per Hand bzw. über das bestehende Snippet-Feld)
- Mehrere PHP-Versionen pro Host (nur die installierte wird genutzt)
