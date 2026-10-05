#!/usr/bin/env bash
# Sets up Zed on a new macOS machine from ~/dotFiles/zed (created by sync-zed.sh).
set -euo pipefail

DOTFILES_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ZED="$DOTFILES_DIR/zed"
ZED_CONFIG="$HOME/.config/zed"

[ -d "$REPO_ZED/config" ] || { echo "Geen $REPO_ZED/config; draai eerst sync-zed.sh op de bron-pc"; exit 1; }

if pgrep -xq zed || pgrep -xq Zed; then
  echo "Sluit Zed eerst af, anders overschrijft Zed de prompts en settings."
  exit 1
fi

# Unquoted globs stay literal when nothing matches, so -e fails for them.
exists_any() { for p in "$@"; do [ -e "$p" ] && return 0; done; return 1; }
brew_try() { brew install "$@" || echo "WAARSCHUWING: brew install $* mislukt; installeer handmatig."; }

if command -v brew >/dev/null; then
  exists_any /Applications/Zed.app "$HOME/Applications/Zed.app" || command -v zed >/dev/null \
    || brew list --cask zed >/dev/null 2>&1 || brew_try --cask zed
  exists_any "$HOME"/Library/Fonts/JetBrainsMono* /Library/Fonts/JetBrainsMono* \
    || brew list --cask font-jetbrains-mono >/dev/null 2>&1 || brew_try --cask font-jetbrains-mono
  for php in php@8.2 php@8.3; do
    exists_any "/opt/homebrew/opt/$php/bin/php" "/usr/local/opt/$php/bin/php" \
      || brew list "$php" >/dev/null 2>&1 || brew_try "$php"
  done
else
  echo "WAARSCHUWING: Homebrew ontbreekt; installeer Zed, JetBrains Mono, php@8.2 en php@8.3 handmatig."
fi

if [ -d "$ZED_CONFIG" ]; then
  backup="$ZED_CONFIG.backup.$(date +%Y%m%d%H%M%S)"
  echo "Bestaande config -> $backup"
  mv "$ZED_CONFIG" "$backup"
fi
mkdir -p "$ZED_CONFIG"
cp -Rp "$REPO_ZED/config/." "$ZED_CONFIG/"

mkdir -p "$HOME/.local/bin"
if [ -f "$REPO_ZED/bin/phpactor" ]; then
  cp -p "$REPO_ZED/bin/phpactor" "$HOME/.local/bin/phpactor"
  chmod +x "$HOME/.local/bin/phpactor"
fi
phar="$HOME/.local/share/phpactor/phpactor.phar"
if [ ! -f "$phar" ]; then
  mkdir -p "$(dirname "$phar")"
  curl -fsSL -o "$phar" https://github.com/phpactor/phpactor/releases/latest/download/phpactor.phar
fi

grep -rlI --exclude=*.mdb "__HOME__" "$ZED_CONFIG" "$HOME/.local/bin/phpactor" 2>/dev/null | while read -r file; do
  perl -pi -e 's/__HOME__/$ENV{HOME}/g' "$file"
done
chmod +x "$ZED_CONFIG"/scripts/* 2>/dev/null || true

# Zed has no CLI to install extensions; auto_install_extensions makes it fetch them on first launch.
settings="$ZED_CONFIG/settings.json"
if [ -s "$REPO_ZED/extensions.txt" ] && ! grep -q '"auto_install_extensions"' "$settings"; then
  entries="$(awk '{ printf "%s    \"%s\": true", (NR > 1 ? ",\n" : ""), $0 }' "$REPO_ZED/extensions.txt")"
  ENTRIES="$entries" perl -0pi -e 's/^\{\n/{\n  "auto_install_extensions": {\n$ENV{ENTRIES}\n  },\n/m' "$settings"
fi

# The licence is not in git; reuse the one the Cursor/VS Code Intelephense extension already activated.
lic_dir="$HOME/.config/intelephense/global"
mkdir -p "$lic_dir"
if [ ! -s "$lic_dir/licence.txt" ]; then
  key=""
  for app in Cursor Code; do
    user_dir="$HOME/Library/Application Support/$app/User"
    [ -d "$user_dir" ] || continue
    if [ -f "$user_dir/settings.json" ]; then
      key="$(sed -n 's/.*"intelephense\.licenceKey"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$user_dir/settings.json" | head -1 || true)"
    fi
    if [ -z "$key" ]; then
      found="$(find "$user_dir/globalStorage" -maxdepth 2 -path '*intelephense*' \
        \( -name licence.txt -o -name 'intelephense_licence_key_*' \) 2>/dev/null | head -1 || true)"
      case "$found" in
        */licence.txt) key="$(tr -d '[:space:]' < "$found")" ;;
        *intelephense_licence_key_*) key="${found##*intelephense_licence_key_}"; cp -p "$found" "$lic_dir/" ;;
      esac
    fi
    if [ -n "$key" ]; then
      echo "Intelephense licentie overgenomen uit $app"
      break
    fi
  done
  if [ -n "$key" ]; then
    printf '%s\n' "$key" > "$lic_dir/licence.txt"
  else
    echo "LET OP: geen Intelephense licentie gevonden; zet je key in $lic_dir/licence.txt."
  fi
fi

installed="$HOME/Library/Application Support/Zed/extensions/installed"
missing=""
for ext in "$REPO_ZED"/extension-overrides/*/; do
  [ -d "$ext" ] || continue
  name="$(basename "$ext")"
  # No -p: the copies must be newer than extension.toml so sync-zed.sh picks them up again.
  if [ -d "$installed/$name" ]; then cp -R "$ext." "$installed/$name/"; else missing="$missing $name"; fi
done

echo "Zed setup geinstalleerd. Start Zed; extensies worden automatisch geinstalleerd."
if [ -n "$missing" ]; then
  echo "LET OP: aanpassingen voor$missing nog niet toegepast. Start Zed, wacht tot de extensies"
  echo "geinstalleerd zijn, sluit Zed en draai dit script opnieuw."
fi
