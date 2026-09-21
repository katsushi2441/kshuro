#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kshuro を kappstore に出品する（再実行で更新）。

  /usr/bin/python3 scripts/list_on_kappstore.py

**FTPは1接続にまとめる**（[[reference_heteml_ftp_block]]）。
  取得 … kapp_data/apps.json
  送信 … kapp_data/files/<stored_zip>（配布物）、kapp_media/<stored_image>（商品画像）、
         kapp_data/apps.json（台帳）
台帳は必ず退避してから書き戻す。件数が減っていないことも確かめる。
"""
import datetime
import ftplib
import io
import json
import os
import subprocess
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NAME = "Kurage 就労継続支援ナビ（住所→近くの就労継続支援A型・B型事業所・定員つき・PHP1ファイル）"
DEMO = "https://kurage.exbridge.jp/kshuro.php/"
PRICE = 50000  # 税抜。税込55,000円
ZIP = f"{ROOT}/outputs/kurage-shuro-navi.zip"
IMG = f"{ROOT}/outputs/kshuro_ogp.png"
SUM = f"{ROOT}/store/summary.txt"
BODY = f"{ROOT}/store/body.md"
LIST_APP = "/home/kojima/work/kappstore/scripts/list_app.php"
REMOTE = "/web/kappstore_exbridge_jp"


def env():
    for line in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in line and not line.startswith("#"):
            k, v = line.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main() -> int:
    env()
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=600)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])

    f.cwd(f"{REMOTE}/kapp_data")
    buf = io.BytesIO()
    f.retrbinary("RETR apps.json", buf.write)
    raw = buf.getvalue()
    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    bak = f"{ROOT}/store/apps_backup_{stamp}.json"
    open(bak, "wb").write(raw)
    before = json.loads(raw)
    n_before = len(before["apps"])
    local = f"{ROOT}/store/apps_in.json"
    open(local, "wb").write(raw)

    out = subprocess.run(["php", LIST_APP, local, NAME, SUM, BODY, DEMO, str(PRICE), ZIP, IMG],
                         capture_output=True)
    sys.stderr.write(out.stderr.decode("utf-8", "replace"))
    if out.returncode != 0:
        print("list_app.php が失敗", file=sys.stderr)
        return 1
    after = json.loads(out.stdout.decode("utf-8"))
    n_after = len(after["apps"])
    if n_after < n_before:
        print(f"！ 件数が減った {n_before} → {n_after}。書き戻さない", file=sys.stderr)
        return 1
    app = [a for a in after["apps"] if a["name"] == NAME][0]

    f.storbinary("STOR files/" + app["file"], open(ZIP, "rb"), blocksize=1 << 18)
    f.cwd(f"{REMOTE}/kapp_media")
    f.storbinary("STOR " + app["image"], open(IMG, "rb"), blocksize=1 << 18)
    f.cwd(f"{REMOTE}/kapp_data")
    f.storbinary("STOR apps.json", io.BytesIO(json.dumps(after, ensure_ascii=False,
                                                         separators=(",", ":")).encode("utf-8")))
    f.quit()
    print(f"  台帳 {n_before} → {n_after}件（退避 {bak}）")

    url = f"https://kappstore.exbridge.jp/app.php?id={app['id']}"
    for u in (url, f"https://kappstore.exbridge.jp/kapp_media/{app['image']}"):
        try:
            with urllib.request.urlopen(urllib.request.Request(u, headers={"User-Agent": "kshuro/1.0"}), timeout=90) as r:
                body = r.read()
                print(f"  {r.status} {len(body):,}B {u}")
        except Exception as e:
            print(f"  ! {u}: {e}")
    print(f"  商品ページ: {url}")
    print(f"  価格: 税抜{PRICE:,}円 / 税込{PRICE + PRICE // 10:,}円")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
