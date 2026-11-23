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
    'pwa_manifest_url' => 'manifest.json',
    // ค่าเริ่มต้นสำหรับ NotificationSyncWorker
    'latest_notice_id' => 'build_120',
    'title' => 'ระบบสารสนเทศนักเรียน MTTS (v1.2.0)',
    'message' => implode("\n", [
        '• เพิ่มระบบแจ้งเตือนผ่าน Web Push',
        '• ปรับปรุงประสิทธิภาพการโหลดหน้าหลัก',
        '• แก้ไขข้อผิดพลาดระบบสถิติการเข้าเวร'
    ]),
    'url' => 'https://mttsturakarn.onrender.com/portalapp/main.php',
];

// พยายามดึงแจ้งเตือนล่าสุดจากฐานข้อมูล app_notifications ตาม device_id (ถ้ามี)
$dbPath = null;
if (file_exists(__DIR__ . '/../db.php')) {
    $dbPath = __DIR__ . '/../db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    $dbPath = __DIR__ . '/db.php';
}

if ($dbPath !== null) {
    require_once $dbPath;

    if (isset($conn) && $conn instanceof mysqli) {
        $deviceId = isset($_GET['device_id']) ? trim((string)$_GET['device_id']) : '';
        $studentId = null;

        if ($deviceId !== '') {
            $hasDevices = $conn->query("SHOW TABLES LIKE 'android_devices'");
            if ($hasDevices && $hasDevices->num_rows > 0) {
                $devStmt = $conn->prepare("SELECT student_id FROM android_devices WHERE device_id = ? LIMIT 1");
                if ($devStmt) {
                    $devStmt->bind_param("s", $deviceId);
                    $devStmt->execute();
                    $devRes = $devStmt->get_result();
                    if ($devRow = $devRes->fetch_assoc()) {
                        $studentId = $devRow['student_id'];
                    }
                    $devStmt->close();
                }
            }
        }

        $hasNoti = $conn->query("SHOW TABLES LIKE 'app_notifications'");
        if ($hasNoti && $hasNoti->num_rows > 0) {
            $noticeRow = null;

            if (!empty($studentId)) {
                $stmt = $conn->prepare("SELECT notice_key, title, message, url FROM app_notifications WHERE student_id = ? ORDER BY id DESC LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $studentId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $noticeRow = $res->fetch_assoc();
                    $stmt->close();
                }
            }

            if (!$noticeRow) {
                $res = $conn->query("SELECT notice_key, title, message, url FROM app_notifications ORDER BY id DESC LIMIT 1");
                if ($res) {
                    $noticeRow = $res->fetch_assoc();
                }
            }

            if ($noticeRow && !empty($noticeRow['notice_key'])) {
                $appVersion['latest_notice_id'] = (string)$noticeRow['notice_key'];
                $appVersion['title'] = (string)$noticeRow['title'];
                $appVersion['message'] = (string)$noticeRow['message'];
                if (!empty($noticeRow['url'])) {
                    $appVersion['url'] = (string)$noticeRow['url'];
                }
            }
        }
    }
}

echo json_encode($appVersion, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
