#!/bin/bash
# Приводит скачанное видео к воспроизводимому mp4.
# $1 - путь к готовому файлу (из yt-dlp --exec after_video:... %(filepath)q)
#
# Контейнер к этому моменту уже mp4 (--remux-video), но контейнера мало: VP9 и
# Opus кладутся в mp4 законно, а Safari, iOS и QuickTime такой файл не откроют.
# Поэтому смотрим ffprobe'ом, что внутри, и перекодируем ТОЛЬКО ту дорожку,
# которая действительно несовместима. В типичном случае (h264+aac) скрипт стоит
# одного вызова ffprobe и ничего не трогает.
#
# Из задачи вызывается через --exec, то есть под тем же nice/ionice, что и сам
# yt-dlp - тяжёлый прогон libx264 не выбивает веб-морду с диска и процессора.
#
# Ненулевым кодом не завершаемся никогда: файл уже скачан, и превращать
# успешную задачу в неудачную из-за косметики нельзя. Не вышло - оставляем как
# есть и говорим об этом в лог.

video="$1"

# HEVC и AV1 оставляем намеренно: перекодировать их в H.264 значит потерять
# качество и сжечь процессор ради узкого круга старых плееров, а Safari и
# Chrome нынешних версий играют оба.
VIDEO_OK="h264 hevc av1"
AUDIO_OK="aac mp3 alac ac3 eac3"

# Потолок длительности. Контейнер к этому моменту уже правильный, а прогон
# восьмичасовой записи эфира через libx264 занял бы сервер на сутки.
MAX_MINUTES="${MP4_COMPAT_MAX_MINUTES:-90}"

if [ -z "$video" ] || [ ! -f "$video" ]; then
    echo "[mp4] файл не найден: $video"
    exit 0
fi

# Аудио и живая запись (.ts пишется по ходу эфира) - не наш случай.
# Остальные видеоконтейнеры разбираем: сюда попадает не только уже готовый mp4,
# но и файл, которому ремукс yt-dlp не удался ("ERROR: Conversion failed!" -
# такой оставался лежать в webm рядом с огрызком mp4 от упавшего ffmpeg).
case "${video,,}" in
    *.mp4|*.m4v|*.webm|*.mkv|*.mov|*.avi|*.flv) ;;
    *) exit 0 ;;
esac

target="${video%.*}.mp4"
same_container=1
case "${video,,}" in
    *.mp4) ;;
    *) same_container=0 ;;
esac

probe() {
    ffprobe -v error -select_streams "$1" -show_entries stream=codec_name \
        -of default=noprint_wrappers=1:nokey=1 "$video" 2>/dev/null | head -n1
}

vcodec="$(probe v:0)"
acodec="$(probe a:0)"

if [ -z "$vcodec" ]; then
    # Битый исходник: чинить нечего, а verifyPlayable() всё равно пометит
    # задачу неудачной - пусть человек видит честную ошибку, а не молчание.
    echo "[mp4] видеодорожка не читается, оставляю как есть"
    exit 0
fi

in_list() {
    case " $2 " in *" $1 "*) return 0 ;; *) return 1 ;; esac
}

vargs="-c:v copy"
aargs="-c:a copy"
need=0

if ! in_list "$vcodec" "$VIDEO_OK"; then
    # yuv420p - профиль, который умеют вообще все; исходник может быть 10-битным
    vargs="-c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p"
    need=1
fi

# Звука может не быть вовсе (превью со стоков) - тогда про -c:a молчим
if [ -z "$acodec" ]; then
    aargs="-an"
elif ! in_list "$acodec" "$AUDIO_OK"; then
    aargs="-c:a aac -b:a 192k"
    need=1
fi

# Контейнер не тот - работа нужна, даже если кодеки в порядке. Тогда это тот же
# ремукс копированием потоков, что не осилил yt-dlp: второй заход дешёвый, а
# альтернатива - оставить .webm лежать под видом результата.
if [ "$same_container" -eq 0 ]; then
    need=1
fi

if [ "$need" -eq 0 ]; then
    exit 0
fi

duration="$(ffprobe -v error -show_entries format=duration \
    -of default=noprint_wrappers=1:nokey=1 "$video" 2>/dev/null | cut -d. -f1)"
if [ -n "$duration" ] && [ "$duration" -gt $((MAX_MINUTES * 60)) ] 2>/dev/null; then
    echo "[mp4] $vcodec/$acodec, но длительность больше $MAX_MINUTES мин - перекодировать не стану"
    exit 0
fi

echo "[mp4] привожу к совместимому виду (видео $vcodec, звук ${acodec:-нет})"

# Хвост намеренно НЕ .mp4: временный файл лежит в каталоге загрузок, а
# FileHandler::listMedia() перебирает его по расширению - готовый .mp4 успел бы
# мелькнуть во вкладке "Видео" на ближайшем опросе. Расширения нет - значит
# нужен явный -f mp4, сам ffmpeg контейнер по имени не угадает.
tmp="$video.mp4compat"
# shellcheck disable=SC2086 - vargs/aargs это именно набор аргументов
if ffmpeg -y -v warning -stats -i "$video" \
    $vargs $aargs -movflags +faststart -f mp4 "$tmp" </dev/null; then
    mv -f "$tmp" "$target"
    if [ "$target" != "$video" ]; then
        rm -f "$video"
        # Имя файла изменилось - показываем его строкой "Destination:", той же,
        # по которой Downloader находит результат задачи (jobProducedFile,
        # verifyPlayable, имя в таблице). Иначе задача считалась бы неудачной
        # из-за того, что старого имени на диске уже нет.
        echo "[mp4] Destination: $target"
    fi
    echo "[mp4] готово: $(basename "$target")"
else
    rm -f "$tmp"
    echo "[mp4] перекодировать не вышло, оставляю исходный файл"
fi

exit 0
