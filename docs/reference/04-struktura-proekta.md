# Структура проекта

```
YT/
├── app/
│   ├── start.sh                 точка входа контейнера
│   ├── logger.sh                генерирует Python-плагин логирования yt-dlp
│   ├── etc/
│   │   ├── nginx/                конфиг веб-сервера
│   │   ├── yt-dlp.conf            общий конфиг yt-dlp (лимиты, фильтр 18+)
│   │   ├── cron/                  пример хостового crontab
│   │   └── Scripts/                скрипты очистки старых файлов и обновления yt-dlp
│   ├── patches/                  патч для yt-dlp (мягкая очистка имён файлов)
│   └── var/www/YT/               сама веб-папка приложения
│       ├── index.php              точка входа, обработка запросов
│       ├── error_pages.php        страница ошибки CSRF
│       ├── class/
│       │   ├── Downloader.php     запуск и управление загрузками
│       │   ├── FileHandler.php    работа с файлами на диске
│       │   └── ProxyStatus.php    замер живости SOCKS5-прокси
│       ├── config/
│       │   ├── config.php          настройки сайта
│       │   └── favicon_domains.json список сайтов для подсказки иконок
│       ├── views/                  HTML-шаблоны страницы
│       ├── js/                     фронтенд-логика
│       ├── css/                    стили оформления
│       ├── download/               сюда сохраняются скачанные файлы
│       └── tmp/                    логи и очередь загрузок
├── Dockerfile
├── docker-compose.yml
├── minify_js.sh                  пересобирает минифицированную копию фронтенд-скрипта
├── patch_replace_insane.sh       переналагает патч yt-dlp вручную, без пересборки образа
├── load_favicons.py              скачивает иконки сайтов в favicons/
└── .env.example
```

## Скрипты и их назначение

| Скрипт | Когда запускается | Что делает |
|---|---|---|
| `app/start.sh` | старт контейнера | сохраняет версию yt-dlp, запускает php-fpm, nginx и сервер токенов bgutil |
| `app/logger.sh` | сборка образа | генерирует Python-постпроцессор логирования для yt-dlp |
| `app/etc/Scripts/cleanup.sh` | вручную | удаляет всё из `download/` и `tmp/` |
| `app/etc/Scripts/2hourcleanup.sh` | хостовый cron, раз в час | удаляет файлы старше `retentionMinutes` |
| `app/etc/Scripts/update-ytdlp.sh` | хостовый cron, раз в сутки | обновляет yt-dlp и переналагает патч имён файлов |
| `minify_js.sh` | вручную, после правки JS | пересобирает `js/youtubedlwebui.min.js` из читаемого исходника |
| `patch_replace_insane.sh` | вручную | переналагает патч мягкой очистки имён файлов без пересборки образа |
| `load_favicons.py` | вручную, при добавлении новых сайтов | скачивает недостающие иконки сайтов в `favicons/` |
