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

      // บันทึกลงฐานข้อมูลผ่าน save_subscription.php
      const res = await fetch('save_subscription.php', {
        method: 'POST',
        body: JSON.stringify(subscription),
        headers: { 'Content-Type': 'application/json' }
      });

      const data = await res.json();
      return data;
    } catch (err) {
      console.error('Push Registration Error:', err);
      throw err;
    }
  }

  // ลงทะเบียน Service Worker และทำงานอัตโนมัติหากเคยเปิดสิทธิ์ไว้แล้ว
  if ('serviceWorker' in navigator && 'PushManager' in window) {
    navigator.serviceWorker.register('sw.js')
      .then(async reg => {
        console.log('Service Worker Registered');
        // หากผู้ใช้เคยอนุญาตสิทธิ์ไว้แล้ว ให้บันทึกข้อมูลเข้า DB ทันทีโดยไม่ต้องรอคลิก
        if (Notification.permission === 'granted') {
          await registerPushSubscription();
        }
      })
      .catch(err => console.error('SW Registration Error:', err));
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
          alert('เกิดข้อผิดพลาดฝั่งเซิร์ฟเวอร์: ' + JSON.stringify(result));
        }
      } else if (permission === 'denied') {
        alert('คุณได้ปฏิเสธการแจ้งเตือน โปรดเปิดสิทธิ์ในการตั้งค่าของเบราว์เซอร์');
      }
    } catch (err) {
      alert('เกิดข้อผิดพลาด: ' + err.message);
    }
  });
</script>
