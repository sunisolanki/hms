<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
if (!checkPermission('branch_master', 'view')) {
    header('Location: /access_denied.php');
    exit;
}
/* HARD DELETE */
if (isset($_GET['delete']) && checkPermission('branch_master', 'delete')) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        mysqli_query($conn, "DELETE FROM branch_master WHERE id = $id");
    }
    header("Location: branch_list.php");
    exit;
}
/* FETCH DATA */
$user_id = $_SESSION['user_id'];

if((int)$_SESSION['rights_type_id'] === 1){

    // SUPER ADMIN

   $sql = "
SELECT
    b.*,
    h.hospital_name,
    u.full_name AS created_by_name
FROM branch_master b
LEFT JOIN hospital_master h
    ON h.id = b.hospital_id
LEFT JOIN users u
    ON u.id = b.created_by
ORDER BY b.id DESC
";
}
else{

    // OTHER USERS

    $sql = "
    SELECT DISTINCT
    b.*,
    h.hospital_name,
    u.full_name AS created_by_name
    FROM branch_master b

    INNER JOIN user_branches ub
        ON ub.branch_id = b.id

    LEFT JOIN hospital_master h
        ON h.id = b.hospital_id
        
    LEFT JOIN users u
    ON u.id = b.created_by

    WHERE ub.user_id = '$user_id'

    ORDER BY b.id DESC
    ";

}

$res = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Branch Master</title>
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
    <a>Branch Masters</a>
    </div>
    <h2 style="margin:0 auto;">Branch Master</h2>
        <?php if (checkPermission('branch_master','add')) { ?>
            <a href="add_edit_branch.php" class="btn btn-add">+ Add Branch</a>
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
                <th>Branch Code</th>
                <th>Branch Name</th>
                <th>Country</th>
                <th>State</th>
                <th>District</th>
                <th>City</th>
                <th>Address</th>
                <th>Mobile</th>
                <th>Email</th>
                <th>Website</th>
                <th>GSTIN</th>
                <th>DL No</th>
                <th>Detail 1</th>
                <th>Detail 2</th>
                <th>Detail 3</th>
                <th>Detail 4</th>
                <th>Status</th>
                <th>Logo</th>
                <th>Created</th>
                <th>Updated</th>
                <th>Created By</th>
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
                <td><?= htmlspecialchars($row['branch_code']); ?></td>
                <td><?= htmlspecialchars($row['branch_name']); ?></td>
                <td><?= htmlspecialchars($row['country']); ?></td>
                <td><?= htmlspecialchars($row['state']); ?></td>
                <td><?= htmlspecialchars($row['district']); ?></td>
                <td><?= htmlspecialchars($row['city']); ?></td>
                <td><?= htmlspecialchars($row['address']); ?></td>
                <td><?= htmlspecialchars($row['mobile']); ?></td>
                <td><?= htmlspecialchars($row['email']); ?></td>
                <td><?= htmlspecialchars($row['website']); ?></td>
                <td><?= htmlspecialchars($row['gstin']); ?></td>
                <td><?= htmlspecialchars($row['dl_no']); ?></td>
                <td><?= htmlspecialchars($row['detail_1']); ?></td>
                <td><?= htmlspecialchars($row['detail_2']); ?></td>
                <td><?= htmlspecialchars($row['detail_3']); ?></td>
                <td><?= htmlspecialchars($row['detail_4']); ?></td>
                <td>
                    <?php if ($row['status'] == 1) { ?>
                        <span class="badge badge-success">Active</span>
                    <?php } else { ?>
                        <span class="badge badge-secondary">Inactive</span>
                    <?php } ?>
                </td>
                <td>
                    <?php if (!empty($row['logo'])) { ?>
                        <img src="/<?= htmlspecialchars($row['logo']); ?>" height="35">
                    <?php } ?>
                </td>
                <td><?= date('d F, Y h:i A', strtotime($row['created_at'])); ?></td>
                <td><?= date('d F, Y h:i A', strtotime($row['updated_at'])); ?></td>
                <td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A'); ?></td>
                <td class="action-buttons">
<?php if (checkPermission('branch_master','edit')) { ?>
<button class="btn-icon btn-edit"
onclick="window.location.href='add_edit_branch.php?id=<?= $row['id']; ?>'">
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>
<?php if (checkPermission('branch_master','delete')) { ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id']; ?>)">
<i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
</td>
            </tr>
            <?php } } else { ?>
            <tr>
                <td colspan="21" style="text-align:center;">No branches found</td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this branch?</p>
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
        window.location.href = "branch_list.php?delete=" + deleteId;
    };
});
</script>
</body>
</html>