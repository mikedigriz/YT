# Коды ошибок yt-dlp

yt-dlp сам по себе выдаёт технические сообщения об ошибках на английском. Качалка разбирает лог по таблице правил и подменяет его на короткое понятное сообщение на русском. Правила проверяются по порядку сверху вниз, первое совпадение побеждает - поэтому сетевые ошибки идут раньше, чем ошибки контента.

Таблица ниже - это `ERROR_RULES` из `app/var/www/YT/class/Downloader.php`.

## Бот-детект YouTube

| Что видит yt-dlp | Что видит пользователь |
|---|---|
| `cookies are no longer valid` / `cookies have expired` | Куки YouTube протухли. Надо зайти под тем же аккаунтом и обновить cookies.txt на сервере |
| `not a bot` / `Sign in to confirm you're not a bot` | YouTube принял нас за бота. IP прокси засвечен - лучше подождать |

## Сетевые ошибки

| Что видит yt-dlp | Что видит пользователь |
|---|---|
| `Name or service not known` / `Could not resolve host` | DNS не резолвил хост. Проверь ссылку или интернет |
| `Connection refused` / `ECONNREFUSED` | Сервер сказал "нет". Connection refused |
| `timed out` / `ETIMEDOUT` | Тайм-аут. Сервер слишком долго молчит |
| `Network is unreachable` / `ENETUNREACH` | Сеть недоступна |
| `No route to host` | Маршрута до хоста нет. Проверь прокси/сеть |
| `HTTP Error 429` / `Too Many Requests` | Сайт оверлоуд. Подожди |
| `HTTP Error 403` / `403 Forbidden` | 403 Forbidden. Не пущает - нужен прокси/куки |
| `HTTP Error 404` / `404 Not Found` | 404 Not Found. Страницы больше нет |
| `HTTP Error 503` | 503. Сайт прилёг |
| `HTTP Error 500` | 500. У сайта внутренние проблемы |
| `SSL handshake` / `certificate verify failed` | Ошибка SSL/HTTPS. Сертификат не прошёл проверку |
| `Unable to download webpage` | Не удалось открыть страницу |
| `Unable to connect to` / `Connection aborted` | Соединение оборвалось. Попробуй ещё раз |

## Доступность контента

| Что видит yt-dlp | Что видит пользователь |
|---|---|
| `Video unavailable` | Видео недоступно |
| `Private video` | Приватное видео. Только для своих |
| `has been removed` | Видео удалено автором |
| `age-restricted` / `confirm your age` | 18+ контент. Нужны куки авторизованного аккаунта |
| `login required` / `Sign in to` | Нужна авторизация. Нужны куки авторизованного аккаунта |
| `members-only` | Members-only. Нужна подписка на канал тем же аккаунтом, чьи куки настроены |
| `Music Premium` | YTMusic Premium. Требуется подписка |
| `requires payment` / `purchase this` | Платный контент. Скачивание невозможно |
| `live event will begin` / `Premieres in` | Ну начинается - пойду поссу, пойду посру |
| `region-locked` / `geo-blocked` | Гео-блок. Видео недоступно в регионе Качалки |
| `This channel is not available` | Канал не существует или удалён |

## Форматы и извлечение

| Что видит yt-dlp | Что видит пользователь |
|---|---|
| `Unsupported URL` / `no suitable extractor` | Сайт не поддерживается. Проверь ссылку |
| `No video formats` / `no formats available` | Форматов для скачивания нет. Видео без дорожек? |
| `unable to extract video url` | Не удалось извлечь ссылку на видео. Сайт поменялся? |
| `Incomplete YouTube ID` / `Invalid URL` | Ссылка выглядит кривой. Проверь URL |
| `This video is encrypted` | Видео зашифровано. Скачивание невозможно |
| `DRM-protected` / `has DRM` | DRM-защита. Обход невозможен |

## Постобработка

| Что видит yt-dlp | Что видит пользователь |
|---|---|
| `ffmpeg not found` | FFmpeg не найден. Установи его на сервер |
| `Postprocessing failed` / `conversion failed` | Ошибка постобработки (ffmpeg). Файл мог повредиться |

## Системные ошибки

| Что видит yt-dlp | Что видит пользователь |
|---|---|
| `Permission denied` / `EACCES` | Нет прав на запись. Проверь права на папку |
| `No space left on device` / `ENOSPC` | Диск переполнен |

## Если ничего не подошло

Если ни одно правило не сработало, пользователю показывается сырая строка `ERROR:` прямо из лога yt-dlp - без перевода, но хоть что-то, а не пустой экран.

Как добавить новое правило: вставить строку в таблицу `ERROR_RULES` в `class/Downloader.php` - порядок значим, более специфичные и приоритетные ошибки должны стоять выше общих.
