#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kshuro の OGP／kappstore 商品画像 1200×630。ライトテーマ（白＋ティール＋濃紺）＋マスコット。
数字は成長するものを焼き込まない（[[feedback_banner_variety]]）。"""
import os
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'outputs', 'kshuro_1200x630.png')
MASCOT = '/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png'
FB = '/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc'
FM = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc'
FR = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'

os.makedirs(os.path.dirname(OUT), exist_ok=True)
img = Image.new('RGB', (W, H), '#ffffff')
dr = ImageDraw.Draw(img, 'RGBA')
dr.ellipse([-200, -260, 460, 360], fill=(230, 244, 242, 255))
dr.ellipse([W - 440, H - 310, W + 240, H + 260], fill=(239, 246, 246, 255))

mascot = None
if os.path.exists(MASCOT):
    mascot = Image.open(MASCOT).convert('RGBA')
    mh = 275
    mascot = mascot.resize((int(mascot.width * mh / mascot.height), mh))
cx = 520 if mascot else W // 2

f_badge = ImageFont.truetype(FM, 25)
f_h = ImageFont.truetype(FB, 47)
f_s = ImageFont.truetype(FR, 24)
f_band = ImageFont.truetype(FM, 27)

badge = '国のオープンデータから'
bw = dr.textlength(badge, font=f_badge) + 44
dr.rounded_rectangle([cx - bw / 2, 82, cx + bw / 2, 131], radius=24, fill='#e6f4f2', outline='#bfe3de')
dr.text((cx, 106), badge, font=f_badge, fill='#0a726b', anchor='mm')

dr.text((cx, 198), '就労継続支援A型・B型を、', font=f_h, fill='#12202f', anchor='mm')
dr.text((cx, 270), '住所ひとつで。', font=f_h, fill='#0a9a8f', anchor='mm')
dr.text((cx, 342), '通える範囲の事業所を、定員と連絡先つきで近い順に。', font=f_s, fill='#5d6b7a', anchor='mm')
dr.text((cx, 382), '空き状況は公表がないので出しません。推定もしません。', font=f_s, fill='#5d6b7a', anchor='mm')

bt = 'PHP 1ファイル＋SQLite ／ 置くだけで動く'
bw2 = dr.textlength(bt, font=f_band) / 2 + 34
dr.rounded_rectangle([cx - bw2, 446, cx + bw2, 504], radius=15, fill='#0a9a8f')
dr.text((cx, 475), bt, font=f_band, fill='#ffffff', anchor='mm')

if mascot:
    img.paste(mascot, (W - mascot.width - 40, H - mascot.height - 28), mascot)
dr.text((40, H - 38), 'kurage.exbridge.jp/kshuro.php/', font=ImageFont.truetype(FR, 21), fill='#5d6b7a', anchor='lm')
img.save(OUT, optimize=True)
print(OUT, img.size)
