#!/bin/bash
yt-dlp --version > /var/www/YT/yt_dlp_version
service php8.4-fpm start && service nginx start && bash