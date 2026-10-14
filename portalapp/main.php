<?php
/**
 * main.php — หน้าหลัก MTTS
 *
 * โครงสร้างไฟล์
 *   1. Bootstrap   : session, header, auth
 *   2. Config      : ค่าคงที่
 *   3. Helpers     : ฟังก์ชันช่วย (DB / แปลงค่า / escape / รูปไอคอนเมนู)
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
const MENU_ICON_DIR      = 'assets/icons/'; // โฟลเดอร์เก็บรูปไอคอนเมนู "บริการระบบ"
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

/**
 * รูปไอคอนของเมนู "บริการระบบ"
 * $img เป็นได้ทั้งชื่อไฟล์ในโฟลเดอร์ MENU_ICON_DIR (เช่น 'duty.png')
 * หรือ URL เต็ม (http/https) ก็ได้
 * เพิ่มเมนูใหม่: ใส่รูปในโฟลเดอร์ แล้วอ้างชื่อไฟล์ที่คีย์ 'img' ใน $menu_items
 */
function resolve_menu_icon(string $img): string
{
    return is_url($img) ? $img : MENU_ICON_DIR . $img;
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

// เมนูบริการระบบ: 'img' = ชื่อไฟล์รูปใน MENU_ICON_DIR (หรือ URL เต็ม)
$menu_items = [
    ['href' => 'duty.php',               'img' => 'duty.png',       'color' => 'bg-purple', 'label' => 'เวรของฉัน'],
    ['href' => 'fill.php',               'img' => 'vote.png',       'color' => 'bg-orange', 'label' => 'โหวตกลับบ้าน'],
    ['href' => 'discipline_history.php', 'img' => 'discipline.png', 'color' => 'bg-blue',   'label' => 'บัตรวินัย'],
    ['href' => 'duty_substitutions.php', 'img' => 'swap.png',       'color' => 'bg-green',  'label' => 'เปลี่ยนเวร'],
    ['href' => 'commanders.php',         'img' => 'commander.png',  'color' => 'bg-green',  'label' => 'รายนามผู้บังคับบัญชา'],
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>หน้าหลัก - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    /* ===== Tokens ===== */
    :root {
      --primary: #5a42a0;
      --primary-light: #7c5dfa;
      --bg-color: #f6f8fc;
      --card-bg: #ffffff;
      --text-dark: #0f172a;
      --text-gray: #64748b;
      --text-muted: #94a3b8;
      --danger: #ef4444;
      --warning: #f59e0b;
      --success: #10b981;
      --radius: 20px;
      --radius-sm: 16px;
      --shadow-card: 0 4px 15px rgba(0,0,0,0.03);
      --gutter: 20px;
    }

    /* ===== Base ===== */
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body {
      font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0;
      display: flex; justify-content: center; user-select: none;
    }
    .app-container {
      width: 100%; max-width: 430px; min-height: 100vh; position: relative;
      background-color: var(--bg-color); padding-bottom: 90px;
      box-shadow: 0 0 30px rgba(0,0,0,0.1);
    }
    .section { margin-bottom: 25px; }
    .section-title { margin: 0 var(--gutter) 15px; }
    .section-title h2 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* ===== Animation ===== */
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s ease forwards; }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes pulse {
      0%   { transform: scale(1);    box-shadow: 0 0 0 0 rgba(79,70,229,0.4); }
      50%  { transform: scale(1.05); box-shadow: 0 0 20px 0 rgba(79,70,229,0); }
      100% { transform: scale(1);    box-shadow: 0 0 0 0 rgba(79,70,229,0); }
    }

    /* ===== Loader ===== */
    #page-loader {
      position: fixed; inset: 0; background: #fff; z-index: 9999;
      display: flex; flex-direction: column; justify-content: center; align-items: center;
      transition: opacity 0.5s ease, visibility 0.5s ease;
    }
    #page-loader.hidden { opacity: 0; visibility: hidden; }
    .loader-logo { width: 80px; height: 80px; border-radius: 15px; animation: pulse 1.5s infinite ease-in-out; }
    .loader-spinner {
      margin-top: 20px; width: 40px; height: 40px; border-radius: 50%;
      border: 4px solid rgba(79,70,229,0.2); border-top-color: var(--primary);
      animation: spin 1s linear infinite;
    }

    /* ===== Header ===== */
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: var(--gutter); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    .school-name h1 { font-size: 1.05rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; font-weight: 500; }
    .noti-btn {
      position: relative; width: 42px; height: 42px; border-radius: 50%; background: #fff;
      display: flex; align-items: center; justify-content: center;
      color: var(--text-gray); font-size: 1.2rem; text-decoration: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    .noti-badge {
      position: absolute; top: 0; right: 0; width: 18px; height: 18px; border-radius: 50%;
      background: var(--danger); color: #fff; font-size: 0.65rem; font-weight: 700; border: 2px solid #fff;
      display: flex; align-items: center; justify-content: center;
    }

    /* ===== Student card ===== */
    .student-card {
      position: relative; overflow: hidden; display: flex; align-items: center; gap: 15px;
      margin: 0 var(--gutter) 25px; padding: 20px; border-radius: var(--radius); color: #fff;
      background: linear-gradient(135deg, var(--primary), #6366f1);
      box-shadow: 0 15px 30px rgba(79,70,229,0.25);
    }

    /* พื้นหลังเคลื่อนไหว: ไล่สีเลื่อน + ลูกบอลแสงลอย + แสงวิ่งผ่านการ์ด */
    .card-bg {
      position: absolute; inset: 0; z-index: 0; pointer-events: none; overflow: hidden;
      background: linear-gradient(120deg, #5a42a0, #6366f1, #7c5dfa, #4f46e5, #5a42a0);
      background-size: 300% 300%;
      animation: cardGradient 10s ease-in-out infinite;
    }
    .card-bg span {
      position: absolute; border-radius: 50%; will-change: transform;
      background: radial-gradient(circle, rgba(255,255,255,0.28), rgba(255,255,255,0) 70%);
    }
    .card-bg span:nth-child(1) { width: 130px; height: 130px; top: -45px; right: -25px; animation: orbFloatA 7s ease-in-out infinite; }
    .card-bg span:nth-child(2) { width: 100px; height: 100px; bottom: -40px; left: 32%;  animation: orbFloatB 9s ease-in-out infinite; }
    .card-bg span:nth-child(3) { width: 70px;  height: 70px;  top: 25%;     left: -20px; animation: orbFloatC 11s ease-in-out infinite; }

    .student-card::after {
      content: ''; position: absolute; inset: 0; z-index: 0; pointer-events: none;
      background: linear-gradient(115deg, transparent 35%, rgba(255,255,255,0.18) 50%, transparent 65%);
      transform: translateX(-120%);
      animation: cardShine 6s ease-in-out infinite;
    }

    @keyframes cardGradient {
      0%, 100% { background-position: 0% 50%; }
      50%      { background-position: 100% 50%; }
    }
    @keyframes orbFloatA {
      0%, 100% { transform: translate(0, 0) scale(1); }
      50%      { transform: translate(-25px, 22px) scale(1.15); }
    }
    @keyframes orbFloatB {
      0%, 100% { transform: translate(0, 0) scale(1); }
      50%      { transform: translate(30px, -18px) scale(0.9); }
    }
    @keyframes orbFloatC {
      0%, 100% { transform: translate(0, 0) scale(1); }
      50%      { transform: translate(22px, 14px) scale(1.2); }
    }
    @keyframes cardShine {
      0%, 55%  { transform: translateX(-120%); }
      100%     { transform: translateX(120%); }
    }
    @media (prefers-reduced-motion: reduce) {
      .card-bg, .card-bg span, .student-card::after { animation: none; }
    }
    .profile-img {
      z-index: 1; width: 70px; height: 70px; border-radius: 50%; object-fit: cover; background: #fff;
      border: 3px solid rgba(255,255,255,0.4);
    }
    .student-info { z-index: 1; flex: 1; }
    .student-info h2 { font-size: 1.15rem; font-weight: 600; margin: 0 0 5px; line-height: 1.2; }
    .student-info p { font-size: 0.85rem; margin: 0 0 8px; opacity: 0.9; }
    .badges { display: flex; flex-wrap: wrap; gap: 6px; }
    .badge-item {
      padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 500;
      background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); backdrop-filter: blur(4px);
    }
    .badge-item.badge-danger { background: var(--danger); border-color: #f87171; }

    /* ===== Alerts ===== */
    .alert-list { display: flex; flex-direction: column; gap: 12px; margin: 0 var(--gutter); }
    .alert-card {
      position: relative; overflow: hidden; display: flex; align-items: center; gap: 15px;
      padding: 16px; border-radius: var(--radius-sm); background: var(--card-bg); box-shadow: var(--shadow-card);
    }
    .alert-card::before { content: ''; position: absolute; left: 0; top: 0; width: 5px; height: 100%; }
    .alert-card.urgent::before  { background: var(--danger); }
    .alert-card.warning::before { background: var(--warning); }
    .alert-card.success::before { background: var(--success); }
    .alert-icon { font-size: 1.4rem; }
    .urgent .alert-icon  { color: var(--danger); }
    .warning .alert-icon { color: var(--warning); }
    .success .alert-icon { color: var(--success); }
    .alert-info { flex: 1; }
    .alert-info h4 { margin: 0 0 3px; font-size: 0.9rem; font-weight: 700; color: var(--text-dark); }
    .alert-info p { margin: 0; font-size: 0.8rem; color: var(--text-gray); }
    .btn-action {
      padding: 6px 14px; border-radius: 10px; font-size: 0.75rem; font-weight: 600;
      text-decoration: none; text-align: center; white-space: nowrap; transition: 0.2s;
    }
    .btn-action.urgent  { background: #fee2e2; color: var(--danger); }
    .btn-action.warning { background: #fef3c7; color: var(--warning); }

    /* ===== Service menu ===== */
    .menu-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 0 var(--gutter); }
    .menu-item {
      display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
      padding: 15px 5px; border-radius: var(--radius-sm); background: var(--card-bg);
      color: var(--text-dark); text-decoration: none; box-shadow: var(--shadow-card); transition: transform 0.2s;
    }
    .menu-item:active { transform: scale(0.95); }
    .icon-box {
      width: 45px; height: 45px; margin-bottom: 8px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
    }
    /* รูปไอคอนเมนู: ปรับขนาดได้ที่ width/height (ถ้าอยากให้รูปเต็มกล่อง ใส่ 100%) */
    .icon-box img {
      width: 32px; height: 32px; display: block; object-fit: contain;
      pointer-events: none; -webkit-user-drag: none;
    }
    .menu-text { font-size: 0.7rem; font-weight: 600; line-height: 1.2; }
    .bg-purple { background: #eef2ff; color: var(--primary); }
    .bg-orange { background: #fff7ed; color: #ea580c; }
    .bg-blue   { background: #f0f9ff; color: #0284c7; }
    .bg-green  { background: #ecfdf5; color: #059669; }

    /* ===== News carousel ===== */
    .news-carousel {
      display: flex; gap: 14px; padding: 10px var(--gutter); overflow-x: auto;
      scroll-snap-type: x mandatory; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; scrollbar-width: none;
    }
    .news-carousel::-webkit-scrollbar { display: none; }
    .news-empty { width: 100%; padding: 20px; text-align: center; color: var(--text-gray); }
    .news-card {
      flex: 0 0 85%; max-width: 350px; display: flex; flex-direction: column; overflow: hidden;
      scroll-snap-align: center; background: #fff; border-radius: var(--radius-sm);
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    .news-media { position: relative; width: 100%; height: 180px; background: #e2e8f0; overflow: hidden; cursor: pointer; }
    .news-media img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
    .news-media:hover img { transform: scale(1.03); }
    .news-media--placeholder {
      height: 120px; background: var(--primary); cursor: default;
      display: flex; align-items: center; justify-content: center;
    }
    .news-media--placeholder i { font-size: 3rem; color: rgba(255,255,255,0.5); }
    .news-tag {
      position: absolute; top: 12px; left: 12px; padding: 4px 12px; border-radius: 20px;
      background: rgba(255,255,255,0.92); color: var(--primary); font-size: 0.7rem; font-weight: 700;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1); backdrop-filter: blur(4px);
    }
    .news-body { display: flex; flex-direction: column; flex: 1; padding: 15px; }
    .news-date { display: flex; align-items: center; gap: 4px; margin-bottom: 6px; font-size: 0.7rem; font-weight: 500; color: var(--text-muted); }
    .news-title, .news-desc {
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .news-title { font-size: 0.95rem; font-weight: 700; margin: 0 0 6px; color: var(--text-dark); line-height: 1.4; }
    .news-desc { font-size: 0.8rem; margin: 0; color: var(--text-gray); line-height: 1.5; }

    .carousel-dots { display: flex; justify-content: center; align-items: center; gap: 6px; margin: 10px 0 20px; }
    .carousel-dots .dot {
      width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1; cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .carousel-dots .dot.active { width: 22px; border-radius: 10px; background: var(--primary); }

    /* ===== Bottom nav ===== */
    .bottom-nav {
      position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); z-index: 1000;
      width: 100%; max-width: 430px; height: 75px; padding-bottom: env(safe-area-inset-bottom, 0);
      display: flex; justify-content: space-around; align-items: center;
      background: rgba(255,255,255,0.9); border-top: 1px solid rgba(0,0,0,0.05);
      backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    }
    .nav-link {
      width: 25%; display: flex; flex-direction: column; align-items: center; gap: 5px;
      color: var(--text-muted); font-size: 0.7rem; font-weight: 600; text-decoration: none;
    }
    .nav-link.active { color: var(--primary); }
    .nav-link i { font-size: 1.4rem; }

    /* ===== Image modal ===== */
    .image-modal {
      display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(0,0,0,0.9);
      justify-content: center; align-items: center; opacity: 0; transition: 0.3s;
    }
    .image-modal.show { display: flex; opacity: 1; }
    .modal-content-img { max-width: 90%; max-height: 80vh; border-radius: 12px; }
    .modal-close-btn {
      position: absolute; top: 20px; right: 20px; width: 40px; height: 40px; border: none; border-radius: 50%;
      background: rgba(255,255,255,0.2); color: #fff; font-size: 1.5rem; cursor: pointer;
    }
      /* ปุ่มเปิดลิงก์/ไฟล์แนบในข่าว */
.news-btn-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding: 8px 14px;
    background-color: #f1f5f9; /* สีเทาอ่อน */
    color: #5a42a0; /* สีม่วงธีมเว็บ */
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s ease;
    border: 1px solid #e2e8f0;
}

.news-btn-link:hover {
    background-color: #e2e8f0;
    color: #473282;
    transform: translateY(-2px);
}

.news-btn-link i.fa-google-drive {
    color: #16a34a; /* สีเขียวของ Drive ให้ดูโดดเด่น */
    font-size: 1rem;
}
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

    <!-- Header -->
    <header class="top-header fade-in-up delay-1">
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

    <main>
      <!-- Student profile -->
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

      <!-- Alerts -->
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

      <!-- Services -->
      <section class="section fade-in-up delay-4">
        <div class="section-title"><h2>บริการระบบ</h2></div>
        <div class="menu-grid">
          <?php foreach ($menu_items as $menu): ?>
            <a href="<?= e($menu['href']) ?>" class="menu-item">
              <div class="icon-box <?= e($menu['color']) ?>">
                <img src="<?= e(resolve_menu_icon($menu['img'])) ?>" alt="" class="menu-img" width="32" height="32" loading="lazy" draggable="false">
              </div>
              <span class="menu-text"><?= e($menu['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- News -->
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
                    // ตรวจหา URL ในเนื้อหาข่าว (ทั้ง http และ https)
                    $raw_content = $news['content'];
                    $attached_link = '';
                    
                    if (preg_match('/(https?:\/\/[^\s]+)/i', $raw_content, $matches)) {
                        $attached_link = $matches[1];
                        // ลบ URL ยึกยือออกจากเนื้อหาข้อความเพื่อความสวยงาม
                        $raw_content = trim(str_replace($attached_link, '', $raw_content));
                    }
                  ?>
                  
                  <p class="news-desc"><?= e($raw_content) ?></p>
                  
                  <!-- หากพบลิงก์ ให้แสดงเป็นปุ่มกด -->
                  <?php if ($attached_link): ?>
                    <?php 
                      // เช็คว่าเป็นลิงก์ Google Drive หรือไม่ เพื่อสลับไอคอนให้เหมาะสม
                      $is_drive = strpos($attached_link, 'drive.google.com') !== false;
                      $btn_icon = $is_drive ? 'fab fa-google-drive' : 'fas fa-external-link-alt';
                    ?>
                    <a href="<?= e($attached_link) ?>" target="_blank" class="news-btn-link">
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

  <!-- Image modal -->
  <div id="imageModal" class="image-modal">
    <button type="button" class="modal-close-btn" aria-label="ปิด">&times;</button>
    <img id="modalImage" class="modal-content-img" src="" alt="Zoom">
  </div>

  <!-- Bottom navigation -->
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
