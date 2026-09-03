<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }
if (count($argv)!==1) { fwrite(STDERR,"Usage: php scripts/verify_virtual_cabinet_vc2.php\n"); exit(1); }
$root=dirname(__DIR__);
$conn=null;
$exit=0;
try {
    $files=['virtual_cabinet.php','config/storage_locations.php','actions/storage_location_handler.php','includes/storage_location_manager.php','assets/css/storage-location-manager.css','assets/js/storage-location-manager.js','config/physical_storage_schema.php','config/maintenance_db.php'];
    foreach($files as $file) {
        $path=$root.'/'.$file;
        if(!is_file($path))throw new RuntimeException('Missing file: '.$file);
        if(substr($file,-4)==='.php') {
            $pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Unable to run PHP syntax check.');
            fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0)throw new RuntimeException($output);
        }
    }
    echo "PASS: required files and PHP syntax.\n";
    $page=file_get_contents($root.'/virtual_cabinet.php');
    foreach(['#storageLocationManager','includes/storage_location_manager.php','assets/css/storage-location-manager.css?v=vc2-1','assets/js/storage-location-manager.js?v=vc2-1'] as $needle) {
        if(strpos($page,$needle)===false)throw new RuntimeException('Missing page integration: '.$needle);
    }
    $js=file_get_contents($root.'/assets/js/storage-location-manager.js');
    if(strpos($js,'.innerHTML')!==false || strpos($js,'textContent')===false)throw new RuntimeException('Expected safe location-label rendering is missing.');
    echo "PASS: page integration and safe text rendering.\n";
    require $root.'/config/maintenance_db.php';
    require $root.'/config/storage_locations.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_storage_ready($conn);
    $nodes=drms_storage_snapshot($conn);
    echo 'PASS: VC1 schema and location directory readable ('.count($nodes)." locations).\n";
    $broken=0;
    foreach($nodes as $node) {
        if($node['parent']!=='' && !isset($nodes[$node['parent']]))$broken++;
    }
    if($broken)throw new RuntimeException('Existing locations have missing parents; no automatic repairs were made.');
    echo "PASS: no missing parent links.\n";
    echo "VC2 verification passed. Read-only: no locations, records or audit rows changed.\n";
    echo "Next: sign in with an authorized records-management account and test Manage locations. Record assignment follows in VC3.\n";
} catch(Throwable $error) {
    $exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");
} finally {
    if($conn instanceof mysqli) {try{$conn->rollback();}catch(Throwable $ignored){} $conn->close();}
}
exit($exit);
