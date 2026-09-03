<?php
declare(strict_types=1);
require_once __DIR__ . '/storage_locations.php';

/** VC3: physical filing never changes digital classification or signed files. */
function drms_copy_schema(mysqli $conn): array
{
    drms_storage_ready($conn);
    $steps=[];
    foreach (['documents','virt_document_locations','physical_borrowing_logs','physical_movement_logs'] as $table) {
        $engine=drms_vc1_select($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (!$engine || $engine[0]['ENGINE']!=='InnoDB') { throw new DrmsStorageError('Required transactional table is unavailable: '.$table,503); }
    }
    $duplicates=$conn->query('SELECT document_id FROM virt_document_locations GROUP BY document_id HAVING COUNT(*)>1 LIMIT 1')->fetch_assoc();
    if ($duplicates) { throw new DrmsStorageError('Multiple physical-copy rows exist for document '.$duplicates['document_id'].'. Resolve this explicitly before VC3; nothing was merged or deleted.',503); }
    $indexes=$conn->query('SHOW INDEX FROM virt_document_locations')->fetch_all(MYSQLI_ASSOC);
    $groups=[];
    foreach ($indexes as $index) { $groups[$index['Key_name']][]=$index; }
    $unique=false;
    foreach ($groups as $index) { if(count($index)===1 && (int)$index[0]['Non_unique']===0 && $index[0]['Column_name']==='document_id') $unique=true; }
    if (!$unique) {
        if (isset($groups['uq_vc3_document_copy'])) throw new DrmsStorageError('Conflicting uq_vc3_document_copy index; no automatic replacement.',503);
        $steps[]='ALTER TABLE virt_document_locations ADD UNIQUE KEY uq_vc3_document_copy (document_id)';
    }
    $columns=drms_vc1_select($conn,"SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='physical_movement_logs'");
    $types=array_column($columns,'DATA_TYPE','COLUMN_NAME');
    foreach (['previous_path','new_path'] as $column) {
        if (!isset($types[$column])) throw new DrmsStorageError('Movement history schema is incomplete.',503);
        if ($types[$column]==='varchar') $steps[]='ALTER TABLE physical_movement_logs MODIFY '.$column.' TEXT NOT NULL';
        elseif (!in_array($types[$column],['text','mediumtext','longtext'],true)) throw new DrmsStorageError('Unsupported movement path column type.',503);
    }
    return $steps;
}

function drms_copy_ready(mysqli $conn): void
{
    if (drms_copy_schema($conn)) throw new DrmsStorageError('Install and verify the VC3 schema before using physical filing.',503);
}

/** VC4B2 adds immutable evidence for a separately confirmed paper-copy disposal. */
function drms_copy_disposal_schema(mysqli $conn): array
{
    drms_copy_ready($conn);
    $database=(string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
    $table=drms_vc1_table_metadata($conn,$database,'physical_disposition_logs');
    if(!$table) {
        return ["CREATE TABLE `physical_disposition_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `document_id` INT NOT NULL,
            `evidence_number` VARCHAR(50) NOT NULL,
            `record_number` VARCHAR(50) NOT NULL,
            `source_folder_id` INT DEFAULT NULL,
            `source_path` TEXT NOT NULL,
            `physical_version` VARCHAR(10) NOT NULL,
            `copy_status` VARCHAR(20) NOT NULL,
            `disposal_method` VARCHAR(100) NOT NULL,
            `reason` TEXT NOT NULL,
            `digital_certificate_number` VARCHAR(50) NOT NULL,
            `digital_certificate_hash` CHAR(64) NOT NULL,
            `disposed_by` INT NOT NULL,
            `disposed_by_name` VARCHAR(255) NOT NULL,
            `disposed_at` DATETIME NOT NULL,
            `evidence_hash` CHAR(64) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_vc4b2_document` (`document_id`),
            UNIQUE KEY `uq_vc4b2_evidence_number` (`evidence_number`),
            UNIQUE KEY `uq_vc4b2_evidence_hash` (`evidence_hash`),
            KEY `idx_vc4b2_disposed_at` (`disposed_at`),
            CONSTRAINT `fk_vc4b2_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`doc_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"];
    }
    if($table['engine']!=='InnoDB' || $table['collation']!=='utf8mb4_general_ci') throw new DrmsStorageError('The physical-disposal evidence table must use InnoDB and utf8mb4_general_ci. No automatic conversion was attempted.',503);
    $expected=[
        'id'=>['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT','bigint unsigned',false,null,'auto_increment'],
        'document_id'=>['INT NOT NULL','int',false,null],
        'evidence_number'=>['VARCHAR(50) NOT NULL','varchar',false,null,'',50],
        'record_number'=>['VARCHAR(50) NOT NULL','varchar',false,null,'',50],
        'source_folder_id'=>['INT DEFAULT NULL','int',true,null],
        'source_path'=>['TEXT NOT NULL','text',false,null],
        'physical_version'=>['VARCHAR(10) NOT NULL','varchar',false,null,'',10],
        'copy_status'=>['VARCHAR(20) NOT NULL','varchar',false,null,'',20],
        'disposal_method'=>['VARCHAR(100) NOT NULL','varchar',false,null,'',100],
        'reason'=>['TEXT NOT NULL','text',false,null],
        'digital_certificate_number'=>['VARCHAR(50) NOT NULL','varchar',false,null,'',50],
        'digital_certificate_hash'=>['CHAR(64) NOT NULL','char',false,null,'',64],
        'disposed_by'=>['INT NOT NULL','int',false,null],
        'disposed_by_name'=>['VARCHAR(255) NOT NULL','varchar',false,null,'',255],
        'disposed_at'=>['DATETIME NOT NULL','datetime',false,null],
        'evidence_hash'=>['CHAR(64) NOT NULL','char',false,null,'',64],
        'created_at'=>['TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','timestamp',false,'current_timestamp()'],
    ];
    foreach($expected as $column=>$definition) {
        $actual=$table['columns'][$column]??null;
        if(!$actual || !drms_vc1_column_matches($actual,$definition)) throw new DrmsStorageError('The existing physical-disposal evidence table is incompatible at '.$column.'. Nothing was altered.',503);
    }
    foreach([
        'PRIMARY'=>[true,['id']],
        'uq_vc4b2_document'=>[true,['document_id']],
        'uq_vc4b2_evidence_number'=>[true,['evidence_number']],
        'uq_vc4b2_evidence_hash'=>[true,['evidence_hash']],
        'idx_vc4b2_disposed_at'=>[false,['disposed_at']],
    ] as $name=>$expectedIndex) {
        if(!isset($table['indexes'][$name]) || !drms_vc1_index_matches($table['indexes'][$name],$expectedIndex)) throw new DrmsStorageError('The physical-disposal evidence index '.$name.' is missing or incompatible. Nothing was altered.',503);
    }
    if(!isset($table['foreign_keys']['fk_vc4b2_document']) || !drms_vc1_fk_matches($table['foreign_keys']['fk_vc4b2_document'],['document_id','documents','doc_id'],$database)) {
        throw new DrmsStorageError('The physical-disposal document safeguard is missing or incompatible. Nothing was altered.',503);
    }
    return [];
}

function drms_copy_disposal_ready(mysqli $conn): void
{
    if(drms_copy_disposal_schema($conn)) throw new DrmsStorageError('Install and verify the VC4B2 physical-disposal evidence schema before using this action.',503);
}

function drms_copy_disposal_hash(array $evidence): string
{
    $payload=[];
    foreach(['evidence_number','document_id','record_number','source_folder_id','source_path','physical_version','copy_status','disposal_method','reason','digital_certificate_number','digital_certificate_hash','disposed_by','disposed_by_name','disposed_at'] as $field) {
        $payload[$field]=$evidence[$field]??null;
    }
    return hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

/** VC4C query support: locate the latest custody event without scanning all history rows. */
function drms_copy_custody_schema(mysqli $conn): array
{
    drms_copy_ready($conn);
    $engine=drms_vc1_select($conn,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='physical_borrowing_logs'");
    if(!$engine || $engine[0]['ENGINE']!=='InnoDB') throw new DrmsStorageError('The physical borrowing history must use InnoDB before custody monitoring can be enabled.',503);
    $groups=[];
    foreach($conn->query('SHOW INDEX FROM physical_borrowing_logs')->fetch_all(MYSQLI_ASSOC) as $index)$groups[$index['Key_name']][]=$index;
    if(isset($groups['idx_vc4c_borrow_document'])) {
        $columns=array_column($groups['idx_vc4c_borrow_document'],'Column_name');
        if((int)$groups['idx_vc4c_borrow_document'][0]['Non_unique']!==1 || $columns!==['document_id','id']) throw new DrmsStorageError('A conflicting idx_vc4c_borrow_document index exists. Nothing was replaced.',503);
        return [];
    }
    foreach($groups as $index)if(array_slice(array_column($index,'Column_name'),0,2)===['document_id','id'])return [];
    return ['ALTER TABLE physical_borrowing_logs ADD KEY idx_vc4c_borrow_document (document_id,id)'];
}

function drms_copy_custody_ready(mysqli $conn): void
{
    if(drms_copy_custody_schema($conn)) throw new DrmsStorageError('Install and verify the VC4C custody-monitoring index before using this list.',503);
}

function drms_copy_actor(mysqli $conn,int $id,bool $lock=false): array
{
    $rows=drms_vc1_select($conn,"SELECT user_id,role,full_name FROM users WHERE user_id=? AND status='Active' AND role<>'Admin'".($lock?' FOR UPDATE':''),[(string)$id]);
    if (!$rows) throw new DrmsStorageError('Sign in with an active records account.',403);
    $actor=$rows[0];
    $permissions=drms_vc1_select($conn,'SELECT permission_name FROM user_permissions WHERE user_id=?'.($lock?' FOR UPDATE':''),[(string)$id]);
    $names=array_column($permissions,'permission_name');
    $actor['all']=in_array('can_view_all_folders',$names,true);
    $actor['manage']=$actor['all'] || in_array('can_manage_folders',$names,true);
    return $actor;
}

function drms_copy_access(array $actor): array
{
    // VC4B1: a destroyed digital binary does not prove the registered paper is gone.
    // VC5B: direct shares require the exact permission value, not merely a matching user token.
    return ["(d.status<>'Recycled' AND (COALESCE(d.disposition_status,'')<>'Destroyed'
        OR EXISTS (SELECT 1 FROM virt_document_locations retained_copy WHERE retained_copy.document_id=d.doc_id))
        AND COALESCE(d.record_phase,'')<>'Converted' AND (?=1 OR d.uploaded_by=?
        OR BINARY JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.file_permissions),d.file_permissions,'{}'),?)) IN ('Viewer','Editor')
        OR (d.access_type='Folder Default' AND EXISTS (SELECT 1 FROM document_categories ac
        JOIN category_role_access ar ON ar.category_id=ac.id WHERE ac.sub_category=d.category AND ar.role_name=?))))",
        [$actor['all']?'1':'0',(string)$actor['user_id'],'$."user_'.(int)$actor['user_id'].'"', $actor['role']]];
}

/** Correlated expression for existing list/disposition readers; d is their document alias. */
function drms_copy_path_sql(): string
{
    return "(SELECT CONCAT_WS(' > ',pb.name,pr.name,pc.name,pd.name,bx.name,pf.name)
        FROM virt_document_locations pl JOIN virt_physical_folders pf ON pf.id=pl.physical_folder_id
        LEFT JOIN virt_storage_boxes bx ON bx.id=pf.box_id
        LEFT JOIN virt_drawers pd ON pd.id=COALESCE(pf.drawer_id,bx.drawer_id)
        LEFT JOIN virt_cabinets pc ON pc.id=pd.cabinet_id
        LEFT JOIN virt_rooms pr ON pr.id=COALESCE(bx.room_id,pc.room_id)
        LEFT JOIN virt_buildings pb ON pb.id=pr.building_id
        WHERE pl.document_id=d.doc_id LIMIT 1)";
}

function drms_copy_revision(array $doc): string
{
    return hash('sha256',json_encode(array_intersect_key($doc,array_flip(['doc_id','location_id','physical_folder_id','physical_status','last_updated','current_version','physical_version','borrow_revision','filing_revision','disposition_status','lifecycle_status','record_phase','is_legal_hold'])),JSON_THROW_ON_ERROR));
}

/** VC4A: bind confirmation to the location displayed, including ancestor labels. */
function drms_copy_folder_revision(array $folder): string
{
    return hash('sha256',json_encode([$folder['key'],$folder['revision'],$folder['path'],$folder['available']],JSON_THROW_ON_ERROR));
}

function drms_copy_profile(mysqli $conn,array $actor,int $id): array
{
    [$access,$params]=drms_copy_access($actor);
    $rows=drms_vc1_select($conn,"SELECT d.doc_id,d.file_name,d.record_number,d.category,d.record_phase,d.status AS lifecycle_status,d.is_legal_hold,
        d.current_version,d.physical_version,d.disposition_status,l.id AS location_id,l.physical_folder_id,l.status AS physical_status,l.last_updated,
        u.full_name AS owner_name,(SELECT parent_category FROM document_categories WHERE sub_category=d.category ORDER BY id LIMIT 1) AS parent_category,
        (SELECT MAX(id) FROM physical_borrowing_logs WHERE document_id=d.doc_id) AS borrow_revision,
        (SELECT MAX(id) FROM physical_movement_logs WHERE document_id=d.doc_id) AS filing_revision
        FROM documents d LEFT JOIN virt_document_locations l ON l.document_id=d.doc_id LEFT JOIN users u ON u.user_id=d.uploaded_by
        WHERE d.doc_id=? AND $access",array_merge([(string)$id],$params));
    if (!$rows) throw new DrmsStorageError('Record not found or access is not permitted.',404);
    $doc=$rows[0];
    $doc['digital_destroyed']=$doc['disposition_status']==='Destroyed';
    $doc['revision']=drms_copy_revision($doc);
    $nodes=drms_storage_snapshot($conn);
    $folder=$doc['physical_folder_id'] ? ($nodes['folder:'.$doc['physical_folder_id']] ?? null) : null;
    $doc['full_physical_path']=$folder['path'] ?? '';
    $doc['folder_revision']=$folder?drms_copy_folder_revision($folder):'';
    $doc['filing_state']=!$doc['location_id']?'No registered physical copy':($folder?'Assigned':'Unassigned');
    $doc['sync_status']=!$doc['location_id']?'No registered physical copy':(!$folder?'Location confirmation needed':
        ($doc['physical_status']==='Borrowed'?'Borrowed':((float)$doc['current_version']>(float)$doc['physical_version']?'Replacement Required':'Up To Date')));
    $doc['physical_disposal_eligible']=$actor['manage'] && $doc['digital_destroyed'] && $doc['record_phase']==='Official'
        && !(bool)$doc['is_legal_hold'] && $doc['location_id'] && $folder && in_array($doc['physical_status'],['Stored','Returned'],true);
    // VC5B: insertion order matches list/export and cannot revive an older holder after clock corrections.
    $borrow=drms_vc1_select($conn,'SELECT l.action_type,l.current_holder_name,l.expected_return_date,l.remarks,l.action_date,u.full_name AS recorded_by FROM physical_borrowing_logs l LEFT JOIN users u ON u.user_id=l.user_id WHERE l.document_id=? ORDER BY l.id DESC LIMIT 20',[(string)$id]);
    $moves=drms_vc1_select($conn,'SELECT l.previous_path,l.new_path,l.reason,l.moved_at,u.full_name AS moved_by_name FROM physical_movement_logs l LEFT JOIN users u ON u.user_id=l.moved_by WHERE l.document_id=? ORDER BY l.moved_at DESC,l.id DESC LIMIT 20',[(string)$id]);
    $folders=[];
    if ($actor['manage']) foreach($nodes as $node) if($node['type']==='folder' && $node['available']) $folders[]=['id'=>$node['id'],'code'=>$node['code'],'path'=>$node['path'],'revision'=>drms_copy_folder_revision($node)];
    $users=$actor['manage']?drms_vc1_select($conn,"SELECT user_id,full_name FROM users WHERE status='Active' ORDER BY full_name,user_id"):[];
    return ['document'=>$doc,'can_manage'=>$actor['manage'],'folders'=>$folders,'borrow_history'=>$borrow,'movement_history'=>$moves,'holders'=>$users];
}

/**
 * VC4D: one validated query definition is shared by the on-screen register and
 * its export. This prevents an export from silently using broader access or
 * different filter semantics than the Virtual Cabinet list.
 */
function drms_copy_filter_query(array $actor,array $input): array
{
    [$where,$params]=drms_copy_access($actor);
    $scope=drms_storage_text($input,'scope',30,false);
    if($scope==='')$scope='all';
    $custody=drms_storage_text($input,'custody',20,false);
    if($custody==='')$custody='all';
    if(!in_array($custody,['all','borrowed','overdue','due_soon','no_due_date'],true)) throw new DrmsStorageError('Select a valid custody filter.',422);
    $search=drms_storage_text($input,'query',150,false);
    if($scope==='unassigned') $where.=' AND l.physical_folder_id IS NULL';
    elseif($scope!=='all') {
        [$type,$id]=drms_storage_key($scope);
        if($type!=='folder') throw new DrmsStorageError('Select a physical folder.',422);
        $where.=' AND l.physical_folder_id=?';$params[]=(string)$id;
    }
    if($custody==='borrowed')$where.=" AND l.status='Borrowed'";
    elseif($custody==='overdue')$where.=" AND l.status='Borrowed' AND bl.expected_return_date<CURRENT_DATE";
    elseif($custody==='due_soon')$where.=" AND l.status='Borrowed' AND bl.expected_return_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE,INTERVAL 3 DAY)";
    elseif($custody==='no_due_date')$where.=" AND l.status='Borrowed' AND bl.expected_return_date IS NULL";
    if($search!=='') {
        // Literal search, including % and _; user input is never a SQL wildcard.
        $like='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $where.=" AND (d.file_name LIKE ? ESCAPE '!' OR d.record_number LIKE ? ESCAPE '!' OR d.category LIKE ? ESCAPE '!' OR (l.status='Borrowed' AND bl.current_holder_name LIKE ? ESCAPE '!'))";
        array_push($params,$like,$like,$like,$like);
    }
    $from=' FROM documents d JOIN virt_document_locations l ON l.document_id=d.doc_id
        LEFT JOIN physical_borrowing_logs bl ON bl.id=(SELECT MAX(latest_borrow.id) FROM physical_borrowing_logs latest_borrow WHERE latest_borrow.document_id=d.doc_id)
        WHERE '.$where;
    $order=$custody==='all'?'l.last_updated DESC,d.doc_id DESC':'CASE WHEN bl.expected_return_date IS NULL THEN 1 ELSE 0 END,bl.expected_return_date ASC,l.last_updated DESC,d.doc_id DESC';
    return ['from'=>$from,'params'=>$params,'scope'=>$scope,'custody'=>$custody,'query'=>$search,'order'=>$order];
}

function drms_copy_list(mysqli $conn,array $actor,array $input): array
{
    drms_copy_custody_ready($conn);
    $filter=drms_copy_filter_query($actor,$input);
    $pageRaw=$input['page'] ?? '1';
    if(!is_string($pageRaw) || !preg_match('/^[1-9][0-9]{0,6}$/D',$pageRaw)) throw new DrmsStorageError('Invalid page.',422);
    $page=(int)$pageRaw;
    $from=$filter['from'];$params=$filter['params'];
    $total=(int)drms_vc1_select($conn,'SELECT COUNT(*) AS n'.$from,$params)[0]['n'];
    $page=min($page,max(1,(int)ceil($total/15)));$offset=($page-1)*15;
    $rows=drms_vc1_select($conn,'SELECT d.doc_id,d.file_name,d.record_number,d.category,d.record_phase,d.status AS lifecycle_status,d.disposition_status,l.physical_folder_id,l.status AS physical_status,l.last_updated,
        bl.current_holder_name,bl.expected_return_date,bl.action_date AS custody_action_date'.$from.' ORDER BY '.$filter['order'].' LIMIT 15 OFFSET '.$offset,$params);
    $nodes=drms_storage_snapshot($conn);
    foreach($rows as &$row) {
        $row['full_physical_path']=$nodes['folder:'.$row['physical_folder_id']]['path'] ?? '';
        $row['filing_state']=$row['physical_folder_id']?'Assigned':'Unassigned';
        $row['is_overdue']=$row['physical_status']==='Borrowed' && $row['expected_return_date']!==null && $row['expected_return_date']<date('Y-m-d');
        $row['is_due_soon']=$row['physical_status']==='Borrowed' && $row['expected_return_date']!==null && !$row['is_overdue'] && $row['expected_return_date']<=date('Y-m-d',strtotime('+3 days'));
    }
    unset($row);
    return ['data'=>$rows,'page'=>$page,'total'=>$total,'pages'=>max(1,(int)ceil($total/15))];
}

/** Return the complete ACL-scoped physical inventory represented by the current view. */
function drms_copy_inventory_export(mysqli $conn,array $actor,array $input): array
{
    drms_copy_custody_ready($conn);
    $filter=drms_copy_filter_query($actor,$input);
    $total=(int)drms_vc1_select($conn,'SELECT COUNT(*) AS n'.$filter['from'],$filter['params'])[0]['n'];
    if($total>20000) throw new DrmsStorageError('This view contains more than 20,000 physical copies. Narrow the location, custody or search filter before exporting.',422);
    $rows=drms_vc1_select($conn,'SELECT d.doc_id,d.record_number,d.file_name,d.category,d.record_phase,d.status AS lifecycle_status,d.disposition_status,
        l.physical_folder_id,l.status AS physical_status,l.last_updated,bl.current_holder_name,bl.expected_return_date'.$filter['from'].' ORDER BY '.$filter['order'].' LIMIT 20001',$filter['params']);
    if(count($rows)>20000)throw new DrmsStorageError('This view contains more than 20,000 physical copies. Narrow the location, custody or search filter before exporting.',422);
    $total=count($rows);
    $nodes=drms_storage_snapshot($conn);$today=date('Y-m-d');$soon=date('Y-m-d',strtotime('+3 days'));
    foreach($rows as &$row) {
        $row['full_physical_path']=$nodes['folder:'.$row['physical_folder_id']]['path'] ?? '';
        $borrowed=$row['physical_status']==='Borrowed';$due=$row['expected_return_date'];
        $row['custody_position']=!$borrowed?'In storage':($due===null?'No return date':($due<$today?'Overdue':($due<=$soon?'Due soon':'Borrowed')));
    }
    unset($row);
    return ['rows'=>$rows,'total'=>$total,'filters'=>['scope'=>$filter['scope'],'custody'=>$filter['custody'],'query'=>$filter['query']]];
}

/** Prefix risky spreadsheet cells; never execute record content as a formula. */
function drms_copy_csv_cell(mixed $value): string
{
    $text=(string)($value ?? '');
    if(preg_match('/^[\p{Z}\p{C}\s]*[=+\-@]/u',$text) || preg_match('/^[\t\r\n]/',$text)) return "'".$text;
    return $text;
}

function drms_copy_directory(mysqli $conn,array $actor): array
{
    drms_copy_custody_ready($conn);
    [$where,$params]=drms_copy_access($actor);
    $counts=drms_vc1_select($conn,"SELECT l.physical_folder_id,COUNT(*) AS total,
        SUM(d.status<>'Archived' AND COALESCE(d.record_phase,'')<>'Official') AS working,
        SUM(d.status<>'Archived' AND d.record_phase='Official') AS official,
        SUM(d.status='Archived') AS archived,SUM(l.status='Borrowed') AS borrowed,
        SUM(l.status='Borrowed' AND bl.expected_return_date<CURRENT_DATE) AS overdue,
        SUM(l.status='Borrowed' AND bl.expected_return_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE,INTERVAL 3 DAY)) AS due_soon,
        SUM(l.status='Borrowed' AND bl.expected_return_date IS NULL) AS no_due_date
        FROM documents d JOIN virt_document_locations l ON l.document_id=d.doc_id
        LEFT JOIN physical_borrowing_logs bl ON bl.id=(SELECT MAX(latest_borrow.id) FROM physical_borrowing_logs latest_borrow WHERE latest_borrow.document_id=d.doc_id)
        WHERE $where GROUP BY l.physical_folder_id",$params);
    $stats=['total'=>0,'working'=>0,'official'=>0,'archived'=>0,'borrowed'=>0,'overdue'=>0,'due_soon'=>0,'no_due_date'=>0,'unassigned'=>0];
    $nodes=drms_storage_snapshot($conn);
    // Directory reveals no global record counts or linked digital-category names.
    foreach($nodes as &$node) { unset($node['references'],$node['in_use'],$node['revision']);$node['count']=0; } unset($node);
    foreach($counts as $count) {
        foreach(['total','working','official','archived','borrowed','overdue','due_soon','no_due_date'] as $field) $stats[$field]+=(int)$count[$field];
        if($count['physical_folder_id']===null) $stats['unassigned']+=(int)$count['total'];
        else { $key='folder:'.$count['physical_folder_id']; if(isset($nodes[$key])) $nodes[$key]['count']=(int)$count['total']; }
    }
    return ['nodes'=>array_values($nodes),'stats'=>$stats];
}

/** One registered physical copy per document. Every mutation locks document + copy + directory. */
function drms_copy_mutate(mysqli $conn,int $userId,array $input,string $ip=''): array
{
    drms_copy_ready($conn);
    $actor=drms_copy_actor($conn,$userId);
    if(!$actor['manage']) throw new DrmsStorageError('Physical storage management permission is required.',403);
    $idText=drms_storage_text($input,'doc_id',10);
    if(!ctype_digit($idText) || (int)$idText<1 || (int)$idText>2147483647) throw new DrmsStorageError('Invalid record.',422);
    $id=(int)$idText;
    $action=drms_storage_text($input,'action',30);
    if(!in_array($action,['assign_copy','transfer_copy','borrow_copy','return_copy','replace_physical_copy','dispose_physical_copy'],true)) throw new DrmsStorageError('Refresh the page and use the current physical-copy form.',422);
    if($action==='dispose_physical_copy') drms_copy_disposal_ready($conn);
    if((int)$conn->query("SELECT GET_LOCK('fixie_drms:storage-management',5)")->fetch_row()[0]!==1) throw new DrmsStorageError('A storage change is in progress. Try again shortly.',409);
    try {
        $conn->begin_transaction();
        drms_vc1_select($conn,'SELECT doc_id FROM documents WHERE doc_id=? FOR UPDATE',[$idText]);
        drms_vc1_select($conn,'SELECT id FROM virt_document_locations WHERE document_id=? FOR UPDATE',[$idText]);
        // VC5B: permissions may have changed while this request waited for another save.
        // Current locking reads keep account status and grants stable until commit/rollback.
        $actor=drms_copy_actor($conn,$userId,true);
        if(!$actor['manage']) throw new DrmsStorageError('Physical storage management permission is required.',403);
        $profile=drms_copy_profile($conn,$actor,$id);$doc=$profile['document'];
        if(!hash_equals($doc['revision'],drms_storage_text($input,'revision',64))) throw new DrmsStorageError('This record changed. Refresh its profile before saving.',409);
        $reason=drms_storage_text($input,'reason',500);
        if(($input['confirmed'] ?? '')!=='1') throw new DrmsStorageError('Confirm the physical action before saving.',422);
        $nodes=drms_storage_snapshot($conn);
        $borrowed=$doc['physical_status']==='Borrowed';
        if($action==='assign_copy') {
            if($borrowed) throw new DrmsStorageError('Return the borrowed copy before assigning its storage.',409);
            if($doc['physical_folder_id']) throw new DrmsStorageError('This copy is already filed. Use Transfer physical copy in Virtual Cabinet.',409);
            $key=drms_storage_text($input,'folder',40);[$type,$folderId]=drms_storage_key($key);
            if($type!=='folder' || !isset($nodes[$key]) || !$nodes[$key]['available']) throw new DrmsStorageError('Choose an active physical folder.',422);
            drms_vc1_select($conn,'SELECT id FROM virt_physical_folders WHERE id=? FOR UPDATE',[(string)$folderId]);
            if($doc['location_id']) drms_storage_write($conn,"UPDATE virt_document_locations SET physical_folder_id=?,status='Stored',last_updated=NOW() WHERE document_id=?",[(string)$folderId,$idText]);
            else drms_storage_write($conn,"INSERT INTO virt_document_locations(document_id,status,physical_folder_id) VALUES(?,'Stored',?)",[$idText,(string)$folderId]);
            // A live digital file confirms a matching current version. When only paper
            // remains, filing confirms location without inventing a newer paper version.
            if(!$doc['digital_destroyed']) drms_storage_write($conn,'UPDATE documents SET physical_version=current_version WHERE doc_id=?',[$idText]);
            drms_storage_write($conn,'INSERT INTO physical_movement_logs(document_id,previous_path,new_path,moved_by,reason) VALUES(?,?,?,?,?)',[$idText,$doc['filing_state'],$nodes[$key]['path'],(string)$userId,$reason]);
            $message=$doc['digital_destroyed']?'Retained physical copy filed. Digital file remains destroyed; its recorded paper version is unchanged.':'Physical copy confirmed and filed.';
        } elseif($action==='transfer_copy') {
            if(!$doc['location_id'] || !$doc['physical_folder_id']) throw new DrmsStorageError('Confirm and file the physical copy before transferring it.',409);
            if($borrowed) throw new DrmsStorageError('Record the return of this borrowed copy before transferring it.',409);
            if(!in_array($doc['physical_status'],['Stored','Returned'],true)) throw new DrmsStorageError('Confirm the custody of this copy before transferring it.',409);
            $sourceKey='folder:'.$doc['physical_folder_id'];
            if(!isset($nodes[$sourceKey])) throw new DrmsStorageError('The source physical folder is unavailable. Ask the records custodian to check its location.',409);
            $key=drms_storage_text($input,'folder',40);[$type,$folderId]=drms_storage_key($key);
            if($type!=='folder' || !isset($nodes[$key]) || !$nodes[$key]['available']) throw new DrmsStorageError('Choose an active destination physical folder.',422);
            if($folderId===(int)$doc['physical_folder_id']) throw new DrmsStorageError('Select a different physical folder for the transfer.',422);
            foreach(['source_revision'=>$sourceKey,'destination_revision'=>$key] as $field=>$folderKey) {
                if(!hash_equals(drms_copy_folder_revision($nodes[$folderKey]),drms_storage_text($input,$field,64))) throw new DrmsStorageError('A storage location changed. Close and reopen this profile, then confirm the updated locations.',409);
            }
            drms_vc1_select($conn,'SELECT id FROM virt_physical_folders WHERE id IN (?,?) ORDER BY id FOR UPDATE',[(string)$doc['physical_folder_id'],(string)$folderId]);
            // Move only the filing pointer. A transfer is not a version replacement or a return.
            drms_storage_write($conn,'UPDATE virt_document_locations SET physical_folder_id=?,last_updated=NOW() WHERE document_id=?',[(string)$folderId,$idText]);
            drms_storage_write($conn,'INSERT INTO physical_movement_logs(document_id,previous_path,new_path,moved_by,reason) VALUES(?,?,?,?,?)',[$idText,$nodes[$sourceKey]['path'],$nodes[$key]['path'],(string)$userId,$reason]);
            $message='Physical copy transfer recorded. Digital file and versions are unchanged.';
        } elseif($action==='borrow_copy') {
            if(!$doc['physical_folder_id'] || !$doc['location_id']) throw new DrmsStorageError('Confirm and assign this copy to a physical folder before check-out.',409);
            if($borrowed) throw new DrmsStorageError('This copy is already borrowed.',409);
            if(empty($nodes['folder:'.$doc['physical_folder_id']]['available'])) throw new DrmsStorageError('Its storage location is unavailable.',409);
            if((float)$doc['current_version']>(float)$doc['physical_version']) throw new DrmsStorageError('Replace the outdated physical copy before check-out.',409);
            $holderId=drms_storage_text($input,'holder_id',10);
            if(!ctype_digit($holderId)) throw new DrmsStorageError('Select an active holder.',422);
            $holder=drms_vc1_select($conn,"SELECT full_name FROM users WHERE user_id=? AND status='Active'",[$holderId]);
            if(!$holder) throw new DrmsStorageError('Select an active holder.',422);
            $due=drms_storage_text($input,'expected_return',10,false);
            if($due!=='') { $date=DateTimeImmutable::createFromFormat('!Y-m-d',$due);if(!$date || $date->format('Y-m-d')!==$due || $due<date('Y-m-d')) throw new DrmsStorageError('Expected return must be today or a valid future date.',422); }
            drms_storage_write($conn,"UPDATE virt_document_locations SET status='Borrowed',last_updated=NOW() WHERE document_id=?",[$idText]);
            drms_storage_write($conn,"INSERT INTO physical_borrowing_logs(document_id,action_type,user_id,current_holder_name,expected_return_date,remarks) VALUES(?,'Borrowed',?,?,?,?)",[$idText,(string)$userId,$holder[0]['full_name'],$due?:null,$reason]);
            $message='Check-out recorded.';
        } elseif($action==='return_copy') {
            if(!$borrowed) throw new DrmsStorageError('This copy is not currently borrowed.',409);
            $latest=drms_vc1_select($conn,"SELECT current_holder_name,expected_return_date FROM physical_borrowing_logs WHERE document_id=? AND action_type='Borrowed' ORDER BY id DESC LIMIT 1",[$idText]);
            drms_storage_write($conn,"UPDATE virt_document_locations SET status='Stored',last_updated=NOW() WHERE document_id=?",[$idText]);
            drms_storage_write($conn,"INSERT INTO physical_borrowing_logs(document_id,action_type,user_id,current_holder_name,expected_return_date,remarks) VALUES(?,'Returned',?,?,?,?)",[$idText,(string)$userId,$latest[0]['current_holder_name']??'Unknown (historical borrowing entry unavailable)',$latest[0]['expected_return_date']??null,$reason]);
            $message=$doc['physical_folder_id']?'Return recorded.':'Return recorded; confirm its physical folder next.';
        } elseif($action==='replace_physical_copy') {
            if($doc['digital_destroyed']) throw new DrmsStorageError('The digital file has been destroyed. Its physical version cannot be replaced from an unavailable file.',409);
            if(!$doc['physical_folder_id'] || $borrowed) throw new DrmsStorageError('A filed, non-borrowed copy is required for replacement.',409);
            if((float)$doc['current_version']<=(float)$doc['physical_version']) throw new DrmsStorageError('The registered physical version is already current.',409);
            $historyRow=drms_vc1_select($conn,'SELECT rename_history FROM documents WHERE doc_id=?',[$idText])[0];
            $history=json_decode($historyRow['rename_history']??'[]',true);
            if(!is_array($history)) throw new DrmsStorageError('Existing version history is invalid; it was not overwritten.',409);
            array_unshift($history,['type'=>'physical_replaced','old_version'=>$doc['physical_version'],'new_version'=>$doc['current_version'],'date'=>date('Y-m-d H:i:s'),'by'=>$actor['full_name']]);
            drms_storage_write($conn,'UPDATE documents SET physical_version=current_version,rename_history=? WHERE doc_id=?',[json_encode($history,JSON_THROW_ON_ERROR),$idText]);
            drms_storage_write($conn,'UPDATE virt_document_locations SET last_updated=NOW() WHERE document_id=?',[$idText]);
            $message='Physical copy replacement confirmed.';
        } else {
            if(!$doc['digital_destroyed'] || $doc['record_phase']!=='Official') throw new DrmsStorageError('The official digital file must have a completed destruction certificate before its physical copy can be disposed.',409);
            if((bool)$doc['is_legal_hold']) throw new DrmsStorageError('This record is under Legal Hold. Its physical copy cannot be disposed.',409);
            if(!$doc['location_id'] || !$doc['physical_folder_id']) throw new DrmsStorageError('Only a registered and filed physical copy can be disposed through this action.',409);
            if($borrowed) throw new DrmsStorageError('Return the borrowed physical copy before disposal.',409);
            if(!in_array($doc['physical_status'],['Stored','Returned'],true)) throw new DrmsStorageError('Confirm the custody of this physical copy before disposal.',409);
            $sourceKey='folder:'.$doc['physical_folder_id'];
            if(!isset($nodes[$sourceKey])) throw new DrmsStorageError('The recorded physical folder is unavailable. Correct the location before disposal.',409);
            if(!hash_equals(drms_copy_folder_revision($nodes[$sourceKey]),drms_storage_text($input,'source_revision',64))) throw new DrmsStorageError('The physical storage path changed. Close and reopen the profile before confirming disposal.',409);
            $method=drms_storage_text($input,'disposal_method',100);
            if(!in_array($method,['Cross-cut shredding','Pulverization','Authorized disposal service','Other documented method'],true)) throw new DrmsStorageError('Select an approved physical disposal method.',422);
            if(mb_strlen($reason,'UTF-8')<10) throw new DrmsStorageError('Explain the physical disposal in at least 10 characters.',422);
            if(drms_storage_text($input,'typed_confirmation',20)!=='DISPOSE') throw new DrmsStorageError('Type DISPOSE exactly to confirm the irreversible physical action.',422);
            $certificates=drms_vc1_select($conn,"SELECT c.certificate_number,c.certificate_hash,c.request_id,c.doc_id,c.file_sha256,c.file_size,c.requested_by,c.reviewed_by,c.destroyed_by,c.destroyed_at,c.deletion_method
                FROM destruction_certificates c JOIN disposition_requests r ON r.request_id=c.request_id AND r.doc_id=c.doc_id
                WHERE c.doc_id=? AND r.status='Executed' AND r.requested_action='Destroy'
                ORDER BY c.destroyed_at DESC,c.certificate_id DESC LIMIT 1 FOR UPDATE",[$idText]);
            if(!$certificates) throw new DrmsStorageError('The completed digital destruction certificate could not be verified. The physical copy was not changed.',409);
            $certificatePayload=[
                'certificate_number'=>$certificates[0]['certificate_number'],'request_id'=>(int)$certificates[0]['request_id'],
                'doc_id'=>(int)$certificates[0]['doc_id'],'file_sha256'=>$certificates[0]['file_sha256'],'file_size'=>(int)$certificates[0]['file_size'],
                'requested_by'=>(int)$certificates[0]['requested_by'],'reviewed_by'=>(int)$certificates[0]['reviewed_by'],
                'destroyed_by'=>(int)$certificates[0]['destroyed_by'],'destroyed_at'=>$certificates[0]['destroyed_at'],'method'=>$certificates[0]['deletion_method'],
            ];
            $certificateHash=hash('sha256',json_encode($certificatePayload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            if(!hash_equals((string)$certificates[0]['certificate_hash'],$certificateHash)) throw new DrmsStorageError('The digital destruction certificate failed its integrity check. The physical copy was not changed.',409);
            $clock=(string)$conn->query('SELECT NOW()')->fetch_row()[0];
            $year=substr($clock,0,4);
            $sequence=(int)drms_vc1_select($conn,"SELECT COALESCE(MAX(CAST(SUBSTRING(evidence_number,10) AS UNSIGNED)),0) AS n FROM physical_disposition_logs WHERE evidence_number LIKE ?",['PCD-'.$year.'-%'])[0]['n']+1;
            $evidenceNumber='PCD-'.$year.'-'.str_pad((string)$sequence,6,'0',STR_PAD_LEFT);
            $evidence=[
                'evidence_number'=>$evidenceNumber,'document_id'=>$id,'record_number'=>$doc['record_number']?:'',
                'source_folder_id'=>(int)$doc['physical_folder_id'],'source_path'=>$nodes[$sourceKey]['path'],
                'physical_version'=>(string)$doc['physical_version'],'copy_status'=>(string)$doc['physical_status'],
                'disposal_method'=>$method,'reason'=>$reason,'digital_certificate_number'=>$certificates[0]['certificate_number'],
                'digital_certificate_hash'=>$certificates[0]['certificate_hash'],'disposed_by'=>$userId,
                'disposed_by_name'=>$actor['full_name'],'disposed_at'=>$clock,
            ];
            $evidence['evidence_hash']=drms_copy_disposal_hash($evidence);
            drms_storage_write($conn,'INSERT INTO physical_disposition_logs(document_id,evidence_number,record_number,source_folder_id,source_path,physical_version,copy_status,disposal_method,reason,digital_certificate_number,digital_certificate_hash,disposed_by,disposed_by_name,disposed_at,evidence_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $idText,$evidenceNumber,$evidence['record_number'],(string)$evidence['source_folder_id'],$evidence['source_path'],$evidence['physical_version'],$evidence['copy_status'],$method,$reason,$evidence['digital_certificate_number'],$evidence['digital_certificate_hash'],(string)$userId,$actor['full_name'],$clock,$evidence['evidence_hash']
            ]);
            drms_storage_write($conn,'INSERT INTO physical_movement_logs(document_id,previous_path,new_path,moved_by,reason) VALUES(?,?,?,?,?)',[$idText,$evidence['source_path'],'[PHYSICAL COPY DISPOSED · '.$evidenceNumber.']',(string)$userId,$reason]);
            $delete=$conn->prepare('DELETE FROM virt_document_locations WHERE id=? AND document_id=?');
            $locationId=(string)$doc['location_id'];$delete->bind_param('ss',$locationId,$idText);$delete->execute();$removed=$delete->affected_rows;$delete->close();
            if($removed!==1) throw new DrmsStorageError('The registered physical copy changed before disposal. Refresh and try again.',409);
            $message='Physical copy disposal recorded as '.$evidenceNumber.'. The digital destruction certificate and histories remain preserved.';
            drms_storage_write($conn,'INSERT INTO audit_logs(user_id,action_type,description,old_payload,new_payload,ip_address) VALUES(?,?,?,?,?,?)',[(string)$userId,'DISPOSE_PHYSICAL_COPY','Physical record '.$id.': '.$message,json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode(['physical_disposition'=>$evidence],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),filter_var($ip,FILTER_VALIDATE_IP)?$ip:'UNKNOWN']);
            $conn->commit();
            return ['message'=>$message,'doc_id'=>$id,'removed'=>true,'evidence_number'=>$evidenceNumber];
        }
        $after=drms_copy_profile($conn,$actor,$id)['document'];
        drms_storage_write($conn,'INSERT INTO audit_logs(user_id,action_type,description,old_payload,new_payload,ip_address) VALUES(?,?,?,?,?,?)',[(string)$userId,strtoupper($action),'Physical record '.$id.': '.$message,json_encode($doc,JSON_THROW_ON_ERROR),json_encode(['document'=>$after,'reason'=>$reason],JSON_THROW_ON_ERROR),filter_var($ip,FILTER_VALIDATE_IP)?$ip:'UNKNOWN']);
        $conn->commit();
        return ['message'=>$message,'doc_id'=>$id];
    } catch(Throwable $error) {
        $conn->rollback();
        if($error instanceof mysqli_sql_exception && in_array($error->getCode(),[1062,1451,1452,1213,1205],true)) throw new DrmsStorageError('Another request changed this record or location. Refresh before retrying.',409);
        throw $error;
    } finally {
        try{$conn->query("SELECT RELEASE_LOCK('fixie_drms:storage-management')");}catch(Throwable $cleanup){error_log('Physical-copy lock cleanup: '.$cleanup->getMessage());}
    }
}
