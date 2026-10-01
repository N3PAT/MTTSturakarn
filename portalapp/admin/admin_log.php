<?php
session_start();
require_once 'db.php';

// ตรวจสอบการเข้าสู่ระบบ
if (empty($_SESSION['admin_id'])) {
    header("Location: turakarn.php");
    exit();
}

$studentCode =$_SESSION['admin_id'];

// ------------------------------------------------------------------
// ระบบควบคุมสถานะ (Toggle Settings)
// ------------------------------------------------------------------
if (isset($_GET['action']) &&$_GET['action'] == 'toggle' && isset($_GET['setting']) && isset($_GET['value'])) {
    $setting_key =$_GET['setting'];
    $new_value = intval($_GET['value']);
    try {
        // อัปเดตสถานะในฐานข้อมูล (ต้องมีตาราง system_settings)
         $stmt =$conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
         $stmt->bind_param("is", $new_value,$setting_key);
         $stmt->execute();$stmt->close();
        header("Location: admin_log.php?msg=toggled");
        exit();
    } catch (Exception $e) { }
}

// ------------------------------------------------------------------
// ระบบจัดการข้อมูล (เพิ่ม)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) &&$_POST['action'] == 'add') {
    $title =$_POST['title'];
    $message =$_POST['message'];
    $type =$_POST['type'];
    
    try {
        $stmt =$conn->prepare("INSERT INTO notifications (title, message, type) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $title, $message,$type);
        $stmt->execute();$stmt->close();
        header("Location: admin_log.php?msg=added");
        exit();
    } catch (Exception $e) { }
}

// ------------------------------------------------------------------
// ระบบจัดการข้อมูล (ลบ)
// ------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    try {
        $stmt =$conn->prepare("DELETE FROM notifications WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();$stmt->close();
        header("Location: admin_log.php?msg=deleted");
        exit();
    } catch (Exception $e) { }
}

// ------------------------------------------------------------------
// ระบบจัดการข้อมูล (แก้ไข)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) &&$_POST['action'] == 'edit') {
    $edit_id = intval($_POST['edit_id']);
    $new_title =$_POST['title'];
    $new_message =$_POST['message'];
    $new_type =$_POST['type'];
    
    try {
        $stmt =$conn->prepare("UPDATE notifications SET title = ?, message = ?, type = ? WHERE id = ?");
        $stmt->bind_param("sssi", $new_title,$new_message, $new_type,$edit_id);
        $stmt->execute();$stmt->close();
        header("Location: admin_log.php?msg=updated");
        exit();
    } catch (Exception $e) { }
}

// ------------------------------------------------------------------
// ระบบ Export Database (ดาวน์โหลดไฟล์ .sql)
// ------------------------------------------------------------------
if (isset($_GET['export']) &&$_GET['export'] == 'sql') {
    error_reporting(0);
    $filename = "mtts_backup_" . date("Y-m-d_H-i-s") . ".sql";
    header("Content-Type: application/sql; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "-- MTTS Database Backup\n";
    echo "-- Date: " . date("Y-m-d H:i:s") . "\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+07:00\";\n\n";

    $tables = [];
    $result =$conn->query("SHOW TABLES");
    while ($row =$result->fetch_row()) {
        $tables[] =$row[0];
    }

    foreach ($tables as $table) {$result = $conn->query("SELECT * FROM $table");
        $num_fields =$result->field_count;

        $row2 =$conn->query("SHOW CREATE TABLE $table")->fetch_row();
        echo "\n\n-- Structure for table `$table`\n";
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $row2[1] . ";\n\n";

        if ($result->num_rows > 0) {
            echo "-- Data for table `$table`\n";
            while ($row =$result->fetch_row()) {
                echo "INSERT INTO `$table` VALUES(";
                for ($j = 0; $j < $num_fields; $j++) {
                    if (isset($row[$j])) {
                        $escaped_val =$conn->real_escape_string($row[$j]);
                        echo "'" . $escaped_val . "'";
                    } else {
                        echo "NULL";
                    }
                    if ($j < ($num_fields - 1)) {
                        echo ", ";
                    }
                }
                echo ");\n";
            }
        }
    }
    exit();
}

// ------------------------------------------------------------------
// ดึงข้อมูล Log ทั้งหมด
// ------------------------------------------------------------------
$logs = [];
try {
    $stmt =$conn->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 50");
    $stmt->execute();
    $result =$stmt->get_result();
    while ($row =$result->fetch_assoc()) {
        $logs[] =$row;
    }
    $stmt->close();
} catch (Exception $e) {$logs = []; }
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>จัดการระบบ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root { --primary: #5a42a0; --bg-color: #f6f8fc; --text-dark: #1e293b; --text-gray: #64748b; }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }

    .app-container {
      width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh;
      position: relative; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }

    .top-header { display: flex; align-items: center; padding: 15px 20px; background: var(--bg-color); gap: 15px; }
    .btn-back { color: var(--text-dark); font-size: 1.2rem; text-decoration: none; }
    .header-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* Action Panel */
    .action-panel { padding: 0 20px; margin-bottom: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .btn-panel {
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
      color: white; text-decoration: none; padding: 15px; border-radius: 12px; font-size: 0.9rem; font-weight: 600;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1); transition: transform 0.2s; cursor: pointer; border: none; font-family: 'Prompt', sans-serif;
    }
    .btn-panel:active { transform: scale(0.96); }
    .btn-export { background: linear-gradient(135deg, #10b981, #059669); }
    .btn-add { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    .btn-panel i { font-size: 1.5rem; }

    /* System Toggles */
    .control-card { background: white; border-radius: 12px; padding: 15px; margin: 0 20px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px dashed #e2e8f0; }
    .toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
    .toggle-label { font-size: 0.95rem; font-weight: 500; color: var(--text-dark); display: flex; align-items: center; gap: 10px; }
    .switch { position: relative; display: inline-block; width: 46px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 24px; }
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: var(--primary); }
    input:checked + .slider:before { transform: translateX(22px); }

    /* Log List */
    .section-title { display: flex; align-items: center; justify-content: space-between; margin: 10px 20px 15px; }
    .title-left { display: flex; align-items: center; gap: 10px; }
    .section-pill { width: 5px; height: 24px; background: var(--primary); border-radius: 4px; }
    .section-title h3 { font-size: 1.05rem; font-weight: 600; color: var(--text-dark); margin: 0; }

    .log-list { padding: 0 20px; display: flex; flex-direction: column; gap: 10px; }
    .log-card {
      background: white; border-radius: 12px; padding: 15px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .log-header { display: flex; justify-content: space-between; align-items: flex-start; }
    .log-type { font-weight: 600; font-size: 0.95rem; color: var(--primary); }
    .log-status { font-size: 0.75rem; padding: 3px 8px; border-radius: 20px; font-weight: 500; background: #e2e8f0; color: #475569; }
    .log-details { font-size: 0.85rem; color: var(--text-gray); display: flex; flex-direction: column; gap: 4px; }
    
    .log-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e2e8f0; }
    .btn-action {
      border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 500;
      font-family: 'Prompt', sans-serif; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: opacity 0.2s;
    }
    .btn-action:active { opacity: 0.7; }
    .btn-edit { background-color: #f59e0b; color: white; }
    .btn-delete { background-color: #ef4444; color: white; text-decoration: none; }

    .empty-state { text-align: center; color: var(--text-gray); font-size: 0.9rem; padding: 20px; }

    /* Modal Styles */
    .modal-overlay {
      display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0, 0, 0, 0.5); z-index: 2000; justify-content: center; align-items: center;
    }
    .modal-content {
      background: white; width: 90%; max-width: 380px; border-radius: 16px; padding: 20px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.1); transform: translateY(20px); opacity: 0; transition: all 0.3s ease;
    }
    .modal-content.show { transform: translateY(0); opacity: 1; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
    .modal-header h3 { margin: 0; font-size: 1.1rem; color: var(--text-dark); }
    .close-modal { background: none; border: none; font-size: 1.2rem; color: var(--text-gray); cursor: pointer; }
    
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-size: 0.85rem; color: var(--text-dark); margin-bottom: 5px; font-weight: 500; }
    .form-control { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: 'Prompt', sans-serif; font-size: 0.9rem; }
    .form-control:focus { outline: none; border-color: var(--primary); }
    .btn-submit { width: 100%; padding: 12px; border: none; background: var(--primary); color: white; border-radius: 8px; font-weight: 600; font-family: 'Prompt', sans-serif; cursor: pointer; }

    .bottom-nav {
      position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); 
      height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; 
      display: flex; justify-content: space-around; align-items: center; 
      box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; 
    }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.3rem; }
  </style>
</head>
<body>

  <div class="app-container">
    
    <div class="top-header fade-in-up delay-1">
      <a href="main.php" class="btn-back"><i class="fas fa-arrow-left"></i></a>
      <div class="header-title">
        <h1>แผงควบคุมระบบ (Admin)</h1>
      </div>
    </div>

    <!-- ปุ่ม Export และ Add -->
    <div class="action-panel fade-in-up delay-1">
      <button class="btn-panel btn-add" onclick="openAddModal()">
        <i class="fas fa-plus-circle"></i> แจ้งเตือนใหม่
      </button>
      <a href="?export=sql" class="btn-panel btn-export">
        <i class="fas fa-database"></i> สำรองข้อมูล
      </a>
    </div>

    <!-- สวิตช์ควบคุมระบบ -->
    <div class="section-title fade-in-up delay-1">
      <div class="title-left">
        <div class="section-pill"></div>
        <h3>ควบคุมฟังก์ชันระบบ</h3>
      </div>
    </div>
    <div class="control-card fade-in-up delay-1">
      <div class="toggle-row">
        <span class="toggle-label"><i class="fas fa-vote-yea" style="color:var(--primary);"></i> ระบบโหวต (Vote)</span>
        <label class="switch">
          <input type="checkbox" checked onchange="toggleSetting('vote_system', this.checked)">
          <span class="slider"></span>
        </label>
      </div>
      <div class="toggle-row">
        <span class="toggle-label"><i class="fas fa-file-alt" style="color:#f59e0b;"></i> ระบบคำร้อง (Reqdoc)</span>
        <label class="switch">
          <input type="checkbox" checked onchange="toggleSetting('req_system', this.checked)">
          <span class="slider"></span>
        </label>
      </div>
      <div class="toggle-row">
        <span class="toggle-label"><i class="fas fa-tools" style="color:#ef4444;"></i> โหมดปรับปรุง (Maintenance)</span>
        <label class="switch">
          <input type="checkbox" onchange="toggleSetting('maintenance_mode', this.checked)">
          <span class="slider"></span>
        </label>
      </div>
    </div>

    <div class="section-title fade-in-up delay-2">
      <div class="title-left">
        <div class="section-pill"></div>
        <h3>การแจ้งเตือน / Log ล่าสุด</h3>
      </div>
      <span style="font-size: 0.8rem; color: var(--text-gray);">รวม <?= count($logs) ?> รายการ</span>
    </div>

    <!-- รายการ Log -->
    <div class="log-list fade-in-up delay-2">
      <?php if (empty($logs)): ?>
        <div class="empty-state">
          <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i><br>
          ยังไม่มีข้อมูลบันทึกระบบ
        </div>
      <?php else: ?>
        <?php foreach ($logs as$log): ?>
          <?php 
            $date = date("d/m/Y H:i", strtotime($log['created_at']));
          ?>
          <div class="log-card">
            <div class="log-header">
              <span class="log-type"><?= htmlspecialchars($log['title']) ?></span>
              <span class="log-status"><?= htmlspecialchars($log['type']) ?></span>
            </div>
            <div class="log-details">
              <div><i class="fas fa-align-left"></i> <?= htmlspecialchars($log['message']) ?></div>
              <div><i class="far fa-clock"></i> <?= $date ?></div>
            </div>
            <div class="log-actions">
              <button type="button" class="btn-action btn-edit" 
                      onclick="openEditModal(<?= $log['id'] ?>, '<?= htmlspecialchars(addslashes($log['title'])) ?>', '<?= htmlspecialchars(addslashes($log['message'])) ?>', '<?=$log['type'] ?>')">
                <i class="fas fa-edit"></i> แก้ไข
              </button>
              <a href="?action=delete&id=<?= $log['id'] ?>" class="btn-action btn-delete" onclick="return confirm('ยืนยันการลบข้อมูลนี้หรือไม่?');">
                <i class="fas fa-trash"></i> ลบ
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i><span>หน้าหลัก</span>
      </a>
      <a href="admin_log.php" class="nav-link active">
        <i class="fas fa-sliders-h"></i><span>จัดการระบบ</span>
      </a>
      <a href="reqdoc.php" class="nav-link">
        <i class="fas fa-vote-yea"></i><span>คำร้อง</span>
      </a>
    </div>
  </div>

  <!-- Modal สำหรับเพิ่ม/แก้ไขข้อมูล -->
  <div class="modal-overlay" id="formModal">
    <div class="modal-content" id="modalContent">
      <div class="modal-header">
        <h3 id="modalTitle">เพิ่มข้อมูลใหม่</h3>
        <button class="close-modal" onclick="closeModal()"><i class="fas fa-times"></i></button>
      </div>
      <form action="" method="POST" id="adminForm">
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="edit_id" id="edit_id">
        
        <div class="form-group">
          <label>หัวข้อ (Title)</label>
          <input type="text" name="title" id="input_title" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label>รายละเอียด (Message)</label>
          <textarea name="message" id="input_message" class="form-control" rows="3" required></textarea>
        </div>
        
        <div class="form-group">
          <label>ประเภท (Type)</label>
          <select name="type" id="input_type" class="form-control">
            <option value="system">System</option>
            <option value="news">News</option>
            <option value="duty">Duty</option>
            <option value="vote">Vote</option>
          </select>
        </div>
        
        <button type="submit" class="btn-submit" id="submitBtn">บันทึกข้อมูล</button>
      </form>
    </div>
  </div>

  <script>
    function toggleSetting(settingName, isChecked) {
      // ส่งค่าการเปิดปิดสวิตช์ไปยัง Backend
      const value = isChecked ? 1 : 0;
      window.location.href = `?action=toggle&setting=${settingName}&value=${value}`;
    }

    function openAddModal() {
      document.getElementById('modalTitle').innerText = 'สร้างการแจ้งเตือนใหม่';
      document.getElementById('formAction').value = 'add';
      document.getElementById('edit_id').value = '';
      document.getElementById('input_title').value = '';
      document.getElementById('input_message').value = '';
      document.getElementById('input_type').value = 'system';
      document.getElementById('submitBtn').innerText = 'เพิ่มข้อมูล';
      
      showModal();
    }

    function openEditModal(id, title, message, type) {
      document.getElementById('modalTitle').innerText = 'แก้ไขข้อมูล';
      document.getElementById('formAction').value = 'edit';
      document.getElementById('edit_id').value = id;
      document.getElementById('input_title').value = title;
      document.getElementById('input_message').value = message;
      document.getElementById('input_type').value = type;
      document.getElementById('submitBtn').innerText = 'บันทึกการแก้ไข';
      
      showModal();
    }

    function showModal() {
      const modal = document.getElementById('formModal');
      const content = document.getElementById('modalContent');
      modal.style.display = 'flex';
      setTimeout(() => { content.classList.add('show'); }, 10);
    }

    function closeModal() {
      const modal = document.getElementById('formModal');
      const content = document.getElementById('modalContent');
      content.classList.remove('show');
      setTimeout(() => { modal.style.display = 'none'; }, 300);
    }
  </script>
</body>
</html>
