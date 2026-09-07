<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==1){fwrite(STDERR,"Run without arguments.\n");exit(1);}
$root=dirname(__DIR__);$conn=null;$exit=0;
try {
    $required=[
        'virtual_cabinet.php'=>['cabinet-redesign','data-redesign="vc5f"','assets/css/virtual-cabinet.css','id="vc3LocationPanel"','id="vc3LocationToggle"','class="vc5-custody-select"','id="vc3Custody"','id="vc3LocationSearch"','id="vc3List"','id="vc3Path"','Physical location','includes/physical_record_profile.php','includes/storage_location_manager.php'],
        'assets/css/virtual-cabinet.css'=>['.cabinet-redesign','height:calc(100dvh - 68px)!important','min-height:0!important','margin-top:auto','.vc3-workspace.is-location-collapsed','.vc5-custody-select','.vc5-empty>i{display:flex','margin:0 auto 12px','@media(max-width:767px)','height:calc(100dvh - 154px)','.vc5-metrics{display:none}','#vc3ExportForm{display:none!important','@media(max-width:700px)','grid-template-columns:minmax(0,1fr) minmax(106px,34%)','.vc3-list td .vc5-record-text small{display:none}','@media(max-width:480px)','#physicalRecordProfile [hidden]','#storageLocationManager [hidden]'],
        'assets/js/physical-cabinet.js'=>["root.dataset.redesign!=='vc5f'",'matchMedia(\'(max-width: 1399px)\')','setLocationPanel','finishLocationChoice',"el('Custody').addEventListener('change'",'dataset.label=\'Location\'','vc5-record','cell.colSpan=4','LocationSearch','syncExport','physical-copy-updated','Content-Disposition','URL.createObjectURL'],
    ];
    foreach($required as $file=>$markers){
        $path=$root.'/'.$file;if(!is_file($path))throw new RuntimeException('Missing file: '.$file);
        $source=file_get_contents($path);foreach($markers as $marker)if(!str_contains($source,$marker))throw new RuntimeException('Incomplete VC5F file: '.$file.' ('.$marker.')');
    }
    $page=file_get_contents($root.'/virtual_cabinet.php');
    preg_match_all('/\bid="([^"]+)"/',$page,$matches);if(count($matches[1])!==count(array_unique($matches[1])))throw new RuntimeException('Duplicate IDs found in the cabinet page.');
    foreach(['vc3Workspace','vc3LocationPanel','vc3LocationToggle','vc3Tree','vc3Search','vc3Custody','vc3Clear','vc3Refresh','vc3ExportForm','vc3Export','vc3Rows','vc3Count','vc3Page','vc3Prev','vc3Next'] as $id)if(!in_array($id,$matches[1],true))throw new RuntimeException('Missing existing control: '.$id);
    if(substr_count($page,'<th scope="col">')!==4)throw new RuntimeException('Install the four-column cabinet table and matching JavaScript together.');
    if(substr_count($page,'id="vc3Custody"')!==1 || substr_count($page,'<option value=')!==5)throw new RuntimeException('The single custody dropdown and its five choices must be installed.');
    if(str_contains($page,'role="tablist"') || str_contains($page,'data-custody='))throw new RuntimeException('The retired custody tabs are still present.');
    $pipes=[];$process=proc_open([PHP_BINARY,'-l',$root.'/virtual_cabinet.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not run the PHP syntax check.');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($output);
    echo "PASS: redesigned page, scoped stylesheet, matching script, preserved controls and PHP syntax.\n";
    $fa=file_get_contents($root.'/assets/css/all.min.css');
    foreach(['archive','layer-group','folder','file-alt','certificate','hand-holding','sliders-h','search','download','sync-alt','chevron-left','chevron-right','building','door-open','box','inbox','arrow-right','hourglass-half','exclamation-circle'] as $name)if(!str_contains($fa,'.fa-'.$name))throw new RuntimeException('Required installed icon definition missing: '.$name);
    echo "PASS: cabinet icon definitions exist in the installed icon library.\n";
    require $root.'/config/maintenance_db.php';require $root.'/config/physical_records.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    drms_copy_custody_ready($conn);drms_copy_disposal_ready($conn);
    echo "PASS: existing physical filing/custody/disposal schema remains ready.\n";
    echo "VC5F verification passed. Read-only; no records, files, locations or permissions changed.\n";
    echo "This checks installation and schema. Browser layout/function tests are documented separately.\n";
}catch(Throwable $error){$exit=1;fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");}
finally{if($conn instanceof mysqli){try{$conn->rollback();}catch(Throwable $ignored){}$conn->close();}}
exit($exit);
