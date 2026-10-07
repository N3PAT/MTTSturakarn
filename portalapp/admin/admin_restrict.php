<?php
session_start();

// เปิดการแสดง Error ชั่วคราวกรณีมีปัญหาอื่น
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once 'db.php';

// ตรวจสอบสิทธิ์ Admin
if (empty($_SESSION['admin_id'])) {
    header("Location: admin_main.php");
    exit();
}

$message = '';
$message_type = '';
$step = 1;
$view = $_GET['view'] ?? 'add'; // 'add' หรือ 'manage'

$valid_students = []; // รายชื่อที่พบ
$not_found = [];      // รายชื่อที่ไม่พบ
$weeks = 1;
$restrict_type = 'restrict'; // ค่าเริ่มต้น: กักบริเวณ (restrict)
$raw_names = '';

/**
 * ฟังก์ชั่นค้นหารายชื่อแบบฉลาด (Smart Student Search)
 */
function searchStudent($conn, $clean_name) {
    $clean_name = trim($clean_name);
    if (empty($clean_name)) return null;

    $parts = preg_split('/\s+/', $clean_name);
    $first_part = $parts[0] ?? '';
    $second_part = $parts[1] ?? '';
    $second_clean = rtrim($second_part, '.');

    if (!empty($second_clean) && mb_strlen($second_clean, 'UTF-8') === 1) {
        $initial = $second_clean . '%';
        $stmt = $conn->prepare("SELECT student_code, first_name, last_name FROM students WHERE REPLACE(first_name, '\t', '') = ? AND last_name LIKE ? LIMIT 1");
        $stmt->bind_param("ss", $first_part, $initial);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) return $row;

        $first_base = preg_replace('/[์\x{0e4c}]/u', '', $first_part);
        if (mb_strlen($first_base, 'UTF-8') >= 3) {
            $first_prefix = mb_substr($first_base, 0, 4, 'UTF-8') . '%';
            $stmt = $conn->prepare("SELECT student_code, first_name, last_name FROM students WHERE REPLACE(first_name, '\t', '') LIKE ? AND last_name LIKE ? LIMIT 1");
            $stmt->bind_param("ss", $first_prefix, $initial);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) return $row;
        }
    }

    $search_pattern = "%" . str_replace(' ', '%', $clean_name) . "%";
    $stmt = $conn->prepare("SELECT student_code, first_name, last_name FROM students WHERE REPLACE(CONCAT(first_name, ' ', last_name), '\t', '') LIKE ? OR REPLACE(first_name, '\t', '') = ? LIMIT 1");
    $stmt->bind_param("ss", $search_pattern, $first_part);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) return $row;

    $first_base = preg_replace('/[์\x{0e4c}]/u', '', $first_part);
    if (mb_strlen($first_base, 'UTF-8') >= 3) {
        $first_prefix = mb_substr($first_base, 0, 4, 'UTF-8') . '%';
        $stmt = $conn->prepare("SELECT student_code, first_name, last_name FROM students WHERE REPLACE(first_name, '\t', '') LIKE ? LIMIT 1");
        $stmt->bind_param("s", $first_prefix);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) return $row;
    }

    return null;
}

// ---------------- จัดการ POST Request ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- โหมดจัดการ: ลบข้อมูล ---
    if (isset($_POST['action_delete'])) {
        $del_id = (int)$_POST['delete_id'];
        $stmt = $conn->prepare("DELETE FROM restrictions WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $message = "ลบข้อมูลสำเร็จ";
            $message_type = "success";
        } else {
            $message = "เกิดข้อผิดพลาดในการลบ: " . $conn->error;
            $message_type = "error";
        }
        $view = 'manage';
    }
    
    // --- โหมดจัดการ: แก้ไขข้อมูล ---
    elseif (isset($_POST['action_update'])) {
        $up_id = (int)$_POST['update_id'];
        $up_weeks = (int)$_POST['edit_weeks'];
        $up_type = $_POST['edit_type'];
        
        $stmt = $conn->prepare("UPDATE restrictions SET weeks_count = ?, restrict_type = ? WHERE id = ?");
        $stmt->bind_param("isi", $up_weeks, $up_type, $up_id);
        if ($stmt->execute()) {
            $message = "อัปเดตข้อมูลสำเร็จ";
            $message_type = "success";
        } else {
            $message = "เกิดข้อผิดพลาดในการอัปเดต: " . $conn->error;
            $message_type = "error";
        }
        $view = 'manage';
    }

    // --- โหมดเพิ่ม: Step 1 ตรวจสอบรายชื่อ ---
    elseif (isset($_POST['action_preview'])) {
        $raw_names = $_POST['names_list'] ?? '';
        $weeks = (int)($_POST['restrict_weeks'] ?? 1);
        $restrict_type = $_POST['restrict_type'] ?? 'restrict';

        if (!empty($raw_names)) {
            $lines = preg_split('/[\r\n,]+/', $raw_names);
            foreach ($lines as $line) {
                $name = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $line);
                $name = trim($name);
                if (empty($name)) continue;

                $clean_name = preg_replace('/^(\d+\.?\s*|นชท\.\s*)+/u', '', $name);
                $clean_name = trim($clean_name);
                if (empty($clean_name)) continue;

                $found_student = searchStudent($conn, $clean_name);
                if ($found_student) {
                    $valid_students[] = [
                        'code' => $found_student['student_code'],
                        'full_name' => trim($found_student['first_name']) . ' ' . trim($found_student['last_name'])
                    ];
                } else {
                    $not_found[] = $clean_name;
                }
            }
            $step = 2;
        } else {
            $message = "กรุณาวางรายชื่อที่ต้องการทำรายการ";
            $message_type = "error";
        }
    }

    // --- โหมดเพิ่ม: Step 2 ยืนยันบันทึกลง DB ---
    elseif (isset($_POST['action_save'])) {
        $student_codes = $_POST['student_codes'] ?? [];
        $weeks = (int)($_POST['weeks'] ?? 1);
        $restrict_type = $_POST['restrict_type'] ?? 'restrict';
        $success_count = 0;

        if (!empty($student_codes)) {
            try {
                $insert = $conn->prepare("INSERT INTO restrictions (student_code, weeks_count, restrict_type) VALUES (?, ?, ?)");
                foreach ($student_codes as $code) {
                    $insert->bind_param("sis", $code, $weeks, $restrict_type);
                    if ($insert->execute()) $success_count++;
                }
                
                $type_txt = ($restrict_type === 'stay') ? 'บังคับอยู่' : 'กักบริเวณ';
                $message = "บันทึกคำสั่ง $type_txt สำเร็จ จำนวน $success_count นาย ($weeks สัปดาห์)";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "ไม่มีรายชื่อให้บันทึก";
            $message_type = "error";
        }
        $step = 1;
    }
}

// โหลดข้อมูลสำหรับโหมด 'manage'
$active_restrictions = [];
if ($view === 'manage') {
    // เพิ่มการกำหนด COLLATE ตรงนี้เพื่อแก้ปัญหา Illegal mix of collations
    $res = $conn->query("
        SELECT r.id, r.student_code, s.first_name, s.last_name, r.weeks_count, r.restrict_type, r.created_at 
        FROM restrictions r
        JOIN students s ON r.student_code COLLATE utf8mb4_unicode_ci = s.student_code COLLATE utf8mb4_unicode_ci
        ORDER BY r.created_at DESC 
        LIMIT 50
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $active_restrictions[] = $row;
        }
    } else {
        $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $conn->error;
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>จัดการคำสั่ง - MTTS</title>
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
    .form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }
    
    .name-textarea { width: 100%; height: 160px; padding: 15px; border-radius: 14px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.9rem; resize: none; outline: none; }
    .name-textarea:focus, .form-control:focus { border-color: var(--primary); }
    .help-text { font-size: 0.75rem; color: var(--text-gray); margin-top: 6px; display: block; line-height: 1.4; }

    .form-control { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: 'Prompt', sans-serif; font-size: 0.95rem; background: white; outline: none; }

    .submit-btn { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 14px; font-family: 'Prompt', sans-serif; font-size: 1rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); transition: 0.2s; }
    .submit-btn:active { transform: scale(0.98); }
    .btn-secondary { background: #64748b; box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3); margin-top: 10px;}
    
    .btn-small { padding: 6px 12px; border: none; border-radius: 8px; font-family: 'Prompt'; font-size: 0.8rem; font-weight: 600; color: white; cursor: pointer; }
    .btn-save { background: #16a34a; }
    .btn-delete { background: #dc2626; }

    .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    .result-box { background: white; border-radius: 14px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
    .result-box h4 { margin: 0 0 10px 0; font-size: 0.9rem; color: var(--text-dark); border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;}
    .name-tag { display: inline-block; background: #f8fafc; color: #0f172a; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; margin: 0 6px 8px 0; border: 1px solid #e2e8f0; font-weight: 500;}
    .name-tag.error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .name-tag.success { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }

    /* Manage List Styles */
    .manage-card { background: white; border-radius: 12px; padding: 15px; margin-bottom: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; }
    .manage-card-header { display: flex; justify-content: space-between; margin-bottom: 10px; font-weight: 600; font-size: 0.95rem; }
    .manage-card-actions { display: flex; gap: 8px; margin-top: 10px; }
    .manage-select { padding: 6px; border-radius: 6px; border: 1px solid #cbd5e1; font-family: 'Prompt'; font-size: 0.85rem; width: 100%; margin-bottom: 8px;}

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
        <h1>จัดการคำสั่ง (กักบริเวณ/บังคับอยู่)</h1>
      </div>
    </div>

    <!-- แถบเมนูย่อย -->
    <div class="tabs-container">
        <a href="?view=add" class="tab-btn <?= $view === 'add' ? 'active' : '' ?>"><i class="fas fa-plus"></i> เพิ่มคำสั่ง</a>
        <a href="?view=manage" class="tab-btn <?= $view === 'manage' ? 'active' : '' ?>"><i class="fas fa-list"></i> แก้ไข/ลบ ข้อมูล</a>
    </div>

    <div class="form-section">
      
      <?php if (!empty($message)): ?>
        <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
          <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
          <?= htmlspecialchars($message) ?>
        </div>
      <?php endif; ?>

      <!-- ================= หน้าจอเพิ่มข้อมูล (Add) ================= -->
      <?php if ($view === 'add'): ?>

          <?php if ($step === 1): ?>
          <!-- ขั้นตอนที่ 1: ฟอร์มกรอกข้อมูล -->
          <form method="POST" action="">
            <div class="form-group">
              <label>วางรายชื่อ (บรรทัดละ 1 คน)</label>
              <textarea name="names_list" class="name-textarea" placeholder="ตัวอย่าง:&#10;นชท.ศักรพัฒน ดวงอินทร์&#10;1.ธนเดช น." required><?= htmlspecialchars($raw_names) ?></textarea>
              <span class="help-text">* รองรับคำว่า นชท., ตัวเลขลำดับ และอักษรย่อนามสกุล</span>
            </div>

            <div class="form-group">
              <label>ประเภทคำสั่ง</label>
              <select name="restrict_type" class="form-control">
                <option value="restrict" <?= $restrict_type == 'restrict' ? 'selected' : '' ?>>กักบริเวณ</option>
                <option value="stay" <?= $restrict_type == 'stay' ? 'selected' : '' ?>>บังคับอยู่</option>
              </select>
            </div>

            <div class="form-group">
              <label>จำนวนสัปดาห์</label>
              <select name="restrict_weeks" class="form-control">
                <?php for($i=1; $i<=30; $i++): ?>
                  <option value="<?= $i ?>" <?= $weeks == $i ? 'selected' : '' ?>><?= $i ?> สัปดาห์</option>
                <?php endfor; ?>
              </select>
            </div>

            <button type="submit" name="action_preview" class="submit-btn">
              <i class="fas fa-search"></i> ตรวจสอบรายชื่อ
            </button>
          </form>
          
          <?php elseif ($step === 2): ?>
          <!-- ขั้นตอนที่ 2: แสดงตัวอย่างและยืนยัน -->
          <form method="POST" action="">
            <input type="hidden" name="weeks" value="<?= $weeks ?>">
            <input type="hidden" name="restrict_type" value="<?= htmlspecialchars($restrict_type) ?>">

            <div class="result-box" style="border-color: #bbf7d0;">
              <h4>
                <i class="fas fa-user-check" style="color: #166534;"></i> 
                ยืนยันการบันทึก: <?= $restrict_type === 'restrict' ? 'กักบริเวณ' : 'บังคับอยู่' ?> <?= $weeks ?> สัปดาห์
              </h4>
              <?php if (count($valid_students) > 0): ?>
                <?php foreach ($valid_students as $student): ?>
                  <div class="name-tag success">
                    <?= htmlspecialchars($student['full_name']) ?>
                    <input type="hidden" name="student_codes[]" value="<?= htmlspecialchars($student['code']) ?>">
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p style="font-size: 0.85rem; color: var(--text-gray);">ไม่พบรายชื่อที่ตรงกับฐานข้อมูลเลย</p>
              <?php endif; ?>
            </div>

            <?php if (count($not_found) > 0): ?>
              <div class="result-box" style="border-color: #fecaca; background: #fffcfc;">
                <h4><i class="fas fa-exclamation-triangle" style="color: #991b1b;"></i> รายชื่อที่ไม่พบ (จะไม่ถูกบันทึก)</h4>
                <?php foreach ($not_found as $n): ?>
                  <div class="name-tag error"><?= htmlspecialchars($n) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (count($valid_students) > 0): ?>
              <button type="submit" name="action_save" class="submit-btn">
                <i class="fas fa-save"></i> ยืนยันการบันทึกข้อมูล
              </button>
            <?php endif; ?>
            
            <a href="?view=add" style="text-decoration: none;">
                <button type="button" class="submit-btn btn-secondary">
                <i class="fas fa-arrow-left"></i> กลับไปแก้ไขรายชื่อ
                </button>
            </a>
          </form>
          <?php endif; ?>


      <!-- ================= หน้าจอแก้ไข/ลบข้อมูล (Manage) ================= -->
      <?php elseif ($view === 'manage'): ?>
          <div style="margin-bottom: 15px; font-size: 0.9rem; color: var(--text-gray);">
            แสดงข้อมูล 50 รายการล่าสุด
          </div>

          <?php if (empty($active_restrictions)): ?>
            <div style="text-align:center; padding: 30px; color: #94a3b8;">
               ไม่มีประวัติคำสั่งในระบบ
            </div>
          <?php else: ?>
              <?php foreach ($active_restrictions as $row): ?>
                  <div class="manage-card">
                      <div class="manage-card-header">
                          <span><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?> (<?= htmlspecialchars($row['student_code']) ?>)</span>
                      </div>
                      
                      <form method="POST" action="?view=manage">
                          <input type="hidden" name="update_id" value="<?= $row['id'] ?>">
                          
                          <div style="display:flex; gap: 10px;">
                              <div style="flex:1;">
                                  <label style="font-size: 0.75rem; color: var(--text-gray);">ประเภทคำสั่ง</label>
                                  <select name="edit_type" class="manage-select">
                                      <option value="restrict" <?= ($row['restrict_type'] ?? '') == 'restrict' ? 'selected' : '' ?>>กักบริเวณ</option>
                                      <option value="stay" <?= ($row['restrict_type'] ?? '') == 'stay' ? 'selected' : '' ?>>บังคับอยู่</option>
                                  </select>
                              </div>
                             <div style="flex:1;">
                                  <label style="font-size: 0.75rem; color: var(--text-gray);">สัปดาห์</label>
                                  <select name="edit_weeks" class="manage-select">
                                      <?php for($i=1; $i<=30; $i++): ?>
                                          <option value="<?= $i ?>" <?= $row['weeks_count'] == $i ? 'selected' : '' ?>><?= $i ?> สัปดาห์</option>
                                      <?php endfor; ?>
                                  </select>
                              </div>
                          </div>
                          
                          <div class="manage-card-actions">
                              <button type="submit" name="action_update" class="btn-small btn-save" style="flex:1;"><i class="fas fa-save"></i> บันทึก</button>
                              
                              <button type="button" class="btn-small btn-delete" style="flex:1;" 
        onclick="if(confirm('ยืนยันการลบข้อมูลนี้ใช่หรือไม่?')) { document.getElementById('del_id').value='<?= $row['id'] ?>'; document.getElementById('deleteForm').submit(); }">
    <i class="fas fa-trash"></i> ลบ
</button>
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

      <?php endif; ?>

    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="admin_main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="admin_restrict.php" class="nav-link active" style="color: var(--primary);">
        <i class="fas fa-ban"></i>
        <span>คำสั่ง</span>
      </a>
      
    </div>

  </div>

</body>
</html>
