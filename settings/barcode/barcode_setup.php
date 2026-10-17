
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();


require_once $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

if (!checkPermission('barcode_setup','view')) {
    header('Location: /access_denied.php');
    exit;
}

require_once 'module_barcode_fields.php';

$edit_id = (int)($_GET['id'] ?? 0);

$data = [
    'hospital_id'       => '',
    'branch_id'         => '',
    'store_id'          => '',
    'module_id'         => '',
    'module_name'       => '',
    'barcode_enabled'   => 1,
    'barcode_type'      => 'CODE128',
    'barcode_prefix'    => '',
    'format_type'       => 'PREFIX_SEQ',
    'generation_scope'  => 'BRANCH',
    'label_width_mm'    => 75,
    'label_height_mm'   => 50,
    'next_sequence'     => 1,
    'fields_to_show'    => '[]',
    'status'            => 'Active'
];

if($edit_id){

    $q = mysqli_query($conn,"
        SELECT *
        FROM barcode_settings
        WHERE id='$edit_id'
        LIMIT 1
    ");

    if($q && mysqli_num_rows($q)){

        $data = mysqli_fetch_assoc($q);
    }
}

$selectedFields = [];

if(!empty($data['fields_to_show'])){

    $selectedFields = json_decode(
        $data['fields_to_show'],
        true
    );

    if(!is_array($selectedFields)){
        $selectedFields = [];
    }
}
/* HOSPITALS */
if((int)$_SESSION['rights_type_id'] === 1){
    $hospitalQuery = mysqli_query($conn,"
        SELECT id,hospital_name
        FROM hospital_master
        WHERE status=1
        ORDER BY hospital_name
    ");
}else{
    $user_id = (int)$_SESSION['user_id'];
    $hospitalQuery = mysqli_query($conn,"
        SELECT DISTINCT
            h.id,
            h.hospital_name
        FROM hospital_master h
        INNER JOIN user_hospitals uh
            ON uh.hospital_id=h.id
        WHERE uh.user_id='$user_id'
        AND h.status=1
        ORDER BY h.hospital_name
    ");
}
/* MODULES */
$moduleQuery = mysqli_query($conn,"
    SELECT
        id,
        name,
        slug
    FROM modules
    WHERE status='Active'
    ORDER BY name
");
/* LIST */
$listQuery = mysqli_query($conn,"
SELECT
    bs.*,
    h.hospital_name,
    b.branch_name,
    s.store_name,
    m.name AS module_title
FROM barcode_settings bs

LEFT JOIN hospital_master h
    ON h.id=bs.hospital_id

LEFT JOIN branch_master b
    ON b.id=bs.branch_id

LEFT JOIN stores s
    ON s.id=bs.store_id

LEFT JOIN modules m
    ON m.id=bs.module_id

ORDER BY bs.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Barcode Setup</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon"
type="image/png"
href="/assets/img/hms_logo.png">
<style>
input,
select,
textarea{
width:100%;
padding:7px;
border:1px solid #ccc;
border-radius:4px;
}
.checkbox-grid{
display:grid;
grid-template-columns:
repeat(auto-fill,minmax(250px,1fr));
gap:8px;
}
.checkbox-grid label{
display:flex;
align-items:center;
gap:8px;
padding:6px;
border:1px solid #ddd;
border-radius:5px;
}
.table-wrap{
overflow:auto;
margin-top:20px;
}
table{
width:100%;
border-collapse:collapse;
}
table th,
table td{
border:1px solid #ddd;
padding:8px;
}
table th{
background:#1976d2;
color:#fff;
}
.barcode-layout{
display:grid;
grid-template-columns:1fr 350px;
gap:20px;
margin-top:15px;
}

.field-card{
background:#fff;
border:1px solid #ddd;
border-radius:8px;
padding:15px;
}

.field-tags{
display:flex;
flex-wrap:wrap;
gap:8px;
}

.field-tags label{
display:flex;
align-items:center;
gap:5px;
padding:6px 10px;
border:1px solid #d1d5db;
border-radius:20px;
cursor:pointer;
background:#f8fafc;
font-size:13px;
}

.field-tags input{
width:auto !important;
}
</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<body>
<div class="top-bar">
<div class="breadcrumb">
<a href="/dashboard/index.php">Home</a>
<span>&gt;</span>
<a href="/settings/settings.php">Settings</a>
<span>&gt;</span>
<a href="#">Barcode Setup</a>
</div>
<h2 style="margin:0 auto;">
<?= $edit_id ? 'Edit' : 'Add' ?>
Barcode Setup
</h2>
</div>
<div class="user-wrapper">
<form method="post"
action="save_barcode_setup.php">
<input type="hidden"
name="id"
value="<?= $edit_id ?>">
<div class="grid">
<div>
<label>Organization</label>
<select
name="hospital_id"
id="hospital_id"
required>
<option value="">
Select Hospital
</option>
<?php
while($h=mysqli_fetch_assoc($hospitalQuery)){
?>
<option
value="<?= $h['id'] ?>"
<?= $data['hospital_id']==$h['id']
?'selected':'' ?>>
<?= htmlspecialchars(
$h['hospital_name']
) ?>
</option>
<?php } ?>
</select>
</div>
<div>
<label>Branch</label>
<select
name="branch_id"
id="branch_id">
<option value="">
Select Branch
</option>
</select>
</div>
<div>
<label>Store</label>
<select
name="store_id"
id="store_id">
<option value="">
Select Store
</option>
</select>
</div>
<div>
<label>Module</label>
<select
name="module_id"
id="module_id"
required>
<option value="">
Select Module
</option>
<?php
mysqli_data_seek($moduleQuery,0);
while($m=mysqli_fetch_assoc($moduleQuery)){
?>
<option
value="<?= $m['id'] ?>"
data-slug="<?= $m['slug'] ?>"
<?= $data['module_id']==$m['id']
?'selected':'' ?>>
<?= htmlspecialchars(
$m['name']
) ?>
</option>
<?php } ?>
</select>
</div>
<div>

<label>Barcode Enabled</label>

<select name="barcode_enabled">

<option value="1"
<?= $data['barcode_enabled']==1?'selected':'' ?>>
Yes
</option>

<option value="0"
<?= $data['barcode_enabled']==0?'selected':'' ?>>
No
</option>

</select>

</div>

<div>

<label>Barcode Type</label>
<select name="barcode_type">

<option value="CODE128"
<?= $data['barcode_type']=='CODE128' ? 'selected' : '' ?>>
Code 128
</option>

<option value="CODE39"
<?= $data['barcode_type']=='CODE39' ? 'selected' : '' ?>>
Code 39
</option>

<option value="QRCODE"
<?= $data['barcode_type']=='QRCODE' ? 'selected' : '' ?>>
QR Code
</option>

<option value="DATAMATRIX"
<?= $data['barcode_type']=='DATAMATRIX' ? 'selected' : '' ?>>
Data Matrix
</option>

<option value="GS1_DATAMATRIX"
<?= $data['barcode_type']=='GS1_DATAMATRIX' ? 'selected' : '' ?>>
GS1 DataMatrix
</option>

<option value="PDF417"
<?= $data['barcode_type']=='PDF417' ? 'selected' : '' ?>>
PDF417
</option>

<option value="EAN13"
<?= $data['barcode_type']=='EAN13' ? 'selected' : '' ?>>
EAN-13
</option>

<option value="UPCA"
<?= $data['barcode_type']=='UPCA' ? 'selected' : '' ?>>
UPC-A
</option>

<option value="GS1_128"
<?= $data['barcode_type']=='GS1_128' ? 'selected' : '' ?>>
GS1-128
</option>

</select>
<small id="barcodeTypeInfo" style="color:#666;"></small>
</div>

<div>

<label>Barcode Prefix</label>

<input
type="text"
name="barcode_prefix"
id="barcode_prefix"
value="<?= htmlspecialchars($data['barcode_prefix']) ?>">
</div>

<div>

<label>Format Type</label>

<select name="format_type">

<option value="PREFIX_SEQ"
<?= $data['format_type']=='PREFIX_SEQ'?'selected':'' ?>>
Prefix + Sequence
</option>

<option value="PREFIX_YEAR_SEQ"
<?= $data['format_type']=='PREFIX_YEAR_SEQ'?'selected':'' ?>>
Prefix + Year + Sequence
</option>

<option value="PREFIX_HOSPITAL_SEQ"
<?= $data['format_type']=='PREFIX_HOSPITAL_SEQ'?'selected':'' ?>>
Prefix + Hospital + Sequence
</option>

<option value="PREFIX_HOSPITAL_BRANCH_SEQ"
<?= $data['format_type']=='PREFIX_HOSPITAL_BRANCH_SEQ'?'selected':'' ?>>
Prefix + Hospital + Branch + Sequence
</option>
<option value="PREFIX_HOSPITAL_BRANCH_STORE_SEQ"
<?= $data['format_type']=='PREFIX_HOSPITAL_BRANCH_STORE_SEQ'?'selected':'' ?>>
Prefix + Hospital + Branch + Store + Sequence
</option>
</select>
</div>
<div>
<label>Generation Scope</label>
<select name="generation_scope">
<option value="GLOBAL"
<?= $data['generation_scope']=='GLOBAL'?'selected':'' ?>>
Global
</option>
<option value="HOSPITAL"
<?= $data['generation_scope']=='HOSPITAL'?'selected':'' ?>>
Hospital Wise
</option>
<option value="BRANCH"
<?= $data['generation_scope']=='BRANCH'?'selected':'' ?>>
Branch Wise
</option>
<option value="STORE"
<?= $data['generation_scope']=='STORE'?'selected':'' ?>>
Store Wise
</option>
</select>
</div>

<div>

<label>Label Width (MM)</label>

<input
type="number"
name="label_width_mm"
value="<?= $data['label_width_mm'] ?>">

</div>

<div>

<label>Label Height (MM)</label>

<input
type="number"
name="label_height_mm"
value="<?= $data['label_height_mm'] ?>">

</div>

<div>

<label>Next Sequence</label>

<input
type="number"
name="next_sequence"
value="<?= $data['next_sequence'] ?>">

</div>

<div>

<label>Status</label>

<select name="status">

<option value="Active"
<?= $data['status']=='Active'?'selected':'' ?>>
Active
</option>

<option value="Inactive"
<?= $data['status']=='Inactive'?'selected':'' ?>>
Inactive
</option>

</select>

</div>

</div>

<h3 style="margin-top:20px;">
Barcode Layout Designer
</h3>

<div class="barcode-layout">

<div class="field-card">

<h4>Select Fields</h4>

<div
id="barcodeFields"
class="field-tags">
</div>

</div>

<div class="field-card">

<h4>Live Preview</h4>

<div id="barcodePreview">

<div style="font-weight:bold">
ABC Hospital
</div>

<div>
Patient Name
</div>

<div>
UHID : 12345
</div>

<hr>

<div style="
font-family:monospace;
font-size:22px;
text-align:center;
">
||||||||||||||||||
</div>

<div style="
text-align:center;
font-weight:bold;
">
OPD000001
</div>

</div>

</div>

</div>
<div class="actions">

<button class="btn btn-success">

<?= $edit_id ? 'Update' : 'Save' ?>

</button>

<a
href="barcode_setup.php"
class="btn btn-secondary">

Reset

</a>

</div>

</form>

<div class="table-wrap">

<h3>
Existing Barcode Configurations
</h3>

<table>

<thead>

<tr>

<th>ID</th>
<th>Hospital</th>
<th>Branch</th>
<th>Store</th>
<th>Module</th>
<th>Prefix</th>
<th>Type</th>
<th>Scope</th>
<th>Width</th>
<th>Height</th>
<th>Status</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php
while($row=mysqli_fetch_assoc($listQuery)){
?>

<tr>

<td><?= $row['id'] ?></td>

<td><?= htmlspecialchars($row['hospital_name']) ?></td>

<td><?= htmlspecialchars($row['branch_name']) ?></td>

<td><?= htmlspecialchars((string)($row['store_name'] ?? '')) ?></td>

<td><?= htmlspecialchars($row['module_title']) ?></td>

<td><?= htmlspecialchars($row['barcode_prefix']) ?></td>

<td><?= htmlspecialchars($row['barcode_type']) ?></td>

<td><?= htmlspecialchars($row['generation_scope']) ?></td>

<td><?= $row['label_width_mm'] ?></td>

<td><?= $row['label_height_mm'] ?></td>

<td><?= $row['status'] ?></td>

<td>

<a
href="barcode_setup.php?id=<?= $row['id'] ?>"
class="btn btn-primary">

Edit

</a>

<?php if(checkPermission('barcode_setup','delete')){ ?>

<a
href="delete_barcode_setup.php?id=<?= $row['id'] ?>"
class="btn btn-danger"
onclick="return confirm('Delete configuration?')">

Delete

</a>

<?php } ?>

</td>

</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
<script>
const selectedFields =
<?= json_encode($selectedFields) ?>;

const barcodeFieldsMap =
<?= json_encode($barcodeModuleFields) ?>;
function loadFields(){
    const module =
    document.getElementById('module_id');
    const slug =
    module.options[module.selectedIndex]
    ?.dataset.slug;
    let html='';
    if(
        slug &&
        barcodeFieldsMap[slug]
    ){
        Object.entries(
            barcodeFieldsMap[slug]
        ).forEach(function(item){
            const key=item[0];
            const label=item[1];
            const checked =
selectedFields.includes(key)
? 'checked'
: '';
            html += `
<label>
<input
type="checkbox"
name="fields_to_show[]"
value="${key}"
${checked}
onchange="updatePreview()">
${label}
</label>
`;
        });
    }
    document.getElementById(
        'barcodeFields'
    ).innerHTML = html;
    updatePreview();
}
document
.getElementById('module_id')
.addEventListener(
    'change',
    loadFields
);
loadFields();
document
.getElementById('hospital_id')
.addEventListener(
'change',
function(){
    fetch(
    'get_branches.php?hospital_id='
    + this.value
    )
    .then(r=>r.text())
    .then(html=>{
        document.getElementById(
        'branch_id'
        ).innerHTML = html;
        updatePreview();
    });
});
document
.getElementById('branch_id')
.addEventListener(
'change',
function(){
    fetch(
    'get_stores.php?branch_id='
    + this.value
    )
    .then(r=>r.text())
    .then(html=>{
        document.getElementById(
        'store_id'
        ).innerHTML = html;
        updatePreview();
    });
});
function updatePreview(){

let preview='';

if(
document.querySelectorAll(
'input[name="fields_to_show[]"]:checked'
).length===0
){
preview=`
<div style="
text-align:center;
color:#999;
padding:20px;
">
Select fields to preview
</div>
`;
}
document
.querySelectorAll(
'input[name="fields_to_show[]"]:checked'
)
.forEach(function(el){
const sampleValues = {
hospital_name:'ABC Hospital',
branch_name:'Main Branch',
store_name:'Medical Store',
patient_name:'Rahul Sharma',
uhid:'UHID000123',
opd_no:'OPD000001',
doctor_name:'Dr. Sharma',
department_name:'Cardiology',
mobile:'9876543210',
date:'21-06-2026',
time:'05:30 PM',
barcode_no:'BC000001'
};

const fieldName = el.value;

preview += `
<div style="
padding:2px 0;
font-size:13px;
">
<b>${el.parentNode.innerText}</b> :
${sampleValues[fieldName] || ''}
</div>
`;
});
const barcodeType =
document.querySelector(
'select[name="barcode_type"]'
).value;

let barcodeHtml='';

switch(barcodeType){

case 'CODE128':

barcodeHtml = `
<div style="
font-family:monospace;
font-size:22px;
text-align:center;
letter-spacing:2px;
">
||||||||||||||||||
</div>
`;

break;

case 'CODE39':

barcodeHtml = `
<div style="
font-family:monospace;
font-size:24px;
text-align:center;
">
*OPD000001*
</div>
`;

break;

case 'QRCODE':

barcodeHtml = `
<div style="
width:120px;
height:120px;
margin:auto;
border:2px solid #000;
display:flex;
align-items:center;
justify-content:center;
font-size:12px;
">
QR CODE
</div>
`;

break;

case 'DATAMATRIX':

barcodeHtml = `
<div style="
width:90px;
height:90px;
margin:auto;
border:2px solid #000;
display:flex;
align-items:center;
justify-content:center;
font-size:11px;
">
DM
</div>
`;

break;

case 'GS1_DATAMATRIX':

barcodeHtml = `
<div style="
width:90px;
height:90px;
margin:auto;
border:2px solid #000;
display:flex;
align-items:center;
justify-content:center;
font-size:11px;
">
GS1 DM
</div>
`;

break;

case 'PDF417':

barcodeHtml = `
<div style="
height:50px;
border:2px solid #000;
margin:auto;
text-align:center;
padding-top:15px;
">
PDF417
</div>
`;

break;

case 'EAN13':

barcodeHtml = `
<div style="
font-family:monospace;
font-size:18px;
text-align:center;
">
5901234123457
</div>
`;

break;

case 'UPCA':

barcodeHtml = `
<div style="
font-family:monospace;
font-size:18px;
text-align:center;
">
123456789012
</div>
`;
break;
case 'GS1_128':
barcodeHtml = `
<div style="
font-family:monospace;
font-size:20px;
text-align:center;
">
(01)12345678901234
</div>
`;
break;
}
const prefix =
document.getElementById(
'barcode_prefix'
).value || 'OPD';
const formatType =
document.querySelector(
'select[name="format_type"]'
).value;
const hospitalText =
document.getElementById('hospital_id')
.options[
document.getElementById('hospital_id').selectedIndex
]?.text || '';

const branchText =
document.getElementById('branch_id')
.options[
document.getElementById('branch_id').selectedIndex
]?.text || '';

const storeText =
document.getElementById('store_id')
.options[
document.getElementById('store_id').selectedIndex
]?.text || '';
let previewBarcode = '';
switch(formatType){

case 'PREFIX_SEQ':

previewBarcode =
prefix + '000001';

break;

case 'PREFIX_YEAR_SEQ':

previewBarcode =
prefix + '2026' + '000001';

break;

case 'PREFIX_HOSPITAL_SEQ':

previewBarcode =
prefix +
hospitalText.substring(0,3).toUpperCase() +
'000001';

break;

case 'PREFIX_HOSPITAL_BRANCH_SEQ':

previewBarcode =
prefix +
hospitalText.substring(0,3).toUpperCase() +
branchText.substring(0,3).toUpperCase() +
'000001';

break;

case 'PREFIX_HOSPITAL_BRANCH_STORE_SEQ':

previewBarcode =
prefix +
hospitalText.substring(0,3).toUpperCase() +
branchText.substring(0,3).toUpperCase() +
storeText.substring(0,3).toUpperCase() +
'000001';

break;

default:

previewBarcode =
prefix + '000001';
}

preview += `
<hr>
${barcodeHtml}
<div style="
text-align:center;
font-weight:bold;
margin-top:8px;
">
${previewBarcode}
</div>
`;
document.getElementById(
'barcodePreview'
).innerHTML = preview;
const width =
document.querySelector('[name="label_width_mm"]').value || 75;

const height =
document.querySelector('[name="label_height_mm"]').value || 50;

document.getElementById(
'barcodePreview'
).style.width = width + 'mm';

document.getElementById(
'barcodePreview'
).style.minHeight = height + 'mm';

document.getElementById(
'barcodePreview'
).style.border = '1px solid #000';

document.getElementById(
'barcodePreview'
).style.padding = '10px';

document.getElementById(
'barcodePreview'
).style.background = '#fff';
}
document.getElementsByName('barcode_type')[0]
.addEventListener('change', function(){

updatePreview();

let txt='';

switch(this.value){

case 'CODE128':
txt='General purpose barcode for HMS.';
break;
case 'CODE39':
txt='Older barcode format.';
break;
case 'QRCODE':
txt='Stores large amount of data.';
break;
case 'DATAMATRIX':
txt='Small barcode used in healthcare.';
break;
case 'GS1_DATAMATRIX':
txt='Healthcare and pharmaceutical standard.';
break;
case 'PDF417':
txt='Used on IDs and documents.';
break;
case 'EAN13':
txt='Retail product barcode.';
break;
case 'UPCA':
txt='North America retail barcode.';
break;
case 'GS1_128':
txt='Supply chain and inventory barcode.';
break;
}
document.getElementById('barcodeTypeInfo').innerHTML = txt;
});
document.getElementById(
'barcode_prefix'
).addEventListener(
'input',
updatePreview
);
document.querySelector(
'select[name="format_type"]'
).addEventListener(
'change',
updatePreview
);
document.getElementById('hospital_id')
.addEventListener('change', updatePreview);

document.getElementById('branch_id')
.addEventListener('change', updatePreview);

document.getElementById('store_id')
.addEventListener('change', updatePreview);
document.querySelector(
'[name="label_width_mm"]'
).addEventListener(
'input',
updatePreview
);
document.querySelector(
'[name="label_height_mm"]'
).addEventListener('input', updatePreview);
document.getElementsByName('barcode_type')[0]
.dispatchEvent(new Event('change'));
</script>
<script>
const editHospitalId = '<?= $data['hospital_id'] ?>';
const editBranchId   = '<?= $data['branch_id'] ?>';
const editStoreId    = '<?= $data['store_id'] ?>';
function loadEditBranches(){
    if(!editHospitalId) return;
    fetch(
        'get_branches.php?hospital_id=' +
        editHospitalId
    )
    .then(r=>r.text())
    .then(html=>{
        document.getElementById(
            'branch_id'
        ).innerHTML = html;
        if(editBranchId){
            document.getElementById(
                'branch_id'
            ).value = editBranchId;
            loadEditStores();
        }
    });
}
function loadEditStores(){
    if(!editBranchId) return;
    fetch(
        'get_stores.php?branch_id=' +
        editBranchId
    )
    .then(r=>r.text())
    .then(html=>{
        document.getElementById(
            'store_id'
        ).innerHTML = html;
        if(editStoreId){
            document.getElementById(
                'store_id'
            ).value = editStoreId;
        }
        updatePreview();
    });
}
if(editHospitalId){
    loadEditBranches();
}
</script>
</body>
</html>