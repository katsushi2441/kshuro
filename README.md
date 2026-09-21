# kshuro — Kurage 就労継続支援ナビ

住所を入れると、通える範囲の**就労移行支援・就労継続支援A型／B型・就労定着支援**の事業所を
距離順に返す。定員・営業時間・電話・サイトつき。市区町村ごとに公表件数の推移と、
「前は載っていたのに、いまは載っていない事業所」も出す。

- 公開: https://kurage.exbridge.jp/kshuro.php/
- 構成: **PHP 1ファイル + SQLite**（常駐サーバー・ポート不要）

## データ

WAM NET（独立行政法人福祉医療機構）[障害福祉サービス等情報公表システムのオープンデータ](https://www.wam.go.jp/content/wamnet/pcpub/top/sfkopendata/)。
営利・非営利を問わず二次利用できると明記された公開データ。毎年3月末・9月末に更新。

2026年3月末時点で、就労継続支援A型 4,650／B型 20,783／就労移行支援 3,396／就労定着支援 2,038、
合計 30,867事業所。**緯度経度は全件ある**ので、住所からの距離計算に外部のジオコーダは要らない
（入力住所の座標だけ国土地理院の住所検索を使う）。

配布 ZIP は「時点 × サービス種別」で分かれている。`_46` は B型であって三重県ではない。
CSV は **UTF-8 BOM付き**（cp932 でも decode は通ってしまい、列名が化けたまま動く）。

## 言えること／言えないこと

| | |
|---|---|
| 言える | 公表データに載っている事業所と、その定員・所在地・連絡先 |
| 言える | ある時点の公表件数と、番号が公表データから消えたこと |
| 言えない | **空き状況**（公表されていない。定員は受け入れ可能人数ではない） |
| 言えない | **廃止したかどうか**（消えた理由は公表されていない） |

公表件数は B型で 2021年11月 13,245 → 2026年3月 20,783 と増えているが、これは事業所が 57% 増えた
のではなく、自治体の登録が進んだぶんが混ざっている。だから画面では全国の増減を
「**公表された件数**」と書き、事業所数とは言わない。

一方、**番号が消えた数**は報酬改定の前後ではっきり違う。就労継続支援A型は、2024年3月末時点より後に
消えたのが 889件、それ以前は 342件（2021年11月〜2023年9月の合計）。

## 使い方

```
scripts/fetch_sfk.py            # 最新時点の全サービス種別を /mnt/data/kshuro/raw へ
scripts/fetch_sfk.py --all      # 全時点 × 就労系4種別（推移と「消えた事業所」に要る）
scripts/build_db.py             # → php/kshuro_data/kshuro.sqlite（約23MB）
scripts/make_store_image.py     # OGP / kappstore の商品画像
scripts/deploy.py               # heteml へ（FTPは1接続にまとめる）
scripts/deploy.py --php         # PHP だけ送る（SQLiteを送らない）
```

## 画面

- `/` 住所から探す（`?q=住所&kind=種別&km=範囲`）
- `/pref/{都道府県}` 市区町村別の一覧・推移・消えた事業所
- `/city/{都道府県}/{市区町村}` `/city/{都道府県}/{市区町村}/{区}`
- `/office/{id}` 事業所ページ
- `/gone` 公表データから消えた事業所（全国・都道府県別）
- `/api?q=住所` JSON / `/data/offices.csv` `/data/gone.csv`
- `/llms.txt` `/robots.txt` `/sitemap.xml`

## 置き方（オンプレミス版）

```
kshuro.php
kshuro_data/kshuro.sqlite
kshuro_data/.htaccess      # Require all denied（SQLite の直読みを止める）
images/ogp/kshuro.png      # OGP。kshuro_data 配下は拒否するので別の場所に置く
```

heteml は既定が PHP 5.6 なので、その階層の `.htaccess` に `AddHandler php-script .php` が要る。
