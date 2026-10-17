<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$id = (int)$_POST['id'];
$status = $_POST['status'];

$conn->query("UPDATE uom_master SET status='$status' WHERE id=$id");
echo "ok";