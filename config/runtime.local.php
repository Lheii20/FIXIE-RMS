<?php
/**
 * Copy only the mail section into config/runtime.local.php, then enter the
 * real SMTP values locally. Do not upload or commit this example unchanged.
 */

return [
    'mail' => [
        // Hostinger mailbox example. For Gmail, use smtp.gmail.com / 587 / tls
        // and a Google App Password instead of the normal account password.
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'tamayolhei5@gmail.com',
        'password' => 'wewnzrsryelddatr',
        'encryption' => 'tls',
        'from' => 'tamayolhei5@gmail.com',
        'from_name' => 'Fixie DRMS Security',
        'timeout_seconds' => 20,
        'required' => true,
    ],
];
