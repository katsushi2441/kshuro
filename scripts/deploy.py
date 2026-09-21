#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kshuro を heteml（kurage.exbridge.jp）へ上げる。

  /usr/bin/python3 scripts/deploy.py           # 全部
  /usr/bin/python3 scripts/deploy.py --php     # PHP だけ（SQLite を送らない）

**FTP は1接続にまとめる。** 短時間に接続を重ねると、うちのIPが全ポートで
15〜20分遮断される（[[reference_heteml_ftp_block]]）。確認は HTTPS で行う。

置き場所:
  /web/kurage_exbridge_jp/kshuro.php
  /web/kurage_exbridge_jp/kshuro_data/kshuro.sqlite … 23MB。.htaccess で直読み禁止
  /web/kurage_exbridge_jp/images/ogp/kshuro.png     … OGP（kshuro_data 配下は拒否なので別の場所）
"""
import ftplib
import os
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = "https://kurage.exbridge.jp"
REMOTE = "/web/kurage_exbridge_jp"
PHP_ONLY = "--php" in sys.argv

FILES = [(f"{ROOT}/php/kshuro.php", f"{REMOTE}/kshuro.php"),
         (f"{ROOT}/php/kshuro_data/.htaccess", f"{REMOTE}/kshuro_data/.htaccess"),
         (f"{ROOT}/outputs/kshuro_ogp.png", f"{REMOTE}/images/ogp/kshuro.png")]
if not PHP_ONLY:
    FILES.insert(1, (f"{ROOT}/php/kshuro_data/kshuro.sqlite", f"{REMOTE}/kshuro_data/kshuro.sqlite"))


def env():
    for line in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in line and not line.startswith("#"):
            k, v = line.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main() -> int:
    env()
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=600)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    for local, remote in FILES:
        d = os.path.dirname(remote)
        try:
            f.cwd(d)
        except ftplib.error_perm:
            f.mkd(d)
            f.cwd(d)
        size = os.path.getsize(local)
        with open(local, "rb") as fh:
            f.storbinary("STOR " + os.path.basename(remote), fh, blocksize=1 << 18)
        print(f"  {remote}  {size/1024:.0f}KB")
    f.quit()

    # 確認は HTTPS（FTP を再接続しない）
    for path in ("/kshuro.php/", "/kshuro.php/about", "/images/ogp/kshuro.png"):
        req = urllib.request.Request(BASE + path, headers={"User-Agent": "kshuro-deploy/1.0"})
        try:
            with urllib.request.urlopen(req, timeout=90) as r:
                body = r.read(400)
                print(f"  {r.status} {len(body)}B+ {BASE}{path}")
        except Exception as e:
            print(f"  ! {BASE}{path}: {e}")
    # データが直読みできないことも確認する
    try:
        with urllib.request.urlopen(BASE + "/kshuro_data/kshuro.sqlite", timeout=60) as r:
            print(f"  ! SQLite が直接読めてしまう: {r.status}")
    except urllib.error.HTTPError as e:
        print(f"  {e.code} SQLite の直読みは拒否されている（想定どおり）")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
