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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>กำลังออกจากระบบ - MTTS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary-color: #4361ee;
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
    }

    @keyframes fadeInDown {
      0% { opacity: 0; transform: translateY(-20px); }
      100% { opacity: 1; transform: translateY(0); }
    }

    .logout-card {
      background: var(--card-bg);
      border-radius: 28px;
      padding: 40px 30px;
      box-shadow: 0 15px 35px rgba(67, 97, 238, 0.08);
      text-align: center;
      max-width: 380px;
      width: 100%;
      animation: fadeInDown 0.5s ease-out forwards;
    }

    .spinner-box {
      width: 70px;
      height: 70px;
      background: var(--primary-light);
      color: var(--primary-color);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      font-size: 1.8rem;
    }

    h4 {
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 8px;
    }

    p {
      color: #64748b;
      font-size: 0.9rem;
      margin-bottom: 0;
    }
  </style>

  <meta http-equiv="refresh" content="1.5;url=login.php">
</head>
<body>

  <div class="logout-card">
    <div class="spinner-box">
      <i class="fas fa-spinner fa-spin"></i>
    </div>
    <h4>กำลังออกจากระบบ</h4>
    <p>กรุณารอสักครู่ ระบบกำลังพาคุณกลับหน้าเข้าสู่ระบบ...</p>
  </div>

  <script>
    // สั่งการสำรองเปลี่ยนหน้าผ่าน JavaScript ทันทีใน 1.5 วินาที
    setTimeout(function() {
      window.location.href = 'login.php';
    }, 1500);
  </script>
</body>
</html>