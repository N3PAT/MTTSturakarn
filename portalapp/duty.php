<?php
date_default_timezone_set('Asia/Bangkok');
session_start();
require_once 'db.php'; 

/* 1. ตรวจสอบการล็อกอินนักเรียนผ่าน Session */
if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}
$login_student_code = $_SESSION['student_code'];

/* 2. ตรวจสอบการแสดงหน้า Loading Screen (แสดงครั้งแรกของ Session) */
$show_loading = false;
if (!isset($_SESSION['has_seen_loading'])) {
    $show_loading = true;
    $_SESSION['has_seen_loading'] = true;
}

/* ตรวจสอบการเชื่อมต่อฐานข้อมูล */
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("เกิดข้อผิดพลาด: ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
}
$conn->set_charset("utf8mb4");

// ---------------- 3. ดึงข้อมูลส่วนตัวนักเรียน ----------------
$student_profile = null;
try {
    $stmt_me = $conn->prepare("
        SELECT student_code, prefix, first_name, last_name, platoon, company, battalion, section, image 
        FROM students 
        WHERE student_code = ? 
        LIMIT 1
    ");
    $stmt_me->bind_param("s", $login_student_code);
    $stmt_me->execute();
    $res_me = $stmt_me->get_result();
    if ($res_me->num_rows > 0) {
        $student_profile = $res_me->fetch_assoc();
    }
    $stmt_me->close();
} catch (Exception $e) {
    error_log("Profile Fetch Error: " . $e->getMessage());
}

$name = $student_profile ? trim(($student_profile['prefix'] ?? '') . $student_profile['first_name'] . ' ' . $student_profile['last_name']) : "นักเรียนทหาร";
$student_code_display = "รหัสนักเรียน " . $login_student_code;
$profile_image = $student_profile['image'] ?? '';

$unit_parts = [];
if (!empty($student_profile['battalion'])) $unit_parts[] = "พัน." . $student_profile['battalion'];
if (!empty($student_profile['company']))   $unit_parts[] = "ร้อย." . $student_profile['company'];
if (!empty($student_profile['platoon']))   $unit_parts[] = "หมวด " . $student_profile['platoon'];
if (!empty($student_profile['section']))   $unit_parts[] = "วิชาชีพช่าง " . $student_profile['section'];
$student_unit = !empty($unit_parts) ? implode(" ", $unit_parts) : "นักเรียนทหาร";

// ---------------- 4. ดึงรายชื่อนักเรียนทั้งหมดสำหรับคำนวณเวร (Algorithm) ----------------
$all_students = [];
$start_date = "2026-07-06"; // วันเริ่มต้นคำนวณเวรหลักการหมุนเวียน
try {
    $res = $conn->query("SELECT student_code, prefix, first_name, last_name, section FROM students ORDER BY id ASC");
    if($res && $res->num_rows > 0) {
        while($row = $res->fetch_assoc()) {
            $all_students[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Student Fetch Error: " . $e->getMessage());
}
$total_students = count($all_students);

// ตั้งค่าตัวแปรเวร
$locations_count = 4;
$location_names = ["จุดที่ 4 อาคารยานยนต์", "จุดที่ 5 ผฟอ.", "จุดที่ 6 กศน.", "จุดที่ 3 โรงรถ บก.หน้า"];
$shift_labels = ["ผลัดที่ 1", "ผลัดที่ 2", "ผลัดที่ 3", "ผลัดที่ 4"];
$thai_months = [
    "01" => "มกราคม", "02" => "กุมภาพันธ์", "03" => "มีนาคม", "04" => "เมษายน",
    "05" => "พฤษภาคม", "06" => "มิถุนายน", "07" => "กรกฎาคม", "08" => "สิงหาคม",
    "09" => "กันยายน", "10" => "ตุลาคม", "11" => "พฤศจิกายน", "12" => "ธันวาคม"
];
$thai_days_short = ["อาทิตย์", "จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์"];

// ---------------- 5. คำนวณแจ้งเตือนเวรล่วงหน้า 3 วัน (Badge & Modal Alert) ----------------
$duty_notifications_count = 0;
$upcoming_alerts = [];
$has_voted = true;
$today_ts = strtotime(date('Y-m-d'));
$start_timestamp = strtotime($start_date);

try {
    // โหวตประสงค์กลับบ้าน
    $stmt_vote = $conn->prepare("
        SELECT COUNT(*) as vote_count FROM votes 
        WHERE student_code = ? AND YEARWEEK(vote_date, 1) = YEARWEEK(CURDATE(), 1)
    ");
    if ($stmt_vote) {
        $stmt_vote->bind_param("s", $login_student_code);
        $stmt_vote->execute();
        $res_vote = $stmt_vote->get_result();
        if ($row_vote = $res_vote->fetch_assoc()) {
            $has_voted = ($row_vote['vote_count'] > 0);
        }
        $stmt_vote->close();
    }

    // ดึงข้อมูลการสลับเวรล่วงหน้า 0-3 วัน
    $stmt_swaps_upcoming = $conn->query("
        SELECT duty_date, requester_code, replacement_code 
        FROM duty_swaps 
        WHERE duty_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          AND (status = 'approved' OR status = '1')
    ");
    $swaps_upcoming = [];
    if ($stmt_swaps_upcoming) {
        while ($row = $stmt_swaps_upcoming->fetch_assoc()) {
            $swaps_upcoming[$row['duty_date'] . '_' . $row['requester_code']] = $row;
        }
    }

    // ลูปคำนวณเวรของวันนี้ถึงอีก 3 วันข้างหน้า เพื่อทำแจ้งเตือน
    if ($total_students > 0) {
        for ($offset = 0; $offset <= 3; $offset++) {
            $check_ts = $today_ts + ($offset * 86400);
            $check_date = date('Y-m-d', $check_ts);
            $diff_days = floor(($check_ts - $start_timestamp) / 86400);
            
            if ($diff_days >= 0) {
                $start_index = ($diff_days * 16) % $total_students;
                for ($i = 0; $i < 16; $i++) {
                    $idx = ($start_index + $i) % $total_students;
                    $orig_code = $all_students[$idx]['student_code'];
                    
                    $swap_key = $check_date . '_' . $orig_code;
                    $has_swap = isset($swaps_upcoming[$swap_key]);
                    $is_my_duty = false;
                    $duty_label_extra = "";
                    
                    if ($has_swap) {
                        // เราเป็นคนไปเข้าแทน
                        if ($swaps_upcoming[$swap_key]['replacement_code'] === $login_student_code) {
                            $is_my_duty = true;
                            $duty_label_extra = " (เข้าแทน)";
                        }
                    } else {
                        // เวรปกติของเรา
                        if ($orig_code === $login_student_code) {
                            $is_my_duty = true;
                        }
                    }
                    
                    if ($is_my_duty) {
                        // เพิ่มตัวนับแจ้งเตือนเฉพาะล่วงหน้า 1 วัน และ 3 วัน
                        if ($offset == 1 || $offset == 3) {
                            $duty_notifications_count++;
                        }
                        
                        // สร้างข้อความแจ้งเตือนเข้า Modal
                        $time_text = ($offset == 0) ? "วันนี้!" : (($offset == 1) ? "พรุ่งนี้" : "ในอีก {$offset} วัน");
                        $shift_idx = floor($i / $locations_count);
                        $loc_idx = $i % $locations_count;
                        $m_num = date('m', $check_ts);
                        $y_num = date('Y', $check_ts) + 543;
                        
                        $upcoming_alerts[] = [
                            'time_text' => $time_text,
                            'full_date' => date('j', $check_ts) . " " . ($thai_months[$m_num] ?? '') . " " . $y_num,
                            'shift' => $shift_labels[$shift_idx] . $duty_label_extra,
                            'location' => $location_names[$loc_idx]
                        ];
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log("Alerts Error: " . $e->getMessage());
}

$noti_count = $duty_notifications_count + (!$has_voted ? 1 : 0);
$upcoming_alerts_json = json_encode($upcoming_alerts);

// ---------------- 6. คำนวณเวรประจำเดือนที่ผู้ใช้เลือกดู ----------------
$selected_month = date('Y-m');
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    $selected_month = $_GET['month'];
}

$first_day_of_month = $selected_month . "-01";
$days_in_month = date('t', strtotime($first_day_of_month));

/* ดึงข้อมูลการแลกเวร/แทนเวรในเดือนนี้ที่ได้รับการอนุมัติแล้ว */
$swaps_by_date_and_orig = [];
try {
    $stmt_swaps = $conn->prepare("
        SELECT 
            s.duty_date, 
            s.requester_code, 
            s.replacement_code, 
            s.status,
            req.prefix AS req_prefix, req.first_name AS req_first_name, req.last_name AS req_last_name,
            rep.prefix AS rep_prefix, rep.first_name AS rep_first_name, rep.last_name AS rep_last_name
        FROM duty_swaps s
        LEFT JOIN students req ON s.requester_code = req.student_code
        LEFT JOIN students rep ON s.replacement_code = rep.student_code
        WHERE DATE_FORMAT(s.duty_date, '%Y-%m') = ? 
          AND (s.status = 'approved' OR s.status = '1')
    ");
    if ($stmt_swaps) {
        $stmt_swaps->bind_param("s", $selected_month);
        $stmt_swaps->execute();
        $res_swaps = $stmt_swaps->get_result();
        while ($row_swap = $res_swaps->fetch_assoc()) {
            $swaps_by_date_and_orig[$row_swap['duty_date'] . '_' . $row_swap['requester_code']] = $row_swap;
        }
        $stmt_swaps->close();
    }
} catch (Throwable $e) {
    error_log("Swap Fetch Error: " . $e->getMessage());
}

$current_m_num = date('m', strtotime($first_day_of_month));
$current_y_num = date('Y', strtotime($first_day_of_month)) + 543;
$month_display_text = ($thai_months[$current_m_num] ?? '') . " " . $current_y_num;

$my_monthly_duties = [];
if ($total_students > 0) {
    for ($d = 1; $d <= $days_in_month; $d++) {
        $eval_date = sprintf("%s-%02d", $selected_month, $d);
        $eval_timestamp = strtotime($eval_date);
        $diff_days = floor((($eval_timestamp - $start_timestamp) / 86400));
        
        if ($diff_days < 0) continue;
        
        $start_index = ($diff_days * 16) % $total_students;
        
        for ($i = 0; $i < 16; $i++) {
            $idx = ($start_index + $i) % $total_students;
            $orig_code = $all_students[$idx]['student_code'];
            
            $shift_idx = floor($i / $locations_count);
            $loc_idx = $i % $locations_count;
            $day_of_week = date('w', $eval_timestamp);

            $swap_key = $eval_date . '_' . $orig_code;
            $has_swap = isset($swaps_by_date_and_orig[$swap_key]);

            if ($has_swap) {
                $swap_info = $swaps_by_date_and_orig[$swap_key];
                $rep_code = $swap_info['replacement_code'];
                $req_name = trim(($swap_info['req_prefix'] ?? '') . $swap_info['req_first_name'] . ' ' . $swap_info['req_last_name']);
                $rep_name = trim(($swap_info['rep_prefix'] ?? '') . $swap_info['rep_first_name'] . ' ' . $swap_info['rep_last_name']);

                // 1. กรณีเราเป็นผู้ไปเข้าแทนคนอื่น
                if ($rep_code === $login_student_code) {
                    $my_monthly_duties[] = [
                        'date' => $eval_date,
                        'day_num' => $d,
                        'day_name' => $thai_days_short[$day_of_week],
                        'shift' => $shift_labels[$shift_idx],
                        'location' => $location_names[$loc_idx],
                        'duty_type' => 'substitute',
                        'duty_type_label' => 'แทนเวร',
                        'note' => 'เข้าแทน: ' . $req_name
                    ];
                } 
                // 2. กรณีเป็นเวรเดิมของเรา แต่มีคนมาเข้าแทน
                else if ($orig_code === $login_student_code) {
                    $my_monthly_duties[] = [
                        'date' => $eval_date,
                        'day_num' => $d,
                        'day_name' => $thai_days_short[$day_of_week],
                        'shift' => $shift_labels[$shift_idx],
                        'location' => $location_names[$loc_idx],
                        'duty_type' => 'replaced',
                        'duty_type_label' => 'มีคนเข้าแทน',
                        'note' => 'ผู้เข้าแทน: ' . $rep_name
                    ];
                }
            } else {
                // 3. เวรปกติที่ไม่ได้รับการเปลี่ยนตัว
                if ($orig_code === $login_student_code) {
                    $my_monthly_duties[] = [
                        'date' => $eval_date,
                        'day_num' => $d,
                        'day_name' => $thai_days_short[$day_of_week],
                        'shift' => $shift_labels[$shift_idx],
                        'location' => $location_names[$loc_idx],
                        'duty_type' => 'normal',
                        'duty_type_label' => 'เวรปกติ',
                        'note' => ''
                    ];
                }
            }
        }
    }
}

$prev_month = date('Y-m', strtotime($first_day_of_month . ' -1 month'));
$next_month = date('Y-m', strtotime($first_day_of_month . ' +1 month'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#f6f8fc" />
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <title>เวรของฉัน - MTTS</title>
  
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #5a42a0;
      --primary-light: #7a60d8;
      --secondary: #f43f5e;
      --bg-color: #f6f8fc;
      --card-bg: #ffffff;
      --text-dark: #1e293b;
      --text-gray: #64748b;
      --border-light: #e2e8f0;
      --safe-area-bottom: env(safe-area-inset-bottom, 20px);
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; display: flex; justify-content: center; user-select: none; -webkit-font-smoothing: antialiased; }
    ::-webkit-scrollbar { display: none; }

    #page-loader { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: #ffffff; z-index: 9999; display: flex; flex-direction: column; justify-content: center; align-items: center; transition: opacity 0.5s ease, visibility 0.5s ease; }
    #page-loader.hidden { opacity: 0; visibility: hidden; }
    .loader-logo { width: 80px; height: 80px; border-radius: 15px; animation: pulse 1.5s infinite ease-in-out; }
    .loader-spinner { margin-top: 20px; width: 40px; height: 40px; border: 4px solid rgba(90, 66, 160, 0.2); border-top: 4px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
    .loader-text { margin-top: 15px; color: var(--primary); font-weight: 600; font-size: 0.9rem; letter-spacing: 1px; animation: flash 1.5s infinite; }

    @keyframes pulse { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(90, 66, 160, 0.4); } 50% { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(90, 66, 160, 0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(90, 66, 160, 0); } }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    @keyframes flash { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }

    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; position: relative; padding-bottom: calc(90px + var(--safe-area-bottom)); box-shadow: 0 0 20px rgba(0,0,0,0.1); overflow-x: hidden; overflow-y: auto; }
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--bg-color); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    .noti-btn { position: relative; background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer; }
    .noti-badge { position: absolute; top: -2px; right: -2px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bg-color); }

    .student-card { background: var(--primary); border-radius: 16px; padding: 20px; margin: 5px 20px 20px; color: white; box-shadow: 0 10px 20px rgba(90, 66, 160, 0.2); }
    .badges { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .badge-item { background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.4); padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 500; backdrop-filter: blur(4px); }
    .student-card h2 { font-size: 1.3rem; font-weight: 600; margin: 0 0 4px 0; }
    .student-card p { font-size: 0.85rem; margin: 0; opacity: 0.9; }

    .month-navigator { display: flex; align-items: center; justify-content: space-between; background: var(--card-bg); margin: 0 20px 20px; padding: 8px; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .nav-btn { width: 42px; height: 42px; border-radius: 12px; background: var(--bg-color); display: flex; align-items: center; justify-content: center; color: var(--text-dark); text-decoration: none; transition: all 0.2s; }
    .nav-btn:active { transform: scale(0.9); background: #e2e8f0; }
    .month-info { text-align: center; flex: 1; position: relative; }
    .month-info h3 { font-size: 0.95rem; color: var(--text-dark); font-weight: 700; }
    .duty-count { font-size: 0.7rem; color: var(--primary); background: #f3e8ff; padding: 2px 10px; border-radius: 10px; display: inline-block; margin-bottom: 4px; font-weight: 600; }
    .hidden-month-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }

    .section-title { display: flex; align-items: center; justify-content: space-between; margin: 0 20px 15px; }
    .title-left { display: flex; align-items: center; gap: 10px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    .duty-container { padding: 0 20px; display: flex; flex-direction: column; gap: 12px; }
    .duty-item-card { background: var(--card-bg); border-radius: 16px; padding: 14px; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.03); position: relative; overflow: hidden; animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; transform: translateY(20px); }
    .duty-item-card::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 4px; background: var(--primary); }
    @keyframes slideUp { to { opacity: 1; transform: translateY(0); } }
    .duty-date-box { background: #f3e8ff; color: var(--primary); border-radius: 12px; padding: 10px; text-align: center; min-width: 60px; display: flex; flex-direction: column; justify-content: center; align-items: center; }
    .duty-date-box .day-num { font-size: 1.3rem; font-weight: 700; line-height: 1; }
    .duty-date-box .day-name { font-size: 0.65rem; font-weight: 600; margin-top: 4px; text-transform: uppercase; }
    .duty-details { flex-grow: 1; }
    .shift-badge { display: inline-block; background: #ecfdf5; color: #10b981; padding: 3px 10px; border-radius: 8px; font-size: 0.7rem; font-weight: 600; margin-bottom: 6px; }
    
    .type-badge { display: inline-block; padding: 3px 10px; border-radius: 8px; font-size: 0.7rem; font-weight: 600; margin-bottom: 6px; }
    .type-badge.normal { background: #e0f2fe; color: #0284c7; }
    .type-badge.substitute { background: #fef3c7; color: #d97706; }
    .type-badge.replaced { background: #f1f5f9; color: #64748b; text-decoration: line-through; }
    
    .location-name { font-weight: 600; color: var(--text-dark); font-size: 0.95rem; display: flex; align-items: center; gap: 6px; margin-bottom: 2px; }
    .location-name i { color: var(--secondary); font-size: 0.9rem; }
    .full-date-text { color: var(--text-gray); font-size: 0.75rem; }

    .empty-state { background: transparent; padding: 40px 20px; text-align: center; display: flex; flex-direction: column; align-items: center; }
    .empty-icon { width: 70px; height: 70px; background: var(--card-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .empty-icon i { font-size: 1.8rem; color: #cbd5e1; }
    .empty-state h4 { font-size: 1rem; color: var(--text-dark); margin-bottom: 5px; }
    .empty-state p { font-size: 0.8rem; color: var(--text-gray); }

    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }

    .alert-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(3px); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
    .alert-overlay.show { opacity: 1; visibility: visible; }
    .alert-modal { background: white; width: 85%; max-width: 350px; border-radius: 20px; padding: 25px 20px; text-align: center; box-shadow: 0 15px 30px rgba(0,0,0,0.2); transform: scale(0.8); transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    .alert-overlay.show .alert-modal { transform: scale(1); }
    .alert-icon-container { width: 60px; height: 60px; background: #ffe4e6; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; }
    .alert-icon-container i { font-size: 1.8rem; color: var(--secondary); animation: ring 2s infinite ease-in-out; }
    @keyframes ring { 0%, 100% { transform: rotate(0); } 10%, 30%, 50% { transform: rotate(-15deg); } 20%, 40% { transform: rotate(15deg); } }
    .alert-title { font-size: 1.2rem; font-weight: 700; color: var(--text-dark); margin-bottom: 15px; }
    .alert-content { text-align: left; background: #f8fafc; padding: 12px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; max-height: 200px; overflow-y: auto; }
    .alert-item { padding: 8px 0; border-bottom: 1px dashed #cbd5e1; }
    .alert-item:last-child { border-bottom: none; }
    .alert-time { color: var(--secondary); font-weight: 700; font-size: 0.95rem; }
    .alert-detail { font-size: 0.85rem; color: var(--text-dark); margin-top: 4px; }
    .alert-btn { background: var(--primary); color: white; border: none; width: 100%; padding: 12px; border-radius: 12px; font-size: 1rem; font-weight: 600; font-family: inherit; cursor: pointer; box-shadow: 0 4px 10px rgba(90, 66, 160, 0.3); }
    .alert-btn:active { transform: scale(0.98); }
  </style>
</head>
<body>

  <?php if ($show_loading): ?>
  <div id="page-loader">
    <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Loading Logo" class="loader-logo">
    <div class="loader-spinner"></div>
    <div class="loader-text">กำลังโหลดข้อมูล...</div>
  </div>
  <?php endif; ?>

  <div class="app-container">
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>ระบบบริการสารสนเทศ กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <button class="noti-btn">
        <i class="far fa-bell"></i>
        <?php if ($noti_count > 0): ?>
          <span class="noti-badge"><?= $noti_count ?></span>
        <?php endif; ?>
      </button>
    </div>

    <div class="student-card fade-in-up delay-2">
      <div class="badges">
        <div class="badge-item">ปีการศึกษา 2569</div>
        <div class="badge-item"><?= htmlspecialchars($student_unit) ?></div>
      </div>
      <h2><?= htmlspecialchars($name) ?></h2>
      <p><?= htmlspecialchars($student_code_display) ?></p>
    </div>

    <div class="month-navigator fade-in-up delay-3">
      <a href="?month=<?= htmlspecialchars($prev_month); ?>" class="nav-btn">
        <i class="fas fa-chevron-left"></i>
      </a>
      
      <div class="month-info">
        <div class="duty-count">รวมเวร <?= count($my_monthly_duties); ?> วัน</div>
        <h3><?= $month_display_text; ?> <i class="fas fa-caret-down" style="font-size:0.8rem; margin-left:4px; opacity:0.5;"></i></h3>
        
        <form action="" method="GET">
          <input type="month" name="month" class="hidden-month-input" value="<?= htmlspecialchars($selected_month) ?>" onchange="this.form.submit()">
        </form>
      </div>

      <a href="?month=<?= htmlspecialchars($next_month); ?>" class="nav-btn">
        <i class="fas fa-chevron-right"></i>
      </a>
    </div>

    <div class="section-title fade-in-up delay-4">
      <div class="title-left">
        <div class="section-pill"></div>
        <h3>ตารางปฏิบัติหน้าที่</h3>
      </div>
      <i class="fas fa-sliders-h" style="font-size: 0.9rem; color: #94a3b8;"></i>
    </div>

    <div class="duty-container fade-in-up delay-4">
      <?php if (!empty($my_monthly_duties)): ?>
        <?php foreach ($my_monthly_duties as $index =>$duty): ?>
          <div class="duty-item-card" style="animation-delay: <?= 0.4 + ($index * 0.08) ?>s;">
            <div class="duty-date-box">
              <div class="day-num"><?= sprintf("%02d", $duty['day_num']); ?></div>
              <div class="day-name"><?= $duty['day_name']; ?></div>
            </div>
            <div class="duty-details">
              <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                <span class="type-badge <?= htmlspecialchars($duty['duty_type']); ?>">
                  <?= htmlspecialchars($duty['duty_type_label']); ?>
                </span>
                <span class="shift-badge"><?= htmlspecialchars($duty['shift']); ?></span>
              </div>
              <div class="location-name">
                <i class="fas fa-map-marker-alt"></i> 
                <?= htmlspecialchars($duty['location']); ?>
              </div>
              <?php if (!empty($duty['note'])): ?>
                <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px;">
                  <i class="fas fa-user-friends"></i> <?= htmlspecialchars($duty['note']); ?>
                </div>
              <?php endif; ?>
              <div class="full-date-text">
                วันที่ <?= $duty['day_num']; ?> <?=$month_display_text; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">
            <i class="fas fa-calendar-check"></i>
          </div>
          <h4>ไม่มีรายการเข้าเวร</h4>
          <p>คุณไม่มีรายชื่อปฏิบัติหน้าที่ในเดือน <?= $month_display_text; ?></p>
        </div>
      <?php endif; ?>
    </div>

    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="duty.php" class="nav-link active">
        <i class="fas fa-calendar-alt"></i>
        <span>เวรของฉัน</span>
      </a>
    </div>

  </div>

  <div id="dutyAlertOverlay" class="alert-overlay">
    <div class="alert-modal">
      <div class="alert-icon-container">
        <i class="fas fa-bell"></i>
      </div>
      <div class="alert-title">เตรียมตัวเข้าเวร!</div>
      <div id="alertContentBox" class="alert-content"></div>
      <button class="alert-btn" onclick="closeDutyAlert()">รับทราบ</button>
    </div>
  </div>

  <script>
    const upcomingAlerts = <?= $upcoming_alerts_json; ?>;

    document.addEventListener("DOMContentLoaded", function() {
      const loader = document.getElementById('page-loader');
      
      function checkAndShowAlert() {
        if (upcomingAlerts && upcomingAlerts.length > 0 && !sessionStorage.getItem('duty_alert_shown')) {
          let contentHtml = '';
          upcomingAlerts.forEach(alert => {
            contentHtml += `
              <div class="alert-item">
                <div class="alert-time"><i class="fas fa-exclamation-circle"></i> ${alert.time_text}</div>
                <div class="alert-detail">
                  <b>${alert.full_date}</b><br>
                  ${alert.shift} - ${alert.location}
                </div>
              </div>
            `;
          });

          document.getElementById('alertContentBox').innerHTML = contentHtml;
          
          setTimeout(() => {
            document.getElementById('dutyAlertOverlay').classList.add('show');
          }, 500);
        }
      }

      if (loader) {
        setTimeout(function() {
          loader.classList.add('hidden');
          setTimeout(function() {
            loader.remove();
            checkAndShowAlert();
          }, 100);
        }, 1200);
      } else {
        checkAndShowAlert();
      }
    });

    function closeDutyAlert() {
      document.getElementById('dutyAlertOverlay').classList.remove('show');
      sessionStorage.setItem('duty_alert_shown', 'true');
    }
  </script>
</body>
</html>
