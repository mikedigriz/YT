FROM alpine:3.20
ENV VIRTUAL_ENV=/.yt_env
RUN apk add \
	bash \
	ffmpeg \
	vim \
	nginx \
	python3 \
	py3-pip \
    curl \
    php83 \
    php83-ctype \
    php83-curl \
    php83-dom \
    php83-fileinfo \
    php83-fpm \
    php83-gd \
    php83-intl \
    php83-mbstring \
    php83-opcache \
    php83-openssl \
    php83-phar \
    php83-session \
    php83-tokenizer \
    php83-xml \
    php83-xmlreader \
    php83-xmlwriter \
	&& rm -rf /etc/apk/cache/*
COPY config/www.conf /etc/php83/php-fpm.d/www.conf
RUN adduser www-data -S -H --disabled-password -g "www-data" -h /dev/null -u 82 -G www-data
RUN python3 -m venv $VIRTUAL_ENV 
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
RUN pip install yt-dlp 
COPY ./app .
RUN chown -R root:www-data /var/www/YT && chmod -R 775 /var/www/YT/
CMD ["bash", "start.sh"]
EXPOSE 80
