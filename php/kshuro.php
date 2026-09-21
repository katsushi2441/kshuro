<?php
/**
 * Kurage 就労継続支援ナビ（kshuro）— PHP 1枚 + SQLite で動く。
 *
 * 住所を入れると、近くの就労継続支援A型・B型・就労移行支援・就労定着支援の事業所を
 * 距離順に返す。定員・営業時間・電話・サイトつき。市区町村ごとに、公表件数の推移と
 * 「前は載っていたのに、いまは載っていない事業所」も出す。
 *
 * 出どころは WAM NET（独立行政法人福祉医療機構）の障害福祉サービス等情報公表システムの
 * オープンデータ。営利・非営利を問わず二次利用できると明記されている公開データ。
 *
 * **この道具が言えること／言えないこと**
 *   言える … 公表データに載っている事業所と、その定員・所在地・連絡先
 *   言える … ある時点の公表件数と、番号が公表データから消えたこと
 *   言えない … 空き状況（公表されていない）
 *   言えない … 「廃止した」かどうか（消えた理由までは公表データに無い）
 *   公表件数の増加は事業所の増加とは限らない。自治体の登録が進んだぶんが混ざる。
 *
 * 置き方:
 *   kshuro.php                     … このファイル
 *   kshuro_data/kshuro.sqlite      … データ（scripts/build_db.py が作る）
 *   kshuro_data/.htaccess          … データ直読みの禁止
 *
 * heteml に置くときは、その階層の .htaccess に `AddHandler php-script .php` が要る（既定はPHP5.6）。
 */

// ── 設定 ───────────────────────────────────────────────
$SITE = 'Kurage 就労継続支援ナビ';
$DATA_DIR = __DIR__ . '/kshuro_data';
$DB_PATH = $DATA_DIR . '/kshuro.sqlite';
$SELF = strtok($_SERVER['SCRIPT_NAME'], '?');           // 例: /kshuro.php
$GSI = 'https://msearch.gsi.go.jp/address-search/AddressSearch';
$OGP = 'https://kurage.exbridge.jp/images/ogp/kshuro.png';
$KINDS = array('就労継続支援A型', '就労継続支援B型', '就労移行支援', '就労定着支援');
// 都道府県は JIS の順（北海道→沖縄）で出す。文字コード順に並べると「三重県」が先頭に来る。
$PREFS = array('北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県', '茨城県', '栃木県', '群馬県',
    '埼玉県', '千葉県', '東京都', '神奈川県', '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県',
    '岐阜県', '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県',
    '鳥取県', '島根県', '岡山県', '広島県', '山口県', '徳島県', '香川県', '愛媛県', '高知県',
    '福岡県', '佐賀県', '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県');

try {
    $db = new PDO('sqlite:' . $DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500); echo 'データを読み込めませんでした'; exit;
}
$META = array();
foreach ($db->query('SELECT k,v FROM meta') as $r) { $META[$r['k']] = $r['v']; }
$TPS = json_decode($META['timepoints'], true);
$LATEST = $META['latest_tp'];

// ── データの引き当て ────────────────────────────────────
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((int)$v); }

/** 202603 → 2026年3月末 */
function tp_label($tp) { return substr($tp, 0, 4) . '年' . (int)substr($tp, 4) . '月末'; }

/** 住所を 都道府県 と 市区町村 に割る。政令市は区ではなく市（名古屋市瑞穂区→名古屋市）。 */
function split_address($addr) {
    if (!$addr) return array(null, null);
    if (!preg_match('/^(北海道|東京都|京都府|大阪府|.{2,3}?県)/u', $addr, $m)) return array(null, null);
    $pref = $m[1];
    $rest = mb_substr($addr, mb_strlen($pref, 'UTF-8'), null, 'UTF-8');
    if (preg_match('/^(.+?市)/u', $rest, $m2)) return array($pref, $m2[1]);
    if (preg_match('/^(?:.+?郡)?(.+?[町村])/u', $rest, $m3)) return array($pref, $m3[1]);
    if (preg_match('/^(.+?区)/u', $rest, $m4)) return array($pref, $m4[1]);
    return array($pref, null);
}

/** 住所文字列から「名古屋市瑞穂区」のような区つきの表記を拾う。無ければ空。 */
function ward_of($addr, $city_key) {
    if (!$addr || !$city_key) return '';
    if (preg_match('/' . preg_quote($city_key, '/') . '(.+?区)/u', $addr, $m)) return $city_key . $m[1];
    return '';
}

/** 国土地理院の住所検索。表記をそろえ、緯度経度も取る。落ちたら座標なしで続ける。 */
function geocode($q, $GSI) {
    $out = array('title' => $q, 'lat' => null, 'lon' => null, 'ok' => false);
    $ctx = stream_context_create(array('http' => array('timeout' => 6, 'header' => "User-Agent: kshuro/1.0\r\n")));
    $raw = @file_get_contents($GSI . '?q=' . rawurlencode($q), false, $ctx);
    if ($raw === false) return $out;
    $items = json_decode($raw, true);
    if (!is_array($items) || !count($items)) return $out;
    $best = null; $bestScore = -1;
    foreach ($items as $it) {
        $t = isset($it['properties']['title']) ? $it['properties']['title'] : '';
        $score = (mb_strpos($t, $q) !== false ? 2 : 0) + (mb_strpos($t, $q) === 0 ? 1 : 0);
        if ($score > $bestScore) { $bestScore = $score; $best = $it; }
    }
    if (!$best) return $out;
    $out['title'] = isset($best['properties']['title']) ? $best['properties']['title'] : $q;
    if (isset($best['geometry']['coordinates'][0])) {
        $out['lon'] = (float)$best['geometry']['coordinates'][0];
        $out['lat'] = (float)$best['geometry']['coordinates'][1];
        $out['ok'] = true;
    }
    return $out;
}

function hav($lat1, $lon1, $lat2, $lon2) {
    $r = 6371.0;
    $p = M_PI / 180;
    $a = 0.5 - cos(($lat2 - $lat1) * $p) / 2
       + cos($lat1 * $p) * cos($lat2 * $p) * (1 - cos(($lon2 - $lon1) * $p)) / 2;
    return $r * 2 * asin(sqrt($a));
}

/** 近い事業所を距離順に。bbox で粗く絞ってから距離を計る（SQLite に三角関数が無いため）。 */
function nearby($db, $lat, $lon, $km, $kind, $limit = 40) {
    $dlat = $km / 111.0;
    $dlon = $km / (111.0 * max(cos($lat * M_PI / 180), 0.01));
    $sql = 'SELECT * FROM offices WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?';
    $args = array($lat - $dlat, $lat + $dlat, $lon - $dlon, $lon + $dlon);
    if ($kind) { $sql .= ' AND kind = ?'; $args[] = $kind; }
    $st = $db->prepare($sql); $st->execute($args);
    $rows = array();
    foreach ($st as $r) {
        $d = hav($lat, $lon, (float)$r['lat'], (float)$r['lon']);
        if ($d > $km) continue;
        $r['km'] = $d;
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) { return $a['km'] < $b['km'] ? -1 : ($a['km'] > $b['km'] ? 1 : 0); });
    return array_slice($rows, 0, $limit);
}

/** 市区町村の様子。種別ごとの件数・定員合計・公表件数の推移・消えた事業所。 */
function city_stats($db, $pref, $city_key) {
    $out = array('pref' => $pref, 'city_key' => $city_key, 'kinds' => array(),
                 'series' => array(), 'gone' => array(), 'total' => 0, 'capacity' => 0);
    $st = $db->prepare('SELECT kind, count(*) n, sum(capacity) cap, sum(capacity IS NULL) nocap
                        FROM offices WHERE pref=? AND city_key=? GROUP BY kind');
    $st->execute(array($pref, $city_key));
    foreach ($st as $r) {
        $out['kinds'][$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap'], 'nocap' => (int)$r['nocap']);
        $out['total'] += (int)$r['n'];
        $out['capacity'] += (int)$r['cap'];
    }
    $st = $db->prepare('SELECT tp, kind, n FROM counts WHERE pref=? AND city_key=? ORDER BY tp');
    $st->execute(array($pref, $city_key));
    foreach ($st as $r) { $out['series'][$r['kind']][$r['tp']] = (int)$r['n']; }
    $st = $db->prepare('SELECT kind, name, city, last_tp FROM gone WHERE pref=? AND city_key=? ORDER BY last_tp DESC, kind, name');
    $st->execute(array($pref, $city_key));
    $out['gone'] = $st->fetchAll();
    // 政令市・東京23区は区ごとの内訳も持つ（区名での検索に応える）
    $st = $db->prepare('SELECT city, count(*) n FROM offices WHERE pref=? AND city_key=? AND city <> ? GROUP BY city ORDER BY city');
    $st->execute(array($pref, $city_key, $city_key));
    $out['wards'] = $st->fetchAll();
    return $out;
}

function national($db, $LATEST) {
    $out = array('kinds' => array(), 'total' => 0, 'capacity' => 0, 'series' => array(), 'gone' => array());
    foreach ($db->query("SELECT kind, count(*) n, sum(capacity) cap FROM offices GROUP BY kind") as $r) {
        $out['kinds'][$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap']);
        $out['total'] += (int)$r['n'];
        $out['capacity'] += (int)$r['cap'];
    }
    foreach ($db->query('SELECT tp, kind, sum(n) n FROM counts GROUP BY tp, kind ORDER BY tp') as $r) {
        $out['series'][$r['kind']][$r['tp']] = (int)$r['n'];
    }
    foreach ($db->query('SELECT last_tp, kind, count(*) n FROM gone GROUP BY last_tp, kind ORDER BY last_tp') as $r) {
        $out['gone'][$r['last_tp']][$r['kind']] = (int)$r['n'];
    }
    return $out;
}

// ── 画面の部品 ─────────────────────────────────────────
function head_html($title, $desc, $canon, $ld_extra = null) {
    global $SELF, $SITE, $OGP, $META, $LATEST, $NAT;
    $base = 'https://kurage.exbridge.jp' . $SELF;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($base . $canon) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:type" content="website">';
    echo '<meta property="og:image" content="' . h($OGP) . '">';
    echo '<meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:url" content="' . h($base . $canon) . '">';
    echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . h($OGP) . '">';
    echo '<style>'
       . ':root{--ink:#12202f;--mut:#5d6b7a;--teal:#0a9a8f;--teal-d:#087f76;--line:#dfe7ec;--bg:#f5f8fa;--red-l:#fdecea;--amber-l:#fdf6e3;--blue:#2c6fbb;--blue-l:#eaf2fb}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.8 "Noto Sans JP",system-ui,sans-serif}'
       . 'a{color:var(--teal-d)}.wrap{width:min(960px,100% - 32px);margin:0 auto}'
       . 'header{background:#fff;border-bottom:1px solid var(--line)}.brand{display:block;padding:14px 0 6px;font-weight:800;font-size:18px;text-decoration:none;color:var(--ink)}'
       . '.menu{display:flex;gap:14px;flex-wrap:wrap;padding-bottom:12px;font-size:14px}.menu a{text-decoration:none;color:var(--mut)}.menu a.on{color:var(--teal-d);font-weight:700}'
       . 'main{padding:22px 0 40px}h1{font-size:26px;line-height:1.4;margin:0 0 10px}h2{font-size:20px;margin:26px 0 10px}h3{font-size:16px;margin:18px 0 8px}.lead{color:var(--mut)}'
       . '.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin:14px 0}'
       . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}'
       . '.card{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;min-width:0}'
       . '.card.none{background:#fbfcfd}.card.lv2{border-color:#e6c98b;background:var(--amber-l)}.card.lv3{border-color:#e3a9a1;background:var(--red-l)}'
       . '.card .k{font-size:12px;color:var(--mut)}.card .v{font-size:22px;font-weight:800;margin-top:4px}.card .s{font-size:12px;color:var(--mut);margin-top:4px}'
       . '.form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}'
       . '.src{font-size:12.5px;color:var(--mut);line-height:1.8}'
       . '.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
       . '.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid var(--line)}'
       . 'input[type=text]{flex:1 1 260px;min-width:0;font-size:17px;padding:12px 14px;border:2px solid var(--line);border-radius:10px}'
       . 'select{font:inherit;padding:10px 12px;border:2px solid var(--line);border-radius:10px;background:#fff}'
       . '.tscroll{overflow-x:auto}table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:460px}'
       . 'table.t th,table.t td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}'
       . 'table.t th{color:var(--mut);font-size:12px}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}'
       . '.off{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;margin:10px 0}'
       . '.off .nm{font-weight:700;font-size:17px;line-height:1.5}.off .meta{font-size:13.5px;color:var(--mut);margin-top:4px}'
       . '.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:999px;padding:2px 10px;border:1px solid var(--line);background:var(--bg);color:var(--mut);margin-right:6px}'
       . '.tag.a{background:#eaf7f5;border-color:#a9ddd6;color:var(--teal-d)}.tag.b{background:var(--blue-l);border-color:#bcd4ef;color:var(--blue)}'
       . '.km{font-weight:800;color:var(--teal-d);font-variant-numeric:tabular-nums}'
       . '.bars{display:flex;gap:3px;align-items:flex-end;height:54px;margin:8px 0}'
       . '.bars i{flex:1;background:var(--teal);opacity:.75;border-radius:2px 2px 0 0;min-height:2px;display:block}'
       . '.bars i.last{opacity:1}'
       . 'footer{border-top:1px solid var(--line);padding:22px 0 40px;color:var(--mut);font-size:13px;background:#fff}ul.plain{margin:0;padding-left:20px}'
       . '</style>';
    echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s)})();</script>';
    $graph = array(
        array('@type' => 'WebApplication', 'name' => $SITE,
              'url' => $base . '/', 'applicationCategory' => 'GovernmentApplication',
              'operatingSystem' => 'Web', 'inLanguage' => 'ja',
              'description' => '住所を入れると、近くの就労継続支援A型・B型・就労移行支援・就労定着支援の事業所を距離順に返します。定員・営業時間・連絡先つき。',
              'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'JPY'),
              'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
        array('@type' => 'Dataset',
              'name' => '障害福祉サービス等情報公表システム（就労系4サービス）' . tp_label($LATEST) . '時点',
              'description' => '就労移行支援・就労継続支援A型・就労継続支援B型・就労定着支援の全国' . n($NAT['total']) . '事業所を、住所・緯度経度・定員つきで機械が読める形にしたもの。空き状況は公表されていないため含みません。',
              'url' => $base . '/data', 'inLanguage' => 'ja',
              'temporalCoverage' => substr($LATEST, 0, 4) . '-' . substr($LATEST, 4, 2),
              'creator' => array('@type' => 'Organization', 'name' => '独立行政法人福祉医療機構（WAM NET）'),
              'isBasedOn' => $META['source_url'],
              'license' => 'https://www.digital.go.jp/resources/open_data',
              'distribution' => array(
                  array('@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => $base . '/data/offices.csv'),
                  array('@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => $base . '/data/gone.csv'))),
        array('@type' => 'FAQPage', 'mainEntity' => array(
            array('@type' => 'Question', 'name' => '就労継続支援A型とB型の違いは何ですか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'A型は事業所と雇用契約を結んで働く形で、最低賃金が適用されます。B型は雇用契約を結ばず、作業した分を工賃として受け取る形です。このサイトは全国のA型' . n($NAT['kinds']['就労継続支援A型']['n']) . '事業所、B型' . n($NAT['kinds']['就労継続支援B型']['n']) . '事業所を住所から探せます。')),
            array('@type' => 'Question', 'name' => '事業所の空き状況は分かりますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '分かりません。空き状況は公表データに含まれていないためです。このサイトが出すのは定員で、いま何人受け入れられるかではありません。空きは各事業所へ直接お問い合わせください。')),
            array('@type' => 'Question', 'name' => '近所のA型事業所が減っているか調べられますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '市区町村ごとに、2021年11月末から半年ごとの公表件数と、前は載っていて今は載っていない事業所の一覧を出しています。ただし公表データから消えた理由（廃止・指定の取消・登録の更新漏れなど）までは公表されていないため、このサイトでは「廃止した」とは書きません。')),
            array('@type' => 'Question', 'name' => 'データはどこから取っていますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'WAM NET（独立行政法人福祉医療機構）の障害福祉サービス等情報公表システムのオープンデータです。営利・非営利を問わず二次利用できると明記された公開データで、毎年3月末・9月末に更新されます。')))),
    );
    if ($ld_extra) { $graph[] = $ld_extra; }
    echo '<script type="application/ld+json">' . json_encode(array('@context' => 'https://schema.org', '@graph' => $graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    echo '</head><body><header><div class="wrap">';
    echo '<a class="brand" href="' . h($SELF) . '/">' . h($SITE) . '</a>';
    echo '<nav class="menu">';
    foreach (array('/' => '住所から探す', '/gone' => '公表データから消えた事業所', '/data' => 'データ', '/about' => 'このサイトについて') as $u => $t) {
        echo '<a href="' . h($SELF . $u) . '">' . h($t) . '</a>';
    }
    echo '</nav></div></header><main><div class="wrap">';
}

function foot_html() {
    global $SELF, $META, $LATEST;
    echo '</div></main><footer><div class="wrap">';
    echo '<p class="src">' . h($META['attribution']) . '（' . h(tp_label($LATEST)) . '時点）<br>'
       . '出典: <a href="' . h($META['source_url']) . '" rel="nofollow">障害福祉サービス等情報公表システム オープンデータ</a>。'
       . '空き状況・工賃・賃金は公表データに含まれないため、このサイトでは表示しません。</p>';
    echo '<p class="src">提供: <a href="https://exbridge.jp/">株式会社エクスブリッジ</a>（名古屋市）／'
       . '<a href="https://kappstore.exbridge.jp/">オンプレミス版</a>もあります。</p>';
    echo '</div></footer></body></html>';
}

/** 住所の入力欄。どのページからでも引き直せるように置く。 */
function search_form($q = '', $kind = '', $km = 5) {
    global $SELF, $KINDS;
    echo '<form class="form" method="get" action="' . h($SELF) . '/">';
    echo '<input type="text" name="q" value="' . h($q) . '" placeholder="住所を入れる（例: 名古屋市中区三の丸3-1-1）" aria-label="住所">';
    echo '<select name="kind" aria-label="サービス種別"><option value="">すべての種別</option>';
    foreach ($KINDS as $k) { echo '<option value="' . h($k) . '"' . ($kind === $k ? ' selected' : '') . '>' . h($k) . '</option>'; }
    echo '</select>';
    echo '<select name="km" aria-label="範囲">';
    foreach (array(2, 5, 10, 20) as $v) { echo '<option value="' . $v . '"' . ((int)$km === $v ? ' selected' : '') . '>' . $v . 'km以内</option>'; }
    echo '</select>';
    echo '<button class="btn" type="submit">探す</button></form>';
}

/** 事業所1件のカード。 */
function office_card($r, $show_km = true) {
    global $SELF;
    $cls = strpos($r['kind'], 'A型') !== false ? 'a' : (strpos($r['kind'], 'B型') !== false ? 'b' : '');
    echo '<div class="off">';
    echo '<div class="nm"><a href="' . h($SELF . '/office/' . $r['id']) . '">' . h($r['name']) . '</a></div>';
    echo '<div class="meta"><span class="tag ' . $cls . '">' . h($r['kind']) . '</span>';
    if ($show_km && isset($r['km'])) { echo '<span class="km">' . number_format($r['km'], 1) . ' km</span>'; }
    echo '</div>';
    echo '<div class="meta">' . h($r['pref'] . $r['city'] . $r['addr']) . '</div>';
    $bits = array();
    if ($r['capacity'] !== null && $r['capacity'] !== '') { $bits[] = '定員 ' . n($r['capacity']) . '人'; }
    if ($r['tel']) { $bits[] = 'TEL ' . h($r['tel']); }
    if ($r['hours_weekday']) { $bits[] = '平日 ' . h($r['hours_weekday']); }
    if ($bits) { echo '<div class="meta">' . implode('／', $bits) . '</div>'; }
    if ($r['url']) { echo '<div class="meta"><a href="' . h($r['url']) . '" rel="nofollow noopener" target="_blank">事業所のサイト</a></div>'; }
    echo '</div>';
}

/** 推移の棒グラフ（画像もJSも使わない）。 */
function bars($series, $tps) {
    $max = 1;
    foreach ($tps as $t) { if (isset($series[$t])) { $max = max($max, $series[$t]); } }
    echo '<div class="bars">';
    $i = 0;
    foreach ($tps as $t) {
        $v = isset($series[$t]) ? $series[$t] : 0;
        $i++;
        echo '<i class="' . ($i === count($tps) ? 'last' : '') . '" style="height:' . max(2, (int)round($v * 100 / $max)) . '%" title="' . h(tp_label($t) . ' ' . n($v) . '件') . '"></i>';
    }
    echo '</div>';
}

// ── ルーティング ───────────────────────────────────────
$NAT = national($db, $LATEST);
$path = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
$base = 'https://kurage.exbridge.jp' . $SELF;

// 配布用 CSV。加工元が公開データなので、加工後もそのまま持ち出せる形で出す。
if ($path === 'data/offices.csv' || $path === 'data/gone.csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    $out = fopen('php://output', 'w');
    if ($path === 'data/offices.csv') {
        fputcsv($out, array('種別', '事業所番号', '事業所名', '法人名', '都道府県', '市区町村', '番地以降',
                            '電話', 'URL', '緯度', '経度', '定員', '平日', '土曜', '日曜', '祝日', '定休日'));
        foreach ($db->query('SELECT * FROM offices ORDER BY pref, city_key, kind, name') as $r) {
            fputcsv($out, array($r['kind'], $r['office_no'], $r['name'], $r['corp'], $r['pref'], $r['city'], $r['addr'],
                                $r['tel'], $r['url'], $r['lat'], $r['lon'], $r['capacity'],
                                $r['hours_weekday'], $r['hours_sat'], $r['hours_sun'], $r['hours_holiday'], $r['closed']));
        }
    } else {
        fputcsv($out, array('種別', '事業所番号', '事業所名', '都道府県', '市区町村', '最後に公表された時点'));
        foreach ($db->query('SELECT * FROM gone ORDER BY last_tp DESC, pref, city_key, kind, name') as $r) {
            fputcsv($out, array($r['kind'], $r['office_no'], $r['name'], $r['pref'], $r['city'], tp_label($r['last_tp'])));
        }
    }
    fclose($out);
    exit;
}

// JSON API。住所を渡すと、近い事業所と市区町村の様子を返す。
if ($path === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $kind = isset($_GET['kind']) && in_array($_GET['kind'], $KINDS, true) ? $_GET['kind'] : '';
    $km = isset($_GET['km']) ? max(1, min(50, (int)$_GET['km'])) : 5;
    if ($q === '') { http_response_code(400); echo json_encode(array('error' => '住所（q）を指定してください'), JSON_UNESCAPED_UNICODE); exit; }
    $g = geocode($q, $GSI);
    list($pref, $city) = split_address($g['title']);
    $res = array('query' => $q, 'address' => $g['title'], 'pref' => $pref, 'city' => $city,
                 'as_of' => tp_label($LATEST), 'source' => $META['source_url'],
                 'geocoded' => $g['ok'], 'offices' => array(), 'city_stats' => null,
                 'note' => '空き状況は公表データに含まれないため返しません。定員は受け入れ可能人数ではありません。');
    if ($g['ok']) {
        foreach (nearby($db, $g['lat'], $g['lon'], $km, $kind) as $r) {
            $res['offices'][] = array('kind' => $r['kind'], 'name' => $r['name'], 'corp' => $r['corp'],
                'address' => $r['pref'] . $r['city'] . $r['addr'], 'tel' => $r['tel'], 'url' => $r['url'],
                'capacity' => $r['capacity'] === null ? null : (int)$r['capacity'],
                'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'km' => round($r['km'], 2));
        }
    } else {
        $res['error'] = '住所から場所を特定できませんでした（国土地理院の住所検索）。市区町村までの表示だけ返します。';
    }
    if ($pref && $city) { $res['city_stats'] = city_stats($db, $pref, preg_replace('/(.+?市).+区$/u', '$1', $city)); }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($path === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nSitemap: $base/sitemap.xml\n";
    exit;
}

if ($path === 'sitemap.xml' || preg_match('#^sitemap-(\d+)\.xml$#', $path, $sm)) {
    header('Content-Type: application/xml; charset=utf-8');
    $per = 20000;
    if ($path === 'sitemap.xml') {
        $n = (int)$db->query('SELECT count(*) FROM offices')->fetchColumn();
        $pages = (int)ceil($n / $per);
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        echo '<sitemap><loc>' . h($base . '/sitemap-0.xml') . '</loc></sitemap>';
        for ($i = 1; $i <= $pages; $i++) { echo '<sitemap><loc>' . h($base . '/sitemap-' . $i . '.xml') . '</loc></sitemap>'; }
        echo '</sitemapindex>';
        exit;
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    if ((int)$sm[1] === 0) {
        foreach (array('/', '/gone', '/data', '/about') as $u) {
            echo '<url><loc>' . h($base . $u) . '</loc><changefreq>monthly</changefreq></url>';
        }
        foreach ($PREFS as $pp) { echo '<url><loc>' . h($base . '/pref/' . rawurlencode($pp)) . '</loc><changefreq>monthly</changefreq></url>'; }
        $st = $db->query('SELECT DISTINCT pref, city_key FROM offices ORDER BY pref, city_key');
        foreach ($st as $r) {
            echo '<url><loc>' . h($base . '/city/' . rawurlencode($r['pref']) . '/' . rawurlencode($r['city_key'])) . '</loc><changefreq>monthly</changefreq></url>';
        }
        // 政令市・東京23区は区ページも索引に入れる（「名古屋市中区 就労継続支援」で探す人が多い）
        $st = $db->query('SELECT DISTINCT pref, city_key, city FROM offices WHERE city <> city_key ORDER BY pref, city_key, city');
        foreach ($st as $r) {
            echo '<url><loc>' . h($base . '/city/' . rawurlencode($r['pref']) . '/' . rawurlencode($r['city_key']) . '/' . rawurlencode($r['city'])) . '</loc><changefreq>monthly</changefreq></url>';
        }
    } else {
        $off = ($sm[1] - 1) * $per;
        $st = $db->prepare('SELECT id FROM offices ORDER BY id LIMIT ? OFFSET ?');
        $st->execute(array($per, $off));
        foreach ($st as $r) { echo '<url><loc>' . h($base . '/office/' . $r['id']) . '</loc><changefreq>yearly</changefreq></url>'; }
    }
    echo '</urlset>';
    exit;
}

if ($path === 'llms.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "# $SITE\n\n";
    echo "住所から、就労移行支援・就労継続支援A型・就労継続支援B型・就労定着支援の事業所を距離順に探せます。\n";
    echo "出どころは WAM NET（独立行政法人福祉医療機構）障害福祉サービス等情報公表システムのオープンデータ（" . tp_label($LATEST) . "時点）。\n\n";
    echo "## 収録\n";
    foreach ($NAT['kinds'] as $k => $v) { echo "- $k: " . n($v['n']) . "事業所\n"; }
    echo "- 合計 " . n($NAT['total']) . "事業所 / 緯度経度は全件あり\n\n";
    echo "## 言えないこと（重要）\n";
    echo "- 空き状況は公表データに無いので出しません。画面の「定員」は受け入れ可能人数ではありません。\n";
    echo "- 公表データから消えた事業所について、廃止したかどうかは分かりません。消えた事実だけを書いています。\n";
    echo "- 公表件数の増加は事業所の増加とは限りません。自治体の登録が進んだぶんが混ざります。\n\n";
    echo "## 使い方\n";
    echo "- 画面: $base/?q=住所&kind=就労継続支援A型&km=5\n";
    echo "- API : $base/api?q=住所&kind=就労継続支援A型&km=5 （JSON）\n";
    echo "- 都道府県: $base/pref/{都道府県}\n";
    echo "- 市区町村: $base/city/{都道府県}/{市区町村}（政令市は .../{市区町村}/{区} まで）\n";
    echo "- CSV : $base/data/offices.csv , $base/data/gone.csv\n";
    exit;
}

// ── 事業所ページ ───────────────────────────────────────
if (preg_match('#^office/(\d+)$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM offices WHERE id = ?');
    $st->execute(array((int)$m[1]));
    $o = $st->fetch();
    if (!$o) { http_response_code(404); head_html('見つかりません｜' . $SITE, '指定された事業所は見つかりませんでした。', '/'); echo '<h1>見つかりません</h1><p class="lead">この番号の事業所は公表データにありません。</p>'; search_form(); foot_html(); exit; }
    $title = $o['name'] . '（' . $o['kind'] . '・' . $o['pref'] . $o['city'] . '）の定員・住所・連絡先';
    $desc = $o['pref'] . $o['city'] . $o['addr'] . 'の' . $o['kind'] . '「' . $o['name'] . '」。'
          . ($o['capacity'] !== null ? '定員' . n($o['capacity']) . '人。' : '定員は公表されていません。')
          . '運営は' . $o['corp'] . '。近くの就労支援事業所も距離順で探せます（' . tp_label($LATEST) . '時点の公表データ）。';
    $ld = array('@type' => 'GovernmentService', 'name' => $o['name'],
                'serviceType' => $o['kind'], 'areaServed' => $o['pref'] . $o['city'],
                'provider' => array('@type' => 'Organization', 'name' => $o['corp']),
                'availableChannel' => array('@type' => 'ServiceChannel',
                    'serviceLocation' => array('@type' => 'Place', 'name' => $o['name'],
                        'address' => array('@type' => 'PostalAddress', 'addressRegion' => $o['pref'],
                                           'addressLocality' => $o['city'], 'streetAddress' => $o['addr'], 'addressCountry' => 'JP'),
                        'geo' => array('@type' => 'GeoCoordinates', 'latitude' => (float)$o['lat'], 'longitude' => (float)$o['lon'])),
                    'servicePhone' => $o['tel']));
    head_html($title . '｜' . $SITE, $desc, '/office/' . $o['id'], $ld);
    echo '<h1>' . h($o['name']) . '</h1>';
    echo '<p class="lead"><span class="tag ' . (strpos($o['kind'], 'A型') !== false ? 'a' : 'b') . '">' . h($o['kind']) . '</span>'
       . h($o['pref'] . $o['city'] . $o['addr']) . '</p>';
    echo '<div class="panel"><div class="grid">';
    echo '<div class="card"><div class="k">定員</div><div class="v">' . ($o['capacity'] !== null ? n($o['capacity']) . '<span style="font-size:14px">人</span>' : '—') . '</div>'
       . '<div class="s">' . ($o['capacity'] !== null ? 'いま空いている人数ではありません' : '公表されていません') . '</div></div>';
    echo '<div class="card"><div class="k">電話</div><div class="v" style="font-size:18px">' . ($o['tel'] ? h($o['tel']) : '—') . '</div><div class="s">空きは事業所へ直接</div></div>';
    echo '<div class="card"><div class="k">運営法人</div><div class="v" style="font-size:16px">' . h($o['corp']) . '</div>'
       . '<div class="s">' . ($o['corp_no'] ? '法人番号 ' . h($o['corp_no']) : '') . '</div></div>';
    echo '<div class="card"><div class="k">事業所番号</div><div class="v" style="font-size:18px">' . h($o['office_no']) . '</div><div class="s">指定 ' . h($o['designator']) . '</div></div>';
    echo '</div>';
    $hrs = array('平日' => $o['hours_weekday'], '土曜' => $o['hours_sat'], '日曜' => $o['hours_sun'], '祝日' => $o['hours_holiday']);
    $has = false; foreach ($hrs as $v) { if ($v) { $has = true; } }
    if ($has || $o['closed']) {
        echo '<h3>利用できる時間</h3><div class="tscroll"><table class="t"><tbody>';
        foreach ($hrs as $k => $v) { echo '<tr><th>' . h($k) . '</th><td>' . ($v ? h($v) : '公表なし') . '</td></tr>'; }
        if ($o['closed']) { echo '<tr><th>定休日</th><td>' . h($o['closed']) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
    if ($o['note']) { echo '<h3>事業所からの特記事項</h3><p>' . h($o['note']) . '</p>'; }
    if ($o['url']) { echo '<p><a class="btn ghost" href="' . h($o['url']) . '" rel="nofollow noopener" target="_blank">事業所のサイトを開く</a></p>'; }
    echo '</div>';
    $near = nearby($db, (float)$o['lat'], (float)$o['lon'], 5, '', 12);
    echo '<h2>この事業所の近くにある就労支援事業所</h2>';
    $shown = 0;
    foreach ($near as $r) { if ((int)$r['id'] === (int)$o['id']) { continue; } office_card($r); $shown++; if ($shown >= 8) { break; } }
    if (!$shown) { echo '<p class="lead">半径5km以内に、ほかの事業所は公表データにありません。</p>'; }
    echo '<p><a class="btn ghost" href="' . h($SELF . '/city/' . rawurlencode($o['pref']) . '/' . rawurlencode($o['city_key'])) . '">'
       . h($o['pref'] . $o['city_key']) . 'の就労支援事業所をまとめて見る</a></p>';
    foot_html();
    exit;
}

// ── 都道府県ページ ─────────────────────────────────────
if (preg_match('#^pref/([^/]+)$#', $path, $m)) {
    $pref = rawurldecode($m[1]);
    if (!in_array($pref, $PREFS, true)) {
        http_response_code(404);
        head_html('見つかりません｜' . $SITE, '指定された都道府県はありません。', '/');
        echo '<h1>見つかりません</h1>'; search_form(); foot_html(); exit;
    }
    $kc = array(); $total = 0;
    $st = $db->prepare('SELECT kind, count(*) n, sum(capacity) cap FROM offices WHERE pref=? GROUP BY kind');
    $st->execute(array($pref));
    foreach ($st as $r) { $kc[$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap']); $total += (int)$r['n']; }
    if (!$total) {
        http_response_code(404);
        head_html('見つかりません｜' . $SITE, '公表データにありません。', '/');
        echo '<h1>公表データにありません</h1>'; search_form(); foot_html(); exit;
    }
    $a = isset($kc['就労継続支援A型']) ? $kc['就労継続支援A型']['n'] : 0;
    $b = isset($kc['就労継続支援B型']) ? $kc['就労継続支援B型']['n'] : 0;
    $gone_n = 0; $gone_after = 0;
    $st = $db->prepare('SELECT last_tp, count(*) n FROM gone WHERE pref=? GROUP BY last_tp');
    $st->execute(array($pref));
    foreach ($st as $r) { $gone_n += (int)$r['n']; if ($r['last_tp'] >= '202403') { $gone_after += (int)$r['n']; } }

    $title = $pref . 'の就労継続支援A型・B型事業所一覧（市区町村別・' . n($total) . 'か所）';
    $desc = $pref . 'の就労継続支援A型' . n($a) . 'か所、B型' . n($b) . 'か所など計' . n($total) . 'か所を市区町村別にまとめました。'
          . '定員・住所・電話つき。2024年3月末より後に公表データから消えた事業所は' . n($gone_after) . '件。' . tp_label($LATEST) . '時点。';
    head_html($title . '｜' . $SITE, $desc, '/pref/' . rawurlencode($pref));
    echo '<h1>' . h($pref) . 'の就労支援事業所</h1>';
    echo '<p class="lead">' . h(tp_label($LATEST)) . '時点で公表されている事業所を市区町村別にまとめました。</p>';
    echo '<div class="panel"><div class="grid">';
    foreach ($KINDS as $k) {
        $v = isset($kc[$k]) ? $kc[$k] : null;
        echo '<div class="card' . ($v ? '' : ' none') . '"><div class="k">' . h($k) . '</div>';
        echo '<div class="v">' . ($v ? n($v['n']) . '<span style="font-size:14px">か所</span>' : '0') . '</div>';
        echo '<div class="s">' . ($v && $v['cap'] ? '定員 計' . n($v['cap']) . '人' : '定員の公表なし') . '</div></div>';
    }
    echo '</div></div>';

    echo '<h2>公表件数の推移</h2>';
    echo '<p class="lead">半年ごとの公表件数です。<strong>事業所数そのものではありません</strong>——自治体の登録が進んだぶんも混ざります。</p>';
    $ser = array();
    $st = $db->prepare('SELECT tp, kind, sum(n) n FROM counts WHERE pref=? GROUP BY tp, kind');
    $st->execute(array($pref));
    foreach ($st as $r) { $ser[$r['kind']][$r['tp']] = (int)$r['n']; }
    echo '<div class="panel"><div class="grid">';
    foreach ($KINDS as $k) {
        if (!isset($ser[$k])) { continue; }
        $first = null; $last = null;
        foreach ($TPS as $t) { if (isset($ser[$k][$t])) { if ($first === null) { $first = $ser[$k][$t]; } $last = $ser[$k][$t]; } }
        echo '<div class="card"><div class="k">' . h($k) . '</div>';
        bars($ser[$k], $TPS);
        echo '<div class="s">' . h(tp_label($TPS[0])) . ' ' . n($first) . ' → ' . h(tp_label($LATEST)) . ' ' . n($last) . '</div></div>';
    }
    echo '</div></div>';

    if ($gone_n) {
        echo '<h2>公表データから消えた事業所</h2>';
        echo '<p class="lead">' . h($pref) . 'では、これまでに<strong>' . n($gone_n) . '件</strong>が公表データから消えています'
           . '（うち2024年3月末より後が' . n($gone_after) . '件）。<strong>消えた理由は公表されていません。</strong></p>';
        echo '<div class="tscroll"><table class="t"><thead><tr><th>最後に公表された時点</th><th>種別</th><th>事業所名</th><th>所在</th></tr></thead><tbody>';
        $st = $db->prepare('SELECT kind, name, city, last_tp FROM gone WHERE pref=? ORDER BY last_tp DESC, city, kind, name LIMIT 300');
        $st->execute(array($pref));
        foreach ($st as $g) {
            echo '<tr><td>' . h(tp_label($g['last_tp'])) . '</td><td>' . h($g['kind']) . '</td><td>' . h($g['name']) . '</td><td>' . h($g['city']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($gone_n > 300) { echo '<p class="src">新しいものから300件まで表示しています。全件は <a href="' . h($SELF . '/data/gone.csv') . '">CSV</a> で取れます。</p>'; }
    }

    echo '<h2>市区町村から選ぶ</h2><div class="panel"><div class="tscroll"><table class="t"><thead><tr><th>市区町村</th>';
    foreach ($KINDS as $k) { echo '<th class="n">' . h(str_replace(array('就労継続支援', '就労'), '', $k)) . '</th>'; }
    echo '<th class="n">計</th></tr></thead><tbody>';
    $rows = array();
    $st = $db->prepare('SELECT city_key, kind, count(*) n FROM offices WHERE pref=? GROUP BY city_key, kind');
    $st->execute(array($pref));
    foreach ($st as $r) { $rows[$r['city_key']][$r['kind']] = (int)$r['n']; }
    uasort($rows, function ($x, $y) { return array_sum($y) - array_sum($x); });
    foreach ($rows as $ckey => $kk) {
        echo '<tr><td><a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ckey)) . '">' . h($ckey) . '</a></td>';
        foreach ($KINDS as $k) { echo '<td class="n">' . (isset($kk[$k]) ? n($kk[$k]) : '—') . '</td>'; }
        echo '<td class="n">' . n(array_sum($kk)) . '</td></tr>';
    }
    echo '</tbody></table></div><p class="src">列は左から A型・B型・移行支援・定着支援です。</p></div>';
    echo '<h2>住所から、通える事業所を探す</h2>';
    search_form($pref);
    foot_html();
    exit;
}

// ── 市区町村ページ ─────────────────────────────────────
if (preg_match('#^city/([^/]+)/([^/]+)(?:/([^/]+))?$#', $path, $m)) {
    $pref = rawurldecode($m[1]); $ck = rawurldecode($m[2]);
    $ward = isset($m[3]) ? rawurldecode($m[3]) : '';
    $s = city_stats($db, $pref, $ck);
    $here = '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . ($ward ? '/' . rawurlencode($ward) : '');
    if ($ward) {
        $chk = $db->prepare('SELECT count(*) FROM offices WHERE pref=? AND city_key=? AND city=?');
        $chk->execute(array($pref, $ck, $ward));
        if (!(int)$chk->fetchColumn()) { $ward = ''; $here = '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck); }
    }
    if (!$s['total'] && !count($s['gone'])) {
        http_response_code(404);
        head_html('見つかりません｜' . $SITE, '指定された市区町村は公表データにありません。', '/');
        echo '<h1>公表データにありません</h1><p class="lead">' . h($pref . $ck) . 'の就労支援事業所は、いまの公表データに載っていません。</p>';
        search_form(); foot_html(); exit;
    }
    $where = 'pref=? AND city_key=?'; $args = array($pref, $ck);
    if ($ward) { $where .= ' AND city=?'; $args[] = $ward; }
    $place = $pref . ($ward ? $ward : $ck);

    // 種別ごとの件数。区が指定されていればその区だけ数える。
    $kc = array(); $total = 0; $capsum = 0;
    $st = $db->prepare("SELECT kind, count(*) n, sum(capacity) cap, sum(capacity IS NULL) nocap FROM offices WHERE $where GROUP BY kind");
    $st->execute($args);
    foreach ($st as $r) {
        $kc[$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap'], 'nocap' => (int)$r['nocap']);
        $total += (int)$r['n']; $capsum += (int)$r['cap'];
    }

    $a = isset($kc['就労継続支援A型']) ? $kc['就労継続支援A型']['n'] : 0;
    $b = isset($kc['就労継続支援B型']) ? $kc['就労継続支援B型']['n'] : 0;
    $title = $place . 'の就労継続支援A型・B型事業所一覧（' . n($total) . 'か所・定員つき）';
    $desc = $place . 'の就労継続支援A型' . n($a) . 'か所、B型' . n($b) . 'か所など計' . n($total) . 'か所を、定員・住所・電話つきで一覧にしました。'
          . (!$ward && count($s['gone']) ? '公表データから消えた事業所' . n(count($s['gone'])) . '件も掲載。' : '')
          . tp_label($LATEST) . '時点の公表データ。空き状況は各事業所へ。';
    head_html($title . '｜' . $SITE, $desc, $here);

    echo '<h1>' . h($place) . 'の就労支援事業所</h1>';
    echo '<p class="lead">' . h(tp_label($LATEST)) . '時点で公表されている事業所です。空き状況は公表されていません。'
       . ($ward ? ' <a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck)) . '">' . h($ck) . '全体を見る</a>' : '') . '</p>';
    echo '<div class="panel"><div class="grid">';
    foreach ($KINDS as $k) {
        $v = isset($kc[$k]) ? $kc[$k] : null;
        echo '<div class="card' . ($v ? '' : ' none') . '"><div class="k">' . h($k) . '</div>';
        echo '<div class="v">' . ($v ? n($v['n']) . '<span style="font-size:14px">か所</span>' : '0') . '</div>';
        echo '<div class="s">' . ($v && $v['cap'] ? '定員 計' . n($v['cap']) . '人' . ($v['nocap'] ? '（' . n($v['nocap']) . 'か所は定員の公表なし）' : '') : '定員の公表なし') . '</div></div>';
    }
    echo '</div></div>';

    if (count($s['wards'])) {
        echo '<h2>区から選ぶ</h2><div class="panel"><p class="src" style="line-height:2.4">';
        foreach ($s['wards'] as $w) {
            $on = ($w['city'] === $ward);
            echo '<a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . '/' . rawurlencode($w['city'])) . '"'
               . ($on ? ' style="font-weight:800"' : '') . '>' . h(str_replace($ck, '', $w['city'])) . '</a>（' . n($w['n']) . '）　';
        }
        echo '</p></div>';
    }

    if (!$ward) {
        echo '<h2>公表件数の推移</h2>';
        echo '<p class="lead">半年ごとの公表件数です。<strong>事業所数そのものではありません</strong>——自治体の登録が進んだぶんも混ざります。</p>';
        echo '<div class="panel"><div class="grid">';
        foreach ($KINDS as $k) {
            if (!isset($s['series'][$k])) { continue; }
            $ser = $s['series'][$k];
            $first = null; $last = null;
            foreach ($TPS as $t) { if (isset($ser[$t])) { if ($first === null) { $first = $ser[$t]; } $last = $ser[$t]; } }
            echo '<div class="card"><div class="k">' . h($k) . '</div>';
            bars($ser, $TPS);
            echo '<div class="s">' . h(tp_label($TPS[0])) . ' ' . n($first) . ' → ' . h(tp_label($LATEST)) . ' ' . n($last) . '</div></div>';
        }
        echo '</div></div>';

        echo '<h2>公表データから消えた事業所</h2>';
        if (count($s['gone'])) {
            $recent = 0;
            foreach ($s['gone'] as $g) { if ($g['last_tp'] >= '202403') { $recent++; } }
            echo '<p class="lead">前の時点には載っていて、' . h(tp_label($LATEST)) . '時点には載っていない事業所です。'
               . '2024年3月末より後に消えたのは<strong>' . n($recent) . '件</strong>です。'
               . '<strong>消えた理由は公表されていません</strong>（廃止・指定の取消・登録の更新漏れなど、どれかは分かりません）。</p>';
            echo '<div class="tscroll"><table class="t"><thead><tr><th>最後に公表された時点</th><th>種別</th><th>事業所名</th><th>所在</th></tr></thead><tbody>';
            foreach ($s['gone'] as $g) {
                echo '<tr><td>' . h(tp_label($g['last_tp'])) . '</td><td>' . h($g['kind']) . '</td><td>' . h($g['name']) . '</td><td>' . h($g['city']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="lead">' . h($pref . $ck) . 'では、公表データから消えた事業所はありません。</p>';
        }
    }

    // 一覧。1ページ50件で切る（政令市は700件を超えるので、1枚に出すとスマホで開けない）
    $per = 50;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) { $page = $pages; }
    echo '<h2>事業所一覧</h2>';
    if ($pages > 1) { echo '<p class="lead">' . n($total) . 'か所のうち ' . n(($page - 1) * $per + 1) . '〜' . n(min($total, $page * $per)) . '件目（' . $page . '/' . $pages . 'ページ）</p>'; }
    $st = $db->prepare("SELECT * FROM offices WHERE $where ORDER BY kind, city, name LIMIT ? OFFSET ?");
    $st->execute(array_merge($args, array($per, ($page - 1) * $per)));
    $cur = '';
    foreach ($st as $r) {
        if ($r['kind'] !== $cur) { $cur = $r['kind']; echo '<h3>' . h($cur) . '</h3>'; }
        office_card($r, false);
    }
    if ($pages > 1) {
        echo '<p class="src" style="line-height:2.4">';
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === $page) { echo '<strong>' . $i . '</strong>　'; }
            else { echo '<a href="' . h($SELF . $here . '?page=' . $i) . '">' . $i . '</a>　'; }
        }
        echo '</p>';
    }
    echo '<h2>住所から、通える事業所を探す</h2>';
    search_form($place);
    foot_html();
    exit;
}

// ── 公表データから消えた事業所（全国） ───────────────────
if ($path === 'gone') {
    $title = '就労継続支援A型が公表データから消えた数の推移（全国・市区町村別）';
    $desc = '就労移行支援・就労継続支援A型/B型・就労定着支援について、前の時点には公表されていて次の時点には載っていない事業所の数を、半年ごとに数えました。都道府県別の内訳とCSVつき。';
    head_html($title . '｜' . $SITE, $desc, '/gone');
    echo '<h1>公表データから消えた事業所</h1>';
    echo '<p class="lead">WAM NET の公表データは半年ごとに出ます。ある時点に載っていた事業所番号が、次から載らなくなることがあります。'
       . 'ここではその数を数えています。<strong>消えた理由は公表されていません。</strong>廃止したのか、指定が取り消されたのか、'
       . '登録が更新されなかっただけなのかは、この数字からは分かりません。</p>';
    echo '<div class="tscroll"><table class="t"><thead><tr><th>最後に公表された時点</th>';
    foreach ($KINDS as $k) { echo '<th class="n">' . h($k) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($TPS as $t) {
        if ($t === $LATEST || !isset($NAT['gone'][$t])) { continue; }
        echo '<tr><td>' . h(tp_label($t)) . '</td>';
        foreach ($KINDS as $k) {
            $v = isset($NAT['gone'][$t][$k]) ? $NAT['gone'][$t][$k] : 0;
            echo '<td class="n">' . n($v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="src">「2024年3月末」の行は、2024年3月末時点を最後に公表データから消えた事業所の数です（2024年度に消えたもの）。</p>';

    echo '<h2>都道府県別（2024年3月末時点より後に消えたもの）</h2>';
    echo '<p class="lead">障害福祉サービスの報酬改定は2024年4月に行われました。その前後で分けて数えています。</p>';
    $before = array(); $after = array();
    $st = $db->query("SELECT pref, kind, last_tp, count(*) n FROM gone GROUP BY pref, kind, last_tp");
    foreach ($st as $r) {
        $bin = ($r['last_tp'] >= '202403') ? 'after' : 'before';
        if ($bin === 'after') { $after[$r['pref']][$r['kind']] = (isset($after[$r['pref']][$r['kind']]) ? $after[$r['pref']][$r['kind']] : 0) + (int)$r['n']; }
        else { $before[$r['pref']][$r['kind']] = (isset($before[$r['pref']][$r['kind']]) ? $before[$r['pref']][$r['kind']] : 0) + (int)$r['n']; }
    }
    $rows = array();
    foreach ($after as $p => $kk) {
        $a = isset($kk['就労継続支援A型']) ? $kk['就労継続支援A型'] : 0;
        $b0 = isset($before[$p]['就労継続支援A型']) ? $before[$p]['就労継続支援A型'] : 0;
        $rows[] = array('pref' => $p, 'a_after' => $a, 'a_before' => $b0, 'all' => array_sum($kk));
    }
    usort($rows, function ($x, $y) { return $y['a_after'] - $x['a_after']; });
    echo '<div class="tscroll"><table class="t"><thead><tr><th>都道府県</th><th class="n">A型（2024年度以降）</th><th class="n">A型（それ以前）</th><th class="n">4種別の合計</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['pref']) . '</td><td class="n">' . n($r['a_after']) . '</td><td class="n">' . n($r['a_before']) . '</td><td class="n">' . n($r['all']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p><a class="btn ghost" href="' . h($SELF . '/data/gone.csv') . '">消えた事業所の一覧をCSVで取る</a></p>';
    echo '<h2>市区町村ごとに見る</h2>';
    search_form();
    foot_html();
    exit;
}

// ── データ ─────────────────────────────────────────────
if ($path === 'data') {
    head_html('就労支援事業所データのダウンロード（CSV・API）｜' . $SITE,
        '全国' . n($NAT['total']) . '事業所の一覧CSVと、住所から引けるJSON APIです。出典表示のうえ自由に使えます。', '/data');
    echo '<h1>データ</h1>';
    echo '<p class="lead">画面で見せているものと同じデータです。加工元が公開データなので、そのまま持ち出せる形で置いています。</p>';
    echo '<div class="panel"><h3>CSV</h3><ul class="plain">';
    echo '<li><a href="' . h($SELF . '/data/offices.csv') . '">offices.csv</a> — ' . n($NAT['total']) . '事業所（種別・住所・緯度経度・定員・営業時間・連絡先）</li>';
    echo '<li><a href="' . h($SELF . '/data/gone.csv') . '">gone.csv</a> — 公表データから消えた事業所と、最後に公表された時点</li>';
    echo '</ul><h3>JSON API</h3>';
    echo '<p class="src">' . h($base) . '/api?q=<em>住所</em>&amp;kind=<em>種別</em>&amp;km=<em>範囲</em></p>';
    echo '<p><a class="btn ghost" href="' . h($SELF . '/api?q=' . rawurlencode('名古屋市中区三の丸3-1-1') . '&km=3') . '">試しに叩いてみる</a></p>';
    echo '<h3>出典表示</h3><p class="src">' . h($META['attribution']) . '</p></div>';
    echo '<div class="panel"><h3>収録している数</h3><div class="tscroll"><table class="t"><thead><tr><th>サービス種別</th><th class="n">事業所</th><th class="n">定員の合計</th></tr></thead><tbody>';
    foreach ($KINDS as $k) {
        $v = isset($NAT['kinds'][$k]) ? $NAT['kinds'][$k] : array('n' => 0, 'cap' => 0);
        echo '<tr><td>' . h($k) . '</td><td class="n">' . n($v['n']) . '</td><td class="n">' . ($v['cap'] ? n($v['cap']) : '—') . '</td></tr>';
    }
    echo '</tbody></table></div><p class="src">就労定着支援には定員という考え方がないため、定員は空欄です。</p></div>';
    foot_html();
    exit;
}

// ── このサイトについて ──────────────────────────────────
if ($path === 'about') {
    head_html('このサイトについて｜' . $SITE,
        'データの出どころ、分かること、分からないことを書いています。空き状況は公表されていないため扱いません。', '/about');
    echo '<h1>このサイトについて</h1>';
    echo '<div class="panel"><h3>何が分かるか</h3><ul class="plain">'
       . '<li>住所から、通える範囲にある就労移行支援・就労継続支援A型/B型・就労定着支援の事業所（距離順）</li>'
       . '<li>それぞれの定員・所在地・電話・営業時間・事業所のサイト</li>'
       . '<li>市区町村ごとの公表件数の推移と、公表データから消えた事業所</li>'
       . '</ul></div>';
    echo '<div class="panel"><h3>何が分からないか（ここが大事です）</h3><ul class="plain">'
       . '<li><strong>空き状況は分かりません。</strong>公表データに入っていないからです。画面に出している定員は、いま受け入れられる人数ではありません。</li>'
       . '<li><strong>消えた事業所が廃止したかどうかは分かりません。</strong>公表データから消えた、という事実だけを書いています。</li>'
       . '<li><strong>公表件数が増えた＝事業所が増えた、ではありません。</strong>自治体の登録が進んだぶんが混ざります。だから全国の増減は「公表された件数」と書いています。</li>'
       . '<li>工賃・賃金・作業の内容は公表データにありません。事業所のサイトか、直接のお問い合わせでご確認ください。</li>'
       . '</ul></div>';
    echo '<div class="panel"><h3>データの出どころ</h3>'
       . '<p>WAM NET（独立行政法人福祉医療機構）が公開している<a href="' . h($META['source_url']) . '" rel="nofollow">障害福祉サービス等情報公表システムのオープンデータ</a>です。'
       . '営利・非営利を問わず二次利用できると明記された公開データで、毎年3月末・9月末に更新されます。'
       . 'このサイトは' . h(tp_label($LATEST)) . '時点のものを使っています（取り込み ' . h($META['built_at']) . '）。</p>'
       . '<p class="src">' . h($META['attribution']) . '</p></div>';
    echo '<div class="panel"><h3>同じ仕組みを自分のところで動かす</h3>'
       . '<p>事務所・自治体・会社の名前で公開できるオンプレミス版をソースコード同梱で出しています。'
       . 'PHPが動くレンタルサーバーにファイルを置くだけで動き、判定は置いた場所で完結します（外部のAIやAPIには出しません）。</p>'
       . '<p><a class="btn" href="https://kappstore.exbridge.jp/?ref=kshuro-about">kappstore で見る</a></p></div>';
    foot_html();
    exit;
}

// ── トップ（住所から探す） ──────────────────────────────
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$kind = isset($_GET['kind']) && in_array($_GET['kind'], $KINDS, true) ? $_GET['kind'] : '';
$km = isset($_GET['km']) ? max(1, min(50, (int)$_GET['km'])) : 5;

$g = null; $pref = null; $city = null; $ck = null; $list = array(); $cs = null;
if ($q !== '') {
    $g = geocode($q, $GSI);
    list($pref, $city) = split_address($g['title']);
    if ($city) { $ck = preg_replace('/(.+?市).+区$/u', '$1', $city); }
    if ($g['ok']) { $list = nearby($db, $g['lat'], $g['lon'], $km, $kind); }
    if ($pref && $ck) { $cs = city_stats($db, $pref, $ck); }
}

$na = $NAT['kinds']['就労継続支援A型']['n'];
$nb = $NAT['kinds']['就労継続支援B型']['n'];
$ward_here = ($q !== '' && $g) ? ward_of($g['title'], $ck) : '';
$place_here = $pref . ($ward_here ? $ward_here : $ck);
if ($q !== '' && $pref && $ck) {
    $title = $place_here . 'の就労継続支援A型・B型事業所を住所から探す（近い順）';
    $desc = $place_here . 'の近くにある就労移行支援・就労継続支援A型/B型・就労定着支援の事業所を、距離順に定員つきで表示しました。'
          . tp_label($LATEST) . '時点の公表データ。空き状況は各事業所へお問い合わせください。';
} else {
    $title = '就労継続支援A型・B型の事業所を住所から探す｜全国' . n($NAT['total']) . 'か所の定員・連絡先';
    $desc = '住所を入れると、通える範囲の就労継続支援A型' . n($na) . 'か所・B型' . n($nb) . 'か所・就労移行支援・就労定着支援を距離順に表示します。'
          . '市区町村ごとの公表件数の推移と、公表データから消えた事業所も見られます。国のオープンデータのみ使用。';
}
head_html($title . '｜' . $SITE, $desc, '/');

echo '<h1>' . ($q !== '' ? h(($pref && $ck) ? $place_here . 'の就労支援事業所' : '検索結果') : '就労継続支援A型・B型の事業所を住所から探す') . '</h1>';
if ($q === '') {
    echo '<p class="lead">住所を入れると、通える範囲にある就労移行支援・就労継続支援A型/B型・就労定着支援の事業所を近い順に出します。'
       . '国が公開しているデータだけを使っています。<strong>空き状況は公表されていないので扱いません。</strong></p>';
}
echo '<div class="panel">';
search_form($q, $kind, $km);
echo '</div>';

if ($q !== '') {
    if (!$g['ok']) {
        echo '<div class="panel"><p><strong>住所から場所を特定できませんでした。</strong>'
           . '国土地理院の住所検索が応答しなかったか、住所の表記が見つかりませんでした。'
           . '「発表されていない」という意味ではありません。市区町村名だけ（例: 名古屋市中区）でもう一度お試しください。</p></div>';
    } else {
        echo '<p class="lead">' . h($g['title']) . ' から ' . (int)$km . 'km 以内'
           . ($kind ? '／' . h($kind) : '') . '：<strong>' . n(count($list)) . '件</strong>'
           . (count($list) >= 40 ? '（近い順に40件まで）' : '') . '</p>';
        if (!count($list)) {
            echo '<div class="panel"><p>この範囲には、公表データに載っている事業所がありません。範囲を広げるか、種別の指定を外してお試しください。</p></div>';
        }
        foreach ($list as $r) { office_card($r); }
    }
    if ($cs) {
        echo '<h2>' . h($pref . $ck) . 'の状況</h2>';
        echo '<div class="panel"><div class="grid">';
        foreach ($KINDS as $k) {
            $v = isset($cs['kinds'][$k]) ? $cs['kinds'][$k] : null;
            echo '<div class="card' . ($v ? '' : ' none') . '"><div class="k">' . h($k) . '</div>';
            echo '<div class="v">' . ($v ? n($v['n']) . '<span style="font-size:14px">か所</span>' : '0') . '</div>';
            echo '<div class="s">' . ($v && $v['cap'] ? '定員 計' . n($v['cap']) . '人' : '定員の公表なし') . '</div></div>';
        }
        echo '</div>';
        if (count($cs['gone'])) {
            $recent = 0;
            foreach ($cs['gone'] as $gg) { if ($gg['last_tp'] >= '202403') { $recent++; } }
            echo '<p style="margin:14px 0 0">' . h($pref . $ck) . 'では、2024年3月末より後に<strong>' . n($recent) . '件</strong>が公表データから消えています'
               . '（全期間で' . n(count($cs['gone'])) . '件）。理由は公表されていません。</p>';
        }
        echo '<p style="margin:10px 0 0">';
        if ($ward_here) {
            echo '<a class="btn ghost" href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . '/' . rawurlencode($ward_here)) . '">'
               . h($ward_here) . 'の事業所一覧</a> ';
        }
        echo '<a class="btn ghost" href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck)) . '">'
           . h($pref . $ck) . 'の一覧・推移・消えた事業所を見る</a></p>';
        echo '</div>';
    } elseif ($pref) {
        echo '<p class="lead">' . h($pref) . 'までは分かりましたが、市区町村を特定できませんでした。市区町村名を入れてお試しください。</p>';
    }
} else {
    echo '<h2>全国でいま公表されている数</h2>';
    echo '<div class="panel"><div class="grid">';
    foreach ($KINDS as $k) {
        $v = $NAT['kinds'][$k];
        echo '<div class="card"><div class="k">' . h($k) . '</div><div class="v">' . n($v['n']) . '<span style="font-size:14px">か所</span></div>';
        echo '<div class="s">' . ($v['cap'] ? '定員 計' . n($v['cap']) . '人' : '定員という考え方がありません') . '</div></div>';
    }
    echo '</div><p class="src">' . h(tp_label($LATEST)) . '時点。定員は、いま受け入れられる人数ではありません。</p></div>';

    $ga = array(); foreach ($TPS as $t) { $ga[$t] = isset($NAT['gone'][$t]['就労継続支援A型']) ? $NAT['gone'][$t]['就労継続支援A型'] : 0; }
    $sum_after = 0; $sum_before = 0;
    foreach ($TPS as $t) { if ($t === $LATEST) { continue; } if ($t >= '202403') { $sum_after += $ga[$t]; } else { $sum_before += $ga[$t]; } }
    echo '<h2>公表データから消えたA型事業所</h2>';
    echo '<div class="panel">';
    echo '<p>就労継続支援A型は、2024年4月の報酬改定のあと、公表データから消える事業所が増えました。'
       . '2024年3月末時点より後に消えたのは<strong>' . n($sum_after) . '件</strong>、それ以前は' . n($sum_before) . '件です。</p>';
    echo '<p class="src">消えた理由は公表されていないため、このサイトでは「廃止した」とは書きません。消えたという事実だけを数えています。</p>';
    echo '<p><a class="btn ghost" href="' . h($SELF . '/gone') . '">都道府県別に見る</a></p>';
    echo '</div>';

    echo '<h2>都道府県から探す</h2><div class="panel"><p class="src" style="line-height:2.4">';
    $cnt = array();
    foreach ($db->query('SELECT pref, count(*) n FROM offices GROUP BY pref') as $r) { $cnt[$r['pref']] = (int)$r['n']; }
    foreach ($PREFS as $pp) {
        if (!isset($cnt[$pp])) { continue; }
        echo '<a href="' . h($SELF . '/pref/' . rawurlencode($pp)) . '">' . h($pp) . '</a>（' . n($cnt[$pp]) . '）　';
    }
    echo '</p></div>';
}
foot_html();
