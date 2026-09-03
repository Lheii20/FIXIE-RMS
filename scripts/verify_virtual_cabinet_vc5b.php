<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try {
    $markers=[
        'config/physical_records.php'=>[
            'BINARY JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.file_permissions)',
            'drms_copy_actor($conn,$userId,true)',
            'ORDER BY l.id DESC LIMIT 20',
            "action_type='Borrowed' ORDER BY id DESC LIMIT 1",
        ],
        'config/storage_locations.php'=>['bool $lock = false','drms_storage_authorize($conn, $userId, true)'],
    ];
    foreach($markers as $file=>$needles) {
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing: '.$file);
        $code=file_get_contents($path);foreach($needles as $needle)if(!str_contains($code,$needle))throw new RuntimeException('Incomplete VC5B replacement: '.$file);
        $pipes=[];$process=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start the syntax check.');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException($output);
    }
    echo "PASS: both VC5B replacements and PHP syntax.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_custody_ready($conn);drms_copy_disposal_ready($conn);
    // Literal derived rows only: no temporary tables, inserts, edits or real shares.
    [$where,$params]=drms_copy_access(['all'=>false,'user_id'=>2147483647,'role'=>'__VC5B_no_role__']);
    $sql="SELECT ($where) AS allowed FROM (SELECT -1 AS doc_id,'Active' AS status,'' AS disposition_status,'Official' AS record_phase,0 AS uploaded_by,? AS file_permissions,'Restricted' AS access_type,'__VC5B_no_category__' AS category) d";
    $cases=[
        ['{"user_2147483647":"Viewer"}',true],['{"user_2147483647":"Editor"}',true],
        ['{"user_2147483647":"\\u0056iewer"}',true],['{"user_2147483647":"Denied"}',false],
        ['{"user_2147483647":"viewer"}',false],['{"user_2147483647":"Viewer "}',false],
        ['{"user_2147483647":null}',false],['{"user_2147483647":false}',false],
        ['{"user_2147483647":1}',false],['{"user_2147483647":["Viewer"]}',false],
        ['{"note":"user_2147483647"}',false],['["user_2147483647"]',false],
        ['{"nested":{"user_2147483647":"Viewer"}}',false],['{"user_214748364":"Viewer"}',false],
        ['null',false],['{}',false],[null,false],['not-json',false],
    ];
    foreach($cases as $index=>[$json,$allowed]){
        $actual=drms_vc1_select($conn,$sql,array_merge($params,[$json]));
        if(((int)$actual[0]['allowed']===1)!==$allowed)throw new RuntimeException('Strict-share regression at test '.($index+1).'.');
    }
    echo 'PASS: '.count($cases)." strict-share cases using read-only literal rows.\n";
    echo "PASS: custody/disposal schema and latest-event ordering markers.\n";
    echo "VC5B verification passed. Read-only; no records, files, locations, custody events or permissions changed.\n";
    echo "Concurrent-save and rollback tests are documented separately; this checker does not simulate writes on your database.\n";
} catch(Throwable $error) {
    $exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");
} finally {
    if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}
}
exit($exit);
