<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$item_id = $_GET['item_id'];

$query = "
SELECT
    i.id,
    i.item_name,
    i.packing,
    i.discount_percent,
    i.discount_wef_date,
    h.gst_percentage
FROM item_master i
LEFT JOIN hsn_master h
ON h.id = i.hsn_id
WHERE i.id='$item_id'
LIMIT 1
";

$data = $conn->query($query);
$row = $data->fetch_assoc();

// Check if discount is applicable
$discount = 0;
if(!empty($row['discount_percent']) && !empty($row['discount_wef_date'])){
    if(strtotime(date('Y-m-d')) >= strtotime($row['discount_wef_date'])){
        $discount = $row['discount_percent'];
    }
}

echo json_encode([
    'gst_percent' => $row['gst_percentage'],
    'discount_percent' => $discount
]);