# Предназначен для скачивания файлов иконок. Который мы используем потом из кэша.
# Данное решение позволяет не дёргать каждый раз эндпоинт Google и не палить ссылки, которые мы скачиваем.

### Использование:
# Получить нескачанные
# python3 load_favicons.py -m


import os
import sys
import time
import urllib.request
import urllib.error
from PIL import Image
import io

DOMAINS = ['vk.com', 'vk.ru', 'm.vk.com', 'video.vk.com', 'vkvideo.ru', 'ok.ru', 'odnoklassniki.ru', 'rutube.ru', 'yandex.ru', 'yandex.com', 'music.yandex.ru', 'music.yandex.com', 'dzen.ru', 'zen.yandex.ru', 'coub.com', 'pikabu.ru', 't.me', 'telegram.me', 'telegram.org', 'youtube.com', 'youtu.be', 'youtube-nocookie.com', 'tiktok.com', 'vm.tiktok.com', 'douyin.com', 'instagram.com', 'instagr.am', 'ig.me', 'twitter.com', 'x.com', 't.co', 'facebook.com', 'fb.watch', 'm.facebook.com', 'twitch.tv', 'clips.twitch.tv', 'soundcloud.com', 'snd.sc', 'bandcamp.com', 'mixcloud.com', 'vimeo.com', 'player.vimeo.com', 'dailymotion.com', 'dai.ly', 'bilibili.com', 'b23.tv', 'iq.com', 'iqiyi.com', 'youku.com', 'v.youku.com', 'v.qq.com', 'nicovideo.jp', 'nico.ms', 'tumblr.com', 'streamable.com', 'archive.org', 'smotrim.ru', '1tv.ru', 'russia.tv', 'matchtv.ru', 'ntv.ru', 'ren.tv', 'tvc.ru', '5-tv.ru', 'ctc.ru', 'tnt-online.ru', 'muz-tv.ru', 'tvzvezda.ru', 'my.mail.ru', 'ivi.ru', 'ivi.tv', 'kinopoisk.ru', 'mir24.tv', 'rt.com', 'rtd.rt.com', 'life.ru', 'video.sibnet.ru', 'fc-zenit.ru', 'noodlemagazine.com', 'goodgame.ru', 'vkplay.ru', 'zvuk.com', 'zaycev.fm', 'muzofond.fm', 'pleer.net', 'rumble.com', 'bitchute.com', 'odysee.com', 'lbry.tv', 'peertube.tv', 'trovo.live', 'kick.com', 'nebula.tv', 'crunchyroll.com', 'ted.com', 'bilibili.tv', 'tubitv.com', 'pluto.tv', 'spotify.com', 'deezer.com', 'tidal.com', 'qobuz.com', 'music.apple.com', 'music.amazon.com', 'pandora.com', 'iheart.com', 'tunein.com', 'kuaishou.com', 'kwai.com', 'ixigua.com', 'mgtv.com', 'sohu.com', 'yapfiles.ru', 'yappy.media', 'news.sportbox.ru',  'mail.ru', 'video.mail.ru', 'yandexvideo.ru', 'disk.yandex.ru', 'disk.yandex.com', 'zen.yandex.com', 'okko.tv', 'okko.com', 'more.tv', 'moretv.ru', 'start.ru', 'premier.one', 'reddit.com', 'vikingfile.com', 'vik1ngfile.site', 'digriz.ddns.net']

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
