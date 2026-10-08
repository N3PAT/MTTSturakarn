<?php
/**
 * notification_lib.php — ข้อมูลและตรรกะของหน้าแจ้งเตือน (ไม่มีการแสดงผลใด ๆ ในไฟล์นี้)
 *
 * โครงสร้าง:  ตัวช่วย -> ดึงข้อมูล (1 ฟังก์ชัน/1 แหล่งข้อมูล) -> รวมเป็นรายการเดียว -> API บันทึก Push
 */

const LEVEL_RANK = ['danger' => 0, 'urgent' => 1, 'warning' => 2, 'info' => 3];

/* ===================== ตัวช่วยทั่วไป ===================== */

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_date(?string $datetime, bool $withTime = false): string
{
    $ts = $datetime ? strtotime($datetime) : false;
    if ($ts === false) { $ts = time(); }
    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

// อนุญาตเฉพาะ URL รูปที่ปลอดภัย (http/https หรือ path ธรรมดา) กัน javascript: และ path แปลก ๆ
function safe_url(?string $u): string
{
    $u = trim((string)$u);
    if ($u === '') { return ''; }
    if (preg_match('#^https?://#i', $u)) { return $u; }
    if (preg_match('#^[\w\-./%]+$#u', $u) && strpos($u, '..') === false) { return $u; }
    return '';
}

function json_response(array $data, int $http = 200): void
{
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// เก็บผลลัพธ์ชั่วคราวใน APCu (ถ้าไม่มี APCu ก็เรียกฟังก์ชันตรง ๆ)
function cache_remember(string $key, int $ttl, callable $fn)
{
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch($key, $hit);
        if ($hit) { return $v; }
    }
    $v = $fn();
    if (function_exists('apcu_store')) { apcu_store($key, $v, $ttl); }
    return $v;
}

// ถ้าแหล่งข้อมูลใดล้มเหลว ให้บันทึก log แล้วใช้ค่าเริ่มต้น หน้าเว็บส่วนอื่นยังแสดงได้
function safe_fetch(callable $fn, $default = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('notification: ' . $e->getMessage());
        return $default;
    }
}

// ป้องกัน CSRF ของ API โดยไม่ต้องแก้ฝั่งที่เรียกใช้: ต้องมาจากเว็บเดียวกันเท่านั้น
function same_origin_request(): bool
{
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '') {
        return $site === 'same-origin' || $site === 'none';
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') { return true; }   // เบราว์เซอร์เก่าบางตัวไม่ส่ง header นี้
    $host = strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':');
    return parse_url($origin, PHP_URL_HOST) === $host;
}

/* ===================== ชั้นฐานข้อมูล ===================== */

function fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function execute_write(mysqli $conn, string $sql, string $types, array $params): void
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

/* ===================== แหล่งข้อมูลแต่ละประเภท ===================== */

function get_restriction(mysqli $conn, string $code): ?array
{
    $rows = fetch_all($conn, 'SELECT weeks_count, created_at FROM restrictions WHERE student_code = ? ORDER BY created_at DESC LIMIT 1', 's', [$code]);
    return $rows[0] ?? null;
}

function get_duties(mysqli $conn, string $code): array
{
    return fetch_all(
        $conn,
        'SELECT duty_name, duty_date, DATEDIFF(duty_date, CURDATE()) AS days_left
           FROM student_duties WHERE student_code = ? AND duty_date >= CURDATE() ORDER BY duty_date ASC',
        's', [$code]
    );
}

function has_voted_this_week(mysqli $conn, string $code): bool
{
    $rows = fetch_all($conn, 'SELECT COUNT(*) AS c FROM weekly_votes WHERE student_code = ? AND YEARWEEK(vote_date, 1) = YEARWEEK(CURDATE(), 1)', 's', [$code]);
    return (int)($rows[0]['c'] ?? 0) > 0;
}

function get_discipline_today(mysqli $conn, string $code): ?array
{
    $rows = fetch_all($conn, 'SELECT card_bed, card_shoe, card_cabinet, total_cards, status_color FROM discipline_cards WHERE student_code = ? AND check_date = CURDATE() LIMIT 1', 's', [$code]);
    $row = $rows[0] ?? null;
    return ($row && (int)$row['total_cards'] > 0) ? $row : null;
}

// ประกาศเหมือนกันทุกคน จึงแคช 30 วินาที (ลดคิวรีเมื่อทุกคนเปิดหน้าพร้อมกัน)
function get_announcements(mysqli $conn): array
{
    return cache_remember('mtts_announcements', 30, function () use ($conn) {
        try {
            return fetch_all($conn, "SELECT id, title, content, attachment, created_at FROM announcements WHERE status = 'active' ORDER BY is_pinned DESC, id DESC LIMIT 10");
        } catch (Throwable $e) {   // ตารางอาจไม่มีคอลัมน์ status
            return fetch_all($conn, 'SELECT id, title, content, attachment, created_at FROM announcements ORDER BY is_pinned DESC, id DESC LIMIT 10');
        }
    });
}

function get_system_notifications(mysqli $conn, string $code): array
{
    return fetch_all(
        $conn,
        "SELECT id, title, message, alert_level, created_at FROM notifications
          WHERE student_code = ? OR student_code IS NULL OR student_code = ''
          ORDER BY created_at DESC LIMIT 15",
        's', [$code]
    );
}

/* ===================== รวมทุกแหล่งเป็นรายการเดียว ===================== */

/**
 * รูปแบบของแต่ละรายการ:
 *   cat      กลุ่ม (alert | duty | news | system)   level  ความสำคัญ (danger > urgent > warning > info)
 *   icon     ชื่อไอคอนใน sprite    title/summary/time   ข้อความที่แสดง    image  รูปแนบ (ถ้ามี)
 *   action   ปุ่มลัด ['href' =>, 'label' =>] หรือ null
 */
function build_notification_items(array $d): array
{
    $items = [];
    $add = function (array $it) use (&$items) {
        $it += ['image' => '', 'action' => null];
        $it['rank'] = LEVEL_RANK[$it['level']];
        $it['seq']  = count($items);       // รักษาลำดับเดิมภายในระดับเดียวกัน
        $items[] = $it;
    };

    if (!empty($d['restriction'])) {
        $weeks = (int)$d['restriction']['weeks_count'];
        $add([
            'cat' => 'alert', 'level' => 'danger', 'icon' => 'slash',
            'title' => 'คุณถูกกักบริเวณ',
            'summary' => "งดกลับบ้าน: คุณถูกโทษกักบริเวณเป็นจำนวน {$weeks} สัปดาห์",
            'time' => 'วันที่สั่งกัก: ' . fmt_date($d['restriction']['created_at']),
        ]);
    }

    if (!empty($d['discipline'])) {
        $x = $d['discipline'];
        $tot = (int)$x['total_cards'];
        $names = [];
        if (!empty($x['card_bed']))     { $names[] = 'เตียง'; }
        if (!empty($x['card_cabinet'])) { $names[] = 'ตู้'; }
        if (!empty($x['card_shoe']))    { $names[] = 'รองเท้า'; }
        $add([
            'cat' => 'alert',
            'level' => ($tot >= 3 || ($x['status_color'] ?? '') === 'red') ? 'urgent' : 'warning',
            'icon' => 'card',
            'title' => "โดนตัดบัตรวินัยวันนี้! (จำนวน {$tot} ใบ)",
            'summary' => 'รายการที่ไม่เรียบร้อย: ' . ($names ? implode(', ', $names) : 'ไม่มีรายละเอียด'),
            'time' => 'ตรวจพบวันนี้',
        ]);
    }

    if (isset($d['voted']) && !$d['voted']) {
        $add([
            'cat' => 'alert', 'level' => 'urgent', 'icon' => 'vote',
            'title' => 'ยังไม่ได้โหวตประสงค์กลับบ้าน',
            'summary' => 'กรุณาลงทำรายการโหวตความประสงค์ประจำสัปดาห์นี้ให้เรียบร้อย',
            'time' => 'สัปดาห์นี้',
            'action' => ['href' => 'fill.php', 'label' => 'ทำรายการโหวต'],
        ]);
    }

    foreach (($d['system'] ?? []) as $n) {
        $lv = $n['alert_level'] ?? 'info';
        [$level, $icon] = $lv === 'urgent' ? ['urgent', 'alert'] : ($lv === 'warning' ? ['warning', 'alert-tri'] : ['info', 'bell']);
        $add([
            'cat' => 'system', 'level' => $level, 'icon' => $icon,
            'title' => (string)($n['title'] ?? ''),
            'summary' => (string)($n['message'] ?? ''),
            'time' => fmt_date($n['created_at'] ?? null, true) . ' น.',
        ]);
    }

    foreach (($d['duties'] ?? []) as $row) {
        $days = (int)($row['days_left'] ?? 0);
        [$level, $icon, $when] = $days === 0 ? ['urgent', 'alert-tri', 'วันนี้']
            : ($days === 1 ? ['warning', 'clock', 'พรุ่งนี้'] : ['info', 'cal', "อีก {$days} วัน"]);
        $date = !empty($row['duty_date']) ? fmt_date($row['duty_date']) : '';
        $name = trim((string)($row['duty_name'] ?? '')) ?: 'ปฏิบัติหน้าที่เวร';
        $add([
            'cat' => 'duty', 'level' => $level, 'icon' => $icon,
            'title' => "แจ้งเตือนเข้าเวร: {$name}",
            'summary' => "คุณมีกำหนดปฏิบัติหน้าที่ในวันที่ {$date} ({$when})",
            'time' => $date,
        ]);
    }

    foreach (($d['news'] ?? []) as $news) {
        $add([
            'cat' => 'news', 'level' => 'info', 'icon' => 'news',
            'title' => trim((string)($news['title'] ?? '')) ?: 'ประกาศข่าวสาร',
            'summary' => (string)($news['content'] ?? ''),
            'time' => fmt_date($news['created_at'] ?? null, true) . ' น.',
            'image' => safe_url($news['attachment'] ?? ''),
        ]);
    }

    // เรียงตามความสำคัญ (danger ก่อน) ภายในระดับเดียวกันคงลำดับเดิม
    usort($items, fn($a, $b) => [$a['rank'], $a['seq']] <=> [$b['rank'], $b['seq']]);
    return $items;
}

/* ===================== API: บันทึก Push Subscription ===================== */

function save_push_subscription(mysqli $conn, string $code, array $post): array
{
    $endpoint = trim((string)($post['endpoint'] ?? ''));
    $p256dh   = trim((string)($post['p256dh'] ?? ''));
    $auth     = trim((string)($post['auth'] ?? ''));

    if ($endpoint === '') {
        return ['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (Missing endpoint)'];
    }
    if (!preg_match('#^https://#i', $endpoint) || strlen($endpoint) > 2048 || strlen($p256dh) > 255 || strlen($auth) > 255) {
        return ['success' => false, 'error' => 'รูปแบบข้อมูลไม่ถูกต้อง'];
    }

    try {
        $exists = fetch_all($conn, 'SELECT id FROM push_subscriptions WHERE student_id = ? LIMIT 1', 's', [$code]);
        if ($exists) {
            execute_write($conn, 'UPDATE push_subscriptions SET endpoint = ?, p256dh = ?, auth = ? WHERE student_id = ?', 'ssss', [$endpoint, $p256dh, $auth, $code]);
            return ['success' => true, 'message' => 'อัปเดตการแจ้งเตือนสำเร็จ'];
        }
        // ถ้ามี UNIQUE (student_id) การกดพร้อมกันจะไม่เกิดแถวซ้ำ (ดู notification_indexes.sql)
        execute_write(
            $conn,
            'INSERT INTO push_subscriptions (student_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth)',
            'ssss', [$code, $endpoint, $p256dh, $auth]
        );
        return ['success' => true, 'message' => 'บันทึกการแจ้งเตือนสำเร็จ'];
    } catch (Throwable $e) {
        error_log('push subscription: ' . $e->getMessage());
        return ['success' => false, 'error' => 'บันทึกการแจ้งเตือนไม่สำเร็จ กรุณาลองใหม่'];
    }
}
