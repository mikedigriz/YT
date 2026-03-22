#!/bin/bash
set -e

# Сохраняем версию yt-dlp
yt-dlp --version > /var/www/YT/yt_dlp_version

# Запускаем настройку логгера
./logger.sh

# Запускаем сервисы
service php8.4-fpm start
service nginx start

exec tail -F /var/log/yt_dlp.log