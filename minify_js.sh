#!/usr/bin/env bash
# Регенерирует минифицированные копии JS из читаемых исходников.
#
# В проекте нет сборки: отдаётся ровно то, что лежит в js/. Чтобы не править
# минифицированный код руками, исходник youtubedlwebui.js остаётся читаемым, а
# в HTML (part.header.php) подключается youtubedlwebui.min.js. После любой правки
# исходника прогони этот скрипт, иначе на сайте поедет старая версия.
#
# Зависит от rjsmin (pip install rjsmin) - инструмент только для разработки,
# в рантайме/образе не нужен. rjsmin корректно держит ES6 template literals и
# регэкспы (проверено), при этом не переименовывает идентификаторы, так что
# минификат поведенчески идентичен исходнику.
#
# qrcode.min.js не трогаем - это вендоренная либа, её "исходник" живёт в upstream.
set -euo pipefail

cd "$(dirname "$0")/app/var/www/YT/js"

declare -a SOURCES=("youtubedlwebui.js")

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
