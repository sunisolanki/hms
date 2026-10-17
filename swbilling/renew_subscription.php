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

if(!checkPermission('swbilling','edit')){
    header("Location:/access_denied.php");
    exit;
}

$user_id=(int)$_SESSION['user_id'];

$subscriptionId=isset($_GET['id'])?(int)$_GET['id']:0;

if($subscriptionId<=0){
    die("Invalid Subscription.");
}

$stmt=$conn->prepare("
SELECT
    ss.*,
    hm.hospital_name
FROM software_subscriptions ss
INNER JOIN hospital_master hm
    ON hm.id=ss.hospital_id
WHERE ss.id=?
LIMIT 1
");

$stmt->bind_param("i",$subscriptionId);
$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows==0){
    die("Subscription not found.");
}

$subscription=$result->fetch_assoc();

$stmt->close();

$page_title="Renew Subscription";
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width,initial-scale=1.0">

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
grid-column:1/span 2;
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

<a href="subscriptions.php">Subscriptions</a>

>

Renew Subscription

</div>

</div>

<div>

<a
href="subscriptions.php"
class="btn">

<i class="fa fa-arrow-left"></i>

Back

</a>

</div>

</div>

<form
method="post"
action="renew_subscription_save.php"
id="renewForm">

<input
type="hidden"
name="subscription_id"
value="<?= $subscription['id']; ?>">

<div class="form-grid">
    
    <div class="form-group">

<label>Hospital</label>

<input
type="text"
class="readonly"
readonly
value="<?= htmlspecialchars($subscription['hospital_name']); ?>">

</div>

<div class="form-group">

<label>Plan Name</label>

<input
type="text"
name="plan_name"
class="readonly"
readonly
value="<?= htmlspecialchars($subscription['plan_name']); ?>">

</div>

<div class="form-group">

<label>Billing Type</label>

<input
type="text"
id="billing_type"
class="readonly"
readonly
value="<?= htmlspecialchars($subscription['billing_type']); ?>">

</div>

<div class="form-group">

<label>Current Start Date</label>

<input
type="date"
class="readonly"
readonly
value="<?= $subscription['start_date']; ?>">

</div>

<div class="form-group">

<label>Current End Date</label>

<input
type="date"
class="readonly"
readonly
value="<?= $subscription['end_date']; ?>">

</div>

<div class="form-group">

<label>

New Start Date <span style="color:red;">*</span>

</label>

<input
type="date"
name="start_date"
id="start_date"
required
value="<?= date('Y-m-d',strtotime('+1 day',strtotime($subscription['end_date']))); ?>">

</div>

<div class="form-group">

<label>

New End Date <span style="color:red;">*</span>

</label>

<input
type="date"
name="end_date"
id="end_date"
readonly
class="readonly">

</div>

<div class="form-group">

<label>Renewal Date</label>

<input
type="date"
name="renewal_date"
id="renewal_date"
readonly
class="readonly">

</div>

<div class="form-group">

<label>Amount (₹)</label>

<input
type="number"
step="0.01"
name="amount"
id="amount"
value="<?= $subscription['amount']; ?>">

</div>

<div class="form-group">

<label>GST (%)</label>

<input
type="number"
step="0.01"
name="gst_percent"
id="gst_percent"
value="<?= $subscription['gst_percent']; ?>">

</div>

<div class="form-group">

<label>Discount (₹)</label>

<input
type="number"
step="0.01"
name="discount"
id="discount"
value="<?= $subscription['discount']; ?>">

</div>

<div class="form-group">

<label>Total Amount (₹)</label>

<input
type="number"
step="0.01"
name="total_amount"
id="total_amount"
readonly
class="readonly"
value="<?= $subscription['total_amount']; ?>">

</div>

<div class="form-group full-width">

<label>Remarks</label>

<textarea
name="remarks"
id="remarks"
rows="4"><?= htmlspecialchars($subscription['remarks']); ?></textarea>

</div>

<div class="form-group">

<label>Hospital</label>

<input
type="text"
class="readonly"
readonly
value="<?= htmlspecialchars($subscription['hospital_name']); ?>">

</div>

<div class="form-group">

<label>Plan Name</label>

<input
type="text"
name="plan_name"
class="readonly"
readonly
value="<?= htmlspecialchars($subscription['plan_name']); ?>">

</div>

<div class="form-group">

<label>Billing Type</label>

<input
type="text"
id="billing_type"
class="readonly"
readonly
value="<?= htmlspecialchars($subscription['billing_type']); ?>">

</div>

<div class="form-group">

<label>Current Start Date</label>

<input
type="date"
class="readonly"
readonly
value="<?= $subscription['start_date']; ?>">

</div>

<div class="form-group">

<label>Current End Date</label>

<input
type="date"
class="readonly"
readonly
value="<?= $subscription['end_date']; ?>">

</div>

<div class="form-group">

<label>

New Start Date <span style="color:red;">*</span>

</label>

<input
type="date"
name="start_date"
id="start_date"
required
value="<?= date('Y-m-d',strtotime('+1 day',strtotime($subscription['end_date']))); ?>">

</div>

<div class="form-group">

<label>

New End Date <span style="color:red;">*</span>

</label>

<input
type="date"
name="end_date"
id="end_date"
readonly
class="readonly">

</div>

<div class="form-group">

<label>Renewal Date</label>

<input
type="date"
name="renewal_date"
id="renewal_date"
readonly
class="readonly">

</div>

<div class="form-group">

<label>Amount (₹)</label>

<input
type="number"
step="0.01"
name="amount"
id="amount"
value="<?= $subscription['amount']; ?>">

</div>

<div class="form-group">

<label>GST (%)</label>

<input
type="number"
step="0.01"
name="gst_percent"
id="gst_percent"
value="<?= $subscription['gst_percent']; ?>">

</div>

<div class="form-group">

<label>Discount (₹)</label>

<input
type="number"
step="0.01"
name="discount"
id="discount"
value="<?= $subscription['discount']; ?>">

</div>

<div class="form-group">

<label>Total Amount (₹)</label>

<input
type="number"
step="0.01"
name="total_amount"
id="total_amount"
readonly
class="readonly"
value="<?= $subscription['total_amount']; ?>">

</div>

<div class="form-group full-width">

<label>Remarks</label>

<textarea
name="remarks"
id="remarks"
rows="4"><?= htmlspecialchars($subscription['remarks']); ?></textarea>

</div>
</div>

<div class="action-buttons">

<button
type="submit"
class="btn btn-success">

<i class="fa fa-refresh"></i>

Renew Subscription

</button>

<a
href="subscriptions.php"
class="btn btn-danger">

<i class="fa fa-times"></i>

Cancel

</a>

</div>

</form>

</div>

<script>

function calculateRenewal(){

let billingType=document.getElementById("billing_type").value;

let startDate=document.getElementById("start_date").value;

if(startDate==""){
return;
}

let d=new Date(startDate);

let end=new Date(d);

switch(billingType){

case "Monthly":
end.setMonth(end.getMonth()+1);
end.setDate(end.getDate()-1);
break;

case "Annual":
end.setFullYear(end.getFullYear()+1);
end.setDate(end.getDate()-1);
break;

case "AMC":
end.setFullYear(end.getFullYear()+1);
end.setDate(end.getDate()-1);
break;

case "One Time":
break;

}

let y=end.getFullYear();

let m=String(end.getMonth()+1).padStart(2,"0");

let day=String(end.getDate()).padStart(2,"0");

document.getElementById("end_date").value=y+"-"+m+"-"+day;

document.getElementById("renewal_date").value=y+"-"+m+"-"+day;

}

function calculateTotal(){

let amount=parseFloat(document.getElementById("amount").value)||0;

let gst=parseFloat(document.getElementById("gst_percent").value)||0;

let discount=parseFloat(document.getElementById("discount").value)||0;

let gstAmount=(amount*gst)/100;

let total=amount+gstAmount-discount;

document.getElementById("total_amount").value=total.toFixed(2);

}

document.getElementById("start_date").addEventListener("change",calculateRenewal);

document.getElementById("amount").addEventListener("input",calculateTotal);

document.getElementById("gst_percent").addEventListener("input",calculateTotal);

document.getElementById("discount").addEventListener("input",calculateTotal);

window.onload=function(){

calculateRenewal();

calculateTotal();

};

document.getElementById("renewForm").addEventListener("submit",function(e){

let startDate=document.getElementById("start_date").value;

let amount=parseFloat(document.getElementById("amount").value)||0;

let total=parseFloat(document.getElementById("total_amount").value)||0;

if(startDate==""){

alert("Please select New Start Date.");

document.getElementById("start_date").focus();

e.preventDefault();

return false;

}

if(amount<=0){

alert("Amount should be greater than zero.");

document.getElementById("amount").focus();

e.preventDefault();

return false;

}

if(total<=0){

alert("Invalid Total Amount.");

e.preventDefault();

return false;

}

return true;

});

</script>

</body>

</html>