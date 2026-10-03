<?php
declare(strict_types=1);

/** Phase 3B: synthetic SQL pagination/search correctness and concurrent reads.
 * Usage: php this-file.php C:/xampp/htdocs/fixie_drms_e2e
 * Only connection-local TEMPORARY tables are written. No real files are created.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const P3B_TABLE = 'phase3b_validation_documents';
const P3B_ROWS = 10000;
const P3B_WORKERS = 4;
const P3B_WAVES = 8;
const P3B_SIZE = 15;

function p3bAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function p3bCount(mysqli $db): int
{
    $result = $db->query('SELECT COUNT(*) AS n FROM documents');
    $count = (int) $result->fetch_assoc()['n'];
    $result->free();
    return $count;
}

function p3bFixture(int $baseId): array
{
    $rows = [];
    $epoch = strtotime('2026-01-01 00:00:00 UTC');
    for ($i = 1; $i <= P3B_ROWS; $i++) {
        $official = $i % 4 === 0;
        $date = gmdate('Y-m-d H:i:s', $epoch + intdiv($i, 6) * 3600);
        $rows[] = [
            'doc_id' => $baseId + $i,
            'file_name' => 'Validation file ' . sprintf('%05d', intdiv($i, 3)) . '.pdf',
            'original_file_name' => 'Original source ' . sprintf('%05d', $i) . '.pdf',
            'record_number' => $official ? 'TEST-2026-' . sprintf('%06d', $i) : null,
            'business_reference' => 'REF-' . sprintf('%06d', $i),
            'doc_type' => 'Validation fixture',
            'category' => 'Folder ' . sprintf('%02d', $i % 12),
            'file_path' => '__phase3b_no_file__/fixture-' . $i . '.pdf',
            'file_hash' => str_repeat('0', 64),
            'uploaded_at' => $date,
            'declared_at' => $official && $i % 5 !== 0
                ? gmdate('Y-m-d H:i:s', strtotime($date . ' UTC') + 86400) : null,
            'status' => $i % 17 === 0 ? 'Recycled' : ($i % 13 === 0 ? 'Archived' : 'Active'),
            'record_phase' => $official ? 'Official' : ($i % 7 === 0 ? 'For Review' : 'Working'),
            'disposition_status' => $i % 97 === 0 ? 'Destroyed' : 'Pending',
        ];
    }
    return $rows;
}

function p3bSeed(mysqli $db, array $rows): void
{
    // LIKE copies the actual columns/indexes, but not persistent table contents.
    $db->query('CREATE TEMPORARY TABLE `' . P3B_TABLE . '` LIKE `documents`');
    $result = $db->query('SHOW INDEX FROM `' . P3B_TABLE . '`');
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[strtolower($row['Key_name'])][(int) $row['Seq_in_index']] = $row['Column_name'];
    }
    $result->free();
    $expected = [
        'idx_documents_status_uploaded_id' => ['status', 'uploaded_at', 'doc_id'],
        'idx_documents_status_category_uploaded_id' => ['status', 'category', 'uploaded_at', 'doc_id'],
    ];
    foreach ($expected as $name => $columns) {
        if (isset($indexes[$name])) {
            ksort($indexes[$name]);
            p3bAssert(array_values($indexes[$name]) === $columns, 'Conflicting copied index: ' . $name);
        } else {
            $db->query('CREATE INDEX `' . $name . '` ON `' . P3B_TABLE . '` (`' . implode('`,`', $columns) . '`)');
        }
    }
    $columns = array_keys($rows[0]);
    $group = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    foreach (array_chunk($rows, 250) as $batch) {
        $sql = 'INSERT INTO `' . P3B_TABLE . '` (`' . implode('`,`', $columns) . '`) VALUES '
            . implode(',', array_fill(0, count($batch), $group));
        $values = [];
        foreach ($batch as $row) foreach ($columns as $column) $values[] = $row[$column];
        $stmt = $db->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $stmt->close();
    }
    $result = $db->query('SELECT COUNT(*) AS n FROM `' . P3B_TABLE . '`');
    p3bAssert((int) $result->fetch_assoc()['n'] === P3B_ROWS, 'Fixture row count is incorrect.');
    $result->free();
}

function p3bSpec(string $module, string $sort = 'date_desc', string $status = 'Active', string $search = '', ?string $folder = null): array
{
    return compact('module', 'sort', 'status', 'search', 'folder');
}

function p3bSql(array $spec): array
{
    $where = "d.status = ? AND COALESCE(d.disposition_status, '') <> 'Destroyed'";
    $params = [$spec['status']];
    $where .= $spec['module'] === 'company'
        ? " AND (d.record_phase = 'Working' OR d.record_phase = 'For Review' OR d.record_phase IS NULL)"
        : " AND d.record_phase = 'Official'";
    $fields = $spec['module'] === 'company'
        ? ['file_name', 'category']
        : ['file_name', 'original_file_name', 'record_number', 'business_reference', 'doc_type', 'category'];
    if ($spec['search'] !== '') {
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = 'd.' . $field . ' LIKE ?';
            $params[] = '%' . $spec['search'] . '%';
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
    }
    if ($spec['folder'] !== null) { $where .= ' AND d.category = ?'; $params[] = $spec['folder']; }
    $date = $spec['module'] === 'company' ? 'd.uploaded_at' : 'COALESCE(d.declared_at, d.uploaded_at)';
    $sorts = [
        'date_desc' => $date . ' DESC, d.doc_id DESC',
        'date_asc' => $date . ' ASC, d.doc_id ASC',
        'name_asc' => 'd.file_name ASC, d.doc_id ASC',
        'name_desc' => 'd.file_name DESC, d.doc_id DESC',
    ];
    p3bAssert(isset($sorts[$spec['sort']]), 'Unknown test sort.');
    // Match the list's metadata joins and physical-path lookup. Fixtures have
    // null link IDs and IDs outside the permanent document range.
    $sql = 'SELECT d.*, p.po_number, p.client_name, p.amount, p.status AS po_status,
                   u.full_name, locker.full_name AS locked_by_name,
                   vdl.status AS physical_status, vdl.physical_folder_id,
                   (SELECT CONCAT_WS(\' > \', pb.name, pr.name, pc.name, pd.name, bx.name, pf.name)
                    FROM virt_document_locations pl
                    JOIN virt_physical_folders pf ON pf.id = pl.physical_folder_id
                    LEFT JOIN virt_storage_boxes bx ON bx.id = pf.box_id
                    LEFT JOIN virt_drawers pd ON pd.id = COALESCE(pf.drawer_id, bx.drawer_id)
                    LEFT JOIN virt_cabinets pc ON pc.id = pd.cabinet_id
                    LEFT JOIN virt_rooms pr ON pr.id = COALESCE(bx.room_id, pc.room_id)
                    LEFT JOIN virt_buildings pb ON pb.id = pr.building_id
                    WHERE pl.document_id = d.doc_id LIMIT 1) AS full_physical_path
            FROM `' . P3B_TABLE . '` d
            LEFT JOIN purchase_orders p ON p.po_id = d.po_id
            LEFT JOIN users u ON u.user_id = d.uploaded_by
            LEFT JOIN users locker ON locker.user_id = d.locked_by
            LEFT JOIN virt_document_locations vdl ON vdl.document_id = d.doc_id
            WHERE ' . $where . ' ORDER BY ' . $sorts[$spec['sort']] . ' LIMIT ? OFFSET ?';
    return [$sql, $params, 'SELECT COUNT(*) AS n FROM `' . P3B_TABLE . '` d WHERE ' . $where];
}

function p3bExpected(array $rows, array $spec): array
{
    $result = array_values(array_filter($rows, static function (array $row) use ($spec): bool {
        if ($row['status'] !== $spec['status'] || $row['disposition_status'] === 'Destroyed') return false;
        if (($row['record_phase'] === 'Official') !== ($spec['module'] === 'official')) return false;
        if ($spec['folder'] !== null && $row['category'] !== $spec['folder']) return false;
        if ($spec['search'] === '') return true;
        $fields = $spec['module'] === 'company' ? ['file_name', 'category']
            : ['file_name', 'original_file_name', 'record_number', 'business_reference', 'doc_type', 'category'];
        foreach ($fields as $field) {
            if (stripos((string) $row[$field], $spec['search']) !== false) return true;
        }
        return false;
    }));
    usort($result, static function (array $a, array $b) use ($spec): int {
        $name = str_starts_with($spec['sort'], 'name_');
        $av = $name ? $a['file_name'] : ($spec['module'] === 'official' ? ($a['declared_at'] ?? $a['uploaded_at']) : $a['uploaded_at']);
        $bv = $name ? $b['file_name'] : ($spec['module'] === 'official' ? ($b['declared_at'] ?? $b['uploaded_at']) : $b['uploaded_at']);
        $comparison = strcmp($av, $bv) ?: ($a['doc_id'] <=> $b['doc_id']);
        return str_ends_with($spec['sort'], '_asc') ? $comparison : -$comparison;
    });
    return array_column($result, 'doc_id');
}

function p3bCheck(mysqli $db, array $rows, string $label, array $spec, bool $traverse = false): void
{
    $expected = p3bExpected($rows, $spec);
    [$sql, $params, $countSql] = p3bSql($spec);
    $count = $db->prepare($countSql);
    $count->bind_param(str_repeat('s', count($params)), ...$params);
    $count->execute();
    $result = $count->get_result();
    p3bAssert((int) $result->fetch_assoc()['n'] === count($expected), $label . ': count mismatch.');
    $result->free(); $count->close();
    $last = max(0, (int) floor(max(0, count($expected) - 1) / P3B_SIZE) * P3B_SIZE);
    $offsets = $traverse ? range(0, $last, P3B_SIZE) : array_unique([0, P3B_SIZE, $last, $last + P3B_SIZE]);
    $stmt = $db->prepare($sql);
    $seen = [];
    foreach ($offsets as $offset) {
        $values = array_merge($params, [P3B_SIZE, $offset]);
        $stmt->bind_param(str_repeat('s', count($params)) . 'ii', ...$values);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) $ids[] = (int) $row['doc_id'];
        $result->free();
        p3bAssert($ids === array_slice($expected, $offset, P3B_SIZE), $label . ': page mismatch at offset ' . $offset);
        if ($traverse) $seen = array_merge($seen, $ids);
    }
    $stmt->close();
    if ($traverse) p3bAssert($seen === $expected && count($seen) === count(array_unique($seen)), $label . ': skipped or repeated IDs.');
    echo 'PASS  ' . $label . ' (' . count($expected) . ' matching records)' . PHP_EOL;
}

function p3bConcurrent(array $clients, array $spec, array $expected, string $label): void
{
    [$sql, $params] = p3bSql($spec);
    // All values below are fixed test values. Async mysqli does not support
    // prepared statements, so quote each value with its connection's charset.
    $values = array_merge($params, [P3B_SIZE, 0]);
    $times = [];
    for ($wave = 0; $wave < P3B_WAVES; $wave++) {
        $pending = [];
        $started = hrtime(true);
        foreach ($clients as $client) {
            $parts = explode('?', $sql);
            $literal = array_shift($parts);
            foreach ($parts as $i => $part) {
                $value = $values[$i];
                $literal .= (is_int($value) ? (string) $value : "'" . $client->real_escape_string($value) . "'") . $part;
            }
            $client->query($literal, MYSQLI_ASYNC);
            $pending[spl_object_id($client)] = $client;
        }
        while ($pending !== []) {
            $read = $errors = $reject = array_values($pending);
            $ready = mysqli_poll($read, $errors, $reject, 5, 0);
            p3bAssert($ready !== false && $ready > 0, $label . ': polling failed or no query completed within five seconds.');
            $unique = [];
            foreach (array_merge($read, $errors, $reject) as $client) $unique[spl_object_id($client)] = $client;
            foreach ($unique as $id => $client) {
                $result = $client->reap_async_query();
                p3bAssert($result instanceof mysqli_result, $label . ': missing SELECT result.');
                $ids = [];
                while ($row = $result->fetch_assoc()) $ids[] = (int) $row['doc_id'];
                $result->free();
                p3bAssert($ids === array_slice($expected, 0, P3B_SIZE), $label . ': concurrent result mismatch.');
                unset($pending[$id]);
            }
        }
        $times[] = (hrtime(true) - $started) / 1_000_000;
    }
    sort($times, SORT_NUMERIC);
    printf("PASS  %s: %d workers x %d waves; median %.2f ms, max %.2f ms per wave\n",
        $label, count($clients), P3B_WAVES, ($times[3] + $times[4]) / 2, max($times));
}

$clients = [];
$exitCode = 0;
try {
    date_default_timezone_set('UTC');
    $root = realpath($argv[1] ?? dirname(__DIR__));
    p3bAssert($root !== false && is_file($root . '/.fixie-e2e-environment.json'), 'A marked E2E project is required. Main project refused.');
    $marker = json_decode((string) file_get_contents($root . '/.fixie-e2e-environment.json'), true, 32, JSON_THROW_ON_ERROR);
    p3bAssert(is_array($marker) && ($marker['format'] ?? '') === 'FIXIE_DRMS_ISOLATED_E2E'
        && realpath((string) ($marker['test_project'] ?? '')) === $root
        && realpath((string) ($marker['source_project'] ?? '')) !== $root
        && stripos((string) ($marker['test_database'] ?? ''), 'e2e') !== false
        && ($marker['test_database'] ?? '') !== ($marker['source_database'] ?? ''), 'Invalid E2E isolation marker.');
    require_once $root . '/config/runtime.php';
    $config = drms_runtime_section('database');
    p3bAssert((string) $config['name'] === $marker['test_database'], 'Configured database does not match the E2E marker.');
    p3bAssert(defined('MYSQLI_ASYNC'), 'PHP mysqli async support is required.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    echo "Fixie DRMS Phase 3B: 10,000-record query validation\nProject: {$root}\n";
    for ($worker = 0; $worker < P3B_WORKERS; $worker++) {
        $db = new mysqli((string) $config['host'], (string) $config['user'], (string) $config['password'],
            (string) $config['name'], (int) $config['port']);
        $clients[] = $db;
        $db->set_charset('utf8mb4');
        $db->query("SET time_zone = '+00:00'");
        if ($worker === 0) {
            $beforeCount = p3bCount($db);
            $result = $db->query('SELECT COALESCE(MAX(doc_id), 0) AS last_id FROM documents');
            $baseId = (int) $result->fetch_assoc()['last_id'] + 10000;
            $result->free();
            p3bAssert($baseId + P3B_ROWS < 2147483647, 'Insufficient synthetic document ID range.');
            $fixtures = p3bFixture($baseId);
        }
        p3bSeed($db, $fixtures);
        echo 'READY Temporary fixture for connection ' . ($worker + 1) . PHP_EOL;
    }
    echo "Only temporary copies receive Phase 2D indexes when missing. Permanent E2E indexes are unchanged.\n\n";
    foreach (['company', 'official'] as $module) {
        foreach (['date_desc', 'date_asc', 'name_asc', 'name_desc'] as $sort) {
            p3bCheck($clients[0], $fixtures, ucfirst($module) . ' ' . $sort,
                p3bSpec($module, $sort), $sort === 'date_desc');
        }
        foreach (['Archived', 'Recycled'] as $status) {
            if ($module === 'official' && $status === 'Recycled') continue;
            p3bCheck($clients[0], $fixtures, ucfirst($module) . ' ' . $status, p3bSpec($module, 'date_desc', $status));
        }
        foreach (['0', 'Folder 02', 'no-such-fixture', "' OR 1=1 --"] as $search) {
            p3bCheck($clients[0], $fixtures, ucfirst($module) . ' search ' . json_encode($search), p3bSpec($module, 'date_desc', 'Active', $search));
        }
        p3bCheck($clients[0], $fixtures, ucfirst($module) . ' folder filter',
            p3bSpec($module, 'date_desc', 'Active', '', $module === 'company' ? 'Folder 02' : 'Folder 00'));
    }
    p3bCheck($clients[0], $fixtures, 'Official source-reference search', p3bSpec('official', 'date_desc', 'Active', 'REF-009992'));
    foreach (['company', 'official'] as $module) {
        $spec = p3bSpec($module);
        p3bConcurrent($clients, $spec, p3bExpected($fixtures, $spec), ucfirst($module) . ' concurrent reads');
    }
    p3bAssert(p3bCount($clients[0]) === $beforeCount, 'Permanent E2E document count changed during this test; inspect external activity.');
    echo "PASS  Permanent E2E document count unchanged: {$beforeCount}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 3B FAILED: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    foreach ($clients as $client) {
        try {
            $client->query('DROP TEMPORARY TABLE IF EXISTS `' . P3B_TABLE . '`');
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Temporary-table cleanup: ' . $cleanupError->getMessage() . PHP_EOL);
            $exitCode = 1;
        } finally {
            // Closing the connection also removes any remaining temporary table,
            // including when a failed asynchronous request prevented DROP.
            try { $client->close(); } catch (Throwable $closeError) {
                fwrite(STDERR, 'Connection cleanup: ' . $closeError->getMessage() . PHP_EOL);
                $exitCode = 1;
            }
        }
    }
}
if ($exitCode === 0) {
    echo "\nPhase 3B synthetic query validation PASSED. Temporary fixtures removed.\n";
    echo "Scope: modeled list SQL with synthetic metadata; no real document files or authenticated HTTP requests.\n";
    echo "Timings exclude fixture creation and browser rendering. This does not certify RBAC, simultaneous approvals, or hosting capacity.\n";
}
exit($exitCode);
