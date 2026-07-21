<?php

class FileHandler
{
    private $videos_ext = ['avi', 'mp4', 'flv', 'webm', '3gp', 'mkv'];
    private $musics_ext = ['mp3', 'ogg', 'm4a', 'wav', 'aac', 'vorbis', 'opus'];
    private $config = [];

    public function __construct()
    {
          $this->config = $GLOBALS['config'];
    }

    // База для lifetime_percent и таймера UI. Синхронизировать вручную с host-cron (2hourcleanup.sh, find -mmin +N).
    private function retention_minutes()
    {
        $m = (int) ($this->config['retentionMinutes'] ?? 120);
        return $m > 0 ? $m : 120;
    }

    // Один проход readdir() вместо старых отдельных listVideos()/listMusics() (экономит проход на ?jobs).
    public function listMedia(): array
    {
        $result = ['videos' => [], 'musics' => []];
        if (!$this->output_folder_exists()) {
            return $result;
        }
        $folder = $this->get_downloads_folder() . '/';
        $dir_handle = opendir($folder);
        if ($dir_handle === false) {
            return $result;
        }

        $retention = $this->retention_minutes();

        while (($file = readdir($dir_handle)) !== false) {
            if ($file === "." || $file === "..") continue;

            $dotPos = strrpos($file, '.');
            if ($dotPos === false) continue;
            $ext = strtolower(substr($file, $dotPos + 1));

            $isVideo = in_array($ext, $this->videos_ext, true);
            $isMusic = !$isVideo && in_array($ext, $this->musics_ext, true);
            if (!$isVideo && !$isMusic) continue;

            $filepath = $folder . $file;
            // Файл мог исчезнуть между readdir и stat (крон-очистка) - пропускаем
            $filemtime = @filemtime($filepath);
            if ($filemtime === false) continue;
            $filesize = @filesize($filepath);
            if ($filesize === false) continue;

            $age_minutes = max(0, floor((time() - $filemtime) / 60));
            $lifetime_percent = max(0, min(100, round((($retention - $age_minutes) / $retention) * 100)));
            $pinned = file_exists($filepath . '.keep');

            $entry = [
                "name" => $file,
                "size" => $this->to_human_filesize($filesize),
                "age_minutes" => $age_minutes,
                // Закреплённый файл не тикает - таймер всегда полный.
                "lifetime_percent" => $pinned ? 100 : $lifetime_percent,
                "pinned" => $pinned
            ];

            // mtime для сортировки: readdir() отдаёт файлы не в хронологическом порядке.
            if ($isVideo) {
                $result['videos'][] = ['mtime' => $filemtime, 'entry' => $entry];
            } else {
                $result['musics'][] = ['mtime' => $filemtime, 'entry' => $entry];
            }
        }
        closedir($dir_handle);

        $sortNewestFirst = fn($a, $b) => $b['mtime'] <=> $a['mtime'];
        usort($result['videos'], $sortNewestFirst);
        usort($result['musics'], $sortNewestFirst);
        $result['videos'] = array_column($result['videos'], 'entry');
        $result['musics'] = array_column($result['musics'], 'entry');
        return $result;
    }

    // Путь до маркера "{имя}.keep", тот же realpath-guard, что у delete()/deleteAll(). Null, если имя вне outputFolder или файла нет.
    private function keepMarkerPath(string $name): ?string
    {
        $name = basename($name);
        $folder = rtrim($this->get_downloads_folder(), '/');
        $file = $folder . '/' . $name;
        $real_folder = realpath($folder);
        $real_file = realpath($file);
        if (!$real_file || !$real_folder || strpos($real_file, $real_folder . '/') !== 0) {
            return null;
        }
        return $real_file . '.keep';
    }

    // Маркер "{имя}.keep" уважают и deleteAll(), и хост-cron (2hourcleanup.sh) - иначе закреп защищал бы только от кнопки "Очистить всё".
    public function setPinned(string $name, bool $pinned): bool
    {
        $marker = $this->keepMarkerPath($name);
        if ($marker === null) {
            return false;
        }
        if ($pinned) {
            return touch($marker);
        }
        return !file_exists($marker) || @unlink($marker);
    }

    public function delete($id): bool
    {
        $id = basename($id);
        $folder = rtrim($this->get_downloads_folder(), '/');
        $file = $folder . '/' . $id;
        $real_folder = realpath($folder);
        $real_file = realpath($file);
        if ($real_file && $real_folder && strpos($real_file, $real_folder . '/') === 0 && file_exists($file)) {
            unlink($file);
            return true;
        }
        return false;
    }

    // $type: 'v'/'m' ограничивает категорией (чтобы "Очистить всё" на вкладке Видео не стирала музыку). Пропускает файлы с .keep.
    // $hasActiveJobs: guard по возрасту (< minAgeSeconds) применяется только при активных задачах - иначе постобработка могла дописывать файл; без активных задач guard бессмыслен и раньше блокировал удаление свежих файлов.
    public function deleteAll(string $type = 'all', bool $hasActiveJobs = false): int
    {
        $minAgeSeconds = 60;

        $folder = rtrim($this->get_downloads_folder(), '/');
        $real_folder = realpath($folder);
        if ($real_folder === false) {
            return 0;
        }

        $media = $this->listMedia();
        $files = match ($type) {
            'v' => $media['videos'],
            'm' => $media['musics'],
            default => array_merge($media['videos'], $media['musics']),
        };
        $now = time();
        $deleted = 0;

        foreach ($files as $f) {
            $name = basename($f['name']);
            $file = $folder . '/' . $name;
            $real_file = realpath($file);
            if (!$real_file || strpos($real_file, $real_folder . '/') !== 0 || !file_exists($file)) {
                continue;
            }
            if (file_exists($file . '.keep')) {
                continue;
            }
            if ($hasActiveJobs) {
                $mtime = @filemtime($file);
                if ($mtime === false || ($now - $mtime) < $minAgeSeconds) {
                    continue;
                }
            }
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function output_folder_exists()
    {
        if(!is_dir($this->get_downloads_folder())) {
            if(!mkdir($this->get_downloads_folder(), 0755)) {
                return false;
            }
        }

        return true;
    }

    public function to_human_filesize($bytes, $decimals = 1)
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ', 'ПБ'];
        $factor = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $factor = min($factor, count($units) - 1);
        $decimals = $factor > 0 ? $decimals : 0;
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
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
