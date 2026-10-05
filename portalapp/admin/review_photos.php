<?php
session_start();

// 1. เปิดการแสดงผล Error ชั่วคราว เพื่อหาต้นตอของ 500 Error (เมื่อแก้ปัญหาเสร็จค่อยแก้กลับเป็น 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    // ==========================================
    // ส่วนประมวลผลหลัก (คลุมด้วย Try...Catch ทั้งหมด)
    // ==========================================
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // ตรวจสอบว่ามีไฟล์อยู่จริงไหมก่อนเรียกใช้
    if (!file_exists('db.php')) { throw new Exception("ไม่พบไฟล์ db.php"); }
    if (!file_exists('photo_config.php')) { throw new Exception("ไม่พบไฟล์ photo_config.php"); }

    require_once 'db.php';
    require_once 'photo_config.php';

    if (!defined('PER_PAGE')) {
        define('PER_PAGE', 12);
    }

    // ตรวจสอบตัวแปรการเชื่อมต่อ DB
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("เชื่อมต่อฐานข้อมูลล้มเหลว กรุณาตรวจสอบไฟล์ db.php");
    }

    /* ---------- ฟังก์ชัน ---------- */
    if (!function_exists('esc')) {
        function esc($s): string {
            return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('back_url')) {
        function back_url(string $tab, string $q, int $page): string {
            $params = ['tab' => $tab];
            if ($q !== '') { $params['q'] = $q; }
            if ($page > 1) { $params['page'] = $page; }
            return 'review_photos.php?' . http_build_query($params);
        }
    }

    $allowed_tabs = ['pending', 'approved', 'rejected', 'all'];
    $tab_labels   = ['pending' => 'รอตรวจ', 'approved' => 'ผ่านแล้ว', 'rejected' => 'ไม่ผ่าน', 'all' => 'ทั้งหมด'];

    /* ---------- สิทธิ์การเข้าถึง ---------- */
    if (empty($_SESSION['admin_id'])) {
        header('Location: admin_login.php');
        exit();
    }
    if (!function_exists('is_photo_reviewer')) {
        throw new Exception("ไม่พบฟังก์ชัน is_photo_reviewer() ตรวจสอบว่ามีฟังก์ชันนี้ในไฟล์ photo_config.php หรือไม่");
    }
    if (!is_photo_reviewer()) {
        http_response_code(403);
        die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>คุณไม่มีสิทธิ์เข้าหน้านี้</div>");
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    /* ---------- บันทึกผลการตรวจ (POST) ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rtab  = in_array($_POST['tab'] ?? '', $allowed_tabs, true) ? $_POST['tab'] : 'pending';
        $rq    = trim((string)($_POST['q'] ?? ''));
        $rpage = max(1, (int)($_POST['page'] ?? 1));

        $flash = ['type' => 'bad', 'text' => 'คำขอไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่'];

        if (hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            $id       = (int)($_POST['id'] ?? 0);
            $action   = (string)($_POST['action'] ?? '');
            $expected = (string)($_POST['expected'] ?? ''); 
            $reason   = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($_POST['reason'] ?? '')));
            $reason   = mb_substr($reason, 0, 200);

            if ($id > 0
                && in_array($action, ['approve', 'reject'], true)
                && in_array($expected, ['pending', 'approved', 'rejected'], true)) {

                if ($action === 'reject' && $reason === '') {
                    $flash = ['type' => 'bad', 'text' => 'กรุณาระบุเหตุผลที่ปฏิเสธรูป เพื่อให้นักเรียนแก้ไขได้ถูกต้อง'];
                } else {
                    try {
                        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
                        $note      = ($action === 'approve') ? 'เจ้าหน้าที่อนุมัติรูปแล้ว' : $reason;

                        $stmt = $conn->prepare(
                            "UPDATE students
                                SET image_status = ?, image_note = ?, image_checked_at = NOW()
                              WHERE id = ? AND image_status = ? AND image IS NOT NULL AND image <> ''"
                        );
                        $stmt->bind_param('ssis', $newStatus, $note, $id, $expected);
                        $stmt->execute();
                        $changed = $stmt->affected_rows;
                        $stmt->close();

                        if ($changed > 0) {
                            $flash = ['type' => 'ok', 'text' => ($action === 'approve') ? 'อนุมัติรูปเรียบร้อย' : 'ปฏิเสธรูปเรียบร้อย นักเรียนจะเห็นเหตุผลในหน้าโปรไฟล์'];
                        } else {
                            $flash = ['type' => 'bad', 'text' => 'รายการนี้ถูกตรวจหรือเปลี่ยนแปลงไปแล้ว กรุณาดูรายการล่าสุด'];
                        }
                    } catch (Throwable $e) {
                        error_log('review_photos: ' . $e->getMessage());
                        $flash = ['type' => 'bad', 'text' => 'บันทึกผลไม่สำเร็จ กรุณาลองใหม่'];
                    }
                }
            }
        }

        $_SESSION['flash'] = $flash;
        header('Location: ' . back_url($rtab, $rq, $rpage));
        exit();
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    /* ---------- ดึงรายการ ---------- */
    $tab  = in_array($_GET['tab'] ?? '', $allowed_tabs, true) ? $_GET['tab'] : 'pending';
    $q    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
    $page = max(1, (int)($_GET['page'] ?? 1));

    $rows   = [];
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    $total  = 0;
    $pages  = 1;
    $error  = '';

    try {
        $base = "FROM students WHERE image IS NOT NULL AND image <> '' AND image_status IS NOT NULL";

        $res = $conn->query("SELECT image_status, COUNT(*) AS c $base GROUP BY image_status");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (isset($counts[$row['image_status']])) {
                    $counts[$row['image_status']] = (int)$row['c'];
                }
            }
        }
        $counts['all'] = array_sum($counts);

        $where  = '';
        $types  = '';
        $params = [];
        if ($tab !== 'all') {
            $where .= ' AND image_status = ?';
            $types .= 's';
            $params[] = $tab;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (student_code LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS c $base$where");
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $pages  = max(1, (int)ceil($total / PER_PAGE));
        $page   = min($page, $pages);
        $offset = ($page - 1) * PER_PAGE;
        $order  = ($tab === 'pending') ? 'ASC' : 'DESC';

        $stmt = $conn->prepare(
            "SELECT id, student_code, prefix, first_name, last_name, battalion, company, platoon, section,
                    image, image_status, image_note, image_checked_at
               $base$where
             ORDER BY image_checked_at $order, id $order
             LIMIT ? OFFSET ?"
        );
        $types2  = $types . 'ii';
        $params2 = array_merge($params, [PER_PAGE, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('review_photos list: ' . $e->getMessage());
        $error = 'โหลดรายการไม่สำเร็จ ตรวจสอบว่าได้รัน migration.sql เพิ่มคอลัมน์สถานะรูปแล้ว หรือตรวจสอบ Error Log';
    }

    $status_chip = [
        'pending'  => ['รอตรวจ', 'chip-pending'],
        'approved' => ['ผ่านแล้ว', 'chip-approved'],
        'rejected' => ['ไม่ผ่าน', 'chip-rejected'],
    ];

} catch (Throwable $e) {
    // ==========================================
    // จัดการ Error 500 ทั่วไประดับระบบที่นี่
    // ==========================================
    http_response_code(500);
    die("<div style='max-width:600px; margin:50px auto; font-family:sans-serif; color:#991b1b; background:#fee2e2; padding:20px; border-radius:10px; border: 1px solid #f87171; text-align: left;'>
            <h2 style='margin-top:0;'>⚠️ ระบบขัดข้อง (HTTP 500)</h2>
            <p><b>ข้อผิดพลาด:</b> " . htmlspecialchars($e->getMessage()) . "</p>
            <p><b>ไฟล์:</b> " . htmlspecialchars($e->getFile()) . " <br><b>บรรทัด:</b> " . $e->getLine() . "</p>
            <hr style='border:0; border-top:1px solid #fca5a5;'>
            <p style='font-size:0.9em;'><em>คำแนะนำ: กรุณานำข้อความข้อผิดพลาดนี้ไปตรวจสอบในไฟล์ที่ระบุ (เช่น ตั้งค่า DB ผิด หรือโค้ดเรียกใช้งานฟังก์ชันที่ไม่ถูกต้อง)</em></p>
         </div>");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบรูปประจำตัว</title>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

    <style>
        :root {
            --primary: #5a42a0;
            --bg-color: #f6f8fc;
            --text-dark: #0f172a;
            --text-gray: #64748b;
            --card-bg: #ffffff;
            --line: #e2e8f0;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        [hidden] { display: none !important; }

        body {
            font-family: 'Prompt', sans-serif;
            background: var(--bg-color);
            color: var(--text-dark);
            margin: 0;
        }

        .page { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }

        .top { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .top a.back { color: var(--text-dark); text-decoration: none; font-size: 1.1rem; }
        .top h1 { font-size: 1.3rem; margin: 0; }

        .flash { border-radius: 12px; padding: 10px 14px; font-size: 0.9rem; font-weight: 500; margin-bottom: 14px; }
        .flash-ok { background: #dcfce7; color: #166534; }
        .flash-bad { background: #fee2e2; color: #991b1b; }

        .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .tab {
            text-decoration: none; color: var(--text-gray); background: #fff; border: 1px solid var(--line);
            padding: 7px 14px; border-radius: 999px; font-size: 0.85rem; font-weight: 500;
        }
        .tab b { margin-left: 4px; font-weight: 600; }
        .tab.active { background: var(--primary); border-color: var(--primary); color: #fff; }

        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; }
        .search { display: flex; gap: 8px; flex: 1; min-width: 220px; max-width: 420px; }
        .search input {
            flex: 1; border: 1px solid var(--line); border-radius: 10px; padding: 9px 12px;
            font-family: inherit; font-size: 0.9rem;
        }
        .btn {
            border: none; border-radius: 10px; padding: 9px 16px; font-family: inherit;
            font-size: 0.85rem; font-weight: 600; cursor: pointer;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-ok { background: #16a34a; color: #fff; }
        .btn-bad { background: #dc2626; color: #fff; }
        .btn-ghost { background: #fff; color: #b91c1c; border: 1.5px solid #fca5a5; }
        .btn:focus-visible, .tab:focus-visible, .chip:focus-visible, input:focus-visible, textarea:focus-visible, summary:focus-visible {
            outline: 3px solid #c4b5fd; outline-offset: 2px;
        }

        details.criteria {
            background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 12px 16px; margin-bottom: 18px;
        }
        details.criteria summary { cursor: pointer; font-weight: 600; font-size: 0.9rem; }
        .criteria-body { display: flex; gap: 16px; margin-top: 12px; flex-wrap: wrap; }
        .criteria-body img { width: 180px; height: 180px; object-fit: cover; border-radius: 12px; }
        .criteria-body ul { margin: 0; padding-left: 18px; font-size: 0.85rem; color: var(--text-gray); line-height: 1.7; flex: 1; min-width: 220px; }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 16px; }

        .card { background: var(--card-bg); border-radius: 16px; padding: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 12px; }
        .card-top { display: flex; gap: 12px; }
        .photo { position: relative; flex-shrink: 0; }
        .photo img.main { width: 150px; height: 150px; object-fit: cover; border-radius: 12px; background: #e2e8f0; cursor: zoom-in; display: block; }
        .photo img.ref { position: absolute; right: -6px; bottom: -6px; width: 56px; height: 56px; object-fit: cover; border-radius: 10px; border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.2); cursor: zoom-in; }
        .info { min-width: 0; font-size: 0.82rem; }
        .info h3 { margin: 0 0 2px; font-size: 0.98rem; line-height: 1.3; }
        .info .code { color: var(--text-gray); margin-bottom: 6px; }
        .info .unit { color: var(--text-gray); line-height: 1.5; }
        .info .time { color: var(--text-gray); margin-top: 6px; font-size: 0.75rem; }

        .chip-status { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 2px 10px; border-radius: 999px; margin-bottom: 6px; }
        .chip-pending { background: #fef3c7; color: #92400e; }
        .chip-approved { background: #dcfce7; color: #166534; }
        .chip-rejected { background: #fee2e2; color: #991b1b; }

        .note { background: #f8fafc; border-radius: 10px; padding: 8px 10px; font-size: 0.78rem; color: var(--text-gray); line-height: 1.5; }
        .note b { color: var(--text-dark); font-weight: 600; }

        .btn-row { display: flex; gap: 8px; }
        .btn-row .btn { flex: 1; }

        .reject-panel { margin-top: 10px; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
        .chip {
            border: 1px solid var(--line); background: #fff; border-radius: 999px; padding: 3px 10px;
            font-family: inherit; font-size: 0.72rem; color: var(--text-gray); cursor: pointer;
        }
        .reject-panel textarea {
            width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: 8px 10px;
            font-family: inherit; font-size: 0.85rem; resize: vertical; margin-bottom: 8px;
        }
        .reject-panel .btn { width: 100%; }

        .empty { text-align: center; color: var(--text-gray); padding: 50px 10px; background: #fff; border-radius: 16px; }

        .pager { display: flex; justify-content: center; align-items: center; gap: 14px; margin-top: 22px; font-size: 0.85rem; color: var(--text-gray); }
        .pager a { color: var(--primary); text-decoration: none; font-weight: 600; }

        #lightbox {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.85); display: flex;
            align-items: center; justify-content: center; z-index: 3000; cursor: zoom-out; padding: 20px;
        }
        #lightbox img { max-width: 100%; max-height: 92vh; border-radius: 12px; }
    </style>
</head>
<body>
<div class="page">

    <div class="top">
        <a href="main.php" class="back" aria-label="กลับหน้าหลัก"><i class="fas fa-chevron-left"></i></a>
        <h1>ตรวจสอบรูปประจำตัว</h1>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?php echo $flash['type'] === 'ok' ? 'ok' : 'bad'; ?>" role="status"><?php echo esc($flash['text']); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash flash-bad"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <nav class="tabs" aria-label="สถานะรูป">
        <?php foreach ($tab_labels as $key => $label): ?>
            <a class="tab <?php echo $tab === $key ? 'active' : ''; ?>" href="<?php echo esc(back_url($key, $q, 1)); ?>">
                <?php echo esc($label); ?><b><?php echo (int)($counts[$key] ?? 0); ?></b>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="toolbar">
        <form class="search" method="get" action="review_photos.php">
            <input type="hidden" name="tab" value="<?php echo esc($tab); ?>">
            <input type="search" name="q" value="<?php echo esc($q); ?>" placeholder="ค้นหารหัสประจำตัวหรือชื่อ" aria-label="ค้นหา">
            <button class="btn btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> ค้นหา</button>
        </form>
    </div>

    <details class="criteria">
        <summary>เกณฑ์ตรวจและภาพตัวอย่างที่ถูกต้อง</summary>
        <div class="criteria-body">
            <img src="uniform_reference.jpg" alt="ภาพตัวอย่างที่ถูกต้อง">
            <ul>
                <?php if (!empty($UNIFORM_CRITERIA)) { foreach ($UNIFORM_CRITERIA as $c): ?>
                    <li><b><?php echo esc($c['label']); ?>:</b> <?php echo esc($c['desc']); ?></li>
                <?php endforeach; } ?>
            </ul>
        </div>
    </details>

    <?php if (empty($rows) && $error === ''): ?>
        <div class="empty">
            <i class="fas fa-circle-check" style="font-size:1.6rem; margin-bottom:8px;"></i><br>
            <?php echo $q !== '' ? 'ไม่พบรายการที่ตรงกับคำค้น' : 'ไม่มีรูปในหมวดนี้'; ?>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($rows as $r):
                $name   = trim(($r['prefix'] ?? '') . ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $status = (string)$r['image_status'];
                [$chip_text, $chip_class] = $status_chip[$status] ?? [$status, 'chip-pending'];
                $unit_parts = [];
                foreach (['battalion' => 'กองพัน', 'company' => 'กองร้อย', 'platoon' => 'หมวด', 'section' => 'ตอน'] as $col => $lbl) {
                    if (!empty($r[$col])) {
                        $unit_parts[] = $lbl . ' ' . $r[$col];
                    }
                }
                $img_src = $r['image'] . '?v=' . rawurlencode((string)$r['image_checked_at']);
            ?>
            <article class="card">
                <div class="card-top">
                    <div class="photo">
                        <img class="main zoomable" src="<?php echo esc($img_src); ?>" alt="รูปของ <?php echo esc($name); ?>">
                        <img class="ref zoomable" src="uniform_reference.jpg" alt="ภาพตัวอย่าง" title="ภาพตัวอย่างที่ถูกต้อง">
                    </div>
                    <div class="info">
                        <span class="chip-status <?php echo esc($chip_class); ?>"><?php echo esc($chip_text); ?></span>
                        <h3><?php echo esc($name); ?></h3>
                        <div class="code">รหัส <?php echo esc($r['student_code']); ?></div>
                        <div class="unit"><?php echo esc(implode(' / ', $unit_parts) ?: '-'); ?></div>
                        <div class="time">อัปเดต <?php echo esc($r['image_checked_at'] ?? '-'); ?></div>
                    </div>
                </div>

                <?php if (!empty($r['image_note'])): ?>
                    <div class="note"><b><?php echo $status === 'rejected' ? 'เหตุผลที่ปฏิเสธ' : 'บันทึกจากระบบ'; ?>:</b> <?php echo esc($r['image_note']); ?></div>
                <?php endif; ?>

                <form method="post" action="review_photos.php">
                    <input type="hidden" name="csrf" value="<?php echo esc($_SESSION['csrf']); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="expected" value="<?php echo esc($status); ?>">
                    <input type="hidden" name="tab" value="<?php echo esc($tab); ?>">
                    <input type="hidden" name="q" value="<?php echo esc($q); ?>">
                    <input type="hidden" name="page" value="<?php echo (int)$page; ?>">

                    <div class="btn-row">
                        <?php if ($status !== 'approved'): ?>
                            <button type="submit" name="action" value="approve" class="btn btn-ok" formnovalidate><i class="fas fa-check"></i> อนุมัติ</button>
                        <?php endif; ?>
                        <?php if ($status !== 'rejected'): ?>
                            <button type="button" class="btn btn-ghost js-open-reject"><i class="fas fa-xmark"></i> ปฏิเสธ</button>
                        <?php endif; ?>
                    </div>

                    <div class="reject-panel" hidden>
                        <div class="chips">
                            <?php if (!empty($UNIFORM_CRITERIA)) { foreach ($UNIFORM_CRITERIA as $c): ?>
                                <button type="button" class="chip" data-text="<?php echo esc($c['label'] . ' ไม่ถูกต้อง'); ?>"><?php echo esc($c['label']); ?></button>
                            <?php endforeach; } ?>
                        </div>
                        <textarea name="reason" rows="2" maxlength="200" required placeholder="ระบุเหตุผล เพื่อให้นักเรียนแก้ไขและส่งใหม่"></textarea>
                        <button type="submit" name="action" value="reject" class="btn btn-bad">ยืนยันปฏิเสธ</button>
                    </div>
                </form>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <div class="pager">
                <?php if ($page > 1): ?><a href="<?php echo esc(back_url($tab, $q, $page - 1)); ?>">&laquo; ก่อนหน้า</a><?php endif; ?>
                <span>หน้า <?php echo (int)$page; ?> / <?php echo (int)$pages; ?> (<?php echo (int)$total; ?> รายการ)</span>
                <?php if ($page < $pages): ?><a href="<?php echo esc(back_url($tab, $q, $page + 1)); ?>">ถัดไป &raquo;</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<div id="lightbox" hidden><img alt="ขยายรูป"></div>

<script>
(function () {
    // เปิด/ปิดช่องเหตุผลการปฏิเสธ
    document.querySelectorAll('.js-open-reject').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = btn.closest('form').querySelector('.reject-panel');
            panel.hidden = !panel.hidden;
            if (!panel.hidden) panel.querySelector('textarea').focus();
        });
    });

    // ปุ่มเหตุผลสำเร็จรูป: เติมข้อความลงช่องเหตุผล
    document.querySelectorAll('.chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var ta = chip.closest('.reject-panel').querySelector('textarea');
            var add = chip.dataset.text;
            if (ta.value.indexOf(add) !== -1) return;
            var base = ta.value.replace(/[;\s]+$/, '');
            ta.value = (base ? base + '; ' : '') + add;
            ta.value = ta.value.slice(0, 200);
            ta.focus();
        });
    });

    // ขยายรูปเพื่อดูป้ายชื่อและเครื่องหมายใกล้ ๆ
    var lb = document.getElementById('lightbox');
    var lbImg = lb.querySelector('img');
    function closeLb() { lb.hidden = true; lbImg.removeAttribute('src'); }
    document.querySelectorAll('img.zoomable').forEach(function (img) {
        img.addEventListener('click', function () {
            lbImg.src = img.currentSrc || img.src;
            lb.hidden = false;
        });
    });
    lb.addEventListener('click', closeLb);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !lb.hidden) closeLb(); });
})();
</script>
</body>
</html>
