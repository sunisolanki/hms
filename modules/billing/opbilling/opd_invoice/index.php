<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if(!isset($_SESSION['user_id'])){
header("Location:/login.php");
exit;
}

$search=trim($_GET['search'] ?? '');
$status_filter=trim($_GET['status'] ?? '');

$sql="
SELECT
a.id AS opd_id,
a.opd_no,
a.appointment_date,
a.patient_id,
a.entity_id AS doctor_id,
a.department_id,
COALESCE(a.net_payable,0) AS consultation_total,
COALESCE(a.paid_amount,0) AS consultation_paid,
COALESCE(a.balance_amount,0) AS consultation_pending,
p.uhid,
CONCAT_WS(' ',a.title,a.first_name,a.last_name) AS patient_name,
u.full_name AS doctor_name,
d.department_name,
COALESCE(sb.service_total,0) AS service_total,
COALESCE(sb.service_paid,0) AS service_paid,
COALESCE(sb.service_pending,0) AS service_pending,
(
COALESCE(a.net_payable,0)+
COALESCE(sb.service_total,0)
) AS total_charge,
(
COALESCE(a.paid_amount,0)+
COALESCE(sb.service_paid,0)
) AS total_received,
(
COALESCE(a.balance_amount,0)+
COALESCE(sb.service_pending,0)
) AS total_pending
FROM appointments a
LEFT JOIN patient_master p
ON p.id=a.patient_id
LEFT JOIN users u
ON u.id=a.entity_id
LEFT JOIN department_master d
ON d.id=a.department_id
LEFT JOIN(
SELECT
opd_id,
SUM(
CASE
WHEN status NOT IN('Cancelled','Refunded')
THEN COALESCE(net_payable,0)
ELSE 0
END
) AS service_total,
SUM(
CASE
WHEN status NOT IN('Cancelled','Refunded')
THEN COALESCE(paid_amount,0)
ELSE 0
END
) AS service_paid,
SUM(
CASE
WHEN status NOT IN('Cancelled','Refunded')
THEN COALESCE(balance_amount,0)
ELSE 0
END
) AS service_pending
FROM opd_bills
GROUP BY opd_id
) sb
ON sb.opd_id=a.id
WHERE a.type='doctor'
AND a.opd_no IS NOT NULL
AND a.opd_no!=''
";

$params=[];
$types="";

if($search!=''){
$sql.="
AND(
a.opd_no LIKE ?
OR p.uhid LIKE ?
OR CONCAT_WS(' ',a.title,a.first_name,a.last_name) LIKE ?
OR u.full_name LIKE ?
)
";
$search_like="%".$search."%";
$params[]=$search_like;
$params[]=$search_like;
$params[]=$search_like;
$params[]=$search_like;
$types.="ssss";
}

if($status_filter=='Paid'){
$sql.=" HAVING total_pending<=0";
}elseif($status_filter=='Pending'){
$sql.=" HAVING total_pending>0";
}

$sql.=" ORDER BY a.appointment_date DESC,a.id DESC";

$stmt=$conn->prepare($sql);

if(!$stmt){
die("Query preparation failed: ".htmlspecialchars($conn->error));
}

if(!empty($params)){
$stmt->bind_param($types,...$params);
}

$stmt->execute();
$result=$stmt->get_result();

function e($value){
return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}

$invoice_rows=[];
$total_patients=0;
$total_charge=0;
$total_received=0;
$total_pending=0;

while($row=$result->fetch_assoc()){
$invoice_rows[]=$row;
$total_patients++;
$total_charge+=(float)$row['total_charge'];
$total_received+=(float)$row['total_received'];
$total_pending+=(float)$row['total_pending'];
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>OPD Invoice</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">

<style>
.table-wrap{
background:#fff;
border-radius:10px;
padding:1px;
box-shadow:0 10px 25px rgba(0,0,0,.08);
overflow:auto;
height:535px;
}
table{
width:100%;
border-collapse:collapse;
font-size:13px;
white-space:nowrap;
}
th,td{
border:1px solid #e5e7eb;
padding:8px 10px;
text-align:left;
}
th{
background:#1976d2;
color:#fff;
position:sticky;
top:0;
z-index:10;
}
tbody tr:hover{
background:#f8fbff;
}
.search-box{
padding:8px 12px;
width:300px;
border:1px solid #ccc;
border-radius:6px;
}
.filter-select{
padding:8px 12px;
border:1px solid #ccc;
border-radius:6px;
}
.btn-main{
padding:8px 14px;
cursor:pointer;
border:none;
border-radius:6px;
background:#1976d2;
color:#fff;
}
.btn-main:hover{
background:#0d5cb6;
}
.btn-reset{
padding:8px 14px;
cursor:pointer;
border:none;
border-radius:6px;
background:#6c757d;
color:#fff;
text-decoration:none;
display:inline-block;
}
.money{
font-weight:bold;
}
.text-right{
text-align:right;
}
.pending{
color:#d32f2f;
font-weight:bold;
}
.paid{
color:#2e7d32;
font-weight:bold;
}
.badge-paid{
display:inline-block;
background:#d4edda;
color:#155724;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
}
.badge-pending{
display:inline-block;
background:#f8d7da;
color:#842029;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
}
.invoice-buttons{
display:flex;
gap:5px;
align-items:center;
}
.generate-btn{
background:#1976d2;
color:#fff;
border:none;
padding:6px 10px;
border-radius:5px;
cursor:pointer;
font-size:12px;
white-space:nowrap;
}
.generate-btn:hover{
background:#0d5cb6;
}
.pay-btn{
background:#d32f2f;
color:#fff;
border:none;
padding:6px 10px;
border-radius:5px;
cursor:pointer;
font-size:12px;
white-space:nowrap;
}
.pay-btn:hover{
background:#b71c1c;
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
right:0;
top:100%;
background:#fff;
min-width:180px;
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
.summary-row{
display:flex;
gap:15px;
margin-top:15px;
flex-wrap:wrap;
}
.summary-card{
background:#fff;
border-radius:8px;
padding:15px 20px;
box-shadow:0 4px 15px rgba(0,0,0,.08);
min-width:190px;
flex:1;
border-left:4px solid #1976d2;
}
.summary-label{
font-size:12px;
color:#666;
margin-bottom:6px;
}
.summary-value{
font-size:20px;
font-weight:bold;
}
</style>
</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" id="mainContent" style="width:auto;">

<div class="page-container">

<div style="
display:flex;
align-items:center;
justify-content:space-between;
margin-bottom:12px;
">

<div>
<h2 style="margin:0;text-align:left;">
📄 OPD Invoice
</h2>
</div>

<form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap: nowrap;flex-direction: row;">

<input
type="text"
name="search"
value="<?= e($search) ?>"
placeholder="Search OPD No., UHID, Patient or Doctor"
class="search-box">

<select
name="status"
class="filter-select">

<option value="">
All Payment Status
</option>

<option
value="Paid"
<?= $status_filter=='Paid'?'selected':'' ?>>
Paid
</option>

<option
value="Pending"
<?= $status_filter=='Pending'?'selected':'' ?>>
Pending
</option>

</select>

<button
type="submit"
class="btn-main">
🔍
</button>

<a
href="index.php"
class="btn-reset">
Reset
</a>

</form>

</div>

<?php if($total_patients>0){ ?>

<div class="summary-row">

<div class="summary-card">

<div class="summary-label">
Total OPD Patients
</div>

<div class="summary-value">
<?= number_format($total_patients) ?>
</div>

</div>

<div class="summary-card">

<div class="summary-label">
Total Charges
</div>

<div class="summary-value">
₹<?= number_format($total_charge,2) ?>
</div>

</div>

<div class="summary-card">

<div class="summary-label">
Total Received
</div>

<div class="summary-value paid">
₹<?= number_format($total_received,2) ?>
</div>

</div>

<div class="summary-card">

<div class="summary-label">
Total Pending
</div>

<div class="summary-value pending">
₹<?= number_format($total_pending,2) ?>
</div>

</div>

</div>

<?php } ?>

<div class="table-wrap">

<table>

<thead>

<tr>
<th>#</th>
<th>OPD No.</th>
<th>UHID</th>
<th>Date</th>
<th>Patient</th>
<th>Doctor</th>
<th>Department</th>
<th class="text-right">Consultation</th>
<th class="text-right">Services</th>
<th class="text-right">Total Charge</th>
<th class="text-right">Received</th>
<th class="text-right">Pending</th>
<th>Status</th>
<th>Invoice / Payment</th>
<th>Action</th>
</tr>

</thead>

<tbody>

<?php if($total_patients>0){ ?>

<?php
$counter=1;

foreach($invoice_rows as $row){

$is_paid=(float)$row['total_pending']<=0;

?>

<tr>

<td>
<?= $counter++ ?>
</td>

<td>
<b>
<?= e($row['opd_no']) ?>
</b>
</td>

<td>
<?= e($row['uhid'] ?: '-') ?>
</td>

<td>
<?= !empty($row['appointment_date']) ? date('d-m-Y',strtotime($row['appointment_date'])) : '-' ?>
</td>

<td>
<?= e($row['patient_name']) ?>
</td>

<td>
<?= e($row['doctor_name'] ?: '-') ?>
</td>

<td>
<?= e($row['department_name'] ?: '-') ?>
</td>

<td class="text-right">
₹<?= number_format((float)$row['consultation_total'],2) ?>
</td>

<td class="text-right">
₹<?= number_format((float)$row['service_total'],2) ?>
</td>

<td class="text-right money">
₹<?= number_format((float)$row['total_charge'],2) ?>
</td>

<td class="text-right paid">
₹<?= number_format((float)$row['total_received'],2) ?>
</td>

<td class="text-right <?= $is_paid?'paid':'pending' ?>">
₹<?= number_format((float)$row['total_pending'],2) ?>
</td>

<td>

<?php if($is_paid){ ?>

<span class="badge-paid">
PAID
</span>

<?php }else{ ?>

<span class="badge-pending">
PENDING
</span>

<?php } ?>

</td>

<td>

<div class="invoice-buttons">

<button
type="button"
class="generate-btn"
onclick="openInvoicePopup(
'generate_invoice.php?opd_id=<?= (int)$row['opd_id'] ?>',
'Generate Invoice'
)">
📄 Generate Invoice
</button>
<?php if(!$is_paid && checkPermission('opd_billing','add')){ ?>
<button 
type="button" 
class="pay-btn" 
onclick="openInvoicePopup( 
'pay_balance.php?opd_id=<?= (int)$row['opd_id'] ?>', 
'Pay Balance' 
)"> 
💳 Pay Balance 
</button> 
<?php } ?>

</div>

</td>

<td>

<div class="action-dropdown">

<button
type="button"
class="action-btn"
onclick="toggleActionMenu(this,event)">
⋮
</button>

<div class="action-menu">

<a
href="#"
onclick="
openInvoicePopup(
'print_invoice.php?opd_id=<?= (int)$row['opd_id'] ?>',
'Print Invoice'
);
return false;
">

🖨 Print Invoice

</a>

</div>

</div>

</td>

</tr>

<?php } ?>

<?php }else{ ?>

<tr>

<td
colspan="15"
style="
text-align:center;
padding:30px;
color:#777;
">

No OPD records found.

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

<div
id="billPopup"
style="
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,.55);
z-index:999999;
">

<div
style="
position:absolute;
top:0;
left:0;
width:100%;
height:100%;
background:#fff;
">

<div
style="
height:55px;
background:#1976d2;
color:#fff;
display:flex;
align-items:center;
justify-content:space-between;
padding:0 25px;
font-size:17px;
font-weight:bold;
">

<span id="popupTitle">
Invoice
</span>

<button
type="button"
onclick="closeBillPopup()"
style="
padding:7px 14px;
background:#d32f2f;
color:#fff;
border:none;
border-radius:5px;
cursor:pointer;
font-size:14px;
">

✖ Close

</button>

</div>

<iframe
id="billFrame"
style="
width:100%;
height:calc(100% - 55px);
border:none;
">
</iframe>

</div>

</div>

<script>

/*==========================
ACTION MENU
==========================*/

function toggleActionMenu(button,event){

event.stopPropagation();

document.querySelectorAll('.action-menu').forEach(function(menu){

if(menu!==button.nextElementSibling){
menu.style.display='none';
}

});

let menu=button.nextElementSibling;

if(menu.style.display==='block'){
menu.style.display='none';
}else{
menu.style.display='block';
}

}

document.addEventListener('click',function(){

document.querySelectorAll('.action-menu').forEach(function(menu){

menu.style.display='none';

});

});

/*==========================
POPUP
==========================*/

function openInvoicePopup(url,title){

document.getElementById('popupTitle').innerText=title;

document.getElementById('billPopup').style.display='block';

document.getElementById('billFrame').src=url;

document.body.style.overflow='hidden';

}

function closeBillPopup(){

document.getElementById('billPopup').style.display='none';

document.getElementById('billFrame').src='';

document.body.style.overflow='auto';

}

/*
Called from payment pages after successful payment.
*/

function paymentSuccess(){

closeBillPopup();

window.location.reload();

}

</script>

</body>
</html>