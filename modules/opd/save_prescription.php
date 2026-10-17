<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/prescription_setup_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/abdm_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/abdm/m2_care_context.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/abdm/m2_add_contexts.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}
$user_id=(int)$_SESSION['user_id'];
/* ===============================
GET FORM DATA
================================ */
$appointment_id=(int)($_POST['appointment_id'] ?? 0);
$prescription_id=(int)($_POST['prescription_id'] ?? 0);
$patient_type=$_POST['patient_type'] ?? 'opd';
$patient_type=($patient_type==='ipd') ? 'ipd' : 'opd';
$mode=$_POST['mode'] ?? (($patient_type==='ipd') ? 'new' : 'edit');
$showTabs=getDoctorPrescriptionTabs($conn,$appointment_id);
/* ===============================
NOTES TAB
================================ */
if(!empty($showTabs['notes'])){
    $chief_complaint=trim($_POST['chief_complaint'] ?? '');
    $drug_history=trim($_POST['drug_history'] ?? '');
    $past_history=trim($_POST['past_history'] ?? '');
    $present_illness=trim($_POST['present_illness'] ?? '');
    $procedure_advice=trim($_POST['procedure_advice'] ?? '');
    $advice=trim($_POST['advice'] ?? '');
    $prognosis=trim($_POST['prognosis'] ?? '');
    $remarks=trim($_POST['remarks'] ?? '');
}else{
    $chief_complaint='';
    $drug_history='';
    $past_history='';
    $present_illness='';
    $procedure_advice='';
    $advice='';
    $prognosis='';
    $remarks='';
}
/* ===============================
DIAGNOSIS TAB
================================ */
if(!empty($showTabs['diagnosis_section'])){
    $diagnosis=trim($_POST['diagnosis'] ?? '');
    $selected_diseases=trim($_POST['selected_diseases'] ?? '');
    $medicines=trim($_POST['medicines'] ?? '');
}else{
    $diagnosis='';
    $selected_diseases='';
    $medicines='';
}
/* ===============================
SERVICES TAB
================================ */
if(!empty($showTabs['services'])){
    $selected_services=$_POST['selected_services'] ?? '';
    $selected_items=$_POST['selected_items'] ?? '';
}else{
    $selected_services='';
    $selected_items='';
}
/* ===============================
DIET TAB
================================ */
$diet_details=!empty($showTabs['diet_details'])
?trim($_POST['diet_details'] ?? '')
:'';
/* ===============================
EYE TAB
================================ */
if(!empty($showTabs['eye_details'])){
    $eye_details=json_encode([
        're_distance_sph'=>$_POST['re_distance_sph'] ?? '',
        're_distance_cyl'=>$_POST['re_distance_cyl'] ?? '',
        're_distance_axis'=>$_POST['re_distance_axis'] ?? '',
        're_distance_va'=>$_POST['re_distance_va'] ?? '',
        're_near_sph'=>$_POST['re_near_sph'] ?? '',
        're_near_cyl'=>$_POST['re_near_cyl'] ?? '',
        're_near_axis'=>$_POST['re_near_axis'] ?? '',
        're_near_va'=>$_POST['re_near_va'] ?? '',
        'le_distance_sph'=>$_POST['le_distance_sph'] ?? '',
        'le_distance_cyl'=>$_POST['le_distance_cyl'] ?? '',
        'le_distance_axis'=>$_POST['le_distance_axis'] ?? '',
        'le_distance_va'=>$_POST['le_distance_va'] ?? '',
        'le_near_sph'=>$_POST['le_near_sph'] ?? '',
        'le_near_cyl'=>$_POST['le_near_cyl'] ?? '',
        'le_near_axis'=>$_POST['le_near_axis'] ?? '',
        'le_near_va'=>$_POST['le_near_va'] ?? ''
    ]);
}else{
    $eye_details='';
}
/* ===============================
GYNAE TAB
================================ */
$gynae_details=!empty($showTabs['gynae_details'])
?trim($_POST['gynae_details'] ?? '')
:'';
/* ===============================
EMBRYOLOGY TAB
================================ */
$embryology_details=!empty($showTabs['embryology'])
?trim($_POST['embryology_details'] ?? '')
:'';
/* ===============================
BARIATRIC TAB
================================ */
$bariatric_details=!empty($showTabs['bariatric'])
?trim($_POST['bariatric_details'] ?? '')
:'';
/* ===============================
NURSING NOTES TAB
================================ */
$nursing_notes=!empty($showTabs['nursing_notes'])
?trim($_POST['nursing_notes_json'] ?? '')
:'';
/* ===============================
VITALS
================================ */
$weight=(float)($_POST['weight'] ?? 0);
$height=(float)($_POST['height'] ?? 0);
$bmi=(float)($_POST['bmi'] ?? 0);
$pain_scale=(int)($_POST['pain_scale'] ?? 0);
/* ===============================
SAVE PRESCRIPTION
================================ */
$existing_prescription_id=0;

if($mode==='edit' && $prescription_id>0){

    $stmt=$conn->prepare("
    SELECT id
    FROM opd_prescriptions
    WHERE id=?
    AND appointment_id=?
    AND patient_type=?
    LIMIT 1
    ");

    if(!$stmt){
        die($conn->error);
    }

    $stmt->bind_param(
        "iis",
        $prescription_id,
        $appointment_id,
        $patient_type
    );

    $stmt->execute();

    $stmt->bind_result(
        $existing_prescription_id
    );

    $stmt->fetch();

    $stmt->close();
}

if(
    $mode==='edit' &&
    $existing_prescription_id>0
){

    $stmt=$conn->prepare("
    UPDATE opd_prescriptions
    SET
    chief_complaint=?,
    drug_history=?,
    past_history=?,
    present_illness=?,
    diagnosis=?,
    selected_diseases=?,
    medicines=?,
    procedure_advice=?,
    advice=?,
    prognosis=?,
    remarks=?,
    selected_services=?,
    selected_items=?,
    diet_details=?,
    eye_details=?,
    gynae_details=?,
    embryology_details=?,
    bariatric_details=?,
    nursing_notes=?,
    weight=?,
    height=?,
    bmi=?,
    pain_scale=?,
    created_by=?
    WHERE id=?
    AND appointment_id=?
    AND patient_type=?
    ");

    $stmt->bind_param(
        "sssssssssssssssssssdddiiiis",
        $chief_complaint,
        $drug_history,
        $past_history,
        $present_illness,
        $diagnosis,
        $selected_diseases,
        $medicines,
        $procedure_advice,
        $advice,
        $prognosis,
        $remarks,
        $selected_services,
        $selected_items,
        $diet_details,
        $eye_details,
        $gynae_details,
        $embryology_details,
        $bariatric_details,
        $nursing_notes,
        $weight,
        $height,
        $bmi,
        $pain_scale,
        $user_id,
        $existing_prescription_id,
        $appointment_id,
        $patient_type
    );

    if(!$stmt->execute()){
        die($stmt->error);
    }

    $stmt->close();

    $prescription_id=$existing_prescription_id;

}else{

    $stmt=$conn->prepare("
    INSERT INTO opd_prescriptions
    (
    appointment_id,
    patient_type,
    chief_complaint,
    drug_history,
    past_history,
    present_illness,
    diagnosis,
    selected_diseases,
    medicines,
    procedure_advice,
    advice,
    prognosis,
    remarks,
    selected_services,
    selected_items,
    diet_details,
    eye_details,
    gynae_details,
    embryology_details,
    bariatric_details,
    nursing_notes,
    weight,
    height,
    bmi,
    pain_scale,
    created_by
    )
    VALUES
    (
    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
    )
    ");

    $stmt->bind_param(
        "issssssssssssssssssssdddii",
        $appointment_id,
        $patient_type,
        $chief_complaint,
        $drug_history,
        $past_history,
        $present_illness,
        $diagnosis,
        $selected_diseases,
        $medicines,
        $procedure_advice,
        $advice,
        $prognosis,
        $remarks,
        $selected_services,
        $selected_items,
        $diet_details,
        $eye_details,
        $gynae_details,
        $embryology_details,
        $bariatric_details,
        $nursing_notes,
        $weight,
        $height,
        $bmi,
        $pain_scale,
        $user_id
    );

    if(!$stmt->execute()){
        die($stmt->error);
    }

    $prescription_id=$conn->insert_id;

    $stmt->close();
}
/* ===============================
FUNCTION TO SAVE TEMPLATE
================================ */
function saveTemplate($conn,$name,$text,$type,$user){
    $name=trim($name);
    $text=trim($text);
    if($name=='' || $text==''){
        return;
    }
    $stmt=$conn->prepare("
INSERT INTO prescription_templates
(
template_name,
template_text,
template_type,
created_by
)
VALUES
(
?,?,?,?
)
");
if(!$stmt){
    die($conn->error);
}
$stmt->bind_param(
"sssi",
$name,
$text,
$type,
$user
);
    if(!$stmt->execute()){
    die($stmt->error);
}
$stmt->close();
}
/* ===============================
SAVE NOTES TEMPLATES
================================ */
if(!empty($showTabs['notes'])){
    if(isset($_POST['save_chief_complaint_template'])){
        saveTemplate(
        $conn,
        $_POST['chief_complaint_template_name'] ?? '',
        $chief_complaint,
        'chief_complaint',
        $user_id
        );
    }
    if(isset($_POST['save_drug_history_template'])){
        saveTemplate(
        $conn,
        $_POST['drug_history_template_name'] ?? '',
        $drug_history,
        'drug_history',
        $user_id
        );
    }
    if(isset($_POST['save_past_history_template'])){
        saveTemplate(
        $conn,
        $_POST['past_history_template_name'] ?? '',
        $past_history,
        'past_history',
        $user_id
        );
    }
    if(isset($_POST['save_present_illness_template'])){
        saveTemplate(
        $conn,
        $_POST['present_illness_template_name'] ?? '',
        $present_illness,
        'present_illness',
        $user_id
        );
    }
    if(isset($_POST['save_procedure_advice_template'])){
        saveTemplate(
        $conn,
        $_POST['procedure_advice_template_name'] ?? '',
        $procedure_advice,
        'procedure_advice',
        $user_id
        );
    }
    if(isset($_POST['save_advice_template'])){
        saveTemplate(
        $conn,
        $_POST['advice_template_name'] ?? '',
        $advice,
        'advice',
        $user_id
        );
    }
    if(isset($_POST['save_prognosis_template'])){
        saveTemplate(
        $conn,
        $_POST['prognosis_template_name'] ?? '',
        $prognosis,
        'prognosis',
        $user_id
        );
    }
    if(isset($_POST['save_remarks_template'])){
        saveTemplate(
        $conn,
        $_POST['remarks_template_name'] ?? '',
        $remarks,
        'remarks',
        $user_id
        );
    }
}
/* ===============================
SAVE DIAGNOSIS TEMPLATES
================================ */
if(!empty($showTabs['diagnosis'])){
    if(isset($_POST['save_diagnosis_template'])){
        saveTemplate(
        $conn,
        $_POST['diagnosis_template_name'] ?? '',
        $diagnosis,
        'diagnosis',
        $user_id
        );
    }
    if(isset($_POST['save_medicine_template'])){
        saveTemplate(
        $conn,
        $_POST['medicine_template_name'] ?? '',
        $medicines,
        'medicine',
        $user_id
        );
    }
}
/* ===============================
GET APPOINTMENT DETAILS
================================ */
$stmt = $conn->prepare("
SELECT
    patient_id,
    entity_id
FROM appointments
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i", $appointment_id);
$stmt->execute();
$stmt->bind_result($patient_id, $doctor_id);
$stmt->fetch();
$stmt->close();
/* ===============================
SAVE PARAMETER READINGS
================================ */
$parameterJson=$_POST['parameter_readings'] ?? '';

if(
    trim($parameterJson)!='' &&
    $parameterJson!='[]'
){

$stmt=$conn->prepare("
DELETE FROM parameter_readings
WHERE appointment_id=?
");

$stmt->bind_param("i",$appointment_id);

if(!$stmt->execute()){
die($stmt->error);
}

$stmt->close();

}
$parameterJson = $_POST['parameter_readings'] ?? '';

if(
    trim($parameterJson) != '' &&
    $parameterJson != '[]'
){

    $parameters = json_decode($parameterJson, true);

    if(is_array($parameters) && count($parameters) > 0){
$stmt=$conn->prepare("
INSERT INTO parameter_readings
(
prescription_id,
appointment_id,
patient_id,
parameter_id,
reading,
reading_date,
reading_time,
taken_by,
created_by
)
VALUES
(
?,?,?,?,?,?,?,?,?
)
");

if(!$stmt){
die($conn->error);
}
foreach($parameters as $row){
$parameter_id=(int)($row['parameter_id'] ?? 0);
$reading=trim($row['reading'] ?? '');
$reading_date=$row['date'] ?? date('Y-m-d');
$reading_time=$row['time'] ?? date('H:i:s');
$taken_by=(int)($row['taken_by'] ?? 0);
if($parameter_id<=0 || $reading==''){
continue;
}
$stmt->bind_param(
"iiiisssii",
$prescription_id,
$appointment_id,
$patient_id,
$parameter_id,
$reading,
$reading_date,
$reading_time,
$taken_by,
$user_id
);
if(!$stmt->execute()){
die($stmt->error);
}
}
$stmt->close();
}
}
/* ===============================
SAVE PRESCRIBED SERVICES
================================ */
if(
    $patient_type==='opd' ||
    $patient_type==='ipd'
){

$stmt=$conn->prepare("
DELETE FROM prescribed_services
WHERE appointment_id=?
");

$stmt->bind_param(
    "i",
    $appointment_id
);

if(!$stmt->execute()){
    die($stmt->error);
}

$stmt->close();

}
if(!empty($selected_services)){
$service_ids=explode(',',$selected_services);
$stmt=$conn->prepare("
INSERT INTO prescribed_services
(
appointment_id,
prescription_id,
patient_id,
doctor_id,
service_id,
billing_status,
created_by
)
VALUES
(
?,?,?,?,?,'Pending',?
)
");
if(!$stmt){
die($conn->error);
}
foreach($service_ids as $service_id){
$service_id=(int)$service_id;
if($service_id<=0){
continue;
}
$stmt->bind_param(
"iiiiii",
$appointment_id,
$prescription_id,
$patient_id,
$doctor_id,
$service_id,
$user_id
);
if(!$stmt->execute()){
die($stmt->error);
}
}
$stmt->close();
}
/* ===============================
SAVE PRESCRIBED MEDICINES
================================ */
/* ===============================
DELETE OLD PRESCRIBED MEDICINES
ON EDIT
================================ */
if($prescription_id > 0){

    $stmt=$conn->prepare("
        DELETE FROM prescribed_medicines
        WHERE prescription_id=?
    ");

    if(!$stmt){
        die($conn->error);
    }

    $stmt->bind_param(
        "i",
        $prescription_id
    );

    if(!$stmt->execute()){
        die($stmt->error);
    }

    $stmt->close();
}
$medicineJson=$_POST['selected_items'] ?? '';
if($medicineJson!=''){
$medicines=json_decode($medicineJson,true);
if(is_array($medicines)){
$stmt=$conn->prepare("
INSERT INTO prescribed_medicines
(
prescription_id,
appointment_id,
patient_type,
patient_id,
doctor_id,
item_id,
frequency,
meal,
qty,
morning,
noon,
evening,
stop_medication,
stop_date,
comments,
pharmacy_status,
billing_status,
created_by
)
VALUES
(
?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
)
");
if(!$stmt){
die($conn->error);
}
foreach($medicines as $row){
$item_id=(int)($row['item_id'] ?? 0);
if($item_id<=0){
continue;
}
$frequency=$row['frequency'] ?? '';
$meal=$row['meal'] ?? '';
$qty=(float)($row['qty'] ?? 0);
$morning=(float)($row['m'] ?? 0);
$noon=(float)($row['n'] ?? 0);
$evening=(float)($row['e'] ?? 0);
$stop=(int)($row['stop'] ?? 0);
$stop_date=$row['dated'] ?? null;
$comments=$row['comments'] ?? '';
$pharmacy_status='Pending';
$billing_status='Pending';
$stmt->bind_param(
"iisiiissddddissssi",
$prescription_id,
$appointment_id,
$patient_type,
$patient_id,
$doctor_id,
$item_id,
$frequency,
$meal,
$qty,
$morning,
$noon,
$evening,
$stop,
$stop_date,
$comments,
$pharmacy_status,
$billing_status,
$user_id
);
if(!$stmt->execute()){
die($stmt->error);
}
}
$stmt->close();
}
}

/* ===============================
SAVE IPD DOCTOR VISITS
================================ */

if(
    $patient_type==='ipd' &&
    !empty($_POST['doctor_visits'])
){
    if($prescription_id>0){

    $stmt=$conn->prepare("
        DELETE FROM ipd_doctor_visits
        WHERE prescription_id=?
    ");

    if(!$stmt){
        die($conn->error);
    }

    $stmt->bind_param(
        "i",
        $prescription_id
    );

    if(!$stmt->execute()){
        die($stmt->error);
    }

    $stmt->close();
}
/* DELETE EXISTING DOCTOR VISITS FOR THIS PRESCRIPTION */
if($prescription_id>0){
    $stmt=$conn->prepare("
        DELETE FROM ipd_doctor_visits
        WHERE prescription_id=?
    ");

    if(!$stmt){
        die($conn->error);
    }

    $stmt->bind_param(
        "i",
        $prescription_id
    );

    if(!$stmt->execute()){
        die($stmt->error);
    }

    $stmt->close();
}
    /* GET IPD ADMISSION ID */

    $stmt=$conn->prepare("
        SELECT id
        FROM ipd_admissions
        WHERE appointment_id=?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $appointment_id
    );

    $stmt->execute();

    $stmt->bind_result(
        $ipd_admission_id
    );

    $stmt->fetch();

    $stmt->close();


    if($ipd_admission_id){

        $stmt=$conn->prepare("
        INSERT INTO ipd_doctor_visits
(
ipd_admission_id,
prescription_id,
patient_id,
doctor_id,
visit_date,
visit_time,
charge_type,
charges,
comment,
created_by
)
        VALUES(?,?,?,?,?,?,?,?,?,?)
        ");

        foreach($_POST['doctor_visits']['doctor_id'] as $key=>$doctor_id){

            $doctor_id=(int)$doctor_id;

            if($doctor_id<=0){
                continue;
            }

            $visit_date=$_POST['doctor_visits']['date'][$key] ?? date('Y-m-d');
            $visit_time=$_POST['doctor_visits']['time'][$key] ?? date('H:i:s');
            $charge_type=$_POST['doctor_visits']['charge_type'][$key] ?? '';
            $charges=(float)($_POST['doctor_visits']['charges'][$key] ?? 0);
            $comment=$_POST['doctor_visits']['comment'][$key] ?? '';


            $stmt->bind_param(
    "iiiisssdsi",
    $ipd_admission_id,
    $prescription_id,
    $patient_id,
    $doctor_id,
    $visit_date,
    $visit_time,
    $charge_type,
    $charges,
    $comment,
    $user_id
);


            if(!$stmt->execute()){
                die($stmt->error);
            }

        }

        $stmt->close();

    }

}
/* ===============================
ABDM M2 - PRESCRIPTION CARE CONTEXT
=============================== */

/*
 * GLOBAL ABDM SWITCH
 *
 * The care-context link below is an automatic OUTBOUND ABDM operation performed
 * on every prescription save. It is skipped entirely when the installation has
 * ABDM switched off, and also when the Global ABDM setting cannot be read, in
 * which case the switch fails closed.
 *
 * Skipping this block is safe: it runs after the prescription has already been
 * persisted, performs no billing or validation work, and writes nothing that
 * ordinary HMS behaviour depends on. Prescription saving continues normally.
 *
 * WHICH MECHANISM IS USED, AND WHY IT CHANGED
 * -------------------------------------------
 * This block previously called abdmM2LinkCareContext(), which posts to the v3
 * HiECM endpoint /hiecm/hip/v3/link/carecontext and carries a linking token
 * obtained from /hiecm/hip/v3/token/generate-token.
 *
 * It now calls abdmM2AddCareContexts(), which posts to the v0.5 gateway endpoint
 * /gateway/v0.5/links/link/add-contexts and carries the Consent Manager linking
 * token that the patient authorises in the ABHA app and that arrives at
 * api/v0.5/users/auth/on-confirm/index.php.
 *
 * Two reasons for the pivot:
 *
 *   1. The v0.5 path is the current documented HIP-initiated linking API and is
 *      the one the Consent Manager recognises for /gateway/v0.5/links/link/*.
 *   2. The v0.5 path removes this installation's SMS OTP dependency entirely,
 *      which was the goal. The patient still authenticates, but that now happens
 *      in the ABHA Sandbox app rather than against this system's own messaging.
 *
 * NOTE FOR DIAGNOSIS: every PRESCRIPTION-* care context recorded before this
 * change carries link_status = 'Failed', while OPCONSULTATION contexts linked
 * successfully. That discrepancy is unresolved and is NOT fixed by this change.
 * The new path is gated on a CM linking token and deliberately has no fallback
 * to the old one, so a patient with no token produces a skip rather than an
 * attempt.
 */
if(abdmOutboundAllowed($conn) && $prescription_id > 0 && $patient_id > 0){

    $careContextReference = 'PRESCRIPTION-' . $prescription_id;
    $careContextDisplay = 'Prescription #' . $prescription_id;

    $m2Status = '';

    $stmt = $conn->prepare("
        SELECT link_status
        FROM abdm_care_contexts
        WHERE patient_id = ?
          AND care_context_reference = ?
        LIMIT 1
    ");

    if($stmt){

        $stmt->bind_param(
            "is",
            $patient_id,
            $careContextReference
        );

        $stmt->execute();

        $stmt->bind_result($m2Status);

        $stmt->fetch();

        $stmt->close();
    }

    /*
     * Do not send the same already-linked care context again.
     * If it does not exist or previously failed, try linking it.
     */
    if($m2Status !== 'Linked'){

        $m2CareContextResult = abdmM2AddCareContexts(
            $patient_id,
            $careContextReference,
            $careContextDisplay,
            'PRESCRIPTION'
        );

        if(empty($m2CareContextResult['success'])){

            /*
             * A SKIP IS NOT A FAILURE.
             *
             * 'no_cm_linking_token' means the patient has not authorised linking
             * yet, which is an expected state rather than a fault. It is logged at
             * a distinct level so the two are distinguishable when counting
             * linking coverage.
             *
             * THE RESULT ARRAY IS DELIBERATELY NOT SERIALISED.
             *
             * This used to log json_encode($m2CareContextResult), which includes
             * the gateway response. The v0.5 gateway can echo the linking token
             * back in that body, so serialising the whole result would write a
             * live bearer credential into the error log on every failure. Only
             * fixed labels are recorded now.
             */
            $isSkip = !empty($m2CareContextResult['skipped']);

            error_log(
                'ABDM M2 Prescription Care Context ' . ($isSkip ? 'skipped' : 'failed') .
                ' | reason=' . preg_replace(
                    '/[^A-Za-z0-9_.\-]/',
                    '',
                    (string)($m2CareContextResult['skip_reason'] ?? 'unknown')
                ) .
                ' | http_code=' . (int)($m2CareContextResult['http_code'] ?? 0) .
                ' | care_context=' . $careContextReference
            );
        }
    }
}
/* ===============================
UPDATE APPOINTMENT STATUS
OPD = Prescription
IPD = Keep Admitted
================================ */
if($patient_type==='opd'){
$stmt=$conn->prepare("
UPDATE appointments
SET status='Prescription'
WHERE id=?
");
$stmt->bind_param("i",$appointment_id);
if(!$stmt->execute()){
die($stmt->error);
}
$stmt->close();
}
/* ===============================
REDIRECT
================================ */
if($patient_type=='opd'){
echo "
<script>
window.top.location.href='/modules/opd/opd_list.php';
</script>
";
}else{
echo "
<script>
window.top.location.href='/modules/ipd/ip_prescription.php';
</script>
";
}
exit;