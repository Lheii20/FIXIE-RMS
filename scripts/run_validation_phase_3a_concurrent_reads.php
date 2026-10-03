<?php
declare(strict_types=1);

/**
 * Phase 3A: read-only, bounded, simultaneous list-query baseline.
 *
 * Run from the isolated test project only:
 * php scripts/run_validation_phase_3a_concurrent_reads.php
 * Or inspect an existing E2E clone without copying this file:
 * php path/to/run_validation_phase_3a_concurrent_reads.php C:/xampp/htdocs/fixie_drms_e2e
 *
 * This is a database read test, not an authenticated HTTP or load certification.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = isset($argv[1]) ? rtrim((string) $argv[1], '/\\') : dirname(__DIR__);
$resolvedRoot = realpath($projectRoot);
$markerPath = $resolvedRoot !== false ? $resolvedRoot . '/.fixie-e2e-environment.json' : '';
if ($resolvedRoot === false || !is_file($markerPath)) {
    fwrite(STDERR, "Phase 3A refused: an isolated, marked E2E project is required.\n");
    exit(2);
}

try {
    $marker = json_decode((string) file_get_contents($markerPath), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($marker)
        || ($marker['format'] ?? '') !== 'FIXIE_DRMS_ISOLATED_E2E'
        || realpath((string) ($marker['test_project'] ?? '')) !== $resolvedRoot
        || stripos((string) ($marker['test_database'] ?? ''), 'e2e') === false) {
        throw new RuntimeException('The E2E marker does not match this project.');
    }

    require_once $resolvedRoot . '/config/runtime.php';
    $database = drms_runtime_section('database');
    if ((string) $database['name'] !== (string) $marker['test_database']) {
        throw new RuntimeException('The configured database does not match the isolated E2E marker.');
    }
    if (!defined('MYSQLI_ASYNC')) {
        throw new RuntimeException('This PHP mysqli build does not support asynchronous queries.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $connect = static function () use ($database): mysqli {
        $client = new mysqli(
            (string) $database['host'],
            (string) $database['user'],
            (string) $database['password'],
            (string) $database['name'],
            (int) $database['port']
        );
        $client->set_charset('utf8mb4');
        return $client;
    };

    $workers = 4;
    $waves = 6;
    $clients = [];
    try {
        for ($i = 0; $i < $workers; $i++) {
            $clients[] = $connect();
        }

        $sampleResult = $clients[0]->query(
            "SELECT
                COUNT(*) AS all_documents,
                SUM(status = 'Active' AND record_phase IN ('Working', 'For Review')) AS active_company,
                SUM(status = 'Active' AND record_phase = 'Official') AS active_official
             FROM documents"
        );
        $sample = $sampleResult->fetch_assoc();
        $sampleResult->free();
        $installedIndexes = [];
        $indexResult = $clients[0]->query('SHOW INDEX FROM `documents`');
        while ($index = $indexResult->fetch_assoc()) {
            $installedIndexes[strtolower((string) $index['Key_name'])] = true;
        }
        $indexResult->free();
        $phase2dIndexesReady = isset(
            $installedIndexes['idx_documents_status_uploaded_id'],
            $installedIndexes['idx_documents_status_category_uploaded_id']
        );
        $documentCount = (int) ($sample['all_documents'] ?? 0);
        echo "Fixie DRMS Phase 3A: concurrent read baseline\n";
        echo 'Project: ' . $resolvedRoot . PHP_EOL;
        echo 'Database: ' . (string) $database['name'] . PHP_EOL;
        echo 'Documents: ' . $documentCount
            . ' (active company ' . (int) ($sample['active_company'] ?? 0)
            . ', active official ' . (int) ($sample['active_official'] ?? 0) . ")\n";
        echo "Workers: {$workers}; waves per query: {$waves}; SELECT statements only.\n\n";

        $queries = [
            'Company count' =>
                "SELECT COUNT(*) AS total FROM documents d
                 WHERE d.status = 'Active'
                   AND (d.record_phase = 'Working' OR d.record_phase = 'For Review' OR d.record_phase IS NULL)
                   AND COALESCE(d.disposition_status, '') <> 'Destroyed'",
            'Company first page' =>
                "SELECT d.doc_id, d.file_name, p.po_number, u.full_name
                 FROM documents d
                 LEFT JOIN purchase_orders p ON p.po_id = d.po_id
                 LEFT JOIN users u ON u.user_id = d.uploaded_by
                 WHERE d.status = 'Active'
                   AND (d.record_phase = 'Working' OR d.record_phase = 'For Review' OR d.record_phase IS NULL)
                   AND COALESCE(d.disposition_status, '') <> 'Destroyed'
                 ORDER BY d.uploaded_at DESC, d.doc_id DESC LIMIT 15 OFFSET 0",
            'Company second page' =>
                "SELECT d.doc_id, d.file_name, p.po_number, u.full_name
                 FROM documents d
                 LEFT JOIN purchase_orders p ON p.po_id = d.po_id
                 LEFT JOIN users u ON u.user_id = d.uploaded_by
                 WHERE d.status = 'Active'
                   AND (d.record_phase = 'Working' OR d.record_phase = 'For Review' OR d.record_phase IS NULL)
                   AND COALESCE(d.disposition_status, '') <> 'Destroyed'
                 ORDER BY d.uploaded_at DESC, d.doc_id DESC LIMIT 15 OFFSET 15",
            'Official count' =>
                "SELECT COUNT(*) AS total FROM documents d
                 WHERE d.status = 'Active' AND d.record_phase = 'Official'
                   AND COALESCE(d.disposition_status, '') <> 'Destroyed'",
            'Official first page' =>
                "SELECT d.doc_id, d.file_name, p.po_number, u.full_name
                 FROM documents d
                 LEFT JOIN purchase_orders p ON p.po_id = d.po_id
                 LEFT JOIN users u ON u.user_id = d.uploaded_by
                 WHERE d.status = 'Active' AND d.record_phase = 'Official'
                   AND COALESCE(d.disposition_status, '') <> 'Destroyed'
                 ORDER BY COALESCE(d.declared_at, d.uploaded_at) DESC, d.doc_id DESC
                 LIMIT 15 OFFSET 0",
        ];

        $percentile = static function (array $values, float $fraction): float {
            sort($values, SORT_NUMERIC);
            return $values[max(0, (int) ceil(count($values) * $fraction) - 1)];
        };

        foreach ($queries as $label => $sql) {
            // Warm the query once before measuring. The result is still read-only.
            $warm = $clients[0]->query($sql);
            if (!$warm instanceof mysqli_result) {
                throw new RuntimeException("Warm-up query failed: {$label}.");
            }
            $warm->free();

            $durations = [];
            for ($wave = 0; $wave < $waves; $wave++) {
                $pending = [];
                $started = hrtime(true);
                foreach ($clients as $client) {
                    $client->query($sql, MYSQLI_ASYNC);
                    $pending[spl_object_id($client)] = $client;
                }

                while ($pending !== []) {
                    $read = array_values($pending);
                    $errors = array_values($pending);
                    $reject = array_values($pending);
                    $ready = mysqli_poll($read, $errors, $reject, 5, 0);
                    if ($ready === false || $ready === 0) {
                        throw new RuntimeException("A concurrent query timed out or could not be polled: {$label}.");
                    }
                    $readyClients = [];
                    foreach (array_merge($read, $errors, $reject) as $client) {
                        $readyClients[spl_object_id($client)] = $client;
                    }
                    foreach ($readyClients as $id => $client) {
                        $result = $client->reap_async_query();
                        if (!$result instanceof mysqli_result) {
                            throw new RuntimeException("A concurrent SELECT failed: {$label}.");
                        }
                        if (str_contains($label, 'page') && $result->num_rows > 15) {
                            throw new RuntimeException("A page returned more than 15 rows: {$label}.");
                        }
                        $result->free();
                        unset($pending[$id]);
                    }
                }
                $durations[] = (hrtime(true) - $started) / 1_000_000;
            }

            printf(
                "PASS  %-24s median %7.2f ms | p95 %7.2f ms | max %7.2f ms\n",
                $label,
                $percentile($durations, 0.50),
                $percentile($durations, 0.95),
                max($durations)
            );
        }

        echo "\nPhase 3A read-only concurrent database check PASSED.\n";
        if (!$phase2dIndexesReady) {
            echo "LIMITATION: Phase 2D list indexes are absent in this E2E database; timings do not reflect the indexed deployment.\n";
        }
        if ($documentCount < 1000) {
            echo "LIMITATION: Fewer than 1,000 documents exist in this E2E database; this is a small-data baseline, not large-record evidence.\n";
        }
        echo "LIMITATION: This does not test authenticated pages, uploads, approvals, or hosted-server concurrency.\n";
    } finally {
        foreach ($clients as $client) {
            $client->close();
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Phase 3A FAILED: " . $error->getMessage() . "\n");
    exit(1);
}
