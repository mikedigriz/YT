<?php

class FileHandler
{
    private $videos_ext = "avi|mp4|flv|webm|3gp|mkv";
    private $musics_ext = "mp3|ogg|m4a|wav|aac|vorbis|opus";
    private $config = [];

    public function __construct()
    {
          $this->config = $GLOBALS['config'];
    }

    // Окно жизни файла (мин): база для lifetime_percent и таймера в UI.
    // Держи в синхроне с host-cron (2hourcleanup.sh, find -mmin +N).
    private function retention_minutes()
    {
        $m = (int) ($this->config['retentionMinutes'] ?? 120);
        return $m > 0 ? $m : 120;
    }

    public function listVideos()
    {
        return $this->listByExt($this->videos_ext);
    }

    public function listMusics()
    {
        return $this->listByExt($this->musics_ext);
    }

    // Список файлов папки загрузок по маске расширений, с размером, возрастом
    // и остатком жизни. Единая логика для видео и музыки.
    private function listByExt($ext_pattern)
    {
        $files = [];
        if (!$this->output_folder_exists()) {
            return $files;
        }
        $folder = $this->get_downloads_folder() . '/';
        $dir_handle = opendir($folder);
        if ($dir_handle === false) {
            return $files;
        }

        $retention = $this->retention_minutes();

        while (($file = readdir($dir_handle)) !== false) {
            if ($file === "." || $file === "..") continue;
            if (!preg_match('/^.*\.(' . $ext_pattern . ')$/i', $file)) continue;

            $filepath = $folder . $file;
            // Файл мог исчезнуть между readdir и stat (крон-очистка) - пропускаем
            $filemtime = @filemtime($filepath);
            if ($filemtime === false) continue;
            $filesize = @filesize($filepath);
            if ($filesize === false) continue;

            $age_minutes = max(0, floor((time() - $filemtime) / 60));
            $lifetime_percent = max(0, min(100, round((($retention - $age_minutes) / $retention) * 100)));

            $files[] = [
                "name" => $file,
                "size" => $this->to_human_filesize($filesize),
                "age_minutes" => $age_minutes,
                "lifetime_percent" => $lifetime_percent
            ];
        }
        closedir($dir_handle);
        return $files;
    }

    public function delete($id)
    {
        $id = basename($id);
        $folder = rtrim($this->get_downloads_folder(), '/');
        $file = $folder . '/' . $id;
        $real_folder = realpath($folder);
        $real_file = realpath($file);
        if ($real_file && $real_folder && strpos($real_file, $real_folder . '/') === 0 && file_exists($file)) {
            unlink($file);
        } else {
            $_SESSION['errors'] = ["Файл не существует"];
        }
    }

    private function output_folder_exists()
    {
        if(!is_dir($this->get_downloads_folder())) {
            // Папки нет
            if(!mkdir($this->get_downloads_folder(), 0755)) {
                return false; //No folder and creation failed
            }
        }

        return true;
    }

    public function to_human_filesize($bytes, $decimals = 0)
    {
        $sz = 'BKMGTP';
        $factor = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
    }

    public function free_space()
    {
        return $this->to_human_filesize(disk_free_space($this->get_downloads_folder()));
    }

    public function get_free_space_bytes()
    {
        return disk_free_space($this->get_downloads_folder());
    }

    public function get_downloads_folder()
    {
        $path = $this->config["outputFolder"];
        if(strpos($path, "/") !== 0) {
            $path = dirname(__DIR__).'/' . $path;
        }
        return $path;
    }

    public function get_downloads_link()
    {
        $path = $this->config["downloadPath"];
        return $path;
    }
}

?>
