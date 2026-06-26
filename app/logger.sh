#!/bin/bash
set -e

YT_ENV="/etc/yt-dlp"
PLUGIN_DIR="$YT_ENV/plugins/log_plugin/yt_dlp_plugins/postprocessor"
LOG_FILE="/var/log/yt_dlp.log"

mkdir -p "$PLUGIN_DIR"
# yt_dlp_plugins/ и postprocessor/ - PEP 420 namespace-пакеты, __init__.py не нужен.
# Постпроцессор подключается явно в команде yt-dlp (Downloader::executeDownload)
# через --plugin-dirs + --use-postprocessor, поэтому файл config тут НЕ пишем:
# иначе LogPluginPP подключился бы дважды и строки в логе дублировались бы.

touch "$LOG_FILE"
chmod 644 "$LOG_FILE"

cat > "$PLUGIN_DIR/log_plugin.py" << 'EOF'
import datetime
import os
import fcntl
from yt_dlp.postprocessor import PostProcessor

class LogPluginPP(PostProcessor):
    def __init__(self, downloader=None):
        super().__init__(downloader)
        self.log_path = '/var/log/yt_dlp.log'

    def run(self, information):
        try:
            tz_moscow = datetime.timezone(datetime.timedelta(hours=3))
            timestamp = datetime.datetime.now(tz_moscow).strftime('%Y-%m-%d %H:%M:%S')

            filename = information.get('filename', information.get('title', 'Unknown'))
            url = information.get('webpage_url', 'No URL')
            client_ip = os.environ.get('CLIENT_IP', 'unknown')

            log_entry = f"{timestamp} | {filename.replace('/var/www/YT/download/', '')} | {url} | {client_ip}"

            with open(self.log_path, 'a', encoding='utf-8') as f:
                fcntl.flock(f.fileno(), fcntl.LOCK_EX)
                try:
                    f.write(log_entry + "\n")
                    f.flush()
                finally:
                    fcntl.flock(f.fileno(), fcntl.LOCK_UN)

            print(log_entry, flush=True)

        except Exception as e:
            err_msg = f"LogPlugin Error: {str(e)}"

            with open(self.log_path, 'a', encoding='utf-8') as f:
                try:
                    f.write(f"ERROR: {err_msg}\n")
                    f.flush()
                except:
                    pass

            print(f"ERROR: {err_msg}", flush=True)

        return [], information
EOF
