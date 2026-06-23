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

    public function listVideos()
    {
        $videos = [];
        if(!$this->output_folder_exists()) {
            return $videos;
        }
        $folder = $this->get_downloads_folder().'/';
        $dir_handle = opendir($folder);
        while ($file = readdir($dir_handle)) {
            if ($file != "." && $file != "..") {
                if(preg_match('/^.*\.('.$this->videos_ext.')$/i', $file)) {
                    $filepath = $folder . $file;
                    $filemtime = @filemtime($filepath); // @ подавляет ошибки, если файл исчез
                    $age_seconds = $filemtime ? (time() - $filemtime) : 0;
                    $age_minutes = max(0, floor($age_seconds / 60));
                    $lifetime_percent = max(0, min(100, round(((120 - $age_minutes) / 120) * 100)));

                    $videos[] = [
                        "name" => str_replace($folder, "", $file),
                        "size" => $this->to_human_filesize(filesize($filepath)),
                        "age_minutes" => $age_minutes,
                        "lifetime_percent" => $lifetime_percent
                    ];
                }
            }
        }
        closedir($dir_handle);
        return $videos;
    }

    public function listMusics()
    {
        $musics = [];
        if(!$this->output_folder_exists()) {
            return $musics;
        }
        $folder = $this->get_downloads_folder().'/';
        $dir_handle = opendir($folder);
        while ($file = readdir($dir_handle)) {
            if ($file != "." && $file != "..") {
                if(preg_match('/^.*\.('.$this->musics_ext.')$/i', $file)) {
                    $filepath = $folder . $file;
                    $filemtime = @filemtime($filepath);
                    $age_seconds = $filemtime ? (time() - $filemtime) : 0;
                    $age_minutes = max(0, floor($age_seconds / 60));
                    $lifetime_percent = max(0, min(100, round(((120 - $age_minutes) / 120) * 100)));

                    $musics[] = [
                        "name" => $file,
                        "size" => $this->to_human_filesize(filesize($filepath)),
                        "age_minutes" => $age_minutes,
                        "lifetime_percent" => $lifetime_percent
                    ];
                }
            }
        }
        closedir($dir_handle);
        return $musics;
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
            $_SESSION['errors'] = "Файл не существует";
        }
    }

    private function output_folder_exists()
    {
        if(!is_dir($this->get_downloads_folder())) {
            //Folder doesn't exist
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
