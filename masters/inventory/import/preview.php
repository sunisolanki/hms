    <?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
if(empty($_FILES['excel_file']['name'])){
    die("No file selected");
}
/* =========================
UPLOAD
========================= */
$file = $_FILES['excel_file']['tmp_name'];
$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet()->toArray();
/* REMOVE HEADER */
unset($sheet[0]);
/* =========================
MASTER ARRAYS
========================= */
function getMasterArray($conn,$table,$field){
    $arr = [];
    $res = $conn->query("
    SELECT $field
    FROM $table
    ");
    while($r = $res->fetch_assoc()){
        $arr[strtolower(trim($r[$field]))] = $r[$field];
    }
    return $arr;
}
$categories = getMasterArray($conn,'category_master','category_name');

$stores = getMasterArray($conn,'stores','store_name');

$salts = getMasterArray($conn,'salts_master','salt_name');

$parties = getMasterArray($conn,'party_master','party_name');
$hsn = getMasterArray($conn,'hsn_master','hsn_code');
/* =========================
VALIDATION
========================= */
$previewData = [];
foreach($sheet as $key => $row){
    /* SKIP SAMPLE / HELP ROWS */

if(
    strtolower(trim($row[0] ?? '')) == 'paracetamol 500'
    || strtolower(trim($row[0] ?? '')) == 'allowed values reference'
    || strtolower(trim($row[0] ?? '')) == 'item group'
){
    continue;
}
    if(empty(array_filter($row))){
        continue;
    }
    $errors = [];
/* COLUMNS */
    $item_name       = trim($row[0] ?? '');
    $category        = trim($row[1] ?? '');
    $sub_category    = trim($row[2] ?? '');
    $item_group      = trim($row[3] ?? '');
    $item_type       = trim($row[4] ?? '');
    $store           = trim($row[5] ?? '');
    $packing         = trim($row[6] ?? '');
    $max_level       = trim($row[7] ?? '');
    $min_level       = trim($row[8] ?? '');
    $reorder_level   = trim($row[9] ?? '');
    $salt            = trim($row[10] ?? '');
    $manufacturer    = trim($row[11] ?? '');
    $vendor          = trim($row[12] ?? '');
    $hsn_code        = trim($row[13] ?? '');
    $risk_type       = trim($row[14] ?? '');
    $high_risk       = trim($row[15] ?? '');
    $discount        = trim($row[16] ?? '');
    $discount_date   = trim($row[17] ?? '');
    $status          = trim($row[18] ?? '');
    /* REQUIRED */
    if($item_name==''){
        $errors['item_name'] = 'Required';
    }
    if($hsn_code==''){
        $errors['hsn_code'] = 'Required';
    }
    /* VALIDATIONS */
    if($category==''){
    $errors['category'] = 'Category Required';
}
else if(!isset($categories[strtolower($category)])){
    $errors['category'] = 'Invalid Category';
}
    if($store==''){
    $errors['store'] = 'Store Required';
}
else if(!isset($stores[strtolower($store)])){
    $errors['store'] = 'Invalid Store';
}
if($packing==''){
    $errors['packing'] = 'Packing Required';
}
    if($salt!='' &&
    !isset($salts[strtolower($salt)])){
        $errors['salt'] = 'Invalid Salt';
    }
    if($manufacturer!='' &&
    !isset($parties[strtolower($manufacturer)])){
        $errors['manufacturer'] = 'Invalid Manufacturer';
    }
    if($vendor==''){
    $errors['vendor'] = 'Vendor Required';
}
else if(!isset($parties[strtolower($vendor)])){
    $errors['vendor'] = 'Invalid Vendor';
}
if($status==''){
    $errors['status'] = 'Status Required';
}
if($hsn_code==''){
    $errors['hsn_code'] = 'HSN Required';
}
else if(!isset($hsn[strtolower($hsn_code)])){
    $errors['hsn_code'] = 'Invalid HSN';
}
    $previewData[] = [
        'data' => $row,
        'errors' => $errors
    ];
}
?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<!DOCTYPE html>
<html>
<head>
<title>Preview Import</title>
<link rel="stylesheet"
href="/assets/css/style.css">
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.preview-table{
    width:100%;
    border-collapse:collapse;
}
.preview-table th,
.preview-table td{
    border:1px solid #ddd;
    font-size:13px;
}
.preview-table th{
    background:#f5f5f5;
}
.error-field{
    background:#ffe5e5;
    border:1px solid red;
}
.error-text{
    color:red;
    font-size:11px;
    display:block;
    margin-top:4px;
}
.remove-btn{
    background:red;
    color:#fff;
    border:none;
    border-radius:50%;
    width:28px;
    height:28px;
    cursor:pointer;
}
.table-wrapper{
    overflow:auto;
    max-height:580px;
}
.top-actions{
    display:flex;
    gap:10px;
}
.breadcrumb span{
    color:#555;
}
</style>
</head>
<body>
<div class="page-container">
    <!-- BREADCRUMB -->
<div class="breadcrumb">
<a href="/settings/settings.php">Settings</a>
<span> > </span>
<a href="/masters/inventory/inventory_master.php">Inventory Masters</a>
<span> > </span>
<a href="/masters/inventory/item_master.php">Item Master</a>
<span> > </span>
<a href="/masters/inventory/import/index.php">Import</a>
<span> > </span>
<span>Preview</span>
</div>
<h2>Import Preview</h2>
<form id="importForm">
<div class="top-actions">
<button type="button"
class="btn btn-success"
onclick="saveImport()">
<i class="fa-solid fa-check"></i>
Save Valid Rows
</button>
<a href="index.php"
class="btn">
Cancel
</a>
</div>
<div class="table-wrapper">
<table class="preview-table">
<thead>
<tr>
<th>#</th>
<th>Action</th>
<th>Item Name</th>
<th>Category</th>
<th>Sub Category</th>
<th>Item Group</th>
<th>Item Type</th>
<th>Store</th>
<th>Packing</th>
<th>Max</th>
<th>Min</th>
<th>Reorder</th>
<th>Salt</th>
<th>Manufacturer</th>
<th>Vendor</th>
<th>HSN</th>
<th>Risk</th>
<th>High Risk</th>
<th>Discount</th>
<th>Discount Date</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach($previewData as $i => $rowData):
$row = $rowData['data'];
$errors = $rowData['errors'];
?>
<tr id="row<?= $i ?>">
<td><?= $i+1 ?></td>
<td>
<button type="button"
class="remove-btn"
onclick="removeRow(<?= $i ?>)">
×
</button>
</td>
<?php
$fieldNames = [
'item_name',
'category',
'sub_category',
'item_group',
'item_type',
'store',
'packing',
'max',
'min',
'reorder',
'salt',
'manufacturer',
'vendor',
'hsn_code',
'risk',
'high_risk',
'discount',
'discount_date',
'status'
];
foreach($fieldNames as $k => $field){
$val = isset($row[$k]) ? $row[$k] : '';
?>
<td>
<?php
$cls = isset($errors[$field]) ? 'error-field' : '';
/* CATEGORY */
if($field=='category'){
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:160px;">
<option value="">Select</option>
<?php foreach($categories as $v){ ?>
<option value="<?= $v ?>"
<?= strtolower($val ?? '')==strtolower($v) ? 'selected' : '' ?>>
<?= $v ?>
</option>
<?php } ?>
</select>
<?php
}
/* SUB CATEGORY */
else if($field=='sub_category'){
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:160px;">
<option value="">Select</option>
<?php foreach($categories as $v){ ?>
<option value="<?= $v ?>"
<?= strtolower($val ?? '')==strtolower($v) ? 'selected' : '' ?>>
<?= $v ?>
</option>
<?php } ?>
</select>
<?php
}
/* ITEM GROUP */
else if($field=='item_group'){
$groups = ['Medicine','Equipment','Other'];
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:140px;">
<option value="">Select</option>
<?php foreach($groups as $g){ ?>
<option value="<?= $g ?>"
<?= strtolower($val ?? '')==strtolower($g) ? 'selected' : '' ?>>
<?= $g ?>
</option>
<?php } ?>
</select>
<?php
}
/* ITEM TYPE */
else if($field=='item_type'){
$types = ['Capex','Non Capex'];
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:140px;">
<option value="">Select</option>
<?php foreach($types as $g){ ?>
<option value="<?= $g ?>"
<?= strtolower($val ?? '')==strtolower($g) ? 'selected' : '' ?>>
<?= $g ?>
</option>
<?php } ?>
</select>
<?php
}
/* STORE */
else if($field=='store'){
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:160px;">
<option value="">Select</option>
<?php foreach($stores as $v){ ?>
<option value="<?= $v ?>"
<?= strtolower($val ?? '')==strtolower($v) ? 'selected' : '' ?>>
<?= $v ?>
</option>
<?php } ?>
</select>
<?php
}
/* SALT */
else if($field=='salt'){
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:160px;">
<option value="">Select</option>
<?php foreach($salts as $v){ ?>
<option value="<?= $v ?>"
<?= strtolower($val ?? '')==strtolower($v) ? 'selected' : '' ?>>
<?= $v ?>
</option>
<?php } ?>
</select>
<?php
}
/* MANUFACTURER/VENDOR */
else if($field=='manufacturer' || $field=='vendor'){
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:180px;">
<option value="">Select</option>
<?php foreach($parties as $v){ ?>
<option value="<?= $v ?>"
<?= strtolower($val ?? '')==strtolower($v) ? 'selected' : '' ?>>
<?= $v ?>
</option>
<?php } ?>
</select>
<?php
}
/* HSN */
else if($field=='hsn_code'){
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:140px;">
<option value="">Select</option>
<?php foreach($hsn as $v){ ?>
<option value="<?= $v ?>"
<?= strtolower($val ?? '')==strtolower($v) ? 'selected' : '' ?>>
<?= $v ?>
</option>
<?php } ?>
</select>
<?php
}
/* RISK */
else if($field=='risk'){
$risk = ['H1','H','X1'];
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:120px;">
<option value="">Select</option>
<?php foreach($risk as $r){ ?>
<option value="<?= $r ?>"
<?= strtolower($val ?? '')==strtolower($r) ? 'selected' : '' ?>>
<?= $r ?>
</option>
<?php } ?>
</select>
<?php
}
/* HIGH RISK */
else if($field=='high_risk'){
$hr = ['Yes','No'];
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:120px;">
<option value="">Select</option>
<?php foreach($hr as $r){ ?>
<option value="<?= $r ?>"
<?= strtolower($val ?? '')==strtolower($r) ? 'selected' : '' ?>>
<?= $r ?>
</option>
<?php } ?>
</select>
<?php
}
/* STATUS */
else if($field=='status'){
$st = ['Active','Inactive'];
?>
<select
name="rows[<?= $i ?>][<?= $field ?>]"
class="<?= $cls ?>"
style="width:120px;">
<option value="">Select</option>
<?php foreach($st as $r){ ?>
<option value="<?= $r ?>"
<?= strtolower($val ?? '')==strtolower($r) ? 'selected' : '' ?>>
<?= $r ?>
</option>
<?php } ?>
</select>
<?php
}
/* NORMAL INPUT */
else{
?>
<input

name="rows[<?= $i ?>][<?= $field ?>]"

type="<?=
$field=='discount_date'
? 'date'
: (
    in_array($field,['packing','max','min','reorder','discount'])
    ? 'number'
    : 'text'
)
?>"

<?= $field=='discount' ? 'step="0.01"' : '' ?>

value="<?= htmlspecialchars($val) ?>"

class="<?= $cls ?>"

style="width:140px;">
<?php } ?>
<?php if(isset($errors[$field])){ ?>
<span class="error-text">
<?= $errors[$field] ?>
</span>
<?php } ?>
</td>
<?php } ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</form>
<script>
    async function saveImport(){
        /* REMOVE OLD ERRORS */
document
.querySelectorAll('.error-text')
.forEach(function(el){
    el.remove();
});
document
.querySelectorAll('.error-field')
.forEach(function(el){
    el.classList.remove('error-field');
});
        if(
    document.querySelectorAll(
        '.preview-table tbody tr'
    ).length==0
){
    alert('No rows remaining');
    return;
}
    let form = document.getElementById('importForm');
    let formData = new FormData(form);
    let res = await fetch(
        'ajax_save_import.php',
        {
            method:'POST',
            body:formData
        }
    );
    let data = await res.json();
    alert(
        'Saved: '+data.saved_count+
        '\nFailed: '+data.failed_count
    );
    if(data.success){
        data.success.forEach(function(id){
            let row =
            document.getElementById('row'+id);
            if(row){
                row.remove();
            }
        });
            refreshRowNumbers();
    }
    /* REMOVE OLD SERVER ERRORS */
document
.querySelectorAll('.server-error')
.forEach(el => el.remove());
/* SHOW FAILED ERRORS */
if(data.failed){
    for(let id in data.failed){
        let row =
        document.getElementById('row'+id);
        if(row){
            let firstTd =
row.querySelectorAll('td')[2];

            firstTd.innerHTML +=
            '<div class="server-error" style="color:red;font-size:11px;margin-top:5px;">'
            +data.failed[id]+
            '</div>';
        }
    }
}
}
function removeRow(id){
    let row =
    document.getElementById('row'+id);
    if(row){
        row.remove();
    }
    refreshRowNumbers();
}
function refreshRowNumbers(){
    document
    .querySelectorAll('.preview-table tbody tr')
    .forEach((tr,index)=>{
        tr.querySelector('td').innerHTML =
        index + 1;
    });
}
</script>
</body>
</html>