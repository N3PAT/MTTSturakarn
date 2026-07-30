<?php
session_start();
require 'db.php';

// กำหนดค่าเริ่มต้น
$message = '';

// ==========================================
// ส่วนที่ 1: ระบบ Authentication (Login / Logout)
// ==========================================

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($conn)) {
    die("เกิดข้อผิดพลาด: ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
}

// 2. จัดการข้อมูลเมื่อมีการ Submit Form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // ---------------- [ เพิ่ม User ] ----------------
    if (isset($_POST['action']) && $_POST['action'] == 'add') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $check = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $message = "<div class='bg-red-50 border-l-4 border-red-600 p-4 mb-6'><p class='text-sm text-red-700 font-medium'>มีชื่อผู้ใช้นี้อยู่ในระบบแล้ว!</p></div>";
        } else {
            $stmt = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
            $stmt->bind_param("ss", $username, $password);
            $stmt->execute();
            $message = "<div class='bg-green-50 border-l-4 border-green-600 p-4 mb-6'><p class='text-sm text-green-700 font-medium'>เพิ่มผู้ใช้สำเร็จ!</p></div>";
        }
    }

    // ---------------- [ แก้ไข User ] ----------------
    if (isset($_POST['action']) && $_POST['action'] == 'edit') {
        $id = $_POST['user_id'];
        $new_username = trim($_POST['username']);
        $new_password = $_POST['password'];

        $stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($target_user);
        $stmt->fetch();
        $stmt->close();

        if (strtolower($target_user) === 'admin') {
            $message = "<div class='bg-red-50 border-l-4 border-red-600 p-4 mb-6'><p class='text-sm text-red-700 font-medium'>ไม่อนุญาตให้แก้ไขบัญชี admin หลัก!</p></div>";
        } else {
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
                $update_stmt->bind_param("ssi", $new_username, $hashed_password, $id);
                $update_stmt->execute();
            } else {
                $update_stmt = $conn->prepare("UPDATE admins SET username = ? WHERE id = ?");
                $update_stmt->bind_param("si", $new_username, $id);
                $update_stmt->execute();
            }
            $message = "<div class='bg-blue-50 border-l-4 border-blue-600 p-4 mb-6'><p class='text-sm text-blue-700 font-medium'>อัปเดตข้อมูลผู้ใช้สำเร็จ!</p></div>";
        }
    }
}

// ดึงข้อมูล User ทั้งหมด
$query = $conn->query("SELECT id, username FROM admins ORDER BY id ASC");
$users = [];
if ($query) {
    $users = $query->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการ User - ระบบผู้ดูแล</title>
    <!-- ใช้ฟอนต์ Sarabun เพื่อความสวยงามและเป็นทางการ -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Sarabun', 'sans-serif'] },
                    colors: { 
                        flat: { primary: '#1e40af', dark: '#1e3a8a', light: '#eff6ff' } 
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased pb-12">

    <!-- Header (สไตล์ Flat) -->
    <header class="bg-flat-primary text-white shadow-sm border-b-4 border-flat-dark mb-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row justify-between items-center">
            <div>
                <h1 class="text-xl font-bold tracking-tight">ระบบจัดการผู้ดูแล (Admins)</h1>
            </div>
            <div class="mt-4 sm:mt-0 flex items-center gap-4">
                <a href="?logout=1" onclick="return confirm('ยืนยันการออกจากระบบ?');" class="inline-flex items-center px-4 py-2 rounded-sm text-sm font-semibold bg-red-600 hover:bg-red-700 text-white transition duration-150 shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    ออกจากระบบ
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-6">
        
        <!-- แสดงข้อความแจ้งเตือน (ถ้ามี) -->
        <?= $message; ?>

        <!-- กล่องเพิ่มผู้ใช้ใหม่ (Flat Card) -->
        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-flat-primary p-6 mb-8">
            <h2 class="text-lg font-bold text-gray-700 mb-4">เพิ่มผู้ใช้ใหม่</h2>
            <form method="POST" class="flex flex-col sm:flex-row gap-4 items-end">
                <input type="hidden" name="action" value="add">
                <div class="w-full sm:w-1/3">
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">ชื่อผู้ใช้ (Username)</label>
                    <input type="text" name="username" required 
                           class="w-full px-4 py-2 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-flat-primary focus:border-flat-primary">
                </div>
                <div class="w-full sm:w-1/3">
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">รหัสผ่าน (Password)</label>
                    <input type="password" name="password" required 
                           class="w-full px-4 py-2 border border-gray-300 rounded-sm focus:outline-none focus:ring-1 focus:ring-flat-primary focus:border-flat-primary">
                </div>
                <div class="w-full sm:w-auto">
                    <button type="submit" class="w-full sm:w-auto bg-flat-primary hover:bg-flat-dark text-white font-bold py-2 px-6 rounded-sm transition duration-200 shadow-sm flex items-center justify-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        เพิ่มข้อมูล
                    </button>
                </div>
            </form>
        </div>

        <!-- ��ารางรายชื่อผู้ใช้ (Flat Table) -->
        <div class="bg-white rounded-sm shadow-sm border border-gray-200 border-t-4 border-t-gray-600 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-700">รายชื่อผู้ดูแลระบบทั้งหมด</h3>
                <span class="text-sm text-gray-500">จำนวน <?= count($users) ?> บัญชี</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-600 uppercase bg-gray-100 border-b border-gray-200 font-semibold">
                        <tr>
                            <th class="px-6 py-3 w-20 text-center">ID</th>
                            <th class="px-6 py-3 w-48">Username</th>
                            <th class="px-6 py-3">จัดการข้อมูล (อัปเดต)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach($users as $user): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 text-center text-gray-500 font-medium">
                                <?= $user['id'] ?>
                            </td>
                            <td class="px-6 py-4 font-bold <?= strtolower($user['username']) === 'admin' ? 'text-red-600' : 'text-gray-800' ?>">
                                <?= htmlspecialchars($user['username']) ?>
                                <?php if(strtolower($user['username']) === 'admin'): ?>
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Root</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-3">
                                <?php if (strtolower($user['username']) === 'admin'): ?>
                                    <span class="text-gray-400 text-sm italic">ไม่อนุญาตให้แก้ไขบัญชีหลัก</span>
                                <?php else: ?>
                                    <!-- ฟอร์มสำหรับแก้ไขแบบ Inline สไตล์ Flat -->
                                    <form method="POST" class="flex flex-wrap gap-2 items-center">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required
                                               class="border border-gray-300 px-3 py-1.5 rounded-sm text-sm w-40 focus:outline-none focus:ring-1 focus:ring-flat-primary focus:border-flat-primary">
                                        <input type="password" name="password" placeholder="รหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)" 
                                               class="border border-gray-300 px-3 py-1.5 rounded-sm text-sm w-56 focus:outline-none focus:ring-1 focus:ring-flat-primary focus:border-flat-primary">
                                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-semibold px-4 py-1.5 rounded-sm text-sm transition shadow-sm">
                                            บันทึก
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($users)): ?>
                        <tr>
                            <td colspan="3" class="px-6 py-8 text-center text-gray-500">ไม่พบข้อมูลผู้ใช้</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

<!-- Vercel Speed Insights -->
<script>
  window.si = window.si || function () { (window.siq = window.siq || []).push(arguments); };
</script>
<script defer src="/_vercel/speed-insights/script.js"></script>

</body>
</html>
