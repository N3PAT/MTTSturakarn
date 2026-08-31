<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (empty($_SESSION['student_code'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$student_code = $_SESSION['student_code'];
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['endpoint']) || !isset($data['keys'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

$endpoint = $data['endpoint'];
$p256dh = $data['keys']['p256dh'] ?? '';
$auth = $data['keys']['auth'] ?? '';

try {
    // บันทึกหรืออัปเดตข้อมูล Subscription ของนักเรียน
    $stmt = $conn->prepare("
        INSERT INTO push_subscriptions (student_code, endpoint, p256dh, auth) 
        VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth)
    ");
    $stmt->bind_param("ssss", $student_code, $endpoint, $p256dh, $auth);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
