<?php
session_start();

// ตรวจสอบสิทธิ์ Admin (หากไม่ได้ล็อกอินให้กลับไปหน้าหลัก)
if (empty($_SESSION['admin_id'])) {
    header("Location: admin_main.php");
    exit();
}

require_once 'db.php';

// ตั้งค่า Charset การเชื่อมต่อ
if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset("utf8mb4");
}

// 1. ดึงสถิติภาพรวม
$sql_stat = "
    SELECT 
        COUNT(s.student_code) AS total_students,
        COUNT(DISTINCT p.student_id) AS registered_count,
        (COUNT(s.student_code) - COUNT(DISTINCT p.student_id)) AS unregistered_count
    FROM students s
    LEFT JOIN push_subscriptions p ON s.student_code = p.student_id
";

$res_stat = $conn->query($sql_stat);
$stat = ($res_stat && $res_stat->num_rows > 0) ? $res_stat->fetch_assoc() : [
    'total_students' => 0,
    'registered_count' => 0,
    'unregistered_count' => 0
];

// 2. ดึงรายชื่อผู้ที่ยังไม่ลงทะเบียน PWA
$sql_unregistered = "
    SELECT 
        s.student_code, 
        s.prefix, 
        s.first_name, 
        s.last_name,
        s.company,
        s.platoon
    FROM students s
    LEFT JOIN push_subscriptions p ON s.student_code = p.student_id
    WHERE p.student_id IS NULL
    ORDER BY s.student_code ASC
";

$result = $conn->query($sql_unregistered);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการลงทะเบียน PWA - MTTS</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="bg-light">

<div class="container py-4" style="max-width: 900px;">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="turakarn.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chevron-left"></i> ย้อนกลับ</a>
            <h3 class="mb-0 fw-bold">📱 รายงานการลงทะเบียน PWA</h3>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-dark"><i class="fas fa-print"></i> พิมพ์รายงาน</button>
    </div>

    <!-- การ์ดแสดงสถิติ -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white p-3 shadow-sm border-0">
                <div class="small opacity-75">นักเรียนทั้งหมด</div>
                <h2 class="mb-0 fw-bold"><?= number_format($stat['total_students'] ?? 0); ?> <span class="fs-6 fw-normal">นาย</span></h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white p-3 shadow-sm border-0">
                <div class="small opacity-75">ลงทะเบียน PWA แล้ว</div>
                <h2 class="mb-0 fw-bold"><?= number_format($stat['registered_count'] ?? 0); ?> <span class="fs-6 fw-normal">นาย</span></h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-danger text-white p-3 shadow-sm border-0">
                <div class="small opacity-75">ยังไม่ลงทะเบียน</div>
                <h2 class="mb-0 fw-bold"><?= number_format($stat['unregistered_count'] ?? 0); ?> <span class="fs-6 fw-normal">นาย</span></h2>
            </div>
        </div>
    </div>

    <!-- ตารางรายชื่อผู้ยังไม่ลงทะเบียน -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 text-danger fw-bold"><i class="fas fa-user-times me-2"></i>รายชื่อผู้ที่ยังไม่ได้ลงทะเบียนรับแจ้งเตือน PWA</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th width="70" class="text-center">ลำดับ</th>
                            <th width="140">รหัสนักเรียน</th>
                            <th>ชื่อ - นามสกุล</th>
                            <th width="180">สังกัด (กองร้อย/หมวด)</th>
                            <th width="140" class="text-center">สถานะ PWA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
                                <?php 
                                    $fullname = trim(($row['prefix'] ?? '') . $row['first_name'] . ' ' . $row['last_name']);
                                    $unit = (!empty($row['company']) ? 'ร้อย.' . $row['company'] : '') . (!empty($row['platoon']) ? ' หมวด ' . $row['platoon'] : '-');
                                ?>
                                <tr>
                                    <td class="text-center"><?= $i++; ?></td>
                                    <td><strong><?= htmlspecialchars($row['student_code']); ?></strong></td>
                                    <td><?= htmlspecialchars($fullname); ?></td>
                                    <td><?= htmlspecialchars($unit); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-danger">ยังไม่ลงทะเบียน</span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-success fw-bold">
                                    <i class="fas fa-check-circle me-1"></i> นักเรียนทุกคนลงทะเบียนใช้งาน PWA ครบแล้ว
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
