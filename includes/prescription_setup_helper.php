<?php
if(!function_exists('getDoctorPrescriptionTabs')){
function getDoctorPrescriptionTabs($conn,$appointment_id){
    $appointment_id=(int)$appointment_id;
    $showTabs=[
    'notes'=>1,

    'patient_vitals'=>1,
    'chief_complaint'=>1,
    'drug_history'=>1,
    'past_history'=>1,
    'present_illness_history'=>1,
    'diagnosis_section'=>1,
    'medicines'=>1,
    'procedure_advice'=>1,
    'advice'=>1,
    'prognosis'=>1,
    'remarks'=>1,

    'diagnosis'=>1,
    'services'=>1,
    'diet_details'=>1,
    'eye_details'=>1,
    'gynae_details'=>1,
    'embryology'=>1,
'bariatric'=>1,
'nursing_notes'=>1,
'parameters'=>1
];
    /* Super Admin always sees all tabs */
    if(($_SESSION['rights_type'] ?? '')=='Super Admin'){
        return $showTabs;
    }
    if($appointment_id<=0){
        return $showTabs;
    }
    /* Get Doctor User ID from Appointment */
    $doctorUserId=0;
    $sql=mysqli_query($conn,"
    SELECT entity_id
    FROM appointments
    WHERE id='$appointment_id'
    LIMIT 1
    ");
    if($sql && mysqli_num_rows($sql)){
        $row=mysqli_fetch_assoc($sql);
        $doctorUserId=(int)$row['entity_id'];
    }
    if($doctorUserId==0){
        return $showTabs;
    }
    /* Get Doctor Master ID */
    $doctorId=0;
    $sql=mysqli_query($conn,"
    SELECT id
    FROM doctor_master
    WHERE user_id='$doctorUserId'
    LIMIT 1
    ");
    if($sql && mysqli_num_rows($sql)){
        $row=mysqli_fetch_assoc($sql);
        $doctorId=(int)$row['id'];
    }
    if($doctorId==0){
        return $showTabs;
    }
    /* Get Prescription Setup */
    $sql=mysqli_query($conn,"
    SELECT
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
parameters
FROM doctor_prescription_tabs
    WHERE doctor_id='$doctorId'
    AND status='Active'
    LIMIT 1
    ");
    if($sql && mysqli_num_rows($sql)){
        $row=mysqli_fetch_assoc($sql);
        $showTabs=array_merge($showTabs,$row);
    }
    return $showTabs;
}
}
?>