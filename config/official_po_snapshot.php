<?php

if (!class_exists('DrmsPrfPdfBuilder')) {
    require_once __DIR__ . '/official_prf_snapshot.php';
}

function drms_po_pdf_date(?string $value, string $format = 'M d, Y'): string
{
    if (!$value) {
        return 'Not recorded';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : 'Not recorded';
}

function drms_po_pdf_money($value): string
{
    return 'PHP ' . number_format((float) $value, 2);
}

function drms_verify_official_source_file(array $record, string $label): void
{
    $record_number = trim((string) ($record['record_number'] ?? ''));
    $stored_path = trim((string) ($record['file_path'] ?? ''));
    $expected_hash = strtolower(trim((string) ($record['file_hash'] ?? '')));

    if (
        (int) ($record['doc_id'] ?? 0) < 1 ||
        $record_number === '' ||
        $stored_path === '' ||
        !preg_match('/^[a-f0-9]{64}$/', $expected_hash) ||
        ($record['record_phase'] ?? '') !== 'Official' ||
        ($record['status'] ?? '') === 'Recycled' ||
        (int) ($record['is_locked'] ?? 0) !== 1
    ) {
        throw new RuntimeException(
            $label . ' is not available as a locked Official Record.'
        );
    }

    $project_root = realpath(dirname(__DIR__));
    $uploads_root = $project_root !== false
        ? realpath($project_root . DIRECTORY_SEPARATOR . 'uploads')
        : false;
    if ($project_root === false || $uploads_root === false) {
        throw new RuntimeException('Official Record storage is unavailable.');
    }

    $normalized_path = str_replace(
        ['/', '\\'],
        DIRECTORY_SEPARATOR,
        $stored_path
    );
    $normalized_path = ltrim($normalized_path, DIRECTORY_SEPARATOR);
    $uploads_prefix = 'uploads' . DIRECTORY_SEPARATOR;
    if (stripos($normalized_path, $uploads_prefix) === 0) {
        $normalized_path = substr($normalized_path, strlen($uploads_prefix));
    }

    $absolute_path = realpath(
        $uploads_root . DIRECTORY_SEPARATOR . $normalized_path
    );
    $required_prefix = rtrim($uploads_root, DIRECTORY_SEPARATOR) .
        DIRECTORY_SEPARATOR;
    if (
        $absolute_path === false ||
        !is_file($absolute_path) ||
        strncasecmp(
            $absolute_path,
            $required_prefix,
            strlen($required_prefix)
        ) !== 0
    ) {
        throw new RuntimeException($label . ' file is missing from protected storage.');
    }

    $actual_hash = strtolower((string) hash_file('sha256', $absolute_path));
    if (!hash_equals($expected_hash, $actual_hash)) {
        throw new RuntimeException($label . ' failed file-integrity verification.');
    }
}

function drms_load_authorized_po_snapshot_data(
    mysqli $conn,
    int $po_id
): array {
    if ($po_id < 1) {
        throw new InvalidArgumentException('The Purchase Order reference is invalid.');
    }

    $po_stmt = $conn->prepare(
        "SELECT
            po.*,
            creator.full_name AS creator_name,
            request.pr_number,
            request.status AS pr_status,
            request.current_approval_stage AS pr_approval_stage,
            request.final_approved_by AS pr_final_approved_by,
            request.final_approved_at AS pr_final_approved_at,
            request.client_approval_record_id,
            quotation.quotation_number AS source_quotation_number,
            client_po.actual_client_po_number,
            client_po.client_po_date,
            supplier.supplier_name,
            supplier.supplier_reference,
            supplier.supplier_quote_date,
            supplier.payment_method,
            supplier.payment_terms,
            supplier.remarks AS supplier_remarks,
            prf_record.doc_id AS prf_doc_id,
            prf_record.record_number AS prf_record_number,
            prf_record.file_path AS prf_file_path,
            prf_record.file_hash AS prf_file_hash,
            prf_record.record_phase AS prf_record_phase,
            prf_record.status AS prf_record_status,
            prf_record.is_locked AS prf_is_locked,
            client_po_record.doc_id AS client_po_doc_id,
            client_po_record.record_number AS client_po_record_number,
            client_po_record.file_path AS client_po_record_file_path,
            client_po_record.file_hash AS client_po_record_file_hash,
            client_po_record.record_phase AS client_po_record_phase,
            client_po_record.status AS client_po_record_status,
            client_po_record.is_locked AS client_po_is_locked
         FROM purchase_orders po
         INNER JOIN purchase_requests request
           ON request.pr_id = po.pr_id
         LEFT JOIN quotations quotation
           ON quotation.quotation_id = request.quotation_id
         INNER JOIN client_approval_records client_po
           ON client_po.approval_record_id = request.client_approval_record_id
          AND client_po.record_status = 'Active'
         INNER JOIN pr_supplier_details supplier
           ON supplier.supplier_detail_id = po.supplier_detail_id
          AND supplier.pr_id = request.pr_id
          AND supplier.record_status = 'Active'
         LEFT JOIN users creator
           ON creator.user_id = po.created_by
         LEFT JOIN documents prf_record
           ON prf_record.source_module = 'Purchase Requisition Form'
          AND prf_record.source_record_id = request.pr_id
         LEFT JOIN documents client_po_record
           ON client_po_record.source_module = 'Client PO Approval'
          AND client_po_record.source_record_id = request.client_approval_record_id
         WHERE po.po_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $po_stmt->bind_param('i', $po_id);
    $po_stmt->execute();
    $po = $po_stmt->get_result()->fetch_assoc();
    $po_stmt->close();

    if (!$po) {
        throw new RuntimeException(
            'The Purchase Order or its approved source records are unavailable.'
        );
    }

    $official_statuses = [
        'President-Approved',
        'Funded',
        'Delivery Requested',
        'For Pick-up/Delivery',
        'Delivered',
        'Partially-Collected',
        'Collected',
    ];
    if (
        !in_array($po['status'], $official_statuses, true) ||
        !in_array($po['pr_status'], ['Approved', 'Converted_to_PO'], true) ||
        $po['pr_approval_stage'] !== 'Official Approved' ||
        empty($po['pr_final_approved_by']) ||
        empty($po['pr_final_approved_at']) ||
        empty($po['date_created']) ||
        empty($po['pr_id']) ||
        empty($po['client_approval_record_id'])
    ) {
        throw new RuntimeException(
            'The Purchase Order has not completed its authoritative PRF authorization.'
        );
    }

    if (
        (string) $po['source_pr_final_approved_at'] !==
            (string) $po['pr_final_approved_at'] ||
        (int) $po['supplier_detail_id'] < 1 ||
        trim((string) $po['po_number']) === '' ||
        trim((string) $po['pr_number']) === '' ||
        trim((string) $po['actual_client_po_number']) === ''
    ) {
        throw new RuntimeException(
            'The Purchase Order does not match its approved PRF snapshot.'
        );
    }

    $source_prf_record = [
        'doc_id' => $po['prf_doc_id'],
        'record_number' => $po['prf_record_number'],
        'file_path' => $po['prf_file_path'],
        'file_hash' => $po['prf_file_hash'],
        'record_phase' => $po['prf_record_phase'],
        'status' => $po['prf_record_status'],
        'is_locked' => $po['prf_is_locked'],
    ];
    $source_client_po_record = [
        'doc_id' => $po['client_po_doc_id'],
        'record_number' => $po['client_po_record_number'],
        'file_path' => $po['client_po_record_file_path'],
        'file_hash' => $po['client_po_record_file_hash'],
        'record_phase' => $po['client_po_record_phase'],
        'status' => $po['client_po_record_status'],
        'is_locked' => $po['client_po_is_locked'],
    ];
    drms_verify_official_source_file($source_prf_record, 'The source PRF');
    drms_verify_official_source_file(
        $source_client_po_record,
        'The signed Client PO'
    );

    if (
        !preg_match('/^PRF-\d{4}-\d{4}$/', (string) $po['prf_record_number']) ||
        !preg_match('/^CPO-\d{4}-\d{4}$/', (string) $po['client_po_record_number'])
    ) {
        throw new RuntimeException(
            'The source records do not use their controlled folder codes.'
        );
    }

    $items_stmt = $conn->prepare(
        "SELECT
            item_id,
            source_pr_item_id,
            category,
            brand,
            item_name,
            specifications,
            quantity,
            unit_price,
            unit_cost,
            total_price,
            total_cost,
            line_profit_amount
         FROM po_items
         WHERE po_id = ?
         ORDER BY item_id
         FOR UPDATE"
    );
    $items_stmt->bind_param('i', $po_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    $items = [];
    $calculated_selling = 0.0;
    $calculated_cost = 0.0;
    while ($item = $items_result->fetch_assoc()) {
        $quantity = (int) $item['quantity'];
        $unit_cost = round((float) $item['unit_cost'], 2);
        $total_cost = round((float) $item['total_cost'], 2);
        $total_price = round((float) $item['total_price'], 2);
        if (
            (int) $item['source_pr_item_id'] < 1 ||
            $quantity < 1 ||
            trim((string) $item['item_name']) === '' ||
            $unit_cost <= 0 ||
            $total_cost <= 0 ||
            $total_price <= 0 ||
            abs($total_cost - round($quantity * $unit_cost, 2)) > 0.01
        ) {
            throw new RuntimeException(
                'An Internal PO line does not match its approved PRF item.'
            );
        }
        $calculated_selling = round($calculated_selling + $total_price, 2);
        $calculated_cost = round($calculated_cost + $total_cost, 2);
        $items[] = $item;
    }
    $items_stmt->close();

    if (empty($items)) {
        throw new RuntimeException('The Internal PO contains no approved item lines.');
    }

    $amount = round((float) $po['amount'], 2);
    $cost = round((float) $po['cost_of_goods_amount'], 2);
    $other_expense = round((float) $po['other_expense_amount'], 2);
    $requested_fund = round((float) $po['requested_fund_amount'], 2);
    $gross_profit = round((float) $po['gross_profit_amount'], 2);
    $gross_margin = round((float) $po['gross_margin_percent'], 4);
    $expected_margin = $amount > 0
        ? round(($gross_profit / $amount) * 100, 4)
        : 0.0;
    if (
        abs($calculated_selling - $amount) > 0.01 ||
        abs($calculated_cost - $cost) > 0.01 ||
        abs($requested_fund - round($cost + $other_expense, 2)) > 0.01 ||
        abs($gross_profit - round($amount - $requested_fund, 2)) > 0.01 ||
        abs($gross_margin - $expected_margin) > 0.001
    ) {
        throw new RuntimeException(
            'The Internal PO financial totals do not match its approved item snapshot.'
        );
    }

    $approval_stmt = $conn->prepare(
        "SELECT
            approval.approval_stage,
            approval.required_role,
            approval.stage_sequence,
            approval.decision,
            approval.acted_by,
            approval.acted_at,
            actor.full_name AS acted_by_name
         FROM pr_approval_records approval
         LEFT JOIN users actor
           ON actor.user_id = approval.acted_by
         WHERE approval.pr_id = ?
           AND approval.approval_cycle = (
                SELECT MAX(latest.approval_cycle)
                FROM pr_approval_records latest
                WHERE latest.pr_id = ?
           )
         ORDER BY approval.stage_sequence
         FOR UPDATE"
    );
    $pr_id = (int) $po['pr_id'];
    $approval_stmt->bind_param('ii', $pr_id, $pr_id);
    $approval_stmt->execute();
    $approval_result = $approval_stmt->get_result();
    $approvals = [];
    while ($approval = $approval_result->fetch_assoc()) {
        $approvals[$approval['approval_stage']] = $approval;
    }
    $approval_stmt->close();

    $requirements = [
        'GM Review' => ['GM', 1],
        'Finance Review' => ['Finance', 2],
        'Owner Approval' => ['President', 3],
    ];
    foreach ($requirements as $stage => $requirement) {
        $approval = $approvals[$stage] ?? null;
        if (
            !$approval ||
            $approval['required_role'] !== $requirement[0] ||
            (int) $approval['stage_sequence'] !== $requirement[1] ||
            $approval['decision'] !== 'Approved' ||
            (int) $approval['acted_by'] < 1 ||
            empty($approval['acted_at']) ||
            empty($approval['acted_by_name'])
        ) {
            throw new RuntimeException(
                'The source PRF approval signatures are incomplete.'
            );
        }
    }
    if (
        (int) $approvals['Owner Approval']['acted_by'] !==
            (int) $po['pr_final_approved_by'] ||
        (string) $approvals['Owner Approval']['acted_at'] !==
            (string) $po['pr_final_approved_at']
    ) {
        throw new RuntimeException(
            'The Owner signature does not match the PRF final approval.'
        );
    }

    $history_stmt = $conn->prepare(
        "SELECT
            history.status_from,
            history.status_to,
            history.changed_by,
            history.timestamp AS created_at,
            actor.full_name AS created_by_name
         FROM po_history history
         LEFT JOIN users actor
           ON actor.user_id = history.changed_by
         WHERE history.po_id = ?
           AND history.status_from = 'Official PRF'
           AND history.status_to = 'President-Approved'
         ORDER BY history.history_id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $history_stmt->bind_param('i', $po_id);
    $history_stmt->execute();
    $creation_history = $history_stmt->get_result()->fetch_assoc();
    $history_stmt->close();
    if (
        !$creation_history ||
        (int) $creation_history['changed_by'] !== (int) $po['created_by'] ||
        empty($creation_history['created_at']) ||
        empty($creation_history['created_by_name'])
    ) {
        throw new RuntimeException(
            'The Procurement creation record for the Internal PO is incomplete.'
        );
    }

    return [
        'po' => $po,
        'items' => $items,
        'approvals' => $approvals,
        'creation_history' => $creation_history,
    ];
}

function drms_render_official_po_pdf(
    array $snapshot,
    string $record_number
): string {
    $po = $snapshot['po'];
    $items = $snapshot['items'];
    $approvals = $snapshot['approvals'];
    $creation = $snapshot['creation_history'];

    $pdf = new DrmsPrfPdfBuilder(
        $record_number . ' - Internal Purchase Order',
        'Fixie Computer Ventures'
    );

    $navy = [22, 45, 77];
    $blue = [37, 99, 235];
    $green = [4, 120, 87];
    $ink = [24, 35, 52];
    $muted = [100, 116, 139];
    $border = [218, 226, 237];
    $soft = [246, 249, 253];

    $new_page = static function (
        DrmsPrfPdfBuilder $document,
        bool $continued = false
    ) use ($record_number, $po, $navy, $blue, $green, $ink, $muted): float {
        $document->addPage();
        $document->rectangle(34, 28, 527, 74, [248, 250, 253], [218, 226, 237]);
        $document->rectangle(34, 28, 7, 74, $blue);
        $document->text(54, 42, 'FIXIE COMPUTER VENTURES', 9.5, true, $blue);
        $document->text(54, 58, 'INTERNAL PURCHASE ORDER', 16, true, $navy);
        $document->text(
            54,
            80,
            $continued
                ? 'Official procurement record - continued'
                : 'Official procurement and supplier-funding basis',
            8.5,
            false,
            $muted
        );
        $document->rectangle(407, 42, 136, 25, [232, 248, 242], [167, 229, 203]);
        $document->text(407, 48, 'OFFICIAL - AUTHORIZED', 8.2, true, $green, 'center', 136);
        $document->text(407, 76, 'RMS: ' . $record_number, 9.2, true, $ink, 'center', 136);
        $document->text(407, 90, 'Business PO: ' . $po['po_number'], 7.6, false, $muted, 'center', 136);
        return 119.0;
    };

    $section_title = static function (
        DrmsPrfPdfBuilder $document,
        float $top,
        string $eyebrow,
        string $title
    ) use ($blue, $ink): float {
        $document->text(40, $top, strtoupper($eyebrow), 7.5, true, $blue);
        $document->text(40, $top + 14, $title, 11.5, true, $ink);
        $document->line(40, $top + 32, 555, $top + 32, [218, 226, 237], 0.7);
        return $top + 43;
    };

    $value_cell = static function (
        DrmsPrfPdfBuilder $document,
        float $x,
        float $top,
        float $width,
        string $label,
        string $value
    ) use ($soft, $border, $muted, $ink): void {
        $document->rectangle($x, $top, $width, 48, $soft, $border);
        $document->text($x + 10, $top + 8, strtoupper($label), 7, true, $muted);
        $document->wrappedText(
            $x + 10,
            $top + 23,
            $value,
            $width - 20,
            8.8,
            true,
            $ink,
            10.5,
            2
        );
    };

    $y = $new_page($pdf, false);
    $value_cell($pdf, 40, $y, 124.25, 'Business PO', (string) $po['po_number']);
    $value_cell($pdf, 170, $y, 124.25, 'Official PRF', (string) $po['prf_record_number']);
    $value_cell($pdf, 300, $y, 124.25, 'Signed Client PO', (string) $po['client_po_record_number']);
    $value_cell($pdf, 430, $y, 125, 'Prepared', drms_po_pdf_date($creation['created_at'], 'M d, Y h:i A'));
    $y += 63;

    $y = $section_title($pdf, $y, 'Order context', 'Client and supplier details');
    $pdf->rectangle(40, $y, 253, 94, [255, 255, 255], $border);
    $pdf->text(52, $y + 10, 'CLIENT ORDER', 7.5, true, $blue);
    $pdf->text(52, $y + 27, (string) $po['client_name'], 11, true, $ink);
    $pdf->text(52, $y + 47, 'Client PO: ' . $po['actual_client_po_number'], 8.5, false, $muted);
    $pdf->text(52, $y + 63, 'Client PO date: ' . drms_po_pdf_date($po['client_po_date']), 8.5, false, $muted);
    $pdf->text(52, $y + 79, 'Quotation: ' . ($po['source_quotation_number'] ?: $po['quotation_number']), 8.5, false, $muted);

    $pdf->rectangle(302, $y, 253, 94, [255, 255, 255], $border);
    $pdf->text(314, $y + 10, 'SUPPLIER', 7.5, true, $blue);
    $pdf->text(314, $y + 27, (string) $po['supplier_name'], 11, true, $ink);
    $pdf->text(314, $y + 47, 'Reference: ' . ($po['supplier_reference'] ?: 'Not recorded'), 8.5, false, $muted);
    $pdf->text(314, $y + 63, 'Quote date: ' . drms_po_pdf_date($po['supplier_quote_date']), 8.5, false, $muted);
    $pdf->text(314, $y + 79, 'Payment: ' . ($po['payment_method'] ?: 'Not recorded'), 8.5, false, $muted);
    $y += 112;

    $draw_items_header = static function (
        DrmsPrfPdfBuilder $document,
        float $top
    ) use ($navy): float {
        $document->rectangle(40, $top, 515, 25, $navy);
        $document->text(47, $top + 7, 'QTY', 7.5, true, [255, 255, 255]);
        $document->text(79, $top + 7, 'ITEM / SPECIFICATION', 7.5, true, [255, 255, 255]);
        $document->text(326, $top + 7, 'UNIT COST', 7.5, true, [255, 255, 255], 'right', 70);
        $document->text(404, $top + 7, 'TOTAL COST', 7.5, true, [255, 255, 255], 'right', 70);
        $document->text(482, $top + 7, 'CLIENT TOTAL', 7.5, true, [255, 255, 255], 'right', 65);
        return $top + 25;
    };

    $y = $section_title($pdf, $y, 'Order items', 'Approved supplier cost worksheet');
    $y = $draw_items_header($pdf, $y);
    foreach ($items as $index => $item) {
        $item_title = trim((string) ($item['brand'] . ' ' . $item['item_name']));
        $details = trim(implode(' - ', array_filter([
            (string) $item['category'],
            (string) $item['specifications'],
        ])));
        $title_lines = $pdf->wrap($item_title, 228, 8.5, true);
        $detail_lines = $pdf->wrap(
            $details !== '' ? $details : 'No additional specification',
            228,
            7.4,
            false
        );
        $row_height = max(
            34,
            9 + (min(2, count($title_lines)) * 9.5) +
                (min(2, count($detail_lines)) * 8.5)
        );

        if ($y + $row_height > 725) {
            $y = $new_page($pdf, true);
            $pdf->text(40, $y, 'ORDER ITEMS - CONTINUED', 8, true, $blue);
            $y += 16;
            $y = $draw_items_header($pdf, $y);
        }

        $fill = $index % 2 === 0 ? [255, 255, 255] : $soft;
        $pdf->rectangle(40, $y, 515, $row_height, $fill, $border);
        $pdf->text(47, $y + 10, (string) (int) $item['quantity'], 8.5, true, $ink);
        $pdf->wrappedText(79, $y + 7, $item_title, 228, 8.5, true, $ink, 9.5, 2);
        $pdf->wrappedText(
            79,
            $y + 26,
            $details !== '' ? $details : 'No additional specification',
            228,
            7.4,
            false,
            $muted,
            8.5,
            2
        );
        $pdf->text(326, $y + 11, drms_po_pdf_money($item['unit_cost']), 7.8, false, $ink, 'right', 70);
        $pdf->text(404, $y + 11, drms_po_pdf_money($item['total_cost']), 7.8, false, $ink, 'right', 70);
        $pdf->text(482, $y + 11, drms_po_pdf_money($item['total_price']), 7.8, true, $ink, 'right', 65);
        $y += $row_height;
    }
    $y += 18;

    if ($y + 185 > 750) {
        $y = $new_page($pdf, true);
    }
    $y = $section_title($pdf, $y, 'Financial authorization', 'Approved funding and projected return');
    $pdf->rectangle(40, $y, 245, 128, $soft, $border);
    $pdf->text(53, $y + 12, 'PROCUREMENT AND PAYMENT ROUTE', 7.5, true, $blue);
    $pdf->wrappedText(
        53,
        $y + 32,
        'Procure the approved client order from ' . $po['supplier_name'] .
            ' using the final PRF funding authorization.',
        219,
        9,
        false,
        $ink,
        12,
        4
    );
    $pdf->text(53, $y + 83, 'Method: ' . ($po['payment_method'] ?: 'Not recorded'), 8.2, false, $muted);
    $pdf->wrappedText(53, $y + 99, 'Terms: ' . ($po['payment_terms'] ?: 'Not recorded'), 219, 8.2, false, $muted, 10, 2);

    $pdf->rectangle(296, $y, 259, 128, [255, 255, 255], $border);
    $financial_rows = [
        ['Client selling amount', drms_po_pdf_money($po['amount']), false],
        ['Cost of goods', drms_po_pdf_money($po['cost_of_goods_amount']), false],
        ['Other expense', drms_po_pdf_money($po['other_expense_amount']), false],
        ['Approved funds', drms_po_pdf_money($po['requested_fund_amount']), true],
        ['Projected gross profit', drms_po_pdf_money($po['gross_profit_amount']), true],
        ['Projected margin', number_format((float) $po['gross_margin_percent'], 2) . '%', false],
    ];
    foreach ($financial_rows as $row_index => $row) {
        $row_top = $y + 10 + ($row_index * 18);
        $pdf->text(309, $row_top, $row[0], 8, false, $muted);
        $pdf->text(418, $row_top, $row[1], 8.5, (bool) $row[2], $row[2] ? $green : $ink, 'right', 124);
    }
    $y += 148;

    if ($y + 135 > 790) {
        $y = $new_page($pdf, true);
    }
    $y = $section_title(
        $pdf,
        $y,
        'Signatories',
        'Preparation and inherited authorization record'
    );

    $signatories = [
        [
            'Prepared by Procurement',
            (string) $creation['created_by_name'],
            'PO CREATED',
            drms_po_pdf_date($creation['created_at'], 'M d, Y h:i A'),
        ],
        [
            'Reviewed by General Manager',
            (string) $approvals['GM Review']['acted_by_name'],
            'APPROVED IN PRF',
            drms_po_pdf_date($approvals['GM Review']['acted_at'], 'M d, Y h:i A'),
        ],
        [
            'Checked by Finance',
            (string) $approvals['Finance Review']['acted_by_name'],
            'APPROVED IN PRF',
            drms_po_pdf_date($approvals['Finance Review']['acted_at'], 'M d, Y h:i A'),
        ],
        [
            'Approved by Owner / President',
            (string) $approvals['Owner Approval']['acted_by_name'],
            'FINAL PRF APPROVAL',
            drms_po_pdf_date($approvals['Owner Approval']['acted_at'], 'M d, Y h:i A'),
        ],
    ];
    foreach ($signatories as $index => $signatory) {
        $x = 40 + ($index * 130);
        $pdf->rectangle($x, $y, 125, 82, [255, 255, 255], $border);
        $pdf->wrappedText(
            $x + 9,
            $y + 8,
            strtoupper($signatory[0]),
            107,
            6.7,
            true,
            $blue,
            8,
            2
        );
        $pdf->wrappedText(
            $x + 9,
            $y + 30,
            $signatory[1],
            107,
            8.8,
            true,
            $ink,
            10,
            2
        );
        $pdf->line($x + 9, $y + 53, $x + 116, $y + 53, $border, 0.7);
        $pdf->text($x + 9, $y + 59, $signatory[2], 6.2, true, $green);
        $pdf->text($x + 9, $y + 70, $signatory[3], 6.3, false, $muted);
    }

    $page_count = $pdf->pageCount();
    for ($page_index = 0; $page_index < $page_count; $page_index++) {
        $pdf->selectPage($page_index);
        $pdf->line(40, 800, 555, 800, $border, 0.6);
        $pdf->text(
            40,
            809,
            'Generated and locked by Fixie DRMS from the final-approved PRF conversion.',
            7.2,
            false,
            $muted
        );
        $pdf->text(
            455,
            809,
            'Page ' . ($page_index + 1) . ' of ' . $page_count,
            7.2,
            true,
            $muted,
            'right',
            100
        );
    }

    return $pdf->build();
}

function drms_file_authorized_po_as_official_record(
    mysqli $conn,
    int $po_id,
    int $declared_by
): array {
    $snapshot = drms_load_authorized_po_snapshot_data($conn, $po_id);
    $po = $snapshot['po'];
    $tags = array_filter([
        'internal purchase order',
        'business po ' . trim((string) $po['po_number']),
        trim((string) $po['prf_record_number']) !== ''
            ? 'source ' . trim((string) $po['prf_record_number'])
            : null,
        trim((string) $po['client_po_record_number']) !== ''
            ? 'source ' . trim((string) $po['client_po_record_number'])
            : null,
    ]);

    return drms_file_generated_pdf_as_official_record(
        $conn,
        static function (
            string $record_number,
            string $_stored_file_name
        ) use ($snapshot): string {
            return drms_render_official_po_pdf($snapshot, $record_number);
        },
        (string) $po['po_number'] . '-official.pdf',
        'Official Internal Purchase Orders',
        'Internal Purchase Order',
        $declared_by,
        (string) $po['date_created'],
        'Internal Purchase Order',
        $po_id,
        (string) $po['po_number'],
        (int) $po['created_by'],
        $po_id,
        implode(',', $tags)
    );
}
