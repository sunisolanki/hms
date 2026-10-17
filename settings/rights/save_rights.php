<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* PERMISSION CHECK */
if (!checkPermission('rights','update')) {
    die('Access Denied');
}
$rights_type_id = $_POST['rights_type_id'] ?? 0;
/* GET RIGHTS TYPE NAME */
$stmt = $conn->prepare("
    SELECT name
    FROM rights_types
    WHERE id = ?
");
$stmt->bind_param("i", $rights_type_id);
$stmt->execute();
$result = $stmt->get_result();
$rights = $result->fetch_assoc();
$result->free();
$stmt->close();

/* GET OLD PERMISSIONS */
$oldPermissions = [];

$sql = "
    SELECT
    mp.module_id,
    m.name,
    mp.can_view,
    mp.can_create,
    mp.can_update,
    mp.can_delete
    FROM module_permissions mp
    INNER JOIN modules m ON m.id = mp.module_id
    WHERE mp.rights_type_id = ?
";

$old = $conn->prepare($sql);
$old->bind_param("i", $rights_type_id);
$old->execute();
$res = $old->get_result();

while ($row = $res->fetch_assoc()) {
    $oldPermissions[$row['module_id']] = [
    'name'       => $row['name'],
    'can_view'   => $row['can_view'],
    'can_create' => $row['can_create'],
    'can_update' => $row['can_update'],
    'can_delete' => $row['can_delete']
];
}

$res->free();
$old->close();

$permissions    = $_POST['perm'] ?? [];

if (!$rights_type_id) {
    die('Role not selected');
}
$enabledPermissions = [];
$disabledPermissions = [];
/* 🔴 DELETE OLD PERMISSIONS (FIXED) */
$del = $conn->prepare("DELETE FROM module_permissions WHERE rights_type_id = ?");
$del->bind_param("i", $rights_type_id);
$del->execute();
$del->close();

/* INSERT NEW PERMISSIONS */
$ins = $conn->prepare("
    INSERT INTO module_permissions
    (rights_type_id, module_id, can_view, can_create, can_update, can_delete)
    VALUES (?,?,?,?,?,?)
");
foreach ($permissions as $module_id => $perm) {
    $view   = isset($perm['view'])   ? 1 : 0;
    $create = isset($perm['create']) ? 1 : 0;
    $update = isset($perm['update']) ? 1 : 0;

    /* CHECK MODULE PERMISSION TYPE */
    $stmtModule = $conn->prepare("
        SELECT permission_type
        FROM modules
        WHERE id = ?
        LIMIT 1
    ");
    $stmtModule->bind_param("i", $module_id);
    $stmtModule->execute();
    $moduleResult = $stmtModule->get_result();
    $moduleData = $moduleResult->fetch_assoc();
    $stmtModule->close();

    /* DELETE NOT ALLOWED */
    if (($moduleData['permission_type'] ?? '') === 'view_only' || ($moduleData['permission_type'] ?? '') === 'no_delete') {
        $delete = 0;
    } else {
        $delete = isset($perm['delete']) ? 1 : 0;
    }

    $ins->bind_param(
        "iiiiii",
        $rights_type_id,
        $module_id,
        $view,
        $create,
        $update,
        $delete
    );
    $ins->execute();
}
$ins->close();
foreach ($oldPermissions as $moduleId => $old) {

    $new = $permissions[$moduleId] ?? [];

    $checks = [
        'view'   => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete'
    ];

    foreach ($checks as $key => $dbField) {

        $oldValue = (int)$old[$dbField];
        $newValue = isset($new[$key]) ? 1 : 0;

        if ($oldValue == 0 && $newValue == 1) {
            $enabledPermissions[] = $old['name'].' - '.ucfirst($key);
        }

        if ($oldValue == 1 && $newValue == 0) {
            $disabledPermissions[] = $old['name'].' - '.ucfirst($key);
        }
    }
}

$oldValues = [];
$newValues = [];

if (!empty($disabledPermissions)) {
    $oldValues['Disabled Permissions'] = implode("\n", $disabledPermissions);
}

if (!empty($enabledPermissions)) {
    $newValues['Enabled Permissions'] = implode("\n", $enabledPermissions);
}

if (!empty($enabledPermissions) || !empty($disabledPermissions)) {
    addAuditLog(
        'Rights Type',
        'Update Permissions',
        'Permissions Updated : '.($rights['name'] ?? ''),
        $rights_type_id,
        json_encode($oldValues),
        json_encode($newValues),
        'SUCCESS'
    );
}
/* REDIRECT */
header("Location: rights.php");
exit;