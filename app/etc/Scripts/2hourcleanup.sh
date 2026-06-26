#!/bin/bash
# для добавления в cron
# но не было времени протестировать это в докере
find /var/www/YT/tmp/ -type f -mmin +120 -delete
find /var/www/YT/download/ -type f -mmin +120 -delete
