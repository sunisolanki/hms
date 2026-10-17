<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $id = $_POST['id'] ?? '';
    $status = $_POST['status'] ?? '';

    if(!empty($id) && !empty($status)){

        $stmt = $conn->prepare("UPDATE party_master SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();

        echo "success";
    } else {
        echo "invalid";
    }
}