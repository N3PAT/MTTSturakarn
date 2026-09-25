<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['admin_id'])) {
    // header("Location: index.php");
    // exit();
}

$success_msg = '';
$error_msg = '';

// ตัวแปรสำหรับคัดกรอง
$filter_company = $_GET['company'] ?? '';
$filter_platoon = $_GET['platoon'] ?? '';
$check_date = $_POST['check_date'] ?? ($_GET['date'] ?? date('Y-m-d'));

// ==========================================
// 1. จัดการเมื่อมีการกด "บันทึกข้อมูลทั้งหมวด"
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_batch'])) {
    if (!empty($_POST['records']) && is_array($_POST['records'])) {
        $conn->begin_transaction();
        try {
            // ใช้คำสั่ง INSERT ... ON DUPLICATE KEY UPDATE เพื่อรองรับการกดเซฟจากหลายอุปกรณ์พร้อมกัน
            $stmt = $conn->prepare("
                INSERT INTO discipline_cards 
                (student_code, check_date, card_bed, card_shoe, card_cabinet, total_cards, status_color) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                card_bed = VALUES(card_bed), 
                card_shoe = VALUES(card_shoe), 
                card_cabinet = VALUES(card_cabinet), 
                total_cards = VALUES(total_cards), 
                status_color = VALUES(status_color)
            ");

            foreach ($_POST['records'] as $std_code => $cards) {
                // เก็บค่าสถานะบัตร (เราใช้ column เดิมใน DB แต่ใน UI จะแสดงเป็นบัตรเปล่า 3 ใบ)
                $c1 = isset($cards['c1']) ? 1 : 0;
                $c2 = isset($cards['c2']) ? 1 : 0;
                $c3 = isset($cards['c3']) ? 1 : 0;
                
                $total_cards = $c1 + $c2 + $c3;
                
                // กำหนดสี
                $status_color = 'green';
                if ($total_cards == 2) $status_color = 'yellow';
                if ($total_cards == 3) $status_color = 'red';

                $stmt->bind_param("ssiiiis", $std_code, $check_date, $c1, $c2, $c3, $total_cards, $status_color);
                $stmt->execute();
            }
            $conn->commit();
            $success_msg = "บันทึกข้อมูลสำเร็จเรียบร้อยแล้ว!";
            
            // อัปเดตตัวกรองเพื่อให้กลับมาหน้าเดิม
            $filter_company = $_POST['company'] ?? '';
            $filter_platoon = $_POST['platoon'] ?? '';
        } catch (Throwable $e) {
            $conn->rollback();
            $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// ==========================================
// 2. ดึงรายชื่อนักเรียนตาม หมวด และ กองร้อย ที่เลือก
// ==========================================
$students_list = [];
if (!empty($filter_company) && !empty($filter_platoon)) {
    try {
        // ดึงรายชื่อ พร้อมข้อมูลการตรวจของวันนี้ (ถ้าเคยบันทึกจากอุปกรณ์อื่นไปแล้ว จะดึงมาแสดงด้วย)
        $sql = "
            SELECT s.student_code, s.prefix, s.first_name, s.last_name, 
                   d.card_bed AS c1, d.card_shoe AS c2, d.card_cabinet AS c3, d.total_cards, d.status_color
            FROM students s
            LEFT JOIN discipline_cards d ON s.student_code = d.student_code AND d.check_date = ?
            WHERE s.company = ? AND s.platoon = ?
            ORDER BY s.student_code ASC
        ";
        $stmt_s = $conn->prepare($sql);
        $stmt_s->bind_param("sss", $check_date, $filter_company, $filter_platoon);
        $stmt_s->execute();
        $res = $stmt_s->get_result();
        while ($row = $res->fetch_assoc()) {
            $students_list[] = $row;
        }
    } catch (Throwable $e) {
        $error_msg = "Error loading students: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>บันทึกบัตรวินัยแบบกลุ่ม - MTTS</title>
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

    .app-container { width: 100%; max-width: 500px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }

    /* Header */
    .top-header { display: flex; align-items: center; padding: 20px 24px 10px; background: white; position: sticky; top: 0; z-index: 10; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: #f8fafc; margin-right: 15px; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    
    /* Filter Section */
    .filter-card { background: white; margin: 15px 24px; padding: 15px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
    .filter-row { display: flex; gap: 10px; margin-bottom: 10px; }
    .filter-group { flex: 1; }
    .filter-group label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-gray); margin-bottom: 4px; }
    .filter-group select, .filter-group input { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.85rem; outline: none; }
    .btn-filter { width: 100%; background: var(--primary); color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; font-family: 'Prompt', sans-serif; cursor: pointer; }

    /* Student List */
    .list-section { padding: 0 24px 20px; }
    .student-row { background: white; padding: 12px 15px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; }
    .student-info h4 { margin: 0; font-size: 0.9rem; color: var(--text-dark); }
    .student-info p { margin: 0; font-size: 0.75rem; color: var(--text-gray); }

    /* Generic Cards UI */
    .cards-container { display: flex; gap: 6px; align-items: center; }
    .card-checkbox { display: none; }
    
    /* ดีไซน์บัตรเปล่า */
    .empty-card { 
      width: 22px; 
      height: 30px; 
      border: 2px solid #cbd5e1; 
      border-radius: 4px; 
      display: inline-block; 
      cursor: pointer; 
      background: #f8fafc; 
      transition: all 0.2s ease;
      position: relative;
    }
    
    .card-checkbox:checked + .empty-card { 
      border-color: #ef4444; 
      background: #fee2e2; 
    }
    .card-checkbox:checked + .empty-card::after {
      content: '\f00d'; /* X icon in FontAwesome */
      font-family: 'Font Awesome 5 Free';
      font-weight: 900;
      color: #ef4444;
      font-size: 0.7rem;
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
    }

    /* Status Indicator Dot */
    .status-dot { width: 12px; height: 12px; border-radius: 50%; background-color: #10b981; margin-left: 10px; transition: 0.3s; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    .status-dot.yellow { background-color: #facc15; }
    .status-dot.red { background-color: #ef4444; }

    /* Buttons */
    .submit-btn { width: 100%; background-color: #10b981; color: white; border: none; padding: 14px; border-radius: 12px; font-family: 'Prompt', sans-serif; font-size: 1rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2); margin-top: 10px; position: sticky; bottom: 85px; z-index: 10; }
    .alert { padding: 12px; border-radius: 10px; margin: 15px 24px; font-size: 0.85rem; font-weight: 500; text-align: center; }
    .alert-success { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

    /* Bottom Nav */
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 500px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 20px; border-top-right-radius: 20px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.05); z-index: 100; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header">
      <a href="turakarn.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>ตรวจบัตรวินัย (แบบรายหมวด)</h1>
      </div>
    </div>

    <?php if($success_msg): ?>
      <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success_msg ?></div>
    <?php endif; ?>

    <!-- ฟอร์มเลือกกองร้อยและหมวด -->
    <div class="filter-card">
      <form method="GET" action="">
        <div class="filter-row">
          <div class="filter-group">
            <label>วันที่ตรวจ</label>
            <input type="date" name="date" value="<?= $check_date ?>" required>
          </div>
          <div class="filter-group">
            <label>กองร้อย</label>
            <select name="company" required>
              <option value="">-- เลือก --</option>
              <option value="1" <?= $filter_company == '1' ? 'selected' : '' ?>>ร้อย. 1</option>
              <option value="2" <?= $filter_company == '2' ? 'selected' : '' ?>>ร้อย. 2</option>
              <option value="3" <?= $filter_company == '3' ? 'selected' : '' ?>>ร้อย. 3</option>
              <option value="4" <?= $filter_company == '4' ? 'selected' : '' ?>>ร้อย. 4</option>
              <option value="5" <?= $filter_company == '5' ? 'selected' : '' ?>>ร้อย. 5</option>
            </select>
          </div>
          <div class="filter-group">
            <label>หมวด</label>
            <select name="platoon" required>
              <option value="">-- เลือก --</option>
              <option value="1" <?= $filter_platoon == '1' ? 'selected' : '' ?>>หมวด 1</option>
              <option value="2" <?= $filter_platoon == '2' ? 'selected' : '' ?>>หมวด 2</option>
              <option value="3" <?= $filter_platoon == '3' ? 'selected' : '' ?>>หมวด 3</option>
              <option value="4" <?= $filter_platoon == '4' ? 'selected' : '' ?>>หมวด 4</option>
            </select>
          </div>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> ค้นหารายชื่อ</button>
      </form>
    </div>

    <!-- ฟอร์มแสดงรายชื่อและบันทึกข้อมูล -->
    <div class="list-section">
      <?php if (!empty($filter_company) && empty($students_list)): ?>
        <div style="text-align:center; color:#64748b; margin-top:20px; font-size:0.9rem;">
          ไม่พบรายชื่อนักเรียนใน ร้อย.<?= $filter_company ?> หมวด <?= $filter_platoon ?>
        </div>
      <?php elseif (!empty($students_list)): ?>
        
        <form method="POST" action="">
          <input type="hidden" name="check_date" value="<?= $check_date ?>">
          <input type="hidden" name="company" value="<?= $filter_company ?>">
          <input type="hidden" name="platoon" value="<?= $filter_platoon ?>">

          <div style="display:flex; justify-content:space-between; margin-bottom:10px; padding:0 5px;">
            <span style="font-size:0.85rem; font-weight:600; color:var(--text-gray);">รายชื่อ (ร้อย.<?= $filter_company ?> หมวด <?= $filter_platoon ?>)</span>
            <span style="font-size:0.85rem; font-weight:600; color:var(--text-gray);">เลือกบัตร</span>
          </div>

          <?php foreach ($students_list as $std): ?>
            <?php 
              $std_code = $std['student_code'];
              $name = trim($std['prefix'] . $std['first_name'] . ' ' . $std['last_name']);
              // เตรียมค่าสีเริ่มต้น ถ้าดึงมาจากฐานข้อมูล (กรณีเครื่องอื่นบันทึกไว้แล้ว)
              $dot_color = $std['status_color'] ?? 'green';
            ?>
            <div class="student-row">
              <div class="student-info">
                <h4><?= htmlspecialchars($name) ?></h4>
                <p>รหัส: <?= htmlspecialchars($std_code) ?></p>
              </div>
              
              <div class="cards-container">
                <!-- บัตรใบที่ 1 -->
                <label>
                  <input type="checkbox" class="card-checkbox cb-<?= $std_code ?>" 
                         name="records[<?= $std_code ?>][c1]" value="1" 
                         <?= !empty($std['c1']) ? 'checked' : '' ?> 
                         onchange="updateRowColor('<?= $std_code ?>')">
                  <div class="empty-card"></div>
                </label>
                <!-- บัตรใบที่ 2 -->
                <label>
                  <input type="checkbox" class="card-checkbox cb-<?= $std_code ?>" 
                         name="records[<?= $std_code ?>][c2]" value="1" 
                         <?= !empty($std['c2']) ? 'checked' : '' ?> 
                         onchange="updateRowColor('<?= $std_code ?>')">
                  <div class="empty-card"></div>
                </label>
                <!-- บัตรใบที่ 3 -->
                <label>
                  <input type="checkbox" class="card-checkbox cb-<?= $std_code ?>" 
                         name="records[<?= $std_code ?>][c3]" value="1" 
                         <?= !empty($std['c3']) ? 'checked' : '' ?> 
                         onchange="updateRowColor('<?= $std_code ?>')">
                  <div class="empty-card"></div>
                </label>
                
                <!-- จุดแสดงสถานะสี -->
                <div id="dot-<?= $std_code ?>" class="status-dot <?= $dot_color ?>"></div>
              </div>
            </div>
          <?php endforeach; ?>

          <button type="submit" name="submit_batch" class="submit-btn">
            <i class="fas fa-save"></i> บันทึกข้อมูลทั้งหมวด
          </button>
        </form>
      <?php endif; ?>
    </div>

    <div class="bottom-nav">
      <a href="turakarn.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="record_discipline.php" class="nav-link active">
        <i class="fas fa-clipboard-list"></i>
        <span>ตรวจวินัย</span>
      </a>
    </div>
  </div>

  <script>
    // ฟังก์ชันสำหรับคำนวณสีเมื่อมีการคลิกบัตรของแต่ละคนแบบ Real-time
    function updateRowColor(studentCode) {
      const checkboxes = document.querySelectorAll('.cb-' + studentCode);
      let checkedCount = 0;
      
      checkboxes.forEach(cb => {
        if (cb.checked) checkedCount++;
      });
      
      const dot = document.getElementById('dot-' + studentCode);
      dot.className = 'status-dot'; // Reset classes
      
      if (checkedCount === 3) {
        dot.classList.add('red');
      } else if (checkedCount === 2) {
        dot.classList.add('yellow');
      } else {
        // เขียว (ปกติ) ค่าเริ่มต้นไม่ต้อง add class เพิ่ม
      }
    }
  </script>
</body>
</html>
