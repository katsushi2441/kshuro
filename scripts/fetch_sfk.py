#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""WAM NET「障害福祉サービス等情報公表システム」のオープンデータ CSV を落とす。

  /usr/bin/python3 scripts/fetch_sfk.py            # 最新時点の全サービス種別
  /usr/bin/python3 scripts/fetch_sfk.py --all      # 全時点 × 就労系の種別
  /usr/bin/python3 scripts/fetch_sfk.py --all-full # 全時点 × 全種別（約500MB）

配布元: https://www.wam.go.jp/content/wamnet/pcpub/top/sfkopendata/
  ZIP は「時点 × サービス種別」で分かれる。**都道府県別ではない**（_46 は B型であって
  三重県ではない。ここを取り違えると「29県しか無い」と誤読する）。
  提供時点は毎年 3月末・9月末（初回のみ 2021年11月末）。
  利用条件: ページに「営利目的、非営利目的を問わず二次利用可能」と明記（オープンデータ）。
  出典表示は画面の脚注で出す。

落とし先は /mnt/data/kshuro/raw（ルートディスクを圧迫させない）。取得済みは飛ばす。
"""
from __future__ import annotations

import argparse
import csv
import io
import json
import os
import re
import sys
import urllib.request
import zipfile
from pathlib import Path

INDEX = "https://www.wam.go.jp/content/wamnet/pcpub/top/sfkopendata/"
BASE = "https://www.wam.go.jp/content/files/pcpub/top/sfkopendata/{t}/sfkopendata_{t}_{c}.zip"
RAW = Path(os.environ.get("KSHURO_RAW_DIR", "/mnt/data/kshuro/raw"))
ROOT = Path(__file__).resolve().parent.parent
CATALOG = ROOT / "data" / "sfk_catalog.json"
UA = {"User-Agent": "kshuro/1.0 (+https://kurage.exbridge.jp/kshuro.php/)"}

# 就労系（この製品の本体）。コードは実データの「サービス種別」列で検算する。
SHURO = ["45", "46", "60", "62"]  # A型・B型・就労移行・就労定着（実データの種別名で確認済み）


def index_html() -> str:
    req = urllib.request.Request(INDEX, headers=UA)
    with urllib.request.urlopen(req, timeout=120) as r:
        return r.read().decode("cp932", "replace")


def listing(html: str) -> dict[str, list[str]]:
    """時点 -> サービス種別コードの一覧。"""
    out: dict[str, set] = {}
    for t, c in re.findall(r"sfkopendata_(\d{6})_(\d+)\.zip", html):
        out.setdefault(t, set()).add(c)
    return {t: sorted(v, key=int) for t, v in sorted(out.items())}


def get(t: str, c: str) -> Path | None:
    d = RAW / t
    d.mkdir(parents=True, exist_ok=True)
    path = d / f"{t}_{c}.zip"
    if path.exists() and path.stat().st_size > 1000:
        return path
    req = urllib.request.Request(BASE.format(t=t, c=c), headers=UA)
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            body = r.read()
    except Exception as e:  # 種別が無い時点がある。落ちないで飛ばす
        print(f"  ! {t}_{c}: {e}", file=sys.stderr)
        return None
    if not body.startswith(b"PK"):
        print(f"  ! {t}_{c}: ZIP ではない（{len(body)}B）", file=sys.stderr)
        return None
    path.write_bytes(body)
    return path


def peek(path: Path) -> tuple[str, int]:
    """(サービス種別名, 件数) を CSV の中身から読む。名前は推測せず実データから取る。"""
    with zipfile.ZipFile(path) as z:
        name = z.namelist()[0]
        raw = z.read(name)
    for enc in ("cp932", "utf-8-sig", "utf-8"):
        try:
            text = raw.decode(enc)
            break
        except UnicodeDecodeError:
            continue
    else:
        return "", 0
    rows = list(csv.DictReader(io.StringIO(text)))
    kinds = {r.get("サービス種別", "") for r in rows if r.get("サービス種別")}
    # 1ファイル＝1種別のはずだが、混在していたら全部並べて気づけるようにする
    return "／".join(sorted(kinds)), len(rows)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--all", action="store_true", help="全時点 × 就労系")
    ap.add_argument("--all-full", action="store_true", help="全時点 × 全種別")
    a = ap.parse_args()

    avail = listing(index_html())
    latest = max(avail)
    print(f"提供時点 {len(avail)}: {' '.join(sorted(avail))}（最新 {latest}）")

    if a.all_full:
        jobs = [(t, c) for t, cs in avail.items() for c in cs]
    elif a.all:
        jobs = [(t, c) for t, cs in avail.items() for c in cs if c in SHURO]
    else:
        jobs = [(latest, c) for c in avail[latest]]

    catalog: dict[str, dict] = {}
    if CATALOG.exists():
        catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    got = 0
    for t, c in jobs:
        p = get(t, c)
        if not p:
            continue
        got += 1
        kind, n = peek(p)
        catalog.setdefault(c, {})["name"] = kind
        catalog[c].setdefault("counts", {})[t] = n
        print(f"  {t} 種別{c:>2} {kind or '（種別名なし）'} {n:,}件")
    CATALOG.parent.mkdir(parents=True, exist_ok=True)
    CATALOG.write_text(json.dumps(catalog, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"{got}ファイル取得 → {RAW}  目録 → {CATALOG}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
