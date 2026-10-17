<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/* DEFINE ID FIRST */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
/* PERMISSION CHECK */
if ($isEdit) {
    if (!checkPermission('hospital_master', 'edit')) {
        header('Location: /access_denied.php');
        exit;
    }
} else {
    if (!checkPermission('hospital_master', 'add')) {
        header('Location: /access_denied.php');
        exit;
    }
}
$data = [
    'hospital_code' => '',
    'hospital_name' => '',
    'director_name' => '',
    'country_id' => '',
    'state_id' => '',
    'district_id' => '',
    'city_id' => '',
    'landmark' => '',
    'address' => '',
    'pincode' => '',
    'mobile' => '',
    'phone' => '',
    'email' => '',
    'website' => '',
    'pan_no' => '',
    'tin_no' => '',
    'tin_date' => '',
    'gstin' => '',
    'dl_no' => '',
    'detail_1' => '',
    'detail_2' => '',
    'detail_3' => '',
    'detail_4' => '',
    'logo' => '',
    'secondary_logo' => ''
];
if ($id > 0) {
    $sql = "SELECT * FROM hospital_master WHERE id = $id";
    $res = mysqli_query($conn, $sql);
    if ($res && mysqli_num_rows($res) === 1) {
        $data = mysqli_fetch_assoc($res);
        $isEdit = true;
        // Convert saved names to IDs for edit dropdowns
if ($isEdit) {
    // Country ID
    if (!empty($data['country'])) {
        $q = mysqli_query($conn, "SELECT id FROM countries WHERE name = '".mysqli_real_escape_string($conn,$data['country'])."'");
        if ($r = mysqli_fetch_assoc($q)) {
            $data['country_id'] = $r['id'];
        }
    }
    // State ID
    if (!empty($data['state']) && !empty($data['country_id'])) {
        $q = mysqli_query($conn, "SELECT id FROM states 
                                  WHERE name = '".mysqli_real_escape_string($conn,$data['state'])."' 
                                  AND country_id = '{$data['country_id']}'");
        if ($r = mysqli_fetch_assoc($q)) {
            $data['state_id'] = $r['id'];
        }
    }
    // District ID
    if (!empty($data['district']) && !empty($data['state_id'])) {
        $q = mysqli_query($conn, "SELECT id FROM districts 
                                  WHERE name = '".mysqli_real_escape_string($conn,$data['district'])."' 
                                  AND state_id = '{$data['state_id']}'");
        if ($r = mysqli_fetch_assoc($q)) {
            $data['district_id'] = $r['id'];
        }
    }
    // City ID
    if (!empty($data['city']) && !empty($data['district_id'])) {
        $q = mysqli_query($conn, "SELECT id FROM cities 
                                  WHERE name = '".mysqli_real_escape_string($conn,$data['city'])."' 
                                  AND district_id = '{$data['district_id']}'");
        if ($r = mysqli_fetch_assoc($q)) {
            $data['city_id'] = $r['id'];
        }
    }
}
    } else {
        die("Invalid Hospital ID");
    }
}
/* Load masters */
$countries = mysqli_query($conn, "SELECT id, name FROM countries ORDER BY name");
$states = $isEdit
    ? mysqli_query($conn, "SELECT id, name FROM states WHERE country_id='{$data['country_id']}' ORDER BY name")
    : [];
$districts = $isEdit
    ? mysqli_query($conn, "SELECT id, name FROM districts WHERE state_id='{$data['state_id']}' ORDER BY name")
    : [];
$cities = $isEdit
    ? mysqli_query($conn, "SELECT id, name FROM cities WHERE district_id='{$data['district_id']}' ORDER BY name")
    : [];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $isEdit ? 'Edit Hospital' : 'Add Hospital'; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        .actions { margin-top: 20px; }
        </style>
    <script>
    function loadChild(type,id,target){
        fetch("load_location.php?type="+type+"&id="+id)
            .then(res => res.text())
            .then(html => document.getElementById(target).innerHTML = html);
    }
    </script>
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
    <a href="/masters/hospital/hospital_list.php">Hospital Masters</a>
    <span>&gt;</span>
    <a>Add/Edit Hospital</a>
    </div>
<h2 style="margin:0 auto;"><?= $isEdit ? 'Edit Hospital' : 'Add Hospital'; ?></h2>
</div>
<div class="user-wrapper">
<form method="post" action="save.php" enctype="multipart/form-data">
<input type="hidden" name="id" value="<?= $id ?>">
<div class="grid">
    <div><label>Hospital Code *</label><input type="text" name="hospital_code" required value="<?= htmlspecialchars($data['hospital_code']) ?>" <?= $isEdit ? 'readonly' : '' ?>> </div>
    <div><label>Hospital Name *</label><input type="text" name="hospital_name" required value="<?= htmlspecialchars($data['hospital_name']) ?>"></div>
    <div><label>Director Name</label><input type="text" name="director_name" value="<?= htmlspecialchars($data['director_name']) ?>"></div>
    <div><label>Country</label><select name="country_id" required onchange="loadChild('state',this.value,'state_id')"> <option value="">Select Country</option>
    <?php while($c=mysqli_fetch_assoc($countries)){ ?> 
    <option value="<?= $c['id'] ?>"<?= $c['id']==$data['country_id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option><?php } ?></select></div>
    <div> <label>State</label><select name="state_id" id="state_id" required onchange="loadChild('district',this.value,'district_id')">
    <option value="">Select State</option> <?php if($isEdit) while($s=mysqli_fetch_assoc($states)){ ?>
    <option value="<?= $s['id'] ?>" <?= $s['id']==$data['state_id']?'selected':'' ?>> <?= htmlspecialchars($s['name']) ?></option><?php } ?></select></div>
    <div><label>District</label><select name="district_id" id="district_id" required onchange="loadChild('city',this.value,'city_id')">
    <option value="">Select District</option><?php if($isEdit) while($d=mysqli_fetch_assoc($districts)){ ?>
    <option value="<?= $d['id'] ?>"<?= $d['id']==$data['district_id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option><?php } ?></select></div>
    <div><label>City</label><select name="city_id" id="city_id" required><option value="">Select City</option> <?php if($isEdit) while($ct=mysqli_fetch_assoc($cities)){ ?>
    <option value="<?= $ct['id'] ?>"<?= $ct['id']==$data['city_id']?'selected':'' ?>><?= htmlspecialchars($ct['name']) ?></option><?php } ?></select></div>
    <div><label>Landmark</label><input type="text" name="landmark" value="<?= htmlspecialchars($data['landmark']) ?>"></div>
    <div><label>Pincode</label><input type="text" name="pincode" id="pincode" maxlength="10" value="<?= htmlspecialchars($data['pincode']) ?>"></div>
    <div><label>Address</label><textarea name="address"><?= htmlspecialchars($data['address']) ?></textarea></div>
    <div><label>Mobile</label><input type="text" name="mobile" id="mobile" value="<?= htmlspecialchars($data['mobile']) ?>"></div>
    <div><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($data['phone']) ?>"></div>
    <div><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($data['email']) ?>"></div>
    <div><label>Website</label><input type="text" name="website" value="<?= htmlspecialchars($data['website']) ?>"></div>
    <div><label>PAN No</label><input type="text" name="pan_no" value="<?= htmlspecialchars($data['pan_no']) ?>"></div>
    <div><label>TIN No</label><input type="text" name="tin_no" value="<?= htmlspecialchars($data['tin_no']) ?>"></div>
    <div><label>TIN Date</label><input type="date" name="tin_date" value="<?= htmlspecialchars($data['tin_date']) ?>"></div>
    <div><label>GSTIN</label><input type="text" name="gstin" value="<?= htmlspecialchars($data['gstin']) ?>"></div>
    <div><label>DL No</label><input type="text" name="dl_no" value="<?= htmlspecialchars($data['dl_no']) ?>"></div>
    <div><label>Detail 1</label><textarea name="detail_1"><?= htmlspecialchars($data['detail_1']) ?></textarea></div>
    <div><label>Detail 2</label><textarea name="detail_2"><?= htmlspecialchars($data['detail_2']) ?></textarea></div>
    <div><label>Detail 3</label><textarea name="detail_3"><?= htmlspecialchars($data['detail_3']) ?></textarea></div>
    <div><label>Detail 4</label><textarea name="detail_4"><?= htmlspecialchars($data['detail_4']) ?></textarea></div>
    <div><label>Logo</label><input type="file" name="logo" accept="image/*"><?php if ($data['logo']) { ?><img src="/<?= $data['logo'] ?>" class="preview"><?php } ?></div>
    <div><label>Secondary Logo</label><input type="file" name="secondary_logo" accept="image/*"><?php if ($data['secondary_logo']) { ?><img src="/<?= $data['secondary_logo'] ?>" class="preview"><?php } ?></div>
    <div class="actions"><button type="submit" class="btn btn-save">Save Hospital</button><a href="hospital_list.php" class="btn btn-back">Back</a>
</div>
</form>
</div>
<script>
    document.querySelector('form').addEventListener('submit', function(e){
    const countrySelect = document.querySelector('[name="country_id"]');
    const countryText = countrySelect.options[countrySelect.selectedIndex].text.toLowerCase();
    const pincode = document.getElementById('pincode').value;

    if (countryText === 'india') {
        if (!/^[0-9]{6}$/.test(pincode)) {
            alert("Indian pincode must be 6 digits");
            e.preventDefault();
        }
    } else {
        if (!/^[A-Z0-9]{3,10}$/.test(pincode)) {
            alert("Pincode must be alphanumeric (3–10 characters)");
            e.preventDefault();
        }
    }
});

    document.getElementById('pincode').addEventListener('input', function () {
    const countrySelect = document.querySelector('[name="country_id"]');
    const countryText = countrySelect.options[countrySelect.selectedIndex].text.toLowerCase();

    let value = this.value.toUpperCase();

    if (countryText === 'india') {
        // Only digits, max 6
        value = value.replace(/[^0-9]/g, '').slice(0,6);
    } else {
        // Alphanumeric, max 10
        value = value.replace(/[^A-Z0-9]/g, '').slice(0,10);
    }

    this.value = value;
});

</script>
</body>
</html>
