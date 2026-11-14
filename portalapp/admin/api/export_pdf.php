<?php
date_default_timezone_set('Asia/Bangkok');
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    die("Unauthorized Access");
}

$target_month = $_GET['month'] ?? date('Y-m');

// 1. ดึงข้อมูลการตั้งค่า
$settings_query = $conn->query("SELECT * FROM duty_settings LIMIT 1");
$settings = $settings_query ? $settings_query->fetch_assoc() : null;
$start_date = $settings['start_date'] ?? '2026-07-06';
$skip_resigned_from = "2026-10-31"; // วันที่เริ่มใช้งานเงื่อนไขข้ามคนลาออก
$per_day = 16; // 4 ผลัด x 4 จุด = 16 คน

// 2. ดึงข้อมูลนักเรียนทั้งหมด (เรียงตาม id เพื่อรักษารักษารอบเวร และดึง status มาตรวจสอบ)
$all_students = [];
$student_names = [];
$res = $conn->query("
    SELECT student_code, first_name, last_name, status 
    FROM students 
    WHERE student_code COLLATE utf8mb4_unicode_ci NOT IN (
        SELECT student_code COLLATE utf8mb4_unicode_ci FROM duty_exemptions WHERE is_active = 1
    ) 
    ORDER BY id ASC
");
while ($row = $res->fetch_assoc()) {
    $all_students[] = $row;
    $student_names[$row['student_code']] = $row['first_name']; // ใช้ชื่อจริงตามแบบฟอร์ม
}
$total_students = count($all_students);

// ดึงข้อมูล Overrides (การสลับ/แทนเวร)
$overrides = [];
$res_override = $conn->query("SELECT target_date, original_student_code, new_student_code FROM duty_overrides WHERE target_date LIKE '{$target_month}%'");
while ($row = $res_override->fetch_assoc()) {
    $overrides[$row['target_date']][$row['original_student_code']] = $row['new_student_code'];
}

// 3. ฟังก์ชันคำนวณตารางเวรรายวันแบบข้ามคนลาออก (อิงตามระบบหลัก)
function buildMonthlyRoster(array $students, string $start_date, string $target_month, string $skip_from, array $overrides, int $per_day = 16): array {
    $monthly_roster = [];
    $total = count($students);
    if ($total === 0) return $monthly_roster;

    $days_in_month = date('t', strtotime($target_month . '-01'));
    $start_ts = strtotime($start_date);
    $target_ts_end = strtotime("$target_month-$days_in_month");

    if ($target_ts_end < $start_ts) {
        for ($day = 1; $day <= $days_in_month; $day++) {
            $monthly_roster[$day] = array_fill(0, $per_day, '-');
        }
        return $monthly_roster;
    }

    $p = 0; // ตัวชี้ลำดับในรายชื่อเต็ม
    for ($ts = $start_ts; $ts <= $target_ts_end; $ts += 86400) {
        $date = date('Y-m-d', $ts);
        $current_m = date('Y-m', $ts);
        
        $slots = [];
        for ($i = 0; $i < $per_day; $i++) {
            $tries = 0;
            while ($tries < $total) {
                $s = $students[$p];
                $p = ($p + 1) % $total;
                $tries++;
                
                $is_resigned = (($s['status'] ?? 'studying') === 'resigned');
                // ข้ามเฉพาะคนลาออก ตั้งแต่วันที่กำหนดเป็นต้นไป
                if ($is_resigned && $date >= $skip_from) continue;
                
                $slots[] = $s['student_code'];
                break;
            }
        }

        // หากอยู่ในเดือนที่กำลังเลือก ให้บันทึกลงตารางประจำเดือน
        if ($current_m === $target_month) {
            $day_num = (int)date('j', $ts);
            $daily_duty = [];
            foreach ($slots as $orig_code) {
                // ตรวจสอบว่ามีการ Override หรือไม่
                $actual = isset($overrides[$date][$orig_code]) ? $overrides[$date][$orig_code] : $orig_code;
                $daily_duty[] = $student_names[$actual] ?? $actual;
            }
            $monthly_roster[$day_num] = $daily_duty;
        }
    }
    return $monthly_roster;
}

$monthly_roster = buildMonthlyRoster($all_students, $start_date, $target_month, $skip_resigned_from, $overrides, $per_day);

// 4. จัดกลุ่มวันที่เป็นสัปดาห์ (จันทร์ - อาทิตย์)
$weeks = [];
$current_week = [];
$days_in_month = date('t', strtotime($target_month . '-01'));
$first_day_of_month_w = date('N', strtotime($target_month . '-01')); // 1=จันทร์, 7=อาทิตย์

// เติมช่องว่างสัปดาห์แรก
for ($i = 1; $i < $first_day_of_month_w; $i++) {
    $current_week[$i] = null; 
}

for ($day = 1; $day <= $days_in_month; $day++) {
    $w = date('N', strtotime("$target_month-$day"));
    $current_week[$w] = $day;
    
    if ($w == 7 || $day == $days_in_month) {
        if ($day == $days_in_month && $w != 7) {
            for ($i = $w + 1; $i <= 7; $i++) {
                $current_week[$i] = null;
            }
        }
        $weeks[] = $current_week;
        $current_week = [];
    }
}

// แปลงเดือน/ปี เป็นภาษาไทย
$thai_months = ["01"=>"มกราคม", "02"=>"กุมภาพันธ์", "03"=>"มีนาคม", "04"=>"เมษายน", "05"=>"พฤษภาคม", "06"=>"มิถุนายน", "07"=>"กรกฎาคม", "08"=>"สิงหาคม", "09"=>"กันยายน", "10"=>"ตุลาคม", "11"=>"พฤศจิกายน", "12"=>"ธันวาคม"];
$th_month = $thai_months[date('m', strtotime($target_month))];
$th_year = date('Y', strtotime($target_month)) + 543;
$th_year_short = substr($th_year, 2, 2); // เช่น 69

$shifts = ['ผลัดที่ ๑', 'ผลัดที่ ๒', 'ผลัดที่ ๓', 'ผลัดที่ ๔'];
$points = ['จุดที่ ๔', 'จุดที่ ๕', 'จุดที่ ๖', 'จุดที่ ๓'];
$day_names = [1 => 'จันทร์', 2 => 'อังคาร', 3 => 'พุธ', 4 => 'พฤหัสบดี', 5 => 'ศุกร์', 6 => 'เสาร์', 7 => 'อาทิตย์'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ตารางเวรเดือน <?= $th_month ?> <?= $th_year ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 14px;
            color: #000;
            background: #fff;
            margin: 0;
        }
        .page {
            width: 297mm; /* A4 Landscape */
            min-height: 210mm;
            padding: 20mm;
            margin: auto;
            box-sizing: border-box;
            page-break-after: always;
        }
        .text-center { text-align: center; }
        .title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #000;
            padding: 4px 8px;
            text-align: center;
            vertical-align: middle;
        }
        th { font-weight: bold; }
        .footer {
            margin-top: 40px;
            width: 100%;
            display: flex;
            justify-content: space-between;
        }
        .footer-left p, .footer-right p {
            margin: 5px 0;
        }
        .footer-right {
            text-align: center;
            width: 40px;
        }
        
        @media print {
            @page { size: A4 landscape; margin: 0; }
            body { background: none; }
            .page { padding: 15mm; box-shadow: none; width: 100%; height: 100%; page-break-after: always; }
            .no-print { display: none; }
        }
        
        .btn-print {
            position: fixed; top: 20px; right: 20px;
            padding: 10px 20px; background: #503896; color: #fff;
            border: none; border-radius: 8px; cursor: pointer;
            font-family: 'Sarabun'; font-size: 16px; z-index: 1000;
        }
        .btn-print:hover { background: #3d2b78; }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">บันทึกเป็น PDF</button>

    <?php foreach ($weeks as $week_index => $week_days): ?>
    <div class="page">
        <div class="title text-center">
            เวรรักษาการณ์ นชท. ชั้นปีที่ ๑ ภาคปกติ รุ่นที่ ๖๗ ประจำเดือน <?= $th_month ?> <?= $th_year_short ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th colspan="2">วันที่</th>
                    <?php for($w=1; $w<=7; $w++): ?>
                        <th><?= $week_days[$w] ? $week_days[$w] : '' ?></th>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <th>ผลัด</th>
                    <th>วัน</th>
                    <?php for($w=1; $w<=7; $w++): ?>
                        <th><?= $day_names[$w] ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                $person_idx = 0;
                foreach ($shifts as $shift_idx => $shift_name): 
                ?>
                    <?php foreach ($points as $point_idx => $point_name): ?>
                    <tr>
                        <?php if ($point_idx === 0): ?>
                            <td rowspan="4"><?= $shift_name ?></td>
                        <?php endif; ?>
                        
                        <td><?= $point_name ?></td>
                        
                        <?php for($w=1; $w<=7; $w++): 
                            $day_num = $week_days[$w];
                            $student_name = '';
                            if ($day_num && isset($monthly_roster[$day_num][$person_idx])) {
                                $student_name = $monthly_roster[$day_num][$person_idx];
                            }
                        ?>
                            <td><?= htmlspecialchars($student_name) ?></td>
                        <?php endfor; ?>
                    </tr>
                    <?php $person_idx++; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($week_index === count($weeks) - 1): ?>
        <div class="footer">
            <div class="footer-left">
                <p>จุดที่ ๔ ยานยนต์</p>
                <p>จุดที่ ๕ อาคารแผนก ผฝอ</p>
                <p>จุดที่ ๖ กสน</p>
                <p>จุดที่ ๓ โรงรถ บก.หน้า</p>
            </div>
            <div class="footer-right">
                <p>ตรวจถูกต้อง</p>
                <br><br>
                <p>พ.ต. .....................................................</p>
                <p>(อัคเดช ใจเที่ยงกุล)</p>
                <p>ผบ.ร้อย กรม นร.รร.ชท.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</body>
</html>
