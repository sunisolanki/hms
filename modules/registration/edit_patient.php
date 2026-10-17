<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/abdm_helper.php';
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

/*
 * GLOBAL ABDM SWITCH
 *
 * Read once, server-side. When ABDM is disabled for this installation - or when
 * the setting cannot be read, in which case the switch fails closed - the
 * patient-level ABDM Yes/No card and the ABHA ID field are not rendered.
 *
 * The stored ABHA ID is never modified by this page: hiding a field is not the
 * same as clearing it, and update_patient.php separately refuses to erase an
 * existing ABHA ID when it is submitted blank.
 */
$abdmGlobalEnabled = abdmIsAbdmGloballyEnabled($conn);

if (!checkPermission('patient_registration', 'view')) {
    header('Location: /access_denied.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);

if($id <= 0){
    die("Invalid Patient ID");
}

$stmt = $conn->prepare("
    SELECT *
    FROM patient_master
    WHERE id=?
    LIMIT 1
");

$stmt->bind_param("i",$id);
$stmt->execute();

$result = $stmt->get_result();

if(!$patient = $result->fetch_assoc()){
    die("Patient not found");
}

$stmt->close();

$cities = mysqli_query($conn,"
    SELECT id,name
    FROM cities
    ORDER BY name
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Edit Patient</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

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
.card>form>.grid{
    margin-left:15px;
    margin-right:15px;
    margin-top:14px;
    margin-bottom:14px;
}

.card>form>.actions{
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
    max-height:35px;
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

.actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:15px;
    margin-bottom:15px;
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

@media(max-width:1100px){
    .grid{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:800px){
    .grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:560px){
    body{
        font-size:13px;
    }

    .container{
        padding:8px;
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
    }

    .card h3{
        padding:9px 12px;
        font-size:14px;
    }

    .card>.grid,
    .card>.actions,
    .card>div:not(h3){
        margin-left:10px;
        margin-right:10px;
    }

    .actions{
        flex-direction:column;
    }

    .save-appointment-btn{
        width:100%;
    }
}
</style>
</head>

<body>

<div class="container">

<div class="card">

<h3>
    <span>🧑</span>
    Patient Details
</h3>

<form method="post" action="update_patient.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

<input type="hidden"
       name="id"
       value="<?= $patient['id'] ?>">

<?php
/* Workflow flag only - NOT persisted, NOT a patient preference and NOT
   consent. Defaults to "Yes" when the patient already has an ABHA ID so
   that editing an ABHA-linked patient shows its existing value instead of
   hiding it. Hiding never clears the field; update_patient.php separately
   refuses to erase an existing ABHA ID on a blank submission. */
$abdmEditDefaultYes = !empty($patient['abha_id']);
?>

<!-- ================= ABDM INTEGRATION CHOICE ================= -->
<?php if ($abdmGlobalEnabled): ?>
<div class="grid">
<div>
    <label>ABDM Integration</label>
    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-top:6px;">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;">
            <input type="radio" name="abdm_integration" id="abdm_no" value="0" <?= $abdmEditDefaultYes ? '' : 'checked' ?>>
            <span>No</span>
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;">
            <input type="radio" name="abdm_integration" id="abdm_yes" value="1" <?= $abdmEditDefaultYes ? 'checked' : '' ?>>
            <span>Yes</span>
        </label>
    </div>
    <small style="display:block;margin-top:6px;color:#666;">
        Choosing &quot;Yes&quot; shows the existing ABHA ID. Leaving this on &quot;No&quot; does not
        remove any ABHA already recorded for this patient.
    </small>
</div>
</div>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>

<div class="grid">

<?php if ($abdmGlobalEnabled): ?>
<div id="abdm_abha_field">
<label>ABHA ID (ABDM)</label>
<input type="text"
       name="abha_id"
       value="<?= htmlspecialchars((string)($patient['abha_id'] ?? '')) ?>">
</div>
<?php endif; /* GLOBAL ABDM SWITCH */ ?>

<div>
<label>Title</label>
<select name="title">
<option value="Mr" <?= $patient['title']=='Mr'?'selected':'' ?>>Mr</option>
<option value="Mrs" <?= $patient['title']=='Mrs'?'selected':'' ?>>Mrs</option>
<option value="Miss" <?= $patient['title']=='Miss'?'selected':'' ?>>Miss</option>
<option value="Master" <?= $patient['title']=='Master'?'selected':'' ?>>Master</option>
<option value="Baby" <?= $patient['title']=='Baby'?'selected':'' ?>>Baby</option>
<option value="Dr" <?= $patient['title']=='Dr'?'selected':'' ?>>Dr</option>
</select>
</div>

<div>
<label>First Name <span class="required">*</span></label>
<input type="text"
       id="first_name"
       name="first_name"
       required
       value="<?= htmlspecialchars($patient['first_name']) ?>">
</div>

<div>
<label>Last Name</label>
<input type="text"
       name="last_name"
       value="<?= htmlspecialchars($patient['last_name']) ?>">
</div>

<div>
<label>Marital Status</label>
<select name="marital_status" id="marital">
<option value="UnMarried" <?= $patient['marital_status']=='UnMarried'?'selected':'' ?>>UnMarried</option>
<option value="Married" <?= $patient['marital_status']=='Married'?'selected':'' ?>>Married</option>
<option value="Divorced" <?= $patient['marital_status']=='Divorced'?'selected':'' ?>>Divorced</option>
</select>
</div>

<div>
<label>Gender</label>
<select name="sex" id="sex">
<option value="Male" <?= $patient['sex']=='Male'?'selected':'' ?>>Male</option>
<option value="Female" <?= $patient['sex']=='Female'?'selected':'' ?>>Female</option>
<option value="Other" <?= $patient['sex']=='Other'?'selected':'' ?>>Other</option>
</select>
</div>

<div>
<label id="guardian_label">Father / Mother Name</label>
<input type="text"
       name="guardian_name"
       value="<?= htmlspecialchars($patient['guardian_name']) ?>">
</div>

<div>
<label>Aadhaar No</label>
<input type="text"
       name="aadhaar"
       id="aadhaar"
       maxlength="12"
       minlength="12"
       pattern="[0-9]{12}"
       inputmode="numeric"
       value="<?= htmlspecialchars($patient['aadhaar']) ?>">
</div>

<div>
<label>Mobile <span class="required">*</span></label>
<input type="text"
       name="mobile"
       id="mobile"
       maxlength="10"
       pattern="[0-9]{10}"
       inputmode="numeric"
       required
       value="<?= htmlspecialchars($patient['mobile']) ?>">
</div>

<div>
<label>Phone</label>
<input type="text"
       name="phone"
       id="phone"
       maxlength="10"
       pattern="[0-9]{10}"
       inputmode="numeric"
       value="<?= htmlspecialchars($patient['phone']) ?>">
</div>

<div>
<label>Email</label>
<input type="email"
       name="email"
       id="email"
       value="<?= htmlspecialchars($patient['email']) ?>">
</div>

<div>
<label>Age (Y | M | D)</label>

<div class="inline">

<input type="number"
       id="age_y"
       name="age_y"
       placeholder="Y"
       value="<?= $patient['age_y'] ?>">

<input type="number"
       id="age_m"
       name="age_m"
       placeholder="M"
       value="<?= $patient['age_m'] ?>">

<input type="number"
       id="age_d"
       name="age_d"
       placeholder="D"
       value="<?= $patient['age_d'] ?>">

</div>
</div>

<div>
<label>Date of Birth <span class="required">*</span></label>

<input type="date"
       id="dob"
       name="dob"
       value="<?= $patient['dob'] ?>">
</div>

<div>
<label>City <span class="required">*</span></label>

<select name="city_id"
        id="city_id"
        required>

<option value="">Select City</option>

<?php while($city=mysqli_fetch_assoc($cities)): ?>

<option value="<?= $city['id'] ?>"
<?= $patient['city_id']==$city['id'] ? 'selected' : '' ?>>

<?= htmlspecialchars($city['name']) ?>

</option>

<?php endwhile; ?>

</select>

</div>

<div>
<label>Pincode</label>

<input type="text"
       name="pincode"
       value="<?= htmlspecialchars($patient['pincode']) ?>">

</div>

<div>
<label>Address</label>

<textarea name="address"><?= htmlspecialchars($patient['address']) ?></textarea>

</div>

</div>

<div class="actions">

<button type="submit"
        class="save-appointment-btn">

<span class="save-icon">💾</span>
<span>Update Patient</span>

</button>
</div>

</form>

</div>

</div>

<script>
let isUpdating=false;

function dobToAge(){

if(isUpdating)return;

const dob=document.querySelector('[name="dob"]');

if(!dob.value)return;

isUpdating=true;

const birth=new Date(dob.value);
const today=new Date();

let y=today.getFullYear()-birth.getFullYear();
let m=today.getMonth()-birth.getMonth();
let d=today.getDate()-birth.getDate();

if(d<0){
m--;
const previousMonth=new Date(
today.getFullYear(),
today.getMonth(),
0
);
d+=previousMonth.getDate();
}

if(m<0){
y--;
m+=12;
}

document.querySelector('[name="age_y"]').value=y;
document.querySelector('[name="age_m"]').value=m;
document.querySelector('[name="age_d"]').value=d;

isUpdating=false;
}

function ageToDob(){

if(isUpdating)return;

const y=parseInt(
document.querySelector('[name="age_y"]').value||0
);

const m=parseInt(
document.querySelector('[name="age_m"]').value||0
);

const d=parseInt(
document.querySelector('[name="age_d"]').value||0
);

if(y===0&&m===0&&d===0)return;

isUpdating=true;

let dt=new Date();

dt.setFullYear(dt.getFullYear()-y);
dt.setMonth(dt.getMonth()-m);
dt.setDate(dt.getDate()-d);

document.querySelector('[name="dob"]').value=
dt.toISOString().split('T')[0];

isUpdating=false;
}

document.querySelector('[name="dob"]')
.addEventListener('change',dobToAge);

document.querySelector('[name="age_y"]')
.addEventListener('input',ageToDob);

document.querySelector('[name="age_m"]')
.addEventListener('input',ageToDob);

document.querySelector('[name="age_d"]')
.addEventListener('input',ageToDob);

document.querySelector('[name="mobile"]')
.addEventListener('blur',function(){

if(this.value && !/^[0-9]{10}$/.test(this.value)){
alert('Enter valid 10 digit mobile number');
this.focus();
}

});

document.querySelector('[name="aadhaar"]')
.addEventListener('blur',function(){

if(this.value && !/^[0-9]{12}$/.test(this.value)){
alert('Aadhaar must be 12 digits');
this.focus();
}

});

document.getElementById('aadhaar')
.addEventListener('input',function(){
this.value=this.value.replace(/\D/g,'');
});

document.getElementById('mobile')
.addEventListener('input',function(){
this.value=this.value.replace(/\D/g,'');
});

document.getElementById('phone')
.addEventListener('input',function(){
this.value=this.value.replace(/\D/g,'');
});

document.querySelector('form')
.addEventListener('submit',function(e){

const aadhaar=document.getElementById('aadhaar').value.trim();
const mobile=document.getElementById('mobile').value.trim();
const phone=document.getElementById('phone').value.trim();
const email=document.getElementById('email').value.trim();

if(aadhaar!==''&&!/^\d{12}$/.test(aadhaar)){
alert('Aadhaar Number must be exactly 12 digits');
document.getElementById('aadhaar').focus();
e.preventDefault();
return;
}

if(!/^[0-9]{10}$/.test(mobile)){
alert('Mobile Number must be exactly 10 digits');
document.getElementById('mobile').focus();
e.preventDefault();
return;
}

if(phone!==''&&!/^[0-9]{10}$/.test(phone)){
alert('Phone Number must be exactly 10 digits');
document.getElementById('phone').focus();
e.preventDefault();
return;
}

if(email!==''&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
alert('Enter valid Email Address');
document.getElementById('email').focus();
e.preventDefault();
return;
}

});
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function(){

$('#city_id').select2({
placeholder:'Select City',
allowClear:true,
width:'100%'
});

$('#city_id').on('select2:open',function(){

setTimeout(function(){

document.querySelector(
'.select2-container--open .select2-search__field'
)?.focus();

},0);

});

});

/* ============================================================
   ABDM INTEGRATION TOGGLE (edit patient)
   Hides/shows the ABHA ID field only. style.display is used
   deliberately: the field stays in the DOM and its value is never
   cleared, so switching to "No" cannot discard an existing ABHA.
   ============================================================ */
<?php if ($abdmGlobalEnabled): ?>
function applyAbdmIntegrationVisibility() {
    var yes = document.getElementById('abdm_yes');
    var on = yes !== null && yes.checked === true;

    var el = document.getElementById('abdm_abha_field');
    if (el) { el.style.display = on ? '' : 'none'; }
}

document.addEventListener('DOMContentLoaded', function () {
    applyAbdmIntegrationVisibility();

    var no = document.getElementById('abdm_no');
    var yes = document.getElementById('abdm_yes');

    if (no) { no.addEventListener('change', applyAbdmIntegrationVisibility); }
    if (yes) { yes.addEventListener('change', applyAbdmIntegrationVisibility); }
});
<?php endif; /* GLOBAL ABDM SWITCH */ ?>
</script>

</body>
</html>