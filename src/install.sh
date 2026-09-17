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

echo "== Oberfläche nach /var/www/localhost-8080"
install -d -m 2775 -o "$OWNER" -g www-data /var/www/localhost-8080
install -m 644 -o "$OWNER" -g www-data "$SRC"/public/*.php /var/www/localhost-8080/

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

echo "== Dienste"
for unit in /usr/lib/systemd/system/php*-fpm.service; do
	systemctl enable --now "$(basename "$unit")" >/dev/null
done
systemctl enable --now nginx >/dev/null

echo "== Datenbank und nginx-Configs"
install -d -m 770 -o www-data -g www-data /var/lib/vhost-admin
/usr/local/sbin/vhost init
/usr/local/sbin/vhost render

echo
echo "Fertig."
echo "  Oberfläche : http://127.0.0.1:8080  (nur lokal; von außen z.B. per ssh -L 8080:127.0.0.1:8080 <host>)"
echo "  CLI        : sudo vhost --help"
