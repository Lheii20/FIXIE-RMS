<?php
session_start();

require '../config/db_connect.php';
require '../config/functions.php';

if (
    !isset($_SESSION['user_id']) ||
    $_SESSION['role'] !== 'Sales Staff'
) {
    header("Location: ../index.php");
    exit();
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['action'])
) {
    header("Location: ../dashboard.php");
    exit();
}

if (
    !isset($_POST['csrf_token']) ||
    !hash_equals(
        (string) ($_SESSION['csrf_token'] ?? ''),
        (string) $_POST['csrf_token']
    )
) {
    die("Security Error: Invalid CSRF Token");
}

$action = trim((string) $_POST['action']);

/*
|--------------------------------------------------------------------------
| CREATE DETAILED QUOTATION
|--------------------------------------------------------------------------
*/

if ($action === 'create_detailed_quotation') {
    $data = [
        'quotation_number' => trim((string) ($_POST['quotation_number'] ?? '')),
        'client_name' => trim((string) ($_POST['client_name'] ?? '')),
        'grand_total' => round(
            (float) ($_POST['amount'] ?? 0),
            2
        ),
        'items' => $_POST['items'] ?? []
    ];

    if (
        $data['quotation_number'] === '' ||
        $data['client_name'] === ''
    ) {
        header(
            "Location: ../create_quotation.php?error=" .
            rawurlencode(
                "Quotation number and client name are required."
            )
        );
        exit();
    }

    if (
        !is_array($data['items']) ||
        empty($data['items'])
    ) {
        header(
            "Location: ../create_quotation.php?error=" .
            rawurlencode(
                "Quotation must have at least one item."
            )
        );
        exit();
    }

    if ($data['grand_total'] <= 0) {
        header(
            "Location: ../create_quotation.php?error=" .
            rawurlencode(
                "Quotation total must be greater than zero."
            )
        );
        exit();
    }

    $result = create_detailed_quotation(
        $conn,
        $data,
        (int) $_SESSION['user_id']
    );

    if ($result) {
        header(
            "Location: ../quotations_list.php?success=" .
            rawurlencode(
                "Detailed Quotation Created."
            )
        );
    } else {
        header(
            "Location: ../create_quotation.php?error=" .
            rawurlencode(
                "Failed to save quotation."
            )
        );
    }

    exit();
}

/*
|--------------------------------------------------------------------------
| RECORD CLIENT CONFIRMATION OR OFFICIAL CLIENT PO
|--------------------------------------------------------------------------
|
| Supporting confirmations:
| - Messenger
| - Viber / WhatsApp
| - Email
| - Signed quotation
| - In-person confirmation
| - Other written confirmation
|
| These records do not make the quotation eligible for PRF creation.
|
| Official Client PO:
| - Requires actual Client PO number
| - Requires Client PO date
| - Requires final approval date
| - Requires signed proof
| - Changes quotation status to "PO Received"
|
*/

if ($action === 'receive_po') {
    $quotation_id = (int) ($_POST['quotation_id'] ?? 0);
    $approval_mode = trim(
        (string) ($_POST['approval_mode'] ?? '')
    );

    $actual_client_po_number = trim(
        (string) ($_POST['actual_client_po_number'] ?? '')
    );

    $client_po_date_input = trim(
        (string) ($_POST['client_po_date'] ?? '')
    );

    $final_approval_date_input = trim(
        (string) ($_POST['final_approval_date'] ?? '')
    );

    $remarks = trim(
        (string) ($_POST['remarks'] ?? '')
    );

    $user_id = (int) $_SESSION['user_id'];

    $allowed_modes = [
        'Messenger Chat',
        'Viber / WhatsApp Chat',
        'Email Confirmation',
        'Signed Quotation',
        'Official Client PO',
        'In-Person Confirmation',
        'Other Written Confirmation'
    ];

    $is_official_client_po = (
        $approval_mode === 'Official Client PO'
    );

    $record_type = $is_official_client_po
        ? 'Official Client PO'
        : 'Supporting Confirmation';

    /*
    |--------------------------------------------------------------------------
    | BASIC INPUT VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($quotation_id < 1) {
        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                "Please select a valid quotation."
            )
        );
        exit();
    }

    if (
        !in_array(
            $approval_mode,
            $allowed_modes,
            true
        )
    ) {
        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                "Please provide a valid client approval mode."
            )
        );
        exit();
    }

    if (strlen($remarks) > 2000) {
        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                "Remarks must not exceed 2,000 characters."
            )
        );
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | OFFICIAL CLIENT PO VALIDATION
    |--------------------------------------------------------------------------
    */

    $client_po_date = null;
    $final_approval_date = null;

    if ($is_official_client_po) {
        if (
            $actual_client_po_number === '' ||
            strlen($actual_client_po_number) > 100
        ) {
            header(
                "Location: ../quotations_list.php?error=" .
                rawurlencode(
                    "Enter the actual Client PO number. Maximum length is 100 characters."
                )
            );
            exit();
        }

        if (
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $actual_client_po_number
            )
        ) {
            header(
                "Location: ../quotations_list.php?error=" .
                rawurlencode(
                    "The Client PO number contains invalid characters."
                )
            );
            exit();
        }

        $client_po_date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $client_po_date_input
        );

        $final_approval_date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $final_approval_date_input
        );

        if (
            !$client_po_date ||
            $client_po_date->format('Y-m-d') !== $client_po_date_input
        ) {
            header(
                "Location: ../quotations_list.php?error=" .
                rawurlencode(
                    "Enter a valid Client PO date."
                )
            );
            exit();
        }

        if (
            !$final_approval_date ||
            $final_approval_date->format('Y-m-d') !==
                $final_approval_date_input
        ) {
            header(
                "Location: ../quotations_list.php?error=" .
                rawurlencode(
                    "Enter a valid final client approval date."
                )
            );
            exit();
        }

        $today = new DateTimeImmutable('today');

        if (
            $client_po_date > $today ||
            $final_approval_date > $today
        ) {
            header(
                "Location: ../quotations_list.php?error=" .
                rawurlencode(
                    "Client PO and final approval dates cannot be in the future."
                )
            );
            exit();
        }

        $client_po_date_input = $client_po_date->format(
            'Y-m-d'
        );

        $final_approval_date_input =
            $final_approval_date->format('Y-m-d');
    } else {
        /*
         * Supporting confirmation records must not accidentally
         * contain or update official Client PO information.
         */
        $actual_client_po_number = null;
        $client_po_date_input = null;
        $final_approval_date_input = null;
    }

    /*
    |--------------------------------------------------------------------------
    | PROOF FILE VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_FILES['po_file']) ||
        $_FILES['po_file']['error'] !== UPLOAD_ERR_OK ||
        !is_uploaded_file($_FILES['po_file']['tmp_name'])
    ) {
        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                "Please attach the client approval proof."
            )
        );
        exit();
    }

    $file = $_FILES['po_file'];
    $maximum_file_size = 10 * 1024 * 1024;

    $original_file_name = basename(
        (string) $file['name']
    );

    $file_extension = strtolower(
        pathinfo(
            $original_file_name,
            PATHINFO_EXTENSION
        )
    );

    $allowed_files = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png'
    ];

    if (
        $original_file_name === '' ||
        strlen($original_file_name) > 255
    ) {
        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                "The proof file name is invalid or too long."
            )
        );
        exit();
    }

    if (
        $file['size'] < 1 ||
        $file['size'] > $maximum_file_size ||
        !array_key_exists(
            $file_extension,
            $allowed_files
        )
    ) {
        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                "Proof must be a PDF, JPG, or PNG file no larger than 10 MB."
            )
        );
        exit();
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);

    if (
        $mime_type !== $allowed_files[$file_extension]
    ) {
        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                "The uploaded proof is not a valid PDF or image."
            )
        );
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE APPROVAL RECORD
    |--------------------------------------------------------------------------
    */

    $upload_directory =
        __DIR__ . '/../uploads/pos/';

    $stored_file_name = null;
    $stored_file_path = null;

    try {
        /*
         * Phase 1A must be installed before this handler.
         */
        $table_check = $conn->query(
            "SHOW TABLES LIKE 'client_approval_records'"
        );

        if (
            !$table_check ||
            $table_check->num_rows === 0
        ) {
            throw new RuntimeException(
                "Phase 1A database preparation is required before recording client approvals."
            );
        }

        if (
            !is_dir($upload_directory) &&
            !mkdir(
                $upload_directory,
                0755,
                true
            ) &&
            !is_dir($upload_directory)
        ) {
            throw new RuntimeException(
                "Unable to create the client approval proof directory."
            );
        }

        $conn->begin_transaction();

        /*
         * Lock the quotation to prevent simultaneous official
         * approval submissions.
         */
        $quotation_statement = $conn->prepare(
            "SELECT
                quotation_id,
                quotation_number,
                client_name,
                status
             FROM quotations
             WHERE quotation_id = ?
             FOR UPDATE"
        );

        $quotation_statement->bind_param(
            "i",
            $quotation_id
        );

        $quotation_statement->execute();

        $quotation = $quotation_statement
            ->get_result()
            ->fetch_assoc();

        if (!$quotation) {
            throw new RuntimeException(
                "The selected quotation was not found."
            );
        }

        if (
            !in_array(
                $quotation['status'],
                ['Pending Approval', 'Pending PO'],
                true
            )
        ) {
            throw new RuntimeException(
                "This quotation is no longer waiting for client approval."
            );
        }

        /*
         * Prevent the same client PO number from being assigned
         * to another quotation belonging to the same client.
         */
        if ($is_official_client_po) {
            $duplicate_statement = $conn->prepare(
                "SELECT car.approval_record_id
                 FROM client_approval_records car
                 INNER JOIN quotations q
                    ON q.quotation_id = car.quotation_id
                 WHERE car.record_type = 'Official Client PO'
                   AND car.record_status = 'Active'
                   AND car.actual_client_po_number = ?
                   AND q.client_name = ?
                   AND car.quotation_id <> ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $duplicate_statement->bind_param(
                "ssi",
                $actual_client_po_number,
                $quotation['client_name'],
                $quotation_id
            );

            $duplicate_statement->execute();

            if (
                $duplicate_statement
                    ->get_result()
                    ->num_rows > 0
            ) {
                throw new RuntimeException(
                    "This Client PO number is already assigned to another quotation for the same client."
                );
            }
        }

        /*
         * Store the validated proof with a randomized server name.
         */
        $stored_file_name =
            time() .
            '_client_approval_' .
            bin2hex(random_bytes(12)) .
            '.' .
            $file_extension;

        $stored_file_path =
            $upload_directory .
            $stored_file_name;

        if (
            !move_uploaded_file(
                $file['tmp_name'],
                $stored_file_path
            )
        ) {
            throw new RuntimeException(
                "The approval proof could not be saved."
            );
        }

        $proof_file_hash = hash_file(
            'sha256',
            $stored_file_path
        );

        if (!$proof_file_hash) {
            throw new RuntimeException(
                "The approval proof could not be verified."
            );
        }

        /*
         * Insert with a temporary unique reference first.
         * The generated reference will use the auto-increment ID,
         * preventing duplicate reference numbers.
         */
        $temporary_reference =
            'TMP-' .
            bin2hex(random_bytes(16));

        $insert_statement = $conn->prepare(
            "INSERT INTO client_approval_records (
                quotation_id,
                internal_reference,
                record_type,
                approval_mode,
                actual_client_po_number,
                client_po_date,
                final_approval_date,
                proof_original_name,
                proof_file_path,
                proof_file_hash,
                remarks,
                recorded_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insert_statement->bind_param(
            "issssssssssi",
            $quotation_id,
            $temporary_reference,
            $record_type,
            $approval_mode,
            $actual_client_po_number,
            $client_po_date_input,
            $final_approval_date_input,
            $original_file_name,
            $stored_file_name,
            $proof_file_hash,
            $remarks,
            $user_id
        );

        $insert_statement->execute();

        $approval_record_id = (int) $conn->insert_id;

        $internal_reference = sprintf(
            'CAR-%s-%06d',
            date('Y'),
            $approval_record_id
        );

        $reference_statement = $conn->prepare(
            "UPDATE client_approval_records
             SET internal_reference = ?
             WHERE approval_record_id = ?"
        );

        $reference_statement->bind_param(
            "si",
            $internal_reference,
            $approval_record_id
        );

        $reference_statement->execute();

        if (
            $reference_statement->affected_rows !== 1
        ) {
            throw new RuntimeException(
                "The internal approval reference could not be generated."
            );
        }

        /*
         * Only an official signed Client PO may change the
         * quotation to "PO Received".
         */
        if ($is_official_client_po) {
            $quotation_update_statement =
                $conn->prepare(
                    "UPDATE quotations
                     SET
                        client_po_number = ?,
                        approval_mode = ?,
                        po_file_path = ?,
                        status = 'PO Received'
                     WHERE quotation_id = ?
                       AND status IN (
                            'Pending Approval',
                            'Pending PO'
                       )"
                );

            $quotation_update_statement->bind_param(
                "sssi",
                $actual_client_po_number,
                $approval_mode,
                $stored_file_name,
                $quotation_id
            );

            $quotation_update_statement->execute();

            if (
                $quotation_update_statement
                    ->affected_rows !== 1
            ) {
                throw new RuntimeException(
                    "The quotation status changed before the official Client PO could be saved."
                );
            }
        }

        $audit_action = $is_official_client_po
            ? 'RECEIVE_OFFICIAL_CLIENT_PO'
            : 'RECORD_CLIENT_CONFIRMATION';

        $audit_description = $is_official_client_po
            ? "Recorded official Client PO {$actual_client_po_number} for Quotation {$quotation['quotation_number']}."
            : "Recorded supporting client confirmation via {$approval_mode} for Quotation {$quotation['quotation_number']}.";

        log_audit_action(
            $conn,
            $user_id,
            $audit_action,
            $audit_description,
            null,
            [
                'approval_record_id' =>
                    $approval_record_id,
                'internal_reference' =>
                    $internal_reference,
                'quotation_id' =>
                    $quotation_id,
                'quotation_number' =>
                    $quotation['quotation_number'],
                'record_type' =>
                    $record_type,
                'approval_mode' =>
                    $approval_mode,
                'actual_client_po_number' =>
                    $actual_client_po_number,
                'client_po_date' =>
                    $client_po_date_input,
                'final_approval_date' =>
                    $final_approval_date_input,
                'proof_file' =>
                    $stored_file_name
            ]
        );

        $conn->commit();

        if ($is_official_client_po) {
            $success_message =
                "Official Client PO recorded successfully. Internal Reference: {$internal_reference}";
        } else {
            $success_message =
                "Supporting client confirmation recorded. The quotation will remain pending until the official signed Client PO is received. Internal Reference: {$internal_reference}";
        }

        header(
            "Location: ../quotations_list.php?success=" .
            rawurlencode($success_message)
        );
    } catch (Throwable $exception) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log(
                "Client approval rollback error: " .
                $rollback_exception->getMessage()
            );
        }

        if (
            $stored_file_path &&
            is_file($stored_file_path)
        ) {
            unlink($stored_file_path);
        }

        error_log(
            "Client approval recording error: " .
            $exception->getMessage()
        );

        header(
            "Location: ../quotations_list.php?error=" .
            rawurlencode(
                $exception->getMessage()
            )
        );
    }

    exit();
}

/*
|--------------------------------------------------------------------------
| UNKNOWN ACTION
|--------------------------------------------------------------------------
*/

header(
    "Location: ../dashboard.php?error=" .
    rawurlencode(
        "Unsupported quotation action."
    )
);
exit();
?>