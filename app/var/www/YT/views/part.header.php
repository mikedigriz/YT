<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); } ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="theme-color" content="#DAA520">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <title><?= htmlspecialchars($config['siteName'] ?? 'Качалка', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon.png">
    <link rel="apple-touch-icon" href="img/favicon.png">
<?php $themeCss = 'css/' . preg_replace('/[^a-z0-9\-\_]/i', '', $siteTheme ?? 'default') . '.min.css'; ?>
    <!-- Тема грузится блокирующе (не async): иначе страница успевает нарисоваться
         без Bootswatch и .nav-tabs мигают/перестраиваются при каждом рефреше (FOUC).
         baskerstyle идёт после темы - это переопределения поверх неё. -->
    <link rel="stylesheet" href="<?= htmlspecialchars($themeCss, ENT_QUOTES) ?>" fetchpriority="high">
    <link rel="stylesheet" href="css/baskerstyle.min.css" fetchpriority="high">
    <script nonce="<?= htmlspecialchars($cspNonce ?? '', ENT_QUOTES) ?>">const KNOWN_SERVICES = <?= json_encode($knownServices ?? [], JSON_UNESCAPED_SLASHES) ?>;</script>
    <script defer src="js/youtubedlwebui.min.js" fetchpriority="high"></script>
</head>
<body>