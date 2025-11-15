FROM debian:trixie-slim
ENV VIRTUAL_ENV=/.yt_env
RUN echo 'APT::Install-Recommends "false";' > /etc/apt/apt.conf.d/00recommends
RUN apt update && apt install -y \
        apt-utils \
        ffmpeg \
        mc \
        vim \
        nginx \
        python3.13 \        
        python3-pip \
        python3-venv \
        php8.4 \             
        php8.4-fpm \
        && rm -rf /var/lib/apt/lists/*
RUN python3 -m venv $VIRTUAL_ENV
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
RUN pip install --no-cache-dir yt-dlp
COPY ./app .
RUN chown -R root:www-data /var/www/YT && chmod -R 775 /var/www/YT/
CMD ["bash", "start.sh"]
EXPOSE 80
