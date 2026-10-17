<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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
if(!$data){
    die(
        'SQL Error : '
        . $conn->error
    );
}
if($data->num_rows == 0){
    die('No Record Found');
}
$header = $data->fetch_assoc();
mysqli_data_seek($data,0);
?>
<style>
.popup-wrapper{
    padding:5px;
}
.info-table{
    width:100%;
    border-collapse:collapse;
}
.info-table th,
.info-table td{
    border:1px solid #ddd;
    padding:10px;
    text-align:left;
}
.info-table th{
    background:#f5f5f5;
    width:220px;
}
.item-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
}
.item-table th,
.item-table td{
    border:1px solid #ddd;
    padding:8px;
    text-align:left;
    white-space:nowrap;
}
.item-table th{
    background:#1976d2;
    color:#fff;
    position:sticky;
    top:0;
}
.badge{
    background:green;
    color:#fff;
    padding:4px 8px;
    border-radius:5px;
    font-size:12px;
}
.section-title{
    margin-top:25px;
    margin-bottom:10px;
    color:#1976d2;
    font-size:18px;
    font-weight:bold;
}
</style>

<div class="popup-wrapper">

<h2 style="
color:#1976d2;
text-align:center;
">

📦 Material Receipt Details

</h2>
<!-- HEADER DETAILS -->
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
<th>Supplier</th>
<td><?= htmlspecialchars($header['party_name']) ?></td>
</tr>
<tr>
<th>Bill No</th>
<td><?= htmlspecialchars($header['bill_no']) ?></td>
<th>Bill Date</th>
<td><?= $header['bill_date'] ?></td>
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
<td><?= htmlspecialchars($header['mode_of_entry']) ?></td>
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
<span class="badge">
<?= $header['mrn_status'] ?>
</span>
</td>
</tr>
<tr>
<th>Remarks</th>
<td colspan="3">
<?= nl2br(htmlspecialchars($header['remarks'])) ?>
</td>
</tr>
<tr>
<th>Created By</th>
<td>
<?= htmlspecialchars($header['created_by_name']) ?>
</td>
<th>Created At</th>
<td>
<?= $header['created_at'] ?>
</td>
</tr>
</table>
<div class="section-title">
📦 Item Details
</div>
<div style="
overflow:auto;
max-height:500px;
border:1px solid #ddd;
">
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
<td><?= $row['qty_received'] ?></td>
<td><?= $row['free_qty'] ?></td>
<td><?= $row['qty_available'] ?></td>
<td><?= $row['pack'] ?></td>
<td><?= $row['mrp'] ?></td>
<td><?= $row['trade_rate'] ?></td>
<td><?= $row['scheme'] ?></td>
<td><?= $row['bill_rate'] ?></td>
<td><?= $row['discount_percent'] ?? 0 ?>%</td>
<td><?= $row['gst_percent'] ?>%</td>
<td><?= $row['net_amount'] ?></td>
<td><?= $row['mfg_date'] ?></td>
<td><?= $row['exp_date'] ?></td>
<td>
<?= htmlspecialchars($row['manufacturer_name'] ?? '') ?>
</td>
</tr>
<?php } ?>
</tbody>
<?php
$roundedTotal =
round($grandTotal);
$roundOff =
$roundedTotal - $grandTotal;
?>
<?php
$roundedTotal =
round($grandTotal);
$roundOff =
$roundedTotal - $grandTotal;
?>
<tfoot>
<tr style="background:#f5f5f5;font-weight:bold;">
<td colspan="14" style="text-align:right;">
Sub Total
</td>
<td colspan="4">
<?= number_format($subTotal,2) ?>
</td>
</tr>
<tr style="background:#f5f5f5;font-weight:bold;">
<td colspan="14" style="text-align:right;">
Total Discount
</td>
<td colspan="4">
<?= number_format($totalDiscount,2) ?>
</td>
</tr>
<tr style="background:#f5f5f5;font-weight:bold;">
<td colspan="14" style="text-align:right;">
Total Tax
</td>
<td colspan="4">
<?= number_format($totalTax,2) ?>
</td>
</tr>
<tr style="background:#f5f5f5;font-weight:bold;">
<td colspan="14" style="text-align:right;">
Round Off
</td>
<td colspan="4">
<?= number_format($roundOff,2) ?>
</td>
</tr>
<tr style="
background:#1976d2;
color:#fff;
font-weight:bold;
">
<td colspan="14" style="text-align:right;">
Grand Total
</td>
<td colspan="4">
<?= number_format($roundedTotal,2) ?>
</td>
</tr>
</tfoot>
</table>
</div>
</div>