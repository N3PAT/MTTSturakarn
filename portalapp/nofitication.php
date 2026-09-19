<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

$studentCode = $_SESSION['student_code'];
$duty_notifications = [];
$announcements = []; 
$has_voted = true; 
$error_message = ""; 

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

    // 2. ดึงข้อมูลการเข้าเวรทั้งหมดที่กำลังจะมาถึง (วันนี้เป็นต้นไป)
    $stmt_duty = $conn->prepare("
        SELECT duty_name, duty_date, DATEDIFF(duty_date, CURDATE()) AS days_left 
        FROM student_duties 
        WHERE student_code = ? 
          AND duty_date >= CURDATE()
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

    // 4. ดึงประกาศ/ข่าวสารทั้งหมดล่าสุด
    $sql_news = "SELECT * FROM announcements WHERE status = 'active' OR status = '1' ORDER BY id DESC LIMIT 10";
    $res_news = $conn->query($sql_news);
    
    if (!$res_news) {
        $sql_fallback = "SELECT * FROM announcements ORDER BY id DESC LIMIT 10";
        $res_news = $conn->query($sql_fallback);
    }

    if ($res_news) {
        while ($row = $res_news->fetch_assoc()) {
            $announcements[] = $row;
        }
    }

} catch (Throwable $e) {
    error_log("System error: " . $e->getMessage());
    $error_message = "เกิดข้อผิดพลาดของระบบฐานข้อมูล";
}

$name = trim(($student['prefix'] ?? '') . $student['first_name'] . ' ' . $student['last_name']);
$student_code_display = "รหัสนักเรียน " . $student['student_code'];

$unit_parts = [];
if (!empty($student['battalion'])) $unit_parts[] = "พัน." . $student['battalion'];
if (!empty($student['company']))   $unit_parts[] = "ร้อย." . $student['company'];
if (!empty($student['platoon']))   $unit_parts[] = "หมวด " . $student['platoon'];
if (!empty($student['section']))   $unit_parts[] = "ตอนเรียนที่ " . $student['section'];
$student_unit = !empty($unit_parts) ? implode(" ", $unit_parts) : "นักเรียนทหาร";

// นับจำนวนรายการแจ้งเตือนทั้งหมด
$total_notifications = count($duty_notifications) + (!$has_voted ? 1 : 0) + count($announcements);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>การแจ้งเตือน - MTTS</title>
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

    .back-btn {
      background: #e2e8f0;
      border: none;
      font-size: 1.1rem;
      color: var(--text-dark);
      cursor: pointer;
      text-decoration: none;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      border-radius: 50%;
      transition: background-color 0.2s;
    }

    .back-btn:active {
      background: #cbd5e1;
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
      justify-content: space-between;
      margin: 0 20px 15px;
    }

    .section-title-left {
      display: flex;
      align-items: center;
      gap: 10px;
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

    .noti-count-tag {
      background: #e2e8f0;
      color: var(--text-dark);
      font-size: 0.75rem;
      font-weight: 600;
      padding: 3px 10px;
      border-radius: 12px;
    }

    /* สไตล์สำหรับการ์ดแจ้งเตือน */
    .noti-list {
      margin: 0 20px 25px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .noti-item {
      background: white;
      border-radius: 14px;
      padding: 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      display: flex;
      gap: 14px;
      align-items: flex-start;
      position: relative;
    }

    .noti-item.urgent {
      border-left: 4px solid #ef4444;
      background-color: #fef2f2;
    }

    .noti-item.warning {
      border-left: 4px solid #f97316;
      background-color: #fff7ed;
    }

    .noti-item.info {
      border-left: 4px solid #3b82f6;
      background-color: #ffffff;
    }

    .noti-icon {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      flex-shrink: 0;
    }

    .noti-item.urgent .noti-icon { background: #fee2e2; color: #dc2626; }
    .noti-item.warning .noti-icon { background: #ffedd5; color: #ea580c; }
    .noti-item.info .noti-icon { background: #dbeafe; color: #2563eb; }

    .noti-content {
      flex: 1;
    }

    .noti-content h4 {
      margin: 0 0 4px 0;
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-dark);
    }

    .noti-content p {
      margin: 0 0 8px 0;
      font-size: 0.82rem;
      color: var(--text-gray);
      line-height: 1.4;
      word-break: break-word;
    }

    .noti-time {
      font-size: 0.7rem;
      color: #94a3b8;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .btn-action {
      display: inline-block;
      margin-top: 6px;
      background-color: var(--primary);
      color: white;
      text-decoration: none;
      padding: 6px 14px;
      border-radius: 8px;
      font-size: 0.75rem;
      font-weight: 600;
      box-shadow: 0 2px 4px rgba(90, 66, 160, 0.2);
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      background: white;
      border-radius: 16px;
      margin: 0 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }

    .empty-state i {
      font-size: 3rem;
      color: #cbd5e1;
      margin-bottom: 12px;
    }

    .empty-state p {
      margin: 0;
      color: #94a3b8;
      font-size: 0.9rem;
    }

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
  </style>
</head>
<body>

  <div class="app-container">
    
    <!-- Top Header -->
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg?utm_source=th.wikipedia.org&utm_campaign=index&utm_content=thumbnail_unscaled" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>การแจ้งเตือน</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <a href="main.php" class="back-btn" title="ย้อนกลับ">
        <i class="fas fa-arrow-left"></i>
      </a>
    </div>

    <!-- Student Card -->
    <div class="student-card fade-in-up delay-2">
      <div class="badges">
        <div class="badge-item">ปีการศึกษา 2569</div>
        <div class="badge-item"><?= htmlspecialchars($student_unit) ?></div>
      </div>
      <h2><?= htmlspecialchars($name) ?></h2>
      <p><?= htmlspecialchars($student_code_display) ?></p>
    </div>

    <!-- Section Title -->
    <div class="section-title fade-in-up delay-3">
      <div class="section-title-left">
        <div class="section-pill"></div>
        <h3>รายการแจ้งเตือนทั้งหมด</h3>
      </div>
      <span class="noti-count-tag"><?= $total_notifications ?> รายการ</span>
    </div>

    <!-- Notification List -->
    <div class="noti-list fade-in-up delay-4">

      <?php if (!empty($error_message)): ?>
        <div style="padding: 12px 15px; background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; border-radius: 10px; font-size: 0.85rem;">
          <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
        </div>
      <?php endif; ?>

      <!-- 1. แจ้งเตือนการโหวตกลับบ้าน -->
      <?php if (!$has_voted): ?>
        <div class="noti-item urgent">
          <div class="noti-icon">
            <i class="fas fa-vote-yea"></i>
          </div>
          <div class="noti-content">
            <h4>ยังไม่ได้โหวตประสงค์กลับบ้าน</h4>
            <p>กรุณาลงทำรายการโหวตความประสงค์ประจำสัปดาห์นี้ให้เรียบร้อย</p>
            <div class="noti-time">
              <i class="far fa-clock"></i> ต้องการการดำเนินการด่วน
            </div>
            <a href="fill.php" class="btn-action">ทำรายการโหวต</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- 2. แจ้งเตือนการเข้าเวร -->
      <?php foreach ($duty_notifications as $duty): ?>
        <?php 
          $days = (int)$duty['days_left'];
          $is_today = ($days === 0);
          $is_tomorrow = ($days === 1);
          
          if ($is_today) {
              $card_type = 'urgent';
              $icon_class = 'fa-exclamation-triangle';
              $time_text = 'วันนี้';
          } elseif ($is_tomorrow) {
              $card_type = 'warning';
              $icon_class = 'fa-clock';
              $time_text = 'พรุ่งนี้';
          } else {
              $card_type = 'info';
              $icon_class = 'fa-calendar-alt';
              $time_text = "อีก {$days} วัน";
          }
          
          $duty_date_fmt = date('d/m/Y', strtotime($duty['duty_date']));
        ?>
        <div class="noti-item <?= $card_type ?>">
          <div class="noti-icon">
            <i class="fas <?= $icon_class ?>"></i>
          </div>
          <div class="noti-content">
            <h4>แจ้งเตือนเข้าเวร: <?= htmlspecialchars($duty['duty_name'] ?? 'ปฏิบัติหน้าที่เวร') ?></h4>
            <p>คุณมีกำหนดปฏิบัติหน้าที่ในวันที่ <?= $duty_date_fmt ?> (<?= $time_text ?>)</p>
            <div class="noti-time">
              <i class="far fa-calendar"></i> วันที่ <?= $duty_date_fmt ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- 3. แจ้งเตือนข่าวสาร/ประกาศ -->
      <?php foreach ($announcements as $news): ?>
        <?php 
          $title = $news['title'] ?? $news['topic'] ?? $news['subject'] ?? 'ประกาศข่าวสาร';
          $content = $news['content'] ?? $news['detail'] ?? $news['description'] ?? '';
          $date = $news['created_at'] ?? $news['date'] ?? $news['created_date'] ?? date('Y-m-d H:i:s');
        ?>
        <div class="noti-item info">
          <div class="noti-icon">
            <i class="fas fa-bullhorn"></i>
          </div>
          <div class="noti-content">
            <h4><?= htmlspecialchars($title) ?></h4>
            <p><?= nl2br(htmlspecialchars($content)) ?></p>
            <div class="noti-time">
              <i class="far fa-clock"></i> <?= date('d/m/Y H:i', strtotime($date)) ?> น.
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- แสดงเมื่อไม่มีรายการแจ้งเตือนเลย -->
      <?php if ($total_notifications == 0 && empty($error_message)): ?>
        <div class="empty-state">
          <i class="far fa-bell-slash"></i>
          <p>ไม่มีรายการแจ้งเตือนใหม่ในขณะนี้</p>
        </div>
      <?php endif; ?>

    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
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

</body>
</html>
