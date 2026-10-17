<?php
/*public_html/settings/users/user_list.php>*/
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* LOGIN CHECK */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/* PERMISSION CHECK */
if (!checkPermission('users','view')) {
    die('Access Denied');
}
/* FETCH USERS WITH ALL RELATED DATA */
$sql = "
SELECT 
    u.id,
    u.employee_id,
    u.full_name,
    u.username,
    u.email,
    u.mobile_no,
    u.gender,
    u.dob,
    u.marital_status,
    u.aadhar_no,
    u.status,
    rt.name AS role_name,
    etm_parent.type_name AS employee_type,
    GROUP_CONCAT(DISTINCT hm.hospital_name ORDER BY hm.hospital_name SEPARATOR ', ') AS hospitals,
    GROUP_CONCAT(DISTINCT bm.branch_name ORDER BY bm.branch_name SEPARATOR ', ') AS branches,
    GROUP_CONCAT(DISTINCT dm.department_name ORDER BY dm.department_name SEPARATOR ', ') AS departments,
    GROUP_CONCAT(DISTINCT qm.qualification_name ORDER BY qm.qualification_name SEPARATOR ', ') AS qualifications,
    GROUP_CONCAT(DISTINCT dsm.designation_name ORDER BY dsm.designation_name SEPARATOR ', ') AS designations,
    GROUP_CONCAT(DISTINCT etm.type_name ORDER BY etm.type_name SEPARATOR ', ') AS employee_sub_types,
    ro.full_name AS reporting_officer
FROM users u
LEFT JOIN rights_types rt ON rt.id = u.rights_type_id
LEFT JOIN employee_type_master etm_parent 
       ON etm_parent.id = u.employee_type_id
LEFT JOIN user_employee_sub_types ust ON ust.user_id = u.id
LEFT JOIN employee_type_master etm ON etm.id = ust.sub_type_id
LEFT JOIN user_hospitals uh ON uh.user_id = u.id
LEFT JOIN hospital_master hm ON hm.id = uh.hospital_id
LEFT JOIN user_branches ub ON ub.user_id = u.id
LEFT JOIN branch_master bm ON bm.id = ub.branch_id
LEFT JOIN user_departments ud ON ud.user_id = u.id
LEFT JOIN department_master dm ON dm.id = ud.department_id
LEFT JOIN user_qualifications uq ON uq.user_id = u.id
LEFT JOIN qualification_master qm ON qm.id = uq.qualification_id
LEFT JOIN user_designations udsg ON udsg.user_id = u.id
LEFT JOIN designation_master dsm ON dsm.designation_code = udsg.designation_code
LEFT JOIN users ro ON ro.id = u.reporting_officer_id
GROUP BY u.id
ORDER BY u.id DESC
";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User List</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
</head>
<body>
    <style>.btn-add{background:#28a745;color:#fff;padding:8px 14px;border-radius:5px;text-decoration:none}
.btn-add:hover{background:#218838}
.table-responsive{overflow-x:auto}
.badge{padding:4px 6px;font-size:12px;border-radius:3px;color:#fff}
.badge-success{background:#28a745}
.badge-secondary{background:#6c757d}
.action-buttons{display:flex;gap:6px;justify-content:center}
.btn-icon{
border:none;width:32px;height:32px;border-radius:6px;
display:flex;align-items:center;justify-content:center;
cursor:pointer;transition:0.2s;
}
.btn-edit{background:#e8f5e9;color:#2e7d32}
.btn-edit:hover{background:#2e7d32;color:#fff}
.btn-delete{background:#fdecea;color:#c62828}
.btn-delete:hover{background:#c62828;color:#fff}</style>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="top-bar">  
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/settings/users/users.php">User Management</a>
        <span>&gt;</span>
        <a>Manage Users</a>
    </div>
    <h2 style="margin:0 auto;">User Management</h2>
    <?php if (checkPermission('users','create')) { ?>
        <a href="add_user.php" class="btn btn-add">+ Add User</a>
    <?php } ?>
</div>
<div class="page-wrapper">
    <div class="table-responsive">
<table>
<thead>
<tr>
    <th>#</th>
    <th>Employee ID</th>
    <th>Full Name</th>
    <th>Username</th>
    <th>Email</th>
    <th>Mobile No.</th>
    <th>Gender</th>
    <th>DOB</th>
    <th>Marital Status</th>
    <th>Aadhaar</th>
    <th>Hospitals</th>
    <th>Branches</th>
    <th>Departments</th>
    <th>Designations</th>
    <th>Qualifications</th>
    <th>Employee Type</th>
    <th>Employee Sub Types</th>
    <th>Reporting Officer</th>
    <th>Role</th>
    <th>Status</th>
    <?php if (checkPermission('users','update')): ?>
        <th>Action</th>
    <?php endif; ?>
</tr>
</thead>
<tbody>
<?php if ($result->num_rows === 0): ?>
<tr><td colspan="10" style="text-align:center;">No users found</td></tr>

<?php else: $i=1; while($row=$result->fetch_assoc()): ?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($row['employee_id']) ?></td>
    <td><?= htmlspecialchars($row['full_name']) ?></td>
    <td><?= htmlspecialchars($row['username']) ?></td>
    <td><?= htmlspecialchars($row['email']) ?></td>
    <td><?= htmlspecialchars($row['mobile_no']) ?></td>
    <td><?= htmlspecialchars($row['gender']) ?></td>
    <td><?= $row['dob'] ? date('d-M-Y', strtotime($row['dob'])): '' ?></td>
    <td><?= htmlspecialchars($row['marital_status']) ?></td>
    <td><?= htmlspecialchars($row['aadhar_no']) ?></td>
    <td><?= htmlspecialchars($row['hospitals']) ?></td>
    <td><?= htmlspecialchars($row['branches']) ?></td>
    <td><?= htmlspecialchars($row['departments']) ?></td>
    <td><?= htmlspecialchars($row['designations']) ?></td>
    <td><?= htmlspecialchars($row['qualifications']) ?></td>
    <td><?= htmlspecialchars($row['employee_type']) ?></td>
    <td><?= htmlspecialchars($row['employee_sub_types']) ?></td>
    <td><?= htmlspecialchars($row['reporting_officer']) ?></td>
    <td><?= htmlspecialchars($row['role_name']) ?></td>
    <td>
<?php if ($row['status']=='Active'): ?>
    <span class="badge badge-success">Active</span>
<?php else: ?>
    <span class="badge badge-secondary">Inactive</span>
<?php endif; ?>
</td>
    <td class="action-buttons">
<?php if (checkPermission('users','update')) { ?>
<button class="btn-icon btn-edit"
onclick="window.location.href='edit_user.php?id=<?= $row['id']; ?>'">
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>
</td>

</tr>
<?php endwhile; endif; ?>

</tbody>
</table>
</div>
</body>
</html>