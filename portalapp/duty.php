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

/* ตรวจสอบการเชื่อมต่อฐานข้อมูล */
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("เกิดข้อผิดพลาด: ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
}
$conn->set_charset("utf8mb4");

// ---------------- 2. ดึงข้อมูลส่วนตัวนักเรียนที่ล็อกอิน (สำหรับ Profile Header) ----------------
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
    // จัดการข้อผิดพลาดเงียบๆ หรือบันทึก Log
}

/* กำหนดตัวแปรแสดงผล Profile */
$name = $student_profile ? trim(($student_profile['prefix'] ?? '') . $student_profile['first_name'] . ' ' . $student_profile['last_name']) : "นักเรียนทหาร";
$student_code_display = "รหัสนักเรียน " . $login_student_code;
$profile_image = $student_profile['image'] ?? '';

$unit_parts = [];
if (!empty($student_profile['battalion'])) $unit_parts[] = "พัน." . $student_profile['battalion'];
if (!empty($student_profile['company']))   $unit_parts[] = "ร้อย." . $student_profile['company'];
if (!empty($student_profile['platoon']))   $unit_parts[] = "หมวด " . $student_profile['platoon'];
if (!empty($student_profile['section']))   $unit_parts[] = "ตอนเรียนที่ " . $student_profile['section'];
$student_unit = !empty($unit_parts) ? implode(" ", $unit_parts) : "นักเรียนทหาร";

if (empty($profile_image) || $profile_image === "default_student.png" || $profile_image === "0") {
    $profile_image = "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=fff&color=5a42a0&size=128&bold=true";
}

// ---------------- 3. ระบบกำหนดเดือน และคำนวณเวรประจำเดือน ----------------
/* Validate รูปแบบเดือนให้เป็น YYYY-MM เท่านั้น เพื่อความปลอดภัย */
$selected_month = date('Y-m');
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    $selected_month = $_GET['month'];
}

$first_day_of_month = $selected_month . "-01";
$days_in_month = date('t', strtotime($first_day_of_month));
$start_date = "2026-07-06"; // วันเริ่มต้นคำนวณเวรหลักการหมุนเวียน

$all_students = [];
$error_db = false;

try {
    $res = $conn->query("SELECT student_code, prefix, first_name, last_name, section FROM students ORDER BY id ASC");
    if($res && $res->num_rows > 0) {
        while($row = $res->fetch_assoc()) {
            $all_students[] = $row;
        }
    }
} catch (Exception $e) {
    $error_db = true;
}

$total_students = count($all_students);

// ข้อมูลสำหรับจัดผลัด/จุดรักษาการณ์
$locations_count = 4;
$location_names = [
    "จุดที่ 4 อาคารยานยนต์", 
    "จุดที่ 5 ผฟอ.", 
    "จุดที่ 6 กศน.", 
    "จุดที่ 7 ศาลพระปิ่น"
];
$shift_labels = ["ผลัดที่ 1", "ผลัดที่ 2", "ผลัดที่ 3", "ผลัดที่ 4"];

$thai_months = [
    "01" => "มกราคม", "02" => "กุมภาพันธ์", "03" => "มีนาคม", "04" => "เมษายน",
    "05" => "พฤษภาคม", "06" => "มิถุนายน", "07" => "กรกฎาคม", "08" => "สิงหาคม",
    "09" => "กันยายน", "10" => "ตุลาคม", "11" => "พฤศจิกายน", "12" => "ธันวาคม"
];
$thai_days_short = ["อาทิตย์", "จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์"];

$current_m_num = date('m', strtotime($first_day_of_month));
$current_y_num = date('Y', strtotime($first_day_of_month)) + 543;
$month_display_text = ($thai_months[$current_m_num] ?? '') . " " . $current_y_num;

// วนลูปตรวจสอบทุกวันในเดือนที่เลือกเพื่อหาว่ามีเวรวันไหนบ้าง
$my_monthly_duties = [];

if ($total_students > 0) {
    $start_timestamp = strtotime($start_date);
    
    for ($d = 1; $d <= $days_in_month; $d++) {
        $eval_date = sprintf("%s-%02d", $selected_month, $d);
        $eval_timestamp = strtotime($eval_date);
        
        $diff_days = floor((($eval_timestamp - $start_timestamp) / 86400));
        if ($diff_days < 0) continue;
        
        $start_index = ($diff_days * 16) % $total_students;
        
        for ($i = 0; $i < 16; $i++) {
            $idx = ($start_index + $i) % $total_students;
            if (isset($all_students[$idx]) && $all_students[$idx]['student_code'] === $login_student_code) {
                $shift_idx = floor($i / $locations_count);
                $loc_idx = $i % $locations_count;
                $day_of_week = date('w', $eval_timestamp);
                
                $my_monthly_duties[] = [
                    'date' => $eval_date,
                    'day_num' => $d,
                    'day_name' => $thai_days_short[$day_of_week],
                    'shift' => $shift_labels[$shift_idx],
                    'location' => $location_names[$loc_idx]
                ];
                break; // เจอเวรของตัวเองในวันนี้แล้ว ข้ามไปหาวันอื่นต่อเลย
            }
        }
    }
}

// ปุ่มเปลี่ยนเดือนก่อนหน้า / ถัดไป
$prev_month = date('Y-m', strtotime($first_day_of_month . ' -1 month'));
$next_month = date('Y-m', strtotime($first_day_of_month . ' +1 month'));
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#f8fafc" />
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <title>ตารางเวรประจำเดือน - MTTS</title>
  
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #5b40b8;
      --primary-light: #7a60d8;
      --secondary: #f43f5e;
      --bg-color: #f8fafc;
      --card-bg: #ffffff;
      --text-dark: #0f172a;
      --text-gray: #64748b;
      --border-light: #e2e8f0;
      --safe-area-bottom: env(safe-area-inset-bottom, 20px);
    }

    * { 
      box-sizing: border-box; 
      -webkit-tap-highlight-color: transparent; 
      margin: 0; 
      padding: 0;
    }
    
    body {
      font-family: 'Prompt', sans-serif;
      background-color: #e2e8f0;
      display: flex;
      justify-content: center;
      user-select: none;
      -webkit-font-smoothing: antialiased;
    }

    ::-webkit-scrollbar { display: none; }
    
    .app-container {
      width: 100%;
      max-width: 430px;
      background-color: var(--bg-color);
      min-height: 100vh;
      position: relative;
      padding-bottom: calc(90px + var(--safe-area-bottom));
      box-shadow: 0 0 30px rgba(0,0,0,0.15);
      overflow-x: hidden;
      overflow-y: auto;
    }

    /* Top Header */
    .top-header {
      position: sticky;
      top: 0;
      z-index: 50;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 15px 20px;
      background: rgba(248, 250, 252, 0.85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(0,0,0,0.05);
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .school-logo {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      object-fit: cover;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .school-name h1 {
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-dark);
      margin-bottom: 2px;
    }

    .school-name p {
      font-size: 0.75rem;
      color: var(--text-gray);
    }

    .header-action-btn {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-dark);
      text-decoration: none;
      font-size: 1.1rem;
    }

    .student-card {
      background: var(--primary);
      border-radius: 16px;
      padding: 20px;
      margin: 5px 20px 20px;
      color: white;
      box-shadow: 0 10px 20px rgba(90, 66, 160, 0.2);
    }

    .badges {
      display: flex;
      gap: 8px;
      margin-bottom: 12px;
    }

    .badge-item {
      background: rgba(255, 255, 255, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.4);
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
      backdrop-filter: blur(4px);
    }

    .student-card h2 {
      font-size: 1.3rem;
      font-weight: 600;
      margin: 0 0 4px 0;
    }

    .student-card p {
      font-size: 0.85rem;
      margin: 0;
      opacity: 0.9;
    }
    
    .profile-wrap {
      display: flex;
      align-items: center;
      gap: 15px;
      position: relative;
      z-index: 2;
    }

    .profile-img {
      width: 65px;
      height: 65px;
      border-radius: 18px;
      border: 2px solid rgba(255,255,255,0.4);
      background-color: white;
      object-fit: cover;
    }

    .profile-info {
      flex: 1;
    }

    .profile-info .badges {
      display: flex;
      gap: 6px;
      margin-bottom: 6px;
      flex-wrap: wrap;
    }

    .profile-info .badge-item {
      background: rgba(255, 255, 255, 0.2);
      padding: 3px 10px;
      border-radius: 12px;
      font-size: 0.65rem;
      font-weight: 600;
      backdrop-filter: blur(4px);
      letter-spacing: 0.3px;
    }

    .profile-info h2 {
      font-size: 1.15rem;
      font-weight: 600;
      margin-bottom: 2px;
    }

    .profile-info p {
      font-size: 0.8rem;
      opacity: 0.9;
    }

    /* Month Navigator */
    .month-navigator {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: var(--card-bg);
      margin: 0 20px 20px;
      padding: 8px;
      border-radius: 16px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    }

    .nav-btn {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: var(--bg-color);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-dark);
      text-decoration: none;
      transition: all 0.2s;
    }

    .nav-btn:active {
      transform: scale(0.9);
      background: #e2e8f0;
    }

    .month-info {
      text-align: center;
      flex: 1;
      position: relative;
    }

    .month-info h3 {
      font-size: 1rem;
      color: var(--text-dark);
      font-weight: 700;
    }

    .duty-count {
      font-size: 0.7rem;
      color: var(--primary);
      background: #f3e8ff;
      padding: 2px 10px;
      border-radius: 10px;
      display: inline-block;
      margin-bottom: 4px;
      font-weight: 600;
    }

    .hidden-month-input {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      opacity: 0;
      cursor: pointer;
    }

    /* Section Title */
    .section-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 0 20px 15px;
    }

    .title-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .section-pill {
      width: 4px;
      height: 20px;
      background: var(--primary);
      border-radius: 4px;
    }

    .section-title h3 {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-dark);
    }

    /* Duty Cards */
    .duty-container {
      padding: 0 20px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .duty-item-card {
      background: var(--card-bg);
      border-radius: 16px;
      padding: 12px;
      display: flex;
      align-items: center;
      gap: 15px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.02);
      border: 1px solid rgba(0,0,0,0.03);
      position: relative;
      overflow: hidden;
      animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      opacity: 0;
      transform: translateY(20px);
    }

    .duty-item-card::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      height: 100%;
      width: 4px;
      background: var(--primary);
    }

    @keyframes slideUp {
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .duty-date-box {
      background: rgba(91, 64, 184, 0.08);
      color: var(--primary);
      border-radius: 12px;
      padding: 10px;
      text-align: center;
      min-width: 60px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
    }

    .duty-date-box .day-num {
      font-size: 1.4rem;
      font-weight: 700;
      line-height: 1;
    }

    .duty-date-box .day-name {
      font-size: 0.65rem;
      font-weight: 600;
      margin-top: 4px;
      text-transform: uppercase;
    }

    .duty-details {
      flex-grow: 1;
    }

    .shift-badge {
      display: inline-block;
      background: #ecfdf5;
      color: #10b981;
      padding: 3px 10px;
      border-radius: 8px;
      font-size: 0.7rem;
      font-weight: 600;
      margin-bottom: 6px;
    }

    .location-name {
      font-weight: 600;
      color: var(--text-dark);
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 2px;
    }

    .location-name i {
      color: var(--secondary);
      font-size: 0.9rem;
    }

    .full-date-text {
      color: var(--text-gray);
      font-size: 0.75rem;
    }

    /* Empty State */
    .empty-state {
      background: transparent;
      padding: 40px 20px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .empty-icon {
      width: 70px;
      height: 70px;
      background: var(--card-bg);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 15px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    
    .empty-icon i {
      font-size: 1.8rem;
      color: #cbd5e1;
    }

    .empty-state h4 {
      font-size: 1rem;
      color: var(--text-dark);
      margin-bottom: 5px;
    }

    .empty-state p {
      font-size: 0.8rem;
      color: var(--text-gray);
    }

    /* Bottom Navigation */
    .bottom-nav {
      position: fixed;
      bottom: 0;
      width: 100%;
      max-width: 430px;
      background: rgba(255, 255, 255, 0.9);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      display: flex;
      justify-content: space-around;
      align-items: center;
      box-shadow: 0 -5px 25px rgba(0,0,0,0.04);
      padding: 10px 10px calc(15px + var(--safe-area-bottom));
      z-index: 100;
      border-top-left-radius: 24px;
      border-top-right-radius: 24px;
      border: 1px solid rgba(255,255,255,0.5);
    }

    .nav-link {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-decoration: none;
      color: #94a3b8;
      font-size: 0.7rem;
      font-weight: 500;
      width: 33%;
      gap: 6px;
      transition: all 0.2s;
      position: relative;
    }

    .nav-link i {
      font-size: 1.35rem;
      transition: transform 0.2s;
    }

    .nav-link.active {
      color: var(--primary);
      font-weight: 600;
    }

    .nav-link.active i {
      transform: translateY(-2px);
    }

    .nav-link.active::after {
      content: '';
      position: absolute;
      top: -10px;
      width: 4px;
      height: 4px;
      background: var(--primary);
      border-radius: 50%;
    }
    
    .nav-link:active i {
      transform: scale(0.85);
    }
  </style>
</head>
<body>

  <div class="app-container">
    
    <!-- Top Header -->
    <div class="top-header">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>ระบบบริการสารสนเทศ กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <a href="#" class="header-action-btn">
        <i class="far fa-bell"></i>
      </a>
    </div>

        <div class="student-card">
      <div class="badges">
        <div class="badge-item">ปีการศึกษา 2569</div>
        <div class="badge-item"><?= htmlspecialchars($student_unit) ?></div>
      </div>
      <h2><?= htmlspecialchars($name) ?></h2>
      <p><?= htmlspecialchars($student_code_display) ?></p>
    </div>
    <!-- แถบเลือกเดือน -->
    <div class="month-navigator">
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

    <!-- หัวข้อรายการเวรประจำเดือน -->
    <div class="section-title">
      <div class="title-left">
        <div class="section-pill"></div>
        <h3>ตารางปฏิบัติหน้าที่</h3>
      </div>
      <i class="fas fa-sliders-h text-muted" style="font-size: 0.9rem;"></i>
    </div>

    <!-- รายการเวร -->
    <div class="duty-container mb-4">
      <?php if (!empty($my_monthly_duties)): ?>
        
        <?php foreach ($my_monthly_duties as $index => $duty): ?>
          <div class="duty-item-card" style="animation-delay: <?= $index * 0.08 ?>s;">
            <div class="duty-date-box">
              <div class="day-num"><?= sprintf("%02d", $duty['day_num']); ?></div>
              <div class="day-name"><?= $duty['day_name']; ?></div>
            </div>
            <div class="duty-details">
              <div class="shift-badge"><?= htmlspecialchars($duty['shift']); ?></div>
              <div class="location-name">
                <i class="fas fa-map-marker-alt"></i> 
                <?= htmlspecialchars($duty['location']); ?>
              </div>
              <div class="full-date-text">
                วันที่ <?= $duty['day_num']; ?> <?= $month_display_text; ?>
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

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      
      <a href="duty.php" class="nav-link active">
        <i class="fas fa-calendar-alt"></i>
        <span>เวรของฉัน</span>
      </a>

      <a href="logout.php" class="nav-link">
        <i class="far fa-user"></i>
        <span>ออกจากระบบ</span>
      </a>
    </div>

  </div>

</body>
</html>
