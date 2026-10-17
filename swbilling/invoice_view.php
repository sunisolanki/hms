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

if(!checkPermission('swbilling','view')){
    header("Location:/access_denied.php");
    exit;
}

$user_id=(int)$_SESSION['user_id'];

$invoiceId=isset($_GET['id'])?(int)$_GET['id']:0;
if($invoiceId<=0){
    die("Invalid Invoice.");
}

$invoice=[];

if($invoiceId>0){

    $stmt=$conn->prepare("
        SELECT
            si.*,
            ss.hospital_id,
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
}


$page_title = "View Invoice";
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
color:#333;
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

.breadcrumb span{
margin:0 5px;
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

.top-bar{
flex-direction:column;
align-items:flex-start;
gap:10px;
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

<span>&gt;</span>

<a href="/swbilling/index.php">Software Billing</a>

<span>&gt;</span>

<a href="invoices.php">Invoices</a>

<span>&gt;</span>

<?= $page_title; ?>

</div>

</div>

<div>

<a
href="invoices.php"
class="btn">

<i class="fa fa-arrow-left"></i>

Back

</a>

</div>

</div>
<div class="form-grid">
<div class="form-group">
<label>
Invoice Date <span style="color:red">*</span>
</label>
<input
type="date"
name="invoice_date"
id="invoice_date"
value="<?= $invoice['invoice_date']; ?>"

readonly >

</div>

<div class="form-group">

<label>

Hospital

</label>

<input
type="text"
id="hospital_name"
class="readonly"
readonly
value="<?= htmlspecialchars($invoice['hospital_name']); ?>">

</div>

<div class="form-group">

<label>

Plan Name

</label>

<input
type="text"
name="plan_name"
id="plan_name"
class="readonly"
readonly
value="<?= htmlspecialchars($invoice['plan_name']); ?>">

</div>

<div class="form-group">

<label>

Billing Type

</label>

<input
type="text"
name="billing_type"
id="billing_type"
class="readonly"
readonly
value="<?= htmlspecialchars($invoice['billing_type']); ?>">

</div>

<div class="form-group">

<label>

Billing From

</label>

<input
type="date"
name="billing_from"
id="billing_from"
class="readonly"
readonly
value="<?= $invoice['billing_from']; ?>">

</div>

<div class="form-group">

<label>

Billing To

</label>

<input
type="date"
name="billing_to"
id="billing_to"
class="readonly"
readonly
value="<?= $invoice['billing_to']; ?>">

</div>
<div class="form-group">

<label>

Amount (₹)

</label>

<input
type="number"
step="0.01"
name="amount"
id="amount"
class="readonly"
readonly
value="<?= $invoice['amount']; ?>">

</div>

<div class="form-group">

<label>

GST (%)

</label>

<input
type="number"
step="0.01"
name="gst_percent"
id="gst_percent"
class="readonly"
readonly
value="<?= $invoice['gst_percent']; ?>">

</div>

<div class="form-group">

<label>

GST Amount (₹)

</label>

<input
type="number"
step="0.01"
name="gst_amount"
id="gst_amount"
class="readonly"
readonly
value="<?= $invoice['gst_amount']; ?>">

</div>

<div class="form-group">

<label>

Discount (₹)

</label>

<input
type="number"
step="0.01"
name="discount"
id="discount"
class="readonly"
readonly
value="<?= $invoice['discount']; ?>">
</div>
<div class="form-group">
<label>
Total Amount (₹)
</label>
<input
type="number"
step="0.01"
name="total_amount"
id="total_amount"
class="readonly"
readonly
value="<?= $invoice['total_amount']; ?>">

</div>
</div>

<div class="form-group full-width">

<label>

Remarks

</label>

<textarea
name="remarks"
id="remarks"
rows="4"
readonly ><?= htmlspecialchars($invoice['remarks'] ?? ''); ?></textarea>

</div>

<div class="form-group full-width">

<div class="action-buttons">
<a
href="invoice_print.php?id=<?= $invoice['id']; ?>"
target="_blank"
class="btn btn-primary">
<i class="fa-solid fa-print"></i>
Print Invoice
</a>

<a
href="payments.php?invoice_id=<?= $invoice['id']; ?>"
class="btn btn-success">
<i class="fa-solid fa-money-bill-wave"></i>
Receive Payment
</a>

<a
href="invoices.php"
class="btn btn-danger">
<i class="fa fa-times"></i>
Back
</a>
</div>
</div>
</div>
</div>
</body>
</html>