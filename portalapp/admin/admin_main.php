<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

// 1. ป้องกัน HTTP 500 กรณีไฟล์ db.php หาย
if (file_exists('db.php')) {
    require_once 'db.php';
} else {
    die("Error: ไม่พบไฟล์ db.php หรือตั้งชื่อไฟล์ผิด (ตัวพิมพ์เล็ก-ใหญ่มีผลบน Server)");
}

// 2. จัดการการออกจากระบบ (Logout)
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

// 3. ตรวจสอบสิทธิ์การเข้าสู่ระบบ
if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// 4. จัดการระบบ Loading Screen
$show_loading = false;
if (!isset($_SESSION['admin_has_seen_loading'])) {$show_loading = true;
    $_SESSION['admin_has_seen_loading'] = true;
}

$admin_id = $_SESSION['admin_id'];$admin_name = 'ผู้ดูแลระบบ MTTS';

$total_students = 0;
$voted_count = 0;
$today_duties = 0;

// =========================================================
// คำนวณหาวันที่เป้าหมาย (วันศุกร์)
// =========================================================
$tz = new DateTimeZone('Asia/Bangkok');
$dt = new DateTime('now',$tz);

$current_day_of_week = (int)$dt->format('N');
$days_until_friday = 5 -$current_day_of_week;
if ($days_until_friday < 0) {$days_until_friday += 7;
}

$target_dt = clone$dt;
$target_dt->modify("+$days_until_friday days");
$target_date = $target_dt->format('Y-m-d');$start_date = "2026-07-06"; // วันเริ่มต้นคำนวณเวรหลักการหมุนเวียน

// 5. ดึงข้อมูลสถิติ, ข้อมูลแอดมิน และ คำนวณเวร
if (isset($conn) && $conn instanceof mysqli) {$conn->set_charset("utf8mb4");

    try {
        // ดึงข้อมูล Username ของ Admin จากฐานข้อมูล
        $stmt_admin =$conn->prepare("SELECT username FROM admins WHERE id = ?");
        if ($stmt_admin) {$stmt_admin->bind_param("i", $admin_id);$stmt_admin->execute();
            $res_admin =$stmt_admin->get_result();
            if ($row_admin =$res_admin->fetch_assoc()) {
                $admin_name =$row_admin['username'];
            }
            $stmt_admin->close();
        }

        // ยอดนักเรียนทั้งหมด
        $res_std =$conn->query("SELECT COUNT(*) as total FROM students");
        if ($res_std && $row =$res_std->fetch_assoc()) {
            $total_students =$row['total'];
        }

        // ยอดโหวตในสัปดาห์นี้
        $res_vote =$conn->query("SELECT COUNT(DISTINCT student_code) as voted FROM weekly_votes WHERE vote_date = '$target_date'");
        if ($res_vote && $row =$res_vote->fetch_assoc()) {
            $voted_count =$row['voted'];
        }

        // =========================================================
        // คำนวณยอดคนเข้าเวรวันนี้
        // =========================================================
        
        // ดึงรายชื่อนักเรียนทั้งหมดเพื่อมาจำลองรอบเวรตาม Index
        $all_students_for_duty = [];
        $res_all =$conn->query("SELECT student_code FROM students ORDER BY id ASC");
        if ($res_all &&$res_all->num_rows > 0) {
            while ($row =$res_all->fetch_assoc()) {
                $all_students_for_duty[] =$row;
            }
        }
        
        $total_std_count = count($all_students_for_duty);
        if ($total_std_count > 0) {$start_timestamp = strtotime($start_date);$target_timestamp = strtotime($target_date);$diff_days = floor((($target_timestamp -$start_timestamp) / 86400));
            if ($diff_days < 0) {$diff_days = 0;
            }
            
            // คำนวณหา 16 คนของรอบเวรเป้าหมาย
            $start_index = ($diff_days * 16) \%$total_std_count;
            $today_duty_list = [];
            
            for ($i = 0; $i < 16; $i++) {
                $idx = ($start_index + $i) \%$total_std_count;
                if (isset($all_students_for_duty[$idx])) {$today_duty_list[] = $all_students_for_duty[$idx]['student_code'];
                }
            }
            $today_duties = count($today_duty_list); // ยอดปกติคือ 16 คน
        }

    } catch (Throwable $e) {
        error_log("SQL Error in admin_main.php: " . $e->getMessage());
    }
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
      --primary: #0f172a; 
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

    /* Loading Screen */
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
    #page-loader.hidden { opacity: 0; visibility: hidden; }
    .loader-logo { width: 80px; height: 80px; border-radius: 15px; animation: pulse 1.5s infinite ease-in-out; }
    .loader-spinner { margin-top: 20px; width: 40px; height: 40px; border: 4px solid rgba(15, 23, 42, 0.2); border-top: 4px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
    .loader-text { margin-top: 15px; color: var(--primary); font-weight: 600; font-size: 0.9rem; letter-spacing: 1px; animation: flash 1.5s infinite; }

    @keyframes pulse { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(15, 23, 42, 0.4); } 50% { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(15, 23, 42, 0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(15, 23, 42, 0); } }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    @keyframes flash { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }

    /* App Container */
    .app-container {
      width: 100%;
      max-width: 430px;
      background-color: var(--bg-color);
      min-height: 100vh;
      position: relative;
      padding-bottom: 90px;
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }

    /* Header */
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--bg-color); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    
    .noti-btn { position: relative; background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer; }
    .noti-badge { position: absolute; top: -2px; right: -2px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bg-color); }

    /* Admin Card */
    .admin-card { background: linear-gradient(135deg, #0f172a, #1e293b); border-radius: 16px; padding: 20px; margin: 5px 20px 20px; color: white; box-shadow: 0 10px 20px rgba(15, 23, 42, 0.2); position: relative; overflow: hidden; }
    .admin-card::after { content: '\f3ed'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: -15px; bottom: -25px; font-size: 8rem; opacity: 0.05; transform: rotate(-15deg); }
    .badges { display: flex; gap: 8px; margin-bottom: 12px; }
    .badge-item { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2); padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 500; backdrop-filter: blur(8px); }
    .admin-card h2 { font-size: 1.3rem; font-weight: 600; margin: 0 0 4px 0; text-transform: uppercase; }
    .admin-card p { font-size: 0.85rem; margin: 0; color: #cbd5e1; display: flex; align-items: center; gap: 6px; opacity: 0.9; }

    /* Section Title */
    .section-title { display: flex; align-items: center; gap: 10px; margin: 0 20px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 0 20px 20px; }
    .stat-box { background: white; padding: 15px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between; border: 1px solid #f1f5f9; }
    .stat-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; margin-bottom: 10px; }
    .stat-box h3 { font-size: 1.5rem; font-weight: 700; margin: 0 0 2px 0; color: var(--text-dark); line-height: 1; }
    .stat-box p { margin: 0; font-size: 0.75rem; color: var(--text-gray); font-weight: 500; }
    
    .c-blue { background: #eff6ff; color: #3b82f6; }
    .c-green { background: #f0fdf4; color: #10b981; }
    .c-orange { background: #fffbeb; color: #f59e0b; }
    .c-red { background: #fef2f2; color: #ef4444; }

    /* Menu Grid */
    .menu-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 0 20px 25px; }
    .menu-item { background: white; border-radius: 16px; padding: 18px 5px; text-decoration: none; color: var(--text-dark); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border: none; cursor: pointer; font-family: 'Prompt', sans-serif;}
    .menu-item:active { transform: scale(0.95); }
    
    .icon-box { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 10px; }
    .menu-text { font-size: 0.8rem; font-weight: 600; line-height: 1.2; }

    /* Theme Colors */
    .bg-purple { background: #f3e8ff; color: #9333ea; }
    .bg-orange { background: #ffedd5; color: #ea580c; }
    .bg-blue { background: #dbeafe; color: #2563eb; }
    .bg-green { background: #dcfce7; color: #16a34a; }
    .bg-red { background: #fee2e2; color: #dc2626; }

    /* Bottom Navigation */
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <?php if ($show_loading): ?>
  <div id="page-loader">
    <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Loading Logo" class="loader-logo">
    <div class="loader-spinner"></div>
    <div class="loader-text">กำลังโหลดข้อมูล...</div>
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

    <!-- Stats Section -->
    <div class="section-title fade-in-up delay-3">
      <div class="section-pill"></div>
      <h3>ภาพรวมวันที่ <?= date('d/m/Y', strtotime($target_date)) ?></h3>
    </div>
    
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
            <h3><?= number_format(max(0, $total_students -$voted_count)) ?></h3>
            <p>ยังไม่โหวต</p>
        </div>
      </div>
    </div>

    <!-- Menu Section -->
    <div class="section-title fade-in-up delay-4">
      <div class="section-pill"></div>
      <h3>เมนูหลัก</h3>
    </div>

    <div class="menu-grid fade-in-up delay-4">
      <a href="admin_students.php" class="menu-item">
        <div class="icon-box bg-blue"><i class="fas fa-user-edit"></i></div>
        <span class="menu-text">นร.ชท.</span>
      </a>
      
      <a href="admin_duties.php" class="menu-item">
        <div class="icon-box bg-purple"><i class="fas fa-calendar-alt"></i></div>
        <span class="menu-text">จัดเวร</span>
      </a>
      
      <a href="admin_votes.php" class="menu-item">
        <div class="icon-box bg-green"><i class="fas fa-chart-bar"></i></div>
        <span class="menu-text">ผลโหวต</span>
      </a>
      
      <!-- ปุ่มแจ้งเตือน -->
      <button onclick="triggerPushNotification()" class="menu-item">
        <div class="icon-box bg-orange"><i class="fas fa-broadcast-tower"></i></div>
        <span class="menu-text">แจ้งเตือน</span>
      </button>

      <a href="reports.php" class="menu-item">
        <div class="icon-box bg-red"><i class="fas fa-file-pdf"></i></div>
        <span class="menu-text">รายงาน</span>
      </a>

      <a href="admin_restrict.php" class="menu-item">
        <div class="icon-box bg-red"><i class="fas fa-dungeon"></i></div>
        <span class="menu-text">กักบริเวณ</span>
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
          }, 500);
        }, 1200); 
      }
    });

    async function triggerPushNotification() {
      if (!confirm("คุณต้องการส่งแจ้งเตือนไปยังผู้ใช้ที่ยังไม่ได้ทำรายการ ใช่หรือไม่?")) {
          return;
      }

      try {
          const today = new Date();
          const targetDate = today.toLocaleDateString('en-GB'); 

          const response = await fetch('send_push.php', {
              method: 'POST',
              headers: {
                  'Content-Type': 'application/json',
                  'X-API-KEY': 'N4tthap41!nwza'
              },
              body: JSON.stringify({
                  target_date: targetDate
              })
          });

          const data = await response.json();

          if (data.success) {
              alert(`ส่งแจ้งเตือนสำเร็จ!\nส่งไปยัง ${data.sent_count} อุปกรณ์`);
          } else {
              alert(`เกิดข้อผิดพลาด:\n${data.message}`);
          }
      } catch (error) {
          console.error('Fetch Error:', error);
          alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ หรือ URL ไม่ถูกต้อง');
      }
    }
  </script>
</body>
</html>
