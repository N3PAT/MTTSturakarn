<?php
/**
 * feedback.php — ส่งข้อเสนอแนะ / แจ้งปัญหา (นักเรียน)
 * ต้องสร้างตารางด้วย feedback.sql ก่อน และต้องมี maintenance_gate.php อยู่โฟลเดอร์เดียวกัน
 */
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once 'db.php';
require_once 'maintenance_gate.php';
mtts_maintenance_gate($conn ?? null);

const APP_LOGO_URL = 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg';
const FB_MAX_PER_DAY = 5;
const FB_MIN_LEN = 10;
const FB_MAX_LEN = 1000;

$CATEGORIES = [
    'general' => ['ทั่วไป',            'fa-comment-dots'],
    'idea'    => ['อยากให้มีฟีเจอร์',   'fa-lightbulb'],
    'bug'     => ['แจ้งปัญหา/บั๊ก',     'fa-bug'],
    'data'    => ['ข้อมูลไม่ถูกต้อง',   'fa-triangle-exclamation'],
    'praise'  => ['ชื่นชม',             'fa-heart'],
];
$STATUS = [
    'new'      => ['รอดำเนินการ', 'st-new'],
    'read'     => ['รับทราบแล้ว',  'st-read'],
    'resolved' => ['แก้ไขแล้ว',    'st-done'],
];

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function json_out(array $data, int $code = 200): void
{
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if (empty($_SESSION['student_code'])) {
    if ($isPost) json_out(['success' => false, 'error' => 'กรุณาเข้าสู่ระบบใหม่'], 401);
    header('Location: login.php');
    exit();
}
$code = trim((string)$_SESSION['student_code']);

if (empty($_SESSION['fb_csrf'])) {
    $_SESSION['fb_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['fb_csrf'];

/* ===================== POST: บันทึกข้อเสนอแนะ ===================== */
if ($isPost) {
    session_write_close();

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['success' => false, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้า'], 403);
    }

    $cat     = (string)($_POST['category'] ?? '');
    $rating  = (int)($_POST['rating'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $message);
    $anon    = !empty($_POST['anonymous']) ? 1 : 0;
    $len     = mb_strlen($message, 'UTF-8');

    if (!isset($CATEGORIES[$cat]))            json_out(['success' => false, 'error' => 'กรุณาเลือกหมวดหมู่']);
    if ($rating < 1 || $rating > 5)           json_out(['success' => false, 'error' => 'กรุณาให้คะแนนความพึงพอใจ']);
    if ($len < FB_MIN_LEN)                    json_out(['success' => false, 'error' => 'กรุณาเขียนอย่างน้อย ' . FB_MIN_LEN . ' ตัวอักษร']);
    if ($len > FB_MAX_LEN)                    json_out(['success' => false, 'error' => 'ข้อความยาวเกิน ' . FB_MAX_LEN . ' ตัวอักษร']);

    try {
        $conn->set_charset('utf8mb4');

        // กันกดซ้ำ: ข้อความเดิมภายใน 10 นาที ถือว่าส่งสำเร็จแล้ว
        $st = $conn->prepare("SELECT id FROM feedback WHERE student_code = ? AND message = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE) LIMIT 1");
        $st->bind_param('ss', $code, $message);
        $st->execute();
        $dup = $st->get_result()->fetch_assoc();
        $st->close();
        if ($dup) json_out(['success' => true]);

        // จำกัดจำนวนต่อวัน
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM feedback WHERE student_code = ? AND created_at >= (NOW() - INTERVAL 1 DAY)");
        $st->bind_param('s', $code);
        $st->execute();
        $cnt = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        if ($cnt >= FB_MAX_PER_DAY) {
            json_out(['success' => false, 'error' => 'ส่งได้สูงสุด ' . FB_MAX_PER_DAY . ' ครั้งต่อวัน กรุณาลองใหม่พรุ่งนี้']);
        }

        $st = $conn->prepare("INSERT INTO feedback (student_code, category, rating, message, is_anonymous) VALUES (?, ?, ?, ?, ?)");
        $st->bind_param('ssisi', $code, $cat, $rating, $message, $anon);
        $st->execute();
        $st->close();

        json_out(['success' => true]);
    } catch (Throwable $ex) {
        error_log('feedback save: ' . $ex->getMessage());
        json_out(['success' => false, 'error' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'], 500);
    }
}

/* ===================== GET: แสดงหน้า ===================== */
$student = null;
$history = [];
$load_error = '';
try {
    $conn->set_charset('utf8mb4');

    $st = $conn->prepare("SELECT prefix, first_name, last_name FROM students WHERE student_code = ? LIMIT 1");
    $st->bind_param('s', $code);
    $st->execute();
    $student = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$student) {
        session_destroy();
        header('Location: login.php');
        exit();
    }

    $st = $conn->prepare("SELECT category, rating, message, status, admin_reply, replied_at, created_at
                          FROM feedback WHERE student_code = ? ORDER BY created_at DESC, id DESC LIMIT 10");
    $st->bind_param('s', $code);
    $st->execute();
    $history = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} catch (Throwable $ex) {
    error_log('feedback page: ' . $ex->getMessage());
    $load_error = 'ยังใช้งานหน้านี้ไม่ได้ (ตาราง feedback อาจยังไม่ถูกสร้าง) กรุณาแจ้งผู้ดูแลระบบ';
}
session_write_close();

function th_date(string $ts): string { return date('d/m/Y H:i', strtotime($ts)); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#503896">
  <title>ข้อเสนอแนะ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--primary:#503896;--bg:#f2f3f8;--ink:#1c1b29;--mute:#6b7280;--faint:#94a3b8;--danger:#ef4444;--success:#10b981;--star:#f59e0b;--g:16px;--nav:64px;--st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);--shadow:0 1px 2px rgba(20,20,43,.04),0 2px 8px rgba(20,20,43,.04)}
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
    [hidden]{display:none!important}
    body{margin:0;font-family:'Prompt',sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100dvh}
    .app{width:100%;max-width:430px;min-height:100dvh;background:var(--bg);padding-bottom:calc(var(--nav) + var(--sb) + 20px)}
    @media(min-width:431px){.app{box-shadow:0 0 40px rgba(15,23,42,.14)}}

    .top{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:12px;padding:calc(10px + var(--st)) var(--g) 10px;background:rgba(80,56,150,.96);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);color:#fff}
    .back{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;text-decoration:none}
    .top h1{margin:0;font-size:1rem;font-weight:600;line-height:1.25}
    .top p{margin:0;font-size:.72rem;opacity:.8}

    .card{margin:16px var(--g) 0;padding:18px 16px;background:#fff;border-radius:20px;box-shadow:var(--shadow)}
    .card h2{margin:0 0 4px;font-size:.95rem;font-weight:600}
    .card .hint{margin:0 0 14px;font-size:.78rem;color:var(--mute);line-height:1.5}
    .lbl{display:block;margin:16px 0 8px;font-size:.8rem;font-weight:600;color:var(--mute)}
    .lbl:first-of-type{margin-top:0}

    .chips{display:flex;flex-wrap:wrap;gap:8px}
    .chip input{position:absolute;opacity:0;pointer-events:none}
    .chip span{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:999px;border:1.5px solid #e5e7f0;background:#fff;font-size:.8rem;font-weight:500;color:var(--mute);cursor:pointer;transition:all .15s}
    .chip input:checked+span{background:var(--primary);border-color:var(--primary);color:#fff}
    .chip input:focus-visible+span{outline:3px solid #c4b5fd;outline-offset:2px}

    .stars{display:flex;gap:6px;direction:rtl;justify-content:flex-end}
    .stars input{position:absolute;opacity:0;pointer-events:none}
    .stars label{font-size:2rem;color:#e2e8f0;cursor:pointer;transition:color .12s,transform .12s;line-height:1}
    .stars label:active{transform:scale(.85)}
    .stars input:checked~label,.stars label:hover,.stars label:hover~label{color:var(--star)}
    .stars input:focus-visible+label{outline:3px solid #c4b5fd;border-radius:6px}
    .star-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .star-text{font-size:.8rem;color:var(--mute);min-width:70px}

    textarea{width:100%;min-height:130px;padding:12px 14px;border:1.5px solid #e5e7f0;border-radius:14px;font:inherit;font-size:.9rem;line-height:1.5;resize:vertical;outline:none;color:var(--ink)}
    textarea:focus{border-color:var(--primary)}
    .counter{margin-top:4px;text-align:right;font-size:.72rem;color:var(--faint)}
    .counter.bad{color:var(--danger)}

    .switch{display:flex;align-items:flex-start;gap:10px;margin-top:14px;font-size:.82rem;color:var(--ink);cursor:pointer}
    .switch input{margin-top:3px;width:18px;height:18px;accent-color:var(--primary);flex:none}
    .switch small{display:block;color:var(--mute);font-size:.72rem;line-height:1.4}

    .err{margin-top:12px;padding:10px 12px;border-radius:12px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:.8rem}
    .btn{width:100%;height:50px;margin-top:16px;border:0;border-radius:25px;background:var(--primary);color:#fff;font:inherit;font-size:1rem;font-weight:600;cursor:pointer;box-shadow:0 4px 15px rgba(80,56,150,.3);transition:transform .12s,opacity .2s}
    .btn:active{transform:scale(.98)}
    .btn:disabled{opacity:.55;cursor:default}
    .btn.ghost{background:#efeaf9;color:var(--primary);box-shadow:none}

    .done{text-align:center;padding:30px 16px}
    .done i{font-size:3.2rem;color:var(--success);animation:pop .5s cubic-bezier(.16,1,.3,1)}
    @keyframes pop{from{transform:scale(.4);opacity:0}}
    .done h2{margin:12px 0 4px;font-size:1.1rem}
    .done p{margin:0;color:var(--mute);font-size:.85rem}

    .sec-title{margin:22px calc(var(--g) + 4px) 8px;font-size:.8rem;font-weight:600;color:var(--mute)}
    .list{margin:0 var(--g);display:flex;flex-direction:column;gap:10px}
    .item{padding:14px;background:#fff;border-radius:16px;box-shadow:var(--shadow)}
    .item-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}
    .tag{display:inline-flex;align-items:center;gap:6px;font-size:.72rem;font-weight:600;color:var(--primary)}
    .st{padding:2px 10px;border-radius:999px;font-size:.68rem;font-weight:600}
    .st-new{background:#fef3c7;color:#b45309}.st-read{background:#dbeafe;color:#1d4ed8}.st-done{background:#d1fae5;color:#047857}
    .item .stars-ro{color:var(--star);font-size:.75rem;letter-spacing:1px}
    .item .stars-ro .off{color:#e2e8f0}
    .item p{margin:6px 0 4px;font-size:.84rem;line-height:1.55;white-space:pre-line;word-break:break-word}
    .item time{font-size:.7rem;color:var(--faint)}
    .reply{margin-top:10px;padding:10px 12px;border-radius:12px;background:#f5f3ff;border-left:3px solid var(--primary);font-size:.8rem;line-height:1.5;white-space:pre-line;word-break:break-word}
    .reply b{display:block;margin-bottom:2px;font-size:.72rem;color:var(--primary)}
    .empty{margin:0 var(--g);padding:24px;text-align:center;background:#fff;border-radius:16px;color:var(--faint);font-size:.85rem}

    .bottom-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:1000;width:100%;max-width:430px;height:calc(var(--nav) + var(--sb));padding-bottom:var(--sb);display:flex;align-items:center;background:var(--primary);box-shadow:0 -4px 18px rgba(20,20,43,.18)}
    .nav-link{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;color:rgba(255,255,255,.6);font-size:.7rem;font-weight:500;text-decoration:none}
    .nav-link i{display:grid;place-items:center;width:56px;height:30px;border-radius:15px;font-size:1.2rem}
    @media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
  </style>
</head>
<body>
<div class="app">
  <header class="top">
    <a href="main.php" class="back" aria-label="ย้อนกลับ"><i class="fas fa-arrow-left"></i></a>
    <div>
      <h1>ข้อเสนอแนะ</h1>
      <p>บอกเราว่าควรปรับปรุงอะไร</p>
    </div>
  </header>

<?php if ($load_error !== ''): ?>
  <div class="card"><div class="err" style="margin:0"><?= e($load_error) ?></div></div>
<?php else: ?>

  <!-- ฟอร์ม -->
  <form class="card" id="fbForm" novalidate>
    <h2>ส่งข้อเสนอแนะ</h2>
    <p class="hint">ความเห็นของคุณช่วยให้ระบบดีขึ้น ผู้ดูแลจะอ่านทุกข้อความ และอาจตอบกลับในหน้านี้</p>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

    <span class="lbl">หมวดหมู่</span>
    <div class="chips" role="radiogroup" aria-label="หมวดหมู่">
<?php foreach ($CATEGORIES as $key => [$label, $icon]): ?>
      <label class="chip"><input type="radio" name="category" value="<?= e($key) ?>"<?= $key === 'general' ? ' checked' : '' ?>><span><i class="fas <?= e($icon) ?>"></i> <?= e($label) ?></span></label>
<?php endforeach; ?>
    </div>

    <span class="lbl">ความพึงพอใจต่อระบบ</span>
    <div class="star-row">
      <span class="star-text" id="starText">ยังไม่ให้คะแนน</span>
      <div class="stars" role="radiogroup" aria-label="ให้คะแนน 1 ถึง 5 ดาว">
<?php for ($i = 5; $i >= 1; $i--): ?>
        <input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>"><label for="r<?= $i ?>" title="<?= $i ?> ดาว"><i class="fas fa-star"></i></label>
<?php endfor; ?>
      </div>
    </div>

    <span class="lbl">รายละเอียด</span>
    <textarea name="message" id="msg" maxlength="<?= FB_MAX_LEN ?>" placeholder="เล่าให้เราฟังได้เลย เช่น เจออะไรที่ใช้ยาก หรืออยากให้เพิ่มอะไร..."></textarea>
    <div class="counter" id="counter">0 / <?= FB_MAX_LEN ?></div>

    <label class="switch">
      <input type="checkbox" name="anonymous" value="1">
      <span>ไม่แสดงชื่อของฉันในหน้าผู้ดูแล<small>ผู้ดูแลจะเห็นเฉพาะข้อความและคะแนน</small></span>
    </label>

    <div class="err" id="err" role="alert" hidden></div>
    <button type="submit" class="btn" id="submitBtn">ส่งข้อเสนอแนะ</button>
  </form>

  <!-- สำเร็จ -->
  <div class="card done" id="doneBox" hidden>
    <i class="fas fa-circle-check"></i>
    <h2>ขอบคุณสำหรับข้อเสนอแนะ</h2>
    <p>เราได้รับข้อความของคุณแล้ว</p>
    <button type="button" class="btn ghost" onclick="location.reload()">ส่งอีกข้อความ</button>
  </div>

  <!-- ประวัติ -->
  <div class="sec-title">ข้อเสนอแนะของฉัน</div>
<?php if ($history): ?>
  <div class="list">
<?php foreach ($history as $h):
    [$cl, $ci] = $CATEGORIES[$h['category']] ?? ['ทั่วไป', 'fa-comment-dots'];
    [$sl, $sc] = $STATUS[$h['status']] ?? $STATUS['new'];
    $r = max(0, min(5, (int)$h['rating']));
?>
    <article class="item">
      <div class="item-top">
        <span class="tag"><i class="fas <?= e($ci) ?>"></i> <?= e($cl) ?></span>
        <span class="st <?= e($sc) ?>"><?= e($sl) ?></span>
      </div>
      <div class="stars-ro" aria-label="<?= $r ?> ดาว"><?= str_repeat('★', $r) ?><span class="off"><?= str_repeat('★', 5 - $r) ?></span></div>
      <p><?= e($h['message']) ?></p>
      <time><?= e(th_date($h['created_at'])) ?></time>
<?php if (!empty($h['admin_reply'])): ?>
      <div class="reply"><b><i class="fas fa-reply"></i> ตอบกลับจากผู้ดูแล<?= !empty($h['replied_at']) ? ' · ' . e(th_date($h['replied_at'])) : '' ?></b><?= e($h['admin_reply']) ?></div>
<?php endif; ?>
    </article>
<?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="empty">ยังไม่มีข้อเสนอแนะที่ส่งไว้</div>
<?php endif; ?>

<?php endif; ?>
</div>

<nav class="bottom-nav" aria-label="เมนูหลัก">
  <a href="main.php" class="nav-link"><i class="fas fa-home"></i><span>หน้าหลัก</span></a>
  <a href="profile.php" class="nav-link"><i class="fas fa-user"></i><span>โปรไฟล์</span></a>
</nav>

<script>
(function () {
  var form = document.getElementById('fbForm');
  if (!form) return;
  var msg = document.getElementById('msg'), counter = document.getElementById('counter');
  var err = document.getElementById('err'), btn = document.getElementById('submitBtn');
  var starText = document.getElementById('starText');
  var MIN = <?= FB_MIN_LEN ?>, MAX = <?= FB_MAX_LEN ?>;
  var labels = ['', 'ควรปรับปรุง', 'พอใช้', 'ปานกลาง', 'ดี', 'ดีมาก'];

  msg.addEventListener('input', function () {
    var n = msg.value.length;
    counter.textContent = n + ' / ' + MAX;
    counter.classList.toggle('bad', n > 0 && n < MIN);
  });
  Array.prototype.forEach.call(form.querySelectorAll('input[name=rating]'), function (r) {
    r.addEventListener('change', function () { starText.textContent = labels[r.value]; });
  });

  function showErr(t) { err.textContent = t; err.hidden = false; err.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    err.hidden = true;
    if (!form.querySelector('input[name=rating]:checked')) return showErr('กรุณาให้คะแนนความพึงพอใจ');
    if (msg.value.trim().length < MIN) return showErr('กรุณาเขียนอย่างน้อย ' + MIN + ' ตัวอักษร');

    btn.disabled = true; btn.textContent = 'กำลังส่ง...';
    fetch('feedback.php', { method: 'POST', credentials: 'same-origin', body: new FormData(form) })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: 'เกิดข้อผิดพลาด กรุณาลองใหม่' }; }); })
      .then(function (res) {
        if (res.success) {
          form.hidden = true;
          document.getElementById('doneBox').hidden = false;
          window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
          showErr(res.error || 'ส่งไม่สำเร็จ กรุณาลองใหม่');
          btn.disabled = false; btn.textContent = 'ส่งข้อเสนอแนะ';
        }
      })
      .catch(function () {
        showErr('เชื่อมต่อไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองใหม่');
        btn.disabled = false; btn.textContent = 'ส่งข้อเสนอแนะ';
      });
  });
})();
</script>
<?php mtts_maintenance_watch_js(); ?>
</body>
</html>
