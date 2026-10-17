<!--public_html/modules/pharmacy/ajax/get_sale_batches.php  -->
<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
header('Content-Type: application/json');

if(!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])){
    echo json_encode([
        'status'=>false,
        'batches'=>[]
    ]);
    exit;
}

if(!checkPermission('pharmacy_sales','view')){
    echo json_encode([
        'status'=>false,
        'batches'=>[]
    ]);
    exit;
}

$store_id=(int)($_GET['store_id']??0);
$item_id=(int)($_GET['item_id']??0);

if($store_id<=0||$item_id<=0){
    echo json_encode([
        'status'=>false,
        'batches'=>[]
    ]);
    exit;
}

$sql="
SELECT
    id AS batch_id,
    mrn_no,
    batch_no,
    exp_date,
    qty_available,
    mrp,
    trade_rate,
    bill_rate,
    gst_percent,
    discount_percent
FROM inventory_batches
WHERE store_id=?
AND item_id=?
AND status='Active'
AND qty_available>0
ORDER BY exp_date ASC,id ASC
";

$stmt=$conn->prepare($sql);
$stmt->bind_param(
    "ii",
    $store_id,
    $item_id
);
$stmt->execute();

$result=$stmt->get_result();
$batches=[];

while($row=$result->fetch_assoc()){
    $batches[]=$row;
}

echo json_encode([
    'status'=>true,
    'batches'=>$batches
]);