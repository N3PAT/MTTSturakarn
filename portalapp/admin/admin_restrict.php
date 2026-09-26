<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์ Admin
if (empty($_SESSION['admin_id'])) {
    header("Location: admin_main.php");
    exit();
}

$message = '';
$message_type = '';
$processed_list = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_names = $_POST['names_list'] ?? '';
    $weeks = (int)($_POST['restrict_weeks'] ?? 1);

    if (!empty($raw_names)) {
        // แยกข้อความด้วยการขึ้นบรรทัดใหม่ หรือ ลูกน้ำ
        $lines = preg_split('/[\r\n,]+/', $raw_names);
        $success_count = 0;
        $not_found = [];

        foreach ($lines as $line) {
            // 1. แปลงช่องว่างพิเศษ (Non-breaking space) ที่ติดมาจากการก๊อปปี้ให้เป็นช่องว่างปกติ
            $name = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $line);
            
            // ลบช่องว่างซ้าย-ขวา
            $name = trim($name);
            if (empty($name)) continue;

            // 2. ใช้ Regex ตัดคำนำหน้า:
            // ^ เริ่มต้นบรรทัด
            // \d+\.?\s* = ตัวเลข ตามด้วยจุด(มีหรือไม่มีก็ได้) และช่องว่าง
            // นชท\.\s* = คำว่า นชท. ตามด้วยช่องว่าง
            // u = รองรับภาษาไทย (Unicode)
            $clean_name = preg_replace('/^(\d+\.?\s*|นชท\.\s*)+/u', '', $name);
            $clean_name = trim($clean_name);
            
            if (empty($clean_name)) continue;

            // นำชื่อที่ทำความสะอาดแล้วมาเก็บไว้แสดงผล
            $processed_list[] = $clean_name;

            // =========================================================
            // ส่วนบันทึกลงฐานข้อมูล
            // =========================================================
            /*
            try {
                // ค้นหารหัสนักเรียนจากชื่อที่กรอกเข้ามา
                // แทนที่ช่องว่างด้วย % เพื่อให้หาเจอแม้พิมพ์เว้นวรรคไม่ตรงกัน
                $search_term = "%" . str_replace(' ', '%', $clean_name) . "%"; 
                
                $stmt = $conn->prepare("SELECT student_code FROM students WHERE CONCAT(first_name, ' ', last_name) LIKE ? OR first_name LIKE ? LIMIT 1");
                $stmt->bind_param("ss", $search_term, $search_term);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $student_code = $row['student_code'];
                    
                    // บันทึกลงตารางกักบริเวณ
                    $insert = $conn->prepare("INSERT INTO restrictions (student_code, weeks_count) VALUES (?, ?)");
                    $insert->bind_param("si", $student_code, $weeks);
                    $insert->execute();
                    
                    $success_count++;
                } else {
                    $not_found[] = $clean_name;
                }
            } catch (Exception $e) {
                error_log("Restrict insert error: " . $e->getMessage());
            }
            */
            // =========================================================
        }

        $message = "ประมวลผลสำเร็จ " . count($processed_list) . " รายชื่อ (สั่งกัก $weeks สัปดาห์)";
        $message_type = "success";

    } else {
        $message = "กรุณาวางรายชื่อที่ต้องการกักบริเวณ";
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>สั่งกักบริเวณ - MTTS</title>
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

    .app-container { 
      width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 100px; box-shadow: 0 0 20px rgba(0,0,0,0.05); position: relative; 
    }

    .top-header { display: flex; align-items: center; padding: 20px 24px 10px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-right: 15px; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    .form-section { padding: 20px 24px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }
    
    .name-textarea { width: 100%; height: 180px; padding: 15px; border-radius: 14px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.9rem; resize: none; outline: none; transition: border-color 0.2s; }
    .name-textarea:focus { border-color: var(--primary); }
    .help-text { font-size: 0.75rem; color: var(--text-gray); margin-top: 6px; display: block; line-height: 1.4; }

    .week-select { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.95rem; background: white; outline: none; }

    .submit-btn { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 14px; font-family: 'Prompt', sans-serif; font-size: 1rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); transition: transform 0.1s, background 0.2s; }
    .submit-btn:active { transform: scale(0.98); background: var(--primary-hover); }

    .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    .result-box { background: white; border-radius: 14px; padding: 15px; margin-top: 20px; border: 1px solid #e2e8f0; }
    .result-box h4 { margin: 0 0 10px 0; font-size: 0.9rem; color: var(--text-dark); }
    .name-tag { display: inline-block; background: #f8fafc; color: #0f172a; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; margin: 0 6px 8px 0; border: 1px solid #e2e8f0; font-weight: 500;}

    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: #0f172a; }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header">
      <a href="turakarn.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>เพิ่มรายชื่อกักบริเวณ</h1>
      </div>
    </div>

    <div class="form-section">
      
      <?php if (!empty($message)): ?>
        <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
          <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
          <?= htmlspecialchars($message) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        
        <div class="form-group">
          <label for="names_list">วางรายชื่อ (บรรทัดละ 1 คน)</label>
          <textarea 
            name="names_list" 
            id="names_list" 
            class="name-textarea" 
            placeholder="ตัวอย่าง:&#10;นชท.ศักรพัฒน ดวงอินทร์&#10;1.ธนเดช น.&#10;2.รัฐธรรมนูญ ค" 
            required></textarea>
          <span class="help-text">* ระบบจะตัดตัวเลข และคำว่า นชท. ออกให้โดยอัตโนมัติ</span>
        </div>

        <div class="form-group">
          <label for="restrict_weeks">จำนวนสัปดาห์ที่กักบริเวณ</label>
          <select name="restrict_weeks" id="restrict_weeks" class="week-select">
            <option value="1">1 สัปดาห์</option>
            <option value="2">2 สัปดาห์</option>
            <option value="3">3 สัปดาห์</option>
            <option value="4">4 สัปดาห์</option>
            <option value="8">8 สัปดาห์ (งดกลับบ้านยาว)</option>
          </select>
        </div>

        <button type="submit" class="submit-btn">
          <i class="fas fa-gavel"></i> บันทึกรายชื่อ
        </button>
      </form>

      <?php if (!empty($processed_list)): ?>
        <div class="result-box">
          <h4>รายชื่อที่ระบบเตรียมบันทึก:</h4>
          <?php foreach ($processed_list as $n): ?>
            <div class="name-tag"><?= htmlspecialchars($n) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="admin_main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="admin_restrict.php" class="nav-link active" style="color: var(--primary);">
        <i class="fas fa-ban"></i>
        <span>กักบริเวณ</span>
      </a>
      <a href="admin_votes.php" class="nav-link">
        <i class="fas fa-vote-yea"></i>
        <span>ผลโหวต</span>
      </a>
    </div>

  </div>

</body>
</html>
