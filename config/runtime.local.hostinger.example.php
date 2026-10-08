<?php
/**
 * Hostinger template. Copy as config/runtime.local.php on the server and
 * replace every placeholder. Never put real secrets in a shared copy.
 */
return [
    'app' => [
        'environment' => 'production',
        'url' => 'https://YOUR-DOMAIN.COM',
        'timezone' => 'Asia/Manila',
        'hosting_profile' => 'shared',
        'trust_proxy_https' => false,
    ],
    'database' => [
        'host' => 'YOUR_DATABASE_HOST_FROM_HPANEL',
        'port' => 3306,
        'user' => 'YOUR_HOSTINGER_DATABASE_USER',
        'password' => 'YOUR_HOSTINGER_DATABASE_PASSWORD',
        'name' => 'YOUR_HOSTINGER_DATABASE_NAME',
    ],
    'mail' => [
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'username' => 'security@YOUR-DOMAIN.COM',
        'password' => 'YOUR_EMAIL_ACCOUNT_PASSWORD',
        'encryption' => 'ssl',
        'from' => 'security@YOUR-DOMAIN.COM',
        'from_name' => 'Fixie DRMS Security',
        'timeout_seconds' => 20,
        'required' => true,
    ],
    'uploads' => [
        'host_max_mb' => 25,
    ],
    'features' => [
        'scheduled_maintenance' => true,
        'server_backup' => true,
        'large_uploads' => true,
    ],
];
