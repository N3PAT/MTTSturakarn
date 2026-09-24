<?php
session_start();

// ตั้งค่า Timezone ให้เป็นเวลาประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// สร้าง CSRF Token
if (empty($_SESSION['csrf_token'])) {$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'db.php';

// ---------------- 1. API สำหรับดึงข้อมูลนักเรียนผ่าน AJAX ----------------
if (isset($_GET['action']) && $_GET['action'] == 'get_student_name' && isset($_GET['code'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    
    $code = trim($_GET['code']);
    try {
        $stmt =$conn->prepare("SELECT prefix, first_name, last_name, section FROM students WHERE student_code = ? LIMIT 1");
        $stmt->bind_param("s", $code);$stmt->execute();
        $res =$stmt->get_result();
        
        if ($row =$res->fetch_assoc()) {
            $fullname = trim(($row['prefix'] ?? '') . ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            echo json_encode(['found' => true, 'name' => $fullname]);
        } else {
            echo json_encode(['found' => false, 'name' => '']);
        }
    } catch (Exception $e) {
        echo json_encode(['found' => false, 'name' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

$alert_type = "";
$message = "";

// ---------------- 2. เมื่อนักเรียนกดบันทึกข้อมูลขอแทนเวร ----------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token =$_POST['csrf_token'] ?? '';
    $requester_code = trim($_POST['requester_code'] ?? '');
    $target_code = trim($_POST['target_code'] ?? '');
    $duty_date = trim($_POST['duty_date'] ?? '');

    // เปลี่ยนจาก || เป็น or เพื่อแก้ปัญหาอักขระพิเศษ
    if (empty($csrf_token) or !hash_equals($_SESSION['csrf_token'], $csrf_token)) {$alert_type = "error";
        $message = "เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่";
    } else if ($requester_code === $target_code) {$alert_type = "error";
        $message = "ไม่สามารถขอแทนเวรตัวเองได้";
    } else {
        $alert_type = "success";
        $message = "ส่งคำขอแทนเวรของรหัส " . htmlspecialchars($target_code) . " เรียบร้อยแล้ว รอการอนุมัติ";
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
    .section-title { display: flex; align-items: center; gap: 10px; margin: 10px 20px 15px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.1rem; margin: 0; }
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
    .btn-submit { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 24px; font-family: 'Prompt', sans-serif; }
    .divider { height: 1px; background: #e2e8f0; margin: 20px 0; }
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
      <div class="section-pill"></div>
      <h3>แบบฟอร์มขอแทนเวร</h3>
    </div>
    
    <div class="form-card">
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

        <button type="submit" id="submitBtn" class="btn-submit">ยื่นคำร้องขอแทนเวร <i class="fas fa-paper-plane"></i></button>
      </form>
    </div>
  </div>

<script>
<?php if ($alert_type == 'success'): ?>
    Swal.fire({ title: 'สำเร็จ', text: <?php echo json_encode($message); ?>, icon: 'success', confirmButtonColor: '#5a42a0' }).then(() => { window.location.href = window.location.pathname; });
<?php elseif ($alert_type == 'error'): ?>
    Swal.fire({ title: 'ข้อผิดพลาด', text: <?php echo json_encode($message); ?>, icon: 'error', confirmButtonColor: '#b71c1c' });
<?php endif; ?>

async function fetchStudentName(inputId, nameInputId) {
    const codeInput = document.getElementById(inputId);
    const nameInput = document.getElementById(nameInputId);
    const code = codeInput.value.trim();

    if (code === "") {
        nameInput.value = "";
        nameInput.className = "form-control input-readonly";
        return;
    }

    nameInput.value = "กำลังค้นหาข้อมูล...";
    nameInput.className = "form-control input-readonly";

    try {
        const response = await fetch(`?action=get_student_name&code=${encodeURIComponent(code)}&_t=${Date.now()}`);
        if (!response.ok) throw new Error('Network response error');
        const data = await response.json();

        if (data.found) {
            nameInput.value = data.name;
            nameInput.className = "form-control input-success";
        } else {
            nameInput.value = "ไม่พบรหัสนี้ในระบบ";
            nameInput.className = "form-control input-error";
        }
    } catch (error) {
        nameInput.value = "เชื่อมต่อล้มเหลว";
        nameInput.className = "form-control input-error";
    }
}

const reqCode = document.getElementById('requester_code');
reqCode.addEventListener('blur', () => fetchStudentName('requester_code', 'requester_name'));
reqCode.addEventListener('input', function() { if (this.value.trim().length >= 5) fetchStudentName('requester_code', 'requester_name'); });

const targetCode = document.getElementById('target_code');
targetCode.addEventListener('blur', () => fetchStudentName('target_code', 'target_name'));
targetCode.addEventListener('input', function() { if (this.value.trim().length >= 5) fetchStudentName('target_code', 'target_name'); });

const form = document.getElementById("substituteForm");
form.addEventListener("submit", function(e){
    e.preventDefault();
    if (!form.checkValidity()) { form.reportValidity(); return; }

    Swal.fire({
        title: 'ยืนยันการแทนเวร',
        text: 'กรุณาตรวจสอบความถูกต้อง คุณแน่ใจหรือไม่ว่าต้องการแทนเวรให้รหัสนี้?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#5a42a0',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if(result.isConfirmed) {
            document.getElementById("submitBtn").innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังดำเนินการ...';
            form.submit();
        }
    });
});
</script>
</body>
</html>
