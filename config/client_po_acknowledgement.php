<?php

if (!function_exists('phase6b2_is_installed')) {
    function phase6b2_is_installed(mysqli $conn): bool
    {
        static $result_by_database = [];

        $database_result = $conn->query('SELECT DATABASE() AS database_name');
        $database_name = $database_result
            ? (string) ($database_result->fetch_assoc()['database_name'] ?? '')
            : '';

        if ($database_name !== '' && array_key_exists($database_name, $result_by_database)) {
            return $result_by_database[$database_name];
        }

        $table_statement = $conn->prepare(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'client_po_internal_acknowledgements'
             LIMIT 1"
        );
        $table_statement->execute();
        $table_exists = $table_statement->get_result()->num_rows === 1;

        $status_statement = $conn->prepare(
            "SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'quotations'
               AND column_name = 'status'
             LIMIT 1"
        );
        $status_statement->execute();
        $status_row = $status_statement->get_result()->fetch_assoc();
        $status_ready = $status_row &&
            str_contains(
                (string) $status_row['COLUMN_TYPE'],
                'For GM Acknowledgement'
            );

        $installed = $table_exists && $status_ready;

        if ($database_name !== '') {
            $result_by_database[$database_name] = $installed;
        }

        return $installed;
    }
}

if (!function_exists('phase6b2_get_active_acknowledgement')) {
    function phase6b2_get_active_acknowledgement(
        mysqli $conn,
        int $approval_record_id
    ): ?array {
        if ($approval_record_id < 1 || !phase6b2_is_installed($conn)) {
            return null;
        }

        $statement = $conn->prepare(
            "SELECT
                ack.*,
                COALESCE(u.full_name, ack.signatory_name) AS actor_name
             FROM client_po_internal_acknowledgements ack
             LEFT JOIN users u ON u.user_id = ack.acted_by
             WHERE ack.approval_record_id = ?
               AND ack.record_status = 'Active'
             ORDER BY ack.acknowledgement_id DESC
             LIMIT 1"
        );
        $statement->bind_param('i', $approval_record_id);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return $row ?: null;
    }
}

if (!function_exists('phase6b2_create_notification')) {
    function phase6b2_create_notification(
        mysqli $conn,
        string $target_role,
        string $message,
        string $target_url,
        string $notification_key,
        ?int $recipient_user_id = null
    ): int {
        $statement = $conn->prepare(
            "INSERT INTO notifications (
                target_role,
                recipient_user_id,
                message,
                target_url,
                notification_key
             ) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                message = VALUES(message),
                target_url = VALUES(target_url)"
        );
        $statement->bind_param(
            'sisss',
            $target_role,
            $recipient_user_id,
            $message,
            $target_url,
            $notification_key
        );
        $statement->execute();

        $notification_id = (int) $statement->insert_id;

        if ($notification_id < 1) {
            $lookup_statement = $conn->prepare(
                'SELECT notif_id FROM notifications WHERE notification_key = ? LIMIT 1'
            );
            $lookup_statement->bind_param('s', $notification_key);
            $lookup_statement->execute();
            $notification_id = (int) (
                $lookup_statement->get_result()->fetch_assoc()['notif_id'] ?? 0
            );
        }

        if ($notification_id < 1) {
            throw new RuntimeException('The workflow notification could not be created.');
        }

        if ($recipient_user_id !== null) {
            $state_statement = $conn->prepare(
                "INSERT IGNORE INTO notification_user_states (
                    notif_id,
                    user_id,
                    is_read,
                    is_pinned,
                    is_deleted
                 )
                 SELECT ?, user_id, 0, 0, 0
                 FROM users
                 WHERE user_id = ?
                   AND role = ?
                   AND status = 'Active'"
            );
            $state_statement->bind_param(
                'iis',
                $notification_id,
                $recipient_user_id,
                $target_role
            );
        } else {
            $state_statement = $conn->prepare(
                "INSERT IGNORE INTO notification_user_states (
                    notif_id,
                    user_id,
                    is_read,
                    is_pinned,
                    is_deleted
                 )
                 SELECT ?, user_id, 0, 0, 0
                 FROM users
                 WHERE role = ?
                   AND status = 'Active'"
            );
            $state_statement->bind_param(
                'is',
                $notification_id,
                $target_role
            );
        }

        $state_statement->execute();

        return $notification_id;
    }
}

if (!function_exists('phase6b2_mark_notification_read')) {
    function phase6b2_mark_notification_read(
        mysqli $conn,
        string $notification_key
    ): void {
        $statement = $conn->prepare(
            "UPDATE notification_user_states nus
             INNER JOIN notifications n ON n.notif_id = nus.notif_id
             SET
                nus.is_read = 1,
                nus.read_at = COALESCE(nus.read_at, NOW())
             WHERE n.notification_key = ?"
        );
        $statement->bind_param('s', $notification_key);
        $statement->execute();
    }
}
