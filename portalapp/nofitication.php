<?php

//nofification.php
ob_start();                       // กันช่องว่างจากไฟล์อื่นหลุดไปรบกวน JSON/Header
ini_set('display_errors', 0);
session_start();

require_once 'db.php';            // ต้องมี $conn (mysqli)
require_once 'notification_lib.php';
require_once 'rich_content.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

/* ============ 1) ต้องเข้าสู่ระบบ ============ */
if (empty($_SESSION['student_code'])) {
    if ($isPost) {
        json_response(['success' => false, 'error' => 'Unauthorized: กรุณาเข้าสู่ระบบ']);
    }
    header('Location: login.php');
    exit();
}
$studentCode = (string)$_SESSION['student_code'];

/* ============ 2) API: บันทึก/อัปเดต Push Subscription ============ */
if ($isPost) {
    session_write_close();
    if (!same_origin_request()) {
        json_response(['success' => false, 'error' => 'Forbidden'], 403);
    }
    json_response(save_push_subscription($conn, $studentCode, $_POST));
}

/* ============ 3) หน้าเว็บ: รวบรวมข้อมูลเป็นรายการเดียว ============ */
$items = [];
$error_message = '';

try {
    session_write_close();        // ปล่อย lock ของ session ก่อนดึงข้อมูลส่วนที่เหลือ

    $items = build_notification_items([
        'restriction' => safe_fetch(fn() => get_restriction($conn, $studentCode)),
        'discipline'  => safe_fetch(fn() => get_discipline_today($conn, $studentCode)),
        'voted'       => safe_fetch(fn() => has_voted_this_week($conn, $studentCode), true),
        'system'      => safe_fetch(fn() => get_system_notifications($conn, $studentCode), []),
        'duties'      => safe_fetch(fn() => get_duties($conn, $studentCode), []),
        'news'        => safe_fetch(fn() => get_announcements($conn), []),
    ]);
} catch (Throwable $e) {
    error_log('notification page: ' . $e->getMessage());
    $error_message = 'ไม่สามารถโหลดการแจ้งเตือนได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง';
}

$counts = ['all' => count($items), 'important' => 0, 'duty' => 0, 'news' => 0];
foreach ($items as $it) {
    if ($it['rank'] <= 2)       { $counts['important']++; }
    if ($it['cat'] === 'duty')  { $counts['duty']++; }
    if ($it['cat'] === 'news')  { $counts['news']++; }
}
$filters = ['all' => 'ทั้งหมด', 'important' => 'สำคัญ', 'duty' => 'เวร', 'news' => 'ประกาศ'];

if (ob_get_level()) { ob_end_flush(); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#5a42a0">
<title>การแจ้งเตือน - MTTS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#5a42a0;--ink:#1e293b;--mute:#64748b;--paper:#f6f8fc;--line:#dbe1ea}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
[hidden]{display:none!important}
body{margin:0;font-family:'Prompt',system-ui,sans-serif;background:#e2e8f0;color:var(--ink);display:flex;justify-content:center}
.app{width:100%;max-width:430px;min-height:100vh;background:var(--paper);padding-bottom:96px;box-shadow:0 0 24px rgba(15,23,42,.08)}
.ic{width:1.15em;height:1.15em;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;vertical-align:-.2em}

.top{display:flex;align-items:center;justify-content:space-between;padding:16px 20px}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:44px;height:44px;border-radius:10px;object-fit:contain;background:#fff}
.brand h1{margin:0;font-size:1.05rem;font-weight:700}
.brand p{margin:0;font-size:.75rem;color:var(--mute)}
.back{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:#e2e8f0;color:var(--ink);text-decoration:none;font-size:1.1rem;transition:background .2s,transform .15s}
.back:hover{background:#d5dce6}
.back:active{transform:scale(.9)}

.head{display:flex;align-items:center;justify-content:space-between;margin:8px 20px 12px}
.head h2{margin:0;font-size:1.05rem;font-weight:700;border-left:5px solid var(--primary);padding-left:10px;line-height:1.3}
.count{font-size:.75rem;font-weight:600;padding:3px 10px;border-radius:12px;background:#e2e8f0}

.chips{display:flex;gap:8px;margin:0 20px 14px;overflow-x:auto;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex-shrink:0;display:inline-flex;align-items:center;gap:6px;border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:6px 14px;font:inherit;font-size:.82rem;font-weight:500;color:var(--mute);cursor:pointer;transition:background .2s,color .2s,border-color .2s,transform .15s}
.chip:active{transform:scale(.94)}
.chip b{font-weight:600}
.chip[aria-pressed=true]{background:var(--primary);border-color:var(--primary);color:#fff}
.chip:focus-visible,.noti:focus-visible,.back:focus-visible,.nav a:focus-visible,.x:focus-visible{outline:3px solid #c4b5fd;outline-offset:2px}

.list{display:flex;flex-direction:column;gap:12px;margin:0 20px 24px}
.noti{display:flex;gap:14px;align-items:flex-start;padding:15px 16px;background:var(--bg,#fff);border:1px solid var(--line);border-left:4px solid var(--lv);border-radius:14px;cursor:pointer;transition:transform .2s cubic-bezier(.16,1,.3,1),box-shadow .2s}
@media (hover:hover){.noti:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(15,23,42,.08)}}
.noti:active{transform:scale(.985)}
.lv-danger{--lv:#991b1b;--bg:#fef2f2;--ic:#fecaca;--glow:rgba(153,27,27,.45)}
.lv-urgent{--lv:#dc2626;--bg:#fef2f2;--ic:#fee2e2;--glow:rgba(220,38,38,.4)}
.lv-warning{--lv:#ea580c;--bg:#fff7ed;--ic:#ffedd5}
.lv-info{--lv:#2563eb;--bg:#fff;--ic:#dbeafe}
.noti-ic{display:grid;place-items:center;width:40px;height:40px;border-radius:12px;background:var(--ic);color:var(--lv);font-size:1.2rem;flex-shrink:0}
.body{flex:1;min-width:0}
.body h4{margin:0 0 4px;font-size:.95rem;font-weight:700;line-height:1.35;word-break:break-word}
.lv-danger h4{color:#991b1b}
.summary{margin:0 0 8px;font-size:.82rem;color:var(--mute);line-height:1.5;white-space:pre-line;word-break:break-word;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.attach{display:flex;align-items:center;gap:5px;margin-bottom:6px;font-size:.75rem;color:var(--primary)}
.act{display:inline-block;margin:0 0 8px;padding:6px 14px;border-radius:8px;background:var(--primary);color:#fff;font-size:.78rem;font-weight:600;text-decoration:none;transition:transform .15s,filter .2s}
.act:hover{filter:brightness(1.1)}
.act:active{transform:scale(.95)}
.time{display:flex;align-items:center;gap:5px;font-size:.72rem;color:#94a3b8}

.empty{margin:0 20px;padding:40px 20px;text-align:center;background:#fff;border:1px solid var(--line);border-radius:16px;color:#94a3b8}
.empty .ic{width:3rem;height:3rem;color:#cbd5e1;margin-bottom:10px}
.empty p{margin:0;font-size:.9rem}
.alert{margin:0 20px 12px;padding:12px 14px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:.85rem}

.nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:430px;height:68px;background:#fff;border-radius:22px 22px 0 0;box-shadow:0 -4px 18px rgba(15,23,42,.06);display:flex;justify-content:space-around;align-items:center;z-index:50}
.nav a{display:flex;flex-direction:column;align-items:center;gap:3px;width:30%;text-decoration:none;color:#94a3b8;font-size:.7rem;font-weight:600}
.nav a .ic{font-size:1.35rem}
.nav a[aria-current=page]{color:var(--primary)}

dialog{width:calc(100% - 40px);max-width:380px;max-height:85vh;padding:0;border:0;border-radius:16px;color:var(--ink);font-family:inherit;box-shadow:0 20px 50px rgba(15,23,42,.3)}
dialog[open]{display:flex}
dialog::backdrop{background:rgba(15,23,42,.55)}
.box{display:flex;flex-direction:column;width:100%;max-height:85vh;padding:18px 20px}
.box header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding-bottom:10px;margin-bottom:12px;border-bottom:1px solid var(--line)}
.box h3{margin:0;font-size:1.05rem;line-height:1.4;word-break:break-word}
.x{flex-shrink:0;display:grid;place-items:center;width:32px;height:32px;border:0;border-radius:50%;background:#f1f5f9;color:var(--mute);font-size:1rem;cursor:pointer}
.scroll{overflow-y:auto;flex:1;font-size:.92rem;color:var(--mute);line-height:1.6;white-space:pre-wrap;word-break:break-word}
.scroll>img{display:block;max-width:100%;height:auto;margin-bottom:12px;border-radius:8px;opacity:0;transition:opacity .35s}
.scroll>img.on{opacity:1}
.rich{white-space:normal}
.rich img{max-width:100%;height:auto;border-radius:8px;vertical-align:middle}
.rich a{color:var(--primary);text-decoration:underline;word-break:break-word}
.rich p{margin:0 0 8px}
.rich ul,.rich ol{padding-left:20px;margin:0 0 8px}
.x .ic{transition:transform .25s}
.x:hover .ic{transform:rotate(90deg)}
.box footer{margin-top:12px;font-size:.78rem;color:#94a3b8;text-align:right}

/* ===== แอนิเมชัน ===== */
/* เข้าฉาก: ใช้ fill-mode backwards เท่านั้น เพื่อให้ :hover/:active ทำงานต่อหลังจบแอนิเมชัน */
.rise{animation:rise .55s cubic-bezier(.16,1,.3,1) backwards;animation-delay:calc(var(--i,0)*60ms)}
@keyframes rise{from{opacity:0;transform:translateY(18px)}}

/* ไอคอนการ์ดสำคัญ: เด้งเรียกความสนใจ 3 รอบแล้วหยุด (ไม่วนไม่รู้จบ) */
.lv-danger .noti-ic,.lv-urgent .noti-ic{animation:glow 1.8s ease-out 1.1s 3}
@keyframes glow{0%{box-shadow:0 0 0 0 var(--glow)}100%{box-shadow:0 0 0 13px transparent}}
.lv-danger .noti-ic .ic{animation:wiggle .7s ease-in-out 1.1s 2}
@keyframes wiggle{0%,100%{transform:rotate(0)}20%{transform:rotate(-12deg)}40%{transform:rotate(10deg)}60%{transform:rotate(-8deg)}80%{transform:rotate(5deg)}}

/* กระดิ่งในเมนูล่างสั่นครั้งเดียว เมื่อมีรายการสำคัญ */
.ring{transform-origin:50% 10%;animation:ringBell 1.1s ease-in-out 1s 1}
@keyframes ringBell{0%,100%{transform:rotate(0)}15%{transform:rotate(16deg)}30%{transform:rotate(-14deg)}45%{transform:rotate(10deg)}60%{transform:rotate(-8deg)}75%{transform:rotate(4deg)}}

/* หน้าไม่มีรายการ: ไอคอนแกว่งเบา ๆ */
.empty .ic{transform-origin:50% 5%;animation:sway 2.6s ease-in-out .5s 3}
@keyframes sway{0%,100%{transform:rotate(0)}30%{transform:rotate(8deg)}70%{transform:rotate(-8deg)}}

/* หน้าต่างรายละเอียด: เปิด/ปิดแบบนุ่ม (JS เติมคลาส .closing ก่อนปิดจริง) */
dialog[open]{animation:pop .3s cubic-bezier(.16,1,.3,1)}
dialog[open]::backdrop{animation:fade .3s ease}
dialog.closing{animation:popOut .18s ease forwards}
dialog.closing::backdrop{animation:fadeOut .18s ease forwards}
@keyframes pop{from{opacity:0;transform:translateY(24px) scale(.96)}}
@keyframes popOut{to{opacity:0;transform:translateY(16px) scale(.97)}}
@keyframes fade{from{opacity:0}}
@keyframes fadeOut{to{opacity:0}}

/* ผู้ใช้ที่ตั้งค่าลดการเคลื่อนไหว: ปิดทุกอย่าง (เนื้อหายังแสดงครบ) */
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="i-slash" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></symbol>
<symbol id="i-card" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></symbol>
<symbol id="i-vote" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></symbol>
<symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></symbol>
<symbol id="i-alert-tri" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>
<symbol id="i-cal" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></symbol>
<symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></symbol>
<symbol id="i-bell-off" viewBox="0 0 24 24"><path d="M13.73 21a2 2 0 0 1-3.46 0"/><path d="M18.63 13A17.89 17.89 0 0 1 18 8"/><path d="M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.33-5"/><line x1="1" y1="1" x2="23" y2="23"/></symbol>
<symbol id="i-news" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></symbol>
<symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></symbol>
<symbol id="i-back" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>
<symbol id="i-home" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></symbol>
</defs></svg>

<div class="app">

  <header class="top rise" style="--i:0">
    <div class="brand">
      <img class="logo" src="mtts_logo.jpg" onerror="this.onerror=null;this.src='https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg'" alt="" width="44" height="44">
      <div>
        <h1>การแจ้งเตือน</h1>
        <p>โรงเรียนช่างฝีมือทหาร</p>
      </div>
    </div>
    <a class="back" href="main.php" aria-label="ย้อนกลับ"><svg class="ic"><use href="#i-back"/></svg></a>
  </header>

  <div class="head rise" style="--i:1">
    <h2>รายการแจ้งเตือนทั้งหมด</h2>
    <span class="count"><span data-count="<?= (int)$counts['all'] ?>"><?= (int)$counts['all'] ?></span> รายการ</span>
  </div>

<?php if ($error_message !== ''): ?>
  <div class="alert" role="alert"><?= e($error_message) ?></div>
<?php endif; ?>

<?php if ($counts['all'] > 0): ?>
  <div class="chips rise" style="--i:2" role="group" aria-label="กรองการแจ้งเตือน">
<?php foreach ($filters as $key => $label): ?>
    <button type="button" class="chip" data-filter="<?= e($key) ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"><?= e($label) ?> <b data-count="<?= (int)$counts[$key] ?>"><?= (int)$counts[$key] ?></b></button>
<?php endforeach; ?>
  </div>

  <div class="list" id="list">
<?php foreach ($items as $n => $it):
    $rich    = rich_html((string)$it['summary']);
    $hasRich = (stripos($rich, '<img') !== false || stripos($rich, '<a ') !== false);
?>
    <article class="noti rise lv-<?= e($it['level']) ?>" style="--i:<?= min($n, 9) + 3 ?>" role="button" tabindex="0"
             data-cat="<?= e($it['cat']) ?>" data-important="<?= $it['rank'] <= 2 ? '1' : '0' ?>"<?= $it['image'] !== '' ? ' data-image="' . e($it['image']) . '"' : '' ?>>
      <div class="noti-ic"><svg class="ic"><use href="#i-<?= e($it['icon']) ?>"/></svg></div>
      <div class="body">
        <h4><?= e($it['title']) ?></h4>
        <p class="summary"><?= e(rich_plain((string)$it['summary'])) ?></p>
        <template class="full"><?= $rich ?></template>
<?php if ($it['image'] !== ''): ?>
        <div class="attach"><svg class="ic"><use href="#i-image"/></svg>มีรูปภาพแนบ</div>
<?php elseif ($hasRich): ?>
        <div class="attach"><svg class="ic"><use href="#i-news"/></svg>มีลิงก์/รูปแนบ แตะเพื่อเปิดดู</div>
<?php endif; ?>
<?php if ($it['action']): ?>
        <a class="act" href="<?= e($it['action']['href']) ?>"><?= e($it['action']['label']) ?></a>
<?php endif; ?>
        <div class="time"><svg class="ic"><use href="#i-clock"/></svg><span><?= e($it['time']) ?></span></div>
      </div>
    </article>
<?php endforeach; ?>
  </div>

  <div class="empty rise" id="emptyFilter" hidden>
    <svg class="ic"><use href="#i-bell-off"/></svg>
    <p>ไม่มีรายการในหมวดนี้</p>
  </div>

<?php elseif ($error_message === ''): ?>
  <div class="empty rise" style="--i:2">
    <svg class="ic"><use href="#i-bell-off"/></svg>
    <p>ไม่มีรายการแจ้งเตือนใหม่ในขณะนี้</p>
  </div>
<?php endif; ?>


</div>

<dialog id="detail" aria-labelledby="dTitle">
  <div class="box">
    <header>
      <h3 id="dTitle"></h3>
      <button type="button" class="x" id="dClose" aria-label="ปิด"><svg class="ic"><use href="#i-x"/></svg></button>
    </header>
    <div class="scroll">
      <img id="dImg" alt="ภาพประกอบ" hidden>
      <div id="dText" class="rich"></div>
    </div>
    <footer id="dTime"></footer>
  </div>
</dialog>

<script>
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- ตัวเลขนับขึ้นจาก 0 (ไม่ใช้ JS = เห็นตัวเลขจริงทันที) ---- */
  if (!reduce) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-count]'), function (el) {
      var to = parseInt(el.dataset.count, 10);
      if (!to) return;
      el.textContent = '0';
      setTimeout(function () {
        var t0 = performance.now(), dur = 700;
        (function tick(t) {
          var p = Math.min(1, (t - t0) / dur);
          el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3)));
          if (p < 1) requestAnimationFrame(tick);
        })(t0);
      }, 250);
    });
  }

  var cards = Array.prototype.slice.call(document.querySelectorAll('.noti'));
  if (!cards.length) return;

  /* ---- กรองรายการ: การ์ดที่เหลือไหลเข้ามาใหม่ทีละใบ ---- */
  var chips = Array.prototype.slice.call(document.querySelectorAll('.chip'));
  var emptyBox = $('#emptyFilter');
  function replay(el, i) {
    el.style.setProperty('--i', i);
    el.style.animation = 'none';
    void el.offsetWidth;            // บังคับ reflow เพื่อเริ่มแอนิเมชันใหม่
    el.style.animation = '';
  }
  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      var f = chip.dataset.filter, shown = 0;
      chips.forEach(function (c) { c.setAttribute('aria-pressed', String(c === chip)); });
      cards.forEach(function (card) {
        var ok = f === 'all' || (f === 'important' ? card.dataset.important === '1' : card.dataset.cat === f);
        card.hidden = !ok;
        if (ok) replay(card, Math.min(shown++, 8));
      });
      emptyBox.hidden = shown > 0;
    });
  });

  /* ---- หน้าต่างรายละเอียด (<dialog>) พร้อมแอนิเมชันเปิด/ปิด ---- */
  var dlg = $('#detail'), img = $('#dImg');

  /* ภาพย่อ Drive ที่โหลดไม่ได้ → ใช้ไอคอน Drive แทน, รูปอื่นที่เสีย → ซ่อน */
  $('#dText').addEventListener('error', function (e) {
    var im = e.target;
    if (!im || im.tagName !== 'IMG') return;
    if (!im.dataset.fb && im.src.indexOf('drive.google.com') > -1) {
      im.dataset.fb = '1';
      im.src = 'https://ssl.gstatic.com/docs/doclist/images/drive_favicon_2026_32dp.png';
      im.style.objectFit = 'contain'; im.style.padding = '15%';
    } else { im.style.display = 'none'; }
  }, true);

  function openCard(card) {
    $('#dTitle').textContent = $('h4', card).textContent;
    var box = $('#dText'), tpl = $('template.full', card);   // เนื้อหาเต็มที่กรองแล้วจากเซิร์ฟเวอร์ (รูป/ลิงก์)
    box.textContent = '';
    if (tpl && tpl.content.childNodes.length) box.appendChild(tpl.content.cloneNode(true));
    else box.textContent = $('.summary', card).textContent;
    $('#dTime').textContent = $('.time span', card).textContent;
    var src = card.dataset.image;
    img.classList.remove('on');
    if (src) {
      img.hidden = false;
      img.onload = function () { img.classList.add('on'); };
      img.onerror = function () { img.hidden = true; };
      img.src = src;
    } else {
      img.removeAttribute('src'); img.hidden = true;
    }
    dlg.showModal();
  }
  function closeDlg() {
    if (!dlg.open || dlg.classList.contains('closing')) return;
    if (reduce) { dlg.close(); return; }
    var finished = false;
    function done() {
      if (finished) return;
      finished = true;
      dlg.removeEventListener('animationend', onEnd);
      dlg.classList.remove('closing');
      dlg.close();
    }
    function onEnd(e) { if (e.target === dlg && e.animationName === 'popOut') done(); }
    dlg.addEventListener('animationend', onEnd);
    dlg.classList.add('closing');
    setTimeout(done, 320);          // กันพลาดกรณี animationend ไม่ถูกยิง
  }
  cards.forEach(function (card) {
    card.addEventListener('click', function (e) { if (!e.target.closest('a')) openCard(card); });
    card.addEventListener('keydown', function (e) {
      if (e.target === card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openCard(card); }
    });
  });
  $('#dClose').addEventListener('click', closeDlg);
  dlg.addEventListener('click', function (e) { if (e.target === dlg) closeDlg(); });
  dlg.addEventListener('cancel', function (e) { e.preventDefault(); closeDlg(); });   // ปุ่ม Esc
  dlg.addEventListener('close', function () {
    img.onload = img.onerror = null; img.removeAttribute('src'); img.classList.remove('on');
    $('#dText').textContent = '';
  });
})();
</script>
</body>
</html>
