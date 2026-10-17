<!--public_html/modules/pharmacy/sale_bill.php  -->
<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

if (!checkPermission('pharmacy_sales', 'view')) {
    header('Location: /access_denied.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$branches=$conn->query("
SELECT id,branch_name
FROM branch_master
WHERE status=1
ORDER BY branch_name ASC
");

if (!($branches instanceof mysqli_result)) {
    header('Location: /access_denied.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pharmacy Sale Bill</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.card{
    background:#fff;
    padding:0px 20px 2px 20px;
    border-radius:10px;
}
.form-grid{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:2px 15px;
}
.form-group{
    display:flex;
    flex-direction:column;
}
.form-group input
{
    padding: 6px; !important
}
.form-group.full-width{
    grid-column:span 2;
}
.form-group label{
    margin-bottom:5px;
    font-weight:600;
}
.prescription-section{
    display:none;
}
.table-responsive{
    height:calc(100vh - 510px);
    min-height:300px;
    overflow-y:auto;
    overflow-x:auto;
    border:1px solid #ddd;
    background:#fff;
}
.removeRow{
    background:#dc3545 !important;
    color:#fff !important;
    border:1px solid #dc3545 !important;
    padding: 5px 10px;
}

.removeRow:hover{
    background:#c82333 !important;
    color:#fff !important;
}
#itemTable{
    min-width:1600px;
}
#itemTable th,
#itemTable td{
    white-space:nowrap;
    vertical-align:middle;
}
#itemTable input,
#itemTable select{
    min-width:100px;
}
.item-name{
    min-width:220px !important;
}
.patient-name-group{
    grid-column:span 2;
}
.patient-name-wrapper{
    display:flex;
    gap:5px;
    align-items:center;
}
.patient-name-wrapper .patient-title{
    width:95px;
    min-width:95px;
}
.patient-name-wrapper #patient_name{
    flex:1;
}
.patient-name-wrapper .patient-search-btn{
    width:38px;
    min-width:38px;
    height:30px;
}
.qty-field{
    width:80px;
    min-width:80px !important;
}
.rate-field{
    width:100px;
}
.readonly-field{
    background:#f3f4f6;
}
.barcode-box{
    display:flex;
    align-items:center;
    gap:20px;
    margin-bottom:2px;
}
.barcode-box label{
    font-weight:600;
    min-width:130px;
}
.barcode-box input{
    width:300px;
    height:38px;
}
.add-item-btn{
    min-width:28px;
    width:28px;
    height:28px;
    padding:0;
    font-size:20px;
    line-height:20px;
}
.payment-grid{
    display:grid;
    grid-template-columns:repeat(8, minmax(0,1fr));
    gap:10px;
}
.paymentFields{
    display:none;
}

.paymentFields.active{
    display:contents;
}
.sticky-footer{
    position:fixed;
    bottom:0;
    left:260px;
    right:0;
    background:#fff;
    border-top:2px solid #1976d2;
    padding:10px 15px;
    display:flex;
    gap:15px;
    justify-content:flex-end;
    align-items:center;
    z-index:9999;
    box-shadow:0 -2px 10px rgba(0,0,0,.15);
}
.footer-box{
    display:flex;
    flex-direction:column;
    min-width:135px;
}
.footer-box label{
    font-size:12px;
    font-weight:bold;
    margin-bottom:5px;
}
.footer-box input{
    padding:8px;
    border:1px solid #ccc;
    border-radius:5px;
    background:#f9fafb;
}
.grand-total{
    background:#1976d2 !important;
    color:#fff;
    font-weight:bold;
}
.patient-search-box{
    display:flex;
    gap:5px;
    align-items:center;
}
.patient-search-box input{
    flex:1;
}
.patient-search-btn{
    width:38px;
    height:38px;
    border:none;
    background:#1976d2;
    color:#fff;
    border-radius:4px;
    cursor:pointer;
    font-size:16px;
}
.patient-search-btn:hover{
    opacity:.9;
}
.patient-modal{
    display:none;
    position:fixed;
    z-index:10000;
    left:0;
    top:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,.5);
    overflow:auto;
}
.patient-modal-content{
    background:#fff;
    width:80%;
    max-width:1100px;
    margin:60px auto;
    border-radius:8px;
    padding:20px;
    position:relative;
}
.patient-modal-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:15px;
}
.patient-modal-header h3{
    margin:0;
}
.custom-alert-modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.45);
    z-index:99999;
    align-items:center;
    justify-content:center;
}
.custom-alert-box{
    background:#fff;
    width:380px;
    max-width:90%;
    border-radius:10px;
    padding:30px;
    text-align:center;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}
.custom-alert-icon{
    width:50px;
    height:50px;
    margin:0 auto 15px;
    border-radius:50%;
    background:#f8d7da;
    color:#dc3545;
    font-size:30px;
    line-height:50px;
    font-weight:bold;
}
.custom-alert-box h3{
    margin:0 0 10px;
}
.custom-alert-box p{
    margin:0 0 20px;
    color:#666;
}
#customAlertOk{
    background:#dc3545;
    color:#fff;
    border:0;
    border-radius:5px;
    padding:9px 30px;
    cursor:pointer;
}
.close-patient-modal{
    font-size:28px;
    font-weight:bold;
    cursor:pointer;
    border:none;
    background:none;
}
.patient-search-filter{
    width:100%;
    margin-bottom:15px;
    padding:9px 10px;
    border:1px solid #ccc;
    border-radius:4px;
}
.patient-list-table{
    width:100%;
    border-collapse:collapse;
}
.payment-section{
    grid-column:1 / -1;
    margin-top:1px;
    padding:5px;
    border:1px solid #ddd;
    border-radius:8px;
    background:#fff;
}

.payment-section h3{
    margin:0 0 10px 0;
    font-size:16px;
    font-weight:700;
}

.payment-grid{
    display:grid;
    grid-template-columns:repeat(8,minmax(0,1fr));
    gap:10px;
    align-items:end;
}

.payment-field,
.payment-control{
    display:flex;
    flex-direction:column;
    min-width:0;
}

.payment-field label,
.payment-control label{
    margin-bottom:5px;
    font-size:12px;
    font-weight:600;
    white-space:nowrap;
}

.payment-field input,
.payment-field select,
.payment-control input,
.payment-control select{
    width:100%;
    box-sizing:border-box;
    padding:7px;
    height:36px;
    border:1px solid #ccc;
    border-radius:4px;
}

.payment-field input[readonly]{
    background:#f3f4f6;
}

.paymentFields .payment-control{
    display:flex;
    flex-direction:column;
    min-width:0;
}

.payment-status-box{
    padding:8px 10px;
    border-radius:4px;
    background:#f3f4f6;
    font-weight:600;
}
@media(max-width:1400px){
    .payment-grid{
        grid-template-columns:repeat(8,minmax(120px,1fr));
        overflow-x:auto;
    }
}
.payment-status-box{
    padding:8px 10px;
    border-radius:4px;
    background:#f3f4f6;
    font-weight:600;
}
.credit-message{
    display:none;
    align-items:center;
    height:36px;
    padding:0 12px;
    box-sizing:border-box;
    border-radius:4px;
    background:#fff3cd;
    color:#856404;
    border:1px solid #ffe69c;
    color:#856404;
    font-size:12px;
    font-weight:600;
    white-space:nowrap;
    grid-column:span 4;
    align-content : space-evenly;
}
@media(max-width:1200px){
    .payment-grid{
        grid-template-columns:repeat(2,1fr);
    }
}
.patient-list-table th,
.patient-list-table td{
    padding:10px;
    border:1px solid #ddd;
    text-align:left;
}
.patient-list-table thead{
    background:#f1f3f5;
}
.select-patient-btn{
    padding:6px 12px;
}
.referral-dropdown-box{
    width:100%;
    min-width:0;
}
.referral-dropdown-box select{
    width:100%;
    box-sizing:border-box;
}
@media(max-width:1200px){
    .form-grid{
        grid-template-columns:repeat(4,1fr);
    }
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">
<div class="page-wrapper">
<form id="saleForm" method="POST">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
   <input type="hidden" name="patient_id" id="patient_id">
<input type="hidden" name="appointment_id" id="appointment_id">
<input type="hidden" name="admission_id" id="admission_id">
<div class="card">
<h2>Pharmacy Sale Bill</h2>
<div class="form-grid">
<div class="form-group">
<label>Patient Type *</label>
<select name="patient_type" id="patient_type" required>
<option value="">Select</option>
<option value="OPD">OPD</option>
<option value="IPD">IPD</option>
<option value="Direct Visit">Direct Visit</option>
</select>
</div>
<div class="form-group">
<label>Sale Against *</label>
<select name="sale_against" id="sale_against" required disabled>
<option value="">Select Patient Type First</option>
</select>
</div>
<div class="form-group patient-name-group">
<label>Patient Name *</label>
<div class="patient-name-wrapper">
<select name="title" id="title" class="patient-title">
<option value="">Select</option>
<option value="Mr">Mr</option>
<option value="Mrs">Mrs</option>
<option value="Miss">Miss</option>
<option value="Master">Master</option>
<option value="Baby">Baby</option>
<option value="Babyof">Baby of</option>
<option value="Dr">Dr</option>
</select>
<input type="text" name="patient_name" id="patient_name" required>
<button type="button" class="patient-search-btn" id="openPatientModal">
🔍
</button>
</div>
</div>
<div class="form-group prescription-section" id="prescription_group">
<label>Prescription *</label>
<select name="prescription_id" id="prescription_id">
<option value="">Select Prescription</option>
</select>
</div>
<div class="form-group">
<label>Mobile *</label>
<input type="text" name="mobile" id="mobile" maxlength="10" required>
</div>
<div class="form-group">
<label>IPD No / OPD No</label>
<input type="text" name="uhid_op_no" id="uhid_op_no">
</div>
<div class="form-group">
<label>Aadhar Card No</label>
<input type="text" name="aadhar_no" id="aadhar_no" maxlength="12">
</div>
<div class="form-group">
<label>Branch</label>
<select name="branch_id" id="branch_id">
<option value="">Select Branch</option>
<?php while($branch=$branches->fetch_assoc()){ ?>
<option value="<?= $branch['id'] ?>">
<?= htmlspecialchars($branch['branch_name']) ?>
</option>
<?php } ?>
</select>
</div>
<div class="form-group">
<label>Store Name *</label>
<select name="store_id" id="store_id" required>
<option value="">Select Store</option>
</select>
</div>
<div class="form-group">
<label>Referral Type</label>
<select name="referral_type" id="referral_type">
<option value="">Select</option>
<option value="Internal">Internal</option>
<option value="External">External</option>
</select>
</div>

<div class="form-group referral-dropdown-box" id="internal_referral_box" style="display:none;">
<label>Internal Referral</label>
<select name="internal_referral_id" id="internal_referral_id">
<option value="">Select User</option>
</select>
</div>

<div class="form-group referral-dropdown-box" id="external_referral_box" style="display:none;">
<label>External Referral</label>
<select name="external_referral_id" id="external_referral_id">
<option value="">Select Referral</option>
</select>
</div>
<div class="form-group">
<label>Location</label>
<input type="text" name="location" id="location" readonly class="readonly-field">
</div>
<div class="form-group">
<label>Credit Balance</label>
<input type="text" name="credit_balance" id="credit_balance" value="0.00" readonly class="readonly-field">
</div>
<div class="form-group">
<label>Empanelment</label>
<select name="empanelment_id" id="empanelment_id">
<option value="">Select Empanelment</option>
<?php
$empanelments=$conn->query("
    SELECT id,empanelment_name
    FROM empanelment_master
    WHERE status=1
    ORDER BY empanelment_name ASC
");

while($empanelment=$empanelments->fetch_assoc()){
?>
<option value="<?= $empanelment['id'] ?>">
<?= htmlspecialchars($empanelment['empanelment_name']) ?>
</option>
<?php } ?>
</select>
</div>
<div class="form-group full-width">
<label>Remarks</label>
<input type="text" name="remarks" id="remarks">
</div>
<div class="payment-section">
<div class="payment-grid">
<div class="payment-field">
<label>Receipt Date *</label>
<input
type="datetime-local"
name="receipt_date"
id="receipt_date"
required
value="<?= date('Y-m-d\TH:i') ?>">

</div>

<div class="payment-field">

<label>Payment Mode *</label>

<select
name="payment_mode"
id="payment_mode"
required>

<option value="Cash" selected>
Cash
</option>

<option value="UPI">
UPI
</option>

<option value="Card">
Card
</option>

<option value="Cheque">
Cheque
</option>

<option value="NEFT">
NEFT
</option>

<option value="Credit">
Credit (Due)
</option>

<option value="ECS">
ECS
</option>

</select>

</div>

<div class="payment-field">

<label>Received Amount</label>

<input
type="number"
name="received_amount"
id="received_amount"
value="0.00"
min="0"
step="0.01">

</div>

<div class="payment-field">

<label>Balance Amount</label>

<input
type="number"
name="balance_amount"
id="balance_amount"
value="0.00"
readonly>

</div>

<input
type="hidden"
name="payment_status"
id="payment_status"
value="Pending">

<div id="cashFields" class="paymentFields">

<div class="payment-control">

<label>Cash Remarks</label>

<input
type="text"
name="cash_remarks"
id="cash_remarks"
placeholder="Cash Remarks">

</div>

</div>

<div
id="creditMessage"
class="credit-message">

Credit Sale — Payment will be collected later.

</div>

<div id="upiFields" class="paymentFields">

<div class="payment-control">

<label>UPI App</label>

<select
name="upi_app"
id="upi_app">

<option value="">
Select
</option>
<option value="PhonePe">
PhonePe
</option>
<option value="Google Pay">
Google Pay
</option>
<option value="Paytm">
Paytm
</option>
<option value="BHIM">
BHIM
</option>
<option value="Other">
Other
</option>
</select>
</div>
<div class="payment-control">
<label>Transaction ID</label>
<input
type="text"
name="upi_txn_id"
id="upi_txn_id">
</div>
</div>
<div id="cardFields" class="paymentFields">

    <div class="payment-control">
        <label>Card Type</label>
        <select name="card_type" id="card_type">
            <option value="">Select Card Type</option>
            <option value="Credit Card">Credit Card</option>
            <option value="Debit Card">Debit Card</option>
        </select>
    </div>

    <div class="payment-control">
        <label>Bank Name</label>
        <input
            type="text"
            name="card_bank_name"
            id="card_bank_name"
            placeholder="Enter Bank Name">
    </div>

    <div class="payment-control">
        <label>Last 4 Digits</label>
        <input
            type="text"
            name="card_last4"
            id="card_last4"
            maxlength="4"
            placeholder="1234">
    </div>

    <div class="payment-control">
        <label>Approval / Auth Code</label>
        <input
            type="text"
            name="card_auth_code"
            id="card_auth_code"
            placeholder="POS Approval Code">
    </div>

</div>
<div id="chequeFields" class="paymentFields">

    <div class="payment-control">
        <label>Cheque No.</label>
        <input
            type="text"
            name="cheque_no"
            id="cheque_no">
    </div>

    <div class="payment-control">
        <label>Bank Name</label>
        <input
            type="text"
            name="cheque_bank"
            id="cheque_bank">
    </div>

    <div class="payment-control">
        <label>Cheque Date</label>
        <input
            type="date"
            name="cheque_date"
            id="cheque_date">
    </div>

</div>

<div id="neftFields" class="paymentFields">

    <div class="payment-control">
        <label>Reference No.</label>
        <input
            type="text"
            name="neft_ref"
            id="neft_ref">
    </div>

    <div class="payment-control">
        <label>Bank Name</label>
        <input
            type="text"
            name="neft_bank"
            id="neft_bank">
    </div>

</div>

<div id="ecsFields" class="paymentFields">

    <div class="payment-control">
        <label>Bank Name</label>
        <input
            type="text"
            name="ecs_bank_name"
            id="ecs_bank_name"
            placeholder="Enter Bank Name">
    </div>

    <div class="payment-control">
        <label>Reference No.</label>
        <input
            type="text"
            name="ecs_reference_no"
            id="ecs_reference_no"
            placeholder="Enter ECS Reference No.">
    </div>

    <div class="payment-control">
        <label>Transaction Date</label>
        <input
            type="date"
            name="ecs_transaction_date"
            id="ecs_transaction_date">
    </div>

</div>
</div>

</div>
</div>
</div>
<div class="table-responsive">
<table id="itemTable">
<thead>
<tr>
<th>S.No</th>
<th>Item Name</th>
<th><button type="button" class="btn btn-success add-item-btn" id="addRowBtn">+</button></th>
<th>Batch</th>
<th>Exp. Date</th>
<th>Stk Qty.</th>
<th>MRP</th>
<th>Qty.*</th>
<th>Sale Rt.*</th>
<th>Dis(%)</th>
<th>Dis. Val</th>
<th>Amount</th>
<th>GST</th>
<th>GST Val</th>
<th>Net Amt</th>
<th>MRN</th>
</tr>
</thead>
<tbody>
<tr>
<td class="serial_no">1</td>
<td>
<select name="item_id[]" class="item_select item-name">
<option value="">Select Item</option>
</select>
</td>
<td>
<button type="button" class="btn btn-success add-item-btn addItemInline">+</button>
</td>
<td>
<select name="batch_no[]" class="batch_select">
<option value="">Select Batch</option>
</select>
</td>
<td>
<input type="text" name="exp_date[]" class="exp_date readonly-field" readonly>
</td>
<td>
<input type="number" step="0.01" name="stock_qty[]" class="stock_qty readonly-field qty-field" readonly>
</td>
<td>
<input type="number" step="0.01" name="mrp[]" class="mrp readonly-field rate-field" readonly>
</td>
<td>
<input type="number" step="0.01" name="qty[]" class="qty qty-field" min="0">
</td>
<td>
<input type="number" step="0.01" name="sale_rate[]" class="sale_rate rate-field">
</td>
<td>
<input type="number" step="0.01" name="discount_percent[]" class="discount_percent qty-field" value="0">
</td>
<td>
<input type="number" step="0.01" name="discount_value[]" class="discount_value readonly-field rate-field" readonly>
</td>
<td>
<input type="number" step="0.01" name="amount[]" class="amount readonly-field rate-field" readonly>
</td>
<td>
<input type="number" step="0.01" name="gst_percent[]" class="gst_percent readonly-field qty-field" readonly>
</td>
<td>
<input type="number" step="0.01" name="gst_value[]" class="gst_value readonly-field rate-field" readonly>
</td>
<td>
<input type="number" step="0.01" name="net_amount[]" class="net_amount readonly-field rate-field" readonly>
</td>
<td>
<input type="text" name="mrn[]" class="mrn readonly-field" readonly>
</td>
</tr>
</tbody>
</table>
</div>
</div>
<div class="sticky-footer">
<div class="actions">
<button type="button" class="btn btn-primary" id="saveBtn">
Save Sale Bill
</button>
</div>
<div class="footer-box">
<label>Sub Total</label>
<input type="text" name="sub_total" id="sub_total" value="0.00" readonly>
</div>
<div class="footer-box">
<label>Total Discount</label>
<input type="text" name="total_discount" id="total_discount" value="0.00" readonly>
</div>
<div class="footer-box">
<label>Total GST</label>
<input type="text" name="total_gst" id="total_gst" value="0.00" readonly>
</div>
<div class="footer-box">
<label>Round Off</label>
<input type="text" name="round_off" id="round_off" value="0.00" readonly>
</div>
<div class="footer-box">
<label>Grand Total</label>
<input type="text" name="grand_total" id="grand_total" value="0.00" readonly class="grand-total">
</div>
</div>
</form>
</div>
</div>
</div>
<div id="patientModal" class="patient-modal">
<div class="patient-modal-content">
<div class="patient-modal-header">
<h3 id="patientModalTitle">Select Patient</h3>
<button type="button" id="closePatientModal" class="patient-modal-close">&times;</button>
</div>
<div class="patient-modal-body">
<div class="patient-search-box">
<input type="text" id="patientSearchInput" placeholder="Search by Patient Name, Mobile, UHID, OPD/IPD No">
</div>
<div class="patient-table-wrapper">
<table class="patient-list-table">
<thead>
<tr>
<th>Patient Name</th>
<th>Mobile</th>
<th>UHID</th>
<th id="patientNoHeader">OP/IPD No</th>
<th>Aadhar</th>
<th>Location</th>
<th>Credit Balance</th>
<th>Action</th>
</tr>
</thead>
<tbody id="patientListBody">
<tr>
<td colspan="8" style="text-align:center;">
Select Patient Type and click Search
</td>
</tr>
</tbody>
</table>
</div>
</div>
</div>
</div>
<script>
const patientType=document.getElementById('patient_type');
const saleAgainst=document.getElementById('sale_against');
const prescriptionGroup=document.getElementById('prescription_group');
const prescriptionId=document.getElementById('prescription_id');
const openPatientModal=document.getElementById('openPatientModal');

function updateSaleAgainst(){

    const type=patientType.value;

    saleAgainst.innerHTML='';

    prescriptionId.innerHTML=
        '<option value="">Select Prescription</option>';

    prescriptionId.value='';

    prescriptionGroup.style.display='none';

    prescriptionId.required=false;

    if(type==='OPD'||type==='IPD'){

        saleAgainst.disabled=false;

        saleAgainst.innerHTML=`
            <option value="">Select</option>
            <option value="Direct">Direct</option>
            <option value="Prescription">Prescription</option>
        `;

        openPatientModal.disabled=false;

    }else if(type==='Direct Visit'){

        saleAgainst.disabled=false;

        saleAgainst.innerHTML=`
            <option value="Direct" selected>Direct</option>
        `;

        openPatientModal.disabled=true;

    }else{

        saleAgainst.disabled=true;

        saleAgainst.innerHTML=`
            <option value="">Select Patient Type First</option>
        `;

        openPatientModal.disabled=true;
    }

}
patientType.addEventListener('change',function(){
    updateSaleAgainst();

    document.getElementById('title').disabled=false;
    document.getElementById('patient_name').readOnly=false;
    document.getElementById('mobile').readOnly=false;
    document.getElementById('uhid_op_no').readOnly=false;
    document.getElementById('aadhar_no').readOnly=false;

    document.getElementById('title').value='';
    document.getElementById('patient_name').value='';
    document.getElementById('mobile').value='';
    document.getElementById('uhid_op_no').value='';
    document.getElementById('aadhar_no').value='';
    document.getElementById('location').value='';
    document.getElementById('credit_balance').value='0.00';
});

saleAgainst.addEventListener('change',function(){
    if(this.value==='Prescription'){
        prescriptionGroup.style.display='flex';
        prescriptionId.required=true;
        loadPrescriptions();
    }else{
        prescriptionGroup.style.display='none';
        prescriptionId.required=false;
        prescriptionId.value='';
    }
});
function addRow(){

    let tbody=document.querySelector('#itemTable tbody');
    let firstRow=tbody.querySelector('tr');

    if(!firstRow){
        return;
    }

    let clone=firstRow.cloneNode(true);

    clone.querySelectorAll('input').forEach(function(input){

        if(input.classList.contains('discount_percent')){
            input.value='0';
        }else{
            input.value='';
        }

    });

    clone.querySelectorAll('select').forEach(function(select){
        select.selectedIndex=0;
    });

    const actionCell=
        clone.querySelector('.addItemInline').parentElement;

    actionCell.innerHTML=`
        <button
        type="button"
        class="btn btn-danger removeRow">
        X
        </button>
    `;

    tbody.appendChild(clone);

    updateSerialNumbers();
}
document.getElementById('addRowBtn').addEventListener('click',function(e){
    e.preventDefault();
    addRow();
});

document.addEventListener('click',function(e){

    const addButton=e.target.closest('.addItemInline');

    if(addButton){
        e.preventDefault();
        addRow();
        return;
    }

    const removeButton=e.target.closest('.removeRow');

    if(removeButton){

        let rows=document.querySelectorAll(
            '#itemTable tbody tr'
        );

        if(rows.length>1){

            removeButton.closest('tr').remove();

            updateSerialNumbers();
            calculateFooterTotals();

        }
    }

});
function updateSerialNumbers(){
    document.querySelectorAll('#itemTable tbody tr').forEach((row,index)=>{
        row.querySelector('.serial_no').textContent=index+1;
    });
}

function calculateRow(row){
    let qty=parseFloat(row.querySelector('.qty').value)||0;
    let saleRate=parseFloat(row.querySelector('.sale_rate').value)||0;
    let discountPercent=parseFloat(
        row.querySelector('.discount_percent').value
    )||0;
    let gstPercent=parseFloat(
        row.querySelector('.gst_percent').value
    )||0;

    let amount=qty*saleRate;
    let discountValue=(amount*discountPercent)/100;
    let taxableAmount=amount-discountValue;
    let gstValue=(taxableAmount*gstPercent)/100;
    let netAmount=taxableAmount+gstValue;

    row.querySelector('.discount_value').value=
    discountValue.toFixed(2);

    row.querySelector('.amount').value=
    amount.toFixed(2);

    row.querySelector('.gst_value').value=
    gstValue.toFixed(2);

    row.querySelector('.net_amount').value=
    netAmount.toFixed(2);

    calculateFooterTotals();
}

document.addEventListener('input',function(e){
    let row=e.target.closest('#itemTable tbody tr');

    if(!row){
        return;
    }

    if(
        e.target.classList.contains('qty')
        ||
        e.target.classList.contains('sale_rate')
        ||
        e.target.classList.contains('discount_percent')
    ){
        calculateRow(row);
    }
});

document.addEventListener('change',function(e){
    let row=e.target.closest('#itemTable tbody tr');

    if(!row){
        return;
    }

    if(
        e.target.classList.contains('qty')
        ||
        e.target.classList.contains('sale_rate')
        ||
        e.target.classList.contains('discount_percent')
    ){
        calculateRow(row);
    }
});

function calculateFooterTotals(){
    let subTotal=0;
    let totalDiscount=0;
    let totalGST=0;
    let grandTotal=0;

    document.querySelectorAll('#itemTable tbody tr').forEach(row=>{
        let amount=parseFloat(
            row.querySelector('.amount').value
        )||0;

        let discount=parseFloat(
            row.querySelector('.discount_value').value
        )||0;

        let gst=parseFloat(
            row.querySelector('.gst_value').value
        )||0;

        let netAmount=parseFloat(
            row.querySelector('.net_amount').value
        )||0;

        subTotal+=amount;
        totalDiscount+=discount;
        totalGST+=gst;
        grandTotal+=netAmount;
    });

    let roundedTotal=Math.round(grandTotal);
    let roundOff=roundedTotal-grandTotal;

    document.getElementById('sub_total').value=
    subTotal.toFixed(2);

    document.getElementById('total_discount').value=
    totalDiscount.toFixed(2);

    document.getElementById('total_gst').value=
    totalGST.toFixed(2);

    document.getElementById('round_off').value=
    roundOff.toFixed(2);

    document.getElementById('grand_total').value=
    roundedTotal.toFixed(2);
    calculatePaymentAmounts();
}

document.getElementById('branch_id').addEventListener('change',function(){
    let branchId=this.value;
    let storeDropdown=document.getElementById('store_id');

    if(!branchId){
        storeDropdown.innerHTML=
        '<option value="">Select Branch First</option>';
        return;
    }

    storeDropdown.innerHTML=
    '<option value="">Loading Stores...</option>';

    fetch(
        'ajax/get_stores_for_sale.php?branch_id='+
        encodeURIComponent(branchId)
    )
    .then(response=>response.text())
    .then(data=>{
    storeDropdown.innerHTML=data;

    if(storeDropdown.options.length===2){
        storeDropdown.selectedIndex=1;
        storeDropdown.dispatchEvent(new Event('change'));
    }
})
    .catch(()=>{
        storeDropdown.innerHTML=
        '<option value="">Unable to load stores</option>';
    });
});
updateSaleAgainst();
const patientModal=document.getElementById('patientModal');
const closePatientModal=document.getElementById('closePatientModal');
const patientSearchInput=document.getElementById('patientSearchInput');
const patientListBody=document.getElementById('patientListBody');
const patientModalTitle=document.getElementById('patientModalTitle');
const patientNoHeader=document.getElementById('patientNoHeader');

let patientSearchTimer=null;

openPatientModal.addEventListener('click',function(){
    const type=patientType.value;

    if(type!=='OPD'&&type!=='IPD'){
        alert('Please select OPD or IPD Patient Type first.');
        return;
    }

    patientModalTitle.textContent=
    type==='OPD'?'Select OPD Patient':'Select IPD Patient';

    patientNoHeader.textContent=
    type==='OPD'?'OP No':'IPD No';

    patientModal.style.display='flex';
    patientSearchInput.value='';
    loadSalePatients('');

    setTimeout(function(){
        patientSearchInput.focus();
    },200);
});

closePatientModal.addEventListener('click',function(){
    patientModal.style.display='none';

if(saleAgainst.value==='Prescription'){
    loadPrescriptions();
}
});

window.addEventListener('click',function(e){
    if(e.target===patientModal){
        patientModal.style.display='none';
    }
});

function loadSalePatients(search=''){
    const type=patientType.value;

    patientListBody.innerHTML=`
    <tr>
    <td colspan="8" style="text-align:center;">
    Loading patients...
    </td>
    </tr>
    `;

    fetch(
        'ajax/get_sale_patients.php?patient_type='+
        encodeURIComponent(type)+
        '&search='+
        encodeURIComponent(search)
    )
    .then(response=>response.json())
    .then(data=>{
        if(
            !data.status||
            !data.patients||
            data.patients.length===0
        ){
            patientListBody.innerHTML=`
            <tr>
            <td colspan="8" style="text-align:center;">
            No patients found
            </td>
            </tr>
            `;
            return;
        }

        let html='';

        data.patients.forEach(patient=>{
            let patientNo='';

if(type==='OPD'){
    patientNo=patient.opd_no||patient.uhid||'';
}else if(type==='IPD'){
    patientNo=patient.ipd_no||'';
}

            html+=`
            <tr>
            <td>${escapeHtml(patient.patient_name||'')}</td>
            <td>${escapeHtml(patient.mobile||'')}</td>
            <td>${escapeHtml(String(patient.uhid||''))}</td>
            <td>${escapeHtml(String(patientNo))}</td>
            <td>${escapeHtml(patient.aadhaar||'')}</td>
            <td>${escapeHtml(patient.location||'')}</td>
            <td>${escapeHtml(patient.credit_balance||'0.00')}</td>
            <td>
            <button
            type="button"
            class="btn btn-primary select-patient-btn"
            data-patient='${JSON.stringify(patient).replace(/'/g,"&#39;")}'>
            Select
            </button>
            </td>
            </tr>
            `;
        });

        patientListBody.innerHTML=html;
    })
    .catch(error=>{
        console.error(error);

        patientListBody.innerHTML=`
        <tr>
        <td colspan="8" style="text-align:center;">
        Unable to load patients
        </td>
        </tr>
        `;
    });
}

patientSearchInput.addEventListener('input',function(){
    clearTimeout(patientSearchTimer);

    patientSearchTimer=setTimeout(function(){
        loadSalePatients(
            patientSearchInput.value.trim()
        );
    },300);
});
document.addEventListener('click',function(e){
    const button=e.target.closest('.select-patient-btn');

    if(!button){
        return;
    }

    let patient;

    try{
        patient=JSON.parse(
            button.getAttribute('data-patient')
        );
    }catch(error){
        console.error(error);
        alert('Unable to read patient information.');
        return;
    }

    document.getElementById('title').value=
    patient.title||'';

    document.getElementById('patient_name').value=
    patient.patient_name||'';

    document.getElementById('mobile').value=
    patient.mobile||'';

    document.getElementById('aadhar_no').value=
    patient.aadhaar||'';

    if(patientType.value==='OPD'){
        document.getElementById('uhid_op_no').value=
        patient.opd_no||'';

        document.getElementById('location').value='';
        document.getElementById('credit_balance').value='0.00';

        document.getElementById('patient_name')
        .setAttribute(
            'data-appointment-id',
            patient.opd_id||''
        );
    }else{
        document.getElementById('uhid_op_no').value=
        patient.ipd_no||'';

        document.getElementById('location').value=
        patient.location||'';

        document.getElementById('credit_balance').value=
        patient.credit_balance||'0.00';

        document.getElementById('patient_name')
        .setAttribute(
            'data-appointment-id',
            patient.appointment_id||''
        );
    }

   document.getElementById('patient_name')
.setAttribute(
    'data-patient-id',
    patient.patient_id||''
);
document.getElementById('patient_id').value=
patient.patient_id||'';

document.getElementById('appointment_id').value=
document.getElementById('patient_name')
.getAttribute('data-appointment-id')||'';

document.getElementById('admission_id').value=
patient.admission_id||'';
if(
    saleAgainst.value==='Prescription'
){
    loadPrescriptions();
}
document.getElementById('title').disabled=true;
document.getElementById('patient_name').readOnly=true;
document.getElementById('mobile').readOnly=true;
document.getElementById('uhid_op_no').readOnly=true;
document.getElementById('aadhar_no').readOnly=true;
patientModal.style.display='none';
});

function escapeHtml(value){
    const div=document.createElement('div');
    div.textContent=value;
    return div.innerHTML;
}
function loadPrescriptions(){
    const type=patientType.value;
    const appointmentId=
    document.getElementById('patient_name')
    .getAttribute('data-appointment-id');
    
    console.log('Patient Type:',type);
console.log('Appointment ID:',appointmentId);

    prescriptionId.innerHTML=
    '<option value="">Loading Prescriptions...</option>';

    if(!appointmentId){
        prescriptionId.innerHTML=
        '<option value="">Select Patient First</option>';
        return;
    }

    fetch(
        'ajax/get_prescriptions_for_sale.php'
        +'?patient_type='+encodeURIComponent(type)
        +'&appointment_id='+encodeURIComponent(appointmentId)
    )
    .then(response=>response.text())
    .then(data=>{
        prescriptionId.innerHTML=data;
    })
    .catch(error=>{
        console.error(error);

        prescriptionId.innerHTML=
        '<option value="">Unable to load prescriptions</option>';
    });
}
const referralType=document.getElementById('referral_type');
const internalReferralBox=document.getElementById('internal_referral_box');
const externalReferralBox=document.getElementById('external_referral_box');
const internalReferralId=document.getElementById('internal_referral_id');
const externalReferralId=document.getElementById('external_referral_id');

referralType.addEventListener('change',function(){
    const type=this.value;

    internalReferralBox.style.display='none';
    externalReferralBox.style.display='none';
    internalReferralId.value='';
    externalReferralId.value='';

    if(type==='Internal'){
        internalReferralBox.style.display='block';
        internalReferralId.innerHTML='<option value="">Loading...</option>';

        fetch('/modules/registration/ajax/get_active_users.php')
        .then(response=>response.json())
        .then(data=>{
            let html='<option value="">Select User</option>';

            data.forEach(user=>{
    html+=`<option value="${user.id}">${user.full_name}</option>`;
});

            internalReferralId.innerHTML=html;
        })
        .catch(error=>{
            console.error(error);
            internalReferralId.innerHTML=
            '<option value="">Unable to load users</option>';
        });
    }else if(type==='External'){
        externalReferralBox.style.display='block';
        externalReferralId.innerHTML='<option value="">Loading...</option>';

        fetch('/modules/registration/ajax/get_external_referrals.php')
        .then(response=>response.json())
        .then(data=>{
            let html='<option value="">Select Referral</option>';
            data.forEach(referral=>{
                html+=`<option value="${referral.id}">${referral.name}</option>`;
            });
            externalReferralId.innerHTML=html;
        })
        .catch(error=>{
            console.error(error);
            externalReferralId.innerHTML=
            '<option value="">Unable to load referrals</option>';
        });
    }
});
function loadSaleItems(){
    const storeId=document.getElementById('store_id').value;
    document.querySelectorAll('.item_select').forEach(itemSelect=>{
        itemSelect.innerHTML='<option value="">Select Item</option>';
    });
    if(!storeId){
        return;
    }
    document.querySelectorAll('.item_select').forEach(itemSelect=>{
        itemSelect.innerHTML='<option value="">Loading Items...</option>';
    });
    fetch(
        'ajax/get_sale_items.php?store_id='+
        encodeURIComponent(storeId)
    )
    .then(response=>response.json())
    .then(data=>{
        if(!data.status){
            throw new Error('Unable to load items');
        }
        document.querySelectorAll('.item_select').forEach(itemSelect=>{
    let html='<option value="">Select Item</option>';
    data.items.forEach(item=>{
        html+=`
        <option value="${item.id}">
        ${escapeHtml(item.item_name)}
        </option>
        `;
    });
    itemSelect.innerHTML=html;
});

  
  
  
  
})
    .catch(error=>{
        console.error(error);
        document.querySelectorAll('.item_select').forEach(itemSelect=>{
            itemSelect.innerHTML=
            '<option value="">Unable to load items</option>';
        });
    });
}
document.getElementById('store_id')
.addEventListener('change',loadSaleItems);
document.addEventListener('change',function(e){
    if(!e.target.classList.contains('item_select')){
        return;
    }
    const itemSelect=e.target;
    const row=itemSelect.closest('tr');
    const storeId=document.getElementById('store_id').value;
    const itemId=itemSelect.value;
    const batchSelect=row.querySelector('.batch_select');
    batchSelect.innerHTML='<option value="">Select Batch</option>';
    row.querySelector('.exp_date').value='';
row.querySelector('.stock_qty').value='';
row.querySelector('.mrp').value='';
row.querySelector('.qty').value='';
row.querySelector('.sale_rate').value='';
row.querySelector('.discount_percent').value='0';
row.querySelector('.discount_value').value='';
row.querySelector('.amount').value='';
row.querySelector('.gst_percent').value='';
row.querySelector('.gst_value').value='';
row.querySelector('.net_amount').value='';
row.querySelector('.mrn').value='';
    if(!storeId||!itemId){
        return;
    }
    batchSelect.innerHTML='<option value="">Loading Batches...</option>';
    fetch(
        'ajax/get_sale_batches.php?store_id='+
        encodeURIComponent(storeId)+
        '&item_id='+
        encodeURIComponent(itemId)
    )
    .then(response=>response.json())
    .then(data=>{
        let html='<option value="">Select Batch</option>';
        if(data.status&&data.batches){
            data.batches.forEach(batch=>{
                html+=`
                <option
                    value="${batch.batch_id}"
                    data-batch='${JSON.stringify(batch).replace(/'/g,"&#39;")}'>
                    ${escapeHtml(batch.batch_no||'No Batch')}
                </option>
                `;
            });
        }
        batchSelect.innerHTML=html;
        if(batchSelect.options.length===2){
    batchSelect.selectedIndex=1;
    batchSelect.dispatchEvent(
        new Event('change',{
            bubbles:true
        })
    );
}
    })
    .catch(error=>{
        console.error(error);
        batchSelect.innerHTML=
        '<option value="">Unable to load batches</option>';
    });
});
document.addEventListener('change',function(e){
    if(!e.target.classList.contains('batch_select')){
        return;
    }
    const batchSelect=e.target;
    const row=batchSelect.closest('tr');
    const itemId=row.querySelector('.item_select').value;
const batchId=batchSelect.value;
if(itemId&&batchId){
    const duplicate=[...document.querySelectorAll('#itemTable tbody tr')]
    .some(otherRow=>{
        if(otherRow===row){
            return false;
        }
        return otherRow.querySelector('.item_select').value===itemId
        &&
        otherRow.querySelector('.batch_select').value===batchId;
    });
    if(duplicate){
        showCustomAlert(
    'This medicine and batch combination is already selected.'
);
        batchSelect.value='';
        row.querySelector('.exp_date').value='';
        row.querySelector('.stock_qty').value='';
        row.querySelector('.mrp').value='';
        row.querySelector('.qty').value='';
        row.querySelector('.sale_rate').value='';
        row.querySelector('.discount_percent').value='0';
        row.querySelector('.discount_value').value='';
        row.querySelector('.amount').value='';
        row.querySelector('.gst_percent').value='';
        row.querySelector('.gst_value').value='';
        row.querySelector('.net_amount').value='';
        row.querySelector('.mrn').value='';
        return;
    }
}
    const selectedOption=batchSelect.options[
        batchSelect.selectedIndex
    ];
    if(!selectedOption.value){
        row.querySelector('.exp_date').value='';
        row.querySelector('.stock_qty').value='';
        row.querySelector('.mrp').value='';
        row.querySelector('.sale_rate').value='';
        row.querySelector('.gst_percent').value='';
        row.querySelector('.mrn').value='';
        return;
    }
    let batch;
    try{
        batch=JSON.parse(
            selectedOption.getAttribute('data-batch')
        );
    }catch(error){
        console.error(error);
        return;
    }
    row.querySelector('.exp_date').value=
    batch.exp_date||'';
    const expiryInput=row.querySelector('.exp_date');

expiryInput.style.color='';

if(batch.exp_date){
    const today=new Date();
    today.setHours(0,0,0,0);

    const expiryDate=new Date(batch.exp_date);
    expiryDate.setHours(0,0,0,0);

    if(expiryDate<today){
        expiryInput.style.color='#dc3545';
        expiryInput.style.fontWeight='bold';
    }
}
    row.querySelector('.stock_qty').value=
    batch.qty_available||'0';
    row.querySelector('.mrp').value=
    batch.mrp||'0';
    row.querySelector('.sale_rate').value=
    batch.mrp||'0';
    row.querySelector('.gst_percent').value=
    batch.gst_percent||'0';
    row.querySelector('.mrn').value=
    batch.mrn_no||'';
});
document.addEventListener('DOMContentLoaded',function(){
    const branchDropdown=document.getElementById('branch_id');
    if(branchDropdown.options.length===2){
        branchDropdown.selectedIndex=1;
        branchDropdown.dispatchEvent(new Event('change'));
    }
});
function showCustomAlert(message){
    const modal=document.getElementById('customAlertModal');
    const messageBox=document.getElementById('customAlertMessage');
    messageBox.textContent=message;
    modal.style.display='flex';
    setTimeout(function(){
        document.getElementById('customAlertOk').focus();
    },0);
}
function closeCustomAlert(){
    document.getElementById('customAlertModal').style.display='none';
}
document.addEventListener('click',function(e){
    if(e.target.closest('#customAlertOk')){
        closeCustomAlert();
        return;
    }
    const modal=document.getElementById('customAlertModal');
    if(
        modal.style.display==='flex'
        &&
        e.target===modal
    ){
        closeCustomAlert();
    }
});
document.addEventListener('keydown',function(e){
    if(
        e.key==='Escape'
        &&
        document.getElementById('customAlertModal').style.display==='flex'
    ){
        closeCustomAlert();
    }
});
prescriptionId.addEventListener('change',function(){
    const selectedPrescriptionId=this.value;

    if(!selectedPrescriptionId){
        return;
    }

    fetch(
        'ajax/get_prescription_sale_items.php?prescription_id='+
        encodeURIComponent(selectedPrescriptionId)
    )
    .then(response=>response.json())
    .then(data=>{
        if(!data.status){
            showCustomAlert(
                'Unable to load prescription medicines.'
            );
            return;
        }

       if(!Array.isArray(data.items)||data.items.length===0){
    return;
}

        loadPrescriptionItems(data.items);
    })
    .catch(error=>{
        console.error(error);

        showCustomAlert(
            'Unable to load prescription medicines.'
        );
    });
});

function loadPrescriptionItems(items){
    const tbody=document.querySelector('#itemTable tbody');
    const firstRow=tbody.querySelector('tr');

    tbody.querySelectorAll('tr').forEach((row,index)=>{
        if(index>0){
            row.remove();
        }
    });

    items.forEach((medicine,index)=>{
        let row;

        if(index===0){
            row=firstRow;
        }else{
            addRow();
            row=tbody.lastElementChild;
        }

        const itemSelect=row.querySelector('.item_select');

        itemSelect.value=String(medicine.item_id);

       itemSelect.dataset.prescriptionQty=
    medicine.remaining_qty;

itemSelect.dispatchEvent(
    new Event('change',{
        bubbles:true
    })
);

setTimeout(function(){
    const qtyInput=row.querySelector('.qty');

    qtyInput.value=medicine.remaining_qty;

    calculateRow(row);
},500);
    });

    updateSerialNumbers();
}
function calculatePaymentAmounts(){

    const grandTotalField =
    document.getElementById('grand_total');

const netPayable =
    parseFloat(grandTotalField?.value) || 0;

    const receivedField=
        document.getElementById('received_amount');

    const balanceField=
        document.getElementById('balance_amount');

    const paidField=
        document.getElementById('paid_amount');

    const statusField=
        document.getElementById('payment_status');
        
    const paymentMode=
        document.getElementById('payment_mode').value;

    let received=
        parseFloat(receivedField.value)||0;

    if(paymentMode==='Credit'){

        received=0;

        receivedField.value='0.00';

        receivedField.readOnly=true;

    }else{

        receivedField.readOnly=false;

        if(received<0){
            received=0;
            receivedField.value='0.00';
        }

        if(received>netPayable){

            received=netPayable;

            receivedField.value=
                netPayable.toFixed(2);

        }

    }

    let balance=
        netPayable-received;

    if(balance<0){
        balance=0;
    }

    balanceField.value=
        balance.toFixed(2);

    if(paidField){
    paidField.value=
        received.toFixed(2);
}

    let status='Pending';

    if(netPayable>0&&balance<=0){

        status='Paid';

    }else if(received>0&&balance>0){

        status='Partial';

    }else{

        status='Pending';

    }

    statusField.value=status;

    if(typeof statusDisplay!=='undefined'){
    statusDisplay.textContent=status;
}
}

function togglePaymentFields(){

    document
    .querySelectorAll('.paymentFields')
    .forEach(function(field){
        field.classList.remove('active');
    });

    const paymentMode =
        document.getElementById('payment_mode').value;

    const creditMessage =
        document.getElementById('creditMessage');

    creditMessage.style.display='none';

    if(paymentMode==='Cash'){
        document.getElementById('cashFields')
        .classList.add('active');
    }

    if(paymentMode==='UPI'){
        document.getElementById('upiFields')
        .classList.add('active');
    }

    if(paymentMode==='Card'){
        document.getElementById('cardFields')
        .classList.add('active');
    }

    if(paymentMode==='Cheque'){
        document.getElementById('chequeFields')
        .classList.add('active');
    }

    if(paymentMode==='NEFT'){
        document.getElementById('neftFields')
        .classList.add('active');
    }

    if(paymentMode==='ECS'){
        document.getElementById('ecsFields')
        .classList.add('active');
    }

    if(paymentMode==='Credit'){
        creditMessage.style.display='block';
    }

    calculatePaymentAmounts();
}

document
.getElementById('payment_mode')
.addEventListener(
    'change',
    togglePaymentFields
);

document
.getElementById('received_amount')
.addEventListener(
    'input',
    calculatePaymentAmounts
);
document.addEventListener('DOMContentLoaded',function(){
    togglePaymentFields();
});
document.getElementById('saveBtn').addEventListener('click',function(){

    const form=document.getElementById('saleForm');

    if(!form){
        alert('Sale form not found.');
        return;
    }

    const patientType=
        document.getElementById('patient_type').value;

    const saleAgainst=
        document.getElementById('sale_against').value;

    const patientName=
        document.getElementById('patient_name').value.trim();

    const branchId=
        document.getElementById('branch_id').value;

    const storeId=
        document.getElementById('store_id').value;

    const grandTotal=
        parseFloat(
            document.getElementById('grand_total').value
        )||0;

    const receivedAmount=
        parseFloat(
            document.getElementById('received_amount').value
        )||0;

    if(!patientType){
        alert('Please select Patient Type.');
        return;
    }

    if(!saleAgainst){
        alert('Please select Sale Against.');
        return;
    }

    if(!patientName){
        alert('Please select Patient Name.');
        return;
    }

    if(!branchId){
        alert('Please select Branch.');
        return;
    }

    if(!storeId){
        alert('Please select Store.');
        return;
    }

    const itemRows=
        document.querySelectorAll(
            '#itemTable tbody tr'
        );

    let validItems=0;

    itemRows.forEach(function(row){

        const itemSelect=
            row.querySelector('.item_select');

        const batchSelect=
            row.querySelector('.batch_select');

        const qtyInput=
            row.querySelector('.qty');

        if(
            itemSelect &&
            batchSelect &&
            qtyInput &&
            itemSelect.value &&
            batchSelect.value &&
            parseFloat(qtyInput.value)>0
        ){
            validItems++;
        }

    });

    if(validItems===0){
        alert('Please add at least one medicine.');
        return;
    }

    const paymentMode=
        document.getElementById('payment_mode').value;

    if(!paymentMode){
        alert('Please select Payment Mode.');
        return;
    }

    if(paymentMode==='Credit'){
        document.getElementById(
            'received_amount'
        ).value='0.00';
    }

    if(receivedAmount>grandTotal){
        alert(
            'Received amount cannot be greater than Grand Total.'
        );
        return;
    }

    const formData=
        new FormData(form);
        
    const saveButton=this;

saveButton.disabled=true;
saveButton.innerHTML='Saving...';

fetch('ajax/save_pharmacy_sale.php',{
    method:'POST',
    body:formData
})
.then(function(response){
    return response.json();
})
.then(function(data){

    if(!data.status){
        throw new Error(
            data.message||
            'Unable to save sale.'
        );
    }

    let message=
        'Sale saved successfully.\n\n'+
        'Sale No: '+
        (data.sale_no||'')+
        '\nReceipt No: '+
        (data.receipt_no||'');

    if(data.invoice_no){
        message+=
            '\nInvoice No: '+
            data.invoice_no;
    }

    message+=
        '\n\nReceived: ₹'+
        parseFloat(
            data.received_amount||0
        ).toFixed(2)+
        '\nBalance: ₹'+
        parseFloat(
            data.balance_amount||0
        ).toFixed(2);

   saveButton.disabled=true;
saveButton.innerHTML='Saved';

window.location.href=
    '/modules/pharmacy/pharmacy_receipt.php?sale_id='+
    encodeURIComponent(data.sale_id);

})
.catch(function(error){

    console.error(
        'Pharmacy Sale Error:',
        error
    );

    alert(
        error.message||
        'Unable to save pharmacy sale.'
    );

    saveButton.disabled=false;
    saveButton.innerHTML='Save Sale Bill';

});

});
</script>
<div id="customAlertModal" class="custom-alert-modal">
    <div class="custom-alert-box">
        <div class="custom-alert-icon">!</div>
        <h3>Duplicate Item</h3>
        <p id="customAlertMessage"></p>
        <button type="button" id="customAlertOk">OK</button>
    </div>
</div>
</body>
</html>