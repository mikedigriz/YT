#!/bin/bash
set -e

yt-dlp --version > /var/www/YT/yt_dlp_version

./logger.sh

php-fpm8.4 -F &
tail -F /var/log/yt_dlp.log &

exec nginx -g "daemon off;"