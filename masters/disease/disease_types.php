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
        $oldQry = mysqli_query($conn,"SELECT * FROM disease_types WHERE id=$id");
        $oldData = mysqli_fetch_assoc($oldQry);
        if ($oldData) {
            /* CHECK IF THIS TYPE HAS CHILD TYPES */
            $childQry = mysqli_query($conn,"SELECT id FROM disease_types WHERE parent_id=$id LIMIT 1");
            /* CHECK IF USED IN DISEASE MASTER */
            $usedQry = mysqli_query($conn,"
                SELECT id
                FROM disease_master
                WHERE disease_type_id=$id
                   OR main_type_id=$id
                   OR sub_type_id=$id
                   OR sub_sub_type_id=$id
                LIMIT 1
            ");
            if (mysqli_num_rows($childQry) > 0) {
                $_SESSION['disease_type_error'] = 'Cannot delete this type because child types exist under it.';
            } elseif (mysqli_num_rows($usedQry) > 0) {
                $_SESSION['disease_type_error'] = 'Cannot delete this type because it is being used in Disease Master.';
            } else {
                mysqli_query($conn,"DELETE FROM disease_types WHERE id=$id");
                addAuditLog(
                    'Disease Master',
                    'DELETE',
                    'Disease Classification Deleted : '.$oldData['type_name'],
                    $id,
                    '',
                    ''
                );
                $_SESSION['disease_type_success'] = 'Disease classification deleted successfully.';
            }
        }
    }
    header("Location: disease_types.php");
    exit;
}
/* SAVE ADD / EDIT */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (
        ($id > 0 && !checkPermission('disease_master','edit')) ||
        ($id === 0 && !checkPermission('disease_master','add'))
    ) {
        header('Location: /access_denied.php');
        exit;
    }
    $typeLevel = $_POST['type_level'] ?? '';
    $typeName = trim($_POST['type_name'] ?? '');
    $diseaseTypeId = (int)($_POST['disease_type_id'] ?? 0);
    $mainTypeId = (int)($_POST['main_type_id'] ?? 0);
    $subTypeId = (int)($_POST['sub_type_id'] ?? 0);
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
    $allowedLevels = [
        'disease_type',
        'main_type',
        'sub_type',
        'sub_sub_type'
    ];
    if (!in_array($typeLevel,$allowedLevels,true)) {
        $_SESSION['disease_type_error'] = 'Invalid classification level.';
        header("Location: disease_types.php");
        exit;
    }
    if ($typeName === '') {
        $_SESSION['disease_type_error'] = 'Type Name is required.';
        header("Location: disease_types.php");
        exit;
    }
    /* DETERMINE PARENT */
    $parentId = 0;
    if ($typeLevel === 'main_type') {
        $parentId = $diseaseTypeId;
    } elseif ($typeLevel === 'sub_type') {
        $parentId = $mainTypeId;
    } elseif ($typeLevel === 'sub_sub_type') {
        $parentId = $subTypeId;
    }
    if ($typeLevel !== 'disease_type' && $parentId <= 0) {
        $_SESSION['disease_type_error'] = 'Please select the required parent classification.';
        header("Location: disease_types.php");
        exit;
    }
    /* VERIFY PARENT LEVEL */
    if ($parentId > 0) {
        $expectedParentLevel = '';
        if ($typeLevel === 'main_type') {
            $expectedParentLevel = 'disease_type';
        } elseif ($typeLevel === 'sub_type') {
            $expectedParentLevel = 'main_type';
        } elseif ($typeLevel === 'sub_sub_type') {
            $expectedParentLevel = 'sub_type';
        }
        $parentStmt = mysqli_prepare(
            $conn,
            "SELECT id FROM disease_types WHERE id=? AND type_level=? LIMIT 1"
        );
        mysqli_stmt_bind_param($parentStmt,'is',$parentId,$expectedParentLevel);
        mysqli_stmt_execute($parentStmt);
        $parentResult = mysqli_stmt_get_result($parentStmt);
        if (mysqli_num_rows($parentResult) === 0) {
            mysqli_stmt_close($parentStmt);
            $_SESSION['disease_type_error'] = 'Invalid parent classification selected.';
            header("Location: disease_types.php");
            exit;
        }
        mysqli_stmt_close($parentStmt);
    }
    /* DUPLICATE CHECK */
    if ($parentId > 0) {
        $duplicateStmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM disease_types
             WHERE type_level=?
             AND type_name=?
             AND parent_id=?
             AND id<>?
             LIMIT 1"
        );
        mysqli_stmt_bind_param(
            $duplicateStmt,
            'ssii',
            $typeLevel,
            $typeName,
            $parentId,
            $id
        );
    } else {
        $duplicateStmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM disease_types
             WHERE type_level=?
             AND type_name=?
             AND parent_id IS NULL
             AND id<>?
             LIMIT 1"
        );
        mysqli_stmt_bind_param(
            $duplicateStmt,
            'ssi',
            $typeLevel,
            $typeName,
            $id
        );
    }
    mysqli_stmt_execute($duplicateStmt);
    $duplicateResult = mysqli_stmt_get_result($duplicateStmt);
    if (mysqli_num_rows($duplicateResult) > 0) {
        mysqli_stmt_close($duplicateStmt);
        $_SESSION['disease_type_error'] = 'This classification already exists.';
        header("Location: disease_types.php");
        exit;
    }
    mysqli_stmt_close($duplicateStmt);
    $userId = (int)$_SESSION['user_id'];
    if ($id > 0) {
        $oldQry = mysqli_query($conn,"SELECT * FROM disease_types WHERE id=$id");
        $oldData = mysqli_fetch_assoc($oldQry);
        if (!$oldData) {
            $_SESSION['disease_type_error'] = 'Classification record not found.';
            header("Location: disease_types.php");
            exit;
        }
        $parentValue = $parentId > 0 ? $parentId : null;
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE disease_types
             SET parent_id=?,
                 type_level=?,
                 type_name=?,
                 status=?,
                 updated_at=NOW()
             WHERE id=?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'issii',
            $parentValue,
            $typeLevel,
            $typeName,
            $status,
            $id
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $oldValues = [];
        $newValues = [];
        $oldParent = (int)($oldData['parent_id'] ?? 0);
        if ($oldData['type_level'] !== $typeLevel) {
            $oldValues['type_level'] = $oldData['type_level'];
            $newValues['type_level'] = $typeLevel;
        }
        if ($oldData['type_name'] !== $typeName) {
            $oldValues['type_name'] = $oldData['type_name'];
            $newValues['type_name'] = $typeName;
        }
        if ($oldParent !== $parentId) {
            $oldValues['parent_id'] = $oldParent ?: '';
            $newValues['parent_id'] = $parentId ?: '';
        }
        if ((int)$oldData['status'] !== $status) {
            $oldValues['status'] = (int)$oldData['status'] === 1 ? 'Active' : 'Inactive';
            $newValues['status'] = $status === 1 ? 'Active' : 'Inactive';
        }
        if (!empty($oldValues)) {
            addAuditLog(
                'Disease Master',
                'UPDATE',
                'Disease Classification Updated : '.$typeName,
                $id,
                json_encode($oldValues),
                json_encode($newValues)
            );
        }
        $_SESSION['disease_type_success'] = 'Disease classification updated successfully.';
    } else {
        $parentValue = $parentId > 0 ? $parentId : null;
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO disease_types
            (parent_id,type_level,type_name,status,created_by)
            VALUES (?,?,?,?,?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'issii',
            $parentValue,
            $typeLevel,
            $typeName,
            $status,
            $userId
        );
        mysqli_stmt_execute($stmt);
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        addAuditLog(
            'Disease Master',
            'CREATE',
            'Disease Classification Created : '.$typeName,
            $newId,
            '',
            ''
        );
        $_SESSION['disease_type_success'] = 'Disease classification created successfully.';
    }
    header("Location: disease_types.php");
    exit;
}
/* FETCH CLASSIFICATIONS */
$res = mysqli_query($conn,"
SELECT
    dt.*,
    p.type_name AS parent_name,
    u.full_name AS created_by_name
FROM disease_types dt
LEFT JOIN disease_types p
    ON p.id=dt.parent_id
LEFT JOIN users u
    ON u.id=dt.created_by
ORDER BY
    FIELD(dt.type_level,'disease_type','main_type','sub_type','sub_sub_type'),
    dt.type_name ASC
");
/* FETCH DROPDOWN DATA */
$diseaseTypes = [];
$mainTypes = [];
$subTypes = [];
$typeQry = mysqli_query($conn,"
SELECT id,parent_id,type_level,type_name,status
FROM disease_types
ORDER BY type_name ASC
");
while ($typeRow = mysqli_fetch_assoc($typeQry)) {
    if ($typeRow['type_level'] === 'disease_type') {
        $diseaseTypes[] = $typeRow;
    } elseif ($typeRow['type_level'] === 'main_type') {
        $mainTypes[] = $typeRow;
    } elseif ($typeRow['type_level'] === 'sub_type') {
        $subTypes[] = $typeRow;
    }
}
function levelLabel($level) {
    $labels = [
        'disease_type' => 'Disease Type',
        'main_type' => 'Main Type',
        'sub_type' => 'Sub Type',
        'sub_sub_type' => 'Sub Sub Type'
    ];
    return $labels[$level] ?? $level;
}
$successMessage = $_SESSION['disease_type_success'] ?? '';
$errorMessage = $_SESSION['disease_type_error'] ?? '';
unset($_SESSION['disease_type_success'],$_SESSION['disease_type_error']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Disease Type Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.header-bar{display:flex;justify-content:space-between;align-items:center;}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#f1f1f1}
.btn{background:#2c7be5;color:#fff;padding:8px 14px;border:none;border-radius:5px;cursor:pointer;width:auto;max-width:fit-content;text-decoration:none;display:inline-block}
.btn:hover{background:#1a5fd0;color:#fff}
.btn-success{background:#28a745;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.badge-success{background:#28a745;color:#fff;padding:3px 6px;border-radius:3px}
.badge-secondary{background:#6c757d;color:#fff;padding:3px 6px;border-radius:3px}
.action-buttons{display:flex;gap:6px;justify-content:center;}
.btn-icon{border:none;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:0.2s;}
.btn-edit{background:#e8f5e9;color:#2e7d32;}
.btn-edit:hover{background:#2e7d32;color:#fff;}
.btn-delete{background:#fdecea;color:#c62828;}
.btn-delete:hover{background:#c62828;color:#fff;}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999}
.modal-box{background:#fff;width:560px;max-width:95%;margin:60px auto;padding:20px;border-radius:6px;max-height:85vh;overflow-y:auto}
.modal h3{margin-top:0}
label{font-weight:600;font-size:13px;margin-top:10px;display:block}
input,textarea,select{width:100%;padding:7px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box}
.actions{text-align:right;margin-top:15px}
.parent-field{display:none}
.alert{padding:10px 14px;margin-bottom:15px;border-radius:5px;font-size:13px}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-danger{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.level-badge{padding:3px 7px;border-radius:4px;background:#e7f1ff;color:#0b5ed7;font-size:12px;font-weight:600}
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
        <a href="/masters/disease/disease_master.php">Disease Master</a>
        <span>&gt;</span>
        <a>Disease Types</a>
    </div>
    <h2 style="margin:0 auto;">Disease Type Management</h2>
    <?php if (checkPermission('disease_master','add')) { ?>
    <button class="btn" onclick="openModal()">+ Add Type</button>
    <?php } ?>
</div>
<div class="container">
<?php if ($successMessage !== '') { ?>
<div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
<?php } ?>
<?php if ($errorMessage !== '') { ?>
<div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
<?php } ?>
<table>
<thead>
<tr>
    <th>#</th>
    <th>Level</th>
    <th>Type Name</th>
    <th>Parent</th>
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
    <td><span class="level-badge"><?= htmlspecialchars(levelLabel($row['type_level'])) ?></span></td>
    <td><?= htmlspecialchars($row['type_name']) ?></td>
    <td><?= htmlspecialchars($row['parent_name'] ?? '-') ?></td>
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
        <button class="btn-icon btn-edit" onclick='editType(<?= htmlspecialchars(json_encode($row),ENT_QUOTES,"UTF-8") ?>)'>
            <i class="fa-solid fa-pen-to-square"></i>
        </button>
        <?php } ?>
        <?php if (checkPermission('disease_master','delete')) { ?>
        <button class="btn-icon btn-delete" onclick="confirmDelete(<?= (int)$row['id'] ?>)">
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
    <td colspan="9" align="center">No records found</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<!-- ADD / EDIT MODAL -->
<div class="modal" id="typeModal">
<div class="modal-box">
<h3 id="modalTitle">Add Disease Classification</h3>
<form method="post" id="typeForm">
<input type="hidden" name="id" id="id">
<label>Classification Level *</label>
<select name="type_level" id="type_level" required onchange="levelChanged()">
    <option value="">Select Level</option>
    <option value="disease_type">Disease Type</option>
    <option value="main_type">Main Type</option>
    <option value="sub_type">Sub Type</option>
    <option value="sub_sub_type">Sub Sub Type</option>
</select>
<div class="parent-field" id="diseaseTypeField">
<label>Disease Type *</label>
<select name="disease_type_id" id="disease_type_id" onchange="loadMainTypes()">
    <option value="">Select Disease Type</option>
    <?php foreach ($diseaseTypes as $item) { ?>
    <option value="<?= (int)$item['id'] ?>">
        <?= htmlspecialchars($item['type_name']) ?>
    </option>
    <?php } ?>
</select>
</div>
<div class="parent-field" id="mainTypeField">
<label>Main Type *</label>
<select name="main_type_id" id="main_type_id" onchange="loadSubTypes()">
    <option value="">Select Main Type</option>
</select>
</div>
<div class="parent-field" id="subTypeField">
<label>Sub Type *</label>
<select name="sub_type_id" id="sub_type_id">
    <option value="">Select Sub Type</option>
</select>
</div>
<label>Type Name *</label>
<input type="text" name="type_name" id="type_name" required maxlength="150">
<label>Status</label>
<select name="status" id="status">
    <option value="1">Active</option>
    <option value="0">Inactive</option>
</select>
<div class="actions">
    <button type="submit" class="btn btn-success">Save</button>
    <button type="button" class="btn btn-danger" onclick="closeModal()">Cancel</button>
</div>
</form>
</div>
</div>
<!-- DELETE CONFIRMATION -->
<div id="deleteConfirm" class="confirm-modal">
<div class="confirm-box">
    <p>Are you sure you want to delete this disease classification?</p>
    <small style="color:red;">This action cannot be undone.</small>
    <div class="confirm-actions">
        <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
        <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
</div>
</div>
<script>
const mainTypes = <?= json_encode($mainTypes,JSON_UNESCAPED_UNICODE) ?>;
const subTypes = <?= json_encode($subTypes,JSON_UNESCAPED_UNICODE) ?>;
function openModal(){
    document.getElementById('typeModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Disease Classification';
    document.getElementById('typeForm').reset();
    document.getElementById('id').value='';
    resetParents();
    levelChanged();
}
function closeModal(){
    document.getElementById('typeModal').style.display='none';
}
function resetParents(){
    document.getElementById('diseaseTypeField').style.display='none';
    document.getElementById('mainTypeField').style.display='none';
    document.getElementById('subTypeField').style.display='none';
    document.getElementById('disease_type_id').required=false;
    document.getElementById('main_type_id').required=false;
    document.getElementById('sub_type_id').required=false;
}
function levelChanged(){
    const level=document.getElementById('type_level').value;
    resetParents();
    if(level==='main_type'){
        document.getElementById('diseaseTypeField').style.display='block';
        document.getElementById('disease_type_id').required=true;
    }
    if(level==='sub_type'){
        document.getElementById('diseaseTypeField').style.display='block';
        document.getElementById('mainTypeField').style.display='block';
        document.getElementById('disease_type_id').required=true;
        document.getElementById('main_type_id').required=true;
    }
    if(level==='sub_sub_type'){
        document.getElementById('diseaseTypeField').style.display='block';
        document.getElementById('mainTypeField').style.display='block';
        document.getElementById('subTypeField').style.display='block';
        document.getElementById('disease_type_id').required=true;
        document.getElementById('main_type_id').required=true;
        document.getElementById('sub_type_id').required=true;
    }
}
function loadMainTypes(selectedId=''){
    const diseaseTypeId=parseInt(document.getElementById('disease_type_id').value || 0);
    const select=document.getElementById('main_type_id');
    select.innerHTML='<option value="">Select Main Type</option>';
    mainTypes.forEach(function(item){
        if(parseInt(item.parent_id || 0)===diseaseTypeId){
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
}
function loadSubTypes(selectedId=''){
    const mainTypeId=parseInt(document.getElementById('main_type_id').value || 0);
    const select=document.getElementById('sub_type_id');
    select.innerHTML='<option value="">Select Sub Type</option>';
    subTypes.forEach(function(item){
        if(parseInt(item.parent_id || 0)===mainTypeId){
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
function findParent(id,collection){
    return collection.find(function(item){
        return parseInt(item.id)===parseInt(id);
    });
}
function editType(data){
    document.getElementById('typeModal').style.display='block';
    document.getElementById('modalTitle').innerText='Edit Disease Classification';
    document.getElementById('typeForm').reset();
    document.getElementById('id').value=data.id;
    document.getElementById('type_level').value=data.type_level;
    document.getElementById('type_name').value=data.type_name || '';
    document.getElementById('status').value=data.status;
    levelChanged();
    if(data.type_level==='main_type'){
        document.getElementById('disease_type_id').value=data.parent_id || '';
    }
    if(data.type_level==='sub_type'){
        const main=findParent(data.parent_id,mainTypes);
        if(main){
            document.getElementById('disease_type_id').value=main.parent_id || '';
            loadMainTypes(data.parent_id);
        }
    }
    if(data.type_level==='sub_sub_type'){
        const sub=findParent(data.parent_id,subTypes);
        if(sub){
            const main=findParent(sub.parent_id,mainTypes);
            if(main){
                document.getElementById('disease_type_id').value=main.parent_id || '';
                loadMainTypes(main.id);
                loadSubTypes(sub.id);
            }
        }
    }
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
        window.location.href='disease_types.php?delete='+deleteId;
    };
});
</script>
</body>
</html>