<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
/* LOGIN CHECK */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/* MODE */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$created_by = (int)$_SESSION['user_id'];
/* PERMISSIONS */
if ($isEdit) {
    if (!checkPermission('department_master', 'edit')) {
        header('Location: /access_denied.php');
        exit;
    }
} else {
    if (!checkPermission('department_master', 'add')) {
        header('Location: /access_denied.php');
        exit;
    }
}
/* DEFAULT DATA */
$data = [
    'hospital_id' => '',
    'branch_id' => '',
    'department_code' => '',
    'department_name' => '',
    'description' => '',
    'status' => 1
];
/* LOAD EDIT DATA */
if ($isEdit) {
    $q = mysqli_query($conn, "SELECT * FROM department_master WHERE id = $id");
    if ($q && mysqli_num_rows($q) === 1) {
        $data = mysqli_fetch_assoc($q);
    } else {
        die('Invalid Department ID');
    }
}
/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hospital_id = (int)$_POST['hospital_id'];
    $branch_id   = (int)$_POST['branch_id'];
    $department_code = mysqli_real_escape_string($conn, $_POST['department_code']);
    $department_name = mysqli_real_escape_string($conn, $_POST['department_name']);
    $description     = mysqli_real_escape_string($conn, $_POST['description']);
    $status           = (int)$_POST['status'];
    if ($isEdit) {
        
        $oldQry = mysqli_query(
    $conn,
    "SELECT * FROM department_master WHERE id = $id"
);

$oldData = mysqli_fetch_assoc($oldQry);

        mysqli_query($conn, "
            UPDATE department_master SET
                hospital_id     = '$hospital_id',
                branch_id       = '$branch_id',
                department_code = '$department_code',
                department_name = '$department_name',
                description     = '$description',
                status          = '$status'
            WHERE id = $id
        ");
        $oldValues = [];
$newValues = [];
$fields = [
    'hospital_id'     => $hospital_id,
    'branch_id'       => $branch_id,
    'department_code' => $department_code,
    'department_name' => $department_name,
    'description'     => $description,
    'status'          => $status
];
foreach($fields as $field => $newValue){
    $oldValue = $oldData[$field] ?? '';
    if((string)$oldValue !== (string)$newValue){
        $oldValues[$field] = $oldValue;
        $newValues[$field] = $newValue;
    }
}
if(!empty($newValues)){
    addAuditLog(
        'Department Master',
        'UPDATE',
        'Department Updated : '.$department_name,
        $id,
        json_encode($oldValues),
        json_encode($newValues)
    );
}
    } else {
       
        mysqli_query($conn, "
            INSERT INTO department_master SET
    hospital_id     = '$hospital_id',
    branch_id       = '$branch_id',
    department_code = '$department_code',
    department_name = '$department_name',
    description     = '$description',
    status          = 1,
    created_by      = '$created_by'
        ");
        $departmentId = mysqli_insert_id($conn);
addAuditLog(
    'Department Master',
    'CREATE',
    'Department Created : '.$department_name,
    $departmentId,
    '',
    ''
);
    }
    header("Location: department_list.php");
    exit;
}
/* LOAD MASTERS */
$hospitals = mysqli_query($conn, "SELECT id, hospital_name FROM hospital_master WHERE status = 1 ORDER BY hospital_name");
$branches  = $data['hospital_id']
    ? mysqli_query($conn, "SELECT id, branch_name FROM branch_master WHERE hospital_id = {$data['hospital_id']} AND status = 1 ORDER BY branch_name")
    : [];
?>
<script>
function loadBranches(hospitalId, selectedBranchId = '') {
    if (!hospitalId) {
        document.getElementById('branch_id').innerHTML =
            '<option value="">Select Branch</option>';
        return;
    }
    fetch('/masters/department/load_branches.php?hospital_id=' + hospitalId)
        .then(res => res.text())
        .then(html => {
            const branchSelect = document.getElementById('branch_id');
            branchSelect.innerHTML = html;

            if (selectedBranchId) {
                branchSelect.value = selectedBranchId;
            }
        });
}
</script>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $isEdit ? 'Edit' : 'Add' ?> Department</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- MAIN CSS -->
<link rel="stylesheet" href="/assets/css/style.css">
<!-- FAVICON -->
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
input, select, textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 13px;
}
</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<body>

    <div class="top-bar">  
    <div class="breadcrumb">
    <a href="/dashboard/index.php">Home</a>
    <span>&gt;</span>
    <a href="/settings/settings.php">Settings</a>
    <span>&gt;</span>
    <a href="/masters/masters.php">Masters</a>
    <span>&gt;</span>
    <a href="/masters/department/department_list.php">Department Masters</a>
    <span>&gt;</span>
    <a>Add/Edit Department</a>
    </div></div>
 <div class="container">   
<h2><?= $isEdit ? 'Edit' : 'Add' ?> Department</h2>
<form method="post">
<div class="grid">
    <div>
        <label>Hospital *</label>
        <select name="hospital_id" required onchange="loadBranches(this.value)">
            <option value="">Select Hospital</option>
            <?php while ($h = mysqli_fetch_assoc($hospitals)) { ?>
                <option value="<?= $h['id'] ?>" <?= $h['id'] == $data['hospital_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($h['hospital_name']) ?>
                </option>
            <?php } ?>
        </select>
    </div>
    <div>
        <label>Branch *</label>
        <select name="branch_id" id="branch_id" required>
            <option value="">Select Branch</option>
            <?php if ($isEdit && $branches) {
                while ($b = mysqli_fetch_assoc($branches)) { ?>
                    <option value="<?= $b['id'] ?>" <?= $b['id'] == $data['branch_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
            <?php }} ?>
        </select>
    </div>
    <div>
        <label>Department Code *</label>
        <input type="text" name="department_code" required value="<?= htmlspecialchars($data['department_code']) ?>">
    </div>
    <div>
        <label>Department Name *</label>
        <input type="text" name="department_name" required value="<?= htmlspecialchars($data['department_name']) ?>">
    </div>
    <div class="full">
        <label>Description</label>
        <textarea name="description"><?= htmlspecialchars($data['description']) ?></textarea>
    </div>
    <div>
        <label>Status</label>
        <select name="status">
            <option value="1" <?= $data['status'] == 1 ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $data['status'] == 0 ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
</div>
<div class="actions">
    <button class="btn btn-success">Save</button>
    <a href="department_list.php" class="btn btn-secondary">Back</a>
</div>
</form>
</div>
<?php if ($isEdit && $data['hospital_id']) { ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    loadBranches(<?= (int)$data['hospital_id'] ?>);
});
</script>
<?php } ?>
<?php if ($isEdit): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    loadBranches(
        <?= (int)$data['hospital_id'] ?>,
        <?= (int)$data['branch_id'] ?>
    );
});
</script>
<?php endif; ?>
</body>
</html>