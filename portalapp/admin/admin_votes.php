<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์ Admin
if (empty($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$admin_name = $_SESSION['admin_name'] ?? 'ผู้ดูแลระบบ';
$votes = [];
$weeks_list = [];
$total_go_home = 0;
$total_stay = 0;
$total_duty = 0;

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection error");
    }
    $conn->set_charset("utf8mb4");

    // 1. ดึงรายการสัปดาห์ทั้งหมดที่มีข้อมูลโหวต
    $sql_weeks = "SELECT DISTINCT YEARWEEK(vote_date, 1) AS yw, 
                         MIN(DATE(vote_date)) AS start_d, 
                         MAX(DATE(vote_date)) AS end_d 
                  FROM weekly_votes 
                  ORDER BY yw DESC";
    $res_weeks = $conn->query($sql_weeks);
    if ($res_weeks) {
        while ($row_w = $res_weeks->fetch_assoc()) {
            $weeks_list[] = $row_w;
        }
    }

    // 2. ตรวจสอบสัปดาห์ที่ถูกเลือก (ถ้าไม่มี ให้ใช้สัปดาห์ปัจจุบัน)
    if (isset($_GET['week']) && !empty($_GET['week'])) {
        $selected_week = intval($_GET['week']);
    } else {
        $res_cur = $conn->query("SELECT YEARWEEK(CURDATE(), 1) AS cur_yw");
        if ($res_cur && $r = $res_cur->fetch_assoc()) {
            $selected_week = intval($r['cur_yw']);
        } else {
            $selected_week = intval(date('YW'));
        }
    }

    // เพิ่มสัปดาห์ที่เลือกลงในรายการ ตัวเลือกหากไม่มีในฐานข้อมูล
    $found_selected = false;
    foreach ($weeks_list as $w) {
        if (intval($w['yw']) === $selected_week) {
            $found_selected = true;
            break;
        }
    }
    if (!$found_selected) {
        array_unshift($weeks_list, [
            'yw' => $selected_week,
            'start_d' => date('Y-m-d'),
            'end_d' => date('Y-m-d')
        ]);
    }

    // 3. ดึงข้อมูลโหวตของสัปดาห์ที่เลือก
    $sql = "
        SELECT v.*, s.prefix, s.first_name, s.last_name, s.company, s.platoon 
        FROM weekly_votes v 
        LEFT JOIN students s ON v.student_code = s.student_code 
        WHERE YEARWEEK(v.vote_date, 1) = ?
        ORDER BY v.created_at DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $selected_week);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $votes[] = $row;
            $status_val = trim($row['status']);
            
            if ($status_val === 'home') {
                $total_go_home++;
            } elseif ($status_val === 'duty') {
                $total_duty++;
            } else {
                $total_stay++;
            }
        }
    }
} catch (Throwable $e) {
    error_log("Admin Votes error: " . $e->getMessage());
}

// ==========================================
// ฟังก์ชัน Export CSV ตามสัปดาห์ที่เลือก
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="weekly_votes_' . $selected_week . '_' . date('Ymd_His') . '.csv"');
    
    echo "\xEF\xBB\xBF"; // UTF-8 BOM สำหรับ Excel
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['รหัสนักเรียน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'กองร้อย', 'หมวด', 'สถานะ', 'จังหวัด', 'การเดินทาง', 'วันกลับ', 'เวลาที่บันทึก']);
    
    foreach ($votes as $v) {
        $status_raw = trim($v['status']);
        $status_thai = 'ไม่ระบุ';
        
        if ($status_raw === 'home') {
            $status_thai = 'กลับบ้าน';
        } elseif ($status_raw === 'duty') {
            $status_thai = 'เข้าเวร';
        } elseif ($status_raw === 'restrict') {
            $status_thai = 'กักบริเวณ/อยู่ รร.';
        }

        fputcsv($output, [
            $v['student_code'],
            $v['prefix'],
            $v['first_name'],
            $v['last_name'],
            $v['company'],
            $v['platoon'],
            $status_thai,
            $v['province'] ?? '-',
            $v['travel_method'] ?? '-',
            $v['return_day'] ?? '-',
            $v['created_at']
        ]);
    }
    
    fclose($output);
    exit();
}
// ==========================================
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ผลโหวตประสงค์กลับบ้าน - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #0f172a; 
      --bg-color: #f8fafc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }

    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }

    /* Header */
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 10px; }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    
    /* ปุ่ม Export CSV */
    .export-btn { background-color: #10b981; color: white; padding: 8px 12px; border-radius: 10px; font-size: 0.85rem; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3); transition: transform 0.1s; }
    .export-btn:active { transform: scale(0.95); }

    /* Filter Section (เลือกสัปดาห์) */
    .filter-section { margin: 10px 24px 0; }
    .select-wrapper { position: relative; display: flex; align-items: center; }
    .select-icon { position: absolute; left: 14px; color: var(--primary); font-size: 1rem; pointer-events: none; }
    .week-select {
      width: 100%;
      padding: 12px 14px 12px 40px;
      border-radius: 14px;
      border: 1px solid #cbd5e1;
      background-color: white;
      font-family: 'Prompt', sans-serif;
      font-size: 0.88rem;
      font-weight: 500;
      color: var(--text-dark);
      outline: none;
      box-shadow: 0 2px 6px rgba(0,0,0,0.03);
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 16px;
      cursor: pointer;
    }

    /* Summary Card */
    .summary-card { background: linear-gradient(135deg, var(--primary), #1e293b); border-radius: 18px; padding: 20px; margin: 15px 24px 20px; color: white; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2); }
    .summary-card h2 { font-size: 1.2rem; font-weight: 600; margin: 0 0 15px 0; }
    .stats-row { display: flex; justify-content: space-between; gap: 8px; }
    .stat-item { background: rgba(255,255,255,0.1); padding: 10px 5px; border-radius: 12px; flex: 1; text-align: center; border: 1px solid rgba(255,255,255,0.15); }
    .stat-item h3 { margin: 0; font-size: 1.3rem; font-weight: 700; }
    .stat-item p { margin: 0; font-size: 0.7rem; opacity: 0.8; white-space: nowrap; }

    /* List Container */
    .section-title { display: flex; align-items: center; gap: 10px; margin: 0 24px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    .vote-list { margin: 0 24px 20px; display: flex; flex-direction: column; gap: 12px; }
    .vote-card { background: white; border-radius: 14px; padding: 15px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
    .vote-header { display: flex; justify-content: space-between; align-items: center; }
    .student-info h4 { margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--text-dark); }
    .student-info p { margin: 0; font-size: 0.75rem; color: var(--text-gray); }
    
    .status-badge { padding: 4px 10px; border-radius: 8px; font-size: 0.7rem; font-weight: 600; }
    .status-home { background: #dcfce7; color: #166534; }
    .status-stay { background: #fef3c7; color: #92400e; }
    .status-duty { background: #fee2e2; color: #991b1b; } 
    
    .vote-details { font-size: 0.8rem; color: #475569; background: #f8fafc; padding: 10px; border-radius: 8px; display: flex; flex-direction: column; gap: 4px; }

    /* Bottom Nav */
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 20px; border-top-right-radius: 20px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <a href="turakarn.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
        <div class="page-title">
          <h1>ผลโหวตประสงค์</h1>
        </div>
      </div>
      <!-- ปุ่ม Export CSV พร้อมส่งตัวแปรสัปดาห์ที่เลือก -->
      <a href="?export=csv&week=<?= $selected_week ?>" class="export-btn"><i class="fas fa-file-excel"></i> CSV</a>
    </div>

    <!-- Filter เลือกสัปดาห์ -->
    <div class="filter-section fade-in-up delay-2">
      <form method="GET" action="" id="weekForm">
        <div class="select-wrapper">
          <i class="fas fa-calendar-alt select-icon"></i>
          <select name="week" onchange="document.getElementById('weekForm').submit()" class="week-select">
            <?php foreach ($weeks_list as $w_item): ?>
              <?php 
                $yw_val = intval($w_item['yw']);
                $year = substr($yw_val, 0, 4);
                $week_num = substr($yw_val, 4);
                $is_selected = ($yw_val === $selected_week) ? 'selected' : '';
                $start_formatted = date('d/m/Y', strtotime($w_item['start_d']));
                $end_formatted = date('d/m/Y', strtotime($w_item['end_d']));
              ?>
              <option value="<?= $yw_val ?>" <?= $is_selected ?>>
                สัปดาห์ที่ <?= $week_num ?>/<?= $year ?> (<?= $start_formatted ?> - <?= $end_formatted ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <!-- Summary Card -->
    <div class="summary-card fade-in-up delay-2">
      <h2>ภาพรวมสัปดาห์ที่เลือก</h2>
      <div class="stats-row">
        <div class="stat-item">
          <h3><?= count($votes) ?></h3>
          <p>ทั้งหมด</p>
        </div>
        <div class="stat-item">
          <h3 style="color: #4ade80;"><?= $total_go_home ?></h3>
          <p>กลับบ้าน</p>
        </div>
        <div class="stat-item">
          <h3 style="color: #facc15;"><?= $total_stay ?></h3>
          <p>กักบริเวณ</p>
        </div>
        <div class="stat-item">
          <h3 style="color: #f87171;"><?= $total_duty ?></h3>
          <p>เข้าเวร</p>
        </div>
      </div>
    </div>

    <div class="section-title fade-in-up delay-3">
      <div class="section-pill"></div>
      <h3>รายการผู้ลงข้อมูล</h3>
    </div>

    <div class="vote-list fade-in-up delay-3">
      <?php if (empty($votes)): ?>
        <div style="text-align:center; color:#64748b; font-size:0.9rem; padding: 20px;">
          ยังไม่มีข้อมูลการโหวตในสัปดาห์นี้
        </div>
      <?php else: ?>
        <?php foreach ($votes as $v): ?>
          <?php 
            $full_name = (!empty($v['first_name'])) ? trim($v['prefix'].$v['first_name'].' '.$v['last_name']) : 'ไม่พบชื่อ (รหัส: '.$v['student_code'].')';
            $unit = (!empty($v['company']) ? 'ร้อย.'.$v['company'] : '') . (!empty($v['platoon']) ? ' หมวด '.$v['platoon'] : '');
            
            $status_raw = trim($v['status']);
            $is_home = false;
            $status_text = 'ไม่ระบุ';
            $badge_class = 'status-stay';

            if ($status_raw === 'home') {
                $is_home = true;
                $status_text = 'กลับบ้าน';
                $badge_class = 'status-home';
            } elseif ($status_raw === 'duty') {
                $status_text = 'เข้าเวร';
                $badge_class = 'status-duty';
            } elseif ($status_raw === 'restrict') {
                $status_text = 'กักบริเวณ/อยู่ รร.';
                $badge_class = 'status-stay';
            }
          ?>
          <div class="vote-card">
            <div class="vote-header">
              <div class="student-info">
                <h4><?= htmlspecialchars($full_name) ?></h4>
                <p><?= htmlspecialchars($unit) ?></p>
              </div>
              <div class="status-badge <?= $badge_class ?>">
                <?= htmlspecialchars($status_text) ?>
              </div>
            </div>
            
            <?php if ($is_home): ?>
            <div class="vote-details">
              <div><i class="fas fa-map-marker-alt" style="color:#ef4444; width:16px;"></i> <b>จังหวัด:</b> <?= htmlspecialchars($v['province'] ?? '-') ?></div>
              <div><i class="fas fa-bus" style="color:#3b82f6; width:16px;"></i> <b>การเดินทาง:</b> <?= htmlspecialchars($v['travel_method'] ?? '-') ?></div>
              <div><i class="fas fa-calendar-check" style="color:#10b981; width:16px;"></i> <b>วันกลับ:</b> <?= htmlspecialchars($v['return_day'] ?? '-') ?></div>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="bottom-nav">
      <a href="turakarn.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="admin_votes.php" class="nav-link active">
        <i class="fas fa-vote-yea"></i>
        <span>ผลโหวต</span>
      </a>
    </div>
  </div>

</body>
</html>
