<?php

// Страницы нет (сейчас - выключенная в конфиге обратная связь). Отдельная функция,
// а не голый 404: пустой ответ выглядит как поломка сайта, а не как "тут ничего нет".
function showNotFoundPage(string $text = 'Такой страницы нет.') {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Страницы нет</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
                   min-height: 100vh; display: flex; align-items: center; justify-content: center;
                   background: #f5f5f5; margin: 0; padding: 20px; }
            .container { background: #fff; border-radius: 8px; padding: 40px; max-width: 460px;
                         box-shadow: 0 2px 10px rgba(0,0,0,.1); }
            h1 { font-size: 22px; margin: 0 0 12px; color: #333; }
            p { color: #666; line-height: 1.6; margin: 0 0 20px; }
            a { color: #2b6cb0; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Страницы нет</h1>
            <p><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></p>
            <p><a href="/">Вернуться на главную</a></p>
        </div>
    </body>
    </html>
    <?php
    die();
}

function showCsrfErrorPage() {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ошибка безопасности</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f5f5f5;
                padding: 20px;
            }

            .container {
                background: white;
                border-radius: 8px;
                padding: 50px 40px;
                max-width: 480px;
                width: 100%;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .error-code {
                font-size: 13px;
                color: #999;
                letter-spacing: 1px;
                margin-bottom: 10px;
                font-weight: 500;
            }

            h1 {
                font-size: 24px;
                color: #d32f2f;
                margin-bottom: 15px;
                font-weight: 600;
                font-family: monospace;
            }

            .description {
                font-size: 15px;
                color: #666;
                line-height: 1.6;
                margin-bottom: 20px;
            }

            .button-group {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            button {
                padding: 12px 20px;
                border: none;
                border-radius: 6px;
                font-size: 15px;
                font-weight: 500;
                cursor: pointer;
                transition: background 0.2s;
            }

            .btn-primary {
                background: #667eea;
                color: white;
            }

            .btn-primary:hover {
                background: #5568d3;
            }

            .btn-secondary {
                background: #f0f0f0;
                color: #333;
                border: 1px solid #ddd;
            }

            .btn-secondary:hover {
                background: #e8e8e8;
            }

            @media (max-width: 480px) {
                .container {
                    padding: 40px 25px;
                }

                h1 {
                    font-size: 24px;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="error-code">Сессия обновилась</div>
            <h1>Секунду...</h1>

            <p class="description">
                Сервер недавно перезапускался, и страница у вас в браузере успела устареть. Сейчас обновим её автоматически - просто повторите действие ещё раз.
            </p>

            <div class="button-group">
                <button class="btn-primary" id="csrf-reload">Вернуться на сайт</button>
            </div>
        </div>
        <script nonce="<?= htmlspecialchars($GLOBALS['cspNonce'] ?? '', ENT_QUOTES) ?>">
            function goHome() { window.location.href = '/'; }
            document.getElementById('csrf-reload').addEventListener('click', goHome);
            setTimeout(goHome, 1500);
        </script>
    </body>
    </html>
    <?php
    die();
}
