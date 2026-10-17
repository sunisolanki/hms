<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
if (!isset($_SESSION['user_id'])) {
    header("Location:/index.php");
    exit;
}
if (!checkPermission('swbilling','view')) {
    header("Location:/access_denied.php");
    exit;
}
$user_id = $_SESSION['user_id'];
/* Get Logged-in Hospital */
$stmt = $conn->prepare("
    SELECT
        hm.id,
        hm.hospital_code,
        hm.hospital_name,
        hm.logo,
        hm.address,
        hm.mobile,
        hm.email
    FROM user_hospitals uh
    INNER JOIN hospital_master hm
        ON hm.id = uh.hospital_id
    WHERE uh.user_id=?
    LIMIT 1
");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$hospital = $stmt->get_result()->fetch_assoc();
if(!$hospital){
    die("Hospital not assigned.");
}
$hospital_id   = $hospital['id'];
$hospital_name = $hospital['hospital_name'];
$hospital_code = $hospital['hospital_code'];
$hospital_logo = $hospital['logo'];
/* Current Subscription */
$subscription = [];
$stmt = $conn->prepare("
SELECT *
FROM software_subscriptions
WHERE hospital_id=?
LIMIT 1
");
$stmt->bind_param("i",$hospital_id);
$stmt->execute();
$res = $stmt->get_result();
if($res->num_rows>0){
    $subscription=$res->fetch_assoc();
}
$plan_name       = $subscription['plan_name'] ?? '-';
$billing_type    = $subscription['billing_type'] ?? '-';
$status          = $subscription['status'] ?? 'Pending';
$start_date      = $subscription['start_date'] ?? '';
$end_date        = $subscription['end_date'] ?? '';
$renewal_date    = $subscription['renewal_date'] ?? '';
$total_amount    = $subscription['total_amount'] ?? 0;
$days_remaining='-';
if(!empty($end_date)){
    $today=new DateTime();
    $expiry=new DateTime($end_date);
    $diff=$today->diff($expiry);
    $days_remaining=$diff->format('%r%a');
}
/* Outstanding Amount */
$outstanding=0;
$stmt=$conn->prepare("
SELECT
IFNULL(SUM(balance_amount),0) balance
FROM software_invoices
WHERE subscription_id=?
AND payment_status!='Paid'
");
$subid=$subscription['id'] ?? 0;
$stmt->bind_param("i",$subid);
$stmt->execute();
$outstanding=$stmt->get_result()->fetch_assoc()['balance'] ?? 0;
/* Last Invoice */
$last_invoice=[];
$stmt=$conn->prepare("
SELECT *
FROM software_invoices
WHERE subscription_id=?
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param("i",$subid);
$stmt->execute();
$r=$stmt->get_result();
if($r->num_rows){
    $last_invoice=$r->fetch_assoc();
}
$last_invoice_id = $last_invoice['id'] ?? 0;
/* Last Payment */
$last_payment=[];
$stmt=$conn->prepare("
SELECT p.*
FROM software_payments p
INNER JOIN software_invoices i
ON p.invoice_id=i.id
WHERE i.subscription_id=?
ORDER BY p.id DESC
LIMIT 1
");
$stmt->bind_param("i",$subid);
$stmt->execute();
$r=$stmt->get_result();
if($r->num_rows){
    $last_payment=$r->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Software Billing Dashboard</title>
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet">
<style>
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    --danger-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --dark-gradient: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
}

body {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.06);
    transition: all 0.3s ease;
    overflow: hidden;
    height: 100%;
}
.row {
    gap:0px; !important
}
.card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(0,0,0,0.10);
}

.summary-card {
    padding: 15px 18px;
    position: relative;
    background: white;
    height: 100%;
}

.summary-card .summary-icon {
    float: right;
    font-size: 2rem;
    opacity: 0.12;
    margin-top: -5px;
}

.summary-card h6 {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.summary-card h3 {
    font-weight: 700;
    margin: 0;
    color: #2d3748;
    font-size: 1.6rem;
}

.summary-card small {
    font-size: 11px;
    }
.badge {
    font-size: 12px;
    padding: 6px 14px;
    font-weight: 600;
    border-radius: 20px;
}

.btn {
    border-radius: 8px;
    font-weight: 600;
    padding: 8px 20px;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    border: none;
    color: white;
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(17, 153, 142, 0.4);
    color: white;
}

.btn-warning {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    border: none;
    color: white;
}

.btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(245, 87, 108, 0.4);
    color: white;
}

.btn-info {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    border: none;
    color: white;
}

.btn-info:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(79, 172, 254, 0.4);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    border: none;
    color: white;
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(250, 112, 154, 0.4);
    color: white;
}

.card-header {
    background: transparent;
    border-bottom: 2px solid rgba(0,0,0,0.04);
    padding: 14px 18px;
}

.card-header h5 {
    font-weight: 700;
    font-size: 1rem;
    color: #2d3748;
    margin: 0;
}

.card-header.bg-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    border: none;
}

.card-header.bg-success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%) !important;
    border: none;
}

.card-header.bg-danger {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%) !important;
    border: none;
}

.card-header.bg-dark {
    background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%) !important;
    border: none;
}

.card-header.text-white h5 {
    color: white !important;
}

.card-body {
    padding: 15px 18px;
}

.table {
    margin-bottom: 0;
    font-size: 0.85rem;
}

.table th {
    font-weight: 600;
    color: #4a5568;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.table-hover tbody tr:hover {
    background-color: rgba(102, 126, 234, 0.04);
}

.table-bordered {
    border: 1px solid #e2e8f0;
}

.table-bordered th,
.table-bordered td {
    border-color: #e2e8f0;
    padding: 8px 12px;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(0,0,0,0.015);
}

.quick-actions .btn {
    margin: 3px;
    min-width: 120px;
}

.quick-actions .btn i {
    margin-right: 6px;
}

.icon-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    flex-shrink: 0;
}

.icon-primary { background: rgba(102, 126, 234, 0.1); color: #667eea; }
.icon-success { background: rgba(17, 153, 142, 0.1); color: #11998e; }
.icon-warning { background: rgba(245, 87, 108, 0.1); color: #f5576c; }
.icon-info { background: rgba(79, 172, 254, 0.1); color: #4facfe; }
.icon-danger { background: rgba(250, 112, 154, 0.1); color: #fa709a; }

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card {
    animation: fadeInUp 0.5s ease-out;
}

@media (max-width: 768px) {
    .summary-card h3 {
        font-size: 1.2rem;
    }
    .btn {
        width: 100%;
        margin: 3px 0;
    }
    .quick-actions .btn {
        min-width: auto;
    }
    .card-body {
        padding: 12px;
    }
}

/* Compact spacing */
.g-3 {
    --bs-gutter-y: 0.75rem;
    --bs-gutter-x: 0.75rem;
}

.mt-3 {
    margin-top: 0.75rem !important;
}

.mb-3 {
    margin-bottom: 0.75rem !important;
}

.table td, .table th {
    padding: 6px 10px;
}

.border-left-primary {
    border-left: 3px solid #667eea;
}

.border-left-success {
    border-left: 3px solid #11998e;
}

.border-left-warning {
    border-left: 3px solid #f5576c;
}

.border-left-danger {
    border-left: 3px solid #fa709a;
}

.border-left-info {
    border-left: 3px solid #4facfe;
}
</style>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
</head>
<body>
<div class="container-fluid mt-3">
    <!-- First Row - 4 Summary Cards -->
    <div class="row g-3">
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-primary">
                <i class="fas fa-rocket summary-icon text-primary"></i>
                <h6><i class="fas fa-crown text-primary me-1"></i>Current Plan</h6>
                <h3><?=htmlspecialchars($plan_name)?></h3>
                <small>Active Subscription</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-success">
                <i class="fas fa-clock summary-icon text-success"></i>
                <h6><i class="fas fa-tag text-success me-1"></i>Billing Type</h6>
                <h3><?=htmlspecialchars($billing_type)?></h3>
                <small>Payment Frequency</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-warning">
                <i class="fas fa-circle summary-icon text-warning"></i>
                <h6><i class="fas fa-check-circle me-1"></i>Status</h6>
                <h3>
                    <?php
                    $color='secondary';
                    $icon='fa-circle';
                    if($status=='Active') { $color='success'; $icon='fa-check-circle'; }
                    if($status=='Pending') { $color='warning'; $icon='fa-clock'; }
                    if($status=='Expired') { $color='danger'; $icon='fa-exclamation-circle'; }
                    ?>
                    <span class="badge bg-<?=$color?>">
                        <i class="fas <?=$icon?> me-1"></i>
                        <?=htmlspecialchars($status)?>
                    </span>
                </h3>
                <small>Subscription Status</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-danger">
                <i class="fas fa-coin summary-icon text-danger"></i>
                <h6><i class="fas fa-money-bill-wave text-danger me-1"></i>Outstanding</h6>
                <h3 class="<?=($outstanding > 0) ? 'text-danger' : 'text-success'?>">
                    ₹ <?=number_format($outstanding,2)?>
                </h3>
                <small>Total Due Amount</small>
            </div>
        </div>
    </div>

    <!-- Second Row - 4 Date Cards -->
    <div class="row g-3 mt-1">
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-primary">
                <i class="fas fa-calendar-alt summary-icon text-primary"></i>
                <h6><i class="fas fa-play text-primary me-1"></i>Start Date</h6>
                <h3><?=htmlspecialchars($start_date)?></h3>
                <small>Subscription Start</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-danger">
                <i class="fas fa-calendar-times summary-icon text-danger"></i>
                <h6><i class="fas fa-stop text-danger me-1"></i>Expiry Date</h6>
                <h3><?=htmlspecialchars($end_date)?></h3>
                <small>Subscription End</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-warning">
                <i class="fas fa-sync summary-icon text-warning"></i>
                <h6><i class="fas fa-redo text-warning me-1"></i>Renewal Date</h6>
                <h3><?=htmlspecialchars($renewal_date)?></h3>
                <small>Next Renewal</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card summary-card border-left-info">
                <i class="fas fa-hourglass-half summary-icon text-info"></i>
                <h6><i class="fas fa-hourglass-start text-info me-1"></i>Days Remaining</h6>
                <h3 class="<?=($days_remaining < 30) ? 'text-danger' : (($days_remaining < 90) ? 'text-warning' : 'text-success')?>">
                    <?=$days_remaining?>
                </h3>
                <small>Until Expiry</small>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mt-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-white py-2">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt text-warning me-2"></i>
                        Quick Actions
                    </h5>
                </div>
                <div class="card-body quick-actions py-2">
                    <a href="renew_subscription.php?id=<?=$subid?>" class="btn btn-primary">
    <i class="fas fa-sync-alt"></i> Renew
</a>
                    <a href="invoices.php" class="btn btn-success">
                        <i class="fas fa-file-invoice"></i> Invoices
                    </a>
                    <a href="payments.php?invoice_id=<?=$last_invoice_id?>" class="btn btn-warning">
    <i class="fas fa-credit-card"></i> Payments
</a>
                   <a href="invoice_print.php?id=<?=$last_invoice_id?>" class="btn btn-info">
    <i class="fas fa-print"></i> Print Invoice
</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Subscription Details & Last Invoice in one row -->
    <div class="row mt-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-primary text-white py-2">
                    <h5 class="mb-0">
                        <i class="fas fa-id-card me-2"></i>
                        Subscription Details
                    </h5>
                </div>
                <div class="card-body p-2">
                    <table class="table table-bordered table-striped mb-0">
                        <tr>
                            <th width="35%"><i class="fas fa-hospital me-1 text-primary"></i>Hospital</th>
                            <td><?=htmlspecialchars($hospital_name)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-crown me-1 text-warning"></i>Plan</th>
                            <td><?=htmlspecialchars($plan_name)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-tag me-1 text-success"></i>Billing Type</th>
                            <td><?=htmlspecialchars($billing_type)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-check-circle me-1 text-info"></i>Status</th>
                            <td>
                                <?php
                                $statusColor='secondary';
                                $statusIcon='fa-circle';
                                if($status=='Active') { $statusColor='success'; $statusIcon='fa-check-circle'; }
                                if($status=='Pending') { $statusColor='warning'; $statusIcon='fa-clock'; }
                                if($status=='Expired') { $statusColor='danger'; $statusIcon='fa-exclamation-circle'; }
                                if($status=='Suspended') { $statusColor='dark'; $statusIcon='fa-pause-circle'; }
                                ?>
                                <span class="badge bg-<?=$statusColor?>">
                                    <i class="fas <?=$statusIcon?> me-1"></i>
                                    <?=htmlspecialchars($status)?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-calendar-alt me-1 text-primary"></i>Start</th>
                            <td><?=htmlspecialchars($start_date)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-calendar-times me-1 text-danger"></i>End</th>
                            <td><?=htmlspecialchars($end_date)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-redo me-1 text-warning"></i>Renewal</th>
                            <td><?=htmlspecialchars($renewal_date)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-money-bill-wave me-1 text-success"></i>Total</th>
                            <td><strong class="text-success">₹ <?=number_format($total_amount,2)?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-success text-white py-2">
                    <h5 class="mb-0">
                        <i class="fas fa-file-invoice me-2"></i>
                        Last Invoice
                    </h5>
                </div>
                <div class="card-body p-2">
                    <?php if(!empty($last_invoice)){ ?>
                    <table class="table table-bordered mb-0">
                        <tr>
                            <th width="40%"><i class="fas fa-hashtag me-1 text-primary"></i>Invoice No</th>
                            <td><strong><?=htmlspecialchars($last_invoice['invoice_no'])?></strong></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-calendar-day me-1 text-info"></i>Date</th>
                            <td><?=htmlspecialchars($last_invoice['invoice_date'])?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-calendar-range me-1 text-warning"></i>Period</th>
                            <td><?=htmlspecialchars($last_invoice['billing_from'])?> - <?=htmlspecialchars($last_invoice['billing_to'])?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-calculator me-1 text-danger"></i>Total</th>
                            <td class="fw-bold">₹ <?=number_format($last_invoice['total_amount'],2)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-check-circle me-1 text-success"></i>Paid</th>
                            <td>₹ <?=number_format($last_invoice['paid_amount'],2)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-exclamation-triangle me-1 text-danger"></i>Balance</th>
                            <td class="text-danger fw-bold">₹ <?=number_format($last_invoice['balance_amount'],2)?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-info-circle me-1 text-primary"></i>Status</th>
                            <td>
                                <?php
                                $p='secondary';
                                $pIcon='fa-circle';
                                if($last_invoice['payment_status']=='Paid') { $p='success'; $pIcon='fa-check-circle'; }
                                if($last_invoice['payment_status']=='Pending') { $p='warning'; $pIcon='fa-clock'; }
                                if($last_invoice['payment_status']=='Partial') { $p='info'; $pIcon='fa-adjust'; }
                                if($last_invoice['payment_status']=='Cancelled') { $p='danger'; $pIcon='fa-times-circle'; }
                                ?>
                                <span class="badge bg-<?=$p?>">
                                    <i class="fas <?=$pIcon?> me-1"></i>
                                    <?=htmlspecialchars($last_invoice['payment_status'])?>
                                </span>
                            </td>
                        </tr>
                    </table>
                    <?php } else { ?>
                    <div class="alert alert-warning mb-0 py-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No Invoice Generated.
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Invoices -->
    <div class="row mt-3">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header bg-dark text-white py-2">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Recent Invoices
                    </h5>
                </div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Invoice No</th>
                                    <th>Date</th>
                                    <th>Billing Type</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt=$conn->prepare("
                                    SELECT *
                                    FROM software_invoices
                                    WHERE subscription_id=?
                                    ORDER BY id DESC
                                    LIMIT 10
                                ");
                                $stmt->bind_param("i",$subid);
                                $stmt->execute();
                                $list=$stmt->get_result();
                                $sr=1;
                                if($list->num_rows){
                                    while($row=$list->fetch_assoc()){
                                        $statusBadge = 'secondary';
                                        if($row['payment_status']=='Paid') $statusBadge = 'success';
                                        if($row['payment_status']=='Pending') $statusBadge = 'warning';
                                        if($row['payment_status']=='Partial') $statusBadge = 'info';
                                        if($row['payment_status']=='Cancelled') $statusBadge = 'danger';
                                ?>
                                <tr>
                                    <td><?=$sr++?></td>
                                    <td><strong><?=$row['invoice_no']?></strong></td>
                                    <td><?=$row['invoice_date']?></td>
                                    <td><?=$row['billing_type']?></td>
                                    <td class="text-success fw-bold">₹ <?=number_format($row['total_amount'],2)?></td>
                                    <td class="text-info">₹ <?=number_format($row['paid_amount'],2)?></td>
                                    <td class="text-danger fw-bold">₹ <?=number_format($row['balance_amount'],2)?></td>
                                    <td><span class="badge bg-<?=$statusBadge?>"><?=$row['payment_status']?></span></td>
                                </tr>
                                <?php
                                    }
                                }else{
                                ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-2">
                                        <i class="fas fa-inbox me-2"></i>No Invoice Found
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Payments -->
    <div class="row mt-3">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header bg-success text-white py-2">
                    <h5 class="mb-0">
                        <i class="fas fa-money-check-dollar me-2"></i>
                        Recent Payments
                    </h5>
                </div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Payment Date</th>
                                    <th>Payment Mode</th>
                                    <th>Transaction No.</th>
                                    <th>Bank</th>
                                    <th>Amount</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt=$conn->prepare("
                                    SELECT *
                                    FROM software_payments
                                    WHERE invoice_id IN (
                                        SELECT id
                                        FROM software_invoices
                                        WHERE subscription_id=?
                                    )
                                    ORDER BY id DESC
                                    LIMIT 10
                                ");
                                $stmt->bind_param("i",$subid);
                                $stmt->execute();
                                $payments=$stmt->get_result();
                                $sr=1;
                                if($payments->num_rows){
                                    while($pay=$payments->fetch_assoc()){
                                ?>
                                <tr>
                                    <td><?=$sr++?></td>
                                    <td><?=htmlspecialchars($pay['payment_date'])?></td>
                                    <td><span class="badge bg-info"><i class="fas fa-<?=($pay['payment_mode']=='Cash' ? 'money-bill-wave' : 'credit-card')?> me-1"></i><?=htmlspecialchars($pay['payment_mode'])?></span></td>
                                    <td><code><?=htmlspecialchars($pay['transaction_no'])?></code></td>
                                    <td><?=htmlspecialchars($pay['bank_name'])?></td>
                                    <td class="fw-bold text-success">₹ <?=number_format($pay['amount'],2)?></td>
                                    <td><?=htmlspecialchars($pay['remarks'])?></td>
                                </tr>
                                <?php
                                    }
                                }else{
                                ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-2">
                                        <i class="fas fa-credit-card me-2"></i>No Payment History Found
                                    </td>
                                </tr>
                                <?php
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Last Payment, Subscription Summary & Outstanding Balance in one row -->
    <div class="row mt-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-success">
                <div class="card-header bg-success text-white py-2">
                    <i class="fas fa-credit-card me-2"></i>
                    Last Payment
                </div>
                <div class="card-body p-3">
                    <?php if(!empty($last_payment)){ ?>
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon-circle icon-success">
                            <i class="fas fa-check fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Date</small>
                            <strong><?=htmlspecialchars($last_payment['payment_date'])?></strong>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon-circle icon-info">
                            <i class="fas fa-credit-card fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Mode</small>
                            <strong><?=htmlspecialchars($last_payment['payment_mode'])?></strong>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon-circle icon-warning">
                            <i class="fas fa-hashtag fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Transaction</small>
                            <code><?=htmlspecialchars($last_payment['transaction_no'])?></code>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="icon-circle icon-success">
                            <i class="fas fa-money-bill-wave fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Amount</small>
                            <span class="text-success fw-bold">₹ <?=number_format($last_payment['amount'],2)?></span>
                        </div>
                    </div>
                    <?php } else { ?>
                    <div class="alert alert-warning mb-0 py-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>No Payment Available
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white py-2">
                    <i class="fas fa-chart-pie me-2"></i>
                    Subscription Summary
                </div>
                <div class="card-body p-3">
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon-circle icon-primary">
                            <i class="fas fa-crown fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Plan</small>
                            <strong><?=htmlspecialchars($plan_name)?></strong>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon-circle icon-success">
                            <i class="fas fa-tag fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Billing</small>
                            <strong><?=htmlspecialchars($billing_type)?></strong>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon-circle icon-warning">
                            <i class="fas fa-check-circle fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Status</small>
                            <span class="badge bg-<?=$statusColor?>"><?=htmlspecialchars($status)?></span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="icon-circle icon-danger">
                            <i class="fas fa-calendar-times fa-sm"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">Expiry</small>
                            <strong><?=htmlspecialchars($end_date)?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white py-2">
                    <i class="fas fa-coins me-2"></i>
                    Outstanding Balance
                </div>
                <div class="card-body p-3 text-center">
                    <div class="display-6 fw-bold text-danger mb-2">
                        ₹ <?=number_format($outstanding,2)?>
                    </div>
                    <div class="progress mb-2" style="height: 6px;">
                        <div class="progress-bar bg-danger" style="width: <?=($outstanding > 0) ? min(100, ($outstanding/10000)*100) : 0?>%"></div>
                    </div>
                    <small class="text-muted d-block mb-2">
                        <?=($outstanding > 0) ? 'Please clear your outstanding balance' : 'All payments up to date!'?>
                    </small>
                    <a href="payments.php?invoice_id=<?=$last_invoice_id?>" class="btn btn-danger w-100">
    <i class="fas fa-credit-card me-2"></i>Pay Outstanding
</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(function(){
    $('[data-bs-toggle="tooltip"]').tooltip();
});
</script>
</body>
</html>