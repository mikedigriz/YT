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
    <link rel="preload" href="css/<?= preg_replace('/[^a-z0-9\-\_]/i', '', $siteTheme ?? 'default') ?>.min.css" as="style" fetchpriority="high" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="stylesheet" href="css/baskerstyle.min.css" fetchpriority="high">
    <noscript>
        <link rel="stylesheet" href="css/<?= preg_replace('/[^a-z0-9\-\_]/i', '', $siteTheme ?? 'default') ?>.min.css">
    </noscript>
    <script defer src="js/youtubedlwebui.js" fetchpriority="high"></script>
</head>
<body>