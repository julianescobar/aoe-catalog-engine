#!/bin/bash
# Rename files with URL-encoded characters to decoded names
# Put this script in the images/ directory and run: bash rename-files.sh

for f in *%*; do
  [ -f "$f" ] || continue
  new=$(python3 -c "import urllib.parse; print(urllib.parse.unquote('$f'))" 2>/dev/null)
  if [ "$new" != "$f" ] && [ ! -e "$new" ]; then
    mv -n "$f" "$new"
    echo "OK: $f -> $new"
  fi
done
echo "Done."
