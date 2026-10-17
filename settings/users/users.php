<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* RIGHTS PAGE VIEW PERMISSION */
if (
    !checkPermission('users', 'view') &&
    !checkPermission('rights', 'view')
) {
    header('Location: /access_denied.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Rights Management</title>

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">

<!-- FONT AWESOME -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===== TOP BAR FIX ===== */
.top-bar h2 {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
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
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a>User Management</a>
    </div>
    <h2>User Rights Management</h2>
</div>
<div class="container settings-wrapper">
    <div class="settings-grid">
        <!-- MANAGE USER -->
        <?php if (checkPermission('users', 'view')): ?>
        <a href="/settings/users/user_list.php" class="settings-card">
            <div class="settings-icon" style="color:#20c997;">
                <i class="fas fa-users-cog"></i>
            </div>
            <h4>Manage User</h4>
            <p>
                Create users and assign permissions
            </p>
        </a>
        <?php endif; ?>
        <!-- RIGHTS TYPE -->
        <?php if (checkPermission('rights', 'view')): ?>
        <a href="/settings/rights/rights.php" class="settings-card">
            <div class="settings-icon" style="color:#845ef7;">
                <i class="fas fa-user-shield"></i>
            </div>
            <h4>Rights Type</h4>
            <p>
                Configure role based access rights
            </p>
        </a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>