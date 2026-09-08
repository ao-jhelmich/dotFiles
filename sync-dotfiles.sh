#!/usr/bin/env bash
set -euo pipefail

DOTFILES_DIR="$HOME/dotFiles"
HOME_DIR="$HOME"
FILES=(".aliases" ".functions" ".zshrc" )

for file in "${FILES[@]}"; do
  repo_file="$DOTFILES_DIR/$file"
  home_file="$HOME_DIR/$file"

  if [ ! -f "$repo_file" ] && [ ! -f "$home_file" ]; then
    echo "SKIP $file: nergens gevonden"
    continue
  fi

  if [ ! -f "$repo_file" ]; then
    echo "KOPIEER $file: home -> repo (nieuw)"
    cp -p "$home_file" "$repo_file"
  elif [ ! -f "$home_file" ]; then
    echo "KOPIEER $file: repo -> home (nieuw)"
    cp -p "$repo_file" "$home_file"
  elif [ "$home_file" -nt "$repo_file" ]; then
    echo "SYNC $file: home is nieuwer -> repo"
    cp -p "$home_file" "$repo_file"
  elif [ "$repo_file" -nt "$home_file" ]; then
    echo "SYNC $file: repo is nieuwer -> home"
    cp -p "$repo_file" "$home_file"
  else
    echo "OK $file: al in sync"
  fi
done
