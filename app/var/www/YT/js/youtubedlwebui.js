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

// В поле ссылки идут по одной на строку, чтобы список было видно целиком.
// "||" (разделитель Downloader::addOneDownload()) - только при отправке, но
// на входе его тоже понимаем: так поле возвращает сервер после ошибки.
const URL_FIELD_SEP = /\|\||\r?\n/;

function normalizeUrlField(value) {
    return String(value ?? '').split(URL_FIELD_SEP)
        .flatMap(chunk => extractUrlsFromText(chunk))
        .map(u => normalizeMediaUrl(u.trim()))
        .filter(Boolean)
        .join('\n');
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
    String(value ?? '').split(URL_FIELD_SEP)
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

// Сессия живёт в контейнере и пропадает при его пересоздании: страница, открытая
// до выкатки, получает 403 на первое же действие. Берём свежий токен (?token) и
// повторяем запрос один раз - человек ничего не замечает.
function refreshCsrfToken() {
    return fetch('index.php?token', { cache: 'no-store' })
        .then(resp => (resp.ok ? resp.json() : null))
        .then(data => {
            if (!data || typeof data.token !== 'string' || data.token === '') return false;
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) meta.setAttribute('content', data.token);
            document.querySelectorAll('input[name="csrf_token"]').forEach(input => { input.value = data.token; });
            return true;
        })
        .catch(() => false);
}

// POST в index.php с токеном. body - URLSearchParams или FormData: поле
// csrf_token сервер читает раньше заголовка, поэтому на повторе меняем и его.
function csrfFetch(body, headers = {}) {
    const send = () => {
        if (body.has('csrf_token')) body.set('csrf_token', getCsrfToken());
        return fetch('index.php', {
            method: 'POST',
            body,
            headers: { ...headers, 'X-CSRF-Token': getCsrfToken() },
        });
    };
    return send().then(resp => (resp.status !== 403
        ? resp
        : refreshCsrfToken().then(ok => (ok ? send() : resp))));
}

// Общий fetch-сабмит для деструктивных/тумблерных действий - без full-reload формы, только диффовая перерисовка через loadList()/renderTable().
function submitActionFetch(fields) {
    return csrfFetch(new URLSearchParams(fields))
        .then(resp => {
            // 403 и после свежего токена - значит, дело не в сессии (чужой Origin,
            // сломанные куки). Без этой проверки resp.json() падал в catch(() => null),
            // и кнопка молча ничего не делала - пользователь не понимал, что случилось.
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
            return loadList(true);
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

    // Ссылка из очереди ничего не теряет - её вставят заново за секунду, а
    // подтверждение на каждый крестик превращало чистку списка в мучение.
    if (action !== 'removeQueued' && !confirm(messages[action] || 'Выполнить действие?')) {
        return;
    }

    // Массовые действия ("Стоп ВСЕ", "Очистить всё") гасить построчно нечего -
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

// Салюты и сценки снегиря живут в js/eggs.js (почти половина кода): грузим их
// при первом салюте или когда появилась задача, чтобы к финишу всё было готово,
// а не на каждом заходе.
let eggsPromise = null;
function loadEggs() {
    if (eggsPromise) return eggsPromise;
    eggsPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const version = typeof STATIC_VERSION === 'string' && STATIC_VERSION ? '?v=' + encodeURIComponent(STATIC_VERSION) : '';
        script.src = 'js/eggs.min.js' + version;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            eggsPromise = null; // дать шанс повторить при следующем салюте
            script.remove();
            reject(new Error('Не удалось загрузить салют'));
        };
        document.head.appendChild(script);
    });
    return eggsPromise;
}

function fireConfetti(info = {}) {
    // На «Домой» сценок нет: там Снежик и поле ввода, сценки - только на других вкладках
    if (fxOff || isOnHomePage()) return;
    loadEggs().then(() => runConfetti(info)).catch(e => console.warn(e.message));
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
        title = okCount === 1
            ? (Math.random() < DONE_TITLE_CHANCE ? pickRandom(DONE_TITLES) : 'Готово, лови')
            : `Готово: ${okCount} шт.`;
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
        // Один готовый файл - ведём прямо к нему, иначе в "Загрузки". Файл ищем в
        // момент клика: плашка могла провисеть, пока он истёк или его удалили.
        const onlyItem = (okCount === 1 && !failCount) ? successItems[0] : null;
        note.onclick = () => {
            window.focus();
            const disk = onlyItem ? findDiskFile(onlyItem.file, onlyItem.type) : null;
            if (disk) {
                gotoFile(disk.key, disk.kind);
            } else {
                // Одна смена hash вкладку не переключала - её переключает клик по навигации
                const nav = document.getElementById('dl_link');
                if (nav) nav.click();
            }
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

// Третья угловая кнопка: выключает Снежика, снег и салюты разом.
// Класс на <html> ставим сразу, до DOMContentLoaded, чтобы не мелькали.
const FX_OFF_KEY = 'yt_fx_off';
let fxOff = false;
// Источник правды - cookie: по ней PHP вообще не рисует Снежика и снег.
// localStorage - для тех, кто выключил до появления cookie.
const setFxCookie = on => { document.cookie = 'yt_fx_off=' + (on ? '1; max-age=31536000' : '; max-age=0') + '; path=/; SameSite=Lax'; };
fxOff = document.documentElement.classList.contains('fx-off');
try {
    if (!fxOff && localStorage.getItem(FX_OFF_KEY) === 'true') { fxOff = true; setFxCookie(true); }
} catch (e) {}
document.documentElement.classList.toggle('fx-off', fxOff);

let fxToggleBtn = null;

function updateFxButton() {
    if (!fxToggleBtn) return;
    // Снежинка-эмодзи, как 🔊 и 🔔 у соседей; в выключенном виде CSS перечёркивает её, как в 🔇/🔕
    fxToggleBtn.textContent = '❄️';
    fxToggleBtn.title = fxOff ? 'Эффекты выключены. Нажмите, чтобы вернуть Снежика и снег.' : 'Выключить Снежика, снег и салюты.';
    fxToggleBtn.classList.toggle('is-visible', isOnHomePage());
}

function initFxToggle() {
    const btn = document.createElement('div');
    btn.id = 'fx-toggle';
    btn.className = 'fx-toggle' + (isMobileDevice ? ' fx-toggle-alone' : '');
    btn.tabIndex = 0;
    btn.setAttribute('role', 'button');
    fxToggleBtn = btn;
    updateFxButton();

    btn.addEventListener('click', () => {
        fxOff = !fxOff;
        try { localStorage.setItem(FX_OFF_KEY, fxOff); } catch (e) {}
        setFxCookie(fxOff);
        // Снежика с выключенными эффектами сервер не отдал - вернуть можно только перезагрузкой
        if (!fxOff && !document.getElementById('snej')) { location.reload(); return; }
        document.documentElement.classList.toggle('fx-off', fxOff);
        // Идущий салют гасим сразу, а не ждём конца сценки
        if (fxOff && typeof confettiRun !== 'undefined' && confettiRun) confettiRun.canvas.remove();
        updateFxButton();
    });
    btn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            btn.click();
        }
    });
    document.body.appendChild(btn);
    window.addEventListener('hashchange', updateFxButton);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initFxToggle);
else initFxToggle();

// Ушли на «Домой» посреди сценки - гасим её сразу
window.addEventListener('hashchange', () => {
    if (isOnHomePage() && typeof confettiRun !== 'undefined' && confettiRun) confettiRun.canvas.remove();
});

function getIconClass(type) {
    return type === "audio" ? "fa-music" : "fa-video-camera";
}

const urlsCache = new Map();

function renderUrls(urlString, includeIcon = false, iconType = null, leadingBreak = true) {
    if (!urlString) return "";
    const key = `${urlString}|${includeIcon}|${iconType}|${leadingBreak}`;
    if (urlsCache.has(key)) return urlsCache.get(key);

    // Пачка в очереди - одна запись со ссылками через "||" (формат dl_queue).
    // Запятая не разделитель: она законна внутри ссылки ("?list=a,b").
    const result = urlString.split(URL_FIELD_SEP)
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
// Приводит живой узел to к виду from, трогая только отличия: атрибуты, текст,
// детей по порядку. Где теги разошлись - заменяет поддерево целиком. Обработчики
// у строк делегированные (на document), поэтому живому узлу ничего не теряется.
function morphNode(to, from) {
    if (to.nodeType !== from.nodeType || to.nodeName !== from.nodeName) {
        to.replaceWith(from);
        return;
    }
    if (to.nodeType === Node.TEXT_NODE || to.nodeType === Node.COMMENT_NODE) {
        if (to.nodeValue !== from.nodeValue) to.nodeValue = from.nodeValue;
        return;
    }
    if (to.nodeType !== Node.ELEMENT_NODE) return;
    for (const { name } of Array.from(to.attributes)) {
        if (!from.hasAttribute(name)) to.removeAttribute(name);
    }
    for (const { name, value } of Array.from(from.attributes)) {
        if (to.getAttribute(name) !== value) to.setAttribute(name, value);
    }
    // Атрибут checked/value после действий человека свойство уже не двигает -
    // переносим состояние, как это сделала бы замена узла
    if (to instanceof HTMLInputElement) {
        if (to.checked !== from.checked) to.checked = from.checked;
        if (to.value !== from.value) to.value = from.value;
    }
    const toKids = Array.from(to.childNodes);
    const fromKids = Array.from(from.childNodes);
    fromKids.forEach((kid, i) => {
        if (i < toKids.length) morphNode(toKids[i], kid);
        else to.appendChild(kid);
    });
    for (let i = fromKids.length; i < toKids.length; i++) toKids[i].remove();
}

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
            // Переносим отличия в живую строку, а не меняем её целиком: так
            // полоска прогресса получает старую и новую ширину и течёт
            // CSS-переходом, а не прыгает, и у строки не слетают hover и фокус
            morphNode(found, fresh);
            if (found === cursor) cursor = found.nextElementSibling;
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

// Кнопки "Удалить всё"/"Очистить всё"/"Стоп ВСЕ" - в tfoot, а не последней строкой tbody:
// строкой они читались как ещё один элемент списка (зебра, подсветка). Внутри
// таблицы, чтобы на телефоне прокручиваться вбок вместе с кнопками строк.
function renderTableFooter(container, cols, html) {
    const table = container.closest('table');
    if (!table) return;
    let footer = table.tFoot;
    if (!footer) {
        footer = table.createTFoot();
        footer.className = 'table-footer-actions';
    }
    if (footer.dataset.html !== html) {
        footer.dataset.html = html;
        footer.innerHTML = `<tr><td colspan="${cols}">${html}</td></tr>`;
    }
    footer.hidden = !html;
}

function renderTable(container, items, cols, emptyMsg, rowHtmlGenerator, footerHtml = "", keyFn = null) {
    // #dlqueue отсутствует в DOM при disableQueue=true - без этой проверки TypeError тут обрывал бы весь остаток loadList().
    if (!container) return;

    renderTableFooter(container, cols, items && items.length ? footerHtml : "");

    const hash = computeDataHash(items);

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
    }

    // Плавно показываем только заметные глазу перестроения - появление и
    // исчезновение строк. Смена процентов в существующей строке идёт как
    // раньше: кроссфейд полтора раза в секунду выглядел бы как мигание.
    const structuralChange = container.children.length !== desired.length;

    // Перестроение бывает отложенным: startViewTransition зовёт колбэк не сразу,
    // а опрос успевает запланировать следующее. Метку версии ставим СИНХРОННО,
    // чтобы устаревший колбэк не разложил строки по неактуальным данным - так
    // строка с кнопкой "Удалить всё" однажды оказалась выше файлов.
    const generation = (Number(container.dataset.renderGen) || 0) + 1;
    container.dataset.renderGen = String(generation);
    container.dataset.lastHash = hash;

    const apply = () => {
        if (Number(container.dataset.renderGen) !== generation) return;
        reconcileRows(container, desired);
        // Отсчёт до старта в очереди заполняется сразу, а не на следующем тике
        if (container === nativeUI.queue) tickQueueWaits();
    };

    if (structuralChange) {
        withViewTransition(apply);
    } else {
        apply();
    }
}

// Полоска активной задачи. Видео и звук - отдельные отрезки: yt-dlp качает их
// по очереди, у каждой дорожки проценты с нуля, и одна полоска откатывалась бы
// назад. Ширину отрезков и общий процент считает сервер (Downloader::buildProgress).
function renderJobProgress(p, statusText) {
    const segs = (Array.isArray(p.segments) ? p.segments : []).map(s => {
        const share = Math.max(0, Number(s.share) || 0);
        const fill = Math.min(100, Math.max(0, Number(s.fill) || 0));
        const kind = s.kind === 'audio' ? 'audio' : 'video';
        return `<div class="job-seg job-seg-${kind}" style="flex-grow: ${share}"><div class="job-seg-fill" style="width: ${fill}%"></div></div>`;
    }).join('');
    const percent = Math.min(100, Math.max(0, Math.floor(Number(p.percent) || 0)));
    // Процент и остаток времени - первыми: ради них на строку и смотрят
    const meta = [`<b>${percent}%</b>`];
    if (p.eta) meta.push(`<span>осталось ${escapeHtml(p.eta)}</span>`);
    if (p.track) meta.push(`<span class="job-track">${escapeHtml(p.track)}</span>`);
    for (const part of [p.size, p.speed]) {
        if (part) meta.push(`<span>${escapeHtml(part)}</span>`);
    }
    // Без пробелов между блоками: у ячейки статуса white-space: pre-wrap (ради
    // многострочных ошибок), и перенос строки в шаблоне рисовался пустой строкой.
    return `<div class="job-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent}" title="${escapeHtml(statusText)}">${segs}</div>`
        + `<div class="job-progress-meta">${meta.join('')}</div>`;
}

// Имя файла в "Загрузках" - главное, адрес источника - серая подпись под ним:
// одного веса и размера они сливались. "Ща.." - заглушка сервера, пока yt-dlp
// не узнал название.
function renderJobFileName(file, innerHtml = null) {
    if (!file || file === 'Ща..') return '<span class="job-file-name job-file-name-pending">Узнаю название…</span>';
    return `<span class="job-file-name">${innerHtml ?? escapeHtml(file)}</span>`;
}

// Под именем уже стоит адрес источника, и хвост "_<id>" из шаблона имени
// (Downloader: "%(title)s_%(id)s", у перевода ещё "_ru") вместе с расширением
// только сбивали с толку - в "Загрузках" показываем одно название, полное имя
// файла остаётся во вкладках "Видео"/"Музыка". Id режем, только если он и правда
// есть в адресе: подчёркивания бывают и в самих названиях.
function displayFileName(file, urlString) {
    // ".f137" - промежуточная дорожка (как INTERMEDIATE_STREAM_RE на сервере),
    // "(3 of 10)" - позиция в плейлисте, её оставляем
    const m = /^(.+?)(?:\.f\d+(?:-[a-z0-9]+)?)?\.[a-z0-9]{2,4}(\s*\(\d+ of \d+\))?$/i.exec(String(file || '').trim());
    if (!m) return file;
    const base = m[1];
    const listpos = m[2] || '';
    let url = String(urlString || '');
    try { url = decodeURIComponent(url); } catch (e) {}
    for (let i = base.indexOf('_'); i > 0; i = base.indexOf('_', i + 1)) {
        const id = base.slice(i + 1).replace(/_ru$/, '');
        const title = base.slice(0, i).trim();
        if (id.length >= 4 && title && url.includes(id)) return title + listpos;
    }
    return base + listpos;
}

function renderSourceUrls(urlString) {
    return String(urlString || '').split(',').map(u => u.trim()).filter(Boolean)
        .map(u => `<div class="job-source"><a href="${safeUrlAttr(u)}" title="${escapeHtml(u)}">${escapeHtml(u.replace(/^https?:\/\/(www\.)?/i, ''))}</a></div>`)
        .join('');
}

function renderJobRow(job) {
    const iconClass = getIconClass(job.type);
    const statusHtml = job.progress ? renderJobProgress(job.progress, job.status) : escapeHtml(job.status);
    return `
    <tr>
        <td style="vertical-align: middle;"><i class="fa ${iconClass}"></i> ${renderJobFileName(displayFileName(job.file, job.url))}${renderSourceUrls(job.url)}</td>
        <td style="vertical-align: middle;">${statusHtml}</td>
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
    // Текст отсчёта заполняет tickQueueWaits(): в разметке только момент старта,
    // иначе строка менялась бы каждую секунду и перерисовывалась под пальцем
    const waitHtml = item.start_at
        ? `<div class="job-status queue-wait" data-start-at="${Number(item.start_at) || 0}" data-wait-reason="${item.wait_reason === 'cooldown' ? 'cooldown' : 'gap'}"></div>`
        : '';
    const isFirst = idx === 0;
    const isLast = !arr || idx === arr.length - 1;
    const pid = safePid(item.pid);
    const upBtn = `<button type="button" style="width: 30px" data-reorder-pid="${pid}" data-reorder-dir="up" class="btn btn-default btn-xs reorder-btn"${isFirst ? ' disabled' : ''} title="Поднять в очереди">▲</button>`;
    const downBtn = `<button type="button" style="width: 30px" data-reorder-pid="${pid}" data-reorder-dir="down" class="btn btn-default btn-xs reorder-btn"${isLast ? ' disabled' : ''} title="Опустить в очереди">▼</button>`;
    return `
    <tr>
        <td style="vertical-align: middle;">${urlsHtml}${waitHtml}</td>
        <td style="vertical-align: middle;">${escapeHtml(item.dl_format)}</td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                ${upBtn}${downBtn}
                <button type="button" style="width: 30px" data-action="removeQueued" data-value="${pid}" class="btn btn-default btn-xs queue-remove-btn" title="Убрать из очереди" aria-label="Убрать из очереди">×</button>
            </div>
        </td>
    </tr>`;
}

// Сколько секунд часы сервера впереди часов браузера (по последнему ?jobs)
let serverClockSkew = 0;

// Очередь ждёт не только слота: YouTube не чаще раза в 20 с, после 429 сайт
// придержан на минуты. Без отсчёта такая строка выглядит зависшей.
function tickQueueWaits() {
    const now = Date.now() / 1000 + serverClockSkew;
    document.querySelectorAll('.queue-wait[data-start-at]').forEach(el => {
        const left = Math.ceil(Number(el.dataset.startAt) - now);
        let text = '';
        if (left > 0) {
            const when = left < 60 ? left + ' с' : Math.ceil(left / 60) + ' мин';
            text = el.dataset.waitReason === 'cooldown'
                ? 'сайт попросил паузу, старт через ' + when
                : 'старт через ' + when;
        }
        if (el.textContent !== text) el.textContent = text;
    });
}
setInterval(tickQueueWaits, 1000);

// Успех - без слов: строка в "Последних загрузках" со ссылкой на файл сама значит
// "готово", а одинаковое "Готово" в каждой строке было шумом. Текст остаётся, только
// когда есть что сказать: пояснение после "Готово" ("...сработало с куки"), отмена,
// ожидание автоповтора ("пробую...") - серым; всё остальное - ошибка.
function renderFinishedStatus(status) {
    const text = String(status || '').trim();
    if (!text) return '';
    if (text.startsWith('Готово')) {
        const note = text.split('\n').slice(1).join('\n').trim();
        return note ? `<div class="job-status">${escapeHtml(note)}</div>` : '';
    }
    const neutral = text.startsWith('Отменено') || /пробую/i.test(text);
    return `<div class="job-status${neutral ? '' : ' job-status-error'}">${escapeHtml(text)}</div>`;
}

function renderFinishedRow(item, logURL) {
    const iconClass = getIconClass(item.type);
    // Файл ещё на диске - имя ведёт к нему во вкладку "Видео"/"Музыка", а не
    // заставляет искать его там глазами (diskKind/diskKey проставляет loadList)
    const fileHtml = item.diskKey
        ? `<a href="#${item.diskKind === 'm' ? 'music' : 'videos'}" class="finished-file-link" data-goto-file="${escapeHtml(item.diskKey)}" data-goto-kind="${item.diskKind}" title="Показать файл">${escapeHtml(displayFileName(item.file, item.url))}</a>`
        : escapeHtml(displayFileName(item.file, item.url));
    let logButton = "";
    // 80px на "Удалить" и с "Логом": 60px было уже самого слова, и оно вылезало
    // за края. Место под них берёт "↺" - тема и так ужимает его до 30px.
    const actionBtnWidth = "80px";

    if (logURL && logURL !== "") {
        logButton = `<a href="${safeUrlAttr(logURL)}/${safePid(item.pid)}" style="width: 48px;" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-xs">Лог</a>`;
    }

    return `
    <tr>
        <td style="vertical-align: middle;"><i class="fa ${iconClass}"></i> ${renderJobFileName(item.file, fileHtml)}<div class="job-meta">${renderSourceUrls(item.url)}${renderFinishedStatus(item.status)}</div></td>
        <td style="vertical-align: middle;">
            <div class="btn-group">
                ${logButton}
                <button type="button" style="width: ${actionBtnWidth}" data-action="restart" data-value="${safePid(item.pid)}" class="btn btn-success btn-xs" title="Перекачать" aria-label="Перекачать">↺</button>
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
    // На телефоне та же кнопка открывает системное "Поделиться": ссылку оттуда
    // сразу отправляют в мессенджер, без копирования и вставки.
    const canShare = isMobileDevice && typeof navigator.share === 'function';
    const shareIcon = `<svg class="copy-btn-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11A2.99 2.99 0 0 0 21 5a3 3 0 1 0-5.91.7L8.04 9.81A2.99 2.99 0 0 0 3 12a3 3 0 0 0 5.04 2.19l7.12 4.16c-.05.21-.08.43-.08.65A2.92 2.92 0 1 0 18 16.08z"/></svg>`;
    const copyButton = file.downloadurl
        ? `<button type="button" class="btn btn-default btn-xs copy-btn" title="${canShare ? 'Поделиться ссылкой на файл' : 'Скопировать ссылку на файл'}" data-copy-url="${escapeHtml(file.downloadurl)}"${canShare ? ' data-share="1"' : ''}>${canShare ? shareIcon : copyIcon}</button>`
        : '';

    const pinIcon = `<svg class="pin-btn-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M16 3l5 5-1.9 1.9-2.5-.6-3.6 3.6.9 3.7-1.9 1.9-3.5-3.5-4.8 4.8-1.4-1.4 4.8-4.8-3.5-3.5 1.9-1.9 3.7.9 3.6-3.6-.6-2.5z"/></svg>`;
    const pinned = !!file.pinned;
    const pinButton = file.name
        ? `<button type="button" class="btn btn-default btn-xs pin-btn${pinned ? ' pin-btn-active' : ''}" title="${pinned ? 'Открепить' : 'Закрепить - не удалится по времени и при «Удалить всё»'}" data-pin-name="${file.name}" data-pin-type="${file.kind === 'audio' ? 'm' : 'v'}" data-pin-pinned="${pinned ? '1' : '0'}">${pinIcon}</button>`
        : '';

    // file.name приходит уже экранированным (JobsSnapshot::fileRow)
    const deleteButton = (typeof allowFileDelete !== 'undefined' && allowFileDelete && file.name)
        ? `<button type="button" data-action="delete" data-value="${file.name}" data-type="${file.kind === 'audio' ? 'm' : 'v'}" class="btn btn-danger btn-xs">Удалить</button>`
        : '';
    if (!playButton && !qrButton && !copyButton && !pinButton && !deleteButton) return '';
    return `<div class="btn-group btn-group-file">${playButton}${qrButton}${copyButton}${pinButton}${deleteButton}</div>`;
}

// Ключ файла для отслеживания "новых" строк между опросами - downloadurl
// стабилен и уникален per-файл, имя как запасной вариант.
function getFileKey(file) {
    return file.downloadurl || file.name;
}

// Имя файла в строке: ссылкой на скачивание, если downloadPath задан
function fileNameHtml(file) {
    return file.downloadurl
        ? `<a href="${escapeHtml(file.downloadurl)}" download>${file.name}</a>`
        : file.name;
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
        return `<tr><td><span class="file-name-plain${enterClass}${waveClass}">${fileNameHtml(file)}</span></td><td>${escapeHtml(file.size)}</td><td>${actions}</td></tr>`;
    }

    const pinned = !!file.pinned;
    let timeText, colorClass, percent;

    // Закреплённый файл не тикает: полоска всегда была бы полной и ничего не сообщала,
    // поэтому вместо неё только знак. Сам закреп показывает кнопка (.pin-btn-active).
    if (pinned) {
        timeText = '∞';
    } else {
        // Часы сервера (serverClockSkew), а не клиента: mtime файла - серверный
        const retention = (typeof retentionMinutes !== 'undefined' && retentionMinutes > 0) ? retentionMinutes : 120;
        const nowSec = Date.now() / 1000 + serverClockSkew;
        const age = file.mtime > 0 ? Math.max(0, Math.floor((nowSec - file.mtime) / 60)) : 0;
        percent = Math.max(0, Math.min(100, Math.round((retention - age) / retention * 100)));
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

    const badge = pinned
        ? `
        <div class="lifetime-badge lifetime-badge-pinned" title="Закреплён - не удалится по времени">
            <div class="lifetime-badge-text">${timeText}</div>
        </div>`
        : `
        <div class="lifetime-badge">
            <div class="lifetime-badge-text">⏱ ${timeText}</div>
            <div class="progress">
                <div class="progress-bar progress-lifetime ${colorClass}" style="width: ${percent}%;"></div>
            </div>
        </div>`;

    return `
        <tr>
            <td>
                <div class="file-row-content${enterClass}">
                    ${badge}
                    <span class="file-name${waveClass}">${fileNameHtml(file)}</span>
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

// Ключ строки-файла отличается от служебных строк тем же tbody (у строки
// "Видео нет." тоже есть data-row-key, ='__empty__' - иначе она бы ловила
// long-press и попадала в счётчик выбранного как несуществующий файл).
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

    // Протяжка мышью по строкам, как в выборе плейлиста: якорь + диапазон от снимка
    // на момент нажатия (повёл назад - строки возвращаются). Только мышь: на touch
    // протяжка отдана прокрутке страницы.
    let drag = null;
    const fileRows = () => Array.from(tbody.children).filter(isSelectableFileRow);

    function dragPaint(toRow) {
        const rows = fileRows();
        const to = rows.indexOf(toRow);
        if (to < 0 || to === drag.last) return;
        drag.last = to;
        const lo = Math.min(drag.anchor, to), hi = Math.max(drag.anchor, to);
        state.selected.clear();
        drag.before.forEach(k => state.selected.add(k));
        for (let i = lo; i <= hi; i++) {
            const key = rows[i].dataset.rowKey;
            if (drag.select) state.selected.add(key); else state.selected.delete(key);
        }
        reapplySelectionClasses(state);
    }

    function dragBegin(row, pointerId) {
        suppressNextClick = true; // click после отпускания иначе вернул бы выбор строки
        drag = {
            anchor: fileRows().indexOf(row),
            last: null,
            before: new Set(state.selected),
            select: !state.selected.has(row.dataset.rowKey),
        };
        try { tbody.setPointerCapture(pointerId); } catch (_) {}
        window.getSelection().removeAllRanges();
        dragPaint(row);
    }

    tbody.addEventListener('pointerdown', (e) => {
        suppressNextClick = false;
        const row = e.target.closest('tr[data-row-key]');
        // Жест, начатый на кнопке действия строки (play/qr/copy/pin/удалить),
        // не должен запускать выбор - у кнопок своё поведение.
        if (!isSelectableFileRow(row) || e.target.closest('button, a')) return;
        const mouse = e.pointerType === 'mouse' && e.button === 0;
        if (state.active && mouse && !state.queue) {
            e.preventDefault(); // без этого браузер начнёт выделять текст
            row.focus({ preventScroll: true });
            dragBegin(row, e.pointerId);
            return;
        }
        startX = e.clientX; startY = e.clientY;
        clearTimeout(pressTimer);
        const pointerId = e.pointerId;
        pressTimer = setTimeout(() => {
            pressTimer = null;
            enterSelectionMode(state);
            if (mouse) {
                dragBegin(row, pointerId);
            } else {
                suppressNextClick = true;
                toggleRowSelection(state, row);
            }
            haptic('tick');
        }, LONG_PRESS_SELECT_TIME);
    });

    tbody.addEventListener('pointermove', (e) => {
        if (drag) {
            const el = document.elementFromPoint(e.clientX, e.clientY);
            const row = el && el.closest('tr[data-row-key]');
            if (isSelectableFileRow(row) && tbody.contains(row)) dragPaint(row);
            return;
        }
        if (!pressTimer) return;
        if (Math.abs(e.clientX - startX) > LONG_PRESS_MOVE_TOLERANCE || Math.abs(e.clientY - startY) > LONG_PRESS_MOVE_TOLERANCE) {
            clearTimeout(pressTimer);
            pressTimer = null;
        }
    });

    const clearPress = () => { clearTimeout(pressTimer); pressTimer = null; drag = null; };
    tbody.addEventListener('pointerup', clearPress);
    tbody.addEventListener('pointercancel', clearPress);
    tbody.addEventListener('lostpointercapture', () => { drag = null; });
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

// "Назад" на телефоне должен закрывать модалку (QR, плеер), а не уводить со страницы:
// открытие кладёт в историю свою запись (тот же адрес), "Назад" её снимает. Закрытие
// крестиком/фоном/Escape снимает её само через history.back(), чтобы в истории не
// копились пустые записи. back() асинхронный: пока он не отработал (modalHistoryLeaving),
// его popstate - наш собственный и модалку закрывать не должен.
let modalHistoryHide = null;     // чем закрыть модалку, чья запись сейчас наверху истории
let modalHistoryLeaving = false;
let modalHistoryQueued = null;   // модалку открыли снова, пока снималась прошлая запись

function pushModalHistory(hide) {
    if (modalHistoryLeaving) { modalHistoryQueued = hide; return; }
    if (modalHistoryHide) return;
    history.pushState({ ytModal: true }, '');
    modalHistoryHide = hide;
}

function popModalHistory(hide) {
    if (modalHistoryQueued === hide) modalHistoryQueued = null;
    if (modalHistoryHide !== hide) return;
    modalHistoryHide = null;
    modalHistoryLeaving = true;
    history.back();
}

window.addEventListener('popstate', () => {
    if (modalHistoryLeaving) {
        modalHistoryLeaving = false;
        const queued = modalHistoryQueued;
        modalHistoryQueued = null;
        if (queued) pushModalHistory(queued);
        return;
    }
    const hide = modalHistoryHide;
    if (!hide) return;
    modalHistoryHide = null;
    hide();
});

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
    pushModalHistory(hideQrModal);

    const canvas = overlay.querySelector('.qr-modal-canvas');
    ensureQrLib()
        .then(() => drawQrToCanvas(canvas, abs))
        .catch((e) => console.error('Не удалось построить QR:', e));
}

function hideQrModal() {
    if (qrModalEl) qrModalEl.classList.remove('is-visible');
    popModalHistory(hideQrModal);
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

    if (btn.dataset.share && navigator.share) {
        let title = '';
        try { title = decodeURIComponent(relative.split('/').pop()); } catch (err) {}
        navigator.share({ title, url: absolute }).catch(err => {
            // Закрыл меню - не ошибка; прочее (нет прав и т.п.) - хотя бы копируем
            if (!err || err.name !== 'AbortError') copyLink(btn, absolute);
        });
        return;
    }
    copyLink(btn, absolute);
});

function copyLink(btn, absolute) {
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
}

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
    pushModalHistory(hidePlayerModal);
}

function hidePlayerModal() {
    if (!playerModalEl) return;
    playerModalEl.classList.remove('is-visible');

    popModalHistory(hidePlayerModal);

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

// Строка очереди переезжает сразу, не дожидаясь ответа сервера и опроса после
// него (два запроса подряд - под нагрузкой это заметная пауза). Сервер потом
// присылает тот же порядок; ошибка или отказ - следующий опрос вернёт настоящий.
function moveQueueRowNow(row, dir) {
    const tbody = row && row.parentElement;
    if (!tbody) return;
    const sibling = dir === 'up' ? row.previousElementSibling : row.nextElementSibling;
    if (!sibling || !sibling.dataset.rowKey) return;
    tbody.insertBefore(dir === 'up' ? row : sibling, dir === 'up' ? sibling : row);
    const rows = Array.from(tbody.rows);
    rows.forEach((r, i) => {
        const up = r.querySelector('[data-reorder-dir="up"]');
        const down = r.querySelector('[data-reorder-dir="down"]');
        if (up) up.disabled = i === 0;
        if (down) down.disabled = i === rows.length - 1;
    });
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.reorder-btn');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    const dir = btn.getAttribute('data-reorder-dir');
    moveQueueRowNow(btn.closest('tr'), dir);
    queueReorderPending++;
    submitActionFetch({
        reorderQueue: btn.getAttribute('data-reorder-pid'),
        direction: dir
    }).then(() => {
        // Опрос, запущенный submitActionFetch после ответа, уже учтён в loadListSeq -
        // с него очередь снова рисуется по данным сервера
        queueFreshFromSeq = loadListSeq;
        queueReorderPending--;
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
    // "Не нажимать" в подвале - салют по требованию
    if (e.target.closest('[data-ui="confetti"]')) {
        fireConfetti();
        return;
    }
    // Имя готовой задачи в "Последних загрузках" - к самому файлу
    const toFile = e.target.closest('[data-goto-file]');
    if (toFile) {
        e.preventDefault();
        gotoFile(toFile.getAttribute('data-goto-file'), toFile.getAttribute('data-goto-kind'));
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
    for (const bucket of [data.videos, data.music]) {
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

// Имя файла -> где он лежит: вкладка ('v'/'m') и ключ его строки в таблице
// (тот же 'file:' + getFileKey, что уходит в data-row-key через keyFn).
let diskFileByName = new Map();

// Имя без хвоста формата и расширения: в истории задачи остаётся имя последнего
// скачанного потока ("x.f140.m4a", у Instagram "x.fdash-123a.m4a"), а на диске
// после склейки лежит "x.mp4".
function diskStem(name) {
    return String(name || '').trim().replace(/(?:\.f[a-z0-9]+(?:-[a-z0-9]+)*)?\.[a-z0-9]{2,4}$/i, '');
}

function collectDiskFileRows(data) {
    const map = new Map();
    for (const [kind, bucket] of [['v', data.videos], ['m', data.music]]) {
        for (const item of bucket || []) {
            if (!item.name) continue;
            const entry = { kind, key: 'file:' + getFileKey(item) };
            map.set(item.name, entry);
            const stem = kind + ':' + diskStem(item.name);
            if (!map.has(stem)) map.set(stem, entry);
        }
    }
    return map;
}

// Точное имя, иначе по основе имени - сначала в "своей" вкладке по типу задачи.
function findDiskFile(file, type) {
    const name = String(file || '').trim();
    if (!name) return null;
    const exact = diskFileByName.get(name);
    if (exact) return exact;
    const stem = diskStem(name);
    const order = type === 'audio' ? ['m', 'v'] : ['v', 'm'];
    for (const kind of order) {
        const hit = diskFileByName.get(kind + ':' + stem);
        if (hit) return hit;
    }
    return null;
}

// Из "Последних загрузок" к самому файлу: вкладка, прокрутка к строке и та же
// волна по имени, которой встречают только что появившийся файл.
function gotoFile(key, kind) {
    const nav = document.getElementById(kind === 'm' ? 'music_link' : 'vid_link');
    const tbody = document.getElementById(kind === 'm' ? 'musicfiles' : 'videofiles');
    if (!nav || !tbody) return;
    nav.click();
    // Вкладка меняется через View Transition - строку ищем, когда панель уже видна
    setTimeout(() => {
        const row = [...tbody.rows].find(r => r.dataset.rowKey === key);
        if (!row) return;
        const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        row.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
        const name = row.querySelector('.file-name, .file-name-plain');
        if (!name) return;
        name.classList.remove('row-name-wave-cell');
        void name.offsetWidth; // без перерисовки повторный класс не перезапустил бы анимацию
        name.classList.add('row-name-wave-cell');
        name.addEventListener('animationend', () => name.classList.remove('row-name-wave-cell'), { once: true });
    }, 350);
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

// Ответы ?jobs приходят не обязательно по порядку: плановый опрос, ушедший до
// нажатия, может вернуться позже опроса, запущенного самим действием. Старый ответ
// перерисовал бы таблицы назад (убранная строка возвращалась на полторы секунды)
// и откатил бы previousFinishedPids - звук и салют по готовой загрузке сыграли бы
// второй раз. Поэтому ответ старше уже показанного выбрасываем.
let loadListSeq = 0;
let loadListShownSeq = 0;
// Перестановка в очереди показывается сразу по нажатию (moveQueueRowNow). Пока
// сервер её не применил, и для ответов на запросы, ушедшие раньше, очередь не
// перерисовываем - иначе строка прыгала бы назад и обратно.
let queueReorderPending = 0;
let queueFreshFromSeq = 0;

// Плановый опрос читает снапшот, который пишет воркер: nginx отдаёт его без PHP,
// а неизменившийся - ответом 304 без тела. index.php?jobs (fresh) - после действия:
// он пинает воркер и ждёт его прохода, иначе таблица на секунду показала бы
// состояние до нажатия. Туда же уходим, когда снапшота нет или он протух - воркер
// трогает файл раз в 10 с, застывший дольше значит, что воркер не жив.
const SNAPSHOT_URL = 'state/jobs.json';
const SNAPSHOT_STALE_MS = 30000;
let snapshotEtag = null;
let snapshotData = null;
let freshInFlight = 0;

function fetchJobs(fresh) {
    if (fresh) return fetch('index.php?jobs').then(resp => resp.json());
    const headers = snapshotData && snapshotEtag ? { 'If-None-Match': snapshotEtag } : {};
    // no-store: 304 должен дойти до нас, а не раствориться в кэше браузера
    return fetch(SNAPSHOT_URL, { cache: 'no-store', headers }).then(resp => {
        const date = Date.parse(resp.headers.get('Date'));
        const modified = Date.parse(resp.headers.get('Last-Modified'));
        const usable = (resp.status === 200 || (resp.status === 304 && snapshotData))
            && date - modified < SNAPSHOT_STALE_MS; // NaN - тоже нет
        if (!usable) {
            snapshotEtag = null;
            snapshotData = null;
            return fetchJobs(true);
        }
        // Часы сервера для отсчёта очереди - из заголовка Date, в снапшоте их нет
        const now = Math.floor(date / 1000);
        if (resp.status === 304) return { ...snapshotData, now };
        return resp.json().then(data => {
            snapshotEtag = resp.headers.get('ETag');
            snapshotData = data;
            return { ...data, now };
        });
    });
}

// Вкладка, открытая до выкатки, живёт со старым JS. Снапшот несёт версию кода
// (build, см. JobsSnapshot::buildId): разошлась с BUILD_ID страницы - пора
// перезагрузиться. Скрытая вкладка перезагружается сразу, видимая - когда
// человек ничего не делает. Черновик поля ссылки переживает перезагрузку,
// вкладка сайта (#hash) и прокрутку браузер восстанавливает сам.
// ?noreload в адресе выключает механизм (отладка).
const RELOAD_IDLE_MS = 10000;
const RELOAD_STATE_KEY = 'yt_reload_state';
const autoReloadEnabled = typeof BUILD_ID === 'string' && BUILD_ID !== ''
    && !new URLSearchParams(location.search).has('noreload');
let pendingBuild = null;
let reloadedForBuild = null;
let lastUserActivity = 0;
let pointerHeld = false;

['pointerdown', 'keydown', 'input', 'wheel', 'touchstart'].forEach(type => {
    document.addEventListener(type, () => { lastUserActivity = Date.now(); }, { capture: true, passive: true });
});
document.addEventListener('pointerdown', () => { pointerHeld = true; }, true);
['pointerup', 'pointercancel'].forEach(type => {
    document.addEventListener(type, () => { pointerHeld = false; }, true);
});

function reloadBlocked() {
    if (pointerHeld || Date.now() - lastUserActivity < RELOAD_IDLE_MS) return true;
    return !!document.querySelector(
        '.qr-modal-overlay.is-visible, #quality-popup.is-visible, .selection-toolbar:not([hidden])');
}

function checkBuild(build) {
    if (!autoReloadEnabled || typeof build !== 'string' || build === '' || build === BUILD_ID) return;
    // На эту версию уже перезагружались, а страница всё равно старая (кэш по
    // дороге) - второй раз не дёргаем, иначе петля
    if (build === reloadedForBuild) return;
    pendingBuild = build;
    tryAutoReload();
}

function tryAutoReload() {
    if (pendingBuild === null || (!document.hidden && reloadBlocked())) return;
    try {
        sessionStorage.setItem(RELOAD_STATE_KEY, JSON.stringify({
            build: pendingBuild,
            draft: urlInput ? urlInput.value : '',
        }));
    } catch (e) {}
    pendingBuild = null;
    location.reload();
}

function restoreAfterReload() {
    let state = null;
    try {
        state = JSON.parse(sessionStorage.getItem(RELOAD_STATE_KEY) || 'null');
        sessionStorage.removeItem(RELOAD_STATE_KEY);
    } catch (e) {}
    if (!state || typeof state !== 'object') return;
    reloadedForBuild = typeof state.build === 'string' ? state.build : null;
    if (typeof state.draft === 'string' && state.draft !== '' && urlInput && !urlInput.value) {
        setUrlFieldValue(urlInput, state.draft);
    }
}

function loadList(fresh = false) {
    // Пока свежий ответ в пути, снапшот может быть ещё до действия - не читаем его.
    // Свежий ответ сам назначит следующий опрос.
    if (!fresh && freshInFlight) return;
    const seq = ++loadListSeq;
    if (fresh) freshInFlight++;
    fetchJobs(fresh)
        .finally(() => { if (fresh) freshInFlight--; })
        .then(function (data) {
        if (seq < loadListShownSeq) return;
        loadListShownSeq = seq;
        if (typeof data.now === 'number') serverClockSkew = data.now - Date.now() / 1000;
        hidePollErrorBadge();
        checkBuild(data.build);
        knownDownloadedUrls = collectKnownUrls(data);
        knownFileByUrl = collectDiskFiles(data);
        diskFileByName = collectDiskFileRows(data);
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
                if (!document.hidden) fireConfetti({ audio: newSuccess.some(item => item.type === 'audio') });
            }
            snejOnDone(newSuccess);

            // Плашкой зовём только при скрытой вкладке - иначе звука и таблицы хватает.
            if (document.hidden && (newSuccess.length || newFailure.length)) {
                showCompletionNotification(newSuccess, newFailure);
            }
        }

        previousFinishedPids = currentFinishedPids;
        snejOnJobs(data.jobs);
        snejOnStats(data.stats);

        renderTable(nativeUI.progress, data.jobs, 3, "Активных загрузок нет.", renderJobRow, `<button type="button" id="killallbutton" style="width: 100px;" class="btn btn-danger btn-xs" data-action="kill" data-value="all">Стоп ВСЕ</button>`,
            job => 'job:' + job.pid);

        if (!queueReorderPending && seq >= queueFreshFromSeq) renderTable(nativeUI.queue, data.queue, 3, "Очередь пуста.", renderQueueRow, `<button type="button" id="clearallbutton-queue" style="width: 160px;" class="btn btn-danger btn-xs" data-action="clear" data-value="queue">Очистить всё</button>`,
            item => 'queue:' + item.pid);

        // При непустой очереди говорим, что она идёт сама (её двигает воркер на
        // сервере) - иначе человек сидел бы с открытой вкладкой на всякий случай.
        const queueHint = document.getElementById('queue-hint');
        if (queueHint) queueHint.classList.toggle('is-hidden', !(data.queue && data.queue.length));

        // Где лежит файл задачи - полями записи, а не справкой изнутри генератора
        // строки: renderTable сравнивает данные, и строка перерисуется, когда
        // файл удалят или он истечёт по сроку.
        const finishedRows = (data.finished || []).map(item => {
            const disk = findDiskFile(item.file, item.type);
            return disk ? { ...item, diskKind: disk.kind, diskKey: disk.key } : item;
        });
        renderTable(nativeUI.completed, finishedRows, 2, "Завершенных загрузок нет.", item => renderFinishedRow(item, data.logURL), `<button type="button" id="clearallbutton-finished" style="width: 160px;" class="btn btn-danger btn-xs" data-action="clear" data-value="recent">Очистить всё</button>`,
            item => 'done:' + item.pid);

        // Пустые разделы не показываем: три таблицы с шапками над строками "пусто"
        // были шумом. "Текущие" остаются всегда - их пустая строка и сообщает,
        // что ничего не качается, но шапка колонок над ней не нужна.
        const queueSection = document.getElementById('queue-section');
        if (queueSection) queueSection.hidden = !(data.queue && data.queue.length);
        const recentSection = document.getElementById('recent-section');
        if (recentSection) recentSection.hidden = !(data.finished && data.finished.length);
        const progressHead = nativeUI.progress ? nativeUI.progress.closest('table').tHead : null;
        if (progressHead) progressHead.hidden = !(data.jobs && data.jobs.length);

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
            ? `<button type="button" style="width: 120px;" class="btn btn-danger btn-xs" data-action="clearDownloads" data-value="all" data-type="v">Удалить всё</button>` : "";
        const clearMusicFooter = (typeof allowFileDelete !== 'undefined' && allowFileDelete)
            ? `<button type="button" style="width: 120px;" class="btn btn-danger btn-xs" data-action="clearDownloads" data-value="all" data-type="m">Удалить всё</button>` : "";

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

        // retryPending - задача упала и ждёт отложенного авторетрея. В data.jobs её ещё
        // нет, но затихать нельзя: перезапуск делает сервер на входящем GET, и без
        // быстрого опроса он откладывается до следующего (12с), а на скрытой вкладке -
        // до возвращения человека, то есть не происходит вовсе.
        const isActive = (data.jobs && data.jobs.length > 0)
            || (data.queue && data.queue.length > 0)
            || !!data.retryPending;
        lastActiveState = isActive;
        // Звуки нужны только к финишу задачи - качаем их, когда задача появилась,
        // а не на каждом заходе
        if (isActive && !isMobileDevice && !isSoundMuted) preloadNotificationSounds();
        if (isActive && !fxOff) loadEggs().catch(() => {});

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
    refreshTimer = setTimeout(() => loadList(), delay);
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
    restoreAfterReload();
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

// Вкладка вернулась из bfcache (мобильные браузеры, "назад"): JS не
// перезапускался, данные и версия могли устареть - опрашиваем сразу.
window.addEventListener('pageshow', function (e) {
    if (e.persisted && nativeUI.progress) loadList();
});

document.addEventListener('visibilitychange', function () {
    // Ушли со вкладки, а новая версия уже ждёт - перезагружаемся, пока не смотрят
    if (document.hidden) tryAutoReload();
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

// Подвал на телефоне листается как колесо сейфа: грань карточки, уходящая под
// верхнюю кромку панели, отдаёт мягкий щелчок вибрацией. Только там, где есть
// Vibration API (Android); в Safari на iPhone его нет - там прокрутка тихая.
// Chrome пускает vibrate() лишь после касания страницы, а панель как раз
// открывают нажатием, так что к прокрутке разрешение уже есть.
(function initFooterDetents() {
    const panel = document.getElementById('helppanel');
    if (!panel || !isMobileDevice || typeof navigator.vibrate !== 'function') return;
    const TICK_MS = 10;     // короче - на части телефонов не ощущается, длиннее - уже жужжание
    const MIN_GAP_MS = 60;  // быстрый бросок - трещотка из щелчков, а не сплошной гул
    const MERGE_PX = 24;    // низ карточки и верх следующей - одна грань, а не двойной щелчок
    let lastPassed = -1;
    let lastTick = 0;

    // Сколько граней уже ушло выше кромки панели
    function passedEdges() {
        const top = panel.getBoundingClientRect().top + panel.clientTop;
        const edges = [];
        panel.querySelectorAll('.footer-card, .footer-useful').forEach(el => {
            const r = el.getBoundingClientRect();
            edges.push(r.top - top, r.bottom - top);
        });
        edges.sort((a, b) => a - b);
        let passed = 0;
        for (let i = 0; i < edges.length; ) {
            let j = i;
            while (j + 1 < edges.length && edges[j + 1] - edges[j] < MERGE_PX) j++;
            if ((edges[i] + edges[j]) / 2 <= 0) passed++;
            i = j + 1;
        }
        return passed;
    }

    panel.addEventListener('scroll', () => {
        const passed = passedEdges();
        if (lastPassed >= 0 && passed !== lastPassed) {
            const now = performance.now();
            if (now - lastTick >= MIN_GAP_MS) {
                lastTick = now;
                navigator.vibrate(TICK_MS);
            }
        }
        lastPassed = passed;
    }, { passive: true });
})();

document.addEventListener('DOMContentLoaded', function () {
    const faviconContainer = document.getElementById('url-favicon');
    const faviconImg = document.getElementById('url-favicon-img');
    const clearBtn = document.getElementById('url-clear');
    const urlInput = document.getElementById('url');
    const wrapper = document.querySelector('.url-input-wrapper');

    let inputTimer = null;
    const URL_FIELD_MAX_LINES = 6;
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
                    let v = urlInput.value.trim().split(URL_FIELD_SEP)[0].trim();
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
        autosizeUrlField();
        faviconContainer.classList.remove('is-visible');
        wrapper.classList.remove('has-favicon');
        clearBtn.classList.remove('is-visible');
        hideAudioAutoPrompt();
        urlInput.focus();
        setTimeout(() => { isClearing = false; }, 50);
    }

    // Поле растёт по строкам до URL_FIELD_MAX_LINES, дальше - прокрутка
    function autosizeUrlField() {
        // Мелкий кегль (is-compact), когда текст не влезает в строку обычным:
        // меряем без него, иначе поле не вернулось бы к крупному после правки
        urlInput.classList.remove('is-compact');
        urlInput.style.height = 'auto';
        const base = getComputedStyle(urlInput);
        const oneLine = parseFloat(base.lineHeight) + parseFloat(base.paddingTop) + parseFloat(base.paddingBottom);
        // Пустое поле - всегда одна строка обычным кеглем. Chrome считает в
        // scrollHeight и плейсхолдер: в узком поле он переносился, и пустое
        // поле после blur (ушли со страницы) мельчало и раздувалось по высоте.
        const hasText = urlInput.value.trim() !== '';
        if (hasText && (urlInput.value.trim().includes('\n') || urlInput.scrollHeight > oneLine + 1)) {
            urlInput.classList.add('is-compact');
        }
        const cs = getComputedStyle(urlInput);
        const border = parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth);
        const max = parseFloat(cs.lineHeight) * URL_FIELD_MAX_LINES
            + parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom) + border;
        const full = (hasText ? urlInput.scrollHeight : oneLine) + border;
        urlInput.style.height = Math.min(full, max) + 'px';
        urlInput.style.overflowY = full > max + 1 ? 'auto' : 'hidden';
    }

    function checkUrl() {
        autosizeUrlField();
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

    // Разбор многострочного текста (paste/drag-and-drop): дедуп, по ссылке на строку.
    function mergeUrlLines(text) {
        const existing = urlInput.value.trim();
        const incoming = text.split(/\r\n|\r|\n/).map(s => s.trim()).filter(Boolean);
        const combined = existing ? existing.split(URL_FIELD_SEP).map(s => s.trim()).filter(Boolean).concat(incoming) : incoming;
        const seen = new Set();
        return combined.filter(u => {
            const key = u.toLowerCase();
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        }).join('\n');
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
        autosizeUrlField();
        clearTimeout(inputTimer);
        inputTimer = setTimeout(checkUrl, INPUT_DELAY);
    });
    // Поле многострочное, но Enter по-прежнему отправляет, как в обычном input;
    // новая строка - Shift+Enter (списки и так приходят вставкой).
    // Пустое поле с доступным буфером обрабатывает магия вставки ниже.
    urlInput.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey || e.isComposing) return;
        e.preventDefault();
        if (!urlInput.value.trim() && navigator.clipboard && navigator.clipboard.readText) return;
        const form = urlInput.form;
        if (!form) return;
        if (form.requestSubmit) form.requestSubmit();
        else form.querySelector('[type="submit"]')?.click();
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

    // Сервер после ошибки возвращает поле склеенным через "||" - раскладываем по строкам
    if (urlInput.value.includes('||')) urlInput.value = normalizeUrlField(urlInput.value);
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
    initQuietEasterEggs();
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
    banner.className = 'site-update-banner';
    banner.innerHTML = `
        <span>Сайт обновили с прошлого визита ✨</span>
        <button type="button" class="site-update-banner-close" aria-label="Закрыть">&times;</button>`;
    document.body.insertBefore(banner, document.body.firstChild);
    // Плашка висит над содержимым: закрывается кликом в любом месте и сама
    // через несколько секунд
    const close = () => {
        if (!banner.isConnected) return;
        banner.classList.add('is-leaving');
        setTimeout(() => banner.remove(), 300);
    };
    banner.addEventListener('click', close);
    setTimeout(close, 6000);
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
        urlError.classList.remove('is-hidden', 'is-info');
        if (urlWrapper) urlWrapper.classList.add('has-error');
    }

    // Та же строка, но не ошибка: поле не краснеет
    function showUrlHint(message) {
        if (!urlError) return;
        urlError.textContent = message;
        urlError.classList.remove('is-hidden');
        urlError.classList.add('is-info');
        if (urlWrapper) urlWrapper.classList.remove('has-error');
    }

    function hideUrlError() {
        if (!urlError) return;
        urlError.classList.add('is-hidden');
        if (urlWrapper) urlWrapper.classList.remove('has-error');
    }

    // Что сервер сделает с этими ссылками прямо сейчас: очередь, пауза YouTube,
    // недавний отказ хоста, полный диск - то, чего фронт сам не знает. Запрос -
    // HTTP QUERY (см. index.php). Не вышло - молчим: это подсказка, отправку она
    // не держит и не заменяет.
    let preflightTimer = null;
    let preflightAbort = null;

    function cancelPreflight() {
        clearTimeout(preflightTimer);
        if (preflightAbort) preflightAbort.abort();
        preflightAbort = null;
    }

    function runPreflight() {
        const value = urlField.value;
        const parsed = validateUrlField(value);
        // Кривые ссылки - забота проверки на отправке, её текст точнее
        if (!parsed.urls.length || parsed.bad.length) return;
        preflightAbort = new AbortController();
        fetch('index.php?preflight', {
            method: 'QUERY',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ urls: parsed.urls }),
            signal: preflightAbort.signal,
        })
            .then(resp => resp.ok ? resp.json() : null)
            .then(result => {
                if (!result || urlField.value !== value) return;
                const note = result.message ? null : videoNote(parsed.urls);
                if (note) showUrlHint(note);
                else if (!result.message) hideUrlError();
                else if (result.level === 'error') showUrlError(result.message);
                else showUrlHint(result.message);
            })
            .catch(() => {});
    }

    urlField.addEventListener('input', function () {
        hideUrlError();
        cancelPreflight();
        preflightTimer = setTimeout(runPreflight, 400);
    });

    downloadForm.addEventListener('submit', function (e) {
        cancelPreflight();
        // Ссылку разбираем прямо тут: обработчик вставки и так её парсит ради
        // фавикона, поэтому за ответом "ссылка кривая" незачем ходить на сервер
        // и получать полную перезагрузку страницы.
        const parsed = validateUrlField(urlField.value);
        if (parsed.bad.length) {
            e.preventDefault();
            const joke = parsed.urls.length ? null : fieldJoke(urlField.value);
            if (joke) {
                showUrlError(joke.text);
                joke.react();
                urlField.focus();
                return;
            }
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
            setUrlFieldValue(urlField, '');
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
                    <table class="table playlist-modal-table" role="presentation">
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
    initPlaylistDragSelect(overlay, rows);
    rows.addEventListener('click', (e) => {
        // Мышь и палец уже отработаны протяжкой на pointerdown/touch - тут
        // остаются клики без указателя (Enter на строке, экранный диктор)
        if (e.detail !== 0) return;
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

    csrfFetch(body, { 'X-Requested-With': 'fetch', 'Accept': 'application/json' })
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

// Выбор протяжкой: нажал на строку и повёл - все строки по пути получают то
// состояние, которое получила первая (выбрать или снять пачкой). Короткое
// нажатие - обычное переключение одной строки.
//
// Мышь и перо - сразу. Палец - после удержания: без него протяжка отняла бы у
// списка прокрутку, а плейлист в сотню роликов без прокрутки не пролистать.
const PLAYLIST_TOUCH_HOLD_MS = 350;
const PLAYLIST_TOUCH_SLOP = 8;
const PLAYLIST_EDGE_PX = 36;

function initPlaylistDragSelect(overlay, rows) {
    const list = overlay.querySelector('.playlist-modal-list');
    let drag = null;
    let holdTimer = null;
    let touchStart = null;
    let edgeRaf = null;

    function selectableIdx(row) {
        if (!row || !rows.contains(row)) return null;
        const idx = parseInt(row.dataset.rowIdx, 10);
        const item = playlistState.frozenItems[idx];
        return item && item.available !== false ? idx : null;
    }

    function rowAt(x, y) {
        const el = document.elementFromPoint(x, y);
        return el ? el.closest('tr[data-row-idx]') : null;
    }

    function paint(toIdx) {
        if (toIdx === drag.last) return;
        drag.last = toIdx;
        const lo = Math.min(drag.anchor, toIdx);
        const hi = Math.max(drag.anchor, toIdx);
        // От снимка на момент нажатия: повёл назад - отпущенные строки
        // возвращаются в прежнее состояние, а не остаются закрашенными
        const selected = new Set(drag.before);
        for (let i = lo; i <= hi; i++) {
            const item = playlistState.frozenItems[i];
            if (!item || item.available === false) continue;
            const key = playlistItemKey(item, i);
            if (drag.select) selected.add(key); else selected.delete(key);
        }
        playlistState.selectedIds = selected;
        reapplyPlaylistSelection();
        updatePlaylistBar();
    }

    function begin(idx) {
        const key = playlistItemKey(playlistState.frozenItems[idx], idx);
        drag = {
            anchor: idx,
            last: null,
            before: new Set(playlistState.selectedIds),
            select: !playlistState.selectedIds.has(key),
            x: 0,
            y: 0,
        };
        paint(idx);
        haptic('tick');
    }

    // Список прокручивается сам, пока указатель у его верхнего или нижнего края
    function edgeScroll() {
        edgeRaf = null;
        if (!drag) return;
        const box = list.getBoundingClientRect();
        let dy = 0;
        if (drag.y < box.top + PLAYLIST_EDGE_PX) dy = -Math.ceil((box.top + PLAYLIST_EDGE_PX - drag.y) / 3);
        else if (drag.y > box.bottom - PLAYLIST_EDGE_PX) dy = Math.ceil((drag.y - box.bottom + PLAYLIST_EDGE_PX) / 3);
        if (!dy) return;
        list.scrollTop += dy;
        const y = Math.min(Math.max(drag.y, box.top + 1), box.bottom - 1);
        const idx = selectableIdx(rowAt(drag.x, y));
        if (idx !== null) paint(idx);
        edgeRaf = requestAnimationFrame(edgeScroll);
    }

    function move(x, y) {
        drag.x = x;
        drag.y = y;
        const idx = selectableIdx(rowAt(x, y));
        if (idx !== null) paint(idx);
        if (!edgeRaf) edgeRaf = requestAnimationFrame(edgeScroll);
    }

    function end() {
        drag = null;
        if (edgeRaf) cancelAnimationFrame(edgeRaf);
        edgeRaf = null;
    }

    rows.addEventListener('pointerdown', (e) => {
        if (e.pointerType === 'touch' || e.button !== 0) return;
        const row = e.target.closest('tr[data-row-idx]');
        const idx = selectableIdx(row);
        if (idx === null) return;
        e.preventDefault(); // без этого браузер начнёт выделять текст
        row.focus({ preventScroll: true }); // и не отдаст строке фокус для стрелок
        rows.setPointerCapture(e.pointerId);
        begin(idx);
    });
    rows.addEventListener('pointermove', (e) => {
        if (drag && e.pointerType !== 'touch') move(e.clientX, e.clientY);
    });
    rows.addEventListener('pointerup', end);
    rows.addEventListener('pointercancel', end);
    rows.addEventListener('lostpointercapture', end);

    rows.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1) return;
        const t = e.touches[0];
        const idx = selectableIdx(e.target.closest('tr[data-row-idx]'));
        if (idx === null) return;
        touchStart = { x: t.clientX, y: t.clientY, idx, moved: false };
        clearTimeout(holdTimer);
        holdTimer = setTimeout(() => {
            holdTimer = null;
            if (touchStart && !touchStart.moved) begin(touchStart.idx);
        }, PLAYLIST_TOUCH_HOLD_MS);
    }, { passive: true });

    // Не passive: после удержания движение пальца - протяжка, и прокрутку
    // списка надо гасить. До удержания событие не трогаем - это прокрутка.
    rows.addEventListener('touchmove', (e) => {
        const t = e.touches[0];
        if (!t) return;
        if (drag) {
            e.preventDefault();
            move(t.clientX, t.clientY);
            return;
        }
        if (touchStart && Math.hypot(t.clientX - touchStart.x, t.clientY - touchStart.y) > PLAYLIST_TOUCH_SLOP) {
            touchStart.moved = true;
            clearTimeout(holdTimer);
            holdTimer = null;
        }
    }, { passive: false });

    rows.addEventListener('touchend', (e) => {
        // Короткое касание без движения - обычное переключение строки. Клик,
        // который браузер пришлёт следом, отсекается проверкой e.detail.
        if (holdTimer && touchStart && !touchStart.moved) {
            clearTimeout(holdTimer);
            holdTimer = null;
            togglePlaylistRow(touchStart.idx);
            e.preventDefault();
        }
        touchStart = null;
        end();
    });
    // Android на удержание открывает контекстное меню поверх протяжки
    rows.addEventListener('contextmenu', (e) => {
        if (e.target.closest('tr[data-row-idx]')) e.preventDefault();
    });
    rows.addEventListener('touchcancel', () => {
        clearTimeout(holdTimer);
        holdTimer = null;
        touchStart = null;
        end();
    });
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

// Запись в поле из кода не порождает input, а на нём висит вся свита поля:
// фавикон, крестик очистки, отступ под иконку. Без события они оставались от
// уже отправленной ссылки - поле пустое, а иконка сервиса всё ещё горит.
function setUrlFieldValue(field, value) {
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

function sendDownloadForm(form, urlField) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    // Поле очищается раньше, чем дело дойдёт до реплики Снежика
    const sentUrls = String(urlField.value || '').split('||');

    csrfFetch(new FormData(form), {
        'X-Requested-With': 'fetch',
        // Accept - второй маркер для сервера на случай, если свой заголовок
        // где-то потеряется по дороге (см. $wantsJson в index.php)
        'Accept': 'application/json',
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
            setUrlFieldValue(urlField, result.keepInField || '');

            // Короткий тик - ссылка принята, длинный рисунок ошибки - не принята.
            haptic((result.errors || []).length ? 'error' : 'tick');

            const errorBox = document.getElementById('url-error');
            if (errorBox) {
                if ((result.errors || []).length) {
                    errorBox.textContent = result.errors[0];
                    errorBox.classList.remove('is-hidden', 'is-info');
                } else {
                    errorBox.classList.add('is-hidden');
                }
            }

            // Раньше сюда приводил редирект на #downloads - оставляем тот же
            // переход, но только когда действительно что-то ушло качаться.
            // Опрос дёргаем всегда, а не только при успехе: даже отклонённая
            // отправка могла изменить очередь, а следующий плановый опрос в покое
            // приходит через 12 секунд - всё это время таблицы врут.
            if (typeof loadList === 'function') loadList(true);

            // Переход на Загрузки, как делал прежний редирект. Сводка при этом не
            // теряется: её разметка лежит вне вкладок, под навигацией.
            if (result.started || result.queued) {
                snejOnSubmit(sentUrls);
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
                errorBox.classList.remove('is-hidden', 'is-info');
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
            fireSnejLaser(snejDiv, countSnejShot() % SNEJ_LUCKY_SHOT === 0);
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

function fireSnejLaser(snejDiv, forceRare = false) {
    const isRare = forceRare || Math.random() < SNEJ_RARE_CHANCE;
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

// Тихие пасхалки: ни одна не меняет вид страницы, пока её не нашли.
// Сверяем e.code - физическую клавишу: на русской раскладке e.key у B и A это "и" и "ф".
const KONAMI_CODE = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'KeyB', 'KeyA'];
// Имя снегиря латиницей вслепую, вне полей ввода. Тоже по клавишам, не по буквам.
const SNEJ_NAME_CODE = ['KeyS', 'KeyN', 'KeyE', 'KeyJ'];
// Каждый N-й выстрел за всё время - гарантированно редкий. Счётчик в localStorage.
const SNEJ_LUCKY_SHOT = 10;
const SNEJ_SHOTS_KEY = 'snejShots';

// Реплики Снежика. Цепляются за обычные действия - отправку, готовую загрузку,
// долгое ожидание, - поэтому находятся сами. Редкость держит их сюрпризом:
// ситуативная реплика - раз в сутки, остальные - по жребию.
const NIGHT_LINES = [
    'Качаю. А ты ложись, утром будет готово.',
    'Снежик тоже не спит. Ему положено.',
    'Ночью качается быстрее. Наверное.',
];

const DATE_LINES = {
    '01-01': 'С Новым годом. Снежик не выспался.',
    '01-07': 'С Рождеством. Снежик доедает оливье.',
    '01-25': 'Татьянин день. Студентам - видео вместо лекций.',
    '02-23': 'С 23 Февраля. Носки не дарим, видео качаем.',
    '02-29': 'Такой день бывает раз в четыре года. Качай, пока можно.',
    '03-08': 'С 8 Марта! Мимоза не скачивается, а видео - да.',
    '03-14': 'День числа π. Прогресс-бар тоже иррационален.',
    '04-01': 'Сегодня всё скачается с первого раза. Шутка.',
    '04-12': 'Поехали!',
    '05-01': 'Мир, труд, май, скачать.',
    '05-04': 'May the 4th be with you.',
    '05-07': 'День радио. Попов передаёт привет yt-dlp.',
    '05-25': 'День полотенца. Без паники.',
    '08-02': 'Фонтаны сегодня заняты. Качаем дома.',
    '09-01': 'День знаний. Учиться никогда не поздно, качать - тем более.',
    '09-03': 'Третье сентября. Календарь переворачивать не надо, он сам.',
    '10-31': 'Сегодня Снежик - летучая мышь. Снаружи не видно.',
    '12-31': 'Оливье нарезан? Тогда можно и покачать.',
};

// Отечественные площадки - реплика по жребию, не каждый раз
const RU_HOSTS = /(?:^|\.)(?:rutube\.ru|vk\.com|vkvideo\.ru|vk\.ru|ok\.ru|dzen\.ru|yandex\.ru|my\.mail\.ru)$/i;
const RU_HOST_LINES = [
    'Отечественный производитель. Уважаю.',
    'Импортозамещение одобрено Снежиком.',
    'Своё, родное. Качаю бережно.',
];
const RU_HOST_CHANCE = 0.2;

const MUSIC_LINES = [
    'Опять на repeat поставишь?',
    'Хорошая песня. Наверное. Я снегирь, мне всё нравится.',
    'Громкость на максимум - соседи поймут.',
];
const MUSIC_CHANCE = 0.08;

// Вехи общего счёта сервера (stats в ?jobs, Downloader::downloadStats). Трек
// в среднем 3,5 минуты - отсюда поезда.
const STATS_MILESTONES = {
    videos: {
        100: 'Сотое видео на этой Качалке. Снежик гордится.',
        1000: 'Тысячное видео. Снежику пора давать зарплату.',
        10000: 'Десять тысяч видео. Это уже не качалка, а Госфильмофонд.',
    },
    tracks: {
        70: 'Музыки накачали уже на Сапсан до Питера.',
        400: 'Музыки хватит на поезд Москва - Сочи.',
        2900: 'Музыки - на Москва - Владивосток без повторов.',
    },
    bytes: {
        700e6: 'Набралась целая болванка CD. Раньше на такую «Бригаду» писали.',
        4.7e9: 'Целый DVD. Можно подписывать маркером.',
        100e9: 'Сто гигов. Как весь жёсткий диск в 2005-м.',
        1e12: 'Терабайт! Снежик зауважал.',
        10e12: 'Десять терабайт. Где-то тихо плачет один провайдер.',
    },
};
const SNEJ_LONG_PRESS_MS = 700;

const LONG_JOB_MS = 10 * 60 * 1000;
const LONG_JOB_LINES = [
    'Долго качается. Снежик не спит, Снежик бдит.',
    'Большой файл попался. Чайник поставь.',
    'Качается. Медленно, но по-русски основательно.',
];

const RETURN_GAP_MS = 14 * 24 * 60 * 60 * 1000;
const LAST_VISIT_KEY = 'snejLastVisit';

// Готово одним файлом в плашке ОС - изредка говорим по-другому
const DONE_TITLES = ['Готово, лови', 'Лови, горяченькое', 'Готово. Снежик старался', 'Скачал. С тебя чай'];
const DONE_TITLE_CHANCE = 0.25;

function pickRandom(list) {
    return list[Math.floor(Math.random() * list.length)];
}

function storageGet(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
}

function storageSet(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
}

// Ключ сегодняшнего дня по местному времени
function todayKey(now) {
    return now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
}

// true один раз за сутки на ключ. Без хранилища - false: лучше промолчать,
// чем повторять одно и то же на каждой отправке.
function onceToday(key, now) {
    const full = 'snejOnce:' + key;
    const today = todayKey(now);
    try {
        if (localStorage.getItem(full) === today) return false;
        localStorage.setItem(full, today);
        return true;
    } catch (e) {
        return false;
    }
}

function specialDayLine(now) {
    // День программиста - 256-й день года (0x100), 12 или 13 сентября
    const dayOfYear = Math.round((new Date(now.getFullYear(), now.getMonth(), now.getDate()) - new Date(now.getFullYear(), 0, 0)) / 86400000);
    if (dayOfYear === 256) return 'С Днём программиста. 256-й день, он же 0x100.';
    if (now.getDate() === 13 && now.getDay() === 5) return 'Пятница, 13-е. Ничего не обещаю.';
    const key = String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    return DATE_LINES[key] || null;
}

// Ситуация дня: возвращает [ключ, реплика] или null. Праздник важнее буднего
// ритма, будни важнее ночи.
function situationLine(now) {
    const special = specialDayLine(now);
    if (special) return ['date', special];
    const hour = now.getHours();
    const day = now.getDay();
    const date = now.getDate();
    if (day >= 1 && day <= 5 && hour === 13) return ['lunch', 'Приятного аппетита. Пока жуёшь - докачаю.'];
    if (day === 5 && hour >= 17) return ['friday', 'Сериал на выходные? Одобряю.'];
    if (day === 1 && hour < 12) return ['monday', 'Понедельник. Тяжёлый день - тяжёлые файлы.'];
    if (date === 10 || date === 25) return ['payday', 'Аванс пришёл? А видео всё равно бесплатно.'];
    if (hour >= 1 && hour < 5) return ['night', pickRandom(NIGHT_LINES)];
    return null;
}

// Снежик говорит через тот же тост, что и сводка массовой загрузки
function snejSays(text) {
    showBulkDownloadSummary(text);
    snejWink();
}

// После принятой отправки. Не больше одной реплики за раз.
function snejOnSubmit(urls) {
    const now = new Date();
    const situation = situationLine(now);
    if (situation && onceToday(situation[0], now)) {
        snejSays(situation[1]);
        return;
    }
    const ruHost = urls.some(u => {
        try { return RU_HOSTS.test(new URL(u).hostname); } catch (e) { return false; }
    });
    if (ruHost && Math.random() < RU_HOST_CHANCE) snejSays(pickRandom(RU_HOST_LINES));
}

// Новые готовые загрузки из опроса
function snejOnDone(newSuccess) {
    if (newSuccess.some(item => item.type === 'audio') && Math.random() < MUSIC_CHANCE) {
        snejSays(pickRandom(MUSIC_LINES));
    }
}

// Общий счёт из опроса. Первый опрос только запоминает точку отсчёта: веху
// услышит тот, при ком её перешагнули, а не каждый открывший страницу.
// Счёт назад не ходит - уменьшение (файл не прочитался) просто пропускаем.
let lastStats = null;

function snejOnStats(stats) {
    if (!stats) return;
    const prev = lastStats;
    lastStats = prev ? {
        videos: Math.max(prev.videos, stats.videos),
        tracks: Math.max(prev.tracks, stats.tracks),
        bytes: Math.max(prev.bytes, stats.bytes),
    } : { ...stats };
    if (!prev) return;
    for (const key of Object.keys(STATS_MILESTONES)) {
        // Пачка могла перешагнуть веху, а не попасть в неё ровно
        const hit = Object.keys(STATS_MILESTONES[key]).map(Number)
            .filter(m => m > prev[key] && m <= lastStats[key]).pop();
        if (hit) {
            snejSays(STATS_MILESTONES[key][hit]);
            return;
        }
    }
}

function formatStatsBytes(bytes) {
    if (bytes >= 1e12) return (bytes / 1e12).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' ТБ';
    return (bytes / 1e9).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' ГБ';
}

// Скрытая сводка: долгое нажатие на Снежика или snej.stats() в консоли
function statsSummary() {
    if (!lastStats) return 'Снежик ещё считает. Загляни через пару секунд.';
    const { videos, tracks, bytes } = lastStats;
    const n = v => v.toLocaleString('ru-RU');
    const dvd = Math.floor(bytes / 4.7e9);
    const hours = Math.round(tracks * 3.5 / 60);
    let text = `Качалка скачала: видео - ${n(videos)}, треков - ${n(tracks)}, всего ${formatStatsBytes(bytes)}.`;
    if (dvd >= 1) text += ` Это ${n(dvd)} DVD.`;
    if (hours >= 1) text += ` Музыки на ${n(hours)} ч.`;
    return text;
}

// Загрузка висит дольше LONG_JOB_MS, пока на неё смотрят - Снежик подаёт
// голос один раз на задачу. Время старта сервер не отдаёт, считаем с момента,
// когда задачу впервые увидели на этой странице.
const jobFirstSeen = new Map();
const jobLongNoticed = new Set();

function snejOnJobs(jobs) {
    const now = Date.now();
    const alive = new Set();
    for (const job of jobs || []) {
        const pid = String(job.pid);
        alive.add(pid);
        if (!jobFirstSeen.has(pid)) jobFirstSeen.set(pid, now);
        if (!document.hidden && !jobLongNoticed.has(pid) && now - jobFirstSeen.get(pid) >= LONG_JOB_MS) {
            jobLongNoticed.add(pid);
            snejSays(pickRandom(LONG_JOB_LINES));
        }
    }
    for (const pid of jobFirstSeen.keys()) {
        if (!alive.has(pid)) { jobFirstSeen.delete(pid); jobLongNoticed.delete(pid); }
    }
}

// Первый заход после долгого перерыва
function snejOnVisit() {
    const now = Date.now();
    const last = parseInt(storageGet(LAST_VISIT_KEY), 10) || 0;
    storageSet(LAST_VISIT_KEY, String(now));
    if (last && now - last >= RETURN_GAP_MS) {
        setTimeout(() => snejSays('Давно не виделись. Снежик скучал.'), 1500);
    }
}

function fireSnejFromEgg() {
    const snejDiv = document.querySelector('#snej');
    if (!snejDiv || snejDiv.classList.contains('snej-laser-active')) return false;
    fireSnejLaser(snejDiv, true);
    return true;
}

// Номер выстрела за всё время. Хранилище недоступно - считаем, что выстрел
// первый: пасхалка просто не сработает, остальное не заметит.
function countSnejShot() {
    try {
        const n = (parseInt(localStorage.getItem(SNEJ_SHOTS_KEY), 10) || 0) + 1;
        localStorage.setItem(SNEJ_SHOTS_KEY, String(n));
        return n;
    } catch (e) {
        return 1;
    }
}

// Слушает последовательность клавиш вне полей ввода и зовёт action на её конце.
function listenKeySequence(codes, action) {
    let pos = 0;
    document.addEventListener('keydown', (e) => {
        const t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        if (e.code === codes[pos]) {
            pos++;
            if (pos === codes.length) {
                pos = 0;
                action();
            }
        } else {
            pos = e.code === codes[0] ? 1 : 0;
        }
    });
}

// Долгое нажатие на Снежика показывает сводку. Клик, который придёт следом,
// гасим, чтобы он не засчитался в счётчик лазера.
function initSnejLongPress() {
    const hit = document.querySelector('#snej-click .snej-hit-area');
    if (!hit) return;
    let timer = null;
    let fired = false;
    const cancel = () => { clearTimeout(timer); timer = null; };
    hit.addEventListener('pointerdown', () => {
        fired = false;
        cancel();
        timer = setTimeout(() => {
            fired = true;
            snejSays(statsSummary());
        }, SNEJ_LONG_PRESS_MS);
    });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(evt => hit.addEventListener(evt, cancel));
    // На телефоне долгое нажатие иначе открывает меню
    hit.addEventListener('contextmenu', e => e.preventDefault());
    hit.addEventListener('click', e => {
        if (!fired) return;
        fired = false;
        e.stopImmediatePropagation();
        e.preventDefault();
    }, true);
}

function initQuietEasterEggs() {
    // Konami-код стреляет сразу редким лазером, без 30 кликов
    listenKeySequence(KONAMI_CODE, fireSnejFromEgg);
    // Позвать по имени - Снежик откликается сальтухой
    listenKeySequence(SNEJ_NAME_CODE, snejWink);

    initSnejLongPress();

    // Для тех, кто открыл DevTools
    window.snej = () => (fireSnejFromEgg() ? 'пиу' : 'перезаряжаюсь');
    window.snej.stats = () => statsSummary();
    console.log(
        '%c  (o>\n  //\\\n  V_/_%c  Снежик на связи.\n\nНашёл баг - напиши в "Жмак". Хочешь пострелять - snej()\nГоворят, есть ещё код из восьмидесятых.',
        'color:#c0392b;font-weight:bold', 'color:inherit'
    );

    snejOnVisit();
    // Картинка для пролёта Снежика в салюте - заранее, она уже в кэше
}

// Ответы на то, что ссылкой не является, но набрано явно нарочно.
// Сверяется со всем полем: разбор на ссылки режет команду на куски.
// Порядок важен: частные случаи выше общих (sandwich выше sudo).
const FIELD_JOKES = [
    [/^sudo\s+make\s+me\s+a\s+sandwich/i, 'Ладно.'],
    [/^make\s+me\s+a\s+sandwich/i, 'Сделай сам.'],
    [/^sudo\b/i, 'sudo не поможет: тут никто не рут, даже я.'],
    [/^rm\s+-(?:[a-z]*r[a-z]*f|[a-z]*f[a-z]*r)\b/i, 'Хорошая попытка.'],
    [/^git\s+push\s+(?:-f|--force)\b/i, 'Только не в пятницу.'],
    [/^:(?:q|wq|x)!?$/i, 'Это не vim. Хотя выйти отсюда тоже непросто.'],
    [/^(?:exit|quit|logout)$/i, 'Выход там же, где вход: крестик у вкладки.'],
    [/^(?:ls|dir)$/i, 'Тут не терминал. Файлы - во вкладках ниже.'],
    [/^ping(?:\s|$)/i, 'pong'],
    [/^xyzzy$/i, 'Ничего не произошло.'],
    [/<script/i, 'XSS? Тут всё экранируется. Но попытка засчитана.'],
    [/drop\s+table|'\s*or\s+'?1'?\s*=\s*'?1/i, 'Бобби Таблс, ты? Базы тут нет вообще.'],
    [/^(?:снежик|снежок|snej)[!.?]*$/iu, 'Я тут. Ссылку давай.'],
    [/^(?:помощь|справка|help)[!.?]*$/iu, 'Помощь внизу, в «Жмак».'],
    [/^(?:спасибо|thanks|thank you)(?![\p{L}\d])/iu, 'Пожалуйста. Обращайся, но со ссылкой.'],
    [/^(?:test|тест)[!.?]*$/iu, 'Тест пройден. Теперь ссылку.'],
    [/^(?:asdf|qwerty|йцукен|фыва)/iu, 'Клавиатура в порядке, проверил.'],
    [/^(?:семки\s+есть|есть\s+ч[ёео]|есть\s+закурить)/iu, 'А если найду?'],
    [/почта\s+росс/iu, 'Ваше видео прибыло в сортировочный центр. Шутка: тут качается сразу.'],
    [/^(?:авось|на\s+авось)/iu, 'На авось - это без ссылки. С ссылкой надёжнее.'],
    [/^(?:халява|халявы)/iu, 'Халява, приди! Пришла. Ссылку только дай.'],
    [/^бесплатно/iu, 'Бесплатно только сыр в мышеловке. И эта качалка.'],
    [/^(?:а\s+)?можно[?!.]*$/iu, 'Можно. Со ссылкой - тем более.'],
    [/^надо[,\s]+федя/iu, 'Надо, Федя. Надо. Ссылку.'],
    [/заливная\s+рыба|какая\s+гадость/iu, 'Какая гадость эта ваша заливная рыба. Видео получше будет.'],
    [/^ну[,\s]+погоди/iu, 'Погожу. Но недолго: ссылку давай.'],
    [/^(?:красота|благодать)[!.]*$/iu, 'Лепота! Красота-то какая. Только ссылки нет.'],
    [/^(?:я\s+)?(?:устал|ухожу)/iu, 'Отдохни. Чай, плед, видео - его скачаю я.'],
    [/^(?:бабушка|бабуля)/iu, 'Бабушка сказала, что ты похудел. Скачай ей сериал.'],
    [/^(?:дача|на\s+дачу)/iu, 'На даче интернета нет. Качай заранее.'],
    [/^(?:шапку\s+надень|надень\s+шапку)/iu, 'Надел. Снежик без шапки зимой не выходит.'],
    [/^(?:чай|чаю)[!.?]*$/iu, 'Чайник уже на плите. Пока закипает - давай ссылку.'],
    [/^(?:всё\s+пропало|гипс\s+снимают)/iu, 'Клиент уезжает! Спокойно, видео не пропадёт.'],
    [/^(?:превед|медвед)[!.]*$/iu, 'Превед, медвед! Ссылку давай.'],
    [/^абырвалг[!.]*$/iu, 'Главрыба. Шариков, ссылку!'],
    [/славянский\s+шкаф/iu, 'Шкаф продан. Могу предложить никелированную кровать.'],
    [/^(?:пароль|password)[!.?]*$/iu, 'У вас продаётся славянский шкаф?'],
    [/^где\s+деньги/iu, 'Денег нет, но вы держитесь.'],
    [/^кто\s+виноват/iu, 'Провайдер. Всегда провайдер.'],
    [/^что\s+делать/iu, 'Вставить ссылку. Чернышевский бы одобрил.'],
    [/^тварь\s+дрожащая/iu, 'Право имеешь. Качай.'],
    [/^поехали[!.]*$/iu, 'Гагарин одобряет. Ссылку бы ещё.'],
    [/^ур+а+[!.]*$/iu, 'Ура! А теперь ссылку.', () => fireConfetti()],
    [/^вжух[!.]*$/iu, 'Вжух - и ничего. Магия работает только со ссылкой.'],
    [/^ал+[оё][!.?]*$/iu, 'Алло, это Снежик. Ссылку диктуйте.'],
    [/^(?:ау+|есть\s+кто)(?![\p{L}\d])/iu, 'Есть. Снежик. Он тут всегда.'],
    [/^ты\s+кто/iu, 'Снегирь. Качаю видео. А ты?'],
    [/^(?:кек|лол|ахах\p{L}*|хаха\p{L}*)[!.)]*$/iu, 'Смешно. Ссылку тоже давай.'],
    [/^ой\s+вс[её][!.]*$/iu, 'Не всё. Ссылку забыл.'],
    [/^бобр[!.]*$/iu, 'Бобр, курва! Но ссылку всё равно давай.'],
    [/^(?:кот|котик|котики)[!.]*$/iu, 'Котиков качаем охотно. Ссылку на котика давай.'],
    [/^погода(?![\p{L}\d])/iu, 'Погоду не качаю. Посмотри в окно.'],
    [/^(?:ссылка|ссылку|ссыль)[!.]*$/iu, 'Не слово «ссылка», а саму ссылку: адрес из строки браузера.'],
    [/^давай[!.]*$/iu,'Давай ты первый: ссылку.'],
    [/^(?:привет|здравствуй|hello|hi)(?![\p{L}\d])/iu, 'Привет. А ссылку?'],
];

// Третий элемент записи - своя реакция вместо сальтухи Снежика.
function fieldJoke(value) {
    const v = String(value ?? '').trim();
    const hit = FIELD_JOKES.find(([re]) => re.test(v));
    return hit ? { text: hit[1], react: hit[2] || snejWink } : null;
}

// Подписи к особым роликам. Качать не мешают - это та же строка подсказки,
// что у предпроверки, и только когда серверу сказать нечего.
const VIDEO_NOTES = {
    dQw4w9WgXcQ: 'Меня не зарикроллишь. Но скачаю.',
    jNQXAC9IVRw: 'Самое первое видео YouTube, 2005 год. Уважаю археологов.',
    '9bZkp7q19f0': 'Первый ролик с миллиардом просмотров. Плюс один.',
    kJQP7kiw5Fk: 'Despacito. Качаю тоже despacito, но стараюсь.',
    'QH2-TGUlwu4': 'Ня.',
    jofNR_WkoCE: 'Что говорит лиса - так и не выяснили. Зато скачаем.',
    'ZZ5LpwO-An4': 'И тебе привет. Что происходит?',
    y6120QOlsfU: 'Darude - Sandstorm. Да, это он.',
    L_jWHffIx5E: 'Шрек одобряет.',
    '2Z4m4lnjxkY': 'Трололо. Эдуард Хиль одобряет выбор.',
    KYniUCGPGLs: 'Маша плюс каша. Медведь тоже не против.',
    mDFBTdToRmw: 'Скибиди-вапапа. Качаю, пританцовывая.',
};

function videoNote(urls) {
    if (urls.length !== 1) return null;
    try {
        const url = new URL(urls[0]);
        if (!isYoutubeHost(url.hostname)) return null;
        const id = url.searchParams.get('v');
        return id && Object.hasOwn(VIDEO_NOTES, id) ? VIDEO_NOTES[id] : null;
    } catch (e) {
        return null;
    }
}