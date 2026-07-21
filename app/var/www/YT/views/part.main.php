<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); } ?>
<?php
    $showLifetime = isset($config['showFileLifetime']) && $config['showFileLifetime'];
    $retentionMinutes = (int)($config['retentionMinutes'] ?? 120);
    if ($retentionMinutes <= 0) $retentionMinutes = 120;
?>
<?php
$video_hidden_class = $audio_check ? ' is-hidden' : '';
$audio_hidden_class = $audio_check ? '' : ' is-hidden';
$video_form_style = preg_replace('/display\s*:\s*[^;]+;?/i', '', $video_form_style);
$audio_form_style = preg_replace('/display\s*:\s*[^;]+;?/i', '', $audio_form_style);
?>
<script nonce="<?= htmlspecialchars($cspNonce ?? '', ENT_QUOTES) ?>">
var showFileLifetime = <?php echo $showLifetime ? 'true' : 'false'; ?>;
var retentionMinutes = <?php echo $retentionMinutes; ?>;
var allowFileDelete = <?php echo $config['allowFileDelete'] ? 'true' : 'false'; ?>;
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
    <?php if (!empty($_SESSION['errors'])) : ?>
    <?php foreach ($_SESSION['errors'] as $e): ?>
    <div class="alert alert-warning" role="alert"><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endforeach; ?>
    <?php endif; ?>
    <div id="myTabContent" class="tab-content">
            <div class="tab-pane fade active in" id="home">
                <div id="snej" class="snej-animation" style="pointer-events: none">
                    <div class="snej-eye-wrap">
                        <input type="image" src="<?= htmlspecialchars($mascotImg ?? 'img/snej.webp', ENT_QUOTES, 'UTF-8') ?>" alt="" title="Снежик" tabindex="-1" fetchpriority="high" draggable="false" style="pointer-events: none; -webkit-user-drag: none; user-select: none;">
                        <span class="snej-eye-glow"></span>
                        <span class="snej-eye-laser"></span>
                    </div>
                </div>
                <!-- #snej позиционируется позади поля ввода (без z-index), поэтому его
                картинка не перекрывает #url. Кликабельная зона головы вынесена в отдельный
                невидимый #snej-click с тем же position/размером и приподнятым z-index -
                у position:fixed всегда свой стекинг-контекст, увидеть картинку "позади", но
                кликать "поверх" одним и тем же элементом нельзя. Визуальные эффекты (тряска,
                свечение, лазер) по-прежнему играют на видимом #snej - см. initSnejEasterEgg(). -->
                <div id="snej-click" class="snej-animation" style="pointer-events: none" aria-hidden="true">
                    <div class="snej-eye-wrap">
                        <input type="image" src="<?= htmlspecialchars($mascotImg ?? 'img/snej.webp', ENT_QUOTES, 'UTF-8') ?>" alt="" tabindex="-1" draggable="false" style="pointer-events: none; visibility: hidden;">
                        <span class="snej-hit-area" style="visibility: visible; pointer-events: auto; cursor: pointer;" tabindex="0" role="button" aria-label="Снежик"></span>
                    </div>
                </div>
            <div class="row">
                <br />
                <h1 style="text-align: center;"><?php echo($config['siteName']); ?></h1>
                <?php if (!empty($isWinterMascot)):
                    // Разовая случайная фраза при загрузке страницы - для разнообразия у постоянных
                    // посетителей. Гейт тот же $isWinterMascot, что и у остального зимнего режима,
                    // новый конфиг-ключ не заводим. Один из вариантов - настоящий счётчик дней
                    // (до Нового года в ноябре-декабре, до конца "зимней магии" в январе-марте),
                    // остальные - шутливые фиксированные фразы в тоне сайта.
                    function winterDaysWord(int $n): string {
                        $n = abs($n) % 100;
                        $n1 = $n % 10;
                        if ($n > 10 && $n < 20) return 'дней';
                        if ($n1 > 1 && $n1 < 5) return 'дня';
                        if ($n1 === 1) return 'день';
                        return 'дней';
                    }
                    $today = new DateTime('today');
                    if ($nowMonth === 11 || $nowMonth === 12) {
                        // Ноябрь и декабрь - до ближайшего 1 января всегда следующий год.
                        $target = new DateTime(((int) date('Y') + 1) . '-01-01');
                        $daysLeft = (int) $today->diff($target)->format('%a');
                        $countdownPhrase = "До Нового года осталось {$daysLeft} " . winterDaysWord($daysLeft) . '.';
                    } else {
                        // Январь-март - до 1 апреля текущего года (конец календарной зимы).
                        // Если дата уже прошла (например, оверрайд 'on' форсирован вне зимних
                        // месяцев для теста - см. config['winterMascot']) - берём 1 апреля
                        // следующего года, чтобы счётчик всегда смотрел вперёд, а не назад.
                        $target = new DateTime((int) date('Y') . '-04-01');
                        if ($target <= $today) {
                            $target = new DateTime(((int) date('Y') + 1) . '-04-01');
                        }
                        $daysLeft = (int) $today->diff($target)->format('%a');
                        $countdownPhrase = "Зимней магии осталось ещё {$daysLeft} " . winterDaysWord($daysLeft) . '.';
                    }
                    $winterGreetings = [
                        'Снежик утеплился. Сервер - как обычно, без шапки.',
                        'На улице гололёд, а мы тут как обычно качаем и качаем.',
                        'Снежик мёрзнет молча - тела у него всё равно нет, один клюв.',
                        'Дед Мороза не завезли, зато Снежик работает и в -30.',
                        'Пока где-то лепят снеговика, мы тут лепим видео в mp4.',
                        'Один снежный сезон - и Снежик уже мнит себя Дедом Морозом.',
                        'Снежик надел шапку. Лыжи и сноуборд - в следующей версии.',
                        'На улице минус, в очереди загрузок - плюс.',
                        'Гирлянду не завезли, зато у Снежика глаза светятся и так.',
                        'Кто-то верит в Деда Мороза, а мы верим, что сервер не упадёт в январе.',
                        'Снежик подмигивает из-под сугроба - привет от зимнего режима.',
                        'С наступающим! Или уже наступившим - Снежик со счётом не дружит.',
                        'Мороз крепчает, а видео как качалось, так и качается.',
                        'Снежик смотрит на снегопад с философским видом. Ему идёт.',
                        'Мандарины уже пахнут Новым годом, а видео - всё ещё буферизацией.',
                        'Оливье готовят с ноября - традиция сильнее, чем очередь загрузок.',
                        'Куранты бьют, а yt-dlp качает - у каждого свои полночные ритуалы.',
                        'Дед Мороз дарит подарки раз в год. Мы - каждый раз, когда жмёте "Скачать".',
                        'Оливье, мандарины, буферизация - три вечных спутника зимы.',
                        'Ирония судьбы: и там, и тут кто-то ждёт, пока что-то загрузится.',
                        'Загадай желание под курантами. Второе - чтобы видео скачалось с первого раза.',
                        'Шампанское откроют в полночь. Прокси - когда получится.',
                        'Пока Дед Мороз собирает мешок, мы собираем очередь загрузок.',
                        'Голубой огонёк по телевизору, зелёный - на кнопке "Скачать".',
                        'С Новым годом! Дарим традиционные глюки, но с любовью.',
                        'Мороз и солнце. И, конечно, видео в очереди.',
                        'Ёлку наряжают раз в год. Прогресс-бар - хоть каждую минуту.',
                        'Снег идёт третий день подряд. Загрузка - как повезёт.',
                        'Скачивание без единой ошибки - тоже маленькое новогоднее чудо.',
                        'Пока все загадывают желания, мы просто ждём, пока дойдёт до 100%.',
                        'Бой курантов длится 36 секунд. Загрузка видео - как повезёт с сервером.',
                        'На Новый год все немного волшебники. Мы - просто качаем видео.',
                        'Хвойный запах не прилагается, зато видео - да.',
                        'Пока где-то наряжают ёлку, у нас наряжается очередь загрузок.',
                        'Новогодняя ночь одна на всех. Очередь загрузок - у каждого своя.',
                        'Мандарины закончатся к январю. Видео в очереди - не закончатся никогда.',
                        'Всё, что нужно под Новый год: мандарины, оливье и стабильный интернет.',
                        'Салют на улице, прогресс-бар на экране - у каждого свой фейерверк.',
                        'Обещаем: эта загрузка пройдёт лучше, чем прошлогодние обещания.',
                        'Готовь оливье летом, а видео качай зимой - народная мудрость обновлена.',
                        'Пусть в новом году будет меньше ошибок. Начиная с этой загрузки.',
                        'Пока часы бьют полночь, у нас тут своя традиция - обновить страницу.',
                        'Дед Мороз приходит один раз. yt-dlp - хоть каждые пять минут.',
                        'Пусть все ссылки будут рабочими, а видео - в хорошем качестве.',
                        'Ёлочные игрушки бьются реже, чем падает соединение. Почти.',
                        'Пока бенгальские огни догорают, видео как раз должно докачаться.',
                        'Новый год - повод обновить обещания. И, может быть, yt-dlp заодно.',
                        'Традиционно желаем здоровья, счастья и стабильного пинга.',
                        'Пусть под ёлкой будут подарки, а в очереди - только успешные загрузки.',
                        'Зима - время носков, мандаринов и терпеливого ожидания прогресс-бара.',
                        'Из искренних пожеланий: пусть сервер не упадёт хотя бы сегодня.',
                        'Бой курантов - раз в году. Ошибка 403 - к сожалению, чаще.',
                        'Настоящее новогоднее чудо выглядит как строка прогресса на 99%, застывшая всего на секунду.',
                        'Оливье намешали, телевизор включили, видео поставили качаться - всё по плану.',
                        'Пусть все планы сбудутся, а видео скачаются с первого раза.',
                        'Загадывать желание под бой курантов - древняя традиция. Обновлять страницу - тоже, но новая.',
                        'На Новый год бывает всякое чудо. Например, загрузка без единой ошибки.',
                        'Пусть в этом году будет меньше дедлайнов и больше готовых видео.',
                        'Мандарины, гирлянда, стабильный интернет - три кита новогоднего настроения.',
                        'Новый год - единственный праздник, где ждать полночи не скучно, а традиционно.',
                        'Пусть оливье удастся, а видео скачается с первой попытки - минимальная программа на праздник.',
                        'Где-то открывают шампанское. У нас открывается очередь загрузок.',
                        'Всё, что нужно для праздника: гирлянда, мандарины и работающий интернет.',
                        'С Новым годом! Пусть всё качается легко - и настроение, и видео.',
                        $countdownPhrase,
                    ];
                    $winterGreeting = $winterGreetings[array_rand($winterGreetings)];
                ?>
                <p class="winter-greeting"><?= htmlspecialchars($winterGreeting, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <br />
                <form id="download-form" class="form-horizontal" action="index.php" method="post">
                    <div class="form-group">
                        <div class="col-md-12">
                            <div class="url-input-wrapper">
                                <input class="form-control url-input-animation" id="url" name="urls"
                                    value="<?php echo htmlspecialchars($sharedUrl ?? '', ENT_QUOTES, 'UTF-8'); ?>"
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
                                        <div class="quality-popup-item quality-popup-item-translate" data-format="translate">
                                            <span class="quality-label">Перевод 🇷🇺</span>
                                            <span class="quality-desc">Озвучка Яндекс</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="controls-row">

                                    <div class="controls-left">
                                        <label class="minimal-toggle"
                                            <?php echo($config['disableExtraction'] ? " style=\"display: none;\"" : ""); ?>>
                                            <input type="checkbox" id="ui_audio_mode" class="toggle-input-main"
                                                <?php echo($audio_check); ?>>
                                            <span class="toggle-track"></span>
                                            <span class="toggle-text">В аудио</span>
                                        </label>
                                    </div>

                                    <div class="control-divider"></div>

                                    <div class="controls-right">
                                        <div class="quality-switch-wrapper<?php echo $video_hidden_class; ?>"
                                            id="params-video" <?php echo($video_form_style); ?>>
                                            <span class="side-label label-left">Топ</span>
                                            <label class="minimal-toggle-inner">
                                                <input type="checkbox" id="ui_quality_toggle"
                                                    class="toggle-input-single">
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
                                                    checked>
                                                <span class="toggle-track"></span>
                                                <span class="toggle-text">HQ</span>
                                            </label>
                                            <label class="minimal-toggle-inner">
                                                <input type="checkbox" class="toggle-input-sub" data-value="mp3">
                                                <span class="toggle-track"></span>
                                                <span class="toggle-text">MP3</span>
                                            </label>
                                            <label class="minimal-toggle-inner">
                                                <input type="checkbox" class="toggle-input-sub" data-value="wav">
                                                <span class="toggle-track"></span>
                                                <span class="toggle-text">WAV</span>
                                            </label>
                                        </div>
                                    </div>

                                </div>

                                <div style="display: none !important;">
                                    <input id="audio_convert" type="checkbox" name="audio">
                                    <input type="hidden" name="translate" id="translate_field" value="">
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
                <br />
                <h4>Текущие Загрузки</h4>
                <div class="table-responsive">
                <table style="text-align: left;" class="table table-striped table-hover ">
                    <thead>
                        <tr>
                            <th style="width: 10%; height:35px; white-space:nowrap;">Сайт/Тип</th>
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
                <br />
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
                <br />
                <?php endif; ?>
                <h4>Последние Загрузки</h4>
                <div class="table-responsive">
                <table style="text-align: left;" class="table table-striped table-hover ">
                    <thead>
                        <tr>
                            <th style="width: 10%; height:35px; white-space:nowrap;">Сайт/Тип</th>
                            <th>Файл/Плейлист</th>
                            <th style="width: 25%;">Статус</th>
                            <th style="width: 180px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="dlcompleted">
                        <tr>
                            <td colspan="4" class="skeleton-shimmer">Получаю загрузки...</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="videos">
            <br />
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
                        <td colspan="3" class="skeleton-shimmer">Получаю видео, обажди...</td>
                    </tr>
                </tbody>
            </table>
            </div>
            <br />
        </div>
        <div class="tab-pane fade" id="music">
            <br />
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
                        <td colspan="3" class="skeleton-shimmer">Получаю аудио, ждемс...</td>
                    </tr>
                </tbody>
            </table>
            </div>
            <br />
        </div>
    </div>
</div>
<script nonce="<?= htmlspecialchars($cspNonce ?? '', ENT_QUOTES) ?>">
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