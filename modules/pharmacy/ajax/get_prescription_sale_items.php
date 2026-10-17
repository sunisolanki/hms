<!--public_html/modules/pharmacy/ajax/get_prescription_sale_items.php  -->
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

$prescription_id=(int)($_GET['prescription_id']??0);

if($prescription_id<=0){
    echo json_encode([
        'status'=>false,
        'items'=>[]
    ]);
    exit;
}

$sql="
SELECT
    pm.id AS prescribed_medicine_id,
    pm.prescription_id,
    pm.item_id,
    pm.qty,
    pm.dispensed_qty,
    (pm.qty-pm.dispensed_qty) AS remaining_qty,
    im.item_name
FROM prescribed_medicines pm
INNER JOIN item_master im
    ON im.id=pm.item_id
WHERE pm.prescription_id=?
AND pm.pharmacy_status!='Cancelled'
AND (pm.qty-pm.dispensed_qty)>0
ORDER BY pm.id ASC
";

$stmt=$conn->prepare($sql);
$stmt->bind_param(
    "i",
    $prescription_id
);
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