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
$selected_date = date('Y-m-d');

if (!empty($_GET['date'])) {

    $inputDate = trim($_GET['date']);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputDate)) {
        $selected_date = $inputDate;
    } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $inputDate)) {
        $parts = explode('-', $inputDate);

        $selected_date =
            $parts[2] . '-' .
            $parts[1] . '-' .
            $parts[0];
    }
}

/* Normalize date to MySQL format: Y-m-d */

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

$prev_date = date(
    'Y-m-d',
    strtotime($selected_date . ' -1 day')
);

$next_date = date(
    'Y-m-d',
    strtotime($selected_date . ' +1 day')
);
/*=====================================
SEARCH
=====================================*/
$search=trim($_GET['search'] ?? '');
/*=====================================
WHERE CONDITION
=====================================*/
$where="
WHERE a.type='doctor'
AND a.status IN
(
'Prescription',
'Done'
)
";
if(!isset($_GET['all'])){
    $where.="
AND a.appointment_date='$selected_date'
";
}
if($search!=''){
$search=mysqli_real_escape_string($conn,$search);
$where.="
AND
(
a.opd_no LIKE '%$search%'
OR
a.appointment_code LIKE '%$search%'
OR
p.uhid LIKE '%$search%'
OR
a.mobile LIKE '%$search%'
OR
CONCAT(
a.first_name,
' ',
a.last_name
)
LIKE '%$search%'
)
";
}
/*=====================================
PATIENT QUERY
=====================================*/
$query="
SELECT
a.*,
p.uhid,
u.full_name AS doctor_name,
cu.full_name AS created_by_name,
d.department_name,
c.name AS city_name,
em.empanelment_name,
dm.discount_name,
bill.id AS bill_id,
bill.bill_no,
bill.grand_total,
bill.paid_amount AS bill_paid_amount,
bill.balance_amount AS bill_balance_amount

FROM appointments a
LEFT JOIN patient_master p
ON a.patient_id=p.id
LEFT JOIN users u
ON a.entity_id=u.id
LEFT JOIN users cu
ON a.created_by=cu.id
LEFT JOIN department_master d
ON a.department_id=d.id
LEFT JOIN cities c
ON a.city_id=c.id
LEFT JOIN empanelment_master em
ON a.empanelment_id=em.id
LEFT JOIN discount_master dm
ON a.discount_type=dm.id

LEFT JOIN (
SELECT
MAX(id) AS bill_id,
opd_id
FROM opd_bills
WHERE status<>'Cancelled'
GROUP BY opd_id
) ob
ON ob.opd_id=a.id

LEFT JOIN opd_bills bill
ON bill.id=ob.bill_id

$where
ORDER BY
a.appointment_date DESC,
a.token_no ASC
";
$result = mysqli_query($conn, $query);

if (!$result) {
    die("Billing Query Error: " . mysqli_error($conn));
}

$billing_rows = [];

while ($row = mysqli_fetch_assoc($result)) {
    $billing_rows[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>OPD Billing</title>
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
    height: 620px;
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
    white-space:nowrap;
}
th, td{
    border:1px solid #e5e7eb;
    padding:1px 10px;
    text-align:left;
}
th{
    background:#1976d2;
    padding:8px 10px;
    color:#fff;
    position:sticky;
    top:0;
}
.search-box{
padding:8px 12px;
width:300px;
border:1px solid #ccc;
border-radius:6px;
}
button{
padding:8px 14px;
cursor:pointer;
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">
<div style="display:flex;align-items:center;">
    <!-- LEFT -->
    <div style="flex:1;text-align:left;">
        <h2 style="margin:0;text-align:left;">
            💰 OPD Billing
        </h2>
    </div>
  <!-- CENTER -->
<div style="flex:1;text-align:center;">
    <a href="?date=<?= $prev_date ?>&search=<?= urlencode($search) ?><?= isset($_GET['all']) ? '&all=1' : '' ?>"
    style="text-decoration:none;font-size:22px;font-weight:bold;padding:0 10px;">
        &#10094;
    </a>
    <span
    id="dateDisplay"
    onclick="document.getElementById('jumpDate').showPicker();"
    style="
    font-size:18px;
    font-weight:600;
    cursor:pointer;
    padding:6px 12px;
    border-radius:6px;
    background:#f3f4f6;
    ">
        <?= date('d M Y',strtotime($selected_date)) ?> 📅
    </span>
    <a href="?date=<?= $next_date ?>&search=<?= urlencode($search) ?><?= isset($_GET['all']) ? '&all=1' : '' ?>"
    style="text-decoration:none;font-size:22px;font-weight:bold;padding:0 10px;">
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
<div style="flex:1;text-align:center;">
<form method="get" style="display:flex;justify-content:center;align-items:center;gap:8px;">
<?php if(isset($_GET['all'])){ ?>
<input type="hidden" name="all" value="1">
<?php } ?>
<input type="hidden" name="date" value="<?= $selected_date ?>">
<input
type="text"
name="search"
value="<?= htmlspecialchars($search) ?>"
placeholder="Search..."
class="search-box">
<button type="submit">
🔍
</button>
</form>
</div>
<!-- RIGHT -->
<div style="flex:1;text-align:right;">
<?php if(isset($_GET['all'])){ ?>
<button onclick="window.location='opd_bill.php'">
📅 Today
</button>
<?php } else { ?>
<button onclick="window.location='opd_bill.php?all=1'">
📋 All
</button>
<?php } ?>
</div>
</div>
<div style="
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:5px;
">
<form method="get" style="display:flex;gap:8px;align-items:center;">
<?php if(isset($_GET['all'])){ ?>
<input type="hidden" name="all" value="1">
<?php } ?>
<input
type="hidden"
name="date"
value="<?= $selected_date ?>">
</form>
</div>
<div class="table-wrap">
<table>
<tr>
<th>Action</th>
<th>OPD No.</th>
<th>UHID</th>
<th>Date</th>
<th>Token</th>
<th>Patient</th>
<th>Age</th>
<th>Gender</th>
<th>Mobile</th>
<th>Department</th>
<th>Doctor</th>
<th>Status</th>
<th>Empanelment</th>
<th>Fee</th>
<th>Paid</th>
<th>Balance</th>
<th>Created By</th>
</tr><?php
foreach($billing_rows as $row){
?>
<tr>
<td>
<div class="action-dropdown">
<button class="action-btn">⋮</button>
<div class="action-menu">

<?php if(checkPermission('opd_billing','add')){ ?>
<a href="#" 
onclick="openBillPopup(<?= $row['id'] ?>);return false;"> 
💰 New Bill 
</a>
<?php } ?>

<?php if(!empty($row['bill_id']) && (float)$row['bill_balance_amount'] > 0){ ?>

<?php if(checkPermission('opd_billing','add')){ ?>
<a href="#"
onclick="openReceivePaymentPopup(<?= $row['bill_id'] ?>);return false;">
💳 Pay Balance
</a>
<?php } ?>

<?php } ?>

<?php if(!empty($row['bill_id'])){ ?>

<a href="print_bill.php?bill_id=<?= $row['bill_id'] ?>" target="_blank">
🖨 Print Bill
</a>

<?php } ?>



<a href="/modules/opd/print_op_slip.php?id=<?= $row['id'] ?>" target="_blank">
🖨 Print OP Slip
</a>

<?php if($row['status']=='Prescription'){ ?>

<a href="/modules/opd/prescription_pdf.php?appointment_id=<?= $row['id'] ?>" target="_blank">
💊 Print Prescription
</a>

<?php } ?>

</div>
</div>
</td>
<td><?= htmlspecialchars($row['opd_no'] ?? '') ?></td>
<td><?= htmlspecialchars($row['uhid'] ?? '') ?></td>
<td><?= !empty($row['appointment_date']) ? date('d-m-Y',strtotime($row['appointment_date'])) : '' ?></td>
<td><?= htmlspecialchars($row['token_no'] ?? '') ?></td>
<td>
<strong>
<?= htmlspecialchars(
trim(
($row['title'] ?? '').' '.
($row['first_name'] ?? '').' '.
($row['last_name'] ?? '')
)
) ?>
</strong>
</td>
<td>
<?=
(int)($row['age_y'] ?? 0).'Y '.
(int)($row['age_m'] ?? 0).'M '.
(int)($row['age_d'] ?? 0).'D'
?>
</td>
<td>
<?= htmlspecialchars($row['sex'] ?? '') ?>
</td>
<td>
<?= htmlspecialchars($row['mobile'] ?? '') ?>
</td>
<td>
<?= htmlspecialchars($row['department_name'] ?? '') ?>
</td>
<td>
<?= htmlspecialchars($row['doctor_name'] ?? '') ?>
</td>
<td>
<?php
if($row['status']=='Prescription'){
echo '<span style="
background:#fff3cd;
color:#856404;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
">Prescription</span>';
}
else{
echo '<span style="
background:#d4edda;
color:#155724;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
">Done</span>';
}
?>
</td>
<td>
<?= htmlspecialchars($row['empanelment_name'] ?? '') ?>
</td>
<td>

<?php
$consultation_fee=(float)($row['consultation_fee'] ?? 0);
$bill_amount=(float)($row['grand_total'] ?? 0);
?>

<div>
<b>Consultation :</b>
₹ <?= number_format($consultation_fee,2) ?>
</div>

<?php if(!empty($row['bill_id'])){ ?>

<div style="color:#1976d2;font-weight:bold;">
Bill :
₹ <?= number_format($bill_amount,2) ?>
</div>

<?php } ?>

</td>
<td>
<?= number_format(
(float)(
!empty($row['bill_id'])
? $row['bill_paid_amount']
: $row['paid_amount']
),
2
) ?>
</td>
<td>
<?php
$balance = !empty($row['bill_id'])
? (float)$row['bill_balance_amount']
: (float)$row['balance_amount'];
?>

<span style="
font-weight:bold;
color:<?= $balance>0 ? '#d32f2f' : '#2e7d32' ?>;
">
₹ <?= number_format($balance,2) ?>
</span>

</td>
<td>
<?= htmlspecialchars($row['created_by_name'] ?? '') ?>
</td>
</tr>
<?php
}
?>
</table>
</div>
</div>
<style>
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
.status-prescription{
background:#fff3cd;
color:#856404;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
}
.status-done{
background:#d4edda;
color:#155724;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
}
</style>
<script>
/*==========================
ACTION MENU
==========================*/
document.querySelectorAll('.action-btn').forEach(function(btn){
btn.addEventListener('click',function(e){
e.stopPropagation();
document.querySelectorAll('.action-menu').forEach(function(menu){
menu.style.display='none';
});
this.nextElementSibling.style.display='block';
});
});
document.addEventListener('click',function(){
document.querySelectorAll('.action-menu').forEach(function(menu){
menu.style.display='none';
});
});
/*==========================
DATE PICKER
==========================*/
document
.getElementById('jumpDate')
.addEventListener('change',function(){
let url='opd_bill.php?date='+this.value;
<?php if($search!=''){ ?>
url+='&search=<?= urlencode($search) ?>';
<?php } ?>
<?php if(isset($_GET['all'])){ ?>
url+='&all=1';
<?php } ?>
window.location.href=url;
});
/*==========================
SEARCH ON ENTER
==========================*/
document
.querySelector('.search-box')
.addEventListener('keypress',function(e){
if(e.key==='Enter'){
this.form.submit();
}
});
function openReceivePaymentPopup(bill_id){

document.getElementById("billPopup").style.display="block";

document.getElementById("billFrame").src=
"/modules/billing/opbilling/receive_payment.php?bill_id="+bill_id;

document.body.style.overflow="hidden";

}
function openBillPopup(opd_id){
document.getElementById("billPopup").style.display="block";
document.getElementById("billFrame").src=
"/modules/billing/opbilling/new_bill.php?opd_id="+opd_id;
document.body.style.overflow="hidden";
}
function closeBillPopup(){
document.getElementById("billPopup").style.display="none";
document.getElementById("billFrame").src="";
document.body.style.overflow="auto";
}
</script>
<div id="billPopup" style="
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,.55);
z-index:999999;
">
<div style="
position:absolute;
top:0;
left:0;
width:100%;
height:100%;
background:#fff;
">
<button
onclick="closeBillPopup()"
style="
position:absolute;
top:19px;
right:27px;
z-index:999999;
padding:8px 15px;
background:#d32f2f;
color:#fff;
border:none;
border-radius:5px;
cursor:pointer;
font-size:14px;
">
✖
</button>
<iframe
id="billFrame"
style="
width:100%;
height:100%;
border:none;
">
</iframe>
</div>
</div>
</body>
</html>