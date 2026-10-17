<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id'])){
exit("Login expired.");
}

if(!checkPermission('ipd_list','view')){
exit("Access Denied");
}

$appointment_id=(int)($_GET['appointment_id']??0);

if($appointment_id<=0){
exit("Invalid Appointment.");
}

/*=========================================
PATIENT DETAILS
=========================================*/

$stmt=$conn->prepare("
SELECT
a.id,
a.patient_id,
a.ipd_no,
a.appointment_date,
a.status,

pm.uhid,
pm.first_name,
pm.last_name,
pm.sex,
pm.mobile,

d.department_name,

doc.full_name doctor_name,

ia.id admission_id,

b.building_name,
f.floor_name,
r.room_number,
bd.bed_number

FROM appointments a

INNER JOIN ipd_admissions ia
ON ia.appointment_id=a.id

LEFT JOIN patient_master pm
ON pm.id=a.patient_id

LEFT JOIN department_master d
ON d.id=a.department_id

LEFT JOIN users doc
ON doc.id=a.entity_id

LEFT JOIN ipd_room_allocation ra
ON ra.admission_id=ia.id
AND ra.status='Occupied'

LEFT JOIN building_master b
ON b.id=ra.building_id

LEFT JOIN floor_master f
ON f.id=ra.floor_id

LEFT JOIN room_master r
ON r.id=ra.room_id

LEFT JOIN bed_master bd
ON bd.id=ra.bed_id

WHERE a.id=?

LIMIT 1
");

$stmt->bind_param("i",$appointment_id);
$stmt->execute();

$patient=$stmt->get_result()->fetch_assoc();

$stmt->close();

if(!$patient){
exit("<div style='padding:20px;'>Patient not found.</div>");
}

$admission_id=(int)$patient['admission_id'];

/*=========================================
SHIFT HISTORY
=========================================*/

$stmt=$conn->prepare("
SELECT

h.*,

ob.building_name old_building,
ofl.floor_name old_floor,
oroom.room_number old_room,
obed.bed_number old_bed,

nb.building_name new_building,
nfl.floor_name new_floor,
nroom.room_number new_room,
nbed.bed_number new_bed,

u.full_name shifted_by

FROM ipd_room_shift_history h

LEFT JOIN building_master ob
ON ob.id=h.old_building_id

LEFT JOIN floor_master ofl
ON ofl.id=h.old_floor_id

LEFT JOIN room_master oroom
ON oroom.id=h.old_room_id

LEFT JOIN bed_master obed
ON obed.id=h.old_bed_id

LEFT JOIN building_master nb
ON nb.id=h.new_building_id

LEFT JOIN floor_master nfl
ON nfl.id=h.new_floor_id

LEFT JOIN room_master nroom
ON nroom.id=h.new_room_id

LEFT JOIN bed_master nbed
ON nbed.id=h.new_bed_id

LEFT JOIN users u
ON u.id=h.shifted_by

WHERE h.admission_id=?

ORDER BY h.shift_date DESC
");

$stmt->bind_param("i",$admission_id);
$stmt->execute();

$history=$stmt->get_result();

function e($v){
return htmlspecialchars((string)$v);
}
?>
<style>

.patient-card{
background:#fff;
border:1px solid #ddd;
border-radius:8px;
padding:15px;
margin-bottom:15px;
box-shadow:0 2px 8px rgba(0,0,0,.08);
}

.patient-card h4{
margin:0 0 12px;
color:#1976d2;
}

.info-grid{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:10px;
}

.info-box{
background:#f8f9fa;
padding:10px;
border-radius:6px;
border:1px solid #e5e5e5;
}

.info-box label{
display:block;
font-size:12px;
color:#666;
margin-bottom:3px;
}

.info-box strong{
font-size:14px;
}

.timeline{
position:relative;
margin-top:20px;
padding-left:30px;
}

.timeline:before{
content:"";
position:absolute;
left:12px;
top:0;
bottom:0;
width:3px;
background:#1976d2;
}

.timeline-item{
position:relative;
margin-bottom:25px;
}

.timeline-item:before{
content:"";
position:absolute;
left:-23px;
top:10px;
width:14px;
height:14px;
background:#1976d2;
border-radius:50%;
}

.timeline-card{
background:#fff;
border:1px solid #ddd;
border-radius:8px;
padding:15px;
box-shadow:0 2px 6px rgba(0,0,0,.08);
}

.timeline-header{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:12px;
padding-bottom:8px;
border-bottom:1px solid #eee;
}

.timeline-header strong{
font-size:15px;
color:#1976d2;
}

.room-grid{
display:grid;
grid-template-columns:1fr 70px 1fr;
gap:10px;
align-items:center;
margin-top:10px;
}

.room-box{
background:#f8f9fa;
border:1px solid #ddd;
border-radius:6px;
padding:12px;
}

.room-box h5{
margin:0 0 10px;
color:#1976d2;
font-size:14px;
}

.arrow-box{
text-align:center;
font-size:30px;
font-weight:bold;
color:#16a34a;
}

.reason-box{
margin-top:15px;
padding:10px;
background:#fff8dc;
border-left:4px solid #f59e0b;
border-radius:5px;
}

.empty-history{
text-align:center;
padding:50px;
background:#fff;
border:1px dashed #bbb;
border-radius:8px;
font-size:16px;
color:#666;
}

.print-btn{
float:right;
margin-bottom:10px;
}

</style>

<div id="printArea">

<button
type="button"
class="btn btn-primary print-btn"
onclick="window.print();">
🖨 Print
</button>

<div style="clear:both;"></div>

<div class="patient-card">

<h4>🏥 Patient Shift History</h4>

<div class="info-grid">

<div class="info-box">
<label>Patient Name</label>
<strong><?=e($patient['first_name'].' '.$patient['last_name'])?></strong>
</div>

<div class="info-box">
<label>UHID</label>
<strong><?=e($patient['uhid'])?></strong>
</div>

<div class="info-box">
<label>IPD No.</label>
<strong><?=e($patient['ipd_no'])?></strong>
</div>

<div class="info-box">
<label>Doctor</label>
<strong><?=e($patient['doctor_name'])?></strong>
</div>

<div class="info-box">
<label>Department</label>
<strong><?=e($patient['department_name'])?></strong>
</div>

<div class="info-box">
<label>Current Room</label>
<strong>
<?=e($patient['building_name'])?>
/
<?=e($patient['floor_name'])?>
/
<?=e($patient['room_number'])?>
/
<?=e($patient['bed_number'])?>
</strong>
</div>

</div>

</div>

<?php
if($history->num_rows==0){
?>

<div class="empty-history">
No Room Shift History Found.
</div>

<?php
}else{
?>

<div class="timeline">

<?php
while($row=$history->fetch_assoc()){
?>

<div class="timeline-item">

<div class="timeline-card">

<div class="timeline-header">

<strong>
<?=date("d-M-Y h:i A",strtotime($row['shift_date']))?>
</strong>

<span>
Shifted By :
<b><?=e($row['shifted_by'])?></b>
</span>

</div>

<div class="room-grid">

<div class="room-box">

<h5>FROM</h5>
Building :
<b><?=e($row['old_building'])?></b> > 
Floor :
<b><?=e($row['old_floor'])?></b>
➡
Room :
<b><?=e($row['old_room'])?></b>
➡
Bed :
<b><?=e($row['old_bed'])?></b>
</div>
<div class="arrow-box">
➡
</div>
<div class="room-box">
<h5>TO</h5>
Building :
<b><?=e($row['new_building'])?></b>
➡
Floor :
<b><?=e($row['new_floor'])?></b>
➡

Room :
<b><?=e($row['new_room'])?></b>
➡

Bed :
<b><?=e($row['new_bed'])?></b>

</div>

</div>

<div class="reason-box">

<b>Reason :</b>

<?=nl2br(e($row['shift_reason']))?>

</div>

</div>

</div>

<?php
}
?>

</div>

<?php
}
?>

</div>
<?php
if(!empty($patient['room_number'])){
echo e($patient['building_name']).' / ';
echo e($patient['floor_name']).' / ';
echo e($patient['room_number']).' / ';
echo e($patient['bed_number']);
}else{
echo "<span style='color:red;'>No Room Allocated</span>";
}
?>