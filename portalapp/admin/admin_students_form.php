<?php
session_start();
require_once 'db.php';

// สมมติว่าตรวจสอบสิทธิ์ Admin แล้ว
// if (empty($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit = $id > 0;

// ค่าเริ่มต้นสำหรับฟอร์ม (กรณีเพิ่มใหม่)
$student = [
    'student_code' => '', 'id_card' => '', 'prefix' => 'นตท.', 
    'first_name' => '', 'last_name' => '', 'battalion' => '1', 
    'company' => '1', 'platoon' => '1', 'section' => '1'
];

// ถ้าเป็นการแก้ไข ให้ดึงข้อมูลเดิมมาแสดง
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $student = $row;
    }
    $stmt->close();
}

// จัดการเมื่อกดปุ่มบันทึก (Submit Form)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_code = $_POST['student_code'];
    $id_card = $_POST['id_card'];
    $prefix = $_POST['prefix'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $battalion = $_POST['battalion'];
    $company = $_POST['company'];
    $platoon = $_POST['platoon'];
    $section = $_POST['section'];
    $password = $_POST['password']; // หากใช้ระบบจริง ควรครอบด้วย password_hash()

    if ($is_edit) {
        // อัปเดตข้อมูล (เช็คว่ามีการเปลี่ยนรหัสผ่านหรือไม่)
        if (!empty($password)) {
            $stmt = $conn->prepare("UPDATE students SET student_code=?, id_card=?, prefix=?, first_name=?, last_name=?, battalion=?, company=?, platoon=?, section=?, password=? WHERE id=?");
            $stmt->bind_param("ssssssssssi", $student_code, $id_card, $prefix, $first_name, $last_name, $battalion, $company, $platoon, $section, $password, $id);
        } else {
            $stmt = $conn->prepare("UPDATE students SET student_code=?, id_card=?, prefix=?, first_name=?, last_name=?, battalion=?, company=?, platoon=?, section=? WHERE id=?");
            $stmt->bind_param("sssssssssi", $student_code, $id_card, $prefix, $first_name, $last_name, $battalion, $company, $platoon, $section, $id);
        }
    } else {
        // เพิ่มข้อมูลใหม่
        $stmt = $conn->prepare("INSERT INTO students (student_code, id_card, prefix, first_name, last_name, battalion, company, platoon, section, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssss", $student_code, $id_card, $prefix, $first_name, $last_name, $battalion, $company, $platoon, $section, $password);
    }
    
    if ($stmt->execute()) {
        header("Location: admin_students.php");
        exit();
    } else {
        $error = "เกิดข้อผิดพลาด: " . $conn->error;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title><?= $is_edit ? 'แก้ไขข้อมูลนักเรียน' : 'เพิ่มนักเรียนใหม่' ?> - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #5a42a0;
      --bg-color: #f6f8fc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; user-select: none; }

    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; position: relative; padding-bottom: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }

    .top-header { display: flex; align-items: center; gap: 15px; padding: 20px; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.02); position: sticky; top: 0; z-index: 10; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    .form-container { padding: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
    .form-group input, .form-group select { width: 100%; padding: 12px 15px; border: 1px solid #cbd5e1; border-radius: 12px; font-family: 'Prompt', sans-serif; font-size: 0.95rem; outline: none; transition: border-color 0.2s; background: white; }
    .form-group input:focus, .form-group select:focus { border-color: var(--primary); }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

    .btn-save { width: 100%; background: var(--primary); color: white; border: none; padding: 14px; border-radius: 14px; font-family: 'Prompt', sans-serif; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 10px; box-shadow: 0 4px 12px rgba(90, 66, 160, 0.2); }
    .btn-save:active { transform: scale(0.98); }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.4s forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body>
  <div class="app-container">
    
    <div class="top-header">
      <a href="admin_students.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1><?= $is_edit ? 'แก้ไขข้อมูลนักเรียน' : 'เพิ่มนักเรียนใหม่' ?></h1>
      </div>
    </div>

    <form method="POST" class="form-container fade-in-up">
      <?php if(isset($error)): ?>
        <div style="background: #fef2f2; color: #ef4444; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 0.85rem;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label>รหัสนักเรียน (10 หลัก)</label>
        <input type="text" name="student_code" value="<?= htmlspecialchars($student['student_code']) ?>" required maxlength="10">
      </div>

      <div class="form-group">
        <label>รหัสประจำตัวประชาชน</label>
        <input type="text" name="id_card" value="<?= htmlspecialchars($student['id_card']) ?>" maxlength="13">
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>คำนำหน้า</label>
          <input type="text" name="prefix" value="<?= htmlspecialchars($student['prefix']) ?>" required>
        </div>
        <div class="form-group">
          <label>ชื่อ</label>
          <input type="text" name="first_name" value="<?= htmlspecialchars($student['first_name']) ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label>นามสกุล</label>
        <input type="text" name="last_name" value="<?= htmlspecialchars($student['last_name']) ?>" required>
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>กองพัน</label>
          <input type="text" name="battalion" value="<?= htmlspecialchars($student['battalion']) ?>">
        </div>
        <div class="form-group">
          <label>กองร้อย</label>
          <input type="text" name="company" value="<?= htmlspecialchars($student['company']) ?>">
        </div>
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>หมวด</label>
          <input type="text" name="platoon" value="<?= htmlspecialchars($student['platoon']) ?>">
        </div>
        <div class="form-group">
          <label>ตอนเรียน</label>
          <input type="text" name="section" value="<?= htmlspecialchars($student['section']) ?>">
        </div>
      </div>

      <div class="form-group">
        <label>รหัสผ่าน <?= $is_edit ? '<span style="color:#94a3b8; font-size:0.75rem;">(เว้นว่างไว้หากไม่ต้องการเปลี่ยน)</span>' : '' ?></label>
        <input type="password" name="password" <?= $is_edit ? '' : 'required' ?>>
      </div>

      <button type="submit" class="btn-save"><i class="fas fa-save"></i> บันทึกข้อมูล</button>
    </form>

  </div>
</body>
</html>
