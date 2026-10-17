<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Location: /login/login.php');
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$appointment_id = (int)($_GET['id'] ?? 0);

if ($appointment_id <= 0) {
    die('Invalid appointment.');
}

/* =========================
   CHECK USER ACCESS
========================= */

$userChk = $conn->prepare("
    SELECT rights_type_id
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userChk->bind_param("i", $current_user_id);
$userChk->execute();
$userResult = $userChk->get_result();
$currentUser = $userResult->fetch_assoc();
$userChk->close();

if (!$currentUser) {
    die('User account not found.');
}

$rights_type_id = (int)$currentUser['rights_type_id'];

$isSuperAdmin = ($rights_type_id === 1);

$docChk = $conn->prepare("
    SELECT id
    FROM doctor_master
    WHERE user_id = ?
    LIMIT 1
");
$docChk->bind_param("i", $current_user_id);
$docChk->execute();
$docResult = $docChk->get_result();
$isDoctor = $docResult->num_rows > 0;
$docChk->close();

if (!$isSuperAdmin && !$isDoctor) {
    die('Access denied. Only Super Admin or Doctor can start a telemedicine consultation.');
}
/* =========================
   GET APPOINTMENT
========================= */

$stmt = $conn->prepare("
    SELECT
        a.*,
        p.uhid,
        p.first_name AS patient_first_name,
        p.last_name AS patient_last_name,
        p.mobile AS patient_mobile
    FROM appointments a
    LEFT JOIN patient_master p ON p.id = a.patient_id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $appointment_id);
$stmt->execute();
$result = $stmt->get_result();
$appointment = $result->fetch_assoc();
$stmt->close();

if (!$appointment) {
    die('Appointment not found.');
}

/* =========================
   TELEMEDICINE VALIDATION
========================= */

if ($appointment['type'] !== 'doctor') {
    die('This appointment is not a doctor appointment.');
}

if (($appointment['consultation_mode'] ?? 'PHYSICAL') !== 'TELEMEDICINE') {
    die('This appointment is not a telemedicine appointment.');
}

/* =========================
   DOCTOR ACCESS VALIDATION
========================= */
if (!$isSuperAdmin && (int)$appointment['entity_id'] !== $current_user_id) {
    die('Access denied. This consultation is assigned to another doctor.');
}

/* =========================
   GET TELEMEDICINE ROOM
========================= */

$teleStmt = $conn->prepare("
    SELECT *
    FROM telemedicine_consultations
    WHERE appointment_id = ?
    LIMIT 1
");
$teleStmt->bind_param("i", $appointment_id);
$teleStmt->execute();
$teleResult = $teleStmt->get_result();
$telemedicine = $teleResult->fetch_assoc();
$teleStmt->close();

if (!$telemedicine) {
    die('Telemedicine consultation room not found.');
}

$room_name = $telemedicine['room_name'];

$patient_name = trim(
    ($appointment['patient_first_name'] ?? $appointment['first_name'] ?? '') . ' ' .
    ($appointment['patient_last_name'] ?? $appointment['last_name'] ?? '')
);

if ($patient_name === '') {
    $patient_name = 'Patient';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Telemedicine Consultation</title>

<script src="https://meet.jit.si/external_api.js"></script>

<style>
*{
    box-sizing:border-box;
}
html,
body{
    margin:0;
    padding:0;
    width:100%;
    height:100%;
    overflow:hidden;
    font-family:Arial,sans-serif;
    background:#111827;
}
.telemedicine-page{
    width:100%;
    height:100vh;
    display:flex;
    flex-direction:column;
    background:#111827;
}
.top-bar{
    height:64px;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:0 20px;
    background:#ffffff;
    border-bottom:1px solid #e5e7eb;
    z-index:10;
}
.top-left{
    display:flex;
    align-items:center;
    gap:18px;
    min-width:0;
}
.page-title{
    font-size:18px;
    font-weight:700;
    color:#111827;
    white-space:nowrap;
}
.patient-info{
    display:flex;
    align-items:center;
    gap:14px;
    min-width:0;
}
.patient-name{
    font-size:14px;
    font-weight:700;
    color:#1f2937;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:220px;
}
.info-item{
    font-size:13px;
    color:#6b7280;
    white-space:nowrap;
}
.info-item strong{
    color:#374151;
}
.top-right{
    display:flex;
    align-items:center;
    gap:10px;
}
.room-status{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 11px;
    border-radius:6px;
    background:#dcfce7;
    color:#166534;
    font-size:12px;
    font-weight:700;
}
.status-dot{
    width:7px;
    height:7px;
    border-radius:50%;
    background:#16a34a;
}
.end-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:0;
    border-radius:6px;
    padding:9px 14px;
    background:#dc2626;
    color:#ffffff;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    transition:.15s ease;
}
.end-btn:hover{
    background:#b91c1c;
}
.meeting-area{
    position:relative;
    flex:1;
    min-height:0;
    background:#111827;
}
#jitsi-container{
    width:100%;
    height:100%;
}
.hms-logo-overlay{
    position:absolute;
    width:25%;
    height: 80px;
    top:14px;
    left:14px;
    z-index:20;
    display:flex;
    align-items:center;
    gap:8px;
    background:#ffffff;
    padding:6px 10px;
    border-radius:6px;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
    pointer-events:none;
}
.hms-logo-overlay img{
    display:block;
    height:70px;
    width:auto;
}
.hms-logo-overlay span{
    font-size:46px;
    font-weight:700;
    color:#1976d2;
    line-height:1;
}
.loading-overlay{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#111827;
    z-index:5;
    color:#ffffff;
    transition:opacity .25s ease;
}
.loading-box{
    text-align:center;
}
.spinner{
    width:42px;
    height:42px;
    margin:0 auto 16px;
    border:4px solid rgba(255,255,255,.2);
    border-top-color:#ffffff;
    border-radius:50%;
    animation:spin .8s linear infinite;
}
.loading-title{
    font-size:17px;
    font-weight:700;
    margin-bottom:6px;
}
.loading-text{
    font-size:13px;
    color:#cbd5e1;
}
.error-message{
    display:none;
    position:absolute;
    inset:0;
    align-items:center;
    justify-content:center;
    background:#111827;
    color:#ffffff;
    z-index:6;
}
.error-box{
    width:min(420px,90%);
    padding:25px;
    text-align:center;
    background:#1f2937;
    border:1px solid #374151;
    border-radius:10px;
}
.error-box h2{
    margin:0 0 10px;
    font-size:20px;
}
.error-box p{
    margin:0;
    color:#d1d5db;
    font-size:14px;
    line-height:1.5;
}
@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}
@media(max-width:900px){
    .top-bar{
        padding:0 12px;
        gap:10px;
    }
    .page-title{
        display:none;
    }
    .patient-info{
        gap:8px;
    }
    .patient-name{
        max-width:150px;
    }
    .info-item{
        display:none;
    }
    .room-status{
        display:none;
    }
}
@media(max-width:600px){
    .top-bar{
        height:56px;
        min-height:56px;
    }
    .patient-name{
        max-width:130px;
    }
    .end-btn{
        padding:8px 10px;
        font-size:12px;
    }
}
</style>
</head>

<body>

<div class="telemedicine-page">

    <div class="top-bar">

        <div class="top-left">

            <div class="page-title">
                Telemedicine
            </div>

            <div class="patient-info">

                <div class="patient-name">
                    <?= htmlspecialchars($patient_name) ?>
                </div>

                <div class="info-item">
                    <strong>UHID:</strong>
                    <?= htmlspecialchars((string)($appointment['uhid'] ?? '-')) ?>
                </div>

                <div class="info-item">
                    <strong>Appointment:</strong>
                    #<?= (int)$appointment_id ?>
                </div>

            </div>

        </div>

        <div class="top-right">

            <div class="room-status">
                <span class="status-dot"></span>
                Consultation Ready
            </div>

            

        </div>

    </div>

    <div class="meeting-area">

        <div id="jitsi-container"></div>

<div class="hms-logo-overlay">
    <img src="/assets/img/hms_logo.png" alt="WebSplend">
    <span>WebSplend</span>
</div>

        <div class="loading-overlay" id="loading-overlay">

            <div class="loading-box">

                <div class="spinner"></div>

                <div class="loading-title">
                    Starting Consultation
                </div>

                <div class="loading-text">
                    Connecting to the secure video room...
                </div>

            </div>

        </div>

        <div class="error-message" id="error-message">

            <div class="error-box">

                <h2>Unable to Start Consultation</h2>

                <p id="error-text">
                    Unable to connect to the video consultation room.
                </p>

            </div>

        </div>

    </div>

</div>
<script>
let jitsiApi = null;
let consultationStarted = false;

const roomName = <?= json_encode($room_name) ?>;
const patientName = <?= json_encode($patient_name) ?>;

function hideLoading(){
    const loadingOverlay = document.getElementById('loading-overlay');

    if(loadingOverlay){
        loadingOverlay.style.opacity = '0';

        setTimeout(function(){
            loadingOverlay.style.display = 'none';
        }, 250);
    }
}

function showError(message){
    const loadingOverlay = document.getElementById('loading-overlay');
    const errorMessage = document.getElementById('error-message');
    const errorText = document.getElementById('error-text');

    if(loadingOverlay){
        loadingOverlay.style.display = 'none';
    }

    if(errorText){
        errorText.textContent = message;
    }

    if(errorMessage){
        errorMessage.style.display = 'flex';
    }
}

function startJitsi(){

    if(typeof JitsiMeetExternalAPI === 'undefined'){
        showError('Jitsi video service could not be loaded. Please refresh the page and try again.');
        return;
    }

    if(!roomName){
        showError('Telemedicine room name is missing.');
        return;
    }

    try{

        jitsiApi = new JitsiMeetExternalAPI('meet.jit.si', {
            roomName: roomName,
            parentNode: document.querySelector('#jitsi-container'),
            width: '100%',
            height: '100%',
            userInfo: {
                displayName: 'Doctor'
            },
            configOverwrite: {
                prejoinConfig: {
                    enabled: true
                }
            },
            interfaceConfigOverwrite: {
                TOOLBAR_BUTTONS: [
                    'microphone',
                    'camera',
                    'desktop',
                    'fullscreen',
                    'fittowindow',
                    'chat',
                    'raisehand',
                    'tileview',
                    'hangup'
                ],
                SETTINGS_SECTIONS: [
                    'devices',
                    'language'
                ],
                SHOW_JITSI_WATERMARK: false,
                SHOW_WATERMARK_FOR_GUESTS: false,
                MOBILE_APP_PROMO: false
            },
            onload: function(){
                hideLoading();
            }
        });
jitsiApi.addEventListener('videoConferenceJoined', function(event){

    consultationStarted = true;

    hideLoading();

    console.log('Jitsi consultation joined successfully:', event);

});

        jitsiApi.addEventListener('readyToClose', function(){

            consultationStarted = false;

            if(jitsiApi){
                try{
                    jitsiApi.dispose();
                }catch(error){
                    console.error(error);
                }
            }

            window.location.href =
                window.location.pathname +
                '?id=' +
                encodeURIComponent(<?= (int)$appointment_id ?>);

        });

        jitsiApi.addEventListener('errorOccurred', function(event){

    console.error('Jitsi error:', event);

    if(event && event.isFatal){
        showError(
            event.message ||
            'There was a fatal problem connecting to the video consultation.'
        );
    }

});

        jitsiApi.addEventListener('cameraError', function(event){

            console.error('Jitsi camera error:', event);

        });

        jitsiApi.addEventListener('micError', function(event){

            console.error('Jitsi microphone error:', event);

        });

    }catch(error){

        console.error('Jitsi initialization error:', error);

        showError('Unable to start the video consultation.');

    }
}

function endConsultation(){

    const confirmed = confirm(
        'Are you sure you want to end this telemedicine consultation?'
    );

    if(!confirmed){
        return;
    }

    if(jitsiApi){

        try{
            jitsiApi.executeCommand('hangup');
        }catch(error){

            console.error(error);

            try{
                jitsiApi.dispose();
            }catch(disposeError){
                console.error(disposeError);
            }

            window.location.href =
                window.location.pathname +
                '?id=' +
                encodeURIComponent(<?= (int)$appointment_id ?>);
        }

    }else{

        window.location.href =
            window.location.pathname +
            '?id=' +
            encodeURIComponent(<?= (int)$appointment_id ?>);

    }

}

window.addEventListener('load', function(){
    startJitsi();
});
</script>
</body>
</html>