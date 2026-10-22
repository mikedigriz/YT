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

    // Хост и порт приходят раздельно, а HTTP_HOST порт содержит - пересобираем
    // "host[:port]", иначе сравнение мимо на dev/prod портах (25567/8000).
    // WHATWG-парсер, как в браузере: порт по умолчанию он отбрасывает, и в
    // HTTP_HOST его тоже нет.
    $checkUrl = $origin ?? $referer;
    if ($checkUrl !== null) {
        $parsed = Uri\WhatWg\Url::parse($checkUrl);
        if ($parsed === null) {
            return false;
        }
        $authority = $parsed->getAsciiHost() ?? '';
        if ($parsed->getPort() !== null) {
            $authority .= ':' . $parsed->getPort();
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
// Пасхалка для тех, кто смотрит заголовки. Не X-Powered-By: его режет хостовый
// прокси (ops/host-nginx/.../proxy-common.conf).
header("X-Snezhik: powered by tea and yt-dlp");

// RFC 2324: на просьбу о кофе чайник отвечает 418. Ссылок сюда нет нигде.
if (isset($_GET['coffee'])) {
    http_response_code(418);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "418 I'm a teapot\n\nКофе не варю. Только видео качаю.\n";
    exit;
}

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

require_once 'class/Downloader.php';
require_once 'class/FileHandler.php';
require_once 'class/ProxyStatus.php';
require_once 'class/PlaylistProbe.php';
require_once 'class/Markdown.php';
require_once 'class/Feedback.php';
require_once 'class/JobsSnapshot.php';

// Состояние двигает фоновый воркер (bin/worker.php), см. JobsSnapshot. Пока он жив,
// запросы его не трогают: опрос отдаёт снапшот, а пролог ниже пропускается.
// Воркер умер - всё как раньше, пролог идёт прямо в запросе.
$workerAlive = JobsSnapshot::workerAlive();

// Опрос ?jobs при живом воркере: пинок, короткое ожидание его прохода - и готовый
// снапшот. Ни сессии, ни сканов tmp/ в этом запросе.
if (isset($_GET['jobs']) && $workerAlive) {
    JobsSnapshot::kickAndWait();
    $snapshot = JobsSnapshot::readWithNow();
    if ($snapshot !== null) {
        if (!isset($_GET['cron'])) {
            header('Content-Type: application/json');
            echo $snapshot;
        }
        die();
    }
    // Снапшота ещё нет (воркер только стартовал) - отвечаем по-старому
}

// Сколько ссылок принимает одна отправка. Выбор целого плейлиста иначе дописал бы в
// dl_queue три сотни строк одним махом - очередь после такого разгребается часами,
// а отменять её придётся по одной задаче.
const MAX_URLS_PER_SUBMIT = 50;

// Отказы, которые не зависят от самих ссылок. Общие для отправки (POST) и проверки
// до неё (QUERY), чтобы подсказка под полем и ответ на отправку не разошлись.
function submitGuardError(int $count): ?string {
    if ((new FileHandler())->get_free_space_bytes() < 100 * 1024 * 1024) {
        return "Ой, еей! Диск почти полный, приберись";
    }
    if ($count > MAX_URLS_PER_SUBMIT) {
        return "Многовато за раз: " . $count . " ссылок, можно до " . MAX_URLS_PER_SUBMIT . ". Отправь частями";
    }
    return null;
}

// Проверка ссылок до отправки - HTTP QUERY (RFC 10008): безопасный метод с телом.
// GET со списком ссылок в строке запроса упёрся бы в длину URL и осел бы в логах,
// а POST означал бы действие. Состояние не меняется, поэтому ни сессии, ни CSRF:
// чужая страница QUERY без CORS-preflight не отправит, а ответ не прочитает.
if ($_SERVER['REQUEST_METHOD'] === 'QUERY' && isset($_GET['preflight'])) {
    header('Accept-Query: application/json');
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        http_response_code(415);
        die();
    }
    $limit = 64 * 1024;
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) {
        http_response_code(413);
        die();
    }
    $body = json_decode($raw, true);
    if (!is_array($body) || !is_array($body['urls'] ?? null)) {
        http_response_code(400);
        die();
    }
    $urls = array_values(array_filter(array_map('trim', array_filter($body['urls'], 'is_string')), 'strlen'));

    header('Content-Type: application/json');
    $guard = submitGuardError(count($urls));
    if ($guard !== null) {
        $result = ['level' => 'error', 'message' => $guard];
    } elseif (!$urls) {
        $result = ['level' => '', 'message' => ''];
    } else {
        $result = Downloader::preflight($urls);
    }
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    die();
}

// X-Forwarded-For/X-Real-IP подделываются любым клиентом, достучавшимся до контейнера
// напрямую (порт открыт в compose) - доверяем только запросам с приватного/служебного адреса.
//
// ПОРЯДОК ЗАГОЛОВКОВ ВАЖЕН. X-Real-IP идёт ПЕРВЫМ, потому что обратный прокси
// выставляет его как $remote_addr, то есть значение целиком его собственное.
// X-Forwarded-For в стандартном Debian-овском proxy_params собирается через
// $proxy_add_x_forwarded_for - он ДОПИСЫВАЕТ адрес к тому, что прислал клиент.
// Раньше XFF читался первым и брался первый элемент списка, поэтому запрос с
// подставленным в этот заголовок доверенным адресом выдавал себя за него - а с
// появлением доверенных сетей администратора это стало прямым обходом пароля.
// Из XFF теперь берётся ПОСЛЕДНИЙ элемент (его дописал сам прокси), а не первый.
function clientIp(): string {
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $is_valid_remote = filter_var($remote_addr, FILTER_VALIDATE_IP) !== false;
    $is_public_remote = filter_var($remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    $is_trusted_proxy = $is_valid_remote && !$is_public_remote;

    $raw_ip = $remote_addr;
    if ($is_trusted_proxy) {
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $raw_ip = (string) $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $chain = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $raw_ip = trim(end($chain));
        }
    }
    if (strpos($raw_ip, ',') !== false) {
        $raw_ip = trim(explode(',', $raw_ip)[0]);
    }
    return filter_var($raw_ip, FILTER_VALIDATE_IP) ?: 'unknown';
}

session_start();

// Свежий CSRF-токен для страницы, чья сессия пропала: сессии живут в контейнере
// и не переживают его пересоздание. Фронт берёт токен на 403 и повторяет
// действие сам, без перезагрузки. Чужой origin ответ не прочитает - CORS нет.
if (isset($_GET['token'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['token' => generateCsrfToken()]);
    die();
}

// Опрос (?jobs раз в полторы секунды, ?playlist) отпускает блокировку сессии сразу,
// а не после пролога ниже. Пролог - скан tmp/, продвижение очереди с запуском
// yt-dlp, уборка, ретраи - под нагрузкой на диск (склейка больших файлов) идёт
// секундами, и всё это время любой клик того же браузера ("Стоп", крестик,
// "Скачать") ждал своей очереди на сессию: сайт казался подвисшим.
$isPoll = isset($_GET['jobs']) || isset($_GET['playlist']);
if ($isPoll) {
    $pollErrorsBefore = $_SESSION['errors'] ?? null;
    session_write_close();
}

$file = new FileHandler;
$allowFileDelete = $config['allowFileDelete'] ?? false;

// Пролог (уборка мёртвых задач, очередь, ретраи) - только без живого воркера,
// иначе это его работа. Порядок шагов и причины - в JobsSnapshot::advance().
$logPathFileList = null;
$queuedJobsAfterProcessing = [];
if (!$workerAlive) {
    // Воркер может подняться посреди запроса - лок не даёт им разбирать задачи вдвоём
    JobsSnapshot::holdState();
    $advanced = JobsSnapshot::advance();
    JobsSnapshot::releaseState();
    $logPathFileList = $advanced['fileList'];
    $queuedJobsAfterProcessing = $advanced['queued'];
}

// Сессия опроса закрыта ещё до пролога (см. $isPoll выше), но пролог мог положить
// в $_SESSION['errors'] ошибки очереди или ретрая - для следующей полной отрисовки.
// Сохраняем их коротким повторным открытием: блокировка берётся на миг, а не на
// весь пролог. Повторный session_start() перечитывает сессию с диска, поэтому
// значение запоминаем до него.
if ($isPoll) {
    $pollErrorsAfter = $_SESSION['errors'] ?? null;
    if ($pollErrorsAfter !== $pollErrorsBefore) {
        session_start();
        $_SESSION['errors'] = $pollErrorsAfter;
        session_write_close();
    }
}

// Опрос результата разбора плейлиста. Только чтение кэша по непрозрачному ключу,
// который сам же сервер и выдал на POST - сети эта ветка не касается, поэтому CSRF
// ей не нужен. Сырую ссылку на GET не принимаем намеренно: GET, дёргающий внешнюю
// выборку, это CSRF-усилитель SSRF даже при живой проверке pointsToInternalHost().
if (isset($_GET['playlist'])) {
    header('Content-Type: application/json');
    // Названия роликов приходят от yt-dlp - см. JSON_INVALID_UTF8_SUBSTITUTE в ?jobs
    echo json_encode(PlaylistProbe::read((string) $_GET['playlist']), JSON_INVALID_UTF8_SUBSTITUTE);
    die();
}

// Сюда опрос доходит, только если воркер не жив или снапшота ещё нет
if(isset($_GET['jobs'])) {
    if (!$workerAlive) {
        $response = JobsSnapshot::build($logPathFileList, $queuedJobsAfterProcessing);
    } else {
        // Воркер жив, но первого снапшота ещё нет - окно в секунду после его старта.
        // Очередь не двигаем (это его работа), остальное собираем как раньше.
        // build() финализирует мёртвые задачи - под тем же локом, что и воркер.
        JobsSnapshot::holdState();
        $response = JobsSnapshot::build(Downloader::scanLogPath(), []);
    }
    // Часы сервера - отсчёт до старта в очереди (start_at) не должен зависеть
    // от того, насколько врут часы телефона
    $response = ['now' => time()] + $response;

    if(!isset($_GET['cron'])) {
        header('Content-Type: application/json');
        echo JobsSnapshot::encode($response);
    }
    die();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Любое действие меняет состояние - воркер пересобирает снапшот сразу, а не на
    // своём тике. В конце запроса, когда действие уже выполнено.
    register_shutdown_function([JobsSnapshot::class, 'kick']);
    // Действие и воркер не должны менять задачи одновременно (см. holdState()).
    // Лок держится до конца запроса.
    JobsSnapshot::holdState();
    $isDestructive = isset($_POST['removeQueued'])
        || isset($_POST['delete'])
        || isset($_POST['clearDownloads'])
        || isset($_POST['pin'])
        || isset($_POST['reorderQueue'])
        || (isset($_POST['kill']) && !empty($_POST['kill']))
        || (isset($_POST['clear']) && !empty($_POST['clear']))
        || (isset($_POST['restart']) && !empty($_POST['restart']))
        // Проба плейлиста не разрушительна, но поднимает процесс и ходит в сеть
        // от имени пользователя - в чужие руки такую кнопку давать незачем.
        || isset($_POST['playlist'])
        // Обратная связь пишет на диск и публикует текст от имени посетителя -
        // отправка с чужой страницы недопустима ровно так же.
        || isset($_POST['feedback_new'])
        || isset($_POST['feedback_reply'])
        || isset($_POST['feedback_delete_message'])
        || isset($_POST['feedback_delete_dialog']);

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

    // Запуск разбора плейлиста. Отвечает сразу: 'ready' из тёплого кэша, иначе
    // 'pending' и ключ, по которому фронт добирает результат через GET ?playlist.
    if(isset($_POST['playlist']) && !empty($_POST['playlist'])) {
        header('Content-Type: application/json');
        echo json_encode(PlaylistProbe::start((string) $_POST['playlist'], clientIp()), JSON_INVALID_UTF8_SUBSTITUTE);
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

    // Действия администратора обратной связи: удаление сообщения и обращения.
    // Права проверяет сам Feedback (доверенная сеть либо пароль на КАЖДОЕ
    // действие - метки в сессии нет намеренно, см. checkAdmin()).
    if (isset($_POST['feedback_delete_message']) || isset($_POST['feedback_delete_dialog'])) {
        if (!Feedback::enabled()) {
            showNotFoundPage();
        }

        $dialogId = (int) ($_POST['dialog'] ?? 0);
        // Права проверяются РОВНО ОДИН раз на запрос: каждая проверка двигает
        // счётчик неудачных попыток пароля (см. Feedback::authorize()).
        $auth = Feedback::authorize(clientIp(), (string) ($_POST['admin_password'] ?? ''));

        if (!$auth['ok']) {
            $result = ['ok' => false, 'error' => $auth['error']];
        } elseif (isset($_POST['feedback_delete_dialog'])) {
            $result = Feedback::deleteDialog($dialogId, true, clientIp());
        } else {
            $result = Feedback::deleteMessage($dialogId, (int) $_POST['feedback_delete_message'], true, clientIp());
        }

        $_SESSION['feedback_flash'] = $result['ok']
            ? ['ok' => true, 'text' => !empty($result['dialogDeleted']) ? 'Обращение удалено.' : 'Сообщение удалено.']
            : ['ok' => false, 'text' => (string) $result['error']];

        // Удалённый диалог показывать негде - возвращаемся к списку.
        $back = (!$result['ok'] || empty($result['dialogDeleted'])) && $dialogId > 0
            ? '=' . $dialogId
            : '';
        header('Location: index.php?feedback' . $back);
        die();
    }

    // Обратная связь. Ответ - всегда редирект на страницу (обычная форма, работает
    // без JS), результат едет через сессию. Цель редиректа собирается из своей же
    // строки и целого числа, поэтому увести его пользовательским вводом нельзя.
    if (isset($_POST['feedback_new']) || isset($_POST['feedback_reply'])) {
        if (!Feedback::enabled()) {
            showNotFoundPage();
        }

        $isNew = isset($_POST['feedback_new']);
        $body = (string) ($_POST['message'] ?? '');
        $title = (string) ($_POST['title'] ?? '');
        $dialogId = (int) ($_POST['dialog'] ?? 0);
        // Ответ (или обращение) от имени администратора. Сама галочка ничего не
        // даёт: права проверяются здесь, один раз на запрос, и дальше едут
        // готовым флагом. Подтверждённый администратор освобождается от ВСЕХ
        // ограничений - приманки, минимальной задержки, лимитов частоты, потолков
        // длины и числа сообщений (см. Feedback::validate()/addMessage()).
        $wantsAdmin = !empty($_POST['as_admin']);
        $asAdmin = false;
        $adminError = null;
        if ($wantsAdmin) {
            $auth = Feedback::authorize(clientIp(), (string) ($_POST['admin_password'] ?? ''));
            $asAdmin = $auth['ok'];
            $adminError = $auth['ok'] ? null : (string) $auth['error'];
        }

        if ($adminError !== null) {
            $_SESSION['feedback_flash'] = ['ok' => false, 'text' => $adminError];
            $_SESSION['feedback_draft'] = ['title' => $title, 'message' => $body];
            header('Location: index.php?feedback' . ($isNew ? '' : '=' . $dialogId));
            die();
        }

        // Приманка и минимальная задержка. Отсутствие поля или отметки времени -
        // это ОТКАЗ, а не пропуск проверки: бот, не берущий куки и не разбирающий
        // форму, иначе обходил бы обе разом.
        $trapFilled = trim((string) ($_POST['website'] ?? 'нет поля')) !== '';
        $openedAt = (int) ($_SESSION['feedback_form_ts'] ?? 0);
        $tooFast = $openedAt <= 0 || (time() - $openedAt) < (int) ($config['feedbackMinDelay'] ?? 3);

        // Приманка не касается подтверждённого администратора: браузер умеет
        // подставить что угодно в скрытое поле (автозаполнение), и терять из-за
        // этого ответ, права на который уже проверены, незачем.
        if ($trapFilled && !$asAdmin) {
            // Молча делаем вид, что приняли: явный отказ подсказал бы боту, на чём
            // он попался, и следующая попытка пришла бы уже без приманки.
            $_SESSION['feedback_flash'] = ['ok' => true, 'text' => 'Спасибо, отправлено.'];
            header('Location: index.php?feedback');
            die();
        }

        // Минимальная задержка - мера против ботов, а не против человека с правами.
        if ($tooFast && !$asAdmin) {
            $_SESSION['feedback_flash'] = ['ok' => false, 'text' => 'Слишком быстро. Перечитай написанное и отправь ещё раз.'];
            $_SESSION['feedback_draft'] = ['title' => $title, 'message' => $body];
            header('Location: index.php?feedback' . ($isNew ? '' : '=' . $dialogId));
            die();
        }

        $result = $isNew
            ? Feedback::createDialog($title, $body, clientIp(), $asAdmin)
            : Feedback::addMessage($dialogId, $body, clientIp(), $asAdmin);

        if ($result['ok']) {
            unset($_SESSION['feedback_draft'], $_SESSION['feedback_form_ts']);
            $_SESSION['feedback_flash'] = ['ok' => true, 'text' => 'Отправлено.'];
            header('Location: index.php?feedback=' . (int) $result['id'] . '#end');
            die();
        }

        $_SESSION['feedback_flash'] = [
            'ok' => false,
            'text' => (string) $result['error'],
            'limited' => !empty($result['limited']),
        ];
        $_SESSION['feedback_draft'] = ['title' => $title, 'message' => $body];
        header('Location: index.php?feedback' . ($isNew ? '' : '=' . $dialogId));
        die();
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

    $client_ip = clientIp();

    $dl_list = [[
        'url' => $_POST['urls'],
        'audio_only' => $audio_only,
        'dl_format' => $dl_format,
        'audio_format' => $audio_format,
        'client_ip' => $client_ip,
        'translate' => $translate
    ]];

    $submitted_count = count(array_filter(array_map('trim', explode('||', $_POST['urls'])), 'strlen'));

    $guard = submitGuardError($submitted_count);
    if ($guard !== null) {
        $_SESSION['errors'] = [$guard];
    } else {
        $downloader = new Downloader($dl_list);
    }

    $hasErrors = isset($_SESSION['errors']) && count($_SESSION['errors']) > 0;
    $rejected = isset($downloader) ? $downloader->getRejectedUrls() : [];

    // Отправка через fetch (как все остальные действия на сайте) отвечает JSON -
    // после редиректа сводку показывать негде, данные умирают вместе с запросом.
    // Обычный POST формы сохранён рабочим: без JS страница ведёт себя как раньше.
    // Признаём два маркера: свой заголовок и обычный Accept. Заголовок может не
    // дойти (прокси/фильтры), Accept шлёт сам fetch - хватит любого из двух.
    $wantsJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'fetch')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($wantsJson) {
        $started = isset($downloader) ? $downloader->getStartedUrls() : [];
        $queued  = isset($downloader) ? $downloader->getQueuedUrls() : [];
        header('Content-Type: application/json');
        echo json_encode([
            'ok'       => !$hasErrors,
            'started'  => count($started),
            'queued'   => count($queued),
            'rejected' => $rejected,
            'errors'   => $_SESSION['errors'] ?? [],
            // Что вернуть в поле: только битые ссылки, иначе повторная отправка
            // задвоила бы уже запущенные
            'keepInField' => implode('||', $rejected),
        ]);
        unset($_SESSION['errors']);
        die();
    }

    // Классический путь без JS. Три состояния вместо двух: всё ушло качаться -
    // редирект; всё отклонено - остаёмся и возвращаем введённое в поле; часть
    // ушла, часть отклонена - тоже остаёмся (иначе ошибку негде показать), но в
    // поле кладём только битые ссылки.
    if (!$hasErrors) {
        header("Location: index.php#" . $config['redirectAfterSubmit']);
        die();
    }

    $_SESSION['form_urls'] = !empty($rejected)
        ? implode('||', $rejected)
        : (string) ($_POST['urls'] ?? '');
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
// Кнопка-снежинка в углу: с cookie не отдаём ни Снежика, ни снега, ни их скриптов
$fxOff = ($_COOKIE['yt_fx_off'] ?? '') === '1';
if ($fxOff) {
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
// Сироты .part (после docker restart процесс убит, огрызок остался). Только на
// обычном рендере страницы: на опросах ?jobs это лишний обход папки, а сюда мы
// доходим уже после process_queue, то есть после finalize_job_log со спасателем -
// спасённая запись к этому моменту .part уже не называется.
Downloader::sweepOrphanPartFiles();

// Страница обратной связи. Стоит перед чтением списков доменов и сборкой формы
// загрузки: ей не нужно ни то, ни другое, а $pageMode гасит в шапке и опрос ?jobs.
if (isset($_GET['feedback'])) {
    if (!Feedback::enabled()) {
        showNotFoundPage('Обратная связь на этом сервере выключена.');
    }

    $pageMode = 'feedback';
    $feedbackRaw = (string) $_GET['feedback'];
    $feedbackId = ctype_digit($feedbackRaw) ? (int) $feedbackRaw : 0;
    $feedbackDialog = $feedbackId > 0 ? Feedback::read($feedbackId) : null;
    $feedbackList = $feedbackId > 0
        ? null
        : Feedback::listDialogs((int) ($_GET['page'] ?? 1), (int) ($config['feedbackPerPage'] ?? 20));

    // Одноразовые: показали - забыли, иначе всплывут на следующей странице.
    $feedbackFlash = $_SESSION['feedback_flash'] ?? null;
    $feedbackDraft = $_SESSION['feedback_draft'] ?? ['title' => '', 'message' => ''];
    unset($_SESSION['feedback_flash'], $_SESSION['feedback_draft']);

    // Отметка времени открытия формы живёт в сессии, а не в скрытом поле: подделать
    // нечего, подписывать нечего (см. проверку $tooFast в POST-обработчике).
    $_SESSION['feedback_form_ts'] = time();

    // Доверенный адрес - поле пароля не рисуем вовсе; снаружи оно нужно на каждое
    // действие. Признак влияет ТОЛЬКО на разметку: настоящую проверку делает
    // Feedback::checkAdmin() на каждый POST, подделка разметки прав не даёт.
    $feedbackAdminTrusted = Feedback::isTrustedAdminNetwork(clientIp());
    $feedbackAdminAvailable = $feedbackAdminTrusted || Feedback::adminPasswordConfigured();

    require_once 'views/part.header.php';
    require_once 'views/part.feedback.php';
    require_once 'views/part.footer.php';
    die();
}

$faviconDomainsJson = file_get_contents(__DIR__ . '/config/favicon_domains.json');
$faviconDomains = json_decode($faviconDomainsJson, true) ?: [];
// Формат файла - объект {domains, audio}; голый массив тоже принимаем, чтобы
// старая копия конфига не роняла страницу в пустой список сервисов.
$knownServices = isset($faviconDomains['domains']) ? $faviconDomains['domains'] : (array_is_list($faviconDomains) ? $faviconDomains : []);
$audioServices = isset($faviconDomains['audio']) && is_array($faviconDomains['audio']) ? $faviconDomains['audio'] : [];
// Список прямых доменов нужен фронту только чтобы не пугать мёртвым прокси там,
// где прокси не используется. Источник тот же, что у маршрутизации - без копии.
$directDomains = Downloader::DIRECT_ACCESS_DOMAINS;
// Введённое возвращается в поле после ошибки: раньше страница перерисовывалась
// пустой, и пять ссылок из-за одной опечатки набирали заново.
$formUrls = (string) ($_SESSION['form_urls'] ?? '');

require_once 'views/part.header.php';
require_once 'views/part.main.php';
require_once 'views/part.footer.php';

unset($_SESSION['errors']);
unset($_SESSION['form_urls']);