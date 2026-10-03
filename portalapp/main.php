<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db.php';

if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

$show_loading = false;
if (!isset($_SESSION['has_seen_loading'])) {
    $show_loading = true;
    $_SESSION['has_seen_loading'] = true;
}

$studentCode = $_SESSION['student_code'];
$duty_notifications = [];
$announcements = []; 
$has_voted = true; 
$news_error = ""; 
$today_discipline = null; 
$today_disc_count = 0;
$total_cards_history = 0; 
$student = [];

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection error");
    }

    $conn->set_charset("utf8mb4");

    // 1. ดึงข้อมูลนักเรียน
    $stmt = $conn->prepare("SELECT student_code, prefix, first_name, last_name, platoon, company, battalion, section, image FROM students WHERE student_code = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $studentCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $student = $result->fetch_assoc();
        $stmt->close();
    }

    if (!$student) {
        session_destroy();
        header("Location: login.php");
        exit();
    }

    // 2. ดึงข้อมูลแจ้งเตือนเวรล่วงหน้า 3 วัน และ 1 วัน
    $stmt_duty = $conn->prepare("
        SELECT duty_name, duty_date, DATEDIFF(duty_date, CURDATE()) AS days_left 
        FROM student_duties 
        WHERE student_code = ? 
          AND DATEDIFF(duty_date, CURDATE()) IN (1, 3)
        ORDER BY duty_date ASC
    ");
    if ($stmt_duty) {
        $stmt_duty->bind_param("s", $studentCode);
        $stmt_duty->execute();
        $res_duty = $stmt_duty->get_result();
        while ($row = $res_duty->fetch_assoc()) {
            $duty_notifications[] = $row;
        }
        $stmt_duty->close();
    }

    // 3. ตรวจสอบว่า "โหวตประสงค์กลับบ้าน" หรือยังในสัปดาห์นี้
    $stmt_vote = $conn->prepare("
        SELECT COUNT(*) as vote_count 
        FROM weekly_votes 
        WHERE TRIM(student_code) = TRIM(?) 
          AND YEARWEEK(COALESCE(vote_date, DATE(created_at)), 1) = YEARWEEK(CURDATE(), 1)
    ");
    if ($stmt_vote) {
        $stmt_vote->bind_param("s", $studentCode);
        $stmt_vote->execute();
        $res_vote = $stmt_vote->get_result();
        if ($row_vote = $res_vote->fetch_assoc()) {
            if ((int)$row_vote['vote_count'] === 0) {
                $has_voted = false;
            } else {
                $has_voted = true;
            }
        }
        $stmt_vote->close();
    }
   
    // ดึงวันที่ปัจจุบันของไทย
    $today_date = date('Y-m-d');
    $studentCode = trim($_SESSION['student_code']);

    // 4. ตรวจสอบข้อมูลบัตรวินัยประจำวันนี้
    $today_discipline = null;
    $today_disc_count = 0;

    $stmt_disc = $conn->prepare("
        SELECT card_bed, card_shoe, card_cabinet, total_cards, status_color 
        FROM discipline_cards 
        WHERE TRIM(student_code) = TRIM(?) AND DATE(check_date) = ? 
        ORDER BY id DESC LIMIT 1
    ");
    if ($stmt_disc) {
        $stmt_disc->bind_param("ss", $studentCode, $today_date);
        $stmt_disc->execute();
        $res_disc = $stmt_disc->get_result();
        if ($row_disc = $res_disc->fetch_assoc()) {
            $today_discipline = $row_disc;
            
            $bed  = (int)($today_discipline['card_bed'] ?? 0);
            $shoe = (int)($today_discipline['card_shoe'] ?? 0);
            $cab  = (int)($today_discipline['card_cabinet'] ?? 0);
            $db_tot = (int)($today_discipline['total_cards'] ?? 0);
            
            $today_disc_count = max($db_tot, ($bed + $shoe + $cab));
        }
        $stmt_disc->close();
    }

    // 4.1 ดึงข้อมูลยอดรวมบัตรวินัยสะสมทั้งหมด
    $total_cards_history = 0;
    $stmt_total_disc = $conn->prepare("
        SELECT COALESCE(SUM(total_cards), 0) as all_cards 
        FROM discipline_cards 
        WHERE TRIM(student_code) = TRIM(?)
    ");
    if ($stmt_total_disc) {
        $stmt_total_disc->bind_param("s", $studentCode);
        $stmt_total_disc->execute();
        $res_total = $stmt_total_disc->get_result();
        if ($row_total = $res_total->fetch_assoc()) {
            $total_cards_history = (int)$row_total['all_cards'];
        }
        $stmt_total_disc->close();
    }

    // 5. ดึงข้อมูลข่าวสารประกาศ
    $announcements = [];
    $sql = "SELECT id, title, content, attachment, created_at 
            FROM announcements 
            ORDER BY created_at DESC, id DESC 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
        $stmt->close();
    }

    // จัดเตรียมตัวแปรสำหรับการแสดงผล
    $name = trim(($student['prefix'] ?? '') . ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $student_code_display = "รหัส " . ($student['student_code'] ?? '');
    $profile_image = $student['image'] ?? '';

    $unit_parts = [];
    if (!empty($student['battalion'])) $unit_parts[] = "พัน." . $student['battalion'];
    if (!empty($student['company']))   $unit_parts[] = "ร้อย." . $student['company'];
    if (!empty($student['platoon']))   $unit_parts[] = "หมวด " . $student['platoon'];
    if (!empty($student['section']))   $unit_parts[] = "ตอนเรียน " . $student['section'];
    $student_unit = !empty($unit_parts) ? implode(" ", $unit_parts) : "นักเรียนทหาร";

    if (empty($profile_image) || $profile_image === "default_student.png" || $profile_image === "0") {
        $profile_image = "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=4f46e5&color=fff&size=128";
    } else {
        // กรณีมีรูปในระบบ ปรับ Path ให้ตรงตามที่ใช้งานจริง
        $profile_image = "uploads/profiles/" . $profile_image;
    }

    // คำนวณนับการแจ้งเตือน
    $has_discipline_alert = ($today_disc_count > 0);
    $noti_count = count($duty_notifications) + (!$has_voted ? 1 : 0) + ($has_discipline_alert ? 1 : 0);

} catch (Exception $e) {
    die("เกิดข้อผิดพลาดของระบบ: " . $e->getMessage());
}
?>
    
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  
  <title>หน้าหลัก - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #4f46e5;      /* Modern Indigo */
      --primary-light: #818cf8;
      --bg-color: #f8fafc;     /* Light slate */
      --card-bg: #ffffff;
      --text-dark: #0f172a;
      --text-gray: #64748b;
      --radius: 20px;
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    
    body {
      font-family: 'Prompt', sans-serif;
      background-color: #e2e8f0;
      margin: 0;
      display: flex;
      justify-content: center;
      user-select: none;
    }

    /* Loader */
    #page-loader {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background-color: #ffffff; z-index: 9999;
      display: flex; flex-direction: column; justify-content: center; align-items: center;
      transition: opacity 0.5s ease, visibility 0.5s ease;
    }
    #page-loader.hidden { opacity: 0; visibility: hidden; }
    .loader-logo { width: 80px; height: 80px; border-radius: 15px; animation: pulse 1.5s infinite ease-in-out; }
    .loader-spinner {
      margin-top: 20px; width: 40px; height: 40px;
      border: 4px solid rgba(79, 70, 229, 0.2);
      border-top: 4px solid var(--primary);
      border-radius: 50%; animation: spin 1s linear infinite;
    }

    @keyframes pulse {
      0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.4); }
      50% { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(79, 70, 229, 0); }
      100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(79, 70, 229, 0); }
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s ease forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }

    /* App Layout */
    .app-container {
      width: 100%; max-width: 430px;
      background-color: var(--bg-color);
      min-height: 100vh; position: relative;
      padding-bottom: 90px;
      box-shadow: 0 0 30px rgba(0,0,0,0.1);
    }

    /* Header */
    .top-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px; background: transparent;
    }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    .school-name h1 { font-size: 1.05rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; font-weight: 500; }

    .noti-btn {
      position: relative; background: white; border: none;
      width: 42px; height: 42px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: var(--text-gray); font-size: 1.2rem; cursor: pointer;
      text-decoration: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    .noti-badge {
      position: absolute; top: 0; right: 0;
      background: #ef4444; color: white; font-size: 0.65rem; font-weight: 700;
      width: 18px; height: 18px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      border: 2px solid white;
    }

    /* Profile Card - Revamped */
    .student-card {
      background: linear-gradient(135deg, var(--primary), #6366f1);
      border-radius: var(--radius);
      padding: 20px; margin: 0 20px 25px;
      color: white; box-shadow: 0 15px 30px rgba(79, 70, 229, 0.25);
      display: flex; align-items: center; gap: 15px; position: relative; overflow: hidden;
    }
    .student-card::after {
      content: ''; position: absolute; right: -20px; bottom: -20px;
      width: 100px; height: 100px; background: rgba(255,255,255,0.1);
      border-radius: 50%;
    }
    .profile-img {
      width: 70px; height: 70px; border-radius: 50%;
      border: 3px solid rgba(255, 255, 255, 0.4);
      object-fit: cover; background-color: #fff; z-index: 1;
    }
    .student-info { z-index: 1; flex: 1; }
    .student-info h2 { font-size: 1.15rem; font-weight: 600; margin: 0 0 5px 0; line-height: 1.2; }
    .student-info p { font-size: 0.85rem; margin: 0 0 8px 0; opacity: 0.9; }
    .badges { display: flex; flex-wrap: wrap; gap: 6px; }
    .badge-item {
      background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3);
      padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 500; backdrop-filter: blur(4px);
    }
    .badge-danger { background: #ef4444 !important; border-color: #f87171 !important; color: white;}

    /* Section Headers */
    .section-title {
      display: flex; align-items: center; justify-content: space-between;
      margin: 0 20px 15px;
    }
    .section-title h3 {
      font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0;
    }

    /* Modern Alert Cards */
    .duty-alert-container { margin: 0 20px 25px; display: flex; flex-direction: column; gap: 12px; }
    .duty-alert-card {
      display: flex; align-items: center; gap: 15px;
      padding: 16px; border-radius: 16px; background: var(--card-bg);
      box-shadow: 0 4px 15px rgba(0,0,0,0.03); position: relative; overflow: hidden;
    }
    /* Left colored bar */
    .duty-alert-card::before {
      content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 5px;
    }
    .duty-alert-card.urgent::before { background-color: #ef4444; }
    .duty-alert-card.warning::before { background-color: #f59e0b; }
    .duty-alert-card.success::before { background-color: #10b981; }

    .alert-icon { font-size: 1.4rem; }
    .urgent .alert-icon { color: #ef4444; }
    .warning .alert-icon { color: #f59e0b; }
    .success .alert-icon { color: #10b981; }

    .alert-info { flex: 1; }
    .alert-info h4 { margin: 0 0 3px 0; font-size: 0.9rem; font-weight: 700; color: var(--text-dark); }
    .alert-info p { margin: 0; font-size: 0.8rem; color: var(--text-gray); }

    .btn-action {
      padding: 6px 14px; border-radius: 10px; font-size: 0.75rem; font-weight: 600;
      text-decoration: none; text-align: center; white-space: nowrap; transition: 0.2s;
    }
    .btn-action.urgent { background-color: #fee2e2; color: #ef4444; }
    .btn-action.warning { background-color: #fef3c7; color: #f59e0b; }

    /* Menu Grid */
    .menu-grid {
      display: grid; grid-template-columns: repeat(4, 1fr);
      gap: 12px; margin: 0 20px 25px;
    }
    .menu-item {
      background: var(--card-bg); border-radius: 16px; padding: 15px 5px;
      text-decoration: none; color: var(--text-dark);
      display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
      box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s;
    }
    .menu-item:active { transform: scale(0.95); }
    .icon-box {
      width: 45px; height: 45px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; margin-bottom: 8px;
    }
    .menu-text { font-size: 0.7rem; font-weight: 600; line-height: 1.2; }
    
    .bg-purple { background: #eef2ff; color: var(--primary); }
    .bg-orange { background: #fff7ed; color: #ea580c; }
    .bg-blue { background: #f0f9ff; color: #0284c7; }
    .bg-red { background: #fef2f2; color: #dc2626; }
    .bg-green { background: #ecfdf5; color: #059669; }

    /* News Carousel */
    .news-carousel {
      display: flex; overflow-x: auto; gap: 16px;
      padding: 5px 20px 20px 20px; scroll-snap-type: x mandatory;
      scrollbar-width: none; scroll-behavior: smooth;
    }
    .news-carousel::-webkit-scrollbar { display: none; }
    .news-card {
      min-width: 85%; max-width: 85%; background: var(--card-bg);
      border-radius: var(--radius); scroll-snap-align: center; flex-shrink: 0;
      box-shadow: 0 4px 20px rgba(0,0,0,0.04); display: flex; flex-direction: column; overflow: hidden;
    }
    .news-media { position: relative; width: 100%; height: 180px; background: #e2e8f0; cursor: pointer; }
    .news-media img { width: 100%; height: 100%; object-fit: cover; }
    .news-tag {
      position: absolute; top: 12px; left: 12px;
      background: rgba(255,255,255,0.9); color: var(--primary); font-size: 0.7rem; font-weight: 700;
      padding: 4px 10px; border-radius: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .news-body { padding: 15px; display: flex; flex-direction: column; flex: 1; }
    .news-date { font-size: 0.7rem; color: #94a3b8; margin-bottom: 5px; font-weight: 500; }
    .news-title { font-size: 0.95rem; font-weight: 700; margin: 0 0 5px 0; color: var(--text-dark); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .news-desc { font-size: 0.8rem; color: var(--text-gray); margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    
    /* Bottom Nav (Glassmorphism) */
    .bottom-nav {
      position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%);
      height: 75px; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
      border-top: 1px solid rgba(0,0,0,0.05);
      display: flex; justify-content: space-around; align-items: center; z-index: 1000;
      padding-bottom: env(safe-area-inset-bottom, 0); /* Support for iPhone notch */
    }
    .nav-link {
      display: flex; flex-direction: column; align-items: center; gap: 5px;
      text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 25%;
    }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.4rem; }

    /* Modal Image */
    .image-modal {
      display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
      background: rgba(0, 0, 0, 0.9); z-index: 99999; justify-content: center; align-items: center;
      opacity: 0; transition: 0.3s;
    }
    .image-modal.show { display: flex; opacity: 1; }
    .modal-content-img { max-width: 90%; max-height: 80vh; border-radius: 12px; }
    .modal-close-btn {
      position: absolute; top: 20px; right: 20px;
      background: rgba(255,255,255,0.2); border: none; color: white; width: 40px; height: 40px;
      border-radius: 50%; font-size: 1.5rem; cursor: pointer;
    }
  </style>
</head>
<body>

  <?php if ($show_loading): ?>
  <div id="page-loader">
    <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Loading Logo" class="loader-logo">
    <div class="loader-spinner"></div>
  </div>
  <?php endif; ?>

  <div class="app-container">
    
    <!-- Top Header -->
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <a href="nofitication.php" class="noti-btn" id="notiBellBtn">
        <i class="far fa-bell"></i>
        <?php if ($noti_count > 0): ?>
          <span class="noti-badge"><?= $noti_count ?></span>
        <?php endif; ?>
      </a>
    </div>

    <!-- Student Profile Card (New Design) -->
    <div class="student-card fade-in-up delay-2">
      <img src="<?= htmlspecialchars($profile_image) ?>" alt="Profile" class="profile-img">
      <div class="student-info">
        <h2><?= htmlspecialchars($name) ?></h2>
        <p><?= htmlspecialchars($student_code_display) ?></p>
        <div class="badges">
          <div class="badge-item"><?= htmlspecialchars($student_unit) ?></div>
          <?php if ($total_cards_history > 0): ?>
            <div class="badge-item badge-danger">โดนตัดบัตรสะสม: <?= $total_cards_history ?> ใบ</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Alerts Section -->
    <div class="section-title fade-in-up delay-3">
      <h3>การแจ้งเตือนของฉัน</h3>
    </div>

    <div class="duty-alert-container fade-in-up delay-3">
      <!-- 1. บัตรวินัย -->
      <?php if ($today_disc_count > 0): ?>
        <?php 
          $tot = $today_disc_count;
          $color = strtolower(trim($today_discipline['status_color'] ?? ''));
          $items = [];
          if (!empty($today_discipline['card_bed']))     $items[] = "เตียง";
          if (!empty($today_discipline['card_cabinet'])) $items[] = "ตู้";
          if (!empty($today_discipline['card_shoe']))    $items[] = "รองเท้า";
          $items_str = !empty($items) ? implode(", ", $items) : "บัตรวินัย";
        ?>

        <?php if ($tot >= 3 || $color === 'red'): ?>
          <div class="duty-alert-card urgent">
            <div class="alert-icon"><i class="fas fa-id-card-alt"></i></div>
            <div class="alert-info">
              <h4>สถานะ: แดง (<?= $tot ?> ใบ)</h4>
              <p>รายการ: <?= htmlspecialchars($items_str) ?></p>
            </div>
            <a href="discipline_history.php" class="btn-action urgent">ตรวจสอบ</a>
          </div>
        <?php else: ?>
          <div class="duty-alert-card warning">
            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="alert-info">
              <h4>สถานะ: เตือน (<?= $tot ?> ใบ)</h4>
              <p>รายการ: <?= htmlspecialchars($items_str) ?></p>
            </div>
            <a href="discipline_history.php" class="btn-action warning">ตรวจสอบ</a>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="duty-alert-card success">
          <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
          <div class="alert-info">
            <h4>บัตรวินัยวันนี้: ปกติ</h4>
            <p>ยังไม่มีรายการโดนตัดบัตรวินัย</p>
          </div>
        </div>
      <?php endif; ?>

      <!-- 2. แจ้งเตือนโหวต -->
      <?php if (!$has_voted): ?>
        <div class="duty-alert-card urgent">
          <div class="alert-icon"><i class="fas fa-vote-yea"></i></div>
          <div class="alert-info">
            <h4>ยังไม่ได้โหวตกลับบ้าน</h4>
            <p>กรุณาทำรายการในสัปดาห์นี้</p>
          </div>
          <a href="fill.php" class="btn-action urgent">โหวตเลย</a>
        </div>
      <?php endif; ?>

      <!-- 3. แจ้งเตือนเวร -->
      <?php foreach ($duty_notifications as $noti): ?>
        <?php $is_urgent = ($noti['days_left'] == 1); ?>
        <div class="duty-alert-card <?= $is_urgent ? 'urgent' : 'warning' ?>">
          <div class="alert-icon"><i class="fas fa-clock"></i></div>
          <div class="alert-info">
            <h4>เวร: <?= htmlspecialchars($noti['duty_name']) ?></h4>
            <p>อีก <?= $noti['days_left'] ?> วัน (<?= date('d/m/Y', strtotime($noti['duty_date'])) ?>)</p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Main Menus -->
    <div class="section-title fade-in-up delay-4">
      <h3>บริการระบบ</h3>
    </div>

    <div class="menu-grid fade-in-up delay-4">
      <a href="duty.php" class="menu-item">
        <div class="icon-box bg-purple"><i class="fas fa-user-clock"></i></div>
        <span class="menu-text">เวรของฉัน</span>
      </a>
      <a href="fill.php" class="menu-item">
        <div class="icon-box bg-orange"><i class="fas fa-vote-yea"></i></div>
        <span class="menu-text">โหวตกลับบ้าน</span>
      </a>
      <a href="reqdoc.php" class="menu-item">
        <div class="icon-box bg-blue"><i class="fas fa-file-alt"></i></div>
        <span class="menu-text">ขอเอกสาร</span>
      </a>
      <a href="duty_substitutions.php" class="menu-item">
        <div class="icon-box bg-green"><i class="fas fa-address-book"></i></div>
        <span class="menu-text">เปลี่ยนเวร</span>
      </a>
    </div>

    <!-- News Carousel -->
    <div class="section-title fade-in-up delay-4">
      <h3>ประชาสัมพันธ์</h3>
    </div>

    <div class="news-carousel fade-in-up delay-4" id="newsCarousel">
      <?php if (!empty($announcements)): ?>
        <?php foreach ($announcements as $index => $news): ?>
          <?php 
            $attachment_path = $news['attachment'] ?? '';
            if (!empty($attachment_path) && !preg_match('/^https?:\/\//i', $attachment_path) && strpos($attachment_path, 'uploads/') !== 0) {
                $attachment_path = 'uploads/news/' . $attachment_path;
            }
            $ext = strtolower(pathinfo($attachment_path, PATHINFO_EXTENSION));
            $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $full_img_url = htmlspecialchars($attachment_path) . "?v=" . time();
          ?>

          <div class="news-card">
            <?php if (!empty($news['attachment']) && $is_image): ?>
              <div class="news-media" onclick="openImageModal('<?= $full_img_url ?>')">
                <img src="<?= $full_img_url ?>" alt="ข่าวสาร" loading="lazy">
                <div class="news-tag">ประกาศ</div>
              </div>
            <?php else: ?>
              <div class="news-media" style="background: var(--primary); display: flex; align-items: center; justify-content: center; height: 120px;">
                <i class="fas fa-bullhorn" style="font-size: 3rem; color: rgba(255,255,255,0.5);"></i>
                <div class="news-tag">ประกาศ</div>
              </div>
            <?php endif; ?>

            <div class="news-body">
              <div class="news-date"><i class="far fa-clock"></i> <?= date('d/m/Y H:i', strtotime($news['created_at'])) ?></div>
              <h4 class="news-title"><?= htmlspecialchars($news['title']) ?></h4>
              <p class="news-desc"><?= htmlspecialchars($news['content']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="padding: 20px; text-align: center; color: var(--text-gray); width: 100%;">ยังไม่มีประกาศในขณะนี้</div>
      <?php endif; ?>
    </div>

  </div> <!-- End App Container -->

  <!-- Image Modal -->
  <div id="imageModal" class="image-modal" onclick="closeImageModal()">
    <button class="modal-close-btn" onclick="closeImageModal()">&times;</button>
    <img id="modalImage" class="modal-content-img" src="" alt="Zoom">
  </div>

  <!-- Bottom Navigation -->
  <div class="bottom-nav">
    <a href="main.php" class="nav-link active">
      <i class="fas fa-home"></i>
      <span>หน้าหลัก</span>
    </a>

    <a href="logout.php" class="nav-link">
      <i class="fas fa-sign-out-alt"></i>
      <span>ออกระบบ</span>
    </a>
  </div>

 <script>
    // 1. ซ่อน Page Loader เมื่อโหลดหน้าเว็บเสร็จสมบูรณ์
    window.addEventListener('load', () => {
      const loader = document.getElementById('page-loader');
      if (loader) {
        setTimeout(() => { loader.classList.add('hidden'); }, 300);
      }
    });

    // 2. ฟังก์ชัน Modal
    function openImageModal(src) {
      const modal = document.getElementById('imageModal');
      const modalImg = document.getElementById('modalImage');
      if (modal && modalImg) {
        modalImg.src = src;
        modal.classList.add('show');
      }
    }
    function closeImageModal() {
      const modal = document.getElementById('imageModal');
      if (modal) { modal.classList.remove('show'); }
    }

    // 3. สคริปต์ Push Notification
    const applicationServerPublicKey = 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA';

    function urlB64ToUint8Array(base64String) {
      const padding = '='.repeat((4 - base64String.length % 4) % 4);
      const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
      const rawData = window.atob(base64);
      const outputArray = new Uint8Array(rawData.length);
      for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
      }
      return outputArray;
    }

    function sendSubscriptionToServer(subscription) {
      fetch('save_subscription.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription)
      })
      .then(response => response.text())
      .then(data => console.log('บันทึกแจ้งเตือน:', data))
      .catch(error => console.error('เกิดข้อผิดพลาด:', error));
    }

    if ('serviceWorker' in navigator && 'PushManager' in window) {
      window.addEventListener('load', async () => {
        try {
          const permission = await Notification.requestPermission();
          if (permission !== 'granted') return;

          const swReg = await navigator.serviceWorker.register('sw.js');
          let subscription = await swReg.pushManager.getSubscription();
          
          if (!subscription) {
            subscription = await swReg.pushManager.subscribe({
              userVisibleOnly: true,
              applicationServerKey: urlB64ToUint8Array(applicationServerPublicKey)
            });
          }
          sendSubscriptionToServer(subscription);
        } catch (error) {
          console.error('Push Registration Error:', error);
        }
      });
    }
  </script>
</body>
</html>
