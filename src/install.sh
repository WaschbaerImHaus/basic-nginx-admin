#!/usr/bin/env bash
# Installiert vhost-admin (nginx + PHP/SQLite-Verwaltung) auf Ubuntu/Debian.
#
#   sudo ./install.sh [--owner BENUTZER]
#
# --owner: Besitzer der Ordner unter /var/www (Standard: der aufrufende sudo-Benutzer).
# Mehrfach ausführbar (Update). Für einen Umzug zusätzlich /var/lib/vhost-admin,
# /var/www und /etc/letsencrypt mitnehmen – die nginx-Configs erzeugt das Script neu.
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP=/opt/vhost-admin
OWNER="${SUDO_USER:-root}"

while [ $# -gt 0 ]; do
	case "$1" in
		--owner) OWNER="$2"; shift 2 ;;
		*) echo "Unbekannte Option: $1" >&2; exit 2 ;;
	esac
done

[ "$(id -u)" -eq 0 ] || { echo "Bitte als root bzw. mit sudo ausführen." >&2; exit 1; }
id "$OWNER" >/dev/null 2>&1 || { echo "Benutzer '$OWNER' existiert nicht." >&2; exit 1; }

echo "== Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q nginx php-fpm php-cli php-sqlite3 certbot phpunit

# Die eigenen Direktiven und der PHP-Block verweisen auf diese Datei; ohne sie
# scheitert jeder nginx-Test mit "open() ... failed".
[ -f /etc/nginx/fastcgi_params ] || { echo "/etc/nginx/fastcgi_params fehlt - ist nginx vollstaendig installiert?" >&2; exit 1; }

echo "== Anwendung nach $APP (Besitzer von /var/www/*: $OWNER)"
install -d -m 755 "$APP" "$APP/bin" "$APP/templates"
rm -rf "$APP/lib" "$APP/public"
cp -r "$SRC/lib" "$APP/lib"
find "$APP/lib" -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
install -m 644 "$SRC/bootstrap.php" "$APP/bootstrap.php"
install -m 644 "$SRC"/templates/index.html "$APP/templates/"
install -m 755 "$SRC"/bin/vhost.php "$APP/bin/"
install -m 755 "$SRC"/bin/vhost /usr/local/sbin/vhost
sed -i "s|'wwwOwner' => '[^']*'|'wwwOwner' => '$OWNER'|" "$APP/lib/VhostAdmin/Config.php"
grep -q "'wwwOwner' => '$OWNER'" "$APP/lib/VhostAdmin/Config.php" || { echo "Besitzer konnte nicht gesetzt werden" >&2; exit 1; }

echo "== Oberfläche nach /var/www/localhost-8080/web"
# Basisordner nicht gruppenbeschreibbar (C2, Abschlussreview 2026-09-20): www-data
# könnte sonst Einträge darin ersetzen, auch den root-eigenen conf/-Ordner. Nur web/
# selbst bleibt für www-data beschreibbar (analog zu VhostLayout::directories()).
install -d -m 755 -o "$OWNER" -g "$OWNER" /var/www/localhost-8080
install -d -m 2775 -o "$OWNER" -g www-data /var/www/localhost-8080/web
install -d -m 750 -o root -g www-data /var/www/localhost-8080/conf
install -d -m 750 -o root -g "$OWNER" /var/www/localhost-8080/cert /var/www/localhost-8080/logs
install -d -m 750 -o "$OWNER" -g "$OWNER" /var/www/localhost-8080/private
# Aus einer früheren Fassung liegt index.php eventuell noch flach im Basisordner.
if [ -f /var/www/localhost-8080/index.php ]; then
	mv /var/www/localhost-8080/index.php /var/www/localhost-8080/web/index.php
fi
install -m 644 -o "$OWNER" -g www-data "$SRC"/public/*.php /var/www/localhost-8080/web/

echo "== sudo-Regel für www-data"
install -m 440 "$SRC/etc/sudoers-vhost-admin" /etc/sudoers.d/vhost-admin
visudo -cf /etc/sudoers.d/vhost-admin >/dev/null

echo "== nginx"
install -d -m 750 -g www-data /etc/nginx/auth
install -m 644 "$SRC/etc/nginx-admin.conf"   /etc/nginx/sites-available/vhost-admin.conf
install -m 644 "$SRC/etc/nginx-default.conf" /etc/nginx/sites-available/00-default.conf
if [ ! -s /proc/net/if_inet6 ]; then
	sed -i '/listen \[::/d' /etc/nginx/sites-available/vhost-admin.conf /etc/nginx/sites-available/00-default.conf
fi
ln -sfn /etc/nginx/sites-available/vhost-admin.conf /etc/nginx/sites-enabled/vhost-admin.conf
ln -sfn /etc/nginx/sites-available/00-default.conf  /etc/nginx/sites-enabled/00-default.conf
rm -f /etc/nginx/sites-enabled/default

echo "== certbot: nginx nach Zertifikatsverlängerung neu laden"
install -d /etc/letsencrypt/renewal-hooks/deploy
install -m 755 "$SRC/etc/certbot-deploy-nginx.sh" /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh

echo "== logrotate"
sed "s/create 0640 root root/create 0640 root $OWNER/" "$SRC/etc/logrotate-vhost-admin" > /etc/logrotate.d/vhost-admin
chmod 644 /etc/logrotate.d/vhost-admin
logrotate -d /etc/logrotate.d/vhost-admin >/dev/null 2>&1 || { echo "logrotate-Konfiguration ist fehlerhaft" >&2; exit 1; }

echo "== Dienste"
for unit in /usr/lib/systemd/system/php*-fpm.service; do
	systemctl enable --now "$(basename "$unit")" >/dev/null
done
systemctl enable --now nginx >/dev/null

echo "== Datenbank und nginx-Configs"
# Verzeichnis und Datei gehören root; www-data (die Oberfläche) bekommt per Gruppe nur
# Leserechte (0750/0640) – geschrieben wird ausschließlich über "vhost" als root. Die
# endgültigen Rechte setzt VhostService::fixDatabasePermissions(), das jeder CLI-Lauf
# aufruft; hier nur, damit "vhost init" gleich in ein passendes Verzeichnis schreibt.
install -d -m 750 -o root -g www-data /var/lib/vhost-admin
/usr/local/sbin/vhost init
# Taegliche Auswertung der Honigtopf-Logs. Die Liste der Hosts steht in
# /etc/default/vhost-admin-honeypot und wird bei einem Update nicht ueberschrieben.
install -m 644 "$SRC/etc/vhost-admin-honeypot.service" /etc/systemd/system/vhost-admin-honeypot.service
install -m 644 "$SRC/etc/vhost-admin-honeypot.timer" /etc/systemd/system/vhost-admin-honeypot.timer
[ -f /etc/default/vhost-admin-honeypot ] || printf 'HONEYPOT_HOSTS=""\n' > /etc/default/vhost-admin-honeypot
if [ -f "$SRC/../honeypot/analyse.php" ]; then
	install -d -m 755 "$APP/honeypot" "$APP/research/honeypot"
	install -m 755 "$SRC/../honeypot/analyse.php" "$APP/honeypot/analyse.php"
else
	echo "Hinweis: honeypot/analyse.php fehlt im Paket - die taegliche Auswertung bleibt leer." >&2
fi

# Zeitgeber, der abgelaufene Loeschvormerkungen endgueltig entfernt. Ohne ihn bliebe
# ein entfernter vHost unbegrenzt gesperrt liegen, statt nach der Frist zu verschwinden.
install -m 644 "$SRC/etc/vhost-admin-purge.service" /etc/systemd/system/vhost-admin-purge.service
install -m 644 "$SRC/etc/vhost-admin-purge.timer" /etc/systemd/system/vhost-admin-purge.timer
systemctl daemon-reload
systemctl enable --now vhost-admin-purge.timer >/dev/null
systemctl enable --now vhost-admin-honeypot.timer >/dev/null

/usr/local/sbin/vhost migrate-layout
# Rechte bestehender vHosts auf den aktuellen Stand bringen. Nötig, weil sich die
# Soll-Rechte zwischen Fassungen ändern koennen (z.B. conf/ am 2026-09-20 von
# root:www-data auf root:<besitzer>, damit der Besitzer lesen darf, aber nur root
# schreibt). Ohne diesen Schritt bliebe ein Bestandshost auf den alten Rechten.
/usr/local/sbin/vhost fix-permissions
/usr/local/sbin/vhost render

echo
echo "Fertig."
echo "  Oberfläche : http://127.0.0.1:8080  (nur lokal; von außen z.B. per ssh -L 8080:127.0.0.1:8080 <host>)"
echo "  CLI        : sudo vhost --help"
