<?php
if (!isset($GLOBALS['config'])) { die("No direct script access"); }

// Публичная доска обратной связи: анонимные нумерованные диалоги.
//
// Хранилище файловое, как и всё состояние в проекте (БД тут нет вовсе): диалог -
// один JSON, счётчик номеров и статистика - отдельные файлы, всё под flock.
// Каталог лежит ВЫШЕ корня сайта (feedbackPath, /var/www/YT_data/feedback), то
// есть отдать его по HTTP нельзя в принципе, а не только правилом deny в nginx.
//
// В файл диалога не попадает ни IP, ни User-Agent, ни куки, ни любой другой
// технический след - только заголовок, текст и время. Ограничитель частоты знает
// об отправителе ровно один отпечаток: HMAC от адреса с локальным секретом.
// Голого sha1(ip) недостаточно - всё пространство IPv4 перебирается за секунды.
class Feedback
{
    // Лимиты содержимого. Заголовок короткий намеренно: он живёт в списке одной
    // строкой, а не пересказывает обращение.
    const MAX_TITLE = 80;
    const MAX_BODY = 10000;
    const MAX_CODE_BLOCK = 5000;
    const MAX_CODE_BLOCKS = 10;

    // Свободного места меньше - новых записей не принимаем (тот же порог, что у
    // формы загрузки в index.php).
    const MIN_FREE_BYTES = 104857600;

    // Уборка отметок частоты идёт лениво, в среднем раз на столько записей.
    const RL_SWEEP_CHANCE = 50;
    const RL_MAX_AGE = 86400;

    private static function dir(): string
    {
        return rtrim($GLOBALS['config']['feedbackPath'] ?? '/var/www/YT_data/feedback', '/');
    }

    public static function enabled(): bool
    {
        return !empty($GLOBALS['config']['feedback']);
    }

    private static function cfg(string $key, int $default): int
    {
        $v = $GLOBALS['config'][$key] ?? $default;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** Каталоги создаются лениво: в образе они есть, но том можно подмонтировать пустым. */
    private static function ensureDirs(): bool
    {
        foreach ([self::dir(), self::dir() . '/d', self::dir() . '/rl'] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                return false;
            }
        }
        return true;
    }

    // Путь к файлу диалога. Номер уже целое число, имя собирается формате -
    // пользовательская строка в путь не попадает ни при каком вводе.
    private static function dialogPath(int $id): string
    {
        return self::dir() . '/d/' . sprintf('%06d', $id) . '.json';
    }

    /**
     * Атомарная запись: во временный файл рядом, затем rename(). Обрыв записи
     * посреди JSON иначе терял бы весь диалог, а читатель видел бы огрызок.
     */
    private static function writeAtomic(string $path, string $data): bool
    {
        $tmp = $path . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Событие в лог контейнера. Файл читает "tail -F" из start.sh, поэтому строка
     * оказывается в "docker logs" - отдельного канала заводить не пришлось, тем же
     * способом наружу отдаётся лог yt-dlp.
     *
     * IP пишется - это служебный лог владельца сервера, по нему видно, откуда
     * пришёл спам. Важно, что он остаётся ТОЛЬКО здесь: в файл диалога адрес не
     * попадает (там его нет вовсе), на публичной странице не показывается, а
     * ограничитель частоты по-прежнему знает лишь HMAC (см. sourceKey()).
     *
     * Текста сообщения в строке нет намеренно: тело может содержать то, что
     * человек не должен был присылать, и лог - не место это размножать; вместо
     * него пишется длина. Значения чистятся от переводов строк и разделителя,
     * иначе одно обращение растащило бы вывод на несколько строк и подделало бы
     * соседние записи.
     */
    private static function logEvent(string $event, int $id, array $extra = []): void
    {
        $file = (string) ($GLOBALS['config']['feedbackLogFile'] ?? '/var/log/feedback.log');
        if ($file === '') {
            return;
        }
        $parts = [date('Y-m-d H:i:s'), 'feedback', $event, '#' . $id];
        foreach ($extra as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'да' : 'нет';
            }
            $value = preg_replace('~[\r\n\t|]+~u', ' ', (string) $value) ?? '';
            if (Markdown::length($value) > 80) {
                $value = preg_replace('~^(.{0,80}).*~us', '$1', $value) . '...';
            }
            $parts[] = $key . '=' . trim($value);
        }
        // Ошибка записи не должна ронять отправку: лог - дело служебное.
        @file_put_contents($file, implode(' | ', $parts) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Секрет для отпечатка отправителя. Заводится при первом обращении и
     * больше не меняется - смена обнулила бы все действующие лимиты.
     */
    private static function secret(): string
    {
        $path = self::dir() . '/secret';
        $existing = @file_get_contents($path);
        if (is_string($existing) && strlen($existing) >= 32) {
            return $existing;
        }
        $secret = random_bytes(32);
        self::writeAtomic($path, $secret);
        return $secret;
    }

    private static function sourceKey(string $ip): string
    {
        return hash_hmac('sha256', $ip, self::secret());
    }

    /**
     * Скользящее окно на источник - по мотивам PlaylistProbe::rateLimited().
     * Метки времени лежат одной строкой через запятую; вышедшие из окна
     * отбрасываются при каждой проверке, поэтому файл не растёт.
     *
     * $commit = false только проверяет, не записывая свою метку: у отправки
     * сообщения два окна (10 секунд и час), и провалить второе после записи в
     * первое означало бы наказать за отказ.
     */
    private static function windowHit(string $ip, string $kind, int $window, int $max, bool $commit): bool
    {
        // Каталоги нужны до первой записи. Без этой строки отметка на пустом
        // томе не сохранялась вовсе (file_put_contents в несуществующий каталог
        // тихо возвращает false), и защита от перебора пароля молча не работала
        // ровно до первого созданного диалога - поймано тестом.
        self::ensureDirs();
        $file = self::dir() . '/rl/' . $kind . '_' . substr(self::sourceKey($ip), 0, 32);
        $now = time();
        $stamps = [];
        $raw = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') {
            foreach (explode(',', $raw) as $item) {
                $t = (int) trim($item);
                if ($t > 0 && ($now - $t) < $window) {
                    $stamps[] = $t;
                }
            }
        }
        if (count($stamps) >= $max) {
            return true;
        }
        if ($commit) {
            $stamps[] = $now;
            @file_put_contents($file, implode(',', $stamps), LOCK_EX);
            @chmod($file, 0600);
            self::sweepRateFiles();
        }
        return false;
    }

    /** Отметки копятся по одной на источник, поэтому чистятся лениво, без крона. */
    private static function sweepRateFiles(): void
    {
        if (random_int(1, self::RL_SWEEP_CHANCE) !== 1) {
            return;
        }
        $now = time();
        foreach (glob(self::dir() . '/rl/*') ?: [] as $file) {
            if (is_file($file) && ($now - (int) @filemtime($file)) > self::RL_MAX_AGE) {
                @unlink($file);
            }
        }
    }

    /**
     * Проверка лимитов перед записью. Возвращает готовый текст отказа или null.
     * Ни адреса, ни отпечатка в тексте нет - человеку они ничего не говорят,
     * а боту подсказывают, чем именно его опознали.
     */
    public static function rateLimit(string $ip, bool $isNewDialog): ?string
    {
        $msgWindow = self::cfg('feedbackMsgWindow', 10);
        $msgHour = self::cfg('feedbackMsgHour', 10);
        $dialogDay = self::cfg('feedbackDialogDay', 3);

        if (self::windowHit($ip, 'burst', $msgWindow, 1, false)) {
            return 'Слишком часто. Подожди ' . $msgWindow . ' секунд и отправь снова.';
        }
        if (self::windowHit($ip, 'hour', 3600, $msgHour, false)) {
            return 'За час можно отправить не больше ' . $msgHour . ' сообщений. Попробуй позже.';
        }
        if ($isNewDialog && self::windowHit($ip, 'day', 86400, $dialogDay, false)) {
            return 'За сутки можно завести не больше ' . $dialogDay . ' обращений. Ответить в уже открытое можно.';
        }
        return null;
    }

    private static function rateCommit(string $ip, bool $isNewDialog): void
    {
        self::windowHit($ip, 'burst', self::cfg('feedbackMsgWindow', 10), PHP_INT_MAX, true);
        self::windowHit($ip, 'hour', 3600, PHP_INT_MAX, true);
        if ($isNewDialog) {
            self::windowHit($ip, 'day', 86400, PHP_INT_MAX, true);
        }
    }

    /**
     * Проверка содержимого. Возвращает текст ошибки или null.
     * Длина считается в символах (Markdown::length, mbstring в образе нет).
     *
     * $isAdmin снимает ВСЕ ограничения на объём: лимиты тут стоят против спама и
     * простыней от анонимов, а администратор уже подтвердил права - упереться в
     * потолок символов, отвечая по делу, он не должен. Пустое сообщение остаётся
     * ошибкой и для него: показывать нечего в любом случае.
     */
    public static function validate(?string $title, string $body, bool $isAdmin = false): ?string
    {
        if ($isAdmin) {
            if ($title !== null && trim(Markdown::normalize($title)) === '') {
                return 'Напиши заголовок - по нему обращение находят в списке.';
            }
            return trim(Markdown::normalize($body)) === '' ? 'Сообщение пустое.' : null;
        }

        if ($title !== null) {
            $title = trim(Markdown::normalize($title));
            if ($title === '') {
                return 'Напиши заголовок - по нему обращение находят в списке.';
            }
            if (Markdown::length($title) > self::MAX_TITLE) {
                return 'Заголовок длиннее ' . self::MAX_TITLE . ' символов. Сократи.';
            }
        }

        $body = trim(Markdown::normalize($body));
        if ($body === '') {
            return 'Сообщение пустое.';
        }
        if (Markdown::length($body) > self::MAX_BODY) {
            return 'Сообщение длиннее ' . self::MAX_BODY . ' символов. Сократи или разбей на два.';
        }

        $blocks = self::codeBlocks($body);
        if (count($blocks) > self::MAX_CODE_BLOCKS) {
            return 'В одном сообщении не больше ' . self::MAX_CODE_BLOCKS . ' блоков кода.';
        }
        foreach ($blocks as $block) {
            if (Markdown::length($block) > self::MAX_CODE_BLOCK) {
                return 'Блок кода длиннее ' . self::MAX_CODE_BLOCK . ' символов. Оставь только нужный кусок.';
            }
        }
        return null;
    }

    /** Содержимое ограждённых блоков кода - только для проверки лимитов. */
    private static function codeBlocks(string $body): array
    {
        $lines = explode("\n", $body);
        $blocks = [];
        $current = null;
        $fence = '';
        foreach ($lines as $line) {
            if ($current === null) {
                if (preg_match('~^\s{0,3}(`{3,}|\~{3,})~', $line, $m)) {
                    $current = [];
                    $fence = $m[1];
                }
                continue;
            }
            if (preg_match('~^\s{0,3}' . preg_quote($fence[0], '~') . '{3,}\s*$~', $line)) {
                $blocks[] = implode("\n", $current);
                $current = null;
                continue;
            }
            $current[] = $line;
        }
        if ($current !== null) {
            $blocks[] = implode("\n", $current);
        }
        return $blocks;
    }

    /**
     * Следующий номер диалога. fopen('c+'), а не 'w': 'w' обрезает файл ДО
     * взятия блокировки, и параллельный запрос прочитал бы пустоту.
     */
    private static function nextId(): ?int
    {
        $fh = @fopen(self::dir() . '/counter', 'c+');
        if (!$fh) {
            return null;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return null;
        }
        $id = (int) trim((string) stream_get_contents($fh)) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $id);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $id;
    }

    /**
     * Счётчики для значка в подвале: сколько всего диалогов и сколько из них
     * без единого ответа. Подвал рисуется на КАЖДОЙ странице, поэтому число
     * читается из готового файла, а не пересчитывается обходом каталога.
     */
    public static function stats(): array
    {
        $data = self::readJson(self::dir() . '/stats.json');
        return [
            'dialogs' => (int) ($data['dialogs'] ?? 0),
            'unanswered' => max(0, (int) ($data['unanswered'] ?? 0)),
        ];
    }

    private static function bumpStats(int $dDialogs, int $dUnanswered): void
    {
        $path = self::dir() . '/stats.json';
        $fh = @fopen($path . '.lock', 'c');
        if ($fh) {
            flock($fh, LOCK_EX);
        }
        $stats = self::stats();
        $stats['dialogs'] = max(0, $stats['dialogs'] + $dDialogs);
        $stats['unanswered'] = max(0, $stats['unanswered'] + $dUnanswered);
        self::writeAtomic($path, json_encode($stats));
        if ($fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    // ── Права администратора ────────────────────────────────────────────────
    //
    // Две двери, обе узкие. Из доверенной сети (feedbackAdminNetworks) кнопки
    // работают сразу: адрес там свой, спрашивать пароль на каждое нажатие дома
    // - обряд без выгоды. Снаружи пароль обязателен на КАЖДОЕ действие, метки
    // в сессии нет намеренно: угнанная кука иначе означала бы права админа до
    // конца окна, а тут её недостаточно.

    /** Разбор одного правила CIDR. Работает и для IPv4, и для IPv6 - сравнение
     *  побитовое по упакованному адресу, поэтому разбирать семейства порознь
     *  не нужно. Кривое правило игнорируется молча: уронить страницу опечаткой
     *  в настройках хуже, чем не пустить админа. */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }
        $slash = strpos($cidr, '/');
        $net = $slash === false ? $cidr : substr($cidr, 0, $slash);
        $binIp = @inet_pton($ip);
        $binNet = @inet_pton($net);
        if ($binIp === false || $binNet === false || strlen($binIp) !== strlen($binNet)) {
            return false;
        }
        $maxBits = strlen($binNet) * 8;
        $bits = $slash === false ? $maxBits : (int) substr($cidr, $slash + 1);
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && strncmp($binIp, $binNet, $fullBytes) !== 0) {
            return false;
        }
        $restBits = $bits % 8;
        if ($restBits === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $restBits)) - 1) & 0xFF;
        return (ord($binIp[$fullBytes]) & $mask) === (ord($binNet[$fullBytes]) & $mask);
    }

    public static function isTrustedAdminNetwork(string $ip): bool
    {
        $networks = $GLOBALS['config']['feedbackAdminNetworks'] ?? [];
        if (!is_array($networks) || $ip === '' || $ip === 'unknown') {
            return false;
        }
        foreach ($networks as $cidr) {
            if (is_string($cidr) && self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** Настроен ли вообще пароль - от этого зависит, показывать ли поле ввода. */
    public static function adminPasswordConfigured(): bool
    {
        return (string) ($GLOBALS['config']['feedbackAdminPassword'] ?? '') !== '';
    }

    private static function passwordMatches(string $given): bool
    {
        $stored = (string) ($GLOBALS['config']['feedbackAdminPassword'] ?? '');
        if ($stored === '' || $given === '') {
            return false;
        }
        // Хеш password_hash() опознаётся по префиксу; обычную строку сравниваем
        // hash_equals - посимвольное сравнение утекает длину общего префикса.
        if (preg_match('~^\$(2y|argon2i|argon2id|2a|2b)\$~', $stored)) {
            return password_verify($given, $stored);
        }
        return hash_equals($stored, $given);
    }

    /**
     * Главная проверка прав. Возвращает ['ok'=>bool,'error'=>?string].
     *
     * Текст отказа одинаков для "пароль неверный" и "пароль не настроен" -
     * подсказывать, какая из дверей вообще существует, незачем.
     */
    public static function checkAdmin(string $ip, string $password): array
    {
        if (self::isTrustedAdminNetwork($ip)) {
            return ['ok' => true, 'error' => null];
        }
        if (!self::adminPasswordConfigured()) {
            return ['ok' => false, 'error' => 'Действие недоступно.'];
        }

        $tries = self::cfg('feedbackAdminTries', 5);
        $window = self::cfg('feedbackAdminTriesWindow', 600);
        if (self::windowHit($ip, 'adm', $window, $tries, false)) {
            return ['ok' => false, 'error' => 'Слишком много попыток. Подожди и попробуй снова.'];
        }

        if (!self::passwordMatches($password)) {
            // Счётчик двигает только неудача: верный пароль не должен приближать
            // владельца к собственной блокировке.
            self::windowHit($ip, 'adm', $window, PHP_INT_MAX, true);
            return ['ok' => false, 'error' => 'Пароль не подошёл.'];
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Единственная точка проверки прав на запрос. index.php зовёт её ОДИН раз и
     * дальше передаёт готовый bool в createDialog/addMessage/deleteMessage/
     * deleteDialog. Так сделано не ради краткости: checkAdmin() двигает счётчик
     * неудачных попыток, и повторная проверка внутри каждого метода списывала бы
     * за одну опечатку две попытки из пяти.
     */
    public static function authorize(string $ip, string $password): array
    {
        return self::checkAdmin($ip, $password);
    }

    // ── Изменения от администратора ─────────────────────────────────────────

    /**
     * Удаление одного сообщения. Текст стирается с диска, оставшиеся
     * перенумеровываются подряд - выбранный вариант "убирать совсем", поэтому
     * дырок в нумерации не остаётся (ценой того, что старая ссылка на #3 после
     * удаления #2 указывает на соседнее сообщение).
     *
     * Удаление ЕДИНСТВЕННОГО сообщения сносит и сам диалог: обращение без
     * единого текста показывать нечем.
     */
    public static function deleteMessage(int $id, int $n, bool $isAdmin, string $ip = ''): array
    {
        if (!$isAdmin) {
            return ['ok' => false, 'error' => 'Действие недоступно.', 'auth' => true];
        }

        $path = self::dialogPath($id);
        $fh = @fopen($path . '.lock', 'c');
        if ($fh) { flock($fh, LOCK_EX); }

        $dialog = self::read($id);
        if ($dialog === null) {
            if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
            return ['ok' => false, 'error' => 'Такого обращения нет.'];
        }

        $before = count($dialog['messages']);
        $kept = array_values(array_filter(
            $dialog['messages'],
            fn(array $m): bool => (int) ($m['n'] ?? 0) !== $n
        ));
        if (count($kept) === $before) {
            if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
            return ['ok' => false, 'error' => 'Такого сообщения нет.'];
        }

        if ($kept === []) {
            if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
            return self::deleteDialog($id, $isAdmin, $ip);
        }

        foreach ($kept as $i => $_) {
            $kept[$i]['n'] = $i + 1;
        }
        $dialog['messages'] = $kept;
        $dialog['updated'] = (int) ($kept[count($kept) - 1]['ts'] ?? time());
        $written = self::writeAtomic($path, json_encode($dialog, JSON_UNESCAPED_UNICODE));

        if ($fh) { flock($fh, LOCK_UN); fclose($fh); }

        if (!$written) {
            return ['ok' => false, 'error' => 'Не получилось удалить сообщение. Попробуй позже.'];
        }
        // Диалог мог стать снова "без ответа" - значок в подвале считает именно
        // одиночные обращения.
        if ($before > 1 && count($kept) === 1) {
            self::bumpStats(0, 1);
        }
        self::logEvent('удалено сообщение', $id, [
            'ip' => $ip,
            'сообщение' => '#' . $n,
            'осталось' => count($kept),
        ]);
        return ['ok' => true, 'error' => null, 'dialogDeleted' => false];
    }

    /** Удаление обращения целиком - спам-тред проще снести разом. */
    public static function deleteDialog(int $id, bool $isAdmin, string $ip = ''): array
    {
        if (!$isAdmin) {
            return ['ok' => false, 'error' => 'Действие недоступно.', 'auth' => true];
        }

        $dialog = self::read($id);
        if ($dialog === null) {
            return ['ok' => false, 'error' => 'Такого обращения нет.'];
        }
        $wasUnanswered = count($dialog['messages']) === 1;

        $path = self::dialogPath($id);
        if (!@unlink($path)) {
            return ['ok' => false, 'error' => 'Не получилось удалить обращение. Попробуй позже.'];
        }
        @unlink($path . '.lock');
        // Счётчик номеров не откатываем намеренно: номера публичные, и
        // переиспользовать освободившийся - значит увести старую ссылку на
        // чужое обращение.
        self::bumpStats(-1, $wasUnanswered ? -1 : 0);
        self::logEvent('удалено обращение', $id, [
            'ip' => $ip,
            'заголовок' => (string) ($dialog['title'] ?? ''),
            'сообщений было' => count($dialog['messages']),
        ]);
        return ['ok' => true, 'error' => null, 'dialogDeleted' => true];
    }

    /** Один диалог или null, если такого номера нет. */
    public static function read(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $data = self::readJson(self::dialogPath($id));
        if ($data === null || !isset($data['messages']) || !is_array($data['messages'])) {
            return null;
        }
        return $data;
    }

    /**
     * Страница списка, новые сверху. Сортировка по времени последнего сообщения:
     * диалог с ответом должен подниматься, иначе живое обсуждение тонет.
     */
    public static function listDialogs(int $page, int $perPage): array
    {
        $files = glob(self::dir() . '/d/*.json') ?: [];
        $items = [];
        foreach ($files as $file) {
            $data = self::readJson($file);
            if ($data === null || !isset($data['id'])) {
                continue;
            }
            $items[] = [
                'id' => (int) $data['id'],
                'title' => (string) ($data['title'] ?? ''),
                'created' => (int) ($data['created'] ?? 0),
                'updated' => (int) ($data['updated'] ?? 0),
                'count' => is_array($data['messages'] ?? null) ? count($data['messages']) : 0,
            ];
        }
        usort($items, fn(array $a, array $b): int => $b['updated'] <=> $a['updated']);

        $total = count($items);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));
        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    private static function diskFull(): bool
    {
        $free = @disk_free_space(self::dir());
        return is_float($free) && $free < self::MIN_FREE_BYTES;
    }

    /**
     * Новое обращение. Возвращает ['ok'=>bool,'id'=>int,'error'=>?string].
     *
     * $isAdmin означает "права УЖЕ проверены вызывающим" (index.php зовёт
     * authorize() один раз на запрос). Проверять пароль повторно здесь нельзя:
     * каждая проверка двигает счётчик неудачных попыток, и одна опечатка
     * засчитывалась бы дважды.
     */
    public static function createDialog(string $title, string $body, string $ip, bool $isAdmin = false): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'id' => 0, 'error' => 'Обратная связь выключена.'];
        }
        if (!self::ensureDirs()) {
            return ['ok' => false, 'id' => 0, 'error' => 'Не получилось сохранить обращение. Попробуй позже.'];
        }
        // Место на диске проверяется и для администратора: это не ограничение
        // прав, а физика - записать всё равно не выйдет.
        if (self::diskFull()) {
            return ['ok' => false, 'id' => 0, 'error' => 'На сервере кончилось место. Попробуй позже.'];
        }
        if (!$isAdmin && self::stats()['dialogs'] >= self::cfg('feedbackMaxDialogs', 5000)) {
            return ['ok' => false, 'id' => 0, 'error' => 'Обращений накопилось слишком много. Ответить в уже открытое можно.'];
        }

        $error = self::validate($title, $body, $isAdmin);
        if ($error !== null) {
            return ['ok' => false, 'id' => 0, 'error' => $error];
        }
        $error = $isAdmin ? null : self::rateLimit($ip, true);
        if ($error !== null) {
            return ['ok' => false, 'id' => 0, 'error' => $error, 'limited' => true];
        }

        $id = self::nextId();
        if ($id === null) {
            return ['ok' => false, 'id' => 0, 'error' => 'Не получилось сохранить обращение. Попробуй позже.'];
        }

        $now = time();
        $first = ['n' => 1, 'ts' => $now, 'body' => trim(Markdown::normalize($body))];
        if ($isAdmin) {
            $first['admin'] = true;
        }
        $dialog = [
            'id' => $id,
            'title' => trim(Markdown::normalize($title)),
            'created' => $now,
            'updated' => $now,
            'messages' => [$first],
        ];
        if (!self::writeAtomic(self::dialogPath($id), json_encode($dialog, JSON_UNESCAPED_UNICODE))) {
            return ['ok' => false, 'id' => 0, 'error' => 'Не получилось сохранить обращение. Попробуй позже.'];
        }

        if (!$isAdmin) {
            self::rateCommit($ip, true);
        }
        self::bumpStats(1, 1);
        self::logEvent('новое обращение', $id, [
            'ip' => $ip,
            'заголовок' => $dialog['title'],
            'символов' => Markdown::length($dialog['messages'][0]['body']),
            'админ' => $isAdmin,
        ]);
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /**
     * Ответ в существующий диалог. $asAdmin означает "права УЖЕ проверены"
     * (см. authorize() и комментарий у createDialog()) и снимает разом лимиты
     * частоты, потолок сообщений в обращении и ограничения на объём текста.
     */
    public static function addMessage(int $id, string $body, string $ip, bool $asAdmin = false): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'id' => $id, 'error' => 'Обратная связь выключена.'];
        }
        if (!self::ensureDirs()) {
            return ['ok' => false, 'id' => $id, 'error' => 'Не получилось сохранить ответ. Попробуй позже.'];
        }
        if (self::diskFull()) {
            return ['ok' => false, 'id' => $id, 'error' => 'На сервере кончилось место. Попробуй позже.'];
        }

        $error = self::validate(null, $body, $asAdmin);
        if ($error !== null) {
            return ['ok' => false, 'id' => $id, 'error' => $error];
        }
        // Лимиты частоты - защита от спама снаружи; администратору они мешают
        // разгребать поток ответов и ничего не защищают: он уже подтвердил права.
        $error = $asAdmin ? null : self::rateLimit($ip, false);
        if ($error !== null) {
            return ['ok' => false, 'id' => $id, 'error' => $error, 'limited' => true];
        }

        $path = self::dialogPath($id);
        $fh = @fopen($path . '.lock', 'c');
        if ($fh) {
            flock($fh, LOCK_EX);
        }

        $dialog = self::read($id);
        if ($dialog === null) {
            if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
            return ['ok' => false, 'id' => $id, 'error' => 'Такого обращения нет.'];
        }
        $wasUnanswered = count($dialog['messages']) === 1;
        if (!$asAdmin && count($dialog['messages']) >= self::cfg('feedbackMaxPerDialog', 200)) {
            if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
            return ['ok' => false, 'id' => $id, 'error' => 'В обращении набралось предельное число сообщений. Заведи новое.'];
        }

        $now = time();
        $message = [
            'n' => count($dialog['messages']) + 1,
            'ts' => $now,
            'body' => trim(Markdown::normalize($body)),
        ];
        if ($asAdmin) {
            $message['admin'] = true;
        }
        $dialog['messages'][] = $message;
        $dialog['updated'] = $now;
        $written = self::writeAtomic($path, json_encode($dialog, JSON_UNESCAPED_UNICODE));

        if ($fh) { flock($fh, LOCK_UN); fclose($fh); }

        if (!$written) {
            return ['ok' => false, 'id' => $id, 'error' => 'Не получилось сохранить ответ. Попробуй позже.'];
        }

        if (!$asAdmin) {
            self::rateCommit($ip, false);
        }
        if ($wasUnanswered) {
            self::bumpStats(0, -1);
        }
        self::logEvent('ответ', $id, [
            'ip' => $ip,
            'сообщение' => '#' . $message['n'],
            'символов' => Markdown::length($message['body']),
            'админ' => $asAdmin,
        ]);
        return ['ok' => true, 'id' => $id, 'error' => null];
    }
}
