<?php
/**
 * commanders.php — แผนผังผู้บังคับบัญชา รร.ชท.
 *
 * โครงสร้างไฟล์ (เหมือน main.php)
 *   1. Bootstrap : session, header, auth
 *   2. Config    : ค่าคงที่
 *   3. Helpers   : ฟังก์ชันช่วย
 *   4. Data      : รายชื่อผู้บังคับบัญชา (แก้ไขที่นี่ที่เดียว)
 *   5. View      : HTML + CSS + JS
 */

/* ========== 1. Bootstrap ========== */
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Cache-Control: no-cache, no-store, must-revalidate");

const REQUIRE_LOGIN = true; // ตั้งเป็น false ถ้าต้องการเปิดดูโดยไม่ต้องล็อกอิน
if (REQUIRE_LOGIN && empty($_SESSION['student_code'])) {
    header('Location: login.php');
    exit();
}

/* ========== 2. Config ========== */
const APP_LOGO_URL = 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg';
const DEFAULT_AVATAR_URL = 'https://taothetutor.wordpress.com/wp-content/uploads/2026/10/e0b894e0b8b5e0b984e0b88be0b899e0b98ce0b897e0b8b5e0b988e0b8a2e0b8b1e0b887e0b984e0b8a1e0b988e0b984e0b894e0b989e0b895e0b8b1e0b989e0b887e0b88ae0b8b7e0b988e0b8ad.png'; // รูปที่ใช้แทนเมื่อยังไม่มีรูปของคนนั้น
const PHOTO_DIR    = 'uploads/commanders/'; // ตั้งชื่อรูปเป็น "ชื่อ นามสกุล.jpg" แล้วระบบจับคู่ให้อัตโนมัติ

// ตารางยศย่อ -> ยศเต็ม (เพิ่ม/แก้ได้) ถ้าไม่พบในตารางจะแสดงยศย่อตามเดิม
// หมายเหตุ: ยศที่ย่อซ้ำข้ามเหล่าทัพ (น.อ./น.ท./น.ต., จ.อ./จ.ท./จ.ต.) ตั้งไว้ตามกองทัพเรือ
//           ถ้าเป็นกองทัพอากาศ ให้แก้เป็น นาวาอากาศเอก / จ่าอากาศเอก ฯลฯ
const RANK_FULL = [
    // ทหารบก
    'พล.อ.' => 'พลเอก', 'พล.ท.' => 'พลโท', 'พล.ต.' => 'พลตรี',
    'พ.อ.' => 'พันเอก', 'พ.ท.' => 'พันโท', 'พ.ต.' => 'พันตรี',
    'ร.อ.' => 'ร้อยเอก', 'ร.ท.' => 'ร้อยโท', 'ร.ต.' => 'ร้อยตรี',
    'จ.ส.อ.' => 'จ่าสิบเอก', 'จ.ส.ท.' => 'จ่าสิบโท', 'จ.ส.ต.' => 'จ่าสิบตรี',
    'ส.อ.' => 'สิบเอก', 'ส.ท.' => 'สิบโท', 'ส.ต.' => 'สิบตรี',
    // ทหารเรือ
    'พล.ร.อ.' => 'พลเรือเอก', 'พล.ร.ท.' => 'พลเรือโท', 'พล.ร.ต.' => 'พลเรือตรี',
    'น.อ.' => 'นาวาเอก', 'น.ท.' => 'นาวาโท', 'น.ต.' => 'นาวาตรี',
    'พ.จ.อ.' => 'พันจ่าเอก', 'พ.จ.ท.' => 'พันจ่าโท', 'พ.จ.ต.' => 'พันจ่าตรี',
    'จ.อ.' => 'จ่าเอก', 'จ.ท.' => 'จ่าโท', 'จ.ต.' => 'จ่าตรี',
    // นักเรียน
    'นชท.' => 'นักเรียนช่างฝีมือทหาร',
];

/* ========== 3. Helpers ========== */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** สีตามชั้นยศ: พล.=ทอง, พ.อ./น.อ.=ม่วง, พ.ท./น.ท.=ฟ้า, ร้อย-ตรี=เขียว, ประทวน=ส้ม, นชท.=แดง */
function rank_tier(string $rank): string
{
    $groups = [
        'gold'   => ['พล.'],
        'purple' => ['พ.อ.', 'น.อ.'],
        'blue'   => ['พ.ท.', 'น.ท.'],
        'green'  => ['พ.ต.', 'น.ต.', 'ร.อ.', 'ร.ท.', 'ร.ต.'],
        'red'    => ['นชท.'],
    ];
    foreach ($groups as $tier => $prefixes) {
        foreach ($prefixes as $prefix) {
            if (strpos($rank, $prefix) === 0) return $tier;
        }
    }
    return 'orange'; // นายทหารประทวน (พ.จ.อ., จ.ส.อ., จ.อ. ...)
}

/** ยศเต็มจากยศย่อ (รองรับต่อท้าย "หญิง") ถ้าไม่พบในตารางจะคืนยศย่อเดิม */
function rank_full(string $rank): string
{
    $map  = RANK_FULL;
    $base = preg_replace('/หญิง$/u', '', $rank);
    return isset($map[$base]) ? $map[$base] . ($base !== $rank ? 'หญิง' : '') : $rank;
}

/** ป้ายยศ: ใส่ทั้งแบบเต็มและแบบย่อ แล้วใช้ CSS สลับตามที่ผู้ใช้เลือก */
function rank_html(string $rank): string
{
    return '<span class="r-full">' . e(rank_full($rank)) . '</span><span class="r-abbr">' . e($rank) . '</span>';
}

/** หารูปจาก "ชื่อ นามสกุล.jpg|png|webp" ใน PHOTO_DIR (คืนชื่อไฟล์ หรือ '' ถ้าไม่มี) */
function find_photo(string $name): string
{
    foreach (['jpg', 'jpeg', 'png', 'webp', 'JPG', 'JPEG', 'PNG'] as $ext) {
        if (is_file(__DIR__ . '/' . PHOTO_DIR . $name . '.' . $ext)) return $name . '.' . $ext;
    }
    return '';
}

function p(string $rank, string $name, string $role, string $photo = ''): array
{
    $photo = $photo !== '' ? $photo : find_photo($name);
    return compact('rank', 'name', 'role', 'photo');
}

function photo_url(array $x): string
{
    return $x['photo'] !== '' ? PHOTO_DIR . rawurlencode($x['photo']) : DEFAULT_AVATAR_URL;
}

function avatar_html(array $x): string
{
    $fb = $x['photo'] === '' ? ' data-fb="1"' : ''; // data-fb = เป็นรูปเริ่มต้นอยู่แล้ว
    return '<img src="' . e(photo_url($x)) . '" alt="" loading="lazy"' . $fb . ' onerror="imgFallback(this)">';
}

function person_attrs(array $x, string $group): string
{
    return 'data-rank="' . e($x['rank']) . '" data-full="' . e(rank_full($x['rank'])) . '" data-name="' . e($x['name']) . '" data-role="' . e($x['role'])
         . '" data-group="' . e($group) . '" data-photo="' . e(photo_url($x)) . '" data-tier="' . rank_tier($x['rank'])
         . '" data-s="' . e(strtolower($x['rank'] . ' ' . rank_full($x['rank']) . ' ' . $x['name'] . ' ' . $x['role'] . ' ' . $group)) . '"';
}

function render_person(array $x, string $group): void
{
    ?>
    <button type="button" class="person t-<?= rank_tier($x['rank']) ?> reveal" <?= person_attrs($x, $group) ?>>
      <span class="avatar"><?= avatar_html($x) ?></span>
      <span class="p-text"><small><?= rank_html($x['rank']) ?></small><b><?= e($x['name']) ?></b><em><?= e($x['role']) ?></em></span>
    </button>
    <?php
}

/* ========== 4. Data ========== */
// p(ยศ, ชื่อ-สกุล, ตำแหน่ง [, ชื่อไฟล์รูป])
// รูปจับคู่อัตโนมัติจากชื่อ-สกุล เช่น uploads/commanders/คมศักดิ์ เจียมวัฒนาเลิศ.jpg
$top = p('พล.ต.', 'คมศักดิ์ เจียมวัฒนาเลิศ', 'ผบ.รร.ชท.');

// [หัวข้อชั้น, จำนวนคอลัมน์, รายชื่อ]  เรียงจากบนลงล่างตามสายการบังคับบัญชา
$tiers = [
    ['รองผู้บัญชาการ', 2, [
        p('พ.อ.', 'จิรานุวัฒน์ ศักดิ์เสือ', 'รอง.ผบ.รร.ชท.'),
        p('พ.อ.', 'จีระเดช มั่งไธสง', 'รอง.ผบ.รร.ชท.'),
    ]],
    ['เสนาธิการ', 1, [
        p('พ.อ.', 'ณัฐวุฒิ ดวงจรัศ', 'เสธ.รร.ชท.'),
    ]],
    ['รองเสนาธิการ', 1, [
        p('น.อ.หญิง', 'วราภร บุญเขียน', 'รอง เสธ.บก.รร.ชท.'),
    ]],
    ['หัวหน้าหน่วยงาน', 2, [
        p('พ.อ.หญิง', 'นภวรรณ แผ้วสกุล', 'ผอ.กฝศ.รร.ชท.'),
        p('พ.อ.', 'อรรณพ ศรีวัฒนาวานิช', 'ผอ.กองแพทย์ รร.ชท.'),
        p('พ.อ.', 'ชัยทัต บุญเนตร์', 'หน.กสน.รร.ชท.'),
        p('พ.อ.', 'พจน์ หลอดทอง', 'หน.กปศ.รร.ชท.'),
    ]],
    ['ผู้บังคับการ กรม นร.', 1, [
        p('พ.อ.', 'พิตติพัฒน์ ไขรัศมี', 'ผบ.กรม.นร.รร.ชท.'),
    ]],
    ['รองผู้บังคับการ กรม นร.', 1, [
        p('พ.อ.', 'ประสพชัย ศิลาอ่อน', 'รอง.ผบ.กรม.นร.รร.ชท.'),
    ]],
    ['เสนาธิการ กรม นร.', 1, [
        p('พ.อ.', 'สายยันต์ ชาติชนะ', 'เสธ.กรม.นร.รร.ชท.'),
    ]],
    ['ผู้บังคับกองพัน นร.', 1, [
        p('น.ท.', 'สุรชาติ นรสิงห์', 'ผบ.พัน. นร.1 กรม นร.รร.ชท.'),
        p('พ.ท.', 'ขจร ยิ้มละมัย', 'ผบ.พัน. นร.2 กรม นร.รร.ชท.'),
        p('พ.ท.', 'สิริโชติ บริบูรณ์ทรัพย์', 'ผบ.พัน. นร.3 กรม นร.รร.ชท.'),
    ]],
    ['ผู้บังคับหมวด พัน นร.1', 2, [
        p('ร.ท.', 'ปฏิพัฒ ปิยะธนากร', 'ผบ.มว. กรม นร.พัน นร.1'),
        p('ร.ท.', 'ณัฏฐ์ สิริภิญโญชัยกุล', 'ผบ.มว. กรม นร.พัน นร.1'),
    ]],
    ['ข้าราชการ พัน นร.1', 2, [
        p('พ.จ.อ.', 'วรวุฒิ โสมเกษตริน', 'จ่ากองพัน กรม นร.พัน นร.1'),
        p('จ.ส.อ.', 'จินตนะ นุชตะมา', 'จ่ากองพัน กรม นร.พัน นร.1'),
        p('จ.อ.', 'เดชธนา มั่นคง', 'เสมียนกองพัน กรม นร.พัน นร.1'),
        p('พ.จ.อ.', 'เบญญพล ปลอดดี', 'จ่ากองร้อย กรม นร.พัน นร.1'),
    ]],
    ['นักเรียนบังคับบัญชา พัน นร.1', 2, [
        p('นชท.', 'ปฏิพัทธ์ นาหนองขาม', 'หัวหน้าพัน นร.1'),
        p('นชท.', 'ธาวิน อาชวโรจน์', 'หัวหน้าครูฝึก'),
    ]],
    ['หัวหน้าร้อย พัน นร.1', 2, [
        p('นชท.', 'ณัฐพล บุญฤทธิ์', 'หัวหน้าร้อย 1'),
        p('นชท.', 'วริทธิ์ธร ใสใหม่', 'หัวหน้าร้อย 2'),
    ]],
    ['หัวหน้าหมวด ร้อย 1', 3, [
        p('นชท.', 'กฤศกร ศิริงาม', 'หัวหน้าหมวด 1 ร้อย 1'),
        p('นชท.', 'ชานนท์ ไชยเพชร', 'หัวหน้าหมวด 2 ร้อย 1'),
        p('นชท.', 'สรวิชญ์ อิสลาม', 'หัวหน้าหมวด 3 ร้อย 1'),
    ]],
    ['หัวหน้าหมวด ร้อย 2', 3, [
        p('นชท.', 'ธนพล นิ่มสุวรรณ์', 'หัวหน้าหมวด 1 ร้อย 2'),
        p('นชท.', 'นวภัทร อุดมบัว', 'หัวหน้าหมวด 2 ร้อย 2'),
        p('นชท.', 'นพรัตน์ กันหะบุตร', 'หัวหน้าหมวด 3 ร้อย 2'),
    ]],
];

$total = 1;
foreach ($tiers as $t) $total += count($t[2]);

$nav_items = [
    ['href' => 'main.php',       'icon' => 'fa-home',    'label' => 'หน้าหลัก',       'active' => false],
    ['href' => 'commanders.php', 'icon' => 'fa-sitemap', 'label' => 'ผู้บังคับบัญชา', 'active' => true],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>แผนผังผู้บังคับบัญชา - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <script>
    // รูปสำรอง: รูปของคนนั้นโหลดไม่ได้ -> ใช้รูปเริ่มต้น -> ถ้ายังโหลดไม่ได้อีก ใช้ไอคอน
    const DEFAULT_AVATAR = <?= json_encode(DEFAULT_AVATAR_URL, JSON_UNESCAPED_SLASHES) ?>;
    function imgFallback(img) {
      if (img.dataset.fb) {
        img.replaceWith(Object.assign(document.createElement('i'), { className: 'fas fa-user-shield' }));
        return;
      }
      img.dataset.fb = '1';
      img.src = DEFAULT_AVATAR;
    }
  </script>

  <style>
    /* ===== Tokens ===== */
    :root {
      --primary: #5a42a0; --primary-light: #7c5dfa; --bg-color: #f6f8fc; --card-bg: #fff;
      --text-dark: #0f172a; --text-gray: #64748b; --text-muted: #94a3b8;
      --gold: #f59e0b; --blue: #0284c7; --green: #059669;
      --radius: 20px; --radius-sm: 16px; --shadow-card: 0 4px 15px rgba(0,0,0,0.03); --gutter: 20px;
    }
    .t-gold { --c: var(--gold); } .t-purple { --c: var(--primary); }
    .t-blue { --c: var(--blue); } .t-green  { --c: var(--green); } .t-orange { --c: #ea580c; } .t-red { --c: #e11d48; }

    /* ===== Base ===== */
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background: #e2e8f0; margin: 0; display: flex; justify-content: center; }
    .app-container {
      width: 100%; max-width: 430px; min-height: 100vh; position: relative; overflow: hidden;
      background: var(--bg-color); padding-bottom: 100px; box-shadow: 0 0 30px rgba(0,0,0,0.1);
    }

    /* ===== Animation ===== */
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s ease forwards; }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
    @keyframes rise { from { opacity: 0; translate: 0 24px; } to { opacity: 1; translate: 0 0; } }
    .reveal { opacity: 0; }
    .reveal.in { animation: rise 0.6s cubic-bezier(.2,.8,.3,1) forwards; animation-delay: var(--d, 0s); }
    @keyframes grad  { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
    @keyframes shine { 0% { left: -60%; } 55%,100% { left: 130%; } }
    @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-14px); } }
    @keyframes ring  { 0% { box-shadow: 0 0 0 0 rgba(251,191,36,0.6); } 100% { box-shadow: 0 0 0 24px rgba(251,191,36,0); } }
    @keyframes twinkle { 50% { transform: scale(1.4); opacity: 0.6; } }
    @keyframes flow { 0% { top: -4px; opacity: 0; } 20%,80% { opacity: 1; } 100% { top: 26px; opacity: 0; } }

    /* ===== Header ===== */
    .top-header { display: flex; align-items: center; justify-content: space-between; padding: var(--gutter); }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .school-logo { width: 45px; height: 45px; object-fit: contain; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    .school-name h1 { font-size: 1.05rem; font-weight: 700; color: var(--text-dark); margin: 0 0 2px; }
    .school-name p { font-size: 0.75rem; color: var(--text-gray); margin: 0; font-weight: 500; }
    .noti-btn {
      width: 42px; height: 42px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center;
      color: var(--text-gray); font-size: 1.1rem; text-decoration: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    /* ===== Search ===== */
    .search-row { display: flex; align-items: center; gap: 10px; margin: 0 var(--gutter) 22px; }
    .search {
      flex: 1; display: flex; align-items: center; gap: 10px; padding: 0 16px; height: 42px;
      border-radius: 21px; background: #fff; box-shadow: var(--shadow-card); color: var(--text-muted);
    }
    .search input { flex: 1; min-width: 0; border: 0; outline: 0; background: none; font: 400 0.85rem 'Prompt', sans-serif; color: var(--text-dark); }
    .count { font-size: 0.75rem; font-weight: 600; color: var(--primary); background: #eef2ff; padding: 8px 14px; border-radius: 20px; white-space: nowrap; }

    /* ===== Rank format toggle ===== */
    .r-abbr { display: none; }
    body[data-rankmode="short"] .r-full { display: none; }
    body[data-rankmode="short"] .r-abbr { display: inline; }
    .seg { display: flex; width: max-content; margin: -10px var(--gutter) 16px auto; padding: 3px; border-radius: 20px; background: #fff; box-shadow: var(--shadow-card); }
    .seg button { border: 0; background: none; padding: 5px 14px; border-radius: 17px; font: 600 0.72rem 'Prompt', sans-serif; color: var(--text-gray); cursor: pointer; transition: 0.2s; }
    .seg button.on { background: var(--primary); color: #fff; }

    /* ===== Hero (ผบ.รร.ชท.) ===== */
    .tier { margin: 0 var(--gutter); }
    .hero {
      position: relative; overflow: hidden; width: 100%; display: flex; flex-direction: column; align-items: center;
      padding: 24px 20px 22px; border: 0; border-radius: var(--radius); color: #fff; font: inherit; cursor: pointer; text-align: center;
      background: linear-gradient(120deg, #5a42a0, #6366f1, #7c5dfa, #4f46e5, #5a42a0); background-size: 300% 300%;
      box-shadow: 0 15px 30px rgba(79,70,229,0.25); animation: grad 10s ease-in-out infinite;
      transition: transform 0.2s, opacity 0.3s;
    }
    .hero:active { transform: scale(0.98); }
    .hero::after {
      content: ''; position: absolute; top: 0; left: -60%; width: 40%; height: 100%; pointer-events: none;
      background: linear-gradient(100deg, transparent, rgba(255,255,255,0.28), transparent); transform: skewX(-20deg); animation: shine 4.5s infinite;
    }
    .orb { position: absolute; border-radius: 50%; background: rgba(255,255,255,0.12); animation: float 7s ease-in-out infinite; }
    .o1 { width: 90px; height: 90px; top: -25px; left: -20px; } .o2 { width: 60px; height: 60px; bottom: -10px; right: 18px; animation-delay: 2s; }
    .stars { display: flex; gap: 6px; margin-bottom: 12px; color: #fbbf24; font-size: 0.9rem; z-index: 1; }
    .stars i { animation: twinkle 1.6s ease-in-out infinite; } .stars i:nth-child(2) { animation-delay: 0.4s; }
    .h-wrap { z-index: 1; border-radius: 50%; animation: ring 2s infinite; }
    .h-avatar {
      width: 84px; height: 84px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center;
      border: 3px solid #fbbf24; background: rgba(255,255,255,0.18); font-size: 2.3rem;
    }
    .h-avatar img { width: 100%; height: 100%; object-fit: cover; object-position: center 20%; }
    .hero small { z-index: 1; margin-top: 12px; font-size: 0.8rem; font-weight: 600; color: #fde68a; }
    .hero h2 { z-index: 1; margin: 2px 0 8px; font-size: 1.2rem; font-weight: 600; line-height: 1.3; }
    .badge-item {
      z-index: 1; padding: 3px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 500;
      background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); backdrop-filter: blur(4px);
    }

    /* ===== Connector ===== */
    .link { position: relative; width: 2px; height: 30px; margin: 0 auto; background: linear-gradient(#c7d2fe, #a5b4fc); }
    .link::after {
      content: ''; position: absolute; left: -3px; width: 8px; height: 8px; border-radius: 50%;
      background: var(--primary-light); box-shadow: 0 0 8px var(--primary-light); animation: flow 1.8s linear infinite;
    }

    /* ===== Tiers & cards ===== */
    .tier-label {
      display: table; margin: 0 auto 12px; padding: 3px 14px; border-radius: 20px;
      background: #eef2ff; color: var(--primary); font-size: 0.72rem; font-weight: 600;
    }
    .grid { display: grid; gap: 12px; } .c1 { grid-template-columns: 1fr; } .c2 { grid-template-columns: 1fr 1fr; }
    .c3 { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .person {
      position: relative; overflow: hidden; width: 100%; display: flex; align-items: center; gap: 10px;
      padding: 12px 12px 12px 17px; border: 0; border-radius: var(--radius-sm); background: var(--card-bg);
      box-shadow: var(--shadow-card); font: inherit; text-align: left; cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s, opacity 0.3s;
    }
    .person::before { content: ''; position: absolute; left: 0; top: 0; width: 5px; height: 100%; background: var(--c); }
    .person:hover { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(90,66,160,0.15); }
    .person:active { transform: scale(0.96); }
    .avatar {
      flex: 0 0 42px; height: 42px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center;
      background: color-mix(in srgb, var(--c) 14%, #fff); color: var(--c); font-size: 1.1rem;
    }
    .c2 .avatar { flex-basis: 34px; height: 34px; font-size: 0.9rem; }
    .avatar img { width: 100%; height: 100%; object-fit: cover; object-position: center 20%; }
    .p-text { display: flex; flex-direction: column; min-width: 0; }
    .p-text small { font-size: 0.68rem; font-weight: 600; color: var(--c); }
    .p-text b { font-size: 0.82rem; font-weight: 600; color: var(--text-dark); line-height: 1.3; }
    .p-text em { font-style: normal; font-size: 0.68rem; color: var(--text-gray); margin-top: 2px; line-height: 1.3; }
    .c3 .person { flex-direction: column; gap: 6px; padding: 14px 6px 10px; text-align: center; }
    .c3 .person::before { width: 100%; height: 4px; }
    .c3 .avatar { flex: 0 0 44px; width: 44px; height: 44px; }
    .c3 .p-text { align-items: center; } .c3 .p-text b { font-size: 0.76rem; }
    [data-name].dim { opacity: 0.2; } .person.dim { transform: scale(0.97); }
    .person.hit { box-shadow: 0 0 0 2px var(--primary-light), 0 8px 20px rgba(124,93,250,0.2); }

    /* ===== Detail sheet ===== */
    .sheet {
      position: fixed; inset: 0; z-index: 2000; display: flex; align-items: flex-end; justify-content: center;
      background: rgba(15,23,42,0.55); backdrop-filter: blur(3px); opacity: 0; visibility: hidden; transition: 0.3s;
    }
    .sheet.show { opacity: 1; visibility: visible; }
    .sheet-card {
      width: 100%; max-width: 430px; padding: 12px 24px 28px; border-radius: 28px 28px 0 0; background: #fff; text-align: center;
      transform: translateY(100%); transition: transform 0.35s cubic-bezier(.2,.9,.3,1.1);
    }
    .sheet.show .sheet-card { transform: none; }
    .grab { width: 42px; height: 4px; margin: 0 auto 18px; border-radius: 4px; background: #cbd5e1; }
    .s-avatar {
      width: 76px; height: 76px; margin: 0 auto 10px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center;
      font-size: 2rem; border: 3px solid var(--c); color: var(--c); background: color-mix(in srgb, var(--c) 14%, #fff);
    }
    .s-avatar img { width: 100%; height: 100%; object-fit: cover; object-position: center 20%; }
    .sheet small { font-weight: 600; color: var(--c); }
    .sheet h3 { margin: 2px 0 4px; font-size: 1.15rem; color: var(--text-dark); }
    .sheet p { margin: 0 0 12px; font-size: 0.85rem; color: var(--text-gray); }
    .sheet-close { width: 100%; margin-top: 16px; padding: 12px; border: 0; border-radius: 14px; background: var(--primary); color: #fff; font: 600 0.9rem 'Prompt', sans-serif; cursor: pointer; }

    /* ===== Bottom nav ===== */
    .bottom-nav {
      position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); z-index: 1000; width: 100%; max-width: 430px; height: 75px;
      padding-bottom: env(safe-area-inset-bottom, 0); display: flex; justify-content: space-around; align-items: center;
      background: rgba(255,255,255,0.9); border-top: 1px solid rgba(0,0,0,0.05); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    }
    .nav-link { width: 25%; display: flex; flex-direction: column; align-items: center; gap: 5px; color: var(--text-muted); font-size: 0.7rem; font-weight: 600; text-decoration: none; }
    .nav-link.active { color: var(--primary); } .nav-link i { font-size: 1.4rem; }

    @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } .reveal, .fade-in-up { opacity: 1 !important; transform: none !important; } }
  </style>
</head>
<body>
  <div class="app-container">

    <!-- Header -->
    <header class="top-header fade-in-up delay-1">
      <div class="header-left">
        <img src="<?= e(APP_LOGO_URL) ?>" alt="Logo" class="school-logo">
        <div class="school-name">
          <h1>แผนผังผู้บังคับบัญชา</h1>
          <p>โรงเรียนช่างฝีมือทหาร</p>
        </div>
      </div>
      <a href="main.php" class="noti-btn" aria-label="กลับหน้าหลัก"><i class="fas fa-home"></i></a>
    </header>

    <main>
      <!-- Search -->
      <div class="search-row fade-in-up delay-2">
        <label class="search"><i class="fas fa-search"></i><input id="q" type="search" placeholder="ค้นหาชื่อ / ตำแหน่ง" autocomplete="off"></label>
        <span class="count"><b data-count="<?= (int)$total ?>">0</b> นาย</span>
      </div>

      <div class="seg fade-in-up delay-2" role="group" aria-label="รูปแบบยศ">
        <button type="button" class="on" data-rank-mode="full">ยศเต็ม</button>
        <button type="button" data-rank-mode="short">ยศย่อ</button>
      </div>

      <!-- ผบ.รร.ชท. -->
      <section class="tier reveal">
        <button type="button" class="hero t-gold" <?= person_attrs($top, 'ผู้บัญชาการ') ?>>
          <span class="orb o1"></span><span class="orb o2"></span>
          <div class="h-wrap"><div class="h-avatar"><?= avatar_html($top) ?></div></div>
          <small><?= rank_html($top['rank']) ?></small>
          <h2><?= e($top['name']) ?></h2>
          <div class="badge-item"><?= e($top['role']) ?></div>
        </button>
      </section>

      <!-- Tiers -->
      <?php foreach ($tiers as [$label, $cols, $people]): ?>
        <div class="link reveal"></div>
        <section class="tier">
          <div class="tier-label reveal"><?= e($label) ?></div>
          <div class="grid c<?= (int)$cols ?>">
            <?php foreach ($people as $x) render_person($x, $label); ?>
          </div>
        </section>
      <?php endforeach; ?>
    </main>
  </div>

  <!-- Detail sheet -->
  <div id="sheet" class="sheet">
    <div class="sheet-card" id="sheetCard">
      <div class="grab"></div>
      <div class="s-avatar" id="sAvatar"></div>
      <small id="sRank"></small>
      <h3 id="sName"></h3>
      <p id="sRole"></p>
      <div class="tier-label" id="sGroup"></div>
      <button type="button" class="sheet-close">ปิด</button>
    </div>
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
    const cards = [...document.querySelectorAll('[data-name]')];

    /* ---------- 1. Reveal on scroll (เรียงทีละใบ) ---------- */
    function initReveal() {
      document.querySelectorAll('.grid').forEach((g) =>
        [...g.children].forEach((c, i) => c.style.setProperty('--d', i * 0.1 + 's')));
      const items = document.querySelectorAll('.reveal');
      if (!('IntersectionObserver' in window)) { items.forEach((el) => el.classList.add('in')); return; }
      const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
      }, { threshold: 0.15 });
      items.forEach((el) => io.observe(el));
    }

    /* ---------- 2. Count-up ---------- */
    function initCount() {
      document.querySelectorAll('[data-count]').forEach((el) => {
        const to = +el.dataset.count; let n = 0;
        const t = setInterval(() => { el.textContent = ++n; if (n >= to) clearInterval(t); }, 70);
      });
    }

    /* ---------- 3. Search ---------- */
    function initSearch() {
      const q = $('q');
      if (!q) return;
      q.addEventListener('input', () => {
        const k = q.value.trim().toLowerCase();
        cards.forEach((c) => {
          const hit = !k || c.dataset.s.includes(k);
          c.classList.toggle('dim', !hit);
          c.classList.toggle('hit', !!k && hit);
        });
      });
    }

    /* ---------- 4. Detail sheet ---------- */
    function initSheet() {
      const sheet = $('sheet');
      const open = (c) => {
        const d = c.dataset, av = $('sAvatar');
        $('sheetCard').className = 'sheet-card t-' + d.tier;
        $('sRank').textContent = d.full && d.full !== d.rank ? d.full + ' (' + d.rank + ')' : d.rank;
        $('sName').textContent = d.name;
        $('sRole').textContent = d.role;
        $('sGroup').textContent = d.group;
        av.replaceChildren();
        const im = new Image(); im.alt = '';
        if (d.photo === DEFAULT_AVATAR) im.dataset.fb = '1';
        im.onerror = () => imgFallback(im);
        im.src = d.photo;
        av.appendChild(im);
        sheet.classList.add('show');
      };
      document.addEventListener('click', (ev) => {
        const c = ev.target.closest('[data-name]');
        if (c) open(c);
      });
      sheet.addEventListener('click', (ev) => {
        if (ev.target === sheet || ev.target.closest('.sheet-close')) sheet.classList.remove('show');
      });
      document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') sheet.classList.remove('show'); });
    }

    /* ---------- 5. ยศเต็ม / ยศย่อ ---------- */
    function initRankMode() {
      const btns = document.querySelectorAll('[data-rank-mode]');
      const apply = (mode) => {
        document.body.dataset.rankmode = mode;
        btns.forEach((b) => b.classList.toggle('on', b.dataset.rankMode === mode));
        try { localStorage.setItem('rankMode', mode); } catch (_) {}
      };
      let saved = 'full';
      try { saved = localStorage.getItem('rankMode') || 'full'; } catch (_) {}
      apply(saved === 'short' ? 'short' : 'full');
      btns.forEach((b) => b.addEventListener('click', () => apply(b.dataset.rankMode)));
    }

    document.addEventListener('DOMContentLoaded', () => {
      initRankMode();
      initReveal();
      initCount();
      initSearch();
      initSheet();
    });
  })();
  </script>
</body>
</html>
