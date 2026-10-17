<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once 'service_code_helper.php';

/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php'); exit;
}

/* VIEW PERMISSION */
if (!checkPermission('service_master','view')) {
    header('Location: /access_denied.php'); exit;
}
/* DELETE */
if (isset($_GET['delete']) && checkPermission('service_master','delete')) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $q = mysqli_query($conn,"
            SELECT *
            FROM service_master
            WHERE id = $id
        ");
        if ($service = mysqli_fetch_assoc($q)) {
            addAuditLog(
                'Service Master',
                'Delete',
                "Service Deleted : {$service['service_name']}",
                $id,
                json_encode($service),
                '',
                'SUCCESS'
            );
        }
        mysqli_query($conn,"DELETE FROM service_master WHERE id=$id");
    }
    header("Location: service_master.php");
    exit;
}
/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if (
        ($id > 0 && !checkPermission('service_master','update')) ||
        ($id === 0 && !checkPermission('service_master','add'))
    ) {
        header('Location: /access_denied.php');
        exit;
    }
    $category_type = $_POST['category_type'];
    $service_type = $_POST['service_type'];
    $main_service_id = (int)($_POST['main_service_id'] ?? 0);
    $service_name    = mysqli_real_escape_string($conn,$_POST['service_name']);
    $hospital_id     = (int)$_POST['hospital_id'];
    $branch_id       = (int)$_POST['branch_id'];
    $wef_date        = $_POST['wef_date'] ?: NULL;
    $amount          = $_POST['amount'] ?: 0;
    $service_mode    = implode(',', $_POST['service_mode'] ?? []);
$specimen_id = (int)($_POST['specimen_id'] ?? 0);
$container_id = (int)($_POST['container_id'] ?? 0);
$specimen_uom_value = (isset($_POST['specimen_uom_value']) && $_POST['specimen_uom_value'] !== '' && is_numeric($_POST['specimen_uom_value'])) ? (float)$_POST['specimen_uom_value'] : null;
$specimen_uom_unit = trim($_POST['specimen_uom_unit'] ?? '');
$allowedSpecimenUnits = ['gm', 'ml', 'gm/ml'];
if (!in_array($specimen_uom_unit, $allowedSpecimenUnits, true)) {
    $specimen_uom_unit = '';
}
$method_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['method_id'] ?? []), function($v) { return $v > 0; })));
$machine_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['machine_id'] ?? []), function($v) { return $v > 0; })));
$method_id = implode(',', $method_ids);
$machine_id = implode(',', $machine_ids);

$sample_collection_time = (int)($_POST['sample_collection_time'] ?? 0);
    $service_duration       = (int)($_POST['service_duration'] ?? 0);
    $service_start_time = $_POST['service_start_time'] ?? null;
    $service_close_time = $_POST['service_close_time'] ?? null;
    $status          = $_POST['status'];
    $created_by = $_SESSION['user_id'];


$oldService = [];

if ($id > 0) {

    $q = mysqli_query($conn,"
        SELECT *
        FROM service_master
        WHERE id = $id
    ");

    $oldService = mysqli_fetch_assoc($q);
}
$changedFields = [];
if ($id > 0) {

    $fields = [
        'category_type'            => 'Category Type',
        'service_type'             => 'Service Type',
        'main_service_id'          => 'Main Service',
        'service_name'             => 'Service Name',
        'hospital_id'              => 'Hospital',
        'branch_id'                => 'Branch',
        'wef_date'                 => 'W.E.F Date',
        'amount'                   => 'Amount',
        'service_mode'             => 'Service Mode',
'specimen_id'              => 'Specimen',
'container_id'             => 'Container',
'method_id'  => 'Method',
'machine_id' => 'Machine',
'sample_collection_time'   => 'Sample Collection Time',
        'service_duration'         => 'Service Duration',
        'service_start_time'       => 'Service Start Time',
        'service_close_time'       => 'Service Close Time',
        'status'                   => 'Status'
    ];

    foreach ($fields as $dbField => $label) {

        $oldValue = (string)($oldService[$dbField] ?? '');

        switch ($dbField) {

            case 'category_type':
                $newValue = $category_type;
                break;

            case 'service_type':
                $newValue = $service_type;
                break;

            case 'main_service_id':
                $newValue = (string)$main_service_id;
                break;

            case 'service_name':
                $newValue = $service_name;
                break;

            case 'hospital_id':
                $newValue = (string)$hospital_id;
                break;

            case 'branch_id':
                $newValue = (string)$branch_id;
                break;

            case 'wef_date':
                $newValue = (string)$wef_date;
                break;

            case 'amount':
                $newValue = (string)$amount;
                break;

            case 'service_mode':
                $newValue = $service_mode;
                break;

case 'specimen_id':
    $newValue = (string)$specimen_id;
    break;

case 'container_id':
    $newValue = (string)$container_id;
    break;
    
    case 'method_id':
    $newValue = (string)$method_id;
    break;
case 'machine_id':
    $newValue = (string)$machine_id;
    break;
    
    case 'specimen_uom_value':
    $newValue = $specimen_uom_value !== null ? (string)$specimen_uom_value : '';
    break;
case 'specimen_uom_unit':
    $newValue = $specimen_uom_unit;
    break;
    
            case 'sample_collection_time':
                $newValue = (string)$sample_collection_time;
                break;

            case 'service_duration':
                $newValue = (string)$service_duration;
                break;

            case 'service_start_time':
                $newValue = (string)$service_start_time;
                break;

            case 'service_close_time':
                $newValue = (string)$service_close_time;
                break;

            case 'status':
                $newValue = $status;
                break;

            default:
                $newValue = '';
        }

        if ($oldValue !== $newValue) {

            $changedFields[$label] = [
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }
}
    if ($id > 0) {

        mysqli_query($conn,"
            UPDATE service_master SET
                category_type='$category_type',
                service_type='$service_type',
                main_service_id='$main_service_id',
                service_name='$service_name',
                hospital_id='$hospital_id',
                branch_id='$branch_id',
                wef_date=".($wef_date?"'$wef_date'":"NULL").",
                amount='$amount',
                service_mode='$service_mode',
specimen_id='$specimen_id',
container_id='$container_id',
specimen_uom_value=".($specimen_uom_value !== null ? "'".$specimen_uom_value."'" : "NULL").",
specimen_uom_unit=".($specimen_uom_unit !== '' ? "'".mysqli_real_escape_string($conn, $specimen_uom_unit)."'" : "NULL").",

method_id=".($method_id !== '' ? "'".mysqli_real_escape_string($conn, $method_id)."'" : "NULL").",
machine_id=".($machine_id !== '' ? "'".mysqli_real_escape_string($conn, $machine_id)."'" : "NULL").",

sample_collection_time='$sample_collection_time',
                service_duration='$service_duration',
                service_start_time='$service_start_time',
                service_close_time='$service_close_time',
                status='$status'
            WHERE id=$id
        ");

    } else {

        $service_code = generateServiceCode($category_type, $conn);

        mysqli_query($conn,"
            INSERT INTO service_master SET
                category_type='$category_type',
                service_type='$service_type',
                main_service_id='$main_service_id',
                service_code='$service_code',
                service_name='$service_name',
                hospital_id='$hospital_id',
                branch_id='$branch_id',
                wef_date=".($wef_date?"'$wef_date'":"NULL").",
                amount='$amount',
                service_mode='$service_mode',
specimen_id='$specimen_id',
container_id='$container_id',
method_id=".($method_id !== '' ? "'".mysqli_real_escape_string($conn, $method_id)."'" : "NULL").",
machine_id=".($machine_id !== '' ? "'".mysqli_real_escape_string($conn, $machine_id)."'" : "NULL").",

sample_collection_time='$sample_collection_time',
                service_duration='$service_duration',
                service_start_time='$service_start_time',
                service_close_time='$service_close_time',
                status='$status',
                created_by='$created_by'
        ");
    }
/* ================= AUDIT LOG ================= */

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';

if ($id > 0) {

    if (!empty($changedFields)) {

        $oldChangedFields = [];
        $newChangedFields = [];

        foreach ($changedFields as $field => $values) {

            $oldChangedFields[$field] = $values['old'];
            $newChangedFields[$field] = $values['new'];
        }

        addAuditLog(
            'Service Master',
            'Update',
            "Service Updated : {$service_name}",
            $id,
            json_encode($oldChangedFields),
            json_encode($newChangedFields),
            'SUCCESS'
        );
    }

} else {

    $serviceId = mysqli_insert_id($conn);

    $newValues = [
        'Category Type'            => $category_type,
        'Service Type'             => $service_type,
        'Main Service'             => $main_service_id,
        'Service Name'             => $service_name,
        'Hospital'                 => $hospital_id,
        'Branch'                   => $branch_id,
        'W.E.F Date'               => $wef_date,
        'Amount'                   => $amount,
'Service Mode'             => $service_mode,
'Specimen'                 => $specimen_id,
'Container'                => $container_id,
'Method'  => $method_id,
'Machine' => $machine_id,
'Specimen UOM Value' => $specimen_uom_value,
'Specimen UOM Unit' => $specimen_uom_unit,
'Sample Collection Time'   => $sample_collection_time,
        'Service Duration'         => $service_duration,
        'Service Start Time'       => $service_start_time,
        'Service Close Time'       => $service_close_time,
        'Status'                   => $status
    ];

    addAuditLog(
        'Service Master',
        'Create',
        "Service Created : {$service_name}",
        $serviceId,
        '',
        json_encode($newValues),
        'SUCCESS'
    );
}
    header("Location: service_master.php");
    exit;
}

/* FETCH LIST */
$res = mysqli_query($conn,"
    SELECT s.*,
        h.hospital_name,
        b.branch_name,
        p.service_name AS parent_service,
        sp.specimen_name,
        c.container_name,
        (
            SELECT GROUP_CONCAT(lmm.method_name ORDER BY lmm.method_name SEPARATOR ', ')
            FROM lab_method_master lmm
            WHERE FIND_IN_SET(lmm.id, s.method_id)
        ) AS method_names,
        (
            SELECT GROUP_CONCAT(lmc.machine_name ORDER BY lmc.machine_name SEPARATOR ', ')
            FROM lab_machine_master lmc
            WHERE FIND_IN_SET(lmc.id, s.machine_id)
        ) AS machine_names,
        u.full_name AS created_by_name
    FROM service_master s
    LEFT JOIN hospital_master h ON h.id = s.hospital_id
    LEFT JOIN branch_master b ON b.id = s.branch_id
    LEFT JOIN service_master p ON p.id = s.main_service_id
    LEFT JOIN lab_specimen_master sp ON sp.id = s.specimen_id
    LEFT JOIN lab_container_master c ON c.id = s.container_id
    LEFT JOIN users u ON u.id = s.created_by
    ORDER BY s.id DESC
");

/* MAIN SERVICES */
$mainServices = mysqli_query($conn,"
    SELECT id, service_name
    FROM service_master
    WHERE service_type='Main Service' AND status='Active'
");

/* HOSPITALS */
$hospitals = mysqli_query($conn,"
    SELECT id, hospital_name
    FROM hospital_master
    WHERE status='1'
    ORDER BY hospital_name
");
$specimens = mysqli_query($conn,"
    SELECT id,specimen_name
    FROM lab_specimen_master
    WHERE status='Active'
    ORDER BY specimen_name
");

$containers = mysqli_query($conn,"
    SELECT id,container_name
    FROM lab_container_master
    WHERE status='Active'
    ORDER BY container_name
");


$methods = mysqli_query($conn,"
    SELECT id, method_name
    FROM lab_method_master
    WHERE status='Active'
    ORDER BY method_name
");
$machines = mysqli_query($conn,"
    SELECT id, machine_name
    FROM lab_machine_master
    WHERE status=1
    ORDER BY machine_name
");

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Service Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<style>

.btn{padding:6px 12px;font-size:13px;border-radius:4px;text-decoration:none;cursor:pointer;  max-width: fit-content;}
.btn-success{background:#28a745;color:#fff}
.btn-primary{background:#007bff;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.badge-success{background:#28a745;color:#fff;padding:3px 6px;border-radius:3px}
.badge-secondary{background:#6c757d;color:#fff;padding:3px 6px;border-radius:3px}
.modal-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}
/* MODAL GRID SYSTEM – 3 FIELDS PER ROW */
#serviceModal form{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
}
#serviceModal .form-group{
    width:100%;
    min-width:0;
}
#serviceModal .form-group label{
    display:block;
    font-weight:600;
    font-size:13px;
    margin-bottom:5px;
}
#serviceModal .form-group input,
#serviceModal .form-group select{
    width:100%;
    padding:7px;
    box-sizing:border-box;
}
#serviceModal .full-width{
    grid-column:1/-1;
}
#serviceModal .actions{
    grid-column:1/-1;
    text-align:right;
    margin-top:5px;
}
/* MODAL */
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5)}
.modal-box1{background:#fff;max-width:95%;margin:10px auto;padding:20px;border-radius:6px;max-height:95vh;overflow-y:auto;}
label{font-weight:600;font-size:13px;margin-top:10px;display:block}
input,select{width:100%;padding:7px}
.actions{text-align:right;margin-top:15px}
@media(max-width:900px){
#serviceModal .form-group{width:calc(50% - 6px);}
}
@media(max-width:600px){
#serviceModal .form-group{width:100%;}
}
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
        <a>Service Master</a>
    </div>
    <h2 style="margin:0 auto;">Service Master</h2>
    <?php if (checkPermission('service_master','add')) { ?>
        <button class="btn" onclick="openModal()">+ Add Service</button>
    <?php } ?>
</div>
<div class="container">
    <div class="table-responsive">
<table>
<thead>
<tr>
<th>#</th>
<th>Category</th>
<th>Code</th>
<th>Name</th>
<th>Type</th>
<th>Main Service</th>
<th>Hospital</th>
<th>Branch</th>
<th>Amount</th>
<th>Mode</th>
<th>Specimen</th>
<th>Container</th>
<th>Specimen UOM</th>
<th>Method</th>
<th>Machine</th>
<th>Sample Collection Time</th>
<th>Service Duration</th>
<th>Service Start Time</th>
<th>Service End Time</th>
<th>Status</th>
<th>Created</th>
<th>Updated</th>
<th>Created By</th>
<th>Action</th>
</tr>
</thead>
<tbody>

<?php $i=1; while($row=mysqli_fetch_assoc($res)){ ?>
<tr>
<td><?= $i++ ?></td>
<td><?= htmlspecialchars($row['category_type']) ?></td>
<td><?= htmlspecialchars($row['service_code']) ?></td>
<td><?= htmlspecialchars($row['service_name']) ?></td>
<td><?= htmlspecialchars($row['service_type']) ?></td>
<td><?= htmlspecialchars($row['parent_service'] ?: '—') ?></td>
<td><?= htmlspecialchars($row['hospital_name']) ?></td>
<td><?= htmlspecialchars($row['branch_name']) ?></td>
<td><?= number_format($row['amount'],2) ?></td>
<td><?= htmlspecialchars($row['service_mode']) ?></td>
<td><?= htmlspecialchars($row['specimen_name'] ?? '—') ?></td>
<td><?= htmlspecialchars($row['container_name'] ?? '—') ?></td>
<td>
    <?= ($row['specimen_uom_value'] !== null && $row['specimen_uom_value'] !== '')
        ? htmlspecialchars(rtrim(rtrim(number_format((float)$row['specimen_uom_value'], 3, '.', ''), '0'), '.').' '.($row['specimen_uom_unit'] ?? ''))
        : '—' ?>
</td>
<td><?= htmlspecialchars($row['method_names'] ?? '—') ?></td>
<td><?= htmlspecialchars($row['machine_names'] ?? '—') ?></td>
<td><?= htmlspecialchars($row['sample_collection_time']) ?></td>
<td><?= htmlspecialchars($row['service_duration']) ?></td>
<td><?= htmlspecialchars($row['service_start_time']) ?></td>
<td><?= htmlspecialchars($row['service_close_time']) ?></td>
<td>
<?= $row['status']=='Active'
? '<span class="badge-success">Active</span>'
: '<span class="badge-secondary">Inactive</span>' ?>
</td>
<td>
<?= !empty($row['created_at'])
? date('d-M-Y h:i A', strtotime($row['created_at']))
: '-' ?>
</td>
<td>
<?= !empty($row['updated_at'])
? date('d-M-Y h:i A', strtotime($row['updated_at']))
: '-' ?>
</td>
<td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A') ?></td>
<td class="action-buttons">

<?php if(checkPermission('service_master','update')){ ?>
<button class="btn-icon btn-edit"
onclick='editService(<?= json_encode($row) ?>)'>
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

<?php if(checkPermission('service_master','delete')){ ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id'] ?>)">
    <i class="fa-solid fa-trash"></i>
</button>
<?php } ?>

</td>
</tr>
<?php } ?>

</tbody>
</table>
</div>
</div>

<!-- MODAL -->
<div class="modal" id="serviceModal">
<div class="modal-box1">

<div class="modal-header">
    <h3 id="modalTitle">Add Service</h3>
    <button type="button" class="btn btn-danger btn-sm" onclick="closeModal()">Cancel</button>
</div>


<form method="post">
<input type="hidden" name="id" id="id">

<div class="form-group">
    <label>Category Type *</label>
    <select name="category_type" id="category_type" required>
        <option>Laboratory</option>
        <option>Radiology</option>
        <option>Hospital Services</option>
        <option>Ambulance</option>
        <option>Procedure</option>
        <option>Minor Procedure</option>
        <option>Physiotherapy</option>
        <option>Misc</option>
        <option>Other Services</option>
    </select>
</div>

<div class="form-group">
    <label>Service Type *</label>
    <select name="service_type" id="service_type" onchange="toggleMainService()" required>
        <option>Main Service</option>
        <option>Service</option>
    </select>
</div>

<div class="form-group">
    <label>Main Service</label>
    <select name="main_service_id" id="main_service_id" disabled>
        <option value="0">— Select —</option>
        <?php while($m=mysqli_fetch_assoc($mainServices)){ ?>
        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['service_name']) ?></option>
        <?php } ?>
    </select>
</div>

<div class="form-group">
    <label>Service Name *</label>
    <input name="service_name" id="service_name" required>
</div>
    <div class="form-group">
<label>Hospital *</label>
<select name="hospital_id" id="hospital_id" required onchange="loadBranches(this.value)">
<option value="">Select Hospital</option>
<?php mysqli_data_seek($hospitals,0); while($h=mysqli_fetch_assoc($hospitals)){ ?>
<option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['hospital_name']) ?></option>
<?php } ?>
</select>
</div>

<div class="form-group">
<label>Branch *</label>
<select name="branch_id" id="branch_id" required>
<option value="">Select Branch</option>
</select>
</div>

<div class="form-group">
    <label>Specimen</label>
    <select name="specimen_id" id="specimen_id">
        <option value="0">Select Specimen</option>
        <?php while($s=mysqli_fetch_assoc($specimens)){ ?>
        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['specimen_name']) ?></option>
        <?php } ?>
    </select>
</div>

<div class="form-group">
    <label>Container</label>
    <select name="container_id" id="container_id">
        <option value="0">Select Container</option>
        <?php while($c=mysqli_fetch_assoc($containers)){ ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['container_name']) ?></option>
        <?php } ?>
    </select>
</div>

<div class="form-group">
    <label>Specimen UOM Value</label>
    <input type="number" name="specimen_uom_value" id="specimen_uom_value" min="0" step="0.001" placeholder="Enter numeric value">
</div>
<div class="form-group">
    <label>Specimen UOM Unit</label>
    <select name="specimen_uom_unit" id="specimen_uom_unit">
        <option value="">Select Unit</option>
        <option value="gm">gm</option>
        <option value="ml">ml</option>
        <option value="gm/ml">gm/ml</option>
    </select>
</div>

<div class="form-group">
    <label>Method (Multiple Selection)</label>
    <select name="method_id[]" id="method_id" multiple size="4">
        <?php while($m = mysqli_fetch_assoc($methods)){ ?>
        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['method_name']) ?></option>
        <?php } ?>
    </select>
    <small>Hold Ctrl (Windows) to select multiple methods.</small>
</div>
<div class="form-group">
    <label>Machine (Multiple Selection)</label>
    <select name="machine_id[]" id="machine_id" multiple size="4">
        <?php while($m = mysqli_fetch_assoc($machines)){ ?>
        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['machine_name']) ?></option>
        <?php } ?>
    </select>
    <small>Hold Ctrl (Windows) to select multiple machines.</small>
</div>



<div class="form-group">
<label>W.e.f</label>
<input type="date" name="wef_date" id="wef_date">
</div>

<div class="form-group">
<label>Amount</label>
<input type="number" step="0.01" name="amount" id="amount">
</div>
    <div class="form-group">
<label>Service In / Service Out *</label>
<select name="service_mode[]" id="service_mode" multiple required>
<option value="Service In">Service In</option>
<option value="Service Out">Service Out</option>
</select>
</div>
    <div class="form-group">
        <label>Sample Collection Time (HH:MM)</label>
        <div style="display:flex;gap:5px;">
            <input type="number" id="sample_hh" min="0" placeholder="HH">
            <input type="number" id="sample_mm" min="0" max="59" placeholder="MM">
        </div>
        </div>
    <div class="form-group">
        <label>Service Duration (HH:MM)</label>
        <div style="display:flex;gap:5px;">
            <input type="number" id="duration_hh" min="0" placeholder="HH">
            <input type="number" id="duration_mm" min="0" max="59" placeholder="MM">
        </div>
        

<input type="hidden" name="sample_collection_time" id="sample_collection_time">
<input type="hidden" name="service_duration" id="service_duration">

</div>
    <div class="form-group">
        <label>Service Start Time</label>
        <input type="time" name="service_start_time" id="service_start_time" required>
    </div>
    <div class="form-group">
        <label>Service Close Time</label>
        <input type="time" name="service_close_time" id="service_close_time" required>
</div>
    <div class="form-group">
<label>Status</label>
<select name="status" id="status">
<option>Active</option>
<option>Inactive</option>
</select>
</div>
<div class="actions">
<button type="submit" class="btn btn-success" onclick="calculateTimes()">Save</button>
<button type="button" class="btn btn-danger" onclick="closeModal()">Cancel</button>
</div>
</form>
</div>
</div>
<script>
function openModal(){
document.getElementById('serviceModal').style.display='block';
document.getElementById('modalTitle').innerText='Add Service';
document.querySelector('#serviceModal form').reset();
document.getElementById('id').value='';
document.getElementById('main_service_id').disabled=true;
}

function closeModal(){
document.getElementById('serviceModal').style.display='none';
}

function toggleMainService(){
document.getElementById('main_service_id').disabled =
document.getElementById('service_type').value !== 'Service';
}

function loadBranches(hospitalId, selectedBranchId=null){
fetch('load_branches.php?hospital_id=' + hospitalId)
.then(res => res.text())
.then(html => {
document.getElementById('branch_id').innerHTML = html;
if (selectedBranchId) {
document.getElementById('branch_id').value = selectedBranchId;
}
});
}

function editService(data){
openModal();
document.getElementById('modalTitle').innerText='Edit Service';

for (let k in data) {
    const element = document.getElementById(k);
    if (!element) continue;
    if (element.multiple && Array.isArray(data[k])) {
        Array.from(element.options).forEach(option => {
            option.selected = data[k].includes(option.value);
        });
    } else if (k !== 'method_id' && k !== 'machine_id') {
        element.value = data[k] ?? '';
    }
}
document.getElementById('method_id').querySelectorAll('option').forEach(option => {
    option.selected = (data.method_id || '').toString().split(',').includes(option.value);
});
document.getElementById('machine_id').querySelectorAll('option').forEach(option => {
    option.selected = (data.machine_id || '').toString().split(',').includes(option.value);
});

document.getElementById('service_mode').querySelectorAll('option').forEach(option => {
    option.selected = (data.service_mode || '').toString().split(',').includes(option.value);
});
if (data.service_type === 'Service') {
document.getElementById('main_service_id').disabled = false;
}

// ✅ Sample Collection Time
    if (data.sample_collection_time) {
        document.getElementById('sample_hh').value = Math.floor(data.sample_collection_time / 60);
        document.getElementById('sample_mm').value = data.sample_collection_time % 60;
    }

    // ✅ Service Duration
    if (data.service_duration) {
        document.getElementById('duration_hh').value = Math.floor(data.service_duration / 60);
        document.getElementById('duration_mm').value = data.service_duration % 60;
    }

loadBranches(data.hospital_id, data.branch_id);
}
function calculateTimes() {
    const sh = parseInt(document.getElementById('sample_hh').value || 0);
    const sm = parseInt(document.getElementById('sample_mm').value || 0);
    const dh = parseInt(document.getElementById('duration_hh').value || 0);
    const dm = parseInt(document.getElementById('duration_mm').value || 0);

    document.getElementById('sample_collection_time').value = (sh * 60) + sm;
    document.getElementById('service_duration').value = (dh * 60) + dm;
}


</script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this service?</p>
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
    window.location.href = "service_master.php?delete=" + deleteId;
};
document.querySelector("#serviceModal form").addEventListener("submit", function () {
    calculateTimes();
});
</script>
</body>
</html>
