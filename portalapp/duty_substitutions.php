<?php
session_start();

date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'db.php';
require_once __DIR__ . '/duty_core.php'; // ตัวคำนวณเวรกลาง (ข้ามคนลาออก)

function getStudentDutyForDate($conn, $student_code, $duty_date) {
    if (empty($duty_date) or empty($student_code)) {
        return null;
    }

    $start_date = '2026-07-06';
    $location_names = array(
        'จุดที่ 4 อาคารยานยนต์', 
        'จุดที่ 5 ผฟอ.', 
        'จุดที่ 6 กศน.', 
        'จุดที่ 3 โรงรถ บก.หน้า'
    );
    $shift_labels = array('ผลัดที่ 1', 'ผลัดที่ 2', 'ผลัดที่ 3', 'ผลัดที่ 4');

    $eval_ts = strtotime($duty_date);
    $start_ts = strtotime($start_date);
    $diff_days = floor(($eval_ts - $start_ts) / 86400);

    if ($diff_days < 0) {
        return null;
    }

    // ใช้ตัวคำนวณกลาง (ข้ามคนที่ status = 'resigned') ให้ตรงกับหน้าอื่น
    try {
        $codes = duty_codes_for_date($conn, date('Y-m-d', $eval_ts), $start_date, 16);
    } catch (Exception $e) {
        return null;
    }

    foreach ($codes as $i => $code_on_duty) {
        if ($code_on_duty === (string)$student_code) {
            $shift_idx = floor($i / 4);
            $loc_idx = $i % 4;
            return array(
                'shift' => $shift_labels[$shift_idx],
                'location' => $location_names[$loc_idx],
                'full_duty' => $shift_labels[$shift_idx] . " (" . $location_names[$loc_idx] . ")"
            );
        }
    }

    return null;
}

$is_system_open = false;
try {
    $setting_stmt = $conn->query("SELECT setting_value FROM system_settings WHERE setting_name = 'duty_system_status' LIMIT 1");
    if ($setting_row = $setting_stmt->fetch_assoc()) {
        if ($setting_row['setting_value'] === 'open') {
            $is_system_open = true;
        }
    } else {
        $is_system_open = true;
    }
} catch (Exception $e) {
    $is_system_open = true; 
}

if (isset($_GET['action']) and $_GET['action'] == 'get_student_name' and isset($_GET['code'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    
    $code = trim($_GET['code']);
    $date = isset($_GET['date']) ? trim($_GET['date']) : '';

    try {
        $stmt = $conn->prepare("SELECT prefix, first_name, last_name, section FROM students WHERE student_code = ? LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $prefix = isset($row['prefix']) ? $row['prefix'] : '';
            $fname = isset($row['first_name']) ? $row['first_name'] : '';
            $lname = isset($row['last_name']) ? $row['last_name'] : '';
            $fullname = trim($prefix . ' ' . $fname . ' ' . $lname);
            
            $duty_info = null;
            if (!empty($date)) {
                $duty_info = getStudentDutyForDate($conn, $code, $date);
            }

            echo json_encode(array(
                'found' => true, 
                'name' => $fullname,
                'duty_info' => $duty_info ? $duty_info['full_duty'] : '',
                'shift' => $duty_info ? $duty_info['shift'] : '',
                'location' => $duty_info ? $duty_info['location'] : ''
            ));
        } else {
            echo json_encode(array('found' => false, 'name' => '', 'duty_info' => ''));
        }
    } catch (Exception $e) {
        echo json_encode(array('found' => false, 'name' => 'Error: ' . $e->getMessage()));
    }
    exit;
}

$success_msg = "";
$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$is_system_open) {
        $error_msg = 'ระบบปิดรับคำขอแทนเวรชั่วคราว ไม่สามารถทำรายการได้';
    } else {
        $csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        $requester_code = isset($_POST['requester_code']) ? trim($_POST['requester_code']) : ''; 
        $target_code = isset($_POST['target_code']) ? trim($_POST['target_code']) : '';
        $duty_date = isset($_POST['duty_date']) ? trim($_POST['duty_date']) : '';
        $duty_name = isset($_POST['duty_name']) ? trim($_POST['duty_name']) : '';
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

        if (empty($csrf_token) or !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
            $error_msg = 'เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่';
        } else if (empty($requester_code) or empty($target_code) or empty($duty_date) or empty($duty_name)) {
            $error_msg = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
        } else if ($requester_code === $target_code) {
            $error_msg = 'ไม่สามารถขอแทนเวรตัวเองได้';
        } else {
            try {
                $status = 'pending';
                $insert_stmt = $conn->prepare("INSERT INTO duty_swaps (original_student_code, replacement_student_code, duty_date, duty_name, reason, status) VALUES (?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("ssssss", $target_code, $requester_code, $duty_date, $duty_name, $reason, $status);
                
                if ($insert_stmt->execute()) {
                    $notif_title = "คำขอแทนเวรใหม่จากรหัส " . $requester_code;
                    $notif_msg = "นักเรียนรหัส " . $requester_code . " ขอเข้าเวร (" . $duty_name . ") แทนรหัส " . $target_code . " ในวันที่ " . $duty_date . " เหตุผล: " . $reason;
                    $notif_type = "duty";
                    $alert_level = "info";

                    $notif_stmt = $conn->prepare("INSERT INTO notifications (student_code, title, message, type, alert_level) VALUES (NULL, ?, ?, ?, ?)");
                    $notif_stmt->bind_param("ssss", $notif_title, $notif_msg, $notif_type, $alert_level);
                    $notif_stmt->execute();

                    $success_msg = 'ส่งคำขอแทนเวรของรหัส ' . htmlspecialchars($target_code) . ' เรียบร้อยแล้ว รอการอนุมัติจากผู้ดูแลระบบ';
                } else {
                    $error_msg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
                }
            } catch (Exception $e) {
                $error_msg = 'เกิดข้อผิดพลาดของระบบ: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#503896" />
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>แจ้งขอแทนเวร - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <style>
    :root{--primary:#503896;--primary-dark:#3d2b78;--bar:#503896;--bg:#f2f3f8;--ink:#1c1b29;--mute:#6b7280;--line:#eef0f5;--g:16px;--nav:64px;--st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);--shadow:0 1px 2px rgba(20,20,43,.04),0 2px 8px rgba(20,20,43,.04)}

    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;margin:0;padding:0}
    html{-webkit-text-size-adjust:100%}
    body{font-family:'Prompt',sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100vh;min-height:100dvh;overscroll-behavior-y:none;touch-action:manipulation;-webkit-font-smoothing:antialiased}
    input,textarea{-webkit-user-select:text;user-select:text}
    ::-webkit-scrollbar{display:none}

    .fade-in-up{opacity:0;transform:translateY(14px);animation:fadeInUp .45s cubic-bezier(.16,1,.3,1) forwards}
    @keyframes fadeInUp{to{opacity:1;transform:none}}
    .delay-1{animation-delay:.05s}.delay-2{animation-delay:.12s}.delay-3{animation-delay:.2s}
    @media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.fade-in-up{opacity:1;transform:none}}

    .app-container{width:100%;max-width:430px;min-height:100vh;min-height:100dvh;background:var(--bg);padding-bottom:calc(var(--nav) + var(--sb) + 16px)}
    @media(min-width:431px){.app-container{box-shadow:0 0 40px rgba(15,23,42,.14)}}

    /* app bar */
    .top-header{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:calc(10px + var(--st)) var(--g) 10px;background:rgba(80,56,150,.94);-webkit-backdrop-filter:saturate(180%) blur(14px);backdrop-filter:saturate(180%) blur(14px);color:#fff}
    .header-left{display:flex;align-items:center;gap:12px;min-width:0}
    .btn-back{display:flex;align-items:center;justify-content:center;flex:none;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.05rem;text-decoration:none;transition:transform .12s}
    .btn-back:active{transform:scale(.92)}
    .header-title h1{font-size:1.05rem;font-weight:600}
    .status-badge{display:flex;align-items:center;gap:6px;flex:none;padding:4px 11px;border-radius:20px;font-size:.72rem;font-weight:600}
    .status-badge.open{background:#dcfce7;color:#166534}
    .status-badge.closed{background:#fee2e2;color:#991b1b}

    /* section label */
    .section-title{margin:18px calc(var(--g) + 4px) 8px;font-size:.8rem;font-weight:600;letter-spacing:.02em;color:var(--mute)}
    .section-title+.card,.alert-success+.section-title{margin-top:0}

    /* cards */
    .req-form{padding:0}
    .card{margin:0 var(--g) 14px;border-radius:20px;background:#fff;box-shadow:var(--shadow);overflow:hidden}
    .card .form-group{margin:0;padding:14px 16px}
    .card .form-group+.form-group{border-top:1px solid var(--line)}
    .card .form-group.sub{padding-top:0;border-top:0}
    .form-group label{display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:.8rem;font-weight:600;color:var(--mute)}
    .text-danger{color:#ef4444}
    .icon-label{color:var(--primary)}

    .form-control{width:100%;height:50px;padding:0 16px;border:2px solid transparent;border-radius:14px;background:#eef0f6;font-family:inherit;font-size:1rem;color:var(--ink);outline:none;transition:border-color .15s,background .15s}
    .form-control:focus{border-color:var(--primary);background:#fff}
    .input-readonly{background:#f6f7fb;color:var(--mute);font-size:.92rem}
    .input-success{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:500;font-size:.92rem}
    .input-error{background:#fef2f2;border-color:#fecaca;color:#b91c1c;font-size:.92rem}
    textarea.form-control{height:84px;padding:12px 16px;resize:none;line-height:1.5}
    input[type=date].form-control{min-height:50px}

    /* remark */
    .remark-box{display:flex;align-items:flex-start;gap:10px;margin:0 var(--g);padding:12px 14px;border-radius:16px;background:#fffbeb;border:1px solid #fde68a}
    .remark-box i{margin-top:2px;font-size:1.05rem;color:#d97706}
    .remark-box p{font-size:.8rem;line-height:1.5;font-weight:500;color:#92400e}

    /* submit: ลอยค้างเหนือแถบเมนู */
    .btn-submit{position:sticky;bottom:calc(var(--nav) + var(--sb) + 12px);z-index:30;display:flex;align-items:center;justify-content:center;gap:8px;width:calc(100% - 32px);height:52px;margin:16px var(--g) 0;border:0;border-radius:16px;background:var(--primary);color:#fff;font-family:inherit;font-size:1rem;font-weight:600;cursor:pointer;box-shadow:0 6px 16px rgba(80,56,150,.3);transition:transform .1s,background .15s}
    .btn-submit:active{transform:scale(.97);background:var(--primary-dark)}

    /* alerts */
    .alert-success,.alert-error{display:flex;align-items:center;gap:10px;margin:16px var(--g) 0;padding:14px;border-radius:16px;font-size:.88rem;font-weight:500;line-height:1.45}
    .alert-success{background:#dcfce7;color:#166534}
    .alert-error{background:#fee2e2;color:#991b1b}

    /* closed */
    .closed-box{margin:0 var(--g);padding:32px 20px;border-radius:20px;background:#fff;box-shadow:var(--shadow);text-align:center}
    .closed-icon{margin-bottom:12px;font-size:2.6rem;color:#ef4444}
    .closed-title{margin-bottom:6px;font-size:1.05rem;font-weight:600}
    .closed-desc{font-size:.84rem;line-height:1.5;color:var(--mute)}

    /* bottom nav สีม่วง */
    .bottom-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:1000;width:100%;max-width:430px;height:calc(var(--nav) + var(--sb));padding-bottom:var(--sb);display:flex;align-items:center;background:var(--bar);box-shadow:0 -4px 18px rgba(20,20,43,.18)}
    .nav-link{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;color:rgba(255,255,255,.6);font-size:.7rem;font-weight:500;text-decoration:none}
    .nav-link i{display:grid;place-items:center;width:56px;height:30px;border-radius:15px;font-size:1.2rem;transition:background .2s}
    .nav-link.active{color:#fff;font-weight:600}
    .nav-link.active i{background:rgba(255,255,255,.2)}
    .nav-link:active{color:#fff}
  </style>
</head>
<body>

  <div class="app-container">
    
    <!-- Header -->
    <header class="top-header">
      <div class="header-left">
        <a href="main.php" class="btn-back" aria-label="กลับหน้าหลัก"><i class="fas fa-chevron-left"></i></a>
        <div class="header-title">
          <h1>แจ้งขอแทนเวร</h1>
        </div>
      </div>
      <?php if ($is_system_open): ?>
        <div class="status-badge open">
          <i class="fas fa-circle" style="font-size:.45rem;"></i> เปิดระบบ
        </div>
      <?php else: ?>
        <div class="status-badge closed">
          <i class="fas fa-lock"></i> ปิดระบบ
        </div>
      <?php endif; ?>
    </header>

    <main>
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

    <?php if ($is_system_open): ?>
      <!-- Form -->
      <form id="substituteForm" method="POST" action="" class="req-form fade-in-up delay-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="section-title">ผู้ทำหน้าที่แทน</div>
        <div class="card">
          <div class="form-group">
            <label for="requester_code"><i class="fas fa-user-check icon-label"></i> รหัสประจำตัว (ผู้ทำหน้าที่แทน) <span class="text-danger">*</span></label>
            <input type="text" id="requester_code" name="requester_code" required maxlength="10" inputmode="numeric" class="form-control" placeholder="เช่น 151xx">
          </div>
          <div class="form-group sub">
            <input type="text" id="requester_name" readonly tabindex="-1" class="form-control input-readonly" placeholder="ระบบจะแสดงชื่ออัตโนมัติ">
          </div>
        </div>

        <div class="section-title">เวรที่ขอแทน</div>
        <div class="card">
          <div class="form-group">
            <label for="target_code"><i class="fas fa-user-clock icon-label"></i> รหัสประจำตัว (ผู้ที่ถูกแทนเวร) <span class="text-danger">*</span></label>
            <input type="text" id="target_code" name="target_code" required maxlength="10" inputmode="numeric" class="form-control" placeholder="รหัสของเพื่อนที่คุณจะเข้าเวรแทน">
          </div>
          <div class="form-group sub">
            <input type="text" id="target_name" readonly tabindex="-1" class="form-control input-readonly" placeholder="ระบบจะแสดงชื่อเพื่อนอัตโนมัติ">
          </div>

          <div class="form-group">
            <label for="duty_date"><i class="far fa-calendar-alt icon-label"></i> วันที่ต้องเข้าเวร <span class="text-danger">*</span></label>
            <input type="date" id="duty_date" name="duty_date" required class="form-control">
          </div>

          <div class="form-group">
            <label><i class="fas fa-shield-alt icon-label"></i> ผลัดที่ / จุดรักษาการณ์ (ของเจ้าของเวรเดิม)</label>
            <input type="text" id="target_duty_info" readonly tabindex="-1" class="form-control input-readonly" placeholder="ระบบจะคำนวณผลัดและจุดรักษาการณ์อัตโนมัติ">
          </div>
        </div>

        <div class="section-title">รายละเอียด</div>
        <div class="card">
          <div class="form-group">
            <label for="duty_name"><i class="fas fa-clipboard-list icon-label"></i> ชื่อเวร / หน้าที่ <span class="text-danger">*</span></label>
            <input type="text" id="duty_name" name="duty_name" required class="form-control" placeholder="เช่น ผลัดที่ 1 (จุดที่ 4 อาคารยานยนต์)">
          </div>

          <div class="form-group">
            <label for="reason"><i class="fas fa-comment-dots icon-label"></i> เหตุผลการขอแทนเวร</label>
            <textarea id="reason" name="reason" class="form-control" placeholder="ระบุเหตุผลที่ขอทำหน้าที่แทน (ถ้ามี)"></textarea>
          </div>
        </div>

        <!-- หมายเหตุ -->
        <div class="remark-box">
          <i class="fas fa-info-circle"></i>
          <p><strong>หมายเหตุ:</strong> กรุณากรอกหลังส่งใบแทนเวรรักษาการณ์ที่ บก.พัน 1</p>
        </div>

        <button type="submit" id="submitBtn" class="btn-submit">
          ยื่นคำร้องขอแทนเวร <i class="fas fa-paper-plane"></i>
        </button>
      </form>

    <?php else: ?>
      <div class="section-title">แบบฟอร์มขอแทนเวร</div>
      <div class="closed-box fade-in-up delay-2">
        <div class="closed-icon"><i class="fas fa-ban"></i></div>
        <div class="closed-title">ระบบปิดรับคำขอแทนเวรชั่วคราว</div>
        <p class="closed-desc">ขณะนี้ระบบไม่อนุญาตให้ยื่นคำขอแทนเวร หากมีข้อสงสัยหรือเหตุจำเป็น กรุณาติดต่อผู้ดูแลระบบหรือกองพัน</p>
      </div>
    <?php endif; ?>
    </main>

  </div>

  <!-- Bottom Navigation -->
  <nav class="bottom-nav" aria-label="เมนูหลัก">
    <a href="main.php" class="nav-link">
      <i class="fas fa-home"></i>
      <span>หน้าหลัก</span>
    </a>

    <!-- กำหนดหน้าปัจจุบันเป็น active -->
    <a href="substitute.php" class="nav-link active">
      <i class="fas fa-exchange-alt"></i>
      <span>แทนเวร</span>
    </a>
  </nav>

<script>
<?php if ($is_system_open): ?>
async function fetchStudentName(inputId, nameInputId, isTarget) {
    var codeInput = document.getElementById(inputId);
    var nameInput = document.getElementById(nameInputId);
    var dutyDateInput = document.getElementById('duty_date');
    var dutyInfoInput = document.getElementById('target_duty_info');
    var dutyNameInput = document.getElementById('duty_name');

    var code = codeInput ? codeInput.value.trim() : "";
    var date = dutyDateInput ? dutyDateInput.value.trim() : "";

    if (code === "") {
        if (nameInput) {
            nameInput.value = "";
            nameInput.className = "form-control input-readonly";
        }
        if (isTarget && dutyInfoInput) {
            dutyInfoInput.value = "";
            dutyInfoInput.className = "form-control input-readonly";
        }
        return;
    }

    if (nameInput) {
        nameInput.value = "กำลังค้นหาข้อมูล...";
        nameInput.className = "form-control input-readonly";
    }

    try {
        var requestUrl = '?action=get_student_name&code=' + encodeURIComponent(code) + '&date=' + encodeURIComponent(date) + '&_t=' + Date.now();
        var response = await fetch(requestUrl);
        
        if (!response.ok) throw new Error('Network response error');
        var data = await response.json();

        if (data.found) {
            if (nameInput) {
                nameInput.value = data.name;
                nameInput.className = "form-control input-success";
            }

            if (isTarget) {
                if (data.duty_info) {
                    if (dutyInfoInput) {
                        dutyInfoInput.value = data.duty_info;
                        dutyInfoInput.className = "form-control input-success";
                    }
                    if (dutyNameInput && (dutyNameInput.value === "" || dutyNameInput.dataset.autofilled === "true")) {
                        dutyNameInput.value = data.duty_info;
                        dutyNameInput.dataset.autofilled = "true";
                    }
                } else if (date !== "") {
                    if (dutyInfoInput) {
                        dutyInfoInput.value = "ไม่พบข้อมูลเวรในวันที่เลือก";
                        dutyInfoInput.className = "form-control input-readonly";
                    }
                } else {
                    if (dutyInfoInput) {
                        dutyInfoInput.value = "กรุณาเลือกวันที่ต้องเข้าเวรเพื่อดูผลัด/จุดที่";
                        dutyInfoInput.className = "form-control input-readonly";
                    }
                }
            }
        } else {
            if (nameInput) {
                nameInput.value = "ไม่พบรหัสนี้ในระบบ";
                nameInput.className = "form-control input-error";
            }
            if (isTarget && dutyInfoInput) {
                dutyInfoInput.value = "";
                dutyInfoInput.className = "form-control input-readonly";
            }
        }
    } catch (error) {
        if (nameInput) {
            nameInput.value = "เชื่อมต่อล้มเหลว";
            nameInput.className = "form-control input-error";
        }
    }
}

var reqCode = document.getElementById('requester_code');
if (reqCode) {
    reqCode.addEventListener('blur', function() { fetchStudentName('requester_code', 'requester_name', false); });
    reqCode.addEventListener('input', function() { if (this.value.trim().length >= 5) fetchStudentName('requester_code', 'requester_name', false); });
}

var targetCode = document.getElementById('target_code');
if (targetCode) {
    targetCode.addEventListener('blur', function() { fetchStudentName('target_code', 'target_name', true); });
    targetCode.addEventListener('input', function() { if (this.value.trim().length >= 5) fetchStudentName('target_code', 'target_name', true); });
}

var dutyDate = document.getElementById('duty_date');
if (dutyDate) {
    dutyDate.addEventListener('change', function() {
        if (targetCode && targetCode.value.trim() !== "") {
            fetchStudentName('target_code', 'target_name', true);
        }
    });
}

var form = document.getElementById("substituteForm");
if (form) {
    form.addEventListener("submit", function(e){
        e.preventDefault();
        if (!form.checkValidity()) { form.reportValidity(); return; }

        Swal.fire({
            title: 'ยืนยันการแทนเวร',
            text: 'กรุณาตรวจสอบความถูกต้อง คุณแน่ใจหรือไม่ว่าต้องการแทนเวรให้เพื่อนคนนี้?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#503896',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก'
        }).then(function(result) {
            if(result.isConfirmed) {
                document.getElementById("submitBtn").innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังดำเนินการ...';
                form.submit();
            }
        });
    });
}
<?php endif; ?>
</script>
</body>
</html>
