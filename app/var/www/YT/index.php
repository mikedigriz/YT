<?php
// === Session configuration BEFORE session_start ===
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

// === CSRF Protection ===
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

// === Security headers ===
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; media-src 'self' blob:;");

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

require_once 'class/Session.php';
require_once 'class/Downloader.php';
require_once 'class/FileHandler.php';

$session = Session::getInstance();
$file = new FileHandler;
$allowFileDelete = $config['allowFileDelete'] ?? false;

// Process queue only for non-AJAX requests
if (!$config['disableQueue'] && !isset($_GET['jobs'])) {
    $downloader = new Downloader([]);
    $downloader->process_queue();
}

// Helper function for file row generation
function generateFileRow($f, $config, $file, $allowFileDelete, $type) {
    $deleteurl = "";
    if ($allowFileDelete) {
        // json_encode produces a valid JS string literal; htmlspecialchars then escapes it for
        // safe placement inside an HTML attribute. Plain htmlspecialchars() alone is NOT enough here:
        // browsers HTML-decode the attribute value before executing it as JS, so an entity like
        // &#039; would be decoded back to ' and could break out of the JS string (DOM XSS).
        $jsName = htmlspecialchars(json_encode($f["name"], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        $jsType = htmlspecialchars(json_encode($type, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        $deleteurl = '<button onclick="confirmAction(\'delete\', ' . $jsName . ', {type: ' . $jsType . '})" class="btn btn-danger btn-xs">Удалить</button>';
    }
    
    $fileurl = $f["name"];
    if ($config['downloadPath'] != "") {
        $safeName = htmlspecialchars($f["name"], ENT_QUOTES, 'UTF-8');
        $encodedName = rawurlencode($f["name"]);
        $fileurl = '<a href="'.$file->get_downloads_link().'/'.$encodedName.'" download>'.$safeName.'</a>';
    }
    
    return [
        'file'             => $fileurl,
        'size'             => $f["size"],
        'deleteurl'        => $deleteurl,
        'age_minutes'      => (int)($f["age_minutes"] ?? 0),
        'lifetime_percent' => (int)($f["lifetime_percent"] ?? 100)
    ];
}

// Return JSON with all jobs currently running and jobs history
if(isset($_GET['jobs'])) {
    $response = [
        'jobs'     => Downloader::get_current_background_jobs(),
        'queue'    => [],
        'finished' => Downloader::get_finished_background_jobs(),
        'videos'   => [],
        'music'    => [],
        'logURL'   => $config['logURL'] ?? ''
    ];

    if (!$config['disableQueue']) {
        foreach(Downloader::get_queued_jobs() as $key) {
            $dl_type = "video";
            $dl_format = str_replace("-f ", "Format: ", $key['dl_format']);
            if ($key['audio_only']) {
                $dl_type = "audio";
                $dl_format = str_replace("--audio-format ", "Format: ", $key['audio_format']);
                $dl_format = str_replace(" --audio-quality ", ", Quality: ", $dl_format);
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

// === POST handlers for destructive actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isDestructive = isset($_POST['removeQueued']) 
        || isset($_POST['delete']) 
        || (isset($_POST['kill']) && !empty($_POST['kill']))
        || (isset($_POST['clear']) && !empty($_POST['clear']))
        || (isset($_POST['restart']) && !empty($_POST['restart']));
    
    if ($isDestructive && !validateCsrfToken()) {
        http_response_code(403);
        die('CSRF token validation failed');
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

// Download a video
if(isset($_POST['urls']) && !empty($_POST['urls'])) {
    if (!validateCsrfToken()) {
        http_response_code(403);
        die('CSRF token validation failed');
    }

    $audio_only = false;
    $audio_format = "";
    $dl_format = "";

    $allowed_audio_formats = [
        'mp3-high' => '--audio-format mp3 --audio-quality 0',
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

    $dl_list = [[
        'url' => $_POST['urls'],
        'audio_only' => $audio_only,
        'dl_format' => $dl_format,
        'audio_format' => $audio_format
    ]];
    
    $downloader = new Downloader($dl_list);

    if(!isset($_SESSION['errors']) || count($_SESSION['errors']) === 0) {
        header("Location: index.php#" . $config['redirectAfterSubmit']);
        die();
    }
}

// Prepare for display of web page
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