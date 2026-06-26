<p align="right">
  <a href="docs/README.md"><img src="https://img.shields.io/badge/📖-docs-blue?style=flat-square&labelColor=gray" alt="Документация" height="20"></a>
</p>

<div align="center">

# 🚀 Качалка

**Веб-интерфейс для yt-dlp в одном Docker-контейнере**

[![Docker Pulls](https://img.shields.io/docker/pulls/mikedigriz/yt?style=for-the-badge&logo=docker&logoColor=white&labelColor=2496ED&color=1E3A8A)](https://hub.docker.com/r/mikedigriz/yt)
[![Docker Image Size](https://img.shields.io/docker/image-size/mikedigriz/yt/latest?style=for-the-badge&logo=docker&logoColor=white&labelColor=2496ED&color=1E3A8A)](https://hub.docker.com/r/mikedigriz/yt)
[![GitHub Stars](https://img.shields.io/github/stars/mikedigriz/YT?style=for-the-badge&logo=github&logoColor=white&color=4A90D9)](https://github.com/mikedigriz/YT)

[![Powered by yt-dlp](https://img.shields.io/badge/POWERED%20BY-yt--dlp-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://github.com/yt-dlp/yt-dlp)

</div>

## Описание

Позволяет скачивать практически с любого ресурса видео.
- Конвертирует в mp4 и хранит на сервере.
- Есть возможность забрать аудиодорожку с видео.
- По умолчанию скачивается максимальное качество видео.
- Подходит для использования с устройствами на базе Win, macOS, Linux, Android, iOS.
- Поддержка SOCKS5-прокси для обхода региональных ограничений
- Кастомный логгер загрузок
- Ограничение загрузки контента 18+
- Обработка ошибок, управление процессами и безопасность
- Удобен для работы с контентом для SMM-групп и контент-маркетологов

<p align="center">
    <img src="res/YT.webp" width="65%">
</p>

## Как пользоваться
1. Вставить прямую ссылку в строку
2. Кнопка **СКАЧАТЬ**
3. Отследить что загрузка завершена
4. Перейти на вкладку **Видео**
5. Забрать файл

**Дополнительно:**
- Формат: по умолчанию лучшее качество в mp4. Для аудио - переключите режим перед загрузкой
- Прокси: укажите SOCKS5 в `.env` → параметр `SOCKS5_URL` (формат: `socks5://user:pass@host:port`)
- Доп. параметры yt-dlp: файл `/etc/yt-dlp.conf`
- Логи: `docker logs <container>`

## Docker
_Предполагается что docker установлен и вы умеете пользоваться гуглом._

_Готовый образ забрать можно [тут](https://hub.docker.com/r/mikedigriz/yt)_

---
Установка:
```
git clone https://github.com/mikedigriz/YT.git && cd YT
docker build -t yt .
```
Запуск:
```
docker run -it -p 8000:80 yt
```
Чистая пересборка:
```
docker build --no-cache -t yt .
```

## Рекомендации
- Если нет необходимости долго хранить файлы. В **cron** можно добавить автоматическое удаление.  
Пример скрипта тут: ```app/etc/Scripts```


- Названия файлов: используется измененный файл **_utils.py** для yt-dlp.  
При проблемах после обновления yt-dlp проверь этот файл.


- Когда нужен доступ с других устройств, вспомните про **route**.  
Для этого в командной строке ПК или настройках роутера добавьте маршрут.  
Пример для Windows:
```route add 172.17.0.0 mask 255.255.0.0 192.168.1.100```


- Если с ресурса перестало загружать - посмотрите на открытые [ишью библиотеки yt-dlp](https://github.com/yt-dlp/yt-dlp/issues).
## Баги бэка

- Скачивается видео без звука - проблема может быть в **yt-dlp** или **ffmpeg**. 
- Загрузка не начинается и во вкладках пусто - проблема с правами на каталог.
- Загрузка началась и упала с ошибкой - сайты меняются, а некоторые как YouTube - борется с загрузками.  
Проверить актуальность библиотеки **yt-dlp**. Проверить работает ли загрузка из консоли.

