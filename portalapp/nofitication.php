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
$system_notifications = []; 
$has_voted = true; 
$error_message = ""; 
$today_discipline = null; // ตัวแปรเก็บข้อมูลบัตรวินัยวันนี้

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("ไม่พบการเชื่อมต่อฐานข้อมูล (Database connection error) หรือไฟล์ db.php ไม่ได้ใช้ MySQLi");
    }

    $conn->set_charset("utf8mb4");

    // 1. ดึงข้อมูลนักเรียน (เช็คว่ามีตัวตนอยู่จริงหรือไม่)
    $stmt = $conn->prepare("SELECT student_code FROM students WHERE student_code = ? LIMIT 1");
    
    if (!$stmt) {
        throw new Exception("Query ข้อมูลนักเรียนผิดพลาด: " . $conn->error);
    }

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

    // 2. ดึงข้อมูลการเข้าเวรทั้งหมดที่กำลังจะมาถึง
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
    } else {
        error_log("Query student_duties ผิดพลาด: " . $conn->error);
    }

    // 3. ตรวจสอบโหวตประสงค์กลับบ้าน
    $stmt_vote = $conn->prepare("
        SELECT COUNT(*) as vote_count 
        FROM weekly_votes 
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
    } else {
        error_log("Query votes ผิดพลาด: " . $conn->error);
    }

    // 4. [เพิ่มใหม่] ตรวจสอบบัตรวินัยวันนี้
    $stmt_disc = $conn->prepare("
        SELECT card_bed, card_shoe, card_cabinet, total_cards, status_color 
        FROM discipline_cards 
        WHERE student_code = ? AND check_date = CURDATE() 
        LIMIT 1
    ");
    if ($stmt_disc) {
        $stmt_disc->bind_param("s", $studentCode);
        $stmt_disc->execute();
        $res_disc = $stmt_disc->get_result();
        if ($row_disc = $res_disc->fetch_assoc()) {
            if ($row_disc['total_cards'] > 0) {
                $today_discipline = $row_disc;
            }
        }
        $stmt_disc->close();
    } else {
        error_log("Query discipline_cards ผิดพลาด: " . $conn->error);
    }

    // 5. ดึงประกาศ/ข่าวสาร
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
    } else {
        error_log("Query announcements ผิดพลาด: " . $conn->error);
    }

    // 6. ดึงการแจ้งเตือนจากตาราง notifications
    $stmt_noti = $conn->prepare("
        SELECT * FROM notifications 
        WHERE student_code = ? 
           OR student_code IS NULL 
           OR student_code = '' 
        ORDER BY created_at DESC LIMIT 15
    ");
    if ($stmt_noti) {
        $stmt_noti->bind_param("s", $studentCode);
        $stmt_noti->execute();
        $res_noti = $stmt_noti->get_result();
        while ($row = $res_noti->fetch_assoc()) {
            $system_notifications[] = $row;
        }
        $stmt_noti->close();
    } else {
        error_log("Query notifications ผิดพลาด: " . $conn->error);
    }

} catch (Throwable $e) {
    // แสดง Error ที่แท้จริงบนหน้าจอเพื่อการดีบัก
    $error_message = "System Error: " . htmlspecialchars($e->getMessage());
}

// นับจำนวนรายการแจ้งเตือนทั้งหมด
$has_discipline_alert = ($today_discipline !== null);
$total_notifications = count($duty_notifications) + (!$has_voted ? 1 : 0) + count($announcements) + count($system_notifications) + ($has_discipline_alert ? 1 : 0);
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
    
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--bg-color); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    .back-btn { background: #e2e8f0; border: none; font-size: 1.1rem; color: var(--text-dark); cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; transition: background-color 0.2s; }
    .back-btn:active { background: #cbd5e1; }
    
    .section-title { display: flex; align-items: center; justify-content: space-between; margin: 15px 20px 15px; }
    .section-title-left { display: flex; align-items: center; gap: 10px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    .noti-count-tag { background: #e2e8f0; color: var(--text-dark); font-size: 0.75rem; font-weight: 600; padding: 3px 10px; border-radius: 12px; }
    
    .noti-list { margin: 0 20px 25px; display: flex; flex-direction: column; gap: 12px; }
    .noti-item { background: white; border-radius: 14px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); display: flex; gap: 14px; align-items: flex-start; position: relative; }
    .noti-item.urgent { border-left: 4px solid #ef4444; background-color: #fef2f2; }
    .noti-item.warning { border-left: 4px solid #f97316; background-color: #fff7ed; }
    .noti-item.info { border-left: 4px solid #3b82f6; background-color: #ffffff; }
    .noti-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
    .noti-item.urgent .noti-icon { background: #fee2e2; color: #dc2626; }
    .noti-item.warning .noti-icon { background: #ffedd5; color: #ea580c; }
    .noti-item.info .noti-icon { background: #dbeafe; color: #2563eb; }
    .noti-content { flex: 1; }
    .noti-content h4 { margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 700; color: var(--text-dark); }
    .noti-content p { margin: 0 0 8px 0; font-size: 0.82rem; color: var(--text-gray); line-height: 1.4; word-break: break-word; }
    .noti-time { font-size: 0.7rem; color: #94a3b8; display: flex; align-items: center; gap: 4px; }
    .btn-action { display: inline-block; margin-top: 6px; background-color: var(--primary); color: white; text-decoration: none; padding: 6px 14px; border-radius: 8px; font-size: 0.75rem; font-weight: 600; box-shadow: 0 2px 4px rgba(90, 66, 160, 0.2); }
    
    .empty-state { text-align: center; padding: 40px 20px; background: white; border-radius: 16px; margin: 0 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 12px; }
    .empty-state p { margin: 0; color: #94a3b8; font-size: 0.9rem; }
    
    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; position: relative; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <div class="app-container">
    
    <!-- Top Header -->
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>การแจ้งเตือน</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <a href="main.php" class="back-btn" title="ย้อนกลับ">
        <i class="fas fa-arrow-left"></i>
      </a>
    </div>

    <!-- Section Title -->
    <div class="section-title fade-in-up delay-2">
      <div class="section-title-left">
        <div class="section-pill"></div>
        <h3>รายการแจ้งเตือนทั้งหมด</h3>
      </div>
      <span class="noti-count-tag"><?= $total_notifications ?> รายการ</span>
    </div>

    <!-- Notification List -->
    <div class="noti-list fade-in-up delay-3">

      <?php if (!empty($error_message)): ?>
        <div style="padding: 12px 15px; background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; border-radius: 10px; font-size: 0.85rem; margin-bottom: 12px;">
          <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
        </div>
      <?php endif; ?>

      <!-- 0. แจ้งเตือนบัตรวินัย (เรียงขึ้นมาบนสุด) -->
      <?php if ($today_discipline): ?>
        <?php 
          $tot = (int)$today_discipline['total_cards'];
          $color = $today_discipline['status_color'];
          
          $items = [];
          if ($today_discipline['card_bed']) $items[] = "เตียง";
          if ($today_discipline['card_cabinet']) $items[] = "ตู้";
          if ($today_discipline['card_shoe']) $items[] = "รองเท้า";
          $items_str = !empty($items) ? implode(", ", $items) : "ไม่มีรายละเอียด";

          // กำหนดระดับความแจ้งเตือน (3 ใบแดง = Urgent / 1-2 ใบเหลือง = Warning)
          $card_class = ($tot >= 3 || $color == 'red') ? 'urgent' : 'warning';
        ?>
        <div class="noti-item <?= $card_class ?>">
          <div class="noti-icon">
            <i class="fas fa-id-card-alt"></i>
          </div>
          <div class="noti-content">
            <h4>โดนตัดบัตรวินัยวันนี้! (จำนวน <?= $tot ?> ใบ)</h4>
            <p><strong>รายการที่ไม่เรียบร้อย:</strong> <?= $items_str ?></p>
            <div class="noti-time">
              <i class="far fa-clock"></i> ตรวจพบวันนี้
            </div>
          </div>
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

      <!-- 2. แจ้งเตือนจากตาราง Notifications -->
      <?php foreach ($system_notifications as $sys_noti): ?>
        <?php 
          $level = $sys_noti['alert_level'] ?? 'info';
          if ($level === 'urgent') {
              $card_type = 'urgent'; $icon_class = 'fa-exclamation-circle';
          } elseif ($level === 'warning') {
              $card_type = 'warning'; $icon_class = 'fa-exclamation-triangle';
          } else {
              $card_type = 'info'; $icon_class = 'fa-bell';
          }
          $noti_date = !empty($sys_noti['created_at']) ? date('d/m/Y H:i', strtotime($sys_noti['created_at'])) : date('d/m/Y H:i');
        ?>
        <div class="noti-item <?= $card_type ?>">
          <div class="noti-icon">
            <i class="fas <?= $icon_class ?>"></i>
          </div>
          <div class="noti-content">
            <h4><?= htmlspecialchars($sys_noti['title'] ?? '') ?></h4>
            <p><?= nl2br(htmlspecialchars($sys_noti['message'] ?? '')) ?></p>
            <div class="noti-time">
              <i class="far fa-clock"></i> <?= $noti_date ?> น.
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- 3. แจ้งเตือนการเข้าเวร -->
      <?php foreach ($duty_notifications as $duty): ?>
        <?php 
          $days = (int)($duty['days_left'] ?? 0);
          if ($days === 0) {
              $card_type = 'urgent'; $icon_class = 'fa-exclamation-triangle'; $time_text = 'วันนี้';
          } elseif ($days === 1) {
              $card_type = 'warning'; $icon_class = 'fa-clock'; $time_text = 'พรุ่งนี้';
          } else {
              $card_type = 'info'; $icon_class = 'fa-calendar-alt'; $time_text = "อีก {$days} วัน";
          }
          $duty_date_fmt = !empty($duty['duty_date']) ? date('d/m/Y', strtotime($duty['duty_date'])) : '';
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

      <!-- 4. แจ้งเตือนข่าวสาร/ประกาศ -->
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
