<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); } ?>
<?php
    $showLifetime = isset($config['showFileLifetime']) && $config['showFileLifetime'];
?>
<?php
$video_hidden_class = $audio_check ? ' is-hidden' : '';
$audio_hidden_class = $audio_check ? '' : ' is-hidden';
// Если $video_form_style/$audio_form_style содержат display, убери его:
$video_form_style = preg_replace('/display\s*:\s*[^;]+;?/i', '', $video_form_style);
$audio_form_style = preg_replace('/display\s*:\s*[^;]+;?/i', '', $audio_form_style);
?>
<script>
var showFileLifetime = <?php echo $showLifetime ? 'true' : 'false'; ?>;
</script>
<div class="container" style="margin-bottom: 50px;">
    <ul id="mainnav" class="nav nav-tabs ">
        <li class="active"><a id="home_link" href="#home" aria-expanded="true">Домой</a></li>
        <li><a id="dl_link" href="#downloads" aria-expanded="false">Загрузки</a></li>
        <li><a id="vid_link" href="#videos" aria-expanded="false">Видео<span class="tab-badge"
                    id="video-badge"></span></a></li>
        <li><a id="music_link" href="#music" aria-expanded="false">Музыка<span class="tab-badge"
                    id="music-badge"></span></a></li>
    </ul>
    <div id="myTabContent" class="tab-content">
            <div class="tab-pane fade active in" id="home">
                <div id="snej" class="snej-animation" style="pointer-events: none">
                    <div class="snej-eye-wrap">
                        <input type="image" src="img/snej.webp" title="Снежик" fetchpriority="high" draggable="false" style="pointer-events: none; -webkit-user-drag: none; user-select: none;">
                        <span class="snej-eye-glow"></span>
                        <span class="snej-eye-laser"></span>
                        <span class="snej-hit-area" style="pointer-events: auto; cursor: pointer;"></span>
                    </div>
                </div>
            <div class="row">
                <br />
                <h1 style="text-align: center;"><?php echo($config['siteName']); ?></h1><br />
                <?php if(isset($_SESSION['errors']) && $_SESSION['errors'] > 0) : ?>
                <?php foreach ($_SESSION['errors'] as $e): ?>
                <div class="alert alert-warning" role="alert"><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endforeach; ?>
                <?php endif; ?>
                <form id="download-form" class="form-horizontal" action="index.php" method="post">
                    <div class="form-group">
                        <div class="col-md-12">
                            <div class="url-input-wrapper">
                                <input class="form-control url-input-animation" id="url" name="urls"
                                    value=""
                                    placeholder="Ссылка на видео..." type="text">
                                <div id="url-clear" class="url-clear-btn" title="Очистить поле">
                                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor"
                                        stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="18" y1="6" x2="6" y2="18"></line>
                                        <line x1="6" y1="6" x2="18" y2="18"></line>
                                    </svg>
                                </div>
                                <div id="url-favicon" class="url-favicon" title="Очистить поле">
                                    <img id="url-favicon-img" src="" alt="">
                                </div>
                            </div>
                            <div id="clipboard-magic-prompt" class="clipboard-magic-prompt is-hidden">
                                <div class="clipboard-magic-bubble">
                                    <span>Включить магию вставки?</span>
                                    <div class="clipboard-magic-actions">
                                        <button type="button" id="clipboard-magic-yes" class="clipboard-magic-btn clipboard-magic-btn-yes" title="Я буду забирать только ссылку и ничего больше">Да</button>
                                        <button type="button" id="clipboard-magic-no" class="clipboard-magic-btn clipboard-magic-btn-no" title="Больше не спрошу, пока не сбросишь куки">Нет</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="minimal-controls-wrapper">

                                <div class="btn-download-wrapper">
                                    <button type="submit" class="btn btn-primary btn-download-minimal">Скачать</button>
                                    <div class="quality-popup-menu" id="quality-popup">
                                        <div class="quality-popup-item" data-format="4K">
                                            <span class="quality-label">4K</span>
                                            <span class="quality-desc">2160p</span>
                                        </div>
                                        <div class="quality-popup-item" data-format="1440p">
                                            <span class="quality-label">2K</span>
                                            <span class="quality-desc">1440p</span>
                                        </div>
                                        <div class="quality-popup-item" data-format="1080p">
                                            <span class="quality-label">1080p</span>
                                            <span class="quality-desc">Full HD</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="controls-row">

                                    <!-- ЛЕВАЯ ЧАСТЬ -->
                                    <div class="controls-left">
                                        <label class="minimal-toggle"
                                            <?php echo($config['disableExtraction'] ? " style=\"display: none;\"" : ""); ?>>
                                            <input type="checkbox" id="ui_audio_mode" class="toggle-input-main"
                                                <?php echo($audio_check); ?> onchange="syncLogic()">
                                            <span class="toggle-track"></span>
                                            <span class="toggle-text">В аудио</span>
                                        </label>
                                    </div>

                                    <!-- РАЗДЕЛИТЕЛЬ (строго по центру) -->
                                    <div class="control-divider"></div>

                                    <!-- ПРАВАЯ ЧАСТЬ -->
                                    <div class="controls-right">
                                        <div class="quality-switch-wrapper<?php echo $video_hidden_class; ?>"
                                            id="params-video" <?php echo($video_form_style); ?>>
                                            <span class="side-label label-left">Топ</span>
                                            <label class="minimal-toggle-inner">
                                                <input type="checkbox" id="ui_quality_toggle"
                                                    class="toggle-input-single"
                                                    onchange="syncLogic()">
                                                <span class="toggle-track"></span>
                                            </label>
                                            <span class="side-label label-right">
                                                <span class="bullshit-text">
                                                    Булшит
                                                    <span class="poop-icon">💩</span>
                                                </span>
                                            </span>
                                        </div>

                                        <div class="audio-switches-wrapper<?php echo $audio_hidden_class; ?>"
                                            id="params-audio" <?php echo($audio_form_style); ?>>
                                            <label class="minimal-toggle-inner">
                                                <input type="checkbox" class="toggle-input-sub" data-value="mp3-high"
                                                    checked onchange="syncSubToggles(this)">
                                                <span class="toggle-track"></span>
                                                <span class="toggle-text">HQ</span>
                                            </label>
                                            <label class="minimal-toggle-inner">
                                                <input type="checkbox" class="toggle-input-sub" data-value="mp3"
                                                    onchange="syncSubToggles(this)">
                                                <span class="toggle-track"></span>
                                                <span class="toggle-text">MP3</span>
                                            </label>
                                            <label class="minimal-toggle-inner">
                                                <input type="checkbox" class="toggle-input-sub" data-value="wav"
                                                    onchange="syncSubToggles(this)">
                                                <span class="toggle-track"></span>
                                                <span class="toggle-text">WAV</span>
                                            </label>
                                        </div>
                                    </div>

                                </div>

                                <div style="display: none !important;">
                                    <input id="audio_convert" type="checkbox" name="audio">
                                    <select name="audio_format" id="audio_format">
                                        <option value="mp3-high">mp3 HQ</option>
                                        <option value="mp3">mp3</option>
                                        <option value="wav">wav</option>
                                    </select>
                                    <select name="format" id="format">
                                        <option value="top">Топ</option>
                                        <option value="worst">Булшит</option>
                                        <option value="4K">4K</option>
                                        <option value="1440p">2K</option>
                                        <option value="1080p">Full HD</option>
                                    </select>
                                </div>

                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="tab-pane fade" id="downloads">
            <div style="text-align: center;" class="row">
                <br /><br />
                <h4>Текущие Загрузки</h4>
                <div class="table-responsive">
                <table style="text-align: left;" class="table table-striped table-hover ">
                    <thead>
                        <tr>
                            <th style="width: 10%; height:35px;">Сайт/Тип</th>
                            <th>Файл</th>
                            <th style="width: 25%;">Статус</th>
                            <th style="width: 120px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="dlprogress">
                        <tr>
                            <td colspan="4">Буп-Буп понеслась! Жди...</td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <br /><br />
                <?php if(!$config['disableQueue']) : ?>
                <h4>Очередь</h4>
                <div class="table-responsive">
                <table style="text-align: left;" class="table table-striped table-hover ">
                    <thead>
                        <tr>
                            <th style="height:35px;">URL</th>
                            <th style="width: 120px;">Формат</th>
                            <th style="width: 120px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="dlqueue">
                        <tr>
                            <td colspan="3">Добавляю в очередь ждемс...</td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <br /><br />
                <?php endif; ?>
                <h4>Последние Загрузки</h4>
                <div class="table-responsive">
                <table style="text-align: left;" class="table table-striped table-hover ">
                    <thead>
                        <tr>
                            <th style="width: 10%; height:35px;">Сайт/Тип</th>
                            <th>Файл/Плейлист</th>
                            <th style="width: 25%;">Статус</th>
                            <th style="width: 180px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="dlcompleted">
                        <tr>
                            <td colspan="4">Получаю загрузки...</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="videos">
            <br /><br />
            <h4 style="text-align: center;">Загруженные Видео</h4>
            <div class="table-responsive">
            <table style="text-align: left;" class="table table-striped table-hover ">
                <thead>
                    <tr>
                        <th style="height:35px; min-width:300px;">Файл <?php if ($showLifetime): ?><small
                                class="text-muted"
                                style="font-weight: 400; font-size: 12px; margin-left: 8px;">(автоудаление через 2
                                часа)</small></th><?php endif; ?>
                        <th style="width:80px">Размер</th>
                        <?php if ($config['allowFileDelete']) : ?>
                        <th style="width:110px">Действия</th>
                        <?php else: ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="videofiles">
                    <tr>
                        <td colspan="3">Получаю видео, обажди...</td>
                    </tr>
                </tbody>
            </table>
            </div>
            <br /><br />
        </div>
        <div class="tab-pane fade" id="music">
            <br /><br />
            <h4 style="text-align: center;">Загруженные Аудио</h4>
            <div class="table-responsive">
            <table style="text-align: left;" class="table table-striped table-hover ">
                <thead>
                    <tr>
                        <th style="height:35px; min-width:300px;">Файл <?php if ($showLifetime): ?><small
                                class="text-muted"
                                style="font-weight: 400; font-size: 12px; margin-left: 8px;">(автоудаление через 2
                                часа)</small></th><?php endif; ?>
                        <th style="width:80px">Размер</th>
                        <?php if ($config['allowFileDelete']) : ?>
                        <th style="width:110px">Действия</th>
                        <?php else: ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="musicfiles">
                    <tr>
                        <td colspan="3">Получаю аудио, ждемс...</td>
                    </tr>
                </tbody>
            </table>
            </div>
            <br /><br />
        </div>
    </div>
</div>
<script>
function showTab(link) {
    var id = link.getAttribute('href').substr(1);

    document.querySelectorAll('#mainnav > li').forEach(function (li) {
        li.classList.remove('active');
    });
    document.querySelectorAll('#mainnav a[aria-expanded]').forEach(function (a) {
        a.setAttribute('aria-expanded', 'false');
    });
    link.closest('li').classList.add('active');
    link.setAttribute('aria-expanded', 'true');

    document.querySelectorAll('#myTabContent > .tab-pane').forEach(function (pane) {
        pane.classList.remove('active', 'in');
    });
    var pane = document.getElementById(id);
    if (pane) {
        pane.classList.add('active', 'in');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#mainnav a').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.hash = this.getAttribute('href').substr(1);
            showTab(this);
        });
    });

    var hash = window.location.hash;
    if (hash) {
        var initialLink = document.querySelector('#mainnav a[href="' + hash + '"]');
        if (initialLink) {
            showTab(initialLink);
        }
    }
});
</script>