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

$hospitals = [];
$hospitalResult = $conn->query("SELECT id, hospital_code, hospital_name FROM hospital_master WHERE status = 1 ORDER BY hospital_name ASC");
if ($hospitalResult) {
    while ($row = $hospitalResult->fetch_assoc()) {
        $hospitals[] = $row;
    }
}
$selectedHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
$selectedEnvironment = $_GET['environment'] ?? 'Sandbox';
$settings = null;

if (!in_array($selectedEnvironment, ['Sandbox', 'Production'], true)) {
    $selectedEnvironment = 'Sandbox';
}

if ($selectedHospitalId > 0) {
    $stmt = $conn->prepare("SELECT * FROM abdm_m3_settings WHERE hospital_id = ? AND environment = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("is", $selectedHospitalId, $selectedEnvironment);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result ? $result->fetch_assoc() : null;
    $stmt->close();
} else {
    $result = $conn->query("SELECT * FROM abdm_m3_settings ORDER BY id DESC LIMIT 1");
    $settings = $result ? $result->fetch_assoc() : null;

    if ($settings) {
        $selectedHospitalId = (int)$settings['hospital_id'];
        $selectedEnvironment = $settings['environment'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkPermission('abdm_settings', 'update')) {
        header('Location: /access_denied.php');
        exit;
    }

    $hospitalId = (int)($_POST['hospital_id'] ?? 0);
    $environment = $_POST['environment'] ?? 'Sandbox';
    $clientId = trim($_POST['client_id'] ?? '');
    $clientSecret = trim($_POST['client_secret'] ?? '');
    $facilityId = trim($_POST['facility_id'] ?? '');
    $hiuId = trim($_POST['hiu_id'] ?? '');
    $bridgeId = trim($_POST['bridge_id'] ?? '');
    $callbackUrl = trim($_POST['callback_url'] ?? '');
    $dataPushUrl = trim($_POST['data_push_url'] ?? '');
    $status = $_POST['status'] ?? 'Active';

    if (!in_array($environment, ['Sandbox', 'Production'], true)) {
        $environment = 'Sandbox';
    }

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $status = 'Active';
    }

    /*
     * Resolve the target row BEFORE validation so that a blank Client Secret can
     * mean "keep the stored value" when a row already exists. This mirrors the
     * established M1 behaviour in modules/abdm/settings/m1_settings.php.
     * Without it the form cannot be saved without re-entering the secret on
     * every edit, because the stored secret is never rendered back into the
     * HTML (see the value="" attribute further down).
     */
    $existing = null;
    if ($hospitalId > 0) {
        $checkStmt = $conn->prepare("SELECT id FROM abdm_m3_settings WHERE hospital_id = ? AND environment = ? ORDER BY id DESC LIMIT 1");
        $checkStmt->bind_param("is", $hospitalId, $environment);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $existing = $checkResult ? $checkResult->fetch_assoc() : null;
        $checkStmt->close();
    }

    if ($hospitalId <= 0) {
        $message = 'Please select a hospital.';
        $messageType = 'error';
    } elseif ($clientId === '') {
        $message = 'Client ID is required.';
        $messageType = 'error';
    } elseif ($clientSecret === '' && !$existing) {
        $message = 'Client Secret is required.';
        $messageType = 'error';
    } elseif ($facilityId === '') {
        $message = 'Facility ID is required.';
        $messageType = 'error';
    } elseif ($hiuId === '') {
        $message = 'HIU ID is required.';
        $messageType = 'error';
    } elseif ($bridgeId === '') {
        $message = 'Bridge ID is required.';
        $messageType = 'error';
    } elseif ($callbackUrl === '') {
        $message = 'Callback URL is required.';
        $messageType = 'error';
    } else {
        if ($existing) {
            $settingsId = (int)$existing['id'];

            if ($clientSecret === '') {
                /*
                 * Blank submission: update every other field and deliberately
                 * OMIT client_secret from the SET clause so the stored value is
                 * preserved. This is the M1 pattern and is what allows the form
                 * to carry value="" without wiping the secret.
                 */
                $stmt = $conn->prepare("
                    UPDATE abdm_m3_settings
                    SET client_id = ?,
                        facility_id = ?,
                        hiu_id = ?,
                        bridge_id = ?,
                        callback_url = ?,
                        data_push_url = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "sssssssi",
                    $clientId,
                    $facilityId,
                    $hiuId,
                    $bridgeId,
                    $callbackUrl,
                    $dataPushUrl,
                    $status,
                    $settingsId
                );
            } else {
                $stmt = $conn->prepare("
                    UPDATE abdm_m3_settings
                    SET client_id = ?,
                        client_secret = ?,
                        facility_id = ?,
                        hiu_id = ?,
                        bridge_id = ?,
                        callback_url = ?,
                        data_push_url = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "ssssssssi",
                    $clientId,
                    $clientSecret,
                    $facilityId,
                    $hiuId,
                    $bridgeId,
                    $callbackUrl,
                    $dataPushUrl,
                    $status,
                    $settingsId
                );
            }

            if ($stmt->execute()) {
                $message = 'M3 settings updated successfully.';
                $messageType = 'success';
            } else {
                $message = 'Failed to update M3 settings: '.$stmt->error;
                $messageType = 'error';
            }

            $stmt->close();
        } else {
            $branchId = null;
            $createdBy = null;

            $stmt = $conn->prepare("
                INSERT INTO abdm_m3_settings
                (
                    hospital_id,
                    branch_id,
                    environment,
                    client_id,
                    client_secret,
                    facility_id,
                    hiu_id,
                    bridge_id,
                    callback_url,
                    data_push_url,
                    status,
                    created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "iisssssssssi",
                $hospitalId,
                $branchId,
                $environment,
                $clientId,
                $clientSecret,
                $facilityId,
                $hiuId,
                $bridgeId,
                $callbackUrl,
                $dataPushUrl,
                $status,
                $createdBy
            );

            if ($stmt->execute()) {
                $message = 'M3 settings saved successfully.';
                $messageType = 'success';
            } else {
                $message = 'Failed to save M3 settings: '.$stmt->error;
                $messageType = 'error';
            }

            $stmt->close();
        }

        $selectedHospitalId = $hospitalId;
        $selectedEnvironment = $environment;

        $stmt = $conn->prepare("SELECT * FROM abdm_m3_settings WHERE hospital_id = ? AND environment = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("is", $selectedHospitalId, $selectedEnvironment);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M3 - Health Information Exchange - WebSplend HMS</title>
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
.m3-card {
    max-width: 900px;
    margin: 0 auto;
    background: #fff;
    border-radius: 12px;
    padding: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
}
.m3-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}
.m3-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    background: #e7f5ff;
    color: #1971c2;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}
.m3-header h3 {
    margin: 0;
    font-size: 20px;
}
.m3-header p {
    margin: 4px 0 0;
    color: #777;
    font-size: 13px;
}
.form-group {
    margin-bottom: 5px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 11px 13px;
    border: 1px solid #d9dee3;
    border-radius: 7px;
    font-size: 14px;
    box-sizing: border-box;
}
.form-group textarea {
    min-height: 80px;
    resize: vertical;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #1971c2;
}
.form-help {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: #777;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.alert {
    padding: 12px 15px;
    border-radius: 7px;
    margin-bottom: 20px;
    font-size: 14px;
}
.alert-success {
    background: #d3f9d8;
    color: #2b8a3e;
}
.alert-error {
    background: #ffe3e3;
    color: #c92a2a;
}
.section-box {
    margin-top: 25px;
    padding-top: 25px;
    border-top: 1px solid #eee;
}
.section-box h4 {
    margin: 0 0 15px;
    font-size: 16px;
}
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 25px;
}
.btn-save {
    border: 0;
    background: #1971c2;
    color: #fff;
    padding: 11px 22px;
    border-radius: 7px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
.btn-save:hover {
    background: #1864ab;
}
.btn-back {
    display: inline-flex;
    align-items: center;
    text-decoration: none;
    background: #f1f3f5;
    color: #495057;
    padding: 11px 20px;
    border-radius: 7px;
    font-size: 14px;
    font-weight: 600;
}
.btn-back:hover {
    background: #e9ecef;
}
@media (max-width: 700px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    .m3-card {
        padding: 20px;
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
        <a>M3</a>
    </div>
    <h2>M3 - Health Information Exchange</h2>
</div>
<div class="container settings-wrapper">
    <div class="m3-card">
        <div class="m3-header">
            <div class="m3-icon">
                <i class="fas fa-network-wired"></i>
            </div>
            <div>
                <h3>ABDM M3 Settings</h3>
                <p>Configure ABDM M3 Gateway, HIU and health information exchange details.</p>
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
                    <label for="hospital_id">Hospital</label>
                    <select name="hospital_id" id="hospital_id" required onchange="loadHospitalSettings()">
                        <option value="">Select Hospital</option>
                        <?php foreach ($hospitals as $hospital): ?>
                            <option value="<?php echo (int)$hospital['id']; ?>" <?php echo ((int)$selectedHospitalId === (int)$hospital['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hospital['hospital_name']); ?> (<?php echo htmlspecialchars($hospital['hospital_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-help">Select the hospital for this ABDM M3 configuration.</span>
                </div>
                <div class="form-group">
                    <label for="environment">Environment</label>
                    <select name="environment" id="environment" required onchange="loadHospitalSettings()">
                        <option value="Sandbox" <?php echo $selectedEnvironment === 'Sandbox' ? 'selected' : ''; ?>>Sandbox</option>
                        <option value="Production" <?php echo $selectedEnvironment === 'Production' ? 'selected' : ''; ?>>Production</option>
                    </select>
                    <span class="form-help">Select the ABDM environment.</span>
                </div>
            </div>
            <div class="section-box">
                <h4>Gateway Credentials</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="client_id">Client ID</label>
                        <input type="text" name="client_id" id="client_id" value="<?php echo htmlspecialchars($settings['client_id'] ?? ''); ?>" required>
                        <span class="form-help">ABDM software/client identifier.</span>
                    </div>
                    <div class="form-group">
                        <label for="client_secret">Client Secret</label>
                        <input type="password" name="client_secret" id="client_secret" value="" autocomplete="new-password"
                            <?php echo $settings ? '' : 'required'; ?> placeholder="<?php echo $settings ? 'Unchanged - leave blank to keep the stored secret' : ''; ?>">
                            <span class="form-help">For security the stored secret is never displayed. Leave this blank to keep the current value, or type a new secret to replace it.</span>
                        <span class="form-help">ABDM client secret used for gateway authentication.</span>
                    </div>
                </div>
            </div>
            <div class="section-box">
                <h4>ABDM Identity</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="facility_id">Facility ID</label>
                        <input type="text" name="facility_id" id="facility_id" value="<?php echo htmlspecialchars($settings['facility_id'] ?? ''); ?>" required>
                        <span class="form-help">ABDM registered facility identifier.</span>
                    </div>
                    <div class="form-group">
                        <label for="hiu_id">HIU ID</label>
                        <input type="text" name="hiu_id" id="hiu_id" value="<?php echo htmlspecialchars($settings['hiu_id'] ?? ''); ?>" required>
                        <span class="form-help">Health Information User identifier.</span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="bridge_id">Bridge ID</label>
                    <input type="text" name="bridge_id" id="bridge_id" value="<?php echo htmlspecialchars($settings['bridge_id'] ?? ''); ?>" required>
                    <span class="form-help">ABDM bridge identifier associated with the M3 integration.</span>
                </div>
            </div>
            <div class="section-box">
                <h4>Callback Configuration</h4>
                <div class="form-group">
                    <label for="callback_url">Callback URL</label>
                    <textarea name="callback_url" id="callback_url" required><?php echo htmlspecialchars($settings['callback_url'] ?? ''); ?></textarea>
                    <span class="form-help">URL where ABDM callback requests will be received.</span>
                </div>
                <div class="form-group">
                    <label for="data_push_url">Data Push URL</label>
                    <textarea name="data_push_url" id="data_push_url"><?php echo htmlspecialchars($settings['data_push_url'] ?? ''); ?></textarea>
                    <span class="form-help">URL used for health information data exchange/push processing.</span>
                </div>
            </div>
            <div class="section-box">
                <h4>Configuration Status</h4>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" required>
                        <option value="Active" <?php echo (($settings['status'] ?? 'Active') === 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo (($settings['status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <a href="/modules/abdm/settings/abdm_settings.php" class="btn-back">
                    <i class="fas fa-arrow-left" style="margin-right:7px;"></i> Back
                </a>
                <button type="submit" class="btn-save">
                    <i class="fas fa-save" style="margin-right:7px;"></i> Save M3 Settings
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function loadHospitalSettings() {
    const hospitalId = document.getElementById('hospital_id').value;
    const environment = document.getElementById('environment').value;
    if (hospitalId && environment) {
        window.location.href = '?hospital_id=' + encodeURIComponent(hospitalId) + '&environment=' + encodeURIComponent(environment);
    }
}
</script>
</body>
</html>