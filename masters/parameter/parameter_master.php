<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/* VIEW PERMISSION */
if (!checkPermission('parameter_master','view')) {
    header('Location: /access_denied.php');
    exit;
}
/* DELETE */
if (isset($_GET['delete']) && checkPermission('parameter_master','delete')) {
    $id=(int)$_GET['delete'];
    if ($id>0) {
        $oldStmt=mysqli_prepare($conn,"SELECT * FROM parameter_master WHERE id=? LIMIT 1");
        mysqli_stmt_bind_param($oldStmt,'i',$id);
        mysqli_stmt_execute($oldStmt);
        $oldResult=mysqli_stmt_get_result($oldStmt);
        $oldData=mysqli_fetch_assoc($oldResult);
        mysqli_stmt_close($oldStmt);
        if ($oldData) {
            $deleteStmt=mysqli_prepare($conn,"DELETE FROM parameter_master WHERE id=?");
            mysqli_stmt_bind_param($deleteStmt,'i',$id);
            mysqli_stmt_execute($deleteStmt);
            mysqli_stmt_close($deleteStmt);
            addAuditLog(
                'Parameter Master',
                'DELETE',
                'Parameter Deleted : '.$oldData['parameter_name'],
                $id,
                '',
                ''
            );
            $_SESSION['parameter_success']='Parameter deleted successfully.';
        }
    }
    header('Location: parameter_master.php');
    exit;
}
/* SAVE ADD / EDIT */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id=isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (
        ($id>0 && !checkPermission('parameter_master','edit')) ||
        ($id===0 && !checkPermission('parameter_master','add'))
    ) {
        header('Location: /access_denied.php');
        exit;
    }
    $parameterName=trim($_POST['parameter_name'] ?? '');
    $unit=trim($_POST['unit'] ?? '');
    $status=isset($_POST['status']) ? (int)$_POST['status'] : 1;
    $userId=(int)$_SESSION['user_id'];
    if ($parameterName==='') {
        $_SESSION['parameter_error']='Parameter Name is required.';
        header('Location: parameter_master.php');
        exit;
    }
    if (!in_array($status,[0,1],true)) {
        $status=1;
    }
    /* DUPLICATE PARAMETER CHECK */
    $duplicateStmt=mysqli_prepare($conn,"
        SELECT id
        FROM parameter_master
        WHERE parameter_name=?
        AND id<>?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($duplicateStmt,'si',$parameterName,$id);
    mysqli_stmt_execute($duplicateStmt);
    $duplicateResult=mysqli_stmt_get_result($duplicateStmt);
    if (mysqli_num_rows($duplicateResult)>0) {
        mysqli_stmt_close($duplicateStmt);
        $_SESSION['parameter_error']='This Parameter Name already exists.';
        header('Location: parameter_master.php');
        exit;
    }
    mysqli_stmt_close($duplicateStmt);
    /* GET RANGE ARRAYS */
    $ageGroups=$_POST['age_group'] ?? [];
    $ageFroms=$_POST['age_from'] ?? [];
    $ageTos=$_POST['age_to'] ?? [];
    $ageUnits=$_POST['age_unit'] ?? [];
    $maleMins=$_POST['male_min'] ?? [];
    $maleMaxs=$_POST['male_max'] ?? [];
    $femaleMins=$_POST['female_min'] ?? [];
    $femaleMaxs=$_POST['female_max'] ?? [];
    $ranges=[];
    $rangeCount=max(
        count($ageGroups),
        count($ageFroms),
        count($ageTos),
        count($ageUnits),
        count($maleMins),
        count($maleMaxs),
        count($femaleMins),
        count($femaleMaxs)
    );
    for ($i=0;$i<$rangeCount;$i++) {
        $ageGroup=trim($ageGroups[$i] ?? '');
        $ageFrom=trim($ageFroms[$i] ?? '');
        $ageTo=trim($ageTos[$i] ?? '');
        $ageUnit=trim($ageUnits[$i] ?? 'Years');
        $maleMin=trim($maleMins[$i] ?? '');
        $maleMax=trim($maleMaxs[$i] ?? '');
        $femaleMin=trim($femaleMins[$i] ?? '');
        $femaleMax=trim($femaleMaxs[$i] ?? '');
        /* IGNORE COMPLETELY EMPTY ROW */
        if (
            $ageGroup==='' &&
            $ageFrom==='' &&
            $ageTo==='' &&
            $maleMin==='' &&
            $maleMax==='' &&
            $femaleMin==='' &&
            $femaleMax===''
        ) {
            continue;
        }
        if (!in_array($ageUnit,['Days','Months','Years'],true)) {
            $ageUnit='Years';
        }
        if ($ageFrom!=='' && (!is_numeric($ageFrom) || (float)$ageFrom<0)) {
            $_SESSION['parameter_error']='Age From must be a valid non-negative number.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($ageTo!=='' && (!is_numeric($ageTo) || (float)$ageTo<0)) {
            $_SESSION['parameter_error']='Age To must be a valid non-negative number.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($ageFrom!=='' && $ageTo!=='' && (float)$ageFrom>(float)$ageTo) {
            $_SESSION['parameter_error']='Age From cannot be greater than Age To.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($maleMin!=='' && !is_numeric($maleMin)) {
            $_SESSION['parameter_error']='Male Min Value must be numeric.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($maleMax!=='' && !is_numeric($maleMax)) {
            $_SESSION['parameter_error']='Male Max Value must be numeric.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($femaleMin!=='' && !is_numeric($femaleMin)) {
            $_SESSION['parameter_error']='Female Min Value must be numeric.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($femaleMax!=='' && !is_numeric($femaleMax)) {
            $_SESSION['parameter_error']='Female Max Value must be numeric.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($maleMin!=='' && $maleMax!=='' && (float)$maleMin>(float)$maleMax) {
            $_SESSION['parameter_error']='Male Min Value cannot be greater than Male Max Value.';
            header('Location: parameter_master.php');
            exit;
        }
        if ($femaleMin!=='' && $femaleMax!=='' && (float)$femaleMin>(float)$femaleMax) {
            $_SESSION['parameter_error']='Female Min Value cannot be greater than Female Max Value.';
            header('Location: parameter_master.php');
            exit;
        }
        $ranges[]=[
            'age_group'=>$ageGroup,
            'age_from'=>$ageFrom,
            'age_to'=>$ageTo,
            'age_unit'=>$ageUnit,
            'male_min'=>$maleMin,
            'male_max'=>$maleMax,
            'female_min'=>$femaleMin,
            'female_max'=>$femaleMax
        ];
    }
    mysqli_begin_transaction($conn);
    try {
        if ($id>0) {
            /* OLD PARAMETER */
            $oldStmt=mysqli_prepare($conn,"SELECT * FROM parameter_master WHERE id=? LIMIT 1");
            mysqli_stmt_bind_param($oldStmt,'i',$id);
            mysqli_stmt_execute($oldStmt);
            $oldResult=mysqli_stmt_get_result($oldStmt);
            $oldData=mysqli_fetch_assoc($oldResult);
            mysqli_stmt_close($oldStmt);
            if (!$oldData) {
                throw new Exception('Parameter record not found.');
            }
            /* OLD RANGES FOR AUDIT */
            $oldRanges=[];
            $oldRangeStmt=mysqli_prepare($conn,"
                SELECT
                    age_group,
                    age_from,
                    age_to,
                    age_unit,
                    male_min,
                    male_max,
                    female_min,
                    female_max
                FROM parameter_reference_ranges
                WHERE parameter_id=?
                ORDER BY id ASC
            ");
            mysqli_stmt_bind_param($oldRangeStmt,'i',$id);
            mysqli_stmt_execute($oldRangeStmt);
            $oldRangeResult=mysqli_stmt_get_result($oldRangeStmt);
            while ($oldRange=mysqli_fetch_assoc($oldRangeResult)) {
                $oldRanges[]=$oldRange;
            }
            mysqli_stmt_close($oldRangeStmt);
            /* UPDATE PARAMETER */
            $updateStmt=mysqli_prepare($conn,"
                UPDATE parameter_master SET
                    parameter_name=?,
                    unit=?,
                    status=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            mysqli_stmt_bind_param(
                $updateStmt,
                'ssii',
                $parameterName,
                $unit,
                $status,
                $id
            );
            if (!mysqli_stmt_execute($updateStmt)) {
                throw new Exception(mysqli_stmt_error($updateStmt));
            }
            mysqli_stmt_close($updateStmt);
            /* REPLACE RANGES */
            $deleteRangeStmt=mysqli_prepare($conn,"
                DELETE FROM parameter_reference_ranges
                WHERE parameter_id=?
            ");
            mysqli_stmt_bind_param($deleteRangeStmt,'i',$id);
            if (!mysqli_stmt_execute($deleteRangeStmt)) {
                throw new Exception(mysqli_stmt_error($deleteRangeStmt));
            }
            mysqli_stmt_close($deleteRangeStmt);
            foreach ($ranges as $range) {
                $ageGroup=$range['age_group'];
                $ageFrom=$range['age_from']!=='' ? $range['age_from'] : null;
                $ageTo=$range['age_to']!=='' ? $range['age_to'] : null;
                $ageUnit=$range['age_unit'];
                $maleMin=$range['male_min']!=='' ? $range['male_min'] : null;
                $maleMax=$range['male_max']!=='' ? $range['male_max'] : null;
                $femaleMin=$range['female_min']!=='' ? $range['female_min'] : null;
                $femaleMax=$range['female_max']!=='' ? $range['female_max'] : null;
                $rangeStmt=mysqli_prepare($conn,"
                    INSERT INTO parameter_reference_ranges
                    (
                        parameter_id,
                        age_group,
                        age_from,
                        age_to,
                        age_unit,
                        male_min,
                        male_max,
                        female_min,
                        female_max
                    )
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");
                mysqli_stmt_bind_param(
                    $rangeStmt,
                    'isddsssss',
                    $id,
                    $ageGroup,
                    $ageFrom,
                    $ageTo,
                    $ageUnit,
                    $maleMin,
                    $maleMax,
                    $femaleMin,
                    $femaleMax
                );
                if (!mysqli_stmt_execute($rangeStmt)) {
                    throw new Exception(mysqli_stmt_error($rangeStmt));
                }
                mysqli_stmt_close($rangeStmt);
            }
            $oldValues=[];
            $newValues=[];
            if ($oldData['parameter_name']!=$parameterName) {
                $oldValues['parameter_name']=$oldData['parameter_name'];
                $newValues['parameter_name']=$parameterName;
            }
            if (($oldData['unit'] ?? '')!=$unit) {
                $oldValues['unit']=$oldData['unit'];
                $newValues['unit']=$unit;
            }
            if ((int)$oldData['status']!=$status) {
                $oldValues['status']=(int)$oldData['status']===1 ? 'Active' : 'Inactive';
                $newValues['status']=$status===1 ? 'Active' : 'Inactive';
            }
            if (json_encode($oldRanges)!=json_encode($ranges)) {
                $oldValues['reference_ranges']=$oldRanges;
                $newValues['reference_ranges']=$ranges;
            }
            if (!empty($oldValues)) {
                addAuditLog(
                    'Parameter Master',
                    'UPDATE',
                    'Parameter Updated : '.$parameterName,
                    $id,
                    json_encode($oldValues),
                    json_encode($newValues)
                );
            }
            $_SESSION['parameter_success']='Parameter updated successfully.';
        } else {
            /* INSERT PARAMETER */
            $insertStmt=mysqli_prepare($conn,"
                INSERT INTO parameter_master
                (
                    parameter_name,
                    unit,
                    status,
                    created_by
                )
                VALUES (?,?,?,?)
            ");
            mysqli_stmt_bind_param(
                $insertStmt,
                'ssii',
                $parameterName,
                $unit,
                $status,
                $userId
            );
            if (!mysqli_stmt_execute($insertStmt)) {
                throw new Exception(mysqli_stmt_error($insertStmt));
            }
            $parameterId=mysqli_insert_id($conn);
            mysqli_stmt_close($insertStmt);
            foreach ($ranges as $range) {
                $ageGroup=$range['age_group'];
                $ageFrom=$range['age_from']!=='' ? $range['age_from'] : null;
                $ageTo=$range['age_to']!=='' ? $range['age_to'] : null;
                $ageUnit=$range['age_unit'];
                $maleMin=$range['male_min']!=='' ? $range['male_min'] : null;
                $maleMax=$range['male_max']!=='' ? $range['male_max'] : null;
                $femaleMin=$range['female_min']!=='' ? $range['female_min'] : null;
                $femaleMax=$range['female_max']!=='' ? $range['female_max'] : null;
                $rangeStmt=mysqli_prepare($conn,"
                    INSERT INTO parameter_reference_ranges
                    (
                        parameter_id,
                        age_group,
                        age_from,
                        age_to,
                        age_unit,
                        male_min,
                        male_max,
                        female_min,
                        female_max
                    )
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");
                mysqli_stmt_bind_param(
                    $rangeStmt,
                    'isddsssss',
                    $parameterId,
                    $ageGroup,
                    $ageFrom,
                    $ageTo,
                    $ageUnit,
                    $maleMin,
                    $maleMax,
                    $femaleMin,
                    $femaleMax
                );
                if (!mysqli_stmt_execute($rangeStmt)) {
                    throw new Exception(mysqli_stmt_error($rangeStmt));
                }
                mysqli_stmt_close($rangeStmt);
            }
            addAuditLog(
                'Parameter Master',
                'CREATE',
                'Parameter Created : '.$parameterName,
                $parameterId,
                '',
                ''
            );
            $_SESSION['parameter_success']='Parameter created successfully.';
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['parameter_error']='Unable to save parameter: '.$e->getMessage();
    }
    header('Location: parameter_master.php');
    exit;
}
/* FETCH PARAMETERS */
$res=mysqli_query($conn,"
SELECT
    p.*,
    u.full_name AS created_by_name,
    COUNT(r.id) AS range_count
FROM parameter_master p
LEFT JOIN users u
    ON u.id=p.created_by
LEFT JOIN parameter_reference_ranges r
    ON r.parameter_id=p.id
GROUP BY
    p.id,
    p.parameter_name,
    p.unit,
    p.status,
    p.created_by,
    p.created_at,
    p.updated_at,
    u.full_name
ORDER BY p.id DESC
");
/* FETCH ALL RANGES FOR EDIT */
$parameterRanges=[];
$rangeQry=mysqli_query($conn,"
SELECT *
FROM parameter_reference_ranges
ORDER BY parameter_id,id
");
while ($rangeRow=mysqli_fetch_assoc($rangeQry)) {
    $parameterId=(int)$rangeRow['parameter_id'];
    if (!isset($parameterRanges[$parameterId])) {
        $parameterRanges[$parameterId]=[];
    }
    $parameterRanges[$parameterId][]=$rangeRow;
}
$successMessage=$_SESSION['parameter_success'] ?? '';
$errorMessage=$_SESSION['parameter_error'] ?? '';
unset($_SESSION['parameter_success'],$_SESSION['parameter_error']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Parameter Master</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#f1f1f1}
.btn{background:#2c7be5;color:#fff;padding:8px 14px;border:none;border-radius:5px;cursor:pointer;width:auto;max-width:fit-content;text-decoration:none;display:inline-block}
.btn:hover{background:#1a5fd0;color:#fff}
.btn-success{background:#28a745;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.btn-secondary{background:#6c757d;color:#fff}
.badge-success{background:#28a745;color:#fff;padding:3px 6px;border-radius:3px}
.badge-secondary{background:#6c757d;color:#fff;padding:3px 6px;border-radius:3px}
.range-count{background:#e7f1ff;color:#0b5ed7;padding:3px 7px;border-radius:4px;font-size:12px;font-weight:600}
.action-buttons{display:flex;gap:6px;justify-content:center}
.btn-icon{border:none;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:.2s}
.btn-edit{background:#e8f5e9;color:#2e7d32}
.btn-edit:hover{background:#2e7d32;color:#fff}
.btn-delete{background:#fdecea;color:#c62828}
.btn-delete:hover{background:#c62828;color:#fff}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;overflow-y:auto}
.modal-box{background:#fff;width:1050px;max-width:96%;margin:35px auto;padding:20px;border-radius:7px;max-height:88vh;overflow-y:auto}
.modal h3{margin-top:0}
label{font-weight:600;font-size:13px;margin-top:8px;display:block}
input,select{width:100%;padding:7px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box}
.parameter-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:15px}
.range-section{margin-top:20px;border-top:1px solid #ddd;padding-top:15px}
.range-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.range-header h4{margin:0}
.range-row{border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:12px;background:#fafafa}
.range-top{display:grid;grid-template-columns:1.5fr .8fr .8fr 1fr 40px;gap:10px;align-items:end}
.range-values{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-top:10px}
.gender-title{font-size:12px;font-weight:700;color:#555;margin-top:10px;margin-bottom:3px}
.range-value-group{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.gender-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.remove-range{border:none;background:#fdecea;color:#c62828;width:34px;height:34px;border-radius:5px;cursor:pointer}
.remove-range:hover{background:#c62828;color:#fff}
.actions{text-align:right;margin-top:15px}
.alert{padding:10px 14px;margin-bottom:15px;border-radius:5px;font-size:13px}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-danger{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
@media(max-width:800px){
    .parameter-grid{grid-template-columns:1fr}
    .range-top{grid-template-columns:1fr 1fr}
    .gender-grid{grid-template-columns:1fr}
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
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Parameter Master</a>
    </div>
    <h2 style="margin:0 auto;">Parameter Master</h2>
    <?php if (checkPermission('parameter_master','add')) { ?>
    <button class="btn" onclick="openModal()">+ Add Parameter</button>
    <?php } ?>
</div>
<div class="container">
<?php if ($successMessage!=='') { ?>
<div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
<?php } ?>
<?php if ($errorMessage!=='') { ?>
<div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
<?php } ?>
<table>
<thead>
<tr>
    <th>#</th>
    <th>Parameter Name</th>
    <th>Unit</th>
    <th>Age Ranges</th>
    <th>Status</th>
    <th>Created By</th>
    <th>Created</th>
    <th>Modified</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php
$i=1;
if ($res && mysqli_num_rows($res)>0) {
    while ($row=mysqli_fetch_assoc($res)) {
        $rowRanges=$parameterRanges[(int)$row['id']] ?? [];
?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($row['parameter_name']) ?></td>
    <td><?= htmlspecialchars($row['unit'] ?? '') ?></td>
    <td>
        <span class="range-count">
            <?= (int)$row['range_count'] ?> Range<?= (int)$row['range_count']===1 ? '' : 's' ?>
        </span>
    </td>
    <td>
        <?= (int)$row['status']===1
            ? '<span class="badge-success">Active</span>'
            : '<span class="badge-secondary">Inactive</span>' ?>
    </td>
    <td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A') ?></td>
    <td>
        <?= !empty($row['created_at'])
            ? date('d-M-Y h:i A',strtotime($row['created_at']))
            : '-' ?>
    </td>
    <td>
        <?= !empty($row['updated_at'])
            ? date('d-M-Y h:i A',strtotime($row['updated_at']))
            : '-' ?>
    </td>
    <td class="action-buttons">
        <?php if (checkPermission('parameter_master','edit')) { ?>
        <button class="btn-icon btn-edit"
        onclick='editParameter(
            <?= htmlspecialchars(json_encode($row),ENT_QUOTES,"UTF-8") ?>,
            <?= htmlspecialchars(json_encode($rowRanges),ENT_QUOTES,"UTF-8") ?>
        )'>
            <i class="fa-solid fa-pen-to-square"></i>
        </button>
        <?php } ?>
        <?php if (checkPermission('parameter_master','delete')) { ?>
        <button class="btn-icon btn-delete" onclick="confirmDelete(<?= (int)$row['id'] ?>)">
            <i class="fa-solid fa-trash"></i>
        </button>
        <?php } ?>
    </td>
</tr>
<?php
    }
} else {
?>
<tr>
    <td colspan="9" align="center">No records found</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<!-- ADD / EDIT MODAL -->
<div class="modal" id="parameterModal">
<div class="modal-box">
<h3 id="modalTitle">Add Parameter</h3>
<form method="post" id="parameterForm">
<input type="hidden" name="id" id="id">
<div class="parameter-grid">
<div>
<label>Parameter Name *</label>
<input type="text" name="parameter_name" id="parameter_name" required maxlength="200">
</div>
<div>
<label>Unit</label>
<input type="text" name="unit" id="unit" maxlength="50" placeholder="e.g. g/dL">
</div>
<div>
<label>Status</label>
<select name="status" id="status">
    <option value="1">Active</option>
    <option value="0">Inactive</option>
</select>
</div>
</div>
<div class="range-section">
<div class="range-header">
    <h4>Reference Ranges</h4>
    <button type="button" class="btn" onclick="addRange()">+ Add Range</button>
</div>
<div id="rangeContainer"></div>
</div>
<div class="actions">
    <button type="submit" class="btn btn-success">Save</button>
    <button type="button" class="btn btn-danger" onclick="closeModal()">Cancel</button>
</div>
</form>
</div>
</div>
<!-- DELETE CONFIRM -->
<div id="deleteConfirm" class="confirm-modal">
<div class="confirm-box">
    <p>Are you sure you want to delete this parameter?</p>
    <small style="color:red;">All reference ranges of this parameter will also be deleted.</small>
    <div class="confirm-actions">
        <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
        <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
</div>
</div>
<script>
function openModal(){
    document.getElementById('parameterModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Parameter';
    document.getElementById('parameterForm').reset();
    document.getElementById('id').value='';
    document.getElementById('rangeContainer').innerHTML='';
    addRange();
}
function closeModal(){
    document.getElementById('parameterModal').style.display='none';
}
function addRange(data=null){
    const container=document.getElementById('rangeContainer');
    const row=document.createElement('div');
    row.className='range-row';
    const ageGroup=data && data.age_group ? data.age_group : '';
    const ageFrom=data && data.age_from!==null ? cleanNumber(data.age_from) : '';
    const ageTo=data && data.age_to!==null ? cleanNumber(data.age_to) : '';
    const ageUnit=data && data.age_unit ? data.age_unit : 'Years';
    const maleMin=data && data.male_min!==null ? cleanNumber(data.male_min) : '';
    const maleMax=data && data.male_max!==null ? cleanNumber(data.male_max) : '';
    const femaleMin=data && data.female_min!==null ? cleanNumber(data.female_min) : '';
    const femaleMax=data && data.female_max!==null ? cleanNumber(data.female_max) : '';
    row.innerHTML=`
        <div class="range-top">
            <div>
                <label>Age Group</label>
<select name="age_group[]">
    <option value="">Select Age Group</option>
    <option value="Newborn" ${ageGroup==='Newborn'?'selected':''}>Newborn</option>
    <option value="Infant" ${ageGroup==='Infant'?'selected':''}>Infant</option>
    <option value="Child" ${ageGroup==='Child'?'selected':''}>Child</option>
    <option value="Adolescent" ${ageGroup==='Adolescent'?'selected':''}>Adolescent</option>
    <option value="Adult" ${ageGroup==='Adult'?'selected':''}>Adult</option>
    <option value="Senior" ${ageGroup==='Senior'?'selected':''}>Senior</option>
</select>
            </div>
            <div>
                <label>Age From</label>
                <input type="number" name="age_from[]" min="0" step="0.01"
                value="${escapeHtml(ageFrom)}">
            </div>
            <div>
                <label>Age To</label>
                <input type="number" name="age_to[]" min="0" step="0.01"
                value="${escapeHtml(ageTo)}">
            </div>
            <div>
                <label>Age Unit</label>
                <select name="age_unit[]">
                    <option value="Days" ${ageUnit==='Days'?'selected':''}>Days</option>
                    <option value="Months" ${ageUnit==='Months'?'selected':''}>Months</option>
                    <option value="Years" ${ageUnit==='Years'?'selected':''}>Years</option>
                </select>
            </div>
            <div>
                <button type="button" class="remove-range" onclick="removeRange(this)" title="Remove Range">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>
        <div class="gender-grid">
            <div>
                <div class="gender-title">Male Range</div>
                <div class="range-value-group">
                    <div>
                        <label>Min Value</label>
                        <input type="number" name="male_min[]" step="0.0001"
                        value="${escapeHtml(maleMin)}">
                    </div>
                    <div>
                        <label>Max Value</label>
                        <input type="number" name="male_max[]" step="0.0001"
                        value="${escapeHtml(maleMax)}">
                    </div>
                </div>
            </div>
            <div>
                <div class="gender-title">Female Range</div>
                <div class="range-value-group">
                    <div>
                        <label>Min Value</label>
                        <input type="number" name="female_min[]" step="0.0001"
                        value="${escapeHtml(femaleMin)}">
                    </div>
                    <div>
                        <label>Max Value</label>
                        <input type="number" name="female_max[]" step="0.0001"
                        value="${escapeHtml(femaleMax)}">
                    </div>
                </div>
            </div>
        </div>
    `;
    container.appendChild(row);
}
function removeRange(button){
    const container=document.getElementById('rangeContainer');
    const rows=container.querySelectorAll('.range-row');
    if(rows.length<=1){
        const row=button.closest('.range-row');
        row.querySelectorAll('input').forEach(function(input){
            input.value='';
        });
        row.querySelector('select[name="age_unit[]"]').value='Years';
        return;
    }
    button.closest('.range-row').remove();
}
function editParameter(data,ranges){
    document.getElementById('parameterModal').style.display='block';
    document.getElementById('modalTitle').innerText='Edit Parameter';
    document.getElementById('parameterForm').reset();
    document.getElementById('id').value=data.id;
    document.getElementById('parameter_name').value=data.parameter_name || '';
    document.getElementById('unit').value=data.unit || '';
    document.getElementById('status').value=data.status;
    document.getElementById('rangeContainer').innerHTML='';
    if(Array.isArray(ranges) && ranges.length>0){
        ranges.forEach(function(range){
            addRange(range);
        });
    }else{
        addRange();
    }
}
function cleanNumber(value){
    if(value===null || value==='') return '';
    const number=parseFloat(value);
    if(Number.isNaN(number)) return value;
    return String(number);
}
function escapeHtml(value){
    return String(value ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}
let deleteId=null;
function confirmDelete(id){
    deleteId=id;
    document.getElementById('deleteConfirm').style.display='flex';
}
function closeDelete(){
    document.getElementById('deleteConfirm').style.display='none';
    deleteId=null;
}
document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('confirmDeleteBtn').onclick=function(){
        if(!deleteId) return;
        window.location.href='parameter_master.php?delete='+deleteId;
    };
});
</script>
</body>
</html>