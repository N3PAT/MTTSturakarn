<?php
header('Content-Type: application/json; charset=utf-8');

set_time_limit(120);
ini_set('memory_limit', '256M');

// อนุญาตเฉพาะ POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed: เปิดรับเฉพาะ POST Request'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// โหลด Composer Autoload
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
}
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ไม่พบ vendor/autoload.php'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $autoloadPath;

// เชื่อมต่อฐานข้อมูล
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

$inputData = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $inputData['action'] ?? 'send';

try {
    // ==================================================================
    // โหมด A: บันทึกการลงทะเบียนรับแจ้งเตือนจากหน้าเว็บ PWA (ไม่ต้องใช้ X-API-KEY)
    // ==================================================================
    if ($action === 'subscribe') {
        $studentId = trim((string)($inputData['student_id'] ?? ''));
        $endpoint  = trim((string)($inputData['endpoint'] ?? ''));
        $p256dh    = trim((string)($inputData['p256dh'] ?? ''));
        $authKey   = trim((string)($inputData['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $authKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ข้อมูล Subscription ไม่ครบถ้วน'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // เช็คว่ามี endpoint นี้ในระบบหรือยัง ถ้ามีให้อัปเดตแทนการเพิ่มซ้ำ
        $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ? LIMIT 1");
        $checkStmt->bind_param("s", $endpoint);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $updStmt = $conn->prepare("UPDATE push_subscriptions SET student_id = ?, p256dh = ?, auth = ? WHERE id = ?");
            $idInt = (int)$existing['id'];
            $updStmt->bind_param("sssi", $studentId, $p256dh, $authKey, $idInt);
            $updStmt->execute();
            $updStmt->close();
        } else {
            $insStmt = $conn->prepare("INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
            $insStmt->bind_param("ssss", $studentId, $endpoint, $p256dh, $authKey);
            $insStmt->execute();
            $insStmt->close();
        }

        echo json_encode(['success' => true, 'message' => 'ลงทะเบียนรับแจ้งเตือน PWA สำเร็จ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ==================================================================
    // โหมด B: ยิง Push Notification (ตรวจสอบ X-API-KEY ก่อนส่ง)
    // ==================================================================
    define('SECRET_API_KEY', getenv('API_SECRET_KEY') ?: 'N4tthap41!nwza');
    $fallbackKey = 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs';

    $providedKey = '';
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $providedKey = $_SERVER['HTTP_X_API_KEY'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtolower($key) === 'x-api-key') {
                $providedKey = $value;
                break;
            }
        }
    }

    if (empty($providedKey) || ($providedKey !== SECRET_API_KEY && $providedKey !== $fallbackKey)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: สิทธิ์ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $vapidSubject = getenv('VAPID_SUBJECT') ?: 'mailto:natthapatinsangchan@gmail.com';
    $publicKey    = getenv('VAPID_PUBLIC_KEY') ?: 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA';
    $privateKey   = getenv('VAPID_PRIVATE_KEY') ?: 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs';

    $webPush = new WebPush([
        'VAPID' => [
            'subject'    => $vapidSubject,
            'publicKey'  => $publicKey,
            'privateKey' => $privateKey,
        ],
    ]);

    $studentId  = isset($inputData['student_id']) ? trim((string)$inputData['student_id']) : null;
    $rawDate    = isset($inputData['target_date']) ? (string)$inputData['target_date'] : date('d/m/Y');
    $targetDate = htmlspecialchars(strip_tags($rawDate), ENT_QUOTES, 'UTF-8');

    $title = isset($inputData['title']) ? (string)$inputData['title'] : 'ประกาศ: ยังไม่ได้ทำรายการ';
    $body  = isset($inputData['body']) ? (string)$inputData['body'] : "ระบบตรวจสอบพบว่าคุณยังไม่ได้ทำรายการประจำวันที่ {$targetDate}\n\nกรุณาเข้าสู่ระบบเพื่อดำเนินการให้เรียบร้อย";
    $url   = isset($inputData['url']) ? (string)$inputData['url'] : '/portalapp/main.php';

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

        $subscriptionsMap = [];
        $queuedCount = 0;

        while ($row = $result->fetch_assoc()) {
            try {
                $subscription = Subscription::create([
                    'endpoint'  => $row['endpoint'],
                    'publicKey' => $row['p256dh'],
                    'authToken' => $row['auth'],
                ]);

                // TTL 86400 วินาที (24 ชม.) เผื่อเครื่องปิดเน็ตอยู่ พอเปิดเน็ตแจ้งเตือนจะเด้งทันที
                $webPush->queueNotification($subscription, $payload, ['TTL' => 86400]);
                $subscriptionsMap[$row['endpoint']] = (int)$row['id'];
                $queuedCount++;
            } catch (Throwable $subErr) {
                $skippedCount++;
            }
        }

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

    echo json_encode([
        'success' => $sentCount > 0,
        'sent_count' => $sentCount,
        'skipped_count' => $skippedCount,
        'expired_removed' => count($failedIds),
        'message' => $sentCount > 0 ? 'ส่งแจ้งเตือนสำเร็จ' : 'ไม่พบข้อมูลเครื่องที่ลงทะเบียนรับแจ้งเตือน'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
