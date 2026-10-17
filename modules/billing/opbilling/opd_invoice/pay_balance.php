<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
header("Location:/login.php");
exit;
}
$opd_id=(int)($_GET['opd_id'] ?? 0);
if($opd_id<=0){
die("Invalid OPD ID.");
}
function e($value){
return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}
/*==================================================
LOAD OPD
==================================================*/
$stmt=$conn->prepare("
SELECT
a.*,
p.uhid,
u.full_name AS doctor_name,
d.department_name
FROM appointments a
LEFT JOIN patient_master p ON p.id=a.patient_id
LEFT JOIN users u ON u.id=a.entity_id
LEFT JOIN department_master d ON d.id=a.department_id
WHERE a.id=?
AND a.type='doctor'
LIMIT 1
");
$stmt->bind_param("i",$opd_id);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows==0){
die("OPD record not found.");
}
$opd=$result->fetch_assoc();
$stmt->close();
$patient_name=trim(($opd['title'] ?? '').' '.($opd['first_name'] ?? '').' '.($opd['last_name'] ?? ''));
$pending_items=[];
/*==================================================
CONSULTATION
FULL PAYMENT ONLY - NO PARTIAL PAYMENT
==================================================*/
$consultation_pending=(float)($opd['balance_amount'] ?? 0);
if($consultation_pending>0){
$pending_items[]=[
'type'=>'Consultation',
'reference_id'=>(int)$opd['id'],
'reference_no'=>$opd['opd_no'],
'particular'=>'Consultation - '.($opd['doctor_name'] ?: 'Doctor'),
'total'=>(float)($opd['net_payable'] ?? 0),
'paid'=>(float)($opd['paid_amount'] ?? 0),
'pending'=>$consultation_pending,
'partial_allowed'=>false
];
}
/*==================================================
SERVICE BILLS
PARTIAL PAYMENT ALLOWED
==================================================*/
$bill_stmt=$conn->prepare("
SELECT
id,
bill_no,
bill_date,
net_payable,
paid_amount,
balance_amount,
status
FROM opd_bills
WHERE opd_id=?
AND status NOT IN('Cancelled','Refunded')
AND balance_amount>0
ORDER BY bill_date ASC,id ASC
");
$bill_stmt->bind_param("i",$opd_id);
$bill_stmt->execute();
$bill_result=$bill_stmt->get_result();
while($bill=$bill_result->fetch_assoc()){
$pending_items[]=[
'type'=>'Service',
'reference_id'=>(int)$bill['id'],
'reference_no'=>$bill['bill_no'],
'particular'=>'Service Bill - '.$bill['bill_no'],
'total'=>(float)$bill['net_payable'],
'paid'=>(float)$bill['paid_amount'],
'pending'=>(float)$bill['balance_amount'],
'partial_allowed'=>true
];
}
$bill_stmt->close();
$total_pending=0;
foreach($pending_items as $item){
$total_pending+=(float)$item['pending'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pay Balance - <?= e($opd['opd_no']) ?></title>
<style>
*{box-sizing:border-box;}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;color:#333;}
.container{max-width:1250px;margin:auto;padding:20px;}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:20px;}
.topbar h2{margin:0;color:#0b5ed7;}
.topbar p{margin:5px 0 0;font-size:13px;color:#666;}
.card{background:#fff;border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.section-title{background:#0b5ed7;color:#fff;padding:10px 14px;font-size:15px;font-weight:bold;margin:-20px -20px 15px;border-radius:8px 8px 0 0;}
.btn{display:inline-block;padding:10px 16px;border:none;border-radius:5px;font-size:14px;cursor:pointer;text-decoration:none;}
.btn-success{background:#198754;color:#fff;}
.btn-secondary{background:#6c757d;color:#fff;}
.btn:disabled{opacity:.55;cursor:not-allowed;}
.info-table{width:100%;border-collapse:collapse;}
.info-table td{border:1px solid #ddd;padding:9px;font-size:13px;}
.label{font-weight:bold;background:#f8f9fa;width:15%;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{background:#0b5ed7;color:#fff;padding:10px 8px;font-size:13px;text-align:left;}
td{padding:10px 8px;border:1px solid #ddd;font-size:13px;}
.text-right{text-align:right;}
.checkbox-cell{text-align:center;}
.pending-row{background:#fff5f5;}
.pending{color:#dc3545;font-weight:bold;}
.locked{background:#f1f1f1;color:#555;cursor:not-allowed;}
.pay-amount{width:135px;height:37px;border:1px solid #ccc;border-radius:4px;padding:6px 8px;text-align:right;}
.payment-note{font-size:11px;color:#777;margin-top:4px;}
.consultation-note{font-size:11px;color:#dc3545;font-weight:bold;}
.total-box{display:flex;justify-content:flex-end;margin-top:20px;}
.total-summary{width:400px;border:2px solid #198754;border-radius:7px;overflow:hidden;}
.total-summary div{display:flex;justify-content:space-between;padding:12px 15px;border-bottom:1px solid #ddd;}
.total-summary div:last-child{border-bottom:none;background:#effaf4;font-size:20px;font-weight:bold;color:#198754;}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group.full{grid-column:1/-1;}
.form-group label{font-size:13px;font-weight:bold;}
.form-group input,.form-group select,.form-group textarea{width:100%;height:42px;border:1px solid #ccc;border-radius:5px;padding:8px 10px;font-size:14px;}
.form-group textarea{height:80px;resize:vertical;}
.transaction-fields{display:none;}
.payment-summary{margin-top:20px;padding:18px;background:#f8f9fa;border-radius:7px;display:flex;justify-content:space-between;align-items:center;gap:15px;}
.selected-label{font-size:13px;color:#666;}
.selected-amount{font-size:25px;font-weight:bold;color:#198754;margin-top:5px;}
.actions{margin-top:20px;display:flex;justify-content:flex-end;gap:10px;}
.empty{text-align:center;padding:35px;color:#777;}
@media(max-width:700px){
.container{padding:10px;}
.topbar{flex-direction:column;align-items:flex-start;}
.form-grid{grid-template-columns:1fr;}
.form-group.full{grid-column:auto;}
.total-summary{width:100%;}
.payment-summary{flex-direction:column;align-items:flex-start;}
}
</style>
</head>
<body>
<div class="container">
<div class="topbar">
<div>
<h2>Pay OPD Balance</h2>
<p>
OPD No. : <b><?= e($opd['opd_no']) ?></b>
|
Patient : <b><?= e($patient_name) ?></b>
</p>
</div>
<div>
<a href="generate_invoice.php?opd_id=<?= $opd_id ?>" class="btn btn-secondary">← Back to Invoice</a>
</div>
</div>
<div class="card">
<div class="section-title">Patient Details</div>
<table class="info-table">
<tr>
<td class="label">OPD No.</td>
<td><?= e($opd['opd_no']) ?></td>
<td class="label">UHID</td>
<td><?= e($opd['uhid'] ?: '-') ?></td>
</tr>
<tr>
<td class="label">Patient</td>
<td><?= e($patient_name) ?></td>
<td class="label">Mobile</td>
<td><?= e($opd['mobile'] ?: '-') ?></td>
</tr>
<tr>
<td class="label">Doctor</td>
<td><?= e($opd['doctor_name'] ?: '-') ?></td>
<td class="label">Department</td>
<td><?= e($opd['department_name'] ?: '-') ?></td>
</tr>
</table>
</div>
<form method="post" action="save_balance_payment.php" id="paymentForm">
<input type="hidden" name="opd_id" value="<?= $opd_id ?>">
<div class="card">
<div class="section-title">Pending Payments</div>
<?php if(empty($pending_items)){ ?>
<div class="empty">
<h3 style="color:#198754;margin-bottom:8px;">✓ No Pending Payment</h3>
All payments for this OPD have been completed.
</div>
<?php }else{ ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th style="width:55px;text-align:center;">
<input type="checkbox" id="selectAll" checked>
</th>
<th>Type</th>
<th>Reference No.</th>
<th>Particular</th>
<th class="text-right">Total</th>
<th class="text-right">Already Paid</th>
<th class="text-right">Pending</th>
<th class="text-right">Pay Amount</th>
</tr>
</thead>
<tbody>
<?php foreach($pending_items as $index=>$item){ ?>
<tr class="pending-row">
<td class="checkbox-cell">
<input
type="checkbox"
class="payment-item"
name="items[<?= $index ?>][selected]"
value="1"
checked
data-index="<?= $index ?>"
data-type="<?= e($item['type']) ?>"
data-pending="<?= number_format($item['pending'],2,'.','') ?>"
>
<input type="hidden" name="items[<?= $index ?>][type]" value="<?= e($item['type']) ?>">
<input type="hidden" name="items[<?= $index ?>][reference_id]" value="<?= (int)$item['reference_id'] ?>">
<input type="hidden" name="items[<?= $index ?>][reference_no]" value="<?= e($item['reference_no']) ?>">
<input type="hidden" name="items[<?= $index ?>][pending_amount]" value="<?= number_format($item['pending'],2,'.','') ?>">
</td>
<td>
<b><?= e($item['type']) ?></b>
<?php if($item['type']==='Consultation'){ ?>
<div class="consultation-note">Full payment only</div>
<?php } ?>
</td>
<td><b><?= e($item['reference_no']) ?></b></td>
<td><?= e($item['particular']) ?></td>
<td class="text-right">₹<?= number_format($item['total'],2) ?></td>
<td class="text-right">₹<?= number_format($item['paid'],2) ?></td>
<td class="text-right pending">₹<?= number_format($item['pending'],2) ?></td>
<td class="text-right">
<?php if($item['partial_allowed']){ ?>
<input
type="number"
class="pay-amount"
name="items[<?= $index ?>][pay_amount]"
id="pay_amount_<?= $index ?>"
value="<?= number_format($item['pending'],2,'.','') ?>"
min="0.01"
max="<?= number_format($item['pending'],2,'.','') ?>"
step="0.01"
data-index="<?= $index ?>"
>
<div class="payment-note">Partial payment allowed</div>
<?php }else{ ?>
<input
type="number"
class="pay-amount consultation-amount locked"
name="items[<?= $index ?>][pay_amount]"
id="pay_amount_<?= $index ?>"
value="<?= number_format($item['pending'],2,'.','') ?>"
min="<?= number_format($item['pending'],2,'.','') ?>"
max="<?= number_format($item['pending'],2,'.','') ?>"
step="0.01"
readonly
data-index="<?= $index ?>"
>
<div class="payment-note">Full amount required</div>
<?php } ?>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<div class="total-box">
<div class="total-summary">
<div>
<span>Total Pending</span>
<strong>₹<?= number_format($total_pending,2) ?></strong>
</div>
<div>
<span>Selected Payment</span>
<strong id="selectedTotal">₹<?= number_format($total_pending,2) ?></strong>
</div>
</div>
</div>
<?php } ?>
</div>
<?php if(!empty($pending_items)){ ?>
<div class="card">
<div class="section-title">Payment Details</div>
<div class="form-grid">
<div class="form-group">
<label>Payment Mode *</label>
<select name="payment_mode" id="paymentMode" required>
<option value="">Select Payment Mode</option>
<option value="Cash">Cash</option>
<option value="UPI">UPI</option>
<option value="Card">Card</option>
<option value="Cheque">Cheque</option>
<option value="NEFT">NEFT</option>
<option value="Credit">Credit</option>
<option value="ECS">ECS</option>
</select>
</div>
<div class="form-group">
<label>Total Payment Amount</label>
<input type="text" id="paymentTotalDisplay" readonly value="₹<?= number_format($total_pending,2) ?>">
<input type="hidden" name="total_amount" id="paymentTotal" value="<?= number_format($total_pending,2,'.','') ?>">
</div>
<div class="form-group transaction-fields" id="bankField">
<label>Bank Name</label>
<input type="text" name="bank_name">
</div>
<div class="form-group transaction-fields" id="referenceField">
<label>Reference No.</label>
<input type="text" name="reference_no">
</div>
<div class="form-group transaction-fields" id="transactionField">
<label>Transaction ID</label>
<input type="text" name="transaction_id">
</div>
<div class="form-group transaction-fields" id="upiField">
<label>UPI App</label>
<input type="text" name="upi_app" placeholder="Google Pay / PhonePe / Paytm">
</div>
<div class="form-group transaction-fields" id="chequeField">
<label>Cheque No.</label>
<input type="text" name="cheque_no">
</div>
<div class="form-group transaction-fields" id="chequeDateField">
<label>Cheque Date</label>
<input type="date" name="cheque_date">
</div>
<div class="form-group transaction-fields" id="creditDaysField">
<label>Credit Days</label>
<input type="number" name="credit_days" min="1">
</div>
<div class="form-group full">
<label>Remarks</label>
<textarea name="remarks"></textarea>
</div>
</div>
<div class="payment-summary">
<div>
<div class="selected-label">Amount selected for payment</div>
<div class="selected-amount" id="selectedTotalBottom">₹<?= number_format($total_pending,2) ?></div>
</div>
<div>
<span style="font-size:13px;color:#666;">
Consultation requires full payment. Services may be partially paid.
</span>
</div>
</div>
<div class="actions">
<a href="generate_invoice.php?opd_id=<?= $opd_id ?>" class="btn btn-secondary">Cancel</a>
<button type="submit" class="btn btn-success" id="acceptPayment">Accept Payment</button>
</div>
</div>
<?php } ?>
</form>
</div>
<script>
function updateSelectedTotal(){
let total=0;
let allCheckboxes=document.querySelectorAll('.payment-item');
let checkedCheckboxes=document.querySelectorAll('.payment-item:checked');
allCheckboxes.forEach(function(checkbox){
let index=checkbox.dataset.index;
let type=checkbox.dataset.type;
let pending=parseFloat(checkbox.dataset.pending)||0;
let amountInput=document.getElementById('pay_amount_'+index);
if(checkbox.checked){
amountInput.disabled=false;
if(type==='Consultation'){
amountInput.value=pending.toFixed(2);
total+=pending;
}else{
let amount=parseFloat(amountInput.value)||0;
if(amount>pending){
amount=pending;
amountInput.value=pending.toFixed(2);
}
if(amount<0){
amount=0;
amountInput.value='0.00';
}
total+=amount;
}
}else{
amountInput.disabled=true;
}
});
document.getElementById('selectAll').checked=(allCheckboxes.length===checkedCheckboxes.length);
document.getElementById('selectAll').indeterminate=(checkedCheckboxes.length>0&&checkedCheckboxes.length<allCheckboxes.length);
document.getElementById('selectedTotal').textContent='₹'+total.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
document.getElementById('selectedTotalBottom').textContent='₹'+total.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
document.getElementById('paymentTotalDisplay').value='₹'+total.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
document.getElementById('paymentTotal').value=total.toFixed(2);
document.getElementById('acceptPayment').disabled=(total<=0);
}
document.getElementById('selectAll').addEventListener('change',function(){
document.querySelectorAll('.payment-item').forEach(function(checkbox){
checkbox.checked=document.getElementById('selectAll').checked;
});
updateSelectedTotal();
});
document.querySelectorAll('.payment-item').forEach(function(checkbox){
checkbox.addEventListener('change',function(){
updateSelectedTotal();
});
});
document.querySelectorAll('.pay-amount:not(.consultation-amount)').forEach(function(input){
input.addEventListener('input',function(){
let max=parseFloat(this.max)||0;
let value=parseFloat(this.value)||0;
if(value>max){
this.value=max.toFixed(2);
}
if(value<0){
this.value='0.00';
}
updateSelectedTotal();
});
});
function hideTransactionFields(){
document.querySelectorAll('.transaction-fields').forEach(function(field){
field.style.display='none';
});
}
document.getElementById('paymentMode').addEventListener('change',function(){
let mode=this.value;
hideTransactionFields();
if(mode==='UPI'){
document.getElementById('transactionField').style.display='flex';
document.getElementById('upiField').style.display='flex';
}
if(mode==='Card'){
document.getElementById('transactionField').style.display='flex';
document.getElementById('bankField').style.display='flex';
}
if(mode==='Cheque'){
document.getElementById('bankField').style.display='flex';
document.getElementById('chequeField').style.display='flex';
document.getElementById('chequeDateField').style.display='flex';
}
if(mode==='NEFT'||mode==='ECS'){
document.getElementById('bankField').style.display='flex';
document.getElementById('referenceField').style.display='flex';
document.getElementById('transactionField').style.display='flex';
}
if(mode==='Credit'){
document.getElementById('creditDaysField').style.display='flex';
}
});
document.getElementById('paymentForm').addEventListener('submit',function(event){
let total=parseFloat(document.getElementById('paymentTotal').value)||0;
let mode=document.getElementById('paymentMode').value;
if(total<=0){
event.preventDefault();
alert('Please select at least one pending payment.');
return;
}
if(mode===''){
event.preventDefault();
alert('Please select payment mode.');
return;
}
});
updateSelectedTotal();
</script>
</body>
</html>