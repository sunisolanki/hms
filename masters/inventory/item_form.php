<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
/* EDIT ID */
$id = $_GET['id'] ?? '';
$data = [];
/* FETCH DROPDOWNS */
$categories = $conn->query("
SELECT *
FROM category_master
WHERE parent_id = 0
ORDER BY category_name ASC
");
$stores = $conn->query("
SELECT *
FROM stores
ORDER BY store_name ASC
");
$salts = $conn->query("
SELECT *
FROM salts_master
ORDER BY salt_name ASC
");
$parties = $conn->query("
SELECT *
FROM party_master
ORDER BY party_name ASC
");
$hsn = $conn->query("
SELECT *
FROM hsn_master
ORDER BY hsn_code ASC
");
/* EDIT FETCH */
$selectedStores = [];
$selectedSalts = [];
if($id){
    $res = $conn->query("
    SELECT *
    FROM item_master
    WHERE id=$id
    ");
    $data = $res->fetch_assoc();
    /* STORES */
    $storeMap = $conn->query("
    SELECT store_id
    FROM item_store_mapping
    WHERE item_id=$id
    ");
    while($s = $storeMap->fetch_assoc()){
        $selectedStores[] = $s['store_id'];
    }
    /* SALTS */
    $saltMap = $conn->query("
    SELECT salt_id
    FROM item_salt_mapping
    WHERE item_id=$id
    ");
    while($s = $saltMap->fetch_assoc()){
        $selectedSalts[] = $s['salt_id'];
    }
}
/* SAVE */

if($_SERVER['REQUEST_METHOD']=='POST'){
    $item_name       = $_POST['item_name'];
    $category_id     = $_POST['category_id'];
    $sub_category_id = $_POST['sub_category_id'];
    $item_group      = $_POST['item_group'];
    $item_type       = $_POST['item_type'];
    $packing         = $_POST['packing'];
    $max_level       = $_POST['max_level'];
    $min_level       = $_POST['min_level'];
    $reorder_level   = $_POST['reorder_level'];
    $manufacturer_id = $_POST['manufacturer_id'];
    $vendor_id       = $_POST['vendor_id'];
    $hsn_id          = $_POST['hsn_id'];
    $risk_type       = $_POST['risk_type'];
    $high_risk       = $_POST['high_risk'];
    $status = $_POST['status'];
    $discount_percent = $_POST['discount_percent'];
    $discount_wef_date = $_POST['discount_wef_date'];
    $stores_selected = $_POST['stores'] ?? [];
    $salts_selected  = $_POST['salts'] ?? [];
    if($id){
        $stmt = $conn->prepare("
        UPDATE item_master SET
        item_name=?,
        category_id=?,
        sub_category_id=?,
        item_group=?,
        item_type=?,
        packing=?,
        max_level=?,
        min_level=?,
        reorder_level=?,
        manufacturer_id=?,
        vendor_id=?,
        hsn_id=?,
        risk_type=?,
        high_risk=?,
        discount_percent=?,
        discount_wef_date=?,
        status=?
        WHERE id=?
        ");
        $stmt->bind_param(
        "siissiiiiiiissdssi",
        $item_name,
        $category_id,
        $sub_category_id,
        $item_group,
        $item_type,
        $packing,
        $max_level,
        $min_level,
        $reorder_level,
        $manufacturer_id,
        $vendor_id,
        $hsn_id,
        $risk_type,
        $high_risk,
        $discount_percent,
        $discount_wef_date,
        $status,
        $id
        );
        $stmt->execute();
        $itemId = $id;
        /* DELETE OLD MAPPING */
        $conn->query("DELETE FROM item_store_mapping WHERE item_id=$itemId");
        $conn->query("DELETE FROM item_salt_mapping WHERE item_id=$itemId");
    }else{
        $stmt = $conn->prepare("
        INSERT INTO item_master(
        item_name,
        category_id,
        sub_category_id,
        item_group,
        item_type,
        packing,
        max_level,
        min_level,
        reorder_level,
        manufacturer_id,
        vendor_id,
        hsn_id,
        risk_type,
        high_risk,
        discount_percent,
        discount_wef_date,
        status
        ) VALUES (
        ?,?,?,?,
        ?,?,?,?,
        ?,?,?,?,
        ?,?,?,?,?
        )
        ");
        $stmt->bind_param(
        "siissiiiiiiissdss",
        $item_name,
        $category_id,
        $sub_category_id,
        $item_group,
        $item_type,
        $packing,
        $max_level,
        $min_level,
        $reorder_level,
        $manufacturer_id,
        $vendor_id,
        $hsn_id,
        $risk_type,
        $high_risk,
        $discount_percent,
        $discount_wef_date,
        $status
        );
        $stmt->execute();
        $itemId = $conn->insert_id;
    }
    /* STORE MAP */
    foreach($stores_selected as $store){
        $stmt = $conn->prepare("
        INSERT INTO item_store_mapping(item_id,store_id)
        VALUES(?,?)
        ");
        $stmt->bind_param("ii",$itemId,$store);
        $stmt->execute();
    }
    /* SALT MAP */
    foreach($salts_selected as $salt){
        $stmt = $conn->prepare("
        INSERT INTO item_salt_mapping(item_id,salt_id)
        VALUES(?,?)
        ");
        $stmt->bind_param("ii",$itemId,$salt);
        $stmt->execute();
    }
    header("Location:item_master.php");
    exit;
}
?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<!DOCTYPE html>
<html>
<head>
<title>Item Form</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<style>
.form-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin:20px;
}
.form-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:15px;
    margin: 20px;
}
.form-group label{
    font-weight:600;
}
.form-group input,
.form-group select{
    width:100%;
}
.top-bar{
    position: relative;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:20px;
}
.top-bar .breadcrumb{
    position:absolute;
    left:0;
}
</style>
</head>
<body>
<div class="top-bar">
<div class="breadcrumb">
<a href="/dashboard/index.php">Home</a>
<span>&gt;</span>
<a href="/masters/masters.php">Masters</a>
<span>&gt;</span>
<a href="/masters/inventory/inventory_master.php">Inventory</a>
<span>&gt;</span>
<a href="item_master.php">Item Master</a>
<span>&gt;</span>
<a><?= $id ? 'Edit Item' : 'Add Item' ?></a>
</div>
<h2><?= $id ? 'Edit Item' : 'Add Item' ?></h2>
</div>
<form method="POST">
<div class="form-grid">
<!-- ITEM NAME -->
<div class="form-group"><label>Item Name *</label><input type="text" name="item_name" required value="<?= htmlspecialchars($data['item_name'] ?? '') ?>"></div>
<!-- CATEGORY -->
<div class="form-group">
<label>Category</label>
<select name="category_id"
id="category_id" required
onchange="loadSubCategory(this.value)">
<option value="">Select</option>
<?php while($c = $categories->fetch_assoc()) { ?>
<option value="<?= $c['id'] ?>"
<?= ($data['category_id'] ?? '')==$c['id']?'selected':'' ?>>
<?= $c['category_name'] ?>
</option>
<?php } ?>
</select>
</div>
<!-- SUB CATEGORY -->
<div class="form-group">
<label>Sub Category</label>
<select name="sub_category_id" id="sub_category_id">
<option value="">Select</option>
</select>
</div>
<!-- STORES -->
<div class="form-group">
<label>Stores</label>
<select name="stores[]"
multiple
class="select2" required>
<?php while($s = $stores->fetch_assoc()) { ?>
<option value="<?= $s['id'] ?>"
<?= in_array($s['id'],$selectedStores)?'selected':'' ?>>
<?= $s['store_name'] ?>
</option>
<?php } ?>
</select>
</div>
<!-- PACKING -->
<div class="form-group">
<label>Packing</label>
<input type="number"
name="packing" required
value="<?= $data['packing'] ?? '' ?>">
</div>
<!-- MAX -->
<div class="form-group">
<label>Max Level</label>
<input type="number"
name="max_level"
value="<?= $data['max_level'] ?? '' ?>">
</div>
<!-- MIN -->
<div class="form-group">
<label>Min Level</label>
<input type="number"
name="min_level"
value="<?= $data['min_level'] ?? '' ?>">
</div>
<!-- REORDER -->
<div class="form-group">
<label>Re Order Level</label>
<input type="number"
name="reorder_level"
value="<?= $data['reorder_level'] ?? '' ?>">
</div>
<!-- SALTS -->
<div class="form-group">
<label>Salts</label>
<select name="salts[]"
multiple
class="select2">
<?php while($s = $salts->fetch_assoc()) { ?>
<option value="<?= $s['id'] ?>"
<?= in_array($s['id'],$selectedSalts)?'selected':'' ?>>
<?= $s['salt_name'] ?>
</option>
<?php } ?>
</select>
</div>
<!-- STATUS -->
<div class="form-group">
<label>Status</label>
<select name="status">
<option value="Active"
<?= ($data['status'] ?? '')=='Active'?'selected':'' ?>>
Active
</option>
<option value="Inactive"
<?= ($data['status'] ?? '')=='Inactive'?'selected':'' ?>>
Inactive
</option>
</select>
</div>
<!-- ITEM GROUP -->
<div class="form-group">
<label>Item Group</label>
<select name="item_group">
<option value="Medicine"
<?= ($data['item_group'] ?? '')=='Medicine'?'selected':'' ?>>
Medicine
</option>
<option value="Equipment"
<?= ($data['item_group'] ?? '')=='Equipment'?'selected':'' ?>>
Equipment
</option>
<option value="Other"
<?= ($data['item_group'] ?? '')=='Other'?'selected':'' ?>>
Other
</option>
</select>
</div>
<!-- ITEM TYPE -->
<div class="form-group">
<label>Item Type</label>
<select name="item_type">
<option value="Capex"
<?= ($data['item_type'] ?? '')=='Capex'?'selected':'' ?>>
Capex
</option>
<option value="Non Capex"
<?= ($data['item_type'] ?? '')=='Non Capex'?'selected':'' ?>>
Non Capex
</option>
</select>
</div>
<!-- MANUFACTURER -->
<div class="form-group">
<label>Manufacturer</label>
<select name="manufacturer_id">
<option value="">Select</option>
<?php mysqli_data_seek($parties,0); ?>
<?php while($p = $parties->fetch_assoc()) { ?>
<option value="<?= $p['id'] ?>"
<?= ($data['manufacturer_id'] ?? '')==$p['id']?'selected':'' ?>>
<?= $p['party_name'] ?>
</option>
<?php } ?>
</select>
</div>
<!-- VENDOR -->
<div class="form-group">
<label>Vendor</label>
<select name="vendor_id" required>
<option value="">Select</option>
<?php mysqli_data_seek($parties,0); ?>
<?php while($p = $parties->fetch_assoc()) { ?>
<option value="<?= $p['id'] ?>"
<?= ($data['vendor_id'] ?? '')==$p['id']?'selected':'' ?>>
<?= $p['party_name'] ?>
</option>
<?php } ?>
</select>
</div>
<!-- HSN -->
<div class="form-group">
<label>HSN Code *</label>
<select name="hsn_id" required>
<option value="">Select</option>
<?php while($h = $hsn->fetch_assoc()) { ?>
<option value="<?= $h['id'] ?>"
<?= ($data['hsn_id'] ?? '')==$h['id']?'selected':'' ?>>
<?= $h['hsn_code'] ?>
</option>
<?php } ?>
</select>
</div>
<!-- RISK -->
<div class="form-group">
<label>Risk Type</label>
<select name="risk_type">
<option value="">Select</option>
<option value="H1"
<?= ($data['risk_type'] ?? '')=='H1'?'selected':'' ?>>
H1
</option>
<option value="H"
<?= ($data['risk_type'] ?? '')=='H'?'selected':'' ?>>
H
</option>
<option value="X1"
<?= ($data['risk_type'] ?? '')=='X1'?'selected':'' ?>>
X1
</option>
</select>
</div>
<!-- HIGH RISK -->
<div class="form-group">
<label>High Risk</label>
<select name="high_risk">
<option value="No"
<?= ($data['high_risk'] ?? '')=='No'?'selected':'' ?>>
No
</option>
<option value="Yes"
<?= ($data['high_risk'] ?? '')=='Yes'?'selected':'' ?>>
Yes
</option>
</select>
</div>
<!-- DISCOUNT -->
<div class="form-group">
<label>Discount %</label>
<input type="number"
step="0.01"
name="discount_percent"
value="<?= $data['discount_percent'] ?? '' ?>">
</div>
<!-- WEF -->
<div class="form-group">
<label>Discount Wef. Date</label>
<input type="date"
name="discount_wef_date"
value="<?= $data['discount_wef_date'] ?? '' ?>">
</div>
</div>
<div class="form-actions">
    <a href="item_master.php" class="btn">
        Cancel
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-floppy-disk"></i>
        Save Item
    </button>
</div>
</form>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$('.select2').select2();
/* LOAD SUBCATEGORY */
function loadSubCategory(categoryId){
    $.ajax({
        url:'load_subcategory.php',
        type:'POST',
        data:{
            category_id:categoryId
        },
        success:function(res){
            $('#sub_category_id').html(res);
            <?php if(!empty($data['sub_category_id'])) { ?>

            $('#sub_category_id').val('<?= $data['sub_category_id'] ?>');

            <?php } ?>
        }
    });
}
/* EDIT LOAD */
<?php if(!empty($data['category_id'])) { ?>
loadSubCategory('<?= $data['category_id'] ?>');
<?php } ?>
</script>
</body>
</html>