<?php

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$id = $_GET['id'] ?? '';

if($id!=''){

    /* DELETE STORE MAP */

    $conn->query("
    DELETE FROM item_store_mapping
    WHERE item_id='$id'
    ");

    /* DELETE SALT MAP */

    $conn->query("
    DELETE FROM item_salt_mapping
    WHERE item_id='$id'
    ");

    /* DELETE ITEM */

    $conn->query("
    DELETE FROM item_master
    WHERE id='$id'
    ");
}

header("Location:item_master.php");
exit;