FROM debian:trixie-slim

ARG ENVIRONMENT=prod
ENV VIRTUAL_ENV=/.yt_env
ENV TZ=Europe/Moscow

# build-essential/pkg-config/lib*-dev - для сборки canvas из исходников (npm-зависимость
# bgutil), если prebuild-install не смог скачать готовый бинарник
# vim/mc/htop - только для dev-контейнера
RUN echo 'APT::Install-Recommends "false";' > /etc/apt/apt.conf.d/00recommends && \
    apt update && apt install -y \
    ffmpeg nginx patch tzdata aria2 \
    python3.13 python3-pip python3-venv \
    php8.4 php8.4-fpm php8.4-opcache \
    ca-certificates curl gnupg git \
    build-essential pkg-config libcairo2-dev libpango1.0-dev libjpeg-dev libgif-dev librsvg2-dev \
    $( [ "$ENVIRONMENT" = "dev" ] && echo "vim mc htop" ) \
    && rm -rf /var/lib/apt/lists/*

# Debian trixie's apt-репо тащит nodejs 20.x, а yt-dlp для решения
# JS-загадок YouTube (EJS) требует Node >= 22 - ставим из NodeSource
RUN mkdir -p /etc/apt/keyrings && \
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg && \
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list && \
    apt update && apt install -y nodejs && \
    rm -rf /var/lib/apt/lists/*

# vot-cli - CLI-обёртка над Яндекс-VOT (закадровый перевод видео). Тянет
# готовую переведённую аудиодорожку по URL ролика; ffmpeg вклеивает её в видео.
RUN npm install -g vot-cli && npm cache clean --force

RUN python3 -m venv $VIRTUAL_ENV
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
# [default] тащит yt-dlp-ejs - сами скрипты-решатели JS-загадок,
# без них Node 22 сам по себе ничего не разблокирует
RUN pip install --no-cache-dir "yt-dlp[default]"

# Без WORKDIR содержимое кладётся прямо в корень контейнера
# (app/var/www/YT -> /var/www/YT, app/etc/... -> /etc/... и т.д.)
COPY ./app .

# Применить патч для более либеральных правил filename sanitizing
RUN cd /.yt_env/lib/python3.13/site-packages && patch -p0 --fuzz=0 < /patches/replace_insane.patch

# --- YouTube PO-token провайдер (bgutil) ---
# Обход "Sign in to confirm you're not a bot" / HTTP 429. Две части:
# 1) yt-dlp-плагин (pip) - yt-dlp сам находит его в site-packages (namespace
#    yt_dlp_plugins), запрашивает PO-токен у сервера ниже на каждой youtube-загрузке.
# 2) сервер-генератор токенов (Node/TypeScript) - собираем здесь, фоном
#    запускается в start.sh, слушает 127.0.0.1:4416. Токен решается локально
#    (BotGuard в JS-песочнице, без браузера), прокси ему не нужен.
# npm prune --omit=dev убирает tsc и прочие devDependencies после сборки - в том же
# RUN, что install/tsc, иначе слой уже закоммитится с ними
RUN npm config set fetch-timeout 120000 && \
    npm config set fetch-retry-mintimeout 20000 && \
    npm config set fetch-retry-maxtimeout 120000 && \
    pip install --no-cache-dir bgutil-ytdlp-pot-provider && \
    git clone --depth 1 https://github.com/Brainicism/bgutil-ytdlp-pot-provider.git /opt/bgutil && \
    cd /opt/bgutil/server && \
    npm install && npx tsc && \
    npm prune --omit=dev && \
    npm cache clean --force

RUN sed -i 's/^user www-data;/#user www-data;/' /etc/nginx/nginx.conf

# Разрешаем php-fpm пробрасывать переменные окружения контейнера (например,
# SOCKS5_URL из .env через docker-compose env_file), чтобы их видел getenv() в config.php.
RUN echo "clear_env = no" >> /etc/php/8.4/fpm/pool.d/www.conf

# Дефолтных 5 воркеров мало: страницу опрашивает каждая открытая вкладка раз в
# полторы секунды, и когда запросы притормаживают из-за занятого диска, пул
# выбирается целиком - ложится весь сайт, а не отдельный опрос. Дублирующиеся
# ключи в конце файла перебивают исходные (тот же приём, что с clear_env).
# request_terminate_timeout - страховка от намертво залипшего воркера; nginx и
# так сдаётся через 30с (fastcgi_read_timeout), так что живой запрос под него
# не попадает.
RUN printf 'pm = dynamic\npm.max_children = 12\npm.start_servers = 3\npm.min_spare_servers = 2\npm.max_spare_servers = 6\nrequest_terminate_timeout = 60\n' >> /etc/php/8.4/fpm/pool.d/www.conf

# дефолт PHP (120с) короче 120-минутного retention-окна файлов в tmp/logPath -
# без повышения TTL realpath() в destructive-операциях бил бы диск заново чаще, чем нужно
RUN echo "realpath_cache_ttl=300" >> /etc/php/8.4/fpm/conf.d/99-realpath-cache.ini

# validate_timestamps=1 не отключаем совсем - в dev код монтируется живым томом
# и правится без ребилда, live-reload должен видеть изменения
RUN printf 'opcache.enable=1\nopcache.memory_consumption=64\nopcache.max_accelerated_files=1000\nopcache.validate_timestamps=1\nopcache.revalidate_freq=2\n' >> /etc/php/8.4/fpm/conf.d/99-opcache.ini

RUN rm -f /etc/nginx/sites-enabled/default && \
    if [ "$ENVIRONMENT" = "dev" ]; then \
        ln -s /etc/nginx/sites-available/default_dev /etc/nginx/sites-enabled/default; \
    else \
        ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default; \
    fi

# Хэш содержимого CSS/JS - part.header.php подставляет его в статику как ?v=<хэш>,
# чтобы держать nginx-кэш "immutable": URL сам меняется при пересборке образа
RUN sha256sum /var/www/YT/js/youtubedlwebui.min.js /var/www/YT/css/*.min.css | sha256sum | cut -c1-12 > /var/www/YT/static_version

# Исполняемый бит скриптов не гарантирован на хосте (Windows-чекаут не хранит unix-права).
# start.sh/logger.sh/mux_translated.sh всегда запускаются через явный `bash script.sh`
# (см. CMD ниже, RUN bash /logger.sh выше, Downloader::executeDownload()) - им +x не нужен.
# Только Scripts/*.sh запускаются как путь напрямую (docker exec ... /etc/Scripts/X.sh
# из host-cron/вручную) - им бит нужен реально.
RUN chmod +x /etc/Scripts/update-ytdlp.sh /etc/Scripts/2hourcleanup.sh /etc/Scripts/cleanup.sh \
    /etc/Scripts/ensure_mp4.sh

# Плагин-логгер yt-dlp и его config статичны - генерируем их при сборке от root
# в системный каталог /etc/yt-dlp (read-only для www-data). yt-dlp находит их там
# автоматически как системный конфиг, без переменных окружения.
RUN bash /logger.sh

# download/ и tmp/ создаём здесь заранее (до chown) - в prod это именованные volume,
# и если путь в образе не существует, Docker на первом монтировании создаёт точку
# монтирования сам от root, www-data туда писать не сможет
# YT_data лежит ВЫШЕ корня сайта (/var/www/YT) намеренно: диалоги обратной связи
# нельзя отдать по HTTP в принципе, а не только правилом deny в nginx.
RUN mkdir -p /var/www/YT /var/www/YT/download /var/www/YT/tmp \
    /var/www/YT_data/feedback/d /var/www/YT_data/feedback/rl \
    /var/run/php /var/log/nginx /var/lib/nginx/body /var/cache/nginx && \
    touch /var/www/YT/yt_dlp_version /var/log/php8.4-fpm.log /run/nginx.pid /var/log/feedback.log && \
    chown -R www-data:www-data /var/www /var/log/yt_dlp.log /var/log/feedback.log /var/log/php8.4-fpm.log /run/nginx.pid \
    /var/run/php /var/log/nginx /var/lib/nginx /var/cache/nginx && \
    chmod 750 /var/www/YT /var/www/YT/download /var/www/YT/tmp && \
    chmod 700 /var/www/YT_data /var/www/YT_data/feedback \
    /var/www/YT_data/feedback/d /var/www/YT_data/feedback/rl && \
    chmod 644 /var/log/yt_dlp.log /var/log/feedback.log /var/www/YT/yt_dlp_version /var/log/php8.4-fpm.log && \
    setcap 'cap_net_bind_service=+ep' /usr/sbin/nginx

USER www-data

CMD ["bash", "start.sh"]
EXPOSE 80

# Бьём в лёгкий health.php (только nginx+php-fpm), а не в ?jobs -
# тот на каждый вызов сканирует tmp, крутит очередь и дёргает прокси
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health.php >/dev/null || exit 1
