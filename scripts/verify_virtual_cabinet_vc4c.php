<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try{
    $markers=[
        'config/physical_records.php'=>['drms_copy_custody_schema','no_due_date','current_holder_name LIKE','is_overdue'],
        'virtual_cabinet.php'=>['vc3Custody','Physical position / custody','vc4c-1'],
        'assets/js/physical-cabinet.js'=>['Due in 3 days','record.current_holder_name','custodyParam'],
        'assets/js/physical-record-profile.js'=>['vcp-overdue','OVERDUE'],
        'assets/css/physical-records.css'=>['vc3-position-overdue','vc3-search select'],
        'scripts/migrate_virtual_cabinet_vc4c.php'=>['idx_vc4c_borrow_document','drms_copy_custody_ready'],
    ];
    foreach($markers as $file=>$needles){
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing: '.$file);
        $code=file_get_contents($path);foreach($needles as $needle)if(!str_contains($code,$needle))throw new RuntimeException('Incomplete VC4C file: '.$file);
        if(str_ends_with($file,'.php')){
            $pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start PHP syntax check.');
            fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0)throw new RuntimeException($output);
        }
    }
    echo "PASS: VC4C files, markers and PHP syntax.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_custody_ready($conn);
    $counts=$conn->query("SELECT COUNT(*) AS registered,
        SUM(l.status='Borrowed') AS borrowed,
        SUM(l.status='Borrowed' AND b.expected_return_date<CURRENT_DATE) AS overdue,
        SUM(l.status='Borrowed' AND b.expected_return_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE,INTERVAL 3 DAY)) AS due_soon,
        SUM(l.status='Borrowed' AND b.expected_return_date IS NULL) AS no_due_date
        FROM virt_document_locations l
        LEFT JOIN physical_borrowing_logs b ON b.id=(SELECT MAX(latest.id) FROM physical_borrowing_logs latest WHERE latest.document_id=l.document_id)")->fetch_assoc();
    foreach(['registered','borrowed','overdue','due_soon','no_due_date'] as $field)$counts[$field]=(int)($counts[$field]??0);
    if($counts['overdue']>$counts['borrowed'] || $counts['due_soon']>$counts['borrowed'] || $counts['no_due_date']>$counts['borrowed'])throw new RuntimeException('Custody totals are inconsistent. No data was changed.');
    echo 'PASS: '.$counts['registered'].' registered; '.$counts['borrowed'].' borrowed; '.$counts['overdue'].' overdue; '.$counts['due_soon'].' due within three days; '.$counts['no_due_date']." without a return date.\n";
    echo "VC4C verification passed. Read-only; no records, custody events, locations or permissions changed.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}}
exit($exit);
