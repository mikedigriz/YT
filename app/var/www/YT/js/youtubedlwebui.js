const CONFIG = {
    fastInterval: 1500,
    slowInterval: 12000
};

const nativeUI = {};
let previousFinishedPids = null;

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function safeUrlAttr(url) {
    const trimmed = String(url ?? "").trim();
    if (!/^https?:\/\//i.test(trimmed)) return "#";
    return escapeHtml(trimmed);
}

function safePid(pid) {
    const str = String(pid ?? "");
    return /^[A-Za-z0-9_-]+$/.test(str) ? str : "";
}

let audioSuccess = null;
let audioError = null;
let soundsLoading = null;

// === CSRF Protection ===
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function confirmAction(action, value, extraFields = {}) {
    const messages = {
        'kill': value === 'all' 
            ? 'Остановить ВСЕ загрузки?' 
            : 'Остановить загрузку?',
        'delete': 'Удалить файл безвозвратно?',
        'clear': value === 'recent' 
            ? 'Очистить историю загрузок?' 
            : (value === 'queue' ? 'Очистить очередь?' : 'Удалить из истории?'),
        'restart': 'Перезапустить загрузку?',
        'removeQueued': 'Удалить из очереди?'
    };

    if (!confirm(messages[action] || 'Выполнить действие?')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';
    form.style.display = 'none';

    const addField = (name, val) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = val;
        form.appendChild(input);
    };

    addField('csrf_token', getCsrfToken());
    addField(action, value);

    for (const [key, val] of Object.entries(extraFields)) {
        addField(key, val);
    }

    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function updateFileBadges(data) {
    const videoBadge = document.getElementById('video-badge');
    const musicBadge = document.getElementById('music-badge');
    
    if (videoBadge) {
        if (data.videos && data.videos.length > 0) {
            videoBadge.classList.add('is-visible');
        } else {
            videoBadge.classList.remove('is-visible');
        }
    }
    
    if (musicBadge) {
        if (data.music && data.music.length > 0) {
            musicBadge.classList.add('is-visible');
        } else {
            musicBadge.classList.remove('is-visible');
        }
    }
}

async function preloadNotificationSounds() {
    if (audioSuccess && audioError) return;
    if (soundsLoading) return soundsLoading;
    
    soundsLoading = (async () => {
        try {
            const [successResp, errorResp] = await Promise.all([
                fetch('finish_job.mp3'),
                fetch('error_job.mp3')
            ]);
            
            const [successBlob, errorBlob] = await Promise.all([
                successResp.blob(),
                errorResp.blob()
            ]);
            
            audioSuccess = new Audio(URL.createObjectURL(successBlob));
            audioSuccess.volume = 0.5;
            
            audioError = new Audio(URL.createObjectURL(errorBlob));
            audioError.volume = 0.5;
        } catch (error) {
            console.warn("Не удалось предзагрузить звуки через Blob, используется fallback:", error);
            audioSuccess = new Audio('finish_job.mp3');
            audioSuccess.volume = 0.5;
            audioError = new Audio('error_job.mp3');
            audioError.volume = 0.5;
        } finally {
            soundsLoading = null;
        }
    })();
    
    return soundsLoading;
}

function unloadNotificationSounds() {
    if (audioSuccess) {
        if (audioSuccess.src.startsWith('blob:')) URL.revokeObjectURL(audioSuccess.src);
        audioSuccess.pause();
    }
    if (audioError) {
        if (audioError.src.startsWith('blob:')) URL.revokeObjectURL(audioError.src);
        audioError.pause();
    }
    audioSuccess = null;
    audioError = null;
}

function isDownloadFailed(item) {
    const status = item.status || "";
    const type = item.type || "";

    if (status.includes("Отменено") || status.includes("Ошибка") || status.includes("Порнографию") || status.includes("не та ссылка")) {
        return true;
    }
    if (type === "unknown") {
        return true;
    }
    return false;
}

const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || (window.innerWidth <= 768 && 'ontouchstart' in window);
let isSoundMuted = localStorage.getItem('yt_sound_muted') === 'true';

if (!isMobileDevice && !isSoundMuted) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', preloadNotificationSounds);
    } else {
        preloadNotificationSounds();
    }
}

function isOnHomePage() {
    const hash = window.location.hash.toLowerCase();
    return hash === '' || hash === '#home' || hash === '#';
}

let soundToggleBtn = null;

function updateButtonVisibility() {
    if (!soundToggleBtn) return;
    soundToggleBtn.classList.toggle('is-visible', isOnHomePage());
}

function initSoundToggle() {
    if (isMobileDevice) return;

    if (document.getElementById('sound-toggle')) {
        soundToggleBtn = document.getElementById('sound-toggle');
        updateButtonVisibility();
        return;
    }

    const btn = document.createElement('div');
    btn.id = 'sound-toggle';
    btn.className = 'sound-toggle';
    
    soundToggleBtn = btn;
    updateSoundButtonVisuals(btn);
    updateButtonVisibility();

    btn.addEventListener('click', () => {
        isSoundMuted = !isSoundMuted;
        localStorage.setItem('yt_sound_muted', isSoundMuted);
        updateSoundButtonVisuals(btn);

        if (!isSoundMuted) {
            preloadNotificationSounds();
        } else {
            unloadNotificationSounds();
        }
    });

    document.body.appendChild(btn);
}

function updateSoundButtonVisuals(btn) {
    btn.innerHTML = isSoundMuted ? '🔇' : '🔊';
    btn.title = isSoundMuted ? 'Звук выключен. Нажмите, чтобы включить.' : 'Звук включен. Нажмите, чтобы выключить.';
}

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

function playNotificationSound(isSuccess) {
    if (isMobileDevice || isSoundMuted) return;

    if (!audioSuccess || !audioError) {
        preloadNotificationSounds().then(() => {
            const audio = isSuccess ? audioSuccess : audioError;
            if (audio) {
                audio.currentTime = 0;
                audio.play().catch(e => console.warn("Autoplay blocked:", e));
            }
        });
        return;
    }

    const audio = isSuccess ? audioSuccess : audioError;
    audio.currentTime = 0;
    
    audio.play().catch(error => {
        console.warn("Браузер заблокировал автовоспроизведение звука:", error);
    });
}

function getIconClass(type) {
    return type === "audio" ? "fa-music" : "fa-video-camera";
}

const urlsCache = new Map();

function renderUrls(urlString, includeIcon = false, iconType = null, leadingBreak = true) {
    if (!urlString) return "";
    const key = `${urlString}|${includeIcon}|${iconType}|${leadingBreak}`;
    if (urlsCache.has(key)) return urlsCache.get(key);

    const result = urlString.split(",")
        .filter(url => url.trim())
        .map((url, idx) => {
            const iconHtml = includeIcon ? `<i class="fa ${getIconClass(iconType)}"></i> ` : "";
            const prefix = (idx === 0 && !leadingBreak) ? "" : "<br />";
            return `${prefix}<a href="${safeUrlAttr(url)}">${iconHtml}${escapeHtml(url)}</a>`;
        }).join("");

    urlsCache.set(key, result);
    return result;
}

function computeDataHash(items) {
    if (!items || items.length === 0) return '0';
    let hash = '';
    for (const item of items) {
        for (const key in item) {
            if (item.hasOwnProperty(key)) {
                hash += item[key];
            }
        }
        hash += '|';
    }
    return hash;
}

function renderTable(container, items, cols, emptyMsg, rowHtmlGenerator, footerHtml = "") {
    const hash = computeDataHash(items) + ':' + footerHtml;

    if (container.dataset.lastHash === hash) {
        return;
    }

    const newHtml = (!items || items.length === 0)
        ? `<tr><td colspan="${cols}">${emptyMsg}</td></tr>`
        : items.map(rowHtmlGenerator).join("") + (footerHtml ? `<tr>${footerHtml}</tr>` : "");

    container.innerHTML = newHtml;
    container.dataset.lastHash = hash;
}

function renderJobRow(job) {
    const iconClass = getIconClass(job.type);
    const urlsHtml = renderUrls(job.url);
    return `
    <tr>
        <td style="vertical-align: middle;">${escapeHtml(job.site)}</td>
        <td style="vertical-align: middle;"><i class="fa ${iconClass}"></i> ${escapeHtml(job.file)} ${urlsHtml}</td>
        <td style="vertical-align: middle;">${escapeHtml(job.status)}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                <button style="width: 100px;" onclick="confirmAction('kill', '${safePid(job.pid)}')" class="btn btn-danger btn-xs">Стоп</button>
            </div>
        </td>
    </tr>`;
}

function renderQueueRow(item) {
    const iconClass = getIconClass(item.type);
    const urlsHtml = renderUrls(item.url, true, item.type, false);
    return `
    <tr>
        <td style="vertical-align: middle;">${urlsHtml}</td>
        <td style="vertical-align: middle;">${escapeHtml(item.dl_format)}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                <button style="width: 160px" onclick="confirmAction('removeQueued', '${safePid(item.pid)}')" class="btn btn-danger btn-xs">Удалить</button>
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
        logButton = `<a href="${safeUrlAttr(logURL)}/${safePid(item.pid)}" style="width: 40px;" target="_blank" class="btn btn-default btn-xs">Лог</a>`;
    }

    return `
    <tr>
        <td style="vertical-align: middle;">${escapeHtml(item.site)}</td>
        <td style="vertical-align: middle;"><i class="fa ${iconClass}"></i> ${escapeHtml(item.file)} ${urlsHtml}</td>
        <td style="vertical-align: middle;">${escapeHtml(item.status)}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                ${logButton}
                <button style="width: ${actionBtnWidth}" onclick="confirmAction('restart', '${safePid(item.pid)}')" class="btn btn-success btn-xs">↺</button>
                <button style="width: ${actionBtnWidth}" onclick="confirmAction('clear', '${safePid(item.pid)}')" class="btn btn-danger btn-xs">Удалить</button>
            </div>
        </td>
    </tr>`;
}

function renderFileRow(file) {
    if (typeof showFileLifetime !== 'undefined' && !showFileLifetime) {
        return `<tr><td>${file.file}</td><td>${file.size}</td><td>${file.deleteurl}</td></tr>`;
    }

    const age = (typeof file.age_minutes === 'number' && !isNaN(file.age_minutes)) ? file.age_minutes : 0;
    const percent = (typeof file.lifetime_percent === 'number' && !isNaN(file.lifetime_percent)) ? file.lifetime_percent : 100;
    let timeText = '';
    let colorClass = 'bg-safe';
    const remainingMinutes = Math.max(0, 120 - age);

    if (percent > 60) colorClass = 'bg-safe';
    else if (percent > 30) colorClass = 'bg-warn';
    else if (percent > 0) colorClass = 'bg-danger';
    else colorClass = 'bg-dead';

    if (remainingMinutes > 60) {
        const hours = Math.floor(remainingMinutes / 60);
        timeText = `${hours}ч ${remainingMinutes % 60}м`;
    } else if (remainingMinutes > 0) {
        timeText = `${remainingMinutes}м`;
    } else {
        timeText = 'скоро';
    }

    const badge = `
        <div class="lifetime-badge">
            <div class="lifetime-badge-text">⏱ ${timeText}</div>
            <div class="progress">
                <div class="progress-bar progress-lifetime ${colorClass}" style="width: ${percent}%;"></div>
            </div>
        </div>`;

    return `
        <tr>
            <td>
                <div class="file-row-content">
                    ${badge}
                    <span class="file-name">${file.file}</span>
                </div>
            </td>
            <td>${file.size}</td>
            <td>${file.deleteurl}</td>
        </tr>`;
}

function loadList() {
    fetch("index.php?jobs")
        .then(resp => resp.json())
        .then(function (data) {
        const currentFinishedPids = new Set();
        for (const item of data.finished) {
            currentFinishedPids.add(String(item.pid));
        }

        if (previousFinishedPids !== null) {
            let hasNewSuccess = false;
            let hasNewFailure = false;

            for (let item of data.finished) {
                const pid = String(item.pid);
                if (!previousFinishedPids.has(pid)) {
                    if (isDownloadFailed(item)) {
                        hasNewFailure = true;
                    } else {
                        hasNewSuccess = true;
                    }
                }
            }

            if (hasNewFailure) {
                playNotificationSound(false);
            } else if (hasNewSuccess) {
                playNotificationSound(true);
            }
        }

        previousFinishedPids = currentFinishedPids;

        renderTable(nativeUI.progress, data.jobs, 4, "Активных загрузок нет.", renderJobRow, `
            <td></td><td></td><td></td>
            <td><div class="btn-group"><button id="killallbutton" style="width: 100px;" class="btn btn-danger btn-xs" onclick="confirmAction('kill', 'all')">Стоп ВСЕ</button></div></td>`);

        renderTable(nativeUI.queue, data.queue, 3, "Очередь пуста.", renderQueueRow, `
            <td></td><td></td>
            <td><div class="btn-group"><button id="clearallbutton-queue" style="width: 160px;" class="btn btn-danger btn-xs" onclick="confirmAction('clear', 'queue')">Удалить Все</button></div></td>`);

        renderTable(nativeUI.completed, data.finished, 4, "Завершенных загрузок нет.", item => renderFinishedRow(item, data.logURL), `
            <td></td><td></td><td></td>
            <td><div class="btn-group"><button id="clearallbutton-finished" style="width: 160px;" class="btn btn-danger btn-xs" onclick="confirmAction('clear', 'recent')">Удалить Все</button></div></td>`);

        renderTable(nativeUI.videos, data.videos, 3, "Видео нет.", renderFileRow);
        renderTable(nativeUI.music, data.music, 3, "Музыки нет.", renderFileRow);
        updateFileBadges(data);

        const isActive = (data.jobs && data.jobs.length > 0) || (data.queue && data.queue.length > 0);
        scheduleNextRefresh(isActive ? CONFIG.fastInterval : CONFIG.slowInterval);

    }).catch(function () {
        console.error("Не удалось загрузить данные");
        scheduleNextRefresh(CONFIG.slowInterval);
    });
}

let refreshTimer = null;
let refreshActive = false;

let urlInput = null;

function initCache() {
    nativeUI.progress = document.getElementById('dlprogress');
    nativeUI.queue = document.getElementById('dlqueue');
    nativeUI.completed = document.getElementById('dlcompleted');
    nativeUI.videos = document.getElementById('videofiles');
    nativeUI.music = document.getElementById('musicfiles');
    urlInput = document.getElementById('url');
}

function scheduleNextRefresh(delay) {
    clearTimeout(refreshTimer);
    if (!refreshActive) return;
    refreshTimer = setTimeout(loadList, delay);
}

function startAutoRefresh() {
    if (nativeUI.progress && !refreshActive) {
        refreshActive = true;
        loadList();
    }
}

function stopAutoRefresh() {
    refreshActive = false;
    clearTimeout(refreshTimer);
    refreshTimer = null;
}

document.addEventListener('DOMContentLoaded', function () {
    initCache();
    if (nativeUI.progress) {
        startAutoRefresh();
        if (urlInput) urlInput.focus();
    }

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (form.tagName !== 'FORM') return;
        if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = getCsrfToken();
            form.appendChild(input);
        }
    });
});

window.addEventListener('beforeunload', function () {
    stopAutoRefresh();
});

document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
        stopAutoRefresh();
    } else {
        if (nativeUI.progress && !refreshActive) {
            startAutoRefresh();
        }
    }
});

function checkControls() {
    const isChecked = document.getElementById('audio_convert').checked;
    const videoGroup = document.getElementById('video_group');
    const audioGroup = document.getElementById('audio_group');
    if (videoGroup) videoGroup.style.display = 'none';
    if (audioGroup) audioGroup.style.display = 'none';
    if (isChecked) {
        if (audioGroup) audioGroup.style.display = '';
    } else {
        if (videoGroup) videoGroup.style.display = '';
    }
}

function helpPanel() {
    const panelBody = document.getElementById('helppanel');
    const helpLink = document.getElementById('helplink');
    if (!panelBody.classList.contains('panel-collapsed')) {
        panelBody.classList.add('panel-collapsed');
        helpLink.innerHTML = 'Я туть, твоя помощь';
    } else {
        panelBody.classList.remove('panel-collapsed');
        helpLink.innerHTML = 'Скрыть';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const faviconContainer = document.getElementById('url-favicon');
    const faviconImg = document.getElementById('url-favicon-img');
    const clearBtn = document.getElementById('url-clear');
    const urlInput = document.getElementById('url');
    const wrapper = document.querySelector('.url-input-wrapper');

    let inputTimer = null;
    const INPUT_DELAY = 150;
    let isClearing = false;

    const FAVICON_BASE = 'favicons/';
    const FALLBACK_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzg4OCI+PHBhdGggZD0iTTEyIDJDNi40OCAyIDIgNi40OCAyIDEyczQuNDggMTAgMTAgMTAgMTAtNC40OCAxMC0xMFMxNy41MiAyIDEyIDJ6bTAgMThjLTQuNDEgMC04LTMuNTktOC04czMuNTktOCA4LTggOCAzLjU5IDggOC0zLjU5IDgtOCA4eiIvPjwvc3ZnPg==';
    const faviconCache = new Map();

    const KNOWN_SERVICES = ['vk.com', 'vk.ru', 'm.vk.com', 'video.vk.com', 'vkvideo.ru', 'vkclips.ru', 'ok.ru', 'odnoklassniki.ru', 'rutube.ru', 'rutube.com', 'yandex.ru', 'yandex.com', 'music.yandex.ru', 'music.yandex.com', 'dzen.ru', 'dzen.com', 'zen.yandex.ru', 'coub.com', 'pikabu.ru', 't.me', 'telegram.me', 'telegram.org', 'youtube.com', 'youtu.be', 'youtube-nocookie.com', 'tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com', 'douyin.com', 'instagram.com', 'instagr.am', 'ig.me', 'twitter.com', 'x.com', 't.co', 'facebook.com', 'fb.watch', 'm.facebook.com', 'twitch.tv', 'clips.twitch.tv', 'soundcloud.com', 'snd.sc', 'bandcamp.com', 'mixcloud.com', 'vimeo.com', 'player.vimeo.com', 'dailymotion.com', 'dai.ly', 'bilibili.com', 'b23.tv', 'iq.com', 'iqiyi.com', 'youku.com', 'v.youku.com', 'v.qq.com', 'nicovideo.jp', 'nico.ms', 'tumblr.com', 'streamable.com', 'archive.org', 'smotrim.ru', '1tv.ru', 'russia.tv', 'matchtv.ru', 'ntv.ru', 'ren.tv', 'tvc.ru', '5-tv.ru', 'ctc.ru', 'tnt-online.ru', 'muz-tv.ru', 'tvzvezda.ru', 'my.mail.ru', 'ivi.ru', 'ivi.tv', 'kinopoisk.ru', 'mir24.tv', 'rt.com', 'rtd.rt.com', 'life.ru', 'tvigle.ru', 'video.sibnet.ru', 'fc-zenit.ru', 'noodlemagazine.com', 'goodgame.ru', 'vkplay.ru', 'zvuk.com', 'zaycev.fm', 'muzofond.fm', 'pleer.net', 'rumble.com', 'bitchute.com', 'odysee.com', 'lbry.tv', 'peertube.tv', 'trovo.live', 'kick.com', 'nebula.tv', 'crunchyroll.com', 'ted.com', 'bilibili.tv', 'tubitv.com', 'pluto.tv', 'spotify.com', 'deezer.com', 'tidal.com', 'qobuz.com', 'music.apple.com', 'music.amazon.com', 'pandora.com', 'iheart.com', 'tunein.com', 'kuaishou.com', 'kwai.com', 'ixigua.com', 'mgtv.com', 'sohu.com', 'yapfiles.ru', 'yappy.media', 'news.sportbox.ru',  'mail.ru', 'video.mail.ru', 'yandexvideo.ru', 'yandexvideo.com', 'disk.yandex.ru', 'disk.yandex.com', 'zen.yandex.com', 'okko.tv', 'okko.com', 'more.tv', 'moretv.ru', 'start.ru', 'premier.one', 'reddit.com', 'redd.it', 'v.redd.it', 'vikingfile.com', 'vik1ngfile.site', 'digriz.ddns.net'];

    const KNOWN_SERVICES_SET = new Set(KNOWN_SERVICES);
    const serviceIndex = new Map();
    for (const service of KNOWN_SERVICES) {
        const parts = service.split('.');
        const key = parts.slice(-2).join('.');
        if (!serviceIndex.has(key)) serviceIndex.set(key, []);
        serviceIndex.get(key).push(service);
    }

    function getBaseService(hostname) {
        if (!hostname) return null;
        hostname = hostname.toLowerCase();
        
        if (KNOWN_SERVICES_SET.has(hostname)) return hostname;
        
        const parts = hostname.split('.');
        for (let i = 1; i < parts.length - 1; i++) {
            const key = parts.slice(i).join('.');
            const candidates = serviceIndex.get(key);
            if (candidates) {
                for (const service of candidates) {
                    if (hostname === service || hostname.endsWith('.' + service)) {
                        return service;
                    }
                }
            }
        }
        return null;
    }

    function getFaviconUrl(domain) {
        return `${FAVICON_BASE}${encodeURIComponent(domain)}.png`;
    }

    function applyFavicon(url) {
        if (faviconImg.getAttribute('src') !== url) {
            faviconImg.setAttribute('src', url);
        }
        faviconContainer.classList.add('is-visible');
        wrapper.classList.add('has-favicon');
    }

    function resetUI() {
        faviconImg.setAttribute('src', '');
        faviconContainer.classList.remove('is-visible');
        wrapper.classList.remove('has-favicon');
        if (urlInput.value.trim() && !isClearing) {
            clearBtn.classList.add('is-visible');
        }
    }

    function showFavicon(serviceDomain) {
        if (!urlInput.value.trim() || isClearing) return;

        const cached = faviconCache.get(serviceDomain);
        if (cached) {
            if (cached.ok) {
                applyFavicon(cached.url);
            } else {
                resetUI();
            }
            return;
        }

        faviconContainer.classList.remove('is-visible');
        wrapper.classList.remove('has-favicon');

        const url = getFaviconUrl(serviceDomain);
        const tempImg = new Image();

        tempImg.onload = function () {
            if (!urlInput.value.trim() || isClearing) return;
            faviconCache.set(serviceDomain, { url, ok: true });
            const currentService = getBaseService((() => {
                try {
                    let v = urlInput.value.trim().split('||')[0].trim();
                    if (!/^https?:\/\//i.test(v)) v = 'https://' + v;
                    return new URL(v).hostname.replace(/^www\./i, '');
                } catch (e) { return null; }
            })());
            if (currentService !== serviceDomain) return;
            applyFavicon(url);
        };

        tempImg.onerror = function () {
            if (!urlInput.value.trim() || isClearing) return;
            faviconCache.set(serviceDomain, { url: FALLBACK_ICON, ok: false });
            resetUI();
        };

        tempImg.src = url;
    }

    function hideFavicon() {
        faviconImg.onload = null;
        faviconImg.onerror = null;
        faviconContainer.classList.remove('is-visible');
        wrapper.classList.remove('has-favicon');
    }

    function showClearBtn() {
        clearBtn.classList.add('is-visible');
    }

    function hideClearBtn() {
        clearBtn.classList.remove('is-visible');
    }

    function clearInput() {
        isClearing = true;
        faviconImg.onload = null;
        faviconImg.onerror = null;
        clearTimeout(inputTimer);
        urlInput.value = '';
        faviconContainer.classList.remove('is-visible');
        wrapper.classList.remove('has-favicon');
        clearBtn.classList.remove('is-visible');
        urlInput.focus();
        setTimeout(() => { isClearing = false; }, 50);
    }

    function checkUrl() {
        if (isClearing) return;
        const val = urlInput.value.trim();
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

    urlInput.addEventListener('paste', () => setTimeout(checkUrl, 10));
    urlInput.addEventListener('input', () => {
        clearTimeout(inputTimer);
        inputTimer = setTimeout(checkUrl, INPUT_DELAY);
    });
    urlInput.addEventListener('blur', () => {
        if (isClearing) return;
        clearTimeout(inputTimer);
        checkUrl();
    });
    ['mousedown', 'touchstart'].forEach(evt => {
        clearBtn.addEventListener(evt, (e) => {
            e.preventDefault();
            clearInput();
        });
        faviconContainer.addEventListener(evt, (e) => {
            e.preventDefault();
            clearInput();
        });
    });

    if (urlInput.value.trim()) setTimeout(checkUrl, 50);
});

function syncLogic() {
    const isAudio = document.getElementById('ui_audio_mode').checked;
    const paramsVideo = document.getElementById('params-video');
    const paramsAudio = document.getElementById('params-audio');
    const hiddenAudioCheckbox = document.getElementById('audio_convert');
    const hiddenVideoFormat = document.getElementById('format');
    const qualityToggle = document.getElementById('ui_quality_toggle');

    paramsVideo.classList.toggle('is-hidden', isAudio);
    paramsAudio.classList.toggle('is-hidden', !isAudio);

    hiddenAudioCheckbox.checked = isAudio;

    if (!isAudio) {
        hiddenVideoFormat.value = qualityToggle.checked ? 'worst' : 'top';
    }

    if (isAudio) syncHiddenSelects();
    if (typeof checkControls === 'function') checkControls();
}

function syncSubToggles(clickedToggle) {
    if (!clickedToggle.checked) {
        clickedToggle.checked = true;
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

    initSnejEasterEgg();
});

const SNEJ_CLICKS_TO_FIRE = 30;
const SNEJ_RESET_DELAY_START = 420;
const SNEJ_RESET_DELAY_END = 180;
const SNEJ_MAX_SHAKE_LEVEL = SNEJ_CLICKS_TO_FIRE - 2;
const SNEJ_GLOW_START_CLICK = 8;
const SNEJ_MAX_GLOW_LEVEL = SNEJ_CLICKS_TO_FIRE - SNEJ_GLOW_START_CLICK;
const SNEJ_RARE_CHANCE = 0.12;
const SNEJ_RARE_VARIANTS = [
    'snej-laser-rare-purple',
    'snej-laser-rare-green',
    'snej-laser-rare-blue',
    'snej-laser-rare-gold',
    'snej-laser-rare-white'
];

function initSnejEasterEgg() {
    let clickCount = 0;
    let resetTimer = null;
    const snejDiv = document.querySelector('#snej');
    const snejInput = snejDiv ? snejDiv.querySelector('input[type="image"]') : null;
    const snejWrap = snejDiv ? snejDiv.querySelector('.snej-eye-wrap') : null;
    const snejGlow = snejDiv ? snejDiv.querySelector('.snej-eye-glow') : null;
    const snejHitArea = snejDiv ? snejDiv.querySelector('.snej-hit-area') : null;

    if (!snejInput || !snejWrap || !snejGlow || !snejHitArea) return;

    snejInput.addEventListener('dragstart', (e) => e.preventDefault());

    snejHitArea.addEventListener('click', (e) => {
        e.preventDefault();

        if (snejDiv.classList.contains('snej-laser-active')) return;

        clickCount++;
        clearTimeout(resetTimer);

        if (clickCount >= SNEJ_CLICKS_TO_FIRE) {
            clickCount = 0;
            resetSnejShake(snejWrap);
            resetSnejEyeFlash(snejGlow);
            fireSnejLaser(snejDiv);
            return;
        }

        if (clickCount > 1) {
            applySnejShake(snejWrap, clickCount - 1);
        }

        if (clickCount >= SNEJ_GLOW_START_CLICK) {
            applySnejEyeFlash(snejGlow, clickCount - SNEJ_GLOW_START_CLICK + 1);
        }

        resetTimer = setTimeout(() => {
            clickCount = 0;
            resetSnejShake(snejWrap);
        }, getSnejResetDelay(clickCount));
    });
}

function getSnejResetDelay(clickCount) {
    const progress = Math.min(clickCount / SNEJ_CLICKS_TO_FIRE, 1);
    return SNEJ_RESET_DELAY_START - progress * (SNEJ_RESET_DELAY_START - SNEJ_RESET_DELAY_END);
}

function applySnejShake(snejWrap, level) {
    const progress = Math.min(level / SNEJ_MAX_SHAKE_LEVEL, 1);
    const amplitude = 2 + progress * 10;
    const duration = Math.max(0.45 - progress * 0.28, 0.17);
    snejWrap.style.setProperty('--shake-amp', amplitude + 'px');

    snejWrap.style.animation = 'none';
    void snejWrap.offsetWidth;
    snejWrap.style.animation = `snej-shake-burst ${duration}s ease-out`;
}

function resetSnejShake(snejWrap) {
    snejWrap.style.animation = '';
    snejWrap.style.removeProperty('--shake-amp');
}

function applySnejEyeFlash(snejGlow, level) {
    const progress = Math.min(level / SNEJ_MAX_GLOW_LEVEL, 1);
    const peak = 0.4 + progress * 0.6;
    snejGlow.style.setProperty('--glow-peak', peak);

    snejGlow.style.animation = 'none';
    void snejGlow.offsetWidth;
    snejGlow.style.animation = 'snej-eye-pulse-decay 0.5s ease-out';
}

function resetSnejEyeFlash(snejGlow) {
    snejGlow.style.animation = '';
    snejGlow.style.removeProperty('--glow-peak');
}

function fireSnejLaser(snejDiv) {
    const isRare = Math.random() < SNEJ_RARE_CHANCE;
    const variant = isRare
        ? SNEJ_RARE_VARIANTS[Math.floor(Math.random() * SNEJ_RARE_VARIANTS.length)]
        : null;

    if (variant) snejDiv.classList.add(variant);
    snejDiv.classList.add('snej-laser-active');

    setTimeout(() => {
        snejDiv.classList.remove('snej-laser-active');
        if (variant) snejDiv.classList.remove(variant);
    }, 950);
}