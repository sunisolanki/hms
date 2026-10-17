<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php'); exit;
}
if (!checkPermission('masters','view')) {
    header('Location:/access_denied.php'); exit;
}
$id = $_GET['id'] ?? '';
$data = [];

$states_res = $conn->query("SELECT id, name FROM states ORDER BY name ASC");
$states = [];

while($row = $states_res->fetch_assoc()){
    $states[] = $row;
}

$cities_res = $conn->query("SELECT id, name FROM cities ORDER BY name ASC");
$cities = [];

while($row = $cities_res->fetch_assoc()){
    $cities[] = $row;
}

if($id){
    $res = $conn->query("SELECT * FROM party_master WHERE id=$id");
    $data = $res->fetch_assoc();
    
}
/* SAVE */
if($_SERVER['REQUEST_METHOD']=='POST'){
    $id = $_POST['id'] ?? '';
    if($id){
        $sql = "UPDATE party_master SET 
        party_type=?, party_name=?, address=?, city=?, pincode=?, mobile=?, phone=?, tin_no=?, type=?, bank_details=?, quality=?, status=?,
        customer_type=?, email=?, website=?, contact_person=?, customer_city=?, reg_address=?, customer_mobile=?, customer_phone=?,
        gstin=?, gst_state=?, exemption=?, exemption_remark=?, vat_no=?, vat_date=?, cst_no=?, cst_date=?, pan_no=?, credit_period=?, credit_amt=?, spcl_discount=?, excise_reg_no=?, drug_license_no=?, tds_type=?, tds_rate=?
        WHERE id=?";
    } else {
        $sql = "INSERT INTO party_master (
        party_type, party_name, address, city, pincode, mobile, phone, tin_no, type, bank_details, quality, status,
        customer_type, email, website, contact_person, customer_city, reg_address, customer_mobile, customer_phone,
        gstin, gst_state, exemption, exemption_remark, vat_no, vat_date, cst_no, cst_date, pan_no, credit_period, credit_amt, spcl_discount, excise_reg_no, drug_license_no, tds_type, tds_rate
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    }
    $stmt = $conn->prepare($sql);
    $fields = [
        $_POST['party_type'], $_POST['party_name'], $_POST['address'], $_POST['city'], 
        $_POST['pincode'], $_POST['mobile'], $_POST['phone'], $_POST['tin_no'], 
        $_POST['type'], $_POST['bank_details'], $_POST['quality'], $_POST['status'],
        $_POST['customer_type'], $_POST['email'], $_POST['website'], $_POST['contact_person'], 
        $_POST['customer_city'], $_POST['reg_address'], $_POST['customer_mobile'], $_POST['customer_phone'],
        $_POST['gstin'], $_POST['gst_state'], $_POST['exemption'], $_POST['exemption_remark'], 
        $_POST['vat_no'], $_POST['vat_date'], $_POST['cst_no'], $_POST['cst_date'], 
        $_POST['pan_no'], $_POST['credit_period'], $_POST['credit_amt'], $_POST['spcl_discount'], 
        $_POST['excise_reg_no'], $_POST['drug_license_no'], $_POST['tds_type'], $_POST['tds_rate']
    ];
    if($id) {
        $fields[] = $id;
    }
    $types = str_repeat("s", count($fields));
    $stmt->bind_param($types, ...$fields);
    $stmt->execute();
    header("Location: party_master.php"); 
    exit;
}
?>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<!DOCTYPE html>
<html>
<head>
<title><?= $id ? 'Edit' : 'Add' ?> Party</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
.form-container {
    margin: 20px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}
.form-body {
    padding: 30px;
}
.section-title {
    background: #93c1ef;
    padding: 12px 20px;
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #333;
    border-bottom: 2px solid #667eea;
}
.section-content {
    padding: 20px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 15px;
    border: ridge;
}
.form-group {
    display: flex;
    flex-direction: column;
}
.form-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}
@media (max-width: 768px) {
    .section-content {
        grid-template-columns: 1fr;
    }
    
    .form-body {
        padding: 20px;
    }
}
</style>
</head>
<body>
<div class="top-bar custom-topbar">
    
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a> >
        <a href="/settings/settings.php">Settings</a> >
        <a href="/masters/masters.php">Masters</a> >
        <a href="/masters/inventory/inventory_master.php">Inventory</a> >
        <a href="party_master.php">Party Master</a>
    
    </div>

    <div class="page-title">
     <h2>   <?= $id ? 'Edit Party' : 'Add Party' ?></h2>
    </div>

    <div class="top-right"></div>

</div>

    
    <div class="form-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?= $data['id'] ?? '' ?>">
            
            <!-- Basic Party Information -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fa-solid fa-building"></i> Basic Party Information
                </h3>
                <div class="section-content">
                    <div class="form-group">
                        <label class="required">Party Type</label>
                        <select name="party_type" required>
                            <option value="Sale" <?= ($data['party_type'] ?? '') == 'Sale' ? 'selected' : '' ?>>Sale</option>
                            <option value="Purchase" <?= ($data['party_type'] ?? '') == 'Purchase' ? 'selected' : '' ?>>Purchase</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Party Name</label>
                        <input type="text" name="party_name" value="<?= htmlspecialchars($data['party_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="address"><?= htmlspecialchars($data['address'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="form-group">
    <label>City</label>
    <select name="city">
        <option value="">Select City</option>
        <?php foreach($cities as $c) { ?>
            <option value="<?= $c['id'] ?>"
                <?= ($data['city'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
        <?php } ?>
    </select>
</div>
                    
                    <div class="form-group">
                        <label>Pincode</label>
                        <input type="text" name="pincode" value="<?= htmlspecialchars($data['pincode'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Mobile</label>
                        <input type="text" name="mobile" value="<?= htmlspecialchars($data['mobile'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($data['phone'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>TIN No</label>
                        <input type="text" name="tin_no" value="<?= htmlspecialchars($data['tin_no'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type">
                            <option value="Generic" <?= ($data['type'] ?? '') == 'Generic' ? 'selected' : '' ?>>Generic</option>
                            <option value="Patient" <?= ($data['type'] ?? '') == 'Patient' ? 'selected' : '' ?>>Patient</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Bank Details</label>
                        <textarea name="bank_details"><?= htmlspecialchars($data['bank_details'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Quality</label>
                        <select name="quality">
                            <option value="Better" <?= ($data['quality'] ?? '') == 'Better' ? 'selected' : '' ?>>Better</option>
                            <option value="Good" <?= ($data['quality'] ?? '') == 'Good' ? 'selected' : '' ?>>Good</option>
                            <option value="Bad" <?= ($data['quality'] ?? '') == 'Bad' ? 'selected' : '' ?>>Bad</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="Active" <?= ($data['status'] ?? '') == 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= ($data['status'] ?? '') == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Customer Details -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fa-solid fa-users"></i> Customer Details
                </h3>
                <div class="section-content">
                    <div class="form-group">
                        <label>Customer Type</label>
                        <select name="customer_type">
                            <option value="Dealer" <?= ($data['customer_type'] ?? '') == 'Dealer' ? 'selected' : '' ?>>Dealer</option>
                            <option value="Project" <?= ($data['customer_type'] ?? '') == 'Project' ? 'selected' : '' ?>>Project</option>
                            <option value="Buyer" <?= ($data['customer_type'] ?? '') == 'Buyer' ? 'selected' : '' ?>>Buyer</option>
                            <option value="Other Party" <?= ($data['customer_type'] ?? '') == 'Other Party' ? 'selected' : '' ?>>Other Party</option>
                            <option value="Other govt" <?= ($data['customer_type'] ?? '') == 'Other govt' ? 'selected' : '' ?>>Other govt</option>
                            <option value="Laundry" <?= ($data['customer_type'] ?? '') == 'Laundry' ? 'selected' : '' ?>>Laundry</option>
                            <option value="Manufacturing" <?= ($data['customer_type'] ?? '') == 'Manufacturing' ? 'selected' : '' ?>>Manufacturing</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($data['email'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Website</label>
                        <input type="text" name="website" value="<?= htmlspecialchars($data['website'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" value="<?= htmlspecialchars($data['contact_person'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
    <label>Customer City</label>
    <select name="customer_city">
        <option value="">Select City</option>
        <?php foreach($cities as $c) { ?>
            <option value="<?= $c['id'] ?>"
                <?= ($data['customer_city'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
        <?php } ?>
    </select>
</div>
                    
                    <div class="form-group full-width">
                        <label>Registered Address</label>
                        <textarea name="reg_address"><?= htmlspecialchars($data['reg_address'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Customer Mobile</label>
                        <input type="text" name="customer_mobile" value="<?= htmlspecialchars($data['customer_mobile'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Customer Phone</label>
                        <input type="text" name="customer_phone" value="<?= htmlspecialchars($data['customer_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>
            
            <!-- Tax Details -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fa-solid fa-file-invoice-dollar"></i> Tax Details
                </h3>
                <div class="section-content">
                    <div class="form-group">
                        <label>GSTIN</label>
                        <input type="text" name="gstin" value="<?= htmlspecialchars($data['gstin'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
    <label>GST State</label>
    <select name="gst_state">
        <option value="">Select State</option>
        <?php foreach($states as $s) { ?>
            <option value="<?= $s['id'] ?>"
                <?= ($data['gst_state'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name']) ?>
            </option>
        <?php } ?>
    </select>
</div>
                    
                    <div class="form-group">
                        <label>Exemption</label>
                        <select name="exemption">
                            <option value="Exempted" <?= ($data['exemption'] ?? '') == 'Exempted' ? 'selected' : '' ?>>Exempted</option>
                            <option value="Not Exempted" <?= ($data['exemption'] ?? '') == 'Not Exempted' ? 'selected' : '' ?>>Not Exempted</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Exemption Remark</label>
                        <textarea name="exemption_remark"><?= htmlspecialchars($data['exemption_remark'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>VAT No</label>
                        <input type="text" name="vat_no" value="<?= htmlspecialchars($data['vat_no'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>VAT Date</label>
                        <input type="date" name="vat_date" value="<?= htmlspecialchars($data['vat_date'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>CST No</label>
                        <input type="text" name="cst_no" value="<?= htmlspecialchars($data['cst_no'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>CST Date</label>
                        <input type="date" name="cst_date" value="<?= htmlspecialchars($data['cst_date'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>PAN No</label>
                        <input type="text" name="pan_no" value="<?= htmlspecialchars($data['pan_no'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Credit Period</label>
                        <input type="text" name="credit_period" value="<?= htmlspecialchars($data['credit_period'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Credit Amount</label>
                        <input type="text" name="credit_amt" value="<?= htmlspecialchars($data['credit_amt'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Special Discount</label>
                        <input type="text" name="spcl_discount" value="<?= htmlspecialchars($data['spcl_discount'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Excise Reg No</label>
                        <input type="text" name="excise_reg_no" value="<?= htmlspecialchars($data['excise_reg_no'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Drug License No</label>
                        <input type="text" name="drug_license_no" value="<?= htmlspecialchars($data['drug_license_no'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
    <label>TDS Type</label>
    <select name="tds_type">
        <option value="">Select TDS Type</option>

        <option value="Professional"
            <?= ($data['tds_type'] ?? '') == 'Professional' ? 'selected' : '' ?>>
            Professional
        </option>

        <option value="Others"
            <?= ($data['tds_type'] ?? '') == 'Others' ? 'selected' : '' ?>>
            Others
        </option>
    </select>
</div>
                    
                    <div class="form-group">
                        <label>TDS Rate</label>
                        <input type="text" name="tds_rate" value="<?= htmlspecialchars($data['tds_rate'] ?? '') ?>">
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="window.location.href='party_master.php'">
                    <i class="fa-solid fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-save">
                    <i class="fa-solid fa-save"></i> Save Party
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>