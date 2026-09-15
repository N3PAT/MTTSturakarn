<?php
session_start();
require_once 'db.php';

// สมมติว่ามีระบบเช็คสิทธิ์ Admin (ถ้ามี Session Admin ของตัวเอง ให้เปลี่ยนตรงนี้ครับ)
// if (empty($_SESSION['admin_id'])) {
//     header("Location: admin_login.php");
//     exit();
// }

// -------------------------------------------------------------
// 1. จัดการการเพิ่มประกาศใหม่ (POST Request)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (!empty($title) && !empty($content)) {
        try {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, status) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $title, $content, $status);
            $stmt->execute();
            $stmt->close();
            
            // รีเฟรชหน้าเพื่อป้องกันการส่งฟอร์มซ้ำ
            header("Location: admin_announcements.php?success=1");
            exit();
        } catch (Exception $e) {
            $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
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
// 3. ดึงข้อมูลประกาศทั้งหมดมาแสดง (เรียงจากใหม่ไปเก่า)
// -------------------------------------------------------------
$announcements = [];
try {
    $sql = "SELECT id, title, content, status, created_at FROM announcements ORDER BY created_at DESC";
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

    /* Header */
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

    /* Form Card */
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

    /* Announcement List */
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
    }

    /* Alert Messages */
    .alert {
      padding: 10px 15px;
      border-radius: 8px;
      margin: 15px 20px;
      font-size: 0.85rem;
      font-weight: 500;
    }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

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
      <div class="alert alert-success fade-in-up"><i class="fas fa-check-circle"></i> เพิ่มประกาศเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
      <div class="alert alert-success fade-in-up"><i class="fas fa-trash-alt"></i> ลบประกาศเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
      <div class="alert alert-danger fade-in-up"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่มประกาศใหม่ -->
    <div class="section-title fade-in-up">
      <div class="section-pill"></div>
      <h3>สร้างประกาศใหม่</h3>
    </div>

    <div class="form-card fade-in-up">
      <form method="POST" action="admin_announcements.php">
        <input type="hidden" name="action" value="add">
        
        <div class="form-group">
          <label class="form-label">หัวข้อประกาศ <span style="color:red;">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="เช่น แจ้งกำหนดการ..." required>
        </div>

        <div class="form-group">
          <label class="form-label">เนื้อหา / รายละเอียด <span style="color:red;">*</span></label>
          <textarea name="content" class="form-control" placeholder="พิมพ์รายละเอียดข่าวสารที่นี่..." required></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">สถานะการแสดงผล</label>
          <select name="status" class="form-control">
            <option value="active">🟢 แสดงผล (Active)</option>
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
      <!-- เปลี่ยนลิงก์ให้ตรงกับหน้าแดชบอร์ด Admin ของคุณ -->
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
