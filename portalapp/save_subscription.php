<?php
session_start();
require 'db.php'; // เปลี่ยนเป็นไฟล์ตั้งค่าเชื่อมต่อ DB ของคุณ

header('Content-Type: application/json');

// รับข้อมูล JSON จาก php://input
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['endpoint'])) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

// ดึงค่า student_code จาก Session (พร้อมตัวดักรองรับทั้งสองชื่อ)
$student_id = $_SESSION['student_code'] ?? $_SESSION['student_id'] ?? 'unknown';

$endpoint = $data['endpoint'];
$p256dh = $data['keys']['p256dh'];
$auth = $data['keys']['auth'];

// ตรวจสอบว่าเคยมี Endpoint นี้ในระบบหรือไม่
$stmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ?");
$stmt->bind_param("s", $endpoint);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // หากมีแล้ว ให้อัปเดตรหัสนักเรียนทับ (กรณีมีการล็อกอินสลับผู้ใช้ในเบราว์เซอร์เดิม)
    $updateStmt = $conn->prepare("UPDATE push_subscriptions SET student_id = ? WHERE endpoint = ?");
    $updateStmt->bind_param("ss", $student_id, $endpoint);
    $updateStmt->execute();
} else {
    // หากยังไม่มี ให้เพิ่มเรคคอร์ดใหม่
    $insertStmt = $conn->prepare("INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
    $insertStmt->bind_param("ssss", $student_id, $endpoint, $p256dh, $auth);
    $insertStmt->execute();
}

echo json_encode(['success' => true]);
?>
