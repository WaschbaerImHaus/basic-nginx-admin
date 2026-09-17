#!/usr/bin/env bash
# Startet Claude Code im Yolo-Modus (ohne Permission-Prompts) und setzt
# die letzte offene Session im aktuellen Verzeichnis fort, falls vorhanden.
set -euo pipefail

# Maus nicht von Claude Code abfangen lassen, damit das Terminal selbst markieren/kopieren kann
export CLAUDE_CODE_DISABLE_MOUSE=1

exec claude --dangerously-skip-permissions --continue "$@"
