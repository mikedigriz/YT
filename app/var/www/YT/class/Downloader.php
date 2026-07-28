<?php

class Downloader
{
    private $dl_list = [];
    private $errors = [];
    private $download_path = "";
    private $config = [];

    // Логи короче порога читаются целиком; длиннее - только голова (site/playlist) и хвост (текущий статус).
    private const LOG_HEAD_TAIL_THRESHOLD = 65536;
    private const LOG_HEAD_BYTES = 4096;
    private const LOG_TAIL_BYTES = 65536;

    // Домены с прямым доступом (без прокси) - иностранные прокси их часто блокируют или тормозят.
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
                $urls = explode('||', $onedownload['url']);
                $hasNonEmptyUrl = false;
                foreach ($urls as $url) {
                    $url = trim($url);
                    if (empty($url)) continue;
                    $hasNonEmptyUrl = true;
                    if (!$this->is_valid_url($url)) {
                        $this->errors[] = "«" . $url . "» ты в порядке? Поправь ссыль, ну че ты!";
                    }
                }
                // Ввод вида "||" проходит !empty() в index.php, но после trim() все сегменты пустые.
                if (!$hasNonEmptyUrl) {
                    $this->errors[] = "Пустая ссылка - нечего качать";
                }
            }

            if (!empty($this->errors)) {
                $_SESSION['errors'] = $this->errors;
                return;
            }

            $this->do_download();

            // dispatchGroup() может добавить ошибку (max_dl исчерпан, битый конфиг) - флашим, иначе не дойдёт до юзера.
            if (!empty($this->errors)) {
                $_SESSION['errors'] = $this->errors;
            }
        }
    }

    // Кэш числа фоновых задач на запрос - background_jobs() зовётся многократно, glob+/proc дорогие.
    private static $bg_jobs_cache = null;

    // Один проход по logPath вместо трёх отдельных сканов. Без static-кэша - PHP-FPM воркер живёт много запросов, кэш отдавал бы устаревший список. index.php строит $fileList раз за запрос и передаёт всем трём методам.
    public static function scanLogPath(): ?array
    {
        if (!isset($GLOBALS['config']['logPath']) || !is_dir($GLOBALS['config']['logPath'])) {
            return null;
        }

        $fileList = ['pid' => [], 'ytdl' => []];
        $dir = new DirectoryIterator($GLOBALS['config']['logPath']);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isFile()) continue;
            $name = $fileinfo->getFilename();
            if (strpos($name, 'pid_') === 0) {
                $fileList['pid'][] = $fileinfo->getPathname();
            } elseif (strpos($name, 'ytdl_') === 0) {
                $fileList['ytdl'][] = $fileinfo->getPathname();
            }
        }

        return $fileList;
    }

    // $fileList - опционально, ['pid' => [...путей]] из scanLogPath(). null -
    // сканирует /pid_* сам (вызовы вне ?jobs, где единого скана на запрос нет).
    public static function background_jobs(?array $fileList = null)
    {
        if (self::$bg_jobs_cache !== null) {
            return self::$bg_jobs_cache;
        }

        if (!function_exists('shell_exec') || !isset($GLOBALS['config']['logPath'])) {
            return 0;
        }

        $count = 0;
        $youtubedlExe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';
        $pidFiles = $fileList['pid'] ?? glob($GLOBALS['config']['logPath'] . '/pid_*');

        foreach ($pidFiles as $pidFile) {
            $content = @file_get_contents($pidFile);
            if ($content === false) continue;

            $jpid = trim(explode("\n", $content)[0] ?? '');

            if (empty($jpid) || !file_exists("/proc/$jpid")) {
                continue;
            }

            // PID-reuse guard: ОС могла отдать номер другому процессу до уборки в get_current_background_jobs(). Файл не трогаем - тут только подсчёт.
            $pidcmd = @file_get_contents('/proc/' . $jpid . '/cmdline');
            if ($pidcmd !== false && strpos($pidcmd, $youtubedlExe) === false) {
                continue;
            }

            $count++;
        }

        self::$bg_jobs_cache = $count;
        return $count;
    }

    // $fileList по ссылке: если задача завершается прямо тут, finalize_job_log() переименовывает job_*->ytdl_*, а старый скан этого не видел. Без обновления по ссылке get_finished_background_jobs() в этом же запросе использовал бы устаревший список - задача пропала бы и из активных, и из finished до следующего запроса.
    public static function get_current_background_jobs(?array &$fileList = null)
    {
        if (!isset($GLOBALS['config']['logPath']) || !is_dir($GLOBALS['config']['logPath'])) {
            return [];
        }

        $bjs = [];
        $logPath = $GLOBALS['config']['logPath'];
        $youtubedlExe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';

        $pidFiles = $fileList['pid'] ?? null;
        if ($pidFiles === null) {
            $pidFiles = [];
            foreach (new DirectoryIterator($logPath) as $fileinfo) {
                if (!$fileinfo->isDot() && $fileinfo->isFile() && strpos($fileinfo->getFilename(), "pid_") === 0) {
                    $pidFiles[] = $fileinfo->getPathname();
                }
            }
        }

        foreach ($pidFiles as $pidFile) {
            {
                $pidBasename = basename($pidFile);
                $outfile = $logPath . "/" . str_replace("pid_", "job_", $pidBasename);
                $completefile = $logPath . "/" . str_replace("pid_", "ytdl_", $pidBasename);

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
                $clientip = trim($jpid_parts[3] ?? '');

                if (!empty($jpid) && !file_exists("/proc/" . $jpid)) {
                    @unlink($pidFile);
                    // Сбрасываем кэш счётчика - иначе canSpawnRetry() увидит устаревшее число и ретрей упрётся в max_dl из-за уже мёртвой задачи.
                    self::$bg_jobs_cache = null;
                    self::finalize_job_log($outfile, $completefile, $ytcmd, $urltext, $clientip);
                    if ($fileList !== null) {
                        $fileList['ytdl'][] = $completefile;
                    }
                    // Авторетрей через прокси при гео-блоке/403 для прямых доменов
                    $retryPid = self::autoRetryIfNeeded($completefile);
                    $retryStatus = "Первая попытка не прошла, пробую через прокси";
                    if ($retryPid === null) {
                        // Авторетрей с куками YouTube при бот-чеке/приватности/возрасте
                        $retryPid = self::autoRetryWithCookiesIfNeeded($completefile);
                        $retryStatus = "Обычный способ заблокирован, пробую с куками аккаунта";
                    }

                    // Pid-файл ретрея пишется асинхронно и в этот ответ ?jobs не попадает (каталог уже проитерирован). Отдаём синтетическую строку с реальным pid ретрея, чтобы фронтенд не ушёл в медленный опрос - следующий тик заменит её настоящей задачей бесшовно.
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

                if (!empty($jpid)) {
                    $pidcmd = @file_get_contents('/proc/' . $jpid . '/cmdline');
                    if ($pidcmd !== false && strpos($pidcmd, $youtubedlExe) === false) {
                        @unlink($pidFile);
                        self::finalize_job_log($outfile, $completefile, $ytcmd, $urltext, $clientip);
                        if ($fileList !== null) {
                            $fileList['ytdl'][] = $completefile;
                        }
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
                $isaudio = (strpos($pidBasename, "_a") !== false);
                $listpos = "";
                $playlist = "";
                // Маркеры перевода (VOT) ищем только у translate-задач: их признак - vot-cli в сохранённой команде.
                // Без этой привязки "frame=" от обычного ffmpeg (Twitch и любой другой HLS/live идёт через него)
                // ложно включал фазу микса, и юзер видел "Вклеиваю русскую дорожку" на закачке без всякого перевода.
                $isTranslateJob = strpos($ytcmd, 'vot-cli') !== false;
                $votPhase = false;
                $muxPhase = false;
                $ffmpegProgress = null;

                // Большие логи читаем частично: голова для site/playlist (печатаются раз в начале), хвост для текущего статуса. Цена тика O(головы+хвоста), не растёт с логом.
                $outSize = @filesize($outfile);
                if ($outSize !== false && $outSize > self::LOG_HEAD_TAIL_THRESHOLD) {
                    $head = fread($handle, self::LOG_HEAD_BYTES);
                    if ($head !== false && $head !== '') {
                        foreach (explode("\n", $head) as $headLine) {
                            self::scanForSiteAndPlaylist($headLine . "\n", $siteset, $site, $playlist);
                        }
                    }

                    $tailStart = max(0, $outSize - self::LOG_TAIL_BYTES);
                    fseek($handle, $tailStart);
                    $tail = stream_get_contents($handle);
                    $tailLines = ($tail === false) ? [] : explode("\n", $tail);
                    if ($tailStart > 0 && count($tailLines) > 0) {
                        // Первая строка хвоста обрублена fseek не по границе - выбрасываем.
                        array_shift($tailLines);
                    }

                    foreach ($tailLines as $tailLine) {
                        self::scanForCurrentStatus($tailLine . "\n", $listpos, $votPhase, $muxPhase, $lastline, $verylastline, $filename, $ffmpegProgress);
                    }
                } else {
                    while (($line = fgets($handle)) !== false) {
                        self::scanForSiteAndPlaylist($line, $siteset, $site, $playlist);
                        self::scanForCurrentStatus($line, $listpos, $votPhase, $muxPhase, $lastline, $verylastline, $filename, $ffmpegProgress);
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

                // Прогресс ffmpeg (трансляции) важнее "Собираю информацию"/"В Процессе", но слабее фаз перевода ниже:
                // там ffmpeg занят миксом дорожек, а не приёмом потока.
                if ($ffmpegProgress !== null && !$isTranslateJob) {
                    $lastline = "Записываю трансляцию: " . self::formatBytes($ffmpegProgress['bytes'])
                        . ", " . $ffmpegProgress['time'];
                }

                // Фаза перевода перекрывает всё - vot/ffmpeg не пишут проценты, иначе висело бы вечное "В Процессе".
                if ($muxPhase && $isTranslateJob) {
                    $lastline = "Вклеиваю русскую дорожку в видео, почти готово";
                } elseif ($votPhase && $isTranslateJob) {
                    // Таймер от старта задачи (mtime pid-файла) - vot-cli стартует вместе со скачиванием.
                    $elapsed = max(0, time() - (int) @filemtime($pidFile));
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
                    'pid' => $pidBasename,
                    'url' => $urltext
                );
            }
        }

        return $bjs;
    }

    // Проверка, переиспользуемая ли ошибка (сетевая временная ошибка vs постоянная проблема контента)
    private const RETRYABLE_KEYWORDS = [
        'Тайм-аут', 'ETIMEDOUT', 'Connection timed out', 'Connection refused',
        'Соединение оборвалось', 'Сеть недоступна', 'Network unreachable',
        'HTTP Error 429', 'Too Many Requests', 'HTTP Error 503', 'Service Unavailable',
        'Service temporarily unavailable', 'DNS не резолвил', "couldn't resolve host",
        'Failed to resolve', 'DNS error', 'Name or service not known', 'Temporary failure',
    ];

    private static ?string $retryablePattern = null;

    private static function isRetryableError($status)
    {
        if (self::$retryablePattern === null) {
            self::$retryablePattern = '/(' . implode('|', array_map(
                fn($k) => preg_quote($k, '/'),
                self::RETRYABLE_KEYWORDS
            )) . ')/i';
        }
        return (bool) preg_match(self::$retryablePattern, $status);
    }

    // Авторетрей через прокси при гео-блоке/403 для прямых доменов. Возвращает имя нового pid_-файла или null.
    private static function autoRetryIfNeeded($completefile)
    {
        if (!file_exists($completefile)) {
            return null;
        }

        $log_content = @file_get_contents($completefile);
        if ($log_content === false) {
            return null;
        }

        if (strpos($log_content, '[RETRY_ATTEMPTED:') !== false) {
            return null;
        }

        $jobstatus = self::parseYtDlpError($log_content);

        if (!self::isRetryableError($jobstatus)) {
            return null;
        }

        // Лимит max_dl добит другими задачами - маркер не пишем, задача остаётся неудачной для ручного рестарта.
        if (!self::canSpawnRetry()) {
            return null;
        }

        $retry_marker = "[RETRY_ATTEMPTED:" . time() . "] Авторетрей через прокси\n";
        @file_put_contents($completefile, $retry_marker, FILE_APPEND);

        // Ищет лог по имени готового файла (ytdl_*), не по уже удалённому pid_*.
        $newpid = self::restart_download(basename($completefile), true);
        return $newpid ?: null;
    }

    // В отличие от isRetryableError() - признак закрытого контента, не временный сбой сети.
    // Бот-чек тоже в списке: настоящие куки обходят сбой bgutil (не смог получить PO-токен) независимо от его здоровья.
    private const COOKIES_RETRY_KEYWORDS = [
        'Приватное видео', '18+ контент', 'Нужна авторизация', 'Members-only', 'принял нас за бота',
    ];

    private static ?string $cookiesRetryPattern = null;

    private static function needsCookiesRetry($status)
    {
        if (self::$cookiesRetryPattern === null) {
            self::$cookiesRetryPattern = '/(' . implode('|', array_map(
                fn($k) => preg_quote($k, '/'),
                self::COOKIES_RETRY_KEYWORDS
            )) . ')/i';
        }
        return (bool) preg_match(self::$cookiesRetryPattern, $status);
    }

    // Определяет "сайт кук" по URL - используется и для выбора файла кук, и для выбора сценария подключения (retry vs сразу). null - сайт без своих кук, куки не трогаем вообще.
    private static function detectCookiesSite($url)
    {
        if (preg_match('/(youtube\.com|youtu\.be)/i', $url)) {
            return 'youtube';
        }
        if (preg_match('/instagram\.com/i', $url)) {
            return 'instagram';
        }
        return null;
    }

    // Хосты, для которых Яндекс-VOT реально умеет перевод. Всё остальное (в первую очередь Twitch и любые
    // live-трансляции) переводить нечем: vot-cli либо сразу отваливается, либо висит до таймаута, пока
    // yt-dlp ждёт его в "wait", и поток не доезжает до пользователя. Такие ссылки качаем обычной веткой.
    private const VOT_SUPPORTED_DOMAINS = [
        'youtube.com', 'youtu.be', 'vk.com', 'vkvideo.ru', 'vk.ru', 'vkvideo.com',
        'rutube.ru', 'ok.ru', 'my.mail.ru', 'vimeo.com', 'bilibili.com', 'coub.com'
    ];

    // Живая трансляция не переводится даже на поддерживаемом хосте - у ролика нет конечной дорожки, которую мог бы взять Яндекс.
    private static function isLiveStreamUrl($url)
    {
        return (bool) preg_match('#(?:twitch\.tv|/live/|/live\b|[?&]live=)#i', $url);
    }

    private function translationSupported(array $urls)
    {
        foreach ($urls as $url) {
            if (self::isLiveStreamUrl($url)) {
                return false;
            }
            $host = $this->getHost($url);
            if ($host === '') {
                return false;
            }
            $ok = false;
            foreach (self::VOT_SUPPORTED_DOMAINS as $domain) {
                if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return !empty($urls);
    }

    // Путь к файлу кук для сайта из detectCookiesSite() - пусто, если сайт неизвестен или ключ не задан в конфиге.
    private static function cookiesFileForSite($site)
    {
        switch ($site) {
            case 'youtube':
                return $GLOBALS['config']['youtubeCookiesFile'] ?? '';
            case 'instagram':
                return $GLOBALS['config']['instagramCookiesFile'] ?? '';
            default:
                return '';
        }
    }

    // Куки = полный доступ к аккаунту, файл должен быть 600/400. Если права открыты, пытаемся chmod 600 сами (владелец www-data), отказываем только если не удалось (не владелец / ФС без unix-прав, напр. Windows bind-mount).
    private static function cookiesFileUsable($cookiesFile)
    {
        if (empty($cookiesFile) || !is_readable($cookiesFile)) {
            return false;
        }
        $perms = @fileperms($cookiesFile);
        if ($perms === false) {
            return false;
        }
        // 0o077 - биты группы/остальных, у файла кук их быть не должно.
        if (($perms & 0077) !== 0) {
            // clearstatcache - fileperms кэширует
            @chmod($cookiesFile, 0600);
            clearstatcache(true, $cookiesFile);
            $perms = @fileperms($cookiesFile);
            if ($perms === false || ($perms & 0077) !== 0) {
                error_log("[YTDL] Security: файл кук " . $cookiesFile . " доступен группе/остальным ("
                    . ($perms === false ? '?' : substr(sprintf('%o', $perms), -4))
                    . ") и закрыть его до 600 не удалось, использовать не буду. Сделай chmod 600 вручную.");
                return false;
            }
        }
        return true;
    }

    // Показать юзеру причину в статусе задачи, а не молчать при бот-чеке с настроенными куками. $site - detectCookiesSite() по URL задачи, по умолчанию 'youtube' (исторический вызов без явного сайта).
    private static function cookiesConfiguredButUnusable($site = 'youtube')
    {
        $f = self::cookiesFileForSite($site);
        return !empty($f) && !self::cookiesFileUsable($f);
    }

    // Смерть задачи уже освободила слот (pid_ снят до вызова), ретрей его законно занимает. Отказываем, только если лимит добит другими - иначе ретрей + поднятая из очереди задача дали бы два процесса на один IP.
    private static function canSpawnRetry()
    {
        $max = $GLOBALS['config']['max_dl'] ?? 3;
        if ($max <= 0) {
            return true; // -1 (без лимита) или невалидное значение - как в do_download
        }
        return self::background_jobs() < $max;
    }

    // Первая попытка всегда без кук для YouTube (см. Data Sync ID в executeDownload), куки подключаются только если реально понадобились. needsCookiesRetry() матчит и по общим фразам yt-dlp ("login required"), которые вылетают и у не-YouTube экстракторов (напр. Instagram) - поэтому файл кук выбираем по URL из лога, а не хардкодом на YouTube, иначе чужому сайту подставился бы youtube_cookies.txt.
    private static function autoRetryWithCookiesIfNeeded($completefile)
    {
        if (!file_exists($completefile)) {
            return null;
        }

        $log_content = @file_get_contents($completefile);
        if ($log_content === false) {
            return null;
        }

        $jobUrl = '';
        if (preg_match('/^\[yturl\]\s*(.+)$/m', $log_content, $urlMatch)) {
            $jobUrl = trim(explode(',', $urlMatch[1])[0]);
        }
        $site = self::detectCookiesSite($jobUrl);
        $cookiesFile = self::cookiesFileForSite($site);
        if (!self::cookiesFileUsable($cookiesFile)) {
            return null;
        }

        // Общий маркер с проксийным ретреем - не более одной авто-попытки на задачу
        if (strpos($log_content, '[RETRY_ATTEMPTED:') !== false) {
            return null;
        }

        // Проверяем только строку [ytcmd], не весь лог - текст ошибки бот-чека сам советует "--cookies", ложно срабатывало бы.
        if (preg_match('/^\[ytcmd\].*--cookies\s/m', $log_content)) {
            return null;
        }

        $jobstatus = self::parseYtDlpError($log_content, $site);
        if (!self::needsCookiesRetry($jobstatus)) {
            return null;
        }

        // Маркер не пишем - задача остаётся доступной для ручного рестарта.
        if (!self::canSpawnRetry()) {
            return null;
        }

        $retry_marker = "[RETRY_ATTEMPTED:" . time() . "] Авторетрей с куками (" . ($site ?? '?') . ")\n";
        @file_put_contents($completefile, $retry_marker, FILE_APPEND);

        // Удаляем старый лог только при успехе рестарта - иначе задача осталась бы без следа при провале старта.
        $newpid = self::restart_download(basename($completefile), false, true);
        if ($newpid) {
            @unlink($completefile);
            return $newpid;
        }
        return null;
    }

    private static function finalize_job_log($outfile, $completefile, $ytcmd, $urltext, $clientip = '')
    {
        if (!file_exists($outfile)) return;

        $content = file_get_contents($outfile);
        if (!empty($content) && substr($content, -1) !== "\n") {
            file_put_contents($outfile, "\n", FILE_APPEND);
        }

        rename($outfile, $completefile);
        self::salvagePartialVideo($completefile);
        // Убираем маркер [USES_PROXY], чтобы файл был чистым
        $ytcmd = preg_replace('/^\[USES_PROXY\]\s+/', '', $ytcmd);
        file_put_contents($completefile, "[ytcmd] " . $ytcmd . "\n", FILE_APPEND);
        file_put_contents($completefile, "[yturl] " . $urltext . "\n", FILE_APPEND);
        // IP уже провалидирован FILTER_VALIDATE_IP. Нужен restart_download'у - putenv живёт в FPM-воркере между запросами, реальный IP иначе теряется.
        if ($clientip !== '') {
            file_put_contents($completefile, "[ytip] " . $clientip . "\n", FILE_APPEND);
        }
    }

    // Обрезок меньше этого размера спасать бессмысленно - там нет ни одного кадра.
    private const SALVAGE_MIN_BYTES = 262144;
    private const SALVAGE_EXTENSIONS = ['mp4', 'mkv', 'ts', 'webm'];

    // Оборванная запись остаётся в outputFolder как "<имя>.mp4.part" и не видна во вкладке "Видео".
    // Файл фрагментированный (см. --downloader-args в executeDownload), то есть играется как есть,
    // поэтому достаточно снять суффикс .part. Зовётся из finalize_job_log - покрывает и кнопку "Стоп",
    // и падение yt-dlp, и обрыв самой трансляции. При обычной успешной загрузке .part уже нет - no-op.
    private static function salvagePartialVideo(string $logFile): void
    {
        $folder = $GLOBALS['config']['outputFolder'] ?? '';
        $realFolder = ($folder === '') ? false : realpath($folder);
        if ($realFolder === false) {
            return;
        }

        $handle = @fopen($logFile, 'r');
        if (!$handle) {
            return;
        }

        // Спасаем только запись через ffmpeg (трансляции): её .part фрагментированный и играется обрезком.
        // Обычная HTTP-загрузка обрывается посреди неполного mp4 - переименовать его значило бы подсунуть
        // человеку заведомо битый файл, поэтому такие .part оставляем как есть.
        $viaFfmpeg = false;
        $targets = [];
        while (($line = fgets($handle)) !== false) {
            if (!$viaFfmpeg && strpos($line, 'frame=') !== false && strpos($line, 'size=') !== false) {
                $viaFfmpeg = true;
            }
            $pos = strpos($line, 'Destination:');
            if ($pos === false) {
                continue;
            }
            $path = trim(substr($line, $pos + 12));
            if ($path !== '') {
                $targets[$path] = true;
            }
        }
        fclose($handle);

        if (!$viaFfmpeg) {
            return;
        }

        foreach (array_keys($targets) as $target) {
            if (!in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), self::SALVAGE_EXTENSIONS, true)) {
                continue;
            }
            $realPart = realpath($target . '.part');
            if ($realPart === false || strpos($realPart, $realFolder . '/') !== 0) {
                continue;
            }
            if (@filesize($realPart) < self::SALVAGE_MIN_BYTES || file_exists($target)) {
                continue;
            }
            @rename($realPart, $target);
        }
    }

    // Служебные теги, не являющиеся именем сайта - vot-cli пишет в тот же лог параллельно с yt-dlp.
    private const NON_EXTRACTOR_TAGS = [
        'download', 'info', 'debug', 'vot', 'ffmpeg', 'merger', 'metadata',
        'extractaudio', 'embedthumbnail', 'videoconvertor', 'sponsorblock',
        'ytcmd', 'yturl', 'ytip', 'retry_attempted',
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

    private static function formatBytes(float $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, '.', '') . " ГБ";
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, '.', '') . " МБ";
        }
        return number_format($bytes / 1024, 0, '.', '') . " КБ";
    }

    // Site и заголовок плейлиста печатаются раз в начале лога - для больших логов ищутся только в голове, не в хвосте.
    private static function scanForSiteAndPlaylist(string $line, bool &$siteset, string &$site, string &$playlist): void
    {
        if (!$siteset) {
            $detected = self::detectSite($line);
            if ($detected !== null) {
                $siteset = true;
                $site = $detected;
            }
        }

        // "[extractor] Playlist TITLE: Downloading N items"
        if (preg_match('/\] Playlist (.+): Downloading \d+ items?\s*$/', $line, $pm)) {
            $playlist = trim($pm[1]) . "<br />";
        }
    }

    // Текущее состояние задачи. Поля либо "последний победил" (filename/lastline/verylastline/listpos), либо флаг, повторяющийся и в хвосте большого лога (votPhase/muxPhase) - поэтому ищется только в хвосте, не в голове.
    private static function scanForCurrentStatus(string $line, string &$listpos, bool &$votPhase, bool &$muxPhase, string &$lastline, string &$verylastline, string &$filename, ?array &$ffmpegProgress = null): void
    {
        // Живые трансляции (Twitch и любой HLS без известной длительности) yt-dlp тянет через ffmpeg,
        // а тот не печатает процентов - только свой прогресс "size= ... time= ...". Без этого статус
        // намертво застревал на "Собираю информацию", хотя файл на диске рос.
        // Строки ffmpeg разделены \r, поэтому ищем все вхождения в куске и берём последнее.
        if (preg_match_all('/size=\s*(\d+(?:\.\d+)?)\s*(k|K|M|G)i?B.*?time=\s*(\d+:\d{2}:\d{2})/', $line, $fm, PREG_SET_ORDER)) {
            $last = end($fm);
            $mult = ['k' => 1024, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
            $ffmpegProgress = [
                'bytes' => (float) $last[1] * $mult[$last[2]],
                'time' => $last[3],
            ];
        }

        // yt-dlp печатает по-английски: "[download] Downloading item N of M"
        if (preg_match('/\[download\] Downloading item (.+)/', $line, $lm)) {
            $listpos = "(" . trim($lm[1]) . ")";
        }

        // Скачивание кончилось (--print-to-file). Фазу "перевожу" включаем по этому маркеру, не по раннему баннеру vot-cli - иначе юзер видел бы её раньше времени вместо процентов закачки.
        if (strpos($line, "Writing '%(filepath)s'") !== false) {
            $votPhase = true;
        }
        // mux_translated.sh печатает "[vot] ...", а сырой ffmpeg-микс - "frame="
        if (strpos($line, '[vot]') !== false || strpos($line, 'frame=') !== false) {
            $muxPhase = true;
        }

        if (trim($line) != "") {
            $lastline = $line;
        }

        $verylastline = $line;

        if (strpos($line, 'Destination') !== false) {
            $pos = strrpos($line, '/');
            $filename = $pos === false ? $line : substr($line, $pos + 1);
        }
    }

    // Разбирает строку dl_queue после "queueid...=" - общая логика для process_queue()/remove_queued_job().
    // Возвращает null, если нет "=" - вызывающий код пропускает такую строку.
    private static function parseQueueLine(string $line): ?array
    {
        $parts = explode("=", $line, 2);
        if (count($parts) < 2) {
            return null;
        }

        $urlParts = explode(">", $parts[1]);

        return [
            'qid'          => $parts[0],
            'urlData'      => $parts[1],
            'url'          => urldecode(trim($urlParts[0] ?? '')),
            'dl_format'    => urldecode($urlParts[1] ?? ''),
            'audio_only'   => $urlParts[2] ?? '',
            'audio_format' => $urlParts[3] ?? '',
            'client_ip'    => trim($urlParts[4] ?? 'unknown'),
            'translate'    => trim($urlParts[5] ?? ''),
        ];
    }

    // $fileList - опционально, ['ytdl' => [...путей]] из scanLogPath(). null -
    // сканирует /ytdl_* сам (вызовы вне ?jobs).
    public static function get_finished_background_jobs(?array $fileList = null)
    {
        if (!isset($GLOBALS['config']['logPath']) || !is_dir($GLOBALS['config']['logPath'])) return [];
        $logPath = $GLOBALS['config']['logPath'];

        $entries = $fileList['ytdl'] ?? null;
        if ($entries === null) {
            $entries = [];
            foreach (new DirectoryIterator($logPath) as $fileinfo) {
                if ($fileinfo->isDot() || !$fileinfo->isFile()) continue;
                if (strpos($fileinfo->getFilename(), "ytdl_") !== 0) continue;
                $entries[] = $fileinfo->getPathname();
            }
        }

        // Per-file кэш (filename => sig+job) вместо одной сигнатуры на весь набор - иначе
        // завершение любой одной новой задачи инвалидировало кэш целиком и build_finished_jobs()
        // перечитывало построчно вообще все ytdl_*-файлы, а не только новый.
        $cacheFile = $logPath . '/.finished_cache';
        $cached = @file_get_contents($cacheFile);
        $cacheMap = [];
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data) && ($data['v'] ?? null) === 2 && isset($data['files']) && is_array($data['files'])) {
                $cacheMap = $data['files'];
            }
        }

        $newCacheMap = [];
        $jobsWithMtime = [];
        $dirty = false;

        foreach ($entries as $entryPath) {
            $name = basename($entryPath);
            $mtime = (int) @filemtime($entryPath);
            $size = (int) @filesize($entryPath);
            $sig = $mtime . ':' . $size;

            if (isset($cacheMap[$name]) && ($cacheMap[$name]['sig'] ?? null) === $sig) {
                $job = $cacheMap[$name]['job'];
            } else {
                $parsed = self::build_finished_jobs([$entryPath]);
                $job = $parsed[0] ?? null;
                if ($job === null) continue;
                $dirty = true;
            }

            $newCacheMap[$name] = ['sig' => $sig, 'job' => $job];
            $jobsWithMtime[] = ['mtime' => $mtime, 'job' => $job];
        }

        if (!$dirty && count($newCacheMap) !== count($cacheMap)) {
            $dirty = true; // файл(ы) удалили - сжимаем кэш до актуального набора
        }

        if ($dirty) {
            @file_put_contents(
                $cacheFile,
                json_encode(['v' => 2, 'files' => $newCacheMap], JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
        }

        // Свежие сверху - DirectoryIterator/кэш не гарантируют хронологический порядок.
        usort($jobsWithMtime, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return array_column($jobsWithMtime, 'job');
    }

    // $entries уже собран за один проход в get_finished_background_jobs() - каталог сам не сканирует.
    private static function build_finished_jobs(array $entries)
    {
        $bjs = [];

        foreach ($entries as $filepath) {
            $filenameOnly = basename($filepath);
            {
                $handle = @fopen($filepath, "r");
                if (!$handle) continue;

                $lastline = "";
                $verylastline = "";
                $filename = "Дундук :)";
                $site = "N/A";
                $siteset = false;
                $isaudio = (strpos($filenameOnly, "_a") !== false);
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

                    // [ytcmd] с --cookies - точечная попытка после блокировки обычного способа (autoRetryWithCookiesIfNeeded)
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

                if (strpos($filenameOnly, "_cancelled") !== false) {
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
                            $urlSite = self::detectCookiesSite($urltext);
                            $jobstatus = self::parseYtDlpError($log_content, $urlSite);
                            // Куки в конфиге есть, но непригодны (обычно права) - молчать нельзя, юзер не поймёт почему бот-чек не обошёлся.
                            if (self::needsCookiesRetry($jobstatus) && self::cookiesConfiguredButUnusable($urlSite)) {
                                $jobstatus .= "\nКуки настроены, но файл непригоден (права/доступ) - нужен chmod 600";
                            }
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
                    'pid' => $filenameOnly,
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

    // Регексп протухших кук - вынесен из ERROR_RULES отдельной константой, т.к. текст сообщения зависит от сайта ($site в parseYtDlpError), а не фиксирован как остальные правила.
    private const EXPIRED_COOKIES_PATTERN = '/cookies are no longer valid|cookies have expired|cookies are not valid|Failed to load cookies/i';

    // Правила «регексп -> сообщение», по приоритету: первое совпадение выигрывает (сетевые -> доступность -> форматы -> постобработка -> системные).
    private const ERROR_RULES = [
        // === Бот-детект (выше всех - часто идёт с 429, но причина именно бот-чек) ===
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

    // $site - результат detectCookiesSite() по URL задачи, null если неизвестен/не передан. Влияет только на текст сообщения о протухших куках.
    private static function parseYtDlpError(string $log, $site = null): string
    {
        $log = self::sanitizeLog($log);

        if (preg_match(self::EXPIRED_COOKIES_PATTERN, $log)) {
            $siteLabel = $site === 'instagram' ? 'Instagram' : 'YouTube';
            return "Куки $siteLabel протухли 🍪\nНадо зайти под тем же аккаунтом и обновить cookies.txt на сервере";
        }

        foreach (self::ERROR_RULES as [$pattern, $message]) {
            if (preg_match($pattern, $log)) {
                return $message;
            }
        }

        // Фоллбэк: сам текст ошибки yt-dlp, лог уже санитизирован.
        if (preg_match('/ERROR:\s*(.{10,120})/i', $log, $m)) {
            return "⚠️ " . trim($m[1]);
        }

        return "🤔 ХЗ, что случилось \nСмотри лог";
    }

    // basename()+realpath() guard, как в FileHandler::delete(). realpath - защита от симлинка внутри logPath, ведущего наружу; не требует существования файла. Возвращает basename или null, если путь ведёт наружу.
    private static function safeLogPathBasename(string $filename): ?string
    {
        if (!isset($GLOBALS['config']['logPath'])) {
            return null;
        }

        $name = basename($filename);
        $realLogPath = realpath(rtrim($GLOBALS['config']['logPath'], '/'));
        if ($realLogPath === false) {
            return null;
        }

        $realFile = realpath($realLogPath . '/' . $name);
        if ($realFile !== false && strpos($realFile, $realLogPath . '/') !== 0) {
            return null;
        }

        return $name;
    }

    // Убивает всю группу процессов ($jpid - лидер группы, см. setsid в executeDownload), не только сам $jpid.
    // Для обычной закачки эквивалентно "kill $jpid", но для VOT-задач (bash -c "yt-dlp & vot-cli & wait")
    // одиночный kill убивал бы только bash, дети продолжали бы работать осиротевшими (дописывали *_ru.mp4 после "Стоп").
    // cmdline сверяется с youtubedlExe - PID-reuse guard. $sleepAfterKill только для одиночного "Стоп" (даёт время среагировать до finalize_job_log); "Стоп ВСЕ" не ждёт на каждый процесс, есть отдельный pgrep-fallback ниже.
    private static function killProcessGroupIfAlive(string $jpid, bool $sleepAfterKill): void
    {
        if (empty($jpid) || !file_exists('/proc/' . $jpid)) {
            return;
        }
        $pidcmd = @file_get_contents('/proc/' . $jpid . '/cmdline');
        if ($pidcmd === false || strpos($pidcmd, $GLOBALS['config']['youtubedlExe']) === false) {
            return;
        }
        shell_exec("kill -- -" . escapeshellarg($jpid));
        if ($sleepAfterKill) {
            usleep(500000);
        }
    }

    public static function kill_one_of_them($fpid)
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        $fpid = self::safeLogPathBasename($fpid);
        if ($fpid === null) return;
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
        $clientip = trim($jid_parts[3] ?? '');

        self::killProcessGroupIfAlive($jpid, true);

        self::finalize_job_log($outfile, $completed, $ytcmd, $urltext, $clientip);
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
                $jpid = $jid_parts[0] ?? '';
                $ytcmd = $jid_parts[1] ?? '';
                $urltext = $jid_parts[2] ?? '';
                $clientip = trim($jid_parts[3] ?? '');

                self::killProcessGroupIfAlive($jpid, false);

                self::finalize_job_log($jobfile, $completed, $ytcmd, $urltext, $clientip);
            }
            @unlink($file);
        }

        // Fallback для процессов без pid-файла (tmp/ потерян). VOT-сирот не находит - их argv не содержит "yt-dlp".
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

    // Возвращает имя нового pid_-файла или false. Авторетрею нужно, чтобы удалить старый лог только при успехе и сразу показать ретрей активной строкой в ?jobs.
    public static function restart_download($fpid, $forceUseProxy = false, $forceUseCookies = false)
    {
        if (!isset($GLOBALS['config']['logPath'])) return false;

        $logPath = $GLOBALS['config']['logPath'];

        $fpid = self::safeLogPathBasename($fpid);
        if ($fpid === null) return false;
        $file = $logPath . '/' . $fpid;

        if (!file_exists($file)) {
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
        $clientip = "";
        $usesProxy = false;
        $handle = fopen($file, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if (($pos = strpos($line, '[ytip]')) !== false) {
                    $clientip = trim(substr($line, $pos + 7));
                }
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

        // БЕЗОПАСНОСТЬ: проверка, что команда содержит ожидаемый бинарник, убираем префикс env-переменных (может быть несколько).
        $expectedExe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';
        $cmdToCheck = preg_replace('/^env\s+(?:[\w]+=\S+\s+)+/', '', $ytcmd);

        // VOT-задачи сохранены как "bash -c '...'" - youtubedlExe внутри строки, не в начале. Для такой формы проверяем вхождение где угодно, иначе рестарт VOT всегда бы отклонялся. Обычные команды - строгая проверка позиции 0.
        $isBashWrapped = (strpos($cmdToCheck, 'bash -c ') === 0);
        $commandLooksValid = $isBashWrapped
            ? (strpos($ytcmd, $expectedExe) !== false)
            : (strpos($cmdToCheck, $expectedExe) === 0);

        if (!$commandLooksValid) {
            $_SESSION['errors'] = ["Подозрительная команда в логе. Рестарт отменен"];
            error_log("[YTDL] Security: Command mismatch on restart.");
            return false;
        }

        // Убираем замаскированный плейсхолдер "env all_proxy=[SOCKS5_PROXY]" перед вставкой настоящего прокси, иначе yt-dlp получит буквальную строку-плейсхолдер.
        $ytcmd = preg_replace('/^env\s+all_proxy=\S+\s+/', '', $ytcmd);

        // Если исходная задача использовала прокси ИЛИ нас просят принудительно добавить его
        // (как при авторетрее с гео-блоком) - вставляем его из текущего конфига
        if (($usesProxy || $forceUseProxy) && !empty($GLOBALS['config']['socks5'])) {
            $ytcmd = "env all_proxy=" . escapeshellarg($GLOBALS['config']['socks5']) . " " . $ytcmd;
            $usesProxy = true; // Отмечаем, что теперь используется прокси
        }

        // Вставляем --cookies сразу после бинарника yt-dlp, не в конец - гарантированно читается как опция. stripos-проверка против задвоения флага на повторном ретрее.
        // VOT-задачи пропускаем: youtubedlExe внутри уже заэкранированной "bash -c '...'" - сырая вставка сломала бы кавычение. Мягкая деградация - VOT остаётся доступна для ручного рестарта.
        // Файл кук выбираем по URL исходной задачи - иначе не-YouTube задача, дошедшая сюда через общие фразы needsCookiesRetry(), получила бы чужой (YouTube) файл кук.
        $cookiesFile = self::cookiesFileForSite(self::detectCookiesSite($urltext));
        if ($forceUseCookies && !$isBashWrapped && self::cookiesFileUsable($cookiesFile) && stripos($ytcmd, '--cookies ') === false) {
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

        // Восстанавливаем IP из [ytip], ре-валидируем FILTER_VALIDATE_IP - файлу не доверяем на слово. putenv обязателен даже при пустом IP - иначе останется чужое значение от прошлой загрузки на этом FPM-воркере.
        $clientip = filter_var($clientip, FILTER_VALIDATE_IP) ?: 'unknown';
        putenv("CLIENT_IP=" . $clientip);

        // exec вместо passthru - не выводить мусор в браузер. setsid обязателен: restart_download всегда оборачивает в "bash -c", без него group-kill не нашёл бы группу перезапущенной задачи.
        $cmd = sprintf(
            'setsid bash -c %s > %s/%s 2>&1 & echo $! > %s/%s',
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

        // Маскируем прокси только в сохраняемой копии, $ytcmd для exec() уже использован с настоящим значением. Одна атомарная запись, не три FILE_APPEND - узкое окно гонки для конкурентного читателя.
        $ytcmd_masked = preg_replace('/env\s+all_proxy=\S+/', 'env all_proxy=[SOCKS5_PROXY]', $ytcmd);
        $proxyMarker = $usesProxy ? "[USES_PROXY] " : "";
        // Строка 4 - IP отправителя, переживает финализацию/рестарт этой задачи.
        file_put_contents(
            "$logPath/$fnp",
            $proxyMarker . $ytcmd_masked . "\n" . $urltext . "\n" . $clientip . "\n",
            FILE_APPEND
        );

        return $fnp;
    }

    public static function clear_one_finished($fpid)
    {
        if (!isset($GLOBALS['config']['logPath'])) return;

        $fpid = self::safeLogPathBasename($fpid);
        if ($fpid === null) return;
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
        // Проверка "max_dl сломан в конфиге" - одна на весь список. Сам гейт лимита - в dispatchGroup(), на уровне группы-хоста.
        if ($this->config["max_dl"] != -1 && $this->config["max_dl"] <= 0) {
            $this->errors[] = "Значение max_dl value в config.php указано неверно";
            return;
        }

        foreach ($this->dl_list as $onedownload) {
            $this->addOneDownload($onedownload);
        }
    }

    public function addOneDownload($onedownload)
    {
        $urls = array_filter(array_map('trim', explode('||', $onedownload['url'])));

        // Группируем по хосту - один сайт, один процесс (иначе параллельные запросы через общий прокси ловят 429). Разные хосты качаются параллельно.
        $groups = [];
        foreach ($urls as $url) {
            $groups[$this->getHost($url)][] = $url;
        }

        foreach ($groups as $groupUrls) {
            $useProxy = !$this->isDirectAccessDomain($groupUrls[0]);

            if (count($groupUrls) === 1) {
                $this->dispatchGroup($onedownload, $groupUrls, $useProxy, false);
            } else {
                // Первую ссылку качаем сразу без паузы; остальные того же хоста - вторым процессом с паузой между запросами (иначе 429).
                $first = array_shift($groupUrls);
                $this->dispatchGroup($onedownload, [$first], $useProxy, false);
                $this->dispatchGroup($onedownload, $groupUrls, $useProxy, true);
            }
        }
    }

    // Гейт max_dl на уровне группы-хоста, а не всего сабмита - раньше проверка была одна на весь $onedownload, и сабмит с несколькими хостами реально обходил max_dl.
    private function dispatchGroup($onedownload, $groupUrls, $useProxy, $paceRequests)
    {
        if ($this->config["max_dl"] == -1) {
            $this->executeDownload($onedownload, $groupUrls, $useProxy, $paceRequests);
            return;
        }

        // background_jobs() кэширован на запрос, но инкрементируется сразу после exec() - видит процессы, запущенные более ранними группами этого же запроса.
        if (self::background_jobs() < $this->config["max_dl"]) {
            $this->executeDownload($onedownload, $groupUrls, $useProxy, $paceRequests);
            return;
        }

        if ($this->config["disableQueue"]) {
            $this->errors[] = "Достигнут лимит одновременных загрузок. " . implode(', ', $groupUrls) . " не был загружен";
            return;
        }

        // В очередь уходит только эта группа, не весь многохостовый $onedownload - иначе process_queue() снова запустил бы все хосты разом в обход лимита.
        $groupDownload = $onedownload;
        $groupDownload['url'] = implode('||', $groupUrls);
        $this->addToQueue($groupDownload);
    }

    private function executeDownload($onedownload, $urls, $useProxy, $paceRequests = false)
    {
        $suffix = "";
        $cmd = $this->config['youtubedlExe'];
        $cmd .= " --js-runtimes node";
        // Логгер (LogPluginPP) подключаем явно, не полагаясь на автопоиск. "default" обязателен - без него --plugin-dirs заменяет дефолтные пути, и bgutil PO-token провайдер не грузится, YouTube режет бот-чеком.
        $cmd .= " --plugin-dirs default";
        $cmd .= " --plugin-dirs " . escapeshellarg("/etc/yt-dlp/plugins/log_plugin");
        $cmd .= " --use-postprocessor LogPluginPP";
        $cmd .= " -o " . escapeshellarg($this->download_path . "/%(title)s_%(id)s.%(ext)s");
        $cmd .= " --restrict-filenames";

        $sanitizedFormat = $this->sanitizeDlFormat($onedownload['dl_format']);
        if ($sanitizedFormat === 'worst') {
            $cmd .= " -f worst";
        } else {
            // 'top': лучшее видео+аудио до maxVideoRes; явный выбор качества переопределяет потолок
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

        // Параллельные фрагменты (--concurrent-fragments 4 --http-chunk-size 5M) убраны: Google начал помечать трафик как подозрительный - несколько одновременных Range-соединений к CDN с одного IP выглядят как бот. Стандартное одно соединение на файл.

        if ($useProxy && !empty($this->config['socks5'])) {
            // no_proxy для localhost - запрос к серверу PO-токенов (bgutil, 127.0.0.1:4416) не должен уходить в SOCKS5.
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
            // Трансляции (Twitch и прочий live-HLS) yt-dlp тянет через ffmpeg, а обычный mp4 без moov-атома
            // в конце файла не открывается вообще - оборванная запись превращалась в мусор. Фрагментированный
            // mp4 пишет заголовки по ходу, поэтому любой обрезок играется. Ключ уходит в выходные аргументы
            // ffmpeg (yt-dlp кладёт голый "ffmpeg:" именно туда) и молча игнорируется, когда качает не ffmpeg.
            $cmd .= " --downloader-args " . escapeshellarg("ffmpeg:-movflags +frag_keyframe+empty_moov+default_base_moof");
        }

        $cmd .= " --embed-thumbnail --embed-metadata";

        $isYoutube = false;
        $isYoutubeMulti = false;
        foreach ($urls as $url) {
            if (preg_match('/(youtube\.com|youtu\.be)/i', $url)) {
                $isYoutube = true;
                // Плейлист/канал разворачивается в десятки роликов - нужен сон и между загрузками, не только между HTTP-запросами.
                if (preg_match('#[?&]list=|/playlist|/channel/|/@|/c/|/user/#i', $url)) {
                    $isYoutubeMulti = true;
                }
            }
        }
        if ($isYoutube) {
            $cmd .= " --sponsorblock-remove sponsor";
            // mweb - официально рекомендованный yt-dlp клиент для связки с PO-токен провайдером (у нас bgutil,
            // см. app/start.sh) - https://github.com/yt-dlp/yt-dlp/wiki/PO-Token-Guide. web/web_safari туда же
            // требуют GVS PO-токен, но именно на них ловили бот-чек - исключены. android_vr токен для GVS не
            // требует вовсе, аварийный fallback: с марта 2026 клиент нестабилен (иногда только 360p,
            // https://github.com/yt-dlp/yt-dlp/issues/16150), поэтому не первым. tv токен тоже не требует, но без
            // подключённых кук (на первую попытку куки не даём, см. ниже) у него все форматы DRM - бесполезен здесь.
            $cmd .= " --extractor-args " . escapeshellarg("youtube:player_client=mweb,android_vr");
        }

        // YouTube: куки не подключаются к обычной загрузке - аккаунт-based PO-токен запросы требуют Data Sync ID, который без реальной нужды в куках взяться неоткуда, только лишние WARNING. Точечно, только повторной попыткой - см. autoRetryWithCookiesIfNeeded().
        // Instagram: наоборот, публичный доступ у yt-dlp часто отсутствует вовсе (приватные аккаунты, stories) - ждать первой неудачной попытки бессмысленно, куки подключаем сразу, если настроены и пригодны.
        $isInstagram = false;
        foreach ($urls as $url) {
            if (self::detectCookiesSite($url) === 'instagram') {
                $isInstagram = true;
                break;
            }
        }
        if ($isInstagram) {
            $instagramCookiesFile = self::cookiesFileForSite('instagram');
            if (self::cookiesFileUsable($instagramCookiesFile)) {
                $cmd .= " --cookies " . escapeshellarg($instagramCookiesFile);
            }
        }

        // Пауза - защита от 429/бот-чека. Плейлист/канал YouTube разворачивается в десятки роликов (залп extraction-запросов) -
        // нужна пауза и между загрузками, не только между HTTP-запросами. Одиночный YouTube-ролик тоже получает лёгкую
        // sleep-requests: 429 на самом первом webpage-запросе (см. android_vr/tv приоритет выше) бьёт по прогретости прокси
        // независимо от того, один ролик грузится или пачка - риск невелик, а пауза короткая.
        if ($isYoutubeMulti) {
            $cmd .= " --sleep-requests 1.5 --sleep-interval 3 --max-sleep-interval 8";
        } elseif ($paceRequests) {
            $cmd .= " --sleep-interval 3 --max-sleep-interval 8 --sleep-requests 1";
        } elseif ($isYoutube) {
            $cmd .= " --sleep-requests 0.5";
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

        // Перевод озвучки через Яндекс-VOT, только для видео. yt-dlp качает как обычно, vot-cli тянет переведённую дорожку, mux_translated.sh вклеивает. Обёртка bash -c: yt-dlp остаётся в cmdline, liveness-проверка продолжает видеть задачу.
        if (!empty($onedownload['translate']) && empty($onedownload['audio_only']) && $this->translationSupported($urls)) {
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

            // vot-cli стартует одновременно с yt-dlp - перевод Яндекса идёт во время скачивания, не плюсом к нему. Mux только если оба успешны, иначе остаётся видео без перевода.
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

        // Одна из двух разрешённых точек backgrounding'а (вторая - restart_download). setsid делает процесс лидером группы - без этого group-kill не достаёт детей translate-ветки (vot-cli/ffmpeg). Новая точка запуска ОБЯЗАНА пройти через setsid, иначе group-kill для неё тихо сломается. $logcmd (без "setsid") пишется в pid-файл и сверяется в restart_download - остаётся чистым.
        $cmd = "setsid " . $cmd;
        $cmd .= " > " . escapeshellarg($this->config['logPath'] . "/" . $fno) . " 2>&1 & echo $! > " . escapeshellarg($this->config['logPath'] . "/" . $fnp);

        // putenv не меняет команду/лог - передаёт IP плагину LogPluginPP через окружение, не задевая restart-парсинг
        putenv("CLIENT_IP=" . ($onedownload['client_ip'] ?? 'unknown'));
        exec($cmd);

        // Учитываем в кэше сразу - pid-файл пишется асинхронно, ре-glob мог бы не увидеть
        if (self::$bg_jobs_cache !== null) {
            self::$bg_jobs_cache++;
        }

        // Прокси маскируем плейсхолдером - видно, что использовался, без пароля. Одна атомарная запись - конкурентный читатель (kill/restart/?jobs) не увидит наполовину дописанное.
        $logcmd_masked = preg_replace('/env\s+all_proxy=[^\s]+/', 'env all_proxy=[SOCKS5_PROXY]', $logcmd);
        $proxyMarker = ($useProxy && !empty($this->config['socks5'])) ? "[USES_PROXY] " : "";
        // Строка 4 - IP отправителя, для восстановления при рестарте.
        file_put_contents(
            $this->config['logPath'] . "/" . $fnp,
            $proxyMarker . $logcmd_masked . "\n" . $urltext . "\n" . ($onedownload['client_ip'] ?? 'unknown') . "\n",
            FILE_APPEND
        );
    }

    // Общий шаг чтения dl_queue для process_queue()/remove_queued_job()/reorder_queued_job():
    // построчно, пропуская пустые, до первой строки без префикса "queueid" (после неё - corrupt).
    private static function readQueueLines($handle): array
    {
        $lines = [];
        $corrupt = false;
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === "") continue;
            if (substr($line, 0, 7) !== "queueid") {
                $corrupt = true;
                break;
            }
            $lines[] = $line;
        }
        return ['lines' => $lines, 'corrupt' => $corrupt];
    }

    private static function writeQueueLines($handle, array $lines): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        foreach ($lines as $oneline) {
            fwrite($handle, $oneline);
        }
    }

    // Возвращает распарсенный остаток очереди - index.php переиспользует для вкладки "Очередь", не читая dl_queue повторно. $fileList - опционально, из scanLogPath(), избегает повторного скана logPath.
    public function process_queue(?array $fileList = null): array
    {
        $queue_file = $this->config['logPath'] . "/dl_queue";
        if (!file_exists($queue_file)) return [];

        $handle = fopen($queue_file, "c+");
        if (!$handle) return [];

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return [];
        }

        $read = self::readQueueLines($handle);
        $corrupt_queue = $read['corrupt'];

        $currently_running = self::background_jobs($fileList);
        $remaining_urls = [];
        $remainingParsed = [];
        $newDownloads = [];

        foreach ($read['lines'] as $line) {
            $parsed = self::parseQueueLine($line);
            if ($parsed === null) continue;

            if (!$this->is_valid_url($parsed['url'])) {
                $this->errors[] = $parsed['urlData'] . " не верный URL, удаляю из списка очереди";
                continue;
            }

            // max_dl == -1 нужен отдельным условием - "$currently_running < -1" всегда false, задачи в очереди никогда бы не продвинулись, если лимит сменили на -1 постфактум.
            if ($this->config["max_dl"] == -1 || $currently_running < $this->config["max_dl"]) {
                $newDownloads[] = array(
                    'url' => $parsed['url'],
                    'dl_format' => $parsed['dl_format'],
                    'audio_only' => $parsed['audio_only'],
                    'audio_format' => $parsed['audio_format'],
                    'client_ip' => $parsed['client_ip'],
                    'translate' => $parsed['translate']
                );
                $currently_running++;
            } else {
                $remaining_urls[] = $line;
                $remainingParsed[] = array(
                    'pid' => $parsed['qid'],
                    'url' => $parsed['url'],
                    'dl_format' => $parsed['dl_format'],
                    'audio_only' => !empty(trim($parsed['audio_only'])),
                    'audio_format' => $parsed['audio_format']
                );
            }
        }

        self::writeQueueLines($handle, $remaining_urls);

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($corrupt_queue) {
            @unlink($queue_file);
            $this->errors[] = "Файл повредился либо был удален";
            $remainingParsed = [];
        }

        if (!empty($newDownloads)) {
            $this->dl_list = $newDownloads;
            $this->do_download();
        }

        if (!empty($this->errors)) {
            $_SESSION['errors'] = $this->errors;
        }

        return $remainingParsed;
    }

    public function addToQueue($onedownload)
    {
        $queue_file = $this->config['logPath'] . "/dl_queue";
        $clientIp = $onedownload['client_ip'] ?? 'unknown';
        $translate = !empty($onedownload['translate']) ? '1' : '';
        // dl_format тоже через urlencode - не даёт ">"/"\n" попасть в файл очереди, даже если addToQueue() вызовут в обход whitelist-проверки в index.php.
        $fcontent = "queueid" . uniqid() . "=" . urlencode($onedownload['url']) . ">" . urlencode($onedownload['dl_format']) . ">" . $onedownload['audio_only'] . ">" . $onedownload['audio_format'] . ">" . $clientIp . ">" . $translate . "\n";

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

        $read = self::readQueueLines($handle);
        $corrupt_queue = $read['corrupt'];
        $remaining_urls = [];

        foreach ($read['lines'] as $line) {
            $parsed = self::parseQueueLine($line);
            if ($parsed === null) continue;

            if ($parsed['qid'] !== $qid) {
                $remaining_urls[] = $line;
            }
        }

        self::writeQueueLines($handle, $remaining_urls);

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

    // Переставляет задачу на одну позицию - соседние строки dl_queue меняются местами. За краем очереди молча ничего не делает.
    public static function reorder_queued_job($qid, string $direction): void
    {
        if (!isset($GLOBALS['config']['logPath'])) return;
        if ($direction !== 'up' && $direction !== 'down') return;

        $queue_file = $GLOBALS['config']['logPath'] . "/dl_queue";
        if (!file_exists($queue_file)) return;

        $handle = fopen($queue_file, "c+");
        if (!$handle) return;

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        $read = self::readQueueLines($handle);
        $corrupt_queue = $read['corrupt'];
        $lines = $read['lines'];

        if (!$corrupt_queue) {
            $index = null;
            foreach ($lines as $i => $oneline) {
                $parsed = self::parseQueueLine($oneline);
                if ($parsed !== null && $parsed['qid'] === $qid) {
                    $index = $i;
                    break;
                }
            }

            if ($index !== null) {
                $swapWith = ($direction === 'up') ? $index - 1 : $index + 1;
                if ($swapWith >= 0 && $swapWith < count($lines)) {
                    [$lines[$index], $lines[$swapWith]] = [$lines[$swapWith], $lines[$index]];
                }
            }

            self::writeQueueLines($handle, $lines);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($corrupt_queue) {
            @unlink($queue_file);
            $_SESSION['errors'] = ["Файл очереди повредился либо был удален."];
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