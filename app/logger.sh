#!/bin/bash
##############################
# Логирует успешные загрузки #
##############################
set -e

YT_ENV="/var/www/.config/yt-dlp"
PLUGIN_DIR="$YT_ENV/plugins/log_plugin/yt_dlp_plugins/postprocessor"
LOG_FILE="/var/log/yt_dlp.log"

id www-data &>/dev/null || { echo "Ошибка: пользователь www-data не найден"; exit 1; }

mkdir -p "$PLUGIN_DIR"
touch "$YT_ENV/plugins/log_plugin/yt_dlp_plugins/__init__.py"
touch "$PLUGIN_DIR/__init__.py"
echo '--use-postprocessor LogPluginPP' > "$YT_ENV/config"

touch "$LOG_FILE"
chown www-data:www-data "$LOG_FILE"
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
            timestamp = (datetime.datetime.utcnow() + datetime.timedelta(hours=3)).strftime('%Y-%m-%d %H:%M:%S')
            filename = information.get('filename', information.get('title', 'Unknown'))
            url = information.get('webpage_url', 'No URL')
            log_entry = f"{timestamp} | {filename} | {url}\n"

            with open(self.log_path, 'a', encoding='utf-8') as f:
                f.write(log_entry)
            
            # !!! ДОПОЛНИТЕЛЬНО !!!
            # Дублируем запись в stdout, чтобы docker logs показывал логи мгновенно,
            # даже если tail еще не подхватил изменение файла
            print(f"[YT-DLP LOG] {log_entry.strip()}", flush=True)
            
        except Exception as e:
            err_msg = f"LogPlugin Error: {str(e)}"
            self.to_stderr(err_msg)
            print(err_msg, file=sys.stderr, flush=True)

        return [], information
EOF

chown -R www-data:www-data "$YT_ENV"

echo "Контейнер запущен. Логи доступны через: docker logs <container_name>"
