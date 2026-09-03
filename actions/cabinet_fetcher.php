<?php
declare(strict_types=1);
ini_set('display_errors','0');ob_start();
require __DIR__.'/../config/db_connect.php';
require_once __DIR__.'/../config/physical_records.php';
function drms_copy_response(int $status,array $body): void {
    ob_end_clean();http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')throw new DrmsStorageError('Use GET to read physical records.',405);
    $actor=drms_copy_actor($conn,(int)($_SESSION['user_id']??0));drms_copy_ready($conn);
    $action=drms_storage_text($_GET,'action',30);
    if($action==='directory')$result=drms_copy_directory($conn,$actor);
    elseif($action==='get_documents' || $action==='smart_search')$result=drms_copy_list($conn,$actor,$_GET);
    elseif($action==='get_document_profile') {
        $id=drms_storage_text($_GET,'doc_id',10);if(!ctype_digit($id) || (int)$id<1 || (int)$id>2147483647)throw new DrmsStorageError('Invalid record.',422);
        $result=drms_copy_profile($conn,$actor,(int)$id);
    }else throw new DrmsStorageError('Refresh the Virtual Cabinet to use its current interface.',422);
    drms_copy_response(200,['ok'=>true,'status'=>'success']+$result);
}catch(DrmsStorageError $error){drms_copy_response($error->getCode()?:400,['ok'=>false,'status'=>'error','message'=>$error->getMessage()]);}
catch(Throwable $error){error_log('Physical records read: '.$error->getMessage());drms_copy_response(500,['ok'=>false,'status'=>'error','message'=>'Unable to load physical records. Refresh or ask the administrator to check the server log.']);}
