<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
/* ===========================================================
   HELPERS
=========================================================== */
function e($value){
return htmlspecialchars((string)($value??''),ENT_QUOTES,'UTF-8');
}
function showValue($value,$default='-'){
$value=trim((string)($value??''));
return $value!==''?e($value):$default;
}
function formatDateValue($date){
if(empty($date) || $date==='0000-00-00'){
return '-';
}
$timestamp=strtotime($date);
return $timestamp?date('d-m-Y',$timestamp):e($date);
}
function formatTimeValue($time){
if(empty($time)){
return '-';
}
$timestamp=strtotime($time);
return $timestamp?date('h:i A',$timestamp):e($time);
}
/* ===========================================================
   CERTIFICATE ID
=========================================================== */
$certificate_id=isset($_GET['id'])?(int)$_GET['id']:0;
if($certificate_id<=0){
die('Invalid New Born Certificate ID.');
}
/* ===========================================================
   GET CERTIFICATE
=========================================================== */
$stmt=$conn->prepare("
SELECT *
FROM newborn_certificates
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$certificate_id);
$stmt->execute();
$result=$stmt->get_result();
$certificate=$result->fetch_assoc();
$stmt->close();
if(!$certificate){
die('New Born Certificate not found.');
}
/* ===========================================================
   GET HOSPITAL
=========================================================== */
$hospital=[];
if(!empty($certificate['hospital_id'])){
$hospital_id=(int)$certificate['hospital_id'];
$stmt=$conn->prepare("
SELECT *
FROM hospital_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$hospital_id);
$stmt->execute();
$result=$stmt->get_result();
$hospital=$result->fetch_assoc()?:[];
$stmt->close();
}
/* ===========================================================
   GET BRANCH
=========================================================== */
$branch=[];
if(!empty($certificate['branch_id'])){
$branch_id=(int)$certificate['branch_id'];
$stmt=$conn->prepare("
SELECT *
FROM branch_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$result=$stmt->get_result();
$branch=$result->fetch_assoc()?:[];
$stmt->close();
}
/* ===========================================================
   GET OBSERVATIONS
=========================================================== */
$observations=[];
$stmt=$conn->prepare("
SELECT
id,
sr_no,
vital_observation,
vaccination_given
FROM newborn_certificate_observations
WHERE newborn_certificate_id=?
ORDER BY sr_no ASC,id ASC
");
$stmt->bind_param("i",$certificate_id);
$stmt->execute();
$result=$stmt->get_result();
while($row=$result->fetch_assoc()){
$observations[]=$row;
}
$stmt->close();
/* ===========================================================
   HOSPITAL DETAILS
=========================================================== */
$hospital_name=trim((string)($hospital['hospital_name']??''));
$hospital_address=trim((string)($hospital['address']??''));
$hospital_city=trim((string)($hospital['city']??''));
$hospital_state=trim((string)($hospital['state']??''));
$hospital_pincode=trim((string)($hospital['pincode']??''));
$hospital_mobile=trim((string)($hospital['mobile']??''));
$hospital_phone=trim((string)($hospital['phone']??''));
$hospital_email=trim((string)($hospital['email']??''));
$hospital_website=trim((string)($hospital['website']??''));
/* ===========================================================
   BRANCH
=========================================================== */
$branch_name=trim((string)($branch['branch_name']??''));
/* ===========================================================
   HOSPITAL ADDRESS
=========================================================== */
$address_parts=[];
if($hospital_address!==''){
$address_parts[]=$hospital_address;
}
if($hospital_city!==''){
$address_parts[]=$hospital_city;
}
if($hospital_state!==''){
$address_parts[]=$hospital_state;
}
if($hospital_pincode!==''){
$address_parts[]=$hospital_pincode;
}
$full_hospital_address=implode(', ',$address_parts);
/* ===========================================================
   CONTACT
=========================================================== */
$contact_parts=[];
if($hospital_mobile!==''){
$contact_parts[]='Mobile: '.$hospital_mobile;
}
if($hospital_phone!==''){
$contact_parts[]='Phone: '.$hospital_phone;
}
if($hospital_email!==''){
$contact_parts[]='Email: '.$hospital_email;
}
$contact_line=implode(' | ',$contact_parts);
/* ===========================================================
   YES / NO VALUES
=========================================================== */
$first_pregnancy=!empty($certificate['first_pregnancy'])?'Yes':'No';
$anaesthesia=!empty($certificate['anaesthesia'])?'Yes':'No';
$oxygen_required=!empty($certificate['oxygen_required'])?'Yes':'No';
$jaundice=!empty($certificate['jaundice'])?'Yes':'No';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>New Born Certificate - <?= e($certificate['certificate_no']) ?></title>
<style>
*{
box-sizing:border-box;
}
body{
margin:0;
padding:0;
background:#f1f3f5;
font-family:Arial,Helvetica,sans-serif;
font-size:12px;
color:#111;
}
.print-toolbar{
width:210mm;
margin:15px auto 8px auto;
display:flex;
justify-content:flex-end;
gap:8px;
}
.print-toolbar button{
border:0;
border-radius:4px;
padding:8px 16px;
font-size:13px;
font-weight:600;
cursor:pointer;
}
.btn-print{
background:#198754;
color:#fff;
}
.btn-close{
background:#6c757d;
color:#fff;
}
.page{
width:210mm;
min-height:297mm;
margin:0 auto 20px auto;
background:#fff;
padding:9mm 10mm;
box-shadow:0 0 5px rgba(0,0,0,.18);
}
.hospital-header{
text-align:center;
padding-bottom:7px;
border-bottom:2px solid #111;
}
.hospital-name{
font-size:22px;
font-weight:700;
text-transform:uppercase;
margin-bottom:3px;
}
.branch-name{
font-size:13px;
font-weight:600;
margin-bottom:3px;
}
.hospital-address{
font-size:11px;
line-height:1.4;
}
.certificate-title{
text-align:center;
font-size:18px;
font-weight:700;
text-transform:uppercase;
text-decoration:underline;
margin:10px 0;
}
.meta-table,
.detail-table,
.observation-table{
width:100%;
border-collapse:collapse;
}
.meta-table td{
padding:4px 5px;
vertical-align:top;
}
.meta-label{
font-weight:700;
white-space:nowrap;
}
.section-title{
margin-top:8px;
background:#ededed;
border:1px solid #777;
padding:5px 7px;
font-size:12px;
font-weight:700;
text-transform:uppercase;
}
.detail-table{
border-left:1px solid #777;
border-top:1px solid #777;
}
.detail-table td{
border-right:1px solid #777;
border-bottom:1px solid #777;
padding:5px 6px;
vertical-align:top;
line-height:1.35;
}
.detail-table .label{
font-weight:700;
background:#f8f8f8;
width:17%;
}
.detail-table .value{
width:33%;
}
.observation-table th,
.observation-table td{
border:1px solid #777;
padding:5px 6px;
vertical-align:top;
}
.observation-table th{
background:#f1f1f1;
font-weight:700;
text-align:left;
}
.text-center{
text-align:center!important;
}
.signature-section{
margin-top:30px;
display:flex;
justify-content:space-between;
align-items:flex-end;
}
.signature-box{
width:40%;
text-align:center;
}
.doctor-details{
min-height:40px;
margin-bottom:5px;
}
.signature-line{
border-top:1px solid #111;
padding-top:5px;
font-weight:700;
}
.footer-note{
margin-top:18px;
padding-top:6px;
border-top:1px solid #aaa;
font-size:9px;
text-align:center;
color:#555;
}
@page{
size:A4;
margin:7mm;
}
@media print{
body{
background:#fff;
font-size:10px;
}
.print-toolbar{
display:none!important;
}
.page{
width:auto;
min-height:auto;
margin:0;
padding:0;
box-shadow:none;
}
.section-title{
margin-top:6px;
padding:4px 6px;
}
.detail-table td,
.observation-table th,
.observation-table td{
padding:4px 5px;
}
.signature-section{
margin-top:22px;
}
}
</style>
</head>
<body>
<div class="print-toolbar">
<button type="button" class="btn-close" onclick="closePrintPage()">Close</button>
<button type="button" class="btn-print" onclick="window.print()">Print</button>
</div>
<div class="page">
<div class="hospital-header">
<div class="hospital-name">
<?= $hospital_name!==''?e($hospital_name):'Hospital' ?>
</div>
<?php if($branch_name!==''){ ?>
<div class="branch-name"><?= e($branch_name) ?></div>
<?php } ?>
<?php if($full_hospital_address!==''){ ?>
<div class="hospital-address">
<?= e($full_hospital_address) ?>
</div>
<?php } ?>
<?php if($contact_line!==''){ ?>
<div class="hospital-address">
<?= e($contact_line) ?>
</div>
<?php } ?>
<?php if($hospital_website!==''){ ?>
<div class="hospital-address">
<?= e($hospital_website) ?>
</div>
<?php } ?>
</div>
<div class="certificate-title">
New Born Certificate
</div>
<table class="meta-table">
<tr>
<td>
<span class="meta-label">Certificate No.:</span>
<?= showValue($certificate['certificate_no']) ?>
</td>
<td style="text-align:right;">
<span class="meta-label">Certificate Date:</span>
<?= formatDateValue($certificate['certificate_date']) ?>
</td>
</tr>
</table>
<!-- =========================================================
     MOTHER DETAILS
========================================================= -->
<div class="section-title">
Mother / IPD Information
</div>
<table class="detail-table">
<tr>
<td class="label">IPD No.</td>
<td class="value"><?= showValue($certificate['ipd_no']) ?></td>
<td class="label">UHID</td>
<td class="value"><?= showValue($certificate['uhid']) ?></td>
</tr>
<tr>
<td class="label">Mother Name</td>
<td class="value"><?= showValue($certificate['mother_name']) ?></td>
<td class="label">Guardian Name</td>
<td class="value"><?= showValue($certificate['guardian_name']) ?></td>
</tr>
<tr>
<td class="label">DOB</td>
<td class="value"><?= formatDateValue($certificate['dob']) ?></td>
<td class="label">Age</td>
<td class="value"><?= showValue($certificate['age']) ?></td>
</tr>
<tr>
<td class="label">Gender</td>
<td class="value"><?= showValue($certificate['sex']) ?></td>
<td class="label">Marital Status</td>
<td class="value"><?= showValue($certificate['marital_status']) ?></td>
</tr>
<tr>
<td class="label">Mobile</td>
<td class="value"><?= showValue($certificate['mobile']) ?></td>
<td class="label">Admission</td>
<td class="value">
<?= formatDateValue($certificate['admission_date']) ?>
&nbsp;
<?= formatTimeValue($certificate['admission_time']) ?>
</td>
</tr>
<tr>
<td class="label">Address</td>
<td colspan="3">
<?= showValue($certificate['address']) ?>
</td>
</tr>
</table>
<!-- =========================================================
     ADMISSION DETAILS
========================================================= -->
<div class="section-title">
Admission / Doctor Information
</div>
<table class="detail-table">
<tr>
<td class="label">Department</td>
<td class="value"><?= showValue($certificate['department_name']) ?></td>
<td class="label">Doctor</td>
<td class="value"><?= showValue($certificate['doctor_name']) ?></td>
</tr>
<tr>
<td class="label">Registration No.</td>
<td class="value"><?= showValue($certificate['doctor_registration_no']) ?></td>
<td class="label">Room / Ward</td>
<td class="value"><?= showValue($certificate['room_ward_type']) ?></td>
</tr>
<tr>
<td class="label">Bed Detail</td>
<td class="value"><?= showValue($certificate['bed_detail']) ?></td>
<td class="label">Patient Status</td>
<td class="value"><?= showValue($certificate['patient_status']) ?></td>
</tr>
</table>
<!-- =========================================================
     DELIVERY INFORMATION
========================================================= -->
<div class="section-title">
Delivery Information
</div>
<table class="detail-table">
<tr>
<td class="label">First Pregnancy</td>
<td class="value"><?= e($first_pregnancy) ?></td>
<td class="label">Anaesthesia</td>
<td class="value"><?= e($anaesthesia) ?></td>
</tr>
<tr>
<td class="label">Delivery Type</td>
<td class="value"><?= showValue($certificate['delivery_type']) ?></td>
<td class="label">Last Child Age</td>
<td class="value"><?= showValue($certificate['last_child_age']) ?></td>
</tr>
<tr>
<td class="label">Lead Surgeon</td>
<td class="value"><?= showValue($certificate['lead_surgeon_name']) ?></td>
<td class="label">Second Surgeon</td>
<td class="value"><?= showValue($certificate['second_surgeon_name']) ?></td>
</tr>
<tr>
<td class="label">Assistant</td>
<td class="value"><?= showValue($certificate['assistant_name']) ?></td>
<td class="label">Delivery Date / Time</td>
<td class="value">
<?= formatDateValue($certificate['delivery_date']) ?>
&nbsp;
<?= formatTimeValue($certificate['delivery_time']) ?>
</td>
</tr>
</table>
<!-- =========================================================
     BABY DETAILS
========================================================= -->
<div class="section-title">
New Born Baby Information
</div>
<table class="detail-table">
<tr>
<td class="label">Born Child</td>
<td class="value"><?= showValue($certificate['born_child']) ?></td>
<td class="label">Alive Child</td>
<td class="value"><?= showValue($certificate['alive_child']) ?></td>
</tr>
<tr>
<td class="label">Still Born</td>
<td class="value"><?= showValue($certificate['still_born_count']) ?></td>
<td class="label">Baby Gender</td>
<td class="value"><?= showValue($certificate['baby_gender']) ?></td>
</tr>
<tr>
<td class="label">Baby Weight</td>
<td class="value">
<?php
if($certificate['baby_weight']!==NULL && $certificate['baby_weight']!==''){
echo e($certificate['baby_weight']).' Kg';
}else{
echo '-';
}
?>
</td>
<td class="label">Baby Length</td>
<td class="value">
<?php
if($certificate['baby_length']!==NULL && $certificate['baby_length']!==''){
echo e($certificate['baby_length']).' cm';
}else{
echo '-';
}
?>
</td>
</tr>
</table>
<!-- =========================================================
     CONDITION AT BIRTH
========================================================= -->
<div class="section-title">
Condition at Birth
</div>
<table class="detail-table">
<tr>
<td class="label">Oxygen Required</td>
<td class="value"><?= e($oxygen_required) ?></td>
<td class="label">Jaundice</td>
<td class="value"><?= e($jaundice) ?></td>
</tr>
<tr>
<td class="label">Oxygen Duration</td>
<td class="value"><?= showValue($certificate['oxygen_duration']) ?></td>
<td class="label">Head Shape</td>
<td class="value"><?= showValue($certificate['head_shape']) ?></td>
</tr>
<tr>
<td class="label">1 Minute APGAR</td>
<td class="value"><?= showValue($certificate['apgar_1_min']) ?></td>
<td class="label">5 Minute APGAR</td>
<td class="value"><?= showValue($certificate['apgar_5_min']) ?></td>
</tr>
</table>
<!-- =========================================================
     OBSERVATIONS
========================================================= -->
<div class="section-title">
Vital Observation / Vaccination
</div>
<table class="observation-table">
<thead>
<tr>
<th style="width:60px;" class="text-center">
Sr. No.
</th>
<th>
Vital Observation
</th>
<th>
Vaccination Given
</th>
</tr>
</thead>
<tbody>
<?php if(!empty($observations)){ ?>
<?php foreach($observations as $index=>$observation){ ?>
<tr>
<td class="text-center">
<?= $index+1 ?>
</td>
<td>
<?= showValue($observation['vital_observation']) ?>
</td>
<td>
<?= showValue($observation['vaccination_given']) ?>
</td>
</tr>
<?php } ?>
<?php }else{ ?>
<tr>
<td colspan="3" class="text-center">
No observation recorded.
</td>
</tr>
<?php } ?>
</tbody>
</table>
<!-- =========================================================
     REMARKS
========================================================= -->
<?php if(!empty($certificate['remarks'])){ ?>
<div class="section-title">
Remarks
</div>
<table class="detail-table">
<tr>
<td>
<?= nl2br(e($certificate['remarks'])) ?>
</td>
</tr>
</table>
<?php } ?>
<!-- =========================================================
     SIGNATURE
========================================================= -->
<div class="signature-section">
<div class="signature-box">
<div class="doctor-details"></div>
<div class="signature-line">
Mother / Attendant Signature
</div>
</div>
<div class="signature-box">
<div class="doctor-details">
<?php if(!empty($certificate['doctor_name'])){ ?>
<strong>
<?= e($certificate['doctor_name']) ?>
</strong>
<br>
<?php } ?>
<?php if(!empty($certificate['doctor_registration_no'])){ ?>
Reg. No.:
<?= e($certificate['doctor_registration_no']) ?>
<?php } ?>
</div>
<div class="signature-line">
Doctor Signature
</div>
</div>
</div>
<div class="footer-note">
This certificate has been generated from the Hospital Management System.
<br>
Certificate No.: <?= e($certificate['certificate_no']) ?>
</div>
</div>
<script>
function closePrintPage(){
if(window.opener){
window.close();
return;
}
if(window.parent && window.parent!==window){
window.parent.postMessage("close_newborn_print","*");
return;
}
window.history.back();
}
</script>
</body>
</html>