<?php
// เปิดการแสดง Error ชั่วคราวสำหรับการทดสอบ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

/* ตรวจสอบการล็อกอินนักเรียนผ่าน Session */
if (empty($_SESSION['student_code'])) {
    // หากไม่มี Session ให้เด้งกลับไปหน้า login
    header("Location: login.php");
    exit();
}

$studentCode = $_SESSION['student_code'];
$error = '';
$success = '';
$id_card_input = ''; // ประกาศไว้เพื่อคงค่าเดิมในฟอร์มกรณีเกิด error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_card_input    = trim($_POST['id_card'] ?? '');
    $old_password     = trim($_POST['old_password'] ?? '');
    $new_password     = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($id_card_input) || empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (strlen($id_card_input) !== 13 || !ctype_digit($id_card_input)) {
        $error = 'กรุณากรอกเลขบัตรประชาชนให้ครบ 13 หลัก เป็นตัวเลขเท่านั้น';
    } elseif ($new_password !== $confirm_password) {
        $error = 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน';
    } elseif (strlen($new_password) < 6) {
        $error = 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร';
    } else {
        try {
            if (!isset($conn) || !($conn instanceof mysqli)) {
                throw new Exception("ไม่พบการเชื่อมต่อฐานข้อมูลตัวแปร \$conn แบบ MySQLi");
            }
            $conn->set_charset("utf8mb4");

            // ดึงข้อมูลรหัสผ่านและเลขบัตรประชาชนเดิมมาตรวจสอบ
            $stmt = $conn->prepare("SELECT id_card, password FROM students WHERE student_code = ? LIMIT 1");
            $stmt->bind_param("s", $studentCode);
            $stmt->execute();
            $result = $stmt->get_result();
            $student = $result->fetch_assoc();
            $stmt->close();

            if ($student) {
                $db_id_card  = trim($student['id_card'] ?? '');
                $db_password = trim($student['password']); // trim ป้องกันช่องว่างจาก DB

                // 1. ตรวจสอบเลขบัตรประชาชน (ถ้าใน DB มีบันทึกไว้แล้วต้องตรงกัน)
                if (!empty($db_id_card) && $db_id_card !== $id_card_input) {
                    $error = 'เลขบัตรประจำตัวประชาชนไม่ถูกต้อง (ไม่ตรงกับข้อมูลเดิม)';
                } else {
                    // 2. ตรวจสอบรหัสผ่านเดิม
                    $is_old_pass_valid = false;
                    if (password_verify($old_password, $db_password)) {
                        $is_old_pass_valid = true;
                    } elseif ($old_password === $db_password || md5($old_password) === $db_password || sha1($old_password) === $db_password) {
                        $is_old_pass_valid = true;
                    }

                    if (!$is_old_pass_valid) {
                        $error = 'รหัสผ่านเดิมไม่ถูกต้อง';
                    } else {
                        // 3. ทำการเข้ารหัสรหัสผ่านใหม่และอัปเดตข้อมูล
                        $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

                        $update_stmt = $conn->prepare("UPDATE students SET password = ?, id_card = ? WHERE student_code = ?");
                        $update_stmt->bind_param("sss", $hashed_new_password, $id_card_input, $studentCode);

                        if ($update_stmt->execute()) {
                            $success = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว กำลังนำท่านไปหน้าหลัก...';
                            // อัปเดต Session id_card เพื่อให้ผ่านเงื่อนไขในหน้า Login ครั้งถัดไป
                            $_SESSION['id_card'] = $id_card_input; 
                        } else {
                            $error = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง';
                        }
                        $update_stmt->close();
                    }
                }
            } else {
                $error = 'ไม่พบข้อมูลนักเรียนในระบบ';
            }
        } catch (Throwable $e) {
            $error = 'เกิดข้อผิดพลาดของระบบ: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ตั้งรหัสผ่านใหม่ - MTTS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary-color: #4361ee;
      --primary-hover: #3751d7;
      --primary-light: #e0e8ff;
      --secondary-color: #3f3f46;
      --bg-color: #f4f6f9;
      --card-bg: #ffffff;
    }

    body {
      font-family: 'Prompt', sans-serif;
      background: linear-gradient(135deg, #f4f6f9 0%, #eef2f7 100%);
      color: var(--secondary-color);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px 15px;
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
    }

    /* --- Animations --- */
    @keyframes fadeInDown {
      0% { opacity: 0; transform: translateY(-30px); }
      100% { opacity: 1; transform: translateY(0); }
    }

    @keyframes fadeInUp {
      0% { opacity: 0; transform: translateY(30px); }
      100% { opacity: 1; transform: translateY(0); }
    }

    @keyframes popIn {
      0% { opacity: 0; transform: scale(0.5); }
      70% { transform: scale(1.05); }
      100% { opacity: 1; transform: scale(1); }
    }

    .setpass-wrapper {
      width: 100%;
      max-width: 440px;
      animation: fadeInDown 0.6s ease-out forwards;
    }

    .setpass-card {
      background: var(--card-bg);
      border-radius: 28px;
      padding: 35px 25px;
      box-shadow: 0 15px 35px rgba(67, 97, 238, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.8);
      animation: fadeInUp 0.6s ease-out 0.2s forwards;
      opacity: 0;
    }

    .brand-icon-box {
      width: 80px;
      height: 80px;
      background: var(--primary-light);
      color: var(--primary-color);
      border-radius: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 15px;
      font-size: 2rem;
      box-shadow: 0 8px 20px rgba(67, 97, 238, 0.15);
      animation: popIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s forwards;
      opacity: 0;
    }

    .setpass-header h4 {
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 4px;
    }

    .setpass-header p {
      font-size: 0.88rem;
      color: #64748b;
    }

    .form-label {
      font-weight: 500;
      font-size: 0.88rem;
      color: #334155;
      margin-bottom: 6px;
    }

    .input-group {
      border-radius: 14px;
      overflow: hidden;
      transition: all 0.3s ease;
    }

    .input-group:focus-within {
      box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
      transform: translateY(-2px);
    }

    .input-group-text {
      background-color: #f8fafc;
      border-color: #e2e8f0;
      color: #64748b;
      padding-left: 16px;
      padding-right: 16px;
    }

    .form-control {
      border-color: #e2e8f0;
      background-color: #f8fafc;
      padding: 11px 15px;
      font-size: 0.92rem;
    }

    .toggle-btn {
      border-color: #e2e8f0;
      background-color: #f8fafc;
      color: #64748b;
      cursor: pointer;
      padding-right: 16px;
      padding-left: 16px;
    }

    .form-control:focus {
      border-color: var(--primary-color);
      background-color: #ffffff;
      box-shadow: none;
    }

    .btn-submit {
      background-color: var(--primary-color);
      border: none;
      color: #ffffff;
      font-weight: 600;
      padding: 13px;
      border-radius: 14px;
      font-size: 1rem;
      box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
      transition: all 0.3s ease;
    }

    .btn-submit:hover, .btn-submit:active {
      background-color: var(--primary-hover);
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
    }

    .alert-custom {
      border-radius: 14px;
      font-size: 0.88rem;
      font-weight: 500;
      animation: fadeInDown 0.4s ease-out;
    }
  </style>
</head>
<body>

  <div class="setpass-wrapper">
    <div class="setpass-card">
      
      <div class="text-center setpass-header mb-4">
        <div class="brand-icon-box">
          <i class="fas fa-key"></i>
        </div>
        <h4>ตั้งรหัสผ่านใหม่</h4>
        <p>รหัสประจำตัว: <strong><?= htmlspecialchars($studentCode) ?></strong></p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-custom text-center p-3 mb-4" role="alert">
          <i class="fas fa-exclamation-circle me-1"></i> <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-custom text-center p-3 mb-4" role="alert">
          <i class="fas fa-check-circle me-1"></i> <?= htmlspecialchars($success) ?>
        </div>
        <script>
          // เปลี่ยนหน้าไปที่ index.php ตามเงื่อนไขล็อกอินหลัก
          setTimeout(function() {
            window.location.href = 'main.php';
          }, 2000);
        </script>
      <?php else: ?>

      <form method="POST" action="">
        
        <!-- เลขบัตรประชาชน 13 หลัก -->
        <div class="mb-3">
          <label for="id_card" class="form-label">เลขบัตรประจำตัวประชาชน (13 หลัก)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-address-card"></i></span>
            <input type="text" 
                   class="form-control" 
                   id="id_card" 
                   name="id_card" 
                   placeholder="กรอกเลขบัตรประชาชน 13 หลัก" 
                   value="<?= htmlspecialchars($id_card_input) ?>"
                   maxlength="13"
                   inputmode="numeric"
                   required autofocus>
          </div>
        </div>

        <!-- รหัสผ่านเดิม -->
        <div class="mb-3">
          <label for="old_password" class="form-label">รหัสผ่านเดิม</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-lock"></i></span>
            <input type="password" 
                   class="form-control" 
                   id="old_password" 
                   name="old_password" 
                   placeholder="กรอกรหัสผ่านเดิม" 
                   required>
            <button class="btn toggle-btn" type="button" onclick="togglePass('old_password', 'icon_old')">
              <i class="fas fa-eye" id="icon_old"></i>
            </button>
          </div>
        </div>

        <!-- รหัสผ่านใหม่ -->
        <div class="mb-3">
          <label for="new_password" class="form-label">รหัสผ่านใหม่ (อย่างน้อย 6 ตัวอักษร)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-key"></i></span>
            <input type="password" 
                   class="form-control" 
                   id="new_password" 
                   name="new_password" 
                   placeholder="กำหนดรหัสผ่านใหม่" 
                   required>
            <button class="btn toggle-btn" type="button" onclick="togglePass('new_password', 'icon_new')">
              <i class="fas fa-eye" id="icon_new"></i>
            </button>
          </div>
        </div>

        <!-- ยืนยันรหัสผ่านใหม่ -->
        <div class="mb-4">
          <label for="confirm_password" class="form-label">ยืนยันรหัสผ่านใหม่</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-check-double"></i></span>
            <input type="password" 
                   class="form-control" 
                   id="confirm_password" 
                   name="confirm_password" 
                   placeholder="กรอกรหัสผ่านใหม่อีกครั้ง" 
                   required>
            <button class="btn toggle-btn" type="button" onclick="togglePass('confirm_password', 'icon_confirm')">
              <i class="fas fa-eye" id="icon_confirm"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-submit w-100 mb-2">
          <i class="fas fa-save me-2"></i>บันทึกรหัสผ่านใหม่
        </button>
      </form>
      <?php endif; ?>

    </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function togglePass(inputId, iconId) {
      const input = document.getElementById(inputId);
      const icon = document.getElementById(iconId);
      if (input && icon) {
        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
        input.setAttribute('type', type);
        icon.classList.toggle('fa-eye');
        icon.classList.toggle('fa-eye-slash');
      }
    }
  </script>
</body>
</html>
