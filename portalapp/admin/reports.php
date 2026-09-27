<?php
session_start();

// เปิดการแสดง Error ชั่วคราวสำหรับการตรวจสอบระบบ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    // 1. ตรวจสอบไฟล์เชื่อมต่อฐานข้อมูล
    if (!file_exists('db.php')) {
        throw new Exception("ไม่พบไฟล์ 'db.php' กรุณาตรวจสอบระบบ");
    }
    require_once 'db.php';

    // 2. ตรวจสอบสิทธิ์ Admin
    if (empty($_SESSION['admin_id'])) {
        header("Location: admin_main.php");
        exit();
    }

    if (!isset($conn)) {
        throw new Exception("ไม่พบตัวแปร \$conn ใน db.php");
    }

    $view = $_GET['view'] ?? 'overview'; // overview, tickets, detentions

    // =========================================================================
    // ดึงข้อมูลจริงจากฐานข้อมูล (กรุณาปรับชื่อ Table และ Column ให้ตรงกับ DB ของคุณ)
    // =========================================================================

    if ($view === 'overview') {
        // ดึงสถิติภาพรวม
        $total_tickets = $conn->query("SELECT COUNT(id) as total FROM disciplinary_tickets")->fetch_assoc()['total'] ?? 0;
        $total_detentions = $conn->query("SELECT COUNT(id) as total FROM detentions")->fetch_assoc()['total'] ?? 0;
        
        // 5 อันดับเหตุผลที่โดนบัตรวินัยมากที่สุด
        $top_ticket_reasons = [];
        $res = $conn->query("SELECT reason, COUNT(id) as count FROM disciplinary_tickets GROUP BY reason ORDER BY count DESC LIMIT 5");
        if ($res) while ($row = $res->fetch_assoc()) $top_ticket_reasons[] = $row;

        // 5 อันดับเหตุผลที่โดนกักมากที่สุด
        $top_detention_reasons = [];
        $res = $conn->query("SELECT reason, COUNT(id) as count FROM detentions GROUP BY reason ORDER BY count DESC LIMIT 5");
        if ($res) while ($row = $res->fetch_assoc()) $top_detention_reasons[] = $row;
    }

    if ($view === 'tickets') {
        // 10 อันดับนักเรียนที่โดนบัตรวินัยมากที่สุด
        $top_tickets_students = [];
        $sql = "SELECT s.student_code, s.prefix, s.first_name, s.last_name, COUNT(dt.id) as total_tickets 
                FROM disciplinary_tickets dt 
                JOIN students s ON dt.student_code = s.student_code 
                GROUP BY s.student_code 
                ORDER BY total_tickets DESC LIMIT 10";
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) $top_tickets_students[] = $row;
    }

    if ($view === 'detentions') {
        // 10 อันดับนักเรียนที่โดนกักมากที่สุด
        $top_detentions_students = [];
        $sql = "SELECT s.student_code, s.prefix, s.first_name, s.last_name, COUNT(d.id) as total_times, SUM(d.days) as total_days 
                FROM detentions d 
                JOIN students s ON d.student_code = s.student_code 
                GROUP BY s.student_code 
                ORDER BY total_days DESC, total_times DESC LIMIT 10";
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) $top_detentions_students[] = $row;
    }

} catch (Throwable $e) {
    die('
    <div style="background:#fee2e2; border:1px solid #fecaca; padding:20px; margin:20px; border-radius:12px; font-family:\'Prompt\', sans-serif;">
        <h3 style="color:#991b1b; margin-top:0;"><i class="fas fa-exclamation-circle"></i> SQL/System Error</h3>
        <p style="color:#7f1d1d; font-size:0.9rem;"><strong>ข้อความ:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
        <p style="color:#7f1d1d; font-size:0.85rem;"><em>* หมายเหตุ: กรุณาตรวจสอบชื่อ Table (disciplinary_tickets, detentions) และ Column ให้ตรงกับฐานข้อมูลจริงของคุณ</em></p>
    </div>
    ');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>รายงานสถิติ - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #8b5cf6; /* สีม่วงสำหรับ Report */
      --primary-hover: #7c3aed;
      --bg-color: #f8fafc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
      --danger: #ef4444;
      --warning: #f59e0b;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; }

    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes popIn { 0% { opacity: 0; transform: scale(0.9); } 100% { opacity: 1; transform: scale(1); } }

    .animate-slide-up { animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }
    .delay-1 { animation-delay: 0.1s; }
    .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; }

    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 100px; box-shadow: 0 0 20px rgba(0,0,0,0.05); position: relative; overflow-x: hidden; }

    .top-header { display: flex; align-items: center; padding: 20px 24px 10px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-right: 15px; transition: 0.2s ease; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    .tabs-container { display: flex; margin: 10px 24px 0; background: #e2e8f0; border-radius: 12px; padding: 4px; }
    .tab-btn { flex: 1; text-align: center; padding: 8px; font-size: 0.8rem; font-weight: 600; color: var(--text-gray); border-radius: 10px; text-decoration: none; transition: 0.3s ease; display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .tab-btn i { font-size: 1.1rem; }
    .tab-btn.active { background: white; color: var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }

    .content-section { padding: 20px 24px; }

    /* Stats Box */
    .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
    .stat-box { background: white; padding: 15px; border-radius: 16px; border: 1px solid #e2e8f0; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
    .stat-box i { font-size: 1.8rem; margin-bottom: 10px; }
    .stat-box .num { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); margin: 0; line-height: 1; }
    .stat-box .label { font-size: 0.8rem; color: var(--text-gray); margin-top: 5px; }

    /* Ranking List */
    .ranking-list { background: white; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px; }
    .list-header { background: #f8fafc; padding: 12px 15px; font-weight: 700; font-size: 0.9rem; color: var(--text-dark); border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; }
    .list-item { display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid #f1f5f9; transition: 0.2s; }
    .list-item:last-child { border-bottom: none; }
    .list-item:hover { background: #f8fafc; }
    
    .rank-num { width: 30px; height: 30px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; color: var(--text-dark); margin-right: 12px; flex-shrink: 0; }
    .rank-num.top-1 { background: #fef08a; color: #854d0e; }
    .rank-num.top-2 { background: #e2e8f0; color: #475569; }
    .rank-num.top-3 { background: #fed7aa; color: #9a3412; }
    
    .student-info { flex: 1; }
    .student-info .name { font-weight: 600; font-size: 0.9rem; color: var(--text-dark); margin: 0; }
    .student-info .code { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    .stat-value { font-weight: 700; font-size: 1rem; }

    /* Reason Progress */
    .reason-item { margin-bottom: 12px; }
    .reason-header { display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 5px; color: var(--text-dark); font-weight: 500; }
    .progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 4px; }

    .bottom-nav { position: fixed; bottom: 0; width: 100%; max-width: 430px; left: 50%; transform: translateX(-50%); height: 70px; background: white; border-top-left-radius: 25px; border-top-right-radius: 25px; display: flex; justify-content: space-around; align-items: center; box-shadow: 0 -5px 20px rgba(0,0,0,0.03); padding: 0 10px; z-index: 1000; }
    .nav-link { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 600; width: 30%; gap: 4px; }
    .nav-link.active { color: var(--primary); }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="top-header animate-slide-up delay-1">
      <a href="admin_main.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>รายงานและสถิติ</h1>
      </div>
    </div>

    <div class="tabs-container animate-slide-up delay-2">
        <a href="?view=overview" class="tab-btn <?= $view === 'overview' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> ภาพรวม</a>
        <a href="?view=tickets" class="tab-btn <?= $view === 'tickets' ? 'active' : '' ?>"><i class="fas fa-ticket-alt"></i> บัตรวินัย</a>
        <a href="?view=detentions" class="tab-btn <?= $view === 'detentions' ? 'active' : '' ?>"><i class="fas fa-user-lock"></i> การกัก</a>
    </div>

    <div class="content-section animate-slide-up delay-3">
      
      <!-- ================= VIEW: ภาพรวม ================= -->
      <?php if ($view === 'overview'): ?>
        
        <div class="stats-grid">
            <div class="stat-box">
                <i class="fas fa-ticket-alt" style="color: var(--warning);"></i>
                <p class="num"><?= number_format($total_tickets) ?></p>
                <p class="label">บัตรวินัย (ใบ)</p>
            </div>
            <div class="stat-box">
                <i class="fas fa-user-lock" style="color: var(--danger);"></i>
                <p class="num"><?= number_format($total_detentions) ?></p>
                <p class="label">การกัก (ครั้ง)</p>
            </div>
        </div>

        <div class="ranking-list">
            <div class="list-header"><i class="fas fa-list-ul"></i> 5 อันดับเหตุผลที่โดนบัตรวินัย</div>
            <div style="padding: 15px;">
                <?php if (empty($top_ticket_reasons)): ?>
                    <p style="text-align:center; color:#94a3b8; font-size:0.85rem; margin:0;">ไม่มีข้อมูล</p>
                <?php else: ?>
                    <?php 
                    $max = $top_ticket_reasons[0]['count']; 
                    foreach ($top_ticket_reasons as $r): 
                        $pct = ($r['count'] / $max) * 100;
                    ?>
                    <div class="reason-item">
                        <div class="reason-header">
                            <span><?= htmlspecialchars($r['reason']) ?></span>
                            <span><?= $r['count'] ?> ใบ</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?= $pct ?>%; background: var(--warning);"></div></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="ranking-list">
            <div class="list-header"><i class="fas fa-list-ul"></i> 5 อันดับเหตุผลที่โดนกัก</div>
            <div style="padding: 15px;">
                <?php if (empty($top_detention_reasons)): ?>
                    <p style="text-align:center; color:#94a3b8; font-size:0.85rem; margin:0;">ไม่มีข้อมูล</p>
                <?php else: ?>
                    <?php 
                    $max = $top_detention_reasons[0]['count']; 
                    foreach ($top_detention_reasons as $r): 
                        $pct = ($r['count'] / $max) * 100;
                    ?>
                    <div class="reason-item">
                        <div class="reason-header">
                            <span><?= htmlspecialchars($r['reason']) ?></span>
                            <span><?= $r['count'] ?> ครั้ง</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?= $pct ?>%; background: var(--danger);"></div></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

      <!-- ================= VIEW: บัตรวินัย ================= -->
      <?php elseif ($view === 'tickets'): ?>

        <div class="ranking-list">
            <div class="list-header" style="background:#fffbeb; color:#b45309;">
                <span><i class="fas fa-trophy"></i> 10 อันดับโดนบัตรวินัยสูงสุด</span>
                <span>จำนวนใบ</span>
            </div>
            
            <?php if (empty($top_tickets_students)): ?>
                <div style="padding: 20px; text-align:center; color:#94a3b8;"><i class="fas fa-check-circle" style="font-size:2rem; margin-bottom:10px; display:block;"></i>ยังไม่มีประวัติบัตรวินัย</div>
            <?php else: ?>
                <?php 
                $rank = 1;
                foreach ($top_tickets_students as $stu): 
                    $rankClass = $rank <= 3 ? "top-{$rank}" : "";
                ?>
                <div class="list-item">
                    <div class="rank-num <?= $rankClass ?>"><?= $rank ?></div>
                    <div class="student-info">
                        <p class="name"><?= htmlspecialchars($stu['prefix'] . $stu['first_name'] . ' ' . $stu['last_name']) ?></p>
                        <p class="code">รหัส: <?= htmlspecialchars($stu['student_code']) ?></p>
                    </div>
                    <div class="stat-value" style="color: var(--warning);"><?= $stu['total_tickets'] ?></div>
                </div>
                <?php $rank++; endforeach; ?>
            <?php endif; ?>
        </div>

      <!-- ================= VIEW: การกัก ================= -->
      <?php elseif ($view === 'detentions'): ?>

        <div class="ranking-list">
            <div class="list-header" style="background:#fef2f2; color:#b91c1c;">
                <span><i class="fas fa-trophy"></i> 10 อันดับโดนกักสูงสุด</span>
                <span>รวมวันกัก</span>
            </div>
            
            <?php if (empty($top_detentions_students)): ?>
                <div style="padding: 20px; text-align:center; color:#94a3b8;"><i class="fas fa-check-circle" style="font-size:2rem; margin-bottom:10px; display:block;"></i>ยังไม่มีประวัติการกัก</div>
            <?php else: ?>
                <?php 
                $rank = 1;
                foreach ($top_detentions_students as $stu): 
                    $rankClass = $rank <= 3 ? "top-{$rank}" : "";
                ?>
                <div class="list-item">
                    <div class="rank-num <?= $rankClass ?>"><?= $rank ?></div>
                    <div class="student-info">
                        <p class="name"><?= htmlspecialchars($stu['prefix'] . $stu['first_name'] . ' ' . $stu['last_name']) ?></p>
                        <p class="code">รหัส: <?= htmlspecialchars($stu['student_code']) ?> (กัก <?= $stu['total_times'] ?> ครั้ง)</p>
                    </div>
                    <div class="stat-value" style="color: var(--danger);"><?= $stu['total_days'] ?></div>
                </div>
                <?php $rank++; endforeach; ?>
            <?php endif; ?>
        </div>

      <?php endif; ?>

    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="admin_main.php" class="nav-link">
        <i class="fas fa-home" style="font-size:1.1rem;"></i>
        <span>หน้าหลัก</span>
      </a>
      <a href="report.php" class="nav-link active">
        <i class="fas fa-chart-bar" style="font-size:1.1rem;"></i>
        <span>รายงาน</span>
      </a>
      <a href="admin_settings.php" class="nav-link">
        <i class="fas fa-cog" style="font-size:1.1rem;"></i>
        <span>ตั้งค่า</span>
      </a>
    </div>

  </div>
</body>
</html>
