<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==2 || !in_array($argv[1],['--check','--apply'],true)){fwrite(STDERR,"Use --check or --apply. Back up the database before --apply.\n");exit(1);}
try {
    require dirname(__DIR__).'/config/maintenance_db.php';
    require dirname(__DIR__).'/config/physical_records.php';
    $database=$conn->query('SELECT DATABASE()')->fetch_row()[0];
    if($database!=='fixie_drms')throw new RuntimeException('Refusing unexpected database: '.$database);
    echo 'Database: '.$database."\n";
    $steps=drms_copy_schema($conn);
    foreach($steps as $step)echo 'Pending: '.$step."\n";
    if($argv[1]==='--check'){echo 'VC3 preflight passed. Pending steps: '.count($steps).". No changes made.\n";exit;}
    // DDL commits implicitly. Run during maintenance; safe to check/retry after partial installation.
    foreach($steps as $step)$conn->query($step);
    drms_copy_ready($conn);
    echo 'VC3 schema installed. Steps applied: '.count($steps).". No record data merged, deleted or reassigned.\n";
}catch(Throwable $error){fwrite(STDERR,'STOP: '.$error->getMessage()."\n");exit(1);}
