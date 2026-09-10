<?php
session_start();
require_once 'db.php';

// ตรวจสอบการเข้าสู่ระบบ
if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

$studentCode = $_SESSION['student_code'];

// ------------------------------------------------------------------
// ระบบ Export Database (ดาวน์โหลดไฟล์ .sql)
// ------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'sql') {
    // ปิดการแสดงผล Error ชั่วคราวเพื่อไม่ให้ปนไปในไฟล์ SQL
    error_reporting(0);
    
    // ตั้งค่า Header เพื่อบังคับให้เบราว์เซอร์ดาวน์โหลดไฟล์
    $filename = "mtts_backup_" . date("Y-m-d_H-i-s") . ".sql";
    header("Content-Type: application/sql; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "-- MTTS Database Backup\n";
    echo "-- Date: " . date("Y-m-d H:i:s") . "\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+07:00\";\n\n";

    // ดึงรายชื่อตารางทั้งหมด
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    // วนลูปสร้างคำสั่ง SQL สำหรับแต่ละตาราง
    foreach ($tables as $table) {
        $result = $conn->query("SELECT * FROM $table");
        $num_fields = $result->field_count;

        $row2 = $conn->query("SHOW CREATE TABLE $table")->fetch_row();
        echo "\n\n-- Structure for table `$table`\n";
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $row2[1] . ";\n\n";

        // หากมีข้อมูล ให้สร้างคำสั่ง INSERT
        if ($result->num_rows > 0) {
            echo "-- Data for table `$table`\n";
            while ($row = $result->fetch_row()) {
                echo "INSERT INTO `$table` VALUES(";
                for ($j = 0; $j < $num_fields; $j++) {
                    if (isset($row[$j])) {
                        // ป้องกันอักขระพิเศษ
                        $escaped_val = $conn->real_escape_string($row[$j]);
                        echo "'" . $escaped_val . "'";
                    } else {
                        echo "NULL";
                    }
                    if ($j < ($num_fields - 1)) {
                        echo ", ";
                    }
                }
                echo ");\n";
            }
        }
    }
    
    // จบการทำงาน ไม่ให้พิมพ์ HTML ต่อ
    exit();
}

// ------------------------------------------------------------------
// ดึงข้อมูล Log (ตัวอย่าง: ดึงประวัติการขอเอกสาร)
// ------------------------------------------------------------------
$logs = [];
try {
    $stmt = $conn->prepare("SELECT * FROM document_requests ORDER BY request_date DESC LIMIT 50");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    // กรณีที่ตาราง document_requests ยังไม่มี
    $logs = [];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>บันทึกระบบ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root { --primary: #5a42a0; --bg-color: #f6f8fc; --text-dark: #1e293b; --text-gray: #64748b; }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; }

    /* Animations & Layout */
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }

    .app-container {
      width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh;
      position: relative; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }

    .top-header { display: flex; align-items: center; padding: 15px 20px; background: var(--bg-color); gap: 15px; }
    .btn-back { color: var(--text-dark); font-size: 1.2rem; text-decoration: none; }
    .header-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* Export Box */
    .export-container { padding: 0 20px; margin-bottom: 20px; }
    .btn-export {
      display: flex; align-items: center; justify-content: center; gap: 10px;
      width: 100%; background-color: #10b981; color: white; text-decoration: none;
      padding: 14px; border-radius: 12px; font-size: 1rem; font-weight: 600;
      box-shadow: 0 4px 15px rgba(16, 185, 129, 0.25); transition: background-color 0.2s;
    }
    .btn-export:active { background-color: #059669; transform: scale(0.98); }

    /* Log List */
    .section-title { display: flex; align-items: center; gap: 10px; margin: 10px 20px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.05rem; font-weight: 600; color: var(--text-dark); margin: 0; }

    .log-list { padding: 0 20px; display: flex; flex-direction: column; gap: 10px; }
    .log-card {
      background: white; border-radius: 12px; padding: 15px;
      display: flex; flex-direction: column; gap: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .log-header { display: flex; justify-content: space-between; align-items: center; }
    .log-type { font-weight: 600; font-size: 0.95rem; color: var(--primary); }
    .log-status { font-size: 0.75rem; padding: 3px 8px; border-radius: 20px; font-weight: 500; }
    .status-pending { background: #fef08a; color: #854d0e; }
    .status-completed { background: #bbf7d0; color: #166534; }
    .log-details { font-size: 0.85rem; color: var(--text-gray); }
    
    .empty-state { text-align: center; color: var(--text-gray); font-size: 0.9rem; padding: 20px; }

    /* Bottom Nav */
    .bottom-nav {
      position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); 
      height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; 
      display: flex; justify-content: space-around; align-items: center; 
      box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; 
    }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <div class="app-container">
    
    <div class="top-header fade-in-up delay-1">
      <a href="main.php" class="btn-back"><i class="fas fa-arrow-left"></i></a>
      <div class="header-title">
        <h1>บันทึกระบบ</h1>
      </div>
    </div>

    <!-- ปุ่ม Export SQL -->
    <div class="export-container fade-in-up delay-1">
      <a href="?export=sql" class="btn-export">
        <i class="fas fa-database"></i> สำรองฐานข้อมูล (.sql)
      </a>
    </div>

    <div class="section-title fade-in-up delay-2">
      <div class="section-pill"></div>
      <h3>ประวัติคำร้องล่าสุด</h3>
    </div>

    <!-- รายการ Log -->
    <div class="log-list fade-in-up delay-2">
      <?php if (empty($logs)): ?>
        <div class="empty-state">
          <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i><br>
          ยังไม่มีข้อมูลบันทึกระบบ
        </div>
      <?php else: ?>
        <?php foreach ($logs as $log): ?>
          <?php 
            // จัดการสีของสถานะ
            $status_class = ($log['status'] === 'completed') ? 'status-completed' : 'status-pending';
            $status_text = ($log['status'] === 'completed') ? 'เสร็จสิ้น' : 'รอดำเนินการ';
            // แปลงรูปแบบวันที่
            $date = date("d/m/Y H:i", strtotime($log['request_date']));
          ?>
          <div class="log-card">
            <div class="log-header">
              <span class="log-type"><?= htmlspecialchars($log['doc_type']) ?></span>
              <span class="log-status <?= $status_class ?>"><?= $status_text ?></span>
            </div>
            <div class="log-details">
              <div><i class="far fa-user"></i> รหัส: <?= htmlspecialchars($log['student_code']) ?></div>
              <div><i class="far fa-clock"></i> เวลา: <?= $date ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="log.php" class="nav-link active">
        <i class="fas fa-history"></i>
        <span>บันทึกระบบ</span>
      </a>
      <a href="reqdoc.php" class="nav-link">
        <i class="fas fa-vote-yea"></i>
        <span>คำร้อง</span>
      </a>
    </div>

  </div>

</body>
</html>
