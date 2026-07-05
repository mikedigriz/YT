#!/bin/bash
# Вклеивает переведённую Яндексом дорожку в скачанное видео.
# $1 - путь к видеофайлу (из yt-dlp --print-to-file after_move:filepath)
# $2 - каталог, куда vot-cli положил mp3 с переводом
# $3 - каталог загрузок, куда класть итоговый *_ru.mp4
set -e

video="$1"
transdir="$2"
outdir="$3"

if [ -z "$video" ] || [ ! -f "$video" ]; then
    echo "[vot] видео не найдено: $video"
    exit 1
fi

# vot-cli пишет mp3 с именем по заголовку ролика - берём самый свежий в каталоге
trans="$(ls -t "$transdir"/*.mp3 2>/dev/null | head -n1)"
if [ -z "$trans" ] || [ ! -f "$trans" ]; then
    echo "[vot] перевод не найден в $transdir"
    exit 1
fi

base="$(basename "${video%.*}")"
out="$outdir/${base}_ru.mp4"

# Переведённая дорожка первой (default), оригинал вторым - ничего не теряем.
# -map 0:a:0? - оригинальное аудио опционально (у некоторых источников его нет).
ffmpeg -y -i "$video" -i "$trans" \
    -map 0:v:0 -map 1:a:0 -map 0:a:0? \
    -c:v copy -c:a aac -b:a 192k \
    -metadata:s:a:0 title="Перевод (Яндекс)" -metadata:s:a:0 language=rus \
    -metadata:s:a:1 title="Оригинал" \
    -disposition:a:0 default \
    "$out"

# Исходное видео заменено переведённым - убираем, чтобы не дублировать в списке
rm -f "$video"
echo "[vot] готово: $out"
