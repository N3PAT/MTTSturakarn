<?php
// เปิดการแสดง Error ชั่วคราวเพื่อใช้วิเคราะห์ปัญหา
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. ดักจับและเรียกไฟล์เชื่อมต่อฐานข้อมูล (รองรับทั้ง /admin/ และโฟลเดอร์หลัก)
if (file_exists('../db.php')) {
    require_once '../db.php';
} elseif (file_exists('db.php')) {
    require_once 'db.php';
} elseif (file_exists('../config.php')) {
    require_once '../config.php';
} else {
    die("ไม่พบไฟล์เชื่อมต่อฐานข้อมูล (db.php หรือ config.php)");
}

// ตรวจสอบการเชื่อมต่อ $conn
if (!isset($conn) || $conn->connect_error) {
    die("การเชื่อมต่อฐานข้อมูลล้มเหลว: " . ($conn->connect_error ?? 'ไม่พบตัวแปร $conn'));
}

// 2. ดึงสถิติภาพรวม
$sql_stat = "
    SELECT 
        COUNT(s.student_code) AS total_students,
        COUNT(DISTINCT p.student_id) AS registered_count,
        (COUNT(s.student_code) - COUNT(DISTINCT p.student_id)) AS unregistered_count
    FROM students s
    LEFT JOIN push_subscriptions p ON s.student_code = p.student_id
";

$res_stat = $conn->query($sql_stat);

if (!$res_stat) {
    die("SQL Error (Stat): " . $conn->error);
}
$stat = $res_stat->fetch_assoc();

// 3. ดึงรายชื่อผู้ที่ยังไม่ลงทะเบียน PWA
$sql_unregistered = "
    SELECT 
        s.student_code, 
        s.prefix, 
        s.first_name, 
        s.last_name,
        s.company
    FROM students s
    LEFT JOIN push_subscriptions p ON s.student_code = p.student_id
    WHERE p.student_id IS NULL
    ORDER BY s.student_code ASC
";

$result = $conn->query($sql_unregistered);

if (!$result) {
    die("SQL Error (Unregistered): " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการลงทะเบียน PWA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-4">
    <h3 class="mb-4">📱 รายงานสถานะการลงทะเบียนใช้งาน PWA Notification</h3>

    <!-- การ์ดแสดงสถิติ -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white p-3 shadow-sm">
                <h5>นักเรียนทั้งหมด</h5>
                <h2 class="mb-0"><?= number_format($stat['total_students'] ?? 0); ?> คน</h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white p-3 shadow-sm">
                <h5>ลงทะเบียน PWA แล้ว</h5>
                <h2 class="mb-0"><?= number_format($stat['registered_count'] ?? 0); ?> คน</h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-danger text-white p-3 shadow-sm">
                <h5>ยังไม่ลงทะเบียน</h5>
                <h2 class="mb-0"><?= number_format($stat['unregistered_count'] ?? 0); ?> คน</h2>
            </div>
        </div>
    </div>

    <!-- ตารางรายชื่อผู้ยังไม่ลงทะเบียน -->
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-danger">รายชื่อผู้ที่ยังไม่ได้ลงทะเบียนรับแจ้งเตือน PWA</h5>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">🖨️ พิมพ์ / Export PDF</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th width="80" class="text-center">ลำดับ</th>
                            <th width="150">รหัสนักเรียน</th>
                            <th>ยศ - ชื่อ - นามสกุล</th>
                            <th width="150">สังกัด/หน่วย</th>
                            <th width="150" class="text-center">สถานะ PWA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="text-center"><?= $i++; ?></td>
                                    <td><strong><?= htmlspecialchars($row['student_code'] ?? ''); ?></strong></td>
                                    <td><?= htmlspecialchars(($row['prefix'] ?? '') . ($row['first_name'] ?? '') . " " . ($row['last_name'] ?? '')); ?></td>
                                    <td><?= htmlspecialchars($row['company'] ?? '-'); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-danger">ยังไม่ลงทะเบียน</span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-success">
                                    นักเรียนทุกคนลงทะเบียนใช้งาน PWA ครบแล้ว
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>
