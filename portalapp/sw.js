const CACHE_NAME = 'mtts-app-v1';
const urlsToCache = [
  'login.php',
  'manifest.json',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
  'https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap'
];

// ติดตั้ง Service Worker และ Cache ไฟล์เบื้องต้น
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return cache.addAll(urlsToCache);
      })
  );
});

// จัดการการส่ง Request (ดึงจาก Cache ถ้ามี หรือโหลดใหม่ถ้าไม่มี)
self.addEventListener('fetch', event => {
  // ยกเว้น POST request เนื่องจากไม่สามารถ Cache ได้
  if (event.request.method !== 'GET') return;

  event.respondWith(
    caches.match(event.request)
      .then(response => {
        if (response) {
          return response;
        }
        return fetch(event.request);
      })
  );
});

// อัปเดต Cache เมื่อมีการเปลี่ยนเวอร์ชัน
self.addEventListener('activate', event => {
  const cacheWhitelist = [CACHE_NAME];
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheWhitelist.indexOf(cacheName) === -1) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});
