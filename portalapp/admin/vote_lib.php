<?php
/*
 * vote_lib.php — ฟังก์ชันร่วมของ fill.php และ admin_vote_date.php
 * (วางไฟล์นี้ไว้โฟลเดอร์เดียวกับ db.php)
 */
date_default_timezone_set('Asia/Bangkok');
// ตัวคำนวณเวรกลาง (ข้ามคนลาออก) — หาไฟล์ใน admin/ ก่อน แล้วค่อยหาที่ root
foreach ([__DIR__ . '/duty_core.php', __DIR__ . '/../duty_core.php'] as $__duty_core) {
    if (is_file($__duty_core)) { require_once $__duty_core; break; }
}

const START_DATE   = '2026-07-06';  // วันเริ่มต้นคำนวณเวรหลัก
const DUTY_PER_DAY = 16;            // จำนวนผู้อยู่เวรต่อวัน

// คีย์ในตาราง system_settings สำหรับ "วันกลับที่แอดมินกำหนด"
const VOTE_TARGET_KEY    = 'vote_target_date';
const VOTE_TARGET_BY_KEY = 'vote_target_set_by';

function thai_day_name(string $date_str): string
{
    $ts = strtotime($date_str);
    if (!$ts) return '';
    $days = ['วันอาทิตย์', 'วันจันทร์', 'วันอังคาร', 'วันพุธ', 'วันพฤหัสบดี', 'วันศุกร์', 'วันเสาร์'];
    return $days[(int)date('w', $ts)];
}

// ฟังก์ชันแปลงวันที่เป็นภาษาไทยแบบเต็ม
function thai_date_full(string $date_str): string
{
    $timestamp = strtotime($date_str);
    if (!$timestamp) return $date_str;
    $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    $day   = (int)date('j', $timestamp);
    $month = (int)date('n', $timestamp);
    $year  = (int)date('Y', $timestamp) + 543;

    return thai_day_name($date_str) . "ที่ {$day} {$months[$month]} พ.ศ. {$year}";
}

// วันศุกร์ตามปกติ (วันนี้ถ้าเป็นวันศุกร์ ไม่เช่นนั้นคือวันศุกร์ถัดไป)
function friday_target_date(): string
{
    $dt  = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $dow = (int)$dt->format('N');

    $until = 5 - $dow;
    if ($until < 0) {
        $until += 7;
    }
    $dt->modify("+$until days");
    return $dt->format('Y-m-d');
}

// วันกลับที่แอดมินตั้งไว้ — ใช้ได้ถึงสิ้นวันนั้น พ้นแล้วคืนค่า null (กลับเป็นวันศุกร์อัตโนมัติ)
function vote_override(mysqli $conn): ?array
{
    try {
        $res  = $conn->query(
            "SELECT setting_name, setting_value FROM system_settings
              WHERE setting_name IN ('" . VOTE_TARGET_KEY . "', '" . VOTE_TARGET_BY_KEY . "')"
        );
        $vals = [];
        while ($r = $res->fetch_assoc()) {
            $vals[$r['setting_name']] = (string)$r['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('vote_override: ' . $e->getMessage());
        return null;
    }

    $d = $vals[VOTE_TARGET_KEY] ?? '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    if ($d < date('Y-m-d')) {
        return null;
    }
    return ['date' => $d, 'by' => $vals[VOTE_TARGET_BY_KEY] ?? ''];
}

function vote_window(mysqli $conn): array
{
    $ov = vote_override($conn);

    return [
        'target_date' => $ov ? $ov['date'] : friday_target_date(),
        'is_open'     => true, // กำหนดให้เปิดรับตลอดทุกวัน
        'is_override' => $ov !== null,
    ];
}

// รายชื่อผู้อยู่เวรของวันที่กำหนด — ใช้ตัวคำนวณกลางใน duty_core.php (ข้ามคนลาออกตั้งแต่ DUTY_SKIP_RESIGNED_FROM)
function duty_students_for_date(mysqli $conn, string $date): array
{
    $codes = duty_codes_for_date($conn, $date, START_DATE, DUTY_PER_DAY);
    if (!$codes) return [];

    $by_code = [];
    $res = $conn->query('SELECT student_code, prefix, first_name, last_name, section FROM students');
    while ($r = $res->fetch_assoc()) {
        $by_code[(string)$r['student_code']] = $r;
    }

    $out = [];
    foreach ($codes as $code) {
        $r = $by_code[$code] ?? null;
        if ($r === null) continue;
        $out[] = [
            'code'    => (string)$code,
            'name'    => trim(($r['prefix'] ?? '') . ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'section' => (string)($r['section'] ?? ''),
        ];
    }
    return $out;
}
