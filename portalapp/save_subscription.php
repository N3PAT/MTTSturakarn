<script>
  // ฟังก์ชันแปลง VAPID Public Key
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  const publicVapidKey = 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA';

  // ฟังก์ชันสร้างและส่งข้อมูล Subscription ไปยังฐานข้อมูล
  async function registerPushSubscription() {
    try {
      const registration = await navigator.serviceWorker.ready;
      let subscription = await registration.pushManager.getSubscription();

      // หากยังไม่มี Subscription ให้สร้างใหม่
      if (!subscription) {
        subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicVapidKey)
        });
      }

      // ส่งข้อมูลไปบันทึกที่ save_subscription.php
      const res = await fetch('save_subscription.php', {
        method: 'POST',
        body: JSON.stringify(subscription),
        headers: { 'Content-Type': 'application/json' }
      });

      // ดักจับและอ่านการตอบกลับฝั่ง Server อย่างปลอดภัย
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error('เซิร์ฟเวอร์ตอบกลับผิดรูปแบบ (PHP Error): ' + text.substring(0, 100));
      }

      return data;
    } catch (err) {
      console.error('Push Registration Error:', err);
      throw err;
    }
  }

  // ลงทะเบียน Service Worker เมื่อโหลดหน้าเว็บเสร็จสมบูรณ์
  if ('serviceWorker' in navigator && 'PushManager' in window) {
    window.addEventListener('load', async () => {
      try {
        await navigator.serviceWorker.register('sw.js');
        await navigator.serviceWorker.ready;
        
        // หากผู้ใช้เคยเปิดสิทธิ์ไว้แล้ว ให้ส่งข้อมูลเข้าฐานข้อมูลอัตโนมัติ
        if (Notification.permission === 'granted') {
          await registerPushSubscription();
        }
      } catch (err) {
        console.error('SW/Push Init Error:', err);
      }
    });
  }

  // ผูกเหตุการณ์เมื่อผู้ใช้กดปุ่มรูปกระดิ่ง
  document.getElementById('notiBellBtn').addEventListener('click', async function() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      alert('เบราว์เซอร์ของคุณไม่รองรับการแจ้งเตือน');
      return;
    }

    try {
      const permission = await Notification.requestPermission();
      if (permission === 'granted') {
        const result = await registerPushSubscription();
        if (result && result.success) {
          alert('เปิดการแจ้งเตือนสำเร็จแล้ว!');
        } else {
          alert('เกิดข้อผิดพลาดฝั่งเซิร์ฟเวอร์: ' + (result.error || JSON.stringify(result)));
        }
      } else if (permission === 'denied') {
        alert('คุณได้ปฏิเสธการแจ้งเตือน โปรดเปิดสิทธิ์ในการตั้งค่าของเบราว์เซอร์');
      }
    } catch (err) {
      alert('เกิดข้อผิดพลาด: ' + err.message);
    }
  });
</script>
