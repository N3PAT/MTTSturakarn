<?php
// 1. ตั้งค่า Timezone ของ PHP
date_default_timezone_set('Asia/Bangkok');

// 2. ปิดการแสดง Error บนหน้าเว็บ (เปิดไว้เฉพาะ Error Log)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// 3. ตั้งค่าให้ MySQLi โยน Exception เมื่อเกิดข้อผิดพลาด (ใช้เครื่องหมาย | แบบปกติ)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 4. ดึงข้อมูลเชื่อมต่อฐานข้อมูลจาก Environment Variables
$host   = getenv('Host') ?: '';
$port   = (int)getenv('Port');
$user   = getenv('User') ?: '';
$pass   = getenv('Password') ?: '';
$dbname = getenv('Databasename') ?: '';

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

} catch (Throwable $e) {
    // บันทึก Error ลง Log และแสดงข้อความให้ผู้ใช้
    error_log("Database Connection Error: " . $e->getMessage());
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้ กรุณาแจ้งผู้ดูแลระบบ");
}

// 9. กันกดค้าง/คลิกขวา/ลากที่รูป ให้ทุกหน้าที่ require db.php (ไม่ต้องแก้ทีละไฟล์)
//    ทำงานเฉพาะผลลัพธ์ที่เป็นหน้า HTML (มี </head> และ </body>) ส่วน JSON/API จะไม่ถูกแตะ
if (PHP_SAPI !== 'cli' && !defined('MTTS_IMG_PROTECT')) {
    define('MTTS_IMG_PROTECT', true);
    ob_start(function ($html) {
        if (strpos($html, 'mtts-img-protect') !== false) return $html;   // ใส่แล้ว
        $h = strripos($html, '</head>');
        $b = strripos($html, '</body>');
        if ($h === false || $b === false) return $html;                  // ไม่ใช่หน้า HTML เต็ม

        $css = '<style id="mtts-img-protect">img,svg image{-webkit-touch-callout:none;-webkit-user-drag:none;user-drag:none;-webkit-user-select:none;user-select:none}</style>';
        $js  = '<script>(function(){'
             . 'document.addEventListener("contextmenu",function(e){if(e.target&&e.target.closest&&e.target.closest("img,.news-media,.image-modal"))e.preventDefault();});'
             . 'document.addEventListener("dragstart",function(e){if(e.target&&e.target.tagName==="IMG")e.preventDefault();});'
             . '})();</script>';

        // แทรก script ก่อน (ตำแหน่งท้ายกว่า) แล้วค่อยแทรก css เพื่อไม่ให้ตำแหน่งเลื่อน
        $html = substr($html, 0, $b) . $js . substr($html, $b);
        $html = substr($html, 0, $h) . $css . substr($html, $h);
        return $html;
    });
}
