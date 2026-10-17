<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$prescription_id=(int)($_GET['prescription_id'] ?? $_POST['prescription_id'] ?? 0);
$appointment_id=(int)(
    $_GET['id']
    ?? $_GET['appointment_id']
    ?? $_POST['id']
    ?? $_POST['appointment_id']
    ?? 0
);
$department_id = 0;
$stmt=$conn->prepare("
SELECT 
type,
department_id,
entity_id
FROM appointments
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$result=$stmt->get_result();
if($row=$result->fetch_assoc()){
    $department_id=(int)$row['department_id'];
}
$stmt->close();
$mode=$_GET['mode'] ?? $_POST['mode'] ?? 'edit';
$patient_type='opd';
$stmt=$conn->prepare("
SELECT 
type,
department_id
FROM appointments
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$appointment=$stmt->get_result()->fetch_assoc();
$stmt->close();
if($appointment && $appointment['type']=='ipd'){
$patient_type='ipd';
}
require $_SERVER['DOCUMENT_ROOT'].'/includes/prescription_setup_helper.php';
$showTabs=getDoctorPrescriptionTabs($conn,$appointment_id);
/* ==========================================
LOAD PRESCRIPTION
EDIT = LOAD EXACT PRESCRIPTION BY ID
========================================== */

$prescription=[];

if($mode==='edit' && $prescription_id>0){

    $stmt=$conn->prepare("
        SELECT *
        FROM opd_prescriptions
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $prescription_id
    );

    $stmt->execute();

    $result=$stmt->get_result();

    if($result && $result->num_rows>0){

        $prescription=$result->fetch_assoc();

        $appointment_id=(int)(
            $prescription['appointment_id']
            ?? $appointment_id
        );

        $patient_type=
            $prescription['patient_type']
            ?? $patient_type;

    }

    $stmt->close();
}
/* ==========================================
REFRESH APPOINTMENT CONTEXT
========================================== */

$department_id=0;

if($appointment_id>0){

    $stmt=$conn->prepare("
        SELECT
            type,
            department_id,
            entity_id
        FROM appointments
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $appointment_id
    );

    $stmt->execute();

    $result=$stmt->get_result();

    if($appointment=$result->fetch_assoc()){

        $department_id=(int)(
            $appointment['department_id'] ?? 0
        );

        if(($appointment['type'] ?? '')==='ipd'){
            $patient_type='ipd';
        }

    }

    $stmt->close();
}

// Revised query block: filter only by prescription ID
if ($mode === 'edit' && $prescription_id > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM opd_prescriptions
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $prescription_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows) {
        $prescription = $result->fetch_assoc();
        // Update appointment_id and patient_type from the fetched row if needed
        $appointment_id = (int)($prescription['appointment_id'] ?? $appointment_id);
        $patient_type   = $prescription['patient_type'] ?? $patient_type;
    }
    $stmt->close();
}
/* ==========================================
   SELECTED SERVICES FOR EDIT
========================================== */
$selectedServices=[];
if(
    $mode==='edit' &&
    !empty($prescription['selected_services'])
){
    $selectedServices=array_values(
        array_filter(
            array_map(
                'trim',
                explode(
                    ',',
                    $prescription['selected_services']
                )
            )
        )
    );

}
/* ===============================
LOAD EXISTING IPD DOCTOR VISITS
EDIT MODE ONLY
================================ */
$doctorVisits=[];
if(
    $mode==='edit' &&
    $patient_type==='ipd'
){
    $stmt=$conn->prepare("
    SELECT
        doctor_id,
        visit_date,
        visit_time,
        charge_type,
        charges,
        comment
    FROM ipd_doctor_visits
    WHERE prescription_id=?
    ORDER BY id ASC
");
$stmt->bind_param(
    "i",
    $prescription_id
);
    $stmt->execute();
    $result=$stmt->get_result();
    while($row=$result->fetch_assoc()){
        $doctorVisits[]=$row;
    }
    $stmt->close();
}
/* ==========================================
PATIENT VITALS
========================================== */
$weight     = $prescription['weight'] ?? '';
$height     = $prescription['height'] ?? '';
$bmi        = $prescription['bmi'] ?? '';
$pain_scale = $prescription['pain_scale'] ?? '';
/* ==========================================
EYE DETAILS
========================================== */
$eyeDetails=[];
if(!empty($prescription['eye_details'])){
    $eyeDetails=json_decode($prescription['eye_details'],true);
    if(!is_array($eyeDetails)){
        $eyeDetails=[];
    }
}
/* ===============================
   LOAD ACTIVE USERS
================================ */
$userResult=mysqli_query($conn,"
SELECT
id,
full_name
FROM users
WHERE status='Active'
ORDER BY full_name
");
/* ===============================
LOAD EXISTING NURSING NOTES
================================ */
$nursingNotes=[];
$stmt=$conn->prepare("
SELECT nursing_notes
FROM opd_prescriptions
WHERE appointment_id=?
AND patient_type=?
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param("is",$appointment_id,$patient_type);
$stmt->execute();
$result=$stmt->get_result();
if($result && $result->num_rows>0){
    $prescriptionRow=$result->fetch_assoc();
    if(!empty($prescriptionRow['nursing_notes'])){
        $decoded=json_decode($prescriptionRow['nursing_notes'],true);
        if(is_array($decoded)){
            $nursingNotes=$decoded;
        }
    }
}
$stmt->close();
/* LOAD TEMPLATES */
$templates = [];
$q = $conn->query("
SELECT
id,
template_name,
template_text,
template_type
FROM prescription_templates
ORDER BY template_name ASC
");
if($q){
    while($row = $q->fetch_assoc()){
        $templates[$row['template_type']][] = $row;
    }
}
/* SECTIONS LIST */
$sections = [
"chief_complaint"=>"Chief Complaint",
"drug_history"=>"Drug History",
"past_history"=>"Past History",
"present_illness"=>"Present Illness History",
"diagnosis"=>"Diagnosis",
"medicines"=>"Medicines",
"procedure_advice"=>"Procedure Advice",
"advice"=>"Advice",
"prognosis"=>"Prognosis",
"remarks"=>"Remarks"
];
/* CATEGORY LIST */
$categoryResult = mysqli_query($conn,"
SELECT DISTINCT category_type
FROM service_master
WHERE status='Active'
ORDER BY category_type
");
/* MAIN SERVICE LIST */
$mainServiceResult = mysqli_query($conn,"
SELECT
id,
service_name
FROM service_master
WHERE status='Active'
AND service_type='Main Service'
ORDER BY service_name
");
/* SERVICE LIST */
$serviceDropdownResult = mysqli_query($conn,"
SELECT
    id,
    service_name,
    category_type,
    CONCAT(category_type,' → ',service_name) AS display_name
FROM service_master
WHERE status='Active'
ORDER BY category_type, service_name
");
$serviceResult = mysqli_query($conn,"
SELECT
    id,
    category_type,
    service_type,
    main_service_id,
    service_name,
    service_code,
    service_mode,
    amount,
    CONCAT(category_type,' → ',service_name) AS display_name
FROM service_master
WHERE status='Active'
ORDER BY category_type, service_name
");
$categoryItemResult = mysqli_query($conn,"
SELECT
id,
category_name
FROM category_master
WHERE status='Active'
AND parent_id=0
ORDER BY category_name
");
$subCategoryItemResult = mysqli_query($conn,"
SELECT
id,
parent_id,
category_name
FROM category_master
WHERE status='Active'
AND parent_id<>0
ORDER BY category_name
");
$storeResult = mysqli_query($conn,"
SELECT
id,
store_name
FROM stores
WHERE status='Active'
ORDER BY store_name
");
$manufacturerResult = mysqli_query($conn,"
SELECT
id,
party_name
FROM party_master
WHERE status='Active'
ORDER BY party_name
");
/* ===============================
LOAD PARAMETERS WITH LATEST READING
================================ */
$stmt=$conn->prepare("
SELECT
pm.id,
pm.parameter_name,
pm.unit,
(
SELECT pr.reading
FROM parameter_readings pr
WHERE pr.parameter_id=pm.id
AND pr.appointment_id=?
ORDER BY pr.reading_date DESC,pr.reading_time DESC,pr.id DESC
LIMIT 1
) AS latest_reading
FROM parameter_master pm
WHERE pm.status='1'
ORDER BY pm.parameter_name ASC
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$parameterResult=$stmt->get_result();
/* ===============================
   LOAD DISEASE MASTER
================================ */
$diseaseResult=mysqli_query($conn,"
SELECT
id,
disease_name,
icd_code
FROM disease_master
WHERE status=1
ORDER BY disease_name ASC
");
$itemResult = mysqli_query($conn,"
SELECT
    i.id,
    i.item_name,
    i.category_id,
    i.sub_category_id,
    i.manufacturer_id,
    i.item_type,
    i.packing,
    i.min_level,
    COALESCE(SUM(b.qty_available),0) AS stock,
    GROUP_CONCAT(DISTINCT b.store_id) AS stores
FROM item_master i
LEFT JOIN inventory_batches b
ON b.item_id=i.id
AND b.status='Active'
WHERE i.status='Active'
GROUP BY i.id
ORDER BY i.item_name
");

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Prescription</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
*{
    box-sizing:border-box;
}
html,body{
    margin:0;
    padding:0;
    width:100%;
    min-height:100%;
}
body{
    font-family:Inter,"Segoe UI",Arial,sans-serif;
    background:var(--bg);
    color:var(--text);
    font-size:14px;
}
.container{
    width:100%;
    max-width:none;
    margin:0;
    padding:0;
}
.header{
position:sticky;
top:0;
z-index:50;
display:flex;
align-items:center;
justify-content:space-between;
min-height:52px;
padding:8px 16px;
border-radius:10px;
background:#1976d2;
color:#fff;
box-shadow:0 8px 20px rgba(0,0,0,.08);
margin:5px;
}
.header h1{
margin:0;
font-size:18px;
font-weight:bold;
letter-spacing:0;
}
.header p{
    margin:3px 0 0;
    font-size:12px;
    opacity:.9;
}
.disease-section{
margin-bottom:12px;
}
.disease-title{
font-size:16px;
font-weight:600;
margin-bottom:6px;
}
.disease-search{
width:100%;
height:34px;
padding:5px 10px;
margin-bottom:7px;
border:1px solid #d5d9df;
border-radius:6px;
}
.disease-columns{
display:grid;
grid-template-columns:1fr 1fr;
gap:8px;
}
.disease-column{
border:1px solid #d9dee5;
border-radius:6px;
overflow:hidden;
background:#fff;
}
.disease-column-title{
height:30px;
display:flex;
align-items:center;
justify-content:space-between;
padding:0 10px;
font-size:12px;
font-weight:600;
background:#f1f3f5;
border-bottom:1px solid #d9dee5;
}
#selectedDiseaseCount{
min-width:20px;
height:20px;
line-height:20px;
text-align:center;
border-radius:10px;
background:#e2e8f0;
font-size:11px;
}
.disease-list,
.selected-disease-list{
height:105px;
overflow-y:auto;
padding:4px;
}
.disease-option{
display:flex;
align-items:center;
gap:7px;
padding:5px 6px;
margin:0;
font-size:12px;
cursor:pointer;
border-radius:4px;
color: black;
}
.disease-option:hover{
background:#f5f7fa;
}
.disease-empty{
padding:10px;
text-align:center;
font-size:12px;
color:#888;
}
@media(max-width:800px){
.disease-columns{
grid-template-columns:1fr;
}
}
.card{
    position:relative;
    background:#fff;
    border:1px solid #ddd;
    border-radius:10px;
    padding:5px;
    margin-bottom:5px;
    box-shadow:0 8px 20px rgba(0,0,0,.08);
    overflow:hidden;
}
.card1{
    position:relative;
    background:#fff;
    border:1px solid #ddd;
    border-radius:10px;
    padding:5px;
    margin-bottom:5px;
    box-shadow:0 8px 20px rgba(0,0,0,.08);
    overflow:hidden;
    display: flex;
    flex-direction: row;
    gap: 10px;
}
.tab-content.active {
  display: block;
  flex-direction: row;
  align-items: flex-start;
  gap: 20px;
}

.card {
  flex: 0 0 auto;
  width: 100%;
}
/* Blue top line */
.card::before{
    content:"";
    position:absolute;
    top:0;
    left:0;
    right:0;
    height:4px;
    background:#1976d2;
}
.notes-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:7px;
}
.card h3{
margin:0;
padding:10px 15px;
font-size:15px;
font-weight:bold;
display:flex;
align-items:center;
gap:7px;
width:100%;
}
.card h3 span{
background:transparent !important;
color:#fff !important;
width:auto !important;
height:auto !important;
border-radius:0 !important;
display:inline !important;
font-size:15px !important;
box-shadow:none !important;
}
.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(230px,1fr));
    gap:16px;
}
label{
    font-size:12px;
    font-weight:600;
    color:var(--muted);
    margin-bottom:5px;
    display:block;
}
input,select,textarea{
    padding:7px 7px;
    border-radius:10px;
    border:1px solid #e5e7eb;
    font-size:14px;
    background:#f9fafb;
    transition:.2s;
}
input:focus,select:focus,textarea:focus{
    outline:none;
    border-color:var(--primary);
    background:#fff;
    box-shadow:0 0 0 3px rgba(37,99,235,.15);
}
textarea{ resize:vertical; }
.inline{
    display:flex;
    gap:6px;
}
.readonly{
    background:#f1f5f9 !important;
}
button {
    background: #1976d2;
    color: #fff;
    border: none;
    font-size: 16px;
    border-radius: 6px;
    cursor: pointer;
}
.btn-primary{
background:#1976d2;
color:#fff;
border:1px solid #1565c0;!important
border-radius:6px;
padding:8px 18px;
font-size:13px;
font-weight:bold;
cursor:pointer;
box-shadow:none;
transition:background .18s ease,border-color .18s ease;
}
.btn-primary:hover{
background:#1565c0;
border-color:#0d47a1;
}
.btn-primary:focus{
outline:none;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}
.btn-secondary{
    background:#64748b;
    color:#fff;
}
.actions{
    display:flex;
    justify-content:flex-end;
    gap:12px;
    margin-top:25px;
}
.latest-reading-value{
display:flex;
align-items:center;
justify-content:center;
gap:6px;
}
.parameter-history-btn{
background:none;
border:none;
padding:0;
cursor:pointer;
font-size:17px;
}
.parameter-history-btn:hover{
transform:scale(1.15);
}
.parameter-reading,
.parameter-date,
.parameter-time,
.parameter-user{
height:38px;
}
.parameter-reading,
.parameter-date,
.parameter-time,
.parameter-user{
height:38px;
}
#parameterTable thead th{
position:sticky;
top:0;
background:#f8fafc;
z-index:5;
}
.field{
    margin-bottom:12px;
}
.field-error {
    border: 2px solid #dc2626 !important;
    background: #fff5f5 !important;
}
.select2-container .field-error {
    border: 2px solid #dc2626 !important;
}
.template-bar{
display:flex;
gap:8px;
margin-bottom:10px;
}
.template-bar select{
flex:1;
}
.template-btn{
background:var(--primary);
color:#fff;
border:none;
padding:6px 12px;
border-radius:6px;
cursor:pointer;
}
textarea{
width:100%;
min-height:50px;
border-radius:10px;
border:1px solid #8d7878;
padding:8px;
background:#f9fafb;
}
.save-template{
display:flex;
gap:10px;
margin-top:8px;
}
button.submit-btn{
background:linear-gradient(135deg,var(--primary),var(--accent));
color:#fff;
border:groove;
padding:5px 15px;
border-radius:8px;
cursor:pointer;
font-size:15px;
}
.template-combo{
     position:relative;
    display:flex;
    align-items:center;
    gap:10px;
    width:100%;
}
/* search box */
.template-combo input[type="text"]:first-child{
    
    min-width:250px;
}
/* template name input */
.template-combo input[type="text"]:last-child{
    min-width:300px;
}
.nursing-notes-table{
width:100%;
border-collapse:collapse;
background:#fff;
}
.nursing-notes-table th,
.nursing-notes-table td{
border:1px solid #dfe3e8;
padding:7px;
vertical-align:middle;
}
.nursing-notes-table th{
background:#f5f7fa;
font-size:13px;
font-weight:600;
text-align:left;
white-space:nowrap;
}
.nursing-notes-table input{
width:100%;
box-sizing:border-box;
padding:7px 8px;
border:1px solid #ccd2d8;
border-radius:4px;
}
.nursing-sr{
text-align:center;
font-weight:600;
}
.nursing-add-btn,
.selected-medicine-wrap{
overflow-x:auto;
}
.selected-medicine-table{
width:100%;
min-width:1200px;
border-collapse:collapse;
background:#fff;
}
.selected-medicine-table th,
.selected-medicine-table td{
border:1px solid #d9dee5;
padding:4px;
vertical-align:middle;
}
.selected-medicine-table th{
background:#f1f3f5;
font-size:12px;
font-weight:600;
text-align:center;
white-space:nowrap;
}
.selected-medicine-table input,
.selected-medicine-table select{
width:100%;
height:34px;
padding:4px 6px;
border:1px solid #ccd2d8;
border-radius:4px;
background:#fff;
font-size:12px;
}
.selected-medicine-name{
font-size:13px;
font-weight:600;
white-space:nowrap;
}
.selected-medicine-stop{
width:18px !important;
height:18px !important;
}
.selected-medicine-delete{
width:28px;
height:28px;
padding:0;
border:0;
border-radius:4px;
background:#dc3545;
color:#fff;
font-size:16px;
cursor:pointer;
}
.nursing-remove-btn{
border:0;
border-radius:4px;
padding:6px 11px;
font-size:16px;
font-weight:bold;
cursor:pointer;
}
.nursing-add-btn{
color:#fff;
}
.nursing-remove-btn{
background:#dc3545;
color:#fff;
}
/* checkbox */
.save-check{
    display:flex;
    align-items:center;
    gap:5px;
    font-size:13px;
    white-space:nowrap;
}
.template-list{
position:absolute;
width:300px;
top:100%;
left:0;
right:0;
background:#fff;
border:1px solid #ddd;
border-radius:6px;
max-height:200px;
overflow:auto;
display:none;
z-index:999;
}
.template-list div{
padding:8px;
cursor:pointer;
border-bottom:1px solid #eee;
}
.template-list div:hover{
background:#eef2ff;
}

/* ===============================
   PRESCRIPTION TABS
================================ */

.tabs{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    padding:8px;
    margin-bottom:15px;
    background:#ffffff;
    border:1px solid #dbe3ec;
    border-radius:12px;
    box-shadow:0 4px 12px rgba(0,0,0,.06);
}
/* TAB BUTTON */
.tab-btn{
    position:relative;
    display:flex;
    align-items:center;
    gap:6px;
    background:#f8fafc;
    color:#000000;
    border:1px solid #dbe3ec;
    padding:9px 16px;
    cursor:pointer;
    border-radius:8px;
    font-size:14px;
    font-weight:600;
    transition:all .25s ease;
}
/* Hover */
.tab-btn:hover{
    background:#eaf3ff;
    color:#1976d2;
    border-color:#90caf9;
}
/* Active */
.tab-btn.active{
    background:#1976d2;
    color:#fff;
    border-color:#1976d2;
    box-shadow:
    0 4px 10px rgba(25,118,210,.25);
}
/* Active top indicator */
.tab-btn.active::after{
    content:"";
    position:absolute;
    bottom:-5px;
    left:50%;
    transform:translateX(-50%);
    width:35px;
    height:3px;
    background:#42a5f5;
    border-radius:5px;
}
/* CONTENT */
.tab-content{
    display:none;
margin: 0 0 0 10px;
    animation:fadeTab .25s ease;
}
.tab-content.active {
  display: block;
  flex-direction: row;
  align-items: flex-start;
  gap: 20px;
}

/* Animation */
@keyframes fadeTab{
from{
opacity:0;
transform:translateY(5px);
}
to{
opacity:1;
transform:translateY(0);
}
}
/* Mobile */
@media(max-width:700px){
.tabs{
gap:6px;
}
.tab-btn{
flex:1;
justify-content:center;
padding:8px 10px;
font-size:12px;
}
}
.eye-card{
flex:1;
min-width:470px;
background:#ffffff;
border-radius:14px;
padding:18px;
border:1px solid #dbeafe;
box-shadow:0 8px 20px rgba(0,0,0,.08);
}
.eye-table{
width:100%;
border-collapse:collapse;
}
.eye-table th{
background:#1976d2;
color:#fff;
padding:10px;
text-align:center;
border:1px solid #dbeafe;
}
.eye-table td{
border:1px solid #e5e7eb;
padding:8px;
text-align:center;
}
.eye-table td:first-child{
background:#f8fafc;
font-weight:600;
width:110px;
}
.eye-table input{
width:100%;
text-align:center;
padding:8px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
}
.eye-table input:focus{
border-color:#1976d2;
box-shadow:0 0 0 3px rgba(25,118,210,.15);
background:#fdfefe;
}
.parameter-chart-btn{
display:flex;
justify-content:center;
align-items:center;
width:28px;
height:28px;
margin:auto;
background:none;
border:none;
cursor:pointer;
}
@media(max-width:600px){
    .header h1{ font-size:22px; }
    .actions{ justify-content:center; }
}
@media(max-width:600px){
    .template-combo{
        flex-wrap:wrap;
    }
}
@media(max-width:800px){
.notes-grid{
    grid-template-columns:1fr;
}
}
</style>
</head>
<body>
<div class="container">
<div class="header">
<div style="display:flex;justify-content:space-between;align-items:center;width:100%;gap:15px;">
    <h1 style="margin:0;">
        <?= $patient_type=='ipd' ? 'IPD Prescription' : 'OPD Prescription' ?>
    </h1>
    <button type="submit"
            form="prescriptionForm"
            class="submit-btn"
            style="margin-left:auto; margin-right:40px;">
        💾 Save Prescription
    </button>
</div>
</div>
<form
id="prescriptionForm"
method="post"
action="save_prescription.php"
onsubmit="return preparePrescriptionData();">
    <div class="tabs">
<!-- ===============================
NOTES TAB
================================ -->
<?php if(!empty($showTabs['notes'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('notes')">
📝 Notes
</button>
<?php } ?>
<!-- ===============================
PARAMETERS TAB
================================ -->
<?php if(!empty($showTabs['parameters'])){ ?>
<button type="button"
class="tab-btn"
onclick="openTab('parameters_tab')">
📈 Parameters
</button>
<?php } ?>
<!-- ===============================
Diagnosis TAB
================================ -->
<?php if(!empty($showTabs['diagnosis'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('diagnosis_tab')">
🩺 Diagnosis
</button>
<?php } ?>
<!-- ===============================
Services TAB
================================ -->
<?php if(!empty($showTabs['services'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('services_tab')">
🏥 Services
</button>
<?php } ?>
<!-- ===============================
Diet TAB
================================ -->
<?php if(!empty($showTabs['diet_details'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('diet_tab')">
🥗 Diet Details
</button>
<?php } ?>
<!-- ===============================
Eye TAB
================================ -->
<?php if(!empty($showTabs['eye_details'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('eye_tab')">
👁 Eye Details
</button>
<?php } ?>
<!-- ===============================
Gynae TAB
================================ -->
<?php if(!empty($showTabs['gynae_details'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('gynae_tab')">
🤰 Gynae Details
</button>
<?php } ?>
<!-- ===============================
embryology TAB
================================ -->
<?php if(!empty($showTabs['embryology'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('embryology_tab')">
🧬 Embryology
</button>
<?php } ?>
<!-- ===============================
bariatric TAB
================================ -->
<?php if(!empty($showTabs['bariatric'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('bariatric_tab')">
⚖ Bariatric
</button>
<?php } ?>
<!-- ===============================
nursing notes TAB
================================ -->
<?php if(!empty($showTabs['nursing_notes'])){ ?>
<button type="button" class="tab-btn" onclick="openTab('nursing_notes')">
👩‍⚕️ Nursing Notes
</button>
<?php } ?>
</div>
<input type="hidden" name="appointment_id" value="<?= $appointment_id ?>">
<input type="hidden" name="patient_type" value="<?= htmlspecialchars($patient_type) ?>">
<input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
<input type="hidden" name="prescription_id" value="<?= (int)$prescription_id ?>">
<input type="hidden" name="selected_services" id="selected_services">
<input type="hidden" name="selected_items" id="selected_items">
<input type="hidden" name="selected_diseases" id="selected_diseases" form="prescriptionForm">
<input type="hidden" name="parameter_readings" id="parameter_readings">
<input type="hidden" name="eye_details" id="eye_details_json">
<?php if(!empty($showTabs['notes'])){ ?>
<div id="notes" class="tab-content">
<div class="notes-grid">
<?php foreach($sections as $key=>$title): ?>
<?php
$dbField = $key;
if($key=='present_illness'){
    $dbField='present_illness_history';
}
elseif($key=='diagnosis'){
    $dbField='diagnosis_section';
}
?>
<?php if(!empty($showTabs[$dbField])){ ?>
<div class="card">
<div class="template-bar">
<h3><?= $title ?></h3>
<div class="template-combo">
    <input type="text"
        id="<?= $key ?>_search"
        placeholder="Search Template"
        autocomplete="off">
    <div id="<?= $key ?>_list" class="template-list">
        <?php if(!empty($templates[$key])): ?>
        <?php foreach($templates[$key] as $t): ?>
        <div data-text="<?= htmlspecialchars($t['template_text']) ?>"
             onclick="selectTemplate('<?= $key ?>', this)">
            <?= htmlspecialchars($t['template_name']) ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <input type="text"
        name="<?= $key ?>_template_name"
        placeholder="Template Name">
        <label class="save-check">
        <input type="checkbox" name="save_<?= $key ?>_template">
        Save as Template
    </label>
</div>
</div>
<textarea
name="<?= $key ?>"
id="<?= $key ?>_area"><?= htmlspecialchars($prescription[$key] ?? '') ?></textarea>
</div>
<?php } ?>
<?php endforeach; ?>
</div>
<?php } ?>
</div>
<?php if(!empty($showTabs['parameters'])){ ?>
<div id="parameters_tab" class="tab-content">
<!-- ===============================
DISEASE SELECTION
================================ -->
<div class="card disease-section">
<h3>
🩺 Diseases
</h3>
<input type="text"
id="diseaseSearch"
class="disease-search"
placeholder="Search Disease / ICD Code">
<div class="disease-columns">
<div class="disease-column">
<div class="disease-column-title">Available Diseases</div>
<div id="diseaseList" class="disease-list">
<?php while($disease=mysqli_fetch_assoc($diseaseResult)){ ?>
<div class="disease-row"
data-search="<?= htmlspecialchars(strtolower(($disease['disease_name'] ?? '').' '.($disease['icd_code'] ?? ''))) ?>">
<label class="disease-option">
<input type="checkbox"
class="disease-checkbox"
value="<?= (int)$disease['id'] ?>"
data-name="<?= htmlspecialchars($disease['disease_name'] ?? '') ?>"
data-icd="<?= htmlspecialchars($disease['icd_code'] ?? '') ?>">
<span>
<?= htmlspecialchars($disease['disease_name'] ?? '') ?>
<?php if(!empty($disease['icd_code'])){ ?>
(<?= htmlspecialchars($disease['icd_code']) ?>)
<?php } ?>
</span>
</label>
</div>
<?php } ?>
</div>
</div>
<div class="disease-column">
<div class="disease-column-title">
Selected Diseases
<span id="selectedDiseaseCount">0</span>
</div>
<div id="selectedDiseases" class="selected-disease-list">
<div class="disease-empty">No disease selected</div>
</div>

</div>
</div>
</div>
    <!-- ===============================
PATIENT VITALS
================================ -->
    <?php if(!empty($showTabs['patient_vitals'])){ ?>
<div class="card">
<h3>📋 Patient Vitals</h3>
<div style=" display:grid; grid-template-columns:180px 180px 200px 1fr; gap:15px; align-items:end; ">
<div>
    <label>Weight (Kg)</label>
<input
type="number"
step="0.01"
id="weight"
name="weight"
placeholder="Weight"
value="<?= htmlspecialchars($weight) ?>">
</div>
<div>
<label>Height (Cm)</label>
<input
type="number"
step="0.01"
id="height"
name="height"
placeholder="Height"
value="<?= htmlspecialchars($height) ?>">
</div>
<div>
<label>BMI Index</label>
<input type="text" id="bmi" name="bmi" value="<?= htmlspecialchars($bmi) ?>" readonly style=" background:#eef6ff; font-weight:700; text-align:center; color:#1976d2; ">
</div>
<div>
<label>Pain Scale (0 - No Pain) (10 - Wrost)</label>
<div style=" display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border:1px solid #d1d5db; border-radius:10px; background:#fafafa; ">
<?php for($i=0;$i<=10;$i++){ ?>
<label style=" display:flex; flex-direction:row; align-items:center; cursor:pointer; margin:0; font-size:15px; ">
<input
type="radio"
name="pain_scale"
value="<?= $i ?>"
<?= ((string)$pain_scale==(string)$i)?'checked':'' ?> style="margin-bottom:5px;">
<span style=" font-weight:600; color: <?= $i<=3 ? '#16a34a' : ($i<=6 ? '#f59e0b' : '#dc2626') ?>; "> <?= $i ?>
</span>
</label>
<?php } ?>
</div>
</div>
</div>
</div>
<?php } ?>
<!-- ===============================
PATIENT PARAMETERS
================================ -->
<div class="card">
<h3 style="margin-top:0;">
📈 Patient Parameters
</h3>
<div class="table-responsive">
<table
class="table table-bordered table-hover align-middle"
id="parameterTable"
style="width:100%;margin-bottom:0;">
<thead>
<tr>
<th style="width:50px;text-align:center;">Sr.</th>
<th style="min-width:220px;">Parameter</th>
<th style="width:180px;text-align:center;">Latest Reading</th>
<th style="width:180px;text-align:center;">Current Reading</th>
<th style="width:140px;text-align:center;">Date</th>
<th style="width:120px;text-align:center;">Time</th>
<th style="width:170px;text-align:center;">By</th>
<th style="width:70px;text-align:center;">📈</th></tr>
</thead>
<tbody id="parameterBody">
<?php
$sr=1;
while($parameter=mysqli_fetch_assoc($parameterResult)){
?>
<tr>
<td><?= $sr++ ?></td>
<td>
<strong style="font-size:14px;">
<?= htmlspecialchars($parameter['parameter_name']) ?>
<?php if(!empty($parameter['unit'])){ ?>
<span style="
font-weight:normal;
font-size:12px;
color:#6c757d;
">
(<?= htmlspecialchars($parameter['unit']) ?>)
</span>
<?php } ?>
</strong>
</td>
<td class="latest-reading" style="text-align:center;">
<div class="latest-reading-value">
<span class="reading-value">
<?= ($parameter['latest_reading']!==null && $parameter['latest_reading']!=='')
? htmlspecialchars($parameter['latest_reading'])
: '--' ?>
</span>
</div>
</td>
<td> <input type="text" class="form-control parameter-reading" data-parameter-id="<?= $parameter['id'] ?>" placeholder="Enter Reading" style=" width:140px; text-align:center; font-weight:600; " autocomplete="off"></td>
<td><input type="date" class="parameter-date" value="<?= date('Y-m-d') ?>"> </td>
<td> <input type="time" class="parameter-time" value="<?= date('H:i') ?>"></td>
<td><select class="parameter-user" data-parameter-id="<?= $parameter['id'] ?>">
<option value="">Select</option>
<?php
mysqli_data_seek($userResult,0);
while($user=mysqli_fetch_assoc($userResult)){
?>
<option
value="<?= $user['id'] ?>"
<?= ($_SESSION['user_id']==$user['id']) ? 'selected' : '' ?>>
<?= htmlspecialchars($user['full_name']) ?>
</option>
<?php } ?>
</select>
</td>
<td style="text-align:center;">
<button
type="button"
class="parameter-chart-btn"
data-parameter-id="<?= $parameter['id'] ?>"
data-parameter-name="<?= htmlspecialchars($parameter['parameter_name']) ?>"
title="View Trend"
style="
background:none;
border:none;
cursor:pointer;
font-size:18px;
padding:0;
">
📈
</button>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>
<?php } ?>
<!-- Diagnosis Tab -->
<?php if(!empty($showTabs['diagnosis'])){ ?>
<div id="diagnosis_tab" class="tab-content">
    <!-- ===============================
DOCTOR VISITS
================================ -->
<div class="card">
<h3>
👨‍⚕️ Doctor Visits
</h3>
<div style="overflow-x:auto;">
<table class="table table-bordered" id="doctorVisitTable">
<thead>
<tr>
<th>Doctor</th>
<th>Date</th>
<th>Time</th>
<th>Charge Type</th>
<th>Charges</th>
<th>Comment</th>
<th width="50"><button type="button" 
onclick="addDoctorVisitRow()">
Add
</button></th>
</tr>
</thead>
<tbody id="doctorVisitBody">
</tbody>
</table>
</div>
</div>
<!-- END DOCTOR VISITS -->
<div class="card1">
    <!-- Available Items -->
    <div style="width:20%;">
        <div style="
display:flex;
gap:10px;
align-items:center;
">
<input
type="text"
id="itemSearch"
placeholder="🔍 Search Medicine"
style="flex:2;">
<select
id="categoryItemFilter"
style="flex:1;">
<option value="">
Category
</option>
<?php while($cat=mysqli_fetch_assoc($categoryItemResult)){ ?>
<option
value="<?= $cat['id'] ?>">
<?= htmlspecialchars($cat['category_name']) ?>
</option>
<?php } ?>
</select>
<select
id="subCategoryItemFilter"
style="flex:1;">
<option value="">
Sub Category
</option>
<?php while($sub=mysqli_fetch_assoc($subCategoryItemResult)){ ?>
<option
value="<?= $sub['id'] ?>"
data-parent="<?= $sub['parent_id'] ?>">
<?= htmlspecialchars($sub['category_name']) ?>
</option>
<?php } ?>
</select>
<select
id="storeFilter"
style="flex:1;">
<option value="">
Store
</option>
<?php while($store=mysqli_fetch_assoc($storeResult)){ ?>
<option value="<?= $store['id'] ?>">
<?= htmlspecialchars($store['store_name']) ?>
</option>
<?php } ?>
</select>
<select
id="manufacturerFilter"
style="flex:1;">
<option value="">
Manufacturer
</option>
<?php while($m=mysqli_fetch_assoc($manufacturerResult)){ ?>
<option
value="<?= $m['id'] ?>">
<?= htmlspecialchars($m['party_name']) ?>
</option>
<?php } ?>
</select>
</div>
        <div id="itemList"
             style="height:500px;overflow:auto;border:1px solid #ddd;padding:10px;">
<?php while($item=mysqli_fetch_assoc($itemResult)){ ?>
<div
class="item-row"
data-name="<?= strtolower($item['item_name']) ?>"
data-category="<?= $item['category_id'] ?>"
data-subcategory="<?= $item['sub_category_id'] ?>"
data-manufacturer="<?= $item['manufacturer_id'] ?>"
data-store="<?= htmlspecialchars($item['stores']) ?>">
<label style="display:flex;align-items:center;width:100%;cursor:pointer;">
<div style="flex:1;">
<div style="display:flex;align-items:center;width:100%;">
<div style="
flex:1;
font-size:16px;
font-weight:600;
color:#111827;
">
<?= htmlspecialchars($item['item_name']) ?>
</div>
<div style="
margin-left:auto;
font-size:12px;
white-space:nowrap;
">
<?php
$stock = (float)$item['stock'];
$min   = (float)$item['min_level'];
?>
<span style="
color:<?= ($stock <= $min) ? '#dc2626' : '#16a34a' ?>;
font-weight:600;
padding:8px;
">
<?= $stock ?>
</span>
</div>
</div>
</div>
<input type="checkbox"
       class="item-checkbox"
       value="<?= $item['id'] ?>"
       data-name="<?= htmlspecialchars($item['item_name']) ?>"
       data-stock="<?= $item['stock'] ?>"
       data-min="<?= $item['min_level'] ?>">
</label>
<hr style="margin:0;">
</div>
<?php } ?>
        </div>
    </div>
    <!-- Selected Items -->
    <div style="width:80%;">
        <h4  style="margin-top:40px;">Selected Diagnosis Items</h4>
        <div id="selectedItems"
     class="selected-medicine-wrap"
     style="height:500px;border:1px solid #ddd;">
<table class="selected-medicine-table">
<thead>
<tr>
<th style="min-width:160px;">Medicine</th>
<th style="width:70px;">Freq.</th>
<th style="width:100px;">Med.Meal</th>
<th style="width:65px;">Qty</th>
<th style="width:60px;">M</th>
<th style="width:60px;">N</th>
<th style="width:60px;">E</th>
<th style="min-width:80px;">Doctor</th>
<th style="width:35px;">Stop</th>
<th style="width:120px;">Dated</th>
<th style="min-width:150px;">Comments</th>
<th style="width:40px;"></th>
</tr>
</thead>
<tbody id="selectedMedicineBody">
</tbody>
</table>
</div>
    </div>
</div>
</div>
</div>
<?php } ?>
<!-- Service Tab -->
<?php if(!empty($showTabs['services'])){ ?>
<div id="services_tab" class="tab-content">
<div class="card">
<div style="display:flex;gap:20px;">
    <!-- Available Services -->
    <div style="width:50%;">
        <br>
        <div style="
display:flex;
gap:10px;
align-items:center;
">
<input
type="text"
id="serviceSearch"
placeholder="🔍 Search Service"
style="flex:2;">
<select id="categoryFilter" style="flex:1;">
<option value="">Category Type</option>
<?php while($cat=mysqli_fetch_assoc($categoryResult)){ ?>
<option value="<?= htmlspecialchars($cat['category_type']) ?>">
<?= htmlspecialchars($cat['category_type']) ?>
</option>
<?php } ?>
</select>
<select id="mainServiceFilter" style="flex:1;">
<option value="">Main Service</option>
<?php while($ms=mysqli_fetch_assoc($mainServiceResult)){ ?>
<option
value="<?= $ms['id'] ?>">
<?= htmlspecialchars($ms['service_name']) ?>
</option>
<?php } ?>
</select>
<select id="serviceFilter" style="flex:1;">
<option value="">Service</option>
<?php while($srv=mysqli_fetch_assoc($serviceDropdownResult)){ ?>
<option
value="<?= $srv['id'] ?>">
<?= htmlspecialchars($srv['service_name']) ?>
</option>
<?php } ?>
</select>
</div>
        <div id="serviceList"
             style="height:500px;overflow:auto;border:1px solid #ddd;padding:10px;">
<?php while($srv=mysqli_fetch_assoc($serviceResult)){ ?>
<div
class="service-row"
data-category="<?= htmlspecialchars($srv['category_type']) ?>"
data-main="<?= $srv['main_service_id'] ?>"
data-id="<?= $srv['id'] ?>"
data-name="<?= strtolower($srv['service_name']) ?>">
<label style="display:flex;align-items:center;width:100%;cursor:pointer;">
<div style="flex:1;">
<?php
$mode = trim($srv['service_mode']);
?>
<div style="display:flex;align-items:center;width:100%;">
<div style="
flex:1;
font-size:16px;
font-weight:600;
color:#111827;
">
<?= htmlspecialchars($srv['display_name']) ?>
</div>
<div style="
margin-left:auto;
font-size:13px;
white-space:nowrap;
">
<span style="color:<?= ($mode == 'Service In') ? '#16a34a' : '#dc2626'; ?>;font-weight:500;">
<?= htmlspecialchars($mode) ?>
</span>
&nbsp;|&nbsp;
<?= htmlspecialchars($srv['service_code']) ?>
&nbsp;|&nbsp;
₹<?= number_format($srv['amount'],2) ?>
</div>
</div>
</div>
<input type="checkbox"
       class="service-checkbox"
       value="<?= $srv['id'] ?>"
       data-name="<?= htmlspecialchars($srv['display_name']) ?>"
       data-price="<?= $srv['amount'] ?>"
       data-code="<?= htmlspecialchars($srv['service_code']) ?>"
       data-mode="<?= htmlspecialchars($srv['service_mode']) ?>">
</label>
<hr style="margin:0;">
</div>
<?php } ?>
        </div>
    </div>
    <!-- Selected Services -->
    <div style="width:50%;">
        <h4>Selected Services</h4>
        <div id="selectedServices"
             style="height:500px;overflow:auto;border:1px solid #ddd;padding:10px;">
        </div>
    </div>
</div>
</div>
</div>
<?php } ?>
<!-- Diet Tab -->
<?php if(!empty($showTabs['diet_details'])){ ?>
<div id="diet_tab" class="tab-content">
<div class="card">
<h3>Diet Details</h3>
<textarea name="diet_details"><?= htmlspecialchars($prescription['diet_details'] ?? '') ?></textarea>
</div>
</div>
<?php } ?>
<!-- Eye Tab -->
<?php if(!empty($showTabs['eye_details'])){ ?>
<div id="eye_tab" class="tab-content">
<div style="display:flex;gap:20px;flex-wrap:wrap;">
<!-- RIGHT EYE -->
<div class="eye-card">
<h3 style="margin-bottom:15px; font-size:17px;">👁 Right Eye</h3>
<table class="eye-table">
<tr>
<th></th>
<th>SPH.</th>
<th>CYL.</th>
<th>AXIS.</th>
<th>V.A</th>
</tr>
<tr>
<td><strong>Constant/Distance</strong></td>
<td><input type="text" name="re_distance_sph"></td>
<td><input type="text" name="re_distance_cyl"></td>
<td><input type="text" name="re_distance_axis"></td>
<td><input type="text" name="re_distance_va"></td>
</tr>
<tr>
<td><strong>Near</strong></td>
<td><input type="text" name="re_near_sph"></td>
<td><input type="text" name="re_near_cyl"></td>
<td><input type="text" name="re_near_axis"></td>
<td><input type="text" name="re_near_va"></td>
</tr>
</table>
</div>
<!-- LEFT EYE -->
<div class="eye-card">
<h3 style="margin-bottom:15px; font-size:17px;">👁 Left Eye</h3>
<table class="eye-table">
<tr>
<th></th>
<th>SPH.</th>
<th>CYL.</th>
<th>AXIS.</th>
<th>V.A</th>
</tr>
<tr>
<td><strong>Constant/Distance</strong></td>
<td><input type="text" name="le_distance_sph"></td>
<td><input type="text" name="le_distance_cyl"></td>
<td><input type="text" name="le_distance_axis"></td>
<td><input type="text" name="le_distance_va"></td>
</tr>
<tr>
<td><strong>Near</strong></td>
<td><input type="text" name="le_near_sph"></td>
<td><input type="text" name="le_near_cyl"></td>
<td><input type="text" name="le_near_axis"></td>
<td><input type="text" name="le_near_va"></td>
</tr>
</table>
</div>
</div>
</div>
<?php } ?>
<!-- Gynae Tab -->
<?php if(!empty($showTabs['gynae_details'])){ ?>
<div id="gynae_tab" class="tab-content">
<div class="card">
<h3>Gynae Details</h3>
<textarea name="gynae_details"><?= htmlspecialchars($prescription['gynae_details'] ?? '') ?></textarea>
</div>
</div>
<?php } ?>
<!-- Embryology Tab -->
<?php if(!empty($showTabs['embryology'])){ ?>
<div id="embryology_tab" class="tab-content">
<div class="card">
<h3>Embryology</h3>
<textarea name="embryology_details"><?= htmlspecialchars($prescription['embryology_details'] ?? '') ?></textarea>
</div>
</div>
<?php } ?>
<!-- Bariatric Tab -->
<?php if(!empty($showTabs['bariatric'])){ ?>
<div id="bariatric_tab" class="tab-content">
<div class="card">
<h3>Bariatric</h3>
<textarea name="bariatric_details"><?= htmlspecialchars($prescription['bariatric_details'] ?? '') ?></textarea>
</div>
</div>
<?php } ?>

<?php if(!empty($showTabs['nursing_notes'])){ ?>
<div class="tab-content" id="nursing_notes">
    <input type="hidden" name="nursing_notes_json" id="nursing_notes_json" value="">
<div style="overflow-x:auto;">
<table class="nursing-notes-table" id="nursingNotesTable">
<thead>
<tr>
<th style="width:55px;">Sr. No.</th>
<th style="min-width:220px;">Treatment</th>
<th style="width:140px;">Date</th>
<th style="width:120px;">Time</th>
<th style="width:140px;">Stop Date</th>
<th style="width:120px;">Stop Time</th>
<th style="min-width:250px;">Comments</th>
<th style="width:90px;">Action</th>
</tr>
</thead>
<tbody id="nursingNotesBody">
<?php if(!empty($nursingNotes)){ ?>
<?php foreach($nursingNotes as $index=>$note){ ?>
<tr class="nursing-note-row">
<td class="nursing-sr"><?= $index+1 ?></td>
<td>
<input type="text"
class="nursing-treatment"
placeholder="Treatment"
value="<?= htmlspecialchars($note['treatment'] ?? '') ?>">
</td>
<td>
<input type="date"
class="nursing-date"
value="<?= htmlspecialchars($note['date'] ?? '') ?>">
</td>
<td>
<input type="time"
class="nursing-time"
value="<?= htmlspecialchars($note['time'] ?? '') ?>">
</td>
<td>
<input type="date"
class="nursing-stop-date"
value="<?= htmlspecialchars($note['stop_date'] ?? '') ?>">
</td>
<td>
<input type="time"
class="nursing-stop-time"
value="<?= htmlspecialchars($note['stop_time'] ?? '') ?>">
</td>
<td>
<input type="text"
class="nursing-comments"
placeholder="Comments"
value="<?= htmlspecialchars($note['comments'] ?? '') ?>">
</td>
<td>
<?php if($index==0){ ?>
<button type="button"
class="nursing-add-btn"
onclick="addNursingRow()">+</button>
<?php }else{ ?>
<button type="button"
class="nursing-remove-btn"
onclick="removeNursingRow(this)">×</button>
<?php } ?>
</td>
</tr>
<?php } ?>
<?php }else{ ?>
<tr class="nursing-note-row">
<td class="nursing-sr">1</td>
<td> <input type="text" class="nursing-treatment" placeholder="Treatment"> </td>
<td> <input type="date" class="nursing-date" value="<?= date('Y-m-d') ?>"> </td>
<td><input type="time" class="nursing-time" value="<?= date('H:i') ?>"> </td>
<td> <input type="date" class="nursing-stop-date"> </td>
<td> <input type="time" class="nursing-stop-time"></td>
<td> <input type="text" class="nursing-comments" placeholder="Comments"> </td>
<td><button type="button" class="nursing-add-btn" onclick="addNursingRow()">+</button> </td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
<?php } ?>
</form>
</div>
<script>
/* TEMPLATE SEARCH */
<?php foreach($sections as $key=>$title): ?>
setupSearch("<?= $key ?>");
<?php endforeach; ?>
function selectTemplate(section,el){
const text=el.getAttribute("data-text");
const area=document.getElementById(section+"_area");
const search=document.getElementById(section+"_search");
const list=document.getElementById(section+"_list");
if(area){
area.value=text;
}
if(search){
search.value=el.innerText;
}
if(list){
list.style.display="none";
}
}
function setupSearch(section){
const search=document.getElementById(section+"_search");
const list=document.getElementById(section+"_list");
if(!search || !list){
return;
}
search.addEventListener("focus",function(){
list.style.display="block";
});
search.addEventListener("keyup",function(){
const filter=this.value.toLowerCase();
const items=list.querySelectorAll("div");
items.forEach(function(item){
const txt=item.innerText.toLowerCase();
item.style.display=txt.includes(filter) ? "block" : "none";
});
});
document.addEventListener("click",function(e){
if(!search.contains(e.target) && !list.contains(e.target)){
list.style.display="none";
}
});
}
</script>
<script>
function openTab(tabId){
    document.querySelectorAll('.tab-content').forEach(function(tab){
        tab.classList.remove('active');
    });

    document.querySelectorAll('.tab-btn').forEach(function(btn){
        btn.classList.remove('active');
    });

    const target = document.getElementById(tabId);

    if(!target){
        console.error('Tab content not found:', tabId);
        return;
    }

    target.classList.add('active');

    document.querySelectorAll('.tab-btn').forEach(function(btn){
        if(btn.getAttribute('onclick') === "openTab('" + tabId + "')"){
            btn.classList.add('active');
        }
    });

    if(tabId === 'services_tab'){
        setTimeout(function(){
            const search = document.getElementById('serviceSearch');
            if(search){
                search.focus();
            }
        },100);
    }

    if(tabId === 'diagnosis_tab'){
        setTimeout(function(){
            const search = document.getElementById('itemSearch');
            if(search){
                search.focus();
            }
        },100);
    }
}

/* ===============================
   LOAD EYE DETAILS
================================ */
<?php if(!empty($eyeDetails)){ ?>

const eyeDetails = <?= json_encode(
    $eyeDetails,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

document.addEventListener("DOMContentLoaded", function(){
    Object.keys(eyeDetails).forEach(function(key){
        const el = document.querySelector('[name="' + key + '"]');

        if(el){
            el.value = eyeDetails[key];
        }
    });
});

<?php } ?>

/* ===============================
   OPEN FIRST TAB
================================ */
window.addEventListener('load', function(){

    const firstBtn = document.querySelector('.tab-btn');

    if(firstBtn){
        firstBtn.click();
    }

});
</script>
<?php
$selectedServices=[];

if(!empty($prescription['selected_services'])){

    $selectedServices=array_values(
        array_filter(
            array_map(
                'trim',
                explode(',', $prescription['selected_services'])
            )
        )
    );

}
$selectedDiseaseIds=[];

if(!empty($prescription['selected_diseases'])){
    $selectedDiseaseIds=json_decode($prescription['selected_diseases'],true);

    if(!is_array($selectedDiseaseIds)){
        $selectedDiseaseIds=[];
    }
}
?>
<script>
    
window.addEventListener('load',function(){

<?php if(!empty($selectedDiseaseIds)){ ?>

    const diseaseIds = <?= json_encode($selectedDiseaseIds) ?>;

    diseaseIds.forEach(function(id){

        const chk = document.querySelector(
            '.disease-checkbox[value="' + id + '"]'
        );

        if(chk){
            chk.checked = true;

            chk.dispatchEvent(
                new Event('change',{bubbles:true})
            );
        }

    });

    if(typeof updateSelectedDiseases === 'function'){
        updateSelectedDiseases();
    }

<?php } ?>

});
</script>
<script>
function filterServices(){
let search =
document.getElementById('serviceSearch').value.toLowerCase();
let category =
document.getElementById('categoryFilter').value;
let main =
document.getElementById('mainServiceFilter').value;
let service =
document.getElementById('serviceFilter').value;
document.querySelectorAll('.service-row').forEach(function(row){
let show = true;
if(
search &&
!row.dataset.name.includes(search)
){
show = false;
}
if(
category &&
row.dataset.category != category
){
show = false;
}
if(
main &&
row.dataset.main != main
){
show = false;
}
if(
service &&
row.dataset.id != service
){
show = false;
}
row.style.display =
show
?
'block'
:
'none';
});
}
document.getElementById('serviceSearch')
.addEventListener('keyup',filterServices);
document.getElementById('categoryFilter')
.addEventListener('change',filterServices);
document.getElementById('mainServiceFilter')
.addEventListener('change',filterServices);
document.getElementById('serviceFilter')
.addEventListener('change',filterServices);
document.addEventListener('change',function(e){
if(!e.target.classList.contains('service-checkbox')){
return;
}
let box=e.target;
let selected=document.getElementById('selectedServices');
if(box.checked){
let div=document.createElement('div');
div.id='srv_'+box.value;
var modeColor =
box.dataset.mode == 'Service In'
?
'#16a34a'
:
'#dc2626';
div.innerHTML = `
<div style="
display:flex;
justify-content:space-between;
align-items:center;
padding:5px;
border-bottom:1px solid #e5e7eb;
">
<div style="flex:1;">
<div style="display:flex;align-items:center;width:100%;">
<div style="
flex:1;
font-size:14px;
font-weight:600;
color:#111827;
">
${box.dataset.name}
</div>
<div style="
font-size:13px;
white-space:nowrap;
text-align:right;
">
<span style="
color:${modeColor};
font-weight:500;
">
${box.dataset.mode}
</span>
&nbsp;|&nbsp;
${box.dataset.code}
&nbsp;|&nbsp;
₹${parseFloat(box.dataset.price).toLocaleString('en-IN',{
minimumFractionDigits:2,
maximumFractionDigits:2
})}
</div>
</div>
</div>
<button
type="button"
onclick="removeService(${box.value})"
style="
width:20px;
height:20px;
border:none;
border-radius:50%;
background:#ef4444;
color:#fff;
font-size:12px;
font-weight:bold;
cursor:pointer;
display:flex;
align-items:center;
justify-content:center;
margin-left:10px;
">
✕
</button>
</div>
`;
selected.appendChild(div);
}else{
let old=document.getElementById('srv_'+box.value);
if(old){
old.remove();
}
}
updateSelectedServices();
});
function updateSelectedServices(){
let ids=[];
document.querySelectorAll('.service-checkbox:checked')
.forEach(function(box){
ids.push(box.value);
});
document.getElementById('selected_services').value=
ids.join(',');
}


document.addEventListener('change',function(e){
if(!e.target.classList.contains('item-checkbox')){
return;
}
let box=e.target;
let tbody=document.getElementById('selectedMedicineBody');
if(!tbody){
return;
}
if(box.checked){
    if(document.getElementById('item_'+box.value)){
    return;
}
let row=document.createElement('tr');
row.id='item_'+box.value;
row.className='selected-medicine-row';
row.dataset.itemId=box.value;
row.innerHTML=`
<td>
<div class="selected-medicine-name">${box.dataset.name}</div>
</td>
<td>
<select class="medicine-frequency">
<option value="">Select</option>
<option value="OD">OD</option>
<option value="BD">BD</option>
<option value="TDS">TDS</option>
<option value="QID">QID</option>
<option value="HS">HS</option>
<option value="TFS">TFS</option>
<option value="E/S">E/S</option>
<option value="SOS">SOS</option>
</select>
</td>
<td>
<select class="medicine-meal">
<option value="">Select</option>
<option value="After Meal">After Meal</option>
<option value="Before Meal">Before Meal</option>
<option value="After Breakfast">After Breakfast</option>
<option value="Before Breakfast">Before Breakfast</option>
<option value="After Lunch">After Lunch</option>
<option value="Before Lunch">Before Lunch</option>
<option value="After Dinner">After Dinner</option>
<option value="Before Dinner">Before Dinner</option>
</select>
</td>
<td>
<input type="number"
class="medicine-qty"
min="0"
step="1"
value="1">
</td>
<td>
<input type="number"
class="medicine-m"
min="0"
step="0.01"
value="0">
</td>
<td>
<input type="number"
class="medicine-n"
min="0"
step="0.01"
value="0">
</td>
<td>
<input type="number"
class="medicine-e"
min="0"
step="0.01"
value="0">
</td>
<td>
<select class="medicine-doctor">
<option value="">Select Doctor</option>
<?php
$doctorResult=mysqli_query($conn,"
SELECT DISTINCT
dm.id,
u.full_name
FROM doctor_master dm
INNER JOIN users u ON u.id=dm.user_id
WHERE u.status='Active'
ORDER BY u.full_name
");
while($doctor=mysqli_fetch_assoc($doctorResult)){
?>
<option value="<?= (int)$doctor['id'] ?>">
<?= htmlspecialchars($doctor['full_name']) ?>
</option>
<?php } ?>
</select> </td>
<td style="text-align:center;"> <input type="checkbox" class="medicine-stop selected-medicine-stop"></td>
<td><input type="date" class="medicine-date"></td>
<td><input type="text" class="medicine-comments" placeholder="Comments"> </td>
<td style="text-align:center;"> <button type="button" class="selected-medicine-delete" onclick="removeItem(${box.value})">×</button> </td>
`;
tbody.appendChild(row);
}else{
let old=document.getElementById('item_'+box.value);
if(old){
old.remove();
}
}
updateSelectedItems();
});
function updateSelectedItems(){
let items=[];
document.querySelectorAll('#selectedMedicineBody .selected-medicine-row')
.forEach(function(row){
let itemId=row.dataset.itemId || '';
let medicine=row.querySelector('.selected-medicine-name')?.textContent.trim() || '';
let frequency=row.querySelector('.medicine-frequency')?.value || '';
let meal=row.querySelector('.medicine-meal')?.value || '';
let qty=row.querySelector('.medicine-qty')?.value || '';
let morning=row.querySelector('.medicine-m')?.value || '';
let noon=row.querySelector('.medicine-n')?.value || '';
let evening=row.querySelector('.medicine-e')?.value || '';
let doctor=row.querySelector('.medicine-doctor')?.value.trim() || '';
let stop=row.querySelector('.medicine-stop')?.checked ? 1 : 0;
let dated=row.querySelector('.medicine-date')?.value || '';
let comments=row.querySelector('.medicine-comments')?.value.trim() || '';
items.push({
item_id:itemId,
medicine:medicine,
frequency:frequency,
meal:meal,
qty:qty,
m:morning,
n:noon,
e:evening,
doctor:doctor,
stop:stop,
dated:dated,
comments:comments
});
});
document.getElementById('selected_items').value=JSON.stringify(items);
}
document.addEventListener('input',function(e){
if(e.target.closest('.selected-medicine-row')){
updateSelectedItems();
}
});
document.addEventListener('change',function(e){
if(e.target.closest('.selected-medicine-row')){
updateSelectedItems();
}
});
document.getElementById('serviceSearch').addEventListener('keydown',function(e){
    if(e.key !== 'Enter'){
        return;
    }
    e.preventDefault();
    let firstVisible = document.querySelector(
        '.service-row:not([style*="display: none"]) .service-checkbox'
    );
    if(firstVisible && !firstVisible.checked){
        firstVisible.checked = true;
        firstVisible.dispatchEvent(
            new Event('change',{bubbles:true})
        );
    }
    this.value='';
    filterServices();
    this.focus();
});
<?php
$selectedMedicines=[];

if(!empty($prescription['selected_items'])){

    $selectedMedicines=json_decode($prescription['selected_items'],true);

    if(!is_array($selectedMedicines)){
        $selectedMedicines=[];
    }

}
?>
document.addEventListener('DOMContentLoaded',function(){
<?php if(!empty($selectedMedicines)){ ?>
let medicines=<?= json_encode($selectedMedicines) ?>;
medicines.forEach(function(m){
let chk=document.querySelector('.item-checkbox[value="'+m.item_id+'"]');
if(chk){
chk.checked=true;
chk.dispatchEvent(new Event('change',{bubbles:true}));
}
});
<?php } ?>
});
window.addEventListener('load',function(){
    const searchBox = document.getElementById('serviceSearch');
    if(searchBox){
        searchBox.focus();
    }
});
function setMedicineFrequency(row,frequency){
let m=row.querySelector('.medicine-m');
let n=row.querySelector('.medicine-n');
let e=row.querySelector('.medicine-e');
if(!m || !n || !e){
return;
}
let values={
'OD':[1,0,0],
'BD':[1,0,1],
'TDS':[1,1,1],
'QID':[1,1,1],
'HS':[0,0,1],
'TFS':[0,0,1],
'E/S':[0,0,1],
'SOS':[0,0,1]
};
if(values[frequency]){
m.value=values[frequency][0];
n.value=values[frequency][1];
e.value=values[frequency][2];
}else{
m.value=0;
n.value=0;
e.value=0;
}
}
document.addEventListener('change',function(e){
if(!e.target.classList.contains('medicine-frequency')){
return;
}
let row=e.target.closest('.selected-medicine-row');
if(!row){
return;
}
setMedicineFrequency(row,e.target.value);
updateSelectedItems();
});
function removeService(id){
let checkbox=document.querySelector(
'.service-checkbox[value="'+id+'"]'
);
if(checkbox){
checkbox.checked=false;
checkbox.dispatchEvent(
new Event('change',{bubbles:true})
);
}
}
function removeItem(id){
let checkbox=document.querySelector(
'.item-checkbox[value="'+id+'"]'
);
if(checkbox){
checkbox.checked=false;
checkbox.dispatchEvent(
new Event('change',{bubbles:true})
);
}
}
document.getElementById('itemSearch').addEventListener('keydown',function(e){
    if(e.key !== 'Enter'){
        return;
    }
    e.preventDefault();
    let firstVisible = document.querySelector(
        '.item-row:not([style*="display: none"]) .item-checkbox'
    );
    if(firstVisible && !firstVisible.checked){
        firstVisible.checked = true;
        firstVisible.dispatchEvent(
            new Event('change',{bubbles:true})
        );
    }
    this.value='';
filterItems();
this.focus();
});
function filterItems(){
let search =
document.getElementById('itemSearch').value.toLowerCase();
let category =
document.getElementById('categoryItemFilter').value;
let subcategory =
document.getElementById('subCategoryItemFilter').value;
let manufacturer =
document.getElementById('manufacturerFilter').value;
let store =
document.getElementById('storeFilter').value;
document.querySelectorAll('.item-row').forEach(function(row){
let show=true;
if(search && !row.dataset.name.includes(search)){
show=false;
}
if(category && row.dataset.category!=category){
show=false;
}
if(subcategory && row.dataset.subcategory!=subcategory){
show=false;
}
if(manufacturer && row.dataset.manufacturer!=manufacturer){
show=false;
}
if(store){
let stores=row.dataset.store.split(',');
if(!stores.includes(store)){
show=false;
}
}
row.style.display=show?'block':'none';
});
}
document.getElementById('itemSearch')
.addEventListener('keyup',filterItems);
document.getElementById('categoryItemFilter')
.addEventListener('change',filterItems);
document.getElementById('subCategoryItemFilter')
.addEventListener('change',filterItems);
document.getElementById('storeFilter')
.addEventListener('change',filterItems);
document.getElementById('manufacturerFilter')
.addEventListener('change',filterItems);
document.getElementById('categoryItemFilter')
.addEventListener('change',function(){
    let cat=this.value;
    document.querySelectorAll(
        '#subCategoryItemFilter option[data-parent]'
    ).forEach(function(opt){
        opt.style.display=
            (!cat || opt.dataset.parent==cat)
            ? 'block'
            : 'none';
    });
    document.getElementById('subCategoryItemFilter').value='';
    filterItems();
});
function calculateBMI(){

    const weightInput=document.getElementById('weight');
    const heightInput=document.getElementById('height');
    const bmiInput=document.getElementById('bmi');

    if(!weightInput || !heightInput || !bmiInput){
        return;
    }

    let weight=parseFloat(weightInput.value)||0;
    let height=parseFloat(heightInput.value)||0;

    if(weight>0 && height>0){

        let bmi=weight/Math.pow(height/100,2);

        bmiInput.value=bmi.toFixed(2);

    }else{

        bmiInput.value='';

    }

}
const weightInput=document.getElementById('weight');
const heightInput=document.getElementById('height');

if(weightInput){
    weightInput.addEventListener('input',calculateBMI);
}

if(heightInput){
    heightInput.addEventListener('input',calculateBMI);
}
/*=========================================
NURSING NOTES - ADD / REMOVE ROW
=========================================*/
function addNursingRow(){
const tbody=document.getElementById('nursingNotesBody');
if(!tbody){
return;
}
const row=document.createElement('tr');
row.className='nursing-note-row';
const now=new Date();
const year=now.getFullYear();
const month=String(now.getMonth()+1).padStart(2,'0');
const day=String(now.getDate()).padStart(2,'0');
const hours=String(now.getHours()).padStart(2,'0');
const minutes=String(now.getMinutes()).padStart(2,'0');
const currentDate=year+'-'+month+'-'+day;
const currentTime=hours+':'+minutes;
row.innerHTML=`
<td class="nursing-sr"></td>
<td><input type="text" class="nursing-treatment" placeholder="Treatment"></td>
<td><input type="date" class="nursing-date" value="${currentDate}"></td>
<td><input type="time" class="nursing-time" value="${currentTime}"></td>
<td><input type="date" class="nursing-stop-date"></td>
<td><input type="time" class="nursing-stop-time"></td>
<td><input type="text" class="nursing-comments" placeholder="Comments"></td>
<td><button type="button" class="nursing-remove-btn" onclick="removeNursingRow(this)">×</button></td>
`;
tbody.appendChild(row);
updateNursingSerialNumbers();
}
function removeNursingRow(button){
const row=button.closest('.nursing-note-row');
if(row){
row.remove();
}
updateNursingSerialNumbers();
}
function updateNursingSerialNumbers(){
document.querySelectorAll('#nursingNotesBody .nursing-note-row')
.forEach(function(row,index){
const sr=row.querySelector('.nursing-sr');
if(sr){
sr.textContent=index+1;
}
});
}
/*=========================================
NURSING NOTES - CREATE JSON
=========================================*/
function prepareNursingNotes(){

    const rows=[];

    document.querySelectorAll('#nursingNotesBody .nursing-note-row').forEach(function(row){

        const treatment =
            row.querySelector('.nursing-treatment')?.value.trim() || '';

        const date =
            row.querySelector('.nursing-date')?.value || '';

        const time =
            row.querySelector('.nursing-time')?.value || '';

        const stopDate =
            row.querySelector('.nursing-stop-date')?.value || '';

        const stopTime =
            row.querySelector('.nursing-stop-time')?.value || '';

        const comments =
            row.querySelector('.nursing-comments')?.value.trim() || '';

        /*
         * Date and Time are auto-filled.
         * They must NOT make an empty row a nursing note.
         *
         * Only Treatment or Comments creates a note.
         */
        if(treatment !== '' || comments !== ''){

            rows.push({
                treatment: treatment,
                date: date,
                time: time,
                stop_date: stopDate,
                stop_time: stopTime,
                comments: comments
            });

        }

    });

    const hidden =
        document.getElementById('nursing_notes_json');

    if(hidden){
        hidden.value = JSON.stringify(rows);
    }
}
/* =========================================
DISEASE SEARCH
========================================= */
const diseaseSearch=document.getElementById('diseaseSearch');
if(diseaseSearch){
diseaseSearch.addEventListener('input',function(){
let search=this.value.toLowerCase().trim();
document.querySelectorAll('.disease-row').forEach(function(row){
let text=row.dataset.search || '';
row.style.display=(!search || text.includes(search))?'block':'none';
});
});
}
/* =========================================
DISEASE SELECT / UNSELECT
========================================= */
document.addEventListener('change',function(e){
if(!e.target.classList.contains('disease-checkbox')){
return;
}
updateSelectedDiseases();
});
/* =========================================
UPDATE SELECTED DISEASES
========================================= */
function updateSelectedDiseases(){
    
let selectedBox=document.getElementById('selectedDiseases');
let hidden=document.getElementById('selected_diseases');
if(!selectedBox || !hidden){
return;
}
selectedBox.innerHTML='';
let diseases=[];
document.querySelectorAll('.disease-checkbox:checked').forEach(function(box){
let id=box.value;
let name=box.dataset.name || '';
let icd=box.dataset.icd || '';
diseases.push(id);
let div=document.createElement('div');
div.id='selected_disease_'+id;
div.style.cssText='display:flex;align-items:center;justify-content:space-between;gap:6px;padding:4px 6px;margin-bottom:3px;border:1px solid #e1e5e9;border-radius:4px;background:#fff;font-size:12px;';
let displayName=name;
if(icd){
displayName+=' ('+icd+')';
}
div.innerHTML=`
<span>${displayName}</span>
<button type="button"
onclick="removeDisease('${id}')"
style="border:0;background:#dc3545;color:#fff;width:22px;height:22px;padding:0;border-radius:4px;cursor:pointer;">×</button>
`;
selectedBox.appendChild(div);
});
hidden.value=JSON.stringify(diseases);
let count=document.getElementById('selectedDiseaseCount');
if(count){
count.textContent=diseases.length;
}
if(diseases.length===0){
selectedBox.innerHTML='<div class="disease-empty">No disease selected</div>';
}
}
/* =========================================
REMOVE SELECTED DISEASE
========================================= */
function removeDisease(id){
let checkbox=document.querySelector('.disease-checkbox[value="'+id+'"]');
if(checkbox){
checkbox.checked=false;
}
updateSelectedDiseases();
}
let parameterChart=null;
document.addEventListener("click",function(e){
const btn=e.target.closest(".parameter-chart-btn");
if(!btn){
return;
}
const parameterId=btn.dataset.parameterId;
const parameterName=btn.dataset.parameterName;
document.querySelector("#parameterHistoryModal .modal-title").innerHTML=
"📈 "+parameterName+" Trend";
document.getElementById("latestReading").innerHTML="--";
document.getElementById("highestReading").innerHTML="--";
document.getElementById("lowestReading").innerHTML="--";
document.getElementById("averageReading").innerHTML="--";
document.getElementById("parameterHistoryContent").innerHTML=`
<table class="table table-bordered table-striped">
<thead class="table-primary">
<tr>
<th>Reading</th>
<th>Date</th>
<th>Time</th>
<th>By</th>
</tr>
</thead>
<tbody>
<tr>
<td colspan="4" class="text-center">Loading...</td>
</tr>
</tbody>
</table>
`;
if(parameterChart){
parameterChart.destroy();
}
const ctx=document.getElementById("parameterChart");
parameterChart=new Chart(ctx,{
type:"line",
data:{
labels:[],
datasets:[{
label:parameterName,
data:[],
borderColor:"#2563eb",
backgroundColor:"rgba(37,99,235,.15)",
pointRadius:4,
pointHoverRadius:6,
borderWidth:3,
fill:true,
tension:.35
}]
},
options:{
responsive:true,
plugins:{
legend:{
display:false
}
},
scales:{
y:{
beginAtZero:false
}
}
}
});
new bootstrap.Modal(
document.getElementById("parameterHistoryModal")
).show();
fetch(
"/modules/opd/ajax/get_parameter_chart.php?appointment_id=<?=$appointment_id?>&parameter_id="+parameterId
)
.then(response=>response.json())
.then(data=>{
document.getElementById("latestReading").innerHTML=data.latest;
document.getElementById("highestReading").innerHTML=data.highest;
document.getElementById("lowestReading").innerHTML=data.lowest;
document.getElementById("averageReading").innerHTML=data.average;
/* Chart */
parameterChart.data.labels=[];
parameterChart.data.datasets[0].data=[];
data.chart.forEach(function(item){
parameterChart.data.labels.push(item.label);
parameterChart.data.datasets[0].data.push(item.value);
});
parameterChart.update();
/* History */
let html=`
<table class="table table-bordered table-hover">
<thead class="table-primary">
<tr>
<th width="120">Reading</th>
<th width="120">Date</th>
<th width="120">Time</th>
<th>By</th>
</tr>
</thead>
<tbody>
`;
if(data.history.length==0){
html+=`
<tr>
<td colspan="4" class="text-center"> No Reading Available </td>
</tr>
`;
}else{
data.history.forEach(function(row){
html+=`
<tr>
<td>${row.reading}</td>
<td>${row.date}</td>
<td>${row.time}</td>
<td>${row.by}</td>
</tr>
`;
});
}
html+=`
</tbody>
</table>
`;
document.getElementById("parameterHistoryContent").innerHTML=html;
});
});
function prepareParameterReadings(){
let readings=[];
document.querySelectorAll("#parameterBody tr").forEach(function(row){
let reading=row.querySelector(".parameter-reading")?.value.trim();
if(reading===""){
return;
}
readings.push({
parameter_id:parseInt(
row.querySelector(".parameter-reading").dataset.parameterId
),
reading:reading,
date:
row.querySelector(".parameter-date").value,
time:
row.querySelector(".parameter-time").value + ":00",
taken_by:parseInt(
row.querySelector(".parameter-user").value
)
});
});
document.getElementById("parameter_readings").value=
JSON.stringify(readings);
}
function prepareEyeDetails(){
const fields=[
're_distance_sph',
're_distance_cyl',
're_distance_axis',
're_distance_va',
're_near_sph',
're_near_cyl',
're_near_axis',
're_near_va',
'le_distance_sph',
'le_distance_cyl',
'le_distance_axis',
'le_distance_va',
'le_near_sph',
'le_near_cyl',
'le_near_axis',
'le_near_va'
];
let data={};
fields.forEach(function(name){
const input=document.querySelector('[name="'+name+'"]');
data[name]=input ? input.value.trim() : '';
});
const hidden=document.getElementById('eye_details_json');
if(hidden){
hidden.value=JSON.stringify(data);
}
}
function preparePrescriptionData(){
prepareParameterReadings();
prepareNursingNotes();
prepareEyeDetails();

const selectedDiseases=[];

document.querySelectorAll('.disease-checkbox:checked').forEach(function(box){
    selectedDiseases.push(box.value);
});

const diseaseInput=document.getElementById('selected_diseases');

if(diseaseInput){
    diseaseInput.value=JSON.stringify(selectedDiseases);
}

return true;
}
<?php if(!empty($selectedMedicines)){ ?>

document.addEventListener('DOMContentLoaded',function(){

setTimeout(function(){

let medicines=<?= json_encode($selectedMedicines) ?>;

medicines.forEach(function(m){

let chk=document.querySelector('.item-checkbox[value="'+m.item_id+'"]');

if(!chk){
return;
}

chk.checked=true;

chk.dispatchEvent(new Event('change',{bubbles:true}));

let row=document.getElementById('item_'+m.item_id);

if(!row){
return;
}

row.querySelector('.medicine-frequency').value=m.frequency||'';
row.querySelector('.medicine-meal').value=m.meal||'';
row.querySelector('.medicine-qty').value=m.qty||'';
row.querySelector('.medicine-m').value=m.m||'';
row.querySelector('.medicine-n').value=m.n||'';
row.querySelector('.medicine-e').value=m.e||'';
row.querySelector('.medicine-doctor').value=m.doctor||'';
row.querySelector('.medicine-stop').checked=(m.stop==1);
row.querySelector('.medicine-date').value=m.dated||'';
row.querySelector('.medicine-comments').value=m.comments||'';

});

updateSelectedItems();

},300);

});

<?php } ?>

/* =========================================
   RESTORE SELECTED SERVICES IN EDIT MODE
========================================= */

window.addEventListener('load', function(){

    const selectedServicesRaw =
        <?= json_encode(
            $prescription['selected_services'] ?? '',
            JSON_UNESCAPED_UNICODE
        ) ?>;

    console.log(
        'DB SELECTED SERVICES:',
        selectedServicesRaw
    );

    if(!selectedServicesRaw){
        return;
    }

    const selectedServices=String(selectedServicesRaw)
        .split(',')
        .map(function(id){
            return id.trim();
        })
        .filter(function(id){
            return id!=='';
        });

    console.log(
        'EDIT SERVICE IDS:',
        selectedServices
    );

    selectedServices.forEach(function(serviceId){

        const checkbox=document.querySelector(
            '.service-checkbox[value="'+serviceId+'"]'
        );

        if(!checkbox){

            console.log(
                'SERVICE NOT FOUND:',
                serviceId
            );

            return;
        }

        checkbox.checked=true;

        checkbox.dispatchEvent(
            new Event('change',{
                bubbles:true
            })
        );

    });

    updateSelectedServices();

});
const existingDoctorVisits =
<?= json_encode($doctorVisits ?? []) ?>;
function addDoctorVisitRow(data={}) {

    let tbody=document.getElementById("doctorVisitBody");

    let row=document.createElement("tr");

    row.innerHTML=`

    <td>
        <select class="doctor-select" name="doctor_visits[doctor_id][]">
            <option value="">Select Doctor</option>
        </select>
    </td>

    <td>
        <input type="date" 
        name="doctor_visits[date][]"
        value="<?= date('Y-m-d') ?>">
    </td>

    <td>
        <input type="time"
        name="doctor_visits[time][]"
        value="<?= date('H:i') ?>">
    </td>

    <td>
        <select class="charge-type"
        name="doctor_visits[charge_type][]">
            <option value="">Select Type</option>
        </select>
    </td>

    <td>
        <input type="number"
        class="doctor-charge"
        name="doctor_visits[charges][]"
        value="0">
    </td>

    <td>
<input type="text"
name="doctor_visits[comment][]"
placeholder="Comment"
value="${data.comment || ''}">
</td>

    <td>
        <button type="button"
        onclick="this.closest('tr').remove();">
        ❌
        </button>
    </td>
    `;
   tbody.appendChild(row);
setTimeout(function(){
    loadDoctors(row);
    if(data.doctor_id){
        setTimeout(function(){
            row.querySelector('.doctor-select').value=data.doctor_id;
            loadDoctorCharges(
                data.doctor_id,
                row
            );
            setTimeout(function(){
                row.querySelector('.charge-type').value=data.charge_type;
                row.querySelector('.doctor-charge').value=data.charges;
            },500);
        },500);
    }
},100);
}
function loadDoctors(row,savedData={}){
    let doctorSelect=row.querySelector('.doctor-select');
    if(!doctorSelect){
        console.log("Doctor dropdown not found");
        return;
    }
    fetch(
        '/modules/opd/ajax/get_ipd_doctors.php?department_id=<?= $department_id ?>'
    )
    .then(response=>response.json())
    .then(doctors=>{
        doctorSelect.innerHTML=
            '<option value="">Select Doctor</option>';
        doctors.forEach(function(doc){
            let option=document.createElement('option');
            option.value=doc.id;
            option.textContent=doc.name;
            doctorSelect.appendChild(option);
        });
        if(savedData.doctor_id){
            doctorSelect.value=String(savedData.doctor_id);
            loadDoctorCharges(
                savedData.doctor_id,
                row,
                savedData
            );
        }
        doctorSelect.addEventListener('change',function(){
            loadDoctorCharges(
                this.value,
                row
            );
        });
    })
    .catch(error=>{
        console.log("Doctor AJAX Error",error);
    });
}
function loadDoctorCharges(doctor_id,row){
    if(!doctor_id){
        return;
    }
    let chargeType=row.querySelector('.charge-type');
    let chargeInput=row.querySelector('.doctor-charge');
    fetch(
        '/modules/opd/ajax/get_ipd_doctor_visit_charges.php?doctor_id='+doctor_id
    )
    .then(response=>response.json())
    .then(data=>{
        console.log("CHARGE DATA:",data);
        chargeType.innerHTML=
        '<option value="">Select Type</option>';
        data.forEach(function(item){
            let option=document.createElement("option");
            option.value=item.type;
            option.textContent=item.type;
            option.dataset.amount=item.amount;
            chargeType.appendChild(option);
        });
        chargeType.addEventListener('change',function(){
            let amount=
            this.options[this.selectedIndex].dataset.amount || 0;
            chargeInput.value=amount;
        });
    })
    .catch(error=>{
        console.log("Charge AJAX Error",error);
    });
}
window.addEventListener('load',function(){
    if(existingDoctorVisits.length){
        existingDoctorVisits.forEach(function(row){
            addDoctorVisitRow(row);
        });
    }
});
</script>
<!-- ===============================
     Parameter Reading History
================================ -->
<div class="modal fade" id="parameterHistoryModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title">📈 Parameter Trend</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="row mb-3">
<div class="col-md-3">
<div class="card border-primary">
<div class="card-body text-center">
<div style="font-size:12px;">Latest</div>
<h4 id="latestReading">--</h4>
</div>
</div>
</div>
<div class="col-md-3">
<div class="card border-success">
<div class="card-body text-center">
<div style="font-size:12px;">Highest</div>
<h4 id="highestReading">--</h4>
</div>
</div>
</div>
<div class="col-md-3">
<div class="card border-danger">
<div class="card-body text-center">
<div style="font-size:12px;">Lowest</div>
<h4 id="lowestReading">--</h4>
</div>
</div>
</div>
<div class="col-md-3">
<div class="card border-info">
<div class="card-body text-center">
<div style="font-size:12px;">Average</div>
<h4 id="averageReading">--</h4>
</div>
</div>
</div>
</div>
<div class="card mb-3">
<div class="card-body">
<canvas id="parameterChart" height="120"> </canvas>
</div>
</div>
<div id="parameterHistoryContent">
<table class="table table-bordered table-striped">
<thead class="table-primary">
<tr>
<th width="120">Reading</th>
<th width="120">Date</th>
<th width="120">Time</th>
<th>By</th>
</tr>
</thead>
<tbody>
<tr>
<td colspan="4" class="text-center"> No Reading Available </td>
</tr>
</tbody>
</table>
</div>
</div>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>