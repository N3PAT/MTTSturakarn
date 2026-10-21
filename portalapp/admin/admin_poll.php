<?php
date_default_timezone_set('Asia/Bangkok');
require_once 'db.php';
require_once 'functions.php';

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// จัดการการบันทึกตั้งค่า
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        die('CSRF Token Invalid');
    }

    $target_date = trim((string)($_POST['target_date'] ?? ''));
    $is_open     = isset($_POST['is_open']) ? '1' : '0';

    if ($target_date !== '') {
        set_system_setting($conn, 'target_date', $target_date);
    }
    set_system_setting($conn, 'is_open', $is_open);

    $_SESSION['flash_msg'] = 'บันทึกการตั้งค่าประกาศโพลเรียบร้อยแล้ว';
    header('Location: admin.php');
    exit;
}

$window       = vote_window($conn);
$target_date  = $window['target_date'];
$is_open      = $window['is_open'];
$date_th_full = thai_date_full($target_date);

$flash = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>จัดการระบบประกาศโพล - MTTS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{ --primary:#5a42a0; --primary-dark:#473282; --ink:#1e293b; --mute:#64748b; --paper:#f6f8fc; --line:#dbe1ea; --ok:#15803d; --ok-bg:#f0fdf4; }
*{box-sizing:border-box}
body{margin:0;font-family:'Prompt',sans-serif;background:#e2e8f0;color:var(--ink);display:flex;justify-content:center;min-height:100vh}
.app{width:100%;max-width:430px;background:var(--paper);min-height:100vh;padding-bottom:96px;box-shadow:0 0 24px rgba(15,23,42,.08)}
.top{display:flex;align-items:center;gap:12px;padding:16px 20px;background:#fff;border-bottom:1px solid var(--line)}
.logo{width:44px;height:44px;border-radius:10px;object-fit:contain}
.top h1{margin:0;font-size:.95rem;font-weight:700}
.top p{margin:0;font-size:.75rem;color:var(--mute)}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;margin:20px;padding:20px}
.field{margin-bottom:18px}
.field label{display:block;font-size:.85rem;font-weight:600;margin-bottom:8px}
.input{width:100%;padding:12px;border:1.5px solid var(--line);border-radius:10px;font:inherit;font-size:1rem}
.checkbox-field{display:flex;align-items:center;gap:10px;background:#f8fafc;padding:12px;border-radius:10px;border:1px solid var(--line);cursor:pointer}
.checkbox-field input{width:20px;height:20px}
.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:12px;padding:13px 18px;font:inherit;font-size:1rem;font-weight:600;cursor:pointer;background:var(--primary);color:#fff;width:100%}
.alert{padding:12px;border-radius:10px;background:var(--ok-bg);color:var(--ok);font-size:.85rem;margin-bottom:16px;font-weight:600}
.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;padding:12px;border-radius:10px;font-size:.8rem;margin-top:16px}
.nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:430px;height:68px;background:#fff;border-radius:22px 22px 0 0;box-shadow:0 -4px 18px rgba(15,23,42,.06);display:flex;justify-content:space-around;align-items:center}
.nav a{display:flex;flex-direction:column;align-items:center;text-decoration:none;color:#94a3b8;font-size:.75rem;font-weight:600}
.nav a.on{color:var(--primary)}
</style>
</head>
<body>
<div class="app">
  <header class="top">
    <img class="logo" src="mtts_logo.jpg" onerror="this.onerror=null;this.src='https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg'" alt="">
    <div>
      <h1>ระบบหลังบ้าน Admin</h1>
      <p>กำหนดประกาศโพลแจ้งวันกลับ</p>
    </div>
  </header>

  <div class="card">
    <?php if ($flash): ?>
      <div class="alert"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

      <div class="field">
        <label for="target_date">กำหนดวันกลับเป้าหมาย (วันศุกร์)</label>
        <input class="input" type="date" id="target_date" name="target_date" value="<?php echo htmlspecialchars($target_date); ?>" required>
      </div>

      <div class="field">
        <label class="checkbox-field">
          <input type="checkbox" name="is_open" value="1" <?php echo $is_open ? 'checked' : ''; ?>>
          <span>เปิดรับข้อมูลแจ้งความประสงค์</span>
        </label>
      </div>

      <button type="submit" class="btn">บันทึกตั้งค่าประกาศโพล</button>
    </form>

    <div class="info">
      <strong>สถานะปัจจุบัน:</strong><br>
      วันกลับที่กำหนด: <strong><?php echo htmlspecialchars($date_th_full); ?></strong><br>
      สถานะโพล: <strong><?php echo $is_open ? 'เปิดใช้งาน' : 'ปิดใช้งาน'; ?></strong>
    </div>
  </div>

  <nav class="nav">
    <a href="fill.php"><span>หน้าโหวต</span></a>
    <a href="admin.php" class="on"><span>ตั้งค่า Admin</span></a>
  </nav>
</div>
</body>
</html>
