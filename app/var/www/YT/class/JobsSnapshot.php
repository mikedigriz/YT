<?php
if (!isset($GLOBALS['config'])) { die("No direct script access"); }

// Состояние загрузок двигает один писатель - фоновый воркер (bin/worker.php).
// Раньше это делал каждый опрос ?jobs, то есть каждая открытая вкладка: без
// вкладки очередь стояла, а несколько вкладок разбирали одни и те же файлы
// наперегонки. Тут собраны оба шага, которые раньше жили в index.php: пролог
// (advance) и сборка ответа (build). index.php зовёт их сам, только если
// воркер не жив.
//
// Служебные файлы лежат в /dev/shm: это tmpfs, лишних записей на диск, занятый
// муксом, нет, а возрастная чистка tmp/ (2hourcleanup.sh) не снесёт лок живого
// воркера.
class JobsSnapshot
{
    // Сколько опрос ?jobs ждёт, пока воркер отработает его пинок. Дольше - отдаём
    // последний снапшот как есть: устаревший на секунду список лучше висящего.
    private const KICK_WAIT_SECONDS = 1.5;

    private static ?string $dir = null;

    // Вне контейнера (тесты, php -S) /dev/shm может не быть - тогда системный temp
    public static function dir(): string
    {
        if (self::$dir !== null) {
            return self::$dir;
        }
        $dir = getenv('KACHALKA_STATE_DIR') ?: '/dev/shm/kachalka';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir() . '/kachalka';
            @mkdir($dir, 0750, true);
        }
        return self::$dir = $dir;
    }

    public static function lockPath(): string
    {
        return self::dir() . '/worker.lock';
    }

    public static function snapshotPath(): string
    {
        return self::dir() . '/jobs.json';
    }

    private static function kickPath(): string
    {
        return self::dir() . '/kick';
    }

    private static function takenKickPath(): string
    {
        return self::dir() . '/kick.taken';
    }

    // Воркер держит flock, пока жив; ядро снимает его само, когда процесс умер.
    // Удалось взять лок - значит воркера нет.
    public static function workerAlive(): bool
    {
        $h = @fopen(self::lockPath(), 'ce');
        if ($h === false) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);
        return !$free;
    }

    private static $stateLock = null;

    // Лок на изменение pid_/job_/ytdl_. Без него POST kill шлёт SIGTERM, воркер
    // между сигналом и финализацией видит мёртвый процесс и закрывает задачу как
    // обычную: без _cancelled, с авторетреем на "упавшую" загрузку (сценарии 21, 49).
    // Повторный вызов в том же процессе ничего не делает, снимается выходом из
    // процесса или releaseState().
    public static function holdState(): void
    {
        if (self::$stateLock !== null) {
            return;
        }
        $h = @fopen(self::dir() . '/state.lock', 'ce');
        if ($h === false) {
            return; // без лока хуже, но не повод ронять запрос
        }
        flock($h, LOCK_EX);
        self::$stateLock = $h;
    }

    public static function releaseState(): void
    {
        if (self::$stateLock === null) {
            return;
        }
        flock(self::$stateLock, LOCK_UN);
        fclose(self::$stateLock);
        self::$stateLock = null;
    }

    // Просьба к воркеру пройти цикл немедленно, не дожидаясь своего тика.
    public static function kick(): void
    {
        @touch(self::kickPath());
    }

    // Воркер забирает пинок переименованием: новый пинок, пришедший во время
    // прохода, ляжет отдельным файлом и не потеряется.
    public static function takeKick(): ?string
    {
        $taken = self::takenKickPath();
        return @rename(self::kickPath(), $taken) ? $taken : null;
    }

    public static function kickPending(): bool
    {
        clearstatcache(true, self::kickPath());
        clearstatcache(true, self::takenKickPath());
        return file_exists(self::kickPath()) || file_exists(self::takenKickPath());
    }

    // Пинок и ожидание его отработки: ответ ?jobs отражает состояние на момент
    // запроса, как и раньше, когда пролог шёл прямо в нём.
    public static function kickAndWait(): void
    {
        self::kick();
        $deadline = microtime(true) + self::KICK_WAIT_SECONDS;
        while (self::kickPending() && microtime(true) < $deadline) {
            usleep(25000);
        }
    }

    // Пролог: прибрать мёртвые задачи, продвинуть очередь, погасить безнадёжные,
    // запустить отложенные ретраи. Возвращает свежий скан и остаток очереди.
    public static function advance(): array
    {
        $config = $GLOBALS['config'];

        // Фоновый замер прокси (сам троттлится, лишнего не дёргает)
        ProxyStatus::maybe_check();

        // Один скан tmp/ на проход: раньше каждый метод сканировал logPath сам
        $fileList = Downloader::scanLogPath();

        // Уборка ДО очереди. get_current_background_jobs() замечает мёртвый процесс,
        // финализирует лог и ставит отметки - кулдаун хоста после 429 и метку
        // авторетрея. Если сначала разобрать очередь, слот освобождается раньше
        // отметок, и следующая задача ТОГО ЖЕ хоста стартует до кулдауна (сценарий 36).
        Downloader::get_current_background_jobs($fileList);

        $queued = [];
        if (!$config['disableQueue']) {
            $downloader = new Downloader([]);
            $queued = $downloader->process_queue($fileList);
            // pid_ поднятой из очереди задачи создан ПОСЛЕ скана
            if ($downloader->getStartedUrls()) {
                $fileList = Downloader::scanLogPath();
            }
        }

        // Задачи, залипшие на закрытом контенте (18+, приватное, бот-чек): yt-dlp
        // переизвлекает такую ссылку по кругу и сам не завершится, а значит не
        // сработает и авторетрей с куками.
        Downloader::abortHopelessWaiters($fileList);

        // Ретраи, чья задержка (RETRY_SCHEDULE_DELAY) истекла. Та же причина
        // пересканировать, что у очереди выше: новый pid_ создан после скана.
        if (Downloader::processScheduledRetries($fileList)) {
            $fileList = Downloader::scanLogPath();
        }

        return ['fileList' => $fileList, 'queued' => $queued];
    }

    // Ответ ?jobs без поля now: оно менялось бы каждую секунду, и снапшот
    // переписывался бы без всякой перемены в загрузках.
    public static function build(?array $fileList, array $queued): array
    {
        $config = $GLOBALS['config'];
        $file = new FileHandler;

        $response = [
            'jobs'     => Downloader::get_current_background_jobs($fileList),
            'queue'    => [],
            'finished' => Downloader::get_finished_background_jobs($fileList),
            // Строго после get_finished_background_jobs() - она этот флаг и взводит
            'retryPending' => Downloader::hasPendingRetry(),
            'videos'   => [],
            'music'    => [],
            'logURL'   => $config['logURL'] ?? '',
            'proxy'    => ProxyStatus::payload(),
            'build'    => self::buildId(),
            // Общий счёт скачанного - вехи и сводка Снежика
            'stats'    => Downloader::downloadStats(),
        ];

        if (!$config['disableQueue']) {
            $formatLabels = ['worst' => 'Булшит', '4K' => '4K', '1440p' => '2K', '1080p' => 'Full HD'];
            foreach ($queued as $key) {
                $dl_type = "video";
                $dl_format = $formatLabels[$key['dl_format']] ?? "Топ";
                if ($key['audio_only']) {
                    $dl_type = "audio";
                    $dl_format = str_replace("--audio-format ", "", $key['audio_format']);
                    $dl_format = str_replace(" --audio-quality 0", " HQ", $dl_format);
                }
                $response['queue'][] = [
                    'pid'       => $key['pid'],
                    'url'       => $key['url'],
                    'dl_format' => $dl_format,
                    'type'      => $dl_type,
                    'start_at'  => $key['start_at'] ?? null,
                    'wait_reason' => $key['wait_reason'] ?? null
                ];
            }
        }

        $media = $file->listMedia();
        foreach ($media['videos'] as $f) {
            $response['videos'][] = self::fileRow($f, $config, $file, 'v');
        }
        foreach ($media['musics'] as $f) {
            $response['music'][] = self::fileRow($f, $config, $file, 'm');
        }

        return $response;
    }

    // Версия кода, с которой нарисована страница (BUILD_ID в part.header.php).
    // Разошлась с той, что в снапшоте, - фронт перезагружает вкладку сам.
    // static_version меняет пересборка образа; в dev код смонтирован живым и
    // правится без неё, поэтому к хэшу подмешано время правки статики и
    // шаблонов. В prod эти mtime застыли в образе и ничего не меняют.
    public static function buildId(): string
    {
        $root = dirname(__DIR__);
        $parts = [trim((string) @file_get_contents($root . '/static_version'))];
        $files = array_merge(
            glob($root . '/js/*.min.js') ?: [],
            glob($root . '/css/*.min.css') ?: [],
            glob($root . '/views/*.php') ?: [],
            [$root . '/index.php']
        );
        foreach ($files as $f) {
            $parts[] = (int) @filemtime($f);
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 12);
    }

    // JSON_INVALID_UTF8_SUBSTITUTE - одна битая буква в имени файла или тексте
    // ошибки не должна превращать весь ответ в пустоту (json_encode отдаёт false).
    public static function encode(array $response): string
    {
        return (string) json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // Раз в столько секунд снапшот трогается и без перемен: по его возрасту фронт
    // отличает "ничего не происходит" от "воркер умер, файл застыл".
    // Больше медленного опроса (12с), иначе ETag меняется к каждому запросу и
    // 304 в простое не бывает; меньше SNAPSHOT_STALE_MS (30с) с запасом на тик.
    public const HEARTBEAT_SECONDS = 20;

    private static int $lastMtime = 0;

    // Атомарная подмена через rename(): читатель видит либо старый файл, либо
    // новый целиком. Пишем только при перемене.
    //
    // nginx строит ETag из mtime и размера, а mtime секундный: две записи за
    // секунду одной длины ("45%" -> "46%") дали бы одинаковый ETag, и браузер
    // получил бы 304 на изменившийся файл. Поэтому mtime строго растёт.
    public static function write(string $json): bool
    {
        $path = self::snapshotPath();
        $current = @file_get_contents($path);
        if ($current === $json) {
            clearstatcache(true, $path);
            if ((int) @filemtime($path) <= time() - self::HEARTBEAT_SECONDS) {
                self::bumpMtime($path);
            }
            return false;
        }
        // Перезапущенный воркер не помнит прошлый mtime - берём его с файла
        clearstatcache(true, $path);
        self::$lastMtime = max(self::$lastMtime, (int) @filemtime($path));
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            return false;
        }
        self::bumpMtime($path);
        return true;
    }

    private static function bumpMtime(string $path): void
    {
        self::$lastMtime = max(time(), self::$lastMtime + 1);
        @touch($path, self::$lastMtime);
    }

    // Снапшот с полем now: часы сервера нужны фронту для отсчёта до старта в
    // очереди. Вставляем в начало объекта, не разбирая JSON заново.
    public static function readWithNow(): ?string
    {
        $json = @file_get_contents(self::snapshotPath());
        if ($json === false || strlen($json) < 2 || $json[0] !== '{') {
            return null;
        }
        $rest = substr($json, 1);
        return '{"now":' . time() . ($rest === '}' ? '' : ',') . $rest;
    }

    // Данные в data-атрибуты, обработчик вешается делегированием в JS - без inline onclick,
    // CSP без unsafe-inline. htmlspecialchars(ENT_QUOTES) достаточно для чистого атрибутного контекста.
    // Разметку (ссылку на файл, кнопку "Удалить") строит фронт - в JSON только
    // данные: готовый HTML повторял имя файла в каждой строке ещё дважды.
    private static function fileRow(array $f, array $config, FileHandler $file, string $type): array
    {
        // Имя уходит уже экранированным: фронт вставляет его в DOM как есть
        $attrName = htmlspecialchars($f["name"], ENT_QUOTES, 'UTF-8');

        $downloadurl = "";
        if ($config['downloadPath'] != "") {
            // Относительная ссылка - фронт делает её абсолютной через window.location для QR-кода.
            $downloadurl = $file->get_downloads_link() . '/' . rawurlencode($f["name"]);
        }

        return [
            'name'             => $attrName,
            'downloadurl'      => $downloadurl,
            'kind'             => ($type === 'v') ? 'video' : 'audio',
            'size'             => $f["size"],
            'size_bytes'       => (int)($f["size_bytes"] ?? 0),
            'pinned'           => (bool)($f["pinned"] ?? false),
            'mtime'            => (int)($f["mtime"] ?? 0)
        ];
    }
}
