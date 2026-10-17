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
    <title>Masters - WebSplend HMS</title>
    <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
    <!-- FONT AWESOME (FOR ICONS) -->
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
/* GRID */
.masters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-top: 3px;
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
/* KEEPING YOUR HOVER EXACTLY SAME */
.master-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}
/* ICON STYLE */
.master-icon {
    font-size: 34px;
    margin-bottom: 12px;
}
/* TEXT */
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
        <a>Masters</a>
    </div> 
    <h2>Masters</h2>
</div> 
<div class="container">
    <div class="masters-grid">
        <?php if (checkPermission('territory_master','view')): ?>
<div class="master-card">
    <a href="/masters/territory_master.php">
        <div class="master-icon" style="color:#ff6b6b;">
            <i class="fas fa-map-marked-alt"></i>
        </div>
        <h3>City Master</h3>
        <p>Manage Cities & regions</p>
    </a>
</div>
<?php endif; ?>
<?php if (checkPermission('hospital_master','view')): ?>
        <div class="master-card">
            <a href="/masters/hospital/hospital_list.php">
                <div class="master-icon" style="color:#4dabf7;"><i class="fas fa-hospital"></i></div>
                <h3>Hospital Master</h3>
                <p>Hospital</p>
            </a>
          </div>
<?php endif; ?>
<?php if (checkPermission('branch_master','view')): ?>
        <div class="master-card">
            <a href="/masters/branch/branch_list.php">
                <div class="master-icon" style="color:#20c997;"><i class="fas fa-code-branch"></i></div>
                <h3>Branch Master</h3>
                <p>Manage Multiple Branches</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('department_master','view')): ?>
        <div class="master-card">
            <a href="/masters/department/department_list.php">
                <div class="master-icon" style="color:#845ef7;"><i class="fas fa-sitemap"></i></div>
                <h3>Department</h3>
                <p>Medical & Admin Departments</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('designation_master','view')): ?>
        <div class="master-card">
            <a href="/masters/designation/designation_master.php">
                <div class="master-icon" style="color:#f59f00;"><i class="fas fa-user-tie"></i></div>
                <h3>Designation</h3>
                <p>Roles & Titles</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('specialization_master','view')): ?>
        <div class="master-card">
            <a href="/masters/specialization/specialization_master.php">
                <div class="master-icon" style="color:#e64980;"><i class="fas fa-stethoscope"></i></div>
                <h3>Specialization</h3>
                <p>Manage Specialization</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('qualification_master','view')): ?>
        <div class="master-card">
            <a href="/masters/qualification/qualification_master.php">
                <div class="master-icon" style="color:#228be6;"><i class="fas fa-graduation-cap"></i></div>
                <h3>Qualification</h3>
                <p>Qualification of Dr. and Emp.</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('employee_type_master','view')): ?>
        <div class="master-card">
            <a href="/masters/employee_type/employee_type_master.php">
                <div class="master-icon" style="color:#40c057;"><i class="fas fa-users"></i></div>
                <h3>Employee Type</h3>
                <p>Employee Type / Sub Type Master</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('doctor_master','view')): ?>
        <div class="master-card">
            <a href="/masters/doctor/doctor_list.php">
                <div class="master-icon" style="color:#fa5252;"><i class="fas fa-user-md"></i></div>
                <h3>Doctor Master</h3>
                <p>Doctor Settings</p>
            </a>
        </div>
<?php endif; ?>
      <!--  <div class="master-card">
            <a href="/masters/employee/employee_list.php">
                <div class="master-icon" style="color:#7950f2;"><i class="fas fa-id-badge"></i></div>
                <h3>Employee Master</h3>
                <p>Employee Details for HRD</p>
            </a>
        </div> -->
<?php if (checkPermission('service_master','view')): ?>
        <div class="master-card">
            <a href="/masters/service/service_master.php">
                <div class="master-icon" style="color:#12b886;"><i class="fas fa-vials"></i></div>
                <h3>Service Master</h3>
                <p>Path Lab and Radiology Services</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('empanelment_master','view')): ?>
        <div class="master-card">
            <a href="/masters/emph/index.php">
                <div class="master-icon" style="color:#fd7e14;"><i class="fas fa-handshake"></i></div>
                <h3>Emphanelment Master</h3>
                <p>Emphanelment Charges</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('payment_gateway','view')): ?>
        <div class="master-card">
            <a href="/payments/payment_gateway_config.php">
                <div class="master-icon" style="color:#15aabf;"><i class="fas fa-credit-card"></i></div>
                <h3>Payment Gateway</h3>
                <p>Payment Gateway Configuration</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('referral_master','view')): ?>
        <div class="master-card">
            <a href="/masters/referral/Referral_master.php">
                <div class="master-icon" style="color:#be4bdb;"><i class="fas fa-share-alt"></i></div>
                <h3>Referral Master</h3>
                <p>Referrals Details</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('discount_master','view')): ?>
        <div class="master-card">
            <a href="/masters/discount/discount_master.php">
                <div class="master-icon" style="color:#fab005;"><i class="fas fa-tags"></i></div>
                <h3>Discount Type Master</h3>
                <p>Can create multiple type of Discounts</p>
            </a>
        </div>
<?php endif; ?>
<?php if (checkPermission('room_master','view')): ?>
        <div class="master-card">
            <a href="/masters/rooms/room_master.php">
                <div class="master-icon" style="color:#2f9e44;"><i class="fas fa-bed"></i></div>
                <h3>Room Master</h3>
                <p>building Room and Wards can create</p>
            </a>
        </div>
<?php endif; ?>

<?php if (checkPermission('disease_master','view')): ?>
<div class="master-card">
    <a href="/masters/disease/disease_master.php">
        <div class="master-icon" style="color:#e03131;"><i class="fas fa-virus"></i></div>
        <h3>Disease Master</h3>
        <p>Manage Diseases & Diagnosis</p>
    </a>
</div>
<?php endif; ?>
<?php if (checkPermission('parameter_master','view')): ?>
<div class="master-card">
    <a href="/masters/parameter/parameter_master.php">
        <div class="master-icon" style="color:#7048e8;"><i class="fas fa-sliders-h"></i></div>
        <h3>Parameter Master</h3>
        <p>Manage Parameters & Reference Ranges</p>
    </a>
</div>
<?php endif; ?>
<?php if (checkPermission('charges_type_master','view')): ?>
<div class="master-card">
    <a href="/masters/charges/charges_type_master.php">
        <div class="master-icon" style="color:#f08c00;">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <h3>Charges Type Master</h3>
        <p>Manage Hospital Charges</p>
    </a>
</div>
<?php endif; ?>
    </div>
</div>
</body>
</html>