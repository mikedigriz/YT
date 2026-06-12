const CONFIG = {
    refreshInterval: 3000
};

// Кэшированные элементы DOM
const $ui = {};

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

// ============================================
// ОСНОВНАЯ ФУНКЦИЯ
// ============================================

function loadList() {
    $.get("index.php?jobs", function(data) {
        
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

    }, "json").fail(function() {
        console.error("Не удалось загрузить данные");
    });
}

// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================

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

$(document).ready(function() {
    initCache();
    
    if ($ui.progress.length) {
        startAutoRefresh();
        $ui.urlInput.focus();
    }
    
    // Делегирование для модального окна (чтобы работало после динамической перерисовки)
    $(document).on('click', '[data-toggle="modal"]', function() {
        const href = $(this).data('href');
        if (href) {
            $('#confirm-delete .btn-danger').off('click').on('click', function() {
                window.location.href = href;
            });
        }
    });
});

$(window).on('beforeunload', function() {
    stopAutoRefresh();
});

$(document).on('visibilitychange', function() {
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
