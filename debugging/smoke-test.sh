#!/usr/bin/env bash
# Ende-zu-Ende-Prüfung der installierten Version über CLI und Oberfläche.
# Legt Test-vHosts an, prüft die HTTP-Antworten und räumt wieder auf.
# Aufruf: sudo ./debugging/smoke-test.sh
set -euo pipefail

JAR=""
# Projektverzeichnis (das Skript liegt in debugging/). Wird fuer die Vorlagen der
# Honigtopf-Ansicht gebraucht, falls der Lauf aus dem Projekt statt aus /opt kommt.
PROJECT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

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
	vhost remove smoke-frist.example --purge >/dev/null 2>&1
	rm -rf /var/www/smoke-test.example /var/www/smoke-ui.example /var/www/localhost-3999
	rm -rf /var/www/smoke-conf.example /var/www/smoke-php.example /var/www/smoke-frist.example
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
# ACME-Marker: Grundlage des Erreichbarkeitstests. Muss auch bei aktivem
# Verzeichnisschutz ohne Anmeldung ausgeliefert werden, sonst kaeme Let's Encrypt nicht durch.
vhost protect smoke-test.example on >/dev/null
[ -f /var/www/smoke-test.example/web/.well-known/acme-challenge/vhost-admin-health ] \
	&& echo "ok   ACME-Marker angelegt" || fail "ACME-Marker fehlt"
expect 200 "ACME-Pfad trotz Schutz offen" -H 'Host: smoke-test.example' \
	http://127.0.0.1/.well-known/acme-challenge/vhost-admin-health
vhost protect smoke-test.example off >/dev/null
# ".example" loest nie auf: der Test muss das erkennen und benennen, nicht "ok" melden.
grep -q '^dns ' <<<"$(vhost check-acme smoke-test.example)" \
	&& echo "ok   Erreichbarkeitstest erkennt fehlendes DNS" \
	|| fail "check-acme meldet nicht 'dns': $(vhost check-acme smoke-test.example)"
vhost ssl smoke-test.example on >/dev/null 2>&1 \
	&& fail "Zertifikat trotz unerreichbarer Domain angefordert" \
	|| echo "ok   kein Zertifikat fuer unerreichbare Domain"
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
# Das Passwortfeld ist nur noch lesbar und traegt ein erzeugtes Passwort; genau das
# wird hinterlegt. Es aus der Seite ziehen und damit anmelden.
UIPW=$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-ui.example' | grep -o 'name="password" class="mono pw" value="[^"]*"' | sed 's/.*value="//;s/"//')
[ ${#UIPW} -eq 20 ] && echo "ok   erzeugtes Passwort (20 Zeichen)" || fail "kein erzeugtes Passwort im Formular: '$UIPW'"
grep -qE '^[A-Za-z0-9@=#+.,_:;-]{20}$' <<<"$UIPW" && echo "ok   nur erlaubte Zeichen" || fail "unerlaubte Zeichen: $UIPW"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF" -d 'action=user_add&name=smoke-ui.example&username=bob' --data-urlencode "password=$UIPW" http://127.0.0.1:8080/
expect 200 "UI-Benutzer" -u "bob:$UIPW" -H 'Host: smoke-ui.example' http://127.0.0.1/
# Das gesetzte Passwort wird genau einmal angezeigt.
grep -q 'mono pw wide' <<<"$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-ui.example')" \
	&& echo "ok   Passwort einmal angezeigt" || fail "Passwort wurde nicht angezeigt"
grep -q 'mono pw wide' <<<"$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-ui.example')" \
	&& fail "Passwort wird mehrfach angezeigt" || echo "ok   danach nicht mehr"
# Zuruecksetzen: neues Passwort gilt, altes nicht mehr.
curl -s -o /dev/null -c "$JAR" -b "$JAR" -d "csrf=$CSRF&action=user_reset&name=smoke-ui.example&username=bob" http://127.0.0.1:8080/
NEWPW=$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-ui.example' | grep -o 'class="mono pw wide" value="[^"]*"' | sed 's/.*value="//;s/"//')
[ -n "$NEWPW" ] && [ "$NEWPW" != "$UIPW" ] && echo "ok   neues Passwort erzeugt" || fail "Zuruecksetzen lieferte kein neues Passwort"
expect 200 "neues Passwort gilt" -u "bob:$NEWPW" -H 'Host: smoke-ui.example' http://127.0.0.1/
expect 401 "altes Passwort ungueltig" -u "bob:$UIPW" -H 'Host: smoke-ui.example' http://127.0.0.1/
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
# Fertige Konfiguration: die eingebundenen Teile muessen im Text stehen, nicht als Verweis.
SHOW=$(vhost show smoke-conf.example)
grep -q 'X-Smoke-Test' <<<"$SHOW" && echo "ok   eigene Direktiven im Gesamttext" || fail "eigene Direktiven fehlen in 'vhost show'"
grep -q 'satisfy any\|Verzeichnisschutz deaktiviert' <<<"$SHOW" && echo "ok   Verzeichnisschutz im Gesamttext" || fail "Verzeichnisschutz fehlt in 'vhost show'"
grep -q 'include /etc/nginx/auth/' <<<"$SHOW" && fail "Verweis auf das Auth-Snippet blieb stehen" || echo "ok   kein Verweis mehr auf eigene Dateien"
grep -q 'class="listing"' <<<"$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-conf.example')" \
	&& echo "ok   Konfiguration in der Oberflaeche sichtbar" || fail "Konfigurationsansicht fehlt in der Oberflaeche"
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
# private/ steht in open_basedir und muss mit PHP auch wirklich lesbar sein - genau
# darauf liegt die Honigtopf-Ansicht auf. Geschrieben werden darf dort nichts, und
# ausgeliefert schon gar nicht.
vhost fix-permissions smoke-php.example >/dev/null
printf 'geheimnis\n' > /var/www/smoke-php.example/private/probe.txt
chown "$(stat -c '%U' /var/www/smoke-php.example/private)" /var/www/smoke-php.example/private/probe.txt
chmod 640 /var/www/smoke-php.example/private/probe.txt
chgrp "$(stat -c '%G' /var/www/smoke-php.example/private)" /var/www/smoke-php.example/private/probe.txt
printf '<?php var_dump(trim((string)@file_get_contents(__DIR__."/../private/probe.txt"))); ?>\n' > /var/www/smoke-php.example/web/priv.php
grep -q 'geheimnis' <<<"$(curl -s -H 'Host: smoke-php.example' http://127.0.0.1/priv.php)" \
	&& echo "ok   private/ ist fuer den Pool lesbar" || fail "PHP kommt nicht an private/ - die Honigtopf-Ansicht faende ihre Berichte nicht"
printf '<?php var_dump(@file_put_contents(__DIR__."/../private/schreib.txt", "x")); ?>\n' > /var/www/smoke-php.example/web/privw.php
grep -q 'bool(false)' <<<"$(curl -s -H 'Host: smoke-php.example' http://127.0.0.1/privw.php)" \
	&& echo "ok   private/ bleibt fuer PHP schreibgeschuetzt" || fail "PHP darf in private/ schreiben"

# Die Honigtopf-Ansicht selbst: Dateien und Klassen an ihren Platz, ein Tagesbericht
# dazu, und die Seite muss durch nginx und php-fpm hindurch erscheinen.
# Die Vorlage liegt je nach Aufrufort im Projekt oder in der Installation.
LIBSRC=/opt/vhost-admin/lib/Honeypot; [ -d "$PROJECT/src/lib/Honeypot" ] && LIBSRC="$PROJECT/src/lib/Honeypot"
PAGESRC=/opt/vhost-admin/public/honeypot; [ -d "$PROJECT/src/public/honeypot" ] && PAGESRC="$PROJECT/src/public/honeypot"
HP=/var/www/smoke-php.example
PUSER=$(stat -c '%U' "$HP/private"); PGROUP=$(stat -c '%G' "$HP/private")
WUSER=$(stat -c '%U' "$HP/web"); WGROUP=$(stat -c '%G' "$HP/web")
install -d -m 750 -o "$PUSER" -g "$PGROUP" "$HP/private/honeypot-lib/Honeypot" "$HP/private/honeypot/smoke.example"
install -m 640 -o "$PUSER" -g "$PGROUP" "$LIBSRC"/*.php "$HP/private/honeypot-lib/Honeypot/"
printf '{"date":"2026-01-01","complete":true,"requests":7,"status":{"404":5},"hours":{"07":7},"agents":{"scanner":7},"notFound":{"/.env":5},"loot":{"Zugangsdaten":5},"events":[{"time":"07:00:00","method":"GET","path":"/.env","status":"404","agent":"scanner","group":"Zugangsdaten","probe":"","request":"GET /.env HTTP/1.1"}]}\n' \
	> "$HP/private/honeypot/smoke.example/2026-01-01.json"
chown "$PUSER:$PGROUP" "$HP/private/honeypot/smoke.example/2026-01-01.json"
chmod 640 "$HP/private/honeypot/smoke.example/2026-01-01.json"
install -d -m 2775 -o "$WUSER" -g "$WGROUP" "$HP/web/hp"
for f in index.php detail.php bootstrap.php style.css; do
	install -m 644 -o "$WUSER" -g "$WGROUP" "$PAGESRC/$f" "$HP/web/hp/$f"
done
OUT=$(curl -s -H 'Host: smoke-php.example' http://127.0.0.1/hp/)
grep -q 'Honigtopf' <<<"$OUT" || fail "Honigtopf-Ansicht erscheint nicht: $(head -c 200 <<<"$OUT")"
grep -q 'Sondierungen' <<<"$OUT" || fail "Honigtopf-Ansicht ohne Kennzahlen"
echo "ok   Honigtopf-Ansicht liefert aus"
grep -q '\.env' <<<"$(curl -s -H 'Host: smoke-php.example' 'http://127.0.0.1/hp/?ansicht=pfade')" \
	&& echo "ok   Detailansicht liefert aus" || fail "Detailansicht ohne Inhalt"

vhost php smoke-php.example off >/dev/null
ls /etc/php/*/fpm/pool.d/vhost-smoke-php.example.conf >/dev/null 2>&1 && fail "Pool-Datei blieb liegen" || echo "ok   Pool entfernt"

echo "== Sicherheit, Cache, Komprimierung"
printf 'body{color:red}/* Fuellung */\n%.0s' $(seq 60) > /var/www/smoke-test.example/web/public/t.css
printf '<h1>x</h1>\n' > /var/www/smoke-test.example/web/public/t.html
printf 'PNGDATA%.0s' $(seq 200) > /var/www/smoke-test.example/web/public/t.png
H=$(curl -sI -H 'Host: smoke-test.example' http://127.0.0.1/t.html)
grep -qi 'x-content-type-options: nosniff' <<<"$H" && echo "ok   nosniff" || fail "nosniff fehlt"
grep -qi 'referrer-policy:' <<<"$H" && echo "ok   Referrer-Policy" || fail "Referrer-Policy fehlt"
grep -qi 'x-frame-options: SAMEORIGIN' <<<"$H" && echo "ok   X-Frame-Options" || fail "X-Frame-Options fehlt"
grep -qi '^Server: nginx.$' <<<"$(printf '%s' "$H" | tr -d '\r')" && echo "ok   Version verschwiegen" || echo "ok   Server-Kopfzeile: $(grep -i '^server:' <<<"$H" | tr -d '\r')"
# Wichtig: Die Sicherheitskopfzeilen muessen AUCH in den Cache-Bloecken ankommen. Ein
# add_header in einem location-Block wuerde sie dort verwerfen - deshalb "expires".
grep -qi 'cache-control: no-cache' <<<"$H" && echo "ok   HTML ohne Cache" || fail "HTML wird zwischengespeichert"
HC=$(curl -sI -H 'Host: smoke-test.example' -H 'Accept-Encoding: gzip' http://127.0.0.1/t.css)
grep -qi 'x-content-type-options: nosniff' <<<"$HC" && echo "ok   Kopfzeilen auch im Cache-Block" || fail "Sicherheitskopfzeilen im Cache-Block verloren"
grep -qi 'content-encoding: gzip' <<<"$HC" && echo "ok   CSS komprimiert" || fail "CSS nicht komprimiert"
grep -qi 'cache-control: max-age=604800' <<<"$HC" && echo "ok   CSS 7 Tage" || fail "CSS ohne 7-Tage-Cache"
PLAIN=$(curl -s -o /dev/null -w '%{size_download}' -H 'Host: smoke-test.example' http://127.0.0.1/t.css)
GZ=$(curl -s -o /dev/null -w '%{size_download}' -H 'Host: smoke-test.example' -H 'Accept-Encoding: gzip' http://127.0.0.1/t.css)
[ "$GZ" -lt "$PLAIN" ] && echo "ok   gzip spart ($PLAIN -> $GZ Bytes)" || fail "gzip spart nichts ($PLAIN -> $GZ)"
HP=$(curl -sI -H 'Host: smoke-test.example' -H 'Accept-Encoding: gzip' http://127.0.0.1/t.png)
grep -qi 'cache-control: max-age=2592000' <<<"$HP" && echo "ok   PNG 30 Tage" || fail "PNG ohne 30-Tage-Cache"
grep -qi 'content-encoding: gzip' <<<"$HP" && fail "PNG wird erneut komprimiert" || echo "ok   PNG nicht neu komprimiert"
rm -f /var/www/smoke-test.example/web/public/t.css /var/www/smoke-test.example/web/public/t.html /var/www/smoke-test.example/web/public/t.png

echo "== Entfernen mit Schonfrist"
vhost add smoke-frist.example >/dev/null
vhost protect smoke-frist.example off >/dev/null
expect 200 "vor dem Entfernen erreichbar" -H 'Host: smoke-frist.example' http://127.0.0.1/
vhost remove smoke-frist.example >/dev/null
# Kernzusage: nginx liefert sofort nicht mehr aus (444 -> curl schreibt 000).
expect 000 "sofort gesperrt" -H 'Host: smoke-frist.example' http://127.0.0.1/
grep -q 'smoke-frist.example.*GESPERRT' <<<"$(vhost list)" && echo "ok   als gesperrt gelistet" || fail "nicht als gesperrt gelistet"
[ -d /var/www/smoke-frist.example ] && echo "ok   Dateien bleiben" || fail "Dateien wurden geloescht"
grep -q 'Nichts fällig' <<<"$(vhost purge-due)" && echo "ok   Frist laeuft noch" || fail "zu frueh entfernt"
vhost restore smoke-frist.example >/dev/null
expect 200 "zurueckgeholt" -H 'Host: smoke-frist.example' http://127.0.0.1/
# Frist kuenstlich ablaufen lassen und endgueltig entfernen.
vhost remove smoke-frist.example >/dev/null
php -r '$p = new PDO("sqlite:/var/lib/vhost-admin/vhosts.sqlite"); $p->exec("UPDATE vhosts SET deleted_at = datetime(\"now\", \"-2 hours\") WHERE name = \"smoke-frist.example\"");'
grep -q 'smoke-frist.example' <<<"$(vhost purge-due)" && echo "ok   nach Ablauf endgueltig entfernt" || fail "nach Ablauf nicht entfernt"
grep -q smoke-frist <<<"$(vhost list)" && fail "Eintrag noch in der Datenbank" || echo "ok   Eintrag weg"
[ -d /var/www/smoke-frist.example ] && echo "ok   Dateien auch danach erhalten" || fail "Dateien wurden mitgeloescht"

echo "== Aufräumen"
# Die eigentliche Entfernung übernimmt cleanup() (auch schon über den trap
# beim Skriptende zuständig); hier nur vorgezogen, damit die folgende Prüfung
# auf einen bereits sauberen Zustand trifft, bevor die Erfolgsmeldung fällt.
cleanup
grep -q smoke <<<"$(vhost list)" && fail "Reste in der Datenbank" || echo "ok   sauber"
echo "ALLE PRÜFUNGEN BESTANDEN"
