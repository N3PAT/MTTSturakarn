<?php
header('Content-Type: application/json; charset=utf-8');

// 1. โหลด Composer Autoload (ปรับ Path ให้ถอยออกไปหาโฟลเดอร์ vendor)
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    // ลองหาในกรณีโฟลเดอร์อยู่ชั้นเดียว
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
}

if (!file_exists($autoloadPath)) {
    echo json_encode([
        'success' => false, 
        'message' => 'ไม่พบโฟลเดอร์ vendor/autoload.php กรุณารัน composer install บน Render'
    ]);
    exit;
}

require_once $autoloadPath;

// 2. เชื่อมต่อฐานข้อมูล (ปรับ Path ให้ตรงกับไฟล์ db.php ของคุณ)
if (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    echo json_encode(['success' => false, 'message' => 'ไม่พบไฟล์ db.php']);
    exit;
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// 3. ตั้งค่า VAPID Keys
// แก้ไข: เพิ่ม mailto: ตามมาตรฐาน VAPID
$vapidSubject = 'mailto:natthapatinsangchan@gmail.com';
$publicKey    = 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA';
$privateKey   = 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs';

// แก้ไข: เปลี่ยนการตรวจสอบเป็นการเช็คค่าว่างแทน เพื่อไม่ให้บล็อก Key ของคุณเอง
if (empty($publicKey) || empty($privateKey)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาตั้งค่า VAPID Keys ในไฟล์ send_push.php']);
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

    // 4. ดึงข้อมูลผู้รับแจ้งเตือนจากตาราง push_subscriptions
    $stmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions");
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'ไม่พบข้อมูลเครื่องที่ลงทะเบียนรับแจ้งเตือนในระบบ (push_subscriptions เป็นค่าว่าง)'
        ]);
        exit;
    }

    // 5. เตรียมข้อความแจ้งเตือน
    $inputData = json_decode(file_get_contents('php://input'), true);
    $targetDate = isset($inputData['target_date']) ? $inputData['target_date'] : date('d/m/Y');

    $payload = json_encode([
        'title' => 'ระบบแจ้งเตือน นชท.',
        'body'  => "คุณยังไม่ได้ทำรายการ ประจำวันที่ {$targetDate} กรุณาเข้ามาดำเนินการให้เรียบร้อย",
        'icon'  => 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg',
        'url'   => '/portalapp/main.php'
    ], JSON_UNESCAPED_UNICODE);

    // 6. วนลูปส่งการแจ้งเตือน
    $sentCount = 0;
    $failedIds = [];

    while ($row = $result->fetch_assoc()) {
        $subscription = Subscription::create([
            'endpoint'  => $row['endpoint'],
            'publicKey' => $row['p256dh'],
            'authToken' => $row['auth'],
        ]);

        $report = $webPush->sendOneNotification($subscription, $payload);

        if ($report->isSuccess()) {
            $sentCount++;
        } else {
            // หากส่งไม่ผ่าน (เช่น ยกเลิกการติดตั้งแอปไปแล้ว) เก็บ ID ไว้ลบออก
            if ($report->isSubscriptionExpired()) {
                $failedIds[] = $row['id'];
            }
        }
    }

    // 7. เคลียร์ข้อมูล Token ที่หมดอายุออกจาก DB
    if (!empty($failedIds)) {
        $idsList = implode(',', array_map('intval', $failedIds));
        $conn->query("DELETE FROM push_subscriptions WHERE id IN ($idsList)");
    }

    echo json_encode([
        'success' => true, 
        'sent_count' => $sentCount
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>
