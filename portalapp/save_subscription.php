<?php
// เริ่ม Output Buffering เพื่อป้องกันปัญหาช่องว่างหรือข้อความแปลกปลอมหลุดไปรบกวน JSON
ob_start();
session_start();

// บังคับให้ไฟล์นี้ตอบกลับเป็น JSON เท่านั้น
header('Content-Type: application/json; charset=utf-8');

// ปิดการแสดง Error บนหน้าเว็บ ป้องกันไม่ให้มี HTML หลุดไปปนกับ JSON
ini_set('display_errors', 0);
error_reporting(0);

// เรียกใช้การเชื่อมต่อฐานข้อมูลตัวเดียวกับระบบหลักของคุณ
require_once 'db.php';

// 1. ตรวจสอบการเข้าสู่ระบบ
if (empty($_SESSION['student_code'])) {
    if (ob_get_length()) ob_clean(); // ล้างข้อมูลแปลกปลอมก่อนหน้าทิ้ง
    echo json_encode(['success' => false, 'error' => 'Unauthorized: กรุณาเข้าสู่ระบบ']);
    exit();
}

$studentCode = $_SESSION['student_code'];

// 2. ตรวจสอบว่ามีข้อมูลส่งมาจาก FormData หรือไม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['endpoint']) && !empty($_POST['endpoint'])) {
    
    // ล้างข้อมูลหน้าจอที่อาจจะถูกแทรกมาจาก db.php
    if (ob_get_length()) ob_clean();
    
    $endpoint = $_POST['endpoint'];
    $p256dh = $_POST['p256dh'] ?? '';
    $auth = $_POST['auth'] ?? '';

    try {
        // 3. เช็คว่ามี student_id นี้ในตารางแล้วหรือไม่
        $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE student_id = ? LIMIT 1");
        $checkStmt->bind_param("s", $studentCode);
        $checkStmt->execute();
        $res = $checkStmt->get_result();

        if ($res->num_rows > 0) {
            // ถ้าเจอ student_id ซ้ำ ให้ตอบกลับว่าสำเร็จไปเลย โดยไม่ต้องบันทึกใหม่
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

        // ตอบกลับไปหา JavaScript ว่าบันทึกสำเร็จ
        echo json_encode(['success' => true, 'message' => 'บันทึกการแจ้งเตือนสำเร็จ']);

    } catch (Exception $e) {
        // Error code 1062 คือ Duplicate entry สำหรับ MySQLi (กรณี endpoint ซ้ำ)
        if ($conn->errno === 1062) {
            echo json_encode(['success' => true, 'message' => 'มีข้อมูล endpoint นี้ในระบบแล้ว']);
        } else {
            error_log("Database Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
        }
    }
} else {
    // หากไม่มี endpoint ส่งมา
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (Missing endpoint)']);
}

exit();
?>
