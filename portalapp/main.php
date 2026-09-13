<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

// -------------------------------------------------------------
// เช็คว่าเคยแสดงหน้า Loading ไปหรือยังใน Session นี้
// -------------------------------------------------------------
$show_loading = false;
if (!isset($_SESSION['has_seen_loading'])) {
    $show_loading = true;
    $_SESSION['has_seen_loading'] = true; // เซ็ตค่าไว้ว่าเห็นแล้ว ครั้งหน้าจะได้ไม่โชว์ซ้ำ
}

$studentCode = $_SESSION['student_code'];
$duty_notifications = [];
$announcements = []; // ตัวแปรเก็บข่าวสาร
$has_voted = true; // สมมติว่าโหวตแล้วเป็นค่าเริ่มต้น

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection error");
    }

    $conn->set_charset("utf8mb4");

    // 1. ดึงข้อมูลนักเรียน
    $stmt = $conn->prepare("SELECT student_code, prefix, first_name, last_name, platoon, company, battalion, section, image FROM students WHERE student_code = ? LIMIT 1");
    $stmt->bind_param("s", $studentCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

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
                $has_voted = false; // ถ้ายังไม่พบข้อมูลการโหวตในสัปดาห์นี้
            }
        }
        $stmt_vote->close();
    }

    // 4. ดึงข้อมูลข่าวสาร/ประกาศที่เปิดใช้งาน (จำกัด 3 รายการล่าสุด)
    $stmt_news = $conn->prepare("
        SELECT title, content, created_at 
        FROM announcements 
        WHERE status = 'active' 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    if ($stmt_news) {
        $stmt_news->execute();
        $res_news = $stmt_news->get_result();
        while ($row = $res_news->fetch_assoc()) {
            $announcements[] = $row;
        }
        $stmt_news->close();
    }

} catch (Throwable $e) {
    error_log("System error: " . $e->getMessage());
}

$name = trim(($student['prefix'] ?? '') . $student['first_name'] . ' ' . $student['last_name']);
$student_code_display = "รหัสนักเรียน " . $student['student_code'];
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

// นับจำนวนแจ้งเตือนทั้งหมด (รวมเวร + สถานะยังไม่ได้โหวต)
$noti_count = count($duty_notifications) + (!$has_voted ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
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

    /* -------------------------------------------------------------
       CSS สำหรับ Loading Screen & Animations
       ------------------------------------------------------------- */
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

    /* Keyframes */
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

    /* แอนิเมชันเลื่อนขึ้น (Fade In Up) สำหรับเนื้อหาในหน้า */
    .fade-in-up {
      opacity: 0;
      transform: translateY(20px);
      animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes fadeInUp {
      to { opacity: 1; transform: translateY(0); }
    }
    
    /* หน่วงเวลา (Stagger) ให้แอนิเมชันขึ้นทีละอัน */
    .delay-1 { animation-delay: 0.1s; }
    .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; }
    .delay-4 { animation-delay: 0.4s; }
    /* ------------------------------------------------------------- */


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

    .section-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 0 20px 15px;
    }
/* ประกาศ / ข่าวสาร (Announcement Card) */
    .announcement-card {
      background: #ffffff;
      border-left: 4px solid #3b82f6;
      border-radius: 12px;
      padding: 15px;
      margin: 0 20px 20px;
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
    }
    
    .announcement-date {
      display: block;
      font-size: 0.7rem;
      color: #94a3b8;
      margin-top: 8px;
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

    /* กล่องการแจ้งเตือน */
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

    .alert-icon {
      font-size: 1.5rem;
    }

    .alert-info {
      flex: 1;
    }

    .alert-info h4 {
      margin: 0 0 2px 0;
      font-size: 0.9rem;
      font-weight: 700;
    }

    .alert-info p {
      margin: 0;
      font-size: 0.8rem;
      opacity: 0.85;
    }

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

    /* ปรับ Menu Grid เป็น 3 ช่อง */
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

    .nav-link.active {
      color: var(--primary);
    }

    .nav-link i {
      font-size: 1.3rem;
    }
    .bg-blue { background: #dbeafe; color: #2563eb; }
  </style>
</head>
<body>

  <!-- Loading Screen (จะทำงานต่อเมื่อ $show_loading เป็น true) -->
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

<button class="noti-btn" id="notiBellBtn" title="เปิดใช้งานการแจ้งเตือน">
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

    <!-- ส่วนแสดงการแจ้งเตือน (เวร + สถานะการโหวต) -->
    <?php if (!empty($duty_notifications) || !$has_voted): ?>
      <div class="section-title fade-in-up delay-3">
        <div class="section-pill"></div>
        <h3>การแจ้งเตือน</h3>
      </div>
      <div class="duty-alert-container fade-in-up delay-3">
        
        <!-- แจ้งเตือนกรณีที่ยังไม่ได้โหวต -->
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

        <!-- แจ้งเตือนเข้าเวร -->
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
    <?php endif; ?>

    <div class="section-title fade-in-up delay-4">
      <div class="section-pill"></div>
      <h3>เมนูหลัก</h3>
    </div>

   <!-- เมนูหลัก 3 ปุ่ม -->
    <div class="menu-grid fade-in-up delay-4">
      <a href="duty.php" class="menu-item">
        <div class="icon-box bg-purple"><i class="fas fa-user-clock"></i></div>
        <span class="menu-text">เวรของฉัน</span>
      </a>

      <a href="fill.php" class="menu-item">
        <div class="icon-box bg-orange"><i class="fas fa-vote-yea"></i></div>
        <span class="menu-text">โหวตประสงค์กลับบ้าน</span>
      </a>

      <!-- เปลี่ยนไอคอนเป็น fa-file-alt และเพิ่มคลาสสีใหม่ (เช่น bg-blue) -->
      <a href="reqdoc.php" class="menu-item">
        <div class="icon-box bg-blue"><i class="fas fa-file-alt"></i></div>
        <span class="menu-text">คำร้องขอเอกสาร</span>
      </a>
    </div>
<!-- ส่วนแสดงประกาศ / ข่าวสาร จากฐานข้อมูล -->
    <?php if (!empty($announcements)): ?>
      <div class="section-title fade-in-up delay-2">
        <div class="section-pill" style="background: #3b82f6;"></div>
        <h3>ประกาศ / ข่าวสาร</h3>
      </div>
      
      <?php foreach ($announcements as $news): ?>
        <div class="announcement-card fade-in-up delay-2">
          <div class="announcement-icon">
            <i class="fas fa-bullhorn"></i>
          </div>
          <div class="announcement-content">
            <h4><?= htmlspecialchars($news['title']) ?></h4>
            <p><?= nl2br(htmlspecialchars($news['content'])) ?></p>
            <span class="announcement-date">
              <i class="far fa-clock"></i> <?= date('d/m/Y H:i', strtotime($news['created_at'])) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
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

  document.getElementById('notiBellBtn').addEventListener('click', async function() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      alert('เบราว์เซอร์ของคุณไม่รองรับการแจ้งเตือนเด้งเข้าตัวเครื่อง');
      return;
    }

    try {
      const permission = await Notification.requestPermission();
      if (permission === 'granted') {
        const registration = await navigator.serviceWorker.ready;
        
        const publicVapidKey = 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA'; 
        
        // 1. สร้าง Subscription
        const subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicVapidKey)
        });

        const subData = subscription.toJSON();
        
        // 2. แปลงข้อมูลเป็น FormData (เพื่อให้ PHP ใช้ $_POST รับค่าได้ง่ายๆ)
        const formData = new FormData();
        formData.append('endpoint', subData.endpoint);
        formData.append('p256dh', subData.keys.p256dh);
        formData.append('auth', subData.keys.auth);

        // 3. ส่งไปที่ PHP
        const res = await fetch('save_subscription.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin' // แนบ Session ไปด้วย
        });

        // 4. อ่านค่าที่ PHP ตอบกลับมา
        const text = await res.text();
        
        try {
            const data = JSON.parse(text);
            if (data.success) {
              alert('เปิดการแจ้งเตือนสำเร็จแล้ว!');
            } else {
              alert('เซิร์ฟเวอร์แจ้งข้อผิดพลาด: ' + (data.error || 'ไม่ทราบสาเหตุ'));
            }
        } catch (parseError) {
            console.error('PHP ตอบกลับมาผิดปกติ:', text);
            alert('บันทึกไม่สำเร็จ: PHP มี Error (ดูรายละเอียดใน Console)');
        }

      } else if (permission === 'denied') {
        alert('คุณได้ปฏิเสธการแจ้งเตือน โปรดเปิดสิทธิ์ในการตั้งค่าของเบราว์เซอร์');
      }
    } catch (err) {
      alert('เกิดข้อผิดพลาดของระบบ: ' + err.message);
    }
  });
</script>
  <!-- Javascript สำหรับซ่อน Loading Screen -->
  <script>
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
