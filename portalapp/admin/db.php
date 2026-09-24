<?php
// 1. ตั้งค่า Timezone ของ PHP
date_default_timezone_set('Asia/Bangkok');

// 2. ปิดการแสดง Error บนหน้าเว็บ (เปิดไว้เฉพาะ Error Log)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// 3. ตั้งค่าให้ MySQLi โยน Exception เมื่อเกิดข้อผิดพลาด
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ฟังก์ชันดึงค่า Environment Variables รองรับหลายรูปแบบตัวอักษร
function getEnvVar($key, $default = '') {
    $val = getenv($key);
    if ($val === false || $val === '') {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $val;
}

// 4. ดึงข้อมูลเชื่อมต่อฐานข้อมูล (รองรับทั้ง Host, HOST และ DB_HOST)
$host   = getEnvVar('Host') ?: getEnvVar('HOST') ?: getEnvVar('DB_HOST');
$port   = (int)(getEnvVar('Port') ?: getEnvVar('PORT') ?: getEnvVar('DB_PORT') ?: 3306);
$user   = getEnvVar('User') ?: getEnvVar('USER') ?: getEnvVar('DB_USER');
$pass   = getEnvVar('Password') ?: getEnvVar('PASSWORD') ?: getEnvVar('DB_PASS');
$dbname = getEnvVar('Databasename') ?: getEnvVar('DATABASE') ?: getEnvVar('DB_NAME');

try {
    // ตรวจสอบว่าดึงค่า Host และ User ได้หรือไม่
    if (empty($host) || empty($user)) {
        throw new Exception("ไม่พบข้อมูลการตั้งค่า Environment Variables ในระบบ Render (Host / User)");
    }

    // 5. เตรียมการเชื่อมต่อ
    $conn = mysqli_init();
    if (!$conn) {
        throw new Exception("mysqli_init failed");
    }

    // 6. ตั้งค่า SSL สำหรับ Aiven Cloud
    if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
        $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    }
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);

    // 7. เชื่อมต่อฐานข้อมูล
    $flags = MYSQLI_CLIENT_SSL;
    if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
        $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }

    $connected = @$conn->real_connect($host, $user, $pass, $dbname, $port, NULL, $flags);
    if (!$connected) {
        throw new Exception("ไม่สามารถเชื่อมต่อฐานข้อมูลได้: " . mysqli_connect_error());
    }

    // 8. ตั้งค่า Charset และ Timezone ของ MySQL
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+07:00'");

} catch (Throwable $e) { // เปลี่ยนเป็น Throwable เพื่อดักจับทั้ง Exception และ TypeError บน PHP 8+
    error_log("Database Connection Error: " . $e->getMessage());
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้: " . $e->getMessage());
}
?>
