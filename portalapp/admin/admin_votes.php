<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์ Admin
if (empty($_SESSION['admin_id'])) {
    header("Location: admin_main.php");
    exit();
}

$admin_name = $_SESSION['admin_id'] ?? '';

// ==========================================
// 0. จัดการ การแก้ไขสถานะ (Admin Override)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_status') {
    $target_stu = $_POST['student_code'];
    $target_d = $_POST['target_date'];
    $new_status = $_POST['override_status'];
    $reason = $_POST['override_reason'] ?? '';

    if ($new_status === 'clear') {
        // 1. ยกเลิกการแก้ไข (กลับไปใช้สถานะเดิมของระบบ)
        $stmt_del = $conn->prepare("DELETE FROM admin_overrides WHERE student_code = ? AND target_date = ?");
        $stmt_del->bind_param("ss", $target_stu, $target_d);
        $stmt_del->execute();
        $stmt_del->close();

        // [เพิ่มใหม่] ลบรายชื่อออกจาก restrictions ด้วยเมื่อกดยกเลิก
        $stmt_del_rest = $conn->prepare("DELETE FROM restrictions WHERE student_code = ?");
        $stmt_del_rest->bind_param("s", $target_stu);
        $stmt_del_rest->execute();
        $stmt_del_rest->close();
    } else {
        // เช็คว่ามีข้อมูลเดิมไหม ถ้ามีให้อัปเดต ถ้าไม่มีให้เพิ่มใหม่ ในตาราง admin_overrides
        $stmt_chk = $conn->prepare("SELECT id FROM admin_overrides WHERE student_code = ? AND target_date = ?");
        $stmt_chk->bind_param("ss", $target_stu, $target_d);
        $stmt_chk->execute();
        $res_chk = $stmt_chk->get_result();
        
        if ($res_chk->num_rows > 0) {
            $stmt_upd = $conn->prepare("UPDATE admin_overrides SET override_status = ?, override_reason = ?, admin_username = ? WHERE student_code = ? AND target_date = ?");
            $stmt_upd->bind_param("sssss", $new_status, $reason, $admin_name, $target_stu, $target_d);
            $stmt_upd->execute();
            $stmt_upd->close();
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO admin_overrides (student_code, target_date, override_status, override_reason, admin_username) VALUES (?, ?, ?, ?, ?)");
            $stmt_ins->bind_param("sssss", $target_stu, $target_d, $new_status, $reason, $admin_name);
            $stmt_ins->execute();
            $stmt_ins->close();
        }
        $stmt_chk->close();

        // ==========================================
        // [เพิ่มใหม่] จัดการข้อมูลลงตาราง restrictions
        // ==========================================
        if ($new_status === 'restrict') {
            // เช็คว่ามีการบันทึกกักบริเวณในวันนี้ไปแล้วหรือยัง (ป้องกันการ Insert ซ้ำเวลาแอดมินกดบันทึกหลายรอบ)
            $stmt_chk_rest = $conn->prepare("SELECT id FROM restrictions WHERE student_code = ? AND restrict_type = 'restrict' AND DATE(created_at) = CURRENT_DATE");
            $stmt_chk_rest->bind_param("s", $target_stu);
            $stmt_chk_rest->execute();
            
            if ($stmt_chk_rest->get_result()->num_rows == 0) {
                // ถ้ายังไม่มีให้ Insert ใหม่ (weeks_count เป็น 1 สัปดาห์อัตโนมัติ ตาม Default ของตาราง)
                $stmt_ins_rest = $conn->prepare("INSERT INTO restrictions (student_code, weeks_count, restrict_type) VALUES (?, 1, 'restrict')");
                $stmt_ins_rest->bind_param("s", $target_stu);
                $stmt_ins_rest->execute();
                $stmt_ins_rest->close();
            }
            $stmt_chk_rest->close();
        } else {
            // หากแอดมินเปลี่ยนใจ แก้จาก "กักบริเวณ" เป็น "กลับบ้าน/อยู่ รร./เข้าเวร" ให้ลบข้อมูลกักบริเวณออก
            $stmt_del_rest = $conn->prepare("DELETE FROM restrictions WHERE student_code = ? AND restrict_type = 'restrict'");
            $stmt_del_rest->bind_param("s", $target_stu);
            $stmt_del_rest->execute();
            $stmt_del_rest->close();
        }
    }
    
    // Refresh หน้าจอ
    $search_param = isset($_POST['search']) ? "&search=" . urlencode($_POST['search']) : "";
    header("Location: ?target_date=" . $target_d . $search_param);
    exit();
}

// ==========================================
// 1. ระบบคำนวณวันที่ และการค้นหา
// ==========================================
$default_target_date = (date('w') == 5) ? date('Y-m-d') : date('Y-m-d', strtotime('next friday'));
$target_date = isset($_GET['target_date']) ? $_GET['target_date'] : $default_target_date;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = "2026-07-06"; // วันเริ่มต้นคำนวณเวรหลัก

// ดึงรายการวันที่ที่มีการโหวตทั้งหมดสำหรับทำ Dropdown
$date_list = [];
try {
    $conn->set_charset("utf8mb4");
    $res_dates = $conn->query("SELECT DISTINCT vote_date FROM weekly_votes ORDER BY vote_date DESC");
    if ($res_dates) {
        while ($row = $res_dates->fetch_assoc()) {
            $date_list[] = $row['vote_date'];
        }
    }
    if (!in_array($target_date, $date_list)) {
        array_unshift($date_list, $target_date);
    }
} catch (Exception $e) {
    error_log("Date fetch error: " . $e->getMessage());
}

// ==========================================
// 2. ดึงข้อมูลนักเรียน โหวต และการแทรกแซง
// ==========================================
$students_data = [];
$restricted_ids = [];

try {
    // 2.1 ดึงรหัสนักเรียนที่ถูกกักบริเวณ
    $stmt_rest = $conn->prepare("
        SELECT DISTINCT student_code 
        FROM restrictions 
        WHERE (restrict_type = 'restrict' OR restrict_type IS NULL OR restrict_type = '')
          AND ? >= DATE(created_at) 
          AND ? <= DATE_ADD(DATE(created_at), INTERVAL weeks_count WEEK)
    ");
    $stmt_rest->bind_param("ss", $target_date, $target_date);
    $stmt_rest->execute();
    $res_rest = $stmt_rest->get_result();
    while ($r_row = $res_rest->fetch_assoc()) {
        $restricted_ids[] = $r_row['student_code'];
    }
    $stmt_rest->close();

    // 2.2 ดึงข้อมูลนักเรียนทั้งหมด (รวมระบบค้นหา)
    $sql_students = "
        SELECT s.id, s.student_code, s.prefix, s.first_name, s.last_name, s.platoon, s.company, s.section, 
               v.status AS normal_vote, v.province, v.travel_method, v.return_day, v.created_at,
               a.override_status, a.override_reason, a.admin_username
        FROM students s
        LEFT JOIN weekly_votes v ON s.student_code = v.student_code AND v.vote_date = ?
        LEFT JOIN admin_overrides a ON s.student_code = a.student_code AND a.target_date = ?
    ";
    
    if ($search_query !== '') {
        $sql_students .= " WHERE s.student_code LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? ORDER BY s.id ASC";
        $stmt = $conn->prepare($sql_students);
        $search_like = "%{$search_query}%";
        $stmt->bind_param("sssss", $target_date, $target_date, $search_like, $search_like, $search_like);
    } else {
        $sql_students .= " ORDER BY s.id ASC";
        $stmt = $conn->prepare($sql_students);
        $stmt->bind_param("ss", $target_date, $target_date);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) {
        $students_data[] = $row;
    }
    $stmt->close();

    // คำนวณ duty_codes เพื่อใช้เปรียบเทียบ (โดยไม่เกี่ยวกับการค้นหา จะต้องดึงรายชื่อทั้งหมดมานับใหม่ถ้าต้องการให้แม่นยำ)
    // หมายเหตุ: หากมีการค้นหา การคำนวณเวรแบบสุ่มด้วยดัชนีอาจจะคลาดเคลื่อนได้ แนะนำให้ดึง duty_codes แยกต่างหากถ้าจำเป็น
    // เพื่อความรวดเร็ว จะคำนวณจาก $students_data เฉพาะกรณีไม่ได้ค้นหา
    $duty_codes = [];
    if ($search_query === '') {
        $total_all = count($students_data);
        if ($total_all > 0) {
            $diff_days = floor((strtotime($target_date) - strtotime($start_date)) / 86400);
            if ($diff_days < 0) $diff_days = 0;
            $start_index = ($diff_days * 16) % $total_all;
            for ($i = 0; $i < 16; $i++) {
                $idx = ($start_index + $i) % $total_all;
                if (isset($students_data[$idx])) {
                    $duty_codes[] = $students_data[$idx]['student_code'];
                }
            }
        }
    }

} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
}

// ==========================================
// 3. สรุปสถานะ
// ==========================================
$total_displayed = count($students_data);
$summary = ['total' => $total_displayed, 'home' => 0, 'stay' => 0, 'duty' => 0, 'restrict' => 0, 'unvoted' => 0];
$processed_students = [];

foreach ($students_data as $stu) {
    $code = $stu['student_code'];
    $final_status = 'unvoted';
    $is_overridden = !empty($stu['override_status']);

    if (in_array($code, $duty_codes)) {
        $final_status = 'duty';
    } elseif ($is_overridden) {
        $final_status = $stu['override_status'];
    } elseif (in_array($code, $restricted_ids)) {
        $final_status = 'restrict';
    } elseif (!empty($stu['normal_vote'])) {
        $final_status = $stu['normal_vote'];
    }

    $stu['final_status'] = $final_status;
    $processed_students[] = $stu;

    if (isset($summary[$final_status])) {
        $summary[$final_status]++;
    }
}

// ==========================================
// 4. ฟังก์ชัน Export CSV
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // โค้ดส่วน Export CSV เดิม (เก็บไว้เหมือนเดิม)
    $filename = "report_votes_" . date('Ymd', strtotime($target_date)) . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    
    fputcsv($output, ['สรุปยอด นชท. ประจำวันที่', date('d/m/Y', strtotime($target_date))]);
    fputcsv($output, ['1. กลับภูมิลำเนา', $summary['home'], 'นาย']);
    fputcsv($output, ['2. ประสงค์อยู่ รร.ชท.', $summary['stay'], 'นาย']);
    fputcsv($output, ['3. เวรรักษาการณ์', $summary['duty'], 'นาย']);
    fputcsv($output, ['4. กักบริเวณ', $summary['restrict'], 'นาย']);
    fputcsv($output, ['5. ยังไม่ทำรายการ', $summary['unvoted'], 'นาย']);
    fputcsv($output, ['รวมทั้งสิ้น', $summary['total'], 'นาย']);
    fputcsv($output, []); 
    
    fputcsv($output, ['ลำดับ', 'รหัสประจำตัว', 'ชื่อ - นามสกุล', 'หมวด', 'กองร้อย', 'ตอนเรียน', 'สถานะ', 'จังหวัด', 'การเดินทาง', 'หมายเหตุ']);
    
    $run_no = 1;
    foreach ($processed_students as $s) {
        $full_name = trim(($s['prefix'] ?? '') . ($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        $status_th = 'ยังไม่ทำรายการ';
        if ($s['final_status'] == 'home') $status_th = 'กลับภูมิลำเนา';
        if ($s['final_status'] == 'stay') $status_th = 'อยู่ รร.ชท.';
        if ($s['final_status'] == 'duty') $status_th = 'เวรรักษาการณ์';
        if ($s['final_status'] == 'restrict') $status_th = 'กักบริเวณ';

        $note = '';
        if (!empty($s['override_status'])) {
            $note = "Admin แก้ไข (" . $s['admin_username'] . "): " . ($s['override_reason'] ?? '');
        }

        fputcsv($output, [
            $run_no++, $s['student_code'], $full_name, $s['platoon'] ?? '-', $s['company'] ?? '-',
            $s['section'] ?? '-', $status_th, $s['province'] ?? '-', $s['travel_method'] ?? '-', $note
        ]);
    }
    fclose($output);
    exit();
}
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
    /* ... (CSS เดิมของคุณ) ... */
    :root { --primary: #0f172a; --bg-color: #f8fafc; --text-dark: #1e293b; --text-gray: #64748b; }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; user-select: none; }
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 100px; box-shadow: 0 0 20px rgba(0,0,0,0.05); position: relative; }
    
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 10px; }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    .export-btn { background-color: #10b981; color: white; padding: 8px 12px; border-radius: 10px; font-size: 0.85rem; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3); transition: transform 0.1s; }
    .export-btn:active { transform: scale(0.95); }

    .filter-section { margin: 10px 24px 0; }
    .select-wrapper { position: relative; display: flex; align-items: center; margin-bottom: 10px; }
    .select-icon { position: absolute; left: 14px; color: var(--primary); font-size: 1rem; pointer-events: none; }
    .week-select, .search-input { width: 100%; padding: 12px 14px 12px 40px; border-radius: 14px; border: 1px solid #cbd5e1; background-color: white; font-family: 'Prompt', sans-serif; font-size: 0.88rem; font-weight: 500; outline: none; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
    
    .search-wrapper { position: relative; display: flex; align-items: center; }
    .search-icon { position: absolute; left: 14px; color: #94a3b8; font-size: 1rem; }
    .search-input { padding-left: 40px; }

    .summary-card { background: linear-gradient(135deg, var(--primary), #1e293b); border-radius: 18px; padding: 20px; margin: 15px 24px 20px; color: white; }
    .summary-card h2 { font-size: 1.1rem; font-weight: 600; margin: 0 0 15px 0; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;}
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .stat-item { background: rgba(255,255,255,0.1); padding: 10px 5px; border-radius: 12px; text-align: center; border: 1px solid rgba(255,255,255,0.15); }
    .stat-item h3 { margin: 0; font-size: 1.2rem; font-weight: 700; }
    .stat-item p { margin: 0; font-size: 0.7rem; opacity: 0.8; white-space: nowrap; }

    .section-title { display: flex; align-items: center; gap: 10px; margin: 0 24px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    .vote-list { margin: 0 24px 20px; display: flex; flex-direction: column; gap: 12px; }
    .vote-card { background: white; border-radius: 14px; padding: 15px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; position: relative; overflow: hidden; }
    .vote-header { display: flex; justify-content: space-between; align-items: flex-start; }
    .student-info h4 { margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 600; color: var(--text-dark); }
    .student-info p { margin: 0; font-size: 0.75rem; color: var(--text-gray); }
    
    .status-badge { padding: 4px 10px; border-radius: 8px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; display: inline-block; }
    .status-home { background: #dcfce7; color: #166534; }
    .status-stay { background: #fef3c7; color: #92400e; }
    .status-duty { background: #fee2e2; color: #991b1b; } 
    .status-restrict { background: #ffedd5; color: #c2410c; }
    .status-unvoted { background: #f1f5f9; color: #475569; }
    
    .vote-details { font-size: 0.8rem; color: #475569; background: #f8fafc; padding: 10px; border-radius: 8px; display: flex; flex-direction: column; gap: 4px; }
    .override-note { font-size: 0.75rem; color: #ea580c; background: #fff7ed; padding: 6px 10px; border-radius: 6px; border-left: 3px solid #ea580c; margin-top: 5px; }

    /* CSS สำหรับปุ่มแก้ไข */
    .edit-btn { border: none; background: #f1f5f9; color: #64748b; padding: 5px 8px; border-radius: 6px; font-size: 0.7rem; font-family: 'Prompt'; cursor: pointer; transition: 0.2s; margin-top: 5px; width: 100%;}
    .edit-btn:hover { background: #e2e8f0; color: #0f172a; }

    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }

    /* Modal CSS */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
    .modal-content { background: white; width: 90%; max-width: 380px; border-radius: 16px; padding: 20px; font-family: 'Prompt', sans-serif; position: relative; animation: fadeInUp 0.3s ease; }
    .modal-close { position: absolute; top: 15px; right: 20px; font-size: 1.2rem; color: #94a3b8; cursor: pointer; }
    .modal-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 15px; color: var(--primary); }
    .form-group { margin-bottom: 12px; }
    .form-group label { display: block; font-size: 0.85rem; margin-bottom: 5px; color: var(--text-dark); font-weight: 500; }
    .form-control { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Prompt'; font-size: 0.9rem; outline: none; }
    .btn-submit { width: 100%; padding: 12px; background: var(--primary); color: white; border: none; border-radius: 8px; font-family: 'Prompt'; font-weight: 600; font-size: 1rem; cursor: pointer; margin-top: 10px;}
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header fade-in-up delay-1">
      <div class="header-left">
        <a href="turakarn.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
        <div class="page-title">
          <h1>ผลการทำรายการ</h1>
        </div>
      </div>
      <a href="?export=csv&target_date=<?= $target_date ?>&search=<?= urlencode($search_query) ?>" class="export-btn"><i class="fas fa-file-excel"></i> CSV</a>
    </div>

    <!-- Filter เลือกวันที่ และ ช่องค้นหา -->
    <div class="filter-section fade-in-up delay-2">
      <form method="GET" action="" id="filterForm">
        <div class="select-wrapper">
          <i class="fas fa-calendar-alt select-icon"></i>
          <select name="target_date" onchange="document.getElementById('filterForm').submit()" class="week-select" style="background-image: none;">
            <?php foreach ($date_list as $d): ?>
              <?php $is_selected = ($d === $target_date) ? 'selected' : ''; ?>
              <option value="<?= $d ?>" <?= $is_selected ?>>
                ประจำวันศุกร์ที่ <?= date('d/m/Y', strtotime($d)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="search-wrapper">
          <i class="fas fa-search search-icon"></i>
          <input type="text" name="search" class="search-input" placeholder="ค้นหา รหัส, ชื่อ หรือ นามสกุล..." value="<?= htmlspecialchars($search_query) ?>">
        </div>
        <button type="submit" style="display:none;">ค้นหา</button>
      </form>
    </div>

    <!-- Summary Card -->
    <div class="summary-card fade-in-up delay-2">
      <h2>ข้อมูลที่แสดง <?= $summary['total'] ?> นาย</h2>
      <div class="stats-grid">
        <div class="stat-item"><h3 style="color: #4ade80;"><?= $summary['home'] ?></h3><p>กลับบ้าน</p></div>
        <div class="stat-item"><h3 style="color: #facc15;"><?= $summary['stay'] ?></h3><p>อยู่ รร.</p></div>
        <div class="stat-item"><h3 style="color: #f87171;"><?= $summary['duty'] ?></h3><p>เข้าเวร</p></div>
        <div class="stat-item"><h3 style="color: #fb923c;"><?= $summary['restrict'] ?></h3><p>กักบริเวณ</p></div>
        <div class="stat-item" style="grid-column: span 2;"><h3 style="color: #cbd5e1;"><?= $summary['unvoted'] ?></h3><p>ยังไม่ทำรายการ</p></div>
      </div>
    </div>

    <div class="section-title fade-in-up delay-3">
      <div class="section-pill"></div>
      <h3>รายชื่อนักเรียน</h3>
    </div>

    <div class="vote-list fade-in-up delay-3">
      <?php if (empty($processed_students)): ?>
        <div style="text-align:center; color:#64748b; font-size:0.9rem; padding: 20px;">
          ไม่พบข้อมูลที่ค้นหา
        </div>
      <?php else: ?>
        <?php foreach ($processed_students as $s): ?>
          <?php 
            $full_name = (!empty($s['first_name'])) ? trim(($s['prefix'] ?? '').$s['first_name'].' '.$s['last_name']) : 'ไม่พบชื่อ';
            $unit = (!empty($s['company']) ? 'ร้อย.'.$s['company'] : '') . (!empty($s['platoon']) ? ' หมวด '.$s['platoon'] : '');
            
            $status_th = 'ยังไม่ทำรายการ';
            $badge_class = 'status-unvoted';
            
            if ($s['final_status'] === 'home') { $status_th = 'กลับบ้าน'; $badge_class = 'status-home'; }
            if ($s['final_status'] === 'stay') { $status_th = 'อยู่ รร.'; $badge_class = 'status-stay'; }
            if ($s['final_status'] === 'duty') { $status_th = 'เข้าเวร'; $badge_class = 'status-duty'; }
            if ($s['final_status'] === 'restrict') { $status_th = 'กักบริเวณ'; $badge_class = 'status-restrict'; }
          ?>
          <div class="vote-card">
            <div class="vote-header">
              <div class="student-info">
                <h4><?= htmlspecialchars($full_name) ?></h4>
                <p><?= htmlspecialchars($unit) ?> | รหัส: <?= htmlspecialchars($s['student_code']) ?></p>
              </div>
              <div style="text-align: right;">
                <div class="status-badge <?= $badge_class ?>"><?= htmlspecialchars($status_th) ?></div>
                <!-- ปุ่มแก้ไขสถานะ -->
                <button type="button" class="edit-btn" onclick="openEditModal('<?= $s['student_code'] ?>', '<?= htmlspecialchars($full_name) ?>', '<?= $s['override_status'] ?? '' ?>', '<?= htmlspecialchars($s['override_reason'] ?? '') ?>')">
                  <i class="fas fa-cog"></i> แก้ไข
                </button>
              </div>
            </div>
            
            <?php if (!empty($s['override_status'])): ?>
              <div class="override-note">
                <i class="fas fa-exclamation-circle"></i> แก้ไขโดย <?= htmlspecialchars($s['admin_username']) ?>: <?= htmlspecialchars($s['override_reason'] ?? 'ไม่ระบุเหตุผล') ?>
              </div>
            <?php endif; ?>

            <?php if ($s['final_status'] === 'home' && !empty($s['normal_vote'])): ?>
            <div class="vote-details">
              <div><i class="fas fa-map-marker-alt" style="color:#ef4444; width:16px;"></i> <b>จังหวัด:</b> <?= htmlspecialchars($s['province'] ?? '-') ?></div>
              <div><i class="fas fa-bus" style="color:#3b82f6; width:16px;"></i> <b>การเดินทาง:</b> <?= htmlspecialchars($s['travel_method'] ?? '-') ?></div>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="admin_main.php" class="nav-link">
        <i class="fas fa-home"></i> <span>หน้าหลัก</span>
      </a>
      <a href="admin_votes.php" class="nav-link active">
        <i class="fas fa-list-alt"></i> <span>สถานะ นชท.</span>
      </a>
    </div>
  </div>

  <!-- Modal แก้ไขสถานะ -->
  <div class="modal-overlay" id="editModal">
    <div class="modal-content">
      <i class="fas fa-times modal-close" onclick="closeEditModal()"></i>
      <div class="modal-title">แก้ไขสถานะ นชท.</div>
      
      <form method="POST" action="">
        <input type="hidden" name="action" value="edit_status">
        <input type="hidden" name="student_code" id="modal_student_code">
        <input type="hidden" name="target_date" value="<?= htmlspecialchars($target_date) ?>">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search_query) ?>">
        
        <div class="form-group">
          <label>ชื่อนักเรียน:</label>
          <input type="text" id="modal_student_name" class="form-control" readonly style="background: #f1f5f9; color:#64748b;">
        </div>
        
        <div class="form-group">
          <label>เลือกสถานะบังคับ (Override):</label>
          <select name="override_status" id="modal_override_status" class="form-control" required>
            <option value="clear">-- ยกเลิกการบังคับ (ใช้ค่าปกติ) --</option>
            <option value="home">กลับภูมิลำเนา</option>
            <option value="stay">ประสงค์อยู่ รร.ชท.</option>
            <option value="restrict">กักบริเวณ</option>
            <option value="duty">เวรรักษาการณ์</option>
          </select>
        </div>

        <div class="form-group">
          <label>หมายเหตุ / เหตุผล:</label>
          <input type="text" name="override_reason" id="modal_override_reason" class="form-control" placeholder="ระบุเหตุผล (ถ้ามี)...">
        </div>

        <button type="submit" class="btn-submit">บันทึกข้อมูล</button>
      </form>
    </div>
  </div>

  <script>
    // สคริปต์เปิด-ปิด Modal
    function openEditModal(code, name, status, reason) {
      document.getElementById('modal_student_code').value = code;
      document.getElementById('modal_student_name').value = name + ' (รหัส: ' + code + ')';
      
      let statusSelect = document.getElementById('modal_override_status');
      statusSelect.value = status ? status : 'clear';
      
      document.getElementById('modal_override_reason').value = reason;
      
      document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
      document.getElementById('editModal').style.display = 'none';
    }
  </script>
</body>
</html>
