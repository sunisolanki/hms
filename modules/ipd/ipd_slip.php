<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
ini_set('display_errors',1);
error_reporting(E_ALL);
if(!isset($_SESSION['user_id'])){
    die("Unauthorized");
}
$appointment_id=(int)($_GET['appointment_id'] ?? 0);
$stmt=$conn->prepare("
SELECT
a.id AS appointment_id,
a.appointment_code,
a.title,
a.first_name,
a.last_name,
a.sex,
a.mobile,
a.phone,
a.email,
a.address,
a.age_y,
a.age_m,
a.age_d,
a.aadhaar,
p.abha_id,
a.guardian_name,
a.created_at,
p.uhid,
ipd.id AS admission_id,
ipd.ipd_no,
ipd.file_no,
ipd.admission_date,
ipd.admission_time,
ipd.admission_type,
ipd.referral_type,
ipd.payment_type,
ipd.initial_deposit,
ipd.credit_limit,
a.is_emergency AS is_emergency,
ipd.is_vip,
ipd.is_mlc,
ipd.mlc_type,
ipd.police_station,
ipd.accident_place,
ipd.mlc_remarks,
ipd.fir_no,
ipd.status,
u.full_name AS doctor_name,
d.department_name,
h.id AS hospital_id,
h.hospital_name,
h.hospital_code,
h.address AS hospital_address,
h.city AS hospital_city,
h.state AS hospital_state,
h.pincode AS hospital_pincode,
h.mobile AS hospital_mobile,
h.phone AS hospital_phone,
h.email AS hospital_email,
h.website AS hospital_website,
h.logo AS hospital_logo,
b.branch_name,
b.address AS branch_address,
b.city AS branch_city,
b.state AS branch_state,
b.mobile AS branch_mobile,
b.email AS branch_email,
b.website AS branch_website,
b.logo AS branch_logo,
ira.admission_reason,
ira.primary_diagnosis,
ira.attendant_name,
ira.attendant_mobile,
ira.expected_stay,
ira.initial_deposit AS room_deposit,
ira.remarks,
bm.building_name,
fm.floor_name,
rm.room_name,
rm.room_number,
rm.room_type,
bed.bed_number,
bed.bed_type
FROM appointments a
INNER JOIN ipd_admissions ipd
ON ipd.appointment_id=a.id
LEFT JOIN patient_master p
ON p.id=a.patient_id
LEFT JOIN users u
ON u.id=ipd.doctor_id
LEFT JOIN department_master d
ON d.id=ipd.department_id
LEFT JOIN hospital_master h
ON h.id=ipd.hospital_id
LEFT JOIN branch_master b
ON b.id=ipd.branch_id
LEFT JOIN ipd_room_allocation ira
ON ira.admission_id=ipd.id
AND ira.status='Occupied'
LEFT JOIN building_master bm
ON bm.id=ira.building_id
LEFT JOIN floor_master fm
ON fm.id=ira.floor_id
LEFT JOIN room_master rm
ON rm.id=ira.room_id
LEFT JOIN bed_master bed
ON bed.id=ira.bed_id
WHERE a.id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$result=$stmt->get_result();
if(!$row=$result->fetch_assoc()){
    die("IP Admission not found.");
}
$stmt->close();
$logo='';
if(!empty($row['branch_logo'])){
    $logo=$row['branch_logo'];
}elseif(!empty($row['hospital_logo'])){
    $logo=$row['hospital_logo'];
}
?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">

<title>IP Slip</title>

<style>

body{
font-family:Arial,Helvetica,sans-serif;
margin:0;
padding:0;
background:#ffffff;
}

.page{
    width:210mm;
    min-height:297mm;
    margin:auto;
    padding:10mm;
    box-sizing:border-box;
}


.slip{
border:1px solid #000;
padding:10px;
}

.header{
display:flex;
align-items:center;
border-bottom:2px solid #000;
padding-bottom:8px;
margin-bottom:10px;
}

.logo{
width:80px;
text-align:center;
}

.logo img{
width:70px;
height:auto;
}

.header-info{
flex:1;
text-align:center;
}

.header-info h1{
margin:0;
font-size:24px;
}

.header-info h2{
margin:3px 0;
font-size:16px;
font-weight:600;
}

.header-info p{
margin:2px 0;
font-size:12px;
}

.title{
text-align:center;
font-size:18px;
font-weight:bold;
margin:10px 0;
padding:5px;
border:1px solid #000;
background:#f5f5f5;
}

table{
width:100%;
border-collapse:collapse;
font-size:13px;
}

td{
padding:5px;
vertical-align:top;
}

.label{
font-weight:bold;
width:180px;
}

.section{
margin-top:10px;
border:1px solid #000;
}
.section-title{
background:#efefef;
padding:6px;
font-weight:bold;
border-bottom:1px solid #000;
}
.emergency-banner{
    margin:8px 0;
    padding:8px;
    text-align:center;
    background:#dc2626;
    color:#fff;
    font-size:14px;
    font-weight:800;
    border:1px solid #991b1b;
}
@page{
    size:A4 portrait;
    margin:5mm;
}
@media print{
button{
display:none;
}
}
</style>
</head>
<body>
<div class="page">
<div class="slip">
<div class="header">
<div class="logo">
<?php
if($logo!='' && file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$logo)){
?>
<img src="/<?= htmlspecialchars($logo) ?>">
<?php
}
?>
</div>
<div class="header-info">
<h1><?= htmlspecialchars($row['hospital_name'] ?? '') ?></h1>
<h2><?= htmlspecialchars($row['branch_name'] ?? '') ?></h2>
<p>
<?= htmlspecialchars($row['branch_address'] ?? '') ?>,
<?= htmlspecialchars($row['branch_city'] ?? '') ?>,
<?= htmlspecialchars($row['branch_state'] ?? '') ?>
</p>
<p>
☎ <?= htmlspecialchars($row['branch_mobile']) ?>
<?php if(!empty($row['branch_phone'])){ ?>
| <?= htmlspecialchars($row['branch_phone'] ?? '') ?>
<?php } ?>
</p>
<p>
<?= htmlspecialchars($row['branch_email'] ?? '') ?>
</p>
</div>
</div>
<div class="title">
INDOOR PATIENT ADMISSION SLIP
</div>
<?php if((int)$row['is_emergency'] === 1){ ?>
<div class="emergency-banner">
    🚨 EMERGENCY PATIENT
</div>
<?php } ?>
<!-- ============================= -->
<!-- ADMISSION DETAILS -->
<!-- ============================= -->
<div class="section">
<div class="section-title">
Admission Details
</div>
<table>
<tr>
<td class="label">IPD No.</td>
<td><?= htmlspecialchars($row['ipd_no'] ?? '') ?></td>
<td class="label">File No.</td>
<td><?= htmlspecialchars($row['file_no'] ?? '') ?></td>
</tr>
<tr>
<td class="label">UHID</td>
<td><?= htmlspecialchars($row['uhid'] ?? '') ?></td>
<td class="label">Appointment Code</td>
<td><?= htmlspecialchars($row['appointment_code'] ?? '') ?></td>
</tr>
<tr>
<td class="label">Admission Date</td>
<td>
<?= date('d-m-Y',strtotime($row['admission_date'])) ?>
</td>
<td class="label">Admission Time</td>
<td>
<?= date('h:i A',strtotime($row['admission_time'])) ?>
</td>
</tr>
<tr>
<td class="label">Admission Type</td>
<td>
<?= htmlspecialchars($row['admission_type']) ?>
</td>
<td class="label">Status</td>
<td>
<?= htmlspecialchars($row['status']) ?>
</td>
</tr>
</table>
</div>
<!-- ============================= -->
<!-- PATIENT DETAILS -->
<!-- ============================= -->
<div class="section">
<div class="section-title">
Patient Details
</div>
<table>
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
</tr>
<tr>
<td class="label">Guardian</td>
<td>
<?= htmlspecialchars($row['guardian_name']) ?>
</td>
<td class="label">Gender</td>
<td>
<?= htmlspecialchars($row['sex']) ?>
</td>
</tr>
<tr>
<td class="label">Age</td>
<td>
<?= (int)$row['age_y'] ?> Years
<?= (int)$row['age_m'] ?> Months
</td>
<td class="label">Mobile</td>
<td>
<?= htmlspecialchars($row['mobile']) ?>
</td>
</tr>
<tr>
<td class="label">Phone</td>
<td>
<?= htmlspecialchars($row['phone']) ?>
</td>
<td class="label">Email</td>
<td>
<?= htmlspecialchars($row['email']) ?>
</td>
</tr>
<tr>
<td class="label">AADHAAR</td>
<td>
<?= htmlspecialchars($row['aadhaar']) ?>
</td>
<td class="label">ABHA ID</td>
<td>
<?= htmlspecialchars($row['abha_id']) ?>
</td>
</tr>
<tr>
<td class="label">Address</td>
<td colspan="3">
<?= nl2br(htmlspecialchars($row['address'])) ?>
</td>
</tr>
</table>
</div>
<!-- ============================= -->
<!-- DOCTOR & ROOM DETAILS -->
<!-- ============================= -->
<div class="section">
<div class="section-title">
Doctor & Admission Location
</div>
<table>
<tr>
<td class="label">Doctor</td>
<td><?= htmlspecialchars($row['doctor_name']) ?></td>
<td class="label">Department</td>
<td><?= htmlspecialchars($row['department_name']) ?></td>
</tr>
<tr>
<td class="label">Building</td>
<td><?= htmlspecialchars($row['building_name']) ?></td>
<td class="label">Floor</td>
<td><?= htmlspecialchars($row['floor_name']) ?></td>
</tr>
<tr>
<td class="label">Room</td>
<td>
<?= htmlspecialchars($row['room_name']) ?>
<?php
if(!empty($row['room_number'])){
echo ' ('.$row['room_number'].')';
}
?>
</td>
<td class="label">Bed</td>
<td><?= htmlspecialchars($row['bed_number']) ?></td>
</tr>
<tr>
<td class="label">Room Type</td>
<td><?= htmlspecialchars($row['room_type']) ?></td>
<td class="label">Bed Type</td>
<td><?= htmlspecialchars($row['bed_type']) ?></td>
</tr>
</table>
</div>
<!-- ============================= -->
<!-- ADMISSION INFORMATION -->
<!-- ============================= -->
<div class="section">
<div class="section-title">
Admission Information
</div>
<table>
<tr>
<td class="label">
Admission Reason
</td>
<td colspan="3">
<?= nl2br(htmlspecialchars($row['admission_reason'])) ?>
</td>
</tr>
<tr>
<td class="label">
Primary Diagnosis
</td>
<td colspan="3">
<?= nl2br(htmlspecialchars($row['primary_diagnosis'])) ?>
</td>
</tr>
<tr>
<td class="label">
Expected Stay
</td>
<td>
<?= (int)$row['expected_stay'] ?> Days
</td>
<td class="label">
Payment Type
</td>
<td>
<?= htmlspecialchars($row['payment_type']) ?>
</td>
</tr>
<tr>
<td class="label">
Initial Deposit
</td>
<td>
₹ <?= number_format((float)$row['initial_deposit'],2) ?>
</td>
<td class="label">
Credit Limit
</td>
<td>
₹ <?= number_format((float)$row['credit_limit'],2) ?>
</td>
</tr>
</table>
</div>
<!-- ============================= -->
<!-- ATTENDANT DETAILS -->
<!-- ============================= -->
<div class="section">
<div class="section-title">
Attendant Details
</div>
<table>
<tr>
<td class="label">Attendant Name</td>
<td><?= htmlspecialchars($row['attendant_name']) ?></td>
<td class="label">Mobile</td>
<td><?= htmlspecialchars($row['attendant_mobile']) ?></td>
</tr>
</table>
</div>
<!-- ============================= -->
<!-- REFERRAL DETAILS -->
<!-- ============================= -->
<div class="section">
<div class="section-title">
Referral Details
</div>
<table>
<tr>
<td class="label">Referral Type</td>
<td><?= htmlspecialchars($row['referral_type']) ?></td>
<td class="label">Emergency</td>
<td><?= $row['is_emergency'] ? 'YES' : 'NO' ?></td>
</tr>
<tr>
<td class="label">VIP Patient</td>
<td><?= $row['is_vip'] ? 'YES' : 'NO' ?></td>
<td class="label">MLC</td>
<td><?= $row['is_mlc'] ? 'YES' : 'NO' ?></td>
</tr>
<?php if($row['is_mlc']){ ?>
<tr>
<td class="label">MLC Type</td>
<td><?= htmlspecialchars($row['mlc_type']) ?></td>
<td class="label">FIR No.</td>
<td><?= htmlspecialchars($row['fir_no']) ?></td>
</tr>
<tr>
<td class="label">Police Station</td>
<td><?= htmlspecialchars($row['police_station']) ?></td>
<td class="label">Accident Place</td>
<td><?= htmlspecialchars($row['accident_place']) ?></td>
</tr>
<tr>
<td class="label">MLC Remarks</td>
<td colspan="3">
<?= nl2br(htmlspecialchars($row['mlc_remarks'])) ?>
</td>
</tr>
<?php } ?>
</table>
</div>
<!-- ============================= -->
<!-- REMARKS -->
<!-- ============================= -->
<?php if(!empty($row['remarks'])){ ?>
<div class="section">
<div class="section-title">
Admission Remarks
</div>
<div style="padding:8px;font-size:13px;">
<?= nl2br(htmlspecialchars($row['remarks'])) ?>
</div>
</div>
<?php } ?>
<!-- ============================= -->
<!-- SIGNATURES -->
<!-- ============================= -->
<table style="margin-top:40px;">
<tr>
<td style="text-align:center;">
_________________________
<br>
Patient / Attendant Signature
</td>
<td style="text-align:center;">
____________________
<br>
Admission Staff
</td>
</tr>
</table>
<div style="margin-top:20px;text-align:center;font-size:13px;">
<b>Thank You</b><br>
We wish you a speedy recovery.
</div>
</div>
</div>
<div style="text-align:center;margin:15px;">
<button onclick="window.print()">
🖨 Print
</button>
</div>
<script>
window.onload=function(){
window.print();
};
</script>
</body>
</html>