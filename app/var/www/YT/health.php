<?php
// Лёгкая проверка живости для Docker HEALTHCHECK: подтверждает связку
// nginx + php-fpm, не запуская фронт-контроллер (process_queue, скан tmp,
// замер прокси). Никакой логики приложения - только 200 OK.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
