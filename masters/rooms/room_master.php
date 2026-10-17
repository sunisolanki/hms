<?php
// ERROR REPORTING
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) { header('Location:/index.php'); exit; }
if (!checkPermission('room_master','view')) { header('Location:/access_denied.php'); exit; }
/* ---------- DELETE BACKEND ---------- */
if (
    isset($_GET['delete_id']) &&
    isset($_GET['type']) &&
    checkPermission('room_master','delete')
) {
    
    $id = (int)$_GET['delete_id'];
    $type = $_GET['type'];
    if ($type === 'building') {
        $stmt = $conn->prepare("SELECT * FROM building_master WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$building = $stmt->get_result()->fetch_assoc();
$stmt->close();
$q = mysqli_query($conn,"SELECT hospital_name FROM hospital_master WHERE id='".$building['hospital_id']."'");
$hospital = mysqli_fetch_assoc($q);

$q = mysqli_query($conn,"SELECT branch_name FROM branch_master WHERE id='".$building['branch_id']."'");
$branch = mysqli_fetch_assoc($q);

$oldValues = [
    'Hospital'      => $hospital['hospital_name'] ?? '',
    'Branch'        => $branch['branch_name'] ?? '',
    'Building Name' => $building['building_name']
];
    // delete beds
    $conn->query("
        DELETE b FROM bed_master b
        INNER JOIN room_master r ON r.id = b.room_id
        INNER JOIN floor_master f ON f.id = r.floor_id
        WHERE f.building_id = $id
    ");
    // delete rooms
    $conn->query("
        DELETE r FROM room_master r
        INNER JOIN floor_master f ON f.id = r.floor_id
        WHERE f.building_id = $id
    ");
    // delete floors
    $conn->query("DELETE FROM floor_master WHERE building_id = $id");
    // delete building
    addAuditLog(
    'Room Master - Building',
    'Delete',
    'Building Deleted : '.$building['building_name'],
    $id,
    json_encode($oldValues),
    '',
    'SUCCESS'
);
    $conn->query("DELETE FROM building_master WHERE id = $id");
}
    if ($type === 'floor') {
    $stmt = $conn->prepare("SELECT * FROM floor_master WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $floor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $q = mysqli_query($conn,"SELECT building_name FROM building_master WHERE id='".$floor['building_id']."'");
$building = mysqli_fetch_assoc($q);
$oldValues = [
    'Building' => $building['building_name'] ?? '',
    'Floor Name' => $floor['floor_name']
];
    // delete beds of rooms under this floor
    $conn->query("
        DELETE b FROM bed_master b
        INNER JOIN room_master r ON r.id = b.room_id
        WHERE r.floor_id = $id
    ");
    // delete rooms
    $conn->query("DELETE FROM room_master WHERE floor_id = $id");
    addAuditLog(
        'Room Master - Floor',
        'Delete',
        'Floor Deleted : '.$floor['floor_name'],
        $id,
        json_encode($oldValues),
        '',
        'SUCCESS'
    );
    // delete floor
    $conn->query("DELETE FROM floor_master WHERE id = $id");
}
    if ($type === 'room') {
    $stmt = $conn->prepare("
        SELECT *
        FROM room_master
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $q = mysqli_query($conn,"SELECT floor_name FROM floor_master WHERE id='".$room['floor_id']."'");
$floor = mysqli_fetch_assoc($q);
$oldValues = [
    'Floor' => $floor['floor_name'] ?? '',
    'Room Number'  => $room['room_number'],
    'Room Name'    => $room['room_name'],
    'Room Type'    => $room['room_type'],
    'Bed Capacity' => $room['bed_capacity'],
    'Room Rent'    => $room['room_rent'],
    'Gender'       => $room['gender_type'],
    'Status'       => $room['status'],
    'Remarks'      => $room['remarks']
];
    addAuditLog(
        'Room Master - Room',
        'Delete',
        'Room Deleted : '.$room['room_name'],
        $id,
        json_encode($oldValues),
        '',
        'SUCCESS'
    );
    $conn->query("DELETE FROM bed_master WHERE room_id=$id");
    $conn->query("DELETE FROM room_master WHERE id=$id");
}
    header("Location: room_master.php#roomTab");
    exit;
}
/* ---------- STATUS UPDATE ---------- */
if (isset($_GET['status_id'])) {
    
     if (!checkPermission('room_master','update')) {
        header("Location: /access_denied.php");
        exit;
    }
    $id = (int)$_GET['status_id'];
    $type = $_GET['type'];
    $status = ($_GET['status'] === 'Inactive') ? 'Inactive' : 'Active';
    if ($type === 'building') {
    $stmt = $conn->prepare("
        SELECT building_name, status
        FROM building_master
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $building = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $conn->prepare("
        UPDATE building_master
        SET status=?
        WHERE id=?
    ");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    if ($building['status'] != $status) {
        addAuditLog(
            'Room Master - Building',
            'Status Change',
            'Building Status Changed : '.$building['building_name'],
            $id,
            json_encode([
                'Status' => $building['status']
            ]),
            json_encode([
                'Status' => $status
            ]),
            'SUCCESS'
        );
    }
}
    if ($type === 'floor') {
    $stmt = $conn->prepare("
        SELECT floor_name, status
        FROM floor_master
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $floor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $conn->prepare("
        UPDATE floor_master
        SET status=?
        WHERE id=?
    ");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    if ($floor['status'] != $status) {
        addAuditLog(
            'Room Master - Floor',
            'Status Change',
            'Floor Status Changed : '.$floor['floor_name'],
            $id,
            json_encode([
                'Status' => $floor['status']
            ]),
            json_encode([
                'Status' => $status
            ]),
            'SUCCESS'
        );
    }
}
  if ($type === 'room') {
    $stmt = $conn->prepare("
        SELECT room_name, status
        FROM room_master
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $conn->prepare("
        UPDATE room_master
        SET status=?
        WHERE id=?
    ");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    if ($room['status'] != $status) {
        addAuditLog(
            'Room Master - Room',
            'Status Change',
            'Room Status Changed : '.$room['room_name'],
            $id,
            json_encode([
                'Status' => $room['status']
            ]),
            json_encode([
                'Status' => $status
            ]),
            'SUCCESS'
        );
    }
}
    header("Location: room_master.php#roomTab");
    exit;
}
/* BED STATUS UPDATE */
if (isset($_GET['bed_status_id'])) {

    if (!checkPermission('room_master','update')) {
        header("Location: /access_denied.php");
        exit;
    }

    $id = (int)$_GET['bed_status_id'];

    $stmt = $conn->prepare("
        SELECT
            bed_number,
            status
        FROM bed_master
        WHERE id=?
    ");
    $stmt->bind_param("i",$id);
    $stmt->execute();
    $bed = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $current = $bed['status'];
    $requested = $_GET['status'];

    if ($requested === 'Occupied') {
        header("Location: room_master.php#bedTab");
        exit;
    }

    if ($current === 'Occupied') {
        header("Location: room_master.php#bedTab");
        exit;
    }

    $status = ($requested === 'Maintenance') ? 'Maintenance' : 'Available';

    $stmt = $conn->prepare("
        UPDATE bed_master
        SET status=?
        WHERE id=?
    ");
    $stmt->bind_param("si",$status,$id);
    $stmt->execute();

    if ($current != $status) {
        addAuditLog(
            'Room Master - Bed',
            'Status Change',
            'Bed Status Changed : '.$bed['bed_number'],
            $id,
            json_encode([
                'Status' => $current
            ]),
            json_encode([
                'Status' => $status
            ]),
            'SUCCESS'
        );
    }

    header("Location: room_master.php#bedTab");
    exit;
}
/* ---------- SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $editId = (int)($_POST['edit_id'] ?? 0);

if (
    ($editId > 0 && !checkPermission('room_master','update')) ||
    ($editId == 0 && !checkPermission('room_master','add'))
) {
    header("Location: /access_denied.php");
    exit;
}

    $mode = $_POST['mode'];
    // BUILDING
    if ($mode === 'building') {
    $oldBuilding = [];
    $changedFields = [];
    if (!empty($_POST['edit_id'])) {
        $stmtOld = $conn->prepare("
            SELECT *
            FROM building_master
            WHERE id=?
        ");
        $stmtOld->bind_param("i", $_POST['edit_id']);
        $stmtOld->execute();
        $oldBuilding = $stmtOld->get_result()->fetch_assoc();
        $stmtOld->close();
        $fields = [
            'hospital_id'   => 'Hospital',
            'branch_id'     => 'Branch',
            'building_name' => 'Building Name'
        ];
        foreach ($fields as $dbField => $label) {
            $oldValue = (string)($oldBuilding[$dbField] ?? '');
            $newValue = (string)($_POST[$dbField] ?? '');
            if ($oldValue !== $newValue) {
                $changedFields[$label] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }
    }
    /* ================= UPDATE ================= */
    if (!empty($_POST['edit_id'])) {
        $stmt = $conn->prepare("
            UPDATE building_master
            SET hospital_id=?, branch_id=?, building_name=?
            WHERE id=?
        ");
        $stmt->bind_param(
            "iisi",
            $_POST['hospital_id'],
            $_POST['branch_id'],
            $_POST['building_name'],
            $_POST['edit_id']
        );
        $stmt->execute();
        if (!empty($changedFields)) {
            $oldValues = [];
            $newValues = [];
            foreach ($changedFields as $field => $value) {
                $old = $value['old'];
                $new = $value['new'];
                if ($field == 'Hospital') {
                    $q = mysqli_query($conn,"SELECT hospital_name FROM hospital_master WHERE id='$old'");
                    $r = mysqli_fetch_assoc($q);
                    $old = $r['hospital_name'] ?? '';
                    $q = mysqli_query($conn,"SELECT hospital_name FROM hospital_master WHERE id='$new'");
                    $r = mysqli_fetch_assoc($q);
                    $new = $r['hospital_name'] ?? '';
                }
                if ($field == 'Branch') {
                    $q = mysqli_query($conn,"SELECT branch_name FROM branch_master WHERE id='$old'");
                    $r = mysqli_fetch_assoc($q);
                    $old = $r['branch_name'] ?? '';
                    $q = mysqli_query($conn,"SELECT branch_name FROM branch_master WHERE id='$new'");
                    $r = mysqli_fetch_assoc($q);
                    $new = $r['branch_name'] ?? '';
                }
                $oldValues[$field] = $old;
                $newValues[$field] = $new;
            }
            addAuditLog(
                'Room Master - Building',
                'Update',
                'Building Updated : '.$_POST['building_name'],
                $_POST['edit_id'],
                json_encode($oldValues),
                json_encode($newValues),
                'SUCCESS'
            );
        }
        /* UPDATE ROOM CHARGES */
$room_id=$_POST['edit_id'];
/* Remove old mapping */
$conn->query("
DELETE FROM room_charge_mapping
WHERE room_id=$room_id
");
if(!empty($_POST['room_charges'])){
    foreach($_POST['room_charges'] as $charge_id){
        $amount=$_POST['charge_amount'][$charge_id] ?? 0;
        $stmtCharge=$conn->prepare("
            INSERT INTO room_charge_mapping
            (
                room_id,
                charge_id,
                amount,
                frequency,
                created_by
            )
            SELECT
                ?,
                id,
                ?,
                frequency,
                ?
            FROM charges_master
            WHERE id=?
        ");
        $stmtCharge->bind_param(
            "idii",
            $room_id,
            $amount,
            $_SESSION['user_id'],
            $charge_id
        );
        $stmtCharge->execute();
    }
}
    }
    /* ================= CREATE ================= */
    else {
        $res = $conn->query("SELECT MAX(id) as max_id FROM building_master");
        $row = $res->fetch_assoc();
        $next = ($row['max_id'] ?? 0) + 1;
        $building_code = 'B'.$next;
        $stmt = $conn->prepare("
            INSERT INTO building_master
            (hospital_id, branch_id, building_name, building_code, created_by)
            VALUES (?,?,?,?,?)
        ");
        $stmt->bind_param(
            "iissi",
            $_POST['hospital_id'],
            $_POST['branch_id'],
            $_POST['building_name'],
            $building_code,
            $_SESSION['user_id']
        );
        $stmt->execute();
        $q = mysqli_query($conn,"SELECT hospital_name FROM hospital_master WHERE id='".(int)$_POST['hospital_id']."'");
        $hospital = mysqli_fetch_assoc($q);
        $q = mysqli_query($conn,"SELECT branch_name FROM branch_master WHERE id='".(int)$_POST['branch_id']."'");
        $branch = mysqli_fetch_assoc($q);
        $newValues = [
            'Hospital'      => $hospital['hospital_name'] ?? '',
            'Branch'        => $branch['branch_name'] ?? '',
            'Building Name' => $_POST['building_name']
        ];
        addAuditLog(
            'Room Master - Building',
            'Create',
            'Building Created : '.$_POST['building_name'],
            $conn->insert_id,
            '',
            json_encode($newValues),
            'SUCCESS'
        );
    }
}
  // FLOOR
elseif ($mode === 'floor') {
    $oldFloor = [];
    $changedFields = [];
    if (!empty($_POST['edit_id'])) {
        $stmtOld = $conn->prepare("
            SELECT *
            FROM floor_master
            WHERE id=?
        ");
        $stmtOld->bind_param("i", $_POST['edit_id']);
        $stmtOld->execute();
        $oldFloor = $stmtOld->get_result()->fetch_assoc();
        $stmtOld->close();
        $fields = [
            'building_id' => 'Building',
            'floor_name'  => 'Floor Name'
        ];
        foreach ($fields as $dbField => $label) {
            $oldValue = (string)($oldFloor[$dbField] ?? '');
            $newValue = (string)($_POST[$dbField] ?? '');
            if ($oldValue !== $newValue) {
                $changedFields[$label] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }
    }
    /* ================= UPDATE ================= */
    if (!empty($_POST['edit_id'])) {
        $stmt = $conn->prepare("
            UPDATE floor_master
            SET building_id=?, floor_name=?
            WHERE id=?
        ");
        $stmt->bind_param(
            "isi",
            $_POST['building_id'],
            $_POST['floor_name'],
            $_POST['edit_id']
        );
        $stmt->execute();
        if (!empty($changedFields)) {
            $oldValues = [];
            $newValues = [];
            foreach ($changedFields as $field => $value) {
                $old = $value['old'];
                $new = $value['new'];
                if ($field == 'Building') {
                    $q = mysqli_query($conn,"SELECT building_name FROM building_master WHERE id='$old'");
                    $r = mysqli_fetch_assoc($q);
                    $old = $r['building_name'] ?? '';
                    $q = mysqli_query($conn,"SELECT building_name FROM building_master WHERE id='$new'");
                    $r = mysqli_fetch_assoc($q);
                    $new = $r['building_name'] ?? '';
                }
                $oldValues[$field] = $old;
                $newValues[$field] = $new;
            }
            addAuditLog(
                'Room Master - Floor',
                'Update',
                'Floor Updated : '.$_POST['floor_name'],
                $_POST['edit_id'],
                json_encode($oldValues),
                json_encode($newValues),
                'SUCCESS'
            );
        }
    }
    /* ================= CREATE ================= */
    else {
        $stmt = $conn->prepare("
            INSERT INTO floor_master
            (building_id, floor_name)
            VALUES (?,?)
        ");
        $stmt->bind_param(
            "is",
            $_POST['building_id'],
            $_POST['floor_name']
        );
        $stmt->execute();
        $q = mysqli_query($conn,"SELECT building_name FROM building_master WHERE id='".(int)$_POST['building_id']."'");
        $building = mysqli_fetch_assoc($q);
        $newValues = [
            'Building'  => $building['building_name'] ?? '',
            'Floor Name' => $_POST['floor_name']
        ];
        addAuditLog(
            'Room Master - Floor',
            'Create',
            'Floor Created : '.$_POST['floor_name'],
            $conn->insert_id,
            '',
            json_encode($newValues),
            'SUCCESS'
        );
    }
}
 /* ---------- ROOM SAVE ---------- */
elseif ($mode === 'room') {
    $oldRoom = [];
    $changedFields = [];
    if (!empty($_POST['edit_id'])) {
        $stmtOld = $conn->prepare("
            SELECT *
            FROM room_master
            WHERE id=?
        ");
        $stmtOld->bind_param("i", $_POST['edit_id']);
        $stmtOld->execute();
        $oldRoom = $stmtOld->get_result()->fetch_assoc();
        $stmtOld->close();
        $fields = [
            'floor_id'      => 'Floor',
            'room_number'   => 'Room Number',
            'room_name'     => 'Room Name',
            'room_type'     => 'Room Type',
            'bed_capacity'  => 'Bed Capacity',
            'room_rent'     => 'Room Rent',
            'gender_type'   => 'Gender',
            'status'        => 'Status',
            'remarks'       => 'Remarks'
        ];

        foreach ($fields as $dbField => $label) {
            $oldValue = (string)($oldRoom[$dbField] ?? '');
            $newValue = (string)($_POST[$dbField] ?? '');
            if ($oldValue !== $newValue) {
                $changedFields[$label] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }
    }
    /* ================= UPDATE ================= */
    if (!empty($_POST['edit_id'])) {
        $stmt = $conn->prepare("
            UPDATE room_master
            SET floor_id=?, room_number=?, room_name=?, room_type=?, bed_capacity=?, room_rent=?, gender_type=?, status=?, remarks=?
            WHERE id=?
        ");
        $status_val = !empty($_POST['status']) ? $_POST['status'] : 'Active';
        $stmt->bind_param(
            "isssidsssi",
            $_POST['floor_id'],
            $_POST['room_number'],
            $_POST['room_name'],
            $_POST['room_type'],
            $_POST['bed_capacity'],
            $_POST['room_rent'],
            $_POST['gender_type'],
            $status_val,
            $_POST['remarks'],
            $_POST['edit_id']
        );
        $stmt->execute();
        if (!empty($changedFields) || isset($_POST['room_charges'])) {
            $oldValues = [];
            $newValues = [];
            foreach ($changedFields as $field => $value) {
                $old = $value['old'];
                $new = $value['new'];
                if ($field == 'Floor') {
                    $q = mysqli_query($conn,"SELECT floor_name FROM floor_master WHERE id='$old'");
                    $r = mysqli_fetch_assoc($q);
                    $old = $r['floor_name'] ?? '';
                    $q = mysqli_query($conn,"SELECT floor_name FROM floor_master WHERE id='$new'");
                    $r = mysqli_fetch_assoc($q);
                    $new = $r['floor_name'] ?? '';
                }
                $oldValues[$field] = $old;
                $newValues[$field] = $new;
            }
            addAuditLog(
                'Room Master - Room',
                'Update',
                'Room Updated : '.$_POST['room_name'],
                $_POST['edit_id'],
                json_encode($oldValues),
                json_encode($newValues),
                'SUCCESS'
            );
            $room_id = $_POST['edit_id'];
            /* SAVE ROOM CHARGES */
            $conn->query("
DELETE FROM room_charge_mapping
WHERE room_id=".$_POST['edit_id']."
");
if(!empty($_POST['room_charges'])){

    foreach($_POST['room_charges'] as $charge_id){

        $amount = $_POST['charge_amount'][$charge_id] ?? 0;

        $stmtCharge=$conn->prepare("
            INSERT INTO room_charge_mapping
            (
                room_id,
                charge_id,
                amount,
                frequency,
                created_by
            )
            SELECT
                ?,
                id,
                ?,
                frequency,
                ?
            FROM charges_master
            WHERE id=?
        ");

        $stmtCharge->bind_param(
            "idii",
            $room_id,
            $amount,
            $_SESSION['user_id'],
            $charge_id
        );

        $stmtCharge->execute();
    }

}

        }
    }
    /* ================= CREATE ================= */
    else {
        $stmt = $conn->prepare("
            INSERT INTO room_master
            (floor_id, room_number, room_name, room_type, bed_capacity, room_rent, gender_type, status, remarks, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $status_val = !empty($_POST['status']) ? $_POST['status'] : 'Active';
        $stmt->bind_param(
            "isssidsssi",
            $_POST['floor_id'],
            $_POST['room_number'],
            $_POST['room_name'],
            $_POST['room_type'],
            $_POST['bed_capacity'],
            $_POST['room_rent'],
            $_POST['gender_type'],
            $status_val,
            $_POST['remarks'],
            $_SESSION['user_id']
        );
        $stmt->execute();
        $room_id = $conn->insert_id;
        $q = mysqli_query($conn,"SELECT floor_name FROM floor_master WHERE id='".(int)$_POST['floor_id']."'");
        $floor = mysqli_fetch_assoc($q);
        $newValues = [
            'Floor'         => $floor['floor_name'] ?? '',
            'Room Number'  => $_POST['room_number'],
            'Room Name'    => $_POST['room_name'],
            'Room Type'    => $_POST['room_type'],
            'Bed Capacity' => $_POST['bed_capacity'],
            'Room Rent'    => $_POST['room_rent'],
            'Gender'       => $_POST['gender_type'],
            'Status'       => $status_val,
            'Remarks'      => $_POST['remarks']
        ];
        addAuditLog(
            'Room Master - Room',
            'Create',
            'Room Created : '.$_POST['room_name'],
            $room_id,
            '',
            json_encode($newValues),
            'SUCCESS'
        );
        /* SAVE ROOM CHARGES */

if(!empty($_POST['room_charges'])){

    foreach($_POST['room_charges'] as $charge_id){

        $amount = $_POST['charge_amount'][$charge_id] ?? 0;

        $stmtCharge=$conn->prepare("
            INSERT INTO room_charge_mapping
            (
                room_id,
                charge_id,
                amount,
                frequency,
                created_by
            )
            SELECT
                ?,
                id,
                ?,
                frequency,
                ?
            FROM charges_master
            WHERE id=?
        ");

        $stmtCharge->bind_param(
            "idii",
            $room_id,
            $amount,
            $_SESSION['user_id'],
            $charge_id
        );

        $stmtCharge->execute();
        $stmtCharge->close();
    }
}
    }
}
// AUTO CREATE BEDS (ONLY WHEN NEW ROOM)
if (empty($_POST['edit_id'])) {
   // $room_id = $conn->insert_id;
    $capacity = (int)$_POST['bed_capacity'];
if($capacity <= 0){
    $capacity = 1; // minimum 1 bed
}
    // check if beds already exist
    $check = $conn->query("SELECT COUNT(*) as cnt FROM bed_master WHERE room_id = $room_id");
    $row = $check->fetch_assoc();
    if($row['cnt'] == 0){
        for($i=1; $i <= $capacity; $i++){
            $bed_no = "Bed-" . $i;
            $bed_type = "General";

            $conn->query("INSERT INTO bed_master (room_id, bed_number, bed_type) 
            VALUES ($room_id, '$bed_no', '$bed_type')");
        }
    }
}
  // ===== ADD BEDS ON EDIT (ONLY INCREASE) =====
if (!empty($_POST['edit_id'])) {
    $room_id = (int)$_POST['edit_id'];
    $new_capacity = (int)$_POST['bed_capacity'];
    // get current beds count
    $res = $conn->query("SELECT COUNT(*) as cnt FROM bed_master WHERE room_id = $room_id");
    $row = $res->fetch_assoc();
    $existing = (int)$row['cnt'];
    // only add if increased
    if($new_capacity > $existing){
        for($i = $existing + 1; $i <= $new_capacity; $i++){
            $bed_no = "Bed-" . $i;
            $bed_type = "General";
            $conn->query("INSERT INTO bed_master (room_id, bed_number, bed_type) 
            VALUES ($room_id, '$bed_no', '$bed_type')");
        }
    }
}
    header("Location: room_master.php#roomTab");
    exit;
}
/* ---------- DATA ---------- */
$hospitals = $conn->query("SELECT id,hospital_name FROM hospital_master ORDER BY hospital_name");
$branches = $conn->query("
SELECT id, branch_name, hospital_id 
FROM branch_master 
ORDER BY branch_name
");
$buildings = $conn->query("SELECT id,building_name,hospital_id,branch_id 
FROM building_master ORDER BY building_name");
$floors = $conn->query("SELECT id,floor_name,building_id FROM floor_master ORDER BY floor_name");
/* LIST for Infrastructure */
$list = $conn->query("
SELECT 
h.hospital_name,
br.branch_name,
b.building_name,
b.id AS building_id,
f.id AS floor_id,
IFNULL(f.floor_name,'-') AS floor_name,
-- STATUS
IFNULL(f.status, b.status) AS status,
-- CREATED BY (user name)
IFNULL(u.full_name, '-') AS created_by,
-- CREATED DATE
IFNULL(DATE_FORMAT(b.created_at,'%d-%b-%Y %h:%i %p'), '-') AS created_date
FROM building_master b
LEFT JOIN hospital_master h ON h.id = b.hospital_id
LEFT JOIN branch_master br ON br.id = b.branch_id
LEFT JOIN floor_master f ON f.building_id = b.id
LEFT JOIN users u ON u.id = b.created_by
ORDER BY h.hospital_name, b.building_name
");
/* ROOM LIST */
$roomList = $conn->query("
SELECT 
r.id,
r.room_number,
r.room_name,
r.room_type,
r.bed_capacity,
r.room_rent,
r.gender_type,
r.status,
r.remarks,
b.building_name,
f.floor_name,
f.id as floor_id,
h.hospital_name,
br.branch_name
FROM room_master r
LEFT JOIN floor_master f ON f.id = r.floor_id
LEFT JOIN building_master b ON b.id = f.building_id
LEFT JOIN branch_master br ON br.id = b.branch_id
LEFT JOIN hospital_master h ON h.id = b.hospital_id
ORDER BY b.building_name, f.floor_name, r.room_number
");
?>
<!DOCTYPE html>
<html>
<head>
<title>Room Master</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<style>
.tab-bar{display:flex;gap:10px;margin:15px}
.tab-btn{border:none;background:#545151;cursor:pointer;border-radius:6px}
.tab-btn.active{background:#2c7be5;color:#fff}
.tab-content{display:none}
.tab-content.active{display:block}
.status-dd{
    border-radius:6px;
    border:none;
    font-weight:600;
    cursor:pointer;
}
.charge-title{
background:#f1f7ff;
padding:10px;
border-radius:8px;
color:#2c7be5;
margin-top:0;
}
/* ACTIVE */
.status-dd.active{
    background:#e8f5e9;
    color:#2e7d32;
}
/* INACTIVE */
.status-dd.inactive{
    background:#fdecea;
    color:#c62828;
}
.room-popup-layout{
display:flex;
gap:20px;
}

.room-left,
.room-right{
background:#fff;
border:1px solid #e5e7eb;
border-radius:12px;
padding:5px;
}

.room-left{
width:50%;
}

.room-right{
width:50%;
}
.charge-table{
width:100%;
border-collapse:collapse;
}
.charge-table{
border-radius:10px;
overflow:hidden;
}

.charge-table th{
background:#2c7be5;
color:#fff;
padding:10px;
}

.charge-table td{
padding:3px;
}

.charge-table tr:hover{
background:#f8faff;
}

.charge-table input[type="number"]{
width:100px;
text-align:right;
font-weight:600;
}
.charge-table input[type="number"]{
width:90px;
}
.btn{background:#2c7be5;color:#fff;padding:6px 12px;border:none;border-radius:5px;cursor:pointer}
.btn:hover{opacity:0.8}
.modal{display:none;position:fixed;width:100%;height:100%;background:rgba(0,0,0,.6);top:0;left:0;z-index:1000}
.modal-box{
background:#fff;
width:1400px;
margin:30px auto;
border-radius:15px;
max-height:98vh;
overflow-y:auto;
box-shadow:0 10px 35px rgba(0,0,0,0.25);
}
.room-header{
    background:#f8faff;
    padding:5px 5px;
    border-radius:10px;
    border-bottom:1px solid #ddd;
}

.room-header h3{
    margin:0;
    color:#2c7be5;
}

.room-header small{
    color:#777;
}

.close-btn{
    font-size:28px;
    cursor:pointer;
    color:#555;
}
.close-btn:hover{
    color:#dc3545;
}
.modal-box form{display:flex;flex-direction:column;gap:12px}
.modal-header{display:flex;justify-content:space-between;align-items:center;}
#searchBox{width:180px;padding:6px;border:1px solid #ccc;border-radius:6px}
#roomSearch{width:180px;padding:6px;border:1px solid #ccc;border-radius:6px}

.confirm-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:1001;justify-content:center;align-items:center}
.confirm-box{background:#fff;padding:25px;border-radius:8px;text-align:center;width:300px}
.confirm-actions{display:flex;gap:10px;justify-content:center;margin-top:20px}
.btn-cancel{background:#6c757d;color:#fff;padding:6px 15px;border:none;border-radius:4px;cursor:pointer}
.btn-confirm{background:#dc3545;color:#fff;padding:6px 15px;border:none;border-radius:4px;cursor:pointer}
input, select, textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px}
</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<body>
<div class="top-bar">
<div class="breadcrumb">
<a href="/dashboard/index.php">Home</a>
<span>&gt;</span>
<a href="/masters/masters.php">Masters</a>
<span>&gt;</span>
<a>Room Master</a>
</div>
<h2 style="margin:0 auto;">Room Master</h2>
<div class="top-actions">
<input type="text" id="searchBox" placeholder="Search..." onkeyup="searchTable()">
<?php if(checkPermission('room_master','add')){ ?>
<button class="btn" onclick="openModal()">+ Add</button>
<?php } ?>
</div>
</div>
<!-- TABS -->
<div style="display:flex; justify-content:space-between; align-items:center; margin:15px;">
    <!-- LEFT : TABS -->
    <div class="tab-bar" style="margin:0;">
        <button class="tab-btn active" onclick="switchTab('infra')">Infrastructure</button>
        <button class="tab-btn" onclick="switchTab('room')">Rooms</button>
        <button class="tab-btn" onclick="switchTab('bed')">Beds</button>
    </div>
    <!-- RIGHT : ROOM ACTIONS -->
    <div id="roomActions" style="display:none; gap:10px; align-items:center;">
        <input type="text"
               id="roomSearch"
               placeholder="Search Room..."
               onkeyup="searchRoomTable()">
        <?php if(checkPermission('room_master','add')){ ?>
<button class="btn" onclick="openRoomModal()">
    + Add Room
</button>
<?php } ?>
    </div>
<!-- RIGHT : BED ACTIONS -->
<div id="bedActions" style="display:none; gap:10px; align-items:center;">
    <input type="text"
           id="bedSearch"
           placeholder="Search Bed..."
           onkeyup="searchBedTable()">
</div>
</div>
<!-- INFRA TAB -->
<div id="infraTab" class="tab-content active">
<table class="table">
<thead>
<tr>
<th>Hospital</th>
<th>Branch</th>
<th>Building</th>
<th>Floor</th>
<th>Status</th>
<th>Created By</th>
<th>Created Date</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php while($r=$list->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($r['hospital_name']) ?></td>
<td><?= htmlspecialchars($r['branch_name'] ?? '') ?></td>
<td><?= htmlspecialchars($r['building_name']) ?></td>
<td><?= htmlspecialchars($r['floor_name']) ?></td>
<td>
    <?php if(checkPermission('room_master','update')){ ?>
<select 
    onchange="changeStatus(this,'<?= empty($r['floor_id'])?'building':'floor' ?>',<?= $r['floor_id'] ?: $r['building_id'] ?>)"
    class="status-dd <?= strtolower($r['status']) ?>">
    <option value="Active" <?= $r['status']=='Active'?'selected':'' ?>>Active</option>
    <option value="Inactive" <?= $r['status']=='Inactive'?'selected':'' ?>>Inactive</option>
</select>
<?php } else { ?>
<?= htmlspecialchars($r['status']) ?>
<?php } ?>
</td>
<td><?= htmlspecialchars($r['created_by']) ?></td>
<td><?= htmlspecialchars($r['created_date']) ?></td>
<td class="action-buttons">
<!-- EDIT -->
<?php if(checkPermission('room_master','update')){ ?>
<button class="btn-icon btn-edit" title="Edit"
onclick="editInfra(
'<?= empty($r['floor_id'])?'building':'floor' ?>',
<?= $r['building_id'] ?: 0 ?>,
<?= $r['floor_id'] ?: 0 ?>,
'<?= htmlspecialchars($r['hospital_name']) ?>',
'<?= htmlspecialchars($r['branch_name'] ?? '') ?>',
'<?= htmlspecialchars($r['building_name']) ?>',
'<?= htmlspecialchars($r['floor_name']) ?>'
)">
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>
<!-- DELETE -->
<?php if(checkPermission('room_master','delete')){ ?>

<button class="btn-icon btn-delete" title="Delete"
onclick="deleteInfra(
'<?= empty($r['floor_id'])?'building':'floor' ?>',
<?= $r['floor_id'] ?: $r['building_id'] ?>
)">
<i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
<!-- ROOM TAB -->
<div id="roomTab" class="tab-content">
<table class="table">
<thead>
<tr>
<th>Hospital</th>
<th>Branch</th>
<th>Building</th>
<th>Floor</th>
<th>Room No</th>
<th>Room Name</th>
<th>Type</th>
<th>Capacity</th>
<th>Rent</th>
<th>Gender</th>
<th>Remarks</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php while($r=$roomList->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($r['hospital_name']) ?></td>
<td><?= htmlspecialchars($r['branch_name'] ?? '') ?></td>
<td><?= htmlspecialchars($r['building_name']) ?></td>
<td><?= htmlspecialchars($r['floor_name']) ?></td>
<td><?= htmlspecialchars($r['room_number']) ?></td>
<td><?= htmlspecialchars($r['room_name']) ?></td>
<td><?= htmlspecialchars($r['room_type']) ?></td>
<td><?= htmlspecialchars($r['bed_capacity']) ?></td>
<td><?= htmlspecialchars($r['room_rent']) ?></td>
<td><?= htmlspecialchars($r['gender_type']) ?></td>
<td title="<?= htmlspecialchars($r['remarks']) ?>">
<?= strlen($r['remarks']) > 30 
    ? substr(htmlspecialchars($r['remarks']),0,30).'...' 
    : htmlspecialchars($r['remarks']) ?>
</td>
<td>
<?php if(checkPermission('room_master','update')){ ?>
<select onchange="changeStatus(this,'room',<?= $r['id'] ?>)" class="status-dd <?= strtolower($r['status']) ?>">
    <option value="Active" <?= $r['status']=='Active'?'selected':'' ?>>Active</option>
    <option value="Inactive" <?= $r['status']=='Inactive'?'selected':'' ?>>Inactive</option>
</select>
<?php } else { ?>
<?= htmlspecialchars($r['status']) ?>
<?php } ?>
</td>
<td class="action-buttons">
<?php if(checkPermission('room_master','update')){ ?>
<button class="btn-icon btn-edit" onclick="editRoom(<?= $r['id'] ?>)">✏️</button>
<?php } ?>
<?php if(checkPermission('room_master','delete')){ ?>
<button class="btn-icon btn-delete" onclick="deleteInfra('room',<?= $r['id'] ?>)">🗑</button>
<?php } ?>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
<!-- BED TAB -->
<div id="bedTab" class="tab-content">
<table class="table">
<thead>
<tr>
<th>Hospital</th>
<th>Branch</th>
<th>Building</th>
<th>Floor</th>
<th>Room</th>
<th>Bed No</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php
$bedList = $conn->query("
SELECT 
b.id,
b.bed_number,
b.status,
r.room_number,
f.floor_name,
bl.building_name,
h.hospital_name,
br.branch_name
FROM bed_master b
LEFT JOIN room_master r ON r.id = b.room_id
LEFT JOIN floor_master f ON f.id = r.floor_id
LEFT JOIN building_master bl ON bl.id = f.building_id
LEFT JOIN branch_master br ON br.id = bl.branch_id
LEFT JOIN hospital_master h ON h.id = bl.hospital_id
ORDER BY h.hospital_name, bl.building_name, f.floor_name, r.room_number
");
while($row = $bedList->fetch_assoc()):
?>
<tr>
<td><?= htmlspecialchars((string)($row['hospital_name'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($row['branch_name'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($row['building_name'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($row['floor_name'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($row['room_number'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($row['bed_number'] ?? '')) ?></td>
<td>
    <?php if(checkPermission('room_master','update')){ ?>
<select onchange="changeBedStatus(this,<?= $row['id'] ?>)" class="status-dd">
<option value="Available" <?= $row['status']=='Available'?'selected':'' ?>>Available</option>
<option value="Maintenance" <?= $row['status']=='Maintenance'?'selected':'' ?>>Maintenance</option>
<?php if($row['status']=='Occupied'): ?>
<option value="Occupied" selected>Occupied</option>
<?php endif; ?>
</select>
<?php } else { ?>
<?= htmlspecialchars($row['status']) ?>
<?php } ?>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
<!-- INFRASTRUCTURE MODAL -->
<div class="modal" id="modal">
<div class="modal-box">
<!-- HEADER -->
<div class="modal-header">
    <h3>Add / Edit Infrastructure</h3>
</div>
<form method="post">
<input type="hidden" name="mode" id="infra_mode">
<input type="hidden" name="edit_id" id="edit_id">
<select id="typeSelect" onchange="infraToggle(this.value)" required>
<option value="">Select Type</option>
<option value="building">Building</option>
<option value="floor">Floor</option>
</select>
<!-- BUILDING -->
<div id="buildingBox" style="display:none">
<select name="hospital_id" id="hospitalSelect"
onchange="filterBranches(this.value)">
<option value="">Select Hospital</option>
<?php 
$hospitals->data_seek(0);
while($h=$hospitals->fetch_assoc()): ?>
<option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['hospital_name']) ?></option>
<?php endwhile; ?>
</select>
<select name="branch_id" id="branchSelect" required>
<option value="">Select Branch</option>
<?php 
$branches->data_seek(0);
while($br = $branches->fetch_assoc()): ?>
<option value="<?= $br['id'] ?>" 
        data-hospital="<?= $br['hospital_id'] ?>">
    <?= htmlspecialchars($br['branch_name']) ?>
</option>
<?php endwhile; ?>
</select>
<input type="text" name="building_name" id="buildingName" placeholder="Building Name">
</div>
<!-- FLOOR -->
<div id="floorBox" style="display:none">
<select id="floorHospitalSelect" onchange="filterFloorBranches(this.value)">
<option value="">Select Hospital</option>
<?php 
$hospitals->data_seek(0);
while($h=$hospitals->fetch_assoc()): ?>
<option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['hospital_name']) ?></option>
<?php endwhile; ?>
</select>
<select id="floorBranchSelect" onchange="filterBuildings(this.value)">
<option value="">Select Branch</option>
<?php 
$branches->data_seek(0);
while($br=$branches->fetch_assoc()): ?>
<option value="<?= $br['id'] ?>" data-hospital="<?= $br['hospital_id'] ?>">
<?= htmlspecialchars($br['branch_name']) ?>
</option>
<?php endwhile; ?>
</select>
<select name="building_id" id="buildingSelect">
<option value="">Select Building</option>
<?php 
$buildings->data_seek(0);
while($b=$buildings->fetch_assoc()): ?>
<option value="<?= $b['id'] ?>" data-branch="<?= $b['branch_id'] ?>">
<?= htmlspecialchars($b['building_name']) ?>
</option>
<?php endwhile; ?>
</select>
<input type="text" name="floor_name" id="floorName" placeholder="Floor Name">
</div>
<div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
    
<?php if(
    checkPermission('room_master','add') ||
    checkPermission('room_master','update')
){ ?>
<button type="submit" class="btn">
<i class="fa-solid fa-save"></i>
Save Room
</button>
<?php } ?>
</div>
</form>
</div>
</div>
<!-- ROOM MODAL -->
<div class="modal" id="roomModal">
<div class="modal-box">
<div class="modal-header room-header">
    <div>
        <h3>
            <i class="fa-solid fa-bed"></i>
            Add / Edit Room
        </h3>
    </div>
    <span onclick="closeRoomModal()" class="close-btn">
        ×
    </span>
</div>
<form method="post">
<div class="room-popup-layout">
<div class="room-left">
    
    
<input type="hidden" name="mode" value="room">
<input type="hidden" name="edit_id" id="room_edit_id">
<!-- HOSPITAL -->
<select id="roomHospital" onchange="filterRoomBranches(this.value)" required>
<option value="">Select Hospital</option>
<?php 
$hospitals->data_seek(0);
while($h=$hospitals->fetch_assoc()): ?>
<option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['hospital_name']) ?></option>
<?php endwhile; ?>
</select>
<!-- BRANCH -->
<select id="roomBranch" onchange="filterRoomBuildings(this.value,false)" required>
<option value="">Select Branch</option>
<?php 
$branches->data_seek(0);
while($br=$branches->fetch_assoc()): ?>
<option value="<?= $br['id'] ?>" data-hospital="<?= $br['hospital_id'] ?>">
<?= htmlspecialchars($br['branch_name']) ?>
</option>
<?php endwhile; ?>
</select>
<!-- BUILDING -->
<select id="roomBuilding" onchange="filterRoomFloors(this.value)" required>
<option value="">Select Building</option>
<?php 
$buildings->data_seek(0);
while($b=$buildings->fetch_assoc()): ?>
<option value="<?= $b['id'] ?>" data-branch="<?= $b['branch_id'] ?>">
<?= htmlspecialchars($b['building_name']) ?>
</option>
<?php endwhile; ?>
</select>
<!-- FLOOR -->
<select name="floor_id" id="roomFloor" required>
<option value="">Select Floor</option>
<?php 
$floors->data_seek(0);
while($f=$floors->fetch_assoc()): ?>
<option value="<?= $f['id'] ?>" data-building="<?= $f['building_id'] ?>">
<?= htmlspecialchars($f['floor_name']) ?>
</option>
<?php endwhile; ?>
</select>
<input type="text" name="room_number" placeholder="Room No" required>
<input type="text" name="room_name" placeholder="Room Name">
<select name="room_type">
<option value="General">General</option>
<option value="Private">Private</option>
<option value="ICU">ICU</option>
<option value="Deluxe">Deluxe</option>
</select>
<input type="number" name="bed_capacity" placeholder="Capacity">
<input type="number" name="room_rent" placeholder="Rent">
<select name="gender_type">
<option value="">Select Gender</option>
<option value="Male">Male</option>
<option value="Female">Female</option>
<option value="Unisex">Unisex</option>
</select>
<select name="status" required>
<option value="">Select Status</option>
<option value="Active">Active</option>
<option value="Inactive">Inactive</option>
</select>
<textarea name="remarks" placeholder="Remarks" rows="3"></textarea>

</div>


<div class="room-right">
<h4 class="charge-title">
<i class="fa-solid fa-money-bill-wave"></i>
Room Charges
</h4>

<table class="charge-table">

<tr>
<th>Select</th>
<th>Charge Name</th>
<th>Type</th>
<th>Frequency</th>
<th>Amount</th>
</tr>
<?php
$roomCharges=$conn->query("
SELECT 
cm.id,
cm.charges_name,
cm.frequency,
cm.charges,
GROUP_CONCAT(ctm.charge_type SEPARATOR ', ') as charge_type
FROM charges_master cm
INNER JOIN charges_type_mapping ctm
ON ctm.charge_id=cm.id
WHERE cm.status=1
GROUP BY cm.id
ORDER BY cm.charges_name
");
while($c=$roomCharges->fetch_assoc()){
?>
<tr>
<td>
<input type="checkbox"
name="room_charges[]"
value="<?=$c['id']?>">
</td>


<td>
<?=$c['charges_name']?>
</td>


<td>
<?=$c['charge_type']?>
</td>


<td>
<?=$c['frequency']?>
</td>


<td>

<input type="number"
name="charge_amount[<?=$c['id']?>]"
value="<?=$c['charges']?>"
data-default="<?=$c['charges']?>">

</td>


</tr>


<?php } ?>


</table>


</div>


</div>
<div style="display:flex; gap:10px; justify-content:flex-end;">
<button type="button" class="btn-cancel" onclick="closeRoomModal()">Cancel</button>
<?php if(
    checkPermission('room_master','add') ||
    checkPermission('room_master','update')
){ ?>
<button type="submit" class="btn">Save</button>
<?php } ?>
</div>
</form>
</div>
</div>
<!-- DELETE CONFIRM MODAL -->
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p id="deleteText">Delete?</p>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
<script>
function openModal(){ 
    document.getElementById('modal').style.display='block'; 
    document.getElementById('infra_mode').value = '';
    document.getElementById('edit_id').value = '';
    document.getElementById('typeSelect').value = '';
    document.getElementById('buildingBox').style.display = 'none';
    document.getElementById('floorBox').style.display = 'none';
}
function closeModal(){
    document.getElementById('modal').style.display='none';
}
function openRoomModal(isEdit=false){
    document.getElementById('roomModal').style.display='block';
    if(!isEdit){
        document.getElementById('room_edit_id').value = '';
        document.querySelector('#roomModal form').reset();
        document.getElementById("roomHospital").value = "";
document.getElementById("roomBranch").value = "";
document.getElementById("roomBuilding").value = "";
document.getElementById("roomFloor").value = "";
        // 🔥 FORCE STATUS
        document.querySelector('#roomModal select[name="status"]').value = "Active";
    }
}
function closeRoomModal(){
    document.getElementById('roomModal').style.display='none';
}
function switchTab(tab){
    document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    if(tab==='infra'){
        document.getElementById('infraTab').classList.add('active');
    }
    if(tab==='room'){
        document.getElementById('roomTab').classList.add('active');
    }
    if(tab==='bed'){
        document.getElementById('bedTab').classList.add('active');
    }
    event.target.classList.add('active');
    document.getElementById("roomActions").style.display =
(tab === 'room') ? 'flex' : 'none';
document.getElementById("bedActions").style.display =
(tab === 'bed') ? 'flex' : 'none';
}
function infraToggle(type){
    // set mode (MOST IMPORTANT)
    document.getElementById('infra_mode').value = type;
    // hide both sections
    document.getElementById('buildingBox').style.display = 'none';
    document.getElementById('floorBox').style.display = 'none';
    // remove required from all fields
    document.querySelectorAll('#buildingBox input, #buildingBox select, #floorBox input, #floorBox select')
    .forEach(el => el.removeAttribute('required'));
    // show correct section + make required
    if(type === 'building'){
        document.getElementById('buildingBox').style.display = 'block';
        document.querySelectorAll('#buildingBox input, #buildingBox select')
        .forEach(el => el.setAttribute('required', true));
    }
    if(type === 'floor'){
        document.getElementById('floorBox').style.display = 'block';
        document.querySelectorAll('#floorBox input, #floorBox select')
        .forEach(el => el.setAttribute('required', true));
    }
}
function filterFloorBranches(hospitalId){
let opts = document.querySelectorAll("#floorBranchSelect option");
opts.forEach(o=>{
    if(!o.value) return;
    o.style.display =
    (!hospitalId || o.dataset.hospital === hospitalId)
    ? 'block'
    : 'none';
});
document.getElementById("floorBranchSelect").value = "";
}
function filterBranches(hospitalId){
let opts = document.querySelectorAll("#branchSelect option");
opts.forEach(o=>{
    if(!o.value) return;
    o.style.display = (!hospitalId || o.dataset.hospital===hospitalId)
        ? 'block'
        : 'none';
});
document.getElementById("branchSelect").value = "";
}
function filterBuildings(branchId){
    let opts = document.querySelectorAll("#buildingSelect option");
    opts.forEach(o=>{
        if(!o.value) return;
        o.style.display =
            (!branchId || o.dataset.branch === branchId)
            ? 'block'
            : 'none';
    });
    document.getElementById('buildingSelect').value = "";
}
function getHospitalIdByName(name){
    let select = document.querySelector('#hospitalSelect');
    for(let opt of select.options){
        if(opt.text.trim() === name.trim()){
            return opt.value;
        }
    }
    return '';
}
function searchTable(){
    let val = document.getElementById('searchBox').value.toLowerCase();
    let rows = document.querySelectorAll("#infraTab table tbody tr");
    rows.forEach((row)=>{
        row.style.display = row.innerText.toLowerCase().includes(val)?'':'none';
    });
}
function searchRoomTable(){
    let val = document.getElementById("roomSearch").value.toLowerCase();
    let rows = document.querySelectorAll("#roomTab table tbody tr");
    rows.forEach(row=>{
        row.style.display = row.innerText.toLowerCase().includes(val)?'':'none';
    });
}
function editInfra(type, buildingId, floorId, hospital, branch, building, floor){
    openModal();
    // set mode
    document.getElementById('typeSelect').value = type;
    infraToggle(type);
    // set edit id
    document.getElementById("edit_id").value = type==='building'?buildingId:floorId;
    // ---- BUILDING EDIT ----
    if(type==='building'){
        let hospitalId = getHospitalIdByName(hospital);
        if(hospitalId) {
    document.querySelector('select[name="hospital_id"]').value = hospitalId;
    filterBranches(hospitalId);
    let branchSelect = document.getElementById("branchSelect");
    for(let opt of branchSelect.options){
        if(opt.text.trim() === branch.trim()){
            opt.selected = true;
            break;
        }
    }
}
        document.querySelector('input[name="building_name"]').value = building;
    }
    // ---- FLOOR EDIT ----
    if(type==='floor'){
        // set hospital
        let hospitalId = getHospitalIdByName(hospital);
        if(hospitalId) {
            document.getElementById('floorHospitalSelect').value = hospitalId;
            // filter buildings
            // select branch automatically
let branchId =
document.querySelector('#buildingSelect option[value="'+buildingId+'"]').dataset.branch;
document.getElementById('floorBranchSelect').value = branchId;
// filter buildings using branch
filterBuildings(branchId);
        }
        // set building
        setTimeout(function() {
            let buildingSelect = document.querySelector('#buildingSelect');
            for(let opt of buildingSelect.options) {
                if(opt.value == buildingId) {
                    opt.selected = true;
                    break;
                }
            }
        }, 100);
        
        // set floor name
        document.querySelector('input[name="floor_name"]').value = floor;
    }
}
function editRoom(id){
    // Fetch room details via AJAX
    fetch(`get_room_details.php?id=${id}&load_charges=1`)
        .then(response => response.json())
        .then(data => {
    if(data.success) {
        // set hospital FIRST
        document.getElementById("roomHospital").value = data.hospital_id;
        filterRoomBranches(data.hospital_id);
        // filter buildings
        document.getElementById("roomBranch").value = data.branch_id;
filterRoomBuildings(data.branch_id, true);
        // set building
        document.getElementById("roomBuilding").value = data.building_id;
        // filter floors
        filterRoomFloors(data.building_id, true);
        // set floor
                document.getElementById("roomFloor").value = data.floor_id;
                document.getElementById('room_edit_id').value = id;
                document.querySelector('#roomModal input[name="room_number"]').value = data.room_number;
                document.querySelector('#roomModal input[name="room_name"]').value = data.room_name;
                document.querySelector('#roomModal select[name="room_type"]').value = data.room_type;
                document.querySelector('#roomModal input[name="bed_capacity"]').value = data.bed_capacity;
                document.querySelector('#roomModal input[name="room_rent"]').value = data.room_rent;
                document.querySelector('#roomModal select[name="gender_type"]').value = data.gender_type;
                document.querySelector('#roomModal select[name="status"]').value = data.status;
                document.querySelector('#roomModal textarea[name="remarks"]').value = data.remarks;
                openRoomModal(true);

/* RESET CHARGES FIRST */

document.querySelectorAll(
    'input[name="room_charges[]"]'
).forEach(function(cb){
    cb.checked = false;
});

document.querySelectorAll(
    'input[name^="charge_amount"]'
).forEach(function(input){

    input.value = input.getAttribute('data-default');

});


/* LOAD SAVED ROOM CHARGES */

if(data.room_charges){

    data.room_charges.forEach(function(charge){

        let checkbox = document.querySelector(
            'input[name="room_charges[]"][value="'+charge.charge_id+'"]'
        );

        if(checkbox){
            checkbox.checked = true;
        }


        let amount = document.querySelector(
            'input[name="charge_amount['+charge.charge_id+']"]'
        );

        if(amount){
            amount.value = charge.amount;
        }

    });

}

            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading room details');
        });
}

let deleteId = 0;
let deleteType = '';
function deleteInfra(type, id){
    deleteId = id;
    deleteType = type;
    document.getElementById("deleteText").innerHTML = "Delete " + type + "? This action cannot be undone.";
    document.getElementById("deleteConfirm").style.display = "flex";
}
function closeDelete(){
    document.getElementById("deleteConfirm").style.display = "none";
}
document.getElementById("confirmDeleteBtn").onclick = function(){
    window.location = "room_master.php?delete_id="+deleteId+"&type="+deleteType;
};
function changeStatus(el,type,id){
    let status = el.value;
    // 🔥 update color instantly
    el.classList.remove('active','inactive');
    el.classList.add(status.toLowerCase());
    // backend update
    window.location = "room_master.php?status_id="+id+"&type="+type+"&status="+status + (type==='room' ? '#roomTab' : '');
}
// Close modal when clicking outside
window.onclick = function(event) {
    let modal = document.getElementById('modal');
    let roomModal = document.getElementById('roomModal');
    let deleteModal = document.getElementById('deleteConfirm');
    if (event.target == modal) {
        modal.style.display = "none";
    }
    if (event.target == roomModal) {
        roomModal.style.display = "none";
    }
    if (event.target == deleteModal) {
        deleteModal.style.display = "none";
    }
}
document.addEventListener('keydown', function(e){
    if(e.key === "Escape"){
        closeModal();
        closeRoomModal();
    }
});
function filterRoomBranches(hospitalId){
let opts = document.querySelectorAll("#roomBranch option");
opts.forEach(o=>{
    if(!o.value) return;
    o.style.display =
    (!hospitalId || o.dataset.hospital === hospitalId)
    ? 'block'
    : 'none';
});
document.getElementById("roomBranch").value = "";
}
function filterRoomBuildings(branchId, isEdit=false){
let opts = document.querySelectorAll("#roomBuilding option");
opts.forEach(o=>{
    if(!o.value) return;
    o.style.display =
    (!branchId || o.dataset.branch === branchId)
    ? 'block'
    : 'none';
});
// ONLY reset when adding
if(!isEdit){
    document.getElementById("roomBuilding").value = "";
    document.getElementById("roomFloor").value = "";
}
}
function filterRoomFloors(buildingId, isEdit=false){
let opts = document.querySelectorAll("#roomFloor option");
opts.forEach(o=>{
if(!o.value) return;
o.style.display = (!buildingId || o.dataset.building === buildingId) ? 'block' : 'none';
});
if(!isEdit){
    document.getElementById("roomFloor").value = "";
}
}
window.addEventListener("load", function(){
if(window.location.href.includes("#roomTab") || window.location.href.includes("type=room")){
    document.getElementById("roomActions").style.display = "flex";
}
if(window.location.href.includes("#bedTab")){
    document.getElementById("bedActions").style.display = "flex";
}
    // check if URL has #roomTab OR status update
    if(window.location.href.includes("#roomTab") || window.location.href.includes("type=room")){
    document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('roomTab').classList.add('active');
    document.querySelectorAll('.tab-btn')[1].classList.add('active');
}
else if(window.location.href.includes("#bedTab")){
    document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('bedTab').classList.add('active');
    document.querySelectorAll('.tab-btn')[2].classList.add('active');
}
});
function changeBedStatus(el,id){
    let status = el.value;
    window.location = "room_master.php?bed_status_id="+id+"&status="+status+"#bedTab";
}
function searchBedTable(){
    let val = document.getElementById("bedSearch").value.toLowerCase();
    let rows = document.querySelectorAll("#bedTab table tbody tr");
    rows.forEach(row=>{
        row.style.display = row.innerText.toLowerCase().includes(val)?'':'none';
    });
}
</script>
</body>
</html>