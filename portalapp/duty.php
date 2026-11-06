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
    // ดึงทุกคนรวมคนลาออก (ห้ามลบแถว) เพื่อให้ลำดับเดิมคงอยู่ แล้วใช้ status ตัดสินว่าข้ามหรือไม่
    $res = $conn->query("SELECT student_code, prefix, first_name, last_name, section, status FROM students ORDER BY id ASC");
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

// ---------------- 4.1 สร้างตารางเวรโดยข้ามคนที่ status = 'resigned' ----------------
// ก่อนวันที่นี้ใช้ลำดับเดิมทั้งหมด (เวรย้อนหลังไม่เปลี่ยน) ตั้งแต่วันนี้เป็นต้นไปข้ามคนลาออก
$skip_resigned_from = "2026-10-31";

function buildDutySchedule(array $students, string $start_date, string $end_date, string $skip_from, int $per_day = 16): array {
    $schedule = [];
    $total = count($students);
    if ($total === 0) return $schedule;

    $p = 0; // ตัวชี้ลำดับในรายชื่อเต็ม (รวมคนลาออก)
    $end_ts = strtotime($end_date);
    for ($ts = strtotime($start_date); $ts <= $end_ts; $ts += 86400) {
        $date = date('Y-m-d', $ts);
        $slots = [];
        for ($i = 0; $i < $per_day; $i++) {
            $tries = 0;
            while ($tries < $total) {
                $s = $students[$p];
                $p = ($p + 1) % $total;
                $tries++;
                $is_resigned = (($s['status'] ?? 'studying') === 'resigned');
                // ข้ามเฉพาะคนลาออก และเฉพาะวันที่ตั้งแต่ $skip_from เป็นต้นไป
                if ($is_resigned && $date >= $skip_from) continue;
                $slots[$i] = $s['student_code'];
                break;
            }
        }
        $schedule[$date] = $slots;
    }
    return $schedule;
}

$sel_month_tmp = (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) ? $_GET['month'] : date('Y-m');
$end_calc = max(
    date('Y-m-d', strtotime('+3 days')),
    date('Y-m-t', strtotime($sel_month_tmp . '-01'))
);
$duty_schedule = buildDutySchedule($all_students, $start_date, $end_calc, $skip_resigned_from);

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
            
            if ($diff_days >= 0 && isset($duty_schedule[$check_date])) {
                foreach ($duty_schedule[$check_date] as $i => $orig_code) {
                    
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
        
        if ($diff_days < 0 || !isset($duty_schedule[$eval_date])) continue;
        
        foreach ($duty_schedule[$eval_date] as $i => $orig_code) {
            
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
  <meta name="theme-color" content="#503896" />
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>เวรของฉัน - MTTS</title>

  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    :root{--primary:#503896;--bar:#503896;--bg:#f2f3f8;--ink:#1c1b29;--mute:#6b7280;--danger:#ef4444;--g:16px;--nav:64px;--st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);--shadow:0 1px 2px rgba(20,20,43,.04),0 2px 8px rgba(20,20,43,.04)}
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;margin:0;padding:0}
    html{-webkit-text-size-adjust:100%}
    body{font-family:'Prompt',sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100dvh;-webkit-user-select:none;user-select:none;overscroll-behavior-y:none;touch-action:manipulation;-webkit-font-smoothing:antialiased}
    ::-webkit-scrollbar{display:none}
    .app-container{width:100%;max-width:430px;min-height:100dvh;background:var(--bg);padding-bottom:calc(var(--nav) + var(--sb) + 16px)}
    @media(min-width:431px){.app-container{box-shadow:0 0 40px rgba(15,23,42,.14)}}

    /* animation */
    .fade-in-up{opacity:0;transform:translateY(14px);animation:fadeInUp .45s cubic-bezier(.16,1,.3,1) forwards}
    .delay-2{animation-delay:.08s}.delay-3{animation-delay:.16s}.delay-4{animation-delay:.24s}
    @keyframes fadeInUp{to{opacity:1;transform:none}}
    @keyframes spin{to{transform:rotate(360deg)}}
    @keyframes pulse{50%{transform:scale(1.05)}}
    @keyframes ring{0%,100%{transform:rotate(0)}10%,30%,50%{transform:rotate(-15deg)}20%,40%{transform:rotate(15deg)}}
    @media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.fade-in-up,.duty-row{opacity:1;transform:none}}

    /* splash */
    #page-loader{position:fixed;inset:0;z-index:9999;background:linear-gradient(160deg,#503896,#3d2b78);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;transition:opacity .5s,visibility .5s}
    #page-loader.hidden{opacity:0;visibility:hidden}
    .loader-logo{width:88px;height:88px;padding:6px;border-radius:22px;background:#fff;animation:pulse 1.5s infinite ease-in-out}
    .loader-spinner{width:34px;height:34px;border-radius:50%;border:4px solid rgba(255,255,255,.25);border-top-color:#fff;animation:spin 1s linear infinite}
    .loader-text{color:rgba(255,255,255,.85);font-size:.85rem;font-weight:500}

    /* app bar */
    .top-header{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;padding:calc(10px + var(--st)) var(--g) 10px;background:rgba(80,56,150,.94);-webkit-backdrop-filter:saturate(180%) blur(14px);backdrop-filter:saturate(180%) blur(14px);color:#fff}
    .header-left{display:flex;align-items:center;gap:12px;min-width:0}
    .school-logo{width:40px;height:40px;flex:none;border-radius:50%;object-fit:contain;background:#fff;padding:2px}
    .school-name{min-width:0}
    .school-name h1{font-size:.95rem;font-weight:600;line-height:1.25}
    .school-name p{font-size:.72rem;opacity:.8}
    .noti-btn{position:relative;flex:none;display:flex;align-items:center;justify-content:center;width:40px;height:40px;border:0;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.15rem;cursor:pointer;transition:transform .12s}
    .noti-btn:active{transform:scale(.92)}
    .noti-badge{position:absolute;top:-2px;right:-2px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:var(--danger);color:#fff;font-size:.65rem;font-weight:700;border:2px solid var(--bar);display:flex;align-items:center;justify-content:center}

    /* student row */
    .student-card{display:flex;align-items:center;gap:14px;margin:16px var(--g) 14px;padding:16px;border-radius:20px;background:#fff;box-shadow:var(--shadow);color:inherit;text-decoration:none;transition:transform .12s}
    .student-card:active{transform:scale(.98)}
    .student-info{flex:1;min-width:0}
    .student-info h2{font-size:1.05rem;font-weight:600;line-height:1.3}
    .student-info p{margin:2px 0 8px;font-size:.8rem;color:var(--mute)}
    .badges{display:flex;flex-wrap:wrap;gap:6px}
    .badge-item{padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:500;background:#efeaf9;color:var(--primary)}
    .student-chevron{flex:none;color:#c3c7d4;font-size:.9rem}

    /* month navigator */
    .month-navigator{display:flex;align-items:center;justify-content:space-between;margin:0 var(--g) 20px;padding:6px;border-radius:18px;background:#fff;box-shadow:var(--shadow)}
    .nav-btn{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;background:var(--bg);color:var(--ink);text-decoration:none;transition:transform .12s}
    .nav-btn:active{transform:scale(.9)}
    .month-info{position:relative;flex:1;text-align:center}
    .month-info h3{font-size:.95rem;font-weight:700}
    .duty-count{display:inline-block;margin-bottom:2px;padding:2px 10px;border-radius:10px;background:#efeaf9;color:var(--primary);font-size:.7rem;font-weight:600}
    .hidden-month-input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}

    /* section title */
    .section-title{margin:0 calc(var(--g) + 4px) 8px}
    .section-title h2{font-size:.8rem;font-weight:600;letter-spacing:.02em;color:var(--mute)}

    /* duty list: grouped list */
    .duty-container{margin:0 var(--g);border-radius:20px;background:#fff;box-shadow:var(--shadow);overflow:hidden}
    .duty-row{display:flex;align-items:center;gap:14px;padding:14px;opacity:0;transform:translateY(12px);animation:fadeInUp .45s cubic-bezier(.16,1,.3,1) forwards}
    .duty-row+.duty-row{border-top:1px solid #eef0f5}
    .duty-date-box{flex:none;display:flex;flex-direction:column;align-items:center;justify-content:center;width:56px;padding:9px 0;border-radius:14px;background:#efeaf9;color:var(--primary);text-align:center}
    .duty-date-box .day-num{font-size:1.25rem;font-weight:700;line-height:1}
    .duty-date-box .day-name{margin-top:4px;font-size:.62rem;font-weight:600}
    .duty-details{flex:1;min-width:0}
    .tags{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:6px}
    .tag{padding:2px 10px;border-radius:8px;font-size:.68rem;font-weight:600}
    .tag.shift{background:#ecfdf5;color:#059669}
    .tag.normal{background:#e0f2fe;color:#0284c7}
    .tag.substitute{background:#fef3c7;color:#b45309}
    .tag.replaced{background:#f1f5f9;color:var(--mute);text-decoration:line-through}
    .location-name{display:flex;align-items:center;gap:6px;font-size:.92rem;font-weight:600}
    .location-name i{color:var(--danger);font-size:.85rem}
    .duty-note{margin-top:3px;font-size:.74rem;color:var(--mute)}
    .full-date-text{margin-top:2px;font-size:.72rem;color:var(--mute)}

    .empty-state{display:flex;flex-direction:column;align-items:center;padding:44px 20px;text-align:center}
    .empty-icon{display:grid;place-items:center;width:64px;height:64px;margin-bottom:12px;border-radius:50%;background:#f1f2f8}
    .empty-icon i{font-size:1.6rem;color:#c3c7d4}
    .empty-state h4{margin-bottom:4px;font-size:.95rem}
    .empty-state p{font-size:.78rem;color:var(--mute)}

    /* bottom nav สีม่วง */
    .bottom-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:1000;width:100%;max-width:430px;height:calc(var(--nav) + var(--sb));padding-bottom:var(--sb);display:flex;align-items:center;background:var(--bar);box-shadow:0 -4px 18px rgba(20,20,43,.18)}
    .nav-link{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;color:rgba(255,255,255,.6);font-size:.7rem;font-weight:500;text-decoration:none}
    .nav-link i{display:grid;place-items:center;width:56px;height:30px;border-radius:15px;font-size:1.2rem;transition:background .2s}
    .nav-link.active{color:#fff;font-weight:600}
    .nav-link.active i{background:rgba(255,255,255,.2)}
    .nav-link:active{color:#fff}

    /* duty alert modal */
    .alert-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);opacity:0;visibility:hidden;transition:all .3s ease}
    .alert-overlay.show{opacity:1;visibility:visible}
    .alert-modal{width:85%;max-width:350px;padding:24px 20px 20px;border-radius:24px;background:#fff;text-align:center;box-shadow:0 15px 30px rgba(0,0,0,.2);transform:scale(.85);transition:transform .3s cubic-bezier(.175,.885,.32,1.275)}
    .alert-overlay.show .alert-modal{transform:scale(1)}
    .alert-icon-container{display:grid;place-items:center;width:56px;height:56px;margin:0 auto 12px;border-radius:50%;background:#fee2e2}
    .alert-icon-container i{font-size:1.6rem;color:var(--danger);animation:ring 2s infinite ease-in-out}
    .alert-title{margin-bottom:14px;font-size:1.1rem;font-weight:700}
    .alert-content{max-height:200px;margin-bottom:18px;padding:4px 14px;border-radius:14px;background:#f6f7fb;text-align:left;overflow-y:auto}
    .alert-item{padding:10px 0;border-bottom:1px solid #e8eaf2}
    .alert-item:last-child{border-bottom:0}
    .alert-time{font-size:.92rem;font-weight:700;color:var(--danger)}
    .alert-detail{margin-top:4px;font-size:.82rem}
    .alert-btn{width:100%;padding:12px;border:0;border-radius:14px;background:var(--primary);color:#fff;font-family:inherit;font-size:1rem;font-weight:600;cursor:pointer;transition:transform .12s}
    .alert-btn:active{transform:scale(.97)}
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

    <header class="top-header">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <button class="noti-btn" type="button" aria-label="การแจ้งเตือน">
        <i class="far fa-bell"></i>
        <?php if ($noti_count > 0): ?>
          <span class="noti-badge"><?= (int)$noti_count ?></span>
        <?php endif; ?>
      </button>
    </header>

    <main>
      <a href="profile.php" class="student-card fade-in-up delay-2" aria-label="โปรไฟล์ของฉัน">
        <div class="student-info">
          <h2><?= htmlspecialchars($name) ?></h2>
          <p><?= htmlspecialchars($student_code_display) ?></p>
          <div class="badges">
            <div class="badge-item"><?= htmlspecialchars($student_unit) ?></div>
            <div class="badge-item">ปีการศึกษา 2569</div>
          </div>
        </div>
        <i class="fas fa-chevron-right student-chevron" aria-hidden="true"></i>
      </a>

      <div class="month-navigator fade-in-up delay-3">
        <a href="?month=<?= htmlspecialchars($prev_month); ?>" class="nav-btn" aria-label="เดือนก่อนหน้า">
          <i class="fas fa-chevron-left"></i>
        </a>

        <div class="month-info">
          <div class="duty-count">รวมเวร <?= count($my_monthly_duties); ?> วัน</div>
          <h3><?= $month_display_text; ?> <i class="fas fa-caret-down" style="font-size:.8rem;margin-left:4px;opacity:.5;"></i></h3>

          <form action="" method="GET">
            <input type="month" name="month" class="hidden-month-input" value="<?= htmlspecialchars($selected_month) ?>" onchange="this.form.submit()">
          </form>
        </div>

        <a href="?month=<?= htmlspecialchars($next_month); ?>" class="nav-btn" aria-label="เดือนถัดไป">
          <i class="fas fa-chevron-right"></i>
        </a>
      </div>

      <section class="fade-in-up delay-4">
        <div class="section-title"><h2>ตารางปฏิบัติหน้าที่</h2></div>

        <div class="duty-container">
          <?php if (!empty($my_monthly_duties)): ?>
            <?php foreach ($my_monthly_duties as $index => $duty): ?>
              <div class="duty-row" style="animation-delay: <?= min(0.25 + ($index * 0.05), 0.8) ?>s;">
                <div class="duty-date-box">
                  <div class="day-num"><?= sprintf("%02d", $duty['day_num']); ?></div>
                  <div class="day-name"><?= htmlspecialchars($duty['day_name']); ?></div>
                </div>
                <div class="duty-details">
                  <div class="tags">
                    <span class="tag <?= htmlspecialchars($duty['duty_type']); ?>"><?= htmlspecialchars($duty['duty_type_label']); ?></span>
                    <span class="tag shift"><?= htmlspecialchars($duty['shift']); ?></span>
                  </div>
                  <div class="location-name">
                    <i class="fas fa-map-marker-alt"></i>
                    <?= htmlspecialchars($duty['location']); ?>
                  </div>
                  <?php if (!empty($duty['note'])): ?>
                    <div class="duty-note"><i class="fas fa-user-friends"></i> <?= htmlspecialchars($duty['note']); ?></div>
                  <?php endif; ?>
                  <div class="full-date-text">วันที่ <?= $duty['day_num']; ?> <?= $month_display_text; ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state">
              <div class="empty-icon"><i class="fas fa-calendar-check"></i></div>
              <h4>ไม่มีรายการเข้าเวร</h4>
              <p>คุณไม่มีรายชื่อปฏิบัติหน้าที่ในเดือน <?= $month_display_text; ?></p>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </main>

  </div>

  <nav class="bottom-nav" aria-label="เมนูหลัก">
    <a href="main.php" class="nav-link">
      <i class="fas fa-home"></i>
      <span>หน้าหลัก</span>
    </a>
    <a href="duty.php" class="nav-link active">
      <i class="fas fa-calendar-alt"></i>
      <span>เวรของฉัน</span>
    </a>
  </nav>

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

      // สั่นเบาๆ ตอนแตะ (Android)
      if (navigator.vibrate) {
        document.addEventListener('click', function(ev) {
          if (ev.target.closest('a, button')) navigator.vibrate(8);
        });
      }
    });

    function closeDutyAlert() {
      document.getElementById('dutyAlertOverlay').classList.remove('show');
      sessionStorage.setItem('duty_alert_shown', 'true');
    }
  </script>
</body>
</html>
