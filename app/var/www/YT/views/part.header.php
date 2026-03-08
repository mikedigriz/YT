<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); } ?>
<!DOCTYPE html>
<html lang="ru">
  <head>
    <meta charset="utf-8">
    <meta name="theme-color" content="#DAA520"/>
    <title><?php echo htmlspecialchars($config['siteName'] ?? 'Качалка', ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <?php $theme = preg_replace('/[^a-z0-9\-\_]/i', '', $siteTheme ?? 'default'); ?>
    <link rel="stylesheet" href="css/<?php echo $theme; ?>.min.css" media="screen">
    <link rel="stylesheet" href="css/baskerstyle.css">
    <link rel="stylesheet" media="screen and (min-width: 600px)" href="css/animate.css">
    
    <script type="text/javascript" src="js/jquery-3.7.0.min.js"></script>
    <script type="text/javascript" src="js/bootstrap.min.js"></script>
    <script type="text/javascript" src="js/youtubedlwebui.js"></script>
    <!-- <script type="text/javascript" src="js/snow.js"></script> -->
    <script src="js/wow.min.js"></script>
    <script>
      new WOW().init();
    </script>
    <link rel="icon" href="img/favicon.png">
  </head>
  <body>