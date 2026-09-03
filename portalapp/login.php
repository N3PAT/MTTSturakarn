<?php
// 1. ตั้งค่าความปลอดภัยของ Session ขั้นสูง (ทำงานก่อน session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

// 2. ปิดการแสดง Error บนหน้าเว็บจริง
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

if (isset($_SESSION['student_code'])) {
    header("Location: main.php");
    exit();
}

$error = '';
$student_code_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_code_input = trim($_POST['student_code'] ?? '');
    $password = trim($_POST['password'] ?? ''); 

    if (!empty($student_code_input) && !empty($password)) {
        try {
            if (!isset($conn) || !($conn instanceof mysqli)) {
                throw new Exception("Database connection error");
            }

            $conn->set_charset("utf8mb4");
            $stmt = $conn->prepare("SELECT * FROM students WHERE student_code = ? LIMIT 1");
            if (!$stmt) throw new Exception("Prepare failed"); 

            $stmt->bind_param("s", $student_code_input);
            $stmt->execute();
            $result = $stmt->get_result();
            $student = $result->fetch_assoc();
            $stmt->close();

            if ($student) {
                $db_password = trim($student['password'] ?? '');
                $db_id_card = trim($student['id_card'] ?? '');
                $is_password_correct = false;

                if (password_verify($password, $db_password)) {
                    $is_password_correct = true;
                } elseif (!empty($db_id_card) && $password === $db_id_card) {
                    $is_password_correct = true;
                }

                if ($is_password_correct) {
                    session_regenerate_id(true);

                    $_SESSION['student_code'] = $student['student_code'];
                    $_SESSION['student_name'] = trim(($student['prefix'] ?? '') . ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
                    $_SESSION['role'] = 'นักเรียน';
                    $_SESSION['image'] = $student['image'] ?? 'default_student.png';
                    $_SESSION['platoon'] = $student['platoon'] ?? '-';
                    $_SESSION['company'] = $student['company'] ?? '-';
                    $_SESSION['battalion'] = $student['battalion'] ?? '-';
                    $_SESSION['section'] = $student['section'] ?? '-';
                    $_SESSION['id_card'] = $db_id_card;

                    header("Location: main.php");
                    exit();
                } else {
                    $error = 'รหัสประจำตัวหรือรหัสผ่านไม่ถูกต้อง';
                }
            } else {
                $error = 'รหัสประจำตัวหรือรหัสผ่านไม่ถูกต้อง';
            }
        } catch (Throwable $e) {
            error_log('Login Error: ' . $e->getMessage());
            $error = 'เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่อีกครั้ง';
        }
    } else {
        $error = 'กรุณากรอกรหัสประจำตัวและรหัสผ่านให้ครบถ้วน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <title>เข้าสู่ระบบนักเรียน - MTTS</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="manifest.json" />
  <meta name="theme-color" content="#503896" />
  <link rel="apple-touch-icon" href="icon-192x192.png" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="MTTS App" />

  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    /* CSS เดิมทั้งหมดของคุณ */
    :root {
      --primary-color: #503896;
      --text-dark: #1f2937;
      --text-gray: #6b7280;
      --border-color: #e5e7eb;
      --btn-inactive-bg: #e5e7eb;
      --btn-inactive-text: #9ca3af;
      --bg-color: #f3f4f6;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: var(--bg-color); margin: 0; padding: 0; display: flex; justify-content: center; min-height: 100vh; user-select: none; }
    .mobile-container { width: 100%; max-width: 430px; background-color: #ffffff; position: relative; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.05); display: flex; flex-direction: column; }
    .top-background { height: 16vh; min-height: 170px; background: url('https://taothetutor.wordpress.com/wp-content/uploads/2026/08/wp-1788171118011623217780448241186.jpg') center top / cover no-repeat; position: relative; z-index: 1; }
    .top-background::after { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(to bottom, rgba(0,0,0,0.1), rgba(0,0,0,0.4)); }
    .login-panel { flex-grow: 1; background-color: #ffffff; border-radius: 35px 35px 0 0; position: relative; z-index: 2; margin-top: -40px; padding: 30px 25px 20px; display: flex; flex-direction: column; align-items: center; animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes slideUp { 0% { transform: translateY(50px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
    .school-logo { width: 100px; height: 100px; object-fit: contain; margin-bottom: 15px; background-image: url('https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg?utm_source=th.wikipedia.org&utm_campaign=index&utm_content=thumbnail_unscaled'); background-size: contain; background-position: center; background-repeat: no-repeat; }
    .title { font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin: 0 0 5px 0; letter-spacing: -0.5px; }
    .subtitle { font-size: 0.95rem; color: var(--text-gray); margin: 0 0 35px 0; }
    .login-form { width: 100%; margin-bottom: auto; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 0.9rem; font-weight: 500; color: var(--text-dark); margin-bottom: 8px; }
    .input-wrapper { position: relative; }
    .form-control { width: 100%; height: 52px; padding: 0 16px; border: 1.5px solid var(--border-color); border-radius: 14px; font-size: 1rem; color: var(--text-dark); font-family: 'Prompt', sans-serif; outline: none; transition: border-color 0.2s ease; }
    .form-control::placeholder { color: #9ca3af; font-weight: 400; }
    .form-control:focus { border-color: var(--primary-color); }
    .toggle-password { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #6b7280; font-size: 1.2rem; background: none; border: none; padding: 0; cursor: pointer; }
    .btn-submit { width: 100%; height: 54px; background-color: var(--btn-inactive-bg); color: var(--btn-inactive-text); border: none; border-radius: 27px; font-size: 1.05rem; font-weight: 600; font-family: 'Prompt', sans-serif; margin-top: 15px; cursor: pointer; transition: all 0.3s ease; }
    .btn-submit.active { background-color: var(--primary-color); color: #ffffff; box-shadow: 0 4px 15px rgba(80, 56, 150, 0.3); }
    .btn-submit.active:active { transform: scale(0.98); }
    .alert-error { background-color: #fef2f2; border: 1px solid #fecaca; color: #ef4444; padding: 12px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 20px; text-align: center; }
    .footer { text-align: center; margin-top: 40px; font-size: 0.75rem; color: var(--text-gray); line-height: 1.6; }
    .footer a { color: var(--primary-color); text-decoration: none; font-weight: 500; }
    .version { margin-top: 15px; font-size: 0.7rem; color: #9ca3af; }

    /* CSS สำหรับ PWA Install Banner */
    .pwa-banner {
      position: fixed;
      bottom: -100px; /* ซ่อนไว้ก่อน */
      left: 50%;
      transform: translateX(-50%);
      width: 90%;
      max-width: 400px;
      background-color: #ffffff;
      box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
      border-radius: 16px;
      padding: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      transition: bottom 0.5s cubic-bezier(0.16, 1, 0.3, 1);
      z-index: 1000;
    }
    
    .pwa-banner.show {
      bottom: 20px; /* แสดงขึ้นมา */
    }

    .pwa-content {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .pwa-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      object-fit: cover;
    }

    .pwa-text {
      display: flex;
      flex-direction: column;
    }

    .pwa-title {
      font-weight: 600;
      font-size: 0.95rem;
      color: var(--text-dark);
      margin: 0;
    }

    .pwa-subtitle {
      font-size: 0.8rem;
      color: var(--text-gray);
      margin: 0;
    }

    .pwa-actions {
      display: flex;
      gap: 8px;
    }

    .btn-pwa-install {
      background-color: var(--primary-color);
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 20px;
      font-family: 'Prompt', sans-serif;
      font-weight: 600;
      font-size: 0.85rem;
      cursor: pointer;
    }

    .btn-pwa-close {
      background: none;
      border: none;
      color: var(--text-gray);
      font-size: 1.2rem;
      cursor: pointer;
      padding: 4px;
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    <div class="top-background"></div>
    <div class="login-panel">
      <div class="school-logo"></div>
      <h1 class="title">ระบบนักเรียน รุ่น 67</h1>

      <form method="POST" action="" class="login-form">
        <?php if (!empty($error)): ?>
          <div class="alert-error">
            <i class="fas fa-exclamation-circle me-1"></i> <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label" for="student_code">รหัสประจำตัว</label>
          <div class="input-wrapper">
            <input type="text" class="form-control" id="student_code" name="student_code" placeholder="รหัสประจำตัว" value="<?= htmlspecialchars($student_code_input) ?>" inputmode="numeric" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">เลขบัตรประจำตัวประชาชน</label>
          <div class="input-wrapper">
            <input type="password" class="form-control" id="password" name="password" placeholder="เลขบัตร 13 หลัก" required>
            <button type="button" class="toggle-password" id="togglePasswordBtn">
              <i class="fas fa-eye" id="toggleIcon"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn-submit" id="submitBtn">เข้าสู่ระบบ</button>
      </form>

      <div class="footer">
        <div class="version">
          &copy; 2026 กระทิงมึน Production<br>
          เวอร์ชัน 1.0.0
        </div>
      </div>
    </div>
  </div>

  <!-- PWA Install Banner -->
  <div class="pwa-banner" id="pwaBanner">
    <div class="pwa-content">
      <img src="icon-192x192.png" alt="App Icon" class="pwa-icon" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg?utm_source=th.wikipedia.org&utm_campaign=index&utm_content=thumbnail_unscaled'">
      <div class="pwa-text">
        <p class="pwa-title">ติดตั้ง MTTS App</p>
        <p class="pwa-subtitle">เพื่อการใช้งานที่รวดเร็วยิ่งขึ้น</p>
      </div>
    </div>
    <div class="pwa-actions">
      <button class="btn-pwa-install" id="btnInstallPwa">ติดตั้ง</button>
      <button class="btn-pwa-close" id="btnClosePwa"><i class="fas fa-times"></i></button>
    </div>
  </div>

  <script>
    // สคริปต์ฟังก์ชันแสดง/ซ่อนรหัสผ่าน
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');

    togglePasswordBtn.addEventListener('click', function () {
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      
      if (type === 'text') {
        toggleIcon.classList.remove('fa-eye');
        toggleIcon.classList.add('fa-eye-slash');
      } else {
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
      }
    });

    const studentCodeInput = document.getElementById('student_code');
    const submitBtn = document.getElementById('submitBtn');

    function checkInputs() {
      if (studentCodeInput.value.trim() !== '' && passwordInput.value.trim() !== '') {
        submitBtn.classList.add('active');
      } else {
        submitBtn.classList.remove('active');
      }
    }

    studentCodeInput.addEventListener('input', checkInputs);
    passwordInput.addEventListener('input', checkInputs);
    window.addEventListener('DOMContentLoaded', checkInputs);

    // ลงทะเบียน Service Worker
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
          .then(registration => {
            console.log('ServiceWorker registered successfully');
          })
          .catch(error => {
            console.log('ServiceWorker registration failed: ', error);
          });
      });
    }

    // จัดการ PWA Installation
    let deferredPrompt;
    const pwaBanner = document.getElementById('pwaBanner');
    const btnInstallPwa = document.getElementById('btnInstallPwa');
    const btnClosePwa = document.getElementById('btnClosePwa');

    // ตรวจจับเหตุการณ์เมื่อเบราว์เซอร์พร้อมให้ติดตั้ง PWA
    window.addEventListener('beforeinstallprompt', (e) => {
      // ป้องกันไม่ให้ Chrome แสดง mini-infobar อัตโนมัติ (บางเวอร์ชัน)
      e.preventDefault();
      // เก็บ event ไว้ใช้เมื่อผู้ใช้กดปุ่ม
      deferredPrompt = e;
      
      // ตรวจสอบว่าผู้ใช้เคยปิด banner ไปแล้วหรือไม่ใน session นี้
      if (!sessionStorage.getItem('pwaBannerDismissed')) {
        // เลื่อน banner ขึ้นมาแสดง
        setTimeout(() => {
          pwaBanner.classList.add('show');
        }, 1500); // ดีเลย์ 1.5 วินาทีเพื่อให้หน้าเว็บโหลดเสร็จก่อน
      }
    });

    // เมื่อผู้ใช้กดปุ่ม "ติดตั้ง"
    btnInstallPwa.addEventListener('click', async () => {
      if (deferredPrompt) {
        // ซ่อน banner ทันที
        pwaBanner.classList.remove('show');
        
        // แสดง dialog ติดตั้งของระบบ
        deferredPrompt.prompt();
        
        // รอรับผลลัพธ์ว่าผู้ใช้กดยอมรับหรือปฏิเสธ
        const { outcome } = await deferredPrompt.userChoice;
        console.log(`User response to the install prompt: ${outcome}`);
        
        // ล้างค่า event เพราะใช้ได้แค่ครั้งเดียว
        deferredPrompt = null;
      }
    });

    // เมื่อผู้ใช้กดปุ่ม "ปิด" (กากบาท)
    btnClosePwa.addEventListener('click', () => {
      pwaBanner.classList.remove('show');
      // บันทึกไว้ใน sessionStorage เพื่อไม่ให้แสดงอีกจนกว่าจะปิดเบราว์เซอร์
      sessionStorage.setItem('pwaBannerDismissed', 'true');
    });
    
    // ตรวจจับว่าแอปถูกติดตั้งและเปิดใช้งานผ่านโหมด PWA (Standalone) สำเร็จแล้ว
    window.addEventListener('appinstalled', () => {
      console.log('PWA was installed successfully');
      pwaBanner.classList.remove('show');
    });
  </script>
</body>
</html>
