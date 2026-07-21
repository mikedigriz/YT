#!/bin/bash
set -e

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting yt-dlp self-update..."

source /.yt_env/bin/activate

# запоминаем версию до апгрейда - если патч потом не наляжет, откатываемся на неё
OLD_VERSION=$(yt-dlp --version 2>/dev/null || echo "")

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Upgrading yt-dlp[default]..."
pip install -U "yt-dlp[default]" 2>&1

# патч нужно накатывать заново после каждого апгрейда (pip перезаписывает _utils.py).
# if/! исключает команду из-под set -e, чтобы откат ниже успел выполниться
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Reapplying replace_insane patch..."
if ! (cd /.yt_env/lib/python3.13/site-packages && patch -p0 --fuzz=0 < /patches/replace_insane.patch); then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Patch failed to apply!"
    if [ -n "$OLD_VERSION" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rolling back to yt-dlp==$OLD_VERSION..."
        pip install --no-cache-dir "yt-dlp[default]==$OLD_VERSION" 2>&1
        # откаченный _utils.py тоже приходит непропатченным - нужно наложить заново
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Reapplying patch to rolled-back version..."
        if (cd /.yt_env/lib/python3.13/site-packages && patch -p0 --fuzz=0 < /patches/replace_insane.patch); then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rolled back and patched successfully. app/patches/replace_insane.patch needs regenerating for the newer version."
        else
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Patch failed even on rolled-back version $OLD_VERSION - prod is now running UNPATCHED yt-dlp. Needs manual intervention."
        fi
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] No previous version recorded - cannot roll back automatically."
    fi
    exit 1
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Writing version file..."
yt-dlp --version > /var/www/YT/yt_dlp_version

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Self-update completed successfully. New version: $(cat /var/www/YT/yt_dlp_version)"
