<?php

class Downloader
{
    private $dl_list = [];
    private $errors = [];
    // Отклонённые ссылки и факт "хоть что-то ушло качаться" - index.php по ним
    // решает, редиректить ли на вкладку загрузок и что вернуть в поле ввода.
    private $rejectedUrls = [];
    private $accepted = false;
    // Что реально произошло с отправкой - для сводки "3 качаются, 6 в очереди,
    // 1 отклонена". Считается на уровне группы-хоста, там же, где принимается
    // решение "запустить или в очередь" (dispatchGroup).
    private $startedUrls = [];
    private $queuedUrls = [];

    public function getStartedUrls(): array
    {
        return $this->startedUrls;
    }

    public function getQueuedUrls(): array
    {
        return $this->queuedUrls;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getRejectedUrls(): array
    {
        return $this->rejectedUrls;
    }

    public function hasAcceptedDownloads(): bool
    {
        return $this->accepted;
    }
    private $download_path = "";
    private $config = [];

    // Логи короче порога читаются целиком; длиннее - только голова (site/playlist) и хвост (текущий статус).
    private const LOG_HEAD_TAIL_THRESHOLD = 65536;
    private const LOG_HEAD_BYTES = 4096;
    private const LOG_TAIL_BYTES = 65536;

    // Домены с прямым доступом (без прокси) - иностранные прокси их часто блокируют или тормозят.
    // public, потому что тот же список инжектится на фронт (index.php -> part.header.php):
    // предупреждение "прокси мёртв" не должно всплывать на ссылке, которая прокси не касается.
    public const DIRECT_ACCESS_DOMAINS = [
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
        // TikTok исключён - блокирует по IP даже с куками, только через прокси
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
            // Раньше первая же кривая ссылка отменяла всю пачку: пять ссылок, одна
            // с опечаткой - не запускалось ничего. Теперь валидные уходят качаться,
            // отклоняется только битая, и она же возвращается в поле ввода.
            $accepted = [];
            foreach ($dl_list as $onedownload) {
                $urls = self::splitUrls($onedownload['url']);
                // Ввод вида "||" проходит !empty() в index.php, но после разбора не остаётся ни одного сегмента.
                if (empty($urls)) {
                    $this->errors[] = "Пустая ссылка - нечего качать";
                    continue;
                }

                $valid = [];
                foreach ($urls as $url) {
                    if ($this->is_valid_url($url)) {
                        $valid[] = $url;
                    } else {
                        $this->errors[] = "«" . $url . "» ты в порядке? Поправь ссыль, ну че ты!";
                        $this->rejectedUrls[] = $url;
                    }
                }

                if (!empty($valid)) {
                    $onedownload['url'] = implode('||', $valid);
                    $accepted[] = $onedownload;
                }
            }

            if (empty($accepted)) {
                $_SESSION['errors'] = $this->errors;
                return;
            }

            $this->dl_list = $accepted;
            $this->accepted = true;
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

            // Задача, ждущая начала эфира (--wait-for-video), слот не занимает:
            // при max_dl = 1 она заперла бы очередь на часы, ничего при этом не
            // качая. Как только эфир начнётся, ждущей она быть перестанет и
            // попадёт в счёт на следующем же опросе.
            if (self::isWaitingForStream($pidFile)) {
                continue;
            }

            $count++;
        }

        self::$bg_jobs_cache = $count;
        return $count;
    }

    // Приметы ожидания эфира в логе yt-dlp. Прогресс закачки любую из них
    // отменяет - значит эфир начался.
    private const WAITING_PATTERNS = '/Waiting for video|This live event will begin|Premiere will begin|will begin in a few moments/i';

    // Задача стоит на --wait-for-video? Читаем хвост её job-лога: ждущая задача
    // пишет туда строку ожидания и не пишет процентов.
    private static function isWaitingForStream(string $pidFile): bool
    {
        $jobLog = dirname($pidFile) . '/' . str_replace('pid_', 'job_', basename($pidFile));
        $size = @filesize($jobLog);
        if ($size === false || $size === 0) {
            return false;
        }

        // Хвоста хватает: строка ожидания переписывается yt-dlp по мере отсчёта,
        // а начавшаяся закачка сразу пишет проценты и Destination.
        $handle = @fopen($jobLog, 'r');
        if (!$handle) {
            return false;
        }
        $offset = max(0, $size - 4096);
        if ($offset > 0) fseek($handle, $offset);
        $tail = (string) fread($handle, 4096);
        fclose($handle);

        if (strpos($tail, 'Destination:') !== false || strpos($tail, '[download]') !== false) {
            return false;
        }

        return (bool) preg_match(self::WAITING_PATTERNS, $tail);
    }

    // Признаки закрытого контента в логе ЖИВОЙ задачи. Ключ --wait-for-video ждёт не
    // только анонсированный эфир: любую недоступность видео он считает "ещё не началось"
    // и переизвлекает ссылку по кругу без предела. Возрастной гейт детерминирован, ждать
    // его бессмысленно, а задача при этом не завершается - значит и авторетрей с куками
    // (см. autoRetryWithCookiesIfNeeded(), "18+ контент" у него в списке) никогда не
    // сработает. Список намеренно уже, чем COOKIES_RETRY_KEYWORDS: тут сырой лог yt-dlp,
    // а не разобранный статус.
    //
    // /u обязателен: "." без него матчит один БАЙТ, а апостроф в "you're" у
    // yt-dlp реально приходит curly - "'" (U+2019, 3 байта в UTF-8). Без /u
    // точка съедала первый байт кавычки, "re" дальше не совпадало, и вся
    // альтернатива не матчилась никогда - сторож молчал на живом бот-чеке.
    private const HOPELESS_WAIT_PATTERNS = '/Sign in to confirm your age|age-restricted|This video is private|members-only|Join this channel|Sign in to confirm you.re not a bot/iu';

    // Сколько раз примета должна повториться, чтобы задачу признать безнадёжной. Одного
    // раза мало: yt-dlp перебирает клиентов (см. --extractor-args player_client), и первый
    // из них может выхватить бот-чек, после чего следующий спокойно скачает ролик.
    private const HOPELESS_WAIT_HITS = 2;

    // Гасит задачи, залипшие на --wait-for-video из-за закрытого контента. Лог финализируется
    // БЕЗ суффикса _cancelled (в отличие от kill_one_of_them()): "отменено" тут неправда, да и
    // авторетреи такие логи пропускают - а нам нужен именно ретрей с куками.
    // $fileList по ссылке - по той же причине, что у get_current_background_jobs():
    // финализированный тут ytdl_ должен попасть в список этого же запроса.
    public static function abortHopelessWaiters(?array &$fileList = null): void
    {
        if (!isset($GLOBALS['config']['logPath'])) {
            return;
        }

        $logPath = $GLOBALS['config']['logPath'];
        $pidFiles = $fileList['pid'] ?? glob($logPath . '/pid_*');

        foreach ($pidFiles as $pidFile) {
            $pidBasename = basename($pidFile);
            $outfile = $logPath . '/' . str_replace('pid_', 'job_', $pidBasename);
            $completefile = $logPath . '/' . str_replace('pid_', 'ytdl_', $pidBasename);

            $log = @file_get_contents($outfile);
            if ($log === false || $log === '') {
                continue;
            }

            // Началась закачка - значит один из клиентов пробился, задача здорова.
            if (strpos($log, 'Destination:') !== false || strpos($log, '[download]') !== false) {
                continue;
            }

            if (preg_match_all(self::HOPELESS_WAIT_PATTERNS, $log) < self::HOPELESS_WAIT_HITS) {
                continue;
            }

            $content = @file_get_contents($pidFile);
            if ($content === false) {
                continue;
            }

            $parts = explode("\n", trim($content));
            $jpid = $parts[0] ?? '';
            $ytcmd = $parts[1] ?? '';
            $urltext = $parts[2] ?? '';
            $clientip = trim($parts[3] ?? '');

            // Короткое ожидание: вызов сидит в опросе ?jobs, а качать тут нечего -
            // дописывать файл процессу не надо, ждать его до последнего незачем.
            self::killProcessGroupIfAlive($jpid, false);

            @unlink($pidFile);
            self::$bg_jobs_cache = null;

            self::finalize_job_log($outfile, $completefile, $ytcmd, $urltext, $clientip);
            self::cleanupPartialFiles($completefile);

            if ($fileList !== null) {
                $fileList['ytdl'][] = $completefile;
            }

            self::autoRetryWithCookiesIfNeeded($completefile);
        }
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
                    // Уборка до авторетреев: они судят об успехе по файлам задачи,
                    // и обрубок недокачанного плеча сбивал бы им проверку.
                    self::cleanupPartialFiles($completefile);
                    if ($fileList !== null) {
                        $fileList['ytdl'][] = $completefile;
                    }
                    // Авторетреи (прокси/куки/другой клиент) не рестартуют сразу - только помечают
                    // лог маркером [RETRY_ATTEMPTED:...], фактический рестарт делает
                    // processScheduledRetries() через RETRY_SCHEDULE_DELAY секунд (см. там же) -
                    // чтобы статус в списке "Загрузки" успевал побыть прочитанным. Первый
                    // применимый маркер и выигрывает, порядок совпадает с приоритетом причин.
                    self::autoRetryIfNeeded($completefile);
                    self::autoRetryWithCookiesIfNeeded($completefile);
                    self::autoRetryWithAltClientIfNeeded($completefile);
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
                $mp4Phase = false;
                $ffmpegProgress = null;
                $aria2Progress = null;

                // Большие логи читаем частично: голова для site/playlist (печатаются раз в начале), хвост для текущего статуса. Цена тика O(головы+хвоста), не растёт с логом.
                $outSize = @filesize($outfile);
                if ($outSize !== false && $outSize > self::LOG_HEAD_TAIL_THRESHOLD) {
                    $head = fread($handle, self::LOG_HEAD_BYTES);
                    if ($head !== false && $head !== '') {
                        foreach (explode("\n", $head) as $headLine) {
                            self::scanForSiteAndPlaylist($headLine . "\n", $siteset, $site, $playlist);
                            // Имя файла печатается один раз в начале, как site и playlist:
                            // у длинной закачки Destination: уходит за пределы хвоста, и
                            // имя в таблице сползало на "Ща..". Прочие выходные параметры
                            // выброшены - статус берётся только из хвоста.
                            $skipLine = ''; $skipFlag = false; $skipPos = '';
                            self::scanForCurrentStatus($headLine . "\n", $skipPos, $skipFlag, $skipFlag, $skipLine, $skipLine, $filename);
                        }
                    }

                    $tailStart = max(0, $outSize - self::LOG_TAIL_BYTES);
                    fseek($handle, $tailStart);
                    $tail = stream_get_contents($handle);
                    $tailLines = ($tail === false) ? [] : explode("\n", $tail);
                    // Первая строка хвоста обрублена fseek не по границе - выбрасываем,
                    // но только если есть что оставить. aria2c и ffmpeg разделяют
                    // прогресс символом \r, без \n, и весь хвост бывает одной строкой:
                    // выбросив её, теряли разом проценты и Destination:, а статус
                    // посреди загрузки откатывался на "Собираю информацию по сайту".
                    // Обрубок безвреден - регулярки якорные, на неполной записи молчат.
                    if ($tailStart > 0 && count($tailLines) > 1) {
                        array_shift($tailLines);
                    }

                    foreach ($tailLines as $tailLine) {
                        self::scanForCurrentStatus($tailLine . "\n", $listpos, $votPhase, $muxPhase, $lastline, $verylastline, $filename, $ffmpegProgress, $aria2Progress, $mp4Phase);
                    }
                } else {
                    while (($line = fgets($handle)) !== false) {
                        self::scanForSiteAndPlaylist($line, $siteset, $site, $playlist);
                        self::scanForCurrentStatus($line, $listpos, $votPhase, $muxPhase, $lastline, $verylastline, $filename, $ffmpegProgress, $aria2Progress, $mp4Phase);
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

                // Ожидание анонсированного эфира: без этого статус выглядел бы как
                // зависшее "Собираю информацию по сайту" часами.
                if (preg_match(self::WAITING_PATTERNS, $verylastline) || preg_match(self::WAITING_PATTERNS, $lastline)) {
                    $lastline = "Жду начала эфира, слот очереди не занимаю";
                }

                // Прогресс ffmpeg (трансляции) важнее "Собираю информацию"/"В Процессе", но слабее фаз перевода ниже:
                // там ffmpeg занят миксом дорожек, а не приёмом потока.
                if ($ffmpegProgress !== null && !$isTranslateJob) {
                    $lastline = "Записываю трансляцию: " . self::formatBytes($ffmpegProgress['bytes'])
                        . ", " . $ffmpegProgress['time'];
                    if (!empty($ffmpegProgress['speed'])) {
                        $lastline .= ", " . self::formatBytes($ffmpegProgress['speed']) . "/с";
                    }
                }

                // Прогресс aria2c - там же по старшинству, что и ffmpeg: перекрывает
                // "Собираю информацию"/"В Процессе", но уступает фазам перевода ниже.
                if ($aria2Progress !== null && !$isTranslateJob) {
                    // Скачанные байты не показываем - третье число в строке лишнее,
                    // оно выводится из процента и размера.
                    $lastline = $aria2Progress['percent'] . "% из "
                        . self::formatBytes($aria2Progress['total']);
                    if (!empty($aria2Progress['speed'])) {
                        $lastline .= ", " . self::formatBytes($aria2Progress['speed']) . "/с";
                    }
                    if (!empty($aria2Progress['eta'])) {
                        $eta = self::formatEta($aria2Progress['eta']);
                        if ($eta !== null) {
                            $lastline .= ", осталось " . $eta;
                        }
                    }
                }

                // Фаза перевода перекрывает всё - vot/ffmpeg не пишут проценты, иначе висело бы вечное "В Процессе".
                if ($mp4Phase) {
                    // Скачивание кончилось, идёт ensure_mp4.sh. Без этой строки
                    // человек полминуты-минуту смотрит на застывшие 100%.
                    $lastline = "Привожу к mp4, почти готово";
                } elseif ($muxPhase && $isTranslateJob) {
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
                    // yt-dlp выравнивает поля прогресса пробелами ("of ~   1.57GiB at
                    // 6.46MiB/s"), чтобы строка не дёргалась в терминале. В таблице
                    // ширина и так фиксирована, а дыры внутри строки видны - схлопываем.
                    'status' => trim(preg_replace('/\s+/u', ' ', str_replace("\n", " ", $lastline))),
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
        // Немое видео чинится обычным повтором: дорожка пропадает от разового
        // отказа CDN на втором плече, а не от свойств самого ролика.
        'Звук не докачался',
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
    // Сколько держать хост придержанным после 429. Минуты, чтобы можно было
    // менять из конфига, не трогая код.
    private static function hostCooldownSeconds(): int
    {
        $minutes = (int) ($GLOBALS['config']['hostCooldownMinutes'] ?? 10);
        if ($minutes < 1 || $minutes > 240) {
            $minutes = 10;
        }
        return $minutes * 60;
    }

    // Файл-отметка вместо общего хранилища состояния: пауза живёт минуты, а
    // logPath чистится по возрасту в часах - переживать пересборку тут нечему.
    // Само время берём из mtime, содержимое не нужно.
    private static function hostMarkerFile(string $prefix, string $host): ?string
    {
        $logPath = $GLOBALS['config']['logPath'] ?? '';
        if ($logPath === '' || $host === '') {
            return null;
        }
        // Хост в имени файла - только буквы, цифры, точки и дефисы
        $safeHost = preg_replace('/[^a-z0-9.\-]/', '_', $host);
        return $logPath . '/' . $prefix . $safeHost;
    }

    private static function cooldownFile(string $host): ?string
    {
        return self::hostMarkerFile('cooldown_', $host);
    }

    // Когда к этому хосту в последний раз стартовала задача. Отдельная отметка, а
    // не cooldown_: кулдаун - это наказание после отказа, а это обычный интервал
    // между стартами, он действует и когда всё хорошо.
    private static function spawnFile(string $host): ?string
    {
        return self::hostMarkerFile('lastspawn_', $host);
    }

    // 429 на СТРАНИЦЕ - обычный первый шаг для YouTube: yt-dlp сам откатывается на
    // API и почти всегда докачивает ролик, WARNING в логе есть у каждой второй
    // здоровой задачи. Кулдаун ставим только на настоящий отказ - хост не пустил
    // и yt-dlp сдался (ERROR:, файла нет). Без этого различия cooldown_<host>
    // трогался бы на каждом завершении и очередь/проба плейлиста стояли бы почти
    // не переставая, пока задачи продолжают выполняться.
    private const HOST_REFUSAL_PATTERN = '/^ERROR:.*(?:HTTP Error 429|Too Many Requests)/mi';

    // Хост ответил 429 и мы сдались - запоминаем время. Ставится при завершении
    // задачи, там же, где разбирается её лог.
    private static function rememberHostRefusal($completefile, string $urltext): void
    {
        if ($urltext === '') {
            return;
        }
        if (self::jobProducedFile($completefile)) {
            return;
        }
        $log = @file_get_contents($completefile);
        if ($log === false || !preg_match(self::HOST_REFUSAL_PATTERN, $log)) {
            return;
        }
        $file = self::cooldownFile(self::getHostStatic(explode(',', $urltext)[0]));
        if ($file !== null) {
            @file_put_contents($file, '');
        }
    }

    // Сколько секунд ещё держать хост придержанным, 0 - можно качать
    public static function hostCooldownLeft(string $host): int
    {
        $file = self::cooldownFile($host);
        if ($file === null || !file_exists($file)) {
            return 0;
        }
        $at = @filemtime($file);
        if ($at === false) {
            return 0;
        }
        $left = $at + self::hostCooldownSeconds() - time();
        return $left > 0 ? $left : 0;
    }

    // Минимальный интервал между стартами задач к одному хосту. Только YouTube:
    // остальные площадки залпа не замечают, а тут три extraction-запроса подряд с
    // одного IP прокси дают 429 и следом бот-чек.
    //
    // Нужен именно барьер на спавне, а не паузы внутри процесса: признак "это
    // пачка" ($isYoutubeMulti в executeDownload(), --sleep-interval) не переживает
    // очередь. download() пускает первую ссылку группы без пауз, остаток уходит в
    // dl_queue, а process_queue() прогоняет каждую строку через download() заново -
    // и каждая снова оказывается "первой", то есть непаузированной.
    private const YOUTUBE_SPAWN_GAP = 20;

    // Задача стартовала - отмечаем время для следующей. Зовётся из обеих точек
    // backgrounding'а (executeDownload() и restart_download()): авторетрей - такой
    // же запрос к хосту, ему тоже нельзя лезть в след предыдущему.
    private static function rememberHostSpawn(string $urltext): void
    {
        if ($urltext === '') {
            return;
        }
        $file = self::spawnFile(self::getHostStatic(explode(',', $urltext)[0]));
        if ($file !== null) {
            @file_put_contents($file, '');
        }
    }

    // Сколько секунд ещё ждать до следующего старта к этому хосту, 0 - можно.
    public static function hostSpawnGapLeft(string $host): int
    {
        if ($host === '' || !self::isYoutubeUrl($host)) {
            return 0;
        }
        $file = self::spawnFile($host);
        if ($file === null || !file_exists($file)) {
            return 0;
        }
        $at = @filemtime($file);
        if ($at === false) {
            return 0;
        }
        $left = $at + self::YOUTUBE_SPAWN_GAP - time();
        return $left > 0 ? $left : 0;
    }

    // getHost() - метод экземпляра, а очередь разбирается и статически
    private static function getHostStatic(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        return is_string($host) ? preg_replace('/^www\./i', '', strtolower($host)) : '';
    }

    // Ниже две обёртки для PlaylistProbe. Логика не переезжает и не копируется -
    // проба обязана решать "через прокси или напрямую", "какие куки" и "пускать ли
    // ссылку вообще" ровно так же, как загрузка, иначе списки разъедутся.
    public static function validateUrl(string $url): bool
    {
        return self::is_valid_url($url, true);
    }

    // Тот же паттерн, что у executeDownload() - проба обязана видеть YouTube
    // ровно так же, как загрузка, иначе список клиентов у них разъедется.
    private const YOUTUBE_HOST_PATTERN = '/(youtube\.com|youtu\.be)/i';

    public static function isYoutubeUrl(string $url): bool
    {
        return (bool) preg_match(self::YOUTUBE_HOST_PATTERN, $url);
    }

    // Порядок клиентов YouTube. Один на всех: проба плейлиста (PlaylistProbe) обязана
    // видеть ровно те же форматы, что и загрузка, иначе пикер покажет ролики, которые
    // качалка не осилит.
    //   mweb        - официально рекомендованная связка с PO-токен провайдером (у нас
    //                 bgutil, см. app/start.sh) - https://github.com/yt-dlp/yt-dlp/wiki/PO-Token-Guide.
    //                 При живом провайдере даёт полный набор форматов. web/web_safari
    //                 туда же, но именно на них ловили бот-чек - исключены.
    //   web_embedded- GVS PO-токен не требует вовсе, то есть переживает и падение
    //                 bgutil, и бот-чек по IP. Цена: ролики с запретом встраивания и
    //                 возрастным гейтом через него не идут - их подхватывает авторетрей
    //                 с куками (autoRetryWithCookiesIfNeeded()).
    // Список короткий намеренно: yt-dlp опрашивает КАЖДОГО клиента из него, а не
    // останавливается на первом удачном - лишний клиент это лишний extraction-запрос
    // на каждый ролик, то есть прямая дорога обратно в 429.
    //
    // Замер через тот же SOCKS5 (август 2026, yt-dlp -F по одному клиенту):
    //   android_vr - бот-чек, форматов ноль. Раньше был аварийным fallback'ом "без
    //                токена", но с августа 2026 GVS-токен требуется и ему
    //                (https://github.com/yt-dlp/yt-dlp/issues/17348), а на практике
    //                не отдаёт даже формат 18. Выкинут.
    //   web_safari - "Missing required Visitor Data", только картинки.
    //   tv         - "The page needs to be reloaded" (yt-dlp#17389). Проверен и с
    //                подключёнными куками - тот же отказ, так что в ретрей с куками
    //                его тоже не ставим. Без кук вдобавок все форматы DRM (#12563).
    //   tv_simply  - бот-чек, куки не поддерживает вовсе.
    // Замер повторить, если YouTube снова всё поломает: команда в docs.
    public const YOUTUBE_PLAYER_CLIENTS = 'mweb,web_embedded';

    public static function probeRouting(string $url): array
    {
        $site = self::detectCookiesSite($url);
        $cookiesFile = self::cookiesFileForSite($site);
        if ($cookiesFile !== '' && !self::cookiesFileUsable($cookiesFile)) {
            $cookiesFile = '';
        }

        return [
            'useProxy'    => !self::isDirectAccessHost(self::getHostStatic($url)),
            'cookiesFile' => $cookiesFile,
            'host'        => self::getHostStatic($url),
        ];
    }

    // Тот же перебор, что в isDirectAccessDomain(), но от готового хоста -
    // статическим вызовам (проба) экземпляр Downloader ни к чему.
    private static function isDirectAccessHost(string $hostname): bool
    {
        foreach (self::DIRECT_ACCESS_DOMAINS as $domain) {
            if ($hostname === $domain || str_ends_with($hostname, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    // Потолок соединений к одному хосту. Выше не поднимать: дальше начинается
    // залп, а не загрузка. Ниже держать незачем - на YouTube, где ловили бот-чек,
    // ускорение теперь не включается вовсе.
    private const CONNECTION_BUDGET = 4;

    // Задача уступает диск и процессор веб-морде. Мукс гигабайтного файла в mp4 -
    // это ffmpeg, копирующий его с диска на диск; PHP-FPM живёт на том же диске и
    // переставал получать свою очередь - сайт отвечал "Не удалось обновить данные",
    // пока шёл постпроцессинг. Класс best-effort с худшим приоритетом, а не idle:
    // запись живого эфира обязана успевать за потоком, её нельзя морить голодом.
    // ionice и setsid - из одного пакета util-linux, отдельной зависимости нет.
    // Понижать приоритет непривилегированному процессу можно, повышать нельзя -
    // прав контейнера хватает.
    private const NICE_PREFIX = 'nice -n 10 ionice -c 2 -n 7 ';

    // Сколько соединений позволить ЭТОЙ задаче. Константой нельзя: max_dl
    // настраивается, dispatchGroup() поднимает процесс на каждый хост отправки,
    // очередь продвигается сразу после освобождения слота - соединения сложились
    // бы в залп к одному CDN.
    //
    // Гонка одновременного старта принята: две задачи возьмут по полному бюджету.
    // flock ради этого усложнил бы dispatchGroup(), а короткий всплеск не тот
    // залп, что вызывал бот-чек.
    private static function connectionBudget(string $host, ?array $fileList = null): int
    {
        if ($host === '') {
            return 1;
        }

        // Хост уже отвечал 429. Свежесть отметки не проверяем намеренно: первая
        // задача после кулдауна тоже идёт в один поток.
        $cooldown = self::cooldownFile($host);
        if ($cooldown !== null && file_exists($cooldown)) {
            return 1;
        }

        // Свой pid-файл ещё не записан (пишется после exec), поэтому любая
        // найденная живая задача - чужая.
        if (self::background_jobs($fileList) > 0) {
            return 1;
        }

        // Ждущие эфира задачи в background_jobs() не считаются, но соединение к
        // хосту при старте эфира откроют.
        $logPath = $GLOBALS['config']['logPath'] ?? '';
        $pidFiles = $fileList['pid'] ?? (glob($logPath . '/pid_*') ?: []);
        foreach ($pidFiles as $pidFile) {
            $lines = explode("\n", (string) @file_get_contents($pidFile));
            $jpid = trim($lines[0] ?? '');
            if ($jpid === '' || !file_exists('/proc/' . $jpid)) {
                continue;
            }
            // Третья строка pid-файла - исходные URL задачи через запятую
            foreach (explode(',', trim($lines[2] ?? '')) as $url) {
                if ($url !== '' && self::getHostStatic($url) === $host) {
                    return 1;
                }
            }
        }

        return self::CONNECTION_BUDGET;
    }

    // Промежуточные потоки yt-dlp: "<имя>.f137.mp4", "<имя>.f399-sr.mp4",
    // "<имя>.f140-drc.m4a". После склейки yt-dlp убирает их сам, но если второе
    // плечо отвалилось (403 на аудио - обычное дело на бот-детекте YouTube),
    // первое остаётся на диске законченным файлом. Считать его результатом
    // нельзя: задача показывалась "Готово", авторетреи молчали, а в списке
    // висело видео без звука.
    private const INTERMEDIATE_STREAM_RE = '/\.f[0-9]+(?:-[a-z0-9]+)?\.[a-z0-9]{2,4}$/i';

    // Что задача писала на диск, по её же логу. Раньше лог разбирали три места
    // (проверка результата, уборка и ffprobe), каждое по-своему - правила
    // расходились.
    //   final        - итоговые файлы, по ним судим об успехе;
    //   intermediate - промежуточные потоки, см. INTERMEDIATE_STREAM_RE;
    //   viaFfmpeg    - запись шла через ffmpeg (обрывается на полуслове);
    //   mergePlanned - выбирались отдельные видео и аудио, то есть в итоговом
    //                  файле обязана быть звуковая дорожка.
    private static function collectLogTargets(string $logFile): array
    {
        $res = ['final' => [], 'intermediate' => [], 'viaFfmpeg' => false, 'mergePlanned' => false];

        $handle = @fopen($logFile, 'r');
        if (!$handle) {
            return $res;
        }

        $final = [];
        $intermediate = [];
        while (($line = fgets($handle)) !== false) {
            if (!$res['viaFfmpeg'] && strpos($line, 'frame=') !== false && strpos($line, 'size=') !== false) {
                $res['viaFfmpeg'] = true;
            }
            if (!$res['mergePlanned'] && preg_match('/Downloading \d+ format\(s\):\s*\S+\+\S+/', $line)) {
                $res['mergePlanned'] = true;
            }
            // "Destination:" - обычная загрузка, "Merging formats into" - склейка
            // видео и звука, у неё имя итогового файла только в этой строке.
            if (($pos = strpos($line, 'Destination:')) !== false) {
                $target = trim(substr($line, $pos + 12));
                if ($target === '') continue;
                if (preg_match(self::INTERMEDIATE_STREAM_RE, $target)) {
                    $intermediate[$target] = true;
                } else {
                    $final[$target] = true;
                }
            } elseif (preg_match('/Merging formats into "([^"]+)"/', $line, $m)) {
                $final[$m[1]] = true;
            }
        }
        fclose($handle);

        $res['final'] = array_keys($final);
        $res['intermediate'] = array_keys($intermediate);
        return $res;
    }

    // Путь внутри outputFolder и файл существует. false - и то, и другое неправда.
    private static function realTargetPath(string $target, string $realFolder)
    {
        $real = realpath($target);
        if ($real === false || strpos($real, $realFolder . '/') !== 0 || !is_file($real)) {
            return false;
        }
        return $real;
    }

    // Файл на диске есть - значит задача сделала своё дело, что бы ни мелькало в
    // логе по дороге. Без этой проверки успешная загрузка, во время которой
    // yt-dlp сам пережил 503 или тайм-аут фрагмента и докачал файл, считалась
    // проваленной и уезжала на повторную попытку через прокси.
    private static function jobProducedFile(string $logFile): bool
    {
        $folder = $GLOBALS['config']['outputFolder'] ?? '';
        $realFolder = ($folder === '') ? false : realpath($folder);
        if ($realFolder === false) {
            return false;
        }

        foreach (self::collectLogTargets($logFile)['final'] as $target) {
            if (self::realTargetPath($target, $realFolder) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function autoRetryIfNeeded($completefile)
    {
        if (!file_exists($completefile)) {
            return null;
        }

        if (self::jobProducedFile($completefile)) {
            return null;
        }

        $log_content = @file_get_contents($completefile);
        if ($log_content === false) {
            return null;
        }

        if (strpos($log_content, '[RETRY_ATTEMPTED:') !== false) {
            return null;
        }

        // Сама эта задача уже была автоповтором (метка живёт в [ytcmd], см. restart_download()) -
        // повтор не помог, вторую попытку не даём, чтобы детерминированная ошибка не зациклилась.
        // escapeshellarg() оборачивает значение в кавычки ('1' на Linux) - искать
        // нужно без "=1" на конце, иначе гвард никогда не совпадёт с тем, что
        // реально пишет restart_download(), и одна и та же задача ретраится
        // раз за разом, накапливая противоречащие друг другу флаги (см. случай
        // "--cookies" + "player_client=android_vr" одновременно: второй клиент
        // кук не поддерживает и yt-dlp отказывается от всех клиентов разом).
        if (strpos($log_content, 'YTDL_AUTORETRIED') !== false) {
            return null;
        }

        $jobstatus = self::parseYtDlpError($log_content);

        if (!self::isRetryableError($jobstatus)) {
            return null;
        }

        // Занятость слота тут НЕ проверяется намеренно. Раньше проверялась, и при max_dl = 1
        // плейлист (поштучные задачи, слот занят всегда) не получал авторетрея вовсе: маркер не
        // писался, а processScheduledRetries() ищет именно его - второго шанса не было никогда.
        // Слот проверяет сам processScheduledRetries() на каждом опросе и ждёт, пока освободится.

        // Не рестартуем сразу - откладываем на RETRY_SCHEDULE_DELAY секунд (см. processScheduledRetries()),
        // иначе синтетическая строка "пробую..." живёт один опрос (~1.5с) и пользователь не успевает её
        // прочитать: на следующем опросе новый job_ уже жив и его реальный статус перебивает сообщение.
        $retry_marker = "[RETRY_ATTEMPTED:" . time() . ":proxy] Авторетрей через прокси\n";
        @file_put_contents($completefile, $retry_marker, FILE_APPEND);

        return null;
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
        if (preg_match('/(tiktok\.com|vm\.tiktok\.com|vt\.tiktok\.com|douyin\.com)/i', $url)) {
            return 'tiktok';
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

    // Можно ли отдать закачку aria2c. Четыре запрета:
    //  - прокси: SOCKS5 у aria2 нет вообще, он пошёл бы мимо него;
    //  - живой эфир и метка времени: там качает ffmpeg, на нём завязаны
    //    фрагментированный mp4, salvagePartialVideo() и --download-sections;
    //  - YouTube: отсекается первым запретом сам собой, но не молча.
    private static function canUseExternalDownloader(array $urls, bool $useProxy, ?int $startSeconds): bool
    {
        if (empty($GLOBALS['config']['externalDownloader']) || $useProxy || $startSeconds !== null) {
            return false;
        }
        foreach ($urls as $url) {
            if (self::isLiveStreamUrl($url) || preg_match('/(youtube\.com|youtu\.be)/i', $url)) {
                return false;
            }
        }
        return !empty($urls);
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
            case 'tiktok':
                return $GLOBALS['config']['tiktokCookiesFile'] ?? '';
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

        // Та же защита, что у ретрея через прокси: файл получен - задача удалась
        if (self::jobProducedFile($completefile)) {
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

        // Сама эта задача уже была автоповтором (метка живёт в [ytcmd], см. restart_download()) -
        // повтор не помог, вторую попытку не даём, чтобы детерминированная ошибка не зациклилась.
        // Без "=1": escapeshellarg() кавычит значение, см. подробный комментарий в autoRetryIfNeeded().
        if (strpos($log_content, 'YTDL_AUTORETRIED') !== false) {
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

        // Слот тут не проверяем - см. autoRetryIfNeeded().

        // Откладываем сам рестарт - см. комментарий у автопроксийного ретрея выше.
        $retry_marker = "[RETRY_ATTEMPTED:" . time() . ":cookies] Авторетрей с куками (" . ($site ?? '?') . ")\n";
        @file_put_contents($completefile, $retry_marker, FILE_APPEND);

        return null;
    }

    // Клиент, который встаёт вместо mweb при "video data 403": смысл ретрея - уйти
    // с клиента, чей PO-токен отбил CDN, на тот, которому GVS-токен не нужен вовсе.
    // Раньше тут стоял android_vr - см. YOUTUBE_PLAYER_CLIENTS, почему он больше
    // на эту роль не годится. Один клиент, не список: подмена в restart_download()
    // должна быть однозначной, иначе yt-dlp снова начнёт с mweb.
    private const YOUTUBE_RETRY_CLIENT = 'web_embedded';

    // "unable to download video data: ... HTTP Error 403" - НЕ страница/метаданные (те уже получены), а сам
    // CDN отбил ссылку на конкретный клиентский PO-токен. Детерминированная ошибка (GitHub issues yt-dlp
    // #16144, #14421 - токен привязан к video ID/клиенту): повтор той же команды не помогает и раньше
    // зацикливался (см. YTDL_AUTORETRIED). Вместо повтора - другой клиент без PO-токена вовсе.
    // "Got error: HTTP Error 403" - тот же отказ, но пойманный на фрагменте уже
    // начавшейся закачки. Предупреждение "Unable to download webpage: HTTP Error 403"
    // сюда намеренно не попадает: страницу yt-dlp добирает через API и едет дальше.
    private const VIDEO_DATA_403_PATTERN = '/unable to download video data.*HTTP Error 403|Got error: HTTP Error 403/i';

    private static function autoRetryWithAltClientIfNeeded($completefile)
    {
        if (!file_exists($completefile)) {
            return null;
        }

        if (self::jobProducedFile($completefile)) {
            return null;
        }

        $log_content = @file_get_contents($completefile);
        if ($log_content === false) {
            return null;
        }

        if (strpos($log_content, '[RETRY_ATTEMPTED:') !== false) {
            return null;
        }

        // Без "=1": escapeshellarg() кавычит значение, см. подробный комментарий в autoRetryIfNeeded().
        if (strpos($log_content, 'YTDL_AUTORETRIED') !== false) {
            return null;
        }

        if (!preg_match(self::VIDEO_DATA_403_PATTERN, $log_content)) {
            return null;
        }

        $jobUrl = '';
        if (preg_match('/^\[yturl\]\s*(.+)$/m', $log_content, $urlMatch)) {
            $jobUrl = trim(explode(',', $urlMatch[1])[0]);
        }
        // Подмена клиента в restart_download() завязана на "--extractor-args 'youtube:...'" -
        // фикс имеет смысл только для youtube-ссылок, у остальных сайтов такого аргумента нет.
        if (self::detectCookiesSite($jobUrl) !== 'youtube') {
            return null;
        }

        // Слот тут не проверяем - см. autoRetryIfNeeded().

        // Откладываем сам рестарт - см. комментарий у автопроксийного ретрея выше.
        $retry_marker = "[RETRY_ATTEMPTED:" . time() . ":altclient] Авторетрей другим клиентом (" . self::YOUTUBE_RETRY_CLIENT . ")\n";
        @file_put_contents($completefile, $retry_marker, FILE_APPEND);

        return null;
    }

    // Сколько ждать между обнаружением провала и фактическим стартом авторетрея - чтобы
    // пользователь успел прочитать статус ("Пробую другим клиентом..." и т.п.) до того, как
    // новая задача перебьёт его собственным прогрессом.
    private const RETRY_SCHEDULE_DELAY = 10;

    private const RETRY_TYPE_LABELS = [
        'proxy'     => 'Первая попытка не прошла, пробую через прокси',
        'cookies'   => 'Обычный способ заблокирован, пробую с куками аккаунта',
        'altclient' => 'Ссылка на поток не подошла, пробую другим клиентом YouTube',
    ];

    // Запускает отложенные автоповторы, чей RETRY_SCHEDULE_DELAY уже истёк. Вызывается на
    // каждом опросе (см. index.php, рядом с process_queue()) - не только там, где задача
    // только что упала, а на любом дальнейшем опросе, пока не придёт время рестарта.
    public static function processScheduledRetries(?array $fileList = null): void
    {
        $entries = $fileList['ytdl'] ?? self::scanLogPath()['ytdl'] ?? [];

        foreach ($entries as $filepath) {
            if (strpos(basename($filepath), '_cancelled') !== false) {
                continue;
            }

            $content = @file_get_contents($filepath);
            if ($content === false) {
                continue;
            }

            if (!preg_match('/\[RETRY_ATTEMPTED:(\d+):(proxy|cookies|altclient)\]/', $content, $m)) {
                continue;
            }

            if (time() - (int) $m[1] < self::RETRY_SCHEDULE_DELAY) {
                continue; // Ещё не время
            }

            if (!self::canSpawnRetry()) {
                continue; // Слот занят другой задачей - попробуем на следующем опросе
            }

            $fname = basename($filepath);
            $newpid = match ($m[2]) {
                'proxy' => self::restart_download($fname, true),
                'cookies' => self::restart_download($fname, false, true),
                'altclient' => self::restart_download($fname, false, false, true),
                default => false,
            };

            // Удаляем старый (упавший) лог только при успехе рестарта - иначе задача осталась бы
            // без следа при провале старта, а не более одной попытки на задачу (сама метка
            // RETRY_ATTEMPTED не даёт запланировать это же ещё раз).
            if ($newpid) {
                @unlink($filepath);
            }
        }
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
        // Отменённую задачу не проверяем: её файл оборван по воле человека, и
        // "не читается плеером" перебило бы честный статус "Отменено".
        if (substr($completefile, -10) !== '_cancelled') {
            self::verifyPlayable($completefile);
            // Счётчик в state/: постпроцессор LogPluginPP до неудачных задач не
            // доходит, поэтому неудачи считать больше негде.
            // Хост сказал 429 - придерживаем его в очереди, см. process_queue()
            self::rememberHostRefusal($completefile, $urltext);
        }
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

    // Хвосты незавершённой закачки. .part пишет сам yt-dlp, .aria2 (и .part.aria2) -
    // внешний загрузчик: это его файл возобновления, без задачи он бесполезен.
    private const PARTIAL_SUFFIXES = ['.part', '.part.aria2', '.aria2'];

    // Убирает .part одиночно остановленной задачи, если keepPartialFiles = false.
    // "Стоп ВСЕ" сметает все .part в папке разом, одиночному "Стоп" так нельзя -
    // соседние задачи ещё качаются, поэтому цели берём из лога самой задачи.
    // Зовётся строго после finalize_job_log: спасённая запись к этому моменту
    // уже переименована из .part, под удаление попадают только обрубки.
    private static function cleanupPartialFiles(string $logFile): void
    {
        if (!empty($GLOBALS['config']['keepPartialFiles'])) {
            return;
        }

        $folder = $GLOBALS['config']['outputFolder'] ?? '';
        $realFolder = ($folder === '') ? false : realpath($folder);
        if ($realFolder === false) {
            return;
        }

        $log = self::collectLogTargets($logFile);
        $targets = array_merge($log['final'], $log['intermediate']);

        foreach ($targets as $target) {
            foreach (self::PARTIAL_SUFFIXES as $suffix) {
                $realPart = realpath($target . $suffix);
                if ($realPart === false || strpos($realPart, $realFolder . '/') !== 0) {
                    continue;
                }
                @unlink($realPart);
            }
        }

        // Промежуточные потоки убираем только у провалившейся задачи: у успешной
        // их уже нет (склейка удалила сама), а после обрыва на втором плече
        // "<имя>.f137.mp4" оставался на диске и попадал во вкладку "Видео"
        // обычным файлом - видео без звука.
        if (!self::jobProducedFile($logFile)) {
            foreach ($log['intermediate'] as $target) {
                $real = self::realTargetPath($target, $realFolder);
                if ($real !== false) {
                    @unlink($real);
                }
            }
        }
    }

    // yt-dlp умеет вернуть нулевой код, оставив обрубок: сеть отвалилась на
    // последних байтах, постпроцессор упал, диск кончился. Прогоняем ffprobe
    // (он уже в образе рядом с ffmpeg) и, если файл не читается, дописываем в лог
    // строку ERROR - её подхватывает parseYtDlpError, и задача честно становится
    // неудачной вместо "Готово" с битым файлом.
    private static function verifyPlayable(string $logFile): void
    {
        $folder = $GLOBALS['config']['outputFolder'] ?? '';
        $realFolder = ($folder === '') ? false : realpath($folder);
        if ($realFolder === false) {
            return;
        }

        // viaFfmpeg: запись трансляции идёт через ffmpeg и часто обрывается на
        // полуслове - длительности в таком файле нет, зато кадры есть. Проверять
        // его как обычный файл значило бы штамповать провал на только что
        // спасённой записи.
        $log = self::collectLogTargets($logFile);

        foreach ($log['final'] as $target) {
            $real = self::realTargetPath($target, $realFolder);
            if ($real === false) {
                continue;
            }
            $readable = self::probeReadable($real, $log['viaFfmpeg']);
            // null - проверка не уложилась в отведённое время. Молчим: объявить файл
            // битым по таймауту хуже, чем не проверить его вовсе.
            if ($readable === null || $readable) {
                // Файл читается, но склейка планировалась, а звука в нём нет:
                // второе плечо не докачалось (403 на аудио), и yt-dlp оставил
                // немое видео. Молчать нельзя - иначе это "Готово" без звука.
                if ($readable && $log['mergePlanned'] && self::probeHasAudio($real) === false) {
                    file_put_contents(
                        $logFile,
                        "ERROR: итоговый файл без аудиодорожки (второе плечо не докачалось)\n",
                        FILE_APPEND
                    );
                    return;
                }
                continue;
            }
            file_put_contents(
                $logFile,
                "ERROR: файл не читается плеером (проверка ffprobe не нашла ни длительности, ни кадров)\n",
                FILE_APPEND
            );
            return;
        }
    }

    // true - дорожка есть, false - нет, null - проверить не успели. Зовётся только
    // когда звук обязан быть: yt-dlp выбрал отдельные видео и аудио
    // ("Downloading 1 format(s): 137+140"), значит без склейки результат неполон.
    private static function probeHasAudio(string $file): ?bool
    {
        if (!is_file($file)) {
            return null;
        }

        $out = [];
        $code = 0;
        @exec('timeout ' . self::PROBE_TIMEOUT . ' ffprobe -v error -select_streams a:0 '
            . '-show_entries stream=codec_type -of csv=p=0 ' . escapeshellarg($file) . ' 2>/dev/null', $out, $code);
        if ($code === 124) {
            return null;
        }

        return trim(implode('', $out)) !== '';
    }

    // Проверка идёт внутри запроса ?jobs, а у nginx стоит fastcgi_read_timeout 30s:
    // ffprobe, задумавшийся над файлом, который прямо сейчас дописывает ремукс,
    // утаскивал за собой весь опрос, и сайт отвечал "Не удалось обновить данные".
    private const PROBE_TIMEOUT = 8;

    // true - файл читается, false - нет, null - проверить не успели. Для записи через
    // ffmpeg достаточно одного декодируемого кадра, обычному файлу нужна ещё и
    // положительная длительность.
    private static function probeReadable(string $file, bool $framesAreEnough): ?bool
    {
        // Порог намеренно крошечный, не SALVAGE_MIN_BYTES: короткий mp3 весит
        // меньше четверти мегабайта и был бы объявлен битым ни за что.
        if (!is_file($file) || filesize($file) < 1024) {
            return false;
        }

        $common = 'timeout ' . self::PROBE_TIMEOUT . ' ffprobe -v error -analyzeduration 5M -probesize 5M ';
        $quoted = escapeshellarg($file);

        // Один кадр из начала - дешевле, чем полный разбор, и работает на
        // фрагментированном mp4 без корректного завершения
        $out = [];
        $code = 0;
        @exec($common . '-select_streams v:0 -show_entries frame=pkt_size '
            . '-read_intervals "%+#1" -of csv=p=0 ' . $quoted . ' 2>/dev/null', $out, $code);
        if ($code === 124) {
            return null;
        }
        $hasFrames = trim(implode('', $out)) !== '';

        if ($framesAreEnough) {
            return $hasFrames;
        }
        if ($hasFrames) {
            return true;
        }

        $out = [];
        @exec($common . '-show_entries format=duration -of csv=p=0 ' . $quoted . ' 2>/dev/null', $out, $code);
        if ($code === 124) {
            return null;
        }

        // Аудиофайлы кадров видео не имеют - для них решает длительность
        return (float) trim(implode('', $out)) > 0.0;
    }

    // Огрызок моложе минуты не трогаем: файл может дописываться постпроцессором
    // задачи, которая только что закончила качать.
    private const ORPHAN_PART_MIN_AGE = 60;

    // Подчищает .part, оставшиеся без задачи: после docker restart процесс убит,
    // pid-файл остался, а огрызок висел в папке до возрастной чистки. Живые задачи
    // защищены дважды - по возрасту файла и по списку целей из их же логов.
    // Зовётся при обычном рендере страницы, не на каждом опросе ?jobs.
    public static function sweepOrphanPartFiles(): int
    {
        if (!empty($GLOBALS['config']['keepPartialFiles'])) {
            return 0;
        }
        $folder = $GLOBALS['config']['outputFolder'] ?? '';
        $realFolder = ($folder === '') ? false : realpath($folder);
        if ($realFolder === false) {
            return 0;
        }

        // Цели живых задач: их .part трогать нельзя, сколько бы им ни было лет
        $activeTargets = [];
        $logPath = $GLOBALS['config']['logPath'] ?? '';
        $youtubedlExe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';
        foreach (glob($logPath . '/pid_*') ?: [] as $pidFile) {
            $jpid = trim(explode("\n", (string) @file_get_contents($pidFile))[0] ?? '');
            if ($jpid === '' || !file_exists('/proc/' . $jpid)) {
                continue;
            }
            $pidcmd = @file_get_contents('/proc/' . $jpid . '/cmdline');
            if ($pidcmd !== false && strpos($pidcmd, $youtubedlExe) === false) {
                continue;
            }
            $jobLog = $logPath . '/' . str_replace('pid_', 'job_', basename($pidFile));
            $handle = @fopen($jobLog, 'r');
            if (!$handle) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $pos = strpos($line, 'Destination:');
                if ($pos === false) continue;
                $target = trim(substr($line, $pos + 12));
                if ($target !== '') $activeTargets[$target] = true;
            }
            fclose($handle);
        }

        $now = time();
        $removed = 0;
        foreach (self::PARTIAL_SUFFIXES as $suffix) {
            foreach (glob($realFolder . '/*' . $suffix) ?: [] as $part) {
                $target = substr($part, 0, -strlen($suffix));
                if (isset($activeTargets[$target])) {
                    continue;
                }
                $mtime = @filemtime($part);
                if ($mtime === false || ($now - $mtime) < self::ORPHAN_PART_MIN_AGE) {
                    continue;
                }
                $realPart = realpath($part);
                if ($realPart === false || strpos($realPart, $realFolder . '/') !== 0) {
                    continue;
                }
                if (@unlink($realPart)) $removed++;
            }
        }

        return $removed;
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

    // ETA у aria2c приходит как "15s", "1m10s", "1h2m3s". Точность режем: секунды
    // при часах не нужны, а строка статуса и так длинная.
    private static function formatEta(string $eta): ?string
    {
        if (!preg_match_all('/(\d+)([dhms])/', $eta, $m, PREG_SET_ORDER)) {
            return null;
        }
        $units = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;
        foreach ($m as $part) {
            $total += (int) $part[1] * $units[$part[2]];
        }
        if ($total <= 0) {
            return null;
        }
        if ($total < 60) {
            return $total . " сек";
        }
        if ($total < 3600) {
            return intdiv($total, 60) . " мин";
        }
        $hours = intdiv($total, 3600);
        $mins = intdiv($total % 3600, 60);
        return $mins > 0 ? ($hours . " ч " . $mins . " мин") : ($hours . " ч");
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
    private static function scanForCurrentStatus(string $line, string &$listpos, bool &$votPhase, bool &$muxPhase, string &$lastline, string &$verylastline, string &$filename, ?array &$ffmpegProgress = null, ?array &$aria2Progress = null, ?bool &$mp4Phase = null): void
    {
        // С внешним загрузчиком yt-dlp своих процентов не печатает вовсе - без этого
        // статус залипал бы на "Собираю информацию" до конца загрузки. aria2c пишет
        // "[#a1b2c3 12MiB/100MiB(12%) CN:2 DL:1.2MiB ETA:1m10s]", записи разделены \r
        // (как у ffmpeg ниже) - берём последнюю в куске.
        if (strpos($line, '[#') !== false
            && preg_match_all(
                '/\[#\w+\s+([\d.]+)([KMGT]?)i?B\/([\d.]+)([KMGT]?)i?B\((\d+)%\)'
                . '(?:[^\r\n]*?DL:\s*([\d.]+)([KMGT]?)i?B)?'
                . '(?:[^\r\n]*?ETA:\s*(\d+[dhms](?:\d+[hms])*))?/',
                $line,
                $am,
                PREG_SET_ORDER
            )
        ) {
            $last = end($am);
            $mult = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776];
            $aria2Progress = [
                'percent' => (int) $last[5],
                'done' => (float) $last[1] * ($mult[$last[2]] ?? 1),
                'total' => (float) $last[3] * ($mult[$last[4]] ?? 1),
                'speed' => (isset($last[6]) && $last[6] !== '')
                    ? (float) $last[6] * ($mult[$last[7]] ?? 1)
                    : null,
                // ETA у aria2c появляется не сразу и пропадает на паузах - null тут норма
                'eta' => (isset($last[8]) && $last[8] !== '') ? $last[8] : null,
            ];
        }

        // Живые трансляции (Twitch и любой HLS без известной длительности) yt-dlp тянет через ffmpeg,
        // а тот не печатает процентов - только свой прогресс "size= ... time= ...". Без этого статус
        // намертво застревал на "Собираю информацию", хотя файл на диске рос.
        // Строки ffmpeg разделены \r, поэтому ищем все вхождения в куске и берём последнее.
        // Внутри одной записи прогресса ищем через [^\r\n], а не через ".": точка в PCRE не матчит \n,
        // но матчит \r, и жадность утащила бы bitrate из соседней записи.
        $rx = '/size=\s*(\d+(?:\.\d+)?)\s*(k|K|M|G)i?B'
            . '[^\r\n]*?time=\s*(\d+:\d{2}:\d{2})'
            . '(?:[^\r\n]*?bitrate=\s*([\d.]+)\s*kbits\/s)?'
            . '(?:[^\r\n]*?speed=\s*([\d.]+)\s*x)?/';
        if (preg_match_all($rx, $line, $fm, PREG_SET_ORDER)) {
            $last = end($fm);
            $mult = ['k' => 1024, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
            // Скорость приёма = битрейт потока, умноженный на коэффициент опережения реального времени
            // (у живой трансляции speed около 1.00x, но yt-dlp сначала догоняет буфер и там бывает 5x).
            $speed = null;
            if (isset($last[4]) && $last[4] !== '') {
                $factor = (isset($last[5]) && $last[5] !== '') ? (float) $last[5] : 1.0;
                $speed = (float) $last[4] * 1000 / 8 * $factor;
            }
            $ffmpegProgress = [
                'bytes' => (float) $last[1] * $mult[$last[2]],
                'time' => $last[3],
                'speed' => $speed,
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
        // ensure_mp4.sh печатает "[mp4] ...". Отдельный флаг, а не $muxPhase:
        // тот включается и от голого "frame=", то есть от любого ffmpeg, а
        // перекодирование надо отличать от записи эфира - иначе живая
        // трансляция подписывалась бы "привожу к mp4" всё время записи.
        if (strpos($line, '[mp4] привожу') !== false) {
            $mp4Phase = true;
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

                // Ждём отложенного авторетрея (см. processScheduledRetries()) - показываем понятный
                // статус вместо голой причины провала, пока рестарт ещё не запущен. Флаг гасит
                // обе ветки ниже - они иначе безусловно перезаписали бы jobstatus своим разбором.
                $isPendingRetry = false;
                if ($jobstatus !== "Отменено" && $jobstatus !== "Отменено (Уже Загружено)") {
                    $log_content_for_retry = @file_get_contents($filepath);
                    if ($log_content_for_retry !== false
                        && preg_match('/\[RETRY_ATTEMPTED:\d+:(proxy|cookies|altclient)\]/', $log_content_for_retry, $rm)) {
                        $jobstatus = self::RETRY_TYPE_LABELS[$rm[1]] ?? "Пробую ещё раз...";
                        $isPendingRetry = true;
                    }
                }

                if ($isPendingRetry) {
                    $type = $isaudio ? "audio" : "video";
                } elseif ($filename == "Дундук :)") {
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
                    // "Destination:" в логе значит только, что закачка СТАРТОВАЛА - не что она
                    // закончилась. Обрыв посреди файла (сеть, 403 на CDN, крах постобработки)
                    // до этой проверки всё равно показывался "Готово": итог проверялся только
                    // в ветке "Дундук :)" выше, для логов без единой Destination-строки вовсе.
                    // jobProducedFile() - тот же метод, что уже используют все три авторетрея.
                    if ($jobstatus === "Готово" && !self::jobProducedFile($filepath)) {
                        $log_content = @file_get_contents($filepath);
                        // VOT-задачи (перевод) при успехе удаляют исходный файл yt-dlp после мукса в
                        // *_ru.mp4 (см. docs/explanation/06...) - jobProducedFile() по оригинальному
                        // Destination-пути такой файл не найдёт, даже если перевод прошёл отлично.
                        if ($log_content !== false && strpos($log_content, 'vot-cli') === false) {
                            $jobstatus = self::parseYtDlpError($log_content, self::detectCookiesSite($urltext));
                        }
                    }
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
        // === Наш собственный вердикт по файлу на диске (выше всех: он про
        // результат, а не про шум в логе, который задача могла пережить) ===
        ['/итоговый файл без аудиодорожки/u', "Звук не докачался 🔇\nYouTube отдал только видео - попробуй ещё раз"],

        // === Бот-детект (выше всех - часто идёт с 429, но причина именно бот-чек) ===
        // "not a bot" без апострофа матчится всегда и одна тянет всё правило;
        // /u на второй альтернативе - та же причина, что у HOPELESS_WAIT_PATTERNS.
        ['/not a bot|Sign in to confirm you.re not a bot/iu', "YouTube принял нас за бота 🤖\nIP PROXY засвечен - лучше подождать"],

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
            $siteLabel = match ($site) {
                'instagram' => 'Instagram',
                'tiktok' => 'TikTok',
                default => 'YouTube',
            };
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
        $group = escapeshellarg($jpid);

        // SIGINT, а не SIGTERM: и yt-dlp, и ffmpeg обрабатывают его штатно -
        // дописывают заголовки, закрывают файл и выходят. SIGTERM обрывал ffmpeg
        // на полуслове, и запись оставалась с недописанной структурой, годной
        // только через спасателя (см. salvagePartialVideo).
        shell_exec("kill -INT -- -" . $group);

        // Ждём завершения: одиночный "Стоп" может позволить себе подождать дольше,
        // "Стоп ВСЕ" бьёт по многим задачам подряд и ждёт коротко на каждой.
        $waitSteps = $sleepAfterKill ? 20 : 4;   // 2 секунды против 0.4
        for ($i = 0; $i < $waitSteps; $i++) {
            usleep(100000);
            if (!file_exists('/proc/' . $jpid)) {
                return;
            }
        }

        // Не вышел сам - обычное завершение, и только потом добивание.
        shell_exec("kill -- -" . $group);
        usleep(300000);
        if (file_exists('/proc/' . $jpid)) {
            shell_exec("kill -9 -- -" . $group);
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
        self::cleanupPartialFiles($completed);
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
    public static function restart_download($fpid, $forceUseProxy = false, $forceUseCookies = false, $forceAltClient = false)
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

        // Переменные окружения собираем в ОДИН "env"-вызов, а не цепочкой из нескольких -
        // commandLooksValid() на следующем рестарте снимает регэкспом только первый префикс
        // "env VAR=val ...", второй "env" перед бинарником он бы принял за подмену команды.
        $envVars = [];

        // Если исходная задача использовала прокси ИЛИ нас просят принудительно добавить его
        // (как при авторетрее с гео-блоком) - вставляем его из текущего конфига
        if (($usesProxy || $forceUseProxy) && !empty($GLOBALS['config']['socks5'])) {
            $envVars['all_proxy'] = $GLOBALS['config']['socks5'];
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

        // Подмена клиента YouTube при авторетрее на "video data 403" (см. autoRetryWithAltClientIfNeeded()):
        // ссылка на CDN, выданная mweb, привязана к его PO-токену и после провала не спасается повтором той
        // же команды (PO-токен привязан к video ID/клиенту - GitHub issues yt-dlp #16144, #14421).
        // YOUTUBE_RETRY_CLIENT токена не требует вовсе и уже в основном наборе (см. YOUTUBE_PLAYER_CLIENTS) -
        // форсируем только его, чтобы CDN-ссылка выдавалась без PO-токена с самого начала.
        if ($forceAltClient && !$isBashWrapped) {
            $ytcmd = preg_replace(
                '/(--extractor-args\s+)\'youtube:player_client=[^\']*\'/',
                '$1' . "'youtube:player_client=" . self::YOUTUBE_RETRY_CLIENT . "'",
                $ytcmd,
                1
            );
        }

        // Метка "это уже автоповтор" - переживает рестарт вместе с командой (autoRetryIfNeeded()/
        // autoRetryWithCookiesIfNeeded() пишут [RETRY_ATTEMPTED:...] в СТАРЫЙ, уже завершённый лог,
        // а новая задача стартует с чистого job_/pid_ и этой строки не видит - без метки в самой
        // команде детерминированная (не временная) ошибка ретраилась бы на каждом новом провале
        // бесконечно, а не один раз.
        if ($forceUseProxy || $forceUseCookies || $forceAltClient) {
            $envVars['YTDL_AUTORETRIED'] = '1';
        }

        if (!empty($envVars)) {
            $envPrefix = 'env';
            foreach ($envVars as $envName => $envValue) {
                $envPrefix .= ' ' . $envName . '=' . escapeshellarg($envValue);
            }
            $ytcmd = $envPrefix . ' ' . $ytcmd;
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
            'setsid ' . self::NICE_PREFIX . 'bash -c %s > %s/%s 2>&1 & echo $! > %s/%s',
            escapeshellarg($ytcmd),
            $logPath,
            $fno,
            $logPath,
            $fnp
        );

        exec($cmd);
        self::rememberHostSpawn($urltext);

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

    // Ссылки приходят как придётся: через "||" (формат dl_queue), переносами строк
    // или просто пробелами. Резать по любому пробелу нельзя - незакодированный пробел
    // ВНУТРИ одной ссылки (".../search?q=привет мир") распался бы на валидный огрызок,
    // который ушёл бы качаться не туда, и мусорный хвост. Поэтому пробел считается
    // разделителем, только когда сразу за ним идёт "http://" или "https://" - внутри
    // одной ссылки такого быть не может, а is_valid_url() других схем и не принимает.
    // Запятая разделителем НЕ считается: она легальна в пути и query ("?list=a,b").
    private static function splitUrls($raw): array
    {
        $urls = [];
        foreach (explode('||', (string) $raw) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            foreach (self::extractUrls($chunk) as $extracted) {
                $extracted = trim($extracted);
                if ($extracted !== '') $urls[] = $extracted;
            }
        }
        return $urls;
    }

    // Ссылку часто копируют вместе с куском переписки ("[14:03] Петя: смотри https://... огонь").
    // Вытаскиваем ссылки регекспом, остальное отбрасываем - но только там, где это
    // однозначно проза: ссылок несколько, либо перед первой есть текст. Единственная
    // ссылка в начале строки с хвостом после пробела остаётся целой: отличить прозу
    // от незакодированного пробела ВНУТРИ ссылки (".../search?q=привет мир") здесь
    // нечем, а обрезка молча увела бы загрузку не туда. Такая строка, как и раньше,
    // честно отвергается is_valid_url().
    private static function extractUrls(string $text): array
    {
        if (filter_var($text, FILTER_VALIDATE_URL) !== false) {
            return [$text];
        }

        if (!preg_match_all('~https?://\S+~i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            // Ссылок не видно - отдаём как есть, чтобы ошибку сформулировал is_valid_url()
            return [$text];
        }

        $junkBefore = trim(substr($text, 0, $matches[0][0][1])) !== '';
        if (count($matches[0]) === 1 && !$junkBefore) {
            return [$text];
        }

        $found = [];
        foreach ($matches[0] as $match) {
            // Хвостовая пунктуация из текста: "...смотри https://site/video." или "(https://site/v)"
            $url = rtrim($match[0], '.,;:!?)]»"\'');
            if ($url !== '') $found[] = $url;
        }

        return $found ?: [$text];
    }

    // Прямая ссылка на файл (".../clip.mp4", ".../track.mp3"): экстрактора там нет,
    // yt-dlp скачивает файл как есть через generic. Перебор форматов и вшивание
    // обложки к такому файлу неприменимы - выбирать не из чего, вшивать нечего.
    // А вот ремукс применим и обязателен: правило "на выходе всегда mp4" на
    // прямые ссылки не распространялось вовсе, и ".../clip.webm" оседал на диске
    // как .webm. Видео и аудио разведены именно поэтому - контейнер mp4 нужен
    // видеофайлу, а прямой ссылке на .mp3 он не нужен и вреден.
    private const DIRECT_MEDIA_VIDEO_EXTENSIONS = [
        'mp4', 'mkv', 'webm', 'mov', 'avi', 'ts', 'm4v', 'flv',
    ];
    private const DIRECT_MEDIA_AUDIO_EXTENSIONS = [
        'mp3', 'm4a', 'aac', 'opus', 'ogg', 'oga', 'flac', 'wav', 'wma',
    ];
    private const DIRECT_MEDIA_EXTENSIONS = [
        ...self::DIRECT_MEDIA_VIDEO_EXTENSIONS,
        ...self::DIRECT_MEDIA_AUDIO_EXTENSIONS,
    ];

    private static function directMediaExtension(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::DIRECT_MEDIA_EXTENSIONS, true) ? $ext : null;
    }

    // Секунды из таймкода ссылки: "t=1234", "t=1h2m3s", "start=90". null, если
    // таймкода нет или он нулевой (качать "с нулевой секунды" незачем).
    private static function startTimeSeconds(string $url): ?int
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $params);

        foreach (['t', 'start'] as $key) {
            $raw = $params[$key] ?? null;
            if (!is_string($raw) || $raw === '') continue;

            if (ctype_digit($raw)) {
                $seconds = (int) $raw;
            } elseif (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/i', $raw, $m) && $raw !== '') {
                $seconds = ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));
            } else {
                continue;
            }

            if ($seconds > 0) return $seconds;
        }

        return null;
    }

    private static function allDirectMedia(array $urls): bool
    {
        if (empty($urls)) {
            return false;
        }
        foreach ($urls as $url) {
            if (self::directMediaExtension($url) === null) {
                return false;
            }
        }
        return true;
    }

    // Вся пачка - прямые ссылки на ВИДЕОфайлы. Отдельно от allDirectMedia(),
    // потому что ремукс в mp4 осмыслен только тут: прямой .mp3 переупаковывать
    // некуда и незачем.
    private static function allDirectVideo(array $urls): bool
    {
        if (empty($urls)) {
            return false;
        }
        foreach ($urls as $url) {
            $ext = self::directMediaExtension($url);
            if ($ext === null || !in_array($ext, self::DIRECT_MEDIA_VIDEO_EXTENSIONS, true)) {
                return false;
            }
        }
        return true;
    }

    // $checkNetwork = false отключает резолв хоста (SSRF-проверку), оставляя разбор
    // самой строки. Нужно там, где ссылка уже проходила полную проверку при приёме:
    // process_queue() перебирает всю очередь на КАЖДОМ опросе ?jobs, и резолв
    // превращался в два DNS-запроса на ссылку раз в полторы секунды - задержка
    // ответа плюс, при моргнувшем DNS, молчаливый выброс задач из очереди.
    // static, чтобы ту же проверку могла звать проба плейлиста (PlaylistProbe) -
    // $this здесь и не использовался. Существующие вызовы через $this-> законны.
    private static function is_valid_url($url, bool $checkNetwork = true)
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        // Только http/https - иначе yt-dlp можно скормить file://, ftp:// и прочие
        // схемы, дающие доступ к локальным путям сервера
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        if (!$checkNetwork) {
            return true;
        }
        return !self::pointsToInternalHost(parse_url($url, PHP_URL_HOST) ?? '');
    }

    // SSRF: без проверки yt-dlp сходит по ссылке за нас, и адрес вида
    // http://169.254.169.254/latest/meta-data/ или http://192.168.1.1/ превращает
    // Качалку в разведчика по внутренней сети хоста (ответ виден в логе задачи).
    // Резолв обязателен - имя вроде localtest.me указывает на 127.0.0.1.
    // Неразрешимое имя пропускаем: домены, закрытые для хоста, но доступные
    // через SOCKS5, локально не резолвятся, и отказ ломал бы обычные загрузки.
    // Результаты резолва на время запроса: пачка ссылок с одного хоста иначе
    // тянула бы DNS столько раз, сколько в ней ссылок.
    private static $internalHostCache = [];

    private static function pointsToInternalHost(string $host): bool
    {
        $host = trim(strtolower($host), " \t\n\r\0\x0B.[]");
        if ($host === '') {
            return true;
        }
        if (array_key_exists($host, self::$internalHostCache)) {
            return self::$internalHostCache[$host];
        }
        return self::$internalHostCache[$host] = self::resolveInternalHost($host);
    }

    private static function resolveInternalHost(string $host): bool
    {
        if ($host === 'localhost' || substr($host, -6) === '.local' ||
            substr($host, -10) === '.localhost' || substr($host, -8) === '.internal') {
            return true;
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } elseif (ctype_digit($host)) {
            // http://2130706433/ - тот же 127.0.0.1 одним числом, yt-dlp его развернёт
            $addresses[] = long2ip((int) $host);
        } else {
            foreach ([DNS_A, DNS_AAAA] as $type) {
                $records = @dns_get_record($host, $type);
                if (!is_array($records)) {
                    continue;
                }
                foreach ($records as $record) {
                    if (isset($record['ip'])) $addresses[] = $record['ip'];
                    if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
                }
            }
        }

        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
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
        return self::isDirectAccessHost($this->getHost($url));
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
        $urls = self::splitUrls($onedownload['url']);

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
        // Предыдущая задача к этому хосту стартовала только что - откладываем, не
        // отказываем. Проверка выше лимита: она не про слоты, а про интервал, и
        // при max_dl = -1 (лимита нет) нужна тем более. disableQueue отложить
        // некуда - там пускаем как есть, залп меньшее зло, чем потерянная задача.
        if (!$this->config["disableQueue"] && self::hostSpawnGapLeft(self::getHostStatic($groupUrls[0])) > 0) {
            $groupDownload = $onedownload;
            $groupDownload['url'] = implode('||', $groupUrls);
            $this->addToQueue($groupDownload);
            $this->queuedUrls = array_merge($this->queuedUrls, $groupUrls);
            return;
        }

        if ($this->config["max_dl"] == -1) {
            $this->executeDownload($onedownload, $groupUrls, $useProxy, $paceRequests);
            $this->startedUrls = array_merge($this->startedUrls, $groupUrls);
            return;
        }

        // background_jobs() кэширован на запрос, но инкрементируется сразу после exec() - видит процессы, запущенные более ранними группами этого же запроса.
        if (self::background_jobs() < $this->config["max_dl"]) {
            $this->executeDownload($onedownload, $groupUrls, $useProxy, $paceRequests);
            $this->startedUrls = array_merge($this->startedUrls, $groupUrls);
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
        $this->queuedUrls = array_merge($this->queuedUrls, $groupUrls);
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
        // Музыке даём человеческое имя "Исполнитель - Трек", когда теги есть.
        // Синтаксис yt-dlp: %(artist&{} - |)s печатает "<artist> - " только при
        // непустом artist, иначе ничего; %(track,title)s берёт первое непустое поле.
        // У музыки имя чистое - "Исполнитель - Трек", как в плеере. У видео
        // остаётся "_%(id)s": названия роликов на одном канале часто совпадают
        // ("Стрим #12", "Выпуск 3"), и без хвоста второй файл затёр бы первый.
        // У трека такой беды нет - совпадение имени означает тот же трек.
        $nameTemplate = !empty($onedownload['audio_only'])
            ? "/%(artist&{} - |)s%(track,title)s.%(ext)s"
            : "/%(title)s_%(id)s.%(ext)s";
        $cmd .= " -o " . escapeshellarg($this->download_path . $nameTemplate);
        $cmd .= " --restrict-filenames";

        $directMedia = self::allDirectMedia($urls);

        $sanitizedFormat = $this->sanitizeDlFormat($onedownload['dl_format']);
        if ($directMedia) {
            // У прямой ссылки один-единственный формат - выбирать не из чего.
        } elseif ($sanitizedFormat === 'worst') {
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

        // Ускорение закачки (aria2c и параллельные фрагменты) собирается ниже, после
        // того как известны $isYoutube и $startSeconds - от них зависят обе развилки.

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
            if (!$directMedia) {
                // У сырого аудиофайла ни обложки, ни метаданных источника нет - вшивать нечего
                $cmd .= " --embed-thumbnail --embed-metadata";
            }
            $suffix = "_a";
        } elseif ($directMedia) {
            // Прямая ссылка на видеофайл. Сливать нечего (формат один), ffmpeg
            // файл не тянет - поэтому из блока ниже берём ровно ремукс. Это
            // "ffmpeg -c copy": процессор не греется, качество бит-в-бит, а
            // yt-dlp шаг и вовсе пропускает, когда файл уже mp4.
            if (self::allDirectVideo($urls)) {
                $cmd .= " --remux-video mp4";
            }
        } else {
            $cmd .= " --merge-output-format mp4";
            $cmd .= " --remux-video mp4";
            // Трансляции (Twitch и прочий live-HLS) yt-dlp тянет через ffmpeg, а обычный mp4 без moov-атома
            // в конце файла не открывается вообще - оборванная запись превращалась в мусор. Фрагментированный
            // mp4 пишет заголовки по ходу, поэтому любой обрезок играется. Ключ уходит в выходные аргументы
            // ffmpeg (yt-dlp кладёт голый "ffmpeg:" именно туда) и молча игнорируется, когда качает не ffmpeg.
            $cmd .= " --downloader-args " . escapeshellarg("ffmpeg:-movflags +frag_keyframe+empty_moov+default_base_moof");
            // Переподключение при обрыве связи: без него короткий провал сети
            // заканчивал многочасовую запись. Префикс "ffmpeg_i:" обязателен -
            // это ВХОДНЫЕ аргументы (относятся к чтению потока), а голый "ffmpeg:"
            // выше yt-dlp кладёт в выходные, где они молча ничего не делают.
            $cmd .= " --downloader-args " . escapeshellarg("ffmpeg_i:-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5");
            // Видео: превью пишется отдельным .webp ДО вшивания - если сама загрузка сорвётся
            // (напр. 403 на протухшем URL CDN), файл останется мусорным сиротой, которого не
            // чистит ни cleanupPartialFiles (не суффикс Destination-цели), ни сайт (webp не в
            // списке расширений FileHandler). Обложка у видео не так нужна, как у трека - не вшиваем.
        }

        // Контейнер mp4 - ещё не гарантия воспроизведения: VP9 и Opus кладутся в
        // него законно, но Safari, iOS и QuickTime такой файл не откроют, а у нас
        // и встроенный плеер, и забор по QR на телефон. Скрипт смотрит ffprobe'ом,
        // что внутри, и перекодирует ТОЛЬКО несовместимую дорожку - в типичном
        // случае он стоит одного вызова ffprobe. Через --exec, а не отдельным
        // шагом: задача запускается одним фоновым exec(), вклинить второй процесс
        // некуда, а --exec держит yt-dlp живым до конца скрипта, поэтому pid-файл,
        // статус, verifyPlayable() и finalize_job_log() продолжают работать без
        // единой правки. Имя файла скрипт сохраняет (подмена на месте), так что
        // разбор Destination: и пары "ссылка - файл" на фронте тоже целы.
        if (empty($onedownload['audio_only']) && ($this->config['mp4Compat'] ?? true)) {
            // after_move, а НЕ after_video: у after_video поле %(filepath)q ещё не
            // заполнено, yt-dlp подставляет туда "NA", и скрипт получал вместо
            // пути двухбуквенную заглушку. Ловилось живьём.
            $cmd .= " --exec " . escapeshellarg('after_move:/etc/Scripts/ensure_mp4.sh %(filepath)q');
        }

        $isYoutube = false;
        $isYoutubeMulti = false;
        foreach ($urls as $url) {
            if (self::isYoutubeUrl($url)) {
                $isYoutube = true;
                // Плейлист/канал разворачивается в десятки роликов - нужен сон и между загрузками, не только между HTTP-запросами.
                if (preg_match('#[?&]list=|/playlist|/channel/|/@|/c/|/user/#i', $url)) {
                    $isYoutubeMulti = true;
                }
            }
        }
        // Таймкод из ссылки (&t=1234, t=1h2m3s). yt-dlp сам его не применяет, хотя
        // человек копировал ссылку именно с этого места. Одна ссылка на задачу -
        // иначе непонятно, к какому ролику относится отрезок.
        $startSeconds = (count($urls) === 1) ? self::startTimeSeconds($urls[0]) : null;
        if ($startSeconds !== null) {
            $cmd .= " --download-sections " . escapeshellarg('*' . $startSeconds . '-');
        }

        // Ширина канала. Паузы --sleep-* ниже решают другую задачу - частоту
        // запросов, а не их одновременность, поэтому остаются как были.
        $budget = self::connectionBudget($this->getHost($urls[0]));
        $externalDownloader = self::canUseExternalDownloader($urls, $useProxy, $startSeconds);

        if ($externalDownloader) {
            // --min-split-size=4M - короткие ролики качаются одним соединением
            //   независимо от бюджета: кусок мельче этого дальше не делится.
            // --file-allocation=none - дефолтный prealloc сначала выделяет весь
            //   объём, и гигабайтное видео заметное время просто стоит.
            // --auto-file-renaming=false - иначе повтор создаст file.1.mp4, и
            //   jobProducedFile()/verifyPlayable() не найдут его по Destination:.
            // --lowest-speed-limit - замена --throttled-rate, который при внешнем
            //   загрузчике молчит. Тут соединение обрывается, а не переизвлекается
            //   ссылка, но упавшую задачу подхватит autoRetryIfNeeded().
            $cmd .= " --downloader aria2c";
            $cmd .= " --downloader-args " . escapeshellarg(
                "aria2c:--max-connection-per-server={$budget} --split={$budget} --min-split-size=4M"
                . " --lowest-speed-limit=20K --console-log-level=warn --summary-interval=1"
                . " --auto-file-renaming=false --allow-overwrite=true --file-allocation=none"
            );
        }

        // Параллельные фрагменты. Ключ уже стоял глобально и был снят из-за
        // бот-чека Google - теперь исключён только виновник. $isYoutubeMulti
        // избыточен при !$isYoutube, но оставлен явно: расширят белый список -
        // залп не должен вернуться молча.
        $fragments = (int) ($this->config['concurrentFragments'] ?? 0);
        if ($fragments > 1 && !$isYoutube && !$isYoutubeMulti && !$externalDownloader) {
            $n = min($fragments, 4, $budget * 2);
            if ($n > 1) {
                $cmd .= " --concurrent-fragments " . escapeshellarg((string) $n);
            }
        }

        if ($isYoutube) {
            // sponsorblock-remove режет куски из середины и сдвигает всё, что после:
            // вместе с отрезком по таймкоду это дало бы не то место. Когда отрезок
            // задан - только помечаем главы, ничего не вырезаем.
            $cmd .= $startSeconds !== null ? " --sponsorblock-mark sponsor" : " --sponsorblock-remove sponsor";
            $cmd .= " --extractor-args " . escapeshellarg("youtube:player_client=" . self::YOUTUBE_PLAYER_CLIENTS);

            // Подключились к трансляции на середине - без этого ключа всё, что было
            // до подключения, теряется навсегда. С ним запись идёт с начала эфира.
            // Цена честная: стрим на пятом часу начнётся с закачки пятичасового
            // хвоста, диск заполняется быстрее, готовый файл появится только в
            // конце, а сама задача гарантированно переваливает за окно чистки
            // (её pid-файл защищён, см. 2hourcleanup.sh). Ключ действует только на
            // живой эфир, для обычных роликов yt-dlp его игнорирует.
            // Отключается в конфиге: 'liveFromStart' => false.
            if (($this->config['liveFromStart'] ?? true) && $startSeconds === null) {
                $cmd .= " --live-from-start";
            }

            // Ссылка на анонсированный эфир: вместо ошибки "трансляция ещё не
            // началась" задача ждёт и стартует сама. Ключ действует только на
            // запланированные стримы и премьеры, обычный ролик по нему не ждёт.
            // Диапазон - интервал опроса в секундах.
            if ($this->config['waitForVideo'] ?? true) {
                $cmd .= " --wait-for-video 30-300";
            }
        }

        // YouTube: куки не подключаются к обычной загрузке - аккаунт-based PO-токен запросы требуют Data Sync ID, который без реальной нужды в куках взяться неоткуда, только лишние WARNING. Точечно, только повторной попыткой - см. autoRetryWithCookiesIfNeeded().
        // Instagram и TikTok: наоборот, публичный доступ у yt-dlp часто отсутствует вовсе (приватные аккаунты, IP-блок) - ждать первой неудачной попытки бессмысленно, куки подключаем сразу, если настроены и пригодны.
        $isInstagram = false;
        $isTikTok = false;
        foreach ($urls as $url) {
            $site = self::detectCookiesSite($url);
            if ($site === 'instagram') {
                $isInstagram = true;
            } elseif ($site === 'tiktok') {
                $isTikTok = true;
            }
        }
        if ($isInstagram) {
            $instagramCookiesFile = self::cookiesFileForSite('instagram');
            if (self::cookiesFileUsable($instagramCookiesFile)) {
                $cmd .= " --cookies " . escapeshellarg($instagramCookiesFile);
            }
        }
        if ($isTikTok) {
            $tiktokCookiesFile = self::cookiesFileForSite('tiktok');
            if (self::cookiesFileUsable($tiktokCookiesFile)) {
                $cmd .= " --cookies " . escapeshellarg($tiktokCookiesFile);
            }
        }

        // Пауза - защита от 429/бот-чека. Плейлист/канал YouTube разворачивается в десятки роликов (залп extraction-запросов) -
        // нужна пауза и между загрузками, не только между HTTP-запросами. Одиночный YouTube-ролик тоже получает лёгкую
        // sleep-requests: 429 на самом первом webpage-запросе (см. YOUTUBE_PLAYER_CLIENTS) бьёт по прогретости прокси
        // независимо от того, один ролик грузится или пачка - риск невелик, а пауза короткая.
        if ($isYoutubeMulti) {
            $cmd .= " --sleep-requests 1.5 --sleep-interval 3 --max-sleep-interval 8";
        } elseif ($paceRequests) {
            $cmd .= " --sleep-interval 3 --max-sleep-interval 8 --sleep-requests 1";
        } elseif ($isYoutube) {
            $cmd .= " --sleep-requests 0.5";
        }

        // Скорость просела ниже порога - yt-dlp переизвлекает ссылку и начинает
        // заново. Лечит "висит часами на дохлом соединении CDN", но порог должен
        // быть заведомо ниже honest-скорости через SOCKS5: на 100K загрузка,
        // идущая нормальные 100-150 КБ/с, считалась задушенной и перезапускалась
        // по кругу. 20K - это уже точно не работа, а стоящее соединение.
        // С внешним загрузчиком передачей занят не yt-dlp, и порог тут молчит -
        // его роль играет --lowest-speed-limit в аргументах aria2c выше.
        if (!$externalDownloader) {
            $cmd .= " --throttled-rate 20K";
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
        $cmd = "setsid " . self::NICE_PREFIX . $cmd;
        $cmd .= " > " . escapeshellarg($this->config['logPath'] . "/" . $fno) . " 2>&1 & echo $! > " . escapeshellarg($this->config['logPath'] . "/" . $fnp);

        // putenv не меняет команду/лог - передаёт IP плагину LogPluginPP через окружение, не задевая restart-парсинг
        putenv("CLIENT_IP=" . ($onedownload['client_ip'] ?? 'unknown'));
        exec($cmd);
        self::rememberHostSpawn($urltext);

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
        // Хосты, уже получившие слот в этом же проходе - см. $spawnGapLeft ниже.
        $grantedHosts = [];
        $remaining_urls = [];
        $remainingParsed = [];
        $newDownloads = [];

        foreach ($read['lines'] as $line) {
            $parsed = self::parseQueueLine($line);
            if ($parsed === null) continue;

            // Без резолва: ссылка уже прошла полную проверку при постановке в очередь,
            // а тут строка перебирается заново на каждом опросе (см. is_valid_url).
            if (!$this->is_valid_url($parsed['url'], false)) {
                $this->errors[] = $parsed['urlData'] . " не верный URL, удаляю из списка очереди";
                continue;
            }

            // Хост недавно ответил 429 - не долбимся в него, но и очередь не
            // держим: строка остаётся на месте, а слот забирает следующая задача
            // другого хоста. Строгий FIFO тут сознательно нарушен - при max_dl = 1
            // один придержанный хост иначе останавливал бы вообще всё.
            $host = self::getHostStatic($parsed['url']);
            $cooldownLeft = self::hostCooldownLeft($host);

            // Интервал между стартами к одному хосту (см. YOUTUBE_SPAWN_GAP). Отметка
            // на диске обновится только после exec(), а весь этот цикл отбирает задачи
            // ДО запуска - без $grantedHosts вся пачка одного хоста прошла бы гейт
            // разом, ровно тем залпом, от которого интервал и защищает.
            $spawnGapLeft = self::hostSpawnGapLeft($host);
            if ($spawnGapLeft === 0 && isset($grantedHosts[$host]) && self::isYoutubeUrl($host)) {
                $spawnGapLeft = self::YOUTUBE_SPAWN_GAP;
            }

            // max_dl == -1 нужен отдельным условием - "$currently_running < -1" всегда false, задачи в очереди никогда бы не продвинулись, если лимит сменили на -1 постфактум.
            if ($cooldownLeft === 0 && $spawnGapLeft === 0 && ($this->config["max_dl"] == -1 || $currently_running < $this->config["max_dl"])) {
                $newDownloads[] = array(
                    'url' => $parsed['url'],
                    'dl_format' => $parsed['dl_format'],
                    'audio_only' => $parsed['audio_only'],
                    'audio_format' => $parsed['audio_format'],
                    'client_ip' => $parsed['client_ip'],
                    'translate' => $parsed['translate']
                );
                $currently_running++;
                $grantedHosts[$host] = true;
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