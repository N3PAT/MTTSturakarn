<?php
date_default_timezone_set('Asia/Bangkok');

require_once 'db.php';
require_once 'functions.php';

/* ===================== API ค้นหานักเรียน ===================== */
if (($_GET['action'] ?? '') === 'get_student_name') {
    $code = trim((string)($_GET['code'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $code)) {
        json_out(['found' => false]);
    }
    try {
        $window = vote_window($conn);
        $info = get_student_info($conn, $code, $window['target_date']);
        if (!$info) { json_out(['found' => false]); }
        json_out(['found' => true, 'name' => $info['name'], 'section' => $info['section'], 'forced' => $info['forced']]);
    } catch (Throwable $e) {
        error_log('fill lookup: ' . $e->getMessage());
        json_out(['found' => false, 'error' => true], 503);
    }
}

/* ===================== session / ส่งข้อมูล ===================== */
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$show_loading = empty($_SESSION['has_seen_loading_fill']);
$_SESSION['has_seen_loading_fill'] = true;

$window         = vote_window($conn);
$target_date    = $window['target_date'];
$is_voting_open = $window['is_open'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = process_vote($conn, $_POST, $target_date, $is_voting_open);
    if (($_POST['ajax'] ?? '') === '1') {
        json_out($result['body'], $result['http']);
    }
    $_SESSION['flash'] = $result['body'];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = $_SESSION['csrf_token'];
session_write_close();

$date_th_full = thai_date_full($target_date);
$tiles = [
    'home'     => ['๑', 'กลับภูมิลำเนา', 'home'],
    'stay'     => ['๒', 'ประสงค์อยู่ รร.ชท.', 'pin'],
    'duty'     => ['๓', 'เวรรักษาการณ์ (วันศุกร์)', 'shield'],
    'restrict' => ['๔', 'กักบริเวณ', 'lock'],
];
header('Cache-Control: private, no-cache');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#5a42a0">
<title>แจ้งความประสงค์ - MTTS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<noscript><style>#page-loader{display:none}</style></noscript>
<style>
:root{
  --primary:#5a42a0; --primary-dark:#473282; --ink:#1e293b; --mute:#64748b; --paper:#f6f8fc; --line:#dbe1ea;
  --ok:#15803d; --ok-bg:#f0fdf4; --bad:#b91c1c; --bad-bg:#fef2f2; --warn:#b45309; --warn-bg:#fffbeb;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{margin:0;font-family:'Prompt',system-ui,sans-serif;background:#e2e8f0;color:var(--ink);display:flex;justify-content:center;min-height:100vh}
.app{width:100%;max-width:430px;background:var(--paper);min-height:100vh;padding-bottom:96px;box-shadow:0 0 24px rgba(15,23,42,.08)}
.ic{width:1.15em;height:1.15em;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;vertical-align:-.2em}

/* หัวหน้า */
.top{display:flex;align-items:center;gap:12px;padding:16px 20px}
.logo{width:44px;height:44px;border-radius:10px;object-fit:contain;background:#fff}
.top h1{margin:0;font-size:.95rem;font-weight:700;line-height:1.3}
.top p{margin:0;font-size:.75rem;color:var(--mute)}

/* ส่วนวันที่ */
.hero{background:linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);color:#fff;padding:24px 20px 28px;border-radius:0 0 32px 32px;position:relative;overflow:hidden;box-shadow:0 8px 20px rgba(90,66,160,.15)}
.hero-badge{display:inline-flex;align-items:center;gap:6px;font-size:.75rem;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.2);margin-bottom:8px;backdrop-filter:blur(4px)}
.hero small{display:block;font-size:.8rem;opacity:.9;text-transform:uppercase;letter-spacing:.5px}
.hero strong{display:block;font-size:1.45rem;font-weight:700;line-height:1.3;margin:4px 0 6px}
.hero-sub{font-size:.78rem;opacity:.85;margin-top:2px}

/* แบบฟอร์ม */
.card{background:#fff;border:1px solid var(--line);border-radius:18px;margin:20px 20px 0;padding:20px}
.field{margin:0 0 20px;padding:0;border:0;min-width:0}
.field>label,.field>legend{display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:600;margin:0 0 8px;padding:0}
.field>label .ic,.field>legend .ic{color:var(--primary)}
.req{color:#ef4444}
.input{width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:10px;background:#f8fafc;font:inherit;font-size:1rem;color:var(--ink);outline:none;transition:border-color .15s,background .15s}
.input:focus{border-color:var(--primary);background:#fff}
.input[readonly]{background:#f1f5f9;color:var(--primary);font-weight:600;cursor:not-allowed}
.hint{display:flex;gap:6px;margin:8px 0 0;font-size:.75rem;color:var(--mute);line-height:1.5}
.hint .ic{color:var(--primary);margin-top:2px}

.lookup{display:flex;align-items:center;gap:8px;margin-top:8px;padding:9px 12px;border-radius:10px;font-size:.85rem;font-weight:500}
.lookup[hidden]{display:none}
.lk-ok{background:var(--ok-bg);color:var(--ok)}
.lk-bad{background:var(--bad-bg);color:var(--bad)}
.lk-loading{background:#f1f5f9;color:var(--mute)}
.lookup b{font-weight:600}
.lookup span.sec{margin-left:auto;font-size:.78rem;opacity:.85;white-space:nowrap}

.tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.tile{position:relative;display:block}
.tile input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer}
.tile-body{display:flex;flex-direction:column;gap:6px;height:100%;padding:12px;border:1.5px solid var(--line);border-radius:14px;background:#fff;transition:border-color .15s,background .15s}
.tile-top{display:flex;align-items:center;justify-content:space-between}
.tile-ic{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:var(--bg);color:var(--c);font-size:1.05rem}
.tile-num{font-size:.8rem;font-weight:600;color:var(--mute)}
.tile-text{font-size:.85rem;font-weight:600;line-height:1.35}
.t-home{--c:#2563eb;--bg:#dbeafe}.t-stay{--c:#6d28d9;--bg:#ede9fe}.t-duty{--c:#b45309;--bg:#fef3c7}.t-restrict{--c:#b91c1c;--bg:#fee2e2}
.tile input:checked + .tile-body{border-color:var(--c);background:var(--bg)}
.tile input:focus-visible + .tile-body{outline:3px solid #c4b5fd;outline-offset:2px}
.tile input:disabled{cursor:not-allowed}
.tile input:disabled + .tile-body{opacity:.4}
.tile input:disabled:checked + .tile-body{opacity:1}

.notice{display:flex;gap:10px;margin:0 0 20px;padding:12px;border-radius:12px;background:var(--warn-bg);color:var(--warn);font-size:.8rem;line-height:1.5;border:1px solid #fde68a}
.notice[hidden]{display:none}

.help{display:flex;gap:12px;margin:0 0 20px;padding:12px;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:.8rem;line-height:1.5}
.help .ic{font-size:1.2rem;margin-top:2px}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:12px;padding:13px 18px;font:inherit;font-size:1rem;font-weight:600;cursor:pointer;transition:transform .1s,background .15s}
.btn:active{transform:scale(.98)}
.btn:focus-visible,.input:focus-visible,.nav a:focus-visible{outline:3px solid #c4b5fd;outline-offset:2px}
.btn-primary{background:var(--primary);color:#fff;width:100%}
.btn-primary:hover{background:var(--primary-dark)}
.btn-primary:disabled{opacity:.55;cursor:not-allowed}
.btn-ghost{background:#f1f5f9;color:var(--mute)}
.spinner{width:1.1em;height:1.1em;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}
.lk-loading .spinner{border-color:#cbd5e1;border-top-color:var(--primary)}
@keyframes spin{to{transform:rotate(360deg)}}

.closed{text-align:center}
.closed .big{display:grid;place-items:center;width:64px;height:64px;margin:0 auto 14px;border-radius:50%;background:#fee2e2;color:#ef4444;font-size:1.8rem}
.closed h2{margin:0 0 6px;font-size:1.15rem;color:#991b1b}
.closed p{margin:0 0 14px;font-size:.85rem;color:var(--mute)}
.closed .when{display:inline-block;padding:8px 14px;border-radius:10px;background:#f8fafc;border:1px solid var(--line);font-size:.85rem;font-weight:600;color:#b91c1c}
.closed .btn{margin-top:16px;background:none;color:var(--primary);padding:8px}

/* เมนูล่าง */
.nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:430px;height:68px;background:#fff;border-radius:22px 22px 0 0;box-shadow:0 -4px 18px rgba(15,23,42,.06);display:flex;justify-content:space-around;align-items:center;z-index:50}
.nav a{display:flex;flex-direction:column;align-items:center;gap:3px;width:30%;text-decoration:none;color:#94a3b8;font-size:.7rem;font-weight:600}
.nav a .ic{font-size:1.35rem}
.nav a.on{color:var(--primary)}

/* กล่องข้อความ */
dialog{border:0;border-radius:20px;padding:24px 20px 18px;width:calc(100% - 40px);max-width:340px;text-align:center;color:var(--ink);font-family:inherit;box-shadow:0 20px 50px rgba(15,23,42,.3)}
dialog::backdrop{background:rgba(15,23,42,.55)}
.dlg-icon{display:grid;place-items:center;width:56px;height:56px;margin:0 auto 12px;border-radius:50%;font-size:1.7rem}
.dlg-icon.success{background:#dcfce7;color:#16a34a}.dlg-icon.error{background:#fee2e2;color:#dc2626}.dlg-icon.warning{background:#fef3c7;color:#d97706}
dialog h3{margin:0 0 8px;font-size:1.1rem}
dialog p{margin:0 0 10px;font-size:.88rem;color:var(--mute);line-height:1.5}
.sum{margin:0 0 12px;padding:10px 12px;border-radius:12px;background:#f8fafc;text-align:left;font-size:.85rem}
.sum div{display:flex;justify-content:space-between;gap:12px;padding:3px 0}
.sum dt{color:var(--mute)}.sum dd{margin:0;font-weight:600;text-align:right}
.warnbox{margin:0 0 12px;padding:10px 12px;border-radius:12px;background:var(--bad-bg);border:1px solid #fecaca;color:var(--bad);font-size:.8rem;font-weight:600;text-align:left;line-height:1.5}
.dlg-actions{display:flex;gap:10px;margin-top:6px}
.dlg-actions .btn{flex:1;padding:11px}
.dlg-actions .btn-primary{width:auto}

/* หน้า loading ครั้งแรก */
#page-loader{position:fixed;inset:0;z-index:9999;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;transition:opacity .4s,visibility .4s}
#page-loader.hidden{opacity:0;visibility:hidden}
#page-loader img{width:76px;height:76px;border-radius:14px}
#page-loader .ring{width:36px;height:36px;border:4px solid rgba(90,66,160,.2);border-top-color:var(--primary);border-radius:50%;animation:spin 1s linear infinite}

.hero strong{animation:rise .5s cubic-bezier(.16,1,.3,1) both}
@keyframes rise{from{opacity:0;transform:translateY(10px)}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>

<?php if ($show_loading): ?>
<div id="page-loader">
  <img src="mtts_logo.jpg" onerror="this.onerror=null;this.src='https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg'" alt="">
  <div class="ring"></div>
</div>
<?php endif; ?>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="i-card" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
<symbol id="i-layers" viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></symbol>
<symbol id="i-clip" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></symbol>
<symbol id="i-lock" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
<symbol id="i-home" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></symbol>
<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>
<symbol id="i-send" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></symbol>
<symbol id="i-headset" viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></symbol>
<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></symbol>
<symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></symbol>
<symbol id="i-refresh" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></symbol>
<symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></symbol>
</defs></svg>

<div class="app">

  <header class="top">
    <img class="logo" src="mtts_logo.jpg" onerror="this.onerror=null;this.src='https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg'" alt="" width="44" height="44">
    <div>
      <h1>กองพันนักเรียนที่ 1</h1>
      <p>โรงเรียนช่างฝีมือทหาร</p>
    </div>
  </header>

  <section class="hero" aria-label="วันที่ที่แจ้งความประสงค์">
    <div class="hero-badge">
      <svg class="ic"><use href="#i-clip"/></svg> แจ้งความประสงค์ประจำสัปดาห์
    </div>
    <small>กำหนดวันศุกร์ที่</small>
    <strong><?php echo htmlspecialchars($date_th_full); ?></strong>
    <div class="hero-sub">กรุณาตรวจสอบข้อมูลและเลือกสถานะให้ถูกต้อง</div>
  </section>

<?php if ($is_voting_open): ?>
  <form id="voteForm" class="card" method="post" action="">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

    <div class="field">
      <label for="student_code"><svg class="ic"><use href="#i-card"/></svg>รหัสประจำตัว (นชท.) <span class="req">*</span></label>
      <input class="input" type="text" id="student_code" name="student_code" required maxlength="10" inputmode="numeric" autocomplete="off" placeholder="เช่น 151xx">
      <div id="lookup" class="lookup" aria-live="polite" hidden></div>
    </div>

    <div class="field">
      <label for="section"><svg class="ic"><use href="#i-layers"/></svg>ตอนเรียน (ดึงอัตโนมัติจากระบบ)</label>
      <input class="input" type="text" id="section" name="section" readonly placeholder="กรอกรหัส นชท. เพื่อดึงข้อมูลอัตโนมัติ">
    </div>

    <fieldset class="field">
      <legend><svg class="ic"><use href="#i-clip"/></svg>เลือกสถานะ <span class="req">*</span></legend>
      <div class="tiles">
<?php $first = true; foreach ($tiles as $val => [$num, $label, $icon]): ?>
        <label class="tile t-<?php echo $val; ?>">
          <input type="radio" name="status" value="<?php echo $val; ?>"<?php echo $first ? ' required' : ''; ?>>
          <span class="tile-body">
            <span class="tile-top"><span class="tile-ic"><svg class="ic"><use href="#i-<?php echo $icon; ?>"/></svg></span><span class="tile-num"><?php echo $num; ?></span></span>
            <span class="tile-text"><?php echo htmlspecialchars($label); ?></span>
          </span>
        </label>
<?php $first = false; endforeach; ?>
      </div>
      <p class="hint"><svg class="ic"><use href="#i-info"/></svg>กรณีใครไม่รู้ว่าโดนกักบริเวณ ธุรการจะเป็นผู้ประกาศอีกที</p>
    </fieldset>

    <div id="forcedNote" class="notice" role="status" hidden><svg class="ic"><use href="#i-alert"/></svg><span></span></div>

    <div class="help">
      <svg class="ic"><use href="#i-headset"/></svg>
      <div><strong>มีปัญหาการใช้งาน?</strong><br>หากพบปัญหาในการกรอกข้อมูล หรือข้อมูลไม่ถูกต้อง กรุณาติดต่อ Line ID: <strong>ntw25020</strong></div>
    </div>

    <button type="submit" id="submitBtn" class="btn btn-primary"><span class="lbl">ส่งข้อมูล</span><svg class="ic"><use href="#i-send"/></svg></button>
  </form>

<?php else: ?>
  <div class="card closed">
    <div class="big"><svg class="ic"><use href="#i-lock"/></svg></div>
    <h2>ระบบปิดรับข้อมูล</h2>
    <p>ขณะนี้ระบบยังไม่เปิดให้ทำรายการ หรือปิดรับลงโหวตแล้ว</p>
    <button type="button" class="btn" onclick="location.reload()"><svg class="ic"><use href="#i-refresh"/></svg>รีเฟรชหน้าเว็บ</button>
  </div>
<?php endif; ?>

  <nav class="nav" aria-label="เมนูหลัก">
    <a href="main.php"><svg class="ic"><use href="#i-home"/></svg><span>หน้าหลัก</span></a>
    <a href="logout.php"><svg class="ic"><use href="#i-logout"/></svg><span>ออกจากระบบ</span></a>
  </nav>
</div>

<dialog id="dlg" aria-labelledby="dlgTitle">
  <div id="dlgIcon" class="dlg-icon"></div>
  <h3 id="dlgTitle"></h3>
  <div id="dlgBody"></div>
  <div class="dlg-actions">
    <button type="button" id="dlgCancel" class="btn btn-ghost" hidden></button>
    <button type="button" id="dlgOk" class="btn btn-primary"></button>
  </div>
</dialog>

<script>
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

  /* ---- หน้า loading ครั้งแรก ---- */
  var loader = $('#page-loader');
  if (loader) {
    var t0 = Date.now();
    window.addEventListener('load', function () {
      setTimeout(function () { loader.classList.add('hidden'); setTimeout(function () { loader.remove(); }, 450); },
                 Math.max(0, 600 - (Date.now() - t0)));
    });
  }

  /* ---- ตัวช่วยสร้าง DOM ---- */
  var NS = 'http://www.w3.org/2000/svg';
  function icon(id) {
    var s = document.createElementNS(NS, 'svg'), u = document.createElementNS(NS, 'use');
    s.setAttribute('class', 'ic'); u.setAttribute('href', '#' + id); s.appendChild(u); return s;
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n;
  }

  /* ---- กล่องข้อความ ---- */
  var dlg = $('#dlg');
  function showDialog(o) {
    return new Promise(function (resolve) {
      var ico = { success: 'i-check', error: 'i-x', warning: 'i-alert' }[o.type || 'warning'];
      var di = $('#dlgIcon'); di.className = 'dlg-icon ' + (o.type || 'warning'); di.replaceChildren(icon(ico));
      $('#dlgTitle').textContent = o.title;
      var body = $('#dlgBody'); body.replaceChildren();
      (o.lines || []).forEach(function (t) { body.appendChild(el('p', null, t)); });
      if (o.summary) {
        var dl = el('dl', 'sum');
        o.summary.forEach(function (kv) { var d = el('div'); d.appendChild(el('dt', null, kv[0])); d.appendChild(el('dd', null, kv[1])); dl.appendChild(d); });
        body.appendChild(dl);
      }
      if (o.warn) body.appendChild(el('div', 'warnbox', o.warn));

      var ok = $('#dlgOk'), cancel = $('#dlgCancel'), left = o.countdown || 0, timer = null;
      var label = function () { ok.textContent = left > 0 ? (o.confirm + ' (' + left + ')') : o.confirm; };
      cancel.hidden = !o.cancel; if (o.cancel) cancel.textContent = o.cancel;
      ok.disabled = left > 0; label();

      function finish(v) {
        clearInterval(timer); dlg.removeEventListener('cancel', onEsc);
        ok.onclick = cancel.onclick = null; dlg.close(); resolve(v);
      }
      function onEsc(e) { e.preventDefault(); finish(!o.cancel); }
      dlg.addEventListener('cancel', onEsc);
      ok.onclick = function () { finish(true); };
      cancel.onclick = function () { finish(false); };
      if (left > 0) {
        timer = setInterval(function () { left--; label(); if (left <= 0) { clearInterval(timer); ok.disabled = false; } }, 1000);
      }
      dlg.showModal();
    });
  }

  function showResult(d) {
    if (d.ok) {
      showDialog({ type: 'success', title: 'บันทึกสำเร็จ', lines: [d.message], confirm: 'ตกลง' })
        .then(function () { location.href = location.pathname; });
    } else {
      showDialog({ type: 'error', title: 'ไม่สามารถบันทึกได้', lines: [d.message], confirm: 'ตกลง' });
    }
  }

  var FLASH = <?php echo json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  if (FLASH) showResult(FLASH);

  var form = $('#voteForm');
  if (!form) return;

  /* ---- ค้นหานักเรียนและดึงตอนเรียนอัตโนมัติ ---- */
  var codeIn = $('#student_code'), secIn = $('#section'), lookupBox = $('#lookup'), note = $('#forcedNote');
  var tiles = Array.prototype.slice.call(form.querySelectorAll('input[name=status]'));
  var cache = {}, ctl = null, timer = null, state = 'idle', auto = false, current = Promise.resolve();

  function setLookup(kind, name, sec) {
    lookupBox.hidden = !kind; if (!kind) return;
    lookupBox.className = 'lookup lk-' + kind; lookupBox.replaceChildren();
    if (kind === 'loading') { lookupBox.appendChild(el('i', 'spinner')); lookupBox.appendChild(el('span', null, 'กำลังค้นหาข้อมูล...')); return; }
    lookupBox.appendChild(icon(kind === 'ok' ? 'i-check' : 'i-x'));
    lookupBox.appendChild(el('b', null, name));
    if (sec) lookupBox.appendChild(el('span', 'sec', 'ตอน ' + sec));
  }

  function applyForced(forced, unknown) {
    tiles.forEach(function (t) {
      t.disabled = forced ? t.value !== forced : (!unknown && t.value === 'duty');
    });
    if (forced) {
      tiles.forEach(function (t) { t.checked = t.value === forced; });
      auto = true; note.hidden = false;
      note.lastElementChild.textContent = forced === 'restrict'
        ? 'รหัสนี้มีรายชื่อถูกกักบริเวณในสัปดาห์นี้ ระบบเลือกสถานะ "กักบริเวณ" ให้อัตโนมัติ'
        : 'รหัสนี้มีรายชื่อเวรรักษาการณ์ในสัปดาห์นี้ ระบบเลือกสถานะ "เวรรักษาการณ์" ให้อัตโนมัติ';
    } else {
      note.hidden = true;
      if (auto) { tiles.forEach(function (t) { t.checked = false; }); auto = false; }
    }
  }

  function reset() { state = 'idle'; setLookup(null); applyForced(null, true); secIn.value = ''; }

  function apply(d) {
    if (!d.found) { state = 'notfound'; setLookup('bad', 'ไม่พบรหัสนี้ในระบบ'); applyForced(null, true); secIn.value = ''; return; }
    state = 'found'; setLookup('ok', d.name, d.section);
    if (d.section) {
      secIn.value = 'ตอนที่ ' + d.section;
    } else {
      secIn.value = 'ไม่พบข้อมูลตอนเรียน';
    }
    applyForced(d.forced, false);
  }

  async function run(code) {
    if (cache[code]) { apply(cache[code]); return; }
    if (ctl) ctl.abort();
    ctl = new AbortController(); state = 'loading'; setLookup('loading');
    try {
      var r = await fetch('?action=get_student_name&code=' + encodeURIComponent(code), { signal: ctl.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!r.ok) throw new Error('http ' + r.status);
      var d = await r.json(); cache[code] = d;
      if (codeIn.value.trim() === code) apply(d);
    } catch (e) {
      if (e.name === 'AbortError') return;
      state = 'error'; setLookup('bad', 'เชื่อมต่อไม่ได้ ลองใหม่อีกครั้ง');
    }
  }
  function lookup(code) { current = run(code); return current; }

  codeIn.addEventListener('input', function () {
    clearTimeout(timer); reset();
    var c = codeIn.value.trim();
    if (c.length >= 5) timer = setTimeout(function () { lookup(c); }, 350);
  });
  codeIn.addEventListener('change', function () {
    var c = codeIn.value.trim(); if (c && state === 'idle') { clearTimeout(timer); lookup(c); }
  });

  /* ---- ส่งข้อมูล ---- */
  var btn = $('#submitBtn');
  function busy(on, text) { btn.disabled = on; $('.lbl', btn).textContent = on ? text : 'ส่งข้อมูล'; }

  async function send() {
    var fd = new FormData(form); fd.set('ajax', '1');
    for (var i = 0; i < 4; i++) {
      try {
        var r = await fetch(location.pathname, { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (r.status >= 500 || r.status === 429) throw new Error('busy ' + r.status);
        return await r.json();
      } catch (e) {
        if (i === 3) break;
        busy(true, 'เซิร์ฟเวอร์หนาแน่น ลองใหม่ครั้งที่ ' + (i + 2) + '...');
        await sleep(700 * (i + 1) + Math.random() * 1000);
      }
    }
    return { ok: false, message: 'ส่งข้อมูลไม่สำเร็จ เซิร์ฟเวอร์หรืออินเทอร์เน็ตมีปัญหา กรุณากดส่งอีกครั้ง' };
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!form.checkValidity()) { form.reportValidity(); return; }
    var c = codeIn.value.trim();
    if (state === 'idle') lookup(c);
    await current;
    if (state !== 'found') {
      showDialog({ type: 'error', title: 'ตรวจสอบรหัสประจำตัว',
        lines: [state === 'error' ? 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง' : 'กรุณากรอกรหัสที่ถูกต้อง และรอให้ระบบแสดงชื่อก่อนกดส่ง'], confirm: 'ตกลง' });
      return;
    }
    var picked = form.querySelector('input[name=status]:checked');
    var ok = await showDialog({
      type: 'warning', title: 'โปรดยืนยันการทำรายการ',
      lines: ['กรุณาตรวจสอบข้อมูลให้ถูกต้องก่อนกดยืนยัน'],
      summary: [['รหัส', c], ['ชื่อ', ($('b', lookupBox) || {}).textContent || '-'], ['ตอน', secIn.value], ['สถานะ', picked ? $('.tile-text', picked.closest('.tile')).textContent : '-']],
      warn: 'ข้อควรระวัง : ไม่สามารถแก้ไขข้อมูลภายหลังได้ คิดดีๆ ถ้าไม่อยากใส่ชุดจับหมู',
      confirm: 'ยืนยันการส่งข้อมูล', cancel: 'ยกเลิก', countdown: 10
    });
    if (!ok) return;

    busy(true, 'กำลังบันทึก...');
    var d = await send();
    busy(false);
    showResult(d);
  });
})();
</script>
</body>
</html>
