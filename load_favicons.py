# Предназначен для скачивания файлов иконок. Который мы используем потом из кэша.
# Данное решение позволяет не дёргать каждый раз эндпоинт Google и не палить ссылки, которые мы скачиваем.

### Использование:
# Получить нескачанные
# python3 load_favicons.py -m


import os
import sys
import json
import time
import urllib.request
import urllib.error
from PIL import Image
import io

# Единый источник доменов - тот же файл, что инжектится в JS как KNOWN_SERVICES
# (см. index.php / part.header.php). Downloader::DIRECT_ACCESS_DOMAINS в PHP
# отдельный - там другая задача (прямой доступ против прокси), сюда не входит.
DOMAINS_FILE = "app/var/www/YT/config/favicon_domains.json"

with open(DOMAINS_FILE, encoding="utf-8") as f:
    DOMAINS = json.load(f)

OUTPUT_DIR = "app/var/www/YT/favicons"
MIN_DIMENSION = 32

def fetch_favicon(domain, only_missing=False):
    url = f"https://www.google.com/s2/favicons?domain={domain}&sz=64"
    out_path = os.path.join(OUTPUT_DIR, f"{domain}.png")

    if only_missing and os.path.exists(out_path):
        print(f"[SKIP] {domain}: already exists")
        return

    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=10) as response:
            data = response.read()

        img = Image.open(io.BytesIO(data))
        width, height = img.size
        
        if width < MIN_DIMENSION or height < MIN_DIMENSION:
            print(f"[SKIP] {domain}: too small ({width}x{height})")
            return

        with open(out_path, 'wb') as f:
            f.write(data)
        print(f"[OK] {domain} ({width}x{height})")

    except Exception as e:
        print(f"[ERR] {domain}: {e}")

if __name__ == "__main__":
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    only_missing = "--missing" in sys.argv or "-m" in sys.argv
    for domain in DOMAINS:
        fetch_favicon(domain, only_missing=only_missing)
        time.sleep(0.2)
