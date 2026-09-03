<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$locked=false;$exit=0;
try{
    require $root.'/config/maintenance_db.php';
    require $root.'/config/physical_records.php';
    if((string)$conn->query('SELECT DATABASE()')->fetch_row()[0]!=='fixie_drms')throw new RuntimeException('Refusing migration outside the fixie_drms database.');
    $locked=(int)$conn->query("SELECT GET_LOCK('fixie_drms:storage-management',10)")->fetch_row()[0]===1;
    if(!$locked)throw new RuntimeException('A storage change is in progress. Try the migration again shortly.');
    $steps=drms_copy_custody_schema($conn);
    if(!$steps)echo "VC4C custody index is already installed and compatible. No change was made.\n";
    else{
        if(count($steps)!==1 || $steps[0]!=='ALTER TABLE physical_borrowing_logs ADD KEY idx_vc4c_borrow_document (document_id,id)')throw new RuntimeException('Unexpected migration plan; nothing was executed.');
        $conn->query($steps[0]);
        echo "Created idx_vc4c_borrow_document.\n";
    }
    drms_copy_custody_ready($conn);
    echo "VC4C schema verification passed. Borrowing entries and physical-copy records were not modified.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){if($locked){try{$conn->query("SELECT RELEASE_LOCK('fixie_drms:storage-management')");}catch(Throwable $ignored){}}$conn->close();}}
exit($exit);
