<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_GET['mrn_no'])){
    exit('Invalid Request');
}
$mrn_no =
$conn->real_escape_string(
    trim($_GET['mrn_no'])
);
$sql = "
SELECT
ib.*,
pm.party_name,
im.item_name,
s.store_name,
hm.hospital_name,
bm.branch_name,
u.full_name AS created_by_name
FROM inventory_batches ib
LEFT JOIN party_master pm
ON pm.id = ib.supplier_id
LEFT JOIN item_master im
ON im.id = ib.item_id
LEFT JOIN stores s
ON s.id = ib.store_id
LEFT JOIN hospital_master hm
ON hm.id = ib.hospital_id
LEFT JOIN branch_master bm
ON bm.id = ib.branch_id
LEFT JOIN users u
ON u.id = ib.created_by
WHERE ib.mrn_no = '$mrn_no'
ORDER BY ib.id ASC
";
$data = $conn->query($sql);
if(!$data || $data->num_rows == 0){
    exit('No Record Found');
}
$header = $data->fetch_assoc();
mysqli_data_seek($data,0);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>
MRN Print
</title>
<style>
body{
    font-family:Arial,sans-serif;
    margin:0;
    padding:20px;
    color:#000;
    background:#fff;
}
.print-container{
    width:100%;
}
.top-header{
    text-align:center;
    border-bottom:2px solid #000;
    padding-bottom:10px;
    margin-bottom:20px;
}
.top-header h1{
    margin:0;
    font-size:28px;
}
.top-header p{
    margin:5px 0;
    font-size:14px;
}
.invoice-title{
    text-align:center;
    font-size:22px;
    font-weight:bold;
    margin-bottom:20px;
    color:#1976d2;
}
.info-table{
    width:100%;
    border-collapse:collapse;
    margin-bottom:20px;
}
.info-table th,
.info-table td{
    border:1px solid #000;
    padding:8px;
    text-align:left;
    font-size:13px;
}
.info-table th{
    background:#f5f5f5;
    width:180px;
}
.item-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
}
.item-table th,
.item-table td{
    border:1px solid #000;
    padding:8px;
    text-align:center;
    font-size:12px;
}
.item-table th{

    background:#1976d2;

    color:#fff;

}

.footer-box{

    margin-top:20px;

    width:350px;

    margin-left:auto;

}

.total-table{

    width:100%;

    border-collapse:collapse;

}

.total-table td{

    border:1px solid #000;

    padding:8px;

    font-weight:bold;

}

.sign-section{

    margin-top:60px;

    display:flex;

    justify-content:space-between;

}

.sign-box{

    width:200px;

    text-align:center;

}

.sign-line{

    margin-top:60px;

    border-top:1px solid #000;

    padding-top:5px;

}

.print-btn{

    position:fixed;

    top:20px;

    right:20px;

    background:#1976d2;

    color:#fff;

    border:none;

    padding:10px 20px;

    border-radius:5px;

    cursor:pointer;

    font-size:14px;

}

@media print{

    .print-btn{

        display:none;

    }

    body{

        padding:0;

    }

}

</style>

</head>

<body>

<button
class="print-btn"
onclick="window.print()">

🖨 Print

</button>

<div class="print-container">

<div class="top-header">

<h1>

<?= htmlspecialchars($header['hospital_name']) ?>

</h1>

<p>

Inventory Management System

</p>

<p>

Material Receipt Note (MRN)

</p>

</div>

<div class="invoice-title">

📦 MATERIAL RECEIPT NOTE

</div>

<table class="info-table">

<tr>

<th>MRN No</th>
<td><?= htmlspecialchars($header['mrn_no']) ?></td>

<th>MRN Type</th>
<td><?= htmlspecialchars($header['mrn_type']) ?></td>

</tr>

<tr>

<th>MRN Against</th>
<td><?= htmlspecialchars($header['mrn_against']) ?></td>

<th>Bill No</th>
<td><?= htmlspecialchars($header['bill_no']) ?></td>

</tr>

<tr>

<th>Bill Date</th>
<td><?= $header['bill_date'] ?></td>

<th>Supplier</th>
<td><?= htmlspecialchars($header['party_name']) ?></td>

</tr>

<tr>

<th>Hospital</th>
<td><?= htmlspecialchars($header['hospital_name']) ?></td>
<th>Branch</th>
<td><?= htmlspecialchars($header['branch_name']) ?></td>
</tr>
<tr>
<th>Store</th>
<td><?= htmlspecialchars($header['store_name']) ?></td>
<th>GST Type</th>
<td><?= htmlspecialchars($header['gst_type']) ?></td>
</tr>
<tr>
<th>Mode of Entry</th>
<td>
<?= htmlspecialchars($header['mode_of_entry']) ?>
</td>
<th>Exempted</th>
<td>
<?php if($header['exempted']==1){ ?>
Yes
<?php } else { ?>
No
<?php } ?>
</td>
</tr>
<tr>
<th>Status</th>
<td>
<?= htmlspecialchars($header['mrn_status']) ?>
</td>
<th>Created By</th>
<td>
<?= htmlspecialchars($header['created_by_name']) ?>
</td>
</tr>
<tr>
<th>Created At</th>
<td>
<?= $header['created_at'] ?>
</td>
<th></th>
<td></td>
</tr>
<tr>

<th>Remarks</th>

<td colspan="3">

<?= nl2br(htmlspecialchars($header['remarks'])) ?>

</td>

</tr>

</table>

<table class="item-table">

<thead>
<tr>
<th>#</th>
<th>Item</th>
<th>Batch No</th>
<th>Barcode</th>
<th>Qty</th>
<th>Free Qty</th>
<th>Available Qty</th>
<th>Pack</th>
<th>MRP</th>
<th>Trade Rate</th>
<th>Scheme</th>
<th>Bill Rate</th>
<th>Discount %</th>
<th>GST %</th>
<th>Net Amount</th>
<th>Mfg Date</th>
<th>Expiry Date</th>
<th>Manufacturer</th>
</tr>
</thead>
<tbody>
<?php
$sr = 1;
$subTotal = 0;
$totalDiscount = 0;
$totalTax = 0;
$grandTotal = 0;
while($row = $data->fetch_assoc()) {
    $gross =
    $row['bill_rate']
    *
    $row['qty_received'];
    $discountAmount =
    (
        $gross
        *
        $row['discount_percent']
    )
    /
    100;

    $taxable =
    $gross - $discountAmount;

    $gstAmount =
    (
        $taxable
        *
        $row['gst_percent']
    )
    /
    100;

    $subTotal += $gross;

    $totalDiscount += $discountAmount;

    $totalTax += $gstAmount;

    $grandTotal += $row['net_amount'];

?>
<tr>
<td><?= $sr++ ?></td>
<td>
<?= htmlspecialchars($row['item_name']) ?>
</td>
<td>
<?= htmlspecialchars($row['batch_no']) ?>
</td>
<td>
<?= htmlspecialchars($row['barcode_no']) ?>
</td>
<td>
<?= $row['qty_received'] ?>
</td>
<td>
<?= $row['free_qty'] ?>
</td>
<td>
<?= $row['qty_available'] ?>
</td>
<td>
<?= $row['pack'] ?>
</td>
<td>
<?= number_format($row['mrp'],2) ?>
</td>
<td>
<?= number_format($row['trade_rate'],2) ?>
</td>
<td>
<?= htmlspecialchars($row['scheme']) ?>
</td>
<td>
<?= number_format($row['bill_rate'],2) ?>
</td>
<td>
<?= number_format($row['discount_percent'],2) ?>%
</td>
<td>
<?= number_format($row['gst_percent'],2) ?>%
</td>
<td>
<?= number_format($row['net_amount'],2) ?>
</td>
<td>
<?= $row['mfg_date'] ?>
</td>
<td>
<?= $row['exp_date'] ?>
</td>
<td>
<?= htmlspecialchars($row['manufacturer_name']) ?>
</td>
</tr>
<?php } ?>

</tbody>

</table>

<?php

$roundedTotal =
round($grandTotal);

$roundOff =
$roundedTotal - $grandTotal;

?>

<div class="footer-box">

<table class="total-table">

<tr>
<td>Sub Total</td>
<td>

<?= number_format($subTotal,2) ?>

</td>
</tr>

<tr>
<td>Total Discount</td>
<td>

<?= number_format($totalDiscount,2) ?>

</td>
</tr>

<tr>
<td>Total Tax</td>
<td>
<?= number_format($totalTax,2) ?>
</td>
</tr>
<tr>
<td>Round Off</td>
<td>
<?= number_format($roundOff,2) ?>
</td>
</tr>
<tr>
<td>Grand Total</td>
<td>
<?= number_format($roundedTotal,2) ?>
</td>
</tr>
</table>
</div>
<div class="sign-section">
<div class="sign-box">
<div class="sign-line">
Prepared By
</div>
</div>
<div class="sign-box">
<div class="sign-line">
Checked By
</div>
</div>
<div class="sign-box">
<div class="sign-line">
Authorized Signatory
</div>
</div>
</div>
</div>
</body>
</html>