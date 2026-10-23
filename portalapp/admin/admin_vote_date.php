<?php
date_default_timezone_set('Asia/Bangkok');

require 'db.php';          // ต้องมี $conn (mysqli)
require 'vote_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();

/* ===================== ตรวจสิทธิ์แอดมิน =====================
   ชื่อคีย์ session ที่หน้า login แอดมินเดิมเก็บ username ไว้ — แก้ให้ตรงกับระบบจริง
   (ถ้าไม่ตรง หน้านี้จะปฏิเสธการเข้าถึงทุกคน ไม่ได้เปิดให้ใครเข้า) */
const ADMIN_SESSION_KEY = 'admin_username';
const MAX_DAYS_AHEAD    = 30;   // ตั้งวันกลับล่วงหน้าได้ไม่เกินกี่วัน (กันพิมพ์วันที่ผิด)

function json_out(array $data, int $http = 200): void
{
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$admin = trim((string)($_SESSION[ADMIN_SESSION_KEY] ?? ''));
if ($admin === '') {
    if (($_GET['action'] ?? '') === 'duty') {
        json_out(['ok' => false, 'message' => 'ต้องเข้าสู่ระบบแอดมินก่อน'], 403);
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<body style="font-family:sans-serif;text-align:center;padding:48px 20px">'
       . '<h3>ต้องเข้าสู่ระบบแอดมินก่อน</h3><p><a href="main.php">กลับหน้าหลัก</a></p></body>';
    exit;
}

/* ===================== ตัวช่วย ===================== */
// ตรวจวันที่ที่แอดมินเลือก — คืนข้อความ error หรือ null ถ้าถูกต้อง
function validate_target(string $d): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return 'รูปแบบวันที่ไม่ถูกต้อง';
    }
    $today = date('Y-m-d');
    if ($d < $today) {
        return 'ไม่สามารถเลือกวันที่ผ่านมาแล้วได้';
    }
    if ($d > date('Y-m-d', strtotime('+' . MAX_DAYS_AHEAD . ' days'))) {
        return 'เลือกล่วงหน้าได้ไม่เกิน ' . MAX_DAYS_AHEAD . ' วัน';
    }
    return null;
}

function short_th(string $d): string
{
    $dn = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
    $mn = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $ts = strtotime($d);
    return $dn[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' ' . $mn[(int)date('n', $ts)];
}

function vote_count(mysqli $conn, string $date): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM weekly_votes WHERE vote_date = ?');
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $n;
}

/* ===================== API: ดูรายชื่อเวรของวันที่เลือก ===================== */
if (($_GET['action'] ?? '') === 'duty') {
    session_write_close();
    $d   = trim((string)($_GET['date'] ?? ''));
    $err = validate_target($d);
    if ($err) { json_out(['ok' => false, 'message' => $err]); }
    try {
        $duty  = duty_students_for_date($conn, $d);
        $total = (int)$conn->query('SELECT COUNT(*) AS c FROM students')->fetch_assoc()['c'];
        json_out([
            'ok'       => true,
            'date_th'  => thai_date_full($d),
            'is_fri'   => date('N', strtotime($d)) === '5',
            'total'    => $total,
            'voted'    => vote_count($conn, $d),
            'duty'     => $duty,
        ]);
    } catch (Throwable $e) {
        error_log('admin_vote_date duty: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'ดึงข้อมูลไม่สำเร็จ ลองใหม่อีกครั้ง'], 503);
    }
}

/* ===================== บันทึก / ล้างค่า ===================== */
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['ok' => false, 'message' => 'เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วทำรายการใหม่'];
    $token = (string)($_POST['csrf_token'] ?? '');

    if ($token !== '' && hash_equals((string)$_SESSION['admin_csrf'], $token)) {
        $act = (string)($_POST['action'] ?? '');
        try {
            if ($act === 'save') {
                $d   = trim((string)($_POST['target_date'] ?? ''));
                $err = validate_target($d);
                if ($err) {
                    $flash = ['ok' => false, 'message' => $err];
                } else {
                    $conn->begin_transaction();
                    $stmt = $conn->prepare(
                        'INSERT INTO system_settings (setting_name, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = ?'
                    );
                    foreach ([[VOTE_TARGET_KEY, $d], [VOTE_TARGET_BY_KEY, $admin]] as [$k, $v]) {
                        $stmt->bind_param('sss', $k, $v, $v);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $conn->commit();
                    $flash = ['ok' => true, 'message' => 'ตั้งวันกลับเป็น' . thai_date_full($d) . ' เรียบร้อยแล้ว'];
                }
            } elseif ($act === 'clear') {
                $stmt = $conn->prepare('DELETE FROM system_settings WHERE setting_name IN (?, ?)');
                $k1 = VOTE_TARGET_KEY; $k2 = VOTE_TARGET_BY_KEY;
                $stmt->bind_param('ss', $k1, $k2);
                $stmt->execute();
                $stmt->close();
                $flash = ['ok' => true, 'message' => 'กลับไปใช้วันศุกร์ตามปกติแล้ว'];
            } else {
                $flash = ['ok' => false, 'message' => 'คำสั่งไม่ถูกต้อง'];
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $e2) {}
            error_log('admin_vote_date save: ' . $e->getMessage());
            $flash = ['ok' => false, 'message' => 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง'];
        }
    }
    $_SESSION['admin_flash'] = $flash;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
$csrf = $_SESSION['admin_csrf'];
session_write_close();

/* ===================== ข้อมูลแสดงผล ===================== */
$override   = vote_override($conn);
$friday     = friday_target_date();
$current    = $override ? $override['date'] : $friday;
$today      = date('Y-m-d');
$max_date   = date('Y-m-d', strtotime('+' . MAX_DAYS_AHEAD . ' days'));
$voted_now  = vote_count($conn, $current);

// ปุ่มลัด: ตั้งแต่วันนี้ถึงวันศุกร์ตามปกติ
$chips = [];
for ($t = strtotime($today); $t <= strtotime($friday); $t += 86400) {
    $chips[] = date('Y-m-d', $t);
}

header('Cache-Control: private, no-store');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#5a42a0">
<title>ตั้งวันกลับ - MTTS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#5a42a0;--primary-dark:#473282;--ink:#1e293b;--mute:#64748b;--paper:#f6f8fc;--line:#dbe1ea;
  --ok:#15803d;--ok-bg:#f0fdf4;--bad:#b91c1c;--bad-bg:#fef2f2;--warn:#b45309;--warn-bg:#fffbeb}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{margin:0;font-family:'Prompt',system-ui,sans-serif;background:#e2e8f0;color:var(--ink);display:flex;justify-content:center;min-height:100vh}
.app{width:100%;max-width:560px;background:var(--paper);min-height:100vh;padding-bottom:32px;box-shadow:0 0 24px rgba(15,23,42,.08)}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px}
.top h1{margin:0;font-size:1rem;font-weight:700}
.top p{margin:0;font-size:.75rem;color:var(--mute)}
.top a{font-size:.8rem;color:var(--primary);text-decoration:none;font-weight:600}

.hero{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;padding:22px 20px 26px;border-radius:0 0 32px 32px;box-shadow:0 8px 20px rgba(90,66,160,.15)}
.badge{display:inline-block;font-size:.75rem;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.2);margin-bottom:8px}
.hero small{display:block;font-size:.8rem;opacity:.9}
.hero strong{display:block;font-size:1.35rem;font-weight:700;line-height:1.3;margin:4px 0 6px}
.hero .sub{font-size:.78rem;opacity:.88;line-height:1.5}

.card{background:#fff;border:1px solid var(--line);border-radius:18px;margin:18px 20px 0;padding:18px}
.card h2{margin:0 0 12px;font-size:.95rem}
.input{width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:10px;background:#f8fafc;font:inherit;font-size:1rem;color:var(--ink);outline:none}
.input:focus{border-color:var(--primary);background:#fff}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.chip{border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:7px 12px;font:inherit;font-size:.8rem;font-weight:600;color:var(--ink);cursor:pointer}
.chip.on{border-color:var(--primary);background:#ede9fe;color:var(--primary-dark)}
.chip small{font-weight:500;color:var(--mute)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:12px;padding:13px 18px;font:inherit;font-size:1rem;font-weight:600;cursor:pointer;width:100%}
.btn:active{transform:scale(.98)}
.btn-primary{background:var(--primary);color:#fff;margin-top:14px}
.btn-primary:disabled{opacity:.55;cursor:not-allowed}
.btn-ghost{background:#f1f5f9;color:var(--mute);margin-top:10px}
.notice{margin:18px 20px 0;padding:12px 14px;border-radius:12px;font-size:.85rem;line-height:1.5;font-weight:500}
.notice.ok{background:var(--ok-bg);color:var(--ok);border:1px solid #bbf7d0}
.notice.bad{background:var(--bad-bg);color:var(--bad);border:1px solid #fecaca}
.note{margin:10px 0 0;padding:10px 12px;border-radius:10px;background:var(--warn-bg);color:var(--warn);font-size:.78rem;line-height:1.5;border:1px solid #fde68a}

.meta{display:flex;gap:10px;margin:0 0 12px}
.meta div{flex:1;padding:8px 10px;border-radius:10px;background:#f8fafc;font-size:.75rem;color:var(--mute)}
.meta b{display:block;font-size:1.05rem;color:var(--ink)}
.duty{list-style:none;margin:0;padding:0;counter-reset:n}
.duty li{counter-increment:n;display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid #eef1f6;font-size:.88rem}
.duty li:first-child{border-top:0}
.duty li::before{content:counter(n);flex:0 0 24px;height:24px;border-radius:50%;background:#ede9fe;color:var(--primary-dark);font-size:.72rem;font-weight:600;display:grid;place-items:center}
.duty .nm{flex:1;min-width:0}
.duty .cd{font-size:.75rem;color:var(--mute);white-space:nowrap}
.state{padding:14px;text-align:center;font-size:.85rem;color:var(--mute)}
</style>
</head>
<body>
<div class="app">

  <header class="top">
    <div>
      <h1>ตั้งวันกลับ</h1>
      <p>แอดมิน: <?php echo htmlspecialchars($admin); ?></p>
    </div>
    <a href="main.php">หน้าหลัก</a>
  </header>

  <section class="hero">
    <span class="badge"><?php echo $override ? 'แอดมินกำหนดวันกลับ' : 'ปกติ (วันศุกร์)'; ?></span>
    <small>วันกลับที่ นชท. กำลังแจ้งความประสงค์อยู่ตอนนี้</small>
    <strong><?php echo htmlspecialchars(thai_date_full($current)); ?></strong>
    <div class="sub">
      กรอกแล้ว <?php echo $voted_now; ?> คน
<?php if ($override): ?>
      <br>ตั้งโดย <?php echo htmlspecialchars($override['by'] !== '' ? $override['by'] : '-'); ?> · พ้นวันนี้แล้วระบบจะกลับเป็นวันศุกร์เองอัตโนมัติ
<?php endif; ?>
    </div>
  </section>

<?php if ($flash): ?>
  <div class="notice <?php echo !empty($flash['ok']) ? 'ok' : 'bad'; ?>" role="status"><?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?></div>
<?php endif; ?>

  <form id="saveForm" class="card" method="post" action="">
    <h2>เลือกวันกลับ</h2>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="action" value="save">

    <div class="chips" id="chips">
<?php foreach ($chips as $c): ?>
      <button type="button" class="chip" data-date="<?php echo $c; ?>"><?php echo htmlspecialchars(short_th($c)); ?><?php echo $c === $friday ? ' <small>(ปกติ)</small>' : ''; ?></button>
<?php endforeach; ?>
    </div>

    <input class="input" type="date" id="target_date" name="target_date" required
           min="<?php echo $today; ?>" max="<?php echo $max_date; ?>" value="<?php echo htmlspecialchars($current); ?>">
    <div class="note">เมื่อบันทึก หน้าแจ้งความประสงค์ของ นชท. จะเปลี่ยนเป็นวันที่นี้ทันที คนที่กรอกวันศุกร์ไปแล้วจะกรอกของวันใหม่ได้อีกครั้ง (ข้อมูลเก็บแยกตามวัน)</div>

    <button type="submit" id="saveBtn" class="btn btn-primary">บันทึกวันกลับ</button>
  </form>

<?php if ($override): ?>
  <form id="clearForm" class="card" method="post" action="" style="padding-top:8px">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="action" value="clear">
    <button type="submit" class="btn btn-ghost" style="margin-top:10px">กลับไปใช้วันศุกร์ตามปกติ (<?php echo htmlspecialchars(short_th($friday)); ?>)</button>
  </form>
<?php endif; ?>

  <section class="card" aria-live="polite">
    <h2>รายชื่อเวรประจำวันที่เลือก</h2>
    <div id="preview"><div class="state">กำลังโหลด...</div></div>
  </section>

</div>

<script>
(function () {
  var $ = function (s) { return document.querySelector(s); };
  var dateIn = $('#target_date'), box = $('#preview'), chips = document.querySelectorAll('.chip');
  var ctl = null, seq = 0;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function state(msg) { box.replaceChildren(el('div', 'state', msg)); }

  function markChips() {
    chips.forEach(function (c) { c.classList.toggle('on', c.getAttribute('data-date') === dateIn.value); });
  }

  function render(d) {
    box.replaceChildren();
    box.appendChild(el('p', null, d.date_th)).style.cssText = 'margin:0 0 10px;font-size:.85rem;font-weight:600';
    var meta = el('div', 'meta');
    [['ผู้อยู่เวร', d.duty.length + ' คน'], ['กรอกแล้ว', d.voted + ' / ' + d.total]].forEach(function (kv) {
      var m = el('div', null, kv[0]); m.appendChild(el('b', null, kv[1])); meta.appendChild(m);
    });
    box.appendChild(meta);
    if (!d.duty.length) { box.appendChild(el('div', 'state', 'ไม่พบรายชื่อนักเรียนในระบบ')); return; }
    var ul = el('ul', 'duty');
    d.duty.forEach(function (p) {
      var li = el('li');
      li.appendChild(el('span', 'nm', p.name));
      li.appendChild(el('span', 'cd', p.code + (p.section ? ' · ตอน ' + p.section : '')));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }

  async function load() {
    var v = dateIn.value; markChips();
    if (!v) { state('เลือกวันที่เพื่อดูรายชื่อเวร'); return; }
    if (ctl) ctl.abort();
    ctl = new AbortController(); var my = ++seq;
    state('กำลังโหลด...');
    try {
      var r = await fetch('?action=duty&date=' + encodeURIComponent(v), { signal: ctl.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
      var d = await r.json();
      if (my !== seq) return;
      if (!d.ok) { state(d.message || 'ดึงข้อมูลไม่สำเร็จ'); return; }
      render(d);
    } catch (e) {
      if (e.name !== 'AbortError') state('เชื่อมต่อไม่ได้ ลองใหม่อีกครั้ง');
    }
  }

  chips.forEach(function (c) {
    c.addEventListener('click', function () { dateIn.value = c.getAttribute('data-date'); load(); });
  });
  dateIn.addEventListener('change', load);

  $('#saveForm').addEventListener('submit', function (e) {
    if (!confirm('ยืนยันตั้งวันกลับเป็นวันที่ ' + dateIn.value + ' ?\nหน้าแจ้งความประสงค์ของ นชท. จะเปลี่ยนทันที')) {
      e.preventDefault();
    } else {
      $('#saveBtn').disabled = true;
    }
  });
  var cf = $('#clearForm');
  if (cf) cf.addEventListener('submit', function (e) {
    if (!confirm('กลับไปใช้วันศุกร์ตามปกติ ?')) e.preventDefault();
  });

  load();
})();
</script>
</body>
</html>
