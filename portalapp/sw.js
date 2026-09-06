const CACHE_NAME = 'mtts-app-v1';

// ลบรายชื่อไฟล์ออกชั่วคราว เพื่อแก้ปัญหา cache.addAll พังจากไฟล์ที่หาไม่เจอ (404)
const urlsToCache = [];

// 1. Install Service Worker และทำการ Caching ไฟล์
self.addEventListener('install', event => {
  self.skipWaiting(); // บังคับให้อัปเดต Service worker ทันที
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache');
        return cache.addAll(urlsToCache);
      })
  );
});

// 2. Activate และเคลียร์ Cache เก่า (ถ้ามีการเปลี่ยนชื่อ CACHE_NAME)
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});

// 3. จัดการ Fetch Request (ดึงข้อมูล)
self.addEventListener('fetch', event => {
  // สำหรับระบบ PHP ที่มีการ Login ควรใช้กลยุทธ์ "Network First, falling back to cache"
  if (event.request.method === 'GET') {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, responseClone);
          });
          return response;
        })
        .catch(() => {
          return caches.match(event.request);
        })
    );
  }
});

// ==========================================
// ส่วนที่เพิ่มใหม่: สำหรับรับแจ้งเตือนเมื่อปิดแอป (Web Push)
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

// 5. เมื่อนักเรียนกดจิ้มที่ Pop-up แจ้งเตือน
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(
    clients.openWindow(event.notification.data.url)
  );
});
