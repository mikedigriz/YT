#!/bin/bash
set -e

# Скрипт для применения patch к функции replace_insane() в yt-dlp
# для образа из удалённого репозитория https://github.com/mikedigriz/YT
#
# Актуальный Dockerfile этого репозитория уже сам накатывает патч при сборке
# (apt install patch + COPY app/patches + patch -p0 --fuzz=0), поэтому скрипт
# нужен только когда yt-dlp обновляют ВНУТРИ уже собранного контейнера
# и патч нужно переналожить вручную, либо когда правят файлы ../YT на хосте.
#
# Режимы:
#   patch_replace_insane_remote.sh container [имя_контейнера]
#       обновить yt-dlp и переналожить патч внутри запущенного контейнера,
#       собранного из ../YT (по умолчанию ищет контейнер по образу mikedigriz/yt)
#   patch_replace_insane_remote.sh repo
#       применить патч прямо к файлам соседнего репозитория ../YT на хосте

MODE=${1:-repo}
PATCH_FILE="$(cd "$(dirname "$0")" && pwd)/app/patches/replace_insane.patch"
TARGET_PATH="/.yt_env/lib/python3.13/site-packages"
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)/YT"

if [ ! -f "$PATCH_FILE" ]; then
    echo "❌ Файл патча не найден: $PATCH_FILE"
    exit 1
fi

case "$MODE" in
    container)
        CONTAINER=${2:-}
        if [ -z "$CONTAINER" ]; then
            CONTAINER=$(docker ps --filter "ancestor=mikedigriz/yt:latest" --format "{{.Names}}" | head -n1)
        fi

        if [ -z "$CONTAINER" ]; then
            echo "❌ Не найден запущенный контейнер из образа mikedigriz/yt"
            echo "Укажите имя явно: $0 container <имя_контейнера>"
            exit 1
        fi

        echo "🐳 Обновляем yt-dlp и переналагаем патч в контейнере ${CONTAINER}..."

        # patch и pip уже есть в образе - apt install patch выполнен ещё при
        # сборке, USER www-data действует только для CMD, поэтому root доступен через exec
        echo "📦 Обновляем yt-dlp в контейнере..."
        docker exec -u root "$CONTAINER" bash -c "pip install --upgrade pip && pip install --upgrade yt-dlp"

        # Скопировать патч в контейнер
        docker cp "$PATCH_FILE" "$CONTAINER":/tmp/replace_insane.patch

        # Применить патч в контейнере
        echo "📝 Применяем патч в контейнере..."
        docker exec -u root "$CONTAINER" bash -c "cd $TARGET_PATH && patch -p0 --fuzz=0 < /tmp/replace_insane.patch && echo '✅ Патч применён успешно'"

        # Скопировать патченный файл в соседний репозиторий ../YT, чтобы он попал
        # в следующую сборку build_for_git.sh
        mkdir -p "$REPO_DIR/app/.yt_env/lib/python3.13/site-packages/yt_dlp/utils"
        docker cp "$CONTAINER":$TARGET_PATH/yt_dlp/utils/_utils.py "$REPO_DIR/app/.yt_env/lib/python3.13/site-packages/yt_dlp/utils/"
        echo "✅ Patched файл скопирован в $REPO_DIR"
        ;;

    repo)
        echo "💻 Применяем патч прямо к репозиторию $REPO_DIR..."

        TARGET_FILE="$REPO_DIR/app/.yt_env/lib/python3.13/site-packages/yt_dlp/utils/_utils.py"
        LOCAL_TARGET="$(dirname "$(dirname "$TARGET_FILE")")"

        if [ ! -f "$TARGET_FILE" ]; then
            echo "❌ Файл не найден: $TARGET_FILE"
            echo "Сначала установите yt-dlp в venv репозитория ../YT"
            exit 1
        fi

        # Создать backup
        BACKUP_FILE="$TARGET_FILE.backup"
        if [ ! -f "$BACKUP_FILE" ]; then
            cp "$TARGET_FILE" "$BACKUP_FILE"
            echo "💾 Backup создан: $BACKUP_FILE"
        fi

        # Применить патч
        echo "📝 Применяем патч к $LOCAL_TARGET..."
        cd "$LOCAL_TARGET" && patch -p0 --fuzz=0 < "$PATCH_FILE" && echo "✅ Патч применён успешно"
        ;;

    *)
        echo "❌ Неизвестный режим: $MODE"
        echo ""
        echo "Использование:"
        echo "  $0 container [имя]  - обновить yt-dlp и переналожить патч в контейнере mikedigriz/yt"
        echo "  $0 repo              - применить патч прямо к файлам ../YT на хосте"
        echo ""
        exit 1
        ;;
esac
