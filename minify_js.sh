#!/usr/bin/env bash
# Регенерирует минифицированные копии JS из читаемых исходников.
#
# Подключаются .min.js, поэтому прогонять после любой правки исходников.
# Нужен rjsmin (pip install rjsmin), только для разработки.
#
# qrcode.min.js не трогаем - это вендоренная либа, её "исходник" живёт в upstream.
set -euo pipefail

cd "$(dirname "$0")/app/var/www/YT/js"

declare -a SOURCES=("youtubedlwebui.js" "feedback.js" "eggs.js")

for src in "${SOURCES[@]}"; do
    out="${src%.js}.min.js"
    python3 - "$src" "$out" <<'PY'
import sys, rjsmin
src, out = sys.argv[1], sys.argv[2]
code = open(src, encoding='utf-8').read()
mini = rjsmin.jsmin(code)
open(out, 'w', encoding='utf-8', newline='\n').write(mini + '\n')
print("%s: %d -> %d bytes" % (out, len(code), len(mini)))
PY
done
