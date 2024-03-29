FROM debian:bookworm-20230703-slim
ENV VIRTUAL_ENV=/.yt_env
RUN echo 'apt::install-recommends "false";' > /etc/apt/apt.conf.d/00recomends
RUN apt-get update && apt-get install -y \
	apt-utils  \
	ffmpeg \
	mc \
	vim \
	nginx \
	python3.11 \
	python3-pip \ 
	python3.11-venv \
	php8.2 \ 
	php8.2-fpm \
	&& rm -rf /var/lib/apt/lists/*
RUN python3 -m venv $VIRTUAL_ENV 
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
RUN pip install yt-dlp 
COPY ./app .
RUN chown -R root:www-data /var/www/YT && chmod -R 775 /var/www/YT/
CMD ["bash", "start.sh"]
EXPOSE 80
