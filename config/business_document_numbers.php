<?php
declare(strict_types=1);

/**
 * Fixie DRMS business document numbering.
 *
 * This allocator is deliberately separate from Official Record numbering.
 * It issues internal transaction numbers only after the caller has opened
 * its database transaction, so simultaneous users cannot receive the same
 * quotation, PRF, or internal PO number.
 */

if (!function_exists('drms_business_number_definition')) {
    /**
     * @return array{prefix: string, label: string, padding: int}
     */
    function drms_business_number_definition(string $numberType): array
    {
        $definitions = [
            'quotation' => [
                'prefix' => 'QTN',
                'label' => 'Quotation',
                'padding' => 4,
            ],
            'prf' => [
                'prefix' => 'PR',
                'label' => 'Purchase Request Form',
                'padding' => 4,
            ],
            'po' => [
                'prefix' => 'PO',
                'label' => 'Internal Purchase Order',
                'padding' => 4,
            ],
            'physical_disposition' => [
                'prefix' => 'PCD',
                'label' => 'Physical Copy Disposal Evidence',
                'padding' => 6,
            ],
        ];

        $normalizedType = strtolower(trim($numberType));
        if (!isset($definitions[$normalizedType])) {
            throw new DomainException('The requested business document number type is invalid.');
        }

        return $definitions[$normalizedType];
    }
}

if (!function_exists('drms_allocate_business_document_number')) {
    /**
     * Allocates an internal business number in the active database transaction.
     *
     * Format:
     * - quotation: QTN-YYYY-####
     * - prf:       PR-YYYY-####
     * - po:        PO-YYYY-####
     * - physical_disposition: PCD-YYYY-######
     */
    function drms_allocate_business_document_number(
        mysqli $conn,
        string $numberType,
        ?DateTimeInterface $issuedAt = null
    ): string {
        $definition = drms_business_number_definition($numberType);
        $normalizedType = strtolower(trim($numberType));
        $documentYear = (int) ($issuedAt ?? new DateTimeImmutable('now'))->format('Y');

        $sequenceStatement = $conn->prepare(
            "INSERT INTO business_document_sequences (
                document_type,
                document_year,
                last_sequence
            ) VALUES (
                ?,
                ?,
                LAST_INSERT_ID(1)
            )
            ON DUPLICATE KEY UPDATE
                last_sequence = LAST_INSERT_ID(last_sequence + 1),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$sequenceStatement) {
            throw new RuntimeException('The business document numbering service is unavailable.');
        }

        $sequenceStatement->bind_param('si', $normalizedType, $documentYear);
        if (!$sequenceStatement->execute()) {
            $sequenceStatement->close();
            throw new RuntimeException('The next business document number could not be allocated.');
        }
        $sequenceStatement->close();

        $sequenceResult = $conn->query('SELECT LAST_INSERT_ID() AS allocated_sequence');
        $sequenceRow = $sequenceResult ? $sequenceResult->fetch_assoc() : null;
        $allocatedSequence = (int) ($sequenceRow['allocated_sequence'] ?? 0);

        if ($allocatedSequence < 1) {
            throw new RuntimeException('The next business document number could not be allocated.');
        }

        return sprintf(
            '%s-%04d-%0' . (int) $definition['padding'] . 'd',
            $definition['prefix'],
            $documentYear,
            $allocatedSequence
        );
    }
}

if (!function_exists('drms_business_document_number_preview')) {
    /**
     * Returns display copy only. It never reserves or allocates a number.
     */
    function drms_business_document_number_preview(string $numberType): string
    {
        $definition = drms_business_number_definition($numberType);

        return $definition['prefix'] . '-' . date('Y') . '-Auto';
    }
}


