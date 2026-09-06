<?php
// บังคับให้ไฟล์นี้ตอบกลับเป็น JSON เท่านั้น
header('Content-Type: application/json; charset=utf-8');

// ปิดการแสดง Error บนหน้าเว็บ ป้องกันไม่ให้มี HTML หรือข้อความแปลกๆ หลุดไปปนกับ JSON
ini_set('display_errors', 0);

// 1. รับค่า JSON ที่ส่งมาจาก JavaScript
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// ตรวจสอบว่ามีข้อมูลส่งมาหรือไม่
if (!$data || !isset($data['endpoint'])) {
    echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล Subscription']);
    exit();
}

// 2. ดึงค่าที่ต้องใช้บันทึกลงฐานข้อมูล
$endpoint = $data['endpoint'];
$p256dh = $data['keys']['p256dh'];
$auth = $data['keys']['auth'];

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

    // 5. บันทึกข้อมูลลง Table (แก้ไขชื่อ table push_subscribers ให้ตรงกับของคุณ)
    $stmt = $pdo->prepare("INSERT INTO push_subscribers (endpoint, p256dh, auth) VALUES (:endpoint, :p256dh, :auth)");
    $stmt->execute([
        ':endpoint' => $endpoint,
        ':p256dh' => $p256dh,
        ':auth' => $auth
    ]);

    // ตอบกลับไปหา JavaScript ว่าบันทึกสำเร็จ
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // หากเคยบันทึก endpoint นี้ไปแล้ว (Duplicate entry) ถือว่าสำเร็จเช่นกัน
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => true, 'message' => 'มีข้อมูลนี้ในระบบแล้ว']);
    } else {
        // บันทึก Error ลงระบบของ Render จะได้เข้ามาเช็คได้
        error_log("Database Error: " . $e->getMessage());
        
        // ตอบกลับ error ไปยัง JavaScript
        echo json_encode(['success' => false, 'error' => 'เชื่อมต่อหรือบันทึกข้อมูลไม่สำเร็จ กรุณาเช็ค Log']);
    }
}
?>
