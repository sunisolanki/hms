<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if(!isset($_SESSION['user_id'])){
header('Location:/index.php');
exit;
}
if(!checkPermission('masters','view')){
header('Location:/access_denied.php');
exit;
}
if(isset($_POST['save'])){
$hospital_id=$_POST['hospital_id'];
$branch_id=$_POST['branch_id'];
$store_id=$_POST['store_id'];
$inspection_required=
isset($_POST['inspection_required'])
?1:0;
$check=$conn->query("
SELECT id
FROM inventory_store_setup
WHERE hospital_id='$hospital_id'
AND branch_id='$branch_id'
AND store_id='$store_id'
LIMIT 1
");
if($check->num_rows>0){
$row=$check->fetch_assoc();
$conn->query("
UPDATE inventory_store_setup
SET inspection_required=
'$inspection_required'
WHERE id='".$row['id']."'
");
}
else{
$conn->query("
INSERT INTO inventory_store_setup(
hospital_id,
branch_id,
store_id,
inspection_required
) VALUES (
'$hospital_id',
'$branch_id',
'$store_id',
'$inspection_required'
)
");
}
$success=true;
}
$hospitals=$conn->query("
SELECT id,hospital_name
FROM hospital_master
ORDER BY hospital_name ASC
");
$setupData=$conn->query("
SELECT
iss.*,
hm.hospital_name,
bm.branch_name,
s.store_name
FROM inventory_store_setup iss
LEFT JOIN hospital_master hm
ON hm.id=iss.hospital_id
LEFT JOIN branch_master bm
ON bm.id=iss.branch_id
LEFT JOIN stores s
ON s.id=iss.store_id
ORDER BY iss.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>
Inventory Setup
</title>
<link rel="stylesheet"
href="/assets/css/style.css">
<link rel="icon"
type="image/png"
href="/assets/img/hms_logo.png">
<style>

.setup-card{
background:#fff;
padding:25px;
border-radius:14px;
box-shadow:0 4px 18px rgba(0,0,0,.08);
margin-bottom:20px;
}
.form-grid{
display:grid;
grid-template-columns:
repeat(auto-fit,minmax(220px,1fr));
gap:20px;
}
.form-group select{
width:100%;
padding:10px;
border:1px solid #ddd;
border-radius:8px;
background:#fff;
}
.checkbox-wrap{
display:flex;
align-items:center;
gap:10px;
margin-top:35px;
font-weight:600;
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="page-container">
<div class="breadcrumb"><a href="/dashboard/index.php">Home</a>
><a href="/settings/settings.php">Settings</a>
><a href="/masters/inventory/inventory_master.php">Inventory Masters</a>
><a>Inventory Setup</a>
</div>
<h2>⚙ Inventory Setup</h2>
<?php if(isset($success)){ ?>
<div class="success-msg">Setup Saved Successfully</div>
<?php } ?>
<div class="setup-card">
<form method="POST">
<div class="form-grid">
<div class="form-group">
<label>Hospital</label>
<select name="hospital_id" id="hospital_id" required>
<option value="">Select Hospital</option>
<?php while($h=$hospitals->fetch_assoc()){ ?> <option value="<?= $h['id'] ?>">
<?= htmlspecialchars(
$h['hospital_name']
) ?>
</option>
<?php } ?>
</select>
</div>
<div class="form-group">
<label> Branch </label>
<select name="branch_id" id="branch_id" required>
<option value=""> Select Branch </option>
</select>
</div>
<div class="form-group">
<label> Store </label>
<select name="store_id" id="store_id" required> <option value=""> Select Store </option> </select>
</div>
<div class="checkbox-wrap">
<label> <input type="checkbox" name="inspection_required" checked>Inspection Required </label></div>
</div>
<button type="submit" name="save" class="btn"> Save Setup </button> </div>
</form>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>ID</th>
<th>Hospital</th>
<th>Branch</th>
<th>Store</th>
<th>
Inspection Required
</th>
</tr>
</thead>
<tbody>
<?php while($row=$setupData->fetch_assoc()){ ?>
<tr>
<td><?= $row['id'] ?></td>
<td>
<?= htmlspecialchars(
$row['hospital_name']
) ?>
</td>
<td>
<?= htmlspecialchars(
$row['branch_name']
) ?>
</td>
<td>
<?= htmlspecialchars(
$row['store_name']
) ?>
</td>
<td>
<?php
if($row['inspection_required']==1){
echo 'Yes';
}
else{
echo 'No';
}
?>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>
<script>
document
.getElementById('hospital_id')
.addEventListener(
'change',
function(){
let hospitalId=this.value;
fetch(
'get_branches.php?hospital_id='
+hospitalId
)
.then(res=>res.json())
.then(data=>{
let html=
'<option value="">Select Branch</option>';
data.forEach(function(row){
html+=
'<option value="'+row.id+'">'
+row.branch_name+
'</option>';
});
document
.getElementById('branch_id')
.innerHTML=html;
});
}
);
document
.getElementById('branch_id')
.addEventListener(
'change',
function(){
let branchId=this.value;
fetch(
'get_stores.php?branch_id='
+branchId
)
.then(res=>res.json())
.then(data=>{
let html=
'<option value="">Select Store</option>';
data.forEach(function(row){
html+=
'<option value="'+row.id+'">'
+row.store_name+
'</option>';
});
document
.getElementById('store_id')
.innerHTML=html;
});
}
);
</script>
</body>
</html>