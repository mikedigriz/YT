const CONFIG = {
    fastInterval: 1500,
    slowInterval: 12000
};

const nativeUI = {};
let previousFinishedPids = null;
let previousVideoKeys = null;
let previousMusicKeys = null;
let lastActiveState = false;
let originalDocTitle = null;
let notifyEnabled = false;
let notifyToggleBtn = null;
const NOTIFY_KEY = 'yt_notify_enabled';
const BG_POLL_INTERVAL = 5000;

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

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

// Общий fetch-сабмит для деструктивных/тумблерных действий - без full-reload формы, только диффовая перерисовка через loadList()/renderTable().
function submitActionFetch(fields) {
    return fetch('index.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': getCsrfToken()
        },
        body: new URLSearchParams(fields)
    })
        .then(resp => {
            // Сервер перезапустился - сессия и CSRF-токен на странице устарели.
            // Без этой проверки resp.json() падал в catch(() => null), и кнопка
            // молча ничего не делала - пользователь не понимал, что случилось.
            if (resp.status === 403) {
                alert('Страница устарела после перезапуска сервера. Сейчас обновим её - повторите действие.');
                window.location.reload();
                return null;
            }
            return resp.json().catch(() => null);
        })
        .then(data => {
            if (data && data.errors && data.errors.length) {
                alert(data.errors.join('\n'));
            }
            return loadList();
        })
        .catch(() => {});
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
        'removeQueued': 'Удалить из очереди?',
        'clearDownloads': 'Удалить все скачанные файлы безвозвратно?'
    };

    if (!confirm(messages[action] || 'Выполнить действие?')) {
        return;
    }

    submitActionFetch({ [action]: value, ...extraFields });
}

// Закреп/откреп файла - без confirm(): действие обратимое, один клик туда-обратно.
function togglePin(name, type, pinned) {
    submitActionFetch({ pin: name, type: type, pinned: pinned ? '1' : '0' });
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

function updateProxyStatus(proxy) {
    const box = document.getElementById('proxy-status');
    if (!box || !proxy || !proxy.enabled) return;

    // Прокси не задан - серверный рендер уже показал "Прокси не установлен",
    // трогать нечего.
    if (proxy.unset) {
        box.setAttribute('data-state', 'unset');
        return;
    }

    const dotClass = v => {
        if (v === null || v === undefined) return 'is-pending';
        if (v === 'warn') return 'is-warn';
        return v === 'death' ? 'is-death' : 'is-work';
    };
    const windows = proxy.windows || {};
    box.querySelectorAll('.proxy-dot').forEach(dot => {
        const win = dot.getAttribute('data-win');
        dot.classList.remove('is-work', 'is-warn', 'is-death', 'is-pending');
        dot.classList.add(dotClass(windows[win]));
    });

    box.setAttribute('data-state', proxy.state || 'pending');
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
const notificationsSupported = !isMobileDevice && ('Notification' in window);
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
    btn.tabIndex = 0;
    btn.setAttribute('role', 'button');

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

    // Кастомный div, не нативная <button> - без этого клавиатурный фокус
    // не может активировать переключатель.
    btn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            btn.click();
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

// Канвас-салют при завершении (только видимая вкладка). Уважает prefers-reduced-motion, убирает себя из DOM сам.
function fireConfetti() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const canvas = document.createElement('canvas');
    canvas.className = 'confetti-canvas';
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    document.body.appendChild(canvas);
    const ctx = canvas.getContext('2d');

    const colors = ['#e04b4b', '#e0a83f', '#4bb87a', '#4b8fe0', '#a05fe0'];
    // Произвольная точка старта - не жёсткий центр-верх, а разброс по всему
    // экрану (с отступом от самых краёв, чтобы залп не срезало сразу).
    const originX = canvas.width * (0.15 + Math.random() * 0.7);
    const originY = canvas.height * (0.15 + Math.random() * 0.55);
    const particles = [];
    for (let i = 0; i < 60; i++) {
        particles.push({
            x: originX + (Math.random() - 0.5) * 60,
            y: originY,
            vx: (Math.random() - 0.5) * 8,
            vy: -Math.random() * 8 - 4,
            size: 4 + Math.random() * 4,
            color: colors[Math.floor(Math.random() * colors.length)],
            rotation: Math.random() * Math.PI * 2,
            vr: (Math.random() - 0.5) * 0.3,
            life: 0
        });
    }

    const gravity = 0.25;
    const maxLife = 90;

    function frame() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        let alive = false;
        for (const p of particles) {
            if (p.life > maxLife) continue;
            alive = true;
            p.vy += gravity;
            p.x += p.vx;
            p.y += p.vy;
            p.rotation += p.vr;
            p.life++;
            ctx.save();
            ctx.globalAlpha = Math.max(0, 1 - p.life / maxLife);
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rotation);
            ctx.fillStyle = p.color;
            ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
            ctx.restore();
        }
        if (alive) {
            requestAnimationFrame(frame);
        } else {
            canvas.remove();
        }
    }
    requestAnimationFrame(frame);
}

// === Уведомления о готовности + прогресс в заголовке вкладки ===
// Плашкой ОС зовём только при document.hidden - на видимой вкладке хватает звука и таблицы.
function showCompletionNotification(successItems, failureItems) {
    if (!notifyEnabled || !notificationsSupported || Notification.permission !== 'granted') return;

    const okCount = successItems.length;
    const failCount = failureItems.length;
    if (okCount + failCount === 0) return;

    let title, body;
    if (failCount && !okCount) {
        title = failCount === 1 ? 'Не вышло скачать' : `Не вышло: ${failCount} шт.`;
        body = failCount === 1 ? (failureItems[0].file || '') : '';
    } else if (okCount && !failCount) {
        title = okCount === 1 ? 'Готово, лови' : `Готово: ${okCount} шт.`;
        body = okCount === 1 ? (successItems[0].file || '') : '';
    } else {
        title = 'Загрузки завершились';
        body = `Готово ${okCount}, не вышло ${failCount}`;
    }

    try {
        const note = new Notification(title, {
            body: body,
            icon: (typeof MASCOT_IMG !== 'undefined' ? MASCOT_IMG : 'img/snej.webp'),
            tag: 'yt-download',   // одна плашка на сайт, стек не копим
            silent: true          // свой звук уже играет, системный не дублируем
        });
        note.onclick = () => {
            window.focus();
            window.location.hash = '#downloads';
            note.close();
        };
    } catch (e) {}
}

// Процент вперёд: заголовок браузер режет с конца, значит число доживает до
// самой узкой вкладки. Нет активных задач - возвращаем исходный заголовок.
function updateTabTitleProgress(jobs) {
    if (originalDocTitle === null) originalDocTitle = document.title;

    if (!jobs || jobs.length === 0) {
        if (document.title !== originalDocTitle) document.title = originalDocTitle;
        return;
    }

    let pct = null;
    for (const job of jobs) {
        const m = /(\d{1,3}(?:\.\d+)?)%/.exec(job.status || '');
        if (m) { pct = Math.min(100, Math.round(parseFloat(m[1]))); break; }
    }

    const next = (pct !== null ? pct + '% ' : '⏳ ') + originalDocTitle;
    if (document.title !== next) document.title = next;
}

function updateNotifyButtonVisuals(btn) {
    const denied = notificationsSupported && Notification.permission === 'denied';
    btn.innerHTML = notifyEnabled ? '🔔' : '🔕';
    if (denied) {
        btn.title = 'Браузер заблокировал уведомления. Разреши их в настройках сайта у адресной строки.';
    } else if (notifyEnabled) {
        btn.title = 'Позову, когда файл будет готов. Нажми, чтобы выключить.';
    } else {
        btn.title = 'Нажми - и позову, когда файл скачается, даже если ты на другой вкладке.';
    }
}

function updateNotifyButtonVisibility() {
    if (!notifyToggleBtn) return;
    notifyToggleBtn.classList.toggle('is-visible', isOnHomePage());
}

function initNotifyToggle() {
    if (!notificationsSupported) return;

    try {
        notifyEnabled = localStorage.getItem(NOTIFY_KEY) === 'true' && Notification.permission === 'granted';
    } catch (e) {
        notifyEnabled = false;
    }

    const btn = document.createElement('div');
    btn.id = 'notify-toggle';
    btn.className = 'notify-toggle';
    btn.tabIndex = 0;
    btn.setAttribute('role', 'button');
    notifyToggleBtn = btn;
    updateNotifyButtonVisuals(btn);
    updateNotifyButtonVisibility();

    btn.addEventListener('click', () => {
        if (Notification.permission === 'granted') {
            notifyEnabled = !notifyEnabled;
            try { localStorage.setItem(NOTIFY_KEY, notifyEnabled); } catch (e) {}
            updateNotifyButtonVisuals(btn);
        } else if (Notification.permission === 'denied') {
            // Сами включить не можем - разрешение зарублено в браузере. Коротко
            // качнём кнопку, чтобы человек заметил и глянул подсказку.
            btn.classList.remove('notify-nudge');
            void btn.offsetWidth;
            btn.classList.add('notify-nudge');
        } else {
            // Запрос из клика - осознанный жест, нативный диалог выйдет в понятном
            // контексте, сразу после нажатия.
            Notification.requestPermission().then((perm) => {
                notifyEnabled = perm === 'granted';
                try { localStorage.setItem(NOTIFY_KEY, notifyEnabled); } catch (e) {}
                updateNotifyButtonVisuals(btn);
            }).catch(() => {});
        }
    });

    // Кастомный div, не нативная <button> - без этого клавиатурный фокус
    // не может активировать переключатель.
    btn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            btn.click();
        }
    });

    document.body.appendChild(btn);
}

if (notificationsSupported) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initNotifyToggle();
            window.addEventListener('hashchange', updateNotifyButtonVisibility);
        });
    } else {
        initNotifyToggle();
        window.addEventListener('hashchange', updateNotifyButtonVisibility);
    }
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

    if (urlsCache.size > 500) urlsCache.clear();
    urlsCache.set(key, result);
    return result;
}

function computeDataHash(items) {
    if (!items || items.length === 0) return '0';
    let hash = '';
    for (const item of items) {
        for (const key in item) {
            if (item.hasOwnProperty(key)) {
                hash += item[key] + '|';
            }
        }
        hash += '|';
    }
    return hash;
}

function renderTable(container, items, cols, emptyMsg, rowHtmlGenerator, footerHtml = "") {
    // #dlqueue отсутствует в DOM при disableQueue=true - без этой проверки TypeError тут обрывал бы весь остаток loadList().
    if (!container) return;

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
                <button type="button" style="width: 100px;" data-action="kill" data-value="${safePid(job.pid)}" class="btn btn-danger btn-xs">Стоп</button>
            </div>
        </td>
    </tr>`;
}

// idx/arr приходят от map() автоматически - нужны, чтобы прятать "▲"/"▼" на первой/последней строке.
function renderQueueRow(item, idx, arr) {
    const urlsHtml = renderUrls(item.url, true, item.type, false);
    const isFirst = idx === 0;
    const isLast = !arr || idx === arr.length - 1;
    const pid = safePid(item.pid);
    const upBtn = `<button type="button" style="width: 30px" data-reorder-pid="${pid}" data-reorder-dir="up" class="btn btn-default btn-xs reorder-btn"${isFirst ? ' disabled' : ''} title="Поднять в очереди">▲</button>`;
    const downBtn = `<button type="button" style="width: 30px" data-reorder-pid="${pid}" data-reorder-dir="down" class="btn btn-default btn-xs reorder-btn"${isLast ? ' disabled' : ''} title="Опустить в очереди">▼</button>`;
    return `
    <tr>
        <td style="vertical-align: middle;">${urlsHtml}</td>
        <td style="vertical-align: middle;">${escapeHtml(item.dl_format)}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                ${upBtn}${downBtn}
                <button type="button" style="width: 160px" data-action="removeQueued" data-value="${pid}" class="btn btn-danger btn-xs">Удалить</button>
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
        logButton = `<a href="${safeUrlAttr(logURL)}/${safePid(item.pid)}" style="width: 40px;" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-xs">Лог</a>`;
    }

    return `
    <tr>
        <td style="vertical-align: middle;">${escapeHtml(item.site)}</td>
        <td style="vertical-align: middle;"><i class="fa ${iconClass}"></i> ${escapeHtml(item.file)} ${urlsHtml}</td>
        <td style="vertical-align: middle;">${escapeHtml(item.status)}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                ${logButton}
                <button type="button" style="width: ${actionBtnWidth}" data-action="restart" data-value="${safePid(item.pid)}" class="btn btn-success btn-xs">↺</button>
                <button type="button" style="width: ${actionBtnWidth}" data-action="clear" data-value="${safePid(item.pid)}" class="btn btn-danger btn-xs">Удалить</button>
            </div>
        </td>
    </tr>`;
}

function buildFileActions(file) {
    const playIcon = `<svg class="play-btn-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>`;
    const playButton = file.downloadurl
        ? `<button type="button" class="btn btn-default btn-xs play-btn" title="Глянуть/послушать прямо тут" data-play-url="${escapeHtml(file.downloadurl)}" data-play-kind="${escapeHtml(file.kind || 'video')}">${playIcon}</button>`
        : '';

    const qrIcon = `<svg class="qr-btn-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M3 3h8v8H3V3zm2 2v4h4V5H5zm-2 8h8v8H3v-8zm2 2v4h4v-4H5zM13 3h8v8h-8V3zm2 2v4h4V5h-4zm-2 8h3v2h-2v3h-2v-5h1zm5 0h3v3h-2v2h-3v-2h2v-3zm-3 5h2v3h-2v-3zm5 0h3v3h-3v-3z"/></svg>`;
    const qrButton = file.downloadurl
        ? `<button type="button" class="btn btn-default btn-xs qr-btn" title="Забрать на телефон - покажу QR" data-qr-url="${escapeHtml(file.downloadurl)}">${qrIcon}</button>`
        : '';

    const pinIcon = `<svg class="pin-btn-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M16 3l5 5-1.9 1.9-2.5-.6-3.6 3.6.9 3.7-1.9 1.9-3.5-3.5-4.8 4.8-1.4-1.4 4.8-4.8-3.5-3.5 1.9-1.9 3.7.9 3.6-3.6-.6-2.5z"/></svg>`;
    const pinned = !!file.pinned;
    const pinButton = file.name
        ? `<button type="button" class="btn btn-default btn-xs pin-btn${pinned ? ' pin-btn-active' : ''}" title="${pinned ? 'Открепить' : 'Закрепить - не удалится по времени и при «Очистить всё»'}" data-pin-name="${file.name}" data-pin-type="${file.kind === 'audio' ? 'm' : 'v'}" data-pin-pinned="${pinned ? '1' : '0'}">${pinIcon}</button>`
        : '';

    if (!playButton && !qrButton && !pinButton && !file.deleteurl) return '';
    return `<div class="btn-group btn-group-file">${playButton}${qrButton}${pinButton}${file.deleteurl}</div>`;
}

// Ключ файла для отслеживания "новых" строк между опросами - downloadurl
// стабилен и уникален per-файл, имя внутри HTML-ссылки как запасной вариант.
function getFileKey(file) {
    return file.downloadurl || file.file;
}

function renderFileRow(file, isNew) {
    const actions = buildFileActions(file);
    const enterClass = isNew ? ' row-enter-cell' : '';

    if (typeof showFileLifetime !== 'undefined' && !showFileLifetime) {
        return `<tr><td><span class="file-name-plain${enterClass}">${file.file}</span></td><td>${escapeHtml(file.size)}</td><td>${actions}</td></tr>`;
    }

    const pinned = !!file.pinned;
    let timeText, colorClass, percent;

    if (pinned) {
        timeText = '📌 закреплён';
        colorClass = 'bg-safe';
        percent = 100;
    } else {
        const age = (typeof file.age_minutes === 'number' && !isNaN(file.age_minutes)) ? file.age_minutes : 0;
        percent = (typeof file.lifetime_percent === 'number' && !isNaN(file.lifetime_percent)) ? file.lifetime_percent : 100;
        const retention = (typeof retentionMinutes !== 'undefined' && retentionMinutes > 0) ? retentionMinutes : 120;
        const remainingMinutes = Math.max(0, retention - age);

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
    }

    const badge = `
        <div class="lifetime-badge">
            <div class="lifetime-badge-text">${pinned ? '' : '⏱ '}${timeText}</div>
            <div class="progress">
                <div class="progress-bar progress-lifetime ${colorClass}" style="width: ${percent}%;"></div>
            </div>
        </div>`;

    return `
        <tr>
            <td>
                <div class="file-row-content${enterClass}">
                    ${badge}
                    <span class="file-name">${file.file}</span>
                </div>
            </td>
            <td>${escapeHtml(file.size)}</td>
            <td>${actions}</td>
        </tr>`;
}

// === QR-код на готовый файл ===
// Абсолютная ссылка на текущий хост - телефон в той же сети заберёт файл без проброса маршрутов.
function buildAbsoluteFileUrl(relativeUrl) {
    try {
        return new URL(relativeUrl, window.location.href).href;
    } catch (e) {
        return null;
    }
}

let qrModalEl = null;
let qrLibPromise = null;

// Ленивая загрузка QR-либы (~56КБ) при первом клике, вне критического пути. Промис кэшируется.
function ensureQrLib() {
    if (typeof qrcode !== 'undefined') return Promise.resolve();
    if (qrLibPromise) return qrLibPromise;

    qrLibPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'js/qrcode.min.js';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            qrLibPromise = null; // дать шанс повторить при следующем клике
            reject(new Error('Не удалось загрузить библиотеку QR'));
        };
        document.head.appendChild(script);
    });
    return qrLibPromise;
}

function ensureQrModal() {
    if (qrModalEl) return qrModalEl;

    const overlay = document.createElement('div');
    overlay.className = 'qr-modal-overlay';
    overlay.innerHTML = `
        <div class="qr-modal-card" role="dialog" aria-modal="true" aria-label="QR-код, чтобы забрать файл на телефон">
            <button type="button" class="qr-modal-close" aria-label="Закрыть">&times;</button>
            <div class="qr-modal-title">Лови на телефон</div>
            <div class="qr-modal-subtitle">Наведи камеру - и файл уже у тебя</div>
            <div class="qr-modal-canvas-wrap">
                <canvas class="qr-modal-canvas" width="320" height="320"></canvas>
                <div class="qr-modal-bird"><img class="qr-modal-bird-img" src="${typeof MASCOT_IMG !== 'undefined' ? MASCOT_IMG : 'img/snej.webp'}" alt="" draggable="false"></div>
            </div>
            <div class="qr-modal-filename"></div>
            <div class="qr-modal-hint">Снегирь покараулит ссылку, не торопись</div>
            <a class="qr-modal-link" href="#" target="_blank" rel="noopener">Открыть прямо здесь</a>
        </div>`;

    document.body.appendChild(overlay);
    qrModalEl = overlay;

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) hideQrModal();
    });
    overlay.querySelector('.qr-modal-close').addEventListener('click', hideQrModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.classList.contains('is-visible')) hideQrModal();
    });

    return overlay;
}

function drawQrToCanvas(canvas, text) {
    if (typeof qrcode === 'undefined') throw new Error('Библиотека QR не загружена');

    // 0 - авто-подбор версии под длину данных; 'H' - максимальная коррекция (~30%),
    // чтобы снегирь в центре не мешал считыванию.
    const qr = qrcode(0, 'H');
    qr.addData(text);
    qr.make();

    const count = qr.getModuleCount();
    const size = canvas.width;
    const margin = 4; // тихая зона в модулях
    const cell = size / (count + margin * 2);
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#1c1c1c';
    for (let r = 0; r < count; r++) {
        for (let c = 0; c < count; c++) {
            if (qr.isDark(r, c)) {
                const x = (c + margin) * cell;
                const y = (r + margin) * cell;
                ctx.fillRect(Math.floor(x), Math.floor(y), Math.ceil(cell), Math.ceil(cell));
            }
        }
    }
}

function showQrModal(relativeUrl) {
    const abs = buildAbsoluteFileUrl(relativeUrl);
    if (!abs) return;

    const overlay = ensureQrModal();

    let name = relativeUrl;
    try { name = decodeURIComponent(relativeUrl.split('/').pop()); } catch (e) {}
    overlay.querySelector('.qr-modal-filename').textContent = name;
    overlay.querySelector('.qr-modal-link').href = abs;

    // Модалку показываем сразу - моментальный отклик на клик; код рисуем, как
    // только подтянется библиотека (после первого раза она уже в памяти).
    overlay.classList.add('is-visible');

    const canvas = overlay.querySelector('.qr-modal-canvas');
    ensureQrLib()
        .then(() => drawQrToCanvas(canvas, abs))
        .catch((e) => console.error('Не удалось построить QR:', e));
}

function hideQrModal() {
    if (qrModalEl) qrModalEl.classList.remove('is-visible');
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.qr-btn');
    if (!btn) return;
    e.preventDefault();
    const url = btn.getAttribute('data-qr-url');
    if (url) showQrModal(url);
});

// === Плеер прямо на странице ===
// Модалка живёт вне таблиц Видео/Музыка: те перерисовываются целиком на каждом опросе ?jobs, живой <video>/<audio> внутри строки обрывался бы.
let playerModalEl = null;

function ensurePlayerModal() {
    if (playerModalEl) return playerModalEl;

    const overlay = document.createElement('div');
    overlay.className = 'qr-modal-overlay player-modal-overlay';
    overlay.innerHTML = `
        <div class="qr-modal-card player-modal-card" role="dialog" aria-modal="true" aria-label="Просмотр файла">
            <button type="button" class="qr-modal-close" aria-label="Закрыть">&times;</button>
            <div class="qr-modal-title player-modal-title"></div>
            <div class="player-modal-video-wrap is-hidden">
                <video class="player-modal-video" controls playsinline preload="metadata"></video>
            </div>
            <div class="player-modal-audio-wrap is-hidden">
                <audio class="player-modal-audio" controls preload="metadata"></audio>
                <canvas class="player-modal-viz is-hidden" width="440" height="48"></canvas>
            </div>
            <div class="qr-modal-filename player-modal-filename"></div>
        </div>`;

    document.body.appendChild(overlay);
    playerModalEl = overlay;

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) hidePlayerModal();
    });
    overlay.querySelector('.qr-modal-close').addEventListener('click', hidePlayerModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.classList.contains('is-visible')) hidePlayerModal();
    });

    // AudioContext создаётся лениво в 'play' (клик по нативным controls обходит autoplay-политику).
    // createMediaElementSource() можно вызвать на элементе только раз за всю жизнь страницы - ensurePlayerAudioViz() поэтому no-op при повторе.
    const audio = overlay.querySelector('.player-modal-audio');
    const vizCanvas = overlay.querySelector('.player-modal-viz');
    audio.addEventListener('play', () => {
        ensurePlayerAudioViz(audio);
        const startViz = () => {
            if (playerAnalyser) {
                playerVizRunning = true;
                drawPlayerViz(vizCanvas, playerAnalyser);
            }
        };
        // resume() асинхронный - пока suspended, анализатор отдаёт нули, ждём завершения.
        if (playerAudioCtx && playerAudioCtx.state === 'suspended') {
            playerAudioCtx.resume().then(startViz).catch(startViz);
        } else {
            startViz();
        }
    });
    audio.addEventListener('pause', () => {
        playerVizRunning = false;
        vizCanvas.classList.add('is-hidden');
    });
    audio.addEventListener('ended', () => {
        playerVizRunning = false;
        vizCanvas.classList.add('is-hidden');
    });

    return overlay;
}

let playerAudioCtx = null;
let playerAnalyser = null;
let playerAudioSource = null;
let playerVizRunning = false;

function ensurePlayerAudioViz(audio) {
    if (playerAudioSource) return;
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        playerAudioCtx = new AudioCtx();
        playerAudioSource = playerAudioCtx.createMediaElementSource(audio);
        playerAnalyser = playerAudioCtx.createAnalyser();
        playerAnalyser.fftSize = 128;
        // Аналайзер обязателен в цепочке до destination, иначе звук пропадает.
        playerAudioSource.connect(playerAnalyser);
        playerAnalyser.connect(playerAudioCtx.destination);
    } catch (e) {
        console.warn('Аудио-визуализация недоступна:', e);
        playerAudioSource = null;
    }
}

// Кадров тишины (~1.5с при 60fps) до скрытия блока - короткие паузы не должны мигать пустой рамкой.
const VIZ_SILENCE_FRAMES = 90;

function drawPlayerViz(canvas, analyser) {
    const ctx = canvas.getContext('2d');
    const bufferLength = analyser.frequencyBinCount;
    const data = new Uint8Array(bufferLength);
    let silentFrames = VIZ_SILENCE_FRAMES;

    function frame() {
        if (!playerVizRunning) return;
        try {
            analyser.getByteFrequencyData(data);
            let hasSignal = false;
            for (let i = 0; i < bufferLength; i++) {
                if (data[i] > 2) { hasSignal = true; break; }
            }

            // Нет сигнала (тишина или обнулено антифингерпринт-защитой) - прячем блок целиком.
            if (hasSignal) {
                silentFrames = 0;
            } else {
                silentFrames++;
            }
            canvas.classList.toggle('is-hidden', silentFrames >= VIZ_SILENCE_FRAMES);

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const barWidth = canvas.width / bufferLength;
            for (let i = 0; i < bufferLength; i++) {
                const barHeight = (data[i] / 255) * canvas.height;
                ctx.fillStyle = '#b8960b';
                ctx.fillRect(i * barWidth, canvas.height - barHeight, barWidth - 1, barHeight);
            }
        } catch (e) {
            // Напр. SecurityError на кросс-доменном источнике без CORS.
            console.warn('Аудио-визуализация остановлена:', e);
            playerVizRunning = false;
            return;
        }
        requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
}

// preload="metadata" - тянет только длительность/заголовок; поток пойдёт Range-запросами после play.
function showPlayerModal(relativeUrl, kind) {
    const abs = buildAbsoluteFileUrl(relativeUrl);
    if (!abs) return;

    const overlay = ensurePlayerModal();
    const videoWrap = overlay.querySelector('.player-modal-video-wrap');
    const audioWrap = overlay.querySelector('.player-modal-audio-wrap');
    const video = overlay.querySelector('.player-modal-video');
    const audio = overlay.querySelector('.player-modal-audio');
    const isAudio = kind === 'audio';

    overlay.querySelector('.player-modal-title').textContent = isAudio ? 'Слушаем' : 'Смотрим';

    let name = relativeUrl;
    try { name = decodeURIComponent(relativeUrl.split('/').pop()); } catch (e) {}
    overlay.querySelector('.player-modal-filename').textContent = name;

    videoWrap.classList.toggle('is-hidden', isAudio);
    audioWrap.classList.toggle('is-hidden', !isAudio);
    overlay.querySelector('.player-modal-viz').classList.add('is-hidden');

    // Останавливаем неиспользуемый плеер целиком - иначе тихо тянет предыдущий файл в фоне.
    if (isAudio) {
        video.pause();
        video.removeAttribute('src');
        video.load();
        audio.src = abs;
    } else {
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
        video.src = abs;
    }

    // Автоплей нарочно не включён - без внезапного звука на всю систему.
    overlay.classList.add('is-visible');
}

function hidePlayerModal() {
    if (!playerModalEl) return;
    playerModalEl.classList.remove('is-visible');

    // Останавливаем оба плеера и снимаем src при закрытии - иначе браузер тянет файл в фоне.
    const video = playerModalEl.querySelector('.player-modal-video');
    const audio = playerModalEl.querySelector('.player-modal-audio');
    video.pause();
    video.removeAttribute('src');
    video.load();
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.play-btn');
    if (!btn) return;
    e.preventDefault();
    const url = btn.getAttribute('data-play-url');
    const kind = btn.getAttribute('data-play-kind');
    if (url) showPlayerModal(url, kind);
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.pin-btn');
    if (!btn) return;
    e.preventDefault();
    const name = btn.getAttribute('data-pin-name');
    const type = btn.getAttribute('data-pin-type');
    const currentlyPinned = btn.getAttribute('data-pin-pinned') === '1';
    if (name) togglePin(name, type, !currentlyPinned);
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.reorder-btn');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    submitActionFetch({
        reorderQueue: btn.getAttribute('data-reorder-pid'),
        direction: btn.getAttribute('data-reorder-dir')
    });
});

// Делегированные обработчики вместо inline on* (CSP без unsafe-inline).
document.addEventListener('click', function (e) {
    // Деструктивные действия: kill/delete/clear/restart/removeQueued
    const act = e.target.closest('[data-action]');
    if (act) {
        e.preventDefault();
        const extra = {};
        const type = act.getAttribute('data-type');
        if (type) extra.type = type;
        confirmAction(act.getAttribute('data-action'), act.getAttribute('data-value') || '', extra);
        return;
    }
    // Свернуть/развернуть панель помощи в футере
    if (e.target.closest('[data-ui="help"]')) {
        helpPanel();
        return;
    }
    // Переход на вкладку по ссылкам из футера (data-goto = id ссылки в навигации)
    const goto = e.target.closest('[data-goto]');
    if (goto) {
        e.preventDefault();
        const nav = document.getElementById(goto.getAttribute('data-goto'));
        if (nav) nav.click();
        return;
    }
});

// Клавиатурная активация панели помощи (кастомный div с tabindex, не нативная
// <button> - без этого Enter/Space на фокусе ничего не делают).
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    if (e.target.closest('[data-ui="help"]')) {
        e.preventDefault();
        helpPanel();
    }
});

// "/" (как на GitHub) или Ctrl/Cmd+K - фокус на поле ссылки. Ctrl+K обычно зарезервирован браузером под адресную строку, "/" - основной способ.
// Не перехватываем при фокусе в input/textarea/contenteditable, чтобы "/" вводился как обычный символ.
document.addEventListener('keydown', function (e) {
    const isSlash = e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey;
    const isCtrlK = (e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k';
    if (!isSlash && !isCtrlK) return;

    const active = document.activeElement;
    const isTyping = active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable);
    if (isTyping) return;

    const urlInput = document.getElementById('url');
    if (!urlInput) return;
    e.preventDefault();
    urlInput.focus();
    urlInput.select();
});

// Тумблеры аудио/качество и подрежимы: раньше были onchange="syncLogic()" /
// onchange="syncSubToggles(this)"
document.addEventListener('change', function (e) {
    const t = e.target;
    if (!t) return;
    if (t.id === 'ui_audio_mode' || t.id === 'ui_quality_toggle') {
        syncLogic();
    } else if (t.classList && t.classList.contains('toggle-input-sub')) {
        syncSubToggles(t);
    }
});

// Нормализованные ссылки из истории/активных/очереди - для "уже качали" на сабмите, без отдельного запроса.
let knownDownloadedUrls = new Set();

function collectKnownUrls(data) {
    const set = new Set();
    for (const bucket of [data.finished, data.jobs, data.queue]) {
        for (const item of bucket || []) {
            (item.url || '').split(',').forEach(u => {
                const t = u.trim().toLowerCase();
                if (t) set.add(t);
            });
        }
    }
    return set;
}

let pollErrorBadgeEl = null;

// Неблокирующая плашка на неудачный опрос ?jobs - раньше ошибка уходила молча в console.error.
function ensurePollErrorBadge() {
    if (pollErrorBadgeEl) return pollErrorBadgeEl;
    pollErrorBadgeEl = document.createElement('div');
    pollErrorBadgeEl.className = 'alert alert-warning poll-error-badge';
    pollErrorBadgeEl.style.display = 'none';
    pollErrorBadgeEl.textContent = 'Не удалось обновить данные, повторяю...';
    const container = document.querySelector('.container');
    (container || document.body).insertBefore(pollErrorBadgeEl, (container || document.body).firstChild);
    return pollErrorBadgeEl;
}

function showPollErrorBadge() {
    ensurePollErrorBadge().style.display = '';
}

function hidePollErrorBadge() {
    if (pollErrorBadgeEl) {
        pollErrorBadgeEl.style.display = 'none';
    }
}

function loadList() {
    fetch("index.php?jobs")
        .then(resp => resp.json())
        .then(function (data) {
        hidePollErrorBadge();
        knownDownloadedUrls = collectKnownUrls(data);
        const currentFinishedPids = new Set();
        for (const item of data.finished) {
            currentFinishedPids.add(String(item.pid));
        }

        if (previousFinishedPids !== null) {
            const newSuccess = [];
            const newFailure = [];

            for (let item of data.finished) {
                const pid = String(item.pid);
                if (!previousFinishedPids.has(pid)) {
                    (isDownloadFailed(item) ? newFailure : newSuccess).push(item);
                }
            }

            // Вибрация не требует диалога разрешений (в отличие от Notification) -
            // просто дублирует звук на мобильном, если вкладка беззвучна/в фоне.
            if (newFailure.length) {
                playNotificationSound(false);
                if (navigator.vibrate) navigator.vibrate([80, 40, 80]);
            } else if (newSuccess.length) {
                playNotificationSound(true);
                if (navigator.vibrate) navigator.vibrate(120);
                if (!document.hidden) fireConfetti();
            }

            // Плашкой зовём только при скрытой вкладке - иначе звука и таблицы хватает.
            if (document.hidden && (newSuccess.length || newFailure.length)) {
                showCompletionNotification(newSuccess, newFailure);
            }
        }

        previousFinishedPids = currentFinishedPids;

        renderTable(nativeUI.progress, data.jobs, 4, "Активных загрузок нет.", renderJobRow, `
            <td></td><td></td><td></td>
            <td><div class="btn-group"><button type="button" id="killallbutton" style="width: 100px;" class="btn btn-danger btn-xs" data-action="kill" data-value="all">Стоп ВСЕ</button></div></td>`);

        renderTable(nativeUI.queue, data.queue, 3, "Очередь пуста.", renderQueueRow, `
            <td></td><td></td>
            <td><div class="btn-group"><button type="button" id="clearallbutton-queue" style="width: 160px;" class="btn btn-danger btn-xs" data-action="clear" data-value="queue">Удалить Все</button></div></td>`);

        renderTable(nativeUI.completed, data.finished, 4, "Завершенных загрузок нет.", item => renderFinishedRow(item, data.logURL), `
            <td></td><td></td><td></td>
            <td><div class="btn-group"><button type="button" id="clearallbutton-finished" style="width: 160px;" class="btn btn-danger btn-xs" data-action="clear" data-value="recent">Удалить Все</button></div></td>`);

        // Анимация только для реально новых файлов, не при каждом обновлении (напр. смена % "времени жизни"). Первый опрос за сессию - база, не "новые".
        const currentVideoKeys = new Set((data.videos || []).map(getFileKey));
        const newVideoKeys = previousVideoKeys === null ? new Set()
            : new Set([...currentVideoKeys].filter(k => !previousVideoKeys.has(k)));
        previousVideoKeys = currentVideoKeys;

        const currentMusicKeys = new Set((data.music || []).map(getFileKey));
        const newMusicKeys = previousMusicKeys === null ? new Set()
            : new Set([...currentMusicKeys].filter(k => !previousMusicKeys.has(k)));
        previousMusicKeys = currentMusicKeys;

        const clearVideosFooter = (typeof allowFileDelete !== 'undefined' && allowFileDelete)
            ? `<td></td><td></td>
            <td><button type="button" style="width: 120px;" class="btn btn-danger btn-xs" data-action="clearDownloads" data-value="all" data-type="v">Очистить всё</button></td>` : "";
        const clearMusicFooter = (typeof allowFileDelete !== 'undefined' && allowFileDelete)
            ? `<td></td><td></td>
            <td><button type="button" style="width: 120px;" class="btn btn-danger btn-xs" data-action="clearDownloads" data-value="all" data-type="m">Очистить всё</button></td>` : "";

        renderTable(nativeUI.videos, data.videos, 3, "Видео нет.", item => renderFileRow(item, newVideoKeys.has(getFileKey(item))), clearVideosFooter);
        renderTable(nativeUI.music, data.music, 3, "Музыки нет.", item => renderFileRow(item, newMusicKeys.has(getFileKey(item))), clearMusicFooter);
        updateFileBadges(data);
        updateProxyStatus(data.proxy);
        updateTabTitleProgress(data.jobs);

        // Лёгкий пульс маскота, пока есть хотя бы одна реально качающаяся задача
        // (не просто стоящая в очереди) - живой отклик без лишнего UI-шума.
        const snejEl = document.getElementById('snej');
        if (snejEl) {
            snejEl.classList.toggle('snej-is-downloading', !!(data.jobs && data.jobs.length > 0));
        }

        const isActive = (data.jobs && data.jobs.length > 0) || (data.queue && data.queue.length > 0);
        lastActiveState = isActive;

        // В фоне (вкладка скрыта) держим опрос живым, только пока реально ждём
        // финиша. Всё стихло - гасим сами, чтобы не молотить впустую.
        if (document.hidden && !isActive) {
            stopAutoRefresh();
            return;
        }

        scheduleNextRefresh(document.hidden ? BG_POLL_INTERVAL : (isActive ? CONFIG.fastInterval : CONFIG.slowInterval));

    }).catch(function () {
        console.error("Не удалось загрузить данные");
        showPollErrorBadge();
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
        // Уведомления включены и что-то качается - не глушим опрос, роняем в редкий
        // фоновый интервал, чтобы поймать финиш и позвать. Иначе как раньше: стоп.
        if (notifyEnabled && lastActiveState && notificationsSupported
            && Notification.permission === 'granted') {
            scheduleNextRefresh(BG_POLL_INTERVAL);
        } else {
            stopAutoRefresh();
        }
    } else {
        if (nativeUI.progress) {
            if (!refreshActive) {
                startAutoRefresh();
            } else {
                clearTimeout(refreshTimer);
                loadList();
            }
        }
    }
});

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

    // KNOWN_SERVICES - глобальная переменная, инжектится в head из
    // config/favicon_domains.json (см. index.php), общий источник с load_favicons.py
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
            if (faviconCache.size > 500) faviconCache.clear();
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
            if (faviconCache.size > 500) faviconCache.clear();
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

    // Разбор многострочного текста (paste/drag-and-drop): дедуп, склейка через "||" - разделитель, понятный Downloader::addOneDownload().
    function mergeUrlLines(text) {
        const existing = urlInput.value.trim();
        const incoming = text.split(/\r\n|\r|\n/).map(s => s.trim()).filter(Boolean);
        const combined = existing ? existing.split('||').map(s => s.trim()).filter(Boolean).concat(incoming) : incoming;
        const seen = new Set();
        return combined.filter(u => {
            const key = u.toLowerCase();
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        }).join('||');
    }

    // Обычный paste в <input> молча схлопывает \n - ловим сырой текст буфера сами.
    urlInput.addEventListener('paste', (e) => {
        const clipboard = e.clipboardData || window.clipboardData;
        const text = clipboard ? clipboard.getData('text') : '';
        if (text && /[\r\n]/.test(text)) {
            e.preventDefault();
            urlInput.value = mergeUrlLines(text);
        }
        setTimeout(checkUrl, 10);
    });

    // Drag-and-drop: text/uri-list (может нести несколько строк) или text/plain, тот же mergeUrlLines.
    ['dragover', 'dragenter'].forEach(evt => {
        wrapper.addEventListener(evt, (e) => {
            e.preventDefault();
            wrapper.classList.add('url-drop-active');
        });
    });
    ['dragleave', 'dragend'].forEach(evt => {
        wrapper.addEventListener(evt, () => wrapper.classList.remove('url-drop-active'));
    });
    wrapper.addEventListener('drop', (e) => {
        e.preventDefault();
        wrapper.classList.remove('url-drop-active');
        const dt = e.dataTransfer;
        const text = dt ? (dt.getData('text/uri-list') || dt.getData('text/plain')) : '';
        if (!text) return;
        urlInput.value = mergeUrlLines(text);
        urlInput.focus();
        checkUrl();
    });
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

    // Магия буфера: подставляем ссылку из буфера при возврате на вкладку, только если домен есть в KNOWN_SERVICES.
    // readText() без жеста пользователя триггерит пугающий диалог разрешения - поэтому auto-вызов (visibilitychange/focus) идёт только если фича включена явным кликом по кнопке.
    const CLIPBOARD_MAGIC_KEY = 'clipboardMagicEnabled';
    const CLIPBOARD_MAGIC_DISMISSED_KEY = 'clipboardMagicDismissed';
    let lastClipboardText = null;

    function playSnejWink() {
        const snejDiv = document.getElementById('snej');
        if (!snejDiv) return;
        snejDiv.classList.remove('snej-wink');
        void snejDiv.offsetWidth;
        snejDiv.classList.add('snej-wink');
        setTimeout(() => snejDiv.classList.remove('snej-wink'), 500);
    }

    function isClipboardMagicEnabled() {
        try {
            return localStorage.getItem(CLIPBOARD_MAGIC_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function matchKnownServiceUrl(text) {
        const trimmed = (text || '').trim();
        if (!trimmed) return null;
        let hostname = null;
        try {
            let urlToParse = trimmed;
            if (!/^https?:\/\//i.test(urlToParse)) urlToParse = 'https://' + urlToParse;
            hostname = new URL(urlToParse).hostname.replace(/^www\./i, '');
        } catch (e) {
            return null;
        }
        const service = hostname ? getBaseService(hostname) : null;
        return service ? trimmed : null;
    }

    function applyClipboardText(trimmed) {
        urlInput.value = trimmed;
        checkUrl();
        playSnejWink();
    }

    function tryClipboardMagic() {
        if (!isClipboardMagicEnabled()) return;
        if (isClearing || !document.hasFocus()) return;
        if (urlInput.value.trim()) return;
        if (!navigator.clipboard || !navigator.clipboard.readText) return;

        navigator.clipboard.readText().then((text) => {
            const trimmed = (text || '').trim();
            if (!trimmed || trimmed === lastClipboardText) return;
            lastClipboardText = trimmed;
            if (urlInput.value.trim() || isClearing) return;

            const matched = matchKnownServiceUrl(trimmed);
            if (!matched) return;
            applyClipboardText(matched);
        }).catch(() => {});
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) tryClipboardMagic();
    });
    window.addEventListener('focus', tryClipboardMagic);

    // Опт-ин плашка: показываем один раз, пока пользователь не ответил.
    const magicPrompt = document.getElementById('clipboard-magic-prompt');
    const magicYesBtn = document.getElementById('clipboard-magic-yes');
    const magicNoBtn = document.getElementById('clipboard-magic-no');

    function clipboardMagicDecided() {
        try {
            return localStorage.getItem(CLIPBOARD_MAGIC_KEY) !== null
                || localStorage.getItem(CLIPBOARD_MAGIC_DISMISSED_KEY) === '1';
        } catch (e) {
            return true;
        }
    }

    if (magicPrompt && magicYesBtn && magicNoBtn
        && navigator.clipboard && navigator.clipboard.readText
        && !clipboardMagicDecided()) {
        magicPrompt.classList.remove('is-hidden');

        magicYesBtn.addEventListener('click', () => {
            // Запрос идёт прямо из клика "Включить" - осознанный жест, диалог разрешения появится в понятном контексте.
            navigator.clipboard.readText().then(() => {
                try { localStorage.setItem(CLIPBOARD_MAGIC_KEY, '1'); } catch (e) {}
                magicPrompt.classList.add('is-hidden');
                tryClipboardMagic();
            }).catch(() => {
                magicPrompt.classList.add('is-hidden');
            });
        });

        magicNoBtn.addEventListener('click', () => {
            try { localStorage.setItem(CLIPBOARD_MAGIC_DISMISSED_KEY, '1'); } catch (e) {}
            magicPrompt.classList.add('is-hidden');
        });
    }
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
}

function syncHiddenSelects() {
    const hiddenAudioFormat = document.getElementById('audio_format');
    const activeToggle = document.querySelector('#params-audio .toggle-input-sub:checked');
    if (activeToggle) hiddenAudioFormat.value = activeToggle.getAttribute('data-value');
}

function initLongPressQualitySelector() {
    const downloadBtn = document.querySelector('.btn-download-minimal');
    const qualityPopup = document.getElementById('quality-popup');
    const downloadForm = document.getElementById('download-form');
    const formatField = document.getElementById('format');
    const translateField = document.getElementById('translate_field');
    const uiAudioMode = document.getElementById('ui_audio_mode');
    const controlsRow = document.querySelector('.controls-row');

    if (!downloadBtn || !qualityPopup) return;

    const ALLOWED_FORMATS = new Set(['4K', '1440p', '1080p']);
    const LONG_PRESS_TIME = 500;

    let longPressTimer = null;
    let isLongPress = false;
    let isPointerDown = false;
    let selectedMenuItem = null;

    downloadBtn.addEventListener('pointerdown', (e) => {
        const qualityToggle = document.getElementById('ui_quality_toggle');
        if (uiAudioMode.checked || qualityToggle.checked) return;

        isPointerDown = true;
        isLongPress = false;
        selectedMenuItem = null;

        longPressTimer = setTimeout(() => {
            if (isPointerDown) {
                isLongPress = true;
                downloadBtn.classList.add('is-pressed');
                showQualityMenu();
            }
        }, LONG_PRESS_TIME);
    });

    document.addEventListener('pointermove', (e) => {
        if (!isLongPress || !qualityPopup.classList.contains('is-visible')) return;

        const menuItems = qualityPopup.querySelectorAll('.quality-popup-item');
        let hoveredItem = null;

        for (const item of menuItems) {
            const rect = item.getBoundingClientRect();
            if (e.clientX >= rect.left && e.clientX <= rect.right &&
                e.clientY >= rect.top && e.clientY <= rect.bottom) {
                hoveredItem = item;
                break;
            }
        }

        menuItems.forEach(item => {
            item.classList.toggle('is-selected', item === hoveredItem);
        });

        selectedMenuItem = hoveredItem;
    });

    document.addEventListener('pointerup', (e) => {
        clearTimeout(longPressTimer);
        isPointerDown = false;
        downloadBtn.classList.remove('is-pressed');

        if (isLongPress && selectedMenuItem) {
            const format = selectedMenuItem.getAttribute('data-format');

            if (format === 'translate') {
                // Перевод озвучки Яндексом: качество остаётся дефолтным (top),
                // ставим только флаг translate. Бэк игнорирует его для аудио.
                if (translateField) translateField.value = '1';
                hideQualityMenu();
                downloadForm.requestSubmit();
            } else if (ALLOWED_FORMATS.has(format)) {
                formatField.value = format;
                hideQualityMenu();
                downloadForm.requestSubmit();
            } else {
                console.warn('Invalid format selected:', format);
                hideQualityMenu();
            }
        } else {
            hideQualityMenu();
        }

        isLongPress = false;
        selectedMenuItem = null;
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && qualityPopup.classList.contains('is-visible')) {
            hideQualityMenu();
            isLongPress = false;
            selectedMenuItem = null;
        }
    });

    function showQualityMenu() {
        if (controlsRow) controlsRow.classList.add('is-controls-hidden');

        qualityPopup.classList.add('is-visible');

        if (navigator.vibrate) {
            navigator.vibrate(50);
        }
    }

    function hideQualityMenu() {
        if (controlsRow) controlsRow.classList.remove('is-controls-hidden');

        qualityPopup.classList.remove('is-visible');
        const items = qualityPopup.querySelectorAll('.quality-popup-item');
        items.forEach(item => item.classList.remove('is-selected'));
    }
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
    initLongPressQualitySelector();
    initWinterSnow();
    checkStaticVersionBanner();
});

// Баннер "Обновили сайт" - сравниваем STATIC_VERSION (хэш статики, part.header.php) с localStorage. Первый визит - просто запоминаем, без баннера.
function checkStaticVersionBanner() {
    if (!STATIC_VERSION) return;
    const KEY = 'yt_static_version';
    let stored;
    try {
        stored = localStorage.getItem(KEY);
    } catch (e) {
        return;
    }

    if (stored !== null && stored === STATIC_VERSION) return;

    try { localStorage.setItem(KEY, STATIC_VERSION); } catch (e) {}
    if (stored === null) return; // первый визит - не с чем сравнивать

    const banner = document.createElement('div');
    banner.className = 'alert alert-info site-update-banner';
    banner.innerHTML = `
        <span>Сайт обновили с прошлого визита ✨</span>
        <button type="button" class="site-update-banner-close" aria-label="Закрыть">&times;</button>`;
    document.body.insertBefore(banner, document.body.firstChild);
    banner.querySelector('.site-update-banner-close').addEventListener('click', () => banner.remove());
}

// Снег у маскота только в зимнем режиме (.winter, $isWinterMascot). Иней на поле ввода - чистый CSS, JS не нужен.
// Якорь - .snej-eye-wrap, не #snej: у #snej на мобильном своя узкая фикс. ширина, не совпадающая с картинкой птицы; eye-wrap облегает картинку плотно.
function initWinterSnow() {
    if (!document.body.classList.contains('winter')) return;
    const eyeWrap = document.querySelector('.snej-eye-wrap');
    if (!eyeWrap) return;

    const container = document.createElement('div');
    container.className = 'winter-snow';
    // Видимая птица (альфа-канал snej.webp) занимает ~29-71% ширины .snej-eye-wrap, не всю коробку - иначе снег падал бы мимо маскота.
    for (let i = 0; i < 14; i++) {
        const flake = document.createElement('span');
        flake.className = 'winter-snowflake';
        flake.textContent = '❄';
        flake.style.left = (15 + Math.random() * 70) + '%';
        flake.style.fontSize = (7 + Math.random() * 6) + 'px';
        // Три независимые анимации на снежинку (см. CSS): падение, базовое покачивание
        // (или его запасной вариант) и отдельный, более редкий цикл порыва ветра -
        // у каждой снежинки свои длительность/задержка на все три, поэтому ничего не
        // синхронизировано ни между снежинками, ни между падением и ветром одной снежинки.
        const fallDuration = 10 + Math.random() * 6;
        const swayDuration = 3 + Math.random() * 3;
        const gustDuration = 7 + Math.random() * 9;
        flake.style.animationDuration = fallDuration + 's, ' + swayDuration + 's, ' + gustDuration + 's';
        flake.style.animationDelay = (Math.random() * -14) + 's, ' + (Math.random() * -swayDuration) + 's, ' + (Math.random() * -gustDuration) + 's';
        // Амплитуда базового покачивания и силы/направления порыва - тоже случайные и
        // свои у каждой снежинки: часть почти не реагирует на ветер, часть иногда заметно
        // и плавно сносит в сторону с доворотом (см. @supports (sin()) в CSS).
        flake.style.setProperty('--sway-amp', (3 + Math.random() * 4).toFixed(1) + 'px');
        flake.style.setProperty('--sway-rot', (6 + Math.random() * 8).toFixed(1) + 'deg');
        flake.style.setProperty('--gust-amp', (14 + Math.random() * 30).toFixed(1) + 'px');
        flake.style.setProperty('--gust-rot', (25 + Math.random() * 60).toFixed(1) + 'deg');
        flake.style.setProperty('--gust-dir', Math.random() < 0.5 ? '-1' : '1');
        container.appendChild(flake);
    }
    eyeWrap.appendChild(container);
}

// Предупреждения до сабмита (чисто клиентские, не блокируют бэкенд): 1) "это плейлист" - list= в query; 2) "уже качали" - ссылка в knownDownloadedUrls.
document.addEventListener('DOMContentLoaded', function () {
    const downloadForm = document.getElementById('download-form');
    const urlField = document.getElementById('url');
    if (!downloadForm || !urlField) return;

    downloadForm.addEventListener('submit', function (e) {
        const urls = urlField.value.split('||').map(u => u.trim()).filter(Boolean);

        const hasPlaylist = urls.some(u => /[?&]list=/i.test(u));
        if (hasPlaylist && !confirm('Ссылка похожа на плейлист - скачаются все видео из него. Продолжить?')) {
            e.preventDefault();
            return;
        }

        const isDuplicate = urls.some(u => knownDownloadedUrls.has(u.toLowerCase()));
        if (isDuplicate && !confirm('Эта ссылка уже скачивалась (или качается сейчас). Скачать ещё раз?')) {
            e.preventDefault();
        }
    });
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

// Зимний "редкий" вариант - добавляется в пул вариантов лазера, только когда активен
// зимний режим (.winter на body). Вне зимы SNEJ_RARE_VARIANTS и вероятности не трогаются -
// чистое дополнение, а не замена (см. fireSnejLaser()).
const SNEJ_WINTER_RARE_VARIANT = 'snej-laser-rare-snow';
const SNEJ_SNOW_BURST_COUNT = 10;

function initSnejEasterEgg() {
    let clickCount = 0;
    let resetTimer = null;
    const snejDiv = document.querySelector('#snej');
    const snejClick = document.querySelector('#snej-click');
    const snejInput = snejDiv ? snejDiv.querySelector('input[type="image"]') : null;
    const snejWrap = snejDiv ? snejDiv.querySelector('.snej-eye-wrap') : null;
    const snejGlow = snejDiv ? snejDiv.querySelector('.snej-eye-glow') : null;
    // Кликабельная зона - в отдельном невидимом #snej-click (стоит выше поля ввода),
    // а не в видимом #snej (стоит позади него) - см. комментарий в part.main.php.
    const snejHitArea = snejClick ? snejClick.querySelector('.snej-hit-area') : null;

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

    // span с tabindex, не <button> - Space скроллит страницу по умолчанию, preventDefault гасит. Focus-outline снят в CSS.
    snejHitArea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            snejHitArea.click();
        }
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
    const isWinter = document.body.classList.contains('winter');
    const variantPool = isWinter ? SNEJ_RARE_VARIANTS.concat(SNEJ_WINTER_RARE_VARIANT) : SNEJ_RARE_VARIANTS;
    const variant = isRare
        ? variantPool[Math.floor(Math.random() * variantPool.length)]
        : null;

    if (variant) snejDiv.classList.add(variant);
    snejDiv.classList.add('snej-laser-active');

    // Зимний вариант - вместо луча снежинки/конфетти-взрыв из глаза.
    const snowBurst = variant === SNEJ_WINTER_RARE_VARIANT ? spawnSnejSnowBurst(snejDiv) : null;

    setTimeout(() => {
        snejDiv.classList.remove('snej-laser-active');
        if (variant) snejDiv.classList.remove(variant);
        if (snowBurst) snowBurst.remove();
    }, 950);
}

// Взрыв снежинками вместо луча - зимний редкий вариант (SNEJ_WINTER_RARE_VARIANT). Та же
// техника, что и в initWinterSnow(): JS создаёт span'ы с ❄ и раздаёт им случайные CSS-переменные
// направления/дистанции/вращения, разлёт/затухание считает общий @keyframes
// (snej-snow-burst-fly в baskerstyle.min.css). Контейнер живёт ровно один "выстрел" -
// создаётся тут, удаляется в fireSnejLaser() теми же 950мс, что и .snej-laser-active.
function spawnSnejSnowBurst(snejDiv) {
    const eyeWrap = snejDiv.querySelector('.snej-eye-wrap');
    if (!eyeWrap) return null;

    const container = document.createElement('div');
    container.className = 'snej-snow-burst';
    for (let i = 0; i < SNEJ_SNOW_BURST_COUNT; i++) {
        const flake = document.createElement('span');
        flake.className = 'snej-snow-particle';
        flake.textContent = '❄';
        const angle = (360 / SNEJ_SNOW_BURST_COUNT) * i + (Math.random() * 20 - 10);
        flake.style.setProperty('--angle', angle + 'deg');
        flake.style.setProperty('--dist', (34 + Math.random() * 22) + 'px');
        flake.style.setProperty('--rot', (Math.random() * 360) + 'deg');
        flake.style.fontSize = (8 + Math.random() * 5) + 'px';
        flake.style.animationDelay = (Math.random() * 60) + 'ms';
        container.appendChild(flake);
    }
    eyeWrap.appendChild(container);
    return container;
}