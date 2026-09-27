<?php
session_start();

// เปิดการแสดง Error ชั่วคราวกรณีมีปัญหาอื่น
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

// ตรวจสอบสิทธิ์ Admin
if (empty($_SESSION['admin_id'])) {
    header("Location: admin_main.php");
    exit();
}

$message = '';
$message_type = '';
$view = $_GET['view'] ?? 'manage'; // 'manage' หรือ 'add'

// ---------------- จัดการ POST Request ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- โหมดจัดการ: ลบข้อมูลแอดมิน ---
    if (isset($_POST['action_delete'])) {
        $del_id = (int)$_POST['delete_id'];
        
        if ($del_id === 1) {
            $message = "ไม่สามารถลบ Root Admin (ID: 1) ได้";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
            $stmt->bind_param("i", $del_id);
            if ($stmt->execute()) {
                $message = "ลบผู้ดูแลระบบสำเร็จ";
                $message_type = "success";
            } else {
                $message = "เกิดข้อผิดพลาดในการลบ: " . $conn->error;
                $message_type = "error";
            }
        }
        $view = 'manage';
    }
    
    // --- โหมดจัดการ: แก้ไขข้อมูลแอดมิน ---
    elseif (isset($_POST['action_update'])) {
        $up_id = (int)$_POST['update_id'];
        $up_user = trim($_POST['username']);
        $up_fname = trim($_POST['first_name']);
        $up_lname = trim($_POST['last_name']);
        $up_pass = $_POST['new_password'] ?? '';

        // ตรวจสอบ Username ซ้ำ (ยกเว้นตัวเอง)
        $chk = $conn->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
        $chk->bind_param("si", $up_user, $up_id);
        $chk->execute();
        
        if ($chk->get_result()->num_rows > 0) {
            $message = "Username นี้ถูกใช้งานแล้ว กรุณาใช้ชื่ออื่น";
            $message_type = "error";
        } else {
            // ถ้ามีการกรอกรหัสผ่านใหม่เข้ามา ให้เปลี่ยนรหัสผ่านด้วย
            if (!empty($up_pass)) {
                $hashed = password_hash($up_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admins SET username = ?, first_name = ?, last_name = ?, password = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $up_user, $up_fname, $up_lname, $hashed, $up_id);
            } else {
                $stmt = $conn->prepare("UPDATE admins SET username = ?, first_name = ?, last_name = ? WHERE id = ?");
                $stmt->bind_param("sssi", $up_user, $up_fname, $up_lname, $up_id);
            }

            if ($stmt->execute()) {
                $message = "อัปเดตข้อมูลแอดมินสำเร็จ";
                $message_type = "success";
            } else {
                $message = "เกิดข้อผิดพลาดในการอัปเดต: " . $conn->error;
                $message_type = "error";
            }
        }
        $view = 'manage';
    }

    // --- โหมดเพิ่ม: บันทึกแอดมินใหม่ ---
    elseif (isset($_POST['action_add'])) {
        $username = trim($_POST['username']);
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            // ตรวจสอบ Username ซ้ำ
            $chk = $conn->prepare("SELECT id FROM admins WHERE username = ?");
            $chk->bind_param("s", $username);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $message = "Username นี้ถูกใช้งานแล้ว";
                $message_type = "error";
                $view = 'add';
            } else {
                $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO admins (username, first_name, last_name, password) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username, $first_name, $last_name, $hashed_pass);
                
                if ($stmt->execute()) {
                    $message = "เพิ่มผู้ดูแลระบบเรียบร้อยแล้ว";
                    $message_type = "success";
                    $view = 'manage';
                } else {
                    $message = "เกิดข้อผิดพลาดในการบันทึก: " . $conn->error;
                    $message_type = "error";
                    $view = 'add';
                }
            }
        } else {
            $message = "กรุณากรอก Username และ Password ให้ครบถ้วน";
            $message_type = "error";
            $view = 'add';
        }
    }
}

// โหลดข้อมูลแอดมินทั้งหมด
$admins_list = [];
$res = $conn->query("SELECT id, username, first_name, last_name FROM admins ORDER BY id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $admins_list[] = $row;
    }
} else {
    $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $conn->error;
    $message_type = "error";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>จัดการผู้ดูแลระบบ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #dc2626; 
      --primary-hover: #b91c1c;
      --bg-color: #f8fafc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; }

    .app-container { 
      width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 100px; box-shadow: 0 0 20px rgba(0,0,0,0.05); position: relative; 
    }

    .top-header { display: flex; align-items: center; padding: 20px 24px 10px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-right: 15px; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* Tabs */
    .tabs-container { display: flex; margin: 10px 24px 0; background: #e2e8f0; border-radius: 12px; padding: 4px; }
    .tab-btn { flex: 1; text-align: center; padding: 10px; font-size: 0.9rem; font-family: 'Prompt'; font-weight: 600; color: var(--text-gray); border-radius: 10px; text-decoration: none; transition: 0.2s; }
    .tab-btn.active { background: white; color: var(--text-dark); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }

    .form-section { padding: 20px 24px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }

    .form-control { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.95rem; background: white; outline: none; }
    .form-control:focus { border-color: var(--primary); }

    .submit-btn { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 14px; font-family: 'Prompt', sans-serif; font-size: 1rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); transition: 0.2s; }
    .submit-btn:active { transform: scale(0.98); }
    
    .btn-small { padding: 8px 12px; border: none; border-radius: 8px; font-family: 'Prompt'; font-size: 0.8rem; font-weight: 600; color: white; cursor: pointer; }
    .btn-save { background: #16a34a; }
    .btn-delete { background: #dc2626; }

    .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Manage Card Styles */
    .manage-card { background: white; border-radius: 12px; padding: 15px; margin-bottom: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; }
    .manage-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-weight: 600; font-size: 0.95rem; }
    .manage-card-actions { display: flex; gap: 8px; margin-top: 12px; }
    .manage-input { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Prompt'; font-size: 0.85rem; outline: none; }
    .manage-input:focus { border-color: var(--primary); }
    .badge-root { background: #0f172a; color: white; font-size: 0.7rem; padding: 3px 8px; border-radius: 6px; font-weight: 500; }

    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: #0f172a; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header">
      <a href="admin_main.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>จัดการผู้ดูแลระบบ (Admins)</h1>
      </div>
    </div>

    <!-- แถบเมนูย่อย -->
    <div class="tabs-container">
        <a href="?view=manage" class="tab-btn <?= $view === 'manage' ? 'active' : '' ?>"><i class="fas fa-users-cog"></i> รายชื่อแอดมิน</a>
        <a href="?view=add" class="tab-btn <?= $view === 'add' ? 'active' : '' ?>"><i class="fas fa-user-plus"></i> เพิ่มแอดมิน</a>
    </div>

    <div class="form-section">
      
      <?php if (!empty($message)): ?>
        <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
          <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
          <?= htmlspecialchars($message) ?>
        </div>
      <?php endif; ?>

      <!-- ================= หน้าจอแสดง/แก้ไขรายชื่อแอดมิน (Manage) ================= -->
      <?php if ($view === 'manage'): ?>
          <div style="margin-bottom: 12px; font-size: 0.85rem; color: var(--text-gray);">
            ผู้ดูแลระบบทั้งหมด (<?= count($admins_list) ?> คน)
          </div>

          <?php if (empty($admins_list)): ?>
            <div style="text-align:center; padding: 30px; color: #94a3b8;">
                ไม่พบข้อมูลผู้ดูแลระบบ
            </div>
          <?php else: ?>
              <?php foreach ($admins_list as $row): ?>
                  <div class="manage-card">
                      <div class="manage-card-header">
                          <span>
                            <i class="fas fa-user-shield" style="color: var(--primary);"></i> 
                            <?= htmlspecialchars($row['username']) ?>
                          </span>
                          <?php if ($row['id'] == 1): ?>
                            <span class="badge-root">Root Admin</span>
                          <?php else: ?>
                            <span style="font-size: 0.75rem; color: var(--text-gray);">ID: <?= $row['id'] ?></span>
                          <?php endif; ?>
                      </div>
                      
                      <form method="POST" action="?view=manage">
                          <input type="hidden" name="update_id" value="<?= $row['id'] ?>">
                          <input type="hidden" name="action_update" value="1">
                          
                          <div style="margin-bottom: 8px;">
                              <label style="font-size: 0.75rem; color: var(--text-gray);">Username</label>
                              <input type="text" name="username" class="manage-input" value="<?= htmlspecialchars($row['username']) ?>" required>
                          </div>

                          <div style="display:flex; gap: 8px; margin-bottom: 8px;">
                              <div style="flex:1;">
                                  <label style="font-size: 0.75rem; color: var(--text-gray);">ชื่อ</label>
                                  <input type="text" name="first_name" class="manage-input" value="<?= htmlspecialchars($row['first_name'] ?? '') ?>" required>
                              </div>
                              <div style="flex:1;">
                                  <label style="font-size: 0.75rem; color: var(--text-gray);">นามสกุล</label>
                                  <input type="text" name="last_name" class="manage-input" value="<?= htmlspecialchars($row['last_name'] ?? '') ?>">
                              </div>
                          </div>

                          <div>
                              <label style="font-size: 0.75rem; color: var(--text-gray);">รหัสผ่านใหม่ (ปล่อยว่างถ้าไม่เปลี่ยน)</label>
                              <input type="password" name="new_password" class="manage-input" placeholder="กรอกรหัสผ่านใหม่...">
                          </div>
                          
                          <div class="manage-card-actions">
                              <button type="submit" class="btn-small btn-save" style="flex:1;">
                                <i class="fas fa-save"></i> บันทึก
                              </button>
                              
                              <?php if ($row['id'] != 1): ?>
                                <button type="button" class="btn-small btn-delete" style="flex:1;" 
                                        onclick="if(confirm('ยืนยันการลบแอดมิน <?= htmlspecialchars($row['username']) ?> ใช่หรือไม่?')) { document.getElementById('del_id').value='<?= $row['id'] ?>'; document.getElementById('deleteForm').submit(); }">
                                    <i class="fas fa-trash"></i> ลบ
                                </button>
                              <?php endif; ?>
                          </div>
                      </form>
                  </div>
              <?php endforeach; ?>
              
              <!-- ฟอร์มซ่อนสำหรับส่งค่า ID เพื่อลบ -->
              <form id="deleteForm" method="POST" action="?view=manage" style="display:none;">
                  <input type="hidden" name="delete_id" id="del_id" value="">
                  <input type="hidden" name="action_delete" value="1">
              </form>
          <?php endif; ?>


      <!-- ================= หน้าจอเพิ่มแอดมินใหม่ (Add) ================= -->
      <?php elseif ($view === 'add'): ?>
          <form method="POST" action="?view=add">
            <input type="hidden" name="action_add" value="1">

            <div class="form-group">
              <label>Username (บัญชีผู้ใช้)</label>
              <input type="text" name="username" class="form-control" placeholder="ตั้งค่า Username" required>
            </div>

            <div class="form-group">
              <label>Password (รหัสผ่าน)</label>
              <input type="password" name="password" class="form-control" placeholder="กำหนดรหัสผ่าน" required>
            </div>

            <div class="form-group">
              <label>ชื่อ (First Name)</label>
              <input type="text" name="first_name" class="form-control" placeholder="กรอกชื่อจริง" required>
            </div>

            <div class="form-group">
              <label>นามสกุล (Last Name)</label>
              <input type="text" name="last_name" class="form-control" placeholder="กรอกนามสกุล">
            </div>

            <button type="submit" class="submit-btn">
              <i class="fas fa-user-plus"></i> ยืนยันเพิ่มผู้ดูแลระบบ
            </button>
          </form>
      <?php endif; ?>

    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="admin_main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="admin_manage.php" class="nav-link active" style="color: var(--primary);">
        <i class="fas fa-user-shield"></i>
        <span>แอดมิน</span>
      </a>
    </div>

  </div>

</body>
</html>
