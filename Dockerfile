FROM debian:trixie-slim
ENV VIRTUAL_ENV=/.yt_env
ENV TZ=Europe/Moscow

RUN echo 'APT::Install-Recommends "false";' > /etc/apt/apt.conf.d/00recommends && \
    apt update && apt install -y \
    ffmpeg nginx \
    python3.13 python3-pip python3-venv \
    php8.4 php8.4-fpm \
    vim mc htop \
    && rm -rf /var/lib/apt/lists/*

RUN python3 -m venv $VIRTUAL_ENV
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
RUN pip install --no-cache-dir yt-dlp

RUN sed -i 's/^user www-data;/#user www-data;/' /etc/nginx/nginx.conf
COPY ./app/etc/nginx/sites-available/default /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

COPY ./app .

RUN mkdir -p /var/www/YT /var/www/.config/yt-dlp/plugins/log_plugin/yt_dlp_plugins/postprocessor \
    /var/run/php /var/log/nginx /var/lib/nginx/body /var/cache/nginx && \
    touch /var/log/yt_dlp.log /var/www/YT/yt_dlp_version /var/log/php8.4-fpm.log /run/nginx.pid && \
    chown -R www-data:www-data /var/www /var/log/yt_dlp.log /var/log/php8.4-fpm.log /run/nginx.pid \
    /var/run/php /var/log/nginx /var/lib/nginx /var/cache/nginx && \
    chmod 755 /var/www/YT && \
    chmod 644 /var/log/yt_dlp.log /var/www/YT/yt_dlp_version /var/log/php8.4-fpm.log && \
    setcap 'cap_net_bind_service=+ep' /usr/sbin/nginx

USER www-data

CMD ["bash", "start.sh"]
EXPOSE 80
