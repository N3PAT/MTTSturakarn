<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$appVersion = [
    'appName' => 'ระบบสารสนเทศนักเรียน MTTS',
    'version' => '1.2.0',
    'build' => 120,
    'release_date' => '2026-09-25',
    'min_os_version' => [
        'android' => '8.0',
        'ios' => '14.0'
    ],
    'changelog' => [
        'เพิ่มระบบแจ้งเตือนผ่าน Web Push',
        'ปรับปรุงประสิทธิภาพการโหลดหน้าหลัก',
        'แก้ไขข้อผิดพลาดระบบสถิติการเข้าเวร'
    ],
    'apkUrl' => '../downloads/mtts-app-v1.2.0.apk',
    'apk_download_url' => '../downloads/mtts-app-v1.2.0.apk',
    'pwa_manifest_url' => 'manifest.json'
];

echo json_encode($appVersion, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
