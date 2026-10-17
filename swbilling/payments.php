<?php
session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once __DIR__.'/includes/init.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}

if(!checkPermission('swbilling','add')){
    header("Location:/access_denied.php");
    exit;
}

$user_id=(int)$_SESSION['user_id'];

$invoiceId=isset($_GET['invoice_id'])?(int)$_GET['invoice_id']:0;

if($invoiceId<=0){
    die("Invalid Invoice.");
}

$stmt=$conn->prepare("
SELECT
    si.*,
    ss.plan_name,
    hm.hospital_name
FROM software_invoices si
INNER JOIN software_subscriptions ss
    ON ss.id=si.subscription_id
INNER JOIN hospital_master hm
    ON hm.id=ss.hospital_id
WHERE si.id=?
LIMIT 1
");

$stmt->bind_param("i",$invoiceId);
$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows==0){
    die("Invoice not found.");
}

$invoice=$result->fetch_assoc();

$stmt->close();

$page_title="Receive Payment";
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= $page_title; ?></title>

<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">

<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<link rel="stylesheet" href="/assets/css/style.css">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

<style>

.page-container{
padding:20px;
}

.top-bar{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:20px;
}

.page-title{
font-size:24px;
font-weight:600;
margin:0;
}

.breadcrumb{
font-size:14px;
color:#666;
margin-top:5px;
}

.breadcrumb a{
text-decoration:none;
color:#0d6efd;
}

.form-grid{
display:grid;
grid-template-columns:repeat(2,1fr);
gap:15px;
margin-top:20px;
}

.form-group{
display:flex;
flex-direction:column;
}

.form-group label{
font-weight:600;
margin-bottom:5px;
}

.form-group input,
.form-group select,
.form-group textarea{
padding:10px;
border:1px solid #ccc;
border-radius:4px;
font-size:14px;
}

.readonly{
background:#f5f5f5;
}

.full-width{
grid-column:1 / span 2;
}

.action-buttons{
display:flex;
gap:10px;
margin-top:20px;
}

@media(max-width:768px){

.form-grid{
grid-template-columns:1fr;
}

.full-width{
grid-column:1;
}

}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="page-container">

<div class="top-bar">

<div>

<h2 class="page-title">

<?= $page_title; ?>

</h2>

<div class="breadcrumb">

<a href="/dashboard/index.php">Home</a>

>

<a href="/swbilling/index.php">Software Billing</a>

>

<a href="invoices.php">Invoices</a>

>

Receive Payment

</div>

</div>

<div>

<a
href="invoice_view.php?id=<?= $invoice['id']; ?>"
class="btn">

<i class="fa fa-arrow-left"></i>

Back

</a>

</div>

</div>

<form
id="paymentForm"
method="post"
action="payment_save.php">

<input
type="hidden"
name="invoice_id"
value="<?= $invoice['id']; ?>">

<div class="form-grid">
    <div class="form-group">

<label>Invoice No.</label>

<input
type="text"
class="readonly"
readonly
value="<?= htmlspecialchars($invoice['invoice_no']); ?>">

</div>

<div class="form-group">

<label>Hospital</label>

<input
type="text"
class="readonly"
readonly
value="<?= htmlspecialchars($invoice['hospital_name']); ?>">

</div>

<div class="form-group">

<label>Plan Name</label>

<input
type="text"
class="readonly"
readonly
value="<?= htmlspecialchars($invoice['plan_name']); ?>">

</div>

<div class="form-group">

<label>Invoice Date</label>

<input
type="date"
class="readonly"
readonly
value="<?= $invoice['invoice_date']; ?>">

</div>

<div class="form-group">

<label>Total Invoice Amount (₹)</label>

<input
type="number"
step="0.01"
id="total_amount"
class="readonly"
readonly
value="<?= $invoice['total_amount']; ?>">

</div>

<div class="form-group">

<label>Paid Amount (₹)</label>

<input
type="number"
step="0.01"
id="paid_amount"
class="readonly"
readonly
value="<?= $invoice['paid_amount']; ?>">

</div>

<div class="form-group">

<label>Balance Amount (₹)</label>

<input
type="number"
step="0.01"
id="balance_amount"
class="readonly"
readonly
value="<?= $invoice['balance_amount']; ?>">

</div>

<div class="form-group">

<label>

Payment Date <span style="color:red;">*</span>

</label>

<input
type="date"
name="payment_date"
id="payment_date"
required
value="<?= date('Y-m-d'); ?>">

</div>

<div class="form-group">

<label>

Payment Mode <span style="color:red;">*</span>

</label>

<select
name="payment_mode"
id="payment_mode"
required>

<option value="">Select</option>

<option value="Cash">Cash</option>

<option value="UPI">UPI</option>

<option value="Card">Card</option>

<option value="Cheque">Cheque</option>

<option value="NEFT">NEFT</option>

<option value="RTGS">RTGS</option>

</select>

</div>

<div class="form-group">

<label>Transaction No.</label>

<input
type="text"
name="transaction_no"
id="transaction_no"
maxlength="100">

</div>

<div class="form-group">

<label>

Amount Received (₹) <span style="color:red;">*</span>

</label>

<input
type="number"
step="0.01"
name="received_amount"
id="received_amount"
required
max="<?= $invoice['balance_amount']; ?>">

</div>

<div class="form-group full-width">

<label>Remarks</label>

<textarea
name="remarks"
id="remarks"
rows="4"></textarea>

</div>

</div>

<div class="action-buttons">

<button
type="submit"
class="btn btn-success">

<i class="fa fa-save"></i>

Receive Payment

</button>

<a
href="invoice_view.php?id=<?= $invoice['id']; ?>"
class="btn btn-danger">

<i class="fa fa-times"></i>

Cancel

</a>

</div>

</form>

</div>

<script>

document.getElementById("paymentForm").addEventListener("submit",function(e){

let paymentDate=document.getElementById("payment_date").value.trim();

let paymentMode=document.getElementById("payment_mode").value.trim();

let balance=parseFloat(document.getElementById("balance_amount").value);

let received=parseFloat(document.getElementById("received_amount").value);

if(paymentDate==""){
alert("Please select Payment Date.");
document.getElementById("payment_date").focus();
e.preventDefault();
return false;
}

if(paymentMode==""){
alert("Please select Payment Mode.");
document.getElementById("payment_mode").focus();
e.preventDefault();
return false;
}

if(isNaN(received) || received<=0){
alert("Received Amount should be greater than zero.");
document.getElementById("received_amount").focus();
e.preventDefault();
return false;
}

if(received>balance){
alert("Received Amount cannot be greater than Balance Amount.");
document.getElementById("received_amount").focus();
e.preventDefault();
return false;
}

let transactionNo=document.getElementById("transaction_no").value.trim();

if(
(paymentMode=="UPI" ||
paymentMode=="Card" ||
paymentMode=="Cheque" ||
paymentMode=="NEFT" ||
paymentMode=="RTGS")
&& transactionNo==""
){
alert("Please enter Transaction Number.");
document.getElementById("transaction_no").focus();
e.preventDefault();
return false;
}

return true;

});

</script>

</body>

</html>
