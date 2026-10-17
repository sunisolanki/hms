<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/abdm_helper.php';

if (!checkPermission('abdm_settings', 'view')) {
    header('Location: /access_denied.php');
    exit;
}

/*
 * GLOBAL ABDM ON/OFF SWITCH
 *
 * Read server-side. This page deliberately stays reachable while ABDM is OFF so
 * that an authorised administrator can always turn ABDM back ON.
 */
$abdmSwitch      = abdmGetGlobalSwitch($conn);
$abdmCanEdit     = checkPermission('abdm_settings', 'update');
$abdmSwitchOn    = (bool)$abdmSwitch['enabled'];
$abdmSwitchError = ($abdmSwitch['state'] === 'error');

/* Established session CSRF token, same pattern as index.php. */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* Flash messages, using the application's existing session pattern. */
$abdmFlashSuccess = $_SESSION['success'] ?? '';
$abdmFlashError   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ABDM Settings - WebSplend HMS</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
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
.abdm-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
}
.abdm-settings-card {
    background: linear-gradient(145deg, #ffffff, #f5f9ff);
    padding: 28px 20px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 4px 12px rgb(26 142 210);
    text-decoration: none;
    color: #333;
    transition: transform .25s ease, box-shadow .25s ease;
    cursor: pointer;
}
.abdm-settings-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 18px 40px rgba(0,0,0,0.15);
}
.abdm-settings-icon {
    font-size: 38px;
    margin-bottom: 15px;
}
.abdm-settings-card h4 {
    margin-bottom: 8px;
    font-size: 17px;
    font-weight: 600;
    color: #333;
}
.abdm-settings-card p {
    margin: 0;
    font-size: 13px;
    color: #666;
}
.abdm-section {
    margin-top: 10px;
    background: #fff;
    border-radius: 12px;
    padding: 10px;
    box-shadow: 0 4px 12px rgba(26,142,210,.15);
}
.abdm-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 5px;
}
.abdm-section-header i {
    color: #228be6;
    font-size: 20px;
}
.abdm-section-header h3 {
    margin: 0;
    font-size: 18px;
}
.abdm-section-note {
    margin: 0 0 18px;
    font-size: 12px;
    color: #777;
}
.abdm-table-wrap {
    width: 100%;
    
}
.abdm-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}
.abdm-table th {
    background: #f1f5f9;
    color: #333;
    font-size: 12px;
    font-weight: 700;
    text-align: left;
    padding: 11px 12px;
    border-bottom: 2px solid #dee2e6;
}
.abdm-table td {
    padding: 5px 12px;
    border-bottom: 1px solid #edf0f2;
    font-size: 12px;
    vertical-align: middle;
}
.abdm-table tr:last-child td {
    border-bottom: 0;
}
.abdm-milestone {
    width: 70px;
    text-align: center;
    font-weight: 700;
}
.m1-color {
    color: #12b886;
}
.m2-color {
    color: #845ef7;
}
.m3-color {
    color: #228be6;
}
.m4-color {
    color: #e64980;
}
.abdm-purpose {
    font-weight: 600;
    color: #333;
}
.abdm-url {
    display: inline-block;
    padding: 5px 8px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 5px;
    color: #1864ab;
    text-decoration: none;
    word-break: break-all;
    font-family: monospace;
    font-size: 11px;
}
.abdm-url:hover {
    background: #e7f5ff;
}
.abdm-no-url {
    color: #868e96;
    font-size: 11px;
    font-style: italic;
}
.abdm-portal-section {
    margin-top: 30px;
}
.abdm-portal-badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}
.abdm-test {
    background: #e7f5ff;
    color: #1971c2;
}
.abdm-production {
    background: #e6fcf5;
    color: #087f5b;
}
.id-reference-section{
    margin-top:12px;
    padding-top:10px;
    border-top:1px solid #eee;
}
.id-reference-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:7px;
}
.id-reference-title h4{
    margin:0;
    font-size:13px;
    color:#333;
}
.id-reference-table-wrap{
    overflow:hidden;
    border:1px solid #e2e6ea;
    border-radius:7px;
}
.id-reference-table{
    width:100%;
    border-collapse:collapse;
    font-size:11px;
}
.id-reference-table th{
    background:#f5f7fa;
    color:#333;
    font-weight:700;
    padding:7px 8px;
    text-align:left;
    border-bottom:1px solid #e2e6ea;
}
.id-reference-table td{
    padding:7px 8px;
    border-bottom:1px solid #edf0f2;
    vertical-align:middle;
    color:#555;
    line-height:1.35;
}
.id-reference-table tr:last-child td{
    border-bottom:0;
}
.id-reference-table .term{
    font-weight:700;
    color:#222;
    white-space:nowrap;
}
.id-reference-table .example{
    font-family:monospace;
    background:#f1f3f5;
    padding:3px 6px;
    border-radius:4px;
    color:#333;
    white-space:nowrap;
}
.id-reference-link{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:4px 7px;
    border-radius:4px;
    background:#e7f5ff;
    color:#1971c2;
    text-decoration:none;
    font-weight:600;
    white-space:nowrap;
}
.id-reference-link:hover{
    background:#d0ebff;
}
.m2-infographic-section{
    margin-top:12px;
    padding-top:10px;
    border-top:1px solid #eee;
}
.m2-infographic-title{
    margin-bottom:7px;
}
.m2-infographic-title h4{
    margin:0;
    font-size:13px;
    color:#333;
}
.m2-infographic{
    width:100%;
    border:1px solid #e2e6ea;
    border-radius:7px;
    background:#fff;
    overflow:hidden;
    text-align:center;
}
.m2-infographic img{
    display:block;
    width:100%;
    height:auto;
    object-fit:contain;
}
.abdm-global-panel{
    margin-bottom:20px;
    background:#fff;
    border-left:5px solid #12b886;
    border-radius:12px;
    padding:20px 24px;
    box-shadow:0 4px 12px rgba(18,184,134,.15);
}
.abdm-global-panel.is-off{ border-left-color:#e64980; }
.abdm-global-panel.is-error{ border-left-color:#fd7e14; }
.abdm-global-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:10px;
}
.abdm-global-head h3{
    margin:0;
    font-size:19px;
    display:flex;
    align-items:center;
    gap:9px;
}
.abdm-global-state{
    display:inline-flex;
    align-items:center;
    gap:7px;
    font-size:13px;
    font-weight:700;
    padding:6px 15px;
    border-radius:20px;
}
.abdm-global-state.on{ background:#e6fcf5; color:#087f5b; }
.abdm-global-state.off{ background:#ffe3e3; color:#c92a2a; }
.abdm-global-state.error{ background:#fff4e6; color:#d9480f; }
.abdm-global-desc{
    margin:10px 0 16px;
    font-size:13px;
    color:#555;
    line-height:1.55;
}
.abdm-global-desc strong{ color:#222; }
.abdm-global-form{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
}
.abdm-global-form select,
.abdm-global-form button{
    padding:9px 16px;
    font-size:14px;
    border-radius:7px;
    border:1px solid #ced4da;
}
.abdm-global-form button{
    background:#228be6;
    color:#fff;
    border-color:#228be6;
    cursor:pointer;
    font-weight:600;
}
.abdm-global-form button:hover{ background:#1c7ed6; }
.abdm-global-note{
    margin:12px 0 0;
    font-size:12px;
    color:#777;
}
.abdm-alert{
    margin:0 0 16px;
    padding:12px 16px;
    border-radius:8px;
    font-size:13px;
    font-weight:600;
}
.abdm-alert.success{ background:#e6fcf5; color:#087f5b; border:1px solid #69db7c; }
.abdm-alert.error{ background:#fff5f5; color:#c92a2a; border:1px solid #ffa8a8; }
@media(max-width:700px) {
    .top-bar h2 {
        position: static;
        transform: none;
        text-align: center;
        margin-top: 5px;
    }
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
        <a>ABDM Settings</a>
    </div>
    <h2>ABDM Settings</h2>
</div>

<div class="container settings-wrapper">

    <!-- FLASH MESSAGES -->
<?php if ($abdmFlashSuccess !== ''): ?>
    <div class="abdm-alert success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($abdmFlashSuccess, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>
<?php if ($abdmFlashError !== ''): ?>
    <div class="abdm-alert error">
        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($abdmFlashError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

    <!-- ================= GLOBAL ABDM ON/OFF SWITCH ================= -->
    <div class="abdm-global-panel<?php echo $abdmSwitchError ? ' is-error' : ($abdmSwitchOn ? '' : ' is-off'); ?>">
        <div class="abdm-global-head">
            <h3>
                <i class="fas fa-power-off" style="color:<?php echo $abdmSwitchOn ? '#12b886' : '#e64980'; ?>;"></i>
                Global ABDM
            </h3>
<?php if ($abdmSwitchError): ?>
            <span class="abdm-global-state error"><i class="fas fa-exclamation-triangle"></i> SETTING UNAVAILABLE</span>
<?php elseif ($abdmSwitchOn): ?>
            <span class="abdm-global-state on"><i class="fas fa-check-circle"></i> ON</span>
<?php else: ?>
            <span class="abdm-global-state off"><i class="fas fa-times-circle"></i> OFF</span>
<?php endif; ?>
        </div>

        <p class="abdm-global-desc">
            This switch controls ABDM for the <strong>whole installation</strong>. The setting belongs to
            this client's own database, so one client installation changing it has no effect on any other.
        </p>

<?php if ($abdmSwitchError): ?>
        <div class="abdm-alert error" style="margin-bottom:0;">
            <i class="fas fa-exclamation-triangle"></i>
            The Global ABDM setting could not be read, so ABDM is currently treated as
            <strong>disabled</strong> for this request. A sanitized diagnostic was written to the
            server error log. Ordinary registration, patient editing and prescription saving are
            unaffected. Check the database connection and the table
            <code>abdm_master_settings</code>, then reload this page.
        </div>
<?php elseif ($abdmSwitch['state'] === 'missing'): ?>
        <div class="abdm-alert success" style="margin-bottom:0;">
            <i class="fas fa-info-circle"></i>
            No stored value was found for this installation, so ABDM is running on its
            default of <strong>ON</strong> for backward compatibility. Saving from this form
            writes the stored value for the first time.
        </div>
<?php endif; ?>

<?php if ($abdmCanEdit): ?>
        <form method="post" action="/modules/abdm/settings/save_global_switch.php" class="abdm-global-form" style="margin-top:16px;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="abdm_enabled"><strong>ABDM Enabled</strong></label>
            <select name="abdm_enabled" id="abdm_enabled">
                <option value="on"<?php echo $abdmSwitchOn ? ' selected' : ''; ?>>ON</option>
                <option value="off"<?php echo $abdmSwitchOn ? '' : ' selected'; ?>>OFF</option>
            </select>
            <button type="submit"><i class="fas fa-save"></i> Save</button>
        </form>
        <p class="abdm-global-note">
            <strong>ON</strong> &mdash; the patient-level ABDM Yes/No choice is shown during registration and
            Edit Patient, and ABDM actions are permitted.<br>
            <strong>OFF</strong> &mdash; all ABDM controls are hidden and ABDM actions are refused on the server.
            Ordinary registration, the normal Aadhaar field, patient editing and prescription saving
            continue to work. Existing ABHA IDs and ABDM link records are <strong>kept and never deleted</strong>.
        </p>
<?php else: ?>
        <p class="abdm-global-note">
            You do not have the <code>abdm_settings</code> update right, so this setting is read-only for your role.
        </p>
<?php endif; ?>
    </div>

    <!-- M1-M4 SETTINGS -->
    <div class="abdm-settings-grid">

        <!-- M1 -->
        <a href="/modules/abdm/settings/m1_settings.php" class="abdm-settings-card">
            <div class="abdm-settings-icon" style="color:#12b886;">
                <i class="fas fa-id-card"></i>
            </div>
            <h4>M1 - ABHA Integration</h4>
            <p>Configure ABHA creation, verification and patient integration.</p>
        </a>

        <!-- M2 -->
        <a href="/modules/abdm/settings/m2_settings.php" class="abdm-settings-card">
            <div class="abdm-settings-icon" style="color:#845ef7;">
                <i class="fas fa-file-signature"></i>
            </div>
            <h4>M2 - Consent Management</h4>
            <p>Configure ABDM consent management and related settings.</p>
        </a>

        <!-- M3 -->
        <a href="/modules/abdm/m3/settings.php" class="abdm-settings-card">
            <div class="abdm-settings-icon" style="color:#228be6;">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <h4>M3 - Health Exchange</h4>
            <p>Configure health information exchange and data transfer.</p>
        </a>

        <!-- M4 -->
        <a href="/modules/abdm/settings/m4_settings.php" class="abdm-settings-card">
            <div class="abdm-settings-icon" style="color:#e64980;">
                <i class="fas fa-file-medical"></i>
            </div>
            <h4>M4 - Health Records</h4>
            <p>Configure advanced health record exchange and integration.</p>
        </a>

    </div>

    <!-- ABDM IMPLEMENTATION MILESTONES -->
    <div class="abdm-section">
        <div class="abdm-section-header">
            <i class="fas fa-list-check"></i>
            <h3>ABDM Implementation Milestones</h3>
        </div>
        <p class="abdm-section-note">
            Overall ABDM implementation flow for WebSplend HMS.
        </p>

        <div class="abdm-table-wrap">
            <table class="abdm-table">
                <thead>
                    <tr>
                        <th>Milestone</th>
                        <th>Implementation Area</th>
                        <th>WebSplend HMS Requirement</th>
                    </tr>
                </thead>
                <tbody>

                    <tr>
                        <td class="abdm-milestone m1-color">M1</td>
                        <td class="abdm-purpose">Software / Integration Onboarding</td>
                        <td>ABDM application, sandbox onboarding, credentials, testing and production approval.</td>
                    </tr>

                    <tr>
                        <td class="abdm-milestone m1-color">M1</td>
                        <td class="abdm-purpose">HFR – Hospital / Facility</td>
                        <td>Register each hospital/facility and obtain/map the Facility ID.</td>
                    </tr>

                    <tr>
                        <td class="abdm-milestone m1-color">M1</td>
                        <td class="abdm-purpose">HPR – Doctor / Healthcare Professional</td>
                        <td>Healthcare professionals obtain/register their HPR identity where required.</td>
                    </tr>

                    <tr>
                        <td class="abdm-milestone m2-color">M2</td>
                        <td class="abdm-purpose">Patient ABHA / Consent</td>
                        <td>ABHA identification, patient linking and consent-related workflow.</td>
                    </tr>

                    <tr>
                        <td class="abdm-milestone m3-color">M3</td>
                        <td class="abdm-purpose">Health Information Exchange</td>
                        <td>Generate, encrypt and exchange clinical health records through the ABDM ecosystem.</td>
                    </tr>

                    <tr>
                        <td class="abdm-milestone m4-color">M4</td>
                        <td class="abdm-purpose">Additional ABDM Capabilities</td>
                        <td>Implement the M4 capabilities applicable to the HMS deployment.</td>
                    </tr>

                </tbody>
            </table>
        </div>
    </div>

   <!-- ABDM PORTALS -->
<div class="abdm-section">
    <div class="abdm-section-header">
        <i class="fas fa-globe"></i>
        <h3>ABDM Portals</h3>
    </div>
    <p class="abdm-section-note">
        Official portals used across the ABDM M1, M2, M3 and M4 implementation stages.
    </p>

    <div class="abdm-table-wrap">
        <table class="abdm-table">
            <thead>
                <tr>
                    <th>ABDM</th>
                    <th>Portal</th>
                    <th>Purpose</th>
                    <th>Test / Sandbox URL</th>
                    <th>Production URL</th>
                </tr>
            </thead>
            <tbody>

                <tr>
                    <td class="abdm-milestone m1-color">M1</td>
                    <td class="abdm-purpose">ABDM Software / Sandbox</td>
                    <td>Software/application onboarding, sandbox application, testing and sandbox exit.</td>
                    <td>
                        <a href="https://sandbox.abdm.gov.in/sandbox/v3/login" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://sandbox.abdm.gov.in/sandbox/v3/login
                        </a>
                    </td>
                    <td>
                        <span class="abdm-no-url">Production access after sandbox exit / approval</span>
                    </td>
                </tr>

                <tr>
                    <td class="abdm-milestone m1-color">M1</td>
                    <td class="abdm-purpose">HFR</td>
                    <td>Hospital / Health Facility registration and Facility ID.</td>
                    <td>
                        <a href="https://hspsbx.abdm.gov.in/nhpr/v4/hfr/dashboard" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://hspsbx.abdm.gov.in/nhpr/v4/hfr/dashboard
                        </a>
                    </td>
                    <td>
                        <a href="https://facility.abdm.gov.in/" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://facility.abdm.gov.in/
                        </a>
                    </td>
                </tr>

                <tr>
                    <td class="abdm-milestone m1-color">M1</td>
                    <td class="abdm-purpose">HPR</td>
                    <td>Healthcare Professional / Doctor registration and professional identity.</td>
                    <td>
                        <a href="https://hspsbx.abdm.gov.in/nhpr/v4/" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://hspsbx.abdm.gov.in/nhpr/v4/
                        </a>
                    </td>
                    <td>
                        <a href="https://nhpr.abdm.gov.in/nhpr/v4/home" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://nhpr.abdm.gov.in/nhpr/v4/home
                        </a>
                    </td>
                </tr>

                <tr>
                    <td class="abdm-milestone m2-color">M2</td>
                    <td class="abdm-purpose">ABHA</td>
                    <td>Patient ABHA identification, linking and consent-related activities.</td>
                    <td>
                        <a href="https://abhasbx.abdm.gov.in/" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://abhasbx.abdm.gov.in/
                        </a>
                    </td>
                    <td>
                        <a href="https://abha.abdm.gov.in/abha/v3/login" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://abha.abdm.gov.in/abha/v3/login
                        </a>
                    </td>
                </tr>

                <tr>
                    <td class="abdm-milestone m3-color">M3</td>
                    <td class="abdm-purpose">ABDM Health Exchange</td>
                    <td>HIP/HIU health-information exchange, consent-based data transfer and clinical records.</td>
                    <td>
                        <a href="https://sandbox.abdm.gov.in/sandbox/v3/login" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://sandbox.abdm.gov.in/sandbox/v3/login
                        </a>
                    </td>
                    <td>
                        <span class="abdm-no-url">Production ABDM APIs after production approval</span>
                    </td>
                </tr>

                <tr>
                    <td class="abdm-milestone m4-color">M4</td>
                    <td class="abdm-purpose">M4 – Additional ABDM Capabilities</td>
                    <td>Additional ABDM ecosystem capabilities applicable to the HMS.</td>
                    <td>
                        <a href="https://sandbox.abdm.gov.in/sandbox/v3/login" target="_blank" rel="noopener noreferrer" class="abdm-url">
                            https://sandbox.abdm.gov.in/sandbox/v3/login
                        </a>
                    </td>
                    <td>
                        <span class="abdm-no-url">Production ABDM APIs / applicable production portal</span>
                    </td>
                </tr>

            </tbody>
        </table>
    </div>
</div>


<div class="id-reference-section">
    <div class="id-reference-title">
        <h4>
            <i class="fas fa-id-card" style="margin-right:5px;"></i>
            ABDM ID Reference
        </h4>
    </div>

    <div class="id-reference-table-wrap">
        <table class="id-reference-table">
            <thead>
                <tr>
                    <th>Term</th>
                    <th>What It Represents</th>
                    <th>Naming / System Context</th>
                    <th>Example</th>
                    <th>URL</th>
                </tr>
            </thead>
            <tbody>

                <tr>
                    <td class="term">Facility ID</td>
                    <td>
                        Unique national ID assigned to a hospital, clinic or lab
                        registered in the Health Facility Registry (HFR).
                    </td>
                    <td>
                        Used across the HFR registry platform.
                    </td>
                    <td>
                        <span class="example">IN02100000XX</span>
                    </td>
                    <td>
                        <a href="https://facility.abdm.gov.in/"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="id-reference-link">
                            <i class="fas fa-external-link-alt"></i>
                            HFR
                        </a>
                    </td>
                </tr>

                <tr>
                    <td class="term">Service ID</td>
                    <td>
                        The same alphanumeric ID as the Facility ID for the
                        facility/service mapping described in your M2 setup.
                    </td>
                    <td>
                        Used in the ABDM Sandbox Portal when mapping
                        your software Bridge ID to the hospital.
                    </td>
                    <td>
                        <span class="example">IN02100000XX</span>
                    </td>
                    <td>
                        <a href="https://sandbox.abdm.gov.in/sandbox/v3/login"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="id-reference-link">
                            <i class="fas fa-external-link-alt"></i>
                            Sandbox
                        </a>
                    </td>
                </tr>

                <tr>
                    <td class="term">HIP ID</td>
                    <td>
                        Facility ID when the facility acts as a
                        Health Information Provider and generates/stores
                        health records.
                    </td>
                    <td>
                        Used in ABDM API requests as
                        <strong>X-HIP-ID</strong>.
                    </td>
                    <td>
                        <span class="example">IN02100000XX</span>
                    </td>
                    <td>
                        <a href="https://facility.abdm.gov.in/"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="id-reference-link">
                            <i class="fas fa-external-link-alt"></i>
                            HFR
                        </a>
                    </td>
                </tr>

                <tr>
                    <td class="term">HIU ID</td>
                    <td>
                        Facility ID when the facility acts as a
                        Health Information User and requests access to
                        health records with patient consent.
                    </td>
                    <td>
                        Used in ABDM API requests as
                        <strong>X-HIU-ID</strong>.
                    </td>
                    <td>
                        <span class="example">IN02100000XX</span>
                    </td>
                    <td>
                        <a href="https://sandbox.abdm.gov.in/sandbox/v3/login"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="id-reference-link">
                            <i class="fas fa-external-link-alt"></i>
                            Sandbox
                        </a>
                    </td>
                </tr>

            </tbody>
        </table>
    </div>
</div>


<div class="m2-infographic-section">
    <div class="m2-infographic-title">
        <h4>
            <i class="fas fa-project-diagram" style="margin-right:5px;"></i>
            ABDM M2 Interaction Flow
        </h4>
    </div>

    <div class="m2-infographic">
        <img
            src="/assets/img/ABDM%20M2%20Interaction%20Infographic.png"
            alt="ABDM M2 Interaction Infographic"
        >
    </div>
</div>



</div>
</body>
</html>