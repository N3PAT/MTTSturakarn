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

    // 2. ตรวจสอบสิทธิ์ Admin (ปรับ Session ตามระบบของคุณ)
    if (empty($_SESSION['admin_id'])) {
        header("Location: admin_main.php");
        exit();
    }

    if (!isset($conn)) {
        throw new Exception("ไม่พบตัวแปร \$conn ใน db.php");
    }

    $view = $_GET['view'] ?? 'overview'; // overview, cards, restrictions, votes

    // =========================================================================
    // ดึงข้อมูลจริงจากฐานข้อมูล
    // =========================================================================

    if ($view === 'overview') {
        // สถิติบัตรวินัยรวม (นับรวมจำนวนบัตรทั้งหมดจาก total_cards)
        $total_cards_query = $conn->query("SELECT SUM(total_cards) as total FROM discipline_cards");
        $total_cards = $total_cards_query ? ($total_cards_query->fetch_assoc()['total'] ?? 0) : 0;

        // สถิติโดนกักรวม (นับรวมจำนวนสัปดาห์จาก weeks_count)
        $total_restrictions_query = $conn->query("SELECT SUM(weeks_count) as total FROM restrictions");
        $total_restrictions = $total_restrictions_query ? ($total_restrictions_query->fetch_assoc()['total'] ?? 0) : 0;
        
        // สถิติเหตุผลที่โดนบัตรวินัย (ดึงจากคอลัมน์ boolean: card_bed, card_shoe, card_cabinet)
        $card_reasons = [];
        $res_cards = $conn->query("SELECT SUM(card_bed) as bed, SUM(card_shoe) as shoe, SUM(card_cabinet) as cabinet FROM discipline_cards");
        if ($res_cards) {
            $row = $res_cards->fetch_assoc();
            $card_reasons = [
                ['name' => 'เตียงนอนไม่เรียบร้อย', 'count' => $row['bed'] ?? 0],
                ['name' => 'รองเท้าผิดระเบียบ/ไม่เรียบร้อย', 'count' => $row['shoe'] ?? 0],
                ['name' => 'ตู้เก็บของไม่เรียบร้อย', 'count' => $row['cabinet'] ?? 0]
            ];
            // เรียงลำดับจากมากไปน้อย
            usort($card_reasons, function($a, $b) { return $b['count'] <=> $a['count']; });
        }

        // สถิติเหตุผล/ประเภทการโดนกัก (ดึงจาก restrict_type)
        $restriction_reasons = [];
        $res_rest = $conn->query("SELECT restrict_type, COUNT(id) as times, SUM(weeks_count) as total_weeks FROM restrictions GROUP BY restrict_type ORDER BY times DESC LIMIT 5");
        if ($res_rest) {
            while ($row = $res_rest->fetch_assoc()) {
                $restriction_reasons[] = $row;
            }
        }
    }

    if ($view === 'cards') {
        // 10 อันดับนักเรียนที่โดนบัตรวินัยมากที่สุด
        $top_cards_students = [];
        $sql = "SELECT d.student_code, s.prefix, s.first_name, s.last_name, 
                       SUM(d.total_cards) as total_cards,
                       SUM(d.card_bed) as sum_bed, SUM(d.card_shoe) as sum_shoe, SUM(d.card_cabinet) as sum_cabinet
                FROM discipline_cards d 
                LEFT JOIN students s ON d.student_code = s.student_code 
                GROUP BY d.student_code 
                ORDER BY total_cards DESC LIMIT 10";
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) $top_cards_students[] = $row;
    }

    if ($view === 'restrictions') {
        // 10 อันดับนักเรียนที่โดนกักมากที่สุด
        $top_restrictions_students = [];
        $sql = "SELECT r.student_code, s.prefix, s.first_name, s.last_name, 
                       COUNT(r.id) as total_times, SUM(r.weeks_count) as total_weeks 
                FROM restrictions r 
                LEFT JOIN students s ON r.student_code = s.student_code 
                GROUP BY r.student_code 
                ORDER BY total_weeks DESC, total_times DESC LIMIT 10";
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) $top_restrictions_students[] = $row;
    }

    if ($view === 'votes') {
        // สถิติการลากลับบ้านประจำสัปดาห์ล่าสุด (ประเมินจากข้อมูลทั้งหมด)
        $vote_stats = [];
        $res = $conn->query("SELECT status, COUNT(id) as count FROM weekly_votes GROUP BY status ORDER BY count DESC");
        if ($res) while ($row = $res->fetch_assoc()) $vote_stats[] = $row;

        // สถิติวิธีการเดินทาง
        $travel_stats = [];
        $res2 = $conn->query("SELECT travel_method, COUNT(id) as count FROM weekly_votes WHERE travel_method IS NOT NULL AND travel_method != '' GROUP BY travel_method ORDER BY count DESC LIMIT 5");
        if ($res2) while ($row = $res2->fetch_assoc()) $travel_stats[] = $row;
    }

} catch (Throwable $e) {
    die('
    <div style="background:#fee2e2; border:1px solid #fecaca; padding:20px; margin:20px; border-radius:12px; font-family:\'Prompt\', sans-serif;">
        <h3 style="color:#991b1b; margin-top:0;"><i class="fas fa-exclamation-circle"></i> SQL/System Error</h3>
        <p style="color:#7f1d1d; font-size:0.9rem;"><strong>ข้อความ:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
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
      --primary: #8b5cf6; 
      --primary-hover: #7c3aed;
      --bg-color: #f8fafc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
      --danger: #ef4444;
      --warning: #f59e0b;
      --info: #0ea5e9;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #e2e8f0; margin: 0; display: flex; justify-content: center; }

    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

    .animate-slide-up { animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }
    .delay-1 { animation-delay: 0.1s; }
    .delay-2 { animation-delay: 0.2s; }
    .delay-3 { animation-delay: 0.3s; }

    .app-container { width: 100%; max-width: 430px; background-color: var(--bg-color); min-height: 100vh; padding-bottom: 100px; box-shadow: 0 0 20px rgba(0,0,0,0.05); position: relative; overflow-x: hidden; }

    .top-header { display: flex; align-items: center; padding: 20px 24px 10px; }
    .back-btn { font-size: 1.2rem; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-right: 15px; transition: 0.2s ease; }
    .page-title h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }

    /* Scrollable Tabs */
    .tabs-wrapper { margin: 10px 24px 0; overflow-x: auto; white-space: nowrap; -ms-overflow-style: none; scrollbar-width: none; border-radius: 12px; background: #e2e8f0; padding: 4px; display: flex; gap: 4px; }
    .tabs-wrapper::-webkit-scrollbar { display: none; }
    .tab-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; font-size: 0.85rem; font-weight: 600; color: var(--text-gray); border-radius: 10px; text-decoration: none; transition: 0.3s ease; }
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
    .list-header { background: #f8fafc; padding: 12px 15px; font-weight: 700; font-size: 0.9rem; color: var(--text-dark); border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .list-item { display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid #f1f5f9; transition: 0.2s; }
    .list-item:last-child { border-bottom: none; }
    
    .rank-num { width: 30px; height: 30px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; color: var(--text-dark); margin-right: 12px; flex-shrink: 0; }
    .rank-num.top-1 { background: #fef08a; color: #854d0e; }
    .rank-num.top-2 { background: #e2e8f0; color: #475569; }
    .rank-num.top-3 { background: #fed7aa; color: #9a3412; }
    
    .student-info { flex: 1; }
    .student-info .name { font-weight: 600; font-size: 0.9rem; color: var(--text-dark); margin: 0; }
    .student-info .code { font-size: 0.75rem; color: var(--text-gray); margin: 0; }
    .student-info .sub-details { font-size: 0.7rem; color: #94a3b8; margin-top: 2px; }
    
    .stat-value { font-weight: 700; font-size: 1.1rem; text-align: right; }
    .stat-value small { display: block; font-size: 0.7rem; font-weight: 400; color: var(--text-gray); }

    /* Reason Progress */
    .reason-item { margin-bottom: 12px; }
    .reason-header { display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 5px; color: var(--text-dark); font-weight: 500; }
    .progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 4px; }

    /* Bottom Nav */
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

    <!-- แถบเมนูแบบเลื่อนได้ (Scrollable) -->
    <div class="tabs-wrapper animate-slide-up delay-2">
        <a href="?view=overview" class="tab-btn <?= $view === 'overview' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> ภาพรวม</a>
        <a href="?view=cards" class="tab-btn <?= $view === 'cards' ? 'active' : '' ?>"><i class="fas fa-ticket-alt"></i> บัตรวินัย</a>
        <a href="?view=restrictions" class="tab-btn <?= $view === 'restrictions' ? 'active' : '' ?>"><i class="fas fa-user-lock"></i> คำสั่งกัก</a>
        <a href="?view=votes" class="tab-btn <?= $view === 'votes' ? 'active' : '' ?>"><i class="fas fa-home"></i> สถิติกลับบ้าน</a>
    </div>

    <div class="content-section animate-slide-up delay-3">
      
      <!-- ================= VIEW: ภาพรวม ================= -->
      <?php if ($view === 'overview'): ?>
        
        <div class="stats-grid">
            <div class="stat-box">
                <i class="fas fa-ticket-alt" style="color: var(--warning);"></i>
                <p class="num"><?= number_format($total_cards) ?></p>
                <p class="label">บัตรวินัย (ใบ)</p>
            </div>
            <div class="stat-box">
                <i class="fas fa-user-lock" style="color: var(--danger);"></i>
                <p class="num"><?= number_format($total_restrictions) ?></p>
                <p class="label">กักบริเวณ (สัปดาห์)</p>
            </div>
        </div>

        <!-- กราฟแท่ง: เหตุผลบัตรวินัย -->
        <div class="ranking-list">
            <div class="list-header"><i class="fas fa-list-ul"></i> สถิติความผิดบัตรวินัย</div>
            <div style="padding: 15px;">
                <?php 
                $max_card = (!empty($card_reasons) && $card_reasons[0]['count'] > 0) ? $card_reasons[0]['count'] : 1; 
                foreach ($card_reasons as $r): 
                    $pct = ($r['count'] / $max_card) * 100;
                ?>
                <div class="reason-item">
                    <div class="reason-header">
                        <span><?= htmlspecialchars($r['name']) ?></span>
                        <span><?= $r['count'] ?> ครั้ง</span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill" style="width: <?= $pct ?>%; background: var(--warning);"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- กราฟแท่ง: เหตุผลการกัก -->
        <div class="ranking-list">
            <div class="list-header"><i class="fas fa-user-slash"></i> ประเภทการกักบริเวณ</div>
            <div style="padding: 15px;">
                <?php if (empty($restriction_reasons)): ?>
                    <p style="text-align:center; color:#94a3b8; font-size:0.85rem; margin:0;">ยังไม่มีข้อมูลการกัก</p>
                <?php else: ?>
                    <?php 
                    $max_rest = $restriction_reasons[0]['times'] > 0 ? $restriction_reasons[0]['times'] : 1; 
                    foreach ($restriction_reasons as $r): 
                        $pct = ($r['times'] / $max_rest) * 100;
                    ?>
                    <div class="reason-item">
                        <div class="reason-header">
                            <span><?= htmlspecialchars($r['restrict_type'] ?: 'ไม่ระบุ') ?></span>
                            <span><?= $r['times'] ?> ครั้ง (รวม <?= $r['total_weeks'] ?> สัปดาห์)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?= $pct ?>%; background: var(--danger);"></div></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

      <!-- ================= VIEW: บัตรวินัย ================= -->
      <?php elseif ($view === 'cards'): ?>

        <div class="ranking-list">
            <div class="list-header" style="background:#fffbeb; color:#b45309;">
                <span><i class="fas fa-trophy"></i> 10 อันดับนักเรียน (บัตรวินัย)</span>
                <span>จำนวนใบ</span>
            </div>
            
            <?php if (empty($top_cards_students)): ?>
                <div style="padding: 20px; text-align:center; color:#94a3b8;">ยังไม่มีประวัติบัตรวินัย</div>
            <?php else: ?>
                <?php 
                $rank = 1;
                foreach ($top_cards_students as $stu): 
                    $rankClass = $rank <= 3 ? "top-{$rank}" : "";
                    $name = trim($stu['prefix'] . $stu['first_name'] . ' ' . $stu['last_name']);
                    if(empty(trim($stu['first_name']))) $name = "ไม่ทราบชื่อ";
                ?>
                <div class="list-item">
                    <div class="rank-num <?= $rankClass ?>"><?= $rank ?></div>
                    <div class="student-info">
                        <p class="name"><?= htmlspecialchars($name) ?></p>
                        <p class="code">รหัส: <?= htmlspecialchars($stu['student_code']) ?></p>
                        <p class="sub-details">
                            เตียง: <?= $stu['sum_bed'] ?? 0 ?> | รองเท้า: <?= $stu['sum_shoe'] ?? 0 ?> | ตู้: <?= $stu['sum_cabinet'] ?? 0 ?>
                        </p>
                    </div>
                    <div class="stat-value" style="color: var(--warning);">
                        <?= $stu['total_cards'] ?>
                        <small>ใบ</small>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>
            <?php endif; ?>
        </div>

      <!-- ================= VIEW: คำสั่งกัก ================= -->
      <?php elseif ($view === 'restrictions'): ?>

        <div class="ranking-list">
            <div class="list-header" style="background:#fef2f2; color:#b91c1c;">
                <span><i class="fas fa-trophy"></i> 10 อันดับนักเรียน (โดนกัก)</span>
                <span>รวมสัปดาห์</span>
            </div>
            
            <?php if (empty($top_restrictions_students)): ?>
                <div style="padding: 20px; text-align:center; color:#94a3b8;">ยังไม่มีประวัติการกัก</div>
            <?php else: ?>
                <?php 
                $rank = 1;
                foreach ($top_restrictions_students as $stu): 
                    $rankClass = $rank <= 3 ? "top-{$rank}" : "";
                    $name = trim($stu['prefix'] . $stu['first_name'] . ' ' . $stu['last_name']);
                    if(empty(trim($stu['first_name']))) $name = "ไม่ทราบชื่อ";
                ?>
                <div class="list-item">
                    <div class="rank-num <?= $rankClass ?>"><?= $rank ?></div>
                    <div class="student-info">
                        <p class="name"><?= htmlspecialchars($name) ?></p>
                        <p class="code">รหัส: <?= htmlspecialchars($stu['student_code']) ?></p>
                        <p class="sub-details">โดนกักทั้งหมด <?= $stu['total_times'] ?> ครั้ง</p>
                    </div>
                    <div class="stat-value" style="color: var(--danger);">
                        <?= $stu['total_weeks'] ?>
                        <small>สัปดาห์</small>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>
            <?php endif; ?>
        </div>

      <!-- ================= VIEW: การลากลับบ้าน ================= -->
      <?php elseif ($view === 'votes'): ?>
        
        <div class="ranking-list">
            <div class="list-header" style="background:#f0f9ff; color:#0369a1;">
                <span><i class="fas fa-clipboard-list"></i> ภาพรวมการแจ้งสถานะ (Weekly Votes)</span>
            </div>
            <div style="padding: 15px;">
                <?php if (empty($vote_stats)): ?>
                    <p style="text-align:center; color:#94a3b8; font-size:0.85rem; margin:0;">ไม่มีข้อมูล</p>
                <?php else: ?>
                    <?php foreach ($vote_stats as $st): ?>
                    <div class="list-item" style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0;">
                        <span style="flex:1; font-weight:500; color:var(--text-dark);"><?= htmlspecialchars($st['status'] ?: 'ไม่ระบุ') ?></span>
                        <span style="font-weight:700; color:var(--info);"><?= $st['count'] ?> นาย</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="ranking-list">
            <div class="list-header"><i class="fas fa-bus"></i> 5 อันดับรูปแบบการเดินทาง</div>
            <div style="padding: 15px;">
                <?php if (empty($travel_stats)): ?>
                    <p style="text-align:center; color:#94a3b8; font-size:0.85rem; margin:0;">ไม่มีข้อมูลรูปแบบการเดินทาง</p>
                <?php else: ?>
                    <?php 
                    $max_travel = $travel_stats[0]['count'] > 0 ? $travel_stats[0]['count'] : 1; 
                    foreach ($travel_stats as $t): 
                        $pct = ($t['count'] / $max_travel) * 100;
                    ?>
                    <div class="reason-item">
                        <div class="reason-header">
                            <span><?= htmlspecialchars($t['travel_method']) ?></span>
                            <span><?= $t['count'] ?> นาย</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?= $pct ?>%; background: var(--info);"></div></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
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
