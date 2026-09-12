<?php
session_start();
require_once 'db.php';

// สมมติว่าตรวจสอบสิทธิ์ Admin แล้ว
// if (empty($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

// -------------------------------------------------------------
// จัดการการลบข้อมูลนักเรียน
// -------------------------------------------------------------
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $del_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin_students.php");
    exit();
}

// -------------------------------------------------------------
// ค้นหาและดึงข้อมูลนักเรียน
// -------------------------------------------------------------
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM students";
if ($search) {
    $query .= " WHERE student_code LIKE '%" . $conn->real_escape_string($search) . "%' 
                OR first_name LIKE '%" . $conn->real_escape_string($search) . "%' 
                OR last_name LIKE '%" . $conn->real_escape_string($search) . "%'";
}
$query .= " ORDER BY student_code ASC";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>จัดการนักเรียน - MTTS</title>
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
    
    .action-bar { padding: 15px 20px; display: flex; gap: 10px; flex-direction: column; }
    .search-box { position: relative; width: 100%; }
    .search-box input { width: 100%; padding: 10px 15px 10px 40px; border: 1px solid #e2e8f0; border-radius: 12px; font-family: 'Prompt', sans-serif; outline: none; }
    .search-box i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-gray); }
    
    .btn-add { background: var(--primary); color: white; border: none; padding: 12px; border-radius: 12px; font-family: 'Prompt', sans-serif; font-weight: 600; cursor: pointer; text-decoration: none; text-align: center; display: block; box-shadow: 0 4px 10px rgba(90, 66, 160, 0.2); }

    .student-list { padding: 0 20px; display: flex; flex-direction: column; gap: 10px; }
    .student-item { background: white; border-radius: 14px; padding: 12px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: transform 0.2s; }
    .student-item:active { transform: scale(0.98); }
    
    .student-img { width: 45px; height: 45px; border-radius: 10px; object-fit: cover; background: #e2e8f0; }
    .student-info { flex: 1; }
    .student-info h4 { margin: 0; font-size: 0.9rem; font-weight: 600; color: var(--text-dark); }
    .student-info p { margin: 2px 0 0 0; font-size: 0.75rem; color: var(--text-gray); }
    
    .action-btns { display: flex; gap: 6px; }
    .btn-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 0.85rem; }
    .btn-edit { background: #eff6ff; color: #3b82f6; }
    .btn-delete { background: #fef2f2; color: #ef4444; }

    .empty-state { text-align: center; padding: 40px 20px; color: var(--text-gray); }
    
    .fade-in-up { opacity: 0; transform: translateY(20px); animation: fadeInUp 0.5s forwards; }
    @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body>
  <div class="app-container">
    
    <div class="top-header">
      <a href="admin_dashboard.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
      <div class="page-title">
        <h1>จัดการข้อมูลนักเรียน</h1>
      </div>
    </div>

    <div class="action-bar fade-in-up">
      <a href="admin_students_form.php" class="btn-add"><i class="fas fa-plus"></i> เพิ่มนักเรียนใหม่</a>
      
      <form method="GET" action="admin_students.php" class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" name="search" placeholder="ค้นหารหัสนักเรียน หรือ ชื่อ..." value="<?= htmlspecialchars($search) ?>">
      </form>
    </div>

    <div class="student-list fade-in-up" style="animation-delay: 0.1s;">
      <?php if ($result && $result->num_rows > 0): ?>
        <?php while($row = $result->fetch_assoc()): ?>
          <?php 
            $fullname = ($row['prefix'] ?? '') . $row['first_name'] . ' ' . $row['last_name'];
            $img = (!empty($row['image']) && $row['image'] !== 'default_student.png') ? $row['image'] : "https://ui-avatars.com/api/?name=".urlencode($fullname)."&background=5a42a0&color=fff";
            $unit = "พัน.{$row['battalion']} ร้อย.{$row['company']} ม.{$row['platoon']}";
          ?>
          <div class="student-item">
            <img src="<?= htmlspecialchars($img) ?>" alt="img" class="student-img">
            <div class="student-info">
              <h4><?= htmlspecialchars($fullname) ?></h4>
              <p>รหัส: <?= htmlspecialchars($row['student_code']) ?> | <?= htmlspecialchars($unit) ?></p>
            </div>
            <div class="action-btns">
              <a href="admin_students_form.php?id=<?= $row['id'] ?>" class="btn-icon btn-edit"><i class="fas fa-pen"></i></a>
              <a href="?delete=<?= $row['id'] ?>" class="btn-icon btn-delete" onclick="return confirm('ยืนยันการลบข้อมูลนักเรียนรหัส <?= $row['student_code'] ?>?');"><i class="fas fa-trash"></i></a>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; color: #cbd5e1;"></i>
          <p>ไม่พบข้อมูลนักเรียน</p>
        </div>
      <?php endif; ?>
    </div>

  </div>
</body>
</html>
