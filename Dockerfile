# Готовый сервер PO-токенов: своя сборка компилирует canvas из исходников.
# Версия обязана совпадать с pip-плагином bgutil-ytdlp-pot-provider ниже.
FROM brainicism/bgutil-ytdlp-pot-provider:1.3.2-node AS potprovider

# Node для yt-dlp (EJS), vot-cli и сервера PO-токенов. Мажор должен совпадать
# с ABI, под который собран canvas в образе potprovider.
FROM node:26-trixie-slim AS noderuntime

# vot-cli - закадровый перевод видео (Яндекс-VOT). Мажор прибит: ключи CLI
# между мажорами меняются, а Downloader::executeDownload() на них завязан.
# Отдельная стадия собирается параллельно с apt и не зависит от его слоя.
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm_config_proxy="$all_proxy" npm_config_https_proxy="$all_proxy" \
    env -u http_proxy -u https_proxy -u all_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY \
    npm install -g --prefer-offline --no-audit --no-fund vot-cli@2

FROM php:8.5.12-fpm-trixie

WORKDIR /

# PID 1 тут nginx: SIGQUIT базового образа держал бы docker stop до таймаута.
STOPSIGNAL SIGTERM

ARG ENVIRONMENT=prod

# Зеркало Debian, переопределяется через APT_MIRROR в .env.
ARG APT_MIRROR=deb.debian.org

# Прокси сборки приходит предопределёнными build-args (build.sh), они не
# оседают в слоях. npm и pip падают на socks5h в http_proxy, поэтому на их
# шагах переменные снимаются через "env -u"; npm получает SOCKS через
# npm_config_proxy, pip идёт напрямую.
ENV VIRTUAL_ENV=/.yt_env
ENV TZ=Europe/Moscow

# apt ходит к Debian напрямую, мимо прокси сборки. Скачанные пакеты остаются
# в кэш-маунте и в слои не попадают.
RUN set -e; \
    if [ "$APT_MIRROR" != "deb.debian.org" ]; then \
        sed -i "s|deb.debian.org|$APT_MIRROR|g" /etc/apt/sources.list.d/debian.sources; \
    fi; \
    { echo "Acquire::http::Proxy::$APT_MIRROR \"DIRECT\";"; \
      echo "Acquire::https::Proxy::$APT_MIRROR \"DIRECT\";"; \
      echo 'Acquire::http::Proxy::deb.debian.org "DIRECT";'; \
      echo 'Acquire::https::Proxy::deb.debian.org "DIRECT";'; \
      echo 'Acquire::http::Proxy::security.debian.org "DIRECT";'; \
      echo 'Acquire::https::Proxy::security.debian.org "DIRECT";'; \
    } > /etc/apt/apt.conf.d/99direct-debian; \
    rm -f /etc/apt/apt.conf.d/docker-clean; \
    echo 'Binary::apt::APT::Keep-Downloaded-Packages "true";' > /etc/apt/apt.conf.d/99keep-cache

# lib* - рантайм для canvas из стадии potprovider. vim/mc/htop - только dev.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    echo 'APT::Install-Recommends "false";' > /etc/apt/apt.conf.d/00recommends && \
    apt update && apt install -y \
    ffmpeg nginx patch tzdata aria2 \
    python3.13 python3-pip python3-venv \
    ca-certificates curl \
    libcairo2 libpango-1.0-0 libjpeg62-turbo libgif7 librsvg2-2 \
    $( [ "$ENVIRONMENT" = "dev" ] && echo "vim mc htop" )

# Только node, npm и vot-cli: /usr/local целиком перетёр бы PHP базового образа.
COPY --from=noderuntime /usr/local/bin/node /usr/local/bin/node
COPY --from=noderuntime /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=noderuntime /usr/local/include/node /usr/local/include/node
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm && \
    ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx && \
    ln -s ../lib/node_modules/vot-cli/dist/index.js /usr/local/bin/vot-cli

RUN python3 -m venv $VIRTUAL_ENV
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
# [default] тянет yt-dlp-ejs - решатели JS-загадок YouTube
RUN env -u http_proxy -u https_proxy -u all_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY \
    pip install --no-cache-dir "yt-dlp[default]"

# PO-token провайдер (bgutil): обход бот-чека YouTube и HTTP 429.
# Сервер запускается в start.sh на 127.0.0.1:4416, pip-плагин ходит к нему.
COPY --from=potprovider /app /opt/bgutil/server

RUN env -u http_proxy -u https_proxy -u all_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY \
    pip install --no-cache-dir "bgutil-ytdlp-pot-provider==1.3.2"

# app/var/www/YT -> /var/www/YT, app/etc/... -> /etc/...
COPY ./app .

# Более либеральная санитизация имён файлов
RUN cd /.yt_env/lib/python3.13/site-packages && patch -p0 --fuzz=0 < /patches/replace_insane.patch

RUN sed -i 's/^user www-data;/#user www-data;/' /etc/nginx/nginx.conf

# Пул php-fpm. Имя обязано идти по алфавиту после zz-docker.conf базового образа.
# - error_log в файл: /proc/self/fd/2 не-root процессу недоступен;
# - clear_env = no: config.php читает переменные из .env;
# - 12 воркеров: при занятом диске дефолтные 5 выбираются целиком.
RUN printf '[global]\nerror_log = /var/log/php-fpm.log\n\n[www]\nlisten = /run/php/php-fpm.sock\naccess.log = /dev/null\nclear_env = no\npm = dynamic\npm.max_children = 12\npm.start_servers = 3\npm.min_spare_servers = 2\npm.max_spare_servers = 6\nrequest_terminate_timeout = 60\n' \
    > /usr/local/etc/php-fpm.d/zzz-app.conf

# Без php.ini display_errors=On
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN echo "realpath_cache_ttl=300" > "$PHP_INI_DIR/conf.d/99-realpath-cache.ini"

# Вкладка с CSRF-токеном может висеть открытой сутками, дефолтных 24 минут мало
RUN echo "session.gc_maxlifetime=604800" > "$PHP_INI_DIR/conf.d/99-session.ini"

# В dev код монтируется томом - проверяем mtime; в prod код меняется только с образом.
RUN printf 'opcache.enable=1\nopcache.memory_consumption=64\nopcache.max_accelerated_files=1000\nopcache.validate_timestamps=%s\nopcache.revalidate_freq=2\n' \
    "$( [ "$ENVIRONMENT" = "dev" ] && echo 1 || echo 0 )" > "$PHP_INI_DIR/conf.d/99-opcache.ini"

RUN rm -f /etc/nginx/sites-enabled/default && \
    if [ "$ENVIRONMENT" = "dev" ]; then \
        ln -s /etc/nginx/sites-available/default_dev /etc/nginx/sites-enabled/default; \
    else \
        ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default; \
    fi

# Версия статики (?v=<хэш>) для immutable-кэша, см. part.header.php
RUN sha256sum /var/www/YT/js/youtubedlwebui.min.js /var/www/YT/js/eggs.min.js /var/www/YT/css/*.min.css | sha256sum | cut -c1-12 > /var/www/YT/static_version

# Чекаут может потерять +x. Нужен только скриптам, которые вызываются по пути
# (docker exec из host-cron); остальные запускаются через bash.
RUN chmod +x /etc/Scripts/update-ytdlp.sh /etc/Scripts/2hourcleanup.sh /etc/Scripts/cleanup.sh \
    /etc/Scripts/ensure_mp4.sh

# Плагин-логгер yt-dlp в /etc/yt-dlp (read-only для www-data)
RUN bash /logger.sh

# download/ и tmp/ создаются до chown: иначе Docker создаст точку монтирования
# volume от root. YT_data лежит вне корня сайта, чтобы не отдаваться по HTTP.
RUN mkdir -p /var/www/YT /var/www/YT/download /var/www/YT/tmp \
    /var/www/YT_data/feedback/d /var/www/YT_data/feedback/rl \
    /var/run/php /var/log/nginx /var/lib/nginx/body /var/cache/nginx && \
    touch /var/www/YT/yt_dlp_version /var/log/php-fpm.log /run/nginx.pid /var/log/feedback.log && \
    chown -R www-data:www-data /var/www /var/log/yt_dlp.log /var/log/feedback.log /var/log/php-fpm.log /run/nginx.pid \
    /var/run/php /var/log/nginx /var/lib/nginx /var/cache/nginx && \
    chmod 750 /var/www/YT /var/www/YT/download /var/www/YT/tmp && \
    chmod 700 /var/www/YT_data /var/www/YT_data/feedback \
    /var/www/YT_data/feedback/d /var/www/YT_data/feedback/rl && \
    chmod 644 /var/log/yt_dlp.log /var/log/feedback.log /var/www/YT/yt_dlp_version /var/log/php-fpm.log && \
    setcap 'cap_net_bind_service=+ep' /usr/sbin/nginx

USER www-data

CMD ["bash", "start.sh"]
EXPOSE 80

# Лёгкий health.php вместо тяжёлого ?jobs
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health.php >/dev/null || exit 1
