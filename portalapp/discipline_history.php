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

function thaiNum($v) {
    return (string)$v;
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

// รายการที่โดนตัดบัตรจริง (ข้ามวันที่ไม่โดน)
$visible_records = array_values(array_filter($history_records, function ($rec) {
    return (int)$rec['total_cards'] > 0;
}));
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#503896" />
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>ประวัติบัตรวินัย - MTTS</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root{--primary:#503896;--bar:#503896;--bg:#f2f3f8;--ink:#1c1b29;--mute:#6b7280;--danger:#dc2626;--g:16px;--nav:64px;--st:env(safe-area-inset-top,0px);--sb:env(safe-area-inset-bottom,0px);--shadow:0 1px 2px rgba(20,20,43,.04),0 2px 8px rgba(20,20,43,.04)}

    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;margin:0;padding:0}
    html{-webkit-text-size-adjust:100%}
    body{font-family:'Prompt',sans-serif;background:#d9dce6;color:var(--ink);display:flex;justify-content:center;min-height:100vh;min-height:100dvh;-webkit-user-select:none;user-select:none;overscroll-behavior-y:none;touch-action:manipulation;-webkit-font-smoothing:antialiased}
    ::-webkit-scrollbar{display:none}

    .app-container{width:100%;max-width:430px;min-height:100vh;min-height:100dvh;background:var(--bg);padding-bottom:calc(var(--nav) + var(--sb) + 16px)}
    @media(min-width:431px){.app-container{box-shadow:0 0 40px rgba(15,23,42,.14)}}

    /* app bar */
    .top-header{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:12px;padding:calc(10px + var(--st)) var(--g) 10px;background:rgba(80,56,150,.94);-webkit-backdrop-filter:saturate(180%) blur(14px);backdrop-filter:saturate(180%) blur(14px);color:#fff}
    .back-btn{display:flex;align-items:center;justify-content:center;flex:none;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.05rem;text-decoration:none;transition:transform .12s}
    .back-btn:active{transform:scale(.92)}
    .header-title h1{font-size:1.05rem;font-weight:600}

    /* ================= บัตรวินัย (พลิกได้) ================= */
    .flip-card{margin:18px var(--g) 8px;perspective:1200px;cursor:pointer;outline:none}

    .flip-inner{position:relative;width:100%;aspect-ratio:490 / 265;transition:transform .7s cubic-bezier(.4,.2,.2,1);transform-style:preserve-3d}
    .flip-card.flipped .flip-inner{transform:rotateY(180deg)}
    .flip-card:focus-visible .flip-inner{outline:3px solid var(--primary);outline-offset:3px;border-radius:14px}

    .face{position:absolute;inset:0;background:var(--card-bg);border-radius:14px;border:1px solid rgba(0,0,0,.08);box-shadow:0 6px 16px rgba(30,41,59,.16);padding:4.5% 5%;color:#1f2937;backface-visibility:hidden;-webkit-backface-visibility:hidden;isolation:isolate;display:flex;flex-direction:column;justify-content:space-between}
    .face.back{transform:rotateY(180deg)}

    /* ----- ด้านหน้า ----- */
    .card-logo{position:absolute;top:6%;right:5%;width:12%}
    /* multiply ทำให้พื้นขาวของไฟล์ JPG กลืนไปกับสีพื้นบัตร */
    .card-logo img{width:100%;height:auto;display:block;mix-blend-mode:multiply}
    .card-title{text-align:center;font-size:clamp(1.25rem,6.2vw,1.7rem);font-weight:400;line-height:1.2;margin-top:1%}
    .card-row{font-size:clamp(.72rem,3.7vw,.95rem);line-height:1.5}
    .card-row b{font-weight:700}
    .name-row{display:flex;align-items:baseline;gap:10px}
    .name-row .lbl{white-space:nowrap}
    .name-row .name{flex:1;text-align:center;font-size:clamp(.85rem,4.4vw,1.15rem)}

    /* ----- ด้านหลัง ----- */
    .back-title{text-align:center;font-size:clamp(.85rem,4.2vw,1.05rem);font-weight:500}
    .back-total{text-align:center;line-height:1}
    .back-total .num{font-size:clamp(2.6rem,14vw,3.6rem);font-weight:700;color:#b91c1c}
    .back-total .unit{font-size:clamp(.9rem,4.4vw,1.1rem);margin-left:4px}
    .back-breakdown{display:flex;justify-content:center;gap:8px;flex-wrap:wrap}
    .chip{background:rgba(255,255,255,.65);border-radius:999px;padding:3px 11px;font-size:clamp(.65rem,3.3vw,.8rem);font-weight:500;white-space:nowrap}
    .chip b{margin-left:3px;color:#b91c1c}
    .back-hint{text-align:center;font-size:.65rem;color:rgba(31,41,55,.6)}

    .flip-hint{text-align:center;font-size:.76rem;color:var(--mute);margin:10px var(--g) 4px}
    .flip-hint i{margin-right:4px}

    @media(prefers-reduced-motion:reduce){.flip-inner{transition-duration:.01s}}

    /* ================= รายการประวัติ: grouped list ================= */
    .section-title{margin:16px calc(var(--g) + 4px) 8px;font-size:.8rem;font-weight:600;letter-spacing:.02em;color:var(--mute)}

    .history-list{margin:0 var(--g);border-radius:20px;background:#fff;box-shadow:var(--shadow);overflow:hidden}
    .history-card{display:flex;align-items:center;gap:14px;padding:14px}
    .history-card+.history-card{border-top:1px solid #eef0f5}

    .date-box{flex:none;width:56px;padding:9px 0;border-radius:14px;background:#efeaf9;text-align:center}
    .date-box .day{font-size:1.2rem;font-weight:700;color:var(--primary);line-height:1}
    .date-box .month{margin-top:3px;font-size:.62rem;font-weight:500;color:var(--mute)}

    .detail-box{flex:1;min-width:0}
    .detail-box h4{display:flex;align-items:center;gap:8px;font-size:.92rem;font-weight:600}
    .dot{flex:none;width:9px;height:9px;border-radius:50%}
    .status-red{background:#dc2626}
    .status-yellow{background:#eab308}
    .status-green{background:#22c55e}

    .tag-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}
    .tag{padding:3px 10px;border-radius:8px;background:#f1f2f8;color:#4b5563;font-size:.7rem;font-weight:500}

    .empty-card{margin:0 var(--g);padding:36px 20px;border-radius:20px;background:#fff;box-shadow:var(--shadow);text-align:center;color:var(--mute)}
    .empty-card i{margin-bottom:12px;font-size:2.4rem;color:#c3c7d4}
    .empty-card h3{margin-bottom:4px;font-size:1rem;color:var(--ink)}
    .empty-card p{font-size:.8rem}

    /* bottom nav สีม่วง */
    .bottom-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:1000;width:100%;max-width:430px;height:calc(var(--nav) + var(--sb));padding-bottom:var(--sb);display:flex;align-items:center;background:var(--bar);box-shadow:0 -4px 18px rgba(20,20,43,.18)}
    .nav-link{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;color:rgba(255,255,255,.6);font-size:.7rem;font-weight:500;text-decoration:none}
    .nav-link i{display:grid;place-items:center;width:56px;height:30px;border-radius:15px;font-size:1.2rem;transition:background .2s}
    .nav-link.active{color:#fff;font-weight:600}
    .nav-link.active i{background:rgba(255,255,255,.2)}
    .nav-link:active{color:#fff}
  </style>
</head>
<body>

  <div class="app-container">
    
    <header class="top-header">
      <a href="main.php" class="back-btn" aria-label="กลับหน้าหลัก"><i class="fas fa-chevron-left"></i></a>
      <div class="header-title">
        <h1>ประวัติบัตรวินัย</h1>
      </div>
    </header>

    <main>
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

    <?php if (!empty($visible_records)): ?>
      <div class="history-list">
        <?php foreach ($visible_records as $record): 
            $tot = (int)$record['total_cards'];

            $color_class = 'status-green';
            if ($record['status_color'] == 'red' || $tot >= 3) {
                $color_class = 'status-red';
            } elseif ($record['status_color'] == 'yellow' || $tot == 2) {
                $color_class = 'status-yellow';
            }

            $date_parts = explode(" ", formatThaiDate($record['check_date']));
        ?>
          <div class="history-card">
            <div class="date-box">
              <div class="day"><?= h($date_parts[0]) ?></div>
              <div class="month"><?= h($date_parts[1] . ' ' . $date_parts[2]) ?></div>
            </div>
            
            <div class="detail-box">
              <h4><span class="dot <?= $color_class ?>"></span>โดนตัด <?= $tot ?> ใบ</h4>
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
      </div>
    <?php elseif (empty($history_records)): ?>
      <div class="empty-card">
        <i class="fas fa-shield-alt"></i>
        <h3>ดีมาก</h3>
        <p>คุณยังไม่มีประวัติการโดนตัดบัตรวินัย</p>
      </div>
    <?php else: ?>
      <div class="empty-card">
        <i class="fas fa-shield-alt"></i>
        <h3>ดีมาก</h3>
        <p>คุณมีประวัติการตรวจ แต่ยังไม่เคยโดนตัดบัตรวินัย</p>
      </div>
    <?php endif; ?>
    </main>
    
  </div>

  <!-- Bottom Navigation -->
  <nav class="bottom-nav" aria-label="เมนูหลัก">
    <a href="main.php" class="nav-link">
      <i class="fas fa-home"></i>
      <span>หน้าหลัก</span>
    </a>
    
    <a href="discipline_history.php" class="nav-link active">
      <i class="fa-solid fa-address-card"></i>
      <span>ประวัติบัตรวินัย</span>
    </a>
  </nav>

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
