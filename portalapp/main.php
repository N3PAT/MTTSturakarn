<?php
session_start();

// -------------------------------------------------------------
// ปิดการเซฟ Cache บังคับให้เบราว์เซอร์โหลดข้อมูลใหม่ล่าสุดเสมอ
// -------------------------------------------------------------
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db.php';

// ตรวจสอบการล็อกอิน
if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

// เช็คว่าเคยแสดงหน้า Loading ไปหรือยังใน Session นี้
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
        FROM votes 
        WHERE student_code = ? 
          AND YEARWEEK(vote_date, 1) = YEARWEEK(CURDATE(), 1)
    ");
    if ($stmt_vote) {
        $stmt_vote->bind_param("s", $studentCode);
        $stmt_vote->execute();
        $res_vote = $stmt_vote->get_result();
        if ($row_vote = $res_vote->fetch_assoc()) {
            if ($row_vote['vote_count'] == 0) {
                $has_voted = false;
            }
        }
        $stmt_vote->close();
    }

   // 4. ตรวจสอบข้อมูลบัตรวินัยประจำวันนี้
$today_discipline = null;
$today_disc_count = 0;

$stmt_disc = $conn->prepare("
    SELECT card_bed, card_shoe, card_cabinet, total_cards, status_color 
    FROM discipline_cards 
    WHERE student_code = ? AND check_date = CURDATE() 
    ORDER BY id DESC LIMIT 1
");
if ($stmt_disc) {
    $stmt_disc->bind_param("s", $studentCode);
    $stmt_disc->execute();
    $res_disc = $stmt_disc->get_result();
    if ($row_disc = $res_disc->fetch_assoc()) {
        $today_discipline = $row_disc;
        
        // คำนวณจำนวนบัตรวินัยวันนี้
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
    WHERE student_code = ?
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
    $sql = "SELECT id, title, content, attachment, created_at 
            FROM announcements 
            WHERE status = 'active' 
            ORDER BY created_at DESC, id DESC 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $announcements = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

} catch (Exception $e) {
    error_log("Database/System Error in main.php: " . $e->getMessage());
    $news_error = $e->getMessage();
}

// จัดเตรียมตัวแปรสำหรับการแสดงผล
$name = trim(($student['prefix'] ?? '') . ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$student_code_display = "รหัสนักเรียน " . ($student['student_code'] ?? '');
$profile_image = $student['image'] ?? '';

$unit_parts = [];
if (!empty($student['battalion'])) $unit_parts[] = "พัน." . $student['battalion'];
if (!empty($student['company']))   $unit_parts[] = "ร้อย." . $student['company'];
if (!empty($student['platoon']))   $unit_parts[] = "หมวด " . $student['platoon'];
if (!empty($student['section']))   $unit_parts[] = "ตอนเรียนที่ " . $student['section'];
$student_unit = !empty($unit_parts) ? implode(" ", $unit_parts) : "นักเรียนทหาร";

if (empty($profile_image) || $profile_image === "default_student.png" || $profile_image === "0") {
    $profile_image = "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=5a42a0&color=fff&size=128";
}

// คำนวณนับการแจ้งเตือน
$has_discipline_alert = ($today_disc_count > 0);
$noti_count = count($duty_notifications) + (!$has_voted ? 1 : 0) + ($has_discipline_alert ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <!-- กำหนด Meta Tag ไม่ให้เบราว์เซอร์และ PWA เก็บ Cache หน้าเว็บ -->
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  
  <title>หน้าหลัก - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #5a42a0;
      --bg-color: #f6f8fc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
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

    #page-loader {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: #ffffff;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      transition: opacity 0.5s ease, visibility 0.5s ease;
    }
    #page-loader.hidden {
      opacity: 0;
      visibility: hidden;
    }
    .loader-logo {
      width: 80px;
      height: 80px;
      border-radius: 15px;
      animation: pulse 1.5s infinite ease-in-out;
    }
    .loader-spinner {
      margin-top: 20px;
      width: 40px;
      height: 40px;
      border: 4px solid rgba(90, 66, 160, 0.2);
      border-top: 4px solid var(--primary);
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    .loader-text {
      margin-top: 15px;
      color: var(--primary);
      font-weight: 600;
      font-size: 0.9rem;
      letter-spacing: 1px;
      animation: flash 1.5s infinite;
    }

    @keyframes pulse {
      0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(90, 66, 160, 0.4); }
      50% { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(90, 66, 160, 0); }
      100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(90, 66, 160, 0); }
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    @keyframes flash {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }

    .fade-in-up {
      opacity: 0;
      transform: translateY(20px);
      animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes fadeInUp {
      to { opacity: 1; transform: translateY(0); }
    }
    
    .delay-1 { animation-delay: 0.1s; }
    .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; }
    .delay-4 { animation-delay: 0.4s; }

    .top-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 15px 20px;
      background: var(--bg-color);
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .school-logo {
      width: 45px;
      height: 45px;
      object-fit: contain;
      border-radius: 10px;
    }

    .school-name h1 {
      font-size: 1rem;
      font-weight: 700;
      color: var(--text-dark);
      margin: 0 0 2px 0;
    }

    .school-name p {
      font-size: 0.75rem;
      color: var(--text-gray);
      margin: 0;
    }

    .noti-btn {
      position: relative;
      background: none;
      border: none;
      font-size: 1.4rem;
      color: #94a3b8;
      cursor: pointer;
      text-decoration: none;
    }

    .noti-badge {
      position: absolute;
      top: -2px;
      right: -2px;
      background: #ef4444;
      color: white;
      font-size: 0.65rem;
      font-weight: 700;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--bg-color);
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
      flex-wrap: wrap;
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

    .news-carousel {
      display: flex;
      overflow-x: auto;
      gap: 15px;
      padding: 0 20px 15px 20px;
      scroll-snap-type: x mandatory;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
      -ms-overflow-style: none;
    }

    .news-carousel::-webkit-scrollbar {
      display: none;
    }

    .news-carousel .announcement-card {
      min-width: 85%; 
      margin: 0; 
      scroll-snap-align: center; 
      flex-shrink: 0;
      display: flex;
      gap: 15px;
      align-items: flex-start;
    }

    .badge-danger {
      background: rgba(220, 38, 38, 0.8) !important;
      border-color: #f87171 !important;
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

    .section-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 0 20px 15px;
    }

    .announcement-card {
      background: #ffffff;
      border-left: 4px solid #3b82f6;
      border-radius: 12px;
      padding: 15px;
      margin: 0 20px 15px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
      display: flex;
      gap: 15px;
      align-items: flex-start;
    }
    
    .announcement-icon {
      color: #3b82f6;
      font-size: 1.5rem;
      margin-top: 2px;
    }
    
    .announcement-content {
      flex: 1;
    }

    .announcement-content h4 {
      margin: 0 0 5px 0;
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--text-dark);
    }
    
    .announcement-content p {
      margin: 0;
      font-size: 0.8rem;
      color: var(--text-gray);
      line-height: 1.4;
      word-break: break-word;
    }

    .section-pill {
      width: 5px;
      height: 24px;
      background: var(--primary);
      border-radius: 4px;
    }

    .section-title h3 {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text-dark);
      margin: 0;
    }

    .duty-alert-container {
      margin: 0 20px 20px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .duty-alert-card {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      border-radius: 14px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }

    .duty-alert-card.urgent {
      background-color: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }

    .duty-alert-card.warning {
      background-color: #fff7ed;
      border: 1px solid #ffedd5;
      color: #9a3412;
    }

    .duty-alert-card.success {
      background-color: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #166534;
    }

    .alert-icon { font-size: 1.5rem; }
    .alert-info { flex: 1; }
    .alert-info h4 { margin: 0 0 2px 0; font-size: 0.9rem; font-weight: 700; }
    .alert-info p { margin: 0; font-size: 0.8rem; opacity: 0.85; }

    .btn-action-alert {
      background-color: #dc2626;
      color: white;
      text-decoration: none;
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 0.75rem;
      font-weight: 600;
      white-space: nowrap;
      box-shadow: 0 2px 4px rgba(220, 38, 38, 0.2);
    }
    
    .btn-action-warning {
      background-color: #ea580c;
      color: white;
      text-decoration: none;
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 0.75rem;
      font-weight: 600;
      white-space: nowrap;
    }

    .menu-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin: 0 20px 25px;
    }

    .menu-item {
      background: white;
      border-radius: 16px;
      padding: 18px 5px;
      text-decoration: none;
      color: var(--text-dark);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .menu-item:active { transform: scale(0.95); }

    .icon-box {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      margin-bottom: 10px;
    }

    .menu-text {
      font-size: 0.8rem;
      font-weight: 600;
      line-height: 1.2;
    }

    .bg-purple { background: #f3e8ff; color: #9333ea; }
    .bg-orange { background: #ffedd5; color: #ea580c; }
    .bg-blue { background: #dbeafe; color: #2563eb; }
    .bg-red { background: #fee2e2; color: #dc2626; }

    .app-container {
      width: 100%;
      max-width: 430px;
      background-color: var(--bg-color);
      min-height: 100vh;
      position: relative;
      padding-bottom: 90px;
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }

    .bottom-nav {
      position: fixed; 
      bottom: 0;
      width: 100%;
      max-width: 430px; 
      left: 50%;
      transform: translateX(-50%); 
      height: 70px;
      background: white;
      border-top-left-radius: 25px;
      border-top-right-radius: 25px;
      display: flex;
      justify-content: space-around;
      align-items: center;
      box-shadow: 0 -5px 20px rgba(0,0,0,0.03);
      padding: 0 10px;
      z-index: 1000; 
    }

    .nav-link {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-decoration: none;
      color: #94a3b8;
      font-size: 0.7rem;
      font-weight: 600;
      width: 30%;
      gap: 4px;
    }

    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <?php if ($show_loading): ?>
  <div id="page-loader">
    <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg?utm_source=th.wikipedia.org&utm_campaign=index&utm_content=thumbnail_unscaled" alt="Loading Logo" class="loader-logo">
    <div class="loader-spinner"></div>
    <div class="loader-text">กำลังโหลดข้อมูล...</div>
  </div>
  <?php endif; ?>

  <div class="app-container">
    
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg?utm_source=th.wikipedia.org&utm_campaign=index&utm_content=thumbnail_unscaled" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>ระบบบริการสารสนเทศ กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>

      <a href="nofitication.php" class="noti-btn" id="notiBellBtn" title="ดูการแจ้งเตือน">
        <i class="far fa-bell"></i>
        <?php if ($noti_count > 0): ?>
          <span class="noti-badge"><?= $noti_count ?></span>
        <?php endif; ?>
      </a>
    </div>

    <div class="student-card fade-in-up delay-2">
      <div class="badges">
        <div class="badge-item">ปีการศึกษา 2569</div>
        <div class="badge-item"><?= htmlspecialchars($student_unit) ?></div>
        <?php if ($total_cards_history > 0): ?>
          <div class="badge-item badge-danger"><i class="fas fa-exclamation-circle"></i> โดนตัดบัตรสะสม: <?= $total_cards_history ?> ใบ</div>
        <?php endif; ?>
      </div>
      <h2><?= htmlspecialchars($name) ?></h2>
      <p><?= htmlspecialchars($student_code_display) ?></p>
    </div>

    <!-- ส่วนแสดงการแจ้งเตือน (บัตรวินัย + เวร + สถานะการโหวต) -->
    <div class="section-title fade-in-up delay-3">
      <div class="section-pill"></div>
      <h3>บัตรวินัย</h3>
    </div>

    <div class="duty-alert-container fade-in-up delay-3">

      <!-- 1. การ์ดแสดงสถานะบัตรวินัยประจำวันนี้ -->
<?php if ($today_disc_count > 0): ?>
  <?php 
    $tot = $today_disc_count;
    $color = strtolower(trim($today_discipline['status_color'] ?? ''));

    $items = [];
    if (!empty($today_discipline['card_bed']))     $items[] = "เตียง (" . (int)$today_discipline['card_bed'] . ")";
    if (!empty($today_discipline['card_cabinet'])) $items[] = "ตู้ (" . (int)$today_discipline['card_cabinet'] . ")";
    if (!empty($today_discipline['card_shoe']))    $items[] = "รองเท้า (" . (int)$today_discipline['card_shoe'] . ")";
    
    $items_str = !empty($items) ? implode(", ", $items) : "โดนตัดบัตรวินัยประจำวันนี้";
  ?>

  <?php if ($tot >= 3 || $color === 'red'): ?>
    <div class="duty-alert-card urgent">
      <div class="alert-icon"><i class="fas fa-id-card-alt"></i></div>
      <div class="alert-info">
        <h4>วันนี้สถานะบัตร: แดง (โดน <?= $tot ?> ใบ)</h4>
        <p>รายการ: <?= htmlspecialchars($items_str) ?></p>
      </div>
      <a href="discipline_history.php" class="btn-action-alert">ดูรายละเอียด</a>
    </div>

  <?php elseif ($tot == 2 || $color === 'yellow'): ?>
    <div class="duty-alert-card warning">
      <div class="alert-icon"><i class="fas fa-id-card-alt"></i></div>
      <div class="alert-info">
        <h4>วันนี้สถานะบัตร: เหลือง (โดน <?= $tot ?> ใบ)</h4>
        <p>รายการ: <?= htmlspecialchars($items_str) ?></p>
      </div>
      <a href="discipline_history.php" class="btn-action-warning">ดูรายละเอียด</a>
    </div>

  <?php else: ?>
    <div class="duty-alert-card warning" style="background-color: #fff3cd; color: #856404; border-color: #ffeeba;">
      <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="alert-info">
        <h4>วันนี้สถานะบัตร: เตือน (โดน <?= $tot ?> ใบ)</h4>
        <p>รายการ: <?= htmlspecialchars($items_str) ?></p>
      </div>
      <a href="discipline_history.php" class="btn-action-warning">ดูรายละเอียด</a>
    </div>
  <?php endif; ?>

<?php else: ?>
  <div class="duty-alert-card success">
    <div class="alert-icon"><i class="fas fa-shield-alt"></i></div>
    <div class="alert-info">
      <h4>สถานะบัตรวินัยวันนี้: ปกติ</h4>
      <p>ยังไม่มีรายการโดนตัดบัตรวินัยประจำวันนี้</p>
    </div>
  </div>
<?php endif; ?>

      <!-- 2. แจ้งเตือนโหวต -->
      <?php if (!$has_voted): ?>
        <div class="duty-alert-card urgent">
          <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
          <div class="alert-info">
            <h4>ยังไม่ได้โหวตประสงค์กลับบ้าน</h4>
            <p>กรุณาทำรายการโหวตสำหรับสัปดาห์นี้</p>
          </div>
          <a href="fill.php" class="btn-action-alert">โหวตเลย</a>
        </div>
      <?php endif; ?>

      <!-- 3. แจ้งเตือนเข้าเวร -->
      <?php foreach ($duty_notifications as $noti): ?>
        <?php 
          $is_urgent = ($noti['days_left'] == 1);
          $card_class = $is_urgent ? 'urgent' : 'warning';
          $icon_class = $is_urgent ? 'fa-exclamation-circle' : 'fa-clock';
          $date_str = date('d/m/Y', strtotime($noti['duty_date']));
        ?>
        <div class="duty-alert-card <?= $card_class ?>">
          <div class="alert-icon"><i class="fas <?= $icon_class ?>"></i></div>
          <div class="alert-info">
            <h4>แจ้งเตือนเข้าเวร: <?= htmlspecialchars($noti['duty_name'] ?? 'ปฏิบัติหน้าที่เวร') ?></h4>
            <p>อีก <?= $noti['days_left'] ?> วัน (วันที่ <?= $date_str ?>) กรุณาเตรียมตัวให้พร้อม</p>
          </div>
        </div>
      <?php endforeach; ?>

    </div>

    <div class="section-title fade-in-up delay-4">
      <div class="section-pill"></div>
      <h3>เมนูหลัก</h3>
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
        <span class="menu-text">คำร้องขอเอกสาร</span>
      </a>
      
      <a href="duty_substitutions.php" class="menu-item">
        <div class="icon-box bg-blue"><i class="fas fa-address-book"></i></div>
        <span class="menu-text">เปลี่ยนเวร</span>
      </a>

      <a href="discipline_history.php" class="menu-item">
        <div class="icon-box bg-red"><i class="fas fa-id-card"></i></div>
        <span class="menu-text">ประวัติบัตรวินัย</span>
      </a>
    </div>

    <div class="fade-in-up delay-4">
      <div class="news-carousel">
        <?php if (!empty($announcements)): ?>
          <?php foreach ($announcements as $news): ?>
            <?php 
              $attachment_path = $news['attachment'] ?? '';
              if (!empty($attachment_path) && !preg_match('/^https?:\/\//i', $attachment_path) && !str_starts_with($attachment_path, 'uploads/')) {
                  $attachment_path = 'uploads/news/' . $attachment_path;
              }
              
              $ext = strtolower(pathinfo($attachment_path, PATHINFO_EXTENSION));
              $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            ?>

            <div class="announcement-card">
              <div class="announcement-icon">
                <i class="fas fa-bullhorn"></i>
              </div>
              <div class="announcement-content">
                <h4><?= htmlspecialchars($news['title']) ?></h4>
                <p><?= nl2br(htmlspecialchars($news['content'])) ?></p>
                
                <?php if (!empty($news['attachment'])): ?>
                  <div class="announcement-attachment" style="margin-top: 10px;">
                    <?php 
  // สร้างตัวแปร version เพื่อไม่ให้ cache รูปภาพ/ไฟล์
  $cache_buster = "?v=" . time(); 
?>
<?php if ($is_image): ?>
  <a href="<?= htmlspecialchars($attachment_path) . $cache_buster ?>" target="_blank">
    <img src="<?= htmlspecialchars($attachment_path) . $cache_buster ?>" alt="ภาพแนบประกาศ" style="max-width: 100%; max-height: 200px; object-fit: cover; border-radius: 8px; margin-top: 5px; border: 1px solid #e2e8f0; display: block;">
  </a>
<?php else: ?>
  <a href="<?= htmlspecialchars($attachment_path) . $cache_buster ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f1f5f9; color: #2563eb; border-radius: 6px; font-size: 0.8rem; text-decoration: none; font-weight: 500;">
    <i class="fas fa-paperclip"></i> ดูไฟล์แนบ
  </a>
<?php endif; ?>
                  </div>
                <?php endif; ?>
                
                <span class="announcement-date" style="display: block; margin-top: 10px; color: #94a3b8; font-size: 0.75rem;">
                  <i class="far fa-clock"></i> <?= date('d/m/Y H:i', strtotime($news['created_at'])) ?>
                </span>
              </div>
            </div>
          <?php endforeach; ?>

        <?php else: ?>
          <div class="empty-news" style="width: 100%; text-align: center; padding: 25px 15px; background: #ffffff; border-radius: 12px; color: #94a3b8; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <i class="far fa-newspaper" style="font-size: 2rem; margin-bottom: 8px; display: block; opacity: 0.6;"></i>
            <span style="font-size: 0.85rem;">ไม่มีประกาศข่าวสารในขณะนี้</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
      
    <div class="bottom-nav">
      <a href="main.php" class="nav-link active">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      
      <a href="duty.php" class="nav-link">
        <i class="fas fa-calendar-alt"></i>
        <span>เวรของฉัน</span>
      </a>

      <a href="logout.php" class="nav-link">
        <i class="far fa-user"></i>
        <span>ออกจากระบบ</span>
      </a>
    </div>

  </div>

<script>
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  if ('serviceWorker' in navigator && 'PushManager' in window) {
    navigator.serviceWorker.register('sw.js')
      .then(reg => console.log('SW Registered'))
      .catch(err => console.error('SW Error:', err));
  }

  document.getElementById('notiBellBtn').addEventListener('click', async function(e) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      return; 
    }

    if (Notification.permission === 'default') {
      e.preventDefault();
      
      try {
        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
          const registration = await navigator.serviceWorker.ready;
          const publicVapidKey = 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA'; 
          
          const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(publicVapidKey)
          });

          const subData = subscription.toJSON();
          const formData = new FormData();
          formData.append('endpoint', subData.endpoint);
          formData.append('p256dh', subData.keys.p256dh);
          formData.append('auth', subData.keys.auth);

          const res = await fetch('nofitication.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          });

          const text = await res.text();
          
          try {
              const data = JSON.parse(text);
              if (data.success) {
                window.location.href = this.href;
              } else {
                alert('เซิร์ฟเวอร์แจ้งข้อผิดพลาด: ' + (data.error || 'ไม่ทราบสาเหตุ'));
              }
          } catch (parseError) {
              console.error('PHP ตอบกลับมาผิดปกติ:', text);
              alert('บันทึกไม่สำเร็จ: PHP มี Error (ดูรายละเอียดใน Console)');
          }

        } else if (permission === 'denied') {
          alert('คุณได้ปฏิเสธการแจ้งเตือน หากต้องการใช้งานโปรดเปิดสิทธิ์ในการตั้งค่าของเบราว์เซอร์');
        }
      } catch (err) {
        alert('เกิดข้อผิดพลาดของระบบ: ' + err.message);
      }
    }
  });

  document.addEventListener("DOMContentLoaded", function() {
    const loader = document.getElementById('page-loader');
    if (loader) {
      setTimeout(function() {
        loader.classList.add('hidden');
        setTimeout(function() {
          loader.remove();
        }, 500);
      }, 1200);
    }
  });
</script>
   
</body>
</html>
