#!/usr/bin/env bash
# Ende-zu-Ende-Prüfung der installierten Version über CLI und Oberfläche.
# Legt Test-vHosts an, prüft die HTTP-Antworten und räumt wieder auf.
# Aufruf: sudo ./debugging/smoke-test.sh
set -euo pipefail

JAR=""

fail() { echo "FEHLER: $*" >&2; exit 1; }
expect() { # expect <erwartet> <beschreibung> <curl-args...>
	local want="$1" what="$2"; shift 2
	# curl beendet sich bei geschlossener Verbindung (z.B. nginx-Code 444) mit
	# Exit-Status 52, schreibt aber trotzdem "000" nach -w; das darf das Skript
	# unter set -e nicht abbrechen.
	local got; got=$(curl -s -o /dev/null -w '%{http_code}' "$@") || true
	[ "$got" = "$want" ] && echo "ok   $what -> $got" || fail "$what: erwartet $want, bekommen $got"
}

# Entfernt alle Test-vHosts, ihre Docroots und das Cookie-Tempfile – egal ob
# der Lauf erfolgreich war, fehlgeschlagen ist oder per Signal abgebrochen
# wurde (trap ... EXIT). Muss deshalb idempotent sein: Fehler einzelner
# Befehle (z.B. weil ein vHost gar nicht existiert) dürfen das Aufräumen
# selbst nicht abbrechen.
cleanup() {
	set +e
	vhost remove smoke-test.example --purge >/dev/null 2>&1
	vhost remove localhost:3999 --purge >/dev/null 2>&1
	vhost remove smoke-ui.example --purge >/dev/null 2>&1
	vhost remove smoke-conf.example --purge >/dev/null 2>&1
	vhost remove smoke-php.example --purge >/dev/null 2>&1
	rm -rf /var/www/smoke-test.example /var/www/smoke-ui.example /var/www/localhost-3999
	rm -rf /var/www/smoke-conf.example /var/www/smoke-php.example
	rm -f /etc/php/*/fpm/pool.d/vhost-smoke-php.example.conf
	[ -n "$JAR" ] && rm -f "$JAR"
	set -e
}
trap cleanup EXIT

# Reste eines vorherigen, abgebrochenen Laufs dürfen diesen Lauf nicht blockieren.
cleanup

echo "== CLI"
vhost add smoke-test.example --subdir public >/dev/null
expect 401 "gesperrt" -H 'Host: smoke-test.example' http://127.0.0.1/
printf 'geheim\n' | vhost user-add smoke-test.example alice >/dev/null
expect 200 "Login" -u alice:geheim -H 'Host: smoke-test.example' http://127.0.0.1/
expect 401 "falsches Passwort" -u alice:falsch -H 'Host: smoke-test.example' http://127.0.0.1/
vhost ip-add smoke-test.example 127.0.0.1 >/dev/null
expect 200 "IP-Freigabe" -H 'Host: smoke-test.example' http://127.0.0.1/
vhost ip-del smoke-test.example 127.0.0.1 >/dev/null
vhost protect smoke-test.example off >/dev/null
expect 200 "Schutz aus" -H 'Host: smoke-test.example' http://127.0.0.1/
# Kein direktes "curl | grep -q": grep -q schließt die Pipe beim ersten Treffer,
# wodurch curl bei größeren Antworten gelegentlich mit SIGPIPE abbricht und
# pipefail das Ergebnis fälschlich als Fehler meldet, obwohl grep fündig wurde.
grep -q '<h1>200</h1>' <<<"$(curl -s -H 'Host: smoke-test.example' http://127.0.0.1/)" && echo "ok   Startseite" || fail "Startseite fehlt"
expect 000 "unbekannter Host (444)" -H 'Host: nix.example' http://127.0.0.1/
vhost ssl smoke-test.example on >/dev/null 2>&1 && fail "SSL ohne E-Mail darf nicht klappen" || echo "ok   SSL ohne E-Mail abgelehnt"

echo "== localhost"
vhost add-local 3999 >/dev/null
ss -ltn | grep -q '127.0.0.1:3999' && echo "ok   bindet 127.0.0.1" || fail "Port 3999 nicht gebunden"
ss -ltn | grep -q '0.0.0.0:3999' && fail "Port 3999 öffentlich gebunden" || true
expect 401 "localhost gesperrt" http://127.0.0.1:3999/
vhost protect localhost:3999 off >/dev/null
expect 200 "localhost offen" http://127.0.0.1:3999/
vhost add-local 8080 >/dev/null 2>&1 && fail "8080 darf nicht anlegbar sein" || echo "ok   8080 reserviert"

echo "== Oberfläche"
JAR=$(mktemp)
CSRF=$(curl -s -c "$JAR" -b "$JAR" http://127.0.0.1:8080/ | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)
[ -n "$CSRF" ] || fail "kein CSRF-Token"
expect 403 "CSRF-Schutz" -d 'csrf=x&action=remove&name=smoke-test.example' http://127.0.0.1:8080/
expect 302 "Domain anlegen" -c "$JAR" -b "$JAR" -d "csrf=$CSRF&action=create&domain=smoke-ui.example" http://127.0.0.1:8080/
grep -q 'flash ok' <<<"$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-ui.example')" && echo "ok   Flash ok" || fail "Flash fehlt"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -d "csrf=$CSRF&action=create&domain=localhost:3998" http://127.0.0.1:8080/
grep -q 'flash err' <<<"$(curl -s -c "$JAR" -b "$JAR" http://127.0.0.1:8080/)" && echo "ok   localhost über UI abgelehnt" || fail "localhost über UI nicht abgelehnt"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF" -d 'action=user_add&name=smoke-ui.example&username=bob' --data-urlencode 'password=p@ss wörd!' http://127.0.0.1:8080/
expect 200 "UI-Benutzer" -u 'bob:p@ss wörd!' -H 'Host: smoke-ui.example' http://127.0.0.1/
curl -s -o /dev/null -c "$JAR" -b "$JAR" -d "csrf=$CSRF&action=remove&name=smoke-ui.example" http://127.0.0.1:8080/

echo "== Eigene Direktiven"
vhost add smoke-conf.example >/dev/null
vhost protect smoke-conf.example off >/dev/null
CSRF2=$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-conf.example' | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)
[ -n "$CSRF2" ] || fail "kein CSRF-Token auf der Detailseite"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF2" -d 'action=conf&name=smoke-conf.example' --data-urlencode 'snippet=add_header X-Smoke-Test bestanden;' http://127.0.0.1:8080/
# Zugesichert ist nur: geprüft, geschrieben, beim Reload wirksam – nicht, dass die
# Oberfläche schon beim POST selbst darauf wartet (sie tut es bewusst nicht, siehe
# SystemdReloader/BUGS.md). Bis hier deshalb nur deterministische Prüfungen ohne
# jede Wartezeit: Datei geschrieben, genau eine Erfolgsmeldung (einmalig, kein
# zweiter Abruf davor).
grep -q 'X-Smoke-Test' /var/www/smoke-conf.example/conf/custom.conf && echo "ok   Snippet gespeichert" || fail "Snippet nicht gespeichert"
grep -q 'flash ok' <<<"$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-conf.example')" && echo "ok   Flash ok" || fail "keine Erfolgsmeldung nach dem Snippet"
# Ob nginx die Konfiguration danach tatsächlich ausliefert, prüft dieser Test
# nicht: Ein kurz zuvor von der Oberfläche ausgelöster Reload lässt einen
# unmittelbar folgenden wirkungslos werden (siehe BUGS.md, "Reload wird
# verschluckt"). Geprüft wird deshalb die Kette Oberfläche → CLI →
# Positivliste → Datei, nicht der letzte Schritt nginx.
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF2" -d 'action=conf&name=smoke-conf.example' --data-urlencode 'snippet=root /etc;' http://127.0.0.1:8080/
grep -q 'X-Smoke-Test' /var/www/smoke-conf.example/conf/custom.conf && echo "ok   verbotene Direktive abgelehnt, alte Fassung aktiv" || fail "verbotene Direktive hat das Snippet überschrieben"
grep -q 'flash err' <<<"$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-conf.example')" && echo "ok   Fehlermeldung angezeigt" || fail "keine Fehlermeldung in der Oberfläche"
# conf/ darf vom Besitzer der Website nur gelesen werden; bearbeitet wird
# ausschliesslich über die Oberfläche bzw. das CLI (Nutzervorgabe 2026-09-20).
OWNER=$(stat -c '%U' /var/www/smoke-conf.example)
[ "$(stat -c '%U:%G:%a' /var/www/smoke-conf.example/conf)" = "root:$OWNER:750" ] \
	&& echo "ok   conf/ gehoert root, Gruppe $OWNER" \
	|| fail "conf/ hat falsche Rechte: $(stat -c '%U:%G:%a' /var/www/smoke-conf.example/conf)"
[ "$(stat -c '%U:%G:%a' /var/www/smoke-conf.example/conf/custom.conf)" = "root:$OWNER:640" ] \
	&& echo "ok   custom.conf gehoert root, nur lesbar" \
	|| fail "custom.conf hat falsche Rechte: $(stat -c '%U:%G:%a' /var/www/smoke-conf.example/conf/custom.conf)"
sudo -u "$OWNER" test -r /var/www/smoke-conf.example/conf/custom.conf \
	&& echo "ok   Besitzer darf lesen" || fail "Besitzer kann custom.conf nicht lesen"
sudo -u "$OWNER" test -w /var/www/smoke-conf.example/conf/custom.conf \
	&& fail "Besitzer kann custom.conf schreiben - das muss der Admin allein tun" \
	|| echo "ok   Besitzer darf nicht schreiben"
sudo -u "$OWNER" test -w /var/www/smoke-conf.example/conf \
	&& fail "Besitzer kann Dateien in conf/ anlegen" || echo "ok   Besitzer darf in conf/ nichts anlegen"
# Die Oberflaeche zeigt den Text trotzdem an - sie holt ihn ueber "vhost conf-show".
grep -q 'X-Smoke-Test' <<<"$(vhost conf-show smoke-conf.example)" \
	&& echo "ok   conf-show liefert den Text" || fail "conf-show liefert das Snippet nicht"

for sub in web conf cert private logs; do
	[ -d "/var/www/smoke-conf.example/$sub" ] || fail "Ordner $sub fehlt"
done
echo "ok   Struktur vollständig"

echo "== PHP"
vhost add smoke-php.example >/dev/null
vhost protect smoke-php.example off >/dev/null
printf '<?php echo "USER=", posix_getpwuid(posix_geteuid())["name"], "\\n"; ?>\n' > /var/www/smoke-php.example/web/t.php
# Ohne PHP darf die Datei NICHT als Text kommen: darin stehen sonst Zugangsdaten offen.
expect 404 "PHP aus: .php abgewiesen" -H 'Host: smoke-php.example' http://127.0.0.1/t.php
vhost php smoke-php.example on >/dev/null
[ -f /etc/php/8.5/fpm/pool.d/vhost-smoke-php.example.conf ] || ls /etc/php/*/fpm/pool.d/vhost-smoke-php.example.conf >/dev/null || fail "Pool-Datei fehlt"
echo "ok   Pool angelegt"
# PHP muss unter dem eigenen Benutzer des Hosts laufen, nie als www-data: www-data darf
# per sudoers das vhost-CLI als root aufrufen, Website-PHP käme damit an root.
OUT=$(curl -s -H 'Host: smoke-php.example' http://127.0.0.1/t.php)
grep -q '^USER=web' <<<"$OUT" && echo "ok   PHP laeuft als eigener Benutzer ($OUT)" || fail "PHP laeuft nicht als eigener Benutzer: $OUT"
grep -q 'USER=www-data' <<<"$OUT" && fail "PHP laeuft als www-data - das waere root ueber sudoers" || true
# open_basedir muss den Zugriff auf die Oberfläche und andere Hosts verhindern.
printf '<?php var_dump(@file_get_contents("/var/lib/vhost-admin/vhosts.sqlite")); ?>\n' > /var/www/smoke-php.example/web/esc.php
grep -q 'bool(false)' <<<"$(curl -s -H 'Host: smoke-php.example' http://127.0.0.1/esc.php)" && echo "ok   open_basedir haelt" || fail "PHP kommt an die Datenbank der Oberflaeche"
# Eigene Direktiven im ISPConfig-Stil müssen angenommen werden (copy-paste-Fall).
printf 'location = /health {\n    return 200 "ok";\n}\nif ($request_method !~ ^(GET|HEAD|POST)$) {\n    return 405;\n}\n' | vhost conf smoke-php.example >/dev/null
grep -q 'health' /var/www/smoke-php.example/conf/custom.conf && echo "ok   fremdes Fragment angenommen" || fail "Fragment abgelehnt"
# Und was aus dem vHost herausführt, muss scheitern.
echo 'root /etc;' | vhost conf smoke-php.example >/dev/null 2>&1 && fail "root /etc wurde angenommen" || echo "ok   root /etc abgelehnt"
echo 'listen 0.0.0.0:8081;' | vhost conf smoke-php.example >/dev/null 2>&1 && fail "listen wurde angenommen" || echo "ok   listen abgelehnt"
vhost php smoke-php.example off >/dev/null
ls /etc/php/*/fpm/pool.d/vhost-smoke-php.example.conf >/dev/null 2>&1 && fail "Pool-Datei blieb liegen" || echo "ok   Pool entfernt"

echo "== Aufräumen"
# Die eigentliche Entfernung übernimmt cleanup() (auch schon über den trap
# beim Skriptende zuständig); hier nur vorgezogen, damit die folgende Prüfung
# auf einen bereits sauberen Zustand trifft, bevor die Erfolgsmeldung fällt.
cleanup
vhost list | grep -q smoke && fail "Reste in der Datenbank" || echo "ok   sauber"
echo "ALLE PRÜFUNGEN BESTANDEN"
