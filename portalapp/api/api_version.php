<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$appVersion = [
    'appName' => 'ระบบสารสนเทศนักเรียน MTTS',
    'version' => '1.2.0',
    'build' => 120,
    'release_date' => '2026-09-29',
    'min_os_version' => [
        'android' => '8.0',
        'ios' => '14.0'
    ],
    'changelog' => [
        'เพิ่มระบบแจ้งเตือนผ่าน Web Push',
        'ปรับปรุงประสิทธิภาพการโหลดหน้าหลัก',
        'แก้ไขข้อผิดพลาดระบบสถิติการเข้าเวร'
    ],
    // เติม api/ ด้านหน้า เพื่อให้อ้างอิงจากหน้าเว็บหลัก (index.html) ได้ถูกต้อง
    'apkUrl' => 'api/downloads/MTTS-1001.apk',
    'apk_download_url' => 'api/downloads/MTTS-1001.apk',
    // manifest.json มักจะอยู่ที่หน้าแรก (Root) อยู่แล้ว จึงใช้ path เดิมได้
    'pwa_manifest_url' => 'manifest.json' 
];

echo json_encode($appVersion, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
