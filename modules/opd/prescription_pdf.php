<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

ini_set('display_errors',1);
error_reporting(E_ALL);

/* =========================
   VALIDATE INPUT
========================= */
$appointment_id = (int)($_GET['appointment_id'] ?? 0);

if ($appointment_id <= 0) {
    die("Invalid appointment");
}
$patient_type='opd';
/* =========================
   FETCH FULL DATA
========================= */
$stmt = $conn->prepare("
SELECT 
    a.*,
    u.full_name AS doctor_name,
    d.department_name,
    c.name AS city_name,
    p.uhid,
    dmed.signature_image AS doctor_signature,
    p.abha_id
FROM appointments a
LEFT JOIN users u ON u.id = a.entity_id
LEFT JOIN doctor_master dm ON dm.user_id = u.id
LEFT JOIN doctor_media dmed ON dmed.doctor_id = dm.id
LEFT JOIN department_master d ON d.id = a.department_id
LEFT JOIN cities c ON c.id = a.city_id
LEFT JOIN patient_master p ON p.id = a.patient_id
WHERE a.id = ?
LIMIT 1
");
$stmt->bind_param("i", $appointment_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$row = $res->fetch_assoc()) {
    die("Invalid appointment");
}
$stmt->close();
if(($row['type'] ?? '')==='ipd'){
$patient_type='ipd';
}
/* =========================
   FETCH PRESCRIPTION
========================= */
$prescription=[];
$stmt=$conn->prepare("
SELECT *
FROM opd_prescriptions
WHERE appointment_id=?
AND patient_type=?
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param("is",$appointment_id,$patient_type);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows){
    $prescription=$result->fetch_assoc();
}
$stmt->close();
$prescription_id=(int)($prescription['id'] ?? 0);
/* =========================
FETCH PARAMETER READINGS
========================= */
$parameterReadings=[];

$stmt=$conn->prepare("
SELECT
pm.parameter_name,
pm.unit,
pr.reading,
pr.reading_date,
pr.reading_time,
u.full_name AS taken_by_name
FROM parameter_readings pr
LEFT JOIN parameter_master pm ON pm.id=pr.parameter_id
LEFT JOIN users u ON u.id=pr.taken_by
WHERE pr.appointment_id=?
ORDER BY pm.parameter_name ASC,pr.reading_date ASC,pr.reading_time ASC
");

$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$result=$stmt->get_result();

while($parameter=$result->fetch_assoc()){
$parameterReadings[]=$parameter;
}

$stmt->close();
/* =========================
FETCH SELECTED DISEASES
========================= */
$selectedDiseases=[];
if($patient_type==='ipd' && empty($prescription['selected_diseases'])){
$stmt=$conn->prepare("
SELECT selected_diseases
FROM opd_prescriptions
WHERE appointment_id=?
AND patient_type='ipd'
AND selected_diseases IS NOT NULL
AND selected_diseases!=''
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$stmt->bind_result($previous_selected_diseases);
if($stmt->fetch()){
$prescription['selected_diseases']=$previous_selected_diseases;
}
$stmt->close();
}
$diseaseIds=json_decode($prescription['selected_diseases'] ?? '',true);
if(is_array($diseaseIds) && !empty($diseaseIds)){
$diseaseIds=array_map('intval',$diseaseIds);
$diseaseIds=array_filter($diseaseIds);
if(!empty($diseaseIds)){
$idList=implode(',',$diseaseIds);
$result=mysqli_query($conn,"
SELECT disease_name
FROM disease_master
WHERE id IN ($idList)
ORDER BY disease_name ASC
");
if($result){
while($disease=$result->fetch_assoc()){
$selectedDiseases[]=$disease['disease_name'];
}
}
}
}
/* =========================
FETCH PRESCRIBED MEDICINES
========================= */
$prescribedMedicines=[];
if($prescription_id>0){
$stmt=$conn->prepare("
SELECT
pm.*,
im.item_name
FROM prescribed_medicines pm
LEFT JOIN item_master im ON im.id=pm.item_id
WHERE pm.prescription_id=?
ORDER BY pm.id ASC
");
$stmt->bind_param("i",$prescription_id);
$stmt->execute();
$result=$stmt->get_result();

while($medicine=$result->fetch_assoc()){
$prescribedMedicines[]=$medicine;
}
$stmt->close();
}


/* =========================
FETCH PRESCRIBED SERVICES
========================= */
$prescribedServices=[];

if($prescription_id>0){
$stmt=$conn->prepare("
SELECT
ps.*,
sm.service_name
FROM prescribed_services ps
LEFT JOIN service_master sm ON sm.id=ps.service_id
WHERE ps.prescription_id=?
ORDER BY ps.id ASC
");
$stmt->bind_param("i",$prescription_id);
$stmt->execute();
$result=$stmt->get_result();

while($service=$result->fetch_assoc()){
$prescribedServices[]=$service;
}

$stmt->close();
}
/* =========================
FETCH IPD ADMISSION
========================= */
$ipdAdmission=[];

if($patient_type==='ipd'){
$stmt=$conn->prepare("
SELECT
ipd_no,
file_no,
admission_date,
admission_time,
admission_type,
status
FROM ipd_admissions
WHERE appointment_id=?
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$result=$stmt->get_result();

if($result->num_rows){
$ipdAdmission=$result->fetch_assoc();
}

$stmt->close();
}
/* =========================
   FETCH HOSPITAL DETAILS
========================= */
$hospital = [];
$res = mysqli_query($conn, "
    SELECT *
    FROM hospital_master
    LIMIT 1
");
if ($res && mysqli_num_rows($res) > 0) {
    $hospital = mysqli_fetch_assoc($res);
}
$opd_no='';

if($patient_type==='opd'){
/* =========================
   OPD NO (Doctor-wise)
========================= */
$doctor_id = (int)$row['entity_id'];
$date      = $row['appointment_date'];

$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM appointments
    WHERE entity_id = ?
      AND appointment_date = ?
      AND id <= ?
");
$stmt->bind_param("isi", $doctor_id, $date, $appointment_id);
$stmt->execute();
$stmt->bind_result($opd_count);
$stmt->fetch();
$stmt->close();

$day = date('d', strtotime($date));
$opd_no = $day . '-' . str_pad($opd_count, 2, '0', STR_PAD_LEFT);
}
/* =========================
   FORMAT DATA
========================= */
$patient_name = trim(
    ($row['title'] ?? '') . ' ' .
    ($row['first_name'] ?? '') . ' ' .
    ($row['last_name'] ?? '')
);

$age = ($row['age_y'] ?? 0).'Y '.$row['age_m'].'M '.$row['age_d'].'D';
$doctor_name = $row['doctor_name'] ?? 'Doctor';
/* =========================
PRESCRIPTION NUMBER / DATE / TIME
========================= */

$prescription_id=(int)($prescription['id'] ?? 0);

if($prescription_id>0){
    $prescription_no=($patient_type==='ipd' ? 'IPRX-' : 'OPRX-')
        .str_pad($prescription_id,6,'0',STR_PAD_LEFT);
}else{
    $prescription_no='';
}

$prescription_date='';
$prescription_time='';

if(!empty($prescription['created_at'])){
    $prescription_date=date(
        'd-m-Y',
        strtotime($prescription['created_at'])
    );

    $prescription_time=date(
        'h:i A',
        strtotime($prescription['created_at'])
    );
}
$prescription_no='';

if($prescription_id>0){
    $prefix=($patient_type==='ipd')?'IPRX':'OPRX';
    $prescription_no=$prefix.'-'.str_pad($prescription_id,6,'0',STR_PAD_LEFT);
}

$visit_no='';

if($patient_type==='ipd'){
    $visit_no=$ipdAdmission['ipd_no'] ?? '';
}else{
    $visit_no=$opd_no;
}

$prescription_date='';

if(!empty($prescription['created_at'])){
    $prescription_date=date('d-m-Y',strtotime($prescription['created_at']));
}

$prescription_time='';

if(!empty($prescription['created_at'])){
    $prescription_time=date('h:i A',strtotime($prescription['created_at']));
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Prescription</title>
<style>
body{
    font-family: 'Segoe UI', sans-serif;
    padding:20px;
    color:#000;
}
.header{
display:flex;
align-items:center;
justify-content:flex-start;
border-bottom:3px solid #1565c0;
padding:10px 0 15px;
margin-bottom:10px;
}
.logo{
width:90px;
min-width:90px;
margin-right:20px;
text-align:center;
}
.logo img{
max-width:80px;
max-height:80px;
display:block;
margin:auto;
}
.header-info{
flex:1;
}
.header-info h1{
margin:0;
padding:0;
font-size:26px;
font-weight:700;
color:#1565c0;
line-height:1.2;
}
.header-info p{
margin:4px 0;
padding:0;
font-size:13px;
line-height:1.4;
color:#333;
}
.section{
page-break-inside:avoid;
}
.section{
border:1px solid #d8d8d8;
border-radius:8px;
margin:12px 0;
overflow:hidden;
}
.section-title{
padding:9px 15px;
font-size:15px;
font-weight:700;
background:#fafafa;
border-bottom:1px solid #ddd;
}
.section-content{
padding:10px 15px;
line-height:1.7;
}
.row{
    display:flex;
    justify-content:space-between;
    margin-bottom:5px;
    font-size:14px;
}
.box{
    border:1px solid #000;
    padding:10px;
    border-radius:6px;
}
.medicine{
font-size:16px;
line-height:1.9;
font-weight:500;
}
.patient-table td{
vertical-align:top;
}
.eye-table{
width:100%;
border-collapse:collapse;
margin-top:5px;
}
.eye-table th,
.eye-table td{
border:1px solid #000;
padding:6px;
text-align:center;
}
.rx{
margin-top:12px;
padding:12px;
border:1px solid #cfcfcf;
border-radius:8px;
background:#fff;
}
.footer{
    margin-top:60px;
    display:flex;
    justify-content:space-between;
}
.signature{
width:220px;
margin-left:auto;
text-align:center;
}
.patient-table{
width:100%;
border-collapse:collapse;
margin:12px 0;
font-size:13px;
}

.patient-table td{
padding:6px;
border:1px solid #ccc;
}
.vitals-row{
display:flex;
justify-content:space-between;
align-items:center;
gap:20px;
flex-wrap:wrap;
font-size:14px;
}

.vitals-row span{
white-space:nowrap;
}
</style>
</head>
<body onload="window.print()">
<!-- HEADER -->
<div class="header">
    <div class="logo">
        <?php if(!empty($hospital['logo']) && file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$hospital['logo'])){ ?>
            <img src="/<?= htmlspecialchars($hospital['logo']) ?>" alt="Hospital Logo">
        <?php }else{ ?>
            <div style="color:red;">Logo not found</div>
        <?php } ?>
    </div>
    <div class="header-info">
        <h1><?= htmlspecialchars($hospital['hospital_name'] ?? 'Hospital Name') ?></h1>
        <p>
            <?= htmlspecialchars($hospital['address'] ?? '') ?>
            <?= !empty($hospital['city']) ? ', '.htmlspecialchars($hospital['city']) : '' ?>
        </p>
        <p>
            📞 <?= htmlspecialchars($hospital['phone'] ?? '') ?>
            <?php if(!empty($hospital['email'])){ ?>
                | ✉ <?= htmlspecialchars($hospital['email']) ?>
            <?php } ?>
        </p>
    </div>
</div>
<!-- PATIENT + APPOINTMENT -->
<table width="100%" class="patient-table">
<tr>
<td><b>Patient</b></td>
<td><?= $patient_name ?></td>
<td><b>UHID</b></td>
<td><?= $row['uhid'] ?></td>
</tr>
<tr>
<td><b>Age/Gender</b></td>
<td><?= $age ?> / <?= $row['sex'] ?></td>
<td><b>City</b></td>
<td><?= $row['city_name'] ?></td>
</tr>
<tr>
<td><b>Doctor</b></td>
<td><?= $doctor_name ?></td>
<td><b>Department</b></td>
<td><?= $row['department_name'] ?></td>
</tr>
<?php if($patient_type==='ipd'){ ?>
<tr>
<td><b>Admission Date</b></td>
<td>
<?= !empty($ipdAdmission['admission_date']) ? date('d-m-Y',strtotime($ipdAdmission['admission_date'])) : '' ?>
</td>
<td><b>Admission Time</b></td>
<td>
<?= !empty($ipdAdmission['admission_time']) ? date('h:i A',strtotime($ipdAdmission['admission_time'])) : '' ?>
</td>
</tr>
<?php }else{ ?>
<tr>
<td><b>Date</b></td>
<td><?= date('d-m-Y',strtotime($row['appointment_date'])) ?></td>
<td><b>Time</b></td>
<td><?= htmlspecialchars($row['appointment_time'] ?? '') ?></td>
</tr>
<?php } ?>
<?php if($patient_type==='ipd'){ ?>
<tr>
<td><b>IPD No</b></td>
<td><?= htmlspecialchars($ipdAdmission['ipd_no'] ?? '') ?></td>
<td><b>File No</b></td>
<td><?= htmlspecialchars($ipdAdmission['file_no'] ?? '') ?></td>
</tr>
<tr>
<td><b>Admission Type</b></td>
<td><?= htmlspecialchars($ipdAdmission['admission_type'] ?? '') ?></td>
<td><b>Status</b></td>
<td><?= htmlspecialchars($ipdAdmission['status'] ?? '') ?></td>
</tr>
<?php }else{ ?>
<tr>
<td><b>OPD No</b></td>
<td><?= htmlspecialchars($opd_no) ?></td>
<td><b>Token</b></td>
<td><?= htmlspecialchars($row['token_no'] ?? '') ?></td>
</tr>
<?php } ?>
<!-- ADD ONLY THESE -->

<tr>
<td><b>Prescription No.</b></td>
<td><?= htmlspecialchars($prescription_no) ?></td>
<td><b>Prescription Date</b></td>
<td><?= htmlspecialchars($prescription_date) ?></td>
</tr>

<tr>
<td><b>Prescription Time</b></td>
<td><?= htmlspecialchars($prescription_time) ?></td>
<td><b>Prescription Type</b></td>
<td><?= $patient_type==='ipd' ? 'IPD' : 'OPD' ?></td>
</tr>
</table>
<?php
if(
    !empty($prescription['weight']) ||
    !empty($prescription['height']) ||
    !empty($prescription['bmi']) ||
    !empty($prescription['pain_scale'])
){
?>
<div class="section-title">
Patient Vitals
</div>

<div class="section-content vitals-row">

<span><strong>Weight:</strong> <?= htmlspecialchars($prescription['weight']) ?> Kg</span>

<span><strong>Height:</strong> <?= htmlspecialchars($prescription['height']) ?> cm</span>

<span><strong>BMI:</strong> <?= htmlspecialchars($prescription['bmi']) ?></span>

<span><strong>Pain Scale:</strong> <?= htmlspecialchars($prescription['pain_scale']) ?>/10</span>

</div>

<?php
}
?>
<!-- RX -->
<div class="rx">
    <?php
if(!empty($parameterReadings)){
?>
<div class="section-title">
Parameters
</div>
<div class="section-content">
<table class="patient-table">
<tr>
<th>Parameter</th>
<th>Reading</th>
<th>Date</th>
<th>Time</th>
<th>By</th>
</tr>
<?php foreach($parameterReadings as $parameter){ ?>
<tr>
<td><?= htmlspecialchars($parameter['parameter_name'] ?? '') ?></td>
<td>
<?= htmlspecialchars($parameter['reading'] ?? '') ?>
<?= !empty($parameter['unit']) ? ' '.htmlspecialchars($parameter['unit']) : '' ?>
</td>
<td>
<?= !empty($parameter['reading_date']) ? date('d-m-Y',strtotime($parameter['reading_date'])) : '' ?>
</td>
<td>
<?= !empty($parameter['reading_time']) ? date('h:i A',strtotime($parameter['reading_time'])) : '' ?>
</td>
<td><?= htmlspecialchars($parameter['taken_by_name'] ?? '') ?></td>
</tr>
<?php } ?>
</table>
</div>
<?php
}
?>
<?php
if(!empty($prescription['chief_complaint'])){
?>
<div class="section-title">
Chief Complaint
</div>
<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['chief_complaint'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescription['drug_history'])){
?>
<div class="section-title">
Drug History
</div>
<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['drug_history'])) ?>
</div>

<?php
}
?>

<?php
if(!empty($prescription['past_history'])){
?>
<div class="section-title">
Past History
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['past_history'])) ?>
</div>

<?php
}
?>
<?php
if(!empty($prescription['present_illness'])){
?>
<div class="section-title">
Present Illness
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['present_illness'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescription['diagnosis'])){
?>
<div class="section-title">
Diagnosis
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['diagnosis'])) ?>
</div>
<?php
}
?>
<?php
if(!empty($selectedDiseases)){
?>
<div class="section-title">
Diseases
</div>

<div class="section-content">
<?= htmlspecialchars(implode(', ',$selectedDiseases)) ?>
</div>
<?php
}
?>
<?php
if(!empty($prescription['medicines'])){
?>
<div class="section-title">
Medicines
</div>

<div class="section-content medicine">
<?= nl2br(htmlspecialchars($prescription['medicines'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescribedMedicines)){
?>
<div class="section-title">
Prescribed Medicines
</div>
<div class="section-content">
<table class="patient-table">
<tr>
<th>Medicine</th>
<th>Frequency</th>
<th>Meal</th>
<th>Qty</th>
<th>Morning</th>
<th>Noon</th>
<th>Evening</th>
<th>Comments</th>
</tr>
<?php foreach($prescribedMedicines as $medicine){ ?>
<tr>
<td><?= htmlspecialchars($medicine['item_name'] ?? '') ?></td>
<td><?= htmlspecialchars($medicine['frequency'] ?? '') ?></td>
<td><?= htmlspecialchars($medicine['meal'] ?? '') ?></td>
<td><?= htmlspecialchars($medicine['qty'] ?? '') ?></td>
<td><?= htmlspecialchars($medicine['morning'] ?? '') ?></td>
<td><?= htmlspecialchars($medicine['noon'] ?? '') ?></td>
<td><?= htmlspecialchars($medicine['evening'] ?? '') ?></td>
<td><?= htmlspecialchars($medicine['comments'] ?? '') ?></td>
</tr>
<?php } ?>
</table>
</div>
<?php
}
?>

<?php
if(!empty($prescribedServices)){
?>
<div class="section-title">
Prescribed Services
</div>
<div class="section-content">
<table class="patient-table">
<tr>
<th>Service</th>
<th>Status</th>
</tr>
<?php foreach($prescribedServices as $service){ ?>
<tr>
<td><?= htmlspecialchars($service['service_name'] ?? '') ?></td>
<td><?= htmlspecialchars($service['billing_status'] ?? '') ?></td>
</tr>
<?php } ?>
</table>
</div>
<?php
}
?>

<?php
if(!empty($prescription['procedure_advice'])){
?>
<div class="section-title">
Procedure Advice
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['procedure_advice'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescription['advice'])){
?>
<div class="section-title">
Advice
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['advice'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescription['prognosis'])){
?>
<div class="section-title">
Prognosis
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['prognosis'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescription['remarks'])){
?>
<div class="section-title">
Remarks
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['remarks'])) ?>
</div>
<?php
}
?>
<?php
if(!empty($prescription['diet_details'])){
?>
<div class="section-title">
Diet Details
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['diet_details'])) ?>
</div>
<?php
}
?>
<?php

$eye=[];
$show_eye=false;

if(
    !empty($prescription['eye_details'])
){

    $eye=json_decode(
        $prescription['eye_details'],
        true
    );

    if(
        is_array($eye)
    ){

        foreach(
            $eye
            as $value
        ){

            if(
                is_scalar($value) &&
                trim((string)$value)!==''
            ){

                $show_eye=true;

                break;
            }
        }
    }
}

if($show_eye){

?>

<div class="section-title">
Eye Examination
</div>

<div class="section-content">

<table class="eye-table">

<tr>

<th rowspan="2">
Eye
</th>

<th colspan="4">
Distance Vision
</th>

<th colspan="4">
Near Vision
</th>

</tr>

<tr>

<th>SPH</th>
<th>CYL</th>
<th>AXIS</th>
<th>VA</th>

<th>SPH</th>
<th>CYL</th>
<th>AXIS</th>
<th>VA</th>

</tr>

<tr>

<td>
<b>RE</b>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_distance_sph'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_distance_cyl'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_distance_axis'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_distance_va'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_near_sph'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_near_cyl'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_near_axis'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['re_near_va'] ?? ''
) ?>
</td>

</tr>

<tr>

<td>
<b>LE</b>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_distance_sph'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_distance_cyl'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_distance_axis'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_distance_va'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_near_sph'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_near_cyl'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_near_axis'] ?? ''
) ?>
</td>

<td>
<?= htmlspecialchars(
    $eye['le_near_va'] ?? ''
) ?>
</td>

</tr>

</table>

</div>

<?php

}

?>

<?php
if(!empty($prescription['gynae_details'])){
?>
<div class="section-title">
Gynae Details
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['gynae_details'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescription['embryology_details'])){
?>
<div class="section-title">
Embryology Details
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['embryology_details'])) ?>
</div>
<?php
}
?>

<?php
if(!empty($prescription['bariatric_details'])){
?>
<div class="section-title">
Bariatric Details
</div>

<div class="section-content">
<?= nl2br(htmlspecialchars($prescription['bariatric_details'])) ?>
</div>
<?php
}
?>
<?php
if(!empty($prescription['nursing_notes'])){
$nursingNotes=json_decode($prescription['nursing_notes'],true);
if(is_array($nursingNotes) && !empty($nursingNotes)){
?>
<div class="section-title">
Nursing Notes
</div>
<div class="section-content">
<table class="patient-table">
<tr>
<th>Treatment / Medicine</th>
<th>Date</th>
<th>Time</th>
<th>Stop Date</th>
<th>Stop Time</th>
<th>Comments</th>
</tr>
<?php foreach($nursingNotes as $note){ ?>
<tr>
<td><?= htmlspecialchars($note['treatment'] ?? '') ?></td>
<td><?= htmlspecialchars($note['date'] ?? '') ?></td>
<td><?= htmlspecialchars($note['time'] ?? '') ?></td>
<td><?= htmlspecialchars($note['stop_date'] ?? '') ?></td>
<td><?= htmlspecialchars($note['stop_time'] ?? '') ?></td>
<td><?= htmlspecialchars($note['comments'] ?? '') ?></td>
</tr>
<?php } ?>
</table>
</div>
<?php
}
}
?>
<!-- FOOTER -->
<div class="footer">
<div class="signature">
<?php
if(!empty($row['doctor_signature']) && file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$row['doctor_signature'])){
?>
<img src="/<?= htmlspecialchars($row['doctor_signature']) ?>" style="max-width:180px;max-height:70px;"><br>
<?php
}else{
?>
<div style="height:65px;"></div>
<?php
}
?>
<div style="border-top:1px solid #000;margin-top:5px;padding-top:5px;font-weight:bold;">
<?= htmlspecialchars($doctor_name) ?>
</div>
<div style="font-size:12px;">
<?= htmlspecialchars($row['department_name']) ?>
</div>
<div style="font-size:11px;color:#666;">
Authorized Doctor Signature
</div>
</div>
</div>
</body>
</html>