<?php

function drms_configure_mailer($mail, array $overrides = []) {
    $host = getenv('DRMS_SMTP_HOST') ?: 'smtp.gmail.com';
    $port = (int) (getenv('DRMS_SMTP_PORT') ?: 587);
    $username = getenv('DRMS_SMTP_USER') ?: '';
    $password = getenv('DRMS_SMTP_PASS') ?: '';
    $secure = strtolower(getenv('DRMS_SMTP_SECURE') ?: 'tls');

    $from = $overrides['from'] ?? (getenv('DRMS_MAIL_FROM') ?: $username);
    $fromName = $overrides['from_name'] ?? (getenv('DRMS_MAIL_FROM_NAME') ?: 'Fixie DRMS Security');

    if ($username === '' || $password === '' || $from === '') {
        throw new RuntimeException('SMTP credentials are not configured. Set DRMS_SMTP_USER, DRMS_SMTP_PASS, and DRMS_MAIL_FROM.');
    }

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->Port = $port;

    if ($secure === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($from, $fromName);
}

