<?php
if (!isset($GLOBALS['config'])) die('Прямой вызов запрещён');

/**
 * Безопасный рендер подмножества Markdown для страницы обратной связи.
 *
 * Защита - в порядке операций, а не в фильтрах: сначала весь ввод проходит
 * htmlspecialchars(), и только потом по уже экранированному тексту ищутся
 * конструкции разметки. Сырой HTML при таком порядке невозможен в принципе:
 * <script> становится &lt;script&gt; до того, как парсер вообще увидит строку,
 * поэтому отдельных запретов на script/iframe/object/embed/svg/math не нужно -
 * этим тегам неоткуда взяться.
 *
 * Разбор построчный. Это не стилистика: регулярка через весь текст на 10 КБ
 * ввода способна выбить pcre.backtrack_limit, и тогда preg_replace() вернёт
 * null - наивный код записал бы null в результат и показал пустое сообщение.
 * На отдельной строке катастрофического отката не бывает, а результат каждой
 * замены всё равно проверяется (см. sub()).
 */
class Markdown
{
    /** Метка языка у ограждённого блока кода. */
    private const LANG_RE = '~^[a-z0-9+#_-]{1,16}$~i';

    /** Языки, для которых есть подсветка. Остальные показываются без покраски. */
    private const KEYWORDS = [
        'php' => 'abstract|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|empty|enum|extends|final|finally|fn|for|foreach|function|global|if|implements|include|include_once|instanceof|interface|isset|list|match|namespace|new|print|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|yield|true|false|null',
        'js' => 'async|await|break|case|catch|class|const|continue|debugger|default|delete|do|else|export|extends|finally|for|function|if|import|in|instanceof|let|new|of|return|static|super|switch|this|throw|try|typeof|var|void|while|yield|true|false|null|undefined',
        'python' => 'and|as|assert|async|await|break|class|continue|def|del|elif|else|except|finally|for|from|global|if|import|in|is|lambda|nonlocal|not|or|pass|raise|return|try|while|with|yield|True|False|None',
        'bash' => 'case|do|done|elif|else|esac|fi|for|function|if|in|local|return|select|then|until|while|export|source|echo|cd|set|trap|exit',
        'json' => 'true|false|null',
    ];

    /** Однострочные комментарии по языкам. */
    private const LINE_COMMENT = [
        'php' => '(?://|\#)',
        'js' => '//',
        'python' => '\#',
        'bash' => '\#',
    ];

    /**
     * Единственная публичная точка. Отдаёт готовый HTML.
     *
     * Любой сбой разбора (отказ PCRE) роняет не страницу, а только оформление:
     * сообщение показывается как есть, экранированным текстом.
     */
    public static function render(string $text): string
    {
        $text = self::normalize($text);
        if ($text === '') {
            return '';
        }

        try {
            $html = self::renderBlocks($text);
        } catch (RuntimeException $e) {
            $html = null;
        }

        if ($html === null || $html === '') {
            return '<pre class="md-fallback">' . self::esc($text) . '</pre>';
        }
        return $html;
    }

    /**
     * Приведение ввода к пригодному виду ДО экранирования.
     *
     * Битый UTF-8 обезвреживается здесь, а не глубже: любая регулярка с флагом
     * /u на нём просто отказывает (возвращает null), и разбор пришлось бы
     * откатывать целиком. Байты вне корректных последовательностей выбрасываются.
     * mbstring в образе нет (php8.4 + fpm + opcache, см. Dockerfile), поэтому
     * проверка сделана самой регуляркой - preg_match('//u') на битой строке даёт
     * не 1. Управляющие символы вырезаются: в тексте им делать нечего, а в
     * разметке они умеют разрывать проверку схемы ссылки.
     */
    public static function normalize(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            $text = preg_replace('~[\x80-\xFF]~', '', $text) ?? '';
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text);
        return $text === null ? '' : trim($text, "\n");
    }

    /** Длина в символах без mbstring. На битой строке отступает к длине в байтах. */
    public static function length(string $s): int
    {
        $n = preg_match_all('~.~us', $s);
        return $n === false ? strlen($s) : $n;
    }

    /**
     * Ключ метки-заглушки. Номер кодируется ЗАГЛАВНЫМИ буквами, не цифрами:
     * подсветка чисел иначе красила цифры внутри самой метки, strtr переставал
     * её узнавать, и спрятанная строка (или комментарий) исчезала из блока кода.
     * Заглавные буквы безопасны и для подсветки ключевых слов - та регистрозависима.
     */
    private static function stashKey(int $n): string
    {
        return "\x01" . strtr((string) $n, '0123456789', 'ABCDEFGHIJ') . "\x02";
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * preg_replace с проверкой отказа. null означает, что PCRE сдался
     * (backtrack/recursion limit) - продолжать с ним нельзя, получится
     * разорванная разметка, поэтому весь рендер откатывается на простой текст.
     */
    private static function sub(string $pattern, string $replacement, string $subject): string
    {
        $out = preg_replace($pattern, $replacement, $subject);
        if ($out === null) {
            throw new RuntimeException('pcre failure');
        }
        return $out;
    }

    private static function subCb(string $pattern, callable $cb, string $subject): string
    {
        $out = preg_replace_callback($pattern, $cb, $subject);
        if ($out === null) {
            throw new RuntimeException('pcre failure');
        }
        return $out;
    }

    /**
     * Блочный разбор: идём по строкам, накапливаем блоки.
     * Ограждённый код перехватывается первым и внутрь не разбирается вовсе.
     */
    private static function renderBlocks(string $text): string
    {
        $lines = explode("\n", $text);
        $out = [];
        $paragraph = [];
        $listStack = [];        // открытые списки: 'ul'|'ol'
        $quote = [];            // накопитель строк цитаты
        $count = count($lines);

        $flushParagraph = function () use (&$paragraph, &$out) {
            if ($paragraph !== []) {
                $out[] = '<p>' . self::inline(implode("\n", $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $closeLists = function () use (&$listStack, &$out) {
            while ($listStack !== []) {
                $out[] = '</' . array_pop($listStack) . '>';
            }
        };
        $flushQuote = function () use (&$quote, &$out) {
            if ($quote !== []) {
                $inner = self::renderBlocks(implode("\n", $quote));
                $out[] = '<blockquote>' . $inner . '</blockquote>';
                $quote = [];
            }
        };

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // Ограждённый блок кода: содержимое не разбирается, только красится.
            if (preg_match('~^\s{0,3}(`{3,}|\~{3,})\s*([^\s`\~]*)\s*$~', $line, $m)) {
                $flushParagraph();
                $closeLists();
                $flushQuote();
                $fence = $m[1][0];
                $fenceLen = strlen($m[1]);
                $lang = $m[2];
                $code = [];
                $i++;
                for (; $i < $count; $i++) {
                    if (preg_match('~^\s{0,3}(' . preg_quote($fence, '~') . '{' . $fenceLen . ',})\s*$~', $lines[$i])) {
                        break;
                    }
                    $code[] = $lines[$i];
                }
                $out[] = self::codeBlock(implode("\n", $code), $lang);
                continue;
            }

            // Цитата копится целиком и разбирается рекурсивно - иначе списки и
            // код внутри цитаты пришлось бы разбирать вторым набором правил.
            if (preg_match('~^\s{0,3}>\s?(.*)$~', $line, $m)) {
                $flushParagraph();
                $closeLists();
                $quote[] = $m[1];
                continue;
            }
            $flushQuote();

            if (trim($line) === '') {
                $flushParagraph();
                $closeLists();
                continue;
            }

            if (preg_match('~^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$~', $line, $m)) {
                $flushParagraph();
                $closeLists();
                $level = strlen($m[1]);
                $out[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('~^\s{0,3}([-*_])(\s*\1){2,}\s*$~', $line)) {
                $flushParagraph();
                $closeLists();
                $out[] = '<hr>';
                continue;
            }

            // Таблица GFM: заголовок + строка-разделитель. Разделитель обязателен,
            // иначе обычный текст с вертикальной чертой стал бы таблицей.
            if (strpos($line, '|') !== false && isset($lines[$i + 1])
                && preg_match('~^\s*\|?[\s:|-]+\|[\s:|-]*$~', $lines[$i + 1])
                && strpos($lines[$i + 1], '-') !== false) {
                $flushParagraph();
                $closeLists();
                $table = [$line];
                $align = self::tableAlign($lines[$i + 1]);
                $i += 2;
                for (; $i < $count; $i++) {
                    if (trim($lines[$i]) === '' || strpos($lines[$i], '|') === false) {
                        $i--;
                        break;
                    }
                    $table[] = $lines[$i];
                }
                $out[] = self::table($table, $align);
                continue;
            }

            if (preg_match('~^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$~', $line, $m)) {
                $flushParagraph();
                $type = in_array($m[2], ['-', '*', '+'], true) ? 'ul' : 'ol';
                $depth = (int) floor(strlen(str_replace("\t", '    ', $m[1])) / 2);
                $depth = min($depth, 5);

                while (count($listStack) > $depth + 1) {
                    $out[] = '</' . array_pop($listStack) . '>';
                }
                if (count($listStack) === $depth + 1 && end($listStack) !== $type) {
                    $out[] = '</' . array_pop($listStack) . '>';
                }
                while (count($listStack) < $depth + 1) {
                    $listStack[] = $type;
                    $out[] = '<' . $type . '>';
                }
                $out[] = '<li>' . self::inline($m[3]) . '</li>';
                continue;
            }

            $closeLists();
            $paragraph[] = $line;
        }

        $flushParagraph();
        $closeLists();
        $flushQuote();

        return implode("\n", $out);
    }

    /** Выравнивание столбцов из строки-разделителя таблицы. */
    private static function tableAlign(string $sep): array
    {
        $cells = self::tableCells($sep);
        $align = [];
        foreach ($cells as $cell) {
            $cell = trim($cell);
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            if ($left && $right) {
                $align[] = 'center';
            } elseif ($right) {
                $align[] = 'right';
            } elseif ($left) {
                $align[] = 'left';
            } else {
                $align[] = '';
            }
        }
        return $align;
    }

    private static function tableCells(string $row): array
    {
        $row = trim($row);
        $row = preg_replace('~^\||\|$~', '', $row);
        if ($row === null) {
            throw new RuntimeException('pcre failure');
        }
        return explode('|', $row);
    }

    private static function table(array $rows, array $align): string
    {
        $header = self::tableCells(array_shift($rows));
        $html = '<div class="md-table-wrap"><table class="md-table"><thead><tr>';
        foreach ($header as $idx => $cell) {
            $style = ($align[$idx] ?? '') !== '' ? ' style="text-align:' . $align[$idx] . '"' : '';
            $html .= '<th' . $style . '>' . self::inline(trim($cell)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $cells = self::tableCells($row);
            $html .= '<tr>';
            foreach ($cells as $idx => $cell) {
                $style = ($align[$idx] ?? '') !== '' ? ' style="text-align:' . $align[$idx] . '"' : '';
                $html .= '<td' . $style . '>' . self::inline(trim($cell)) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private static function codeBlock(string $code, string $lang): string
    {
        $lang = strtolower($lang);
        if ($lang !== '' && !preg_match(self::LANG_RE, $lang)) {
            $lang = '';
        }
        $escaped = self::esc($code);
        $body = self::highlight($escaped, $lang);
        $label = $lang !== '' ? '<span class="md-code-lang">' . self::esc($lang) . '</span>' : '';
        return '<div class="md-code">' . $label . '<pre><code>' . $body . '</code></pre></div>';
    }

    /**
     * Подсветка работает по УЖЕ экранированному тексту и вставляет только span
     * с фиксированными классами - новой поверхности для инъекции не создаёт.
     * Строки и комментарии красятся первыми, чтобы ключевое слово внутри строки
     * не перебивало её собственную окраску.
     */
    public static function highlight(string $escapedCode, string $lang): string
    {
        if ($lang === '' || !isset(self::KEYWORDS[$lang])) {
            return $escapedCode;
        }

        $placeholders = [];
        $stash = function (string $html) use (&$placeholders): string {
            $key = self::stashKey(count($placeholders));
            $placeholders[$key] = $html;
            return $key;
        };

        $out = $escapedCode;

        if (isset(self::LINE_COMMENT[$lang])) {
            $out = self::subCb(
                '~' . self::LINE_COMMENT[$lang] . '[^\n]*~',
                fn(array $m): string => $stash('<span class="hl-comment">' . $m[0] . '</span>'),
                $out
            );
        }

        $out = self::subCb(
            '~&quot;[^&\n]*(?:&(?!quot;)[^&\n]*)*&quot;|&#039;[^&\n]*(?:&(?!\#039;)[^&\n]*)*&#039;~',
            fn(array $m): string => $stash('<span class="hl-string">' . $m[0] . '</span>'),
            $out
        );

        $out = self::sub(
            '~\b(' . self::KEYWORDS[$lang] . ')\b~',
            '<span class="hl-keyword">$1</span>',
            $out
        );

        $out = self::sub('~\b(\d+(?:\.\d+)?)\b~', '<span class="hl-number">$1</span>', $out);

        return strtr($out, $placeholders);
    }

    /**
     * Строчная разметка. Порядок важен: код вынимается первым и до конца разбора
     * заменён меткой, поэтому звёздочки и скобки внутри `кода` не превращаются
     * в разметку.
     */
    private static function inline(string $text): string
    {
        $placeholders = [];
        $stash = function (string $html) use (&$placeholders): string {
            $key = self::stashKey(count($placeholders));
            $placeholders[$key] = $html;
            return $key;
        };

        $out = self::subCb(
            '~(`+)([^`]|[^`].*?[^`])\1~',
            fn(array $m): string => $stash('<code>' . self::esc(trim($m[2])) . '</code>'),
            $text
        );

        $out = self::esc($out);

        // Ссылки. Схема проверяется отдельно (см. safeUrl), непрошедшее
        // рендерится обычным текстом - именно текстом, а не ссылкой на "#".
        $out = self::subCb(
            '~\[([^\]\n]{0,200})\]\(([^)\s\n]{1,2000})\)~',
            function (array $m) use ($stash): string {
                $url = self::safeUrl($m[2]);
                $label = self::inlineEmphasis($m[1]);
                if ($url === null) {
                    return $stash($label);
                }
                return $stash('<a href="' . $url . '" rel="nofollow noopener noreferrer" target="_blank">' . $label . '</a>');
            },
            $out
        );

        $out = self::inlineEmphasis($out);

        return strtr($out, $placeholders);
    }

    /** Жирный/курсив/зачёркнутый по уже экранированному тексту. */
    private static function inlineEmphasis(string $escaped): string
    {
        $out = self::sub('~\*\*([^*\n]{1,500})\*\*~', '<strong>$1</strong>', $escaped);
        $out = self::sub('~__([^_\n]{1,500})__~', '<strong>$1</strong>', $out);
        $out = self::sub('~(?<![\w*])\*([^*\n]{1,500})\*(?![\w*])~u', '<em>$1</em>', $out);
        $out = self::sub('~(?<![\w_])_([^_\n]{1,500})_(?![\w_])~u', '<em>$1</em>', $out);
        $out = self::sub('{~~([^~\n]{1,500})~~}', '<del>$1</del>', $out);
        return $out;
    }

    /**
     * Единственное место, где пользовательская строка попадает в атрибут.
     *
     * Сущности раскрываются перед проверкой (иначе java&#115;cript: проскочит
     * мимо неё и схлопнется обратно уже в браузере), пробельные и управляющие
     * символы вырезаются (java\tscript: исторически работал), и только потом
     * URL сверяется с ^https?://. Всё прочее - javascript:, data:, vbscript:,
     * mailto:, протокольно-относительное //host - возвращает null, и вызывающий
     * рисует обычный текст.
     */
    public static function safeUrl(string $url): ?string
    {
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('~[\s\x00-\x1F\x7F]~u', '', $decoded);
        if ($decoded === null || $decoded === '') {
            return null;
        }
        if (!preg_match('~^https?://[^\s"\'<>\\\\]+$~i', $decoded)) {
            return null;
        }
        $host = parse_url($decoded, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        return self::esc($decoded);
    }
}
