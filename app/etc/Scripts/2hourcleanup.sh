#!/bin/bash
find /var/www/YT/tmp/ -type f -mmin +120 -delete

# пропускаем файлы с .keep-маркером (тот же, что уважает FileHandler::deleteAll())
find /var/www/YT/download/ -type f -mmin +120 ! -name '*.keep' -print | while IFS= read -r f; do
    [ -e "${f}.keep" ] && continue
    rm -f -- "$f"
done
