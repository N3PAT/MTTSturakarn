<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 1. เรียกใช้ไฟล์เชื่อมต่อฐานข้อมูล + ตั้งค่าระบบอัปโหลดรูป
require_once 'db.php';
require_once 'photo_config.php';

// 2. ตรวจสอบการ Login
if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

// โทเคนกัน CSRF สำหรับฟอร์มอัปโหลด
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

try {
    // 3. ดึงข้อมูลพื้นฐานจากตาราง students
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $student_id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $student_id);
    } else {
        $student_code_session = $_SESSION['student_code'];
        $stmt = $conn->prepare("SELECT * FROM students WHERE student_code = ? LIMIT 1");
        $stmt->bind_param("s", $student_code_session);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if (!$student) {
        die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>ไม่พบข้อมูลนักเรียน</div>");
    }

    // 4. ดึงข้อมูลทางกายภาพล่าสุดจากตาราง student_bmi_records
    $current_student_code = $student['student_code'];
    $stmt_bmi = $conn->prepare("SELECT * FROM student_bmi_records WHERE student_code = ? ORDER BY created_at DESC LIMIT 1");
    $stmt_bmi->bind_param("s", $current_student_code);
    $stmt_bmi->execute();
    $result_bmi = $stmt_bmi->get_result();
    $bmi_record = $result_bmi->fetch_assoc();
    $stmt_bmi->close();

    // 5. เตรียมข้อมูลตัวแปรสำหรับแสดงผล
    $image_status = $student['image_status'] ?? '';
    $image_note   = $student['image_note'] ?? '';
    // รูปที่เจ้าหน้าที่ปฏิเสธจะไม่แสดง ให้ใช้รูปเริ่มต้นจนกว่าจะอัปโหลดใหม่
    $image_path   = htmlspecialchars((!empty($student['image']) && $image_status !== 'rejected') ? $student['image'] : 'default_student.png');
    $full_name    = htmlspecialchars(($student['prefix'] ?? '') . ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $student_code = htmlspecialchars($student['student_code'] ?? '-');

    // แก้ไขรูปได้เฉพาะโปรไฟล์ของตัวเอง
    $is_own_profile = hash_equals((string)$student['student_code'], (string)$_SESSION['student_code']);

    // ดึงข้อมูลกายภาพ (ถ้าไม่มีใน bmi_records ให้ดึงจาก students แทน หรือใส่ค่าว่าง)
    $display_age      = htmlspecialchars($bmi_record['age'] ?? $student['age'] ?? '-');
    $display_weight   = htmlspecialchars($bmi_record['weight'] ?? '-');
    $display_height   = htmlspecialchars($bmi_record['height'] ?? '-');
    $display_bmi      = htmlspecialchars($bmi_record['bmi'] ?? '-');
    $display_bmi_cat  = htmlspecialchars($bmi_record['bmi_category'] ?? '-');

} catch (Exception $e) {
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif; color:red;'>
        <h3>เกิดข้อผิดพลาดของระบบ</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>");
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#503896">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>ข้อมูลส่วนตัว - Profile</title>

    <!-- FontAwesome & Google Fonts -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

    <style>
        :root {
            --primary: #503896;
            --primary-dark: #3d2b78;
            --bar: #503896;
            --bg: #f2f3f8;
            --ink: #1c1b29;
            --mute: #6b7280;
            --line: #eef0f5;
            --g: 16px;
            --nav: 64px;
            --st: env(safe-area-inset-top, 0px);
            --sb: env(safe-area-inset-bottom, 0px);
            --shadow: 0 1px 2px rgba(20,20,43,.04), 0 2px 8px rgba(20,20,43,.04);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; }
        html { -webkit-text-size-adjust: 100%; }
        [hidden] { display: none !important; }

        body {
            font-family: 'Prompt', sans-serif;
            background: #d9dce6;
            color: var(--ink);
            display: flex;
            justify-content: center;
            min-height: 100vh;
            min-height: 100dvh;
            -webkit-user-select: none;
            user-select: none;
            overscroll-behavior-y: none;
            touch-action: manipulation;
            -webkit-font-smoothing: antialiased;
        }
        ::-webkit-scrollbar { display: none; }

        .app-container {
            width: 100%;
            max-width: 430px;
            min-height: 100vh;
            min-height: 100dvh;
            background: var(--bg);
            padding-bottom: calc(var(--nav) + var(--sb) + 20px);
            overflow-x: hidden;
        }
        @media (min-width: 431px) { .app-container { box-shadow: 0 0 40px rgba(15,23,42,.14); } }

        /* App bar */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 40;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: calc(10px + var(--st)) var(--g) 10px;
            background: rgba(80,56,150,.94);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            backdrop-filter: saturate(180%) blur(14px);
            color: #fff;
        }

        .back-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,.16);
            color: #fff;
            font-size: 1.05rem;
            text-decoration: none;
            transition: transform .12s;
        }
        .back-btn:active { transform: scale(.92); }

        .header-title { font-size: 1.05rem; font-weight: 600; }

        /* Profile card (การ์ดขาวเรียบ) */
        .profile-main-card {
            margin: 16px var(--g) 14px;
            padding: 24px 20px 20px;
            border-radius: 20px;
            background: #fff;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .profile-img-container {
            position: relative;
            width: 104px;
            height: 104px;
            margin: 0 auto 14px;
        }

        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #efeaf9;
            background: #fff;
        }

        .edit-img-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            padding: 0;
            border: 3px solid #fff;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            font-size: .8rem;
            cursor: pointer;
            transition: transform .12s;
        }
        .edit-img-btn:active { transform: scale(.92); }

        .profile-main-card h2 { margin-bottom: 8px; font-size: 1.15rem; font-weight: 600; line-height: 1.3; }

        .profile-main-card p {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            background: #efeaf9;
            color: var(--primary);
            font-size: .8rem;
            font-weight: 500;
        }

        .photo-status {
            display: inline-block;
            margin-top: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            background: #fef3c7;
            color: #92400e;
            font-size: .75rem;
            font-weight: 500;
        }

        .photo-status-bad {
            background: #fee2e2;
            color: #991b1b;
            line-height: 1.5;
            max-width: 100%;
        }

        /* Info sections: grouped list */
        .section-label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 18px calc(var(--g) + 4px) 8px;
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .02em;
            color: var(--mute);
        }
        .section-label i { color: var(--primary); }

        .info-section {
            margin: 0 var(--g);
            padding: 0 16px;
            border-radius: 20px;
            background: #fff;
            box-shadow: var(--shadow);
        }

        .info-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 0;
            font-size: .88rem;
        }
        .info-row + .info-row { border-top: 1px solid var(--line); }

        .info-label { color: var(--mute); font-weight: 400; }
        .info-value { color: var(--ink); font-weight: 600; text-align: right; }
        .text-highlight { color: var(--primary); }

        /* Bottom nav สีม่วง */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: 100%;
            max-width: 430px;
            height: calc(var(--nav) + var(--sb));
            padding-bottom: var(--sb);
            display: flex;
            align-items: center;
            background: var(--bar);
            box-shadow: 0 -4px 18px rgba(20,20,43,.18);
        }

        .nav-link {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            padding: 6px 0;
            color: rgba(255,255,255,.6);
            font-size: .7rem;
            font-weight: 500;
            text-decoration: none;
        }
        .nav-link i {
            display: grid;
            place-items: center;
            width: 56px;
            height: 30px;
            border-radius: 15px;
            font-size: 1.2rem;
            transition: background .2s;
        }
        .nav-link:active { color: #fff; }
        .nav-link:active i { background: rgba(255,255,255,.2); }
        .nav-link.danger { color: #fecaca; }

        /* Photo upload modal (bottom sheet) */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 2000;
            background: rgba(15,23,42,.5);
            -webkit-backdrop-filter: blur(3px);
            backdrop-filter: blur(3px);
        }

        .modal-sheet {
            width: 100%;
            max-width: 430px;
            max-height: 92vh;
            max-height: 92dvh;
            overflow-y: auto;
            padding: 10px 20px calc(24px + var(--sb));
            border-radius: 26px 26px 0 0;
            background: #fff;
        }
        .modal-sheet::before {
            content: '';
            display: block;
            width: 40px;
            height: 5px;
            margin: 0 auto 14px;
            border-radius: 3px;
            background: #d4d8e2;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .modal-head h3 { font-size: 1.05rem; font-weight: 600; color: var(--ink); }

        .modal-close {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 50%;
            background: #eef0f6;
            color: var(--mute);
            cursor: pointer;
        }

        .guide {
            display: flex;
            gap: 12px;
            margin-bottom: 14px;
            padding: 12px;
            border-radius: 16px;
            background: #f6f7fb;
        }

        .guide-img {
            width: 96px;
            height: 96px;
            flex-shrink: 0;
            object-fit: cover;
            border-radius: 12px;
        }

        .guide-title { margin: 0 0 4px; font-size: .78rem; font-weight: 600; color: var(--ink); }

        .guide-list {
            list-style: none;
            font-size: .72rem;
            color: var(--mute);
            line-height: 1.5;
        }
        .guide-list li { position: relative; padding-left: 14px; }
        .guide-list li::before { content: '•'; position: absolute; left: 3px; color: var(--primary); }

        .preview-box {
            width: 100%;
            aspect-ratio: 1 / 1;
            max-width: 220px;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 2px dashed #cbd5e1;
            border-radius: 18px;
            color: #9aa3b2;
            font-size: .8rem;
            text-align: center;
        }
        .preview-box img { width: 100%; height: 100%; object-fit: cover; }

        .btn {
            width: 100%;
            margin-top: 8px;
            padding: 13px;
            border: 0;
            border-radius: 14px;
            font-family: inherit;
            font-size: .92rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform .1s;
        }
        .btn:active { transform: scale(.97); }
        .btn-outline { background: #fff; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn:disabled { opacity: .45; cursor: not-allowed; }

        .result-box {
            margin-top: 10px;
            padding: 12px;
            border-radius: 14px;
            font-size: .8rem;
        }
        .result-ok { background: #dcfce7; color: #166534; }
        .result-bad { background: #fee2e2; color: #991b1b; }
        .result-warn { background: #fef3c7; color: #92400e; }
        .result-msg { margin: 0; font-weight: 600; }

        .check-list { list-style: none; margin: 8px 0 0; padding: 0; }
        .check-list li { display: flex; gap: 8px; padding: 3px 0; line-height: 1.4; }
        .check-list i { flex-shrink: 0; width: 14px; margin-top: 3px; }
        .check-list .is-ok i { color: #16a34a; }
        .check-list .is-bad i { color: #dc2626; }
        .check-note { display: block; opacity: .85; }

        .spin { animation: spin .9s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Animations */
        .fade-in-up {
            opacity: 0;
            transform: translateY(14px);
            animation: fadeInUp .45s cubic-bezier(.16,1,.3,1) forwards;
        }
        @keyframes fadeInUp { to { opacity: 1; transform: none; } }
        .delay-1 { animation-delay: .08s; }
        .delay-2 { animation-delay: .16s; }
        .delay-3 { animation-delay: .24s; }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
            .fade-in-up { animation: none; opacity: 1; transform: none; }
        }
    </style>
</head>
<body>

<div class="app-container">

    <!-- Top Header -->
    <header class="top-header">
        <a href="javascript:history.back()" class="back-btn" aria-label="ย้อนกลับ"><i class="fas fa-chevron-left"></i></a>
        <h1 class="header-title">ข้อมูลส่วนตัว</h1>
    </header>

    <main>
    <!-- Profile Header Card -->
    <div class="profile-main-card fade-in-up delay-1">
        <div class="profile-img-container">
            <img src="<?php echo $image_path; ?>" alt="Profile Image" class="profile-img" id="profileImg">
            <?php if ($is_own_profile): ?>
                <button type="button" class="edit-img-btn" id="editImgBtn" aria-label="เปลี่ยนรูปประจำตัว">
                    <i class="fas fa-camera"></i>
                </button>
            <?php endif; ?>
        </div>
        <h2><?php echo $full_name; ?></h2>
        <p><i class="fa-solid fa-id-badge"></i> รหัสประจำตัว: <?php echo $student_code; ?></p>
        <?php if ($image_status === 'pending'): ?>
            <div><span class="photo-status"><i class="fas fa-hourglass-half"></i> รูปรอเจ้าหน้าที่ตรวจสอบ</span></div>
        <?php elseif ($image_status === 'rejected'): ?>
            <div>
                <span class="photo-status photo-status-bad">
                    <i class="fas fa-circle-exclamation"></i> รูปไม่ผ่านการตรวจ<?php echo ($is_own_profile && $image_note !== '') ? ': ' . htmlspecialchars($image_note) : ''; ?>
                    <?php if ($is_own_profile): ?><br>กรุณาอัปโหลดรูปใหม่<?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ข้อมูลส่วนตัว -->
    <div class="fade-in-up delay-2">
        <div class="section-label"><i class="fas fa-user"></i> ข้อมูลพื้นฐาน</div>
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">เลขประจำตัวประชาชน</span>
                <span class="info-value"><?php echo htmlspecialchars($student['id_card'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">เพศ</span>
                <span class="info-value">ชาย</span>
            </div>
            <div class="info-row">
                <span class="info-label">อายุ</span>
                <span class="info-value"><?php echo $display_age; ?> ปี</span>
            </div>
        </div>
    </div>

    <!-- ข้อมูลสังกัด -->
    <div class="fade-in-up delay-3">
        <div class="section-label"><i class="fas fa-sitemap"></i> ข้อมูลสังกัด</div>
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">กองพัน</span>
                <span class="info-value"><?php echo htmlspecialchars($student['battalion'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">กองร้อย</span>
                <span class="info-value"><?php echo htmlspecialchars($student['company'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">หมวด</span>
                <span class="info-value"><?php echo htmlspecialchars($student['platoon'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">ตอน/วิชาชีพช่าง</span>
                <span class="info-value"><?php echo htmlspecialchars($student['section'] ?? '-'); ?></span>
            </div>
        </div>
    </div>

    <!-- ข้อมูลทางกายภาพ -->
    <div class="fade-in-up delay-3">
        <div class="section-label"><i class="fas fa-weight-scale"></i> ข้อมูลสุขภาพ</div>
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">น้ำหนัก</span>
                <span class="info-value"><?php echo $display_weight; ?> กก.</span>
            </div>
            <div class="info-row">
                <span class="info-label">ส่วนสูง</span>
                <span class="info-value"><?php echo $display_height; ?> ซม.</span>
            </div>
            <div class="info-row">
                <span class="info-label">ค่า BMI</span>
                <span class="info-value"><?php echo $display_bmi; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">เกณฑ์ BMI</span>
                <span class="info-value text-highlight"><?php echo $display_bmi_cat; ?></span>
            </div>
        </div>
    </div>
    </main>

</div>

<!-- Bottom Navigation -->
<nav class="bottom-nav" aria-label="เมนูหลัก">
    <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
    </a>
    <a href="logout.php" class="nav-link danger">
        <i class="fas fa-sign-out-alt"></i>
        <span>ออกจากระบบ</span>
    </a>
</nav>

<?php if ($is_own_profile): ?>
<!-- Modal อัปโหลดรูป -->
<div class="modal-backdrop" id="photoModal" hidden>
    <div class="modal-sheet" role="dialog" aria-modal="true" aria-labelledby="photoModalTitle">
        <div class="modal-head">
            <h3 id="photoModalTitle">เปลี่ยนรูปประจำตัว</h3>
            <button type="button" class="modal-close" id="closeModalBtn" aria-label="ปิด"><i class="fas fa-times"></i></button>
        </div>

        <div class="guide">
            <img src="uniform_reference.jpg" alt="ตัวอย่างรูปที่ถูกต้อง" class="guide-img">
            <div>
                <p class="guide-title">รูปที่ถูกต้องต้องเป็นแบบนี้</p>
                <ul class="guide-list">
                    <?php foreach ($UNIFORM_CRITERIA as $c): ?>
                        <li><?php echo htmlspecialchars($c['desc']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="preview-box" id="previewBox">
            <span id="previewHint">ยังไม่ได้เลือกรูป</span>
            <img id="previewImg" alt="ตัวอย่างรูปที่เลือก" hidden>
        </div>

        <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" hidden>
        <button type="button" class="btn btn-outline" id="pickBtn"><i class="fas fa-image"></i> เลือกรูปจากเครื่อง</button>

        <div class="result-box" id="resultBox" hidden></div>

        <button type="button" class="btn btn-primary" id="submitBtn" disabled>ตรวจสอบและอัปโหลด</button>
    </div>
</div>

<script>
(function () {
    const CSRF      = <?php echo json_encode($_SESSION['csrf']); ?>;
    const MIN_SIDE  = <?php echo (int)MIN_SIDE_PX; ?>;
    const MAX_BYTES = <?php echo (int)MAX_FILE_BYTES; ?>;
    const TYPES     = ['image/jpeg', 'image/png', 'image/webp'];

    const $ = (id) => document.getElementById(id);
    const modal = $('photoModal'), input = $('photoInput'), pickBtn = $('pickBtn'), submitBtn = $('submitBtn');
    const previewImg = $('previewImg'), previewHint = $('previewHint'), resultBox = $('resultBox');
    const profileImg = $('profileImg');

    let selectedFile = null;
    let objectUrl = null;
    let uploaded = false;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function showResult(kind, message, checks) {
        resultBox.hidden = false;
        resultBox.className = 'result-box result-' + kind;
        resultBox.replaceChildren(el('p', 'result-msg', message));
        if (checks && checks.length) {
            const list = el('ul', 'check-list');
            checks.forEach((c) => {
                const li = el('li', c.ok ? 'is-ok' : 'is-bad');
                li.appendChild(el('i', 'fas ' + (c.ok ? 'fa-check' : 'fa-xmark')));
                const body = el('span');
                body.appendChild(document.createTextNode(c.label));
                if (!c.ok && c.note) body.appendChild(el('span', 'check-note', c.note));
                li.appendChild(body);
                list.appendChild(li);
            });
            resultBox.appendChild(list);
        }
    }

    function resetSelection() {
        selectedFile = null;
        input.value = '';
        submitBtn.disabled = true;
        previewImg.hidden = true;
        previewHint.hidden = false;
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    }

    function openModal() {
        resetSelection();
        resultBox.hidden = true;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        resetSelection();
        if (uploaded) location.reload(); // รีเฟรชเพื่อแสดงสถานะรูปล่าสุด
    }

    $('editImgBtn').addEventListener('click', openModal);
    $('closeModalBtn').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
    pickBtn.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        resultBox.hidden = true;

        if (!TYPES.includes(file.type)) {
            resetSelection(); showResult('bad', 'รองรับเฉพาะไฟล์ JPG, PNG หรือ WebP'); return;
        }
        if (file.size > MAX_BYTES) {
            resetSelection(); showResult('bad', 'ไฟล์ใหญ่เกิน ' + (MAX_BYTES / 1048576) + ' MB'); return;
        }

        const url = URL.createObjectURL(file);
        const probe = new Image();
        probe.onload = () => {
            if (Math.min(probe.naturalWidth, probe.naturalHeight) < MIN_SIDE) {
                URL.revokeObjectURL(url);
                resetSelection();
                showResult('bad', 'ภาพเล็กเกินไป ด้านสั้นสุดต้องไม่ต่ำกว่า ' + MIN_SIDE + ' พิกเซล');
                return;
            }
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = url;
            selectedFile = file;
            previewImg.src = url;
            previewImg.hidden = false;
            previewHint.hidden = true;
            submitBtn.disabled = false;
        };
        probe.onerror = () => {
            URL.revokeObjectURL(url);
            resetSelection();
            showResult('bad', 'เปิดไฟล์ภาพไม่ได้ กรุณาเลือกไฟล์อื่น');
        };
        probe.src = url;
    });

    submitBtn.addEventListener('click', async () => {
        if (!selectedFile) return;

        const label = submitBtn.textContent;
        submitBtn.disabled = true;
        pickBtn.disabled = true;
        submitBtn.replaceChildren(el('i', 'fas fa-spinner spin'), document.createTextNode(' กำลังตรวจสอบรูป...'));
        resultBox.hidden = true;

        const fd = new FormData();
        fd.append('photo', selectedFile);
        fd.append('csrf', CSRF);

        try {
            const res = await fetch('upload_photo.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();

            if (data.ok) {
                uploaded = true;
                showResult(data.status === 'approved' ? 'ok' : 'warn', data.message, data.checks);
                if (data.image_url) profileImg.src = data.image_url;
                selectedFile = null; // ไม่ให้กดอัปโหลดซ้ำรูปเดิม
                submitBtn.textContent = label;
                pickBtn.disabled = false;
                return;
            }
            showResult('bad', data.message || 'อัปโหลดไม่สำเร็จ', data.checks);
        } catch (err) {
            showResult('bad', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่');
        }

        submitBtn.textContent = label;
        submitBtn.disabled = !selectedFile;
        pickBtn.disabled = false;
    });
})();
</script>
<?php endif; ?>

</body>
</html>
