<?php
session_start();
if(isset($_GET['saved'])){
    echo "<script>alert('Configuration saved successfully.');</script>";
}
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
/* Save config */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gateway = $_POST['gateway'];
    $mode    = $_POST['mode'];
    $active  = isset($_POST['is_active']) ? 1 : 0;
    $merchant_key    = $_POST['merchant_key'] ?? null;
    $merchant_secret = $_POST['merchant_secret'] ?? null;
    $api_key         = $_POST['api_key'] ?? null;
    $api_secret      = $_POST['api_secret'] ?? null;
    $webhook_secret  = $_POST['webhook_secret'] ?? null;
    $extra = json_encode($_POST['extra'] ?? []);
/* ================= LOAD OLD CONFIG ================= */
$oldConfig = [];
$changedFields = [];
$q = $conn->prepare("
    SELECT *
    FROM payment_gateway_config
    WHERE gateway = ?
");
$q->bind_param("s", $gateway);
$q->execute();
$res = $q->get_result();
if ($res->num_rows > 0) {
    $oldConfig = $res->fetch_assoc();
}
$q->close();
/* ================= COMPARE ================= */

$fields = [
    'mode'              => 'Mode',
    'merchant_key'      => 'Merchant Key',
    'merchant_secret'   => 'Merchant Secret',
    'api_key'           => 'API Key',
    'api_secret'        => 'API Secret',
    'webhook_secret'    => 'Webhook Secret',
    'is_active'         => 'Status'
];

foreach ($fields as $dbField => $label) {

    $oldValue = (string)($oldConfig[$dbField] ?? '');

    switch ($dbField) {

        case 'mode':
            $newValue = $mode;
            break;

        case 'merchant_key':
            $newValue = $merchant_key;
            break;

        case 'merchant_secret':
            $newValue = $merchant_secret;
            break;

        case 'api_key':
            $newValue = $api_key;
            break;

        case 'api_secret':
            $newValue = $api_secret;
            break;

        case 'webhook_secret':
            $newValue = $webhook_secret;
            break;

        case 'is_active':
            $newValue = (string)$active;
            break;

        default:
            $newValue = '';
    }

    if ($oldValue !== $newValue) {

        $changedFields[$label] = [
            'old' => $oldValue,
            'new' => $newValue
        ];
    }
}
    $stmt = $conn->prepare("
        INSERT INTO payment_gateway_config
        (gateway, mode, merchant_key, merchant_secret, api_key, api_secret, webhook_secret, extra_config, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            mode=VALUES(mode),
            merchant_key=VALUES(merchant_key),
            merchant_secret=VALUES(merchant_secret),
            api_key=VALUES(api_key),
            api_secret=VALUES(api_secret),
            webhook_secret=VALUES(webhook_secret),
            extra_config=VALUES(extra_config),
            is_active=VALUES(is_active)
    ");

    $stmt->bind_param(
        "ssssssssi",
        $gateway,
        $mode,
        $merchant_key,
        $merchant_secret,
        $api_key,
        $api_secret,
        $webhook_secret,
        $extra,
        $active
    );

    $stmt->execute();
    $stmt->close();
/* ================= AUDIT LOG ================= */

if (empty($oldConfig)) {

    $newValues = [
        'Gateway' => $gateway,
        'Mode' => $mode,
        'Merchant Key' => $merchant_key,
        'Merchant Secret' => $merchant_secret,
        'API Key' => $api_key,
        'API Secret' => $api_secret,
        'Webhook Secret' => $webhook_secret,
        'Status' => $active
    ];

    addAuditLog(
        'Payment Gateway',
        'Create',
        "Payment Gateway Config Created : {$gateway}",
        null,
        '',
        json_encode($newValues),
        'SUCCESS'
    );

} elseif (!empty($changedFields)) {

    $oldChanged = [];
    $newChanged = [];

    foreach ($changedFields as $field => $values) {

        $oldChanged[$field] = $values['old'];
        $newChanged[$field] = $values['new'];
    }

    addAuditLog(
        'Payment Gateway',
        'Update',
        "Payment Gateway Config Updated : {$gateway}",
        null,
        json_encode($oldChanged),
        json_encode($newChanged),
        'SUCCESS'
    );
}
    header("Location: ".$_SERVER['PHP_SELF']."?saved=1");
exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
<title>Payment Gateway Configuration</title>
 <meta name="viewport" content="width=device-width, initial-scale=1">
 <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
label{font-weight:600}
.field{margin-bottom:10px}
.hidden{display:none}
</style>
</head>
<body>
  <style>
      
/* CARD */
.form-card{
    background:#fff;
    padding:20px;
    border-radius:12px;
    max-width:900px;
    margin:auto;
    box-shadow:0 4px 12px rgba(0,0,0,0.70);
}

/* GRID */
.grid{
    display:grid;
    grid-template-columns: repeat(auto-fit,minmax(250px,1fr));
    gap:15px;
}

/* LABEL */
label{
    font-size:12px;
    font-weight:600;
    color:#555;
}

/* INPUT */
input, select{
    width:100%;
    padding:8px;
    border:1px solid #ddd;
    border-radius:6px;
    font-size:13px;
}

/* SECTION TITLE */
h3{
    font-size:14px;
    margin-bottom:10px;
    color:#333;
}

/* HR */
hr{
    margin:15px 0;
    border:0;
    border-top:1px solid #eee;
}

/* BUTTON */
.form-actions{
    text-align:right;
    margin-top:15px;
}

.btn-save{
    background:#2c7be5;
    color:#fff;
    padding:10px 18px;
    border:none;
    border-radius:6px;
    cursor:pointer;
}

.btn-save:hover{
    background:#1a5fd0;
}

/* SWITCH */
.switch{
    position:relative;
    display:inline-block;
    width:40px;
    height:20px;
}

.switch input{
    opacity:0;
    width:0;
    height:0;
}

.slider{
    position:absolute;
    cursor:pointer;
    background:#ccc;
    border-radius:20px;
    top:0;
    left:0;
    right:0;
    bottom:0;
    transition:.3s;
}

.slider:before{
    position:absolute;
    content:"";
    height:14px;
    width:14px;
    left:3px;
    bottom:3px;
    background:white;
    border-radius:50%;
    transition:.3s;
}

.switch input:checked + .slider{
    background:#28a745;
}

.switch input:checked + .slider:before{
    transform:translateX(20px);
}

/* TOGGLE BOX */
.toggle-box{
    display:flex;
    flex-direction:column;
    justify-content:center;
}

/* HIDDEN */
.hidden{
    display:none;
}
  </style>  
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<div class="top-bar">

    <!-- LEFT: BREADCRUMB -->
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Payment Gateway</a>
    </div>

    <!-- CENTER: TITLE -->
    <h2 class="page-title">Payment Gateway Configuration</h2>

    <!-- RIGHT: ACTION -->
    <div class="top-actions"></div></div>
    <form method="POST">
<div class="form-card">
<h3>Gateway Configuration</h3>
<div class="grid">
<!-- Gateway -->
<div>
<label>Gateway</label>
<select name="gateway" id="gateway" onchange="toggleFields()" required>
   <option value="">Select Gateway</option>
    <option value="PAYU">PayU</option>
    <option value="RAZORPAY">Razorpay</option>
    <option value="STRIPE">Stripe</option>
    <option value="CASHFREE">Cashfree</option>
    <option value="PAYPAL">PayPal</option>
</select>
</div>

<!-- Mode -->
<div>
<label>Mode</label>
<select name="mode" id="mode">
    <option value="TEST">TEST</option>
    <option value="LIVE">LIVE</option>
</select>
</div>

<!-- Active -->
<div class="toggle-box">
<label>Status</label>
<label class="switch">
    <input type="checkbox" name="is_active" id="is_active">
    <span class="slider"></span>
</label>
</div>

</div>
<hr>
<h3>API Credentials</h3>
<div class="grid">
<!-- COMMON -->
<div>
<label>API Key</label>
<input type="text" name="api_key" id="api_key">
</div>

<div>
<label>API Secret</label>
<input type="text" name="api_secret" id="api_secret">
</div>
<!-- WEBHOOK -->
<div>
<label>Webhook Secret</label>
<input type="text" name="webhook_secret" id="webhook_secret">
</div>
</div>
<!-- PAYU -->
<div id="payu_fields" class="hidden">
<hr>
<h3>PayU Settings</h3>
<div class="grid">
    <div>
        <label>Merchant Key</label>
        <input type="text" name="merchant_key" id="merchant_key">
    </div>

    <div>
        <label>Merchant Salt</label>
        <input type="text" name="merchant_secret" id="merchant_secret">
    </div>
</div>
</div>
<!-- BUTTON -->
<div class="form-actions">
<button type="submit" class="btn-save">
<i class="fas fa-save"></i> Save Configuration
</button>
</div>

</form>

<script>
function toggleFields() {

    const gw = document.getElementById('gateway').value;

    // Show / hide PayU fields
    document.getElementById('payu_fields').style.display =
        (gw === 'PAYU') ? 'block' : 'none';

    // If nothing selected → clear form
    if (!gw) {
        clearForm();
        return;
    }

    // 🔥 AUTOLOAD saved config
    fetch('/payments/ajax_get_gateway_config.php?gateway=' + gw)
        .then(res => res.json())
        .then(cfg => {

            clearForm(); // reset first

            if (!cfg) return; // no saved data

            document.getElementById('mode').value = cfg.mode;
            document.getElementById('api_key').value = cfg.api_key || '';
            document.getElementById('api_secret').value = cfg.api_secret || '';
            document.getElementById('merchant_key').value = cfg.merchant_key || '';
            document.getElementById('merchant_secret').value = cfg.merchant_secret || '';
            document.getElementById('webhook_secret').value = cfg.webhook_secret || '';
            document.getElementById('is_active').checked = cfg.is_active == 1;
        });
}
</script>

<script>
function clearForm() {
    document.getElementById('mode').value = 'TEST';
    document.getElementById('api_key').value = '';
    document.getElementById('api_secret').value = '';
    document.getElementById('merchant_key').value = '';
    document.getElementById('merchant_secret').value = '';
    document.getElementById('webhook_secret').value = '';
    document.getElementById('is_active').checked = false;
}
</script>

</body>
</html>
