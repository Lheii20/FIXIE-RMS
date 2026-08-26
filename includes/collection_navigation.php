<?php
$collection_section = isset($collection_section)
    ? (string) $collection_section
    : 'overview';

$collection_navigation = [
    'overview' => [
        'href' => 'collection_monitoring.php',
        'icon' => 'fas fa-chart-line',
        'label' => 'Overview',
        'description' => 'Status & follow-up',
    ],
    'receivables' => [
        'href' => 'collection_aging.php',
        'icon' => 'fas fa-file-invoice-dollar',
        'label' => 'Receivables',
        'description' => 'Aging & priority',
    ],
    'payments' => [
        'href' => 'collection_ledger.php',
        'icon' => 'fas fa-receipt',
        'label' => 'Payments',
        'description' => 'Ledger & proof',
    ],
];
?>
<section class="collection-module-bar" aria-label="Collections module navigation">
    <div class="collection-module-name">
        <span><i class="fas fa-hand-holding-usd"></i></span>
        <div>
            <strong>Collections</strong>
            <small>PO receivable records</small>
        </div>
    </div>

    <nav class="collection-module-links">
        <?php foreach ($collection_navigation as $section_key => $section): ?>
            <?php $is_active = $collection_section === $section_key; ?>
            <a
                href="<?php echo htmlspecialchars($section['href']); ?>"
                class="<?php echo $is_active ? 'is-active' : ''; ?>"
                <?php echo $is_active ? 'aria-current="page"' : ''; ?>
            >
                <i class="<?php echo htmlspecialchars($section['icon']); ?>"></i>
                <span>
                    <strong><?php echo htmlspecialchars($section['label']); ?></strong>
                    <small><?php echo htmlspecialchars($section['description']); ?></small>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>
</section>
