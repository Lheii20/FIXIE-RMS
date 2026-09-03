<?php
declare(strict_types=1);
require_once __DIR__ . '/physical_storage_schema.php';

class DrmsStorageError extends RuntimeException {}

function drms_storage_types(): array
{
    return [
        'building' => ['table'=>'virt_buildings', 'label'=>'Office / site', 'parents'=>[], 'limit'=>100],
        'room' => ['table'=>'virt_rooms', 'label'=>'Room', 'parents'=>['building'=>'building_id'], 'limit'=>100],
        'cabinet' => ['table'=>'virt_cabinets', 'label'=>'Cabinet', 'parents'=>['room'=>'room_id'], 'limit'=>100],
        'drawer' => ['table'=>'virt_drawers', 'label'=>'Drawer', 'parents'=>['cabinet'=>'cabinet_id'], 'limit'=>100],
        'box' => ['table'=>'virt_storage_boxes', 'label'=>'Box', 'parents'=>['room'=>'room_id','drawer'=>'drawer_id'], 'limit'=>120],
        'folder' => ['table'=>'virt_physical_folders', 'label'=>'Physical folder', 'parents'=>['drawer'=>'drawer_id','box'=>'box_id'], 'limit'=>120],
    ];
}

function drms_storage_authorize(mysqli $conn, int $userId, bool $lock = false): void
{
    $rows = drms_vc1_select($conn, "SELECT user_id FROM users WHERE user_id=? AND status='Active' AND role<>'Admin'" . ($lock ? ' FOR UPDATE' : ''), [(string)$userId]);
    $permissions = $rows ? drms_vc1_select($conn, "SELECT permission_name FROM user_permissions WHERE user_id=? AND permission_name IN ('can_manage_folders','can_view_all_folders')" . ($lock ? ' FOR UPDATE' : ''), [(string)$userId]) : [];
    if (!$permissions) { throw new DrmsStorageError('You do not have permission to manage physical storage.', 403); }
}

function drms_storage_csrf($token, $expected): void
{
    if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        throw new DrmsStorageError('Your session security token has expired. Refresh the page and try again.', 403);
    }
}

function drms_storage_ready(mysqli $conn): void
{
    $report = drms_vc1_inspect($conn);
    if ($report['issues'] || $report['steps']) {
        throw new DrmsStorageError('The physical storage foundation is incomplete. Run the VC1 verifier before managing locations.', 503);
    }
    $audit = drms_vc1_select($conn, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs'");
    if (!$audit || $audit[0]['ENGINE'] !== 'InnoDB') {
        throw new DrmsStorageError('Transactional audit logging must be available before managing locations.', 503);
    }
    $columns=drms_vc1_select($conn,"SELECT COLUMN_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs'");
    $auditColumns=array_column($columns,'EXTRA','COLUMN_NAME');
    foreach(['log_id','user_id','action_type','description','old_payload','new_payload','ip_address'] as $column) {
        if(!array_key_exists($column,$auditColumns)) { throw new DrmsStorageError('The audit schema must be completed before managing locations.',503); }
    }
    if(stripos($auditColumns['log_id'],'auto_increment')===false) { throw new DrmsStorageError('Audit IDs must be generated safely before managing locations.',503); }
}

function drms_storage_key(string $value): array
{
    if (!preg_match('/^(building|room|cabinet|drawer|box|folder):([1-9][0-9]{0,9})$/D', $value, $matches) || (int)$matches[2] > 2147483647) {
        throw new DrmsStorageError('Select a valid storage location.', 422);
    }
    return [$matches[1], (int)$matches[2]];
}

function drms_storage_snapshot(mysqli $conn): array
{
    $nodes = [];
    foreach (drms_storage_types() as $type => $spec) {
        $rows = $conn->query('SELECT * FROM `' . $spec['table'] . '` ORDER BY name, id LIMIT 5001')->fetch_all(MYSQLI_ASSOC);
        if (count($rows) > 5000) { throw new DrmsStorageError('This location directory needs server-side paging before it can be managed here.', 503); }
        foreach ($rows as $row) {
            $parent = '';
            foreach ($spec['parents'] as $parentType => $column) {
                if (!empty($row[$column])) { $parent = $parentType . ':' . $row[$column]; }
            }
            $key = $type . ':' . $row['id'];
            $data = ['key'=>$key, 'type'=>$type, 'id'=>(int)$row['id'], 'name'=>$row['name'], 'code'=>$row['code'] ?? '', 'parent'=>$parent, 'active'=>!isset($row['is_active']) || (int)$row['is_active'] === 1];
            $data['revision'] = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $nodes[$key] = $data + ['children'=>0, 'references'=>0];
        }
    }
    foreach ($nodes as $node) {
        if (isset($nodes[$node['parent']])) { $nodes[$node['parent']]['children']++; }
    }
    // Include legacy digital-folder mappings, even where no documents are currently visible.
    foreach ($conn->query('SELECT drawer_id AS id, COUNT(*) AS n FROM document_categories WHERE drawer_id IS NOT NULL GROUP BY drawer_id')->fetch_all(MYSQLI_ASSOC) as $count) {
        if (isset($nodes['drawer:' . $count['id']])) { $nodes['drawer:' . $count['id']]['references'] = (int)$count['n']; }
    }
    foreach ($conn->query('SELECT physical_folder_id AS id, COUNT(*) AS n FROM virt_document_locations WHERE physical_folder_id IS NOT NULL GROUP BY physical_folder_id')->fetch_all(MYSQLI_ASSOC) as $count) {
        if (isset($nodes['folder:' . $count['id']])) { $nodes['folder:' . $count['id']]['references'] = (int)$count['n']; }
    }
    foreach ($nodes as $key => $node) {
        $parts = [];
        $cursor = $key;
        $seen = [];
        $available = true;
        while ($cursor !== '') {
            if (!isset($nodes[$cursor]) || isset($seen[$cursor])) { $available=false; array_unshift($parts, '[Missing parent]'); break; }
            $seen[$cursor] = true;
            array_unshift($parts, $nodes[$cursor]['name']);
            $available = $available && $nodes[$cursor]['active'];
            $cursor = $nodes[$cursor]['parent'];
        }
        $nodes[$key]['path'] = implode(' / ', $parts);
        $nodes[$key]['available'] = $available;
        $nodes[$key]['in_use'] = $node['children'] > 0 || $node['references'] > 0;
    }
    return $nodes;
}

function drms_storage_text(array $input, string $field, int $limit, bool $required = true): string
{
    $value = $input[$field] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) { throw new DrmsStorageError('Invalid ' . $field . '.', 422); }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $limit || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new DrmsStorageError(ucfirst($field) . ' must contain ' . ($required ? '1' : '0') . '–' . $limit . ' characters without control characters.', 422);
    }
    return $value;
}

function drms_storage_write(mysqli $conn, string $sql, array $parameters): void
{
    $stmt = $conn->prepare($sql);
    if ($parameters) { $stmt->bind_param(str_repeat('s', count($parameters)), ...$parameters); }
    $stmt->execute();
    $stmt->close();
}

/** Caller supplies the authenticated session's user ID, never a posted actor ID. */
function drms_storage_mutate(mysqli $conn, int $userId, array $input, string $ip = ''): array
{
    drms_storage_authorize($conn, $userId);
    drms_storage_ready($conn);
    $action = drms_storage_text($input, 'action', 10);
    if (!in_array($action, ['create','update','delete'], true)) { throw new DrmsStorageError('Unknown storage action.', 422); }
    $types = drms_storage_types();
    $type = drms_storage_text($input, 'type', 12);
    if (!isset($types[$type])) { throw new DrmsStorageError('Unknown location type.', 422); }
    $spec = $types[$type];
    $key = $action === 'create' ? '' : drms_storage_text($input, 'key', 40);
    $id = 0;
    if ($key !== '') {
        [$keyType,$id] = drms_storage_key($key);
        if ($keyType !== $type) { throw new DrmsStorageError('Location type does not match.', 422); }
    }
    $reason = drms_storage_text($input, 'reason', 500, $action !== 'create');
    $locked = (int)$conn->query("SELECT GET_LOCK('fixie_drms:storage-management', 5)")->fetch_row()[0] === 1;
    if (!$locked) { throw new DrmsStorageError('Another location change is in progress. Please try again.', 409); }
    try {
        $conn->begin_transaction();
        // Lock the edited/deleted parent before counting dependants. Foreign-key writers then wait.
        if ($id > 0) { drms_vc1_select($conn, 'SELECT id FROM `' . $spec['table'] . '` WHERE id=? FOR UPDATE', [(string)$id]); }
        $parent = $action === 'delete' ? '' : drms_storage_text($input, 'parent', 40, !empty($spec['parents']));
        if ($parent !== '') {
            [$parentType,$parentId] = drms_storage_key($parent);
            if (!isset($spec['parents'][$parentType])) { throw new DrmsStorageError('That parent cannot contain this location type.', 422); }
            drms_vc1_select($conn, 'SELECT id FROM `' . $types[$parentType]['table'] . '` WHERE id=? FOR UPDATE', [(string)$parentId]);
        }
        // VC5B: revalidate after lock waits and hold grants stable during this change.
        drms_storage_authorize($conn, $userId, true);
        $nodes = drms_storage_snapshot($conn);
        $old = $key !== '' ? ($nodes[$key] ?? null) : null;
        if ($key !== '' && !$old) { throw new DrmsStorageError('This location no longer exists. Refresh the list.', 404); }
        if ($old && !hash_equals($old['revision'], drms_storage_text($input, 'revision', 64))) {
            throw new DrmsStorageError('This location changed while you were editing. Refresh the list before trying again.', 409);
        }
        if ($action === 'delete') {
            if ($old['in_use']) { throw new DrmsStorageError('This location still has child locations, linked folders or physical copies. It cannot be deleted.', 409); }
            drms_storage_write($conn, 'DELETE FROM `' . $spec['table'] . '` WHERE id=?', [(string)$id]);
            $new = null;
        } else {
            if ($parent !== '' && (!isset($nodes[$parent]) || !$nodes[$parent]['available'])) {
                throw new DrmsStorageError('Choose an existing active parent location.', 422);
            }
            $name = drms_storage_text($input, 'name', $spec['limit']);
            $supportsState = in_array($type, ['box','folder'], true);
            $active = '1';
            if ($supportsState) {
                $active = drms_storage_text($input, 'active', 1);
                if (!in_array($active, ['0','1'], true)) { throw new DrmsStorageError('Invalid location status.', 422); }
            }
            if ($old && $old['in_use'] && ($old['parent'] !== $parent || $active === '0')) {
                throw new DrmsStorageError('Only empty, unlinked locations can be moved or made inactive. You can still correct their name.', 409);
            }
            $code = $supportsState ? drms_storage_text($input, 'code', 40, false) : '';
            if ($old && $supportsState && $code !== $old['code']) { throw new DrmsStorageError('The location code is permanent and cannot be changed.', 422); }
            if ($supportsState && !$old && $code === '') { $code = ($type === 'box' ? 'B-' : 'F-') . strtoupper(bin2hex(random_bytes(6))); }
            if ($supportsState && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $code)) { throw new DrmsStorageError('Use letters, numbers, hyphens or underscores for the location code.', 422); }
            $where = 'name=? AND id<>?';
            $checkParams = [$name,(string)$id];
            foreach ($spec['parents'] as $candidateType => $column) {
                $matchesParent = $parent !== '' && $candidateType === $parentType;
                $where .= $matchesParent ? " AND `$column`=?" : " AND `$column` IS NULL";
                if ($matchesParent) { $checkParams[]=(string)$parentId; }
            }
            if (drms_vc1_select($conn, 'SELECT id FROM `' . $spec['table'] . '` WHERE ' . $where . ' LIMIT 1', $checkParams)) {
                throw new DrmsStorageError('That name is already used in this parent location.', 409);
            }
            $values = ['name'=>$name];
            foreach ($spec['parents'] as $candidateType => $column) { $values[$column] = $parent !== '' && $candidateType === $parentType ? (string)$parentId : null; }
            if ($supportsState) { $values['code']=$code; $values['is_active']=$active; }
            if ($action === 'create') {
                drms_storage_write($conn, 'INSERT INTO `' . $spec['table'] . '` (`' . implode('`,`',array_keys($values)) . '`) VALUES (' . implode(',',array_fill(0,count($values),'?')) . ')', array_values($values));
                $id=(int)$conn->insert_id;
                $key=$type . ':' . $id;
            } else {
                $set=implode(',',array_map(static fn($column)=>"`$column`=?",array_keys($values)));
                drms_storage_write($conn, 'UPDATE `' . $spec['table'] . "` SET $set WHERE id=?", array_merge(array_values($values),[(string)$id]));
            }
            $new = drms_storage_snapshot($conn)[$key];
        }
        // Same transaction as the location change: an audit failure must roll everything back.
        $description = ucfirst($action) . ' ' . $spec['label'] . ': ' . ($new['name'] ?? $old['name']);
        $payload = ['location'=>$new, 'reason'=>$reason];
        drms_storage_write($conn, 'INSERT INTO audit_logs(user_id,action_type,description,old_payload,new_payload,ip_address) VALUES(?,?,?,?,?,?)', [
            (string)$userId, strtoupper($action) . '_STORAGE_LOCATION', $description,
            $old ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'UNKNOWN',
        ]);
        $conn->commit();
        return ['message'=>$action === 'delete' ? 'Empty location deleted.' : 'Location saved.', 'key'=>$key];
    } catch (Throwable $error) {
        $conn->rollback();
        if ($error instanceof mysqli_sql_exception && in_array($error->getCode(), [1062,1451,1452,1213,1205], true)) {
            throw new DrmsStorageError('The location is duplicated, in use, or changed by another request. Refresh and try again.', 409);
        }
        throw $error;
    } finally {
        try { $conn->query("SELECT RELEASE_LOCK('fixie_drms:storage-management')"); }
        catch (Throwable $cleanupError) { error_log('Storage advisory-lock cleanup: ' . $cleanupError->getMessage()); }
    }
}
