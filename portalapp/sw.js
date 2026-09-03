const CACHE_NAME = 'mtts-app-v1';

// ไฟล์พื้นฐานที่ต้องการแคชไว้เพื่อให้แอปโหลดเร็วขึ้นและรองรับตอนออฟไลน์
const urlsToCache = [
  '/',
  'login.php',
  'manifest.json',
  'icon/icon-192x192.png',
  'icon/icon-512x512.png'
];

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
  // คือพยายามโหลดจากเน็ตก่อน ถ้าไม่มีเน็ตค่อยเอาจากแคชมาแสดง
  if (event.request.method === 'GET') {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          // ถ้าดึงข้อมูลสำเร็จ ให้เอามาอัปเดตลงแคชด้วย
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, responseClone);
          });
          return response;
        })
        .catch(() => {
          // ถ้าไม่มีอินเทอร์เน็ต ให้ดึงจากแคชมาแสดง
          return caches.match(event.request);
        })
    );
  }
});
