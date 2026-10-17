<!--/public_html/modules/pharmacy/ajax/get_stores_for_sale.php  -->
<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])){
    exit;
}

if(!checkPermission('pharmacy_sales','view')){
    exit;
}
$branch_id=(int)($_GET['branch_id']??0);
echo '<option value="">Select Store</option>';
if($branch_id<=0){
    exit;
}
$stmt=$conn->prepare("
SELECT id,store_name
FROM stores
WHERE branch_id=?
AND status='Active'
ORDER BY store_name ASC
");
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$result=$stmt->get_result();
while($store=$result->fetch_assoc()){
    echo '<option value="'.$store['id'].'">'
    .htmlspecialchars($store['store_name'])
    .'</option>';
}