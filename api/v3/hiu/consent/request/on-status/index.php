<?php
error_reporting(0);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
if(session_status()===PHP_SESSION_NONE){
    session_start();
}
$_SESSION['tenant_id']='HMS1';
require_once __DIR__.'/../../../../../../includes/db.php';
function responseJson($success,$message,$data=[],$httpCode=200){
    http_response_code($httpCode);
    echo json_encode([
        'success'=>$success,
        'message'=>$message,
        'data'=>$data
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){
    responseJson(false,'Only POST method is allowed.',[],405);
}
if(!isset($conn)||!($conn instanceof mysqli)){
    responseJson(false,'Tenant database connection is not available.',[],500);
}
$rawBody=file_get_contents('php://input');
$payload=json_decode($rawBody,true);
if(!is_array($payload)){
    responseJson(false,'Invalid JSON request body.',[],400);
}
$requestIdHeader=trim($_SERVER['HTTP_REQUEST_ID']??'');
$timestamp=trim($_SERVER['HTTP_TIMESTAMP']??'');
$hiuId=trim($_SERVER['HTTP_X_HIU_ID']??'');
$authorization=trim($_SERVER['HTTP_AUTHORIZATION']??'');
$consentRequest=[];
if(isset($payload['consentRequest'])&&is_array($payload['consentRequest'])){
    $consentRequest=$payload['consentRequest'];
}
$consentRequestId=trim((string)($consentRequest['id']??''));
$status=trim((string)($consentRequest['status']??''));
$consentArtefacts=[];
if(isset($consentRequest['consentArtefacts'])&&is_array($consentRequest['consentArtefacts'])){
    $consentArtefacts=$consentRequest['consentArtefacts'];
}
$consentArtefactId='';
if(isset($consentArtefacts[0])&&is_array($consentArtefacts[0])){
    $consentArtefactId=trim((string)($consentArtefacts[0]['id']??''));
}
$errorData=$payload['error']??null;
$responseData=[];
if(isset($payload['response'])&&is_array($payload['response'])){
    $responseData=$payload['response'];
}
$callbackRequestId=trim((string)($responseData['requestId']??''));
$respData=$payload['resp']??null;
if($hiuId===''){
    responseJson(false,'X-HIU-ID header is missing.',[],400);
}
if($consentRequestId===''){
    responseJson(false,'Consent Request ID is missing.',[],400);
}
if($status===''){
    responseJson(false,'Consent status is missing.',[],400);
}
$settingsStmt=$conn->prepare("
    SELECT
        id,
        hospital_id,
        environment,
        hiu_id,
        facility_id,
        callback_url,
        status
    FROM abdm_m3_settings
    WHERE hiu_id=?
      AND status='Active'
    ORDER BY id DESC
    LIMIT 1
");
if(!$settingsStmt){
    responseJson(false,'Unable to prepare M3 settings query.',[
        'db_error'=>$conn->error
    ],500);
}
$settingsStmt->bind_param('s',$hiuId);
$settingsStmt->execute();
$settingsResult=$settingsStmt->get_result();
$settings=$settingsResult->fetch_assoc();
$settingsStmt->close();
if(!$settings){
    responseJson(false,'Active M3 settings were not found for this HIU ID.',[
        'hiu_id'=>$hiuId
    ],404);
}
$hospitalId=(int)$settings['hospital_id'];
$headersForLog=[
    'REQUEST-ID'=>$requestIdHeader,
    'TIMESTAMP'=>$timestamp,
    'X-HIU-ID'=>$hiuId,
    'Authorization'=>$authorization!==''?'[REDACTED]':''
];
$headersJson=json_encode($headersForLog,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$payloadJson=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$callbackType='consent_request_on_status';
$callbackStmt=$conn->prepare("
    INSERT INTO abdm_m3_callbacks
    (
        hospital_id,
        callback_type,
        request_id,
        consent_request_id,
        http_method,
        headers,
        payload,
        processing_status,
        processing_message
    )
    VALUES
    (?,?,?,?,'POST',?,?, 'Received',NULL)
");
if(!$callbackStmt){
    responseJson(false,'Unable to prepare callback log query.',[
        'db_error'=>$conn->error
    ],500);
}
$callbackStmt->bind_param(
    'isssss',
    $hospitalId,
    $callbackType,
    $requestIdHeader,
    $consentRequestId,
    $headersJson,
    $payloadJson
);
$callbackSaved=$callbackStmt->execute();
$callbackId=$callbackStmt->insert_id;
if(!$callbackSaved){
    $callbackError=$callbackStmt->error;
    $callbackStmt->close();
    responseJson(false,'Unable to save callback.',[
        'db_error'=>$callbackError
    ],500);
}
$callbackStmt->close();
$matchedConsent=null;
$matchStmt=$conn->prepare("
    SELECT
        id,
        hospital_id,
        patient_id,
        request_id,
        consent_request_id,
        status
    FROM abdm_m3_consent_requests
    WHERE consent_request_id=?
    ORDER BY id DESC
    LIMIT 1
");
if($matchStmt){
    $matchStmt->bind_param('s',$consentRequestId);
    $matchStmt->execute();
    $matchResult=$matchStmt->get_result();
    $matchedConsent=$matchResult->fetch_assoc();
    $matchStmt->close();
}
if($matchedConsent){
    $newStatus=$status;
    $updateStmt=$conn->prepare("
        UPDATE abdm_m3_consent_requests
        SET
            status=?,
            callback_response=?,
            updated_at=CURRENT_TIMESTAMP
        WHERE id=?
    ");
    if(!$updateStmt){
        $callbackStatus='Failed';
        $callbackMessage='Unable to prepare consent request update.';
        $updateCallback=$conn->prepare("
            UPDATE abdm_m3_callbacks
            SET
                processing_status=?,
                processing_message=?,
                processed_at=CURRENT_TIMESTAMP
            WHERE id=?
        ");
        if($updateCallback){
            $updateCallback->bind_param('ssi',$callbackStatus,$callbackMessage,$callbackId);
            $updateCallback->execute();
            $updateCallback->close();
        }
        responseJson(false,'Unable to prepare consent request update.',[
            'callback_id'=>$callbackId,
            'db_error'=>$conn->error
        ],500);
    }
    $updateStmt->bind_param(
        'ssi',
        $newStatus,
        $payloadJson,
        $matchedConsent['id']
    );
    $updateSaved=$updateStmt->execute();
    $updateError=$updateStmt->error;
    $updateStmt->close();
    if(!$updateSaved){
        $callbackStatus='Failed';
        $callbackMessage='Unable to update local consent request status.';
        $updateCallback=$conn->prepare("
            UPDATE abdm_m3_callbacks
            SET
                processing_status=?,
                processing_message=?,
                processed_at=CURRENT_TIMESTAMP
            WHERE id=?
        ");
        if($updateCallback){
            $updateCallback->bind_param('ssi',$callbackStatus,$callbackMessage,$callbackId);
            $updateCallback->execute();
            $updateCallback->close();
        }
        responseJson(false,'Unable to update local consent request.',[
            'callback_id'=>$callbackId,
            'db_error'=>$updateError
        ],500);
    }
    $artifactSaved=false;
    $artifactError='';
    if($consentArtefactId!==''){
        $artifactStmt=$conn->prepare("
            INSERT INTO abdm_m3_consent_artefacts
            (
                hospital_id,
                patient_id,
                consent_request_id,
                consent_artefact_id,
                status,
                consent_artifact,
                fetched_at
            )
            VALUES
            (?,?,?,?,?,NULL,NULL)
            ON DUPLICATE KEY UPDATE
                status=VALUES(status),
                updated_at=CURRENT_TIMESTAMP
        ");
        if($artifactStmt){
            $artifactStatus=$status;
            $artifactStmt->bind_param(
                'iisss',
                $hospitalId,
                $matchedConsent['patient_id'],
                $consentRequestId,
                $consentArtefactId,
                $artifactStatus
            );
            $artifactSaved=$artifactStmt->execute();
            $artifactError=$artifactStmt->error;
            $artifactStmt->close();
        }else{
            $artifactError=$conn->error;
        }
        if(!$artifactSaved){
            $callbackStatus='Failed';
            $callbackMessage='Consent status updated but Consent Artifact ID could not be stored.';
            $updateCallback=$conn->prepare("
                UPDATE abdm_m3_callbacks
                SET
                    processing_status=?,
                    processing_message=?,
                    processed_at=CURRENT_TIMESTAMP
                WHERE id=?
            ");
            if($updateCallback){
                $updateCallback->bind_param('ssi',$callbackStatus,$callbackMessage,$callbackId);
                $updateCallback->execute();
                $updateCallback->close();
            }
            responseJson(false,'Consent status was updated, but Consent Artifact ID could not be stored.',[
                'callback_id'=>$callbackId,
                'local_id'=>(int)$matchedConsent['id'],
                'consent_request_id'=>$consentRequestId,
                'status'=>$status,
                'consent_artefact_id'=>$consentArtefactId,
                'db_error'=>$artifactError
            ],500);
        }
    }
    $callbackStatus='Processed';
    $callbackMessage='Consent status callback processed successfully.';
    if($callbackRequestId!==''){
        $callbackMessage.=' Callback request ID: '.$callbackRequestId.'.';
    }
    if($consentArtefactId!==''){
        $callbackMessage.=' Consent Artifact ID stored: '.$consentArtefactId.'.';
    }
    $updateCallback=$conn->prepare("
        UPDATE abdm_m3_callbacks
        SET
            processing_status=?,
            processing_message=?,
            processed_at=CURRENT_TIMESTAMP
        WHERE id=?
    ");
    if($updateCallback){
        $updateCallback->bind_param('ssi',$callbackStatus,$callbackMessage,$callbackId);
        $updateCallback->execute();
        $updateCallback->close();
    }
    responseJson(true,'ABDM consent status callback processed successfully.',[
        'callback_id'=>$callbackId,
        'local_id'=>(int)$matchedConsent['id'],
        'consent_request_id'=>$consentRequestId,
        'status'=>$status,
        'consent_artefact_id'=>$consentArtefactId,
        'artifact_saved'=>$artifactSaved,
        'callback_request_id'=>$callbackRequestId,
        'error'=>$errorData,
        'resp'=>$respData
    ],202);
}
$callbackStatus='Received';
$callbackMessage='Callback received but matching local consent request was not found.';
$updateCallback=$conn->prepare("
    UPDATE abdm_m3_callbacks
    SET
        processing_status=?,
        processing_message=?,
        processed_at=CURRENT_TIMESTAMP
    WHERE id=?
");
if($updateCallback){
    $updateCallback->bind_param('ssi',$callbackStatus,$callbackMessage,$callbackId);
    $updateCallback->execute();
    $updateCallback->close();
}
responseJson(true,'ABDM consent status callback received.',[
    'callback_id'=>$callbackId,
    'consent_request_id'=>$consentRequestId,
    'status'=>$status,
    'consent_artefact_id'=>$consentArtefactId,
    'callback_request_id'=>$callbackRequestId,
    'error'=>$errorData,
    'resp'=>$respData,
    'matched_local_request'=>false
],202);
?>