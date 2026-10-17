<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

/* LOGIN CHECK */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

/* PERMISSION */
if (!checkPermission('masters','view')) {
    header('Location: /access_denied.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Inventory Master</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.container {
    padding-left: 25px;
    padding-right: 25px;
}
.top-bar {
    position: relative;
}
.top-bar h2 {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
}
.breadcrumb {
    display: inline-block;
}
.masters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-top: 30px;
}

.master-card {
    background: linear-gradient(145deg, #ffffff, #f5f9ff);
    border-radius: 12px;
    padding: 25px 20px;
    text-align: center;
    box-shadow: 0 4px 12px rgb(26 142 210);
    transition: all 0.25s ease;
    cursor: pointer;
}

.master-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}

.master-icon {
    font-size: 34px;
    margin-bottom: 12px;
}

.master-card h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.master-card p {
    margin-top: 8px;
    font-size: 13px;
    color: #666;
}

.master-card a {
    text-decoration: none;
    color: inherit;
    display: block;
}
</style>
</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
         <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Inventory Master</a>
    </div> 
    <h2>Inventory / Store Master</h2>
</div>

<div class="container">
<div class="masters-grid">

     <!-- Store -->
    <?php if(checkPermission('store_master','view')){ ?>
<div class="master-card">
    <a href="/masters/inventory/store_master.php">
        <div class="master-icon" style="color:#20c997;">
            <i class="fas fa-warehouse"></i>
        </div>
        <h3>Store</h3>
        <p>Store / Warehouse</p>
    </a>
</div>
<?php } ?>

<!-- UOM -->
    <?php if(checkPermission('uom_master','view')){ ?>
<div class="master-card">
    <a href="/masters/inventory/uom_master.php">
        <div class="master-icon" style="color:#fab005;">
            <i class="fas fa-ruler"></i>
        </div>
        <h3>UOM</h3>
        <p>Unit of Measurement</p>
    </a>
</div>
<?php } ?>

<!-- HSN -->
    <?php if(checkPermission('hsn_master','view')){ ?>
<div class="master-card">
    <a href="/masters/inventory/hsn_master.php">
        <div class="master-icon" style="color:#15aabf;">
            <i class="fas fa-barcode"></i>
        </div>
        <h3>HSN Master</h3>
        <p>HSN Codes</p>
    </a>
</div>
<?php } ?>
<!-- GST Charges -->
   <?php if(checkPermission('state_gst_master','view')){ ?>
<div class="master-card">
    <a href="/masters/inventory/state_gst_master.php">
        <div class="master-icon" style="color:#e64980;">
            <i class="fas fa-file-invoice"></i>
        </div>
        <h3>GST Charges</h3>
        <p>GST Configuration</p>
    </a>
</div>
<?php } ?>
    
    <!-- Category -->
    <?php if(checkPermission('category_master','view')){ ?>
    <div class="master-card">
        <a href="/masters/inventory/category_master.php">
            <div class="master-icon" style="color:#845ef7;">
                <i class="fas fa-tags"></i>
            </div>
            <h3>Category</h3>
            <p>Item Categories</p>
        </a>
    </div>
    <?php } ?>
    <!-- Party -->
    <?php if(checkPermission('party_master','view')){ ?>
    <div class="master-card">
        <a href="/masters/inventory/party_master.php">
            <div class="master-icon" style="color:#0ca678;">
                <i class="fas fa-users"></i>
            </div>
            <h3>Party</h3>
            <p>Suppliers / Vendors</p>
        </a>
    </div>
    <?php } ?>
    <!-- Salts Master -->
    <?php if(checkPermission('salts_master','view')){ ?>
<div class="master-card">
    <a href="/masters/inventory/salts_master.php">
        <div class="master-icon" style="color:#e8590c;">
            <i class="fas fa-flask"></i>
        </div>
        <h3>Salts Master</h3>
        <p>Medicine Salt Composition</p>
    </a>
</div>
<?php } ?>
<?php if(checkPermission('item_master','view')){ ?>
     <!-- Item -->
    <div class="master-card">
        <a href="/masters/inventory/item_master.php">
            <div class="master-icon" style="color:#228be6;">
                <i class="fas fa-box"></i>
            </div>
            <h3>Item</h3>
            <p>Manage Items and Import Items</p>
        </a>
    </div>
<?php } ?>
    <!-- Map Service Item -->
    <?php if(checkPermission('map_service_item','view')){ ?>
    <div class="master-card">
        <a href="/masters/inventory/map_service_item.php">
            <div class="master-icon" style="color:#12b886;">
                <i class="fas fa-link"></i>
            </div>
            <h3>Map Service Item</h3>
            <p>Link Services with Items</p>
        </a>
    </div>
<?php } ?>
    <!-- Kit -->
    <?php if(checkPermission('kit_master','view')){ ?>
    <div class="master-card">
        <a href="/masters/inventory/kit_master.php">
            <div class="master-icon" style="color:#7950f2;">
                <i class="fas fa-box-open"></i>
            </div>
            <h3>Kit</h3>
            <p>Bundle Items</p>
        </a>
    </div>
<?php } ?>
 <!-- UOM Conversion -->
 <?php if(checkPermission('uom_conversion','view')){ ?>
    <div class="master-card">
        <a href="/masters/inventory/uom_conversion.php">
            <div class="master-icon" style="color:#fd7e14;">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <h3>UOM Conversion</h3>
            <p>Convert Units</p>
        </a>
    </div>
    <?php } ?>
    <!-- Tax -->
    <?php if(checkPermission('tax_master','view')){ ?>
    <div class="master-card">
        <a href="/masters/inventory/tax_master.php">
            <div class="master-icon" style="color:#fa5252;">
                <i class="fas fa-percent"></i>
            </div>
            <h3>Tax</h3>
            <p>Tax Setup</p>
        </a>
    </div>
    <?php } ?>
<!-- Inventory Setup -->
<?php if(checkPermission('inventory_setup','view')){ ?>
<div class="master-card">
    <a href="/masters/inventory/inventory_setup.php">
        <div
        class="master-icon"
        style="color:#1971c2;">
            <i class="fas fa-cogs"></i>
        </div>
        <h3>
            Inventory Setup
        </h3>
        <p>
            Inventory Configuration & Settings
        </p>
    </a>
</div>
<?php } ?>
</div>
</div>
</body>
</html>