FROM debian:bookworm-20230703-slim
#Fix: tzdata hangs during Docker image build
ENV TZ=Europe/Moscow
ENV VIRTUAL_ENV=/.yt_env
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
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
ENTRYPOINT service php8.2-fpm start && service nginx start && bash
EXPOSE 80