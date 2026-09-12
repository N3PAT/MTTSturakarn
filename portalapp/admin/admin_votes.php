<?php
session_start();
require_once 'db.php';

// สมมติว่าตรวจสอบสิทธิ์ Admin แล้ว
// if (empty($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

// ดึงข้อมูลสรุปผลโหวตในสัปดาห์ปัจจุบัน
$stats_query = "
    SELECT 
        COUNT(*) as total_votes,
        SUM(CASE WHEN status = 'ประสงค์กลับบ้าน' THEN 1 ELSE 0 END) as return_home,
        SUM(CASE WHEN status = 'ไม่ประสงค์กลับบ้าน' THEN 1 ELSE 0 END) as stay_school
    FROM weekly_votes 
    WHERE YEARWEEK(vote_date, 1) = YEARWEEK(CURDATE(), 1)
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : ['total_votes' => 0, 'return_home' => 0, 'stay_school' => 0];

// ดึงรายการโหวต พร้อมข้อมูลนักเรียน
$list_query = "
    SELECT v.*, s.prefix, s.first_name, s.last_name, s.battalion, s.company, s.platoon 
    FROM weekly_votes v
    LEFT JOIN students s ON v.student_code = s.student_code
    WHERE YEARWEEK(v.vote_date, 1) = YEARWEEK(CURDATE(), 1)
    ORDER BY v.created_at DESC
";
$list_result = $conn->query($list_query);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ผลการโหวตประจำสัปดาห์ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #5a42a0;
      --bg-color: #f6f8fc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; user-select: none; }

    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; position: relative; padding-bottom: 90px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }

    .top-header { display: flex; align-items: center; gap: 15px; padding: 20px; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.02); position: sticky; top: 0; z-index: 10; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
    .page-title p { font-size: 0.75rem; color: var(--text-gray); margin: 0; }

    .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; padding: 15px 20px; }
    .summary-card { background: white; padding: 15px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); text-align: center; }
    .summary-card.full { grid-column: span 2; background: var(--primary); color: white; }
    .summary-card h3 { font-size: 1.5rem; font-weight: 700; margin: 0; }
    .summary-card p { font-size: 0.75rem; margin: 5px 0 0; font-weight: 500; opacity: 0.9; }
    
    .text-green { color: #10b981; }
    .text-gray { color: #64748b; }

    .list-header { display: flex; justify-content: space-between; align-items: center; padding: 0 20px 10px; }
    .list-header h3 { font-size: 0.95rem; font-weight: 600; color: var(--text-dark); margin: 0; }
    
    .vote-list { padding: 0 20px; display: flex; flex-direction: column; gap: 10px; }
    .vote-item { background: white; border-radius: 14px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .vote-item-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 10px; }
    
    .student-info h4 { margin: 0; font-size: 0.9rem; font-weight: 600; color: var(--text-dark); }
    .student-info p { margin: 2px 0 0 0; font-size: 0.75rem; color: var(--text-gray); }
    
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
    .status-badge.home { background: #dcfce7; color: #16a34a; }
    .status-badge.stay { background: #f1f5f9; color: #64748b; }

    .vote-details { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.75rem; color: var(--text-dark); }
    .detail-col i { color: var(--primary); margin-right: 5px; width: 14px; text-align: center; }

    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body>
  <div class="app-container">
    
    <div class="top-header">
      <a href="admin_dashboard.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>ผลการโหวตประจำสัปดาห์</h1>
        <p>สัปดาห์ที่ <?= date('W') ?> / ปี <?= date('Y') + 543 ?></p>
      </div>
    </div>

    <!-- สรุปผล -->
    <div class="summary-grid fade-in-up">
      <div class="summary-card full">
        <h3><?= number_format($stats['total_votes'] ?? 0) ?></h3>
        <p>จำนวนผู้โหวตทั้งหมด (คน)</p>
      </div>
      <div class="summary-card">
        <h3 class="text-green"><?= number_format($stats['return_home'] ?? 0) ?></h3>
        <p class="text-gray">ประสงค์กลับบ้าน</p>
      </div>
      <div class="summary-card">
        <h3 class="text-gray"><?= number_format($stats['stay_school'] ?? 0) ?></h3>
        <p class="text-gray">ไม่ประสงค์กลับ</p>
      </div>
    </div>

    <div class="list-header fade-in-up" style="animation-delay: 0.1s;">
      <h3>รายการโหวตล่าสุด</h3>
      <a href="export_votes.php" style="font-size: 0.75rem; color: var(--primary); text-decoration: none;"><i class="fas fa-download"></i> ส่งออก Excel</a>
    </div>

    <!-- รายการโหวต -->
    <div class="vote-list fade-in-up" style="animation-delay: 0.2s;">
      <?php if ($list_result && $list_result->num_rows > 0): ?>
        <?php while($row = $list_result->fetch_assoc()): ?>
          <?php 
            $fullname = ($row['prefix'] ?? '') . ($row['first_name'] ?? 'ไม่พบชื่อ') . ' ' . ($row['last_name'] ?? '');
            $unit = "พัน.{$row['battalion']} ร้อย.{$row['company']}";
            $is_returning = ($row['status'] === 'ประสงค์กลับบ้าน');
          ?>
          <div class="vote-item">
            <div class="vote-item-header">
              <div class="student-info">
                <h4><?= htmlspecialchars($fullname) ?></h4>
                <p>รหัส: <?= htmlspecialchars($row['student_code']) ?> | <?= htmlspecialchars($unit) ?></p>
              </div>
              <div class="status-badge <?= $is_returning ? 'home' : 'stay' ?>">
                <?= htmlspecialchars($row['status']) ?>
              </div>
            </div>
            
            <?php if ($is_returning): ?>
            <div class="vote-details">
              <div class="detail-col"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($row['province'] ?? '-') ?></div>
              <div class="detail-col"><i class="fas fa-bus"></i> <?= htmlspecialchars($row['travel_method'] ?? '-') ?></div>
              <div class="detail-col" style="grid-column: span 2;"><i class="fas fa-calendar-check"></i> กลับเข้ากองพัน: <?= htmlspecialchars($row['return_day'] ?? '-') ?></div>
            </div>
            <?php endif; ?>
            <div style="font-size: 0.65rem; color: #94a3b8; text-align: right; margin-top: 8px;">
              โหวตเมื่อ: <?= date('d/m/Y H:i', strtotime($row['created_at'])) ?>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--text-gray);">
          <i class="fas fa-box-open" style="font-size: 2rem; margin-bottom: 10px; color: #cbd5e1;"></i>
          <p>ยังไม่มีข้อมูลการโหวตในสัปดาห์นี้</p>
        </div>
      <?php endif; ?>
    </div>

  </div>
</body>
</html>
