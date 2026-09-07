<?php

require_once __DIR__ . '/runtime.php';

function drms_configure_mailer($mail, array $overrides = []) {
    $mailConfig = drms_runtime_section('mail');
    $host = (string) $mailConfig['host'];
    $port = (int) $mailConfig['port'];
    $username = (string) $mailConfig['username'];
    $password = (string) $mailConfig['password'];
    $secure = (string) $mailConfig['encryption'];
    $timeout = (int) $mailConfig['timeout_seconds'];

    $from = $overrides['from'] ?? ((string) $mailConfig['from'] ?: $username);
    $fromName = $overrides['from_name'] ?? (string) $mailConfig['from_name'];

    if (
        $username === '' ||
        $password === '' ||
        filter_var($from, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new RuntimeException('SMTP credentials are not configured in config/runtime.local.php or the server environment.');
    }

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->Port = $port;
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->Timeout = $timeout;
    $mail->Timelimit = $timeout;
    $mail->SMTPDebug = 0;
    $mail->SMTPAutoTLS = true;
    $mail->SMTPKeepAlive = false;

    if ($secure === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($from, $fromName);
}

