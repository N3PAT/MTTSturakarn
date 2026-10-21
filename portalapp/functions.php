<?php
date_default_timezone_set('Asia/Bangkok');

if (!isset($conn)) {
    require_once 'db.php';
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ===================== ค่าคงที่ ===================== */
const START_DATE   = '2026-07-06';  // วันเริ่มต้นคำนวณเวรหลัก
const DUTY_PER_DAY = 16;            // จำนวนผู้อยู่เวรต่อวัน
const STATUS_LABELS = [
    'home'     => 'กลับภูมิลำเนา',
    'stay'     => 'ประสงค์อยู่ รร.ชท.',
    'duty'     => 'เวรรักษาการณ์ (วันศุกร์)',
    'restrict' => 'กักบริเวณ',
];

/* ===================== จัดการตารางและค่าระบบ ===================== */
// สร้างตาราง system_settings หากยังไม่มีในระบบ
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

function get_system_setting(mysqli $conn, string $key, string $default = ''): string {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? $res['setting_value'] : $default;
}

function set_system_setting(mysqli $conn, string $key, string $value): void {
    $stmt = $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

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

function thai_date_full(string $date_str): string {
    $timestamp = strtotime($date_str);
    if (!$timestamp) return $date_str;
    $days   = ['วันอาทิตย์', 'วันจันทร์', 'วันอังคาร', 'วันพุธ', 'วันพฤหัสบดี', 'วันศุกร์', 'วันเสาร์'];
    $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    
    $dow   = (int)date('w', $timestamp);
    $day   = (int)date('j', $timestamp);
    $month = (int)date('n', $timestamp);
    $year  = (int)date('Y', $timestamp) + 543;
    
    return "{$days[$dow]}ที่ {$day} {$months[$month]} พ.ศ. {$year}";
}

// ดึงวันเป้าหมายและสถานะจากระบบ Admin (หากไม่มีใน DB จะคำนวณวันศุกร์ถัดไปให้อัตโนมัติ)
function vote_window(mysqli $conn): array
{
    $dt    = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $dow   = (int)$dt->format('N');
    $until = 5 - $dow;
    if ($until < 0) { $until += 7; }
    $target = clone $dt;
    $target->modify("+$until days");
    $default_target = $target->format('Y-m-d');

    $target_date = get_system_setting($conn, 'target_date', $default_target);
    $is_open     = get_system_setting($conn, 'is_open', '1') === '1';

    return [
        'target_date' => $target_date,
        'is_open'     => $is_open,
    ];
}

function student_total(mysqli $conn): int
{
    $key = 'mtts_student_total';
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch($key, $hit);
        if ($hit) { return (int)$v; }
    }
    $n = (int)$conn->query('SELECT COUNT(*) AS c FROM students')->fetch_assoc()['c'];
    if (function_exists('apcu_store')) { apcu_store($key, $n, 60); }
    return $n;
}

function get_student_info(mysqli $conn, string $code, string $target_date): ?array
{
    $stmt = $conn->prepare('SELECT id, prefix, first_name, last_name, section FROM students WHERE student_code = ? LIMIT 1');
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

    // อยู่เวรหรือไม่ คำนวณตามวันที่เป้าหมาย
    $on_duty = false;
    $total   = student_total($conn);
    if ($total > 0) {
        $id = (int)$row['id'];
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM students WHERE id < ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pos = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $diff  = max(0, (int)floor((strtotime($target_date) - strtotime(START_DATE)) / 86400));
        $start = ($diff * DUTY_PER_DAY) % $total;
        $on_duty = ((($pos - $start) % $total) + $total) % $total < DUTY_PER_DAY;
    }

    return [
        'name'       => trim(($row['prefix'] ?? '') . ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'section'    => (string)($row['section'] ?? ''),
        'restricted' => $restricted,
        'on_duty'    => $on_duty,
        'forced'     => $restricted ? 'restrict' : ($on_duty ? 'duty' : null),
    ];
}

function vote_fail(string $msg): array
{
    return ['http' => 200, 'body' => ['ok' => false, 'message' => $msg]];
}

function vote_ok(string $msg, string $name, string $status, string $date): array
{
    return ['http' => 200, 'body' => [
        'ok' => true, 'message' => $msg, 'name' => $name,
        'status_label' => STATUS_LABELS[$status], 'date' => $date,
    ]];
}

function process_vote(mysqli $conn, array $in, string $target_date, bool $is_open): array
{
    $token = (string)($in['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        return vote_fail('เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่');
    }
    if (!$is_open) {
        return vote_fail('ขณะนี้ระบบปิดรับข้อมูลจากผู้ดูแลระบบ');
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

        $date_th = date('d/m/Y', strtotime($target_date));
        $now     = date('Y-m-d H:i:s');

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

            $stmt = $conn->prepare('SELECT status FROM weekly_votes WHERE student_code = ? AND vote_date = ? LIMIT 1');
            $stmt->bind_param('ss', $code, $target_date);
            $stmt->execute();
            $prev = (string)($stmt->get_result()->fetch_assoc()['status'] ?? '');
            $stmt->close();

            if ($prev === $status) {
                return vote_ok("รหัส $code ได้บันทึกข้อมูลประจำวันศุกร์ที่ $date_th ไว้แล้ว", $info['name'], $status, $date_th);
            }
            $was = array_key_exists($prev, STATUS_LABELS) ? STATUS_LABELS[$prev] : $prev;
            return vote_fail("รหัส $code ได้ทำการบันทึกข้อมูลของสัปดาห์นี้ไปแล้ว (สถานะ: $was) ไม่สามารถทำรายการซ้ำได้");
        }

        return vote_ok("บันทึกข้อมูลรหัส $code ประจำวันศุกร์ที่ $date_th เรียบร้อยแล้ว", $info['name'], $status, $date_th);

    } catch (Throwable $e) {
        error_log('fill submit: ' . $e->getMessage());
        return ['http' => 503, 'body' => ['ok' => false, 'retry' => true, 'message' => 'ระบบกำลังรับคำขอจำนวนมาก กรุณาลองใหม่อีกครั้ง']];
    }
}
