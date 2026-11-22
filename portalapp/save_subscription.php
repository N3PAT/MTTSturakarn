<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function respondJson(int $statusCode, array $payload): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    require_once 'db.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(405, ['success' => false, 'error' => 'Method Not Allowed']);
    }

    // 1. ตรวจสอบการเข้าสู่ระบบ
    if (empty($_SESSION['student_code'])) {
        respondJson(401, ['success' => false, 'error' => 'Unauthorized: กรุณาเข้าสู่ระบบ']);
    }

    $studentCode = (string) $_SESSION['student_code'];

    // 2. รับข้อมูล JSON ที่ส่งมาจาก Fetch API
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!is_array($data)) {
        respondJson(400, ['success' => false, 'error' => 'รูปแบบข้อมูล JSON ไม่ถูกต้อง']);
    }

    $endpoint = trim($data['endpoint'] ?? '');
    $p256dh   = trim($data['keys']['p256dh'] ?? '');
    $auth     = trim($data['keys']['auth'] ?? '');

    // 3. ตรวจสอบความครบถ้วนและรูปแบบของ Subscription
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        respondJson(400, ['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (ต้องมี endpoint, p256dh และ auth)']);
    }

    if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
        respondJson(400, ['success' => false, 'error' => 'รูปแบบ endpoint ไม่ถูกต้อง']);
    }

    // 4. เช็คว่ามี student_id นี้ในตารางแล้วหรือไม่
    $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE student_id = ? LIMIT 1");
    $checkStmt->bind_param("s", $studentCode);
    $checkStmt->execute();
    $res = $checkStmt->get_result();
    $exists = $res->num_rows > 0;
    $checkStmt->close();

    if ($exists) {
        $updateStmt = $conn->prepare("UPDATE push_subscriptions SET endpoint = ?, p256dh = ?, auth = ? WHERE student_id = ?");
        $updateStmt->bind_param("ssss", $endpoint, $p256dh, $auth, $studentCode);
        $updateStmt->execute();
        $updateStmt->close();

        $message = 'อัปเดตข้อมูลการแจ้งเตือนสำเร็จ';
    } else {
        $insertStmt = $conn->prepare("INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
        $insertStmt->bind_param("ssss", $studentCode, $endpoint, $p256dh, $auth);
        $insertStmt->execute();
        $insertStmt->close();

        $message = 'บันทึกการแจ้งเตือนสำเร็จ';
    }

    respondJson(200, ['success' => true, 'message' => $message]);

} catch (Throwable $e) {
    error_log("Push Subscription Error: " . $e->getMessage());
    respondJson(500, ['success' => false, 'error' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูลการแจ้งเตือน']);
}
