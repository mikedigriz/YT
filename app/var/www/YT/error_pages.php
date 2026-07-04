<?php

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

            .info-box {
                background: #fafafa;
                border-left: 3px solid #d32f2f;
                padding: 12px 15px;
                margin-bottom: 30px;
                font-size: 14px;
                color: #555;
                line-height: 1.6;
            }

            .steps {
                list-style: none;
                margin-bottom: 30px;
            }

            .steps li {
                padding: 12px 0;
                font-size: 15px;
                color: #333;
                line-height: 1.5;
            }

            .code {
                background: #f0f0f0;
                padding: 2px 6px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 13px;
                color: #444;
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
            <div class="error-code">Ошибка 403</div>
            <h1>CSRF token validation failed</h1>

            <p class="description">
                Токен безопасности истёк или больше не действителен. Это происходит для защиты от несанкционированных действий.
            </p>

            <div class="info-box">
                <strong>Что это означает:</strong><br>
                При отправке формы ваш браузер проверяет специальный токен безопасности. Если сессия закончилась или браузер кэшировал старые данные, токен становится невалидным.
            </div>

            <ol class="steps">
                <li>Нажмите <span class="code">Ctrl+Shift+R</span> (на Mac: <span class="code">Cmd+Shift+R</span>)</li>
                <li>Или очистите куки браузера для этого сайта</li>
                <li>Повторите действие</li>
            </ol>

            <div class="button-group">
                <button class="btn-primary" id="csrf-reload">Обновить страницу</button>
                <button class="btn-secondary" id="csrf-back">Вернуться назад</button>
            </div>
        </div>
        <script nonce="<?= htmlspecialchars($GLOBALS['cspNonce'] ?? '', ENT_QUOTES) ?>">
            document.getElementById('csrf-reload').addEventListener('click', function () { location.reload(); });
            document.getElementById('csrf-back').addEventListener('click', function () { history.back(); });
        </script>
    </body>
    </html>
    <?php
    die();
}
