<?php
/**
 * main.php — หน้าหลัก MTTS
 *
 * โครงสร้างไฟล์
 *   1. Bootstrap   : session, header, auth
 *   2. Config      : ค่าคงที่
 *   3. Helpers     : ฟังก์ชันช่วย (DB / แปลงค่า / escape)
 *   4. Data layer  : ฟังก์ชันดึงข้อมูลจากฐานข้อมูล
 *   5. View model  : เตรียมข้อมูลที่หน้าเว็บต้องใช้ (แจ้งเตือน, เมนู, ข่าว)
 *   6. View        : HTML + CSS + JS
 */

/* ========== 1. Bootstrap ========== */
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db.php';

function redirect(string $url): void
{
    header("Location: $url");
    exit();
}

if (empty($_SESSION['student_code'])) {
    redirect('login.php');
}

// แสดง loader เฉพาะครั้งแรกของ session
$show_loading = !isset($_SESSION['has_seen_loading']);
$_SESSION['has_seen_loading'] = true;

/* ========== 2. Config ========== */
const APP_LOGO_URL       = 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg';
const DEFAULT_AVATAR_URL = 'https://taothetutor.wordpress.com/wp-content/uploads/2026/10/300-400-px.png';
const VAPID_PUBLIC_KEY   = 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA';
const PROFILE_IMG_DIR    = 'uploads/profiles/';
const NEWS_IMG_DIR       = 'uploads/news/';
const NEWS_LIMIT         = 10;
const DISCIPLINE_RED_AT  = 3; // จำนวนบัตรที่ถือเป็นสถานะแดง

/* ========== 3. Helpers ========== */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_url(string $value): bool
{
    return (bool)preg_match('/^https?:\/\//i', $value);
}

/** รัน prepared statement แล้วคืนทุกแถว (คืน [] ถ้า prepare ไม่สำเร็จ) */
function db_all(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function db_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    return db_all($db, $sql, $types, $params)[0] ?? null;
}

function resolve_profile_image(?string $image): string
{
    if (empty($image)) return DEFAULT_AVATAR_URL;
    return is_url($image) ? $image : PROFILE_IMG_DIR . $image;
}

function resolve_news_attachment(?string $path): string
{
    $path = (string)$path;
    if ($path !== '' && !is_url($path) && strpos($path, 'uploads/') !== 0) {
        return NEWS_IMG_DIR . $path;
    }
    return $path;
}

function build_student_name(array $s): string
{
    return trim(($s['prefix'] ?? '') . ($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
}

function build_student_unit(array $s): string
{
    $parts = [];
    if (!empty($s['battalion'])) $parts[] = 'พัน นร.' . $s['battalion'];
    if (!empty($s['company']))   $parts[] = 'ร้อย.' . $s['company'];
    if (!empty($s['platoon']))   $parts[] = 'หมวด ' . $s['platoon'];
    if (!empty($s['section']))   $parts[] = 'วิชาชีพช่าง ' . $s['section'];
    return $parts ? implode(' ', $parts) : 'ไม่มีข้อมูล';
}

/* ========== 4. Data layer ========== */
function fetch_student(mysqli $db, string $code): ?array
{
    return db_one($db,
        "SELECT student_code, prefix, first_name, last_name, platoon, company, battalion, section, image
         FROM students WHERE student_code = ? LIMIT 1", 's', [$code]);
}

/** เวรที่จะถึงในอีก 1 วัน และ 3 วัน */
function fetch_upcoming_duties(mysqli $db, string $code): array
{
    return db_all($db,
        "SELECT duty_name, duty_date, DATEDIFF(duty_date, CURDATE()) AS days_left
         FROM student_duties
         WHERE student_code = ? AND DATEDIFF(duty_date, CURDATE()) IN (1, 3)
         ORDER BY duty_date ASC", 's', [$code]);
}

/** โหวตประสงค์กลับบ้านในสัปดาห์นี้แล้วหรือยัง */
function has_voted_this_week(mysqli $db, string $code): bool
{
    $row = db_one($db,
        "SELECT COUNT(*) AS vote_count FROM weekly_votes
         WHERE TRIM(student_code) = TRIM(?)
           AND YEARWEEK(COALESCE(vote_date, DATE(created_at)), 1) = YEARWEEK(CURDATE(), 1)", 's', [$code]);
    return $row ? ((int)$row['vote_count'] > 0) : true;
}

/** บัตรวินัยวันนี้ (คืน null ถ้าไม่มีข้อมูล) พร้อมคีย์ 'count' = จำนวนบัตรรวม */
function fetch_today_discipline(mysqli $db, string $code): ?array
{
    $row = db_one($db,
        "SELECT card_bed, card_shoe, card_cabinet, total_cards, status_color
         FROM discipline_cards
         WHERE TRIM(student_code) = TRIM(?) AND DATE(check_date) = ?
         ORDER BY id DESC LIMIT 1", 'ss', [$code, date('Y-m-d')]);
    if (!$row) return null;

    $sum = (int)($row['card_bed'] ?? 0) + (int)($row['card_shoe'] ?? 0) + (int)($row['card_cabinet'] ?? 0);
    $row['count'] = max((int)($row['total_cards'] ?? 0), $sum);
    return $row;
}

function fetch_total_discipline_cards(mysqli $db, string $code): int
{
    $row = db_one($db,
        "SELECT COALESCE(SUM(total_cards), 0) AS all_cards
         FROM discipline_cards WHERE TRIM(student_code) = TRIM(?)", 's', [$code]);
    return (int)($row['all_cards'] ?? 0);
}

function fetch_announcements(mysqli $db, int $limit): array
{
    return db_all($db,
        "SELECT id, title, content, attachment, created_at
         FROM announcements ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit);
}

/* ========== 5. View model ========== */
function build_alerts(?array $disc, bool $hasVoted, array $duties): array
{
    $alerts = [];

    // 5.1 บัตรวินัย
    if ($disc && $disc['count'] > 0) {
        $total = $disc['count'];
        $items = [];
        if (!empty($disc['card_bed']))     $items[] = 'เตียง';
        if (!empty($disc['card_cabinet'])) $items[] = 'ตู้';
        if (!empty($disc['card_shoe']))    $items[] = 'รองเท้า';
        $itemsText = $items ? implode(', ', $items) : 'บัตรวินัย';
        $isRed = $total >= DISCIPLINE_RED_AT || strtolower(trim($disc['status_color'] ?? '')) === 'red';

        $alerts[] = [
            'type'   => $isRed ? 'urgent' : 'warning',
            'icon'   => $isRed ? 'fa-id-card-alt' : 'fa-exclamation-triangle',
            'title'  => ($isRed ? 'สถานะ: แดง' : 'สถานะ: เตือน') . " ($total ใบ)",
            'text'   => "รายการ: $itemsText",
            'action' => ['href' => 'discipline_history.php', 'label' => 'ตรวจสอบ'],
        ];
    } else {
        $alerts[] = [
            'type' => 'success', 'icon' => 'fa-check-circle',
            'title' => 'บัตรวินัยวันนี้: ปกติ', 'text' => 'ยังไม่มีรายการโดนตัดบัตรวินัย',
            'action' => null,
        ];
    }

    // 5.2 โหวตกลับบ้าน
    if (!$hasVoted) {
        $alerts[] = [
            'type' => 'urgent', 'icon' => 'fa-vote-yea',
            'title' => 'ยังไม่ได้โหวตกลับบ้าน', 'text' => 'กรุณาทำรายการในสัปดาห์นี้',
            'action' => ['href' => 'fill.php', 'label' => 'โหวตเลย'],
        ];
    }

    // 5.3 เวร
    foreach ($duties as $duty) {
        $alerts[] = [
            'type'   => ((int)$duty['days_left'] === 1) ? 'urgent' : 'warning',
            'icon'   => 'fa-clock',
            'title'  => 'เวร: ' . $duty['duty_name'],
            'text'   => 'อีก ' . $duty['days_left'] . ' วัน (' . date('d/m/Y', strtotime($duty['duty_date'])) . ')',
            'action' => null,
        ];
    }

    return $alerts;
}

function build_news_items(array $announcements): array
{
    $items = [];
    foreach ($announcements as $news) {
        $path    = resolve_news_attachment($news['attachment'] ?? '');
        $ext     = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isImage = !empty($news['attachment']) && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

        $items[] = [
            'title'     => $news['title'],
            'content'   => $news['content'],
            'date'      => date('d/m/Y H:i', strtotime($news['created_at'])),
            'image_url' => $isImage ? $path . '?v=' . time() : null,
        ];
    }
    return $items;
}

$menu_items = [
    ['href' => 'duty.php',               'icon' => 'fa-user-clock',   'color' => 'bg-purple', 'label' => 'เวรของฉัน'],
    ['href' => 'fill.php',               'icon' => 'fa-vote-yea',     'color' => 'bg-orange', 'label' => 'โหวตกลับบ้าน'],
    ['href' => 'discipline_history.php', 'icon' => 'fa-file-alt',     'color' => 'bg-blue',   'label' => 'บัตรวินัย'],
    ['href' => 'duty_substitutions.php', 'icon' => 'fa-address-book', 'color' => 'bg-green',  'label' => 'เปลี่ยนเวร'],
    ['href' => 'commanders.php', 'icon' => 'fa-solid fa-user-tie', 'color' => 'bg-green',  'label' => 'รายนามผู้บังคับบัญชา'],
];

$nav_items = [
    ['href' => 'main.php',    'icon' => 'fa-home', 'label' => 'หน้าหลัก', 'active' => true],
    ['href' => 'profile.php', 'icon' => 'fa-user', 'label' => 'โปรไฟล์',  'active' => false],
];

/* โหลดข้อมูลทั้งหมดของหน้า */
try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection error');
    }
    $conn->set_charset('utf8mb4');

    $rawCode = $_SESSION['student_code'];
    $code    = trim($rawCode);

    $student = fetch_student($conn, $rawCode);
    if (!$student) {
        session_destroy();
        redirect('login.php');
    }

    $duties         = fetch_upcoming_duties($conn, $rawCode);
    $has_voted      = has_voted_this_week($conn, $rawCode);
    $today_disc     = fetch_today_discipline($conn, $code);
    $total_cards    = fetch_total_discipline_cards($conn, $code);
    $announcements  = fetch_announcements($conn, NEWS_LIMIT);
} catch (Exception $e) {
    error_log('main.php: ' . $e->getMessage());
    die('เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่อีกครั้ง');
}

$alerts     = build_alerts($today_disc, $has_voted, $duties);
$news_items = build_news_items($announcements);

$noti_count = count($duties)
            + ($has_voted ? 0 : 1)
            + (($today_disc['count'] ?? 0) > 0 ? 1 : 0);

$profile = [
    'name'  => build_student_name($student),
    'code'  => 'รหัส ' . ($student['student_code'] ?? ''),
    'unit'  => build_student_unit($student),
    'image' => resolve_profile_image($student['image'] ?? ''),
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

  <title>หน้าหลัก - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>


:root{--primary:#5a42a0;--primary-light:#7c5dfa;--bg-color:#f6f8fc;--card-bg:#fff;--text-dark:#0f172a;--text-gray:#64748b;--text-muted:#94a3b8;--danger:#ef4444;--warning:#f59e0b;--success:#10b981;--radius:24px;--radius-sm:18px;--shadow-card:0 1px 2px rgba(20,20,43,.05),0 4px 14px rgba(20,20,43,.04);--gutter:20px;--nav:64px;--safe-t:env(safe-area-inset-top,0px);--safe-b:env(safe-area-inset-bottom,0px)}

/* ===== Base ===== */
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{font-family:'Prompt',sans-serif;background:#d9dce6;margin:0;display:flex;justify-content:center;-webkit-user-select:none;user-select:none;min-height:100dvh;overscroll-behavior-y:none;touch-action:manipulation}
.app-container{width:100%;max-width:430px;min-height:100vh;min-height:100dvh;position:relative;background:var(--bg-color);padding-bottom:calc(var(--nav) + var(--safe-b) + 16px)}
@media(min-width:431px){.app-container{box-shadow:0 0 40px rgba(15,23,42,.14)}}
.section{margin-bottom:25px}
.section-title{margin:0 var(--gutter) 15px}
.section-title h2{font-size:1.05rem;font-weight:700;color:var(--text-dark);margin:0}

/* ===== Animation ===== */
.fade-in-up{opacity:0;transform:translateY(20px);animation:fadeInUp .5s ease forwards}
.delay-1{animation-delay:.1s}.delay-2{animation-delay:.2s}.delay-3{animation-delay:.3s}.delay-4{animation-delay:.4s}
@keyframes fadeInUp{to{opacity:1;transform:translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}

/* ===== Splash (ครั้งแรกของ session) ===== */
#page-loader{position:fixed;inset:0;background:linear-gradient(160deg,#5a42a0,#473282);z-index:9999;display:flex;flex-direction:column;justify-content:center;align-items:center;transition:opacity .5s ease,visibility .5s ease}
#page-loader.hidden{opacity:0;visibility:hidden}
.loader-logo{width:88px;height:88px;padding:6px;border-radius:22px;background:#fff;animation:pulse 1.5s infinite ease-in-out}
.loader-spinner{margin-top:24px;width:36px;height:36px;border-radius:50%;border:4px solid rgba(255,255,255,.25);border-top-color:#fff;animation:spin 1s linear infinite}

/* ===== แถบหัวแอป (ติดบน เบลอพื้นหลังแบบ iOS) ===== */
.top-header{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;padding:calc(10px + var(--safe-t)) var(--gutter) 10px;background:rgba(246,248,252,.9);-webkit-backdrop-filter:saturate(1.8) blur(14px);backdrop-filter:saturate(1.8) blur(14px)}
.header-left{display:flex;align-items:center;gap:12px}
.school-logo{width:40px;height:40px;object-fit:contain;border-radius:50%;background:#fff}
.school-name h1{font-size:1.05rem;font-weight:700;color:var(--text-dark);margin:0 0 2px}
.school-name p{font-size:.75rem;color:var(--text-gray);margin:0;font-weight:500}
.noti-btn{position:relative;width:40px;height:40px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;color:var(--text-gray);font-size:1.2rem;text-decoration:none;box-shadow:0 1px 3px rgba(20,20,43,.08);transition:transform .12s}
.noti-btn:active{transform:scale(.92)}
.noti-badge{position:absolute;top:0;right:0;width:18px;height:18px;border-radius:50%;background:var(--danger);color:#fff;font-size:.65rem;font-weight:700;border:2px solid #fff;display:flex;align-items:center;justify-content:center}

/* ===== การ์ดนักเรียน (พื้นหลังเคลื่อนไหว) ===== */
.student-card{position:relative;overflow:hidden;display:flex;align-items:center;gap:15px;margin:6px var(--gutter) 25px;padding:20px;border-radius:var(--radius);color:#fff;background:linear-gradient(135deg,var(--primary),#6366f1);box-shadow:0 15px 30px rgba(79,70,229,.25)}
.card-bg{position:absolute;inset:0;z-index:0;pointer-events:none;overflow:hidden;background:linear-gradient(120deg,#5a42a0,#6366f1,#7c5dfa,#4f46e5,#5a42a0);background-size:300% 300%;animation:cardGradient 10s ease-in-out infinite}
.card-bg span{position:absolute;border-radius:50%;will-change:transform;background:radial-gradient(circle,rgba(255,255,255,.28),rgba(255,255,255,0) 70%)}
.card-bg span:nth-child(1){width:130px;height:130px;top:-45px;right:-25px;animation:orbFloatA 7s ease-in-out infinite}
.card-bg span:nth-child(2){width:100px;height:100px;bottom:-40px;left:32%;animation:orbFloatB 9s ease-in-out infinite}
.card-bg span:nth-child(3){width:70px;height:70px;top:25%;left:-20px;animation:orbFloatC 11s ease-in-out infinite}
.student-card::after{content:'';position:absolute;inset:0;z-index:0;pointer-events:none;background:linear-gradient(115deg,transparent 35%,rgba(255,255,255,.18) 50%,transparent 65%);transform:translateX(-120%);animation:cardShine 6s ease-in-out infinite}
@keyframes cardGradient{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes orbFloatA{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(-25px,22px) scale(1.15)}}
@keyframes orbFloatB{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(30px,-18px) scale(.9)}}
@keyframes orbFloatC{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(22px,14px) scale(1.2)}}
@keyframes cardShine{0%,55%{transform:translateX(-120%)}100%{transform:translateX(120%)}}
.profile-img{z-index:1;width:70px;height:70px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid rgba(255,255,255,.4)}
.student-info{z-index:1;flex:1}
.student-info h2{font-size:1.15rem;font-weight:600;margin:0 0 5px;line-height:1.2}
.student-info p{font-size:.85rem;margin:0 0 8px;opacity:.9}
.badges{display:flex;flex-wrap:wrap;gap:6px}
.badge-item{padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:500;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);backdrop-filter:blur(4px)}
.badge-item.badge-danger{background:var(--danger);border-color:#f87171}

/* ===== การแจ้งเตือน ===== */
.alert-list{display:flex;flex-direction:column;gap:12px;margin:0 var(--gutter)}
.alert-card{position:relative;overflow:hidden;display:flex;align-items:center;gap:15px;padding:16px;border-radius:var(--radius-sm);background:var(--card-bg);box-shadow:var(--shadow-card);transition:transform .12s}
.alert-card:active{transform:scale(.98)}
.alert-card::before{content:'';position:absolute;left:0;top:0;width:5px;height:100%}
.alert-card.urgent::before{background:var(--danger)}
.alert-card.warning::before{background:var(--warning)}
.alert-card.success::before{background:var(--success)}
.alert-icon{font-size:1.4rem}
.urgent .alert-icon{color:var(--danger)}
.warning .alert-icon{color:var(--warning)}
.success .alert-icon{color:var(--success)}
.alert-info{flex:1}
.alert-info h4{margin:0 0 3px;font-size:.9rem;font-weight:700;color:var(--text-dark)}
.alert-info p{margin:0;font-size:.8rem;color:var(--text-gray)}
.btn-action{padding:7px 14px;border-radius:999px;font-size:.75rem;font-weight:600;text-decoration:none;text-align:center;white-space:nowrap;transition:.2s}
.btn-action.urgent{background:#fee2e2;color:var(--danger)}
.btn-action.warning{background:#fef3c7;color:var(--warning)}

/* ===== บริการระบบ: ไอคอนแอปแบบภาพ SVG (จับคู่ตามลิงก์) ===== */
.menu-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px 8px;margin:0 var(--gutter)}
.menu-item{display:flex;flex-direction:column;align-items:center;text-align:center;color:var(--text-dark);text-decoration:none}
.icon-box{width:60px;height:60px;margin-bottom:8px;border-radius:18px;box-shadow:0 4px 12px rgba(20,20,43,.12);background-color:#e2e8f0;background-size:cover;background-repeat:no-repeat;transition:transform .12s}
.menu-item:active .icon-box{transform:scale(.9)}
.icon-box i{display:none}
.menu-text{font-size:.72rem;font-weight:600;line-height:1.!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#503896">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>หน้าหลัก - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--primary:#503896;--bar:#503896;--bg:#f2f3f8;--ink:#1c1b29;--mute:#6b7280;--faint:#94a3b8;--danger:#ef4444;--warning:#f59e0b;--success:#10b981;--g:16px;--nav:64px;--st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);--shadow:0 1px 2px rgba(20,20,43,.05),0 4px 14px rgba(20,20,43,.05)}
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
    html{-webkit-text-size-adjust:100%}
    body{margin:0;font-family:'Prompt',sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100dvh;-webkit-user-select:none;user-select:none;overscroll-behavior-y:none;touch-action:manipulation}
    .app-container{width:100%;max-width:430px;min-height:100dvh;background:var(--bg);padding-bottom:calc(var(--nav) + var(--sb) + 16px)}
    @media(min-width:431px){.app-container{box-shadow:0 0 40px rgba(15,23,42,.14)}}
    .section{margin-bottom:22px}
    .section-title{margin:0 var(--g) 12px}
    .section-title h2{margin:0;font-size:1rem;font-weight:700}

    /* animation */
    .fade-in-up{opacity:0;transform:translateY(16px);animation:fadeInUp .5s cubic-bezier(.16,1,.3,1) forwards}
    .delay-2{animation-delay:.1s}.delay-3{animation-delay:.2s}.delay-4{animation-delay:.3s}
    @keyframes fadeInUp{to{opacity:1;transform:none}}
    @keyframes spin{to{transform:rotate(360deg)}}
    @keyframes pulse{50%{transform:scale(1.05)}}
    @media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.fade-in-up{opacity:1;transform:none}}

    /* splash */
    #page-loader{position:fixed;inset:0;z-index:9999;background:linear-gradient(160deg,#503896,#3d2b78);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;transition:opacity .5s,visibility .5s}
    #page-loader.hidden{opacity:0;visibility:hidden}
    .loader-logo{width:88px;height:88px;padding:6px;border-radius:22px;background:#fff;animation:pulse 1.5s infinite ease-in-out}
    .loader-spinner{width:34px;height:34px;border-radius:50%;border:4px solid rgba(255,255,255,.25);border-top-color:#fff;animation:spin 1s linear infinite}

    /* app bar + hero */
    .top-header{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;padding:calc(10px + var(--st)) var(--g) 10px;background:var(--bar);color:#fff}
    .header-left{display:flex;align-items:center;gap:12px}
    .school-logo{width:40px;height:40px;border-radius:50%;object-fit:contain;background:#fff;padding:2px}
    .school-name h1{margin:0;font-size:.95rem;font-weight:600;line-height:1.25}
    .school-name p{margin:0;font-size:.72rem;opacity:.8}
    .noti-btn{position:relative;display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.15rem;text-decoration:none;transition:transform .12s}
    .noti-btn:active{transform:scale(.92)}
    .noti-badge{position:absolute;top:-2px;right:-2px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:var(--danger);color:#fff;font-size:.65rem;font-weight:700;border:2px solid var(--bar);display:flex;align-items:center;justify-content:center}
    .hero{background:var(--bar);color:#fff;padding:4px 20px 58px;border-radius:0 0 28px 28px}
    .hero-badge{display:inline-flex;align-items:center;gap:6px;margin-bottom:12px;padding:5px 11px;border-radius:999px;background:rgba(255,255,255,.16);font-size:.72rem;font-weight:500}
    .hero small{display:block;font-size:.8rem;opacity:.8}
    .hero strong{display:block;margin:2px 0 6px;font-size:1.5rem;font-weight:700;line-height:1.3}
    .hero-sub{font-size:.78rem;opacity:.8}

    /* student card (พื้นหลังเคลื่อนไหวเดิม) */
    .student-card{position:relative;overflow:hidden;display:flex;align-items:center;gap:15px;margin:-38px var(--g) 22px;padding:20px;border-radius:24px;color:#fff;border:1px solid rgba(255,255,255,.18);background:#503896;box-shadow:0 12px 28px rgba(80,56,150,.3)}
    .card-bg{position:absolute;inset:0;z-index:0;pointer-events:none;overflow:hidden;background:linear-gradient(120deg,#503896,#6366f1,#7c5dfa,#4f46e5,#503896);background-size:300% 300%;animation:cardGradient 10s ease-in-out infinite}
    .card-bg span{position:absolute;border-radius:50%;will-change:transform;background:radial-gradient(circle,rgba(255,255,255,.28),rgba(255,255,255,0) 70%)}
    .card-bg span:nth-child(1){width:130px;height:130px;top:-45px;right:-25px;animation:orbA 7s ease-in-out infinite}
    .card-bg span:nth-child(2){width:100px;height:100px;bottom:-40px;left:32%;animation:orbB 9s ease-in-out infinite}
    .card-bg span:nth-child(3){width:70px;height:70px;top:25%;left:-20px;animation:orbC 11s ease-in-out infinite}
    .student-card::after{content:'';position:absolute;inset:0;z-index:0;pointer-events:none;background:linear-gradient(115deg,transparent 35%,rgba(255,255,255,.18) 50%,transparent 65%);transform:translateX(-120%);animation:shine 6s ease-in-out infinite}
    @keyframes cardGradient{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
    @keyframes orbA{50%{transform:translate(-25px,22px) scale(1.15)}}
    @keyframes orbB{50%{transform:translate(30px,-18px) scale(.9)}}
    @keyframes orbC{50%{transform:translate(22px,14px) scale(1.2)}}
    @keyframes shine{0%,55%{transform:translateX(-120%)}100%{transform:translateX(120%)}}
    .profile-img{z-index:1;width:70px;height:70px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid rgba(255,255,255,.4)}
    .student-info{z-index:1;flex:1;min-width:0}
    .student-info h2{margin:0 0 4px;font-size:1.15rem;font-weight:600;line-height:1.25}
    .student-info p{margin:0 0 8px;font-size:.85rem;opacity:.9}
    .badges{display:flex;flex-wrap:wrap;gap:6px}
    .badge-item{padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:500;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3)}
    .badge-item.badge-danger{background:var(--danger);border-color:#f87171}

    /* alerts */
    .alert-list{display:flex;flex-direction:column;gap:12px;margin:0 var(--g)}
    .alert-card{position:relative;overflow:hidden;display:flex;align-items:center;gap:14px;padding:16px;border-radius:18px;background:#fff;box-shadow:var(--shadow);transition:transform .12s}
    .alert-card:active{transform:scale(.98)}
    .alert-card::before{content:'';position:absolute;left:0;top:0;width:5px;height:100%}
    .urgent::before{background:var(--danger)}.warning::before{background:var(--warning)}.success::before{background:var(--success)}
    .alert-icon{font-size:1.4rem}
    .urgent .alert-icon{color:var(--danger)}.warning .alert-icon{color:var(--warning)}.success .alert-icon{color:var(--success)}
    .alert-info{flex:1}
    .alert-info h4{margin:0 0 3px;font-size:.9rem;font-weight:700}
    .alert-info p{margin:0;font-size:.8rem;color:var(--mute)}
    .btn-action{padding:7px 14px;border-radius:999px;font-size:.75rem;font-weight:600;text-decoration:none;white-space:nowrap}
    .btn-action.urgent{background:#fee2e2;color:var(--danger)}.btn-action.warning{background:#fef3c7;color:#b45309}

    /* บริการระบบ: การ์ดขาว + ไอคอนแอปแบบภาพ SVG (จับคู่ตามลิงก์) */
    .menu-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px 4px;margin:0 var(--g);padding:18px 8px;background:#fff;border-radius:20px;box-shadow:var(--shadow)}
    .menu-item{display:flex;flex-direction:column;align-items:center;text-align:center;color:var(--ink);text-decoration:none}
    .icon-box{width:58px;height:58px;margin-bottom:8px;border-radius:18px;background:#e2e8f0 center/cover no-repeat;box-shadow:0 4px 10px rgba(20,20,43,.12);transition:transform .12s}
    .menu-item:active .icon-box{transform:scale(.9)}
    .icon-box i{display:none}
    .menu-text{font-size:.72rem;font-weight:600;line-height:1.3}
    .menu-item[href='duty.php'] .icon-box{background-image:url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='%236d4fc2'/><circle cx='32' cy='34' r='17' fill='%23fff'/><path d='M32 23v11l8 5' stroke='%236d4fc2' stroke-width='4' stroke-linecap='round' stroke-linejoin='round' fill='none'/><path d='M26 11h12l-3 7H29z' fill='%23fbbf24'/></svg>")}
    .menu-item[href='fill.php'] .icon-box{background-image:url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='%23f97316'/><rect x='14' y='32' width='36' height='20' rx='4' fill='%23fff'/><rect x='22' y='36' width='20' height='4' rx='2' fill='%23f97316'/><rect x='23' y='11' width='18' height='22' rx='3' fill='%23ffedd5'/><path d='M28 21l3 3 6-7' stroke='%2316a34a' stroke-width='3.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>")}
    .menu-item[href='discipline_history.php'] .icon-box{background-image:url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='%230284c7'/><rect x='11' y='16' width='42' height='32' rx='5' fill='%23fff'/><path d='M11 21a5 5 0 0 1 5-5h32a5 5 0 0 1 5 5v4H11z' fill='%23f472b6'/><circle cx='23' cy='36' r='5' fill='%23bae6fd'/><rect x='32' y='31' width='15' height='3' rx='1.5' fill='%23cbd5e1'/><rect x='32' y='38' width='11' height='3' rx='1.5' fill='%23cbd5e1'/></svg>")}
    .menu-item[href='duty_substitutions.php'] .icon-box{background-image:url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='%23059669'/><circle cx='22' cy='22' r='6' fill='%23fff'/><path d='M12 38a10 10 0 0 1 20 0z' fill='%23fff'/><circle cx='44' cy='22' r='6' fill='%23a7f3d0'/><path d='M34 38a10 10 0 0 1 20 0z' fill='%23a7f3d0'/><path d='M16 49h32M21 44l-5 5 5 5M43 44l5 5-5 5' stroke='%23fff' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>")}
    .menu-item[href='commanders.php'] .icon-box{background-image:url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='%231e3a8a'/><circle cx='32' cy='23' r='9' fill='%23fff'/><path d='M14 54c0-11 8-17 18-17s18 6 18 17z' fill='%23fff'/><path d='M29 38h6l-1 4 3 12H27l3-12z' fill='%23fbbf24'/></svg>")}

    /* news */
    .news-carousel{display:flex;gap:14px;padding:4px var(--g) 10px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;-webkit-overflow-scrolling:touch;scrollbar-width:none}
    .news-carousel::-webkit-scrollbar{display:none}
    .news-empty{width:100%;padding:20px;text-align:center;color:var(--mute)}
    .news-card{flex:0 0 85%;max-width:350px;display:flex;flex-direction:column;overflow:hidden;scroll-snap-align:center;background:#fff;border-radius:20px;box-shadow:var(--shadow)}
    .news-media{position:relative;width:100%;height:180px;background:#e2e8f0;overflow:hidden;cursor:pointer}
    .news-media img{width:100%;height:100%;object-fit:cover}
    .news-media--placeholder{height:120px;background:var(--primary);cursor:default;display:flex;align-items:center;justify-content:center}
    .news-media--placeholder i{font-size:3rem;color:rgba(255,255,255,.5)}
    .news-tag{position:absolute;top:12px;left:12px;padding:4px 12px;border-radius:20px;background:rgba(255,255,255,.92);color:var(--primary);font-size:.7rem;font-weight:700}
    .news-body{display:flex;flex-direction:column;flex:1;padding:15px}
    .news-date{display:flex;align-items:center;gap:4px;margin-bottom:6px;font-size:.7rem;font-weight:500;color:var(--faint)}
    .news-title,.news-desc{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .news-title{margin:0 0 6px;font-size:.95rem;font-weight:700;line-height:1.4}
    .news-desc{margin:0;font-size:.8rem;color:var(--mute);line-height:1.5}
    .news-btn-link{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;margin-top:12px;padding:8px 14px;border-radius:999px;background:#f1f5f9;border:1px solid #e2e8f0;color:var(--primary);font-size:.85rem;font-weight:600;text-decoration:none;transition:transform .12s}
    .news-btn-link:active{transform:scale(.96)}
    .news-btn-link i.fa-google-drive{color:#16a34a}
    .carousel-dots{display:flex;justify-content:center;gap:6px;margin:6px 0 20px}
    .carousel-dots .dot{width:8px;height:8px;border-radius:50%;background:#cbd5e1;cursor:pointer;transition:all .3s cubic-bezier(.4,0,.2,1)}
    .carousel-dots .dot.active{width:22px;border-radius:10px;background:var(--primary)}

    /* bottom nav สีม่วง */
    .bottom-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:1000;width:100%;max-width:430px;height:calc(var(--nav) + var(--sb));padding-bottom:var(--sb);display:flex;align-items:center;background:var(--bar);box-shadow:0 -4px 18px rgba(20,20,43,.18)}
    .nav-link{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;color:rgba(255,255,255,.6);font-size:.7rem;font-weight:500;text-decoration:none}
    .nav-link i{display:grid;place-items:center;width:56px;height:30px;border-radius:15px;font-size:1.2rem;transition:background .2s}
    .nav-link.active{color:#fff;font-weight:600}
    .nav-link.active i{background:rgba(255,255,255,.2)}
    .nav-link:active{color:#fff}

    /* image modal */
    .image-modal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.9);justify-content:center;align-items:center;opacity:0;transition:.3s}
    .image-modal.show{display:flex;opacity:1}
    .modal-content-img{max-width:90%;max-height:80vh;border-radius:12px}
    .modal-close-btn{position:absolute;top:calc(16px + var(--st));right:20px;width:40px;height:40px;border:0;border-radius:50%;background:rgba(255,255,255,.2);color:#fff;font-size:1.5rem;cursor:pointer}
  </style>
</head>
<body>

  <?php if ($show_loading): ?>
  <div id="page-loader">
    <img src="<?= e(APP_LOGO_URL) ?>" alt="Loading Logo" class="loader-logo">
    <div class="loader-spinner"></div>
  </div>
  <?php endif; ?>

  <div class="app-container">

    <header class="top-header">
      <div class="header-left">
        <img src="<?= e(APP_LOGO_URL) ?>" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>กองพันนักเรียนที่ 1</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <a href="nofitication.php" class="noti-btn" id="notiBellBtn" aria-label="การแจ้งเตือน">
        <i class="far fa-bell"></i>
        <?php if ($noti_count > 0): ?><span class="noti-badge"><?= (int)$noti_count ?></span><?php endif; ?>
      </a>
    </header>

    <section class="hero" aria-label="ยินดีต้อนรับ">
      <div class="hero-badge"><i class="fas fa-shield-alt"></i> ระบบนักเรียน MTTS</div>
      <small>ยินดีต้อนรับ</small>
      <strong>หน้าหลัก</strong>
      <div class="hero-sub">ตรวจสอบสถานะและบริการของคุณได้ที่นี่</div>
    </section>

    <main>
      <section class="student-card fade-in-up delay-2" aria-label="ข้อมูลนักเรียน">
        <div class="card-bg" aria-hidden="true"><span></span><span></span><span></span></div>
        <img src="<?= e($profile['image']) ?>" alt="Profile" class="profile-img">
        <div class="student-info">
          <h2><?= e($profile['name']) ?></h2>
          <p><?= e($profile['code']) ?></p>
          <div class="badges">
            <div class="badge-item"><?= e($profile['unit']) ?></div>
            <?php if ($total_cards > 0): ?>
              <div class="badge-item badge-danger">โดนตัดบัตรสะสม: <?= $total_cards ?> ใบ</div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="section fade-in-up delay-3">
        <div class="section-title"><h2>การแจ้งเตือนของฉัน</h2></div>
        <div class="alert-list">
          <?php foreach ($alerts as $alert): ?>
            <div class="alert-card <?= e($alert['type']) ?>">
              <div class="alert-icon"><i class="fas <?= e($alert['icon']) ?>"></i></div>
              <div class="alert-info">
                <h4><?= e($alert['title']) ?></h4>
                <p><?= e($alert['text']) ?></p>
              </div>
              <?php if ($alert['action']): ?>
                <a href="<?= e($alert['action']['href']) ?>" class="btn-action <?= e($alert['type']) ?>"><?= e($alert['action']['label']) ?></a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="section fade-in-up delay-4">
        <div class="section-title"><h2>บริการระบบ</h2></div>
        <div class="menu-grid">
          <?php foreach ($menu_items as $menu): ?>
            <a href="<?= e($menu['href']) ?>" class="menu-item">
              <div class="icon-box"><i class="fas <?= e($menu['icon']) ?>"></i></div>
              <span class="menu-text"><?= e($menu['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="fade-in-up delay-4">
        <div class="section-title"><h2>ประชาสัมพันธ์</h2></div>
        <div class="news-carousel" id="newsCarousel">
          <?php if ($news_items): ?>
            <?php foreach ($news_items as $news): ?>
              <article class="news-card">
                <?php if ($news['image_url']): ?>
                  <div class="news-media" data-full="<?= e($news['image_url']) ?>">
                    <img src="<?= e($news['image_url']) ?>" alt="ข่าวสาร" loading="lazy">
                    <div class="news-tag">ประกาศ</div>
                  </div>
                <?php else: ?>
                  <div class="news-media news-media--placeholder">
                    <i class="fas fa-bullhorn"></i>
                    <div class="news-tag">ประกาศ</div>
                  </div>
                <?php endif; ?>
                <div class="news-body">
                  <div class="news-date"><i class="far fa-clock"></i> <?= e($news['date']) ?></div>
                  <h4 class="news-title"><?= e($news['title']) ?></h4>
                  <?php
                    // ตรวจหา URL ในเนื้อหาข่าว แล้วแยกออกมาทำเป็นปุ่ม
                    $raw_content = $news['content'];
                    $attached_link = '';
                    if (preg_match('/(https?:\/\/[^\s]+)/i', $raw_content, $matches)) {
                        $attached_link = $matches[1];
                        $raw_content = trim(str_replace($attached_link, '', $raw_content));
                    }
                  ?>
                  <p class="news-desc"><?= e($raw_content) ?></p>
                  <?php if ($attached_link): ?>
                    <?php
                      $is_drive = strpos($attached_link, 'drive.google.com') !== false;
                      $btn_icon = $is_drive ? 'fab fa-google-drive' : 'fas fa-external-link-alt';
                    ?>
                    <a href="<?= e($attached_link) ?>" target="_blank" rel="noopener" class="news-btn-link">
                      <i class="<?= $btn_icon ?>"></i> เปิดดูไฟล์แนบ
                    </a>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="news-empty">ยังไม่มีประกาศในขณะนี้</div>
          <?php endif; ?>
        </div>
        <?php if (count($news_items) > 1): ?>
          <div class="carousel-dots" id="newsDots"></div>
        <?php endif; ?>
      </section>
    </main>
  </div>


<div id="imageModal" class="image-modal">
    <button type="button" class="modal-close-btn" aria-label="ปิด">&times;</button>
    <img id="modalImage" class="modal-content-img" src="" alt="Zoom">
  </div>

  <nav class="bottom-nav" aria-label="เมนูหลัก">
    <?php foreach ($nav_items as $nav): ?>
      <a href="<?= e($nav['href']) ?>" class="nav-link<?= $nav['active'] ? ' active' : '' ?>">
        <i class="fas <?= e($nav['icon']) ?>"></i>
        <span><?= e($nav['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <script>
  (() => {
    'use strict';
    const $ = (id) => document.getElementById(id);

    /* ---------- 1. Page loader ---------- */
    function initLoader() {
      window.addEventListener('load', () => {
        const loader = $('page-loader');
        if (loader) setTimeout(() => loader.classList.add('hidden'), 300);
      });
    }

    /* ---------- 2. Image modal ---------- */
    function initImageModal() {
      const modal = $('imageModal');
      const img = $('modalImage');
      if (!modal || !img) return;

      document.addEventListener('click', (ev) => {
        const media = ev.target.closest('.news-media[data-full]');
        if (media) {
          img.src = media.dataset.full;
          modal.classList.add('show');
        }
      });
      modal.addEventListener('click', () => modal.classList.remove('show'));
    }

    /* ---------- 3. News carousel dots ---------- */
    function initNewsCarousel() {
      const carousel = $('newsCarousel');
      const dotsBox = $('newsDots');
      if (!carousel || !dotsBox) return;

      const cards = carousel.querySelectorAll('.news-card');
      if (cards.length < 2) return;

      cards.forEach((card, i) => {
        const dot = document.createElement('div');
        dot.className = 'dot' + (i === 0 ? ' active' : '');
        dot.addEventListener('click', () => {
          carousel.scrollTo({
            left: card.offsetLeft - carousel.offsetLeft - (carousel.clientWidth - card.offsetWidth) / 2,
            behavior: 'smooth'
          });
        });
        dotsBox.appendChild(dot);
      });

      const dots = dotsBox.querySelectorAll('.dot');
      let ticking = false;
      carousel.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
          const center = carousel.scrollLeft + carousel.clientWidth / 2;
          let nearest = 0, minDiff = Infinity;
          cards.forEach((card, i) => {
            const diff = Math.abs(card.offsetLeft + card.offsetWidth / 2 - center);
            if (diff < minDiff) { minDiff = diff; nearest = i; }
          });
          dots.forEach((dot, i) => dot.classList.toggle('active', i === nearest));
          ticking = false;
        });
      });
    }

    /* ---------- 4. Push notification ---------- */
    function initPush() {
      if (!('serviceWorker' in navigator && 'PushManager' in window)) return;

      const publicKey = <?= json_encode(VAPID_PUBLIC_KEY) ?>;

      const urlB64ToUint8Array = (base64String) => {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        return Uint8Array.from(raw, (c) => c.charCodeAt(0));
      };

      const sendSubscriptionToServer = (subscription) =>
        fetch('save_subscription.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(subscription)
        })
          .then((res) => res.text())
          .then((data) => console.log('บันทึกแจ้งเตือน:', data))
          .catch((err) => console.error('เกิดข้อผิดพลาด:', err));

      window.addEventListener('load', async () => {
        try {
          const permission = await Notification.requestPermission();
          if (permission !== 'granted') return;

          const reg = await navigator.serviceWorker.register('sw.js');
          let subscription = await reg.pushManager.getSubscription();
          if (!subscription) {
            subscription = await reg.pushManager.subscribe({
              userVisibleOnly: true,
              applicationServerKey: urlB64ToUint8Array(publicKey)
            });
          }
          sendSubscriptionToServer(subscription);
        } catch (err) {
          console.error('Push Registration Error:', err);
        }
      });
    }

    /* ---------- 5. Console security warning ---------- */
    function showConsoleWarning() {
      const stopArt = `
   ███████╗████████╗ ██████╗ ██████╗ ██╗
   ██╔════╝╚══██╔══╝██╔═══██╗██╔══██╗██║
   ███████╗   ██║   ██║   ██║██████╔╝██║
   ╚════██║   ██║   ██║   ██║██╔═══╝ ╚═╝
   ███████║   ██║   ╚██████╔╝██║     ██╗
   ╚══════╝   ╚═╝    ╚═════╝ ╚═╝     ╚═╝
  `;
      console.log('%c' + stopArt, 'color: red; font-weight: bold; font-family: monospace;');
      console.log('%cคำเตือนด้านความปลอดภัย!', 'color: red; font-family: sans-serif; font-size: 30px; font-weight: bold;');
      console.log(
        "%cนี่คือพื้นที่สำหรับนักพัฒนาซอฟต์แวร์ หากมีคนบอกให้คุณคัดลอกและวางโค้ดที่นี่เพื่อเปิดใช้งานฟีเจอร์พิเศษ หรือ 'แฮ็ก' ระบบ นั่นคือการหลอกลวง (Scam) และอาจทำให้พวกเขาเข้าถึงบัญชีหรือขโมยข้อมูลของคุณได้",
        'font-size: 16px; font-family: sans-serif; color: #333; line-height: 1.5;'
      );
    }

    /* ---------- Init ---------- */
    initLoader();
    initPush();
    document.addEventListener('DOMContentLoaded', () => {
      initImageModal();
      initNewsCarousel();
    });
    showConsoleWarning();
  })();
  </script>
</body>
</html>
