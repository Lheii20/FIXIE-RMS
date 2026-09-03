<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try{
    foreach(['general_docs.php','documents.php'] as $file){
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing: '.$file);
        $source=file_get_contents($path);$start=strpos($source,'<!-- DECLARE OFFICIAL MODAL -->');$end=strpos($source,'<!-- UPLOAD MODAL -->',$start?:0);
        if($start===false || $end===false)throw new RuntimeException('Declaration form not found: '.$file);
        $modal=substr($source,$start,$end-$start);
        if(!preg_match('/<input[^>]+type="checkbox"[^>]+name="official_signature_confirmed"[^>]+value="1"[^>]+id="declareSignatureConfirmed"[^>]+required>/',$modal))throw new RuntimeException('Required signature checkbox missing: '.$file);
        if(!str_contains($source,"document.getElementById('declareSignatureConfirmed').checked = false;"))throw new RuntimeException('Fresh confirmation reset missing: '.$file);
        if(!str_contains($source,'drms_copy_path_sql()') || !str_contains($source,'openPhysicalRecordProfile'))throw new RuntimeException('Physical-profile integration missing: '.$file);
        $pipes=[];$proc=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Unable to start PHP syntax check.');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($output);
    }
    echo "PASS: both record pages have required signature confirmation, reset behavior and physical-profile integration.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_custody_ready($conn);
    $counts=$conn->query('SELECT COUNT(*) AS registered,SUM(physical_folder_id IS NOT NULL) AS assigned FROM virt_document_locations')->fetch_assoc();
    $conn->query('SELECT '.drms_copy_path_sql().' AS physical_path FROM documents d LIMIT 1');
    echo 'PASS: shared location query and installed physical schema; '.(int)$counts['registered'].' registered, '.(int)($counts['assigned']??0)." assigned.\n";
    echo "VC5A verification passed. Read-only; no records, files, locations or permissions changed.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}}
exit($exit);
