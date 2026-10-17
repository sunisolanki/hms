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

$invoiceId=isset($_GET['id'])?(int)$_GET['id']:0;

if($invoiceId<=0){
    die("Invalid Invoice.");
}

$stmt=$conn->prepare("
SELECT
    si.*,
    ss.plan_name,
    ss.start_date,
    ss.end_date,
    hm.hospital_code,
    hm.hospital_name,
    hm.director_name,
    hm.address,
    hm.city,
    hm.state,
    hm.country,
    hm.pincode,
    hm.mobile,
    hm.phone,
    hm.email,
    hm.website,
    hm.gstin,
    hm.logo
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

$page_title="Print Invoice";
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

body{
font-family:Arial,Helvetica,sans-serif;
background:#f5f5f5;
margin:0;
padding:20px;
}

.invoice-container{
max-width:900px;
margin:auto;
background:#fff;
border:1px solid #ccc;
padding:25px;
}

.print-buttons{
display:flex;
justify-content:flex-end;
gap:10px;
margin-bottom:20px;
}

.btn{
padding:10px 18px;
border:none;
border-radius:4px;
text-decoration:none;
cursor:pointer;
font-size:14px;
}

.btn-primary{
background:#0d6efd;
color:#fff;
}

.btn-secondary{
background:#6c757d;
color:#fff;
}

@media print{

.print-buttons{
display:none;
}

body{
background:#fff;
padding:0;
}

.invoice-container{
border:none;
box-shadow:none;
max-width:100%;
}

}

</style>

</head>

<body>

<div class="invoice-container">

<div class="print-buttons">

<button
class="btn btn-primary"
onclick="window.print();">
<i class="fa fa-print"></i>
Print
</button>

<button
class="btn btn-secondary"
onclick="window.close();">
Close
</button>

</div>
<div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:20px;">

<div style="display:flex;gap:15px;">

<?php if(!empty($invoice['logo']) && file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$invoice['logo'])){ ?>

<img
src="/<?= htmlspecialchars($invoice['logo']); ?>"
style="width:90px;height:90px;object-fit:contain;">

<?php } ?>

<div>

<h2 style="margin:0;font-size:28px;color:#0d6efd;">

<?= htmlspecialchars($invoice['hospital_name']); ?>

</h2>

<?php if(!empty($invoice['director_name'])){ ?>

<div>
<b>Director :</b>
<?= htmlspecialchars($invoice['director_name']); ?>
</div>

<?php } ?>

<div>

<?= nl2br(htmlspecialchars($invoice['address'])); ?>

</div>

<div>

<?= htmlspecialchars($invoice['city']); ?>

<?php if($invoice['state']!=""){ ?>,
<?= htmlspecialchars($invoice['state']); ?>
<?php } ?>

<?php if($invoice['country']!=""){ ?>,
<?= htmlspecialchars($invoice['country']); ?>
<?php } ?>

<?php if($invoice['pincode']!=""){ ?>

- <?= htmlspecialchars($invoice['pincode']); ?>

<?php } ?>

</div>

<?php if($invoice['mobile']!=""){ ?>

<div>

<b>Mobile :</b>

<?= htmlspecialchars($invoice['mobile']); ?>

</div>

<?php } ?>

<?php if($invoice['phone']!=""){ ?>

<div>

<b>Phone :</b>

<?= htmlspecialchars($invoice['phone']); ?>

</div>

<?php } ?>

<?php if($invoice['email']!=""){ ?>

<div>

<b>Email :</b>

<?= htmlspecialchars($invoice['email']); ?>

</div>

<?php } ?>

<?php if($invoice['website']!=""){ ?>

<div>

<b>Website :</b>

<?= htmlspecialchars($invoice['website']); ?>

</div>

<?php } ?>

<?php if($invoice['gstin']!=""){ ?>

<div>

<b>GSTIN :</b>

<?= htmlspecialchars($invoice['gstin']); ?>

</div>

<?php } ?>

</div>

</div>

<div style="text-align:right;min-width:280px;">

<h2 style="margin:0 0 10px 0;">

TAX INVOICE

</h2>

<table style="width:100%;border-collapse:collapse;">

<tr>

<td style="padding:6px;border:1px solid #ccc;"><b>Invoice No.</b></td>

<td style="padding:6px;border:1px solid #ccc;">
<?= htmlspecialchars($invoice['invoice_no']); ?>
</td>

</tr>

<tr>

<td style="padding:6px;border:1px solid #ccc;"><b>Invoice Date</b></td>

<td style="padding:6px;border:1px solid #ccc;">
<?= date('d-m-Y',strtotime($invoice['invoice_date'])); ?>
</td>

</tr>

<tr>

<td style="padding:6px;border:1px solid #ccc;"><b>Billing Type</b></td>

<td style="padding:6px;border:1px solid #ccc;">
<?= htmlspecialchars($invoice['billing_type']); ?>
</td>

</tr>

<tr>

<td style="padding:6px;border:1px solid #ccc;"><b>Billing From</b></td>

<td style="padding:6px;border:1px solid #ccc;">
<?= date('d-m-Y',strtotime($invoice['billing_from'])); ?>
</td>

</tr>

<tr>

<td style="padding:6px;border:1px solid #ccc;"><b>Billing To</b></td>

<td style="padding:6px;border:1px solid #ccc;">
<?= date('d-m-Y',strtotime($invoice['billing_to'])); ?>
</td>

</tr>

<tr>

<td style="padding:6px;border:1px solid #ccc;"><b>Plan</b></td>

<td style="padding:6px;border:1px solid #ccc;">
<?= htmlspecialchars($invoice['plan_name']); ?>
</td>

</tr>

</table>

</div>

</div>
<table style="width:100%;border-collapse:collapse;margin-top:20px;">

<tr style="background:#f2f2f2;">

<th style="border:1px solid #ccc;padding:10px;text-align:left;">Description</th>

<th style="border:1px solid #ccc;padding:10px;text-align:right;">Amount (₹)</th>

</tr>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
<?= htmlspecialchars($invoice['plan_name']); ?>
Subscription (<?= htmlspecialchars($invoice['billing_type']); ?>)
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
<?= number_format($invoice['amount'],2); ?>
</td>

</tr>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
GST (<?= number_format($invoice['gst_percent'],2); ?>%)
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
<?= number_format($invoice['gst_amount'],2); ?>
</td>

</tr>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
Discount
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
<?= number_format($invoice['discount'],2); ?>
</td>

</tr>

<tr style="background:#f9f9f9;font-weight:bold;">

<td style="border:1px solid #ccc;padding:10px;">
Total Amount
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
₹ <?= number_format($invoice['total_amount'],2); ?>
</td>

</tr>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
Paid Amount
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
₹ <?= number_format($invoice['paid_amount'],2); ?>
</td>

</tr>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
Balance Amount
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
₹ <?= number_format($invoice['balance_amount'],2); ?>
</td>

</tr>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
Payment Status
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
<?= htmlspecialchars($invoice['payment_status']); ?>
</td>

</tr>

<?php if(!empty($invoice['payment_mode'])){ ?>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
Payment Mode
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
<?= htmlspecialchars($invoice['payment_mode']); ?>
</td>

</tr>

<?php } ?>

<?php if(!empty($invoice['transaction_no'])){ ?>

<tr>

<td style="border:1px solid #ccc;padding:10px;">
Transaction No.
</td>

<td style="border:1px solid #ccc;padding:10px;text-align:right;">
<?= htmlspecialchars($invoice['transaction_no']); ?>
</td>

</tr>

<?php } ?>

</table>

<?php if(!empty($invoice['remarks'])){ ?>

<div style="margin-top:25px;">

<h3 style="margin-bottom:8px;">Remarks</h3>

<div style="border:1px solid #ccc;padding:12px;min-height:60px;">

<?= nl2br(htmlspecialchars($invoice['remarks'])); ?>

</div>

</div>

<?php } ?>

<div style="display:flex;justify-content:space-between;margin-top:70px;">

<div style="width:250px;text-align:center;">

<div style="border-top:1px solid #000;padding-top:8px;">

Customer Signature

</div>

</div>

<div style="width:250px;text-align:center;">

<div style="border-top:1px solid #000;padding-top:8px;">

Authorized Signatory

</div>

</div>

</div>

<div style="margin-top:40px;text-align:center;font-size:13px;color:#666;">

<strong>Thank you for your business!</strong>

<br>

This is a computer-generated invoice and does not require a physical signature.

</div>

</div>

</body>

</html>
