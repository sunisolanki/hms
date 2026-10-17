<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

if (!checkPermission('doctor_master','view')) {
    header('Location: /access_denied.php'); exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Doctors List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
    
<link rel="stylesheet" href="/assets/css/style.css">
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
    <a>Doctors</a>
</div>    <h2 style="margin:0;">Doctors</h2>

    <?php if (checkPermission('doctor_master','add')): ?>
        <button class="btn" onclick="window.location.href='/masters/doctor/add_edit_doctor.php'">
    + Add Doctor
</button>
    <?php endif; ?>
</div>



<div class="table-wrap">
<table>
<thead>
<tr>
    <th>#</th>
    
    <th>Photo</th>
    <th>Doctor Name</th>
    <th>Doctor ID</th>
    <th>Doctor Type</th>

    <th>Employee ID</th>
    <th>Username</th>
    <th>Email</th>
    <th>Mobile</th>

    <th>Hospitals</th>
    <th>Branches</th>
    <th>Departments</th>

    <th>License No</th>
    <th>Surgeon</th>
    <th>Slot (Min)</th>
    <th>Specialization</th>
    <th>Charges</th>
    <th>Schedule</th>
    <th>Share</th>
    <th>Media</th>
    <th>Status</th>
    <th>Created</th>
    <th>Updated</th>
    <th>Created By</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php
$user_id = (int)$_SESSION['user_id'];
if((int)$_SESSION['rights_type_id'] === 1){
    // Super Admin
    $sql = "
    SELECT
        dm.id AS doctor_id,
        dm.user_id,
        dm.license_no,
        dm.is_surgeon,
        dm.time_slot_minutes,
        u.full_name,
        u.employee_id,
        u.username,
        u.email,
        u.mobile_no,
        u.status,
        dm.created_at,
        dm.updated_at, 
        cu.full_name AS created_by_name,
        etm.type_name AS doctor_type,
        GROUP_CONCAT(DISTINCT h.hospital_name ORDER BY h.hospital_name SEPARATOR ', ') AS hospitals,
        GROUP_CONCAT(DISTINCT b.branch_name ORDER BY b.branch_name SEPARATOR ', ') AS branches,
        GROUP_CONCAT(DISTINCT d.department_name ORDER BY d.department_name SEPARATOR ', ') AS departments,
        GROUP_CONCAT(DISTINCT sm.specialization_name ORDER BY sm.specialization_name SEPARATOR ', ') AS specializations,
        m.profile_photo,
        m.signature_image,
        (SELECT COUNT(*) FROM doctor_charges dc WHERE dc.doctor_id = dm.id) AS charge_count,
        (SELECT COUNT(*) FROM doctor_working_days wd WHERE wd.doctor_id = dm.id) AS schedule_count,
        (SELECT COUNT(*) FROM doctor_share ds WHERE ds.doctor_id = dm.id) AS share_count
    FROM doctor_master dm
    JOIN users u ON u.id = dm.user_id
    LEFT JOIN users cu
    ON cu.id = dm.created_by
    LEFT JOIN user_employee_sub_types ust
        ON ust.user_id = u.id
    LEFT JOIN employee_type_master etm
        ON etm.id = ust.sub_type_id
        AND etm.parent_id = 2
    LEFT JOIN user_hospitals uh
        ON uh.user_id = u.id
    LEFT JOIN hospital_master h
        ON h.id = uh.hospital_id
    LEFT JOIN user_branches ub
        ON ub.user_id = u.id
    LEFT JOIN branch_master b
        ON b.id = ub.branch_id
    LEFT JOIN user_departments ud
        ON ud.user_id = u.id
    LEFT JOIN department_master d
        ON d.id = ud.department_id
    LEFT JOIN doctor_specializations dsp
        ON dsp.doctor_id = dm.id
    LEFT JOIN specialization_master sm
        ON sm.specialization_code = dsp.specialization_code
    LEFT JOIN doctor_media m
        ON m.doctor_id = dm.id
    GROUP BY dm.id
    ORDER BY u.full_name
    ";
} else {
    // Hospital-wise access
    $sql = "
    SELECT
        dm.id AS doctor_id,
        dm.user_id,
        dm.license_no,
        dm.is_surgeon,
        dm.time_slot_minutes,
        u.full_name,
        u.employee_id,
        u.username,
        u.email,
        u.mobile_no,
        u.status,
        dm.created_at,
        dm.updated_at,
        cu.full_name AS created_by_name,
        etm.type_name AS doctor_type,
        GROUP_CONCAT(DISTINCT h.hospital_name ORDER BY h.hospital_name SEPARATOR ', ') AS hospitals,
        GROUP_CONCAT(DISTINCT b.branch_name ORDER BY b.branch_name SEPARATOR ', ') AS branches,
        GROUP_CONCAT(DISTINCT d.department_name ORDER BY d.department_name SEPARATOR ', ') AS departments,
        GROUP_CONCAT(DISTINCT sm.specialization_name ORDER BY sm.specialization_name SEPARATOR ', ') AS specializations,
        m.profile_photo,
        m.signature_image,
        (SELECT COUNT(*) FROM doctor_charges dc WHERE dc.doctor_id = dm.id) AS charge_count,
        (SELECT COUNT(*) FROM doctor_working_days wd WHERE wd.doctor_id = dm.id) AS schedule_count,
        (SELECT COUNT(*) FROM doctor_share ds WHERE ds.doctor_id = dm.id) AS share_count
    FROM doctor_master dm
    JOIN users u ON u.id = dm.user_id
    LEFT JOIN users cu
    ON cu.id = dm.created_by
    INNER JOIN user_hospitals doctor_hospital
        ON doctor_hospital.user_id = u.id
    INNER JOIN user_hospitals my_hospital
        ON my_hospital.hospital_id = doctor_hospital.hospital_id
        AND my_hospital.user_id = '$user_id'
    LEFT JOIN user_employee_sub_types ust
        ON ust.user_id = u.id
    LEFT JOIN employee_type_master etm
        ON etm.id = ust.sub_type_id
        AND etm.parent_id = 2
    LEFT JOIN user_hospitals uh
        ON uh.user_id = u.id
    LEFT JOIN hospital_master h
        ON h.id = uh.hospital_id
    LEFT JOIN user_branches ub
        ON ub.user_id = u.id
    LEFT JOIN branch_master b
        ON b.id = ub.branch_id
    LEFT JOIN user_departments ud
        ON ud.user_id = u.id
    LEFT JOIN department_master d
        ON d.id = ud.department_id
    LEFT JOIN doctor_specializations dsp
        ON dsp.doctor_id = dm.id
    LEFT JOIN specialization_master sm
        ON sm.specialization_code = dsp.specialization_code
    LEFT JOIN doctor_media m
        ON m.doctor_id = dm.id
    GROUP BY dm.id
    ORDER BY u.full_name
    ";
}
$res = $conn->query($sql);$sn = 1;
while ($row = $res->fetch_assoc()) {
    $photo = $row['profile_photo']
        ? '/'.$row['profile_photo']
        : '/assets/img/user.png';
    echo "<tr>";
    echo "<td>{$sn}</td>";
    echo "<td>
            <img src='{$photo}' class='avatar'>
          </td>";
    echo "<td>".htmlspecialchars($row['full_name'])."</td>";
    echo "<td style='font-family:monospace;font-weight:600'>{$row['doctor_id']}
      </td>";
    echo "<td>".htmlspecialchars($row['doctor_type'] ?: '—')."</td>";
    echo "<td>".htmlspecialchars($row['employee_id'])."</td>";
    echo "<td>".htmlspecialchars($row['username'])."</td>";
    echo "<td>".htmlspecialchars($row['email'])."</td>";
    echo "<td>".htmlspecialchars($row['mobile_no'])."</td>";
    echo "<td class='small'>".($row['hospitals'] ?: '—')."</td>";
    echo "<td class='small'>".($row['branches'] ?: '—')."</td>";
    echo "<td class='small'>".($row['departments'] ?: '—')."</td>";
    echo "<td>".htmlspecialchars($row['license_no'])."</td>";
    echo "<td>".($row['is_surgeon'] ? 'Yes' : 'No')."</td>";
    echo "<td>".(int)$row['time_slot_minutes']."</td>";
    echo "<td class='small'>".($row['specializations'] ?: '—')."</td>";
    echo "<td>
            <span class='badge ".($row['charge_count'] ? 'badge-ok' : 'badge-no')."'>
                ".($row['charge_count'] ? 'Configured' : 'Not Set')."
            </span>
          </td>";
    echo "<td>
            <span class='badge ".($row['schedule_count'] ? 'badge-ok' : 'badge-no')."'>
                ".($row['schedule_count'] ? 'Available' : 'Not Set')."
            </span>
          </td>";
    echo "<td>
            <span class='badge ".($row['share_count'] ? 'badge-ok' : 'badge-no')."'>
                ".($row['share_count'] ? 'Defined' : 'Not Set')."
            </span>
          </td>";
    echo "<td>";
    echo $row['profile_photo'] ? '📷 ' : '';
    echo $row['signature_image'] ? '✍️' : '';
    if (!$row['profile_photo'] && !$row['signature_image']) echo '—';
    echo "</td>";
    echo "<td>";
if ($row['status'] == 1 || strtolower($row['status']) == 'active') {
    echo "<span class='status-badge status-active'>Active</span>";
} else {
    echo "<span class='status-badge status-inactive'>Inactive</span>";
}
echo "</td>";

echo "<td>";
echo !empty($row['created_at'])
    ? date('d-M-Y h:i A', strtotime($row['created_at']))
    : '-';
echo "</td>";

echo "<td>";
echo !empty($row['updated_at'])
    ? date('d-M-Y h:i A', strtotime($row['updated_at']))
    : '-';
echo "</td>";
echo "<td>";
echo htmlspecialchars($row['created_by_name'] ?? 'N/A');
echo "</td>";
echo "<td class='action-buttons'>";
if (checkPermission('doctor_master','edit')) {
    echo "<button class='btn-icon btn-edit'
        onclick=\"window.location.href='add_edit_doctor.php?user_id={$row['user_id']}'\">
        <i class='fa-solid fa-pen-to-square'></i>
    </button>";
}
echo "</td>";
    echo "</tr>";
    $sn++;
}
?>
</tbody>
</table>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    const userId = params.get('user_id');
    if (userId) {
        const select = document.getElementById('user_id');
        if (select) {
            select.value = userId;
            // 🔥 force change event so AJAX loads data
            select.dispatchEvent(new Event('change'));
        }
    }
});
</script>
</body>
</html>