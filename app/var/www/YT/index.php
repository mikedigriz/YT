<?php
require_once __DIR__ . '/error_pages.php';

// === Настройка сессии ДО session_start ===
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

// === Защита от CSRF ===
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
    $host = $_SERVER['HTTP_HOST'] ?? '';

    if ($origin === 'null') {
        $origin = null;
    }

    if ($origin !== null) {
        $parsed = parse_url($origin);
        if (($parsed['host'] ?? '') !== $host) {
            return false;
        }
    } elseif ($referer !== null) {
        $parsed = parse_url($referer);
        if (($parsed['host'] ?? '') !== $host) {
            return false;
        }
    }
    
    return true;
}

// Одноразовый nonce для инлайн-скриптов: позволяет убрать 'unsafe-inline' из
// script-src (иначе любой внедрённый <script> исполнился бы). Пробрасывается во
// вьюхи как $cspNonce и в CSP-заголовок ниже.
$cspNonce = base64_encode(random_bytes(16));
$GLOBALS['cspNonce'] = $cspNonce;

// === Заголовки безопасности ===
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; media-src 'self' blob:;");

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

// Единый источник доменов для favicon-детекта - тот же список, что читает
// load_favicons.py. PHP-список Downloader::DIRECT_ACCESS_DOMAINS отдельный,
// у него другая задача (прямой доступ против прокси), сюда не входит.
$faviconDomainsJson = file_get_contents(__DIR__ . '/config/favicon_domains.json');
$knownServices = json_decode($faviconDomainsJson, true) ?: [];

require_once 'class/Downloader.php';
require_once 'class/FileHandler.php';
require_once 'class/ProxyStatus.php';

session_start();
$file = new FileHandler;
$allowFileDelete = $config['allowFileDelete'] ?? false;

// Фоновый замер прокси (сам по себе троттлится, лишнего не дёргает)
ProxyStatus::maybe_check();

// Продвигаем очередь на каждой загрузке, включая AJAX-поллинг ?jobs -
// иначе задачи стартуют только когда кто-то вручную перезагрузит страницу
if (!$config['disableQueue']) {
    $downloader = new Downloader([]);
    $downloader->process_queue();
}

// Вспомогательная функция для построения строки файла
function generateFileRow($f, $config, $file, $allowFileDelete, $type) {
    $deleteurl = "";
    if ($allowFileDelete) {
        // Данные уходят в data-атрибуты (атрибутный контекст), обработчик вешается
        // делегированием в JS - никакого inline onclick, поэтому CSP без unsafe-inline.
        // htmlspecialchars(ENT_QUOTES) достаточно: это чистый атрибутный контекст,
        // JS-строку тут уже не собираем.
        $attrName = htmlspecialchars($f["name"], ENT_QUOTES, 'UTF-8');
        $attrType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
        $deleteurl = '<button type="button" data-action="delete" data-value="' . $attrName . '" data-type="' . $attrType . '" class="btn btn-danger btn-xs">Удалить</button>';
    }
    
    $fileurl = $f["name"];
    $downloadurl = "";
    if ($config['downloadPath'] != "") {
        $safeName = htmlspecialchars($f["name"], ENT_QUOTES, 'UTF-8');
        $encodedName = rawurlencode($f["name"]);
        // Относительная ссылка на файл. Фронт превращает её в абсолютную через
        // window.location, чтобы зашить в QR-код тот же хост, по которому открыта
        // страница - телефон в той же сети сразу заберёт файл.
        $downloadurl = $file->get_downloads_link().'/'.$encodedName;
        $fileurl = '<a href="'.$downloadurl.'" download>'.$safeName.'</a>';
    }

    return [
        'file'             => $fileurl,
        'downloadurl'      => $downloadurl,
        'kind'             => ($type === 'v') ? 'video' : 'audio',
        'size'             => $f["size"],
        'deleteurl'        => $deleteurl,
        'age_minutes'      => (int)($f["age_minutes"] ?? 0),
        'lifetime_percent' => (int)($f["lifetime_percent"] ?? 100)
    ];
}

// Возвращаем JSON со всеми текущими задачами и историей задач
if(isset($_GET['jobs'])) {
    $response = [
        'jobs'     => Downloader::get_current_background_jobs(),
        'queue'    => [],
        'finished' => Downloader::get_finished_background_jobs(),
        'videos'   => [],
        'music'    => [],
        'logURL'   => $config['logURL'] ?? '',
        'proxy'    => ProxyStatus::payload()
    ];

    if (!$config['disableQueue']) {
        foreach(Downloader::get_queued_jobs() as $key) {
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

    foreach($file->listVideos() as $f) {
        $response['videos'][] = generateFileRow($f, $config, $file, $allowFileDelete, 'v');
    }

    foreach($file->listMusics() as $f) {
        $response['music'][] = generateFileRow($f, $config, $file, $allowFileDelete, 'm');
    }

    if(!isset($_GET['cron'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
    }
    die();
}

// === Обработчики POST для деструктивных действий ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isDestructive = isset($_POST['removeQueued']) 
        || isset($_POST['delete']) 
        || (isset($_POST['kill']) && !empty($_POST['kill']))
        || (isset($_POST['clear']) && !empty($_POST['clear']))
        || (isset($_POST['restart']) && !empty($_POST['restart']));
    
    if ($isDestructive && !validateCsrfToken()) {
        showCsrfErrorPage();
    }

    if(isset($_POST["removeQueued"])) {
        Downloader::remove_queued_job($_POST["removeQueued"]);
        header("Location: index.php#downloads");
        die();
    }

    if(isset($_POST["delete"]) && $allowFileDelete) {
        $file->delete($_POST["delete"]);
        $redirect = (($_POST["type"] ?? '') == "m") ? "index.php#music" : "index.php#videos";
        header("Location: " . $redirect);
        die();
    }

    if(isset($_POST['kill']) && !empty($_POST['kill'])) {
        if ($_POST['kill'] === "all") {
            Downloader::kill_them_all();
        } else {
            Downloader::kill_one_of_them($_POST['kill']);
        }
        header("Location: index.php#downloads");
        die();
    }

    if(isset($_POST['clear']) && !empty($_POST['clear'])) {
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
        header("Location: index.php#downloads");
        die();
    }

    if(isset($_POST['restart']) && !empty($_POST['restart'])) {
        Downloader::restart_download($_POST['restart']);
        header("Location: index.php#downloads");
        die();
    }
}

// Скачиваем видео
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

    if(isset($_POST['format']) && !empty($_POST['format'])) {
        if($_POST['format'] != "best") {
            $dl_format = $_POST['format'];
        }
    }

    // X-Forwarded-For/X-Real-IP подделываются любым клиентом, что достучится до
    // контейнера напрямую (порт опубликован в compose) - доверяем им только
    // если запрос реально пришёл с приватного/служебного адреса (наш nginx/докер-бридж)
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
        'client_ip' => $client_ip
    ]];

    // Проверка свободного места
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

// Готовим данные для отображения страницы
if (@$_GET["audio"]=="true" && !$config['disableExtraction']) {
    $audio_check = " checked=\"checked\"";
    $video_form_style = " style=\"display: none;\"";
    $audio_form_style = "";
} else {
    $audio_check = "";
    $video_form_style = "";
    $audio_form_style = "style=\"display: none;\"";
}

require_once 'views/part.header.php';
require_once 'views/part.main.php';
require_once 'views/part.footer.php';

unset($_SESSION['errors']);