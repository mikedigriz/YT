#!/bin/bash
set -e

yt-dlp --version > /var/www/YT/yt_dlp_version

# Сервер PO-токенов (bgutil) для обхода бот-чека YouTube. Слушает 127.0.0.1:4416,
# yt-dlp-плагин ходит к нему на каждой youtube-загрузке. Падение сервера не роняет
# контейнер - просто вернётся прежняя ошибка "confirm you're not a bot".
if [ -f /opt/bgutil/server/build/main.js ]; then
    node /opt/bgutil/server/build/main.js > /var/www/YT/tmp/bgutil.log 2>&1 &
fi

php-fpm -F &

# Воркер загрузок (bin/worker.php): двигает очередь и ретраи без открытой вкладки.
# Выходит сам при смене кода или раз в час - цикл поднимает его заново. Вывод
# идёт в stdout контейнера, то есть в "docker logs".
if [ -f /var/www/YT/bin/worker.php ]; then
    # Плановые выходы - код 0, молча. Ненулевой код значит падение (фатал PHP и т.п.)
    ( while :; do
        php /var/www/YT/bin/worker.php
        rc=$?
        [ "$rc" -ne 0 ] && echo "[worker] упал с кодом $rc, перезапускаю"
        sleep 1
    done ) &
fi
tail -F /var/log/yt_dlp.log &
# События обратной связи (новое обращение, ответ, удаление) - тем же способом,
# что и лог yt-dlp: файл на диске плюс tail в stdout PID 1, поэтому строки видны
# в "docker logs". Файл создаётся в Dockerfile; -F переживает его пересоздание.
tail -F /var/log/feedback.log &

exec nginx -g "daemon off;"
