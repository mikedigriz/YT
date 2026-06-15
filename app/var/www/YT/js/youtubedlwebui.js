const CONFIG = {
    refreshInterval: 3000
};

// Кэшированные элементы DOM
const $ui = {};

// Переменная для хранения ID завершенных задач с прошлого тика опроса
let previousFinishedPids = null;

function playNotificationSound(isSuccess) {
    // Если звук выключен, просто выходим из функции
    if (isSoundMuted) return;

    // Выбираем нужный аудиофайл в зависимости от результата
    const audioFile = isSuccess ? 'finish_job.mp3' : 'error_job.mp3';
    const audio = new Audio(audioFile);
    audio.volume = 0.5;

    audio.play().catch(error => {
        console.warn("Браузер заблокировал автовоспроизведение звука:", error);
    });
}

function isDownloadFailed(item) {
    const status = item.status || "";
    const type = item.type || "";

    // Проверяем статус на ключевые слова ошибок
    if (status.includes("Отменено") ||
        status.includes("Ошибка") ||
        status.includes("Порнографию") ||
        status.includes("не та ссылка")) {
        return true;
    }

    // Проверяем тип задачи
    if (type === "unknown") {
        return true;
    }

    return false;
}

// УПРАВЛЕНИЕ ЗВУКОВЫМИ УВЕДОМЛЕНИЯМИ

// 1. Определяем мобильное устройство
const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || (window.innerWidth <= 768 && 'ontouchstart' in window);

// 2. Читаем сохраненное состояние (только для десктопа)
let isSoundMuted = localStorage.getItem('yt_sound_muted') === 'true';

// 3. Функция проверки: находимся ли мы на главной странице («Home»)
function isOnHomePage() {
    const hash = window.location.hash.toLowerCase();
    // Проверяем пустой хэш (стартовая страница) или явно указанный #home
    return hash === '' || hash === '#home' || hash === '#';
}

// 4. Глобальная ссылка на элемент кнопки (для управления видимостью)
let soundToggleBtn = null;

// 5. Функция обновления видимости кнопки
function updateButtonVisibility() {
    if (!soundToggleBtn) return;
    // Показываем кнопку ТОЛЬКО если мы на вкладке "Домой"
    soundToggleBtn.style.display = isOnHomePage() ? 'block' : 'none';
}

// 6. Функция инициализации кнопки
function initSoundToggle() {
    // Если это мобильное устройство, прерываем функцию: иконка НЕ будет создана
    if (isMobileDevice) return;

    // Если кнопка уже есть в DOM, просто обновляем её видимость и выходим
    if (document.getElementById('sound-toggle')) {
        soundToggleBtn = document.getElementById('sound-toggle');
        updateButtonVisibility();
        return;
    }

    const btn = document.createElement('div');
    btn.id = 'sound-toggle';
    btn.style.cssText = `
        position: fixed; 
        top: 15px; 
        right: 15px; 
        cursor: pointer; 
        font-size: 22px; 
        color: #888; 
        opacity: 0.1; 
        transition: all 0.3s ease; 
        z-index: 9999; 
        user-select: none;
        background: rgba(255,255,255,0.8);
        padding: 5px 8px;
        border-radius: 50%;
        display: none; /* Скрыта по умолчанию, пока не проверим текущую вкладку */
    `;

    soundToggleBtn = btn;
    updateSoundButtonVisuals(btn);
    updateButtonVisibility(); // Применяем видимость в зависимости от текущей вкладки

    // Эффекты при наведении
    btn.addEventListener('mouseenter', () => {
        btn.style.opacity = '1';
        btn.style.color = '#333';
        btn.style.background = 'rgba(255,255,255,1)';
        btn.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
    });

    btn.addEventListener('mouseleave', () => {
        btn.style.opacity = '0.1';
        btn.style.color = '#888';
        btn.style.background = 'rgba(255,255,255,0.8)';
        btn.style.boxShadow = 'none';
    });

    // Обработка клика
    btn.addEventListener('click', () => {
        isSoundMuted = !isSoundMuted;
        localStorage.setItem('yt_sound_muted', isSoundMuted);
        updateSoundButtonVisuals(btn);

        // Анимация нажатия
        btn.style.transform = 'scale(0.8)';
        setTimeout(() => { btn.style.transform = 'scale(1)'; }, 150);
    });

    document.body.appendChild(btn);
}

// Вспомогательная функция для обновления иконки и подсказки
function updateSoundButtonVisuals(btn) {
    btn.innerHTML = isSoundMuted ? '🔇' : '🔊';
    btn.title = isSoundMuted ? 'Звук выключен. Нажмите, чтобы включить.' : 'Звук включен. Нажмите, чтобы выключить.';
}

// Запускаем создание кнопки и отслеживание смены вкладок (hashchange)
if (!isMobileDevice) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initSoundToggle();
            window.addEventListener('hashchange', updateButtonVisibility);
        });
    } else {
        initSoundToggle();
        window.addEventListener('hashchange', updateButtonVisibility);
    }
}

// 7. Функция воспроизведения звука
function playNotificationSound(isSuccess) {
    // ТРОЙНАЯ ПРОВЕРКА перед воспроизведением:
    // 1. Не мобильное устройство?
    // 2. Пользователь не выключил звук вручную?
    // 3. Мы находимся на вкладке «Домой» isOnHomePage?
    if (isMobileDevice || isSoundMuted) {
        return;
    }

    const audioFile = isSuccess ? 'finish_job.mp3' : 'error_job.mp3';
    const audio = new Audio(audioFile);
    audio.volume = 0.5;

    audio.play().catch(error => {
        console.warn("Браузер заблокировал автовоспроизведение звука:", error);
    });
}

function getIconClass(type) {
    return type === "audio" ? "fa-music" : "fa-video-camera";
}

function renderUrls(urlString, includeIcon = false, iconType = null) {
    if (!urlString) return "";
    return urlString.split(",")
        .filter(url => url.trim())
        .map(url => {
            const iconHtml = includeIcon ? `<i class="fa ${getIconClass(iconType)}"></i> ` : "";
            return `<br /><a href="${url}">${iconHtml}${url}</a>`;
        }).join("");
}

function renderTable($container, items, cols, emptyMsg, rowHtmlGenerator, footerHtml = "") {
    if (!items || items.length === 0) {
        $container.html(`<tr><td colspan="${cols}">${emptyMsg}</td></tr>`);
        return;
    }
    const rowsHtml = items.map(rowHtmlGenerator).join("");
    $container.html(rowsHtml + (footerHtml ? `<tr>${footerHtml}</tr>` : ""));
}

function renderJobRow(job) {
    const iconClass = getIconClass(job.type);
    const urlsHtml = renderUrls(job.url);

    return `
    <tr>
        <td style="vertical-align: middle;">${job.site}</td>
        <td style="vertical-align: middle;"><i class="fa ${iconClass}"></i> ${job.file} ${urlsHtml}</td>
        <td style="vertical-align: middle;">${job.status}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                <a style="width: 100px;" data-href="?kill=${job.pid}" data-toggle="modal" data-target="#confirm-delete" class="btn btn-danger btn-xs">Стоп</a>
            </div>
        </td>
    </tr>`;
}

function renderQueueRow(item) {
    const iconClass = getIconClass(item.type);
    const urlsHtml = renderUrls(item.url, true, item.type);

    return `
    <tr>
        <td style="vertical-align: middle;">${urlsHtml}</td>
        <td style="vertical-align: middle;">${item.dl_format}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                <a style="width: 160px" data-href="?removeQueued=${item.pid}" data-toggle="modal" class="btn btn-danger btn-xs" data-target="#confirm-delete">Удалить</a>
            </div>
        </td>
    </tr>`;
}

function renderFinishedRow(item, logURL) {
    const iconClass = getIconClass(item.type);
    const urlsHtml = renderUrls(item.url);

    let logButton = "";
    let actionBtnWidth = "80px";

    if (logURL && logURL !== "") {
        actionBtnWidth = "60px";
        logButton = `<a href="${logURL}/${item.pid}" style="width: 40px;" target="_blank" class="btn btn-default btn-xs">Лог</a>`;
    }

    return `
    <tr>
        <td style="vertical-align: middle;">${item.site}</td>
        <td style="vertical-align: middle;"><i class="fa ${iconClass}"></i> ${item.file} ${urlsHtml}</td>
        <td style="vertical-align: middle;">${item.status}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                ${logButton}
                <a style="width: ${actionBtnWidth}" href="?restart=${item.pid}" class="btn btn-success btn-xs">↺</a>
                <a style="width: ${actionBtnWidth}" data-href="?clear=${item.pid}" data-toggle="modal" data-target="#confirm-delete" class="btn btn-danger btn-xs">Удалить</a>
            </div>
        </td>
    </tr>`;
}

function renderFileRow(file) {
    if (typeof showFileLifetime !== 'undefined' && !showFileLifetime) {
        return `
            <tr>
                <td style="vertical-align: middle; padding: 10px 8px;">${file.file}</td>
                <td style="vertical-align: middle; padding: 10px 8px; color: #6c757d; font-size: 13px; white-space: nowrap;">${file.size}</td>
                <td style="vertical-align: middle; padding: 10px 8px; white-space: nowrap;">${file.deleteurl}</td>
            </tr>
        `;
    }
    // 1. Защита от отсутствия данных
    const age = (typeof file.age_minutes === 'number' && !isNaN(file.age_minutes)) ? file.age_minutes : 0;
    const percent = (typeof file.lifetime_percent === 'number' && !isNaN(file.lifetime_percent)) ? file.lifetime_percent : 100;

    let timeText = '';
    let barColor = '#5cb85c'; // Зеленый по умолчанию

    // 2. Расчет оставшегося времени
    const remainingMinutes = Math.max(0, 120 - age);

    // 3. Определение цвета
    if (percent > 60) {
        barColor = '#28a745'; // Насыщенный зеленый
    } else if (percent > 30) {
        barColor = '#ffc107'; // Теплый желтый
    } else if (percent > 0) {
        barColor = '#dc3545'; // Насыщенный красный
    } else {
        barColor = '#6c757d'; // Серый
    }

    // 4. Форматирование текста времени
    if (remainingMinutes > 60) {
        const hours = Math.floor(remainingMinutes / 60);
        const mins = remainingMinutes % 60;
        timeText = `${hours}ч ${mins}м`;
    } else if (remainingMinutes > 0) {
        timeText = `${remainingMinutes}м`;
    } else {
        timeText = 'скоро';
    }

    const progressBar = `
        <div style="display: inline-flex; flex-direction: column; width: 85px; margin-right: 12px; vertical-align: middle; background: #f8f9fa; border-radius: 6px; padding: 5px 6px; border: 1px solid #e9ecef; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
            <div style="font-size: 11px; font-weight: 600; color: #495057; text-align: center; line-height: 1.2; margin-bottom: 4px; white-space: nowrap;">
                ⏱ ${timeText}
            </div>
            <div style="background: #e9ecef; border-radius: 2px; height: 3px; overflow: hidden;">
                <div style="background: ${barColor}; width: ${percent}%; height: 100%; transition: width 0.5s ease-out;"></div>
            </div>
        </div>
    `;

    return `
      <tr>
            <td style="vertical-align: middle; padding: 8px;">${progressBar} ${file.file}</td>
            <td style="vertical-align: middle; padding: 8px; color: #6c757d; font-size: 13px;">${file.size}</td>
            <td style="vertical-align: middle; padding: 8px;">${file.deleteurl}</td>
      </tr>  
    `;
}


// ОСНОВНАЯ ФУНКЦИЯ


function loadList() {
    $.get("index.php?jobs", function (data) {
        // Проверка новых завершенных загрузок
        const currentFinishedPids = new Set(data.finished.map(item => String(item.pid)));

        if (previousFinishedPids !== null) {
            let hasNewSuccess = false;
            let hasNewFailure = false;

            // Ищем новые завершенные задачи
            for (let item of data.finished) {
                const pid = String(item.pid);

                // Если эта задача появилась впервые
                if (!previousFinishedPids.has(pid)) {
                    if (isDownloadFailed(item)) {
                        hasNewFailure = true;
                    } else {
                        hasNewSuccess = true;
                    }
                }
            }

            // Воспроизводим соответствующий звук
            // Приоритет: если есть ошибки - играем звук ошибки
            if (hasNewFailure) {
                playNotificationSound(false);
            } else if (hasNewSuccess) {
                playNotificationSound(true);
            }
        }

        // Запоминаем текущее состояние для следующего сравнения
        previousFinishedPids = currentFinishedPids;

        // 1. Активные загрузки
        renderTable($ui.progress, data.jobs, 4, "Активных загрузок нет.", renderJobRow, `
            <td></td><td></td><td></td>
            <td>
                <div class="btn-group">
                    <button id="killallbutton" style="width: 100px;" class="btn btn-danger btn-xs" data-href="?kill=all" data-toggle="modal" data-target="#confirm-delete">Стоп ВСЕ</button>
                </div>
            </td>`);

        // 2. Очередь
        renderTable($ui.queue, data.queue, 3, "Очередь пуста.", renderQueueRow, `
            <td></td><td></td>
            <td>
                <div class="btn-group">
                    <button id="clearallbutton-queue" style="width: 160px;" class="btn btn-danger btn-xs" data-href="?clear=queue" data-toggle="modal" data-target="#confirm-delete">Удалить Все</button>
                </div>
            </td>`);

        // 3. Завершенные
        renderTable($ui.completed, data.finished, 4, "Завершенных загрузок нет.",
            item => renderFinishedRow(item, data.logURL), `
            <td></td><td></td><td></td>
            <td>
                <div class="btn-group">
                    <button id="clearallbutton-finished" style="width: 160px;" class="btn btn-danger btn-xs" data-href="?clear=recent" data-toggle="modal" data-target="#confirm-delete">Удалить Все</button>
                </div>
            </td>`);

        // 4. Видео
        renderTable($ui.videos, data.videos, 3, "Видео нет.", renderFileRow);

        // 5. Музыка
        renderTable($ui.music, data.music, 3, "Музыки нет.", renderFileRow);

    }, "json").fail(function () {
        console.error("Не удалось загрузить данные");
    });
}


// ИНИЦИАЛИЗАЦИЯ


let refreshInterval = null;

function initCache() {
    $ui.progress = $('#dlprogress');
    $ui.queue = $('#dlqueue');
    $ui.completed = $('#dlcompleted');
    $ui.videos = $('#videofiles');
    $ui.music = $('#musicfiles');
    $ui.urlInput = $('#url');
}

function startAutoRefresh() {
    if ($ui.progress.length && !refreshInterval) {
        loadList();
        refreshInterval = setInterval(loadList, CONFIG.refreshInterval);
    }
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

$(document).ready(function () {
    initCache();

    if ($ui.progress.length) {
        startAutoRefresh();
        $ui.urlInput.focus();
    }

    // Делегирование для модального окна (чтобы работало после динамической перерисовки)
    $(document).on('click', '[data-toggle="modal"]', function () {
        const href = $(this).data('href');
        if (href) {
            $('#confirm-delete .btn-danger').off('click').on('click', function () {
                window.location.href = href;
            });
        }
    });
});

$(window).on('beforeunload', function () {
    stopAutoRefresh();
});

$(document).on('visibilitychange', function () {
    if (document.hidden) {
        stopAutoRefresh();
    } else {
        if ($ui.progress.length && !refreshInterval) {
            startAutoRefresh();
        }
    }
});

function checkControls() {
    const isChecked = $('#audio_convert').prop("checked");
    $('#video_group, #audio_group').hide();
    if (isChecked) {
        $('#audio_group').show();
    } else {
        $('#video_group').show();
    }
}

function helpPanel() {
    const panelBody = $('#helppanel');
    if (!panelBody.hasClass('panel-collapsed')) {
        panelBody.slideUp();
        panelBody.addClass('panel-collapsed');
        $('#helplink').html('Я туть, твоя помощь');
    } else {
        panelBody.slideDown();
        panelBody.removeClass('panel-collapsed');
        $('#helplink').html('Скрыть');
    }
}


// Иконка в поиске


$(document).ready(function () {
    const $faviconContainer = $('#url-favicon');
    const $faviconImg = $('#url-favicon-img'); // Работаем ТОЛЬКО с этим единственным тегом
    const $clearBtn = $('#url-clear');
    const $urlInput = $('#url');
    const $wrapper = $('.url-input-wrapper');

    let inputTimer = null;
    const INPUT_DELAY = 150;
    let isClearing = false;

    // Сессия: домены, которые мы уже успешно загрузили и проверили (>= 32px)
    const validatedDomains = new Set();

    const KNOWN_SERVICES = ['vk.com', 'vk.ru', 'm.vk.com', 'video.vk.com', 'vkvideo.ru', 'ok.ru', 'odnoklassniki.ru', 'rutube.ru', 'rutube.com', 'yandex.ru', 'yandex.com', 'music.yandex.ru', 'music.yandex.com', 'dzen.ru', 'zen.yandex.ru', 'dzen.com', 'coub.com', 'pikabu.ru', 't.me', 'telegram.me', 'telegram.org', 'youtube.com', 'youtu.be', 'youtube-nocookie.com', 'tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com', 'douyin.com', 'instagram.com', 'instagr.am', 'ig.me', 'twitter.com', 'x.com', 't.co', 'facebook.com', 'fb.watch', 'm.facebook.com', 'twitch.tv', 'clips.twitch.tv', 'v.redd.it', 'soundcloud.com', 'snd.sc', 'bandcamp.com', 'mixcloud.com', 'vimeo.com', 'player.vimeo.com', 'dailymotion.com', 'dai.ly', 'bilibili.com', 'b23.tv', 'iq.com', 'iqiyi.com', 'youku.com', 'v.youku.com', 'v.qq.com', 'nicovideo.jp', 'nico.ms', 'tumblr.com', 'streamable.com', 'archive.org', 'smotrim.ru', '1tv.ru', 'russia.tv', 'matchtv.ru', 'ntv.ru', 'ren.tv', 'tvc.ru', '5-tv.ru', 'ctc.ru', 'tnt-online.ru', 'muz-tv.ru', 'tvzvezda.ru', 'my.mail.ru', 'ivi.ru', 'ivi.tv', 'kinopoisk.ru', 'tvigle.ru', 'cloud.tvigle.ru', 'mir24.tv', 'rt.com', 'rtd.rt.com', 'ruptly.tv', 'life.ru', 'embed.life.ru', 'video.sibnet.ru', 'fc-zenit.ru', 'noodlemagazine.com', 'goodgame.ru', 'vkplay.ru', 'zvuk.com', 'zaycev.fm', 'muzofond.fm', 'pleer.net', 'rumble.com', 'bitchute.com', 'odysee.com', 'lbry.tv', 'peertube.tv', 'trovo.live', 'kick.com', 'nebula.tv', 'crunchyroll.com', 'ted.com', 'dtube.app', 'bilibili.tv', 'tubitv.com', 'pluto.tv', 'spotify.com', 'deezer.com', 'tidal.com', 'qobuz.com', 'music.apple.com', 'music.amazon.com', 'pandora.com', 'iheart.com', 'tunein.com', 'kuaishou.com', 'kwai.com', 'ixigua.com', 'mgtv.com', 'sohu.com', 'krasview.ru', 'yapfiles.ru', 'yappy.media', 'news.sportbox.ru', 'cliprs.ru'];

    function getBaseService(hostname) {
        if (!hostname) return null;
        hostname = hostname.toLowerCase();
        for (let service of KNOWN_SERVICES) {
            if (hostname === service || hostname.endsWith('.' + service)) {
                return service;
            }
        }
        return null;
    }

    function showFavicon(serviceDomain) {
        const faviconUrl = 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(serviceDomain) + '&sz=64';

        // === ГЛАВНАЯ МАГИЯ: ЕСЛИ ДОМЕН УЖЕ ПРОВЕРЕН ===
        if (validatedDomains.has(serviceDomain)) {
            // Мы присваиваем src ТОЛЬКО если он отличается от текущего.
            // Если он уже равен faviconUrl, мы НЕ ТРОГАЕМ атрибут src вообще.
            // Это гарантирует, что браузер НЕ сделает ни одного запроса к сети.
            if ($faviconImg.attr('src') !== faviconUrl) {
                $faviconImg.attr('src', faviconUrl);
            }
            $faviconContainer.addClass('is-visible');
            $wrapper.addClass('has-favicon');
            return; // МГНОВЕННЫЙ ВЫХОД. Никаких onload, никаких запросов.
        }

        // === ПЕРВИЧНАЯ ПРОВЕРКА (Только для новых доменов) ===
        $faviconImg.off('load error');

        // Скрываем контейнер, но НЕ очищаем src старой иконки, чтобы она осталась в кэше браузера
        $faviconContainer.removeClass('is-visible');
        $wrapper.removeClass('has-favicon');

        $faviconImg.one('load', function () {
            if (!$urlInput.val().trim() || isClearing) return;

            // Проверяем, что это не заглушка Google (16x16)
            if (this.naturalWidth >= 32 && this.naturalHeight >= 32) {
                validatedDomains.add(serviceDomain); // Сохраняем в сессию
                $faviconContainer.addClass('is-visible');
                $wrapper.addClass('has-favicon');
            } else {
                // Это заглушка, очищаем src
                $faviconImg.attr('src', '');
                if ($urlInput.val().trim() && !isClearing) {
                    $clearBtn.addClass('is-visible');
                }
            }
        }).one('error', function () {
            if (!$urlInput.val().trim() || isClearing) return;
            $faviconImg.attr('src', '');
            if ($urlInput.val().trim() && !isClearing) {
                $clearBtn.addClass('is-visible');
            }
        });

        // Запускаем загрузку
        $faviconImg.attr('src', faviconUrl);
    }

    function hideFavicon() {
        $faviconImg.off('load error');
        $faviconContainer.removeClass('is-visible');
        $wrapper.removeClass('has-favicon');
        // ВАЖНО: Мы НИКОГДА не делаем $faviconImg.attr('src', '') здесь.
        // Картинка остается в DOM, браузер держит её в кэше.
    }

    function showClearBtn() {
        $clearBtn.addClass('is-visible');
    }

    function hideClearBtn() {
        $clearBtn.removeClass('is-visible');
    }

    function clearInput() {
        isClearing = true;
        $faviconImg.off('load error');
        clearTimeout(inputTimer);

        $urlInput.val('');

        // Просто скрываем. Атрибут src НЕ ТРОГАЕМ.
        $faviconContainer.removeClass('is-visible');
        $wrapper.removeClass('has-favicon');
        $clearBtn.removeClass('is-visible');

        $urlInput.focus();

        setTimeout(function () {
            isClearing = false;
        }, 50);
    }

    function checkUrl() {
        if (isClearing) return;

        const val = $urlInput.val().trim();
        if (!val) {
            hideFavicon();
            hideClearBtn();
            return;
        }

        const firstUrl = val.split('||')[0].trim();
        let hostname = null;

        try {
            let urlToParse = firstUrl;
            if (!/^https?:\/\//i.test(urlToParse)) urlToParse = 'https://' + urlToParse;
            hostname = new URL(urlToParse).hostname.replace(/^www\./i, '');
        } catch (e) {
            hostname = null;
        }

        const service = hostname ? getBaseService(hostname) : null;

        if (service) {
            hideClearBtn();
            showFavicon(service);
        } else {
            hideFavicon();
            showClearBtn();
        }
    }

    $urlInput.on('paste', function () {
        setTimeout(checkUrl, 10);
    });

    $urlInput.on('input', function () {
        clearTimeout(inputTimer);
        inputTimer = setTimeout(checkUrl, INPUT_DELAY);
    });

    $urlInput.on('blur', function () {
        if (isClearing) return;
        clearTimeout(inputTimer);
        checkUrl();
    });

    $clearBtn.on('mousedown touchstart', function (e) {
        e.preventDefault();
        clearInput();
    });

    $faviconContainer.on('mousedown touchstart', function (e) {
        e.preventDefault();
        clearInput();
    });

    if ($urlInput.val().trim()) {
        setTimeout(checkUrl, 50);
    }
});

function syncLogic() {
    const isAudio = document.getElementById('ui_audio_mode').checked;
    const paramsVideo = document.getElementById('params-video');
    const paramsAudio = document.getElementById('params-audio');
    const hiddenAudioCheckbox = document.getElementById('audio_convert');
    const hiddenVideoFormat = document.getElementById('format');
    const qualityToggle = document.getElementById('ui_quality_toggle');

    // 1. Переключение видимости блоков
    if (isAudio) {
        paramsVideo.style.display = 'none';
        paramsAudio.style.display = 'flex';
    } else {
        paramsVideo.style.display = 'flex';
        paramsAudio.style.display = 'none';
    }

    // 2. Синхронизация скрытого чекбокса
    hiddenAudioCheckbox.checked = isAudio;

    // 3. Синхронизация скрытого селекта видео
    if (!isAudio) {
        hiddenVideoFormat.value = qualityToggle.checked ? 'worst' : "-S res:1080 -f 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4] / bv*+ba/b'";
    }

    // 4. Синхронизация скрытого селекта аудио
    if (isAudio) {
        syncHiddenSelects();
    }

    // 5. Вызов оригинальной функции
    if (typeof checkControls === 'function') checkControls();
}

function syncSubToggles(clickedToggle) {
    if (!clickedToggle.checked) {
        clickedToggle.checked = true; // Запрет на полное отключение
        return;
    }
    const parentGroup = clickedToggle.closest('.audio-switches-wrapper');
    parentGroup.querySelectorAll('.toggle-input-sub').forEach(toggle => {
        if (toggle !== clickedToggle) toggle.checked = false;
    });
    syncHiddenSelects();
    if (typeof checkControls === 'function') checkControls();
}

function syncHiddenSelects() {
    const hiddenAudioFormat = document.getElementById('audio_format');
    const activeToggle = document.querySelector('#params-audio .toggle-input-sub:checked');
    if (activeToggle) hiddenAudioFormat.value = activeToggle.getAttribute('data-value');
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', function () {
    const hiddenAudioCheckbox = document.getElementById('audio_convert');
    const hiddenAudioFormat = document.getElementById('audio_format');
    const hiddenVideoFormat = document.getElementById('format');

    const uiAudioMode = document.getElementById('ui_audio_mode');
    const qualityToggle = document.getElementById('ui_quality_toggle');

    uiAudioMode.checked = hiddenAudioCheckbox.checked;
    qualityToggle.checked = (hiddenVideoFormat.value === 'worst');

    syncLogic();

    if (uiAudioMode.checked && hiddenAudioFormat) {
        const targetToggle = document.querySelector(`#params-audio .toggle-input-sub[data-value="${hiddenAudioFormat.value}"]`);
        if (targetToggle) {
            targetToggle.checked = true;
            document.querySelectorAll('#params-audio .toggle-input-sub').forEach(t => {
                if (t !== targetToggle) t.checked = false;
            });
        }
    }
});