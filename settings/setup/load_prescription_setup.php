<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!checkPermission('setup','view')) {
    exit('Access Denied');
}
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
$action = $_GET['action'] ?? '';
/* ===========================================================
   LOAD BRANCHES
=========================================================== */
if($action=="branches"){
    $hospital_id=(int)($_GET['hospital_id'] ?? 0);
    echo '<option value="">Select Branch</option>';
    $sql=mysqli_query($conn,"
        SELECT
            id,
            branch_name
        FROM branch_master
        WHERE hospital_id='$hospital_id'
        AND status='1'
        ORDER BY branch_name
    ");
    while($row=mysqli_fetch_assoc($sql)){
        echo '<option value="'.$row['id'].'">'.
             htmlspecialchars($row['branch_name']).
             '</option>';
    }
    exit;
}
/* ===========================================================
   LOAD DOCTORS
=========================================================== */
if($action=="doctor_list"){
    $hospital_id=(int)($_GET['hospital_id'] ?? 0);
    $branch_id=(int)($_GET['branch_id'] ?? 0);
    echo '<option value="">Select Doctor</option>';
    $sql = mysqli_query($conn,"
SELECT DISTINCT
    dm.id AS doctor_id,
    u.full_name
FROM doctor_master dm
INNER JOIN users u
    ON dm.user_id = u.id
INNER JOIN user_branches ub
    ON ub.user_id = u.id
INNER JOIN branch_master bm
    ON bm.id = ub.branch_id
WHERE
    bm.hospital_id = '$hospital_id'
AND
    ub.branch_id = '$branch_id'
AND
    u.status = 'Active'
AND
    bm.status = '1'
ORDER BY
    u.full_name
    ");
    while($row=mysqli_fetch_assoc($sql)){
    echo '<option value="'.$row['doctor_id'].'">'.
         htmlspecialchars($row['full_name']).
         '</option>';
}
    exit;
}
/* ===========================================================
   LOAD SAVED SETUP
=========================================================== */
if($action=="load"){
    header('Content-Type: application/json');
    $doctor_id=(int)($_GET['doctor_id'] ?? 0);
    $tabs=[];
    $noteSections=[];
    $sql=mysqli_query($conn,"
        SELECT *
FROM doctor_prescription_tabs
WHERE doctor_id='$doctor_id'
AND status='Active'
LIMIT 1
    ");
    if(mysqli_num_rows($sql)){
        $row=mysqli_fetch_assoc($sql);
        if($row['notes']) $tabs[]='notes';
        if($row['diagnosis']) $tabs[]='diagnosis';
        if($row['services']) $tabs[]='services';
        if($row['diet_details']) $tabs[]='diet_details';
        if($row['eye_details']) $tabs[]='eye_details';
        if($row['gynae_details']) $tabs[]='gynae_details';
        if($row['embryology']) $tabs[]='embryology';
        if($row['bariatric']) $tabs[]='bariatric';
        if($row['nursing_notes']) $tabs[]='nursing_notes';
        if($row['parameters']) $tabs[]='parameters';
        if($row['patient_vitals']) $noteSections[]='patient_vitals';
if($row['chief_complaint']) $noteSections[]='chief_complaint';
if($row['drug_history']) $noteSections[]='drug_history';
if($row['past_history']) $noteSections[]='past_history';
if($row['present_illness_history']) $noteSections[]='present_illness_history';
if($row['diagnosis_section']) $noteSections[]='diagnosis_section';
if($row['medicines']) $noteSections[]='medicines';
if($row['procedure_advice']) $noteSections[]='procedure_advice';
if($row['advice']) $noteSections[]='advice';
if($row['prognosis']) $noteSections[]='prognosis';
if($row['remarks']) $noteSections[]='remarks';

    }else{
        $tabs=[
    'parameters',
    'notes',
    'diagnosis',
    'services',
    'diet_details',
    'eye_details',
    'gynae_details',
    'embryology',
    'bariatric',
    'nursing_notes'
];
        $noteSections=[
    'patient_vitals',
    'chief_complaint',
    'drug_history',
    'past_history',
    'present_illness_history',
    'diagnosis_section',
    'medicines',
    'procedure_advice',
    'advice',
    'prognosis',
    'remarks'
];
    }
   echo json_encode([
    'status'=>'success',
    'doctor_id'=>$doctor_id,
    'tabs'=>$tabs,
    'noteSections'=>$noteSections,
    'count'=>count($tabs)
]);
    exit;
}
/* ===========================================================
   INVALID REQUEST
=========================================================== */
http_response_code(400);
header('Content-Type: application/json');
echo json_encode([
    'status'=>'error',
    'message'=>'Invalid Request'
]);
exit;
?>