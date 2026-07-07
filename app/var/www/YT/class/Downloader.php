<?php

class Downloader
{
    private $dl_list = [];
    private $errors = [];
    private $download_path = "";
    private $config = [];

    /*
     * Список российских доменов для прямого доступа (без прокси).
     * Основан на экстракторах yt-dlp. Прямое подключение предпочтительнее,
     * так как иностранные прокси часто блокируются этими сервисами или работают нестабильно.
     * будет расширяться если будет точно известно что загрузка с сайта успешна.
     */
    private const DIRECT_ACCESS_DOMAINS = [
        // Социальные сети и видеохостинги
        'vk.com', 'vk.ru', 'm.vk.com', 'video.vk.com', 'vkvideo.ru', 'vkclips.ru',
        'ok.ru', 'odnoklassniki.ru',
        'rutube.ru', 'rutube.com',
        'coub.com',
        'pikabu.ru',
        'mail.ru', 'my.mail.ru', 'video.mail.ru',
        // Экосистема Яндекса
        'yandex.ru', 'yandex.com',
        'yandexvideo.ru', 'yandexvideo.com',
        'music.yandex.ru', 'music.yandex.com',
        'disk.yandex.ru', 'disk.yandex.com',
        'dzen.ru', 'dzen.com', 'zen.yandex.ru', 'zen.yandex.com',
        // Федеральные телеканалы и медиа (официальные сайты)
        '1tv.ru',
        'ntv.ru',
        'matchtv.ru',
        'tvc.ru',
        'ctc.ru',
        'tnt-online.ru',
        'ren.tv',
        'tvzvezda.ru',
        'mir24.tv',
        '5-tv.ru',
        'smotrim.ru', // ВГТРК
        'life.ru',
        'tvigle.ru',
        // Стриминговые сервисы (экстракторы есть, но часто требуют авторизации)
        'kinopoisk.ru',
        'ivi.ru', 'ivi.tv',
        'okko.tv', 'okko.com',
        'more.tv', 'moretv.ru',
        'start.ru', 'premier.one',
        'twitch.tv', 'clips.twitch.tv',
        'kick.com', 'goodgame.ru',
        'vkplay.ru',
        // Музыкальные и аудио сервисы
        'zvuk.com',
        'zaycev.fm',
        'muzofond.fm',
        'pleer.net',
        // Социальные сети и короткие видео
        'yappy.media',
        'tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com', 'douyin.com',
        'reddit.com', 'redd.it', 'v.redd.it',
        // Аудио и музыкальные платформы
        'bandcamp.com',
        // Архивы и нишевые платформы
        'archive.org', 'vikingfile.com', 'vik1ngfile.site', 'digriz.ddns.net'
    ];

    public function __construct($dl_list)
    {
        // Проверка инициализации глобальной конфигурации
        if (!isset($GLOBALS['config'])) {
            $this->errors[] = "Конфигурация не загружена";
            $_SESSION['errors'] = $this->errors;
            return;
        }

        $this->download_path = (new FileHandler())->get_downloads_folder();
        $this->config = $GLOBALS['config'];
        $this->dl_list = $dl_list;

        if (!$this->check_requirements()) {
            return;
        }

        if (!empty($dl_list)) {
            foreach ($dl_list as $onedownload) {
                // Обработка множественных ссылок через ||
                $urls = explode('||', $onedownload['url']);
                foreach ($urls as $url) {
                    $url = trim($url);
                    if (!empty($url) && !$this->is_valid_url($url)) {
                        $this->errors[] = "«" . $url . "» ты в порядке? Поправь ссыль, ну че ты!";
                    }
                }
            }

            if (!empty($this->errors)) {
                $_SESSION['errors'] = $this->errors;
                return;
            }

            $this->do_download();
        }
    }

    // Кэш числа фоновых задач в рамках одного запроса. background_jobs() зовётся
    // многократно (do_download в цикле, process_queue), а glob+чтение /proc дорогие.
    // Инкрементируется при запуске нового процесса, сбрасывается при kill.
    private static $bg_jobs_cache = null;

    public static function background_jobs()
    {
        if (self::$bg_jobs_cache !== null) {
            return self::$bg_jobs_cache;
        }

        if (!function_exists('shell_exec') || !isset($GLOBALS['config']['logPath'])) {
            return 0;
        }

        // Считаем только процессы, у которых есть валидные pid-файлы
        $count = 0;
        $logPath = $GLOBALS['config']['logPath'];

        foreach (glob($logPath . '/pid_*') as $pidFile) {
            $content = @file_get_contents($pidFile);
            if ($content === false) continue;

            $jpid = trim(explode("\n", $content)[0] ?? '');

            // Проверяем, что процесс реально существует - без удаления файла!
            if (!empty($jpid) && file_exists("/proc/$jpid")) {
                $count++;
            }
        }

        self::$bg_jobs_cache = $count;
        return $count;
    }

    public function max_background_jobs()
    {
        return $this->config['max_dl'] ?? 3;
    }

    public static function get_current_background_jobs()
    {
        if (!isset($GLOBALS['config']['logPath']) || !is_dir($GLOBALS['config']['logPath'])) {
            return [];
        }

        $bjs = [];
        $logPath = $GLOBALS['config']['logPath'];
        $youtubedlExe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';
        $dir = new DirectoryIterator($logPath);

        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->isFile() && strpos($fileinfo->getFilename(), "pid_") === 0) {
                $pidFile = $fileinfo->getPathname();
                $outfile = $logPath . "/" . str_replace("pid_", "job_", $fileinfo->getFilename());
                $completefile = $logPath . "/" . str_replace("pid_", "ytdl_", $fileinfo->getFilename());

                if (!file_exists($outfile)) {
                    @unlink($pidFile);
                    continue;
                }

                $content = @file_get_contents($pidFile);
                if ($content === false) {
                    continue;
                }

                $jpid_parts = explode("\n", trim($content));
                $jpid = $jpid_parts[0] ?? '';
                $ytcmd = $jpid_parts[1] ?? '';
                $urltext = $jpid_parts[2] ?? '';

                // Проверка: процесс существует?
                if (!empty($jpid) && !file_exists("/proc/" . $jpid)) {
                    @unlink($pidFile);
                    self::finalize_job_log($outfile, $completefile, $ytcmd, $urltext);
                    // Авторетрей через прокси при гео-блоке/403 для прямых доменов
                    $retryPid = self::autoRetryIfNeeded($completefile);
                    $retryStatus = "Первая попытка не прошла, пробую через прокси";
                    if ($retryPid === null) {
                        // Авторетрей с куками YouTube при бот-чеке/приватности/возрасте
                        $retryPid = self::autoRetryWithCookiesIfNeeded($completefile);
                        $retryStatus = "Обычный способ заблокирован, пробую с куками аккаунта";
                    }

                    // Ретрей запускается тут же, но его pid_-файл пишется асинхронно
                    // (echo $! в фоне) и в ЭТОТ ответ ?jobs ещё не попадает: каталог
                    // уже проитерирован DirectoryIterator. Без подсказки фронтенд
                    // увидел бы "активных нет", ушёл в медленный опрос (12с) и загрузка
                    // на эти секунды "провалилась" бы, пока юзер не нажмёт F5. Поэтому
                    // сразу отдаём синтетическую активную строку с РЕАЛЬНЫМ pid ретрея -
                    // на следующем быстром опросе настоящая задача с тем же pid просто
                    // заменит её бесшовно.
                    if ($retryPid !== null) {
                        $isaudioRetry = (strpos($retryPid, "_a") !== false);
                        $bjs[] = array(
                            'file'   => "Повторная попытка",
                            'site'   => "Повтор",
                            'status' => $retryStatus,
                            'type'   => $isaudioRetry ? "audio" : "video",
                            'pid'    => $retryPid,
                            'url'    => $urltext
                        );
                    }
                    continue;
                }

                // Проверка: это действительно процесс yt-dlp?
                if (!empty($jpid)) {
                    $pidcmd = @file_get_contents('/proc/' . $jpid . '/cmdline');
                    if ($pidcmd !== false && strpos($pidcmd, $youtubedlExe) === false) {
                        @unlink($pidFile);
                        self::finalize_job_log($outfile, $completefile, $ytcmd, $urltext);
                        continue;
                    }
                }

                $handle = @fopen($outfile, "r");
                if (!$handle) {
                    continue;
                }

                $lastline = "";
                $verylastline = "";
                $filename = "Ща..";
                $site = "Погоди...";
                $siteset = false;
                $isaudio = (strpos($fileinfo->getFilename(), "_a") !== false);
                $listpos = "";
                $playlist = "";
                // Фазы задачи с переводом озвучки (Яндекс-VOT). Маркеры встречаются
                // только у translate-задач, поэтому обычные загрузки их не покажут.
                $votPhase = false;
                $muxPhase = false;

                while (($line = fgets($handle)) !== false) {
                    // yt-dlp печатает по-английски: "[download] Downloading item N of M"
                    if (preg_match('/\[download\] Downloading item (.+)/', $line, $lm)) {
                        $listpos = "(" . trim($lm[1]) . ")";
                    }

                    // yt-dlp записал путь файла (--print-to-file) - значит скачивание
                    // кончилось. В параллельном режиме vot-cli работает и во время
                    // скачивания, поэтому фазу "перевожу" включаем именно по этому
                    // маркеру, а не по раннему баннеру vot-cli, - пока идёт закачка,
                    // пользователь видит её проценты, а не преждевременное "перевожу".
                    if (strpos($line, "Writing '%(filepath)s'") !== false) {
                        $votPhase = true;
                    }
                    // mux_translated.sh печатает "[vot] ...", а сырой ffmpeg-микс - "frame="
                    if (strpos($line, '[vot]') !== false || strpos($line, 'frame=') !== false) {
                        $muxPhase = true;
                    }

                    // "[extractor] Playlist TITLE: Downloading N items"
                    if (preg_match('/\] Playlist (.+): Downloading \d+ items?\s*$/', $line, $pm)) {
                        $playlist = trim($pm[1]) . "<br />";
                    }

                    if (trim($line) != "") {
                        $lastline = $line;
                    }

                    $verylastline = $line;

                    if (!$siteset) {
                        $detected = self::detectSite($line);
                        if ($detected !== null) {
                            $siteset = true;
                            $site = $detected;
                        }
                    }

                    if (strpos($line, 'Destination') !== false) {
                        $pos = strrpos($line, '/');
                        $filename = $pos === false ? $line : substr($line, $pos + 1);
                    }
                }

                fclose($handle);

                // yt-dlp выводит паузу как "[download] Sleeping N.NN seconds ...".
                // Ловим её на сырой строке (до перезаписи статуса ниже) и показываем
                // человеку по-русски, что это осознанное ожидание, а не зависание.
                $sleepStatus = null;
                if (preg_match('/Sleeping\s+([\d.]+)\s*second/i', $lastline, $sm)) {
                    $sleepStatus = "Пауза " . max(1, (int) round($sm[1])) . " сек, чтобы сайт не ругался на частые запросы";
                }

                if ($filename == "Ща..") {
                    $lastline = "Собираю информацию по сайту";
                } else {
                    $pos = strrpos($lastline, '[download]');
                    $lastline = $pos === false ? "" : trim(substr($lastline, $pos + 11));
                    $filename = urldecode(str_replace("%0A", "", urlencode($filename . "" . $listpos)));

                    if ($isaudio && strpos($verylastline, '[ffmpeg]') !== false) {
                        $lastline = "Конвертирую в аудио, это займет время";
                    }
                }

                if (strpos($lastline, '100%') !== false || $lastline == "") {
                    $lastline = "В Процессе...";
                }

                // Пауза важнее прочих статусов - перекрываем в самом конце
                if ($sleepStatus !== null) {
                    $lastline = $sleepStatus;
                }

                // Фаза перевода перекрывает всё: скачивание уже позади, а vot/ffmpeg
                // не пишут проценты - без этого юзер видел бы вечное "В Процессе".
                if ($muxPhase) {
                    $lastline = "Вклеиваю русскую дорожку в видео, почти готово";
                } elseif ($votPhase) {
                    // Живой таймер: сколько уже идёт перевод. Считаем от старта задачи
                    // (mtime pid-файла) - в параллельном режиме vot-cli стартует вместе
                    // со скачиванием, так что это и есть длительность перевода. Фронт
                    // опрашивает ?jobs каждые секунды и перерисовывает - счётчик растёт.
                    $elapsed = max(0, time() - $fileinfo->getMTime());
                    $mins = intdiv($elapsed, 60);
                    $secs = $elapsed % 60;
                    $human = $mins > 0 ? ($mins . " мин " . $secs . " сек") : ($secs . " сек");
                    $lastline = "Перевожу озвучку через Яндекс, идёт уже " . $human;
                }

                $bjs[] = array(
                    'file' => $playlist . $filename,
                    'site' => $site,
                    'status' => str_replace("\n", "", $lastline),
                    'type' => $isaudio ? "audio" : "video",
                    'pid' => $fileinfo->getFilename(),
                    'url' => $urltext
                );
            }
        }

        return $bjs;
    }

    // Проверка, переиспользуемая ли ошибка (сетевая временная ошибка vs постоянная проблема контента)
    private static function isRetryableError($status)
    {
        // Переиспользуемые сетевые ошибки
        $retryable_keywords = [
            'Тайм-аут',
            'ETIMEDOUT',
            'Connection timed out',
            'Connection refused',
            'Соединение оборвалось',
            'Сеть недоступна',
            'Network unreachable',
            'HTTP Error 429',
            'Too Many Requests',
            'HTTP Error 503',
            'Service Unavailable',
            'Service temporarily unavailable',
            'DNS не резолвил',
            "couldn't resolve host",
            'Failed to resolve',
            'DNS error',
            'Name or service not known',
            'Temporary failure',
        ];

        foreach ($retryable_keywords as $keyword) {
            if (stripos($status, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    // Автоматический ретрей через прокси при гео-блоке/403 для прямых доменов.
    // Возвращает имя нового pid_-файла ретрея (для синтетической активной строки
    // в ?jobs), либо null, если ретрей не запускался.
    private static function autoRetryIfNeeded($completefile)
    {
        if (!file_exists($completefile)) {
            return null;
        }

        $log_content = @file_get_contents($completefile);
        if ($log_content === false) {
            return null;
        }

        // Проверка: уже был ретрей?
        if (strpos($log_content, '[RETRY_ATTEMPTED:') !== false) {
            return null;
        }

        // Парсим ошибку
        $jobstatus = self::parseYtDlpError($log_content);

        // Проверяем: переиспользуемая ли ошибка?
        if (!self::isRetryableError($jobstatus)) {
            return null;
        }

        // Добавляем маркер, чтобы не ретрейтить снова
        $retry_marker = "[RETRY_ATTEMPTED:" . time() . "] Авторетрей через прокси\n";
        @file_put_contents($completefile, $retry_marker, FILE_APPEND);

        // Ретрей ищет лог по имени готового файла (ytdl_*), а не по уже удалённому
        // pid_* - иначе restart_download не находит файл и молча падает в ошибку,
        // а маркер [RETRY_ATTEMPTED] уже записан, так что второй попытки не будет
        $newpid = self::restart_download(basename($completefile), true);
        return $newpid ?: null;
    }

    // Ошибки, для которых имеет смысл точечно повторить попытку с куками
    // (приватность/возраст/подписка) - в отличие от isRetryableError() выше,
    // это не временные сетевые сбои, а признак закрытого контента.
    //
    // Бот-чек тоже в списке: если bgutil не смог получить PO-токен (сбой
    // сервиса, обновление YouTube и т.п.), настоящие куки авторизованного
    // аккаунта - рабочий обходной путь независимо от здоровья bgutil.
    // Без этого пункта сбой bgutil ронял бы вообще все YouTube-загрузки,
    // хотя куки, если настроены, реально могли бы их спасти.
    private static function needsCookiesRetry($status)
    {
        $keywords = [
            'Приватное видео',
            '18+ контент',
            'Нужна авторизация',
            'Members-only',
            'принял нас за бота',
        ];

        foreach ($keywords as $keyword) {
            if (stripos($status, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    // Точечный авторетрей с куками: первая попытка всегда идёт БЕЗ куки (см.
    // комментарий в executeDownload про Data Sync ID), куки подключаются только
    // если реально понадобились - обычный/публичный контент их вообще не видит.
    // Возвращает имя нового pid_-файла ретрея (для синтетической активной строки
    // в ?jobs), либо null, если ретрей не запускался.
    private static function autoRetryWithCookiesIfNeeded($completefile)
    {
        $cookiesFile = $GLOBALS['config']['youtubeCookiesFile'] ?? '';
        if (empty($cookiesFile) || !is_readable($cookiesFile)) {
            return null;
        }

        if (!file_exists($completefile)) {
            return null;
        }

        $log_content = @file_get_contents($completefile);
        if ($log_content === false) {
            return null;
        }

        // Общий маркер с проксийным ретреем - не более одной авто-попытки на задачу
        if (strpos($log_content, '[RETRY_ATTEMPTED:') !== false) {
            return null;
        }

        // Куки уже были в команде (например, при ручном restart) - повтор не поможет.
        // Проверяем ТОЛЬКО строку [ytcmd] (реальную команду), а не весь лог: yt-dlp
        // в тексте ошибки бот-чека сам советует "...or --cookies for the auth", и
        // проверка по всему логу ложно срабатывала на этой прозе, глуша ретрей.
        if (preg_match('/^\[ytcmd\].*--cookies\s/m', $log_content)) {
            return null;
        }

        $jobstatus = self::parseYtDlpError($log_content);
        if (!self::needsCookiesRetry($jobstatus)) {
            return null;
        }

        $retry_marker = "[RETRY_ATTEMPTED:" . time() . "] Авторетрей с куками YouTube\n";
        @file_put_contents($completefile, $retry_marker, FILE_APPEND);

        // restart_download читает completefile синхронно ДО запуска нового процесса,
        // поэтому его безопасно удалить сразу после успешного старта. Убираем лог
        // снятой (первой, без кук) попытки, чтобы в "Последних" не висели две строки
        // на одну ссылку - остаётся только итог ретрея. Удаляем ТОЛЬКО при успехе:
        // если старт не удался (например, не прошла security-проверка команды),
        // первый лог остаётся на месте, задача не исчезает бесследно.
        $newpid = self::restart_download(basename($completefile), false, true);
        if ($newpid) {
            @unlink($completefile);
            return $newpid;
        }
        return null;
    }

    // Вспомогательный метод для завершения лога (DRY)
    private static function finalize_job_log($outfile, $completefile, $ytcmd, $urltext)
    {
        if (!file_exists($outfile)) return;

        $content = file_get_contents($outfile);
        if (!empty($content) && substr($content, -1) !== "\n") {
            file_put_contents($outfile, "\n", FILE_APPEND);
        }

        rename($outfile, $completefile);
        // Убираем маркер [USES_PROXY] из записанной команды, чтобы файл был чистым
        $ytcmd = preg_replace('/^\[USES_PROXY\]\s+/', '', $ytcmd);
        file_put_contents($completefile, "[ytcmd] " . $ytcmd . "\n", FILE_APPEND);
        file_put_contents($completefile, "[yturl] " . $urltext . "\n", FILE_APPEND);
    }

    // Служебные теги yt-dlp и наши маркеры, которые НЕ являются именем сайта.
    // В translate-задачах vot-cli пишет в тот же лог параллельно с yt-dlp,
    // поэтому имя сайта берём только со строки-анонса экстрактора ("[youtube] ..."),
    // пропуская и не-скобочный вывод vot-cli, и эти служебные теги.
    private const NON_EXTRACTOR_TAGS = [
        'download', 'info', 'debug', 'vot', 'ffmpeg', 'merger', 'metadata',
        'extractaudio', 'embedthumbnail', 'videoconvertor', 'sponsorblock',
        'ytcmd', 'yturl', 'retry_attempted',
    ];

    // Имя сайта из строки лога, либо null если строка не похожа на тег экстрактора.
    private static function detectSite($line)
    {
        if (!preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            return null;
        }
        $base = explode(':', strtolower(trim($m[1])))[0]; // "youtube:tab" -> "youtube"
        if (in_array($base, self::NON_EXTRACTOR_TAGS, true)) {
            return null;
        }
        return ucfirst($m[1]);
    }

    public static function get_queued_jobs()
    {
        if (!isset($GLOBALS['config']['logPath'])) return [];

        $qjs = [];
        $queue_file = $GLOBALS['config']['logPath'] . "/dl_queue";

        if (!file_exists($queue_file)) {
            return $qjs;
        }

        $handle = fopen($queue_file, "r");
        if (!$handle) return [];

        // Блокировка чтения
        flock($handle, LOCK_SH);

        $corrupt_queue = false;
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === "") continue;

            if (substr($line, 0, 7) !== "queueid") {
                $corrupt_queue = true;
                break;
            }

            $parts = explode("=", $line, 2);
            if (count($parts) < 2) continue;

            $pid = $parts[0];
            $urlData = $parts[1];
            $urlParts = explode(">", $urlData);

            $audio_only = !empty(trim($urlParts[2] ?? ''));

            $qjs[] = array(
                'pid' => $pid,
                'url' => urldecode(trim($urlParts[0] ?? '')),
                'dl_format' => $urlParts[1] ?? '',
                'audio_only' => $audio_only,
                'audio_format' => $urlParts[3] ?? ''
            );
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($corrupt_queue) {
            @unlink($queue_file);
            return [];
        }

        return $qjs;
    }

    public static function get_finished_background_jobs()
    {
        if (!isset($GLOBALS['config']['logPath'])) return [];
        $logPath = $GLOBALS['config']['logPath'];

        // Сигнатура завершённых логов (имя+mtime+размер каждого ytdl_). Меняется
        // при появлении/переименовании/дозаписи лога - тогда и пересобираем.
        // Поллинг без изменений делает только stat, без открытия и разбора
        // каждого файла - основная стоимость метода снималась именно на разборе.
        $sig = self::finished_signature($logPath);
        $cacheFile = $logPath . '/.finished_cache';

        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data) && ($data['sig'] ?? null) === $sig
                && isset($data['jobs']) && is_array($data['jobs'])) {
                return $data['jobs'];
            }
        }

        $jobs = self::build_finished_jobs($logPath);
        @file_put_contents(
            $cacheFile,
            json_encode(['sig' => $sig, 'jobs' => $jobs], JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        return $jobs;
    }

    private static function finished_signature($logPath)
    {
        $parts = [];
        $dir = new DirectoryIterator($logPath);
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->isFile() && strpos($fileinfo->getFilename(), "ytdl_") === 0) {
                $parts[] = $fileinfo->getFilename() . ':' . $fileinfo->getMTime() . ':' . $fileinfo->getSize();
            }
        }
        sort($parts);
        return md5(implode('|', $parts));
    }

    private static function build_finished_jobs($logPath)
    {
        $bjs = [];
        $dir = new DirectoryIterator($logPath);

        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->isFile() && strpos($fileinfo->getFilename(), "ytdl_") === 0) {
                $filepath = $fileinfo->getPathname();
                $handle = @fopen($filepath, "r");
                if (!$handle) continue;

                $lastline = "";
                $verylastline = "";
                $filename = "Дундук :)";
                $site = "N/A";
                $siteset = false;
                $isaudio = (strpos($fileinfo->getFilename(), "_a") !== false);
                $listpos = "";
                $playlist = "";
                $urltext = "";
                $jobstatus = "Готово";
                $usedCookies = false;

                while (($line = fgets($handle)) !== false) {
                    // "[download] Downloading item N of M" - берём M (всего в плейлисте)
                    if (preg_match('/\[download\] Downloading item \d+ of (\S+)/', $line, $lm)) {
                        $listpos = trim($lm[1]);
                    }

                    // "[extractor] Playlist TITLE: Downloading N items"
                    if (preg_match('/\] Playlist (.+): Downloading \d+ items?\s*$/', $line, $pm)) {
                        $playlist = trim($pm[1]);
                    }

                    $verylastline = $line;

                    if (!$siteset) {
                        $detected = self::detectSite($line);
                        if ($detected !== null) {
                            $siteset = true;
                            $site = $detected;
                        }
                    }

                    if (strpos($line, '[yturl]') !== false) {
                        $urltext = trim(substr($line, 8));
                    }

                    // [ytcmd] пишется finalize_job_log() одной строкой с полной командой -
                    // если в ней есть --cookies, значит это была точечная попытка после
                    // блокировки обычного способа (см. autoRetryWithCookiesIfNeeded())
                    if (strpos($line, '[ytcmd]') !== false && stripos($line, '--cookies') !== false) {
                        $usedCookies = true;
                    }

                    if (strpos($line, 'Destination') !== false) {
                        $pos = strrpos($line, '/');
                        $filename = $pos === false ? $line : substr($line, $pos + 1);
                    }

                    // "[download] /path/file.ext has already been downloaded"
                    // либо "[download] The file has already been downloaded"
                    if (strpos($line, 'has already been downloaded') !== false) {
                        if (preg_match('#\[download\]\s+(.+?)\s+has already been downloaded#', $line, $dm)) {
                            $name = $dm[1];
                            $slash = strrpos($name, '/');
                            $filename = ($slash === false) ? $name : substr($name, $slash + 1);
                            if ($filename === 'The file') {
                                $filename = 'Файл';
                            }
                        }
                        $jobstatus = "Отменено (Уже Загружено)";
                    }
                }

                fclose($handle);

                if (strpos($fileinfo->getFilename(), "_cancelled") !== false) {
                    $jobstatus = "Отменено";
                }

                if ($playlist != "") {
                    $filename = $playlist . " (" . $listpos . " files)";
                }

                if ($filename == "Дундук :)") {
                    $type = "unknown";
                    $log_content = @file_get_contents($filepath);

                    if ($log_content) {
                        // Приоритетная проверка: порно-фильтр
                        if (preg_match('/does not pass filter.*skipping/i', $log_content)
                            || strpos($log_content, 'webpage_url!~=') !== false) {
                            $jobstatus = "Порнографию я вам не дам 🔞";
                        } else {
                            $jobstatus = self::parseYtDlpError($log_content);
                        }
                    } else {
                        $jobstatus = "Лог пуст 🤷\nЗагрузка даже не стартовала";
                    }
                } else {
                    $type = $isaudio ? "audio" : "video";
                    // Пояснение только для чистого "Готово" - "Отменено"/"Отменено
                    // (Уже Загружено)" и так информативны сами по себе
                    if ($usedCookies && $jobstatus === "Готово") {
                        $jobstatus = "Готово 🍪\nОбычный способ заблокировал YouTube, сработало с куки аккаунта";
                    }
                }

                $bjs[] = array(
                    'file' => $filename,
                    'site' => $site,
                    'status' => $jobstatus,
                    'type' => $type,
                    'pid' => $fileinfo->getFilename(),
                    'url' => $urltext
                );
            }
        }

        return $bjs;
    }

    /**
     * Вырезает из лога всё, что может раскрыть прокси, IP, логины и URL.
     * Применяется ДО любого анализа и ДО вывода сообщения пользователю.
     */
    private static function sanitizeLog(string $log): string
    {
        // 0. Маскируем env переменные с чувствительными данными (all_proxy, http_proxy и т.д.)
        $log = preg_replace('/env\s+all_proxy=[^\s]+/', 'env all_proxy=[SOCKS5_PROXY]', $log);

        // 1. Удаляем любые URL (http, https, socks, socks5, ftp) вместе с user:pass@host:port
        $log = preg_replace('#\b[a-z][a-z0-9+\-.]*://[^\s\'"<>]+#i', '[URL]', $log);

        // 2. Удаляем «голые» user:pass@host конструкции (если URL уже частично съеден)
        $log = preg_replace('#[a-zA-Z0-9._%+\-]+:[^@\s]+@[a-zA-Z0-9.\-:]+#', '[PROXY]', $log);

        // 3. Удаляем IPv4-адреса (включая с портом)
        $log = preg_replace('#\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?\b#', '[IP]', $log);

        // 4. Удаляем IPv6-адреса в квадратных скобках с портом
        $log = preg_replace('#\[[0-9a-fA-F:]+\](:\d+)?#', '[IP]', $log);

        // 5. Удаляем «domain:port» паттерны, которые могли остаться
        $log = preg_replace('#\b[a-zA-Z0-9\-]+\.[a-zA-Z]{2,}:\d{2,5}\b#', '[HOST]', $log);

        // 6. Удаляем возможные токены/ключи длиной от 32 символов
        $log = preg_replace('#\b[a-zA-Z0-9_\-]{32,}\b#', '[TOKEN]', $log);

        return $log;
    }

    /**
     * Парсит лог yt-dlp и возвращает человекочитаемое сообщение об ошибке.
     * Перед анализом лог санитизируется  - прокси/IP/токены не попадут в вывод.
     */
    // Правила «регексп -> сообщение», по порядку приоритета. Первое совпадение
    // выигрывает, поэтому порядок значим (сетевые -> доступность -> форматы ->
    // постобработка -> системные). Добавлять новую ошибку - вставить строку.
    private const ERROR_RULES = [
        // === Протухшие куки YouTube (выше бот-детекта: отдельный признак от
        // yt-dlp - "cookies are no longer valid" - не путать с обычным
        // бот-чеком, чинится по-разному: см. youtubeCookiesFile в config.php) ===
        ['/cookies are no longer valid|cookies have expired|cookies are not valid|Failed to load cookies/i', "Куки YouTube протухли 🍪\nНадо зайти под тем же аккаунтом и обновить cookies.txt на сервере"],

        // === Бот-детект YouTube (выше всех: часто идёт в паре с 429, но
        // истинная причина - именно бот-чек, а не перегрузка сайта) ===
        ['/not a bot|Sign in to confirm you.re not a bot/i', "YouTube принял нас за бота 🤖\nIP PROXY засвечен - лучше подождать"],

        // === Сетевые ошибки ===
        ['/Name or service not known|Could not resolve host|No address associated with hostname/i', "DNS не резолвил хост 🌐\nПроверь ссылку или интернет"],
        ['/Connection refused|ECONNREFUSED/i', "Сервер сказал «нет» 🚪\nConnection refused"],
        ['/timed out|ETIMEDOUT|Connection timed out/i', "Тайм-аут ⏳\nСервер слишком долго молчит"],
        ['/Network is unreachable|ENETUNREACH/i', "Сеть недоступна 🔌"],
        ['/No route to host/i', "Маршрута до хоста нет 🗺️\nПроверь прокси/сеть"],
        ['/HTTP Error 429|Too Many Requests/i', "Сайт оверлоуд 🚦\nПодожди"],
        ['/HTTP Error 403|403 Forbidden/i', "403 Forbidden 🚫\nНе пущает - нужен прокси/куки"],
        ['/HTTP Error 404|404 Not Found/i', "404 Not Found 👻\nСтраницы больше нет"],
        ['/HTTP Error 503|503 Service Unavailable/i', "503 💤\nСайт прилёг"],
        ['/HTTP Error 500|500 Internal Server Error/i', "500 💥\nУ сайта внутренние проблемы"],
        ['/SSL.*handshake|certificate verify failed|SSL_ERROR/i', "Ошибка SSL/HTTPS 🔒\nСертификат не прошёл проверку"],
        ['/Unable to download webpage/i', "Не удалось открыть страницу 🕸️"],
        ['/Unable to connect to|Connection aborted/i', "Соединение оборвалось 🔌\nПопробуй ещё раз"],

        // === Доступность контента ===
        ['/Video unavailable|This video is not available|video is unavailable/i', "Видео недоступно 🙈"],
        ['/Private video|this video is private/i', "Приватное видео 🔐\nТолько для своих"],
        ['/has been removed|removed by the uploader/i', "Видео удалено автором 🗑️"],
        ['/age-restricted|Sign in to confirm your age|confirm your age/i', "18+ контент 🔞\nНужны куки авторизованного аккаунта"],
        ['/only available for registered users|login required|Sign in to/i', "Нужна авторизация 👤\nНужны куки авторизованного аккаунта"],
        ['/members-only|Members only content/i', "Members-only 💎\nНужна подписка на канал тем же аккаунтом, чьи куки настроены"],
        ['/Music Premium|YouTube Music Premium/i', "YTMusic Premium 🎵\nТребуется лухари подписка"],
        ['/requires payment|paid content|purchase this/i', "Платный контент 💰\nСкачивание невозможно \nГде деньги?"],
        ['/live event will begin|Premieres in|is live and is being watched/i', "Ну начинается - пойду поссу, пойду посру 📡"],
        ['/region-locked|not available in your country|geo-blocked|country-specific/i', "Гео-блок 🌍\nВидео недоступно в регионе Качалки"],
        ['/This channel is not available|channel does not exist/i', "Канал не существует или удалён 📭"],

        // === Форматы и извлечение ===
        ['/Unsupported URL|no suitable extractor|no extractor/i', "Сайт не поддерживается 🤷\nПроверь ссылку"],
        ['/No video formats|no formats available|no playable media/i', "Форматов для скачивания нет 📦\nВидео без дорожек?"],
        ['/unable to extract video url|Unable to extract.*url|Could not extract URL/i', "Не удалось извлечь ссылку на видео 🔍\nСайт поменялся?"],
        ['/Incomplete YouTube ID|Invalid YouTube URL|not a valid URL|Invalid URL/i', "Ссылка выглядит кривой ✏️\nПроверь URL"],
        ['/This video is encrypted|encrypted media/i', "Видео зашифровано 🔑\nСкачивание невозможно"],
        ['/DRM-protected|has DRM/i', "DRM-защита 🛡️\nОбход невозможен"],

        // === Постобработка ===
        ['/ffmpeg.*not found|ffmpeg.*is not recognized|unable to open ffmpeg|No ffmpeg/i', "FFmpeg не найден 🎬\nУстанови его на сервер"],
        ['/Postprocessing.*failed|conversion failed|merge failed/i', "Ошибка постобработки (ffmpeg) ⚙️\nФайл мог повредиться"],

        // === Системные ошибки ===
        ['/Permission denied|EACCES/i', "Нет прав на запись 🔒\nПроверь права на папку"],
        ['/No space left on device|ENOSPC/i', "Диск переполнен 💾\nАхтунг!"],
    ];

    private static function parseYtDlpError(string $log): string
    {
        // СНАЧАЛА чистим, ПОТОМ матчим
        $log = self::sanitizeLog($log);

        foreach (self::ERROR_RULES as [$pattern, $message]) {
            if (preg_match($pattern, $log)) {
                return $message;
            }
        }

        // Фоллбэк: вытащим сам текст ошибки yt-dlp, если ничего не подошло.
        // Лог уже санитизирован  - прокси/IP/токены вырезаны.
        if (preg_match('/ERROR:\s*(.{10,120})/i', $log, $m)) {
            return "⚠️ " . trim($m[1]);
        }

        return "🤔 ХЗ, что случилось \nСмотри лог";
    }

    public static function kill_one_of_them($fpid)
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        // Защита от выхода за пределы директории
        $fpid = basename($fpid);
        $file = $GLOBALS['config']['logPath'] . '/' . $fpid;

        if (!file_exists($file)) return;

        $outfile = $GLOBALS['config']['logPath'] . "/" . str_replace("pid_", "job_", $fpid);
        $completed = $GLOBALS['config']['logPath'] . "/" . str_replace("pid_", "ytdl_", $fpid) . "_cancelled";

        $content = @file_get_contents($file);
        if ($content === false) return;

        $jid_parts = explode("\n", trim($content));
        $ytcmd = $jid_parts[1] ?? '';
        $urltext = $jid_parts[2] ?? '';
        $jpid = $jid_parts[0] ?? '';

        // Убиваем только конкретный процесс, а не весь ffmpeg на сервере
        if (!empty($jpid) && file_exists('/proc/'.$jpid)) {
            $pidcmd = @file_get_contents('/proc/'.$jpid.'/cmdline');
            if ($pidcmd !== false && strpos($pidcmd, $GLOBALS['config']['youtubedlExe']) !== false) {
                shell_exec("kill " . escapeshellarg($jpid));
                // Даем время на очистку дочерних процессов
                usleep(500000);
            }
        }

        self::finalize_job_log($outfile, $completed, $ytcmd, $urltext);
        @unlink($file);

        self::$bg_jobs_cache = null;
    }

    public static function kill_them_all()
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        $logPath = $GLOBALS['config']['logPath'];

        foreach (glob($logPath . '/pid_*') as $file) {
            $fpid = basename($file);
            $jobfile = str_replace("pid_", "job_", $file);
            $completed = str_replace("pid_", "ytdl_", $file) . "_cancelled";

            $content = @file_get_contents($file);
            if ($content !== false) {
                $jid_parts = explode("\n", trim($content));
                $ytcmd = $jid_parts[1] ?? '';
                $urltext = $jid_parts[2] ?? '';
                self::finalize_job_log($jobfile, $completed, $ytcmd, $urltext);
            }
            @unlink($file);
        }

        exec("pgrep -f 'yt-dlp'", $output);
        if (!empty($output)) {
            foreach ($output as $p) {
                shell_exec("kill " . escapeshellarg($p));
            }
        }

        $folder = $GLOBALS['config']['outputFolder'] ?? '';
        if (!empty($folder) && !$GLOBALS['config']['keepPartialFiles']) {
            foreach (glob($folder . '/*.part') as $file) {
                @unlink($file);
            }
        }

        self::$bg_jobs_cache = null;
    }

    // Возвращает имя нового pid_-файла запущенной задачи (строку), либо false,
    // если старт не удался. Вызывающему авторетрею это нужно, чтобы: (1) удалить
    // лог снятой попытки только при успешном старте, (2) сразу показать ретрей
    // активной строкой в ?jobs (реальный pid новой задачи, без асинхронного окна).
    public static function restart_download($fpid, $forceUseProxy = false, $forceUseCookies = false)
    {
        if (!isset($GLOBALS['config']['logPath'])) return false;

        $logPath = $GLOBALS['config']['logPath'];

        // Санитизация имени файла
        $fpid = basename($fpid);
        $file = $logPath . '/' . $fpid;

        if (!file_exists($file)) {
            // Попытка найти отмененный файл
            if (strpos($fpid, 'pid_') === 0) {
                $cancelled = $logPath . '/' . str_replace('pid_', 'ytdl_', $fpid) . '_cancelled';
                if (file_exists($cancelled)) $file = $cancelled;
            } elseif (strpos($fpid, 'ytdl_') === 0 && strpos($fpid, '_cancelled') === false) {
                $cancelled = $file . '_cancelled';
                if (file_exists($cancelled)) $file = $cancelled;
            }
        }

        if (!file_exists($file)) {
            $_SESSION['errors'] = ["Лог-файл не найден: $fpid"];
            error_log("[YTDL] Restart failed: file not found: $file");
            return false;
        }

        $ytcmd = "";
        $urltext = "";
        $usesProxy = false;
        $handle = fopen($file, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if (($pos = strpos($line, '[ytcmd]')) !== false) {
                    $ytcmd = trim(substr($line, $pos + 8));
                    // Определяем использование прокси либо по маркеру [USES_PROXY]
                    // (он ещё есть у задач, которые не были финализированы), либо по
                    // замаскированному префиксу "env all_proxy=[SOCKS5_PROXY]", который
                    // finalize_job_log() оставляет после удаления маркера у завершённых задач.
                    if (strpos($ytcmd, '[USES_PROXY]') === 0) {
                        $usesProxy = true;
                        $ytcmd = trim(substr($ytcmd, 12)); // Убираем '[USES_PROXY]'
                    } elseif (stripos($ytcmd, 'env all_proxy=') !== false) {
                        $usesProxy = true;
                    }
                }
                if (($pos = strpos($line, '[yturl]')) !== false) {
                    $urltext = trim(substr($line, $pos + 8));
                }
            }
            fclose($handle);
        }

        if (empty($ytcmd)) {
            $_SESSION['errors'] = ["Команда не найдена в логе!"];
            return false;
        }

        // БЕЗОПАСНОСТЬ: Проверка, что команда содержит ожидаемый бинарник
        // (убираем префикс с env-переменными, если есть: "env VAR=value ... /path/to/yt-dlp").
        // Переменных может быть несколько (all_proxy + no_proxy/NO_PROXY) - снимаем все.
        $expectedExe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';
        $cmdToCheck = preg_replace('/^env\s+(?:[\w]+=\S+\s+)+/', '', $ytcmd);
        if (strpos($cmdToCheck, $expectedExe) !== 0) {
            $_SESSION['errors'] = ["Подозрительная команда в логе. Рестарт отменен"];
            error_log("[YTDL] Security: Command mismatch on restart.");
            return false;
        }

        // В сохранённой команде из лога ещё стоит замаскированный плейсхолдер
        // "env all_proxy=[SOCKS5_PROXY]" - убираем его перед (повторной) вставкой
        // настоящего прокси, иначе получим два вложенных вызова "env", и yt-dlp
        // получит в качестве прокси буквальную строку-плейсхолдер.
        $ytcmd = preg_replace('/^env\s+all_proxy=\S+\s+/', '', $ytcmd);

        // Если исходная задача использовала прокси ИЛИ нас просят принудительно добавить его
        // (как при авторетрее с гео-блоком) - вставляем его из текущего конфига
        if (($usesProxy || $forceUseProxy) && !empty($GLOBALS['config']['socks5'])) {
            $ytcmd = "env all_proxy=" . escapeshellarg($GLOBALS['config']['socks5']) . " " . $ytcmd;
            $usesProxy = true; // Отмечаем, что теперь используется прокси
        }

        // Точечное добавление куки при ретрее из-за приватности/возраста/подписки.
        // Вставляем флаг сразу после бинарника yt-dlp (а не в конец команды, после
        // уже подставленных URL) - так он гарантированно читается как опция, а не
        // как позиционный аргумент. stripos-проверка на случай повторного ретрея
        // с уже вставленными куками (форс не задваивает флаг).
        $cookiesFile = $GLOBALS['config']['youtubeCookiesFile'] ?? '';
        if ($forceUseCookies && !empty($cookiesFile) && is_readable($cookiesFile) && stripos($ytcmd, '--cookies ') === false) {
            $exePos = strpos($ytcmd, $expectedExe);
            if ($exePos !== false) {
                $insertPos = $exePos + strlen($expectedExe);
                $ytcmd = substr($ytcmd, 0, $insertPos) . " --cookies " . escapeshellarg($cookiesFile) . substr($ytcmd, $insertPos);
            }
        }

        $suffix = (strpos($fpid, "_a") !== false || strpos($file, "_a") !== false) ? "_a" : "";

        do {
            $fno = "job_" . uniqid() . $suffix;
        } while (file_exists("$logPath/$fno"));

        $fnp = str_replace("job_", "pid_", $fno);

        $ytcmd = preg_replace('/\s{2,}/', ' ', $ytcmd);
        $ytcmd = rtrim($ytcmd);

        // Используем exec вместо passthru, чтобы не выводить мусор в браузер
        $cmd = sprintf(
            'bash -c %s > %s/%s 2>&1 & echo $! > %s/%s',
            escapeshellarg($ytcmd),
            $logPath,
            $fno,
            $logPath,
            $fnp
        );

        exec($cmd);

        if (self::$bg_jobs_cache !== null) {
            self::$bg_jobs_cache++;
        }

        // Маскируем реальные учётные данные прокси перед сохранением команды на диск -
        // $ytcmd (использован выше для exec()) всё ещё содержит настоящее значение,
        // маскируется только сохраняемая копия - так же, как делает executeDownload().
        $ytcmd_masked = preg_replace('/env\s+all_proxy=\S+/', 'env all_proxy=[SOCKS5_PROXY]', $ytcmd);
        $proxyMarker = $usesProxy ? "[USES_PROXY] " : "";
        file_put_contents("$logPath/$fnp", $proxyMarker . $ytcmd_masked . "\n", FILE_APPEND);
        file_put_contents("$logPath/$fnp", $urltext . "\n", FILE_APPEND);

        return $fnp;
    }

    public static function clear_one_finished($fpid)
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        $fpid = basename($fpid);
        @unlink($GLOBALS['config']['logPath'] . '/' . $fpid);
    }

    public static function clear_finished()
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        foreach (glob($GLOBALS['config']['logPath'] . '/ytdl_*') as $file) {
            @unlink($file);
        }
    }

    private function check_requirements()
    {
        $this->check_output_folder();

        if (!empty($this->errors)) {
            $_SESSION['errors'] = $this->errors;
            return false;
        }

        return true;
    }

    private function is_valid_url($url)
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        // Только http/https - иначе yt-dlp можно скормить file://, ftp:// и прочие
        // схемы, дающие доступ к локальным путям сервера
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        return $scheme === 'http' || $scheme === 'https';
    }

    private function check_output_folder()
    {
        if (!is_dir($this->download_path)) {
            if (!mkdir($this->download_path, 0775, true)) {
                $this->errors[] = "Папка для сохранения загрузки не существует и не может быть создана!";
            }
        } else {
            if (!is_writable($this->download_path)) {
                $this->errors[] = "В папку загрузки невозможно записать!";
            }
        }
    }

    private function getUniqueFileName($prefix, $suffix, $path)
    {
        do {
            $uid = $prefix . uniqid() . $suffix;
        } while (file_exists($path . $uid));

        return $uid;
    }

    // Нормализованный хост URL (lowercase, без www). Пустой URL/битый парс -
    // возвращаем сам URL как ключ, чтобы такая ссылка стала отдельной группой.
    private function getHost($url)
    {
        $urlToParse = $url;
        if (!preg_match('/^https?:\/\//i', $urlToParse)) {
            $urlToParse = 'https://' . $urlToParse;
        }

        $hostname = strtolower(parse_url($urlToParse, PHP_URL_HOST) ?? '');
        $hostname = preg_replace('/^www\./i', '', $hostname);

        return $hostname !== '' ? $hostname : $url;
    }

    private function isDirectAccessDomain($url)
    {
        $hostname = $this->getHost($url);

        foreach (self::DIRECT_ACCESS_DOMAINS as $domain) {
            if ($hostname === $domain || str_ends_with($hostname, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeAudioFormat($format)
    {
        $allowed = [
            '--audio-format mp3 --audio-quality 0',
            '--audio-format mp3',
            '--audio-format wav',
            '--audio-format aac',
            '--audio-format flac',
            ''
        ];

        return in_array($format, $allowed) ? $format : '';
    }

    private function sanitizeDlFormat($format)
    {
        $allowed = ['top', 'worst', '4K', '1440p', '1080p', ''];
        return in_array($format, $allowed) ? $format : 'top';
    }

    private function do_download()
    {
        foreach ($this->dl_list as $onedownload) {
            if ($this->config["max_dl"] == -1) {
                $this->addOneDownload($onedownload);
            } elseif ($this->config["max_dl"] > 0) {
                if (self::background_jobs() < $this->config["max_dl"]) {
                    $this->addOneDownload($onedownload);
                } else {
                    if ($this->config["disableQueue"]) {
                        $this->errors[] = "Достигнут лимит одновременных загрузок. " . $onedownload['url'] . " не был загружен";
                    } else {
                        $this->addToQueue($onedownload);
                    }
                }
            } else {
                $this->errors[] = "Значение max_dl value в config.php указано неверно";
            }
        }
    }

    public function addOneDownload($onedownload)
    {
        $urls = array_filter(array_map('trim', explode('||', $onedownload['url'])));

        // Группируем ссылки по хосту. Один сайт - один процесс yt-dlp
        // (последовательно): иначе параллельные запросы к одному хосту через
        // общий прокси/IP ловят 429 (rate-limit, "Сайт оверлоуд"). Разные хосты
        // идут отдельными процессами - падение одного не роняет другой и качается
        // параллельно.
        $groups = [];
        foreach ($urls as $url) {
            $groups[$this->getHost($url)][] = $url;
        }

        foreach ($groups as $groupUrls) {
            $useProxy = !$this->isDirectAccessDomain($groupUrls[0]);

            if (count($groupUrls) === 1) {
                $this->executeDownload($onedownload, $groupUrls, $useProxy, false);
            } else {
                // Первую ссылку качаем сразу, без паузы - старт загрузки не ждёт.
                // Остальные того же хоста идут вторым процессом с паузой между
                // запросами, иначе хост отдаёт 429 на частые запросы с одного IP.
                $first = array_shift($groupUrls);
                $this->executeDownload($onedownload, [$first], $useProxy, false);
                $this->executeDownload($onedownload, $groupUrls, $useProxy, true);
            }
        }
    }

    private function executeDownload($onedownload, $urls, $useProxy, $paceRequests = false)
    {
        $suffix = "";
        $cmd = $this->config['youtubedlExe'];
        $cmd .= " --js-runtimes node";
        // Логгер скачиваний (LogPluginPP -> /var/log/yt_dlp.log) подключаем явно,
        // не полагаясь на автопоиск config/плагинов yt-dlp. --plugin-dirs указывает
        // на каталог, содержащий yt_dlp_plugins/ (плагин запечён в образ из logger.sh).
        // "default" обязателен: без него --plugin-dirs ЗАМЕНЯЕТ дефолтные пути, и
        // pip-плагины из site-packages (bgutil PO-token провайдер) не грузятся -
        // YouTube тогда режет бот-чеком. С "default" ищутся оба набора.
        $cmd .= " --plugin-dirs default";
        $cmd .= " --plugin-dirs " . escapeshellarg("/etc/yt-dlp/plugins/log_plugin");
        $cmd .= " --use-postprocessor LogPluginPP";
        $cmd .= " -o " . escapeshellarg($this->download_path . "/%(title)s_%(id)s.%(ext)s");
        $cmd .= " --restrict-filenames";

        $sanitizedFormat = $this->sanitizeDlFormat($onedownload['dl_format']);
        if ($sanitizedFormat === 'worst') {
            $cmd .= " -f worst";
        } else {
            // 'top' (по умолчанию): лучшее видео+аудио до maxVideoRes (config.php)
            // явный выбор качества (долгое нажатие на "Скачать") переопределяет потолок
            $explicitRes = ['4K' => 2160, '1440p' => 1440, '1080p' => 1080];
            if (isset($explicitRes[$sanitizedFormat])) {
                $maxRes = $explicitRes[$sanitizedFormat];
            } else {
                $maxRes = (int) ($this->config['maxVideoRes'] ?? 1080);
                if ($maxRes < 144 || $maxRes > 8640) {
                    $maxRes = 1080;
                }
            }
            $cmd .= " -S " . escapeshellarg("res:{$maxRes}") . " -f " . escapeshellarg('bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/bv*+ba/b');
        }

        if ($useProxy && !empty($this->config['socks5'])) {
            // googlevideo и подобные CDN часто отдают поток как один URL
            // с поддержкой Range, а не как фрагментированный манифест -
            // --http-chunk-size режет его на чанки через Range-запросы,
            // --concurrent-fragments качает их параллельно (без HLS/DASH-фрагментации).
            // 4 (не 8): скорость упирается в прокси, а не в число соединений,
            // а меньше параллельных Range-запросов с одного IP к CDN - меньше
            // шанс поймать 403 на чанк и вести себя заметно.
            $cmd .= " --concurrent-fragments 4 --http-chunk-size 5M";
            // no_proxy для localhost: запрос yt-dlp к серверу PO-токенов (bgutil на
            // 127.0.0.1:4416) не должен уходить в SOCKS5, иначе токен не получить.
            $cmd = "env all_proxy=" . escapeshellarg($this->config['socks5'])
                . " no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost " . $cmd;
        }

        if ($onedownload['audio_only']) {
            $cmd .= " -x";
            $sanitizedAudio = $this->sanitizeAudioFormat($onedownload['audio_format']);
            if (!empty($sanitizedAudio)) {
                $cmd .= " " . $sanitizedAudio;
            }
            $suffix = "_a";
        } else {
            $cmd .= " --merge-output-format mp4";
            $cmd .= " --remux-video mp4";
        }

        $cmd .= " --embed-thumbnail --embed-metadata";

        $isYoutube = false;
        $isYoutubeMulti = false;
        foreach ($urls as $url) {
            if (preg_match('/(youtube\.com|youtu\.be)/i', $url)) {
                $isYoutube = true;
                // Плейлист/канал/хэндл разворачивается в десятки роликов внутри
                // одного процесса yt-dlp - тогда нужен сон и между самими
                // загрузками, а не только между HTTP-запросами.
                if (preg_match('#[?&]list=|/playlist|/channel/|/@|/c/|/user/#i', $url)) {
                    $isYoutubeMulti = true;
                }
            }
        }
        if ($isYoutube) {
            $cmd .= " --sponsorblock-remove sponsor";
        }

        // Куки НЕ подключаются к обычной загрузке: аккаунт-based PO-токен запросы
        // (tv_downgraded/web_safari) требуют Data Sync ID, которого без реальной
        // необходимости в куках взяться неоткуда - только лишние WARNING в логе
        // и лишняя аккаунт-привязанная активность на пустом месте. Куки
        // подключаются точечно, только повторной попыткой, если первая упёрлась
        // именно в приватность/возраст/подписку - см. autoRetryWithCookiesIfNeeded().

        // Пауза между запросами - защита от 429/бот-чека YouTube (частые
        // обращения к player API с одного прокси-IP). Сон виден юзеру как статус
        // "Пауза N сек" (см. разбор "Sleeping ... seconds" выше), поэтому вешаем
        // его только там, где запросов реально много:
        // - Плейлист/канал YouTube приходит одним URL (ветка count===1 в
        //   addOneDownload, paceRequests=false), но yt-dlp разворачивает его в
        //   десятки роликов подряд - без пауз это залп extraction-запросов с
        //   одного IP, прямой путь к 429. Пейсим и запросы, и сами загрузки.
        // - "Хвост" мультизагрузки одного хоста ($paceRequests) - тот же случай.
        // Одиночный ролик делает пару запросов, риск бана мизерный - сон не
        // навешиваем, иначе юзер видит частокол "Пауза N сек" на пустом месте.
        if ($isYoutubeMulti) {
            $cmd .= " --sleep-requests 1.5 --sleep-interval 3 --max-sleep-interval 8";
        } elseif ($paceRequests) {
            $cmd .= " --sleep-interval 3 --max-sleep-interval 8 --sleep-requests 1";
        }

        $fno = $this->getUniqueFileName("job_", $suffix, $this->config['logPath'] . "/");
        $fnp = str_replace("job_", "pid_", $fno);

        $urltext = "";
        foreach ($urls as $url) {
            $cmd .= " " . escapeshellarg($url);
            $urltext .= $url . ",";
        }
        $urltext = trim($urltext, ",");

        $cmd .= " --ignore-errors";

        // Перевод озвучки через Яндекс-VOT. Только для видео (не для -x аудио).
        // yt-dlp качает ролик как обычно (прокси/директ уже в $cmd), затем vot-cli
        // тянет переведённую дорожку по URL, mux_translated.sh вклеивает её.
        // Обёртка bash -c: yt-dlp остаётся в cmdline процесса, liveness-проверка
        // в get_current_background_jobs() продолжает видеть задачу как yt-dlp.
        if (!empty($onedownload['translate']) && empty($onedownload['audio_only'])) {
            $votTmp = $this->config['logPath'] . "/vot_" . uniqid();
            $pathFile = $votTmp . "/vpath";

            // Путь скачанного файла yt-dlp пишет в $pathFile - оттуда его берёт mux
            $ytPart = $cmd . " --print-to-file " . escapeshellarg('after_move:filepath') . " " . escapeshellarg($pathFile);

            // Приводим ссылку к виду, который понимает Яндекс-VOT. yt-dlp качает
            // и оригинальную форму - правим URL только для vot-cli.
            $votUrl = $urls[0];
            if (preg_match('#youtube\.com/shorts/([\w-]+)#i', $votUrl, $ym)) {
                // Shorts -> канонический watch?v=ID
                $votUrl = 'https://www.youtube.com/watch?v=' . $ym[1];
            } elseif (preg_match('#(?:vkvideo\.ru|vk\.ru|vkvideo\.com)/(video-?[\d_]+)#i', $votUrl, $vm)) {
                // Новый домен VK -> привычный vk.com/video...
                $votUrl = 'https://vk.com/' . $vm[1];
            }
            // Яндекс сам тянет ролик со своих серверов - vot-cli идёт без прокси
            $votPart = "vot-cli --reslang=ru --output=" . escapeshellarg($votTmp) . " " . escapeshellarg($votUrl);
            $muxPart = "bash /mux_translated.sh \"\$(cat " . escapeshellarg($pathFile) . ")\" "
                . escapeshellarg($votTmp) . " " . escapeshellarg($this->download_path);

            // Параллельно: Яндекс переводит ролик у себя на серверах и не ждёт наш
            // файл, поэтому vot-cli стартует ОДНОВРЕМЕННО с yt-dlp - долгая обработка
            // Яндекса идёт во время скачивания, а не плюсом к нему. Ждём оба процесса,
            // mux запускаем только если оба успешны; иначе остаётся скачанное видео
            // без перевода (мягкая деградация, как было с &&).
            $inner = "mkdir -p " . escapeshellarg($votTmp)
                . ' ; ' . $ytPart . ' & ytpid=$!'
                . ' ; ' . $votPart . ' & votpid=$!'
                . ' ; wait $ytpid ; ytrc=$?'
                . ' ; wait $votpid ; votrc=$?'
                . ' ; if [ $ytrc -eq 0 ] && [ $votrc -eq 0 ]; then ' . $muxPart . ' ; fi'
                . ' ; rm -rf ' . escapeshellarg($votTmp);

            $cmd = "bash -c " . escapeshellarg($inner);
        }

        $logcmd = $cmd;
        $cmd .= " > " . escapeshellarg($this->config['logPath'] . "/" . $fno) . " 2>&1 & echo $! > " . escapeshellarg($this->config['logPath'] . "/" . $fnp);

        // putenv не меняет саму команду/строку лога - просто передаёт IP плагину
        // LogPluginPP через окружение дочернего процесса, не задевая restart-парсинг
        putenv("CLIENT_IP=" . ($onedownload['client_ip'] ?? 'unknown'));
        exec($cmd);

        // Запущен новый процесс - учитываем в кэше (pid-файл пишется асинхронно,
        // ре-glob мог бы его не увидеть); держим счётчик в согласии с реальностью
        if (self::$bg_jobs_cache !== null) {
            self::$bg_jobs_cache++;
        }

        // Сохраняем команду с замаскированным прокси: учётные данные заменяются
        // плейсхолдером, чтобы в логах было видно, что прокси использовался, но без пароля
        $logcmd_masked = preg_replace('/env\s+all_proxy=[^\s]+/', 'env all_proxy=[SOCKS5_PROXY]', $logcmd);
        $proxyMarker = ($useProxy && !empty($this->config['socks5'])) ? "[USES_PROXY] " : "";
        file_put_contents($this->config['logPath'] . "/" . $fnp, $proxyMarker . $logcmd_masked . "\n", FILE_APPEND);
        file_put_contents($this->config['logPath'] . "/" . $fnp, $urltext . "\n", FILE_APPEND);
    }

    public function process_queue()
    {
        $queue_file = $this->config['logPath'] . "/dl_queue";
        if (!file_exists($queue_file)) return;

        // Блокировка файла очереди для атомарности
        $handle = fopen($queue_file, "c+");
        if (!$handle) return;

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        $currently_running = self::background_jobs();
        $remaining_urls = [];
        $corrupt_queue = false;
        $newDownloads = [];

        while (($line = fgets($handle)) !== false) {
            if (trim($line) === "") continue;

            if (substr($line, 0, 7) !== "queueid") {
                $corrupt_queue = true;
                break;
            }

            $parts = explode("=", $line, 2);
            if (count($parts) < 2) continue;

            $urlData = $parts[1];
            $urlParts = explode(">", $urlData);

            $rawUrl = urldecode(trim($urlParts[0] ?? ''));
            if (!$this->is_valid_url($rawUrl)) {
                $this->errors[] = $urlData . " не верный URL, удаляю из списка очереди";
                continue;
            }

            if ($currently_running < $this->config["max_dl"]) {
                $newDownloads[] = array(
                    'url' => $rawUrl,
                    'dl_format' => $urlParts[1] ?? '',
                    'audio_only' => $urlParts[2] ?? '',
                    'audio_format' => $urlParts[3] ?? '',
                    'client_ip' => trim($urlParts[4] ?? 'unknown'),
                    'translate' => trim($urlParts[5] ?? '')
                );
                $currently_running++;
            } else {
                $remaining_urls[] = $line;
            }
        }

        ftruncate($handle, 0);
        rewind($handle);

        foreach ($remaining_urls as $oneline) {
            fwrite($handle, $oneline);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($corrupt_queue) {
            @unlink($queue_file);
            $this->errors[] = "Файл повредился либо был удален";
        }

        // Запуск новых загрузок из очереди
        if (!empty($newDownloads)) {
            $this->dl_list = $newDownloads;
            $this->do_download();
        }

        if (!empty($this->errors)) {
            $_SESSION['errors'] = $this->errors;
        }
    }

    public function addToQueue($onedownload)
    {
        $queue_file = $this->config['logPath'] . "/dl_queue";
        $clientIp = $onedownload['client_ip'] ?? 'unknown';
        $translate = !empty($onedownload['translate']) ? '1' : '';
        $fcontent = "queueid" . uniqid() . "=" . urlencode($onedownload['url']) . ">" . $onedownload['dl_format'] . ">" . $onedownload['audio_only'] . ">" . $onedownload['audio_format'] . ">" . $clientIp . ">" . $translate . "\n";

        // LOCK_EX важен здесь
        file_put_contents($queue_file, $fcontent, FILE_APPEND | LOCK_EX);
    }

    public static function remove_queued_job($qid)
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        $queue_file = $GLOBALS['config']['logPath'] . "/dl_queue";
        if (!file_exists($queue_file)) return;

        $handle = fopen($queue_file, "c+");
        if (!$handle) return;

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        $remaining_urls = [];
        $corrupt_queue = false;

        while (($line = fgets($handle)) !== false) {
            if (trim($line) === "") continue;

            if (substr($line, 0, 7) !== "queueid") {
                $corrupt_queue = true;
                break;
            }

            $pid = substr($line, 0, strpos($line, "="));

            if ($pid !== $qid) {
                $remaining_urls[] = $line;
            }
        }

        ftruncate($handle, 0);
        rewind($handle);

        foreach ($remaining_urls as $oneline) {
            fwrite($handle, $oneline);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($corrupt_queue) {
            @unlink($queue_file);
            $_SESSION['errors'] = ["Файл очереди повредился либо был удален."];
            return;
        }

        if (count($remaining_urls) === 0) {
            @unlink($queue_file);
        }
    }

    public static function remove_all_queued_jobs()
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        $queue_file = $GLOBALS['config']['logPath'] . "/dl_queue";
        if (file_exists($queue_file)) {
            @unlink($queue_file);
        }
    }
}

?>