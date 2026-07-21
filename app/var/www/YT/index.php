<?php
require_once __DIR__ . '/error_pages.php';

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
    || $_SERVER['SERVER_PORT'] == 443 
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isSecure,
    'httponly'  => true,
    'samesite' => 'Lax'
]);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');

    if ($origin === 'null') {
        $origin = null;
    }

    // parse_url() отдаёт host/port раздельно - HTTP_HOST порт содержит, голый ['host'] нет.
    // Пересобираем "host[:port]" вручную, иначе сравнение мимо на dev/prod портах (25567/8000).
    $checkUrl = $origin ?? $referer;
    if ($checkUrl !== null) {
        $parsed = parse_url($checkUrl);
        $authority = strtolower($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $authority .= ':' . $parsed['port'];
        }
        if ($authority !== $host) {
            return false;
        }
    }

    return true;
}

// Одноразовый nonce для инлайн-скриптов - убирает 'unsafe-inline' из script-src.
$cspNonce = base64_encode(random_bytes(16));
$GLOBALS['cspNonce'] = $cspNonce;

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
// img-src сужен до 'self' data: - все картинки локальные, 'https:' был лишним.
// object-src/base-uri/form-action/frame-ancestors - defense in depth; form-action не даёт увести форму при XSS.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self';");
// COOP/CORP - защита от Spectre-класса side-channel и window.opener-атак.
header("Cross-Origin-Opener-Policy: same-origin");
header("Cross-Origin-Resource-Policy: same-origin");

if ($isSecure) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}

$config = include 'config/config.php';
if ($config['debug']) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

$siteTheme = $config['siteTheme'];
if (isset($_GET['theme'])) {
    $siteTheme = $_GET['theme'];
}

require_once 'class/Downloader.php';
require_once 'class/FileHandler.php';
require_once 'class/ProxyStatus.php';

session_start();
$file = new FileHandler;
$allowFileDelete = $config['allowFileDelete'] ?? false;

// Фоновый замер прокси (сам по себе троттлится, лишнего не дёргает)
ProxyStatus::maybe_check();

// Единый скан tmp/ на весь запрос - раньше process_queue()/get_current_background_jobs()/
// get_finished_background_jobs() сканировали logPath отдельно (до 3-4 проходов на ?jobs).
$logPathFileList = Downloader::scanLogPath();

// Продвигаем очередь на каждом GET (включая ?jobs), иначе задачи стартуют только
// вручную. Возврат - распарсенный остаток очереди, переиспользуется ниже на ?jobs.
$queuedJobsAfterProcessing = [];
if (!$config['disableQueue']) {
    $downloader = new Downloader([]);
    $queuedJobsAfterProcessing = $downloader->process_queue($logPathFileList);
}

function generateFileRow($f, $config, $file, $allowFileDelete, $type) {
    // Данные в data-атрибуты, обработчик вешается делегированием в JS - без inline onclick,
    // CSP без unsafe-inline. htmlspecialchars(ENT_QUOTES) достаточно для чистого атрибутного контекста.
    $attrName = htmlspecialchars($f["name"], ENT_QUOTES, 'UTF-8');
    $attrType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');

    $deleteurl = "";
    if ($allowFileDelete) {
        $deleteurl = '<button type="button" data-action="delete" data-value="' . $attrName . '" data-type="' . $attrType . '" class="btn btn-danger btn-xs">Удалить</button>';
    }
    
    // Экранируем даже на случай пустого downloadPath (ссылка не строится) - иначе
    // на фронте (renderFileRow()) имя шло бы в DOM без экранирования.
    $fileurl = htmlspecialchars($f["name"], ENT_QUOTES, 'UTF-8');
    $downloadurl = "";
    if ($config['downloadPath'] != "") {
        $safeName = htmlspecialchars($f["name"], ENT_QUOTES, 'UTF-8');
        $encodedName = rawurlencode($f["name"]);
        // Относительная ссылка - фронт делает её абсолютной через window.location для QR-кода.
        $downloadurl = $file->get_downloads_link().'/'.$encodedName;
        $fileurl = '<a href="'.$downloadurl.'" download>'.$safeName.'</a>';
    }

    return [
        'file'             => $fileurl,
        'name'             => $attrName,
        'downloadurl'      => $downloadurl,
        'kind'             => ($type === 'v') ? 'video' : 'audio',
        'size'             => $f["size"],
        'deleteurl'        => $deleteurl,
        'pinned'           => (bool)($f["pinned"] ?? false),
        'age_minutes'      => (int)($f["age_minutes"] ?? 0),
        'lifetime_percent' => (int)($f["lifetime_percent"] ?? 100)
    ];
}

// $_SESSION дальше в этой ветке не трогается (только в part.main.php при полном рендере
// и в POST-обработчиках ниже) - закрываем сессию пораньше, иначе file-based session handler
// держит эксклюзивный lock на весь ?jobs (частый опрос, 1.5с) и блокирует параллельные запросы
// с той же cookie: вторую вкладку или клик "Стоп"/"Удалить" во время идущего опроса.
if (isset($_GET['jobs'])) {
    session_write_close();
}

if(isset($_GET['jobs'])) {
    $response = [
        'jobs'     => Downloader::get_current_background_jobs($logPathFileList),
        'queue'    => [],
        'finished' => Downloader::get_finished_background_jobs($logPathFileList),
        'videos'   => [],
        'music'    => [],
        'logURL'   => $config['logURL'] ?? '',
        'proxy'    => ProxyStatus::payload()
    ];

    if (!$config['disableQueue']) {
        foreach($queuedJobsAfterProcessing as $key) {
            $dl_type = "video";
            $formatLabels = ['worst' => 'Булшит', '4K' => '4K', '1440p' => '2K', '1080p' => 'Full HD'];
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
                'type'      => $dl_type
            ];
        }
    }

    $media = $file->listMedia();

    foreach($media['videos'] as $f) {
        $response['videos'][] = generateFileRow($f, $config, $file, $allowFileDelete, 'v');
    }

    foreach($media['musics'] as $f) {
        $response['music'][] = generateFileRow($f, $config, $file, $allowFileDelete, 'm');
    }

    if(!isset($_GET['cron'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
    }
    die();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isDestructive = isset($_POST['removeQueued'])
        || isset($_POST['delete'])
        || isset($_POST['clearDownloads'])
        || isset($_POST['pin'])
        || isset($_POST['reorderQueue'])
        || (isset($_POST['kill']) && !empty($_POST['kill']))
        || (isset($_POST['clear']) && !empty($_POST['clear']))
        || (isset($_POST['restart']) && !empty($_POST['restart']));
    
    if ($isDestructive && !validateCsrfToken()) {
        showCsrfErrorPage();
    }

    // JSON, не редирект - кнопки бьются через fetch (submitActionFetch()), loadList()
    // перерисовывает только изменившиеся строки. Прокидывает $_SESSION['errors'] (например
    // от restart_download() при испорченной команде), иначе сообщение терялось бы без reload.
    function jsonActionResponse(callable $action): void {
        unset($_SESSION['errors']);
        $action();
        header('Content-Type: application/json');
        echo json_encode(['ok' => empty($_SESSION['errors']), 'errors' => $_SESSION['errors'] ?? []]);
        unset($_SESSION['errors']);
        die();
    }

    if(isset($_POST["removeQueued"])) {
        jsonActionResponse(fn() => Downloader::remove_queued_job($_POST["removeQueued"]));
    }

    if(isset($_POST["reorderQueue"]) && in_array($_POST["direction"] ?? '', ['up', 'down'], true)) {
        jsonActionResponse(fn() => Downloader::reorder_queued_job($_POST["reorderQueue"], $_POST["direction"]));
    }

    // Закреп не завязан на allowFileDelete - защищает файлы от deleteAll() и от чистки
    // хост-крона (FileHandler::setPinned()), не удаляет. JSON через fetch (togglePin()),
    // без полной навигации - та сбрасывала прокрутку и мигала маскотом ради одной иконки.
    if(isset($_POST["pin"])) {
        $pinned = ($_POST["pinned"] ?? '') === '1';
        $ok = $file->setPinned($_POST["pin"], $pinned);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        die();
    }

    // JSON, не редирект - те же причины, что у пина выше (deleteFile() в JS).
    if(isset($_POST["delete"]) && $allowFileDelete) {
        $ok = $file->delete($_POST["delete"]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        die();
    }

    // Не путать с 'clear' (тот про логи задач, не про файлы) - имена разделены нарочно.
    if(isset($_POST["clearDownloads"]) && $allowFileDelete) {
        $type = $_POST["type"] ?? '';
        $deleted = $file->deleteAll($type, Downloader::background_jobs() > 0);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'deleted' => $deleted]);
        die();
    }

    if(isset($_POST['kill']) && !empty($_POST['kill'])) {
        jsonActionResponse(function() {
            if ($_POST['kill'] === "all") {
                Downloader::kill_them_all();
            } else {
                Downloader::kill_one_of_them($_POST['kill']);
            }
        });
    }

    if(isset($_POST['clear']) && !empty($_POST['clear'])) {
        jsonActionResponse(function() {
            switch ($_POST['clear']) {
                case "recent":
                    Downloader::clear_finished();
                    break;
                case "queue":
                    Downloader::remove_all_queued_jobs();
                    break;
                default:
                    Downloader::clear_one_finished($_POST['clear']);
                    break;
            }
        });
    }

    if(isset($_POST['restart']) && !empty($_POST['restart'])) {
        jsonActionResponse(fn() => Downloader::restart_download($_POST['restart']));
    }
}

if(isset($_POST['urls']) && !empty($_POST['urls'])) {
    if (!validateCsrfToken()) {
        showCsrfErrorPage();
    }

    $audio_only = false;
    $audio_format = "";
    $dl_format = "";

    $allowed_audio_formats = [
        'mp3-high' => '--audio-format mp3 --audio-quality 0',
        'mp3' => '--audio-format mp3',
        'wav' => '--audio-format wav',
        'aac' => '--audio-format aac',
        'flac' => '--audio-format flac',
        '' => ''
    ];

    if(isset($_POST['audio']) && !empty($_POST['audio'])) {
        $audio_only = true;
    }

    $audio_format_key = $_POST['audio_format'] ?? '';
    if (isset($allowed_audio_formats[$audio_format_key])) {
        $audio_format = $allowed_audio_formats[$audio_format_key];
    }

    // Whitelist, как audio_format выше - непровалидированное значение уходит в dl_queue
    // без urlencode, ">" сдвинет поля, "\n" сломает парсинг всего файла очереди.
    $allowed_dl_formats = ['top', 'worst', '4K', '1440p', '1080p'];
    $format_key = $_POST['format'] ?? '';
    if ($format_key !== 'best' && in_array($format_key, $allowed_dl_formats, true)) {
        $dl_format = $format_key;
    }

    // Перевод озвучки через Яндекс-VOT - только для видео, для аудио бессмысленно
    $translate = !$audio_only && !empty($_POST['translate']);

    // X-Forwarded-For/X-Real-IP подделываются любым клиентом, достучавшимся до контейнера
    // напрямую (порт открыт в compose) - доверяем только запросам с приватного/служебного адреса.
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $is_valid_remote = filter_var($remote_addr, FILTER_VALIDATE_IP) !== false;
    $is_public_remote = filter_var($remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    $is_trusted_proxy = $is_valid_remote && !$is_public_remote;
    $raw_ip = $is_trusted_proxy
        ? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $remote_addr)
        : $remote_addr;
    if (strpos($raw_ip, ',') !== false) {
        $raw_ip = trim(explode(',', $raw_ip)[0]);
    }
    $client_ip = filter_var($raw_ip, FILTER_VALIDATE_IP) ?: 'unknown';

    $dl_list = [[
        'url' => $_POST['urls'],
        'audio_only' => $audio_only,
        'dl_format' => $dl_format,
        'audio_format' => $audio_format,
        'client_ip' => $client_ip,
        'translate' => $translate
    ]];

    $fh = new FileHandler();
    $min_free_bytes = 100 * 1024 * 1024; // 100 МБ
    $free_bytes = $fh->get_free_space_bytes();

    if ($free_bytes < $min_free_bytes) {
        $_SESSION['errors'] = ["Ой, еей! Диск почти полный, приберись"];
    } else {
        $downloader = new Downloader($dl_list);
    }

    if(!isset($_SESSION['errors']) || count($_SESSION['errors']) === 0) {
        header("Location: index.php#" . $config['redirectAfterSubmit']);
        die();
    }
}

// Новогодний маскот (ноябрь - март) - месяц считается один раз тут и
// прокидывается в PHP-разметку и JS, чтобы обе стороны не считали его отдельно и не разошлись.
$nowMonth = (int) date('n');
$isWinterMascot = in_array($nowMonth, [11, 12, 1, 2, 3], true);
// Ручной оверрайд из конфига: 'on'/'off' форсируют режим без перевода часов,
// 'auto' (или отсутствие ключа) - по календарю. См. config.php 'winterMascot'.
$winterOverride = $config['winterMascot'] ?? 'auto';
if ($winterOverride === 'on') {
    $isWinterMascot = true;
} elseif ($winterOverride === 'off') {
    $isWinterMascot = false;
}
$mascotImg = $isWinterMascot ? 'img/snej_new_year.webp' : 'img/snej.webp';

// PWA share target (manifest.json) - ОС "Поделиться" шлёт сюда обычный GET без JS/CSRF-токена
// (взять неоткуда). Просто подставляем ссылку в поле, сабмит - вручную через штатный POST.
$sharedUrl = '';
if (!empty($_GET['shared_url'])) {
    $sharedUrl = trim($_GET['shared_url']);
} elseif (!empty($_GET['shared_text']) && preg_match('#https?://\S+#i', $_GET['shared_text'], $sm)) {
    $sharedUrl = $sm[0];
}

if (@$_GET["audio"]=="true" && !$config['disableExtraction']) {
    $audio_check = " checked=\"checked\"";
    $video_form_style = " style=\"display: none;\"";
    $audio_form_style = "";
} else {
    $audio_check = "";
    $video_form_style = "";
    $audio_form_style = "style=\"display: none;\"";
}

// Тот же список доменов, что читает load_favicons.py. Downloader::DIRECT_ACCESS_DOMAINS -
// отдельный список (прямой доступ vs прокси), сюда не входит. Читается только тут, не в начале
// файла - единственный потребитель part.header.php, куда ?jobs/destructive POST не доходят (die() раньше).
$faviconDomainsJson = file_get_contents(__DIR__ . '/config/favicon_domains.json');
$knownServices = json_decode($faviconDomainsJson, true) ?: [];

require_once 'views/part.header.php';
require_once 'views/part.main.php';
require_once 'views/part.footer.php';

unset($_SESSION['errors']);