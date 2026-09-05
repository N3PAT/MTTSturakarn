<?php
require_once __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\VAPID;

$vapidKeys = VAPID::createVapidKeys();

echo "Public Key: " . $vapidKeys['publicKey'] . "<br><br>";
echo "Private Key: " . $vapidKeys['privateKey'];
