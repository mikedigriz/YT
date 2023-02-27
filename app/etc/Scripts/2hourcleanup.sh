#!/bin/bash
# for adding to cron
# but i have no time for test it in docker
time=$(date '+%d-%m-%Y_%H:%M:%S')
find /var/www/YT/tmp/ -type f -cmin +120 -delete
find /var/www/YT/download/ -type f -cmin +120 -delete
