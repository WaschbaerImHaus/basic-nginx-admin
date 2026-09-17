#!/usr/bin/env bash
# Wrapper: ruft den eigentlichen Installer unter src/ auf.
# Aufruf: sudo ./install.sh [--owner BENUTZER]
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/src/install.sh" "$@"
