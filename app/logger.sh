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
            log_entry = f"{timestamp} | {filename} | {url}\n"

            with open(self.log_path, 'a', encoding='utf-8') as f:
                f.write(log_entry)
                f.flush()
            
        except Exception as e:
            err_msg = f"LogPlugin Error: {str(e)}"
            with open(self.log_path, 'a', encoding='utf-8') as f:
                f.write(f"ERROR: {err_msg}\n")
                f.flush()

        return [], information
EOF