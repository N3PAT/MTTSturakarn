<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 1. เรียกใช้ไฟล์เชื่อมต่อฐานข้อมูล
require_once 'db.php';

// 2. ตรวจสอบการ Login
if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
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
    $image_path   = !empty($student['image']) ? $student['image'] : 'default_student.png';
    $full_name    = htmlspecialchars(($student['prefix'] ?? '') . ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $student_code = htmlspecialchars($student['student_code'] ?? '-');
    
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
            <img src="<?php echo $image_path; ?>" alt="Profile Image" class="profile-img">
        </div>
        <h2><?php echo $full_name; ?></h2>
        <p><i class="fa-solid fa-id-badge"></i> รหัสประจำตัว: <?php echo $student_code; ?></p>
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

</body>
</html>
