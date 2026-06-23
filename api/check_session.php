<?php
require '../config/db_connect.php'; 

// Kapag nakaabot ang code dito at hindi pinatay ng db_connect.php, 
// ibig sabihin ay valid pa ang session ng user at hindi siya kinick ng Admin.
header('Content-Type: application/json');
echo json_encode(['status' => 'valid']);
?>