<!--public_html/modules/pharmacy/ajax/get_prescriptions_for_sale.php  -->
<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])){
    echo '<option value="">Select Prescription</option>';
    exit;
}

if(!checkPermission('pharmacy_sales','view')){
    echo '<option value="">Select Prescription</option>';
    exit;
}

$patientType=$_GET['patient_type']??'';
$appointmentId=(int)($_GET['appointment_id']??0);

if(
    !in_array($patientType,['OPD','IPD'])
    ||
    $appointmentId<=0
){
    echo '<option value="">Select Prescription</option>';
    exit;
}

$dbPatientType=strtolower($patientType);

$sql="
SELECT
    id,
    appointment_id,
    diagnosis,
    created_at
FROM opd_prescriptions
WHERE appointment_id=?
AND patient_type=?
ORDER BY id DESC
";

$stmt=$conn->prepare($sql);

$stmt->bind_param(
    "is",
    $appointmentId,
    $dbPatientType
);

$stmt->execute();

$result=$stmt->get_result();

echo '<option value="">Select Prescription</option>';

while($row=$result->fetch_assoc()){
    $date=date(
    'd-M-y',
    strtotime($row['created_at'])
);

$label=$date.' #'.$row['id'];
    

    echo '<option value="'.(int)$row['id'].'">'
        .htmlspecialchars($label)
        .'</option>';
}
?>