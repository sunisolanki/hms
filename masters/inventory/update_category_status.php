<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$id=$_POST['id'];
$status=$_POST['status'];

$stmt=$conn->prepare("UPDATE category_master SET status=? WHERE id=?");
$stmt->bind_param("si",$status,$id);
$stmt->execute();