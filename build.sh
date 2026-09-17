#!/usr/bin/env bash
# Build: Tests → Buildnummer +1 → build/vhost-admin.tar.gz → Commit „Build N“ → Push.
# Aufruf: ./build.sh [--no-push]   (--no-push: nur bauen und committen)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
PUSH=1
[ "${1:-}" = "--no-push" ] && PUSH=0

echo "== Tests"
phpunit

echo "== Buildnummer"
BUILD=$(( $(cat src/build.txt) + 1 ))
echo "$BUILD" > src/build.txt
echo "Build $BUILD"

echo "== Paket"
mkdir -p build
rm -f build/vhost-admin.tar.gz
tar --transform 's,^,vhost-admin/,' -czf build/vhost-admin.tar.gz src install.sh README.md
ls -la build/vhost-admin.tar.gz

echo "== Git"
git add -A
git commit -q -m "Build $BUILD" || echo "nichts zu committen"
if [ "$PUSH" = 1 ]; then
	git push origin main
fi
echo "Fertig: Build $BUILD"
