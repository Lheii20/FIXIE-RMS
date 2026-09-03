<?php

if (!function_exists('drms_verify_official_source_file')) {
    require_once __DIR__ . '/official_po_snapshot.php';
}

if (!function_exists('drms_delivery_request_pdf_date')) {
    function drms_delivery_request_pdf_date(
        ?string $value,
        string $format = 'M d, Y h:i A'
    ): string {
        if (!$value) {
            return 'Not required';
        }
        $timestamp = strtotime($value);
        return $timestamp ? date($format, $timestamp) : 'Not recorded';
    }
}

if (!function_exists('drms_load_approved_delivery_request_snapshot')) {
    function drms_load_approved_delivery_request_snapshot(
        mysqli $conn,
        int $delivery_request_id,
        int $declared_by
    ): array {
        if ($delivery_request_id < 1 || $declared_by < 1) {
            throw new InvalidArgumentException(
                'The approved delivery-request reference is invalid.'
            );
        }

        $request_stmt = $conn->prepare(
            "SELECT
                request.delivery_request_id,
                request.request_number,
                request.po_id,
                request.request_cycle,
                request.fund_release_id,
                request.supplier_detail_id,
                request.request_type,
                request.supplier_name_snapshot,
                request.supplier_ready_confirmed_at,
                request.supplier_confirmation_reference,
                request.supplier_contact_name,
                request.supplier_contact_number,
                request.supplier_contact_email,
                request.pickup_address,
                request.delivery_address,
                request.preferred_pickup_at,
                request.preferred_delivery_at,
                request.package_count,
                request.handling_instructions,
                request.procurement_remarks,
                request.request_status,
                request.record_status AS request_record_status,
                request.prepared_by,
                request.submitted_at,
                plan.delivery_plan_id,
                plan.logistics_status,
                plan.provider_type,
                plan.provider_name,
                plan.planned_pickup_at,
                plan.planned_delivery_at,
                plan.driver_name,
                plan.driver_contact_number,
                plan.vehicle_type,
                plan.vehicle_plate_number,
                plan.tracking_reference,
                plan.route_or_plot_notes,
                plan.reviewed_by,
                plan.reviewed_at,
                plan.record_status AS plan_record_status,
                po.po_number,
                po.client_name,
                po.status AS po_status,
                po.supplier_detail_id AS po_supplier_detail_id,
                supplier.supplier_name AS current_supplier_name,
                funding.reference_number AS funding_reference,
                funding.released_amount,
                funding.release_method,
                funding.released_at,
                funding.record_status AS funding_record_status,
                preparer.full_name AS prepared_by_name,
                reviewer.full_name AS reviewed_by_name,
                po_record.doc_id AS po_record_doc_id,
                po_record.record_number AS po_record_number,
                po_record.file_path AS po_record_file_path,
                po_record.file_hash AS po_record_file_hash,
                po_record.record_phase AS po_record_phase,
                po_record.status AS po_record_status,
                po_record.is_locked AS po_record_is_locked,
                funding_record.doc_id AS funding_record_doc_id,
                funding_record.record_number AS funding_record_number,
                funding_record.file_path AS funding_record_file_path,
                funding_record.file_hash AS funding_record_file_hash,
                funding_record.record_phase AS funding_record_phase,
                funding_record.status AS funding_record_status_document,
                funding_record.is_locked AS funding_record_is_locked
             FROM po_delivery_requests request
             INNER JOIN po_delivery_plans plan
               ON plan.delivery_request_id = request.delivery_request_id
             INNER JOIN purchase_orders po
               ON po.po_id = request.po_id
             INNER JOIN po_supplier_fund_releases funding
               ON funding.fund_release_id = request.fund_release_id
              AND funding.po_id = po.po_id
             INNER JOIN pr_supplier_details supplier
               ON supplier.supplier_detail_id = request.supplier_detail_id
              AND supplier.supplier_detail_id = po.supplier_detail_id
              AND supplier.record_status = 'Active'
             LEFT JOIN users preparer
               ON preparer.user_id = request.prepared_by
             LEFT JOIN users reviewer
               ON reviewer.user_id = plan.reviewed_by
             LEFT JOIN documents po_record
               ON po_record.source_module = 'Internal Purchase Order'
              AND po_record.source_record_id = po.po_id
             LEFT JOIN documents funding_record
               ON funding_record.source_module = 'Supplier Fund Release'
              AND funding_record.source_record_id = funding.fund_release_id
             WHERE request.delivery_request_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $request_stmt->bind_param('i', $delivery_request_id);
        $request_stmt->execute();
        $request = $request_stmt->get_result()->fetch_assoc();
        $request_stmt->close();

        if (!$request) {
            throw new RuntimeException(
                'The approved delivery request or its source records are unavailable.'
            );
        }

        $allowed_po_statuses = [
            'For Pick-up/Delivery',
            'Delivered',
            'Partially-Collected',
            'Collected',
        ];
        $allowed_request_statuses = ['Scheduled', 'Completed'];
        $allowed_plan_statuses = ['Scheduled', 'Dispatched', 'Completed'];
        $allowed_request_types = [
            'Pick-up and Delivery',
            'Delivery Only',
            'Client Pick-up',
        ];
        $allowed_provider_types = [
            'Company Fleet',
            'Third-Party Logistics',
            'Supplier Delivery',
            'Client Pick-up',
        ];

        if (
            !in_array($request['po_status'], $allowed_po_statuses, true) ||
            !in_array(
                $request['request_status'],
                $allowed_request_statuses,
                true
            ) ||
            !in_array(
                $request['logistics_status'],
                $allowed_plan_statuses,
                true
            ) ||
            !in_array(
                $request['request_type'],
                $allowed_request_types,
                true
            ) ||
            !in_array(
                $request['provider_type'],
                $allowed_provider_types,
                true
            ) ||
            ($request['request_record_status'] ?? '') !== 'Active' ||
            ($request['plan_record_status'] ?? '') !== 'Active' ||
            ($request['funding_record_status'] ?? '') !== 'Active'
        ) {
            throw new RuntimeException(
                'The delivery request has not completed Supply Chain approval.'
            );
        }

        if (
            (int) $request['request_cycle'] < 1 ||
            (int) $request['fund_release_id'] < 1 ||
            (int) $request['delivery_plan_id'] < 1 ||
            (int) $request['supplier_detail_id'] !==
                (int) $request['po_supplier_detail_id'] ||
            (int) $request['prepared_by'] < 1 ||
            (int) $request['reviewed_by'] !== $declared_by ||
            empty($request['submitted_at']) ||
            empty($request['reviewed_at']) ||
            empty($request['prepared_by_name']) ||
            empty($request['reviewed_by_name']) ||
            trim((string) $request['request_number']) === '' ||
            trim((string) $request['po_number']) === '' ||
            trim((string) $request['client_name']) === '' ||
            trim((string) $request['supplier_name_snapshot']) === '' ||
            trim((string) $request['provider_name']) === '' ||
            (int) $request['package_count'] < 1
        ) {
            throw new RuntimeException(
                'The approved delivery request contains incomplete authorization details.'
            );
        }

        if (
            strtotime((string) $request['supplier_ready_confirmed_at']) === false ||
            strtotime((string) $request['submitted_at']) === false ||
            strtotime((string) $request['reviewed_at']) === false ||
            strtotime((string) $request['supplier_ready_confirmed_at']) >
                strtotime((string) $request['submitted_at']) ||
            strtotime((string) $request['submitted_at']) >
                strtotime((string) $request['reviewed_at'])
        ) {
            throw new RuntimeException(
                'The delivery-request approval chronology is invalid.'
            );
        }

        $request_type = (string) $request['request_type'];
        $has_pickup = $request_type !== 'Delivery Only';
        $has_delivery = $request_type !== 'Client Pick-up';
        if (
            ($has_pickup && (
                trim((string) $request['pickup_address']) === '' ||
                empty($request['preferred_pickup_at']) ||
                empty($request['planned_pickup_at'])
            )) ||
            ($has_delivery && (
                trim((string) $request['delivery_address']) === '' ||
                empty($request['preferred_delivery_at']) ||
                empty($request['planned_delivery_at'])
            )) ||
            (!$has_pickup && (
                !empty($request['pickup_address']) ||
                !empty($request['preferred_pickup_at']) ||
                !empty($request['planned_pickup_at'])
            )) ||
            (!$has_delivery && (
                !empty($request['delivery_address']) ||
                !empty($request['preferred_delivery_at']) ||
                !empty($request['planned_delivery_at'])
            ))
        ) {
            throw new RuntimeException(
                'The approved route does not match the delivery-request type.'
            );
        }
        if (
            ($request_type === 'Client Pick-up' &&
                $request['provider_type'] !== 'Client Pick-up') ||
            ($request_type !== 'Client Pick-up' &&
                $request['provider_type'] === 'Client Pick-up') ||
            (
                $has_pickup &&
                $has_delivery &&
                strtotime((string) $request['planned_delivery_at']) <
                    strtotime((string) $request['planned_pickup_at'])
            )
        ) {
            throw new RuntimeException(
                'The approved provider or schedule is inconsistent with the request route.'
            );
        }

        $source_po_record = [
            'doc_id' => $request['po_record_doc_id'],
            'record_number' => $request['po_record_number'],
            'file_path' => $request['po_record_file_path'],
            'file_hash' => $request['po_record_file_hash'],
            'record_phase' => $request['po_record_phase'],
            'status' => $request['po_record_status'],
            'is_locked' => $request['po_record_is_locked'],
        ];
        $source_funding_record = [
            'doc_id' => $request['funding_record_doc_id'],
            'record_number' => $request['funding_record_number'],
            'file_path' => $request['funding_record_file_path'],
            'file_hash' => $request['funding_record_file_hash'],
            'record_phase' => $request['funding_record_phase'],
            'status' => $request['funding_record_status_document'],
            'is_locked' => $request['funding_record_is_locked'],
        ];
        drms_verify_official_source_file(
            $source_po_record,
            'The source Internal PO'
        );
        drms_verify_official_source_file(
            $source_funding_record,
            'The source supplier fund-release proof'
        );
        if (
            !preg_match(
                '/^PO-\d{4}-\d{4}$/',
                (string) $request['po_record_number']
            ) ||
            !preg_match(
                '/^FRP-\d{4}-\d{4}$/',
                (string) $request['funding_record_number']
            )
        ) {
            throw new RuntimeException(
                'The source records do not use their controlled folder codes.'
            );
        }

        $items_stmt = $conn->prepare(
            "SELECT
                item_id,
                category,
                brand,
                item_name,
                specifications,
                quantity
             FROM po_items
             WHERE po_id = ?
             ORDER BY item_id
             FOR UPDATE"
        );
        $po_id = (int) $request['po_id'];
        $items_stmt->bind_param('i', $po_id);
        $items_stmt->execute();
        $item_rows = $items_stmt->get_result();
        $items = [];
        $total_units = 0;
        while ($item = $item_rows->fetch_assoc()) {
            if (
                (int) $item['quantity'] < 1 ||
                trim((string) $item['item_name']) === ''
            ) {
                throw new RuntimeException(
                    'A delivery-request item line is incomplete.'
                );
            }
            $total_units += (int) $item['quantity'];
            $items[] = $item;
        }
        $items_stmt->close();

        if (empty($items)) {
            throw new RuntimeException(
                'The delivery request does not contain any PO item lines.'
            );
        }

        return [
            'request' => $request,
            'items' => $items,
            'total_units' => $total_units,
        ];
    }
}

if (!function_exists('drms_render_official_delivery_request_pdf')) {
    function drms_render_official_delivery_request_pdf(
        array $snapshot,
        string $record_number
    ): string {
        $request = $snapshot['request'];
        $items = $snapshot['items'];
        $total_units = (int) $snapshot['total_units'];

        $pdf = new DrmsPrfPdfBuilder(
            $record_number . ' - Delivery and Pick-up Request',
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
        ) use ($record_number, $request, $navy, $blue, $green, $ink, $muted): float {
            $document->addPage();
            $document->rectangle(34, 28, 527, 74, [248, 250, 253], [218, 226, 237]);
            $document->rectangle(34, 28, 7, 74, $blue);
            $document->text(54, 42, 'FIXIE COMPUTER VENTURES', 9.5, true, $blue);
            $document->text(54, 58, 'DELIVERY / PICK-UP REQUEST FORM', 15, true, $navy);
            $document->text(
                54,
                80,
                $continued
                    ? 'Approved operational record - continued'
                    : 'Approved supplier-to-client coordination record',
                8.5,
                false,
                $muted
            );
            $document->rectangle(407, 42, 136, 25, [232, 248, 242], [167, 229, 203]);
            $document->text(407, 48, 'OFFICIAL - SCHEDULED', 8.2, true, $green, 'center', 136);
            $document->text(407, 76, 'RMS: ' . $record_number, 9.2, true, $ink, 'center', 136);
            $document->text(
                407,
                90,
                'Business DRF: ' . $request['request_number'],
                7.2,
                false,
                $muted,
                'center',
                136
            );
            return 119.0;
        };

        $section_title = static function (
            DrmsPrfPdfBuilder $document,
            float $top,
            string $eyebrow,
            string $title
        ) use ($blue, $ink, $border): float {
            $document->text(40, $top, strtoupper($eyebrow), 7.5, true, $blue);
            $document->text(40, $top + 14, $title, 11.5, true, $ink);
            $document->line(40, $top + 32, 555, $top + 32, $border, 0.7);
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
                8.5,
                true,
                $ink,
                10,
                2
            );
        };

        $text_box = static function (
            DrmsPrfPdfBuilder $document,
            float $x,
            float $top,
            float $width,
            float $height,
            string $label,
            string $value
        ) use ($border, $muted, $ink): void {
            $document->rectangle($x, $top, $width, $height, [255, 255, 255], $border);
            $document->text($x + 10, $top + 9, strtoupper($label), 7, true, $muted);
            $document->wrappedText(
                $x + 10,
                $top + 25,
                $value !== '' ? $value : 'Not recorded',
                $width - 20,
                8.2,
                false,
                $ink,
                10,
                max(2, (int) floor(($height - 31) / 10))
            );
        };

        $y = $new_page($pdf, false);
        $value_cell($pdf, 40, $y, 124.25, 'Business PO', (string) $request['po_number']);
        $value_cell($pdf, 170, $y, 124.25, 'Official PO', (string) $request['po_record_number']);
        $value_cell($pdf, 300, $y, 124.25, 'Fund proof', (string) $request['funding_record_number']);
        $value_cell(
            $pdf,
            430,
            $y,
            125,
            'Request cycle',
            'Cycle ' . (int) $request['request_cycle']
        );
        $y += 63;

        $y = $section_title($pdf, $y, 'Request context', 'Client, supplier, and route');
        $value_cell($pdf, 40, $y, 167, 'Client', (string) $request['client_name']);
        $value_cell($pdf, 213, $y, 167, 'Supplier', (string) $request['supplier_name_snapshot']);
        $value_cell($pdf, 386, $y, 169, 'Request type', (string) $request['request_type']);
        $y += 61;
        $value_cell(
            $pdf,
            40,
            $y,
            167,
            'Packages / item units',
            (int) $request['package_count'] . ' packages / ' . $total_units . ' units'
        );
        $value_cell(
            $pdf,
            213,
            $y,
            167,
            'Supplier ready',
            drms_delivery_request_pdf_date($request['supplier_ready_confirmed_at'])
        );
        $value_cell(
            $pdf,
            386,
            $y,
            169,
            'Confirmation reference',
            (string) ($request['supplier_confirmation_reference'] ?: 'Not recorded')
        );
        $y += 67;

        $y = $section_title($pdf, $y, 'Approved schedule', 'Requested and final timing');
        $value_cell(
            $pdf,
            40,
            $y,
            124.25,
            'Preferred pick-up',
            drms_delivery_request_pdf_date($request['preferred_pickup_at'])
        );
        $value_cell(
            $pdf,
            170,
            $y,
            124.25,
            'Final pick-up',
            drms_delivery_request_pdf_date($request['planned_pickup_at'])
        );
        $value_cell($pdf, 300, $y, 124.25, 'Provider type', (string) $request['provider_type']);
        $value_cell($pdf, 430, $y, 125, 'Provider', (string) $request['provider_name']);
        $y += 55;
        $value_cell(
            $pdf,
            40,
            $y,
            124.25,
            'Preferred delivery',
            drms_delivery_request_pdf_date($request['preferred_delivery_at'])
        );
        $value_cell(
            $pdf,
            170,
            $y,
            124.25,
            'Final delivery',
            drms_delivery_request_pdf_date($request['planned_delivery_at'])
        );
        $value_cell(
            $pdf,
            300,
            $y,
            124.25,
            'Prepared',
            drms_delivery_request_pdf_date($request['submitted_at'])
        );
        $value_cell(
            $pdf,
            430,
            $y,
            125,
            'Approved',
            drms_delivery_request_pdf_date($request['reviewed_at'])
        );
        $y += 67;

        $y = $section_title($pdf, $y, 'Route points', 'Pick-up and delivery addresses');
        $text_box(
            $pdf,
            40,
            $y,
            253,
            62,
            'Pick-up address',
            (string) ($request['pickup_address'] ?: 'Not required')
        );
        $text_box(
            $pdf,
            302,
            $y,
            253,
            62,
            'Delivery address',
            (string) ($request['delivery_address'] ?: 'Not required')
        );
        $y += 79;

        $draw_items_header = static function (
            DrmsPrfPdfBuilder $document,
            float $top
        ) use ($navy): float {
            $document->rectangle(40, $top, 515, 25, $navy);
            $document->text(48, $top + 7, 'QTY', 7.5, true, [255, 255, 255]);
            $document->text(87, $top + 7, 'ITEM', 7.5, true, [255, 255, 255]);
            $document->text(330, $top + 7, 'CATEGORY / SPECIFICATION', 7.5, true, [255, 255, 255]);
            return $top + 25;
        };

        if ($y + 105 > 724) {
            $y = $new_page($pdf, true);
        }
        $y = $section_title($pdf, $y, 'Items for movement', 'PO item checklist');
        $y = $draw_items_header($pdf, $y);
        foreach ($items as $index => $item) {
            $item_name = trim((string) ($item['brand'] . ' ' . $item['item_name']));
            $details = trim(implode(' - ', array_filter([
                (string) $item['category'],
                (string) $item['specifications'],
            ])));
            $item_lines = $pdf->wrap($item_name, 225, 8.4, true);
            $detail_lines = $pdf->wrap(
                $details !== '' ? $details : 'No additional specification',
                215,
                7.6,
                false
            );
            $row_height = max(
                33,
                10 + max(
                    min(3, count($item_lines)) * 9.5,
                    min(3, count($detail_lines)) * 8.5
                )
            );

            if ($y + $row_height > 724) {
                $y = $new_page($pdf, true);
                $pdf->text(40, $y, 'PO ITEM CHECKLIST - CONTINUED', 8, true, $blue);
                $y += 16;
                $y = $draw_items_header($pdf, $y);
            }

            $fill = $index % 2 === 0 ? [255, 255, 255] : $soft;
            $pdf->rectangle(40, $y, 515, $row_height, $fill, $border);
            $pdf->text(48, $y + 10, (string) (int) $item['quantity'], 8.5, true, $ink);
            $pdf->wrappedText(87, $y + 8, $item_name, 225, 8.4, true, $ink, 9.5, 3);
            $pdf->wrappedText(
                330,
                $y + 8,
                $details !== '' ? $details : 'No additional specification',
                215,
                7.6,
                false,
                $muted,
                8.5,
                3
            );
            $y += $row_height;
        }
        $y += 18;

        if ($y + 205 > 755) {
            $y = $new_page($pdf, true);
        }
        $y = $section_title($pdf, $y, 'Coordination notes', 'Contact and handling instructions');
        $contact_details = array_filter([
            trim((string) $request['supplier_contact_name']),
            trim((string) $request['supplier_contact_number']),
            trim((string) $request['supplier_contact_email']),
        ]);
        $text_box(
            $pdf,
            40,
            $y,
            253,
            72,
            'Supplier contact',
            $contact_details ? implode(' / ', $contact_details) : 'Not recorded'
        );
        $text_box(
            $pdf,
            302,
            $y,
            253,
            72,
            'Handling instructions',
            (string) ($request['handling_instructions'] ?: 'Standard handling')
        );
        $y += 82;
        $text_box(
            $pdf,
            40,
            $y,
            253,
            62,
            'Procurement remarks',
            (string) ($request['procurement_remarks'] ?: 'No additional remarks')
        );
        $text_box(
            $pdf,
            302,
            $y,
            253,
            62,
            'Approval note',
            'Supply Chain accepted the request and confirmed the final provider and schedule.'
        );
        $y += 82;

        if ($y + 130 > 775) {
            $y = $new_page($pdf, true);
        }
        $y = $section_title($pdf, $y, 'Signatories', 'Authenticated workflow approvals');
        $signatories = [
            [
                'Prepared and submitted by Procurement',
                (string) $request['prepared_by_name'],
                'PREPARED / SUBMITTED',
                drms_delivery_request_pdf_date($request['submitted_at']),
            ],
            [
                'Reviewed and approved by Supply Chain',
                (string) $request['reviewed_by_name'],
                'APPROVED / SCHEDULED',
                drms_delivery_request_pdf_date($request['reviewed_at']),
            ],
        ];
        foreach ($signatories as $index => $signatory) {
            $x = 40 + ($index * 260);
            $pdf->rectangle($x, $y, 255, 88, [255, 255, 255], $border);
            $pdf->wrappedText(
                $x + 12,
                $y + 10,
                strtoupper($signatory[0]),
                231,
                7,
                true,
                $blue,
                8.5,
                2
            );
            $pdf->wrappedText(
                $x + 12,
                $y + 35,
                $signatory[1],
                231,
                10,
                true,
                $ink,
                11,
                2
            );
            $pdf->line($x + 12, $y + 60, $x + 243, $y + 60, $border, 0.7);
            $pdf->text($x + 12, $y + 67, $signatory[2], 6.6, true, $green);
            $pdf->text($x + 130, $y + 67, $signatory[3], 6.6, false, $muted, 'right', 113);
        }

        $page_count = $pdf->pageCount();
        for ($page_index = 0; $page_index < $page_count; $page_index++) {
            $pdf->selectPage($page_index);
            $pdf->line(40, 800, 555, 800, $border, 0.6);
            $pdf->text(
                40,
                809,
                'Generated and locked by Fixie DRMS after Supply Chain approval.',
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
}

if (!function_exists('drms_file_approved_delivery_request_as_official_record')) {
    function drms_file_approved_delivery_request_as_official_record(
        mysqli $conn,
        int $delivery_request_id,
        int $declared_by
    ): array {
        $folder = drms_get_official_folder_profile(
            $conn,
            'Approved Delivery and Pick-up Requests'
        );
        if (
            (int) ($folder['is_system_folder'] ?? 0) !== 1 ||
            ($folder['system_folder_key'] ?? '') !== 'delivery_request' ||
            ($folder['record_prefix'] ?? '') !== 'DRF'
        ) {
            throw new RuntimeException(
                'The protected Delivery and Pick-up Requests folder is not configured correctly.'
            );
        }

        $snapshot = drms_load_approved_delivery_request_snapshot(
            $conn,
            $delivery_request_id,
            $declared_by
        );
        $request = $snapshot['request'];
        $tags = array_filter([
            'delivery request',
            'business drf ' . trim((string) $request['request_number']),
            'business po ' . trim((string) $request['po_number']),
            'supplier ' . trim((string) $request['supplier_name_snapshot']),
            'source ' . trim((string) $request['po_record_number']),
            'source ' . trim((string) $request['funding_record_number']),
        ]);

        return drms_file_generated_pdf_as_official_record(
            $conn,
            static function (
                string $record_number,
                string $_stored_file_name
            ) use ($snapshot): string {
                return drms_render_official_delivery_request_pdf(
                    $snapshot,
                    $record_number
                );
            },
            (string) $request['request_number'] . '-official.pdf',
            'Approved Delivery and Pick-up Requests',
            'Approved Delivery Request',
            $declared_by,
            (string) $request['reviewed_at'],
            'Delivery Request',
            $delivery_request_id,
            (string) $request['request_number'],
            (int) $request['prepared_by'],
            (int) $request['po_id'],
            implode(',', $tags)
        );
    }
}
