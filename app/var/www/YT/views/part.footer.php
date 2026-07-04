<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); } ?>
<?php
$yt_dlp_version = 'yt-dlp: Ошибка';
if (file_exists('yt_dlp_version')) {
    $content = @file_get_contents('yt_dlp_version');
    if ($content !== false) {
        $yt_dlp_version = 'yt-dlp: ' . htmlspecialchars(trim($content), ENT_QUOTES, 'UTF-8');
    }
}

$proxyFeature = class_exists('ProxyStatus') && ProxyStatus::feature_on();
$proxyEnabled = $proxyFeature && ProxyStatus::enabled();
$proxyWindows = $proxyEnabled ? ProxyStatus::get_windows() : [];
$proxyState = $proxyEnabled ? ProxyStatus::overall_state($proxyWindows) : 'pending';
$proxyDotClass = function ($v) {
    if ($v === null) return 'is-pending';
    if ($v === 'warn') return 'is-warn';
    return $v === 'death' ? 'is-death' : 'is-work';
};
?>
<footer class="footer">
  <div class="footer-wrapper">
    <div class="panel panel-info">
      <div data-ui="help" style="cursor: pointer;" class="panel-heading">
        <h3 id="helplink" class="panel-title">Жмак</h3>
      </div>
      <div id="helppanel" class="panel-body panel-collapsed">
        <table class="table table-hover footer-table">
          <tr>
            <td><b>О версии:</b></td>
            <td>
              C обходом замедления YouTube!<br>
              <div id="yt-dlp-version" class="text-muted small"><?= $yt_dlp_version ?></div>
<?php if ($proxyFeature): ?>
              <div id="proxy-status" class="proxy-status small" data-state="<?= $proxyEnabled ? htmlspecialchars($proxyState, ENT_QUOTES, 'UTF-8') : 'unset' ?>" title="Живость прокси: окна 1 / 5 / 15 минут">
                <span class="proxy-status-label" title="Зелёный - прокси работает&#10;Жёлтый - редкие пропуски&#10;Красный - прокси недоступен&#10;Точки: окна 1 / 5 / 15 минут">proxy</span>
<?php if ($proxyEnabled): ?>
                <span class="proxy-dots">
                  <i class="proxy-dot <?= $proxyDotClass($proxyWindows['1'] ?? null) ?>" data-win="1" title="1 минута"></i>
                  <i class="proxy-dot <?= $proxyDotClass($proxyWindows['5'] ?? null) ?>" data-win="5" title="5 минут"></i>
                  <i class="proxy-dot <?= $proxyDotClass($proxyWindows['15'] ?? null) ?>" data-win="15" title="15 минут"></i>
                </span>
<?php else: ?>
                <span class="proxy-status-unset">Прокси не установлен</span>
<?php endif; ?>
              </div>
<?php endif; ?>
            </td>
          </tr>
          <!--<tr>
            <td><b>Свободно места на сервере:</b></td>
            <td><?= htmlspecialchars($file->free_space(), ENT_QUOTES, 'UTF-8') ?>B</td>
          </tr>
          <tr>
            <td><b>Папка Загрузки:</b></td>
            <td><?= htmlspecialchars($file->get_downloads_folder(), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>-->
          <tr>
            <td><b>Как это работает?</b></td>
            <td>
              <ul class="footer-list">
                <li>Вставьте ссылку в поле "Скачать"</li>
                <li>Во вкладке "Видео" находятся готовые файлы</li>
                <li>Во вкладке "Загрузки" можно увидеть историю загрузок и их статус</li>
              </ul>
              <small>Загрузка файлов списком: <code>vk.com/xx1||vk.com/xx2||vk.com/xx3</code></small>
            </td>
          </tr>
          <tr>
            <td><b>Как забрать файлы с сервера?</b></td>
            <td>
              Нажать на <a href="#videos" data-goto="vid_link" aria-expanded="false">файл</a> или открыть в новой вкладке
            </td>
          </tr>
          <tr>
            <td><b>Ошибка?</b></td>
            <td>
              Проверить корректность URL<br>
              Ссылка должна быть прямой и указывать на видео или плейлист<br>
              Файл уже скачан - <a href="#downloads" data-goto="dl_link" aria-expanded="false">проверь историю</a> или <a href="#videos" data-goto="vid_link" aria-expanded="false">имя файла</a><br>
              "В процессе" - файл конвертируется в mp4<br>
              Бэкенд еще не поддерживает ваш ресурс
            </td>
          </tr>
          <tr>
            <td><b>Полезные ссылки</b></td>
            <td>
              <a target="_blank" rel="noopener noreferrer" href="https://ezgif.com/video-to-gif">Видео в гиф</a> &nbsp;&nbsp;
              <a target="_blank" rel="noopener noreferrer" href="https://github.com/mikedigriz/YT">GitHub</a>
            </td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</footer>
</body>
</html>