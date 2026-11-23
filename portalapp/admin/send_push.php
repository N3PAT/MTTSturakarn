<?php
header('Content-Type: application/json; charset=utf-8');

// ------------------------------------------------------------------
// ปรับแต่ง Performance เพื่อรองรับการส่งพร้อมกัน 300+ รายการ
// ------------------------------------------------------------------
set_time_limit(120);          // ขยายเวลาประมวลผลสูงสุดเป็น 2 นาที ป้องกัน PHP Timeout
ini_set('memory_limit', '256M'); // ขยาย RAM เผื่อการดึงข้อมูลและดึงคิวจำนวนมาก

// ------------------------------------------------------------------
// 1. ความปลอดภัยขั้นที่ 1: ป้องกันการกดเข้าผ่าน Web URL โดยตรง (อนุญาตเฉพาะ POST)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed: ไม่อนุญาตให้เข้าถึงผ่าน URL โดยตรง (เปิดรับเฉพาะ POST Request)'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// 2. ความปลอดภัยขั้นที่ 2: ตรวจสอบ Security Secret Key (X-API-KEY)
// ------------------------------------------------------------------
define('SECRET_API_KEY', getenv('API_SECRET_KEY') ?: 'N4tthap41!nwza');

$providedKey = '';
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $providedKey = $_SERVER['HTTP_X_API_KEY'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'x-api-key') {
            $providedKey = $value;
            break;
        }
    }
}

$fallbackKey = 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs';
if (empty($providedKey) || ($providedKey !== SECRET_API_KEY && $providedKey !== $fallbackKey)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: สิทธิ์ไม่ถูกต้อง ไม่อนุญาตให้ส่งข้อความ'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// 3. โหลด Composer Autoload
// ------------------------------------------------------------------
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
}

if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่พบโฟลเดอร์ vendor/autoload.php กรุณารัน composer install บน Render'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $autoloadPath;

// ------------------------------------------------------------------
// 4. เชื่อมต่อฐานข้อมูล
// ------------------------------------------------------------------
if (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ไม่พบไฟล์ db.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// ------------------------------------------------------------------
// 5. ตั้งค่า VAPID Keys
// ------------------------------------------------------------------
$vapidSubject = getenv('VAPID_SUBJECT') ?: 'mailto:natthapatinsangchan@gmail.com';
$publicKey    = getenv('VAPID_PUBLIC_KEY') ?: 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA';
$privateKey   = getenv('VAPID_PRIVATE_KEY') ?: 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs';

if (empty($publicKey) || empty($privateKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'กรุณาตั้งค่า VAPID Keys'], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = [
    'VAPID' => [
        'subject'    => $vapidSubject,
        'publicKey'  => $publicKey,
        'privateKey' => $privateKey,
    ],
];

/**
 * บันทึกแจ้งเตือนลงตาราง app_notifications เพื่อให้แอป Android (NotificationSyncWorker) ดึงไปแสดง
 */
function queueAndroidDeviceNotifications(mysqli $conn, ?string $studentId, string $title, string $body, string $url): int
{
    $inserted = 0;

    // ตรวจสอบว่ามีตาราง android_devices หรือไม่
    $checkTable = $conn->query("SHOW TABLES LIKE 'android_devices'");
    if (!$checkTable || $checkTable->num_rows === 0) {
        return 0;
    }

    $checkNotiTable = $conn->query("SHOW TABLES LIKE 'app_notifications'");
    if (!$checkNotiTable || $checkNotiTable->num_rows === 0) {
        return 0;
    }

    $targetStudentIds = [];

    if (!empty($studentId)) {
        $devStmt = $conn->prepare("SELECT DISTINCT student_id FROM android_devices WHERE student_id = ? AND student_id IS NOT NULL AND student_id != ''");
        if ($devStmt) {
            $devStmt->bind_param("s", $studentId);
            $devStmt->execute();
            $devRes = $devStmt->get_result();
            while ($r = $devRes->fetch_assoc()) {
                $targetStudentIds[] = (string)$r['student_id'];
            }
            $devStmt->close();
        }
    } else {
        $devRes = $conn->query("SELECT DISTINCT student_id FROM android_devices WHERE student_id IS NOT NULL AND student_id != ''");
        if ($devRes) {
            while ($r = $devRes->fetch_assoc()) {
                $targetStudentIds[] = (string)$r['student_id'];
            }
        }
    }

    if (empty($targetStudentIds)) {
        return 0;
    }

    $insStmt = $conn->prepare("INSERT INTO app_notifications (notice_key, student_id, title, message, url) VALUES (?, ?, ?, ?, ?)");
    if (!$insStmt) {
        return 0;
    }

    foreach ($targetStudentIds as $sid) {
        $noticeKey = uniqid('ntf_', true);
        $insStmt->bind_param("sssss", $noticeKey, $sid, $title, $body, $url);
        if ($insStmt->execute()) {
            $inserted++;
        }
    }
    $insStmt->close();

    return $inserted;
}

try {
    // ------------------------------------------------------------------
    // 6. ดึงและทำความสะอาด Input Data
    // ------------------------------------------------------------------
    $inputData  = json_decode(file_get_contents('php://input'), true) ?? [];

    $studentId  = isset($inputData['student_id']) ? trim((string)$inputData['student_id']) : null;
    $rawDate    = isset($inputData['target_date']) ? (string)$inputData['target_date'] : date('d/m/Y');
    $targetDate = htmlspecialchars(strip_tags($rawDate), ENT_QUOTES, 'UTF-8');

    $title = isset($inputData['title']) ? (string)$inputData['title'] : 'ประกาศ: ยังไม่ได้ทำรายการ';
    $body  = isset($inputData['body']) ? (string)$inputData['body'] : "ระบบตรวจสอบพบว่าคุณยังไม่ได้ทำรายการประจำวันที่ {$targetDate}\n\nกรุณาเข้าสู่ระบบเพื่อดำเนินการให้เรียบร้อย";
    $url   = isset($inputData['url']) ? (string)$inputData['url'] : '/main.php';

    // บันทึกแจ้งเตือนสำหรับเครื่อง Android ในตาราง app_notifications ก่อน
    $androidQueuedCount = queueAndroidDeviceNotifications($conn, $studentId, $title, $body, $url);

    // ------------------------------------------------------------------
    // 7. ดึงข้อมูลผู้รับแจ้งเตือนจากตาราง push_subscriptions (Web Push)
    // ------------------------------------------------------------------
    if (!empty($studentId)) {
        $stmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE student_id = ?");
        $stmt->bind_param("s", $studentId);
    } else {
        $stmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions");
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $sentCount = 0;
    $skippedCount = 0;
    $failedIds = [];

    if ($result->num_rows > 0) {
        $webPush = new WebPush($auth);

        // --------------------------------------------------------------
        // 8. เตรียม Payload
        // --------------------------------------------------------------
        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg',
            'image' => isset($inputData['image']) ? (string)$inputData['image'] : 'https://taothetutor.wordpress.com/wp-content/uploads/2026/09/wp-17898328944611784106779603276252.jpg',
            'url'   => $url,
            'requireInteraction' => true,
            'vibrate' => [200, 100, 200],
            'actions' => [
                [
                    'action' => 'open_app',
                    'title'  => 'เข้าสู่ระบบเพื่อทำรายการ'
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);

        // --------------------------------------------------------------
        // 9. เข้าคิวแจ้งเตือน (Queue Batching)
        // --------------------------------------------------------------
        $subscriptionsMap = [];
        $queuedCount = 0;

        while ($row = $result->fetch_assoc()) {
            try {
                $subscription = Subscription::create([
                    'endpoint'  => $row['endpoint'],
                    'publicKey' => $row['p256dh'],
                    'authToken' => $row['auth'],
                ]);

                $webPush->queueNotification($subscription, $payload);
                $subscriptionsMap[$row['endpoint']] = (int)$row['id'];
                $queuedCount++;
            } catch (Throwable $subErr) {
                $skippedCount++;
                error_log("Skipped invalid subscription id={$row['id']}: " . $subErr->getMessage());
            }
        }

        // --------------------------------------------------------------
        // 10. ประมวลผลยิงข้อมูลออกพร้อมกันแบบ Asynchronous
        // --------------------------------------------------------------
        if ($queuedCount > 0) {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $sentCount++;
                } elseif ($report->isSubscriptionExpired() && isset($subscriptionsMap[$endpoint])) {
                    $failedIds[] = $subscriptionsMap[$endpoint];
                }
            }
        }

        // --------------------------------------------------------------
        // 11. เคลียร์ข้อมูล Token ที่หมดอายุออกจาก DB
        // --------------------------------------------------------------
        if (!empty($failedIds)) {
            $delStmt = $conn->prepare("DELETE FROM push_subscriptions WHERE id = ?");
            if ($delStmt) {
                foreach ($failedIds as $expiredId) {
                    $idInt = (int)$expiredId;
                    $delStmt->bind_param("i", $idInt);
                    $delStmt->execute();
                }
                $delStmt->close();
            }
        }
    }
    $stmt->close();

    if ($sentCount === 0 && $androidQueuedCount === 0) {
        echo json_encode([
            'success' => false,
            'sent_count' => 0,
            'android_queued' => 0,
            'skipped_count' => $skippedCount,
            'message' => 'ไม่พบข้อมูลเครื่องที่ลงทะเบียนรับแจ้งเตือนในระบบ'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'sent_count' => $sentCount,
        'android_queued' => $androidQueuedCount,
        'skipped_count' => $skippedCount,
        'expired_removed' => count($failedIds)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
