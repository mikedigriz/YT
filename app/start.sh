#!/bin/bash
set -e

yt-dlp --version > /var/www/YT/yt_dlp_version

# Сервер PO-токенов (bgutil) для обхода бот-чека YouTube. Слушает 127.0.0.1:4416,
# yt-dlp-плагин ходит к нему на каждой youtube-загрузке. Падение сервера не роняет
# контейнер - просто вернётся прежняя ошибка "confirm you're not a bot".
#
# Свой node, а не системный: сервер приезжает собранным из образа апстрима, и
# нативный canvas в нём собран под ABI Node 26. Системный у нас 22 - он нужен
# EJS-решателям yt-dlp, и подменять его нельзя.
if [ -f /opt/bgutil/server/build/main.js ]; then
    /opt/bgutil/node /opt/bgutil/server/build/main.js > /var/www/YT/tmp/bgutil.log 2>&1 &
fi

php-fpm8.4 -F &
tail -F /var/log/yt_dlp.log &
# События обратной связи (новое обращение, ответ, удаление) - тем же способом,
# что и лог yt-dlp: файл на диске плюс tail в stdout PID 1, поэтому строки видны
# в "docker logs". Файл создаётся в Dockerfile; -F переживает его пересоздание.
tail -F /var/log/feedback.log &

exec nginx -g "daemon off;"
