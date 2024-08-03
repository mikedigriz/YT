# Правильная функция для правильных имен файлов в VK и прочих
    def replace_insane(char):
        if restricted and char in ACCENT_CHARS:
            return ACCENT_CHARS[char]
        if char == '?' or ord(char) < 32 or ord(char) == 127:
           return ''
        elif char == '"':
            return '' if restricted else '\''
        elif char == ':':
            return '_-' if restricted else ' -'
        elif char in '\\/|*<>':
            return ' '
        elif char[0] == '#':
            return ''
        if restricted and (char in '!&\'()[]{}$;`^,#' or char.isspace()):
            return ' '
        if restricted and ord(char) > 127:
            return char
        return char
