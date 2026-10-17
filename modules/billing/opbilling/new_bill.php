<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}
$user_id=(int)$_SESSION['user_id'];
$opd_id=(int)($_GET['opd_id'] ?? 0);
if($opd_id<=0){
    die("Invalid OPD.");
}
/*=========================================
GET OPD DETAILS
=========================================*/
$stmt=$conn->prepare("
SELECT
a.*,
p.uhid,
c.name AS city_name,
u.full_name AS doctor_name,
b.branch_name,
d.department_name,
em.empanelment_name
FROM appointments a
LEFT JOIN patient_master p ON p.id=a.patient_id
LEFT JOIN cities c ON c.id=a.city_id
LEFT JOIN users u
ON u.id=a.entity_id
LEFT JOIN branch_master b
ON b.id=u.branch_id
LEFT JOIN department_master d
ON d.id=a.department_id
LEFT JOIN empanelment_master em
ON em.id=a.empanelment_id
WHERE a.id=?
LIMIT 1
");
$stmt->bind_param("i",$opd_id);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows==0){
    die("OPD Record not found.");
}
$row=$result->fetch_assoc();
$stmt->close();

/*=========================================
LOAD SERVICE CATEGORIES
=========================================*/
$categories=[];

$res=mysqli_query($conn,"
SELECT DISTINCT
category_type
FROM service_master
WHERE
status='Active'
AND category_type IS NOT NULL
AND category_type<>''
ORDER BY category_type
");

while($cat=mysqli_fetch_assoc($res)){
    $categories[]=$cat;
}

$discounts = mysqli_query($conn,"
SELECT
    id,
    discount_name,
    receipt_type,
    receipt_value
FROM discount_master
WHERE
    status='Active'
    AND receipt_type IS NOT NULL
ORDER BY discount_name
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OPD Billing</title>
  <!-- Font Awesome (optional but keeps icons) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="icon" href="/assets/img/hms_logo.png">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      background: #eef2f7;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 13px;
      padding: 16px;
      color: #1e293b;
    }

    .wrapper {
      max-width: 1500px;
      margin: 0 auto;
    }

    /* ----- CARD (same as original) ----- */
    .card {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 8px 20px rgba(0,0,0,.08);
      margin-bottom: 14px;
      overflow: hidden;
    }

    .card-header {
      background: #1976d2;   /* original blue */
      color: #fff;
      padding: 10px 16px;
      font-size: 15px;
      font-weight: bold;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .card-header i {
      margin-right: 6px;
    }

    .card-body {
      padding: 14px 16px;
    }

    /* ----- patient table (compact) ----- */
    .patient-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12.5px;
    }

    .patient-table td {
      border: 1px solid #e5e7eb;
      padding: 5px 8px;
    }

    .patient-table td.label {
      background: #f8fafc;
      font-weight: bold;
      width: 90px;
      white-space: nowrap;
    }

    /* ----- service layout (compact) ----- */
    .service-layout {
      display: grid;
      grid-template-columns: 320px 1fr;
      gap: 14px;
      margin-top: 6px;
    }

    .left-panel {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 5px 15px rgba(0,0,0,.08);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      width: 28%;
    display: inline-block;
    float: left;
    margin-right: 12px;
    }

    .panel-header {
      background: #0d6efd;   /* slightly different but keeps original style */
      color: #fff;
      padding: 8px 14px;
      font-weight: bold;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 2px;
    }
table#selectedServiceTable tr td {
    font-size: 14px !important;
}
    .panel-body {
      padding: 10px 12px;
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      max-height: 94vh;
      min-height: 94vh;
    }

    .filter-group {
      display: flex;
      gap: 8px;
      margin-bottom: 8px;
      flex-wrap: wrap;
    }

    .filter-group select,
    .filter-group input {
      flex: 1;
      min-width: 80px;
      padding: 5px 10px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 12px;
      background: #fff;
    }

    .service-list {
      flex: 1;
      overflow-y: auto;
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      background: #fafcff;
    }

    .service-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 12px;
      border-bottom: 1px solid #eee;
      cursor: default;
    }

    .service-item:hover {
      background: #f5f8ff;
    }

    .service-item .info {
      font-size: 12.5px;
    }

    .service-item .info b {
      font-weight: 600;
    }

    .service-item .info small {
      display: block;
      color: #475569;
      font-size: 11px;
      margin-top: 1px;
    }

    .add-btn {
      background: #28a745;
      color: #fff;
      border: none;
      width: 26px;
      height: 26px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 15px;
      font-weight: bold;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: 0.15s;
    }

    .add-btn:hover {
      background: #1e7e34;
    }

    /* ----- right panel (selected) ----- */
    .right-panel {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 5px 15px rgba(0,0,0,.08);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      max-height: 540px;
      width: 70%;
    }

    .table-wrap {
      overflow: auto;
      flex: 1;
      padding: 0 4px 4px 4px;
          min-height: 300px;
 max-height: 300px;
          
          
    }

    #selectedServiceTable {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
      min-width: 780px;
    }

    #selectedServiceTable thead {
      position: sticky;
      top: 0;
      z-index: 5;
    }

    #selectedServiceTable th {
      background: #1976d2;
      color: #fff;
      padding: 8px 6px;
      font-weight: bold;
      text-align: left;
      white-space: nowrap;
      border: 1px solid #1a6bb0;
    }

    #selectedServiceTable td {
      padding: 5px 4px;
      border-bottom: 1px solid #e5e7eb;
      vertical-align: middle;
      background: #fff;
    }

    #selectedServiceTable input,
    #selectedServiceTable select {
      border: 1px solid #d1d5db;
      border-radius: 4px;
      padding: 4px 6px;
      font-size: 14.5px;
      background: #fff;
      width: 100%;
      transition: 0.1s;
    }

    #selectedServiceTable input:focus,
    #selectedServiceTable select:focus {
      border-color: #1976d2;
      outline: none;
      box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.15);
    }

    #selectedServiceTable input[readonly] {
      background: #f5f5f5;
      border-color: #e0e0e0;
    }

    .removeService {
      background: #dc3545;
      color: #fff;
      border: none;
      border-radius: 4px;
      width: 24px;
      height: 24px;
      cursor: pointer;
      font-size: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: 0.15s;
    }

    .removeService:hover {
      background: #b02a37;
    }

    /* ----- bottom (compact) ----- */
    .bottom-grid{
    display:grid;
    grid-template-columns:350px 340px 330px;
    gap:14px;
        position: relative;
    top: 12px;
}

    .remarks-box {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 5px 15px rgba(0,0,0,.08);
      padding: 14px 16px;
    }

    .remarks-box h3 {
      font-size: 13px;
      font-weight: bold;
      margin-bottom: 8px;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .remarks-box textarea {
      width: 100%;
      height: 300px;
      resize: vertical;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      padding: 8px 10px;
      font-family: Arial, sans-serif;
      font-size: 12px;
      background: #fafcff;
    }

    .summary-box {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 5px 15px rgba(0,0,0,.08);
      padding: 14px 16px;
    }

    .summary-box h3 {
      font-size: 13px;
      font-weight: bold;
      margin-bottom: 12px;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .summary-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 12px;
    }
.form-control{
    width:100%;
    padding:8px;
    border:1px solid #d1d5db;
    border-radius:6px;
    margin-top:4px;
    font-size:12px;
    margin-bottom:8px;
}
    .summary-grid .full {
      grid-column: 1 / -1;
    }

    .summary-grid label {
      font-size: 10px;
      font-weight: bold;
      color: #475569;
      display: block;
      margin-bottom: 1px;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }

    .summary-grid input,
    .summary-grid select {
      width: 100%;
      padding: 5px 8px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 12px;
      background: #fff;
    }

    .summary-grid input[readonly] {
      background: #f5f5f5;
    }

    .save-btn-wrap {
      grid-column: 1 / -1;
      margin-top: 10px;
      text-align: center;
    }

    .save-btn {
      background: #198754;
      color: #fff;
      border: none;
      padding: 10px 28px;
      border-radius: 8px;
      font-weight: bold;
      font-size: 14px;
      cursor: pointer;
      transition: 0.15s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .save-btn:hover {
      background: #146c43;
    }

    /* responsive */
    @media (max-width: 1000px) {
      .service-layout {
        grid-template-columns: 1fr;
        gap: 12px;
      }
      .bottom-grid {
        grid-template-columns: 1fr;
      }
      .left-panel, .right-panel {
        max-height: 400px;
      }
    }

    @media (max-width: 600px) {
      .patient-table td {
        padding: 4px 6px;
        font-size: 11px;
      }
      .patient-table td.label {
        width: 60px;
      }
    }

    ::-webkit-scrollbar {
      width: 5px;
      height: 5px;
    }
    ::-webkit-scrollbar-track {
      background: #eef2f7;
      border-radius: 8px;
    }
    ::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 8px;
    }
  </style>
</head>
<body>
<form id="billForm" method="post" action="save_bill.php">
    <input type="hidden" name="opd_id" value="<?= $opd_id ?>">
<input type="hidden" name="patient_id" value="<?= $row['patient_id'] ?>">
<input type="hidden" name="doctor_id" value="<?= $row['entity_id'] ?>">
<input type="hidden" name="create_receipt" id="create_receipt" value="0">
<input type="hidden" name="department_id" value="<?= $row['department_id'] ?>">
<input type="hidden" name="empanelment_id" value="<?= $row['empanelment_id'] ?>">

<input type="hidden" name="user_id" value="<?= $user_id ?>">
<div class="wrapper">

  <!-- ====== HEADER CARD (original style) ====== -->
  <div class="card">
    <div class="card-header">
    <span><i class="fas fa-coins"></i> OPD Billing</span>
</div>
    <div class="card-body" style="padding:10px 16px;">
      <table class="patient-table">
        <tr>
          <td class="label">OPD No.</td>
          <td><?= htmlspecialchars($row['opd_no'] ?? '') ?></td>
          <td class="label">UHID</td>
          <td><?= htmlspecialchars($row['uhid'] ?? '') ?></td>
          <td class="label">Patient</td>
          <td><?= htmlspecialchars(trim(($row['title'] ?? '').' '.($row['first_name'] ?? '').' '.($row['last_name'] ?? ''))) ?></td>
          <td class="label">Age</td>
          <td><?= (int)($row['age_y'] ?? 0) ?>Y <?= (int)($row['age_m'] ?? 0) ?>M <?= (int)($row['age_d'] ?? 0) ?>D</td>
        </tr>
        <tr>
          <td class="label">Gender</td>
          <td><?= htmlspecialchars($row['sex'] ?? '') ?></td>
          <td class="label">Mobile</td>
          <td><?= htmlspecialchars($row['mobile'] ?? '') ?></td>
          <td class="label">City</td>
          <td><?= htmlspecialchars($row['city_name'] ?? '') ?></td>
          <td class="label">Doctor</td>
          <td><?= htmlspecialchars($row['doctor_name'] ?? '') ?></td>
        </tr>
        <tr>
          <td class="label">Department</td>
          <td><?= htmlspecialchars($row['department_name'] ?? '') ?></td>
          <td class="label">Empanelment</td>
          <td><?= htmlspecialchars($row['empanelment_name'] ?? '') ?></td>
          <td class="label">Marital Status</td>
          <td><?= htmlspecialchars($row['marital_status'] ?? '') ?></td>
          <td class="label">Date</td>
          <td><?= date("d-m-Y", strtotime($row['appointment_date'] ?? 'now')) ?></td>
        </tr>
      </table>
    </div>
  </div>

  <!-- ====== SERVICE LAYOUT ====== -->
 

    <!-- LEFT: Available Services -->
    <div class="left-panel">
      <div class="panel-header"><i class="fas fa-list-ul"></i> Available Services</div>
      <div class="panel-body">
        <div class="filter-group">
          <select id="categoryFilter" onchange="filterCategory()">
            <option value="">All Categories</option>
            <?php foreach($categories as $cat){ ?>
              <option value="<?= htmlspecialchars($cat['category_type'] ?? '') ?>"><?= htmlspecialchars($cat['category_type'] ?? '') ?></option>
            <?php } ?>
          </select>
          <input type="text" id="serviceSearch" placeholder="Search..." onkeyup="filterServices()">
        </div>
        <div class="service-list" id="service_list">
          <?php
          $serviceRes = mysqli_query($conn, "SELECT id, service_name, amount, category_type FROM service_master WHERE status='Active' ORDER BY service_name");
          while($service = mysqli_fetch_assoc($serviceRes)){ ?>
            <div class="service-item" data-category="<?= htmlspecialchars($service['category_type']) ?>">
              <div class="info">
                <b><?= htmlspecialchars($service['service_name']) ?></b>
                <small>₹ <?= number_format($service['amount'], 2) ?></small>
              </div>
              <button type="button" class="add-btn" data-id="<?= $service['id'] ?>" data-name="<?= htmlspecialchars($service['service_name']) ?>" data-charge="<?= $service['amount'] ?>">+</button>
            </div>
          <?php } ?>
        </div>
      </div>
    </div>

    <!-- RIGHT: Selected Services -->
    <div class="right-panel">
      <div class="panel-header"><i class="fas fa-clipboard-list"></i> Selected Services</div>
      <div class="table-wrap">
        <table id="selectedServiceTable">
          <thead>
              <tr>
              <th style="min-width:120px;">Service</th>
              <th style="width:90px;">Charges</th>
              <th style="width:90px;">Unit</th>
              <th style="width:90px;">Emr. Chrg</th>
              <th style="width:90px;">Disc. Type</th>
              <th style="width:90px;">Disc %</th>
              <th style="width:90px;">Disc Amt</th>
              <th style="width:90px;">Net Amt</th>
              <th style="width:90px;">Bal Amt</th>
              <th style="width:90px;">Del</th>
            </tr>
          </thead>
          <tbody id="selectedServicesBody">
            <?php
            $stmt = $conn->prepare("
SELECT
    ps.service_id,
    sm.service_name,
    sm.amount
FROM prescribed_services ps
INNER JOIN service_master sm
    ON sm.id = ps.service_id
WHERE
    ps.appointment_id = ?
AND ps.billing_status = 'Pending'
AND NOT EXISTS(
    SELECT 1
    FROM opd_bill_details obd
    INNER JOIN opd_bills ob
        ON ob.id = obd.bill_id
    WHERE
        ob.opd_id = ps.appointment_id
    AND obd.service_id = ps.service_id
    AND obd.status = 'Active'
    AND ob.status NOT IN('Cancelled','Refunded')
)
");
$stmt->bind_param("i", $opd_id);
$stmt->execute();
$res = $stmt->get_result();
while($srv = $res->fetch_assoc()){ ?>
            <tr>
              <td>
    <input type="hidden" name="service_id[]" value="<?= $srv['service_id'] ?>">
    <?= htmlspecialchars($srv['service_name'] ?? '') ?>
</td>
              <td><input type="number" class="charges" name="charges[]" value="<?= $srv['amount'] ?>" style="width:90px;"></td>
              <td><input type="number" class="unit" name="unit[]" value="1" min="1" style="width:48px;"></td>
              <td><input type="number" class="emr_charge" name="emr_charge[]" value="0" style="width:90px;"></td>
              <td><select class="discount_type" name="discount_type[]" disabled style="width:90px;"><option value="None">None</option></select></td>
                <td><input type="number" class="discount_percent" name="discount_percent[]" value="0" readonly style="width:90px;background:#f5f5f5;"></td>
                <td><input type="number" class="discount_amount" name="discount_amount[]" value="0" readonly style="width:90px;background:#f5f5f5;"></td>
              <td><input type="number" class="net_amount" name="net_amount[]" value="<?= $srv['amount'] ?>" readonly style="width:100px;background:#f5f5f5;"></td>
              <td><input type="number" class="balance_amount" name="balance_amount[]" value="<?= $srv['amount'] ?>" readonly style="width:100px;background:#f5f5f5;"></td>
              <td><button type="button" class="removeService"><i class="fas fa-times"></i></button></td>
            </tr>
            <?php
}
$stmt->close();
?>
          </tbody>
        </table>
      </div>
    </div>
 
 
 
 
 
  <!-- ====== BOTTOM: Remarks + Summary ====== -->
  <div class="bottom-grid">
    <div class="remarks-box">
      <h3><i class="far fa-sticky-note"></i> Remarks</h3>
      <textarea name="remarks" id="remarks" placeholder="Add notes..."></textarea>
    </div>


<div class="remarks-box">
    <h3><i class="fas fa-money-check-alt"></i> Payment Details</h3>

    <!-- ADD THIS -->
    <div style="margin-bottom:10px;">
        <label style="font-size:12px;font-weight:bold;">Receipt Date</label>
        <input
            type="datetime-local"
            name="receipt_date"
            required
            class="form-control"
            value="<?= date('Y-m-d\TH:i', strtotime('+5 minutes')) ?>">
    </div>
    
    <div style="margin-bottom:10px;">
        <label style="font-size:12px;font-weight:bold;">Payment Mode</label>
        <select id="payment_mode" name="payment_mode" style="width:100%;padding:8px;" onchange="togglePaymentFields()">
            <option value="Cash">Cash</option>
            <option value="UPI">UPI</option>
            <option value="Card">Card</option>
            <option value="Cheque">Cheque</option>
            <option value="NEFT">NEFT</option>
            <option value="Credit">Credit (Due)</option>
            <option value="ECS">ECS</option>
        </select>
    </div>

    <!-- Cash -->
    <div id="cashFields" class="paymentFields">
        <label style="font-size:12px;font-weight:bold;">Remarks</label>
        <input type="text" name="cash_remarks" class="form-control" placeholder="Cash Remarks">
    </div>

    <!-- UPI -->
    <div id="upiFields" class="paymentFields" style="display:none;">
        <label style="font-size:12px;font-weight:bold;">UPI App</label>
        <select name="upi_app" class="form-control">
            <option value="">Select</option>
            <option>PhonePe</option>
            <option>Google Pay</option>
            <option>Paytm</option>
            <option>BHIM</option>
            <option>Other</option>
        </select>

        <label style="margin-top:8px;font-size:12px;font-weight:bold;">Transaction ID</label>
        <input type="text" name="upi_txn_id" class="form-control">
    </div>

    <!-- Card -->
<div id="cardFields" class="paymentFields" style="display:none;">

    <label style="font-size:12px;font-weight:bold;">Card Type</label>
    <select name="card_type" class="form-control">
        <option value="">Select Card Type</option>
        <option value="Credit Card">Credit Card</option>
        <option value="Debit Card">Debit Card</option>
    </select>

    <label style="margin-top:8px;font-size:12px;font-weight:bold;">Bank Name</label>
    <input type="text" name="card_bank_name" class="form-control" placeholder="Enter Bank Name">

    <label style="margin-top:8px;font-size:12px;font-weight:bold;">Last 4 Digits</label>
    <input type="text" name="card_last4" class="form-control" maxlength="4" placeholder="1234">

    <label style="margin-top:8px;font-size:12px;font-weight:bold;">Approval / Auth Code</label>
    <input type="text" name="card_auth_code" class="form-control" placeholder="POS Approval Code">

</div>

    <!-- Cheque -->
    <div id="chequeFields" class="paymentFields" style="display:none;">
        <label style="font-size:12px;font-weight:bold;">Cheque No.</label>
        <input type="text" name="cheque_no" class="form-control">

        <label style="margin-top:8px;font-size:12px;font-weight:bold;">Bank Name</label>
        <input type="text" name="cheque_bank" class="form-control">

        <label style="margin-top:8px;font-size:12px;font-weight:bold;">Cheque Date</label>
        <input type="date" name="cheque_date" class="form-control">
    </div>

    <!-- NEFT -->
    <div id="neftFields" class="paymentFields" style="display:none;">
        <label style="font-size:12px;font-weight:bold;">Reference No.</label>
        <input type="text" name="neft_ref" class="form-control">

        <label style="margin-top:8px;font-size:12px;font-weight:bold;">Bank Name</label>
        <input type="text" name="neft_bank" class="form-control">
    </div>

    <!-- Credit -->
    <div id="creditFields" class="paymentFields" style="display:none;">
        <label style="font-size:12px;font-weight:bold;">Credit Days</label>
        <input type="number" name="credit_days" class="form-control">

        <label style="margin-top:8px;font-size:12px;font-weight:bold;">Approval By</label>
        <input type="text" name="approved_by" class="form-control">
    </div>


<!-- ECS -->
<div id="ecsFields" class="paymentFields" style="display:none;">
    <label style="font-size:12px;font-weight:bold;">Bank Name</label>
    <input type="text" name="ecs_bank_name" class="form-control" placeholder="Enter Bank Name">
    <label style="margin-top:8px;font-size:12px;font-weight:bold;">Reference No.</label>
    <input type="text" name="ecs_reference_no" class="form-control" placeholder="Enter ECS Reference No.">
    <label style="margin-top:8px;font-size:12px;font-weight:bold;">Transaction Date</label>
    <input type="date" name="ecs_transaction_date" class="form-control">
</div>


</div>
    <div class="summary-box">
      <h3><i class="fas fa-calculator"></i> Billing Summary</h3>
      <div class="summary-grid">
        <div class="full"><label>Total</label><input type="number" id="grand_total" name="grand_total" value="0" readonly></div>
        <div>
    <label>Discount</label>

    <select id="bill_discount_master" name="bill_discount_master">
        <option value="">No Discount</option>

        <?php while($d = mysqli_fetch_assoc($discounts)){ ?>

        <option
            value="<?= $d['id'] ?>"
            data-name="<?= htmlspecialchars($d['discount_name']) ?>"
            data-type="<?= $d['receipt_type'] ?>"
            data-value="<?= $d['receipt_value'] ?>">
            <?= htmlspecialchars($d['discount_name']) ?>
        </option>

        <?php } ?>

    </select>

</div>
        <div><label>Disc Amt</label><input type="number"
       id="bill_discount_amount"
       name="bill_discount_amount"
       value="0"
       readonly></div>
        <div class="full"><label>Net Payable</label><input type="number" id="net_payable" name="net_payable" value="0" readonly></div>
        <div><label>Received</label><input type="number" id="received_amount" name="received_amount" value="0"></div>
        <div><label>Balance</label><input type="number" id="balance_amount" name="balance_amount" value="0" readonly></div>
        <div class="full"><label>Paid Amount</label><input type="number" id="paid_amount" name="paid_amount" value="0" readonly></div>
        <div class="save-btn-wrap">
          <button type="button" id="saveBill" class="save-btn"><i class="fas fa-save"></i> Save Bill</button>
        </div>
      </div>
    </div>
  </div>
  
  
    
    
    
  </div>
<br>
 
  
  
  
</div>
</form>

<script>
  /* =============================================
     All billing logic (compact, same as before)
  ============================================= */
function calculateBill() {
    let grandTotal = 0;
    document.querySelectorAll('#selectedServicesBody tr').forEach(row => {
        let charge = parseFloat(row.querySelector('.charges')?.value) || 0;
        let qty = parseFloat(row.querySelector('.unit')?.value) || 1;
        let emr = parseFloat(row.querySelector('.emr_charge')?.value) || 0;
        grandTotal += (charge * qty) + emr;
    });
    document.getElementById('grand_total').value = grandTotal.toFixed(2);
    let discount = document.getElementById('bill_discount_master');
    let selected = discount.options[discount.selectedIndex];
    let discType = selected.dataset.type || "";
    let discValue = parseFloat(selected.dataset.value || 0);
    let totalDiscount = 0;
    let rows = Array.from(document.querySelectorAll('#selectedServicesBody tr'));
    if (discType === "Percentage") {
        rows.forEach(row => {
            let charge = parseFloat(row.querySelector('.charges')?.value) || 0;
            let qty = parseFloat(row.querySelector('.unit')?.value) || 1;
            let emr = parseFloat(row.querySelector('.emr_charge')?.value) || 0;
            let itemTotal = (charge * qty) + emr;
            let itemDiscount = (itemTotal * discValue) / 100;
            totalDiscount += itemDiscount;
            row.querySelector('.discount_type').innerHTML = '<option value="Percentage">Percentage</option>';
            row.querySelector('.discount_type').value = "Percentage";
            row.querySelector('.discount_percent').value = discValue.toFixed(2);
            row.querySelector('.discount_amount').value = itemDiscount.toFixed(2);
            let net = itemTotal - itemDiscount;
            if (net < 0) net = 0;
            row.querySelector('.net_amount').value = net.toFixed(2);
            row.querySelector('.balance_amount').value = net.toFixed(2);
        });
        totalDiscount = Math.min(totalDiscount, grandTotal);
    } else if (discType === "Flat") {
        totalDiscount = Math.min(discValue, grandTotal);
        let allocatedDiscount = 0;
        rows.forEach((row, index) => {
            let charge = parseFloat(row.querySelector('.charges')?.value) || 0;
            let qty = parseFloat(row.querySelector('.unit')?.value) || 1;
            let emr = parseFloat(row.querySelector('.emr_charge')?.value) || 0;
            let itemTotal = (charge * qty) + emr;
            let itemDiscount = 0;
            if (grandTotal > 0) {
                if (index === rows.length - 1) {
                    itemDiscount = totalDiscount - allocatedDiscount;
                } else {
                    itemDiscount = (itemTotal / grandTotal) * totalDiscount;
                    itemDiscount = Math.round(itemDiscount * 100) / 100;
                }
            }
            if (itemDiscount > itemTotal) {
                itemDiscount = itemTotal;
            }
            allocatedDiscount += itemDiscount;
            row.querySelector('.discount_type').innerHTML = '<option value="Flat">Flat</option>';
            row.querySelector('.discount_type').value = "Flat";
            row.querySelector('.discount_percent').value = "0";
            row.querySelector('.discount_amount').value = itemDiscount.toFixed(2);
            let net = itemTotal - itemDiscount;
            if (net < 0) net = 0;
            row.querySelector('.net_amount').value = net.toFixed(2);
            row.querySelector('.balance_amount').value = net.toFixed(2);
        });
    } else {
        rows.forEach(row => {
            let charge = parseFloat(row.querySelector('.charges')?.value) || 0;
            let qty = parseFloat(row.querySelector('.unit')?.value) || 1;
            let emr = parseFloat(row.querySelector('.emr_charge')?.value) || 0;
            let itemTotal = (charge * qty) + emr;
            row.querySelector('.discount_type').innerHTML = '<option value="None">None</option>';
            row.querySelector('.discount_type').value = "None";
            row.querySelector('.discount_percent').value = "0";
            row.querySelector('.discount_amount').value = "0.00";
            row.querySelector('.net_amount').value = itemTotal.toFixed(2);
            row.querySelector('.balance_amount').value = itemTotal.toFixed(2);
        });
    }
    document.getElementById('bill_discount_amount').value = totalDiscount.toFixed(2);
    let net = grandTotal - totalDiscount;
    if (net < 0) net = 0;
    document.getElementById('net_payable').value = net.toFixed(2);
    let received = parseFloat(document.getElementById('received_amount').value) || 0;
    if (received > net) {
        received = net;
        document.getElementById('received_amount').value = net.toFixed(2);
    }
    let balance = net - received;
    if (balance < 0) balance = 0;
    document.getElementById('balance_amount').value = balance.toFixed(2);
    document.getElementById('paid_amount').value = received.toFixed(2);
    document.getElementById('create_receipt').value = received > 0 ? "1" : "0";
}
  // event delegation
  document.addEventListener('input', function(e) {
    if (e.target.classList.contains('charges') || e.target.classList.contains('unit') ||
    e.target.classList.contains('emr_charge')) {
      let row = e.target.closest('tr');
      if (row) calculateRow(row);
    }
    if (e.target.id === 'received_amount') {
    calculateBill();
}
  });

  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('discount_type')) {
      let row = e.target.closest('tr');
      if (row) calculateRow(row);
    }
    if (e.target.id === 'bill_discount_master') {
    calculateBill();
}
  });

  // remove service
  document.addEventListener('click', function(e) {
    if (e.target.closest('.removeService')) {
      if (confirm('Remove this service?')) {
        e.target.closest('tr').remove();
        calculateBill();
      }
    }
  });

  // add service
  document.addEventListener('click', function(e) {
    let btn = e.target.closest('.add-btn');
    if (!btn) return;
    let id = btn.dataset.id;
    let name = btn.dataset.name;
    let charge = btn.dataset.charge;
    let tbody = document.getElementById('selectedServicesBody');
// avoid duplicate by service id
let existing = Array.from(
    tbody.querySelectorAll('input[name="service_id[]"]')
).find(input => input.value == id);

if (existing) {
    let row = existing.closest('tr');
    let unitInput = row.querySelector('.unit');

    unitInput.value = parseInt(unitInput.value || 1) + 1;

    calculateRow(row);
    calculateBill();

    return;
}
    let html = `
      <tr>
        <td><input type="hidden" name="service_id[]" value="${id}">${name}</td>
        <td><input type="number" class="charges" name="charges[]" value="${charge}" style="width:65px;"></td>
        <td><input type="number" class="unit" name="unit[]" value="1" min="1" style="width:48px;"></td>
        <td><input type="number" class="emr_charge" name="emr_charge[]" value="0" style="width:65px;"></td>
       <td><select class="discount_type" name="discount_type[]" disabled style="width:85px;"><option value="None">None</option></select></td>
<td><input type="number" class="discount_percent" name="discount_percent[]" value="0" readonly style="width:55px;background:#f5f5f5;"></td>
<td><input type="number" class="discount_amount" name="discount_amount[]" value="0" readonly style="width:65px;background:#f5f5f5;"></td>
<td><input type="number" class="net_amount" name="net_amount[]" value="${charge}" readonly style="width:70px;background:#f5f5f5;"></td>
        <td><input type="number" class="balance_amount" name="balance_amount[]" value="${charge}" readonly style="width:70px;background:#f5f5f5;"></td>
        <td><button type="button" class="removeService"><i class="fas fa-times"></i></button></td>
      </tr>
    `;
    tbody.insertAdjacentHTML('beforeend', html);
    let newRow = tbody.lastElementChild;
    calculateRow(newRow);
    calculateBill();
  });

  // filter functions
  function filterServices() {
    let input = document.getElementById('serviceSearch').value.toLowerCase();
    document.querySelectorAll('.service-item').forEach(item => {
      let txt = item.innerText.toLowerCase();
      item.style.display = txt.indexOf(input) > -1 ? '' : 'none';
    });
  }

  function filterCategory() {
    let cat = document.getElementById('categoryFilter').value;
    document.querySelectorAll('.service-item').forEach(item => {
      let match = cat === '' || item.dataset.category === cat;
      item.style.display = match ? '' : 'none';
    });
  }

  // init
  document.querySelectorAll('#selectedServicesBody tr').forEach(row => calculateRow(row));
  calculateBill();

  // Save Bill
document.getElementById('saveBill').addEventListener('click', function () {

    calculateBill();

    let receivedAmount = parseFloat(document.getElementById('received_amount').value.trim() || 0);
    let netPayable = parseFloat(document.getElementById('net_payable').value || 0);

    let paymentMode = document.getElementById('payment_mode').value;

if (paymentMode !== "Credit" && receivedAmount <= 0) {
    alert("Received Amount should be greater than 0.");
    document.getElementById('received_amount').focus();
    return;
}

if (receivedAmount < 0) {
    alert("Received Amount can't be less than 0.");
    document.getElementById('received_amount').focus();
    return;
}

    if (receivedAmount > netPayable) {
        alert("Received Amount can't be greater than Net Payable.");
        document.getElementById('received_amount').focus();
        return;
    }

    if (document.querySelectorAll('#selectedServicesBody tr').length === 0) {
        alert('Please add at least one service.');
        return;
    }

    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    document.getElementById('billForm').submit();
});
function togglePaymentFields(){

    document.querySelectorAll('.paymentFields').forEach(function(div){
        div.style.display='none';
    });

    let mode=document.getElementById('payment_mode').value;

    if(mode==='Cash')
        document.getElementById('cashFields').style.display='block';

    if(mode==='UPI')
        document.getElementById('upiFields').style.display='block';

    if(mode==='Card')
        document.getElementById('cardFields').style.display='block';

    if(mode==='Cheque')
        document.getElementById('chequeFields').style.display='block';

    if(mode==='NEFT')
        document.getElementById('neftFields').style.display='block';

    if(mode==='Credit')
        document.getElementById('creditFields').style.display='block';
        
    if(mode==='ECS')
    document.getElementById('ecsFields').style.display='block';
    
    const receivedField = document.getElementById('received_amount');

if(mode === 'Credit'){
    receivedField.value = 0;
    receivedField.readOnly = true;
    calculateBill();
}
else{
    receivedField.readOnly = false;

    if(receivedField.value == "0"){
        receivedField.focus();
    }
}
}

togglePaymentFields();
</script>
</body>
</html>