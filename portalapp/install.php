<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ติดตั้งแอปพลิเคชัน MTTS</title>
    
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="icon-192x192.png">
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

    <style>
        :root {
            --primary: #6d28d9;
            --primary-dark: #503896;
            --secondary: #10b981;
            --secondary-dark: #059669;
            --bg-gradient: linear-gradient(135deg, #f0fdf4 0%, #ede9fe 100%);
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }

        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }

        body { 
            font-family: 'Prompt', sans-serif; 
            background: var(--bg-gradient);
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            padding: 24px; 
            color: var(--text-main);
        }

        /* แอนิเมชันสำหรับโหลดการ์ด */
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* แอนิเมชันสำหรับข้อความกำลังโหลด */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .loading-pulse {
            animation: pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        .install-card {
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 28px; 
            padding: 40px 28px;
            max-width: 420px; 
            width: 100%; 
            text-align: center;
            box-shadow: 0 20px 40px rgba(109, 40, 217, 0.08);
            animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .app-icon {
            width: 104px; 
            height: 104px; 
            border-radius: 26px; 
            margin: 0 auto 20px;
            box-shadow: 0 12px 24px rgba(109, 40, 217, 0.15); 
            display: block;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            background-image: url('icon-192x192.png');
            background-size: cover;
            background-position: center;
            border: 3px solid #ffffff;
        }

        h1 { 
            font-size: 1.4rem; 
            font-weight: 700; 
            color: #111827; 
            margin-bottom: 8px; 
            letter-spacing: -0.3px;
        }

        .version-badge {
            display: inline-flex; 
            align-items: center;
            gap: 6px;
            background: #f5f3ff; 
            color: var(--primary);
            padding: 6px 14px; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 600;
            margin-bottom: 32px;
            border: 1px solid #ede9fe;
        }

        .btn-group { 
            display: flex; 
            flex-direction: column; 
            gap: 14px; 
        }

        .btn {
            width: 100%; 
            padding: 16px; 
            border: none; 
            border-radius: 16px;
            font-size: 1rem; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.25s ease;
            display: flex; 
            justify-content: center; 
            align-items: center; 
            gap: 10px;
            text-decoration: none;
        }

        .btn:hover { 
            transform: translateY(-2px); 
        }

        .btn:active { 
            transform: scale(0.98); 
        }

        .btn-primary { 
            background: linear-gradient(135deg, var(--primary), var(--primary-dark)); 
            color: white; 
            box-shadow: 0 8px 20px rgba(109, 40, 217, 0.25); 
        }

        .btn-primary:hover {
            box-shadow: 0 12px 24px rgba(109, 40, 217, 0.35);
        }

        .btn-secondary { 
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark)); 
            color: white; 
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.25); 
        }

        .btn-secondary:hover {
            box-shadow: 0 12px 24px rgba(16, 185, 129, 0.35);
        }
        
        .ios-guide {
            margin-top: 28px; 
            padding: 20px; 
            background: #fffbeb; 
            border: 1px solid #fde68a;
            border-radius: 18px; 
            font-size: 0.9rem; 
            text-align: left; 
            color: #92400e; 
            display: none;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.05);
            line-height: 1.6;
        }

        .ios-guide strong {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #b45309;
            font-size: 1rem;
            margin-bottom: 12px;
        }

        .changelog { 
            margin-top: 32px; 
            text-align: left; 
            background: #f9fafb;
            padding: 20px;
            border-radius: 18px;
            border: 1px solid #f3f4f6;
        }

        .changelog h3 { 
            font-size: 0.95rem; 
            margin-bottom: 12px; 
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .changelog ul { 
            list-style: none; 
        }

        .changelog li { 
            margin-bottom: 8px; 
            font-size: 0.9rem;
            color: var(--text-muted);
            position: relative;
            padding-left: 20px;
            line-height: 1.5;
        }

        .changelog li::before {
            content: '\f058';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 0;
            top: 2px;
            color: var(--primary);
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

    <div class="install-card">
        <div class="app-icon"></div>
        <h1 id="appName" class="loading-pulse">กำลังโหลดข้อมูล...</h1>
        <div class="version-badge">
            <i class="fas fa-tag"></i>
            <span id="appVersion" class="loading-pulse">v--.--</span>
        </div>

        <div class="btn-group">
            <button id="pwaBtn" class="btn btn-primary" style="display: none;">
                <i class="fas fa-mobile-screen"></i> ติดตั้งแอปลงเครื่อง (PWA)
            </button>

            <a id="apkBtn" class="btn btn-secondary" style="display: none;" download>
                <i class="fab fa-android"></i> ดาวน์โหลดไฟล์ APK
            </a>
        </div>

        <div id="iosGuide" class="ios-guide">
            <strong><i class="fab fa-apple"></i> วิธีติดตั้งบน iPhone/iPad</strong>
            1. แตะไอคอน <b><i class="fas fa-arrow-up-from-bracket"></i> แชร์</b> ด้านล่างของ Safari<br>
            2. เลื่อนลงแล้วเลือก <b>"เพิ่มไปยังหน้าจอโฮม"</b><br>
            3. กด <b>"เพิ่ม"</b> ที่มุมขวาบน
        </div>

        <div class="changelog" id="changelogSection" style="display: none;">
            <h3><i class="fas fa-history"></i> มีอะไรใหม่ในเวอร์ชันนี้:</h3>
            <ul id="changelogList"></ul>
        </div>
    </div>

    <script>
        let deferredPrompt;
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isAndroid = /Android/.test(navigator.userAgent);

        async function fetchAppVersion() {
            const nameEl = document.getElementById('appName');
            const versionEl = document.getElementById('appVersion');

            try {
                const response = await fetch('api/api_version.php');
                
                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.status}`);
                }
                
                const data = await response.json();

                // ปิดเอฟเฟกต์ loading
                nameEl.classList.remove('loading-pulse');
                versionEl.classList.remove('loading-pulse');

                nameEl.textContent = data.appName;
                versionEl.textContent = 'เวอร์ชัน ' + data.version;

                if (data.changelog && data.changelog.length > 0) {
                    const list = document.getElementById('changelogList');
                    list.innerHTML = '';
                    data.changelog.forEach(item => {
                        const li = document.createElement('li');
                        li.textContent = item;
                        list.appendChild(li);
                    });
                    document.getElementById('changelogSection').style.display = 'block';
                }

                if (isAndroid && data.apkUrl) {
                    const apkBtn = document.getElementById('apkBtn');
                    apkBtn.href = data.apkUrl;
                    apkBtn.style.display = 'flex';
                }

            } catch (error) {
                console.error('API Error:', error);
                nameEl.classList.remove('loading-pulse');
                versionEl.classList.remove('loading-pulse');
                
                nameEl.textContent = 'ระบบสารสนเทศ MTTS';
                versionEl.textContent = 'ไม่สามารถดึงข้อมูลเวอร์ชันได้';
            }
        }

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
