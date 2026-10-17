<?php

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$category_id = $_POST['category_id'];

$res = $conn->query("
SELECT *
FROM category_master
WHERE parent_id = '$category_id'
ORDER BY category_name ASC
");

echo '<option value="">Select Sub Category</option>';

while($row = $res->fetch_assoc()){

    echo '<option value="'.$row['id'].'">
            '.$row['category_name'].'
          </option>';
}
?>