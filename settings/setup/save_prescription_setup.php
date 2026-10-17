<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
if(
    !checkPermission('setup','add') &&
    !checkPermission('setup','edit')
){
    $_SESSION['error']="You don't have permission to perform this action.";
    header("Location: setup.php");
    exit;
}
if($_SERVER['REQUEST_METHOD']!="POST"){
    $_SESSION['error']="Invalid Request.";
    header("Location: setup.php");
    exit;
}
/* ===============================
   POST VALUES
================================ */
$hospital_id=(int)($_POST['hospital_id'] ?? 0);
$branch_id=(int)($_POST['branch_id'] ?? 0);
$doctor_id=(int)($_POST['doctor_id'] ?? 0);
$tabs=$_POST['tabs'] ?? [];
$noteSections=$_POST['note_sections'] ?? [];
if($hospital_id==0){
    $_SESSION['error']="Please select Hospital.";
    header("Location: setup.php");
    exit;
}
if($branch_id==0){
    $_SESSION['error']="Please select Branch.";
    header("Location: setup.php");
    exit;
}
if($doctor_id==0){
    $_SESSION['error']="Please select Doctor.";
    header("Location: setup.php");
    exit;
}
/* ===============================
   TAB VALUES
================================ */
$notes=in_array('notes',$tabs)?1:0;
$diagnosis=in_array('diagnosis',$tabs)?1:0;
$services=in_array('services',$tabs)?1:0;
$diet_details=in_array('diet_details',$tabs)?1:0;
$eye_details=in_array('eye_details',$tabs)?1:0;
$gynae_details=in_array('gynae_details',$tabs)?1:0;
$embryology=in_array('embryology',$tabs)?1:0;
$bariatric=in_array('bariatric',$tabs)?1:0;
$nursing_notes=in_array('nursing_notes',$tabs)?1:0;
$parameters=in_array('parameters',$tabs)?1:0;
$patient_vitals=in_array('patient_vitals',$noteSections)?1:0;
$chief_complaint=in_array('chief_complaint',$noteSections)?1:0;
$drug_history=in_array('drug_history',$noteSections)?1:0;
$past_history=in_array('past_history',$noteSections)?1:0;
$present_illness_history=in_array('present_illness_history',$noteSections)?1:0;
$diagnosis_section=in_array('diagnosis_section',$noteSections)?1:0;
$medicines=in_array('medicines',$noteSections)?1:0;
$procedure_advice=in_array('procedure_advice',$noteSections)?1:0;
$advice=in_array('advice',$noteSections)?1:0;
$prognosis=in_array('prognosis',$noteSections)?1:0;
$remarks=in_array('remarks',$noteSections)?1:0;
/* ===============================
   CHECK EXISTING RECORD
   One setup per doctor
================================ */

$check = mysqli_query($conn, "
    SELECT id
    FROM doctor_prescription_tabs
    WHERE doctor_id = '$doctor_id'
    LIMIT 1
");

if (!$check) {
    $_SESSION['error'] =
        "Unable to check existing prescription setup.";
    header("Location: setup.php");
    exit;
}

$isUpdate = mysqli_num_rows($check) > 0;
$recordId = 0;

if ($isUpdate) {
    $row = mysqli_fetch_assoc($check);
    $recordId = (int)$row['id'];
}

mysqli_free_result($check);
/* ===============================
   UPDATE
================================ */
if($isUpdate){
    $sql=mysqli_query($conn,"
    UPDATE doctor_prescription_tabs SET
    hospital_id='$hospital_id',
    branch_id='$branch_id',
    notes='$notes',
    patient_vitals='$patient_vitals',
    chief_complaint='$chief_complaint',
    drug_history='$drug_history',
    past_history='$past_history',
    present_illness_history='$present_illness_history',
    diagnosis_section='$diagnosis_section',
    medicines='$medicines',
    procedure_advice='$procedure_advice',
    advice='$advice',
    prognosis='$prognosis',
    remarks='$remarks',
    diagnosis='$diagnosis',
    services='$services',
    diet_details='$diet_details',
    eye_details='$eye_details',
    gynae_details='$gynae_details',
    embryology='$embryology',
bariatric='$bariatric',
nursing_notes='$nursing_notes',
parameters='$parameters',
status='Active'
    WHERE id='$recordId'
    ");
    if($sql){
        if(function_exists('addAuditLog')){
    addAuditLog(
        'Prescription Setup',
        'UPDATE',
        'Updated prescription tab setup for Doctor ID : '.$doctor_id,
        $recordId,
        '',
        ''
    );
}
        $_SESSION['success']="Prescription setup updated successfully.";
    }else{
        $_SESSION['error']="Failed to update prescription setup.";
    }
}else{
/* ===============================
   INSERT
================================ */
    $sql=mysqli_query($conn,"
    INSERT INTO doctor_prescription_tabs(
    hospital_id,
    branch_id,
    doctor_id,
    notes,
    patient_vitals,
    chief_complaint,
    drug_history,
    past_history,
    present_illness_history,
    diagnosis_section,
    medicines,
    procedure_advice,
    advice,
    prognosis,
    remarks,
    diagnosis,
    services,
    diet_details,
    eye_details,
    gynae_details,
embryology,
bariatric,
nursing_notes,
parameters,
status,
created_by
    )VALUES(
    '$hospital_id',
    '$branch_id',
    '$doctor_id',
    '$notes',
    '$patient_vitals',
    '$chief_complaint',
    '$drug_history',
    '$past_history',
    '$present_illness_history',
    '$diagnosis_section',
    '$medicines',
    '$procedure_advice',
    '$advice',
    '$prognosis',
    '$remarks',
    '$diagnosis',
    '$services',
    '$diet_details',
    '$eye_details',
    '$gynae_details',
    '$embryology',
'$bariatric',
'$nursing_notes',
'$parameters',
'Active',
    '".$_SESSION['user_id']."'
    )
    ");
    if($sql){
        if(function_exists('addAuditLog')){
    $insertId = mysqli_insert_id($conn);

    addAuditLog(
        'Prescription Setup',
        'CREATE',
        'Created prescription tab setup for Doctor ID : '.$doctor_id,
        $insertId,
        '',
        ''
    );
}
        $_SESSION['success']="Prescription setup saved successfully.";
    }else{
        $_SESSION['error']="Failed to save prescription setup.";
    }
}
header("Location: setup.php");
exit;
?>