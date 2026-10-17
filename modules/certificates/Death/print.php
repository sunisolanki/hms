<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if(!isset($_SESSION['user_id'])){
header("Location:/index.php");
exit;
}
if(!checkPermission('death_certificate_list','view')){
header("Location:/access_denied.php");
exit;
}
function e($value){
return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}
function formatDate($date){
if(empty($date) || $date=='0000-00-00'){
return '-';
}
return date('d-m-Y',strtotime($date));
}
function formatTime($time){
if(empty($time) || $time=='00:00:00'){
return '-';
}
return date('h:i A',strtotime($time));
}
/* ============================================================
   CERTIFICATE ID
============================================================ */
$certificate_id=isset($_GET['id'])?(int)$_GET['id']:0;
if($certificate_id<=0){
die("Invalid Death Certificate ID");
}
/* ============================================================
   GET DEATH CERTIFICATE
============================================================ */
$sql="
SELECT
dc.id,
dc.certificate_no,
dc.certificate_date,
dc.patient_type,
dc.patient_id,
dc.appointment_id,
dc.ipd_admission_id,
dc.uhid,
dc.patient_name,
dc.guardian_name,
dc.mobile,
dc.dob,
dc.age_y,
dc.age_m,
dc.age_d,
dc.age,
dc.sex,
dc.marital_status,
dc.address,
dc.date_of_death,
dc.time_of_death,
dc.place_of_death,
dc.place_of_death_other,
dc.immediate_cause,
dc.antecedent_cause,
dc.underlying_cause,
dc.other_significant_conditions,
dc.manner_of_death,
dc.manner_of_death_other,
dc.remarks,
dc.doctor_id,
dc.doctor_name,
dc.doctor_registration_no,
dc.hospital_id,
dc.branch_id,
dc.qr_token,
dc.status,
dc.created_by,
dc.created_at,
dc.updated_by,
dc.updated_at,
hm.hospital_code,
hm.hospital_name,
hm.address AS hospital_address,
hm.city AS hospital_city,
hm.district AS hospital_district,
hm.state AS hospital_state,
hm.country AS hospital_country,
hm.pincode AS hospital_pincode,
hm.mobile AS hospital_mobile,
hm.phone AS hospital_phone,
hm.email AS hospital_email,
hm.website AS hospital_website,
hm.logo AS hospital_logo,
hm.secondary_logo AS hospital_secondary_logo,
bm.branch_code,
bm.branch_name,
bm.address AS branch_address,
bm.city AS branch_city,
bm.district AS branch_district,
bm.state AS branch_state,
bm.country AS branch_country,
bm.mobile AS branch_mobile,
bm.email AS branch_email,
bm.website AS branch_website,
bm.logo AS branch_logo
FROM death_certificates dc
LEFT JOIN hospital_master hm
ON hm.id=dc.hospital_id
LEFT JOIN branch_master bm
ON bm.id=dc.branch_id
WHERE dc.id=?
LIMIT 1
";
$stmt=$conn->prepare($sql);
if(!$stmt){
die("Database Error: ".$conn->error);
}
$stmt->bind_param("i",$certificate_id);
$stmt->execute();
$result=$stmt->get_result();
if(!$certificate=$result->fetch_assoc()){
$stmt->close();
die("Death Certificate not found.");
}
$stmt->close();
/* ============================================================
   PREPARE PATIENT VALUES
============================================================ */
$certificate_no=trim($certificate['certificate_no']??'');
$certificate_date=formatDate($certificate['certificate_date']??'');
$patient_name=trim($certificate['patient_name']??'');
$guardian_name=trim($certificate['guardian_name']??'');
$mobile=trim($certificate['mobile']??'');
$uhid=trim((string)($certificate['uhid']??''));
$dob=formatDate($certificate['dob']??'');
$age=trim($certificate['age']??'');
if($age===''){
$age_parts=[];
if((int)($certificate['age_y']??0)>0){
$age_parts[]=(int)$certificate['age_y'].' Year';
}
if((int)($certificate['age_m']??0)>0){
$age_parts[]=(int)$certificate['age_m'].' Month';
}
if((int)($certificate['age_d']??0)>0){
$age_parts[]=(int)$certificate['age_d'].' Day';
}
$age=implode(' ',$age_parts);
}
$sex=trim($certificate['sex']??'');
$marital_status=trim($certificate['marital_status']??'');
$address=trim($certificate['address']??'');
/* ============================================================
   DEATH DETAILS
============================================================ */
$date_of_death=formatDate($certificate['date_of_death']??'');
$time_of_death=formatTime($certificate['time_of_death']??'');
$place_of_death=trim($certificate['place_of_death']??'');
if($place_of_death==='Other' && !empty($certificate['place_of_death_other'])){
$place_of_death=trim($certificate['place_of_death_other']);
}
$immediate_cause=trim($certificate['immediate_cause']??'');
$antecedent_cause=trim($certificate['antecedent_cause']??'');
$underlying_cause=trim($certificate['underlying_cause']??'');
$other_significant_conditions=trim($certificate['other_significant_conditions']??'');
$manner_of_death=trim($certificate['manner_of_death']??'');
if($manner_of_death==='Other' && !empty($certificate['manner_of_death_other'])){
$manner_of_death=trim($certificate['manner_of_death_other']);
}
$remarks=trim($certificate['remarks']??'');
/* ============================================================
   DOCTOR / STATUS
============================================================ */
$doctor_name=trim($certificate['doctor_name']??'');
$doctor_registration_no=trim($certificate['doctor_registration_no']??'');
$status=trim($certificate['status']??'Done');
$is_cancelled=($status==='Cancelled');
/* ============================================================
   HOSPITAL / BRANCH DISPLAY VALUES
============================================================ */
$hospital_name=trim($certificate['hospital_name']??'');
$branch_name=trim($certificate['branch_name']??'');
$print_address=trim($certificate['branch_address']??'');
if($print_address===''){
$print_address=trim($certificate['hospital_address']??'');
}
$print_city=trim($certificate['branch_city']??'');
if($print_city===''){
$print_city=trim($certificate['hospital_city']??'');
}
$print_state=trim($certificate['branch_state']??'');
if($print_state===''){
$print_state=trim($certificate['hospital_state']??'');
}
$print_mobile=trim($certificate['branch_mobile']??'');
if($print_mobile===''){
$print_mobile=trim($certificate['hospital_mobile']??'');
}
$print_email=trim($certificate['branch_email']??'');
if($print_email===''){
$print_email=trim($certificate['hospital_email']??'');
}
$print_website=trim($certificate['branch_website']??'');
if($print_website===''){
$print_website=trim($certificate['hospital_website']??'');
}
/* ============================================================
   LOGO
============================================================ */
$logo='';
if(!empty($certificate['branch_logo'])){
$logo=$certificate['branch_logo'];
}elseif(!empty($certificate['hospital_logo'])){
$logo=$certificate['hospital_logo'];
}
if($logo!='' && substr($logo,0,1)!='/'){
$logo='/'.$logo;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Death Certificate - <?= e($certificate_no) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{
box-sizing:border-box;
}
html,body{
margin:0;
padding:0;
background:#eef1f5;
font-family:Arial,Helvetica,sans-serif;
color:#111;
font-size:14px;
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
border-radius:5px;
padding:9px 16px;
font-size:14px;
cursor:pointer;
}
.btn-print{
background:#1976d2;
color:#fff;
}
.btn-back{
background:#555;
color:#fff;
}
.certificate-page{
width:210mm;
min-height:297mm;
margin:0 auto 20px auto;
background:#fff;
padding:12mm 14mm;
position:relative;
box-shadow:0 2px 15px rgba(0,0,0,.12);
overflow:hidden;
}
.certificate-border{
min-height:273mm;
border:2px solid #222;
padding:8mm 9mm;
position:relative;
}
.cancelled-watermark{
position:absolute;
top:45%;
left:50%;
transform:translate(-50%,-50%) rotate(-35deg);
font-size:72px;
font-weight:700;
letter-spacing:8px;
color:rgba(190,0,0,.12);
z-index:10;
white-space:nowrap;
pointer-events:none;
}
.hospital-header{
display:flex;
align-items:center;
border-bottom:2px solid #222;
padding-bottom:8px;
margin-bottom:8px;
}
.logo-box{
width:90px;
min-width:90px;
height:75px;
display:flex;
align-items:center;
justify-content:center;
margin-right:12px;
}
.logo-box img{
max-width:88px;
max-height:72px;
object-fit:contain;
}
.hospital-info{
flex:1;
text-align:center;
padding-right:90px;
}
.hospital-name{
font-size:23px;
font-weight:700;
line-height:1.2;
text-transform:uppercase;
}
.branch-name{
font-size:15px;
font-weight:700;
margin-top:3px;
}
.hospital-address{
font-size:12px;
line-height:1.5;
margin-top:4px;
}
.contact-line{
font-size:11px;
line-height:1.5;
margin-top:2px;
}
.certificate-title{
text-align:center;
margin:15px 0 12px 0;
}
.certificate-title h1{
display:inline-block;
font-size:20px;
margin:0;
padding:0 4px 4px 4px;
border-bottom:2px solid #111;
letter-spacing:1px;
}
.certificate-meta{
width:100%;
border-collapse:collapse;
margin-bottom:15px;
font-size:13px;
}
.certificate-meta td{
padding:3px 0;
vertical-align:top;
}
.meta-left{
text-align:left;
width:50%;
}
.meta-right{
text-align:right;
width:50%;
}
.label{
font-weight:700;
}
.patient-table{
width:100%;
border-collapse:collapse;
margin-bottom:18px;
font-size:13px;
}
.patient-table td{
border:1px solid #aaa;
padding:7px 8px;
vertical-align:top;
}
.patient-table .field-label{
font-weight:700;
background:#f5f5f5;
width:18%;
}
.patient-table .field-value{
width:32%;
}
.certificate-body{
font-size:14px;
line-height:1.8;
text-align:justify;
margin-top:8px;
}
.certificate-body p{
margin:0 0 12px 0;
}
.value-line{
font-weight:700;
}
.death-details{
width:100%;
border-collapse:collapse;
margin-top:15px;
font-size:13px;
}
.death-details td{
border:1px solid #aaa;
padding:8px;
vertical-align:top;
}
.death-details .detail-label{
width:25%;
font-weight:700;
background:#f5f5f5;
}
.death-details .detail-value{
width:75%;
white-space:normal;
}
@media print{
@page{
size:A4;
margin:0;
}
html,body{
width:210mm;
min-height:297mm;
background:#fff;
}
.print-toolbar{
display:none !important;
}
.certificate-page{
margin:0;
box-shadow:none;
width:210mm;
min-height:297mm;
padding:12mm 14mm;
page-break-after:avoid;
}
.certificate-border{
min-height:273mm;
}
}
</style>
</head>
<body>

<div class="print-toolbar">
<button type="button" class="btn-back" onclick="window.close();">Close</button>
<button type="button" class="btn-print" onclick="window.print();">Print Certificate</button>
</div>

<div class="certificate-page">
<div class="certificate-border">

<?php if($is_cancelled){ ?>
<div class="cancelled-watermark">CANCELLED</div>
<?php } ?>

<div class="hospital-header">

<div class="logo-box">
<?php if($logo!=''){ ?>
<img src="<?= e($logo) ?>" alt="Hospital Logo">
<?php } ?>
</div>

<div class="hospital-info">

<div class="hospital-name">
<?= e($hospital_name) ?>
</div>

<?php if($branch_name!=''){ ?>
<div class="branch-name">
<?= e($branch_name) ?>
</div>
<?php } ?>

<div class="hospital-address">
<?php
$address_parts=[];
if($print_address!=''){
$address_parts[]=$print_address;
}
if($print_city!=''){
$address_parts[]=$print_city;
}
if($print_state!=''){
$address_parts[]=$print_state;
}
echo e(implode(', ',$address_parts));
?>
</div>

<?php if($print_mobile!='' || $print_email!='' || $print_website!=''){ ?>
<div class="contact-line">

<?php if($print_mobile!=''){ ?>
<strong>Phone:</strong> <?= e($print_mobile) ?>
<?php } ?>

<?php if($print_email!=''){ ?>
<?php if($print_mobile!=''){ ?>
&nbsp;&nbsp; | &nbsp;&nbsp;
<?php } ?>
<strong>Email:</strong> <?= e($print_email) ?>
<?php } ?>

<?php if($print_website!=''){ ?>
<?php if($print_mobile!='' || $print_email!=''){ ?>
&nbsp;&nbsp; | &nbsp;&nbsp;
<?php } ?>
<strong>Web:</strong> <?= e($print_website) ?>
<?php } ?>

</div>
<?php } ?>

</div>
</div>

<div class="certificate-title">
<h1>DEATH CERTIFICATE</h1>
</div>

<table class="certificate-meta">
<tr>
<td class="meta-left">
<span class="label">Certificate No.:</span>
<?= e($certificate_no) ?>
</td>
<td class="meta-right">
<span class="label">Date:</span>
<?= e($certificate_date) ?>
</td>
</tr>
</table>

<table class="patient-table">

<tr>
<td class="field-label">Patient Name</td>
<td class="field-value">
<?= $patient_name!='' ? e($patient_name) : '-' ?>
</td>

<td class="field-label">UHID</td>
<td class="field-value">
<?= $uhid!='' ? e($uhid) : '-' ?>
</td>
</tr>

<tr>
<td class="field-label">Guardian Name</td>
<td class="field-value">
<?= $guardian_name!='' ? e($guardian_name) : '-' ?>
</td>

<td class="field-label">Mobile</td>
<td class="field-value">
<?= $mobile!='' ? e($mobile) : '-' ?>
</td>
</tr>

<tr>
<td class="field-label">DOB</td>
<td class="field-value">
<?= e($dob) ?>
</td>

<td class="field-label">Age / Gender</td>
<td class="field-value">
<?= $age!='' ? e($age) : '-' ?>
<?php if($sex!=''){ ?>
&nbsp;/&nbsp; <?= e($sex) ?>
<?php } ?>
</td>
</tr>

<tr>
<td class="field-label">Marital Status</td>
<td class="field-value">
<?= $marital_status!='' ? e($marital_status) : '-' ?>
</td>

<td class="field-label">Patient Type</td>
<td class="field-value">
<?= e($certificate['patient_type']??'') ?>
</td>
</tr>

<?php if($address!=''){ ?>
<tr>
<td class="field-label">Address</td>
<td colspan="3">
<?= nl2br(e($address)) ?>
</td>
</tr>
<?php } ?>

</table>
<div class="certificate-body">
<p>
This is to certify that
<span class="value-line"><?= e($patient_name) ?></span>
<?php if($guardian_name!=''){ ?>
, <?= $sex==='Female' ? 'Daughter/Wife' : 'Son' ?> of
<span class="value-line"><?= e($guardian_name) ?></span>
<?php } ?>
<?php if($age!=''){ ?>
, aged approximately
<span class="value-line"><?= e($age) ?></span>
<?php } ?>
<?php if($address!=''){ ?>
, resident of
<span class="value-line"><?= e($address) ?></span>
<?php } ?>
, expired on
<span class="value-line"><?= e($date_of_death) ?></span>
<?php if($time_of_death!='-'){ ?>
at
<span class="value-line"><?= e($time_of_death) ?></span>
<?php } ?>
<?php if($place_of_death!=''){ ?>
at
<span class="value-line"><?= e($place_of_death) ?></span>
<?php } ?>.
</p>
</div>

<table class="death-details">
<tr>
<td class="detail-label">Date of Death</td>
<td class="detail-value">
<?= e($date_of_death) ?>
</td>
</tr>

<tr>
<td class="detail-label">Time of Death</td>
<td class="detail-value">
<?= e($time_of_death) ?>
</td>
</tr>

<tr>
<td class="detail-label">Place of Death</td>
<td class="detail-value">
<?= $place_of_death!='' ? e($place_of_death) : '-' ?>
</td>
</tr>

<tr>
<td class="detail-label">Immediate Cause of Death</td>
<td class="detail-value">
<?= $immediate_cause!='' ? nl2br(e($immediate_cause)) : '-' ?>
</td>
</tr>

<tr>
<td class="detail-label">Antecedent Cause</td>
<td class="detail-value">
<?= $antecedent_cause!='' ? nl2br(e($antecedent_cause)) : '-' ?>
</td>
</tr>

<tr>
<td class="detail-label">Underlying Cause</td>
<td class="detail-value">
<?= $underlying_cause!='' ? nl2br(e($underlying_cause)) : '-' ?>
</td>
</tr>

<tr>
<td class="detail-label">Other Significant Conditions</td>
<td class="detail-value">
<?= $other_significant_conditions!='' ? nl2br(e($other_significant_conditions)) : '-' ?>
</td>
</tr>

<tr>
<td class="detail-label">Manner of Death</td>
<td class="detail-value">
<?= $manner_of_death!='' ? e($manner_of_death) : '-' ?>
</td>
</tr>

<?php if($remarks!=''){ ?>
<tr>
<td class="detail-label">Remarks</td>
<td class="detail-value">
<?= nl2br(e($remarks)) ?>
</td>
</tr>
<?php } ?>
</table>
<style>
.signature-section{
margin-top:35px;
display:flex;
justify-content:space-between;
align-items:flex-end;
gap:30px;
}
.verification-box{
width:55%;
font-size:11px;
line-height:1.6;
color:#444;
}
.verification-title{
font-weight:700;
font-size:12px;
color:#111;
margin-bottom:4px;
}
.verification-token{
font-family:monospace;
font-size:9px;
word-break:break-all;
border:1px solid #ccc;
padding:5px 7px;
margin-top:4px;
background:#fafafa;
}
.doctor-signature{
width:40%;
text-align:center;
padding-top:40px;
}
.signature-line{
border-top:1px solid #111;
padding-top:5px;
margin-top:10px;
}
.doctor-name{
font-weight:700;
font-size:14px;
}
.doctor-registration{
font-size:11px;
margin-top:3px;
}
.status-box{
margin-top:15px;
font-size:11px;
}
.status-done{
font-weight:700;
}
.status-cancelled{
font-weight:700;
color:#b00000;
}
.certificate-footer{
position:absolute;
left:9mm;
right:9mm;
bottom:7mm;
border-top:1px solid #999;
padding-top:5px;
font-size:9px;
color:#555;
display:flex;
justify-content:space-between;
align-items:center;
}
.footer-left{
text-align:left;
}
.footer-right{
text-align:right;
}
@media print{
.signature-section{
page-break-inside:avoid;
}
.certificate-footer{
bottom:7mm;
}
}
</style>

<div class="signature-section">

<div class="verification-box">
<div class="verification-title">
Certificate Verification
</div>

<div>
<strong>Certificate No.:</strong>
<?= e($certificate_no) ?>
</div>

<div>
<strong>Issued Date:</strong>
<?= e($certificate_date) ?>
</div>

<?php if(!empty($certificate['qr_token'])){ ?>
<div>
<strong>Verification Token:</strong>
</div>

<div class="verification-token">
<?= e($certificate['qr_token']) ?>
</div>
<?php } ?>

<div class="status-box">
<strong>Status:</strong>

<?php if($is_cancelled){ ?>
<span class="status-cancelled">
CANCELLED
</span>
<?php }else{ ?>
<span class="status-done">
DONE
</span>
<?php } ?>

</div>
</div>

<div class="doctor-signature">

<div style="min-height:45px;"></div>

<div class="signature-line">

<div class="doctor-name">
<?= $doctor_name!='' ? e($doctor_name) : 'Authorized Doctor' ?>
</div>

<?php if($doctor_registration_no!=''){ ?>
<div class="doctor-registration">
Reg. No.: <?= e($doctor_registration_no) ?>
</div>
<?php } ?>

<div class="doctor-registration">
Authorized Medical Officer
</div>

</div>
</div>

</div>

<div class="certificate-footer">

<div class="footer-left">
Generated on:
<?= e(date('d-m-Y h:i A')) ?>
</div>

<div class="footer-right">
<?= e($hospital_name) ?>
<?php if($branch_name!=''){ ?>
- <?= e($branch_name) ?>
<?php } ?>
</div>

</div>

</div>
</div>

<script>
window.addEventListener("keydown",function(e){
if((e.ctrlKey || e.metaKey) && e.key.toLowerCase()==="p"){
e.preventDefault();
window.print();
}
});
</script>

</body>
</html>