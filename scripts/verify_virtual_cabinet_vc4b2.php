<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try{
    $markers=[
        'config/physical_records.php'=>['physical_disposition_logs','dispose_physical_copy','drms_copy_disposal_hash','Type DISPOSE exactly'],
        'includes/physical_record_profile.php'=>['vcpDispose','vcpDisposalFields','Cross-cut shredding'],
        'assets/js/physical-record-profile.js'=>['physical_disposal_eligible','dispose_physical_copy','result.removed'],
        'documents.php'=>['physical_disposal_evidence_number','Physical copy retained in cabinet'],
        'view_destruction_certificate.php'=>['physicalEvidenceValid','Physical evidence SHA-256'],
        'scripts/migrate_virtual_cabinet_vc4b2.php'=>['drms_copy_disposal_schema','fixie_drms'],
    ];
    foreach($markers as $file=>$needles){
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing: '.$file);
        $code=file_get_contents($path);foreach($needles as $needle)if(!str_contains($code,$needle))throw new RuntimeException('Incomplete VC4B2 file: '.$file);
        if(str_ends_with($file,'.php')){
            $pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start PHP syntax check.');
            fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0)throw new RuntimeException($output);
        }
    }
    echo "PASS: VC4B2 files, markers and PHP syntax.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_disposal_ready($conn);
    $rows=drms_vc1_select($conn,'SELECT p.*,c.certificate_number,c.certificate_hash FROM physical_disposition_logs p JOIN documents d ON d.doc_id=p.document_id LEFT JOIN destruction_certificates c ON c.doc_id=p.document_id AND c.certificate_number=p.digital_certificate_number');
    $invalid=0;
    foreach($rows as $row){
        $payload=[
            'evidence_number'=>$row['evidence_number'],'document_id'=>(int)$row['document_id'],'record_number'=>$row['record_number'],
            'source_folder_id'=>(int)$row['source_folder_id'],'source_path'=>$row['source_path'],'physical_version'=>(string)$row['physical_version'],
            'copy_status'=>$row['copy_status'],'disposal_method'=>$row['disposal_method'],'reason'=>$row['reason'],
            'digital_certificate_number'=>$row['digital_certificate_number'],'digital_certificate_hash'=>$row['digital_certificate_hash'],
            'disposed_by'=>(int)$row['disposed_by'],'disposed_by_name'=>$row['disposed_by_name'],'disposed_at'=>$row['disposed_at'],
        ];
        if(!hash_equals($row['evidence_hash'],drms_copy_disposal_hash($payload)) || !$row['certificate_number'] || !hash_equals($row['digital_certificate_hash'],$row['certificate_hash']))$invalid++;
    }
    $active=(int)drms_vc1_select($conn,'SELECT COUNT(*) AS n FROM physical_disposition_logs p JOIN virt_document_locations l ON l.document_id=p.document_id')[0]['n'];
    if($invalid || $active)throw new RuntimeException($invalid.' invalid evidence row(s); '.$active.' disposed copy/copies still active in the cabinet.');
    echo 'PASS: '.count($rows)." physical-disposal evidence row(s) verified; none remain in the active cabinet.\n";
    echo "VC4B2 verification passed. Read-only; no records, files, folders or histories changed.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}}
exit($exit);
