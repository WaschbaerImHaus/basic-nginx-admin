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
	rm -rf /var/www/smoke-test.example /var/www/smoke-ui.example /var/www/localhost-3999
	rm -rf /var/www/smoke-conf.example
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
grep -q 'X-Smoke-Test' /var/www/smoke-conf.example/conf/custom.conf && echo "ok   Snippet gespeichert" || fail "Snippet nicht gespeichert"
curl -s -D - -o /dev/null -H 'Host: smoke-conf.example' http://127.0.0.1/ | grep -qi 'X-Smoke-Test: bestanden' && echo "ok   Snippet wirkt" || fail "Header aus dem Snippet fehlt"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF2" -d 'action=conf&name=smoke-conf.example' --data-urlencode 'snippet=root /etc;' http://127.0.0.1:8080/
grep -q 'X-Smoke-Test' /var/www/smoke-conf.example/conf/custom.conf && echo "ok   verbotene Direktive abgelehnt, alte Fassung aktiv" || fail "verbotene Direktive hat das Snippet überschrieben"
curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-conf.example' | grep -q 'flash err' && echo "ok   Fehlermeldung angezeigt" || fail "keine Fehlermeldung in der Oberfläche"
for sub in web conf cert private logs; do
	[ -d "/var/www/smoke-conf.example/$sub" ] || fail "Ordner $sub fehlt"
done
echo "ok   Struktur vollständig"

echo "== Aufräumen"
# Die eigentliche Entfernung übernimmt cleanup() (auch schon über den trap
# beim Skriptende zuständig); hier nur vorgezogen, damit die folgende Prüfung
# auf einen bereits sauberen Zustand trifft, bevor die Erfolgsmeldung fällt.
cleanup
vhost list | grep -q smoke && fail "Reste in der Datenbank" || echo "ok   sauber"
echo "ALLE PRÜFUNGEN BESTANDEN"
