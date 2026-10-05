#!/bin/bash
set -euo pipefail

file="${ZED_FILE:-}"
if [[ -z "$file" || ! -f "$file" ]]; then
  echo "No file open" >&2
  exit 1
fi

filename=$(basename -- "$file")
dir=$(dirname -- "$file")

if [[ "$filename" == .* ]]; then
  rest="${filename:1}"
  if [[ "$rest" == *.* ]]; then
    stem=".${rest%.*}"
    ext=".${rest##*.}"
  else
    stem="$filename"
    ext=""
  fi
elif [[ "$filename" == *.* ]]; then
  stem="${filename%.*}"
  ext=".${filename##*.}"
else
  stem="$filename"
  ext=""
fi

candidate="${stem} copy${ext}"
n=2
while [[ -e "$dir/$candidate" ]]; do
  candidate="${stem} copy ${n}${ext}"
  n=$((n + 1))
done

dest="$dir/$candidate"
cp -p -- "$file" "$dest"

zed_cli="/Applications/Zed.app/Contents/MacOS/cli"
if [[ ! -x "$zed_cli" ]]; then
  zed_cli="$(command -v zed)"
fi
"$zed_cli" --add "$dest"
