<?php
declare(strict_types=1);
ini_set('display_errors','0');ob_start();
require __DIR__.'/../config/db_connect.php';
require_once __DIR__.'/../config/physical_records.php';
function drms_copy_save_response(int $status,array $body): void {
    ob_end_clean();http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new DrmsStorageError('Use the physical-copy form to make a change.',405);
    drms_storage_csrf($_POST['csrf_token']??null,$_SESSION['csrf_token']??null);
    $result=drms_copy_mutate($conn,(int)($_SESSION['user_id']??0),$_POST,(string)($_SERVER['REMOTE_ADDR']??''));
    drms_copy_save_response(200,['ok'=>true]+$result);
}catch(DrmsStorageError $error){drms_copy_save_response($error->getCode()?:400,['ok'=>false,'message'=>$error->getMessage()]);}
catch(Throwable $error){error_log('Physical record save: '.$error->getMessage());drms_copy_save_response(500,['ok'=>false,'message'=>'The physical action could not be completed. Refresh the record before retrying; ask the administrator to check the server log if this continues.']);}
