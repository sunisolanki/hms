<?php
// includes/sidebar.php
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* ===============================
   ACTIVE PATH HELPERS
   =============================== */
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Modified function to check exact or specific paths
function isActive(string $path): string
{
    global $currentPath;
    // Folder match (dashboard/, opd/, ipd/, registration/)
    if (substr($path, -1) === '/' && strpos($currentPath, $path) === 0) {
        return 'active';
    }
    // Exact file match
    if ($currentPath === $path) {
        return 'active';
    }
    return '';
}
// Modified to only mark parent as active when on exact parent pages
function isSubActive(array $paths): string
{
    global $currentPath;
    foreach ($paths as $p) {
        if (strpos($currentPath, $p) === 0) {
            return 'active open';
        }
    }
    return '';
}
// Additional helper function for exact matches
function isExactActive(string $path): string
{
    global $currentPath;
    return $currentPath === $path ? 'active' : '';
}
?>
<style>
    .menu li.active > a {
        background: #2c7be5;
        color: #fff;
    }
    .menu li.active > a .icon,
    .menu li.active > a .text {
        color: #fff;
    }
    .menu li.has-sub.open > .sub-menu {
        display: block;
    }
    /* Optional: Style for parent when open but not active */
    .menu li.has-sub.open > a {
        background: #364256;
        color: #ffff;
    }
    .menu li.has-sub.open > a .icon,
    .menu li.has-sub.open > a .text {
        color: #ffff;
    }
    .menu li.has-sub.open > .sub-menu {
    display: block;
}
.menu li.has-sub > .sub-menu {
    display: none;
}
.menu li.has-sub.open .arrow {
    transform: rotate(90deg);
}
.arrow {
    transition: transform 0.2s ease;
}
.sidebar.collapsed .text {
    display: none;
}
</style>
<div class="sidebar" id="sidebar">
    <div class="sidebar-toggle" onclick="toggleSidebar()">☰</div>
    <ul class="menu">
        <!-- DASHBOARD -->
        <?php if (checkPermission('dashboard','view')): ?>
        <li class="<?= isExactActive('/dashboard/index.php') ?>">
            <a href="/dashboard/index.php"><span class="icon">🏠</span><span class="text">Dashboard</span></a>
        </li>
        <?php endif; ?>
        <!-- APPOINTMENT -->
        <?php if (checkPermission('appointments','view')): ?>
        <li class="has-sub <?= isSubActive([
            '/modules/appointments/appointment.php',
            '/modules/appointments/appointment_list.php'
        ]) ?>">
            <a href="javascript:void(0)" onclick="toggleSub(this)">
                <span class="icon">📅</span>
                <span class="text">Appointment</span>
                <span class="arrow">▸</span>
            </a>
            <ul class="sub-menu">
    <li class="<?= isExactActive('/modules/appointments/appointment.php') ?>">
        <a href="/modules/appointments/appointment.php">
            <span class="icon">➕</span>
            <span class="text">Add Appointment</span>
        </a>
    </li>
    <li class="<?= isExactActive('/modules/appointments/appointment_list.php') ?>">
        <a href="/modules/appointments/appointment_list.php">
            <span class="icon">📋</span>
            <span class="text">Appointment List</span>
        </a>
    </li>
</ul>
        </li>
        <?php endif; ?>
        <!-- REGISTRATION  -->
       <?php if (checkPermission('patient_registration','view')): ?>
<li class="<?= isActive('/modules/registration/') ?>">
    <a href="/modules/registration/registration_list.php">
        <span class="icon">📝</span>
        <span class="text">Registration</span>
    </a>
</li>
<?php endif; ?>
     <!-- OPD -->
<?php if (checkPermission('opd','view')): ?>
<li class="<?= isExactActive('/modules/opd/opd_list.php') ?>">
    <a href="/modules/opd/opd_list.php">
        <span class="icon">🩺</span>
        <span class="text">OPD</span>
    </a>
</li>
<?php endif; ?>
      <!-- IPD -->
<?php if (checkPermission('ipd','view')): ?>
<li class="has-sub <?= isSubActive([
    '/modules/ipd/ipd_list.php',
    '/modules/ipd/ip_prescription.php',
    '/modules/ipd/patient_finder.php',
    '/modules/ipd/discharge/discharge_list.php'
]) ?>">
    <a href="javascript:void(0)" onclick="toggleSub(this)">
        <span class="icon">🏥</span>
        <span class="text">IPD</span>
        <span class="arrow">▸</span>
    </a>

    <ul class="sub-menu">

        <li class="<?= isExactActive('/modules/ipd/ipd_list.php') ?>">
            <a href="/modules/ipd/ipd_list.php">
                <span class="icon">📋</span>
                <span class="text">IPD List</span>
            </a>
        </li>

        <?php if (checkPermission('ip_prescription','view')): ?>
        <li class="<?= isExactActive('/modules/ipd/ip_prescription.php') ?>">
            <a href="/modules/ipd/ip_prescription.php">
                <span class="icon">💊</span>
                <span class="text">IP Prescription</span>
            </a>
        </li>
        <?php endif; ?>

        <li class="<?= isExactActive('/modules/ipd/patient_finder.php') ?>">
            <a href="/modules/ipd/patient_finder.php">
                <span class="icon">🔍</span>
                <span class="text">Patient Finder</span>
            </a>
        </li>

        <li class="<?= isExactActive('/modules/ipd/discharge/discharge_list.php') ?>">
            <a href="/modules/ipd/discharge/discharge_list.php">
                <span class="icon">📄</span>
                <span class="text">Discharge Summary</span>
            </a>
        </li>

    </ul>
</li>
<?php endif; ?>
<!-- Billing -->
<?php if (checkPermission('billing','view')): ?>
<li class="has-sub <?= isSubActive(['/modules/billing/']) ?>">
    <a href="javascript:void(0)" onclick="toggleSub(this)">
        <span class="icon">💰</span>
        <span class="text">Billing</span>
        <span class="arrow">▸</span>
    </a>
    <ul class="sub-menu">
     <?php if (checkPermission('opd_billing','view')): ?>
<li class="<?= isExactActive('/modules/billing/opbilling/opd_bill.php') ?>">
    <a href="/modules/billing/opbilling/opd_bill.php">
        OPD Billing
    </a>
</li>
<li class="<?= isExactActive('/modules/billing/opbilling/opd_receipts.php') ?>">
    <a href="/modules/billing/opbilling/opd_receipts.php">
        OPD Receipts
    </a>
</li>
<li class="<?= isExactActive('/modules/billing/opbilling/opd_invoice/index.php') ?>">
    <a href="/modules/billing/opbilling/opd_invoice/index.php">
        OPD Invoice
    </a>
</li>
<?php endif; ?>
<?php if (checkPermission('ipd_billing','view')): ?>
<li class="<?= isExactActive('/modules/billing/ipdbilling/ipd_bill.php') ?>">
    <a href="/modules/billing/ipdbilling/ipd_bill.php">
        IPD Billing
    </a>
</li>
<li class="<?= isExactActive('/modules/billing/ipdbilling/ipd_receipts.php') ?>">
    <a href="/modules/billing/ipdbilling/ipd_receipts.php">
        IPD Receipts
    </a>
</li>
<li class="<?= isExactActive('/modules/billing/ipdbilling/ipd_invoice/index.php') ?>">
    <a href="/modules/billing/ipdbilling/ipd_invoice/index.php">
        IPD Invoice
    </a>
</li>
<?php endif; ?>
       <!--------------------
        <li class="<?= isExactActive('/modules/billing/payment_summary.php') ?>">
            <a href="/modules/billing/payment_summary.php">
                Payment Summary
            </a>
        </li>
        ------------------->
    </ul>
</li>
<?php endif; ?>
<!-- Pharmacy -->  
<?php if (checkPermission('pharmacy','view')): ?>  
<li class="has-sub <?= isSubActive(['/reports/pharmacy/', '/modules/pharmacy/']) ?>">  
    <a href="javascript:void(0)" onclick="toggleSub(this)">  
        <span class="icon">💊</span>  
        <span class="text">Pharmacy</span>  
        <span class="arrow">▸</span>  
    </a>  
    <ul class="sub-menu">  

        <!-- Sale Bill --> 
        <li class="<?= isExactActive('/modules/pharmacy/pharmacy_sales.php') ?>">  
            <a href="/modules/pharmacy/pharmacy_sales.php"> 
                <span class="icon">🧾</span> Sale Bill 
            </a>  
        </li>  

        <!-- Sales Report
        <li class="<?= isExactActive('/reports/pharmacy/sales_report.php') ?>">  
            <a href="/reports/pharmacy/sales_report.php"> 
                <span class="icon">📊</span> Sales Report 
            </a>  
        </li>  
        -------------- --> 

        <!-- Stock Report
        <li class="<?= isExactActive('/reports/pharmacy/stock_report.php') ?>">  
            <a href="/reports/pharmacy/stock_report.php"> 
                <span class="icon">📦</span> Stock Report 
            </a>  
        </li>  
  ---------------- --> 
        <!-- Expiry Report  
        <li class="<?= isExactActive('/reports/pharmacy/expiry_report.php') ?>">  
            <a href="/reports/pharmacy/expiry_report.php"> 
                <span class="icon">⏳</span> Expiry Report 
            </a>  
        </li>  
   -------------------------------->
    </ul>  
</li>  
<?php endif; ?>
<!-- INVENTORY -->
<?php if (checkPermission('inventory','view')): ?>
<li class="has-sub <?= isSubActive([
    '/modules/inventory/material_receipt/',
    '/modules/inventory/live_stock/'
]) ?>">
    <a href="javascript:void(0)"
    onclick="toggleSub(this)">
        <span class="icon">📦</span>
        <span class="text">Inventory</span>
        <span class="arrow">▸</span>
    </a>
    <ul class="sub-menu">
        <!-- MATERIAL RECEIPT -->
        <li class="<?= isExactActive('/modules/inventory/material_receipt/index.php') ?>">
            <a href="/modules/inventory/material_receipt/index.php">
                <span class="icon">📥</span>
                <span class="text">
                    Material Receipt
                </span>
            </a>
        </li>
        <!-- LIVE STOCK -->
        <li class="<?= isExactActive('/modules/inventory/live_stock/index.php') ?>">
            <a href="/modules/inventory/live_stock/index.php">
                <span class="icon">📦</span>
                <span class="text">
                    Live Stock
                </span>
            </a>
        </li>
    </ul>
</li>
<?php endif; ?>
<!-- CERTIFICATES -->
<?php if (checkPermission('certificates','view')): ?>
<li class="has-sub <?= isSubActive([
    '/modules/certificates/NewBorn/newborn_certificate.php',
    '/modules/certificates/Medical/medical_certificate.php',
    '/modules/certificates/Death/death_certificate.php'
]) ?>">
    <a href="javascript:void(0)" onclick="toggleSub(this)">
        <span class="icon">📜</span>
        <span class="text">Certificates</span>
        <span class="arrow">▸</span>
    </a>

    <ul class="sub-menu">
        <?php if(checkPermission('newborn_certificate','view')): ?>
<li class="<?= isExactActive('/modules/certificates/NewBorn/newborn_certificate.php') ?>">
    <a href="/modules/certificates/NewBorn/newborn_certificate.php">
        <span class="icon">👶</span>
        <span class="text">New Born Certificate</span>
    </a>
</li>
<?php endif; ?>

        <?php if(checkPermission('medical_certificate','view')): ?>
<li class="<?= isExactActive('/modules/certificates/Medical/medical_certificate.php') ?>">
    <a href="/modules/certificates/Medical/medical_certificate.php">
        <span class="icon">🩺</span>
        <span class="text">Medical Certificate</span>
    </a>
</li>
<?php endif; ?>

        <?php if(checkPermission('death_certificate','view')): ?>
<li class="<?= isExactActive('/modules/certificates/Death/death_certificate.php') ?>">
    <a href="/modules/certificates/Death/death_certificate.php">
        <span class="icon">⚰️</span>
        <span class="text">Death Certificate</span>
    </a>
</li>
<?php endif; ?>
    </ul>
</li>
<?php endif; ?>
    <!-- Docs -->
        <?php if (checkPermission('docs','view')): ?>
        <li class="has-sub <?= isSubActive([
            '/modules/docs/service_list.php',
            '/masters/rooms/bed_dashboard.php',
            '/modules/docs/doctor_schedule_list.php',
            '/modules/docs/doctor_charges_list.php'
        ]) ?>">
            <a href="javascript:void(0)" onclick="toggleSub(this)">
                <span class="icon">📅</span>
                <span class="text">List / Downloads</span>
                <span class="arrow">▸</span>
            </a>
            <ul class="sub-menu">
        <li class="<?= isExactActive('/modules/docs/service_list.php') ?>">
        <a href="/modules/docs/service_list.php">
            <span class="icon">📋</span>
            <span class="text">Service List</span>
        </a>
    </li>
    <li class="<?= isExactActive('/masters/rooms/bed_dashboard.php') ?>">
            <a href="/masters/rooms/bed_dashboard.php">
                <span class="icon">🛏️</span>
                <span class="text">Bed Dashboard</span>
            </a>
        </li>
        <li class="<?= isExactActive('/modules/docs/doctor_schedule_list.php') ?>">
    <a href="/modules/docs/doctor_schedule_list.php">
        <span class="icon">👨‍⚕️</span>
        <span class="text">Dr Schedule</span>
    </a>
</li>
<li class="<?= isExactActive('/modules/docs/doctor_charges_list.php') ?>">
    <a href="/modules/docs/doctor_charges_list.php">
        <span class="icon">💵</span>
        <span class="text">Doctor Charges</span>
    </a>
</li>
</ul>
        </li>
        <?php endif; ?>
   <!-- Pathology  -------------------------------------- --> 
<?php if (checkPermission('pathology','view')): ?>
<li class="has-sub <?= isSubActive(['/modules/pathology/']) ?>">
    <a href="javascript:void(0)" onclick="toggleSub(this)">
        <span class="icon">🧪</span>
        <span class="text">Pathology</span>
        <span class="arrow">▸</span>
    </a>
    <ul class="sub-menu">
        <li class="<?= isExactActive('/modules/pathology/sample_collection.php') ?>">
            <a href="/modules/pathology/sample_collection.php">Sample Collection</a>
        </li>
    </ul>
</li>
<?php endif; ?>  
<!-- Radiology
<?php if (checkPermission('radiology','view')): ?>
<li class="has-sub <?= isSubActive(['/reports/radiology/']) ?>">
    <a href="javascript:void(0)" onclick="toggleSub(this)">
        <span class="icon">🩻</span>
        <span class="text">Radiology</span>
        <span class="arrow">▸</span>
    </a>
    <ul class="sub-menu">
        <li class="<?= isExactActive('/reports/radiology/investigation_wise.php') ?>">
            <a href="/reports/radiology/investigation_wise.php">Investigation Wise</a>
        </li>
        <li class="<?= isExactActive('/reports/radiology/date_wise.php') ?>">
            <a href="/reports/radiology/date_wise.php">Date Wise</a>
        </li>
    </ul>
</li>
------------------------------------------ -->  
<?php endif; ?>
    <!-- Blood Bank 
<?php if (checkPermission('blood_bank','view')): ?>
<li class="has-sub <?= isSubActive(['/blood_bank/']) ?>">
    <a href="javascript:void(0)" onclick="toggleSub(this)">
        <span class="icon">🩸</span>
        <span class="text">Blood Bank</span>
        <span class="arrow">▸</span>
    </a>
    <ul class="sub-menu">
        <li class="<?= isExactActive('/blood_bank/donor_list.php') ?>">
            <a href="/blood_bank/donor_list.php">
                <span class="icon">👤</span>
                <span class="text">Donor List</span>
            </a>
        </li>
        <li class="<?= isExactActive('/blood_bank/blood_collection.php') ?>">
            <a href="/blood_bank/blood_collection.php">
                <span class="icon">🧪</span>
                <span class="text">Blood Collection</span>
            </a>
        </li>
        <li class="<?= isExactActive('/blood_bank/blood_stock.php') ?>">
            <a href="/blood_bank/blood_stock.php">
                <span class="icon">🏦</span>
                <span class="text">Blood Stock</span>
            </a>
        </li>
        <li class="<?= isExactActive('/blood_bank/blood_issue.php') ?>">
            <a href="/blood_bank/blood_issue.php">
                <span class="icon">📤</span>
                <span class="text">Blood Issue</span>
            </a>
        </li>
        <li class="<?= isExactActive('/blood_bank/expiry_report.php') ?>">
            <a href="/blood_bank/expiry_report.php">
                <span class="icon">⏳</span>
                <span class="text">Expiry Report</span>
            </a>
        </li>
    </ul>
</li>
-------------------------------------------------------------------   -->
<?php endif; ?>
    <!-- REPORTS -->
<?php if (checkPermission('reports','view')): ?>
<li class="has-sub <?= isSubActive([
    '/reports/'
]) ?>">
    <a href="javascript:void(0)" onclick="toggleSub(this)">
        <span class="icon">📊</span>
        <span class="text">Reports</span>
        <span class="arrow">▸</span>
    </a>
    <ul class="sub-menu">
        <?php if (checkPermission('appointment_report','view')): ?>
        <li class="<?= isExactActive('/reports/appointment/index.php') ?>">
            <a href="/reports/appointment/index.php">
                <span class="icon">📅</span>
                <span class="text">Appointment Reports</span>
            </a>
        </li>
        <?php endif; ?>
        <ul class="sub-menu">
        <?php if (checkPermission('appointment_report','view')): ?>
        <li class="<?= isExactActive('/reports/appointment/list.php') ?>">
            <a href="/reports/appointment/list.php">
                <span class="icon">📝</span>
                <span class="text">Appointment List</span>
            </a>
        </li>
        
        <?php if (checkPermission('ledger_report','view')): ?>
<li class="<?= isExactActive('/reports/ledger_report.php') ?>">
    <a href="/reports/ledger_report.php">
        <span class="icon">📒</span>
        <span class="text">Ledger Report</span>
    </a>
</li>
<?php endif; ?>
   
   <!-----------------
        
        <?php endif; ?>
        <?php if (checkPermission('registration_report','view')): ?>
        <li class="<?= isExactActive('/reports/registration_report.php') ?>">
            <a href="/reports/registration_report.php">
                <span class="icon">📝</span>
                <span class="text">Registration Reports</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (checkPermission('opd_report','view')): ?>
        <li class="<?= isExactActive('/reports/opd_report.php') ?>">
            <a href="/reports/opd_report.php">
                <span class="icon">🩺</span>
                <span class="text">OPD Reports</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (checkPermission('ipd_report','view')): ?>
        <li class="<?= isExactActive('/reports/ipd_report.php') ?>">
            <a href="/reports/ipd_report.php">
                <span class="icon">🏥</span>
                <span class="text">IPD Reports</span>
            </a>
        </li>
        <?php endif; ?>
    </ul>
</li>
-------------------->
<?php endif; ?>


 
 
 
        <!-- SETTINGS 
        <?php if (checkPermission('settings','view')): ?>
        <li class="has-sub <?= isSubActive([
            '/settings/',
            '/masters/hospital/',
            '/masters/branch/'
        ]) ?>">
            <a href="javascript:void(0)" onclick="toggleSub(this)">
                <span class="icon">⚙️</span>
                <span class="text">Settings</span>
                <span class="arrow">▸</span>
            </a>
            <ul class="sub-menu">
                <?php if (checkPermission('rights','view')): ?>
                <li class="<?= isExactActive('/settings/rights/rights.php') ?>">
                    <a href="/settings/rights/rights.php">Rights & Permissions</a>
                </li>
                <?php endif; ?>

                <?php if (checkPermission('hospital_master','view')): ?>
                <li class="<?= isExactActive('/masters/hospital/hospital_list.php') ?>">
                    <a href="/masters/hospital/hospital_list.php">Hospital Master</a>
                </li>
                <?php endif; ?>
                <?php if (checkPermission('branch_master','view')): ?>
                <li class="<?= isExactActive('/masters/branch/branch_list.php') ?>">
                    <a href="/masters/branch/branch_list.php">Branch Master</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>
 </ul>
 -->
</div>
<script>
function toggleSub(anchor) {
    const li = anchor.closest('li.has-sub');
    if (!li) return;
    // close other open menus (optional but recommended)
    document.querySelectorAll('.menu li.has-sub.open').forEach(item => {
        if (item !== li) {
            item.classList.remove('open');
        }
    });
    // toggle current
    li.classList.toggle('open');
}
</script>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
</script>