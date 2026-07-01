<?php
if (!isset($GLOBALS['config'])) { die("No direct script access"); }

/**
 * Проверка живости SOCKS5-прокси для индикатора в футере.
 *
 * Замер выполняется в фоне (fire-and-forget curl через тот же прокси, что и
 * загрузки), не блокируя ответ страницы/поллинга. Результат каждого замера -
 * одна строка "unix_ts ok" в logPath/proxy_probe.log. По истории строятся три
 * окна: 1, 5 и 15 минут. Окно "work" (зелёное), если за этот период не было ни
 * одного провала; "death" (красное), если был.
 *
 * Прокси нигде не раскрывается: в лог пишутся только timestamp и 0/1, в JSON и
 * футер уходят только булевы статусы и слова work/death. Сама строка прокси
 * живёт лишь в окружении временного curl-процесса (как и при загрузках).
 */
class ProxyStatus
{
    // Нейтральная точка проверки связности: пустой ответ 204, никакого тела,
    // не палит ни нас, ни прокси. Через прокси подтверждает, что канал живой.
    const CHECK_URL = 'https://www.gstatic.com/generate_204';

    // Как часто реально дёргать прокси (сек). Поллинг ходит чаще - лишние вызовы
    // отсекаются по mtime маркера, чтобы не долбить прокси на каждый ?jobs.
    const CHECK_INTERVAL = 60;

    // Таймаут одного замера (сек). Короткий, чтобы фоновый curl не висел.
    const CHECK_TIMEOUT = 6;

    // Горизонт истории и максимальное окно (мин).
    const HISTORY_MINUTES = 15;

    // Доля провалов в окне, до которой это "незначительные пропуски" (жёлтый).
    // Выше - красный. Ноль провалов - зелёный.
    const WARN_RATIO = 0.34;

    // Максимальный размер лога в байтах. Если превышен - ротировать, оставляя 15м.
    const LOG_MAX_SIZE = 102400;

    // Фича индикатора включена в конфиге (независимо от того, задан ли прокси).
    public static function feature_on(): bool
    {
        return !empty($GLOBALS['config']['proxyStatus']);
    }

    // Прокси реально задан - только тогда есть что проверять.
    private static function proxy_set(): bool
    {
        return !empty($GLOBALS['config']['socks5']);
    }

    private static function enabled_config(): bool
    {
        return self::feature_on() && self::proxy_set();
    }

    public static function enabled(): bool
    {
        return self::enabled_config();
    }

    private static function log_path(): string
    {
        return rtrim($GLOBALS['config']['logPath'], '/') . '/proxy_probe.log';
    }

    private static function marker_path(): string
    {
        return rtrim($GLOBALS['config']['logPath'], '/') . '/proxy_probe.at';
    }

    /**
     * Читает историю замеров, отсекая записи старше HISTORY_MINUTES.
     * Возвращает массив [['t' => int, 'ok' => bool], ...] по возрастанию времени.
     */
    private static function read_checks(): array
    {
        $file = self::log_path();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            return [];
        }
        $cutoff = time() - self::HISTORY_MINUTES * 60;
        $checks = [];
        foreach ($raw as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 2) {
                continue;
            }
            $t = (int)$parts[0];
            if ($t < $cutoff || $t > time() + 60) {
                continue;
            }
            $checks[] = ['t' => $t, 'ok' => $parts[1] === '1'];
        }
        return $checks;
    }

    /**
     * Запускает фоновый замер, если с прошлого прошло >= CHECK_INTERVAL.
     * Под неблокирующим flock, чтобы параллельные запросы не плодили процессы.
     * Заодно подрезает историю до горизонта.
     */
    public static function maybe_check(): void
    {
        if (!self::enabled_config()) {
            return;
        }

        $marker = self::marker_path();
        if (is_file($marker) && (time() - @filemtime($marker)) < self::CHECK_INTERVAL) {
            return;
        }

        $lock = @fopen($marker, 'c');
        if ($lock === false) {
            return;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return;
        }
        // Повторная проверка под локом: другой процесс мог только что отметиться.
        clearstatcache(true, $marker);
        if ((time() - @filemtime($marker)) < self::CHECK_INTERVAL && @filesize($marker) !== false && @filesize($marker) > 0) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return;
        }
        @ftruncate($lock, 0);
        @fwrite($lock, (string)time());
        @fflush($lock);
        touch($marker);

        self::launch_probe();
        self::maybe_rotate();

        flock($lock, LOCK_UN);
        fclose($lock);
    }

    // Ротация логов: если файл больше лимита, оставить только свежие 15м.
    private static function maybe_rotate(): void
    {
        $file = self::log_path();
        if (!is_file($file)) {
            return;
        }
        clearstatcache(true, $file);
        if (@filesize($file) < self::LOG_MAX_SIZE) {
            return;
        }

        // Читаем, фильтруем по времени (это не рекурсия, т.к. `read_checks`
        // не вызывает `maybe_rotate`), и перезаписываем компактно.
        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            return;
        }
        $cutoff = time() - self::HISTORY_MINUTES * 60;
        $lines = '';
        foreach ($raw as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 2) continue;
            $t = (int)$parts[0];
            if ($t >= $cutoff) {
                $lines .= $line . "\n";
            }
        }
        @file_put_contents($file, $lines, LOCK_EX);
    }

    /**
     * Фоновый curl через прокси. Пишет одну строку "ts 0|1" в лог.
     * Прокси передаётся через окружение (как в Downloader), в argv скрипта не
     * попадает. Вывод curl отбрасывается - утечь нечему.
     */
    private static function launch_probe(): void
    {
        $proxy = $GLOBALS['config']['socks5'];
        $logFile = self::log_path();
        $timeout = (int)self::CHECK_TIMEOUT;

        $probe = 'code=$(env all_proxy=' . escapeshellarg($proxy)
            . ' curl -s -o /dev/null -m ' . $timeout
            . ' -w %{http_code} ' . escapeshellarg(self::CHECK_URL) . ' 2>/dev/null);'
            . ' if [ "$code" = "204" ] || [ "$code" = "200" ]; then r=1; else r=0; fi;'
            . ' printf "%s %s\n" "$(date +%s)" "$r" >> ' . escapeshellarg($logFile);

        exec('sh -c ' . escapeshellarg($probe) . ' >/dev/null 2>&1 &');
    }

    /**
     * Статусы трёх окон. Значение: 'work' (зелёный, провалов нет),
     * 'warn' (жёлтый, до WARN_RATIO провалов - незначительные пропуски),
     * 'death' (красный, провалов больше), null - данных в окне нет.
     * Если окно пустое - берём общий последний известный результат, чтобы
     * точка не гасла между замерами.
     */
    public static function get_windows(): array
    {
        $checks = self::read_checks();
        $now = time();
        $last = empty($checks) ? null : (end($checks)['ok'] ? 'work' : 'death');

        $result = [];
        foreach ([1, 5, 15] as $w) {
            $from = $now - $w * 60;
            $inWindow = array_filter($checks, fn($c) => $c['t'] >= $from);
            if (empty($inWindow)) {
                $result[(string)$w] = $last;
                continue;
            }
            $total = count($inWindow);
            $fails = 0;
            foreach ($inWindow as $c) {
                if (!$c['ok']) { $fails++; }
            }
            if ($fails === 0) {
                $result[(string)$w] = 'work';
            } elseif ($fails / $total <= self::WARN_RATIO) {
                $result[(string)$w] = 'warn';
            } else {
                $result[(string)$w] = 'death';
            }
        }
        return $result;
    }

    /**
     * Общий статус: work / warn / death / pending (нет данных).
     * Опирается на минутное окно - самое свежее.
     */
    public static function overall_state(array $windows): string
    {
        return $windows['1'] ?? 'pending';
    }

    /**
     * Данные для ?jobs JSON. Пусто, если фича выключена.
     */
    public static function payload(): array
    {
        if (!self::feature_on()) {
            return ['enabled' => false];
        }
        if (!self::proxy_set()) {
            return ['enabled' => true, 'unset' => true];
        }
        $windows = self::get_windows();
        return [
            'enabled' => true,
            'unset'   => false,
            'windows' => $windows,
            'state'   => self::overall_state($windows),
        ];
    }
}
