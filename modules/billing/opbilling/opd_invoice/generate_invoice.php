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
LOAD OPD / CONSULTATION
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
$consultation_total=(float)($opd['net_payable'] ?? 0);
$consultation_paid=(float)($opd['paid_amount'] ?? 0);
$consultation_pending=(float)($opd['balance_amount'] ?? 0);
/*==================================================
LOAD SERVICE BILLS
==================================================*/
$bill_stmt=$conn->prepare("
SELECT
b.*
FROM opd_bills b
WHERE b.opd_id=?
AND b.status!='Cancelled'
ORDER BY b.bill_date ASC,b.id ASC
");
$bill_stmt->bind_param("i",$opd_id);
$bill_stmt->execute();
$bill_result=$bill_stmt->get_result();
$bills=[];
$service_total=0;
$service_paid=0;
$service_pending=0;
while($bill=$bill_result->fetch_assoc()){
$bill_id=(int)$bill['id'];
$service_total+=(float)$bill['net_payable'];
$service_paid+=(float)$bill['paid_amount'];
$service_pending+=(float)$bill['balance_amount'];
/* Individual Services */
$detail_stmt=$conn->prepare("
SELECT
bd.*,
sm.service_name
FROM opd_bill_details bd
LEFT JOIN service_master sm ON sm.id=bd.service_id
WHERE bd.bill_id=?
AND bd.status='Active'
ORDER BY bd.id ASC
");
$detail_stmt->bind_param("i",$bill_id);
$detail_stmt->execute();
$detail_result=$detail_stmt->get_result();
$bill['details']=[];
while($detail=$detail_result->fetch_assoc()){
$bill['details'][]=$detail;
}
$detail_stmt->close();
/* Payment History */
$payment_stmt=$conn->prepare("
SELECT
pay.*,
u.full_name AS received_by_name
FROM opd_bill_payments pay
LEFT JOIN users u ON u.id=pay.received_by
WHERE pay.bill_id=?
AND pay.status='Success'
ORDER BY pay.payment_date ASC,pay.id ASC
");
$payment_stmt->bind_param("i",$bill_id);
$payment_stmt->execute();
$payment_result=$payment_stmt->get_result();
$bill['payments']=[];
while($payment=$payment_result->fetch_assoc()){
$bill['payments'][]=$payment;
}
$payment_stmt->close();
$bills[]=$bill;
}
$bill_stmt->close();
$total_charge=$consultation_total+$service_total;
$total_received=$consultation_paid+$service_paid;
$total_pending=$consultation_pending+$service_pending;
$overall_status=$total_pending<=0?'PAID':'PENDING';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OPD Invoice - <?= e($opd['opd_no']) ?></title>
<style>
*{box-sizing:border-box;}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;color:#333;}
.container{max-width:1400px;margin:auto;padding:20px;}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;gap:15px;}
.topbar h2{margin:0;color:#0b5ed7;}
.topbar p{margin:5px 0 0;color:#666;font-size:13px;}
.btn{display:inline-block;padding:10px 16px;border:none;border-radius:5px;text-decoration:none;font-size:14px;cursor:pointer;}
.btn-primary{background:#0b5ed7;color:#fff;}
.btn-success{background:#198754;color:#fff;}
.btn-secondary{background:#6c757d;color:#fff;}
.card{background:#fff;border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.section-title{background:#0b5ed7;color:#fff;padding:10px 14px;font-weight:bold;font-size:15px;margin:-20px -20px 15px;border-radius:8px 8px 0 0;}
.info-table{width:100%;border-collapse:collapse;}
.info-table td{border:1px solid #ddd;padding:9px;font-size:13px;}
.info-table .label{background:#f8f9fa;font-weight:bold;width:15%;}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px;}
.summary-box{background:#fff;border-radius:8px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-left:5px solid #0b5ed7;}
.summary-box .label{font-size:12px;color:#666;}
.summary-box .value{font-size:23px;font-weight:bold;margin-top:7px;}
.summary-box.pending-box{border-left-color:#dc3545;}
.summary-box.pending-box .value{color:#dc3545;}
.summary-box.received-box{border-left-color:#198754;}
.summary-box.received-box .value{color:#198754;}
table{width:100%;border-collapse:collapse;}
th{background:#0b5ed7;color:#fff;padding:10px 8px;font-size:13px;text-align:left;}
td{padding:9px 8px;border:1px solid #ddd;font-size:13px;}
.text-right{text-align:right;}
.pending{color:#dc3545;font-weight:bold;}
.paid{color:#198754;font-weight:bold;}
.status{padding:5px 9px;border-radius:15px;font-size:11px;font-weight:bold;display:inline-block;}
.status-paid{background:#d1e7dd;color:#0f5132;}
.status-pending{background:#f8d7da;color:#842029;}
.bill-block{border:1px solid #d8d8d8;border-radius:7px;margin-bottom:20px;overflow:hidden;}
.bill-header{background:#f5f7fa;padding:12px 15px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;gap:10px;}
.bill-header h4{margin:0;color:#0b5ed7;}
.bill-summary{display:flex;gap:20px;flex-wrap:wrap;font-size:13px;}
.bill-summary span b{margin-right:4px;}
.sub-title{font-size:14px;font-weight:bold;padding:10px 12px;background:#eef4fb;color:#0b5ed7;border-top:1px solid #ddd;border-bottom:1px solid #ddd;}
.table-wrap{overflow-x:auto;}
.no-data{text-align:center;padding:20px;color:#777;}
.actions{text-align:right;margin-top:20px;}
@media(max-width:900px){
.summary{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:600px){
.container{padding:10px;}
.topbar{flex-direction:column;align-items:flex-start;}
.summary{grid-template-columns:1fr;}
.info-table .label{width:35%;}
}
</style>
</head>
<body>
<div class="container">
<div class="topbar">
<div>
<h2>OPD Consolidated Invoice</h2>
<p>OPD No. : <b><?= e($opd['opd_no']) ?></b></p>
</div>
<div>
    <a
href="print_invoice.php?opd_id=<?= (int)$opd_id ?>"
class="btn btn-primary"
onclick="
window.open(
this.href,
'_blank',
'width=1000,height=850,scrollbars=yes,resizable=yes'
);
return false;
">
🖨 Print Invoice
</a>
<a href="index.php" class="btn btn-secondary">← Back</a>
<?php if($total_pending>0){ ?>
<a href="pay_balance.php?opd_id=<?= $opd_id ?>" class="btn btn-success">₹ Pay Balance</a>
<?php } ?>
</div>
</div>
<div class="summary">
<div class="summary-box">
<div class="label">Total Charges</div>
<div class="value">₹<?= number_format($total_charge,2) ?></div>
</div>
<div class="summary-box received-box">
<div class="label">Total Received</div>
<div class="value">₹<?= number_format($total_received,2) ?></div>
</div>
<div class="summary-box pending-box">
<div class="label">Total Pending</div>
<div class="value">₹<?= number_format($total_pending,2) ?></div>
</div>
<div class="summary-box">
<div class="label">Payment Status</div>
<div class="value <?= $overall_status=='PAID'?'paid':'pending' ?>"><?= $overall_status ?></div>
</div>
</div>
<div class="card">
<div class="section-title">Patient & OPD Details</div>
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
<tr>
<td class="label">Registration Date</td>
<td><?= !empty($opd['appointment_date'])?date("d-m-Y",strtotime($opd['appointment_date'])):'-' ?></td>
<td class="label">Token No.</td>
<td><?= e($opd['token_no'] ?: '-') ?></td>
</tr>
</table>
</div>
<div class="card">
<div class="section-title">1. Consultation</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Particular</th>
<th class="text-right">Charge</th>
<th class="text-right">Received</th>
<th class="text-right">Pending</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<tr>
<td>Consultation - <?= e($opd['doctor_name'] ?: 'Doctor') ?></td>
<td class="text-right">₹<?= number_format($consultation_total,2) ?></td>
<td class="text-right paid">₹<?= number_format($consultation_paid,2) ?></td>
<td class="text-right <?= $consultation_pending>0?'pending':'paid' ?>">₹<?= number_format($consultation_pending,2) ?></td>
<td>
<?php if($consultation_pending>0){ ?>
<span class="status status-pending">PENDING</span>
<?php }else{ ?>
<span class="status status-paid">PAID</span>
<?php } ?>
</td>
</tr>
</tbody>
</table>
</div>
</div>
<div class="card">
<div class="section-title">2. Service Bills</div>
<?php if(empty($bills)){ ?>
<div class="no-data">No service bills found for this OPD.</div>
<?php }else{ ?>
<?php foreach($bills as $bill){ ?>
<div class="bill-block">
<div class="bill-header">
<div>
<h4><?= e($bill['bill_no']) ?></h4>
<div style="font-size:12px;color:#666;margin-top:4px;">
Bill Date : <?= !empty($bill['bill_date'])?date("d-m-Y h:i A",strtotime($bill['bill_date'])):'-' ?>
</div>
</div>
<div class="bill-summary">
<span><b>Bill:</b> ₹<?= number_format((float)$bill['net_payable'],2) ?></span>
<span class="paid"><b>Received:</b> ₹<?= number_format((float)$bill['paid_amount'],2) ?></span>
<span class="<?= (float)$bill['balance_amount']>0?'pending':'paid' ?>">
<b>Pending:</b> ₹<?= number_format((float)$bill['balance_amount'],2) ?>
</span>
</div>
</div>
<div class="sub-title">Services</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>#</th>
<th>Service</th>
<th class="text-right">Charge</th>
<th class="text-right">Qty</th>
<th class="text-right">Discount</th>
<th class="text-right">Net Amount</th>
<th class="text-right">Balance</th>
</tr>
</thead>
<tbody>
<?php if(empty($bill['details'])){ ?>
<tr>
<td colspan="7" class="no-data">No services found.</td>
</tr>
<?php }else{ ?>
<?php foreach($bill['details'] as $key=>$detail){ ?>
<tr>
<td><?= $key+1 ?></td>
<td><?= e($detail['service_name'] ?: 'Service #'.$detail['service_id']) ?></td>
<td class="text-right">₹<?= number_format((float)$detail['charge'],2) ?></td>
<td class="text-right"><?= (int)$detail['qty'] ?></td>
<td class="text-right">₹<?= number_format((float)$detail['discount_amount'],2) ?></td>
<td class="text-right">₹<?= number_format((float)$detail['net_amount'],2) ?></td>
<td class="text-right <?= (float)$detail['balance_amount']>0?'pending':'paid' ?>">₹<?= number_format((float)$detail['balance_amount'],2) ?></td>
</tr>
<?php } ?>
<?php } ?>
</tbody>
</table>
</div>
<div class="sub-title">Payment History</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Receipt No.</th>
<th>Date</th>
<th>Payment Mode</th>
<th>Transaction ID</th>
<th>Reference No.</th>
<th class="text-right">Received</th>
<th class="text-right">Balance After Payment</th>
</tr>
</thead>
<tbody>
<?php if(empty($bill['payments'])){ ?>
<tr>
<td colspan="7" class="no-data">No payment received for this bill.</td>
</tr>
<?php }else{ ?>
<?php foreach($bill['payments'] as $payment){ ?>
<tr>
<td><?= e($payment['receipt_no'] ?: '-') ?></td>
<td><?= !empty($payment['payment_date'])?date("d-m-Y h:i A",strtotime($payment['payment_date'])):'-' ?></td>
<td><?= e($payment['payment_mode']) ?></td>
<td><?= e($payment['transaction_id'] ?: '-') ?></td>
<td><?= e($payment['reference_no'] ?: '-') ?></td>
<td class="text-right paid">₹<?= number_format((float)$payment['amount'],2) ?></td>
<td class="text-right <?= (float)$payment['balance_after_payment']>0?'pending':'paid' ?>">₹<?= number_format((float)$payment['balance_after_payment'],2) ?></td>
</tr>
<?php } ?>
<?php } ?>
</tbody>
</table>
</div>
</div>
<?php } ?>
<?php } ?>
</div>
<div class="actions">
<?php if($total_pending>0){ ?>
<a href="pay_balance.php?opd_id=<?= $opd_id ?>" class="btn btn-success">
Pay Total Pending Balance ₹<?= number_format($total_pending,2) ?>
</a>
<?php } ?>
</div>
</div>
</body>
</html>