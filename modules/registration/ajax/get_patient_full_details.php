<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

/*
 * SESSION AUTHENTICATION AND PERMISSION
 *
 * This endpoint renders a patient's full record -- Aadhaar, ABHA id, mobile,
 * phone, email, date of birth, full address -- together with the patient's
 * entire appointment and visit history including consultation fees. It had no
 * session check and no permission check, so any caller who guessed or
 * enumerated a patient id received that record.
 *
 * Now the same two checks the M1 request guard applies, in the same order,
 * using the patient_registration 'view' permission, matching
 * modules/appointments/ajax/check_patient_by_aadhaar.php.
 *
 * The method stays GET and the HTML output is unchanged, so the existing
 * caller in modules/registration/registration_list.php (viewPatient) keeps
 * working unchanged. That page already starts the session and loads the
 * permission model, and only renders this view to staff who are working
 * within the patient_registration module.
 */
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!checkPermission('patient_registration', 'view')) {
    http_response_code(403);
    exit('Access denied.');
}

$id = (int)$_GET['id'];
/* =========================
   PATIENT DETAILS
========================= */
$patient = mysqli_fetch_assoc(
    mysqli_query($conn,"
        SELECT 
            pm.*,
            c.name AS city_name,
            COUNT(a.id) AS total_visits,
            MAX(a.appointment_date) AS last_visited
        FROM patient_master pm
        LEFT JOIN cities c 
            ON c.id = pm.city_id
        LEFT JOIN appointments a
            ON a.patient_id = pm.id
        WHERE pm.id = $id
        GROUP BY pm.id
    ")
);
function e($v){
    return htmlspecialchars($v ?? '');
}
?>
<style>
.patient-card{
    margin-bottom:20px;
}
.patient-table{
    width:100%;
    border-collapse:collapse;
    font-size:14px;
}
.patient-table th{
    background:#1976d2;
    color:#fff;
    padding:8px;
}

.patient-table td{
    border:1px solid #e5e7eb;
    padding:8px;
}

.visit-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    font-size:13px;
}

.visit-table th{
    background:#0f766e;
    color:#fff;
    padding:8px;
}

.visit-table td{
    border:1px solid #e5e7eb;
    padding:8px;
}

.visit-table tr:nth-child(even){
    background:#f8fafc;
}
</style>
<div class="patient-card">
<h3>Patient Details</h3>
<table class="patient-table">
<tr>
<th>UHID</th>
<th>Name</th>
<th>Marital</th>
<th>Gender</th>
<th>Guardian</th>
<th>Aadhaar</th>
<th>ABHA ID</th>
<th>Mobile</th>
<th>Phone</th>
<th>Email</th>
<th>DOB</th>
<th>ABHA ID</th>
</tr>
<tr>
<td><?= e($patient['uhid']) ?></td>
<td><?= e($patient['title']." ".$patient['first_name']." ".$patient['last_name']) ?></td>
<td><?= e($patient['marital_status']) ?></td>
<td><?= e($patient['sex']) ?></td>
<td><?= e($patient['guardian_name']) ?></td>
<td><?= e($patient['aadhaar']) ?></td>
<td><?= e($patient['abha_id']) ?></td>
<td><?= e($patient['mobile']) ?></td>
<td><?= e($patient['phone']) ?></td>
<td><?= e($patient['email']) ?></td>
<td><?= e($patient['dob']) ?></td>
<td><?= e($patient['abha_id']) ?></td>
</tr>
</table>
<h3>Address and Extra Details</h3>
<table class="patient-table">
<tr>
<th>City</th>
<th>Pincode</th>
<th>Address</th>
<th>Registered On</th>
<th>Last Visited</th>
<th>Total Visits</th>
</tr>
<tr>
<td><?= e($patient['city_name']) ?></td>
<td><?= e($patient['pincode']) ?></td>
<td><?= e($patient['address']) ?></td>
<td><?= e($patient['created_at']) ?></td>
<td><?= $patient['last_visited'] ? date('d-m-Y',strtotime($patient['last_visited'])) : '-' ?></td>
<td><?= $patient['total_visits'] ?></td>
</tr>
</table>
</div>
<?php
/* =========================
   VISIT HISTORY
========================= */
$visits = mysqli_query($conn,"
    SELECT 
        a.*,
        u.full_name AS doctor_name,
        sm.service_name
    FROM appointments a
    LEFT JOIN users u 
        ON a.entity_id = u.id 
       AND a.type='doctor'
    LEFT JOIN service_master sm
        ON a.entity_id = sm.id
       AND a.type='service'
    WHERE a.patient_id = $id
    ORDER BY a.appointment_date DESC
");
?>
<h3>Visit History</h3>
<table class="visit-table">
<tr>
<th>Type</th>
<th>OPD No</th>
<th>Date</th>
<th>Doctor / Service</th>
<th>Fee</th>
<th>Status</th>
</tr>
<?php while($v = mysqli_fetch_assoc($visits)): ?>
<tr>
<td>
<?php
if($v['type']=='doctor'){
    echo "OPD";
}
elseif($v['type']=='service'){
    echo "Direct Service";
}
elseif($v['type']=='ipd'){
    echo "IPD";
}
else{
    echo strtoupper($v['type']);
}
?>
</td>
<td><?= e($v['appointment_seq']) ?></td>
<td><?= e($v['appointment_date']) ?></td>
<td>
<?php
if($v['type']=='doctor'){
    echo e($v['doctor_name']);
}
elseif($v['type']=='service'){
    echo e($v['service_name']);
}
elseif($v['type']=='ipd'){
    echo "IPD Admission";
}
else{
    echo "-";
}
?>
</td>
<td><?= number_format($v['consultation_fee'],2) ?></td>
<td><?= e($v['status']) ?></td>
</tr>
<?php endwhile; ?>
</table>