<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    echo '<div style="padding:30px;color:#dc2626;">Session expired. Please login again.</div>';
    exit;
}
$appointment_id=(int)($_GET['appointment_id'] ?? 0);
if($appointment_id<=0){
    echo '<div style="padding:30px;color:#dc2626;">Invalid appointment.</div>';
    exit;
}
function e($value){
    return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}
/* =========================================
GET IPD / PATIENT DETAILS
========================================= */
$stmt=$conn->prepare("
SELECT
    ia.id AS ipd_admission_id,
    ia.appointment_id,
    ia.patient_id,
    ia.ipd_no,
    ia.file_no,
    ia.hospital_id,
    ia.branch_id,
    ia.doctor_id,
    ia.department_id,
    ia.admission_date,
    ia.admission_time,
    ia.admission_type,
    ia.status,
    ia.payment_type,
    ia.is_emergency,
    ia.is_vip,
    ia.is_mlc,
    op.weight,
    op.height,
    op.bmi,
    op.pain_scale,
    p.uhid,
    p.title,
    p.first_name,
    p.last_name,
    p.sex,
    p.age_y,
    p.age_m,
    p.age_d,
    p.mobile,
    p.dob,
    p.address,
    u.full_name AS doctor_name,
d.department_name,
em.empanelment_name,
dm.disease_name,
bl.building_name,
fm.floor_name,
rm.room_number,
rm.room_name,
bd.bed_number,
ira.allocated_date AS bed_allocated_date
FROM ipd_admissions ia
LEFT JOIN appointments a
    ON a.id=ia.appointment_id
LEFT JOIN patient_master p
    ON p.id=ia.patient_id
LEFT JOIN users u
    ON u.id=ia.doctor_id
LEFT JOIN department_master d
    ON d.id=ia.department_id
LEFT JOIN empanelment_master em
    ON em.id=a.empanelment_id
    LEFT JOIN opd_prescriptions op
    ON op.id=(
        SELECT op2.id
        FROM opd_prescriptions op2
        WHERE op2.appointment_id=ia.appointment_id
        AND op2.patient_type='ipd'
        ORDER BY op2.id DESC
        LIMIT 1
    )
LEFT JOIN disease_master dm
    ON dm.id=CAST(
        JSON_UNQUOTE(
            JSON_EXTRACT(op.selected_diseases,'$[0]')
        ) AS UNSIGNED
    )

LEFT JOIN ipd_room_allocation ira
    ON ira.admission_id=ia.id
    AND ira.status='Occupied'

LEFT JOIN building_master bl
    ON bl.id=ira.building_id

LEFT JOIN floor_master fm
    ON fm.id=ira.floor_id

LEFT JOIN room_master rm
    ON rm.id=ira.room_id

LEFT JOIN bed_master bd
    ON bd.id=ira.bed_id

WHERE ia.appointment_id=?

ORDER BY ira.id DESC

LIMIT 1
");



if(!$stmt){
    echo '<div style="padding:30px;color:#dc2626;">Unable to load IPD details.</div>';
    exit;
}

$stmt->bind_param(
    "i",
    $appointment_id
);

$stmt->execute();

$result=$stmt->get_result();

$ipd=$result->fetch_assoc();

$stmt->close();

if(!$ipd){
    echo '<div style="padding:30px;color:#dc2626;">IPD admission not found.</div>';
    exit;
}

$patientName=trim(
    ($ipd['title'] ?? '').' '.
    ($ipd['first_name'] ?? '').' '.
    ($ipd['last_name'] ?? '')
);

$patientName=$patientName!=='' ? $patientName : '-';

$ageParts=[];

if((int)$ipd['age_y']>0){
    $ageParts[]=(int)$ipd['age_y'].' Y';
}

if((int)$ipd['age_m']>0){
    $ageParts[]=(int)$ipd['age_m'].' M';
}

if((int)$ipd['age_d']>0){
    $ageParts[]=(int)$ipd['age_d'].' D';
}

$ageText=count($ageParts) ? implode(' ',$ageParts) : '-';

$admitDateTime='';

if(!empty($ipd['admission_date'])){

    $admitDateTime=$ipd['admission_date'];

    if(!empty($ipd['admission_time'])){
        $admitDateTime.=' '.$ipd['admission_time'];
    }

    $timestamp=strtotime($admitDateTime);

    if($timestamp){
        $admitDateTime=date('d/m/Y H:i',$timestamp);
    }
}

$admitDateTime=$admitDateTime!=='' ? $admitDateTime : '-';
$lengthOfStay=0;

if(!empty($ipd['admission_date'])){

    $admissionDate=new DateTime($ipd['admission_date']);

    $dischargeDate=new DateTime(
        !empty($_POST['discharge_date'])
        ? $_POST['discharge_date']
        : date('Y-m-d')
    );

    $lengthOfStay=$admissionDate->diff($dischargeDate)->days;

    if($lengthOfStay<1){
        $lengthOfStay=1;
    }
}

$sex=$ipd['sex'] ?? '-';

$doctorName=$ipd['doctor_name'] ?? '-';

$departmentName=$ipd['department_name'] ?? '-';
$weight=$ipd['weight'] ?? '';
$height=$ipd['height'] ?? '';
$bmi=$ipd['bmi'] ?? '';
$pain_scale=$ipd['pain_scale'] ?? '';
/* =========================================
   DOCTOR MASTER LIST
   ========================================= */
$doctorMasterList = [];
$doctorRes = mysqli_query(
    $conn,
    "
    SELECT
        dm.id AS doctor_master_id,
        dm.user_id,
        dm.license_no,
        dm.is_surgeon,
        u.full_name AS doctor_name,
        d.department_name
    FROM doctor_master dm
    INNER JOIN users u
        ON u.id = dm.user_id
    LEFT JOIN user_departments ud
        ON ud.user_id = dm.user_id
    LEFT JOIN department_master d
        ON d.id = ud.department_id
    ORDER BY u.full_name ASC
    "
);
if ($doctorRes) {
    while ($doctorRow = mysqli_fetch_assoc($doctorRes)) {
        $doctorMasterList[] = $doctorRow;
    }
}
/* =========================================
   DISEASE MASTER LIST
   ========================================= */
$diseaseMasterList = [];
$diseaseRes = mysqli_query(
    $conn,
    "
    SELECT
        id,
        disease_name,
        icd_code
    FROM disease_master
    WHERE status=1
    ORDER BY disease_name ASC
    "
);
if ($diseaseRes) {
    while ($diseaseRow = mysqli_fetch_assoc($diseaseRes)) {
        $diseaseMasterList[] = $diseaseRow;
    }
}
/* =========================================
   MEDICINE MASTER LIST
   ========================================= */
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

$medicineDoctorResult = mysqli_query($conn,"
SELECT DISTINCT
    dm.id,
    u.full_name
FROM doctor_master dm
INNER JOIN users u ON u.id=dm.user_id
WHERE u.status='Active'
ORDER BY u.full_name
");

/* =========================================
LOAD PATIENT PARAMETERS
========================================= */

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
?>
<style>
/* =========================================
   DOCTORS LIST - AVAILABLE / SELECTED
   ========================================= */

.doctors-selection-wrap {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    width: 100%;
}

.doctors-selection-panel {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
    overflow: hidden;
    min-width: 0;
}

.doctors-selection-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
}

.doctors-selection-header span {
    font-size: 11px;
    font-weight: 500;
    color: #64748b;
}

.doctors-selection-body {
    padding: 10px;
    max-height: 360px;
    overflow-y: auto;
}

.doctor-selection-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 3px 5px;
    margin-bottom: 7px;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    background: #ffffff;
    transition: all 0.15s ease;
}

.doctor-selection-item:last-child {
    margin-bottom: 0;
}

.doctor-selection-item:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}

.doctor-selection-info {
    min-width: 0;
    flex: 1;
}
.doctor-selection-name{
    font-size:13px;
    font-weight:600;
    color:#334155;
    line-height:1.4;
    white-space:nowrap;
}

.doctor-selection-meta {
    margin-top: 3px;
    font-size: 11px;
    color: #64748b;
}

.doctor-add-btn,
.doctor-remove-btn {
    width: 30px;
    height: 30px;
    flex: 0 0 30px;
    border: 0;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
    font-weight: 600;
}

.doctor-add-btn {
    background: #e6f7f8;
    color: #099aa7;
}

.doctor-add-btn:hover {
    background: #099aa7;
    color: #ffffff;
}

.doctor-remove-btn {
    background: #fee2e2;
    color: #dc2626;
}

.doctor-remove-btn:hover {
    background: #dc2626;
    color: #ffffff;
}

.doctor-empty-state {
    padding: 35px 15px;
    text-align: center;
    color: #94a3b8;
    font-size: 12px;
}

@media (max-width: 700px) {
    .doctors-selection-wrap {
        grid-template-columns: 1fr;
    }

    .doctors-selection-body {
        max-height: 280px;
    }
}
.disease-selection-wrap{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    width:100%;
    margin-top:14px;
}
.disease-selection-panel{
    border:1px solid #e2e8f0;
    border-radius:8px;
    background:#fff;
    overflow:hidden;
    min-width:0;
}
.disease-selection-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:11px 14px;
    background:#f8fafc;
    border-bottom:1px solid #e2e8f0;
    font-size:13px;
    font-weight:600;
    color:#334155;
}
.disease-selection-header span{
    font-size:11px;
    font-weight:500;
    color:#64748b;
}
.disease-selection-body{
    padding:10px;
    max-height:360px;
    overflow-y:auto;
}
.disease-selection-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:4px 5px;
    margin-bottom:7px;
    border:1px solid #e2e8f0;
    border-radius:7px;
    background:#fff;
    transition:all .15s ease;
}
.disease-selection-item:last-child{
    margin-bottom:0;
}
.disease-selection-item:hover{
    border-color:#cbd5e1;
    background:#f8fafc;
}
.disease-selection-info{
    min-width:0;
    flex:1;
}
.disease-selection-name{
    font-size:13px;
    font-weight:600;
    color:#334155;
    line-height:1.4;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.disease-selection-meta{
    margin-top:3px;
    font-size:11px;
    color:#64748b;
}
.disease-add-btn,
.disease-remove-btn{
    width:30px;
    height:30px;
    flex:0 0 30px;
    border:0;
    border-radius:6px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:18px;
    line-height:1;
    font-weight:600;
}
.disease-add-btn{
    background:#e6f7f8;
    color:#099aa7;
}
.disease-add-btn:hover{
    background:#099aa7;
    color:#fff;
}
.disease-remove-btn{
    background:#fee2e2;
    color:#dc2626;
}
.disease-remove-btn:hover{
    background:#dc2626;
    color:#fff;
}
.disease-empty-state{
    padding:35px 15px;
    text-align:center;
    color:#94a3b8;
    font-size:12px;
}
@media(max-width:700px){
    .disease-selection-wrap{
        grid-template-columns:1fr;
    }
    .disease-selection-body{
        max-height:280px;
    }
}


.discharge-form-wrap{
    width:100%;
    box-sizing:border-box;
    background:#f8fafc;
    color:#1e293b;
    font-family:Arial,Helvetica,sans-serif;
    padding:18px;
}

.discharge-header-card{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:10px;
    box-shadow:0 2px 8px rgba(15,23,42,.05);
}

.discharge-header-grid{
    display:grid;
    grid-template-columns:repeat(7,minmax(0,1fr));
    gap:8px 14px;
}

.discharge-field{
    display:grid;
    grid-template-columns:155px minmax(0,1fr);
    align-items:center;
    gap:10px;
    min-width:0;
}

.discharge-field-label{
    font-size:14px;
    font-weight:700;
    color:#334155;
}

.discharge-field input,
.discharge-field textarea,
.discharge-field select{
    width:100%;
    box-sizing:border-box;
    min-height:36px;
    border:1px solid #cbd5e1;
    background:#f8fafc;
    border-radius:5px;
    padding:7px 10px;
    font-size:13px;
    color:#334155;
    outline:none;
}

.discharge-field input:focus,
.discharge-field textarea:focus,
.discharge-field select:focus{
    border-color:#2563eb;
    background:#fff;
}

.discharge-field input[readonly],
.discharge-field textarea[readonly]{
    background:#f1f5f9;
}

.discharge-field textarea{
    min-height:58px;
    resize:vertical;
}

.discharge-sex-age{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:4px;
}

.discharge-section{
    margin-top:5px;
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:10px;
    box-shadow:0 2px 8px rgba(15,23,42,.05);
}

.discharge-section-title{
    display:flex;
    align-items:center;
    gap:9px;
    padding-bottom:5px;
    border-bottom:2px solid #2563eb;
    color:#1769c2;
    font-size:18px;
    font-weight:800;
}

.discharge-section-icon{
    font-size:20px;
}

.discharge-type-row{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:28px;
    margin-bottom:3px;
    margin-top:5px;
}

.discharge-type-label{
    font-size:14px;
    font-weight:700;
    color:#334155;
    margin-right:12px;
}

.discharge-radio{
    display:flex;
    align-items:center;
    gap:7px;
    font-size:14px;
    color:#334155;
    cursor:pointer;
}

.discharge-radio input{
    width:18px;
    height:18px;
    accent-color:#1976d2;
    cursor:pointer;
}

.discharge-info-box{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1d4ed8;
    border-radius:7px;
    padding:10px 13px;
    font-size:13px;
    margin-bottom:18px;
}

.death-details{
    display:none;
    background:#fff7f7;
    border:1px solid #fecaca;
    border-radius:8px;
    padding:5px;
    min-width: 600px;
}

.death-details-title{
    color:#dc2626;
    font-size:14px;
    font-weight:800;
    align-content: center;
}

.death-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.death-field{
    display:grid;
    grid-template-columns:120px minmax(0,1fr);
    align-items:center;
    gap:10px;
}

.death-field label{
    font-size:13px;
    font-weight:700;
    color:#475569;
}

.death-field input{
    width:100%;
    box-sizing:border-box;
    border:1px solid #cbd5e1;
    border-radius:5px;
    padding:3px 10px;
    background:#fff;
}

.required-star{
    color:#dc2626;
}
/* =========================================
   COMMENTS + SUMMARY - TWO COLUMNS
========================================= */

.comments-summary-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    width: 100%;
    margin-top: 5px;
}
.comments-summary-column {
    min-width: 0;
}

.comments-label{
    font-size:14px;
    font-weight:700;
    color:#334155;
    padding-top:10px;
}

.comments-box-wrap{
    position:relative;
}

.comments-box{
    width:100%;
    box-sizing:border-box;
    resize:vertical;
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:11px 12px 10px;
    font-family:Arial,Helvetica,sans-serif;
    font-size:13px;
    outline:none;
}

.comments-box:focus{
    border-color:#2563eb;
}

.comments-counter{
    position:absolute;
    right:10px;
    bottom:7px;
    font-size:11px;
    color:#64748b;
}
/* =========================================
   RESPONSIVE
========================================= */

@media (max-width: 768px) {

    .comments-summary-row {
        grid-template-columns: 1fr;
        gap: 15px;
    }

}
.discharge-tabs{
    margin-top:20px;
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:10px;
    overflow:hidden;
}
.discharge-tab-nav{
    display:flex;
    align-items:flex-end;
    gap:4px;
    padding:8px 10px;
    overflow-x:auto;
    background:#f8fafc;
    border-bottom:1px solid #cbd5e1;
}
.discharge-tab-button{
    flex:0 0 auto;
    border:1px solid #e2e8f0;
    border-bottom:0;
    border-radius:7px 7px 0 0;
    padding:11px 17px;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    white-space:nowrap;
    position:relative;
    margin-bottom:-1px;
    transition:all .15s ease;
}
.discharge-tab-button:hover{
    background:#fff;
    color:#1769c2;
}
.discharge-tab-button.active{
    color:#1769c2;
    background:#fff;
    border-color:#cbd5e1;
    border-bottom:1px solid #fff;
    z-index:2;
}
.discharge-tab-content{
    display:none;
    padding:18px;
    background:#fff;
}
.discharge-tab-content.active{
    display:block;
}
.patient-details-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px 20px;
}
.patient-detail-field{
    min-width:0;
}
.patient-detail-label{
    font-size:11px;
    color:#64748b;
    font-weight:700;
    margin-bottom:5px;
}
.patient-detail-value{
    min-height:36px;
    box-sizing:border-box;
    border:1px solid #cbd5e1;
    background:#f8fafc;
    border-radius:5px;
    padding:8px 10px;
    font-size:13px;
    color:#334155;
}
.patient-detail-field.full{
    grid-column:span 2;
}
.patient-detail-field.full4{
    grid-column:span 4;
}
.patient-detail-value textarea{
    width:100%;
    min-height:60px;
    border:0;
    background:transparent;
    outline:none;
    resize:vertical;
    font-family:Arial,Helvetica,sans-serif;
    font-size:13px;
    color:#334155;
}
.empty-tab{
    padding:35px 20px;
    text-align:center;
    color:#64748b;
    font-size:13px;
    background:#f8fafc;
    border:1px dashed #cbd5e1;
    border-radius:7px;
}
.discharge-footer{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:18px;
}
.discharge-btn{
    border:0;
    border-radius:7px;
    padding:10px 18px;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
}

.discharge-btn-close{
    background:#e2e8f0;
    color:#334155;
}

.discharge-btn-save{
    background:#1976d2;
    color:#fff;
}

.discharge-btn-save:hover{
    background:#1565c0;
}

@media(max-width:1100px){
    .discharge-header-grid{
        grid-template-columns:1fr 1fr;
    }

    .patient-details-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .patient-detail-field.full4{
        grid-column:span 2;
    }
}

@media(max-width:700px){
    .discharge-form-wrap{
        padding:10px;
    }

    .discharge-header-grid{
        grid-template-columns:1fr;
    }

    .discharge-field{
        grid-template-columns:125px minmax(0,1fr);
    }

    .death-grid{
        grid-template-columns:1fr;
    }

    .comments-row{
        grid-template-columns:1fr;
        gap:7px;
    }

    .patient-details-grid{
        grid-template-columns:1fr;
    }

    .patient-detail-field.full,
    .patient-detail-field.full4{
        grid-column:span 1;
    }
}
/* =========================================
   DISCHARGE MEDICINE SELECTION
========================================= */

.medicine-selection-wrap{
    width:100%;
}

.medicine-filter-row{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr 1fr;
    gap:8px;
    margin-bottom:12px;
}

.medicine-filter-row input,
.medicine-filter-row select{
    width:100%;
    height:36px;
    box-sizing:border-box;
    border:1px solid #cbd5e1;
    border-radius:5px;
    padding:7px 9px;
    background:#fff;
    color:#334155;
    font-size:12px;
    outline:none;
}

.medicine-filter-row input:focus,
.medicine-filter-row select:focus{
    border-color:#2563eb;
}

.medicine-selection-panels{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    width:100%;
}

.medicine-selection-panel{
    border:1px solid #e2e8f0;
    border-radius:8px;
    background:#fff;
    overflow:hidden;
    min-width:0;
}

.medicine-selection-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:11px 14px;
    background:#f8fafc;
    border-bottom:1px solid #e2e8f0;
    font-size:13px;
    font-weight:600;
    color:#334155;
}

.medicine-selection-header span{
    font-size:11px;
    font-weight:500;
    color:#64748b;
}

.medicine-selection-body{
    padding:10px;
    max-height:360px;
    overflow-y:auto;
}

.medicine-row{
    display:flex;
    align-items:center;
    gap:8px;
    padding:5px 6px;
    margin-bottom:6px;
    border:1px solid #e2e8f0;
    border-radius:6px;
    background:#fff;
}

.medicine-row:hover{
    border-color:#cbd5e1;
    background:#f8fafc;
}

.medicine-row:last-child{
    margin-bottom:0;
}

.medicine-checkbox{
    width:17px;
    height:17px;
    flex:0 0 17px;
    cursor:pointer;
}

.medicine-row-info{
    min-width:0;
    flex:1;
}

.medicine-row-name{
    font-size:12px;
    font-weight:600;
    color:#334155;
    line-height:1.35;
}

.medicine-row-meta{
    margin-top:2px;
    font-size:10px;
    color:#64748b;
}

.medicine-stock-ok{
    color:#16a34a;
    font-weight:600;
}

.medicine-stock-low{
    color:#dc2626;
    font-weight:600;
}
.selected-medicine-wrap{
    width:100%;
    overflow-x:auto;
    overflow-y:hidden;
}

.selected-medicine-table{
    width:100%;
    min-width:1200px;
    border-collapse:collapse;
    background:#fff;
    table-layout:auto;
}

.selected-medicine-table th,
.selected-medicine-table td{
    border:1px solid #d9dee5;
    padding:5px;
    vertical-align:middle;
}

.selected-medicine-table th{
    background:#f1f3f5;
    font-size:12px;
    font-weight:600;
    text-align:center;
    white-space:nowrap;
    height:36px;
}

.selected-medicine-table td{
    background:#fff;
}

.selected-medicine-table input,
.selected-medicine-table select{
    width:100%;
    height:34px;
    padding:4px 7px;
    border:1px solid #ccd2d8;
    border-radius:4px;
    background:#fff;
    font-size:12px;
    box-sizing:border-box;
    outline:none;
}

.selected-medicine-table input:focus,
.selected-medicine-table select:focus{
    border-color:#2563eb;
}

.selected-medicine-name{
    font-size:13px;
    font-weight:600;
    white-space:nowrap;
    padding-left:8px !important;
    padding-right:8px !important;
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

.medicine-empty-state{
    padding:35px 15px;
    text-align:center;
    color:#94a3b8;
    font-size:12px;
}

@media(max-width:900px){
    .medicine-filter-row{
        grid-template-columns:1fr 1fr;
    }

    .medicine-filter-row input{
        grid-column:span 2;
    }
}

@media(max-width:700px){
    .medicine-selection-panels{
        grid-template-columns:1fr;
    }

    .medicine-selection-body{
        max-height:280px;
    }

    .medicine-filter-row{
        grid-template-columns:1fr;
    }

    .medicine-filter-row input{
        grid-column:span 1;
    }
}
.discharge-instructions-notes{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    width:100%;
    margin-top:16px;
}

.discharge-instruction-note{
    border:1px solid #e2e8f0;
    border-radius:8px;
    background:#fff;
    overflow:hidden;
}

.discharge-instruction-note-label{
    padding:11px 14px;
    background:#f8fafc;
    border-bottom:1px solid #e2e8f0;
    font-size:13px;
    font-weight:600;
    color:#334155;
}

.discharge-instruction-note-box{
    display:block;
    width:100%;
    min-height:120px;
    padding:10px 12px;
    border:0;
    resize:vertical;
    box-sizing:border-box;
    background:#fff;
    color:#334155;
    font-family:inherit;
    font-size:12px;
    line-height:1.5;
    outline:none;
}

.discharge-instruction-note-box:focus{
    box-shadow:inset 0 0 0 1px #2563eb;
}

.discharge-instruction-note-counter{
    padding:5px 10px;
    border-top:1px solid #e2e8f0;
    text-align:right;
    font-size:10px;
    color:#94a3b8;
}

@media(max-width:700px){
    .discharge-instructions-notes{
        grid-template-columns:1fr;
    }
}
.follow-up-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    width:100%;
    margin-top:16px;
}

.follow-up-field{
    display:flex;
    flex-direction:column;
    gap:6px;
}

.follow-up-label{
    font-size:13px;
    font-weight:600;
    color:#334155;
}

.follow-up-input{
    width:100%;
    height:38px;
    padding:7px 10px;
    border:1px solid #cbd5e1;
    border-radius:5px;
    background:#fff;
    color:#334155;
    font-family:inherit;
    font-size:12px;
    box-sizing:border-box;
    outline:none;
}

.follow-up-input:focus{
    border-color:#2563eb;
}

@media(max-width:700px){
    .follow-up-row{
        grid-template-columns:1fr;
    }
}


.medicine-row-name {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.medicine-row-stock {
    margin-left: auto;
    white-space: nowrap;
    font-size: 12px;
    font-weight: 500;
}
/* =========================================
   DIET DETAILS & GUIDELINES
========================================= */

#investigationsTab .diet-guidelines-card,
#dietTab .diet-guidelines-card,
#guidelinesTab .diet-guidelines-card{
    margin-bottom:0;
}

#dietTab .diet-guidelines-card h3,
#guidelinesTab .diet-guidelines-card h3{
    margin:0 0 18px;
    font-size:18px;
    font-weight:700;
    color:#172554;
}

.diet-guidelines-field{
    position:relative;
    width:100%;
}

.diet-guidelines-field > label{
    display:block;
    margin:0 0 8px;
    font-size:14px;
    font-weight:600;
    color:#334155;
}

.diet-guidelines-textarea{
    display:block;
    width:100%;
    min-height:180px;
    box-sizing:border-box;
    resize:vertical;
    padding:12px 14px 30px;
    border:1px solid #d7e0e8;
    border-radius:10px;
    background:#fff;
    color:#334155;
    font-family:inherit;
    font-size:13px;
    line-height:1.6;
    outline:none;
    transition:border-color .15s ease,box-shadow .15s ease;
}

.diet-guidelines-textarea::placeholder{
    color:#94a3b8;
}

.diet-guidelines-textarea:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}

.diet-guidelines-counter{
    position:absolute;
    right:12px;
    bottom:9px;
    padding:2px 6px;
    border-radius:5px;
    background:#f8fafc;
    color:#94a3b8;
    font-size:11px;
    line-height:16px;
    pointer-events:none;
}
/* =========================================
   INVESTIGATIONS UI
========================================= */

#investigationsTab{
    padding:0;
}

#investigationsTab .card{
    margin-bottom:5px;
}

#investigationsTab .card:last-child{
    margin-bottom:0;
}
/* Patient Vitals */

#investigationsTab .vitals-grid{
    display:grid;
    grid-template-columns:220px 220px 255px minmax(500px,1fr);
    gap:26px;
    align-items:end;
    width:100%;
}

#investigationsTab .vitals-grid > div{
    min-width:0;
}

#investigationsTab .vitals-grid > div > label{
    display:block;
    margin:0 0 8px;
    font-size:14px;
    line-height:18px;
    font-weight:500;
    color:#0f172a;
}

#investigationsTab .vitals-grid input[type="number"],
#investigationsTab .vitals-grid input[type="text"]{
    width: 100%;
    padding:8px 10px;
    border:1px solid #dbe3eb;
    border-radius:10px;
    background:#f8fafc;
    outline:none;
}

#investigationsTab .vitals-grid input[type="number"]:focus{
    border-color:#2563eb;
    background:#fff;
}

#investigationsTab .vitals-grid #bmi{
    background:#eef6ff;
    color:#1976d2;
    font-weight:700;
    text-align:center;
}

/* Pain Scale */

#investigationsTab .pain-scale-box{
    width:100%;
    height:33px;
    box-sizing:border-box;
    display:grid;
    grid-template-columns:repeat(11,1fr);
    align-items:center;
    padding:0 8px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#fff;
}

#investigationsTab .pain-scale-box label{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    height:100%;
    margin:0;
    padding:0;
    font-size:14px;
    font-weight:500;
    color:#334155;
    cursor:pointer;
    white-space:nowrap;
}

#investigationsTab .pain-scale-box input[type="radio"]{
    width:15px;
    height:15px;
    margin:0;
    cursor:pointer;
}

#investigationsTab .pain-scale-box label:hover{
    background:#f8fafc;
    border-radius:6px;
}
/* =========================================
   PATIENT PARAMETERS
========================================= */

#investigationsTab #parameterTable{
    width:100%;
    min-width:1100px;
    margin:0 !important;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
    overflow:hidden;
    border:1px solid #e2e8f0;
    border-radius:8px;
    background:#fff;
}

/* Header */

#investigationsTab #parameterTable thead th{
    height:44px;
    padding:10px 12px !important;
    background:#f8fafc;
    border-top:0;
    border-bottom:1px solid #dbe3eb;
    color:#334155;
    font-size:13px;
    font-weight:700;
    line-height:18px;
    vertical-align:middle;
    white-space:nowrap;
}

#investigationsTab #parameterTable thead th:first-child{
    border-left:0;
}

#investigationsTab #parameterTable thead th:last-child{
    border-right:0;
}

/* Column widths */

#investigationsTab #parameterTable th:nth-child(1),
#investigationsTab #parameterTable td:nth-child(1){
    width:55px;
    text-align:center;
}

#investigationsTab #parameterTable th:nth-child(2),
#investigationsTab #parameterTable td:nth-child(2){
    width:250px;
    text-align:left;
}

#investigationsTab #parameterTable th:nth-child(3),
#investigationsTab #parameterTable td:nth-child(3){
    width:150px;
    text-align:center;
}

#investigationsTab #parameterTable th:nth-child(4),
#investigationsTab #parameterTable td:nth-child(4){
    width:165px;
    text-align:center;
}

#investigationsTab #parameterTable th:nth-child(5),
#investigationsTab #parameterTable td:nth-child(5){
    width:145px;
    text-align:center;
}

#investigationsTab #parameterTable th:nth-child(6),
#investigationsTab #parameterTable td:nth-child(6){
    width:125px;
    text-align:center;
}

#investigationsTab #parameterTable th:nth-child(7),
#investigationsTab #parameterTable td:nth-child(7){
    width:155px;
    text-align:center;
}

#investigationsTab #parameterTable th:nth-child(8),
#investigationsTab #parameterTable td:nth-child(8){
    width:65px;
    text-align:center;
}

/* Body */

#investigationsTab #parameterTable tbody td{
    height:62px;
    padding:10px 12px !important;
    border-top:0;
    border-bottom:1px solid #edf1f5;
    vertical-align:middle !important;
    color:#334155;
    font-size:13px;
    background:#fff;
}

#investigationsTab #parameterTable tbody tr:last-child td{
    border-bottom:0;
}

#investigationsTab #parameterTable tbody tr:hover td{
    background:#f8fbff;
}

/* Serial number */

#investigationsTab #parameterTable tbody td:first-child{
    color:#64748b;
    font-weight:600;
}

/* Parameter name */

#investigationsTab #parameterTable tbody td:nth-child(2) strong{
    display:inline-flex;
    align-items:baseline;
    flex-wrap:wrap;
    gap:5px;
    font-size:14px !important;
    line-height:20px;
    color:#172554;
}

#investigationsTab #parameterTable tbody td:nth-child(2) strong span{
    font-size:12px !important;
    color:#64748b !important;
    font-weight:500 !important;
}

/* Latest reading */

#investigationsTab #parameterTable .latest-reading{
    text-align:center;
}

#investigationsTab #parameterTable .latest-reading-value{
    min-height:38px;
    display:flex;
    align-items:center;
    justify-content:center;
}

#investigationsTab #parameterTable .reading-value{
    min-width:55px;
    padding:7px 12px;
    border-radius:7px;
    background:#f1f5f9;
    color:#334155;
    font-size:14px;
    font-weight:700;
    line-height:20px;
}

/* Current reading */

#investigationsTab #parameterTable .parameter-reading{
    width:100%;
    height:40px;
    box-sizing:border-box;
    margin:0;
    padding:7px 10px;
    border:1px solid #d7e0e8;
    border-radius:8px;
    background:#fff;
    color:#1e293b;
    font-size:13px;
    font-weight:600;
    text-align:center;
    outline:none;
    transition:border-color .15s ease,box-shadow .15s ease;
}

#investigationsTab #parameterTable .parameter-reading::placeholder{
    color:#94a3b8;
    font-weight:500;
}

#investigationsTab #parameterTable .parameter-reading:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}

/* Date / Time / User */

#investigationsTab #parameterTable .parameter-date,
#investigationsTab #parameterTable .parameter-time,
#investigationsTab #parameterTable .parameter-user{
    width:100%;
    height:40px;
    box-sizing:border-box;
    margin:0;
    padding:7px 9px;
    border:1px solid #d7e0e8;
    border-radius:8px;
    background:#fff;
    color:#334155;
    font-size:12.5px;
    outline:none;
}

#investigationsTab #parameterTable .parameter-date:focus,
#investigationsTab #parameterTable .parameter-time:focus,
#investigationsTab #parameterTable .parameter-user:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}

/* User */

#investigationsTab #parameterTable .parameter-user{
    cursor:pointer;
    font-weight:500;
}

/* Trend button */

#investigationsTab #parameterTable .parameter-chart-btn{
    width:36px;
    height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin:0 auto;
    padding:0;
    border:1px solid #dbe4ee !important;
    border-radius:8px;
    background:#f8fafc !important;
    color:#2563eb;
    cursor:pointer;
    font-size:17px;
    line-height:1;
    transition:all .15s ease;
}

#investigationsTab #parameterTable .parameter-chart-btn:hover{
    background:#eff6ff !important;
    border-color:#bfdbfe !important;
    transform:translateY(-1px);
}

#investigationsTab #parameterTable .parameter-chart-btn:active{
    transform:translateY(0);
}

/* Responsive */

@media(max-width:900px){

    #investigationsTab #parameterTable{
        min-width:1100px;
    }

}
h3 {
    margin: 1px !important;
    background: #b8dbf9;
}
/* Services */

#investigationsTab .services-layout{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:22px;
}

#investigationsTab .services-column{
    min-width:0;
}

#investigationsTab .services-filter-row{
    display:grid;
    grid-template-columns:1.6fr 1fr 1fr 1fr;
    gap:10px;
    margin-bottom:12px;
}

#investigationsTab .services-filter-row input,
#investigationsTab .services-filter-row select{
    width:100%;
    height:30px;
    border-radius: 10px;
    box-sizing:border-box;
    margin:0;
}

#investigationsTab .services-section-title{
    font-size:14px;
    font-weight:600;
    color:#1e293b;
    margin-bottom:8px;
}

#investigationsTab #serviceList,
#investigationsTab #selectedServices{
    height:400px;
    overflow:auto;
    box-sizing:border-box;
    border:1px solid #d9dee5;
    border-radius:6px;
    background:#fff;
    padding:10px;
}

#investigationsTab .service-row hr{
    border:0;
    border-top:1px solid #e2e8f0;
}
#investigationsTab .service-row label{
    display:flex;
    align-items:center;
    gap:10px;
    width:100%;
    cursor:pointer;
}

#investigationsTab .service-row .service-checkbox{
    width:18px !important;
    min-width:18px !important;
    max-width:18px !important;
    height:18px !important;
    flex:0 0 18px !important;
    margin:0 !important;
    padding:0 !important;
}

#investigationsTab .service-row label > div{
    min-width:0;
}

#investigationsTab .service-row label > div > div{
    min-width:0;
}

#investigationsTab .service-row label > div > div > span{
    min-width:0;
}

#investigationsTab .service-row label > div > div > span:last-child{
    white-space:nowrap;
}
@media(max-width:1100px){

    #investigationsTab .vitals-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    #investigationsTab .services-layout{
        grid-template-columns:1fr;
    }

}

@media(max-width:700px){

    #investigationsTab .vitals-grid{
        grid-template-columns:1fr;
    }

    #investigationsTab .services-filter-row{
        grid-template-columns:1fr;
    }

}
/* =========================================
   CLINICAL DISCHARGE DETAILS
========================================= */

.discharge-clinical-details{
    margin-top:5px;
}

.discharge-clinical-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:5px 18px;
    margin-bottom:5px;
}

.discharge-clinical-field{
    min-width:0;
}

.discharge-clinical-field label,
.discharge-clinical-comment label{
    display:block;
    margin:0 0 7px;
    font-size:14px;
    line-height:19px;
    font-weight:600;
    color:#334155;
}

.discharge-clinical-input{
    width:100%;
    height:44px;
    box-sizing:border-box;
    padding:9px 12px;
    border:1px solid #d7e0e8;
    border-radius:8px;
    background:#fff;
    color:#334155;
    font-family:inherit;
    font-size:13px;
    outline:none;
    transition:border-color .15s ease,box-shadow .15s ease;
}

.discharge-clinical-input::placeholder{
    color:#94a3b8;
}

.discharge-clinical-input:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}

.discharge-clinical-comment{
    position:relative;
    margin-bottom:5px;
    width: 100%;
}
.discharge-clinical-comment:last-child{
    margin-bottom:0;
}

.discharge-clinical-textarea{
    display:block;
    width:100%;
    box-sizing:border-box;
    resize:vertical;
    padding:11px 13px 30px;
    border:1px solid #d7e0e8;
    border-radius:8px;
    background:#fff;
    color:#334155;
    font-family:inherit;
    font-size:13px;
    line-height:1.6;
    outline:none;
    transition:border-color .15s ease,box-shadow .15s ease;
}
.discharge-clinical-textarea::placeholder{
    color:#94a3b8;
}
.discharge-clinical-textarea:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}
.discharge-clinical-counter{
    position:absolute;
    right:10px;
    bottom:8px;
    padding:2px 6px;
    border-radius:4px;
    background:#f8fafc;
    color:#94a3b8;
    font-size:11px;
    line-height:15px;
    pointer-events:none;
}
@media(max-width:700px){
    .discharge-clinical-grid{
        grid-template-columns:1fr;
    }
}
</style>
<form id="dischargeForm" class="discharge-form-wrap" method="post">
<input type="hidden" name="ipd_admission_id" value="<?= (int)$ipd['ipd_admission_id'] ?>">
<!-- =========================================
PATIENT / ADMISSION HEADER
========================================= -->

<div class="discharge-header-card">

<div class="discharge-header-grid">

<div class="patient-detail-field">
<div class="patient-detail-label">UHID No.</div>
<div class="patient-detail-value">
<?=e($ipd['uhid'])?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">IP No.</div>
<div class="patient-detail-value">
<?=e($ipd['ipd_no'])?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Patient Name</div>
<div class="patient-detail-value">
<?=e($patientName)?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Sex / Age</div>
<div class="patient-detail-value">
<?=e($sex)?> / <?=e($ageText)?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Empanelment</div>
<div class="patient-detail-value">
<?=e($ipd['empanelment_name'] ?? '-')?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Admitting Name</div>
<div class="patient-detail-value">
<?=e($doctorName)?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Admission Type</div>
<div class="patient-detail-value"><?=e($ipd['admission_type'] ?? '-')?></div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Building</div>
<div class="patient-detail-value">
<?=e($ipd['building_name'] ?? '-')?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Floor</div>
<div class="patient-detail-value">
<?=e($ipd['floor_name'] ?? '-')?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Room</div>
<div class="patient-detail-value">
<?php
$roomDisplay='-';

if(!empty($ipd['room_number'])){
    $roomDisplay=$ipd['room_number'];

    if(!empty($ipd['room_name'])){
        $roomDisplay.=' ('.$ipd['room_name'].')';
    }
}
?>
<?=e($roomDisplay)?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Bed</div>
<div class="patient-detail-value">
<?=e($ipd['bed_number'] ?? '-')?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Referred By</div>
<div class="patient-detail-value">-</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Disease</div>
<div class="patient-detail-value">
<?=e($ipd['disease_name'] ?? '-')?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Admit Date</div>
<div class="patient-detail-value">
<?=e($admitDateTime)?>
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Discharge Date</div>
<div class="patient-detail-value" style="background:white;">
<input
    type="date"
    name="discharge_date"
    id="discharge_date"
    value="<?= date('Y-m-d') ?>"
    min="<?= e($ipd['admission_date']) ?>"
    max="<?= date('Y-m-d') ?>"
    style="width:100%;border:0;background:white;outline:none;font-size:13px;color:#334155;">
    </div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Discharge Time</div>
<div class="patient-detail-value"  style="background:white;">
<input
type="time"
name="discharge_time"
id="discharge_time"
value="<?= date('H:i') ?>"
style="width:100%;border:0;background:transparent;outline:none;font-size:13px;">
</div>
</div>

<div class="patient-detail-field">
<div class="patient-detail-label">Length of Stay</div>
<div class="patient-detail-value" id="length_of_stay">
    <?=e($lengthOfStay)?> Day<?=($lengthOfStay==1?'':'s')?>
</div>
</div>


</div>
</div>

<!-- =========================================
DISCHARGE DETAILS
========================================= -->

<div class="discharge-section">

<div class="discharge-section-title">
<span class="discharge-section-icon">▣</span>
<span>DISCHARGE DETAILS</span>
</div>

<div class="discharge-type-row">

<div class="discharge-type-label">
1. Discharge Type <span class="required-star">*</span>
</div>

<label class="discharge-radio">
<input
type="radio"
name="discharge_type"
value="Normal"
checked
onchange="window.parent.dischargeTypeChanged && window.parent.dischargeTypeChanged(this.value)">
Normal
</label>

<label class="discharge-radio">
<input
type="radio"
name="discharge_type"
value="LAMA"
onchange="window.parent.dischargeTypeChanged && window.parent.dischargeTypeChanged(this.value)">
LAMA
</label>

<label class="discharge-radio">
<input
type="radio"
name="discharge_type"
value="DAMA"
onchange="window.parent.dischargeTypeChanged && window.parent.dischargeTypeChanged(this.value)">
DAMA
</label>

<label class="discharge-radio">
<input
type="radio"
name="discharge_type"
value="MLC"
onchange="window.parent.dischargeTypeChanged && window.parent.dischargeTypeChanged(this.value)">
MLC
</label>

<label class="discharge-radio">
<input
type="radio"
name="discharge_type"
value="Referred"
onchange="window.parent.dischargeTypeChanged && window.parent.dischargeTypeChanged(this.value)">
Referred
</label>

<label class="discharge-radio">
<input
type="radio"
name="discharge_type"
value="Death"
onchange="window.parent.dischargeTypeChanged && window.parent.dischargeTypeChanged(this.value)">
Death
</label>
<div id="deathDetails" class="death-details">
    <div id="dischargeTypeFullName" class="death-details-title"></div>
    <div id="deathFields" style="display:none;">
        <div class="death-grid">
            <div class="death-field">
                <label>Death Date <span class="required-star">*</span></label>
                <input type="date" name="death_date" id="death_date">
            </div>
            <div class="death-field">
                <label>Death Time <span class="required-star">*</span></label>
                <input type="time" name="death_time" id="death_time">
            </div>
        </div>
    </div>
</div>

</div>
<!-- =========================================
     COMMENTS + SUMMARY
========================================= -->
<div class="comments-summary-row">

    <!-- COMMENTS / REMARKS -->
    <div class="comments-summary-column">

        <div class="comments-label">
            2. Comments / Remarks
            <span class="required-star">*</span>
        </div>

        <div class="comments-box-wrap">

            <textarea
                class="comments-box"
                name="comments"
                id="discharge_comments"
                maxlength="1000"
                required
                placeholder="Enter comments / remarks (required for all discharge types)"
                oninput="window.parent.updateDischargeCommentCounter && window.parent.updateDischargeCommentCounter(this.value.length)"
            ></textarea>

            <div
                id="dischargeCommentCounter"
                class="comments-counter">
                0 / 1000
            </div>

        </div>

    </div>


    <!-- SUMMARY -->
    <div class="comments-summary-column">

        <div class="comments-label">
            3. Discharge Summary
            <span class="required-star">*</span>
        </div>

        <div class="comments-box-wrap">

            <textarea
                class="comments-box"
                name="summary"
                id="discharge_summary"
                maxlength="1000"
                required
                placeholder="Enter discharge summary"
                oninput="window.updateDischargeSummaryCounter && window.updateDischargeSummaryCounter(this.value.length)"
            ></textarea>

            <div
                id="dischargeSummaryCounter"
                class="comments-counter">
                0 / 1000
            </div>

        </div>

    </div>

</div>

<!-- =========================================
TABS
========================================= -->
<div class="discharge-tabs">
<div class="discharge-tab-nav">
<button
type="button"
class="discharge-tab-button active"
data-tab="patientDetailsTab"
onclick="window.parent.openDischargeTab && window.parent.openDischargeTab('patientDetailsTab',this)">
👤 Discharge Details
</button>
<button
type="button"
class="discharge-tab-button"
data-tab="instructionsTab"
onclick="window.parent.openDischargeTab && window.parent.openDischargeTab('instructionsTab',this)">
📄 Instructions
</button>
<button
type="button"
class="discharge-tab-button"
data-tab="investigationsTab"
onclick="window.parent.openDischargeTab && window.parent.openDischargeTab('investigationsTab',this)">
⚗ Investigations
</button>
<button
type="button"
class="discharge-tab-button"
data-tab="dietTab"
onclick="window.parent.openDischargeTab && window.parent.openDischargeTab('dietTab',this)">
🍴 Diet Detail
</button>

<button
type="button"
class="discharge-tab-button"
data-tab="guidelinesTab"
onclick="window.parent.openDischargeTab && window.parent.openDischargeTab('guidelinesTab',this)">
ⓘ Guidelines
</button>

<button
type="button"
class="discharge-tab-button"
data-tab="doctorsTab"
onclick="window.parent.openDischargeTab && window.parent.openDischargeTab('doctorsTab',this)">
👥 Doctors List
</button>

</div>

<!-- =========================================
PATIENT DETAILS
========================================= -->

<div
id="patientDetailsTab"
class="discharge-tab-content active">
<div class="disease-selection-wrap">

    <div class="disease-selection-panel">

        <div class="disease-selection-header">
            <div>
                <i class="fas fa-notes-medical"></i>
                Available Diseases
            </div>
            <span>
                <?= count($diseaseMasterList) ?> Disease<?= count($diseaseMasterList) == 1 ? '' : 's' ?>
            </span>
        </div>

        <div
            class="disease-selection-body"
            id="availableDiseasesBody">

            <?php if (!empty($diseaseMasterList)) { ?>

                <?php foreach ($diseaseMasterList as $disease) { ?>

                    <div
                        class="disease-selection-item available-disease-item"
                        id="availableDisease_<?= (int)$disease['id'] ?>"
                        data-disease-id="<?= (int)$disease['id'] ?>"
                        data-disease-name="<?= htmlspecialchars($disease['disease_name'] ?? '', ENT_QUOTES) ?>"
                        data-icd-code="<?= htmlspecialchars($disease['icd_code'] ?? '', ENT_QUOTES) ?>">

                        <div class="disease-selection-info">

                            <div class="disease-selection-name">
                                <?= htmlspecialchars($disease['disease_name'] ?? '') ?>
                                <?php if (!empty($disease['icd_code'])) { ?>
                                    &nbsp;•&nbsp; <?= htmlspecialchars($disease['icd_code']) ?>
                                <?php } ?>
                            </div>

                        </div>

                        <button
                            type="button"
                            class="disease-add-btn"
                            title="Add Disease"
                            onclick="addDischargeDisease(<?= (int)$disease['id'] ?>)">
                            +
                        </button>

                    </div>

                <?php } ?>

            <?php } else { ?>

                <div class="disease-empty-state">
                    No diseases found in Disease Master.
                </div>

            <?php } ?>
        </div>
    </div>
    <div class="disease-selection-panel">
        <div class="disease-selection-header">
            <div>
                <i class="fas fa-clipboard-list"></i>
                Selected Diseases
            </div>
            <span id="selectedDiseasesCount">
                0 Diseases
            </span>
        </div>
        <div
            class="disease-selection-body"
            id="selectedDiseasesBody">
            <div
                class="disease-empty-state"
                id="selectedDiseasesEmpty">
                No diseases selected.
            </div>
        </div>
    </div>
</div>
<div id="selectedDiseasesInputs"></div>

<!-- =========================================
CLINICAL DISCHARGE DETAILS
========================================= -->

<div class="discharge-clinical-details">

    <!-- PROGNOSIS + COMORBIDITY -->
    <div class="discharge-clinical-grid">

        <div class="discharge-clinical-field">
            <label>Prognosis
            </label>

            <input
                type="text"
                name="prognosis"
                id="prognosis"
                class="discharge-clinical-input"
                maxlength="500"
                placeholder="Enter prognosis">
        </div>

        <div class="discharge-clinical-field">
            <label>
                Comorbidity
            </label>

            <input
                type="text"
                name="comorbidity"
                id="comorbidity"
                class="discharge-clinical-input"
                maxlength="500"
                placeholder="Enter comorbidity">
        </div>

    </div>

<div class="discharge-clinical-grid">
    <!-- CONDITION AT DISCHARGE -->
    <div class="discharge-clinical-comment">

        <label for="condition_at_discharge">
            Condition at discharge
        </label>

        <textarea
            name="condition_at_discharge"
            id="condition_at_discharge"
            class="discharge-clinical-textarea"
            maxlength="2000"
            placeholder="Enter patient's condition at the time of discharge..."></textarea>

        <div class="discharge-clinical-counter">
            <span id="conditionAtDischargeCounter">0</span> / 2000
        </div>

    </div>


    <!-- C/O -->
    <div class="discharge-clinical-comment">

        <label for="co">
            C/O
        </label>

        <textarea
            name="co"
            id="co"
            class="discharge-clinical-textarea"
            maxlength="2000"
            placeholder="Enter complaints / C/O..."></textarea>

        <div class="discharge-clinical-counter">
            <span id="coCounter">0</span> / 2000
        </div>

    </div>


    <!-- H/O -->
    <div class="discharge-clinical-comment">

        <label for="ho">
            H/O
        </label>

        <textarea
            name="ho"
            id="ho"
            class="discharge-clinical-textarea"
            maxlength="2000"
            placeholder="Enter relevant history / H/O..."></textarea>

        <div class="discharge-clinical-counter">
            <span id="hoCounter">0</span> / 2000
        </div>

    </div>

</div>
</div>
</div>
<!-- =========================================
INSTRUCTIONS
========================================= -->

<div
id="instructionsTab"
class="discharge-tab-content">

<div class="medicine-selection-wrap">

    <!-- =====================================
         MEDICINE FILTERS
         ===================================== -->

    <div class="medicine-filter-row">

        <input
            type="text"
            id="dischargeMedicineSearch"
            placeholder="Search medicine..."
            autocomplete="off">

        <select id="dischargeCategoryItemFilter">
            <option value="">All Categories</option>

            <?php while($category=mysqli_fetch_assoc($categoryItemResult)){ ?>
                <option value="<?= (int)$category['id'] ?>">
                    <?= e($category['category_name']) ?>
                </option>
            <?php } ?>

        </select>

        <select id="dischargeSubCategoryItemFilter">
            <option value="">All Sub Categories</option>

            <?php while($subCategory=mysqli_fetch_assoc($subCategoryItemResult)){ ?>
                <option
                    value="<?= (int)$subCategory['id'] ?>"
                    data-parent="<?= (int)$subCategory['parent_id'] ?>">
                    <?= e($subCategory['category_name']) ?>
                </option>
            <?php } ?>

        </select>

        <select id="dischargeStoreFilter">
            <option value="">All Stores</option>

            <?php while($store=mysqli_fetch_assoc($storeResult)){ ?>
                <option value="<?= (int)$store['id'] ?>">
                    <?= e($store['store_name']) ?>
                </option>
            <?php } ?>

        </select>

        <select id="dischargeManufacturerFilter">
            <option value="">All Manufacturers</option>

            <?php while($manufacturer=mysqli_fetch_assoc($manufacturerResult)){ ?>
                <option value="<?= (int)$manufacturer['id'] ?>">
                    <?= e($manufacturer['party_name']) ?>
                </option>
            <?php } ?>

        </select>

    </div>


    <!-- =====================================
         AVAILABLE + SELECTED MEDICINES
         ===================================== -->
<div
    class="medicine-selection-panels"
    style="display:flex;gap:20px;align-items:flex-start;">

    <!-- AVAILABLE MEDICINES -->

    <div
        class="medicine-selection-panel"
        style="width:20%;flex:0 0 20%;">

        <div class="medicine-selection-header">

            <div>
                <i class="fas fa-pills"></i>
                Available Medicines
            </div>

            <span>
                <?= mysqli_num_rows($itemResult) ?> Medicines
            </span>

        </div>

        <div
            class="medicine-selection-body"
            id="availableDischargeMedicinesBody">

            <?php if(mysqli_num_rows($itemResult)>0){ ?>

                <?php while($item=mysqli_fetch_assoc($itemResult)){ ?>

                    <?php
                    $stock=(float)($item['stock'] ?? 0);
                    $minLevel=(float)($item['min_level'] ?? 0);

                    $stockClass=
                        $stock <= $minLevel
                        ? 'medicine-stock-low'
                        : 'medicine-stock-ok';

                    $stores=$item['stores'] ?? '';
                    ?>

                    <div
                        class="medicine-row discharge-medicine-row"
                        data-name="<?= e($item['item_name']) ?>"
                        data-category="<?= (int)$item['category_id'] ?>"
                        data-subcategory="<?= (int)$item['sub_category_id'] ?>"
                        data-manufacturer="<?= (int)$item['manufacturer_id'] ?>"
                        data-store="<?= e($stores) ?>">
                        <input
                            type="checkbox"
                            class="medicine-checkbox discharge-medicine-checkbox"
                            value="<?= (int)$item['id'] ?>"
                            data-name="<?= e($item['item_name']) ?>"
                            data-stock="<?= e($stock) ?>"
                            data-min="<?= e($minLevel) ?>">
                        <div class="medicine-row-info">
    <div class="medicine-row-name">
        <?= e($item['item_name']) ?>

        <span class="medicine-row-stock <?= $stockClass ?>">
            Stock: <?= e($stock) ?>
        </span>
    </div>
</div>

                    </div>

                <?php } ?>

            <?php }else{ ?>

                <div class="medicine-empty-state">
                    No medicines found in Item Master.
                </div>

            <?php } ?>

        </div>

    </div>


    <!-- SELECTED MEDICINE DETAILS -->

    <div
        class="medicine-selection-panel"
        style="width:80%;flex:1;min-width:0;">

        <div class="medicine-selection-header">

            <div>
                <i class="fas fa-clipboard-list"></i>
                Selected Medicines
            </div>

            <span id="selectedDischargeMedicineCount">
                0 Medicines
            </span>

        </div>

        <div
            class="selected-medicine-wrap"
            id="selectedDischargeMedicineTableWrap"
            style="display:none;">

            <table class="selected-medicine-table">

                <thead>

                    <tr>
                        <th>Medicine</th>
                        <th>Freq.</th>
                        <th>Med. Meal</th>
                        <th>Qty</th>
                        <th>M</th>
                        <th>N</th>
                        <th>E</th>
                        <th>Doctor</th>
                        <th>Stop</th>
                        <th>Dated</th>
                        <th>Comments</th>
                        <th></th>
                    </tr>

                </thead>

                <tbody id="selectedDischargeMedicineBody">
                </tbody>

            </table>

        </div>

    </div>

</div>
    <!-- Hidden JSON -->
    <input
        type="hidden"
        name="instructions_medicines"
        id="discharge_selected_medicines"
        value="[]">
<!-- FOLLOW UP DATE + DAYS -->
<div class="follow-up-row">

    <div class="follow-up-field">
        <label class="follow-up-label">
            Follow Up Date
        </label>

        <input
            type="date"
            name="follow_up_date"
            id="follow_up_date"
            class="follow-up-input">
    </div>

    <div class="follow-up-field">
        <label class="follow-up-label">
            Follow Up Days
        </label>

        <input
            type="number"
            name="follow_up_days"
            id="follow_up_days"
            class="follow-up-input"
            min="0"
            step="1"
            placeholder="Enter days">
    </div>

</div>
<!-- FOLLOW UP + ADVICE -->
<div class="discharge-instructions-notes">

    <!-- FOLLOW UP DETAILS -->
    <div class="discharge-instruction-note">

        <div class="discharge-instruction-note-label">
            Follow Up Details
        </div>

        <textarea
            class="discharge-instruction-note-box"
            name="follow_up_details"
            id="follow_up_details"
            maxlength="2000"
            placeholder="Enter follow up details..."
        ></textarea>

        <div class="discharge-instruction-note-counter">
            0 / 2000
        </div>

    </div>

    <!-- ADVICE AT DISCHARGE -->
    <div class="discharge-instruction-note">

        <div class="discharge-instruction-note-label">
            Advice at Discharge
        </div>

        <textarea
            class="discharge-instruction-note-box"
            name="advice_at_discharge"
            id="advice_at_discharge"
            maxlength="2000"
            placeholder="Enter advice at discharge..."
        ></textarea>

        <div class="discharge-instruction-note-counter">
            0 / 2000
        </div>

    </div>

</div>
</div>

</div>

<!-- =========================================
INVESTIGATIONS
========================================= -->
<div id="investigationsTab" class="discharge-tab-content">

<div class="card">
<h3>📋 Patient Vitals</h3>
<div class="vitals-grid">
<div>
<label>Weight (Kg)</label>
<input type="number" step="0.01" id="weight" name="weight" placeholder="Weight" value="<?=e($weight)?>">
</div>
<div>
<label>Height (Cm)</label>
<input type="number" step="0.01" id="height" name="height" placeholder="Height" value="<?=e($height)?>">
</div>
<div>
<label>BMI Index</label>
<input type="text" id="bmi" name="bmi" value="<?=e($bmi)?>" readonly style="background:#eef6ff;font-weight:700;text-align:center;color:#1976d2;">
</div>
<div>
<label>Pain Scale (0 - No Pain) (10 - Worst)</label>
<div class="pain-scale-box">
<?php for($i=0;$i<=10;$i++){ ?>
<label>
<input type="radio" name="pain_scale" value="<?=$i?>" <?=((string)$pain_scale===(string)$i)?'checked':''?>>
<span><?=$i?></span>
</label>
<?php } ?>
</div>
</div>
</div>
</div>

<!-- =========================================
PATIENT PARAMETERS
========================================= -->
<div class="card">
<h3>📈 Patient Parameters</h3>
<div class="table-responsive">
<table
class="table table-bordered table-hover align-middle"
id="parameterTable">

<thead>

<tr>

<th style="width:50px;text-align:center;">
Sr.
</th>

<th style="min-width:220px;">
Parameter
</th>

<th style="width:180px;text-align:center;">
Latest Reading
</th>

<th style="width:180px;text-align:center;">
Current Reading
</th>

<th style="width:140px;text-align:center;">
Date
</th>

<th style="width:120px;text-align:center;">
Time
</th>

<th style="width:170px;text-align:center;">
By
</th>

<th style="width:70px;text-align:center;">
📈
</th>

</tr>

</thead>

<tbody id="parameterBody">

<?php

$sr=1;

while($parameter=mysqli_fetch_assoc($parameterResult)){

?>

<tr>

<td>
<?= $sr++ ?>
</td>

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

<td
class="latest-reading">

<div class="latest-reading-value">

<span class="reading-value">

<?= (
$parameter['latest_reading']!==null &&
$parameter['latest_reading']!==''
)
?
htmlspecialchars($parameter['latest_reading'])
:
'--'
?>

</span>

</div>

</td>

<td>

<input
type="text"
class="form-control parameter-reading"
data-parameter-id="<?= $parameter['id'] ?>"
placeholder="Enter Reading"
style="width:100%;"
autocomplete="off">

</td>

<td>

<input
type="date"
class="parameter-date"
value="<?= date('Y-m-d') ?>">

</td>

<td>

<input
type="time"
class="parameter-time"
value="<?= date('H:i') ?>">

</td>

<td>

<select
class="parameter-user"
data-parameter-id="<?= $parameter['id'] ?>">

<option value="">
Select
</option>

<option
value="<?= (int)($_SESSION['user_id'] ?? 0) ?>"
selected>

Current User

</option>
</select>
</td>
<td style="text-align:center;">
<button
type="button"
class="parameter-chart-btn"
data-parameter-id="<?= $parameter['id'] ?>"
data-parameter-name="<?= htmlspecialchars($parameter['parameter_name'],ENT_QUOTES) ?>"
title="View Trend">
📈
</button>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>

<!-- SERVICES -->
<div class="card">
<h3>Services</h3>

<div class="services-layout">

<div class="services-column">
<div class="services-filter-row">

<input type="text" id="serviceSearch" placeholder="🔍 Search Service">

<select id="categoryFilter">
<option value="">Category Type</option>
<?php
$categoryResult=mysqli_query($conn,"
SELECT DISTINCT category_type
FROM service_master
WHERE status='Active'
ORDER BY category_type
");
while($cat=mysqli_fetch_assoc($categoryResult)){
?>
<option value="<?=e($cat['category_type'])?>">
<?=e($cat['category_type'])?>
</option>
<?php } ?>
</select>

<select id="mainServiceFilter">
<option value="">Main Service</option>
<?php
$mainServiceResult=mysqli_query($conn,"
SELECT id,service_name
FROM service_master
WHERE status='Active'
AND service_type='Main Service'
ORDER BY service_name
");
while($ms=mysqli_fetch_assoc($mainServiceResult)){
?>
<option value="<?=$ms['id']?>">
<?=e($ms['service_name'])?>
</option>
<?php } ?>
</select>

<select id="serviceFilter">
<option value="">Service</option>
<?php
$serviceDropdownResult=mysqli_query($conn,"
SELECT id,service_name
FROM service_master
WHERE status='Active'
ORDER BY service_name
");
while($srv=mysqli_fetch_assoc($serviceDropdownResult)){
?>
<option value="<?=$srv['id']?>">
<?=e($srv['service_name'])?>
</option>
<?php } ?>
</select>

</div>
<div class="services-section-title">
Available Services
</div>

<div id="serviceList" style="height:400px;overflow:auto;border:1px solid #ddd;padding:10px;">

<?php
$serviceResult=mysqli_query($conn,"
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
ORDER BY category_type,service_name
");

while($srv=mysqli_fetch_assoc($serviceResult)){
$mode=trim($srv['service_mode']);
?>

<div
class="service-row"
data-category="<?=e($srv['category_type'])?>"
data-main="<?=e($srv['main_service_id'])?>"
data-id="<?=e($srv['id'])?>"
data-name="<?=strtolower(e($srv['service_name']))?>"
style="padding:2px 0;">

<label style="display:flex;align-items:center;gap:10px;cursor:pointer;">

<input
type="checkbox"
class="service-checkbox"
value="<?=$srv['id']?>"
data-name="<?=e($srv['display_name'])?>"
data-mode="<?=e($mode)?>"
data-code="<?=e($srv['service_code'])?>"
data-price="<?=e($srv['amount'])?>">

<div style="flex:1;">

<div style="display:flex;justify-content:space-between;gap:15px;">

<span>
<?=e($srv['display_name'])?>
</span>

<span style="font-size:13px;white-space:nowrap;">
<span style="
color:<?=($mode==='Service In')?'#16a34a':'#dc2626'?>;
font-weight:500;
">
<?=e($mode)?>
</span>
&nbsp;|&nbsp;
<?=e($srv['service_code'])?>
&nbsp;|&nbsp;
₹<?=number_format((float)$srv['amount'],2)?>
</span>

</div>

</div>

</label>

<hr style="margin:2px 0 0;">

</div>

<?php } ?>

</div>
</div>

<div class="services-column">
<div class="services-section-title">
Selected Services
</div>

<div
id="selectedServices"
style="
height:440px;
overflow:auto;
border:1px solid #ddd;
padding:10px;
">
</div>
</div>

</div>
</div>

<input type="hidden" name="selected_services" id="selected_services">
<input type="hidden" name="parameter_readings" id="parameter_readings">

</div>

<!-- =========================================
DIET DETAIL
========================================= -->
<div
id="dietTab"
class="discharge-tab-content">

<div class="card diet-guidelines-card">

<h3>
🍴 Diet Detail
</h3>

<div class="diet-guidelines-field">

<label for="diet_comments">
Comments / Remarks
</label>

<textarea
class="diet-guidelines-textarea"
name="diet_comments"
id="diet_comments"
maxlength="2000"
placeholder="Enter diet details, dietary advice, restrictions, preferences or other remarks..."
></textarea>

<div class="diet-guidelines-counter">
<span id="dietCommentsCounter">0</span> / 2000
</div>

</div>

</div>

</div>
<!-- =========================================
GUIDELINES
========================================= -->
<div
id="guidelinesTab"
class="discharge-tab-content">
<div class="card diet-guidelines-card">
<h3>
ⓘ Guidelines
</h3>
<div class="diet-guidelines-field">
<label for="guidelines_comments">
Comments / Remarks
</label>
<textarea
class="diet-guidelines-textarea"
name="guidelines_comments"
id="guidelines_comments"
maxlength="2000"
placeholder="Enter discharge guidelines, precautions, follow-up advice or other instructions..."
></textarea>
<div class="diet-guidelines-counter">
<span id="guidelinesCommentsCounter">0</span> / 2000
</div>
</div>
</div>
</div>
<!-- =========================================
DOCTORS LIST
========================================= -->
<div
    id="doctorsTab"
    class="discharge-tab-content">

    <div class="doctors-selection-wrap">

        <!-- =====================================
             AVAILABLE DOCTORS
             ===================================== -->

        <div class="doctors-selection-panel">

            <div class="doctors-selection-header">
                <div>
                    <i class="fas fa-user-md"></i>
                    Available Doctors
                </div>

                <span>
                    <?= count($doctorMasterList) ?> Doctor<?= count($doctorMasterList) == 1 ? '' : 's' ?>
                </span>
            </div>

            <div
                class="doctors-selection-body"
                id="availableDoctorsBody">

                <?php if (!empty($doctorMasterList)) { ?>

                    <?php foreach ($doctorMasterList as $doctor) { ?>

                        <div
                            class="doctor-selection-item available-doctor-item"
                            id="availableDoctor_<?= (int)$doctor['doctor_master_id'] ?>"
                            data-doctor-id="<?= (int)$doctor['doctor_master_id'] ?>"
                            data-doctor-name="<?= htmlspecialchars(
    ($doctor['doctor_name'] ?? '') .
    (!empty($doctor['department_name']) ? ' (' . $doctor['department_name'] . ')' : ''),
    ENT_QUOTES
) ?>"
data-license-no="<?= htmlspecialchars($doctor['license_no'] ?? '', ENT_QUOTES) ?>"
data-is-surgeon="<?= (int)$doctor['is_surgeon'] ?>">

                            <div class="doctor-selection-info">
    <div class="doctor-selection-name">
        <?= htmlspecialchars($doctor['doctor_name'] ?? '') ?>
        <?php if (!empty($doctor['department_name'])) { ?>
            (<?= htmlspecialchars($doctor['department_name']) ?>)
        <?php } ?>
        <?php if (!empty($doctor['license_no'])) { ?>
            &nbsp;•&nbsp; License: <?= htmlspecialchars($doctor['license_no']) ?>
        <?php } ?>
        <?php if ((int)$doctor['is_surgeon'] === 1) { ?>
            &nbsp;•&nbsp; Surgeon
        <?php } ?>
    </div>
</div>

                            <button
                                type="button"
                                class="doctor-add-btn"
                                title="Add Doctor"
                                onclick="addDischargeDoctor(<?= (int)$doctor['doctor_master_id'] ?>)">
                                +
                            </button>

                        </div>

                    <?php } ?>

                <?php } else { ?>

                    <div class="doctor-empty-state">
                        No doctors found in Doctor Master.
                    </div>

                <?php } ?>

            </div>

        </div>


        <!-- =====================================
             SELECTED DOCTORS
             ===================================== -->

        <div class="doctors-selection-panel">

            <div class="doctors-selection-header">
                <div>
                    <i class="fas fa-clipboard-list"></i>
                    Selected Doctors
                </div>

                <span id="selectedDoctorsCount">
                    0 Doctors
                </span>
            </div>

            <div
                class="doctors-selection-body"
                id="selectedDoctorsBody">

                <div
                    class="doctor-empty-state"
                    id="selectedDoctorsEmpty">
                    No doctors selected.
                </div>

            </div>

        </div>

    </div>


    <!-- Selected Doctor IDs will be submitted later -->
    <div id="selectedDoctorsInputs"></div>

</div>

<!-- =========================================
FOOTER
========================================= -->

<div class="discharge-footer">

<button
type="button"
class="discharge-btn discharge-btn-close"
onclick="window.parent.closeDischargePopup && window.parent.closeDischargePopup()">
✕ Close
</button>

<button
type="button"
class="discharge-btn discharge-btn-save"
onclick="window.parent.saveDischarge && window.parent.saveDischarge()">
▣ Save & Discharge
</button>
</div>
</form>
<script>
(function(){

function getFormRoot(){
    return document.querySelector('.discharge-form-wrap');
}
window.updateDischargeSummaryCounter = function(length) {
    const counter = document.getElementById('dischargeSummaryCounter');

    if (counter) {
        counter.textContent = length + ' / 1000';
    }
};
window.dischargeTypeChanged = function(type) {

    const box = document.getElementById('deathDetails');
    const fullName = document.getElementById('dischargeTypeFullName');
    const deathFields = document.getElementById('deathFields');
    const deathDate = document.getElementById('death_date');
    const deathTime = document.getElementById('death_time');

    /*
     * =========================================
     * DEATH DETAILS
     * =========================================
     */

    if (!box) return;

    const fullForms = {
        'Normal': 'Normal',
        'LAMA': 'Leave Against Medical Advice (LAMA)',
        'DAMA': 'Discharge Against Medical Advice (DAMA)',
        'MLC': 'Medico-Legal Case (MLC)',
        'Referred': 'Referred'
    };

    if (type === 'Death') {

        box.style.display = 'block';

        if (fullName) {
            fullName.textContent = '';
            fullName.style.display = 'none';
        }

        if (deathFields) {
            deathFields.style.display = 'block';
        }

        if (deathDate) {
            deathDate.required = true;
        }

        if (deathTime) {
            deathTime.required = true;
        }

    } else {

        box.style.display = 'block';

        if (fullName) {
            fullName.textContent = fullForms[type] || type;
            fullName.style.display = 'block';
        }

        if (deathFields) {
            deathFields.style.display = 'none';
        }

        if (deathDate) {
            deathDate.required = false;
            deathDate.value = '';
        }

        if (deathTime) {
            deathTime.required = false;
            deathTime.value = '';
        }
    }


    /*
     * =========================================
     * HIDE / SHOW TABS
     *
     * Only Death:
     * Instructions
     * Investigations
     * Diet Detail
     * Guidelines
     *
     * are hidden.
     * =========================================
     */

    const restrictedTabs = [
        'instructionsTab',
        'investigationsTab',
        'dietTab',
        'guidelinesTab'
    ];

    const isDeath = (type === 'Death');

    restrictedTabs.forEach(function(tabId) {

        const tabButton = document.querySelector(
            '.discharge-tab-button[data-tab="' + tabId + '"]'
        );

        const tabContent = document.getElementById(tabId);

        if (tabButton) {
            tabButton.style.display = isDeath ? 'none' : '';
        }

        if (tabContent) {
            tabContent.style.display = isDeath ? 'none' : '';
        }
    });


    /*
     * =========================================
     * IF DEATH IS SELECTED WHILE ONE OF THE
     * HIDDEN TABS IS OPEN, AUTOMATICALLY MOVE
     * TO PATIENT DETAILS.
     * =========================================
     */

    if (isDeath) {

        const activeHiddenTab = restrictedTabs.some(function(tabId) {

            const tab = document.getElementById(tabId);

            return tab && tab.classList.contains('active');

        });

        if (activeHiddenTab) {

            const patientTab =
                document.getElementById('patientDetailsTab');

            const patientButton =
                document.querySelector(
                    '.discharge-tab-button[data-tab="patientDetailsTab"]'
                );

            document
                .querySelectorAll('.discharge-tab-content')
                .forEach(function(tab) {
                    tab.classList.remove('active');
                });

            document
                .querySelectorAll('.discharge-tab-button')
                .forEach(function(btn) {
                    btn.classList.remove('active');
                });

            if (patientTab) {
                patientTab.classList.add('active');
            }

            if (patientButton) {
                patientButton.classList.add('active');
            }
        }
    }
};
const selectedDischargeType = document.querySelector('input[name="discharge_type"]:checked');
if (selectedDischargeType) {
    window.dischargeTypeChanged(selectedDischargeType.value);
}
window.updateDischargeCommentCounter=function(length){

    const counter=document.getElementById('dischargeCommentCounter');

    if(counter){
        counter.textContent=length+' / 1000';
    }
};
window.openDischargeTab=function(tabId,button){
    document.querySelectorAll('.discharge-tab-content').forEach(function(tab){
        tab.classList.remove('active');
    });
    document.querySelectorAll('.discharge-tab-button').forEach(function(btn){
        btn.classList.remove('active');
    });
    const target=document.getElementById(tabId);
    if(target){
        target.classList.add('active');
    }
    if(button){
        button.classList.add('active');
    }
};
/* =========================================
   CALCULATE LENGTH OF STAY
   ========================================= */

const dischargeDateField = document.getElementById('discharge_date');

if (dischargeDateField) {

    dischargeDateField.addEventListener('change', function () {

        const admissionDate = new Date('<?= $ipd['admission_date'] ?>T00:00:00');
        const dischargeDate = new Date(this.value + 'T00:00:00');

        if (!this.value || isNaN(admissionDate.getTime()) || isNaN(dischargeDate.getTime())) {
            return;
        }

        const diffTime = dischargeDate - admissionDate;

        let days = Math.floor(diffTime / (1000 * 60 * 60 * 24));

        if (days < 1) {
            days = 1;
        }

        const lengthOfStay = document.getElementById('length_of_stay');

        if (lengthOfStay) {
            lengthOfStay.textContent =
                days + ' Day' + (days === 1 ? '' : 's');
        }
    });

}

})();
/* =========================================
   DISCHARGE DOCTOR SELECTION
   ========================================= */

const selectedDischargeDoctors = new Map();


window.addDischargeDoctor = function(doctorId) {

    doctorId = String(doctorId);

    if (!doctorId) {
        return;
    }

    /* Prevent duplicate doctor */
    if (selectedDischargeDoctors.has(doctorId)) {
        return;
    }

    const availableDoctor =
        document.getElementById('availableDoctor_' + doctorId);

    if (!availableDoctor) {
        return;
    }

    const doctorName =
        availableDoctor.getAttribute('data-doctor-name') || '';

   const licenseNo =
    availableDoctor.getAttribute('data-license-no') || '';

const isSurgeon =
    availableDoctor.getAttribute('data-is-surgeon') === '1';

selectedDischargeDoctors.set(
    doctorId,
    {
        id: doctorId,
        name: doctorName,
        licenseNo: licenseNo,
        isSurgeon: isSurgeon
    }
);
    renderSelectedDischargeDoctors();
};


window.removeDischargeDoctor = function(doctorId) {

    doctorId = String(doctorId);

    selectedDischargeDoctors.delete(doctorId);

    renderSelectedDischargeDoctors();
};


window.renderSelectedDischargeDoctors = function() {

    const selectedBody =
        document.getElementById('selectedDoctorsBody');

    const selectedInputs =
        document.getElementById('selectedDoctorsInputs');

    const selectedCount =
        document.getElementById('selectedDoctorsCount');

    const selectedEmpty =
        document.getElementById('selectedDoctorsEmpty');

    if (!selectedBody) {
        return;
    }

    /*
     * Clear selected list.
     * Keep the empty-state element out while rebuilding.
     */
    selectedBody.innerHTML = '';

    if (selectedDischargeDoctors.size === 0) {

        selectedBody.innerHTML =
            '<div class="doctor-empty-state" id="selectedDoctorsEmpty">' +
            'No doctors selected.' +
            '</div>';

    } else {

        selectedDischargeDoctors.forEach(function(doctor) {

            const item =
                document.createElement('div');

            item.className =
                'doctor-selection-item selected-doctor-item';

            item.setAttribute(
                'data-doctor-id',
                doctor.id
            );

            item.innerHTML =
                '<div class="doctor-selection-info">' +

                    '<div class="doctor-selection-name">' +
    escapeDischargeDoctorHtml(doctor.name) +
    (doctor.licenseNo
        ? ' &nbsp;•&nbsp; License: ' + escapeDischargeDoctorHtml(doctor.licenseNo)
        : '') +
    (doctor.isSurgeon
        ? ' &nbsp;•&nbsp; Surgeon'
        : '') +
'</div>' +

                '</div>' +

                '<button ' +
                    'type="button" ' +
                    'class="doctor-remove-btn" ' +
                    'title="Remove Doctor" ' +
                    'onclick="removeDischargeDoctor(' +
                        doctor.id +
                    ')">' +
                    '×' +
                '</button>';

            selectedBody.appendChild(item);

        });

    }


    /*
     * Update count
     */
    if (selectedCount) {

        const count =
            selectedDischargeDoctors.size;

        selectedCount.textContent =
            count +
            ' Doctor' +
            (count === 1 ? '' : 's');
    }


    /*
     * Create hidden inputs.
     *
     * These will later be picked up by Save & Discharge.
     */
    if (selectedInputs) {

        selectedInputs.innerHTML = '';

        selectedDischargeDoctors.forEach(function(doctor) {

            const input =
                document.createElement('input');

            input.type = 'hidden';

            input.name =
                'selected_doctor_ids[]';

            input.value =
                doctor.id;

            selectedInputs.appendChild(input);

        });
    }


    /*
     * Hide selected doctors from Available Doctors.
     */
    document
        .querySelectorAll('.available-doctor-item')
        .forEach(function(item) {

            const id =
                String(
                    item.getAttribute('data-doctor-id')
                );

            const addButton =
                item.querySelector('.doctor-add-btn');

            if (selectedDischargeDoctors.has(id)) {

                item.style.display = 'none';

                if (addButton) {
                    addButton.disabled = true;
                }

            } else {

                item.style.display = '';

                if (addButton) {
                    addButton.disabled = false;
                }
            }

        });

};


function escapeDischargeDoctorHtml(value) {

    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
/* =========================================
   DISCHARGE DISEASE SELECTION
   ========================================= */

const selectedDischargeDiseases = new Map();

window.addDischargeDisease = function(diseaseId){

    diseaseId = String(diseaseId);

    if(!diseaseId){
        return;
    }

    if(selectedDischargeDiseases.has(diseaseId)){
        return;
    }

    const availableDisease =
        document.getElementById('availableDisease_' + diseaseId);

    if(!availableDisease){
        return;
    }

    const diseaseName =
        availableDisease.getAttribute('data-disease-name') || '';

    const icdCode =
        availableDisease.getAttribute('data-icd-code') || '';

    selectedDischargeDiseases.set(
        diseaseId,
        {
            id:diseaseId,
            name:diseaseName,
            icdCode:icdCode
        }
    );

    renderSelectedDischargeDiseases();
};

window.removeDischargeDisease = function(diseaseId){

    diseaseId = String(diseaseId);

    selectedDischargeDiseases.delete(diseaseId);

    renderSelectedDischargeDiseases();
};

window.renderSelectedDischargeDiseases = function(){

    const selectedBody =
        document.getElementById('selectedDiseasesBody');

    const selectedInputs =
        document.getElementById('selectedDiseasesInputs');

    const selectedCount =
        document.getElementById('selectedDiseasesCount');

    if(!selectedBody){
        return;
    }

    selectedBody.innerHTML = '';

    if(selectedDischargeDiseases.size === 0){

        selectedBody.innerHTML =
            '<div class="disease-empty-state" id="selectedDiseasesEmpty">' +
            'No diseases selected.' +
            '</div>';

    }else{

        selectedDischargeDiseases.forEach(function(disease){

            const item =
                document.createElement('div');

            item.className =
                'disease-selection-item selected-disease-item';

            item.setAttribute(
                'data-disease-id',
                disease.id
            );

            item.innerHTML =
                '<div class="disease-selection-info">' +

                    '<div class="disease-selection-name">' +
                        escapeDischargeDiseaseHtml(disease.name) +

                        (disease.icdCode
                            ? ' &nbsp;•&nbsp; ' +
                              escapeDischargeDiseaseHtml(disease.icdCode)
                            : '') +

                    '</div>' +

                '</div>' +

                '<button ' +
                    'type="button" ' +
                    'class="disease-remove-btn" ' +
                    'title="Remove Disease" ' +
                    'onclick="removeDischargeDisease(' +
                        disease.id +
                    ')">' +
                    '×' +
                '</button>';

            selectedBody.appendChild(item);

        });

    }

    if(selectedCount){

        const count =
            selectedDischargeDiseases.size;

        selectedCount.textContent =
            count +
            ' Disease' +
            (count === 1 ? '' : 's');
    }

    if(selectedInputs){

        selectedInputs.innerHTML = '';

        selectedDischargeDiseases.forEach(function(disease){

            const input =
                document.createElement('input');

            input.type = 'hidden';

            input.name =
                'selected_disease_ids[]';

            input.value =
                disease.id;

            selectedInputs.appendChild(input);

        });
    }

    document
        .querySelectorAll('.available-disease-item')
        .forEach(function(item){

            const id =
                String(
                    item.getAttribute('data-disease-id')
                );

            const addButton =
                item.querySelector('.disease-add-btn');

            if(selectedDischargeDiseases.has(id)){

                item.style.display = 'none';

                if(addButton){
                    addButton.disabled = true;
                }

            }else{

                item.style.display = '';

                if(addButton){
                    addButton.disabled = false;
                }

            }

        });

};

function escapeDischargeDiseaseHtml(value){

    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');

}
/* =========================================
   DISCHARGE MEDICINE SELECTION
========================================= */

const selectedDischargeMedicines = new Map();

window.updateDischargeMedicines = function(){

    const hiddenInput =
        document.getElementById('discharge_selected_medicines');

    if(!hiddenInput){
        return;
    }

    const medicines=[];

    selectedDischargeMedicines.forEach(function(medicine){

        const row=document.querySelector(
            '.selected-medicine-row[data-item-id="' +
            medicine.item_id +
            '"]'
        );

        if(!row){
            return;
        }

        medicines.push({
            item_id:medicine.item_id,
            medicine:medicine.medicine,
            frequency:row.querySelector('.discharge-medicine-frequency')?.value || '',
            meal:row.querySelector('.discharge-medicine-meal')?.value || '',
            qty:row.querySelector('.discharge-medicine-qty')?.value || '1',
            m:row.querySelector('.discharge-medicine-m')?.value || '0',
            n:row.querySelector('.discharge-medicine-n')?.value || '0',
            e:row.querySelector('.discharge-medicine-e')?.value || '0',
            doctor:row.querySelector('.discharge-medicine-doctor')?.value || '',
            stop:row.querySelector('.discharge-medicine-stop')?.checked ? 1 : 0,
            dated:row.querySelector('.discharge-medicine-date')?.value || '',
            comments:row.querySelector('.discharge-medicine-comments')?.value || ''
        });

    });

    hiddenInput.value=JSON.stringify(medicines);
};


function createDischargeMedicineRow(itemId,medicineName){

    const tbody=
        document.getElementById('selectedDischargeMedicineBody');

    if(!tbody){
        return;
    }

    const row=document.createElement('tr');

    row.className='selected-medicine-row';

    row.setAttribute(
        'data-item-id',
        itemId
    );

    row.innerHTML=
        '<td>' +
            '<div class="selected-medicine-name">' +
                escapeDischargeMedicineHtml(medicineName) +
            '</div>' +
        '</td>' +

        '<td>' +
            '<select class="discharge-medicine-frequency">' +
                '<option value="">Select</option>' +
                '<option value="OD">OD</option>' +
                '<option value="BD">BD</option>' +
                '<option value="TDS">TDS</option>' +
                '<option value="QID">QID</option>' +
                '<option value="HS">HS</option>' +
                '<option value="TFS">TFS</option>' +
                '<option value="E/S">E/S</option>' +
                '<option value="SOS">SOS</option>' +
            '</select>' +
        '</td>' +

        '<td>' +
            '<select class="discharge-medicine-meal">' +
                '<option value="">Select</option>' +
                '<option value="After Meal">After Meal</option>' +
                '<option value="Before Meal">Before Meal</option>' +
                '<option value="After Breakfast">After Breakfast</option>' +
                '<option value="Before Breakfast">Before Breakfast</option>' +
                '<option value="After Lunch">After Lunch</option>' +
                '<option value="Before Lunch">Before Lunch</option>' +
                '<option value="After Dinner">After Dinner</option>' +
                '<option value="Before Dinner">Before Dinner</option>' +
            '</select>' +
        '</td>' +

        '<td>' +
            '<input type="number" class="discharge-medicine-qty" min="0" step="1" value="1">' +
        '</td>' +

        '<td>' +
            '<input type="number" class="discharge-medicine-m" min="0" step="0.01" value="0">' +
        '</td>' +

        '<td>' +
            '<input type="number" class="discharge-medicine-n" min="0" step="0.01" value="0">' +
        '</td>' +

        '<td>' +
            '<input type="number" class="discharge-medicine-e" min="0" step="0.01" value="0">' +
        '</td>' +

        '<td>' +
            '<select class="discharge-medicine-doctor">' +
                '<option value="">Select Doctor</option>' +

                <?php
                if($medicineDoctorResult){
                    while($doctor=mysqli_fetch_assoc($medicineDoctorResult)){
                ?>
                    '<option value="<?= (int)$doctor['id'] ?>">' +
                        '<?= e($doctor['full_name']) ?>' +
                    '</option>' +
                <?php
                    }
                }
                ?>

            '</select>' +
        '</td>' +

        '<td style="text-align:center;">' +
            '<input type="checkbox" class="selected-medicine-stop discharge-medicine-stop">' +
        '</td>' +

        '<td>' +
            '<input type="date" class="discharge-medicine-date">' +
        '</td>' +

        '<td>' +
            '<input type="text" class="discharge-medicine-comments" placeholder="Comments">' +
        '</td>' +

        '<td style="text-align:center;">' +
            '<button ' +
                'type="button" ' +
                'class="selected-medicine-delete" ' +
                'title="Remove Medicine" ' +
                'onclick="removeDischargeMedicine(\'' +
                    itemId +
                '\')">' +
                '×' +
            '</button>' +
        '</td>';

    tbody.appendChild(row);

    const frequency=
        row.querySelector('.discharge-medicine-frequency');

    if(frequency){

        frequency.addEventListener(
            'change',
            function(){

                setDischargeMedicineFrequency(
                    row,
                    this.value
                );

                window.updateDischargeMedicines();
            }
        );

    }

    row.querySelectorAll('input,select').forEach(function(field){

        field.addEventListener(
            'change',
            window.updateDischargeMedicines
        );

        field.addEventListener(
            'input',
            window.updateDischargeMedicines
        );

    });
}

window.addDischargeMedicine=function(itemId){

    itemId=String(itemId);

    if(!itemId){
        return;
    }

    if(selectedDischargeMedicines.has(itemId)){
        return;
    }

    const checkbox=document.querySelector(
        '.discharge-medicine-checkbox[value="' +
        itemId +
        '"]'
    );

    if(!checkbox){
        return;
    }

    const medicineName=
        checkbox.getAttribute('data-name') || '';

    selectedDischargeMedicines.set(
        itemId,
        {
            item_id:itemId,
            medicine:medicineName
        }
    );

    createDischargeMedicineRow(
        itemId,
        medicineName
    );

    checkbox.checked=true;

    const tableWrap=
        document.getElementById(
            'selectedDischargeMedicineTableWrap'
        );

    if(tableWrap){
        tableWrap.style.display='block';
    }

    window.updateDischargeMedicines();
};
window.removeDischargeMedicine=function(itemId){

    itemId=String(itemId);

    selectedDischargeMedicines.delete(itemId);

    const row=document.querySelector(
        '.selected-medicine-row[data-item-id="' +
        itemId +
        '"]'
    );

    if(row){
        row.remove();
    }

    const checkbox=document.querySelector(
        '.discharge-medicine-checkbox[value="' +
        itemId +
        '"]'
    );

    if(checkbox){
        checkbox.checked=false;
    }

    const tableWrap=
        document.getElementById(
            'selectedDischargeMedicineTableWrap'
        );

    if(tableWrap){

        if(selectedDischargeMedicines.size===0){
            tableWrap.style.display='none';
        }else{
            tableWrap.style.display='block';
        }

    }

    window.updateDischargeMedicines();
};
function setDischargeMedicineFrequency(row,frequency){

    const values={
        'OD':[1,0,0],
        'BD':[1,0,1],
        'TDS':[1,1,1],
        'QID':[1,1,1],
        'HS':[0,0,1],
        'TFS':[0,0,1],
        'E/S':[0,0,1],
        'SOS':[0,0,1]
    };

    const value=values[frequency] || [0,0,0];

    const morning=row.querySelector(
        '.discharge-medicine-m'
    );

    const noon=row.querySelector(
        '.discharge-medicine-n'
    );

    const evening=row.querySelector(
        '.discharge-medicine-e'
    );

    if(morning){
        morning.value=value[0];
    }

    if(noon){
        noon.value=value[1];
    }

    if(evening){
        evening.value=value[2];
    }
}


function filterDischargeMedicines(){

    const search=
        (document.getElementById(
            'dischargeMedicineSearch'
        )?.value || '').toLowerCase().trim();

    const category=
        document.getElementById(
            'dischargeCategoryItemFilter'
        )?.value || '';

    const subCategory=
        document.getElementById(
            'dischargeSubCategoryItemFilter'
        )?.value || '';

    const store=
        document.getElementById(
            'dischargeStoreFilter'
        )?.value || '';

    const manufacturer=
        document.getElementById(
            'dischargeManufacturerFilter'
        )?.value || '';

    document
        .querySelectorAll('.discharge-medicine-row')
        .forEach(function(row){

            const name=
                (row.dataset.name || '').toLowerCase();

            const rowCategory=
                row.dataset.category || '';

            const rowSubCategory=
                row.dataset.subcategory || '';

            const rowManufacturer=
                row.dataset.manufacturer || '';

            const rowStores=
                (row.dataset.store || '')
                .split(',');

            const matchesSearch=
                !search ||
                name.includes(search);

            const matchesCategory=
                !category ||
                rowCategory===category;

            const matchesSubCategory=
                !subCategory ||
                rowSubCategory===subCategory;

            const matchesManufacturer=
                !manufacturer ||
                rowManufacturer===manufacturer;

            const matchesStore=
                !store ||
                rowStores.includes(store);

            row.style.display=
                matchesSearch &&
                matchesCategory &&
                matchesSubCategory &&
                matchesManufacturer &&
                matchesStore
                    ? 'flex'
                    : 'none';

        });
}


function updateDischargeSubCategories(){

    const category=
        document.getElementById(
            'dischargeCategoryItemFilter'
        )?.value || '';

    const select=
        document.getElementById(
            'dischargeSubCategoryItemFilter'
        );

    if(!select){
        return;
    }

    Array.from(select.options).forEach(function(option,index){

        if(index===0){
            option.hidden=false;
            return;
        }

        const parent=
            option.getAttribute('data-parent') || '';

        option.hidden=
            !!category &&
            parent!==category;

    });

    if(
        select.selectedOptions.length &&
        select.selectedOptions[0].hidden
    ){
        select.value='';
    }
}


document
    .getElementById('dischargeMedicineSearch')
    ?.addEventListener(
        'input',
        filterDischargeMedicines
    );


document
    .getElementById('dischargeCategoryItemFilter')
    ?.addEventListener(
        'change',
        function(){

            updateDischargeSubCategories();

            filterDischargeMedicines();

        }
    );


document
    .getElementById('dischargeSubCategoryItemFilter')
    ?.addEventListener(
        'change',
        filterDischargeMedicines
    );


document
    .getElementById('dischargeStoreFilter')
    ?.addEventListener(
        'change',
        filterDischargeMedicines
    );


document
    .getElementById('dischargeManufacturerFilter')
    ?.addEventListener(
        'change',
        filterDischargeMedicines
    );


document
    .querySelectorAll('.discharge-medicine-checkbox')
    .forEach(function(checkbox){

        checkbox.addEventListener(
            'change',
            function(){

                const itemId=
                    String(this.value);

                if(this.checked){

                    window.addDischargeMedicine(
                        itemId
                    );

                }else{

                    window.removeDischargeMedicine(
                        itemId
                    );

                }

            }
        );

    });


document
    .getElementById('dischargeMedicineSearch')
    ?.addEventListener(
        'keydown',
        function(event){

            if(event.key!=='Enter'){
                return;
            }

            event.preventDefault();

            const visible=
                Array.from(
                    document.querySelectorAll(
                        '.discharge-medicine-row'
                    )
                )
                .find(function(row){

                    return row.style.display!=='none';

                });

            if(visible){

                const checkbox=
                    visible.querySelector(
                        '.discharge-medicine-checkbox'
                    );

                if(checkbox && !checkbox.checked){

                    checkbox.checked=true;

                    checkbox.dispatchEvent(
                        new Event('change')
                    );

                }

            }

            this.value='';

            filterDischargeMedicines();

        }
    );


function escapeDischargeMedicineHtml(value){

    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');

}
function calculateFollowUpDateFromDays(){

    const dischargeDate=
        document.getElementById('discharge_date');

    const followUpDays=
        document.getElementById('follow_up_days');

    const followUpDate=
        document.getElementById('follow_up_date');

    if(!dischargeDate || !followUpDays || !followUpDate){
        return;
    }

    const baseDate=dischargeDate.value;
    const days=parseInt(followUpDays.value,10);

    if(!baseDate || isNaN(days) || days<0){
        return;
    }

    const date=new Date(baseDate+'T00:00:00');

    date.setDate(
        date.getDate()+days
    );

    const year=date.getFullYear();
    const month=String(date.getMonth()+1).padStart(2,'0');
    const day=String(date.getDate()).padStart(2,'0');

    followUpDate.value=
        year+'-'+month+'-'+day;
}


function calculateFollowUpDaysFromDate(){

    const dischargeDate=
        document.getElementById('discharge_date');

    const followUpDate=
        document.getElementById('follow_up_date');

    const followUpDays=
        document.getElementById('follow_up_days');

    if(!dischargeDate || !followUpDate || !followUpDays){
        return;
    }

    if(!dischargeDate.value || !followUpDate.value){
        return;
    }

    const discharge=
        new Date(dischargeDate.value+'T00:00:00');

    const followUp=
        new Date(followUpDate.value+'T00:00:00');

    const difference=
        followUp.getTime()-discharge.getTime();

    const days=
        Math.round(
            difference/(1000*60*60*24)
        );

    followUpDays.value=
        days>=0 ? days : '';
}
const dischargeDate=document.getElementById('discharge_date');
const followUpDays=document.getElementById('follow_up_days');
const followUpDate=document.getElementById('follow_up_date');

if(dischargeDate){
dischargeDate.addEventListener('change',function(){
if(followUpDays&&followUpDays.value!==''){
calculateFollowUpDateFromDays();
}else if(followUpDate&&followUpDate.value!==''){
calculateFollowUpDaysFromDate();
}
});
}

if(followUpDays){
followUpDays.addEventListener('input',calculateFollowUpDateFromDays);
}

if(followUpDate){
followUpDate.addEventListener('change',calculateFollowUpDaysFromDate);
}

if(dischargeDate&&followUpDays&&followUpDays.value!==''){
calculateFollowUpDateFromDays();
}

if(dischargeDate&&followUpDate&&followUpDate.value!==''){
calculateFollowUpDaysFromDate();
}
function calculateDischargeBMI(){
const weightInput=document.getElementById('weight');
const heightInput=document.getElementById('height');
const bmiInput=document.getElementById('bmi');
if(!weightInput||!heightInput||!bmiInput){
return;
}
const weight=parseFloat(weightInput.value)||0;
const height=parseFloat(heightInput.value)||0;
if(weight>0&&height>0){
const bmi=weight/Math.pow(height/100,2);
bmiInput.value=bmi.toFixed(2);
}else{
bmiInput.value='';
}
}
const dischargeWeightInput=document.getElementById('weight');
const dischargeHeightInput=document.getElementById('height');
if(dischargeWeightInput){
dischargeWeightInput.addEventListener('input',calculateDischargeBMI);
}
if(dischargeHeightInput){
dischargeHeightInput.addEventListener('input',calculateDischargeBMI);
}
let dischargeParameterChart=null;

document.addEventListener("click",function(e){

const btn=e.target.closest(".parameter-chart-btn");

if(!btn){
return;
}

const parameterId=btn.dataset.parameterId;
const parameterName=btn.dataset.parameterName;

const modal=document.getElementById("parameterHistoryModal");
const chartCanvas=document.getElementById("parameterChart");

if(!modal||!chartCanvas){
return;
}

const title=document.querySelector("#parameterHistoryModal .modal-title");

if(title){
title.innerHTML="📈 "+parameterName+" Trend";
}

const latest=document.getElementById("latestReading");
const highest=document.getElementById("highestReading");
const lowest=document.getElementById("lowestReading");
const average=document.getElementById("averageReading");
const history=document.getElementById("parameterHistoryContent");

if(latest) latest.innerHTML="--";
if(highest) highest.innerHTML="--";
if(lowest) lowest.innerHTML="--";
if(average) average.innerHTML="--";

if(history){
history.innerHTML=`
<table class="table table-bordered table-striped">
<thead>
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
}

if(dischargeParameterChart){
dischargeParameterChart.destroy();
}

dischargeParameterChart=new Chart(chartCanvas,{
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

new bootstrap.Modal(modal).show();

fetch(
"/modules/opd/ajax/get_parameter_chart.php?appointment_id=<?=$appointment_id?>&parameter_id="+parameterId
)
.then(response=>response.json())
.then(data=>{

if(latest) latest.innerHTML=data.latest ?? "--";
if(highest) highest.innerHTML=data.highest ?? "--";
if(lowest) lowest.innerHTML=data.lowest ?? "--";
if(average) average.innerHTML=data.average ?? "--";

if(data.chart&&Array.isArray(data.chart)){

data.chart.forEach(function(item){

dischargeParameterChart.data.labels.push(item.label);

dischargeParameterChart.data.datasets[0].data.push(item.value);

});

dischargeParameterChart.update();

}

if(history){

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

if(!data.history||data.history.length===0){

html+=`
<tr>
<td colspan="4" class="text-center">
No Reading Available
</td>
</tr>
`;

}else{

data.history.forEach(function(item){

html+=`
<tr>
<td>${item.reading ?? ""}</td>
<td>${item.date ?? ""}</td>
<td>${item.time ?? ""}</td>
<td>${item.by ?? ""}</td>
</tr>
`;

});

}

html+=`
</tbody>
</table>
`;

history.innerHTML=html;

}

})
.catch(function(){

if(history){

history.innerHTML=`
<div style="padding:20px;text-align:center;color:#dc2626;">
Unable to load parameter history.
</div>
`;

}

});

});
/* =========================================
   DISCHARGE SERVICES
========================================= */

function filterDischargeServices(){

    const search=
        (document.getElementById('serviceSearch')?.value || '')
        .toLowerCase()
        .trim();

    const category=
        document.getElementById('categoryFilter')?.value || '';

    const mainService=
        document.getElementById('mainServiceFilter')?.value || '';

    const service=
        document.getElementById('serviceFilter')?.value || '';

    document
        .querySelectorAll('#investigationsTab .service-row')
        .forEach(function(row){

            const name=
                (row.dataset.name || '').toLowerCase();

            const rowCategory=
                row.dataset.category || '';

            const rowMain=
                row.dataset.main || '';

            const rowId=
                row.dataset.id || '';

            const matchesSearch=
                !search ||
                name.includes(search);

            const matchesCategory=
                !category ||
                rowCategory===category;

            const matchesMain=
                !mainService ||
                rowMain===mainService;

            const matchesService=
                !service ||
                rowId===service;

            row.style.display=
                matchesSearch &&
                matchesCategory &&
                matchesMain &&
                matchesService
                    ? ''
                    : 'none';
        });
}


/* =========================================
   SELECT / REMOVE SERVICES
========================================= */

function updateDischargeSelectedServices(){

    const selectedBox=
        document.getElementById('selectedServices');

    const hiddenInput=
        document.getElementById('selected_services');

    if(!selectedBox){
        return;
    }

    selectedBox.innerHTML='';

    const selected=[];

    document
        .querySelectorAll(
            '#investigationsTab .service-checkbox:checked'
        )
        .forEach(function(checkbox){

            const id=
                checkbox.value;

            const name=
                checkbox.dataset.name || '';

            const mode=
                checkbox.dataset.mode || '';

            const code=
                checkbox.dataset.code || '';

            const price=
                checkbox.dataset.price || '';

            selected.push(id);

            const item=
                document.createElement('div');

            item.style.cssText=
                'display:flex;' +
                'align-items:center;' +
                'justify-content:space-between;' +
                'gap:10px;' +
                'padding:10px;' +
                'margin-bottom:8px;' +
                'border:1px solid #ddd;' +
                'border-radius:6px;' +
                'background:#f8fafc;';

            item.innerHTML=
                '<div style="flex:1;">' +

                    '<div style="font-weight:600;">' +
                        escapeDischargeServiceHtml(name) +
                    '</div>' +

                    '<div style="font-size:12px;color:#6c757d;margin-top:3px;">' +
                        escapeDischargeServiceHtml(mode) +
                        ' &nbsp;|&nbsp; ' +
                        escapeDischargeServiceHtml(code) +
                        ' &nbsp;|&nbsp; ₹' +
                        escapeDischargeServiceHtml(price) +
                    '</div>' +

                '</div>' +

                '<button ' +
                    'type="button" ' +
                    'title="Remove Service" ' +
                    'style="' +
                        'border:none;' +
                        'background:#fee2e2;' +
                        'color:#dc2626;' +
                        'border-radius:4px;' +
                        'width:28px;' +
                        'height:28px;' +
                        'cursor:pointer;' +
                        'font-size:18px;' +
                        'line-height:1;' +
                    '"' +
                    ' onclick="removeDischargeService(\'' +
                        id +
                    '\')">' +
                    '×' +
                '</button>';

            selectedBox.appendChild(item);
        });

    if(selected.length===0){

        selectedBox.innerHTML=
            '<div style="' +
                'padding:30px 10px;' +
                'text-align:center;' +
                'color:#888;' +
            '">' +
            'No services selected.' +
            '</div>';
    }

    if(hiddenInput){
        hiddenInput.value=
            selected.join(',');
    }
}


function removeDischargeService(serviceId){

    const checkbox=
        document.querySelector(
            '#investigationsTab .service-checkbox[value="' +
            serviceId +
            '"]'
        );

    if(checkbox){
        checkbox.checked=false;
    }

    updateDischargeSelectedServices();
}


/* =========================================
   SERVICE EVENTS
========================================= */
const serviceSearch=document.getElementById('serviceSearch');
const categoryFilter=document.getElementById('categoryFilter');
const mainServiceFilter=document.getElementById('mainServiceFilter');
const serviceFilter=document.getElementById('serviceFilter');

if(serviceSearch){
serviceSearch.addEventListener('input',filterDischargeServices);
}

if(categoryFilter){
categoryFilter.addEventListener('change',filterDischargeServices);
}

if(mainServiceFilter){
mainServiceFilter.addEventListener('change',filterDischargeServices);
}

if(serviceFilter){
serviceFilter.addEventListener('change',filterDischargeServices);
}

document.querySelectorAll('#investigationsTab .service-checkbox').forEach(function(checkbox){
checkbox.addEventListener('change',function(){
updateDischargeSelectedServices();
});
});
function escapeDischargeServiceHtml(value){
    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}
document.addEventListener('DOMContentLoaded',function(){
const clinicalCounters=[
    ['condition_at_discharge','conditionAtDischargeCounter'],
    ['co','coCounter'],
    ['ho','hoCounter']
];
clinicalCounters.forEach(function(item){
    const textarea=document.getElementById(item[0]);
    const counter=document.getElementById(item[1]);
    if(!textarea || !counter){
        return;
    }
    textarea.addEventListener('input',function(){
        counter.textContent=this.value.length;
    });
});
});
</script>