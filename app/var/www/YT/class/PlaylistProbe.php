<?php
if (!isset($GLOBALS['config'])) { die("No direct script access"); }

// Перечисление содержимого плейлиста для окна выбора роликов.
//
// Замер фоновый (fire-and-forget, как ProxyStatus): "--flat-playlist -J" по SOCKS5
// на большом списке идёт десятки секунд, а у nginx fastcgi_read_timeout 30s -
// синхронный exec гарантированно отрезало бы по таймауту, да ещё и держа воркер
// php-fpm всё это время. Ответ на запрос не блокируется никогда, фронт опрашивает
// результат отдельным GET.
//
// Файлы живут в logPath с префиксом plist_ - Downloader::scanLogPath() раскладывает
// по вёдрам только pid_/ytdl_, поэтому проба невидима для background_jobs(), ?jobs,
// finalize_job_log() и авторетреев: она не задача и слот max_dl занимать не должна.
// Возрастная чистка хост-крона (2hourcleanup.sh) подбирает эти файлы сама.
class PlaylistProbe
{
    // Потолок разбора. Канал бывает на тысячи роликов: без предела проба идёт
    // минутами, JSON раздувается на мегабайты, а окну выбора столько строк
    // всё равно не нужно.
    const MAX_ENTRIES = 300;

    // Поводок процесса. setsid не ставим намеренно - у пробы нет кнопки "Стоп",
    // и единственное, что обязано её прекратить, это внешний timeout.
    const PROBE_TIMEOUT = 45;

    // Сколько разобранный плейлист считается свежим.
    const CACHE_TTL = 600;

    // Частота на один IP.
    const PER_IP_WINDOW = 60;
    const PER_IP_MAX = 5;

    // Одновременно идущих проб на весь сервис.
    const MAX_CONCURRENT = 3;

    // Защита от гигантского ответа: разбирать столько мы всё равно не станем.
    const MAX_RAW_BYTES = 4194304;

    // Приметы того, что ролик в списке есть, а взять его нельзя.
    const UNAVAILABLE_MARKERS = [
        'private video'   => 'Приватный',
        'deleted video'   => 'Удалён',
        'unavailable'     => 'Недоступен',
        'video unavailable' => 'Недоступен',
        'members-only'    => 'Только для подписчиков',
        'geo restricted'  => 'Заблокирован в регионе',
    ];

    private static function dir(): string
    {
        return rtrim($GLOBALS['config']['logPath'], '/');
    }

    public static function keyFor(string $url): string
    {
        return sha1(trim($url));
    }

    // Все файлы одной пробы. Ключ уже прошёл проверку формата в read()/start().
    private static function paths(string $key): array
    {
        $base = self::dir() . '/plist_' . $key;
        return [
            'json' => $base . '.json',
            'raw'  => $base . '.raw',
            'err'  => $base . '.err',
            'done' => $base . '.done',
            'at'   => $base . '.at',
        ];
    }

    // Ключ приходит с фронта и подставляется в путь - пускаем только то, что сами и выдали.
    private static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[a-f0-9]{40}$/', $key);
    }

    // Запуск разбора. Возвращает готовый результат сразу, если он уже в кэше.
    public static function start(string $url, string $clientIp): array
    {
        $url = trim($url);
        if (!Downloader::validateUrl($url)) {
            return ['state' => 'error', 'error' => 'Ссылка не годится'];
        }

        $key = self::keyFor($url);
        $paths = self::paths($key);

        // Тёплый кэш - ни процесса, ни ограничений частоты.
        $cached = self::readCache($key);
        if ($cached !== null) {
            return $cached;
        }

        // Проба уже идёт (своя или из соседней вкладки) - присоединяемся к ней.
        if (self::probeRunning($key)) {
            return ['state' => 'pending', 'key' => $key];
        }

        $routing = Downloader::probeRouting($url);

        if (Downloader::hostCooldownLeft($routing['host']) > 0) {
            return ['state' => 'error', 'key' => $key,
                'error' => 'Сайт попросил притормозить, попробуй через несколько минут'];
        }

        // Мёртвый прокси - незачем жечь 45 секунд впустую, ответ известен заранее.
        if ($routing['useProxy'] && ProxyStatus::enabled()
            && ProxyStatus::overall_state(ProxyStatus::get_windows()) === 'death') {
            return ['state' => 'error', 'key' => $key, 'error' => 'Прокси не отвечает'];
        }

        if (self::rateLimited($clientIp)) {
            return ['state' => 'error', 'key' => $key,
                'error' => 'Слишком часто, подожди минуту'];
        }

        if (self::concurrentProbes() >= self::MAX_CONCURRENT) {
            return ['state' => 'error', 'key' => $key,
                'error' => 'Сейчас разбирается несколько плейлистов, попробуй чуть позже'];
        }

        // Лок на маркере: две вкладки с одной ссылкой не должны поднять два процесса.
        $lock = @fopen($paths['at'], 'c');
        if ($lock === false) {
            return ['state' => 'error', 'key' => $key, 'error' => 'Не удалось начать разбор'];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['state' => 'pending', 'key' => $key];
        }

        foreach (['raw', 'err', 'done', 'json'] as $stale) {
            @unlink($paths[$stale]);
        }
        @ftruncate($lock, 0);
        @fwrite($lock, (string) time());
        @fflush($lock);
        touch($paths['at']);

        self::launch($url, $key, $routing['useProxy'], $routing['cookiesFile']);

        flock($lock, LOCK_UN);
        fclose($lock);

        return ['state' => 'pending', 'key' => $key];
    }

    // Идёт ли прямо сейчас проба по этому ключу: маркер есть, результата ещё нет,
    // и поводок timeout не истёк.
    private static function probeRunning(string $key): bool
    {
        $paths = self::paths($key);
        if (!is_file($paths['at']) || is_file($paths['done'])) {
            return false;
        }
        clearstatcache(true, $paths['at']);
        return (time() - (int) @filemtime($paths['at'])) < (self::PROBE_TIMEOUT + 10);
    }

    private static function concurrentProbes(): int
    {
        $running = 0;
        foreach (glob(self::dir() . '/plist_*.at') ?: [] as $marker) {
            if ((time() - (int) @filemtime($marker)) >= (self::PROBE_TIMEOUT + 10)) {
                continue;
            }
            if (is_file(preg_replace('/\.at$/', '.done', $marker))) {
                continue;
            }
            $running++;
        }
        return $running;
    }

    // Счётчик по IP - список отметок времени в файле, старые отсекаются по окну.
    private static function rateLimited(string $clientIp): bool
    {
        $file = self::dir() . '/plist_ip_' . sha1($clientIp);
        $now = time();
        $stamps = [];
        if (is_file($file)) {
            foreach (explode(',', (string) @file_get_contents($file)) as $raw) {
                $t = (int) trim($raw);
                if ($t > 0 && ($now - $t) < self::PER_IP_WINDOW) {
                    $stamps[] = $t;
                }
            }
        }
        if (count($stamps) >= self::PER_IP_MAX) {
            return true;
        }
        $stamps[] = $now;
        @file_put_contents($file, implode(',', $stamps), LOCK_EX);
        return false;
    }

    // Фоновый yt-dlp. Ничего не скачивает, поэтому LogPluginPP не подключаем -
    // плагин насорил бы строками в /var/log/yt_dlp.log про несуществующие файлы.
    // "--plugin-dirs default" наоборот нужен: без него не грузится провайдер
    // PO-токенов и YouTube отвечает бот-чеком.
    private static function launch(string $url, string $key, bool $useProxy, string $cookiesFile): void
    {
        $paths = self::paths($key);
        $exe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';

        $cmd = $exe
            . ' --flat-playlist -J --no-warnings --ignore-no-formats-error'
            . ' --js-runtimes node --plugin-dirs default'
            . ' --playlist-end ' . (int) self::MAX_ENTRIES;

        if ($cookiesFile !== '') {
            $cmd .= ' --cookies ' . escapeshellarg($cookiesFile);
        }
        $cmd .= ' ' . escapeshellarg($url);

        if ($useProxy && !empty($GLOBALS['config']['socks5'])) {
            // no_proxy - тот же localhost-обход для сервера PO-токенов, что и у загрузок.
            $cmd = 'env all_proxy=' . escapeshellarg($GLOBALS['config']['socks5'])
                . ' no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost ' . $cmd;
        }

        // Тот же приоритет, что у задач: проба не должна отбирать диск и процессор
        // у веб-морды. Внешний timeout - единственный способ её прекратить.
        $script = 'timeout ' . (int) self::PROBE_TIMEOUT . ' nice -n 10 ionice -c 2 -n 7 '
            . $cmd
            . ' > ' . escapeshellarg($paths['raw'])
            . ' 2> ' . escapeshellarg($paths['err'])
            . '; printf %s $? > ' . escapeshellarg($paths['done']);

        exec('sh -c ' . escapeshellarg($script) . ' >/dev/null 2>&1 &');
    }

    // Состояние пробы для фронта.
    public static function read(string $key): array
    {
        if (!self::validKey($key)) {
            return ['state' => 'missing'];
        }

        $cached = self::readCache($key);
        if ($cached !== null) {
            return $cached;
        }

        $paths = self::paths($key);

        if (!is_file($paths['done'])) {
            if (self::probeRunning($key)) {
                return ['state' => 'pending', 'key' => $key];
            }
            if (!is_file($paths['at'])) {
                return ['state' => 'missing'];
            }
            // Маркер есть, результата нет, поводок истёк: процесс убит или
            // контейнер перезапустили посреди разбора.
            self::forget($key);
            return ['state' => 'error', 'key' => $key,
                'error' => 'Разбор прервался, попробуй ещё раз'];
        }

        $code = (int) trim((string) @file_get_contents($paths['done']));
        $raw = is_file($paths['raw']) ? (string) @file_get_contents($paths['raw']) : '';
        $result = self::parse($raw);

        if ($result === null) {
            // 124 - выход по timeout. Это не вердикт "плейлист плохой", а "не успели":
            // та же логика, что у probeReadable() с ffprobe. Кэш не пишем, чтобы
            // повтор действительно перепроверил.
            $message = $code === 124
                ? 'Не успел разобрать плейлист, попробуй ещё раз'
                : self::errorMessage($paths['err']);
            self::forget($key);
            return ['state' => 'error', 'key' => $key, 'error' => $message];
        }

        // Ненулевой код при непустом списке - разбор оборвался на середине, но то,
        // что уже перечислено, годится. Доказательство успеха важнее строки ошибки,
        // ровно как в jobProducedFile().
        $result['partial'] = ($code !== 0 && !empty($result['entries']));
        $result['state'] = 'ready';
        $result['key'] = $key;

        @file_put_contents($paths['json'], json_encode($result), LOCK_EX);
        foreach (['raw', 'err', 'done'] as $tmp) {
            @unlink($paths[$tmp]);
        }

        return $result;
    }

    private static function readCache(string $key): ?array
    {
        $paths = self::paths($key);
        if (!is_file($paths['json'])) {
            return null;
        }
        clearstatcache(true, $paths['json']);
        if ((time() - (int) @filemtime($paths['json'])) > self::CACHE_TTL) {
            @unlink($paths['json']);
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($paths['json']), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function forget(string $key): void
    {
        foreach (self::paths($key) as $path) {
            @unlink($path);
        }
    }

    // Текст ошибки для человека. Через sanitizeLog() обязательно: в stderr yt-dlp
    // лежит строка прокси с логином и паролем.
    private static function errorMessage(string $errFile): string
    {
        $err = is_file($errFile) ? (string) @file_get_contents($errFile) : '';
        if (trim($err) === '') {
            return 'Не удалось разобрать плейлист';
        }
        $friendly = Downloader::parseYtDlpError($err);
        if (!empty($friendly)) {
            return $friendly;
        }
        return Downloader::sanitizeLog(trim(substr($err, 0, 500)));
    }

    // Разбор JSON от yt-dlp. null - разобрать не вышло вовсе.
    private static function parse(string $raw): ?array
    {
        if (trim($raw) === '' || strlen($raw) > self::MAX_RAW_BYTES) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // yt-dlp мог напечатать предупреждение перед самим JSON.
            $start = strpos($raw, '{');
            if ($start === false) {
                return null;
            }
            $data = json_decode(substr($raw, $start), true);
            if (!is_array($data)) {
                return null;
            }
        }

        // Авторитетный ответ на вопрос "плейлист ли это" даёт сам yt-dlp, а не
        // разбор ссылки: экстракторов почти две тысячи, формы ссылок у них
        // несовместимы, и любая эвристика устаревает с очередным обновлением.
        $type = (string) ($data['_type'] ?? 'video');
        $result = [
            'contentType' => $type,
            'title'       => (string) ($data['title'] ?? ''),
            'count'       => 0,
            'truncated'   => false,
            'entries'     => [],
        ];

        if ($type !== 'playlist' && $type !== 'multi_video') {
            return $result;
        }

        $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parsed = self::parseEntry($entry);
            if ($parsed !== null) {
                $result['entries'][] = $parsed;
            }
        }

        $result['count'] = count($result['entries']);
        // playlist_count - сколько роликов в списке на самом деле, до нашего среза.
        $total = (int) ($data['playlist_count'] ?? 0);
        if ($total > $result['count']) {
            $result['count'] = $total;
            $result['truncated'] = true;
        } elseif ($result['count'] >= self::MAX_ENTRIES) {
            $result['truncated'] = true;
        }

        return $result;
    }

    // Одна запись списка. Ссылку собирает сервер: фронт URL-ы не конструирует,
    // чтобы правила сборки не разъехались между двумя реализациями.
    private static function parseEntry(array $entry): ?array
    {
        $id = (string) ($entry['id'] ?? '');
        $url = (string) ($entry['url'] ?? $entry['webpage_url'] ?? '');

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            // Плоский список у части экстракторов отдаёт голый id вместо ссылки.
            $url = ($id !== '' || $url !== '')
                ? 'https://www.youtube.com/watch?v=' . rawurlencode($url !== '' ? $url : $id)
                : '';
            if (strtolower((string) ($entry['ie_key'] ?? '')) !== 'youtube') {
                $url = '';
            }
        }

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $title = (string) ($entry['title'] ?? '');
        $available = true;
        $reason = null;

        $haystack = strtolower($title);
        foreach (self::UNAVAILABLE_MARKERS as $marker => $label) {
            if ($haystack !== '' && strpos($haystack, $marker) !== false) {
                $available = false;
                $reason = $label;
                break;
            }
        }
        // Живой эфир, который ещё не начался, качать нечего.
        if ($available && ($entry['live_status'] ?? '') === 'is_upcoming') {
            $available = false;
            $reason = 'Ещё не начался';
        }

        return [
            'id'        => $id !== '' ? $id : $url,
            'url'       => $url,
            // Сырой текст: экранирует фронт при вставке в DOM, как с именами файлов.
            'title'     => $title !== '' ? $title : $url,
            'duration'  => (int) ($entry['duration'] ?? 0),
            'available' => $available,
            'reason'    => $reason,
        ];
    }
}
