FROM ubuntu:22.04 
#Fix: tzdata hangs during Docker image build
ENV TZ=Europe/Moscow
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN apt-get update && apt-get install -y \
	 apt-utils  \
	 ffmpeg \
	 mc \
	 nginx \
	 python3.10 \
	 python3-pip \ 
	 php8.1 \ 
	 php8.1-fpm \
&& pip install yt-dlp \
&& rm -rf /var/lib/apt/lists/*
COPY ./app .
RUN chown -R root:www-data /var/www/YT && chmod -R 775 /var/www/YT/
ENTRYPOINT service php8.1-fpm start && service nginx start && bash
EXPOSE 80
