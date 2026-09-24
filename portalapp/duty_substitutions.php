<?php
session_start();

date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'db.php';

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

    $all_students = array();
    try {
        $res = $conn->query("SELECT student_code FROM students ORDER BY id ASC");
        if ($res and $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $all_students[] = $row['student_code'];
            }
        }
    } catch (Exception $e) {
        return null;
    }

    $total_students = count($all_students);
    if ($total_students === 0) {
        return null;
    }

    $start_index = ($diff_days * 16) % $total_students;

    for ($i = 0; $i < 16; $i++) {
        $idx = ($start_index + $i) % $total_students;
        if (isset($all_students[$idx]) and $all_students[$idx] === $student_code) {
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

$alert_type = "";
$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$is_system_open) {
        $alert_type = 'error';
        $message = 'ระบบปิดรับคำขอแทนเวรชั่วคราว ไม่สามารถทำรายการได้';
    } else {
        $csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        $requester_code = isset($_POST['requester_code']) ? trim($_POST['requester_code']) : ''; 
        $target_code = isset($_POST['target_code']) ? trim($_POST['target_code']) : '';
        $duty_date = isset($_POST['duty_date']) ? trim($_POST['duty_date']) : '';
        $duty_name = isset($_POST['duty_name']) ? trim($_POST['duty_name']) : '';
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

        if (empty($csrf_token) or !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
            $alert_type = 'error';
            $message = 'เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่';
        } else if (empty($requester_code) or empty($target_code) or empty($duty_date) or empty($duty_name)) {
            $alert_type = 'error';
            $message = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
        } else if ($requester_code === $target_code) {
            $alert_type = 'error';
            $message = 'ไม่สามารถขอแทนเวรตัวเองได้';
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

                    $alert_type = 'success';
                    $message = 'ส่งคำขอแทนเวรของรหัส ' . htmlspecialchars($target_code) . ' เรียบร้อยแล้ว รอการอนุมัติจาก Admin';
                } else {
                    $alert_type = 'error';
                    $message = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
                }
            } catch (Exception $e) {
                $alert_type = 'error';
                $message = 'เกิดข้อผิดพลาดของระบบ: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>แจ้งขอแทนเวร - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <style>
    :root { --primary: #5a42a0; --primary-dark: #473282; --bg-color: #f6f8fc; --text-dark: #1e293b; --text-gray: #64748b; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; min-height: 100vh; }
    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--bg-color); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; margin: 0 0 2px 0; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    
    .section-title { display: flex; align-items: center; justify-content: space-between; margin: 10px 20px 15px; }
    .title-left { display: flex; align-items: center; gap: 10px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; margin: 0; }
    
    .status-badge { font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; display: flex; align-items: center; gap: 5px; }
    .status-badge.open { background-color: #dcfce7; color: #166534; }
    .status-badge.closed { background-color: #fee2e2; color: #991b1b; }

    .form-card { background: white; border-radius: 16px; padding: 20px; margin: 0 20px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; margin-bottom: 8px; }
    .text-danger { color: #ef4444; }
    .icon-label { color: var(--primary); }
    .form-control { width: 100%; padding: 12px 16px; border-radius: 12px; border: 1px solid #cbd5e1; background: #f8fafc; font-family: 'Prompt', sans-serif; font-size: 0.95rem; outline: none; box-sizing: border-box; }
    .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(90, 66, 160, 0.1); background: white; }
    .input-readonly { background: #f1f5f9; color: #64748b; border-style: dashed; }
    .input-success { background: #f0fdf4; border-color: #22c55e; color: #15803d; }
    .input-error { background: #fef2f2; border-color: #ef4444; color: #b91c1c; }
    textarea.form-control { resize: none; height: 80px; }
    .btn-submit { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 24px; font-family: 'Prompt', sans-serif; }
    .divider { height: 1px; background: #e2e8f0; margin: 20px 0; }

    .closed-box { text-align: center; padding: 30px 20px; }
    .closed-icon { font-size: 3rem; color: #ef4444; margin-bottom: 12px; }
    .closed-title { font-size: 1.1rem; font-weight: 600; color: #1e293b; margin-bottom: 8px; }
    .closed-desc { font-size: 0.875rem; color: #64748b; margin: 0; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>ระบบบริการสารสนเทศ กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
    </div>

    <div class="section-title">
      <div class="title-left">
        <div class="section-pill"></div>
        <h3>แบบฟอร์มขอแทนเวร</h3>
      </div>
      <?php if ($is_system_open): ?>
        <div class="status-badge open">
          <i class="fas fa-circle text-[8px]"></i> เปิดระบบ
        </div>
      <?php else: ?>
        <div class="status-badge closed">
          <i class="fas fa-lock"></i> ปิดระบบ
        </div>
      <?php endif; ?>
    </div>
    
    <div class="form-card">
      <?php if ($is_system_open): ?>
        <form id="substituteForm" method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          
          <div class="form-group">
            <label for="requester_code"><i class="fas fa-user-check icon-label"></i> รหัสประจำตัว (ผู้ทำหน้าที่แทน) <span class="text-danger">*</span></label>
            <input type="text" id="requester_code" name="requester_code" required maxlength="10" inputmode="numeric" class="form-control" placeholder="เช่น 151xx">
          </div>
          <div class="form-group">
            <input type="text" id="requester_name" readonly tabindex="-1" class="form-control input-readonly" placeholder="ระบบจะแสดงชื่ออัตโนมัติ">
          </div>

          <div class="divider"></div>

          <div class="form-group">
            <label for="target_code"><i class="fas fa-user-clock icon-label"></i> รหัสประจำตัว (ผู้ที่ถูกแทนเวร) <span class="text-danger">*</span></label>
            <input type="text" id="target_code" name="target_code" required maxlength="10" inputmode="numeric" class="form-control" placeholder="รหัสของเพื่อนที่คุณจะเข้าเวรแทน">
          </div>
          <div class="form-group">
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

          <div class="divider"></div>

          <div class="form-group">
            <label for="duty_name"><i class="fas fa-clipboard-list icon-label"></i> ชื่อเวร / หน้าที่ <span class="text-danger">*</span></label>
            <input type="text" id="duty_name" name="duty_name" required class="form-control" placeholder="เช่น ผลัดที่ 1 (จุดที่ 4 อาคารยานยนต์)">
          </div>

          <div class="form-group">
            <label for="reason"><i class="fas fa-comment-dots icon-label"></i> เหตุผลการขอแทนเวร</label>
            <textarea id="reason" name="reason" class="form-control" placeholder="ระบุเหตุผลที่ขอทำหน้าที่แทน (ถ้ามี)"></textarea>
          </div>

          <button type="submit" id="submitBtn" class="btn-submit">ยื่นคำร้องขอแทนเวร <i class="fas fa-paper-plane"></i></button>
        </form>

      <?php else: ?>
        <div class="closed-box">
          <div class="closed-icon"><i class="fas fa-ban"></i></div>
          <div class="closed-title">ระบบปิดรับคำขอแทนเวรชั่วคราว</div>
          <p class="closed-desc">ขณะนี้ระบบไม่อนุญาตให้ยื่นคำขอแทนเวร หากมีข้อสงสัยหรือเหตุจำเป็น กรุณาติดต่อผู้ดูแลระบบหรือกองร้อย/กองพัน</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

<script>
<?php if ($alert_type == 'success'): ?>
    Swal.fire({ title: 'สำเร็จ', text: "<?php echo addslashes($message); ?>", icon: 'success', confirmButtonColor: '#5a42a0' }).then(function() { window.location.href = window.location.pathname; });
<?php elseif ($alert_type == 'error'): ?>
    Swal.fire({ title: 'ข้อผิดพลาด', text: "<?php echo addslashes($message); ?>", icon: 'error', confirmButtonColor: '#b71c1c' });
<?php endif; ?>

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
            confirmButtonColor: '#5a42a0',
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
