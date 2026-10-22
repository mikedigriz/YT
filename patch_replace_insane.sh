#!/bin/bash
set -e

# Скрипт для применения patch к функции replace_insane() в yt-dlp
# Может работать в двух режимах:
#   patch_replace_insane.sh dev    - выполнить pip install и патч в контейнере dev
#   patch_replace_insane.sh local  - применить патч к локальному file system

MODE=${1:-local}
PATCH_FILE="$(cd "$(dirname "$0")" && pwd)/app/patches/replace_insane.patch"
TARGET_PATH="/.yt_env/lib/python3.13/site-packages"

if [ ! -f "$PATCH_FILE" ]; then
    echo "❌ Файл патча не найден: $PATCH_FILE"
    exit 1
fi

case "$MODE" in
    dev)
        echo "🐳 Применяем патч в dev контейнере..."

        if ! docker ps --format "{{.Names}}" | grep -q "^yt-prod_dev$"; then
            echo "❌ Контейнер yt-prod_dev не запущен"
            echo "Запустите его с: ./build.sh start dev -d"
            exit 1
        fi

        # [default] обязателен - без него не обновится yt-dlp-ejs (JS-решатель)
        echo "📦 Обновляем yt-dlp в контейнере..."
        docker exec -u root yt-prod_dev bash -c "pip install --upgrade pip && pip install --upgrade 'yt-dlp[default]'" || true

        docker cp "$PATCH_FILE" yt-prod_dev:/tmp/replace_insane.patch

        echo "📝 Применяем патч в контейнере..."
        docker exec -u root yt-prod_dev bash -c "cd $TARGET_PATH && patch -p0 --fuzz=0 < /tmp/replace_insane.patch && echo '✅ Патч применён успешно'"

        docker cp yt-prod_dev:$TARGET_PATH/yt_dlp/utils/_utils.py app/.yt_env/lib/python3.13/site-packages/yt_dlp/utils/
        echo "✅ Patched файл скопирован обратно на хост"
        ;;

    local)
        echo "💻 Применяем патч локально..."

        PYTHON_SITE=$(python3 -c "import site; print(site.getsitepackages()[0])")
        LOCAL_TARGET="$PYTHON_SITE"

        if [ ! -d "$LOCAL_TARGET" ]; then
            echo "❌ Не найдена папка site-packages: $LOCAL_TARGET"
            exit 1
        fi

        if [ ! -f "$LOCAL_TARGET/yt_dlp/utils/_utils.py" ]; then
            echo "❌ Файл не найден: $LOCAL_TARGET/yt_dlp/utils/_utils.py"
            exit 1
        fi

        BACKUP_FILE="$LOCAL_TARGET/yt_dlp/utils/_utils.py.backup"
        if [ ! -f "$BACKUP_FILE" ]; then
            cp "$LOCAL_TARGET/yt_dlp/utils/_utils.py" "$BACKUP_FILE"
            echo "💾 Backup создан: $BACKUP_FILE"
        fi

        echo "📝 Применяем патч к $LOCAL_TARGET..."
        cd "$LOCAL_TARGET" && patch -p0 --fuzz=0 < "$PATCH_FILE" && echo "✅ Патч применён успешно"
        ;;

    *)
        echo "❌ Неизвестный режим: $MODE"
        echo ""
        echo "Использование:"
        echo "  $0 dev      - применить патч в dev контейнере (требует запущенный контейнер)"
        echo "  $0 local    - применить патч к локальной установке yt-dlp"
        echo ""
        exit 1
        ;;
esac
