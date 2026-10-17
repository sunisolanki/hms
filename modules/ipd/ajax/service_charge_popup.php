<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$appointment_id=(int)($_GET['appointment_id'] ?? 0);
$ipd_admission_id=(int)($_GET['ipd_admission_id'] ?? 0);
?>

<style>
.service-charge-popup{
    display:grid;
    grid-template-columns:minmax(0,1fr) 330px;
    gap:15px;
    padding:5px;
    box-sizing:border-box;
}
.charge-section,
.payment-section{
    border:1px solid #e5e7eb;
    border-radius:10px;
    background:#fff;
    overflow:hidden;
}
.section-header{
    background:#1976d2;
    color:#fff;
    padding:5px 15px;
    font-size:15px;
    font-weight:600;
}
.section-body{
    padding:5px;
}
.charge-table-box {
  overflow: visible;
  max-height: none;
  width: auto;
}

.charge-table {
  width: 100%;
  table-layout: auto;
  min-width: 0px;
}
.charge-table th{
    background:#f3f7fc;
    color:#374151;
    padding:10px;
    text-align:left;
    font-size:13px;
    white-space:nowrap;
}
.charge-table td{
    border-bottom:1px solid #e5e7eb;
    font-size:13px;
    vertical-align:middle;
}
.charge-table tr:hover{
    background:#f8fbff;
}
.charge-table input[type="checkbox"]{
    width:18px;
    height:18px;
    cursor:pointer;
}
.amount-box{
    width:100px;
    padding:7px;
    border:1px solid #d1d5db;
    border-radius:5px;
    text-align:right;
    font-weight:600;
    box-sizing:border-box;
}
.type-badge{
    display:inline-block;
    background:#e8f1ff;
    color:#1976d2;
    padding:4px 8px;
    border-radius:15px;
    font-size:12px;
}
.freq-badge{
    display:inline-block;
    background:#ecfdf5;
    color:#15803d;
    padding:4px 8px;
    border-radius:15px;
    font-size:12px;
}
.payment-form-group{
    margin-bottom:5px;
}
.payment-form-group label{
    display:block;
    margin-bottom:6px;
    font-size:13px;
    font-weight:600;
    color:#374151;
}
.payment-form-group input,
.payment-form-group select,
.payment-form-group textarea{
    width:100%;
    padding:9px 10px;
    border:1px solid #d1d5db;
    border-radius:6px;
    box-sizing:border-box;
    font-size:13px;
}
.payment-form-group textarea{
    min-height:55px;
    resize:vertical;
}
.payment-summary{
    margin-top:2px;
    padding:12px;
    background:#f8fbff;
    border:1px solid #dbeafe;
    border-radius:8px;
}
.payment-summary-row{
    display:flex;
    justify-content:space-between;
    margin-bottom:8px;
    font-size:13px;
}
.payment-summary-row:last-child{
    margin-bottom:0;
    padding-top:8px;
    border-top:1px solid #dbeafe;
    font-size:15px;
    font-weight:700;
    color:#1976d2;
}
.popup-footer{
    grid-column:1 / -1;
    text-align:right;
}
.add-charge-btn{
    background:#1976d2;
    color:#fff;
    border:none;
    padding:11px 22px;
    border-radius:6px;
    cursor:pointer;
    font-size:14px;
    font-weight:600;
}
.add-charge-btn:hover{
    background:#125aa0;
}
@media(max-width:900px){
    .service-charge-popup{
        grid-template-columns:1fr;
    }
    .popup-footer{
        grid-column:auto;
    }
}
</style>

<div class="service-charge-popup">

<div class="charge-section">
<div class="section-header">
<i class="fa fa-list"></i> Select Charges
</div>

<div class="section-body">
<div class="charge-table-box">
<table class="charge-table">
<thead>
<tr>
<th style="width:60px;text-align:center;">Select</th>
<th>Charges Name</th>
<th>Charges Type</th>
<th>Frequency</th>
<th>Amount</th>
</tr>
</thead>

<tbody>
<?php
$q=$conn->query("
SELECT
cm.id,
cm.charges_name,
cm.frequency,
cm.charges,
GROUP_CONCAT(ctm.charge_type SEPARATOR ', ') AS charge_type
FROM charges_master cm
LEFT JOIN charges_type_mapping ctm
ON ctm.charge_id=cm.id
WHERE cm.status=1
GROUP BY cm.id
ORDER BY cm.charges_name
");

if($q && $q->num_rows){
while($c=$q->fetch_assoc()){
?>
<tr>
<td style="text-align:center;">
<input
type="checkbox"
class="charge-checkbox"
value="<?= (int)$c['id'] ?>"
data-id="<?= (int)$c['id'] ?>"
data-appointment-id="<?= $appointment_id ?>"
data-ipd-admission-id="<?= $ipd_admission_id ?>"
data-name="<?= htmlspecialchars($c['charges_name'] ?? '',ENT_QUOTES) ?>"
data-type="<?= htmlspecialchars($c['charge_type'] ?? '',ENT_QUOTES) ?>"
data-frequency="<?= htmlspecialchars($c['frequency'] ?? '',ENT_QUOTES) ?>">
</td>

<td>
<strong><?= htmlspecialchars($c['charges_name'] ?? '') ?></strong>
</td>

<td>
<span class="type-badge">
<?= htmlspecialchars($c['charge_type'] ?? '') ?>
</span>
</td>

<td>
<span class="freq-badge">
<?= htmlspecialchars($c['frequency'] ?? '') ?>
</span>
</td>

<td>
<input
type="number"
class="amount-box"
value="<?= htmlspecialchars($c['charges'] ?? 0) ?>"
step="0.01"
min="0">
</td>
</tr>
<?php
}
}else{
?>
<tr>
<td colspan="5" style="text-align:center;padding:25px;">
No Charges Found
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

<div class="payment-section">
<div class="section-header">
<i class="fa fa-credit-card"></i> Payment Details
</div>

<div class="section-body">

<div class="payment-form-group">
<label>Payment Mode</label>
<select id="charge_payment_mode">
<option value="Cash">Cash</option>
<option value="UPI">UPI</option>
<option value="Card">Card</option>
<option value="Cheque">Cheque</option>
<option value="NEFT">NEFT</option>
<option value="Credit">Credit</option>
<option value="ECS">ECS</option>
</select>
</div>

<div class="payment-form-group">
<label>Total Selected Charges</label>
<input
type="number"
id="charge_total_amount"
value="0.00"
step="0.01"
min="0"
readonly>
</div>

<div class="payment-form-group">
<label>Received Amount</label>
<input
type="number"
id="charge_received_amount"
value="0.00"
step="0.01"
min="0">
</div>

<div class="payment-form-group">
<label>Balance Amount</label>
<input
type="number"
id="charge_balance_amount"
value="0.00"
step="0.01"
min="0"
readonly>
</div>

<div class="payment-form-group">
<label>Transaction / Reference No.</label>
<input
type="text"
id="charge_transaction_no"
placeholder="Enter transaction/reference number">
</div>

<div class="payment-form-group">
<label>Payment Details</label>
<textarea
id="charge_payment_details"
placeholder="Enter payment details"></textarea>
</div>

<div class="payment-form-group">
<label>Remarks</label>
<textarea
id="charge_remarks"
placeholder="Enter remarks"></textarea>
</div>

<div class="payment-summary">
<div class="payment-summary-row">
<span>Selected Charges</span>
<strong id="selected_charge_count">0</strong>
</div>

<div class="payment-summary-row">
<span>Total Amount</span>
<strong>₹ <span id="selected_charge_total">0.00</span></strong>
</div>

<div class="payment-summary-row">
<span>Payment Amount</span>
<strong>₹ <span id="payment_amount_display">0.00</span></strong>
</div>
</div>

</div>
</div>

<div class="popup-footer">
<button
type="button"
class="add-charge-btn"
onclick="addSelectedCharges()">
<i class="fa fa-save"></i> Save Charges
</button>
</div>

</div>
<script>
function updateSelectedChargeTotal(){

let total=0;
let count=0;

document.querySelectorAll('.charge-checkbox:checked').forEach(function(checkbox){

const row=checkbox.closest('tr');
const amountInput=row.querySelector('.amount-box');

const amount=parseFloat(amountInput.value) || 0;

total+=amount;
count++;

});

const receivedInput=document.getElementById('charge_received_amount');
let received=receivedInput ? (parseFloat(receivedInput.value) || 0) : 0;

if(received>total){
received=total;

if(receivedInput){
receivedInput.value=total.toFixed(2);
}
}

const balance=total-received;

document.getElementById('selected_charge_count').textContent=count;
document.getElementById('selected_charge_total').textContent=total.toFixed(2);

const totalInput=document.getElementById('charge_total_amount');

if(totalInput){
totalInput.value=total.toFixed(2);
}

const balanceInput=document.getElementById('charge_balance_amount');

if(balanceInput){
balanceInput.value=balance.toFixed(2);
}

const paymentDisplay=document.getElementById('payment_amount_display');

if(paymentDisplay){
paymentDisplay.textContent=received.toFixed(2);
}

}

document.addEventListener('change',function(e){

if(e.target.classList.contains('charge-checkbox')){
updateSelectedChargeTotal();
}

});

document.addEventListener('input',function(e){

if(e.target.classList.contains('amount-box')){
updateSelectedChargeTotal();
}

if(e.target.id==='charge_received_amount'){
updateSelectedChargeTotal();
}

});

function addSelectedCharges(){
const selectedCharges=[];
document.querySelectorAll('.charge-checkbox:checked').forEach(function(checkbox){
const row=checkbox.closest('tr');
const amountInput=row.querySelector('.amount-box');

selectedCharges.push({
id:checkbox.dataset.id,
name:checkbox.dataset.name,
type:checkbox.dataset.type,
frequency:checkbox.dataset.frequency,
amount:parseFloat(amountInput ? amountInput.value : 0) || 0,
appointment_id:checkbox.dataset.appointmentId || 0,
ipd_admission_id:checkbox.dataset.ipdAdmissionId || 0
});
});

if(selectedCharges.length===0){
alert('Please select at least one charge.');
return;
}

const paymentAmount=parseFloat(
document.getElementById('charge_received_amount').value
) || 0;
const totalAmount=parseFloat(
document.getElementById('charge_total_amount').value
) || 0;

const paymentMode=document.getElementById('charge_payment_mode').value;

if(paymentMode!=='Credit' && paymentAmount<=0){
alert('Please enter the received amount.');
document.getElementById('charge_received_amount').focus();
return;
}

if(paymentAmount>totalAmount){
alert('Received amount cannot be greater than total selected charges.');
document.getElementById('charge_received_amount').focus();
return;
}
const transactionNo=document.getElementById('charge_transaction_no').value.trim();

const paymentDetails=document.getElementById('charge_payment_details').value.trim();

const remarks=document.getElementById('charge_remarks').value.trim();

fetch('/modules/ipd/ajax/save_service_charge.php',{
method:'POST',
headers:{
'Content-Type':'application/json'
},
body:JSON.stringify({
charges:selectedCharges,
payment_amount:paymentAmount,
payment_mode:paymentMode,
transaction_no:transactionNo,
payment_details:paymentDetails,
remarks:remarks
})
})
.then(response=>response.text())
.then(text=>{
try{
return JSON.parse(text);
}catch(error){
console.error(text);
throw new Error('Invalid server response.');
}
})
.then(data=>{
if(!data.success){
alert(data.message || 'Unable to save charges.');
return;
}

alert(data.message);

if(data.receipt_url){
window.open(data.receipt_url,'_blank');
}

if(typeof closeServicePopup==='function'){
closeServicePopup();
}

if(typeof location!=='undefined'){
location.reload();
}
})
.catch(error=>{
console.error(error);
alert(error.message || 'Error while saving charges and payment.');
});
}
</script>