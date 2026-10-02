<?php
// 1. ตั้งค่า Timezone ของ PHP
date_default_timezone_set('Asia/Bangkok');

// 2. เปิด Error ไว้ชั่วคราวเพื่อดูปัญหา (ถ้าใช้งานจริงค่อยแก้เป็น 0)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// 3. ดึงข้อมูลเชื่อมต่อฐานข้อมูลจาก Environment Variables บน Render
// หมายเหตุ: ตัวพิมพ์เล็ก-ใหญ่ต้องตรงกับที่ตั้งไว้ใน Render ทุกตัวอักษร
$host   = getenv('Host') ?: '';
$port   = getenv('Port') ?: ''; // ใส่ default port ของ Aiven เผื่อไว้
$user   = getenv('User') ?: '';
$pass   = getenv('Password') ?: '';
$dbname = getenv('Databasename') ?: '';

try {
    // 4. เตรียมชุดคำสั่งเชื่อมต่อแบบ PDO (รองรับ MySQL)
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    
    // 5. ตั้งค่า Option การเชื่อมต่อ รวมถึงการเปิดใช้งาน SSL สำหรับ Aiven
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // เปิดใช้งาน SSL แบบไม่บังคับตรวจสอบใบรับรอง (เทียบเท่า ssl_set(NULL...) ใน MySQLi)
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        PDO::MYSQL_ATTR_SSL_CA => NULL
    ];

    // 6. ทำการเชื่อมต่อฐานข้อมูล
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 7. ตั้งค่า Timezone ของ MySQL
    $pdo->exec("SET time_zone = '+07:00'");

} catch (PDOException $e) {
    // กรณีเชื่อมต่อไม่ได้ ให้แสดง Error ออกมาแทนที่จะเป็นหน้าขาว 500
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif; color:red;'>
        <h3>ไม่สามารถเชื่อมต่อฐานข้อมูลได้ (db.php)</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>");
}
?>
