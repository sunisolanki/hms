<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$branch_id=(int)$_GET['branch_id'];
$res=$conn->query("
SELECT
id,
store_name
FROM stores
WHERE branch_id='$branch_id'
AND status=1
ORDER BY store_name ASC
");
$data=[];
while($row=$res->fetch_assoc()){
$data[]=$row;
}
echo json_encode($data);
?>
