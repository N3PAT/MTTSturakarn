<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['student_code'])) {
    header("Location: login.php");
    exit();
}

$studentCode = $_SESSION['student_code'];
$history_records = [];
$total_cards_all_time = 0;

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection error");
    }
    $conn->set_charset("utf8mb4");

    // ดึงประวัติบัตรวินัยทั้งหมดของนักเรียนคนนี้เรียงจากล่าสุด
    $stmt = $conn->prepare("
        SELECT check_date, card_bed, card_shoe, card_cabinet, total_cards, status_color 
        FROM discipline_cards 
        WHERE student_code = ? 
        ORDER BY check_date DESC
    ");
    if ($stmt) {
        $stmt->bind_param("s", $studentCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $history_records[] = $row;
            $total_cards_all_time += (int)$row['total_cards'];
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log("System error in discipline history: " . $e->getMessage());
}

// ฟังก์ชันแปลงวันที่เป็นภาษาไทย
function formatThaiDate($dateStr) {
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $timestamp = strtotime($dateStr);
    $d = date('j', $timestamp);
    $m = $months[date('n', $timestamp)];
    $y = date('Y', $timestamp) + 543;
    return "$d $m $y";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ประวัติบัตรวินัย - MTTS</title>
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
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
      padding-bottom: 100px; /* ปรับเพิ่มเพื่อไม่ให้โดน bottom nav บัง */
    }

    .top-header {
      display: flex;
      align-items: center;
      padding: 15px 20px;
      background: white;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .back-btn {
      font-size: 1.2rem;
      color: var(--text-dark);
      text-decoration: none;
      margin-right: 15px;
      width: 35px;
      height: 35px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: var(--bg-color);
    }

    .header-title h1 {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text-dark);
      margin: 0;
    }

    .summary-card {
      background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
      border-radius: 16px;
      padding: 20px;
      margin: 20px;
      color: white;
      text-align: center;
      box-shadow: 0 10px 20px rgba(220, 38, 38, 0.2);
    }

    .summary-card h2 {
      font-size: 2.5rem;
      margin: 0 0 5px 0;
      font-weight: 700;
    }

    .summary-card p {
      margin: 0;
      font-size: 0.9rem;
      opacity: 0.9;
    }

    .history-container {
      padding: 0 20px;
    }

    .history-card {
      background: white;
      border-radius: 12px;
      padding: 15px;
      margin-bottom: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      display: flex;
      align-items: flex-start;
      gap: 15px;
      position: relative;
      overflow: hidden;
    }

    .status-indicator {
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 5px;
    }

    .status-red { background-color: #dc2626; }
    .status-yellow { background-color: #eab308; }
    .status-green { background-color: #22c55e; }

    .date-box {
      background: var(--bg-color);
      padding: 8px 12px;
      border-radius: 8px;
      text-align: center;
      min-width: 65px;
    }

    .date-box .day {
      font-size: 1.2rem;
      font-weight: 700;
      color: var(--primary);
      line-height: 1;
    }

    .date-box .month {
      font-size: 0.7rem;
      color: var(--text-gray);
      margin-top: 2px;
    }

    .detail-box {
      flex: 1;
    }

    .detail-box h4 {
      margin: 0 0 4px 0;
      font-size: 0.95rem;
      color: var(--text-dark);
    }

    .tag-list {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 6px;
    }

    .tag {
      font-size: 0.7rem;
      padding: 3px 8px;
      border-radius: 6px;
      background: #f1f5f9;
      color: #475569;
      font-weight: 500;
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: var(--text-gray);
    }

    .empty-state i {
      font-size: 3rem;
      color: #cbd5e1;
      margin-bottom: 15px;
    }

    /* Bottom Nav CSS */
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
  </style>
</head>
<body>

  <div class="app-container">
    
    <div class="top-header">
      <a href="main.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="header-title">
        <h1>ประวัติบัตรวินัย</h1>
      </div>
    </div>

    <div class="summary-card">
      <p>ยอดสะสมบัตรวินัยทั้งหมด</p>
      <h2><?= $total_cards_all_time ?></h2>
      <p>ใบ</p>
    </div>

    <div class="history-container">
      <?php if (!empty($history_records)): ?>
        <?php foreach ($history_records as $record): 
            $tot = (int)$record['total_cards'];
            if ($tot == 0) continue; // ข้ามวันที่ไม่โดนตัดบัตร

            $color_class = 'status-green';
            if ($record['status_color'] == 'red' || $tot >= 3) {
                $color_class = 'status-red';
            } elseif ($record['status_color'] == 'yellow' || $tot == 2) {
                $color_class = 'status-yellow';
            }

            $date_parts = explode(" ", formatThaiDate($record['check_date']));
        ?>
          <div class="history-card">
            <div class="status-indicator <?= $color_class ?>"></div>
            
            <div class="date-box">
              <div class="day"><?= $date_parts[0] ?></div>
              <div class="month"><?= $date_parts[1] . ' ' . $date_parts[2] ?></div>
            </div>
            
            <div class="detail-box">
              <h4>โดนตัด <?= $tot ?> ใบ</h4>
              <div class="tag-list">
                <?php if ($record['card_bed']): ?>
                  <span class="tag"><i class="fas fa-bed"></i> เตียง</span>
                <?php endif; ?>
                <?php if ($record['card_cabinet']): ?>
                  <span class="tag"><i class="fas fa-archive"></i> ตู้</span>
                <?php endif; ?>
                <?php if ($record['card_shoe']): ?>
                  <span class="tag"><i class="fas fa-shoe-prints"></i> รองเท้า</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-shield-alt"></i>
          <h3>ยอดเยี่ยมมาก!</h3>
          <p>คุณยังไม่มีประวัติการโดนตัดบัตรวินัย</p>
        </div>
      <?php endif; ?>
      
      <?php if (!empty($history_records) && $total_cards_all_time == 0): ?>
        <div class="empty-state">
          <i class="fas fa-shield-alt"></i>
          <h3>ยอดเยี่ยมมาก!</h3>
          <p>คุณมีประวัติการตรวจ แต่ยังไม่เคยโดนตัดบัตรวินัย</p>
        </div>
      <?php endif; ?>
    </div>
    
    <!-- Bottom Navigation -->
    <div class="bottom-nav">
      <a href="main.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
      </a>
      
      <a href="discipline_history.php" class="nav-link active">
        <i class="fa-solid fa-cards"></i>
        <span>ประวัติบัตรวินัย</span>
      </a>

      <a href="logout.php" class="nav-link">
        <i class="far fa-user"></i>
        <span>ออกจากระบบ</span>
      </a>
    </div>

  </div>

</body>
</html>
