<?php
session_start();

require_once __DIR__.'/../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once __DIR__.'/includes/init.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}

if(!checkPermission('swbilling','view')){
    header("Location:/access_denied.php");
    exit;
}

$user_id=(int)$_SESSION['user_id'];
$isSuperAdmin=((int)$_SESSION['rights_type_id']===1);

$hospitalFilter=(int)($_GET['hospital_id'] ?? 0);
$statusFilter=trim($_GET['payment_status'] ?? '');
$billingFilter=trim($_GET['billing_type'] ?? '');
$search=trim($_GET['search'] ?? '');

$hospitals=[];

if($isSuperAdmin){

    $qry=mysqli_query($conn,"
        SELECT
            id,
            hospital_name
        FROM hospital_master
        WHERE status=1
        ORDER BY hospital_name
    ");

}else{

    $stmt=$conn->prepare("
        SELECT
            hm.id,
            hm.hospital_name
        FROM hospital_master hm
        INNER JOIN user_hospitals uh
            ON hm.id=uh.hospital_id
        WHERE
            uh.user_id=?
        AND hm.status=1
        ORDER BY hm.hospital_name
    ");

    $stmt->bind_param("i",$user_id);
    $stmt->execute();
    $qry=$stmt->get_result();

}

while($row=mysqli_fetch_assoc($qry)){
    $hospitals[]=$row;
}

$where=[];
$params=[];
$types='';

if(!$isSuperAdmin){

    $where[]="ss.hospital_id IN(
        SELECT hospital_id
        FROM user_hospitals
        WHERE user_id=?
    )";

    $types.="i";
    $params[]=$user_id;

}

if($hospitalFilter>0){

    $where[]="ss.hospital_id=?";

    $types.="i";
    $params[]=$hospitalFilter;

}

if($statusFilter!=''){

    $where[]="si.payment_status=?";

    $types.="s";
    $params[]=$statusFilter;

}

if($billingFilter!=''){

    $where[]="si.billing_type=?";

    $types.="s";
    $params[]=$billingFilter;

}

if($search!=''){

    $where[]="(
        si.invoice_no LIKE ?
        OR hm.hospital_name LIKE ?
        OR ss.plan_name LIKE ?
    )";

    $like="%".$search."%";

    $types.="sss";

    $params[]=$like;
    $params[]=$like;
    $params[]=$like;

}

$whereSQL='';

if(count($where)>0){
    $whereSQL=" WHERE ".implode(" AND ",$where);
}

$sql="
SELECT

    si.*,

    ss.plan_name,

    hm.hospital_name

FROM software_invoices si

INNER JOIN software_subscriptions ss
    ON si.subscription_id=ss.id

INNER JOIN hospital_master hm
    ON hm.id=ss.hospital_id

$whereSQL

ORDER BY
    si.invoice_date DESC,
    si.id DESC
";

if($types!=''){

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        die($conn->error);
    }

    $stmt->bind_param($types,...$params);

    $stmt->execute();

    $res=$stmt->get_result();

}else{

    $res=mysqli_query($conn,$sql);

}

?>
<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>Software Invoices</title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">

<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<link rel="stylesheet" href="/assets/css/style.css">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

.filter-bar{
display:flex;
gap:10px;
flex-wrap:wrap;
margin:15px 0;
align-items:center;
}

.filter-bar select,
.filter-bar input{
padding:8px;
min-width:180px;
}

</style>

</head>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<body>

<div class="top-bar">

<div class="breadcrumb">

<a href="/dashboard/index.php">Home</a>

<span>&gt;</span>

<a href="/swbilling/index.php">Software Billing</a>

<span>&gt;</span>

<a>Software Invoices</a>

</div>

<h2 style="margin:0 auto;">Software Invoices</h2>

<?php if(checkPermission('swbilling','add')){ ?>

<button
class="btn btn-add"
onclick="window.location='invoice_view.php'">

+ New Invoice

</button>

<?php } ?>

</div>

<form method="GET">

<div class="filter-bar">

<select name="hospital_id">

<option value="0">All Hospitals</option>

<?php foreach($hospitals as $hospital){ ?>

<option
value="<?= $hospital['id']; ?>"
<?= $hospitalFilter==$hospital['id']?'selected':''; ?>>

<?= htmlspecialchars($hospital['hospital_name']); ?>

</option>

<?php } ?>

</select>

<select name="payment_status">

<option value="">Payment Status</option>

<option value="Pending" <?= $statusFilter=='Pending'?'selected':''; ?>>Pending</option>

<option value="Partial" <?= $statusFilter=='Partial'?'selected':''; ?>>Partial</option>

<option value="Paid" <?= $statusFilter=='Paid'?'selected':''; ?>>Paid</option>

<option value="Cancelled" <?= $statusFilter=='Cancelled'?'selected':''; ?>>Cancelled</option>

</select>

<select name="billing_type">

<option value="">Billing Type</option>

<option value="Monthly" <?= $billingFilter=='Monthly'?'selected':''; ?>>Monthly</option>

<option value="Annual" <?= $billingFilter=='Annual'?'selected':''; ?>>Annual</option>

<option value="One Time" <?= $billingFilter=='One Time'?'selected':''; ?>>One Time</option>

<option value="AMC" <?= $billingFilter=='AMC'?'selected':''; ?>>AMC</option>

</select>

<input
type="text"
name="search"
placeholder="Invoice No / Hospital / Plan"
value="<?= htmlspecialchars($search); ?>">

<button class="btn">
<i class="fa fa-search"></i>
Search
</button>

</div>

</form>

<div class="table-responsive">

<table>

<thead>

<tr>

<th>#</th>

<th>Invoice No</th>

<th>Hospital</th>

<th>Plan</th>

<th>Billing Type</th>

<th>Invoice Date</th>

<th>Period</th>

<th>Total</th>

<th>Paid</th>

<th>Balance</th>

<th>Status</th>

<th>Action</th>

</tr>

</thead>

<tbody>
    <?php

$i=1;

if($res && mysqli_num_rows($res)>0){

while($row=mysqli_fetch_assoc($res)){

$statusClass='badge-secondary';

if($row['payment_status']=='Pending'){
    $statusClass='badge-warning';
}elseif($row['payment_status']=='Partial'){
    $statusClass='badge-primary';
}elseif($row['payment_status']=='Paid'){
    $statusClass='badge-success';
}elseif($row['payment_status']=='Cancelled'){
    $statusClass='badge-danger';
}

?>

<tr>

<td><?= $i++; ?></td>

<td>
<strong>
<?= htmlspecialchars($row['invoice_no']); ?>
</strong>
</td>

<td>
<?= htmlspecialchars($row['hospital_name']); ?>
</td>

<td>
<?= htmlspecialchars($row['plan_name']); ?>
</td>

<td>
<?= htmlspecialchars($row['billing_type']); ?>
</td>

<td>

<?= !empty($row['invoice_date'])
? date('d-M-Y',strtotime($row['invoice_date']))
: '-'; ?>

</td>

<td>

<?= !empty($row['billing_from'])
? date('d-M-Y',strtotime($row['billing_from']))
: '-'; ?>

<br>

<small>

to

<?= !empty($row['billing_to'])
? date('d-M-Y',strtotime($row['billing_to']))
: '-'; ?>

</small>

</td>

<td>

₹ <?= number_format((float)$row['total_amount'],2); ?>

</td>

<td>

₹ <?= number_format((float)$row['paid_amount'],2); ?>

</td>

<td>

₹ <?= number_format((float)$row['balance_amount'],2); ?>

</td>

<td>

<span class="badge <?= $statusClass; ?>">

<?= htmlspecialchars($row['payment_status']); ?>

</span>

</td>

<td class="action-buttons">

<a
href="invoice_view.php?id=<?= $row['id']; ?>"
class="btn-icon btn-edit"
title="View">

<i class="fa-solid fa-eye"></i>

</a>

<?php if(checkPermission('swbilling','edit')){ ?>

<a
href="invoice_view.php?id=<?= $row['id']; ?>&edit=1"
class="btn-icon btn-edit"
title="Edit">

<i class="fa-solid fa-pen-to-square"></i>

</a>

<a
href="payments.php?invoice_id=<?= $row['id']; ?>"
class="btn-icon btn-success"
title="Receive Payment">

<i class="fa-solid fa-money-bill-wave"></i>

</a>

<?php } ?>

<a
href="invoice_print.php?id=<?= $row['id']; ?>"
class="btn-icon btn-primary"
target="_blank"
title="Print">

<i class="fa-solid fa-print"></i>

</a>

<?php if(checkPermission('swbilling','delete')){ ?>

<button
type="button"
class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id']; ?>)">

<i class="fa-solid fa-trash"></i>

</button>

<?php } ?>

</td>

</tr>

<?php

}

}else{

?>

<tr>

<td colspan="12" align="center">

No invoices found

</td>

</tr>

<?php

}

?>

</tbody>

</table>

</div>

<div id="deleteConfirm" class="confirm-modal">

<div class="confirm-box">

<p>

Are you sure you want to delete this invoice?

</p>

<small>

This action cannot be undone.

</small>

<div class="confirm-actions">

<button
class="btn-cancel"
onclick="closeDelete()">

Cancel

</button>

<button
class="btn-confirm"
id="confirmDeleteBtn">
Delete
</button>
</div>
</div>
</div>
<script>
let deleteId=null;
function confirmDelete(id){
    deleteId=id;
    document.getElementById("deleteConfirm").style.display="flex";
}
function closeDelete(){
    deleteId=null;
    document.getElementById("deleteConfirm").style.display="none";
}
document.addEventListener("DOMContentLoaded",function(){
    let btn=document.getElementById("confirmDeleteBtn");
    if(btn){
        btn.onclick=function(){
            if(deleteId){
                window.location.href="invoices.php?delete="+deleteId;
            }
        };
    }
});
</script>
<?php if(isset($_SESSION['success_message'])){ ?>
<div class="success-message">
<?= htmlspecialchars($_SESSION['success_message']); ?>
</div>
<?php unset($_SESSION['success_message']); } ?>
<?php if(isset($_SESSION['error_message'])){ ?>
<div class="error-message">
<?= htmlspecialchars($_SESSION['error_message']); ?>
</div>
<?php unset($_SESSION['error_message']); } ?>
</body>
</html>