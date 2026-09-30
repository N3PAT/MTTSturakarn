<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

// ปิดการแสดงผล Error ออกหน้าเว็บ แต่ยังให้ PHP แจ้ง Error ใน Log ของ Server
error_reporting(E_ALL);
ini_set('display_errors', 0); 

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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['endpoint'])) {
        
        $endpoint = $_POST['endpoint'];
        // Web Push ปกติต้องใช้ p256dh และ auth แต่ใส่ fallback ไว้เผื่อกรณีอื่น
        $p256dh = $_POST['p256dh'] ?? '';
        $auth   = $_POST['auth'] ?? '';

        // 3. เช็คว่ามี student_id นี้ในตารางแล้วหรือไม่
        $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE student_id = ? LIMIT 1");
        $checkStmt->bind_param("s", $studentCode);
        $checkStmt->execute();
        $res = $checkStmt->get_result();

        if ($res->num_rows > 0) {
            // [แก้ไข] อัปเดตข้อมูล Subscription ใหม่ แทนที่จะข้ามการบันทึก
            $updateStmt = $conn->prepare("UPDATE push_subscriptions SET endpoint = ?, p256dh = ?, auth = ? WHERE student_id = ?");
            $updateStmt->bind_param("ssss", $endpoint, $p256dh, $auth, $studentCode);
            $updateStmt->execute();
            $updateStmt->close();

            $message = 'อัปเดตข้อมูลการแจ้งเตือนสำเร็จ';
        } else {
            // 4. เพิ่มข้อมูลใหม่ลงในฐานข้อมูล
            $insertStmt = $conn->prepare("INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("ssss", $studentCode, $endpoint, $p256dh, $auth);
            $insertStmt->execute();
            $insertStmt->close();

            $message = 'บันทึกการแจ้งเตือนสำเร็จ';
        }
        $checkStmt->close();

        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => true, 'message' => $message]);

    } else {
        if (ob_get_length()) ob_clean();
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (Missing endpoint)']);
    }

} catch (Throwable $e) {
    // [แก้ไข] บันทึก Error ลง Server Log แทนการส่งให้ Client
    error_log("Push Subscription Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    if (ob_get_length()) ob_clean();
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false,
        'error' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล โปรดลองใหม่อีกครั้ง'
        // ลบ getFile() และ getLine() ออกเพื่อความปลอดภัย
    ]);
}

exit();
?>
