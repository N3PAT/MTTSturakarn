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
        $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = 'ผู้ดูแลระบบ MTTS'; 
                
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
// 3. แสดงหน้า Login หากยังไม่ได้เข้าระบบ
// -------------------------------------------------------------
if (empty($_SESSION['admin_id'])) {
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Login - MTTS</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <style>
        :root {
            --primary: #0f172a;
            --primary-light: #1e293b;
            --accent: #3b82f6;
            --bg-color: #f1f5f9;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Prompt', sans-serif; display: flex; justify-content: center; background-color: var(--bg-color); margin: 0; min-height: 100vh; }
        
        .app-container { width: 100%; max-width: 430px; display: flex; flex-direction: column; justify-content: center; padding: 24px; }
        .login-box { background: #ffffff; padding: 2.5rem 2rem; border-radius: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08); position: relative; overflow: hidden; }
        .login-box::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, var(--accent), #60a5fa); }
        
        .logo-box { text-align: center; margin-bottom: 1.5rem; }
        .logo-box img { width: 85px; border-radius: 20px; box-shadow: 0 8px 16px rgba(0,0,0,0.1); }
        .login-box h2 { text-align: center; color: var(--primary); margin: 0 0 0.5rem 0; font-weight: 700; font-size: 1.5rem; }
        .login-box p.subtitle { text-align: center; color: #64748b; font-size: 0.9rem; margin: 0 0 2rem 0; }
        
        .form-group { margin-bottom: 1.5rem; position: relative; }
        .form-group i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.1rem; transition: 0.3s; }
        input[type="text"], input[type="password"] { width: 100%; padding: 1rem 1rem 1rem 45px; border: 2px solid #e2e8f0; border-radius: 14px; font-family: 'Prompt', sans-serif; font-size: 0.95rem; background: #f8fafc; transition: all 0.3s ease; }
        input:focus { outline: none; border-color: var(--accent); background: #ffffff; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        input:focus + i { color: var(--accent); }
        
        button { width: 100%; padding: 1rem; background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border: none; border-radius: 14px; cursor: pointer; font-size: 1.05rem; font-family: 'Prompt', sans-serif; font-weight: 600; transition: all 0.2s; box-shadow: 0 8px 15px rgba(15, 23, 42, 0.2); display: flex; justify-content: center; align-items: center; gap: 8px; }
        button:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.3); }
        button:active { transform: translateY(0); }
        
        .error { color: #dc2626; background: #fef2f2; border-left: 4px solid #dc2626; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; gap: 8px; }
        
        .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="login-box fade-in-up">
            <div class="logo-box">
                <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo">
            </div>
            <h2>เข้าสู่ระบบผู้ดูแล</h2>
            <p class="subtitle">ระบบจัดการหลังบ้าน MTTS</p>
            
            <?php if ($login_error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <input type="text" id="username" name="username" placeholder="ชื่อผู้ใช้งาน (Username)" required>
                    <i class="fas fa-user"></i>
                </div>
                <div class="form-group">
                    <input type="password" id="password" name="password" placeholder="รหัสผ่าน (Password)" required>
                    <i class="fas fa-lock"></i>
                </div>
                <button type="submit">เข้าสู่ระบบ <i class="fas fa-arrow-right"></i></button>
            </form>
        </div>
    </div>
</body>
</html>
<?php
    exit(); 
}

// =========================================================================
// 4. ส่วนของหน้า Dashboard (ทำงานเมื่อ Session ถูกสร้างแล้วเท่านั้น)
// =========================================================================

$show_loading = false;
if (!isset($_SESSION['admin_has_seen_loading'])) {
    $show_loading = true;
    $_SESSION['admin_has_seen_loading'] = true;
}

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'ผู้ดูแลระบบ MTTS'; 

$total_students = 0;
$voted_count = 0;
$today_duties = 0;

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection error");
    }
    $conn->set_charset("utf8mb4");

    $res_std = $conn->query("SELECT COUNT(*) as total FROM students");
    if ($res_std && $row = $res_std->fetch_assoc()) { $total_students = $row['total']; }

    $res_vote = $conn->query("SELECT COUNT(DISTINCT student_code) as voted FROM votes WHERE YEARWEEK(vote_date, 1) = YEARWEEK(CURDATE(), 1)");
    if ($res_vote && $row = $res_vote->fetch_assoc()) { $voted_count = $row['voted']; }

    $res_duty = $conn->query("SELECT COUNT(*) as today_duty FROM student_duties WHERE duty_date = CURDATE()");
    if ($res_duty && $row = $res_duty->fetch_assoc()) { $today_duties = $row['today_duty']; }

} catch (Throwable $e) {
    error_log("Admin System error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>หน้าหลักผู้ดูแล - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      var(--primary): #0f172a; 
      --primary: #0f172a; 
      --bg-color: #f8fafc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; user-select: none; }

    /* Loading Screen */
    #page-loader { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: #ffffff; z-index: 9999; display: flex; flex-direction: column; justify-content: center; align-items: center; transition: opacity 0.4s ease, visibility 0.4s ease; }
    #page-loader.hidden { opacity: 0; visibility: hidden; }
    .loader-logo { width: 80px; height: 80px; border-radius: 18px; animation: pulse 1.5s infinite ease-in-out; }
    .loader-spinner { margin-top: 24px; width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top: 3px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes pulse { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(15, 23, 42, 0.2); } 50% { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(15, 23, 42, 0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(15, 23, 42, 0); } }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }

    /* App Container */
    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; position: relative; padding-bottom: 100px; box-shadow: 0 0 30px rgba(0,0,0,0.05); overflow-x: hidden; }

    /* Header */
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 10px; background: transparent; }
    .header-left { display: flex; align-items: center; gap: 14px; }
    .school-logo { width: 48px; height: 48px; object-fit: contain; border-radius: 14px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
    .school-name h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.8rem; color: var(--text-gray); margin: 0; }
    
    .noti-btn { position: relative; background: #ffffff; border: none; font-size: 1.2rem; color: var(--text-dark); cursor: pointer; width: 42px; height: 42px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: center; }
    .noti-badge { position: absolute; top: -2px; right: -2px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff; }

    /* Admin Card */
    .admin-card { background: linear-gradient(135deg, #0f172a, #1e293b); border-radius: 20px; padding: 24px; margin: 15px 24px 24px; color: white; box-shadow: 0 15px 30px rgba(15, 23, 42, 0.2); position: relative; overflow: hidden; }
    .admin-card::after { content: '\f3ed'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: -15px; bottom: -25px; font-size: 8rem; opacity: 0.05; transform: rotate(-15deg); }
    .badges { display: flex; gap: 8px; margin-bottom: 14px; }
    .badge-item { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2); padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 500; backdrop-filter: blur(8px); }
    .admin-card h2 { font-size: 1.4rem; font-weight: 600; margin: 0 0 6px 0; letter-spacing: 0.5px; }
    .admin-card p { font-size: 0.85rem; margin: 0; color: #cbd5e1; display: flex; align-items: center; gap: 6px; }

    /* Section Title */
    .section-title { display: flex; align-items: center; justify-content: space-between; margin: 0 24px 16px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    .section-title a { font-size: 0.8rem; color: #3b82f6; text-decoration: none; font-weight: 600; }

    /* Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin: 0 24px 28px; }
    .stat-box { background: white; padding: 20px; border-radius: 20px; box-shadow: 0 10px 20px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between; border: 1px solid #f1f5f9; }
    .stat-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; margin-bottom: 12px; }
    .stat-box h3 { font-size: 1.8rem; font-weight: 700; margin: 0 0 4px 0; color: var(--text-dark); line-height: 1; }
    .stat-box p { margin: 0; font-size: 0.8rem; color: var(--text-gray); font-weight: 500; }
    
    .c-blue { background: #eff6ff; color: #3b82f6; }
    .c-green { background: #f0fdf4; color: #10b981; }
    .c-orange { background: #fffbeb; color: #f59e0b; }
    .c-red { background: #fef2f2; color: #ef4444; }

    /* App Icons Grid (Menu) */
    .app-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px 12px; margin: 0 24px 28px; }
    .app-btn { display: flex; flex-direction: column; align-items: center; text-decoration: none; gap: 8px; cursor: pointer; }
    .app-icon { width: 58px; height: 58px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; box-shadow: 0 8px 16px rgba(0,0,0,0.1); transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
    .app-btn:hover .app-icon { transform: translateY(-4px) scale(1.05); box-shadow: 0 12px 20px rgba(0,0,0,0.15); }
    .app-btn:active .app-icon { transform: scale(0.95); }
    .app-btn span { font-size: 0.75rem; color: var(--text-dark); font-weight: 500; text-align: center; line-height: 1.2; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* App Colors */
    .bg-grad-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    .bg-grad-purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
    .bg-grad-green { background: linear-gradient(135deg, #10b981, #059669); }
    .bg-grad-orange { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .bg-grad-red { background: linear-gradient(135deg, #ef4444, #dc2626); }

    /* Bottom Navigation */
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 80px; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); border-top: 1px solid #f1f5f9; display: flex; justify-content: space-around; align-items: center; padding: 0 10px 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: #94a3b8; font-size: 0.75rem; font-weight: 500; width: 33%; gap: 6px; height: 100%; transition: 0.3s; }
    .nav-link i { font-size: 1.4rem; transition: transform 0.3s; }
    .nav-link.active { color: var(--primary); font-weight: 600; }
    .nav-link.active i { transform: translateY(-2px); }
    .nav-link:hover { color: var(--primary); }
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
    
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>แผงควบคุมระบบ</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <button class="noti-btn">
        <i class="far fa-bell"></i>
        <div class="noti-badge">2</div>
      </button>
    </div>

    <!-- Admin Profile Card -->
    <div class="admin-card fade-in-up delay-2">
      <div class="badges">
        <div class="badge-item"><i class="fas fa-crown"></i> สิทธิ์ผู้ดูแลระบบ</div>
      </div>
      <h2><?= htmlspecialchars($admin_name) ?></h2>
      <p><i class="fas fa-circle" style="color: #4ade80; font-size: 0.6rem;"></i> สถานะระบบ: ทำงานปกติ (Online)</p>
    </div>

    <div class="section-title fade-in-up delay-3">
      <h3>ภาพรวมวันนี้</h3>
    </div>
    
    <!-- Stats Grid -->
    <div class="stats-grid fade-in-up delay-3">
      <div class="stat-box">
        <div class="stat-icon c-blue"><i class="fas fa-users"></i></div>
        <div>
            <h3><?= number_format($total_students) ?></h3>
            <p>นักเรียนทั้งหมด</p>
        </div>
      </div>
      <div class="stat-box">
        <div class="stat-icon c-green"><i class="fas fa-check-circle"></i></div>
        <div>
            <h3><?= number_format($voted_count) ?></h3>
            <p>โหวตสัปดาห์นี้</p>
        </div>
      </div>
      <div class="stat-box">
        <div class="stat-icon c-orange"><i class="fas fa-user-shield"></i></div>
        <div>
            <h3><?= number_format($today_duties) ?></h3>
            <p>เข้าเวรวันนี้</p>
        </div>
      </div>
      <div class="stat-box">
        <div class="stat-icon c-red"><i class="fas fa-exclamation-circle"></i></div>
        <div>
            <h3><?= number_format(max(0, $total_students - $voted_count)) ?></h3>
            <p>ยังไม่โหวต</p>
        </div>
      </div>
    </div>

    <div class="section-title fade-in-up delay-4">
      <h3>แอปพลิเคชัน</h3>
    </div>

    <!-- App Grid Menu -->
    <div class="app-grid fade-in-up delay-4">
      <a href="admin_students.php" class="app-btn">
        <div class="app-icon bg-grad-blue"><i class="fas fa-user-edit"></i></div>
        <span>นร.ชท.</span>
      </a>
      <a href="admin_duties.php" class="app-btn">
        <div class="app-icon bg-grad-purple"><i class="fas fa-calendar-alt"></i></div>
        <span>จัดเวร</span>
      </a>
      <a href="admin_votes.php" class="app-btn">
        <div class="app-icon bg-grad-green"><i class="fas fa-chart-bar"></i></div>
        <span>ผลโหวต</span>
      </a>
      <a href="send_push.php" class="app-btn">
        <div class="app-icon bg-grad-orange"><i class="fas fa-broadcast-tower"></i></div>
        <span>แจ้งเตือน</span>
      </a>
      <a href="reports.php" class="app-btn">
        <div class="app-icon bg-grad-red"><i class="fas fa-file-pdf"></i></div>
        <span>รายงาน</span>
      </a>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="#" class="nav-link active">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="admin_students.php" class="nav-link">
        <i class="fas fa-users"></i>
        <span>นักเรียน</span>
      </a>
      <a href="?logout=1" class="nav-link" style="color: #ef4444;" onclick="return confirm('ยืนยันการออกจากระบบ?');">
        <i class="fas fa-sign-out-alt"></i>
        <span>ออกระบบ</span>
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
          }, 400);
        }, 1000);
      }
    });
  </script>
</body>
</html>
