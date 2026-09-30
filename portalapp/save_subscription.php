<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

// เปิดการรายงาน Error ทั้งหมดเพื่อดักจับขณะพัฒนา
error_reporting(E_ALL);
ini_set('display_errors', 0); // ปิดการแสดงผลบนหน้าเว็บ เพื่อไม่ให้กระทบโครงสร้าง JSON

// บังคับให้ MySQLi โยน Exception เมื่อคำสั่ง SQL มีปัญหา
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'db.php';

    // 1. ตรวจสอบการเข้าสู่ระบบ
    if (empty($_SESSION['student_code'])) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'error' => 'Unauthorized: กรุณาเข้าสู่ระบบ']);
        exit();
    }

    $studentCode = $_SESSION['student_code'];

    // 2. ตรวจสอบว่ามีข้อมูลส่งมาจาก POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['endpoint']) && !empty($_POST['endpoint'])) {
        
        $endpoint = $_POST['endpoint'];
        $p256dh = $_POST['p256dh'] ?? '';
        $auth = $_POST['auth'] ?? '';

        // 3. เช็คว่ามี student_id นี้ในตารางแล้วหรือไม่
        $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE student_id = ? LIMIT 1");
        $checkStmt->bind_param("s", $studentCode);
        $checkStmt->execute();
        $res = $checkStmt->get_result();

        if ($res->num_rows > 0) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => true, 'message' => 'มีข้อมูลนักเรียนนี้ในระบบแล้ว ไม่บันทึกซ้ำ']);
            $checkStmt->close();
            exit();
        }
        $checkStmt->close();

        // 4. เพิ่มข้อมูลลงในฐานข้อมูล
        $stmt = $conn->prepare("INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $studentCode, $endpoint, $p256dh, $auth);
        $stmt->execute();
        $stmt->close();

        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => true, 'message' => 'บันทึกการแจ้งเตือนสำเร็จ']);

    } else {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (Missing endpoint หรือ ไม่ได้ส่งแบบ POST)']);
    }

} catch (Throwable $e) {
    // ดักจับ Error ทั้งหมดแล้วส่งรายละเอียดกลับไปแสดงผล
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'PHP/DB Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

exit();
?>
