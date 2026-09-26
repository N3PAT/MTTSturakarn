<?php
session_start();

// เปิดการแสดง Error
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// 🛡️ ดักจับ Error และ Exception ทั้งหมดเพื่อป้องกันหน้า HTTP ERROR 500 บน Render
set_exception_handler(function($e) {
    http_response_code(200); // ส่ง Status 200 เพื่อไม่ให้ Render แสดงหน้า Generic HTTP 500
    echo "<div style='padding:20px; background:#fee2e2; color:#991b1b; font-family:sans-serif; border:1px solid #fecaca; margin:20px; border-radius:10px;'>";
    echo "<h3 style='margin-top:0;'>⚠️ เกิดข้อผิดพลาดในระบบ (System Error)</h3>";
    echo "<p><strong>รายละเอียด:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>ไฟล์:</strong> " . htmlspecialchars($e->getFile()) . " (บรรทัดที่ " . $e->getLine() . ")</p>";
    echo "<hr><a href='admin_restrict.php' style='color:#991b1b; font-weight:bold;'>🔄 คลิกที่นี่เพื่อลองใหม่อีกครั้ง</a>";
    echo "</div>";
    exit();
});

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
 * ฟังก์ชั่นช่วยดึงผลลัพธ์จาก Statement อย่างปลอดภัย รองรับทุกเวอร์ชัน PHP
 */
function fetchAssocSafe($stmt) {
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    } else {
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $meta = $stmt->result_metadata();
            $fields = [];
            $row = [];
            while ($field = $meta->fetch_field()) {
                $fields[] = &$row[$field->name];
            }
            call_user_func_array([$stmt, 'bind_result'], $fields);
            if ($stmt->fetch()) {
                $c = [];
                foreach ($row as $k => $v) { $c[$k] = $v; }
                return $c;
            }
        }
        return null;
    }
}

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
        if ($stmt) {
            $stmt->bind_param("ss", $first_part, $initial);
            $stmt->execute();
            $res = fetchAssocSafe($stmt);
            $stmt->close();
            if ($res) return $res;
        }

        $first_base = preg_replace('/[์\x{0e4c}]/u', '', $first_part);
        if (mb_strlen($first_base, 'UTF-8') >= 3) {
            $first_prefix = mb_substr($first_base, 0, 4, 'UTF-8') . '%';
            $stmt = $conn->prepare("SELECT student_code, first_name, last_name FROM students WHERE REPLACE(first_name, '\t', '') LIKE ? AND last_name LIKE ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ss", $first_prefix, $initial);
                $stmt->execute();
                $res = fetchAssocSafe($stmt);
                $stmt->close();
                if ($res) return $res;
            }
        }
    }

    $search_pattern = "%" . str_replace(' ', '%', $clean_name) . "%";
    $stmt = $conn->prepare("SELECT student_code, first_name, last_name FROM students WHERE REPLACE(CONCAT(first_name, ' ', last_name), '\t', '') LIKE ? OR REPLACE(first_name, '\t', '') = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ss", $search_pattern, $first_part);
        $stmt->execute();
        $res = fetchAssocSafe($stmt);
        $stmt->close();
        if ($res) return $res;
    }

    $first_base = preg_replace('/[์\x{0e4c}]/u', '', $first_part);
    if (mb_strlen($first_base, 'UTF-8') >= 3) {
        $first_prefix = mb_substr($first_base, 0, 4, 'UTF-8') . '%';
        $stmt = $conn->prepare("SELECT student_code, first_name, last_name FROM students WHERE REPLACE(first_name, '\t', '') LIKE ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $first_prefix);
            $stmt->execute();
            $res = fetchAssocSafe($stmt);
            $stmt->close();
            if ($res) return $res;
        }
    }

    return null;
}

// ---------------- จัดการ POST Request ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- โหมดจัดการ: ลบข้อมูล ---
    if (isset($_POST['action_delete'])) {
        $del_id = (int)$_POST['delete_id'];
        $stmt = $conn->prepare("DELETE FROM restrictions WHERE id = ?");
        if (!$stmt) throw new Exception("เกิดข้อผิดพลาดในการเตรียมคำสั่งลบ: " . $conn->error);
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $message = "ลบข้อมูลเรียบร้อยแล้ว";
            $message_type = "success";
        } else {
            $message = "เกิดข้อผิดพลาดในการลบ: " . $stmt->error;
            $message_type = "error";
        }
        $stmt->close();
        $view = 'manage';
    }
    
    // --- โหมดจัดการ: แก้ไขข้อมูล ---
    elseif (isset($_POST['action_update'])) {
        $up_id = (int)$_POST['update_id'];
        $up_weeks = (int)$_POST['edit_weeks'];
        $up_type = $_POST['edit_type'] ?? 'restrict';
        
        $stmt = $conn->prepare("UPDATE restrictions SET weeks_count = ?, restrict_type = ? WHERE id = ?");
        if (!$stmt) throw new Exception("เกิดข้อผิดพลาดในการเตรียมคำสั่งแก้ไข: " . $conn->error);
        $stmt->bind_param("isi", $up_weeks, $up_type, $up_id);
        if ($stmt->execute()) {
            $message = "อัปเดตข้อมูลสำเร็จ";
            $message_type = "success";
        } else {
            $message = "เกิดข้อผิดพลาดในการอัปเดต: " . $stmt->error;
            $message_type = "error";
        }
        $stmt->close();
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
            $message = "กรุณาใส่รายชื่อที่ต้องการทำรายการ";
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
            $insert = $conn->prepare("INSERT INTO restrictions (student_code, weeks_count, restrict_type) VALUES (?, ?, ?)");
            if (!$insert) throw new Exception("เกิดข้อผิดพลาดในการเตรียมบันทึกข้อมูล: " . $conn->error);
            foreach ($student_codes as $code) {
                $insert->bind_param("sis", $code, $weeks, $restrict_type);
                if ($insert->execute()) $success_count++;
            }
            $insert->close();
            
            $type_txt = ($restrict_type === 'stay') ? 'บังคับอยู่' : 'กักบริเวณ';
            $message = "บันทึกคำสั่ง $type_txt สำเร็จ จำนวน $success_count นาย ($weeks สัปดาห์)";
            $message_type = "success";
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
    $res = $conn->query("
        SELECT r.id, r.student_code, COALESCE(s.first_name, 'ไม่พบชื่อ') AS first_name, COALESCE(s.last_name, '') AS last_name, r.weeks_count, COALESCE(r.restrict_type, 'restrict') AS restrict_type, r.created_at 
        FROM restrictions r
        LEFT JOIN students s ON r.student_code = s.student_code
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการคำสั่งกักบริเวณ/บังคับอยู่ - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-light: #3b82f6;
            --danger: #dc2626;
            --warning: #d97706;
            --success: #16a34a;
            --bg-slate: #f8fafc;
            --border-slate: #e2e8f0;
        }
        body { font-family: 'Sarabun', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: var(--bg-slate); margin: 0; padding: 20px; color: #1e293b; }
        .container { max-width: 900px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 25px; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-slate); padding-bottom: 15px; margin-bottom: 20px; }
        .header h2 { margin: 0; color: var(--primary); font-size: 1.5rem; display: flex; align-items: center; gap: 10px; }
        
        .nav-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--border-slate); padding-bottom: 10px; }
        .tab-btn { text-decoration: none; padding: 8px 16px; border-radius: 6px; font-weight: 500; color: #64748b; background: #f1f5f9; transition: all 0.2s; }
        .tab-btn.active { background: var(--primary); color: white; }
        .tab-btn:hover:not(.active) { background: #e2e8f0; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .alert-success { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 1rem; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: var(--primary-light); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
        
        .radio-group { display: flex; gap: 20px; margin-top: 5px; }
        .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; }
        .radio-label input[type="radio"] { width: 18px; height: 18px; }

        .btn { padding: 10px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem; transition: background 0.2s; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #15803d; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-danger { background: var(--danger); color: white; }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-slate); }
        .table th { background: #f8fafc; font-weight: 600; color: #475569; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: inline-block; }
        .badge-restrict { background-color: #fee2e2; color: #991b1b; }
        .badge-stay { background-color: #fef3c7; color: #92400e; }

        .btn-small { padding: 5px 10px; font-size: 0.85rem; border-radius: 4px; }
        .btn-edit { background: #f59e0b; color: white; border: none; cursor: pointer; }
        .btn-delete { background: var(--danger); color: white; border: none; cursor: pointer; }

        /* Modal Styles */
        .modal { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; padding: 25px; border-radius: 12px; max-width: 450px; width: 90%; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h2><i class="fas fa-user-slash"></i> ระบบจัดการคำสั่งกักบริเวณ / บังคับอยู่</h2>
        <a href="admin_main.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> กลับหน้าหลัก</a>
    </div>

    <!-- แถบเมนูเปลี่ยนหน้า -->
    <div class="nav-tabs">
        <a href="admin_restrict.php?view=add" class="tab-btn <?= $view === 'add' ? 'active' : '' ?>">
            <i class="fas fa-plus-circle"></i> เพิ่มคำสั่งใหม่
        </a>
        <a href="admin_restrict.php?view=manage" class="tab-btn <?= $view === 'manage' ? 'active' : '' ?>">
            <i class="fas fa-list"></i> จัดการรายการคำสั่งที่มีอยู่
        </a>
    </div>

    <!-- แสดงข้อความแจ้งเตือน -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- ==================== View: เพิ่มคำสั่งใหม่ ==================== -->
    <?php if ($view === 'add'): ?>
        
        <?php if ($step === 1): ?>
            <form method="POST" action="admin_restrict.php?view=add">
                <div class="form-group">
                    <label>ประเภทคำสั่ง:</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="restrict_type" value="restrict" checked>
                            <div>
                                <strong>⛔ กักบริเวณ (Restrict)</strong>
                                <div style="font-size:0.85rem; color:#64748b;">ไม่อนุญาตให้ลาพักบ้าน / ออกนอกพื้นที่</div>
                            </div>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="restrict_type" value="stay" <?= $restrict_type === 'stay' ? 'checked' : '' ?>>
                            <div>
                                <strong>🏠 บังคับอยู่ (Stay)</strong>
                                <div style="font-size:0.85rem; color:#64748b;">ให้อยู่เวร / เฝ้ากองพัน / กิจกรรมพิเศษ</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="restrict_weeks">จำนวนสัปดาห์:</label>
                    <input type="number" id="restrict_weeks" name="restrict_weeks" class="form-control" value="<?= htmlspecialchars($weeks) ?>" min="1" max="52" required>
                </div>

                <div class="form-group">
                    <label for="names_list">วางรายชื่อ นชท. (บรรทัดละ 1 ชื่อ):</label>
                    <textarea id="names_list" name="names_list" class="form-control" rows="8" placeholder="ตัวอย่าง:&#10;สมชาย ใจดี&#10;สมศักดิ์ รักชาติ" required><?= htmlspecialchars($raw_names) ?></textarea>
                </div>

                <button type="submit" name="action_preview" class="btn btn-primary">
                    <i class="fas fa-search"></i> ตรวจสอบรายชื่อ
                </button>
            </form>

        <?php elseif ($step === 2): ?>
            <h3><i class="fas fa-user-check"></i> ตรวจสอบผลการค้นหารายชื่อ</h3>
            
            <form method="POST" action="admin_restrict.php?view=add">
                <input type="hidden" name="weeks" value="<?= htmlspecialchars($weeks) ?>">
                <input type="hidden" name="restrict_type" value="<?= htmlspecialchars($restrict_type) ?>">

                <div style="margin-bottom: 15px; background: #f1f5f9; padding: 12px; border-radius: 6px;">
                    <strong>ประเภทคำสั่ง:</strong> 
                    <span class="badge <?= $restrict_type === 'stay' ? 'badge-stay' : 'badge-restrict' ?>">
                        <?= $restrict_type === 'stay' ? '🏠 บังคับอยู่' : '⛔ กักบริเวณ' ?>
                    </span>
                    <strong style="margin-left: 15px;">ระยะเวลา:</strong> <?= $weeks ?> สัปดาห์
                </div>

                <?php if (!empty($valid_students)): ?>
                    <h4 style="color: var(--success);"><i class="fas fa-check"></i> รายชื่อที่พบในระบบ (<?= count($valid_students) ?> นาย):</h4>
                    <ul style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px 15px 15px 35px;">
                        <?php foreach ($valid_students as $st): ?>
                            <li>
                                <strong><?= htmlspecialchars($st['full_name']) ?></strong> (รหัส: <?= htmlspecialchars($st['code']) ?>)
                                <input type="hidden" name="student_codes[]" value="<?= htmlspecialchars($st['code']) ?>">
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($not_found)): ?>
                    <h4 style="color: var(--danger);"><i class="fas fa-times"></i> รายชื่อที่ไม่พบในระบบ (<?= count($not_found) ?> รายการ):</h4>
                    <ul style="background: #fff1f2; border: 1px solid #fecaca; border-radius: 6px; padding: 15px 15px 15px 35px; color: #991b1b;">
                        <?php foreach ($not_found as $nf): ?>
                            <li><?= htmlspecialchars($nf) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <?php if (!empty($valid_students)): ?>
                        <button type="submit" name="action_save" class="btn btn-success">
                            <i class="fas fa-save"></i> ยืนยันบันทึกข้อมูล
                        </button>
                    <?php endif; ?>
                    <a href="admin_restrict.php?view=add" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> ย้อนกลับไปแก้ไข
                    </a>
                </div>
            </form>
        <?php endif; ?>

    <!-- ==================== View: จัดการรายการคำสั่ง ==================== -->
    <?php elseif ($view === 'manage'): ?>
        
        <h3><i class="fas fa-list-ul"></i> รายการคำสั่งกักบริเวณ/บังคับอยู่ ล่าสุด</h3>
        
        <?php if (!empty($active_restrictions)): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>รหัสนักเรียน</th>
                        <th>ชื่อ - นามสกุล</th>
                        <th>ประเภทคำสั่ง</th>
                        <th>ระยะเวลา</th>
                        <th>วันที่บันทึก</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_restrictions as $idx => $row): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars($row['student_code']) ?></td>
                            <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td>
                                <span class="badge <?= $row['restrict_type'] === 'stay' ? 'badge-stay' : 'badge-restrict' ?>">
                                    <?= $row['restrict_type'] === 'stay' ? '🏠 บังคับอยู่' : '⛔ กักบริเวณ' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($row['weeks_count']) ?> สัปดาห์</td>
                            <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                            <td>
                                <button type="button" class="btn-small btn-edit" onclick="openEditModal(<?= $row['id'] ?>, <?= $row['weeks_count'] ?>, '<?= htmlspecialchars($row['restrict_type']) ?>')">
                                    <i class="fas fa-edit"></i> แก้ไข
                                </button>
                                
                                <form method="POST" action="admin_restrict.php?view=manage" style="display:inline;" onsubmit="return confirm('ยืนยันการลบรายการนี้ใช่หรือไม่?');">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="action_delete" class="btn-small btn-delete">
                                        <i class="fas fa-trash"></i> ลบ
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; color: #64748b; padding: 30px;">ยังไม่มีข้อมูลคำสั่งในระบบ</p>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Modal สำหรับแก้ไขรายการ -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-top:0;"><i class="fas fa-edit"></i> แก้ไขรายการคำสั่ง</h3>
        <form method="POST" action="admin_restrict.php?view=manage">
            <input type="hidden" id="update_id" name="update_id">
            
            <div class="form-group">
                <label>ประเภทคำสั่ง:</label>
                <select id="edit_type" name="edit_type" class="form-control" required>
                    <option value="restrict">⛔ กักบริเวณ (Restrict)</option>
                    <option value="stay">🏠 บังคับอยู่ (Stay)</option>
                </select>
            </div>

            <div class="form-group">
                <label>จำนวนสัปดาห์:</label>
                <input type="number" id="edit_weeks" name="edit_weeks" class="form-control" min="1" max="52" required>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">ยกเลิก</button>
                <button type="submit" name="action_update" class="btn btn-primary"><i class="fas fa-save"></i> บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, weeks, type) {
    document.getElementById('update_id').value = id;
    document.getElementById('edit_weeks').value = weeks;
    document.getElementById('edit_type').value = type;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('editModal');
    if (event.target == modal) {
        closeEditModal();
    }
}
</script>

</body>
</html>
