<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// หากเรียกข้ามโดเมน ให้ตั้งค่า Origin เฉพาะเจาะจงแทน * เมื่อใช้ credentials
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://mttsturakarn.onrender.com',
    'https://mttsthurakarn.rf.gd',
];
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$deviceId = trim((string)($data['device_id'] ?? ''));
$platform = trim((string)($data['platform'] ?? 'android'));

if ($deviceId === '' || strlen($deviceId) > 128) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing device_id',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($platform === '' || strlen($platform) > 32) {
    $platform = 'android';
}

// รองรับทั้ง student_code และ student_id จาก Session (ปรับให้ตรงกับระบบ Login ของคุณ)
$studentId = $_SESSION['student_code'] ?? ($_SESSION['student_id'] ?? null);
if ($studentId !== null) {
    $studentId = trim((string)$studentId);
    if ($studentId === '') {
        $studentId = null;
    }
}

try {
    // กรณีใช้ MySQLi ($conn)
    if (isset($conn) && $conn instanceof mysqli) {
        $sql = 'INSERT INTO android_devices (device_id, student_id, platform, last_seen_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    student_id = COALESCE(VALUES(student_id), student_id),
                    platform = VALUES(platform),
                    last_seen_at = NOW()';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param('sss', $deviceId, $studentId, $platform);
        if (!$stmt->execute()) {
            throw new RuntimeException('Execute failed: ' . $stmt->error);
        }
        $stmt->close();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        // กรณีโปรเจกต์ใช้ PDO ($pdo)
        $sql = 'INSERT INTO android_devices (device_id, student_id, platform, last_seen_at)
                VALUES (:device_id, :student_id, :platform, NOW())
                ON DUPLICATE KEY UPDATE
                    student_id = COALESCE(VALUES(student_id), student_id),
                    platform = VALUES(platform),
                    last_seen_at = NOW()';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':device_id'  => $deviceId,
            ':student_id' => $studentId,
            ':platform'   => $platform,
        ]);
    } else {
        throw new RuntimeException('Database connection ($conn or $pdo) not found');
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Device saved successfully',
        'device_id'  => $deviceId,
        'student_id' => $studentId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('save_device.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save device',
    ], JSON_UNESCAPED_UNICODE);
}
