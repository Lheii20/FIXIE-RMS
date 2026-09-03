<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try {
    $markers=[
        'config/physical_records.php'=>['drms_copy_filter_query','drms_copy_inventory_export','drms_copy_csv_cell','more than 20,000 physical copies'],
        'config/audit_bootstrap.php'=>['actions/export_physical_inventory.php'],
        'actions/export_physical_inventory.php'=>['EXPORT_PHYSICAL_INVENTORY','Content-Disposition: attachment','X-Content-Type-Options: nosniff'],
        'virtual_cabinet.php'=>['vc3ExportForm','Export current view','vc4d-1'],
        'assets/js/physical-cabinet.js'=>['syncExport','Preparing…','URL.createObjectURL'],
        'assets/css/physical-records.css'=>['.vc3-export','.vc3-toolbar>.btn'],
    ];
    foreach($markers as $file=>$needles) {
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing: '.$file);
        $code=file_get_contents($path);foreach($needles as $needle)if(!str_contains($code,$needle))throw new RuntimeException('Incomplete VC4D file: '.$file);
        if(str_ends_with($file,'.php')) {
            $pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start PHP syntax check.');
            fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0)throw new RuntimeException($output);
        }
    }
    echo "PASS: VC4D files, integration markers and PHP syntax.\n";

    require_once $root.'/config/audit_bootstrap.php';
    if(!drms_audit_should_skip_request('actions/export_physical_inventory.php') || drms_audit_should_capture_request('POST','actions/export_physical_inventory.php'))throw new RuntimeException('The explicit physical-inventory export would receive a duplicate generic audit event.');
    echo "PASS: explicit inventory export audit is not duplicated.\n";

    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_custody_ready($conn);
    $actorRow=$conn->query("SELECT user_id FROM users WHERE status='Active' AND role<>'Admin' ORDER BY user_id LIMIT 1")->fetch_assoc();
    if(!$actorRow)throw new RuntimeException('No active records account is available for the ACL verification.');
    $actor=drms_copy_actor($conn,(int)$actorRow['user_id']);
    foreach(['all','borrowed','overdue','due_soon','no_due_date'] as $custody) {
        $input=['scope'=>'all','custody'=>$custody,'query'=>'','page'=>'1'];
        $list=drms_copy_list($conn,$actor,$input);$export=drms_copy_inventory_export($conn,$actor,$input);
        if($list['total']!==$export['total'] || count($export['rows'])!==$export['total'])throw new RuntimeException('List/export mismatch for custody filter: '.$custody);
    }
    if(drms_copy_csv_cell('=2+2')!=="'=2+2" || drms_copy_csv_cell('  -10')!=="'  -10" || drms_copy_csv_cell('Normal record')!=='Normal record')throw new RuntimeException('CSV formula protection regression.');
    echo "PASS: list/export totals match under ACL and every custody filter.\n";
    echo "PASS: spreadsheet formula injection protection.\n";
    echo "VC4D verification passed. Read-only; no records, locations, custody events, files or permissions changed.\n";
} catch(Throwable $error) {
    $exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");
} finally {
    if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}
}
exit($exit);
