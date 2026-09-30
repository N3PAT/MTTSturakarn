<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0); 
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

    // 2. รับข้อมูล JSON ที่ส่งมาจาก Fetch API
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    // 3. ตรวจสอบว่ามีข้อมูล endpoint ถูกส่งมาหรือไม่
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($data['endpoint'])) {
        
        $endpoint = $data['endpoint'];
        // Object ของ Push Subscription ค่า p256dh และ auth จะซ้อนอยู่ใน 'keys'
        $p256dh = $data['keys']['p256dh'] ?? '';
        $auth   = $data['keys']['auth'] ?? '';

        // 4. เช็คว่ามี student_id นี้ในตารางแล้วหรือไม่
        $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE student_id = ? LIMIT 1");
        $checkStmt->bind_param("s", $studentCode);
        $checkStmt->execute();
        $res = $checkStmt->get_result();

        if ($res->num_rows > 0) {
            // อัปเดตข้อมูล Subscription เดิมให้เป็น Token ล่าสุด
            $updateStmt = $conn->prepare("UPDATE push_subscriptions SET endpoint = ?, p256dh = ?, auth = ? WHERE student_id = ?");
            $updateStmt->bind_param("ssss", $endpoint, $p256dh, $auth, $studentCode);
            $updateStmt->execute();
            $updateStmt->close();
            
            $message = 'อัปเดตข้อมูลการแจ้งเตือนสำเร็จ';
        } else {
            // เพิ่มข้อมูลใหม่ลงในฐานข้อมูล
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
        http_response_code(400); // ตั้งสเตตัสเป็น 400 จะได้เห็นชัดๆ ใน Network Log
        echo json_encode(['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (ไม่พบ endpoint)']);
    }

} catch (Throwable $e) {
    error_log("Push Subscription Error: " . $e->getMessage());
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล']);
}

exit();
?>
