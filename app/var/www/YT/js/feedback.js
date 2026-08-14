// Страница обратной связи. Всё здесь - только удобство: счётчики, проверка длины
// до отправки, состояние "отправляю", перевод фокуса. Форма обычная, при
// выключенном JS страница работает целиком, поэтому ничего критичного тут нет.
(function () {
    "use strict";

    var LIMITS = { "fb-title": 80, "fb-message": 10000, "fb-reply": 10000 };

    function counterFor(id) {
        return document.querySelector('[data-counter-for="' + id + '"]');
    }

    function updateCounter(field) {
        var out = counterFor(field.id);
        if (!out) return;
        var limit = LIMITS[field.id] || 0;
        // Считаем символы, а не байты - серверный лимит тоже посимвольный.
        var used = Array.from(field.value).length;
        out.textContent = used + " / " + limit;
        out.classList.toggle("fb-counter-over", limit > 0 && used > limit);
    }

    function fieldError(field, text) {
        var hintId = field.getAttribute("aria-describedby");
        var hint = hintId ? document.getElementById(hintId) : null;
        var box = field.parentNode.querySelector(".fb-field-error");
        if (!box) {
            box = document.createElement("p");
            box.className = "fb-field-error";
            box.setAttribute("role", "alert");
            box.id = field.id + "-error";
            field.parentNode.appendChild(box);
        }
        box.textContent = text;
        field.setAttribute("aria-invalid", "true");
        // Ошибка добавляется к описанию поля, а не заменяет подсказку: обе
        // важны, а перезапись потеряла бы объяснение про Markdown.
        field.setAttribute("aria-describedby", ((hint && hint.id) ? hint.id + " " : "") + box.id);
        field.focus();
    }

    function clearFieldError(field) {
        var box = field.parentNode.querySelector(".fb-field-error");
        if (box) box.remove();
        field.removeAttribute("aria-invalid");
        var described = field.getAttribute("aria-describedby") || "";
        field.setAttribute("aria-describedby", described.replace(field.id + "-error", "").trim());
    }

    function validate(form) {
        var fields = form.querySelectorAll("input[type=text][name=title], textarea[name=message]");
        for (var i = 0; i < fields.length; i++) {
            var field = fields[i];
            clearFieldError(field);
            var value = field.value.trim();
            var limit = LIMITS[field.id] || 0;
            if (!value) {
                fieldError(field, field.name === "title"
                    ? "Напиши заголовок - по нему обращение находят в списке."
                    : "Сообщение пустое.");
                return false;
            }
            if (limit && Array.from(value).length > limit) {
                fieldError(field, "Слишком длинно: " + Array.from(value).length + " из " + limit + " символов.");
                return false;
            }
        }
        return true;
    }

    function initForm(form) {
        form.addEventListener("submit", function (event) {
            if (!validate(form)) {
                event.preventDefault();
                return;
            }
            var button = form.querySelector(".fb-submit");
            if (button) {
                // Отключаем ПОСЛЕ отправки в очередь события, иначе браузер не
                // включит поле кнопки в тело запроса и обработчик его не увидит.
                button.classList.add("fb-submit-busy");
                button.textContent = "Отправляю...";
                setTimeout(function () { button.disabled = true; }, 0);
            }
        });

        form.querySelectorAll("input[type=text][name=title], textarea[name=message]").forEach(function (field) {
            updateCounter(field);
            field.addEventListener("input", function () {
                updateCounter(field);
                clearFieldError(field);
            });
        });
    }

    // Подтверждение на удаление. Висит на самой кнопке, а не на форме: в панели
    // администратора одна форма и много кнопок, и по событию submit уже не
    // понять, какую нажали. Без JS удаление просто произойдёт сразу - серверная
    // проверка прав от этого не зависит.
    function initAdminConfirm() {
        document.querySelectorAll("[data-confirm]").forEach(function (button) {
            button.addEventListener("click", function (event) {
                if (!window.confirm(button.getAttribute("data-confirm"))) {
                    event.preventDefault();
                }
            });
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll(".fb-form").forEach(initForm);
        initAdminConfirm();

        // Сообщение о результате получает фокус: без этого после перезагрузки
        // страницы человек с клавиатуры или скринридером не узнает, чем кончилось.
        var flash = document.getElementById("fb-flash");
        if (flash) flash.focus();
    });
})();
