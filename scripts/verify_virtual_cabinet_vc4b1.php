<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try{
    $markers=[
        'config/physical_records.php'=>['retained_copy.document_id=d.doc_id','digital_destroyed','disposition_status','transfer_copy'],
        'includes/physical_record_profile.php'=>['vcpDigitalNotice','Physical copy retained'],
        'assets/js/physical-record-profile.js'=>['DigitalNotice','profile.document.digital_destroyed',"removeAttribute('href')"],
        'assets/js/physical-cabinet.js'=>['Digital file destroyed','Paper retained'],
        'documents.php'=>['digital_scope_confirmed','scopeConfirmation.required = isDestroy','Digital file destroyed','#dispositionExecutionForm .modal-body','.vc4b-digital-scope'],
        'actions/disposition_handler.php'=>['digital_scope_confirmed','Digital file only:','physical copy not disposed'],
        'view_destruction_certificate.php'=>['digitalOnlyCertificate','Certificate of Digital File Destruction']
    ];
    foreach($markers as $file=>$needles){
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing: '.$file);
        $code=file_get_contents($path);foreach($needles as $needle)if(!str_contains($code,$needle))throw new RuntimeException('Incomplete VC4B1 file: '.$file);
        if(str_ends_with($file,'.php')){
            $pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start PHP syntax check.');
            fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0)throw new RuntimeException($output);
        }
    }
    echo "PASS: eight updated files, PHP syntax and digital/physical scope wiring.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_ready($conn);
    $conn->query('SELECT d.doc_id,d.disposition_status,l.physical_folder_id FROM documents d JOIN virt_document_locations l ON l.document_id=d.doc_id LIMIT 0');
    $conn->query('SELECT deletion_method,certificate_hash FROM destruction_certificates LIMIT 0');
    $conn->query('SELECT execution_method,execution_result_hash FROM disposition_requests LIMIT 0');
    [$where,$params]=drms_copy_access(['all'=>true,'user_id'=>0,'role'=>'']);
    $retained=drms_vc1_select($conn,"SELECT COUNT(*) AS n FROM documents d WHERE $where AND d.disposition_status='Destroyed'",$params)[0]['n'];
    echo 'PASS: existing schema ready; '.$retained." destroyed-digital record(s) still have registered physical copies.\n";
    echo "VC4B1 installation verification passed. Read-only; no files, records, permissions or history changed.\n";
    echo "No new SQL migration. Use Ctrl+F5 before testing. Actual paper disposal is deferred to VC4B2.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}}
exit($exit);
