<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];
/* HEADER */
$mrn_no = 'MRN'.time();
$mrn_type = $_POST['mrn_type'];
$supplier_id = $_POST['supplier_id'];
$mrn_against = $_POST['mrn_against'];
$bill_no = $_POST['bill_no'];
$bill_date = $_POST['bill_date'];
$mode_of_entry = $_POST['mode_of_entry'];
$hospital_id = !empty($_POST['hospital_id'])
? $_POST['hospital_id']
: NULL;
$branch_id = $_POST['branch_id'];
$store_id = $_POST['store_id'];
$setupCheck=$conn->query("
SELECT inspection_required
FROM inventory_store_setup
WHERE hospital_id='$hospital_id'
AND branch_id='$branch_id'
AND store_id='$store_id'
LIMIT 1
");
$mrn_status='Inspection Approved';
if($setupCheck->num_rows>0){
$setupData=$setupCheck->fetch_assoc();
if($setupData['inspection_required']==1){
$mrn_status='MRN Submitted';
}
}
$gst_type = $_POST['gst_type'];
$exempted = isset($_POST['exempted']) ? 1 : 0;
$remarks = $_POST['remarks'];
/* ITEMS */
$item_ids = $_POST['item_id'];
foreach($item_ids as $key => $item_id){
    if(empty($item_id)){
        continue;
    }
    $qty_received =
    $_POST['qty_received'][$key];
    $free_qty =
    !empty($_POST['free_qty'][$key])
    ? $_POST['free_qty'][$key]
    : 0;
    $qty_available =
    $qty_received + $free_qty;
    $pack =
    $_POST['pack'][$key];
    $batch_no =
    $_POST['batch_no'][$key];
    $barcode_no =
    $_POST['barcode_no'][$key];
    $mrp =
    $_POST['mrp'][$key];
    $trade_rate =
    $_POST['trade_rate'][$key];
    $scheme =
    $_POST['scheme'][$key];
    $bill_rate =
    $_POST['bill_rate'][$key];
    $mfg_date =
    !empty($_POST['mfg_date'][$key])
    ? $_POST['mfg_date'][$key]
    : NULL;
    $exp_date =
    !empty($_POST['exp_date'][$key])
    ? $_POST['exp_date'][$key]
    : NULL;
    $discount_percent =
    !empty($_POST['discount_percent'][$key])
    ? $_POST['discount_percent'][$key]
    : 0;
    $gst_percent =
    !empty($_POST['gst_percent'][$key])
    ? $_POST['gst_percent'][$key]
    : 0;
    $net_amount =
    !empty($_POST['net_amount'][$key])
    ? $_POST['net_amount'][$key]
    : 0;
    $manufacturer_name =
    $_POST['manufacturer_name'][$key];
    /* DUPLICATE BATCH CHECK */
    $check = $conn->query("
        SELECT id
        FROM inventory_batches
        WHERE item_id='$item_id'
        AND batch_no='$batch_no'
        AND store_id='$store_id'
        LIMIT 1
    ");
    if($check->num_rows > 0){
        die("
        Duplicate Batch Found:
        Item already exists with same batch
        in this store.
        ");
    }
    /* INSERT */
    $sql = "
    INSERT INTO inventory_batches (
        mrn_no,
        mrn_type,
        mrn_against,
        supplier_id,
        bill_no,
        bill_date,
        hospital_id,
        branch_id,
        store_id,
        item_id,
        qty_received,
        free_qty,
        qty_available,
        pack,
        batch_no,
        barcode_no,
        mrp,
        trade_rate,
        scheme,
        bill_rate,
        mfg_date,
        exp_date,
        mode_of_entry,
        gst_type,
        exempted,
        discount_percent,
        gst_percent,
        net_amount,
        manufacturer_name,
        remarks,
        mrn_status,
        created_by
    ) VALUES (
        '$mrn_no',
        '$mrn_type',
        '$mrn_against',
        '$supplier_id',
        '$bill_no',
        '$bill_date',
        ".($hospital_id ? "'$hospital_id'" : "NULL").",
        '$branch_id',
        '$store_id',
        '$item_id',
        '$qty_received',
        '$free_qty',
        '$qty_available',
        '$pack',
        '$batch_no',
        '$barcode_no',
        '$mrp',
        '$trade_rate',
        '$scheme',
        '$bill_rate',
        ".($mfg_date ? "'$mfg_date'" : "NULL").",
        ".($exp_date ? "'$exp_date'" : "NULL").",
        '$mode_of_entry',
        '$gst_type',
        '$exempted',
        '$discount_percent',
        '$gst_percent',
        '$net_amount',
        '$manufacturer_name',
        '$remarks',
        '$mrn_status',
        '$user_id'
    )
    ";
    $conn->query($sql);
}
header('Location:index.php?success=1');
exit;
?>