<?php
/*
 * duty_core.php — ตัวคำนวณเวรกลาง (ใช้ร่วมกันทุกหน้า เพื่อให้ผลตรงกันเสมอ)
 *
 * หลักการ: ตัวชี้ลำดับ $p วิ่งต่อเนื่องในรายชื่อเต็ม (เรียงตาม id รวมคนลาออก)
 *   - ก่อนวันที่ DUTY_SKIP_RESIGNED_FROM ใช้ลำดับเดิมทั้งหมด (เวรย้อนหลังไม่เปลี่ยน)
 *   - ตั้งแต่วันนั้นเป็นต้นไป ข้ามคนที่ status = 'resigned'
 * ต้องให้ผลเหมือน buildDutySchedule() ใน duty.php ทุกประการ
 *
 * วางไฟล์นี้ไว้โฟลเดอร์เดียวกับ db.php (มีสำเนาทั้งใน root และ admin/)
 */
if (!defined('DUTY_SKIP_RESIGNED_FROM')) {
    define('DUTY_SKIP_RESIGNED_FROM', '2026-10-31');
}
if (!defined('DUTY_CORE_START_DATE')) {
    define('DUTY_CORE_START_DATE', '2026-07-06');
}
if (!defined('DUTY_CORE_PER_DAY')) {
    define('DUTY_CORE_PER_DAY', 16);
}

if (!function_exists('duty_build_schedule')) {
    /**
     * @param array  $students  แถวนักเรียนเรียงตาม id ASC แต่ละแถวมี student_code และ status
     * @return array  ['Y-m-d' => [student_code, ...]]
     */
    function duty_build_schedule(array $students, string $start_date, string $end_date,
                                 string $skip_from = DUTY_SKIP_RESIGNED_FROM,
                                 int $per_day = DUTY_CORE_PER_DAY): array
    {
        $schedule = [];
        $total = count($students);
        if ($total === 0) return $schedule;

        $p = 0;
        $end_ts = strtotime($end_date);
        for ($ts = strtotime($start_date); $ts <= $end_ts; $ts += 86400) {
            $date  = date('Y-m-d', $ts);
            $slots = [];
            for ($i = 0; $i < $per_day; $i++) {
                $tries = 0;
                while ($tries < $total) {
                    $s = $students[$p];
                    $p = ($p + 1) % $total;
                    $tries++;
                    $is_resigned = (($s['status'] ?? 'studying') === 'resigned');
                    if ($is_resigned && $date >= $skip_from) continue;
                    $slots[] = (string)$s['student_code'];
                    break;
                }
            }
            $schedule[$date] = $slots;
        }
        return $schedule;
    }
}

if (!function_exists('duty_codes_for_date')) {
    /** รหัสนักเรียนที่อยู่เวรในวันที่กำหนด (ว่างถ้าก่อนวันเริ่มหมุนเวร) */
    function duty_codes_for_date(mysqli $conn, string $date,
                                 string $start_date = DUTY_CORE_START_DATE,
                                 int $per_day = DUTY_CORE_PER_DAY): array
    {
        static $cache = [];
        if (strtotime($date) < strtotime($start_date)) return [];

        $key = $start_date . '|' . $per_day;
        if (!isset($cache[$key]) || !isset($cache[$key][$date])) {
            $students = [];
            $res = $conn->query('SELECT student_code, status FROM students ORDER BY id ASC');
            while ($r = $res->fetch_assoc()) $students[] = $r;
            $cache[$key] = duty_build_schedule($students, $start_date, $date, DUTY_SKIP_RESIGNED_FROM, $per_day);
        }
        return $cache[$key][$date] ?? [];
    }
}
