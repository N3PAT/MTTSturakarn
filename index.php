<?php
session_start(); // 1. เปิดใช้งาน Session สำหรับ CSRF Token

// ตั้งค่า Timezone ให้เป็นเวลาประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// สร้าง CSRF Token หากยังไม่มี
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// เชื่อมต่อฐานข้อมูล
require 'db.php';

// ---------------- 0. API สำหรับดึงข้อมูลนักเรียนผ่าน AJAX ----------------
if (isset($_GET['action']) && $_GET['action'] == 'get_student_name' && isset($_GET['code'])) {
    
    // 💡 ปิด Session ทันทีเพื่อลดปัญหาคอขวดบน Server (ช่วยให้ iOS Safari ทำงานแบบ Asynchronous ได้ลื่นขึ้น)
    session_write_close(); 

    // ล้าง output buffer ป้องกันอักขระขยะหลุดไปพัง JSON
    while (ob_get_level()) { ob_end_clean(); }
    
    header('Content-Type: application/json; charset=utf-8');
    
    // 🚀 Cache Header: ให้ Vercel Edge จำไว้ 1 วัน (ลดเวลาค้นหาเหลือ 0.01 วิ) และ Safari จำไว้ 1 นาที
    header('Cache-Control: public, max-age=60, s-maxage=86400, stale-while-revalidate=3600');

    $code = trim($_GET['code']);
    
    try {
        if (!isset($conn) || !$conn) {
            throw new Exception('เชื่อมต่อฐานข้อมูลไม่ได้');
        }
        
        $stmt = $conn->prepare("SELECT prefix, first_name, last_name, section FROM students WHERE student_code = ? LIMIT 1");
        
        if (!$stmt) {
            throw new Exception('SQL Error: โครงสร้างตารางไม่ถูกต้อง');
        }
        
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $fullname = trim(($row['prefix'] ?? '') . ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            echo json_encode([
                'found' => true,
                'name' => htmlspecialchars($fullname),
                'section' => htmlspecialchars($row['section'] ?? '')
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['found' => false, 'name' => '', 'section' => ''], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        // หากเจอ Error ห้ามให้ระบบ Cache ผลลัพธ์เด็ดขาด
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo json_encode([
            'found' => false,
            'name' => 'Error: ' . $e->getMessage(),
            'section' => ''
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------------- 1. ระบบคำนวณวันที่ ----------------
$tz = new DateTimeZone('Asia/Bangkok');
$dt = new DateTime('now', $tz);

$dt->modify('+1 day -10 hours'); 

$current_day_of_week = (int)$dt->format('N');
$current_hour = (int)$dt->format('H');

$days_until_friday = 5 - $current_day_of_week;
if ($days_until_friday < 0) {
    $days_until_friday += 7;
}

$target_dt = clone $dt;
$target_dt->modify("+$days_until_friday days");
$target_date = $target_dt->format('Y-m-d');

$start_date = "2026-07-06"; // วันเริ่มต้นคำนวณเวรหลัก

// เปิดโหวต: เสาร์-พุธ(เที่ยง) 
$is_voting_open = (
    in_array($current_day_of_week, [6, 7, 1, 2, 3, 4, 5]) ||
    ($current_day_of_week == 2 && $current_hour < 12)
);

$alert_type = "";
$message = "";

// ---------------- 2. ลอจิกการคำนวณเวร (ผลัดละ 16 คน วนลูป) ----------------
$duty_roster_ids = [];
$total_students = 0;

try {
    if (isset($conn) && $conn) {
        $res_count = $conn->query("SELECT COUNT(id) AS total FROM students");
        if ($res_count && $row_count = $res_count->fetch_assoc()) {
            $total_students = (int)$row_count['total'];
        }

        if ($total_students > 0) {
            $start_dt_obj = new DateTime($start_date, $tz);
            $start_dt_obj->setTime(0, 0, 0); 
            
            $target_dt_obj = new DateTime($target_date, $tz);
            $target_dt_obj->setTime(0, 0, 0);

            if ($target_dt_obj < $start_dt_obj) {
                $diff_weeks = 0;
            } else {
                $interval = $start_dt_obj->diff($target_dt_obj);
                $diff_days = $interval->days; 
                $diff_weeks = floor($diff_days / 7); 
            }
            
            // คำนวณลำดับจุดเริ่มต้นของเวรสัปดาห์นี้
            $start_index = ($diff_weeks * 16) % $total_students;
            
            $limit1 = 16;
            $limit2 = 0;
            
            // กรณีจำนวนคนวนเลยรอบ ให้ดึงส่วนที่เหลือและวนกลับไปเริ่มคนแรก
            if ($start_index + 16 > $total_students) {
                $limit1 = $total_students - $start_index;
                $limit2 = 16 - $limit1;
            }
            
            // คิวรีชุดที่ 1: ดึงรายชื่อตามลำดับ
            $stmt_duty1 = $conn->prepare("SELECT student_code FROM students ORDER BY id ASC LIMIT ? OFFSET ?");
            $stmt_duty1->bind_param("ii", $limit1, $start_index);
            $stmt_duty1->execute();
            $res_duty1 = $stmt_duty1->get_result();
            while ($row = $res_duty1->fetch_assoc()) {
                $duty_roster_ids[] = $row['student_code'];
            }
            
            // คิวรีชุดที่ 2: วนกลับไปเก็บส่วนแรกของตารางถ้าจำนวนคนไม่ครบ 16
            if ($limit2 > 0) {
                $offset0 = 0;
                $stmt_duty2 = $conn->prepare("SELECT student_code FROM students ORDER BY id ASC LIMIT ? OFFSET ?");
                $stmt_duty2->bind_param("ii", $limit2, $offset0);
                $stmt_duty2->execute();
                $res_duty2 = $stmt_duty2->get_result();
                while ($row = $res_duty2->fetch_assoc()) {
                    $duty_roster_ids[] = $row['student_code'];
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Duty Calculation Error: " . $e->getMessage());
}

// ---------------- 3. เมื่อนักเรียนกดบันทึกข้อมูล (POST) ----------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // รับค่าและทำความสะอาดข้อมูลเพื่อป้องกัน XSS
    $stu_code = htmlspecialchars(trim($_POST['student_code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $section = htmlspecialchars(trim($_POST['section'] ?? ''), ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars(trim($_POST['status'] ?? ''), ENT_QUOTES, 'UTF-8');
    $province = htmlspecialchars(trim($_POST['province'] ?? ''), ENT_QUOTES, 'UTF-8');
    $travel_method = htmlspecialchars(trim($_POST['travel_method'] ?? ''), ENT_QUOTES, 'UTF-8');
    $return_day = htmlspecialchars(trim($_POST['return_day'] ?? ''), ENT_QUOTES, 'UTF-8');
    $stay_travel_method = htmlspecialchars(trim($_POST['stay_travel_method'] ?? ''), ENT_QUOTES, 'UTF-8');

    // 🔒 ตรวจสอบความปลอดภัย CSRF Token
    if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $alert_type = "error";
        $message = "เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่";
    }
    else if (!$is_voting_open) {
        $alert_type = "error";
        $message = "หมดเวลาทำรายการ ระบบเปิดให้ทำรายการเฉพาะช่วงที่กำหนด";
    } else {
        
        $allowed_status = ['home', 'stay', 'duty', 'restrict'];
        
        if (!in_array($status, $allowed_status)) {
            $alert_type = "error";
            $message = "สถานะไม่ถูกต้องตามที่ระบบกำหนด";
        } else if ($status === 'duty' && !in_array($stu_code, $duty_roster_ids)) {
            $alert_type = "error";
            $message = "รหัส " . $stu_code . " ไม่ได้มีรายชื่อเวร ไม่สามารถเลือกสถานะเวรได้";
        } else {
            // ล้างค่าฟิลด์ที่ไม่เกี่ยวข้องตามสถานะที่เลือก
            if ($status === 'home') {
                $return_day = null;
            } else if ($status === 'stay') {
                $province = null;
                if (in_array($return_day, ['กลับวันเสาร์', 'กลับวันอาทิตย์'])) {
                    $travel_method = $stay_travel_method; 
                } else {
                    $travel_method = null;
                }
            } else { 
                $province = null;
                $travel_method = null;
                $return_day = null;
            }

            try {
                // เช็กการลงทะเบียนซ้ำประจำสัปดาห์
                $stmt_check = $conn->prepare("SELECT id FROM weekly_votes WHERE student_code = ? AND vote_date = ?");
                $stmt_check->bind_param("ss", $stu_code, $target_date);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();

                if ($result_check->num_rows > 0) {
                    $alert_type = "error";
                    $message = "รหัส " . $stu_code . " ได้ทำการบันทึกข้อมูลของสัปดาห์นี้ไปแล้ว ไม่สามารถทำรายการซ้ำได้";
                } else {
                    // เริ่ม Transaction ป้องกันข้อมูลบันทึกไม่ครบถ้วน
                    $conn->begin_transaction(); 

                    // อัปเดตข้อมูลหมวด/ฝ่ายในตารางนักเรียน
                    $stmt_update = $conn->prepare("UPDATE students SET section = ? WHERE student_code = ?");
                    $stmt_update->bind_param("ss", $section, $stu_code);
                    $stmt_update->execute();

                    $real_time_now = date('Y-m-d H:i:s');
                    
                    // บันทึกคำตอบลงตาราง weekly_votes
                    $stmt_insert = $conn->prepare("INSERT INTO weekly_votes (student_code, vote_date, status, province, travel_method, return_day, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_insert->bind_param("sssssss", $stu_code, $target_date, $status, $province, $travel_method, $return_day, $real_time_now);
                    $stmt_insert->execute();
                    
                    $conn->commit();

                    $alert_type = "success";
                    $message = "บันทึกข้อมูลรหัส " . $stu_code . " ประจำวันศุกร์ที่ " . date('d/m/Y', strtotime($target_date)) . " เรียบร้อยแล้ว";
                }
            } catch (mysqli_sql_exception $e) {
                if (isset($conn)) $conn->rollback();
                
                if ($e->getCode() == 1062) {
                    $alert_type = "error";
                    $message = "รหัส " . $stu_code . " มีการส่งข้อมูลเข้าระบบไปแล้ว";
                } else {
                    $alert_type = "error";
                    $message = "เกิดข้อผิดพลาดจากฐานข้อมูล: โปรดติดต่อผู้ดูแลระบบ";
                }
            } catch (Exception $e) {
                if (isset($conn)) $conn->rollback();
                $alert_type = "error";
                $message = "เกิดข้อผิดพลาด: โปรดติดต่อผู้ดูแลระบบ";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <!-- แก้ไข Viewport: เอา user-scalable=no ออก เพื่อให้ผู้ใช้ซูมหน้าจอได้ (แก้ปัญหา Accessibility) -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>แบบฟอร์ม นชท. - แจ้งความประสงค์</title>

    <!-- เพิ่ม Meta Description (แก้ปัญหา SEO) -->
    <meta name="description" content="ระบบแจ้งความประสงค์กลับภูมิลำเนาและเวรรักษาการณ์ สำหรับนักเรียนช่างฝีมือทหาร (นชท.)">

    <!-- Preconnect เพื่อจองช่องทางเชื่อมต่อเซิร์ฟเวอร์ Google Fonts ไว้ก่อน -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<!-- โหลดฟอนต์แบบ Non-blocking (Asynchronous) -->
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap">
</noscript>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- เพิ่ม defer ใน SweetAlert เพื่อไม่ให้บล็อกการแสดงผลหน้าเว็บ -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Sarabun', 'sans-serif'] },
                    colors: {
                        gov: { purple: '#4A148C', dark: '#380b6b', light: '#f3e5f5', red: '#b71c1c' }
                    }
                }
            }
        }
    </script>
    <style>
        input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .swal2-popup { font-family: 'Sarabun', sans-serif !important; border-radius: 4px !important; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen flex items-center justify-center p-4 antialiased">

    <!-- เปลี่ยน div ที่ครอบเนื้อหาทั้งหมดให้เป็น main (แก้ปัญหา Accessibility) -->
    <main class="w-full max-w-lg bg-white rounded-sm shadow-sm border border-gray-200 overflow-hidden relative">
        <div class="h-2 w-full bg-gov-purple"></div>

        <div class="p-6 sm:p-8">
            <div class="text-center mb-6">
                <h2 class="text-xl sm:text-2xl font-bold text-gov-purple mb-1">แจ้งความประสงค์กลับภูมิลำเนา</h2>
                <p class="text-sm text-gray-600 font-medium bg-gov-light inline-block px-3 py-1 rounded-full mt-1">
                    วันหยุด: วันศุกร์ที่ <?php echo date('d/m/Y', strtotime($target_date)); ?>
                </p>
            </div>
            
            <hr class="border-gray-200 mb-6">

            <?php if ($is_voting_open): ?>
                 <form id="statusForm" method="POST" action="" class="space-y-5">
                    
                    <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    
                    <div>
                        <label for="student_code" class="block text-sm font-semibold text-gray-700 mb-1.5">รหัสประจำตัว (นชท.) <span class="text-red-500">*</span></label>
                        <input type="text" id="student_code" name="student_code" required maxlength="10" inputmode="numeric"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple transition-colors text-base"
                               placeholder="เช่น 151xx">
                    </div>

                    <div>
                        <label for="student_name" class="block text-sm font-semibold text-gray-700 mb-1.5">ชื่อ-นามสกุล</label>
                        <input type="text" id="student_name" readonly tabindex="-1"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-sm bg-gray-50 text-gray-600 focus:outline-none transition-colors text-base font-medium"
                               placeholder="ระบบจะแสดงชื่ออัตโนมัติ">
                    </div>

                    <div>
                        <label for="section" class="block text-sm font-semibold text-gray-700 mb-1.5">ตอนเรียนที่ (1-14) <span class="text-red-500">*</span></label>
                        <input type="text" id="section" name="section" required maxlength="50" inputmode="numeric"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple transition-colors text-base"
                               placeholder="เช่น 1">
                    </div>

                    <div>
                        <label for="status_select" class="block text-sm font-semibold text-gray-700 mb-1.5">เลือกสถานะ <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <select name="status" id="status_select" required 
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-sm bg-white focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple transition-colors appearance-none text-base relative z-10 bg-transparent">
                                <option value="" disabled selected>-- กรุณาเลือกสถานะ --</option>
                                <option value="home">๑. กลับภูมิลำเนา</option>
                                <option value="stay">๒. ประสงค์อยู่ รร.ชท.</option>
                                <option value="duty">๓. เวรรักษาการณ์(วันศุกร์) - ระบบล็อกให้อัตโนมัติ</option>
                                <option value="restrict">๔. กักบริเวณ</option>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 z-0">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/></svg>
                            </div>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">* กรณีใครไม่รู้ว่าโดนกักบริเวณ ธุรการจะเป็นผู้ประกาศอีกที</p>
                    </div>

                    <!-- ส่วนที่ 1: ฟอร์มสำหรับคนกลับภูมิลำเนา (อัปเดตช่องจังหวัดเป็น Dropdown) -->
                    <div id="home_fields" class="hidden space-y-4 p-4 border border-blue-100 bg-blue-50/50 rounded-sm">
                        <div>
                            <label for="province" class="block text-sm font-semibold text-gray-700 mb-1.5">จังหวัดที่เดินทางกลับ <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <select id="province" name="province"
                                       class="w-full px-4 py-2.5 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple transition-colors text-base appearance-none bg-white">
                                    <option value="" disabled selected>-- เลือกจังหวัด --</option>
                                    <option value="กรุงเทพมหานคร">กรุงเทพมหานคร</option>
                                    <option value="กระบี่">กระบี่</option>
                                    <option value="กาญจนบุรี">กาญจนบุรี</option>
                                    <option value="กาฬสินธุ์">กาฬสินธุ์</option>
                                    <option value="กำแพงเพชร">กำแพงเพชร</option>
                                    <option value="ขอนแก่น">ขอนแก่น</option>
                                    <option value="จันทบุรี">จันทบุรี</option>
                                    <option value="ฉะเชิงเทรา">ฉะเชิงเทรา</option>
                                    <option value="ชลบุรี">ชลบุรี</option>
                                    <option value="ชัยนาท">ชัยนาท</option>
                                    <option value="ชัยภูมิ">ชัยภูมิ</option>
                                    <option value="ชุมพร">ชุมพร</option>
                                    <option value="เชียงราย">เชียงราย</option>
                                    <option value="เชียงใหม่">เชียงใหม่</option>
                                    <option value="ตรัง">ตรัง</option>
                                    <option value="ตราด">ตราด</option>
                                    <option value="ตาก">ตาก</option>
                                    <option value="นครนายก">นครนายก</option>
                                    <option value="นครปฐม">นครปฐม</option>
                                    <option value="นครพนม">นครพนม</option>
                                    <option value="นครราชสีมา">นครราชสีมา</option>
                                    <option value="นครศรีธรรมราช">นครศรีธรรมราช</option>
                                    <option value="นครสวรรค์">นครสวรรค์</option>
                                    <option value="นนทบุรี">นนทบุรี</option>
                                    <option value="นราธิวาส">นราธิวาส</option>
                                    <option value="น่าน">น่าน</option>
                                    <option value="บึงกาฬ">บึงกาฬ</option>
                                    <option value="บุรีรัมย์">บุรีรัมย์</option>
                                    <option value="ปทุมธานี">ปทุมธานี</option>
                                    <option value="ประจวบคีรีขันธ์">ประจวบคีรีขันธ์</option>
                                    <option value="ปราจีนบุรี">ปราจีนบุรี</option>
                                    <option value="ปัตตานี">ปัตตานี</option>
                                    <option value="พระนครศรีอยุธยา">พระนครศรีอยุธยา</option>
                                    <option value="พะเยา">พะเยา</option>
                                    <option value="พังงา">พังงา</option>
                                    <option value="พัทลุง">พัทลุง</option>
                                    <option value="พิจิตร">พิจิตร</option>
                                    <option value="พิษณุโลก">พิษณุโลก</option>
                                    <option value="เพชรบุรี">เพชรบุรี</option>
                                    <option value="เพชรบูรณ์">เพชรบูรณ์</option>
                                    <option value="แพร่">แพร่</option>
                                    <option value="ภูเก็ต">ภูเก็ต</option>
                                    <option value="มหาสารคาม">มหาสารคาม</option>
                                    <option value="มุกดาหาร">มุกดาหาร</option>
                                    <option value="แม่ฮ่องสอน">แม่ฮ่องสอน</option>
                                    <option value="ยโสธร">ยโสธร</option>
                                    <option value="ยะลา">ยะลา</option>
                                    <option value="ร้อยเอ็ด">ร้อยเอ็ด</option>
                                    <option value="ระนอง">ระนอง</option>
                                    <option value="ระยอง">ระยอง</option>
                                    <option value="ราชบุรี">ราชบุรี</option>
                                    <option value="ลพบุรี">ลพบุรี</option>
                                    <option value="ลำปาง">ลำปาง</option>
                                    <option value="ลำพูน">ลำพูน</option>
                                    <option value="เลย">เลย</option>
                                    <option value="ศรีสะเกษ">ศรีสะเกษ</option>
                                    <option value="สกลนคร">สกลนคร</option>
                                    <option value="สงขลา">สงขลา</option>
                                    <option value="สตูล">สตูล</option>
                                    <option value="สมุทรปราการ">สมุทรปราการ</option>
                                    <option value="สมุทรสงคราม">สมุทรสงคราม</option>
                                    <option value="สมุทรสาคร">สมุทรสาคร</option>
                                    <option value="สระแก้ว">สระแก้ว</option>
                                    <option value="สระบุรี">สระบุรี</option>
                                    <option value="สิงห์บุรี">สิงห์บุรี</option>
                                    <option value="สุโขทัย">สุโขทัย</option>
                                    <option value="สุพรรณบุรี">สุพรรณบุรี</option>
                                    <option value="สุราษฎร์ธานี">สุราษฎร์ธานี</option>
                                    <option value="สุรินทร์">สุรินทร์</option>
                                    <option value="หนองคาย">หนองคาย</option>
                                    <option value="หนองบัวลำภู">หนองบัวลำภู</option>
                                    <option value="อ่างทอง">อ่างทอง</option>
                                    <option value="อำนาจเจริญ">อำนาจเจริญ</option>
                                    <option value="อุดรธานี">อุดรธานี</option>
                                    <option value="อุตรดิตถ์">อุตรดิตถ์</option>
                                    <option value="อุทัยธานี">อุทัยธานี</option>
                                    <option value="อุบลราชธานี">อุบลราชธานี</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 z-0">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/></svg>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label for="travel_method" class="block text-sm font-semibold text-gray-700 mb-1.5">วิธีการเดินทาง <span class="text-red-500">*</span></label>
                            <input type="text" id="travel_method" name="travel_method"
                                   class="w-full px-4 py-2.5 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple transition-colors text-base"
                                   placeholder="เช่น รถตู้, รถส่วนตัว, ผู้ปกครองมารับ">
                        </div>
                    </div>

                    <!-- ส่วนที่ 2: ฟอร์มสำหรับคนอยู่ รร. -->
                    <div id="stay_fields" class="hidden p-4 border border-orange-100 bg-orange-50/50 rounded-sm">
                        <div>
                            <label for="return_day" class="block text-sm font-semibold text-gray-700 mb-1.5">วันเสาร์-อาทิตย์ จะออกไปธุระ/กลับบ้านไหม? <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <select name="return_day" id="return_day"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-sm bg-white focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple transition-colors appearance-none text-base relative z-10 bg-transparent">
                                    <option value="" disabled selected>-- กรุณาเลือก --</option>
                                    <option value="ไม่กลับ (อยู่ รร. ตลอด)">ไม่กลับ (อยู่ รร. ตลอด)</option>
                                    <option value="กลับวันเสาร์">กลับวันเสาร์</option>
                                    <option value="กลับวันอาทิตย์">กลับวันอาทิตย์</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 z-0">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/></svg>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ช่องที่ซ่อนไว้ จะแสดงเมื่อเลือก "กลับวันเสาร์" หรือ "กลับวันอาทิตย์" -->
                        <div id="stay_travel_container" class="hidden mt-4">
                            <label for="stay_travel_method" class="block text-sm font-semibold text-gray-700 mb-1.5">เดินทางกลับอย่างไร? <span class="text-red-500">*</span></label>
                            <input type="text" id="stay_travel_method" name="stay_travel_method"
                                   class="w-full px-4 py-2.5 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple transition-colors text-base"
                                   placeholder="เช่น ผู้ปกครองมารับ, นั่งรถโดยสารประจำทาง">
                        </div>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-sm p-3 mt-4 flex items-start gap-3">
                        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <div class="text-sm text-blue-800">
                            <strong>มีปัญหาการใช้งาน?</strong><br>
                            หากพบปัญหาในการกรอกข้อมูล หรือข้อมูลไม่ถูกต้อง กรุณาติดต่อ Line ID: <strong>ntw25020</strong>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" id="submitBtn"
                                class="w-full bg-gov-purple text-white font-semibold py-3 px-4 rounded-sm hover:bg-gov-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gov-purple transition-all duration-200">
                            ดำเนินการส่งข้อมูล
                        </button>
                    </div>
                </form>

            <?php else: ?>
                <div class="bg-red-50 border border-red-200 p-6 rounded-sm text-center">
                    <svg class="mx-auto h-12 w-12 text-red-500 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h3 class="text-lg font-bold text-red-800 mb-2">ระบบปิดรับข้อมูล</h3>
                    <p class="text-red-700 mb-1">ระบบเปิดให้ทำรายการเฉพาะช่วง</p>
                    <p class="text-lg font-semibold text-red-900 mb-4">วันเสาร์ - วันพุธ (ก่อนเวลา 12:00 น.)</p>
                    <button type="button" onclick="window.location.reload();" class="text-sm text-gov-purple hover:underline">
                        รีเฟรชหน้าเว็บ
                    </button>
                </div>
            <?php endif; ?>

     </div>
    </main>

<script>
// สั่งให้สคริปต์รอจนกว่า HTML และไฟล์ defer ทั้งหมดโหลดเสร็จ
document.addEventListener('DOMContentLoaded', function() {

    <?php if ($alert_type == 'success'): ?>
        Swal.fire({
            title: 'สำเร็จ',
            text: <?php echo json_encode($message); ?>,
            icon: 'success',
            confirmButtonColor: '#4A148C',
            confirmButtonText: 'ตกลง'
        }).then(() => {
            window.location.href = window.location.pathname;
        });
    <?php elseif ($alert_type == 'error'): ?>
        Swal.fire({
            title: 'ข้อผิดพลาด',
            text: <?php echo json_encode($message); ?>,
            icon: 'error',
            confirmButtonColor: '#b71c1c',
            confirmButtonText: 'ตกลง'
        });
    <?php endif; ?>

    <?php if ($is_voting_open): ?>
        const dutyIds = <?php echo json_encode($duty_roster_ids); ?>;
        
        let lastFetchedCode = ""; 
        let fetchTimeout = null;

        // ----- สคริปต์ควบคุมการซ่อน/แสดงช่องกรอกข้อมูล -----
        const statusSelect = document.getElementById('status_select');
        const homeFields = document.getElementById('home_fields');
        const stayFields = document.getElementById('stay_fields');
        const provinceInput = document.getElementById('province');
        const travelInput = document.getElementById('travel_method');
        const returnDaySelect = document.getElementById('return_day');
        
        const stayTravelContainer = document.getElementById('stay_travel_container');
        const stayTravelInput = document.getElementById('stay_travel_method');
        const csrfTokenValue = document.getElementById('csrf_token').value;

        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                homeFields.classList.add('hidden');
                stayFields.classList.add('hidden');
                stayTravelContainer.classList.add('hidden');
                
                provinceInput.required = false;
                travelInput.required = false;
                returnDaySelect.required = false;
                stayTravelInput.required = false;

                returnDaySelect.value = '';
                stayTravelInput.value = '';

                if (this.value === 'home') {
                    homeFields.classList.remove('hidden');
                    provinceInput.required = true;
                    travelInput.required = true;
                } else if (this.value === 'stay') {
                    stayFields.classList.remove('hidden');
                    returnDaySelect.required = true;
                }
            });
        }

        if (returnDaySelect) {
            returnDaySelect.addEventListener('change', function() {
                if (this.value === 'กลับวันเสาร์' || this.value === 'กลับวันอาทิตย์') {
                    stayTravelContainer.classList.remove('hidden');
                    stayTravelInput.required = true; 
                } else {
                    stayTravelContainer.classList.add('hidden');
                    stayTravelInput.required = false;
                    stayTravelInput.value = ''; 
                }
            });
        }

        async function checkStudentData() {
            const stuInput = document.getElementById('student_code');
            const stuCode = stuInput.value.trim();
            const nameInput = document.getElementById('student_name');

            if (stuCode === "") {
                nameInput.value = "";
                nameInput.classList.remove("text-red-500");
                nameInput.classList.add("text-gray-600");
                
                statusSelect.disabled = false;
                statusSelect.style.pointerEvents = "auto";
                statusSelect.classList.remove("bg-gray-100","text-red-700","border-gray-300");
                statusSelect.classList.add("bg-transparent");
                statusSelect.value = "";
                statusSelect.dispatchEvent(new Event('change')); 
                return;
            }

            nameInput.value = "กำลังค้นหาข้อมูล...";
            nameInput.classList.remove("text-red-500");
            nameInput.classList.add("text-gray-600");

            try {
                const response = await fetch(`?action=get_student_name&code=${encodeURIComponent(stuCode)}&csrf_token=${encodeURIComponent(csrfTokenValue)}&_t=${Date.now()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache'
                    },
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();

                if (data.found) {
                    nameInput.value = data.name;
                    if (data.section) {
                        document.getElementById("section").value = data.section;
                    }
                    nameInput.classList.remove("text-red-500");
                    nameInput.classList.add("text-gray-600");
                } else {
                    nameInput.value = data.name.includes("Error") ? data.name : "ไม่พบรหัสนี้ในระบบฐานข้อมูล";
                    nameInput.classList.remove("text-gray-600");
                    nameInput.classList.add("text-red-500");
                }
            } catch (error) {
                console.error('Fetch error:', error);
                nameInput.value = "เซิร์ฟเวอร์ทำงานหนัก (ลบตัวท้าย 1 ตัวแล้วพิมพ์ใหม่)";
                nameInput.classList.remove("text-gray-600");
                nameInput.classList.add("text-red-500");
                lastFetchedCode = ""; 
            }

            if (dutyIds.includes(stuCode)) {
                Swal.fire({
                    title: 'แจ้งเตือนระบบเวรรักษาการณ์',
                    text: 'รหัสของท่านมีรายชื่อปฏิบัติหน้าที่เวรรักษาการณ์ในสัปดาห์นี้ ระบบจะเลือกสถานะเวรให้อัตโนมัติ',
                    icon: 'warning',
                    confirmButtonColor: '#4A148C',
                    confirmButtonText: 'รับทราบ'
                });

                statusSelect.value = "duty";
                statusSelect.disabled = true;
                statusSelect.style.pointerEvents = "none";
                statusSelect.classList.add("bg-gray-100","text-red-700","border-gray-300");
                statusSelect.classList.remove("bg-transparent");
                statusSelect.dispatchEvent(new Event('change')); 
            } else {
                statusSelect.disabled = false;
                statusSelect.style.pointerEvents = "auto";
                statusSelect.classList.remove("bg-gray-100","text-red-700","border-gray-300");
                statusSelect.classList.add("bg-transparent");
                
                if (statusSelect.value === "duty") {
                    statusSelect.value = "";
                    statusSelect.dispatchEvent(new Event('change'));
                }
            }
        }

        const studentInput = document.getElementById('student_code');

        if (studentInput) {
            studentInput.addEventListener('input', function() {
                const currentCode = this.value.trim();
                
                clearTimeout(fetchTimeout);

                if (currentCode.length >= 5) {
                    fetchTimeout = setTimeout(() => {
                        if (currentCode !== lastFetchedCode) {
                            lastFetchedCode = currentCode;
                            checkStudentData();
                        }
                    }, 500); 
                } else {
                    lastFetchedCode = "";
                    const nameInput = document.getElementById('student_name');
                    
                    if(nameInput) {
                        nameInput.value = "";
                        nameInput.classList.remove("text-red-500");
                        nameInput.classList.add("text-gray-600");
                    }
                    
                    if(statusSelect && statusSelect.value === "duty") {
                        statusSelect.disabled = false;
                        statusSelect.style.pointerEvents = "auto";
                        statusSelect.classList.remove("bg-gray-100","text-red-700","border-gray-300");
                        statusSelect.classList.add("bg-transparent");
                        statusSelect.value = "";
                        statusSelect.dispatchEvent(new Event('change'));
                    }
                }
            });
        }

        const form = document.getElementById("statusForm");
        
        if (form) {
            form.addEventListener("submit", function(e){
                e.preventDefault();
                
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                let timerInterval;
                Swal.fire({
                    title: 'โปรดยืนยันการทำรายการ',
                    html: `
                        <div class="text-sm text-gray-700 mb-4">
                            กรุณาตรวจสอบข้อมูลให้ถูกต้องก่อนกดยืนยัน
                        </div>
                        <div class="bg-red-50 border border-red-200 p-3 rounded-sm mb-4">
                            <span class="text-sm font-semibold text-red-700">
                                ข้อควรระวัง : ไม่สามารถแก้ไขข้อมูลภายหลังได้ คิดดีๆ ถ้าไม่อยากใส่ชุดจับหมู
                            </span>
                        </div>
                        <div class="text-gray-500 font-medium">
                            กรุณารอ <b id="swal-timer" class="text-gov-purple text-lg">10</b> วินาที
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#4A148C',
                    cancelButtonColor: '#9CA3AF',
                    confirmButtonText: 'ยืนยันการส่งข้อมูล',
                    cancelButtonText: 'ยกเลิก',
                    allowOutsideClick: false,
                    didOpen: () => {
                        const confirmBtn = Swal.getConfirmButton();
                        confirmBtn.disabled = true;
                        confirmBtn.classList.add("opacity-50","cursor-not-allowed");
                        
                        const timerElement = document.getElementById("swal-timer");
                        let timeLeft = 10;
                        
                        timerInterval = setInterval(()=>{
                            timeLeft--;
                            if(timerElement) timerElement.textContent = timeLeft;
                            if(timeLeft <= 0){
                                clearInterval(timerInterval);
                                confirmBtn.disabled = false;
                                confirmBtn.classList.remove("opacity-50","cursor-not-allowed");
                                if(timerElement && timerElement.parentElement) {
                                    timerElement.parentElement.innerHTML = '<span class="text-green-600 font-semibold">ท่านสามารถกดยืนยันได้แล้ว</span>';
                                }
                            }
                        }, 1000);
                    },
                    willClose: ()=>{
                        clearInterval(timerInterval);
                    }
                }).then((result)=>{
                    if(result.isConfirmed){
                        const statusSelect = document.getElementById("status_select");
                        statusSelect.disabled = false; 
                        
                        const submitBtn = document.getElementById("submitBtn");
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = "กำลังบันทึก...";
                        
                        form.submit();
                    }
                });
            });
        }
    <?php endif; ?>

}); // ปิด DOMContentLoaded
</script>
</body>
</html>
