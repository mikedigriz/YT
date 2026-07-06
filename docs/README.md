# Документация Качалки

Качалка - это веб-интерфейс для скачивания видео и аудио с разных сайтов. Внутри работает yt-dlp, а Качалка добавляет к нему удобную страницу, очередь загрузок, перевод озвучки, обход блокировок и кучу мелких удобств.

Документация разложена по четырём разделам - в зависимости от того, что тебе сейчас нужно.

## Туториалы

Пошаговый путь для тех, кто видит проект впервые.

- [Первый запуск](tutorials/01-pervyy-zapusk.md) - от `git clone` до первого скачанного файла.

## How-to

Конкретные рецепты под конкретную задачу. Открывай нужный пункт, не читая остальное.

- [Установка через Docker](how-to/01-ustanovka-docker.md)
- [Скачать плейлист или канал](how-to/02-skachat-plaeylist.md)
- [Настроить SOCKS5-прокси](how-to/03-socks5-proxy.md)
- [Включить перевод озвучки](how-to/04-perevod-ozvuchki.md)
- [Починить обход бот-чека YouTube](how-to/05-obhod-bot-cheka.md)
- [Поставить сайт за HTTPS](how-to/06-reverse-proxy-https.md)
- [Обновить yt-dlp](how-to/07-obnovit-yt-dlp.md)
- [Настроить сайт под себя](how-to/08-nastroit-pod-sebya.md)

## Справочник

Точные цифры и таблицы, без объяснений "зачем".

- [Параметры конфигурации](reference/01-config-parametry.md)
- [Формат ответа ?jobs](reference/02-api-jobs-endpoint.md)
- [Коды ошибок yt-dlp](reference/03-oshibki-yt-dlp.md)
- [Структура проекта](reference/04-struktura-proekta.md)

## Объяснения

Как всё устроено внутри и почему сделано именно так - для тех, кто хочет залезть в код или просто разобраться в деталях.

- [Архитектура](explanation/01-arhitektura.md)
- [Backend на PHP](explanation/02-backend.md)
- [Frontend на JavaScript](explanation/03-frontend.md)
- [Очередь и другие технические решения](explanation/04-ochered-i-fishki.md)
- [Безопасность](explanation/05-bezopasnost.md)
- [Как устроены перевод и обход бот-чека YouTube](explanation/06-youtube-i-perevod-kak-eto-rabotaet.md)
- [Пасхалки](explanation/07-pashalki.md)
