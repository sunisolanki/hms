<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php'); exit;
}
/* VIEW PERMISSION */
if (!checkPermission('empanelment_master','view')) {
    header('Location: /access_denied.php'); exit;
}
/* DELETE */
if (isset($_GET['delete']) && checkPermission('empanelment_master','delete')) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $q = mysqli_query($conn,"
            SELECT *
            FROM empanelment_master
            WHERE id = $id
        ");
        if ($emp = mysqli_fetch_assoc($q)) {
            addAuditLog(
                'Empanelment Master',
                'Delete',
                "Empanelment Deleted : {$emp['empanelment_name']}",
                $id,
                json_encode($emp),
                '',
                'SUCCESS'
            );
        }
        mysqli_query($conn,"DELETE FROM empanelment_services WHERE empanelment_id=$id");
        mysqli_query($conn,"DELETE FROM empanelment_master WHERE id=$id");
    }
    header("Location: index.php");
    exit;
}
/* FETCH LIST */
$user_id = (int)$_SESSION['user_id'];

if ((int)$_SESSION['rights_type_id'] === 1) {
    // SUPER ADMIN
    $sql = "
        SELECT
            em.*,
            u.full_name AS created_by_name,
            IF(em.end_date < CURDATE(), 'Expired', 'Active') AS emp_status
        FROM empanelment_master em
        LEFT JOIN users u
            ON u.id = em.created_by
        ORDER BY em.id DESC
    ";
} else {
    // OTHER USERS
    $sql = "
        SELECT
            em.*,
            u.full_name AS created_by_name,
            IF(em.end_date < CURDATE(), 'Expired', 'Active') AS emp_status
        FROM empanelment_master em
        LEFT JOIN users u
            ON u.id = em.created_by
        ORDER BY em.id DESC
    ";
}

$res = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Empanelment Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<!-- FAVICON -->
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>

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
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Empanelment Master</a>
    </div>
    <h2 style="margin:0 auto;">Empanelment Master</h2>
    <?php if (checkPermission('empanelment_master','add')) { ?>
        <button class="btn" onclick="window.location.href='add.php'">
            + Add Empanelment
        </button>
    <?php } ?>
</div>
<div class="container">
<table>
<thead>
<tr>
    <th>#</th>
    <th>Empanelment Name</th>
    <th>Validity</th>
    <th>Payment Mode</th>
    <th>Consultation Discount</th>
    <th>Status</th>
    <th>Created By</th>
    <th>Created Date</th>
    <th>Updated Date</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php
$i = 1;
if ($res && mysqli_num_rows($res) > 0) {
while ($row = mysqli_fetch_assoc($res)) {
?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($row['empanelment_name']) ?></td>
    <td>
        <?= htmlspecialchars($row['start_date']) ?>
        <br>
        <small>to <?= htmlspecialchars($row['end_date']) ?></small>
    </td>
    <td><?= htmlspecialchars($row['payment_mode']) ?></td>
    <td>
        <?= htmlspecialchars($row['consultation_discount_value']) ?>
        <?= $row['consultation_discount_type']=='Percentage' ? '%' : '₹' ?>
        <small>(<?= $row['consultation_discount_type'] ?>)</small>
    </td>
    <td>
        <?php
        $status = '';
if ($row['status']==0) {
    $status = "<span class='status-badge status-inactive'>Inactive</span>";
} elseif ($row['end_date'] < date('Y-m-d')) {
    $status = "<span class='status-badge status-expired'>Expired</span>";
} else {
    $status = "<span class='status-badge status-active'>Active</span>";
}
echo $status;
        ?>
    </td>
    <td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A'); ?></td>
    <td><?= date('d F, Y h:i A', strtotime($row['created_at'])); ?></td>
    <td>
    <?= !empty($row['updated_at'])
        ? date('d F, Y h:i A', strtotime($row['updated_at']))
        : 'N/A'; ?>
</td>
    <td class="action-buttons">
<?php if (checkPermission('empanelment_master','edit')) { ?>
<button class="btn-icon btn-edit"
onclick="window.location.href='edit.php?id=<?= $row['id'] ?>'">
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>
<?php if (checkPermission('empanelment_master','delete')) { ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id'] ?>)">
    <i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
</td>
</tr>
<?php
}} else {
?>
<tr>
    <td colspan="10" align="center">No empanelments found</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this empanelment?</p>
    <small style="color:red;">This action cannot be undone.</small>
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
document.getElementById("confirmDeleteBtn").onclick = function(){
    if(!deleteId) return;
    window.location.href = "index.php?delete=" + deleteId;
};
</script>
</body>
</html>