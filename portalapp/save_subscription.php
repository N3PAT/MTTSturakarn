<?php
// บังคับให้ไฟล์นี้ตอบกลับเป็น JSON เท่านั้น
header('Content-Type: application/json; charset=utf-8');

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

// 3. ตั้งค่าการเชื่อมต่อฐานข้อมูล (เปลี่ยนให้ตรงกับเซิร์ฟเวอร์ของคุณ)
$db_host = 'localhost';
$db_name = 'ชื่อฐานข้อมูลของคุณ';
$db_user = 'ชื่อผู้ใช้ฐานข้อมูล';
$db_pass = 'รหัสผ่านฐานข้อมูล';

try {
    // เชื่อมต่อฐานข้อมูลด้วย PDO
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 4. บันทึกข้อมูลลง Table (สมมติว่าชื่อ table คือ push_subscribers)
    // เปลี่ยนชื่อ table และชื่อคอลัมน์ให้ตรงกับฐานข้อมูลของคุณ
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
        // ตอบกลับ error ของฐานข้อมูลกลับไป
        echo json_encode(['success' => false, 'error' => 'Database Error: ' . $e->getMessage()]);
    }
}
?>
