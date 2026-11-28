<?php
/**
 * poll_lib.php — ฟังก์ชันกลางของระบบโหวตโพล ("โหวตหน่อย")
 * ใช้ร่วมกันโดย poll.php (นักเรียน) และ admin_polls.php (แอดมิน)
 * ต้อง require หลัง db.php (ต้องมี $conn เป็น mysqli)
 */

/** วันที่-เวลาแบบไทยสั้น เช่น "30 พ.ย. 2569 16:00 น." */
function poll_thai_datetime(?string $dt): string
{
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543) . ' ' . date('H:i', $ts) . ' น.';
}

/** โพลยังเปิดรับโหวตอยู่ไหม (สถานะ open และยังไม่เลยเวลาปิด) */
function poll_is_open(array $p): bool
{
    if (($p['status'] ?? '') !== 'open') return false;
    if (!empty($p['closes_at']) && strtotime((string)$p['closes_at']) <= time()) return false;
    return true;
}

/** ข้อความอธิบายจำนวนที่เลือกได้ */
function poll_choice_text(int $max): string
{
    if ($max === 1) return 'เลือกได้ข้อเดียว';
    if ($max === 0) return 'เลือกได้หลายข้อ';
    return 'เลือกได้สูงสุด ' . $max . ' ข้อ';
}

function poll_pct(int $count, int $base): int
{
    return $base > 0 ? (int)round($count * 100 / $base) : 0;
}

function poll_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/**
 * ดึงโพลพร้อมตัวเลือกและคะแนน
 *  - $student_code : ถ้าระบุ จะใส่ 'mine' = id ตัวเลือกที่คนนี้โหวตไว้
 *  - $only_id      : ดึงเฉพาะโพลเดียว
 * คืน array ของโพล (เปิดอยู่มาก่อน แล้วเรียงใหม่สุดก่อน)
 */
function poll_fetch(mysqli $conn, ?string $student_code = null, ?int $only_id = null, int $limit = 30): array
{
    $sql = 'SELECT id, title, description, max_choices, status, closes_at, created_at FROM polls';
    if ($only_id !== null) {
        $sql .= ' WHERE id = ' . (int)$only_id;
    }
    $sql .= " ORDER BY (status = 'open') DESC, created_at DESC, id DESC LIMIT " . (int)$limit;

    $polls = [];
    $res = $conn->query($sql);
    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['id'];
        $polls[$id] = [
            'id'            => $id,
            'title'         => (string)$r['title'],
            'description'   => (string)($r['description'] ?? ''),
            'max_choices'   => (int)$r['max_choices'],
            'status'        => (string)$r['status'],
            'closes_at'     => $r['closes_at'],
            'closes_label'  => poll_thai_datetime($r['closes_at']),
            'created_label' => poll_thai_datetime($r['created_at']),
            'is_open'       => poll_is_open($r),
            'options'       => [],
            'total'         => 0,   // จำนวนคนที่โหวต (นับคนเดียวครั้งเดียว)
            'mine'          => [],
        ];
    }
    if (!$polls) return [];

    $in = implode(',', array_keys($polls)); // เป็น int ทั้งหมด ปลอดภัย

    $res = $conn->query("SELECT id, poll_id, label FROM poll_options WHERE poll_id IN ($in) ORDER BY sort_order, id");
    while ($r = $res->fetch_assoc()) {
        $polls[(int)$r['poll_id']]['options'][(int)$r['id']] = ['id' => (int)$r['id'], 'label' => (string)$r['label'], 'count' => 0];
    }

    $res = $conn->query("SELECT poll_id, option_id, COUNT(*) AS c FROM poll_votes WHERE poll_id IN ($in) GROUP BY poll_id, option_id");
    while ($r = $res->fetch_assoc()) {
        $pid = (int)$r['poll_id'];
        $oid = (int)$r['option_id'];
        if (isset($polls[$pid]['options'][$oid])) {
            $polls[$pid]['options'][$oid]['count'] = (int)$r['c'];
        }
    }

    $res = $conn->query("SELECT poll_id, COUNT(DISTINCT student_code) AS c FROM poll_votes WHERE poll_id IN ($in) GROUP BY poll_id");
    while ($r = $res->fetch_assoc()) {
        $polls[(int)$r['poll_id']]['total'] = (int)$r['c'];
    }

    if ($student_code !== null && $student_code !== '') {
        $st = $conn->prepare("SELECT poll_id, option_id FROM poll_votes WHERE student_code = ? AND poll_id IN ($in)");
        $st->bind_param('s', $student_code);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $polls[(int)$r['poll_id']]['mine'][] = (int)$r['option_id'];
        }
        $st->close();
    }

    foreach ($polls as &$p) {
        $p['options'] = array_values($p['options']);
    }
    unset($p);

    return array_values($polls);
}
