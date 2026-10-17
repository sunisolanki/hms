<?php

ini_set('display_errors',1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$id = $_POST['id'] ?? '';
$status = $_POST['status'] ?? '';

if($id != '' && $status != ''){

    $stmt = $conn->prepare("
    UPDATE item_master
    SET status=?
    WHERE id=?
    ");

    $stmt->bind_param("si",$status,$id);

    if($stmt->execute()){
        echo "success";
    }else{
        echo "failed";
    }

}else{

    echo "invalid";
}