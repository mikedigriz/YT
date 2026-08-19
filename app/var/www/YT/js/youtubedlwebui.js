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

// Мусор из "Поделиться": трекинг накручивается на ссылку и мешает сравнивать
// её с уже качающимися и уже скачанными файлами. utm_* режем везде (стандарт,
// смысла не несёт), остальное - только у YouTube, где эти имена наши.
const TRACKING_PARAMS = ['si', 'pp', 'feature', 'ab_channel', 'gclid', 'fbclid'];

function isYoutubeHost(hostname) {
    const h = hostname.replace(/^www\./i, '').toLowerCase();
    return h === 'youtube.com' || h === 'm.youtube.com' || h === 'music.youtube.com' ||
        h === 'youtu.be' || h.endsWith('.youtube.com');
}

// Приводит ссылку к каноническому виду: youtu.be/ID и /shorts/ID разворачиваются
// в watch?v=ID, трекинг отбрасывается. list= и t= сохраняются - на них завязаны
// выбор "плейлист или ролик" и таймкод. Непонятную строку возвращает как есть.
function normalizeMediaUrl(raw) {
    const trimmed = String(raw ?? '').trim();
    if (!/^https?:\/\//i.test(trimmed)) return trimmed;

    let url;
    try {
        url = new URL(trimmed);
    } catch (e) {
        return trimmed;
    }

    const youtube = isYoutubeHost(url.hostname);

    [...url.searchParams.keys()].forEach(key => {
        const lower = key.toLowerCase();
        if (lower.startsWith('utm_')) {
            url.searchParams.delete(key);
        } else if (youtube && TRACKING_PARAMS.includes(lower)) {
            url.searchParams.delete(key);
        }
    });

    if (youtube) {
        let videoId = null;
        if (url.hostname.replace(/^www\./i, '').toLowerCase() === 'youtu.be') {
            const id = url.pathname.replace(/^\/+/, '').split('/')[0];
            if (/^[\w-]{6,}$/.test(id)) videoId = id;
        } else {
            const m = url.pathname.match(/^\/(?:shorts|live|embed|v)\/([\w-]{6,})/i);
            if (m) videoId = m[1];
        }
        if (videoId) {
            const kept = new URLSearchParams();
            ['list', 'index', 't', 'start'].forEach(p => {
                const v = url.searchParams.get(p);
                if (v !== null) kept.set(p, v);
            });
            kept.set('v', videoId);
            // v первым - привычный вид ссылки
            const ordered = new URLSearchParams();
            ordered.set('v', videoId);
            kept.forEach((v, k) => { if (k !== 'v') ordered.set(k, v); });
            return 'https://www.youtube.com/watch?' + ordered.toString();
        }
    }

    return url.toString();
}

// Поле хранит ссылки через "||" (разделитель Downloader::addOneDownload()).
function normalizeUrlField(value) {
    return String(value ?? '').split('||')
        .flatMap(chunk => extractUrlsFromText(chunk))
        .map(u => normalizeMediaUrl(u.trim()))
        .filter(Boolean)
        .join('||');
}

// Ссылка без схемы ("youtube.com/watch?v=x") валидна для человека, но не для
// FILTER_VALIDATE_URL на бэкенде - дописываем https, раз хост распознаётся.
// Возвращает пригодную ссылку либо null, если строка на адрес не похожа.
function coerceUrl(raw) {
    const trimmed = String(raw ?? '').trim();
    if (!trimmed) return null;

    const candidate = /^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed) ? trimmed : 'https://' + trimmed;
    let url;
    try {
        url = new URL(candidate);
    } catch (e) {
        return null;
    }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
    // Хост без точки - это "localhost" или опечатка вроде "htps://youtube.com"
    if (!/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(url.hostname) && !/^\[?[0-9a-f:.]+\]?$/i.test(url.hostname)) return null;
    return url.toString();
}

// Зеркало Downloader::extractUrls(). Ссылку копируют вместе с куском переписки -
// вытаскиваем её, прозу отбрасываем. Но только когда это однозначно проза: ссылок
// несколько либо перед первой есть текст. Одинокая ссылка в начале строки с хвостом
// после пробела остаётся целой - там неотличим незакодированный пробел ВНУТРИ
// ссылки, и обрезка увела бы загрузку не туда.
function extractUrlsFromText(text) {
    const raw = String(text ?? '').trim();
    if (!raw) return [];

    const matches = [...raw.matchAll(/https?:\/\/\S+/gi)];
    if (!matches.length) return [raw];

    const junkBefore = raw.slice(0, matches[0].index).trim() !== '';
    if (matches.length === 1 && !junkBefore) return [raw];

    return matches
        .map(m => m[0].replace(/[.,;:!?)\]»"']+$/, ''))
        .filter(Boolean);
}

// Зеркало Downloader::startTimeSeconds(): "t=1234", "t=1h2m3s", "start=90".
function urlStartSeconds(raw) {
    let url;
    try {
        url = new URL(String(raw ?? '').trim());
    } catch (e) {
        return null;
    }
    for (const key of ['t', 'start']) {
        const value = url.searchParams.get(key);
        if (!value) continue;
        let seconds = null;
        if (/^\d+$/.test(value)) {
            seconds = parseInt(value, 10);
        } else {
            const m = value.match(/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/i);
            if (m && (m[1] || m[2] || m[3])) {
                seconds = (parseInt(m[1] || 0, 10) * 3600) + (parseInt(m[2] || 0, 10) * 60) + parseInt(m[3] || 0, 10);
            }
        }
        if (seconds !== null && seconds > 0) return seconds;
    }
    return null;
}

function formatClock(totalSeconds) {
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    const pad = n => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

// Приметы того, что за ссылкой не один ролик, а список: плейлист, альбом, канал,
// страница автора, выдача поиска. Намеренно обобщённые и не привязанные к сайту -
// экстракторов у yt-dlp почти две тысячи, формы ссылок у них несовместимы
// (/playlist?list=, /sets/, /album/, /plst/, /-/), и любой поимённый список
// устареет с очередным обновлением. Поэтому здесь только подсказка, а
// окончательный ответ даёт сам yt-dlp полем _type (см. PlaylistProbe::parse).
// Ошибиться в сторону лишнего вопроса дешевле: вопрос человек закроет, сорок
// лишних роликов на диске - нет.
const COLLECTION_QUERY_PARAMS = [
    'list', 'playlist', 'album', 'set', 'series', 'season', 'channel', 'wl', 'start_radio'
];

const COLLECTION_PATH_SEGMENTS = new Set([
    'playlist', 'playlists', 'album', 'albums', 'set', 'sets', 'series', 'season',
    'collection', 'collections', 'channel', 'channels', 'user', 'users', 'feed',
    'results', 'search', 'mix', 'chart', 'charts', 'podcast', 'show', 'shows',
    'episodes', 'videos', 'streams', 'shorts', 'tracks', 'favorites', 'bookmarks',
    'subscriptions', 'library', 'plst',
    // Кириллические варианты попадаются на российских сайтах
    'плейлист', 'подборка', 'альбом'
]);

// Сегменты, которые считаются приметой списка ТОЛЬКО в хвосте пути. У сайтов со
// стоковым видео такие слова стоят папкой посреди адреса конкретного файла
// (/shutterstock/videos/4074757469/preview/...webm), и списком там не пахнет.
// Настоящие вкладки-списки, наоборот, путь заканчивают: /@user/videos,
// /channel/123/streams, /music/tracks.
const COLLECTION_TAIL_ONLY_SEGMENTS = new Set([
    'videos', 'streams', 'shorts', 'tracks', 'episodes', 'feed',
    'favorites', 'bookmarks', 'subscriptions', 'library'
]);

// Прямая ссылка на медиафайл списком быть не может по определению - расширение в
// конце пути перевешивает любые "папочные" приметы выше.
const MEDIA_FILE_EXTENSIONS = new Set([
    'mp4', 'webm', 'mkv', 'mov', 'avi', 'm4v', 'ts', 'flv', 'm3u8', 'mpd',
    'mp3', 'm4a', 'aac', 'opus', 'ogg', 'oga', 'flac', 'wav', 'wma'
]);

function pathIsMediaFile(path) {
    const last = path.split('/').pop() || '';
    const dot = last.lastIndexOf('.');
    if (dot <= 0) return false;
    return MEDIA_FILE_EXTENSIONS.has(last.slice(dot + 1).toLowerCase());
}

function looksLikeCollection(raw) {
    let url;
    try {
        url = new URL(String(raw ?? '').trim());
    } catch (e) {
        return false;
    }

    let path = url.pathname.replace(/\/+$/, '');

    // Проверка файла идёт ДО параметров запроса: у прямых ссылок на CDN сплошь и
    // рядом висит подписной хвост, среди которого попадаются и наши приметы.
    if (pathIsMediaFile(path)) return false;

    for (const param of COLLECTION_QUERY_PARAMS) {
        if (url.searchParams.has(param)) return true;
    }

    if (path === '' && !url.search) return true;

    // pathname приходит percent-encoded, а кириллические сегменты сверяем текстом.
    // Битую последовательность decodeURIComponent роняет исключением - не повод
    // терять весь разбор поля.
    try { path = decodeURIComponent(path); } catch (e) {}

    // @handle - страница автора. Не обязательно первым сегментом: у VK это
    // /video/@user. Но /@handle/video/123 у части сайтов уже конкретный ролик,
    // поэтому handle должен быть последним либо перед известной вкладкой.
    if (/\/@[^/]+(?:\/(?:videos|streams|shorts|playlists|featured|releases)?)?$/i.test(path)) {
        return true;
    }
    // VK-шная форма списка: /-/, /-12345
    if (/^\/-/.test(path)) return true;
    // Раздел видео сообщества VK: /video-123456. Подчёркивание отличает его от
    // конкретного ролика - /video-123456_456239017.
    if (/^\/video-\d+$/.test(path)) return true;
    // Подборка на OK: /video/c1234567 против одиночного /video/9876543210.
    if (/\/video\/c\d+/i.test(path)) return true;
    // Ютубовское /c/Name - только первым сегментом. В общий список "c" класть
    // нельзя: под него попадал любой путь с однобуквенным сегментом (/a/b/c/d).
    if (/^\/c\//i.test(path)) return true;

    const segments = path.split('/').filter(Boolean).map(s => s.toLowerCase());
    return segments.some((seg, i) => {
        if (!COLLECTION_PATH_SEGMENTS.has(seg)) return false;
        // "Хвостовые" слова засчитываем только у конца пути: после них допустим
        // разве что номер страницы (/@user/videos/2), но не адрес файла.
        // Строго последним сегментом: продолжение пути после такого слова - это
        // адрес конкретной записи, а не страница списка (twitch.tv/videos/123456789).
        if (COLLECTION_TAIL_ONLY_SEGMENTS.has(seg)) {
            return i === segments.length - 1;
        }
        return true;
    });
}

// Разбирает поле целиком. Возвращает {urls, bad} - что уйдёт на сервер и что
// человек написал криво. Пустое поле даёт пустой urls без ошибок.
function validateUrlField(value) {
    const urls = [];
    const bad = [];
    String(value ?? '').split('||')
        .flatMap(chunk => extractUrlsFromText(chunk))
        .map(s => s.trim()).filter(Boolean).forEach(part => {
        const coerced = coerceUrl(part);
        if (coerced === null) {
            bad.push(part);
        } else {
            urls.push(normalizeMediaUrl(coerced));
        }
    });
    return { urls, bad };
}

// Единый язык вибрации, чтобы отклик читался не глядя на экран:
// tick - ссылка принята, done - готово, error - не вышло.
// Раньше длительности стояли по месту и означали каждый раз своё.
const HAPTICS = {
    tick: 25,
    done: 120,
    error: [80, 40, 80],
};

// Подмигивание снегиря. Осталось ровно одно применение - магия буфера
// (распознали ссылку и подставили её сама). На события загрузки снегирь больше
// не реагирует: подмиг, наклон и покачивание убраны, состояние видно в таблице.
function snejWink() {
    const snejDiv = document.getElementById('snej');
    if (!snejDiv) return;
    snejDiv.classList.remove('snej-wink');
    void snejDiv.offsetWidth;
    snejDiv.classList.add('snej-wink');
    setTimeout(() => snejDiv.classList.remove('snej-wink'), 500);
}

// Chrome блокирует вибрацию, пока по странице не было касания или нажатия
// клавиши, и на каждый вызов пишет в консоль Intervention-предупреждение.
// Загрузка вполне может закончиться раньше первого касания (открыли вкладку и
// смотрят), поэтому просто не зовём, пока жеста не было.
let userHasInteracted = false;
['pointerdown', 'keydown', 'touchstart'].forEach(evt => {
    window.addEventListener(evt, () => { userHasInteracted = true; }, { once: true, passive: true });
});

function haptic(kind) {
    const pattern = HAPTICS[kind];
    if (!pattern || !navigator.vibrate || !userHasInteracted) return;
    // Разрешения (в отличие от уведомлений) вибрация не требует, но на десктопе
    // её попросту нет - вызов там безвреден.
    navigator.vibrate(pattern);
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

// Мгновенный отклик на действие: строка гаснет сразу по нажатию, не дожидаясь
// ответа сервера и следующего опроса (до полутора секунд - момент, когда человек
// жмёт кнопку второй раз). Ошибка возвращает строку на место; при успехе строка
// и так исчезнет на ближайшей перерисовке таблицы.
function markRowPending(el) {
    const row = el && el.closest ? el.closest('tr') : null;
    if (row) row.classList.add('row-pending');
    return row;
}

function unmarkRowPending(row) {
    if (row) row.classList.remove('row-pending');
}

function confirmAction(action, value, extraFields = {}, sourceEl = null) {
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

    // Массовые действия ("Стоп ВСЕ", "Удалить Все") гасить построчно нечего -
    // там кнопка живёт в подвале таблицы, а не в строке задачи.
    const pendingRow = (value === 'all' || value === 'recent' || value === 'queue')
        ? null
        : markRowPending(sourceEl);

    submitActionFetch({ [action]: value, ...extraFields })
        .then(data => {
            // Сервер отказал - строка возвращается на место, иначе человек
            // остался бы с погашенной строкой и без объяснения
            if (data && data.errors && data.errors.length) unmarkRowPending(pendingRow);
        })
        .catch(() => unmarkRowPending(pendingRow));
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

// Плавная подмена содержимого через View Transitions: браузер сам снимает кадр
// до и после и кроссфейдит их, поэтому переключение вкладок не дёргается, а
// перерисовка таблицы не мигает. Библиотек не нужно; где API нет - выполняется
// обычная синхронная правка. Уважает "уменьшить движение" в системе.
function withViewTransition(fn) {
    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced || typeof document.startViewTransition !== 'function') {
        fn();
        return;
    }
    // Промисы перехода надо гасить: следующий опрос приходит раз в полторы
    // секунды и прерывает незавершённый переход, а тот отклоняет свои промисы
    // с AbortError "Transition was skipped". Сама подмена содержимого при этом
    // проходит - в консоль сыпался только необработанный отказ.
    const transition = document.startViewTransition(fn);
    if (transition) {
        if (transition.finished) transition.finished.catch(() => {});
        if (transition.ready) transition.ready.catch(() => {});
        if (transition.updateCallbackDone) transition.updateCallbackDone.catch(() => {});
    }
}

// Разметка одной строки в живой узел. Через <template>: обычный createElement
// не примет <tr> вне таблицы - браузер выкинет его при разборе.
function rowNodeFromHtml(html, key) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html.trim();
    const node = tpl.content.firstElementChild;
    if (!node) return null;
    node.dataset.rowKey = key;
    node.dataset.rowHtml = html;
    return node;
}

// Сверка строк по ключу вместо замены всего tbody. Раньше таблица целиком
// пересобиралась через innerHTML на каждое изменение данных (для "Загрузок" -
// раз в полторы секунды, пока меняются проценты; для "Видео"/"Музыки" -
// ежеминутно, потому что в каждой строке живёт возраст файла). Из-за этого
// пропадало выделение текста, слетал фокус с кнопки и перезапускались
// CSS-анимации. Теперь узлы неизменившихся строк переживают обновление, и
// внутри строки становятся возможны живые элементы.
//
// Ключ - стабильный идентификатор строки (keyFn), сравнение - по самой
// разметке: генератор уже собрал её со всеми данными, поэтому отдельное
// сравнение полей не нужно.
function reconcileRows(container, desired) {
    const existing = new Map();
    for (const row of Array.from(container.children)) {
        const key = row.dataset.rowKey;
        if (key) existing.set(key, row);
    }

    let cursor = container.firstElementChild;
    const seen = new Set();

    for (const item of desired) {
        seen.add(item.key);
        const found = existing.get(item.key);

        if (found && found.dataset.rowHtml === item.html) {
            // Строка не изменилась - оставляем ТОТ ЖЕ узел. Двигаем, только
            // если порядок поехал: лишний перенос сбрасывает фокус.
            if (found === cursor) {
                cursor = cursor.nextElementSibling;
            } else {
                container.insertBefore(found, cursor);
            }
            continue;
        }

        const fresh = rowNodeFromHtml(item.html, item.key);
        if (!fresh) continue;

        if (found) {
            container.replaceChild(fresh, found);
            existing.set(item.key, fresh);
            if (found === cursor) cursor = fresh.nextElementSibling;
        } else {
            container.insertBefore(fresh, cursor);
        }
    }

    // Всё, чего в новых данных нет. Идём по живым детям, а не по карте ключей:
    // строка-заглушка ("Получаю видео, обажди...") приходит из шаблона с
    // сервера и ключа не имеет, поэтому в карту не попадала и оставалась в
    // таблице навсегда - первый же опрос дорисовывал данные ПОД ней.
    for (const row of Array.from(container.children)) {
        const key = row.dataset.rowKey;
        if (!key || !seen.has(key)) row.remove();
    }
}

function renderTable(container, items, cols, emptyMsg, rowHtmlGenerator, footerHtml = "", keyFn = null) {
    // #dlqueue отсутствует в DOM при disableQueue=true - без этой проверки TypeError тут обрывал бы весь остаток loadList().
    if (!container) return;

    const hash = computeDataHash(items) + ':' + footerHtml;

    if (container.dataset.lastHash === hash) {
        return;
    }

    const desired = [];
    if (!items || items.length === 0) {
        desired.push({ key: '__empty__', html: `<tr><td colspan="${cols}">${emptyMsg}</td></tr>` });
    } else {
        items.forEach((item, idx, arr) => {
            const html = rowHtmlGenerator(item, idx, arr);
            // Без keyFn ключом становится порядковый номер: поведение как
            // раньше (строка на месте N переписывается), но без сноса таблицы.
            const key = keyFn ? String(keyFn(item)) : 'idx:' + idx;
            desired.push({ key, html });
        });
        if (footerHtml) desired.push({ key: '__footer__', html: `<tr>${footerHtml}</tr>` });
    }

    // Плавно показываем только заметные глазу перестроения - появление и
    // исчезновение строк. Смена процентов в существующей строке идёт как
    // раньше: кроссфейд полтора раза в секунду выглядел бы как мигание.
    const structuralChange = container.children.length !== desired.length;

    // Перестроение бывает отложенным: startViewTransition зовёт колбэк не сразу,
    // а опрос успевает запланировать следующее. Метку версии ставим СИНХРОННО,
    // чтобы устаревший колбэк не разложил строки по неактуальным данным - так
    // строка с кнопкой "Очистить всё" однажды оказалась выше файлов.
    const generation = (Number(container.dataset.renderGen) || 0) + 1;
    container.dataset.renderGen = String(generation);
    container.dataset.lastHash = hash;

    const apply = () => {
        if (Number(container.dataset.renderGen) !== generation) return;
        reconcileRows(container, desired);
    };

    if (structuralChange) {
        withViewTransition(apply);
    } else {
        apply();
    }
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

    // Скопировать ссылку: QR хорош для телефона, но кинуть файл в мессенджер
    // на том же устройстве до сих пор можно было только через контекстное меню
    // браузера. Ссылка относительная - абсолютной её делает тот же
    // buildAbsoluteFileUrl(), что и для QR.
    const copyIcon = `<svg class="copy-btn-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg>`;
    const copyButton = file.downloadurl
        ? `<button type="button" class="btn btn-default btn-xs copy-btn" title="Скопировать ссылку на файл" data-copy-url="${escapeHtml(file.downloadurl)}">${copyIcon}</button>`
        : '';

    const pinIcon = `<svg class="pin-btn-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M16 3l5 5-1.9 1.9-2.5-.6-3.6 3.6.9 3.7-1.9 1.9-3.5-3.5-4.8 4.8-1.4-1.4 4.8-4.8-3.5-3.5 1.9-1.9 3.7.9 3.6-3.6-.6-2.5z"/></svg>`;
    const pinned = !!file.pinned;
    const pinButton = file.name
        ? `<button type="button" class="btn btn-default btn-xs pin-btn${pinned ? ' pin-btn-active' : ''}" title="${pinned ? 'Открепить' : 'Закрепить - не удалится по времени и при «Очистить всё»'}" data-pin-name="${file.name}" data-pin-type="${file.kind === 'audio' ? 'm' : 'v'}" data-pin-pinned="${pinned ? '1' : '0'}">${pinIcon}</button>`
        : '';

    if (!playButton && !qrButton && !copyButton && !pinButton && !file.deleteurl) return '';
    return `<div class="btn-group btn-group-file">${playButton}${qrButton}${copyButton}${pinButton}${file.deleteurl}</div>`;
}

// Ключ файла для отслеживания "новых" строк между опросами - downloadurl
// стабилен и уникален per-файл, имя внутри HTML-ссылки как запасной вариант.
function getFileKey(file) {
    return file.downloadurl || file.file;
}

function renderFileRow(file, isNew) {
    const actions = buildFileActions(file);
    // Всплытие вешается на всю строку, волна - ТОЛЬКО на само имя файла.
    // Волна красит текст градиентом через background-clip, а прозрачная заливка
    // букв наследуется потомками: на обёртке она делала невидимым и бейдж
    // времени жизни, у которого своего градиента нет.
    const enterClass = isNew ? ' row-enter-cell' : '';
    const waveClass = isNew ? ' row-name-wave-cell' : '';

    if (typeof showFileLifetime !== 'undefined' && !showFileLifetime) {
        return `<tr><td><span class="file-name-plain${enterClass}${waveClass}">${file.file}</span></td><td>${escapeHtml(file.size)}</td><td>${actions}</td></tr>`;
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
                    <span class="file-name${waveClass}">${file.file}</span>
                </div>
            </td>
            <td>${escapeHtml(file.size)}</td>
            <td>${actions}</td>
        </tr>`;
}

// === Массовый выбор и скачивание файлов (Видео/Музыка) ===
// Вход - только долгим нажатием (никаких чекбоксов, см. ТЗ). Выбор живёт в JS
// Set вне DOM/HTML строки: renderFileRow() не знает о выборе, иначе
// reconcileRows() считал бы неизменившиеся файлы "изменившимися" каждый опрос
// (см. dataset.rowHtml сравнение в reconcileRows) и убивал бы узлы/анимации.
// Синхронизация с перерисованной таблицей - отдельным проходом reapplySelectionClasses()
// сразу после каждого renderTable() в loadList().
const LONG_PRESS_SELECT_TIME = 500;
const LONG_PRESS_MOVE_TOLERANCE = 10;
const BULK_CONFIRM_COUNT_THRESHOLD = 10;
const BULK_CONFIRM_BYTES_THRESHOLD = 500 * 1024 * 1024;
// Пауза между запусками файлов. Держим её маленькой намеренно: право качать
// без спроса браузер даёт на короткое окно после нажатия (в Chrome - порядка
// 5 секунд), и чем дольше тянется очередь, тем вероятнее, что хвост упрётся в
// запрет. 400мс - видимая глазом последовательность и ~12 файлов внутри окна.
const BULK_SEQUENTIAL_DELAY = 400;

function createFileSelectionState(tbodyId, barId, type) {
    return {
        tbodyId, barId, type,
        tbody: null, bar: null,
        active: false,
        selected: new Set(),
        lastKnownFiles: new Map(),
        queue: null
    };
}

const fileSelection = {
    video: createFileSelectionState('videofiles', 'video-select-bar', 'v'),
    music: createFileSelectionState('musicfiles', 'music-select-bar', 'm')
};

// На мобильном панель прижата к низу экрана (position: fixed) и накрыла бы
// последнюю строку таблицы - класс на body добавляет странице отступ под неё.
function syncSelectionBodyClass() {
    const anyActive = fileSelection.video.active || fileSelection.music.active;
    document.body.classList.toggle('selection-active', anyActive);
}

function enterSelectionMode(state) {
    if (state.active) return;
    state.active = true;
    if (state.bar) state.bar.hidden = false;
    if (state.tbody) state.tbody.classList.add('selection-mode');
    syncSelectionBodyClass();
    reapplySelectionClasses(state);
}

function exitSelectionMode(state) {
    stopBulkDownload(state);
    state.active = false;
    state.selected.clear();
    if (state.bar) state.bar.hidden = true;
    if (state.tbody) state.tbody.classList.remove('selection-mode');
    syncSelectionBodyClass();
    reapplySelectionClasses(state);
}

function toggleRowSelection(state, row) {
    const key = row.dataset.rowKey;
    if (!key) return;
    if (state.selected.has(key)) state.selected.delete(key);
    else state.selected.add(key);
    row.classList.toggle('row-selected', state.selected.has(key));
    row.setAttribute('aria-selected', state.selected.has(key) ? 'true' : 'false');
    updateSelectionUI(state);
}

// Единственная точка синхронизации выбора с DOM - зовётся после каждого
// renderTable() для файловых таблиц. HTML строки выбор не знает, поэтому
// подсветка/aria/tabindex расставляются здесь заново на живых узлах.
function reapplySelectionClasses(state) {
    if (!state.tbody) return;
    for (const row of Array.from(state.tbody.children)) {
        const key = row.dataset.rowKey;
        if (!key) continue;
        const selected = state.selected.has(key);
        row.classList.toggle('row-selected', selected);
        if (state.active) {
            row.setAttribute('aria-selected', selected ? 'true' : 'false');
            row.tabIndex = 0;
        } else {
            row.removeAttribute('aria-selected');
            row.removeAttribute('tabindex');
        }
    }
    updateSelectionUI(state);
    // Все файлы пропали из-под выбора (удалены/дочистились) - закрываем режим тихо.
    if (state.active && state.tbody.children.length === 0) {
        exitSelectionMode(state);
    }
}

// Ключ строки-файла отличается от служебных строк тем же tbody (у подвала
// "Очистить всё" тоже есть data-row-key, ='__footer__' - иначе он бы ловил
// long-press и попадал в счётчик выбранного как несуществующий файл).
function isSelectableFileRow(row) {
    return !!row && typeof row.dataset.rowKey === 'string' && row.dataset.rowKey.indexOf('file:') === 0;
}

function updateSelectionUI(state) {
    if (!state.bar) return;
    const count = state.selected.size;
    const running = !!state.queue;
    const countEl = state.bar.querySelector('.selection-toolbar-count');
    if (countEl) {
        countEl.textContent = running
            ? `Запущено ${state.queue.started} из ${state.queue.items.length}`
            : 'Выбрано: ' + count;
    }
    const dlBtn = state.bar.querySelector('.selection-toolbar-download');
    const allBtn = state.bar.querySelector('.selection-toolbar-all');
    if (dlBtn) {
        // В ручном режиме (телефон) кнопка остаётся живой и выпускает следующий
        // файл - каждому нужен свой жест пользователя, см. runBulkDownload().
        const manual = running && state.queue.manual;
        dlBtn.disabled = manual ? false : (running || count === 0);
        dlBtn.textContent = manual
            ? `Ещё ${state.queue.items.length - state.queue.started}`
            : 'Скачать';
        // Пульсация подсказывает, что очередь ждёт следующего нажатия -
        // без неё кнопка "Ещё 2" выглядит как обычный итог, а не как призыв.
        dlBtn.classList.toggle('selection-btn-waiting', manual);
    }
    if (allBtn) {
        allBtn.disabled = running;
        const total = state.lastKnownFiles.size;
        const allSelected = total > 0 && count >= total;
        allBtn.textContent = allSelected ? 'Снять всё' : 'Выбрать всё';
    }
}

function initSelectionForTable(state) {
    const tbody = document.getElementById(state.tbodyId);
    const bar = document.getElementById(state.barId);
    if (!tbody || !bar) return;
    state.tbody = tbody;
    state.bar = bar;

    let pressTimer = null;
    let startX = 0, startY = 0;
    // Долгое нажатие уже переключает строку в колбэке таймера; отпускание
    // пальца/кнопки после этого рождает обычный click по той же строке -
    // без подавления он тут же переключал бы выбор обратно (нужно было жать
    // дважды, чтобы строка осталась выбранной).
    let suppressNextClick = false;

    tbody.addEventListener('pointerdown', (e) => {
        const row = e.target.closest('tr[data-row-key]');
        // Жест, начатый на кнопке действия строки (play/qr/copy/pin/удалить),
        // не должен запускать выбор - у кнопок своё поведение.
        if (!isSelectableFileRow(row) || e.target.closest('button, a')) return;
        startX = e.clientX; startY = e.clientY;
        clearTimeout(pressTimer);
        pressTimer = setTimeout(() => {
            pressTimer = null;
            suppressNextClick = true;
            enterSelectionMode(state);
            toggleRowSelection(state, row);
            haptic('tick');
        }, LONG_PRESS_SELECT_TIME);
    });

    tbody.addEventListener('pointermove', (e) => {
        if (!pressTimer) return;
        if (Math.abs(e.clientX - startX) > LONG_PRESS_MOVE_TOLERANCE || Math.abs(e.clientY - startY) > LONG_PRESS_MOVE_TOLERANCE) {
            clearTimeout(pressTimer);
            pressTimer = null;
        }
    });

    const clearPress = () => { clearTimeout(pressTimer); pressTimer = null; };
    tbody.addEventListener('pointerup', clearPress);
    tbody.addEventListener('pointercancel', clearPress);
    tbody.addEventListener('pointerleave', clearPress);

    // Короткий тап: вне режима выбора - обычное поведение строки (play/qr/...,
    // те слушатели навешаны отдельно и здесь не участвуют). В режиме выбора -
    // toggle, действия строки подавляются через capture-фазу.
    tbody.addEventListener('click', (e) => {
        if (suppressNextClick) {
            suppressNextClick = false;
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        if (!state.active) return;
        const row = e.target.closest('tr[data-row-key]');
        if (!isSelectableFileRow(row)) return;
        if (e.target.closest('button, a')) {
            e.preventDefault();
            e.stopPropagation();
        }
        toggleRowSelection(state, row);
    }, true);

    tbody.addEventListener('keydown', (e) => {
        if (!state.active) return;
        if (e.key === ' ' || e.key === 'Enter') {
            const row = e.target.closest('tr[data-row-key]');
            if (isSelectableFileRow(row)) { e.preventDefault(); toggleRowSelection(state, row); }
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
            e.preventDefault();
            state.lastKnownFiles.forEach((file, key) => state.selected.add(key));
            reapplySelectionClasses(state);
        }
    });

    bar.querySelector('.selection-toolbar-cancel').addEventListener('click', () => exitSelectionMode(state));
    // Одна кнопка на выбрать/снять всё - вместо пары "Выбрать всё"/"Сброс" с
    // дублирующимся смыслом (снять выбор можно было и тем, и другим).
    bar.querySelector('.selection-toolbar-all').addEventListener('click', () => {
        const allSelected = state.lastKnownFiles.size > 0 && state.selected.size >= state.lastKnownFiles.size;
        if (allSelected) {
            state.selected.clear();
        } else {
            state.lastKnownFiles.forEach((file, key) => state.selected.add(key));
        }
        reapplySelectionClasses(state);
    });
    bar.querySelector('.selection-toolbar-download').addEventListener('click', () => {
        // Очередь уже идёт - значит это ручной режим и нажатие выпускает
        // следующий файл (в автоматическом кнопка на это время заблокирована).
        if (state.queue) releaseNextDownload(state);
        else startBulkDownload(state);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && state.active) exitSelectionMode(state);
    });
}

function initFileSelection() {
    initSelectionForTable(fileSelection.video);
    initSelectionForTable(fileSelection.music);
}

function formatBytesHuman(bytes) {
    const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    let value = bytes, i = 0;
    while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
    return value.toFixed(i === 0 || value >= 10 ? 0 : 1) + ' ' + units[i];
}

let bulkConfirmModalEl = null;

function ensureBulkConfirmModal() {
    if (bulkConfirmModalEl) return bulkConfirmModalEl;
    const overlay = document.createElement('div');
    overlay.className = 'qr-modal-overlay';
    overlay.innerHTML = `
        <div class="qr-modal-card" role="dialog" aria-modal="true" aria-label="Подтверждение массового скачивания">
            <button type="button" class="qr-modal-close" aria-label="Закрыть">&times;</button>
            <div class="qr-modal-title">Скачать пачкой?</div>
            <div class="qr-modal-subtitle bulk-confirm-text"></div>
            <div class="bulk-confirm-actions">
                <button type="button" class="selection-btn bulk-confirm-cancel">Отмена</button>
                <button type="button" class="selection-btn selection-btn-primary bulk-confirm-ok">Продолжить</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) hideBulkConfirmModal(); });
    overlay.querySelector('.qr-modal-close').addEventListener('click', hideBulkConfirmModal);
    overlay.querySelector('.bulk-confirm-cancel').addEventListener('click', hideBulkConfirmModal);
    bulkConfirmModalEl = overlay;
    return overlay;
}

function hideBulkConfirmModal() {
    if (bulkConfirmModalEl) bulkConfirmModalEl.classList.remove('is-visible');
}

function showBulkDownloadConfirm(count, totalBytes, onConfirm) {
    const modal = ensureBulkConfirmModal();
    modal.querySelector('.bulk-confirm-text').textContent =
        `Скачать ${count} файлов` + (totalBytes ? `, ~${formatBytesHuman(totalBytes)}` : '') +
        '. Браузер может спросить разрешение на несколько скачиваний сразу.';
    const okBtn = modal.querySelector('.bulk-confirm-ok');
    const handler = () => {
        hideBulkConfirmModal();
        okBtn.removeEventListener('click', handler);
        onConfirm();
    };
    okBtn.addEventListener('click', handler);
    modal.classList.add('is-visible');
}

function triggerFileDownload(url, name) {
    // Единственный вызывающий (releaseNextDownload) уже фильтрует мусор перед
    // сюда - но без own-guard'а пустой/невалидный url страшнее, чем "ничего не
    // скачалось": без атрибута download (он не ставится без name) <a href="">
    // с пустым или нестроковым href браузер не скачивает, а НАВИГИРУЕТ - вся
    // SPA-страница заменяется чужим документом (например, 404), и всё
    // состояние теряется. Один if здесь дешевле, чем гарантия "все вызывающие
    // всегда правы" - тем более, что вызывающих может стать больше.
    if (typeof url !== 'string' || !url) return;
    const a = document.createElement('a');
    a.href = url;
    if (name) a.download = name;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

let bulkDownloadToastEl = null;

function showBulkDownloadSummary(text) {
    if (!bulkDownloadToastEl) {
        bulkDownloadToastEl = document.createElement('div');
        bulkDownloadToastEl.className = 'bulk-download-toast';
        document.body.appendChild(bulkDownloadToastEl);
    }
    bulkDownloadToastEl.textContent = text;
    bulkDownloadToastEl.classList.add('is-visible');
    clearTimeout(bulkDownloadToastEl._hideTimer);
    bulkDownloadToastEl._hideTimer = setTimeout(() => bulkDownloadToastEl.classList.remove('is-visible'), 5000);
}

// Сенсорное устройство определяем не по строке User-Agent (её подделывают и она
// врёт на планшетах), а по способу ввода: нет курсора и грубый указатель.
function isTouchDevice() {
    return !!(window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
}

// Файлы уходят по одному. Дождаться ОКОНЧАНИЯ файла браузер не даёт: у
// <a download> нет ни события завершения, ни ошибки - как только ссылка нажата,
// файл уходит в менеджер загрузок, и связь с ним теряется. Поэтому
// "последовательно" здесь - последовательный ЗАПУСК, а не ожидание докачки.
//
// Два режима, и разница между ними - не косметика:
//
// Десктоп - по таймеру: первый файл синхронно в самом жесте, остальные через
// BULK_SEQUENTIAL_DELAY внутри окна активации.
//
// Телефон - по нажатию на файл. На втором файле Chrome спрашивает "разрешить
// скачивание нескольких файлов", и пока висит этот запрос, таймер продолжает
// тикать: третий файл уходит до выдачи разрешения и молча теряется (ровно то,
// что видно как "выбрал три, скачалось два"). Гонки не будет, если у каждого
// файла свой жест пользователя, - поэтому кнопка превращается в "Ещё N", и
// каждое нажатие выпускает ровно один файл. Медленнее, зато не теряет ничего.
function runBulkDownload(state, items, skippedCount) {
    stopBulkDownload(state);
    state.queue = { items, started: 0, skipped: skippedCount, timer: null, manual: isTouchDevice() };
    releaseNextDownload(state);
}

// Выпускает один файл очереди. На десктопе сама заводит таймер на следующий,
// на телефоне возвращает управление кнопке "Ещё N".
function releaseNextDownload(state) {
    const queue = state.queue;
    if (!queue) return;

    const item = queue.items[queue.started];
    // Второй барьер: startBulkDownload() уже фильтрует мусор при сборке items,
    // но queue живёт дольше одного тика (setTimeout между файлами) - если
    // что-то повредит элемент между запусками, не отдаём браузеру пустую ссылку.
    if (item && item.url) {
        triggerFileDownload(item.url, item.name);
    } else {
        queue.skipped = (queue.skipped || 0) + 1;
    }
    queue.started++;
    updateSelectionUI(state);

    if (queue.started >= queue.items.length) {
        state.queue = null;
        const parts = [`Запущено: ${queue.started} из ${queue.items.length}`];
        if (queue.skipped) parts.push(`пропущено: ${queue.skipped}`);
        showBulkDownloadSummary(parts.join(', '));
        haptic('done');
        // Выбор снимается: пачка ушла, держать подсветку незачем - иначе
        // непонятно, скачалось это уже или ещё нет.
        exitSelectionMode(state);
        return;
    }

    if (!queue.manual) {
        queue.timer = setTimeout(() => releaseNextDownload(state), BULK_SEQUENTIAL_DELAY);
    }
}

// Гасит очередь запусков. Уже отданные браузеру файлы не отменяет - ими
// распоряжается менеджер загрузок, у нас на них ручки нет.
function stopBulkDownload(state) {
    if (!state.queue) return;
    clearTimeout(state.queue.timer);
    state.queue = null;
}

function startBulkDownload(state) {
    if (state.queue || !state.selected.size) return;

    const items = [];
    let skipped = 0;
    let totalBytes = 0;
    state.selected.forEach((key) => {
        const file = state.lastKnownFiles.get(key);
        // Барьер против мусора в очереди: даже если выше по цепочке когда-нибудь
        // просочится файл без ссылки (пустой downloadurl, недогруженная запись
        // между опросами), triggerFileDownload() не должен получить
        // undefined - иначе <a> уйдёт с пустым href, что для юзера выглядит
        // как "ничего не скачалось" без единой строчки в логе.
        if (file && typeof file.downloadurl === 'string' && file.downloadurl && typeof file.name === 'string' && file.name) {
            items.push({ key, url: file.downloadurl, name: file.name });
            totalBytes += Number(file.size_bytes) || 0;
        } else {
            skipped++;
        }
    });

    if (!items.length) {
        showBulkDownloadSummary('Выбранные файлы недоступны.');
        return;
    }

    const proceed = () => runBulkDownload(state, items, skipped);
    // Подтверждение не ломает жест: нажатие "Продолжить" в модалке - такой же
    // жест пользователя, из него скачивания стартуют так же законно.
    if (items.length > BULK_CONFIRM_COUNT_THRESHOLD || totalBytes > BULK_CONFIRM_BYTES_THRESHOLD) {
        showBulkDownloadConfirm(items.length, totalBytes, proceed);
    } else {
        proceed();
    }
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

// === Пасхалка: скачать Качалку Качалкой ===
// Вставил адрес самого сайта - вместо загрузки открывается окно с живым сайтом
// внутри, в нём ещё одно, уходящим коридором. Заголовки этого уже разрешают
// (X-Frame-Options SAMEORIGIN и frame-ancestors 'self'), трогать их не нужно.
// Глубина ограничена жёстко: каждый уровень - настоящий запрос к своему серверу.
const RECURSION_MAX_DEPTH = 4;

function isSelfUrl(raw) {
    try {
        const url = new URL(String(raw || '').trim(), window.location.href);
        if (url.host !== window.location.host) return false;
        // Только корень сайта, не ссылка на конкретный файл из download/
        const path = url.pathname.replace(/\/+$/, '');
        return path === '' || /\/index\.php$/i.test(path);
    } catch (e) {
        return false;
    }
}

// Подпись под коридором. Берётся случайно, чтобы пасхалку было интересно
// открыть второй раз - как варианты лазера у снегиря.
const RECURSION_NOTES = [
    'Стек переполнился, из него выпал снегирь.',
    'Дальше рекурсия упёрлась в снегиря. Он не пускает.',
    'Тут кончилась память. Осталась только птица.',
    'Глубже пробовали - вернулись с одним снегирём.',
    'Рекурсия без выхода из неё. Снегирь - выход.',
];

// Коридор рисуется, а не собирается из настоящих рамок. Живые iframe требовали
// по запросу к серверу на уровень и упирались в Content-Security-Policy
// обратного прокси (frame-ancestors 'none' перебивает нашу 'self'). Рисунок
// работает везде и всегда, а выглядит так же.
function buildRecursionLevel(depth) {
    if (depth > RECURSION_MAX_DEPTH) {
        const src = (typeof MASCOT_IMG !== 'undefined' ? MASCOT_IMG : 'img/snej.webp');
        return `<div class="recursion-bottom">
            <img src="${escapeHtml(src)}" alt="" class="recursion-bird">
        </div>`;
    }
    return `<div class="recursion-level">
        <div class="recursion-chrome">
            <span class="recursion-dot"></span><span class="recursion-dot"></span><span class="recursion-dot"></span>
        </div>
        <div class="recursion-body">
            <div class="recursion-field"></div>
            <div class="recursion-button"></div>
            ${buildRecursionLevel(depth + 1)}
        </div>
    </div>`;
}

let recursionModalEl = null;

function showRecursionModal() {
    if (!recursionModalEl) {
        recursionModalEl = document.createElement('div');
        recursionModalEl.className = 'qr-modal-overlay recursion-modal-overlay';
        recursionModalEl.innerHTML = `
            <div class="qr-modal-card recursion-modal-card">
                <button type="button" class="qr-modal-close" aria-label="Закрыть">×</button>
                <div class="qr-modal-title">Качалка внутри Качалки</div>
                <div class="recursion-modal-body"></div>
                <div class="recursion-modal-note"></div>
            </div>`;
        document.body.appendChild(recursionModalEl);

        recursionModalEl.addEventListener('click', (e) => {
            if (e.target === recursionModalEl || e.target.closest('.qr-modal-close')) {
                hideRecursionModal();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') hideRecursionModal();
        });
    }

    recursionModalEl.querySelector('.recursion-modal-body').innerHTML = buildRecursionLevel(1);

    const note = recursionModalEl.querySelector('.recursion-modal-note');
    if (note) note.textContent = RECURSION_NOTES[Math.floor(Math.random() * RECURSION_NOTES.length)];

    recursionModalEl.classList.add('is-visible');
}

function hideRecursionModal() {
    if (recursionModalEl) recursionModalEl.classList.remove('is-visible');
}

// Копирование ссылки на файл. Отклик даётся на месте, самой кнопкой:
// плашек и всплывающих сообщений на странице нет намеренно.
function flashCopyResult(btn, ok) {
    btn.classList.add(ok ? 'copy-btn-done' : 'copy-btn-failed');
    btn.setAttribute('title', ok ? 'Ссылка скопирована' : 'Не вышло скопировать');
    setTimeout(() => {
        btn.classList.remove('copy-btn-done', 'copy-btn-failed');
        btn.setAttribute('title', 'Скопировать ссылку на файл');
    }, 1200);
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.copy-btn');
    if (!btn) return;
    e.preventDefault();

    const relative = btn.getAttribute('data-copy-url');
    if (!relative) return;
    const absolute = buildAbsoluteFileUrl(relative);

    // clipboard.writeText требует защищённого контекста (https или localhost).
    // Качалка часто открыта по http на локальном адресе - там остаётся запасной
    // путь через скрытое поле и execCommand.
    const fallback = () => {
        const helper = document.createElement('textarea');
        helper.value = absolute;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (err) {
            ok = false;
        }
        document.body.removeChild(helper);
        flashCopyResult(btn, ok);
        haptic(ok ? 'tick' : 'error');
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(absolute)
            .then(() => { flashCopyResult(btn, true); haptic('tick'); })
            .catch(fallback);
    } else {
        fallback();
    }
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
        confirmAction(act.getAttribute('data-action'), act.getAttribute('data-value') || '', extra, act);
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
let knownDownloadedUrls = new Map();

// Ссылка сравнивается в каноническом виде (normalizeMediaUrl), иначе один и тот же
// ролик из разных источников - youtu.be, shorts, watch?v= с трекингом - выглядит
// разными строками и совпадение не находится.
function urlCompareKey(url) {
    return normalizeMediaUrl(String(url || '').trim()).toLowerCase();
}

// Кроме факта "уже знаем такую ссылку" запоминаем, в каком она состоянии:
// сообщение "уже качается, 43%" полезнее, чем "уже скачивалась".
function collectKnownUrls(data) {
    const map = new Map();
    const buckets = [
        ['finished', data.finished],
        ['queued', data.queue],
        ['active', data.jobs],
    ];
    for (const [kind, bucket] of buckets) {
        for (const item of bucket || []) {
            const percent = /(\d{1,3}(?:\.\d+)?)%/.exec(item.status || '');
            (item.url || '').split(',').forEach(u => {
                const key = urlCompareKey(u);
                if (!key) return;
                // Активная задача важнее записи в истории - её и оставляем
                if (map.has(key) && kind === 'finished') return;
                map.set(key, { kind, percent: percent ? percent[1] : null });
            });
        }
    }
    return map;
}

// "Файл уже лежит на диске" определяется по завершённым задачам: в одной записи
// ?jobs есть и ссылка, и имя полученного файла. Раньше ID искали прямо в имени
// файла, но ID из имён убрали - имена стали человеческими.
let knownFileByUrl = new Map();

function collectDiskFiles(data) {
    const onDisk = new Set();
    for (const bucket of [data.videos, data.musics]) {
        for (const item of bucket || []) {
            if (item.name) onDisk.add(item.name);
        }
    }

    const map = new Map();
    for (const job of data.finished || []) {
        const name = (job.file || '').trim();
        if (!name || !onDisk.has(name)) continue;
        (job.url || '').split(',').forEach(u => {
            const key = urlCompareKey(u);
            if (key) map.set(key, name);
        });
    }
    return map;
}

// Состояние минутного окна прокси из последнего ?jobs - хватает, чтобы сказать
// "прокси не отвечает" ДО старта, а не через минуту ожидания в логе задачи.
let lastProxyState = null;

function usesProxy(url) {
    if (typeof DIRECT_ACCESS_DOMAINS === 'undefined') return false;
    let host;
    try {
        host = new URL(String(url || '').trim()).hostname.replace(/^www\./i, '').toLowerCase();
    } catch (e) {
        return false;
    }
    return !DIRECT_ACCESS_DOMAINS.some(d => host === d || host.endsWith('.' + d));
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
        knownFileByUrl = collectDiskFiles(data);
        lastProxyState = (data.proxy && data.proxy.enabled && !data.proxy.unset) ? (data.proxy.state || null) : null;
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

            // Вибрация дублирует звук на мобильном, если вкладка беззвучна или
            // в фоне. Рисунок общий для всего сайта, см. HAPTICS.
            if (newFailure.length) {
                playNotificationSound(false);
                haptic('error');
            } else if (newSuccess.length) {
                playNotificationSound(true);
                haptic('done');
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
            <td><div class="btn-group"><button type="button" id="killallbutton" style="width: 100px;" class="btn btn-danger btn-xs" data-action="kill" data-value="all">Стоп ВСЕ</button></div></td>`,
            job => 'job:' + job.pid);

        renderTable(nativeUI.queue, data.queue, 3, "Очередь пуста.", renderQueueRow, `
            <td></td><td></td>
            <td><div class="btn-group"><button type="button" id="clearallbutton-queue" style="width: 160px;" class="btn btn-danger btn-xs" data-action="clear" data-value="queue">Удалить Все</button></div></td>`,
            item => 'queue:' + item.pid);

        // Очередь продвигается только заходом на страницу (process_queue в index.php),
        // поэтому при непустой очереди говорим об этом прямо, а не оставляем догадываться.
        const queueHint = document.getElementById('queue-hint');
        if (queueHint) queueHint.classList.toggle('is-hidden', !(data.queue && data.queue.length));

        renderTable(nativeUI.completed, data.finished, 4, "Завершенных загрузок нет.", item => renderFinishedRow(item, data.logURL), `
            <td></td><td></td><td></td>
            <td><div class="btn-group"><button type="button" id="clearallbutton-finished" style="width: 160px;" class="btn btn-danger btn-xs" data-action="clear" data-value="recent">Удалить Все</button></div></td>`,
            item => 'done:' + item.pid);

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

        renderTable(nativeUI.videos, data.videos, 3, "Видео нет.", item => renderFileRow(item, newVideoKeys.has(getFileKey(item))), clearVideosFooter,
            item => 'file:' + getFileKey(item));
        renderTable(nativeUI.music, data.music, 3, "Музыки нет.", item => renderFileRow(item, newMusicKeys.has(getFileKey(item))), clearMusicFooter,
            item => 'file:' + getFileKey(item));

        // Выбор живёт вне HTML строки (см. комментарий у reapplySelectionClasses) -
        // синхронизируем его с только что перерисованным DOM и свежими данными файлов.
        // Ключ тот же, что уходит в data-row-key через keyFn выше ('file:' + getFileKey) -
        // без совпадения по префиксу startBulkDownload не находил файлы по выбранным ключам
        // и всегда сообщал "выбранные файлы недоступны".
        fileSelection.video.lastKnownFiles = new Map((data.videos || []).map(f => ['file:' + getFileKey(f), f]));
        reapplySelectionClasses(fileSelection.video);
        fileSelection.music.lastKnownFiles = new Map((data.music || []).map(f => ['file:' + getFileKey(f), f]));
        reapplySelectionClasses(fileSelection.music);

        updateFileBadges(data);
        updateProxyStatus(data.proxy);
        updateTabTitleProgress(data.jobs);

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
    initFileSelection();
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
    const collapsed = !panelBody.classList.contains('panel-collapsed');
    if (collapsed) {
        panelBody.classList.add('panel-collapsed');
        helpLink.innerHTML = 'Я туть, твоя помощь';
    } else {
        panelBody.classList.remove('panel-collapsed');
        helpLink.innerHTML = 'Скрыть';
    }
    // В разметке aria-expanded захардкожен в false и без этого никогда не менялся -
    // скринридер всегда слышал "свёрнуто", даже на раскрытой панели.
    const header = document.querySelector('[data-ui="help"]');
    if (header) header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
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

    // Музыкальный сервис - видео там нет по определению, и переключатель после
    // вставки ссылки всё равно пришлось бы дёргать руками. Включаем аудио сами,
    // молча: сам переключатель на виду, и вернуть видео - один клик по нему.
    // Ручное переключение уважаем - для этой ссылки больше не вмешиваемся.
    const audioModeToggle = document.getElementById('ui_audio_mode');
    let audioAutoKey = null;

    function isAudioOnlyService(hostname) {
        if (typeof AUDIO_ONLY_SERVICES === 'undefined' || !hostname) return false;
        const host = hostname.replace(/^www\./i, '').toLowerCase();
        return AUDIO_ONLY_SERVICES.some(d => host === d || host.endsWith('.' + d));
    }

    // Прямая ссылка на аудиофайл - тот же случай, что музыкальный сервис,
    // только распознаётся по расширению в пути, без обращения в сеть.
    const AUDIO_FILE_EXTENSIONS = ['mp3', 'm4a', 'aac', 'opus', 'ogg', 'oga', 'flac', 'wav', 'wma'];

    function audioFileName(rawUrl) {
        try {
            const path = new URL(rawUrl).pathname;
            const ext = (path.split('.').pop() || '').toLowerCase();
            if (!AUDIO_FILE_EXTENSIONS.includes(ext)) return null;
            return decodeURIComponent(path.split('/').pop() || '') || null;
        } catch (e) {
            return null;
        }
    }

    function hideAudioAutoPrompt() {
        audioAutoKey = null;
    }

    function maybeSwitchToAudio(hostname, rawUrl) {
        if (!audioModeToggle) return;
        const host = hostname ? hostname.replace(/^www\./i, '').toLowerCase() : null;
        const fileName = rawUrl ? audioFileName(rawUrl) : null;

        const key = fileName ? 'file:' + (rawUrl || '') : (host ? 'host:' + host : null);
        const applicable = fileName !== null || (host !== null && isAudioOnlyService(host));

        if (!key || !applicable) {
            audioAutoKey = null;
            return;
        }
        // Одна ссылка - одно переключение: иначе checkUrl на каждом вводе возвращал
        // бы аудио-режим, снятый вручную.
        if (audioAutoKey === key) return;

        audioAutoKey = key;
        if (!audioModeToggle.checked) {
            audioModeToggle.checked = true;
            syncLogic();
        }
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
        hideAudioAutoPrompt();
        urlInput.focus();
        setTimeout(() => { isClearing = false; }, 50);
    }

    function checkUrl() {
        if (isClearing) return;
        const val = urlInput.value.trim();
        if (!val) {
            hideFavicon();
            hideClearBtn();
            hideAudioAutoPrompt();
            return;
        }

        // Тот же разделитель, что у Downloader::splitUrls(): "||" либо пробел,
        // за которым сразу начинается новая ссылка. Просто по пробелу резать нельзя -
        // ссылка с незакодированным пробелом внутри потеряла бы хвост.
        const firstUrl = val.split(/\|\||\s+(?=https?:\/\/)/i)[0].trim();
        let hostname = null;
        try {
            let urlToParse = firstUrl;
            if (!/^https?:\/\//i.test(urlToParse)) urlToParse = 'https://' + urlToParse;
            hostname = new URL(urlToParse).hostname.replace(/^www\./i, '');
        } catch (e) {
            hostname = null;
        }

        maybeSwitchToAudio(hostname, /^https?:\/\//i.test(firstUrl) ? firstUrl : 'https://' + firstUrl);

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

    // Ctrl+V где угодно по странице кладёт ссылку в поле: попасть курсором в input
    // ради вставки - лишний шаг, а других полей для вставки на странице нет.
    // Когда фокус уже в поле ввода, не вмешиваемся - там работает обычная вставка.
    document.addEventListener('paste', (e) => {
        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)) return;
        if (window.getSelection && String(window.getSelection()) !== '') return;

        const clipboard = e.clipboardData || window.clipboardData;
        const text = clipboard ? clipboard.getData('text').trim() : '';
        if (!text) return;

        e.preventDefault();
        // mergeUrlLines, а не присваивание: в поле уже могла лежать ссылка, и
        // затирать её вставкой мимо поля человек не просил. Дедуп там же.
        urlInput.value = mergeUrlLines(text);
        urlInput.focus();
        checkUrl();
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
        // Чистим ссылку, когда человек уже закончил её вводить: посреди набора
        // подмена текста в поле сбивала бы курсор.
        const normalized = normalizeUrlField(urlInput.value);
        if (normalized !== urlInput.value.trim()) urlInput.value = normalized;
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

    // Тот же подмиг, что и на завершение загрузки - держим одну реализацию
    // наверху, чтобы жест означал одно и то же независимо от повода.
    function playSnejWink() {
        snejWink();
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

    // Enter на пустом поле: магия буфера умеет всё, кроме последнего шага -
    // берём распознанную ссылку и сразу запускаем. Клавиша сама по себе жест
    // пользователя, поэтому readText разрешён даже там, где фоновое чтение молчит.
    urlInput.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.ctrlKey || e.metaKey || e.altKey) return;
        if (urlInput.value.trim()) return;
        if (!navigator.clipboard || !navigator.clipboard.readText) return;

        e.preventDefault();
        navigator.clipboard.readText().then((text) => {
            const matched = matchKnownServiceUrl((text || '').trim());
            if (!matched) return;
            applyClipboardText(matched);
            const form = document.getElementById('download-form');
            if (form) form.requestSubmit();
        }).catch(() => {});
    });

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

        haptic('tick');
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

    // Перевод - одноразовый выбор из попапа, не липкая настройка. Браузер восстанавливает значения полей формы
    // при возврате на тот же URL (после сабмита идёт редирект обратно на index.php), и hidden-поле оставалось "1"
    // на все следующие загрузки. format от этого спасает syncLogic() ниже, translate спасать было нечему.
    const translateField = document.getElementById('translate_field');
    if (translateField) translateField.value = '';
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

    const urlError = document.getElementById('url-error');
    const urlWrapper = urlField.closest('.url-input-wrapper');

    function showUrlError(message) {
        if (!urlError) return;
        urlError.textContent = message;
        urlError.classList.remove('is-hidden');
        if (urlWrapper) urlWrapper.classList.add('has-error');
    }

    function hideUrlError() {
        if (!urlError) return;
        urlError.classList.add('is-hidden');
        if (urlWrapper) urlWrapper.classList.remove('has-error');
    }

    urlField.addEventListener('input', hideUrlError);

    downloadForm.addEventListener('submit', function (e) {
        // Ссылку разбираем прямо тут: обработчик вставки и так её парсит ради
        // фавикона, поэтому за ответом "ссылка кривая" незачем ходить на сервер
        // и получать полную перезагрузку страницы.
        const parsed = validateUrlField(urlField.value);
        if (parsed.bad.length) {
            e.preventDefault();
            const first = parsed.bad[0];
            const shown = first.length > 60 ? first.slice(0, 60) + '...' : first;
            showUrlError(parsed.bad.length === 1
                ? 'Это не похоже на ссылку: ' + shown
                : 'Непонятных ссылок: ' + parsed.bad.length + '. Первая: ' + shown);
            urlField.focus();
            return;
        }
        if (!parsed.urls.length) {
            e.preventDefault();
            showUrlError('Вставь ссылку на видео.');
            urlField.focus();
            return;
        }
        hideUrlError();

        // Отправляем канонический вид: blur мог не случиться (Enter из поля),
        // а сравнения ниже иначе идут по замусоренной трекингом ссылке.
        urlField.value = parsed.urls.join('||');

        let urls = parsed.urls;

        // Одна ссылка, похожая на список, - показываем содержимое и даём выбрать
        // вместо прежнего "целиком или только этот ролик". Окно само продолжит
        // отправку (resumeSubmitWith), а если yt-dlp скажет, что это обычный
        // ролик, - продолжит молча, человек ничего лишнего не увидит.
        // Пачку ссылок не трогаем: там список составлен руками.
        const alreadyResolved = playlistState.resolvedFor === urls.join('||');
        if (urls.length === 1 && !alreadyResolved && looksLikeCollection(urls[0])) {
            e.preventDefault();
            openPlaylistPicker(urls[0], document.activeElement);
            return;
        }

        // Таймкод в ссылке применяется сам (бэкенд ставит --download-sections),
        // поэтому спрашиваем до старта: срезанный t= возвращает ролик целиком.
        const timed = urls.map(u => {
            const seconds = urlStartSeconds(u);
            if (seconds === null) return u;
            if (confirm('В ссылке есть метка времени - ' + formatClock(seconds) +
                '.\n\nОК - скачать с этого места.\nОтмена - ролик целиком.')) {
                return u;
            }
            try {
                const parsedUrl = new URL(u);
                parsedUrl.searchParams.delete('t');
                parsedUrl.searchParams.delete('start');
                return parsedUrl.toString();
            } catch (err) {
                return u;
            }
        });
        urls = timed;
        urlField.value = urls.join('||');

        // Канал, страница автора или голая главная разворачиваются в десятки роликов,
        // а с виду это обычная ссылка. Предупреждаем до старта - остановить потом
        // можно только кнопкой Стоп, когда часть уже на диске.
        // При отправке из окна выбора вопрос не задаём: человек только что видел
        // список и сам решил, что берёт.
        const bulkUrl = alreadyResolved ? null : urls.find(looksLikeCollection);
        if (bulkUrl && !confirm('Ссылка ведёт не на ролик, а на канал или страницу со списком:\n\n' +
            (bulkUrl.length > 70 ? bulkUrl.slice(0, 70) + '...' : bulkUrl) +
            '\n\nСкачается всё, что там найдётся. Продолжить?')) {
            e.preventDefault();
            return;
        }

        // Вставили адрес самой Качалки - вместо загрузки открываем коридор из
        // вложенных копий. Только по явной вставке своего адреса, не по ссылке
        // на файл из download/ (там обычная прямая загрузка, см. isSelfUrl).
        if (urls.length && urls.every(isSelfUrl)) {
            e.preventDefault();
            urlField.value = '';
            showRecursionModal();
            return;
        }

        // Прокси лежит - зарубежная ссылка гарантированно упрётся в таймаут.
        // Состояние минутного окна уже приехало в опросе, спрашиваем сразу.
        if (lastProxyState === 'death' && urls.some(usesProxy) &&
            !confirm('Прокси сейчас не отвечает, а эта ссылка идёт через него.\n\nЗагрузка, скорее всего, упадёт по таймауту. Всё равно попробовать?')) {
            e.preventDefault();
            return;
        }

        // Файл уже лежит на диске - качать заново незачем, а узнаётся он по ID
        // в имени файла, без единого запроса в сеть.
        let existingFile = null;
        for (const u of urls) {
            const name = knownFileByUrl.get(urlCompareKey(u));
            if (name) { existingFile = name; break; }
        }
        if (existingFile && !confirm('Этот файл уже лежит на диске:\n\n' +
            (existingFile.length > 60 ? existingFile.slice(0, 60) + '...' : existingFile) +
            '\n\nСкачать ещё раз?')) {
            e.preventDefault();
            return;
        }

        // Активные задачи и очередь приезжают в том же ?jobs, что и история,
        // поэтому "уже качается, 43%" не стоит ни одного лишнего запроса.
        let duplicate = null;
        for (const u of urls) {
            const found = knownDownloadedUrls.get(urlCompareKey(u));
            if (found) { duplicate = found; break; }
        }
        if (duplicate) {
            let message;
            if (duplicate.kind === 'active') {
                message = duplicate.percent !== null
                    ? 'Эта ссылка уже качается, ' + Math.round(parseFloat(duplicate.percent)) + '%. Скачать ещё раз?'
                    : 'Эта ссылка уже качается. Скачать ещё раз?';
            } else if (duplicate.kind === 'queued') {
                message = 'Эта ссылка уже стоит в очереди. Добавить ещё раз?';
            } else {
                message = 'Эта ссылка уже скачивалась. Скачать ещё раз?';
            }
            if (!confirm(message)) {
                e.preventDefault();
                return;
            }
        }

        // Отправка идёт через fetch, как все остальные действия на сайте: после
        // редиректа сводку показывать негде, она умирает вместе с запросом.
        // Форма при этом остаётся обычной формой - без JS сработает POST.
        e.preventDefault();
        sendDownloadForm(downloadForm, urlField);
    });
});

// ---------------------------------------------------------------------------
// Выбор роликов плейлиста
//
// Список показывается модалкой и живёт ВНЕ таблиц ?jobs - те перерисовываются
// полтора раза в секунду, и строка со снятым выбором внутри такой таблицы не
// пережила бы ближайший опрос (по той же причине туда вынесены плеер и QR).
//
// Выбор хранится множеством ключей, а не классом в разметке строки: тот же
// инвариант, что у массового выбора файлов (см. createFileSelectionState).
// ---------------------------------------------------------------------------

// Опрос результата разбора: интервал и потолок ожидания. Потолок чуть больше
// серверного PROBE_TIMEOUT, чтобы ответ об ошибке успел прийти от сервера, а не
// от нас - у сервера он осмысленнее.
const PLAYLIST_POLL_INTERVAL = 1200;
const PLAYLIST_POLL_LIMIT = 55000;

// Столько ссылок принимает бэкенд за одну отправку ($max_urls_per_submit).
const PLAYLIST_MAX_SUBMIT = 50;

// Выше этого числа спрашиваем подтверждение - как у массового скачивания файлов.
const PLAYLIST_CONFIRM_COUNT = 10;

const playlistState = {
    sourceUrl: '',
    parsing: 'idle',
    key: null,
    contentType: null,
    title: '',
    total: 0,
    // Сколько строк сервер скрыл (недоступные и дубли) - для подзаголовка.
    hidden: 0,
    truncated: false,
    frozenItems: [],
    selectedIds: new Set(),
    pollTimer: null,
    pollStartedAt: 0,
    errorText: '',
    // Ссылка, по которой выбор уже сделан: второй раз окно на неё не открываем
    // и повторных вопросов про "скачается всё" не задаём.
    resolvedFor: null,
    opener: null
};

let playlistModalEl = null;

// Ключ строки. Индекс в нём обязателен: один ролик попадает в плейлист дважды
// (обычное дело для YouTube Mix), и ключ из одного id совпал бы у двух строк -
// клик по одной подсвечивал бы обе, а Set схлопывал бы их в один элемент, из-за
// чего "выбрано" никогда не догоняло число строк и кнопка залипала на "Выбрать всё".
function playlistItemKey(item, idx) {
    return 'pl:' + idx + ':' + (item.id || item.url);
}

function resetPlaylistState() {
    if (playlistState.pollTimer) {
        clearTimeout(playlistState.pollTimer);
        playlistState.pollTimer = null;
    }
    playlistState.parsing = 'idle';
    playlistState.key = null;
    // Без сброса второе открытие окна сразу упиралось бы в потолок ожидания,
    // отсчитанный от первого разбора.
    playlistState.pollStartedAt = 0;
    playlistState.contentType = null;
    playlistState.title = '';
    playlistState.total = 0;
    playlistState.hidden = 0;
    playlistState.truncated = false;
    playlistState.frozenItems = [];
    playlistState.selectedIds.clear();
    playlistState.errorText = '';
}

function ensurePlaylistModal() {
    if (playlistModalEl) return playlistModalEl;

    // Оболочка - те же классы, что у QR и плеера: одно затемнение, одна анимация,
    // дублировать CSS незачем.
    const overlay = document.createElement('div');
    overlay.className = 'qr-modal-overlay playlist-modal-overlay';
    overlay.innerHTML = `
        <div class="qr-modal-card playlist-modal-card" role="dialog" aria-modal="true" aria-labelledby="playlist-modal-title">
            <button type="button" class="qr-modal-close playlist-modal-close" aria-label="Закрыть">&times;</button>
            <div class="qr-modal-title" id="playlist-modal-title">Что скачать из плейлиста</div>
            <div class="playlist-modal-subtitle"></div>
            <div class="playlist-modal-body">
                <div class="playlist-modal-status">Разбираю плейлист, это занимает до минуты...</div>
                <div class="playlist-modal-list" role="listbox" aria-multiselectable="true" aria-label="Ролики плейлиста" hidden>
                    <!-- role=presentation на таблице и tbody: без этого их
                         собственные роли разрывают связь listbox -> option,
                         и скринридер перестаёт видеть строки как варианты. -->
                    <table class="table table-striped playlist-modal-table" role="presentation">
                        <tbody class="playlist-modal-rows" role="presentation"></tbody>
                    </table>
                </div>
            </div>
            <div class="playlist-modal-bar" hidden>
                <span class="selection-toolbar-count playlist-modal-count" aria-live="polite">Выбрано: 0</span>
                <span class="selection-toolbar-break" aria-hidden="true"></span>
                <button type="button" class="selection-btn playlist-modal-all">Снять всё</button>
                <button type="button" class="selection-btn selection-btn-primary playlist-modal-submit">Скачать</button>
            </div>
            <div class="playlist-modal-bar playlist-modal-errorbar" hidden>
                <button type="button" class="selection-btn playlist-modal-cancel">Отмена</button>
                <button type="button" class="selection-btn selection-btn-primary playlist-modal-whole">Скачать целиком</button>
            </div>
        </div>`;

    document.body.appendChild(overlay);
    playlistModalEl = overlay;

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closePlaylistPicker();
    });
    overlay.querySelector('.playlist-modal-close').addEventListener('click', closePlaylistPicker);
    overlay.querySelector('.playlist-modal-cancel').addEventListener('click', closePlaylistPicker);

    overlay.querySelector('.playlist-modal-all').addEventListener('click', togglePlaylistAll);

    overlay.querySelector('.playlist-modal-submit').addEventListener('click', submitPlaylistSelection);

    // "Скачать целиком" - запасной путь, когда разбор не удался: отправляем
    // исходную ссылку как раньше, вопросов про неё больше не задаём.
    overlay.querySelector('.playlist-modal-whole').addEventListener('click', () => {
        const url = playlistState.sourceUrl;
        closePlaylistPicker();
        resumeSubmitWith(url, url);
    });

    const rows = overlay.querySelector('.playlist-modal-rows');
    rows.addEventListener('click', (e) => {
        const row = e.target.closest('tr[data-row-idx]');
        if (row) togglePlaylistRow(row.dataset.rowIdx);
    });
    rows.addEventListener('keydown', (e) => {
        const row = e.target.closest('tr[data-row-idx]');
        if (!row) return;
        if (e.key === ' ' || e.key === 'Enter') {
            e.preventDefault();
            togglePlaylistRow(row.dataset.rowIdx);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const all = Array.from(rows.querySelectorAll('tr[tabindex="0"]'));
            const idx = all.indexOf(row);
            const next = all[idx + (e.key === 'ArrowDown' ? 1 : -1)];
            if (next) next.focus();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (!overlay.classList.contains('is-visible')) return;
        if (e.key === 'Escape') {
            closePlaylistPicker();
        } else if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
            if (playlistState.parsing !== 'ready') return;
            e.preventDefault();
            togglePlaylistAll();
        }
    });

    return overlay;
}

// Открыть окно выбора. Модалка показывается сразу, до ответа сервера - разбор
// идёт десятки секунд, и молчащая кнопка всё это время выглядит сломанной.
function openPlaylistPicker(url, opener) {
    resetPlaylistState();
    playlistState.sourceUrl = url;
    playlistState.parsing = 'detecting';
    playlistState.opener = opener || null;

    const overlay = ensurePlaylistModal();
    overlay.classList.add('is-visible');
    renderPlaylistModal();

    const body = new FormData();
    body.append('playlist', url);
    body.append('csrf_token', getCsrfToken());

    fetch('index.php', {
        method: 'POST',
        body: body,
        headers: { 'X-CSRF-Token': getCsrfToken(), 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    })
        .then(resp => {
            const ctype = resp.headers.get('content-type') || '';
            if (!ctype.includes('application/json')) {
                throw new Error('сервер ответил не JSON (' + resp.status + ')');
            }
            return resp.json();
        })
        .then(applyPlaylistResponse)
        .catch(err => failPlaylist('Не удалось разобрать плейлист: ' + (err && err.message ? err.message : 'нет связи')));
}

function applyPlaylistResponse(data) {
    if (!data || data.state === 'missing') {
        failPlaylist('Разбор потерялся, попробуй ещё раз');
        return;
    }
    if (data.state === 'error') {
        failPlaylist(data.error || 'Не удалось разобрать плейлист');
        return;
    }
    if (data.state === 'pending') {
        playlistState.key = data.key || playlistState.key;
        if (!playlistState.pollStartedAt) playlistState.pollStartedAt = Date.now();
        if (Date.now() - playlistState.pollStartedAt > PLAYLIST_POLL_LIMIT) {
            failPlaylist('Не успел разобрать плейлист, попробуй ещё раз');
            return;
        }
        playlistState.pollTimer = setTimeout(() => pollPlaylist(playlistState.key), PLAYLIST_POLL_INTERVAL);
        return;
    }

    // Готово. Ответ на вопрос "плейлист ли" пришёл от yt-dlp, а не от эвристики:
    // одиночный ролик закрывает окно и продолжает обычную отправку.
    playlistState.contentType = data.contentType || 'video';
    if (playlistState.contentType !== 'playlist' && playlistState.contentType !== 'multi_video') {
        const url = playlistState.sourceUrl;
        closePlaylistPicker();
        resumeSubmitWith(url, url);
        return;
    }

    const items = Array.isArray(data.entries) ? data.entries : [];
    if (!items.length) {
        failPlaylist('В плейлисте нечего скачивать');
        return;
    }

    playlistState.parsing = 'ready';
    playlistState.title = data.title || '';
    playlistState.total = data.total || data.count || items.length;
    playlistState.hidden = data.hidden || 0;
    playlistState.truncated = !!data.truncated;
    // Снимок: рисуем только его, что бы дальше ни приехало с сервера.
    playlistState.frozenItems = items;
    // Недоступное сервер уже отсеял, фильтр здесь - страховка на старый кэш.
    playlistState.selectedIds = new Set(
        items.map((i, idx) => (i.available === false ? null : playlistItemKey(i, idx)))
             .filter(k => k !== null)
    );
    renderPlaylistModal();
}

function pollPlaylist(key) {
    if (!key || playlistState.parsing !== 'detecting') return;
    fetch('index.php?playlist=' + encodeURIComponent(key), { headers: { 'Accept': 'application/json' } })
        .then(resp => resp.json())
        .then(applyPlaylistResponse)
        .catch(() => failPlaylist('Связь с сервером потерялась'));
}

function failPlaylist(message) {
    playlistState.parsing = 'error';
    playlistState.errorText = message;
    if (playlistState.pollTimer) {
        clearTimeout(playlistState.pollTimer);
        playlistState.pollTimer = null;
    }
    renderPlaylistModal();
}

function renderPlaylistModal() {
    const overlay = ensurePlaylistModal();
    const status = overlay.querySelector('.playlist-modal-status');
    const list = overlay.querySelector('.playlist-modal-list');
    const bar = overlay.querySelector('.playlist-modal-bar:not(.playlist-modal-errorbar)');
    const errorBar = overlay.querySelector('.playlist-modal-errorbar');
    const subtitle = overlay.querySelector('.playlist-modal-subtitle');

    if (playlistState.parsing === 'detecting') {
        status.textContent = 'Разбираю плейлист, это занимает до минуты...';
        status.hidden = false;
        list.hidden = true;
        bar.hidden = true;
        errorBar.hidden = true;
        subtitle.textContent = '';
        return;
    }

    if (playlistState.parsing === 'error') {
        status.textContent = playlistState.errorText;
        status.hidden = false;
        list.hidden = true;
        bar.hidden = true;
        errorBar.hidden = false;
        subtitle.textContent = '';
        return;
    }

    status.hidden = true;
    list.hidden = false;
    bar.hidden = false;
    errorBar.hidden = true;

    let sub = playlistState.title ? playlistState.title : '';
    if (playlistState.truncated) {
        sub += (sub ? ' - ' : '') + 'показаны первые ' + playlistState.frozenItems.length +
            ' из ' + playlistState.total;
    }
    if (playlistState.hidden > 0) {
        sub += (sub ? ', ' : '') + playlistState.hidden + ' недоступных скрыто';
    }
    subtitle.textContent = sub;

    renderPlaylistRows();
    updatePlaylistBar();
}

function renderPlaylistRows() {
    const rows = ensurePlaylistModal().querySelector('.playlist-modal-rows');
    rows.innerHTML = playlistState.frozenItems.map((item, idx) => {
        const key = playlistItemKey(item, idx);
        const duration = item.duration > 0 ? formatClock(item.duration) : '';
        const badge = item.available === false
            ? '<span class="playlist-row-badge">' + escapeHtml(item.reason || 'Недоступен') + '</span>'
            : '';
        // Номер - позиция ролика в плейлисте, а не в показанном списке: со
        // скрытыми строками нумерация подряд разъехалась бы с сайтом.
        const num = item.position > 0 ? item.position : (idx + 1);
        return '<tr data-row-key="' + escapeHtml(key) + '" data-row-idx="' + idx + '"' +
            ' role="option" aria-selected="false"' +
            (item.available === false ? ' aria-disabled="true" class="playlist-row-unavailable"' : ' tabindex="0"') +
            '><td class="playlist-row-num">' + num + '</td>' +
            '<td class="playlist-row-title">' + escapeHtml(item.title) + badge + '</td>' +
            '<td class="playlist-row-time">' + escapeHtml(duration) + '</td></tr>';
    }).join('');
    reapplyPlaylistSelection();
}

// Подсветка накладывается отдельным проходом по живым строкам, а не встраивается
// в разметку при генерации - см. комментарий у массового выбора файлов.
function reapplyPlaylistSelection() {
    const rows = ensurePlaylistModal().querySelectorAll('.playlist-modal-rows tr[data-row-key]');
    rows.forEach(row => {
        const on = playlistState.selectedIds.has(row.dataset.rowKey);
        row.classList.toggle('row-selected', on);
        row.setAttribute('aria-selected', on ? 'true' : 'false');
    });
}

function togglePlaylistRow(rowIdx) {
    const idx = parseInt(rowIdx, 10);
    const item = playlistState.frozenItems[idx];
    if (!item || item.available === false) return;
    const key = playlistItemKey(item, idx);
    if (playlistState.selectedIds.has(key)) {
        playlistState.selectedIds.delete(key);
    } else {
        playlistState.selectedIds.add(key);
    }
    reapplyPlaylistSelection();
    updatePlaylistBar();
    haptic('tick');
}

// Кнопка и Ctrl/Cmd+A делают одно и то же - иначе они расходятся в состоянии:
// раньше сочетание клавиш только добавляло и снять им выбор было нельзя.
function togglePlaylistAll() {
    const keys = playlistState.frozenItems
        .map((i, idx) => (i.available === false ? null : playlistItemKey(i, idx)))
        .filter(k => k !== null);
    if (playlistState.selectedIds.size >= keys.length) {
        playlistState.selectedIds.clear();
    } else {
        keys.forEach(k => playlistState.selectedIds.add(k));
    }
    reapplyPlaylistSelection();
    updatePlaylistBar();
}

function updatePlaylistBar() {
    const overlay = ensurePlaylistModal();
    const count = playlistState.selectedIds.size;
    // Знаменатель один и тот же у счётчика и у подписи кнопки. Пока они
    // расходились (все строки против доступных), "Снять всё" было недостижимо.
    const selectable = playlistState.frozenItems.filter(i => i.available !== false).length;

    overlay.querySelector('.playlist-modal-count').textContent =
        'Выбрано: ' + count + ' из ' + selectable;

    overlay.querySelector('.playlist-modal-all').textContent =
        count >= selectable ? 'Снять всё' : 'Выбрать всё';

    const submit = overlay.querySelector('.playlist-modal-submit');
    submit.textContent = count ? 'Скачать ' + count : 'Скачать';
    submit.disabled = count === 0;
    submit.setAttribute('aria-disabled', count === 0 ? 'true' : 'false');
}

function submitPlaylistSelection() {
    // Set - на случай, если один и тот же ролик всё-таки попал в список дважды:
    // вторая задача упёрлась бы в "Уже загружено" и только мусорила в очереди.
    const urls = Array.from(new Set(
        playlistState.frozenItems
            .filter((i, idx) => playlistState.selectedIds.has(playlistItemKey(i, idx)))
            .map(i => i.url)
    ));

    if (!urls.length) return;

    if (urls.length > PLAYLIST_MAX_SUBMIT) {
        failPlaylist('За раз можно отправить до ' + PLAYLIST_MAX_SUBMIT +
            ' ссылок, а выбрано ' + urls.length + '. Сними лишние.');
        return;
    }

    if (urls.length > PLAYLIST_CONFIRM_COUNT &&
        !confirm('Отправить ' + urls.length + ' роликов на скачивание?')) {
        return;
    }

    const joined = urls.join('||');
    closePlaylistPicker();
    resumeSubmitWith(joined, joined);
}

// Продолжить обычную отправку: кладём ссылки в поле, помечаем их разрешёнными
// (чтобы окно не открылось по второму кругу) и жмём submit формы штатно -
// requestSubmit, а не submit(), иначе обработчик со всеми проверками был бы обойдён.
function resumeSubmitWith(fieldValue, resolvedFor) {
    const field = document.getElementById('url');
    const form = document.getElementById('download-form');
    if (!field || !form) return;
    field.value = fieldValue;
    // Метку ставим в том же виде, в каком её увидит обработчик отправки: он
    // сначала прогоняет поле через validateUrlField/normalizeMediaUrl, и сырая
    // строка после нормализации могла бы с ней не совпасть - окно открылось бы
    // по второму кругу.
    const normalized = validateUrlField(resolvedFor).urls.join('||');
    playlistState.resolvedFor = normalized || resolvedFor;
    form.requestSubmit();
}

function closePlaylistPicker() {
    if (playlistModalEl) playlistModalEl.classList.remove('is-visible');
    const opener = playlistState.opener;
    resetPlaylistState();
    playlistState.opener = null;
    // Фокус обратно на то, чем окно открыли - иначе он улетает в начало страницы.
    if (opener && typeof opener.focus === 'function') opener.focus();
}

function sendDownloadForm(form, urlField) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch('index.php', {
        method: 'POST',
        body: new FormData(form),
        headers: {
            'X-CSRF-Token': getCsrfToken(),
            'X-Requested-With': 'fetch',
            // Accept - второй маркер для сервера на случай, если свой заголовок
            // где-то потеряется по дороге (см. $wantsJson в index.php)
            'Accept': 'application/json',
        },
    })
        .then(resp => {
            // Сервер мог увести редиректом на HTML - тогда resp.json() падал бы
            // молча в общий catch, и на странице не появлялось вообще ничего.
            const ctype = resp.headers.get('content-type') || '';
            if (!ctype.includes('application/json')) {
                throw new Error('Сервер ответил не JSON (' + resp.status + ' ' + (ctype || 'без типа') + ')');
            }
            return resp.json();
        })
        .then(result => {
            // Отклонённые ссылки остаются в поле, остальное поле отпускает -
            // видимого отчёта об отправке нет, состояние показывают таблицы.
            urlField.value = result.keepInField || '';

            // Короткий тик - ссылка принята, длинный рисунок ошибки - не принята.
            haptic((result.errors || []).length ? 'error' : 'tick');

            const errorBox = document.getElementById('url-error');
            if (errorBox) {
                if ((result.errors || []).length) {
                    errorBox.textContent = result.errors[0];
                    errorBox.classList.remove('is-hidden');
                } else {
                    errorBox.classList.add('is-hidden');
                }
            }

            // Раньше сюда приводил редирект на #downloads - оставляем тот же
            // переход, но только когда действительно что-то ушло качаться.
            // Опрос дёргаем всегда, а не только при успехе: даже отклонённая
            // отправка могла изменить очередь, а следующий плановый опрос в покое
            // приходит через 12 секунд - всё это время таблицы врут.
            if (typeof loadList === 'function') loadList();

            // Переход на Загрузки, как делал прежний редирект. Сводка при этом не
            // теряется: её разметка лежит вне вкладок, под навигацией.
            if (result.started || result.queued) {
                // showTab ждёт саму ссылку навигации, не строку
                const tabLink = document.querySelector('#mainnav a[href="#downloads"]');
                if (tabLink && typeof showTab === 'function') showTab(tabLink);
            }
        })
        .catch((err) => {
            const errorBox = document.getElementById('url-error');
            if (errorBox) {
                // Настоящая причина, а не общее "что-то пошло не так": молчаливый
                // провал этого запроса выглядит как полностью мёртвая кнопка.
                errorBox.textContent = 'Не удалось отправить ссылку: ' + (err && err.message ? err.message : 'нет связи');
                errorBox.classList.remove('is-hidden');
            }
            console.error('Отправка формы не удалась:', err);
        })
        .finally(() => {
            if (submitBtn) submitBtn.disabled = false;
        });
}

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