<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); } ?>
<!DOCTYPE html>
<html lang="ru"<?= !empty($fxOff) ? ' class="fx-off"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="theme-color" content="#DAA520">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <title><?= htmlspecialchars($config['siteName'] ?? 'Качалка', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon.png">
    <link rel="apple-touch-icon" href="img/icon-192.png">
    <link rel="manifest" href="manifest.json">
<?php
// Хэш содержимого CSS/JS (P.7), посчитан при сборке образа (Dockerfile) -
// меняется сам при пересборке, поэтому nginx может кэшировать css/js на год
// как immutable (см. sites-available/default): устаревания не бывает в
// принципе, сбрасывать кэш вручную не нужно.
$staticVersion = '';
if (file_exists('static_version')) {
    $staticVersion = trim(@file_get_contents('static_version') ?: '');
}
$staticVersionQuery = $staticVersion !== '' ? ('?v=' . rawurlencode($staticVersion)) : '';
?>
    <!-- Стили грузятся блокирующе (не async): иначе страница успевает нарисоваться
         без них, и .nav-tabs мигают/перестраиваются при каждом рефреше (FOUC). -->
    <link rel="stylesheet" href="<?= htmlspecialchars('css/baskerstyle.min.css' . $staticVersionQuery, ENT_QUOTES) ?>" fetchpriority="high">
<?php if (($pageMode ?? 'home') === 'feedback'): ?>
    <!-- Обратной связи не нужны ни списки сервисов, ни опрос ?jobs: главный скрипт
         на этой странице только зря стучался бы в index.php?jobs полтора раза в секунду. -->
    <script defer src="<?= htmlspecialchars('js/feedback.min.js' . $staticVersionQuery, ENT_QUOTES) ?>"></script>
<?php else: ?>
    <script nonce="<?= htmlspecialchars($cspNonce ?? '', ENT_QUOTES) ?>">const KNOWN_SERVICES = <?= json_encode($knownServices ?? [], JSON_UNESCAPED_SLASHES) ?>;
    const AUDIO_ONLY_SERVICES = <?= json_encode($audioServices ?? [], JSON_UNESCAPED_SLASHES) ?>;
    const DIRECT_ACCESS_DOMAINS = <?= json_encode($directDomains ?? [], JSON_UNESCAPED_SLASHES) ?>;
    const MASCOT_IMG = <?= json_encode($mascotImg ?? 'img/snej.webp', JSON_UNESCAPED_SLASHES) ?>;
    const STATIC_VERSION = <?= json_encode($staticVersion, JSON_UNESCAPED_SLASHES) ?>;
    const BUILD_ID = <?= json_encode(JobsSnapshot::buildId(), JSON_UNESCAPED_SLASHES) ?>;</script>
    <script defer src="<?= htmlspecialchars('js/youtubedlwebui.min.js' . $staticVersionQuery, ENT_QUOTES) ?>" fetchpriority="high"></script>
<?php endif; ?>
</head>
<body<?= !empty($isWinterMascot) ? ' class="winter"' : '' ?>>