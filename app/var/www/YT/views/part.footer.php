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
      <div data-ui="help" class="panel-heading" tabindex="0" role="button" aria-controls="helppanel" aria-expanded="false">
        <h3 id="helplink" class="panel-title">Жмак</h3>
      </div>
      <div id="helppanel" class="panel-body panel-collapsed">
       <div class="footer-inner">

        <div class="footer-status">
          <span class="footer-chip" id="yt-dlp-version"><?= $yt_dlp_version ?></span>
<?php if ($proxyFeature): ?>
<?php if ($proxyEnabled): ?>
          <span class="footer-chip footer-chip-proxy footer-tip" id="proxy-status" tabindex="0"
                data-state="<?= htmlspecialchars($proxyState, ENT_QUOTES, 'UTF-8') ?>"
                data-tip="Зелёный - работает, жёлтый - редкие пропуски, красный - недоступен, серый - данных пока нет. Точки: последние 1, 5 и 15 минут.">
            <span class="proxy-status-label">Прокси</span>
            <span class="proxy-dots">
              <i class="proxy-dot <?= $proxyDotClass($proxyWindows['1'] ?? null) ?>" data-win="1"></i>
              <i class="proxy-dot <?= $proxyDotClass($proxyWindows['5'] ?? null) ?>" data-win="5"></i>
              <i class="proxy-dot <?= $proxyDotClass($proxyWindows['15'] ?? null) ?>" data-win="15"></i>
            </span>
          </span>
<?php else: ?>
          <span class="footer-chip footer-chip-proxy footer-tip" id="proxy-status" tabindex="0" data-state="unset"
                data-tip="Прокси не задан - качаем напрямую.">
            <span class="proxy-status-label">Без прокси</span>
          </span>
<?php endif; ?>
<?php endif; ?>
        </div>

        <div class="footer-grid">

          <section class="footer-card">
            <h4 class="footer-card-title">С чего начать</h4>
            <ol class="footer-list">
              <li>Вставь ссылку в поле</li>
              <li>Нажми «Скачать»</li>
              <li>Готовый файл появится во вкладке <a href="#videos" data-goto="vid_link">Видео</a></li>
              <li>Нажми на него, чтобы забрать</li>
            </ol>
          </section>

          <section class="footer-card">
            <h4 class="footer-card-title">Несколько ссылок сразу</h4>
            <p>Кидай списком: по одной в строке или через пробел. Скачаются все.</p>
            <pre class="footer-code">https://site.ru/video1
https://site.ru/video2
https://other.tv/clip</pre>
          </section>

          <section class="footer-card">
            <h4 class="footer-card-title">Не всё на виду</h4>
            <ul class="footer-list">
              <li>Долгое нажатие на «Скачать» - выбор качества и перевод озвучки</li>
              <li>Кнопка QR - забрать файл на телефон</li>
              <li>Кнопка Play - смотреть и слушать прямо тут, не скачивая</li>
              <li>Снегирь тут не для красоты</li>
            </ul>
          </section>

          <section class="footer-card">
            <h4 class="footer-card-title">Если не скачалось</h4>
            <ul class="footer-list">
              <li>Ссылка должна вести на само видео или плейлист</li>
              <li>Возможно, уже скачано: посмотри <a href="#downloads" data-goto="dl_link">историю</a> или <a href="#videos" data-goto="vid_link">имя файла</a></li>
              <li>«В процессе» - файл ещё конвертируется в mp4</li>
              <li>Некоторые сайты пока не поддерживаются</li>
            </ul>
          </section>

        </div>

        <div class="footer-useful">
         <h4 class="footer-card-title">Полезное</h4>
         <div class="footer-links">
          <a class="footer-link-btn footer-link-btn-github" target="_blank" rel="noopener noreferrer"
             href="https://github.com/mikedigriz/YT" aria-label="Исходники проекта на GitHub" title="Исходники проекта на GitHub">
            <svg class="footer-github-mark" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 1C5.923 1 1 5.923 1 12c0 4.867 3.149 8.979 7.521 10.436.55.096.756-.233.756-.522 0-.262-.013-1.128-.013-2.049-2.764.509-3.479-.674-3.699-1.292-.124-.317-.66-1.293-1.127-1.554-.385-.207-.936-.715-.014-.729.866-.014 1.485.797 1.691 1.128.99 1.663 2.571 1.196 3.204.907.096-.715.385-1.196.701-1.471-2.448-.275-5.005-1.224-5.005-5.432 0-1.196.426-2.186 1.128-2.956-.111-.275-.496-1.402.11-2.915 0 0 .921-.288 3.024 1.128a10.193 10.193 0 0 1 2.75-.371c.936 0 1.871.123 2.75.371 2.104-1.43 3.025-1.128 3.025-1.128.605 1.513.22 2.64.11 2.915.702.77 1.127 1.746 1.127 2.956 0 4.222-2.571 5.157-5.019 5.432.399.344.743 1.004.743 2.035 0 1.471-.014 2.654-.014 3.025 0 .289.206.632.756.522C19.851 20.979 23 16.854 23 12c0-6.077-4.922-11-11-11Z"/></svg>GitHub<svg class="footer-github-star" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="m12 2.6 2.83 6.05 6.42.87-4.7 4.6 1.16 6.55L12 17.55 6.29 20.67l1.16-6.55-4.7-4.6 6.42-.87L12 2.6Z"/></svg>
          </a>
          <a class="footer-link-btn" target="_blank" rel="noopener noreferrer" href="https://ezgif.com/video-to-gif">Видео в гиф</a>
         </div>
        </div>

       </div>
      </div>
    </div>
  </div>
</footer>
</body>
</html>
