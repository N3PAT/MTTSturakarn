<?php
// ตั้งค่า Timezone ของ PHP ให้เป็นเวลาประเทศไทย (สำคัญมาก ป้องกันเวลาเพี้ยน)
date_default_timezone_set('Asia/Bangkok');

// 1. ปิดการแสดง Error ของ PHP บนหน้าเว็บ (สำคัญมากใน Production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// 2. ตั้งค่าให้ MySQLi โยนข้อผิดพลาดเป็น Exception (เพื่อดักจับได้)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ข้อมูลเชื่อมต่อฐานข้อมูล Aiven Cloud
$host   = 'mtts-mtts.a.aivencloud.com';
$port   = 15983; // Port ของ Aiven Cloud
$user   = 'avnadmin'; 
$pass   = 'AVNS_OXeWWNxKP7DEpKb3Myl'; 
$dbname = 'defaultdb'; // หรือใส่ 'defaultdb' หากไม่ได้สร้าง DB ชื่อ if0_42434539_mtts[cite: 3]

try {
    // 3. เตรียมการเชื่อมต่อฐานข้อมูล
    $conn = mysqli_init();
    if (!$conn) {
        throw new Exception("mysqli_init failed");
    }

    // 4. ตั้งค่าเปิดใช้งาน SSL (จำเป็นมากสำหรับ Aiven Cloud)
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);

    // 5. เชื่อมต่อฐานข้อมูลพร้อมระบุ Port และ SSL Flag
    $conn->real_connect($host, $user, $pass, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);
    
    // ตั้งค่า Charset เป็น utf8mb4 (รองรับภาษาไทยและอิโมจิได้สมบูรณ์ที่สุด)
    $conn->set_charset("utf8mb4");

    // ตั้งค่า Timezone ของ MySQL (Database) ให้เป็น +07:00 (เวลาไทย)
    $conn->query("SET time_zone = '+07:00'");

} catch (Exception $e) {
    // 6. หากเชื่อมต่อล้มเหลว ให้บันทึก Error ลง Log ของ Server (คนทั่วไปมองไม่เห็น)
    error_log("Database Connection Error: " . $e->getMessage());
    
    // 7. แสดงข้อความแจ้งเตือนแบบปลอดภัยให้ผู้ใช้เห็น
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้ กรุณาแจ้งผู้ดูแลระบบ");
}
?>
