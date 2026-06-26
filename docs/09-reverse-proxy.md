# Внешний nginx перед Docker-контейнером

Внутри самого контейнера уже есть свой nginx - он отдаёт статику и передаёт PHP-запросы в php-fpm (см. [Конфигурацию](05-config.md#nginx)). Но в продакшене перед контейнером обычно ставится ещё один, внешний nginx, который работает прямо на хосте. Получается два слоя: внешний nginx занимается TLS, доменом, защитой от ботов и кешированием, а внутренний нginx внутри контейнера просто отдаёт сайт по обычному HTTP на свой порт.

Эта страница разбирает именно внешний слой - конфиг, который стоит перед Docker и проксирует запросы внутрь контейнера.

## Общая схема

```
Интернет → внешний nginx (хост, TLS, HTTP/3, кеш, защита от ботов)
              → контейнер Качалки (внутренний nginx → php-fpm → index.php)
```

Файлы:
- `/etc/nginx/nginx.conf` - общие настройки сервера: лимиты, gzip, зоны для ограничения запросов, список upstream-серверов.
- `/etc/nginx/sites-enabled/YT_prod` - конкретный сайт: редирект на HTTPS, TLS, кеш, проксирование на контейнер.
- `/etc/nginx/snippets/security-headers.conf` - набор заголовков безопасности, общий кусок, который подключается в нескольких местах через `include`, чтобы не дублировать одни и те же строки.

## Почему это хорошая конфигурация

### Редирект на HTTPS и сразу отсев "не браузеров"

Первый `server`-блок слушает порт 80 (обычный HTTP) и ничего не делает, кроме как переводит всех на HTTPS:

```nginx
if ($block_non_browser) { return 444; }
return 301 https://example.com$request_uri;
```

Здесь два решения сразу. Во-первых, `return 444` - это специальный код nginx, который не отдаёт вообще никакого ответа и просто рвёт соединение. Подозрительным клиентам (сканерам портов, ботам, ломающим инструментам) не достаётся даже текста ошибки - незачем тратить ресурсы сервера и подсказывать атакующему, что сервер вообще что-то ответил. Во-вторых, для всех остальных - обычный 301-редирект на HTTPS, чтобы сайт никогда не отдавался по незащищённому каналу.

### Определение "не браузера" через карту user-agent

В `nginx.conf` есть блок `map`, который заранее, один раз для каждого запроса, решает - это похоже на настоящий браузер или нет:

```nginx
map $http_user_agent $block_non_browser {
    default 1;
    "~*\b(zgrab|gobuster|nikto|sqlmap|nmap|...)\b" 1;
    "~*(go-http-client|python-?requests?|...)" 1;
    "~*\b(curl|wget|java|php|headless)\b" 1;
    "~*(googlebot|bingbot|yandexbot|...)" 0;
    "~*^Mozilla/5\.0" 0;
}
```

По умолчанию (`default 1`) всё считается подозрительным. Дальше идут явные сигнатуры известных сканеров безопасности и автоматических инструментов (`nikto`, `sqlmap`, `nmap` и десятки других) - они тоже помечаются как заблокированные. Но отдельно есть исключения: легитимные поисковые боты (Google, Яндекс, Bing) и обычные браузеры (строка user-agent начинается с `Mozilla/5.0`, как у всех настоящих браузеров) явно помечаются как "0" - не блокировать.

Это умно сделано именно через `map`: проверка происходит один раз и результат сохраняется в переменную, а не пересчитывается заново в каждом `location`. Дальше эта переменная просто проверяется (`if ($block_non_browser)`) везде, где нужно - в редиректе на HTTPS и в основном сайте.

### Раздельные логи для заблокированных запросов

```nginx
access_log /var/log/nginx/access.log;
access_log /var/log/nginx/blocked.log combined if=$block_non_browser;
```

Запросы от подозрительных ботов одновременно попадают и в общий лог, и в отдельный `blocked.log` - так удобно отдельно смотреть, кто и как часто пытается просканировать сайт, не засоряя этим основной лог посещений.

### HTTP/3 (QUIC) вместе с HTTP/2

```nginx
listen 443 quic reuseport;
listen 443 ssl;
http2 on;
http3 on;
add_header Alt-Svc 'h3=":443"; ma=86400';
```

Сайт слушает 443-й порт сразу по двум протоколам: обычный TLS (для HTTP/2, его поддерживают все браузеры) и QUIC (для HTTP/3, более новый протокол на основе UDP). Заголовок `Alt-Svc` сообщает браузеру: "тут ещё доступен HTTP/3, попробуй его". Если браузер поддерживает HTTP/3 - соединения становятся быстрее и устойчивее к потере пакетов, особенно на мобильном интернете. Если не поддерживает - просто работает по HTTP/2, ничего не ломается. `reuseport` позволяет нескольким рабочим процессам nginx эффективно делить нагрузку QUIC-соединений между собой на уровне ядра системы.

### Современный и безопасный TLS

```nginx
ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers on;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:...;
ssl_session_cache shared:SSL:50m;
ssl_session_tickets off;
```

Старые и взломанные версии протокола (SSLv3, TLS 1.0, TLS 1.1) явно не указаны в списке - значит, не поддерживаются. Список шифров составлен из современных наборов с прямой секретностью (ECDHE) - даже если ключ сервера однажды утечёт, расшифровать перехваченный трафик прошлых сессий не получится. `ssl_session_cache` ускоряет повторные подключения (не нужно заново делать весь TLS-handshake), а `ssl_session_tickets off` отключает один из менее безопасных способов переиспользования сессии (тикеты сложнее ротировать безопасно, чем общий кеш на сервере).

### Защита от медленных атак (slowloris)

```nginx
client_body_timeout 10s;
client_header_timeout 10s;
send_timeout 10s;
```

Slowloris - это атака, при которой клиент специально отправляет данные очень медленно, по чуть-чуть, чтобы занять соединение и не дать освободить ресурс серверу. Эти три таймаута гарантируют, что если клиент тянет с отправкой заголовков, тела запроса или nginx тянет с отправкой ответа дольше 10 секунд, соединение просто обрывается - ресурсы сервера не держатся в подвешенном состоянии бесконечно.

### Ограничение скорости запросов и числа соединений

```nginx
limit_req_zone $binary_remote_addr zone=general:10m rate=10r/s;
limit_conn_zone $binary_remote_addr zone=conn_limit:10m;
...
limit_req zone=general burst=15 nodelay;
limit_conn conn_limit 20;
```

Каждый IP-адрес ограничен 10 запросами в секунду в среднем, с разрешённым всплеском (`burst`) до 15 запросов подряд без задержки (`nodelay`), и не больше 20 одновременных открытых соединений. Это защита от перегрузки сервера одним агрессивным клиентом или простой DDoS-атакой малой силы - запросы сверх лимита получат ошибку, а не положат сервер.

Раздел `/dev/` намеренно настроен с более широким лимитом (`burst=30`, `limit_conn 50`) - потому что туда вообще не должны попадать чужие, только доверенные адреса (см. ниже), и можно позволить себе быть менее строгим.

### Кеширование ответов от приложения

```nginx
proxy_cache_path /var/cache/nginx ... keys_zone=app_cache:10m max_size=1g inactive=60m use_temp_path=off;
...
proxy_cache app_cache;
proxy_cache_valid 200 10m;
proxy_cache_valid 404 1m;
proxy_cache_bypass $is_args;
proxy_no_cache $is_args;
proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
proxy_cache_background_update on;
proxy_cache_lock on;
```

Это целый набор продуманных решений вокруг кеша:
- Успешные ответы (200) кешируются на 10 минут, ошибки 404 - всего на минуту (чтобы не показывать пользователю "не найдено" слишком долго, если файл скоро появится).
- Запросы с параметрами в адресе (`?...`) не кешируются вообще (`$is_args` непустой, если в URL есть `?`) - это значит динамические запросы вроде `index.php?jobs` всегда идут напрямую в приложение, не отдавая пользователю устаревший статус загрузки из кеша.
- `proxy_cache_use_stale` - если приложение вдруг недоступно или отвечает с ошибкой 500-504, nginx скорее отдаст пользователю старую закешированную версию страницы, чем покажет ошибку. Сайт продолжает работать (хоть и с немного устаревшими данными), даже если контейнер на секунду перезапускается.
- `proxy_cache_lock` - если несколько пользователей одновременно запросили одну и ту же ещё не закешированную страницу, в приложение уйдёт только один запрос, а остальные подождут готовый результат. Это защищает backend от "стада" одинаковых запросов в момент, когда кеш только что устарел.
- `proxy_cache_background_update` - когда кешированная запись устаревает, её обновление происходит в фоне, а текущему пользователю пока отдаётся старая версия - никто не ждёт обновления кеша вживую.
- Заголовок `X-Cache-Status` в ответе показывает, был ли ответ из кеша (`HIT`) или сходил до приложения (`MISS`) - удобно для диагностики на проде без необходимости лезть в логи.

### Раздельный /dev/ с защитой по IP

```nginx
location /dev/ {
    allow <YOUR_LOCAL_SUBNET>;
    allow <YOUR_TRUSTED_IP_1>;
    deny all;
    ...
    add_header X-Robots-Tag "noindex, nofollow" always;
}
```

Тестовое окружение проксируется на отдельный upstream (`dev`) и недоступно вообще никому, кроме перечисленных доверенных адресов - `deny all` в конце закрывает доступ всем остальным. Кеш для него явно выключен (`proxy_cache off`), а поисковым роботам прямо запрещено его индексировать (`X-Robots-Tag: noindex, nofollow`) - тестовая версия сайта никогда не попадёт в поисковую выдачу и не покажет посторонним незаконченные изменения.

### upstream с постоянными соединениями

```nginx
upstream app {
    server 127.0.0.1:<APP_PORT>;
    keepalive 32;
}
```

`keepalive 32` означает, что nginx держит до 32 уже открытых TCP-соединений с приложением и переиспользует их для новых запросов, вместо того чтобы каждый раз заново устанавливать соединение. Это особенно ценно при HTTP/1.1 с `proxy_http_version 1.1; proxy_set_header Connection "";` - именно эта пара строк превращает обычное проксирование в проксирование с переиспользованием соединений, что заметно снижает задержку под нагрузкой.

### Общие заголовки безопасности через include

Все важные заголовки (`Strict-Transport-Security`, `Content-Security-Policy`, `X-Content-Type-Options`, `Permissions-Policy` и так далее) лежат один раз в `snippets/security-headers.conf` и подключаются через `include` в каждом нужном месте - на странице 404, на странице 500, и в основном проксировании. Если нужно поменять политику безопасности, достаточно поправить один файл, а не искать и менять одни и те же строки в нескольких местах конфига.

### Страницы ошибок настроены индивидуально

Несмотря на общий набор заголовков безопасности, страницы 404 и 500 кешируются по-разному, и это осмысленно:

```nginx
location = /404.html {
    add_header Cache-Control "public, max-age=300" always;
}
location = /500.html {
    add_header Cache-Control "no-cache, no-store, must-revalidate" always;
    add_header Retry-After "60" always;
}
```

Страницу "не найдено" можно недолго кешировать (5 минут) - если файл за это время появится, лишний раз ничего страшного не случится, а нагрузка на сервер снизится. А страницу "внутренняя ошибка" кешировать нельзя вообще - если сервер уже один раз ответил ошибкой, эту же ошибку точно не нужно показывать всем следующим посетителям, пока проблема не решена. Заголовок `Retry-After: 60` - явная подсказка браузеру и поисковым роботам, что стоит повторить попытку через минуту, а не сразу и не никогда.

### Безопасные базовые настройки сервера

В `nginx.conf` отдельно стоит обратить внимание на:
- `server_tokens off` - nginx не сообщает свою точную версию в заголовках ответа и в страницах ошибок, что усложняет подбор эксплойтов под конкретную известную уязвимость версии.
- `client_max_body_size 50M` и небольшие размеры буферов заголовков (`client_header_buffer_size 1k`, `large_client_header_buffers 4 8k`) - сервер не выделяет память под запросы крупнее необходимого, что снижает эффект атак на память сервера через специально раздутые заголовки.
- `gzip` со списком конкретных типов содержимого - сжимаются только те ответы, которым это реально помогает (текст, CSS, JS, JSON, шрифты, SVG), а уже сжатые форматы (картинки JPEG/PNG, видео) не трогаются - сжимать их заново только тратит процессорное время без выигрыша в размере.

## Сравнение с внутренним nginx контейнера

Внутренний nginx контейнера (см. [Конфигурацию](05-config.md#nginx)) гораздо проще - он не занимается TLS, не знает про домен и не кеширует ответы приложения. Его задача узкая: отдать статику быстро и передать PHP-запросы в php-fpm. Вся "тяжёлая" инфраструктурная работа (шифрование, защита от ботов, кеш, лимиты) сделана один раз на внешнем слое - и благодаря этому сам контейнер с Качалкой можно спокойно запускать локально, без HTTPS и без домена, для разработки, а в продакшене просто поставить перед ним такой внешний nginx, ничего не меняя внутри образа.

## Примеры конфигурации

### /etc/nginx/nginx.conf

```nginx
user www-data;
worker_processes auto;
worker_rlimit_nofile 65535;
pid /run/nginx.pid;

events {
    worker_connections 4096;
    use epoll;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent" "$http_x_forwarded_for"';

    access_log /var/log/nginx/access.log main;
    error_log /var/log/nginx/error.log warn;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;
    types_hash_max_size 2048;
    server_tokens off;

    # Защита от атак через размер буферов
    client_max_body_size 50M;
    client_header_buffer_size 1k;
    large_client_header_buffers 4 8k;

    # Таймауты для защиты от slowloris
    client_body_timeout 10s;
    client_header_timeout 10s;
    send_timeout 10s;

    # gzip сжатие
    gzip on;
    gzip_vary on;
    gzip_min_length 1000;
    gzip_types text/plain text/css text/xml text/javascript 
               application/x-javascript application/xml+rss 
               application/json application/javascript application/font-woff 
               image/svg+xml;

    # Определение "не браузера"
    map $http_user_agent $block_non_browser {
        default 1;
        "~*\b(zgrab|gobuster|nikto|sqlmap|nmap|masscan|shodan|metasploit)\b" 1;
        "~*(go-http-client|python-?requests?|curl|wget|java|php|headless|phantomjs)\b" 1;
        "~*(googlebot|bingbot|yandexbot|slurp|duckduckbot)\b" 0;
        "~*^Mozilla/5\.0" 0;
    }

    # Кеш для приложения
    proxy_cache_path /var/cache/nginx/app 
                     keys_zone=app_cache:10m 
                     max_size=1g 
                     inactive=60m 
                     use_temp_path=off 
                     levels=1:2;

    # Зоны для ограничения запросов
    limit_req_zone $binary_remote_addr zone=general:10m rate=10r/s;
    limit_req_zone $binary_remote_addr zone=dev:10m rate=30r/s;
    limit_conn_zone $binary_remote_addr zone=conn_limit:10m;
    limit_conn_zone $binary_remote_addr zone=dev_conn_limit:10m;

    # upstream для основного приложения
    upstream app {
        server 127.0.0.1:8000;
        keepalive 32;
    }

    # upstream для dev среды (если используется)
    upstream dev {
        server 127.0.0.1:8001;
        keepalive 16;
    }

    # Редирект на HTTPS
    server {
        listen 80 default_server;
        listen [::]:80 default_server;

        if ($block_non_browser) { return 444; }
        return 301 https://$host$request_uri;

        access_log /var/log/nginx/access.log;
        access_log /var/log/nginx/blocked.log combined if=$block_non_browser;
    }

    include /etc/nginx/sites-enabled/*;
}
```

### /etc/nginx/sites-enabled/YT_prod

```nginx
server {
    listen 443 quic reuseport;
    listen 443 ssl;
    listen [::]:443 ssl;

    server_name example.com www.example.com;

    # TLS сертификаты (например, от Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    # Современный и безопасный TLS
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_session_cache shared:SSL:50m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;
    ssl_stapling on;
    ssl_stapling_verify on;

    # HTTP/3
    http2 on;
    http3 on;
    add_header Alt-Svc 'h3=":443"; ma=86400' always;

    # Логи
    access_log /var/log/nginx/yt_access.log;
    error_log /var/log/nginx/yt_error.log warn;

    # Основной контент
    location / {
        if ($block_non_browser) { return 444; }

        limit_req zone=general burst=15 nodelay;
        limit_conn conn_limit 20;

        # Проксирование на контейнер
        proxy_pass http://app;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;

        # Таймауты
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;

        # Кеширование
        proxy_cache app_cache;
        proxy_cache_valid 200 10m;
        proxy_cache_valid 404 1m;
        proxy_cache_bypass $is_args;
        proxy_no_cache $is_args;
        proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
        proxy_cache_background_update on;
        proxy_cache_lock on;
        add_header X-Cache-Status $upstream_cache_status;

        include /etc/nginx/snippets/security-headers.conf;
    }

    # Статика (если нужна)
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        proxy_pass http://app;
        proxy_cache app_cache;
        proxy_cache_valid 200 365d;
        expires 1y;
        add_header Cache-Control "public, immutable";
        include /etc/nginx/snippets/security-headers.conf;
    }

    # Dev раздел (только для доверенных IP)
    location /dev/ {
        allow 192.168.0.0/16;
        allow 10.0.0.0/8;
        deny all;

        limit_req zone=dev burst=30 nodelay;
        limit_conn dev_conn_limit 50;

        proxy_pass http://dev;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_cache off;
        add_header X-Robots-Tag "noindex, nofollow" always;
        include /etc/nginx/snippets/security-headers.conf;
    }

    # Страница 404
    location = /404.html {
        add_header Cache-Control "public, max-age=300" always;
        include /etc/nginx/snippets/security-headers.conf;
    }

    # Страница 500
    location = /500.html {
        add_header Cache-Control "no-cache, no-store, must-revalidate" always;
        add_header Retry-After "60" always;
        include /etc/nginx/snippets/security-headers.conf;
    }

    error_page 404 /404.html;
    error_page 500 502 503 504 /500.html;
}
```

### /etc/nginx/snippets/security-headers.conf

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self';" always;
```
