<?php
declare(strict_types=1);

/** Read preferences without breaking older installations before the migration. */
function drms_load_user_preferences(mysqli $conn, int $userId): array
{
    $preferences = [
        'theme' => 'light',
        'approval_email_enabled' => false,
        'installed' => false,
    ];

    if ($userId < 1) {
        return $preferences;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT theme, approval_email_enabled FROM user_preferences WHERE user_id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() !== 1146) {
            throw $exception;
        }
        return $preferences;
    }

    $preferences['installed'] = true;
    if ($row) {
        $preferences['theme'] = $row['theme'] === 'dark' ? 'dark' : 'light';
        $preferences['approval_email_enabled'] = (int) $row['approval_email_enabled'] === 1;
    }
    return $preferences;
}
