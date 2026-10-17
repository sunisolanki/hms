<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
if (!checkPermission('hospital_master', 'view')) {
    header('Location: /access_denied.php');
    exit;
}
$sql = "
SELECT
    h.*,
    u.full_name AS created_by_name
FROM hospital_master h
LEFT JOIN users u ON h.created_by = u.id
WHERE h.status = 1
ORDER BY h.id DESC
";
$result = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hospital Master</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
 <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
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
        window.location.href = "delete.php?id=" + deleteId;
    };
});
    </script>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<body>
<div class="top-bar">  
    <div class="breadcrumb">
    <a href="/dashboard/index.php">Home</a><span>&gt;</span>
    <a href="/settings/settings.php">Settings</a><span>&gt;</span>
    <a href="/masters/masters.php">Masters</a><span>&gt;</span>
    <a>Hospital Masters</a>
</div>
    <h2 style="margin:0 auto;">Hospital Master</h2>
       <?php if (checkPermission('hospital_master','add')): ?>
    <a href="add_edit_hospital.php" class="btn btn-add">+ Add Hospital</a>
<?php endif; ?>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Hospital Code</th>
                    <th>Hospital Name</th>
                    <th>Director</th>
                    <th>Country</th>
                    <th>State</th>
                    <th>District</th>
                    <th>City</th>
                    <th>Landmark</th>
                    <th>Address</th>
                    <th>Pincode</th>
                    <th>Mobile</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Website</th>
                    <th>PAN</th>
                    <th>TIN</th>
                    <th>TIN Date</th>
                    <th>GSTIN</th>
                    <th>DL No</th>
                    <th>Detail 1</th>
                    <th>Detail 2</th>
                    <th>Detail 3</th>
                    <th>Detail 4</th>
                    <th>Logo</th>
                    <th>Secondary Logo</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Created By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if ($result && mysqli_num_rows($result) > 0) {
                $i = 1;
                while ($row = mysqli_fetch_assoc($result)) {
            ?>
                <tr>
                    <td><?= $i++; ?></td>
                    <td><?= htmlspecialchars($row['hospital_code']); ?></td>
                    <td><?= htmlspecialchars($row['hospital_name']); ?></td>
                    <td><?= htmlspecialchars($row['director_name']); ?></td>

                    <td><?= htmlspecialchars($row['country']); ?></td>
                    <td><?= htmlspecialchars($row['state']); ?></td>
                    <td><?= htmlspecialchars($row['district']); ?></td>
                    <td><?= htmlspecialchars($row['city']); ?></td>
                    <td><?= htmlspecialchars($row['landmark']); ?></td>
                    <td><?= nl2br(htmlspecialchars($row['address'])); ?></td>
                    <td><?= htmlspecialchars($row['pincode']); ?></td>

                    <td><?= htmlspecialchars($row['mobile']); ?></td>
                    <td><?= htmlspecialchars($row['phone']); ?></td>
                    <td><?= htmlspecialchars($row['email']); ?></td>
                    <td><?= htmlspecialchars($row['website']); ?></td>

                    <td><?= htmlspecialchars($row['pan_no']); ?></td>
                    <td><?= htmlspecialchars($row['tin_no']); ?></td>
                    <td><?= htmlspecialchars($row['tin_date']); ?></td>
                    <td><?= htmlspecialchars($row['gstin']); ?></td>
                    <td><?= htmlspecialchars($row['dl_no']); ?></td>

                    <td><?= htmlspecialchars($row['detail_1']); ?></td>
                    <td><?= htmlspecialchars($row['detail_2']); ?></td>
                    <td><?= htmlspecialchars($row['detail_3']); ?></td>
                    <td><?= htmlspecialchars($row['detail_4']); ?></td>
                    <td>
                        <?php if (!empty($row['logo']) && file_exists(__DIR__ . '/../../' . $row['logo'])) { ?>
                            <a href="/<?= $row['logo']; ?>" target="_blank">
                                <img src="/<?= $row['logo']; ?>" class="logo">
                            </a>
                        <?php } ?>
                    </td>

                    <td>
                        <?php if (!empty($row['secondary_logo']) && file_exists(__DIR__ . '/../../' . $row['secondary_logo'])) { ?>
                            <a href="/<?= $row['secondary_logo']; ?>" target="_blank">
                                <img src="/<?= $row['secondary_logo']; ?>" class="logo">
                            </a>
                        <?php } ?>
                    </td>
                    <td><?= date('d F, Y h:i A', strtotime($row['created_at'])); ?></td>
                    <td><?= date('d F, Y h:i A', strtotime($row['updated_at'])); ?></td>
                    <td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A'); ?></td>
                    
                   <td class="action-buttons">
<?php if (checkPermission('hospital_master','edit')): ?>
<button class="btn-icon btn-edit"
onclick="window.location.href='add_edit_hospital.php?id=<?= $row['id']; ?>'">
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php endif; ?>
<?php if (checkPermission('hospital_master','delete')): ?>
<button class="btn-icon btn-delete" title="Delete"
onclick="confirmDelete(<?= $row['id']; ?>)">
<i class="fa-solid fa-trash"></i>
</button>
<?php endif; ?>
</td>
                </tr>
            <?php
                }
            } else {
                echo '<tr><td colspan="30" style="text-align:center;">No hospitals found</td></tr>';
            }
            ?>
            </tbody>
        </table>
    </div>
</div>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this hospital?</p>
    <small>This action cannot be undone.</small>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
</body>
</html>