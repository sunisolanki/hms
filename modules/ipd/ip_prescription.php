<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}

if(!checkPermission('ipd','view')){
    header("Location:/access_denied.php");
    exit;
}

$current_user_id=(int)$_SESSION['user_id'];
/* =====================================
   FILTERS
===================================== */

$search=trim($_GET['search']??'');
$doctor_id=(int)($_GET['doctor_id']??0);
$department_id=(int)($_GET['department_id']??0);
$status=$_GET['status']??'';
$date_from=$_GET['date_from']??'';
$date_to=$_GET['date_to']??'';

/* =====================================
   WHERE
===================================== */

$where=[];

/*
   Only IPD prescriptions
*/
$where[]="p.patient_type='ipd'";

/*
   Doctor filter
*/
if($doctor_id>0){
    $where[]="ipd.doctor_id=".$doctor_id;
}

/*
   Department filter
*/
if($department_id>0){
    $where[]="ipd.department_id=".$department_id;
}

/*
   IPD Status
*/
if($status!=''){
    $safe=mysqli_real_escape_string($conn,$status);
    $where[]="ipd.status='$safe'";
}

/*
   Search
*/
if($search!=''){

    $safe=mysqli_real_escape_string($conn,$search);

    $where[]="(
        pat.first_name LIKE '%$safe%'
        OR pat.last_name LIKE '%$safe%'
        OR pat.mobile LIKE '%$safe%'
        OR pat.uhid LIKE '%$safe%'
        OR ipd.ipd_no LIKE '%$safe%'
        OR CONCAT('IPRX-',LPAD(p.id,6,'0')) LIKE '%$safe%'
    )";

}

/*
   Prescription Date From
*/
if($date_from!=''){

    $safe=mysqli_real_escape_string($conn,$date_from);

    $where[]="DATE(p.created_at)>='$safe'";

}

/*
   Prescription Date To
*/
if($date_to!=''){

    $safe=mysqli_real_escape_string($conn,$date_to);

    $where[]="DATE(p.created_at)<='$safe'";

}

/*
   If logged user is doctor,
   show only his prescriptions.
*/

$whereSql="";

if(count($where)>0){

    $whereSql="WHERE ".implode(" AND ",$where);

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

/* =====================================
   TOTAL PRESCRIPTIONS
===================================== */

$countSql="

SELECT COUNT(*) AS total

FROM opd_prescriptions p

INNER JOIN ipd_admissions ipd
ON ipd.appointment_id=p.appointment_id

INNER JOIN patient_master pat
ON pat.id=ipd.patient_id

$whereSql

";

$countResult=mysqli_query($conn,$countSql);

$countRow=mysqli_fetch_assoc($countResult);

$totalRows=(int)($countRow['total']??0);

/* =====================================
   PAGINATION
===================================== */

$limit=20;

$page=max(1,(int)($_GET['page']??1));

$totalPages=max(1,(int)ceil($totalRows/$limit));

if($page>$totalPages){
    $page=$totalPages;
}

$offset=($page-1)*$limit;

/* =====================================
   PRESCRIPTION LIST
===================================== */

$sql="

SELECT

p.id,

p.appointment_id,

p.created_at,

CONCAT(
'IPRX-',
LPAD(p.id,6,'0')
) AS prescription_no,

ipd.id AS admission_id,

ipd.ipd_no,

ipd.status AS ipd_status,

pat.uhid,

pat.first_name,

pat.last_name,

pat.sex,

pat.mobile,

pat.age_y,

pat.age_m,

pat.age_d,

d.department_name,

doc.full_name AS doctor_name,

creator.full_name AS created_by

FROM opd_prescriptions p

INNER JOIN ipd_admissions ipd
ON ipd.appointment_id=p.appointment_id

INNER JOIN patient_master pat
ON pat.id=ipd.patient_id

LEFT JOIN department_master d
ON d.id=ipd.department_id

LEFT JOIN users doc
ON doc.id=ipd.doctor_id

LEFT JOIN users creator
ON creator.id=p.created_by

$whereSql

ORDER BY p.created_at DESC,p.id DESC

LIMIT $offset,$limit

";

$res=mysqli_query($conn,$sql);

if(!$res){

    die("Prescription Query Failed: ".mysqli_error($conn));

}

/* =====================================
   ESCAPE
===================================== */

function e($value){

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );

}

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<title>IP Prescription List</title>

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

body{
    overflow-x:hidden;
}

*{
    box-sizing:border-box;
}

.filter-box{
    background:#fff;
    padding:10px;
    border-radius:8px;
    margin-bottom:10px;
    box-shadow:0 2px 8px rgba(0,0,0,.08);
}

.filter-box form{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}

.filter-box select{
    width:130px;
    height:36px;
}

.filter-box input[type=text]{
    width:150px;
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

table{
    width:100%;
    min-width:1250px;
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
.prescription-modal{
    display:none;
    position:fixed;
    z-index:99999;
    left:0;
    top:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,.65);
}

.prescription-modal-box{
    position:relative;
    width:100%;
    height:100%;
    margin:auto;
    background:#fff;
    border-radius:10px;
    overflow:hidden;
    box-shadow:0 10px 40px rgba(0,0,0,.35);
}

.prescription-modal-close{
    position:absolute;
    right:20px;
    top:15px;
    z-index:100000;
    width:34px;
    height:34px;
    border:none;
    background:#dc2626;
    color:#fff;
    font-size:18px;
    font-weight:bold;
    cursor:pointer;
}

#prescriptionFrame{
    width:100%;
    height:100%;
    border:none;
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

.action-dropdown a{
    display:block;
    padding:8px 10px;
    color:#111;
    text-decoration:none;
}

.action-dropdown a:hover{
    background:#f5f5f5;
}

.badge{
    padding:4px 8px;
    border-radius:4px;
    color:#fff;
    font-size:12px;
}

.badge-admit{
    background:#16a34a;
}

.badge-discharge{
    background:#2563eb;
}

.badge-cancel{
    background:#dc2626;
}

.badge-transfer{
    background:#f59e0b;
}

.badge-other{
    background:#6b7280;
}

.pagination{
    display:flex;
    gap:4px;
    margin-top:10px;
    flex-wrap:wrap;
}

.pagination a,
.pagination span{
    display:inline-block;
    padding:5px 10px;
    border:1px solid #d1d5db;
    border-radius:4px;
    text-decoration:none;
    color:#1976d2;
    background:#fff;
}

.pagination .active{
    background:#1976d2;
    color:#fff;
    border-color:#1976d2;
}

.total-info{
    margin-top:8px;
    font-size:13px;
    color:#6b7280;
}

.prescription-no{
    font-weight:600;
    color:#1976d2;
}

.patient-name{
    font-weight:600;
}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" id="mainContent" style="width:auto;">

<div class="page-container">

<div style="display:flex;align-items:center;margin-bottom:10px;">

<div style="flex:1;">

<h2 style="margin:0;">
💊 IP Prescription List
</h2>

</div>

<div style="flex:1;text-align:right;">

<button
type="button"
onclick="location.href='ip_prescription.php'">

Refresh

</button>

</div>

</div>

<div class="filter-box">

<form method="get">

<select
name="department_id"
onchange="this.form.submit()">

<option value="">
Department
</option>

<?php while($d=mysqli_fetch_assoc($deptRes)){ ?>

<option
value="<?= (int)$d['id'] ?>"
<?= ($department_id==(int)$d['id'])?'selected':'' ?>>

<?= e($d['department_name']) ?>

</option>

<?php } ?>

</select>

<select
name="doctor_id"
onchange="this.form.submit()">

<option value="">
Doctor
</option>

<?php while($doc=mysqli_fetch_assoc($doctorRes)){ ?>

<option
value="<?= (int)$doc['id'] ?>"
<?= ($doctor_id==(int)$doc['id'])?'selected':'' ?>>

<?= e($doc['full_name']) ?>

</option>

<?php } ?>

</select>

<select
name="status"
onchange="this.form.submit()">

<option value="">
IPD Status
</option>

<option
value="Registered"
<?= $status==='Registered'?'selected':'' ?>>

Registered

</option>

<option
value="Admitted"
<?= $status==='Admitted'?'selected':'' ?>>

Admitted

</option>

<option
value="Transferred"
<?= $status==='Transferred'?'selected':'' ?>>

Transferred

</option>

<option
value="Discharged"
<?= $status==='Discharged'?'selected':'' ?>>

Discharged

</option>

<option
value="Cancelled"
<?= $status==='Cancelled'?'selected':'' ?>>

Cancelled

</option>

</select>

<input
type="text"
name="search"
placeholder="Search Prescription / IPD / UHID / Patient"
value="<?= e($search) ?>">

<input
type="date"
name="date_from"
value="<?= e($date_from) ?>">

<input
type="date"
name="date_to"
value="<?= e($date_to) ?>">

<button
type="submit">

Search

</button>

<button
type="button"
onclick="window.location='ip_prescription.php'">

Reset

</button>

</form>

</div>

<div class="table-wrap">

<table>

<thead>

<tr>

<th>
Action
</th>

<th>
#
</th>

<th>
Prescription No
</th>

<th>
IPD No
</th>

<th>
UHID
</th>

<th>
Patient
</th>

<th>
Gender
</th>

<th>
Age
</th>

<th>
Mobile
</th>

<th>
Department
</th>

<th>
Doctor
</th>

<th>
Prescription Date
</th>

<th>
Status
</th>

<th>
Created By
</th>

</tr>

</thead>

<tbody>
    <?php if(mysqli_num_rows($res)==0){ ?>

<tr>

<td
colspan="14"
style="text-align:center;padding:25px;">

No IPD Prescriptions Found

</td>

</tr>

<?php }else{ ?>

<?php

$sr=$offset+1;

while($r=mysqli_fetch_assoc($res)){

?>

<tr>

<td>

<div class="action-menu">

<button
type="button"
class="action-btn"
onclick="toggleActionMenu(this)">

⋮
</button>
<div class="action-dropdown">
<?php if(checkPermission('ip_prescription','edit')){ ?>
<a
href="javascript:void(0)"
onclick="openEditPrescriptionPopup(
<?= (int)$r['id'] ?>,
<?= (int)$r['appointment_id'] ?>
)">
✏️ Edit Prescription
</a>
<?php } ?>
<a
href="/modules/ipd/ipd_slip.php?appointment_id=<?= (int)$r['appointment_id'] ?>"
target="_blank">
🖨 Print IP Slip
</a>
<a
href="/modules/opd/prescription_pdf.php?appointment_id=<?= (int)$r['appointment_id'] ?>"
target="_blank">
🖨 Print Prescription
</a>
</div>
</div>
</td>
<td>
<?= $sr++ ?>
</td>
<td>
<span class="prescription-no">
<?= e($r['prescription_no']) ?>
</span>
</td>
<td>
<?= e($r['ipd_no']) ?>
</td>
<td>
<?= e($r['uhid']) ?>
</td>
<td>
<span class="patient-name">
<?= e(
trim(
$r['first_name'].' '.$r['last_name']
)
) ?>
</span>
</td>
<td>
<?= e($r['sex']) ?>
</td>
<td>
<?php
$ageParts=[];
if((int)$r['age_y']>0){
    $ageParts[]=(int)$r['age_y'].'Y';
}
if((int)$r['age_m']>0){
    $ageParts[]=(int)$r['age_m'].'M';
}
if((int)$r['age_d']>0){
    $ageParts[]=(int)$r['age_d'].'D';
}

echo e(
count($ageParts)>0
?
implode(' ',$ageParts)
:
'-'
);
?>
</td>
<td>
<?= e($r['mobile']) ?>
</td>
<td>
<?= e($r['department_name']) ?>
</td>
<td>
<?= e($r['doctor_name']) ?>
</td>
<td>
<?= e(
date(
'd-m-Y h:i A',
strtotime($r['created_at'])
)
) ?>
</td>
<td>
<?php
$statusClass='badge-other';
if($r['ipd_status']==='Admitted'){
    $statusClass='badge-admit';
}elseif($r['ipd_status']==='Discharged'){
    $statusClass='badge-discharge';
}elseif($r['ipd_status']==='Cancelled'){
    $statusClass='badge-cancel';
}elseif($r['ipd_status']==='Transferred'){
    $statusClass='badge-transfer';
}
?>
<span class="badge <?= $statusClass ?>">
<?= e($r['ipd_status']) ?>
</span>
</td>
<td>
<?= e($r['created_by']) ?>
</td>
</tr>
<?php } ?>
<?php } ?>
</tbody>
</table>
</div>
<div class="total-info">
Showing
<strong>
<?= $totalRows>0 ? $offset+1 : 0 ?>
</strong>
to
<strong>
<?= min($offset+$limit,$totalRows) ?>
</strong>
of
<strong>
<?= $totalRows ?>
</strong>
IPD Prescription(s)
</div>
<?php if($totalPages>1){ ?>
<div class="pagination">
<?php if($page>1){ ?>
<a
href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">
« Previous
</a>
<?php } ?>
<?php
$start=max(1,$page-2);
$end=min($totalPages,$page+2);
for($i=$start;$i<=$end;$i++){
?>
<a
href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"
class="<?= $i==$page?'active':'' ?>">
<?= $i ?>
</a>
<?php } ?>
<?php if($page<$totalPages){ ?>
<a
href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">
Next »
</a>
<?php } ?>
</div>
<?php } ?>
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
function viewPrescription(prescription_id)
{
    window.open(
        "/modules/opd/prescription_form.php?prescription_id="+
        prescription_id+
        "&mode=view",
        "_blank"
    );
}
function openEditPrescriptionPopup(prescription_id,appointment_id)
{
    const frame=document.getElementById('prescriptionFrame');
    const modal=document.getElementById('prescriptionModal');

    frame.src=
        '/modules/opd/prescription_form.php?prescription_id='+
        encodeURIComponent(prescription_id)+
        '&appointment_id='+
        encodeURIComponent(appointment_id)+
        '&mode=edit';

    modal.style.display='block';
    document.body.style.overflow='hidden';
}
function closePrescriptionPopup()
{
    const modal=document.getElementById('prescriptionModal');
    const frame=document.getElementById('prescriptionFrame');

    frame.src='';
    modal.style.display='none';
    document.body.style.overflow='';
}
</script>
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
</body>
</html>