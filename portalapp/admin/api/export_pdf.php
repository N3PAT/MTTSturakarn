<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['admin_id'])) {
    die("Unauthorized Access");
}

$target_month = $_GET['month'] ?? date('Y-m');

// 1. ดึงข้อมูลการตั้งค่า
$settings_query = $conn->query("SELECT * FROM duty_settings LIMIT 1");
$settings = $settings_query ? $settings_query->fetch_assoc() : null;
$start_date = $settings['start_date'] ?? '2026-07-06';
$per_day = 16; // อิงตามแบบฟอร์ม 4 ผลัด x 4 จุด = 16 คน

// 2. ดึงข้อมูลนักเรียนและ Overrides
$active_students = [];
$student_names = [];
$res = $conn->query("SELECT student_code, first_name, last_name FROM students WHERE student_code COLLATE utf8mb4_unicode_ci NOT IN (SELECT student_code COLLATE utf8mb4_unicode_ci FROM duty_exemptions WHERE is_active = 1) ORDER BY id ASC");
while ($row = $res->fetch_assoc()) {
    $active_students[] = $row['student_code'];
    $student_names[$row['student_code']] = $row['first_name']; // ใช้แค่ชื่อจริงตามแบบฟอร์ม
}

$overrides = [];
$res_override = $conn->query("SELECT target_date, original_student_code, new_student_code FROM duty_overrides WHERE target_date LIKE '{$target_month}%'");
while ($row = $res_override->fetch_assoc()) {
    $overrides[$row['target_date']][$row['original_student_code']] = $row['new_student_code'];
}

// 3. คำนวณเวรประจำเดือน
$monthly_roster = [];
$days_in_month = date('t', strtotime($target_month . '-01'));
$total_active = count($active_students);

for ($day = 1; $day <= $days_in_month; $day++) {
    $current_date = date('Y-m-d', strtotime("$target_month-$day"));
    $diff_days = floor((strtotime($current_date) - strtotime($start_date)) / 86400);

    $daily_duty = [];
    if ($diff_days >= 0 && $total_active > 0) {
        $start_index = ($diff_days * $per_day) % $total_active;
        for ($i = 0; $i < $per_day; $i++) {
            $idx = ($start_index + $i) % $total_active;
            $orig = $active_students[$idx];
            $actual = isset($overrides[$current_date][$orig]) ? $overrides[$current_date][$orig] : $orig;
            $daily_duty[] = $student_names[$actual] ?? $actual;
        }
    } else {
        $daily_duty = array_fill(0, 16, '-');
    }
    $monthly_roster[$day] = $daily_duty;
}

// 4. จัดกลุ่มวันที่เป็นสัปดาห์ (จันทร์ - อาทิตย์)
$weeks = [];
$current_week = [];
$first_day_of_month_w = date('N', strtotime($target_month . '-01')); // 1=จันทร์, 7=อาทิตย์

// เติมช่องว่างสัปดาห์แรก
for ($i = 1; $i < $first_day_of_month_w; $i++) {
    $current_week[$i] = null; 
}

for ($day = 1; $day <= $days_in_month; $day++) {
    $w = date('N', strtotime("$target_month-$day"));
    $current_week[$w] = $day;
    
    if ($w == 7 || $day == $days_in_month) {
        // เติมช่องว่างสัปดาห์สุดท้าย
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
$thai_months = ["01"=>"ม.ค.", "02"=>"ก.พ.", "03"=>"มี.ค.", "04"=>"เม.ย.", "05"=>"พ.ค.", "06"=>"มิ.ย.", "07"=>"ก.ค.", "08"=>"ส.ค.", "09"=>"ก.ย.", "10"=>"ต.ค.", "11"=>"พ.ย.", "12"=>"ธ.ค."];
$th_month = $thai_months[date('m', strtotime($target_month))];
$th_year = date('Y', strtotime($target_month)) + 543;
$th_year_short = substr($th_year, 2, 2); // เอาแค่ 2 ตัวท้าย เช่น 69

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
            width: 40%;
        }
        
        @media print {
            @page { size: A4 landscape; margin: 0; }
            body { background: none; }
            .page { padding: 15mm; box-shadow: none; width: 100%; height: 100%; page-break-after: always; }
            .no-print { display: none; }
        }
        
        .btn-print {
            position: fixed; top: 20px; right: 20px;
            padding: 10px 20px; background: #ef4444; color: #fff;
            border: none; border-radius: 5px; cursor: pointer;
            font-family: 'Sarabun'; font-size: 16px;
        }
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
