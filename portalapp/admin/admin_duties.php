<?php
session_start();

// เปิดการแสดง Error ชั่วคราวสำหรับการตรวจสอบระบบ
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$message = '';
$message_type = '';

try {
    // 1. ตรวจสอบไฟล์เชื่อมต่อฐานข้อมูล
    if (!file_exists('db.php')) {
        throw new Exception("ไม่พบไฟล์ 'db.php' กรุณาตรวจสอบการอัปโหลดไฟล์");
    }
    require_once 'db.php';
    foreach ([__DIR__ . '/duty_core.php', __DIR__ . '/../duty_core.php'] as $__duty_core) {
        if (is_file($__duty_core)) { require_once $__duty_core; break; }
    }
    if (!function_exists('duty_build_schedule')) {
        throw new Exception("ไม่พบไฟล์ 'duty_core.php' กรุณาอัปโหลดไว้ในโฟลเดอร์เดียวกับ db.php");
    }

    // 2. ตรวจสอบสิทธิ์ Admin
    if (empty($_SESSION['admin_id'])) {
        header("Location: admin_main.php");
        exit();
    }

    if (!isset($conn)) {
        throw new Exception("ไม่พบตัวแปร \$conn ใน db.php");
    }

    $view = $_GET['view'] ?? 'roster'; // 'roster' หรือ 'settings'
    $target_month = $_GET['month'] ?? date('Y-m');

    // แจ้งเตือนจาก URL Parameter
    if (isset($_GET['msg'])) {
        if ($_GET['msg'] === 'settings_updated') {
            $message = "บันทึกการตั้งค่าเรียบร้อยแล้ว";
            $message_type = "success";
        } elseif ($_GET['msg'] === 'duty_swapped') {
            $message = "ดำเนินการสลับเวรสำเร็จ";
            $message_type = "success";
        }
    }

    // 3. จัดการ POST Request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // --- อัปเดตการตั้งค่าระบบ ---
        if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
            $start_date = $_POST['start_date'];
            $per_day = (int)$_POST['students_per_day'];

            $stmt = $conn->prepare("UPDATE duty_settings SET start_date = ?, students_per_day = ? WHERE id = 1");
            if ($stmt) {
                $stmt->bind_param("si", $start_date, $per_day);
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: admin_duties.php?view=settings&msg=settings_updated");
                    exit();
                }
            } else {
                throw new Exception("SQL Error (update_settings): " . $conn->error);
            }
        }

        // --- สลับเวร ---
        if (isset($_POST['action']) && $_POST['action'] === 'swap_duty') {
            $t_date = $_POST['target_date'];
            $o_student = $_POST['original_student'];
            $n_student = $_POST['new_student'];

            $stmt = $conn->prepare("INSERT INTO duty_overrides (target_date, original_student_code, new_student_code) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE new_student_code = ?");
            if ($stmt) {
                $stmt->bind_param("ssss", $t_date, $o_student, $n_student, $n_student);
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: admin_duties.php?view=roster&month=" . date('Y-m', strtotime($t_date)) . "&msg=duty_swapped");
                    exit();
                }
            } else {
                throw new Exception("SQL Error (swap_duty): " . $conn->error);
            }
        }
    }

    // 4. ดึงข้อมูลการตั้งค่า
    $settings_query = $conn->query("SELECT * FROM duty_settings LIMIT 1");
    $settings = $settings_query ? $settings_query->fetch_assoc() : null;
    $start_date = $settings['start_date'] ?? '2026-07-06';
    $per_day = $settings['students_per_day'] ?? 16;

    // 5. ดึงข้อมูลนักเรียน (ป้องกัน Collation Error ด้วย COLLATE)
    $active_students = [];
    $active_rows = [];
    $student_names = [];
    $res = $conn->query("
        SELECT student_code, prefix, first_name, last_name, status 
        FROM students 
        WHERE student_code COLLATE utf8mb4_unicode_ci NOT IN (
            SELECT student_code COLLATE utf8mb4_unicode_ci FROM duty_exemptions WHERE is_active = 1
        ) 
        ORDER BY id ASC
    ");

    if (!$res) throw new Exception("ดึงข้อมูลนักเรียนล้มเหลว: " . $conn->error);

    while ($row = $res->fetch_assoc()) {
        $active_students[] = $row['student_code'];
        $active_rows[] = ['student_code' => $row['student_code'], 'status' => $row['status']];
        $student_names[$row['student_code']] = $row['prefix'] . $row['first_name'] . ' ' . $row['last_name'];
    }

    // 6. โหลดข้อมูลสลับเวร (Overrides)
    $overrides = [];
    $target_month_esc = $conn->real_escape_string($target_month);
    $res_override = $conn->query("SELECT target_date, original_student_code, new_student_code FROM duty_overrides WHERE target_date LIKE '{$target_month_esc}%'");
    
    if (!$res_override) throw new Exception("ดึงข้อมูลการสลับเวรล้มเหลว: " . $conn->error);

    while ($row = $res_override->fetch_assoc()) {
        $overrides[$row['target_date']][$row['original_student_code']] = $row['new_student_code'];
    }

    // 7. คำนวณตารางเวรประจำเดือน
    $monthly_roster = [];
    $days_in_month = date('t', strtotime($target_month . '-01'));
    $total_active = count($active_students);

    // ตารางเวรจากตัวคำนวณกลาง (ข้ามคนที่ status = 'resigned' ตั้งแต่วันที่กำหนด)
    $month_end = date('Y-m-t', strtotime($target_month . '-01'));
    $full_schedule = ($total_active > 0 && strtotime($month_end) >= strtotime($start_date))
        ? duty_build_schedule($active_rows, $start_date, $month_end, DUTY_SKIP_RESIGNED_FROM, (int)$per_day)
        : [];

    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date = date('Y-m-d', strtotime("$target_month-$day"));
        if (!isset($full_schedule[$current_date])) continue;

        $daily_duty = [];
        foreach ($full_schedule[$current_date] as $original_student) {
            $actual_student = $original_student;
            $is_swapped = false;

            if (isset($overrides[$current_date][$original_student])) {
                $actual_student = $overrides[$current_date][$original_student];
                $is_swapped = true;
            }

            $daily_duty[] = [
                'original_code' => $original_student,
                'actual_code' => $actual_student,
                'actual_name' => $student_names[$actual_student] ?? $actual_student,
                'is_swapped' => $is_swapped
            ];
        }
        $monthly_roster[$current_date] = $daily_duty;
    }

} catch (Throwable $e) {
    die('
    <div style="background:#fee2e2; border:1px solid #fecaca; padding:20px; margin:20px; border-radius:12px; font-family:\'Prompt\', sans-serif;">
        <h3 style="color:#991b1b; margin-top:0;"><i class="fas fa-exclamation-circle"></i> ตรวจพบข้อผิดพลาดระบบ</h3>
        <p style="color:#7f1d1d; font-size:0.9rem;"><strong>ข้อความ:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
        <p style="color:#7f1d1d; font-size:0.85rem;"><strong>ตำแหน่ง:</strong> ' . htmlspecialchars($e->getFile()) . ' บรรทัดที่ ' . $e->getLine() . '</p>
    </div>
    ');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>จัดการเวรรักษาการณ์ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #dc2626; 
      --primary-hover: #b91c1c;
      --bg-color: #f8fafc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; }

    /* Animations */
    @keyframes slideUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    @keyframes popIn {
      0% { opacity: 0; transform: scale(0.9); }
      100% { opacity: 1; transform: scale(1); }
    }

    .animate-slide-up { animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }
    .delay-1 { animation-delay: 0.1s; }
    .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; }

    .app-container { 
      width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 100px; box-shadow: 0 0 20px rgba(0,0,0,0.05); position: relative; overflow-x: hidden;
    }

    .top-header { display: flex; align-items: center; padding: 20px 24px 10px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-right: 15px; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .back-btn:hover { transform: translateX(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* Tabs */
    .tabs-container { display: flex; margin: 10px 24px 0; background: #e2e8f0; border-radius: 12px; padding: 4px; }
    .tab-btn { flex: 1; text-align: center; padding: 10px; font-size: 0.9rem; font-family: 'Prompt'; font-weight: 600; color: var(--text-gray); border-radius: 10px; text-decoration: none; transition: 0.3s ease; }
    .tab-btn:hover:not(.active) { background: #cbd5e1; color: var(--text-dark); }
    .tab-btn.active { background: white; color: var(--text-dark); box-shadow: 0 2px 5px rgba(0,0,0,0.05); transform: scale(1.02); }

    .form-section { padding: 20px 24px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }

    .form-control { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.95rem; background: white; outline: none; transition: border-color 0.3s ease, box-shadow 0.3s ease; }
    .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15); }

    .submit-btn { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 14px; font-family: 'Prompt', sans-serif; font-size: 1rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); transition: 0.2s ease; text-align: center; text-decoration: none; display: block; }
    .submit-btn:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(220, 38, 38, 0.4); }
    .submit-btn:active { transform: scale(0.97); }
    .btn-secondary { background: #64748b; box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3); margin-top: 10px;}
    .btn-secondary:hover { background: #475569; box-shadow: 0 6px 15px rgba(100, 116, 139, 0.4); }

    .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; gap: 10px; animation: slideUp 0.4s ease-out forwards; transition: opacity 0.5s ease; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Roster Cards */
    .day-card { background: white; border-radius: 14px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02); opacity: 0; animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .day-card:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.05); }
    .day-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; font-weight: 600; font-size: 0.95rem; color: var(--text-dark); }
    
    .student-item { display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 8px 12px; border-radius: 10px; margin-bottom: 6px; border: 1px solid #e2e8f0; font-size: 0.85rem; transition: background 0.2s; }
    .student-item:hover { background: #f1f5f9; }
    .student-name { font-weight: 500; color: #0f172a; }
    .student-name.swapped { color: #ea580c; font-weight: 700; }
    .btn-swap { padding: 4px 10px; border: none; border-radius: 6px; background: #f59e0b; color: white; font-family: 'Prompt'; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: transform 0.2s, background 0.2s; }
    .btn-swap:hover { background: #d97706; transform: scale(1.05); }
    .btn-swap:active { transform: scale(0.95); }

    /* Modal */
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; animation: fadeIn 0.3s ease; backdrop-filter: blur(2px); }
    .modal-content { background: white; width: 90%; max-width: 380px; padding: 20px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: popIn 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275); }

    /* Bottom Nav */
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; transition: color 0.2s ease, transform 0.2s ease; }
    .nav-link:hover { color: var(--text-gray); transform: translateY(-2px); }
    .nav-link.active { color: var(--primary); }
    .nav-link.active i { animation: popIn 0.4s ease; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header animate-slide-up delay-1">
      <a href="admin_main.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>จัดการเวรรักษาการณ์</h1>
      </div>
    </div>

    <!-- แถบเมนูย่อย -->
    <div class="tabs-container animate-slide-up delay-2">
        <a href="?view=roster&month=<?= htmlspecialchars($target_month) ?>" class="tab-btn <?= $view === 'roster' ? 'active' : '' ?>"><i class="fas fa-calendar-alt"></i> ตารางเวร</a>
        <a href="?view=settings&month=<?= htmlspecialchars($target_month) ?>" class="tab-btn <?= $view === 'settings' ? 'active' : '' ?>"><i class="fas fa-cog"></i> ตั้งค่าระบบ</a>
    </div>

    <div class="form-section animate-slide-up delay-3">
      
      <?php if (!empty($message)): ?>
        <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>" id="systemAlert">
          <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
          <?= htmlspecialchars($message) ?>
        </div>
      <?php endif; ?>

      <!-- ================= หน้าจอตารางเวร (Roster View) ================= -->
      <?php if ($view === 'roster'): ?>
        
        <div style="display:flex; gap: 10px; margin-bottom: 15px;">
            <form method="GET" style="flex:1;">
                <input type="hidden" name="view" value="roster">
                <input type="month" name="month" value="<?= htmlspecialchars($target_month) ?>" class="form-control" onchange="this.form.submit()">
            </form>
            <a href="api/export_pdf.php?month=<?= htmlspecialchars($target_month) ?>" target="_blank" style="text-decoration:none;">
                <button class="form-control" style="background:#ef4444; color:white; border:none; cursor:pointer; font-weight:600; transition: 0.2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
            </a>
        </div>

        <div style="margin-bottom: 10px; font-size: 0.85rem; color: var(--text-gray);">
            ประจำเดือน: <strong><?= date('F Y', strtotime($target_month . '-01')) ?></strong> (<?= $per_day ?> นาย/วัน)
        </div>

        <?php if (empty($monthly_roster)): ?>
            <div style="text-align:center; padding: 40px 10px; color: #94a3b8; background:white; border-radius:14px; border:1px solid #e2e8f0; animation: popIn 0.5s ease;">
                <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 10px; display:block;"></i>
                ไม่พบข้อมูลรอบเวรในเดือนนี้<br><small>(กรุณาตรวจสอบการตั้งค่า Start Date)</small>
            </div>
        <?php else: ?>
            <?php 
                $delay = 0.1; // เริ่มต้น delay สำหรับ Card
                foreach ($monthly_roster as $date => $students): 
            ?>
                <div class="day-card" style="animation-delay: <?= $delay ?>s;">
                    <div class="day-header">
                        <span><i class="far fa-calendar-check" style="color:var(--primary);"></i> <?= date('d/m/Y', strtotime($date)) ?></span>
                        <span style="font-size: 0.75rem; background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 12px;"><?= count($students) ?> นาย</span>
                    </div>

                    <?php foreach ($students as $stu): ?>
                        <div class="student-item">
                            <span class="student-name <?= $stu['is_swapped'] ? 'swapped' : '' ?>" title="รหัส: <?= htmlspecialchars($stu['actual_code']) ?>">
                                <?= htmlspecialchars($stu['actual_name']) ?>
                                <?= $stu['is_swapped'] ? '<i class="fas fa-exchange-alt" style="font-size:0.75rem; margin-left:4px;"></i>' : '' ?>
                            </span>
                            <button type="button" class="btn-swap" onclick="openSwapModal('<?= $date ?>', '<?= htmlspecialchars($stu['original_code']) ?>', '<?= htmlspecialchars($stu['actual_name']) ?>')">
                                แลกเวร
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php 
                $delay += 0.05; // เพิ่ม delay ทีละนิดให้ค่อยๆ โผล่ไล่ลงมา
                endforeach; 
            ?>
        <?php endif; ?>

      <!-- ================= หน้าจอตั้งค่าระบบ (Settings View) ================= -->
      <?php elseif ($view === 'settings'): ?>

        <form method="POST" action="?view=settings" style="animation: popIn 0.4s ease;">
            <input type="hidden" name="action" value="update_settings">
            
            <div class="form-group">
                <label><i class="far fa-calendar-alt"></i> วันที่เริ่มรันเวรรอบแรก (Start Date)</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control" required>
            </div>

            <div class="form-group">
                <label><i class="fas fa-users"></i> จำนวนคนเข้าเวรต่อวัน/ผลัด</label>
                <input type="number" name="students_per_day" value="<?= htmlspecialchars($per_day) ?>" class="form-control" min="1" required>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-save"></i> บันทึกการตั้งค่า
            </button>
        </form>

      <?php endif; ?>

    </div>

    <!-- Modal: สลับเวร -->
    <div id="swapModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-top:0; font-size:1.05rem; color:var(--text-dark);"><i class="fas fa-exchange-alt" style="color:var(--primary);"></i> สลับเวร (Swap Duty)</h3>
            <form method="POST" action="?view=roster">
                <input type="hidden" name="action" value="swap_duty">
                <input type="hidden" name="target_date" id="swap_target_date">
                <input type="hidden" name="original_student" id="swap_original_student">
                
                <div class="form-group">
                    <label style="font-size:0.8rem;">วันที่ปฏิบัติหน้าที่:</label>
                    <input type="text" id="display_swap_date" class="form-control" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label style="font-size:0.8rem;">ผู้เข้าเวรเดิม:</label>
                    <input type="text" id="display_original_name" class="form-control" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label style="font-size:0.8rem;">รหัสประจำตัวผู้มาเข้าแทน (นชท.):</label>
                    <input type="text" name="new_student" class="form-control" required placeholder="กรอกรหัสนักเรียน" style="border-color:var(--primary);">
                </div>

                <button type="submit" class="submit-btn" style="background:#f59e0b; box-shadow:0 4px 12px rgba(245, 158, 11, 0.3);">ยืนยันการสลับเวร</button>
                <button type="button" class="submit-btn btn-secondary" onclick="closeSwapModal()">ยกเลิก</button>
            </form>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="admin_main.php" class="nav-link">
        <i class="fas fa-home" style="font-size:1.1rem;"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="admin_duties.php" class="nav-link active">
        <i class="fas fa-shield-alt" style="font-size:1.1rem;"></i>
        <span>เวรรักษาการณ์</span>
      </a>
    </div>

  </div>

  <script>
    function openSwapModal(date, originalCode, originalName) {
        document.getElementById('swap_target_date').value = date;
        document.getElementById('swap_original_student').value = originalCode;
        document.getElementById('display_swap_date').value = date;
        document.getElementById('display_original_name').value = originalName;
        document.getElementById('swapModal').style.display = 'flex';
    }

    function closeSwapModal() {
        const modalContent = document.querySelector('.modal-content');
        modalContent.style.animation = 'slideUp 0.2s ease-in reverse forwards'; // เล่น Animation ย้อนกลับก่อนปิด
        setTimeout(() => {
            document.getElementById('swapModal').style.display = 'none';
            modalContent.style.animation = 'popIn 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275)'; // คืนค่า Animation เดิม
        }, 200);
    }

    // Auto-hide System Alerts after 3.5 seconds
    document.addEventListener('DOMContentLoaded', () => {
        const alertBox = document.getElementById('systemAlert');
        if (alertBox) {
            setTimeout(() => {
                alertBox.style.opacity = '0';
                setTimeout(() => alertBox.remove(), 500);
            }, 3500);
        }
    });
  </script>
</body>
</html>
