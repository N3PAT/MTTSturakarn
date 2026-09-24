<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ติดตั้งแอปพลิเคชัน MTTS</title>
  
  <!-- Manifest & Favicon -->
  <link rel="manifest" href="manifest.json">
  <link rel="icon" type="image/jpeg" href="https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg">
  
  <!-- Fonts & Icons -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    :root {
      --primary: #0f172a;
      --accent: #2563eb;
      --bg: #f8fafc;
      --text-dark: #0f172a;
      --text-gray: #64748b;
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; min-height: 100vh; display: flex; justify-content: center; align-items: center; color: var(--text-dark); }

    .install-card {
      width: 100%;
      max-width: 430px;
      background: #ffffff;
      min-height: 100vh;
      padding: 32px 24px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
    }

    @media (min-width: 480px) {
      .install-card {
        min-height: auto;
        border-radius: 28px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        margin: 20px;
      }
    }

    .app-header { textโค้ดหน้าเว็บสำหรับติดตั้ง (Install Page) ที่รองรับทั้ง iOS และ Android พร้อมระบบดึงเวอร์ชันล่าสุดจาก API และเลือกติดตั้งได้ทั้งแบบ PWA หรือดาวน์โหลดไฟล์ APK

```html
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตั้งแอปพลิเคชัน</title>
    <link rel="manifest" href="/manifest.json">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        body {
            background-color: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            text-align: center;
        }
        .app-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            margin-bottom: 16px;
        }
        h2 {
            font-size: 1.25rem;
            color: #1a1a1a;
            margin-bottom: 6px;
        }
        .version-badge {
            display: inline-block;
            background: #eef2ff;
            color: #4f46e5;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 10px;
        }
        .btn {
            width: 100%;
            padding: 12px 16px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
            text-decoration: none;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .btn-primary {
            background-color: #4f46e5;
            color: white;
        }
        .btn-secondary {
            background-color: #10b981;
            color: white;
        }
        .instructions {
            margin-top: 16px;
            padding: 12px;
            background: #fffbe3;
            border: 1px solid #ffe58f;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #856404;
            text-align: left;
            display: none;
        }
    </style>
</head>
<body>

<div class="card">
    <img src="icon.png" alt="App Icon" class="app-icon" id="appIcon">
    <h2 id="appName">กำลังโหลดข้อมูลแอป...</h2>
    <span class="version-badge" id="appVersion">v--.--</span>

    <div class="btn-group">
        <!-- ปุ่มติดตั้ง PWA -->
        <button id="pwaInstallBtn" class="btn btn-primary" style="display: none;">
            ติดตั้งแอป (PWA)
        </button>

        <!-- ปุ่มดาวน์โหลด APK สำหรับ Android -->
        <a id="apkDownloadBtn" class="btn btn-secondary" style="display: none;" download>
            ดาวน์โหลดไฟล์ APK
        </a>
    </div>

    <!-- แนะนำการติดตั้งบน iOS Safari -->
    <div id="iosInstructions" class="instructions">
        <strong>วิธีติดตั้งบน iOS:</strong><br>
        1. แตะปุ่ม <strong>แชร์ (Share)</strong> ด้านล่างของ Safari<br>
        2. เลือก <strong>เพิ่มไปยังหน้าจอโฮม (Add to Home Screen)</strong>
    </div>
</div>

<script>
    let deferredPrompt = null;
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isAndroid = /Android/.test(navigator.userAgent);

    // 1. ดึงข้อมูลเวอร์ชันและลิงก์ดาวน์โหลดจาก API
    async function loadVersionInfo() {
        try {
            const response = await fetch('/api/version');
            const data = await response.json();
            
            // ตัวอย่างโครงสร้าง JSON จาก API:
            // { "appName": "MyApp", "version": "1.2.0", "apkUrl": "/downloads/app-v1.2.0.apk" }
            
            document.getElementById('appName').textContent = data.appName || 'แอปพลิเคชัน';
            document.getElementById('appVersion').textContent = `v${data.version || '1.0.0'}`;
            
            if (data.apkUrl && isAndroid) {
                const apkBtn = document.getElementById('apkDownloadBtn');
                apkBtn.href = data.apkUrl;
                apkBtn.style.display = 'flex';
            }
        } catch (error) {
            console.error('ไม่สามารถดึงข้อมูล API ได้:', error);
            document.getElementById('appName').textContent = 'แอปพลิเคชัน';
        }
    }

    // 2. ดักจับ Event สำหรับการติดตั้ง PWA บน Android/Chrome
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        
        const pwaBtn = document.getElementById('pwaInstallBtn');
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

    // 3. ตรวจสอบเงื่อนไข iOS สำหรับแสดงวิธีติดตั้ง
    function checkDeviceSupport() {
        if (isIOS) {
            document.getElementById('iosInstructions').style.display = 'block';
        }
    }

    // เรียกทำงานเมื่อโหลดหน้าเว็บ
    window.addEventListener('DOMContentLoaded', () => {
        loadVersionInfo();
        checkDeviceSupport();
    });
</script>

</body>
</html>
