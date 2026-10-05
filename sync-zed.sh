#!/usr/bin/env bash
# Copies this machine's Zed setup into ~/dotFiles/zed. Restore with install-zed.sh.
set -euo pipefail

DOTFILES_DIR="$(cd "$(dirname "$0")" && pwd)"
ZED_CONFIG="$HOME/.config/zed"
ZED_SUPPORT="$HOME/Library/Application Support/Zed"
REPO_ZED="$DOTFILES_DIR/zed"

[ -d "$ZED_CONFIG" ] || { echo "Geen Zed config gevonden in $ZED_CONFIG"; exit 1; }

rm -rf "$REPO_ZED/config"
mkdir -p "$REPO_ZED/config" "$REPO_ZED/bin"

for item in settings.json keymap.json tasks.json themes scripts snippets; do
  [ -e "$ZED_CONFIG/$item" ] && cp -Rp "$ZED_CONFIG/$item" "$REPO_ZED/config/"
done

# The LMDB lock file is runtime state; data.mdb holds the saved prompts.
if [ -f "$ZED_CONFIG/prompts/prompts-library-db.0.mdb/data.mdb" ]; then
  mkdir -p "$REPO_ZED/config/prompts/prompts-library-db.0.mdb"
  cp -p "$ZED_CONFIG/prompts/prompts-library-db.0.mdb/data.mdb" "$REPO_ZED/config/prompts/prompts-library-db.0.mdb/"
fi

[ -f "$HOME/.local/bin/phpactor" ] && cp -p "$HOME/.local/bin/phpactor" "$REPO_ZED/bin/phpactor"

# Absolute home paths become __HOME__ so install-zed.sh can rewrite them for another user.
grep -rlI --exclude=*.mdb "$HOME" "$REPO_ZED" 2>/dev/null | while read -r file; do
  perl -pi -e 's/\Q$ENV{HOME}\E/__HOME__/g' "$file"
done

rm -rf "$REPO_ZED/extension-overrides"
installed="$ZED_SUPPORT/extensions/installed"
if [ -d "$installed" ]; then
  ls "$installed" > "$REPO_ZED/extensions.txt"
  # Hand edits inside extensions (folder icon colours, PHP highlight queries).
  # ponytail: detected as files newer than the extension's extension.toml; an extension update overwrites them.
  (cd "$installed" && for ext in */; do
    ext="${ext%/}"
    find "$ext" -type f -newer "$ext/extension.toml" ! -name '*.wasm' ! -path '*/grammars/*'
  done) | while read -r f; do
    mkdir -p "$REPO_ZED/extension-overrides/$(dirname "$f")"
    cp -p "$installed/$f" "$REPO_ZED/extension-overrides/$f"
  done
fi

echo "Zed setup gesynct naar $REPO_ZED:"
git -C "$DOTFILES_DIR" status --short -- zed
