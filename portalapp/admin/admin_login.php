<?php
session_start();
require_once 'db.php';

// ถ้าเข้าระบบอยู่แล้ว ให้เด้งไปหน้าหลักผู้ดูแลเลย
if (!empty($_SESSION['admin_id'])) {
    header("Location: admin_main.php"); // *เปลี่ยนชื่อไฟล์ให้ตรงกับหน้าหลักของคุณ
    exit();
}

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = 'ผู้ดูแลระบบ MTTS'; 
                
                header("Location: admin_main.php"); // *เปลี่ยนชื่อไฟล์ให้ตรงกับหน้าหลักของคุณ
                exit();
            } else {
                $login_error = "ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง";
            }
            $stmt->close();
        }
    } else {
        $login_error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Login - MTTS</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <style>
        :root {
            --primary: #0f172a;
            --primary-light: #1e293b;
            --accent: #3b82f6;
            --bg-color: #f1f5f9;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Prompt', sans-serif; display: flex; justify-content: center; background-color: var(--bg-color); margin: 0; min-height: 100vh; }
        
        .app-container { width: 100%; max-width: 430px; display: flex; flex-direction: column; justify-content: center; padding: 24px; }
        .login-box { background: #ffffff; padding: 2.5rem 2rem; border-radius: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08); position: relative; overflow: hidden; }
        .login-box::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, var(--accent), #60a5fa); }
        
        .logo-box { text-align: center; margin-bottom: 1.5rem; }
        .logo-box img { width: 85px; border-radius: 20px; box-shadow: 0 8px 16px rgba(0,0,0,0.1); }
        .login-box h2 { text-align: center; color: var(--primary); margin: 0 0 0.5rem 0; font-weight: 700; font-size: 1.5rem; }
        .login-box p.subtitle { text-align: center; color: #64748b; font-size: 0.9rem; margin: 0 0 2rem 0; }
        
        .form-group { margin-bottom: 1.5rem; position: relative; }
        .form-group i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.1rem; transition: 0.3s; }
        input[type="text"], input[type="password"] { width: 100%; padding: 1rem 1rem 1rem 45px; border: 2px solid #e2e8f0; border-radius: 14px; font-family: 'Prompt', sans-serif; font-size: 0.95rem; background: #f8fafc; transition: all 0.3s ease; }
        input:focus { outline: none; border-color: var(--accent); background: #ffffff; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        input:focus + i { color: var(--accent); }
        
        button { width: 100%; padding: 1rem; background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border: none; border-radius: 14px; cursor: pointer; font-size: 1.05rem; font-family: 'Prompt', sans-serif; font-weight: 600; transition: all 0.2s; box-shadow: 0 8px 15px rgba(15, 23, 42, 0.2); display: flex; justify-content: center; align-items: center; gap: 8px; }
        button:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.3); }
        button:active { transform: translateY(0); }
        
        .error { color: #dc2626; background: #fef2f2; border-left: 4px solid #dc2626; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; gap: 8px; }
        
        .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="login-box fade-in-up">
            <div class="logo-box">
                <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo">
            </div>
            <h2>เข้าสู่ระบบผู้ดูแล</h2>
            <p class="subtitle">ระบบจัดการหลังบ้าน MTTS</p>
            
            <?php if ($login_error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <input type="text" id="username" name="username" placeholder="ชื่อผู้ใช้งาน (Username)" required>
                    <i class="fas fa-user"></i>
                </div>
                <div class="form-group">
                    <input type="password" id="password" name="password" placeholder="รหัสผ่าน (Password)" required>
                    <i class="fas fa-lock"></i>
                </div>
                <button type="submit">เข้าสู่ระบบ <i class="fas fa-arrow-right"></i></button>
            </form>
        </div>
    </div>
</body>
</html>

