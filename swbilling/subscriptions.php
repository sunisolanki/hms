<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once __DIR__.'/includes/init.php';
if (!isset($_SESSION['user_id'])) {
    header("Location:/index.php");
    exit;
}
if (!checkPermission('swbilling','view')) {
    header("Location:/access_denied.php");
    exit;
}
$user_id = $_SESSION['user_id'];

$isSuperAdmin = ((int)$_SESSION['rights_type_id'] === 1);

$selected_hospital_id=0;
/*-------------------------------------------------------
GET HOSPITAL LIST
-------------------------------------------------------*/
$hospitals=[];
if($isSuperAdmin){
    $sql="
SELECT
    id,
    hospital_name,
    hospital_code
FROM hospital_master
WHERE status=1
ORDER BY hospital_name
";
    $result=$conn->query($sql);
    while($row=$result->fetch_assoc()){
        $hospitals[]=$row;
    }
    if(isset($_GET['hospital_id'])){
        $selected_hospital_id=(int)$_GET['hospital_id'];
    }
}else{
    $stmt=$conn->prepare("
    SELECT
        hm.id,
        hm.hospital_name,
        hm.hospital_code
    FROM user_hospitals uh
    INNER JOIN hospital_master hm
        ON hm.id=uh.hospital_id
    WHERE uh.user_id=?
AND hm.status=1
LIMIT 1
    ");
    $stmt->bind_param("i",$user_id);
    $stmt->execute();
    $hospital=$stmt->get_result()->fetch_assoc();
    if(!$hospital){
        die("Hospital not assigned.");
    }
    $selected_hospital_id=$hospital['id'];
}
/*-------------------------------------------------------
LOAD HOSPITAL DETAILS
-------------------------------------------------------*/
$hospital_name='';
$hospital_code='';
if($selected_hospital_id>0){
    $stmt=$conn->prepare("
    SELECT
        id,
        hospital_name,
        hospital_code
    FROM hospital_master
    WHERE id=?
    LIMIT 1
    ");
    $stmt->bind_param("i",$selected_hospital_id);
    $stmt->execute();
    $hospitalInfo=$stmt->get_result()->fetch_assoc();
    if($hospitalInfo){
        $hospital_name=$hospitalInfo['hospital_name'];
        $hospital_code=$hospitalInfo['hospital_code'];
    }
}
/*-------------------------------------------------------
DEFAULT VALUES
-------------------------------------------------------*/
$subscription_id=0;
$plan_name='';
$billing_type='Annual';
$start_date='';
$end_date='';
$renewal_date='';
$amount=0;
$gst_percent=18;
$gst_amount=0;
$discount=0;
$total_amount=0;
$status='Pending';
$remarks='';
/*-------------------------------------------------------
LOAD SUBSCRIPTION
-------------------------------------------------------*/
if($selected_hospital_id>0){
    $stmt=$conn->prepare("
    SELECT *
    FROM software_subscriptions
    WHERE hospital_id=?
    LIMIT 1
    ");
    $stmt->bind_param("i",$selected_hospital_id);
    $stmt->execute();
    $subscription=$stmt->get_result()->fetch_assoc();
    if($subscription){
        $subscription_id=$subscription['id'];
        $plan_name=$subscription['plan_name'];
        $billing_type=$subscription['billing_type'];
        $start_date=$subscription['start_date'];
        $end_date=$subscription['end_date'];
        $renewal_date=$subscription['renewal_date'];
        $amount=$subscription['amount'];
        $gst_percent=$subscription['gst_percent'];
        $gst_amount=$subscription['gst_amount'];
        $discount=$subscription['discount'];
        $total_amount=$subscription['total_amount'];
        $status=$subscription['status'];
        $remarks=$subscription['remarks'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Subscription Master</title>
<link rel="icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

body {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    overflow: hidden;
}

.card-header {
    background: var(--primary-gradient) !important;
    padding: 15px 25px;
    border-bottom: none;
}

.card-header h4 {
    font-weight: 700;
    font-size: 1.3rem;
}

.card-header h4 i {
    margin-right: 10px;
}

.card-body {
    padding: 20px 25px;
    background: white;
}

.form-label {
    font-weight: 600;
    font-size: 0.82rem;
    color: #4a5568;
    margin-bottom: 4px;
}

.form-label .required {
    color: #e53e3e;
    margin-left: 2px;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    padding: 7px 12px;
    font-size: 0.88rem;
    transition: all 0.3s ease;
    background: #f7fafc;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    background: white;
}

.form-control[readonly] {
    background: #edf2f7;
    cursor: not-allowed;
}

.btn {
    border-radius: 8px;
    font-weight: 600;
    padding: 8px 25px;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.btn-primary {
    background: var(--primary-gradient);
    border: none;
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
    color: white;
}

.btn-secondary {
    background: #718096;
    border: none;
    color: white;
}

.btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(113, 128, 150, 0.4);
    color: white;
}
.row {
    gap:0px; !important
}
.select2-container--default .select2-selection--single {
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    height: 42px;
    background: #f7fafc;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 38px;
    padding-left: 12px;
    color: #2d3748;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 38px;
}

hr {
    border-top: 2px solid #e2e8f0;
    margin: 15px 0;
}

/* Compact grid */
.row.g-2 {
    --bs-gutter-y: 0.5rem;
    --bs-gutter-x: 0.5rem;
}

/* Animation */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card {
    animation: fadeInUp 0.5s ease-out;
}

/* Responsive */
@media (max-width: 768px) {
    .card-body {
        padding: 15px;
    }
    .btn {
        width: 100%;
        margin: 5px 0;
    }
    .text-end {
        text-align: center !important;
    }
}

/* Status colors in select */
select[name="status"] option[value="Trial"] { color: #4299e1; }
select[name="status"] option[value="Pending"] { color: #ed8936; }
select[name="status"] option[value="Active"] { color: #48bb78; }
select[name="status"] option[value="Expired"] { color: #fc8181; }
select[name="status"] option[value="Suspended"] { color: #ed64a6; }
select[name="status"] option[value="Cancelled"] { color: #a0aec0; }

.input-group-text {
    background: var(--primary-gradient);
    color: white;
    border: none;
    border-radius: 8px 0 0 8px;
    font-weight: 600;
}

.input-group .form-control {
    border-radius: 0 8px 8px 0;
}

.info-tip {
    color: #a0aec0;
    font-size: 0.7rem;
    cursor: help;
}

.field-icon {
    margin-right: 4px;
    width: 16px;
}
</style>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
</head>
<body>
<div class="container-fluid mt-3">
    <div class="card">
        <div class="card-header text-white">
            <h4 class="mb-0">
                <i class="fas fa-file-contract"></i>
                Software Subscription
                <small class="ms-2 opacity-75" style="font-size: 0.7rem; font-weight: 400;">
                    <i class="fas fa-<?=($subscription_id>0)?'edit':'plus'?>"></i>
                    <?=($subscription_id>0)?'Update':'Create New'?> Subscription
                </small>
            </h4>
        </div>
        <div class="card-body">
            <form
                id="subscriptionForm"
                method="post"
                action="subscription_save.php">
                <input
                    type="hidden"
                    name="subscription_id"
                    value="<?=$subscription_id?>">

                <!-- ROW 1: 4 Fields - Hospital, Hospital Code, Plan Name, Billing Type -->
                <div class="row g-2">
                    <?php if($isSuperAdmin){ ?>
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-hospital text-primary field-icon"></i>
                            Hospital <span class="required">*</span>
                        </label>
                        <select
                            name="hospital_id"
                            id="hospital_id"
                            class="form-select select2"
                            <?=($subscription_id>0)?'disabled':'';?>>
                            <option value="">Select Hospital</option>
                            <?php
                            foreach($hospitals as $row){
                            ?>
                            <option
                                value="<?=$row['id']?>"
                                <?=$selected_hospital_id==$row['id']?'selected':'';?>>
                                <?=htmlspecialchars($row['hospital_name'])?>
                                (<?=htmlspecialchars($row['hospital_code'])?>)
                            </option>
                            <?php
                            }
                            ?>
                        </select>
                        <?php
                        if($subscription_id>0){
                        ?>
                        <input
                            type="hidden"
                            name="hospital_id"
                            value="<?=$selected_hospital_id?>">
                        <?php
                        }
                        ?>
                    </div>
                    <?php } else { ?>
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-hospital text-primary field-icon"></i>
                            Hospital
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            value="<?=htmlspecialchars($hospital_name)?>"
                            readonly>
                        <input
                            type="hidden"
                            name="hospital_id"
                            value="<?=$selected_hospital_id?>">
                    </div>
                    <?php } ?>
                    
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-barcode text-primary field-icon"></i>
                            Hospital Code
                        </label>
                        <input
                            type="text"
                            id="hospital_code"
                            class="form-control"
                            value="<?=htmlspecialchars($hospital_code)?>"
                            readonly>
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-crown text-warning field-icon"></i>
                            Plan Name <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            name="plan_name"
                            class="form-control"
                            maxlength="100"
                            required
                            placeholder="Enter plan name"
                            value="<?=htmlspecialchars($plan_name)?>">
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-tag text-success field-icon"></i>
                            Billing Type <span class="required">*</span>
                        </label>
                        <select
                            name="billing_type"
                            id="billing_type"
                            class="form-select"
                            required>
                            <option value="Monthly" <?=$billing_type=="Monthly"?"selected":"";?>>
                                📅 Monthly
                            </option>
                            <option value="Annual" <?=$billing_type=="Annual"?"selected":"";?>>
                                📆 Annual
                            </option>
                            <option value="One Time" <?=$billing_type=="One Time"?"selected":"";?>>
                                ⚡ One Time
                            </option>
                            <option value="AMC" <?=$billing_type=="AMC"?"selected":"";?>>
                                🔧 AMC
                            </option>
                        </select>
                    </div>
                </div>

                <!-- ROW 2: 4 Fields - Start Date, End Date, Renewal Date, Status -->
                <div class="row g-2">
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-play text-success field-icon"></i>
                            Start Date <span class="required">*</span>
                        </label>
                        <input
                            type="date"
                            name="start_date"
                            id="start_date"
                            class="form-control date-field"
                            required
                            value="<?=$start_date?>">
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-stop text-danger field-icon"></i>
                            End Date <span class="required">*</span>
                        </label>
                        <input
                            type="date"
                            name="end_date"
                            id="end_date"
                            class="form-control date-field"
                            required
                            value="<?=$end_date?>">
                        <small class="text-muted" id="end_date_hint" style="font-size: 0.65rem;"></small>
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-redo text-warning field-icon"></i>
                            Renewal Date <span class="required">*</span>
                        </label>
                        <input
                            type="date"
                            name="renewal_date"
                            id="renewal_date"
                            class="form-control date-field"
                            required
                            value="<?=$renewal_date?>">
                        <small class="text-muted" id="renewal_date_hint" style="font-size: 0.65rem;"></small>
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <label class="form-label">
                            <i class="fas fa-info-circle text-info field-icon"></i>
                            Status <span class="required">*</span>
                        </label>
                        <select
                            name="status"
                            class="form-select"
                            required>
                            <option value="Trial" <?=$status=="Trial"?"selected":"";?>>
                                🧪 Trial
                            </option>
                            <option value="Pending" <?=$status=="Pending"?"selected":"";?>>
                                ⏳ Pending
                            </option>
                            <option value="Active" <?=$status=="Active"?"selected":"";?>>
                                ✅ Active
                            </option>
                            <option value="Expired" <?=$status=="Expired"?"selected":"";?>>
                                ❌ Expired
                            </option>
                            <option value="Suspended" <?=$status=="Suspended"?"selected":"";?>>
                                ⛔ Suspended
                            </option>
                            <option value="Cancelled" <?=$status=="Cancelled"?"selected":"";?>>
                                🚫 Cancelled
                            </option>
                        </select>
                    </div>
                </div>

                <!-- ROW 3: 5 Fields - Amount, GST %, GST Amount, Discount, Total Amount -->
                <div class="row g-2">
                    <div class="col-md-2-4 mb-2" style="max-width:fit-content;">
                        <label class="form-label">
                            <i class="fas fa-money-bill text-success field-icon"></i>
                            Amount <span class="required">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                id="amount"
                                name="amount"
                                class="form-control calc"
                                required
                                placeholder="0.00"
                                value="<?=$amount?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2-4 mb-2" style="max-width:fit-content;">
                        <label class="form-label">
                            <i class="fas fa-percent text-primary field-icon"></i>
                            GST %
                        </label>
                        <div class="input-group">
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                id="gst_percent"
                                name="gst_percent"
                                class="form-control calc"
                                value="<?=$gst_percent?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    
                    <div class="col-md-2-4 mb-2" style="max-width:fit-content;">
                        <label class="form-label">
                            <i class="fas fa-calculator text-info field-icon"></i>
                            GST Amount
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input
                                type="number"
                                step="0.01"
                                id="gst_amount"
                                name="gst_amount"
                                class="form-control"
                                readonly
                                value="<?=$gst_amount?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2-4 mb-2" style="max-width:fit-content;">
                        <label class="form-label">
                            <i class="fas fa-tags text-warning field-icon"></i>
                            Discount
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                id="discount"
                                name="discount"
                                class="form-control calc"
                                placeholder="0.00"
                                value="<?=$discount?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2-4 mb-2" style="max-width:fit-content;">
                        <label class="form-label">
                            <i class="fas fa-file-invoice text-danger field-icon"></i>
                            Total Amount
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input
                                type="number"
                                step="0.01"
                                id="total_amount"
                                name="total_amount"
                                class="form-control"
                                readonly
                                value="<?=$total_amount?>">
                        </div>
                    </div>
                </div>

                <!-- Remarks Row -->
                <div class="row g-2">
                    <div class="col-md-12 mb-2">
                        <label class="form-label">
                            <i class="fas fa-comment text-secondary field-icon"></i>
                            Remarks
                        </label>
                        <textarea
                            name="remarks"
                            class="form-control"
                            rows="2"
                            placeholder="Additional notes or comments..."><?=htmlspecialchars($remarks)?></textarea>
                    </div>
                </div>

                <hr>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-md-12 text-end">
                        <a
                            href="index.php"
                            class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>
                            Back
                        </a>
                        <?php
                        if(checkPermission('swbilling','add') || checkPermission('swbilling','edit')){
                        ?>
                        <button
                            type="submit"
                            class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <?=$subscription_id>0?'Update Subscription':'Save Subscription'?>
                        </button>
                        <?php
                        }
                        ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function(){
    // Initialize Select2
    $('.select2').select2({
        width:'100%',
        placeholder:'Select Hospital',
        allowClear: true
    });

    // Calculate totals on load and change
    calculateTotal();

    // Bind calculation events
    $('.calc').on('keyup change', function(){
        calculateTotal();
    });

    // Date auto-calculation on billing type change
    $('#billing_type').on('change', function(){
        calculateDates();
    });

    // Date auto-calculation on start date change
    $('#start_date').on('change', function(){
        calculateDates();
    });

    // Hospital selection redirect
    $('#hospital_id').on('change', function(){
        let hospital_id = $(this).val();
        if(hospital_id == ''){
            location.href = 'subscriptions.php';
            return;
        }
        location.href = 'subscriptions.php?hospital_id=' + hospital_id;
    });

    // Initialize date calculations if not editing
    <?php if($subscription_id == 0 && empty($start_date)): ?>
    calculateDates();
    <?php endif; ?>
});

function calculateDates() {
    const billingType = $('#billing_type').val();
    const startDate = $('#start_date').val();

    if (!startDate) {
        // If no start date, set default to today
        const today = new Date();
        const formattedDate = today.toISOString().split('T')[0];
        $('#start_date').val(formattedDate);
        return calculateDates(); // Recursive call with new date
    }

    const start = new Date(startDate);
    let end = new Date(start);
    let renewal = new Date(start);

    // Calculate based on billing type
    switch(billingType) {
        case 'Monthly':
            end.setDate(end.getDate() + 30);
            break;
        case 'Annual':
            end.setFullYear(end.getFullYear() + 1);
            break;
        case 'One Time':
            end.setFullYear(end.getFullYear() + 5);
            break;
        case 'AMC':
            end.setFullYear(end.getFullYear() + 1);
            break;
        default:
            end.setFullYear(end.getFullYear() + 1);
    }

    // Renewal date = 15 days after end date
    renewal = new Date(end);
    renewal.setDate(renewal.getDate() + 15);

    // Set the dates
    $('#end_date').val(end.toISOString().split('T')[0]);
    $('#renewal_date').val(renewal.toISOString().split('T')[0]);

    // Show hints
    const daysDiff = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
    const renewalDays = Math.ceil((end - renewal) / (1000 * 60 * 60 * 24));

    let endHint = '';
    let renewalHint = '';

    switch(billingType) {
        case 'Monthly':
            endHint = `📅 ${daysDiff} days (Monthly)`;
            renewalHint = `⏰ ${renewalDays} days after expiry`;
            break;
        case 'Annual':
            endHint = `📆 365 days (Annual)`;
            renewalHint = `⏰ ${renewalDays} days  after expiry`;
            break;
        case 'One Time':
            endHint = `⚡ 5 years (One Time)`;
            renewalHint = `⏰ ${renewalDays} days after expiry`;
            break;
        case 'AMC':
            endHint = `🔧 365 days (AMC)`;
            renewalHint = `⏰ ${renewalDays} days after expiry`;
            break;
        default:
            endHint = `📅 ${daysDiff} days`;
            renewalHint = `⏰ ${renewalDays} days after expiry`;
    }

    $('#end_date_hint').text(endHint);
    $('#renewal_date_hint').text(renewalHint);
}

function calculateTotal(){
    let amount = parseFloat($('#amount').val()) || 0;
    let gst = parseFloat($('#gst_percent').val()) || 0;

    if(gst < 0) {
        gst = 0;
        $('#gst_percent').val(0);
    }
    if(gst > 100) {
        gst = 100;
        $('#gst_percent').val(100);
    }

    let discount = parseFloat($('#discount').val()) || 0;
    let gstAmount = (amount * gst) / 100;

    $('#gst_amount').val(gstAmount.toFixed(2));

    let total = (amount + gstAmount) - discount;
    if(total < 0) {
        total = 0;
    }
    $('#total_amount').val(total.toFixed(2));
}

// Form validation
$('#subscriptionForm').on('submit', function(){
    // Validate Hospital
    if($('#hospital_id').length){
        if($('#hospital_id').val() == ''){
            alert('⚠️ Please Select Hospital.');
            $('#hospital_id').focus();
            return false;
        }
    }

    // Validate Plan Name
    if($.trim($('input[name="plan_name"]').val()) == ''){
        alert('⚠️ Please Enter Plan Name.');
        $('input[name="plan_name"]').focus();
        return false;
    }

    // Validate Amount
    if($('#amount').val() == '' || parseFloat($('#amount').val()) <= 0){
        alert('⚠️ Amount should be greater than 0.');
        $('#amount').focus();
        return false;
    }

    // Validate Dates
    let startDate = $('input[name="start_date"]').val();
    let endDate = $('input[name="end_date"]').val();
    let renewalDate = $('input[name="renewal_date"]').val();

    if(startDate == '' || endDate == ''){
        alert('⚠️ Please select Start Date and End Date.');
        return false;
    }

    if(endDate < startDate){
        alert('⚠️ End Date cannot be earlier than Start Date.');
        $('input[name="end_date"]').focus();
        return false;
    }

    if(renewalDate != '' && renewalDate < startDate){
        alert('⚠️ Renewal Date cannot be earlier than Start Date.');
        $('input[name="renewal_date"]').focus();
        return false;
    }

    // Validate Total Amount
    let total = parseFloat($('#total_amount').val()) || 0;
    if(total <= 0){
        alert('⚠️ Total Amount should be greater than 0.');
        return false;
    }

    return true;
});
</script>
</body>
</html>