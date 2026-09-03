<?php

final class DrmsPrfPdfBuilder
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    private array $pages = [];
    private int $currentPage = -1;
    private string $title;
    private string $author;

    public function __construct(string $title, string $author)
    {
        $this->title = $this->plainText($title);
        $this->author = $this->plainText($author);
    }

    public function addPage(): int
    {
        $this->pages[] = [];
        $this->currentPage = count($this->pages) - 1;
        return $this->currentPage;
    }

    public function selectPage(int $pageIndex): void
    {
        if (!isset($this->pages[$pageIndex])) {
            throw new OutOfBoundsException('The requested PDF page does not exist.');
        }
        $this->currentPage = $pageIndex;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function rectangle(
        float $x,
        float $top,
        float $width,
        float $height,
        array $fill,
        ?array $stroke = null,
        float $lineWidth = 0.7
    ): void {
        $bottom = self::PAGE_HEIGHT - $top - $height;
        $command = 'q ' . $this->colorCommand($fill, false) . ' ';
        if ($stroke !== null) {
            $command .= $this->colorCommand($stroke, true) . ' ' .
                $this->number($lineWidth) . ' w ';
        }
        $command .= $this->number($x) . ' ' . $this->number($bottom) . ' ' .
            $this->number($width) . ' ' . $this->number($height) . ' re ' .
            ($stroke === null ? 'f' : 'B') . ' Q';
        $this->command($command);
    }

    public function line(
        float $x1,
        float $top1,
        float $x2,
        float $top2,
        array $color = [210, 218, 230],
        float $lineWidth = 0.6
    ): void {
        $y1 = self::PAGE_HEIGHT - $top1;
        $y2 = self::PAGE_HEIGHT - $top2;
        $this->command(
            'q ' . $this->colorCommand($color, true) . ' ' .
            $this->number($lineWidth) . ' w ' .
            $this->number($x1) . ' ' . $this->number($y1) . ' m ' .
            $this->number($x2) . ' ' . $this->number($y2) . ' l S Q'
        );
    }

    public function text(
        float $x,
        float $top,
        string $text,
        float $size = 9.0,
        bool $bold = false,
        array $color = [31, 41, 55],
        string $align = 'left',
        float $boxWidth = 0.0
    ): void {
        $plain = $this->plainText($text);
        if ($plain === '') {
            return;
        }

        if ($boxWidth > 0 && $align !== 'left') {
            $textWidth = $this->estimateWidth($plain, $size, $bold);
            if ($align === 'right') {
                $x += max(0, $boxWidth - $textWidth);
            } elseif ($align === 'center') {
                $x += max(0, ($boxWidth - $textWidth) / 2);
            }
        }

        $baseline = self::PAGE_HEIGHT - $top - $size;
        $font = $bold ? '/F2' : '/F1';
        $this->command(
            'BT ' . $font . ' ' . $this->number($size) . ' Tf ' .
            $this->colorCommand($color, false) . ' 1 0 0 1 ' .
            $this->number($x) . ' ' . $this->number($baseline) .
            ' Tm (' . $this->escapePdfText($plain) . ') Tj ET'
        );
    }

    public function wrappedText(
        float $x,
        float $top,
        string $text,
        float $width,
        float $size = 9.0,
        bool $bold = false,
        array $color = [31, 41, 55],
        float $lineHeight = 12.0,
        ?int $maximumLines = null
    ): float {
        $lines = $this->wrap($text, $width, $size, $bold);
        if ($maximumLines !== null && count($lines) > $maximumLines) {
            $lines = array_slice($lines, 0, $maximumLines);
            $lastIndex = count($lines) - 1;
            if ($lastIndex >= 0) {
                $lines[$lastIndex] = rtrim($lines[$lastIndex], '. ') . '...';
            }
        }
        if (empty($lines)) {
            $lines = ['-'];
        }

        foreach ($lines as $index => $line) {
            $this->text(
                $x,
                $top + ($index * $lineHeight),
                $line,
                $size,
                $bold,
                $color
            );
        }

        return max($lineHeight, count($lines) * $lineHeight);
    }

    public function wrap(
        string $text,
        float $width,
        float $size,
        bool $bold = false
    ): array {
        $plain = $this->plainText($text);
        if ($plain === '') {
            return [];
        }

        $paragraphs = preg_split('/\r?\n/', $plain) ?: [$plain];
        $lines = [];
        foreach ($paragraphs as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            $current = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }

                if ($this->estimateWidth($word, $size, $bold) > $width) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }

                    $chunk = '';
                    foreach (str_split($word) as $character) {
                        $candidateChunk = $chunk . $character;
                        if (
                            $chunk === '' ||
                            $this->estimateWidth($candidateChunk, $size, $bold) <= $width
                        ) {
                            $chunk = $candidateChunk;
                            continue;
                        }

                        $lines[] = $chunk;
                        $chunk = $character;
                    }
                    $current = $chunk;
                    continue;
                }

                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($this->estimateWidth($candidate, $size, $bold) <= $width) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines;
    }

    public function build(): string
    {
        if (empty($this->pages)) {
            throw new RuntimeException('The Official PRF PDF contains no pages.');
        }

        $objects = [];
        $pageObjectIds = [];
        $nextObjectId = 5;
        foreach ($this->pages as $_page) {
            $pageObjectIds[] = $nextObjectId;
            $nextObjectId += 2;
        }
        $infoObjectId = $nextObjectId;

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(
            static fn (int $id): string => $id . ' 0 R',
            $pageObjectIds
        ));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' .
            count($pageObjectIds) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $index => $commands) {
            $pageObjectId = $pageObjectIds[$index];
            $contentObjectId = $pageObjectId + 1;
            $stream = implode("\n", $commands) . "\n";
            $objects[$pageObjectId] =
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' .
                $this->number(self::PAGE_WIDTH) . ' ' .
                $this->number(self::PAGE_HEIGHT) .
                '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> ' .
                '/Contents ' . $contentObjectId . ' 0 R >>';
            $objects[$contentObjectId] =
                '<< /Length ' . strlen($stream) . " >>\nstream\n" .
                $stream . 'endstream';
        }

        $objects[$infoObjectId] = '<< /Title (' .
            $this->escapePdfText($this->title) . ') /Author (' .
            $this->escapePdfText($this->author) .
            ') /Creator (Fixie DRMS) /Producer (Fixie DRMS PDF Engine) >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $objectId => $body) {
            $offsets[$objectId] = strlen($pdf);
            $pdf .= $objectId . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $objectCount = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $objectCount . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id < $objectCount; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . $objectCount . ' /Root 1 0 R /Info ' .
            $infoObjectId . " 0 R >>\nstartxref\n" . $xrefOffset .
            "\n%%EOF\n";

        return $pdf;
    }

    private function command(string $command): void
    {
        if ($this->currentPage < 0) {
            throw new RuntimeException('Add a PDF page before drawing content.');
        }
        $this->pages[$this->currentPage][] = $command;
    }

    private function plainText(string $text): string
    {
        $text = str_replace(
            ["\xE2\x82\xB1", "\xE2\x80\x93", "\xE2\x80\x94", "\xC2\xB7"],
            ['PHP ', '-', '-', '-'],
            $text
        );
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text);
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', (string) $text));
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return trim($converted !== false ? $converted : $text);
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', '', ' '],
            $text
        );
    }

    private function estimateWidth(string $text, float $size, bool $bold): float
    {
        $widthUnits = 0.0;
        foreach (str_split($text) as $character) {
            if ($character === ' ') {
                $widthUnits += 0.28;
            } elseif (strpos('MW@#%', $character) !== false) {
                $widthUnits += 0.82;
            } elseif (ctype_upper($character)) {
                $widthUnits += 0.62;
            } elseif (ctype_digit($character)) {
                $widthUnits += 0.55;
            } elseif (strpos('.,:;!|ilI1', $character) !== false) {
                $widthUnits += 0.28;
            } else {
                $widthUnits += 0.50;
            }
        }
        return $widthUnits * $size * ($bold ? 1.03 : 1.0);
    }

    private function colorCommand(array $rgb, bool $stroke): string
    {
        $values = array_map(
            fn ($value): string => $this->number(
                min(255, max(0, (float) $value)) / 255
            ),
            array_pad(array_slice($rgb, 0, 3), 3, 0)
        );
        return implode(' ', $values) . ($stroke ? ' RG' : ' rg');
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}

function drms_prf_pdf_date(?string $value, string $format = 'M d, Y'): string
{
    if (!$value) {
        return 'Not recorded';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : 'Not recorded';
}

function drms_prf_pdf_money($value): string
{
    return 'PHP ' . number_format((float) $value, 2);
}

function drms_load_approved_prf_snapshot_data(mysqli $conn, int $pr_id): array
{
    $pr_stmt = $conn->prepare(
        "SELECT
            pr.*,
            creator.full_name AS creator_name,
            final_approver.full_name AS final_approver_name,
            quotation.quotation_number,
            client_po.actual_client_po_number,
            client_po.client_po_date,
            supplier.supplier_name,
            supplier.supplier_reference,
            supplier.supplier_quote_date,
            supplier.payment_method,
            supplier.payment_terms,
            supplier.bank_name,
            supplier.bank_account_name,
            supplier.bank_account_number,
            supplier.check_payee,
            supplier.remarks AS supplier_remarks
         FROM purchase_requests pr
         LEFT JOIN users creator
           ON creator.user_id = pr.created_by
         LEFT JOIN users final_approver
           ON final_approver.user_id = pr.final_approved_by
         LEFT JOIN quotations quotation
           ON quotation.quotation_id = pr.quotation_id
         LEFT JOIN client_approval_records client_po
           ON client_po.approval_record_id = pr.client_approval_record_id
         LEFT JOIN pr_supplier_details supplier
           ON supplier.pr_id = pr.pr_id
          AND supplier.record_status = 'Active'
         WHERE pr.pr_id = ?
         LIMIT 1"
    );
    $pr_stmt->bind_param('i', $pr_id);
    $pr_stmt->execute();
    $pr = $pr_stmt->get_result()->fetch_assoc();
    $pr_stmt->close();

    if (!$pr) {
        throw new RuntimeException('The approved PRF could not be loaded for filing.');
    }
    if (
        !in_array($pr['status'], ['Approved', 'Converted_to_PO'], true) ||
        $pr['current_approval_stage'] !== 'Official Approved' ||
        empty($pr['final_approved_by']) ||
        empty($pr['final_approved_at'])
    ) {
        throw new RuntimeException(
            'Only a final-approved PRF can be rendered as an Official Record.'
        );
    }

    $item_stmt = $conn->prepare(
        "SELECT
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
         FROM pr_items
         WHERE pr_id = ?
         ORDER BY item_id"
    );
    $item_stmt->bind_param('i', $pr_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    $items = [];
    while ($row = $item_result->fetch_assoc()) {
        $items[] = $row;
    }
    $item_stmt->close();
    if (empty($items)) {
        throw new RuntimeException('The approved PRF has no item lines.');
    }

    $approval_stmt = $conn->prepare(
        "SELECT
            approval.stage_sequence,
            approval.approval_stage,
            approval.required_role,
            approval.decision,
            approval.decision_remarks,
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
         ORDER BY approval.stage_sequence"
    );
    $approval_stmt->bind_param('ii', $pr_id, $pr_id);
    $approval_stmt->execute();
    $approval_result = $approval_stmt->get_result();
    $approvals = [];
    while ($row = $approval_result->fetch_assoc()) {
        $approvals[$row['approval_stage']] = $row;
    }
    $approval_stmt->close();

    foreach (['GM Review', 'Finance Review', 'Owner Approval'] as $stage) {
        $record = $approvals[$stage] ?? null;
        if (
            !$record ||
            $record['decision'] !== 'Approved' ||
            empty($record['acted_by']) ||
            empty($record['acted_at']) ||
            empty($record['acted_by_name'])
        ) {
            throw new RuntimeException(
                'The PRF approval signatures are incomplete for Official Record filing.'
            );
        }
    }

    $owner = $approvals['Owner Approval'];
    if (
        (int) $owner['acted_by'] !== (int) $pr['final_approved_by'] ||
        $owner['acted_at'] !== $pr['final_approved_at']
    ) {
        throw new RuntimeException(
            'The PRF final approval and Owner signature do not match.'
        );
    }

    return [
        'pr' => $pr,
        'items' => $items,
        'approvals' => $approvals,
    ];
}

function drms_render_official_prf_pdf(
    array $snapshot,
    string $record_number
): string {
    $pr = $snapshot['pr'];
    $items = $snapshot['items'];
    $approvals = $snapshot['approvals'];

    $pdf = new DrmsPrfPdfBuilder(
        $record_number . ' - Purchase Requisition Form',
        'Fixie Computer Ventures'
    );

    $navy = [22, 45, 77];
    $blue = [37, 99, 235];
    $green = [4, 120, 87];
    $ink = [24, 35, 52];
    $muted = [100, 116, 139];
    $border = [218, 226, 237];
    $soft = [246, 249, 253];

    $newPage = static function (
        DrmsPrfPdfBuilder $document,
        bool $continued = false
    ) use ($record_number, $pr, $navy, $blue, $green, $ink, $muted): float {
        $document->addPage();
        $document->rectangle(34, 28, 527, 74, [248, 250, 253], [218, 226, 237]);
        $document->rectangle(34, 28, 7, 74, $blue);
        $document->text(54, 42, 'FIXIE COMPUTER VENTURES', 9.5, true, $blue);
        $document->text(54, 58, 'PURCHASE REQUISITION FORM', 16, true, $navy);
        $document->text(
            54,
            80,
            $continued ? 'Official record - continued' : 'Official internal procurement record',
            8.5,
            false,
            $muted
        );
        $document->rectangle(407, 42, 136, 25, [232, 248, 242], [167, 229, 203]);
        $document->text(407, 48, 'OFFICIAL - APPROVED', 8.5, true, $green, 'center', 136);
        $document->text(407, 76, $record_number, 9.5, true, $ink, 'center', 136);
        $document->text(407, 90, (string) $pr['pr_number'], 8, false, $muted, 'center', 136);
        return 119.0;
    };

    $sectionTitle = static function (
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

    $valueCell = static function (
        DrmsPrfPdfBuilder $document,
        float $x,
        float $top,
        float $width,
        string $label,
        string $value
    ) use ($soft, $border, $muted, $ink): void {
        $document->rectangle($x, $top, $width, 48, $soft, $border);
        $document->text($x + 10, $top + 8, strtoupper($label), 7, true, $muted);
        $document->wrappedText($x + 10, $top + 23, $value, $width - 20, 9, true, $ink, 11, 2);
    };

    $y = $newPage($pdf, false);
    $cellWidth = 124.25;
    $valueCell($pdf, 40, $y, $cellWidth, 'PRF number', (string) $pr['pr_number']);
    $valueCell($pdf, 170, $y, $cellWidth, 'Quotation', (string) ($pr['quotation_number'] ?: 'Not recorded'));
    $valueCell($pdf, 300, $y, $cellWidth, 'Client PO', (string) ($pr['actual_client_po_number'] ?: 'Not recorded'));
    $valueCell($pdf, 430, $y, 125, 'Final approval', drms_prf_pdf_date($pr['final_approved_at'], 'M d, Y h:i A'));
    $y += 63;

    $y = $sectionTitle($pdf, $y, 'Request context', 'Client and supplier details');
    $pdf->rectangle(40, $y, 253, 94, [255, 255, 255], $border);
    $pdf->text(52, $y + 10, 'CLIENT / REQUEST', 7.5, true, $blue);
    $pdf->text(52, $y + 27, (string) $pr['client_name'], 11, true, $ink);
    $pdf->text(52, $y + 47, 'Prepared by: ' . ($pr['creator_name'] ?: 'Not recorded'), 8.5, false, $muted);
    $pdf->text(52, $y + 63, 'Submitted: ' . drms_prf_pdf_date($pr['submitted_for_approval_at'], 'M d, Y h:i A'), 8.5, false, $muted);
    $pdf->text(52, $y + 79, 'Client PO date: ' . drms_prf_pdf_date($pr['client_po_date']), 8.5, false, $muted);

    $pdf->rectangle(302, $y, 253, 94, [255, 255, 255], $border);
    $pdf->text(314, $y + 10, 'SUPPLIER', 7.5, true, $blue);
    $pdf->text(314, $y + 27, (string) ($pr['supplier_name'] ?: 'Not recorded'), 11, true, $ink);
    $pdf->text(314, $y + 47, 'Reference: ' . ($pr['supplier_reference'] ?: 'Not recorded'), 8.5, false, $muted);
    $pdf->text(314, $y + 63, 'Quote date: ' . drms_prf_pdf_date($pr['supplier_quote_date']), 8.5, false, $muted);
    $pdf->text(314, $y + 79, 'Payment: ' . ($pr['payment_method'] ?: 'Not recorded'), 8.5, false, $muted);
    $y += 112;

    $drawItemsHeader = static function (
        DrmsPrfPdfBuilder $document,
        float $top
    ) use ($navy): float {
        $document->rectangle(40, $top, 515, 25, $navy);
        $document->text(47, $top + 7, 'QTY', 7.5, true, [255, 255, 255]);
        $document->text(79, $top + 7, 'ITEM / SPECIFICATION', 7.5, true, [255, 255, 255]);
        $document->text(337, $top + 7, 'UNIT COST', 7.5, true, [255, 255, 255], 'right', 65);
        $document->text(410, $top + 7, 'TOTAL COST', 7.5, true, [255, 255, 255], 'right', 65);
        $document->text(482, $top + 7, 'SELLING', 7.5, true, [255, 255, 255], 'right', 65);
        return $top + 25;
    };

    $y = $sectionTitle($pdf, $y, 'Requested items', 'Cost and selling basis');
    $y = $drawItemsHeader($pdf, $y);
    foreach ($items as $index => $item) {
        $itemTitle = trim((string) ($item['brand'] . ' ' . $item['item_name']));
        $details = trim(implode(' - ', array_filter([
            (string) $item['category'],
            (string) $item['specifications'],
        ])));
        $titleLines = $pdf->wrap($itemTitle, 240, 8.7, true);
        $detailLines = $pdf->wrap($details !== '' ? $details : 'No additional specification', 240, 7.6, false);
        $rowHeight = max(34, 10 + (min(2, count($titleLines)) * 10) + (min(2, count($detailLines)) * 9));

        if ($y + $rowHeight > 725) {
            $y = $newPage($pdf, true);
            $pdf->text(40, $y, 'REQUESTED ITEMS - CONTINUED', 8, true, $blue);
            $y += 16;
            $y = $drawItemsHeader($pdf, $y);
        }

        $fill = $index % 2 === 0 ? [255, 255, 255] : $soft;
        $pdf->rectangle(40, $y, 515, $rowHeight, $fill, $border);
        $pdf->text(47, $y + 10, (string) (int) $item['quantity'], 8.5, true, $ink);
        $pdf->wrappedText(79, $y + 7, $itemTitle, 240, 8.7, true, $ink, 10, 2);
        $pdf->wrappedText(79, $y + 27, $details !== '' ? $details : 'No additional specification', 240, 7.6, false, $muted, 9, 2);
        $pdf->text(337, $y + 11, drms_prf_pdf_money($item['unit_cost']), 8, false, $ink, 'right', 65);
        $pdf->text(410, $y + 11, drms_prf_pdf_money($item['total_cost']), 8, false, $ink, 'right', 65);
        $pdf->text(482, $y + 11, drms_prf_pdf_money($item['total_price']), 8, true, $ink, 'right', 65);
        $y += $rowHeight;
    }
    $y += 18;

    if ($y + 185 > 750) {
        $y = $newPage($pdf, true);
    }
    $y = $sectionTitle($pdf, $y, 'Financial authorization', 'Requested company funds and projected return');
    $pdf->rectangle(40, $y, 245, 128, $soft, $border);
    $pdf->text(53, $y + 12, 'PURPOSE AND PAYMENT ROUTE', 7.5, true, $blue);
    $pdf->wrappedText(
        53,
        $y + 32,
        'Request company funds to purchase the approved client order from ' .
            ($pr['supplier_name'] ?: 'the recorded supplier') . '.',
        219,
        9,
        false,
        $ink,
        12,
        4
    );
    $pdf->text(53, $y + 83, 'Method: ' . ($pr['payment_method'] ?: 'Not recorded'), 8.2, false, $muted);
    $pdf->wrappedText(53, $y + 99, 'Terms: ' . ($pr['payment_terms'] ?: 'Not recorded'), 219, 8.2, false, $muted, 10, 2);

    $pdf->rectangle(296, $y, 259, 128, [255, 255, 255], $border);
    $financialRows = [
        ['Client selling amount', drms_prf_pdf_money($pr['amount']), false],
        ['Cost of goods', drms_prf_pdf_money($pr['cost_of_goods_amount']), false],
        ['Other expense', drms_prf_pdf_money($pr['other_expense_amount']), false],
        ['Funds requested', drms_prf_pdf_money($pr['requested_fund_amount']), true],
        ['Projected gross profit', drms_prf_pdf_money($pr['gross_profit_amount']), true],
        ['Projected margin', number_format((float) $pr['gross_margin_percent'], 2) . '%', false],
    ];
    foreach ($financialRows as $rowIndex => $row) {
        $rowTop = $y + 10 + ($rowIndex * 18);
        $pdf->text(309, $rowTop, $row[0], 8, false, $muted);
        $pdf->text(418, $rowTop, $row[1], 8.5, (bool) $row[2], $row[2] ? $green : $ink, 'right', 124);
    }
    $y += 148;

    if ($y + 135 > 790) {
        $y = $newPage($pdf, true);
    }
    $y = $sectionTitle($pdf, $y, 'Signatories', 'Authenticated preparation and approval record');

    $signatories = [
        [
            'Prepared by Sales Staff',
            (string) ($pr['creator_name'] ?: 'Not recorded'),
            'SUBMITTED',
            drms_prf_pdf_date($pr['submitted_for_approval_at'], 'M d, Y h:i A'),
        ],
        [
            'Reviewed by General Manager',
            (string) $approvals['GM Review']['acted_by_name'],
            'APPROVED',
            drms_prf_pdf_date($approvals['GM Review']['acted_at'], 'M d, Y h:i A'),
        ],
        [
            'Checked by Finance',
            (string) $approvals['Finance Review']['acted_by_name'],
            'APPROVED',
            drms_prf_pdf_date($approvals['Finance Review']['acted_at'], 'M d, Y h:i A'),
        ],
        [
            'Approved by Owner / President',
            (string) $approvals['Owner Approval']['acted_by_name'],
            'FINAL APPROVAL',
            drms_prf_pdf_date($approvals['Owner Approval']['acted_at'], 'M d, Y h:i A'),
        ],
    ];

    foreach ($signatories as $index => $signatory) {
        $x = 40 + ($index * 130);
        $top = $y;
        $pdf->rectangle($x, $top, 125, 82, [255, 255, 255], $border);
        $pdf->wrappedText($x + 9, $top + 8, strtoupper($signatory[0]), 107, 6.7, true, $blue, 8, 2);
        $pdf->wrappedText($x + 9, $top + 30, $signatory[1], 107, 8.8, true, $ink, 10, 2);
        $pdf->line($x + 9, $top + 53, $x + 116, $top + 53, $border, 0.7);
        $pdf->text($x + 9, $top + 59, $signatory[2], 6.5, true, $green);
        $pdf->text($x + 9, $top + 70, $signatory[3], 6.3, false, $muted);
    }

    $pageCount = $pdf->pageCount();
    for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
        $pdf->selectPage($pageIndex);
        $pdf->line(40, 800, 555, 800, $border, 0.6);
        $pdf->text(
            40,
            809,
            'Generated and locked by Fixie DRMS after final Owner / President approval.',
            7.2,
            false,
            $muted
        );
        $pdf->text(
            455,
            809,
            'Page ' . ($pageIndex + 1) . ' of ' . $pageCount,
            7.2,
            true,
            $muted,
            'right',
            100
        );
    }

    return $pdf->build();
}

function drms_file_approved_prf_as_official_record(
    mysqli $conn,
    int $pr_id,
    int $declared_by
): array {
    $snapshot = drms_load_approved_prf_snapshot_data($conn, $pr_id);
    $pr = $snapshot['pr'];
    $pr_number = (string) $pr['pr_number'];
    $tags = implode(',', array_filter([
        'prf',
        'purchase requisition',
        'client po ' . trim((string) ($pr['actual_client_po_number'] ?? '')),
        'quotation ' . trim((string) ($pr['quotation_number'] ?? '')),
    ]));

    return drms_file_generated_pdf_as_official_record(
        $conn,
        static function (
            string $record_number,
            string $_stored_file_name
        ) use ($snapshot): string {
            return drms_render_official_prf_pdf($snapshot, $record_number);
        },
        $pr_number . '-official.pdf',
        'Official Purchase Requisition Forms',
        'Purchase Requisition Form',
        $declared_by,
        (string) $pr['final_approved_at'],
        'Purchase Requisition Form',
        $pr_id,
        $pr_number,
        !empty($pr['created_by']) ? (int) $pr['created_by'] : $declared_by,
        null,
        $tags
    );
}
