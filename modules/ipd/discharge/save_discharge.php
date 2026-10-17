<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD']!=='POST'){
    echo json_encode([
        'success'=>false,
        'message'=>'Invalid request.'
    ]);
    exit;
}

$conn->begin_transaction();

try{

    /*========================================================
      USER
    ========================================================*/

    $user_id=(int)($_SESSION['user_id'] ?? 0);

    if($user_id<=0){
        throw new Exception('User session expired. Please login again.');
    }

    /*========================================================
      POST DATA
    ========================================================*/

    $admission_id=(int)($_POST['ipd_admission_id'] ?? $_POST['admission_id'] ?? 0);

    $final_diagnosis=trim($_POST['final_diagnosis'] ?? '');
    $secondary_diagnosis=trim($_POST['secondary_diagnosis'] ?? '');
    $prognosis=trim($_POST['prognosis'] ?? '');
    $comorbidity=trim($_POST['comorbidity'] ?? '');
    $condition_at_discharge=trim($_POST['condition_at_discharge'] ?? '');
    $co=trim($_POST['co'] ?? '');
$ho=trim($_POST['ho'] ?? '');
    $comments=trim($_POST['comments'] ?? '');
    $discharge_summary=trim($_POST['summary'] ?? '');
    $diet_comments=trim($_POST['diet_comments'] ?? '');
    $guidelines_comments=trim($_POST['guidelines_comments'] ?? '');
    $weight=trim($_POST['weight'] ?? '');
$height=trim($_POST['height'] ?? '');
$bmi=trim($_POST['bmi'] ?? '');
$follow_up_days=trim($_POST['follow_up_days'] ?? '');
$follow_up_date=trim($_POST['follow_up_date'] ?? '');
$pain_scale=trim($_POST['pain_scale'] ?? '');
$follow_up_details=trim($_POST['follow_up_details'] ?? '');
$advice_at_discharge=trim($_POST['advice_at_discharge'] ?? '');
    $discharge_type=trim($_POST['discharge_type'] ?? 'Normal');

    $parameter_readings=$_POST['parameter_readings'] ?? '';

    if($admission_id<=0){
        throw new Exception('Invalid IPD admission.');
    }

    /*========================================================
      GET ADMISSION
    ========================================================*/

    $stmt=$conn->prepare("
        SELECT
    id,
    patient_id,
    appointment_id,
    ipd_no,
    status,
    payment_type,
    doctor_id,
    admission_date,
    admission_time
FROM ipd_admissions
        WHERE id=?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->bind_param("i",$admission_id);
    $stmt->execute();

    $result=$stmt->get_result();

    if($result->num_rows===0){
        throw new Exception('IPD admission not found.');
    }

    $admission=$result->fetch_assoc();
    $stmt->close();

    if($admission['status']==='Discharged'){
        throw new Exception('This IPD admission is already discharged.');
    }

    if($admission['status']==='Cancelled'){
        throw new Exception('Cancelled IPD admission cannot be discharged.');
    }

    if($admission['status']!=='Admitted'){
        throw new Exception('Only admitted patients can be discharged.');
    }

    $patient_id=(int)$admission['patient_id'];
    $appointment_id=(int)$admission['appointment_id'];
    $ipd_no=$admission['ipd_no'];
    $admission_doctor_id=(int)$admission['doctor_id'];
    $payment_type=$admission['payment_type'];

    /*========================================================
      DISCHARGE DATE / TIME
    ========================================================*/

    /*========================================================
  DISCHARGE DATE / TIME
========================================================*/

$discharge_date=trim($_POST['discharge_date'] ?? '');
$discharge_time=trim($_POST['discharge_time'] ?? '');

if($discharge_type==='Death'){
    $discharge_date=trim($_POST['death_date'] ?? '');
    $discharge_time=trim($_POST['death_time'] ?? '');
}

/*========================================================
  VALIDATE DISCHARGE DATE
========================================================*/

if($discharge_date===''){
    throw new Exception('Discharge date is required.');
}

$dateObject=DateTime::createFromFormat('Y-m-d',$discharge_date);

if(!$dateObject || $dateObject->format('Y-m-d')!==$discharge_date){
    throw new Exception('Invalid discharge date.');
}

/*========================================================
  VALIDATE DISCHARGE TIME
========================================================*/

if($discharge_time===''){
    throw new Exception('Discharge time is required.');
}

if(preg_match('/^\d{2}:\d{2}$/',$discharge_time)){
    $discharge_time.=':00';
}

$timeObject=DateTime::createFromFormat('H:i:s',$discharge_time);

if(!$timeObject || $timeObject->format('H:i:s')!==$discharge_time){
    throw new Exception('Invalid discharge time.');
}

/*========================================================
  GET ADMISSION DATE/TIME FOR VALIDATION
========================================================*/

$admission_date=trim($admission['admission_date'] ?? '');

if($admission_date===''){
    throw new Exception('Admission date is missing.');
}

$admissionDateObject=DateTime::createFromFormat('Y-m-d',$admission_date);

if(!$admissionDateObject){
    throw new Exception('Invalid admission date.');
}

/*========================================================
  DISCHARGE DATE CANNOT BE BEFORE ADMISSION DATE
========================================================*/

if($dateObject < $admissionDateObject){
    throw new Exception(
        'Discharge date cannot be before admission date.'
    );
}


/*========================================================
  FOR DEATH, VALIDATE DATE/TIME
========================================================*/

if($discharge_type==='Death'){

    if($discharge_date===''){
        throw new Exception('Death date is required.');
    }

    if($discharge_time===''){
        throw new Exception('Death time is required.');
    }
}

    /*
     * For Death discharge, use death date/time when supplied.
     */
    /*========================================================
      PARAMETER READINGS
    ========================================================*/

    if(is_array($parameter_readings)){
        $parameter_readings=json_encode(
            $parameter_readings,
            JSON_UNESCAPED_UNICODE
        );
    }

    if($parameter_readings===''){
        $parameter_readings=null;
    }

    /*========================================================
      EXISTING DISCHARGE SUMMARY
      ONE SUMMARY PER ADMISSION
    ========================================================*/

    $stmt=$conn->prepare("
        SELECT id
        FROM ipd_discharge_summaries
        WHERE admission_id=?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->bind_param("i",$admission_id);
    $stmt->execute();

    $existing=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if($existing){

        $discharge_id=(int)$existing['id'];

        $stmt=$conn->prepare("
            UPDATE ipd_discharge_summaries
            SET
    patient_id=?,
    appointment_id=?,
    ipd_no=?,
    discharge_type=?,
    discharge_date=?,
    discharge_time=?,
    final_diagnosis=?,
    secondary_diagnosis=?,
    prognosis=?,
    comorbidity=?,
    condition_at_discharge=?,
    co=?,
    ho=?,
    comments=?,
    discharge_summary=?,
    diet_comments=?,
    guidelines_comments=?,
    parameter_readings=?,
    weight=?,
    height=?,
    bmi=?,
    follow_up_days=?,
    follow_up_date=?,
    pain_scale=?,
    follow_up_details=?,
    advice_at_discharge=?,
    created_by=?,
    updated_at=CURRENT_TIMESTAMP
            WHERE id=?
        ");

        $stmt->bind_param(
    "iissssssssssssssssddsissssii",
    $patient_id,
    $appointment_id,
    $ipd_no,
    $discharge_type,
    $discharge_date,
    $discharge_time,
    $final_diagnosis,
    $secondary_diagnosis,
    $prognosis,
    $comorbidity,
    $condition_at_discharge,
    $co,
    $ho,
    $comments,
    $discharge_summary,
    $diet_comments,
    $guidelines_comments,
    $parameter_readings,
    $weight,
    $height,
    $bmi,
    $follow_up_days,
    $follow_up_date,
    $pain_scale,
    $follow_up_details,
    $advice_at_discharge,
    $user_id,
    $discharge_id
);

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $stmt->close();

        /* Remove old discharge child records */

        $stmt=$conn->prepare("
            DELETE FROM ipd_discharge_diseases
            WHERE discharge_id=?
        ");
        $stmt->bind_param("i",$discharge_id);
        $stmt->execute();
        $stmt->close();

        $stmt=$conn->prepare("
            DELETE FROM ipd_discharge_doctors
            WHERE discharge_id=?
        ");
        $stmt->bind_param("i",$discharge_id);
        $stmt->execute();
        $stmt->close();

        $stmt=$conn->prepare("
            DELETE FROM ipd_discharge_services
            WHERE discharge_id=?
        ");
        $stmt->bind_param("i",$discharge_id);
        $stmt->execute();
        $stmt->close();

        $stmt=$conn->prepare("
            DELETE FROM ipd_discharge_medicines
            WHERE discharge_id=?
        ");
        $stmt->bind_param("i",$discharge_id);
        $stmt->execute();
        $stmt->close();

    }else{

        $stmt=$conn->prepare("
           INSERT INTO ipd_discharge_summaries
(
    admission_id,
    patient_id,
    appointment_id,
    ipd_no,
    discharge_type,
    discharge_date,
    discharge_time,
    final_diagnosis,
    secondary_diagnosis,
    prognosis,
    comorbidity,
    condition_at_discharge,
    co,
    ho,
    comments,
    discharge_summary,
    diet_comments,
    guidelines_comments,
    parameter_readings,
    weight,
    height,
    bmi,
    follow_up_days,
    follow_up_date,
    pain_scale,
    follow_up_details,
    advice_at_discharge,
    created_by
            )
            VALUES
(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->bind_param(
    "iiissssssssssssssssddsissssi",
    $admission_id,
    $patient_id,
    $appointment_id,
    $ipd_no,
    $discharge_type,
    $discharge_date,
    $discharge_time,
    $final_diagnosis,
    $secondary_diagnosis,
    $prognosis,
    $comorbidity,
    $condition_at_discharge,
    $co,
    $ho,
    $comments,
    $discharge_summary,
    $diet_comments,
    $guidelines_comments,
    $parameter_readings,
    $weight,
    $height,
    $bmi,
    $follow_up_days,
    $follow_up_date,
    $pain_scale,
    $follow_up_details,
    $advice_at_discharge,
    $user_id
);

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $discharge_id=$stmt->insert_id;
        $stmt->close();
    }

    /*========================================================
      DISEASES
    ========================================================*/

    $disease_ids=$_POST['selected_disease_ids'] ?? [];

    if(!is_array($disease_ids)){
        $disease_ids=[];
    }

    $disease_ids=array_unique(
        array_filter(
            array_map('intval',$disease_ids)
        )
    );

    if(!empty($disease_ids)){

        $stmt=$conn->prepare("
            INSERT INTO ipd_discharge_diseases
            (
                discharge_id,
                admission_id,
                patient_id,
                disease_id,
                created_by
            )
            VALUES
            (?,?,?,?,?)
        ");

        foreach($disease_ids as $disease_id){

            if($disease_id<=0){
                continue;
            }

            $stmt->bind_param(
                "iiiii",
                $discharge_id,
                $admission_id,
                $patient_id,
                $disease_id,
                $user_id
            );

            if(!$stmt->execute()){
                throw new Exception($stmt->error);
            }
        }

        $stmt->close();
    }

    /*========================================================
      DOCTORS
    ========================================================*/

    $doctor_ids=[];

    if(isset($_POST['selected_doctor_ids']) && is_array($_POST['selected_doctor_ids'])){
        $doctor_ids=$_POST['selected_doctor_ids'];
    }

    if(isset($_POST['doctor_ids']) && is_array($_POST['doctor_ids'])){
        $doctor_ids=array_merge($doctor_ids,$_POST['doctor_ids']);
    }

    if(empty($doctor_ids)){

        foreach($_POST as $key=>$value){

            if(strpos($key,'doctor_id')!==false){

                if(is_array($value)){
                    foreach($value as $doctor_id){
                        $doctor_ids[]=$doctor_id;
                    }
                }else{
                    $doctor_ids[]=$value;
                }
            }
        }
    }

    $doctor_ids=array_unique(
        array_filter(
            array_map('intval',$doctor_ids)
        )
    );

    if(empty($doctor_ids) && $admission_doctor_id>0){
        $doctor_ids[]=$admission_doctor_id;
    }

    if(!empty($doctor_ids)){

        $stmt=$conn->prepare("
            INSERT INTO ipd_discharge_doctors
            (
                discharge_id,
                admission_id,
                patient_id,
                doctor_id,
                created_by
            )
            VALUES
            (?,?,?,?,?)
        ");

        foreach($doctor_ids as $doctor_id){

            if($doctor_id<=0){
                continue;
            }

            $stmt->bind_param(
                "iiiii",
                $discharge_id,
                $admission_id,
                $patient_id,
                $doctor_id,
                $user_id
            );

            if(!$stmt->execute()){
                throw new Exception($stmt->error);
            }
        }

        $stmt->close();
    }

    /*========================================================
      CREATE IPD PRESCRIPTION
      Used by existing Pharmacy/Billing workflows
    ========================================================*/

    $selected_services=$_POST['selected_services'] ?? '';

    $medicine_json=$_POST['selected_medicines'] ?? '';

if($medicine_json===''){
    $medicine_json=$_POST['instructions_medicines'] ?? '';
}

if($medicine_json===''){
    $medicine_json=$_POST['medicines'] ?? '';
}

    $services=[];
$medicines=[];

if(is_string($selected_services) && trim($selected_services)!==''){

    $decoded_services=json_decode($selected_services,true);

    if(json_last_error()===JSON_ERROR_NONE && is_array($decoded_services)){

        $services=$decoded_services;

    }else{

        $service_ids=array_filter(
            array_map(
                'intval',
                preg_split('/\s*,\s*/',$selected_services)
            )
        );

        foreach($service_ids as $service_id){

            if($service_id>0){
                $services[]=[
                    'service_id'=>$service_id
                ];
            }
        }
    }
}

    if(is_string($medicine_json) && trim($medicine_json)!==''){

        $decoded_medicines=json_decode($medicine_json,true);

        if(json_last_error()===JSON_ERROR_NONE && is_array($decoded_medicines)){
            $medicines=$decoded_medicines;
        }
    }

    $prescription_id=0;

    /*
     * Create one IPD prescription only when discharge
     * medicines/services are actually selected.
     */
    if(!empty($services) || !empty($medicines)){

        $prescription_medicines=json_encode(
            $medicines,
            JSON_UNESCAPED_UNICODE
        );

        $prescription_services=json_encode(
            $services,
            JSON_UNESCAPED_UNICODE
        );

        $stmt=$conn->prepare("
            INSERT INTO opd_prescriptions
            (
                appointment_id,
                patient_type,
                medicines,
                selected_services,
                created_by
            )
            VALUES
            (?,'ipd',?,?,?)
        ");

        $stmt->bind_param(
            "issi",
            $appointment_id,
            $prescription_medicines,
            $prescription_services,
            $user_id
        );

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $prescription_id=$stmt->insert_id;
        $stmt->close();
    }

    /*========================================================
      SERVICES
      Save in discharge table + existing billing table
    ========================================================*/

    if(!empty($services)){

        $stmt_discharge=$conn->prepare("
            INSERT INTO ipd_discharge_services
            (
                discharge_id,
                admission_id,
                patient_id,
                service_id,
                billing_status,
                created_by
            )
            VALUES
            (?,?,?,?, 'Pending',?)
        ");

        $stmt_billing=$conn->prepare("
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
            (?,?,?,?,?,'Pending',?)
        ");

        foreach($services as $service){

            if(is_array($service)){
                $service_id=(int)($service['service_id'] ?? $service['id'] ?? 0);
                $service_doctor_id=(int)(
                    $service['doctor_id']
                    ?? $service['doctor']
                    ?? 0
                );
            }else{
                $service_id=(int)$service;
                $service_doctor_id=0;
            }

            if($service_id<=0){
                continue;
            }

            if($service_doctor_id<=0){
                $service_doctor_id=$admission_doctor_id;
            }

            if($service_doctor_id<=0){
                throw new Exception(
                    'Doctor is required for selected service ID '.$service_id.'.'
                );
            }

            $stmt_discharge->bind_param(
                "iiiii",
                $discharge_id,
                $admission_id,
                $patient_id,
                $service_id,
                $user_id
            );

            if(!$stmt_discharge->execute()){
                throw new Exception($stmt_discharge->error);
            }

            if($prescription_id<=0){
                throw new Exception('Unable to create IPD prescription.');
            }

            $stmt_billing->bind_param(
                "iiiiii",
                $appointment_id,
                $prescription_id,
                $patient_id,
                $service_doctor_id,
                $service_id,
                $user_id
            );

            if(!$stmt_billing->execute()){
                throw new Exception($stmt_billing->error);
            }
        }

        $stmt_discharge->close();
        $stmt_billing->close();
    }

    /*========================================================
      MEDICINES
      Save in discharge table + existing pharmacy table
    ========================================================*/

    if(!empty($medicines)){

        $stmt_discharge=$conn->prepare("
            INSERT INTO ipd_discharge_medicines
            (
                discharge_id,
                admission_id,
                patient_id,
                item_id,
                medicine,
                frequency,
                meal,
                qty,
                morning,
                noon,
                evening,
                doctor_id,
                stop_medication,
                stop_date,
                comments,
                pharmacy_status,
                dispensed_qty,
                billing_status,
                created_by
            )
            VALUES
            (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending',0,'Pending',?)
        ");

        $stmt_pharmacy=$conn->prepare("
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
                dispensed_qty,
                billing_status,
                created_by
            )
            VALUES
            (?,?, 'ipd',?,?,?,?,?,?,?,?,?,?,?,?, 'Pending',0,'Pending',?)
        ");

        foreach($medicines as $medicine){

            if(!is_array($medicine)){
                continue;
            }

            $item_id=(int)($medicine['item_id'] ?? 0);

            if($item_id<=0){
                continue;
            }

            $medicine_name=trim(
                $medicine['medicine']
                ?? $medicine['item_name']
                ?? ''
            );

            $frequency=trim($medicine['frequency'] ?? '');
            $meal=trim($medicine['meal'] ?? '');

            $qty=(float)($medicine['qty'] ?? 0);
            $morning=(float)($medicine['m'] ?? $medicine['morning'] ?? 0);
            $noon=(float)($medicine['n'] ?? $medicine['noon'] ?? 0);
            $evening=(float)($medicine['e'] ?? $medicine['evening'] ?? 0);

            $doctor_id=(int)(
                $medicine['doctor']
                ?? $medicine['doctor_id']
                ?? 0
            );

            if($doctor_id<=0){
                $doctor_id=$admission_doctor_id;
            }

            if($doctor_id<=0){
                throw new Exception(
                    'Doctor is required for medicine '.$medicine_name.'.'
                );
            }

            $stop_medication_raw=trim(
                (string)(
                    $medicine['stop']
                    ?? $medicine['stop_medication']
                    ?? ''
                )
            );

            /*
             * Discharge table keeps the original text.
             */
            $stop_medication=$stop_medication_raw;

            /*
             * prescribed_medicines expects tinyint:
             * 1 = stopped
             * 0 = not stopped
             */
            $stop_medication_flag=0;

            if(
                $stop_medication_raw!==''
                &&
                (
                    strtolower($stop_medication_raw)==='1'
                    ||
                    strtolower($stop_medication_raw)==='yes'
                    ||
                    strtolower($stop_medication_raw)==='true'
                    ||
                    strtolower($stop_medication_raw)==='stop'
                )
            ){
                $stop_medication_flag=1;
            }

            $stop_date_value=trim(
                (string)(
                    $medicine['dated']
                    ?? $medicine['stop_date']
                    ?? ''
                )
            );

            $stop_date=null;

            if(
                $stop_date_value!==''
                &&
                preg_match('/^\d{4}-\d{2}-\d{2}$/',$stop_date_value)
            ){
                $stop_date=$stop_date_value;
            }

            $medicine_comments=trim(
                $medicine['comments'] ?? ''
            );

            /* Save discharge medicine */

            $stmt_discharge->bind_param(
                "iiiisssddddisssi",
                $discharge_id,
                $admission_id,
                $patient_id,
                $item_id,
                $medicine_name,
                $frequency,
                $meal,
                $qty,
                $morning,
                $noon,
                $evening,
                $doctor_id,
                $stop_medication,
                $stop_date,
                $medicine_comments,
                $user_id
            );

            if(!$stmt_discharge->execute()){
                throw new Exception($stmt_discharge->error);
            }

            if($prescription_id<=0){
                throw new Exception('Unable to create IPD prescription.');
            }

            /* Save medicine into existing Pharmacy workflow */

            $stmt_pharmacy->bind_param(
                "iiiiissddddissi",
                $prescription_id,
                $appointment_id,
                $patient_id,
                $doctor_id,
                $item_id,
                $frequency,
                $meal,
                $qty,
                $morning,
                $noon,
                $evening,
                $stop_medication_flag,
                $stop_date,
                $medicine_comments,
                $user_id
            );

            if(!$stmt_pharmacy->execute()){
                throw new Exception($stmt_pharmacy->error);
            }
        }

        $stmt_discharge->close();
        $stmt_pharmacy->close();
    }

    /*========================================================
      RELEASE CURRENT ROOM / BED
    ========================================================*/

    $stmt=$conn->prepare("
        SELECT
            id,
            bed_id
        FROM ipd_room_allocation
        WHERE admission_id=?
        AND status='Occupied'
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->bind_param("i",$admission_id);
    $stmt->execute();

    $allocation=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if($allocation){

        $allocation_id=(int)$allocation['id'];
        $bed_id=(int)$allocation['bed_id'];

        $stmt=$conn->prepare("
            UPDATE ipd_room_allocation
            SET
                status='Released',
                released_date=NOW()
            WHERE id=?
            AND status='Occupied'
        ");

        $stmt->bind_param("i",$allocation_id);

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $stmt->close();

        if($bed_id>0){

            $stmt=$conn->prepare("
                UPDATE bed_master
                SET status='Available'
                WHERE id=?
            ");

            $stmt->bind_param("i",$bed_id);

            if(!$stmt->execute()){
                throw new Exception($stmt->error);
            }

            $stmt->close();
        }
    }

    /*========================================================
      DISCHARGE ADMISSION
    ========================================================*/

    $stmt=$conn->prepare("
        UPDATE ipd_admissions
        SET
            status='Discharged',
            updated_at=NOW()
        WHERE id=?
        AND status='Admitted'
    ");

    $stmt->bind_param("i",$admission_id);

    if(!$stmt->execute()){
        throw new Exception($stmt->error);
    }

    if($stmt->affected_rows!==1){
        throw new Exception('IPD admission could not be discharged.');
    }

    $stmt->close();

    /*========================================================
      COMMIT
    ========================================================*/

    $conn->commit();

    echo json_encode([
        'success'=>true,
        'message'=>'Patient discharged successfully.',
        'discharge_id'=>$discharge_id,
        'prescription_id'=>$prescription_id,
        'ipd_no'=>$ipd_no,
        'payment_type'=>$payment_type
    ]);

}catch(Throwable $e){

    $conn->rollback();

    echo json_encode([
        'success'=>false,
        'message'=>$e->getMessage()
    ]);
}