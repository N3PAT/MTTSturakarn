<?php
session_start();
require_once 'db.php'; // คาดหวังว่ามีการเชื่อมต่อฐานข้อมูลตัวแปร $conn (mysqli) ในไฟล์นี้

// -------------------------------------------------------------
// 1. จัดการการออกจากระบบ (Logout)
// -------------------------------------------------------------
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// -------------------------------------------------------------
// 2. จัดการการเข้าสู่ระบบ (Login POST Request)
// -------------------------------------------------------------
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        // ใช้ mysqli ตรวจสอบข้อมูลในตาราง admins
        $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();

            // ตรวจสอบรหัสผ่านที่ถูกแฮชไว้ด้วย password_verify
            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = 'ผู้ดูแลระบบ MTTS'; // ปรับให้ดึงจากฐานข้อมูลได้ถ้ามีคอลัมน์ชื่อ
                
                // รีเฟรชหน้าเพื่อล้างค่า POST
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $login_error = "ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง";
            }
            $stmt->close();
        }
    } else {
        $login_error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    }
}

// -------------------------------------------------------------
// 3. แสดงหน้า Login หากยังไม่ได้เข้าระบบ (แล้วหยุดการทำงาน)
// -------------------------------------------------------------
if (empty($_SESSION['admin_id'])) {
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - MTTS</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <style>
        body { font-family: 'Prompt', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #e2e8f0; margin: 0; }
        .login-container { background: #fff; padding: 2rem; border-radius: 16px; box-shadow: 0 10px 20px rgba(30,41,59,0.1); width: 100%; max-width: 400px; margin: 20px; }
        .login-container h2 { text-align: center; color: #1e293b; margin-top: 0; font-weight: 600; }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; margin-bottom: 0.5rem; color: #64748b; font-size: 0.9rem; }
        input[type="text"], input[type="password"] { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-family: 'Prompt', sans-serif; }
        input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        button { width: 100%; padding: 0.75rem; background-color: #1e293b; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 1rem; font-family: 'Prompt', sans-serif; font-weight: 500; transition: 0.2s; }
        button:hover { background-color: #334155; }
        .error { color: #ef4444; background: #fee2e2; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; text-align: center; font-size: 0.9rem; }
        .logo-box { text-align: center; margin-bottom: 1rem; }
        .logo-box img { width: 70px; border-radius: 10px; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-box">
            <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo">
        </div>
        <h2>เข้าสู่ระบบผู้ดูแล</h2>
        
        <?php if ($login_error): ?>
            <div class="error"><?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label for="username">ชื่อผู้ใช้งาน (Username)</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">รหัสผ่าน (Password)</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">เข้าสู่ระบบ</button>
        </form>
    </div>
</body>
</html>
<?php
    // คำสั่ง exit สำคัญมาก เพื่อไม่ให้โค้ดส่วน Dashboard ด้านล่างทำงานหากยังไม่ได้ล็อกอิน
    exit(); 
}

// =========================================================================
// 4. ส่วนของหน้า Dashboard (ทำงานเมื่อ Session ถูกสร้างแล้วเท่านั้น)
// =========================================================================

// เช็คว่าเคยแสดงหน้า Loading ไปหรือยังใน Session นี้
$show_loading = false;
if (!isset($_SESSION['admin_has_seen_loading'])) {
    $show_loading = true;
    $_SESSION['admin_has_seen_loading'] = true;
}

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'ผู้ดูแลระบบ MTTS'; 

// ตัวแปรสำหรับเก็บสถิติหน้า Dashboard
$total_students = 0;
$voted_count = 0;
$today_duties = 0;

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection error");
    }
    $conn->set_charset("utf8mb4");

    // 1. นับจำนวนนักเรียนทั้งหมด
    $res_std = $conn->query("SELECT COUNT(*) as total FROM students");
    if ($res_std && $row = $res_std->fetch_assoc()) {
        $total_students = $row['total'];
    }

    // 2. นับจำนวนคนที่โหวตประสงค์กลับบ้านในสัปดาห์นี้
    $res_vote = $conn->query("
        SELECT COUNT(DISTINCT student_code) as voted 
        FROM votes 
        WHERE YEARWEEK(vote_date, 1) = YEARWEEK(CURDATE(), 1)
    ");
    if ($res_vote && $row = $res_vote->fetch_assoc()) {
        $voted_count = $row['voted'];
    }

    // 3. นับจำนวนนักเรียนที่เข้าเวรใน "วันนี้"
    $res_duty = $conn->query("
        SELECT COUNT(*) as today_duty 
        FROM student_duties 
        WHERE duty_date = CURDATE()
    ");
    if ($res_duty && $row = $res_duty->fetch_assoc()) {
        $today_duties = $row['today_duty'];
    }

} catch (Throwable $e) {
    error_log("Admin System error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ระบบจัดการหลังบ้าน - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      var(--primary): #1e293b; 
      --primary-light: #334155;
      --bg-color: #f6f8fc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; user-select: none; }

    /* Loading Screen Animations */
    #page-loader { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: #ffffff; z-index: 9999; display: flex; flex-direction: column; justify-content: center; align-items: center; transition: opacity 0.5s ease, visibility 0.5s ease; }
    #page-loader.hidden { opacity: 0; visibility: hidden; }
    .loader-logo { width: 80px; height: 80px; border-radius: 15px; animation: pulse 1.5s infinite ease-in-out; }
    .loader-spinner { margin-top: 20px; width: 40px; height: 40px; border: 4px solid rgba(30, 41, 59, 0.2); border-top: 4px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
    .loader-text { margin-top: 15px; color: var(--primary); font-weight: 600; font-size: 0.9rem; letter-spacing: 1px; animation: flash 1.5s infinite; }

    @keyframes pulse { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(30, 41, 59, 0.4); } 50% { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(30, 41, 59, 0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(30, 41, 59, 0); } }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    @keyframes flash { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }

    /* Header */
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--bg-color); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    .noti-btn { background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer; }

    /* Admin Card */
    .admin-card { background: linear-gradient(135deg, var(--primary), var(--primary-light)); border-radius: 16px; padding: 20px; margin: 5px 20px 20px; color: white; box-shadow: 0 10px 20px rgba(30, 41, 59, 0.2); }
    .badges { display: flex; gap: 8px; margin-bottom: 12px; }
    .badge-item { background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.4); padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 500; }
    .admin-card h2 { font-size: 1.3rem; font-weight: 600; margin: 0 0 4px 0; }
    .admin-card p { font-size: 0.85rem; margin: 0; opacity: 0.9; }

    .section-title { display: flex; align-items: center; gap: 10px; margin: 0 20px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* Dashboard Stats Box */
    .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 0 20px 25px; }
    .stat-box { background: white; padding: 15px; border-radius: 14px; display: flex; flex-direction: column; align-items: flex-start; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border-left: 4px solid var(--primary); }
    .stat-box.green { border-left-color: #10b981; }
    .stat-box.orange { border-left-color: #f59e0b; }
    .stat-box h3 { font-size: 1.5rem; font-weight: 700; margin: 0 0 5px 0; color: var(--text-dark); }
    .stat-box p { margin: 0; font-size: 0.75rem; color: var(--text-gray); font-weight: 500; }

    /* Menu Grid */
    .menu-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 0 20px 25px; }
    .menu-item { background: white; border-radius: 16px; padding: 18px 5px; text-decoration: none; color: var(--text-dark); display: flex; flex-direction: column; align-items: center; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: transform 0.2s; }
    .menu-item:active { transform: scale(0.95); }
    .icon-box { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin-bottom: 10px; }
    .menu-text { font-size: 0.75rem; font-weight: 600; line-height: 1.2; }

    .bg-blue { background: #e0f2fe; color: #0284c7; }
    .bg-green { background: #dcfce7; color: #16a34a; }
    .bg-purple { background: #f3e8ff; color: #9333ea; }
    .bg-red { background: #fee2e2; color: #dc2626; }
    .bg-yellow { background: #fef9c3; color: #ca8a04; }

    /* Layout */
    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; position: relative; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <!-- Loading Screen -->
  <?php if ($show_loading): ?>
  <div id="page-loader">
    <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Loading Logo" class="loader-logo">
    <div class="loader-spinner"></div>
    <div class="loader-text">กำลังโหลดระบบหลังบ้าน...</div>
  </div>
  <?php endif; ?>

  <div class="app-container">
    
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>ส่วนผู้ดูแลระบบ (Admin)</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <button class="noti-btn"><i class="fas fa-cog"></i></button>
    </div>

    <div class="admin-card fade-in-up delay-2">
      <div class="badges">
        <div class="badge-item"><i class="fas fa-shield-alt"></i> ผู้ดูแลระบบ</div>
      </div>
      <h2><?= htmlspecialchars($admin_name) ?></h2>
      <p>สถานะระบบ: ทำงานปกติ (Online)</p>
    </div>

    <div class="section-title fade-in-up delay-3">
      <div class="section-pill"></div>
      <h3>ภาพรวมวันนี้</h3>
    </div>
    
    <div class="stats-grid fade-in-up delay-3">
      <div class="stat-box">
        <h3><?= number_format($total_students) ?></h3>
        <p>นักเรียนทั้งหมด (นาย)</p>
      </div>
      <div class="stat-box green">
        <h3><?= number_format($voted_count) ?></h3>
        <p>โหวตสัปดาห์นี้แล้ว</p>
      </div>
      <div class="stat-box orange">
        <h3><?= number_format($today_duties) ?></h3>
        <p>นักเรียนเข้าเวรวันนี้</p>
      </div>
      <div class="stat-box" style="border-left-color: #ef4444;">
        <h3><?= number_format(max(0, $total_students - $voted_count)) ?></h3>
        <p>ยังไม่ทำการโหวต</p>
      </div>
    </div>

    <div class="section-title fade-in-up delay-4">
      <div class="section-pill"></div>
      <h3>เครื่องมือจัดการ</h3>
    </div>

    <div class="menu-grid fade-in-up delay-4">
      <a href="admin_students.php" class="menu-item">
        <div class="icon-box bg-blue"><i class="fas fa-users"></i></div>
        <span class="menu-text">จัดการ<br>นักเรียน</span>
      </a>
      <a href="admin_duties.php" class="menu-item">
        <div class="icon-box bg-purple"><i class="fas fa-calendar-check"></i></div>
        <span class="menu-text">จัดตาราง<br>เวรยาม</span>
      </a>
      <a href="admin_votes.php" class="menu-item">
        <div class="icon-box bg-green"><i class="fas fa-poll"></i></div>
        <span class="menu-text">สรุปผล<br>การโหวต</span>
      </a>
      <a href="send_push.php" class="menu-item">
        <div class="icon-box bg-red"><i class="fas fa-paper-plane"></i></div>
        <span class="menu-text">ส่งแจ้งเตือน<br>(Push Noti)</span>
      </a>
      <a href="reports.php" class="menu-item">
        <div class="icon-box bg-yellow"><i class="fas fa-file-alt"></i></div>
        <span class="menu-text">รายงาน<br>เอกสาร</span>
      </a>
    </div>

    <div class="bottom-nav">
      <a href="#" class="nav-link active">
        <i class="fas fa-chart-pie"></i>
        <span>Dashboard</span>
      </a>
      <a href="admin_students.php" class="nav-link">
        <i class="fas fa-user-graduate"></i>
        <span>นักเรียน</span>
      </a>
      <!-- เปลี่ยนปุ่มออกจากระบบเป็นพารามิเตอร์ ?logout=1 เพื่อใช้งานร่วมกับไฟล์นี้ได้เลย -->
      <a href="?logout=1" class="nav-link" style="color: #ef4444;" onclick="return confirm('ยืนยันการออกจากระบบ?');">
        <i class="fas fa-sign-out-alt"></i>
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
</body>
</html>
