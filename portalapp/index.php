<?php
session_start();
require_once 'db.php';

/* ตรวจสอบการล็อกอินนักเรียนผ่าน Session */
if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

$studentCode = $_SESSION['student_code'];

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("ไม่พบการเชื่อมต่อฐานข้อมูลตัวแปร \$conn แบบ MySQLi");
    }

    $conn->set_charset("utf8mb4");

    /* ดึงข้อมูลจากตาราง students */
    $stmt = $conn->prepare("
        SELECT student_code, prefix, first_name, last_name, platoon, company, battalion, section, image 
        FROM students 
        WHERE student_code = ? 
        LIMIT 1
    ");
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

    /* นับจำนวนคำขอลาของนักเรียนที่อยู่ระหว่าง "รออนุมัติ" */
    $pending_count = 0;
    $stmt_leave = $conn->prepare("
        SELECT COUNT(*) AS total 
        FROM leave_requests 
        WHERE student_code = ? AND status = 'รออนุมัติ'
    ");
    if ($stmt_leave) {
        $stmt_leave->bind_param("s", $studentCode);
        $stmt_leave->execute();
        $res_leave = $stmt_leave->get_result();
        if ($row_leave = $res_leave->fetch_assoc()) {
            $pending_count = (int)$row_leave['total'];
        }
        $stmt_leave->close();
    }

} catch (Throwable $e) {
    die("เกิดข้อผิดพลาดของระบบฐานข้อมูล: " . $e->getMessage());
}

/* กำหนดตัวแปรแสดงผล */
$name = trim(($student['prefix'] ?? '') . $student['first_name'] . ' ' . $student['last_name']);
$student_code_display = "รหัส: " . $student['student_code'];
$profile_image = $student['image'] ?? '';

// จัดรูปแบบหน่วยสังกัด (กองพัน / กองร้อย / หมวด / หมู่)
$unit_parts = [];
if (!empty($student['battalion'])) $unit_parts[] = "พัน." . $student['battalion'];
if (!empty($student['company']))   $unit_parts[] = "ร้อย." . $student['company'];
if (!empty($student['platoon']))   $unit_parts[] = "หมวด " . $student['platoon'];
if (!empty($student['section']))   $unit_parts[] = "หมู่ " . $student['section'];
$student_unit = !empty($unit_parts) ? implode(" ", $unit_parts) : "นักเรียนทหาร";

/* จัดการรูปโปรไฟล์ */
if (empty($profile_image) || $profile_image === "default_student.png" || $profile_image === "0") {
    $profile_image = "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=4361EE&color=fff&size=128";
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>หน้าหลักนักเรียน - MTTS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary-color: #4361ee;
      --primary-light: #e0e8ff;
      --secondary-color: #3f3f46;
      --bg-color: #f4f6f9;
      --card-bg: #ffffff;
    }

    body {
      font-family: 'Prompt', sans-serif;
      background-color: var(--bg-color);
      color: var(--secondary-color);
      padding-bottom: 90px;
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
    }

    /* Profile Header */
    .profile-header {
      background: var(--primary-color);
      color: white;
      padding: 40px 20px 30px;
      border-bottom-left-radius: 30px;
      border-bottom-right-radius: 30px;
      box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
      margin-bottom: 25px;
    }

    .profile-info {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .profile-img {
      width: 75px;
      height: 95px;
      border-radius: 12px; 
      border: 3px solid rgba(255,255,255,0.8);
      object-fit: cover;
      background-color: white;
    }

    .profile-text h4 {
      margin: 0;
      font-size: 1.2rem;
      font-weight: 600;
    }

    .profile-text p {
      margin: 0;
      font-size: 0.9rem;
      opacity: 0.9;
    }

    .badge-homeroom {
      background-color: rgba(255, 255, 255, 0.2);
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
      margin-top: 5px;
      display: inline-block;
      backdrop-filter: blur(5px);
    }

    /* Menu Cards */
    .section-title {
      font-size: 1.1rem;
      font-weight: 600;
      margin-bottom: 15px;
      color: #1e293b;
      padding-left: 5px;
    }

    .menu-card {
      background: var(--card-bg);
      border-radius: 20px;
      padding: 20px 10px;
      text-align: center;
      text-decoration: none;
      color: var(--secondary-color);
      display: block;
      box-shadow: 0 4px 10px rgba(0,0,0,0.03);
      transition: all 0.3s ease;
      height: 100%;
      border: 1px solid rgba(0,0,0,0.02);
    }

    .menu-card:hover, .menu-card:active {
      transform: translateY(-3px);
      box-shadow: 0 8px 15px rgba(67, 97, 238, 0.1);
      color: var(--primary-color);
    }

    @keyframes blinkCard {
      0% { box-shadow: 0 0 0 rgba(255, 0, 0, 0); }
      50% { box-shadow: 0 0 8px rgba(255, 0, 0, 0.6); transform: scale(1.01); }
      100% { box-shadow: 0 0 0 rgba(255, 0, 0, 0); }
    }

    .blink {
      animation: blinkCard 1s infinite;
    }

    .icon-wrapper {
      width: 50px;
      height: 50px;
      border-radius: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
      font-size: 1.5rem;
    }

    .bg-blue { background: #e0e8ff; color: #4361ee; }
    .bg-green { background: #dcfce7; color: #16a34a; }
    .bg-orange { background: #ffedd5; color: #ea580c; }
    .bg-purple { background: #f3e8ff; color: #9333ea; }
    .bg-red { background: #fee2e2; color: #dc2626; }
    .bg-teal { background: #ccfbf1; color: #0d9488; }
    .bg-yellow { background: #fef9c3; color: #ca8a04; }

    .menu-card span {
      font-size: 0.85rem;
      font-weight: 500;
      line-height: 1.2;
      display: block;
    }

    /* Bottom Navigation */
    .bottom-nav {
      position: fixed;
      bottom: 0;
      left: 0;
      width: 100%;
      background: #ffffff;
      box-shadow: 0 -5px 20px rgba(0, 0, 0, 0.05);
      border-top-left-radius: 25px;
      border-top-right-radius: 25px;
      display: flex;
      justify-content: space-around;
      align-items: center;
      padding: 10px 15px 15px;
      z-index: 1000;
    }

    .nav-item {
      text-decoration: none;
      color: #9ca3af;
      display: flex;
      flex-direction: column;
      align-items: center;
      font-size: 0.75rem;
      width: 20%;
      transition: color 0.3s;
    }

    .nav-item i {
      font-size: 1.3rem;
      margin-bottom: 4px;
    }

    .nav-item.active {
      color: var(--primary-color);
      font-weight: 600;
    }

    .nav-item-center {
      position: relative;
    }

    .center-btn {
      background: var(--primary-color);
      color: white !important;
      width: 60px;
      height: 60px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      position: absolute;
      top: -35px;
      left: 50%;
      transform: translateX(-50%);
      box-shadow: 0 5px 15px rgba(67, 97, 238, 0.4);
      border: 4px solid #f4f6f9;
      transition: transform 0.2s;
    }
    
    .center-btn:active {
      transform: translateX(-50%) scale(0.95);
    }

    .nav-item-center span {
      margin-top: 30px;
    }
  </style>
</head>
<body>

  <!-- Header Profile นักเรียน -->
  <div class="profile-header">
    <div class="container">
      <div class="profile-info">
        <img src="<?= htmlspecialchars($profile_image) ?>" alt="Profile" class="profile-img">
        <div class="profile-text">
          <h4><?= htmlspecialchars($name) ?></h4>
          <p><?= htmlspecialchars($student_code_display) ?></p>
          <div class="badge-homeroom">
            <i class="fas fa-shield-alt me-1"></i> สังกัด: <?= htmlspecialchars($student_unit) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="container">
    <h5 class="section-title">เมนูบริการนักเรียน</h5>
    
    <!-- เมนู Grid นักเรียน -->
    <div class="row g-3 mb-4">
      
      <div class="col-6 col-md-3">
        <a href="leave_form.php" class="menu-card">
          <div class="icon-wrapper bg-purple">
            <i class="fas fa-file-signature"></i>
          </div>
          <span>ยื่นเรื่องแจ้งลา</span>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="leave_history.php" class="menu-card position-relative <?= $pending_count > 0 ? 'blink' : '' ?>">
          <?php if ($pending_count > 0): ?>
            <span style="position:absolute; top:8px; right:10px; background:red; color:white; font-size:12px; font-weight:bold; padding:2px 7px; border-radius:999px;">
              <?= $pending_count ?>
            </span>
          <?php endif; ?>
          <div class="icon-wrapper bg-blue">
            <i class="fas fa-history"></i>
          </div>
          <span>ประวัติการแจ้งลา</span>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="student_schedule.php" class="menu-card">
          <div class="icon-wrapper bg-orange">
            <i class="fas fa-calendar-alt"></i>
          </div>
          <span>ตารางเรียน</span>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="student_attendance.php" class="menu-card">
          <div class="icon-wrapper bg-green">
            <i class="fas fa-user-check"></i>
          </div>
          <span>สถิติการเข้าแถว/คาบ</span>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="exam_schedule.php" class="menu-card">
          <div class="icon-wrapper bg-red">
            <i class="fas fa-clipboard-list"></i>
          </div>
          <span>ตารางสอบ/ห้องสอบ</span>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="student_profile.php" class="menu-card">
          <div class="icon-wrapper bg-teal">
            <i class="fas fa-id-card"></i>
          </div>
          <span>ข้อมูลส่วนตัว</span>
        </a>
      </div>

    </div>
  </div>

  <!-- Bottom Navigation Bar นักเรียน -->
  <nav class="bottom-nav">
    <a href="student_dashboard.php" class="nav-item active">
      <i class="fas fa-home"></i>
      <span>หน้าหลัก</span>
    </a>
    
    <a href="student_schedule.php" class="nav-item">
      <i class="fas fa-calendar"></i>
      <span>ตารางเรียน</span>
    </a>

    <a href="leave_form.php" class="nav-item nav-item-center">
      <div class="center-btn">
        <i class="fas fa-edit"></i>
      </div>
      <span>แจ้งลา</span>
    </a>

    <a href="leave_history.php" class="nav-item">
      <i class="fas fa-history"></i>
      <span>ประวัติ</span>
    </a>

    <a href="logout.php" class="nav-item">
      <i class="fas fa-sign-out-alt"></i>
      <span>ออกจากระบบ</span>
    </a>
  </nav>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>