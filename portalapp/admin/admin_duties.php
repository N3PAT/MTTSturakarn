<?php
session_start();
require 'db.php'; // ไฟล์เชื่อมต่อฐานข้อมูลของคุณ

// 1. จัดการ Request POST (อัปเดตการตั้งค่า และ สลับเวร)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'update_settings') {
        $start_date = $_POST['start_date'];
        $per_day = (int)$_POST['students_per_day'];
        $stmt = $conn->prepare("UPDATE duty_settings SET start_date = ?, students_per_day = ? WHERE id = 1");
        $stmt->bind_param("si", $start_date, $per_day);
        $stmt->execute();
        header("Location: manage_duty.php?msg=settings_updated");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] == 'swap_duty') {
        $t_date = $_POST['target_date'];
        $o_student = $_POST['original_student'];
        $n_student = $_POST['new_student'];
        
        $stmt = $conn->prepare("INSERT INTO duty_overrides (target_date, original_student_code, new_student_code) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE new_student_code = ?");
        $stmt->bind_param("ssss", $t_date, $o_student, $n_student, $n_student);
        $stmt->execute();
        header("Location: manage_duty.php?month=" . date('Y-m', strtotime($t_date)) . "&msg=duty_swapped");
        exit;
    }
}

// 2. ดึงการตั้งค่า
$settings = $conn->query("SELECT * FROM duty_settings LIMIT 1")->fetch_assoc();
$start_date = $settings['start_date'] ?? '2026-07-06';
$per_day = $settings['students_per_day'] ?? 16;
$target_month = $_GET['month'] ?? date('Y-m');

// 3. ดึงนักเรียนที่ไม่มีชื่อในข้อยกเว้น พร้อมข้อมูลชื่อ-สกุล
$active_students = [];
$student_names = [];
$res = $conn->query("
    SELECT student_code, prefix, first_name, last_name 
    FROM students 
    WHERE student_code NOT IN (SELECT student_code FROM duty_exemptions WHERE is_active = 1)
    ORDER BY id ASC
");
while ($row = $res->fetch_assoc()) {
    $active_students[] = $row['student_code'];
    $student_names[$row['student_code']] = $row['prefix'] . $row['first_name'] . ' ' . $row['last_name'];
}

// 4. โหลดข้อมูลการสลับเวร (Overrides)
$overrides = [];
$res_override = $conn->query("SELECT target_date, original_student_code, new_student_code FROM duty_overrides WHERE target_date LIKE '$target_month%'");
while ($row = $res_override->fetch_assoc()) {
    $overrides[$row['target_date']][$row['original_student_code']] = $row['new_student_code'];
}

// 5. คำนวณเวรรายวันในเดือนนั้น
$monthly_roster = [];
$days_in_month = date('t', strtotime($target_month . '-01'));
$total_active = count($active_students);

for ($day = 1; $day <= $days_in_month; $day++) {
    $current_date = date('Y-m-d', strtotime("$target_month-$day"));
    $diff_days = floor((strtotime($current_date) - strtotime($start_date)) / 86400);
    
    if ($diff_days < 0 || $total_active == 0) continue; 

    $start_index = ($diff_days * $per_day) % $total_active;
    $daily_duty = [];
    
    for ($i = 0; $i < $per_day; $i++) {
        $idx = ($start_index + $i) % $total_active;
        $original_student = $active_students[$idx];
        $actual_student = $original_student;
        $is_swapped = false;
        
        if (isset($overrides[$current_date][$original_student])) {
            $actual_student = $overrides[$current_date][$original_student];
            $is_swapped = true;
        }
        
        $daily_duty[] = [
            'original_code' => $original_student,
            'actual_code' => $actual_student,
            'actual_name' => $student_names[$actual_student] ?? $actual_student,
            'is_swapped' => $is_swapped
        ];
    }
    $monthly_roster[$current_date] = $daily_duty;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการเวรรักษาการณ์</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <style>
        body { font-family: 'Prompt', sans-serif; background: #f1f5f9; padding: 20px; }
        .card { background: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; font-size: 0.9rem; }
        th { background: #1e293b; color: white; }
        .swapped { color: #ea580c; font-weight: bold; }
        .btn { padding: 8px 15px; border: none; border-radius: 6px; cursor: pointer; font-family: 'Prompt', sans-serif; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-warning { background: #f59e0b; color: white; font-size: 0.8rem; padding: 4px 8px; }
        .btn-danger { background: #ef4444; color: white; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; width: 400px; margin: 100px auto; padding: 20px; border-radius: 12px; }
        .form-group { margin-bottom: 15px; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
    </style>
</head>
<body>

    <div class="card flex-between">
        <h2><i class="fas fa-shield-alt"></i> จัดการเวรรักษาการณ์</h2>
        <div>
            <form method="GET" style="display:inline-block;">
                <input type="month" name="month" value="<?= htmlspecialchars($target_month) ?>" class="form-control" style="width: auto; display: inline-block;" onchange="this.form.submit()">
            </form>
            <a href="export_pdf.php?month=<?= htmlspecialchars($target_month) ?>" target="_blank">
                <button class="btn btn-danger"><i class="fas fa-file-pdf"></i> ออกรายงาน PDF</button>
            </a>
            <button class="btn btn-primary" onclick="document.getElementById('settingsModal').style.display='block'">
                <i class="fas fa-cog"></i> ตั้งค่าระบบ
            </button>
        </div>
    </div>

    <div class="card">
        <h3>ตารางเวรประจำเดือน <?= date('F Y', strtotime($target_month . '-01')) ?></h3>
        <table>
            <thead>
                <tr>
                    <th width="100">วันที่</th>
                    <th>รายชื่อผู้ปฏิบัติหน้าที่ (<?= $per_day ?> นาย)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthly_roster as $date => $students): ?>
                <tr>
                    <td valign="top"><strong><?= date('d/m/Y', strtotime($date)) ?></strong></td>
                    <td>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;">
                            <?php foreach ($students as $stu): ?>
                                <div style="background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px;">
                                    <span class="<?= $stu['is_swapped'] ? 'swapped' : '' ?>" title="รหัส: <?= $stu['actual_code'] ?>">
                                        <?= htmlspecialchars($stu['actual_name']) ?>
                                    </span>
                                    <button class="btn btn-warning" style="float: right;" 
                                            onclick="openSwapModal('<?= $date ?>', '<?= $stu['original_code'] ?>', '<?= htmlspecialchars($stu['actual_name']) ?>')">
                                        <i class="fas fa-exchange-alt"></i> แลก
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($monthly_roster)): ?>
                    <tr><td colspan="2" style="text-align:center;">ไม่พบข้อมูลรอบเวรในเดือนนี้ (กรุณาตรวจสอบวันที่เริ่มรันระบบ)</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal: ตั้งค่าระบบ -->
    <div id="settingsModal" class="modal">
        <div class="modal-content">
            <h3>ตั้งค่าการคำนวณเวร</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                <div class="form-group">
                    <label>วันที่เริ่มรันเวรรอบแรก (Start Date):</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>จำนวนคนเข้าเวรต่อผลัด:</label>
                    <input type="number" name="students_per_day" value="<?= htmlspecialchars($per_day) ?>" class="form-control" required min="1">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">บันทึกการตั้งค่า</button>
                <button type="button" class="btn" style="width: 100%; margin-top: 10px;" onclick="document.getElementById('settingsModal').style.display='none'">ยกเลิก</button>
            </form>
        </div>
    </div>

    <!-- Modal: สลับเวร -->
    <div id="swapModal" class="modal">
        <div class="modal-content">
            <h3>สลับเวร (Swap Duty)</h3>
            <form method="POST">
                <input type="hidden" name="action" value="swap_duty">
                <input type="hidden" name="target_date" id="swap_target_date">
                <input type="hidden" name="original_student" id="swap_original_student">
                
                <div class="form-group">
                    <label>วันที่:</label>
                    <input type="text" id="display_swap_date" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>ผู้เข้าเวรเดิม:</label>
                    <input type="text" id="display_original_name" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>รหัสประจำตัวผู้มาเข้าแทน (นชท.):</label>
                    <input type="text" name="new_student" class="form-control" required placeholder="กรอกรหัสนักเรียน">
                </div>
                <button type="submit" class="btn btn-warning" style="width: 100%;">ยืนยันการสลับเวร</button>
                <button type="button" class="btn" style="width: 100%; margin-top: 10px;" onclick="document.getElementById('swapModal').style.display='none'">ยกเลิก</button>
            </form>
        </div>
    </div>

    <script>
        function openSwapModal(date, originalCode, originalName) {
            document.getElementById('swap_target_date').value = date;
            document.getElementById('swap_original_student').value = originalCode;
            document.getElementById('display_swap_date').value = date;
            document.getElementById('display_original_name').value = originalName;
            document.getElementById('swapModal').style.display = 'block';
        }
    </script>
</body>
</html>
