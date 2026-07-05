FROM debian:trixie-slim
ENV VIRTUAL_ENV=/.yt_env
ENV TZ=Europe/Moscow

RUN echo 'APT::Install-Recommends "false";' > /etc/apt/apt.conf.d/00recommends && \
    apt update && apt install -y \
    ffmpeg nginx patch \
    python3.13 python3-pip python3-venv \
    php8.4 php8.4-fpm \
    ca-certificates curl gnupg git \
    vim mc htop \
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

# Применить патч для более либеральных правил filename sanitizing
COPY app/patches /patches
RUN cd /.yt_env/lib/python3.13/site-packages && patch -p0 --fuzz=0 < /patches/replace_insane.patch

# --- YouTube PO-token провайдер (bgutil) ---
# Обход "Sign in to confirm you're not a bot" / HTTP 429. Две части:
# 1) yt-dlp-плагин (pip) - yt-dlp сам находит его в site-packages (namespace
#    yt_dlp_plugins), запрашивает PO-токен у сервера ниже на каждой youtube-загрузке.
# 2) сервер-генератор токенов (Node/TypeScript) - собираем здесь, фоном
#    запускается в start.sh, слушает 127.0.0.1:4416. Токен решается локально
#    (BotGuard в JS-песочнице, без браузера), прокси ему не нужен.
RUN pip install --no-cache-dir bgutil-ytdlp-pot-provider && \
    git clone --depth 1 https://github.com/Brainicism/bgutil-ytdlp-pot-provider.git /opt/bgutil && \
    cd /opt/bgutil/server && \
    npm install && npx tsc && \
    npm cache clean --force

RUN sed -i 's/^user www-data;/#user www-data;/' /etc/nginx/nginx.conf

# Разрешаем php-fpm пробрасывать переменные окружения контейнера (например,
# SOCKS5_URL из .env через docker-compose env_file), чтобы их видел getenv() в config.php.
RUN echo "clear_env = no" >> /etc/php/8.4/fpm/pool.d/www.conf

COPY ./app/etc/nginx/sites-available/ /etc/nginx/sites-available/

RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

COPY ./app .

# Исполняемый бит скриптов не гарантирован на хосте (Windows-чекаут не хранит unix-права),
# фиксируем его явно при сборке образа
RUN chmod +x /start.sh /logger.sh /mux_translated.sh /etc/Scripts/update-ytdlp.sh /etc/Scripts/2hourcleanup.sh

# Плагин-логгер yt-dlp и его config статичны - генерируем их при сборке от root
# в системный каталог /etc/yt-dlp (read-only для www-data). yt-dlp находит их там
# автоматически как системный конфиг, без переменных окружения.
RUN bash /logger.sh

RUN mkdir -p /var/www/YT \
    /var/run/php /var/log/nginx /var/lib/nginx/body /var/cache/nginx && \
    touch /var/www/YT/yt_dlp_version /var/log/php8.4-fpm.log /run/nginx.pid && \
    chown -R www-data:www-data /var/www /var/log/yt_dlp.log /var/log/php8.4-fpm.log /run/nginx.pid \
    /var/run/php /var/log/nginx /var/lib/nginx /var/cache/nginx && \
    chmod 750 /var/www/YT && \
    chmod 644 /var/log/yt_dlp.log /var/www/YT/yt_dlp_version /var/log/php8.4-fpm.log && \
    setcap 'cap_net_bind_service=+ep' /usr/sbin/nginx

USER www-data

CMD ["bash", "start.sh"]
EXPOSE 80

# Бьём в лёгкий health.php (только nginx+php-fpm), а не в ?jobs -
# тот на каждый вызов сканирует tmp, крутит очередь и дёргает прокси
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health.php >/dev/null || exit 1
