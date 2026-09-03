<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run this verifier without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try {
    $files=['config/physical_records.php','includes/physical_record_profile.php','assets/js/physical-record-profile.js',
        'assets/css/physical-records.css','assets/js/physical-cabinet.js','actions/cabinet_fetcher.php','actions/physical_location_handler.php',
        'config/maintenance_db.php','config/storage_locations.php','documents.php','general_docs.php','virtual_cabinet.php'];
    foreach($files as $file){
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing file: '.$file);
        if(str_ends_with($file,'.php')){
            $pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not run syntax check.');
            fclose($pipes[0]);$result=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0)throw new RuntimeException($result);
        }
    }
    echo "PASS: required files and PHP syntax.\n";
    $markers=[
        'config/physical_records.php'=>['function drms_copy_folder_revision','transfer_copy','source_revision','destination_revision'],
        'includes/physical_record_profile.php'=>['id="vcpTransfer"','id="vcpFolderEmpty"','id="vcpDestinationPreview"'],
        'assets/js/physical-record-profile.js'=>['transfer_copy','source_revision','destination_revision','folderPreview','FolderEmpty'],
        'actions/physical_location_handler.php'=>['drms_storage_csrf','drms_copy_mutate'],
        'assets/js/physical-cabinet.js'=>['physical-copy-updated']
    ];
    foreach($markers as $file=>$needles){$code=file_get_contents($root.'/'.$file);foreach($needles as $needle)if(!str_contains($code,$needle))throw new RuntimeException('Incomplete VC4A integration in '.$file.': '.$needle);}
    foreach(['documents.php','general_docs.php','virtual_cabinet.php'] as $file){$code=file_get_contents($root.'/'.$file);foreach(['includes/physical_record_profile.php','assets/js/physical-record-profile.js'] as $needle)if(!str_contains($code,$needle))throw new RuntimeException('Missing shared profile in '.$file);}
    echo "PASS: transfer form, secured endpoint and cabinet refresh wiring.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_ready($conn);$nodes=drms_storage_snapshot($conn);$folders=0;
    foreach($nodes as $node)if($node['type']==='folder'){
        if(!preg_match('/^[a-f0-9]{64}$/D',drms_copy_folder_revision($node)))throw new RuntimeException('Invalid folder confirmation revision.');
        if($node['available'])$folders++;
    }
    $conn->query('SELECT previous_path,new_path,moved_by,moved_at,reason FROM physical_movement_logs LIMIT 0');
    echo 'PASS: VC3 schema and movement history ready; '.$folders." active physical folder(s).\n";
    if($folders<2)echo "NOTE: A transfer needs a filed source copy and a different active destination folder.\n";
    echo "VC4A installation verification passed. Read-only: no copies, files, history or locations changed.\n";
    echo "No new SQL migration is required. Use Ctrl+F5 and test Transfer physical copy in Virtual Cabinet.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}}
exit($exit);
