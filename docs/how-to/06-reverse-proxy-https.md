# Поставить сайт за HTTPS

Внутри контейнера уже есть свой nginx, но он не занимается доменом, TLS-сертификатами и защитой от ботов - это его сознательно не касается, чтобы сам контейнер можно было гонять локально без HTTPS. Для продакшена перед контейнером ставится ещё один, внешний nginx на хосте.

```
Интернет → внешний nginx (хост: TLS, HTTP/3, кеш, защита от ботов)
              → контейнер Качалки (внутренний nginx → php-fpm → index.php)
```

## Шаг 1. Три файла конфигурации

- `/etc/nginx/nginx.conf` - общие настройки: лимиты, gzip, зоны ограничения запросов, upstream.
- `/etc/nginx/sites-enabled/kachalka` - конкретный сайт: TLS, кеш, проксирование в контейнер.
- `/etc/nginx/snippets/security-headers.conf` - общий набор заголовков безопасности, подключается через `include`.

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

    client_max_body_size 50M;
    client_header_buffer_size 1k;
    large_client_header_buffers 4 8k;

    client_body_timeout 10s;
    client_header_timeout 10s;
    send_timeout 10s;

    gzip on;
    gzip_vary on;
    gzip_min_length 1000;
    gzip_types text/plain text/css text/xml text/javascript
               application/x-javascript application/xml+rss
               application/json application/javascript application/font-woff
               image/svg+xml;

    map $http_user_agent $block_non_browser {
        default 1;
        "~*\b(zgrab|gobuster|nikto|sqlmap|nmap|masscan|shodan|metasploit)\b" 1;
        "~*(go-http-client|python-?requests?|curl|wget|java|php|headless|phantomjs)\b" 1;
        "~*(googlebot|bingbot|yandexbot|slurp|duckduckbot)\b" 0;
        "~*^Mozilla/5\.0" 0;
    }

    proxy_cache_path /var/cache/nginx/app
                     keys_zone=app_cache:10m
                     max_size=1g
                     inactive=60m
                     use_temp_path=off
                     levels=1:2;

    limit_req_zone $binary_remote_addr zone=general:10m rate=10r/s;
    limit_conn_zone $binary_remote_addr zone=conn_limit:10m;

    upstream app {
        server 127.0.0.1:8000;
        keepalive 32;
    }

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

### /etc/nginx/sites-enabled/kachalka

```nginx
server {
    listen 443 quic reuseport;
    listen 443 ssl;
    listen [::]:443 ssl;

    server_name example.com www.example.com;

    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_session_cache shared:SSL:50m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;
    ssl_stapling on;
    ssl_stapling_verify on;

    http2 on;
    http3 on;
    add_header Alt-Svc 'h3=":443"; ma=86400' always;

    access_log /var/log/nginx/yt_access.log;
    error_log /var/log/nginx/yt_error.log warn;

    location / {
        if ($block_non_browser) { return 444; }

        limit_req zone=general burst=15 nodelay;
        limit_conn conn_limit 20;

        proxy_pass http://app;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;

        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;

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

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        proxy_pass http://app;
        proxy_cache app_cache;
        proxy_cache_valid 200 365d;
        expires 1y;
        add_header Cache-Control "public, immutable";
        include /etc/nginx/snippets/security-headers.conf;
    }

    location = /404.html {
        add_header Cache-Control "public, max-age=300" always;
        include /etc/nginx/snippets/security-headers.conf;
    }

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

**Важно про CSP.** Приложение уже отдаёт свой `Content-Security-Policy` с одноразовым nonce для инлайн-скриптов. Если внешний nginx добавит второй CSP без nonce, браузер применит пересечение обеих политик - и интерфейс сломается. Поэтому строку `Content-Security-Policy` из сниппета убери, приложение выставит её само. Остальные заголовки дублировать безопасно.

## Шаг 2. Подставь свои значения

Замени `example.com` и пути до сертификатов на свои, затем перезагрузи nginx:

```bash
nginx -t && systemctl reload nginx
```

## Почему конфиг устроен именно так

- **`return 444` для не-браузеров** - сканерам и ботам не достаётся даже текста ошибки. `map` с user-agent'ами вычисляется один раз на запрос, а не в каждом `location`.
- **HTTP/3 (QUIC) вместе с HTTP/2** - браузер сам выберет протокол поновее и побыстрее; если не умеет - ничего не ломается.
- **`ssl_session_tickets off`** - тикеты сложнее безопасно ротировать, чем общий кеш сессий на сервере.
- **Таймауты `10s`** - защита от slowloris-атак, когда клиент специально тянет с отправкой данных, чтобы занять соединение.
- **`limit_req`/`limit_conn`** - один агрессивный клиент не положит сервер частыми запросами или сотней открытых соединений.
- **`proxy_cache_use_stale` + `proxy_cache_lock`** - при недоступности приложения nginx отдаст старую закешированную версию вместо ошибки, а за свежей страницей сходит только один запрос, даже если её ждёт толпа.
