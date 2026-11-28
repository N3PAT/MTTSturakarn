<?php
/**
 * poll.php — โหวตหน่อย (หน้าโหวตโพลสำหรับนักเรียน)
 *   GET  ?action=data  -> JSON รายการโพลล่าสุด (ใช้รีเฟรชกราฟสด)
 *   POST action=vote   -> บันทึกโหวต แล้วคืนโพลที่อัปเดตแล้ว
 */
date_default_timezone_set('Asia/Bangkok');
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db.php';
require_once 'poll_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['student_code'])) {
    header('Location: login.php');
    exit();
}
$student_code = (string)$_SESSION['student_code'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];
$conn->set_charset('utf8mb4');

function json_out(array $data, int $http = 200): void
{
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function vote_fail(string $msg, int $http = 200): void
{
    json_out(['ok' => false, 'message' => $msg], $http);
}

/** บันทึกโหวต (ตรวจทุกอย่างฝั่งเซิร์ฟเวอร์) */
function process_vote(mysqli $conn, string $code, string $csrf, array $in): void
{
    $token = (string)($in['csrf_token'] ?? '');
    if ($token === '' || !hash_equals($csrf, $token)) {
        vote_fail('เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วลองใหม่');
    }

    $poll_id = (int)($in['poll_id'] ?? 0);
    $picked  = [];
    foreach ((array)($in['options'] ?? []) as $v) {
        $n = (int)$v;
        if ($n > 0) $picked[$n] = $n;
    }
    $picked = array_values($picked);

    try {
        $st = $conn->prepare('SELECT id, max_choices, status, closes_at FROM polls WHERE id = ? LIMIT 1');
        $st->bind_param('i', $poll_id);
        $st->execute();
        $poll = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$poll) vote_fail('ไม่พบโพลนี้');
        if (!poll_is_open($poll)) vote_fail('โพลนี้ปิดรับโหวตแล้ว');

        $max = (int)$poll['max_choices'];
        $n   = count($picked);
        if ($n < 1) vote_fail('กรุณาเลือกอย่างน้อย 1 ตัวเลือก');
        if ($max === 1 && $n !== 1) vote_fail('โพลนี้เลือกได้ข้อเดียว');
        if ($max > 1 && $n > $max) vote_fail('โพลนี้เลือกได้สูงสุด ' . $max . ' ข้อ');

        $st = $conn->prepare('SELECT id FROM poll_options WHERE poll_id = ?');
        $st->bind_param('i', $poll_id);
        $st->execute();
        $valid = [];
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) { $valid[] = (int)$r['id']; }
        $st->close();
        if (array_diff($picked, $valid)) vote_fail('ตัวเลือกไม่ถูกต้อง');

        $st = $conn->prepare('SELECT 1 FROM poll_votes WHERE poll_id = ? AND student_code = ? LIMIT 1');
        $st->bind_param('is', $poll_id, $code);
        $st->execute();
        $already = $st->get_result()->num_rows > 0;
        $st->close();
        if ($already) vote_fail('คุณโหวตโพลนี้ไปแล้ว ไม่สามารถโหวตซ้ำได้');

        $conn->begin_transaction();
        try {
            $st = $conn->prepare('INSERT INTO poll_votes (poll_id, option_id, student_code) VALUES (?, ?, ?)');
            foreach ($picked as $oid) {
                $st->bind_param('iis', $poll_id, $oid, $code);
                $st->execute();
            }
            $st->close();
            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ((int)$e->getCode() === 1062) vote_fail('คุณโหวตโพลนี้ไปแล้ว ไม่สามารถโหวตซ้ำได้');
            throw $e;
        }

        $fresh = poll_fetch($conn, $code, $poll_id, 1);
        json_out(['ok' => true, 'message' => 'บันทึกโหวตเรียบร้อยแล้ว', 'poll' => $fresh[0] ?? null]);
    } catch (Throwable $e) {
        error_log('poll vote: ' . $e->getMessage());
        vote_fail('ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง', 503);
    }
}

/* ---------- API ---------- */
if (($_GET['action'] ?? '') === 'data') {
    try {
        json_out(['ok' => true, 'polls' => poll_fetch($conn, $student_code)]);
    } catch (Throwable $e) {
        error_log('poll data: ' . $e->getMessage());
        json_out(['ok' => false, 'polls' => []], 503);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote') {
    process_vote($conn, $student_code, $csrf, $_POST);
}

/* ---------- หน้าเว็บ ---------- */
$polls = [];
$load_error = false;
try {
    $polls = poll_fetch($conn, $student_code);
} catch (Throwable $e) {
    error_log('poll page: ' . $e->getMessage());
    $load_error = true; // เช่น ยังไม่ได้รัน poll_setup.sql
}
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
header('Cache-Control: no-cache, no-store, must-revalidate');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#503896">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>โหวตหน่อย - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    :root{--primary:#503896;--primary-dark:#3d2b78;--bar:#503896;--bg:#f2f3f8;--ink:#1c1b29;--mute:#6b7280;--line:#eef0f5;--ok:#15803d;--bad:#b91c1c;--g:16px;--nav:64px;--st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);--shadow:0 1px 2px rgba(20,20,43,.04),0 2px 8px rgba(20,20,43,.04)}
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;margin:0;padding:0}
    html{-webkit-text-size-adjust:100%}
    body{font-family:'Prompt',sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100vh;min-height:100dvh;-webkit-user-select:none;user-select:none;overscroll-behavior-y:none;touch-action:manipulation;-webkit-font-smoothing:antialiased}
    ::-webkit-scrollbar{display:none}
    .app{width:100%;max-width:430px;min-height:100vh;min-height:100dvh;background:var(--bg);padding-bottom:calc(var(--nav) + var(--sb) + 20px)}
    @media(min-width:431px){.app{box-shadow:0 0 40px rgba(15,23,42,.14)}}

    /* app bar */
    .top{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:12px;padding:calc(10px + var(--st)) var(--g) 10px;background:rgba(80,56,150,.94);-webkit-backdrop-filter:saturate(180%) blur(14px);backdrop-filter:saturate(180%) blur(14px);color:#fff}
    .back{display:flex;align-items:center;justify-content:center;flex:none;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.05rem;text-decoration:none;transition:transform .12s}
    .back:active{transform:scale(.92)}
    .top h1{font-size:1.05rem;font-weight:600;line-height:1.25}
    .top p{font-size:.72rem;opacity:.8}

    .label{margin:18px calc(var(--g) + 4px) 8px;font-size:.8rem;font-weight:600;letter-spacing:.02em;color:var(--mute)}
    .label[hidden]{display:none}

    /* poll card */
    .poll{margin:0 var(--g) 14px;padding:16px;border-radius:20px;background:#fff;box-shadow:var(--shadow)}
    .poll h3{font-size:1.02rem;font-weight:600;line-height:1.35}
    .poll .desc{margin-top:4px;font-size:.82rem;color:var(--mute);line-height:1.5}
    .chips{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 14px}
    .chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;background:#efeaf9;color:var(--primary);font-size:.7rem;font-weight:500}
    .chip.ok{background:#dcfce7;color:#166534}
    .chip.gray{background:#f1f2f8;color:var(--mute)}

    /* ตัวเลือก */
    .opts{display:flex;flex-direction:column;gap:8px}
    .opt{position:relative;display:flex;align-items:center;gap:12px;padding:13px 14px;border:2px solid transparent;border-radius:14px;background:#f4f5fa;cursor:pointer;transition:background .15s,border-color .15s,transform .1s}
    .opt:active{transform:scale(.98)}
    .opt input{position:absolute;opacity:0;pointer-events:none}
    .mark{flex:none;display:grid;place-items:center;width:22px;height:22px;border:2px solid #cfd4e0;background:#fff;color:#fff;font-size:.65rem;transition:.15s}
    .opt input[type=radio]+.mark{border-radius:50%}
    .opt input[type=checkbox]+.mark{border-radius:7px}
    .txt{flex:1;min-width:0;font-size:.92rem;font-weight:500;line-height:1.35;word-break:break-word}
    .opt:has(input:checked){border-color:var(--primary);background:#efeaf9}
    .opt input:checked+.mark{background:var(--primary);border-color:var(--primary)}
    .opt input:checked+.mark::after{content:'\f00c';font-family:'Font Awesome 6 Free';font-weight:900}
    .opt.off{opacity:.45;cursor:not-allowed}
    .hint{margin-top:10px;font-size:.74rem;color:var(--mute)}
    .err{margin-top:10px;padding:10px 12px;border-radius:12px;background:#fef2f2;color:var(--bad);font-size:.8rem;font-weight:500}
    .btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:50px;margin-top:14px;border:0;border-radius:14px;background:var(--primary);color:#fff;font-family:inherit;font-size:.98rem;font-weight:600;cursor:pointer;transition:transform .1s,background .15s}
    .btn:active{transform:scale(.97);background:var(--primary-dark)}
    .btn:disabled{opacity:.5;cursor:not-allowed;transform:none}

    /* ผลโหวต (กราฟแท่ง) */
    .res{display:flex;flex-direction:column;gap:14px}
    .res-top{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:6px}
    .res-name{display:flex;align-items:center;gap:6px;font-size:.9rem;font-weight:500;line-height:1.35;word-break:break-word}
    .res-name i{color:var(--primary);font-size:.8rem}
    .res-num{flex:none;font-size:.78rem;color:var(--mute)}
    .res-num b{color:var(--ink);font-weight:700}
    .track{height:10px;border-radius:6px;background:#eceef6;overflow:hidden}
    .fill{height:100%;width:0;border-radius:6px;background:#b8a9e0;transition:width .8s cubic-bezier(.2,.8,.3,1)}
    .lead .fill{background:var(--primary)}
    .res-foot{margin-top:14px;font-size:.74rem;color:var(--mute);text-align:center}

    .empty{margin:24px var(--g);padding:36px 20px;border-radius:20px;background:#fff;box-shadow:var(--shadow);text-align:center;color:var(--mute)}
    .empty i{margin-bottom:12px;font-size:2.4rem;color:#c3c7d4}
    .empty h3{margin-bottom:4px;font-size:1rem;color:var(--ink)}
    .empty p{font-size:.8rem;line-height:1.5}

    .toast{position:fixed;left:50%;bottom:calc(var(--nav) + var(--sb) + 18px);z-index:2000;max-width:calc(100% - 32px);padding:11px 18px;border-radius:14px;background:#1c1b29;color:#fff;font-size:.85rem;font-weight:500;transform:translate(-50%,20px);opacity:0;pointer-events:none;transition:.3s}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    /* bottom nav สีม่วง */
    .nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:1000;width:100%;max-width:430px;height:calc(var(--nav) + var(--sb));padding-bottom:var(--sb);display:flex;align-items:center;background:var(--bar);box-shadow:0 -4px 18px rgba(20,20,43,.18)}
    .nav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;color:rgba(255,255,255,.6);font-size:.7rem;font-weight:500;text-decoration:none}
    .nav a i{display:grid;place-items:center;width:56px;height:30px;border-radius:15px;font-size:1.2rem;transition:background .2s}
    .nav a.active{color:#fff;font-weight:600}
    .nav a.active i{background:rgba(255,255,255,.2)}
    .nav a:active{color:#fff}

    @media(prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
  </style>
</head>
<body>
<div class="app">

  <header class="top">
    <a href="main.php" class="back" aria-label="กลับหน้าหลัก"><i class="fas fa-chevron-left"></i></a>
    <div>
      <h1>โหวตหน่อย</h1>
      <p>ร่วมแสดงความคิดเห็นกับเพื่อนๆ</p>
    </div>
  </header>

  <main>
    <div class="label" id="labelOpen" hidden>กำลังเปิดโหวต</div>
    <div id="listOpen"></div>
    <div class="label" id="labelClosed" hidden>ปิดโหวตแล้ว</div>
    <div id="listClosed"></div>
    <div id="emptyBox"></div>
  </main>

  <nav class="nav" aria-label="เมนูหลัก">
    <a href="main.php"><i class="fas fa-home"></i><span>หน้าหลัก</span></a>
    <a href="poll.php" class="active"><i class="fas fa-square-poll-vertical"></i><span>โหวตหน่อย</span></a>
  </nav>
</div>

<div class="toast" id="toast" role="status"></div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrf) ?>;
  var LOAD_ERROR = <?= $load_error ? 'true' : 'false' ?>;
  var polls = <?= json_encode($polls, $json_flags) ?>;
  var draft = {};            // ตัวเลือกที่ยังไม่ส่ง (กันหายตอนรีเฟรชกราฟ)
  var animated = {};         // โพลที่เล่นแอนิเมชันกราฟแล้ว
  var busy = false;

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function icon(cls) { var i = el('i', cls); i.setAttribute('aria-hidden', 'true'); return i; }
  function chip(text, cls, ico) {
    var c = el('span', 'chip' + (cls ? ' ' + cls : ''));
    if (ico) c.appendChild(icon(ico));
    c.appendChild(document.createTextNode(text));
    return c;
  }
  function choiceText(max) {
    return max === 1 ? 'เลือกได้ข้อเดียว' : (max === 0 ? 'เลือกได้หลายข้อ' : 'เลือกได้สูงสุด ' + max + ' ข้อ');
  }

  var toastTimer;
  function toast(msg) {
    var t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 2600);
  }

  function collectDraft() {
    document.querySelectorAll('form.opts').forEach(function (f) {
      var vals = [];
      f.querySelectorAll('input:checked').forEach(function (i) { vals.push(i.value); });
      draft[f.dataset.id] = vals;
    });
  }

  /* ---------- ส่วนหัวของการ์ด ---------- */
  function head(p, voted) {
    var box = el('div');
    box.appendChild(el('h3', null, p.title));
    if (p.description) box.appendChild(el('p', 'desc', p.description));
    var chips = el('div', 'chips');
    chips.appendChild(chip(choiceText(p.max_choices), '', 'fas fa-list-check'));
    if (p.is_open && p.closes_label) chips.appendChild(chip('ปิด ' + p.closes_label, 'gray', 'far fa-clock'));
    if (!p.is_open) chips.appendChild(chip('ปิดโหวตแล้ว', 'gray', 'fas fa-lock'));
    if (voted) chips.appendChild(chip('คุณโหวตแล้ว', 'ok', 'fas fa-check'));
    box.appendChild(chips);
    return box;
  }

  /* ---------- ฟอร์มโหวต ---------- */
  function buildForm(p, card) {
    var form = el('form', 'opts');
    form.dataset.id = p.id;
    var multi = p.max_choices !== 1;
    var saved = draft[p.id] || [];
    var err = el('div', 'err');
    err.hidden = true;
    var btn = el('button', 'btn');
    btn.type = 'submit';
    btn.appendChild(el('span', null, 'ส่งโหวต'));
    btn.appendChild(icon('fas fa-paper-plane'));

    var inputs = [];
    p.options.forEach(function (o) {
      var lab = el('label', 'opt');
      var inp = document.createElement('input');
      inp.type = multi ? 'checkbox' : 'radio';
      inp.name = 'o' + p.id;
      inp.value = o.id;
      inp.checked = saved.indexOf(String(o.id)) !== -1;
      lab.appendChild(inp);
      lab.appendChild(el('span', 'mark'));
      lab.appendChild(el('span', 'txt', o.label));
      form.appendChild(lab);
      inputs.push(inp);
    });

    function sync() {
      var n = inputs.filter(function (i) { return i.checked; }).length;
      btn.disabled = busy || n < 1;
      if (multi && p.max_choices > 0) {
        inputs.forEach(function (i) {
          var off = !i.checked && n >= p.max_choices;
          i.disabled = off;
          i.parentNode.classList.toggle('off', off);
        });
      }
    }
    inputs.forEach(function (i) { i.addEventListener('change', function () { err.hidden = true; sync(); }); });
    sync();

    form.appendChild(btn);
    form.appendChild(err);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      var chosen = inputs.filter(function (i) { return i.checked; });
      if (!chosen.length) return;
      busy = true;
      btn.disabled = true;
      btn.firstChild.textContent = 'กำลังส่ง...';
      var fd = new FormData();
      fd.append('action', 'vote');
      fd.append('csrf_token', CSRF);
      fd.append('poll_id', p.id);
      chosen.forEach(function (i) { fd.append('options[]', i.value); });

      fetch('poll.php', { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          busy = false;
          if (d && d.ok && d.poll) {
            delete draft[p.id];
            replacePoll(d.poll);
            render();
            toast('โหวตเรียบร้อยแล้ว');
          } else {
            err.textContent = (d && d.message) || 'ส่งโหวตไม่สำเร็จ';
            err.hidden = false;
            btn.firstChild.textContent = 'ส่งโหวต';
            sync();
          }
        })
        .catch(function () {
          busy = false;
          err.textContent = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่';
          err.hidden = false;
          btn.firstChild.textContent = 'ส่งโหวต';
          sync();
        });
    });

    var hint = multi && p.max_choices > 0 ? 'เลือกได้ไม่เกิน ' + p.max_choices + ' ข้อ · ส่งแล้วแก้ไขไม่ได้' : 'ส่งแล้วแก้ไขไม่ได้';
    card.appendChild(form);
    card.appendChild(el('p', 'hint', hint));
  }

  /* ---------- กราฟผลโหวต ---------- */
  function buildResults(p, card) {
    var wrap = el('div', 'res');
    var max = 0;
    p.options.forEach(function (o) { if (o.count > max) max = o.count; });
    var play = !animated[p.id];
    var bars = [];

    p.options.forEach(function (o) {
      var pct = p.total > 0 ? Math.round(o.count * 100 / p.total) : 0;
      var row = el('div', 'res-row' + (max > 0 && o.count === max ? ' lead' : ''));
      var top = el('div', 'res-top');
      var name = el('div', 'res-name');
      if (p.mine.indexOf(o.id) !== -1) name.appendChild(icon('fas fa-circle-check'));
      name.appendChild(document.createTextNode(o.label));
      var num = el('div', 'res-num');
      var b = el('b', null, String(o.count));
      num.appendChild(b);
      num.appendChild(document.createTextNode(' โหวต · ' + pct + '%'));
      top.appendChild(name);
      top.appendChild(num);
      var track = el('div', 'track');
      var fill = el('div', 'fill');
      fill.style.width = play ? '0%' : pct + '%';
      track.appendChild(fill);
      row.appendChild(top);
      row.appendChild(track);
      wrap.appendChild(row);
      bars.push([fill, pct]);
    });
    card.appendChild(wrap);

    var foot = p.total + ' คนโหวตแล้ว';
    if (p.max_choices !== 1) foot += ' · เปอร์เซ็นต์คิดจากจำนวนคนที่โหวต';
    card.appendChild(el('p', 'res-foot', foot));

    if (play) {
      animated[p.id] = true;
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          bars.forEach(function (x) { x[0].style.width = x[1] + '%'; });
        });
      });
    }
  }

  /* ---------- การ์ดโพล ---------- */
  function buildCard(p) {
    var voted = p.mine.length > 0;
    var card = el('article', 'poll');
    card.dataset.id = p.id;
    card.appendChild(head(p, voted));
    if (p.is_open && !voted) buildForm(p, card);
    else buildResults(p, card);
    return card;
  }

  function replacePoll(np) {
    for (var i = 0; i < polls.length; i++) {
      if (polls[i].id === np.id) { polls[i] = np; return; }
    }
    polls.unshift(np);
  }

  function render() {
    collectDraft();
    var lo = $('listOpen'), lc = $('listClosed'), box = $('emptyBox');
    lo.replaceChildren();
    lc.replaceChildren();
    box.replaceChildren();
    var nOpen = 0, nClosed = 0;
    polls.forEach(function (p) {
      if (p.is_open) { lo.appendChild(buildCard(p)); nOpen++; }
      else { lc.appendChild(buildCard(p)); nClosed++; }
    });
    $('labelOpen').hidden = nOpen === 0;
    $('labelClosed').hidden = nClosed === 0;

    if (nOpen + nClosed === 0) {
      var e = el('div', 'empty');
      e.appendChild(icon(LOAD_ERROR ? 'fas fa-triangle-exclamation' : 'fas fa-square-poll-vertical'));
      e.appendChild(el('h3', null, LOAD_ERROR ? 'โหลดข้อมูลไม่ได้' : 'ยังไม่มีโพลให้โหวต'));
      e.appendChild(el('p', null, LOAD_ERROR ? 'กรุณาลองใหม่อีกครั้งภายหลัง' : 'เมื่อมีหัวข้อใหม่ ระบบจะแจ้งเตือนให้ทราบ'));
      box.appendChild(e);
    }
  }

  /* ---------- รีเฟรชกราฟสดทุก 10 วินาที ---------- */
  function refresh() {
    if (busy || document.hidden) return;
    fetch('poll.php?action=data&_t=' + Date.now(), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.ok && !busy) { polls = d.polls; LOAD_ERROR = false; render(); }
      })
      .catch(function () {});
  }

  render();
  setInterval(refresh, 10000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
</script>
</body>
</html>
