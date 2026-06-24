#!/bin/bash
set -e

YT_ENV="/var/www/.config/yt-dlp"
PLUGIN_DIR="$YT_ENV/plugins/log_plugin/yt_dlp_plugins/postprocessor"
LOG_FILE="/var/log/yt_dlp.log"

mkdir -p "$PLUGIN_DIR"
touch "$YT_ENV/plugins/log_plugin/yt_dlp_plugins/__init__.py"
touch "$PLUGIN_DIR/__init__.py"
echo '--use-postprocessor LogPluginPP' > "$YT_ENV/config"

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
