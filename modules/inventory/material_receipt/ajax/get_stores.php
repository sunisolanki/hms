<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$branch_id = $_GET['branch_id'];
$data = $conn->query("
    SELECT id, store_name
    FROM stores
    WHERE branch_id='$branch_id'
    AND status='Active'
    ORDER BY store_name ASC
");
echo '<option value="">Select Store</option>';
while($row = $data->fetch_assoc()){
    echo '
    <option value="'.$row['id'].'">
        '.$row['store_name'].'
    </option>
    ';
}
?>