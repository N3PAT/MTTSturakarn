<?php
// เปิดการแสดง Error เพื่อดูสาเหตุทันทีหากยังพบปัญหา
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. จัดการ Loading Screen
$show_loading = false;
if (!isset($_SESSION['has_seen_loading_fill'])) {
    $show_loading = true;
    $_SESSION['has_seen_loading_fill'] = true;
}

date_default_timezone_set('Asia/Bangkok');

// สร้าง CSRF Token รองรับ PHP ทุกเวอร์ชัน
if (empty($_SESSION['csrf_token'])) {
    if (function_exists('random_bytes')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $_SESSION['csrf_token'] = md5(uniqid(rand(), true));
    }
}

// โหลดไฟล์เชื่อมต่อฐานข้อมูล
if (file_exists('db.php')) {
    require_once 'db.php';
} else {
    die("ข้อผิดพลาด: ไม่พบไฟล์ db.php กรุณาตรวจสอบตำแหน่งไฟล์");
}

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
            http_response_code(500);
            throw new Exception('เชื่อมต่อฐานข้อมูลไม่ได้');
        }
        
        $stmt = $conn->prepare("SELECT prefix, first_name, last_name, section FROM students WHERE student_code = ? LIMIT 1");
        if (!$stmt) {
            http_response_code(500);
            throw new Exception('SQL Error: ' . $conn->error);
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
        $stmt->close();
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

$days_until_friday = (5 - $current_day_of_week + 7) % 7;
$target_dt = clone $dt;
$target_dt->modify("+$days_until_friday days");
$target_date = $target_dt->format('Y-m-d');

$start_date = "2026-07-06"; 

$is_voting_open = (
    in_array($current_day_of_week, [6, 7, 1]) ||
    ($current_day_of_week == 2 && $current_hour < 16)
);

$alert_type = "";
$message = "";

// ---------------- 2. ลอจิกการคำนวณเวร ----------------
$duty_roster_ids = [];
try {
    if (isset($conn) && $conn) {
        $res = $conn->query("SELECT student_code FROM students ORDER BY id ASC");
        if ($res && $res->num_rows > 0) {
            $all_students = [];
            while ($row = $res->fetch_assoc()) {
                $all_students[] = $row['student_code'];
            }
            
            $total_students = count($all_students);
            $diff_days = max(0, floor((strtotime($target_date) - strtotime($start_date)) / 86400));
            $start_index = ($diff_days * 16) % $total_students;
            
            for ($i = 0; $i < 16; $i++) {
                $idx = ($start_index + $i) % $total_students;
                $duty_roster_ids[] = $all_students[$idx];
            }
        }
    }
} catch (Exception $e) {
    error_log("Duty Calculation Error: " . $e->getMessage());
}

// ---------------- 3. จัดการ Form Submit ----------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $stu_code   = isset($_POST['student_code']) ? trim($_POST['student_code']) : '';
    $section    = isset($_POST['section']) ? trim($_POST['section']) : '';
    $status     = isset($_POST['status']) ? trim($_POST['status']) : '';

    if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $alert_type = "error";
        $message = "เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่";
    } else if (!$is_voting_open) {
        $alert_type = "error";
        $message = "หมดเวลาทำรายการ ระบบเปิดให้ส่งข้อมูลเฉพาะวันเสาร์ถึงวันอังคารก่อนเวลา 16:00 น. เท่านั้น";
    } else {
        $allowed_status = ['home', 'stay', 'duty', 'restrict'];
        
        if (!in_array($status, $allowed_status)) {
            $alert_type = "error";
            $message = "สถานะไม่ถูกต้องตามที่ระบบกำหนด";
        } else if ($status === 'duty' && !in_array($stu_code, $duty_roster_ids)) {
            $alert_type = "error";
            $message = "รหัส " . htmlspecialchars($stu_code) . " ไม่ได้มีรายชื่อปฏิบัติหน้าที่ในสัปดาห์นี้";
        } else {
            try {
                // ตรวจสอบการโหวตซ้ำ
                $stmt_check = $conn->prepare("SELECT id FROM weekly_votes WHERE student_code = ? AND vote_date = ?");
                $stmt_check->bind_param("ss", $stu_code, $target_date);
                $stmt_check->execute();
                
                if ($stmt_check->get_result()->num_rows > 0) {
                    $alert_type = "error";
                    $message = "รหัส " . htmlspecialchars($stu_code) . " ได้บันทึกข้อมูลของสัปดาห์นี้ไปแล้ว";
                } else {
                    // อัปเดตตอนเรียน
                    $stmt_update = $conn->prepare("UPDATE students SET section = ? WHERE student_code = ?");
                    $stmt_update->bind_param("ss", $section, $stu_code);
                    $stmt_update->execute();

                    // บันทึกการโหวต
                    $real_time_now = date('Y-m-d H:i:s');
                    $stmt_insert = $conn->prepare("INSERT INTO weekly_votes (student_code, vote_date, status, created_at) VALUES (?, ?, ?, ?)");
                    $stmt_insert->bind_param("ssss", $stu_code, $target_date, $status, $real_time_now);
                    
                    if ($stmt_insert->execute()) {
                        $alert_type = "success";
                        $message = "บันทึกข้อมูลรหัส " . htmlspecialchars($stu_code) . " ประจำวันศุกร์ที่ " . date('d/m/Y', strtotime($target_date)) . " เรียบร้อยแล้ว";
                    }
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
      user-select: none;
    }
    
    /* Loading Screen */
    #page-loader { position: fixed; inset: 0; background-color: #ffffff; z-index: 9999; display: flex; flex-direction: column; justify-content: center; align-items: center; transition: opacity 0.5s, visibility 0.5s; }
    #page-loader.hidden { opacity: 0; visibility: hidden; }
    .loader-logo { width: 80px; height: 80px; border-radius: 15px; animation: pulse 1.5s infinite ease-in-out; }
    .loader-spinner { margin-top: 20px; width: 40px; height: 40px; border: 4px solid rgba(90, 66, 160, 0.2); border-top: 4px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
    .loader-text { margin-top: 15px; color: var(--primary); font-weight: 600; font-size: 0.9rem; letter-spacing: 1px; animation: flash 1.5s infinite; }
    
    @keyframes pulse { 0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(90, 66, 160, 0); } 50% { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(90, 66, 160, 0); } }
    @keyframes spin { 100% { transform: rotate(360deg); } }
    @keyframes flash { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    
    /* Animations */
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }

    /* Layout */
    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; position: relative; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
    
    /* Header */
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--bg-color); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    
    /* Titles & Badges */
    .section-title { display: flex; align-items: center; gap: 10px; margin: 10px 20px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.05rem; font-weight: 600; color: var(--text-dark); margin: 0; }
    
    .date-badge { display: inline-flex; align-items: center; gap: 6px; background: #e0e7ff; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; color: var(--primary); margin: 0 20px 15px; border: 1px solid #c7d2fe; }
    
    /* Form & Inputs */
    .form-card { background: white; border-radius: 14px; padding: 20px; margin: 0 20px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }
    .text-danger { color: #ef4444; }
    .icon-label { color: var(--primary); font-size: 0.95rem; }
    
    .form-control { width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid #cbd5e1; background: #ffffff; font-family: inherit; font-size: 0.95rem; color: var(--text-dark); outline: none; transition: all 0.2s; }
    .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(90, 66, 160, 0.1); }
    select.form-control { appearance: none; cursor: pointer; }
    
    .select-wrapper { position: relative; }
    .select-icon { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--primary); pointer-events: none; }
    
    .input-readonly { background: #f1f5f9; color: #64748b; border-style: dashed; }
    .input-success { background: #f0fdf4; border-color: #22c55e; color: #15803d; }
    .input-error { background: #fef2f2; border-color: #ef4444; color: #b91c1c; }
    .help-text { font-size: 0.75rem; color: var(--text-gray); margin-top: 6px; display: flex; gap: 4px; }
    
    /* Remark Box */
    .remark-box { background-color: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #3b82f6; border-radius: 10px; padding: 15px; margin-top: 15px; display: flex; gap: 12px; align-items: flex-start; }
    .remark-box i { color: #2563eb; font-size: 1.2rem; margin-top: 2px; }
    .remark-box p { margin: 0; font-size: 0.85rem; color: #1e3a8a; line-height: 1.5; font-weight: 500; }
    
    /* Submit Button */
    .btn-submit { width: 100%; padding: 14px; background-color: var(--primary); color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(90, 66, 160, 0.25); transition: background-color 0.2s, transform 0.2s; font-family: inherit; margin-top: 24px; }
    .btn-submit:active { transform: scale(0.98); background-color: var(--primary-dark); }
    
    /* Closed State */
    .closed-state { text-align: center; padding: 40px 20px; background: white; border-radius: 14px; margin: 0 20px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .closed-icon { width: 64px; height: 64px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 16px; }
    .closed-state h3 { color: #991b1b; margin: 0 0 8px 0; font-size: 1.2rem; }
    .closed-state p { font-size: 0.85rem; color: #b91c1c; margin: 0 0 16px 0; }
    .closed-time { background: #f8fafc; padding: 8px 16px; border-radius: 8px; border: 1px solid #e2e8f0; display: inline-block; font-size: 0.85rem; font-weight: 600; color: #ef4444; }
    
    /* Bottom Nav */
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
    
    /* SweetAlert Override */
    .swal2-popup { font-family: 'Prompt', sans-serif !important; border-radius: 1rem !important; }
  </style>
</head>
<body>

  <?php if ($show_loading): ?>
  <div id="page-loader">
    <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Loading Logo" class="loader-logo" loading="lazy">
    <div class="loader-spinner"></div>
    <div class="loader-text">กำลังโหลดข้อมูล...</div>
  </div>
  <?php endif; ?>

  <div class="app-container">
    
    <!-- Header -->
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>ระบบบริการสารสนเทศ กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
    </div>

    <!-- Title Section -->
    <div class="section-title fade-in-up delay-2">
      <div class="section-pill"></div>
      <h3>โหวตประสงค์กลับบ้าน</h3>
    </div>
    
    <div class="date-badge fade-in-up delay-2">
      <i class="far fa-calendar-alt"></i> วันศุกร์ที่ <?php echo date('d/m/Y', strtotime($target_date)); ?>
    </div>

    <?php if ($is_voting_open): ?>
      <!-- Form Content -->
      <div class="form-card fade-in-up delay-3">
        <form id="statusForm" method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          
          <div class="form-group">
            <label for="student_code"><i class="fas fa-id-badge icon-label"></i> รหัสประจำตัว (นชท.) <span class="text-danger">*</span></label>
            <input type="text" id="student_code" name="student_code" required maxlength="10" inputmode="numeric" class="form-control" placeholder="เช่น 151xx">
          </div>

          <div class="form-group">
            <label for="student_name"><i class="fas fa-user icon-label"></i> ชื่อ-นามสกุล</label>
            <input type="text" id="student_name" readonly tabindex="-1" class="form-control input-readonly" placeholder="ระบบจะแสดงชื่ออัตโนมัติ">
          </div>

          <div class="form-group">
            <label for="section"><i class="fas fa-users icon-label"></i> ตอนเรียนที่ (1-14) <span class="text-danger">*</span></label>
            <input type="text" id="section" name="section" required maxlength="2" inputmode="numeric" class="form-control" placeholder="เช่น 1">
          </div>

          <div class="form-group">
            <label for="status_select"><i class="fas fa-clipboard-check icon-label"></i> เลือกสถานะ <span class="text-danger">*</span></label>
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
              กรณีไม่แน่ใจเรื่องกักบริเวณ รอประกาศจากธุรการ
            </div>
          </div>

          <div class="remark-box">
            <i class="fas fa-headset"></i>
            <p><strong>มีปัญหาการใช้งาน?</strong><br>หากพบปัญหาในการกรอกข้อมูล กรุณาติดต่อ Line ID: <strong>ntw25020</strong></p>
          </div>

          <button type="submit" id="submitBtn" class="btn-submit">
            ดำเนินการส่งข้อมูล <i class="fas fa-paper-plane"></i>
          </button>
        </form>
      </div>

    <?php else: ?>
      <!-- Closed State Content -->
      <div class="closed-state fade-in-up delay-3">
        <div class="closed-icon"><i class="fas fa-lock"></i></div>
        <h3>ระบบปิดรับข้อมูล</h3>
        <p>ระบบเปิดให้ทำรายการเฉพาะช่วงเวลา</p>
        <div class="closed-time">วันเสาร์ - วันอังคาร (ก่อน 16:00 น.)</div>
        <button type="button" onclick="window.location.reload();" class="btn-submit" style="margin-top:20px; width:auto; padding:10px 20px; display:inline-flex;">
          <i class="fas fa-sync-alt"></i> รีเฟรชหน้าเว็บ
        </button>
      </div>
    <?php endif; ?>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="main.php" class="nav-link active">
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
  document.addEventListener("DOMContentLoaded", () => {
    const loader = document.getElementById('page-loader');
    if (loader) {
      setTimeout(() => {
        loader.classList.add('hidden');
        setTimeout(() => loader.remove(), 500);
      }, 1000);
    }
  });

<?php if ($alert_type == 'success'): ?>
    Swal.fire({
        title: 'สำเร็จ', text: <?= json_encode($message) ?>, icon: 'success',
        confirmButtonColor: '#5a42a0', confirmButtonText: 'ตกลง'
    }).then(() => window.location.replace(window.location.pathname));
<?php elseif ($alert_type == 'error'): ?>
    Swal.fire({
        title: 'ข้อผิดพลาด', text: <?= json_encode($message) ?>, icon: 'error',
        confirmButtonColor: '#b71c1c', confirmButtonText: 'ตกลง'
    });
<?php endif; ?>

<?php if ($is_voting_open): ?>
    const dutyIds = <?= json_encode($duty_roster_ids) ?>;

    async function checkStudentData() {
        const stuInput = document.getElementById('student_code');
        const nameInput = document.getElementById('student_name');
        const sectionInput = document.getElementById('section');
        const statusSelect = document.getElementById('status_select');
        const stuCode = stuInput.value.trim();

        if (stuCode === "") {
            nameInput.value = ""; nameInput.className = "form-control input-readonly";
            statusSelect.disabled = false; statusSelect.className = "form-control"; statusSelect.value = "";
            return;
        }

        nameInput.value = "กำลังค้นหาข้อมูล...";
        nameInput.className = "form-control input-readonly";

        try {
            const res = await fetch(`?action=get_student_name&code=${encodeURIComponent(stuCode)}&_t=${Date.now()}`);
            if (!res.ok) throw new Error('Network response error');
            const data = await res.json();

            if (data.found) {
                nameInput.value = data.name;
                nameInput.className = "form-control input-success";
                if (data.section && !sectionInput.value) sectionInput.value = data.section;
            } else {
                nameInput.value = "ไม่พบรหัสนี้ในระบบ";
                nameInput.className = "form-control input-error";
            }
        } catch (err) {
            nameInput.value = "เกิดข้อผิดพลาดในการเชื่อมต่อ";
            nameInput.className = "form-control input-error";
        }

        if (dutyIds.includes(stuCode)) {
            Swal.fire({
                title: 'แจ้งเตือนระบบเวรรักษาการณ์',
                text: 'ท่านมีรายชื่อปฏิบัติหน้าที่ในสัปดาห์นี้ ระบบจะล็อคสถานะเป็นเวรรักษาการณ์อัตโนมัติ',
                icon: 'warning', confirmButtonColor: '#5a42a0'
            });
            statusSelect.value = "duty";
            statusSelect.disabled = true;
            statusSelect.className = "form-control input-error"; 
        } else {
            statusSelect.disabled = false;
            statusSelect.className = "form-control";
            if (statusSelect.value === "duty") statusSelect.value = "";
        }
    }

    const stuCodeElem = document.getElementById('student_code');
    if (stuCodeElem) {
        stuCodeElem.addEventListener('blur', checkStudentData);
        stuCodeElem.addEventListener('input', function() { if (this.value.trim().length >= 5) checkStudentData(); });
    }

    const form = document.getElementById("statusForm");
    if (form) {
        form.addEventListener("submit", function(e){
            e.preventDefault();
            if (!form.checkValidity()) { form.reportValidity(); return; }

            let timerInterval;
            Swal.fire({
                title: 'โปรดยืนยันการทำรายการ',
                html: `
                    <div style="font-size: 0.9rem; margin-bottom: 15px;">กรุณาตรวจสอบข้อมูลให้ถูกต้องก่อนกดยืนยัน</div>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: 12px; margin-bottom: 15px; text-align: left;">
                        <span style="font-size: 0.85rem; font-weight: 600; color: #b91c1c;">
                            <i class="fas fa-exclamation-triangle"></i> ข้อควรระวัง: ไม่สามารถแก้ไขข้อมูลภายหลังได้
                        </span>
                    </div>
                    <div style="color: #64748b; font-weight: 500;">
                        กรุณารอ <b id="swal-timer" style="color: var(--primary); font-size: 1.2rem; margin: 0 4px;">10</b> วินาที
                    </div>`,
                icon: 'warning', showCancelButton: true, confirmButtonColor: '#5a42a0',
                cancelButtonColor: '#94a3b8', confirmButtonText: 'ยืนยันการส่งข้อมูล', cancelButtonText: 'ยกเลิก',
                allowOutsideClick: false,
                didOpen: () => {
                    const confirmBtn = Swal.getConfirmButton();
                    confirmBtn.disabled = true; confirmBtn.style.opacity = '0.5'; confirmBtn.style.cursor = 'not-allowed';
                    
                    let timeLeft = 10;
                    timerInterval = setInterval(() => {
                        timeLeft--;
                        const tElem = document.getElementById("swal-timer");
                        if (tElem) tElem.textContent = timeLeft;
                        if (timeLeft <= 0) {
                            clearInterval(timerInterval);
                            confirmBtn.disabled = false; confirmBtn.style.opacity = '1'; confirmBtn.style.cursor = 'pointer';
                            if (tElem) tElem.parentElement.innerHTML = '<span style="color: #16a34a; font-weight: 600;"><i class="fas fa-check-circle"></i> ท่านสามารถกดยืนยันได้แล้ว</span>';
                        }
                    }, 1000);
                },
                willClose: () => clearInterval(timerInterval)
            }).then((res) => {
                if (res.isConfirmed) {
                    document.getElementById("status_select").disabled = false;
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
