<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* SETTINGS PAGE VIEW PERMISSION */
if (!checkPermission('settings','view')) {
    header('Location: /access_denied.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Settings - WebSplend HMS</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<!-- FONT AWESOME -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===== TOP BAR FIX ===== */
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
    white-space: nowrap;
}
.settings-icon {
    font-size: 34px;
    margin-bottom: 12px;
    text-align: center;
    width: 100%;
}
/* ===== GRID ===== */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
}
/* ===== CARD ===== */
.settings-card {
    background: linear-gradient(145deg, #ffffff, #f5f9ff);
    padding: 25px 20px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 4px 12px rgb(26 142 210);
    text-decoration: none;
    color: #333;
    transition: transform .25s ease, box-shadow .25s ease;
    cursor: pointer;
}
/* SAME HOVER FEEL */
.settings-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 18px 40px rgba(0,0,0,0.15);
}
/* ICON */
.settings-icon {
    font-size: 34px;
    margin-bottom: 12px;
}
/* TEXT */
.settings-card h4 {
    margin-bottom: 6px;
    font-size: 16px;
    font-weight: 600;
    color: #333;
}
.settings-card p {
    font-size: 13px;
    color: #666;
}

</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a>Settings</a>
    </div>
    <h2>Settings</h2>
</div>
<div class="container settings-wrapper">
<div class="settings-grid">
<!-- PROFILE -->
<a href="/profile/profile.php" class="settings-card">
    <div class="settings-icon" style="color:#4dabf7;"><i class="fas fa-user-circle"></i></div>
    <h4>My Profile</h4>
    <p>Manage your personal details</p>
</a>
<!-- USERS -->
<?php if (checkPermission('user_management', 'view')): ?>
<a href="/settings/users/users.php" class="settings-card">
    <div class="settings-icon" style="color:#20c997;"><i class="fas fa-users"></i></div>
    <h4>User Management</h4>
    <p>Create and manage users</p>
</a>
<?php endif; ?>
<!-- Hospital MASTERS -->
<?php if (checkPermission('masters','view')): ?>
<a href="/masters/masters.php" class="settings-card">
    <div class="settings-icon" style="color:#845ef7;"><i class="fas fa-layer-group"></i></div>
    <h4>Hospital Masters</h4>
    <p>Manage system master data</p>
</a>
<?php endif; ?>
<!-- Inventory MASTERS -->
<?php if (checkPermission('inventory_master','view')): ?>
    <a href="/masters/inventory/inventory_master.php" class="settings-card">
        <div class="settings-icon" style="color:#0ca678;"><i class="fas fa-boxes"></i></div>
        <h4>Inventory / Store</h4>
        <p>Manage inventory & store items</p>
    </a>
    <?php endif; ?>
<!--Laboratory MASTERS -->
<?php if (checkPermission('laboratory_master','view')): ?>
    <a href="/masters/laboratory/laboratory_master.php"  class="settings-card">
        <div class="settings-icon" style="color:#c2255c;"><i class="fas fa-flask"></i></div>
        <h4>Laboratory Master</h4>
        <p>Manage laboratories & lab setup</p>
    </a>
    <?php endif; ?>
<!-- IMPORT
<?php if (checkPermission('import','view')): ?>
<a href="/import/index.php" class="settings-card">
    <div class="settings-icon" style="color:#fab005;"><i class="fas fa-file-import"></i></div>
    <h4>Import</h4>
    <p>Bulk upload data</p>
</a>
<?php endif; ?>  -->
<!-- SETTINGS -->
<?php if (checkPermission('system_settings','view')): ?>
<a href="/settings/setup/setup.php" class="settings-card">
    <div class="settings-icon" style="color:#e64980;"><i class="fas fa-cogs"></i></div>
    <h4>Setup</h4>
    <p>Settings & configurations</p>
</a>
<?php endif; ?>
<!-- COMMUNICATION -->
<?php if (checkPermission('emails','view')): ?>
<a href="/communication/communication.php" class="settings-card">
    <div class="settings-icon" style="color:#15aabf;"><i class="fas fa-envelope-open-text"></i></div>
    <h4>Communications</h4>
    <p>Email / SMS / Whatsapp settings</p>
</a>
<?php endif; ?>
<?php if (checkPermission('announcements','view')): ?>
<a href="/settings/announcements/announcement_list.php" class="settings-card">
    <div class="settings-icon" style="color:#ff6b6b;">
        <i class="fas fa-bullhorn"></i>
    </div>
    <h4>Announcements</h4>
    <p>Broadcast notices & updates</p>
</a>
<?php endif; ?>
<!-- BARCODE SETUP -->
<?php if (checkPermission('barcode_setup','view')): ?>
<a href="/settings/barcode/barcode_setup.php" class="settings-card">
    <div class="settings-icon" style="color:#228be6;">
        <i class="fas fa-barcode"></i>
    </div>
    <h4>Barcode Setup</h4>
    <p>Configure barcode settings & formats</p>
</a>
<?php endif; ?>
<!-- AUDIT LOGS -->
<?php if (checkPermission('audit_logs','view')): ?>
<a href="/settings/audit_logs/index.php" class="settings-card">
    <div class="settings-icon" style="color:#fd7e14;">
        <i class="fas fa-clipboard-list"></i>
    </div>
    <h4>Audit Logs</h4>
    <p>Track user activities & system changes</p>
</a>
<?php endif; ?>
<!-- ABDM SETTINGS -->
<?php if (checkPermission('abdm_settings','view')): ?>
<a href="/modules/abdm/settings/abdm_settings.php" class="settings-card">
    <div class="settings-icon" style="color:#12b886;">
        <i class="fas fa-heart-pulse"></i>
    </div>
    <h4>ABDM Settings</h4>
    <p>Configure ABDM integration & facility details</p>
</a>
<?php endif; ?>
<!-- PATHOLOGY -->
<?php if (checkPermission('pathology','view')): ?>
<a href="/modules/pathology/masters.php" class="settings-card">
    <div class="settings-icon" style="color:#e64980;">
        <i class="fas fa-microscope"></i>
    </div>
    <h4>Pathology</h4>
    <p>Manage pathology & laboratory services</p>
</a>
<?php endif; ?>
</div>
</div>
</body>
</html>