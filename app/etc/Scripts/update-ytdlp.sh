#!/bin/bash
set -e

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting yt-dlp self-update..."

source /.yt_env/bin/activate

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Upgrading yt-dlp[default]..."
pip install -U "yt-dlp[default]" 2>&1

# pip перезаписывает _utils.py свежей версией, поэтому патч filename sanitizing
# нужно накатывать заново после каждого апгрейда. --fuzz=0 и set -e сверху дают
# громкий провал (видно в /var/log/ytdlp-update.log), если контекст уехал вверх -
# то же поведение, что и при сборке образа.
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Reapplying replace_insane patch..."
cd /.yt_env/lib/python3.13/site-packages && patch -p0 --fuzz=0 < /patches/replace_insane.patch

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Writing version file..."
yt-dlp --version > /var/www/YT/yt_dlp_version

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Self-update completed successfully. New version: $(cat /var/www/YT/yt_dlp_version)"
