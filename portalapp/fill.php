<?php
date_default_timezone_set('Asia/Bangkok');

require 'db.php';                                       // ต้องมี $conn (mysqli)
require 'vote_lib.php';                                 // START_DATE, DUTY_PER_DAY, vote_window() ฯลฯ
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ===================== ตั้งค่า ===================== */
const STATUS_LABELS = [
    'home'     => 'กลับภูมิลำเนา',
    'stay'     => 'ประสงค์อยู่ รร.ชท.',
    'duty'     => 'เวรรักษาการณ์ (วันศุกร์)',
    'restrict' => 'กักบริเวณ',
];

/* ===================== การแก้ไขยอด ===================== */
const VOTE_MAX_EDITS = 3;   // แก้ไขสถานะที่ส่งไปแล้วได้กี่ครั้ง (ไม่รวมการส่งครั้งแรก)

/* ===================== ฟังก์ชันช่วย ===================== */
function json_out(array $data, int $http = 200): void
{
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ป้ายสถานะ — ถ้าไม่ใช่วันศุกร์ ให้ป้ายเวรแสดงชื่อวันของวันที่กำหนดแทน
function status_label(string $status, string $target_date): string
{
    $label = STATUS_LABELS[$status] ?? $status;
    if ($status === 'duty' && date('N', strtotime($target_date)) !== '5') {
        $label = 'เวรรักษาการณ์ (' . thai_day_name($target_date) . ')';
    }
    return $label;
}

// ดึงข้อมูลนักเรียน 1 คน + สถานะบังคับ (กักบริเวณ/เวร) อ้างอิงระบบข้ามคนลาออก
function get_student_info(mysqli $conn, string $code, string $target_date): ?array
{
    $stmt = $conn->prepare('SELECT id, student_code, prefix, first_name, last_name, section, status FROM students WHERE student_code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }

    $stmt = $conn->prepare(
        "SELECT 1 FROM restrictions
          WHERE student_code = ?
            AND (restrict_type = 'restrict' OR restrict_type IS NULL OR restrict_type = '')
            AND ? >= DATE(created_at)
            AND ? <= DATE_ADD(DATE(created_at), INTERVAL weeks_count WEEK)
          LIMIT 1"
    );
    $stmt->bind_param('sss', $code, $target_date, $target_date);
    $stmt->execute();
    $restricted = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    // ตรวจสอบเวรประจำวัน โดยจำลองการวนลูปข้ามคนลาออก (ตั้งแต่วันที่ 2026-10-31 เป็นต้นไป) ให้ตรงกับระบบกลาง
    $on_duty = false;
    $start_date = START_DATE;
    $skip_resigned_from = "2026-10-31";
    $per_day = DUTY_PER_DAY;

    $all_students = [];
    $res_all = $conn->query("SELECT student_code, status FROM students ORDER BY id ASC");
    while ($r_all = $res_all->fetch_assoc()) {
        $all_students[] = $r_all;
    }
    
    $total = count($all_students);
    if ($total > 0 && strtotime($target_date) >= strtotime($start_date)) {
        $p = 0;
        $target_ts = strtotime($target_date);
        $start_ts = strtotime($start_date);
        
        for ($ts = $start_ts; $ts <= $target_ts; $ts += 86400) {
            $date = date('Y-m-d', $ts);
            $slots = [];
            for ($i = 0; $i < $per_day; $i++) {
                $tries = 0;
                while ($tries < $total) {
                    $s = $all_students[$p];
                    $p = ($p + 1) % $total;
                    $tries++;
                    $is_resigned = (($s['status'] ?? 'studying') === 'resigned');
                    if ($is_resigned && $date >= $skip_resigned_from) continue;
                    $slots[] = $s['student_code'];
                    break;
                }
            }
            if ($date === $target_date) {
                if (in_array($code, $slots)) {
                    $on_duty = true;
                }
                break;
            }
        }
    }

    return [
        'name'       => trim(($row['prefix'] ?? '') . ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'section'    => (string)($row['section'] ?? ''),
        'restricted' => $restricted,
        'on_duty'    => $on_duty,
        'forced'     => $restricted ? 'restrict' : ($on_duty ? 'duty' : null),
    ];
}

// เส้นตายการแก้ไข = 12:00 น. วันพุธของสัปดาห์ของวันที่กำหนด (วันพุธล่าสุดก่อนวันที่กำหนด)
function vote_edit_deadline(string $target_date): DateTime
{
    $d    = new DateTime($target_date . ' 12:00:00', new DateTimeZone('Asia/Bangkok'));
    $back = ((int)$d->format('N') - 3 + 7) % 7;   // วันศุกร์ -> ถอยหลัง 2 วัน = วันพุธ
    if ($back === 0) { $back = 7; }
    $d->modify("-$back days");
    return $d;
}

function vote_edit_is_open(string $target_date): bool
{
    return new DateTime('now', new DateTimeZone('Asia/Bangkok')) < vote_edit_deadline($target_date);
}

function vote_edit_deadline_label(string $target_date): string
{
    $d = vote_edit_deadline($target_date);
    return '12:00 น. ' . thai_day_name($d->format('Y-m-d')) . 'ที่ ' . $d->format('d/m/Y');
}

// ต้องมีคอลัมน์ edit_count / updated_at ใน weekly_votes — ถ้ายังไม่มีจะพยายามเพิ่มให้อัตโนมัติ (ครั้งเดียว)
function vote_edit_columns_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        $r = $conn->query("SHOW COLUMNS FROM weekly_votes LIKE 'edit_count'");
        if ($r && $r->num_rows > 0) { return $ready = true; }
        $conn->query("ALTER TABLE weekly_votes
                        ADD COLUMN edit_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        ADD COLUMN updated_at DATETIME NULL DEFAULT NULL");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('vote_edit_columns_ready: ' . $e->getMessage());
        return $ready = false;
    }
}

// ข้อมูลที่ส่งไว้แล้วของรหัสนี้ในวันที่กำหนด (null ถ้ายังไม่เคยส่ง)
function get_existing_vote(mysqli $conn, string $code, string $target_date): ?array
{
    $ready = vote_edit_columns_ready($conn);
    $sql   = $ready
        ? 'SELECT status, edit_count FROM weekly_votes WHERE student_code = ? AND vote_date = ? LIMIT 1'
        : 'SELECT status, 0 AS edit_count FROM weekly_votes WHERE student_code = ? AND vote_date = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $code, $target_date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }

    $status = (string)$row['status'];
    $used   = (int)$row['edit_count'];
    return [
        'status'     => $status,
        'label'      => array_key_exists($status, STATUS_LABELS) ? status_label($status, $target_date) : $status,
        'edits_used' => $used,
        'edits_left' => $ready ? max(0, VOTE_MAX_EDITS - $used) : 0,
    ];
}

function vote_fail(string $msg): array
{
    return ['http' => 200, 'body' => ['ok' => false, 'message' => $msg]];
}

function vote_ok(string $msg, string $name, string $status_label, string $date): array
{
    return ['http' => 200, 'body' => [
        'ok' => true, 'message' => $msg, 'name' => $name,
        'status_label' => $status_label, 'date' => $date,
    ]];
}

function process_vote(mysqli $conn, array $in, string $target_date, bool $is_open): array
{
    $token = (string)($in['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        return vote_fail('เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่');
    }
    if (!$is_open) {
        return vote_fail('หมดเวลาทำรายการ ระบบเปิดให้ส่งข้อมูลเฉพาะวันเสาร์ถึงวันอังคารก่อนเวลา 16:00 น. เท่านั้น');
    }

    $code   = trim((string)($in['student_code'] ?? ''));
    $status = trim((string)($in['status'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $code)) {
        return vote_fail('รูปแบบรหัสประจำตัวไม่ถูกต้อง');
    }
    if (!array_key_exists($status, STATUS_LABELS)) {
        return vote_fail('สถานะไม่ถูกต้องตามที่ระบบกำหนด (กรุณาเลือกสถานะให้ครบถ้วน)');
    }

    try {
        $info = get_student_info($conn, $code, $target_date);
        if (!$info) {
            return vote_fail("ไม่พบรหัส $code ในระบบ");
        }
        
        $section = trim($info['section']);
        if ($section === '') {
            return vote_fail("รหัส $code ยังไม่มีข้อมูลตอนเรียนในระบบ กรุณาติดต่อธุรการ");
        }

        if ($info['restricted'] && $status !== 'restrict') {
            return vote_fail("รหัส $code มีรายชื่อถูกกักบริเวณในสัปดาห์นี้ ต้องเลือกสถานะกักบริเวณเท่านั้น");
        }
        if ($status === 'duty' && !$info['on_duty']) {
            return vote_fail("รหัส $code ไม่ได้มีรายชื่อปฏิบัติหน้าที่เวรรักษาการณ์ในสัปดาห์นี้ ไม่สามารถเลือกสถานะเวรได้");
        }

        $date_th  = date('d/m/Y', strtotime($target_date));
        $day_name = thai_day_name($target_date);
        $now      = date('Y-m-d H:i:s');

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO weekly_votes (student_code, vote_date, status, created_at) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $code, $target_date, $status, $now);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ((int)$e->getCode() !== 1062) { throw $e; }

            // มีข้อมูลของรหัสนี้ในวันที่กำหนดอยู่แล้ว -> ตรวจสิทธิ์แก้ไข
            $existing = get_existing_vote($conn, $code, $target_date);
            if ($existing === null) {
                return ['http' => 503, 'body' => ['ok' => false, 'retry' => true, 'message' => 'ระบบกำลังหนาแน่น กรุณาลองใหม่อีกครั้ง']];
            }
            $prev     = $existing['status'];

            if ($prev === $status) {
                return vote_ok("รหัส $code ได้บันทึกข้อมูลประจำ{$day_name}ที่ $date_th ไว้แล้ว", $info['name'], status_label($status, $target_date), $date_th);
            }
            $was = $existing['label'];

            if (!vote_edit_columns_ready($conn)) {
                return vote_fail("รหัส $code ได้ทำการบันทึกข้อมูลของสัปดาห์นี้ไปแล้ว (สถานะ: $was) ไม่สามารถทำรายการซ้ำได้");
            }
            if (!vote_edit_is_open($target_date)) {
                return vote_fail("หมดเวลาแก้ไขข้อมูล (แก้ไขได้ก่อน " . vote_edit_deadline_label($target_date) . ") สถานะปัจจุบันของรหัส $code คือ $was");
            }
            if ($existing['edits_left'] <= 0) {
                return vote_fail("รหัส $code แก้ไขข้อมูลครบ " . VOTE_MAX_EDITS . " ครั้งแล้ว ไม่สามารถแก้ไขได้อีก (สถานะปัจจุบัน: $was)");
            }

            // อัปเดตแบบ atomic: นับสิทธิ์ใน WHERE ป้องกันกดซ้ำพร้อมกันเกินจำนวนครั้ง
            $now_upd = date('Y-m-d H:i:s');
            $max     = VOTE_MAX_EDITS;
            $stmt = $conn->prepare('UPDATE weekly_votes SET status = ?, edit_count = edit_count + 1, updated_at = ? WHERE student_code = ? AND vote_date = ? AND edit_count < ?');
            $stmt->bind_param('ssssi', $status, $now_upd, $code, $target_date, $max);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed < 1) {
                return vote_fail("รหัส $code ไม่สามารถแก้ไขข้อมูลได้ในขณะนี้ (อาจแก้ไขครบ " . VOTE_MAX_EDITS . " ครั้งแล้ว) กรุณารีเฟรชหน้าเว็บ");
            }
            $left = max(0, $existing['edits_left'] - 1);
            return vote_ok("แก้ไขข้อมูลรหัส $code ประจำ{$day_name}ที่ $date_th จาก \"$was\" เป็น \"" . status_label($status, $target_date) . "\" เรียบร้อยแล้ว (แก้ไขได้อีก $left ครั้ง ก่อน " . vote_edit_deadline_label($target_date) . ")", $info['name'], status_label($status, $target_date), $date_th);
        }

        return vote_ok("บันทึกข้อมูลรหัส $code ประจำ{$day_name}ที่ $date_th เรียบร้อยแล้ว", $info['name'], status_label($status, $target_date), $date_th);

    } catch (Throwable $e) {
        error_log('fill submit: ' . $e->getMessage());
        return ['http' => 503, 'body' => ['ok' => false, 'retry' => true, 'message' => 'ระบบกำลังหนาแน่น กรุณาลองใหม่อีกครั้ง']];
    }
}

/* ===================== API ค้นหานักเรียน ===================== */
if (($_GET['action'] ?? '') === 'get_student_name') {
    $code = trim((string)($_GET['code'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $code)) {
        json_out(['found' => false]);
    }
    try {
        $info = get_student_info($conn, $code, vote_window($conn)['target_date']);
        if (!$info) { json_out(['found' => false]); }
        json_out(['found' => true, 'name' => $info['name'], 'section' => $info['section'], 'forced' => $info['forced'],
                  'existing' => get_existing_vote($conn, $code, vote_window($conn)['target_date'])]);
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
$edit_cfg = [
    'enabled'  => vote_edit_columns_ready($conn),
    'max'      => VOTE_MAX_EDITS,
    'open'     => vote_edit_is_open($target_date),
    'deadline' => vote_edit_deadline_label($target_date),
];
$tiles = [
    'home'     => ['๑', 'กลับภูมิลำเนา', 'home'],
    'stay'     => ['๒', 'ประสงค์อยู่ รร.ชท.', 'pin'],
    'duty'     => ['๓', status_label('duty', $target_date), 'shield'],
    'restrict' => ['๔', 'กักบริเวณ', 'lock'],
];
header('Cache-Control: private, no-cache');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<meta name="theme-color" content="#503896">
<title>แจ้งความประสงค์ - MTTS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<noscript><style>#page-loader{display:none}</style></noscript>
<style>

:root{
  --primary:#503896;--primary-dark:#3d2b78;--ink:#1c1b29;--mute:#6b7280;--paper:#f2f3f8;--line:#e8eaf2;--nav:64px;
  --ok:#15803d;--ok-bg:#f0fdf4;--bad:#b91c1c;--bad-bg:#fef2f2;--warn:#b45309;--warn-bg:#fffbeb;
  --g:16px;--shadow:0 1px 2px rgba(20,20,43,.04),0 2px 8px rgba(20,20,43,.04);
  --safe-t:env(safe-area-inset-top,0px);--safe-b:env(safe-area-inset-bottom,0px);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:'Prompt',system-ui,sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100vh;min-height:100dvh;overscroll-behavior-y:none;-webkit-user-select:none;user-select:none;touch-action:manipulation}
input{-webkit-user-select:text;user-select:text}
.app{width:100%;max-width:430px;background:var(--paper);min-height:100vh;min-height:100dvh;padding-bottom:calc(var(--nav) + var(--safe-b) + 12px)}
@media(min-width:431px){.app{box-shadow:0 0 40px rgba(15,23,42,.14)}}
.ic{width:1.15em;height:1.15em;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;vertical-align:-.2em}

/* แถบหัวแอป */
.top{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:12px;padding:calc(10px + var(--safe-t)) var(--g) 10px;background:rgba(80,56,150,.94);-webkit-backdrop-filter:saturate(180%) blur(14px);backdrop-filter:saturate(180%) blur(14px);color:#fff}
.logo{width:40px;height:40px;border-radius:50%;object-fit:contain;background:#fff;padding:2px}
.top h1{margin:0;font-size:.95rem;font-weight:600;line-height:1.25}
.top p{margin:0;font-size:.72rem;opacity:.8}

/* การ์ดวันที่ (แทน hero) */
.date-card{display:flex;align-items:center;gap:14px;margin:16px var(--g) 0;padding:16px;border-radius:20px;background:#fff;box-shadow:var(--shadow)}
.date-ic{display:grid;place-items:center;flex:none;width:48px;height:48px;border-radius:14px;background:#efeaf9;color:var(--primary);font-size:1.4rem}
.date-card small{display:block;font-size:.75rem;color:var(--mute)}
.date-card strong{display:block;margin:1px 0 2px;font-size:1.1rem;font-weight:600;line-height:1.3}
.date-card .sub{font-size:.75rem;color:var(--mute)}

/* กลุ่มฟอร์ม: การ์ดขาวเรียบ */
.group,.field-card{margin:12px var(--g) 0;border-radius:20px;background:#fff;box-shadow:var(--shadow);min-width:0}
.group{overflow:hidden}
.group .field+.field{border-top:1px solid var(--line)}
.field{padding:16px;margin:0;min-width:0;border:0}
.field-card{padding:16px;border:0}
.field>label,.field-card>legend{display:flex;align-items:center;gap:6px;margin:0 0 10px;padding:0;font-size:.8rem;font-weight:600;color:var(--mute)}
.field>label .ic,.field-card>legend .ic{color:var(--primary)}
.req{color:#ef4444}
.input{width:100%;height:52px;padding:0 16px;border:2px solid transparent;border-radius:14px;background:#eef0f6;font:inherit;font-size:1.05rem;color:var(--ink);outline:none;transition:border-color .15s,background .15s}
#student_code{font-size:1.4rem;font-weight:600;letter-spacing:.12em}
#student_code::placeholder{font-size:1rem;font-weight:400;letter-spacing:0}
.input:focus{border-color:var(--primary);background:#fff}
.input[readonly]{background:#f6f7fb;color:var(--primary);font-weight:600;font-size:1rem;letter-spacing:0;cursor:default}
.hint{display:flex;gap:6px;margin:12px 0 0;font-size:.75rem;color:var(--mute);line-height:1.5}
.hint .ic{color:var(--primary);margin-top:2px}

.lookup{display:flex;align-items:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:12px;font-size:.85rem;font-weight:500}
.lookup[hidden]{display:none}
.lk-ok{background:var(--ok-bg);color:var(--ok)}
.lk-bad{background:var(--bad-bg);color:var(--bad)}
.lk-loading{background:#f1f5f9;color:var(--mute)}
.lookup b{font-weight:600}
.lookup span.sec{margin-left:auto;font-size:.78rem;opacity:.85;white-space:nowrap}

/* ตัวเลือกสถานะ */
.tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.tile{position:relative;display:block}
.tile input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;z-index:1}
.tile-body{display:flex;flex-direction:column;justify-content:space-between;gap:14px;height:100%;min-height:108px;padding:14px;border:2px solid transparent;border-radius:18px;background:#f4f5fa;transition:transform .12s,border-color .15s,background .15s}
.tile input:active + .tile-body{transform:scale(.96)}
.tile-top{display:flex;align-items:flex-start;justify-content:space-between}
.tile-ic{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:var(--c);color:#fff;font-size:1.25rem}
.tile-num{display:grid;place-items:center;width:24px;height:24px;border-radius:50%;border:2px solid #cfd4e0;background:#fff;font-size:.68rem;font-weight:600;color:var(--mute);transition:background .15s,border-color .15s}
.tile-text{font-size:.86rem;font-weight:600;line-height:1.35}
.t-home{--c:#2563eb;--bg:#e3edff}.t-stay{--c:#6d28d9;--bg:#ece6ff}.t-duty{--c:#d97706;--bg:#fff1d6}.t-restrict{--c:#dc2626;--bg:#ffe3e3}
.tile input:checked + .tile-body{border-color:var(--c);background:var(--bg)}
.tile input:checked + .tile-body .tile-num{background:var(--c);border-color:var(--c);font-size:0}
.tile input:checked + .tile-body .tile-num::after{content:'✓';font-size:.85rem;color:#fff}
.tile input:focus-visible + .tile-body{outline:3px solid #c4b5fd;outline-offset:2px}
.tile input:disabled{cursor:not-allowed}
.tile input:disabled + .tile-body{opacity:.4}
.tile input:disabled:checked + .tile-body{opacity:1}

.notice,.help{display:flex;gap:10px;margin:12px var(--g) 0;padding:12px 14px;border-radius:16px;font-size:.8rem;line-height:1.5}
.notice{background:var(--warn-bg);color:var(--warn);border:1px solid #fde68a}
.notice[hidden]{display:none}
.help{background:transparent;color:var(--mute);padding:4px 6px}
.help .ic{font-size:1.15rem;margin-top:2px;color:var(--primary)}
.help strong{color:var(--ink)}

/* ปุ่ม */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:16px;height:52px;padding:0 18px;font:inherit;font-size:1rem;font-weight:600;cursor:pointer;transition:transform .1s,background .15s}
.btn:active{transform:scale(.97)}
.btn:focus-visible,.input:focus-visible,.nav a:focus-visible{outline:3px solid #c4b5fd;outline-offset:2px}
.btn-primary{background:var(--primary);color:#fff;width:100%}
.btn-primary:hover{background:var(--primary-dark)}
.btn-primary:disabled{opacity:.55;cursor:not-allowed}
.btn-ghost{background:#eef0f6;color:var(--mute)}
#submitBtn{position:sticky;bottom:calc(var(--nav) + var(--safe-b) + 12px);z-index:30;display:flex;width:calc(100% - 32px);margin:16px 16px 0;box-shadow:0 6px 16px rgba(80,56,150,.3)}
.spinner{width:1.1em;height:1.1em;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}
.lk-loading .spinner{border-color:#cbd5e1;border-top-color:var(--primary)}
@keyframes spin{to{transform:rotate(360deg)}}

.closed{padding:28px 20px;text-align:center}
.closed .big{display:grid;place-items:center;width:64px;height:64px;margin:0 auto 14px;border-radius:50%;background:#fee2e2;color:#ef4444;font-size:1.8rem}
.closed h2{margin:0 0 6px;font-size:1.1rem;color:#991b1b}
.closed p{margin:0 0 14px;font-size:.85rem;color:var(--mute)}
.closed .when{display:inline-block;padding:8px 14px;border-radius:12px;background:#f4f5fa;font-size:.85rem;font-weight:600;color:#b91c1c}
.closed .btn{margin-top:16px;background:none;color:var(--primary);height:auto;padding:8px}

/* แถบเมนูล่างสีม่วง */
.nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:50;width:100%;max-width:430px;height:calc(var(--nav) + var(--safe-b));padding-bottom:var(--safe-b);display:flex;align-items:center;background:var(--primary);box-shadow:0 -4px 18px rgba(20,20,43,.18)}
.nav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;color:rgba(255,255,255,.65);font-size:.7rem;font-weight:500;text-decoration:none}
.nav a .pill{display:grid;place-items:center;width:56px;height:30px;border-radius:15px;font-size:1.2rem;transition:background .2s}
.nav a:active{color:#fff}
.nav a:active .pill{background:rgba(255,255,255,.2)}

/* กล่องข้อความ — เด้งขึ้นจากด้านล่าง (bottom sheet) */
dialog{border:0;padding:10px 20px calc(18px + var(--safe-b));width:100%;max-width:430px;margin:auto auto 0;border-radius:26px 26px 0 0;background:#fff;text-align:center;color:var(--ink);font-family:inherit;box-shadow:0 -10px 40px rgba(15,23,42,.25)}
dialog::before{content:'';display:block;width:40px;height:5px;margin:0 auto 16px;border-radius:3px;background:#d4d8e2}
dialog[open]{animation:sheet .28s cubic-bezier(.16,1,.3,1)}
dialog::backdrop{background:rgba(15,23,42,.5)}
@keyframes sheet{from{transform:translateY(100%)}}
.dlg-icon{display:grid;place-items:center;width:56px;height:56px;margin:0 auto 12px;border-radius:50%;font-size:1.7rem}
.dlg-icon.success{background:#dcfce7;color:#16a34a}.dlg-icon.error{background:#fee2e2;color:#dc2626}.dlg-icon.warning{background:#fef3c7;color:#d97706}
dialog h3{margin:0 0 8px;font-size:1.1rem}
dialog p{margin:0 0 10px;font-size:.88rem;color:var(--mute);line-height:1.5}
.sum{margin:0 0 12px;padding:10px 14px;border-radius:14px;background:#f4f5fa;text-align:left;font-size:.85rem}
.sum div{display:flex;justify-content:space-between;gap:12px;padding:4px 0}
.sum dt{color:var(--mute)}.sum dd{margin:0;font-weight:600;text-align:right}
.warnbox{margin:0 0 12px;padding:10px 12px;border-radius:14px;background:var(--bad-bg);border:1px solid #fecaca;color:var(--bad);font-size:.8rem;font-weight:600;text-align:left;line-height:1.5}
.dlg-actions{display:flex;gap:10px;margin-top:6px}
.dlg-actions .btn{flex:1;height:48px}
.dlg-actions .btn-primary{width:auto}

/* หน้า splash ครั้งแรก */
#page-loader{position:fixed;inset:0;z-index:9999;background:linear-gradient(160deg,var(--primary),var(--primary-dark));display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;transition:opacity .4s,visibility .4s}
#page-loader.hidden{opacity:0;visibility:hidden}
#page-loader img{width:88px;height:88px;padding:6px;border-radius:22px;background:#fff}
#page-loader .ring{width:32px;height:32px;border:4px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite}

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

  <section class="date-card" aria-label="วันที่ที่แจ้งความประสงค์">
    <div class="date-ic"><svg class="ic"><use href="#i-clip"/></svg></div>
    <div>
      <small><?php echo date('N', strtotime($target_date)) === '5' ? 'แจ้งความประสงค์ · กำหนดวันศุกร์ที่' : 'แจ้งความประสงค์ · กำหนดวันกลับ'; ?></small>
      <strong><?php echo htmlspecialchars($date_th_full); ?></strong>
      <div class="sub">กรุณาตรวจสอบข้อมูลและเลือกสถานะให้ถูกต้อง</div>
    </div>
  </section>

<?php if ($is_voting_open): ?>
  <form id="voteForm" method="post" action="">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

    <div class="group">
      <div class="field">
        <label for="student_code"><svg class="ic"><use href="#i-card"/></svg>รหัสประจำตัว (นชท.) <span class="req">*</span></label>
        <input class="input" type="text" id="student_code" name="student_code" required maxlength="10" inputmode="numeric" autocomplete="off" placeholder="เช่น 151xx">
        <div id="lookup" class="lookup" aria-live="polite" hidden></div>
      </div>

      <!-- ช่องตอนเรียน ดึงข้อมูลอัตโนมัติจากระบบแบบ Readonly -->
      <div class="field">
        <label for="section"><svg class="ic"><use href="#i-layers"/></svg>ตอนเรียน (ดึงอัตโนมัติจากระบบ)</label>
        <input class="input" type="text" id="section" name="section" readonly placeholder="กรอกรหัส นชท. เพื่อดึงข้อมูลอัตโนมัติ">
      </div>
    </div>

    <fieldset class="field-card">
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
    <div id="voteNote" class="notice" role="status" hidden><svg class="ic"><use href="#i-info"/></svg><span></span></div>

    <div class="help">
      <svg class="ic"><use href="#i-headset"/></svg>
      <div><strong>มีปัญหาการใช้งาน?</strong><br>หากพบปัญหาในการกรอกข้อมูล หรือข้อมูลไม่ถูกต้อง กรุณาติดต่อ Line ID: <strong>ntw25020</strong></div>
    </div>

    <button type="submit" id="submitBtn" class="btn btn-primary"><span class="lbl">ส่งข้อมูล</span><svg class="ic"><use href="#i-send"/></svg></button>
  </form>

<?php else: ?>
  <div class="group closed">
    <div class="big"><svg class="ic"><use href="#i-lock"/></svg></div>
    <h2>ระบบปิดรับข้อมูล</h2>
    <p>ระบบเปิดให้ทำรายการเฉพาะช่วงเวลา</p>
    <div class="when">วันเสาร์ - วันอังคาร (ก่อน 16:00 น.)</div><br>
    <button type="button" class="btn" onclick="location.reload()"><svg class="ic"><use href="#i-refresh"/></svg>รีเฟรชหน้าเว็บ</button>
  </div>
<?php endif; ?>

  <nav class="nav" aria-label="เมนูหลัก">
    <a href="main.php"><span class="pill"><svg class="ic"><use href="#i-home"/></svg></span><span>หน้าหลัก</span></a>
    <a href="logout.php"><span class="pill"><svg class="ic"><use href="#i-logout"/></svg></span><span>ออกจากระบบ</span></a>
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

  var EDIT = <?php echo json_encode($edit_cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
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

  var voteNote = $('#voteNote');
  function showExisting(x) {
    if (!x) { voteNote.hidden = true; return; }
    var t = 'ส่งข้อมูลไว้แล้ว: ' + x.label;
    if (!EDIT.enabled) t += ' · ไม่สามารถแก้ไขได้';
    else if (!EDIT.open) t += ' · หมดเวลาแก้ไขแล้ว (แก้ไขได้ก่อน ' + EDIT.deadline + ')';
    else if (x.edits_left <= 0) t += ' · แก้ไขครบ ' + EDIT.max + ' ครั้งแล้ว';
    else t += ' · แก้ไขได้อีก ' + x.edits_left + ' ครั้ง (ก่อน ' + EDIT.deadline + ')';
    voteNote.lastElementChild.textContent = t; voteNote.hidden = false;
  }

  function reset() { state = 'idle'; setLookup(null); applyForced(null, true); secIn.value = ''; showExisting(null); }

  function apply(d) {
    if (!d.found) { state = 'notfound'; setLookup('bad', 'ไม่พบรหัสนี้ในระบบ'); applyForced(null, true); secIn.value = ''; showExisting(null); return; }
    state = 'found'; setLookup('ok', d.name, d.section);
    if (d.section) {
      secIn.value = 'วิชาชีพช่าง ' + d.section;
    } else {
      secIn.value = 'ไม่พบข้อมูลตอนเรียน';
    }
    applyForced(d.forced, false);
    showExisting(d.existing);
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
    return { ok: false, message: 'ส่งข้อมูลไม่สำเร็จ เซิร์ฟเวอร์หนาแน่นหรืออินเทอร์เน็ตมีปัญหา กรุณากดส่งอีกครั้ง' };
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
      lines: ['กรุณาตรวจสอบข้อมูลให้ถูกต้องก่อนกดยืนยัน'].concat(
        (cache[c] && cache[c].existing && EDIT.enabled) ? ['รหัสนี้ส่งข้อมูลไว้แล้ว การส่งครั้งนี้จะนับเป็นการแก้ไข 1 ครั้ง'] : []),
      summary: [['รหัส', c], ['ชื่อ', ($('b', lookupBox) || {}).textContent || '-'], ['ตอน', secIn.value], ['สถานะ', picked ? $('.tile-text', picked.closest('.tile')).textContent : '-']],
      warn: EDIT.enabled
        ? 'ข้อควรระวัง : แก้ไขข้อมูลได้ไม่เกิน ' + EDIT.max + ' ครั้ง และต้องแก้ไขก่อน ' + EDIT.deadline + ' คิดดีๆ ถ้าไม่อยากใส่ชุดจับหมู'
        : 'ข้อควรระวัง : ไม่สามารถแก้ไขข้อมูลภายหลังได้ คิดดีๆ ถ้าไม่อยากใส่ชุดจับหมู',
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
