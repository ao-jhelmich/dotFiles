#!/usr/bin/env bash
# Copies this machine's personal Cursor skills into ~/dotFiles/cursor/skills.
# Restore with: cp -Rp ~/dotFiles/cursor/skills/. ~/.cursor/skills/
set -euo pipefail

DOTFILES_DIR="$(cd "$(dirname "$0")" && pwd)"
SKILLS_DIR="$HOME/.cursor/skills"
REPO_SKILLS="$DOTFILES_DIR/cursor/skills"

[ -d "$SKILLS_DIR" ] || { echo "Geen Cursor skills gevonden in $SKILLS_DIR"; exit 1; }

rm -rf "$REPO_SKILLS"
mkdir -p "$REPO_SKILLS"
cp -Rp "$SKILLS_DIR/." "$REPO_SKILLS/"
find "$REPO_SKILLS" -name .DS_Store -delete

echo "Cursor skills gesynct naar $REPO_SKILLS:"
git -C "$DOTFILES_DIR" status --short -- cursor
