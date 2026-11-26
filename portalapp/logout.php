<?php
session_start();

// ล้างค่าตัวแปรใน Session ทั้งหมด
$_SESSION = array();

// ทำลาย Cookie ของ Session ถ้ามี
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// ทำลาย Session
session_destroy();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#503896" />
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>กำลังออกจากระบบ - MTTS</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet"/>

  <style>
    :root {
      --primary: #503896;
      --primary-dark: #3d2b78;
      --st: env(safe-area-inset-top, 0px);
      --sb: env(safe-area-inset-bottom, 0px);
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; }

    body {
      font-family: 'Prompt', sans-serif;
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 18px;
      padding: calc(24px + var(--st)) 24px calc(24px + var(--sb));
      background: linear-gradient(160deg, var(--primary), var(--primary-dark));
      color: #fff;
      text-align: center;
      -webkit-user-select: none;
      user-select: none;
      -webkit-font-smoothing: antialiased;
    }

    .logo {
      width: 88px;
      height: 88px;
      padding: 6px;
      border-radius: 22px;
      background: #fff;
      object-fit: contain;
      animation: pulse 1.6s ease-in-out infinite;
    }

    .spinner {
      width: 34px;
      height: 34px;
      border: 4px solid rgba(255,255,255,.25);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    h1 { font-size: 1.15rem; font-weight: 600; }
    p  { max-width: 280px; font-size: .85rem; line-height: 1.5; opacity: .8; }

    .back-link {
      margin-top: 6px;
      padding: 10px 22px;
      border-radius: 999px;
      background: rgba(255,255,255,.16);
      color: #fff;
      font-size: .85rem;
      font-weight: 500;
      text-decoration: none;
      transition: transform .12s;
    }
    .back-link:active { transform: scale(.96); }

    @keyframes spin  { to { transform: rotate(360deg); } }
    @keyframes pulse { 50% { transform: scale(1.05); } }

    @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
  </style>

  <meta http-equiv="refresh" content="1.5;url=login.php">
</head>
<body>

  <img class="logo" src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="MTTS">
  <div class="spinner" aria-hidden="true"></div>
  <h1>กำลังออกจากระบบ</h1>
  <p>กรุณารอสักครู่ ระบบกำลังพาคุณกลับหน้าเข้าสู่ระบบ...</p>
  <a href="login.php" class="back-link">ไปหน้าเข้าสู่ระบบทันที</a>

  <script>
    // สั่งการสำรองเปลี่ยนหน้าผ่าน JavaScript ทันทีใน 1.5 วินาที
    setTimeout(function() {
      window.location.href = 'login.php';
    }, 1500);
  </script>
</body>
</html>
