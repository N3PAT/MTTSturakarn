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
$total_bed = 0;
$total_cabinet = 0;
$total_shoe = 0;
$student = [];

/* ---------- helper functions ---------- */
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// แปลงเลขอารบิกเป็นเลขไทย
function thaiNum($v) {
    return strtr((string)$v, [
        '0' => '๐', '1' => '๑', '2' => '๒', '3' => '๓', '4' => '๔',
        '5' => '๕', '6' => '๖', '7' => '๗', '8' => '๘', '9' => '๙',
    ]);
}

// ดึงเฉพาะตัวเลขออกมา เช่น "ร้อย 1" -> "1" (ถ้าไม่มีตัวเลขจะคืนค่าเดิม)
function numOnly($v) {
    return preg_match('/\d+/', (string)$v, $m) ? $m[0] : (string)$v;
}

// ดึงค่าจาก array โดยลองหลายชื่อคอลัมน์ตามลำดับ
function pick(array $arr, array $keys, $default = '') {
    foreach ($keys as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
    }
    return $default;
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

/* ---------- ประวัติบัตรวินัย ---------- */
try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection error");
    }
    $conn->set_charset("utf8mb4");

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
            $total_bed     += (int)$row['card_bed'];
            $total_cabinet += (int)$row['card_cabinet'];
            $total_shoe    += (int)$row['card_shoe'];
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log("System error in discipline history: " . $e->getMessage());
}

/* ---------- ข้อมูลนักเรียนสำหรับหน้าบัตร ----------
   ปรับชื่อตาราง/คอลัมน์ให้ตรงกับฐานข้อมูลของคุณ */
try {
    if (isset($conn) && $conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT * FROM students WHERE student_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $studentCode);
            $stmt->execute();
            $r = $stmt->get_result();
            $student = $r->fetch_assoc() ?: [];
            $stmt->close();
        }
    }
} catch (Throwable $e) {
    error_log("Cannot load student info: " . $e->getMessage());
}

$fullName = trim(pick($student, ['first_name']) . ' ' . pick($student, ['last_name']));
if ($fullName === '') {
    $fullName = $_SESSION['student_name'] ?? $studentCode;
}

$platoon = numOnly(pick($student, ['platoon']));   // หมวด
$company = numOnly(pick($student, ['company']));   // ร้อย
$batch   = 67;                                     // รุ่นที่ (ตาราง students ยังไม่มีคอลัมน์รุ่น)

// สีพื้นหลังบัตรตามร้อย (ร้อย 1 = ชมพู, ร้อย 2 = ฟ้า)
$cardColors = [
    1 => '#e8b4da',
    2 => '#b4c7e8',
];
$cardBg = $cardColors[(int)$company] ?? '#e2e8f0';
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
      padding-bottom: 100px;
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

    /* ================= บัตรวินัย (พลิกได้) ================= */
    .flip-card {
      margin: 22px 20px 8px;
      perspective: 1200px;
      cursor: pointer;
      outline: none;
    }

    .flip-inner {
      position: relative;
      width: 100%;
      aspect-ratio: 490 / 265;
      transition: transform 0.7s cubic-bezier(.4, .2, .2, 1);
      transform-style: preserve-3d;
    }

    .flip-card.flipped .flip-inner { transform: rotateY(180deg); }

    .flip-card:focus-visible .flip-inner {
      outline: 3px solid var(--primary);
      outline-offset: 3px;
      border-radius: 14px;
    }

    .face {
      position: absolute;
      inset: 0;
      background: var(--card-bg);
      border-radius: 14px;
      border: 1px solid rgba(0,0,0,0.08);
      box-shadow: 0 8px 20px rgba(30, 41, 59, 0.18);
      padding: 4.5% 5%;
      color: #1f2937;
      backface-visibility: hidden;
      -webkit-backface-visibility: hidden;
      isolation: isolate;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .face.back { transform: rotateY(180deg); }

    /* ----- ด้านหน้า ----- */
    .card-logo {
      position: absolute;
      top: 6%;
      right: 5%;
      width: 22%;
    }
    /* multiply ทำให้พื้นขาวของไฟล์ JPG กลืนไปกับสีพื้นบัตร */
    .card-logo img {
      width: 100%;
      height: auto;
      display: block;
      mix-blend-mode: multiply;
    }

    .card-title {
      text-align: center;
      font-size: clamp(1.25rem, 6.2vw, 1.7rem);
      font-weight: 400;
      line-height: 1.2;
      margin-top: 1%;
    }

    .card-row {
      font-size: clamp(0.72rem, 3.7vw, 0.95rem);
      line-height: 1.5;
    }
    .card-row b { font-weight: 700; }

    .name-row {
      display: flex;
      align-items: baseline;
      gap: 10px;
    }
    .name-row .lbl { white-space: nowrap; }
    .name-row .name {
      flex: 1;
      text-align: center;
      font-size: clamp(0.85rem, 4.4vw, 1.15rem);
    }

    /* ----- ด้านหลัง ----- */
    .back-title {
      text-align: center;
      font-size: clamp(0.85rem, 4.2vw, 1.05rem);
      font-weight: 500;
    }

    .back-total {
      text-align: center;
      line-height: 1;
    }
    .back-total .num {
      font-size: clamp(2.6rem, 14vw, 3.6rem);
      font-weight: 700;
      color: #b91c1c;
    }
    .back-total .unit {
      font-size: clamp(0.9rem, 4.4vw, 1.1rem);
      margin-left: 4px;
    }

    .back-breakdown {
      display: flex;
      justify-content: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .chip {
      background: rgba(255,255,255,0.65);
      border-radius: 999px;
      padding: 3px 11px;
      font-size: clamp(0.65rem, 3.3vw, 0.8rem);
      font-weight: 500;
      white-space: nowrap;
    }
    .chip b { margin-left: 3px; color: #b91c1c; }

    .back-hint {
      text-align: center;
      font-size: 0.65rem;
      color: rgba(31, 41, 55, 0.6);
    }

    .flip-hint {
      text-align: center;
      font-size: 0.78rem;
      color: var(--text-gray);
      margin: 10px 20px 4px;
    }
    .flip-hint i { margin-right: 4px; }

    @media (prefers-reduced-motion: reduce) {
      .flip-inner { transition-duration: 0.01s; }
    }

    /* ================= รายการประวัติ ================= */
    .section-title {
      padding: 14px 20px 10px;
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--text-dark);
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

    <!-- บัตรวินัย: แตะเพื่อพลิกดูจำนวนบัตรที่โดนทั้งหมด -->
    <div class="flip-card" id="flipCard" style="--card-bg: <?= h($cardBg) ?>;"
         role="button" tabindex="0" aria-label="บัตรวินัย แตะเพื่อพลิกดูจำนวนบัตรที่โดนทั้งหมด">
      <div class="flip-inner">

        <!-- ด้านหน้า -->
        <div class="face front">
          <div class="card-logo">
            <img src="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg" alt="MTTS" referrerpolicy="no-referrer">
          </div>

          <div class="card-title">บัตรวินัย</div>

          <div class="card-row name-row">
            <span class="lbl">นชท.</span>
            <b class="name"><?= h($fullName) ?></b>
          </div>

          <div class="card-row">
            หมวด <b><?= h(thaiNum($platoon)) ?></b>
            ร้อย <b><?= h(thaiNum($company)) ?></b>
            เลขประจำตัว <b><?= h(thaiNum($studentCode)) ?></b>
            รุ่นที่ <b><?= h(thaiNum($batch)) ?></b>
          </div>
        </div>

        <!-- ด้านหลัง -->
        <div class="face back">
          <div class="back-title">จำนวนบัตรวินัยที่โดนทั้งหมด</div>

          <div class="back-total">
            <span class="num"><?= h(thaiNum($total_cards_all_time)) ?></span><span class="unit">ใบ</span>
          </div>

          <div class="back-breakdown">
            <span class="chip"><i class="fas fa-bed"></i> เตียง <b><?= h(thaiNum($total_bed)) ?></b></span>
            <span class="chip"><i class="fas fa-archive"></i> ตู้ <b><?= h(thaiNum($total_cabinet)) ?></b></span>
            <span class="chip"><i class="fas fa-shoe-prints"></i> รองเท้า <b><?= h(thaiNum($total_shoe)) ?></b></span>
          </div>

          <div class="back-hint"><i class="fas fa-rotate"></i> แตะเพื่อพลิกกลับ</div>
        </div>

      </div>
    </div>
    <p class="flip-hint"><i class="fas fa-rotate"></i> แตะบัตรเพื่อดูจำนวนบัตรที่โดนทั้งหมด</p>

    <div class="section-title">รายการที่โดนตัดบัตร</div>

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
        <i class="fa-solid fa-address-card"></i>
        <span>ประวัติบัตรวินัย</span>
      </a>

    </div>

  </div>

  <script>
    (function () {
      var card = document.getElementById('flipCard');
      var toggle = function () { card.classList.toggle('flipped'); };
      card.addEventListener('click', toggle);
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggle();
        }
      });
    })();
  </script>

</body>
</html>
