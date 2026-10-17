<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /login/login.php');
    exit;
}
if (!checkPermission('referral_master','view')) {
    header('Location: /access_denied.php');
    exit;
}
/* ==============================
   INSERT LOGIC
============================== */
if (isset($_POST['save_referral'])) {
    $id = (int)($_POST['id'] ?? 0);

if (
    ($id > 0 && !checkPermission('referral_master','update')) ||
    ($id == 0 && !checkPermission('referral_master','add'))
) {
    header('Location: /access_denied.php');
    exit;
}
   
$oldReferral = [];
$changedFields = [];

if (!empty($id)) {

    $q = $conn->prepare("
        SELECT *
        FROM referral_master
        WHERE id=?
    ");

    $q->bind_param("i", $id);
    $q->execute();

    $oldReferral = $q->get_result()->fetch_assoc();

    $q->close();
}
if (!empty($oldReferral)) {

    $fields = [
        'name' => 'Name',
        'category' => 'Category',
        'org_name' => 'Organization Name',
        'mobile' => 'Mobile',
        'city_id' => 'City'
    ];

    foreach ($fields as $dbField => $label) {

        if ($dbField == 'city_id') {

    $oldCityId = (int)($oldReferral['city_id'] ?? 0);
    $newCityId = (int)($_POST['city_id'] ?? 0);

    $oldCity = '';
    $newCity = '';

    if ($oldCityId > 0) {
        $q = mysqli_query($conn, "SELECT name FROM cities WHERE id=$oldCityId");
        if ($r = mysqli_fetch_assoc($q)) {
            $oldCity = $r['name'];
        }
    }

    if ($newCityId > 0) {
        $q = mysqli_query($conn, "SELECT name FROM cities WHERE id=$newCityId");
        if ($r = mysqli_fetch_assoc($q)) {
            $newCity = $r['name'];
        }
    }

    $oldValue = $oldCity;
    $newValue = $newCity;

} else {

    $oldValue = (string)($oldReferral[$dbField] ?? '');
    $newValue = (string)($_POST[$dbField] ?? '');
}

        if ($oldValue !== $newValue) {

            $changedFields[$label] = [
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }
}
    $name       = trim($_POST['name']);
    $category   = $_POST['category'];
    $org_name   = trim($_POST['org_name']);
    $mobile     = trim($_POST['mobile']);
    $city_id    = $_POST['city_id'];
    if (empty($name) || empty($category) || empty($city_id)) {
        $error = "Please fill all mandatory fields.";
    } else {
        if (!empty($id)) {
            // UPDATE
            $stmt = $conn->prepare("UPDATE referral_master
SET
    name=?,
    category=?,
    org_name=?,
    mobile=?,
    city_id=?,
    updated_at=NOW()
WHERE id=?");
            $stmt->bind_param("ssssii", $name, $category, $org_name, $mobile, $city_id, $id);
        } else {
            // INSERT
            $stmt = $conn->prepare("INSERT INTO referral_master
(name, category, org_name, mobile, city_id, created_by)
VALUES (?, ?, ?, ?, ?, ?)");
            $created_by = $_SESSION['user_id'];
$stmt->bind_param(
    "ssssii",
    $name,
    $category,
    $org_name,
    $mobile,
    $city_id,
    $created_by
);
        }

        if ($stmt->execute()) {
            if (empty($id)) {

    $newValues = [
        'Name' => $name,
        'Category' => $category,
        'Organization Name' => $org_name,
        'Mobile' => $mobile,
        'City' => $city_id
    ];

    addAuditLog(
        'Referral Master',
        'Create',
        "Referral Created : ".$name,
        $conn->insert_id,
        '',
        json_encode($newValues),
        'SUCCESS'
    );

}
if (!empty($id) && !empty($changedFields)) {

    $oldValues = [];
    $newValues = [];

    foreach ($changedFields as $field => $values) {

        $oldValues[$field] = $values['old'];
        $newValues[$field] = $values['new'];
    }

    addAuditLog(
        'Referral Master',
        'Update',
        "Referral Updated : ".$name,
        $id,
        json_encode($oldValues),
        json_encode($newValues),
        'SUCCESS'
    );
}
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } 
        
else {
            $error = "Something went wrong.";
        }
    }
}
/* ==============================
   FETCH CITY LIST
============================== */
$city_result = $conn->query("SELECT id, name FROM cities ORDER BY name ASC");
/* ==============================
   FETCH REFERRAL LIST
============================== */
$list_result = $conn->query("
    SELECT
    r.*,
    c.name AS city_name,
    u.full_name AS created_by_name
FROM referral_master r
LEFT JOIN cities c
    ON r.city_id = c.id
LEFT JOIN users u
    ON u.id = r.created_by
ORDER BY r.id DESC
");
?>
<?php
if (isset($_GET['delete']) && checkPermission('referral_master','delete')) {

    $id = (int)$_GET['delete'];

    $stmt = $conn->prepare("
        SELECT *
        FROM referral_master
        WHERE id=?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $oldReferral = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if ($oldReferral) {

        addAuditLog(
            'Referral Master',
            'Delete',
            "Referral Deleted : ".$oldReferral['name'],
            $id,
            json_encode($oldReferral),
            '',
            'SUCCESS'
        );
    }

    mysqli_query($conn, "DELETE FROM referral_master WHERE id=$id");

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Referral Master</title>
    <link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }

        .modal-content {
            background: #fff;
            width: 500px;
            margin: 8% auto;
            padding: 20px;
            border-radius: 8px;
        }

        .close-btn {
            float: right;
            cursor: pointer;
            font-weight: bold;
        }
    </style>
</head>

<body>

<?php include $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="container">

    <div class="top-bar">  
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Referral Master</a>
    </div>
    <h2 style="margin:0 auto;">Referral Master</h2>
    <?php if(checkPermission('referral_master','add')){ ?>
<button class="btn" onclick="openModal()">
    + Add Referral
</button>
<?php } ?>
</div>
    <?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>
    <?php if(isset($success)) echo "<div class='success'>$success</div>"; ?>
    <!-- ================= LISTING ================= -->
    <div style="overflow-x:auto;">
        <table>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Category</th>
                <th>Org Name</th>
                <th>Mobile</th>
                <th>City</th>
                <th>Created By</th>
                <th>Updated Date</th>
                <th>Created</th>
                <th>Action</th>
            </tr>

            <?php $i=1; while($row = $list_result->fetch_assoc()): ?>
<tr>
    <td><?= $i++; ?></td>
               <td><?= htmlspecialchars($row['name'] ?? '') ?></td>
<td><?= htmlspecialchars($row['category'] ?? '') ?></td>
<td><?= htmlspecialchars($row['org_name'] ?? '') ?></td>
<td><?= htmlspecialchars($row['mobile'] ?? '') ?></td>
<td><?= htmlspecialchars($row['city_name'] ?? '') ?></td>
<td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A') ?></td>

<td>
<?= !empty($row['updated_at'])
    ? date('d-m-Y h:i A', strtotime($row['updated_at']))
    : 'N/A'; ?>
</td>
                <td><?= !empty($row['created_at'])
    ? date('d-m-Y h:i A', strtotime($row['created_at']))
    : 'N/A'; ?></td>
                <td class="action-buttons">

<?php if(checkPermission('referral_master','update')){ ?>
<button class="btn-icon btn-edit"
onclick='editReferral(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

<?php if(checkPermission('referral_master','delete')){ ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id'] ?>)">
    <i class="fa-solid fa-trash"></i>
</button>
<?php } ?>

</td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

</div>

<!-- ================= MODAL ================= -->

<div id="referralModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
    <h3 id="modalTitle">Add Referral</h3>
    <button type="button" class="btn btn-danger btn-sm" onclick="closeModal()">Cancel</button>
</div>

        <form method="POST">

            <div class="form-group">
                <label>Name *</label>
                <input type="text" name="name" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Category *</label>
                <select name="category" class="form-control" required>
                    <option value="">Select Category</option>
                    <option value="Hospital">Hospital</option>
                    <option value="Clinic">Clinic</option>
                    <option value="Laboratory">Laboratory</option>
                    <option value="Trust">Trust</option>
                    <option value="In Person">In Person</option>
                </select>
            </div>

            <div class="form-group">
                <label>Org Name</label>
                <input type="text" name="org_name" class="form-control">
            </div>

            <div class="form-group">
                <label>Mobile Number</label>
                <input type="text" name="mobile" class="form-control">
            </div>

            <div class="form-group">
                <label>City *</label>
                <select name="city_id" class="form-control" required>
                    <option value="">Select City</option>
                    <?php
                    $city_result = $conn->query("SELECT id, name FROM cities ORDER BY name ASC");
                    while($city = $city_result->fetch_assoc()):
                    ?>
                        <option value="<?= $city['id']; ?>">
                            <?= $city['name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <br>
            <?php if(
    checkPermission('referral_master','add') ||
    checkPermission('referral_master','update')
){ ?>
<button type="submit" name="save_referral" class="btn btn-primary">
    Save Referral
</button>
<?php } ?>

        </form>
    </div>
</div>

<script>
function openModal() {

    document.getElementById("referralModal").style.display = "block";

    document.getElementById("modalTitle").innerText = "Add Referral";

    document.querySelector("#referralModal form").reset();

    let idField = document.querySelector('[name="id"]');

    if(idField){
        idField.value = "";
    }
}

function closeModal() {
    document.getElementById("referralModal").style.display = "none";
}

window.onclick = function(event) {
    if (event.target == document.getElementById("referralModal")) {
        closeModal();
    }
}
</script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Delete this referral?</p>
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
    window.location.href = "?delete=" + deleteId;
};

function editReferral(data){
    openModal();

    document.getElementById('modalTitle').innerText = 'Edit Referral';

    // hidden ID field
    let idField = document.querySelector('[name="id"]');
    if(!idField){
        idField = document.createElement('input');
        idField.type = 'hidden';
        idField.name = 'id';
        document.querySelector('#referralModal form').appendChild(idField);
    }

    idField.value = data.id;

    document.querySelector('[name="name"]').value = data.name;
    document.querySelector('[name="category"]').value = data.category;
    document.querySelector('[name="org_name"]').value = data.org_name;
    document.querySelector('[name="mobile"]').value = data.mobile;
    document.querySelector('[name="city_id"]').value = data.city_id;
}
</script>
</body>
</html>
