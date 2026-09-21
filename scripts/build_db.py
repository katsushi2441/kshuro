#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""WAM NET のオープンデータ CSV から、画面が読む SQLite を作る。

  /usr/bin/python3 scripts/build_db.py
  → php/kshuro_data/kshuro.sqlite

入力は scripts/fetch_sfk.py が /mnt/data/kshuro/raw に落とした ZIP。
扱うのは就労系4種別（就労移行支援・就労継続支援A型・就労継続支援B型・就労定着支援）。

CSV の文字コードは **UTF-8（BOM付き）**。cp932 でも decode 自体は通ってしまい、
列名が化けたまま動くので、BOM を見て決める（推測で cp932 を決め打ちしない）。

テーブルは3つ。
  offices … 最新時点の全事業所（画面の主役）。緯度経度は全件入っている。
  counts  … 時点 × 種別 × 市区町村 の公表件数（増減を数えるため）。
  gone    … 前に載っていて最新には載っていない事業所番号（最後に載った時点つき）。

**増減の言い方に気をつける。** 公表件数は 2021年11月の B型 13,245 から
2026年3月の 20,783 まで増えているが、これは事業所が 57% 増えたのではなく、
**自治体の登録が進んだぶんが混ざっている**。だから画面では
  - 全国の推移 = 「公表された件数」（事業所数とは言わない）
  - 市区町村の比較 = 「前の時点に載っていて、今の時点に載っていない事業所」
    （＝公表データから消えた。廃止と決めつけない）
と書き分ける。ここを混ぜると、廃止していない事業所を廃止したことにしてしまう。
"""
from __future__ import annotations

import csv
import datetime as dt
import io
import json
import os
import re
import sqlite3
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RAW = Path(os.environ.get("KSHURO_RAW_DIR", "/mnt/data/kshuro/raw"))
OUT_DIR = ROOT / "php" / "kshuro_data"
OUT = OUT_DIR / "kshuro.sqlite"
KINDS = {"60": "就労移行支援", "45": "就労継続支援A型", "46": "就労継続支援B型", "62": "就労定着支援"}
SRC_URL = "https://www.wam.go.jp/content/wamnet/pcpub/top/sfkopendata/"
ATTRIBUTION = "出典: 障害福祉サービス等情報公表システム（独立行政法人福祉医療機構 WAM NET）のオープンデータを加工して作成"

# 事業所名から「芯」を作る。同名の別事業所を切り分けるため（khokan と同じ考え方）。
STRIP = re.compile(
    r"(就労継続支援Ａ型事業所|就労継続支援A型事業所|就労継続支援Ｂ型事業所|就労継続支援B型事業所"
    r"|就労移行支援事業所|就労定着支援事業所|多機能型事業所|障害者就労支援事業所|就労支援事業所"
    r"|就労継続支援Ａ型|就労継続支援A型|就労継続支援Ｂ型|就労継続支援B型|就労移行支援|就労定着支援"
    r"|指定障害福祉サービス事業所|障害福祉サービス事業所|事業所|作業所|株式会社|合同会社|有限会社|合資会社"
    r"|社会福祉法人|医療法人社団|医療法人財団|医療法人|一般社団法人|一般財団法人|公益社団法人|公益財団法人"
    r"|特定非営利活動法人|ＮＰＯ法人|NPO法人|生活協同組合)")
PUNCT = re.compile(r"[\s　・（）()「」『』\[\]【】〔〕\-－‐–—~〜～/／,，.．]")

PREFS = ("北海道 青森県 岩手県 宮城県 秋田県 山形県 福島県 茨城県 栃木県 群馬県 埼玉県 千葉県 東京都 "
         "神奈川県 新潟県 富山県 石川県 福井県 山梨県 長野県 岐阜県 静岡県 愛知県 三重県 滋賀県 京都府 "
         "大阪府 兵庫県 奈良県 和歌山県 鳥取県 島根県 岡山県 広島県 山口県 徳島県 香川県 愛媛県 高知県 "
         "福岡県 佐賀県 長崎県 熊本県 大分県 宮崎県 鹿児島県 沖縄県").split()


def read_csv(path: Path) -> list[dict]:
    with zipfile.ZipFile(path) as z:
        raw = z.read(z.namelist()[0])
    if raw[:3] == b"\xef\xbb\xbf":
        text = raw.decode("utf-8-sig")
    else:
        try:
            text = raw.decode("utf-8")
        except UnicodeDecodeError:
            text = raw.decode("cp932", "replace")
    return list(csv.DictReader(io.StringIO(text)))


def name_core(name: str) -> str:
    return PUNCT.sub("", STRIP.sub("", name)).strip()


def split_pref(addr: str) -> tuple[str, str]:
    """事業所住所（市区町村）を 都道府県 と 市区町村 に割る。政令市は「市」まで＋区。"""
    for p in PREFS:
        if addr.startswith(p):
            return p, addr[len(p):]
    return "", addr


def city_key(city: str) -> str:
    """政令市・東京23区をまたいで数えるためのキー。名古屋市中区 → 名古屋市。"""
    m = re.match(r"^(.+?市)(.+区)$", city)
    return m.group(1) if m else city


def timepoints() -> list[str]:
    return sorted(d.name for d in RAW.iterdir() if d.is_dir() and re.fullmatch(r"\d{6}", d.name))


def schema(conn: sqlite3.Connection) -> None:
    conn.executescript("""
    DROP TABLE IF EXISTS offices;
    CREATE TABLE offices (
      id INTEGER PRIMARY KEY,
      kind TEXT NOT NULL,              -- 就労継続支援A型 など
      office_no TEXT,                  -- 事業所番号（従たる事業所は同じ番号を共有する）
      name TEXT NOT NULL, name_kana TEXT, name_core TEXT,
      corp TEXT, corp_no TEXT, corp_url TEXT,
      pref TEXT, city TEXT, city_key TEXT, addr TEXT,
      tel TEXT, fax TEXT, url TEXT,
      lat REAL, lon REAL,
      capacity INTEGER,
      hours_weekday TEXT, hours_sat TEXT, hours_sun TEXT, hours_holiday TEXT,
      closed TEXT, note TEXT,
      designator TEXT, area_code TEXT);
    CREATE INDEX offices_city ON offices(pref, city_key);
    CREATE INDEX offices_kind ON offices(kind);
    CREATE INDEX offices_core ON offices(name_core);
    CREATE INDEX offices_geo ON offices(lat, lon);

    -- 時点ごとの公表件数。**事業所数ではなく「公表された件数」**として画面に出す。
    DROP TABLE IF EXISTS counts;
    CREATE TABLE counts (
      tp TEXT NOT NULL, kind TEXT NOT NULL,
      pref TEXT, city_key TEXT, n INTEGER NOT NULL);
    CREATE INDEX counts_city ON counts(pref, city_key, kind, tp);
    CREATE INDEX counts_tp ON counts(tp, kind);

    -- 前に公表されていて、最新時点には載っていない事業所番号。
    -- **廃止とは書かない。**「公表データから消えた」までしか分からない。
    DROP TABLE IF EXISTS gone;
    CREATE TABLE gone (
      kind TEXT NOT NULL, office_no TEXT, name TEXT,
      pref TEXT, city TEXT, city_key TEXT,
      capacity INTEGER,              -- 最後に公表されたときの定員。**働いていた人数ではない**
      last_tp TEXT NOT NULL);
    CREATE INDEX gone_city ON gone(pref, city_key);
    CREATE INDEX gone_tp ON gone(last_tp);

    DROP TABLE IF EXISTS meta;
    CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);
    """)


def to_int(s: str):
    s = (s or "").strip()
    return int(s) if s.isdigit() else None


def main() -> int:
    tps = timepoints()
    if not tps:
        print(f"生データが無い。先に fetch_sfk.py を実行する（{RAW}）", file=sys.stderr)
        return 2
    latest = tps[-1]
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    if OUT.exists():
        OUT.unlink()
    conn = sqlite3.connect(OUT)
    schema(conn)

    n_off = 0
    for code, kind in KINDS.items():
        p = RAW / latest / f"{latest}_{code}.zip"
        if not p.exists():
            print(f"  ! {kind}: {p} が無い", file=sys.stderr)
            continue
        for r in read_csv(p):
            pref, city = split_pref(r["事業所住所（市区町村）"])
            conn.execute("""INSERT INTO offices
                (kind,office_no,name,name_kana,name_core,corp,corp_no,corp_url,
                 pref,city,city_key,addr,tel,fax,url,lat,lon,capacity,
                 hours_weekday,hours_sat,hours_sun,hours_holiday,closed,note,designator,area_code)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""", (
                kind, r["事業所番号"].strip(), r["事業所の名称"].strip(), r["事業所の名称_かな"].strip(),
                name_core(r["事業所の名称"]), r["法人の名称"].strip(), r["法人番号"].strip(), r["法人URL"].strip(),
                pref, city, city_key(city), r["事業所住所（番地以降）"].strip(),
                r["事業所電話番号"].strip(), r["事業所FAX番号"].strip(), r["事業所URL"].strip(),
                float(r["事業所緯度"]) if r["事業所緯度"].strip() else None,
                float(r["事業所経度"]) if r["事業所経度"].strip() else None,
                to_int(r["定員"]),
                r["利用可能な時間帯（平日）"].strip(), r["利用可能な時間帯（土曜）"].strip(),
                r["利用可能な時間帯（日曜）"].strip(), r["利用可能な時間帯（祝日）"].strip(),
                r["定休日"].strip(), r["利用可能曜日特記事項（留意事項）"].strip(),
                r["指定機関名"].strip(), r["都道府県コード又は市区町村コード"].strip()))
            n_off += 1

    # 時点ごとに、(1) 市区町村別の件数を数え、(2) 事業所番号がいつ最後に載ったかを覚える。
    # 生データを丸ごと持つと 60MB を超える。画面が要るのはこの2つだけ。
    n_counts = 0
    last_seen: dict[tuple[str, str], tuple[str, str, str, str, str, object]] = {}
    for tp in tps:
        for code, kind in KINDS.items():
            p = RAW / tp / f"{tp}_{code}.zip"
            if not p.exists():
                continue
            tally: dict[tuple[str, str], int] = {}
            for r in read_csv(p):
                pref, city = split_pref(r["事業所住所（市区町村）"])
                ck = city_key(city)
                tally[(pref, ck)] = tally.get((pref, ck), 0) + 1
                no = r["事業所番号"].strip()
                if no:
                    last_seen[(kind, no)] = (tp, r["事業所の名称"].strip(), pref, city, ck, to_int(r["定員"]))
            for (pref, ck), n in tally.items():
                conn.execute("INSERT INTO counts (tp,kind,pref,city_key,n) VALUES (?,?,?,?,?)", (tp, kind, pref, ck, n))
                n_counts += 1

    latest_nos = {(k, no) for k, no in conn.execute("SELECT kind, office_no FROM offices WHERE office_no <> ''")}
    n_gone = 0
    for (kind, no), (tp, name, pref, city, ck, cap) in last_seen.items():
        if (kind, no) in latest_nos:
            continue
        conn.execute("INSERT INTO gone (kind,office_no,name,pref,city,city_key,capacity,last_tp) VALUES (?,?,?,?,?,?,?,?)",
                     (kind, no, name, pref, city, ck, cap, tp))
        n_gone += 1

    meta = {
        "latest_tp": latest,
        "latest_label": f"{latest[:4]}年{int(latest[4:]):d}月末時点",
        "timepoints": json.dumps(tps),
        "kinds": json.dumps(list(KINDS.values()), ensure_ascii=False),
        "offices": str(n_off),
        "counts_rows": str(n_counts),
        "gone_rows": str(n_gone),
        "source_url": SRC_URL,
        "attribution": ATTRIBUTION,
        "built_at": dt.datetime.now().strftime("%Y-%m-%d %H:%M"),
    }
    conn.executemany("INSERT INTO meta (k,v) VALUES (?,?)", list(meta.items()))
    conn.commit()

    # 検算: 種別ごとの件数が CSV の行数と一致するか
    print(f"最新時点 {latest} / 事業所 {n_off:,}件 / 件数表 {n_counts:,}行 / 消えた番号 {n_gone:,}件")
    for kind, n, ncity, cap in conn.execute(
            "SELECT kind, count(*), count(DISTINCT pref||city_key), sum(capacity IS NOT NULL) FROM offices GROUP BY kind"):
        print(f"  {kind}: {n:,}件 / {ncity:,}市区町村 / 定員あり {cap:,}")
    miss = conn.execute("SELECT count(*) FROM offices WHERE lat IS NULL OR lon IS NULL").fetchone()[0]
    print(f"  緯度経度が無い: {miss}件")
    conn.execute("VACUUM")
    conn.close()
    print(f"→ {OUT} ({OUT.stat().st_size/1048576:.1f}MB)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
