<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/login/login.php");
    exit;
}
$appointment_id=(int)($_GET['appointment_id'] ?? 0);
if($appointment_id<=0){
    die("Invalid Appointment ID");
}
/*=========================================================
FETCH APPOINTMENT + PATIENT + DOCTOR + DEPARTMENT
=========================================================*/
$stmt=$conn->prepare("
SELECT
a.*,
p.uhid,
p.abha_id,
u.full_name AS doctor_name,
d.department_name,
hm.id AS hospital_id,
hm.hospital_name,
hm.hospital_code,
hm.address AS hospital_address,
hm.city AS hospital_city,
hm.state AS hospital_state,
hm.pincode AS hospital_pincode,
hm.mobile AS hospital_mobile,
hm.phone AS hospital_phone,
hm.email AS hospital_email,
hm.website AS hospital_website,
hm.logo AS hospital_logo
FROM appointments a
LEFT JOIN patient_master p
ON p.id=a.patient_id
LEFT JOIN users u
ON u.id=a.entity_id
LEFT JOIN user_hospitals uh
ON uh.user_id=u.id

LEFT JOIN hospital_master hm
ON hm.id=uh.hospital_id
LEFT JOIN department_master d
ON d.id=a.department_id

WHERE a.id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$result=$stmt->get_result();
if(!$result->num_rows){
die("Appointment not found.");
}
$row=$result->fetch_assoc();
$stmt->close();
/*=========================================================
FETCH LATEST PRESCRIPTION
=========================================================*/
$prescription=[];
$stmt=$conn->prepare("
SELECT *
FROM opd_prescriptions
WHERE appointment_id=?
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows){
$prescription=$result->fetch_assoc();
}
$stmt->close();
/*=========================================================
FETCH LATEST PARAMETER READINGS
=========================================================*/
$parameters=[];
$stmt=$conn->prepare("
SELECT
pm.parameter_name,
pr.reading
FROM parameter_readings pr
LEFT JOIN parameter_master pm
ON pm.id=pr.parameter_id
WHERE pr.appointment_id=?
ORDER BY
pm.parameter_name
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$result=$stmt->get_result();
while($r=$result->fetch_assoc()){
$parameters[]=$r;
}
$stmt->close();
/*=========================================================
AGE
=========================================================*/
$age=$row['age_y'].'Y';
if($row['age_m']>0){
$age.=' '.$row['age_m'].'M';
}
if($row['age_d']>0){
$age.=' '.$row['age_d'].'D';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>OP Consultation Slip (Rx)</title>
<meta
name="viewport"
content="width=device-width, initial-scale=1.0">
<style>
/* A4 PRINT OPTIMIZED LAYOUT - DATA PRESERVED */
@page{
size:A4 portrait;
margin:8mm;
}
*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:Arial,Helvetica,sans-serif;
}

body{
background:#fff;
color:#000;
font-size:13px;
}

.page{
width:210mm;
min-height:287mm;
margin:auto;
background:#fff;
padding:1mm 8mm;
}

.header{
display:flex;
align-items:center;
border-bottom:2px solid #000;
padding-bottom:6px;
margin-bottom:8px;
}

.logo{
width:80px;
text-align:center;
flex-shrink:0;
}

.logo img{
width:70px;
height:70px;
object-fit:contain;
}

.hospital{
flex:1;
text-align:center;
padding:0 4px;
}

.hospital h1{
font-size:26px;
margin-bottom:3px;
font-weight:bold;
letter-spacing:0.5px;
}

.branch{
font-size:16px;
font-weight:bold;
margin-bottom:3px;
}

.address{
font-size:12px;
line-height:1.5;
}

.title{
margin-top:8px;
text-align:center;
font-size:20px;
font-weight:bold;
border:2px solid #000;
padding:6px;
letter-spacing:1px;
background:#f9f9f9;
}

.patient-table{
width:100%;
border-collapse:collapse;
margin-top:8px;
font-size:12px;
}

.patient-table td{
border:1px solid #000;
padding:4px 6px;
vertical-align:middle;
}

.patient-table .label{
width:100px;
font-weight:bold;
background:#f5f5f5;
white-space:nowrap;
}

.section-title{
margin-top:12px;
font-size:15px;
font-weight:bold;
border-bottom:2px solid #000;
padding-bottom:3px;
}

.write-box{
border:1px solid #000;
min-height:60px;
padding:5px 7px;
margin-top:4px;
background:#fff;
}

.rx-box{
border:2px solid #000;
min-height:280px;
margin-top:4px;
padding:8px 10px;
background:#fff;
}

.rx-line{
height:26px;
border-bottom:1px dotted #999;
}

.footer{
margin-top:25px;
display:flex;
justify-content:space-between;
}

.signature{
width:200px;
text-align:center;
padding-top:35px;
border-top:1px solid #000;
font-weight:bold;
font-size:13px;
}

.signature small{
font-weight:normal;
font-size:11px;
display:block;
margin-top:4px;
}

.footer-note{
margin-top:15px;
text-align:center;
font-size:10px;
color:#666;
border-top:1px solid #ccc;
padding-top:6px;
}

@media print{
body{
margin:0;
background:#fff;
}
.page{
width:100%;
min-height:auto;
padding:6mm 8mm;
box-shadow:none;
}
.no-print{
display:none;
}
}
</style>
</head>
<body>

<div class="page">
    <!-- =======================================================
HOSPITAL HEADER
======================================================= -->

<div class="header">
<div class="logo">

<?php
$logo='';

if(!empty($row['hospital_logo'])){
    $logo='/'.$row['hospital_logo'];
    
}

if($logo!='' && file_exists($_SERVER['DOCUMENT_ROOT'].$logo)){
?>
    <img src="<?= $logo ?>" height="70">
<?php } ?>

</div>

<div class="hospital">

<h1><?= htmlspecialchars($row['hospital_name']) ?></h1>

<?php if(!empty($row['branch_name'])){ ?>

<div class="branch">
<?= htmlspecialchars($row['branch_name']?? '') ?>
</div>

<?php } ?>

<div class="address">

<?php

if(!empty($row['branch_address'])){
echo htmlspecialchars($row['branch_address']?? '');
}else{
echo htmlspecialchars($row['hospital_address']?? '');
}

?>

<br>

<?php

echo htmlspecialchars($row['hospital_city']);
?>
,
<?php
echo htmlspecialchars($row['hospital_state']);
?>
-
<?=
htmlspecialchars(
$row['hospital_pincode']
?? '')
?>

<br>

📞

<?=
htmlspecialchars($row['hospital_mobile'])
?>

<?php

if(!empty($row['hospital_phone'])){

echo " | ";

echo htmlspecialchars($row['hospital_phone']?? '');

}

?>

<br>

<?php

if(!empty($row['branch_email'])){

?>

✉

<?= htmlspecialchars($row['branch_email']?? '') ?>

<?php

}elseif(!empty($row['hospital_email'])){

?>

✉

<?= htmlspecialchars($row['hospital_email']?? '') ?>

<?php } ?>


<?php

if(!empty($row['branch_website'])){

?>

|

🌐

<?= htmlspecialchars($row['branch_website']?? '') ?>

<?php

}elseif(!empty($row['hospital_website'])){

?>

|

🌐

<?= htmlspecialchars($row['hospital_website']?? '') ?>

<?php } ?>

</div>

</div>

</div>

<div class="title">

OP CONSULTATION SLIP (Rx)

</div>
<!-- =======================================================
PATIENT DETAILS
======================================================= -->

<table class="patient-table">

<tr>

<td class="label">UHID</td>
<td><?= htmlspecialchars($row['uhid']?? '') ?></td>

<td class="label">OPD No.</td>
<td><?= htmlspecialchars($row['opd_no']?? '') ?></td>

<td class="label">Token</td>
<td><?= htmlspecialchars($row['token_no']?? '') ?></td>

</tr>

<tr>

<td class="label">Date</td>
<td>
<?= date('d-m-Y',strtotime($row['appointment_date'])) ?>
</td>

<td class="label">Time</td>
<td>
<?= htmlspecialchars($row['appointment_time']) ?>
</td>

<td class="label">Visit</td>
<td>
<?= htmlspecialchars($row['visit_type']) ?>
</td>

</tr>

<tr>

<td class="label">Patient Name</td>
<td colspan="3">
<?= htmlspecialchars(
trim(
$row['title'].' '.
$row['first_name'].' '.
$row['last_name']
)
) ?>
</td>

<td class="label">Gender</td>
<td><?= htmlspecialchars($row['sex']) ?></td>

</tr>

<tr>

<td class="label">Age</td>
<td><?= htmlspecialchars($age) ?></td>

<td class="label">Mobile</td>
<td><?= htmlspecialchars($row['mobile']) ?></td>

<td class="label">ABHA ID</td>
<td><?= htmlspecialchars($row['abha_id']) ?></td>

</tr>

<tr>

<td class="label">Doctor</td>
<td colspan="3">
<?= htmlspecialchars($row['doctor_name']) ?>
</td>

<td class="label">Department</td>
<td>
<?= htmlspecialchars($row['department_name']) ?>
</td>
</tr>
</table>
<!-- =======================================================
VITALS
======================================================= -->

<?php

$weight='';
$height='';
$bmi='';
$pain='';

if(!empty($prescription)){
$weight=$prescription['weight'] ?? '';
$height=$prescription['height'] ?? '';
$bmi=$prescription['bmi'] ?? '';
$pain=$prescription['pain_scale'] ?? '';
}

?>

<div class="section-title">
Vitals
</div>

<table class="patient-table">

<tr>

<td class="label">Weight</td>
<td>
<?= $weight!='' ? htmlspecialchars($weight).' Kg' : '' ?>
</td>

<td class="label">Height</td>
<td>
<?= $height!='' ? htmlspecialchars($height).' cm' : '' ?>
</td>

<td class="label">BMI</td>
<td>
<?= htmlspecialchars($bmi) ?>
</td>

<td class="label">Pain Scale</td>
<td>
<?= htmlspecialchars($pain) ?>
</td>

</tr>

</table>

<?php if(!empty($parameters)){ ?>

<div style="margin-top:6px;">

<table class="patient-table">

<tr>

<?php foreach($parameters as $p){ ?>

<th style="border:1px solid #000;padding:4px 6px;background:#f5f5f5;font-size:12px;">

<?= htmlspecialchars($p['parameter_name']) ?>

</th>

<?php } ?>

</tr>

<tr>

<?php foreach($parameters as $p){ ?>

<td style="text-align:center;font-weight:bold;border:1px solid #000;padding:4px 6px;font-size:12px;">

<?= htmlspecialchars($p['reading']) ?>

</td>

<?php } ?>

</tr>

</table>
</div>
<?php } ?>
<!-- =======================================================
HAND WRITTEN PRESCRIPTION
======================================================= -->
<div class="section-title">
Prescription (Rx)
</div>
<div class="rx-box">
<?php for($i=1;$i<=10;$i++){ ?>
<div class="rx-line"></div>
<?php } ?>
</div>
<!-- =======================================================
INVESTIGATION
<div class="section-title">
Investigation
</div>
<div class="write-box" style="min-height:60px;">
</div>
======================================================= -->
<!-- =======================================================
ADVICE
======================================================= -->
<div class="section-title">
Advice
</div>
<div class="write-box" style="min-height:40px;">
</div>
<!-- =======================================================
FOLLOW UP
======================================================= -->

<table class="patient-table" style="margin-top:12px;">

<tr>

<td class="label" style="width:160px;">
Follow Up Date
</td>

<td style="height:40px;"></td>

<td class="label" style="width:160px;">
Next Visit
</td>

<td></td>

</tr>

</table>

<!-- =======================================================
SIGNATURE AREA
======================================================= -->

<div class="footer">

<div class="signature">

Patient / Attendant Signature

<small>Date &amp; Time</small>

</div>

<div class="signature">

Doctor Signature

<br><br>

<b><?= htmlspecialchars($row['doctor_name']) ?></b>

<?php if(!empty($row['department_name'])){ ?>

<br>

<?= htmlspecialchars($row['department_name']) ?>

<?php } ?>

<small>Reg. No.</small>

</div>

</div>

<!-- =======================================================
FOOTER
======================================================= -->

<div class="footer-note">

Computer Generated OP Consultation Slip (Rx)

</div>

</div>

<script>

window.onload=function(){

window.print();

};

</script>

</body>

</html>