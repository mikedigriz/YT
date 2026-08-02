#!/bin/bash
# Возрастная чистка. Окно берётся из config['retentionMinutes'] - тот же
# источник, что у шкалы таймера в интерфейсе, чтобы они не расходились.
YTDL_EXE='/.yt_env/bin/yt-dlp'
CONFIG='/var/www/YT/config/config.php'

RETENTION=''
if command -v php >/dev/null 2>&1; then
    RETENTION=$(php -r '$c=@include $argv[1]; echo (int)($c["retentionMinutes"] ?? 0);' "$CONFIG" 2>/dev/null)
fi
if ! [ "$RETENTION" -gt 0 ] 2>/dev/null; then
    # php-cli в образе может не оказаться - конфиг простой, читаем строкой
    RETENTION=$(sed -n "s/.*'retentionMinutes'[[:space:]]*=>[[:space:]]*\([0-9]\+\).*/\1/p" "$CONFIG" | head -n 1)
fi
if ! [ "$RETENTION" -gt 0 ] 2>/dev/null; then
    RETENTION=120
fi

# Живые задачи не трогаем: у pid_-файла mtime равен времени запуска, поэтому
# запись длиннее окна чистки иначе теряет pid_ (пропадает строка и кнопка
# "Стоп", лог не финализируется) вместе с накопленным job_-логом.
keep=()
for p in /var/www/YT/tmp/pid_*; do
    [ -f "$p" ] || continue
    jpid=$(head -n 1 "$p" | tr -d '[:space:]')
    case "$jpid" in ''|*[!0-9]*) continue;; esac
    [ -r "/proc/$jpid/cmdline" ] || continue
    # PID-reuse guard, как в Downloader::background_jobs()
    tr '\0' ' ' < "/proc/$jpid/cmdline" | grep -qF "$YTDL_EXE" || continue
    base=${p##*/}
    keep+=(-not -name "$base" -not -name "job_${base#pid_}")
done

find /var/www/YT/tmp/ -type f -mmin "+$RETENTION" "${keep[@]}" -delete

# пропускаем файлы с .keep-маркером (тот же, что уважает FileHandler::deleteAll())
find /var/www/YT/download/ -type f -mmin "+$RETENTION" ! -name '*.keep' -print | while IFS= read -r f; do
    [ -e "${f}.keep" ] && continue
    rm -f -- "$f"
done
