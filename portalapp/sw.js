const CACHE_NAME = 'mtts-app-v16'; // อัปเดตเวอร์ชันเพื่อเคลียร์แคชเก่าและอัปเดตตัวรับแจ้งเตือน

// รายชื่อไฟล์ที่ต้องการแคชเก็บไว้ใช้ Offline (ถ้าไม่มีให้เว้นว่างไว้)
const urlsToCache = [];

// 1. Install Service Worker
self.addEventListener('install', event => {
  self.skipWaiting(); // บังคับให้อัปเดต Service Worker ทันทีโดยไม่ต้องรอปิดแอป
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache:', CACHE_NAME);
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
              return caches.delete(cacheName);
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

  // สำหรับไฟล์ Static อื่นๆ (CSS, JS, รูปภาพ) ดึงจาก Network ก่อน ถ้าไม่มีเน็ตค่อยดึงจาก Cache
  event.respondWith(
    fetch(event.request)
      .then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
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

// 4. รับข้อมูล Push Notification จาก Server แม้ปิดแอปไปแล้ว
self.addEventListener('push', event => {
  let payload = {
    title: 'ระบบแจ้งเตือน MTTS',
    body: 'คุณมีการแจ้งเตือนใหม่',
    icon: 'https://upload.wikimedia.org/wikipedia/commons/2/2c/MTTS_Logo.jpg',
    image: '',
    url: '/portalapp/main.php',
    requireInteraction: true,
    vibrate: [200, 100, 200],
    actions: []
  };

  // ป้องกัน Error กรณีข้อมูลที่ส่งมาไม่ใช่ JSON (เช่น กดเทสจาก DevTools)
  if (event.data) {
    try {
      const parsed = event.data.json();
      payload = { ...payload, ...parsed };
    } catch (e) {
      payload.body = event.data.text();
    }
  }

  const options = {
    body: payload.body,
    icon: payload.icon,
    badge: payload.icon,
    image: payload.image || undefined, // แสดงรูปภาพแบนเนอร์ที่ส่งมาจาก PHP
    vibrate: payload.vibrate || [200, 100, 200],
    requireInteraction: Boolean(payload.requireInteraction), // ค้างแจ้งเตือนไว้จนกว่าผู้ใช้จะกด
    tag: 'mtts-notification', // ป้องกันการเด้งซ้อนทับกันรกหน้าจอ
    renotify: true,           // ให้สั่น/มีเสียงเตือนใหม่ทุกครั้งแม้ tag ซ้ำ
    actions: payload.actions || [], // แสดงปุ่ม "เข้าสู่ระบบเพื่อทำรายการ" ที่ส่งมาจาก PHP
    data: {
      url: payload.url || '/portalapp/main.php'
    }
  };

  event.waitUntil(
    self.registration.showNotification(payload.title, options)
  );
});

// 5. เมื่อคลิกที่ Pop-up แจ้งเตือน หรือกดปุ่ม Action บนแจ้งเตือน
self.addEventListener('notificationclick', event => {
  event.notification.close();

  const targetUrl = event.notification.data?.url || '/portalapp/main.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      // ถ้าเปิดหน้าแอปค้างไว้อยู่แล้ว ให้สลับไปหน้านั้นและโหลด URL ปลายทาง
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      // ถ้าปิดแอปไปแล้ว ให้เปิดหน้าต่างแอปขึ้นมาใหม่
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});
