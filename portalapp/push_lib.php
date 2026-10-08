<?php
/**
 * push_lib.php — ส่ง Web Push ถึงอุปกรณ์ที่ subscribe ไว้ (ถึงแม้ปิดแอป/ปิดหน้าเว็บอยู่)
 *
 * ต้องติดตั้ง:  composer require minishlink/web-push   และตั้งค่าใน push_config.php
 * ฟังก์ชันหลัก: push_new_announcements($conn)  — ส่งประกาศใหม่ที่ยังไม่เคยส่ง (เรียกซ้ำกี่รอบก็ไม่ส่งซ้ำ)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/push_config.php';
require_once __DIR__ . '/notification_lib.php';
require_once __DIR__ . '/rich_content.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/* คีย์จากเบราว์เซอร์อาจเป็น base64 ธรรมดาหรือ base64url → แปลงเป็น base64url */
function push_b64u(string $s): string
{
    return strtr(rtrim(trim($s), '='), '+/', '-_');
}

function push_key_ok(string $b64u, int $bytes): bool
{
    $raw = base64_decode(strtr($b64u, '-_', '+/') . str_repeat('=', (4 - strlen($b64u) % 4) % 4), true);
    return $raw !== false && strlen($raw) === $bytes;
}

/* ส่งข้อความเดียวถึงทุกเครื่องที่ subscribe — คืนสถิติ และลบ subscription ที่หมดอายุ (404/410) ให้อัตโนมัติ */
function push_send_all(mysqli $conn, array $payload): array
{
    $res = ['sent' => 0, 'failed' => 0, 'removed' => 0];
    if (strlen(PUSH_VAPID_PUBLIC) < 80 || strlen(PUSH_VAPID_PRIVATE) < 40) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่าคีย์ VAPID ใน push_config.php');
    }

    $subs = fetch_all($conn, "SELECT endpoint, p256dh, auth FROM push_subscriptions
                               WHERE endpoint <> '' AND p256dh <> '' AND auth <> ''");
    if (!$subs) { return $res; }

    $webPush = new WebPush(['VAPID' => [
                                        'subject'    => 'mailto:natthapatinsangchan@gmail.com',
                                'publicKey'  => 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA',
                                'privateKey' => 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs',
    ]]);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    foreach ($subs as $s) {
        $p256dh = push_b64u($s['p256dh']);
        $auth   = push_b64u($s['auth']);
        if (!push_key_ok($p256dh, 65) || !push_key_ok($auth, 16)) { $res['failed']++; continue; }
        $webPush->queueNotification(
            Subscription::create([
                'endpoint'        => $s['endpoint'],
                'publicKey'       => $p256dh,
                'authToken'       => $auth,
                'contentEncoding' => 'aes128gcm',
            ]),
            $json,
            ['TTL' => 86400, 'urgency' => 'high']
        );
    }

    try {
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) { $res['sent']++; continue; }
            $res['failed']++;
            if ($report->isSubscriptionExpired()) {
                execute_write($conn, 'DELETE FROM push_subscriptions WHERE endpoint = ?', 's', [$report->getEndpoint()]);
                $res['removed']++;
            }
        }
    } catch (Throwable $e) {
        error_log('push flush: ' . $e->getMessage());
    }
    return $res;
}

/* ประกาศที่ยังไม่เคยส่ง (เฉพาะที่สร้างภายใน 1 วัน กันส่งประกาศเก่า ๆ รัว) */
function push_pending_announcements(mysqli $conn): array
{
    $sql = 'SELECT id, title, content FROM announcements
             WHERE pushed_at IS NULL AND created_at >= (NOW() - INTERVAL 1 DAY)%s
             ORDER BY id ASC LIMIT 5';
    try {
        return fetch_all($conn, sprintf($sql, " AND status = 'active'"));
    } catch (Throwable $e) {   // ตารางอาจไม่มีคอลัมน์ status
        return fetch_all($conn, sprintf($sql, ''));
    }
}

/* ส่ง push ของประกาศใหม่ — เรียกจาก cron และ/หรือจากหน้าแอดมินหลังบันทึกประกาศ */
function push_new_announcements(mysqli $conn): int
{
    $done = 0;
    foreach (push_pending_announcements($conn) as $r) {
        $id = (int)$r['id'];

        // จองก่อนส่ง: ถ้า cron กับหน้าแอดมินทำงานพร้อมกัน จะมีแค่ฝั่งเดียวที่ได้ส่ง
        $st = $conn->prepare('UPDATE announcements SET pushed_at = NOW() WHERE id = ? AND pushed_at IS NULL');
        $st->bind_param('i', $id);
        $st->execute();
        $claimed = ($st->affected_rows === 1);
        $st->close();
        if (!$claimed) { continue; }

        push_send_all($conn, [
            'title' => trim((string)$r['title']) ?: 'ประกาศข่าวสาร',
            'body'  => mb_substr(rich_plain((string)$r['content']), 0, 120),
            'url'   => 'notification.php',
            'tag'   => 'announcement-' . $id,
        ]);
        $done++;
    }
    if ($done && function_exists('apcu_delete')) { apcu_delete('mtts_announcements'); }   // ให้หน้าแจ้งเตือนเห็นประกาศใหม่ทันที
    return $done;
}
