<?php

if (!function_exists('drms_load_approved_delivery_request_snapshot')) {
    require_once __DIR__ . '/official_delivery_request_snapshot.php';
}

if (!function_exists('drms_logistics_plan_pdf_date')) {
    function drms_logistics_plan_pdf_date(
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

if (!function_exists('drms_load_approved_logistics_plan_snapshot')) {
    function drms_load_approved_logistics_plan_snapshot(
        mysqli $conn,
        int $delivery_plan_id,
        int $declared_by
    ): array {
        if ($delivery_plan_id < 1 || $declared_by < 1) {
            throw new InvalidArgumentException(
                'The approved logistics-plan reference is invalid.'
            );
        }

        $plan_stmt = $conn->prepare(
            "SELECT
                plan.delivery_plan_id,
                plan.delivery_request_id,
                plan.logistics_status,
                plan.reviewed_by,
                plan.reviewed_at,
                plan.record_status
             FROM po_delivery_plans plan
             WHERE plan.delivery_plan_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $plan_stmt->bind_param('i', $delivery_plan_id);
        $plan_stmt->execute();
        $plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();

        if (
            !$plan ||
            !in_array(
                $plan['logistics_status'],
                ['Scheduled', 'Dispatched', 'Completed'],
                true
            ) ||
            ($plan['record_status'] ?? '') !== 'Active' ||
            (int) ($plan['delivery_request_id'] ?? 0) < 1 ||
            (int) ($plan['reviewed_by'] ?? 0) !== $declared_by ||
            empty($plan['reviewed_at'])
        ) {
            throw new RuntimeException(
                'The logistics plan has not completed Supply Chain approval.'
            );
        }

        $snapshot = drms_load_approved_delivery_request_snapshot(
            $conn,
            (int) $plan['delivery_request_id'],
            $declared_by
        );
        $request = $snapshot['request'];
        if ((int) $request['delivery_plan_id'] !== $delivery_plan_id) {
            throw new RuntimeException(
                'The approved logistics plan does not match its delivery request.'
            );
        }

        $request_record_stmt = $conn->prepare(
            "SELECT
                doc_id,
                record_number,
                file_path,
                file_hash,
                record_phase,
                status,
                is_locked
             FROM documents
             WHERE source_module = 'Delivery Request'
               AND source_record_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $delivery_request_id = (int) $request['delivery_request_id'];
        $request_record_stmt->bind_param('i', $delivery_request_id);
        $request_record_stmt->execute();
        $request_record = $request_record_stmt
            ->get_result()
            ->fetch_assoc();
        $request_record_stmt->close();

        if (!$request_record) {
            throw new RuntimeException(
                'The approved Delivery Request Official Record is unavailable.'
            );
        }
        drms_verify_official_source_file(
            $request_record,
            'The source approved Delivery Request'
        );
        if (!preg_match(
            '/^DRF-\d{4}-\d{4}$/',
            (string) $request_record['record_number']
        )) {
            throw new RuntimeException(
                'The source Delivery Request does not use its controlled folder code.'
            );
        }

        $snapshot['request_record'] = $request_record;
        return $snapshot;
    }
}

if (!function_exists('drms_render_official_logistics_plan_pdf')) {
    function drms_render_official_logistics_plan_pdf(
        array $snapshot,
        string $record_number
    ): string {
        $request = $snapshot['request'];
        $request_record = $snapshot['request_record'];
        $items = $snapshot['items'];
        $total_units = (int) $snapshot['total_units'];

        $pdf = new DrmsPrfPdfBuilder(
            $record_number . ' - Approved Logistics Plan',
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
            $document->text(54, 58, 'LOGISTICS PLAN AND ROUTE AUTHORIZATION', 14.2, true, $navy);
            $document->text(
                54,
                80,
                $continued
                    ? 'Approved movement plan - continued'
                    : 'Approved provider, schedule, and route record',
                8.5,
                false,
                $muted
            );
            $document->rectangle(407, 42, 136, 25, [232, 248, 242], [167, 229, 203]);
            $document->text(407, 48, 'OFFICIAL - APPROVED', 8.2, true, $green, 'center', 136);
            $document->text(407, 76, 'RMS: ' . $record_number, 9.2, true, $ink, 'center', 136);
            $document->text(
                407,
                90,
                'Source DRF: ' . $request['request_number'],
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
                8.4,
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

        $display_value = static function ($value, string $fallback = 'Not assigned'): string {
            $text = trim((string) $value);
            return $text !== '' ? $text : $fallback;
        };

        $y = $new_page($pdf, false);
        $value_cell(
            $pdf,
            40,
            $y,
            124.25,
            'Official DRF',
            (string) $request_record['record_number']
        );
        $value_cell($pdf, 170, $y, 124.25, 'Business PO', (string) $request['po_number']);
        $value_cell($pdf, 300, $y, 124.25, 'Provider type', (string) $request['provider_type']);
        $value_cell($pdf, 430, $y, 125, 'Plan status', (string) $request['logistics_status']);
        $y += 63;

        $y = $section_title($pdf, $y, 'Movement ownership', 'Client, supplier, and responsible provider');
        $value_cell($pdf, 40, $y, 167, 'Client', (string) $request['client_name']);
        $value_cell($pdf, 213, $y, 167, 'Supplier', (string) $request['supplier_name_snapshot']);
        $value_cell($pdf, 386, $y, 169, 'Provider', (string) $request['provider_name']);
        $y += 61;
        $value_cell(
            $pdf,
            40,
            $y,
            167,
            'Request route',
            (string) $request['request_type']
        );
        $value_cell(
            $pdf,
            213,
            $y,
            167,
            'Packages / item units',
            (int) $request['package_count'] . ' packages / ' . $total_units . ' units'
        );
        $value_cell(
            $pdf,
            386,
            $y,
            169,
            'Approved by',
            (string) $request['reviewed_by_name']
        );
        $y += 67;

        $y = $section_title($pdf, $y, 'Final schedule', 'Approved movement timing');
        $value_cell(
            $pdf,
            40,
            $y,
            124.25,
            'Final pick-up',
            drms_logistics_plan_pdf_date($request['planned_pickup_at'])
        );
        $value_cell(
            $pdf,
            170,
            $y,
            124.25,
            'Final delivery',
            drms_logistics_plan_pdf_date($request['planned_delivery_at'])
        );
        $value_cell(
            $pdf,
            300,
            $y,
            124.25,
            'Supplier ready',
            drms_logistics_plan_pdf_date($request['supplier_ready_confirmed_at'])
        );
        $value_cell(
            $pdf,
            430,
            $y,
            125,
            'Plan approved',
            drms_logistics_plan_pdf_date($request['reviewed_at'])
        );
        $y += 67;

        $y = $section_title($pdf, $y, 'Transport resources', 'Assigned movement details');
        $value_cell(
            $pdf,
            40,
            $y,
            124.25,
            'Driver / rider',
            $display_value($request['driver_name'])
        );
        $value_cell(
            $pdf,
            170,
            $y,
            124.25,
            'Driver contact',
            $display_value($request['driver_contact_number'])
        );
        $value_cell(
            $pdf,
            300,
            $y,
            124.25,
            'Vehicle / service',
            $display_value($request['vehicle_type'])
        );
        $value_cell(
            $pdf,
            430,
            $y,
            125,
            'Plate / unit',
            $display_value($request['vehicle_plate_number'])
        );
        $y += 55;
        $value_cell(
            $pdf,
            40,
            $y,
            253,
            'Tracking / booking reference',
            $display_value($request['tracking_reference'], 'Not provided')
        );
        $value_cell(
            $pdf,
            302,
            $y,
            253,
            'Funding evidence',
            (string) $request['funding_record_number'] . ' / ' .
                (string) $request['funding_reference']
        );
        $y += 67;

        $y = $section_title($pdf, $y, 'Route authorization', 'Approved movement points');
        $text_box(
            $pdf,
            40,
            $y,
            253,
            62,
            'Pick-up point',
            (string) ($request['pickup_address'] ?: 'Not required')
        );
        $text_box(
            $pdf,
            302,
            $y,
            253,
            62,
            'Delivery point',
            (string) ($request['delivery_address'] ?: 'Not required')
        );
        $y += 79;

        $draw_items_header = static function (
            DrmsPrfPdfBuilder $document,
            float $top
        ) use ($navy): float {
            $document->rectangle(40, $top, 515, 25, $navy);
            $document->text(48, $top + 7, 'QTY', 7.5, true, [255, 255, 255]);
            $document->text(87, $top + 7, 'CARGO ITEM', 7.5, true, [255, 255, 255]);
            $document->text(330, $top + 7, 'CATEGORY / SPECIFICATION', 7.5, true, [255, 255, 255]);
            return $top + 25;
        };

        if ($y + 105 > 724) {
            $y = $new_page($pdf, true);
        }
        $y = $section_title($pdf, $y, 'Movement manifest', 'Cargo item checklist');
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
                $pdf->text(40, $y, 'CARGO ITEM CHECKLIST - CONTINUED', 8, true, $blue);
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

        if ($y + 220 > 755) {
            $y = $new_page($pdf, true);
        }
        $y = $section_title($pdf, $y, 'Execution notes', 'Route and handling instructions');
        $text_box(
            $pdf,
            40,
            $y,
            253,
            72,
            'Route / plot notes',
            $display_value($request['route_or_plot_notes'], 'Use the approved route and schedule')
        );
        $text_box(
            $pdf,
            302,
            $y,
            253,
            72,
            'Handling instructions',
            $display_value($request['handling_instructions'], 'Standard handling')
        );
        $y += 82;
        $text_box(
            $pdf,
            40,
            $y,
            253,
            62,
            'Procurement remarks',
            $display_value($request['procurement_remarks'], 'No additional remarks')
        );
        $text_box(
            $pdf,
            302,
            $y,
            253,
            62,
            'Authorization note',
            'Supply Chain approved this provider, route, and final movement schedule.'
        );
        $y += 82;

        if ($y + 130 > 775) {
            $y = $new_page($pdf, true);
        }
        $y = $section_title($pdf, $y, 'Authorization', 'Authenticated workflow ownership');
        $signatories = [
            [
                'Source request prepared by Procurement',
                (string) $request['prepared_by_name'],
                'REQUEST PREPARED',
                drms_logistics_plan_pdf_date($request['submitted_at']),
            ],
            [
                'Plan reviewed and approved by Supply Chain',
                (string) $request['reviewed_by_name'],
                'PLAN APPROVED',
                drms_logistics_plan_pdf_date($request['reviewed_at']),
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
                'Generated and locked by Fixie DRMS after Supply Chain plan approval.',
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

if (!function_exists('drms_file_approved_logistics_plan_as_official_record')) {
    function drms_file_approved_logistics_plan_as_official_record(
        mysqli $conn,
        int $delivery_plan_id,
        int $declared_by
    ): array {
        $folder = drms_get_official_folder_profile(
            $conn,
            'Approved Logistics Plans'
        );
        if (
            (int) ($folder['is_system_folder'] ?? 0) !== 1 ||
            ($folder['system_folder_key'] ?? '') !== 'logistics_plan' ||
            ($folder['record_prefix'] ?? '') !== 'LGP'
        ) {
            throw new RuntimeException(
                'The protected Approved Logistics Plans folder is not configured correctly.'
            );
        }

        $snapshot = drms_load_approved_logistics_plan_snapshot(
            $conn,
            $delivery_plan_id,
            $declared_by
        );
        $request = $snapshot['request'];
        $request_record = $snapshot['request_record'];
        $tags = array_filter([
            'approved logistics plan',
            'business drf ' . trim((string) $request['request_number']),
            'business po ' . trim((string) $request['po_number']),
            'provider ' . trim((string) $request['provider_name']),
            'source ' . trim((string) $request_record['record_number']),
            trim((string) $request['tracking_reference']) !== ''
                ? 'tracking ' . trim((string) $request['tracking_reference'])
                : null,
        ]);

        return drms_file_generated_pdf_as_official_record(
            $conn,
            static function (
                string $record_number,
                string $_stored_file_name
            ) use ($snapshot): string {
                return drms_render_official_logistics_plan_pdf(
                    $snapshot,
                    $record_number
                );
            },
            (string) $request['request_number'] . '-logistics-plan.pdf',
            'Approved Logistics Plans',
            'Approved Logistics Plan',
            $declared_by,
            (string) $request['reviewed_at'],
            'Logistics Plan',
            $delivery_plan_id,
            (string) $request['request_number'],
            $declared_by,
            (int) $request['po_id'],
            implode(',', $tags)
        );
    }
}
