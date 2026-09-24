<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ติดตั้งแอปพลิเคชัน MTTS</title>
    
    <!-- เรียกใช้ Manifest สำหรับ PWA (ตรวจสอบ path ให้ตรงกับที่เก็บไฟล์ manifest.json) -->
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="icon-192x192.png">
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Prompt', sans-serif; 
            background-color: #f3f4f6; 
            display: flex; justify-content: center; align-items: center; 
            min-height: 100vh; padding: 20px; color: #1f2937;
        }
        .install-card {
            background: #ffffff; border-radius: 24px; padding: 32px 24px;
            max-width: 400px; width: 100%; text-align: center;
            box-shadow: 0 10px 30px rgba(80, 56, 150, 0.1);
        }
        .app-icon {
            width: 96px; height: 96px; border-radius: 24px; margin: 0 auto 16px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1); display: block;
            background-color: #503896; /* Placeholder สีม่วง MTTS */
            background-image: url('icon-192x192.png');
            background-size: cover;
        }
        h1 { font-size: 1.4rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .version-badge {
            display: inline-block; background: #ede9fe; color: #6d28d9;
            padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
            margin-bottom: 24px;
        }
        .btn-group { display: flex; flex-direction: column; gap: 12px; }
        .btn {
            width: 100%; padding: 14px; border: none; border-radius: 14px;
            font-size: 1rem; font-weight: 600; cursor: pointer; transition: 0.2s;
            display: flex; justify-content: center; align-items: center; gap: 8px;
            text-decoration: none;
        }
        .btn:active { transform: scale(0.98); }
        .btn-primary { background-color: #503896; color: white; box-shadow: 0 4px 12px rgba(80, 56, 150, 0.3); }
        .btn-primary:hover { background-color: #432e7a; }
        .btn-secondary { background-color: #10b981; color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-secondary:hover { background-color: #059669; }
        
        .ios-guide {
            margin-top: 24px; padding: 16px; background: #fffbeb; border: 1px solid #fef3c7;
            border-radius: 16px; font-size: 0.85rem; text-align: left; color: #92400e; display: none;
        }
        .ios-guide strong { color: #b45309; }
        .changelog {
            margin-top: 24px; text-align: left; font-size: 0.85rem; color: #4b5563;
        }
        .changelog h3 { font-size: 0.95rem; margin-bottom: 8px; color: #111827; }
        .changelog ul { padding-left: 20px; }
        .changelog li { margin-bottom: 4px; }
    </style>
</head>
<body>

    <div class="install-card">
        <div class="app-icon"></div>
        <h1 id="appName">กำลังตรวจสอบเวอร์ชัน...</h1>
        <div class="version-badge" id="appVersion">v--.--</div>

        <div class="btn-group">
            <!-- ปุ่มติดตั้ง PWA (Android / Chrome Desktop) -->
            <button id="pwaBtn" class="btn btn-primary" style="display: none;">
                <i class="fas fa-mobile-alt"></i> ติดตั้งแอปลงเครื่อง (PWA)
            </button>

            <!-- ปุ่มโหลด APK (แสดงเฉพาะ Android) -->
            <a id="apkBtn" class="btn btn-secondary" style="display: none;" download>
                <i class="fab fa-android"></i> ดาวน์โหลดไฟล์ APK
            </a>
        </div>

        <!-- คำแนะนำสำหรับ iOS -->
        <div id="iosGuide" class="ios-guide">
            <strong><i class="fab fa-apple"></i> วิธีติดตั้งบน iPhone/iPad:</strong><br><br>
            1. แตะไอคอน <i class="fas fa-share-square"></i> (แชร์) ด้านล่างของ Safari<br>
            2. เลื่อนลงแล้วเลือก <strong>"เพิ่มไปยังหน้าจอโฮม" <br>(Add to Home Screen)</strong><br>
            3. กด "เพิ่ม" (Add) มุมขวาบน
        </div>

        <div class="changelog" id="changelogSection" style="display: none;">
            <h3>มีอะไรใหม่:</h3>
            <ul id="changelogList"></ul>
        </div>
    </div>

    <script>
        let deferredPrompt;
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isAndroid = /Android/.test(navigator.userAgent);

        // 1. ดึงข้อมูลจาก API (ย้อนกลับไป 1 โฟลเดอร์เพื่อเข้าโฟลเดอร์ api)
        async function fetchAppVersion() {
            try {
                const response = await fetch('../api/api_version.php');
                const data = await response.json();

                document.getElementById('appName').textContent = data.appName;
                document.getElementById('appVersion').textContent = 'เวอร์ชัน ' + data.version;

                // จัดการ Changelog
                if (data.changelog && data.changelog.length > 0) {
                    const list = document.getElementById('changelogList');
                    data.changelog.forEach(item => {
                        const li = document.createElement('li');
                        li.textContent = item;
                        list.appendChild(li);
                    });
                    document.getElementById('changelogSection').style.display = 'block';
                }

                // จัดการปุ่ม APK สำหรับ Android
                if (isAndroid && data.apkUrl) {
                    const apkBtn = document.getElementById('apkBtn');
                    apkBtn.href = data.apkUrl;
                    apkBtn.style.display = 'flex';
                }

            } catch (error) {
                console.error('API Error:', error);
                document.getElementById('appName').textContent = 'ระบบสารสนเทศ MTTS';
            }
        }

        // 2. ตรวจจับการรองรับ PWA Install
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            const pwaBtn = document.getElementById('pwaBtn');
            pwaBtn.style.display = 'flex';

            pwaBtn.addEventListener('click', async () => {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    if (outcome === 'accepted') {
                        pwaBtn.style.display = 'none';
                    }
                    deferredPrompt = null;
                }
            });
        });

        // 3. ตรวจสอบเงื่อนไขการแสดงผล iOS
        function setupDeviceUI() {
            if (isIOS) {
                document.getElementById('iosGuide').style.display = 'block';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchAppVersion();
            setupDeviceUI();
        });
    </script>

</body>
</html>
