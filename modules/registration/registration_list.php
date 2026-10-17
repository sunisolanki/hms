<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
/* =========================
   FETCH FILTER DATA
========================= */
/* Cities */
$cityRes = mysqli_query($conn,"
    SELECT id, name
    FROM cities
    ORDER BY name
");
/* Created By Users */
$createdByRes = mysqli_query($conn,"
    SELECT id, full_name
    FROM users
    ORDER BY full_name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Registration</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
.table-wrap{
    background:#fff;
    border-radius:10px;
    padding:1px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    overflow:auto;
    height: 570px;
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
    white-space:nowrap;
}
th, td{
    border:1px solid #e5e7eb;
    padding:1px 10px;
    text-align:left;
}
th{
    background:#1976d2;
    padding:8px 10px;
    color:#fff;
    position:sticky;
    top:0;
}
tbody tr{
    transition:box-shadow .15s ease,transform .15s ease;
}
tbody tr.new-patient{
    background:#fef3c7 !important;
}
tbody tr.multiple-visits{
    background:#dcfce7 !important;
}
tbody tr:hover{
    position:relative;
    z-index:5;
    box-shadow:inset 0 0 0 2px #1976d2,0 6px 14px rgba(25,118,210,.25);
    transform:translateY(-1px);
}
/* =========================
   PAGE HEADER
========================= */
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.btn-primary{
    background:#1e88e5;
    color:#fff;
    border:none;
    padding:10px 16px;
    border-radius:6px;
    cursor:pointer;
    font-weight:600;
}
.btn-primary:hover{
    background:#1565c0;
}
.action-menu{
    position:relative;
    display:inline-block;
}

.action-btn{
    border:none;
    background:#1976d2;
    color:#fff;
    padding:4px 10px;
    border-radius:4px;
    cursor:pointer;
    font-size:16px;
}

.action-dropdown{
    display:none;
    position:absolute;
    top:100%;
    left:0;
    min-width:140px;
    background:#fff;
    border:1px solid #ddd;
    border-radius:6px;
    box-shadow:0 4px 12px rgba(0,0,0,.15);
    z-index:999;
}

.action-dropdown a{
    display:block;
    padding:10px 12px;
    text-decoration:none;
    color:#333;
    font-size:13px;
}

.action-dropdown a:hover{
    background:#f5f5f5;
}

</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
    <div class="page-container">
        <!-- PAGE HEADER -->
        <div class="page-header">
            <h2>📋 Registration List</h2>
            <?php if (checkPermission('patient_registration','add')) { ?>
<button type="button" onclick="openRegisterPopup()">+ Register Patient </button>
<?php } ?>
</div>
<form method="get"
style="margin-bottom:5px;display:flex;gap:5px;align-items:center;">
<!-- UHID -->
<input type="text"
name="uhid"
placeholder="UHID"
value="<?= $_GET['uhid'] ?? '' ?>"
onchange="this.form.submit()">
<!-- MOBILE -->
<input type="text"
name="mobile"
placeholder="Mobile"
value="<?= $_GET['mobile'] ?? '' ?>"
onchange="this.form.submit()">
<!-- GENDER -->
<select name="gender" onchange="this.form.submit()">
<option value="">Gender</option>
<option value="Male" <?= (($_GET['gender'] ?? '')=='Male')?'selected':'' ?>>Male</option>
<option value="Female" <?= (($_GET['gender'] ?? '')=='Female')?'selected':'' ?>>Female</option>
<option value="Other" <?= (($_GET['gender'] ?? '')=='Other')?'selected':'' ?>>Other</option>
</select>
<!-- MARITAL -->
<select name="marital_status" onchange="this.form.submit()">
<option value="">Marital</option>
<option value="UnMarried" <?= (($_GET['marital_status'] ?? '')=='UnMarried')?'selected':'' ?>>UnMarried</option>
<option value="Married" <?= (($_GET['marital_status'] ?? '')=='Married')?'selected':'' ?>>Married</option>
<option value="Divorced" <?= (($_GET['marital_status'] ?? '')=='Divorced')?'selected':'' ?>>Divorced</option>
</select>
<!-- CITY -->
<select name="city_id" onchange="this.form.submit()">
<option value="">Cities</option>
<?php while($city=mysqli_fetch_assoc($cityRes)): ?>
<option value="<?= $city['id'] ?>"
<?= (($_GET['city_id'] ?? '')==$city['id'])?'selected':'' ?>>
<?= htmlspecialchars($city['name']) ?>
</option>
<?php endwhile; ?>
</select>
<!-- DATE FROM -->
<input type="date"
name="date_from"
value="<?= $_GET['date_from'] ?? '' ?>"
onchange="this.form.submit()">
<!-- DATE TO -->
<input type="date"
name="date_to"
value="<?= $_GET['date_to'] ?? '' ?>"
onchange="this.form.submit()">
<!-- RESET -->
<a href="?"
style="padding:6px 10px;background:#ef4444;color:#fff;border-radius:6px;text-decoration:none;">
X
</a>
</form>
        <!-- TABLE AREA -->
        <div class="table-wrap">
            <?php
            /* =========================
   BUILD FILTER CONDITIONS
========================= */
$where = [];
/* Gender */
if (!empty($_GET['gender'])) {
    $gender = mysqli_real_escape_string($conn,$_GET['gender']);
    $where[] = "pm.sex = '$gender'";
}
/* Marital Status */
if (!empty($_GET['marital_status'])) {
    $marital = mysqli_real_escape_string($conn,$_GET['marital_status']);
    $where[] = "pm.marital_status = '$marital'";
}
/* City */
if (!empty($_GET['city_id'])) {
    $cityId = (int)$_GET['city_id'];
    $where[] = "pm.city_id = $cityId";
}
/* Search by Mobile */
if (!empty($_GET['mobile'])) {
    $mobile = mysqli_real_escape_string($conn,$_GET['mobile']);
    $where[] = "pm.mobile LIKE '%$mobile%'";
}
/* Search by UHID */
if (!empty($_GET['uhid'])) {
    $uhid = mysqli_real_escape_string($conn,$_GET['uhid']);
    $where[] = "pm.uhid LIKE '%$uhid%'";
}
/* Date From */
if (!empty($_GET['date_from'])) {
    $dateFrom = mysqli_real_escape_string($conn,$_GET['date_from']);
    $where[] = "DATE(pm.created_at) >= '$dateFrom'";
}
/* Date To */
if (!empty($_GET['date_to'])) {
    $dateTo = mysqli_real_escape_string($conn,$_GET['date_to']);
    $where[] = "DATE(pm.created_at) <= '$dateTo'";
}
$whereSql = $where ? "WHERE ".implode(" AND ",$where) : "";
$query = "
SELECT 
    pm.id,
    pm.uhid,
    pm.title,
    pm.first_name,
    pm.last_name,
    pm.marital_status,
    pm.sex,
    pm.guardian_name,
    pm.aadhaar,
    pm.abha_id,
    pm.mobile,
    pm.phone,
    pm.email,
    pm.dob,
    c.name AS city_name,
    pm.pincode,
    pm.address,
    pm.created_at,
    COUNT(a.id) AS total_visits,
MAX(a.appointment_date) AS last_visited,
MAX(CASE WHEN ia.status = 'Admitted' THEN 1 ELSE 0 END) AS is_admitted
FROM patient_master pm
LEFT JOIN cities c 
    ON c.id = pm.city_id  
LEFT JOIN appointments a 
    ON a.patient_id = pm.id
    LEFT JOIN ipd_admissions ia
    ON ia.patient_id = pm.id
$whereSql
GROUP BY pm.id
ORDER BY pm.id DESC
";
$result = mysqli_query($conn, $query);
?>
<table border="1" width="100%" cellpadding="8" cellspacing="0" style="min-width:1500px;">
    <thead style="background:#f1f5f9;">
        <tr>
            <th>Action</th>
            <th>UHID</th>
            <th>ABHA ID</th>
            <th>Patient Name</th>
            <th>Marital</th>
            <th>Gender</th>
            <th>Guardian</th>
            <th>Aadhaar</th>
            <th>Mobile</th>
            <th>Phone</th>
            <th>Email</th>
            <th>DOB</th>
            <th>City</th>
            <th>Pincode</th>
            <th>Address</th>
            <th>Last Visited</th>
            <th>Total Visits</th>
            <th>Registered On</th>
        </tr>
    </thead>
    <tbody>
        <?php while($row = mysqli_fetch_assoc($result)) {
    $rowClass = '';
    if ((int)$row['total_visits'] > 1) {
        $rowClass = 'multiple-visits';
    } elseif ((int)$row['total_visits'] == 1) {
        $rowClass = 'new-patient';
    }
?>
    <tr class="<?= $rowClass ?>">
               <td>
    <div class="action-menu">
        <button class="action-btn"
                onclick="toggleActionMenu(this)">
            ⋮
        </button>

        <div class="action-dropdown">
            <a href="javascript:void(0)"
               onclick="viewPatient(<?= $row['id'] ?>)">
                👁️ View
            </a>

            <?php if (checkPermission('patient_registration','edit')) { ?>
<a href="javascript:void(0)"
   onclick="editPatient(<?= $row['id'] ?>)">
    ✏️ Edit
</a>
<?php } ?>

            <?php if (checkPermission('patient_registration','add') && (int)$row['is_admitted'] !== 1) { ?>
<a href="javascript:void(0)"
   onclick="revisitPatient(<?= $row['id'] ?>)">
    🔄 Revisit
</a>
<?php } ?>
        </div>
    </div>
</td>
                <td><?php echo $row['uhid']; ?></td>
                <td><?php echo $row['abha_id']; ?></td>
                <td><?= $row['title'].' '.$row['first_name'].' '.$row['last_name'] ?></td>
                <td><?php echo $row['marital_status']; ?></td>
                <td><?php echo $row['sex']; ?></td>
                <td><?php echo $row['guardian_name']; ?></td>
                <td><?php echo $row['aadhaar']; ?></td>
                <td><?php echo $row['mobile']; ?></td>
                <td><?php echo $row['phone']; ?></td>
                <td><?php echo $row['email']; ?></td>
                <td><?php echo $row['dob']; ?></td>
                <td><?php echo $row['city_name'] ?? '-'; ?></td>
                <td><?php echo $row['pincode']; ?></td>
                <td><?php echo $row['address']; ?></td>
                <td><?php echo $row['last_visited'] ?? '-'; ?></td>
                <td><?php echo $row['total_visits']; ?></td>
                <td><?php echo $row['created_at']; ?></td>
            </tr>
        <?php } ?>
    </tbody>
</table>
        </div>
    </div>
</div>
<!-- FULL PAGE REGISTER FORM MODAL -->
<div id="registerPopup" style="
    display:none;
    position:fixed;
    top:0; left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
    z-index:99999;
">
    <div style="
        width:100%;
        height:100%;
        background:#fff;
        position:relative;
        overflow:hidden;
    ">
        <button type="button"
    onclick="closeRegisterPopup()"
    aria-label="Close"
    title="Close"
    style="
        position:absolute;
        top:6px;
        right:33px;
        z-index:10;
        width:36px;
        height:36px;
        display:flex;
        align-items:center;
        justify-content:center;
        padding:0;
        border:1px solid rgba(255,255,255,.35);
        border-radius:50%;
        background:rgba(255,255,255,.14);
        color:#fff;
        font-size:20px;
        font-weight:400;
        line-height:1;
        cursor:pointer;
        box-shadow:0 2px 8px rgba(0,0,0,.12);
        transition:all .18s ease;
    "
    onmouseover="this.style.background='rgba(220,38,38,.95)';this.style.borderColor='rgba(255,255,255,.6)';this.style.transform='scale(1.06)'"
    onmouseout="this.style.background='rgba(255,255,255,.14)';this.style.borderColor='rgba(255,255,255,.35)';this.style.transform='scale(1)'">
    ✕
</button>
        <!-- IFRAME -->
        <iframe id="registerFrame"
            src=""
            style="width:100%; height:100%; border:none;">
        </iframe>
    </div>
</div>
<!-- FULL PAGE VIEW PATIENT POPUP -->
<div id="patientDetailPopup" style="
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
    z-index:99999;
">
    <div style="
        width:100%;
        height:100%;
        background:#fff;
        position:relative;
        overflow:hidden;
    ">

        <button type="button"
            onclick="closePatientPopup()"
            aria-label="Close"
            title="Close"
            style="
                position:absolute;
                top:6px;
                right:33px;
                z-index:10;
                width:36px;
                height:36px;
                display:flex;
                align-items:center;
                justify-content:center;
                padding:0;
                border:1px solid rgba(255,255,255,.35);
                border-radius:50%;
                background:rgba(255,255,255,.14);
                color:#fff;
                font-size:20px;
                font-weight:400;
                line-height:1;
                cursor:pointer;
                box-shadow:0 2px 8px rgba(0,0,0,.12);
                transition:all .18s ease;
            "
            onmouseover="this.style.background='rgba(220,38,38,.95)';this.style.borderColor='rgba(255,255,255,.6)';this.style.transform='scale(1.06)'"
            onmouseout="this.style.background='rgba(255,255,255,.14)';this.style.borderColor='rgba(255,255,255,.35)';this.style.transform='scale(1)'">
            ✕
        </button>

        <div id="patientDetailContent"></div>

    </div>
</div>
<script>
function viewPatient(id) {
    fetch('/modules/registration/ajax/get_patient_full_details.php?id=' + id)
        .then(res => res.text())
        .then(html => {
            document.getElementById('patientDetailContent').innerHTML = html;
            document.getElementById('patientDetailPopup').style.display = 'block';
        });
}
function closePatientPopup(){
    document.getElementById('patientDetailPopup').style.display = 'none';
}
</script>
<script>
function editPatient(id)
{
    document.getElementById('registerFrame').src =
        '/modules/registration/edit_patient.php?id=' + id;
    document.getElementById('registerPopup').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
</script>
<!-- =========================
     SCRIPTS
========================= -->
<script>
function openRegisterPopup() {
    document.getElementById('registerFrame').src =
        '/modules/registration/register.php';
    document.getElementById('registerPopup').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeRegisterPopup() {
    document.getElementById('registerFrame').src = '';
    document.getElementById('registerPopup').style.display = 'none';
    document.body.style.overflow = '';
}
/* Sidebar Toggle */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('collapsed');
}
/* Action Menu */
function toggleActionMenu(btn)
{
    document.querySelectorAll('.action-dropdown').forEach(menu => {
        if(menu !== btn.nextElementSibling){
            menu.style.display = 'none';
        }
    });

    const menu = btn.nextElementSibling;

    menu.style.display =
        menu.style.display === 'block'
            ? 'none'
            : 'block';
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('.action-menu')) {
        document.querySelectorAll('.action-dropdown').forEach(d => {
            d.style.display = 'none';
        });
    }
});
function revisitPatient(patientId)
{
    document.getElementById('registerFrame').src =
        '/modules/registration/register.php?revisit_patient_id=' + patientId;

    document.getElementById('registerPopup').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
</script>
</body>
</html>