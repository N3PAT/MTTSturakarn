<?php
session_start();

date_default_timezone_set('Asia/Bangkok');

// สร้าง CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'db.php';

$alert_type = "";
$message = "";

// 1. ดึงสถานะปัจจุบันของระบบ
$is_system_open = true;
try {
    $setting_stmt = $conn->query("SELECT setting_value FROM system_settings WHERE setting_name = 'duty_system_status' LIMIT 1");
    if ($setting_row = $setting_stmt->fetch_assoc()) {
        $is_system_open = ($setting_row['setting_value'] === 'open');
    }
} catch (Exception $e) {
    $is_system_open = true;
}

// 2. จัดการ Action จาก Admin (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (empty($csrf_token) or !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $alert_type = 'error';
        $message = 'เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วทำรายการใหม่';
    } else {
        // Toggle เปิด/ปิดระบบ
        if ($action === 'toggle_system') {
            $new_status = $is_system_open ? 'closed' : 'open';
            try {
                $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = 'duty_system_status'");
                $stmt->bind_param("s", $new_status);
                if ($stmt->execute()) {
                    $is_system_open = ($new_status === 'open');
                    $alert_type = 'success';
                    $message = $is_system_open ? 'เปิดระบบรับคำขอแทนเวรเรียบร้อยแล้ว' : 'ปิดระบบรับคำขอแทนเวรเรียบร้อยแล้ว';
                }
            } catch (Exception $e) {
                $alert_type = 'error';
                $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
        
        // อัปเดต/เปลี่ยนสถานะคำขอแทนเวร (approved, rejected, pending)
        else if ($action === 'update_swap_status') {
            $swap_id = isset($_POST['swap_id']) ? intval($_POST['swap_id']) : 0;
            $status = isset($_POST['status']) ? $_POST['status'] : ''; // 'approved', 'rejected', 'pending'
            
            if ($swap_id > 0 && in_array($status, ['approved', 'rejected', 'pending'])) {
                try {
                    // อัปเดตสถานะใน duty_swaps
                    $stmt = $conn->prepare("UPDATE duty_swaps SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $status, $swap_id);
                    
                    if ($stmt->execute()) {
                        // ส่งแจ้งเตือนเมื่อเปลี่ยนสถานะเป็นอนุมัติหรือปฏิเสธ
                        if (in_array($status, ['approved', 'rejected'])) {
                            $info_stmt = $conn->prepare("SELECT original_student_code, replacement_student_code, duty_date FROM duty_swaps WHERE id = ? LIMIT 1");
                            $info_stmt->bind_param("i", $swap_id);
                            $info_stmt->execute();
                            $swap_info = $info_stmt->get_result()->fetch_assoc();

                            if ($swap_info) {
                                $status_th = ($status === 'approved') ? 'อนุมัติแล้ว' : 'ถูกปฏิเสธ';
                                $notif_title = "ผลการขอแทนเวรวันที่ " . $swap_info['duty_date'];
                                $notif_msg = "คำขอแทนเวรรหัส " . $swap_info['original_student_code'] . " โดยรหัส " . $swap_info['replacement_student_code'] . " " . $status_th;
                                
                                $notif_type = "duty"; 
                                $alert_lvl = "info";

                                // แจ้งเตือนผู้ทำแทน
                                $notif_stmt = $conn->prepare("INSERT INTO notifications (student_code, title, message, type, alert_level) VALUES (?, ?, ?, ?, ?)");
                                $notif_stmt->bind_param("sssss", $swap_info['replacement_student_code'], $notif_title, $notif_msg, $notif_type, $alert_lvl);
                                $notif_stmt->execute();
                            }
                        }

                        $alert_type = 'success';
                        $status_msgs = [
                            'approved' => 'อนุมัติคำขอแทนเวรเรียบร้อย',
                            'rejected' => 'ปฏิเสธคำขอแทนเวรเรียบร้อย',
                            'pending'  => 'เปลี่ยนสถานะเป็น "รออนุมัติ" เรียบร้อย'
                        ];
                        $message = $status_msgs[$status] ?? 'อัปเดตสถานะเรียบร้อยแล้ว';
                    }
                } catch (Exception $e) {
                    $alert_type = 'error';
                    $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
                }
            }
        }

        // ลบรายการคำขอแทนเวร
        else if ($action === 'delete_swap') {
            $swap_id = isset($_POST['swap_id']) ? intval($_POST['swap_id']) : 0;
            
            if ($swap_id > 0) {
                try {
                    $stmt = $conn->prepare("DELETE FROM duty_swaps WHERE id = ?");
                    $stmt->bind_param("i", $swap_id);
                    if ($stmt->execute()) {
                        $alert_type = 'success';
                        $message = 'ลบรายการคำขอแทนเวรเรียบร้อยแล้ว';
                    }
                } catch (Exception $e) {
                    $alert_type = 'error';
                    $message = 'เกิดข้อผิดพลาดในการลบรายการ: ' . $e->getMessage();
                }
            }
        }
    }
}

// 3. ดึงรายการคำขอแทนเวรทั้งหมด
$pending_swaps = [];
$history_swaps = [];

try {
    $sql = "SELECT s.*, 
            s1.prefix AS req_prefix, s1.first_name AS req_fname, s1.last_name AS req_lname,
            s2.prefix AS tar_prefix, s2.first_name AS tar_fname, s2.last_name AS tar_lname
            FROM duty_swaps s
            LEFT JOIN students s1 ON s.replacement_student_code = s1.student_code
            LEFT JOIN students s2 ON s.original_student_code = s2.student_code
            ORDER BY s.id DESC";
            
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            if ($row['status'] === 'pending') {
                $pending_swaps[] = $row;
            } else {
                $history_swaps[] = $row;
            }
        }
    }
} catch (Exception $e) {
    // บันทึก Log ข้อผิดพลาดถ้าจำเป็น
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ผู้ดูแลระบบ - จัดการการแทนเวร MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <style>
    :root { --primary: #5a42a0; --primary-dark: #473282; --bg-color: #f6f8fc; --text-dark: #1e293b; --text-gray: #64748b; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; min-height: 100vh; }
    .app-container { width: 100%; max-width: 540px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: white; border-bottom: 1px solid #e2e8f0; }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; border-radius: 10px; }
    .school-name h1 { font-size: 1rem; margin: 0 0 2px 0; color: var(--text-dark); }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    
    .section-title { display: flex; align-items: center; justify-content: space-between; margin: 20px 20px 12px; }
    .title-left { display: flex; align-items: center; gap: 10px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.05rem; margin: 0; color: var(--text-dark); }

    .card { background: white; border-radius: 16px; padding: 18px; margin: 0 20px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    
    /* Toggle Switch Card */
    .system-toggle-box { display: flex; align-items: center; justify-content: space-between; }
    .system-status-info p { margin: 0; font-size: 0.8rem; color: var(--text-gray); }
    .system-status-info h4 { margin: 2px 0 0 0; font-size: 1rem; color: var(--text-dark); }
    .btn-toggle { border: none; padding: 10px 18px; border-radius: 12px; font-family: 'Prompt', sans-serif; font-size: 0.875rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s; }
    .btn-toggle.open { background: #dcfce7; color: #15803d; }
    .btn-toggle.closed { background: #fee2e2; color: #b91c1c; }

    /* Swap Request Card */
    .swap-item { border-bottom: 1px solid #f1f5f9; padding-bottom: 14px; margin-bottom: 14px; }
    .swap-item:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
    .swap-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .date-badge { font-size: 0.75rem; background: #f1f5f9; padding: 4px 10px; border-radius: 8px; color: var(--text-dark); font-weight: 500; }
    .status-pill { font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 12px; }
    .status-pill.pending { background: #fef3c7; color: #b45309; }
    .status-pill.approved { background: #dcfce7; color: #15803d; }
    .status-pill.rejected { background: #fee2e2; color: #b91c1c; }

    .people-flow { background: #f8fafc; border-radius: 10px; padding: 10px 12px; font-size: 0.85rem; margin-bottom: 10px; }
    .person-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .person-row:last-child { margin-bottom: 0; }
    .person-label { color: var(--text-gray); font-size: 0.8rem; }
    .person-val { font-weight: 500; color: var(--text-dark); }
    .duty-detail { font-size: 0.8rem; color: #475569; margin-bottom: 12px; background: #fff; padding: 8px 10px; border-radius: 8px; border: 1px dashed #cbd5e1; }
    .reason-text { color: #64748b; font-style: italic; }

    .action-btns { display: flex; gap: 8px; }
    .btn-action { flex: 1; padding: 9px; border-radius: 10px; border: none; font-family: 'Prompt', sans-serif; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; }
    .btn-approve { background: #22c55e; color: white; }
    .btn-approve:hover { background: #16a34a; }
    .btn-reject { background: #ef4444; color: white; }
    .btn-reject:hover { background: #dc2626; }
    .btn-status { background: #e0f2fe; color: #0369a1; }
    .btn-status:hover { background: #bae6fd; }
    .btn-delete { background: #fee2e2; color: #b91c1c; flex: 0 0 auto; padding: 9px 12px; }
    .btn-delete:hover { background: #fca5a5; }

    .empty-state { text-align: center; color: var(--text-gray); padding: 20px 0; font-size: 0.9rem; }
  </style>
</head>
<body>

  <div class="app-container">
    <!-- Header -->
    <div class="top-header">
      <div class="header-left">
        <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>แผงควบคุมผู้ดูแลระบบ (Admin)</h1>
          <p>ระบบจัดการการเข้าเวร - กองพันนักเรียนที่ 1</p>
        </div>
      </div>
    </div>

    <!-- 1. ส่วนตั้งค่าสถานะระบบ -->
    <div class="section-title">
      <div class="title-left">
        <div class="section-pill"></div>
        <h3>สถานะระบบรับคำขอแทนเวร</h3>
      </div>
    </div>

    <div class="card">
      <div class="system-toggle-box">
        <div class="system-status-info">
          <p>สถานะปัจจุบัน:</p>
          <h4><?php echo $is_system_open ? '<span style="color:#16a34a;"><i class="fas fa-check-circle"></i> เปิดรับคำขอ</span>' : '<span style="color:#dc2626;"><i class="fas fa-lock"></i> ปิดรับคำขอ</span>'; ?></h4>
        </div>
        <form id="toggleForm" method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="toggle_system">
          <button type="button" onclick="confirmToggle(<?php echo $is_system_open ? 'true' : 'false'; ?>)" class="btn-toggle <?php echo $is_system_open ? 'open' : 'closed'; ?>">
            <i class="fas fa-power-off"></i> <?php echo $is_system_open ? 'สั่งปิดระบบ' : 'สั่งเปิดระบบ'; ?>
          </button>
        </form>
      </div>
    </div>

    <!-- 2. ส่วนรายการคำขอที่รออนุมัติ -->
    <div class="section-title">
      <div class="title-left">
        <div class="section-pill"></div>
        <h3>คำขอรอการอนุมัติ (<?php echo count($pending_swaps); ?>)</h3>
      </div>
    </div>

    <div class="card">
      <?php if (empty($pending_swaps)): ?>
        <div class="empty-state">
          <i class="fas fa-inbox fa-2x" style="margin-bottom: 8px; color: #cbd5e1;"></i>
          <p>ไม่มีคำขอแทนเวรที่รอการอนุมัติในขณะนี้</p>
        </div>
      <?php else: ?>
        <?php foreach ($pending_swaps as $item): 
            $req_name = trim(($item['req_prefix'] ?? '') . ' ' . ($item['req_fname'] ?? '') . ' ' . ($item['req_lname'] ?? ''));
            $tar_name = trim(($item['tar_prefix'] ?? '') . ' ' . ($item['tar_fname'] ?? '') . ' ' . ($item['tar_lname'] ?? ''));
        ?>
          <div class="swap-item">
            <div class="swap-header">
              <span class="date-badge"><i class="far fa-calendar-alt"></i> เวรวันที่: <?php echo htmlspecialchars($item['duty_date']); ?></span>
              <span class="status-pill pending"><i class="fas fa-clock"></i> รออนุมัติ</span>
            </div>

            <div class="people-flow">
              <div class="person-row">
                <span class="person-label"><i class="fas fa-user-check text-indigo-500"></i> ผู้ทำหน้าที่แทน:</span>
                <span class="person-val"><?php echo htmlspecialchars($item['replacement_student_code']); ?> <?php echo $req_name ? "($req_name)" : ''; ?></span>
              </div>
              <div class="person-row">
                <span class="person-label"><i class="fas fa-user-clock text-amber-500"></i> เจ้าของเวรเดิม:</span>
                <span class="person-val"><?php echo htmlspecialchars($item['original_student_code']); ?> <?php echo $tar_name ? "($tar_name)" : ''; ?></span>
              </div>
            </div>

            <div class="duty-detail">
              <strong>หน้าที่:</strong> <?php echo htmlspecialchars($item['duty_name']); ?><br>
              <strong>เหตุผล:</strong> <span class="reason-text"><?php echo htmlspecialchars($item['reason'] ?: 'ไม่ได้ระบุ'); ?></span>
            </div>

            <div class="action-btns">
              <button type="button" onclick="handleSwapAction(<?php echo $item['id']; ?>, 'approved')" class="btn-action btn-approve">
                <i class="fas fa-check"></i> อนุมัติ
              </button>
              <button type="button" onclick="handleSwapAction(<?php echo $item['id']; ?>, 'rejected')" class="btn-action btn-reject">
                <i class="fas fa-times"></i> ปฏิเสธ
              </button>
              <button type="button" onclick="handleDeleteAction(<?php echo $item['id']; ?>)" class="btn-action btn-delete" title="ลบรายการ">
                <i class="fas fa-trash-alt"></i>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Form ซ่อนสำหรับส่งค่า Action ต่างๆ -->
    <form id="actionForm" method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
      <input type="hidden" id="action_input" name="action" value="update_swap_status">
      <input type="hidden" id="swap_id_input" name="swap_id" value="">
      <input type="hidden" id="status_input" name="status" value="">
    </form>

    <!-- 3. ประวัติคำขอที่ดำเนินการแล้ว -->
    <?php if (!empty($history_swaps)): ?>
    <div class="section-title">
      <div class="title-left">
        <div class="section-pill" style="background: #64748b;"></div>
        <h3>ประวัติการดำเนินการ (<?php echo count($history_swaps); ?>)</h3>
      </div>
    </div>

    <div class="card">
      <?php foreach ($history_swaps as $item): ?>
        <div class="swap-item">
          <div class="swap-header">
            <span class="date-badge"><i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($item['duty_date']); ?></span>
            <?php if ($item['status'] === 'approved'): ?>
              <span class="status-pill approved"><i class="fas fa-check-circle"></i> อนุมัติแล้ว</span>
            <?php else: ?>
              <span class="status-pill rejected"><i class="fas fa-times-circle"></i> ไม่อนุมัติ</span>
            <?php endif; ?>
          </div>
          <div style="font-size: 0.85rem; color: var(--text-dark); margin-bottom: 10px;">
            รหัส <strong><?php echo htmlspecialchars($item['replacement_student_code']); ?></strong> แทนรหัส <strong><?php echo htmlspecialchars($item['original_student_code']); ?></strong>
          </div>
          <div class="action-btns">
            <button type="button" onclick="changeStatusDialog(<?php echo $item['id']; ?>, '<?php echo $item['status']; ?>')" class="btn-action btn-status">
              <i class="fas fa-sync-alt"></i> เปลี่ยนสถานะ
            </button>
            <button type="button" onclick="handleDeleteAction(<?php echo $item['id']; ?>)" class="btn-action btn-delete" title="ลบรายการ">
              <i class="fas fa-trash-alt"></i> ลบ
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

<script>
// แจ้งเตือน Toast เมื่อมีผลลัพธ์
<?php if ($alert_type == 'success'): ?>
    Swal.fire({ title: 'สำเร็จ', text: "<?php echo addslashes($message); ?>", icon: 'success', confirmButtonColor: '#5a42a0' });
<?php elseif ($alert_type == 'error'): ?>
    Swal.fire({ title: 'ข้อผิดพลาด', text: "<?php echo addslashes($message); ?>", icon: 'error', confirmButtonColor: '#b71c1c' });
<?php endif; ?>

// ยืนยันสลับสถานะเปิด-ปิดระบบ
function confirmToggle(isOpen) {
    var actionText = isOpen ? "ปิดระบบรับคำขอแทนเวรชั่วคราว" : "เปิดระบบรับคำขอแทนเวร";
    Swal.fire({
        title: 'ยืนยันการทำรายการ',
        text: 'คุณต้องการ ' + actionText + ' ใช่หรือไม่?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#5a42a0',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('toggleForm').submit();
        }
    });
}

// ยืนยันการ อนุมัติ/ปฏิเสธ คำขอแทนเวร (ฉบับรวดเร็ว)
function handleSwapAction(id, status) {
    var isApprove = (status === 'approved');
    var titleText = isApprove ? 'ยืนยันการอนุมัติ' : 'ยืนยันการปฏิเสธ';
    var bodyText = isApprove ? 'คุณต้องการอนุมัติให้แทนเวรรายการนี้ใช่หรือไม่?' : 'คุณต้องการปฏิเสธคำขอแทนเวรรายการนี้ใช่หรือไม่?';
    var btnColor = isApprove ? '#22c55e' : '#ef4444';

    Swal.fire({
        title: titleText,
        text: bodyText,
        icon: isApprove ? 'question' : 'warning',
        showCancelButton: true,
        confirmButtonColor: btnColor,
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'ตกลง',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('action_input').value = 'update_swap_status';
            document.getElementById('swap_id_input').value = id;
            document.getElementById('status_input').value = status;
            document.getElementById('actionForm').submit();
        }
    });
}

// Dialog สำหรับเลือกเปลี่ยนสถานะได้อย่างอิสระ
function changeStatusDialog(id, currentStatus) {
    Swal.fire({
        title: 'เลือกเปลี่ยนสถานะ',
        input: 'select',
        inputOptions: {
            'pending': 'รออนุมัติ (Pending)',
            'approved': 'อนุมัติแล้ว (Approved)',
            'rejected': 'ปฏิเสธ/ไม่อนุมัติ (Rejected)'
        },
        inputValue: currentStatus,
        showCancelButton: true,
        confirmButtonText: 'บันทึกสถานะ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#5a42a0',
        cancelButtonColor: '#94a3b8'
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            document.getElementById('action_input').value = 'update_swap_status';
            document.getElementById('swap_id_input').value = id;
            document.getElementById('status_input').value = result.value;
            document.getElementById('actionForm').submit();
        }
    });
}

// ยืนยันการลบรายการคำขอแทนเวร
function handleDeleteAction(id) {
    Swal.fire({
        title: 'ยืนยันการลบรายการ',
        text: 'คุณต้องการลบรายการคำขอแทนเวรนี้ใช่หรือไม่? (ข้อมูลจะถูกลบถาวร)',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'ลบรายการ',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('action_input').value = 'delete_swap';
            document.getElementById('swap_id_input').value = id;
            document.getElementById('actionForm').submit();
        }
    });
}
</script>
</body>
</html>
