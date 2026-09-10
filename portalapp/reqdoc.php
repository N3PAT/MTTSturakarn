<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

$studentCode = $_SESSION['student_code'];
$success_msg = '';
$error_msg = '';

// จัดการเมื่อมีการกดปุ่ม "บันทึก"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_type'])) {
    $doc_type = trim($_POST['doc_type']);
    
    try {
        if (!isset($conn) || !($conn instanceof mysqli)) {
            throw new Exception("ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
        }

        $conn->set_charset("utf8mb4");

        // เตรียมคำสั่ง SQL เพื่อบันทึกข้อมูล
        $stmt = $conn->prepare("INSERT INTO document_requests (student_code, doc_type) VALUES (?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("ss", $studentCode, $doc_type);
            
            if ($stmt->execute()) {
                $success_msg = "ส่งคำร้องสำเร็จ! กรุณาไปรายงานตัวและรับเอกสารที่ห้อง บก.พัน.๑";
            } else {
                throw new Exception("ไม่สามารถบันทึกข้อมูลได้: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("เกิดข้อผิดพลาดในการเตรียมคำสั่ง SQL: " . $conn->error);
        }

    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>คำร้องขอเอกสาร - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #5a42a0;
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

    /* Animations */
    .fade-in-up {
      opacity: 0;
      transform: translateY(20px);
      animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; }
    .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; }

    /* Layout */
    .app-container {
      width: 100%;
      max-width: 430px;
      background-color: var(--bg-color);
      min-height: 100vh;
      position: relative;
      padding-bottom: 90px;
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }

    .top-header {
      display: flex;
      align-items: center;
      padding: 15px 20px;
      background: var(--bg-color);
      gap: 15px;
    }

    .btn-back {
      color: var(--text-dark);
      font-size: 1.2rem;
      text-decoration: none;
    }

    .header-title h1 {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text-dark);
      margin: 0;
    }

    .section-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 10px 20px 15px;
    }

    .section-pill {
      width: 5px;
      height: 24px;
      background: var(--primary);
      border-radius: 4px;
    }

    .section-title h3 {
      font-size: 1.05rem;
      font-weight: 600;
      color: var(--text-dark);
      margin: 0;
    }

    /* Form & Radio Cards */
    .req-form {
      padding: 0 20px;
    }

    .doc-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-bottom: 20px;
    }

    .doc-item input[type="radio"] {
      display: none;
    }

    .doc-card {
      background: white;
      border: 2px solid transparent;
      border-radius: 14px;
      padding: 15px;
      display: flex;
      align-items: center;
      gap: 15px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .doc-item input[type="radio"]:checked + .doc-card {
      border-color: var(--primary);
      background-color: #f3e8ff;
    }

    .doc-item input[type="radio"]:checked + .doc-card .icon-box {
      background-color: var(--primary);
      color: white;
    }

    .icon-box {
      width: 45px;
      height: 45px;
      border-radius: 12px;
      background: #f1f5f9;
      color: var(--text-gray);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      transition: all 0.2s ease;
    }

    .doc-name {
      font-size: 0.95rem;
      font-weight: 500;
      color: var(--text-dark);
    }

    /* Remark Box */
    .remark-box {
      background-color: #fff7ed;
      border: 1px solid #ffedd5;
      border-left: 4px solid #f97316;
      border-radius: 10px;
      padding: 15px;
      margin-bottom: 25px;
      display: flex;
      gap: 12px;
      align-items: flex-start;
    }
    
    .remark-box i {
      color: #ea580c;
      font-size: 1.2rem;
      margin-top: 2px;
    }

    .remark-box p {
      margin: 0;
      font-size: 0.85rem;
      color: #9a3412;
      line-height: 1.5;
      font-weight: 500;
    }

    /* Submit Button */
    .btn-submit {
      width: 100%;
      background-color: var(--primary);
      color: white;
      border: none;
      padding: 14px;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      font-family: 'Prompt', sans-serif;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(90, 66, 160, 0.25);
      transition: background-color 0.2s;
    }
    
    .btn-submit:active { background-color: #4a3585; transform: scale(0.98); }

    /* Alerts */
    .alert-success {
      background-color: #dcfce7;
      color: #166534;
      padding: 15px;
      border-radius: 12px;
      margin: 0 20px 20px;
      font-size: 0.9rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .alert-error {
      background-color: #fee2e2;
      color: #991b1b;
      padding: 15px;
      border-radius: 12px;
      margin: 0 20px 20px;
      font-size: 0.9rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Bottom Nav */
    .bottom-nav {
      position: fixed; 
      bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); 
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
    
    <!-- Header -->
    <div class="top-header fade-in-up delay-1">
      <a href="main.php" class="btn-back"><i class="fas fa-arrow-left"></i></a>
      <div class="header-title">
        <h1>คำร้องขอเอกสาร</h1>
      </div>
    </div>

    <!-- Success / Error Messages -->
    <?php if ($success_msg): ?>
    <div class="alert-success fade-in-up delay-1">
      <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i>
      <?= htmlspecialchars($success_msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert-error fade-in-up delay-1">
      <i class="fas fa-exclamation-circle" style="font-size: 1.2rem;"></i>
      <?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <div class="section-title fade-in-up delay-2">
      <div class="section-pill"></div>
      <h3>เลือกประเภทเอกสาร</h3>
    </div>

    <!-- Form -->
    <form method="POST" action="" class="req-form fade-in-up delay-3">
      
      <div class="doc-list">
        <!-- 1. ใบลา -->
        <label class="doc-item">
          <input type="radio" name="doc_type" value="ใบลา" required>
          <div class="doc-card">
            <div class="icon-box"><i class="fas fa-file-alt"></i></div>
            <span class="doc-name">1. ใบลา</span>
          </div>
        </label>

        <!-- 2. ใบลา 3 ท่อน -->
        <label class="doc-item">
          <input type="radio" name="doc_type" value="ใบลา 3 ท่อน">
          <div class="doc-card">
            <div class="icon-box"><i class="fas fa-file-signature"></i></div>
            <span class="doc-name">2. ใบลา 3 ท่อน</span>
          </div>
        </label>

        <!-- 3. ใบรายงานชี้แจง -->
        <label class="doc-item">
          <input type="radio" name="doc_type" value="ใบรายงานชี้แจง">
          <div class="doc-card">
            <div class="icon-box"><i class="fas fa-comment-dots"></i></div>
            <span class="doc-name">3. ใบรายงานชี้แจง</span>
          </div>
        </label>

        <!-- 4. ใบรายงานความผิด -->
        <label class="doc-item">
          <input type="radio" name="doc_type" value="ใบรายงานความผิด">
          <div class="doc-card">
            <div class="icon-box"><i class="fas fa-exclamation-triangle"></i></div>
            <span class="doc-name">4. ใบรายงานความผิด</span>
          </div>
        </label>

        <!-- 5. ใบแทนเวรรักษาการณ์ -->
        <label class="doc-item">
          <input type="radio" name="doc_type" value="ใบแทนเวรรักษาการณ์">
          <div class="doc-card">
            <div class="icon-box"><i class="fas fa-user-shield"></i></div>
            <span class="doc-name">5. ใบแทนเวรรักษาการณ์</span>
          </div>
        </label>

        <!-- 6. ขออนุญาตแต่งกายชุดวอร์ม -->
        <label class="doc-item">
          <input type="radio" name="doc_type" value="ขออนุญาตแต่งกายชุดวอร์ม">
          <div class="doc-card">
            <div class="icon-box"><i class="fas fa-tshirt"></i></div>
            <span class="doc-name">6. ขออนุญาตแต่งกายชุดวอร์ม</span>
          </div>
        </label>
      </div>

      <!-- หมายเหตุ -->
      <div class="remark-box">
        <i class="fas fa-info-circle"></i>
        <p><strong>หมายเหตุ:</strong> เมื่อกดบันทึกให้ไปรายงานตัวและรับที่ห้อง บก.พัน.๑</p>
      </div>

      <!-- ปุ่มบันทึก -->
      <button type="submit" class="btn-submit">
        <i class="fas fa-save" style="margin-right: 5px;"></i> บันทึกคำร้อง
      </button>

    </form>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      
      <a href="duty.php" class="nav-link">
        <i class="fas fa-calendar-alt"></i>
        <span>เวรของฉัน</span>
      </a>

      <!-- ไฮไลท์เมนูคำร้องเป็น active -->
      <a href="reqdoc.php" class="nav-link active">
        <i class="fas fa-vote-yea"></i>
        <span>คำร้อง</span>
      </a>
    </div>

  </div>

</body>
</html>
