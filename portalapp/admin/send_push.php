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

try {
    $webPush = new WebPush($auth);

    // ------------------------------------------------------------------
    // 6. ดึงและทำความสะอาด Input Data
    // ------------------------------------------------------------------
    $inputData  = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $studentId  = isset($inputData['student_id']) ? trim($inputData['student_id']) : null;
    $rawDate    = isset($inputData['target_date']) ? $inputData['target_date'] : date('d/m/Y');
    $targetDate = htmlspecialchars(strip_tags($rawDate), ENT_QUOTES, 'UTF-8');

    // รองรับการระบุหัวข้อ และข้อความ (หากไม่ระบุจะใช้ค่าเริ่มต้น)
    $title = isset($inputData['title']) ? $inputData['title'] : 'ประกาศ: ยังไม่ได้ทำรายการ';
    $body  = isset($inputData['body']) ? $inputData['body'] : "ระบบตรวจสอบพบว่าคุณยังไม่ได้ทำรายการประจำวันที่ {$targetDate}\n\nกรุณาเข้าสู่ระบบเพื่อดำเนินการให้เรียบร้อย";
    $url   = isset($inputData['url']) ? $inputData['url'] : '/main.php';

    // ------------------------------------------------------------------
    // 7. ดึงข้อมูลผู้รับแจ้งเตือนจากตาราง push_subscriptions
    // ------------------------------------------------------------------
    if (!empty($studentId)) {
        // กรณีระบุ student_id (ส่งเตือนเฉพาะบุคคล เช่น โดนบัตร/เข้าเวร)
        $stmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE student_id = ?");
        $stmt->bind_param("s", $studentId);
    } else {
        // กรณีกระจายข่าวสารทั่วไป (ส่งหาทุกคน)
        $stmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'ไม่พบข้อมูลเครื่องที่ลงทะเบียนรับแจ้งเตือนในระบบ'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------------------------------------------------
    // 8. เตรียม Payload
    // ------------------------------------------------------------------
    $payload = json_encode([
        'title' => $title,
        'body'  => $body,
        'icon'  => 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg',
        'image' => isset($inputData['image']) ? $inputData['image'] : 'https://taothetutor.wordpress.com/wp-content/uploads/2026/09/wp-17898328944611784106779603276252.jpg',
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

    // ------------------------------------------------------------------
    // 9. เข้าคิวแจ้งเตือน (Queue Batching)
    // ------------------------------------------------------------------
    $subscriptionsMap = [];

    while ($row = $result->fetch_assoc()) {
        $subscription = Subscription::create([
            'endpoint'  => $row['endpoint'],
            'publicKey' => $row['p256dh'],
            'authToken' => $row['auth'],
        ]);

        // จัดเข้าคิว
        $webPush->queueNotification($subscription, $payload);
        $subscriptionsMap[$row['endpoint']] = $row['id'];
    }

    // ------------------------------------------------------------------
    // 10. ประมวลผลยิงข้อมูลออกพร้อมกันแบบ Asynchronous
    // ------------------------------------------------------------------
    $sentCount = 0;
    $failedIds = [];

    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
        
        if ($report->isSuccess()) {
            $sentCount++;
        } else {
            // เช็คเฉพาะกรณี Token หมดอายุหรือถูกลบออกจากบราวเซอร์
            if ($report->isSubscriptionExpired() && isset($subscriptionsMap[$endpoint])) {
                $failedIds[] = $subscriptionsMap[$endpoint];
            }
        }
    }

    // ------------------------------------------------------------------
    // 11. เคลียร์ข้อมูล Token ที่หมดอายุออกจาก DB
    // ------------------------------------------------------------------
    if (!empty($failedIds)) {
        $idsList = implode(',', array_map('intval', $failedIds));
        $conn->query("DELETE FROM push_subscriptions WHERE id IN ($idsList)");
    }

    echo json_encode([
        'success' => true, 
        'sent_count' => $sentCount
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
