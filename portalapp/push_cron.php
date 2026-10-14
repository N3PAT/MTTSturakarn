<?php
/*
 * push_cron.php — ตัวตั้งเวลาส่ง push ประกาศใหม่ (ตั้งให้รันทุก 1 นาที)
 *   cron แบบ CLI:   * * * * * php /path/to/push_cron.php
 *   ถ้าโฮสต์ไม่มี CLI: ตั้ง cron/เว็บ cron เรียก  https://โดเมนของคุณ/push_cron.php?key=<PUSH_CRON_TOKEN>
 */
require_once __DIR__ . '/db.php';          // ต้องมี $conn (mysqli)
require_once __DIR__ . '/push_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (PHP_SAPI !== 'cli') {
    if (PUSH_CRON_TOKEN === '' || !hash_equals(PUSH_CRON_TOKEN, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(120);

try {
    $conn->set_charset('utf8mb4');
    echo 'pushed announcements: ' . push_new_announcements($conn) . PHP_EOL;
} catch (Throwable $e) {
    error_log('push cron: ' . $e->getMessage());
    http_response_code(500);
    echo 'error' . PHP_EOL;
}
