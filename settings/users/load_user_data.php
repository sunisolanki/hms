<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$type = $_GET['type'] ?? '';
$ids  = $_GET['ids'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if ($type === 'branch' && $ids) {
    $ids = array_map('intval', explode(',', $ids));
    $ids = implode(',', $ids);
    $res = mysqli_query($conn,"
        SELECT id, branch_name
        FROM branch_master
        WHERE hospital_id IN ($ids)
        AND status = 1
        ORDER BY branch_name
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        echo '<label>
                <input type="checkbox"
                       name="branch_ids[]"
                       value="'.$r['id'].'"
                       onchange="updateBranches()">
                '.htmlspecialchars($r['branch_name']).'
              </label>';
    }
    exit;
}
if ($type === 'department' && $ids) {
    $ids = array_map('intval', explode(',', $ids));
    $ids = implode(',', $ids);
    $res = mysqli_query($conn,"
        SELECT id, department_name
        FROM department_master
        WHERE branch_id IN ($ids)
        AND status = 1
        ORDER BY department_name
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        echo '<label>
                <input type="checkbox"
                       name="department_ids[]"
                       value="'.$r['id'].'"
                       onchange="updateDepartments()">
                '.htmlspecialchars($r['department_name']).'
              </label>';
    }
    exit;
}
if ($type === 'subtype' && $id > 0) {
    $res = mysqli_query($conn,"
        SELECT id, type_name
        FROM employee_type_master
        WHERE parent_id = $id
        AND status = 1
        ORDER BY type_name
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        echo '<label>
                <input type="checkbox"
                       name="employee_sub_type_ids[]"
                       value="'.$r['id'].'"
                       onchange="updateSubTypes()">
                '.htmlspecialchars($r['type_name']).'
              </label>';
    }
    exit;
}