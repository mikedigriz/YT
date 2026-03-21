<?php
class Downloader
{
    private $dl_list = [];
    private $errors = [];
    private $download_path = "";
    private $config = [];

    public function __construct($dl_list)
    {
        // Проверка инициализации глобальной конфигурации
        if (!isset($GLOBALS['config'])) {
            $this->errors[] = "Конфигурация не загружена.";
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
                        $this->errors[] = "\"" . $url . "\" ты в порядке? Поправь ссыль, ну че ты!";
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

    public static function background_jobs()
    {
        if (!function_exists('shell_exec')) {
            return 0;
        }
        // Улучшенный grep, чтобы не считать сам процесс grep
        $cmd = "ps aux | grep -v grep | grep -v \"yt-dlp -U\" | grep yt-dlp | wc -l";
        $output = shell_exec($cmd);
        return (int) trim($output);
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

                while (($line = fgets($handle)) !== false) {
                    if (strpos($line, '[download] Загрузка') !== false) {
                        $listpos = "(" . substr($line, 29) . ")";
                    }
                    if (strpos($line, '[download] Загружаемый плейлист:') !== false) {
                        $playlist = substr($line, 33) . "<br />";
                    }
                    if (trim($line) != "") {
                        $lastline = $line;
                    }
                    $verylastline = $line;
                    if (!$siteset) {
                        $siteset = true;
                        $site = explode(" ", $line)[0];
                        $site = ucfirst(str_replace(["[", "]"], "", $site));
                    }
                    if (strpos($line, 'Destination') !== false) {
                        $pos = strrpos($line, '/');
                        $filename = $pos === false ? $line : substr($line, $pos + 1);
                    }
                }
                fclose($handle);

                if ($filename == "Ща..") {
                    $lastline = "Собираю информацию по сайту";
                } else {
                    $pos = strrpos($lastline, '[download]');
                    $lastline = $pos === false ? "" : trim(substr($lastline, $pos + 11));
                    $filename = urldecode(str_replace("%0A", "", urlencode($filename . " " . $listpos)));
                    if ($isaudio && strpos($verylastline, '[ffmpeg]') !== false) {
                        $lastline = "Конвертирую в аудио, это займет время.";
                    }
                }

                if (strpos($lastline, '100%') !== false || $lastline == "") {
                    $lastline = "В Процессе...";
                }

                $bjs[] = array(
                    'file' => json_encode($playlist . $filename),
                    'site' => json_encode($site),
                    'status' => str_replace("\\n", "", json_encode($lastline)),
                    'type' => json_encode($isaudio ? "audio" : "video"),
                    'pid' => json_encode($fileinfo->getFilename()),
                    'url' => json_encode($urltext)
                );
            }
        }
        return $bjs;
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
        file_put_contents($completefile, "[ytcmd] " . $ytcmd . "\n", FILE_APPEND);
        file_put_contents($completefile, "[yturl] " . $urltext . "\n", FILE_APPEND);
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
                'pid' => json_encode($pid),
                'url' => json_encode($urlParts[0] ?? ''),
                'dl_format' => json_encode($urlParts[1] ?? ''),
                'audio_only' => $audio_only,
                'audio_format' => json_encode($urlParts[3] ?? '')
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

        $bjs = [];
        $logPath = $GLOBALS['config']['logPath'];
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

                while (($line = fgets($handle)) !== false) {
                    if (strpos($line, '[download] Загружаю') !== false) {
                        $tmp = substr($line, 29);
                        $listpos = trim(substr($tmp, strpos($tmp, " of") + 3));
                    }
                    if (strpos($line, '[download] Загружаемый плейлист:') !== false) {
                        $playlist = substr($line, 33);
                    }
                    $verylastline = $line;
                    if (!$siteset) {
                        $siteset = true;
                        $site = ucfirst(str_replace(["[", "]"], "", explode(" ", $line)[0]));
                    }
                    if (strpos($line, '[yturl]') !== false) {
                        $urltext = trim(substr($line, 8));
                    }
                    if (strpos($line, 'Destination') !== false) {
                        $pos = strrpos($line, '/');
                        $filename = $pos === false ? $line : substr($line, $pos + 1);
                    }
                    if (strpos($line, 'has already been downloaded') !== false) {
                        $posEnd   = strpos($line, 'Уже Загружено');
                        $posStart = strrpos($line, '/');
                        $filename = $posStart === false ? $line : substr($line, $posStart + 1, $posEnd - 28);
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
                    if ($log_content && (preg_match('/does not pass filter.*skipping/i', $log_content) || strpos($log_content, 'webpage_url!~=') !== false)) {
                        $jobstatus = "Порнографию я вам не дам";
                    } else {
                        $jobstatus = "Ошибка, не та ссылка, либо отменено !!!";
                    }
                } else {
                    $type = $isaudio ? "audio" : "video";
                }

                $bjs[] = array(
                    'file' => json_encode($filename),
                    'site' => json_encode($site),
                    'status' => str_replace("\\n", "", json_encode($jobstatus)),
                    'type' => json_encode($type),
                    'pid' => json_encode($fileinfo->getFilename()),
                    'url' => json_encode($urltext)
                );
            }
        }
        return $bjs;
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
        
        // Убрано: exec("killall ffmpeg"); - оставляем ОС завершать дочерние процессы

        $folder = $GLOBALS['config']['outputFolder'] ?? '';
        if (!empty($folder) && !$GLOBALS['config']['keepPartialFiles']) {
            foreach (glob($folder . '/*.part') as $file) {
                @unlink($file);
            }
        }
    }

    public static function restart_download($fpid)
    {
        if (!isset($GLOBALS['config']['logPath'])) return;
        
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
            $_SESSION['errors'] = "Лог-файл не найден: $fpid";
            error_log("[YTDL] Restart failed: file not found: $file");
            return;
        }

        $ytcmd = "";
        $urltext = "";
        $handle = fopen($file, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if (($pos = strpos($line, '[ytcmd]')) !== false) {
                    $ytcmd = trim(substr($line, $pos + 8));
                }
                if (($pos = strpos($line, '[yturl]')) !== false) {
                    $urltext = trim(substr($line, $pos + 8));
                }
            }
            fclose($handle);
        }

        if (empty($ytcmd)) {
            $_SESSION['errors'] = "Команда не найдена в логе!";
            return;
        }

        // БЕЗОПАСНОСТЬ: Проверка, что команда начинается с ожидаемого бинарника
        $expectedExe = $GLOBALS['config']['youtubedlExe'] ?? 'yt-dlp';
        if (strpos($ytcmd, $expectedExe) !== 0) {
             $_SESSION['errors'] = "Подозрительная команда в логе. Рестарт отменен.";
             error_log("[YTDL] Security: Command mismatch on restart.");
             return;
        }

        $suffix = (strpos($fpid, "_a") !== false || strpos($file, "_a") !== false) ? "_a" : "";

        do {
            $fno = "job_" . uniqid() . $suffix;
        } while (file_exists("$logPath/$fno"));

        $fnp = str_replace("job_", "pid_", $fno);

        $ytcmd = preg_replace('/\s{2,}\'/', '\'', $ytcmd);
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

        file_put_contents("$logPath/$fnp", $ytcmd . "\n", FILE_APPEND);
        file_put_contents("$logPath/$fnp", $urltext . "\n", FILE_APPEND);
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
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
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

    private function do_download()
    {
        foreach ($this->dl_list as $onedownload) {
            if ($this->config["max_dl"] == -1) {
                $this->addOneDownload($onedownload);
            } elseif ($this->config["max_dl"] > 0) {
                if ($this->background_jobs() < $this->config["max_dl"]) {
                    $this->addOneDownload($onedownload);
                } else {
                    if ($this->config["disableQueue"]) {
                        $this->errors[] = "Достигнут лимит одновременных загрузок. " . $onedownload['url'] . " не был загружен.";
                    } else {
                        $this->addToQueue($onedownload);
                    }
                }
            } else {
                $this->errors[] = "Значение max_dl value в config.php указано неверно.";
            }
        }
    }

    public function addOneDownload($onedownload)
    {
        $suffix = "";
        $cmd = $this->config['youtubedlExe'];
        $cmd .= " -o " . escapeshellarg($this->download_path . "/%(title)s_%(id)s.%(ext)s");
        $cmd .= " --restrict-filenames";
        $cmd .= " " . $onedownload['dl_format'];
        
        if (!empty($this->config['socks_proxy'])) {
            $cmd .= " --proxy " . escapeshellarg($this->config['socks_proxy']);
        }
        
        if ($onedownload['audio_only']) {
            $cmd .= " -x";
            $cmd .= " " . $onedownload['audio_format'];
            $suffix = "_a";
        } else {
            $cmd .= " --merge-output-format mp4";
        }

        $fno = $this->getUniqueFileName("job_", $suffix, $this->config['logPath'] . "/");
        $fnp = str_replace("job_", "pid_", $fno);
        $urltext = "";
        
        foreach (explode("||", $onedownload['url']) as $url) {
            $url = trim($url);
            if(!empty($url)){
                $cmd .= " " . escapeshellarg($url);
                $urltext .= $url . ",";
            }
        }
        $urltext = trim($urltext, ",");
        
        $cmd .= " --ignore-errors";
        $logcmd = $cmd;
        $cmd .= " > " . escapeshellarg($this->config['logPath'] . "/" . $fno) . " & echo $! > " . escapeshellarg($this->config['logPath'] . "/" . $fnp);
        
        exec($cmd);
        
        file_put_contents($this->config['logPath'] . "/" . $fnp, $logcmd . "\n", FILE_APPEND);
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

        $currently_running = $this->background_jobs();
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
            $rawUrl = $urlParts[0] ?? '';

            if (!$this->is_valid_url($rawUrl)) {
                $this->errors[] = $urlData . " не верный URL, удаляю из списка очереди.";
                continue; 
            }

            if ($currently_running < $this->config["max_dl"]) {
                $newDownloads[] = array(
                    'url' => $rawUrl,
                    'dl_format' => $urlParts[1] ?? '',
                    'audio_only' => $urlParts[2] ?? '',
                    'audio_format' => $urlParts[3] ?? ''
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
            $this->errors[] = "Файл повредился либо был удален.";
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
        $fcontent = "queueid" . uniqid() . "=" . $onedownload['url'] . ">" . $onedownload['dl_format'] . ">" . $onedownload['audio_only'] . ">" . $onedownload['audio_format'] . "\n";
        
        // LOCK_EX важен здесь
        file_put_contents($queue_file, $fcontent, FILE_APPEND | LOCK_EX);
    }

    public function remove_queued_job($qid)
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
            $_SESSION['errors'] = "Файл очереди повредился либо был удален..";
            return;
        }
        if (count($remaining_urls) === 0) {
            @unlink($queue_file);
        }
    }

    public function remove_all_queued_jobs()
    {
        if (!isset($GLOBALS['config']['logPath'])) return;
        $queue_file = $GLOBALS['config']['logPath'] . "/dl_queue";
        if (file_exists($queue_file)) {
            @unlink($queue_file);
        }
    }
}
?>