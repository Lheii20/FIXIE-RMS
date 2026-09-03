<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run this verifier without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try {
    $files=['config/physical_records.php','scripts/migrate_virtual_cabinet_vc3.php','actions/cabinet_fetcher.php','actions/physical_location_handler.php','includes/physical_record_profile.php','assets/css/physical-records.css','assets/js/physical-record-profile.js','assets/js/physical-cabinet.js','documents.php','general_docs.php','virtual_cabinet.php','actions/document_handler.php','config/storage_locations.php','includes/storage_location_manager.php','assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js'];
    foreach($files as $file){$path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing file: '.$file);
        if(substr($file,-4)==='.php'){$pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Could not run syntax check.');fclose($pipes[0]);$result=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($result);}}
    echo "PASS: required files and PHP syntax.\n";
    foreach(['documents.php','general_docs.php','virtual_cabinet.php'] as $file){$code=file_get_contents($root.'/'.$file);foreach(['includes/physical_record_profile.php','assets/css/physical-records.css?v=vc3-1','assets/js/physical-record-profile.js?v=vc3-1'] as $needle)if(strpos($code,$needle)===false)throw new RuntimeException('Incomplete integration in '.$file.': '.$needle);
        if(strpos($code,'CONCAT_WS(\' > \', b.name, r.name, c.name, dr.name, dc.sub_category)')!==false)throw new RuntimeException('Obsolete category-based physical path in '.$file);}
    $conversion=file_get_contents($root.'/actions/document_handler.php');
    foreach(['$has_registered_physical_copy','UPDATE virt_document_locations SET document_id = ? WHERE document_id = ?','UPDATE physical_borrowing_logs SET document_id = ? WHERE document_id = ?','UPDATE physical_movement_logs SET document_id = ? WHERE document_id = ?'] as $needle)if(strpos($conversion,$needle)===false)throw new RuntimeException('Missing official-conversion preservation: '.$needle);
    foreach(['physical-record-profile.js','physical-cabinet.js'] as $file){$js=file_get_contents($root.'/assets/js/'.$file);if(strpos($js,'.innerHTML')!==false)throw new RuntimeException('Unsafe dynamic markup in '.$file);}
    echo "PASS: shared profiles, independent location paths and conversion links.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_ready($conn);$counts=$conn->query('SELECT COUNT(*) AS total,COALESCE(SUM(physical_folder_id IS NULL),0) AS unassigned FROM virt_document_locations')->fetch_assoc();
    $conn->query('SELECT '.drms_copy_path_sql().' AS physical_path FROM documents d LIMIT 1');
    echo 'PASS: schema and location queries; '.$counts['total'].' registered copies, '.$counts['unassigned']." unassigned.\n";
    echo "VC3 verification passed. Read-only: no records, assignments or audit rows changed.\n";
    echo "Next: sign in with an authorized account and test assignment and browsing.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}}
exit($exit);
