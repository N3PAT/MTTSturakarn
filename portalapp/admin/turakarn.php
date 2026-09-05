<?php
session_start();
require 'db.php';

// ==========================================
// ส่วนที่ 1: ระบบ Authentication (Login / Logout)
// ==========================================

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$login_error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'login') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT id, password FROM admins WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            header("Location: " . $_SERVER['PHP_SELF']); 
            exit;
        } else {
            $login_error = "รหัสผ่านไม่ถูกต้อง";
        }
    } else {
        $login_error = "ไม่พบชื่อผู้ใช้ (Username) นี้";
    }
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ควบคุมยอด นชท.</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Sarabun', 'sans-serif'] },
                    colors: { gov: { purple: '#4A148C', dark: '#380b6b', light: '#f3e5f5' } }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen p-4 antialiased">
    <div class="w-full max-w-md bg-white rounded-sm shadow-md border border-gray-200 overflow-hidden">
        <div class="h-2 w-full bg-gov-purple"></div>
        <div class="p-8">
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-gov-purple mb-2">เข้าสู่ระบบผู้ดูแล</h2>
                <p class="text-sm text-gray-500">ระบบควบคุมยอด นชท. ประจำสัปดาห์</p>
            </div>

            <?php if(!empty($login_error)): ?>
            <div class="bg-red-50 border-l-4 border-red-600 p-4 mb-6">
                <p class="text-sm text-red-700 font-medium"><?php echo $login_error; ?></p>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-6">
                <input type="hidden" name="action" value="login">
                <div>
                    <label for="username" class="block text-sm font-semibold text-gray-700 mb-1.5">ชื่อผู้ใช้ (Username)</label>
                    <input type="text" id="username" name="username" required autocomplete="username"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-sm focus:ring-1 focus:ring-gov-purple focus:border-gov-purple">
                </div>
                <div>
                    <label for="password" class="block text-sm font-semibold text-gray-700 mb-1.5">รหัสผ่าน (Password)</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-sm focus:ring-1 focus:ring-gov-purple focus:border-gov-purple">
                </div>
                <button type="submit" class="w-full bg-gov-purple hover:bg-gov-dark text-white font-bold py-3 px-4 rounded-sm transition duration-200 shadow-sm">
                    เข้าสู่ระบบ
                </button>
            </form>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// ==========================================
// ส่วนที่ 2: ระบบจัดการข้อมูล & Dashboard 
// ==========================================

$default_target_date = (date('w') == 5) ? date('Y-m-d') : date('Y-m-d', strtotime('next friday'));
$target_date = isset($_REQUEST['target_date']) ? $_REQUEST['target_date'] : $default_target_date;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'dashboard';
$start_date = "2026-07-06"; 
$alert_msg = '';

$tz = new DateTimeZone('Asia/Bangkok');
$dt = new DateTime('now', $tz);
$dt->modify('+1 day');
$dt->modify('-10 hours'); 
$current_time = $dt->format('Y-m-d H:i:s');
$admin_user = $_SESSION['admin_username'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update_student') {
    $code = $_POST['student_code'];
    
    $stmt = $conn->prepare("UPDATE students SET prefix=?, first_name=?, last_name=?, platoon=?, company=?, section=? WHERE student_code=?");
    $stmt->bind_param("sssssss", $_POST['prefix'], $_POST['first_name'], $_POST['last_name'], $_POST['platoon'], $_POST['company'], $_POST['section'], $code);
    $stmt->execute();

    $override_status = $_POST['override_status'];
    $override_reason = trim($_POST['override_reason'] ?? '');
    
    if (!empty($override_status)) {
        $stmt_override = $conn->prepare("
            INSERT INTO admin_overrides (student_code, target_date, override_status, override_reason, admin_username, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                override_status = ?, override_reason = ?, admin_username = ?, updated_at = ?
        ");
        $stmt_override->bind_param("ssssssssss", $code, $target_date, $override_status, $override_reason, $admin_user, $current_time, $override_status, $override_reason, $admin_user, $current_time);
        $stmt_override->execute();
    } else {
        $stmt_del = $conn->prepare("DELETE FROM admin_overrides WHERE student_code=? AND target_date=?");
        $stmt_del->bind_param("ss", $code, $target_date);
        $stmt_del->execute();
    }

    $action_type = 'single_update';
    $stmt_log = $conn->prepare("INSERT INTO admin_status_logs (student_code, target_date, action_type, override_status, override_reason, admin_username, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt_log->bind_param("sssssss", $code, $target_date, $action_type, $override_status, $override_reason, $admin_user, $current_time);
    $stmt_log->execute();
    
    $alert_msg = "อัปเดตข้อมูลและสถานะของ นชท. รหัส $code สำเร็จแล้ว";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'bulk_update') {
    $selected_students = $_POST['student_codes'] ?? [];
    $bulk_status = $_POST['bulk_status'] ?? '';
    $bulk_reason = trim($_POST['bulk_reason'] ?? '');
    
    if (!empty($selected_students)) {
        $success_count = 0;

        foreach ($selected_students as $code) {
            if (!empty($bulk_status)) {
                $stmt_override = $conn->prepare("
                    INSERT INTO admin_overrides (student_code, target_date, override_status, override_reason, admin_username, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        override_status = ?, override_reason = ?, admin_username = ?, updated_at = ?
                ");
                $stmt_override->bind_param("ssssssssss", $code, $target_date, $bulk_status, $bulk_reason, $admin_user, $current_time, $bulk_status, $bulk_reason, $admin_user, $current_time);
                $stmt_override->execute();
            } else {
                $stmt_del = $conn->prepare("DELETE FROM admin_overrides WHERE student_code=? AND target_date=?");
                $stmt_del->bind_param("ss", $code, $target_date);
                $stmt_del->execute();
            }

            $action_type = 'bulk_update';
            $stmt_log = $conn->prepare("INSERT INTO admin_status_logs (student_code, target_date, action_type, override_status, override_reason, admin_username, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_log->bind_param("sssssss", $code, $target_date, $action_type, $bulk_status, $bulk_reason, $admin_user, $current_time);
            $stmt_log->execute();
            
            $success_count++;
        }
        $alert_msg = "อัปเดตสถานะ นชท. จำนวน $success_count นาย สำเร็จแล้ว";
    } else {
        $alert_msg = "กรุณาเลือก นชท. อย่างน้อย 1 นาย";
    }
}

$students_data = [];
$stmt = $conn->prepare("
    SELECT s.id, s.student_code, s.prefix, s.first_name, s.last_name, s.platoon, s.company, s.section, 
           v.status AS normal_vote, v.province, v.travel_method, v.return_day, v.created_at,
           a.override_status, a.override_reason, a.admin_username
    FROM students s
    LEFT JOIN weekly_votes v ON s.student_code = v.student_code AND v.vote_date = ?
    LEFT JOIN admin_overrides a ON s.student_code = a.student_code AND a.target_date = ?
    ORDER BY s.id ASC
");
$stmt->bind_param("ss", $target_date, $target_date);
$stmt->execute();
$res = $stmt->get_result();

while($row = $res->fetch_assoc()) {
    if (!empty($row['override_status'])) {
        $row['vote_status'] = $row['override_status'];
        $row['is_overridden'] = true;
    } else {
        $row['vote_status'] = $row['normal_vote'];
        $row['is_overridden'] = false;
    }
    $students_data[] = $row;
}
$total_students = count($students_data);

$duty_codes = [];
$duty_roster = [];

if ($total_students > 0) {
    $diff_days = floor((strtotime($target_date) - strtotime($start_date)) / 86400);
    if ($diff_days < 0) $diff_days = 0;
    
    $start_index = ($diff_days * 16) % $total_students;
    for ($i = 0; $i < 16; $i++) {
        $idx = ($start_index + $i) % $total_students;
        if (isset($students_data[$idx])) {
            $duty_codes[] = $students_data[$idx]['student_code'];
            $duty_roster[] = $students_data[$idx];
        }
    }
}

$summary = ['home' => 0, 'stay' => 0, 'duty' => 0, 'restrict' => 0, 'unvoted' => 0];
$list_home = [];
$list_stay = [];
$list_restrict = [];
$list_unvoted = [];
$all_students_formatted = []; 
$running_number = 1;

foreach ($students_data as $stu) {
    $code = $stu['student_code'];
    $full_name = $stu['prefix'] . $stu['first_name'] . " " . $stu['last_name'];
    $platoon = $stu['platoon'] ? $stu['platoon'] : '-';
    $company = $stu['company'] ? $stu['company'] : '-';
    $section_text = $stu['section'] ? $stu['section'] : 'ยังไม่ระบุ';
    $status_text_th = '';
    
    if (in_array($code, $duty_codes)) {
        $summary['duty']++;
        $status_text_th = 'เวรรักษาการณ์';
    } elseif (!empty($stu['vote_status'])) {
        $status = $stu['vote_status'];
        if ($status == 'home') {
            $summary['home']++;
            $list_home[] = $stu;
            $status_text_th = 'กลับภูมิลำเนา';
        } elseif ($status == 'stay') {
            $summary['stay']++;
            $list_stay[] = $stu;
            $status_text_th = 'อยู่ รร.ชท.';
        } elseif ($status == 'restrict') {
            $summary['restrict']++;
            $list_restrict[] = $stu;
            $status_text_th = 'กักบริเวณ';
        }
    } else {
        $summary['unvoted']++;
        $list_unvoted[] = $stu;
        $status_text_th = 'ยังไม่ทำรายการ';
    }

    if (!empty($stu['is_overridden'])) {
        $reason_text = !empty($stu['override_reason']) ? " - เหตุผล: " . $stu['override_reason'] : "";
        $status_text_th .= " (บันทึกโดยแอดมิน: " . $stu['admin_username'] . $reason_text . ")";
    }

    $all_students_formatted[] = [$running_number++, $code, $full_name, $platoon, $company, $section_text, $status_text_th];
}

if (isset($_POST['action']) && $_POST['action'] == 'export_csv') {
    $filename = "report_nc_" . date('Ymd', strtotime($target_date)) . ".csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    
    fputcsv($output, ['สรุปยอด นชท. ประจำวันที่', date('d/m/Y', strtotime($target_date))]);
    fputcsv($output, ['1. กลับภูมิลำเนา', $summary['home'], 'นาย']);
    fputcsv($output, ['2. ประสงค์อยู่ รร.ชท.', $summary['stay'], 'นาย']);
    fputcsv($output, ['3. เวรรักษาการณ์', $summary['duty'], 'นาย']);
    fputcsv($output, ['4. กักบริเวณ', $summary['restrict'], 'นาย']);
    fputcsv($output, ['5. ยังไม่ทำรายการ', $summary['unvoted'], 'นาย']);
    fputcsv($output, ['รวมทั้งสิ้น', $total_students, 'นาย']);
    fputcsv($output, []); 
    fputcsv($output, []); 
    
    fputcsv($output, ['ลำดับ', 'รหัสประจำตัว', 'คำนำหน้า ชื่อ - นามสกุล', 'หมวด', 'กองร้อย', 'ตอนเรียน', 'สถานะ']);
    foreach ($all_students_formatted as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบควบคุมยอด นชท. - โหมดผู้ดูแลระบบ</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Sarabun', 'sans-serif'] },
                    colors: { gov: { purple: '#4A148C', dark: '#380b6b', light: '#f3e5f5' } }
                }
            }
        }

        function openEditModal(data) {
            document.getElementById('edit_student_code').value = data.student_code;
            document.getElementById('edit_prefix').value = data.prefix || '';
            document.getElementById('edit_first_name').value = data.first_name || '';
            document.getElementById('edit_last_name').value = data.last_name || '';
            document.getElementById('edit_platoon').value = data.platoon || '';
            document.getElementById('edit_company').value = data.company || '';
            document.getElementById('edit_section').value = data.section || '';
            document.getElementById('edit_override_status').value = data.override_status || '';
            document.getElementById('edit_override_reason').value = data.override_reason || '';
            document.getElementById('display_student_code').innerText = data.student_code;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
    </script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased pb-12">

    <!-- Container สำหรับแสดง Toast Notifications บนหน้าจอ -->
    <div id="toastContainer" class="fixed top-5 right-5 z-50 space-y-3 max-w-sm"></div>

    <header class="bg-gov-purple text-white shadow-sm border-b-4 border-gov-dark">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 flex flex-col sm:flex-row justify-between items-center">
            <div>
                <h1 class="text-xl font-bold tracking-tight">ระบบโหวตกลับ นชท. ประจำสัปดาห์</h1>
                <p class="text-sm text-purple-200 mt-1">โรงเรียนช่างฝีมือทหาร สถาบันวิชาการป้องกันประเทศ</p>
            </div>
            <div class="mt-4 sm:mt-0 flex items-center gap-4">
                <a href="?mode=dashboard&target_date=<?= htmlspecialchars($target_date) ?>" class="<?= $mode=='dashboard'?'bg-white text-gov-purple':'bg-gov-dark text-white hover:bg-white hover:text-gov-purple' ?> px-4 py-2 rounded-sm text-sm font-semibold transition">แดชบอร์ดสรุปยอด</a>
                <a href="?mode=manage&target_date=<?= htmlspecialchars($target_date) ?>" class="<?= $mode=='manage'?'bg-white text-gov-purple':'bg-gov-dark text-white hover:bg-white hover:text-gov-purple' ?> px-4 py-2 rounded-sm text-sm font-semibold transition">จัดการข้อมูล & สถานะ</a>

                <div class="flex flex-col text-right hidden sm:block ml-4 border-l border-purple-500 pl-4">
                    <span class="text-sm font-medium text-purple-200">เข้าสู่ระบบโดย</span>
                    <span class="text-sm font-bold text-white"><?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                </div>
                <a href="?logout=1" onclick="return confirm('ยืนยันการออกจากระบบ?');" class="inline-flex items-center px-4 py-2 rounded-sm text-sm font-semibold bg-red-600 hover:bg-red-700 text-white transition duration-150 shadow-sm ml-2">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    ออก
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <?php if($alert_msg): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 shadow-sm">
            <span class="block sm:inline font-semibold"><?php echo $alert_msg; ?></span>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-gray-500 p-6 mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-lg font-bold text-gray-700 mb-2">เลือกสัปดาห์ / วันที่ต้องการดำเนินการ (เฉพาะวันศุกร์)</h2>
                <form method="GET" action="" class="flex flex-col sm:flex-row gap-3">
                    <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                    
                    <input type="date" name="target_date" id="target_date" 
                           value="<?php echo htmlspecialchars($target_date); ?>" 
                           onchange="enforceFridaySelection(this)"
                           class="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-gov-purple focus:border-gov-purple text-gray-700">
                    
                    <button type="submit" class="bg-gov-purple hover:bg-gov-dark text-white font-semibold py-2 px-6 rounded-sm transition-colors duration-200 shadow-sm">
                        ดึงข้อมูลยอด
                    </button>
                    <span class="text-sm text-gray-500 mt-2 sm:mt-0 sm:self-center">* ระบบจะคำนวณเวร 16 นายหมุนเวียนให้อัตโนมัติ</span>
                </form>
            </div>
        </div>

        <script>
        function enforceFridaySelection(input) {
            if (!input.value) return;
            
            let selectedDate = new Date(input.value);
            let dayOfWeek = selectedDate.getDay();
            
            if (dayOfWeek !== 5) {
                alert('ระบบอนุญาตให้เลือกเฉพาะ "วันศุกร์" เท่านั้นครับ ระบบจะทำการเลื่อนไปยังวันศุกร์ถัดไปให้อัตโนมัติ');
                
                let diff = 5 - dayOfWeek;
                if (diff <= 0) {
                    diff += 7;
                }
                
                selectedDate.setDate(selectedDate.getDate() + diff);
                
                let year = selectedDate.getFullYear();
                let month = String(selectedDate.getMonth() + 1).padStart(2, '0');
                let day = String(selectedDate.getDate()).padStart(2, '0');
                
                input.value = `${year}-${month}-${day}`;
            }
        }
        </script>
            
        <?php if ($mode == 'dashboard'): ?>
        <!-- ปุ่มดำเนินการหลัก: ดาวน์โหลด CSV & ปุ่มส่งการแจ้งเตือน นชท. ที่ยังไม่ทำรายการ -->
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <form method="POST" action="">
                <input type="hidden" name="target_date" value="<?php echo htmlspecialchars($target_date); ?>">
                <button type="submit" name="action" value="export_csv" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 px-6 rounded-sm transition-colors duration-200 shadow-sm flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    ดาวน์โหลด CSV
                </button>
            </form>

            <!-- ปุ่มกดส่งแจ้งเตือนคนที่ยังไม่ทำรายการ -->
            <button type="button" onclick="sendNotificationToUnvoted()" class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 px-6 rounded-sm transition-colors duration-200 shadow-sm flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 01-6 0v-1m6 0H9"></path></svg>
                ส่งแจ้งเตือน นชท. ที่ยังไม่ทำรายการทั้งหมด (<?= count($list_unvoted) ?> นาย)
            </button>
        </div>
        <?php endif; ?>
        
        <?php if ($mode == 'manage'): ?>
        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-yellow-500 mb-8 overflow-hidden">
            
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col md:flex-row justify-between items-center gap-4">
                <h3 class="text-lg font-bold text-yellow-700">รายชื่อนักเรียนทั้งหมด (ค้นหา / แก้ไขหลายรายการ)</h3>
                <!-- ช่องค้นหา -->
                <div class="relative w-full md:w-1/3">
                    <input type="text" id="searchInput" onkeyup="filterTable()" placeholder="ค้นหารหัส, ชื่อ, หรือสังกัด มว. ร." class="w-full px-4 py-2 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-gov-purple">
                </div>
            </div>

            <!-- ฟอร์มสำหรับ Bulk Update -->
            <form method="POST" action="?mode=manage&target_date=<?= htmlspecialchars($target_date) ?>" id="bulkForm">
                <input type="hidden" name="action" value="bulk_update">
                
                <div class="px-6 py-3 bg-white border-b border-gray-200 flex flex-wrap items-center gap-3">
                    <span class="text-sm font-semibold text-gray-700">ดำเนินการกับรายการที่เลือก:</span>
                    <select name="bulk_status" class="px-3 py-1.5 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple">
                        <option value="">-- ยกเลิกการบันทึกเพิ่มเติม (อิงระบบปกติ) --</option>
                        <option value="restrict">กักบริเวณ (Restrict)</option>
                        <option value="home">บังคับกลับภูมิลำเนา (Home)</option>
                        <option value="stay">บังคับอยู่ รร.ชท. (Stay)</option>
                    </select>
                    <input type="text" name="bulk_reason" placeholder="หมายเหตุ (ถ้ามี)" class="px-3 py-1.5 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple w-48">
                    <button type="submit" onclick="return confirm('ยืนยันการเปลี่ยนแปลงสถานะของ นชท. ที่เลือก?')" class="bg-gov-purple hover:bg-gov-dark text-white px-4 py-1.5 rounded-sm text-sm font-semibold shadow-sm transition-colors">
                        อัปเดตพร้อมกัน
                    </button>

                    <button type="button" onclick="sendNotificationToUnvoted()" class="ml-auto bg-red-600 hover:bg-red-700 text-white px-4 py-1.5 rounded-sm text-sm font-semibold shadow-sm transition-colors flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 01-6 0v-1m6 0H9"></path></svg>
                        แจ้งเตือน นชท. ยังไม่โหวต
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left whitespace-nowrap" id="manageTable">
                        <thead class="text-xs text-gray-600 uppercase bg-gray-100 border-b border-gray-200 font-semibold">
                            <tr>
                                <th class="px-6 py-3 text-center w-12">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" class="w-4 h-4 text-gov-purple rounded focus:ring-gov-purple cursor-pointer">
                                </th>
                                <th class="px-6 py-3 text-center w-16">ลำดับ</th>
                                <th class="px-6 py-3 w-32">รหัสประจำตัว</th>
                                <th class="px-6 py-3">คำนำหน้า ชื่อ - นามสกุล</th>
                                <th class="px-6 py-3 w-32">สังกัด มว. ร.</th>
                                <th class="px-6 py-3 w-40">สถานะที่โหวตปกติ</th>
                                <th class="px-6 py-3 w-48">สถานะแอดมิน (Override)</th>
                                <th class="px-6 py-3 text-center w-32">จัดการรายบุคคล</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach($students_data as $index => $stu): ?>
                            <tr class="hover:bg-gray-50 transition-colors student-row">
                                <td class="px-6 py-3 text-center">
                                    <input type="checkbox" name="student_codes[]" value="<?= htmlspecialchars($stu['student_code']) ?>" class="student-checkbox w-4 h-4 text-gov-purple rounded focus:ring-gov-purple cursor-pointer">
                                </td>
                                <td class="px-6 py-3 text-center text-gray-500"><?php echo $index+1; ?></td>
                                <td class="px-6 py-3 font-bold text-gray-800"><?php echo htmlspecialchars($stu['student_code']); ?></td>
                                <td class="px-6 py-3"><?php echo htmlspecialchars($stu['prefix'].$stu['first_name']." ".$stu['last_name']); ?></td>
                                <td class="px-6 py-3"><?php echo htmlspecialchars($stu['platoon']."/".$stu['company']); ?></td>
                                <td class="px-6 py-3 text-gray-500">
                                    <?= $stu['normal_vote'] ? htmlspecialchars($stu['normal_vote']) : 'ยังไม่ระบุ' ?>
                                    <?php if($stu['normal_vote']): ?>
                                    <button type="button" onclick="openInfoModal(<?= htmlspecialchars(json_encode($stu), ENT_QUOTES, 'UTF-8') ?>)" class="ml-2 inline-flex items-center justify-center text-blue-500 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 p-1 rounded-full transition-colors" title="ดูข้อมูลที่กรอก">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-3">
                                    <?php if($stu['override_status']): ?>
                                        <span class="px-2 py-1 bg-red-100 text-red-800 text-xs rounded-sm font-bold border border-red-200 uppercase">
                                            <?= htmlspecialchars($stu['override_status']) ?>
                                        </span>
                                        <div class="text-[10px] text-gray-500 mt-1 truncate">โดย: <?= htmlspecialchars($stu['admin_username']) ?></div>
                                        <?php if(!empty($stu['override_reason'])): ?>
                                            <div class="text-[10px] text-red-600 truncate mt-0.5" title="<?= htmlspecialchars($stu['override_reason']) ?>">หมายเหตุ: <?= htmlspecialchars($stu['override_reason']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-300">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-3 text-center">
                                    <button type="button" onclick='openEditModal(<?= json_encode($stu) ?>)' class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1.5 rounded-sm text-xs font-semibold shadow-sm transition-colors">
                                        แก้ไขรายการ
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-md shadow-xl w-full max-w-lg overflow-hidden">
                <div class="bg-gov-purple px-6 py-4 flex justify-between items-center">
                    <h3 class="text-white font-bold text-lg">แก้ไขข้อมูล & สถานะ รหัส <span id="display_student_code"></span></h3>
                    <button type="button" onclick="closeEditModal()" class="text-white hover:text-gray-200 text-2xl font-bold leading-none">&times;</button>
                </div>
                <form method="POST" action="?mode=manage&target_date=<?= htmlspecialchars($target_date) ?>" class="p-6">
                    <input type="hidden" name="action" value="update_student">
                    <input type="hidden" name="student_code" id="edit_student_code">
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                        <div class="col-span-1">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">คำนำหน้า</label>
                            <input type="text" name="prefix" id="edit_prefix" class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple">
                        </div>
                        <div class="col-span-1 sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อ</label>
                            <input type="text" name="first_name" id="edit_first_name" class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple">
                        </div>
                        <div class="col-span-1 sm:col-span-3">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">นามสกุล</label>
                            <input type="text" name="last_name" id="edit_last_name" class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple">
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4 mb-6">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">หมวด</label>
                            <input type="text" name="platoon" id="edit_platoon" class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">กองร้อย</label>
                            <input type="text" name="company" id="edit_company" class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">ตอนเรียน</label>
                            <input type="text" name="section" id="edit_section" class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm focus:ring-1 focus:ring-gov-purple">
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-5">
                        <label class="block text-sm font-bold text-red-600 mb-2">กำหนดสถานะพิเศษ (บันทึกแอดมิน)</label>
                        <select name="override_status" id="edit_override_status" class="w-full px-3 py-2 mb-3 border border-red-300 rounded-sm text-sm focus:ring-1 focus:ring-red-500 bg-red-50 text-red-800">
                            <option value="">-- ปกติ (อิงตามการทำรายการปกติ) --</option>
                            <option value="restrict">กักบริเวณ (Restrict)</option>
                            <option value="home">บังคับกลับภูมิลำเนา (Home)</option>
                            <option value="stay">บังคับอยู่ รร.ชท. (Stay)</option>
                        </select>
                        
                        <div>
                            <label for="edit_override_reason" class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ / เหตุผลที่บันทึก</label>
                            <textarea name="override_reason" id="edit_override_reason" rows="3" 
                                      class="mt-1 block w-full rounded-sm border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-gov-purple focus:ring-1 focus:ring-gov-purple focus:outline-none"></textarea>
                        </div>
                        
                        <p class="text-[11px] text-gray-500 mt-2 leading-tight">
                            * สถานะนี้จะไปทับซ้อนการทำรายการปกติของ นชท. ในระบบแดชบอร์ด และจะมีการบันทึกชื่อผู้บันทึก (คุณ <b><?= htmlspecialchars($_SESSION['admin_username']) ?></b>) ไว้เป็นหลักฐาน
                        </p>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" onclick="closeEditModal()" class="px-5 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-sm font-semibold text-sm transition-colors">ยกเลิก</button>
                        <button type="submit" class="px-5 py-2 bg-gov-purple hover:bg-gov-dark text-white rounded-sm font-semibold text-sm shadow-sm transition-colors">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- แสดงกราฟิกตัวเลขและตาราง Dashboard -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-l-4 border-l-green-700 p-5 flex flex-col justify-center">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">1. กลับภูมิลำเนา</h3>
                <div class="text-3xl font-bold text-green-700 mt-2"><?php echo number_format($summary['home']); ?> <span class="text-lg font-normal text-gray-500">นาย</span></div>
            </div>
            <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-l-4 border-l-blue-700 p-5 flex flex-col justify-center">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">2. ประสงค์อยู่ รร.ชท.</h3>
                <div class="text-3xl font-bold text-blue-700 mt-2"><?php echo number_format($summary['stay']); ?> <span class="text-lg font-normal text-gray-500">นาย</span></div>
            </div>
            <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-l-4 border-l-gov-purple p-5 flex flex-col justify-center">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">3. เวรรักษาการณ์</h3>
                <div class="text-3xl font-bold text-gov-purple mt-2"><?php echo number_format($summary['duty']); ?> <span class="text-lg font-normal text-gray-500">นาย</span></div>
            </div>
            <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-l-4 border-l-orange-600 p-5 flex flex-col justify-center">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">4. กักบริเวณ</h3>
                <div class="text-3xl font-bold text-orange-600 mt-2"><?php echo number_format($summary['restrict']); ?> <span class="text-lg font-normal text-gray-500">นาย</span></div>
            </div>
            <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-l-4 border-l-red-700 p-5 flex flex-col justify-center">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">5. ยังไม่ทำรายการ</h3>
                <div class="text-3xl font-bold text-red-700 mt-2"><?php echo number_format($summary['unvoted']); ?> <span class="text-lg font-normal text-gray-500">นาย</span></div>
            </div>
        </div>

        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-gov-purple mb-8 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-gov-purple">รายชื่อผู้ปฏิบัติหน้าที่เวรรักษาการณ์ (16 นาย) - ประจำวันที่ <?php echo date('d/m/Y', strtotime($target_date)); ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-600 uppercase bg-gray-100 border-b border-gray-200 font-semibold">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-center w-16">ลำดับ</th>
                            <th scope="col" class="px-6 py-3 w-32">รหัสประจำตัว</th>
                            <th scope="col" class="px-6 py-3">คำนำหน้า ชื่อ - นามสกุล</th>
                            <th scope="col" class="px-6 py-3 w-24">หมวด</th>
                            <th scope="col" class="px-6 py-3 w-24">กองร้อย</th>
                            <th scope="col" class="px-6 py-3 w-48">ตอนเรียน</th>
                            <th scope="col" class="px-6 py-3 w-40">สถานะระบบ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach($duty_roster as $index => $stu): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-3 text-center text-gray-500 font-medium"><?php echo $index+1; ?></td>
                            <td class="px-6 py-3 font-bold text-gov-purple"><?php echo htmlspecialchars($stu['student_code']); ?></td>
                            <td class="px-6 py-3 text-gray-800"><?php echo htmlspecialchars($stu['prefix'].$stu['first_name']." ".$stu['last_name']); ?></td>
                            <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars($stu['platoon']?:'-'); ?></td>
                            <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars($stu['company']?:'-'); ?></td>
                            <td class="px-6 py-3 text-gray-600"><?php echo $stu['section'] ? htmlspecialchars($stu['section']) : '<span class="text-gray-400 italic">ยังไม่ระบุ</span>'; ?></td>
                            <td class="px-6 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-sm text-xs font-medium bg-gov-purple text-white">
                                    เข้าเวรรักษาการณ์
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-green-700 mb-8 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-green-700">รายชื่อ นชท. ที่ลงทะเบียน "กลับภูมิลำเนา"</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-600 uppercase bg-gray-100 border-b border-gray-200 font-semibold">
                        <tr><th class="px-6 py-3 text-center w-16">ลำดับ</th><th class="px-6 py-3 w-32">รหัสประจำตัว</th><th class="px-6 py-3">คำนำหน้า ชื่อ - นามสกุล</th><th class="px-6 py-3 w-24">หมวด</th><th class="px-6 py-3 w-24">กองร้อย</th><th class="px-6 py-3 w-48">ตอนเรียน</th><th class="px-6 py-3 w-40">สถานะ</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if(count($list_home)>0): foreach($list_home as $index => $stu): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-center"><?php echo $index+1; ?></td>
                            <td class="px-6 py-3 font-bold text-green-700"><?php echo htmlspecialchars($stu['student_code']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['prefix'].$stu['first_name']." ".$stu['last_name']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['platoon']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['company']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['section']?:''); ?></td>
                            <td class="px-6 py-3">
                                <span class="px-2.5 py-0.5 rounded-sm text-xs font-medium bg-green-100 text-green-800">กลับภูมิลำเนา</span>
                                <?php if($stu['is_overridden']): ?>
                                    <div class="text-[10px] text-red-600 mt-1 font-bold">บันทึกโดย: <?= htmlspecialchars($stu['admin_username']) ?></div>
                                    <?php if(!empty($stu['override_reason'])): ?>
                                        <div class="text-[10px] text-gray-500 truncate" title="<?= htmlspecialchars($stu['override_reason']) ?>">(<?= htmlspecialchars($stu['override_reason']) ?>)</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?><tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">ไม่มีข้อมูล</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-blue-700 mb-8 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-blue-700">รายชื่อ นชท. ที่ลงทะเบียน "ประสงค์อยู่ รร.ชท."</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-600 uppercase bg-gray-100 border-b border-gray-200 font-semibold">
                        <tr><th class="px-6 py-3 text-center w-16">ลำดับ</th><th class="px-6 py-3 w-32">รหัสประจำตัว</th><th class="px-6 py-3">คำนำหน้า ชื่อ - นามสกุล</th><th class="px-6 py-3 w-24">หมวด</th><th class="px-6 py-3 w-24">กองร้อย</th><th class="px-6 py-3 w-48">ตอนเรียน</th><th class="px-6 py-3 w-40">สถานะ</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if(count($list_stay)>0): foreach($list_stay as $index => $stu): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-center"><?php echo $index+1; ?></td>
                            <td class="px-6 py-3 font-bold text-blue-700"><?php echo htmlspecialchars($stu['student_code']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['prefix'].$stu['first_name']." ".$stu['last_name']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['platoon']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['company']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['section']?:''); ?></td>
                            <td class="px-6 py-3">
                                <span class="px-2.5 py-0.5 rounded-sm text-xs font-medium bg-blue-100 text-blue-800">อยู่ รร.ชท.</span>
                                <?php if($stu['is_overridden']): ?>
                                    <div class="text-[10px] text-red-600 mt-1 font-bold">บันทึกโดย: <?= htmlspecialchars($stu['admin_username']) ?></div>
                                    <?php if(!empty($stu['override_reason'])): ?>
                                        <div class="text-[10px] text-gray-500 truncate" title="<?= htmlspecialchars($stu['override_reason']) ?>">(<?= htmlspecialchars($stu['override_reason']) ?>)</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?><tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">ไม่มีข้อมูล</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-orange-600 mb-8 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-orange-600">รายชื่อ นชท. ที่มีสถานะ "กักบริเวณ"</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-600 uppercase bg-gray-100 border-b border-gray-200 font-semibold">
                        <tr><th class="px-6 py-3 text-center w-16">ลำดับ</th><th class="px-6 py-3 w-32">รหัสประจำตัว</th><th class="px-6 py-3">คำนำหน้า ชื่อ - นามสกุล</th><th class="px-6 py-3 w-24">หมวด</th><th class="px-6 py-3 w-24">กองร้อย</th><th class="px-6 py-3 w-48">ตอนเรียน</th><th class="px-6 py-3 w-40">สถานะ</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if(count($list_restrict)>0): foreach($list_restrict as $index => $stu): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-center"><?php echo $index+1; ?></td>
                            <td class="px-6 py-3 font-bold text-orange-600"><?php echo htmlspecialchars($stu['student_code']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['prefix'].$stu['first_name']." ".$stu['last_name']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['platoon']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['company']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['section']?:''); ?></td>
                            <td class="px-6 py-3">
                                <span class="px-2.5 py-0.5 rounded-sm text-xs font-medium bg-orange-100 text-orange-800">กักบริเวณ</span>
                                <?php if($stu['is_overridden']): ?>
                                    <div class="text-[10px] text-red-600 mt-1 font-bold border-l-2 border-red-600 pl-1">บันทึกโดยแอดมิน: <?= htmlspecialchars($stu['admin_username']) ?></div>
                                    <?php if(!empty($stu['override_reason'])): ?>
                                        <div class="text-[10px] text-gray-500 truncate pl-1" title="<?= htmlspecialchars($stu['override_reason']) ?>">หมายเหตุ: <?= htmlspecialchars($stu['override_reason']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?><tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">ไม่มีข้อมูล</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-red-700 overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                <h3 class="text-lg font-bold text-red-700">รายชื่อ นชท. ที่ยังไม่ได้แจ้งยอดสถานะ (<?= count($list_unvoted) ?> นาย)</h3>
                <?php if(count($list_unvoted) > 0): ?>
                <button type="button" onclick="sendNotificationToUnvoted()" class="bg-red-600 hover:bg-red-700 text-white text-xs font-semibold px-4 py-2 rounded-sm transition-colors shadow-sm flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 01-6 0v-1m6 0H9"></path></svg>
                    แจ้งเตือนกลุ่มนี้ทั้งหมด
                </button>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-600 uppercase bg-gray-100 border-b border-gray-200 font-semibold">
                        <tr><th class="px-6 py-3 w-32">รหัสประจำตัว</th><th class="px-6 py-3">คำนำหน้า ชื่อ - นามสกุล</th><th class="px-6 py-3 w-24">หมวด</th><th class="px-6 py-3 w-24">กองร้อย</th><th class="px-6 py-3 w-48">ตอนเรียน</th><th class="px-6 py-3 w-40">สถานะ</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if(count($list_unvoted)>0): foreach($list_unvoted as $stu): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 font-bold text-red-700"><?php echo htmlspecialchars($stu['student_code']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['prefix'].$stu['first_name']." ".$stu['last_name']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['platoon']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['company']?:'-'); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($stu['section']?:''); ?></td>
                            <td class="px-6 py-3"><span class="px-2.5 py-0.5 rounded-sm text-xs font-medium bg-red-100 text-red-800">ยังไม่ทำรายการ</span></td>
                        </tr>
                        <?php endforeach; else: ?><tr><td colspan="6" class="px-6 py-12 text-center"><div class="text-green-600 font-bold text-lg">สถานะครบถ้วน</div><div class="text-gray-500">นชท. ทุกนายลงทะเบียนแจ้งสถานะครบถ้วนแล้ว</div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>
        
        <!-- Info Modal -->
        <div id="infoModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-md shadow-xl w-full max-w-md overflow-hidden">
                <div class="bg-blue-600 px-6 py-4 flex justify-between items-center">
                    <h3 class="text-white font-bold text-lg">ข้อมูล</h3>
                    <button type="button" onclick="closeInfoModal()" class="text-white hover:text-gray-200 text-2xl font-bold leading-none">&times;</button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="flex border-b border-gray-100 pb-2">
                        <span class="w-1/3 text-sm font-semibold text-gray-500">รหัสประจำตัว:</span>
                        <span class="w-2/3 text-sm font-bold text-gray-800" id="info_student_code"></span>
                    </div>
                    <div class="flex border-b border-gray-100 pb-2">
                        <span class="w-1/3 text-sm font-semibold text-gray-500">ชื่อ-สกุล:</span>
                        <span class="w-2/3 text-sm text-gray-800" id="info_name"></span>
                    </div>
                    <div class="flex border-b border-gray-100 pb-2">
                        <span class="w-1/3 text-sm font-semibold text-gray-500">สถานะที่โหวต:</span>
                        <span class="w-2/3 text-sm text-blue-600 font-bold" id="info_status"></span>
                    </div>
                    <div class="flex border-b border-gray-100 pb-2">
                        <span class="w-1/3 text-sm font-semibold text-gray-500">จังหวัด:</span>
                        <span class="w-2/3 text-sm text-gray-800" id="info_province"></span>
                    </div>
                    <div class="flex border-b border-gray-100 pb-2">
                        <span class="w-1/3 text-sm font-semibold text-gray-500">วิธีเดินทาง:</span>
                        <span class="w-2/3 text-sm text-gray-800" id="info_travel"></span>
                    </div>
                    <div class="flex border-b border-gray-100 pb-2">
                        <span class="w-1/3 text-sm font-semibold text-gray-500">วันเดินทางกลับ:</span>
                        <span class="w-2/3 text-sm text-gray-800" id="info_return"></span>
                    </div>
                    <div class="flex pb-2">
                        <span class="w-1/3 text-sm font-semibold text-gray-500">เวลาที่ทำรายการ:</span>
                        <span class="w-2/3 text-sm text-gray-500" id="info_created"></span>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 flex justify-end">
                    <button type="button" onclick="closeInfoModal()" class="px-5 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-sm font-semibold text-sm transition-colors">ปิด</button>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript สำหรับระบบค้นหา เลือกทั้งหมด และ Notification -->
    <script>
        const unvotedStudents = <?= json_encode($list_unvoted, JSON_UNESCAPED_UNICODE) ?>;
        const targetDateFormatted = "<?= date('d/m/Y', strtotime($target_date)) ?>";

        function openInfoModal(data) {
            document.getElementById('info_student_code').innerText = data.student_code || '-';
            document.getElementById('info_name').innerText = (data.prefix || '') + (data.first_name || '') + ' ' + (data.last_name || '');
            
            let statusText = 'ยังไม่ทำรายการ';
            if(data.normal_vote === 'home') statusText = 'กลับภูมิลำเนา';
            else if(data.normal_vote === 'stay') statusText = 'อยู่ รร.ชท.';
            else if(data.normal_vote === 'restrict') statusText = 'กักบริเวณ';
            
            document.getElementById('info_status').innerText = statusText;
            document.getElementById('info_province').innerText = data.province || '-';
            document.getElementById('info_travel').innerText = data.travel_method || '-';
            document.getElementById('info_return').innerText = data.return_day || '-';
            document.getElementById('info_created').innerText = data.created_at ? data.created_at : '-';
            
            document.getElementById('infoModal').classList.remove('hidden');
        }

        function closeInfoModal() {
            document.getElementById('infoModal').classList.add('hidden');
        }

        function filterTable() {
            let input = document.getElementById("searchInput");
            if(!input) return;
            
            let filter = input.value.toLowerCase();
            let table = document.getElementById("manageTable");
            let tr = table.getElementsByClassName("student-row");

            for (let i = 0; i < tr.length; i++) { 
                let rowText = tr[i].innerText.toLowerCase();
                if (rowText.includes(filter)) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                    let checkbox = tr[i].querySelector('.student-checkbox');
                    if (checkbox) checkbox.checked = false;
                }
            }
        }

        function toggleSelectAll(source) {
            let checkboxes = document.querySelectorAll('.student-checkbox');
            for (let i = 0; i < checkboxes.length; i++) {
                if (checkboxes[i].closest('tr').style.display !== "none") {
                    checkboxes[i].checked = source.checked;
                }
            }
        }

// ==========================================
// ระบบแจ้งเตือน (Notification System) รองรับ PWA
// ==========================================

// ฟังก์ชันแสดง Toast บนหน้าจอ
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if(!container) return;

    const toast = document.createElement('div');
    let bgColor = 'bg-green-600';
    if (type === 'danger' || type === 'error') bgColor = 'bg-red-600';
    if (type === 'info') bgColor = 'bg-blue-600';
    if (type === 'warning') bgColor = 'bg-yellow-600';
    
    toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg flex items-center justify-between text-sm transition-all duration-300 transform translate-y-0`;
    toast.innerHTML = `
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span>${message}</span>
        </div>
        <button onclick="this.parentElement.remove()" class="ml-4 font-bold text-white hover:text-gray-200">&times;</button>
    `;
    container.appendChild(toast);
    setTimeout(() => { 
        toast.classList.add('opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// ฟังก์ชันเมื่อกดปุ่มส่งแจ้งเตือน
async function sendNotificationToUnvoted() {
    // สมมติว่ามีตัวแปร unvotedStudents และ targetDateFormatted อยู่ใน Scope แล้ว
    const count = unvotedStudents.length;
    if (count === 0) {
        showToast("ไม่มี นชท. ที่ยังไม่ทำรายการในขณะนี้", "info");
        return;
    }

    if (!confirm(`ยืนยันการส่งข้อความ "คุณยังไม่ได้ทำรายการ" ไปยัง นชท. จำนวน ${count} นาย?`)) {
        return;
    }

    if (!("Notification" in window)) {
        showToast(`ส่งแจ้งเตือนแล้ว (เบราว์เซอร์ไม่รองรับ Web Notification)`, "info");
        return;
    }

    let permission = Notification.permission;
    if (permission === "default") {
        permission = await Notification.requestPermission();
    }

    if (permission === "granted") {
        triggerWebNotification();
        showToast(`ส่งการแจ้งเตือนสำเร็จ! แจ้งให้ นชท. ทราบแล้วว่ายังไม่ได้ทำรายการ`, "success");
    } else if (permission === "denied") {
        showToast(`ส่งแจ้งเตือนผ่าน Toast แล้ว *การแจ้งเตือนของเบราว์เซอร์ถูกบล็อกอยู่*`, "warning");
    } else {
        showToast(`ดำเนินการส่งการแจ้งเตือนเรียบร้อยแล้ว`, "success");
    }
}

// ยิงคำสั่งแจ้งเตือนไปยัง PWA Mobile / Desktop ผ่าน Service Worker
async function triggerWebNotification() {
    if ("Notification" in window && Notification.permission === "granted") {
        const title = "ระบบแจ้งเตือน นชท.";
        const options = {
            body: `คุณยังไม่ได้ทำรายการ ประจำวันที่ ${targetDateFormatted} กรุณาเข้ามาดำเนินการให้เรียบร้อย`,
            icon: "https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg",
            tag: "unvoted-reminder",
            requireInteraction: true,
            vibrate: [200, 100, 200, 100, 200, 100, 200], // เพิ่มแพทเทิร์นการสั่นสำหรับมือถือ
            badge: "https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" // ไอคอนเล็กบนแถบแจ้งเตือน
        };

        // ตรวจสอบและใช้ Service Worker สำหรับ PWA
        if ('serviceWorker' in navigator) {
            try {
                // รอให้ Service Worker พร้อมทำงาน
                const registration = await navigator.serviceWorker.ready;
                // สั่งแจ้งเตือนผ่าน Service Worker
                await registration.showNotification(title, options);
            } catch (error) {
                console.error("การแจ้งเตือนผ่าน Service Worker ล้มเหลว:", error);
                // Fallback กลับไปใช้แบบธรรมดาเผื่อรันบน Desktop ทั่วไปที่ไม่มี SW
                fallbackStandardNotification(title, options);
            }
        } else {
            fallbackStandardNotification(title, options);
        }
    }
}

// ฟังก์ชันสำรองกรณีไม่มี Service Worker
function fallbackStandardNotification(title, options) {
    const notif = new Notification(title, options);
    notif.onclick = function(event) {
        event.preventDefault();
        window.focus();
        notif.close();
    };
}
    </script>
</body>
</html>
