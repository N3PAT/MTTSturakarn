const CACHE_NAME = 'mtts-app-v6'; // อัปเดตเวอร์ชันเพื่อเคลียร์แคชเก่าที่ค้างในเครื่องผู้ใช้

// รายชื่อไฟล์ที่ต้องการแคชเก็บไว้ใช้ Offline (ถ้าไม่มีให้เว้นว่างไว้)
const urlsToCache = [];

// 1. Install Service Worker
self.addEventListener('install', event => {
  self.skipWaiting(); // บังคับให้อัปเดต Service Worker ทันที
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache');
        return cache.addAll(urlsToCache);
      })
  );
});

// 2. Activate และเคลียร์ Cache เก่า
self.addEventListener('activate', event => {
  event.waitUntil(
    Promise.all([
      self.clients.claim(), // บังคับให้ Service Worker ตัวใหม่เข้าควบคุมหน้าเว็บทันที
      caches.keys().then(cacheNames => {
        return Promise.all(
          cacheNames.map(cacheName => {
            if (cacheName !== CACHE_NAME) {
              console.log('Deleting old cache:', cacheName);
              return caches.delete(cacheName); // ลบแคชเวอร์ชันเก่าทิ้ง
            }
          })
        );
      })
    ])
  );
});

// 3. จัดการ Fetch Request (ดึงข้อมูล)
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // ป้องกันไม่ให้แคชหน้า PHP และ API (ดึงข้อมูลใหม่จาก Server เสมอ)
  if (url.pathname.endsWith('.php') || url.pathname.includes('/api/')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // สำหรับไฟล์ Static อื่นๆ (เช่น CSS, JS, รูปภาพ) ดึงจาก Network ก่อน ถ้าไม่มีเน็ตค่อยดึงจาก Cache
  event.respondWith(
    fetch(event.request)
      .then(response => {
        if (response.status === 200) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, responseClone);
          });
        }
        return response;
      })
      .catch(() => {
        return caches.match(event.request);
      })
  );
});

// ==========================================
// ส่วนรับแจ้งเตือนเมื่อปิดแอป (Web Push)
// ==========================================

// 4. รับข้อมูล Push Notification จาก Server
self.addEventListener('push', event => {
  if (event.data) {
    const payload = event.data.json();
    const options = {
      body: payload.body || 'คุณมีการแจ้งเตือนใหม่',
      icon: payload.icon || 'https://www.mtts.ac.th/assets/images/1x1.png',
      badge: payload.icon || 'https://www.mtts.ac.th/assets/images/1x1.png',
      vibrate: [100, 50, 100],
      data: {
        url: payload.url || '/main.php' // ลิงก์ที่จะเปิดเมื่อกดแจ้งเตือน
      }
    };

    event.waitUntil(
      self.registration.showNotification(payload.title || 'ระบบแจ้งเตือน MTTS', options)
    );
  }
});

// 5. เมื่อคลิกที่ Pop-up แจ้งเตือน
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(
    clients.openWindow(event.notification.data.url)
  );
});
