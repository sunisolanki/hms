<?php
declare(strict_types=1);
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
function jsonResponse(array $data,int $status=200): void
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}
function cleanText($value): string
{
    if($value===null){
        return '';
    }
    $value=strip_tags((string)$value);
    $value=html_entity_decode($value,ENT_QUOTES,'UTF-8');
    $value=preg_replace('/\s+/u',' ',trim($value));
    return trim((string)$value);
}
function htmlText($value): string
{
    $value=cleanText($value);
    if($value===''){
        return '';
    }
    return htmlspecialchars($value,ENT_QUOTES,'UTF-8');
}
function uuidV4(): string
{
    $data=random_bytes(16);
    $data[6]=chr((ord($data[6])&0x0f)|0x40);
    $data[8]=chr((ord($data[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
}
function parseDateTimeValue($date,$time=''): ?string
{
    $date=trim((string)$date);
    $time=trim((string)$time);
    if($date===''){
        return null;
    }
    $candidates=array();
    if($time!==''){
        $candidates[]=$date.' '.$time;
        $candidates[]=$date.'T'.$time;
    }
    $candidates[]=$date;
    foreach($candidates as $candidate){
        $timestamp=strtotime($candidate);
        if($timestamp!==false){
            return date('c',$timestamp);
        }
    }
    return null;
}
function hasClinicalValue($value): bool
{
    if($value===null){
        return false;
    }
    $value=trim((string)$value);
    if($value===''){
        return false;
    }
    $numeric=str_replace(',','',$value);
    if(is_numeric($numeric) && (float)$numeric==0.0){
        return false;
    }
    return true;
}
function narrativeDiv(string $title,string $text): array
{
    $text=trim($text);
    if($text===''){
        $text='No information recorded.';
    }
    return array(
        'status'=>'generated',
        'div'=>'<div xmlns="http://www.w3.org/1999/xhtml"><p><b>'.htmlText($title).'</b></p><p>'.nl2br(htmlText($text)).'</p></div>'
    );
}
function sectionCode(string $code,string $display): array
{
    return array(
        'coding'=>array(
            array(
                'system'=>'http://snomed.info/sct',
                'code'=>$code,
                'display'=>$display
            )
        )
    );
}
function makeReference(string $resource,string $id): array
{
    return array('reference'=>$resource.'/'.$id);
}
function addBundleEntry(array &$entries,array $resource): void
{
    $entries[]=array(
        'fullUrl'=>'urn:uuid:'.uuidV4(),
        'resource'=>$resource
    );
}
function scalarText($value): string
{
    if($value===null){
        return '';
    }
    if(is_bool($value)){
        return $value?'true':'false';
    }
    if(is_scalar($value)){
        return cleanText((string)$value);
    }
    return '';
}
function flattenParameterData($value,string $prefix='',array &$output=array()): array
{
    if(is_array($value)){
        foreach($value as $key=>$child){
            $name=$prefix!==''?$prefix.'.'.$key:(string)$key;
            if(is_array($child)){
                flattenParameterData($child,$name,$output);
            }else{
                $scalar=scalarText($child);
                if($scalar!==''){
                    $output[]=array(
                        'name'=>$name,
                        'value'=>$scalar
                    );
                }
            }
        }
    }else{
        $scalar=scalarText($value);
        if($scalar!==''){
            $output[]=array(
                'name'=>$prefix!==''?$prefix:'Parameter',
                'value'=>$scalar
            );
        }
    }
    return $output;
}
try{
    $dischargeId=isset($_GET['discharge_id'])?(int)$_GET['discharge_id']:0;
    if($dischargeId<=0 && isset($_POST['discharge_id'])){
        $dischargeId=(int)$_POST['discharge_id'];
    }
    if($dischargeId<=0){
        jsonResponse(array(
            'success'=>false,
            'message'=>'discharge_id is required'
        ),400);
    }
    $stmt=$conn->prepare("
        SELECT
            ds.*,
            ia.id AS admission_id_ref,
            ia.patient_id AS admission_patient_id,
            ia.appointment_id AS admission_appointment_id,
            ia.ipd_no AS admission_ipd_no,
            ia.file_no AS admission_file_no,
            ia.hospital_id AS admission_hospital_id,
            ia.branch_id AS admission_branch_id,
            ia.doctor_id AS admission_doctor_id,
            ia.department_id AS admission_department_id,
            ia.admission_date AS admission_date_ref,
            ia.admission_time AS admission_time_ref,
            ia.status AS admission_status
        FROM ipd_discharge_summaries ds
        INNER JOIN ipd_admissions ia ON ia.id=ds.admission_id
        WHERE ds.id=?
        LIMIT 1
    ");
    $stmt->bind_param('i',$dischargeId);
    $stmt->execute();
    $result=$stmt->get_result();
    $discharge=$result->fetch_assoc();
    $stmt->close();
    if(!$discharge){
        jsonResponse(array(
            'success'=>false,
            'message'=>'Discharge summary not found',
            'discharge_id'=>$dischargeId
        ),404);
    }
    $patientId=(int)($discharge['patient_id']??$discharge['admission_patient_id']??0);
    $admissionId=(int)($discharge['admission_id']??$discharge['admission_id_ref']??0);
    $appointmentId=(int)($discharge['appointment_id']??$discharge['admission_appointment_id']??0);
    $hospitalId=(int)($discharge['admission_hospital_id']??0);
    $ipdNo=cleanText($discharge['ipd_no']??$discharge['admission_ipd_no']??'');
    if($patientId<=0){
        jsonResponse(array(
            'success'=>false,
            'message'=>'Patient is not linked to this discharge summary',
            'discharge_id'=>$dischargeId
        ),422);
    }
    $stmt=$conn->prepare("
        SELECT
            id,
            uhid,
            title,
            first_name,
            last_name,
            sex,
            dob,
            mobile,
            abha_id
        FROM patient_master
        WHERE id=?
        LIMIT 1
    ");
    $stmt->bind_param('i',$patientId);
    $stmt->execute();
    $patientResult=$stmt->get_result();
    $patient=$patientResult->fetch_assoc();
    $stmt->close();
    if(!$patient){
        jsonResponse(array(
            'success'=>false,
            'message'=>'Patient record not found',
            'patient_id'=>$patientId
        ),422);
    }
    if($hospitalId<=0){
        $hospitalId=(int)($discharge['hospital_id']??0);
    }
    $hospital=null;
    if($hospitalId>0){
        $stmt=$conn->prepare("
            SELECT
                id,
                hospital_code,
                hospital_name
            FROM hospital_master
            WHERE id=?
            LIMIT 1
        ");
        $stmt->bind_param('i',$hospitalId);
        $stmt->execute();
        $hospitalResult=$stmt->get_result();
        $hospital=$hospitalResult->fetch_assoc();
        $stmt->close();
    }
    if(!$hospital){
        $stmt=$conn->prepare("
            SELECT
                id,
                hospital_code,
                hospital_name
            FROM hospital_master
            WHERE status=1
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $hospitalResult=$stmt->get_result();
        $hospital=$hospitalResult->fetch_assoc();
        $stmt->close();
        if($hospital){
            $hospitalId=(int)$hospital['id'];
        }
    }
    if(!$hospital){
        jsonResponse(array(
            'success'=>false,
            'message'=>'Hospital record could not be resolved',
            'hospital_id'=>$hospitalId
        ),422);
    }
    $doctorRows=array();
    $stmt=$conn->prepare("
        SELECT
            dd.doctor_id,
            dm.license_no,
            dm.user_id,
            u.full_name,
            u.employee_id,
            u.email,
            u.mobile_no
        FROM ipd_discharge_doctors dd
        LEFT JOIN doctor_master dm ON dm.id=dd.doctor_id
        LEFT JOIN users u ON u.id=dm.user_id
        WHERE dd.discharge_id=?
        ORDER BY dd.id ASC
    ");
    $stmt->bind_param('i',$dischargeId);
    $stmt->execute();
    $doctorResult=$stmt->get_result();
    while($row=$doctorResult->fetch_assoc()){
        if((int)($row['doctor_id']??0)>0){
            $doctorRows[]=$row;
        }
    }
    $stmt->close();
    if(count($doctorRows)===0){
        $admissionDoctorId=(int)($discharge['admission_doctor_id']??0);
        if($admissionDoctorId>0){
            $stmt=$conn->prepare("
                SELECT
                    dm.id AS doctor_id,
                    dm.license_no,
                    dm.user_id,
                    u.full_name,
                    u.employee_id,
                    u.email,
                    u.mobile_no
                FROM doctor_master dm
                LEFT JOIN users u ON u.id=dm.user_id
                WHERE dm.id=?
                LIMIT 1
            ");
            $stmt->bind_param('i',$admissionDoctorId);
            $stmt->execute();
            $doctorResult=$stmt->get_result();
            $row=$doctorResult->fetch_assoc();
            $stmt->close();
            if($row){
                $doctorRows[]=$row;
            }
        }
    }
    $latestPrescription=null;
    if($appointmentId>0){
        $stmt=$conn->prepare("
            SELECT
                id,
                chief_complaint,
                drug_history,
                past_history,
                present_illness,
                diagnosis,
                advice,
                prognosis,
                remarks,
                weight,
                height,
                bmi,
                pain_scale,
                created_at
            FROM opd_prescriptions
            WHERE appointment_id=?
            AND patient_type='ipd'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i',$appointmentId);
        $stmt->execute();
        $prescriptionResult=$stmt->get_result();
        $latestPrescription=$prescriptionResult->fetch_assoc();
        $stmt->close();
    }
    $diseaseRows=array();
    $stmt=$conn->prepare("
        SELECT
            dd.id,
            dd.disease_id,
            dm.disease_name,
            dm.icd_code
        FROM ipd_discharge_diseases dd
        LEFT JOIN disease_master dm ON dm.id=dd.disease_id
        WHERE dd.discharge_id=?
        ORDER BY dd.id ASC
    ");
    $stmt->bind_param('i',$dischargeId);
    $stmt->execute();
    $diseaseResult=$stmt->get_result();
    while($row=$diseaseResult->fetch_assoc()){
        $diseaseRows[]=$row;
    }
    $stmt->close();
    $medicineRows=array();
    $stmt=$conn->prepare("
        SELECT
            dm.id,
            dm.item_id,
            dm.medicine,
            im.item_name,
            dm.frequency,
            dm.meal,
            dm.qty,
            dm.morning,
            dm.noon,
            dm.evening,
            dm.doctor_id,
            dm.stop_medication,
            dm.stop_date,
            dm.comments,
            dm.pharmacy_status,
            dm.dispensed_qty,
            dm.billing_status
        FROM ipd_discharge_medicines dm
        LEFT JOIN item_master im ON im.id=dm.item_id
        WHERE dm.discharge_id=?
        ORDER BY dm.id ASC
    ");
    $stmt->bind_param('i',$dischargeId);
    $stmt->execute();
    $medicineResult=$stmt->get_result();
    while($row=$medicineResult->fetch_assoc()){
        $medicineRows[]=$row;
    }
    $stmt->close();
    $serviceRows=array();
    $stmt=$conn->prepare("
        SELECT
            ds.id,
            ds.service_id,
            ds.billing_status,
            ds.billing_id,
            sm.category_type,
            sm.service_type,
            sm.service_code,
            sm.service_name,
            sm.amount,
            sm.service_mode
        FROM ipd_discharge_services ds
        LEFT JOIN service_master sm ON sm.id=ds.service_id
        WHERE ds.discharge_id=?
        ORDER BY ds.id ASC
    ");
    $stmt->bind_param('i',$dischargeId);
    $stmt->execute();
    $serviceResult=$stmt->get_result();
    while($row=$serviceResult->fetch_assoc()){
        $serviceRows[]=$row;
    }
    $stmt->close();
    $parameterRows=array();
    $parameterRaw=cleanText($discharge['parameter_readings']??'');
    if($parameterRaw!==''){
        $decoded=json_decode($parameterRaw,true);
        if(is_array($decoded)){
            $parameterRows=flattenParameterData($decoded);
        }
    }
    $dischargeDate=cleanText($discharge['discharge_date']??'');
    $dischargeTime=cleanText($discharge['discharge_time']??'');
    $dischargeDateTime=parseDateTimeValue($dischargeDate,$dischargeTime);
    if($dischargeDateTime===null){
        $dischargeDateTime=date('c');
    }
    $admissionDateTime=parseDateTimeValue(
        $discharge['admission_date_ref']??'',
        $discharge['admission_time_ref']??''
    );
    $patientResourceId='patient-'.$patientId;
    $encounterResourceId='encounter-ipd-'.$admissionId;
    $compositionResourceId='composition-discharge-'.$dischargeId;
    $organizationResourceId='organization-'.$hospitalId;
    $bundleResourceId='discharge-summary-'.$dischargeId;
    $patientName=trim(
        cleanText($patient['title']??'').' '.
        cleanText($patient['first_name']??'').' '.
        cleanText($patient['last_name']??'')
    );
    $patientName=trim(preg_replace('/\s+/u',' ',$patientName));
    if($patientName===''){
        $patientName='Patient';
    }
    $entries=array();
    $doctorReferences=array();
    $doctorResourceIds=array();
    foreach($doctorRows as $doctor){
        $doctorId=(int)($doctor['doctor_id']??0);
        if($doctorId<=0){
            continue;
        }
        $doctorResourceId='practitioner-'.$doctorId;
        $doctorResourceIds[$doctorId]=$doctorResourceId;
        $doctorReferences[]=makeReference('Practitioner',$doctorResourceId);
        $doctorName=cleanText($doctor['full_name']??'');
        if($doctorName===''){
            $doctorName='Doctor';
        }
        $practitioner=array(
            'resourceType'=>'Practitioner',
            'id'=>$doctorResourceId,
            'meta'=>array(
                'profile'=>array(
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Practitioner'
                )
            ),
            'name'=>array(
                array(
                    'text'=>$doctorName
                )
            )
        );
        $licenseNo=cleanText($doctor['license_no']??'');
        $employeeId=cleanText($doctor['employee_id']??'');
        $doctorIdentifierValue='';
        $doctorIdentifierSystem='';
        $doctorIdentifierCode='';
        $doctorIdentifierDisplay='';
        if($licenseNo!==''){
            $doctorIdentifierValue=$licenseNo;
            $doctorIdentifierSystem='https://doctor.ndhm.gov.in';
            $doctorIdentifierCode='MD';
            $doctorIdentifierDisplay='Medical License number';
        }elseif($employeeId!==''){
            $doctorIdentifierValue=$employeeId;
            $doctorIdentifierSystem='https://hms.websplend.com/employee-id';
            $doctorIdentifierCode='OIN';
            $doctorIdentifierDisplay='Other identifier';
        }else{
            $doctorIdentifierValue=(string)$doctorId;
            $doctorIdentifierSystem='https://hms.websplend.com/doctor-id';
            $doctorIdentifierCode='OIN';
            $doctorIdentifierDisplay='Other identifier';
        }
        $practitioner['identifier']=array(
            array(
                'use'=>'official',
                'type'=>array(
                    'coding'=>array(
                        array(
                            'system'=>'http://terminology.hl7.org/CodeSystem/v2-0203',
                            'code'=>$doctorIdentifierCode,
                            'display'=>$doctorIdentifierDisplay
                        )
                    ),
                    'text'=>$doctorIdentifierDisplay
                ),
                'system'=>$doctorIdentifierSystem,
                'value'=>$doctorIdentifierValue,
                'assigner'=>array(
                    'reference'=>'Organization/'.$organizationResourceId,
                    'display'=>cleanText($hospital['hospital_name']??'Hospital')
                )
            )
        );
        $telecom=array();
        $mobile=cleanText($doctor['mobile_no']??'');
        $email=cleanText($doctor['email']??'');
        if($mobile!==''){
            $telecom[]=array(
                'system'=>'phone',
                'value'=>$mobile
            );
        }
        if($email!==''){
            $telecom[]=array(
                'system'=>'email',
                'value'=>$email
            );
        }
        if(count($telecom)>0){
            $practitioner['telecom']=$telecom;
        }
        addBundleEntry($entries,$practitioner);
    }
    $patientResource=array(
        'resourceType'=>'Patient',
        'id'=>$patientResourceId,
        'meta'=>array(
            'profile'=>array(
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Patient'
            )
        ),
        'name'=>array(
            array(
                'text'=>$patientName
            )
        )
    );
    $patientIdentifiers=array();
    $uhid=cleanText($patient['uhid']??'');
    $abhaId=cleanText($patient['abha_id']??'');
    if($uhid!==''){
        $patientIdentifiers[]=array(
            'system'=>'https://hms.websplend.com/uhid',
            'value'=>$uhid
        );
    }
    if($abhaId!==''){
        $patientIdentifiers[]=array(
            'system'=>'https://healthid.ndhm.gov.in',
            'value'=>$abhaId
        );
    }
    if(count($patientIdentifiers)>0){
        $patientResource['identifier']=$patientIdentifiers;
    }
    $mobile=cleanText($patient['mobile']??'');
    if($mobile!==''){
        $patientResource['telecom']=array(
            array(
                'system'=>'phone',
                'value'=>$mobile
            )
        );
    }
    $gender=strtolower(cleanText($patient['sex']??''));
    if($gender==='m'){
        $gender='male';
    }elseif($gender==='f'){
        $gender='female';
    }
    if(in_array($gender,array('male','female','other','unknown'),true)){
        $patientResource['gender']=$gender;
    }
    $dob=cleanText($patient['dob']??'');
    if($dob!==''){
        $dobTimestamp=strtotime($dob);
        if($dobTimestamp!==false){
            $patientResource['birthDate']=date('Y-m-d',$dobTimestamp);
        }
    }
    addBundleEntry($entries,$patientResource);
    $organizationResource=array(
        'resourceType'=>'Organization',
        'id'=>$organizationResourceId,
        'meta'=>array(
            'profile'=>array(
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Organization'
            )
        ),
        'name'=>cleanText($hospital['hospital_name']??'Hospital')
    );
    $hospitalCode=cleanText($hospital['hospital_code']??'');
    if($hospitalCode!==''){
        $organizationResource['identifier']=array(
            array(
                'system'=>'https://hms.websplend.com/hospital-code',
                'value'=>$hospitalCode
            )
        );
    }
    addBundleEntry($entries,$organizationResource);
    $encounterResource=array(
        'resourceType'=>'Encounter',
        'id'=>$encounterResourceId,
        'meta'=>array(
            'profile'=>array(
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Encounter'
            )
        ),
        'status'=>'finished',
        'class'=>array(
            'system'=>'http://terminology.hl7.org/CodeSystem/v3-ActCode',
            'code'=>'IMP',
            'display'=>'Inpatient encounter'
        ),
        'subject'=>makeReference('Patient',$patientResourceId),
        'serviceProvider'=>makeReference('Organization',$organizationResourceId)
    );
    if($ipdNo!==''){
        $encounterResource['identifier']=array(
            array(
                'system'=>'https://hms.websplend.com/ipd-no',
                'value'=>$ipdNo
            )
        );
    }
    if($admissionDateTime!==null || $dischargeDateTime!==null){
        $encounterResource['period']=array();
        if($admissionDateTime!==null){
            $encounterResource['period']['start']=$admissionDateTime;
        }
        if($dischargeDateTime!==null){
            $encounterResource['period']['end']=$dischargeDateTime;
        }
    }
    foreach($doctorReferences as $doctorReference){
        $encounterResource['participant'][]=array(
            'individual'=>$doctorReference
        );
    }
    addBundleEntry($entries,$encounterResource);
    $conditionReferences=array();
    foreach($diseaseRows as $disease){
        $diseaseRowId=(int)($disease['id']??0);
        $diseaseName=cleanText($disease['disease_name']??'');
        $icdCode=cleanText($disease['icd_code']??'');
        if($diseaseRowId<=0 || $diseaseName===''){
            continue;
        }
        $conditionResourceId='condition-discharge-'.$diseaseRowId;
        $conditionResource=array(
            'resourceType'=>'Condition',
            'id'=>$conditionResourceId,
            'meta'=>array(
                'profile'=>array(
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Condition'
                )
            ),
            'verificationStatus'=>array(
                'coding'=>array(
                    array(
                        'system'=>'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                        'code'=>'confirmed',
                        'display'=>'Confirmed'
                    )
                )
            ),
            'subject'=>makeReference('Patient',$patientResourceId),
            'code'=>array(
                'text'=>$diseaseName
            ),
            'recordedDate'=>substr($dischargeDateTime,0,10)
        );
        if($icdCode!==''){
            $conditionResource['code']['coding']=array(
                array(
                    'system'=>'http://hl7.org/fhir/sid/icd-10',
                    'code'=>$icdCode,
                    'display'=>$diseaseName
                )
            );
        }
        $conditionReferences[]=makeReference('Condition',$conditionResourceId);
        addBundleEntry($entries,$conditionResource);
    }
    $chiefComplaint=cleanText($latestPrescription['chief_complaint']??'');
    $finalDiagnosis=cleanText($discharge['final_diagnosis']??'');
    $secondaryDiagnosis=cleanText($discharge['secondary_diagnosis']??'');
    $pastHistory=cleanText($latestPrescription['past_history']??'');
    $presentIllness=cleanText($latestPrescription['present_illness']??'');
    $drugHistory=cleanText($latestPrescription['drug_history']??'');
    $prognosis=cleanText($discharge['prognosis']??$latestPrescription['prognosis']??'');
    $conditionAtDischarge=cleanText($discharge['condition_at_discharge']??'');
    $comorbidity=cleanText($discharge['comorbidity']??'');
    $comments=cleanText($discharge['comments']??'');
    $dietComments=cleanText($discharge['diet_comments']??'');
    $guidelinesComments=cleanText($discharge['guidelines_comments']??'');
    $followUpDetails=cleanText($discharge['follow_up_details']??'');
    $adviceAtDischarge=cleanText($discharge['advice_at_discharge']??'');
    $chiefText=array();
    if($chiefComplaint!==''){
        $chiefText[]='Chief complaint: '.$chiefComplaint;
    }
    if(count($chiefText)>0 || count($conditionReferences)>0){
        $section=array(
            'title'=>'Chief Complaints',
            'code'=>sectionCode('422843007','Chief complaint section'),
            'text'=>narrativeDiv('Chief Complaints',implode("\n",$chiefText))
        );
        if(count($conditionReferences)>0){
            $section['entry']=$conditionReferences;
        }
        $sections[]=$section;
    }
    $diagnosisText=array();
    if($finalDiagnosis!==''){
        $diagnosisText[]='Final diagnosis: '.$finalDiagnosis;
    }
    if($secondaryDiagnosis!==''){
        $diagnosisText[]='Secondary diagnosis: '.$secondaryDiagnosis;
    }
    if($comorbidity!==''){
        $diagnosisText[]='Comorbidity: '.$comorbidity;
    }
    $medicalText=array();
    if(count($diagnosisText)>0){
        $medicalText[]=$diagnosisText;
    }
    if($pastHistory!==''){
        $medicalText[]='Past medical history: '.$pastHistory;
    }
    if($presentIllness!==''){
        $medicalText[]='Present illness: '.$presentIllness;
    }
    if($drugHistory!==''){
        $medicalText[]='Drug history: '.$drugHistory;
    }
    $medicalTextFlat=array();
    foreach($medicalText as $medicalLine){
        if(is_array($medicalLine)){
            foreach($medicalLine as $subLine){
                $medicalTextFlat[]=$subLine;
            }
        }else{
            $medicalTextFlat[]=$medicalLine;
        }
    }
    if(count($medicalTextFlat)>0 || count($conditionReferences)>0){
        $medicalHistorySection=array(
            'title'=>'Medical History',
            'code'=>sectionCode('1003642006','Past medical history section'),
            'text'=>narrativeDiv('Medical History',implode("\n",$medicalTextFlat))
        );
        if(count($conditionReferences)>0){
            $medicalHistorySection['entry']=$conditionReferences;
        }
        $sections[]=$medicalHistorySection;
    }
    $observationReferences=array();
    $physicalText=array();
    $weight=cleanText($discharge['weight']??$latestPrescription['weight']??'');
    $height=cleanText($discharge['height']??$latestPrescription['height']??'');
    $bmi=cleanText($discharge['bmi']??$latestPrescription['bmi']??'');
    $painScale=cleanText($discharge['pain_scale']??$latestPrescription['pain_scale']??'');
    $vitalValues=array();
    if(hasClinicalValue($weight)){
        $vitalValues[]=array(
            'name'=>'Weight',
            'value'=>$weight,
            'unit'=>'kg'
        );
        $physicalText[]='Weight: '.$weight.' kg';
    }
    if(hasClinicalValue($height)){
        $vitalValues[]=array(
            'name'=>'Height',
            'value'=>$height,
            'unit'=>'cm'
        );
        $physicalText[]='Height: '.$height.' cm';
    }
    if(hasClinicalValue($bmi)){
        $vitalValues[]=array(
            'name'=>'BMI',
            'value'=>$bmi,
            'unit'=>'kg/m2'
        );
        $physicalText[]='BMI: '.$bmi;
    }
    if($painScale!==''){
        $vitalValues[]=array(
            'name'=>'Pain scale',
            'value'=>$painScale,
            'unit'=>''
        );
        $physicalText[]='Pain scale: '.$painScale;
    }
    foreach($parameterRows as $parameter){
        $parameterName=cleanText($parameter['name']??'Parameter');
        $parameterValue=cleanText($parameter['value']??'');
        if($parameterValue===''){
            continue;
        }
        $vitalValues[]=array(
            'name'=>$parameterName,
            'value'=>$parameterValue,
            'unit'=>''
        );
        $physicalText[]=$parameterName.': '.$parameterValue;
    }
    $observationIndex=1;
    foreach($vitalValues as $vital){
        $value=cleanText($vital['value']??'');
        if($value===''){
            continue;
        }
        $observationId='observation-discharge-'.$dischargeId.'-'.$observationIndex;
        $observation=array(
            'resourceType'=>'Observation',
            'id'=>$observationId,
            'meta'=>array(
                'profile'=>array(
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Observation'
                )
            ),
            'status'=>'final',
            'code'=>array(
                'text'=>cleanText($vital['name'])
            ),
            'subject'=>makeReference('Patient',$patientResourceId),
            'effectiveDateTime'=>$dischargeDateTime
        );
        $numericValue=str_replace(',','',$value);
        if(is_numeric($numericValue)){
            $observation['valueQuantity']=array(
                'value'=>(float)$numericValue
            );
            if(cleanText($vital['unit']??'')!==''){
                $observation['valueQuantity']['unit']=cleanText($vital['unit']);
            }
        }else{
            $observation['valueString']=$value;
        }
        addBundleEntry($entries,$observation);
        $observationReferences[]=makeReference('Observation',$observationId);
        $observationIndex++;
    }
    if(count($observationReferences)>0){
        $sections[]=array(
            'title'=>'Physical Examination',
            'code'=>sectionCode('425044008','Physical exam section'),
            'text'=>narrativeDiv('Physical Examination',implode("\n",$physicalText)),
            'entry'=>$observationReferences
        );
    }
    $investigationText=array();
    foreach($serviceRows as $service){
        $serviceName=cleanText($service['service_name']??'');
        if($serviceName===''){
            continue;
        }
        $category=cleanText($service['category_type']??'');
        $serviceType=cleanText($service['service_type']??'');
        $line=$serviceName;
        if($category!==''){
            $line.=' ['.$category.']';
        }
        if($serviceType!==''){
            $line.=' ['.$serviceType.']';
        }
        $investigationText[]=$line;
    }
    if(count($investigationText)>0){
        $sections[]=array(
            'title'=>'Investigations',
            'code'=>sectionCode('721981007','Diagnostic studies report'),
            'text'=>narrativeDiv('Investigations',implode("\n",$investigationText))
        );
    }
    $medicationReferences=array();
    $medicationText=array();
    $medicationIndex=1;
    foreach($medicineRows as $medicine){
        $medicineName=cleanText($medicine['medicine']??$medicine['item_name']??'');
        if($medicineName===''){
            continue;
        }
        $medicationId='medication-request-discharge-'.$dischargeId.'-'.$medicationIndex;
        $status='active';
        if((int)($medicine['stop_medication']??0)===1){
            $status='stopped';
        }
        $medicationRequest=array(
            'resourceType'=>'MedicationRequest',
            'id'=>$medicationId,
            'meta'=>array(
                'profile'=>array(
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/MedicationRequest'
                )
            ),
            'status'=>$status,
            'intent'=>'order',
            'medicationCodeableConcept'=>array(
                'text'=>$medicineName
            ),
            'subject'=>makeReference('Patient',$patientResourceId),
            'authoredOn'=>$dischargeDateTime
        );
        $dosageParts=array();
        $frequency=cleanText($medicine['frequency']??'');
        $meal=cleanText($medicine['meal']??'');
        if($frequency!==''){
            $dosageParts[]='Frequency: '.$frequency;
        }
        if($meal!==''){
            $dosageParts[]='Meal: '.$meal;
        }
        $doseSchedule=array();
        $morning=cleanText($medicine['morning']??'');
        $noon=cleanText($medicine['noon']??'');
        $evening=cleanText($medicine['evening']??'');
        if($morning!=='' && hasClinicalValue($morning)){
            $doseSchedule[]='Morning: '.$morning;
        }
        if($noon!=='' && hasClinicalValue($noon)){
            $doseSchedule[]='Noon: '.$noon;
        }
        if($evening!=='' && hasClinicalValue($evening)){
            $doseSchedule[]='Evening: '.$evening;
        }
        if(count($doseSchedule)>0){
            $dosageParts[]=implode(', ',$doseSchedule);
        }
        $medicineComments=cleanText($medicine['comments']??'');
        if($medicineComments!==''){
            $dosageParts[]='Comments: '.$medicineComments;
        }
        if(count($dosageParts)>0){
            $medicationRequest['dosageInstruction']=array(
                array(
                    'text'=>implode(' | ',$dosageParts)
                )
            );
        }
        $qty=cleanText($medicine['qty']??'');
        if(hasClinicalValue($qty)){
            $qtyNumeric=str_replace(',','',$qty);
            if(is_numeric($qtyNumeric)){
                $medicationRequest['dispenseRequest']=array(
                    'quantity'=>array(
                        'value'=>(float)$qtyNumeric
                    )
                );
            }
        }
        $medicineDoctorId=(int)($medicine['doctor_id']??0);
        if($medicineDoctorId>0 && isset($doctorResourceIds[$medicineDoctorId])){
            $medicationRequest['requester']=makeReference('Practitioner',$doctorResourceIds[$medicineDoctorId]);
        }
        addBundleEntry($entries,$medicationRequest);
        $medicationReferences[]=makeReference('MedicationRequest',$medicationId);
        $medicationLine=$medicineName;
        if($frequency!==''){
            $medicationLine.=' — '.$frequency;
        }
        if($meal!==''){
            $medicationLine.=' — '.$meal;
        }
        $medicationText[]=$medicationLine;
        $medicationIndex++;
    }
    if(count($medicationReferences)>0){
        $sections[]=array(
            'title'=>'Medications',
            'code'=>sectionCode('1003606003','Medication history section'),
            'text'=>narrativeDiv('Medications',implode("\n",$medicationText)),
            'entry'=>$medicationReferences
        );
    }
    $carePlanText=array();
    if($conditionAtDischarge!==''){
        $carePlanText[]='Condition at discharge: '.$conditionAtDischarge;
    }
    if($prognosis!==''){
        $carePlanText[]='Prognosis: '.$prognosis;
    }
    if($followUpDetails!==''){
        $carePlanText[]='Follow-up details: '.$followUpDetails;
    }
    $followUpDate=cleanText($discharge['follow_up_date']??'');
    $followUpDays=cleanText($discharge['follow_up_days']??'');
    if($followUpDate!==''){
        $carePlanText[]='Follow-up date: '.$followUpDate;
    }
    if($followUpDays!==''){
        $carePlanText[]='Follow-up after days: '.$followUpDays;
    }
    if($dietComments!==''){
        $carePlanText[]='Diet: '.$dietComments;
    }
    if($guidelinesComments!==''){
        $carePlanText[]='Guidelines: '.$guidelinesComments;
    }
    if($adviceAtDischarge!==''){
        $carePlanText[]='Advice at discharge: '.$adviceAtDischarge;
    }
    if($comments!==''){
        $carePlanText[]='Comments: '.$comments;
    }
    if(count($carePlanText)>0){
        $carePlanId='careplan-discharge-'.$dischargeId;
        $carePlan=array(
            'resourceType'=>'CarePlan',
            'id'=>$carePlanId,
            'status'=>'active',
            'intent'=>'plan',
            'subject'=>makeReference('Patient',$patientResourceId),
            'period'=>array(
                'start'=>$dischargeDateTime
            ),
            'title'=>'Discharge Care Plan',
            'description'=>implode("\n",$carePlanText)
        );
        addBundleEntry($entries,$carePlan);
        $sections[]=array(
            'title'=>'Care Plan',
            'code'=>sectionCode('734163000','Care plan'),
            'text'=>narrativeDiv('Care Plan',implode("\n",$carePlanText)),
            'entry'=>array(
                makeReference('CarePlan',$carePlanId)
            )
        );
    }
    if(count($sections)===0){
        $sections[]=array(
            'title'=>'Discharge Summary',
            'text'=>narrativeDiv(
                'Discharge Summary',
                'Discharge summary record generated from HMS discharge data.'
            )
        );
    }
    if(count($doctorReferences)===0){
        jsonResponse(array(
            'success'=>false,
            'message'=>'No discharge/admission doctor could be resolved for the discharge summary',
            'discharge_id'=>$dischargeId
        ),422);
    }
    $composition=array(
        'resourceType'=>'Composition',
        'id'=>$compositionResourceId,
        'meta'=>array(
            'profile'=>array(
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/DischargeSummaryRecord'
            )
        ),
        'status'=>'final',
        'type'=>array(
            'coding'=>array(
                array(
                    'system'=>'http://snomed.info/sct',
                    'code'=>'373942005',
                    'display'=>'Discharge summary'
                )
            ),
            'text'=>'Discharge summary'
        ),
        'subject'=>makeReference('Patient',$patientResourceId),
        'encounter'=>makeReference('Encounter',$encounterResourceId),
        'date'=>$dischargeDateTime,
        'author'=>$doctorReferences,
        'title'=>'Discharge Summary',
        'custodian'=>makeReference('Organization',$organizationResourceId),
        'section'=>$sections
    );
    addBundleEntry($entries,$composition);
    $bundle=array(
        'resourceType'=>'Bundle',
        'id'=>$bundleResourceId,
        'meta'=>array(
            'profile'=>array(
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/DocumentBundle'
            )
        ),
        'identifier'=>array(
            'system'=>'https://hms.websplend.com/abdm/discharge-summary',
            'value'=>'DISCHARGE-'.$dischargeId
        ),
        'type'=>'document',
        'timestamp'=>$dischargeDateTime,
        'entry'=>$entries
    );
    $fhirJson=json_encode($bundle,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($fhirJson===false){
        throw new RuntimeException('FHIR JSON encoding failed: '.json_last_error_msg());
    }
    jsonResponse(array(
        'success'=>true,
        'message'=>'ABDM DischargeSummary FHIR Bundle generated successfully',
        'source'=>array(
            'discharge_id'=>$dischargeId,
            'admission_id'=>$admissionId,
            'appointment_id'=>$appointmentId,
            'patient_id'=>$patientId,
            'hospital_id'=>$hospitalId,
            'ipd_no'=>$ipdNo
        ),
        'fhir'=>json_decode($fhirJson,true),
        'bundle'=>$bundle
    ));
}catch(Throwable $e){
    jsonResponse(array(
        'success'=>false,
        'message'=>'FHIR Discharge Summary generation failed',
        'error'=>$e->getMessage()
    ),500);
}