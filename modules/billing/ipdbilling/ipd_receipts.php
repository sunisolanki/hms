<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
ini_set('display_errors',1);
error_reporting(E_ALL);
if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}
$current_user_id=(int)$_SESSION['user_id'];
/*=====================================
DATE FILTER
=====================================*/
$selected_date=$_GET['date'] ?? date('Y-m-d');
$prev_date=date(
    'Y-m-d',
    strtotime($selected_date.' -1 day')
);
$next_date=date(
    'Y-m-d',
    strtotime($selected_date.' +1 day')
);
/*=====================================
SEARCH
=====================================*/
$search=trim($_GET['search'] ?? '');
/*=====================================
WHERE
=====================================*/
$where="";
if(!isset($_GET['all'])){
    $where.="
    AND pay.payment_date >= '$selected_date 00:00:00'
    AND pay.payment_date < DATE_ADD('$selected_date',INTERVAL 1 DAY)
    ";
}
if($search!=''){
    $search=mysqli_real_escape_string(
        $conn,
        $search
    );

    $where.="
    AND
    (
        pay.payment_no LIKE '%$search%'
        OR
        pay.ipd_no LIKE '%$search%'
        OR
        ib.bill_no LIKE '%$search%'
        OR
        pm.uhid LIKE '%$search%'
        OR
        pm.mobile LIKE '%$search%'
        OR
        CONCAT(
            pm.first_name,
            ' ',
            pm.last_name
        ) LIKE '%$search%'
    )
    ";

}

/*=====================================
IPD RECEIPT QUERY
=====================================*/

$query="

SELECT

pay.id,
pay.ipd_bill_id,
pay.ipd_admission_id,
pay.patient_id,
pay.ipd_no,
pay.payment_no,
pay.payment_date,
pay.payment_amount,
pay.payment_type,
pay.payment_mode,
pay.transaction_no,
pay.payment_details,
pay.remarks,
pay.created_by,
pay.created_at,
ia.appointment_id,
ia.doctor_id,
ia.department_id,
ia.status AS admission_status,

ib.bill_no,
ib.bill_date,
ib.balance_amount,
receipt_items.service_names AS service_name,
receipt_items.service_types,
pm.uhid,
pm.title,
pm.first_name,
pm.last_name,
pm.mobile,

u.full_name AS doctor_name,

d.department_name,

hm.hospital_name,

bm.branch_name

FROM ipd_payments pay

INNER JOIN ipd_admissions ia
ON ia.id=pay.ipd_admission_id

LEFT JOIN ipd_bills ib
ON ib.id = COALESCE(
    pay.ipd_bill_id,
    (
        SELECT b.id
        FROM ipd_bills b
        WHERE b.ipd_admission_id = pay.ipd_admission_id
        AND b.payment_status <> 'Cancelled'
        ORDER BY b.id DESC
        LIMIT 1
    )
)

LEFT JOIN (
    SELECT
    pi.payment_id,
    GROUP_CONCAT(
        bi.item_name
        ORDER BY pi.id ASC
        SEPARATOR ', '
    ) AS service_names,
    GROUP_CONCAT(
        DISTINCT bi.item_type
        ORDER BY bi.item_type ASC
        SEPARATOR ','
    ) AS service_types
    FROM ipd_payment_items pi
    INNER JOIN ipd_bill_items bi
        ON bi.id=pi.ipd_bill_item_id
    GROUP BY pi.payment_id
) receipt_items
ON receipt_items.payment_id=pay.id

LEFT JOIN patient_master pm
ON pm.id=pay.patient_id

LEFT JOIN users u
ON u.id=ia.doctor_id

LEFT JOIN department_master d
ON d.id=ia.department_id

LEFT JOIN hospital_master hm
ON hm.id=ia.hospital_id

LEFT JOIN branch_master bm
ON bm.id=ia.branch_id

WHERE 1=1

$where

ORDER BY
pay.payment_date DESC,
pay.id DESC
";

$result=mysqli_query(
    $conn,
    $query
);

if(!$result){
    die(mysqli_error($conn));
}
$receipts=[];

while($row=mysqli_fetch_assoc($result)){

    $receipts[]=$row;

}

$receipt_count=count($receipts);
function e($value){

    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );

}

?>
<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>IPD Receipts</title>

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<link
rel="stylesheet"
href="/assets/css/style.css">

<link
rel="icon"
href="/assets/img/hms_logo.png">

<style>

.table-wrap{
background:#fff;
border-radius:10px;
padding:1px;
box-shadow:0 10px 25px rgba(0,0,0,.08);
overflow:auto;
height:620px;
}

table{
width:100%;
border-collapse:collapse;
font-size:13px;
white-space:nowrap;
}

th,
td{
border:1px solid #e5e7eb;
padding:6px 10px;
text-align:left;
}

th{
background:#0b5ed7;
color:#fff;
position:sticky;
top:0;
padding:9px;
}

.search-box{
padding:8px 12px;
width:320px;
border:1px solid #ccc;
border-radius:6px;
}

button{
padding:8px 14px;
cursor:pointer;
}
.action-dropdown{
position:relative;
display:inline-block;
}

.action-btn{
background:#1976d2;
color:#fff;
border:none;
padding:5px 10px;
border-radius:5px;
cursor:pointer;
font-size:16px;
}

.action-btn:hover{
background:#0d5cb6;
}

.action-menu{
display:none;
position:absolute;
left:0;
top:100%;
background:#fff;
min-width:220px;
box-shadow:0 8px 18px rgba(0,0,0,.18);
border-radius:8px;
overflow:hidden;
z-index:9999;
}

.action-menu a{
display:block;
padding:10px 14px;
text-decoration:none;
color:#333;
font-size:13px;
border-bottom:1px solid #eee;
}

.action-menu a:last-child{
border-bottom:none;
}

.action-menu a:hover{
background:#f5f7fb;
}

tbody tr:hover{
background:#f8fbff;
}

#receiptPopup{
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,.55);
z-index:999999;
}

.receipt-popup-inner{
position:absolute;
top:0;
left:0;
width:100%;
height:100%;
background:#fff;
}

.receipt-popup-close{
position:absolute;
top:20px;
right:25px;
z-index:999999;
padding:8px 15px;
background:#d32f2f;
color:#fff;
border:none;
border-radius:5px;
cursor:pointer;
font-size:14px;
}

#receiptFrame{
width:100%;
height:100%;
border:none;
}

@media(max-width:992px){

.table-wrap{
height:auto;
}

}
</style>

</head>

<body>

<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php';
?>

<div
class="main-content"
id="mainContent"
style="width:auto;">
<div class="page-container">

<div
style="
display:flex;
align-items:center;
">

<!-- LEFT -->

<div
style="
flex:1;
text-align:left;
">

<h2 style="margin:0;">

🧾 IPD Receipts

</h2>

</div>

<!-- CENTER -->

<div
style="
flex:1;
text-align:center;
">

<a
href="?date=<?= $prev_date ?>&search=<?= urlencode($search) ?><?= isset($_GET['all'])?'&all=1':'' ?>"
style="
text-decoration:none;
font-size:22px;
font-weight:bold;
padding:0 10px;
">

&#10094;

</a>

<span
id="dateDisplay"
onclick="
document
.getElementById('jumpDate')
.showPicker();
"
style="
font-size:18px;
font-weight:600;
cursor:pointer;
padding:6px 12px;
border-radius:6px;
background:#f3f4f6;
">

<?= date(
    'd M Y',
    strtotime($selected_date)
) ?>

📅

</span>

<a
href="?date=<?= $next_date ?>&search=<?= urlencode($search) ?><?= isset($_GET['all'])?'&all=1':'' ?>"
style="
text-decoration:none;
font-size:22px;
font-weight:bold;
padding:0 10px;
">

&#10095;

</a>

<input
type="date"
id="jumpDate"
value="<?= $selected_date ?>"
style="
position:absolute;
opacity:0;
width:1px;
height:1px;
pointer-events:none;
">

</div>

<!-- SEARCH -->

<div
style="
flex:1;
text-align:center;
">

<form
method="get"
style="
display:flex;
justify-content:center;
align-items:center;
gap:8px;
">

<?php
if(isset($_GET['all'])){
?>

<input
type="hidden"
name="all"
value="1">

<?php
}
?>

<input
type="hidden"
name="date"
value="<?= e($selected_date) ?>">

<input
type="text"
name="search"
value="<?= e($search) ?>"
placeholder="Search Receipt / IPD No / Bill No / UHID / Patient"
class="search-box">

<button
type="submit">

🔍

</button>

</form>

</div>

<!-- RIGHT -->

<div
style="
flex:1;
text-align:right;
">

<?php
if(isset($_GET['all'])){
?>

<button
onclick="
window.location='ipd_receipts.php'
">

📅 Today

</button>

<?php
}else{
?>

<button
onclick="
window.location='ipd_receipts.php?all=1'
">

📋 All

</button>

<?php
}
?>

</div>

</div>

<br>

<div class="table-wrap">

<table>

<thead>

<tr>
<th>Action</th>
<th>Receipt No.</th>
<th>Date</th>
<th>Receipt Type</th>
<th>Service / Particular</th>
<th>IPD Bill No.</th>
<th>IPD No.</th>
<th>UHID</th>
<th>Patient</th>
<th>Doctor</th>
<th>Department</th>
<th>Payment Mode</th>
<th>Receipt Amount</th>
</tr>

</thead>

<tbody>

<?php

foreach($receipts as $row){
$receipt_type=$row['payment_type'] ?? 'Advance';

$receipt_amount=(float)(
    $row['payment_amount'] ?? 0
);

if($receipt_type==='Service'){

    $particular=$row['service_name'] ?? 'Service';

}else{

    $particular='IPD Advance';
}

?>

<tr>

<td>

<div class="action-dropdown">

<button
class="action-btn">

⋮

</button>

<div class="action-menu">

<a
href="#"
onclick="
openPrintReceiptPopup(
<?= (int)$row['id'] ?>,
'<?= e($receipt_type) ?>',
'<?= e($row['service_types'] ?? '') ?>'
);
return false;
">

🖨 Print Receipt

</a>

<?php
if(
    !empty($row['ipd_bill_id'])
){
?>

<a
href="#"
onclick="
openPrintBillPopup(
<?= (int)$row['ipd_bill_id'] ?>
);
return false;
">

👁 View Bill

</a>

<?php
}
?>

<?php
if(
    $receipt_type==='Advance' &&
    $row['ipd_bill_id'] !== null &&
    (float)$row['balance_amount']>0 &&
    checkPermission('ipd_billing','add')
){
?>
<a
href="#"
onclick="
openReceiveAdvancePopup(
<?= (int)$row['ipd_admission_id'] ?>
);
return false;
">
💳 Receive Advance
</a>
<?php
}
?>

</div>

</div>

</td>

<td>

<?= e($row['payment_no']) ?>

</td>

<td>

<?= !empty($row['created_at'])
?
date(
    "d-m-Y",
    strtotime($row['created_at'])
)
:
'-'
?>

<br>

<small>

<?= !empty($row['created_at'])
?
date(
    "h:i A",
    strtotime($row['created_at'])
)
:
'-'
?>

</small>

</td>
<td>

<?php
$service_types=$row['service_types'] ?? '';

if($receipt_type==='Service' && strpos($service_types,'Charge')!==false){
?>

<span
style="
background:#fff3cd;
color:#856404;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
">

Charges

</span>

<?php
}elseif($receipt_type==='Service'){
?>

<span
style="
background:#dbeafe;
color:#1d4ed8;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
">

Service

</span>

<?php
}else{
?>

<span
style="
background:#dcfce7;
color:#166534;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
">

Advance

</span>

<?php
}
?>

</td>

<td>

<?= e($particular) ?>

<?php
if(
    $receipt_type==='Service' &&
    !empty($row['service_quantity'])
){
?>

<br>

<small>

Qty: <?= e($row['service_quantity']) ?>

</small>

<?php
}
?>

</td>
<td>

<?= e(
    $row['bill_no'] ?? '-'
) ?>

</td>

<td>

<?= e(
    $row['ipd_no'] ?? '-'
) ?>

</td>

<td>

<?= e(
    $row['uhid'] ?? '-'
) ?>

</td>

<td>

<b>

<?= e(
    trim(
        ($row['title'] ?? '').
        ' '.
        ($row['first_name'] ?? '').
        ' '.
        ($row['last_name'] ?? '')
    )
) ?>

</b>

<br>

<small>

<?= e(
    $row['mobile'] ?? ''
) ?>

</small>

</td>

<td>

<?= e(
    $row['doctor_name'] ?? '-'
) ?>

</td>

<td>

<?= e(
    $row['department_name'] ?? '-'
) ?>

</td>

<td>

<?= e(
    $row['payment_mode'] ?? '-'
) ?>

</td>
<td align="right">

<span
style="
font-weight:bold;
color:#2e7d32;
">

₹ <?= number_format($receipt_amount,2) ?>

</span>

</td>

</tr>

<?php
}
?>

<?php
if(empty($receipts)){
?>

<tr>

<td
colspan="13"
style="
text-align:center;
padding:30px;
color:#64748b;
">

No IPD receipts found.

</td>

</tr>

<?php
}
?>

</tbody>

</table>

</div>

</div>

</div>

<!-- RECEIPT POPUP -->

<div id="receiptPopup">

<div class="receipt-popup-inner">

<button
class="receipt-popup-close"
onclick="closeReceiptPopup()">

✖

</button>

<iframe
id="receiptFrame">
</iframe>

</div>

</div>

<script>

/*==========================
ACTION MENU
==========================*/

document
.querySelectorAll('.action-btn')
.forEach(function(btn){

btn.addEventListener(
'click',
function(e){

e.stopPropagation();

document
.querySelectorAll('.action-menu')
.forEach(function(menu){

menu.style.display='none';

});

this
.nextElementSibling
.style
.display='block';

});

});

document.addEventListener(
'click',
function(){

document
.querySelectorAll('.action-menu')
.forEach(function(menu){

menu.style.display='none';

});

});

/*==========================
DATE PICKER
==========================*/

document
.getElementById('jumpDate')
.addEventListener(
'change',
function(){

let url=
'ipd_receipts.php?date='+
this.value;

<?php
if($search!=''){
?>

url+='&search=<?= urlencode($search) ?>';

<?php
}
?>

<?php
if(isset($_GET['all'])){
?>

url+='&all=1';

<?php
}
?>

window.location.href=url;

});

/*==========================
PRINT RECEIPT
==========================*/
function openPrintReceiptPopup(
payment_id,
receipt_type,
service_types
){

document
.getElementById('receiptPopup')
.style
.display='block';

let receiptUrl='';

if(receipt_type==='Advance'){

receiptUrl=
'/modules/billing/ipdbilling/ipd_print_receipt.php?payment_id='+
encodeURIComponent(payment_id);

}else if(service_types.includes('Charge')){

receiptUrl=
'/modules/ipd/ajax/receipt_print.php?payment_id='+
encodeURIComponent(payment_id);

}else{

receiptUrl=
'/modules/billing/ipdbilling/ipd_print_service_receipt.php?payment_id='+
encodeURIComponent(payment_id);

}

document
.getElementById('receiptFrame')
.src=receiptUrl;

document.body.style.overflow='hidden';

}
/*==========================
VIEW BILL
==========================*/

function openPrintBillPopup(
bill_id
){

document
.getElementById('receiptPopup')
.style
.display='block';

document
.getElementById('receiptFrame')
.src=
'/modules/billing/ipdbilling/ipd_bill_print.php?bill_id='+
encodeURIComponent(bill_id);

document.body.style.overflow='hidden';

}

/*==========================
RECEIVE ADVANCE
==========================*/

function openReceiveAdvancePopup(
admission_id
){

document
.getElementById('receiptPopup')
.style
.display='block';

document
.getElementById('receiptFrame')
.src=
'/modules/billing/ipdbilling/advance.php?ipd_admission_id='+
encodeURIComponent(admission_id);

document.body.style.overflow='hidden';

}

/*==========================
CLOSE POPUP
==========================*/

function closeReceiptPopup(){

document
.getElementById('receiptPopup')
.style
.display='none';

document
.getElementById('receiptFrame')
.src='';

document.body.style.overflow='auto';

}

</script>

</body>

</html>