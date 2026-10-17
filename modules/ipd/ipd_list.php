<!-- public_html/modules/ipd/ipd_list.php -->
<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header("Location:/index.php");
    exit;
}
if (!checkPermission('ipd','view')) {
    header("Location:/access_denied.php");
    exit;
}
$current_user_id = (int)$_SESSION['user_id'];
/* =====================================
   CHECK IF LOGGED USER IS DOCTOR
===================================== */
$isDoctor = false;
$stmt = $conn->prepare("
SELECT id
FROM doctor_master
WHERE user_id=?
LIMIT 1
");
$stmt->bind_param("i",$current_user_id);
$stmt->execute();
$isDoctor = $stmt->get_result()->num_rows>0;
$stmt->close();
/* =====================================
   FILTERS
===================================== */
$where=[];
/* Status */
if(!empty($_GET['status'])){
    $status=mysqli_real_escape_string($conn,$_GET['status']);
    $where[]="a.status='$status'";
}else{
    $where[]="a.status IN('Registered','Admitted')";
}
/* Doctor */
if(!empty($_GET['doctor_id'])){
    $doctor=(int)$_GET['doctor_id'];
    $where[]="a.entity_id=$doctor";
}
/* Department */
if(!empty($_GET['department_id'])){
    $dept=(int)$_GET['department_id'];
    $where[]="a.department_id=$dept";
}
/* UHID */
if(!empty($_GET['uhid'])){
    $uhid=(int)$_GET['uhid'];
    $where[]="pm.uhid=$uhid";
}
/* Mobile */
if(!empty($_GET['mobile'])){
    $mobile=mysqli_real_escape_string($conn,$_GET['mobile']);
    $where[]="pm.mobile LIKE '%$mobile%'";
}
/* Patient */
if(!empty($_GET['patient'])){
    $patient=mysqli_real_escape_string($conn,$_GET['patient']);
    $where[]="CONCAT(pm.first_name,' ',pm.last_name) LIKE '%$patient%'";
}
/* Date From */
if(!empty($_GET['date_from'])){
    $date=mysqli_real_escape_string($conn,$_GET['date_from']);
    $where[]="a.appointment_date>='$date'";
}
/* Date To */
if(!empty($_GET['date_to'])){
    $date=mysqli_real_escape_string($conn,$_GET['date_to']);
    $where[]="a.appointment_date<='$date'";
}
/* Doctor Login */
if($isDoctor){
    $where[]="a.entity_id=".$current_user_id;
}
$whereSql="WHERE a.type='ipd'";
if(count($where)){
    $whereSql.=" AND ".implode(" AND ",$where);
}
/* =====================================
   DROPDOWNS
===================================== */
$doctorRes=mysqli_query($conn,"
SELECT
u.id,
u.full_name
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
ORDER BY u.full_name
");
$deptRes=mysqli_query($conn,"
SELECT
id,
department_name
FROM department_master
ORDER BY department_name
");
$createdRes=mysqli_query($conn,"
SELECT
id,
full_name
FROM users
ORDER BY full_name
");
/* =====================================
   IPD LIST
===================================== */
$sql="
SELECT
a.id,
ia.id AS ipd_admission_id,
a.patient_id,
a.ipd_no,
a.appointment_date,
a.appointment_time,
ia.status AS status,
a.is_emergency,
a.created_at,
pm.uhid,
pm.first_name,
pm.last_name,
pm.sex,
pm.mobile,
pm.age_y,
pm.age_m,
pm.age_d,
d.department_name,
doc.full_name doctor_name,
u.full_name created_by
FROM appointments a
LEFT JOIN patient_master pm
ON pm.id=a.patient_id
LEFT JOIN department_master d
ON d.id=a.department_id
LEFT JOIN users doc
ON doc.id=a.entity_id
LEFT JOIN users u
ON u.id=a.created_by
LEFT JOIN ipd_admissions ia
ON ia.appointment_id=a.id
$whereSql
ORDER BY
a.created_at DESC
";
$res=mysqli_query($conn,$sql);
/* =====================================
   ESCAPE
===================================== */
function e($value){
    return htmlspecialchars((string)$value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IPD Patient List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>
.main-content{
    width:auto;
    overflow:hidden;
}
.page-container{
    padding:8px;
}
.custom-modal{
display:none;
position:fixed;
left:0;
top:0;
width:100%;
height:100%;
background:rgba(0,0,0,.55);
z-index:99999;
}

.custom-modal-box{
background:#fff;
width:100%;
max-width:100%;
height: 100%;
margin:5px auto;
border-radius:8px;
box-shadow:0 10px 30px rgba(0,0,0,.35);
animation:popup .2s ease;
}
.emergency-row{
    background:#fecaca !important;
}

.emergency-row td{
    background:#fecaca !important;
    color:#991b1b;
    font-weight:600;
}
@keyframes popup{
from{
transform:scale(.9);
opacity:0;
}
to{
transform:scale(1);
opacity:1;
}
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
.filter-box{
    background:#fff;
    padding:10px;
    border-radius:8px;
    margin-bottom:5px;
    box-shadow:0 2px 8px rgba(0,0,0,.08);
}
.filter-box form{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}
.ipd-status-select{
height:30px;
min-width:115px;
padding:3px 8px;
border:1px solid #d1d5db;
border-radius:5px;
background:#fff;
font-size:13px;
cursor:pointer;
}
.discharge-modal{
display:none;
position:fixed;
left:0;
top:0;
width:100%;
height:100%;
background:rgba(0,0,0,.60);
z-index:100000;
overflow:hidden;
}
.discharge-modal-box{
width:100%;
height:100%;
margin:auto;
background:#fff;
border-radius:8px;
box-shadow:0 10px 35px rgba(0,0,0,.4);
overflow:hidden;
}
.discharge-modal-header{
height:52px;
display:flex;
align-items:center;
justify-content:space-between;
padding:0 18px;
background:#1976d2;
color:#fff;
}
.discharge-modal-header h3{
margin:0;
font-size:18px;
}
.discharge-close{
background:none;
border:none;
color:#fff;
font-size:22px;
cursor:pointer;
}
.discharge-modal-body{
height:calc(100% - 52px);
overflow:auto;
}
.filter-box select{
    width:110px;
    height:36px;
}

.filter-box input[type=text]{
    width:110px;
    height:36px;
}

.filter-box input[type=date]{
    width:150px;
    height:36px;
}

.filter-box button{
    height:36px;
    padding:0 15px;
}
body{
    overflow-x:hidden;
}
*{
    box-sizing:border-box;
}

.badge{
    padding:4px 8px;
    border-radius:4px;
    color:#fff;
    font-size:12px;
}
.badge-reg{
    background:#f59e0b;
}
.badge-admit{
    background:#16a34a;
}
.action-menu{
    position:relative;
}
.action-btn{
    background:#1976d2;
    color:#fff;
    border:none;
    cursor:pointer;
    border-radius:4px;
    padding:4px 8px;
}
.action-dropdown{
    display:none;
    position:absolute;
    left:0;
    top:28px;
    min-width:180px;
    background:#fff;
    border:1px solid #ddd;
    border-radius:5px;
    box-shadow:0 5px 15px rgba(0,0,0,.15);
    z-index:999;
}
.payment-pending-modal{
display:none;
position:fixed;
inset:0;
z-index:200000;
align-items:center;
justify-content:center;
padding:20px;
background:rgba(15,23,42,.58);
backdrop-filter:blur(7px);
-webkit-backdrop-filter:blur(7px);
}

.payment-pending-modal.show{
display:flex;
}

.payment-pending-box{
width:100%;
max-width:500px;
background:#fff;
border-radius:16px;
box-shadow:0 25px 70px rgba(0,0,0,.35);
overflow:hidden;
animation:paymentPopup .22s ease;
}

.payment-pending-top{
background:linear-gradient(135deg,#fff7ed,#fff);
padding:24px 25px 18px;
text-align:center;
border-bottom:1px solid #f1f5f9;
}

.payment-pending-icon{
width:62px;
height:62px;
margin:0 auto 12px;
border-radius:50%;
display:flex;
align-items:center;
justify-content:center;
background:#fff3cd;
font-size:31px;
}

.payment-pending-title{
margin:0;
font-size:21px;
font-weight:700;
color:#9a3412;
}

.payment-pending-subtitle{
margin:6px 0 0;
font-size:13px;
color:#64748b;
}

.payment-pending-body{
padding:22px 25px 10px;
}

.payment-pending-info{
display:grid;
grid-template-columns:1fr 1fr;
gap:10px;
margin-bottom:18px;
}

.payment-pending-info-box{
background:#f8fafc;
border:1px solid #e2e8f0;
border-radius:8px;
padding:10px 12px;
}

.payment-pending-label{
font-size:11px;
color:#64748b;
margin-bottom:4px;
}

.payment-pending-value{
font-size:14px;
font-weight:700;
color:#1e293b;
}

.payment-pending-balance{
background:#fff1f2;
border:1px solid #fecdd3;
border-radius:10px;
padding:14px;
text-align:center;
margin-bottom:15px;
}

.payment-pending-balance-label{
font-size:12px;
color:#9f1239;
margin-bottom:4px;
}

.payment-pending-balance-value{
font-size:26px;
font-weight:800;
color:#dc2626;
}

.payment-pending-message{
font-size:13px;
line-height:1.5;
text-align:center;
color:#475569;
margin:0 0 8px;
}

.payment-pending-actions{
display:flex;
gap:10px;
padding:15px 25px 22px;
}

.payment-pending-btn{
flex:1;
border:none;
border-radius:8px;
padding:11px 15px;
font-size:14px;
font-weight:700;
cursor:pointer;
}

.payment-pending-pay{
background:#198754;
color:#fff;
}

.payment-pending-pay:hover{
background:#157347;
}

.payment-pending-close{
background:#e2e8f0;
color:#334155;
}

.payment-pending-close:hover{
background:#cbd5e1;
}

@keyframes paymentPopup{
from{
transform:translateY(10px) scale(.96);
opacity:0;
}
to{
transform:translateY(0) scale(1);
opacity:1;
}
}
.action-dropdown a{
    display:block;
    padding:8px 10px;
    color:#111;
    text-decoration:none;
}
.action-dropdown a:hover{
    background:#f5f5f5;
}
.table-wrap{
    background:#fff;
    border-radius:10px;
    padding:1px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    overflow:auto;
    width:100%;
    max-width:100%;
    height:calc(100vh - 180px);
}
.prescription-modal{
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,0.7);
z-index:100001;
}

.prescription-modal-box{
width:100%;
height:100%;
margin:auto;
background:#fff;
border-radius:10px;
position:relative;
overflow:hidden;
}

.prescription-modal-close{
position:absolute;
top:15px;
right:20px;
background:red;
color:#fff;
border:none;
padding:5px 10px;
cursor:pointer;
z-index:1000;
font-size:16px;
}

#prescriptionFrame{
width:100%;
height:100%;
border:none;
display:block;
background:#fff;
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
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">
<div style="display:flex;align-items:center;margin-bottom:5px;">

<div style="flex:1;">
<h2 style="margin:0;">🏥 IPD Patient List</h2>
</div>

<div style="flex:1;text-align:right;">

<button onclick="location.href='ipd_list.php'">
Refresh
</button>

</div>

</div>
<div class="filter-box">
<form method="get">
<select name="department_id" onchange="this.form.submit()">
<option value="">Department</option>
<?php while($d=mysqli_fetch_assoc($deptRes)){ ?>
<option
value="<?= $d['id'] ?>"
<?= (($_GET['department_id']??'')==$d['id'])?'selected':'' ?>>
<?= e($d['department_name']) ?>
</option>
<?php } ?>
</select>
<select name="doctor_id" onchange="this.form.submit()">
<option value="">Doctor</option>
<?php while($doc=mysqli_fetch_assoc($doctorRes)){ ?>
<option
value="<?= $doc['id'] ?>"
<?= (($_GET['doctor_id']??'')==$doc['id'])?'selected':'' ?>>
<?= e($doc['full_name']) ?>
</option>
<?php } ?>
</select>
<select name="status" onchange="this.form.submit()">
<option value="">Status</option>
<option value="Registered"
<?= (($_GET['status']??'')=='Registered')?'selected':'' ?>>
Registered
</option>
<option value="Admitted"
<?= (($_GET['status']??'')=='Admitted')?'selected':'' ?>>
Admitted
</option>
</select>
<input
type="text"
name="uhid"
placeholder="UHID"
value="<?= e($_GET['uhid']??'') ?>">
<input
type="text"
name="patient"
placeholder="Patient Name"
value="<?= e($_GET['patient']??'') ?>">
<input
type="text"
name="mobile"
placeholder="Mobile"
value="<?= e($_GET['mobile']??'') ?>">
<input
type="date"
name="date_from"
value="<?= e($_GET['date_from']??'') ?>">
<input
type="date"
name="date_to"
value="<?= e($_GET['date_to']??'') ?>">
<button type="submit">Search</button>
<button
type="button"
onclick="window.location='ipd_list.php'">
Reset
</button>
</form>
</div>
<div class="table-wrap">
    <div>
<table>
<thead>
<tr>
<th>Action</th>
<th>ID</th>
<th>IPD No</th>
<th>UHID</th>
<th>Patient</th>
<th>Gender</th>
<th>Age</th>
<th>Mobile</th>
<th>Department</th>
<th>Doctor</th>
<th>Date</th>
<th>Time</th>
<th>Status</th>
<th>Prescription</th>
<th>Service / Charges</th>
<th>Created By</th>
<th>Created At</th>
</tr>
</thead>
<tbody>
<?php if(mysqli_num_rows($res)==0){ ?>
<tr>
<td colspan="15" style="text-align:center;padding:25px;">
No IPD Patients Found
</td>
</tr>
<?php } ?>
<?php while($r=mysqli_fetch_assoc($res)){ ?>
<tr class="<?= ((int)$r['is_emergency'] === 1) ? 'emergency-row' : '' ?>">
<td>
<div class="action-menu">
<button
type="button"
class="action-btn"
onclick="toggleActionMenu(this)">
⋮
</button>
<div class="action-dropdown">
    <?php
$dischargePrintStmt=$conn->prepare("
    SELECT id
    FROM ipd_discharge_summaries
    WHERE admission_id=?
    LIMIT 1
");

$dischargePrintStmt->bind_param(
    "i",
    $r['ipd_admission_id']
);

$dischargePrintStmt->execute();

$dischargePrintResult=$dischargePrintStmt->get_result();

$dischargePrintRow=$dischargePrintResult->fetch_assoc();

$dischargePrintStmt->close();
?>

<?php if($dischargePrintRow): ?>

<a
    href="discharge/print_discharge.php?discharge_id=<?= (int)$dischargePrintRow['id'] ?>"
    target="_blank"
>
    🖨 Print Discharge Summary
</a>

<?php endif; ?>
<a href="/modules/ipd/ipd_slip.php?appointment_id=<?= $r['id'] ?>" target="_blank">
🖨 Print IP Slip
</a>
<?php if($r['status']=="Registered"){ ?>
<a href="admission.php?appointment_id=<?= $r['id'] ?>">
🏥 Admit Patient
</a>
<?php } ?>
<?php if($r['status']=="Admitted"){ ?>
<?php } ?>
<?php
$admChk=$conn->prepare("
SELECT id
FROM ipd_admissions
WHERE appointment_id=?
LIMIT 1
");
$admChk->bind_param("i",$r['id']);
$admChk->execute();
$isAdmitted=$admChk->get_result()->num_rows>0;
$admChk->close();
?>
<?php if($isAdmitted){ ?>
<?php if(checkPermission('ipd','edit')){ ?>
<a href="shift_patient.php?appointment_id=<?= $r['id'] ?>"> 
🏥 Shift Patient 
</a>
<?php } ?>
<a href="javascript:void(0)"
onclick="showShiftHistory(<?= $r['id'] ?>)">
📜 Shift History
</a>
<?php } ?>
</div>
</div>
</td>
<td><?= $r['id'] ?></td>
<td><?= e($r['ipd_no']) ?></td>
<td><?= e($r['uhid']) ?></td>
<td>
<?= e(
$r['first_name'].' '.$r['last_name']
) ?>
</td>
<td><?= e($r['sex']) ?></td>
<td>
<?=
$r['age_y'].'Y '.
$r['age_m'].'M '.
$r['age_d'].'D'
?>
</td>
<td><?= e($r['mobile']) ?></td>
<td><?= e($r['department_name']) ?></td>
<td><?= e($r['doctor_name']) ?></td>
<td><?= e($r['appointment_date']) ?></td>
<td><?= e($r['appointment_time']) ?></td>
<td>
<?php if($r['status']=="Registered"){ ?>
<select class="ipd-status-select" onchange="changeIPDStatus(<?= (int)$r['id'] ?>,this)">
<option value="Registered" selected>Registered</option>
<option value="Admit">Admit</option>
</select>
<?php }elseif($r['status']=="Admitted"){ ?>
<select class="ipd-status-select" onchange="changeIPDStatus(<?= (int)$r['id'] ?>,this,<?= (int)$r['ipd_admission_id'] ?>)">
<option value="Admitted" selected>Admitted</option>
<option value="Discharge">Discharge</option>
</select>
<?php }elseif($r['status']=="Discharged"){ ?>
<select class="ipd-status-select" onchange="changeIPDStatus(<?= (int)$r['id'] ?>,this)">
<option value="Discharged" selected>Discharged</option>
<option value="Undischarge">Undischarge</option>
</select>
<?php }else{ ?>
<span class="badge"><?= e($r['status']) ?></span>
<?php } ?>
</td>
<td style="text-align:center;">
<?php if(checkPermission('ip_prescription','add')){ ?>
<button type="button" class="prescription-btn" title="IPD Prescription" onclick="openPrescriptionPopup(<?= (int)$r['id'] ?>)">
+
</button>
<?php } ?>
</td>

<td style="text-align:center;">
<?php if(checkPermission('ip_prescription','add')){ ?>
<button 
type="button"
class="prescription-btn"
title="Add Service / Charges"
onclick="openServicePopup(<?= (int)$r['id'] ?>,<?= (int)$r['ipd_admission_id'] ?>)">
+
</button>
<?php } ?>
</td>

<td><?= e($r['created_by']) ?></td>
<td><?= e($r['created_at']) ?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>
<script>
function toggleActionMenu(btn)
{
    document.querySelectorAll('.action-dropdown').forEach(function(menu){
        if(menu!==btn.nextElementSibling){
            menu.style.display='none';
        }
    });
    let current=btn.nextElementSibling;
    if(current.style.display==="block"){
        current.style.display="none";
    }else{
        current.style.display="block";
    }
}
document.addEventListener("click",function(e){
    if(!e.target.closest(".action-menu")){
        document.querySelectorAll(".action-dropdown").forEach(function(menu){
            menu.style.display="none";
        });
    }
});
function showShiftHistory(appointment_id)
{
    const modal=document.getElementById("shiftHistoryModal");

    modal.style.display="block";

    document.getElementById("shiftHistoryBody").innerHTML=
    "<div style='padding:40px;text-align:center'>Loading...</div>";

    fetch("ajax/shift_history.php?appointment_id="+appointment_id)
    .then(r=>r.text())
    .then(html=>{
        document.getElementById("shiftHistoryBody").innerHTML=html;
    });
}
function closeShiftHistory()
{
    document.getElementById("shiftHistoryModal").style.display="none";
}

window.onclick=function(e)
{
    const modal=document.getElementById("shiftHistoryModal");

    if(e.target===modal){
        closeShiftHistory();
    }
}
document.addEventListener("keydown",function(e){

    if(e.key==="Escape"){

        const modal=document.getElementById("shiftHistoryModal");

        if(modal.style.display==="block"){
            closeShiftHistory();
        }

    }

});
</script>
<div id="shiftHistoryModal" class="custom-modal">

<div class="custom-modal-box">

<div class="custom-modal-header">

<h3 style="margin:0;">
📜 Patient Shift History
</h3>

<button
type="button"
class="custom-close"
onclick="closeShiftHistory()">
✖
</button>

</div>

<div
class="custom-modal-body"
id="shiftHistoryBody">

<div style="padding:40px;text-align:center;">
Loading...
</div>

</div>

</div>

</div>

</div>
<script>
function changeIPDStatus(appointment_id,select,ipd_admission_id)
{
const action=select.value;

if(action==="Admit"){
window.location.href="admission.php?appointment_id="+appointment_id;
return;
}

if(action==="Discharge"){

select.value="Admitted";

fetch(
"ajax/check_discharge_invoice.php?appointment_id="+
encodeURIComponent(appointment_id)
)
.then(function(response){
return response.json();
})
.then(function(data){

if(!data.success){

if(data.status==="payment_pending"){

showPaymentPendingPopup(
data.ipd_no || '-',
data.balance_amount || 0,
ipd_admission_id,
data.message || 'Please clear the outstanding balance before discharging the patient.'
);

return;
}

if(data.status==="advance_balance"){

showPaymentPendingPopup(
data.ipd_no || '-',
data.advance_amount || 0,
ipd_admission_id,
data.message || 'Please settle the advance balance before discharging the patient.',
true
);

return;
}

if(data.status==="ledger_not_generated"){

alert(
data.message ||
"Unable to update the IPD Ledger. Please try again."
);

return;
}

alert(
data.message ||
"Unable to verify the patient's payment balance."
);

return;
}

openDischargePopup(appointment_id);

})
.catch(function(error){

console.error(error);

alert(
"Unable to verify the latest payment balance. Please try again."
);

});

return;
}

if(action==="Undischarge"){

alert(
"Undischarge process will be added later."
);

select.value="Discharged";

return;
}
}
function showPaymentPendingPopup(
ipd_no,
balance,
ipd_admission_id,
message,
isAdvance=false
)
{
const modal=document.getElementById("paymentPendingModal");

const title=document.getElementById("paymentPendingTitle");
const subtitle=document.getElementById("paymentPendingSubtitle");
const ipdNo=document.getElementById("paymentPendingIPDNo");
const balanceAmount=document.getElementById("paymentPendingBalance");
const balanceLabel=document.getElementById("paymentPendingBalanceLabel");
const messageBox=document.getElementById("paymentPendingMessage");
const payButton=document.getElementById("paymentPendingPayButton");

ipdNo.textContent=ipd_no || '-';

balanceAmount.textContent=
"₹"+Number(balance || 0).toLocaleString(
'en-IN',
{
minimumFractionDigits:2,
maximumFractionDigits:2
}
);

messageBox.textContent=message;

if(isAdvance){

title.textContent="Advance Balance";

subtitle.textContent="Patient account has an excess advance.";

balanceLabel.textContent="Advance Amount";

payButton.style.display="none";

}else{

title.textContent="Payment Pending";

subtitle.textContent="Patient cannot be discharged until the balance is cleared.";

balanceLabel.textContent="Pending Balance";

payButton.style.display="block";

payButton.onclick=function(){

window.location.href=
"/modules/billing/ipdbilling/advance.php?ipd_admission_id="+
encodeURIComponent(ipd_admission_id)+
"&mode=balance";

};

}

modal.classList.add("show");

document.body.style.overflow="hidden";
}

function closePaymentPendingPopup()
{
const modal=document.getElementById("paymentPendingModal");

modal.classList.remove("show");

document.body.style.overflow="";
}
function openDischargePopup(appointment_id)
{
const modal=document.getElementById("dischargeModal");
const body=document.getElementById("dischargeModalBody");

modal.style.display="block";
body.innerHTML="<div style='padding:50px;text-align:center;font-size:16px;'>Loading Discharge Form...</div>";

fetch("discharge/discharge_form.php?appointment_id="+encodeURIComponent(appointment_id))
.then(function(response){
if(!response.ok){
throw new Error("HTTP "+response.status);
}
return response.text();
})
.then(function(html){

body.innerHTML=html;

/* Execute scripts loaded inside discharge form */
const scripts=body.querySelectorAll("script");

scripts.forEach(function(oldScript){

const newScript=document.createElement("script");

if(oldScript.src){
newScript.src=oldScript.src;
}else{
newScript.textContent=oldScript.textContent;
}

document.body.appendChild(newScript);
oldScript.remove();

});

})
.catch(function(error){

console.error("Discharge form error:",error);

body.innerHTML="<div style='padding:30px;color:red;'>Unable to load discharge form.</div>";

});
}
function closeDischargePopup()
{
document.getElementById("dischargeModal").style.display="none";
}
function openIPDPrescription(appointment_id){
openPrescriptionPopup(appointment_id);
}
function openPrescriptionPopup(appointment_id)
{
    const frame = document.getElementById('prescriptionFrame');
    const modal = document.getElementById('prescriptionModal');

    frame.src =
        '/modules/opd/prescription_form.php?appointment_id=' +
        encodeURIComponent(appointment_id) +
        '&mode=new';

    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closePrescriptionPopup()
{
    const modal = document.getElementById('prescriptionModal');
    const frame = document.getElementById('prescriptionFrame');

    modal.style.display = 'none';
    frame.src = '';
    document.body.style.overflow = '';
}
function openServicePopup(appointment_id,ipd_admission_id=0){
const modal=document.getElementById("serviceModal");
modal.style.display="block";
document.getElementById("serviceModalBody").innerHTML=
"<div style='padding:40px;text-align:center'>Loading Services...</div>";

fetch(
"ajax/service_charge_popup.php?appointment_id="+appointment_id+
"&ipd_admission_id="+ipd_admission_id
)
.then(r=>r.text())
.then(html=>{
const modalBody=document.getElementById("serviceModalBody");
modalBody.innerHTML=html;

/* Execute scripts loaded inside popup */
const scripts=modalBody.querySelectorAll("script");

scripts.forEach(function(oldScript){
const newScript=document.createElement("script");

if(oldScript.src){
newScript.src=oldScript.src;
}else{
newScript.textContent=oldScript.textContent;
}

document.body.appendChild(newScript);
oldScript.remove();
});
})
.catch(error=>{
console.error(error);
document.getElementById("serviceModalBody").innerHTML=
"<div style='padding:40px;text-align:center;color:red'>Unable to load charges.</div>";
});
}
/* ADD THIS */
function closeServicePopup() {
    document.getElementById("serviceModal").style.display = "none";
}
</script>
<script>
function addSelectedCharges(){
const selectedCharges=[];
document.querySelectorAll('.charge-checkbox:checked').forEach(function(checkbox){
const row=checkbox.closest('tr');
const amountInput=row.querySelector('.amount-box');
selectedCharges.push({
id:checkbox.dataset.id,
appointment_id:checkbox.dataset.appointmentId,
ipd_admission_id:checkbox.dataset.ipdAdmissionId,
name:checkbox.dataset.name,
type:checkbox.dataset.type,
frequency:checkbox.dataset.frequency,
amount:amountInput ? parseFloat(amountInput.value || 0) : 0
});
});

if(selectedCharges.length===0){
alert('Please select at least one charge.');
return;
}

const paymentAmountInput=document.getElementById('payment_amount');
const paymentModeInput=document.getElementById('payment_mode');
const transactionNoInput=document.getElementById('transaction_no');
const paymentDetailsInput=document.getElementById('payment_details');
const remarksInput=document.getElementById('remarks');

const paymentAmount=paymentAmountInput ? parseFloat(paymentAmountInput.value || 0) : 0;
const paymentMode=paymentModeInput ? paymentModeInput.value : 'Cash';
const transactionNo=transactionNoInput ? transactionNoInput.value : '';
const paymentDetails=paymentDetailsInput ? paymentDetailsInput.value : '';
const remarks=remarksInput ? remarksInput.value : '';

fetch('ajax/save_service_charge.php',{
method:'POST',
headers:{
'Content-Type':'application/json'
},
body:JSON.stringify({
charges:selectedCharges,
payment_amount:paymentAmount,
payment_mode:paymentMode,
transaction_no:transactionNo,
payment_details:paymentDetails,
remarks:remarks
})
})
.then(function(response){
return response.json();
})
.then(function(data){
if(data.success){
alert(data.message);

if(data.receipt_url && data.payment_id>0){
window.open(data.receipt_url,'_blank');
}

if(typeof closePopup==='function'){
closePopup();
}

if(typeof location!=='undefined'){
location.reload();
}
}else{
alert(data.message || 'Unable to save charges and payment.');
}
})
.catch(function(error){
console.error(error);
alert('Error while saving charges and payment.');
});
}


function saveDischarge(){
    const form=document.querySelector('#dischargeModalBody form');
    if(!form){
        alert('Discharge form not found.');
        return;
    }

    const formData=new FormData(form);

    fetch('discharge/save_discharge.php',{
        method:'POST',
        body:formData
    })
    .then(response=>response.json())
    .then(data=>{
        if(data.success){
            alert(data.message||'Patient discharged successfully.');
            location.reload();
        }else{
            alert(data.message||'Unable to discharge patient.');
        }
    })
    .catch(error=>{
        console.error(error);
        alert('An error occurred while saving the discharge.');
    });
}
</script>
<div id="dischargeModal" class="discharge-modal">
<div class="discharge-modal-box">
<div class="discharge-modal-header">
<h3>🏥 Patient Discharge</h3>
<button type="button" class="discharge-close" onclick="closeDischargePopup()">✖</button>
</div>
<div id="dischargeModalBody" class="discharge-modal-body"></div>
</div>
</div>
<div id="prescriptionModal" class="prescription-modal">
    <div class="prescription-modal-box">

        <button
            type="button"
            class="prescription-modal-close"
            onclick="closePrescriptionPopup()">
            X
        </button>

        <iframe
            id="prescriptionFrame"
            src="">
        </iframe>
    </div>
</div>
<div id="serviceModal" class="custom-modal">
<div class="custom-modal-box">
<div class="custom-modal-header">
<h3 style="margin:0;">
🧾 Add Service / Charges
</h3>
<button 
type="button"
class="custom-close"
onclick="closeServicePopup()">
✖
</button>
</div>
<div 
class="custom-modal-body"
id="serviceModalBody">
Loading...
</div>
</div>
</div>
<div id="paymentPendingModal" class="payment-pending-modal">

<div class="payment-pending-box">

<div class="payment-pending-top">

<div class="payment-pending-icon">
⚠️
</div>

<h3
id="paymentPendingTitle"
class="payment-pending-title">
Payment Pending
</h3>

<div
id="paymentPendingSubtitle"
class="payment-pending-subtitle">
Patient cannot be discharged until the balance is cleared.
</div>

</div>

<div class="payment-pending-body">

<div class="payment-pending-info">

<div class="payment-pending-info-box">

<div class="payment-pending-label">
IPD No.
</div>

<div
id="paymentPendingIPDNo"
class="payment-pending-value">
-
</div>

</div>

<div class="payment-pending-info-box">

<div class="payment-pending-label">
Status
</div>

<div class="payment-pending-value">
Payment Pending
</div>

</div>

</div>

<div class="payment-pending-balance">

<div
id="paymentPendingBalanceLabel"
class="payment-pending-balance-label">
Pending Balance
</div>

<div
id="paymentPendingBalance"
class="payment-pending-balance-value">
₹0.00
</div>

</div>

<p
id="paymentPendingMessage"
class="payment-pending-message">
Please clear the outstanding balance before discharging the patient.
</p>

</div>

<div class="payment-pending-actions">

<button
type="button"
id="paymentPendingPayButton"
class="payment-pending-btn payment-pending-pay">
💳 Pay Balance
</button>

<button
type="button"
class="payment-pending-btn payment-pending-close"
onclick="closePaymentPendingPopup()">
Close
</button>

</div>

</div>

</div>
</body>
</html>