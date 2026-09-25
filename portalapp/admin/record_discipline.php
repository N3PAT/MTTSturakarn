<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์ Admin (ถ้ามี)
if (empty($_SESSION['admin_id'])) {
    // header("Location: index.php");
    // exit();
}

$success_msg = '';
$error_msg = '';

// จัดการเมื่อมีการส่งฟอร์มบันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_record'])) {
    $student_code = trim($_POST['student_code'] ?? '');
    $check_date = $_POST['check_date'] ?? date('Y-m-d');
    
    // แปลงค่าจาก Checkbox (ถ้าไม่ได้ติ๊กจะไม่มีค่าส่งมา จึงให้เป็น 0)
    $card_bed = isset($_POST['card_bed']) ? 1 : 0;
    $card_shoe = isset($_POST['card_shoe']) ? 1 : 0;
    $card_cabinet = isset($_POST['card_cabinet']) ? 1 : 0;
    
    $total_cards = $card_bed + $card_shoe + $card_cabinet;
    
    // กำหนดสีสถานะ
    $status_color = 'green'; // 0-1 ใบ = ปกติ (สีเขียว)
    if ($total_cards == 2) {
        $status_color = 'yellow';
    } elseif ($total_cards == 3) {
        $status_color = 'red';
    }

    if (!empty($student_code)) {
        try {
            $stmt = $conn->prepare("INSERT INTO discipline_cards (student_code, check_date, card_bed, card_shoe, card_cabinet, total_cards, status_color) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiiis", $student_code, $check_date, $card_bed, $card_shoe, $card_cabinet, $total_cards, $status_color);
            
            if ($stmt->execute()) {
                $success_msg = "บันทึกข้อมูลบัตรวินัยของรหัส $student_code สำเร็จ!";
            } else {
                $error_msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
            }
        } catch (Throwable $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    } else {
        $error_msg = "กรุณากรอกรหัสนักเรียน";
    }
}

// ดึงข้อมูลล่าสุดของวันนี้มาแสดงผล (Preview)
$today_records = [];
try {
    $sql_today = "SELECT d.*, s.first_name, s.last_name 
                  FROM discipline_cards d 
                  LEFT JOIN students s ON d.student_code = s.student_code 
                  WHERE d.check_date = CURDATE() 
                  ORDER BY d.created_at DESC LIMIT 10";
    $res = $conn->query($sql_today);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $today_records[] = $row;
        }
    }
} catch (Throwable $e) {
    // เงียบไว้ถ้าตารางยังไม่มี
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>บันทึกบัตรวินัย - MTTS</title>
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

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }

    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }

    /* Header */
    .top-header { display: flex; align-items: center; padding: 20px 24px 10px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-right: 15px; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    
    /* Form Section */
    .form-section { padding: 20px 24px; }
    .form-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
    .input-group { margin-bottom: 15px; }
    .input-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }
    .input-group input[type="text"], .input-group input[type="date"] { width: 100%; padding: 12px 15px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.9rem; outline: none; transition: border-color 0.2s; }
    .input-group input:focus { border-color: #3b82f6; }

    /* Cards Checkbox Grid */
    .cards-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
    .card-checkbox { display: none; }
    .card-label { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; padding: 15px 5px; cursor: pointer; transition: all 0.2s; color: var(--text-gray); font-size: 0.8rem; font-weight: 600; text-align: center; }
    .card-label i { font-size: 1.5rem; }
    
    /* Active States */
    .card-checkbox:checked + .card-label { background: #fee2e2; border-color: #ef4444; color: #ef4444; }

    /* Status Display Indicator */
    .status-indicator { text-align: center; padding: 12px; border-radius: 10px; font-weight: 600; color: white; background-color: #10b981; transition: background-color 0.3s; margin-bottom: 20px; font-size: 0.95rem; }

    .submit-btn { width: 100%; background-color: var(--primary); color: white; border: none; padding: 14px; border-radius: 12px; font-family: 'Prompt', sans-serif; font-size: 1rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 10px rgba(15, 23, 42, 0.2); transition: transform 0.1s; }
    .submit-btn:active { transform: scale(0.98); }

    /* Alert */
    .alert { padding: 12px; border-radius: 10px; margin-bottom: 15px; font-size: 0.85rem; font-weight: 500; text-align: center; }
    .alert-success { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Recent Records */
    .recent-section { padding: 0 24px 20px; }
    .section-title { font-size: 0.95rem; font-weight: 700; color: var(--text-dark); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .record-item { background: white; padding: 12px 15px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .record-info h4 { margin: 0; font-size: 0.9rem; color: var(--text-dark); }
    .record-info p { margin: 0; font-size: 0.75rem; color: var(--text-gray); }
    .badge { padding: 4px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; color: white; }
    .badge.green { background: #10b981; }
    .badge.yellow { background: #facc15; color: #713f12; }
    .badge.red { background: #ef4444; }

    /* Bottom Nav */
    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 20px; border-top-right-radius: 20px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header fade-in-up">
      <a href="turakarn.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>บันทึกบัตรวินัย (ประจำวัน)</h1>
      </div>
    </div>

    <div class="form-section fade-in-up delay-1">
      <?php if($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success_msg ?></div>
      <?php endif; ?>
      <?php if($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= $error_msg ?></div>
      <?php endif; ?>

      <form class="form-card" method="POST" action="">
        <div class="input-group">
          <label>วันที่ตรวจ</label>
          <input type="date" name="check_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        
        <div class="input-group">
          <label>รหัสนักเรียน</label>
          <input type="text" name="student_code" placeholder="เช่น 66001" required>
        </div>

        <div class="input-group">
          <label>เลือกบัตรที่พบ (คลิกเพื่อเลือก)</label>
          <div class="cards-grid">
            <!-- บัตรเตียง -->
            <div>
              <input type="checkbox" id="c_bed" name="card_bed" class="card-checkbox" value="1" onchange="calculateCards()">
              <label for="c_bed" class="card-label">
                <i class="fas fa-bed"></i> เตียง
              </label>
            </div>
            <!-- บัตรตู้ -->
            <div>
              <input type="checkbox" id="c_cab" name="card_cabinet" class="card-checkbox" value="1" onchange="calculateCards()">
              <label for="c_cab" class="card-label">
                <i class="fas fa-box"></i> ตู้
              </label>
            </div>
            <!-- บัตรระเบียบ/รองเท้า -->
            <div>
              <input type="checkbox" id="c_shoe" name="card_shoe" class="card-checkbox" value="1" onchange="calculateCards()">
              <label for="c_shoe" class="card-label">
                <i class="fas fa-shoe-prints"></i> รองเท้า
              </label>
            </div>
          </div>
        </div>

        <div id="status_display" class="status-indicator">
          <i class="fas fa-check"></i> ปกติ (0 ใบ)
        </div>

        <button type="submit" name="submit_record" class="submit-btn">
          <i class="fas fa-save"></i> บันทึกข้อมูล
        </button>
      </form>
    </div>

    <!-- รายการบันทึกของวันนี้ -->
    <div class="recent-section fade-in-up delay-2">
      <div class="section-title">
        <i class="fas fa-history" style="color:var(--text-gray)"></i> รายการตรวจวันนี้
      </div>
      <?php if(empty($today_records)): ?>
        <div style="text-align:center; font-size:0.8rem; color:#64748b; padding:10px;">ยังไม่มีการบันทึกของวันนี้</div>
      <?php else: ?>
        <?php foreach($today_records as $rec): ?>
          <?php 
            $name = (!empty($rec['first_name'])) ? $rec['first_name'].' '.$rec['last_name'] : 'รหัส: '.$rec['student_code'];
            $color = $rec['status_color'];
            $badge_text = ($color == 'red') ? 'แดง (3)' : (($color == 'yellow') ? 'เหลือง (2)' : 'ปกติ ('.$rec['total_cards'].')');
          ?>
          <div class="record-item">
            <div class="record-info">
              <h4><?= htmlspecialchars($name) ?></h4>
              <p>เตียง: <?= $rec['card_bed']?'โดน':'ผ่าน' ?> | ตู้: <?= $rec['card_cabinet']?'โดน':'ผ่าน' ?> | รองเท้า: <?= $rec['card_shoe']?'โดน':'ผ่าน' ?></p>
            </div>
            <div class="badge <?= $color ?>"><?= $badge_text ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="bottom-nav">
      <a href="turakarn.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="record_discipline.php" class="nav-link active">
        <i class="fas fa-clipboard-check"></i>
        <span>ตรวจวินัย</span>
      </a>
    </div>
  </div>

  <script>
    // ฟังก์ชันคำนวณจำนวนบัตรและเปลี่ยนสีสถานะ
    function calculateCards() {
      let bed = document.getElementById('c_bed').checked ? 1 : 0;
      let cab = document.getElementById('c_cab').checked ? 1 : 0;
      let shoe = document.getElementById('c_shoe').checked ? 1 : 0;
      
      let total = bed + cab + shoe;
      let display = document.getElementById('status_display');
      
      if (total === 3) {
        display.style.backgroundColor = '#ef4444'; // แดง
        display.innerHTML = '<i class="fas fa-times-circle"></i> สถานะ: แดง (โดน 3 ใบ)';
      } else if (total === 2) {
        display.style.backgroundColor = '#facc15'; // เหลือง
        display.innerHTML = '<i class="fas fa-exclamation-triangle"></i> สถานะ: เหลือง (โดน 2 ใบ)';
      } else {
        display.style.backgroundColor = '#10b981'; // เขียว
        display.innerHTML = '<i class="fas fa-check"></i> สถานะ: ปกติ (โดน ' + total + ' ใบ)';
      }
    }
  </script>
</body>
</html>
