<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!checkPermission('rights', 'view')) {
    header('Location: /access_denied.php');
    exit;
}
/* SELECTED ROLE */
$selectedRole = (int)($_GET['role'] ?? 0);
if ($selectedRole <= 0) {
    header('Location: rights.php');
    exit;
}
/* FETCH SELECTED RIGHTS TYPE */
$stmtRole = $conn->prepare("
    SELECT id, name
    FROM rights_types
    WHERE id = ?
    LIMIT 1
");
$stmtRole->bind_param("i", $selectedRole);
$stmtRole->execute();
$roleResult = $stmtRole->get_result();
$selectedRights = $roleResult->fetch_assoc();
$stmtRole->close();
if (!$selectedRights) {
    header('Location: rights.php');
    exit;
}
/* FETCH MODULES */
$modules = $conn->query("
    SELECT m.*,
    /* Main module sort */
    CASE
        WHEN m.module_type = 'module'
            THEN m.sort_order
        WHEN m.module_type = 'sub_module'
            THEN (
                SELECT parent.sort_order
                FROM modules parent
                WHERE parent.id = m.parent_id
            )
        WHEN m.module_type = 'form'
            THEN (
                SELECT grandparent.sort_order
                FROM modules sub
                JOIN modules grandparent
                    ON grandparent.id = sub.parent_id
                WHERE sub.id = m.parent_id
            )
    END AS main_sort,
    /* Sub module sort */
    CASE
        WHEN m.module_type = 'sub_module'
            THEN m.sort_order
        WHEN m.module_type = 'form'
            THEN (
                SELECT sub.sort_order
                FROM modules sub
                WHERE sub.id = m.parent_id
            )
        ELSE 0
    END AS sub_sort
    FROM modules m
    WHERE m.status='Active'
    ORDER BY
        main_sort ASC,
        sub_sort ASC,
        CASE
            WHEN m.module_type='module' THEN 0
            WHEN m.module_type='sub_module' THEN 1
            ELSE 2
        END ASC,
        m.sort_order ASC,
        m.name ASC
");
/* FETCH SAVED PERMISSIONS */
$savedPerms = [];
$stmt = $conn->prepare("
    SELECT module_id, can_view, can_create,
           can_update, can_delete
    FROM module_permissions
    WHERE rights_type_id = ?
");
$stmt->bind_param("i", $selectedRole);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $savedPerms[$row['module_id']] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Show Rights - <?= htmlspecialchars($selectedRights['name']) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png" sizes="32x32" type="image/png">
<link rel="icon" href="/assets/img/hms_logo.png" sizes="192x192" type="image/png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===== FILTER BAR ===== */
.filter-card{
    background:#fff;
    padding:5px;
    border-radius:16px;
    margin-bottom:5px;
    display:flex;
    gap:15px;
    align-items:center;
    box-shadow:0 4px 20px rgba(0,0,0,.05);
}
.filter-card input{
    padding:9px 10px;
    border:1px solid #393535;
    border-radius:10px;
    width:280px;
    font-size:14px;
    box-sizing:border-box;
}
tbody td{
    text-align:center;
}
tbody td:first-child{
    text-align:left;
    padding-left:25px;
    font-weight:600;
    color:#333;
}
/* ===== TOGGLE SWITCH ===== */
.switch{
    position:relative;
    display:inline-block;
    width:46px;
    height:24px;
}
.slider{
    position:absolute;
    cursor:pointer;
    inset:0;
    background:#ccc;
    transition:.3s;
    border-radius:30px;
}
.slider:before{
    position:absolute;
    content:"";
    height:18px;
    width:18px;
    left:3px;
    bottom:3px;
    background:white;
    transition:.3s;
    border-radius:50%;
}
.switch input:checked + .slider{
    background:#1976d2;
}
.switch input:checked + .slider:before{
    transform:translateX(22px);
}

/* ===== TABLE CARD ===== */
.table-card{
    width:100%;
}
.permission-table-wrapper{
    width:100%;
    max-height:calc(100vh - 160px);
    overflow:auto;
    border-radius:12px;
    background:#fff;
}
.permission-table-wrapper table{
    width:100%;
    min-width:750px;
    border-collapse:collapse;
}
.permission-table-wrapper th,
.permission-table-wrapper td{
    border-bottom:1px solid #eee;
}
.permission-table-wrapper th{
    background:#1976d2;
    color:#fff;
    font-weight:600;
    position:sticky;
    top:0;
    z-index:10;
}
.permission-table-wrapper thead th:first-child{
    text-align:left;
    padding-left:25px;
}
/* ===== SAVE BAR ===== */
.save-bar{
    padding:10px;
    text-align:right;
    background:#fff;
}
/* ===== RESPONSIVE ===== */
@media(max-width:900px){
    .filter-card input{
        width:240px;
    }
    .permission-table-wrapper{
        max-height:calc(100vh - 230px);
    }
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<!-- BREADCRUMB / HEADER -->
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/settings/users/users.php">User Management</a>
        <span>&gt;</span>
        <a href="rights.php">Rights Management</a>
        <span>&gt;</span>
        <a><?= htmlspecialchars($selectedRights['name']) ?></a>
    </div>
    <h2>Rights & Permissions - <?= htmlspecialchars($selectedRights['name']) ?></h2>
    <div style="display:flex;align-items:center;gap:10px;">
        <input type="text"
               id="searchModule"
               placeholder="Search Module / Sub Module / Form"
               style="width:260px;padding:9px 10px;border:1px solid #393535;border-radius:10px;font-size:14px;box-sizing:border-box;">
        <a href="rights.php" class="btn">
            <i class="fas fa-arrow-left"></i>
            Back
        </a>
    </div>
</div>
<!-- PERMISSION MATRIX -->
<form method="post" action="save_rights.php">
    <input type="hidden"
           name="rights_type_id"
           value="<?= $selectedRole ?>">
    <div class="table-card">
    <div class="permission-table-wrapper">
        <table id="rightsTable">
            <thead>
                <tr>
                    <th>Module / Sub Module / Form</th>
                    <th>View</th>
                    <th>Create</th>
                    <th>Update</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
            <?php while($m = $modules->fetch_assoc()): ?>
                <?php
                $perm = $savedPerms[$m['id']] ?? [];
                ?>
                <tr>
                    <td>
<?php if($m['module_type'] == 'module'): ?>
    <div style="
        font-weight:700;
        background:#e3f2fd;
        padding:6px;
        border-radius:8px;
    ">
        <i class="fas fa-folder"></i>
        <?= htmlspecialchars($m['name']) ?>
    </div>
<?php elseif($m['module_type'] == 'sub_module'): ?>
    <div style="
        padding-left:25px;
        font-weight:600;
        color:#1976d2;
    ">
        ↳ <?= htmlspecialchars($m['name']) ?>
    </div>
<?php else: ?>
    <div style="
        padding-left:40px;
    ">
        • <?= htmlspecialchars($m['name']) ?>
    </div>
<?php endif; ?>
                    </td>
                    <!-- VIEW -->
                    <td>
                        <label class="switch">
                            <input type="checkbox"
                                   name="perm[<?= $m['id'] ?>][view]"
                                   <?= !empty($perm['can_view']) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </td>
                    <!-- CREATE -->
                    <td>
<?php if($m['permission_type'] == 'view_only'): ?>
    <span style="
        color:#999;
        font-size:12px;
    ">
        —
    </span>
<?php else: ?>
    <label class="switch">
        <input type="checkbox"
               name="perm[<?= $m['id'] ?>][create]"
               <?= !empty($perm['can_create']) ? 'checked' : '' ?>>
        <span class="slider"></span>
    </label>
<?php endif; ?>
                    </td>
                    <!-- UPDATE -->
                    <td>
<?php if($m['permission_type'] == 'view_only'): ?>
    <span style="
        color:#999;
        font-size:12px;
    ">
        —
    </span>
<?php else: ?>
    <label class="switch">
        <input type="checkbox"
               name="perm[<?= $m['id'] ?>][update]"
               <?= !empty($perm['can_update']) ? 'checked' : '' ?>>
        <span class="slider"></span>
    </label>
<?php endif; ?>
                    </td>
                    <!-- DELETE -->
                    <td>
<?php if($m['permission_type'] == 'view_only' || $m['permission_type'] == 'no_delete'): ?>
    <span style="
        color:#999;
        font-size:12px;
    ">
        —
    </span>
<?php else: ?>
    <label class="switch">
        <input type="checkbox"
               name="perm[<?= $m['id'] ?>][delete]"
               <?= !empty($perm['can_delete']) ? 'checked' : '' ?>>
        <span class="slider"></span>
    </label>
<?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <div class="save-bar">
<?php if (checkPermission('rights', 'update')): ?>
            <button type="submit" class="btn">
                <i class="fas fa-save"></i>
                Save Permissions
            </button>
<?php endif; ?>
        </div>
    </div>
</form>
<script>
/* SEARCH MODULE */
document.getElementById('searchModule').addEventListener('keyup', function(){
    let value = this.value.toLowerCase();
    let rows = document.querySelectorAll('#rightsTable tbody tr');
    rows.forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = text.includes(value) ? '' : 'none';
    });
});
/* RIGHTS AUTO LOGIC */
document.querySelectorAll('#rightsTable tbody tr').forEach(row => {
    let viewCheckbox = row.querySelector('input[name*="[view]"]');
    let createCheckbox = row.querySelector('input[name*="[create]"]');
    let updateCheckbox = row.querySelector('input[name*="[update]"]');
    let deleteCheckbox = row.querySelector('input[name*="[delete]"]');
    /* AUTO ENABLE VIEW */
    function autoEnableView(){
        if(
            (createCheckbox && createCheckbox.checked)
            ||
            (updateCheckbox && updateCheckbox.checked)
            ||
            (deleteCheckbox && deleteCheckbox.checked)
        ){
            if(viewCheckbox){
                viewCheckbox.checked = true;
            }
        }
    }
    /* AUTO DISABLE CRUD */
    function autoDisableCrud(){
        if(
            viewCheckbox &&
            !viewCheckbox.checked
        ){
            if(createCheckbox){
                createCheckbox.checked = false;
            }
            if(updateCheckbox){
                updateCheckbox.checked = false;
            }
            if(deleteCheckbox){
                deleteCheckbox.checked = false;
            }
        }
    }
    /* EVENTS */
    if(createCheckbox){
        createCheckbox.addEventListener('change', autoEnableView);
    }
    if(updateCheckbox){
        updateCheckbox.addEventListener('change', autoEnableView);
    }
    if(deleteCheckbox){
        deleteCheckbox.addEventListener('change', autoEnableView);
    }
    if(viewCheckbox){
        viewCheckbox.addEventListener('change', autoDisableCrud);
    }
});
</script>
</body>
</html>