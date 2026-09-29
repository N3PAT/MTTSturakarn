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
            --primary: #5b21b6;
            --primary-light: #8b5cf6;
            --bg-color: #f8fafc;
            --card-bg: rgba(255, 255, 255, 0.85);
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }

        body { 
            font-family: 'Prompt', sans-serif; 
            background: radial-gradient(circle at 50% -20%, #ede9fe 0%, #f8fafc 100%);
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            padding: 20px; 
            color: var(--text-main);
        }

        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(139, 92, 246, 0.4); }
            50% { box-shadow: 0 0 0 15px rgba(139, 92, 246, 0); }
        }

        .install-container {
            background: var(--card-bg); 
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 32px; 
            padding: 48px 32px;
            max-width: 440px; 
            width: 100%; 
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(91, 33, 182, 0.15);
            animation: slideUpFade 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
            position: relative;
            overflow: hidden;
        }

        /* ตกแต่งพื้นหลังการ์ดเล็กน้อย */
        .install-container::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 120px;
            background: linear-gradient(180deg, rgba(139, 92, 246, 0.08) 0%, rgba(255,255,255,0) 100%);
            z-index: 0;
        }

        .content-wrapper {
            position: relative;
            z-index: 1;
        }

        .app-icon {
            width: 112px; 
            height: 112px; 
            border-radius: 28px; 
            margin: 0 auto 24px;
            box-shadow: 0 16px 32px rgba(91, 33, 182, 0.2); 
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            background-image: url('icon-192x192.png');
            background-size: cover;
            background-position: center;
            border: 4px solid #ffffff;
            position: relative;
        }

        h1 { 
            font-size: 1.5rem; 
            font-weight: 700; 
            color: var(--text-main); 
            margin-bottom: 6px; 
        }

        .version-badge {
            display: inline-flex; 
            align-items: center;
            gap: 6px;
            color: var(--primary);
            font-size: 0.9rem; 
            font-weight: 600;
            margin-bottom: 28px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 32px;
        }

        .feature-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .btn {
            width: 100%; 
            padding: 18px; 
            border: none; 
            border-radius: 20px;
            font-size: 1.05rem; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.3s ease;
            display: flex; 
            justify-content: center; 
            align-items: center; 
            gap: 12px;
            text-decoration: none;
        }

        .btn-primary { 
            background: var(--primary); 
            color: white; 
            box-shadow: 0 10px 25px rgba(91, 33, 182, 0.3); 
            animation: pulse-glow 2s infinite;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(91, 33, 182, 0.4);
            background: var(--primary-light);
        }

        .btn-primary:active { 
            transform: scale(0.97); 
        }
        
        .manual-guide {
            margin-top: 24px; 
            padding: 20px; 
            background: #f8fafc; 
            border: 1px solid #e2e8f0;
            border-radius: 20px; 
            font-size: 0.9rem; 
            text-align: left; 
            color: var(--text-main); 
            display: none;
            line-height: 1.7;
        }

        .manual-guide strong {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            font-size: 1rem;
            margin-bottom: 12px;
        }

        .manual-guide .step-icon {
            background: white;
            padding: 4px 8px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            font-size: 0.9rem;
            color: var(--text-main);
        }

        .changelog { 
            margin-top: 24px; 
            text-align: left; 
            background: transparent;
            padding: 0 12px;
        }

        .changelog h3 { 
            font-size: 0.95rem; 
            margin-bottom: 12px; 
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .changelog ul { list-style: none; }
        .changelog li { 
            margin-bottom: 10px; 
            font-size: 0.85rem;
            color: var(--text-muted);
            position: relative;
            padding-left: 24px;
            line-height: 1.5;
        }

        .changelog li::before {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 0;
            top: 2px;
            color: #10b981;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

    <div class="install-container">
        <div class="content-wrapper">
            <div class="app-icon"></div>
            <h1 id="appName">กำลังเตรียมข้อมูล...</h1>
            <div class="version-badge">
                <span id="appVersion">Web App Version</span>
            </div>

            

            <!-- ปุ่มติดตั้ง PWA จะแสดงเมื่อระบบรองรับ -->
            <button id="pwaBtn" class="btn btn-primary" style="display: none;">
                <i class="fas fa-arrow-down"></i> เพิ่มลงหน้าจอโฮม
            </button>

            <!-- คำแนะนำการติดตั้งแบบ Manual สำหรับ iOS หรือ Android ที่ไม่ขึ้น popup -->
            <div id="manualGuide" class="manual-guide">
                <strong><i class="fas fa-info-circle"></i> วิธีติดตั้งแอปพลิเคชัน</strong>
                <div id="iosSteps" style="display: none;">
                    1. แตะไอคอน <span class="step-icon"><i class="fas fa-arrow-up-from-bracket"></i> แชร์</span> ด้านล่าง<br>
                    2. เลือก <span class="step-icon"><i class="far fa-plus-square"></i> เพิ่มไปยังหน้าจอโฮม</span><br>
                    3. กด <b>"เพิ่ม"</b> ที่มุมขวาบน
                </div>
                <div id="androidSteps" style="display: none;">
                    1. แตะเมนู <span class="step-icon"><i class="fas fa-ellipsis-v"></i></span> มุมขวาบนของเบราว์เซอร์<br>
                    2. เลือก <span class="step-icon"><i class="fas fa-mobile-screen"></i> เพิ่มลงในหน้าจอหลัก</span>
                </div>
            </div>

            <div class="changelog" id="changelogSection" style="display: none;">
                <h3><i class="fas fa-sparkles" style="color: var(--primary);"></i> อัปเดตล่าสุด</h3>
                <ul id="changelogList"></ul>
            </div>
        </div>
    </div>

    <script>
        let deferredPrompt;
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isAndroid = /Android/.test(navigator.userAgent);
        
        const pwaBtn = document.getElementById('pwaBtn');
        const manualGuide = document.getElementById('manualGuide');

        async function fetchAppVersion() {
            const nameEl = document.getElementById('appName');
            const versionEl = document.getElementById('appVersion');

            try {
                const response = await fetch('api/api_version.php');
                if (!response.ok) throw new Error(`HTTP Error`);
                const data = await response.json();

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
            } catch (error) {
                nameEl.textContent = 'แอพพลิเคชั่น พัน นร. 1 - MTTS';
                versionEl.textContent = 'Web Application';
            }
        }

        // จับ Event เมื่อ Browser พร้อมให้ติดตั้ง PWA (ส่วนใหญ่คือ Android Chrome)
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            
            // แสดงปุ่มติดตั้งและซ่อนคำแนะนำ Manual
            pwaBtn.style.display = 'flex';
            manualGuide.style.display = 'none';

            pwaBtn.addEventListener('click', async () => {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    if (outcome === 'accepted') {
                        pwaBtn.style.display = 'none';
                        showManualGuide(); // กลับไปแสดงข้อความเผื่อผู้ใช้ต้องการอ่านอีก
                    }
                    deferredPrompt = null;
                }
            });
        });

        // จัดการหน้าจอสำหรับอุปกรณ์ที่ไม่รองรับ beforeinstallprompt (เช่น iOS Safari)
        function showManualGuide() {
            // ถ้าปุ่ม Install ไม่ได้แสดงอยู่ ให้แสดงคู่มือแทน
            if (window.getComputedStyle(pwaBtn).display === 'none') {
                manualGuide.style.display = 'block';
                if (isIOS) {
                    document.getElementById('iosSteps').style.display = 'block';
                } else {
                    document.getElementById('androidSteps').style.display = 'block';
                }
            }
        }

        // ตรวจสอบว่าแอปถูกติดตั้งและเปิดผ่าน PWA แล้วหรือไม่ (Standalone mode)
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

        window.addEventListener('DOMContentLoaded', () => {
            fetchAppVersion();
            
            // ถ้าไม่ได้เปิดจากในแอป (เปิดผ่านเบราว์เซอร์ปกติ) ให้ประมวลผลการแสดงผลปุ่ม/คู่มือ
            if (!isStandalone) {
                // หน่วงเวลาเล็กน้อยเพื่อให้ beforeinstallprompt ทำงาน (ถ้ามี) 
                // หากไม่มี จะเข้าสู่โหมดแสดงคำแนะนำแบบ Manual
                setTimeout(showManualGuide, 1000);
            } else {
                document.getElementById('appName').textContent += " (ติดตั้งแล้ว)";
            }
        });
    </script>

</body>
</html>
