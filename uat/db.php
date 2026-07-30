<?php
// ตั้งค่า Timezone ของ PHP ให้เป็นเวลาประเทศไทย (สำคัญมาก ป้องกันเวลาเพี้ยน)
date_default_timezone_set('Asia/Bangkok');

// 1. ปิดการแสดง Error ของ PHP บนหน้าเว็บ (สำคัญมากใน Production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// 2. ตั้งค่าให้ MySQLi โยนข้อผิดพลาดเป็น Exception (เพื่อดักจับได้)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = 'sql301.infinityfree.com';
$user = 'if0_42434539'; 
$pass = 'd7UeGE9O16HwKCM'; // เปลี่ยนรหัสผ่านใหม่ในระบบแล้วนำมาใส่ตรงนี้นะครับ
$dbname = 'if0_42434539_uatmtts';

try {
    // พยายามเชื่อมต่อฐานข้อมูล
    $conn = new mysqli($host, $user, $pass, $dbname);
    
    // ตั้งค่า Charset เป็น utf8mb4 (รองรับภาษาไทยและอิโมจิได้สมบูรณ์ที่สุด)
    $conn->set_charset("utf8mb4");

    // ตั้งค่า Timezone ของ MySQL (Database) ให้เป็น +07:00 (เวลาไทย)
    // เพิ่มคำว่า "SESSION" เข้าไป เพื่อให้แน่ใจว่าเปลี่ยนแค่ในระดับเซสชัน (InfinityFree จะไม่บล็อกคำสั่งนี้)
    $conn->query("SET SESSION time_zone = '+07:00'");

} catch (mysqli_sql_exception $e) {
    // 3. หากเชื่อมต่อล้มเหลว ให้บันทึก Error ลง Log ของ Server (คนทั่วไปมองไม่เห็น)
    error_log("Database Connection Error: " . $e->getMessage());
    
    // 4. แสดงข้อความแจ้งเตือนแบบปลอดภัยให้ผู้ใช้เห็น
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้ กรุณาแจ้งผู้ดูแลระบบ");
}
?>