<?php
// Фоновый воркер: единственный писатель состояния загрузок. Раз в секунду (или
// сразу по пинку из index.php) прибирает мёртвые задачи, двигает очередь,
// запускает ретраи и пишет снапшот ответа ?jobs. Очередь идёт сама, без открытой
// вкладки, а вкладки и устройства больше не разбирают одни файлы наперегонки.
//
// Запускается из start.sh в цикле-перезапускателе. Сам выходит, когда поменялся
// код (dev монтирует class/ живым) или прошёл час (страховка от утечек).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);

$GLOBALS['config'] = include $root . '/config/config.php';

require_once $root . '/class/Downloader.php';
require_once $root . '/class/FileHandler.php';
require_once $root . '/class/ProxyStatus.php';
require_once $root . '/class/JobsSnapshot.php';

const TICK_SECONDS = 1;
const KICK_POLL_US = 200000;
const MAX_LIFETIME = 3600;

$lock = fopen(JobsSnapshot::lockPath(), 'ce');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Уже работает другой экземпляр - не мешаем ему
    exit(0);
}

function codeStamp(string $root): int
{
    $files = array_merge(
        glob($root . '/class/*.php') ?: [],
        [__FILE__, $root . '/config/config.php']
    );
    $stamp = 0;
    foreach ($files as $f) {
        $stamp = max($stamp, (int) @filemtime($f));
    }
    return $stamp;
}

$startedAt = time();
$stamp = codeStamp($root);

while (true) {
    clearstatcache(true);
    // Конфиг перечитываем на каждом проходе: в dev он примонтирован с хоста
    $GLOBALS['config'] = include $root . '/config/config.php';
    Downloader::resetRequestState();
    unset($_SESSION['errors']);

    $taken = JobsSnapshot::takeKick();
    // build() тоже финализирует мёртвые задачи (get_current_background_jobs),
    // поэтому лок держим на весь проход, а не только на advance()
    JobsSnapshot::holdState();
    try {
        $state = JobsSnapshot::advance();
        JobsSnapshot::write(JobsSnapshot::encode(JobsSnapshot::build($state['fileList'], $state['queued'])));
    } catch (Throwable $e) {
        fwrite(STDERR, "[worker] " . get_class($e) . ": " . $e->getMessage() . "\n");
    } finally {
        JobsSnapshot::releaseState();
    }
    if ($taken !== null) {
        @unlink($taken);
    }

    // Классы пишут ошибки в сессию для показа на странице. Сессии у воркера нет -
    // отдаём их в docker logs, чтобы не терять молча.
    if (!empty($_SESSION['errors'])) {
        fwrite(STDERR, "[worker] " . implode("; ", (array) $_SESSION['errors']) . "\n");
    }

    clearstatcache();
    if (time() - $startedAt > MAX_LIFETIME || codeStamp($root) !== $stamp) {
        exit(0);
    }

    for ($i = 0, $n = (int) (TICK_SECONDS * 1000000 / KICK_POLL_US); $i < $n; $i++) {
        if (JobsSnapshot::kickPending()) {
            break;
        }
        usleep(KICK_POLL_US);
    }
}
