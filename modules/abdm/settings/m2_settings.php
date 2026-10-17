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

$result = $conn->query("SELECT * FROM abdm_m2_settings ORDER BY id DESC LIMIT 1");
$settings = $result ? $result->fetch_assoc() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkPermission('abdm_settings', 'update')) {
        header('Location: /access_denied.php');
        exit;
    }

    $environment = $_POST['environment'] ?? 'Sandbox';
    $bridgeId = trim($_POST['bridge_id'] ?? '');
    $serviceId = trim($_POST['service_id'] ?? '');
    $facilityId = trim($_POST['facility_id'] ?? '');
    $hipId = trim($_POST['hip_id'] ?? '');
    $hiuId = trim($_POST['hiu_id'] ?? '');
    $hipName = trim($_POST['hip_name'] ?? '');
    $callbackUrl = trim($_POST['callback_url'] ?? '');
    $isHip = isset($_POST['is_hip']) ? 1 : 0;
    $isHiu = isset($_POST['is_hiu']) ? 1 : 0;
    $status = $_POST['status'] ?? 'Active';

    if (!in_array($environment, ['Sandbox', 'Production'], true)) {
        $environment = 'Sandbox';
    }

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $status = 'Active';
    }

    if ($bridgeId === '') {
        $message = 'Bridge ID / Client ID is required.';
        $messageType = 'error';
    } elseif ($settings) {
        $stmt = $conn->prepare("
            UPDATE abdm_m2_settings
            SET environment = ?,
                bridge_id = ?,
                service_id = ?,
                facility_id = ?,
                hip_id = ?,
                hiu_id = ?,
                hip_name = ?,
                callback_url = ?,
                is_hip = ?,
                is_hiu = ?,
                status = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ssssssssiisi",
            $environment,
            $bridgeId,
            $serviceId,
            $facilityId,
            $hipId,
            $hiuId,
            $hipName,
            $callbackUrl,
            $isHip,
            $isHiu,
            $status,
            $settings['id']
        );

        if ($stmt->execute()) {
            $message = 'M2 settings updated successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to update M2 settings.';
            $messageType = 'error';
        }

        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO abdm_m2_settings
            (
                environment,
                bridge_id,
                service_id,
                facility_id,
                hip_id,
                hiu_id,
                hip_name,
                callback_url,
                is_hip,
                is_hiu,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssssssiss",
            $environment,
            $bridgeId,
            $serviceId,
            $facilityId,
            $hipId,
            $hiuId,
            $hipName,
            $callbackUrl,
            $isHip,
            $isHiu,
            $status
        );

        if ($stmt->execute()) {
            $message = 'M2 settings saved successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to save M2 settings.';
            $messageType = 'error';
        }

        $stmt->close();
    }

    $result = $conn->query("SELECT * FROM abdm_m2_settings ORDER BY id DESC LIMIT 1");
    $settings = $result ? $result->fetch_assoc() : null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M2 - Health Information Exchange - WebSplend HMS</title>
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
}
.breadcrumb{
    white-space:nowrap;
}
.settings-wrapper{
    padding-top:10px;
    padding-bottom:10px;
}
.m2-card{
    max-width:1100px;
    margin:0 auto;
    background:#fff;
    border-radius:10px;
    padding:14px 18px;
    box-shadow:0 3px 12px rgba(0,0,0,.08);
}
.m2-header{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
}
.m2-icon{
    width:42px;
    height:42px;
    flex:0 0 42px;
    border-radius:9px;
    background:#e7f5ff;
    color:#1971c2;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
}
.m2-header h3{
    margin:0;
    font-size:18px;
    line-height:1.2;
}
.m2-header p{
    margin:3px 0 0;
    color:#777;
    font-size:12px;
}
.alert{
    padding:8px 12px;
    border-radius:6px;
    margin-bottom:10px;
    font-size:13px;
}
.alert-success{
    background:#d3f9d8;
    color:#2b8a3e;
}
.alert-error{
    background:#ffe3e3;
    color:#c92a2a;
}
.form-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:9px 14px;
}
.form-group{
    min-width:0;
}
.form-group.full{
    grid-column:1 / -1;
}
.form-group label{
    display:block;
    margin-bottom:3px;
    font-weight:600;
    color:#333;
    font-size:12px;
}
.form-group input,
.form-group select,
.form-group textarea{
    width:100%;
    height:34px;
    padding:6px 9px;
    border:1px solid #d9dee3;
    border-radius:6px;
    font-size:13px;
    box-sizing:border-box;
    background:#fff;
}
.form-group textarea{
    height:34px;
    min-height:34px;
    resize:none;
    line-height:20px;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
    outline:none;
    border-color:#1971c2;
    box-shadow:0 0 0 2px rgba(25,113,194,.08);
}
.form-help{
    display:block;
    margin-top:2px;
    font-size:10px;
    line-height:12px;
    color:#777;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.checkbox-section{
    display:flex;
    align-items:center;
    gap:20px;
    min-height:34px;
    padding-top:4px;
}
.checkbox-item{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    font-weight:600;
    color:#333;
    cursor:pointer;
}
.checkbox-item input{
    width:15px;
    height:15px;
    margin:0;
    cursor:pointer;
}
.portal-section{
    margin-top:12px;
    padding-top:10px;
    border-top:1px solid #eee;
}
.portal-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:7px;
}
.portal-title h4{
    margin:0;
    font-size:13px;
    color:#333;
}
.portal-title span{
    font-size:10px;
    color:#777;
}
.portal-buttons{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}
.portal-button{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    height:34px;
    border-radius:6px;
    text-decoration:none;
    font-size:12px;
    font-weight:600;
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
.form-actions{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:8px;
    margin-top:12px;
    padding-top:10px;
    border-top:1px solid #eee;
}
.btn-save,
.btn-back{
    height:34px;
    padding:0 16px;
    border-radius:6px;
    font-size:12px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    cursor:pointer;
}
.btn-save{
    border:0;
    background:#1971c2;
    color:#fff;
}
.btn-save:hover{
    background:#1864ab;
}
.btn-back{
    background:#f1f3f5;
    color:#495057;
}
.btn-back:hover{
    background:#e9ecef;
}
@media(max-width:900px){
    .form-grid{
        grid-template-columns:repeat(2,1fr);
    }
}
@media(max-width:700px){
    .top-bar h2{
        display:none;
    }
    .m2-card{
        padding:12px;
    }
    .form-grid{
        grid-template-columns:1fr;
    }
    .form-group.full{
        grid-column:auto;
    }
    .portal-buttons{
        grid-template-columns:1fr;
    }
    .checkbox-section{
        flex-wrap:wrap;
        gap:10px 18px;
    }
    .form-help{
        white-space:normal;
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
        <a>M2</a>
    </div>
    <h2>M2 - Health Information Exchange</h2>
</div>

<div class="container settings-wrapper">
    <div class="m2-card">

        <div class="m2-header">
            <div class="m2-icon">
                <i class="fas fa-network-wired"></i>
            </div>
            <div>
                <h3>ABDM M2 Settings</h3>
                <p>Configure HIP/HIU bridge and facility integration details.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $messageType === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group">
                    <label for="environment">Environment</label>
                    <select name="environment" id="environment" required>
                        <option value="Sandbox" <?php echo (($settings['environment'] ?? 'Sandbox') === 'Sandbox') ? 'selected' : ''; ?>>Sandbox</option>
                        <option value="Production" <?php echo (($settings['environment'] ?? '') === 'Production') ? 'selected' : ''; ?>>Production</option>
                    </select>
                    <span class="form-help">Select the ABDM M2 environment.</span>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" required>
                        <option value="Active" <?php echo (($settings['status'] ?? 'Active') === 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo (($settings['status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="bridge_id">Bridge ID / Client ID</label>
                    <input type="text" name="bridge_id" id="bridge_id" value="<?php echo htmlspecialchars($settings['bridge_id'] ?? ''); ?>" required>
                    <span class="form-help">For ABDM M2, Bridge ID is the same as the software Client ID.</span>
                </div>

                <div class="form-group">
                    <label for="service_id">Service ID</label>
                    <input type="text" name="service_id" id="service_id" value="<?php echo htmlspecialchars($settings['service_id'] ?? ''); ?>">
                    <span class="form-help">Facility Service ID generated through ABDM facility registration.</span>
                </div>

                <div class="form-group">
                    <label for="facility_id">Facility ID</label>
                    <input type="text" name="facility_id" id="facility_id" value="<?php echo htmlspecialchars($settings['facility_id'] ?? ''); ?>">
                    <span class="form-help">ABDM registered facility ID</span>
                </div>

                <div class="form-group">
                    <label for="hip_id">HIP ID</label>
                    <input type="text" name="hip_id" id="hip_id" value="<?php echo htmlspecialchars($settings['hip_id'] ?? ''); ?>">
                    <span class="form-help">Health Information Provider ID</span>
                </div>

                <div class="form-group">
                    <label for="hiu_id">HIU ID</label>
                    <input type="text" name="hiu_id" id="hiu_id" value="<?php echo htmlspecialchars($settings['hiu_id'] ?? ''); ?>">
                    <span class="form-help">Leave blank if this facility is not configured as an HIU.</span>
                </div>

                <div class="form-group">
                    <label for="hip_name">HIP Name</label>
                    <input type="text" name="hip_name" id="hip_name" value="<?php echo htmlspecialchars($settings['hip_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="callback_url">Callback URL</label>
                    <input type="url" name="callback_url" id="callback_url" value="<?php echo htmlspecialchars($settings['callback_url'] ?? ''); ?>" placeholder="https://hms.websplend.com">
                    <span class="form-help">Use the domain-based callback URL registered with ABDM.</span>
                </div>

                <div class="form-group">
                    <label>Integration Role</label>
                    <div class="checkbox-section">
                        <label class="checkbox-item">
                            <input type="checkbox" name="is_hip" value="1" <?php echo !empty($settings['is_hip']) ? 'checked' : ''; ?>>
                            Configure as HIP
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="is_hiu" value="1" <?php echo !empty($settings['is_hiu']) ? 'checked' : ''; ?>>
                            Configure as HIU
                        </label>
                    </div>
                </div>

            </div>

            <div class="portal-section">
                <div class="portal-title">
                    <h4><i class="fas fa-building" style="margin-right:5px;"></i> ABDM Facility Registration</h4>
                    <span>HFR / Facility Portal</span>
                </div>

                <div class="portal-buttons">
                    <a href="https://hspsbx.abdm.gov.in/home" target="_blank" rel="noopener noreferrer" class="portal-button portal-sandbox">
                        <i class="fas fa-flask"></i>
                        Sandbox Facility Portal
                    </a>

                    <a href="https://facility.abdm.gov.in/" target="_blank" rel="noopener noreferrer" class="portal-button portal-production">
                        <i class="fas fa-building"></i>
                        Production Facility Portal
                    </a>
                </div>
            </div>

            <div class="form-actions">
                <a href="/modules/abdm/settings/abdm_settings.php" class="btn-back">
                    <i class="fas fa-arrow-left" style="margin-right:6px;"></i>
                    Back
                </a>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save" style="margin-right:6px;"></i>
                    Save M2 Settings
                </button>
            </div>

        </form>

    </div>
</div>

</body>
</html>