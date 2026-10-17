<!--public_html/modules/billing/ipdbilling/ipd_bill.php  -->
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
$selected_date=date('Y-m-d');

if(!empty($_GET['date'])){
    $inputDate=trim($_GET['date']);

    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$inputDate)){
        $selected_date=$inputDate;
    }elseif(preg_match('/^\d{2}-\d{2}-\d{4}$/',$inputDate)){
        $parts=explode('-',$inputDate);
        $selected_date=$parts[2].'-'.$parts[1].'-'.$parts[0];
    }
}

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$selected_date)){
    $selected_date=date('Y-m-d');
}

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

$where="
WHERE ia.status='Admitted'
";

if(!isset($_GET['all'])){
    $where.="
    AND ia.admission_date='$selected_date'
    ";
}

if($search!=''){

    $search=mysqli_real_escape_string($conn,$search);

    $where.="
    AND
    (
        ia.ipd_no LIKE '%$search%'
        OR ia.file_no LIKE '%$search%'
        OR p.uhid LIKE '%$search%'
        OR p.mobile LIKE '%$search%'
        OR CONCAT(
            p.first_name,
            ' ',
            p.last_name
        ) LIKE '%$search%'
    )
    ";
}

/*=====================================
IPD BILL QUERY
=====================================*/

$query="
SELECT

    ia.id AS ipd_admission_id,
    ia.appointment_id,
    ia.patient_id,
    ia.ipd_no,
    ia.file_no,
    ia.hospital_id,
    ia.branch_id,
    ia.doctor_id,
    ia.department_id,
    ia.admission_date,
    ia.admission_time,
    ia.admission_type,
    ia.status AS admission_status,
    ia.credit_limit,
    ia.payment_type,
    ia.is_emergency,
    ia.is_vip,
    ia.is_mlc,

    p.uhid,
    p.title,
    p.first_name,
    p.last_name,
    p.sex,
    p.age_y,
    p.age_m,
    p.age_d,
    p.mobile,

    u.full_name AS doctor_name,
    d.department_name,

   COALESCE(latest_bill.bill_id,0) AS bill_id,
latest_bill.bill_no,
COALESCE(b.total_amount,0) AS total_amount,
COALESCE(b.discount_amount,0) AS discount_amount,
COALESCE(b.total_bill_amount,0) AS net_amount,
COALESCE(b.bill_paid_amount,0) AS bill_paid_amount,
COALESCE(pay.total_advance,0) AS total_advance,
COALESCE(pay.total_payments,0) AS total_received,
CASE
WHEN COALESCE(b.total_bill_amount,0)>COALESCE(pay.total_payments,0)
THEN COALESCE(b.total_bill_amount,0)-COALESCE(pay.total_payments,0)
ELSE 0
END AS bill_balance_amount,
CASE
WHEN COALESCE(pay.total_payments,0)>COALESCE(b.total_bill_amount,0)
THEN COALESCE(pay.total_payments,0)-COALESCE(b.total_bill_amount,0)
ELSE 0
END AS excess_advance

FROM ipd_admissions ia

LEFT JOIN patient_master p
    ON ia.patient_id=p.id

LEFT JOIN users u
    ON ia.doctor_id=u.id

LEFT JOIN department_master d
    ON ia.department_id=d.id

LEFT JOIN
(
    SELECT
        MAX(id) AS bill_id,
        MAX(bill_no) AS bill_no,
        ipd_admission_id
    FROM ipd_bills
    WHERE payment_status<>'Cancelled'
    GROUP BY ipd_admission_id
) latest_bill
    ON latest_bill.ipd_admission_id=ia.id

LEFT JOIN
(
    SELECT
        ipd_admission_id,
        SUM(total_amount) AS total_amount,
        SUM(discount_amount) AS discount_amount,
        SUM(net_amount) AS total_bill_amount,
        SUM(paid_amount) AS bill_paid_amount
    FROM ipd_bills
    WHERE payment_status<>'Cancelled'
    GROUP BY ipd_admission_id
) b
    ON b.ipd_admission_id=ia.id

LEFT JOIN
(
    SELECT
        ipd_admission_id,
        SUM(payment_amount) AS total_payments,
        SUM(
            CASE
            WHEN ipd_bill_id IS NULL
            THEN payment_amount
            ELSE 0
            END
        ) AS total_advance
    FROM ipd_payments
    GROUP BY ipd_admission_id
) pay
    ON pay.ipd_admission_id=ia.id

$where

ORDER BY
    ia.admission_date DESC,
    ia.id DESC
";

$result=mysqli_query($conn,$query);

if(!$result){
    die("IPD Billing Query Error: ".mysqli_error($conn));
}

/*=====================================
STORE RESULTS
=====================================*/

$billing_rows=[];

while($row=mysqli_fetch_assoc($result)){
    $billing_rows[]=$row;
}

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<title>IPD Billing</title>

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
    white-space:nowrap;
    min-width:1400px;
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
    background:#1976d2;
    color:#fff;
    position:sticky;
    top:0;
    z-index:2;
}

.search-box{
    padding:8px 12px;
    width:300px;
    border:1px solid #ccc;
    border-radius:6px;
}
.custom-modal-header{
display:flex;
justify-content:space-between;
align-items:center;
padding:0px 18px;
background:#1976d2;
color:#fff;
border-radius:8px 8px 0 0;
}

.custom-modal-body{
padding:2px;
max-height:94vh;
overflow:auto;
}

.custom-close{
background:none;
border:none;
font-size:22px;
cursor:pointer;
color:#fff;
}
button{
    padding:8px 14px;
    cursor:pointer;
}

.emergency-row{
    background:#fff1f1 !important;
}

.emergency-tag{
    display:inline-block;
    background:#dc2626;
    color:#fff;
    padding:3px 8px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold;
    margin-left:6px;
}

.status-admitted{
    background:#d4edda;
    color:#155724;
    padding:4px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
}

.balance-due{
    color:#d32f2f;
    font-weight:bold;
}

.balance-zero{
    color:#2e7d32;
    font-weight:bold;
}

.deposit{
    color:#1565c0;
    font-weight:bold;
}
/* ================================
   ADD CHARGES POPUP ONLY
================================ */

.application-modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.55);
    z-index:99999;
    box-sizing:border-box;
}

.application-modal-content{
    width:100%;
    height:100%;
    background:#fff;
    border-radius:8px;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    box-shadow:0 10px 30px rgba(0,0,0,.35);
}

.application-modal-header{
    flex:0 0 auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    min-height:54px;
    padding:0 18px;
    background:#1976d2;
    color:#fff;
    border-radius:8px 8px 0 0;
}

.application-modal-header h3{
    margin:0;
    font-size:20px;
}

.application-modal-header button{
    border:none;
    background:transparent;
    color:#fff;
    font-size:30px;
    cursor:pointer;
    line-height:1;
}

.application-modal-body{
    flex:1;
    min-height:0;
    padding:0;
    overflow:hidden;
}

#chargesFrame{
    display:block;
    width:100%;
    height:100%;
    border:none;
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

.emergency-row:hover{
    background:#ffe3e3 !important;
}

.empty-row{
    text-align:center;
    padding:30px !important;
    color:#777;
    font-size:15px;
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

<!-- ================================
HEADER
================================ -->

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

<h2
style="
margin:0;
text-align:left;
">

🏥 IPD Billing

</h2>

</div>

<!-- CENTER -->

<div
style="
flex:1;
text-align:center;
">

<a
href="?date=<?= $prev_date ?>&search=<?= urlencode($search) ?><?= isset($_GET['all']) ? '&all=1' : '' ?>"
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

<a
href="?date=<?= $next_date ?>&search=<?= urlencode($search) ?><?= isset($_GET['all']) ? '&all=1' : '' ?>"
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
value="<?= htmlspecialchars($selected_date) ?>"
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
value="<?= htmlspecialchars($selected_date) ?>">

<input
type="text"
name="search"
value="<?= htmlspecialchars($search) ?>"
placeholder="Search IPD No. / UHID / Patient..."
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
onclick="window.location='ipd_bill.php'">

📅 Today

</button>

<?php
}else{
?>

<button
onclick="window.location='ipd_bill.php?all=1'">

📋 All

</button>

<?php
}
?>

</div>

</div>

<!-- ================================
TABLE
================================ -->

<div
class="table-wrap"
style="margin-top:10px;">

<table>

<thead>

<tr>

<th>Action</th>

<th>IPD No.</th>

<th>UHID</th>

<th>Admission Date</th>

<th>Patient</th>

<th>Age</th>

<th>Gender</th>

<th>Mobile</th>

<th>Department</th>

<th>Doctor</th>

<th>Admission Type</th>

<th>Status</th>

<th>Emergency</th>
<th>Credit Limit</th>
<th>Total Bill</th>
<th>Advance</th>
<th>Total Received</th>
<th>Pending</th>
<th>Excess Advance</th>

</tr>

</thead>

<tbody>

<?php
if(empty($billing_rows)){
?>

<tr>

<td
colspan="20"
class="empty-row">

No IPD Patients Found

</td>

</tr>

<?php
}
?>

<?php
foreach($billing_rows as $row){

$isEmergency=(int)$row['is_emergency']===1;

$patientName=trim(
($row['title'] ?? '').' '.
($row['first_name'] ?? '').' '.
($row['last_name'] ?? '')
);

$totalAmount=(float)($row['net_amount'] ?? 0);
$advanceAmount=(float)($row['total_advance'] ?? 0);
$receivedAmount=(float)($row['total_received'] ?? 0);
$balanceAmount=(float)($row['bill_balance_amount'] ?? 0);
$excessAdvance=(float)($row['excess_advance'] ?? 0);

?>

<tr
class="<?= $isEmergency ? 'emergency-row' : '' ?>">

<!-- ACTION -->

<td>

<div
class="action-dropdown">

<button
class="action-btn">

⋮
</button>

<div class="action-menu">
  <?php if(checkPermission('ipd_billing','add')){ ?>
<a href="javascript:void(0)"
onclick="openChargesPopup(<?= (int)$row['ipd_admission_id'] ?>)">
➕ Add Charges
</a>
<?php } ?>


<?php if(checkPermission('ipd_billing','add')){ ?>
<a href="#" onclick="openAdvancePopup(<?= (int)$row['ipd_admission_id'] ?>);return false;"> 💰 Advance </a>
<a
href="#"
onclick="openIPDBillPopup(<?= (int)$row['ipd_admission_id'] ?>);return false;">
💰 New IPD Bill
</a>
<?php } ?>

<?php
if(!empty($row['bill_id']) && $balanceAmount>0 && checkPermission('ipd_billing','add')){
?>
<a
href="#"
onclick="openReceivePaymentPopup(<?= (int)$row['bill_id'] ?>);return false;">
💳 Receive Payment
</a>
<?php
}
?>

<?php
if(!empty($row['bill_id'])){
?>

<a
href="print_bill.php?bill_id=<?= (int)$row['bill_id'] ?>"
target="_blank">

🖨 Print Bill

</a>

<?php
}
?>

</div>

</div>

</td>

<!-- IPD NO -->

<td>

<strong>

<?= htmlspecialchars($row['ipd_no'] ?? '') ?>

</strong>

<?php
if($isEmergency){
?>

<span
class="emergency-tag">

EMERGENCY

</span>

<?php
}
?>

</td>

<!-- UHID -->

<td>

<?= htmlspecialchars($row['uhid'] ?? '') ?>

</td>

<!-- DATE -->

<td>

<?= !empty($row['admission_date'])
    ? date('d-m-Y',strtotime($row['admission_date']))
    : ''
?>

</td>

<!-- PATIENT -->

<td>

<strong>

<?= htmlspecialchars($patientName) ?>

</strong>

</td>

<!-- AGE -->

<td>

<?= (int)($row['age_y'] ?? 0) ?>Y

<?= (int)($row['age_m'] ?? 0) ?>M

<?= (int)($row['age_d'] ?? 0) ?>D

</td>

<!-- GENDER -->

<td>

<?= htmlspecialchars($row['sex'] ?? '') ?>

</td>

<!-- MOBILE -->

<td>

<?= htmlspecialchars($row['mobile'] ?? '') ?>

</td>

<!-- DEPARTMENT -->

<td>

<?= htmlspecialchars($row['department_name'] ?? '') ?>

</td>

<!-- DOCTOR -->

<td>

<?= htmlspecialchars($row['doctor_name'] ?? '') ?>

</td>

<!-- ADMISSION TYPE -->

<td>

<?= htmlspecialchars($row['admission_type'] ?? '') ?>

</td>

<!-- STATUS -->

<td>

<span
class="status-admitted">

Admitted

</span>

</td>

<!-- EMERGENCY -->

<td>

<?php
if($isEmergency){
?>

<span
style="
background:#dc2626;
color:#fff;
padding:4px 9px;
border-radius:15px;
font-size:11px;
font-weight:bold;
">

YES

</span>

<?php
}else{
?>

<span
style="
color:#777;
">

No

</span>

<?php
}
?>
</td>
<!-- CREDIT LIMIT -->
<td>
<span
style="
color:#7b1fa2;
font-weight:bold;
">
₹ <?= number_format(
(float)($row['credit_limit'] ?? 0),
2
) ?>
</span>
</td>
<!-- BILL -->
<td>
<?php
if(!empty($row['bill_id'])){
?>
<div>
<b>
₹ <?= number_format($totalAmount,2) ?>
</b>
</div>
<?php
if(!empty($row['bill_no'])){
?>
<small
style="
color:#777;
">

<?= htmlspecialchars($row['bill_no']) ?>

</small>

<?php
}
?>

<?php
}else{
?>

<span
style="
color:#777;
">

Not Generated

</span>

<?php
}
?>

</td>
<!-- ADVANCE -->

<td>

<span
style="
color:#1565c0;
font-weight:bold;
">

₹ <?= number_format($advanceAmount,2) ?>

</span>

</td>
<!-- PAID -->
<!-- TOTAL RECEIVED -->
<td>
₹ <?= number_format($receivedAmount,2) ?>
</td>

<!-- BALANCE -->

<td>

<?php
if($balanceAmount>0){
?>

<span
class="balance-due">

₹ <?= number_format($balanceAmount,2) ?>

</span>

<?php
}else{
?>

<span
class="balance-zero">

₹ 0.00

</span>

<?php
}
?>

</td>
<!-- EXCESS ADVANCE -->
<td>
<span class="deposit">
₹ <?= number_format($excessAdvance,2) ?>
</span>
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

<!-- ================================
ACTION MENU
================================ -->

<script>

document
.querySelectorAll('.action-btn')
.forEach(function(btn){

btn.addEventListener('click',function(e){

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

document
.addEventListener('click',function(){

document
.querySelectorAll('.action-menu')
.forEach(function(menu){

menu.style.display='none';

});

});

/* ==============================
DATE PICKER
============================== */

document
.getElementById('jumpDate')
.addEventListener('change',function(){

let url='ipd_bill.php?date='+this.value;

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

/* ==============================
SEARCH ENTER
============================== */

document
.querySelector('.search-box')
.addEventListener('keypress',function(e){

if(e.key==='Enter'){

this.form.submit();

}

});

/* ==============================
IPD BILL POPUP
============================== */

function openIPDBillPopup(ipd_admission_id){

document
.getElementById("ipdBillPopup")
.style
.display="block";

document
.getElementById("ipdBillFrame")
.src=
"/modules/billing/ipdbilling/new_bill.php?ipd_admission_id="
+ipd_admission_id;

document
.body
.style
.overflow="hidden";

}

/* ==============================
RECEIVE PAYMENT
============================== */

function openReceivePaymentPopup(bill_id){

document
.getElementById("ipdBillPopup")
.style
.display="block";

document
.getElementById("ipdBillFrame")
.src=
"/modules/billing/ipdbilling/receive_payment.php?bill_id="
+bill_id;

document
.body
.style
.overflow="hidden";

}

function closeIPDBillPopup(){

document
.getElementById("ipdBillPopup")
.style
.display="none";

document
.getElementById("ipdBillFrame")
.src="";

document
.body
.style
.overflow="auto";

}

</script>

<!-- ================================
IPD BILL POPUP
================================ -->

<div
id="ipdBillPopup"
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

<button
onclick="closeIPDBillPopup()"
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
id="ipdBillFrame"
style="
width:100%;
height:100%;
border:none;
">

</iframe>

</div>

</div>
<div
id="ipdAdvancePopup"
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
top:50%;
left:50%;
transform:translate(-50%,-50%);
width:600px;
max-width:95%;
height:650px;
background:#fff;
border-radius:12px;
overflow:hidden;
box-shadow:0 15px 40px rgba(0,0,0,.3);
">

<button
onclick="closeAdvancePopup()"
style="
position:absolute;
top:12px;
right:15px;
z-index:999999;
padding:7px 12px;
background:#dc3545;
color:#fff;
border:none;
border-radius:5px;
cursor:pointer;
font-size:14px;
">

✖

</button>

<iframe
id="ipdAdvanceFrame"
style="
width:100%;
height:100%;
border:none;
">

</iframe>

</div>

</div>
<script>
function openAdvancePopup(ipd_admission_id){

    document
    .getElementById("ipdAdvancePopup")
    .style
    .display="block";

    document
    .getElementById("ipdAdvanceFrame")
    .src=
    "/modules/billing/ipdbilling/advance.php?ipd_admission_id="
    +ipd_admission_id;

    document
    .body
    .style
    .overflow="hidden";
}

function closeAdvancePopup(){

    document
    .getElementById("ipdAdvancePopup")
    .style
    .display="none";

    document
    .getElementById("ipdAdvanceFrame")
    .src="";

    document
    .body
    .style
    .overflow="auto";
}
function openChargesPopup(ipdAdmissionId){
document.getElementById('chargesModal').style.display='flex';

document.getElementById('chargesFrame').src=
'/modules/ipd/ajax/service_charge_popup.php?ipd_admission_id='+
encodeURIComponent(ipdAdmissionId);
}
function closeChargesPopup(){
document.getElementById('chargesModal').style.display='none';
document.getElementById('chargesFrame').src='';
}
</script>
<div id="chargesModal" class="application-modal">
<div class="application-modal-content">
<div class="application-modal-header">
<h3>Add Charges</h3>
<button type="button" onclick="closeChargesPopup()">×</button>
</div>

<div class="application-modal-body">
<iframe
id="chargesFrame"
src=""
frameborder="0">
</iframe>
</div>
</div>
</div>
</body>
</html>