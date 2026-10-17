<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/* VIEW PERMISSION */
if (!checkPermission('disease_master','view')) {
    header('Location: /access_denied.php');
    exit;
}
/* DELETE */
if (isset($_GET['delete']) && checkPermission('disease_master','delete')) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $oldQry = mysqli_query($conn,"SELECT * FROM disease_master WHERE id=$id");
        $oldData = mysqli_fetch_assoc($oldQry);
        if ($oldData) {
            mysqli_query($conn,"DELETE FROM disease_master WHERE id=$id");
            addAuditLog(
                'Disease Master',
                'DELETE',
                'Disease Deleted : '.$oldData['disease_name'],
                $id,
                '',
                ''
            );
            $_SESSION['disease_success'] = 'Disease deleted successfully.';
        }
    }
    header("Location: disease_master.php");
    exit;
}
/* SAVE ADD / EDIT */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (
        ($id > 0 && !checkPermission('disease_master','edit')) ||
        ($id === 0 && !checkPermission('disease_master','add'))
    ) {
        header('Location: /access_denied.php');
        exit;
    }
    $diseaseName = trim($_POST['disease_name'] ?? '');
    $icdCode = trim($_POST['icd_code'] ?? '');
    $diseaseTypeId = (int)($_POST['disease_type_id'] ?? 0);
    $mainTypeId = (int)($_POST['main_type_id'] ?? 0);
    $subTypeId = (int)($_POST['sub_type_id'] ?? 0);
    $subSubTypeId = (int)($_POST['sub_sub_type_id'] ?? 0);
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
    $userId = (int)$_SESSION['user_id'];
    if ($diseaseName === '') {
        $_SESSION['disease_error'] = 'Disease Name is required.';
        header("Location: disease_master.php");
        exit;
    }
    /* VALIDATE HIERARCHY */
    if ($diseaseTypeId > 0) {
        $stmt = mysqli_prepare($conn,"
            SELECT id
            FROM disease_types
            WHERE id=?
            AND type_level='disease_type'
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt,'i',$diseaseTypeId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result)===0) {
            mysqli_stmt_close($stmt);
            $_SESSION['disease_error'] = 'Invalid Disease Type selected.';
            header("Location: disease_master.php");
            exit;
        }
        mysqli_stmt_close($stmt);
    }
    if ($mainTypeId > 0) {
        $stmt = mysqli_prepare($conn,"
            SELECT id
            FROM disease_types
            WHERE id=?
            AND type_level='main_type'
            AND parent_id=?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt,'ii',$mainTypeId,$diseaseTypeId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result)===0) {
            mysqli_stmt_close($stmt);
            $_SESSION['disease_error'] = 'Invalid Main Type selected.';
            header("Location: disease_master.php");
            exit;
        }
        mysqli_stmt_close($stmt);
    }
    if ($subTypeId > 0) {
        $stmt = mysqli_prepare($conn,"
            SELECT id
            FROM disease_types
            WHERE id=?
            AND type_level='sub_type'
            AND parent_id=?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt,'ii',$subTypeId,$mainTypeId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result)===0) {
            mysqli_stmt_close($stmt);
            $_SESSION['disease_error'] = 'Invalid Sub Type selected.';
            header("Location: disease_master.php");
            exit;
        }
        mysqli_stmt_close($stmt);
    }
    if ($subSubTypeId > 0) {
        $stmt = mysqli_prepare($conn,"
            SELECT id
            FROM disease_types
            WHERE id=?
            AND type_level='sub_sub_type'
            AND parent_id=?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt,'ii',$subSubTypeId,$subTypeId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result)===0) {
            mysqli_stmt_close($stmt);
            $_SESSION['disease_error'] = 'Invalid Sub Sub Type selected.';
            header("Location: disease_master.php");
            exit;
        }
        mysqli_stmt_close($stmt);
    }
    /* DUPLICATE DISEASE CHECK */
    $duplicateStmt = mysqli_prepare($conn,"
        SELECT id
        FROM disease_master
        WHERE disease_name=?
        AND id<>?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($duplicateStmt,'si',$diseaseName,$id);
    mysqli_stmt_execute($duplicateStmt);
    $duplicateResult = mysqli_stmt_get_result($duplicateStmt);
    if (mysqli_num_rows($duplicateResult)>0) {
        mysqli_stmt_close($duplicateStmt);
        $_SESSION['disease_error'] = 'This Disease Name already exists.';
        header("Location: disease_master.php");
        exit;
    }
    mysqli_stmt_close($duplicateStmt);
    /* OPTIONAL ICD DUPLICATE CHECK */
    if ($icdCode !== '') {
        $icdStmt = mysqli_prepare($conn,"
            SELECT id
            FROM disease_master
            WHERE icd_code=?
            AND id<>?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($icdStmt,'si',$icdCode,$id);
        mysqli_stmt_execute($icdStmt);
        $icdResult = mysqli_stmt_get_result($icdStmt);
        if (mysqli_num_rows($icdResult)>0) {
            mysqli_stmt_close($icdStmt);
            $_SESSION['disease_error'] = 'This ICD Code already exists.';
            header("Location: disease_master.php");
            exit;
        }
        mysqli_stmt_close($icdStmt);
    }
    /* CONVERT EMPTY IDs TO NULL */
    $diseaseTypeValue = $diseaseTypeId > 0 ? $diseaseTypeId : null;
    $mainTypeValue = $mainTypeId > 0 ? $mainTypeId : null;
    $subTypeValue = $subTypeId > 0 ? $subTypeId : null;
    $subSubTypeValue = $subSubTypeId > 0 ? $subSubTypeId : null;
    if ($id > 0) {
        $oldQry = mysqli_query($conn,"SELECT * FROM disease_master WHERE id=$id");
        $oldData = mysqli_fetch_assoc($oldQry);
        if (!$oldData) {
            $_SESSION['disease_error'] = 'Disease record not found.';
            header("Location: disease_master.php");
            exit;
        }
        $stmt = mysqli_prepare($conn,"
            UPDATE disease_master SET
                disease_name=?,
                icd_code=?,
                disease_type_id=?,
                main_type_id=?,
                sub_type_id=?,
                sub_sub_type_id=?,
                status=?,
                updated_at=NOW()
            WHERE id=?
        ");
        mysqli_stmt_bind_param(
            $stmt,
            'ssiiiiii',
            $diseaseName,
            $icdCode,
            $diseaseTypeValue,
            $mainTypeValue,
            $subTypeValue,
            $subSubTypeValue,
            $status,
            $id
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $oldValues = [];
        $newValues = [];
        if ($oldData['disease_name'] != $diseaseName) {
            $oldValues['disease_name'] = $oldData['disease_name'];
            $newValues['disease_name'] = $diseaseName;
        }
        if (($oldData['icd_code'] ?? '') != $icdCode) {
            $oldValues['icd_code'] = $oldData['icd_code'];
            $newValues['icd_code'] = $icdCode;
        }
        if ((int)$oldData['disease_type_id'] != $diseaseTypeId) {
            $oldValues['disease_type_id'] = $oldData['disease_type_id'];
            $newValues['disease_type_id'] = $diseaseTypeValue;
        }
        if ((int)$oldData['main_type_id'] != $mainTypeId) {
            $oldValues['main_type_id'] = $oldData['main_type_id'];
            $newValues['main_type_id'] = $mainTypeValue;
        }
        if ((int)$oldData['sub_type_id'] != $subTypeId) {
            $oldValues['sub_type_id'] = $oldData['sub_type_id'];
            $newValues['sub_type_id'] = $subTypeValue;
        }
        if ((int)$oldData['sub_sub_type_id'] != $subSubTypeId) {
            $oldValues['sub_sub_type_id'] = $oldData['sub_sub_type_id'];
            $newValues['sub_sub_type_id'] = $subSubTypeValue;
        }
        if ((int)$oldData['status'] != $status) {
            $oldValues['status'] = (int)$oldData['status']===1 ? 'Active' : 'Inactive';
            $newValues['status'] = $status===1 ? 'Active' : 'Inactive';
        }
        if (!empty($oldValues)) {
            addAuditLog(
                'Disease Master',
                'UPDATE',
                'Disease Updated : '.$diseaseName,
                $id,
                json_encode($oldValues),
                json_encode($newValues)
            );
        }
        $_SESSION['disease_success'] = 'Disease updated successfully.';
    } else {
        $stmt = mysqli_prepare($conn,"
            INSERT INTO disease_master
            (
                disease_name,
                icd_code,
                disease_type_id,
                main_type_id,
                sub_type_id,
                sub_sub_type_id,
                status,
                created_by
            )
            VALUES (?,?,?,?,?,?,?,?)
        ");
        mysqli_stmt_bind_param(
            $stmt,
            'ssiiiiii',
            $diseaseName,
            $icdCode,
            $diseaseTypeValue,
            $mainTypeValue,
            $subTypeValue,
            $subSubTypeValue,
            $status,
            $userId
        );
        mysqli_stmt_execute($stmt);
        $diseaseId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        addAuditLog(
            'Disease Master',
            'CREATE',
            'Disease Created : '.$diseaseName,
            $diseaseId,
            '',
            ''
        );
        $_SESSION['disease_success'] = 'Disease created successfully.';
    }
    header("Location: disease_master.php");
    exit;
}
/* FETCH DISEASE LIST */
$res = mysqli_query($conn,"
SELECT
    d.*,
    dt.type_name AS disease_type_name,
    mt.type_name AS main_type_name,
    st.type_name AS sub_type_name,
    sst.type_name AS sub_sub_type_name,
    u.full_name AS created_by_name
FROM disease_master d
LEFT JOIN disease_types dt
    ON dt.id=d.disease_type_id
LEFT JOIN disease_types mt
    ON mt.id=d.main_type_id
LEFT JOIN disease_types st
    ON st.id=d.sub_type_id
LEFT JOIN disease_types sst
    ON sst.id=d.sub_sub_type_id
LEFT JOIN users u
    ON u.id=d.created_by
ORDER BY d.id DESC
");
/* FETCH ACTIVE CLASSIFICATIONS */
$diseaseTypes = [];
$mainTypes = [];
$subTypes = [];
$subSubTypes = [];
$typeQry = mysqli_query($conn,"
SELECT
    id,
    parent_id,
    type_level,
    type_name
FROM disease_types
WHERE status=1
ORDER BY type_name ASC
");
while ($typeRow=mysqli_fetch_assoc($typeQry)) {
    if ($typeRow['type_level']==='disease_type') {
        $diseaseTypes[]=$typeRow;
    } elseif ($typeRow['type_level']==='main_type') {
        $mainTypes[]=$typeRow;
    } elseif ($typeRow['type_level']==='sub_type') {
        $subTypes[]=$typeRow;
    } elseif ($typeRow['type_level']==='sub_sub_type') {
        $subSubTypes[]=$typeRow;
    }
}
$successMessage=$_SESSION['disease_success'] ?? '';
$errorMessage=$_SESSION['disease_error'] ?? '';
unset($_SESSION['disease_success'],$_SESSION['disease_error']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Disease Master</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.header-actions{display:flex;gap:8px;align-items:center;}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#f1f1f1}
.btn{background:#2c7be5;color:#fff;padding:8px 14px;border:none;border-radius:5px;cursor:pointer;width:auto;max-width:fit-content;text-decoration:none;display:inline-block}
.btn:hover{background:#1a5fd0;color:#fff}
.btn-success{background:#28a745;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.btn-secondary{background:#6c757d;color:#fff}
.badge-success{background:#28a745;color:#fff;padding:3px 6px;border-radius:3px}
.badge-secondary{background:#6c757d;color:#fff;padding:3px 6px;border-radius:3px}
.action-buttons{display:flex;gap:6px;justify-content:center;}
.btn-icon{border:none;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:.2s;}
.btn-edit{background:#e8f5e9;color:#2e7d32;}
.btn-edit:hover{background:#2e7d32;color:#fff;}
.btn-delete{background:#fdecea;color:#c62828;}
.btn-delete:hover{background:#c62828;color:#fff;}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999}
.modal-box{background:#fff;width:650px;max-width:95%;margin:50px auto;padding:20px;border-radius:6px;max-height:85vh;overflow-y:auto}
.modal h3{margin-top:0}
label{font-weight:600;font-size:13px;margin-top:10px;display:block}
input,select{width:100%;padding:7px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box}
.actions{text-align:right;margin-top:15px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 15px}
.form-full{grid-column:1/-1}
.alert{padding:10px 14px;margin-bottom:15px;border-radius:5px;font-size:13px}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-danger{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
@media(max-width:700px){
    .form-grid{grid-template-columns:1fr}
    .form-full{grid-column:auto}
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Disease Master</a>
    </div>
    <h2 style="margin:0 auto;">Disease Master</h2>
    <div class="header-actions">
        <?php if (checkPermission('disease_master','add')) { ?>
        <button class="btn" onclick="openModal()">+ Add Disease</button>
        <?php } ?>
        <?php if (checkPermission('disease_master','view')) { ?>
        <a href="/masters/disease/disease_types.php" class="btn btn-secondary">
            <i class="fa-solid fa-sitemap"></i> Manage Types
        </a>
        <?php } ?>
    </div>
</div>
<div class="container">
<?php if ($successMessage!=='') { ?>
<div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
<?php } ?>
<?php if ($errorMessage!=='') { ?>
<div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
<?php } ?>
<table>
<thead>
<tr>
    <th>#</th>
    <th>Disease Name</th>
    <th>ICD Code</th>
    <th>Disease Type</th>
    <th>Main Type</th>
    <th>Sub Type</th>
    <th>Sub Sub Type</th>
    <th>Status</th>
    <th>Created By</th>
    <th>Created</th>
    <th>Updated</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php
$i=1;
if ($res && mysqli_num_rows($res)>0) {
    while($row=mysqli_fetch_assoc($res)) {
?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($row['disease_name']) ?></td>
    <td><?= htmlspecialchars($row['icd_code'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['disease_type_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['main_type_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['sub_type_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['sub_sub_type_name'] ?? '-') ?></td>
    <td>
        <?= (int)$row['status']===1
            ? '<span class="badge-success">Active</span>'
            : '<span class="badge-secondary">Inactive</span>' ?>
    </td>
    <td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A') ?></td>
    <td>
        <?= !empty($row['created_at'])
            ? date('d-M-Y h:i A',strtotime($row['created_at']))
            : '-' ?>
    </td>
    <td>
        <?= !empty($row['updated_at'])
            ? date('d-M-Y h:i A',strtotime($row['updated_at']))
            : '-' ?>
    </td>
    <td class="action-buttons">
        <?php if (checkPermission('disease_master','edit')) { ?>
        <button class="btn-icon btn-edit"
        onclick='editDisease(<?= htmlspecialchars(json_encode($row),ENT_QUOTES,"UTF-8") ?>)'>
            <i class="fa-solid fa-pen-to-square"></i>
        </button>
        <?php } ?>
        <?php if (checkPermission('disease_master','delete')) { ?>
        <button class="btn-icon btn-delete"
        onclick="confirmDelete(<?= (int)$row['id'] ?>)">
            <i class="fa-solid fa-trash"></i>
        </button>
        <?php } ?>
    </td>
</tr>
<?php
    }
} else {
?>
<tr>
    <td colspan="12" align="center">No records found</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<!-- ADD / EDIT MODAL -->
<div class="modal" id="diseaseModal">
<div class="modal-box">
<h3 id="modalTitle">Add Disease</h3>
<form method="post" id="diseaseForm">
<input type="hidden" name="id" id="id">
<div class="form-grid">
<div class="form-full">
<label>Disease Name *</label>
<input type="text" name="disease_name" id="disease_name" required maxlength="200">
</div>
<div>
<label>ICD Code</label>
<input type="text" name="icd_code" id="icd_code" maxlength="50">
</div>
<div>
<label>Disease Type</label>
<select name="disease_type_id" id="disease_type_id" onchange="loadMainTypes()">
    <option value="">Select Disease Type</option>
    <?php foreach ($diseaseTypes as $item) { ?>
    <option value="<?= (int)$item['id'] ?>">
        <?= htmlspecialchars($item['type_name']) ?>
    </option>
    <?php } ?>
</select>
</div>
<div>
<label>Main Type</label>
<select name="main_type_id" id="main_type_id" onchange="loadSubTypes()">
    <option value="">Select Main Type</option>
</select>
</div>
<div>
<label>Sub Type</label>
<select name="sub_type_id" id="sub_type_id" onchange="loadSubSubTypes()">
    <option value="">Select Sub Type</option>
</select>
</div>
<div>
<label>Sub Sub Type</label>
<select name="sub_sub_type_id" id="sub_sub_type_id">
    <option value="">Select Sub Sub Type</option>
</select>
</div>
<div>
<label>Status</label>
<select name="status" id="status">
    <option value="1">Active</option>
    <option value="0">Inactive</option>
</select>
</div>
</div>
<div class="actions">
    <button type="submit" class="btn btn-success">Save</button>
    <button type="button" class="btn btn-danger" onclick="closeModal()">Cancel</button>
</div>
</form>
</div>
</div>
<!-- DELETE CONFIRM -->
<div id="deleteConfirm" class="confirm-modal">
<div class="confirm-box">
    <p>Are you sure you want to delete this disease?</p>
    <small style="color:red;">This action cannot be undone.</small>
    <div class="confirm-actions">
        <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
        <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
</div>
</div>
<script>
const mainTypes=<?= json_encode($mainTypes,JSON_UNESCAPED_UNICODE) ?>;
const subTypes=<?= json_encode($subTypes,JSON_UNESCAPED_UNICODE) ?>;
const subSubTypes=<?= json_encode($subSubTypes,JSON_UNESCAPED_UNICODE) ?>;
function openModal(){
    document.getElementById('diseaseModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Disease';
    document.getElementById('diseaseForm').reset();
    document.getElementById('id').value='';
    resetDropdowns();
}
function closeModal(){
    document.getElementById('diseaseModal').style.display='none';
}
function resetDropdowns(){
    document.getElementById('main_type_id').innerHTML='<option value="">Select Main Type</option>';
    document.getElementById('sub_type_id').innerHTML='<option value="">Select Sub Type</option>';
    document.getElementById('sub_sub_type_id').innerHTML='<option value="">Select Sub Sub Type</option>';
}
function loadMainTypes(selectedId=''){
    const parentId=parseInt(document.getElementById('disease_type_id').value || 0);
    const select=document.getElementById('main_type_id');
    select.innerHTML='<option value="">Select Main Type</option>';
    mainTypes.forEach(function(item){
        if(parseInt(item.parent_id || 0)===parentId){
            const option=document.createElement('option');
            option.value=item.id;
            option.textContent=item.type_name;
            if(String(item.id)===String(selectedId)){
                option.selected=true;
            }
            select.appendChild(option);
        }
    });
    document.getElementById('sub_type_id').innerHTML='<option value="">Select Sub Type</option>';
    document.getElementById('sub_sub_type_id').innerHTML='<option value="">Select Sub Sub Type</option>';
}
function loadSubTypes(selectedId=''){
    const parentId=parseInt(document.getElementById('main_type_id').value || 0);
    const select=document.getElementById('sub_type_id');
    select.innerHTML='<option value="">Select Sub Type</option>';
    subTypes.forEach(function(item){
        if(parseInt(item.parent_id || 0)===parentId){
            const option=document.createElement('option');
            option.value=item.id;
            option.textContent=item.type_name;
            if(String(item.id)===String(selectedId)){
                option.selected=true;
            }
            select.appendChild(option);
        }
    });
    document.getElementById('sub_sub_type_id').innerHTML='<option value="">Select Sub Sub Type</option>';
}
function loadSubSubTypes(selectedId=''){
    const parentId=parseInt(document.getElementById('sub_type_id').value || 0);
    const select=document.getElementById('sub_sub_type_id');
    select.innerHTML='<option value="">Select Sub Sub Type</option>';
    subSubTypes.forEach(function(item){
        if(parseInt(item.parent_id || 0)===parentId){
            const option=document.createElement('option');
            option.value=item.id;
            option.textContent=item.type_name;
            if(String(item.id)===String(selectedId)){
                option.selected=true;
            }
            select.appendChild(option);
        }
    });
}
function editDisease(data){
    document.getElementById('diseaseModal').style.display='block';
    document.getElementById('modalTitle').innerText='Edit Disease';
    document.getElementById('diseaseForm').reset();
    document.getElementById('id').value=data.id;
    document.getElementById('disease_name').value=data.disease_name || '';
    document.getElementById('icd_code').value=data.icd_code || '';
    document.getElementById('status').value=data.status;
    document.getElementById('disease_type_id').value=data.disease_type_id || '';
    loadMainTypes(data.main_type_id || '');
    loadSubTypes(data.sub_type_id || '');
    loadSubSubTypes(data.sub_sub_type_id || '');
}
let deleteId=null;
function confirmDelete(id){
    deleteId=id;
    document.getElementById('deleteConfirm').style.display='flex';
}
function closeDelete(){
    document.getElementById('deleteConfirm').style.display='none';
    deleteId=null;
}
document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('confirmDeleteBtn').onclick=function(){
        if(!deleteId) return;
        window.location.href='disease_master.php?delete='+deleteId;
    };
});
</script>
</body>
</html>