<?php
// ตั้งค่า Timezone ให้เป็นเวลาประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// เชื่อมต่อฐานข้อมูล
require 'db.php'; 

// ---------------- 1. ระบบกำหนดวันที่และคำนวณเวร ----------------
// รับค่าวันที่เลือก (ถ้าไม่มีให้ Default เป็นวันศุกร์ของสัปดาห์นี้)
$target_date = isset($_POST['target_date']) ? $_POST['target_date'] : (isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('friday this week')));
$start_date = "2026-07-06"; // วันเริ่มต้นคำนวณเวรหลักหลักการหมุนเวียน

$all_students = [];
$duty_roster = [];
$error_db = false;

try {
    // ดึงรายละเอียดนักเรียนทั้งหมดเพื่อหาคิวเข้าเวร
    $res = $conn->query("SELECT student_code, prefix, first_name, last_name, section FROM students ORDER BY id ASC");
    if($res) {
        while($row = $res->fetch_assoc()) {
            $all_students[] = $row;
        }
    }
} catch (Exception $e) {
    $error_db = true;
}

$total_students = count($all_students);

// ลอจิกการคำนวณเวรหมุนเวียน 16 นาย/วัน 
if ($total_students > 0) {
    $diff_days = floor((strtotime($target_date) - strtotime($start_date)) / 86400);
    if ($diff_days < 0) $diff_days = 0;
    
    $start_index = ($diff_days * 16) % $total_students;
    for ($i = 0; $i < 16; $i++) {
        $idx = ($start_index + $i) % $total_students;
        if (isset($all_students[$idx])) {
            $duty_roster[] = $all_students[$idx];
        }
    }
}

// ---------------- 2. จัดกลุ่มลงผลัดและจุดรักษาการณ์ (4x4) ----------------
$shifts_count = 4;
$locations_count = 4;
$schedule = [];

// กำหนดโครงสร้างตารางว่างไว้ก่อน
for ($s = 0; $s < $shifts_count; $s++) {
    for ($l = 0; $l < $locations_count; $l++) {
        $schedule[$s][$l] = null;
    }
}

// นำรายชื่อใส่ลงในตาราง ผลัดละ 4 คนตามลำดับจุด
foreach ($duty_roster as $index => $student) {
    if ($index >= 16) break; 
    $shift_idx = floor($index / $locations_count);
    $loc_idx = $index % $locations_count;
    $schedule[$shift_idx][$loc_idx] = $student;
}

$shift_labels = ["ผลัดที่ 1", "ผลัดที่ 2", "ผลัดที่ 3", "ผลัดที่ 4"];

// คำนวณวันก่อนหน้าและถัดไปสำหรับปุ่มเปลี่ยนสัปดาห์
$prev_week = date('Y-m-d', strtotime($target_date . ' - 7 days'));
$next_week = date('Y-m-d', strtotime($target_date . ' + 7 days'));
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตารางเวรรักษาการณ์ นชท.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Sarabun', 'sans-serif'],
                    },
                    colors: {
                        gov: {
                            purple: '#4A148C',
                            dark: '#380b6b',
                            light: '#f3e5f5',
                            red: '#b71c1c',
                            gold: '#FFD700'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen antialiased py-8 px-4">

    <!-- Flat Design Container: No shadow, sharp borders, solid colors -->
    <div class="w-full max-w-6xl mx-auto bg-white border-2 border-gray-800 rounded-none overflow-hidden">
        
        <!-- Header Section -->
        <div class="p-6 sm:p-8 border-b-2 border-gray-800 bg-gov-purple text-white">
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="text-center sm:text-left">
                    <h1 class="text-xl sm:text-2xl font-bold uppercase tracking-wide">
                        ตารางปฏิบัติหน้าที่เวรรักษาการณ์ นชท.
                    </h1>
                    <p class="text-xs sm:text-sm text-purple-200 mt-1">ระบบคำนวณคิวหมุนเวียนอัตโนมัติตามทำเนียบรายชื่อ</p>
                </div>
                
                <form method="POST" action="" class="flex items-center gap-2 bg-white text-gray-800 p-2 border-2 border-gray-800 rounded-none">
                    <label class="text-xs font-bold pl-2 hidden sm:inline uppercase">ดูวันที่:</label>
                    <input type="date" name="target_date" value="<?php echo $target_date; ?>" 
                           onchange="this.form.submit()"
                           class="px-2 py-1 text-sm focus:outline-none font-medium bg-transparent cursor-pointer">
                </form>
            </div>
        </div>

        <!-- Navigation Bar -->
        <div class="px-6 py-4 border-b-2 border-gray-800 flex justify-between items-center bg-gray-50">
            <a href="?date=<?php echo $prev_week; ?>" class="px-4 py-2 bg-white border-2 border-gray-800 text-xs font-bold text-gray-800 hover:bg-gray-800 hover:text-white transition-colors uppercase">
                &laquo; สัปดาห์ก่อนหน้า
            </a>
            <div class="text-center">
                <span class="text-sm font-bold text-gov-dark block uppercase tracking-wider">
                    ประจำวันศุกร์ที่ <?php echo date('d/m/Y', strtotime($target_date)); ?>
                </span>
                <span class="text-[11px] text-white bg-gray-800 px-3 py-1 rounded-none mt-1 inline-block uppercase font-semibold">
                    สัปดาห์ที่ <?php echo isset($diff_days) ? floor($diff_days / 7) : 0; ?>
                </span>
            </div>
            <a href="?date=<?php echo $next_week; ?>" class="px-4 py-2 bg-white border-2 border-gray-800 text-xs font-bold text-gray-800 hover:bg-gray-800 hover:text-white transition-colors uppercase">
                สัปดาห์ถัดไป &raquo;
            </a>
        </div>

        <div class="p-6 sm:p-8">
            <?php if($error_db): ?>
                <div class="bg-red-600 border-2 border-red-900 text-white p-4 rounded-none text-sm font-bold mb-6 uppercase">
                    [!] ไม่สามารถเชื่อมต่อตารางข้อมูลได้ กรุณาตรวจสอบฐานข้อมูล
                </div>
            <?php endif; ?>

            <div class="overflow-x-auto border-2 border-gray-800">
                <table class="w-full text-left border-collapse min-w-[800px]">
                    <thead>
                        <tr class="bg-gray-800 text-white text-sm font-semibold uppercase tracking-wide">
                            <th class="border-b-2 border-r-2 border-gray-800 py-4 px-4 text-center w-32">ผลัดป���ิบัติหน้าที่</th>
                            <th class="border-b-2 border-r-2 border-gray-800 py-4 px-4 text-center w-56">จุดรักษาการณ์</th>
                            <th class="border-b-2 border-r-2 border-gray-800 py-4 px-4 text-center w-32">รหัสประจำตัว</th>
                            <th class="border-b-2 border-r-2 border-gray-800 py-4 px-4 text-center">ยศ ชื่อ - นามสกุล</th>
                            <th class="border-b-2 border-gray-800 py-4 px-4 text-center w-32">ตอนเรียน</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm bg-white">
                        <?php 
                        // กำหนดชื่อจุดรักษาการณ์ตามลำดับ
                        $location_names = [
                            "จุดที่ 4 อาคารยานยนต์", 
                            "จุดที่ 5 ผฟอ.", 
                            "จุดที่ 6 กศน.", 
                            "จุดที่ 7 ศาลพระปิ่น"
                        ];
                        
                        if (count($duty_roster) > 0): 
                        ?>
                            <?php foreach ($schedule as $shift_idx => $shift_data): ?>
                                <?php for ($loc_idx = 0; $loc_idx < 4; $loc_idx++): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <!-- คอลัมน์ระบุผลัด (ใช้ rowspan เพื่อรวมเซลล์ผลัดเดียวกัน 4 แถว) -->
                                        <?php if ($loc_idx == 0): ?>
                                            <td rowspan="4" class="border-b-2 border-r-2 border-gray-800 py-4 px-4 text-center font-bold text-gov-purple bg-gray-50 uppercase align-middle">
                                                <?php echo $shift_labels[$shift_idx]; ?>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <!-- คอลัมน์จุดรักษาการณ์ -->
                                        <td class="border-b-2 border-r-2 border-gray-800 py-3 px-4 font-semibold text-gray-700 align-middle">
                                            <?php echo $location_names[$loc_idx]; ?>
                                        </td>
                                        
                                        <!-- ข้อมูลนักเรียน -->
                                        <?php if (isset($shift_data[$loc_idx]) && $shift_data[$loc_idx] !== null): ?>
                                            <?php $student = $shift_data[$loc_idx]; ?>
                                            <td class="border-b-2 border-r-2 border-gray-800 py-3 px-4 text-center font-bold text-gray-900 align-middle">
                                                <?php echo htmlspecialchars($student['student_code']); ?>
                                            </td>
                                            <td class="border-b-2 border-r-2 border-gray-800 py-3 px-4 text-gray-800 font-medium align-middle">
                                                <?php echo htmlspecialchars($student['prefix'] . $student['first_name'] . " " . $student['last_name']); ?>
                                            </td>
                                            <td class="border-b-2 border-gray-800 py-3 px-4 text-center align-middle">
                                                <div class="text-xs font-semibold text-gov-purple bg-gov-light inline-block px-2 py-0.5">
                                                    <?php echo !empty($student['section']) ? htmlspecialchars($student['section']) : 'ไม่ระบุ'; ?>
                                                </div>
                                            </td>
                                        <?php else: ?>
                                            <td colspan="3" class="border-b-2 border-gray-800 py-3 px-4 text-center text-gray-400 font-medium italic align-middle">
                                                - ว่าง -
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endfor; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="border-b-2 border-gray-800 py-16 text-center text-gray-500 font-medium uppercase tracking-widest bg-gray-50">
                                    [ ไม่พบรายชื่อนักเรียนในระบบสำหรับประมวลผล ]
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-8 bg-white border-2 border-gray-800 p-5 flex gap-4 items-start">
                <div class="bg-gray-800 text-white font-bold px-3 py-1 text-sm uppercase">หมายเหตุ</div>
                <div class="text-sm text-gray-700 space-y-2">
                    <p>• รายชื่อด้านบนถูกล็อคตามตารางเวรวันเริ่มต้นที่ <span class="font-bold text-gov-purple">06/07/2026</span> วันละ 16 นายเรียงตามรหัสข้อมูล</p>
                    <p>• ระบบจัดสรรโดยอัตโนมัติ: <span class="font-bold">ผลัดละ 4 นาย ประจำ 4 จุดรักษาการณ์</span> ตามลำดับ</p>
                    <p>• เมื่อนักเรียนที่มีรายชื่อเปิดหน้าแจ้งสถานะ ระบบจะ <span class="font-bold text-red-700">บังคับล็อกสถานะเป็น "เวรรักษาการณ์"</span> เพื่อป้องกันยอดคลาดเคลื่อน</p>
                </div>
            </div>
        </div>

        <div class="bg-gray-800 py-4 text-center text-xs font-semibold text-gray-400 uppercase tracking-widest">
            ระบบตารางปฏิบัติหน้าที่เวรรักษาการณ์ประจำวันศุกร์ | โรงเรียนช่างฝีมือทหาร สถาบันวิชาการป้องกันประเทศ
        </div>
    </div>

<!-- Vercel Speed Insights -->
<script>
  window.si = window.si || function () { (window.siq = window.siq || []).push(arguments); };
</script>
<script defer src="/_vercel/speed-insights/script.js"></script>

</body>
</html>
