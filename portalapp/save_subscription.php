<?php
// เริ่ม Session (สำคัญถ้าคุณต้องการแนบ Session นักเรียนในอนาคต)
session_start();

// บังคับให้ไฟล์นี้ตอบกลับเป็น JSON เท่านั้น
header('Content-Type: application/json; charset=utf-8');

// ปิดการแสดง Error บนหน้าเว็บ ป้องกันไม่ให้มี HTML หลุดไปปนกับ JSON
ini_set('display_errors', 0);

// 1. ตรวจสอบว่ามีข้อมูลส่งมาจาก FormData หรือไม่
if (!isset($_POST['endpoint']) || empty($_POST['endpoint'])) {
    echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล Subscription']);
    exit();
}

// 2. ดึงค่าที่ต้องใช้บันทึกลงฐานข้อมูล
$endpoint = $_POST['endpoint'];
$p256dh = $_POST['p256dh'];
$auth = $_POST['auth'];

// 3. ดึงข้อมูลเชื่อมต่อฐานข้อมูลจาก Environment Variables
$host   = getenv('Host');
$port   = (int)getenv('Port');
$user   = getenv('User');
$pass   = getenv('Password');
$dbname = getenv('Databasename');

try {
    // 4. เชื่อมต่อฐานข้อมูลด้วย PDO พร้อมตั้งค่า Port และ SSL (สำหรับ Aiven)
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // สำคัญมากสำหรับ Aiven: ป้องกัน Error การเชื่อมต่อ SSL
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];
    
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 5. บันทึกข้อมูลลง Table (ต้องมี Table ชื่อ push_subscribers ในฐานข้อมูลแล้ว)
    $stmt = $pdo->prepare("INSERT INTO push_subscriptions (endpoint, p256dh, auth) VALUES (:endpoint, :p256dh, :auth)");
    $stmt->execute([
        ':endpoint' => $endpoint,
        ':p256dh' => $p256dh,
        ':auth' => $auth
    ]);

    // ตอบกลับไปหา JavaScript ว่าบันทึกสำเร็จ
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // หากเคยบันทึก endpoint นี้ไปแล้ว (Duplicate entry - Error 23000) ถือว่าสำเร็จเช่นกัน
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => true, 'message' => 'มีข้อมูลนี้ในระบบแล้ว']);
    } else {
        // บันทึก Error ลงระบบของ Render
        error_log("Database Error: " . $e->getMessage());
        
        // ตอบกลับ Error ไปแสดงผลที่ Alert (แก้ไขให้แสดง Error ฐานข้อมูลให้เห็นชัดเจนตอนทดสอบ)
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    }
}
?>
