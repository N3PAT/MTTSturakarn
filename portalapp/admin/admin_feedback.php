<?php
/**
 * admin_feedback.php — จัดการข้อเสนอแนะ / แจ้งปัญหา (ผู้ดูแลระบบ)
 */
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once 'db.php';
require_once 'maintenance_gate.php';
mtts_maintenance_gate($conn ?? null);

const FB_REPLY_MAX_LEN = 1000;

$CATEGORIES = [
    'general' => ['ทั่วไป',          'fa-comment-dots'],
    'idea'    => ['อยากให้มีฟีเจอร์', 'fa-lightbulb'],
    'bug'     => ['แจ้งปัญหา/บั๊ก',   'fa-bug'],
    'data'    => ['ข้อมูลไม่ถูกต้อง', 'fa-triangle-exclamation'],
    'praise'  => ['ชื่นชม',           'fa-heart'],
];

$STATUS = [
    'new'      => ['รอดำเนินการ', 'st-new'],
    'read'     => ['รับทราบแล้ว',  'st-read'],
    'resolved' => ['แก้ไขแล้ว',    'st-done'],
];

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $code = 200): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function th_date(?string $ts): string
{
    if (empty($ts)) return '';
    $t = strtotime($ts);
    return $t ? date('d/m/Y H:i', $t) : '';
}

function is_admin_session(): bool
{
    if (!empty($_SESSION['is_admin']) || !empty($_SESSION['admin_id'])) {
        return true;
    }
    $role = strtolower(trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));
    return in_array($role, ['admin', 'superadmin', 'staff', 'commander'], true);
}

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if (!is_admin_session()) {
    if ($isPost) {
        json_out(['success' => false, 'error' => 'ไม่มีสิทธิ์เข้าถึงส่วนผู้ดูแลระบบ'], 403);
    }
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['admin_fb_csrf'])) {
    $_SESSION['admin_fb_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['admin_fb_csrf'];

/* ===================== POST: อัปเดตสถานะ / ตอบกลับ ===================== */
if ($isPost) {
    session_write_close();

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['success' => false, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้า'], 403);
    }

    $id     = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $reply  = trim((string)($_POST['admin_reply'] ?? ''));
    $reply  = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $reply);

    if ($id <= 0) {
        json_out(['success' => false, 'error' => 'ไม่พบรายการข้อเสนอแนะ'], 400);
    }
    if (!isset($STATUS[$status])) {
        json_out(['success' => false, 'error' => 'สถานะไม่ถูกต้อง'], 400);
    }
    if (mb_strlen($reply, 'UTF-8') > FB_REPLY_MAX_LEN) {
        json_out(['success' => false, 'error' => 'ข้อความตอบกลับยาวเกิน ' . FB_REPLY_MAX_LEN . ' ตัวอักษร'], 400);
    }

    try {
        $conn->set_charset('utf8mb4');

        $chk = $conn->prepare("SELECT id, admin_reply FROM feedback WHERE id = ? LIMIT 1");
        $chk->bind_param('i', $id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$existing) {
            json_out(['success' => false, 'error' => 'ไม่พบรายการที่ต้องการอัปเดต'], 404);
        }

        $replyParam   = ($reply === '') ? null : $reply;
        $replyChanged = ($reply !== '') && ($reply !== trim((string)($existing['admin_reply'] ?? '')));

        if ($reply === '') {
            $st = $conn->prepare("UPDATE feedback SET status = ?, admin_reply = NULL, replied_at = NULL WHERE id = ?");
            $st->bind_param('si', $status, $id);
        } elseif ($replyChanged) {
            $st = $conn->prepare("UPDATE feedback SET status = ?, admin_reply = ?, replied_at = NOW() WHERE id = ?");
            $st->bind_param('ssi', $status, $replyParam, $id);
        } else {
            $st = $conn->prepare("UPDATE feedback SET status = ?, admin_reply = ? WHERE id = ?");
            $st->bind_param('ssi', $status, $replyParam, $id);
        }

        $st->execute();
        $st->close();

        $outSt = $conn->prepare("SELECT status, admin_reply, replied_at FROM feedback WHERE id = ? LIMIT 1");
        $outSt->bind_param('i', $id);
        $outSt->execute();
        $updated = $outSt->get_result()->fetch_assoc();
        $outSt->close();

        [$sLabel, $sClass] = $STATUS[$updated['status']] ?? $STATUS['new'];

        json_out([
            'success'      => true,
            'id'           => $id,
            'status'       => $updated['status'],
            'status_label' => $sLabel,
            'status_class' => $sClass,
            'admin_reply'  => (string)($updated['admin_reply'] ?? ''),
            'replied_at'   => th_date($updated['replied_at'] ?? null),
        ]);
    } catch (Throwable $ex) {
        error_log('admin_feedback update: ' . $ex->getMessage());
        json_out(['success' => false, 'error' => 'บันทึกการเปลี่ยนแปลงไม่สำเร็จ'], 500);
    }
}

/* ===================== GET: ดึงข้อมูลและตัวกรอง ===================== */
$filterStatus = (string)($_GET['status'] ?? 'all');
if ($filterStatus !== 'all' && !isset($STATUS[$filterStatus])) {
    $filterStatus = 'all';
}

$filterCat = (string)($_GET['category'] ?? 'all');
if ($filterCat !== 'all' && !isset($CATEGORIES[$filterCat])) {
    $filterCat = 'all';
}

$search = trim((string)($_GET['q'] ?? ''));

$stats = [
    'total'      => 0,
    'new'        => 0,
    'read'       => 0,
    'resolved'   => 0,
    'avg_rating' => 0.0,
];
$items = [];
$load_error = '';

try {
    $conn->set_charset('utf8mb4');

    $statSql = "SELECT
                    COUNT(*) AS total_cnt,
                    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_cnt,
                    SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS read_cnt,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_cnt,
                    AVG(rating) AS avg_rating
                FROM feedback";
    $statRes = $conn->query($statSql);
    if ($statRes && ($row = $statRes->fetch_assoc())) {
        $stats['total']      = (int)($row['total_cnt'] ?? 0);
        $stats['new']        = (int)($row['new_cnt'] ?? 0);
        $stats['read']       = (int)($row['read_cnt'] ?? 0);
        $stats['resolved']   = (int)($row['resolved_cnt'] ?? 0);
        $stats['avg_rating'] = round((float)($row['avg_rating'] ?? 0), 1);
    }

    $where  = [];
    $types  = '';
    $params = [];

    if ($filterStatus !== 'all') {
        $where[]  = 'f.status = ?';
        $types   .= 's';
        $params[] = $filterStatus;
    }
    if ($filterCat !== 'all') {
        $where[]  = 'f.category = ?';
        $types   .= 's';
        $params[] = $filterCat;
    }
    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(f.message LIKE ? OR f.admin_reply LIKE ? OR f.student_code LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)';
        $types   .= 'sssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT
                f.id,
                f.student_code,
                f.category,
                f.rating,
                f.message,
                f.is_anonymous,
                f.status,
                f.admin_reply,
                f.replied_at,
                f.created_at,
                s.prefix,
                s.first_name,
                s.last_name
            FROM feedback f
            LEFT JOIN students s ON s.student_code = f.student_code";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY FIELD(f.status, "new", "read", "resolved"), f.created_at DESC, f.id DESC LIMIT 200';

    $st = $conn->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} catch (Throwable $ex) {
    error_log('admin_feedback load: ' . $ex->getMessage());
    $load_error = 'ไม่สามารถโหลดข้อมูลข้อเสนอแนะได้ กรุณาตรวจสอบตาราง feedback หรือการเชื่อมต่อฐานข้อมูล';
}

session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#503896">
  <title>จัดการข้อเสนอแนะ (Admin) - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --primary:#503896;--primary-dark:#3b2775;--bg:#f2f3f8;--ink:#1c1b29;
      --mute:#6b7280;--faint:#94a3b8;--danger:#ef4444;--success:#10b981;
      --star:#f59e0b;--border:#e5e7f0;--g:16px;
      --st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);
      --shadow:0 1px 2px rgba(20,20,43,.04),0 2px 10px rgba(20,20,43,.05)
    }
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
    [hidden]{display:none!important}
    body{margin:0;font-family:'Prompt',sans-serif;background:var(--bg);color:var(--ink);min-height:100dvh;padding-bottom:calc(28px + var(--sb))}

    .top{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:calc(12px + var(--st)) var(--g) 12px;background:rgba(80,56,150,.96);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);color:#fff}
    .top-left{display:flex;align-items:center;gap:12px}
    .back{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;text-decoration:none;flex:none}
    .top h1{margin:0;font-size:1rem;font-weight:600;line-height:1.25}
    .top p{margin:0;font-size:.74rem;opacity:.82}

    .wrap{max-width:960px;margin:0 auto;padding:16px var(--g) 0}

    .stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
    @media(min-width:640px){.stats{grid-template-columns:repeat(5,1fr)}}
    .stat{background:#fff;border-radius:16px;padding:14px;box-shadow:var(--shadow)}
    .stat .num{font-size:1.35rem;font-weight:700;line-height:1.2;color:var(--primary)}
    .stat .lbl{font-size:.74rem;color:var(--mute);margin-top:2px}
    .stat.warn .num{color:#b45309}
    .stat.ok .num{color:#047857}
    .stat.star .num{color:#d97706}

    .filter-card{margin-top:14px;background:#fff;border-radius:18px;padding:14px 16px;box-shadow:var(--shadow)}
    .filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
    .tabs{display:flex;flex-wrap:wrap;gap:6px}
    .tab{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;border:1.5px solid var(--border);background:#fff;color:var(--mute);font:inherit;font-size:.78rem;font-weight:500;text-decoration:none;cursor:pointer}
    .tab.active{background:var(--primary);border-color:var(--primary);color:#fff}

    .search-form{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
    .search-form select,.search-form input{height:40px;padding:0 12px;border:1.5px solid var(--border);border-radius:12px;font:inherit;font-size:.82rem;color:var(--ink);background:#fff;outline:none}
    .search-form input{flex:1;min-width:180px}
    .search-form select:focus,.search-form input:focus{border-color:var(--primary)}
    .btn-sm{height:40px;padding:0 14px;border:0;border-radius:12px;background:var(--primary);color:#fff;font:inherit;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
    .btn-reset{background:#efeaf9;color:var(--primary)}

    .list{margin-top:14px;display:flex;flex-direction:column;gap:12px}
    .item{background:#fff;border-radius:18px;padding:16px;box-shadow:var(--shadow)}
    .item-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px}
    .badges{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
    .tag{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#f5f3ff;color:var(--primary);font-size:.74rem;font-weight:600}
    .st{padding:3px 10px;border-radius:999px;font-size:.72rem;font-weight:600}
    .st-new{background:#fef3c7;color:#b45309}
    .st-read{background:#dbeafe;color:#1d4ed8}
    .st-done{background:#d1fae5;color:#047857}

    .meta{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px;font-size:.76rem;color:var(--mute)}
    .sender{font-weight:500;color:var(--ink)}
    .anon-pill{padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#4b5563;font-size:.7rem}
    .stars-ro{color:var(--star);font-size:.82rem;letter-spacing:1px}
    .stars-ro .off{color:#e2e8f0}

    .msg{margin:10px 0 0;font-size:.88rem;line-height:1.6;white-space:pre-line;word-break:break-word}
    .reply-box{margin-top:10px;padding:10px 12px;border-radius:12px;background:#f5f3ff;border-left:3px solid var(--primary);font-size:.8rem;line-height:1.5;white-space:pre-line;word-break:break-word}
    .reply-box b{display:block;margin-bottom:2px;font-size:.72rem;color:var(--primary)}

    .manage-form{margin-top:14px;padding-top:14px;border-top:1px dashed var(--border);display:grid;gap:10px}
    @media(min-width:680px){
      .manage-form{grid-template-columns:190px 1fr auto;align-items:start}
      .manage-actions{grid-column:1 / -1;display:flex;justify-content:flex-end;gap:8px;align-items:center}
    }
    .manage-form select,.manage-form textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:12px;font:inherit;font-size:.82rem;color:var(--ink);background:#fff;outline:none}
    .manage-form textarea{min-height:68px;resize:vertical;line-height:1.45}
    .manage-form select:focus,.manage-form textarea:focus{border-color:var(--primary)}
    .btn-save{height:38px;padding:0 16px;border:0;border-radius:12px;background:var(--primary);color:#fff;font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
    .btn-save:disabled{opacity:.6;cursor:default}
    .fb-note{font-size:.76rem;color:var(--mute)}
    .fb-note.ok{color:#047857;font-weight:500}
    .fb-note.err{color:#b91c1c;font-weight:500}

    .err-box{margin-top:14px;padding:12px 14px;border-radius:14px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:.84rem}
    .empty{margin-top:14px;padding:32px 16px;text-align:center;background:#fff;border-radius:18px;color:var(--faint);font-size:.88rem;box-shadow:var(--shadow)}
  </style>
</head>
<body>
  <header class="top">
    <div class="top-left">
      <a href="main.php" class="back" aria-label="ย้อนกลับ"><i class="fas fa-arrow-left"></i></a>
      <div>
        <h1>จัดการข้อเสนอแนะ (Admin)</h1>
        <p>ตรวจสอบสถานะและตอบกลับข้อเสนอแนะจากนักเรียน</p>
      </div>
    </div>
  </header>

  <main class="wrap">
    <?php if ($load_error !== ''): ?>
      <div class="err-box"><?= e($load_error) ?></div>
    <?php else: ?>
      <section class="stats" aria-label="สรุปภาพรวม">
        <div class="stat">
          <div class="num"><?= e($stats['total']) ?></div>
          <div class="lbl">ทั้งหมด</div>
        </div>
        <div class="stat warn">
          <div class="num" id="statNew"><?= e($stats['new']) ?></div>
          <div class="lbl">รอดำเนินการ</div>
        </div>
        <div class="stat">
          <div class="num" id="statRead"><?= e($stats['read']) ?></div>
          <div class="lbl">รับทราบแล้ว</div>
        </div>
        <div class="stat ok">
          <div class="num" id="statResolved"><?= e($stats['resolved']) ?></div>
          <div class="lbl">แก้ไขแล้ว</div>
        </div>
        <div class="stat star">
          <div class="num"><?= e(number_format($stats['avg_rating'], 1)) ?> ★</div>
          <div class="lbl">คะแนนเฉลี่ย</div>
        </div>
      </section>

      <section class="filter-card">
        <div class="tabs" role="tablist" aria-label="กรองตามสถานะ">
          <?php
          $baseParams = [];
          if ($filterCat !== 'all') $baseParams['category'] = $filterCat;
          if ($search !== '') $baseParams['q'] = $search;
          ?>
          <a class="tab<?= $filterStatus === 'all' ? ' active' : '' ?>" href="?<?= e(http_build_query($baseParams)) ?>">ทั้งหมด</a>
          <?php foreach ($STATUS as $sKey => [$sLabel, $_]): ?>
            <?php $qArr = array_merge($baseParams, ['status' => $sKey]); ?>
            <a class="tab<?= $filterStatus === $sKey ? ' active' : '' ?>" href="?<?= e(http_build_query($qArr)) ?>"><?= e($sLabel) ?></a>
          <?php endforeach; ?>
        </div>

        <form class="search-form" method="get" action="">
          <?php if ($filterStatus !== 'all'): ?>
            <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
          <?php endif; ?>
          <select name="category" aria-label="หมวดหมู่">
            <option value="all">ทุกหมวดหมู่</option>
            <?php foreach ($CATEGORIES as $cKey => [$cLabel, $_]): ?>
              <option value="<?= e($cKey) ?>"<?= $filterCat === $cKey ? ' selected' : '' ?>><?= e($cLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="q" value="<?= e($search) ?>" placeholder="ค้นหาข้อความ รหัส หรือชื่อนักเรียน...">
          <button type="submit" class="btn-sm"><i class="fas fa-search"></i> ค้นหา</button>
          <?php if ($filterStatus !== 'all' || $filterCat !== 'all' || $search !== ''): ?>
            <a href="admin_feedback.php" class="btn-sm btn-reset">ล้างตัวกรอง</a>
          <?php endif; ?>
        </form>
      </section>

      <?php if (!$items): ?>
        <div class="empty">ไม่พบรายการข้อเสนอแนะตามเงื่อนไขที่เลือก</div>
      <?php else: ?>
        <section class="list" id="fbList">
          <?php foreach ($items as $item):
            $id     = (int)$item['id'];
            $catKey = (string)$item['category'];
            [$cLabel, $cIcon] = $CATEGORIES[$catKey] ?? ['ทั่วไป', 'fa-comment-dots'];

            $stKey = (string)$item['status'];
            if (!isset($STATUS[$stKey])) $stKey = 'new';
            [$sLabel, $sClass] = $STATUS[$stKey];

            $rating = max(0, min(5, (int)$item['rating']));
            $isAnon = !empty($item['is_anonymous']);
            $fullName = trim(((string)($item['prefix'] ?? '')) . ((string)($item['first_name'] ?? '')) . ' ' . ((string)($item['last_name'] ?? '')));
            $sCode = (string)($item['student_code'] ?? '');
          ?>
            <article class="item" data-id="<?= $id ?>">
              <div class="item-head">
                <div class="badges">
                  <span class="tag"><i class="fas <?= e($cIcon) ?>"></i> <?= e($cLabel) ?></span>
                  <span class="st <?= e($sClass) ?>" id="badge-<?= $id ?>"><?= e($sLabel) ?></span>
                </div>
                <time><?= e(th_date($item['created_at'] ?? null)) ?></time>
              </div>

              <div class="meta">
                <?php if ($isAnon): ?>
                  <span class="anon-pill"><i class="fas fa-user-secret"></i> ไม่ระบุตัวตน</span>
                  <?php if ($sCode !== ''): ?>
                    <span>รหัส: <?= e($sCode) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="sender"><i class="fas fa-user"></i> <?= e($fullName !== '' ? $fullName : 'ไม่ระบุชื่อ') ?></span>
                  <?php if ($sCode !== ''): ?>
                    <span>(<?= e($sCode) ?>)</span>
                  <?php endif; ?>
                <?php endif; ?>

                <span class="stars-ro" aria-label="<?= $rating ?> ดาว">
                  <?= str_repeat('★', $rating) ?><span class="off"><?= str_repeat('★', 5 - $rating) ?></span>
                </span>
              </div>

              <p class="msg"><?= e($item['message'] ?? '') ?></p>

              <div class="reply-box" id="replyPreview-<?= $id ?>"<?= empty($item['admin_reply']) ? ' hidden' : '' ?>>
                <b><i class="fas fa-reply"></i> ตอบกลับจากผู้ดูแล<span id="replyTime-<?= $id ?>"><?= !empty($item['replied_at']) ? ' · ' . e(th_date($item['replied_at'])) : '' ?></span></b>
                <span id="replyText-<?= $id ?>"><?= e($item['admin_reply'] ?? '') ?></span>
              </div>

              <form class="manage-form" data-id="<?= $id ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div>
                  <select name="status" aria-label="สถานะรายการ #<?= $id ?>">
                    <?php foreach ($STATUS as $k => [$lbl, $_]): ?>
                      <option value="<?= e($k) ?>"<?= $stKey === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <textarea name="admin_reply" maxlength="<?= FB_REPLY_MAX_LEN ?>" placeholder="พิมพ์ข้อความตอบกลับนักเรียน (เว้นว่างหากไม่ตอบกลับ)"><?= e($item['admin_reply'] ?? '') ?></textarea>
                </div>
                <div class="manage-actions">
                  <span class="fb-note" id="note-<?= $id ?>"></span>
                  <button type="submit" class="btn-save"><i class="fas fa-check"></i> บันทึก</button>
                </div>
              </form>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <script>
  (function () {
    var forms = document.querySelectorAll('.manage-form');
    forms.forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var id = form.getAttribute('data-id');
        var btn = form.querySelector('.btn-save');
        var note = document.getElementById('note-' + id);
        if (btn) { btn.disabled = true; }
        if (note) { note.className = 'fb-note'; note.textContent = 'กำลังบันทึก...'; }

        fetch('admin_feedback.php', {
          method: 'POST',
          credentials: 'same-origin',
          body: new FormData(form)
        })
          .then(function (r) {
            return r.json().catch(function () {
              return { success: false, error: 'เกิดข้อผิดพลาดในการอ่านข้อมูลตอบกลับ' };
            });
          })
          .then(function (res) {
            if (!res.success) {
              if (note) { note.className = 'fb-note err'; note.textContent = res.error || 'บันทึกไม่สำเร็จ'; }
              return;
            }
            var badge = document.getElementById('badge-' + id);
            if (badge) {
              badge.textContent = res.status_label;
              badge.className = 'st ' + res.status_class;
            }
            var box = document.getElementById('replyPreview-' + id);
            var rText = document.getElementById('replyText-' + id);
            var rTime = document.getElementById('replyTime-' + id);
            if (box && rText) {
              if (res.admin_reply) {
                rText.textContent = res.admin_reply;
                if (rTime) rTime.textContent = res.replied_at ? (' · ' + res.replied_at) : '';
                box.hidden = false;
              } else {
                rText.textContent = '';
                if (rTime) rTime.textContent = '';
                box.hidden = true;
              }
            }
            if (note) { note.className = 'fb-note ok'; note.textContent = 'บันทึกเรียบร้อยแล้ว'; }
          })
          .catch(function () {
            if (note) { note.className = 'fb-note err'; note.textContent = 'เชื่อมต่อไม่ได้ กรุณาลองใหม่'; }
          })
          .finally(function () {
            if (btn) { btn.disabled = false; }
          });
      });
    });
  })();
  </script>
  <?php mtts_maintenance_watch_js(); ?>
</body>
</html>
