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
/* VIEW PERMISSION */
if (!checkPermission('department_master', 'view')) {
    header('Location: /access_denied.php');
    exit;
}
/* HARD DELETE */
if (isset($_GET['delete']) && checkPermission('department_master', 'delete')) {
    $id = (int)$_GET['delete'];
$oldQry = mysqli_query(
    $conn,
    "SELECT * FROM department_master WHERE id = $id"
);

$oldData = mysqli_fetch_assoc($oldQry);
    if ($id > 0) {
        mysqli_query($conn, "DELETE FROM department_master WHERE id = $id");

addAuditLog(
    'Department Master',
    'DELETE',
    'Department Deleted : '.($oldData['department_name'] ?? ''),
    $id,
    '',
    ''
);
    }
    header("Location: department_list.php");
    exit;
}
/* FETCH DATA */
$user_id = (int)$_SESSION['user_id'];
if((int)$_SESSION['rights_type_id'] === 1){
    // SUPER ADMIN
    $sql = "
    SELECT
    d.*,
    h.hospital_name,
    b.branch_name,
    u.full_name AS created_by_name
FROM department_master d
LEFT JOIN hospital_master h
    ON h.id = d.hospital_id
LEFT JOIN branch_master b
    ON b.id = d.branch_id
LEFT JOIN users u
    ON u.id = d.created_by
ORDER BY d.id DESC
    ";
}
else{
    // OTHER USERS → ONLY ASSIGNED BRANCHES
    $sql = "
    SELECT DISTINCT
    d.*,
    h.hospital_name,
    b.branch_name,
    u.full_name AS created_by_name
FROM department_master d
INNER JOIN user_branches ub
    ON ub.branch_id = d.branch_id
LEFT JOIN hospital_master h
    ON h.id = d.hospital_id
LEFT JOIN branch_master b
    ON b.id = d.branch_id
LEFT JOIN users u
    ON u.id = d.created_by
WHERE ub.user_id = '$user_id'
ORDER BY d.id DESC
    ";
}

$res = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Department Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- MAIN CSS -->
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- FAVICON -->
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
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
    <a>Department Master</a>
    </div>
    <h2 style="margin:0 auto;">Department Master</h2>
        <?php if (checkPermission('department_master','add')) { ?>
            <a href="add_edit_department.php" class="btn btn-add">+ Add Department</a>
        <?php } ?>
    </div>
<div class="header-bar">
     </div>
    <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Hospital</th>
                <th>Branch</th>
                <th>Department Code</th>
                <th>Department Name</th>
                <th>Description</th>
                <th>Status</th>
                <th>Created</th>
                <th>Created By</th>
                <th>Updated</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php
            if ($res && mysqli_num_rows($res) > 0) {
                $i = 1;
                while ($row = mysqli_fetch_assoc($res)) {
            ?>
            <tr>
                <td><?= $i++; ?></td>
                <td><?= htmlspecialchars($row['hospital_name']); ?></td>
                <td><?= htmlspecialchars($row['branch_name']); ?></td>
                <td><?= htmlspecialchars($row['department_code']); ?></td>
                <td><?= htmlspecialchars($row['department_name']); ?></td>
                <td><?= htmlspecialchars($row['description']); ?></td>
                <td>
                    <?php if ($row['status'] == 1) { ?>
                        <span class="badge badge-success">Active</span>
                    <?php } else { ?>
                        <span class="badge badge-secondary">Inactive</span>
                    <?php } ?>
                </td>
                <td><?= date('d F, Y h:i A', strtotime($row['created_at'])); ?></td>
                <td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A'); ?></td>
                <td><?= date('d F, Y h:i A', strtotime($row['updated_at'])); ?></td>
               <td class="action-buttons">
<?php if (checkPermission('department_master','edit')) { ?>
<button class="btn-icon btn-edit"
onclick="window.location.href='add_edit_department.php?id=<?= $row['id']; ?>'">
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>
<?php if (checkPermission('department_master','delete')) { ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id']; ?>)">
<i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
</td>
            </tr>
            <?php } } else { ?>
            <tr>
                <td colspan="8" style="text-align:center;">No departments found</td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this department?</p>
    <small>This action cannot be undone.</small>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
<script>
let deleteId = null;
function confirmDelete(id){
    deleteId = id;
    document.getElementById("deleteConfirm").style.display = "flex";
}
function closeDelete(){
    document.getElementById("deleteConfirm").style.display = "none";
    deleteId = null;
}
document.addEventListener("DOMContentLoaded", function(){
    document.getElementById("confirmDeleteBtn").onclick = function(){
        if(!deleteId) return;
        window.location.href = "department_list.php?delete=" + deleteId;
    };
});
</script>
</body>
</html>