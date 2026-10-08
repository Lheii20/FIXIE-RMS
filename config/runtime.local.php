<?php
/**
 * Hostinger template. Copy as config/runtime.local.php on the server and
 * replace every placeholder. Never put real secrets in a shared copy.
 */
return [
    'app' => [
        'environment' => 'production',
        'url' => 'https://fixiedrms.com',
        'timezone' => 'Asia/Manila',
        'hosting_profile' => 'shared',
        'trust_proxy_https' => false,
    ],
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'user' => 'u804448657_usr_sCWOvWA6',
        'password' => 'B|3fgGL2F',
        'name' => 'u804448657_db_sCWOvWA6',
    ],
    'mail' => [
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'username' => 'fixie2026@fixiedrms.com',
        'password' => 'bioy-sgaq-gzrv-s5lq',
        'encryption' => 'ssl',
        'from' => 'fixie2026@fixiedrms.com',
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
