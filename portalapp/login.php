<?php
// 1. ตั้งค่าความปลอดภัยและอายุของ Session
$session_lifetime = 2592000; // 30 วัน
ini_set('session.gc_maxlifetime', $session_lifetime);
ini_set('session.cookie_lifetime', $session_lifetime);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 1);

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
require_once 'db.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <title>ปิดปรับปรุงระบบ - MTTS</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="manifest.json" />
  <meta name="theme-color" content="#d97706" />
  <link rel="apple-touch-icon" href="icon-192x192.png" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="MTTS App" />

  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --primary-color: #d97706; /* สีส้มอมเหลือง */
      --primary-light: #fef3c7; /* สีเหลืองพาสเทลอ่อน */
      --accent-yellow: #f59e0b; /* สีเหลืองทอง */
      --text-dark: #78350f;     /* สีน้ำตาลเข้ม */
      --text-gray: #b45309;     /* สีน้ำตาลส้ม */
      --bg-color: #fefce8;      /* พื้นหลังโทนเหลืองนวล */
    }
    
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    
    body { 
      font-family: 'Prompt', sans-serif; 
      background-color: #fef9c3; 
      margin: 0; 
      padding: 0; 
      display: flex; 
      justify-content: center; 
      min-height: 100vh; 
      user-select: none; 
    }

    .mobile-container { 
      width: 100%; 
      max-width: 430px; 
      background-color: var(--bg-color); 
      position: relative; 
      overflow: hidden; 
      box-shadow: 0 0 20px rgba(217, 119, 6, 0.15); 
      display: flex; 
      flex-direction: column; 
    }

    .top-background { 
      height: 18vh; 
      min-height: 180px; 
      background: linear-gradient(135deg, #f59e0b, #d97706); 
      position: relative; 
      z-index: 1; 
      display: flex;
      justify-content: center;
      align-items: center;
    }
    
    .top-background::after { 
      content: ''; 
      position: absolute; 
      top: 0; left: 0; right: 0; bottom: 0; 
      background: url('https://taothetutor.wordpress.com/wp-content/uploads/2026/08/wp-1788171118011623217780448241186.jpg') center top / cover no-repeat; 
      opacity: 0.2;
    }

    .login-panel { 
      flex-grow: 1; 
      background-color: #ffffff; 
      border-radius: 35px 35px 0 0; 
      position: relative; 
      z-index: 2; 
      margin-top: -35px; 
      padding: 30px 25px 20px; 
      display: flex; 
      flex-direction: column; 
      align-items: center; 
      animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; 
    }

    @keyframes slideUp { 
      0% { transform: translateY(40px); opacity: 0; } 
      100% { transform: translateY(0); opacity: 1; } 
    }

    /* กล่องไอคอนพร้อมแอนิเมชันขยับดุ๊กดิ๊ก */
    .icon-wrapper {
      position: relative;
      margin: 10px 0 20px;
    }

    .animated-icon {
      width: 95px;
      height: 95px;
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      color: var(--primary-color);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2.8rem;
      box-shadow: 0 10px 25px rgba(245, 158, 11, 0.25);
      animation: floatBounce 3s ease-in-out infinite;
      border: 4px solid #ffffff;
    }

    /* แอนิเมชันลอยดุ๊กดิ๊ก */
    @keyframes floatBounce {
      0%, 100% { transform: translateY(0px) rotate(0deg); }
      50% { transform: translateY(-12px) rotate(8deg); }
    }

    /* ไอคอนดาวดุ๊กดิ๊กตกแต่ง */
    .icon-badge {
      position: absolute;
      bottom: -2px;
      right: -2px;
      background: var(--accent-yellow);
      color: white;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.9rem;
      border: 3px solid white;
      animation: pulseGlow 2s infinite;
    }

    @keyframes pulseGlow {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.15); }
    }

    .title { 
      font-size: 1.4rem; 
      font-weight: 700; 
      color: var(--text-dark); 
      margin: 5px 0; 
      text-align: center;
    }

    .subtitle { 
      font-size: 0.9rem; 
      color: var(--text-gray); 
      margin: 0 0 20px 0; 
      text-align: center;
      line-height: 1.5;
    }

    /* การ์ดข้อความแจ้งเตือนล่างไอคอน */
    .maintenance-card {
      width: 100%;
      background: #fefce8;
      border: 1.5px dashed #fde047;
      border-radius: 18px;
      padding: 16px;
      margin-bottom: 20px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .info-item {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.88rem;
      color: var(--text-dark);
    }

    .info-item i {
      color: var(--primary-color);
      font-size: 1.1rem;
      width: 20px;
      text-align: center;
    }

    .btn-reload { 
      width: 100%; 
      height: 52px; 
      background: linear-gradient(135deg, #f59e0b, #d97706); 
      color: #ffffff; 
      border: none; 
      border-radius: 26px; 
      font-size: 1rem; 
      font-weight: 600; 
      font-family: 'Prompt', sans-serif; 
      cursor: pointer; 
      transition: all 0.3s ease; 
      box-shadow: 0 4px 15px rgba(217, 119, 6, 0.3);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      text-decoration: none;
    }

    .btn-reload:active { 
      transform: scale(0.97); 
    }

    .footer { 
      text-align: center; 
      margin-top: auto; 
      padding-top: 20px;
      font-size: 0.75rem; 
      color: #a16207; 
      line-height: 1.6; 
    }

    .version { 
      font-size: 0.7rem; 
      color: #ca8a04; 
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    <div class="top-background"></div>
    <div class="login-panel">
      
      <!-- ไอคอนเครื่องมือขยับดุ๊กดิ๊ก -->
      <div class="icon-wrapper">
        <div class="animated-icon">
          <i class="fas fa-tools"></i>
        </div>
        <div class="icon-badge">
          <i class="fas fa-sync-alt fa-spin"></i>
        </div>
      </div>

      <h1 class="title">ปิดปรับปรุงระบบชั่วคราว</h1>
      <p class="subtitle">ระบบกำลังอยู่ในระหว่างการพัฒนาและปรับปรุงประสิทธิภาพ เพื่อการใช้งานที่ดีขึ้น</p>

      <!-- ข้อความรายละเอียดล่างไอคอน -->
      <div class="maintenance-card">
        <div class="info-item">
          <i class="fas fa-exclamation-circle"></i>
          <span>ระงับการเข้าสู่ระบบชั่วคราว</span>
        </div>
        <div class="info-item">
          <i class="fas fa-clock"></i>
          <span>คาดว่าจะเปิดให้บริการเร็วๆ นี้</span>
        </div>
        <div class="info-item">
          <i class="fas fa-shield-alt"></i>
          <span>ข้อมูลทั้งหมดของคุณยังคงปลอดภัย</span>
        </div>
      </div>

      <!-- ปุ่มรีโหลดหน้าเว็บ -->
      <a href="" class="btn-reload" onclick="location.reload(); return false;">
        <i class="fas fa-redo-alt"></i> ลองใหม่อีกครั้ง
      </a>

      <div class="footer">
        <div class="version">
          &copy; 2026 กระทิงมึน Production<br>
          ระบบนักเรียน MTTS (Maintenance Mode)
        </div>
      </div>
    </div>
  </div>
</body>
</html>
