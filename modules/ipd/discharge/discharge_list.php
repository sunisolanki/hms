<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}

if(!checkPermission('ipd','view')){
    header("Location:/access_denied.php");
    exit;
}

$current_user_id=(int)$_SESSION['user_id'];

/* =====================================
   FILTERS
===================================== */

$where=[];

if(!empty($_GET['discharge_type'])){
    $discharge_type=mysqli_real_escape_string(
        $conn,
        $_GET['discharge_type']
    );

    $where[]="ds.discharge_type='$discharge_type'";
}

if(!empty($_GET['uhid'])){
    $uhid=mysqli_real_escape_string(
        $conn,
        $_GET['uhid']
    );

    $where[]="pm.uhid LIKE '%$uhid%'";
}

if(!empty($_GET['patient'])){
    $patient=mysqli_real_escape_string(
        $conn,
        $_GET['patient']
    );

    $where[]="
        CONCAT(
            COALESCE(pm.first_name,''),
            ' ',
            COALESCE(pm.last_name,'')
        ) LIKE '%$patient%'
    ";
}

if(!empty($_GET['mobile'])){
    $mobile=mysqli_real_escape_string(
        $conn,
        $_GET['mobile']
    );

    $where[]="pm.mobile LIKE '%$mobile%'";
}

if(!empty($_GET['ipd_no'])){
    $ipd_no=mysqli_real_escape_string(
        $conn,
        $_GET['ipd_no']
    );

    $where[]="ds.ipd_no LIKE '%$ipd_no%'";
}

if(!empty($_GET['date_from'])){
    $date_from=mysqli_real_escape_string(
        $conn,
        $_GET['date_from']
    );

    $where[]="ds.discharge_date>='$date_from'";
}

if(!empty($_GET['date_to'])){
    $date_to=mysqli_real_escape_string(
        $conn,
        $_GET['date_to']
    );

    $where[]="ds.discharge_date<='$date_to'";
}

$whereSql="";

if(count($where)){
    $whereSql="WHERE ".implode(" AND ",$where);
}

/* =====================================
   DISCHARGE LIST
===================================== */

$sql="
SELECT
    ds.id,
    ds.admission_id,
    ds.patient_id,
    ds.appointment_id,
    ds.ipd_no,
    ds.discharge_type,
    ds.discharge_date,
    ds.discharge_time,
    ds.final_diagnosis,
    ds.created_at,

    ia.payment_type,
    ia.admission_date,
    ia.admission_time,
    ia.status AS admission_status,

    pm.uhid,
    pm.title,
    pm.first_name,
    pm.last_name,
    pm.sex,
    pm.mobile,
    pm.age_y,
    pm.age_m,
    pm.age_d

FROM ipd_discharge_summaries ds

LEFT JOIN ipd_admissions ia
    ON ia.id=ds.admission_id

LEFT JOIN patient_master pm
    ON pm.id=ds.patient_id

$whereSql

ORDER BY
    ds.discharge_date DESC,
    ds.discharge_time DESC,
    ds.id DESC
";

$res=mysqli_query($conn,$sql);

if(!$res){
    die(
        'Database error: '.
        mysqli_error($conn)
    );
}

/* =====================================
   HELPERS
===================================== */

function e($value){
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function patientName($row){

    $name=trim(
        ($row['title'] ?? '').
        ' '.
        ($row['first_name'] ?? '').
        ' '.
        ($row['last_name'] ?? '')
    );

    return trim($name);
}

function formatDateValue($date){

    if(empty($date)){
        return '';
    }

    return date(
        'd-m-Y',
        strtotime($date)
    );
}

function formatDateTimeValue($date,$time=''){

    if(empty($date)){
        return '';
    }

    $value=date(
        'd-m-Y',
        strtotime($date)
    );

    if(!empty($time)){
        $value.=' '.date(
            'h:i A',
            strtotime($time)
        );
    }

    return $value;
}

function showDiagnosis($value){

    if(empty($value)){
        return '<span style="color:#999;">Not entered</span>';
    }

    $value=htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );

    return nl2br($value);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Discharge Summary</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<link
    rel="stylesheet"
    href="/assets/css/style.css"
>

<link
    rel="icon"
    href="/assets/img/hms_logo.png"
>

<style>

.main-content{
    width:auto;
    overflow:hidden;
}

.page-container{
    padding:8px;
}

body{
    overflow-x:hidden;
}

*{
    box-sizing:border-box;
}

/* =====================================
   PAGE HEADER
===================================== */

.page-header{
    display:flex;
    align-items:center;
    margin-bottom:5px;
}

.page-header-title{
    flex:1;
}

.page-header-title h2{
    margin:0;
}

/* =====================================
   FILTER BOX
===================================== */

.filter-box{
    background:#fff;
    padding:10px;
    border-radius:8px;
    margin-bottom:5px;
    box-shadow:0 2px 8px rgba(0,0,0,.08);
}

.filter-box form{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}

.filter-box select{
    width:125px;
    height:36px;
    padding:3px 8px;
    border:1px solid #d1d5db;
    border-radius:5px;
    background:#fff;
    font-size:13px;
}

.filter-box input[type=text]{
    width:125px;
    height:36px;
    padding:3px 8px;
    border:1px solid #d1d5db;
    border-radius:5px;
    font-size:13px;
}

.filter-box input[type=date]{
    width:150px;
    height:36px;
    padding:3px 8px;
    border:1px solid #d1d5db;
    border-radius:5px;
    font-size:13px;
}

.filter-box button{
    height:36px;
    padding:0 15px;
    border:1px solid #d1d5db;
    border-radius:5px;
    background:#1976d2;
    color:#fff;
    cursor:pointer;
}

.filter-box button.reset-btn{
    background:#6b7280;
}

/* =====================================
   TABLE
===================================== */

.table-wrap{
    background:#fff;
    border-radius:10px;
    padding:1px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    overflow:auto;
    width:100%;
    max-width:100%;
    height:calc(100vh - 180px);
}

table{
    width:100%;
    border-collapse:collapse;
    white-space:nowrap;
    min-width:1500px;
    font-size:13px;
}

th,
td{
    border:1px solid #e5e7eb;
    padding:6px 10px;
    text-align:left;
    vertical-align:middle;
}

th{
    background:#1976d2;
    color:#fff;
    position:sticky;
    top:0;
    z-index:2;
}

/* =====================================
   BADGES
===================================== */

.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:4px;
    color:#fff;
    font-size:12px;
}

.badge-normal{
    background:#16a34a;
}

.badge-lama{
    background:#f59e0b;
}

.badge-dama{
    background:#ea580c;
}

.badge-mlc{
    background:#7c3aed;
}

.badge-referred{
    background:#2563eb;
}

.badge-death{
    background:#dc2626;
}

/* =====================================
   ACTION MENU
===================================== */

.action-menu{
    position:relative;
}

.action-btn{
    background:#1976d2;
    color:#fff;
    border:none;
    cursor:pointer;
    border-radius:4px;
    padding:4px 8px;
    font-size:16px;
}

.action-dropdown{
    display:none;
    position:absolute;
    left:0;
    top:28px;
    min-width:190px;
    background:#fff;
    border:1px solid #ddd;
    border-radius:5px;
    box-shadow:0 5px 15px rgba(0,0,0,.15);
    z-index:999;
}

.action-dropdown a{
    display:block;
    padding:8px 10px;
    color:#111;
    text-decoration:none;
    font-size:13px;
}

.action-dropdown a:hover{
    background:#f5f5f5;
}

/* =====================================
   DIAGNOSIS
===================================== */

.diagnosis-cell{
    max-width:280px;
    white-space:normal;
    line-height:1.4;
}

/* =====================================
   EMPTY
===================================== */

.empty-row{
    text-align:center;
    padding:25px !important;
    color:#777;
}

/* =====================================
   RESPONSIVE
===================================== */

@media(max-width:700px){

    .filter-box form{
        align-items:stretch;
    }

    .filter-box select,
    .filter-box input[type=text],
    .filter-box input[type=date]{
        width:100%;
    }

    .filter-box button{
        width:100%;
    }

}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div
    class="main-content"
    id="mainContent"
    style="width:auto;"
>

<div class="page-container">

<!-- =====================================
     HEADER
===================================== -->

<div class="page-header">

    <div class="page-header-title">

        <h2>
            📄 Discharge Summary
        </h2>

    </div>

    <div>

        <button
            type="button"
            onclick="location.href='discharge_list.php'"
        >
            Refresh
        </button>

    </div>

</div>

<!-- =====================================
     FILTERS
===================================== -->

<div class="filter-box">

<form method="get">

<select name="discharge_type">

<option value="">
Discharge Type
</option>

<option
    value="Normal"
    <?=($_GET['discharge_type']??'')==='Normal'?'selected':''?>
>
Normal
</option>

<option
    value="LAMA"
    <?=($_GET['discharge_type']??'')==='LAMA'?'selected':''?>
>
LAMA
</option>

<option
    value="DAMA"
    <?=($_GET['discharge_type']??'')==='DAMA'?'selected':''?>
>
DAMA
</option>

<option
    value="MLC"
    <?=($_GET['discharge_type']??'')==='MLC'?'selected':''?>
>
MLC
</option>

<option
    value="Referred"
    <?=($_GET['discharge_type']??'')==='Referred'?'selected':''?>
>
Referred
</option>

<option
    value="Death"
    <?=($_GET['discharge_type']??'')==='Death'?'selected':''?>
>
Death
</option>

</select>

<input
    type="text"
    name="ipd_no"
    placeholder="IPD No"
    value="<?=e($_GET['ipd_no']??'')?>"
>

<input
    type="text"
    name="uhid"
    placeholder="UHID"
    value="<?=e($_GET['uhid']??'')?>"
>

<input
    type="text"
    name="patient"
    placeholder="Patient Name"
    value="<?=e($_GET['patient']??'')?>"
>

<input
    type="text"
    name="mobile"
    placeholder="Mobile"
    value="<?=e($_GET['mobile']??'')?>"
>

<input
    type="date"
    name="date_from"
    value="<?=e($_GET['date_from']??'')?>"
>

<input
    type="date"
    name="date_to"
    value="<?=e($_GET['date_to']??'')?>"
>

<button type="submit">
Search
</button>

<button
    type="button"
    class="reset-btn"
    onclick="window.location='discharge_list.php'"
>
Reset
</button>

</form>

</div>

<!-- =====================================
     TABLE
===================================== -->

<div class="table-wrap">

<div>

<table>

<thead>

<tr>

<th>
Action
</th>

<th>
ID
</th>

<th>
IPD No
</th>

<th>
UHID
</th>

<th>
Patient
</th>

<th>
Gender
</th>

<th>
Age
</th>

<th>
Mobile
</th>

<th>
Admission Date
</th>

<th>
Discharge Date
</th>

<th>
Discharge Type
</th>

<th>
Payment
</th>

<th>
Diagnosis
</th>

<th>
Created At
</th>

</tr>

</thead>

<tbody>

<?php if(mysqli_num_rows($res)==0){ ?>

<tr>

<td
    colspan="14"
    class="empty-row"
>
No Discharge Summaries Found
</td>

</tr>

<?php } ?>

<?php while($r=mysqli_fetch_assoc($res)){ ?>

<?php

$type=strtolower(
    trim(
        (string)$r['discharge_type']
    )
);

$badgeClass='badge-normal';

if($type==='lama'){
    $badgeClass='badge-lama';
}elseif($type==='dama'){
    $badgeClass='badge-dama';
}elseif($type==='mlc'){
    $badgeClass='badge-mlc';
}elseif($type==='referred'){
    $badgeClass='badge-referred';
}elseif($type==='death'){
    $badgeClass='badge-death';
}

$age='';

if((int)$r['age_y']>0){
    $age.=(int)$r['age_y'].'Y ';
}

if((int)$r['age_m']>0){
    $age.=(int)$r['age_m'].'M ';
}

if((int)$r['age_d']>0){
    $age.=(int)$r['age_d'].'D';
}

$age=trim($age);

if($age===''){
    $age='-';
}

?>

<tr>

<td>

<div class="action-menu">

<button
    type="button"
    class="action-btn"
    onclick="toggleActionMenu(this)"
>
⋮
</button>

<div class="action-dropdown">

<a
    href="print_discharge.php?discharge_id=<?=e($r['id'])?>"
    target="_blank"
>
🖨 Print Discharge Summary
</a>

<a
    href="print_discharge.php?discharge_id=<?=e($r['id'])?>"
    target="_blank"
>
👁 View Discharge Summary
</a>

</div>

</div>

</td>

<td>
<?=e($r['id'])?>
</td>

<td>
<?=e($r['ipd_no'])?>
</td>

<td>
<?=e($r['uhid'])?>
</td>

<td>

<strong>
<?=e(patientName($r))?>
</strong>

</td>

<td>
<?=e($r['sex'])?>
</td>

<td>
<?=e($age)?>
</td>

<td>
<?=e($r['mobile'])?>
</td>

<td>
<?=e(formatDateTimeValue(
    $r['admission_date'],
    $r['admission_time']
))?>
</td>

<td>
<?=e(formatDateTimeValue(
    $r['discharge_date'],
    $r['discharge_time']
))?>
</td>

<td>

<span class="badge <?=$badgeClass?>">

<?=e($r['discharge_type'])?>

</span>

</td>

<td>
<?=e($r['payment_type'] ?? '')?>
</td>

<td class="diagnosis-cell">

<?=showDiagnosis($r['final_diagnosis'])?>

</td>

<td>
<?=e($r['created_at'])?>
</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

</div>

<script>

function toggleActionMenu(btn){

    document
        .querySelectorAll('.action-dropdown')
        .forEach(function(menu){

            if(menu!==btn.nextElementSibling){
                menu.style.display='none';
            }

        });

    const current=btn.nextElementSibling;

    if(current.style.display==="block"){

        current.style.display="none";

    }else{

        current.style.display="block";

    }

}

document.addEventListener(
    "click",
    function(e){

        if(!e.target.closest(".action-menu")){

            document
                .querySelectorAll(".action-dropdown")
                .forEach(function(menu){

                    menu.style.display="none";

                });

        }

    }
);

</script>

</body>

</html>