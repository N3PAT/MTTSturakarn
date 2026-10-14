<?php

define('PUSH_VAPID_SUBJECT', getenv('VAPID_SUBJECT') ?: 'mailto:admin@example.com');
define('PUSH_VAPID_PUBLIC',  getenv('VAPID_PUBLIC_KEY') ?: '');
define('PUSH_VAPID_PRIVATE', getenv('VAPID_PRIVATE_KEY') ?: '');
define('PUSH_CRON_TOKEN',    getenv('PUSH_CRON_TOKEN') ?: '');
