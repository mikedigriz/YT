#!/bin/bash
# add cronjob for autoremove files after 120 min
# cat /etc/crontab
# */5 * * * * <username> /usr/bin/docker exec -it -d <container_name> bash -c "bash /etc/Scripts/2hourcleanup.sh" > /dev/null
time=$(date '+%d-%m-%Y_%H:%M:%S')
find /var/www/YT/tmp/ -type f -cmin +120 -delete
find /var/www/YT/download/ -type f -cmin +120 -delete
