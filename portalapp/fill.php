<?php
session_start(); // 1. เปิดใช้งาน Session สำหรับ CSRF Token

// -------------------------------------------------------------
// เช็คว่าเคยแสดงหน้า Loading ไปหรือยังใน Session นี้ สำหรับหน้า fill
// -------------------------------------------------------------
$show_loading = false;
if (!isset($_SESSION['has_seen_loading_fill'])) {
    $show_loading = true;
    $_SESSION['has_seen_loading_fill'] = true;
}

date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'db.php';

// ---------------- 0. API สำหรับดึงข้อมูลนักเรียนผ่าน AJAX ----------------
if (isset($_GET['action']) && $_GET['action'] == 'get_student_name' && isset($_GET['code'])) {
    
    while (ob_get_level()) { ob_end_clean(); }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate'); 
    header('Pragma: no-cache');
    header('Expires: 0');

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
                'name' => $fullname,
                'section' => $row['section'] ?? ''
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['found' => false, 'name' => '', 'section' => ''], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
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

$is_voting_open = (
    in_array($current_day_of_week, [6, 7, 5]) ||
    ($current_day_of_week == 2 && $current_hour < 12)
);

$alert_type = "";
$message = "";

// ---------------- 2. ลอจิกการคำนวณเวร และดึงรายชื่อผู้ถูกกักบริเวณจากตาราง restrictions ----------------
$all_students = [];
$duty_roster_ids = [];
$restricted_ids = []; // รายชื่อนักเรียนที่โดนกักบริเวณ

try {
    if (isset($conn) && $conn) {
        // 2.1 ดึงนักเรียนทั้งหมดเพื่อคำนวณเวรรักษาการณ์
        $res = $conn->query("SELECT student_code FROM students ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $all_students[] = $row['student_code'];
            }
        }

        // 2.2 ดึงรหัสนักเรียนที่ถูกกักบริเวณในสัปดาห์นี้จากตาราง restrictions
        // เงื่อนไข: วันที่เป้าหมาย ($target_date) อยู่ในช่วงตั้งแต่ created_at ถึง created_at + weeks_count สัปดาห์
        $stmt_rest = $conn->prepare("
            SELECT DISTINCT student_code 
            FROM restrictions 
            WHERE (restrict_type = 'restrict' OR restrict_type IS NULL OR restrict_type = '')
              AND ? >= DATE(created_at) 
              AND ? <= DATE_ADD(DATE(created_at), INTERVAL weeks_count WEEK)
        ");
        if ($stmt_rest) {
            $stmt_rest->bind_param("ss", $target_date, $target_date);
            $stmt_rest->execute();
            $res_rest = $stmt_rest->get_result();
            while ($r_row = $res_rest->fetch_assoc()) {
                $restricted_ids[] = $r_row['student_code'];
            }
        }
    }
} catch (Exception $e) {}

$total_students = count($all_students);

if ($total_students > 0) {
    $diff_days = floor((strtotime($target_date) - strtotime($start_date)) / 86400);
    if ($diff_days < 0) $diff_days = 0;
    
    $start_index = ($diff_days * 16) % $total_students;
    for ($i = 0; $i < 16; $i++) {
        $idx = ($start_index + $i) % $total_students;
        $duty_roster_ids[] = $all_students[$idx];
    }
}

// ---------------- 3. เมื่อนักเรียนกดบันทึกข้อมูล ----------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $csrf_token = $_POST['csrf_token'] ?? '';
    $stu_code = trim($_POST['student_code'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $status = trim($_POST['status'] ?? '');

    if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $alert_type = "error";
        $message = "เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่";
    }
    else if (!$is_voting_open) {
        $alert_type = "error";
        $message = "หมดเวลาทำรายการ ระบบเปิดให้ส่งข้อมูลเฉพาะวันเสาร์ถึงวันอังคารก่อนเวลา 16:00 น. เท่านั้น";
    } else {
        $allowed_status = ['home', 'stay', 'duty', 'restrict'];
        
        if (!in_array($status, $allowed_status)) {
            $alert_type = "error";
            $message = "สถานะไม่ถูกต้องตามที่ระบบกำหนด (กรุณาเลือกสถานะให้ครบถ้วน)";
        } else if (in_array($stu_code, $restricted_ids) && $status !== 'restrict') {
            $alert_type = "error";
            $message = "รหัส " . htmlspecialchars($stu_code) . " มีรายชื่อถูกกักบริเวณในสัปดาห์นี้ ต้องเลือกสถานะกักบริเวณเท่านั้น";
        } else if ($status === 'duty' && !in_array($stu_code, $duty_roster_ids)) {
            $alert_type = "error";
            $message = "รหัส " . htmlspecialchars($stu_code) . " ไม่ได้มีรายชื่อปฏิบัติหน้าที่เวรรักษาการณ์ในสัปดาห์นี้ ไม่สามารถเลือกสถานะเวรได้";
        } else {
            try {
                $stmt_check = $conn->prepare("SELECT id FROM weekly_votes WHERE student_code = ? AND vote_date = ?");
                $stmt_check->bind_param("ss", $stu_code, $target_date);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();

                if ($result_check->num_rows > 0) {
                    $alert_type = "error";
                    $message = "รหัส " . htmlspecialchars($stu_code) . " ได้ทำการบันทึกข้อมูลของสัปดาห์นี้ไปแล้ว ไม่สามารถทำรายการซ้ำได้";
                } else {
                    $stmt_update = $conn->prepare("UPDATE students SET section = ? WHERE student_code = ?");
                    $stmt_update->bind_param("ss", $section, $stu_code);
                    $stmt_update->execute();

                    $real_time_now = date('Y-m-d H:i:s');
                    $stmt_insert = $conn->prepare("INSERT INTO weekly_votes (student_code, vote_date, status, created_at) VALUES (?, ?, ?, ?)");
                    $stmt_insert->bind_param("ssss", $stu_code, $target_date, $status, $real_time_now);
                    $stmt_insert->execute();
                    
                    $alert_type = "success";
                    $message = "บันทึกข้อมูลรหัส " . htmlspecialchars($stu_code) . " ประจำวันศุกร์ที่ " . date('d/m/Y', strtotime($target_date)) . " เรียบร้อยแล้ว";
                }
            } catch (Exception $e) {
                $alert_type = "error";
                $message = "เกิดข้อผิดพลาดจากฐานข้อมูล: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>แจ้งความประสงค์ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <style>
    :root {
      --primary: #5a42a0;
      --primary-dark: #473282;
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
      min-height: 100vh;
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

    .top-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 15px 20px;
      background: var(--bg-color);
    }

    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    
    .section-title { display: flex; align-items: center; gap: 10px; margin: 10px 20px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    .date-badge {
      display: inline-flex; align-items: center; gap: 6px; background: #e0e7ff; 
      padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; 
      color: var(--primary); margin: 0 20px 15px; border: 1px solid #c7d2fe;
    }

    .form-card {
      background: white; border-radius: 16px; padding: 20px; margin: 0 20px 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }

    .form-group { margin-bottom: 18px; }
    .form-group label {
      display: flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; 
      color: var(--text-dark); margin-bottom: 8px;
    }
    .text-danger { color: #ef4444; }
    .icon-label { color: var(--primary); font-size: 0.95rem; }

    .form-control {
      width: 100%; padding: 12px 16px; border-radius: 12px; border: 1px solid #cbd5e1;
      background: #f8fafc; font-family: 'Prompt', sans-serif; font-size: 0.95rem; 
      color: var(--text-dark); outline: none; transition: all 0.2s;
    }
    
    .form-control:focus {
      border-color: var(--primary); box-shadow: 0 0 0 3px rgba(90, 66, 160, 0.1); background: white;
    }
    select.form-control { appearance: none; cursor: pointer; }
    .select-wrapper { position: relative; }
    .select-icon {
      position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
      color: var(--primary); pointer-events: none;
    }

    .input-readonly { background: #f1f5f9; color: #64748b; border-style: dashed; }
    .input-success { background: #f0fdf4; border-color: #22c55e; color: #15803d; }
    .input-error { background: #fef2f2; border-color: #ef4444; color: #b91c1c; }
    .input-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

    .help-text { font-size: 0.75rem; color: var(--text-gray); margin-top: 6px; display: flex; gap: 4px; }
    
    .info-box {
      background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px; border-radius: 12px; 
      display: flex; gap: 12px; align-items: flex-start; margin-top: 10px;
    }
    .info-icon { background: #dbeafe; color: #2563eb; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .info-content { font-size: 0.8rem; color: #1e3a8a; line-height: 1.4; }

    .btn-submit {
      width: 100%; padding: 14px; background: var(--primary); color: white; border: none; 
      border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; 
      display: flex; align-items: center; justify-content: center; gap: 8px;
      box-shadow: 0 4px 12px rgba(90, 66, 160, 0.25); transition: transform 0.2s, background 0.2s; 
      font-family: 'Prompt', sans-serif; margin-top: 24px;
    }
    .btn-submit:active { transform: scale(0.98); background: var(--primary-dark); }

    .closed-state {
      text-align: center; padding: 40px 20px; background: white; border-radius: 16px; 
      margin: 0 20px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .closed-icon {
      width: 64px; height: 64px; background: #fee2e2; color: #ef4444; border-radius: 50%; 
      display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 16px;
    }
    .closed-state h3 { color: #991b1b; margin: 0 0 8px 0; font-size: 1.2rem; }
    .closed-state p { font-size: 0.85rem; color: #b91c1c; margin: 0 0 16px 0; }
    .closed-time { background: #f8fafc; padding: 8px 16px; border-radius: 8px; border: 1px solid #e2e8f0; display: inline-block; font-size: 0.85rem; font-weight: 600; color: #ef4444; }
    .btn-refresh {
      background: none; border: none; color: var(--primary); font-size: 0.85rem; font-weight: 600; cursor: pointer; margin-top: 20px; font-family: 'Prompt', sans-serif;
    }

    .nav-link {
      display: flex; flex-direction: column; align-items: center; text-decoration: none;
      color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px;
    }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
    
    .swal2-popup { font-family: 'Prompt', sans-serif !important; border-radius: 1rem !important; }
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
    </div>

    <div class="section-title fade-in-up delay-2">
      <div class="section-pill"></div>
      <h3>โหวตประสงค์กลับบ้าน</h3>
    </div>
    
    <div class="date-badge fade-in-up delay-2">
      <i class="far fa-calendar-alt"></i> วันศุกร์ที่ <?php echo date('d/m/Y', strtotime($target_date)); ?>
    </div>

    <?php if ($is_voting_open): ?>
      <div class="form-card fade-in-up delay-3">
        <form id="statusForm" method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          
          <div class="form-group">
            <label for="student_code">
              <i class="fas fa-id-badge icon-label"></i> รหัสประจำตัว (นชท.) <span class="text-danger">*</span>
            </label>
            <input type="text" id="student_code" name="student_code" required maxlength="10" inputmode="numeric"
                   class="form-control" placeholder="เช่น 151xx">
          </div>

          <div class="form-group">
            <label for="student_name">
              <i class="fas fa-user icon-label"></i> ชื่อ-นามสกุล
            </label>
            <input type="text" id="student_name" readonly tabindex="-1"
                   class="form-control input-readonly" placeholder="ระบบจะแสดงชื่ออัตโนมัติ">
          </div>

          <div class="form-group">
            <label for="section">
              <i class="fas fa-users icon-label"></i> ตอนเรียนที่ (1-14) <span class="text-danger">*</span>
            </label>
            <input type="text" id="section" name="section" required maxlength="50" inputmode="numeric"
                   class="form-control" placeholder="เช่น 1">
          </div>

          <div class="form-group">
            <label for="status_select">
              <i class="fas fa-clipboard-check icon-label"></i> เลือกสถานะ <span class="text-danger">*</span>
            </label>
            <div class="select-wrapper">
              <select name="status" id="status_select" required class="form-control">
                <option value="" disabled selected>-- กรุณาเลือกสถานะ --</option>
                <option value="home">๑. กลับภูมิลำเนา</option>
                <option value="stay">๒. ประสงค์อยู่ รร.ชท.</option>
                <option value="duty">๓. เวรรักษาการณ์(วันศุกร์)</option>
                <option value="restrict">๔. กักบริเวณ</option>
              </select>
              <i class="fas fa-chevron-down select-icon"></i>
            </div>
            <div class="help-text">
              <i class="fas fa-info-circle" style="color: var(--primary); margin-top: 2px;"></i> 
              กรณีใครไม่รู้ว่าโดนกักบริเวณ ธุรการจะเป็นผู้ประกาศอีกที
            </div>
          </div>

          <div class="info-box">
            <div class="info-icon"><i class="fas fa-headset"></i></div>
            <div class="info-content">
              <strong>มีปัญหาการใช้งาน?</strong><br>
              หากพบปัญหาในการกรอกข้อมูล หรือข้อมูลไม่ถูกต้อง กรุณาติดต่อ Line ID: <strong>ntw25020</strong>
            </div>
          </div>

          <button type="submit" id="submitBtn" class="btn-submit">
            ดำเนินการส่งข้อมูล <i class="fas fa-paper-plane"></i>
          </button>
        </form>
      </div>

    <?php else: ?>
      <div class="closed-state fade-in-up delay-3">
        <div class="closed-icon"><i class="fas fa-lock"></i></div>
        <h3>ระบบปิดรับข้อมูล</h3>
        <p>ระบบเปิดให้ทำรายการเฉพาะช่วงเวลา</p>
        <div class="closed-time">วันเสาร์ - วันอังคาร (ก่อน 16:00 น.)</div>
        <br>
        <button type="button" onclick="window.location.reload();" class="btn-refresh">
          <i class="fas fa-sync-alt"></i> รีเฟรชหน้าเว็บ
        </button>
      </div>
    <?php endif; ?>

    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>

      <a href="logout.php" class="nav-link">
        <i class="far fa-user-circle"></i>
        <span>ออกจากระบบ</span>
      </a>
    </div>

  </div>

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

<script>
<?php if ($alert_type == 'success'): ?>
    Swal.fire({
        title: 'สำเร็จ',
        text: <?php echo json_encode($message); ?>,
        icon: 'success',
        confirmButtonColor: '#5a42a0',
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
    const restrictIds = <?php echo json_encode($restricted_ids); ?>;

    async function checkStudentData() {
        const stuInput = document.getElementById('student_code');
        const stuCode = stuInput.value.trim();
        const nameInput = document.getElementById('student_name');
        const statusSelect = document.getElementById('status_select');

        if (stuCode === "") {
            nameInput.value = "";
            nameInput.className = "form-control input-readonly";
            
            statusSelect.disabled = false;
            statusSelect.style.pointerEvents = "auto";
            statusSelect.className = "form-control";
            statusSelect.value = "";
            return;
        }

        nameInput.value = "กำลังค้นหาข้อมูล...";
        nameInput.className = "form-control input-readonly";

        try {
            const response = await fetch(`?action=get_student_name&code=${encodeURIComponent(stuCode)}&_t=${Date.now()}`, {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
                cache: 'no-store'
            });

            if (!response.ok) throw new Error('Network response was not ok');

            const data = await response.json();

            if (data.found) {
                nameInput.value = data.name;
                if (data.section) {
                    document.getElementById("section").value = data.section;
                }
                nameInput.className = "form-control input-success";
            } else {
                nameInput.value = "ไม่พบรหัสนี้ในระบบ";
                nameInput.className = "form-control input-error";
            }
        } catch (error) {
            console.error('Fetch error:', error);
            nameInput.value = "เกิดข้อผิดพลาดในการเชื่อมต่อข้อมูล";
            nameInput.className = "form-control input-error";
        }

        // เช็คว่าถูกกักบริเวณหรือไม่
        if (restrictIds.includes(stuCode)) {
            Swal.fire({
                title: 'แจ้งเตือนถูกกักบริเวณ',
                text: 'รหัสของท่านมีรายชื่อถูกกักบริเวณในสัปดาห์นี้ ระบบจะเลือกสถานะกักบริเวณให้อัตโนมัติ',
                icon: 'warning',
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'รับทราบ'
            });

            statusSelect.value = "restrict";
            statusSelect.disabled = true;
            statusSelect.style.pointerEvents = "none";
            statusSelect.className = "form-control input-error"; 
        } 
        // เช็คว่าเป็นเวรรักษาการณ์หรือไม่
        else if (dutyIds.includes(stuCode)) {
            Swal.fire({
                title: 'แจ้งเตือนระบบเวรรักษาการณ์',
                text: 'รหัสของท่านมีรายชื่อปฏิบัติหน้าที่เวรรักษาการณ์ในสัปดาห์นี้ ระบบจะเลือกสถานะเวรให้อัตโนมัติ',
                icon: 'warning',
                confirmButtonColor: '#5a42a0',
                confirmButtonText: 'รับทราบ'
            });

            statusSelect.value = "duty";
            statusSelect.disabled = true;
            statusSelect.style.pointerEvents = "none";
            statusSelect.className = "form-control input-error"; 
        } else {
            statusSelect.disabled = false;
            statusSelect.style.pointerEvents = "auto";
            statusSelect.className = "form-control";
            if (statusSelect.value === "duty" || statusSelect.value === "restrict") {
                statusSelect.value = "";
            }
        }
    }

    const studentInput = document.getElementById('student_code');
    if (studentInput) {
        studentInput.addEventListener('blur', checkStudentData);
        studentInput.addEventListener('change', checkStudentData);
        studentInput.addEventListener('input', function() {
            if (this.value.trim().length >= 5) {
                checkStudentData();
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
                    <div style="font-size: 0.9rem; color: #475569; margin-bottom: 15px;">
                        กรุณาตรวจสอบข้อมูลให้ถูกต้องก่อนกดยืนยัน
                    </div>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: 12px; margin-bottom: 15px; text-align: left;">
                        <span style="font-size: 0.85rem; font-weight: 600; color: #b91c1c;">
                            <i class="fas fa-exclamation-triangle"></i> ข้อควรระวัง : ไม่สามารถแก้ไขข้อมูลภายหลังได้ คิดดีๆ ถ้าไม่อยากใส่ชุดจับหมู
                        </span>
                    </div>
                    <div style="color: #64748b; font-weight: 500;">
                        กรุณารอ <b id="swal-timer" style="color: var(--primary); font-size: 1.2rem; margin: 0 4px;">10</b> วินาที
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#5a42a0',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'ยืนยันการส่งข้อมูล',
                cancelButtonText: 'ยกเลิก',
                allowOutsideClick: false,
                didOpen: () => {
                    const confirmBtn = Swal.getConfirmButton();
                    confirmBtn.disabled = true;
                    confirmBtn.style.opacity = '0.5';
                    confirmBtn.style.cursor = 'not-allowed';
                    
                    const timerElement = document.getElementById("swal-timer");
                    let timeLeft = 10;
                    
                    timerInterval = setInterval(()=>{
                        timeLeft--;
                        if(timerElement) timerElement.textContent = timeLeft;
                        if(timeLeft <= 0){
                            clearInterval(timerInterval);
                            confirmBtn.disabled = false;
                            confirmBtn.style.opacity = '1';
                            confirmBtn.style.cursor = 'pointer';
                            if(timerElement && timerElement.parentElement) {
                                timerElement.parentElement.innerHTML = '<span style="color: #16a34a; font-weight: 600;"><i class="fas fa-check-circle"></i> ท่านสามารถกดยืนยันได้แล้ว</span>';
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
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';
                    
                    form.submit();
                }
            });
        });
    }
<?php endif; ?>
</script>
</body>
</html>
