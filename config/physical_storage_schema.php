<?php
declare(strict_types=1);

/** VC1 schema definitions and read-only inspection. Including this file runs no SQL. */
function drms_vc1_definitions(): array
{
    $common = [
        'id' => ['INT NOT NULL AUTO_INCREMENT', 'int', false, null, 'auto_increment'],
        'code' => ['VARCHAR(40) NOT NULL', 'varchar', false, null, '', 40],
        'name' => ['VARCHAR(120) NOT NULL', 'varchar', false, null, '', 120],
        'is_active' => ['TINYINT UNSIGNED NOT NULL DEFAULT 1', 'tinyint unsigned', false, '1'],
        'created_at' => ['DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', false, 'current_timestamp()'],
        'updated_at' => ['DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'datetime', false, 'current_timestamp()', 'on update current_timestamp()'],
    ];
    return [
        'virt_storage_boxes' => [
            'columns' => $common + [
                'room_id' => ['INT NULL DEFAULT NULL', 'int', true, null],
                'drawer_id' => ['INT NULL DEFAULT NULL', 'int', true, null],
            ],
            'indexes' => [
                'PRIMARY' => [true, ['id']],
                'uq_vc1_box_code' => [true, ['code']],
                'uq_vc1_box_room_name' => [true, ['room_id', 'name']],
                'uq_vc1_box_drawer_name' => [true, ['drawer_id', 'name']],
            ],
            'foreign_keys' => [
                'fk_vc1_box_room' => ['room_id', 'virt_rooms', 'id'],
                'fk_vc1_box_drawer' => ['drawer_id', 'virt_drawers', 'id'],
            ],
            'checks' => [
                'ck_vc1_box_parent' => '(room_id IS NULL) <> (drawer_id IS NULL)',
                'ck_vc1_box_code' => 'CHAR_LENGTH(TRIM(code)) > 0',
                'ck_vc1_box_name' => 'CHAR_LENGTH(TRIM(name)) > 0',
                'ck_vc1_box_active' => 'is_active IN (0, 1)',
            ],
        ],
        'virt_physical_folders' => [
            'columns' => $common + [
                'drawer_id' => ['INT NULL DEFAULT NULL', 'int', true, null],
                'box_id' => ['INT NULL DEFAULT NULL', 'int', true, null],
            ],
            'indexes' => [
                'PRIMARY' => [true, ['id']],
                'uq_vc1_folder_code' => [true, ['code']],
                'uq_vc1_folder_drawer_name' => [true, ['drawer_id', 'name']],
                'uq_vc1_folder_box_name' => [true, ['box_id', 'name']],
            ],
            'foreign_keys' => [
                'fk_vc1_folder_drawer' => ['drawer_id', 'virt_drawers', 'id'],
                'fk_vc1_folder_box' => ['box_id', 'virt_storage_boxes', 'id'],
            ],
            'checks' => [
                'ck_vc1_folder_parent' => '(drawer_id IS NULL) <> (box_id IS NULL)',
                'ck_vc1_folder_code' => 'CHAR_LENGTH(TRIM(code)) > 0',
                'ck_vc1_folder_name' => 'CHAR_LENGTH(TRIM(name)) > 0',
                'ck_vc1_folder_active' => 'is_active IN (0, 1)',
            ],
        ],
    ];
}

function drms_vc1_select(mysqli $conn, string $sql, array $parameters = []): array
{
    $statement = $conn->prepare($sql);
    if ($parameters) {
        $statement->bind_param(str_repeat('s', count($parameters)), ...$parameters);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function drms_vc1_normalize(string $value): string
{
    return strtolower((string) preg_replace('/[\s`()]+/', '', $value));
}

function drms_vc1_column_matches(array $actual, array $expected): bool
{
    $actualType = strtolower((string) preg_replace('/\(\d+\)/', '', $actual['COLUMN_TYPE']));
    $default = $actual['COLUMN_DEFAULT'];
    if ($default === 'NULL') {
        $default = null;
    }
    $expectedDefault = $expected[3];
    $defaultMatches = ($default === null && $expectedDefault === null)
        || ($default !== null && $expectedDefault !== null
            && drms_vc1_normalize((string) $default) === drms_vc1_normalize((string) $expectedDefault));
    return $actualType === $expected[1]
        && ($actual['IS_NULLABLE'] === 'YES') === $expected[2]
        && $defaultMatches
        && drms_vc1_normalize((string) $actual['EXTRA']) === drms_vc1_normalize($expected[4] ?? '')
        && (!isset($expected[5]) || (int) $actual['CHARACTER_MAXIMUM_LENGTH'] === $expected[5]);
}

function drms_vc1_table_metadata(mysqli $conn, string $database, string $table): ?array
{
    $tables = drms_vc1_select($conn, 'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $table]);
    if (!$tables) {
        return null;
    }
    $columns = [];
    foreach (drms_vc1_select($conn, 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $table]) as $column) {
        $columns[$column['COLUMN_NAME']] = $column;
    }
    $indexes = [];
    foreach (drms_vc1_select($conn, 'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$database, $table]) as $index) {
        $key = $index['INDEX_NAME'];
        $indexes[$key]['unique'] = (int) $index['NON_UNIQUE'] === 0;
        $indexes[$key]['columns'][] = $index['COLUMN_NAME'];
        $indexes[$key]['prefix'] = ($indexes[$key]['prefix'] ?? false) || $index['SUB_PART'] !== null;
    }
    $foreignKeys = [];
    $sql = 'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.ORDINAL_POSITION';
    foreach (drms_vc1_select($conn, $sql, [$database, $table]) as $foreignKey) {
        $foreignKeys[$foreignKey['CONSTRAINT_NAME']][] = $foreignKey;
    }
    $checks = [];
    foreach (drms_vc1_select($conn, 'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?', [$database, $table]) as $check) {
        $checks[$check['CONSTRAINT_NAME']] = $check['CHECK_CLAUSE'];
    }
    return ['engine' => $tables[0]['ENGINE'], 'collation' => $tables[0]['TABLE_COLLATION'], 'columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys, 'checks' => $checks];
}

function drms_vc1_index_matches(array $actual, array $expected): bool
{
    return $actual['unique'] === $expected[0] && $actual['columns'] === $expected[1] && !$actual['prefix'];
}

function drms_vc1_fk_matches(array $actual, array $expected, string $database): bool
{
    return count($actual) === 1
        && $actual[0]['COLUMN_NAME'] === $expected[0]
        && $actual[0]['REFERENCED_TABLE_SCHEMA'] === $database
        && $actual[0]['REFERENCED_TABLE_NAME'] === $expected[1]
        && $actual[0]['REFERENCED_COLUMN_NAME'] === $expected[2]
        && $actual[0]['DELETE_RULE'] === 'RESTRICT' && $actual[0]['UPDATE_RULE'] === 'RESTRICT';
}

function drms_vc1_create_table_sql(string $table, array $definition): string
{
    $parts = [];
    foreach ($definition['columns'] as $name => $column) {
        $parts[] = "`$name` " . $column[0];
    }
    foreach ($definition['indexes'] as $name => $index) {
        $keys = '`' . implode('`, `', $index[1]) . '`';
        $parts[] = $name === 'PRIMARY' ? "PRIMARY KEY ($keys)" : ($index[0] ? 'UNIQUE ' : '') . "KEY `$name` ($keys)";
    }
    foreach ($definition['foreign_keys'] as $name => $fk) {
        $parts[] = "CONSTRAINT `$name` FOREIGN KEY (`{$fk[0]}`) REFERENCES `{$fk[1]}` (`{$fk[2]}`) ON DELETE RESTRICT ON UPDATE RESTRICT";
    }
    foreach ($definition['checks'] as $name => $clause) {
        $parts[] = "CONSTRAINT `$name` CHECK ($clause)";
    }
    return "CREATE TABLE `$table` (\n    " . implode(",\n    ", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

/** Returns a migration plan; never executes DDL or changes any record. */
function drms_vc1_inspect(mysqli $conn): array
{
    $server = $conn->query('SELECT DATABASE() AS db, VERSION() AS version')->fetch_assoc();
    $database = (string) $server['db'];
    $issues = [];
    $steps = [];
    if ($database !== 'fixie_drms') {
        $issues[] = 'Wrong target database. VC1 only permits fixie_drms, never information_schema or another database.';
    }
    $version = (string) $server['version'];
    if (stripos($version, 'MariaDB') === false || !preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $match) || version_compare($match[1], '10.4.0', '<')) {
        $issues[] = 'This migration requires MariaDB 10.4 or newer with enforced CHECK constraints. Other engines require a separately tested migration.';
    }
    if ($issues) {
        return compact('database', 'version', 'issues', 'steps');
    }
    $settings = $conn->query('SELECT @@foreign_key_checks AS fk_checks, @@check_constraint_checks AS check_checks')->fetch_assoc();
    if ((int) $settings['fk_checks'] !== 1 || (int) $settings['check_checks'] !== 1) {
        $issues[] = 'Foreign-key and CHECK constraint enforcement must both be enabled.';
    }
    $baseline = [
        'virt_buildings' => ['id'], 'virt_rooms' => ['id', 'building_id'],
        'virt_cabinets' => ['id', 'room_id'], 'virt_drawers' => ['id', 'cabinet_id'],
        'virt_document_locations' => ['id', 'document_id'],
    ];
    $metadata = [];
    foreach ($baseline as $table => $required) {
        $metadata[$table] = drms_vc1_table_metadata($conn, $database, $table);
        $actual = $metadata[$table];
        if (!$actual || $actual['engine'] !== 'InnoDB') {
            $issues[] = "Required InnoDB table missing or incompatible: $table. No baseline table will be recreated.";
            continue;
        }
        foreach ($required as $column) {
            $definition = $actual['columns'][$column] ?? null;
            if (!$definition || strtolower((string) preg_replace('/\(\d+\)/', '', $definition['COLUMN_TYPE'])) !== 'int' || $definition['IS_NULLABLE'] !== 'NO') {
                $issues[] = "Expected signed, non-null INT: $table.$column.";
            }
        }
        if (!isset($actual['indexes']['PRIMARY']) || !drms_vc1_index_matches($actual['indexes']['PRIMARY'], [true, ['id']])) {
            $issues[] = "Expected single-column primary key: $table.id.";
        }
    }
    if ($issues) {
        return compact('database', 'version', 'issues', 'steps');
    }
    foreach (drms_vc1_definitions() as $table => $definition) {
        $actual = drms_vc1_table_metadata($conn, $database, $table);
        if (!$actual) {
            $steps[] = ['label' => "Create $table", 'sql' => drms_vc1_create_table_sql($table, $definition)];
            continue;
        }
        if ($actual['engine'] !== 'InnoDB' || $actual['collation'] !== 'utf8mb4_general_ci') {
            $issues[] = "Incompatible storage engine/collation: $table.";
        }
        foreach ($definition['columns'] as $name => $expected) {
            if (!isset($actual['columns'][$name]) || !drms_vc1_column_matches($actual['columns'][$name], $expected)) {
                $issues[] = "Existing column differs from VC1: $table.$name. No automatic repair will run.";
            }
        }
        foreach ($definition['indexes'] as $name => $expected) {
            if (!isset($actual['indexes'][$name]) || !drms_vc1_index_matches($actual['indexes'][$name], $expected)) {
                $issues[] = "Existing index differs from VC1: $table.$name.";
            }
        }
        foreach ($definition['foreign_keys'] as $name => $expected) {
            if (!isset($actual['foreign_keys'][$name]) || !drms_vc1_fk_matches($actual['foreign_keys'][$name], $expected, $database)) {
                $issues[] = "Existing foreign key differs from VC1: $table.$name.";
            }
        }
        foreach ($definition['checks'] as $name => $expected) {
            if (!isset($actual['checks'][$name]) || drms_vc1_normalize($actual['checks'][$name]) !== drms_vc1_normalize($expected)) {
                $issues[] = "Existing CHECK constraint differs from VC1: $table.$name.";
            }
        }
    }
    $locations = $metadata['virt_document_locations'];
    $column = 'physical_folder_id';
    if (!isset($locations['columns'][$column])) {
        $steps[] = ['label' => 'Add optional physical_folder_id (existing rows stay unassigned)', 'sql' => 'ALTER TABLE `virt_document_locations` ADD COLUMN `physical_folder_id` INT NULL DEFAULT NULL'];
    } elseif (!drms_vc1_column_matches($locations['columns'][$column], ['INT NULL DEFAULT NULL', 'int', true, null])) {
        $issues[] = 'Existing physical_folder_id must be a nullable signed INT with NULL default.';
    }
    $index = 'idx_vc1_location_folder';
    if (!isset($locations['indexes'][$index])) {
        $steps[] = ['label' => 'Index the physical-folder link', 'sql' => "ALTER TABLE `virt_document_locations` ADD INDEX `$index` (`physical_folder_id`)"];
    } elseif (!drms_vc1_index_matches($locations['indexes'][$index], [false, ['physical_folder_id']])) {
        $issues[] = "Existing $index has an incompatible definition.";
    }
    $fk = 'fk_vc1_location_folder';
    if (!isset($locations['foreign_keys'][$fk])) {
        $steps[] = ['label' => 'Protect assigned physical folders from deletion', 'sql' => "ALTER TABLE `virt_document_locations` ADD CONSTRAINT `$fk` FOREIGN KEY (`physical_folder_id`) REFERENCES `virt_physical_folders` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT"];
    } elseif (!drms_vc1_fk_matches($locations['foreign_keys'][$fk], [$column, 'virt_physical_folders', 'id'], $database)) {
        $issues[] = "Existing $fk has an incompatible definition.";
    }
    // Refuse a partially/manual installation with orphan assignments before doing any DDL.
    if (!$issues && isset($locations['columns'][$column]) && !isset($locations['foreign_keys'][$fk])) {
        $hasFolders = drms_vc1_table_metadata($conn, $database, 'virt_physical_folders') !== null;
        $sql = $hasFolders
            ? 'SELECT COUNT(*) AS n FROM virt_document_locations l LEFT JOIN virt_physical_folders f ON f.id = l.physical_folder_id WHERE l.physical_folder_id IS NOT NULL AND f.id IS NULL'
            : 'SELECT COUNT(*) AS n FROM virt_document_locations WHERE physical_folder_id IS NOT NULL';
        if ((int) $conn->query($sql)->fetch_assoc()['n'] > 0) {
            $issues[] = 'Orphan physical-folder assignments exist. No records will be changed automatically.';
        }
    }
    return compact('database', 'version', 'issues', 'steps');
}

function drms_vc1_sync_guard_present(string $root): bool
{
    $path = $root . DIRECTORY_SEPARATOR . 'sync_cabinets.php';
    if (!is_file($path)) {
        return false;
    }
    $source = file_get_contents($path);
    return $source !== false
        && strpos($source, 'DRMS_VC1_LEGACY_SYNC_DISABLED') !== false
        && strpos($source, 'PHP_SAPI') !== false
        && !preg_match('/\b(?:require|include|mysqli|truncate|query|exec|eval)\b/i', $source);
}
