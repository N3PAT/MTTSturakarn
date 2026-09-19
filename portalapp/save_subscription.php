<?php
// เริ่ม Session เพื่อดึงข้อมูลนักเรียนที่ล็อกอินอยู่
session_start();

// บังคับให้ไฟล์นี้ตอบกลับเป็น JSON เท่านั้น
header('Content-Type: application/json; charset=utf-8');

// ปิดการแสดง Error บนหน้าเว็บ ป้องกันไม่ให้มี HTML หลุดไปปนกับ JSON
ini_set('display_errors', 0);

// 1. ดึงรหัสนักเรียนจาก Session (เปลี่ยนเป็น student_code ตามระบบของคุณ)
$student_id = isset($_SESSION['student_code']) ? $_SESSION['student_code'] : '00000';

// 2. ตรวจสอบว่ามีข้อมูลส่งมาจาก FormData หรือไม่
if (!isset($_POST['endpoint']) || empty($_POST['endpoint'])) {
    echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล Subscription']);
    exit();
}

// 3. ดึงค่าที่ต้องใช้บันทึกลงฐานข้อมูล
$endpoint = $_POST['endpoint'];
$p256dh = $_POST['p256dh'];
$auth = $_POST['auth'];

// 4. ดึงข้อมูลเชื่อมต่อฐานข้อมูลจาก Environment Variables
$host   = getenv('Host');
$port   = (int)getenv('Port');
$user   = getenv('User');
$pass   = getenv('Password');
$dbname = getenv('Databasename');

try {
    // 5. เชื่อมต่อฐานข้อมูลด้วย PDO
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];
    
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 6. ตรวจสอบว่ามี student_id นี้ในตารางแล้วหรือไม่
    $checkStmt = $pdo->prepare("SELECT id FROM push_subscriptions WHERE student_id = :student_id LIMIT 1");
    $checkStmt->execute([':student_id' => $student_id]);

    if ($checkStmt->rowCount() > 0) {
        // ถ้าเจอ student_id ซ้ำ ให้ตอบกลับว่าสำเร็จไปเลย โดยไม่ต้องบันทึกใหม่
        echo json_encode(['success' => true, 'message' => 'มีข้อมูล student_id นี้ในระบบแล้ว ไม่บันทึกซ้ำ']);
        exit();
    }

    // 7. เพิ่ม student_id เข้าไปในคำสั่ง INSERT INTO push_subscriptions
    $stmt = $pdo->prepare("INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (:student_id, :endpoint, :p256dh, :auth)");
    $stmt->execute([
        ':student_id' => $student_id,
        ':endpoint'   => $endpoint,
        ':p256dh'     => $p256dh,
        ':auth'       => $auth
    ]);

    // ตอบกลับไปหา JavaScript ว่าบันทึกสำเร็จ
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // หากเคยบันทึก endpoint นี้ไปแล้ว (Duplicate entry - Error 23000) ถือว่าสำเร็จเช่นกัน
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => true, 'message' => 'มีข้อมูลนี้ในระบบแล้ว']);
    } else {
        error_log("Database Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    }
}
?>
