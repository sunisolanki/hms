<?php
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$type        = $_GET['type'] ?? '';
$entity_id   = $_GET['entity_id'] ?? '';
$date        = $_GET['date'] ?? '';
$slot        = $_GET['slot'] ?? '';
$isFromSlot = (!empty($type) && !empty($entity_id) && !empty($date) && !empty($slot));
?>
<?php
session_start();
$source = 'INTERNAL';
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/abdm_helper.php';
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
/*
 * GLOBAL ABDM SWITCH
 *
 * Read once, server-side. When ABDM is disabled for this installation - or when
 * the setting cannot be read, in which case the switch fails closed - the
 * patient-level ABDM Yes/No card, the ABHA field, the ABDM verification buttons,
 * the ABHA action buttons and the ABDM modals are not rendered at all.
 *
 * Ordinary registration is completely unaffected: the normal Aadhaar field and
 * every unrelated registration field stay visible, and Aadhaar and ABHA remain
 * optional. The separate server-side enforcement lives in the ABDM endpoints.
 */
$abdmGlobalEnabled = abdmIsAbdmGloballyEnabled($conn);
/* VIEW PERMISSION */
if (!checkPermission('patient_registration','view')) {
    header('Location: /access_denied.php');
    exit;
}
/* =========================
   Revisit
========================= */
$revisit_patient_id = (int)($_GET['revisit_patient_id'] ?? 0);
$revisitPatient = [];
if($revisit_patient_id > 0){
    $q = mysqli_query($conn,"
        SELECT *
        FROM patient_master
        WHERE id = $revisit_patient_id
        LIMIT 1
    ");
    if(mysqli_num_rows($q)){
        $revisitPatient = mysqli_fetch_assoc($q);
    }
}
/* =========================
   GET APPOINTMENT DATA
========================= */
$appointment_id = $_GET['appointment_id'] ?? '';
$appt = null;
$appointmentPaid = 0;
$appointmentPaymentReceived = false;
$consultation_mode = 'PHYSICAL';
if ($appointment_id) {
    $appointment_id = (int)$appointment_id;
    $q = mysqli_query($conn,"
        SELECT *
        FROM appointments
        WHERE id = $appointment_id
        LIMIT 1
    ");
    if(mysqli_num_rows($q)){
    $appt = mysqli_fetch_assoc($q);
    $appointmentPaid = (float)($appt['paid_amount'] ?? 0);
    $appointmentPaymentReceived = ($appointmentPaid > 0);
    // 🔥 extract appointment data
    $type       = $appt['type'];
$entity_id  = $appt['entity_id'];
$date       = $appt['appointment_date'];
$slot = preg_replace('/:00/', '', $appt['appointment_time']);
$consultation_mode = $appt['consultation_mode'] ?? 'PHYSICAL';
    $isFromSlot = (
    !empty($type) &&
    !empty($entity_id) &&
    !empty($date) &&
    !empty($slot)
);
}
}
$departmentName = '';
$entityName     = '';
$feeAmount = ($appointmentPaid > 0) ? 0 : '';
$cities = [];
$cityQ = mysqli_query($conn, "SELECT id, name FROM cities ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($cityQ)) {
    $cities[] = $row;
}
/* =========================
   LOAD ACTIVE EMPANELMENTS
========================= */
$empanelments = [];
$empQ = mysqli_query($conn, "
    SELECT id, empanelment_name 
    FROM empanelment_master 
    WHERE status = 1
    ORDER BY empanelment_name ASC
");
while ($row = mysqli_fetch_assoc($empQ)) {
    $empanelments[] = $row;
}
/* =========================
   LOAD ACTIVE DISCOUNTS
========================= */
$discounts = [];
$disQ = mysqli_query($conn, "
    SELECT id, discount_name
    FROM discount_master
    WHERE status = 'Active'
    ORDER BY discount_name ASC
");
while ($row = mysqli_fetch_assoc($disQ)) {
    $discounts[] = $row;
}
/* =========================
   DOCTOR FROM SLOT
========================= */
if ($isFromSlot && $type === 'doctor' && $entity_id) {
    $stmt = $conn->prepare("
        SELECT 
            u.full_name,
            dm.id AS department_id,
            dm.department_name
        FROM users u
        INNER JOIN user_departments ud ON ud.user_id = u.id
        INNER JOIN department_master dm ON dm.id = ud.department_id
        WHERE u.id = ?
          AND u.employee_type_id = 2
        LIMIT 1
    ");
    $stmt->bind_param("i", $entity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $entityName     = $row['full_name'];
        $departmentId = $row['department_id'];
        $departmentName = $row['department_name'];
    }
    $stmt->close();
    // fee
    $stmt = $conn->prepare("
        SELECT charge_amount
        FROM doctor_charges
        WHERE doctor_id = ?
          AND visit_type = 'NORMAL'
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $entity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
    // Only load fee if appointment was NOT paid
    if ($appointmentPaid <= 0) {
        $feeAmount = $row['charge_amount'];
    }
}
    $stmt->close();
}
/* =========================
   SERVICE FROM SLOT (FIXED)
========================= */
if ($isFromSlot && $type === 'service' && $entity_id) {
    $stmt = $conn->prepare("
        SELECT
            service_name,
            category_type,
            amount
        FROM service_master
        WHERE id = ?
          AND status = 'Active'
        LIMIT 1
    ");
    $stmt->bind_param("i", $entity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $entityName      = $row['service_name'];
        $departmentName  = $row['category_type']; // ENUM value
        $departmentId    = 0;                     // no department for services
         // Only load fee if appointment was NOT paid
    if ($appointmentPaid <= 0) {
        $feeAmount = $row['amount'];
    }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Register Patient</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#eef2f7;
    font-family:Arial,Helvetica,sans-serif;
    font-size:13px;
    color:#1e293b;
}

.container{
    max-width:1500px;
    margin:0 auto;
    padding:5px;
}

.header{
    background:#1976d2;
    color:#fff;
    padding:10px 16px;
    border-radius:10px;
    box-shadow:0 8px 20px rgba(0,0,0,.08);
    margin-bottom:14px;
}

.header h1{
    margin:0;
    font-size:18px;
    font-weight:bold;
}

.header p{
    margin:4px 0 0;
    font-size:13px;
    opacity:.9;
}

.card{
    background:#fff;
    border-radius:10px;
    box-shadow:0 8px 20px rgba(0,0,0,.08);
    margin-bottom:10px;
    overflow:hidden;
    position:relative;
}

.card::before{
    display:none;
}

.card h3{
    background:#1976d2;
    color:#fff;
    margin:0;
    padding:10px 15px;
    font-size:15px;
    font-weight:bold;
    display:flex;
    align-items:center;
    gap:7px;
}

.card h3 span{
    width:auto;
    height:auto;
    border-radius:0;
    background:none;
    color:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
}

.card>.grid,
.card>.revisit-box,
.card>.actions,
.card>div:not(h3){
    margin-left:15px;
    margin-right:15px;
}

.card>.grid{
    margin-top:14px;
    margin-bottom:14px;
}

.grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:10px 14px;
}

label{
    display:block;
    font-size:12px;
    font-weight:bold;
    color:#475569;
    margin-bottom:4px;
}

.required{
    color:#dc2626;
}

input,
select,
textarea{
    width:100%;
    padding:7px 8px;
    border:1px solid #d1d5db;
    border-radius:6px;
    background:#fff;
    color:#1e293b;
    font-family:Arial,Helvetica,sans-serif;
    font-size:14px;
    min-height:34px;
}

input:focus,
select:focus,
textarea:focus{
    outline:none;
    border-color:#1976d2;
    box-shadow:0 0 0 2px rgba(25,118,210,.12);
}

textarea{
    resize:vertical;
    max-height: 35px;
}

.inline{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:5px;
}

.readonly{
    background:#f8fafc!important;
    color:#475569;
}

button{
    background:#1976d2;
    color:#fff;
    border:none;
    padding:8px 14px;
    font-size:13px;
    font-weight:bold;
    border-radius:6px;
    cursor:pointer;
}

button:hover{
    background:#1565c0;
}

.btn-primary{
    background:#1976d2;
    color:#fff;
    box-shadow:none;
}

.btn-secondary{
    background:#64748b;
    color:#fff;
}
.abha-card-modal {
    display:none;
    position:fixed;
    inset:0;
    z-index:99999;
    background:rgba(0,0,0,0.65);
    align-items:center;
    justify-content:center;
    padding:20px;
}

.abha-card-modal-box {
    width:100%;
    max-width:760px;
    max-height:90vh;
    overflow-y:auto;
    background:#fff;
    border-radius:14px;
    box-shadow:0 20px 60px rgba(0,0,0,0.25);
}

.abha-card-modal-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:18px 22px;
    border-bottom:1px solid #e5e7eb;
}

.abha-card-modal-header h3 {
    margin:0;
    font-size:20px;
}

.abha-card-modal-header p {
    margin:4px 0 0;
    color:#6b7280;
    font-size:13px;
}

.abha-card-modal-body {
    padding:22px;
}

.abha-card {
    width:100%;
    max-width:680px;
    margin:0 auto;
    border-radius:16px;
    overflow:hidden;
    background:#fff;
    border:1px solid #d9dee7;
    box-shadow:0 8px 30px rgba(0,0,0,0.10);
}

.abha-card-top {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:20px 24px;
    background:#f3f7ff;
    border-bottom:1px solid #dce5f5;
}

.abha-card-title {
    font-size:28px;
    font-weight:800;
    letter-spacing:1px;
}

.abha-card-subtitle {
    margin-top:3px;
    font-size:13px;
    color:#64748b;
}

.abha-card-status {
    padding:6px 12px;
    border-radius:20px;
    background:#dcfce7;
    color:#166534;
    font-size:12px;
    font-weight:700;
}

.abha-card-content {
    display:flex;
    gap:24px;
    padding:24px;
}

.abha-card-photo-wrap {
    width:120px;
    min-width:120px;
    display:flex;
    justify-content:center;
    align-items:flex-start;
}

.abha-card-photo {
    width:110px;
    height:130px;
    object-fit:cover;
    border-radius:10px;
    border:1px solid #d1d5db;
}

.abha-card-photo-placeholder {
    width:110px;
    height:130px;
    border-radius:10px;
    border:1px solid #d1d5db;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:42px;
    background:#f8fafc;
}

.abha-card-details {
    flex:1;
}

.abha-card-row {
    display:flex;
    justify-content:space-between;
    gap:20px;
    padding:10px 0;
    border-bottom:1px solid #edf0f4;
}

.abha-card-row span {
    color:#64748b;
    font-size:13px;
}

.abha-card-row strong {
    text-align:right;
    font-size:14px;
    color:#111827;
    word-break:break-word;
}

.abha-card-footer {
    display:flex;
    justify-content:space-between;
    padding:12px 24px;
    background:#f8fafc;
    border-top:1px solid #e5e7eb;
    font-size:12px;
    color:#64748b;
}

.abha-card-actions {
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:18px;
}

@media (max-width:600px) {
    .abha-card-content {
        flex-direction:column;
        align-items:center;
    }

    .abha-card-details {
        width:100%;
    }

    .abha-card-row {
        flex-direction:column;
        gap:3px;
    }

    .abha-card-row strong {
        text-align:left;
    }

    .abha-card-top {
        padding:16px;
    }

    .abha-card-content {
        padding:16px;
    }
}
/* =========================
   EXISTING PATIENT MODAL
========================= */
.revisit-modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.58);
    backdrop-filter:blur(4px);
    z-index:9999;
    padding:20px;
    overflow-y:auto;
}
.revisit-modal-box{
    width:100%;
    max-width:950px;
    margin:30px auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 20px 50px rgba(0,0,0,.25);
    overflow:hidden;
}
.revisit-modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:13px 18px;
    background:linear-gradient(135deg,#1976d2,#0288d1);
    color:#fff;
}
.revisit-modal-header h3{
    margin:0;
    font-size:16px;
    font-weight:bold;
    color:#fff;
}
.revisit-modal-subtitle{
    margin-top:3px;
    font-size:11px;
    opacity:.9;
}
.revisit-modal-close{
    width:32px;
    height:32px;
    padding:0;
    border-radius:50%;
    background:rgba(255,255,255,.18);
    color:#fff;
    font-size:21px;
    line-height:32px;
    text-align:center;
    flex:none;
}
.revisit-modal-close:hover{
    background:rgba(255,255,255,.3);
}
.revisit-modal-body{
    padding:16px;
}
.revisit-search-row{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}
.revisit-search-row input{
    flex:1;
    min-width:0;
}
.revisit-search-row button{
    min-width:75px;
}
.revisit-table-wrap{
    width:100%;
    overflow-x:auto;
    border:1px solid #e2e8f0;
    border-radius:8px;
}

#revisitPatientTable{
    width:100%;
    border-collapse:collapse;
}
#revisitPatientTable th{
    background:#f8fafc;
    color:#475569;
    font-size:12px;
    font-weight:bold;
    padding:10px 11px;
    border-bottom:1px solid #e2e8f0;
    text-align:left;
    white-space:nowrap;
}
#revisitPatientTable td{
    padding:9px 11px;
    border-bottom:1px solid #e2e8f0;
    font-size:13px;
    color:#334155;
}
#revisitPatientTable tbody tr:hover{
    background:#f8fafc;
}
#revisitPatientTable tbody tr:last-child td{
    border-bottom:none;
}
#revisitPatientTable input[type="radio"]{
    width:17px;
    height:17px;
    min-height:17px;
    cursor:pointer;
}
.revisit-pagination{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    margin-top:12px;
}
.revisit-pagination button{
    padding:6px 12px;
    font-size:12px;
}
.revisit-pagination button:disabled{
    opacity:.45;
    cursor:not-allowed;
}
#revisitPageInfo{
    min-width:90px;
    text-align:center;
    font-size:12px;
    font-weight:bold;
    color:#475569;
}
@media(max-width:700px){
    .revisit-modal{
        padding:10px;
    }
    .revisit-modal-box{
        margin:15px auto;
    }
    .revisit-modal-header{
        padding:11px 13px;
    }
    .revisit-modal-body{
        padding:11px;
    }
    .revisit-search-row{
        flex-direction:column;
    }
    .revisit-search-row button{
        width:100%;
    }
    #revisitPatientTable th,
    #revisitPatientTable td{
        padding:8px;
    }
}
.actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:15px;
    margin-bottom:15px;
}

.revisit-box{
    display:flex;
    align-items:center;
    gap:12px;
    padding:8px 10px;
    background:#f8fafc;
    border:1px solid #dbe3ee;
    border-radius:6px;
    min-height:34px;
}

.revisit-check{
    display:flex;
    align-items:center;
    gap:7px;
    margin:0;
    font-size:12px;
    font-weight:bold;
    color:#334155;
    cursor:pointer;
}

.revisit-check input{
    width:16px;
    min-height:16px;
    height:16px;
    margin:0;
    cursor:pointer;
}

.revisit-btn{
    margin-left:auto;
    padding:7px 12px;
    border-radius:6px;
    background:#64748b;
    color:#fff;
    font-size:12px;
    font-weight:bold;
    box-shadow:none;
}

.revisit-btn:hover{
    background:#475569;
    transform:none;
}

/* =========================
   ADD CITY MODAL
========================= */
.city-modal{
    position:fixed!important;
    inset:0!important;
    z-index:10000!important;
    display:none;
    align-items:center!important;
    justify-content:center!important;
    padding:15px!important;
    background:rgba(15,23,42,.50)!important;
    backdrop-filter:blur(5px);
    -webkit-backdrop-filter:blur(5px);
}
.city-modal[style*="display: block"]{
    display:flex!important;
}
.city-modal-box{
    width:min(560px,96vw)!important;
    margin:0!important;
    position:relative!important;
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:13px;
    overflow:hidden;
    box-shadow:0 20px 55px rgba(15,23,42,.24);
    animation:cityModalIn .18s ease-out;
}
@keyframes cityModalIn{
    from{
        opacity:0;
        transform:translateY(10px) scale(.98);
    }
    to{
        opacity:1;
        transform:translateY(0) scale(1);
    }
}
.city-modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    height:46px;
    padding:0 14px;
    background:linear-gradient(135deg,#2563eb,#0891b2);
    color:#fff;
}
.city-modal-title{
    display:flex;
    align-items:center;
    gap:9px;
}
.city-modal-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:28px;
    height:28px;
    flex:0 0 28px;
    border-radius:7px;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.22);
    color:#fff;
    font-size:13px;
}
.city-modal-title h3{
    margin:0;
    color:#fff;
    font-size:14px;
    font-weight:700;
}
.city-modal-title p{
    margin:1px 0 0;
    color:rgba(255,255,255,.82);
    font-size:9px;
}
.city-modal-close{
    display:flex;
    align-items:center;
    justify-content:center;
    width:28px;
    height:28px;
    padding:0;
    border:1px solid rgba(255,255,255,.30);
    border-radius:50%;
    background:rgba(255,255,255,.12);
    color:#fff;
    font-size:18px;
    line-height:1;
    cursor:pointer;
}
.city-modal-body{
    padding:16px;
}
.city-modal-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}
.city-modal-grid label{
    display:block;
    margin-bottom:5px;
    color:#334155;
    font-size:11px;
    font-weight:600;
}
.city-modal-grid select,
.city-modal-grid input{
    width:100%;
    min-height:36px;
    padding:7px 9px;
    border:1px solid #cbd5e1;
    border-radius:7px;
    background:#fff;
    color:#172033;
    font-size:12px;
}
.city-modal-grid select:focus,
.city-modal-grid input:focus{
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 2px rgba(37,99,235,.10);
}
.city-modal-footer{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding:10px 14px;
    background:#f8fafc;
    border-top:1px solid #e2e8f0;
}
.city-modal-footer button{
    min-width:70px;
}
@media(max-width:600px){
    .city-modal{
        padding:10px;
    }
    .city-modal-box{
        width:100%;
    }
    .city-modal-grid{
        grid-template-columns:1fr;
    }
}
.field-error{
    border:2px solid #dc2626!important;
    background:#fff5f5!important;
}

.select2-container{
    width:100%!important;
}

.select2-container .select2-selection--single{
    height:34px!important;
    border:1px solid #d1d5db!important;
    border-radius:6px!important;
}

.select2-container .select2-selection--single .select2-selection__rendered{
    line-height:32px!important;
    font-size:13px;
}

.select2-container .select2-selection--single .select2-selection__arrow{
    height:32px!important;
}
.save-button-container{
    display:flex;
    justify-content:flex-end;
}
.save-appointment-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    width:180px;
    height:39px!important;
    min-height:39px!important;
    padding:6px 22px;
    box-sizing:border-box;
    border:1px solid rgba(255,255,255,.18);
    border-radius:9px;
    background:linear-gradient(135deg,#2563eb,#0891b2);
    color:#fff;
    font-size:13px;
    font-weight:700;
    letter-spacing:.1px;
    box-shadow:0 5px 14px rgba(37,99,235,.22);
    cursor:pointer;
    transition:all .18s ease;
    margin-bottom:10px;
}
.save-appointment-btn:hover{
    background:linear-gradient(135deg,#1d4ed8,#07819d);
    transform:translateY(-1px);
    box-shadow:0 7px 18px rgba(37,99,235,.28);
}
.save-appointment-btn:active{
    transform:translateY(0);
    box-shadow:0 3px 8px rgba(37,99,235,.20);
}
.save-appointment-btn .save-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    line-height:1;
}
#fee{
    font-weight:bold;
    color:#1e40af;
}

#balance{
    font-weight:bold;
    color:#b45309;
}

#paid{
    font-weight:bold;
    color:#15803d;
}

#payment_mode{
    font-weight:bold;
}

#consultation_fee{
    font-weight:bold;
    color:#1e40af;
}

#discount_percent,
#discount_amount{
    font-weight:600;
}

#saveBtn{
    min-width:170px;
    padding:9px 18px;
    font-size:13px;
    font-weight:bold;
    background:#1976d2;
    border-radius:6px;
}

.modal-box{
    background:#fff;
    max-width:600px;
    margin:80px auto;
    padding:20px;
    border-radius:10px;
    box-shadow:0 8px 25px rgba(0,0,0,.2);
}

.modal .close{
    float:right;
    font-size:22px;
    cursor:pointer;
    font-weight:bold;
}
#visit_count_box{
    display:flex;
    align-items:center;
    gap:8px;
    height:39px;
    padding:0 9px;
    border:1px solid #bfdbfe;
    border-radius:8px;
    background:#eff6ff;
    box-sizing:border-box;
}
#visit_count_box label{
    min-height:auto;
    margin:0!important;
    white-space:nowrap;
    color:#334155;
    font-size:11px;
    font-weight:650;
}
#visit_count_box input{
    width:48px!important;
    min-width:48px!important;
    max-width:48px!important;
    min-height:28px!important;
    height:28px!important;
    padding:4px 6px!important;
    background:#fff!important;
    text-align:center;
    font-size:12px;
    font-weight:700;
}
@media(max-width:1100px){
    .grid{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:800px){
    .grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .header{
        padding:9px 14px;
    }

    .card{
        margin-left:5px;
        margin-right:5px;
    }

    .card>.grid,
    .card>.revisit-box,
    .card>.actions,
    .card>div:not(h3){
        margin-left:12px;
        margin-right:12px;
    }
}
.ipd-payment-field{
    display:block;
}
select:disabled,
input:disabled{
    background:#f1f5f9!important;
    color:#64748b!important;
    cursor:not-allowed!important;
    opacity:0.75;
}

.appointment-payment-locked{
    color:#64748b!important;
    opacity:0.75;
    cursor:not-allowed!important;
}

.appointment-payment-locked input{
    cursor:not-allowed!important;
}
@media(max-width:560px){
    body{
        font-size:13px;
    }
    .container{
        padding:8px;
    }
    .header{
        border-radius:7px;
    }
    .header h1{
        font-size:16px;
    }
    .grid{
        grid-template-columns:1fr;
        gap:10px;
    }
    .inline{
        grid-template-columns:repeat(3,1fr);
    }
    .card{
        border-radius:8px;
        margin-left:0;
        margin-right:0;
    }
    .card h3{
        padding:9px 12px;
        font-size:14px;
    }
    .card>.grid,
    .card>.revisit-box,
    .card>.actions,
    .card>div:not(h3){
        margin-left:10px;
        margin-right:10px;
    }
    .actions{
        flex-direction:column;
    }
    #saveBtn{
        width:100%;
    }
}
.abha-create-modal{
    position:fixed;
    inset:0;
    z-index:99999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:15px;
    background:rgba(15,23,42,.60);
    backdrop-filter:blur(4px);
}
.abha-create-modal-box{
    width:min(500px,96vw);
    background:#fff;
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 20px 55px rgba(0,0,0,.28);
}
.abha-create-modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:14px 16px;
    background:linear-gradient(135deg,#1976d2,#0288d1);
    color:#fff;
}
.abha-create-modal-header h3{
    margin:0;
    color:#fff;
    font-size:16px;
}
.abha-create-modal-header p{
    margin:3px 0 0;
    color:rgba(255,255,255,.85);
    font-size:11px;
}
.abha-create-close{
    width:32px;
    height:32px;
    min-height:32px;
    padding:0;
    border-radius:50%;
    background:rgba(255,255,255,.18);
    color:#fff;
    font-size:24px;
    line-height:30px;
}
.abha-create-close:hover{
    background:rgba(255,255,255,.30);
}
.abha-create-modal-body{
    padding:18px;
}
.abha-create-modal-body label{
    display:block;
    margin-bottom:5px;
    font-size:12px;
    font-weight:bold;
    color:#475569;
}
.abha-create-modal-body input{
    width:100%;
    margin-bottom:12px;
}
.abha-create-action{
    width:100%;
    margin-top:5px;
    background:#1976d2;
    color:#fff;
}
.abha-create-action.success{
    background:#16a34a;
}
.abha-create-action:hover{
    opacity:.92;
}
</style>
</head>
<body>
<form method="post" action="save_patient_registration.php" id="appointmentForm">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
     <input type="hidden"
       name="patient_id"
       id="patient_id"
       value="<?= $revisitPatient['id'] ?? '' ?>">
      
<input type="hidden"
       name="is_revisit"
       id="is_revisit"
       value="<?= !empty($revisitPatient) ? 1 : 0 ?>">
<input type="hidden" name="appointment_id" value="<?= $appointment_id ?>">
<div class="container">

<!-- ================= APPOINTMENT INFO ================= -->
<div class="card">
<h3><span>🩺</span>Patient Registration</h3>
<div class="grid">
   <!-- 1. TYPE -->
<div>
    <label>Select OPD / IPD / Direct Service*</label>
    <select name="type" id="type_dd">
        <option value="">Select</option>
        <option value="doctor" <?= ($type ?? '')=='doctor'?'selected':'' ?>>OPD</option>
        <option value="ipd" <?= ($type ?? '')=='ipd'?'selected':'' ?>>IPD</option>
        <option value="service" <?= ($type ?? '')=='service'?'selected':'' ?>>Direct Service</option>
    </select>
</div>

<!-- 2. CONSULTATION MODE -->
<div id="consultation_mode_box" style="<?= (($type ?? '') === 'doctor') ? '' : 'display:none;' ?>">
    <label>Consultation Mode*</label>

    <?php if ($appointment_id): ?>

        <select id="consultation_mode_display" disabled>
            <option value="PHYSICAL" <?= $consultation_mode === 'PHYSICAL' ? 'selected' : '' ?>>Physical</option>
            <option value="TELEMEDICINE" <?= $consultation_mode === 'TELEMEDICINE' ? 'selected' : '' ?>>Telemedicine</option>
        </select>

        <input
            type="hidden"
            name="consultation_mode"
            value="<?= htmlspecialchars($consultation_mode) ?>"
        >

    <?php else: ?>

        <select name="consultation_mode" id="consultation_mode">
            <option value="PHYSICAL" <?= $consultation_mode === 'PHYSICAL' ? 'selected' : '' ?>>Physical</option>
            <option value="TELEMEDICINE" <?= $consultation_mode === 'TELEMEDICINE' ? 'selected' : '' ?>>Telemedicine</option>
        </select>

    <?php endif; ?>
</div>
    <!-- 2. DEPARTMENT / CATEGORY -->
<div>
    <label>Department / Service Category *</label>
    <select name="department_id" id="department_dd">
        <option value="">Select</option>
    </select>
    <input type="hidden" name="department_name" id="department_name_hidden">
</div>
    <!-- 3. DOCTOR / SERVICE -->
<div>
    <label>Doctor / Service *</label>
    <select name="entity_id" id="entity_dd">
        <option value="">Select</option>
    </select>
</div>
    <!-- 4. DATE -->
    <div>
    <label>Registration / Admission Date*</label>
    <input type="date" name="appointment_date" id="appointment_date" min="<?= date('Y-m-d', strtotime('-1 day')) ?>" value="<?= $date ?? date('Y-m-d') ?>">
</div>
    <!-- 5. TIME -->
    <div>
    <label>Time</label>
    <select name="appointment_time" id="appointment_time_dd" >
    <option value="">Select Slot</option></select>
</div>
<?php if ($source === 'INTERNAL'): ?>
<div class="revisit-box">
    <label class="revisit-check">
        <input type="checkbox" id="revisit">
        <span>Revisit Patient</span>
    </label>

    <label class="revisit-check">
        <input type="checkbox" id="emergency" name="is_emergency" value="1">
        <span>Emergency</span>
    </label>
</div>
<?php endif; ?>
<?php if ($source === 'INTERNAL'): ?>
<div id="visit_count_box" style="display:none;">
    <label>No. of Visits</label>
    <input type="text"
           id="visit_count"
           class="readonly"
           readonly>
</div>
<?php endif; ?>
 </div>
</div>
<?php /* GLOBAL ABDM SWITCH: rendered only when ABDM is enabled for this installation. */ if ($abdmGlobalEnabled): ?>
<!-- ================= ABDM INTEGRATION CHOICE ================= -->
<!-- Workflow flag only. NOT persisted, NOT a patient preference,
     NOT an ABHA link, and NOT consent to exchange health information. -->
<div class="card">
<h3>ABDM Integration</h3>
<div class="grid">
    <div>
        <label>Is this patient to be registered with ABDM (Ayushman Bharat Digital Mission)?</label>
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-top:6px;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;">
                <input type="radio" name="abdm_integration" id="abdm_no" value="0" checked>
                <span>No</span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;">
                <input type="radio" name="abdm_integration" id="abdm_yes" value="1">
                <span>Yes</span>
            </label>
        </div>
        <small style="display:block;margin-top:6px;color:#666;">
            Choosing &quot;Yes&quot; reveals the ABHA fields and actions below. ABHA is never
            mandatory for registration, and an ABHA number is not consent to share health information.
        </small>
    </div>
</div>
</div>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>
<!-- ================= PATIENT DETAILS ================= -->
<div class="card">
<h3><span>🧑</span>Patient Details</h3>
<div class="grid">
<!-- ================= PATIENT DETAILS ================= -->
<?php /* GLOBAL ABDM SWITCH: rendered only when ABDM is enabled for this installation. */ if ($abdmGlobalEnabled): ?>
    <div id="abdm_abha_field">
    <label>ABHA ID (ABDM)</label>
    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
        <input type="text"
               id="abha_id"
               name="abha_id"
               value="<?= htmlspecialchars($revisitPatient['abha_id'] ?? '') ?>"
               placeholder="Enter ABHA ID (e.g. 12-3456-7890-1234)"
               style="flex:1; min-width:220px;">

        <button type="button"
                onclick="fetchABHADetails()"
                class="btn btn-primary btn-sm"
                title="Verify ABHA"
                style="width:36px; height:34px; padding:0; display:flex; align-items:center; justify-content:center; font-size:18px;">
            ✓
        </button>

        
    </div>

    <input type="hidden"
           name="abha_verified"
           id="abha_verified"
           value="0">
</div>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>

 <div>
        <label>Aadhaar No</label>
<div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap;">
    <input type="text"
           id="aadhaar"
           name="aadhaar_no"
           value="<?= (isset($revisitPatient['aadhaar']) && preg_match('/^\d{12}$/', (string)$revisitPatient['aadhaar'])) ? htmlspecialchars($revisitPatient['aadhaar']) : '' ?>"
           maxlength="12"
           pattern="\d{12}"
           inputmode="numeric"
           oninput="this.value=this.value.replace(/\D/g,'').slice(0,12)"
           title="Aadhaar must be exactly 12 digits"
           style="flex:1;min-width:0;">
<?php /* GLOBAL ABDM SWITCH: rendered only when ABDM is enabled for this installation. */ if ($abdmGlobalEnabled): ?>
    <button type="button"
            id="abdm_aadhaar_verify"
            onclick="verifyAadhaarForRegistration(event)"
            class="btn btn-primary btn-sm"
            title="Verify Aadhaar"
            style="width:36px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;font-size:18px;">
        ✓
    </button>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>
</div>
    </div>
    <div>
    <label>Mobile <span class="required">*</span></label>
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap;">
        <input type="text"
               id="mobile"
               name="mobile"
               maxlength="10"
               pattern="[0-9]{10}"
               inputmode="numeric"
               oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
               value="<?= $revisitPatient['mobile'] ?? ($appt['mobile'] ?? '') ?>"
               style="flex:1;min-width:0;">
<?php /* GLOBAL ABDM SWITCH: rendered only when ABDM is enabled for this installation. */ if ($abdmGlobalEnabled): ?>
        <button type="button"
                onclick="window.verifyMobileForRegistration(event)"
                class="btn btn-primary btn-sm"
                title="Verify Mobile"
                style="width:36px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;font-size:18px;">
            ✓
        </button>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>
    </div>
</div>

<?php /* GLOBAL ABDM SWITCH: rendered only when ABDM is enabled for this installation. */ if ($abdmGlobalEnabled): ?>
<div id="abdm_action_buttons">
<button type="button"
style="margin-top:18px;"
        id="viewABHACardButton"
        onclick="viewABHACard()"
        class="btn btn-success btn-sm"
        style="height:34px; padding:0 12px;">
    View ABHA Card
</button>
<button type="button" id="abdm_create_abha" onclick="openCreateABHAModal()" class="btn btn-success">Create ABHA</button>
</div>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>

    <div>
        <label>Title</label>
        <select id="title" name="title">
<option value="Mr" <?= (($revisitPatient['title'] ?? '')=='Mr')?'selected':'' ?>>Mr</option>
<option value="Mrs" <?= (($revisitPatient['title'] ?? '')=='Mrs')?'selected':'' ?>>Mrs</option>
<option value="Miss" <?= (($revisitPatient['title'] ?? '')=='Miss')?'selected':'' ?>>Miss</option>
<option value="Master" <?= (($revisitPatient['title'] ?? '')=='Master')?'selected':'' ?>>Master</option>
<option value="Baby" <?= (($revisitPatient['title'] ?? '')=='Baby')?'selected':'' ?>>Baby</option>
<option value="Baby of" <?= (($revisitPatient['title'] ?? '')=='Baby of')?'selected':'' ?>>Baby of</option>
<option value="Dr" <?= (($revisitPatient['title'] ?? '')=='Dr')?'selected':'' ?>>Dr</option>
</select>
    </div>
    <div>
        <label>First Name <span class="required">*</span></label>
        <input type="text"
       id="first_name"
       name="first_name"
       value="<?= $revisitPatient['first_name'] ?? ($appt['first_name'] ?? '') ?>">
    </div>
    <div>
    <label>Last Name</label>
    <input type="text"
       id="last_name"
       name="last_name"
       value="<?= $revisitPatient['last_name'] ?? ($appt['last_name'] ?? '') ?>">
</div>
 <div>
        <label>Marital Status</label>
        <select id="marital" name="marital_status">
<option value="Single" <?= (($revisitPatient['marital_status'] ?? '')=='Single')?'selected':'' ?>>Single</option>
<option value="Married" <?= (($revisitPatient['marital_status'] ?? '')=='Married')?'selected':'' ?>>Married</option>
<option value="Divorced" <?= (($revisitPatient['marital_status'] ?? '')=='Divorced')?'selected':'' ?>>Divorced</option>
</select>
    </div>
    <div>
        <label>Gender</label>
        <select id="sex" name="sex">
    <option value="Male" <?= ($appt['sex'] ?? '')=='Male'?'selected':'' ?>>Male</option>
    <option value="Female" <?= ($appt['sex'] ?? '')=='Female'?'selected':'' ?>>Female</option>
    <option value="Other" <?= ($appt['sex'] ?? '')=='Other'?'selected':'' ?>>Other</option>
</select>
    </div>
<div>
    <label id="guardian_label">Father / Mother Name</label>
    <input type="text"
       id="guardian_name"
       name="guardian_name"
       value="<?= $revisitPatient['guardian_name'] ?? ($appt['guardian_name'] ?? '') ?>">
</div>
    <div>
        <label>Phone</label>
        <input type="text"
       id="phone"
       name="phone"
       maxlength="10"
       pattern="[0-9]{10}"
       inputmode="numeric"
       value="<?= $revisitPatient['phone'] ?? ($appt['phone'] ?? '') ?>">
       </div>
    <div>
        <label>Email</label>
        <input type="email" id="email" name="email" value="<?= $revisitPatient['email'] ?? ($appt['email'] ?? '') ?>">
    </div>
    
    <div>
        <label>Age (Y | M | D)</label>
        <div class="inline">
            <input type="number" id="age_y" name="age_y" placeholder="Y" oninput="ageToDob()">
            <input type="number" id="age_m" name="age_m" placeholder="M" oninput="ageToDob()">
            <input type="number" id="age_d" name="age_d" placeholder="D" oninput="ageToDob()">
        </div>
    </div>
    <div>
        <label>Date of Birth <span class="required">*</span></label>
        <input type="date"
       id="dob"
       name="dob"
       onchange="dobToAge()"
       value="<?= $revisitPatient['dob'] ?? ($appt['dob'] ?? '') ?>"
       max="<?= date('Y-m-d') ?>">
    </div>
    <div>
    <label>
    City <span class="required">*</span>
    <?php if ($source === 'INTERNAL'): ?>
        <button type="button" tabindex="-1"
            onclick="openCityModalFromForm()"
            style="
                margin-left:8px;
                background:#2563eb;
                color:#fff;
                border:none;
                padding:2px 8px;
                border-radius:4px;
                font-size:12px;
                cursor:pointer;
            ">
            + Add City
        </button>
    <?php endif; ?>
</label>
    <select id="city" name="city">
    <option value="">Select City</option>
    <?php foreach ($cities as $c): ?>
        <option value="<?= $c['id'] ?>"
    <?= (($revisitPatient['city_id'] ?? ($appt['city_id'] ?? '')) == $c['id']) ? 'selected' : '' ?>>
    <?= htmlspecialchars($c['name']) ?>
</option>
    <?php endforeach; ?>
</select>
</div>
       <div>
        <label>Pincode</label>
        <input type="text" id="pincode" name="pincode" value="<?= $revisitPatient['pincode'] ?? ($appt['pincode'] ?? '') ?>">
    </div>
   <!-- ================= REFERRAL SECTION ================= -->
<div>
    <label>Referral Type</label>
    <select id="referral_type" name="referral_type">
        <option value="">Select</option>
        <option value="Internal">Internal</option>
        <option value="External">External</option>
    </select>
</div>
<div id="internal_referral_box" style="display:none;">
    <label>Internal Referral (User)</label>
    <select id="internal_referral_id" name="internal_referral_id">
        <option value="">Select User</option>
    </select>
</div>
<div id="external_referral_box" style="display:none;">
    <label>
        External Referral
        <button type="button"
                onclick="openReferralModal()"
                style="margin-left:6px;
                       background:#2563eb;
                       color:#fff;
                       border:none;
                       padding:2px 8px;
                       font-size:12px;
                       border-radius:4px;
                       cursor:pointer;">
            + Add
        </button>
    </label>
    <select id="external_referral_id" name="external_referral_id">
        <option value="">Select Referral</option>
    </select>
</div>
<div>
        <label>Address</label>
        <textarea id="address" name="address"><?= htmlspecialchars($revisitPatient['address'] ?? ($appt['address'] ?? '')) ?></textarea>
    </div>
    <div>
        <label>Remarks</label>
        <textarea id="remarks" name="remarks"><?= htmlspecialchars($revisitPatient['remarks'] ?? ($appt['remarks'] ?? '')) ?></textarea>
    </div>
</div>
</div>
<!-- ================= PAYMENT ================= -->
<div class="card">
<h3><span>💰</span>Payment Details</h3>
<?php if(!empty($appointmentPaid) && $appointmentPaid > 0): ?>
<div style="
background:#dcfce7;
padding:10px;
border-radius:6px;
margin-bottom:10px;
color:#065f46;
font-weight:600;
">
₹<?= number_format($appointmentPaid,2) ?> received during appointment
</div>
<?php endif; ?>
<div class="grid">
<div class="ipd-payment-field">
    <label>Consultation Fee</label>
    <input type="number"
           id="consultation_fee"
           value="<?= $feeAmount ?>"
           readonly
           class="readonly">
</div>
<div>
    <label>Empanelment</label>
    <select name="empanelment_id" id="empanelment_id">
        <option value="">Select Empanelment</option>
        <?php foreach ($empanelments as $e): ?>
            <option value="<?= $e['id'] ?>">
                <?= htmlspecialchars($e['empanelment_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
    <!-- ================= DISCOUNT SECTION ================= -->
<div class="ipd-payment-field">
    <label>Discount Type</label>
    <select id="discount_id" name="discount_id">
        <option value="">Select Discount</option>
        <?php foreach ($discounts as $d): ?>
            <option value="<?= $d['id'] ?>">
                <?= htmlspecialchars($d['discount_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
<div class="ipd-payment-field">
    <label>Discount %</label>
    <input type="number" step="0.01" id="discount_percent" name="discount_percent" disabled>
</div>
<div class="ipd-payment-field">
    <label>Discount Amount</label>
    <input type="number" step="0.01" id="discount_amount" name="discount_amount" disabled>
</div>
<div class="revisit-box">
    <label class="revisit-check">
        <input type="checkbox" id="is_camp" name="is_camp" value="1">
        <span>Camp (Free Consultation)</span>
    </label>
</div>
<div class="ipd-payment-field">
        <label>Net Payable</label>
        <input type="number"
       id="fee"
       name="fee"
       value="<?= $feeAmount ?>"
       readonly
       class="readonly">
    </div>
    <div class="col ipd-payment-field">
        <label>Payment Mode</label>
       <select id="payment_mode" name="payment_mode">
            <option>Cash</option>
        <option>UPI</option>
        <option>Card</option>
</select>
    </div>
        <div class="col ipd-payment-field">
        <label>Paid Amount</label>
        <input type="number"
               id="paid"
               name="paid_amount"
               readonly
               class="readonly">
         </div>
    <div class="col ipd-payment-field">
        <label>Balance</label>
        <input type="number" id="balance" name="balance" readonly class="readonly">
    </div>
</div>
<div style="display:flex; justify-content:flex-end; margin-top:5px;">
  <button type="submit" class="btn-primary save-appointment-btn">
    <span class="save-icon">✓</span>
    <span>Save Patient</span>
  </button>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const titleDD   = document.getElementById('title');
    const genderDD  = document.getElementById('sex');
    const maritalDD = document.getElementById('marital');
    const guardianLabel = document.getElementById('guardian_label');
    function setOptions(select, values) {
        select.innerHTML = '';
        values.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v;
            select.appendChild(opt);
        });
    }
    function updateGuardianLabel() {
        const title   = titleDD.value;
        const gender  = genderDD.value;
        const marital = maritalDD.value;

        // Default
        guardianLabel.textContent = 'Father Name';

        // ONLY this exact case → Husband Name
        if (
            title === 'Mrs' &&
            gender === 'Female' &&
            marital === 'Married'
        ) {
            guardianLabel.textContent = 'Husband Name';
        }
    }
    function applyTitleRules() {
        const title = titleDD.value;
// 🔥 Check if we're in revisit mode BEFORE applying restrictions
    if (isRevisit) {
        // For revisit patients, allow all options
        setOptions(genderDD, ['Male','Female','Other']);
        setOptions(maritalDD, ['Single','Married','Divorced']);
        guardianLabel.textContent = 'Father Name';
        updateGuardianLabel();
        return;
    }
        // defaults
        setOptions(genderDD, ['Male','Female','Other']);
        setOptions(maritalDD, ['Single','Married','Divorced']);
        guardianLabel.textContent = 'Father Name';
        switch (title) {
            case 'Mr':
                setOptions(genderDD, ['Male']);
                break;
            case 'Mrs':
                setOptions(genderDD, ['Female']);
                setOptions(maritalDD, ['Married','Divorced']);
                break;
            case 'Miss':
                setOptions(genderDD, ['Female']);
                setOptions(maritalDD, ['Single']);
                break;
            case 'Master':
                setOptions(genderDD, ['Male']);
                setOptions(maritalDD, ['Single']);
                break;
            case 'Baby':
            case 'Baby of':
                setOptions(genderDD, ['Male','Female']);
                setOptions(maritalDD, ['Single']);
                break;
            case 'Dr':
                setOptions(genderDD, ['Male','Female']);
                setOptions(maritalDD, ['Single','Married','Divorced']);
                break;
        }
        updateGuardianLabel();
    }
    // apply on load
    applyTitleRules();
    // update on any change
    titleDD.addEventListener('change', applyTitleRules);
    genderDD.addEventListener('change', updateGuardianLabel);
    maritalDD.addEventListener('change', updateGuardianLabel);
});

/* ============================================================
   ABDM INTEGRATION TOGGLE
   ------------------------------------------------------------
   Reveals/hides the ABDM-specific controls only:
     - ABHA ID field, its verify button and the hidden abha_verified flag
     - the Aadhaar ABDM verification button
     - View ABHA Card and Create ABHA
   The plain "Aadhaar No" demographic input is intentionally NOT
   hidden: it is part of ordinary patient registration.

   Hiding is done with style.display only - elements are never removed
   from the DOM and no value is ever cleared - so switching back to
   "Yes" restores anything the user already typed, and a failed ABDM
   call can never discard the rest of the form.
   ============================================================ */
<?php /* GLOBAL ABDM SWITCH: rendered only when ABDM is enabled for this installation. */ if ($abdmGlobalEnabled): ?>
function applyAbdmIntegrationVisibility() {
    var enabled = document.getElementById('abdm_yes');
    var on = enabled !== null && enabled.checked === true;

    var targets = [
        'abdm_abha_field',
        'abdm_aadhaar_verify',
        'abdm_action_buttons'
    ];

    for (var i = 0; i < targets.length; i++) {
        var el = document.getElementById(targets[i]);
        if (el) {
            el.style.display = on ? '' : 'none';
        }
    }

    // The Create ABHA modal is triggered from a hidden button; make sure a
    // modal left open before switching to "No" cannot linger.
    var modal = document.getElementById('createABHAModal');
    if (modal && !on) {
        modal.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    applyAbdmIntegrationVisibility();

    var no = document.getElementById('abdm_no');
    var yes = document.getElementById('abdm_yes');

    if (no) { no.addEventListener('change', applyAbdmIntegrationVisibility); }
    if (yes) { yes.addEventListener('change', applyAbdmIntegrationVisibility); }
});
<?php endif; /* GLOBAL ABDM SWITCH */ ?>

function formatABHAId(input) {
    let value = input.value.replace(/\D/g, '').slice(0, 14);

    let formatted = '';

    if (value.length > 0) {
        formatted += value.substring(0, 2);
    }

    if (value.length > 2) {
        formatted += '-' + value.substring(2, 6);
    }

    if (value.length > 6) {
        formatted += '-' + value.substring(6, 10);
    }

    if (value.length > 10) {
        formatted += '-' + value.substring(10, 14);
    }

    input.value = formatted;
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const isFromSlot = <?= json_encode($isFromSlot) ?>;
    const typeDD   = document.getElementById('type_dd');
    const deptDD   = document.getElementById('department_dd');
    const entityDD = document.getElementById('entity_dd');
    const dateDD =
    document.getElementById('appointment_date') ||
    document.querySelector('input[name="appointment_date"]');
const timeDD   = document.getElementById('appointment_time_dd');
    window.tenantId = "<?= isset($_SESSION['tenant_id']) ? $_SESSION['tenant_id'] : '' ?>";
    // Session-bound CSRF token for the ABDM M1 endpoints and the duplicate
    // patient checks. Rendered from the same $_SESSION['csrf'] that the
    // registration form's hidden field carries, so one token serves both the
    // form post and the XHR calls. Never logged, never persisted.
    window.csrfToken = "<?= isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '' ?>";
      function loadSlots() {
    timeDD.innerHTML = '<option value="">Select Slot</option>';
    if (!entityDD.value || !dateDD.value) return;
    // 🔥 LOAD BOOKED SLOTS FIRST
    fetch(`/modules/appointments/ajax/get_booked_slots.php?type=${typeDD.value}&entity_id=${entityDD.value}&date=${dateDD.value}&tenant_id=${window.tenantId}`)
        .then(r => r.json())
        .then(bookedSlots => {
            window.bookedSlots = bookedSlots || [];
           const currentType = typeDD.disabled
    ? "<?= $type ?>"
    : typeDD.value;
if (currentType === 'doctor') {
    loadDoctorSlots();
}
if (currentType === 'service') {
    loadServiceSlots();
}
        });
}
/* ============================
   SLOT MODE → FORCE FEE DISPLAY
============================ */
if (isFromSlot) {
    const feeInput = document.getElementById('fee');
    // 1️⃣ If PHP already gave fee → keep it
    if (feeInput && feeInput.value > 0) {
        // do nothing
    }
    // 2️⃣ Otherwise → fetch fee via SAME ajax used in manual flow
    else if (
    <?= json_encode($type) ?> === 'doctor' &&
    <?= json_encode($entity_id) ?> &&
    <?= $appointmentPaid ?> <= 0
) {
        fetch('/modules/appointments/ajax/get_doctor_fee.php?doctor_id=<?= (int)$entity_id ?>&tenant_id=<?= isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : "" ?>')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'FOUND') {
                    const amount = parseFloat(data.fee) || 0;
document.getElementById('consultation_fee').value = amount;
document.getElementById('fee').value = amount;
forceFullPayment();
                } else {
                    feeInput.value = 0;
                }
            })
            .catch(() => {
                feeInput.value = 0;
            });
    }
    // ⚠️ DO NOT return anymore
}
/* ===============================
   TYPE → CONSULTATION MODE
=============================== */
const consultationModeBox = document.getElementById('consultation_mode_box');
const consultationModeDD = document.getElementById('consultation_mode');

function updateConsultationMode() {
    if (!consultationModeBox) return;

    if (typeDD.value === 'doctor') {
        consultationModeBox.style.display = '';
    } else {
        consultationModeBox.style.display = 'none';

        if (consultationModeDD) {
            consultationModeDD.value = 'PHYSICAL';
        }
    }
}

updateConsultationMode();

typeDD.addEventListener('change', updateConsultationMode);
    /* ===============================
       TYPE → DEPARTMENT
    ================================ */
    typeDD.addEventListener('change', function () {
        applyIPDPaymentMode();
        deptDD.innerHTML   = '<option value="">Loading...</option>';
        entityDD.innerHTML = '<option value="">Select</option>';
        timeDD.innerHTML   = '<option value="">Select Slot</option>';
        
        // IPD
if (this.value === 'ipd') {
    document.getElementById('appointment_time_dd').closest('div').style.display = 'none';
} else {
    document.getElementById('appointment_time_dd').closest('div').style.display = '';
}
const btn = document.getElementById('saveBtn');

if (btn) {
    btn.innerHTML = (this.value === 'ipd')
        ? 'Admit Patient'
        : 'Save Registration';
}

        if (!this.value) {
            deptDD.innerHTML = '<option value="">Select</option>';
            return;
        }
        if (this.value === 'doctor' || this.value === 'ipd') {
            fetch('/modules/appointments/ajax/get_departments.php?type=doctor&tenant_id=' + window.tenantId)
                .then(r => r.json())
                .then(data => {
                    deptDD.innerHTML = '<option value="">Select Department</option>';
                    data.forEach(d => {
                        deptDD.innerHTML += `<option value="${d.id}">${d.department_name}</option>`;
                    });
                });
        }
        if (this.value === 'service') {
            fetch('/modules/appointments/ajax/get_service_categories.php?tenant_id=' + window.tenantId)
                .then(r => r.json())
                .then(data => {
                    deptDD.innerHTML = '<option value="">Select Category</option>';
                    data.forEach(c => {
                        deptDD.innerHTML += `<option value="${c.id}">${c.category_name}</option>`;
                    });
                });
        }
    });
// Capture department name when user selects it
deptDD.addEventListener('change', function () {
    if (!this.value) {
        document.getElementById('department_name_hidden').value = '';
        return;
    }
    const selectedText = this.options[this.selectedIndex].text.trim();
    document.getElementById('department_name_hidden').value = selectedText;
});
    /* ===============================
       DEPARTMENT → ENTITY
    ================================ */
    deptDD.addEventListener('change', function () {
        entityDD.innerHTML = '<option value="">Loading...</option>';
        timeDD.innerHTML   = '<option value="">Select Slot</option>';
        if (!this.value) {
            entityDD.innerHTML = '<option value="">Select</option>';
            return;
        }
        if (typeDD.value === 'doctor' || typeDD.value === 'ipd') {
            fetch(`/modules/appointments/ajax/get_doctors.php?department_id=${this.value}&tenant_id=${window.tenantId}`)
                .then(r => r.json())
                .then(data => {
                    entityDD.innerHTML = '<option value="">Select Doctor</option>';
                    data.forEach(d => {
                        entityDD.innerHTML += `<option value="${d.id}">${d.name}</option>`;
                    });
                });
        }
        if (typeDD.value === 'service') {
            fetch(`/modules/appointments/ajax/get_services.php?category_id=${this.value}&tenant_id=${window.tenantId}`)
                .then(r => r.json())
                .then(data => {
                    entityDD.innerHTML = '<option value="">Select Service</option>';
                    data.forEach(s => {
                        entityDD.innerHTML += `<option value="${s.id}">${s.name}</option>`;
                    });
                });
        }
    });
    
 /* ===============================
   ENTITY + DATE → SLOTS
=============================== */
entityDD.addEventListener('change', function () {
    timeDD.innerHTML = '<option value="">Select Slot</option>';
    if (!this.value) return;
    // =========================
    // AUTO SELECT TODAY DATE
    // =========================
    const appointmentDate = document.getElementById('appointment_date');
    if (appointmentDate) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');

        if (!appointmentDate.value) {
            appointmentDate.value = `${yyyy}-${mm}-${dd}`;
        }
    }
    // =========================
    // LOAD DOCTOR FEE
    // =========================
    if (typeDD.value === 'doctor' && <?= $appointmentPaid ?> <= 0) {
    reloadDoctorConsultationFee();
}
    // =========================
    // LOAD SERVICE FEE
    // =========================
    if (typeDD.value === 'service' && <?= $appointmentPaid ?> <= 0) {
        fetch('/modules/appointments/ajax/get_service_fee.php?service_id=' + this.value + '&tenant_id=' + window.tenantId)
            .then(r => r.json())
            .then(d => {
                const amount = d.status === 'FOUND' ? parseFloat(d.fee) : 0;
                document.getElementById('consultation_fee').value = amount;
                document.getElementById('fee').value = amount;
                forceFullPayment();
            });
    }
    // =========================
    // LOAD SLOTS
    // =========================
    if (typeDD.value !== 'ipd') {
        loadSlots();
    }
});
/* ===============================
   DATE CHANGE → RELOAD SLOTS
=============================== */
dateDD.addEventListener('change', function () {

    timeDD.innerHTML =
        '<option value="">Select Slot</option>';

    /* =========================
       RE-CALCULATE REVISIT FEE
    ========================= */

    const patientId =
        document.getElementById('patient_id')?.value || '';

    const doctorId =
        entityDD?.value ||
        "<?= $entity_id ?? '' ?>";

    const revisit =
        document.getElementById('is_revisit')?.value === '1';

    if (
    doctorId &&
    this.value &&
    typeDD.value === 'doctor'
) {
    reloadDoctorConsultationFee();
}

    /* =========================
       RELOAD SLOTS
    ========================= */

    if (!entityDD.value) {
        return;
    }

    if (typeDD.value !== 'ipd') {
        loadSlots();
    }
});
    /* ===============================
       DOCTOR SLOTS
    ================================ */
    function loadDoctorSlots() {
        fetch(`/modules/appointments/ajax/get_doctor_slots.php?doctor_id=${entityDD.value}&tenant_id=${window.tenantId}`)
            .then(r => r.json())
            .then(data => {
                if (!data[dateDD.value]) return;
                const now   = new Date();
                const today = new Date().toISOString().split('T')[0];
                data[dateDD.value].forEach(slot => {
                    if (slot.status !== 'available') return;
                    if (dateDD.value === today) {
    const appointmentSlot = "<?= $slot ?>";
    const [h, m] = slot.start.split(':').map(Number);
    const t = new Date();
    t.setHours(h, m, 0, 0);
    const slotValue =
        `${slot.start.replace(':00','')} - ${slot.end.replace(':00','')}`;
    // Skip past slots EXCEPT the appointment slot
    if (t <= now && slotValue !== appointmentSlot) {
        return;
    }
}
                    const slotValue =
    `${slot.start.replace(':00','')} - ${slot.end.replace(':00','')}`;
const appointmentSlot = "<?= $slot ?>";
if (
    window.bookedSlots &&
    window.bookedSlots[slotValue] &&
    slotValue !== appointmentSlot
) {
    return; // skip booked slots except the appointment slot
}
const selectedSlot = "<?= trim($slot) ?>";
const isSelected =
    selectedSlot.replace(/:00/g,'').trim() === slotValue.trim();
timeDD.innerHTML +=
`<option value="${slotValue}" ${isSelected ? 'selected' : ''}>
    ${slotValue}
</option>`;
                });
            });
    }
    /* ===============================
       SERVICE SLOTS
    ================================ */
    function loadServiceSlots() {
        fetch(`/modules/appointments/ajax/get_service_time.php?service_id=${entityDD.value}&tenant_id=${window.tenantId}`)
            .then(r => r.json())
            .then(cfg => {
                if (!cfg.start_time || !cfg.close_time || !cfg.slot_minutes) return;
                const today = new Date().toISOString().split('T')[0];
                const now   = new Date();
                let [sh, sm] = cfg.start_time.split(':').map(Number);
                let [eh, em] = cfg.close_time.split(':').map(Number);
                let slotMinutes = parseInt(cfg.slot_minutes);
                let cur = new Date();
                cur.setHours(sh, sm, 0, 0);
                let end = new Date();
                end.setHours(eh, em, 0, 0);
                while (cur < end) {
                    let next = new Date(cur.getTime() + slotMinutes * 60000);
                    if (dateDD.value === today && cur <= now) {
                        cur = next;
                        continue;
                    }
                    const s = cur.toTimeString().slice(0,5);
                    const e = next.toTimeString().slice(0,5);
                    const slotValue = `${s} - ${e}`;
const appointmentSlot = "<?= $slot ?>";
if (
    window.bookedSlots &&
    window.bookedSlots[slotValue] &&
    slotValue !== appointmentSlot
) {
    continue;
}
const selectedSlot = "<?= $slot ?>";
timeDD.innerHTML +=
`<option value="${slotValue}"
    ${slotValue === selectedSlot ? 'selected' : ''}>
    ${slotValue}
</option>`;


                    cur = next;
                }
            });
    }

});

/* STEP 4 : SLOT 
setTimeout(function(){
    if(APPT_SLOT){
        slotDD.value = APPT_SLOT;
    }
},500);*/
</script>
<script>
let isUpdating = false;

/* =========================
   DOB → AGE
========================= */
function dobToAge() {
    if (isUpdating) return;

    const dobInput = document.getElementById('dob');
    const y = document.getElementById('age_y');
    const m = document.getElementById('age_m');
    const d = document.getElementById('age_d');

    if (!dobInput.value) return;
const dob = new Date(dobInput.value);
const today = new Date();
if (dob > today) {
    alert("DOB can't be a future date");
    dobInput.value = '';
    return;
}
isUpdating = true;
    let years  = today.getFullYear() - dob.getFullYear();
    let months = today.getMonth() - dob.getMonth();
    let days   = today.getDate() - dob.getDate();

    if (days < 0) {
        months--;
        const prevMonth = new Date(today.getFullYear(), today.getMonth(), 0);
        days += prevMonth.getDate();
    }

    if (months < 0) {
        years--;
        months += 12;
    }

    y.value = years >= 0 ? years : '';
    m.value = months >= 0 ? months : '';
    d.value = days >= 0 ? days : '';

    isUpdating = false;
}

/* =========================
   AGE → DOB
========================= */
function ageToDob() {
    if (isUpdating) return;

    const dobInput = document.getElementById('dob');
    const y = document.getElementById('age_y');
    const m = document.getElementById('age_m');
    const d = document.getElementById('age_d');

    const years  = parseInt(y.value || 0);
    const months = parseInt(m.value || 0);
    const days   = parseInt(d.value || 0);

    if (!years && !months && !days) return;

    isUpdating = true;

    const today = new Date();
    let dob = new Date(today);

    dob.setFullYear(dob.getFullYear() - years);
    dob.setMonth(dob.getMonth() - months);
    dob.setDate(dob.getDate() - days);

    dobInput.value = dob.toISOString().split('T')[0];

    isUpdating = false;
}
</script>



<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- ADD CITY MODAL -->
<div class="city-modal" id="cityModal">
    <div class="city-modal-box">
        <div class="city-modal-header">
            <div class="city-modal-title">
                <span class="city-modal-icon">📍</span>
                <div>
                    <h3>Add City</h3>
                    <p>Add a new city to the patient master</p>
                </div>
            </div>
            <button type="button" class="city-modal-close" onclick="closeCityModal()" aria-label="Close" title="Close">×</button>
        </div>
        <div class="city-modal-body">
            <div class="city-modal-grid">
                <div>
                    <label>Country</label>
                    <select id="cm_country">
                        <option value="">Select</option>
                    </select>
                </div>
                <div>
                    <label>State</label>
                    <select id="cm_state">
                        <option value="">Select</option>
                    </select>
                </div>
                <div>
                    <label>District</label>
                    <select id="cm_district">
                        <option value="">Select</option>
                    </select>
                </div>
                <div>
                    <label>City Name</label>
                    <input type="text" id="cm_city" placeholder="Enter city name" autocomplete="off">
                </div>
            </div>
        </div>
        <div class="city-modal-footer">
            <button type="button" class="btn-secondary" onclick="closeCityModal()">Cancel</button>
            <button type="button" class="btn-primary" onclick="saveCity()">Save</button>
        </div>
    </div>
</div>
</div>
</form>
<!-- ================= REVISIT PATIENT MODAL ================= -->
<div id="revisitModal" class="revisit-modal">
    <div class="revisit-modal-box">
        <div class="revisit-modal-header">
            <div>
                <h3>🔄 Select Existing Patient</h3>
                <div class="revisit-modal-subtitle">Search by name, mobile or Aadhaar</div>
            </div>
            <button type="button" class="revisit-modal-close" onclick="closeRevisitModal()">×</button>
        </div>
        <div class="revisit-modal-body">
            <div class="revisit-search-row">
                <input type="text" id="revisitSearch" placeholder="Search Name / Mobile / Aadhaar">
                <button type="button" onclick="loadRevisitPatients(1)">Search</button>
            </div>
            <div class="revisit-table-wrap">
                <table id="revisitPatientTable">
                    <thead>
                        <tr>
                            <th style="width:70px;text-align:center;">Select</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th>Aadhaar</th>
                        </tr>
                    </thead>
                    <tbody id="revisitPatientTableBody">
                        <tr>
                            <td colspan="4" style="text-align:center;">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="revisit-pagination">
                <button type="button" id="revisitPrevBtn" onclick="changeRevisitPage(-1)">Previous</button>
                <span id="revisitPageInfo">Page 1</span>
                <button type="button" id="revisitNextBtn" onclick="changeRevisitPage(1)">Next</button>
            </div>
        </div>
    </div>
</div>
<script>
const APPOINTMENT_SOURCE = "<?= $source ?>";
let isRevisit = <?= !empty($revisitPatient) ? 'true' : 'false' ?>;
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){

    <?php if(!empty($revisitPatient)): ?>

document.getElementById('revisit').checked = true;

document.getElementById('is_revisit').value = 1;

setTimeout(function () {

    reloadDoctorConsultationFee();

}, 800);

<?php endif; ?>

});
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){

    <?php if(!empty($revisitPatient)): ?>

    fetch('/modules/appointments/ajax/get_visit_count.php?patient_id=<?= $revisitPatient['id'] ?>&tenant_id=<?= isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : "" ?>')
        .then(r => r.json())
        .then(d => {

            document.getElementById('visit_count').value = d.count ?? 0;

            document.getElementById('visit_count_box').style.display = 'flex';

        });

    <?php endif; ?>

});
</script>


<script>
function openRevisitModal() {
    document.getElementById('revisitModal').style.display = 'flex';
    const search = document.getElementById('revisitSearch');
    if (search) search.value = '';
    loadRevisitPatients(1);
    setTimeout(() => search?.focus(), 50);
}

function closeRevisitModal() {
    document.getElementById('revisitModal').style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('revisitSearch');
    if (!search) return;
    search.addEventListener('input', function () {
        clearTimeout(revisitSearchTimer);
        revisitSearchTimer = setTimeout(function () {
            loadRevisitPatients(1);
        }, 350);
    });
    search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(revisitSearchTimer);
            loadRevisitPatients(1);
        }
    });
});
</script>


<script>
document.addEventListener('change', function (e) {

    if (APPOINTMENT_SOURCE !== 'INTERNAL') return;

    if (!e.target || e.target.id !== 'revisit') {
        return;
    }

    const doctorId =
        document.getElementById('entity_dd')?.value ||
        "<?= $entity_id ?? '' ?>";

    /* =========================
       REVISIT CHECKED
    ========================= */

    if (e.target.checked) {

        if (!doctorId) {
            alert('Please select doctor first');
            e.target.checked = false;
            return;
        }

        document.getElementById('is_revisit').value = 1;

        /*
         * Emergency has priority.
         * Do NOT replace Emergency charge.
         */
        const emergency =
            document.getElementById('emergency')?.checked;

        if (emergency) {

            console.log(
                'Revisit selected while Emergency is active.'
            );

            /*
             * Keep Emergency charge.
             */
            loadEmergencyDoctorFee(doctorId);

            return;
        }

        openRevisitModal();

        return;
    }

    /* =========================
       REVISIT UNCHECKED
    ========================= */

    document.getElementById('is_revisit').value = 0;

    /*
     * If Emergency is still checked,
     * Emergency charge remains active.
     */
    const emergency =
        document.getElementById('emergency')?.checked;

    if (emergency) {

        loadEmergencyDoctorFee(doctorId);

        return;
    }

    /*
     * Revisit OFF + Emergency OFF
     * → Normal charge
     */
    if (doctorId) {
        loadNormalDoctorFee(doctorId);
    }

});

</script>
<script>
let revisitCurrentPage = 1;
let revisitTotalPages = 1;
let revisitSearchTimer = null;

function loadRevisitPatients(page = 1) {
    const tbody = document.getElementById('revisitPatientTableBody');
    const search = document.getElementById('revisitSearch')?.value.trim() || '';
    revisitCurrentPage = page;
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Loading...</td></tr>';
    fetch('/modules/appointments/ajax/get_patients_list.php?page=' + page + '&search=' + encodeURIComponent(search) + '&tenant_id=' + window.tenantId)
        .then(res => {
            if (!res.ok) throw new Error('Patient request failed: ' + res.status);
            return res.json();
        })
        .then(data => {
            console.log('REGISTER PATIENT REVISIT:', data);
            if (!data || !Array.isArray(data.data)) {
                throw new Error('Invalid patient response');
            }
            revisitTotalPages = parseInt(data.total_pages) || 1;
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">No patients found</td></tr>';
                updateRevisitPagination();
                return;
            }
            let html = '';
            data.data.forEach(p => {
                const fullName = ((p.first_name || '') + ' ' + (p.last_name || '')).trim();
                html += `
                    <tr>
                        <td style="text-align:center;">
                            <input type="radio" name="revisit_select" value="${p.id}">
                        </td>
                        <td>${escapeRevisitHtml(fullName)}</td>
                        <td>${escapeRevisitHtml(p.mobile || '')}</td>
                        <td>${escapeRevisitHtml(p.aadhaar || '')}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
            updateRevisitPagination();
        })
        .catch(err => {
            console.error('Register Patient revisit loading error:', err);
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Failed to load patients</td></tr>';
            revisitTotalPages = 1;
            updateRevisitPagination();
        });
}

function updateRevisitPagination() {
    const prevBtn = document.getElementById('revisitPrevBtn');
    const nextBtn = document.getElementById('revisitNextBtn');
    const pageInfo = document.getElementById('revisitPageInfo');
    prevBtn.disabled = revisitCurrentPage <= 1;
    nextBtn.disabled = revisitCurrentPage >= revisitTotalPages;
    pageInfo.textContent = 'Page ' + revisitCurrentPage + ' of ' + revisitTotalPages;
}

function changeRevisitPage(direction) {
    const nextPage = revisitCurrentPage + direction;
    if (nextPage < 1 || nextPage > revisitTotalPages) return;
    loadRevisitPatients(nextPage);
}

function escapeRevisitHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
function loadEmergencyDoctorFee(doctorUserId) {

    if (!doctorUserId) {
        return;
    }

    fetch(
        '/modules/appointments/ajax/get_doctor_emergency_fee.php?doctor_id=' +
        encodeURIComponent(doctorUserId)
    )
    .then(res => res.json())
    .then(data => {

        console.log('EMERGENCY CHARGE RESPONSE:', data);

        const consultationFee =
            document.getElementById('consultation_fee');

        const feeInput =
            document.getElementById('fee');

        if (!consultationFee || !feeInput) {
            return;
        }

        if (data.status === 'FOUND') {

            const amount =
                parseFloat(data.fee) || 0;

            consultationFee.value =
                amount.toFixed(2);

            feeInput.value =
                amount.toFixed(2);

            forceFullPayment();

        } else {

            consultationFee.value = '0.00';
            feeInput.value = '0.00';

            forceFullPayment();

            console.log('Emergency charge not found.');
        }
    })
    .catch(err => {

        console.error(
            'Emergency charge error:',
            err
        );
    });
}
document.addEventListener('DOMContentLoaded', function () {

    const emergencyCheckbox =
        document.getElementById('emergency');

    const revisitCheckbox =
        document.getElementById('revisit');

    const entityDD =
        document.getElementById('entity_dd');

    if (!emergencyCheckbox) {
        return;
    }

    emergencyCheckbox.addEventListener('change', function () {

        const doctorId =
            entityDD?.value ||
            "<?= $entity_id ?? '' ?>";

        if (!doctorId) {
            this.checked = false;
            alert('Please select doctor first.');
            return;
        }

        if (this.checked) {

    /*
     * Emergency does NOT remove Revisit.
     * Both can remain selected.
     */

    loadEmergencyDoctorFee(doctorId);


        } else {

    /*
     * Emergency OFF.
     * Let the central fee logic decide:
     * Revisit → Revisit fee
     * Otherwise → Normal fee
     */
    reloadDoctorConsultationFee();

}
    });

});
</script>
<script>
document.addEventListener('change', function (e) {

 // ❌ Stop revisit for WEBSITE
    if (APPOINTMENT_SOURCE !== 'INTERNAL') return;
    
    if (e.target.name !== 'revisit_select') return;
    
    const patientId = e.target.value;
    
    
    // mark revisit
    isRevisit = true;
    
    document.getElementById('patient_id').value = patientId;
document.getElementById('is_revisit').value = 1;
document.getElementById('revisit').checked = true;

// 🔥 Load appropriate charge
const doctorUserId =
    document.getElementById('entity_dd')?.value ||
    "<?= $entity_id ?? '' ?>";

const emergency =
    document.getElementById('emergency')?.checked;

if (emergency) {

    /*
     * Emergency always has priority.
     * Revisit remains selected but does not
     * replace the Emergency consultation fee.
     */
    loadEmergencyDoctorFee(doctorUserId);

} else {

    loadRevisitCharge(
        doctorUserId,
        patientId
    );
}

    fetch('/modules/appointments/ajax/get_patient_details.php?id=' + patientId + '&tenant_id=' + window.tenantId)
        .then(res => res.json())
        .then(p => {
            if (!p) return;
            document.getElementById('title').value = p.title ?? 'Mr';
            document.getElementById('first_name').value = p.first_name ?? '';
            document.getElementById('last_name').value = p.last_name ?? '';
            document.getElementById('marital').value = p.marital_status ?? '';
            let gender = (p.sex || '').toUpperCase();

if (gender === 'M') {
    gender = 'Male';
} else if (gender === 'F') {
    gender = 'Female';
}

setGenderSafely(gender);
            document.getElementById('guardian_name').value = p.guardian_name ?? '';
            /*
             * The existing-patient picker switches the entire form to the
             * selected patient, so the ABHA field must follow that patient:
             * populate it when the endpoint returns one, and clear it when the
             * selected patient has none.
             *
             * Clearing matters. This form is also reachable as a server-side
             * revisit (?revisit_patient_id=N), which renders the ABHA of that
             * patient. If the operator then picked a different patient who has
             * no ABHA, preserving the old value would submit the previous
             * patient's ABHA against the newly selected one.
             *
             * This block runs only for an existing-patient selection: the
             * handler returns early unless e.target.name === 'revisit_select'.
             * A network failure never reaches it - the .catch() at the end of
             * this chain reports the error and leaves the field untouched.
             */
            document.getElementById('abha_id').value = p.abha_id ?? '';
            document.getElementById('aadhaar').value = p.aadhaar ?? '';
            document.getElementById('mobile').value = p.mobile ?? '';
            document.getElementById('phone').value = p.phone ?? '';
            document.getElementById('email').value = p.email ?? '';
            document.getElementById('dob').value = p.dob ?? '';
            // Always calculate latest age from DOB
            dobToAge();
            document.getElementById('city').value = p.city_id ?? '';
            document.getElementById('pincode').value = p.pincode ?? '';
            document.getElementById('address').value = p.address ?? '';

            // Update Select2 city
            $('#city').trigger('change');

            // Calculate age from DOB
            dobToAge();

   /* ===============================
                🔥 LOAD REVISIT COUNT
            =============================== */
            fetch('/modules/appointments/ajax/get_visit_count.php?patient_id=' + patientId + '&tenant_id=' + window.tenantId)
                .then(r => r.json())
                .then(d => {
                    if (d && d.count !== undefined) {
                        document.getElementById('visit_count').value = d.count;
                        document.getElementById('visit_count_box').style.display = 'flex';
                    } else {
                        document.getElementById('visit_count').value = '0';
                        document.getElementById('visit_count_box').style.display = 'flex';
                    }
                })
                .catch(err => {
                    console.error('Error loading visit count:', err);
                    document.getElementById('visit_count').value = 'Error';
                    document.getElementById('visit_count_box').style.display = 'flex';
                });

            closeRevisitModal();
            

        })
        .catch(err => console.error('Error loading patient details:', err));
});
</script>
<script>
function setGenderSafely(genderValue) {
    const genderDD = document.getElementById('sex');
    if (!genderValue) return;

    let gender = genderValue.toString().trim().toUpperCase();

    if (gender === 'M') {
        gender = 'Male';
    } else if (gender === 'F') {
        gender = 'Female';
    } else if (gender === 'O') {
        gender = 'Other';
    } else if (gender === 'MALE') {
        gender = 'Male';
    } else if (gender === 'FEMALE') {
        gender = 'Female';
    } else if (gender === 'OTHER') {
        gender = 'Other';
    }

    genderDD.value = gender;
}
</script>

<script>
function updateBalanceOnFeeChange() {
    const feeInput  = document.getElementById('fee');
    const paidInput = document.getElementById('paid');
    const balInput  = document.getElementById('balance');

    if (!feeInput || !balInput) return;

    const fee  = parseFloat(feeInput.value) || 0;
    const paid = paidInput ? (parseFloat(paidInput.value) || 0) : 0;

    let balance = fee - paid;
    if (balance < 0) balance = 0;

    balInput.value = balance.toFixed(2);
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const source     = "<?= $source ?>";
    const isFromSlot = <?= json_encode($isFromSlot) ?>;

    // ✅ INTERNAL + SLOT → balance = full fee
    if (source === 'INTERNAL' && isFromSlot) {
        const feeInput = document.getElementById('fee');
        const balInput = document.getElementById('balance');

        if (feeInput && balInput) {
            const fee = parseFloat(feeInput.value) || 0;
            balInput.value = fee.toFixed(2);
        }
    }
});
</script>


<script>
document.getElementById('aadhaar').addEventListener('blur', function () {

    const aadhaar = this.value.trim();
    if (!aadhaar) return;

    // POST with the session CSRF token: the Aadhaar must not travel in a URL,
    // where it would be captured in server access logs and browser history.
    const aadhaarBody = 'aadhaar=' + encodeURIComponent(aadhaar) +
        '&csrf=' + encodeURIComponent(window.csrfToken);

    fetch('/modules/appointments/ajax/check_patient_by_aadhaar.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: aadhaarBody
    })
        .then(res => res.json())
        .then(data => {

            if (!data.found) return;

            const p = data.patient;

            alert('Aadhaar already exists. Loading existing patient.');
            
            // 🔥 LOAD REVISIT COUNT (AADHAAR FLOW)
fetch('/modules/appointments/ajax/get_visit_count.php?patient_id=' + p.id + '&tenant_id=' + window.tenantId)
    .then(r => r.json())
    .then(d => {
        document.getElementById('visit_count').value = d.count ?? 0;
        document.getElementById('visit_count_box').style.display = 'block';
    })
    .catch(() => {
        document.getElementById('visit_count').value = '0';
        document.getElementById('visit_count_box').style.display = 'block';
    });


            document.getElementById('revisit').checked = true;


            // Force revisit
            document.getElementById('is_revisit').value = 1;
            document.getElementById('patient_id').value = p.id;

const doctorId =
    document.getElementById('entity_dd')?.value ||
    "<?= $entity_id ?? '' ?>";

if (doctorId) {
    reloadDoctorConsultationFee();
}

            // Auto-fill
            document.getElementById('title').value = p.title;
            document.getElementById('first_name').value = p.first_name;
            document.getElementById('last_name').value = p.last_name;
            document.getElementById('sex').value = p.sex;
            document.getElementById('guardian_name').value = p.guardian_name;
            document.getElementById('mobile').value = p.mobile;
            document.getElementById('phone').value = p.phone;
            document.getElementById('email').value = p.email;
            document.getElementById('dob').value = p.dob;
            const citySelect = $('#city');
            citySelect.val(p.city_id).trigger('change.select2');
            document.getElementById('pincode').value = p.pincode;
            document.getElementById('address').value = p.address;

            

        });
});
</script>


           
<script>
/* ===============================
                🔥 Check patient from mobile no
document.getElementById('mobile').addEventListener('blur', function () {

    const mobile = this.value.trim();
    if (!mobile) return;

    fetch('/modules/appointments/ajax/check_patient_by_mobile.php?mobile=' + mobile + '&tenant_id=' + window.tenantId)
        .then(res => res.json())
        .then(data => {
            if (data.exists) {
                confirm(
                    'This mobile number already exists.\n\nClick OK to continue anyway,\nCancel to change number.'
                );
            }
        });
});
=============================== */
</script>
 <script>
document.getElementById('aadhaar').addEventListener('blur', function () {

    const val = this.value.trim();

    if (val === '') return; // allow empty

    if (!/^\d{12}$/.test(val)) {
        alert('Aadhaar number must be exactly 12 digits (numbers only).');
        this.focus();
        this.value = '';
        return;
    }
});
</script>
<script>
document.getElementById('appointmentForm').addEventListener('submit', function (e) {
    e.preventDefault(); // stop submit first
    let hasError = false;
    // 🔄 reset previous errors
    document.querySelectorAll('.field-error').forEach(el => {
        el.classList.remove('field-error');
    });
    /* =========================
       HELPERS
    ========================== */
    function markError(el) {
        if (!el) return;
        el.classList.add('field-error');
        hasError = true;
    }
    /* =========================
       APPOINTMENT VALIDATION
    ========================== */
    const typeDD = document.getElementById('type_dd');
    if (typeDD && !typeDD.value) markError(typeDD);
    /* =========================
   DEPARTMENT VALIDATION
========================= */
const deptDD = document.getElementById('department_dd');
if (deptDD && !<?= json_encode($isFromSlot) ?>) {
    if (!deptDD.value) {
        markError(deptDD);
        // 🔥 Select2 highlight
        const deptSelect2 = deptDD.closest('.select2-hidden-accessible')
            ? deptDD.nextElementSibling
            : null;
        if (deptSelect2) {
            deptSelect2.querySelector('.select2-selection')
                ?.classList.add('field-error');
        }
    }
}
    const entityDD = document.getElementById('entity_dd');
    if (entityDD && !entityDD.value && !<?= json_encode($isFromSlot) ?>) {
        markError(entityDD);
    }

    const apptDate =
    document.getElementById('appointment_date') ||
    document.querySelector('input[name="appointment_date"]');
    if (!apptDate || !apptDate.value) {
    if (apptDate) markError(apptDate);
    hasError = true;
}


    const timeDD = document.getElementById('appointment_time_dd');
    
    if (typeDD.value === 'ipd') {
    timeDD.closest('div').style.display = 'none';
}

if (typeDD.value !== 'ipd') {
    if (timeDD && !timeDD.value)
        markError(timeDD);
}

    /* =========================
       PATIENT VALIDATION
    ========================== */

    const firstName = document.getElementById('first_name');
    if (!firstName.value.trim()) markError(firstName);

    const mobile = document.getElementById('mobile');

if (!/^[0-9]{10}$/.test(mobile.value.trim())) {
    markError(mobile);
}

    const city = document.getElementById('city');
    if (!city.value) {
        markError(city);
        $('#city').next('.select2').find('.select2-selection')
            .addClass('field-error');
    }
    /* =========================
       AADHAAR VALIDATION (OPTIONAL)
    ========================== */
    const aadhaar = document.getElementById('aadhaar');
    if (aadhaar.value.trim() !== '' && !/^\d{12}$/.test(aadhaar.value.trim())) {
        markError(aadhaar);
    }
    /* =========================
       DOB / AGE VALIDATION
    ========================== */
    const dob  = document.getElementById('dob').value;
    const ageY = document.getElementById('age_y').value;
    const ageM = document.getElementById('age_m').value;
    const ageD = document.getElementById('age_d').value;

    if (!dob && !ageY && !ageM && !ageD) {
        markError(document.getElementById('dob'));
        markError(document.getElementById('age_y'));
    }
    /* =========================
       FINAL DECISION
    ========================== */
    if (hasError) {
        // scroll to first error
        const firstError = document.querySelector('.field-error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstError.focus();
        }
        return;
    }
    // ✅ ALL GOOD — SUBMIT
    this.submit();
});
</script>
<script>
function syncBalanceWithFee() {
    const fee  = parseFloat(document.getElementById('fee')?.value) || 0;
    const paid = parseFloat(document.getElementById('paid')?.value) || 0;
    const bal  = document.getElementById('balance');
    if (!bal) return;
    let balance = fee - paid;
    if (balance < 0) balance = 0;
    bal.value = balance.toFixed(2);
}
function applyAppointmentPaymentLock() {

    const appointmentPaymentReceived =
        <?= json_encode($appointmentPaymentReceived) ?>;

    if (!appointmentPaymentReceived) {
        return;
    }

    const fields = [
        'empanelment_id',
        'discount_id',
        'discount_percent',
        'discount_amount',
        'is_camp',
        'payment_mode'
    ];

    fields.forEach(function(id) {

        const field = document.getElementById(id);

        if (!field) {
            return;
        }

        field.disabled = true;

        if (field.type === 'checkbox') {
            field.disabled = true;

            const label = field.closest('label');

            if (label) {
                label.classList.add('appointment-payment-locked');
            }
        }
    });
}
</script>
<script>
function loadRevisitCharge(doctorUserId, patientId) {

    if (!doctorUserId || !patientId) {
        return;
    }

    const dateInput =
        document.getElementById('appointment_date') ||
        document.querySelector('input[name="appointment_date"]');

    const appointmentDate =
        dateInput ? dateInput.value : '';

    if (!appointmentDate) {
        console.warn('Revisit charge: appointment date not available.');
        return;
    }

    const url =
        '/modules/appointments/ajax/get_doctor_revisit_charge.php' +
        '?doctor_id=' + encodeURIComponent(doctorUserId) +
        '&patient_id=' + encodeURIComponent(patientId) +
        '&appointment_date=' + encodeURIComponent(appointmentDate);

    console.log('REVISIT CHARGE REQUEST:', url);

    fetch(url)
        .then(res => res.json())
        .then(data => {

            console.log('REVISIT CHARGE RESPONSE:', data);

            const consultationFee =
                document.getElementById('consultation_fee');

            const feeInput =
                document.getElementById('fee');

            if (!consultationFee || !feeInput) {
                return;
            }

            if (data.status === 'FOUND') {

                const amount =
                    parseFloat(data.fee) || 0;

                consultationFee.value =
                    amount.toFixed(2);

                feeInput.value =
                    amount.toFixed(2);

                forceFullPayment();

                console.log(
                    'REVISIT FEE APPLIED:',
                    amount,
                    'Days:',
                    data.days,
                    'Previous Visit:',
                    data.previous_visit_date,
                    'Appointment Date:',
                    data.appointment_date
                );

            } else {

                console.log(
                    'NO MATCHING REVISIT SLAB:',
                    data
                );

                loadNormalDoctorFee(doctorUserId);
            }
        })
        .catch(err => {

            console.error(
                'Revisit charge error:',
                err
            );

            loadNormalDoctorFee(doctorUserId);
        });
}
/* 🔁 NORMAL fallback (reuse your existing logic) */
function loadNormalDoctorFee(doctorUserId) {
    fetch(`/modules/appointments/ajax/get_doctor_fee.php?doctor_id=${doctorUserId}&tenant_id=${window.tenantId}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'FOUND') {
                const amount = parseFloat(data.fee) || 0;
            document.getElementById('consultation_fee').value = amount;
            document.getElementById('fee').value = amount;
            forceFullPayment();
            }
        });
}

function reloadDoctorConsultationFee() {

    const doctorId =
        document.getElementById('entity_dd')?.value ||
        "<?= $entity_id ?? '' ?>";

    const patientId =
        document.getElementById('patient_id')?.value || '';

    const emergency =
        document.getElementById('emergency')?.checked === true;

    const revisit =
        document.getElementById('is_revisit')?.value === '1';

    if (!doctorId) {
        return;
    }

    /* =========================
       EMERGENCY HAS PRIORITY
    ========================= */

    if (emergency) {

        console.log('FEE MODE: EMERGENCY');

        loadEmergencyDoctorFee(doctorId);

        return;
    }

    /* =========================
       REVISIT
    ========================= */

    if (revisit && patientId) {

        console.log('FEE MODE: REVISIT');

        loadRevisitCharge(
            doctorId,
            patientId
        );

        return;
    }

    /* =========================
       NORMAL
    ========================= */

    console.log('FEE MODE: NORMAL');

    loadNormalDoctorFee(doctorId);
}
</script>
<script>
function forceFullPayment() {
    const feeInput  = document.getElementById('fee');
    const paidInput = document.getElementById('paid');
    const balInput  = document.getElementById('balance');
    if (!feeInput || !paidInput || !balInput) return;
    const fee = parseFloat(feeInput.value) || 0;
    paidInput.value = fee.toFixed(2);
    balInput.value  = '0.00';
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    applyIPDPaymentMode();

    if (document.getElementById('type_dd')?.value !== 'ipd') {
        forceFullPayment();
    }

    applyAppointmentPaymentLock();

    const firstField = document.getElementById('type_dd');

    if (firstField) {
        setTimeout(() => {
            firstField.focus();
        }, 200);
    }
});
</script>
<script>
$(document).ready(function () {
    $('#city').select2({
        placeholder: 'Select City',
        allowClear: true,
        width: '100%'
    });
    // 🔥 Auto-focus search box when dropdown opens
    $('#city').on('select2:open', function () {
        setTimeout(function () {
            document.querySelector('.select2-container--open .select2-search__field')?.focus();
        }, 0);
    });
});
</script>
<script>
/* CITY MODAL ELEMENTS */
const cm_country  = document.getElementById('cm_country');
const cm_state    = document.getElementById('cm_state');
const cm_district = document.getElementById('cm_district');
const cm_city     = document.getElementById('cm_city');
</script>
<script>
function openCityModalFromForm() {
    document.getElementById('cityModal').style.display = 'flex';

    // reset fields
    cm_country.innerHTML  = '<option value="">Select</option>';
    cm_state.innerHTML    = '<option value="">Select</option>';
    cm_district.innerHTML = '<option value="">Select</option>';
    cm_city.value = '';

    loadCountries();
}

function closeCityModal() {
    document.getElementById('cityModal').style.display = 'none';
}
</script>
<script>
function loadCountries() {
    fetch('/modules/appointments/ajax/get_countries.php?tenant_id=' + window.tenantId)
        .then(r => r.json())
        .then(d => {
            cm_country.innerHTML = '<option value="">Select</option>';
            d.forEach(c => {
                cm_country.innerHTML +=
                    `<option value="${c.id}">${c.name}</option>`;
            });
        });
}
cm_country.addEventListener('change', function () {
    if (!this.value) return;
    fetch('/modules/appointments/ajax/get_states.php?country_id=' + this.value + '&tenant_id=' + window.tenantId)
        .then(r => r.json())
        .then(d => {
            cm_state.innerHTML = '<option value="">Select</option>';
            cm_district.innerHTML = '<option value="">Select</option>';
            d.forEach(s => {
                cm_state.innerHTML +=
                    `<option value="${s.id}">${s.name}</option>`;
            });
        });
});
cm_state.addEventListener('change', function () {
    if (!this.value) return;
    fetch('/modules/appointments/ajax/get_districts.php?state_id=' + this.value + '&tenant_id=' + window.tenantId)
        .then(r => r.json())
        .then(d => {
            cm_district.innerHTML = '<option value="">Select</option>';
            d.forEach(x => {
                cm_district.innerHTML +=
                    `<option value="${x.id}">${x.name}</option>`;
            });
        });
});
</script>
<script>
function saveCity() {
    const cityName = cm_city.value.trim();
    if (!cm_district.value || !cityName) {
        alert('Please select district and enter city name');
        return;
    }
    fetch('/modules/appointments/ajax/save_city.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `district_id=${cm_district.value}&city=${encodeURIComponent(cityName)}&tenant_id=${window.tenantId}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.id) {
            // 🔥 Add city to dropdown & select it
            const opt = new Option(d.name, d.id, true, true);
            $('#city').append(opt).trigger('change');
            closeCityModal();
        }
    });
}
</script>
<script>
let abhaFetchTxnId = '';
let abhaFetchXToken = '';
let registrationMobileTxnId = '';
let registrationABHAProfile = null;

function fetchABHADetails() {
    const abha = document.getElementById('abha_id').value.trim();

    if (!abha) {
        alert('Please enter ABHA ID');
        return;
    }

    fetch('/modules/registration/ajax/get_abha_details.php?abha_id=' + encodeURIComponent(abha))
        .then(res => res.json())
        .then(data => {
            if (data.status) {
                fillLocalABHADetails(data.patient, abha);
                return;
            }

            requestABHAFetchOTP(abha);
        })
        .catch(err => {
            console.error(err);
            requestABHAFetchOTP(abha);
        });
}

function fillLocalABHADetails(p, abha) {
    let dob = String(p.dob || '').trim();
    let yearOfBirth = '';
    let monthOfBirth = '';
    let dayOfBirth = '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(dob)) {
        const parts = dob.split('-');
        yearOfBirth = parts[0];
        monthOfBirth = parts[1];
        dayOfBirth = parts[2];
    } else if (/^\d{2}-\d{2}-\d{4}$/.test(dob)) {
        const parts = dob.split('-');
        dayOfBirth = parts[0];
        monthOfBirth = parts[1];
        yearOfBirth = parts[2];
    }
    registrationABHAProfile = {
        ABHANumber: abha,
        firstName: p.first_name || '',
        lastName: p.last_name || '',
        name: [
            p.first_name || '',
            p.last_name || ''
        ].filter(Boolean).join(' '),
        gender: p.sex || '',
        mobile: p.mobile || '',
        email: p.email || '',
        aadhaar: p.aadhaar || '',
        address: p.address || '',
        pincode: p.pincode || '',
        yearOfBirth: yearOfBirth,
        monthOfBirth: monthOfBirth,
        dayOfBirth: dayOfBirth,
        dob: dob
    };
    fillABHAProfile(registrationABHAProfile);
    const viewCardButton =
        document.getElementById('viewABHACardButton');
    if (viewCardButton) {
        viewCardButton.style.display = 'inline-flex';
    }
}
function requestABHAFetchOTP(abha) {
    const modal = document.getElementById('fetchABHAModal');

    if (!modal) {
        alert('ABDM Fetch OTP modal not found.');
        return;
    }

    document.getElementById('abha_fetch_otp').value = '';
    document.getElementById('abhaFetchMessage').innerHTML =
        '<div class="alert alert-info">Sending OTP to Aadhaar registered mobile...</div>';

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    fetch('/modules/abdm/abha_fetch_request_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'abha_id=' + encodeURIComponent(abha) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('abhaFetchMessage').innerHTML =
                '<div class="alert alert-danger">' +
                (data.message || 'Unable to send OTP.') +
                '</div>';
            return;
        }

        abhaFetchTxnId = data.txnId || '';

        if (!abhaFetchTxnId) {
            document.getElementById('abhaFetchMessage').innerHTML =
                '<div class="alert alert-danger">Transaction ID was not returned.</div>';
            return;
        }

        document.getElementById('abhaFetchMessage').innerHTML =
            '<div class="alert alert-success">' +
            (data.message || 'OTP sent successfully.') +
            '</div>';

        document.getElementById('abha_fetch_otp').focus();
    })
    .catch(error => {
        console.error(error);
        document.getElementById('abhaFetchMessage').innerHTML =
            '<div class="alert alert-danger">Unable to connect to ABDM.</div>';
    });
}

function verifyABHAFetchOTP() {
    const otp = document.getElementById('abha_fetch_otp').value.trim();

    if (!/^\d{6}$/.test(otp)) {
        document.getElementById('abhaFetchMessage').innerHTML =
            '<div class="alert alert-danger">Please enter a valid 6 digit OTP.</div>';
        return;
    }

    if (!abhaFetchTxnId) {
        document.getElementById('abhaFetchMessage').innerHTML =
            '<div class="alert alert-danger">Transaction expired. Please request OTP again.</div>';
        return;
    }

    document.getElementById('abhaFetchMessage').innerHTML =
        '<div class="alert alert-info">Verifying OTP...</div>';

    fetch('/modules/abdm/abha_fetch_verify_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body:
            'txnId=' + encodeURIComponent(abhaFetchTxnId) +
            '&otp=' + encodeURIComponent(otp) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('abhaFetchMessage').innerHTML =
                '<div class="alert alert-danger">' +
                (data.message || 'OTP verification failed.') +
                '</div>';
            return;
        }

        abhaFetchXToken = data.xToken || '';

        if (!abhaFetchXToken) {
            document.getElementById('abhaFetchMessage').innerHTML =
                '<div class="alert alert-danger">X-token was not returned.</div>';
            return;
        }

        document.getElementById('abhaFetchMessage').innerHTML =
            '<div class="alert alert-info">OTP verified. Fetching ABHA profile...</div>';

        fetchABHAProfile();
    })
    .catch(error => {
        console.error(error);
        document.getElementById('abhaFetchMessage').innerHTML =
            '<div class="alert alert-danger">Unable to connect to ABDM.</div>';
    });
}

function fetchABHAProfile() {
    fetch('/modules/abdm/abha_fetch_profile.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'xToken=' + encodeURIComponent(abhaFetchXToken) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('abhaFetchMessage').innerHTML =
                '<div class="alert alert-danger">' +
                (data.message || 'Unable to fetch ABHA profile.') +
                '</div>';
            return;
        }

        fillABHAProfile(data.profile);

document.getElementById('abha_verified').value = '1';

const viewCardButton =
    document.getElementById('viewABHACardButton');

if (viewCardButton) {
    viewCardButton.style.display = 'inline-flex';
}

document.getElementById('abhaFetchMessage').innerHTML =
    '<div class="alert alert-success">' +
    '<strong>ABHA profile fetched successfully.</strong>' +
    '</div>';

abhaFetchXToken = '';
abhaFetchTxnId = '';

        setTimeout(() => {
            closeFetchABHAModal();
        }, 800);
    })
    .catch(error => {
        console.error(error);
        document.getElementById('abhaFetchMessage').innerHTML =
            '<div class="alert alert-danger">Unable to fetch ABHA profile.</div>';
    });
}
function fillABHAProfile(p) {
    if (!p || typeof p !== 'object') {
        console.error('Invalid ABHA profile:', p);
        return;
    }
registrationABHAProfile = p;
    console.log('ABHA profile received:', p);

    const abhaNumber = p.ABHANumber || p.healthId || '';

    const abhaInput = document.getElementById('abha_id');
    if (abhaInput) {
        abhaInput.value = abhaNumber
            .replace(/\D/g, '')
            .slice(0, 14)
            .replace(/^(\d{2})(\d{4})(\d{4})(\d{4})$/, '$1-$2-$3-$4');
    }

    const firstNameInput = document.getElementById('first_name');
    if (firstNameInput) {
        firstNameInput.value = p.firstName || '';
    }

    const lastNameInput = document.getElementById('last_name');
    if (lastNameInput) {
        lastNameInput.value = p.lastName || '';
    }

    setGenderSafely(p.gender || p.sex || '');

    const mobileInput = document.getElementById('mobile');
    if (mobileInput) {
        const mobile = String(p.mobile || p.mobileNumber || '')
            .replace(/\D/g, '')
            .slice(-10);

        if (mobile) {
            mobileInput.value = mobile;
        }
    }

  let profileDob = '';

if (p.yearOfBirth && p.monthOfBirth && p.dayOfBirth) {
    profileDob =
        String(p.yearOfBirth) + '-' +
        String(p.monthOfBirth).padStart(2, '0') + '-' +
        String(p.dayOfBirth).padStart(2, '0');
} else if (p.dob) {
    const dobValue = String(p.dob).trim();

    if (/^\d{4}-\d{2}-\d{2}$/.test(dobValue)) {
        profileDob = dobValue;
    } else if (/^\d{2}-\d{2}-\d{4}$/.test(dobValue)) {
        const parts = dobValue.split('-');
        profileDob =
            parts[2] + '-' +
            parts[1] + '-' +
            parts[0];
    } else if (/^\d{4}\/\d{2}\/\d{2}$/.test(dobValue)) {
        profileDob = dobValue.replace(/\//g, '-');
    }
}

const dobInput = document.getElementById('dob');

if (dobInput && profileDob) {
    dobInput.value = profileDob;
    dobToAge();
}

    const addressInput = document.getElementById('address');
    if (addressInput && p.address) {
        addressInput.value = p.address;
    }

    const pincodeInput = document.getElementById('pincode');
    if (pincodeInput && p.pincode) {
        pincodeInput.value = p.pincode;
    }

    const emailInput = document.getElementById('email');
    if (emailInput && p.email) {
        emailInput.value = p.email;
    }
}
function viewABHACard() {
    if (!registrationABHAProfile) {
        alert('ABHA profile is not available. Please fetch ABHA details first.');
        return;
    }

    const p = registrationABHAProfile;

    const firstName = p.firstName || '';
    const middleName = p.middleName || '';
    const lastName = p.lastName || '';

    const fullName =
        p.name ||
        [firstName, middleName, lastName]
            .filter(Boolean)
            .join(' ');

    const abhaNumber =
        p.ABHANumber ||
        p.abhaNumber ||
        p.healthId ||
        '';

    const abhaAddress =
        p.preferredAbhaAddress ||
        p.abhaAddress ||
        '';

    let dob = '';

    if (
        p.yearOfBirth &&
        p.monthOfBirth &&
        p.dayOfBirth
    ) {
        dob =
            String(p.dayOfBirth).padStart(2, '0') +
            '-' +
            String(p.monthOfBirth).padStart(2, '0') +
            '-' +
            String(p.yearOfBirth);
    } else if (p.dob) {
        dob = p.dob;
    }

    const gender =
        p.gender ||
        p.sex ||
        '';

    const mobile =
        p.mobile ||
        p.mobileNumber ||
        '';

    const status =
        p.status ||
        p.abhaStatus ||
        'ACTIVE';

    const nameElement =
        document.getElementById('abhaCardName');

    const numberElement =
        document.getElementById('abhaCardNumber');

    const addressElement =
        document.getElementById('abhaCardAddress');

    const dobElement =
        document.getElementById('abhaCardDob');

    const genderElement =
        document.getElementById('abhaCardGender');

    const mobileElement =
        document.getElementById('abhaCardMobile');

    const statusElement =
        document.getElementById('abhaCardStatus');

    const footerElement =
        document.getElementById('abhaCardFooterNumber');

    if (nameElement) {
        nameElement.textContent = fullName || '-';
    }

    if (numberElement) {
        numberElement.textContent = abhaNumber || '-';
    }

    if (addressElement) {
        addressElement.textContent = abhaAddress || '-';
    }

    if (dobElement) {
        dobElement.textContent = dob || '-';
    }

    if (genderElement) {
        genderElement.textContent = gender || '-';
    }

    if (mobileElement) {
        mobileElement.textContent = mobile || '-';
    }

    if (statusElement) {
        statusElement.textContent = status;
    }

    if (footerElement) {
        footerElement.textContent =
            abhaNumber || 'ABHA';
    }

    const photoElement =
        document.getElementById('abhaCardPhoto');

    const photoPlaceholder =
        document.getElementById(
            'abhaCardPhotoPlaceholder'
        );

    const photo =
        p.profilePhoto ||
        p.profile_photo ||
        '';

    if (photoElement && photoPlaceholder) {
        if (photo) {
            if (
                String(photo).startsWith('data:image/')
            ) {
                photoElement.src = photo;
            } else {
                photoElement.src =
                    'data:image/jpeg;base64,' + photo;
            }

            photoElement.style.display = 'block';
            photoPlaceholder.style.display = 'none';
        } else {
            photoElement.src = '';
            photoElement.style.display = 'none';
            photoPlaceholder.style.display = 'flex';
        }
    }

    const modal =
        document.getElementById('abhaCardModal');

    if (!modal) {
        alert('ABHA Card modal not found.');
        return;
    }

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeABHACard() {
    const modal =
        document.getElementById('abhaCardModal');

    if (modal) {
        modal.style.display = 'none';
    }

    document.body.style.overflow = '';
}

function printABHACard() {
    const card =
        document.querySelector('#abhaCardModal .abha-card');

    if (!card) {
        alert('ABHA Card not found.');
        return;
    }

    const printWindow =
        window.open(
            '',
            '_blank',
            'width=900,height=700'
        );

    if (!printWindow) {
        alert('Please allow pop-ups to print the ABHA Card.');
        return;
    }

    printWindow.document.write(`
<!DOCTYPE html>
<html>
<head>
    <title>ABHA Card</title>

    <style>
        * {
            box-sizing:border-box;
        }

        body {
            margin:0;
            padding:30px;
            background:#fff;
            font-family:Arial,sans-serif;
        }

        .abha-card {
            width:680px;
            max-width:100%;
            margin:0 auto;
            border:1px solid #d9dee7;
            border-radius:16px;
            overflow:hidden;
            background:#fff;
        }

        .abha-card-top {
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:20px 24px;
            background:#f3f7ff;
            border-bottom:1px solid #dce5f5;
        }

        .abha-card-title {
            font-size:28px;
            font-weight:800;
        }

        .abha-card-subtitle {
            margin-top:3px;
            font-size:13px;
            color:#64748b;
        }

        .abha-card-status {
            padding:6px 12px;
            border-radius:20px;
            background:#dcfce7;
            color:#166534;
            font-size:12px;
            font-weight:700;
        }

        .abha-card-content {
            display:flex;
            gap:24px;
            padding:24px;
        }

        .abha-card-photo-wrap {
            width:120px;
            min-width:120px;
        }

        .abha-card-photo {
            width:110px;
            height:130px;
            object-fit:cover;
            border-radius:10px;
            border:1px solid #d1d5db;
        }

        .abha-card-photo-placeholder {
            width:110px;
            height:130px;
            border-radius:10px;
            border:1px solid #d1d5db;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:42px;
            background:#f8fafc;
        }

        .abha-card-details {
            flex:1;
        }

        .abha-card-row {
            display:flex;
            justify-content:space-between;
            gap:20px;
            padding:10px 0;
            border-bottom:1px solid #edf0f4;
        }

        .abha-card-row span {
            color:#64748b;
            font-size:13px;
        }

        .abha-card-row strong {
            text-align:right;
            font-size:14px;
            color:#111827;
            word-break:break-word;
        }

        .abha-card-footer {
            display:flex;
            justify-content:space-between;
            padding:12px 24px;
            background:#f8fafc;
            border-top:1px solid #e5e7eb;
            font-size:12px;
            color:#64748b;
        }

        @media print {
            body {
                padding:0;
            }

            .abha-card {
                width:100%;
                box-shadow:none;
            }
        }
    </style>
</head>

<body>

${card.outerHTML}

</body>
</html>
`);

    printWindow.document.close();

    setTimeout(function() {
        printWindow.focus();
        printWindow.print();
        printWindow.close();
    }, 500);
}

function closeFetchABHAModal() {
    const modal = document.getElementById('fetchABHAModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
    document.getElementById('abha_fetch_otp').value = '';
    document.getElementById('abhaFetchMessage').innerHTML = '';
    abhaFetchTxnId = '';
    abhaFetchXToken = '';
}
window.verifyMobileForRegistration = function(event) {
    const mobile = document.getElementById('mobile').value.trim();

    if (!/^\d{10}$/.test(mobile)) {
        alert('Please enter a valid 10 digit mobile number.');
        return;
    }

    const button = event.currentTarget;

    button.disabled = true;
    button.innerHTML = '...';

    fetch('/modules/abdm/abha_request_mobile_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'mobile=' + encodeURIComponent(mobile) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(async response => {
        const rawResponse = await response.text();

        let data;

        try {
            data = JSON.parse(rawResponse);
        } catch (error) {
    console.error('RAW MOBILE OTP RESPONSE:', rawResponse);
    alert('RAW MOBILE OTP RESPONSE:\n\n' + rawResponse);
    throw new Error('Invalid JSON response from mobile OTP endpoint.');
}

        if (!response.ok || !data.success) {

            let errorMessage = data.message || 'Unable to send mobile OTP.';

            if (data.http_code) {
                errorMessage += '\nHTTP Code: ' + data.http_code;
            }

            if (data.response) {
                errorMessage +=
                    '\nABDM Response:\n' +
                    JSON.stringify(data.response, null, 2);
            }

            alert(errorMessage);

            button.disabled = false;
            button.innerHTML = '✓';
            return;
        }

        registrationMobileTxnId = data.txnId || '';

        if (!registrationMobileTxnId) {
            alert('ABDM did not return a transaction ID.');
            button.disabled = false;
            button.innerHTML = '✓';
            return;
        }

        const modal = document.getElementById('registrationMobileModal');

        if (modal) {
            modal.style.display = 'flex';
        }

        document.body.style.overflow = 'hidden';

        document.getElementById('registrationMobileMessage').innerHTML =
            '<div class="alert alert-success">' +
            (data.message || 'OTP sent successfully.') +
            '</div>';

        document.getElementById('registrationMobileOtp').value = '';

        setTimeout(() => {
            document.getElementById('registrationMobileOtp').focus();
        }, 100);

        button.disabled = false;
        button.innerHTML = '✓';

      
    })
    .catch(error => {
        alert(error.message || 'Unable to connect to ABDM.');

        button.disabled = false;
        button.innerHTML = '✓';
    });
};
function closeRegistrationMobileModal() {
    const modal = document.getElementById('registrationMobileModal');

    if (modal) {
        modal.style.display = 'none';
    }

    document.body.style.overflow = '';

    const otp = document.getElementById('registrationMobileOtp');
    const message = document.getElementById('registrationMobileMessage');

    if (otp) {
        otp.value = '';
    }

    if (message) {
        message.innerHTML = '';
    }

    registrationMobileTxnId = '';
}
window.verifyRegistrationMobileOtp = function() {
    const otpInput = document.getElementById('registrationMobileOtp');
    const message = document.getElementById('registrationMobileMessage');

    const otp = otpInput ? otpInput.value.trim() : '';

    if (!/^\d{6}$/.test(otp)) {
        message.innerHTML =
            '<div class="alert alert-danger">' +
            'Please enter a valid 6 digit OTP.' +
            '</div>';
        return;
    }

    if (!registrationMobileTxnId) {
        message.innerHTML =
            '<div class="alert alert-danger">' +
            'Mobile OTP transaction has expired. Please request OTP again.' +
            '</div>';
        return;
    }

    const button = document.querySelector(
        '#registrationMobileModal .abha-create-action.success'
    );

    if (button) {
        button.disabled = true;
        button.innerHTML = 'Verifying...';
    }

    message.innerHTML =
        '<div class="alert alert-info">' +
        'Verifying mobile OTP with ABDM...' +
        '</div>';

    fetch('/modules/abdm/abha_verify_mobile_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body:
            'txnId=' +
            encodeURIComponent(registrationMobileTxnId) +
            '&otp=' +
            encodeURIComponent(otp) +
            '&csrf=' +
            encodeURIComponent(window.csrfToken)
    })
    .then(async response => {
        const rawResponse = await response.text();

        let data;

        try {
            data = JSON.parse(rawResponse);
        } catch (error) {
            throw new Error(
                'Invalid JSON response from mobile OTP verification endpoint.'
            );
        }

        console.log(
            'Mobile OTP verification response:',
            data
        );

        if (!response.ok || !data.success) {
            let errorMessage =
                data.message ||
                'Mobile OTP verification failed.';

            if (data.http_code) {
                errorMessage +=
                    '<br>HTTP Code: ' +
                    data.http_code;
            }

            if (data.response) {
                errorMessage +=
                    '<br><pre style="white-space:pre-wrap;text-align:left;">' +
                    escapeHtml(
                        JSON.stringify(
                            data.response,
                            null,
                            2
                        )
                    ) +
                    '</pre>';
            }

            if (data.raw_response) {
                errorMessage +=
                    '<br><pre style="white-space:pre-wrap;text-align:left;">' +
                    escapeHtml(
                        data.raw_response
                    ) +
                    '</pre>';
            }

            message.innerHTML =
                '<div class="alert alert-danger">' +
                errorMessage +
                '</div>';

            if (button) {
                button.disabled = false;
                button.innerHTML = 'Verify OTP';
            }

            return;
        }

        /*
         * The PHP endpoint now verifies the OTP AND
         * fetches the ABHA profile in the same request.
         *
         * Therefore we DO NOT call:
         * abha_fetch_profile.php
         */

        if (!data.profile) {
            message.innerHTML =
                '<div class="alert alert-danger">' +
                'OTP verified, but ABHA profile was not returned by ABDM.' +
                '</div>';

            if (button) {
                button.disabled = false;
                button.innerHTML = 'Verify OTP';
            }

            console.log(
                'ABDM accounts:',
                data.accounts || []
            );

            return;
        }

        console.log(
            'Mobile ABHA full profile:',
            data.profile
        );

        /*
         * Fill Registration fields
         */
        registrationABHAProfile = data.profile;

fillABHAProfile(
    data.profile
);

const viewCardButton =
    document.getElementById('viewABHACardButton');

if (viewCardButton) {
    viewCardButton.style.display = 'inline-flex';
}
        /*
         * Mark ABHA as verified
         */
        const verifiedField =
            document.getElementById('abha_verified');

        if (verifiedField) {
            verifiedField.value = '1';
        }

        /*
         * Success message
         */
        message.innerHTML =
            '<div class="alert alert-success">' +
            '<strong>ABHA details fetched successfully.</strong>' +
            '</div>';

        if (button) {
            button.innerHTML = 'Verified';
        }

        /*
         * Clear transaction data
         */
        registrationMobileTxnId = '';

        /*
         * Close modal
         */
        setTimeout(() => {
            closeRegistrationMobileModal();
        }, 800);
    })
    .catch(error => {
        console.error(
            'Mobile OTP verification error:',
            error
        );

        message.innerHTML =
            '<div class="alert alert-danger">' +
            escapeHtml(
                error.message ||
                'Unable to verify mobile OTP.'
            ) +
            '</div>';

        if (button) {
            button.disabled = false;
            button.innerHTML = 'Verify OTP';
        }
    });
};
function verifyAadhaarForRegistration(event) {
    const aadhaar = document.getElementById('aadhaar').value.trim();

    if (!/^\d{12}$/.test(aadhaar)) {
        alert('Please enter a valid 12-digit Aadhaar number.');
        return;
    }

    const button = event.currentTarget;

    button.disabled = true;
    button.innerHTML = '...';

    fetch('/modules/abdm/abha_request_aadhaar_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'aadhaar=' + encodeURIComponent(aadhaar) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(async response => {
        const rawResponse = await response.text();

        console.log('Registration Aadhaar OTP HTTP:', response.status);
        console.log('Registration Aadhaar OTP Response:', rawResponse);

        let data;

        try {
            data = JSON.parse(rawResponse);
        } catch (error) {
            throw new Error('Invalid JSON response from ABDM endpoint.');
        }

        if (!response.ok || !data.success) {
            console.error('Registration Aadhaar OTP Error:', data);

            let errorMessage = data.message || 'Unable to send Aadhaar OTP.';

if (data.http_code) {
    errorMessage += '\nHTTP Code: ' + data.http_code;
}

if (data.response) {
    errorMessage +=
        '\nABDM Response:\n' +
        JSON.stringify(data.response, null, 2);
}

console.error('Registration Aadhaar Full ABDM Error:', data);

alert(errorMessage);

            button.disabled = false;
            button.innerHTML = '✓';
            return;
        }

        registrationAadhaarTxnId = data.txnId || '';

        if (!registrationAadhaarTxnId) {
            alert('ABDM did not return a transaction ID.');

            button.disabled = false;
            button.innerHTML = '✓';
            return;
        }

        const mobileField = document.getElementById('mobile');
        const mobile = mobileField ? mobileField.value.trim() : '';

        const mobileInput =
            document.getElementById('registration_aadhaar_mobile');

        if (mobileInput) {
            mobileInput.value = mobile;
        }

        document.getElementById('registrationAadhaarOtp').value = '';
        document.getElementById('registrationAadhaarMessage').innerHTML =
            '<div class="alert alert-success">' +
            (data.message || 'OTP sent successfully.') +
            '</div>';

        document.getElementById('registrationAadhaarModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';

        button.innerHTML = '✓';
        button.disabled = false;

        setTimeout(() => {
            document.getElementById('registrationAadhaarOtp').focus();
        }, 100);

        console.log(
            'Registration Aadhaar OTP transaction:',
            registrationAadhaarTxnId
        );
    })
    .catch(error => {
        console.error('Registration Aadhaar Error:', error);

        alert(
            error.message ||
            'Unable to connect to ABDM.'
        );

        button.disabled = false;
        button.innerHTML = '✓';
    });
}
function closeRegistrationAadhaarModal() {
    const modal = document.getElementById('registrationAadhaarModal');

    if (modal) {
        modal.style.display = 'none';
    }

    document.body.style.overflow = '';

    const otpInput =
        document.getElementById('registrationAadhaarOtp');

    if (otpInput) {
        otpInput.value = '';
    }

    const message =
        document.getElementById('registrationAadhaarMessage');

    if (message) {
        message.innerHTML = '';
    }

    registrationAadhaarTxnId = '';
}
function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}
function verifyRegistrationAadhaarOtp() {
    const otp =
        document.getElementById('registrationAadhaarOtp').value.trim();

    const mobileInput =
        document.getElementById('registration_aadhaar_mobile');

    const mobile =
        mobileInput ? mobileInput.value.trim() : '';

    const aadhaar =
        document.getElementById('aadhaar').value.trim();

    if (!/^\d{6}$/.test(otp)) {
        document.getElementById('registrationAadhaarMessage').innerHTML =
            '<div class="alert alert-danger">' +
            'Please enter a valid 6 digit OTP.' +
            '</div>';
        return;
    }

    if (!/^\d{10}$/.test(mobile)) {
        document.getElementById('registrationAadhaarMessage').innerHTML =
            '<div class="alert alert-danger">' +
            'Please enter a valid 10 digit mobile number.' +
            '</div>';
        return;
    }

    if (!registrationAadhaarTxnId) {
        document.getElementById('registrationAadhaarMessage').innerHTML =
            '<div class="alert alert-danger">' +
            'Aadhaar OTP transaction has expired. Please request OTP again.' +
            '</div>';
        return;
    }

    const button =
        document.getElementById('registrationAadhaarVerifyButton');

    button.disabled = true;
    button.innerHTML = 'Verifying...';

    document.getElementById('registrationAadhaarMessage').innerHTML =
        '<div class="alert alert-info">' +
        'Verifying Aadhaar OTP with ABDM...' +
        '</div>';

    fetch('/modules/abdm/abha_verify_aadhaar_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body:
            'txnId=' + encodeURIComponent(registrationAadhaarTxnId) +
            '&otp=' + encodeURIComponent(otp) +
            '&mobile=' + encodeURIComponent(mobile) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(async response => {
        const rawResponse = await response.text();

        console.log(
            'Registration Aadhaar Verify HTTP:',
            response.status
        );

        console.log(
            'Registration Aadhaar Verify Response:',
            rawResponse
        );

        let data;

        try {
            data = JSON.parse(rawResponse);
        } catch (error) {
            throw new Error(
                'Invalid JSON response from Aadhaar verification endpoint.'
            );
        }

        if (!response.ok || !data.success) {
            console.error(
                'Registration Aadhaar Verification Error:',
                data
            );

            document.getElementById(
                'registrationAadhaarMessage'
            ).innerHTML =
                '<div class="alert alert-danger">' +
                '<strong>' +
                escapeHtml(
                    data.message ||
                    'Aadhaar verification failed.'
                ) +
                '</strong>' +
                '</div>';

            button.disabled = false;
            button.innerHTML = 'Verify Aadhaar';

            return;
        }

        const responseData = data.response || {};

        const profile =
            responseData.EnrolProfile ||
            responseData.ABHAProfile ||
            responseData.enrolProfile ||
            responseData.abhaProfile ||
            responseData.profile ||
            {};
            registrationABHAProfile = {
    ...responseData,
    ...profile
};

console.log(
    'Registration Aadhaar ABHA Profile:',
    registrationABHAProfile
);

        console.log(
            'ABDM Aadhaar Profile:',
            profile
        );

        /* =========================
           AADHAAR
        ========================= */

        if (aadhaar) {
            document.getElementById('aadhaar').value = aadhaar;
        }

        /* =========================
           ABHA NUMBER
        ========================= */

        const abhaNumber =
            profile.ABHANumber ||
            profile.abhaNumber ||
            responseData.ABHANumber ||
            responseData.abhaNumber ||
            '';

        if (abhaNumber) {
            document.getElementById('abha_id').value =
                abhaNumber;

            document.getElementById('abha_verified').value =
                '1';
        }

        /* =========================
           ABHA ADDRESS
        ========================= */

        const abhaAddress =
            profile.phrAddress?.[0] ||
            profile.abhaAddress ||
            profile.ABHAAddress ||
            responseData.abhaAddress ||
            responseData.ABHAAddress ||
            '';

        console.log(
            'ABDM Aadhaar ABHA Address:',
            abhaAddress
        );
const aadhaarProfilePhoto =
    profile.profilePhoto ||
    profile.profile_photo ||
    profile.photo ||
    profile.kycPhoto ||
    responseData.profilePhoto ||
    responseData.profile_photo ||
    responseData.photo ||
    responseData.kycPhoto ||
    '';

registrationABHAProfile = {
    ...profile,
    ABHANumber:
        profile.ABHANumber ||
        profile.abhaNumber ||
        responseData.ABHANumber ||
        responseData.abhaNumber ||
        '',
    preferredAbhaAddress:
        abhaAddress || '',
    profilePhoto:
        aadhaarProfilePhoto || ''
};

console.log(
    'Registration Aadhaar ABHA Profile:',
    registrationABHAProfile
);
        /* =========================
           FIRST NAME
        ========================= */

        const firstName =
            profile.firstName ||
            profile.first_name ||
            '';

        if (firstName) {
            document.getElementById('first_name').value =
                firstName;
        }

        /* =========================
           LAST NAME
        ========================= */

        const lastName =
            profile.lastName ||
            profile.last_name ||
            '';

        if (lastName) {
            document.getElementById('last_name').value =
                lastName;
        }

        /* =========================
           FULL NAME FALLBACK
        ========================= */

        if (!firstName && !lastName) {
            const fullName =
                profile.name ||
                profile.fullName ||
                responseData.name ||
                responseData.fullName ||
                '';

            if (fullName) {
                const nameParts =
                    fullName.trim().split(/\s+/);

                if (nameParts.length === 1) {
                    document.getElementById('first_name').value =
                        nameParts[0];
                } else {
                    document.getElementById('first_name').value =
                        nameParts.shift();

                    document.getElementById('last_name').value =
                        nameParts.join(' ');
                }
            }
        }

        /* =========================
           GENDER
        ========================= */

        let gender =
            profile.gender ||
            profile.sex ||
            responseData.gender ||
            responseData.sex ||
            '';

        gender = String(gender).toUpperCase();

        if (gender === 'M' || gender === 'MALE') {
            setGenderSafely('Male');
        } else if (
            gender === 'F' ||
            gender === 'FEMALE'
        ) {
            setGenderSafely('Female');
        } else if (
            gender === 'O' ||
            gender === 'OTHER'
        ) {
            setGenderSafely('Other');
        }

        /* =========================
           MOBILE
        ========================= */

        const returnedMobile =
            profile.mobile ||
            responseData.mobile ||
            mobile;

        if (returnedMobile) {
            document.getElementById('mobile').value =
                returnedMobile;
        }

        /* =========================
           EMAIL
        ========================= */

        const email =
            profile.email ||
            responseData.email ||
            '';

        if (
            email &&
            document.getElementById('email')
        ) {
            document.getElementById('email').value =
                email;
        }

        /* =========================
           DATE OF BIRTH
        ========================= */

       let dob = '';

const profileDob =
    profile.dob ||
    profile.dateOfBirth ||
    profile.date_of_birth ||
    responseData.dob ||
    responseData.dateOfBirth ||
    responseData.date_of_birth ||
    '';

if (profileDob) {
    const dobValue = String(profileDob).trim();

    if (/^\d{4}-\d{1,2}-\d{1,2}$/.test(dobValue)) {
        const parts = dobValue.split('-');

        dob =
            parts[0] + '-' +
            String(parts[1]).padStart(2, '0') + '-' +
            String(parts[2]).padStart(2, '0');
    } else if (/^\d{2}-\d{1,2}-\d{4}$/.test(dobValue)) {
        const parts = dobValue.split('-');

        dob =
            parts[2] + '-' +
            String(parts[1]).padStart(2, '0') + '-' +
            String(parts[0]).padStart(2, '0');
    } else if (/^\d{2}\/\d{1,2}\/\d{4}$/.test(dobValue)) {
        const parts = dobValue.split('/');

        dob =
            parts[2] + '-' +
            String(parts[1]).padStart(2, '0') + '-' +
            String(parts[0]).padStart(2, '0');
    }
}

if (
    !dob &&
    profile.yearOfBirth &&
    profile.monthOfBirth &&
    profile.dayOfBirth
) {
    dob =
        String(profile.yearOfBirth) + '-' +
        String(profile.monthOfBirth).padStart(2, '0') + '-' +
        String(profile.dayOfBirth).padStart(2, '0');
}

if (dob) {
    const dobInput =
        document.getElementById('dob');

    if (dobInput) {
        dobInput.value = dob;
        dobInput.dispatchEvent(
            new Event('change', {
                bubbles: true
            })
        );
        dobToAge();
    }
}
        /* =========================
           ADDRESS
        ========================= */

        const address =
            profile.address ||
            profile.addressLine ||
            responseData.address ||
            '';

        if (address) {
            document.getElementById('address').value =
                address;
        }

        /* =========================
           PINCODE
        ========================= */

        const pincode =
            profile.pinCode ||
            profile.pincode ||
            profile.pin ||
            responseData.pinCode ||
            responseData.pincode ||
            '';

        if (pincode) {
            document.getElementById('pincode').value =
                pincode;
        }

        /* =========================
           TITLE
        ========================= */

        const titleField =
            document.getElementById('title');

        if (titleField && gender) {
            if (gender === 'M' || gender === 'MALE') {
                if (
                    titleField.querySelector(
                        'option[value="Mr"]'
                    )
                ) {
                    titleField.value = 'Mr';
                }
            } else if (
                gender === 'F' ||
                gender === 'FEMALE'
            ) {
                if (
                    titleField.querySelector(
                        'option[value="Miss"]'
                    )
                ) {
                    titleField.value = 'Miss';
                }
            }

            titleField.dispatchEvent(
                new Event('change')
            );
        }

        /* =========================
           VERIFICATION STATUS
        ========================= */

        document.getElementById(
            'abha_verified'
        ).value = '1';
const viewCardButton =
    document.getElementById('viewABHACardButton');

if (viewCardButton) {
    viewCardButton.style.display = 'inline-flex';
}
        document.getElementById(
            'registrationAadhaarMessage'
        ).innerHTML =
            '<div class="alert alert-success">' +
            '<strong>Aadhaar verified successfully.</strong>' +
            '<br>' +
            (
                abhaNumber
                    ? 'ABHA Number: ' +
                      escapeHtml(abhaNumber)
                    : 'ABHA details received from ABDM.'
            ) +
            '</div>';

        button.disabled = false;
        button.innerHTML = 'Verified ✓';

        registrationAadhaarTxnId = '';

        setTimeout(() => {
            closeRegistrationAadhaarModal();
        }, 1200);
    })
    .catch(error => {
        console.error(
            'Registration Aadhaar Verification Error:',
            error
        );

        document.getElementById(
            'registrationAadhaarMessage'
        ).innerHTML =
            '<div class="alert alert-danger">' +
            escapeHtml(
                error.message ||
                'Unable to connect to ABDM.'
            ) +
            '</div>';

        button.disabled = false;
        button.innerHTML = 'Verify Aadhaar';
    });
}
</script>
<script>
document.getElementById('referral_type').addEventListener('change', function () {
    const type = this.value;
    const internalBox = document.getElementById('internal_referral_box');
    const externalBox = document.getElementById('external_referral_box');
    internalBox.style.display = 'none';
    externalBox.style.display = 'none';
    if (type === 'Internal') {
        internalBox.style.display = 'block';
        loadInternalUsers();
    }
    if (type === 'External') {
        externalBox.style.display = 'block';
        loadExternalReferrals();
    }
});
</script>
<script>
function loadInternalUsers() {
    fetch('/modules/registration/ajax/get_active_users.php')
        .then(res => res.json())
        .then(data => {
            const dd = document.getElementById('internal_referral_id');
            dd.innerHTML = '<option value="">Select User</option>';
            data.forEach(u => {
                dd.innerHTML +=
                    `<option value="${u.id}">${u.full_name}</option>`;
            });
        });
}
</script>
<script>
function loadExternalReferrals() {
    fetch('/modules/registration/ajax/get_external_referrals.php')
        .then(res => res.json())
        .then(data => {
            const dd = document.getElementById('external_referral_id');
            dd.innerHTML = '<option value="">Select Referral</option>';
            if (!Array.isArray(data) || data.length === 0) {
                dd.innerHTML += '<option value="">No Referrals Found</option>';
                return;
            }
            data.forEach(r => {
                dd.innerHTML +=
                    `<option value="${r.id}">${r.name}</option>`;
            });
        })
        .catch(err => {
            console.error(err);
            alert('Failed to load referrals');
        });
}
</script>
<!-- ================= REFERRAL MODAL ================= -->
<div class="modal" id="referralModal" style="display:none;">
  <div class="modal-box">
    <span class="close" onclick="closeReferralModal()">×</span>
    <h3>Add External Referral</h3>
    <div class="grid">
        <div>
            <label>Name *</label>
            <input type="text" id="rm_name">
        </div>
        <div>
            <label>Category *</label>
            <select id="rm_category">
                <option value="">Select</option>
                <option value="Hospital">Hospital</option>
                <option value="Clinic">Clinic</option>
                <option value="Laboratory">Laboratory</option>
                <option value="Trust">Trust</option>
                <option value="In Person">In Person</option>
            </select>
        </div>
        <div>
            <label>Organization Name</label>
            <input type="text" id="rm_org_name">
        </div>
        <div>
            <label>Mobile</label>
            <input type="text" id="rm_mobile">
        </div>
        <div>
            <label>City *</label>
            <select id="rm_city">
                <option value="">Select City</option>
                <?php foreach ($cities as $c): ?>
                    <option value="<?= $c['id'] ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div style="text-align:right;margin-top:15px;">
      <button type="button" onclick="closeReferralModal()">Cancel</button>
      <button type="button" onclick="saveReferral()">Save</button>
    </div>
  </div>
</div>
<script>
function openReferralModal(){
    document.getElementById('referralModal').style.display = 'block';
}
function closeReferralModal(){
    document.getElementById('referralModal').style.display = 'none';
}
function saveReferral(){
    const name     = document.getElementById('rm_name').value.trim();
    const category = document.getElementById('rm_category').value;
    const org_name = document.getElementById('rm_org_name').value.trim();
    const mobile   = document.getElementById('rm_mobile').value.trim();
    const city_id  = document.getElementById('rm_city').value;
    if (!name || !category || !city_id) {
        alert('Please fill required fields');
        return;
    }
    fetch('/modules/registration/ajax/save_referral.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:
            `name=${encodeURIComponent(name)}`
            + `&category=${encodeURIComponent(category)}`
            + `&org_name=${encodeURIComponent(org_name)}`
            + `&mobile=${encodeURIComponent(mobile)}`
            + `&city_id=${encodeURIComponent(city_id)}`
    })
    .then(res=>res.json())
    .then(data=>{

        if(data.error){
            alert(data.error);
            return;
        }
        if(data.id){
            loadExternalReferrals();
            setTimeout(()=>{
                document.getElementById('external_referral_id').value = data.id;
            },300);
            closeReferralModal();
        }
    })
    .catch(err=>{
        console.error(err);
        alert('Failed to save referral');
    });
}
</script>
<script>
document.getElementById('empanelment_id').addEventListener('change', function () {
    const empId = this.value;
    const feeInput = document.getElementById('fee');
    const discountDD      = document.getElementById('discount_id');
    const discountPercent = document.getElementById('discount_percent');
    const discountAmount  = document.getElementById('discount_amount');
    // ===============================
    // IF EMPANELMENT SELECTED
    // ===============================
    if (empId) {
        // 🔒 Disable discount fields
        discountDD.disabled      = true;
        discountPercent.disabled = true;
        discountAmount.disabled  = true;
        // Clear discount values
        discountDD.value = '';
        discountPercent.value = '';
        discountAmount.value  = '';
    } else {
    // Enable Discount Type
    discountDD.disabled = false;

    // Discount % and Amount remain disabled
    // until a Discount Type is selected.
    discountPercent.disabled = !discountDD.value;
    discountAmount.disabled = !discountDD.value;
}
    // ===============================
    // EXISTING EMPANELMENT LOGIC
    // ===============================
    if (!empId) {

    /*
     * Recalculate according to the current visit mode:
     * Emergency > Revisit > Normal
     */
    reloadDoctorConsultationFee();

    return;
}
    const originalFee =
    parseFloat(
        document.getElementById('consultation_fee')?.value
    ) || 0;
    fetch('/modules/registration/ajax/get_empanelment_discount.php?empanelment_id=' + empId)
        .then(res => res.json())
        .then(data => {
            if (data.status !== 'FOUND') return;
            let newFee = originalFee;
            if (data.type === 'Amount') {
                newFee = originalFee - parseFloat(data.value);
            }
            if (data.type === 'Percentage') {
                newFee = originalFee - (originalFee * parseFloat(data.value) / 100);
            }
            if (newFee < 0) newFee = 0;
            feeInput.value = newFee.toFixed(2);
            forceFullPayment();
        })
        .catch(err => console.error(err));
});
</script>
<script>
let discountLimitType = null;
let discountLimitValue = 0;
let originalFee = 0;
document.getElementById('discount_id').addEventListener('change', function () {
    const id = this.value;

    const discountPercent =
        document.getElementById('discount_percent');

    const discountAmount =
        document.getElementById('discount_amount');

    if (!id) {

        discountPercent.disabled = true;
        discountAmount.disabled = true;

        resetDiscount();
        return;
    }

    discountPercent.disabled = false;
    discountAmount.disabled = false;
    fetch('/modules/registration/ajax/get_discount_details.php?discount_id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.status !== 'FOUND') return;
            discountLimitType  = data.type;   // Percentage / Flat
            discountLimitValue = parseFloat(data.value);
            originalFee = parseFloat(document.getElementById('fee').value) || 0;
            document.getElementById('discount_percent').value = '';
            document.getElementById('discount_amount').value  = '';
        });
});
/* =========================
   PERCENT → AMOUNT
========================= */
document.getElementById('discount_percent').addEventListener('input', function () {
    if (!originalFee) return;
    let percent = parseFloat(this.value) || 0;
    if (discountLimitType === 'Percentage' && percent > discountLimitValue) {
        percent = discountLimitValue;
        this.value = percent;
    }
    let amount = (originalFee * percent) / 100;
    if (discountLimitType === 'Flat' && amount > discountLimitValue) {
        amount = discountLimitValue;
        percent = (amount / originalFee) * 100;
        this.value = percent.toFixed(2);
    }
    document.getElementById('discount_amount').value = amount.toFixed(2);
    applyDiscount(amount);
});
/* =========================
   AMOUNT → PERCENT
========================= */
document.getElementById('discount_amount').addEventListener('input', function () {
    if (!originalFee) return;
    let amount = parseFloat(this.value) || 0;
    if (discountLimitType === 'Flat' && amount > discountLimitValue) {
        amount = discountLimitValue;
        this.value = amount;
    }
    let percent = (amount / originalFee) * 100;
    if (discountLimitType === 'Percentage' && percent > discountLimitValue) {
        percent = discountLimitValue;
        amount = (originalFee * percent) / 100;
        this.value = amount.toFixed(2);
    }
    document.getElementById('discount_percent').value = percent.toFixed(2);
    applyDiscount(amount);
});
/* =========================
   APPLY DISCOUNT
========================= */
function applyDiscount(discountAmount) {
    const feeInput = document.getElementById('fee');
    let newFee = originalFee - discountAmount;
    if (newFee < 0) newFee = 0;
    feeInput.value = newFee.toFixed(2);
    forceFullPayment();
}
function resetDiscount() {
    document.getElementById('discount_percent').value = '';
    document.getElementById('discount_amount').value  = '';
}
</script>
<script>
let lastCalculatedFee = 0;
document.addEventListener('DOMContentLoaded', function () {
    const campCheckbox = document.getElementById('is_camp');
    const feeInput     = document.getElementById('fee');
    // Store initial fee
    lastCalculatedFee = parseFloat(feeInput.value) || 0;
    campCheckbox.addEventListener('change', function () {
        if (this.checked) {
            // Save current fee before making zero
            lastCalculatedFee = parseFloat(feeInput.value) || 0;
            feeInput.value = 0;
            forceFullPayment();
        } else {
            // Restore previous calculated fee
            feeInput.value = lastCalculatedFee.toFixed(2);
            forceFullPayment();
        }
    });
});
<?php if ($appointment_id): ?>
document.addEventListener('DOMContentLoaded', function(){
    document.getElementById('type_dd').style.pointerEvents='none';
document.getElementById('department_dd').style.pointerEvents='none';
document.getElementById('entity_dd').style.pointerEvents='none';
document.getElementById('appointment_time_dd').style.pointerEvents='none';

document.getElementById('type_dd').style.background='#f5f5f5';
document.getElementById('department_dd').style.background='#f5f5f5';
document.getElementById('entity_dd').style.background='#f5f5f5';
document.getElementById('appointment_time_dd').style.background='#f5f5f5';

document.getElementById('appointment_date').style.pointerEvents='none';
document.getElementById('appointment_date').style.background='#f5f5f5';
    const consultationModeDisplay = document.getElementById('consultation_mode_display');

if (consultationModeDisplay) {
    consultationModeDisplay.style.background = '#f5f5f5';
    consultationModeDisplay.style.pointerEvents = 'none';
}
});
<?php endif; ?>
</script>
<script>
    function waitForOptions(selectElement, value, timeout = 5000) {

    return new Promise(function(resolve) {

        if (!selectElement) {
            resolve(false);
            return;
        }

        const startTime = Date.now();

        function check() {

            const optionExists =
                Array.from(selectElement.options)
                    .some(option => option.value == value);

            if (optionExists) {
                resolve(true);
                return;
            }

            if (Date.now() - startTime >= timeout) {
                console.warn(
                    'Option not found:',
                    value,
                    selectElement.id
                );

                resolve(false);
                return;
            }

            setTimeout(check, 100);
        }

        check();
    });
}
document.addEventListener('DOMContentLoaded', async function(){

    const typeDD   = document.getElementById('type_dd');
    const deptDD   = document.getElementById('department_dd');
    const entityDD = document.getElementById('entity_dd');
    const slotDD   = document.getElementById('appointment_time_dd');

    const APPT_TYPE   = "<?= $type ?>";
    const APPT_DEPT   = "<?= $appt['department_id'] ?? '' ?>";
    const APPT_DOCTOR = "<?= $entity_id ?>";
    const APPT_SLOT   = "<?= $slot ?>";

    if (!APPT_TYPE) {
        return;
    }

    /*
     * STEP 1
     * Select appointment type.
     */
    typeDD.value = APPT_TYPE;
    typeDD.dispatchEvent(new Event('change'));

    /*
     * STEP 2
     * Wait until Department AJAX has populated
     * the department dropdown.
     */
    if (APPT_DEPT) {

        await waitForOptions(
            deptDD,
            APPT_DEPT,
            5000
        );

        deptDD.value = APPT_DEPT;
        deptDD.dispatchEvent(new Event('change'));
    }

    /*
     * STEP 3
     * Wait until Doctor/Service AJAX has populated
     * the entity dropdown.
     */
    if (APPT_DOCTOR) {

        await waitForOptions(
            entityDD,
            APPT_DOCTOR,
            5000
        );

        entityDD.value = APPT_DOCTOR;
        entityDD.dispatchEvent(new Event('change'));
    }

    /*
     * STEP 4
     * Wait until slots are loaded.
     */
    if (APPT_SLOT && APPT_TYPE !== 'ipd') {

        await waitForOptions(
            slotDD,
            APPT_SLOT,
            5000
        );

        slotDD.value = APPT_SLOT;
    }

});
</script>
<script>
document.getElementById('mobile').addEventListener('blur', function () {

    const mobile = this.value.trim();

    if (mobile === '') return;

    if (!/^[0-9]{10}$/.test(mobile)) {
        alert('Please enter a valid 10 digit mobile number');
        this.value = '';
        this.focus();
    }

});
</script>
<script>
    document.getElementById('phone').addEventListener('blur', function () {

    const phone = this.value.trim();

    if (phone === '') return;

    if (!/^[0-9]{10}$/.test(phone)) {
        alert('Please enter a valid 10 digit phone number');
        this.value = '';
        this.focus();
    }

});
document.getElementById('phone').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});
document.getElementById('mobile').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const dobField = document.getElementById('dob');

    if (dobField && dobField.value.trim() !== '') {
        dobToAge();
    }

});
</script>
<script>
function applyIPDPaymentMode() {

    const typeDD = document.getElementById('type_dd');

    if (!typeDD) {
        return;
    }

    const isIPD = typeDD.value === 'ipd';

    const paymentFields =
        document.querySelectorAll('.ipd-payment-field');

    paymentFields.forEach(function(field) {

        const fields =
            field.querySelectorAll('input, select, textarea');

        if (isIPD) {

            field.style.display = 'none';

            fields.forEach(function(input) {

                input.disabled = true;

                /*
                 * Clear values so old OPD payment data
                 * cannot remain when IPD is selected.
                 */
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });

        } else {

    field.style.display = '';

    fields.forEach(function(input) {
        input.disabled = false;
    });

    const discountDD = document.getElementById('discount_id');
    const discountPercent = document.getElementById('discount_percent');
    const discountAmount = document.getElementById('discount_amount');

    if (discountDD && discountPercent && discountAmount) {
        const hasDiscount = !!discountDD.value;
        discountPercent.disabled = !hasDiscount;
        discountAmount.disabled = !hasDiscount;
    }
}

if (!isIPD) {
    const type = typeDD.value;
    if (type === 'doctor') {
        reloadDoctorConsultationFee();
    }
}
    });
    applyAppointmentPaymentLock();
}
let abhaCreateTxnId = '';
function openCreateABHAModal() {
    const aadhaar = document.getElementById('aadhaar')?.value.trim() || '';
    const modal = document.getElementById('createABHAModal');
    if (!modal) {
        alert('Create ABHA modal not found.');
        return;
    }
    document.getElementById('abha_create_aadhaar').value = aadhaar;
    document.getElementById('abha_create_otp').value = '';
    document.getElementById('abha_create_mobile').value = '';
    document.getElementById('abhaCreateMessage').innerHTML = '';
    document.getElementById('abhaAadhaarStep').style.display = 'block';
    document.getElementById('abhaOtpStep').style.display = 'none';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeCreateABHAModal() {
    const modal = document.getElementById('createABHAModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
}
function sendABHAOTP() {
    alert('sendABHAOTP() called');

    const aadhaar = document.getElementById('abha_create_aadhaar').value.trim();

    alert('Aadhaar length: ' + aadhaar.length);

    if (!/^\d{12}$/.test(aadhaar)) {
        document.getElementById('abhaCreateMessage').innerHTML =
            '<div class="alert alert-danger">Please enter a valid 12 digit Aadhaar number.</div>';
        return;
    }

    alert('Calling ABDM OTP API');

    document.getElementById('abhaCreateMessage').innerHTML =
        '<div class="alert alert-info">Sending OTP...</div>';

    fetch('/modules/abdm/abha_request_aadhaar_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'aadhaar=' + encodeURIComponent(aadhaar) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(response => {
        alert('API response received. HTTP: ' + response.status);
        return response.text();
    })
    .then(text => {
        alert('Raw response received');

        console.log('ABDM RAW RESPONSE:', text);

        document.getElementById('abhaCreateMessage').innerHTML =
            '<div class="alert alert-info"><pre style="white-space:pre-wrap;text-align:left;">' +
            text.replace(/</g, '&lt;').replace(/>/g, '&gt;') +
            '</pre></div>';

        try {
            const data = JSON.parse(text);
            console.log('ABDM JSON:', data);

            if (!data.success) {
                document.getElementById('abhaCreateMessage').innerHTML =
                    '<div class="alert alert-danger">' +
                    '<strong>' + (data.message || 'Unable to send OTP.') + '</strong>' +
                    '<br>HTTP Code: ' + (data.http_code || 'N/A') +
                    '<br><pre style="white-space:pre-wrap;text-align:left;">' +
                    JSON.stringify(data.response || data, null, 2) +
                    '</pre></div>';
                return;
            }

            abhaCreateTxnId = data.txnId || '';

            document.getElementById('abhaCreateMessage').innerHTML =
                '<div class="alert alert-success">' +
                (data.message || 'OTP sent successfully.') +
                '</div>';

            document.getElementById('abhaAadhaarStep').style.display = 'none';
            document.getElementById('abhaOtpStep').style.display = 'block';

        } catch (e) {
            console.error('JSON PARSE ERROR:', e);
            document.getElementById('abhaCreateMessage').innerHTML =
                '<div class="alert alert-danger">' +
                'Invalid response from server.<br><pre style="white-space:pre-wrap;">' +
                text.replace(/</g, '&lt;').replace(/>/g, '&gt;') +
                '</pre></div>';
        }
    })
    .catch(error => {
        console.error('FETCH ERROR:', error);
        alert('FETCH ERROR: ' + error.message);

        document.getElementById('abhaCreateMessage').innerHTML =
            '<div class="alert alert-danger">' +
            'Fetch error: ' + error.message +
            '</div>';
    });
}
function verifyCreateABHA() {
    const otp = document.getElementById('abha_create_otp').value.trim();
    const mobile = document.getElementById('abha_create_mobile').value.trim();

    if (!/^\d{6}$/.test(otp)) {
        document.getElementById('abhaCreateMessage').innerHTML =
            '<div class="alert alert-danger">Please enter a valid 6 digit OTP.</div>';
        return;
    }

    if (!/^\d{10}$/.test(mobile)) {
        document.getElementById('abhaCreateMessage').innerHTML =
            '<div class="alert alert-danger">Please enter a valid 10 digit mobile number.</div>';
        return;
    }

    if (!abhaCreateTxnId) {
        document.getElementById('abhaCreateMessage').innerHTML =
            '<div class="alert alert-danger">Transaction expired. Please request OTP again.</div>';
        return;
    }

    document.getElementById('abhaCreateMessage').innerHTML =
        '<div class="alert alert-info">Verifying OTP and creating ABHA...</div>';

    fetch('/modules/abdm/abha_verify_aadhaar_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body:
            'txnId=' + encodeURIComponent(abhaCreateTxnId) +
            '&otp=' + encodeURIComponent(otp) +
            '&mobile=' + encodeURIComponent(mobile) +
            '&csrf=' + encodeURIComponent(window.csrfToken)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('abhaCreateMessage').innerHTML =
                '<div class="alert alert-danger">' +
                '<strong>' + (data.message || 'ABHA creation failed.') + '</strong>' +
                '</div>';
            return;
        }

        const response = data.response || {};
        const profile = response.ABHAProfile || {};

        let abhaNumber =
            profile.ABHANumber ||
            profile.abhaNumber ||
            response.ABHANumber ||
            response.abhaNumber ||
            '';

        if (!abhaNumber && response.accounts && response.accounts.length > 0) {
            abhaNumber = response.accounts[0].ABHANumber || '';
        }

        if (abhaNumber) {
            document.getElementById('abha_id').value = abhaNumber.replace(/\D/g, '').slice(0, 14).replace(/^(\d{2})(\d{4})(\d{4})(\d{4})$/, '$1-$2-$3-$4');
            document.getElementById('abha_verified').value = '1';

            closeCreateABHAModal();

            document.getElementById('abha_id').focus();
        } else {
            document.getElementById('abhaCreateMessage').innerHTML =
                '<div class="alert alert-warning">' +
                '<strong>ABHA created successfully.</strong><br>' +
                'ABHA number was not returned by ABDM.' +
                '</div>';
        }
    })
    .catch(error => {
        console.error(error);
        document.getElementById('abhaCreateMessage').innerHTML =
            '<div class="alert alert-danger">Unable to connect to ABDM.</div>';
    });
}
</script>
<?php /* GLOBAL ABDM SWITCH: rendered only when ABDM is enabled for this installation. */ if ($abdmGlobalEnabled): ?>
<div id="createABHAModal" class="abha-create-modal">
    <div class="abha-create-modal-box">
        <div class="abha-create-modal-header">
            <div>
                <h3>Create ABHA via Aadhaar</h3>
                <p>ABHA enrollment through ABDM</p>
            </div>
            <button type="button" class="abha-create-close" onclick="closeCreateABHAModal()">×</button>
        </div>
        <div class="abha-create-modal-body">
            <div id="abhaCreateMessage"></div>
            <div id="abhaAadhaarStep">
                <label>Aadhaar Number</label>
                <input type="text" id="abha_create_aadhaar" maxlength="12" inputmode="numeric" placeholder="Enter 12 digit Aadhaar number">
                <button type="button" class="abha-create-action" onclick="sendABHAOTP()">Send OTP</button>
            </div>
            <div id="abhaOtpStep" style="display:none;">
                <label>OTP</label>
                <input type="text" id="abha_create_otp" maxlength="6" inputmode="numeric" placeholder="Enter 6 digit OTP">
                <label>Mobile Number</label>
                <input type="text" id="abha_create_mobile" maxlength="10" inputmode="numeric" placeholder="Enter 10 digit mobile number">
                <button type="button" class="abha-create-action success" onclick="verifyCreateABHA()">Verify OTP & Create ABHA</button>
            </div>
        </div>
    </div>
</div>
<div id="fetchABHAModal" class="abha-create-modal">
    <div class="abha-create-modal-box">
        <div class="abha-create-modal-header">
            <div>
                <h3>Fetch ABHA Details</h3>
                <p>Verify ABHA through ABDM</p>
            </div>
            <button type="button" class="abha-create-close" onclick="closeFetchABHAModal()">×</button>
        </div>

        <div class="abha-create-modal-body">
            <div id="abhaFetchMessage"></div>

            <label>OTP</label>
            <input
                type="text"
                id="abha_fetch_otp"
                maxlength="6"
                inputmode="numeric"
                placeholder="Enter 6 digit OTP"
            >

            <button
                type="button"
                class="abha-create-action success"
                onclick="verifyABHAFetchOTP()"
            >
                Verify OTP & Fetch Details
            </button>
        </div>
    </div>
</div>
<div id="registrationAadhaarModal" class="abha-create-modal">
    <div class="abha-create-modal-box">
        <div class="abha-create-modal-header">
            <div>
                <h3>Verify Aadhaar</h3>
                <p>Enter the OTP sent to your Aadhaar registered mobile number</p>
            </div>
            <button
                type="button"
                class="abha-create-close"
                onclick="closeRegistrationAadhaarModal()">
                ×
            </button>
        </div>

        <div class="abha-create-modal-body">
            <div id="registrationAadhaarMessage"></div>

            <label>Aadhaar OTP</label>

            <input
                type="text"
                id="registrationAadhaarOtp"
                maxlength="6"
                inputmode="numeric"
                placeholder="Enter 6 digit OTP"
                oninput="this.value=this.value.replace(/\D/g,'').slice(0,6)"
            >

            <label style="margin-top:12px;">
                Primary Mobile Number
            </label>

            <input
                type="text"
                id="registration_aadhaar_mobile"
                maxlength="10"
                inputmode="numeric"
                placeholder="Enter 10 digit mobile number"
                oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
            >

            <button
                type="button"
                class="abha-create-action success"
                id="registrationAadhaarVerifyButton"
                onclick="verifyRegistrationAadhaarOtp()">
                Verify Aadhaar
            </button>
        </div>
    </div>
</div>    
    <div id="registrationMobileModal" class="abha-create-modal">
    <div class="abha-create-modal-box">
        <div class="abha-create-modal-header">
            <div>
                <h3>Verify Mobile</h3>
                <p>Enter the OTP sent to your mobile number</p>
            </div>
            <button type="button"
                    class="abha-create-close"
                    onclick="closeRegistrationMobileModal()">
                ×
            </button>
        </div>
        <div class="abha-create-modal-body">
            <div id="registrationMobileMessage"></div>
            <label>Mobile OTP</label>
            <input type="text"
                   id="registrationMobileOtp"
                   maxlength="6"
                   inputmode="numeric"
                   placeholder="Enter 6 digit OTP"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,6)">
            <button type="button"
                    class="abha-create-action success"
                    onclick="verifyRegistrationMobileOtp()">
                Verify OTP
            </button>
        </div>
    </div>
</div>

<div id="abhaCardModal" class="abha-card-modal">
    <div class="abha-card-modal-box">
        <div class="abha-card-modal-header">
            <div>
                <h3>ABHA Card</h3>
                <p>Ayushman Bharat Health Account</p>
            </div>

            <button type="button"
                    class="abha-create-close"
                    onclick="closeABHACard()">
                ×
            </button>
        </div>

        <div class="abha-card-modal-body">

            <div class="abha-card">

                <div class="abha-card-top">
                    <div>
                        <div class="abha-card-title">
                            ABHA
                        </div>
                        <div class="abha-card-subtitle">
                            Ayushman Bharat Health Account
                        </div>
                    </div>

                    <div class="abha-card-status"
                         id="abhaCardStatus">
                        ACTIVE
                    </div>
                </div>

                <div class="abha-card-content">

                    <div class="abha-card-photo-wrap">
                        <img id="abhaCardPhoto"
                             class="abha-card-photo"
                             src=""
                             alt="ABHA Profile Photo"
                             style="display:none;">

                        <div id="abhaCardPhotoPlaceholder"
                             class="abha-card-photo-placeholder">
                            👤
                        </div>
                    </div>

                    <div class="abha-card-details">

                        <div class="abha-card-row">
                            <span>Name</span>
                            <strong id="abhaCardName">-</strong>
                        </div>

                        <div class="abha-card-row">
                            <span>ABHA Number</span>
                            <strong id="abhaCardNumber">-</strong>
                        </div>

                        <div class="abha-card-row">
                            <span>ABHA Address</span>
                            <strong id="abhaCardAddress">-</strong>
                        </div>

                        <div class="abha-card-row">
                            <span>Date of Birth</span>
                            <strong id="abhaCardDob">-</strong>
                        </div>

                        <div class="abha-card-row">
                            <span>Gender</span>
                            <strong id="abhaCardGender">-</strong>
                        </div>

                        <div class="abha-card-row">
                            <span>Mobile</span>
                            <strong id="abhaCardMobile">-</strong>
                        </div>

                    </div>

                </div>

                <div class="abha-card-footer">
                    <span>ABDM</span>
                    <span id="abhaCardFooterNumber">ABHA</span>
                </div>

            </div>

            <div class="abha-card-actions">
                <button type="button"
                        class="btn btn-secondary"
                        onclick="printABHACard()">
                    Print Card
                </button>

                <button type="button"
                        class="btn btn-primary"
                        onclick="closeABHACard()">
                    Close
                </button>
            </div>

        </div>
    </div>
</div>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>
</body>
</html>