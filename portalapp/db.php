<?php
// 1. ปิดการแสดง Error ของ PHP บนหน้าเว็บ (สำคัญมากใน Production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// 2. ตั้งค่าให้ MySQLi โยนข้อผิดพลาดเป็น Exception (เพื่อดักจับได้)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = '0.0.0.0'; // ตัดช่องว่างด้านหลังออกแล้ว
$user = 'root'; 
$pass = 'v_8K6oo1tE-6UCXa'; // เปลี่ยนรหัสผ่านใหม่ในระบบแล้วนำมาใส่ตรงนี้นะครับ
$dbname = 'school_duty';

try {
    // พยายามเชื่อมต่อฐานข้อมูล
    $conn = new mysqli($host, $user, $pass, $dbname);
    $conn->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {
    // 3. หากเชื่อมต่อล้มเหลว ให้บันทึก Error ลง Log ของ Server (คนทั่วไปมองไม่เห็น)
    error_log("Database Connection Error: " . $e->getMessage());
    
    // 4. แสดงข้อความแจ้งเตือนแบบปลอดภัยให้ผู้ใช้เห็น
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้ กรุณาแจ้งผู้ดูแลระบบ");
}
?>
