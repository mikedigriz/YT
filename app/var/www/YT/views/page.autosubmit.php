<?php if (!isset($GLOBALS['config'])) { die("No direct script access");
} ?>
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <title><?php echo($config['siteName']); ?></title>
    <link rel="stylesheet" href="css/<?php echo($siteTheme); ?>.min.css" media="screen">
    <link rel="stylesheet" href="css/font-awesome.min.css">
    <script type="text/javascript" src="js/jquery-1.11.1.min.js"></script>
    <script type="text/javascript" src="js/bootstrap.min.js"></script>
    <script type="text/javascript" src="js/youtubedlwebui.js"></script>
    <link rel="icon" href="img/favicon.png">
  </head>
  <body>
    <?php if ($_GET['submitstatus'] == "success") : ?>
      <b>Видео успешно добавлено</b>
      <br /><br />
      <a href="javascript:window.close();">Закрой это окно</a> или <a href="index.php#downloads">Проверь статус загрузки</a>
    <?php else: ?>
      <b>Что-то не то! Попробуй добавить видео вручную.</b>
      <br /><br />
      Error: <?php echo(implode(", ", $_SESSION['errors'])) ?>
      <br /><br />
      <a href="javascript:window.close();">Закрой это окно</a> или <a href="index.php">Попробуй добавить вручную</a>;
    <?php endif; ?>
  </body>
</html>
