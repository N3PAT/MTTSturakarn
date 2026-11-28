<?php
/**
 * admin_polls.php — จัดการโหวต (สำหรับแอดมิน)
 *   - สร้างโพล (ข้อเดียว / หลายข้อ / จำกัดจำนวน) + ตั้งเวลาปิดอัตโนมัติ + ส่งแจ้งเตือนถึงนักเรียน
 *   - ดูผลและรายชื่อผู้โหวต (รหัส + ชื่อ) ทุกโพล
 *   - แก้ไขหัวข้อ/คำอธิบาย/เวลาปิด/ชื่อตัวเลือก, ปิด-เปิดโพล, ลบโพล, ส่งออก CSV
 */
date_default_timezone_set('Asia/Bangkok');
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db.php';
require_once 'poll_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ===================== ตั้งค่าแอดมิน (ปรับให้ตรงกับระบบเดิมของคุณ) ===================== */
const ADMIN_LOGIN_URL = 'admin_login.php';   // หน้าล็อกอินแอดมิน (ไปที่นี่ถ้ายังไม่ได้ล็อกอิน)
// ชื่อ key ใน $_SESSION ที่หน้าแอดมินเดิมใช้เก็บสถานะล็อกอิน (เจอตัวไหนที่มีค่า ถือว่าเป็นแอดมิน)
const ADMIN_SESSION_KEYS = ['admin_id', 'admin_username', 'admin_user', 'admin', 'admin_logged_in', 'is_admin'];

function admin_identity(): ?string
{
    foreach (ADMIN_SESSION_KEYS as $k) {
        if (!empty($_SESSION[$k])) {
            $v = $_SESSION[$k];
            return (is_scalar($v) && $v !== true) ? substr((string)$v, 0, 50) : 'admin';
        }
    }
    return null;
}

$admin = admin_identity();
if ($admin === null) {
    header('Location: ' . ADMIN_LOGIN_URL);
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];
$conn->set_charset('utf8mb4');

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function admin_fail(string $m): array { return ['ok' => false, 'message' => $m]; }
function admin_ok(string $m): array   { return ['ok' => true,  'message' => $m]; }

/** แปลงค่าจาก <input type=datetime-local> เป็น 'Y-m-d H:i:s' (ว่าง = null, ผิดรูปแบบ = false) */
function poll_parse_dt(string $v)
{
    $v = trim($v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v);
    if (!$d || $d->format('Y-m-d\TH:i') !== $v) return false;
    return $d->format('Y-m-d H:i:s');
}

/** กัน CSV injection ใน Excel */
function csv_safe(string $s): string
{
    return ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
}

/* ===================== การทำงานของแอดมิน ===================== */
function admin_create(mysqli $conn, array $in, string $by): array
{
    $title = trim((string)($in['title'] ?? ''));
    $desc  = trim((string)($in['description'] ?? ''));
    if ($title === '' || poll_len($title) > 200) return admin_fail('กรุณากรอกหัวข้อโพล (ไม่เกิน 200 ตัวอักษร)');
    if (poll_len($desc) > 500) return admin_fail('คำอธิบายยาวเกิน 500 ตัวอักษร');

    $max = 1;
    if (($in['type'] ?? 'single') === 'multi') {
        $raw = trim((string)($in['max_choices'] ?? ''));
        if ($raw === '' || $raw === '0') {
            $max = 0;
        } else {
            if (!ctype_digit($raw)) return admin_fail('จำนวนที่เลือกได้สูงสุดต้องเป็นตัวเลข');
            $max = (int)$raw;
            if ($max < 2 || $max > 20) return admin_fail('จำนวนที่เลือกได้สูงสุดต้องเป็น 2–20 หรือเว้นว่างเพื่อไม่จำกัด');
        }
    }

    $closes = poll_parse_dt((string)($in['closes_at'] ?? ''));
    if ($closes === false) return admin_fail('รูปแบบเวลาปิดไม่ถูกต้อง');
    if ($closes !== null && strtotime($closes) <= time()) return admin_fail('เวลาปิดต้องเป็นเวลาในอนาคต');

    $opts = [];
    $seen = [];
    foreach ((array)($in['options'] ?? []) as $o) {
        $o = trim((string)$o);
        if ($o === '') continue;
        if (poll_len($o) > 100) return admin_fail('ตัวเลือกยาวเกิน 100 ตัวอักษร');
        $key = function_exists('mb_strtolower') ? mb_strtolower($o, 'UTF-8') : strtolower($o);
        if (isset($seen[$key])) return admin_fail('มีตัวเลือกที่ซ้ำกัน: ' . $o);
        $seen[$key] = true;
        $opts[] = $o;
    }
    if (count($opts) < 2) return admin_fail('ต้องมีตัวเลือกอย่างน้อย 2 ข้อ');
    if (count($opts) > 12) return admin_fail('ใส่ตัวเลือกได้ไม่เกิน 12 ข้อ');

    $desc_db = $desc === '' ? null : $desc;
    $status  = 'open';

    $conn->begin_transaction();
    try {
        $st = $conn->prepare('INSERT INTO polls (title, description, max_choices, status, closes_at, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $st->bind_param('ssisss', $title, $desc_db, $max, $status, $closes, $by);
        $st->execute();
        $poll_id = (int)$conn->insert_id;
        $st->close();

        $st = $conn->prepare('INSERT INTO poll_options (poll_id, label, sort_order) VALUES (?, ?, ?)');
        foreach ($opts as $i => $label) {
            $order = $i + 1;
            $st->bind_param('isi', $poll_id, $label, $order);
            $st->execute();
        }
        $st->close();

        if (!empty($in['notify'])) {
            $nt = 'โหวตหน่อย: ' . $title;
            $nm = 'มีโพลใหม่ให้โหวต เข้าไปร่วมโหวตได้ที่เมนู "โหวตหน่อย"';
            $ty = 'vote';
            $lv = 'info';
            $st = $conn->prepare('INSERT INTO notifications (student_code, title, message, type, alert_level) VALUES (NULL, ?, ?, ?, ?)');
            $st->bind_param('ssss', $nt, $nm, $ty, $lv);
            $st->execute();
            $st->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return admin_ok('สร้างโพลเรียบร้อยแล้ว');
}

function admin_toggle(mysqli $conn, array $in): array
{
    $id  = (int)($in['id'] ?? 0);
    $to  = (($in['to'] ?? '') === 'open') ? 'open' : 'closed';
    $now = date('Y-m-d H:i:s');
    // ถ้าเปิดอีกครั้ง และเวลาปิดเดิมผ่านไปแล้ว ให้ล้างเวลาปิดทิ้ง (ไม่งั้นจะปิดตัวเองทันที)
    $st = $conn->prepare("UPDATE polls SET status = ?, closes_at = IF(? = 'open' AND closes_at IS NOT NULL AND closes_at <= ?, NULL, closes_at) WHERE id = ?");
    $st->bind_param('sssi', $to, $to, $now, $id);
    $st->execute();
    $st->close();
    return admin_ok($to === 'open' ? 'เปิดโพลอีกครั้งแล้ว' : 'ปิดโพลแล้ว');
}

function admin_delete(mysqli $conn, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $conn->begin_transaction();
    try {
        foreach (['poll_votes', 'poll_options', 'polls'] as $tbl) {
            $col = $tbl === 'polls' ? 'id' : 'poll_id';
            $st = $conn->prepare("DELETE FROM $tbl WHERE $col = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $st->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return admin_ok('ลบโพลและผลโหวตทั้งหมดแล้ว');
}

function admin_update(mysqli $conn, array $in): array
{
    $id    = (int)($in['id'] ?? 0);
    $title = trim((string)($in['title'] ?? ''));
    $desc  = trim((string)($in['description'] ?? ''));
    if ($title === '' || poll_len($title) > 200) return admin_fail('กรุณากรอกหัวข้อโพล (ไม่เกิน 200 ตัวอักษร)');
    if (poll_len($desc) > 500) return admin_fail('คำอธิบายยาวเกิน 500 ตัวอักษร');
    $closes = poll_parse_dt((string)($in['closes_at'] ?? ''));
    if ($closes === false) return admin_fail('รูปแบบเวลาปิดไม่ถูกต้อง');

    $labels = [];
    foreach ((array)($in['labels'] ?? []) as $oid => $label) {
        $label = trim((string)$label);
        if ($label === '' || poll_len($label) > 100) return admin_fail('ชื่อตัวเลือกต้องไม่ว่างและไม่เกิน 100 ตัวอักษร');
        $labels[(int)$oid] = $label;
    }

    $desc_db = $desc === '' ? null : $desc;
    $conn->begin_transaction();
    try {
        $st = $conn->prepare('UPDATE polls SET title = ?, description = ?, closes_at = ? WHERE id = ?');
        $st->bind_param('sssi', $title, $desc_db, $closes, $id);
        $st->execute();
        $st->close();

        $st = $conn->prepare('UPDATE poll_options SET label = ? WHERE id = ? AND poll_id = ?');
        foreach ($labels as $oid => $label) {
            $st->bind_param('sii', $label, $oid, $id);
            $st->execute();
        }
        $st->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return admin_ok('บันทึกการแก้ไขแล้ว');
}

function admin_post(mysqli $conn, array $in, string $by): array
{
    $tok = (string)($in['csrf_token'] ?? '');
    if ($tok === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $tok)) {
        return admin_fail('เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วลองใหม่');
    }
    try {
        switch ((string)($in['action'] ?? '')) {
            case 'create': return admin_create($conn, $in, $by);
            case 'toggle': return admin_toggle($conn, $in);
            case 'delete': return admin_delete($conn, $in);
            case 'update': return admin_update($conn, $in);
        }
    } catch (Throwable $e) {
        error_log('admin_polls: ' . $e->getMessage());
        return admin_fail('ระบบขัดข้อง ทำรายการไม่สำเร็จ (ถ้าเพิ่งติดตั้ง ให้ตรวจว่ารัน poll_setup.sql แล้ว)');
    }
    return admin_fail('คำสั่งไม่ถูกต้อง');
}

/* ===================== ส่งออก CSV ===================== */
if (($_GET['action'] ?? '') === 'export') {
    $id = (int)($_GET['id'] ?? 0);
    try {
        $st = $conn->prepare('SELECT title FROM polls WHERE id = ? LIMIT 1');
        $st->bind_param('i', $id);
        $st->execute();
        $p = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$p) { http_response_code(404); exit('ไม่พบโพล'); }

        $st = $conn->prepare(
            'SELECT o.label, v.student_code, s.prefix, s.first_name, s.last_name, v.created_at
               FROM poll_votes v
               JOIN poll_options o ON o.id = v.option_id
               LEFT JOIN students s ON s.student_code = v.student_code
              WHERE v.poll_id = ?
              ORDER BY o.sort_order, o.id, v.created_at'
        );
        $st->bind_param('i', $id);
        $st->execute();
        $rs = $st->get_result();

        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="poll_' . $id . '_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF"; // BOM ให้ Excel อ่านภาษาไทยถูก
        $out = fopen('php://output', 'w');
        fputcsv($out, ['โพล', 'ตัวเลือก', 'รหัสนักเรียน', 'ชื่อ-สกุล', 'เวลาโหวต'], ',', '"', '\\');
        while ($r = $rs->fetch_assoc()) {
            $name = trim(($r['prefix'] ?? '') . ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            fputcsv($out, [csv_safe((string)$p['title']), csv_safe((string)$r['label']), csv_safe((string)$r['student_code']), csv_safe($name), $r['created_at']], ',', '"', '\\');
        }
        fclose($out);
    } catch (Throwable $e) {
        error_log('admin_polls export: ' . $e->getMessage());
        http_response_code(500);
        echo 'ส่งออกไม่สำเร็จ';
    }
    exit;
}

/* ===================== รับฟอร์ม (POST -> redirect กันส่งซ้ำ) ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['flash_poll'] = admin_post($conn, $_POST, $admin);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$flash = $_SESSION['flash_poll'] ?? null;
unset($_SESSION['flash_poll']);

/* ===================== ข้อมูลสำหรับแสดงผล ===================== */
$polls = [];
$voters = [];   // [poll_id][option_id][] = ['code','name','at']
$load_error = false;
try {
    $polls = poll_fetch($conn, null, null, 100);
    if ($polls) {
        $in = implode(',', array_map(function ($p) { return (int)$p['id']; }, $polls));
        $rs = $conn->query(
            "SELECT v.poll_id, v.option_id, v.student_code, v.created_at, s.prefix, s.first_name, s.last_name
               FROM poll_votes v
               LEFT JOIN students s ON s.student_code = v.student_code
              WHERE v.poll_id IN ($in)
              ORDER BY v.created_at"
        );
        while ($r = $rs->fetch_assoc()) {
            $voters[(int)$r['poll_id']][(int)$r['option_id']][] = [
                'code' => (string)$r['student_code'],
                'name' => trim(($r['prefix'] ?? '') . ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'at'   => poll_thai_datetime($r['created_at']),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('admin_polls load: ' . $e->getMessage());
    $load_error = true;
}
$n_open = 0;
foreach ($polls as $p) { if ($p['is_open']) $n_open++; }
header('Cache-Control: no-cache, no-store, must-revalidate');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#503896">
  <title>จัดการโหวต - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    :root{--primary:#503896;--primary-dark:#3d2b78;--bg:#f2f3f8;--ink:#1c1b29;--mute:#6b7280;--line:#eef0f5;--ok:#15803d;--bad:#b91c1c;--g:16px;--st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);--shadow:0 1px 2px rgba(20,20,43,.04),0 2px 8px rgba(20,20,43,.04)}
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;margin:0;padding:0}
    html{-webkit-text-size-adjust:100%}
    body{font-family:'Prompt',sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100vh;-webkit-font-smoothing:antialiased}
    .app{width:100%;max-width:680px;min-height:100vh;background:var(--bg);padding-bottom:calc(32px + var(--sb))}
    @media(min-width:681px){.app{box-shadow:0 0 40px rgba(15,23,42,.14)}}

    .top{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:12px;padding:calc(10px + var(--st)) var(--g) 10px;background:rgba(80,56,150,.94);-webkit-backdrop-filter:saturate(180%) blur(14px);backdrop-filter:saturate(180%) blur(14px);color:#fff}
    .back{display:flex;align-items:center;justify-content:center;flex:none;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.05rem;text-decoration:none}
    .top h1{font-size:1.05rem;font-weight:600;line-height:1.25}
    .top p{font-size:.72rem;opacity:.8}

    .label{margin:20px calc(var(--g) + 4px) 8px;font-size:.8rem;font-weight:600;letter-spacing:.02em;color:var(--mute)}
    .card{margin:0 var(--g) 14px;padding:16px;border-radius:20px;background:#fff;box-shadow:var(--shadow)}

    .flash{display:flex;align-items:flex-start;gap:10px;margin:16px var(--g) 0;padding:13px 14px;border-radius:16px;font-size:.88rem;font-weight:500;line-height:1.45}
    .flash.ok{background:#dcfce7;color:#166534}
    .flash.bad{background:#fee2e2;color:#991b1b}

    /* ฟอร์ม */
    .field{margin-bottom:14px}
    .field>label,.field>.lbl{display:block;margin-bottom:6px;font-size:.78rem;font-weight:600;color:var(--mute)}
    .input,textarea.input,select.input{width:100%;min-height:46px;padding:0 14px;border:2px solid transparent;border-radius:12px;background:#eef0f6;font-family:inherit;font-size:.95rem;color:var(--ink);outline:none;transition:border-color .15s,background .15s}
    textarea.input{padding:10px 14px;min-height:72px;resize:vertical;line-height:1.5}
    .input:focus{border-color:var(--primary);background:#fff}
    .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:480px){.row2{grid-template-columns:1fr}}
    .seg{display:flex;gap:8px}
    .seg label{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:11px 8px;border:2px solid transparent;border-radius:12px;background:#eef0f6;font-size:.85rem;font-weight:500;color:var(--ink);cursor:pointer;transition:.15s}
    .seg input{display:none}
    .seg label:has(input:checked){border-color:var(--primary);background:#efeaf9;color:var(--primary);font-weight:600}
    .opt-row{display:flex;gap:8px;margin-bottom:8px}
    .opt-row .input{flex:1}
    .icon-btn{flex:none;width:46px;height:46px;border:0;border-radius:12px;background:#fee2e2;color:var(--bad);font-size:.95rem;cursor:pointer}
    .icon-btn:disabled{opacity:.35;cursor:not-allowed}
    .check{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:.88rem;cursor:pointer}
    .check input{width:18px;height:18px;accent-color:var(--primary)}
    .hint{margin-top:6px;font-size:.74rem;color:var(--mute);line-height:1.5}

    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:0 18px;border:0;border-radius:12px;font-family:inherit;font-size:.9rem;font-weight:600;cursor:pointer;text-decoration:none;transition:transform .1s,background .15s}
    .btn:active{transform:scale(.97)}
    .btn-primary{background:var(--primary);color:#fff}
    .btn-primary:hover{background:var(--primary-dark)}
    .btn-block{width:100%}
    .btn-soft{background:#efeaf9;color:var(--primary)}
    .btn-gray{background:#eef0f6;color:var(--ink)}
    .btn-danger{background:#fee2e2;color:var(--bad)}
    .btn-sm{min-height:38px;padding:0 14px;font-size:.8rem}
    .add-link{display:inline-flex;align-items:center;gap:6px;margin-top:2px;padding:8px 0;border:0;background:none;color:var(--primary);font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer}

    /* การ์ดโพล */
    .p-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .p-head h3{font-size:1.02rem;font-weight:600;line-height:1.35;word-break:break-word}
    .badge{flex:none;padding:3px 11px;border-radius:20px;font-size:.7rem;font-weight:600}
    .b-open{background:#dcfce7;color:#166534}
    .b-closed{background:#f1f2f8;color:var(--mute)}
    .desc{margin-top:4px;font-size:.82rem;color:var(--mute);line-height:1.5}
    .chips{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 14px}
    .chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;background:#efeaf9;color:var(--primary);font-size:.7rem;font-weight:500}
    .chip.gray{background:#f1f2f8;color:var(--mute)}

    .res{display:flex;flex-direction:column;gap:12px}
    .res-top{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:5px}
    .res-name{font-size:.88rem;font-weight:500;line-height:1.35;word-break:break-word}
    .res-num{flex:none;font-size:.78rem;color:var(--mute)}
    .res-num b{color:var(--ink);font-weight:700}
    .track{height:10px;border-radius:6px;background:#eceef6;overflow:hidden}
    .fill{height:100%;border-radius:6px;background:#b8a9e0}
    .lead .fill{background:var(--primary)}
    .res-foot{margin-top:12px;font-size:.74rem;color:var(--mute)}

    .actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}
    .actions form{display:inline}

    details.voters{margin-top:12px;border-radius:14px;background:#f6f7fb}
    details.voters summary{padding:12px 14px;font-size:.85rem;font-weight:600;color:var(--primary);cursor:pointer;list-style:none}
    details.voters summary::-webkit-details-marker{display:none}
    .v-body{padding:0 14px 12px}
    .v-opt{margin-top:10px;font-size:.8rem;font-weight:600}
    .v-opt span{color:var(--mute);font-weight:400}
    .v-list{margin-top:4px;list-style:none}
    .v-list li{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-top:1px solid #eceef6;font-size:.8rem}
    .v-list .who b{font-weight:600;margin-right:6px}
    .v-list .when{flex:none;color:var(--mute);font-size:.72rem}
    .v-none{font-size:.78rem;color:var(--mute);padding:4px 0}

    .empty{margin:0 var(--g);padding:34px 20px;border-radius:20px;background:#fff;box-shadow:var(--shadow);text-align:center;color:var(--mute);font-size:.88rem}
    .empty i{display:block;margin-bottom:10px;font-size:2.2rem;color:#c3c7d4}

    dialog{width:calc(100% - 32px);max-width:480px;max-height:90vh;margin:auto;padding:20px;border:0;border-radius:22px;background:#fff;color:var(--ink);font-family:inherit;box-shadow:0 20px 50px rgba(15,23,42,.3);overflow-y:auto}
    dialog::backdrop{background:rgba(15,23,42,.5)}
    dialog h3{margin-bottom:14px;font-size:1.05rem}
    .dlg-actions{display:flex;gap:10px;margin-top:6px}
    .dlg-actions .btn{flex:1}
  </style>
</head>
<body>
<div class="app">

  <header class="top">
    <a href="javascript:history.back()" class="back" aria-label="ย้อนกลับ"><i class="fas fa-chevron-left"></i></a>
    <div>
      <h1>จัดการโหวต</h1>
      <p>โพลทั้งหมด <?= count($polls) ?> รายการ · เปิดอยู่ <?= $n_open ?></p>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="flash <?= !empty($flash['ok']) ? 'ok' : 'bad' ?>">
      <i class="fas <?= !empty($flash['ok']) ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
      <span><?= h($flash['message'] ?? '') ?></span>
    </div>
  <?php endif; ?>

  <!-- สร้างโพลใหม่ -->
  <div class="label">สร้างโพลใหม่</div>
  <form class="card" method="post" action="" id="createForm">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="create">

    <div class="field">
      <label for="c_title">หัวข้อโพล <span style="color:#ef4444">*</span></label>
      <input class="input" type="text" id="c_title" name="title" maxlength="200" required placeholder="เช่น วันเสาร์นี้อยากกินอะไรเป็นมื้อเย็น">
    </div>

    <div class="field">
      <label for="c_desc">คำอธิบาย (ไม่บังคับ)</label>
      <textarea class="input" id="c_desc" name="description" maxlength="500" placeholder="รายละเอียดเพิ่มเติมให้นักเรียนอ่านก่อนโหวต"></textarea>
    </div>

    <div class="field">
      <span class="lbl">รูปแบบการเลือก</span>
      <div class="seg">
        <label><input type="radio" name="type" value="single" checked> เลือกได้ข้อเดียว</label>
        <label><input type="radio" name="type" value="multi"> เลือกได้หลายข้อ</label>
      </div>
      <div id="maxWrap" style="display:none;margin-top:10px">
        <label for="c_max" style="display:block;margin-bottom:6px;font-size:.78rem;font-weight:600;color:var(--mute)">เลือกได้สูงสุดกี่ข้อ</label>
        <input class="input" type="number" id="c_max" name="max_choices" min="2" max="20" inputmode="numeric" placeholder="เว้นว่าง = ไม่จำกัด">
      </div>
    </div>

    <div class="field">
      <span class="lbl">ตัวเลือก (2–12 ข้อ)</span>
      <div id="optList"></div>
      <button type="button" class="add-link" id="addOpt"><i class="fas fa-plus"></i> เพิ่มตัวเลือก</button>
    </div>

    <div class="field">
      <label for="c_close">ปิดโหวตอัตโนมัติ (ไม่บังคับ)</label>
      <input class="input" type="datetime-local" id="c_close" name="closes_at">
      <p class="hint">เว้นว่างไว้ = เปิดจนกว่าแอดมินจะกดปิดเอง</p>
    </div>

    <label class="check"><input type="checkbox" name="notify" value="1" checked> ส่งแจ้งเตือนถึงนักเรียนทุกคน</label>

    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus"></i> สร้างโพล</button>
  </form>

  <!-- รายการโพล -->
  <div class="label">รายการโพล</div>

  <?php if ($load_error): ?>
    <div class="empty"><i class="fas fa-triangle-exclamation"></i>โหลดข้อมูลไม่ได้ — ตรวจว่ารันไฟล์ <b>poll_setup.sql</b> ในฐานข้อมูลแล้ว</div>
  <?php elseif (!$polls): ?>
    <div class="empty"><i class="fas fa-square-poll-vertical"></i>ยังไม่มีโพล สร้างโพลแรกได้จากฟอร์มด้านบน</div>
  <?php endif; ?>

  <?php foreach ($polls as $p):
      $pid   = (int)$p['id'];
      $max_c = 0;
      foreach ($p['options'] as $o) { if ($o['count'] > $max_c) $max_c = $o['count']; }
      $expired = (!$p['is_open'] && $p['status'] === 'open');
      $edit_data = [
          'id'          => $pid,
          'title'       => $p['title'],
          'description' => $p['description'],
          'closes'      => $p['closes_at'] ? date('Y-m-d\TH:i', strtotime((string)$p['closes_at'])) : '',
          'options'     => array_map(function ($o) { return ['id' => $o['id'], 'label' => $o['label']]; }, $p['options']),
      ];
  ?>
  <article class="card">
    <div class="p-head">
      <h3><?= h($p['title']) ?></h3>
      <span class="badge <?= $p['is_open'] ? 'b-open' : 'b-closed' ?>"><?= $p['is_open'] ? 'เปิดอยู่' : ($expired ? 'หมดเวลา' : 'ปิดแล้ว') ?></span>
    </div>
    <?php if ($p['description'] !== ''): ?><p class="desc"><?= h($p['description']) ?></p><?php endif; ?>

    <div class="chips">
      <span class="chip"><i class="fas fa-list-check"></i><?= h(poll_choice_text($p['max_choices'])) ?></span>
      <span class="chip"><i class="fas fa-users"></i><?= (int)$p['total'] ?> คนโหวต</span>
      <?php if ($p['closes_label'] !== ''): ?><span class="chip gray"><i class="far fa-clock"></i>ปิด <?= h($p['closes_label']) ?></span><?php endif; ?>
      <span class="chip gray"><i class="far fa-calendar"></i>สร้าง <?= h($p['created_label']) ?></span>
    </div>

    <div class="res">
      <?php foreach ($p['options'] as $o):
          $pct = poll_pct((int)$o['count'], (int)$p['total']); ?>
        <div class="<?= ($max_c > 0 && $o['count'] === $max_c) ? 'lead' : '' ?>">
          <div class="res-top">
            <div class="res-name"><?= h($o['label']) ?></div>
            <div class="res-num"><b><?= (int)$o['count'] ?></b> โหวต · <?= $pct ?>%</div>
          </div>
          <div class="track"><div class="fill" style="width:<?= $pct ?>%"></div></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($p['max_choices'] !== 1): ?><p class="res-foot">เปอร์เซ็นต์คิดจากจำนวนคนที่โหวต</p><?php endif; ?>

    <details class="voters">
      <summary><i class="fas fa-user-check"></i> ดูรายชื่อผู้โหวต (<?= (int)$p['total'] ?> คน)</summary>
      <div class="v-body">
        <?php foreach ($p['options'] as $o):
            $list = $voters[$pid][(int)$o['id']] ?? []; ?>
          <div class="v-opt"><?= h($o['label']) ?> <span>(<?= count($list) ?>)</span></div>
          <?php if (!$list): ?>
            <div class="v-none">ยังไม่มีผู้เลือก</div>
          <?php else: ?>
            <ul class="v-list">
              <?php foreach ($list as $v): ?>
                <li><span class="who"><b><?= h($v['code']) ?></b><?= h($v['name']) ?></span><span class="when"><?= h($v['at']) ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </details>

    <div class="actions">
      <a class="btn btn-soft btn-sm" href="?action=export&amp;id=<?= $pid ?>"><i class="fas fa-file-csv"></i> ส่งออก CSV</a>
      <button type="button" class="btn btn-gray btn-sm js-edit" data-poll="<?= h(json_encode($edit_data, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i> แก้ไข</button>

      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= $pid ?>">
        <?php if ($p['is_open']): ?>
          <input type="hidden" name="to" value="closed">
          <button type="submit" class="btn btn-gray btn-sm js-confirm" data-msg="ปิดโพลนี้ใช่ไหม? นักเรียนจะโหวตเพิ่มไม่ได้"><i class="fas fa-lock"></i> ปิดโพล</button>
        <?php else: ?>
          <input type="hidden" name="to" value="open">
          <button type="submit" class="btn btn-gray btn-sm"><i class="fas fa-lock-open"></i> เปิดอีกครั้ง</button>
        <?php endif; ?>
      </form>

      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $pid ?>">
        <button type="submit" class="btn btn-danger btn-sm js-confirm" data-msg="ลบโพลนี้พร้อมผลโหวตทั้งหมด? ย้อนกลับไม่ได้"><i class="fas fa-trash"></i> ลบ</button>
      </form>
    </div>
  </article>
  <?php endforeach; ?>

</div>

<!-- แก้ไขโพล -->
<dialog id="editDlg">
  <h3>แก้ไขโพล</h3>
  <form method="post" action="" id="editForm">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="e_id">

    <div class="field">
      <label for="e_title">หัวข้อโพล</label>
      <input class="input" type="text" id="e_title" name="title" maxlength="200" required>
    </div>
    <div class="field">
      <label for="e_desc">คำอธิบาย</label>
      <textarea class="input" id="e_desc" name="description" maxlength="500"></textarea>
    </div>
    <div class="field">
      <label for="e_close">ปิดโหวตอัตโนมัติ</label>
      <input class="input" type="datetime-local" id="e_close" name="closes_at">
      <p class="hint">ล้างช่องนี้ = ไม่กำหนดเวลาปิด</p>
    </div>
    <div class="field">
      <span class="lbl">ชื่อตัวเลือก (แก้ไขชื่อได้ ไม่เพิ่ม/ลบข้อ เพื่อไม่ให้ผลโหวตเพี้ยน)</span>
      <div id="e_opts"></div>
    </div>
    <div class="dlg-actions">
      <button type="button" class="btn btn-gray" id="e_cancel">ยกเลิก</button>
      <button type="submit" class="btn btn-primary">บันทึก</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }

  /* ---- ฟอร์มสร้างโพล: ตัวเลือกแบบเพิ่ม/ลบได้ ---- */
  var list = $('optList'), addBtn = $('addOpt'), MAX_OPTS = 12, MIN_OPTS = 2;

  function refreshRemove() {
    var rows = list.querySelectorAll('.opt-row');
    rows.forEach(function (r) { r.querySelector('.icon-btn').disabled = rows.length <= MIN_OPTS; });
    addBtn.style.display = rows.length >= MAX_OPTS ? 'none' : '';
  }
  function addRow(value) {
    var row = document.createElement('div');
    row.className = 'opt-row';
    var inp = document.createElement('input');
    inp.className = 'input';
    inp.type = 'text';
    inp.name = 'options[]';
    inp.maxLength = 100;
    inp.placeholder = 'ตัวเลือกที่ ' + (list.children.length + 1);
    inp.value = value || '';
    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'icon-btn';
    del.setAttribute('aria-label', 'ลบตัวเลือก');
    del.innerHTML = '<i class="fas fa-xmark"></i>';
    del.addEventListener('click', function () { row.remove(); refreshRemove(); });
    row.appendChild(inp);
    row.appendChild(del);
    list.appendChild(row);
    refreshRemove();
  }
  addRow(); addRow();
  addBtn.addEventListener('click', function () { addRow(); list.lastChild.querySelector('input').focus(); });

  document.querySelectorAll('input[name=type]').forEach(function (r) {
    r.addEventListener('change', function () {
      $('maxWrap').style.display = (document.querySelector('input[name=type]:checked').value === 'multi') ? 'block' : 'none';
    });
  });

  /* ---- ยืนยันก่อนปิด/ลบ ---- */
  document.querySelectorAll('.js-confirm').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!confirm(b.dataset.msg)) e.preventDefault(); });
  });

  /* ---- แก้ไขโพล ---- */
  var dlg = $('editDlg');
  document.querySelectorAll('.js-edit').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = JSON.parse(b.dataset.poll);
      $('e_id').value = d.id;
      $('e_title').value = d.title;
      $('e_desc').value = d.description;
      $('e_close').value = d.closes;
      var box = $('e_opts');
      box.replaceChildren();
      d.options.forEach(function (o, i) {
        var inp = document.createElement('input');
        inp.className = 'input';
        inp.style.marginBottom = '8px';
        inp.type = 'text';
        inp.name = 'labels[' + o.id + ']';
        inp.maxLength = 100;
        inp.required = true;
        inp.value = o.label;
        box.appendChild(inp);
      });
      dlg.showModal();
    });
  });
  $('e_cancel').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
})();
</script>
</body>
</html>
