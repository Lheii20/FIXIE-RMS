<?php
declare(strict_types=1);

/**
 * Optional workflow email delivery.
 *
 * This service is deliberately best-effort: an SMTP outage must never cancel
 * a valid approval, funding, delivery, or collection workflow action. The
 * normal in-app notification remains the authoritative notification channel.
 */

require_once __DIR__ . '/runtime.php';
require_once __DIR__ . '/mailer.php';
require_once dirname(__DIR__) . '/libs/src/Exception.php';
require_once dirname(__DIR__) . '/libs/src/PHPMailer.php';
require_once dirname(__DIR__) . '/libs/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('drms_approval_email_url')) {
    function drms_approval_email_url(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        $applicationUrl = rtrim((string) drms_runtime_section('app')['url'], '/');

        if ($applicationUrl === '' || $relativePath === '') {
            return '';
        }

        // Only application-relative targets are accepted in notification email.
        if (str_contains($relativePath, '://') || str_starts_with($relativePath, '//')) {
            return '';
        }

        return $applicationUrl . '/' . ltrim($relativePath, '/');
    }
}

if (!function_exists('drms_send_approval_email_to_role')) {
    /**
     * Send an optional email to active role members who explicitly enabled
     * approval emails in Account Settings. Returns the number accepted by SMTP.
     */
    function drms_send_approval_email_to_role(
        mysqli $conn,
        string $targetRole,
        string $subject,
        string $message,
        string $relativePath = ''
    ): int {
        $targetRole = trim($targetRole);
        $subject = trim($subject);
        $message = trim($message);

        if ($targetRole === '' || $subject === '' || $message === '') {
            return 0;
        }

        try {
            if (!drms_runtime_mail_is_configured()) {
                return 0;
            }

            $recipientQuery = $conn->prepare(
                "SELECT u.full_name, u.email
                 FROM users AS u
                 INNER JOIN user_preferences AS preferences
                    ON preferences.user_id = u.user_id
                 WHERE u.role = ?
                   AND u.status = 'Active'
                   AND preferences.approval_email_enabled = 1
                   AND u.email IS NOT NULL
                   AND u.email <> ''"
            );
            $recipientQuery->bind_param('s', $targetRole);
            $recipientQuery->execute();
            $recipients = $recipientQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $recipientQuery->close();
        } catch (Throwable $exception) {
            // The preference migration may not yet exist on an older database.
            error_log('Approval email recipients could not be loaded: ' . $exception->getMessage());
            return 0;
        }

        if (!$recipients) {
            return 0;
        }

        $safeSubject = substr(preg_replace('/[\r\n]+/', ' ', $subject) ?: 'Fixie DRMS approval update', 0, 180);
        $safeMessage = substr(preg_replace('/[\r\n]+/', "\n", $message) ?: '', 0, 4000);
        $actionUrl = drms_approval_email_url($relativePath);
        $sentCount = 0;

        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string) ($recipient['email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            try {
                $mail = new PHPMailer(true);
                drms_configure_mailer($mail, ['from_name' => 'Fixie DRMS Notifications']);
                $mail->addAddress($email, trim((string) ($recipient['full_name'] ?? '')));
                $mail->isHTML(false);
                $mail->Subject = $safeSubject;
                $mail->Body = "Hello " . (trim((string) ($recipient['full_name'] ?? '')) ?: 'User') . ",\n\n"
                    . $safeMessage . "\n\n"
                    . ($actionUrl !== '' ? "Open Fixie DRMS: " . $actionUrl . "\n\n" : '')
                    . "You are receiving this because Approval email notifications are enabled in your Account Settings.\n"
                    . "Fixie Computer Ventures";
                $mail->send();
                $sentCount++;
            } catch (Throwable $exception) {
                error_log('Approval email delivery failed for role ' . $targetRole . ': ' . $exception->getMessage());
            }
        }

        return $sentCount;
    }
}
