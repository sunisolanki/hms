<?php
session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

/* LOGIN CHECK */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

/* MASTERS VIEW PERMISSION */
if (!checkPermission('masters','view')) {
    header('Location: /access_denied.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Laboratory Master - WebSplend HMS</title>
    <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
    <!-- FONT AWESOME -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.container {
    padding-left: 25px;
    padding-right: 25px;
}
/* TOP BAR */
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
/* GRID */
.masters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-top: 30px;
}

/* CARD */
.master-card {
    background: linear-gradient(145deg, #ffffff, #f5f9ff);
    border-radius: 12px;
    padding: 25px 20px;
    text-align: center;
    box-shadow: 0 4px 12px rgb(26 142 210);
    transition: all 0.25s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

/* HOVER */
.master-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}

/* ICON */
.master-icon {
    font-size: 34px;
    margin-bottom: 12px;
}

/* TITLE */
.master-card h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

/* DESCRIPTION */
.master-card p {
    margin-top: 8px;
    font-size: 13px;
    color: #666;
}

/* LINK */
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
        <a href="/masters/laboratory/laboratory_master.php">Laboratory Master</a>
    </div>
    <h2>Laboratory Master</h2>
</div>
<div class="container">
    <div class="masters-grid">
        <!-- PARAMETER TAGGING -->
        <div class="master-card">
            <a href="/masters/laboratory/parameter_tagging.php">
                <div class="master-icon" style="color:#e03131;">
                    <i class="fas fa-link"></i>
                </div>
                <h3>Parameter Tagging</h3>
                <p>Manage parameter tagging setup</p>
                
            </a>
        </div>
        <!-- PARAMETERS -->
        <div class="master-card">
            <a href="/masters/laboratory/parameters.php">
                <div class="master-icon" style="color:#1971c2;">
                    <i class="fas fa-vials"></i>
                </div>
                <h3>Parameters</h3>
                <p>Create and manage lab parameters</p>
            </a>
        </div>
        <!-- TEMPLATE -->
        <div class="master-card">
            <a href="/masters/laboratory/template.php">
                <div class="master-icon" style="color:#5f3dc4;">
                    <i class="fas fa-file-medical"></i>
                </div>
                <h3>Template</h3>
                <p>Manage laboratory report templates</p>
            </a>
        </div>
        <!-- CONTAINER -->
        <div class="master-card">
            <a href="/masters/laboratory/container.php">
                <div class="master-icon" style="color:#0ca678;">
                    <i class="fas fa-box-open"></i>
                </div>
                <h3>Container</h3>
                <p>Manage sample containers</p>
            </a>
        </div>
        <!-- SPECIMEN -->
        <div class="master-card">
            <a href="/masters/laboratory/specimen.php">
                <div class="master-icon" style="color:#d6336c;">
                    <i class="fas fa-tint"></i>
                </div>
                <h3>Specimen</h3>
                <p>Manage specimen types</p>
            </a>
        </div>
        <!-- METHOD -->
        <div class="master-card">
            <a href="/masters/laboratory/method.php">
                <div class="master-icon" style="color:#f08c00;">
                    <i class="fas fa-flask"></i>
                </div>
                <h3>Method</h3>
                <p>Manage laboratory methods</p>
            </a>
        </div>
        <!-- MACHINE -->
        <div class="master-card">
            <a href="/masters/laboratory/machine.php">
                <div class="master-icon" style="color:#495057;">
                    <i class="fas fa-microscope"></i>
                </div>
                <h3>Machine</h3>
                <p>Manage laboratory machines</p>
            </a>
        </div>
        <!-- MACHINE CONFIG -->
        <div class="master-card">
            <a href="/masters/laboratory/machine_config.php">
                <div class="master-icon" style="color:#1c7ed6;">
                    <i class="fas fa-cogs"></i>
                </div>
                <h3>Machine Config</h3>
                <p>Machine configuration settings</p>
            </a>
        </div>
    </div>
</div>
</body>
</html>