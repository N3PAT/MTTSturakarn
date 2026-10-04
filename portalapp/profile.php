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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ข้อมูลส่วนตัว - Profile</title>

    <!-- FontAwesome & Google Fonts -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

    <style>
        :root {
            --primary: #5a42a0;
            --primary-light: #7c5dfa;
            --bg-color: #f6f8fc;
            --text-dark: #0f172a;
            --text-gray: #64748b;
            --card-bg: #ffffff;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        [hidden] { display: none !important; }

        body {
            font-family: 'Prompt', sans-serif;
            background-color: #e2e8f0;
            margin: 0;
            display: flex;
            justify-content: center;
            user-select: none;
        }

        .app-container {
            width: 100%;
            max-width: 430px;
            background-color: var(--bg-color);
            min-height: 100vh;
            position: relative;
            padding-bottom: 90px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            overflow-x: hidden;
        }

        /* Top Header */
        .top-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 20px 10px;
            background: var(--bg-color);
        }

        .header-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }

        .back-btn {
            color: var(--text-dark);
            font-size: 1.2rem;
            text-decoration: none;
        }

        /* Profile Main Card */
        .profile-main-card {
            background: var(--primary);
            border-radius: 20px;
            padding: 30px 20px;
            margin: 10px 20px 20px;
            color: white;
            box-shadow: 0 10px 25px rgba(90, 66, 160, 0.25);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        /* Background Pattern for Profile Card */
        .profile-main-card::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .profile-img-container {
            position: relative;
            width: 110px;
            height: 110px;
            margin: 0 auto 15px;
        }

        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid rgba(255, 255, 255, 0.3);
            background-color: #fff;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .edit-img-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background: #ea580c;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            border: 2px solid var(--primary);
            padding: 0;
            cursor: pointer;
            text-decoration: none;
        }

        .profile-main-card h2 {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0 0 5px 0;
        }

        .profile-main-card p {
            font-size: 0.9rem;
            margin: 0;
            opacity: 0.9;
            background: rgba(0,0,0,0.15);
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
        }

        .photo-status {
            display: inline-block;
            margin-top: 10px;
            font-size: 0.75rem;
            padding: 3px 10px;
            border-radius: 20px;
            background: #fef3c7;
            color: #92400e;
        }

        .photo-status-bad {
            background: #fee2e2;
            color: #991b1b;
            line-height: 1.5;
            max-width: 100%;
        }

        /* Info Sections */
        .info-section {
            background: var(--card-bg);
            border-radius: 16px;
            margin: 0 20px 20px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 10px;
        }

        .section-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .bg-blue { background: #dbeafe; color: #2563eb; }
        .bg-green { background: #dcfce7; color: #16a34a; }
        .bg-orange { background: #ffedd5; color: #ea580c; }

        .section-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 0.85rem;
        }

        .info-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .info-label {
            color: var(--text-gray);
            font-weight: 500;
        }

        .info-value {
            color: var(--text-dark);
            font-weight: 600;
            text-align: right;
        }

        .text-highlight {
            color: var(--primary);
        }

        /* Bottom Nav */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            width: 100%;
            max-width: 430px;
            left: 50%;
            transform: translateX(-50%);
            height: 70px;
            background: white;
            border-top-left-radius: 25px;
            border-top-right-radius: 25px;
            display: flex;
            justify-content: space-around;
            align-items: center;
            box-shadow: 0 -5px 20px rgba(0,0,0,0.03);
            padding: 0 10px;
            z-index: 1000;
        }

        .nav-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #94a3b8;
            font-size: 0.7rem;
            font-weight: 600;
            width: 30%;
            gap: 4px;
        }

        .nav-link.active { color: var(--primary); }
        .nav-link i { font-size: 1.3rem; }

        /* Photo upload modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 2000;
        }

        .modal-sheet {
            width: 100%;
            max-width: 430px;
            max-height: 92vh;
            overflow-y: auto;
            background: #fff;
            border-radius: 24px 24px 0 0;
            padding: 20px 20px 28px;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .modal-head h3 { margin: 0; font-size: 1.05rem; color: var(--text-dark); }

        .modal-close {
            border: none;
            background: #f1f5f9;
            color: var(--text-gray);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
        }

        .guide {
            display: flex;
            gap: 12px;
            background: #f8fafc;
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 14px;
        }

        .guide-img {
            width: 96px;
            height: 96px;
            object-fit: cover;
            border-radius: 10px;
            flex-shrink: 0;
        }

        .guide-title { font-size: 0.75rem; font-weight: 600; color: var(--text-dark); margin: 0 0 4px; }

        .guide-list {
            list-style: none;
            margin: 0;
            padding: 0;
            font-size: 0.72rem;
            color: var(--text-gray);
            line-height: 1.5;
        }

        .guide-list li { padding-left: 14px; position: relative; }
        .guide-list li::before { content: '•'; position: absolute; left: 3px; color: var(--primary); }

        .preview-box {
            width: 100%;
            aspect-ratio: 1 / 1;
            max-width: 220px;
            margin: 0 auto 14px;
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            color: #94a3b8;
            font-size: 0.8rem;
            text-align: center;
        }

        .preview-box img { width: 100%; height: 100%; object-fit: cover; }

        .btn {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
        }

        .btn-outline { background: #fff; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn:disabled { opacity: 0.45; cursor: not-allowed; }

        .result-box {
            border-radius: 12px;
            padding: 12px;
            font-size: 0.8rem;
            margin-top: 10px;
        }

        .result-ok { background: #dcfce7; color: #166534; }
        .result-bad { background: #fee2e2; color: #991b1b; }
        .result-warn { background: #fef3c7; color: #92400e; }

        .result-msg { font-weight: 600; margin: 0; }

        .check-list { list-style: none; margin: 8px 0 0; padding: 0; }
        .check-list li { display: flex; gap: 8px; padding: 3px 0; line-height: 1.4; }
        .check-list i { width: 14px; margin-top: 3px; flex-shrink: 0; }
        .check-list .is-ok i { color: #16a34a; }
        .check-list .is-bad i { color: #dc2626; }
        .check-note { display: block; opacity: 0.85; }

        .spin { animation: spin 0.9s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Animations */
        .fade-in-up {
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }

        @media (prefers-reduced-motion: reduce) {
            .fade-in-up { animation: none; opacity: 1; transform: none; }
        }
    </style>
</head>
<body>

<div class="app-container">

    <!-- Top Header -->
    <div class="top-header fade-in-up">
        <a href="javascript:history.back()" class="back-btn"><i class="fas fa-chevron-left"></i></a>
        <h1 class="header-title">ข้อมูลส่วนตัว</h1>
        <div style="width: 20px;"></div>
    </div>

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
    <div class="info-section fade-in-up delay-2">
        <div class="section-header">
            <div class="section-icon bg-blue"><i class="fas fa-user"></i></div>
            <h3>ข้อมูลพื้นฐาน</h3>
        </div>
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

    <!-- ข้อมูลสังกัด -->
    <div class="info-section fade-in-up delay-3">
        <div class="section-header">
            <div class="section-icon bg-orange"><i class="fas fa-sitemap"></i></div>
            <h3>ข้อมูลสังกัด</h3>
        </div>
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

    <!-- ข้อมูลทางกายภาพ -->
    <div class="info-section fade-in-up delay-3">
        <div class="section-header">
            <div class="section-icon bg-green"><i class="fas fa-weight-scale"></i></div>
            <h3>ข้อมูลสุขภาพ</h3>
        </div>
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

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <a href="main.php" class="nav-link">
            <i class="fas fa-home"></i>
            <span>หน้าหลัก</span>
        </a>
        <!-- เพิ่ม style="color: #dc2626;" เข้าไปที่คลาส nav-link ของปุ่ม logout -->
        <a href="logout.php" class="nav-link" style="color: #dc2626;">
            <i class="fas fa-sign-out-alt"></i>
            <span>ออกจากระบบ</span>
        </a>
    </div>

</div>

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
