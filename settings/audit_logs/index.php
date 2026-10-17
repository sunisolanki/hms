<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!checkPermission('audit_logs','view')) {
    header('Location: /access_denied.php');
    exit;
}
$module_filter = $_GET['module'] ?? 'ALL';

$where = '';

if ($module_filter != 'ALL') {
    $module_filter_db = mysqli_real_escape_string($conn, $module_filter);
    $where = " WHERE module_name = '$module_filter_db' ";
}

$result = $conn->query("
    SELECT *
    FROM audit_logs
    $where
    ORDER BY id DESC
    LIMIT 500
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Audit Logs</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<!-- FONT AWESOME -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.top-bar{
    position:relative;
}
.top-bar h2{
    position:absolute;
    left:50%;
    transform:translateX(-50%);
    margin:0;
}
.table-wrapper{
    height: calc(100vh - 160px); /* adjust to your header/footer */
    overflow-y: auto;
    overflow-x: auto;
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
        <a>Audit Logs</a>
    </div>

    <h2>Audit Logs</h2>

    <div class="filter-box">
        <form method="GET">
            <select name="module" onchange="this.form.submit()">
                <option value="ALL">All</option>

                <?php
                $mods = $conn->query("
                    SELECT DISTINCT module_name
                    FROM audit_logs
                    WHERE module_name IS NOT NULL
                    ORDER BY module_name
                ");

                while($m = $mods->fetch_assoc()):
                ?>
                <option value="<?= htmlspecialchars($m['module_name']) ?>"
                    <?= $module_filter == $m['module_name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['module_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </form>
    </div>
</div>
</select>
    </form>
</div>

<div class="table-wrapper">
<table class="table">
<thead>
<tr>
    <th>ID</th>
    <th>Date Time</th>
    <th>User ID</th>
    <th>Username</th>
    <th>Employee Name</th>
    <th>Role</th>
    <th>Branch</th>
    <th>Module</th>
    <th>Action</th>
    <th>Record ID</th>
    <th>Description</th>
    <th>Old Values</th>
    <th>New Values</th>
    <th>IP Address</th>
    <th>City</th>
    <th>State</th>
    <th>Country</th>
    <th>Browser</th>
    <th>OS</th>
    <th>Device</th>
    <th>Session ID</th>
    <th>Status</th>
    <th>Created At</th>
</tr>
</thead>
<tbody>
<?php while($row = $result->fetch_assoc()): ?>
<tr>
<td><?= $row['id'] ?></td>
<td><?= date('d-M-Y h:i:s A', strtotime($row['log_time'])) ?></td>
<td><?= htmlspecialchars($row['user_id']) ?></td>
<td><?= htmlspecialchars($row['username']) ?></td>
<td><?= htmlspecialchars($row['employee_name']) ?></td>
<td><?= htmlspecialchars($row['role_name']) ?></td>
<td><?= htmlspecialchars($row['branch_id']) ?></td>
<td><?= htmlspecialchars($row['module_name']) ?></td>
<td><?= htmlspecialchars($row['action_type']) ?></td>
<td><?= htmlspecialchars($row['record_id']) ?></td>
<td><?= htmlspecialchars($row['description']) ?></td>
<td>
<?php
$old = json_decode($row['old_values'], true);

if(is_array($old)){
    foreach($old as $k=>$v){
        echo '<b>'.ucwords(str_replace('_',' ',$k)).':</b> '.htmlspecialchars($v).'<br>';
    }
}else{
    echo htmlspecialchars($row['old_values']);
}
?>
</td>

<td>
<?php
$new = json_decode($row['new_values'], true);

if(is_array($new)){
    foreach($new as $k=>$v){
        echo '<b>'.ucwords(str_replace('_',' ',$k)).':</b> '.htmlspecialchars($v).'<br>';
    }
}else{
    echo htmlspecialchars($row['new_values']);
}
?>
</td>
<td><?= htmlspecialchars($row['ip_address']) ?></td>
<td><?= htmlspecialchars($row['city']) ?></td>
<td><?= htmlspecialchars($row['state']) ?></td>
<td><?= htmlspecialchars($row['country']) ?></td>
<td><?= htmlspecialchars($row['browser']) ?></td>
<td><?= htmlspecialchars($row['os']) ?></td>
<td><?= htmlspecialchars($row['device_type']) ?></td>
<td><?= htmlspecialchars($row['session_id']) ?></td>
<td>
<?php if($row['status']=='SUCCESS'): ?>
<span class="success">SUCCESS</span>
<?php else: ?>
<span class="failed">FAILED</span>
<?php endif; ?>
</td>
<td><?= $row['created_at'] ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div>
</body>
</html>