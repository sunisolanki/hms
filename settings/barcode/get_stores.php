<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$branch_id = (int)($_GET['branch_id'] ?? 0);

echo '<option value="">Select Store</option>';

$q = mysqli_query($conn,"
    SELECT id, store_name
    FROM stores
    WHERE branch_id='$branch_id'
    AND status=1
    ORDER BY store_name
");

while($row = mysqli_fetch_assoc($q)){

    echo '<option value="'.$row['id'].'">'
        .htmlspecialchars($row['store_name'])
        .'</option>';
}