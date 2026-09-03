<?php
declare(strict_types=1);
ini_set('display_errors','0');
ob_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/physical_records.php';

function drms_inventory_export_error(int $status,string $message): never
{
    if(ob_get_level()>0)ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok'=>false,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST') {
    header('Allow: POST');
    drms_inventory_export_error(405,'Method not allowed.');
}

$userId=filter_var($_SESSION['user_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if($userId===false || $userId===null) drms_inventory_export_error(401,'Authentication is required.');
$sessionToken=(string)($_SESSION['csrf_token'] ?? '');
$requestToken=is_string($_POST['csrf_token'] ?? null)?(string)$_POST['csrf_token']:'';
if($sessionToken==='' || $requestToken==='' || !hash_equals($sessionToken,$requestToken)) drms_inventory_export_error(403,'The request security token is invalid. Refresh the page and try again.');

$inSnapshot=false;$output=null;
try {
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $inSnapshot=true;
    $actor=drms_copy_actor($conn,(int)$userId);
    $inventory=drms_copy_inventory_export($conn,$actor,$_POST);
    $conn->rollback();$inSnapshot=false;
    $count=(int)$inventory['total'];
    // Finish preparation before sending download headers. No partial CSV on failure.
    $output=fopen('php://temp/maxmemory:2097152','w+b');
    if($output===false) throw new RuntimeException('The export stream could not be opened.');
    if(fwrite($output,"\xEF\xBB\xBF")!==3)throw new RuntimeException('Could not write the CSV header.');
    $written=fputcsv($output,[
        'Record Number','Document Name','Classification','Record Phase','Lifecycle',
        'Digital Disposition','Physical Location','Physical Copy Status','Custody Position',
        'Current Holder','Expected Return','Last Physical Update'
    ],',','"','',"\r\n");
    if($written===false)throw new RuntimeException('Could not write the CSV columns.');
    foreach($inventory['rows'] as $row) {
        $values=[
            $row['record_number'] ?: 'Not assigned',
            $row['file_name'],
            $row['category'] ?: 'Unclassified',
            $row['record_phase'] ?: 'Working',
            $row['lifecycle_status'] ?: 'Active',
            $row['disposition_status'] ?: 'Retained',
            $row['full_physical_path'] ?: 'Unassigned location',
            $row['physical_status'] ?: 'Not recorded',
            $row['custody_position'],
            $row['physical_status']==='Borrowed' ? ($row['current_holder_name'] ?: 'Unavailable') : '',
            $row['physical_status']==='Borrowed' ? ($row['expected_return_date'] ?: '') : '',
            $row['last_updated'] ?: '',
        ];
        if(fputcsv($output,array_map('drms_copy_csv_cell',$values),',','"','',"\r\n")===false)throw new RuntimeException('Could not write an inventory row.');
    }
    if(!rewind($output))throw new RuntimeException('Could not prepare the CSV stream.');
    $auditId=log_audit_action(
        $conn,
        (int)$userId,
        'EXPORT_PHYSICAL_INVENTORY',
        'Prepared '.number_format($count).' accessible physical inventory record'.($count===1?'':'s').' for CSV export from Virtual Cabinet.',
        null,
        ['record_count'=>$count,'filters'=>$inventory['filters']]
    );
    if(!$auditId)throw new RuntimeException('The inventory export audit could not be saved.');
    $filename='physical_inventory_'.date('Y-m-d_His').'.csv';
    if(ob_get_level()>0)ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    fpassthru($output);
    fclose($output);
    exit;
} catch(DrmsStorageError $error) {
    if($inSnapshot)$conn->rollback();
    if(is_resource($output))fclose($output);
    $status=$error->getCode();
    drms_inventory_export_error($status>=400 && $status<=599?$status:400,$error->getMessage());
} catch(Throwable $error) {
    if($inSnapshot)$conn->rollback();
    if(is_resource($output))fclose($output);
    error_log('Physical inventory export failed: '.$error->getMessage());
    drms_inventory_export_error(500,'The physical inventory could not be exported right now.');
}
