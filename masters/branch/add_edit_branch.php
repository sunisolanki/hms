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
/* MODE */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
/* PERMISSION */
if ($isEdit && !checkPermission('branch_master','edit')) {
    header('Location: /access_denied.php'); exit;
}
if (!$isEdit && !checkPermission('branch_master','add')) {
    header('Location: /access_denied.php'); exit;
}
/* DEFAULT DATA */
$data = [
    'hospital_id'=>'','branch_code'=>'','branch_name'=>'',
    'country'=>'','state'=>'','district'=>'','city'=>'',
    'address'=>'','mobile'=>'','email'=>'','website'=>'',
    'detail_1'=>'','detail_2'=>'','detail_3'=>'','detail_4'=>'',
    'gstin'=>'','dl_no'=>'','logo'=>'','status'=>1
];
/* LOAD EDIT */
if ($isEdit) {
    if((int)$_SESSION['rights_type_id'] === 1){
    // SUPER ADMIN
    $q = mysqli_query($conn,"
        SELECT *
        FROM branch_master
        WHERE id=$id
    ");
}
else{
    // OTHER USERS
    $user_id = (int)$_SESSION['user_id'];
    $q = mysqli_query($conn,"
        SELECT b.*
        FROM branch_master b
        INNER JOIN user_branches ub
    ON ub.branch_id = b.id

WHERE b.id='$id'
AND ub.user_id='$user_id'
    ");
}
    if ($q && mysqli_num_rows($q)==1) {
        $data = mysqli_fetch_assoc($q);
    } else {
        header('Location: /access_denied.php');
exit;
    }
}
/* HELPERS */
function nameById($conn,$table,$id){
    $id=(int)$id;
    if(!$id) return '';
    $r=mysqli_query($conn,"SELECT name FROM $table WHERE id=$id");
    $d=mysqli_fetch_assoc($r);
    return $d['name']??'';
}
function idByName($conn,$table,$name,$extra=''){
    if(!$name) return 0;
    $name=mysqli_real_escape_string($conn,$name);
    $r=mysqli_query($conn,"SELECT id FROM $table WHERE name='$name' $extra LIMIT 1");
    $d=mysqli_fetch_assoc($r);
    return $d['id']??0;
}
/* LOCATION IDS FOR EDIT */
$country_id  = idByName($conn,'countries',$data['country']);
$state_id    = idByName($conn,'states',$data['state'],"AND country_id=$country_id");
$district_id = idByName($conn,'districts',$data['district'],"AND state_id=$state_id");
$city_id     = idByName($conn,'cities',$data['city'],"AND district_id=$district_id");
/* SAVE */
if ($_SERVER['REQUEST_METHOD']==='POST') {
 $hospital_id=(int)$_POST['hospital_id'];
    if((int)$_SESSION['rights_type_id'] !== 1){
    $user_id = (int)$_SESSION['user_id'];
    if($isEdit){
        // EDIT → validate assigned branch
        $checkAccess = mysqli_query($conn,"
            SELECT id
            FROM user_branches
            WHERE user_id='$user_id'
            AND branch_id='$id'
        ");
    } else {
        // ADD → validate assigned hospital
        $checkAccess = mysqli_query($conn,"
            SELECT id
            FROM user_hospitals
            WHERE user_id='$user_id'
            AND hospital_id='$hospital_id'
        ");
    }
    if(mysqli_num_rows($checkAccess) == 0){

        header('Location: /access_denied.php');
        exit;
    }
}
    $country  = nameById($conn,'countries',$_POST['country_id']);
    $state    = nameById($conn,'states',$_POST['state_id']);
    $district = nameById($conn,'districts',$_POST['district_id']);
    $city     = nameById($conn,'cities',$_POST['city_id']);
    $logoPath=$data['logo'];
    if(!empty($_FILES['logo']['name'])){
        $dir=$_SERVER['DOCUMENT_ROOT'].'/uploads/branches/';
        if(!is_dir($dir)) mkdir($dir,0755,true);
        $file=time().'_'.basename($_FILES['logo']['name']);
        move_uploaded_file($_FILES['logo']['tmp_name'],$dir.$file);
        $logoPath='uploads/branches/'.$file;
    }
    if($isEdit){
        $oldDataQry = mysqli_query(
    $conn,
    "SELECT * FROM branch_master WHERE id = $id"
);
$oldData = mysqli_fetch_assoc($oldDataQry);
        mysqli_query($conn,"
        
            UPDATE branch_master SET
            hospital_id='$hospital_id',
            branch_code='".mysqli_real_escape_string($conn,$_POST['branch_code'])."',
            branch_name='".mysqli_real_escape_string($conn,$_POST['branch_name'])."',
            country='$country', state='$state', district='$district', city='$city',
            address='".mysqli_real_escape_string($conn,$_POST['address'])."',
            mobile='".mysqli_real_escape_string($conn,$_POST['mobile'])."',
            email='".mysqli_real_escape_string($conn,$_POST['email'])."',
            website='".mysqli_real_escape_string($conn,$_POST['website'])."',
            detail_1='".mysqli_real_escape_string($conn,$_POST['detail_1'])."',
            detail_2='".mysqli_real_escape_string($conn,$_POST['detail_2'])."',
            detail_3='".mysqli_real_escape_string($conn,$_POST['detail_3'])."',
            detail_4='".mysqli_real_escape_string($conn,$_POST['detail_4'])."',
            gstin='".mysqli_real_escape_string($conn,$_POST['gstin'])."',
            dl_no='".mysqli_real_escape_string($conn,$_POST['dl_no'])."',
            logo='$logoPath',
            status='".(int)$_POST['status']."'
            WHERE id=$id
        ");
        $oldValues = [];
$newValues = [];

$fields = [
    'hospital_id' => $hospital_id,
    'branch_code' => $_POST['branch_code'],
    'branch_name' => $_POST['branch_name'],
    'country' => $country,
    'state' => $state,
    'district' => $district,
    'city' => $city,
    'address' => $_POST['address'],
    'mobile' => $_POST['mobile'],
    'email' => $_POST['email'],
    'website' => $_POST['website'],
    'detail_1' => $_POST['detail_1'],
    'detail_2' => $_POST['detail_2'],
    'detail_3' => $_POST['detail_3'],
    'detail_4' => $_POST['detail_4'],
    'gstin' => $_POST['gstin'],
    'dl_no' => $_POST['dl_no'],
    'logo' => $logoPath,
    'status' => (int)$_POST['status']
];

foreach($fields as $field => $newValue){

    $oldValue = $oldData[$field] ?? '';

    if((string)$oldValue !== (string)$newValue){

        $oldValues[$field] = $oldValue;
        $newValues[$field] = $newValue;
    }
}
addAuditLog(
    'Branch Master',
    'UPDATE',
    'Branch Updated : '.$_POST['branch_name'],
    $id,
    json_encode($oldValues),
    json_encode($newValues)
);
    } else {
        mysqli_query($conn,"
            INSERT INTO branch_master SET
            hospital_id='$hospital_id',
            branch_code='".mysqli_real_escape_string($conn,$_POST['branch_code'])."',
            branch_name='".mysqli_real_escape_string($conn,$_POST['branch_name'])."',
            country='$country', state='$state', district='$district', city='$city',
            address='".mysqli_real_escape_string($conn,$_POST['address'])."',
            mobile='".mysqli_real_escape_string($conn,$_POST['mobile'])."',
            email='".mysqli_real_escape_string($conn,$_POST['email'])."',
            website='".mysqli_real_escape_string($conn,$_POST['website'])."',
            detail_1='".mysqli_real_escape_string($conn,$_POST['detail_1'])."',
            detail_2='".mysqli_real_escape_string($conn,$_POST['detail_2'])."',
            detail_3='".mysqli_real_escape_string($conn,$_POST['detail_3'])."',
            detail_4='".mysqli_real_escape_string($conn,$_POST['detail_4'])."',
            gstin='".mysqli_real_escape_string($conn,$_POST['gstin'])."',
            dl_no='".mysqli_real_escape_string($conn,$_POST['dl_no'])."',
            logo='$logoPath',
created_by='{$_SESSION['user_id']}',
status=1
        ");
        $branchId = mysqli_insert_id($conn);

addAuditLog(
    'Branch Master',
    'CREATE',
    'Branch Created : '.$_POST['branch_name'],
    $branchId,
    '',
    json_encode([
    'hospital_id' => $hospital_id,
    'branch_code' => $_POST['branch_code'],
    'branch_name' => $_POST['branch_name'],
    'country' => $country,
    'state' => $state,
    'district' => $district,
    'city' => $city,
    'address' => $_POST['address'],
    'mobile' => $_POST['mobile'],
    'email' => $_POST['email'],
    'website' => $_POST['website'],
    'detail_1' => $_POST['detail_1'],
    'detail_2' => $_POST['detail_2'],
    'detail_3' => $_POST['detail_3'],
    'detail_4' => $_POST['detail_4'],
    'gstin' => $_POST['gstin'],
    'dl_no' => $_POST['dl_no'],
    'status' => 1
    ])
);
    }
    header("Location: branch_list.php");
    exit;
}
/* MASTERS */
if((int)$_SESSION['rights_type_id'] === 1){
    // SUPER ADMIN
    $hospitals = mysqli_query($conn,"
        SELECT id,hospital_name
        FROM hospital_master
        WHERE status=1
    ");
}
else{
    // OTHER USERS
    $user_id = (int)$_SESSION['user_id'];
    $hospitals = mysqli_query($conn,"
        SELECT DISTINCT
            h.id,
            h.hospital_name
        FROM hospital_master h
        INNER JOIN user_hospitals uh
            ON uh.hospital_id = h.id
        WHERE uh.user_id='$user_id'
        AND h.status=1
    ");
}
$countries=mysqli_query($conn,"SELECT id,name FROM countries ORDER BY name");

?>

<script>
function loadLocation(type, parentId, targetId, selectedId = 0) {

    if (!parentId) {
        document.getElementById(targetId).innerHTML = '<option value="">Select</option>';
        return;
    }

    fetch(
        '/masters/hospital/load_location.php'
        + '?type=' + type
        + '&id=' + parentId
        + '&selected=' + selectedId
    )
    .then(res => res.text())
    .then(html => {
        document.getElementById(targetId).innerHTML = html;
    });
}
</script>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Masters - WebSplend HMS</title>

    <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<style>
        input, textarea, select {
            width: 100%;
            padding: 7px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        textarea { resize: vertical; }
    </style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<body>
    <div class="top-bar">
    <div class="breadcrumb">
    <a href="/dashboard/index.php">Home</a>
    <span>&gt;</span>
    <a href="/settings/settings.php">Settings</a>
    <span>&gt;</span>
    <a href="/masters/masters.php">Masters</a>
    <span>&gt;</span>
    <a href="/masters/branch/branch_list.php">Branch Masters</a>
    <span>&gt;</span>
    <a>Add/Edit Branch</a>
    </div>
    <h2 style="margin:0 auto;"><?= $isEdit?'Edit':'Add' ?> Branch
    </div>
<div class="user-wrapper">
<form method="post" enctype="multipart/form-data">
<div class="grid">
<div>
<label>Organization</label>
<select name="hospital_id" required>
<option value="">Select Hospital</option>
<?php
if(
    $isEdit
    && $data['hospital_id']
){
    $selectedHospital = mysqli_query($conn,"
        SELECT id,hospital_name
        FROM hospital_master
        WHERE id='".$data['hospital_id']."'
        LIMIT 1
    ");
    if($selectedHospital && mysqli_num_rows($selectedHospital)>0){
        $sh = mysqli_fetch_assoc($selectedHospital);
        echo '<option value="'.$sh['id'].'" selected>'
            .htmlspecialchars($sh['hospital_name']).
        '</option>';
    }
}
?>
<?php while($h=mysqli_fetch_assoc($hospitals)){ ?>
<option value="<?= $h['id'] ?>" <?= $h['id']==$data['hospital_id']?'selected':'' ?>>
<?= htmlspecialchars($h['hospital_name']) ?>
</option>
<?php } ?>
</select>
</div>

<div>
<label>Branch Code</label>
<input name="branch_code" value="<?= htmlspecialchars($data['branch_code']) ?>" required>
</div>

<div>
<label>Branch Name</label>
<input name="branch_name" value="<?= htmlspecialchars($data['branch_name']) ?>" required>
</div>

<div>
<label>Country</label>
<select name="country_id" onchange="loadLocation('state',this.value,'state_id')">
<option value="">Select</option>
<?php while($c=mysqli_fetch_assoc($countries)){ ?>
<option value="<?= $c['id'] ?>" <?= $c['id']==$country_id?'selected':'' ?>>
<?= htmlspecialchars($c['name']) ?>
</option>
<?php } ?>
</select>
</div>

<div>
<label>State</label>
<select name="state_id" id="state_id" onchange="loadLocation('district',this.value,'district_id')">
<option value="">Select</option>
</select>
</div>

<div>
<label>District</label>
<select name="district_id" id="district_id" onchange="loadLocation('city',this.value,'city_id')">
<option value="">Select</option>
</select>
</div>

<div>
<label>City</label>
<select name="city_id" id="city_id">
<option value="">Select</option>
</select>
</div>

<div>
<label>Mobile</label>
<input name="mobile" value="<?= htmlspecialchars($data['mobile']) ?>">
</div>

<div>
<label>Email</label>
<input name="email" value="<?= htmlspecialchars($data['email']) ?>">
</div>

<div>
<label>Website</label>
<input name="website" value="<?= htmlspecialchars($data['website']) ?>">
</div>

<div>
<label>Address</label>
<textarea name="address"><?= htmlspecialchars($data['address']) ?></textarea>
</div>

<div>
<label>GSTIN</label>
<input name="gstin" value="<?= htmlspecialchars($data['gstin']) ?>">
</div>

<div>
<label>DL No</label>
<input name="dl_no" value="<?= htmlspecialchars($data['dl_no']) ?>">
</div>

<div>
<label>Detail 1</label>
<textarea name="detail_1"><?= htmlspecialchars($data['detail_1']) ?></textarea>
</div>

<div>
<label>Detail 2</label>
<textarea name="detail_2"><?= htmlspecialchars($data['detail_2']) ?></textarea>
</div>

<div>
<label>Detail 3</label>
<textarea name="detail_3"><?= htmlspecialchars($data['detail_3']) ?></textarea>
</div>

<div>
<label>Detail 4</label>
<textarea name="detail_4"><?= htmlspecialchars($data['detail_4']) ?></textarea>
</div>

<div>
<label>Status</label>
<select name="status">
<option value="1" <?= $data['status']==1?'selected':'' ?>>Active</option>
<option value="0" <?= $data['status']==0?'selected':'' ?>>Inactive</option>
</select>
</div>

<div>
<label>Logo</label>
<input type="file" name="logo">
<?php if($data['logo']){ ?>
<img src="/<?= htmlspecialchars($data['logo']) ?>" height="50">
<?php } ?>
</div>

</div>

<div class="actions">
<button class="btn btn-success">Save</button>
<a href="branch_list.php" class="btn btn-secondary">Back</a>
</div>

</form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {

    <?php if ($isEdit): ?>
        loadLocation('state', <?= (int)$country_id ?>, 'state_id', <?= (int)$state_id ?>);

        setTimeout(function () {
            loadLocation('district', <?= (int)$state_id ?>, 'district_id', <?= (int)$district_id ?>);
        }, 300);

        setTimeout(function () {
            loadLocation('city', <?= (int)$district_id ?>, 'city_id', <?= (int)$city_id ?>);
        }, 600);
    <?php endif; ?>

});
</script>


<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
