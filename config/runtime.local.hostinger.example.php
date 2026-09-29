<?php
/**
 * Fixie DRMS - Hostinger production configuration template.
 *
 * Copy this file as config/runtime.local.php in the deployed application,
 * then replace every YOUR_* value locally. Never commit the completed file.
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
        // Use the exact database host displayed by Hostinger hPanel.
        // For a database on the same Hostinger account, this is commonly localhost.
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'YOUR_HOSTINGER_DATABASE_USER',
        'password' => 'YOUR_HOSTINGER_DATABASE_PASSWORD',
        'name' => 'YOUR_HOSTINGER_DATABASE_NAME',
    ],

    'mail' => [
        // Recommended: a mailbox created for your domain in Hostinger hPanel.
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'username' => 'security@YOUR-DOMAIN.COM',
        'password' => 'YOUR_HOSTINGER_EMAIL_PASSWORD',
        'encryption' => 'ssl',
        'from' => 'security@YOUR-DOMAIN.COM',
        'from_name' => 'Fixie DRMS Security',
        'timeout_seconds' => 20,
        'required' => true,
    ],

    'uploads' => [
        // Match this with upload_max_filesize and post_max_size in Hostinger PHP Configuration.
        'host_max_mb' => 25,
    ],

    'features' => [
        // Keep these enabled unless the selected Hostinger plan explicitly cannot support them.
        'scheduled_maintenance' => true,
        'server_backup' => true,
        'large_uploads' => true,
    ],
];
