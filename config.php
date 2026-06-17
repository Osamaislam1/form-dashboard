<?php
// config.php - copy this to config.local.php and fill in your values, then require config.local.php instead.

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'form_dashboard',
        'user'     => 'form_dashboard',
        'pass'     => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'         => 'Form Dashboard',
        'base_url'     => 'https://your-dashboard.example.com',
        'timezone'     => 'Asia/Kolkata',
        'session_name' => 'fdash',
    ],
    'mail' => [
        // Uses PHP mail() by default. Swap in PHPMailer/SMTP if you need it.
        'from_email' => 'dashboard@example.com',
        'from_name'  => 'Form Dashboard',
    ],
    'zepto' => [
        // Zepto Mail (zeptomail.com) API token — copy from Zepto dashboard → Settings → API Token
        // Leave empty if you don't use Zepto Mail for the dashboard's own outbound emails.
        'api_token' => '',
        'api_url'   => 'https://api.zeptomail.com/v1.1/',
    ],
    'cron' => [
        // Random secret for /public/cron.php — set this in config.local.php and
        // add to your server cron: curl -s "https://yourdomain/cron.php?secret=JD483jf93Hqkq0Ns9x72BZtQvs6tyW0C"
        'secret'              => 'JD483jf93Hqkq0Ns9x72BZtQvs6tyW0C',
        // How many hours of silence before a "site offline" alert fires
        'site_offline_hours'  => 48,
        // Hostinger Cron Setup Example:
        // 1. Log in to your Hostinger control panel.
        // 2. Go to Advanced → Cron Jobs.
        // 3. Add a new cron job with this command:
        //    curl -s "https://yourdomain.com/cron.php?secret=JD483jf93Hqkq0Ns9x72BZtQvs6tyW0C" > /dev/null
        // 4. Set the schedule to run every hour, e.g. 0 * * * *
    ],
];
