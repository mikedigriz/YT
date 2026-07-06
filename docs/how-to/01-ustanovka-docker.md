# Установка через Docker

Если тебе нужен только рабочий сервис, а не разбор внутренностей - вот прямой путь.

## Запустить готовый образ

Собирать ничего не нужно - готовый образ лежит на [Docker Hub](https://hub.docker.com/r/mikedigriz/yt):

```bash
docker run -it -p 8000:80 mikedigriz/yt
```

Сайт откроется на `http://localhost:8000`.

Хочешь через `docker-compose` (удобнее, если сервис будет жить долго) - создай рядом `docker-compose.yml`:

```yaml
services:
  app:
    image: mikedigriz/yt
    tty: true
    restart: always
    container_name: yt
    env_file:
      - .env
    ports:
      - 8000:80
```

Создай рядом файл `.env` (можно пустой) и запусти:

```bash
touch .env
docker-compose up -d
```

Как прописать в `.env` прокси - в разделе [Настроить прокси через .env](#настроить-прокси-через-env) ниже.

## Собрать образ из исходников

Нужно, если планируешь вносить правки в код или хочешь собрать образ с нуля сам:

```bash
git clone https://github.com/mikedigriz/YT.git && cd YT
docker build -t yt .
docker run -it -p 8000:80 yt
```

Готовый `docker-compose.yml` в репозитории ссылается на локальный образ `yt:latest` - перед `docker-compose up -d` его нужно один раз собрать той же командой `docker build -t yt .`.

## Настроить прокси через .env

Если нужен SOCKS5-прокси, укажи его в `.env`:

```
SOCKS5_URL=socks5://user:pass@host:port
```

Пустая строка означает "прокси не используется" - все загрузки идут напрямую (кроме доменов из специального списка, см. [Backend](../explanation/02-backend.md)). Подробнее про саму настройку прокси - в [Настроить SOCKS5-прокси](03-socks5-proxy.md).

## Чистая пересборка

Если после изменений что-то работает не так, как ожидалось, пересобери образ без кеша:

```bash
docker build --no-cache -t yt .
```

## Установка без Docker

Так тоже можно, но придётся собирать окружение руками:

- PHP 8.4 с php-fpm
- nginx (или другой веб-сервер с поддержкой PHP)
- Python 3.13
- FFmpeg
- Node.js 22+
- yt-dlp, установленный с дополнительными зависимостями: `pip install "yt-dlp[default]"`

Дальше:

1. Скопируй содержимое `app/var/www/YT` в корень веб-сервера.
2. Настрой nginx по образцу `app/etc/nginx/sites-available/default`.
3. Пропиши правильные пути в `config/config.php` (`outputFolder`, `logPath`, `youtubedlExe`).
4. Запусти генератор плагина-логгера - содержимое скрипта `logger.sh` - вручную, чтобы создать Python-постпроцессор для yt-dlp.

Зачем нужен именно Node.js 22+ и что делает `logger.sh` - разобрано в [Архитектуре](../explanation/01-arhitektura.md).

## Настрой автоочистку и автообновление

Внутри контейнера cron не работает - он крутится от `www-data`, а демону cron нужен root. Поэтому периодические задачи (очистка старых файлов, ежедневное обновление yt-dlp) выносятся на хост.

```bash
sudo mkdir -p /var/log/yt
crontab -e
```

Вставь строки из готового примера [`app/etc/cron/host-crontab.example`](../../app/etc/cron/host-crontab.example) (замени имя контейнера `yt`, если у тебя оно другое):

```cron
# Обновление yt-dlp каждый день в 03:00
3 0 * * * docker exec -u root yt /etc/Scripts/update-ytdlp.sh >> /var/log/yt/ytdlp-update.log 2>&1

# Чистка файлов старше 120 минут каждый час
0 * * * * docker exec yt /etc/Scripts/2hourcleanup.sh >> /var/log/yt/cleanup.log 2>&1
```

Проверить, что очистка работает:

```bash
docker exec yt /etc/Scripts/2hourcleanup.sh && echo OK
```

Если cron вообще не настроен - сайт всё равно работает, просто очередь загрузок продвигается только пока кто-то держит вкладку открытой, а старые файлы не удаляются сами. Подробнее про эти скрипты - в [Структуре проекта](../reference/04-struktura-proekta.md).
