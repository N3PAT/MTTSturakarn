<?php
session_start();
require_once 'db.php';

// สมมติว่ามีระบบเช็คสิทธิ์ Admin
if (empty($_SESSION['admin_id'])) {
      header("Location: admin_login.php");
      exit();
 }

// สร้างโฟลเดอร์สำหรับเก็บไฟล์อัปโหลดถ้ายังไม่มี
$upload_dir = 'uploads/announcements/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// -------------------------------------------------------------
// 1. จัดการการเพิ่มประกาศใหม่ (POST Request)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $attachment_path = null;

    if (!empty($title) && !empty($content)) {
        try {
            // --- จัดการอัปโหลดไฟล์ ---
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['attachment']['tmp_name'];
                $file_name = $_FILES['attachment']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                // นามสกุลไฟล์ที่อนุญาต
                $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                
                if (in_array($file_ext, $allowed_exts)) {
                    $new_filename = uniqid('news_') . '.' . $file_ext;
                    $dest_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($file_tmp, $dest_path)) {
                        $attachment_path = $dest_path;
                    } else {
                        throw new Exception("ไม่สามารถบันทึกไฟล์แนบได้");
                    }
                } else {
                    throw new Exception("รองรับเฉพาะไฟล์รูปภาพ, PDF, Word และ Excel เท่านั้น");
                }
            }

            // 1.1 บันทึกลงตาราง announcements (เพิ่ม $attachment_path)
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, status, attachment) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $title, $content, $status, $attachment_path);
            $stmt->execute();
            $stmt->close();
            
            // 1.2 บันทึกลงตาราง notifications เพื่อให้ไปโชว์ในระบบการแจ้งเตือนบนหน้าเว็บ
            $noti_stmt = $conn->prepare("INSERT INTO notifications (title, message, type, alert_level) VALUES (?, ?, 'news', 'info')");
            $noti_stmt->bind_param("ss", $title, $content);
            $noti_stmt->execute();
            $noti_stmt->close();

            // 1.3 ถ้าสถานะเป็น Active ให้ส่ง Web Push Notification เข้ามือถือผู้ใช้ทุกคน
            if ($status === 'active') {
                $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
                if (!file_exists($autoloadPath)) {
                    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
                }
                if (!file_exists($autoloadPath)) {
                    $autoloadPath = __DIR__ . '/vendor/autoload.php';
                }

                if (file_exists($autoloadPath)) {
                    require_once $autoloadPath;
                    if (class_exists('Minishlink\WebPush\WebPush')) {
                        $auth = [
                            'VAPID' => [
                                'subject'    => 'mailto:natthapatinsangchan@gmail.com',
                                'publicKey'  => 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA',
                                'privateKey' => 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs',
                            ],
                        ];
                        $webPush = new \Minishlink\WebPush\WebPush($auth);
                        $sub_res = $conn->query("SELECT id, endpoint, p256dh, auth FROM push_subscriptions");
                        
                        if ($sub_res && $sub_res->num_rows > 0) {
                            $short_content = mb_substr($content, 0, 90, 'UTF-8') . (mb_strlen($content, 'UTF-8') > 90 ? '...' : '');
                            
                            $payload = json_encode([
                                'title' => '📢 ' . $title,
                                'body'  => $short_content,
                                'icon'  => 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg',
                                'url'   => '/portalapp/main.php'
                            ], JSON_UNESCAPED_UNICODE);

                            $failedIds = [];
                            while ($sub_row = $sub_res->fetch_assoc()) {
                                $subscription = \Minishlink\WebPush\Subscription::create([
                                    'endpoint'  => $sub_row['endpoint'],
                                    'publicKey' => $sub_row['p256dh'],
                                    'authToken' => $sub_row['auth'],
                                ]);
                                $report = $webPush->sendOneNotification($subscription, $payload);
                                if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                                    $failedIds[] = $sub_row['id'];
                                }
                            }
                            if (!empty($failedIds)) {
                                $idsList = implode(',', array_map('intval', $failedIds));
                                $conn->query("DELETE FROM push_subscriptions WHERE id IN ($idsList)");
                            }
                        }
                    }
                }
            }
            header("Location: admin_announcements.php?success=1");
            exit();
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
        }
    } else {
        $error_msg = "กรุณากรอกหัวข้อและเนื้อหาประกาศให้ครบถ้วน";
    }
}

// -------------------------------------------------------------
// 2. จัดการการลบประกาศ (GET Request)
// -------------------------------------------------------------
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    try {
        // ค้นหาไฟล์แนบเพื่อลบออกจากเซิร์ฟเวอร์
        $stmt_file = $conn->prepare("SELECT attachment FROM announcements WHERE id = ?");
        $stmt_file->bind_param("i", $del_id);
        $stmt_file->execute();
        $res_file = $stmt_file->get_result();
        if ($row_file = $res_file->fetch_assoc()) {
            if (!empty($row_file['attachment']) && file_exists($row_file['attachment'])) {
                unlink($row_file['attachment']); // ลบไฟล์
            }
        }
        $stmt_file->close();

        // ลบข้อมูลจาก Database
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        $stmt->execute();
        $stmt->close();
        
        header("Location: admin_announcements.php?deleted=1");
        exit();
    } catch (Exception $e) {
        $error_msg = "ไม่สามารถลบข้อมูลได้";
    }
}

// -------------------------------------------------------------
// 3. ดึงข้อมูลประกาศทั้งหมดมาแสดง (เพิ่มคอลัมน์ attachment)
// -------------------------------------------------------------
$announcements = [];
try {
    $sql = "SELECT id, title, content, status, attachment, created_at FROM announcements ORDER BY created_at DESC";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $announcements[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching announcements: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>จัดการประกาศ - Admin</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  
  <style>
    :root {
      --primary: #5a42a0;
      --bg-color: #f6f8fc;
      --text-dark: #1e293b;
      --text-gray: #64748b;
      --danger: #ef4444;
      --success: #10b981;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    
    body {
      font-family: 'Prompt', sans-serif;
      background-color: #e2e8f0;
      margin: 0;
      display: flex;
      justify-content: center;
    }
    .app-container {
      width: 100%;
      max-width: 430px;
      background-color: var(--bg-color);
      min-height: 100vh;
      position: relative;
      padding-bottom: 90px;
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }
    .top-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 15px 20px;
      background: white;
      box-shadow: 0 2px 10px rgba(0,0,0,0.02);
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .header-left h1 {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--primary);
      margin: 0;
    }
    .header-left p {
      font-size: 0.75rem;
      color: var(--text-gray);
      margin: 0;
    }
    .section-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 20px 20px 15px;
    }
    .section-pill {
      width: 5px;
      height: 24px;
      background: var(--primary);
      border-radius: 4px;
    }
    .section-title h3 {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-dark);
      margin: 0;
    }
    .form-card {
      background: white;
      border-radius: 16px;
      padding: 20px;
      margin: 0 20px 20px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    }
    .form-group { margin-bottom: 15px; }
    .form-label {
      display: block;
      margin-bottom: 6px;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-dark);
    }
    .form-control {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-family: 'Prompt', sans-serif;
      font-size: 0.9rem;
      transition: all 0.2s;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(90, 66, 160, 0.1);
    }
    .form-control-file {
      padding: 8px 10px;
      background: #f8fafc;
    }
    textarea.form-control {
      resize: vertical;
      min-height: 80px;
    }
    .btn-submit {
      width: 100%;
      padding: 12px;
      background: var(--primary);
      color: white;
      border: none;
      border-radius: 8px;
      font-family: 'Prompt', sans-serif;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 4px 10px rgba(90, 66, 160, 0.2);
    }
    .btn-submit:active { transform: scale(0.98); }

    .admin-news-card {
      background: white;
      border-left: 4px solid var(--primary);
      border-radius: 12px;
      padding: 15px;
      margin: 0 20px 15px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .admin-news-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
    }
    .admin-news-header h4 {
      margin: 0;
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--text-dark);
    }
    .badge {
      padding: 4px 8px;
      border-radius: 20px;
      font-size: 0.7rem;
      font-weight: 600;
    }
    .badge-active { background: #dcfce7; color: #166534; }
    .badge-inactive { background: #f1f5f9; color: #475569; }
    
    .admin-news-content {
      font-size: 0.85rem;
      color: var(--text-gray);
      margin: 0;
      line-height: 1.4;
    }
    .attachment-preview {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid #f1f5f9;
    }
    .attachment-img {
      max-width: 100%;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }
    .attachment-link {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 8px 12px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      color: var(--primary);
      font-size: 0.8rem;
      font-weight: 600;
      text-decoration: none;
    }
    
    .admin-news-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 5px;
      padding-top: 10px;
      border-top: 1px dashed #e2e8f0;
    }
    .news-date {
      font-size: 0.75rem;
      color: #94a3b8;
    }
    .btn-delete {
      background: #fee2e2;
      color: #991b1b;
      border: none;
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      text-decoration: none;
    }

    .alert {
      padding: 10px 15px;
      border-radius: 8px;
      margin: 15px 20px;
      font-size: 0.85rem;
      font-weight: 500;
    }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

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

    .fade-in-up {
      opacity: 0;
      transform: translateY(15px);
      animation: fadeInUp 0.5s forwards;
    }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body>

  <div class="app-container">
    
    <div class="top-header">
      <div class="header-left">
        <h1>จัดการประกาศ / ข่าวสาร</h1>
        <p>สำหรับผู้ดูแลระบบ (Admin)</p>
      </div>
      <div class="header-right">
        <i class="fas fa-bullhorn" style="font-size: 1.5rem; color: var(--primary);"></i>
      </div>
    </div>

    <!-- แสดงแจ้งเตือนเมื่อเพิ่ม/ลบสำเร็จ -->
    <?php if (isset($_GET['success'])): ?>
      <div class="alert alert-success fade-in-up"><i class="fas fa-check-circle"></i> เผยแพร่ประกาศและส่งแจ้งเตือนเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
      <div class="alert alert-success fade-in-up"><i class="fas fa-trash-alt"></i> ลบประกาศเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
      <div class="alert alert-danger fade-in-up"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่มประกาศใหม่ (เพิ่ม enctype="multipart/form-data") -->
    <div class="section-title fade-in-up">
      <div class="section-pill"></div>
      <h3>สร้างประกาศใหม่</h3>
    </div>

    <div class="form-card fade-in-up">
      <form method="POST" action="admin_announcements.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add">
        
        <div class="form-group">
          <label class="form-label">หัวข้อประกาศ <span style="color:red;">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="เช่น แจ้งกำหนดการ..." required>
        </div>

        <div class="form-group">
          <label class="form-label">เนื้อหา / รายละเอียด <span style="color:red;">*</span></label>
          <textarea name="content" class="form-control" placeholder="พิมพ์รายละเอียดข่าวสารที่นี่..." required></textarea>
        </div>

        <!-- ช่องอัปโหลดไฟล์แนบ -->
        <div class="form-group">
          <label class="form-label">ไฟล์แนบ / รูปภาพประกอบ (ไม่บังคับ)</label>
          <input type="file" name="attachment" class="form-control form-control-file" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx">
        </div>

        <div class="form-group">
          <label class="form-label">สถานะการแสดงผล</label>
          <select name="status" class="form-control">
            <option value="active">🟢 แสดงผลและส่งแจ้งเตือน (Active)</option>
            <option value="inactive">⚪ ซ่อน (Inactive)</option>
          </select>
        </div>

        <button type="submit" class="btn-submit">
          <i class="fas fa-paper-plane"></i> เผยแพร่ประกาศ
        </button>
      </form>
    </div>

    <!-- รายการประกาศที่มีอยู่ -->
    <div class="section-title fade-in-up" style="animation-delay: 0.1s;">
      <div class="section-pill" style="background: #3b82f6;"></div>
      <h3>ประกาศทั้งหมดในระบบ</h3>
    </div>

    <?php if (!empty($announcements)): ?>
      <?php foreach ($announcements as $index => $news): ?>
        <div class="admin-news-card fade-in-up" style="animation-delay: <?= 0.1 + ($index * 0.05) ?>s;">
          
          <div class="admin-news-header">
            <h4><?= htmlspecialchars($news['title']) ?></h4>
            <?php if ($news['status'] === 'active'): ?>
              <span class="badge badge-active">Active</span>
            <?php else: ?>
              <span class="badge badge-inactive">Inactive</span>
            <?php endif; ?>
          </div>
          
          <div class="admin-news-content">
            <?= nl2br(htmlspecialchars($news['content'])) ?>
          </div>

          <!-- แสดงผลไฟล์แนบ ถ้ามี -->
          <?php if (!empty($news['attachment']) && file_exists($news['attachment'])): ?>
            <div class="attachment-preview">
              <?php 
                $ext = strtolower(pathinfo($news['attachment'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): 
              ?>
                <img src="<?= $news['attachment'] ?>" class="attachment-img" alt="รูปภาพประกอบ">
              <?php else: ?>
                <a href="<?= $news['attachment'] ?>" target="_blank" class="attachment-link">
                  <i class="fas fa-paperclip"></i> ดาวน์โหลดไฟล์แนบ
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          
          <div class="admin-news-footer">
            <span class="news-date">
              <i class="far fa-clock"></i> <?= date('d/m/Y H:i', strtotime($news['created_at'])) ?>
            </span>
            <a href="admin_announcements.php?delete_id=<?= $news['id'] ?>" class="btn-delete" onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบประกาศนี้?');">
              <i class="fas fa-trash"></i> ลบ
            </a>
          </div>

        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="admin-news-card fade-in-up" style="border-left-color: #cbd5e1; text-align: center; padding: 30px 15px;">
        <i class="fas fa-folder-open" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 10px;"></i>
        <p style="margin: 0; color: #64748b; font-size: 0.9rem;">ยังไม่มีประกาศในระบบ</p>
      </div>
    <?php endif; ?>

    <!-- เมนูด้านล่างสำหรับ Admin -->
    <div class="bottom-nav">
      <a href="turakarn.php" class="nav-link">
        <i class="fas fa-chart-line"></i>
        <span>ภาพรวม</span>
      </a>
      
      <a href="admin_announcements.php" class="nav-link active">
        <i class="fas fa-bullhorn"></i>
        <span>ประกาศ</span>
      </a>

      <a href="admin_logout.php" class="nav-link">
        <i class="fas fa-sign-out-alt"></i>
        <span>ออก</span>
      </a>
    </div>

  </div>

</body>
</html>
