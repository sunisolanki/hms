<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!checkPermission('abdm_settings', 'view')) {
    header('Location: /access_denied.php');
    exit;
}

$message = '';
$messageType = '';

$result = $conn->query("SELECT * FROM abdm_m1_settings ORDER BY id DESC LIMIT 1");
$settings = $result ? $result->fetch_assoc() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkPermission('abdm_settings', 'update')) {
        header('Location: /access_denied.php');
        exit;
    }

    $environment = $_POST['environment'] ?? 'Sandbox';
    $clientId = trim($_POST['client_id'] ?? '');
    $clientSecret = trim($_POST['client_secret'] ?? '');
    $benefitName = trim($_POST['benefit_name'] ?? '');
    $status = $_POST['status'] ?? 'Active';

    if (!in_array($environment, ['Sandbox', 'Production'], true)) {
        $environment = 'Sandbox';
    }

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $status = 'Active';
    }

    if ($clientId === '') {
        $message = 'Client ID is required.';
        $messageType = 'error';
    } elseif ($settings && $clientSecret === '') {
        $stmt = $conn->prepare("
            UPDATE abdm_m1_settings
            SET environment = ?, client_id = ?, benefit_name = ?, status = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            "ssssi",
            $environment,
            $clientId,
            $benefitName,
            $status,
            $settings['id']
        );

        if ($stmt->execute()) {
            $message = 'M1 settings updated successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to update M1 settings.';
            $messageType = 'error';
        }

        $stmt->close();
    } elseif ($clientSecret === '') {
        $message = 'Client Secret is required for the first save.';
        $messageType = 'error';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO abdm_m1_settings
            (environment, client_id, client_secret, benefit_name, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssss",
            $environment,
            $clientId,
            $clientSecret,
            $benefitName,
            $status
        );

        if ($stmt->execute()) {
            $message = 'M1 settings saved successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to save M1 settings.';
            $messageType = 'error';
        }

        $stmt->close();
    }

    $result = $conn->query("SELECT * FROM abdm_m1_settings ORDER BY id DESC LIMIT 1");
    $settings = $result ? $result->fetch_assoc() : null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1 - ABHA Integration - WebSplend HMS</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.top-bar{
    position:relative;
}
.top-bar h2{
    position:absolute;
    left:50%;
    transform:translateX(-50%);
    margin:0;
    white-space:nowrap;
}
.breadcrumb{
    white-space:nowrap;
}
.m1-card{
    max-width:900px;
    margin:0 auto;
    background:#fff;
    border-radius:10px;
    padding:18px 20px;
    box-shadow:0 3px 12px rgba(0,0,0,.08);
}
.m1-header{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:14px;
    padding-bottom:12px;
    border-bottom:1px solid #edf0f2;
}
.m1-icon{
    width:42px;
    height:42px;
    min-width:42px;
    border-radius:9px;
    background:#d3f9d8;
    color:#12b886;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
}
.m1-header h3{
    margin:0;
    font-size:18px;
    line-height:1.2;
}
.m1-header p{
    margin:3px 0 0;
    color:#777;
    font-size:12px;
}
.form-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}
.form-group{
    margin-bottom:12px;
}
.form-group label{
    display:block;
    margin-bottom:5px;
    font-weight:600;
    color:#333;
    font-size:13px;
}
.form-group input,
.form-group select{
    width:100%;
    height:38px;
    padding:8px 11px;
    border:1px solid #d9dee3;
    border-radius:6px;
    font-size:13px;
    box-sizing:border-box;
    background:#fff;
}
.form-group input:focus,
.form-group select:focus{
    outline:none;
    border-color:#12b886;
    box-shadow:0 0 0 2px rgba(18,184,134,.08);
}
.form-help{
    display:block;
    margin-top:4px;
    font-size:11px;
    color:#777;
}
.secret-status{
    display:block;
    margin-top:4px;
    font-size:11px;
    color:#2b8a3e;
}
.portal-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-top:2px;
    margin-bottom:14px;
}
.portal-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    min-height:38px;
    padding:8px 10px;
    border-radius:6px;
    text-decoration:none;
    font-size:12px;
    font-weight:600;
    text-align:center;
    box-sizing:border-box;
}
.portal-sandbox{
    background:#e7f5ff;
    color:#1971c2;
    border:1px solid #a5d8ff;
}
.portal-production{
    background:#e6fcf5;
    color:#087f5b;
    border:1px solid #96f2d7;
}
.portal-test{
    background:#fff4e6;
    color:#e8590c;
    border:1px solid #ffd8a8;
}
.portal-btn:hover{
    filter:brightness(.97);
}
.alert{
    padding:9px 12px;
    border-radius:6px;
    margin-bottom:12px;
    font-size:12px;
}
.alert-success{
    background:#d3f9d8;
    color:#2b8a3e;
}
.alert-error{
    background:#ffe3e3;
    color:#c92a2a;
}
.form-actions{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:8px;
    padding-top:12px;
    border-top:1px solid #edf0f2;
}
.btn-save,
.btn-back{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    height:36px;
    padding:0 16px;
    border-radius:6px;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    box-sizing:border-box;
}
.btn-save{
    border:0;
    background:#12b886;
    color:#fff;
}
.btn-save:hover{
    background:#0ca678;
}
.btn-back{
    text-decoration:none;
    background:#f1f3f5;
    color:#495057;
}
.btn-back:hover{
    background:#e9ecef;
}
@media(max-width:700px){
    .top-bar h2{
        position:static;
        transform:none;
        text-align:center;
        margin-top:5px;
        font-size:18px;
    }
    .form-row{
        grid-template-columns:1fr;
        gap:0;
    }
    .portal-grid{
        grid-template-columns:1fr;
    }
    .m1-card{
        padding:15px;
    }
    .form-actions{
        justify-content:stretch;
    }
    .btn-save,
    .btn-back{
        flex:1;
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
        <a href="/modules/abdm/settings/abdm_settings.php">ABDM Settings</a>
        <span>&gt;</span>
        <a>M1</a>
    </div>
    <h2>M1 - ABHA Integration</h2>
</div>

<div class="container settings-wrapper">
    <div class="m1-card">
        <div class="m1-header">
            <div class="m1-icon">
                <i class="fas fa-id-card"></i>
            </div>
            <div>
                <h3>ABHA Integration Settings</h3>
                <p>Configure ABDM M1 credentials for ABHA integration.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $messageType === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label for="environment">Environment</label>
                    <select name="environment" id="environment" required>
                        <option value="Sandbox" <?php echo (($settings['environment'] ?? 'Sandbox') === 'Sandbox') ? 'selected' : ''; ?>>Sandbox</option>
                        <option value="Production" <?php echo (($settings['environment'] ?? '') === 'Production') ? 'selected' : ''; ?>>Production</option>
                    </select>
                    <span class="form-help">Select the ABDM environment to use.</span>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" required>
                        <option value="Active" <?php echo (($settings['status'] ?? 'Active') === 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo (($settings['status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <span class="form-help">Enable or disable M1 integration.</span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="client_id">Client ID</label>
                    <input type="text" name="client_id" id="client_id" value="<?php echo htmlspecialchars($settings['client_id'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="client_secret">Client Secret</label>
                    <input type="password" name="client_secret" id="client_secret" value="" <?php echo $settings ? '' : 'required'; ?> autocomplete="new-password">
                    <?php if ($settings): ?>
                        <span class="secret-status"><i class="fas fa-check-circle"></i> Client Secret is already configured. Leave blank to keep the existing secret.</span>
                    <?php else: ?>
                        <span class="form-help">Enter the ABDM Sandbox/Production Client Secret.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="benefit_name">Benefit Name <span style="font-weight:400;color:#777;">(Optional)</span></label>
                <input type="text" name="benefit_name" id="benefit_name" value="<?php echo htmlspecialchars($settings['benefit_name'] ?? ''); ?>">
                <span class="form-help">Required only for ABDM APIs that use a benefit program.</span>
            </div>

 <div class="portal-grid">
           
           <a href="https://sandbox.abdm.gov.in/" target="_blank" rel="noopener noreferrer" class="portal-btn portal-sandbox">
                    <i class="fas fa-flask"></i>
                    Sandbox Software Registration 
                </a>
                
           <a href="https://nhpr.abdm.gov.in/home" target="_blank" rel="noopener noreferrer" class="portal-btn portal-production">
                    <i class="fas fa-building"></i>
                    Production Registration / Facility Portal
                </a>
           
                <a href="https://hspsbx.abdm.gov.in/home" target="_blank" rel="noopener noreferrer" class="portal-btn portal-sandbox">
                    <i class="fas fa-flask"></i>
                    Sandbox Registration / Facility Portal
                </a>

                

                </div>

            <div class="form-actions">
                <a href="/modules/abdm/settings/abdm_settings.php" class="btn-back">
                    <i class="fas fa-arrow-left" style="margin-right:6px;"></i>
                    Back
                </a>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save" style="margin-right:6px;"></i>
                    Save M1 Settings
                </button>
            </div>
        </form>
    </div>
</div>
</body>
</html>