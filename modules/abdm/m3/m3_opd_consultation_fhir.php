<?php
declare(strict_types=1);

ini_set('display_errors','1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

function jsonResponse(array $data,int $status=200): void
{
    http_response_code($status);
    echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanText($value): string
{
    return trim((string)($value ?? ''));
}

function htmlText($value): string
{
    return htmlspecialchars(
        cleanText($value),
        ENT_QUOTES|ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function uuid(): string
{
    $data=random_bytes(16);
    $data[6]=chr((ord($data[6])&0x0f)|0x40);
    $data[8]=chr((ord($data[8])&0x3f)|0x80);
    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($data),4)
    );
}

function fullName(?string $first,?string $last): string
{
    return trim(trim((string)$first).' '.trim((string)$last));
}

function parseAppointmentStart(string $date,string $time): string
{
    $time=trim($time);

    $formats=[
        'Y-m-d H:i:s',
        'Y-m-d H:i',
    ];

    foreach($formats as $format){
        $dt=DateTime::createFromFormat($format,$date.' '.$time);
        if($dt instanceof DateTime){
            return $dt->format('Y-m-d\TH:i:sP');
        }
    }

    $timeClean=preg_replace('/\s+/',' ',trim($time));

    $patterns=[
        '/^([0-9]{1,2}:[0-9]{2})\s*(AM|PM)$/i',
        '/^([0-9]{1,2}:[0-9]{2})$/i',
    ];

    foreach($patterns as $pattern){
        if(preg_match($pattern,$timeClean,$m)){
            $value=$m[1];

            if(isset($m[2])){
                $value.=' '.$m[2];
            }

            $dt=DateTime::createFromFormat(
                isset($m[2])?'Y-m-d g:i A':'Y-m-d H:i',
                $date.' '.$value
            );

            if($dt instanceof DateTime){
                return $dt->format('Y-m-d\TH:i:sP');
            }
        }
    }

    return $date.'T00:00:00+05:30';
}

function getEndTime(string $startIso,int $minutes=15): string
{
    try{
        $dt=new DateTime($startIso);
        $dt->modify('+'.$minutes.' minutes');
        return $dt->format('Y-m-d\TH:i:sP');
    }catch(Throwable $e){
        return $startIso;
    }
}

try{

    $prescriptionId=(int)(
        $_GET['prescription_id']
        ?? $_POST['prescription_id']
        ?? 0
    );

    $appointmentId=(int)(
        $_GET['appointment_id']
        ?? $_GET['id']
        ?? $_POST['appointment_id']
        ?? $_POST['id']
        ?? 0
    );

    if($prescriptionId<=0 && $appointmentId<=0){
        jsonResponse([
            'success'=>false,
            'message'=>'Provide prescription_id or appointment_id.'
        ],400);
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD PRESCRIPTION + APPOINTMENT
    |--------------------------------------------------------------------------
    */

    if($prescriptionId>0){

        $stmt=$conn->prepare("
            SELECT
                p.*,
                a.patient_id,
                a.type AS appointment_type,
                a.consultation_mode,
                a.entity_id AS doctor_id,
                a.department_id,
                a.appointment_date,
                a.appointment_time,
                a.opd_no,
                a.ipd_no,
                a.appointment_code,
                a.status AS appointment_status
            FROM opd_prescriptions p
            INNER JOIN appointments a
                ON a.id=p.appointment_id
            WHERE p.id=?
            LIMIT 1
        ");

        if(!$stmt){
            throw new RuntimeException(
                'Prescription query preparation failed: '.$conn->error
            );
        }

        $stmt->bind_param('i',$prescriptionId);

    }else{

        $stmt=$conn->prepare("
            SELECT
                p.*,
                a.patient_id,
                a.type AS appointment_type,
                a.consultation_mode,
                a.entity_id AS doctor_id,
                a.department_id,
                a.appointment_date,
                a.appointment_time,
                a.opd_no,
                a.ipd_no,
                a.appointment_code,
                a.status AS appointment_status
            FROM appointments a
            INNER JOIN opd_prescriptions p
                ON p.appointment_id=a.id
            WHERE a.id=?
            ORDER BY p.id DESC
            LIMIT 1
        ");

        if(!$stmt){
            throw new RuntimeException(
                'Appointment query preparation failed: '.$conn->error
            );
        }

        $stmt->bind_param('i',$appointmentId);
    }

    if(!$stmt->execute()){
        throw new RuntimeException(
            'Unable to execute prescription/appointment query: '.$stmt->error
        );
    }

    $result=$stmt->get_result();
    $clinical=$result->fetch_assoc();
    $stmt->close();

    if(!$clinical){
        jsonResponse([
            'success'=>false,
            'message'=>'No OPD consultation/prescription record found.'
        ],404);
    }

    $prescriptionId=(int)$clinical['id'];
    $appointmentId=(int)$clinical['appointment_id'];
    $patientId=(int)$clinical['patient_id'];
    $doctorId=(int)$clinical['doctor_id'];

    if($patientId<=0){
        jsonResponse([
            'success'=>false,
            'message'=>'Patient ID is missing from appointment.'
        ],422);
    }

    if($doctorId<=0){
        jsonResponse([
            'success'=>false,
            'message'=>'Doctor ID is missing from appointment.'
        ],422);
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD PATIENT
    |--------------------------------------------------------------------------
    */

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

    if(!$stmt){
        throw new RuntimeException(
            'Patient query preparation failed: '.$conn->error
        );
    }

    $stmt->bind_param('i',$patientId);
    $stmt->execute();

    $patientResult=$stmt->get_result();
    $patient=$patientResult->fetch_assoc();

    $stmt->close();

    if(!$patient){
        jsonResponse([
            'success'=>false,
            'message'=>'Patient record not found.'
        ],404);
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD DOCTOR + USER
    |--------------------------------------------------------------------------
    */

    $stmt=$conn->prepare("
        SELECT
            d.id AS doctor_id,
            d.user_id,
            d.license_no,
            u.full_name,
            u.gender,
            u.dob
        FROM doctor_master d
        INNER JOIN users u
            ON u.id=d.user_id
        WHERE d.user_id=?
LIMIT 1
    ");

    if(!$stmt){
        throw new RuntimeException(
            'Doctor query preparation failed: '.$conn->error
        );
    }

    $stmt->bind_param('i',$doctorId);
    $stmt->execute();

    $doctorResult=$stmt->get_result();
    $doctor=$doctorResult->fetch_assoc();

    $stmt->close();

    if(!$doctor){
        jsonResponse([
            'success'=>false,
            'message'=>'Doctor record not found.'
        ],404);
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD HOSPITAL
    |--------------------------------------------------------------------------
    */

    $hospital=null;

    $stmt=$conn->prepare("
        SELECT
            id,
            hospital_code,
            hospital_name
        FROM hospital_master
        WHERE id=(
            SELECT hospital_id
            FROM abdm_m3_settings
            WHERE environment='Sandbox'
              AND status='Active'
            ORDER BY id DESC
            LIMIT 1
        )
        LIMIT 1
    ");

    if($stmt){

        if($stmt->execute()){

            $hospitalResult=$stmt->get_result();
            $hospital=$hospitalResult->fetch_assoc();

        }

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

        if($stmt){

            $stmt->execute();

            $hospitalResult=$stmt->get_result();
            $hospital=$hospitalResult->fetch_assoc();

            $stmt->close();
        }
    }

    if(!$hospital){
        jsonResponse([
            'success'=>false,
            'message'=>'Hospital record could not be resolved.'
        ],404);
    }

    $hospitalId=(int)$hospital['id'];

    /*
    |--------------------------------------------------------------------------
    | FHIR RESOURCE IDS
    |--------------------------------------------------------------------------
    */

    $patientFhirId='patient-'.$patientId;
    $doctorFhirId='practitioner-'.$doctorId;
    $organizationFhirId='organization-'.$hospitalId;
    $encounterFhirId='encounter-'.$appointmentId;
    $compositionFhirId='composition-'.$appointmentId;

    /*
    |--------------------------------------------------------------------------
    | ENCOUNTER TIME
    |--------------------------------------------------------------------------
    */

    $encounterStart=parseAppointmentStart(
        cleanText($clinical['appointment_date']),
        cleanText($clinical['appointment_time'])
    );

    $encounterEnd=getEndTime($encounterStart,15);

    /*
    |--------------------------------------------------------------------------
    | LOAD DISEASES
    |--------------------------------------------------------------------------
    */

    $conditions=[];
    $conditionReferences=[];

    $selectedDiseases=cleanText($clinical['selected_diseases']);

    if($selectedDiseases!==''){

        $diseaseIds=json_decode($selectedDiseases,true);

        if(is_array($diseaseIds)){

            $diseaseIds=array_values(
                array_filter(
                    array_map('intval',$diseaseIds),
                    fn($id)=>$id>0
                )
            );

            foreach($diseaseIds as $diseaseId){

                $stmt=$conn->prepare("
                    SELECT
                        id,
                        disease_name,
                        icd_code
                    FROM disease_master
                    WHERE id=?
                    LIMIT 1
                ");

                if(!$stmt){
                    continue;
                }

                $stmt->bind_param('i',$diseaseId);
                $stmt->execute();

                $diseaseResult=$stmt->get_result();
                $disease=$diseaseResult->fetch_assoc();

                $stmt->close();

                if(!$disease){
                    continue;
                }

                $conditionId='condition-'.$diseaseId;

                $condition=[
                    'resourceType'=>'Condition',
                    'id'=>$conditionId,
                    'meta'=>[
                        'profile'=>[
                            'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Condition'
                        ]
                    ],
                    'clinicalStatus'=>[
                        'coding'=>[
                            [
                                'system'=>'http://terminology.hl7.org/CodeSystem/condition-clinical',
                                'code'=>'active',
                                'display'=>'Active'
                            ]
                        ]
                    ],
                    'subject'=>[
                        'reference'=>'urn:uuid:'.$patientFhirId
                    ],
                    'encounter'=>[
                        'reference'=>'urn:uuid:'.$encounterFhirId
                    ],
                    'code'=>[
                        'text'=>cleanText($disease['disease_name'])
                    ]
                ];

                if(cleanText($disease['icd_code'])!==''){
                    $condition['code']['coding']=[
                        [
                            'system'=>'http://hl7.org/fhir/sid/icd-10',
                            'code'=>cleanText($disease['icd_code']),
                            'display'=>cleanText($disease['disease_name'])
                        ]
                    ];
                }

                $conditions[]=[
                    'fullUrl'=>'urn:uuid:'.$conditionId,
                    'resource'=>$condition
                ];

                $conditionReferences[]=[
                    'reference'=>'urn:uuid:'.$conditionId
                ];
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD PARAMETER READINGS
    |--------------------------------------------------------------------------
    */

    $observations=[];

    $stmt=$conn->prepare("
        SELECT
            pr.id,
            pr.parameter_id,
            pr.reading,
            pr.reading_date,
            pr.reading_time,
            pm.parameter_name,
            pm.unit
        FROM parameter_readings pr
        INNER JOIN parameter_master pm
            ON pm.id=pr.parameter_id
        WHERE pr.prescription_id=?
        ORDER BY pr.id ASC
    ");

    if($stmt){

        $stmt->bind_param('i',$prescriptionId);
        $stmt->execute();

        $parameterResult=$stmt->get_result();

        while($parameter=$parameterResult->fetch_assoc()){

            $observationId='observation-parameter-'.$parameter['id'];

            $observation=[
                'resourceType'=>'Observation',
                'id'=>$observationId,
                'meta'=>[
                    'profile'=>[
                        'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Observation'
                    ]
                ],
                'status'=>'final',
                'code'=>[
                    'text'=>cleanText($parameter['parameter_name'])
                ],
                'subject'=>[
                    'reference'=>'urn:uuid:'.$patientFhirId
                ],
                'encounter'=>[
                    'reference'=>'urn:uuid:'.$encounterFhirId
                ],
                'valueString'=>cleanText($parameter['reading'])
            ];

            if(cleanText($parameter['unit'])!==''){
                $observation['valueQuantity']=[
                    'value'=>is_numeric($parameter['reading'])
                        ? (float)$parameter['reading']
                        : cleanText($parameter['reading']),
                    'unit'=>cleanText($parameter['unit'])
                ];

                unset($observation['valueString']);
            }

            $observations[]=[
                'fullUrl'=>'urn:uuid:'.$observationId,
                'resource'=>$observation
            ];
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | STANDARD VITAL OBSERVATIONS
    |--------------------------------------------------------------------------
    */

    $vitalDefinitions=[
        [
            'field'=>'weight',
            'id'=>'observation-weight',
            'code'=>'Weight',
            'unit'=>'kg'
        ],
        [
            'field'=>'height',
            'id'=>'observation-height',
            'code'=>'Height',
            'unit'=>'cm'
        ],
        [
            'field'=>'bmi',
            'id'=>'observation-bmi',
            'code'=>'BMI',
            'unit'=>null
        ],
        [
            'field'=>'pain_scale',
            'id'=>'observation-pain',
            'code'=>'Pain scale',
            'unit'=>null
        ]
    ];

    $vitalReferences=[];
    $vitalText=[];

    foreach($vitalDefinitions as $vital){

    $value=$clinical[$vital['field']] ?? null;

    if($value===null || $value===''){
        continue;
    }

    if(is_numeric($value) && (float)$value<=0){
        continue;
    }

    $observation=[
        'resourceType'=>'Observation',
        'id'=>$vital['id'],
        'meta'=>[
            'profile'=>[
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Observation'
            ]
        ],
        'status'=>'final',
        'code'=>[
            'text'=>$vital['code']
        ],
        'subject'=>[
            'reference'=>'urn:uuid:'.$patientFhirId
        ],
        'encounter'=>[
            'reference'=>'urn:uuid:'.$encounterFhirId
        ],
        'valueQuantity'=>[
            'value'=>(float)$value
        ]
    ];

        if($vital['unit']!==null){
            $observation['valueQuantity']['unit']=$vital['unit'];
        }

        $observations[]=[
            'fullUrl'=>'urn:uuid:'.$vital['id'],
            'resource'=>$observation
        ];

        $vitalReferences[]=[
            'reference'=>'urn:uuid:'.$vital['id']
        ];

        $vitalText[]=$vital['code'].' '.$value.($vital['unit']?' '.$vital['unit']:'');
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD PRESCRIBED MEDICINES
    |--------------------------------------------------------------------------
    */

    $medications=[];
    $medicationReferences=[];
    $medicationText=[];

    $stmt=$conn->prepare("
        SELECT
            pm.id,
            pm.item_id,
            pm.frequency,
            pm.meal,
            pm.qty,
            pm.morning,
            pm.noon,
            pm.evening,
            pm.stop_medication,
            pm.stop_date,
            pm.comments,
            im.item_name
        FROM prescribed_medicines pm
        INNER JOIN item_master im
            ON im.id=pm.item_id
        WHERE pm.prescription_id=?
        ORDER BY pm.id ASC
    ");

    if($stmt){

        $stmt->bind_param('i',$prescriptionId);
        $stmt->execute();

        $medicineResult=$stmt->get_result();

        while($medicine=$medicineResult->fetch_assoc()){

            $medicationId='medication-request-'.$medicine['id'];

            $dosageText=trim(
                cleanText($medicine['frequency'])
                .(
                    cleanText($medicine['meal'])!==''
                    ? ', '.cleanText($medicine['meal'])
                    : ''
                )
                .'; morning '.cleanText($medicine['morning'])
                .', noon '.cleanText($medicine['noon'])
                .', evening '.cleanText($medicine['evening'])
            );

            $medication=[
                'resourceType'=>'MedicationRequest',
                'id'=>$medicationId,
                'meta'=>[
                    'profile'=>[
                        'https://nrces.in/ndhm/fhir/r4/StructureDefinition/MedicationRequest'
                    ]
                ],
                'status'=>((int)$medicine['stop_medication']===1?'stopped':'active'),
                'intent'=>'order',
                'medicationCodeableConcept'=>[
                    'text'=>cleanText($medicine['item_name'])
                ],
                'subject'=>[
                    'reference'=>'urn:uuid:'.$patientFhirId
                ],
                'encounter'=>[
                    'reference'=>'urn:uuid:'.$encounterFhirId
                ],
                'authoredOn'=>$encounterStart,
                'requester'=>[
                    'reference'=>'urn:uuid:'.$doctorFhirId
                ],
                'dosageInstruction'=>[
                    [
                        'text'=>$dosageText,
                        'timing'=>[
                            'code'=>[
                                'text'=>cleanText($medicine['frequency'])
                            ]
                        ]
                    ]
                ],
                'dispenseRequest'=>[
                    'quantity'=>[
                        'value'=>(float)$medicine['qty']
                    ]
                ]
            ];

            if(cleanText($medicine['meal'])!==''){
                $medication['dosageInstruction'][0]['additionalInstruction']=[
                    [
                        'text'=>cleanText($medicine['meal'])
                    ]
                ];
            }

            if(cleanText($medicine['comments'])!==''){
                $medication['note']=[
                    [
                        'text'=>cleanText($medicine['comments'])
                    ]
                ];
            }

            $medications[]=[
                'fullUrl'=>'urn:uuid:'.$medicationId,
                'resource'=>$medication
            ];

            $medicationReferences[]=[
                'reference'=>'urn:uuid:'.$medicationId
            ];

            $medicationText[]=
                cleanText($medicine['item_name'])
                .'; '
                .$dosageText
                .'; quantity '
                .cleanText($medicine['qty']);
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD PRESCRIBED SERVICES
    |--------------------------------------------------------------------------
    */

    $services=[];
    $serviceReferences=[];
    $serviceText=[];

    $stmt=$conn->prepare("
        SELECT
            ps.id,
            ps.service_id,
            sm.service_code,
            sm.service_name
        FROM prescribed_services ps
        INNER JOIN service_master sm
            ON sm.id=ps.service_id
        WHERE ps.prescription_id=?
        ORDER BY ps.id ASC
    ");

    if($stmt){

        $stmt->bind_param('i',$prescriptionId);
        $stmt->execute();

        $serviceResult=$stmt->get_result();

        while($service=$serviceResult->fetch_assoc()){

            $serviceId='service-request-'.$service['id'];

            $display=cleanText($service['service_name']);

            if(cleanText($service['service_code'])!==''){
                $display.=' ('.cleanText($service['service_code']).')';
            }

            $serviceRequest=[
                'resourceType'=>'ServiceRequest',
                'id'=>$serviceId,
                'meta'=>[
                    'profile'=>[
                        'https://nrces.in/ndhm/fhir/r4/StructureDefinition/ServiceRequest'
                    ]
                ],
                'status'=>'active',
                'intent'=>'order',
                'code'=>[
                    'text'=>$display
                ],
                'subject'=>[
                    'reference'=>'urn:uuid:'.$patientFhirId
                ],
                'encounter'=>[
                    'reference'=>'urn:uuid:'.$encounterFhirId
                ],
                'requester'=>[
                    'reference'=>'urn:uuid:'.$doctorFhirId
                ]
            ];

            $services[]=[
                'fullUrl'=>'urn:uuid:'.$serviceId,
                'resource'=>$serviceRequest
            ];

            $serviceReferences[]=[
                'reference'=>'urn:uuid:'.$serviceId
            ];

            $serviceText[]=$display;
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | COMPOSITION SECTIONS
    |--------------------------------------------------------------------------
    */

    $sections=[];

    if(cleanText($clinical['chief_complaint'])!=='' || !empty($conditionReferences)){

        $complaintText=cleanText($clinical['chief_complaint']);

        if($complaintText===''){
            $complaintText='Clinical consultation';
        }

        $section=[
            'title'=>'Chief Complaints',
            'code'=>[
                'coding'=>[
                    [
                        'system'=>'http://snomed.info/sct',
                        'code'=>'422843007',
                        'display'=>'Chief complaint'
                    ]
                ]
            ],
            'text'=>[
                'status'=>'generated',
                'div'=>'<div xmlns="http://www.w3.org/1999/xhtml">'
                    .htmlText($complaintText)
                    .'</div>'
            ]
        ];

        if(!empty($conditionReferences)){
            $section['entry']=$conditionReferences;
        }

        $sections[]=$section;
    }

    if(!empty($vitalReferences) || !empty($observations)){

        $physicalText='';

        if(!empty($vitalText)){
            $physicalText=implode('; ',$vitalText);
        }else{
            $physicalText='Clinical observations';
        }

        $references=$vitalReferences;

        foreach($observations as $observation){
            $id=$observation['resource']['id'];

            if(str_starts_with($id,'observation-parameter-')){
                $references[]=[
    'reference'=>$observation['fullUrl']
];
            }
        }

        $sections[]=[
            'title'=>'Physical Examination',
            'code'=>[
                'coding'=>[
                    [
                        'system'=>'http://snomed.info/sct',
                        'code'=>'425044008',
                        'display'=>'Physical examination'
                    ]
                ]
            ],
            'text'=>[
                'status'=>'generated',
                'div'=>'<div xmlns="http://www.w3.org/1999/xhtml">'
                    .htmlText($physicalText)
                    .'</div>'
            ],
            'entry'=>$references
        ];
    }

    if(!empty($serviceReferences)){

        $sections[]=[
            'title'=>'Investigation Advice',
            'code'=>[
                'coding'=>[
                    [
                        'system'=>'http://snomed.info/sct',
                        'code'=>'721963009',
                        'display'=>'Diagnostic procedure'
                    ]
                ]
            ],
            'text'=>[
                'status'=>'generated',
                'div'=>'<div xmlns="http://www.w3.org/1999/xhtml">'
                    .htmlText(implode('; ',$serviceText))
                    .'</div>'
            ],
            'entry'=>$serviceReferences
        ];
    }

    if(!empty($medicationReferences)){

        $sections[]=[
            'title'=>'Medications',
            'code'=>[
                'coding'=>[
                    [
                        'system'=>'http://snomed.info/sct',
                        'code'=>'721912009',
                        'display'=>'Medication management'
                    ]
                ]
            ],
            'text'=>[
                'status'=>'generated',
                'div'=>'<div xmlns="http://www.w3.org/1999/xhtml">'
                    .htmlText(implode('; ',$medicationText))
                    .'</div>'
            ],
            'entry'=>$medicationReferences
        ];
    }

    if(
        cleanText($clinical['advice'])!==''
        ||
        cleanText($clinical['prognosis'])!==''
        ||
        cleanText($clinical['remarks'])!==''
    ){

        $adviceParts=[];

        if(cleanText($clinical['advice'])!==''){
            $adviceParts[]='Advice: '.cleanText($clinical['advice']);
        }

        if(cleanText($clinical['prognosis'])!==''){
            $adviceParts[]='Prognosis: '.cleanText($clinical['prognosis']);
        }

        if(cleanText($clinical['remarks'])!==''){
            $adviceParts[]='Remarks: '.cleanText($clinical['remarks']);
        }

        $sections[]=[
            'title'=>'Advice',
            'text'=>[
                'status'=>'generated',
                'div'=>'<div xmlns="http://www.w3.org/1999/xhtml">'
                    .htmlText(implode('; ',$adviceParts))
                    .'</div>'
            ]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | COMPOSITION
    |--------------------------------------------------------------------------
    */

    $composition=[
        'resourceType'=>'Composition',
        'id'=>$compositionFhirId,
        'meta'=>[
            'profile'=>[
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/OPConsultRecord'
            ]
        ],
        'language'=>'en-IN',
        'status'=>'final',
        'type'=>[
            'coding'=>[
                [
                    'system'=>'http://snomed.info/sct',
                    'code'=>'371530004',
                    'display'=>'Clinical consultation report'
                ]
            ]
        ],
        'subject'=>[
            'reference'=>'urn:uuid:'.$patientFhirId
        ],
        'encounter'=>[
            'reference'=>'urn:uuid:'.$encounterFhirId
        ],
        'date'=>$encounterEnd,
        'author'=>[
            [
                'reference'=>'urn:uuid:'.$doctorFhirId
            ]
        ],
        'title'=>'OP Consultation Record',
        'section'=>$sections
    ];

    /*
    |--------------------------------------------------------------------------
    | PATIENT
    |--------------------------------------------------------------------------
    */

    $patientName=fullName(
        $patient['first_name'] ?? '',
        $patient['last_name'] ?? ''
    );

    $patientResource=[
        'resourceType'=>'Patient',
        'id'=>$patientFhirId,
        'meta'=>[
            'profile'=>[
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Patient'
            ]
        ],
        'identifier'=>[
            [
                'use'=>'usual',
                'value'=>(string)$patient['uhid']
            ]
        ],
        'name'=>[
            [
                'use'=>'official',
                'text'=>$patientName,
                'family'=>cleanText($patient['last_name']),
                'given'=>[
                    cleanText($patient['first_name'])
                ]
            ]
        ]
    ];

    $gender=strtolower(cleanText($patient['sex']));

    if(in_array($gender,['male','female','other','unknown'],true)){
        $patientResource['gender']=$gender;
    }

    if(!empty($patient['dob'])){
        $patientResource['birthDate']=$patient['dob'];
    }

    /*
    |--------------------------------------------------------------------------
    | PRACTITIONER
    |--------------------------------------------------------------------------
    */

    $doctorName=cleanText($doctor['full_name']);

    $practitioner=[
        'resourceType'=>'Practitioner',
        'id'=>$doctorFhirId,
        'meta'=>[
            'profile'=>[
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Practitioner'
            ]
        ],
        'identifier'=>[
            [
                'use'=>'official',
                'value'=>cleanText($doctor['license_no'])
            ]
        ],
        'name'=>[
            [
                'use'=>'official',
                'text'=>$doctorName,
                'family'=>$doctorName,
                'given'=>[
                    $doctorName
                ]
            ]
        ]
    ];

    /*
    |--------------------------------------------------------------------------
    | ORGANIZATION
    |--------------------------------------------------------------------------
    */

    $organization=[
        'resourceType'=>'Organization',
        'id'=>$organizationFhirId,
        'meta'=>[
            'profile'=>[
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Organization'
            ]
        ],
        'identifier'=>[
            [
                'use'=>'official',
                'value'=>cleanText($hospital['hospital_code'])
            ]
        ],
        'name'=>cleanText($hospital['hospital_name'])
    ];

    /*
    |--------------------------------------------------------------------------
    | ENCOUNTER
    |--------------------------------------------------------------------------
    */

    $encounter=[
        'resourceType'=>'Encounter',
        'id'=>$encounterFhirId,
        'meta'=>[
            'profile'=>[
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Encounter'
            ]
        ],
        'status'=>'finished',
        'class'=>[
            'code'=>(
                cleanText($clinical['consultation_mode'])==='TELEMEDICINE'
                ? 'VR'
                : 'AMB'
            ),
            'display'=>(
                cleanText($clinical['consultation_mode'])==='TELEMEDICINE'
                ? 'Virtual'
                : 'Ambulatory'
            ),
            'system'=>'http://terminology.hl7.org/CodeSystem/v3-ActCode'
        ],
        'subject'=>[
            'reference'=>'urn:uuid:'.$patientFhirId
        ],
        'participant'=>[
            [
                'individual'=>[
                    'reference'=>'urn:uuid:'.$doctorFhirId
                ]
            ]
        ],
        'period'=>[
            'start'=>$encounterStart,
            'end'=>$encounterEnd
        ],
        'serviceProvider'=>[
            'reference'=>'urn:uuid:'.$organizationFhirId
        ],
        'identifier'=>[
            [
                'value'=>cleanText($clinical['opd_no'])
            ]
        ]
    ];

    /*
    |--------------------------------------------------------------------------
    | BUNDLE
    |--------------------------------------------------------------------------
    */

    $bundleId='opconsult-'.$appointmentId.'-'.$prescriptionId;

    $entries=[];

    $entries[]=[
        'fullUrl'=>'urn:uuid:'.$compositionFhirId,
        'resource'=>$composition
    ];

    $entries[]=[
        'fullUrl'=>'urn:uuid:'.$patientFhirId,
        'resource'=>$patientResource
    ];

    $entries[]=[
        'fullUrl'=>'urn:uuid:'.$doctorFhirId,
        'resource'=>$practitioner
    ];

    $entries[]=[
        'fullUrl'=>'urn:uuid:'.$organizationFhirId,
        'resource'=>$organization
    ];

    $entries[]=[
        'fullUrl'=>'urn:uuid:'.$encounterFhirId,
        'resource'=>$encounter
    ];

    foreach($conditions as $condition){
        $entries[]=$condition;
    }

    foreach($observations as $observation){
        $entries[]=$observation;
    }

    foreach($medications as $medication){
        $entries[]=$medication;
    }

    foreach($services as $service){
        $entries[]=$service;
    }

    $bundle=[
        'resourceType'=>'Bundle',
        'id'=>$bundleId,
        'meta'=>[
            'profile'=>[
                'https://nrces.in/ndhm/fhir/r4/StructureDefinition/DocumentBundle'
            ]
        ],
        'identifier'=>[
            'system'=>'urn:ietf:rfc:3986',
            'value'=>'urn:uuid:'.$bundleId
        ],
        'type'=>'document',
        'timestamp'=>$encounterEnd,
        'entry'=>$entries
    ];

    /*
    |--------------------------------------------------------------------------
    | VALIDATE JSON
    |--------------------------------------------------------------------------
    */

    $json=json_encode(
        $bundle,
        JSON_PRETTY_PRINT|
        JSON_UNESCAPED_SLASHES|
        JSON_UNESCAPED_UNICODE
    );

    if($json===false){
        throw new RuntimeException(
            'FHIR JSON encoding failed: '.json_last_error_msg()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    jsonResponse([
        'success'=>true,
        'message'=>'Dynamic OP Consultation FHIR Bundle generated successfully.',
        'source'=>[
            'prescription_id'=>$prescriptionId,
            'appointment_id'=>$appointmentId,
            'patient_id'=>$patientId,
            'doctor_id'=>$doctorId,
            'hospital_id'=>$hospitalId
        ],
        'fhir'=>[
            'resourceType'=>$bundle['resourceType'],
            'bundleType'=>$bundle['type'],
            'bundleId'=>$bundle['id'],
            'entryCount'=>count($bundle['entry'])
        ],
        'bundle'=>$bundle
    ]);

}catch(Throwable $e){

    jsonResponse([
        'success'=>false,
        'message'=>'Unable to generate dynamic OP Consultation FHIR Bundle.',
        'error'=>$e->getMessage()
    ],500);
}