<?php
/*
 * ตั้งค่า Web Push — ห้ามเปิดเผยไฟล์นี้ และห้ามอัปขึ้น Git
 *
 * PUBLIC ต้องเป็นคีย์ตัวเดียวกับที่ฝั่งเบราว์เซอร์ใช้เป็น applicationServerKey ตอน subscribe
 * ถ้ายังไม่มีคีย์ สร้างด้วย:
 *   php -r "require 'vendor/autoload.php'; print_r(Minishlink\WebPush\VAPID::createVapidKeys());"
 * (ถ้าเปลี่ยนคีย์ใหม่ ทุกคนต้อง subscribe ใหม่ เพราะ subscription เดิมผูกกับคีย์เดิม)
 */
const PUSH_VAPID_SUBJECT = 'mailto:natthapatinsangchan@gmail.com';   // อีเมลผู้ดูแลระบบ
const PUSH_VAPID_PUBLIC  = 'BDcVYH88lfTwHxM-yyzWmQzIc6_orJpWxXr156VsSppe-srH4z1gYGz0W6z9I67JHkBkcnnbrwqoLuBZLAzC7HA';
const PUSH_VAPID_PRIVATE = 'w86Ccb6pIc6w6EMWun3uVj9zfDe0Q-44hA2asE_RUSs';
const PUSH_CRON_TOKEN    = 'tXwaV/thNEFhSVQ9/d/4scXFeLFcP4CFMy6phyhooqQ=';     // ใช้กับ push_cron.php?key=... เท่านั้น
