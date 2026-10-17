<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$type = $_GET['type'] ?? '';
$entity_id =
    $_GET['doctor_id'] ??
    $_GET['service_id'] ??
    $_GET['entity_id'] ??
    '';
$department_id = (int)($_GET['department_id'] ?? 0);
$date =
    $_GET['appointment_date'] ??
    $_GET['date'] ??
    '';
$slot =
    $_GET['appointment_time'] ??
    $_GET['slot'] ??
    '';
$isFromSlot = (
    !empty($type) &&
    !empty($entity_id) &&
    !empty($date) &&
    !empty($slot)
);
$isPreset = (
    !empty($type) &&
    !empty($entity_id) &&
    $department_id > 0
);
?>
<?php
session_start();
$source = strtoupper(trim($_GET['source'] ?? ''));
if ($source !== 'WEBSITE') {
    $source = (
        isset($_SESSION['user_id']) ||
        isset($_GET['type']) ||
        isset($_GET['entity_id'])
    ) ? 'INTERNAL' : 'WEBSITE';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (
    empty($_SESSION['tenant_id']) &&
    !empty($_GET['tenant_id'])
) {
    $_SESSION['tenant_id'] = trim((string)$_GET['tenant_id']);
}
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$departmentName = '';
$entityName     = '';
$feeAmount      = '';
$cities = [];
$cityQ = mysqli_query($conn, "SELECT id, name FROM cities ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($cityQ)) {
    $cities[] = $row;
}
/* =========================
   DOCTOR FROM SLOT
========================= */
if (($isFromSlot || $isPreset) && $type === 'doctor' && $entity_id) {
    $stmt = $conn->prepare("
        SELECT
            u.full_name,
            ud.department_id,
            dm.department_name
        FROM users u
        INNER JOIN user_departments ud
            ON ud.user_id = u.id
        INNER JOIN department_master dm
            ON dm.id = ud.department_id
        WHERE u.id = ?
        ORDER BY ud.department_id
        LIMIT 1
    ");
    $stmt->bind_param("i", $entity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $entityName = $row['full_name'];
        $department_id =
            (int)$row['department_id'];
        $departmentName =
            $row['department_name'];
    }
    $stmt->close();
    /* =========================
   DOCTOR FEE
   USER ID → DOCTOR MASTER ID
========================= */
$stmt = $conn->prepare("
    SELECT
        dc.charge_amount
    FROM doctor_master dm
    INNER JOIN doctor_charges dc
        ON dc.doctor_id = dm.id
    WHERE dm.user_id = ?
      AND dc.visit_type = 'NORMAL'
      AND dc.is_active = 1
    LIMIT 1
");
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $feeAmount = $row['charge_amount'];
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
        $department_id = 0;                  // no department for services
        $feeAmount       = $row['amount'];
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Add Appointment</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --accent:#06b6d4;
    --success:#16a34a;
    --danger:#dc2626;
    --border:#dbe3ef;
    --bg:#f4f7fb;
    --surface:#ffffff;
    --surface-soft:#f8fafc;
    --text:#172033;
    --muted:#64748b;
    --shadow:0 10px 30px rgba(15,23,42,.08);
}
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
.container {
    max-width: 1500px;
    margin: 0 auto;
    padding: 5px;
}
.header{
    position:sticky;
    top:0;
    z-index:50;
    display:flex;
    align-items:center;
    justify-content:space-between;
    min-height:50px;
    padding:0 24px;
    margin:0 0 5px;
    border-radius:0;
    background:#1d4ed8;
    color:#fff;
    box-shadow:0 5px 18px rgba(15,23,42,.18);
}
.header h1{
    margin:0;
    font-size:20px;
    font-weight:700;
    letter-spacing:.1px;
}
.header p{
    margin:3px 0 0;
    font-size:12px;
    opacity:.9;
}
.card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, .08);
    margin-bottom: 14px;
    overflow: hidden;
    position: relative;
}
.card h3 {
background: #1976d2;
    color: #fff;
    margin: 0;
    padding: 5px 15px;
    font-size: 15px;
    font-weight: bold;
    display: flex;
    align-items: center;
    gap: 7px;
}
.card h3 span{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:28px;
    height:28px;
    border-radius:8px;
    background:#eff6ff;
    font-size:15px;
}
.card>.grid,
.card>.revisit-box,
.card>.actions,
.card>div:not(h3){
    margin-left:17px;
    margin-right:17px;
}
/* =========================================
   EXISTING PATIENT MODAL
========================================= */
.revisit-modal{
    position:fixed;
    inset:0;
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:rgba(15,23,42,.52);
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
}
.revisit-modal-box{
    width:min(820px,95vw);
    max-height:88vh;
    overflow:hidden;
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:16px;
    box-shadow:0 25px 70px rgba(15,23,42,.25);
    animation:revisitModalIn .18s ease-out;
}
@keyframes revisitModalIn{
    from{
        opacity:0;
        transform:translateY(12px) scale(.98);
    }
    to{
        opacity:1;
        transform:translateY(0) scale(1);
    }
}
.revisit-modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    height:46px;
    padding:0 14px;
    background:linear-gradient(135deg,#2563eb,#0891b2);
    color:#fff;
}
.revisit-title{
    display:flex;
    align-items:center;
    gap:8px;
}
.revisit-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:28px;
    height:28px;
    flex:0 0 28px;
    border-radius:7px;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.22);
    font-size:14px;
}
.revisit-title h3{
    margin:0;
    color:#fff;
    font-size:14px;
    font-weight:700;
}
.revisit-title p{
    margin:1px 0 0;
    color:rgba(255,255,255,.82);
    font-size:9px;
}
.revisit-close{
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
    font-size:19px;
    font-weight:300;
    line-height:1;
    cursor:pointer;
    transition:all .18s ease;
}
.revisit-close:hover{
    background:rgba(220,38,38,.92);
    border-color:rgba(255,255,255,.5);
    transform:scale(1.05);
    box-shadow:none;
}
.revisit-modal-body{
    max-height:calc(88vh - 70px);
    overflow-y:auto;
    padding:20px;
}
.revisit-table-wrap{
    width:100%;
    overflow:hidden;
    border:1px solid #dbe4ef;
    border-radius:11px;
    background:#fff;
}
.revisit-table{
    width:100%;
    border-collapse:collapse;
}
.revisit-table th{
    padding:12px 14px;
    background:#f1f5f9;
    border-bottom:1px solid #dbe4ef;
    color:#475569;
    font-size:12px;
    font-weight:700;
    text-align:left;
    white-space:nowrap;
}
.revisit-table th.select-column{
    width:80px;
    text-align:center;
}
.revisit-table td{
    padding:10px 14px;
    border-bottom:1px solid #e8eef5;
    color:#334155;
    font-size:13px;
    background:#fff;
}
.revisit-table tbody tr{
    transition:background .15s ease;
}
.revisit-table tbody tr:hover td{
    background:#f8fbff;
}
.revisit-table tbody tr:last-child td{
    border-bottom:none;
}
.revisit-table input[type="radio"]{
    width:18px;
    height:18px;
    margin:0;
    accent-color:#2563eb;
    cursor:pointer;
}
.revisit-loading{
    padding:35px 15px!important;
    text-align:center!important;
    color:#64748b!important;
    font-size:13px!important;
}
@media(max-width:700px){
    .revisit-modal{
        padding:10px;
    }
    .revisit-modal-box{
        width:100%;
        max-height:94vh;
        border-radius:13px;
    }
    .revisit-modal-header{
        min-height:62px;
        padding:0 14px;
    }
    .revisit-icon{
        width:36px;
        height:36px;
        flex-basis:36px;
        font-size:17px;
    }
    .revisit-title h3{
        font-size:15px;
    }
    .revisit-modal-body{
        max-height:calc(94vh - 62px);
        padding:12px;
    }
    .revisit-table{
        min-width:600px;
    }
    .revisit-table-wrap{
        overflow-x:auto;
    }
}
.grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:5px 14px;
    padding-top:5px;
    padding-bottom:5px;
}
.payment-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:14px;
    align-items:end;
    padding:15px 17px 17px;
}

.payment-grid>div{
    min-width:0;
}

.payment-grid .payment-action{
    display:flex;
    align-items:flex-end;
}

.payment-grid .payment-action button{
    width:100%;
    min-height:39px;
}
.grid>div{
    min-width:0;
}
.grid>div:has(textarea){
    grid-column:span 1;
}
label{
    display:flex;
    align-items:center;
    gap:3px;
    min-height:19px;
    margin:0 0 6px;
    color:#334155;
    font-size:12px;
    font-weight:650;
}
.required{
    color:#dc2626;
    font-weight:800;
}
input,
select,
textarea{
    width:100%;
    min-height:39px;
    padding:8px 11px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#fff;
    color:#172033;
    font-family:inherit;
    font-size:14px;
    transition:border-color .15s,box-shadow .15s,background .15s;
}
input:hover,
select:hover,
textarea:hover{
    border-color:#94a3b8;
}
.save-appointment-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    width:100%;
    min-height:44px!important;
    padding:10px 22px;
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
input:focus,
select:focus,
textarea:focus{
    outline:none;
    border-color:var(--primary);
    background:#fff;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}
textarea{
    width: 100%;
    height: 33px;
}
.readonly,
input[readonly],
select:disabled{
    background:#f1f5f9!important;
    color:#64748b;
    cursor:not-allowed;
}
.inline{
    display:flex;
    gap:8px;
}
.inline>*{
    min-width:0;
}
.revisit-box{
    display:flex;
    align-items:center;
    gap:14px;
    margin-top:0;
    padding:11px 14px;
    background:#f8fafc;
    border:1px dashed #cbd5e1;
    border-radius:9px;
}
.revisit-check{
    display:flex;
    align-items:center;
    gap:8px;
    margin:0;
    color:#334155;
    font-size:13px;
    font-weight:650;
    cursor:pointer;
}
.revisit-check input{
    width:17px;
    min-height:17px;
    height:17px;
    margin:0;
    padding:0;
    accent-color:var(--primary);
    cursor:pointer;
}
.revisit-btn{
    margin-left:auto;
    padding:8px 15px;
    min-height:36px;
    border:0;
    border-radius:8px;
    background:#64748b;
    color:#fff;
    font-size:12px;
    font-weight:650;
    cursor:pointer;
}
.revisit-btn:hover{
    background:#475569;
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
}
#visit_count_box label{
    min-height:auto;
    margin:0;
    white-space:nowrap;
    color:#334155;
    font-size:11px;
    font-weight:650;
}
#visit_count_box input{
    width:48px;
    min-height:28px;
    height:28px;
    padding:4px 6px;
    background:#fff!important;
    text-align:center;
    font-size:12px;
    font-weight:700;
}
.actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:10px;
    padding-top:0;
    padding-bottom:17px;
}
button{
    padding:9px 18px;
    border:0;
    border-radius:8px;
    background:#2563eb;
    color:#fff;
    font-size:13px;
    font-weight:650;
    cursor:pointer;
    transition:all .15s ease;
}
button:hover{
    transform:translateY(-1px);
    box-shadow:0 5px 14px rgba(37,99,235,.20);
}
button:disabled{
    opacity:.65;
    cursor:not-allowed;
    transform:none;
    box-shadow:none;
}
.btn-primary{
    background:linear-gradient(135deg,#2563eb,#0891b2);
    color:#fff;
}
.btn-secondary{
    background:#64748b;
    color:#fff;
}
.field-error{
    border:2px solid var(--danger)!important;
    background:#fff5f5!important;
}
.select2-container{
    width:100%!important;
}
.select2-container .select2-selection--single{
    height:39px!important;
    border:1px solid #cbd5e1!important;
    border-radius:8px!important;
}
.select2-container .select2-selection--single .select2-selection__rendered{
    line-height:37px!important;
    padding-left:11px!important;
    font-size:13px;
}
.select2-container .select2-selection--single .select2-selection__arrow{
    height:37px!important;
}
.select2-container .field-error{
    border:2px solid var(--danger)!important;
}
.select2-dropdown{
    border-color:#cbd5e1!important;
    border-radius:8px!important;
    overflow:hidden;
}
#city{
    width:100%;
}
input[type="date"]{
    min-width:0;
}
input[type="number"]{
    appearance:textfield;
}
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button{
    opacity:1;
}
#fee{
    font-weight:700;
    color:#1e40af;
}
#balance{
    font-weight:700;
    color:#b45309;
}
#paid{
    font-weight:700;
    color:#15803d;
}
#payment_mode{
    font-weight:600;
}
.card:last-of-type{
    margin-bottom:18px;
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
    .grid>div:has(textarea){
        grid-column:span 2;
    }
    .header{
        padding:0 16px;
    }
    .card{
        margin-left:10px;
        margin-right:10px;
    }
}
@media(max-width:560px){
    body{
        font-size:13px;
    }
    .header{
        min-height:56px;
    }
    .header h1{
        font-size:17px;
    }
    .grid{
        grid-template-columns:1fr;
        gap:11px;
    }
    .grid>div:has(textarea){
        grid-column:span 1;
    }
    .card{
        margin-left:7px;
        margin-right:7px;
        border-radius:9px;
    }
    .card h3{
        padding:11px 13px;
    }
    .card>.grid,
    .card>.revisit-box,
    .card>.actions,
    .card>div:not(h3){
        margin-left:13px;
        margin-right:13px;
    }
    .actions{
        flex-direction:column-reverse;
    }
    .actions button,
    .actions>a{
        width:100%;
    }
}
.revisit-modal{
    position:fixed;
    inset:0;
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:15px;
    background:rgba(15,23,42,.50);
    backdrop-filter:blur(5px);
    -webkit-backdrop-filter:blur(5px);
}
.revisit-modal-box{
    width:min(820px,96vw);
    max-height:88vh;
    overflow:hidden;
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:13px;
    box-shadow:0 20px 55px rgba(15,23,42,.24);
}
.revisit-modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    height:56px;
    padding:0 16px;
    background:#1976d2;
    color:#fff;
}
.revisit-title{
    display:flex;
    align-items:center;
    gap:10px;
}
.revisit-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:32px;
    height:32px;
    flex:0 0 32px;
    border-radius:8px;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.22);
    font-size:15px;
}
.revisit-title h3{
    margin:0;
    color:#fff;
    font-size:15px;
    font-weight:700;
}
.revisit-title p{
    margin:2px 0 0;
    color:rgba(255,255,255,.82);
    font-size:10px;
}
.revisit-close{
    display:flex;
    align-items:center;
    justify-content:center;
    width:32px;
    height:32px;
    padding:0;
    border:1px solid rgba(255,255,255,.30);
    border-radius:50%;
    background:rgba(255,255,255,.12);
    color:#fff;
    font-size:21px;
    font-weight:300;
    line-height:1;
    cursor:pointer;
    transition:all .18s ease;
}
.revisit-close:hover{
    background:#dc2626;
    border-color:#fff;
    transform:scale(1.05);
    box-shadow:none;
}
.revisit-modal-body{
    max-height:calc(88vh - 46px);
    overflow-y:auto;
    padding:15px;
}
.revisit-table-wrap{
    width:100%;
    overflow:hidden;
    border:1px solid #dbe4ef;
    border-radius:9px;
}
.revisit-table{
    width:100%;
    border-collapse:collapse;
}
.revisit-table th{
    padding:10px 12px;
    background:#f1f5f9;
    border-bottom:1px solid #dbe4ef;
    color:#475569;
    font-size:11px;
    font-weight:700;
    text-align:left;
}
.revisit-table th.select-column{
    width:75px;
    text-align:center;
}
.revisit-table td{
    padding:9px 12px;
    border-bottom:1px solid #e8eef5;
    color:#334155;
    font-size:12px;
}
.revisit-table tbody tr:hover td{
    background:#f8fbff;
}
.revisit-table tbody tr:last-child td{
    border-bottom:none;
}
.revisit-table input[type="radio"]{
    width:17px;
    height:17px;
    min-height:17px;
    margin:0;
    accent-color:#2563eb;
    cursor:pointer;
}
.revisit-loading{
    padding:30px 15px!important;
    text-align:center!important;
    color:#64748b!important;
    font-size:12px!important;
}
.revisit-search{
    display:flex;
    align-items:center;
    gap:8px;
    height:38px;
    margin-bottom:10px;
    padding:0 10px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#fff;
}
.revisit-search:focus-within{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}
.revisit-search-icon{
    flex:0 0 auto;
    font-size:13px;
}
.revisit-search input{
    flex:1;
    width:100%;
    min-height:34px;
    height:34px;
    padding:5px 0;
    border:0!important;
    outline:none!important;
    box-shadow:none!important;
    background:transparent!important;
    font-size:12px;
}
.revisit-search button{
    display:flex;
    align-items:center;
    justify-content:center;
    width:24px;
    height:24px;
    min-height:24px;
    padding:0;
    border:0;
    border-radius:50%;
    background:#f1f5f9;
    color:#64748b;
    font-size:17px;
    line-height:1;
}
.revisit-search button:hover{
    background:#fee2e2;
    color:#dc2626;
    transform:none;
    box-shadow:none;
}
.revisit-pagination{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-top:10px;
    min-height:32px;
}
.revisit-page-info{
    color:#64748b;
    font-size:10px;
}
.revisit-page-buttons{
    display:flex;
    align-items:center;
    gap:4px;
}
.revisit-page-buttons button{
    min-width:29px;
    height:29px;
    min-height:29px;
    padding:0 7px;
    border:1px solid #dbe4ef;
    border-radius:6px;
    background:#fff;
    color:#475569;
    font-size:11px;
    font-weight:600;
}
.revisit-page-buttons button:hover{
    background:#eff6ff;
    border-color:#93c5fd;
    color:#2563eb;
    transform:none;
    box-shadow:none;
}
.revisit-page-buttons button.active{
    background:#2563eb;
    border-color:#2563eb;
    color:#fff;
}
.revisit-page-buttons button:disabled{
    opacity:.45;
    cursor:not-allowed;
}
@media(max-width:700px){
    .revisit-pagination{
        align-items:flex-start;
        flex-direction:column;
    }
}
@media(max-width:700px){
    .revisit-modal{
        padding:10px;
    }
    .revisit-modal-box{
        width:100%;
        max-height:94vh;
    }
    .revisit-modal-header{
        height:54px;
        padding:0 13px;
    }
    .revisit-modal-body{
        max-height:calc(94vh - 54px);
        padding:10px;
    }
    .revisit-table-wrap{
        overflow-x:auto;
    }
    .revisit-table{
        min-width:560px;
    }
}
/* =========================
   ADD CITY MODAL
========================= */
.city-modal{
    position:fixed;
    inset:0;
    z-index:10000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:15px;
    background:rgba(15,23,42,.50);
    backdrop-filter:blur(5px);
    -webkit-backdrop-filter:blur(5px);
}
.city-modal-box{
    width:min(560px,96vw);
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
    font-size:19px;
    font-weight:300;
    line-height:1;
    cursor:pointer;
}
.city-modal-close:hover{
    background:#dc2626;
    border-color:#fff;
    transform:scale(1.05);
    box-shadow:none;
}
.city-modal-body{
    padding:15px;
}
.city-modal-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}
.city-modal-grid>div{
    min-width:0;
}
.city-modal-grid label{
    margin-bottom:5px;
    font-size:11px;
}
.city-modal-grid input,
.city-modal-grid select{
    min-height:38px;
}
.city-modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:14px;
    padding-top:12px;
    border-top:1px solid #e8eef5;
}
.city-modal-actions button{
    min-height:36px;
    padding:8px 16px;
    font-size:12px;
}
.city-modal-actions .btn-secondary{
    background:#64748b;
}
.city-modal-actions .btn-primary{
    background:linear-gradient(135deg,#2563eb,#0891b2);
}
@media(max-width:600px){
    .city-modal{
        padding:10px;
    }
    .city-modal-box{
        width:100%;
    }
    .city-modal-body{
        padding:12px;
    }
    .city-modal-grid{
        grid-template-columns:1fr;
        gap:10px;
    }
}
</style>
</head>

<body>

<form method="post" action="save_appointment.php" id="appointmentForm">
    <input type="hidden" name="source" value="<?= $source ?>">

    <input type="hidden" name="patient_id" id="patient_id">
<input type="hidden" name="is_revisit" id="is_revisit" value="0">

<!-- 🔐 PAYMENT FIELDS (ADD HERE) -->
    <input type="hidden" name="payment_status" id="payment_status" value="PENDING">
    <input type="hidden" name="payment_txnid" id="payment_txnid">
    <input type="hidden" name="gateway" value="PAYU">
    
<div class="container">




<!-- ================= APPOINTMENT INFO ================= -->
<div class="card">
<h3><span>🩺</span>Appointment Details</h3>
<div class="grid">
   

    <!-- 1. TYPE -->
<div>
    <label>Select Service / Doctor *</label>

    <select name="type" id="type_dd" <?= ($isFromSlot || $isPreset) ? 'disabled' : '' ?>>
        <option value="">Select</option>

        <option value="doctor"
            <?= ($type === 'doctor') ? 'selected' : '' ?>>
            Doctor
        </option>

        <option value="service"
            <?= ($type === 'service') ? 'selected' : '' ?>>
            Service
        </option>
    </select>

    <?php if ($isFromSlot || $isPreset): ?>
        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    <?php endif; ?>
</div>

<!-- 2. CONSULTATION MODE -->
<div id="consultation_mode_box" style="<?= ($type === 'doctor' || empty($type)) ? '' : 'display:none;' ?>">
    <label>Consultation Mode *</label>

    <select name="consultation_mode" id="consultation_mode">
        <option value="PHYSICAL" selected>Physical</option>
        <option value="TELEMEDICINE">Telemedicine/Online</option>
    </select>
</div>

<div>
    <!-- 2. DEPARTMENT / CATEGORY -->
    
    <label>Department / Service Category *</label>

    <?php if ($isFromSlot || $isPreset): ?>
    <input type="text"
           value="<?= htmlspecialchars($departmentName) ?>"
           class="readonly"
           readonly>

    <input type="hidden"
           name="department_name"
           value="<?= htmlspecialchars($departmentName) ?>">

    <input type="hidden"
           name="department_id"
           value="<?= (int)$department_id ?>">
<?php else: ?>
        <select name="department_id" id="department_dd">
            <option value="">Select</option>
        </select>
        <input type="hidden" name="department_name" id="department_name_hidden">
    <?php endif; ?>
</div>

    <!-- 3. DOCTOR / SERVICE -->
    <div>
    <label>Doctor / Service *</label>

    <?php if ($isFromSlot || $isPreset): ?>
        <input type="text"
       value="<?= htmlspecialchars($entityName) ?>"
       class="readonly"
       readonly>
        <input type="hidden" name="entity_id" value="<?= $entity_id ?>">
        <input type="hidden" name="entity_name" value="<?= htmlspecialchars($entityName) ?>">
    <?php else: ?>
        <select name="entity_id" id="entity_dd">
            <option value="">Select</option>
        </select>
    <?php endif; ?>
   
</div>


    <!-- 4. DATE -->
    <div>
    <label>Appointment Date *</label>

    <?php if ($isFromSlot): ?>
        <!-- Visible (readonly, NOT submitted) -->
        <input type="date"
               value="<?= htmlspecialchars($date) ?>"
               readonly
               class="readonly">

        <!-- 🔥 Actual value that WILL be submitted -->
        <input type="hidden"
               name="appointment_date"
               value="<?= htmlspecialchars($date) ?>">
    <?php else: ?>
        <input type="date"
               name="appointment_date"
               id="appointment_date"
                min="<?= date('Y-m-d') ?>">
    <?php endif; ?>
</div>


    <!-- 5. TIME -->
    <div>
    <label>Appointment Time *</label>

    <?php if ($isFromSlot): ?>
        <!-- Visible -->
        <input type="text"
               value="<?= htmlspecialchars($slot) ?>"
               readonly
               class="readonly">

        <!-- 🔥 Actual value submitted -->
        <input type="hidden"
               name="appointment_time"
               value="<?= htmlspecialchars($slot) ?>">
    <?php else: ?>
        <select name="appointment_time" id="appointment_time_dd">
            <option value="">Select Slot</option>
        </select>
    <?php endif; ?>
</div>


<?php if ($source === 'INTERNAL' && ($isFromSlot ? $type === 'doctor' : true)): ?>
<div class="revisit-box">
    <label class="revisit-check">
        <input type="checkbox" id="revisit">
        <span>Revisit Patient</span>
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
<!-- ================= PATIENT DETAILS ================= -->
<div class="card">
<h3><span>🧑</span>Patient Details</h3>
<div class="grid">
<!-- ================= PATIENT DETAILS ================= -->
    <div>
        <label>Title</label>
        <select id="title" name="title">
            <option>Mr</option>
            <option>Mrs</option>
            <option>Miss</option>
            <option>Master</option>
            <option>Baby</option>
            <option>Baby of</option>
            <option>Dr</option>
        </select>
    </div>
    <div>
        <label>First Name <span class="required">*</span></label>
        <input type="text" id="first_name" name="first_name">
    </div>
    <div>
    <label>Last Name</label>
    <input type="text" id="last_name" name="last_name">
</div>

 <div>
        <label>Marital Status</label>
        <select id="marital" name="marital_status">
            <option>UnMarried</option>
            <option>Married</option>
            <option>Divorced</option>
        </select>
    </div>

    <div>
        <label>Gender</label>
        <select id="sex" name="sex">
            <option>Male</option>
            <option>Female</option>
            <option>Other</option>
        </select>
    </div>

<div>
    <label id="guardian_label">Father / Mother Name</label>
    <input type="text" id="guardian_name" name="guardian_name">
</div>


 <div>
        <label>Aadhaar No</label>
        <input type="text"
       id="aadhaar"
       name="aadhaar_no"
       maxlength="12"
       pattern="\d{12}"
       inputmode="numeric"
       oninput="this.value=this.value.replace(/[^0-9]/g,'')"
       title="Aadhaar must be exactly 12 digits">

    </div>
    
     <div>
        <label>Mobile <span class="required">*</span></label>
        <input type="text" id="mobile" name="mobile"
maxlength="10"
inputmode="numeric">

    </div>
    <div>
        <label>Phone</label>
        <input type="text" id="phone" name="phone"
maxlength="10"
inputmode="numeric">
    </div>
    <div>
        <label>Email</label>
        <input type="email" id="email" name="email">
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
    <label>Date of Birth *</label>
    <input type="date" id="dob" name="dob" onchange="dobToAge()">
</div>
    <div>
    <label>
    City <span class="required">*</span>
    <?php if ($source === 'INTERNAL'): ?>
        <button type="button" tabindex="-1"
                onclick="openCityModal()"
                style="
                    margin-left:10px;
                    padding:3px 8px;
                    font-size:12px;
                    border-radius:5px;
                    background:#2563eb;
                    color:#fff;
                    border:none;
                    cursor:pointer;">
            + Add City
        </button>
    <?php endif; ?>
</label>
    <select id="city" name="city">
        <option value="">Select City</option>
        <?php foreach ($cities as $c): ?>
            <option value="<?= $c['id'] ?>">
                <?= htmlspecialchars($c['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
       <div>
        <label>Pincode</label>
        <input type="text" id="pincode" name="pincode">
    </div>
   <div> 
        <label>Address</label>
        <textarea id="address" name="address"></textarea>
    </div>
    <div>
        <label>Remarks</label>
        <textarea id="remarks" name="remarks"></textarea>
    </div>
</div>
</div>
<!-- ================= PAYMENT ================= -->
<div class="card">
<h3><span>💰</span>Payment Details</h3>

<div class="payment-grid">

    <div>
        <label>Consultation Fee</label>
        <input type="number"
               id="fee"
               name="fee"
               value="<?= $feeAmount ?>"
               readonly
               class="readonly"
               data-slot-fee="<?= $isFromSlot ? '1' : '0' ?>">
    </div>

    <div>
        <label>Payment Mode</label>
        <select id="payment_mode"
                name="payment_mode"
                <?= $source === 'WEBSITE' ? 'disabled' : '' ?>>

            <?php if ($source === 'WEBSITE'): ?>
                <option selected>Online</option>
            <?php else: ?>
                <option>Cash</option>
                <option>UPI</option>
                <option>Card</option>
            <?php endif; ?>

        </select>

        <?php if ($source === 'WEBSITE'): ?>
            <input type="hidden"
                   name="payment_mode"
                   value="Online">
        <?php endif; ?>
    </div>

    <?php if ($source === 'INTERNAL'): ?>

        <div>
            <label>Paid Amount</label>
            <input type="number"
                   id="paid"
                   name="paid_amount"
                   readonly
                   class="readonly">
        </div>

        <div>
            <label>Balance</label>
            <input type="number"
                   id="balance"
                   name="balance"
                   readonly
                   class="readonly">
        </div>

    <?php else: ?>

        <input type="hidden"
               name="paid_amount"
               value="0">

        <input type="hidden"
               name="balance"
               value="<?= $feeAmount ?>">

        <div style="display:none;"></div>

    <?php endif; ?>

    <div class="payment-action">

        <?php if ($source === 'WEBSITE'): ?>

            <button type="button"
                    class="btn-primary"
                    onclick="startPayUPayment(this)">
                Pay & Confirm Appointment
            </button>

        <?php else: ?>

           <button type="submit" class="btn-primary save-appointment-btn">
    <span class="save-icon">✓</span>
    <span>Save Appointment</span>
</button>

        <?php endif; ?>

    </div>

</div>
</div>
</form>
<script>

document.addEventListener('DOMContentLoaded', function () {
    let isRevisit = false;

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
</script>



<script>
document.addEventListener('DOMContentLoaded', function () {

    const isFromSlot = <?= json_encode($isFromSlot) ?>;
    const isPreset = <?= json_encode($isPreset) ?>;

    const presetEntityId = <?= json_encode((string)$entity_id) ?>;
    const presetType = <?= json_encode((string)$type) ?>;

    const typeDD = document.getElementById('type_dd');
    const deptDD = document.getElementById('department_dd');
    const entityDD = document.getElementById('entity_dd');
    const consultationModeBox = document.getElementById('consultation_mode_box');
const consultationModeDD = document.getElementById('consultation_mode');

function updateConsultationMode() {
    if (!typeDD || !consultationModeBox || !consultationModeDD) {
        return;
    }

    if (typeDD.value === 'doctor') {
        consultationModeBox.style.display = '';
    } else {
        consultationModeBox.style.display = 'none';
        consultationModeDD.value = 'PHYSICAL';
    }
}

updateConsultationMode();

if (typeDD) {
    typeDD.addEventListener('change', updateConsultationMode);
}

    const dateDD =
        document.getElementById('appointment_date') ||
        document.querySelector('input[name="appointment_date"]');

    const timeDD =
        document.getElementById('appointment_time_dd');

    const feeInput =
        document.getElementById('fee');

    function getAppointmentType() {

        if (typeDD && typeDD.value) {
            return typeDD.value;
        }

        return presetType;
    }

    function getEntityId() {

        if (entityDD && entityDD.value) {
            return entityDD.value;
        }

        return presetEntityId;
    }

    /* ==========================================
       TYPE → DEPARTMENT
       NORMAL FORM ONLY
    ========================================== */

    if (typeDD && deptDD && entityDD) {

        typeDD.addEventListener('change', function () {

            deptDD.innerHTML =
                '<option value="">Loading...</option>';

            entityDD.innerHTML =
                '<option value="">Select</option>';

            if (timeDD) {
                timeDD.innerHTML =
                    '<option value="">Select Slot</option>';
            }

            if (!this.value) {

                deptDD.innerHTML =
                    '<option value="">Select</option>';

                return;
            }

            if (this.value === 'doctor') {

                fetch(
                    '/modules/appointments/ajax/get_departments.php?type=doctor'
                )
                .then(r => r.json())
                .then(data => {

                    deptDD.innerHTML =
                        '<option value="">Select Department</option>';

                    data.forEach(d => {

                        deptDD.innerHTML +=
                            `<option value="${d.id}">
                                ${d.department_name}
                            </option>`;

                    });

                })
                .catch(error => {

                    console.error(
                        'Department loading error:',
                        error
                    );

                    deptDD.innerHTML =
                        '<option value="">Unable to load departments</option>';

                });

            }

            if (this.value === 'service') {

                fetch(
                    '/modules/appointments/ajax/get_service_categories.php'
                )
                .then(r => r.json())
                .then(data => {

                    deptDD.innerHTML =
                        '<option value="">Select Category</option>';

                    data.forEach(c => {

                        deptDD.innerHTML +=
                            `<option value="${c.id}">
                                ${c.category_name}
                            </option>`;

                    });

                })
                .catch(error => {

                    console.error(
                        'Category loading error:',
                        error
                    );

                    deptDD.innerHTML =
                        '<option value="">Unable to load categories</option>';

                });

            }

        });

        /* ==========================================
           DEPARTMENT → DOCTOR / SERVICE
        ========================================== */

        deptDD.addEventListener('change', function () {

            entityDD.innerHTML =
                '<option value="">Loading...</option>';

            if (timeDD) {
                timeDD.innerHTML =
                    '<option value="">Select Slot</option>';
            }

            const hiddenDepartmentName =
                document.getElementById(
                    'department_name_hidden'
                );

            if (hiddenDepartmentName) {

                hiddenDepartmentName.value =
                    this.options[this.selectedIndex]
                        ? this.options[this.selectedIndex]
                            .text
                            .trim()
                        : '';
            }

            if (!this.value) {

                entityDD.innerHTML =
                    '<option value="">Select</option>';

                return;
            }

            if (typeDD.value === 'doctor') {

                fetch(
                    `/modules/appointments/ajax/get_doctors.php?department_id=${encodeURIComponent(this.value)}`
                )
                .then(r => r.json())
                .then(data => {

                    entityDD.innerHTML =
                        '<option value="">Select Doctor</option>';

                    data.forEach(d => {

                        entityDD.innerHTML +=
                            `<option value="${d.id}">
                                ${d.name}
                            </option>`;

                    });

                })
                .catch(error => {

                    console.error(
                        'Doctor loading error:',
                        error
                    );

                    entityDD.innerHTML =
                        '<option value="">Unable to load doctors</option>';

                });

            }

            if (typeDD.value === 'service') {

                fetch(
                    `/modules/appointments/ajax/get_services.php?category_id=${encodeURIComponent(this.value)}`
                )
                .then(r => r.json())
                .then(data => {

                    entityDD.innerHTML =
                        '<option value="">Select Service</option>';

                    data.forEach(s => {

                        entityDD.innerHTML +=
                            `<option value="${s.id}">
                                ${s.name}
                            </option>`;

                    });

                })
                .catch(error => {

                    console.error(
                        'Service loading error:',
                        error
                    );

                    entityDD.innerHTML =
                        '<option value="">Unable to load services</option>';

                });

            }

        });

        /* ==========================================
           DOCTOR / SERVICE → FEE + SLOTS
        ========================================== */

        entityDD.addEventListener('change', function () {

            if (timeDD) {
                timeDD.innerHTML =
                    '<option value="">Select Slot</option>';
            }

            if (
                feeInput &&
                feeInput.dataset.slotFee === '1'
            ) {

                loadSlots();

                return;
            }

            if (
                typeDD.value === 'doctor' &&
                this.value
            ) {

                fetch(
                    '/modules/appointments/ajax/get_doctor_fee.php?doctor_id=' +
                    encodeURIComponent(this.value)
                )
                .then(r => r.json())
                .then(data => {

                    if (feeInput) {

                        feeInput.value =
                            data.status === 'FOUND'
                                ? data.fee
                                : 0;

                        forceFullPayment();

                    }

                })
                .catch(() => {

                    if (feeInput) {
                        feeInput.value = 0;
                    }

                });

            }

            if (
    typeDD.value === 'service' &&
    this.value
) {
    fetch(
        '/modules/appointments/ajax/get_service_fee.php?service_id=' +
        encodeURIComponent(this.value)
    )
    .then(r => r.json())
    .then(data => {
        if (feeInput) {
            feeInput.value =
                data.status === 'FOUND'
                    ? data.fee
                    : 0;
            forceFullPayment();
        }
    })
    .catch(() => {
        if (feeInput) {
            feeInput.value = 0;
            forceFullPayment();
        }
    });
}
            loadSlots();

        });

    }

    /* ==========================================
       DATE → SLOTS
       WORKS FOR PRESET + NORMAL
    ========================================== */
if (dateDD) {

    dateDD.addEventListener('change', function () {
        loadSlots();
        /* =========================
           RE-CALCULATE REVISIT FEE
        ========================= */
        const patientId =
            document.getElementById('patient_id')?.value || '';
        const doctorId =
            document.getElementById('entity_dd')?.value ||
            "<?= $entity_id ?? '' ?>";
        const revisit =
            document.getElementById('is_revisit')?.value === '1';
        if (
            revisit &&
            patientId &&
            doctorId &&
            this.value
        ) {
            console.log(
                'Revisit date changed:',
                this.value
            );
            loadRevisitCharge(
                doctorId,
                patientId
            );
        }
    });
}    /* ==========================================
       LOAD BOOKED SLOTS
    ========================================== */

    function loadSlots() {

        if (!timeDD) {
            return;
        }

        timeDD.innerHTML =
            '<option value="">Loading slots...</option>';

        const currentType =
            getAppointmentType();

        const currentEntityId =
            getEntityId();

        const currentDate =
            dateDD ? dateDD.value : '';

        if (
            !currentType ||
            !currentEntityId ||
            !currentDate
        ) {

            timeDD.innerHTML =
                '<option value="">Select Slot</option>';

            return;
        }

        fetch(
            `/modules/appointments/ajax/get_booked_slots.php?type=${encodeURIComponent(currentType)}&entity_id=${encodeURIComponent(currentEntityId)}&date=${encodeURIComponent(currentDate)}`
        )
        .then(r => {

            if (!r.ok) {
                throw new Error(
                    'Booked slots API failed'
                );
            }

            return r.json();

        })
        .then(bookedSlots => {

            window.bookedSlots =
                bookedSlots || {};

            if (currentType === 'doctor') {

                loadDoctorSlots(
                    currentEntityId,
                    currentDate
                );

            } else if (currentType === 'service') {

                loadServiceSlots(
                    currentEntityId,
                    currentDate
                );

            }

        })
        .catch(error => {

            console.error(
                'Booked slots error:',
                error
            );

            timeDD.innerHTML =
                '<option value="">Unable to load slots</option>';

        });

    }

    /* ==========================================
       DOCTOR SLOTS
    ========================================== */

    function loadDoctorSlots(
        doctorId,
        selectedDate
    ) {

        fetch(
            `/modules/appointments/ajax/get_doctor_slots.php?doctor_id=${encodeURIComponent(doctorId)}`
        )
        .then(r => {

            if (!r.ok) {
                throw new Error(
                    'Doctor slots API failed'
                );
            }

            return r.json();

        })
        .then(data => {

            timeDD.innerHTML =
                '<option value="">Select Slot</option>';

            if (
                !data[selectedDate] ||
                !Array.isArray(data[selectedDate])
            ) {

                timeDD.innerHTML =
                    '<option value="">No slots available</option>';

                return;
            }

            const now =
                new Date();

            const today =
                new Date()
                    .toISOString()
                    .split('T')[0];

            let count = 0;

            data[selectedDate].forEach(slot => {

                if (
                    slot.status !== 'available'
                ) {
                    return;
                }

                if (
                    selectedDate === today
                ) {

                    const parts =
                        slot.start
                            .split(':')
                            .map(Number);

                    const slotTime =
                        new Date();

                    slotTime.setHours(
                        parts[0],
                        parts[1],
                        0,
                        0
                    );

                    if (slotTime <= now) {
                        return;
                    }

                }

                const slotValue =
                    `${slot.start} - ${slot.end}`;

                if (
                    window.bookedSlots &&
                    window.bookedSlots[slotValue]
                ) {

                    return;
                }

                const option =
                    document.createElement('option');

                option.value =
                    slotValue;

                option.textContent =
                    slotValue;

                timeDD.appendChild(option);

                count++;

            });

            if (count === 0) {

                timeDD.innerHTML =
                    '<option value="">No slots available</option>';

            }

        })
        .catch(error => {

            console.error(
                'Doctor slots error:',
                error
            );

            timeDD.innerHTML =
                '<option value="">Unable to load slots</option>';

        });

    }

    /* ==========================================
       SERVICE SLOTS
    ========================================== */

    function loadServiceSlots(
        serviceId,
        selectedDate
    ) {

        fetch(
            `/modules/appointments/ajax/get_service_time.php?service_id=${encodeURIComponent(serviceId)}`
        )
        .then(r => {

            if (!r.ok) {
                throw new Error(
                    'Service time API failed'
                );
            }

            return r.json();

        })
        .then(cfg => {

            timeDD.innerHTML =
                '<option value="">Select Slot</option>';

            if (
                !cfg.start_time ||
                !cfg.close_time ||
                !cfg.slot_minutes
            ) {

                timeDD.innerHTML =
                    '<option value="">No slots available</option>';

                return;
            }

            const today =
                new Date()
                    .toISOString()
                    .split('T')[0];

            const now =
                new Date();

            const [sh, sm] =
                cfg.start_time
                    .split(':')
                    .map(Number);

            const [eh, em] =
                cfg.close_time
                    .split(':')
                    .map(Number);

            const slotMinutes =
                parseInt(
                    cfg.slot_minutes,
                    10
                );

            let cur =
                new Date();

            cur.setHours(
                sh,
                sm,
                0,
                0
            );

            const end =
                new Date();

            end.setHours(
                eh,
                em,
                0,
                0
            );

            let count = 0;

            while (cur < end) {

                const next =
                    new Date(
                        cur.getTime() +
                        slotMinutes * 60000
                    );

                if (
                    selectedDate === today &&
                    cur <= now
                ) {

                    cur = next;

                    continue;
                }

                const s =
                    cur
                        .toTimeString()
                        .slice(0, 5);

                const e =
                    next
                        .toTimeString()
                        .slice(0, 5);

                const slotValue =
                    `${s} - ${e}`;

                if (
                    window.bookedSlots &&
                    window.bookedSlots[slotValue]
                ) {

                    cur = next;

                    continue;
                }

                const option =
                    document.createElement('option');

                option.value =
                    slotValue;

                option.textContent =
                    slotValue;

                timeDD.appendChild(option);

                count++;

                cur = next;

            }

            if (count === 0) {

                timeDD.innerHTML =
                    '<option value="">No slots available</option>';

            }

        })
        .catch(error => {

            console.error(
                'Service slots error:',
                error
            );

            timeDD.innerHTML =
                '<option value="">Unable to load slots</option>';

        });

    }

});
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

<script>
$(document).ready(function () {
    $('#city').select2({
        placeholder: 'Select City',
        allowClear: true,
        width: '100%'
    });
});
</script>


</div>
</form>
<!-- ================= REVISIT PATIENT MODAL ================= -->
<div id="revisitModal" class="revisit-modal">
    <div class="revisit-modal-box">
        <div class="revisit-modal-header">
            <div class="revisit-title">
                <span class="revisit-icon">👤</span>
                <div>
                    <h3>Select Existing Patient</h3>
                    <p>Select a patient for revisit</p>
                </div>
            </div>
            <button type="button"
                    class="revisit-close"
                    onclick="closeRevisitModal()"
                    aria-label="Close"
                    title="Close">
                ×
            </button>
        </div>
        <div class="revisit-modal-body">
    <div class="revisit-search">
        <span class="revisit-search-icon">🔍</span>
        <input type="text"
               id="revisitSearch"
               placeholder="Search Name / Mobile / Aadhaar"
               autocomplete="off">
        <button type="button"
                id="revisitSearchClear"
                onclick="clearRevisitSearch()"
                title="Clear">
            ×
        </button>
    </div>
    <div class="revisit-table-wrap">
        <table class="revisit-table">
            <thead>
                <tr>
                    <th class="select-column">Select</th>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Aadhaar</th>
                </tr>
            </thead>
            <tbody id="revisitPatientTable">
                <tr>
                    <td colspan="4" class="revisit-loading">
                        Loading...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="revisit-pagination" id="revisitPagination"></div>
</div>
    </div>
</div>

<script>
const APPOINTMENT_SOURCE = "<?= $source ?>";
</script>



<script>
function openRevisitModal() {
    document.getElementById('revisitModal').style.display = 'flex';
    const searchInput = document.getElementById('revisitSearch');
    if (searchInput) {
        searchInput.value = '';
    }
    loadRevisitPatients(1);
}

function closeRevisitModal() {
    document.getElementById('revisitModal').style.display = 'none';
}
</script>


<script>
document.addEventListener('change', function (e) {

    if (APPOINTMENT_SOURCE !== 'INTERNAL') return;

    if (e.target && e.target.id === 'revisit' && e.target.checked) {

    const doctorId =
        document.getElementById('entity_dd')?.value ||
        "<?= $entity_id ?? '' ?>";

    if (!doctorId) {
        alert('Please select doctor first');
        e.target.checked = false;
        return;
    }

    openRevisitModal();
}

});


</script>
<script>
let revisitCurrentPage = 1;
let revisitSearchTimer = null;

function loadRevisitPatients(page = 1) {
    const tbody = document.getElementById('revisitPatientTable');
    const pagination = document.getElementById('revisitPagination');
    const searchInput = document.getElementById('revisitSearch');
    const search = searchInput ? searchInput.value.trim() : '';

    revisitCurrentPage = page;

    tbody.innerHTML = `
        <tr>
            <td colspan="4" class="revisit-loading">
                Loading...
            </td>
        </tr>
    `;

    pagination.innerHTML = '';

    const url =
        '/modules/appointments/ajax/get_patients_list.php' +
        '?page=' + encodeURIComponent(page) +
        '&search=' + encodeURIComponent(search);

    fetch(url)
        .then(res => {
            if (!res.ok) {
                throw new Error('Patient list API failed');
            }
            return res.json();
        })
        .then(result => {
            const data = Array.isArray(result.data) ? result.data : [];
            const total = parseInt(result.total || 0, 10);
            const currentPage = parseInt(result.page || 1, 10);
            const totalPages = parseInt(result.total_pages || 1, 10);

            if (data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="4" class="revisit-loading">
                            No patients found
                        </td>
                    </tr>
                `;
                renderRevisitPagination(0, currentPage, totalPages);
                return;
            }

            let html = '';

            data.forEach(p => {
                const fullName =
                    `${p.first_name || ''} ${p.last_name || ''}`.trim();

                html += `
                    <tr>
                        <td align="center">
                            <input type="radio"
                                   name="revisit_select"
                                   value="${p.id}">
                        </td>
                        <td>${escapeRevisitHtml(fullName)}</td>
                        <td>${escapeRevisitHtml(p.mobile || '')}</td>
                        <td>${escapeRevisitHtml(p.aadhaar || '')}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;

            renderRevisitPagination(
                total,
                currentPage,
                totalPages
            );
        })
        .catch(err => {
            console.error('Revisit patient loading error:', err);

            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="revisit-loading">
                        Failed to load patients
                    </td>
                </tr>
            `;
        });
}

function renderRevisitPagination(total, currentPage, totalPages) {
    const pagination =
        document.getElementById('revisitPagination');

    if (!pagination) return;

    if (!total) {
        pagination.innerHTML = '';
        return;
    }

    const start =
        ((currentPage - 1) * 25) + 1;

    const end =
        Math.min(currentPage * 25, total);

    let buttons = '';

    buttons += `
        <button type="button"
                onclick="loadRevisitPatients(${currentPage - 1})"
                ${currentPage <= 1 ? 'disabled' : ''}>
            ‹
        </button>
    `;

    const maxButtons = 5;

    let startPage =
        Math.max(1, currentPage - 2);

    let endPage =
        Math.min(totalPages, startPage + maxButtons - 1);

    if (endPage - startPage < maxButtons - 1) {
        startPage =
            Math.max(1, endPage - maxButtons + 1);
    }

    for (let i = startPage; i <= endPage; i++) {
        buttons += `
            <button type="button"
                    class="${i === currentPage ? 'active' : ''}"
                    onclick="loadRevisitPatients(${i})">
                ${i}
            </button>
        `;
    }

    buttons += `
        <button type="button"
                onclick="loadRevisitPatients(${currentPage + 1})"
                ${currentPage >= totalPages ? 'disabled' : ''}>
            ›
        </button>
    `;

    pagination.innerHTML = `
        <div class="revisit-page-info">
            Showing ${start}-${end} of ${total}
        </div>
        <div class="revisit-page-buttons">
            ${buttons}
        </div>
    `;
}

function escapeRevisitHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('revisitSearch');

    if (!searchInput) return;

    searchInput.addEventListener('input', function () {
        clearTimeout(revisitSearchTimer);

        revisitSearchTimer = setTimeout(function () {
            loadRevisitPatients(1);
        }, 300);
    });
});

function clearRevisitSearch() {
    const searchInput =
        document.getElementById('revisitSearch');

    if (!searchInput) return;

    searchInput.value = '';
    searchInput.focus();

    loadRevisitPatients(1);
}
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

// 🔥 Load REVISIT charge
const doctorUserId = document.getElementById('entity_dd')?.value || "<?= $entity_id ?? '' ?>";

loadRevisitCharge(doctorUserId, patientId);

    fetch('/modules/appointments/ajax/get_patient_details.php?id=' + patientId)
        .then(res => res.json())
        .then(p => {
            if (!p) return;
            document.getElementById('title').value = p.title ?? 'Mr';
            document.getElementById('first_name').value = p.first_name ?? '';
            document.getElementById('last_name').value = p.last_name ?? '';
            document.getElementById('marital').value = p.marital_status ?? '';
            document.getElementById('sex').value = p.sex ?? '';
            document.getElementById('guardian_name').value = p.guardian_name ?? '';
            document.getElementById('aadhaar').value = p.aadhaar ?? '';
            document.getElementById('mobile').value = p.mobile ?? '';
            document.getElementById('phone').value = p.phone ?? '';
            document.getElementById('email').value = p.email ?? '';
            document.getElementById('dob').value = p.dob ?? '';
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
            fetch('/modules/appointments/ajax/get_visit_count.php?patient_id=' + patientId)
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

    // ensure option exists
    let found = false;
    [...genderDD.options].forEach(opt => {
        if (opt.value === genderValue) found = true;
    });

    if (!found) {
        const opt = document.createElement('option');
        opt.value = genderValue;
        opt.textContent = genderValue;
        genderDD.appendChild(opt);
    }

    genderDD.value = genderValue;
}
</script>

<script>
function startPayUPayment(btn) {

    const form = btn.closest('form');

    if (!form) {
        alert('Appointment form not found');
        return;
    }

    const feeElement = document.getElementById('fee');
    const firstNameElement = document.getElementById('first_name');
    const mobileElement = document.getElementById('mobile');
    const appointmentDateElement =
        document.getElementById('appointment_date') ||
        document.querySelector('input[name="appointment_date"]');

    const fee = parseFloat(feeElement ? feeElement.value : 0) || 0;
    const firstName = firstNameElement ? firstNameElement.value.trim() : '';
    const mobile = mobileElement ? mobileElement.value.trim() : '';
    const appointmentDate =
        appointmentDateElement ? appointmentDateElement.value.trim() : '';

    if (fee <= 0) {
        alert('Invalid payment amount');
        return;
    }

    if (!firstName || !mobile || !appointmentDate) {
        alert('Please fill required details');
        return;
    }

    /*
     * Collect ALL appointment form fields.
     * This keeps the complete appointment data available
     * while the patient goes to PayU.
     */
    const formData = new FormData(form);
    const appointmentData = {};

    formData.forEach((value, key) => {
        appointmentData[key] = value;
    });

    /*
     * Make sure these values are correct.
     */
    appointmentData.fee = fee;
    appointmentData.source = 'WEBSITE';

    btn.disabled = true;
    btn.innerText = 'Opening Payment...';

    fetch('/payments/initiate_payment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            amount: fee,
            purpose: 'APPOINTMENT',
            appointment: appointmentData
        })
    })
    .then(res => res.json())
    .then(data => {

        console.log('PayU Response:', data);

        if (data.status === 'OK' && data.redirect) {
            window.location.href = data.redirect;
            return;
        }

        alert(data.message || 'Payment initiation failed');

        btn.disabled = false;
        btn.innerText = 'Pay & Confirm Appointment';

    })
    .catch(error => {

        console.error('PayU Error:', error);

        alert('Payment service unavailable');

        btn.disabled = false;
        btn.innerText = 'Pay & Confirm Appointment';

    });
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

    fetch('/modules/appointments/ajax/check_patient_by_aadhaar.php?aadhaar=' + aadhaar)
        .then(res => res.json())
        .then(data => {

            if (!data.found) return;

            const p = data.patient;

            alert('Aadhaar already exists. Loading existing patient.');
            
            // 🔥 LOAD REVISIT COUNT (AADHAAR FLOW)
fetch('/modules/appointments/ajax/get_visit_count.php?patient_id=' + p.id)
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

    fetch('/modules/appointments/ajax/check_patient_by_mobile.php?mobile=' + mobile)
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
    if (timeDD && !timeDD.value) markError(timeDD);

    /* =========================
       PATIENT VALIDATION
    ========================== */

    const firstName = document.getElementById('first_name');
    if (!firstName.value.trim()) markError(firstName);

    const mobile = document.getElementById('mobile');

if (!mobile.value.trim()) {
    markError(mobile);
} else if (!/^[0-9]{10}$/.test(mobile.value.trim())) {
    markError(mobile);
}
const phone = document.getElementById('phone');

if (
    phone.value.trim() !== '' &&
    !/^[0-9]{10}$/.test(phone.value.trim())
) {
    markError(phone);
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
</script>
<script>
function loadRevisitCharge(doctorUserId, patientId) {

    if (!doctorUserId || !patientId) {
        return;
    }

    const appointmentDateElement =
        document.getElementById('appointment_date') ||
        document.querySelector('input[name="appointment_date"]');

    const appointmentDate =
        appointmentDateElement
            ? appointmentDateElement.value
            : '';

    if (!appointmentDate) {
        console.warn('Appointment date not selected.');
        return;
    }

    const url =
        '/modules/appointments/ajax/get_doctor_revisit_charge.php' +
        '?doctor_id=' + encodeURIComponent(doctorUserId) +
        '&patient_id=' + encodeURIComponent(patientId) +
        '&appointment_date=' + encodeURIComponent(appointmentDate);

    console.log('Revisit charge URL:', url);

    fetch(url)
        .then(res => {

            if (!res.ok) {
                throw new Error(
                    'Revisit charge API failed: ' + res.status
                );
            }

            return res.json();

        })
        .then(data => {

            console.log(
                'REVISIT CHARGE RESPONSE:',
                data
            );

            const feeInput =
                document.getElementById('fee');

            if (!feeInput) {
                return;
            }

            if (data.status === 'FOUND') {

                feeInput.value =
                    parseFloat(data.fee || 0).toFixed(2);

                forceFullPayment();

                console.log(
                    'Revisit fee applied:',
                    data.fee,
                    'Days:',
                    data.days
                );

            } else {

                console.log(
                    'No matching revisit slab:',
                    data
                );

                loadNormalDoctorFee(
                    doctorUserId
                );
            }

        })
        .catch(err => {

            console.error(
                'Revisit fee error:',
                err
            );

            loadNormalDoctorFee(
                doctorUserId
            );
        });
}

/* 🔁 NORMAL fallback (reuse your existing logic) */
function loadNormalDoctorFee(doctorUserId) {

    fetch(`/modules/appointments/ajax/get_doctor_fee.php?doctor_id=${doctorUserId}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'FOUND') {
                document.getElementById('fee').value = data.fee;
                forceFullPayment();
                
            }
        });
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
    forceFullPayment();
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
            <button type="button"
                    class="city-modal-close"
                    onclick="closeCityModal()"
                    aria-label="Close"
                    title="Close">
                ×
            </button>
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
                    <input type="text"
                           id="cm_city"
                           placeholder="Enter city name">
                </div>
            </div>
            <div class="city-modal-actions">
                <button type="button"
                        class="btn-secondary"
                        onclick="closeCityModal()">
                    Cancel
                </button>
                <button type="button"
                        class="btn-primary"
                        onclick="saveCity()">
                    Save City
                </button>
            </div>
        </div>
    </div>
</div>
<script>
function openCityModal() {
    document.getElementById('cityModal').style.display = 'flex';
    loadCountries();
}

function closeCityModal() {
    document.getElementById('cityModal').style.display = 'none';
}
</script>
<script>
function loadCountries() {
    fetch('/modules/appointments/ajax/get_countries.php')
        .then(r=>r.json())
        .then(d=>{
            cm_country.innerHTML='<option value="">Select</option>';
            d.forEach(c=>{
                cm_country.innerHTML+=`<option value="${c.id}">${c.name}</option>`;
            });
        });
}
cm_country.addEventListener('change',()=>{
    fetch('/modules/appointments/ajax/get_states.php?country_id='+cm_country.value)
        .then(r=>r.json())
        .then(d=>{
            cm_state.innerHTML='<option value="">Select</option>';
            cm_district.innerHTML='<option value="">Select</option>';
            d.forEach(s=>{
                cm_state.innerHTML+=`<option value="${s.id}">${s.name}</option>`;
            });
        });
});
cm_state.addEventListener('change',()=>{
    fetch('/modules/appointments/ajax/get_districts.php?state_id='+cm_state.value)
        .then(r=>r.json())
        .then(d=>{
            cm_district.innerHTML='<option value="">Select</option>';
            d.forEach(x=>{
                cm_district.innerHTML+=`<option value="${x.id}">${x.name}</option>`;
            });
        });
});
function saveCity(){
    if(!cm_district.value || !cm_city.value){
        alert('All fields required');
        return;
    }
    fetch('/modules/appointments/ajax/save_city.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`district_id=${cm_district.value}&city=${encodeURIComponent(cm_city.value)}`
    })
    .then(r=>r.json())
    .then(d=>{
        if(d.id){
            const opt = new Option(d.name,d.id,true,true);
            $('#city').append(opt).trigger('change');
            closeCityModal();
        }
    });
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const params = new URLSearchParams(window.location.search);
    if(params.get('type'))
        document.getElementById('type_dd').value = params.get('type');
    if(params.get('first_name'))
        document.getElementById('first_name').value = params.get('first_name');
    if(params.get('mobile'))
        document.getElementById('mobile').value = params.get('mobile');
});
</script>
<script>
document.getElementById('mobile').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});

document.getElementById('phone').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});
</script>
</body>
</html>