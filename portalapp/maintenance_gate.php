<?php
/**
 * maintenance_gate.php — โหมดปิดปรับปรุงระบบ (ใช้ร่วมกันทุกหน้า)
 *
 * วิธีใช้ (ใส่หลัง require_once 'db.php'):
 *     require_once 'maintenance_gate.php';
 *     mtts_maintenance_gate($conn);          // ถ้าปิดปรับปรุง จะแสดงหน้าปิดปรับปรุงแล้วหยุดทันที
 * และก่อน </body> (ถ้าต้องการให้หน้าสลับโหมดเองแบบ real-time):
 *     <?php mtts_maintenance_watch_js(); ?>
 *
 * เงื่อนไขเดียวกับ login.php: system_settings.maintenance_mode เป็น on/1/true/close/closed
 */

const MTTS_FORCE_MAINTENANCE = false; // true = บังคับปิดปรับปรุงทุกหน้าที่ใช้ gate นี้

function mtts_is_maintenance(?mysqli $conn): bool
{
    if (MTTS_FORCE_MAINTENANCE) return true;
    $mode = 'off';
    try {
        if ($conn instanceof mysqli) {
            $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_name = 'maintenance_mode' LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) {
                $mode = strtolower(trim((string)$row['setting_value']));
            }
        }
    } catch (Throwable $e) {
        error_log('maintenance gate: ' . $e->getMessage());
    }
    return in_array($mode, ['on', '1', 'true', 'close', 'closed'], true);
}

function mtts_maintenance_gate(?mysqli $conn): void
{
    $on = mtts_is_maintenance($conn);

    if (isset($_GET['check_status'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['is_maintenance' => $on]);
        exit();
    }
    if (!$on) return;

    while (ob_get_level()) { ob_end_clean(); }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'ระบบปิดปรับปรุงชั่วคราว']);
        exit();
    }

    http_response_code(503);
    header('Retry-After: 600');
    mtts_render_maintenance_page();
    exit();
}

/** สคริปต์เช็กทุก 30 วินาที: ถ้าถูกสั่งปิดปรับปรุงระหว่างใช้งาน ให้รีเฟรชเป็นหน้าปิดปรับปรุง */
function mtts_maintenance_watch_js(): void
{
    echo <<<'HTML'
<script>
setInterval(function () {
  fetch('?check_status=1&_t=' + Date.now(), { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d.is_maintenance) location.reload(); })
    .catch(function () {});
}, 30000);
</script>
HTML;
}

function mtts_render_maintenance_page(): void
{
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#d97706">
  <title>ปิดปรับปรุงระบบ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--primary-color:#d97706;--accent-yellow:#f59e0b;--text-dark:#78350f;--text-gray:#b45309;--bg-color:#fefce8}
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
    body{font-family:'Prompt',sans-serif;background:#fef9c3;margin:0;display:flex;justify-content:center;min-height:100vh;user-select:none}
    .mobile-container{width:100%;max-width:430px;background:var(--bg-color);position:relative;overflow:hidden;box-shadow:0 0 20px rgba(217,119,6,.15);display:flex;flex-direction:column}
    .top-background{height:18vh;min-height:180px;background:linear-gradient(135deg,#f59e0b,#d97706);position:relative;z-index:1}
    .top-background::after{content:'';position:absolute;inset:0;background:url('https://taothetutor.wordpress.com/wp-content/uploads/2026/08/wp-1788171118011623217780448241186.jpg') center top/cover no-repeat;opacity:.2}
    .login-panel{flex-grow:1;background:#fff;border-radius:35px 35px 0 0;position:relative;z-index:2;margin-top:-35px;padding:30px 25px 20px;display:flex;flex-direction:column;align-items:center;animation:slideUp .6s cubic-bezier(.16,1,.3,1) forwards}
    @keyframes slideUp{0%{transform:translateY(40px);opacity:0}100%{transform:none;opacity:1}}
    .icon-wrapper{position:relative;margin:10px 0 20px}
    .animated-icon{width:95px;height:95px;background:linear-gradient(135deg,#fef3c7,#fde68a);color:var(--primary-color);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2.8rem;box-shadow:0 10px 25px rgba(245,158,11,.25);animation:floatBounce 3s ease-in-out infinite;border:4px solid #fff}
    @keyframes floatBounce{0%,100%{transform:translateY(0) rotate(0)}50%{transform:translateY(-12px) rotate(8deg)}}
    .icon-badge{position:absolute;bottom:-2px;right:-2px;background:var(--accent-yellow);color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;border:3px solid #fff}
    .title{font-size:1.4rem;font-weight:700;color:var(--text-dark);margin:5px 0;text-align:center}
    .subtitle{font-size:.9rem;color:var(--text-gray);margin:0 0 20px;text-align:center;line-height:1.5}
    .maintenance-card{width:100%;background:#fefce8;border:1.5px dashed #fde047;border-radius:18px;padding:16px;margin-bottom:20px;display:flex;flex-direction:column;gap:10px}
    .info-item{display:flex;align-items:center;gap:12px;font-size:.88rem;color:var(--text-dark)}
    .info-item i{color:var(--primary-color);font-size:1.1rem;width:20px;text-align:center}
    .btn-reload{width:100%;height:52px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:26px;font-size:1rem;font-weight:600;font-family:'Prompt',sans-serif;cursor:pointer;box-shadow:0 4px 15px rgba(217,119,6,.3);display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}
    .btn-reload:active{transform:scale(.97)}
    .footer{text-align:center;margin-top:auto;padding-top:20px;font-size:.75rem;color:#a16207;line-height:1.6}
  </style>
</head>
<body>
  <div class="mobile-container">
    <div class="top-background"></div>
    <div class="login-panel">
      <div class="icon-wrapper">
        <div class="animated-icon"><i class="fas fa-tools"></i></div>
        <div class="icon-badge"><i class="fas fa-sync-alt fa-spin"></i></div>
      </div>
      <h1 class="title">ปิดปรับปรุงระบบชั่วคราว</h1>
      <p class="subtitle">ระบบกำลังอยู่ในระหว่างการพัฒนาและปรับปรุงประสิทธิภาพ เพื่อการใช้งานที่ดีขึ้น</p>
      <div class="maintenance-card">
        <div class="info-item"><i class="fas fa-exclamation-circle"></i><span>ระงับการใช้งานระบบชั่วคราว</span></div>
        <div class="info-item"><i class="fas fa-clock"></i><span>คาดว่าจะเปิดให้บริการเร็วๆ นี้</span></div>
        <div class="info-item"><i class="fas fa-shield-alt"></i><span>ข้อมูลทั้งหมดของคุณยังคงปลอดภัย</span></div>
      </div>
      <a href="" class="btn-reload" onclick="location.reload(); return false;"><i class="fas fa-redo-alt"></i> ลองใหม่อีกครั้ง</a>
      <div class="footer">&copy; 2026 กระทิงมึน Production<br>ระบบนักเรียน MTTS (Maintenance Mode)</div>
    </div>
  </div>
  <script>
    setInterval(function () {
      fetch('?check_status=1&_t=' + Date.now(), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d.is_maintenance) location.reload(); })
        .catch(function () {});
    }, 30000);
  </script>
</body>
</html>
<?php
}
