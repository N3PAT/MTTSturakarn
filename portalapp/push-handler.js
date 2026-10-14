/*
 * push-handler.js — รับ push แล้วเด้งแจ้งเตือน แม้ปิดแอป/ปิดหน้าเว็บอยู่
 * วิธีใช้: เพิ่มบรรทัดนี้ไว้บนสุดของ service worker เดิม (sw.js)
 *     importScripts('push-handler.js');
 */
self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { body: event.data ? event.data.text() : '' };
  }
  var opts = {
    body: data.body || '',
    icon: data.icon || 'icon-512x512.png',     // ปรับ path ให้ตรงกับไฟล์ไอคอนจริงของแอป
    badge: data.badge || 'icon-512x512.png',
    data: { url: data.url || 'nofitication.php' }
  };
  if (data.tag) { opts.tag = data.tag; }
  // iOS/Safari บังคับให้ต้องเด้งแจ้งเตือนทุกครั้งที่ได้รับ push
  event.waitUntil(self.registration.showNotification(data.title || 'MTTS', opts));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = new URL((event.notification.data && event.notification.data.url) || 'notification.php',
                       self.registration.scope).href;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].url === target && 'focus' in list[i]) { return list[i].focus(); }
      }
      return self.clients.openWindow(target);
    })
  );
});
