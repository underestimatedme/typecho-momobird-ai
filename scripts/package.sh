#!/usr/bin/env bash
# Build the installable plugin archive: dist/MomoBirdAI-<version>.zip with a single
# top-level MomoBirdAI/ directory (Typecho requires the folder name to match the plugin).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n 's/^ \* @version \(.*\)$/\1/p' "$root/Plugin.php" | head -1)"
[ -n "$version" ] || { echo "cannot read @version from Plugin.php" >&2; exit 1; }

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/MomoBirdAI" "$root/dist"
for item in Plugin.php Action.php panel.php README.md lib assets; do
  cp -R "$root/$item" "$stage/MomoBirdAI/"
done

out="$root/dist/MomoBirdAI-$version.zip"
rm -f "$out"
(cd "$stage" && zip -qr -X "$out" MomoBirdAI -x '*.DS_Store')
echo "$out"
