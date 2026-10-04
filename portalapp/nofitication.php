<?php
// เริ่ม Output Buffering เพื่อป้องกันปัญหาช่องว่างหลุดไปรบกวนการส่ง JSON หรือ Header
ob_start();
session_start();

// เรียกใช้การเชื่อมต่อฐานข้อมูล
require_once 'db.php';

// ====================================================================================
// ตรวจสอบการเข้าสู่ระบบ
// ====================================================================================
if (empty($_SESSION['student_code'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (ob_get_length()) ob_clean(); 
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized: กรุณาเข้าสู่ระบบ']);
        exit();
    }
    header("Location: login.php");
    exit();
}

$studentCode = $_SESSION['student_code'];

// ====================================================================================
// ส่วนที่ 1: การจัดการ API สำหรับบันทึก/อัปเดต Push Subscription
// ====================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    error_reporting(0);
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (isset($_POST['endpoint'])) {
        $endpoint = $_POST['endpoint'];
        $p256dh = $_POST['p256dh'] ?? '';
        $auth = $_POST['auth'] ?? '';

        try {
            // ตรวจสอบว่าเคยลงทะเบียนไว้แล้วหรือไม่
            $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE student_id = ? LIMIT 1");
            $checkStmt->bind_param("s", $studentCode);
            $checkStmt->execute();
            $res = $checkStmt->get_result();

            if ($res->num_rows > 0) {
                // หากมีแล้ว ให้ทำการ UPDATE เพื่อรองรับการเปลี่ยน Device หรือ Token ใหม่
                $updateStmt = $conn->prepare("UPDATE push_subscriptions SET endpoint = ?, p256dh = ?, auth = ? WHERE student_id = ?");
                $updateStmt->bind_param("ssss", $endpoint, $p256dh, $auth, $studentCode);
                $updateStmt->execute();
                $updateStmt->close();
                
                echo json_encode(['success' => true, 'message' => 'อัปเดตการแจ้งเตือนสำเร็จ']);
            } else {
                // หากยังไม่มี ให้ INSERT ข้อมูลใหม่
                $stmt = $conn->prepare("INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $studentCode, $endpoint, $p256dh, $auth);
                $stmt->execute();
                $stmt->close();

                echo json_encode(['success' => true, 'message' => 'บันทึกการแจ้งเตือนสำเร็จ']);
            }
            $checkStmt->close();

        } catch (Exception $e) {
            if ($conn->errno === 1062) {
                echo json_encode(['success' => true, 'message' => 'มีข้อมูล endpoint นี้ในระบบแล้ว']);
            } else {
                echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
            }
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (Missing endpoint)']);
    }
    exit(); 
}

// ====================================================================================
// ส่วนที่ 2: การดึงข้อมูลเพื่อแสดงผลบนหน้าเว็บ (UI)
// ====================================================================================
$duty_notifications = [];
$announcements = []; 
$system_notifications = []; 
$has_voted = true; 
$error_message = ""; 
$today_discipline = null; 
$student_restriction = null; 

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("ไม่พบการเชื่อมต่อฐานข้อมูล หรือไฟล์ db.php ไม่ได้ใช้ MySQLi");
    }

    $conn->set_charset("utf8mb4");

    // ตรวจสอบข้อมูลนักเรียน
    $stmt = $conn->prepare("SELECT student_code FROM students WHERE student_code = ? LIMIT 1");
    if (!$stmt) throw new Exception("Query ข้อมูลนักเรียนผิดพลาด: " . $conn->error);
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

    // 1. กักบริเวณ
    $stmt_restrict = $conn->prepare("SELECT weeks_count, created_at FROM restrictions WHERE student_code = ? ORDER BY created_at DESC LIMIT 1");
    if ($stmt_restrict) {
        $stmt_restrict->bind_param("s", $studentCode);
        $stmt_restrict->execute();
        $res_restrict = $stmt_restrict->get_result();
        if ($row_restrict = $res_restrict->fetch_assoc()) {
            $student_restriction = $row_restrict;
        }
        $stmt_restrict->close();
    }

    // 2. การเข้าเวร
    $stmt_duty = $conn->prepare("SELECT duty_name, duty_date, DATEDIFF(duty_date, CURDATE()) AS days_left FROM student_duties WHERE student_code = ? AND duty_date >= CURDATE() ORDER BY duty_date ASC");
    if ($stmt_duty) {
        $stmt_duty->bind_param("s", $studentCode);
        $stmt_duty->execute();
        $res_duty = $stmt_duty->get_result();
        while ($row = $res_duty->fetch_assoc()) {
            $duty_notifications[] = $row;
        }
        $stmt_duty->close();
    }

    // 3. โหวตประสงค์กลับบ้าน
    $stmt_vote = $conn->prepare("SELECT COUNT(*) as vote_count FROM weekly_votes WHERE student_code = ? AND YEARWEEK(vote_date, 1) = YEARWEEK(CURDATE(), 1)");
    if ($stmt_vote) {
        $stmt_vote->bind_param("s", $studentCode);
        $stmt_vote->execute();
        $res_vote = $stmt_vote->get_result();
        if ($row_vote = $res_vote->fetch_assoc()) {
            if ($row_vote['vote_count'] == 0) $has_voted = false;
        }
        $stmt_vote->close();
    }

    // 4. บัตรวินัยวันนี้
    $stmt_disc = $conn->prepare("SELECT card_bed, card_shoe, card_cabinet, total_cards, status_color FROM discipline_cards WHERE student_code = ? AND check_date = CURDATE() LIMIT 1");
    if ($stmt_disc) {
        $stmt_disc->bind_param("s", $studentCode);
        $stmt_disc->execute();
        $res_disc = $stmt_disc->get_result();
        if ($row_disc = $res_disc->fetch_assoc()) {
            if ($row_disc['total_cards'] > 0) $today_discipline = $row_disc;
        }
        $stmt_disc->close();
    }

    // 5. ดึงประกาศ/ข่าวสาร (ระบุชื่อคอลัมน์ชัดเจนแทน SELECT * เพื่อลด Memory)
    $sql_news = "SELECT id, title, topic, subject, content, detail, description, created_at, date, created_date, attachment FROM announcements WHERE status = 'active' OR status = '1' ORDER BY id DESC LIMIT 10";
    $res_news = $conn->query($sql_news);
    if (!$res_news) {
        $sql_fallback = "SELECT id, title, topic, subject, content, detail, description, created_at, date, created_date, attachment FROM announcements ORDER BY id DESC LIMIT 10";
        $res_news = $conn->query($sql_fallback);
    }
    if ($res_news) {
        while ($row = $res_news->fetch_assoc()) {
            $announcements[] = $row;
        }
    }

    // 6. ดึงการแจ้งเตือนจากตาราง notifications
    $stmt_noti = $conn->prepare("SELECT id, title, message, alert_level, created_at FROM notifications WHERE student_code = ? OR student_code IS NULL OR student_code = '' ORDER BY created_at DESC LIMIT 15");
    if ($stmt_noti) {
        $stmt_noti->bind_param("s", $studentCode);
        $stmt_noti->execute();
        $res_noti = $stmt_noti->get_result();
        while ($row = $res_noti->fetch_assoc()) {
            $system_notifications[] = $row;
        }
        $stmt_noti->close();
    }

} catch (Throwable $e) {
    $error_message = "System Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}

$has_discipline_alert = ($today_discipline !== null);
$has_restriction = ($student_restriction !== null);
$total_notifications = count($duty_notifications) + (!$has_voted ? 1 : 0) + count($announcements) + count($system_notifications) + ($has_discipline_alert ? 1 : 0) + ($has_restriction ? 1 : 0);

if (ob_get_length()) ob_end_flush();
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
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; user-select: none; }
    
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
    
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--bg-color); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    .back-btn { background: #e2e8f0; border: none; font-size: 1.1rem; color: var(--text-dark); cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; transition: background-color 0.2s; }
    
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
    .noti-item.danger { border-left: 5px solid #991b1b; background-color: #fef2f2; }
    
    .noti-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
    .noti-item.urgent .noti-icon { background: #fee2e2; color: #dc2626; }
    .noti-item.warning .noti-icon { background: #ffedd5; color: #ea580c; }
    .noti-item.info .noti-icon { background: #dbeafe; color: #2563eb; }
    .noti-item.danger .noti-icon { background: #fecaca; color: #991b1b; }
    
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

    /* Modal CSS */
    .clickable-noti { cursor: pointer; transition: transform 0.1s, box-shadow 0.2s; }
    .clickable-noti:active { transform: scale(0.98); }
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; display: none; justify-content: center; align-items: center; opacity: 0; transition: opacity 0.3s; }
    .modal-overlay.show { display: flex; opacity: 1; }
    .modal-box { background: white; width: 90%; max-width: 380px; border-radius: 16px; padding: 20px; transform: translateY(20px); transition: transform 0.3s; box-shadow: 0 10px 25px rgba(0,0,0,0.1); display: flex; flex-direction: column; max-height: 85vh; }
    .modal-overlay.show .modal-box { transform: translateY(0); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px; flex-shrink: 0; }
    .modal-title { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    .close-modal { background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; }
    .modal-body { font-size: 0.95rem; color: var(--text-gray); line-height: 1.5; overflow-y: auto; white-space: pre-wrap; flex-grow: 1; }
    .modal-image { max-width: 100%; height: auto; border-radius: 8px; margin-bottom: 12px; display: none; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .modal-footer { margin-top: 15px; font-size: 0.8rem; color: #94a3b8; text-align: right; flex-shrink: 0; }
  </style>
</head>
<body>

  <div class="app-container">
    
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo" loading="lazy">
        <div class="school-name">
          <h1>การแจ้งเตือน</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <a href="main.php" class="back-btn" title="ย้อนกลับ"><i class="fas fa-arrow-left"></i></a>
    </div>

    <div class="section-title fade-in-up delay-2">
      <div class="section-title-left">
        <div class="section-pill"></div>
        <h3>รายการแจ้งเตือนทั้งหมด</h3>
      </div>
      <span class="noti-count-tag"><?= $total_notifications ?> รายการ</span>
    </div>

    <div class="noti-list fade-in-up delay-3">

      <?php if (!empty($error_message)): ?>
        <div style="padding: 12px 15px; background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; border-radius: 10px; font-size: 0.85rem; margin-bottom: 12px;">
          <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
        </div>
      <?php endif; ?>

      <!-- 1. แจ้งเตือนกักบริเวณ -->
      <?php if ($student_restriction): ?>
        <div class="noti-item danger clickable-noti" data-title="คุณถูกกักบริเวณ" data-detail="งดกลับบ้าน: คุณถูกโทษกักบริเวณเป็นจำนวน <?= htmlspecialchars($student_restriction['weeks_count'], ENT_QUOTES, 'UTF-8') ?> สัปดาห์" data-time="<?= date('d/m/Y', strtotime($student_restriction['created_at'])) ?>">
          <div class="noti-icon"><i class="fas fa-ban"></i></div>
          <div class="noti-content">
            <h4 style="color: #991b1b;">คุณถูกกักบริเวณ</h4>
            <p><strong>งดกลับบ้าน:</strong> คุณถูกโทษกักบริเวณเป็นจำนวน <strong><?= htmlspecialchars($student_restriction['weeks_count'], ENT_QUOTES, 'UTF-8') ?> สัปดาห์</strong></p>
            <div class="noti-time"><i class="far fa-calendar-times"></i> วันที่สั่งกัก: <?= date('d/m/Y', strtotime($student_restriction['created_at'])) ?></div>
          </div>
        </div>
      <?php endif; ?>

      <!-- 2. แจ้งเตือนบัตรวินัย -->
      <?php if ($today_discipline): ?>
        <?php 
          $tot = (int)$today_discipline['total_cards'];
          $items = [];
          if ($today_discipline['card_bed']) $items[] = "เตียง";
          if ($today_discipline['card_cabinet']) $items[] = "ตู้";
          if ($today_discipline['card_shoe']) $items[] = "รองเท้า";
          $items_str = !empty($items) ? implode(", ", $items) : "ไม่มีรายละเอียด";
          $card_class = ($tot >= 3 || $today_discipline['status_color'] == 'red') ? 'urgent' : 'warning';
        ?>
        <div class="noti-item <?= $card_class ?> clickable-noti" data-title="โดนตัดบัตรวินัยวันนี้! (จำนวน <?= $tot ?> ใบ)" data-detail="รายการที่ไม่เรียบร้อย: <?= htmlspecialchars($items_str, ENT_QUOTES, 'UTF-8') ?>" data-time="ตรวจพบวันนี้">
          <div class="noti-icon"><i class="fas fa-id-card-alt"></i></div>
          <div class="noti-content">
            <h4>โดนตัดบัตรวินัยวันนี้! (จำนวน <?= $tot ?> ใบ)</h4>
            <p><strong>รายการที่ไม่เรียบร้อย:</strong> <?= htmlspecialchars($items_str, ENT_QUOTES, 'UTF-8') ?></p>
            <div class="noti-time"><i class="far fa-clock"></i> ตรวจพบวันนี้</div>
          </div>
        </div>
      <?php endif; ?>

      <!-- 3. แจ้งเตือนการโหวตกลับบ้าน -->
      <?php if (!$has_voted): ?>
        <div class="noti-item urgent clickable-noti" data-title="ยังไม่ได้โหวตประสงค์กลับบ้าน" data-detail="กรุณาลงทำรายการโหวตความประสงค์ประจำสัปดาห์นี้ให้เรียบร้อยโดยการกดที่ปุ่ม 'ทำรายการโหวต'" data-time="สัปดาห์นี้">
          <div class="noti-icon"><i class="fas fa-vote-yea"></i></div>
          <div class="noti-content">
            <h4>ยังไม่ได้โหวตประสงค์กลับบ้าน</h4>
            <p>กรุณาลงทำรายการโหวตความประสงค์ประจำสัปดาห์นี้ให้เรียบร้อย</p>
            <a href="fill.php" class="btn-action">ทำรายการโหวต</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- 4. แจ้งเตือนจากระบบส่วนกลาง -->
      <?php foreach ($system_notifications as $sys_noti): ?>
        <?php 
          $level = $sys_noti['alert_level'] ?? 'info';
          if ($level === 'urgent') { $card_type = 'urgent'; $icon_class = 'fa-exclamation-circle'; }
          elseif ($level === 'warning') { $card_type = 'warning'; $icon_class = 'fa-exclamation-triangle'; }
          else { $card_type = 'info'; $icon_class = 'fa-bell'; }
          $noti_date = !empty($sys_noti['created_at']) ? date('d/m/Y H:i', strtotime($sys_noti['created_at'])) : date('d/m/Y H:i');
        ?>
        <div class="noti-item <?= $card_type ?> clickable-noti" data-title="<?= htmlspecialchars($sys_noti['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-detail="<?= htmlspecialchars($sys_noti['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-time="<?= $noti_date ?> น.">
          <div class="noti-icon"><i class="fas <?= $icon_class ?>"></i></div>
          <div class="noti-content">
            <h4><?= htmlspecialchars($sys_noti['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h4>
            <p><?= nl2br(htmlspecialchars($sys_noti['message'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
            <div class="noti-time"><i class="far fa-clock"></i> <?= $noti_date ?> น.</div>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- 5. แจ้งเตือนการเข้าเวร -->
      <?php foreach ($duty_notifications as $duty): ?>
        <?php 
          $days = (int)($duty['days_left'] ?? 0);
          if ($days === 0) { $card_type = 'urgent'; $icon_class = 'fa-exclamation-triangle'; $time_text = 'วันนี้'; }
          elseif ($days === 1) { $card_type = 'warning'; $icon_class = 'fa-clock'; $time_text = 'พรุ่งนี้'; }
          else { $card_type = 'info'; $icon_class = 'fa-calendar-alt'; $time_text = "อีก {$days} วัน"; }
          $duty_date_fmt = !empty($duty['duty_date']) ? date('d/m/Y', strtotime($duty['duty_date'])) : '';
        ?>
        <div class="noti-item <?= $card_type ?> clickable-noti" data-title="แจ้งเตือนเข้าเวร: <?= htmlspecialchars($duty['duty_name'] ?? 'ปฏิบัติหน้าที่เวร', ENT_QUOTES, 'UTF-8') ?>" data-detail="คุณมีกำหนดปฏิบัติหน้าที่ในวันที่ <?= $duty_date_fmt ?> (<?= $time_text ?>)" data-time="<?= $duty_date_fmt ?>">
          <div class="noti-icon"><i class="fas <?= $icon_class ?>"></i></div>
          <div class="noti-content">
            <h4>แจ้งเตือนเข้าเวร: <?= htmlspecialchars($duty['duty_name'] ?? 'ปฏิบัติหน้าที่เวร', ENT_QUOTES, 'UTF-8') ?></h4>
            <p>คุณมีกำหนดปฏิบัติหน้าที่ในวันที่ <?= $duty_date_fmt ?> (<?= $time_text ?>)</p>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- 6. ประกาศ/ข่าวสาร -->
      <?php foreach ($announcements as $news): ?>
        <?php 
          $title = $news['title'] ?? $news['topic'] ?? $news['subject'] ?? 'ประกาศข่าวสาร';
          $content = $news['content'] ?? $news['detail'] ?? $news['description'] ?? '';
          $date = $news['created_at'] ?? $news['date'] ?? $news['created_date'] ?? date('Y-m-d H:i:s');
          $attachment = $news['attachment'] ?? '';
        ?>
        <div class="noti-item info clickable-noti"
             data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
             data-detail="<?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?>"
             data-time="<?= date('d/m/Y H:i', strtotime($date)) ?> น."
             data-image="<?= htmlspecialchars($attachment, ENT_QUOTES, 'UTF-8') ?>"> 
          <div class="noti-icon"><i class="fas fa-bullhorn"></i></div>
          <div class="noti-content">
            <h4><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h4>
            <p><?= nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) ?></p>
            <?php if (!empty($attachment)): ?>
              <div style="font-size: 0.75rem; color: var(--primary); margin-bottom: 5px;">
                <i class="far fa-image"></i> มีรูปภาพแนบ
              </div>
            <?php endif; ?>
            <div class="noti-time"><i class="far fa-clock"></i> <?= date('d/m/Y H:i', strtotime($date)) ?> น.</div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($total_notifications == 0 && empty($error_message)): ?>
        <div class="empty-state">
          <i class="far fa-bell-slash"></i>
          <p>ไม่มีรายการแจ้งเตือนใหม่ในขณะนี้</p>
        </div>
      <?php endif; ?>

    </div>

    <!-- Modal Detail -->
    <div class="modal-overlay" id="detailModal">
      <div class="modal-box">
        <div class="modal-header">
          <h3 class="modal-title" id="modalTitle">รายละเอียด</h3>
          <button class="close-modal" id="closeModal">&times;</button>
        </div>
        <div class="modal-body">
          <img id="modalImage" class="modal-image" src="" alt="ภาพประกอบ" loading="lazy">
          <div id="modalBodyText"></div>
        </div>
        <div class="modal-footer" id="modalTime"></div>
      </div>
    </div>

    <div class="bottom-nav">
      <a href="main.php" class="nav-link"><i class="fas fa-home"></i><span>หน้าหลัก</span></a>
      <a href="duty.php" class="nav-link"><i class="fas fa-calendar-alt"></i><span>เวรของฉัน</span></a>
      <a href="logout.php" class="nav-link"><i class="far fa-user"></i><span>ออกจากระบบ</span></a>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // ==========================================
        // ระบบจัดการ Modal
        // ==========================================
        const modal = document.getElementById('detailModal');
        const closeBtn = document.getElementById('closeModal');
        const mTitle = document.getElementById('modalTitle');
        const mBodyText = document.getElementById('modalBodyText');
        const mImage = document.getElementById('modalImage');
        const mTime = document.getElementById('modalTime');

        document.querySelectorAll('.clickable-noti').forEach(item => {
          item.addEventListener('click', function(e) {
            if (e.target.tagName.toLowerCase() === 'a' || e.target.closest('a')) return; 

            mTitle.textContent = this.getAttribute('data-title');
            mBodyText.textContent = this.getAttribute('data-detail');
            mTime.innerHTML = '<i class="far fa-clock"></i> ' + this.getAttribute('data-time');
            
            // ตรวจสอบและแสดงผลรูปภาพ
            const imgSrc = this.getAttribute('data-image');
            if (imgSrc && imgSrc.trim() !== '') {
                mImage.src = imgSrc;
                mImage.style.display = 'block';
            } else {
                mImage.src = '';
                mImage.style.display = 'none';
            }
            
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);
          });
        });

        const closeModal = () => {
          modal.classList.remove('show');
          setTimeout(() => {
              modal.style.display = 'none';
              mImage.src = ''; // เคลียร์รูปออกเพื่อกันแสดงผลค้าง
          }, 300);
        };
        
        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
          if (e.target === modal) closeModal(); 
        });
    });
  </script>

</body>
</html>
