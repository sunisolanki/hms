<!--public_html/modules/pharmacy/ajax/get_sale_items.php  -->
<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
header('Content-Type: application/json');

if(!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])){
    echo json_encode([
        'status'=>false,
        'items'=>[]
    ]);
    exit;
}

if(!checkPermission('pharmacy_sales','view')){
    echo json_encode([
        'status'=>false,
        'items'=>[]
    ]);
    exit;
}

$store_id=(int)($_GET['store_id']??0);

if($store_id<=0){
    echo json_encode([
        'status'=>false,
        'items'=>[]
    ]);
    exit;
}

$sql="
SELECT DISTINCT
    im.id,
    im.item_name
FROM item_master im
INNER JOIN inventory_batches ib
    ON ib.item_id=im.id
WHERE ib.store_id=?
AND ib.status='Active'
AND ib.qty_available>0
AND im.status='Active'
ORDER BY im.item_name ASC
";

$stmt=$conn->prepare($sql);
$stmt->bind_param("i",$store_id);
$stmt->execute();

$result=$stmt->get_result();

$items=[];

while($row=$result->fetch_assoc()){
    $items[]=$row;
}

echo json_encode([
    'status'=>true,
    'items'=>$items
]);