<?php
// 1. ตั้งค่า Timezone ของ PHP
date_default_timezone_set('Asia/Bangkok');

// 2. ปิดการแสดง Error บนหน้าเว็บ (เปิดไว้เฉพาะ Error Log)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// 3. ตั้งค่าให้ MySQLi โยน Exception เมื่อเกิดข้อผิดพลาด
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 4. ดึงข้อมูลเชื่อมต่อฐานข้อมูลจาก Environment Variables
$host   = getenv('Host');
$port   = (int)getenv('Port'); // แปลงเป็นตัวเลขเผื่อไว้
$user   = getenv('User');
$pass   = getenv('Password');
$dbname = getenv('Databasename');

try {
    // 5. เตรียมการเชื่อมต่อ
    $conn = mysqli_init();
    if (!$conn) {
        throw new Exception("mysqli_init failed");
    }

    // 6. ตั้งค่าเปิดใช้งาน SSL สำหรับ Aiven Cloud
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);

    // 7. เชื่อมต่อฐานข้อมูล
    $conn->real_connect($host, $user, $pass, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);
    
    // 8. ตั้งค่า Charset และ Timezone ของ MySQL
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+07:00'");

} catch (Exception $e) {
    // บันทึก Error ลง Log และแสดงข้อความให้ผู้ใช้
    error_log("Database Connection Error: " . $e->getMessage());
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้ กรุณาแจ้งผู้ดูแลระบบ");
}
?>
