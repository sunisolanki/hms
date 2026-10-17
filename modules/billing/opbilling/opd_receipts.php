<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

ini_set('display_errors',1);
error_reporting(E_ALL);

if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}

$selected_date=$_GET['date'] ?? date('Y-m-d');
$search=trim($_GET['search'] ?? '');

$prev_date=date('Y-m-d',strtotime($selected_date.' -1 day'));
$next_date=date('Y-m-d',strtotime($selected_date.' +1 day'));

$where = "WHERE b.status <> 'Cancelled'";

if(!isset($_GET['all'])){
    $where .= "
    AND DATE(pay.payment_date) = '$selected_date'
    ";
}

if($search!=''){
    $safe_search=mysqli_real_escape_string($conn, $search);
    
    $where .= "
    AND (
        b.bill_no LIKE '%$safe_search%'
        OR a.opd_no LIKE '%$safe_search%'
        OR a.appointment_code LIKE '%$safe_search%'
        OR pm.uhid LIKE '%$safe_search%'
        OR a.mobile LIKE '%$safe_search%'
        OR CONCAT(pm.first_name, ' ', pm.last_name) LIKE '%$safe_search%'
    )
    ";
}

$query = "
SELECT
    pay.id AS payment_id,
    pay.receipt_no,
    pay.payment_date,
    pay.payment_mode,
    pay.amount,
    pay.balance_after_payment,
    pay.transaction_id,
    pay.reference_no,
    pay.bank_name,
    b.id AS bill_id,
    b.bill_no,
    b.grand_total,
    b.paid_amount,
    b.balance_amount,
    b.status AS bill_status,
    a.opd_no,
    a.appointment_code,
    a.token_no,
    a.title,
    a.first_name,
    a.last_name,
    a.mobile,
    pm.uhid,
    u.full_name AS doctor_name,
    d.department_name,
    em.empanelment_name,
    hm.hospital_name,
    bm.branch_name
FROM opd_bill_payments pay
INNER JOIN opd_bills b ON b.id = pay.bill_id
LEFT JOIN appointments a ON a.id = b.opd_id
LEFT JOIN patient_master pm ON pm.id = b.patient_id
LEFT JOIN users u ON u.id = a.entity_id
LEFT JOIN department_master d ON d.id = b.department_id
LEFT JOIN empanelment_master em ON em.id = b.empanelment_id
LEFT JOIN hospital_master hm ON hm.id = b.hospital_id
LEFT JOIN branch_master bm ON bm.id = b.branch_id
$where
ORDER BY pay.payment_date DESC, pay.id DESC
";

$result=mysqli_query($conn,$query);

if(!$result){
    die(
        "Receipt Query Error: ".
        mysqli_error($conn)
    );
}

function e($value){
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// FIX: Store results in an array instead of using mysqli_data_seek
$receipts = [];
while($row = mysqli_fetch_assoc($result)){
    $receipts[] = $row;
}
$receipt_count = count($receipts);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OPD Receipts</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>
.table-wrap{
background:#fff;
border-radius:10px;
padding:1px;
box-shadow:0 10px 25px rgba(0,0,0,.08);
overflow:auto;
height:620px;
}
table{
width:100%;
border-collapse:collapse;
font-size:13px;
white-space:nowrap;
}
thead th{
background:#0b5ed7;
color:#fff;
position:sticky;
top:0;
padding:9px 10px;
border:1px solid #e5e7eb;
text-align:left;
}
tbody td{
border:1px solid #e5e7eb;
padding:7px 10px;
text-align:left;
}
tbody tr:hover{
background:#f8fbff;
}
.search-box{
padding:8px 12px;
width:320px;
border:1px solid #ccc;
border-radius:6px;
}
.action-dropdown{
position:relative;
display:inline-block;
}
.action-btn{
background:#1976d2;
color:#fff;
border:none;
padding:5px 10px;
border-radius:5px;
cursor:pointer;
font-size:16px;
}
.action-menu{
display:none;
position:absolute;
left:0;
top:100%;
background:#fff;
min-width:220px;
box-shadow:0 8px 18px rgba(0,0,0,.18);
border-radius:8px;
overflow:hidden;
z-index:9999;
}
.action-menu a{
display:block;
padding:10px 14px;
text-decoration:none;
color:#333;
font-size:13px;
border-bottom:1px solid #eee;
}
.action-menu a:hover{
background:#f5f7fb;
}
.receipt-paid{
background:#d4edda;
color:#155724;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
}
.receipt-partial{
background:#fff3cd;
color:#856404;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
}
.receipt-due{
background:#f8d7da;
color:#721c24;
padding:4px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
}
#billPopup{
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,.55);
z-index:999999;
}
.bill-popup-inner{
position:absolute;
top:0;
left:0;
width:100%;
height:100%;
background:#fff;
}
.bill-popup-close{
position:absolute;
top:20px;
right:25px;
z-index:999999;
padding:8px 15px;
background:#d32f2f;
color:#fff;
border:none;
border-radius:5px;
cursor:pointer;
font-size:14px;
}
#billFrame{
width:100%;
height:100%;
border:none;
}
.empty-row{
text-align:center !important;
padding:35px !important;
color:#64748b;
font-size:14px;
}
@media(max-width:992px){
.table-wrap{
height:auto;
}
.search-box{
width:220px;
}
}
</style>
</head>
<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">

<div style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;">

<div style="flex:1;text-align:left;min-width:150px;">
<h2 style="margin:0;">🧾 OPD Receipts</h2>
</div>

<div style="flex:1;text-align:center;min-width:200px;">
<a href="?date=<?= e($prev_date) ?>&search=<?= urlencode($search) ?><?= isset($_GET['all'])?'&all=1':'' ?>" style="text-decoration:none;font-size:22px;font-weight:bold;padding:0 10px;">&#10094;</a>
<span id="dateDisplay" onclick="document.getElementById('jumpDate').showPicker();" style="font-size:18px;font-weight:600;cursor:pointer;padding:6px 12px;border-radius:6px;background:#f3f4f6;">
<?= date('d M Y', strtotime($selected_date)) ?> 📅
</span>
<a href="?date=<?= e($next_date) ?>&search=<?= urlencode($search) ?><?= isset($_GET['all'])?'&all=1':'' ?>" style="text-decoration:none;font-size:22px;font-weight:bold;padding:0 10px;">&#10095;</a>
<input type="date" id="jumpDate" value="<?= e($selected_date) ?>" style="position:absolute;opacity:0;width:1px;height:1px;pointer-events:none;">
</div>

<div style="flex:1;text-align:center;min-width:250px;">
<form method="get" style="display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap: nowrap;flex-direction: row;">
<?php if(isset($_GET['all'])){ ?>
<input type="hidden" name="all" value="1">
<?php } ?>
<input type="hidden" name="date" value="<?= e($selected_date) ?>">
<input type="text" name="search" value="<?= e($search) ?>" placeholder="Search Bill No / UHID / OPD No / Patient" class="search-box">
<button type="submit">🔍</button>
</form>
</div>

<div style="flex:1;text-align:right;min-width:100px;">
<?php if(isset($_GET['all'])){ ?>
<button type="button" onclick="window.location='opd_receipts.php';">📅 Today</button>
<?php }else{ ?>
<button type="button" onclick="window.location='opd_receipts.php?all=1';">📋 All</button>
<?php } ?>
</div>

</div>
<!--
<div style="margin:12px 0;font-size:13px;color:#64748b;">
Receipts Found: <strong><?= $receipt_count ?></strong>
</div> -->

<div class="table-wrap">
<table>
<thead>
<tr>
<th>Action</th>
<th>Receipt No.</th>
<th>Receipt Date</th>
<th>Bill No.</th>
<th>OPD No.</th>
<th>UHID</th>
<th>Patient</th>
<th>Doctor</th>
<th>Department</th>
<th>Payment Mode</th>
<th>Bill Amount</th>
<th>Received</th>
<th>Due</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php if($receipt_count > 0){ ?>
<?php foreach($receipts as $row){ ?>
<tr>
<td>
<div class="action-dropdown">
<button type="button" class="action-btn">⋮</button>
<div class="action-menu">
<a href="javascript:void(0);" onclick="openPrintReceiptPopup('<?= e($row['receipt_no']) ?>')">🧾 Print Receipt</a>
</div>
</div>
</td>
<td><?= e($row['receipt_no']) ?></td>
<td>
<?= date('d-m-Y', strtotime($row['payment_date'])) ?>
<br>
<small><?= date('h:i A', strtotime($row['payment_date'])) ?></small>
</td>
<td><?= e($row['bill_no']) ?></td>
<td><?= e($row['opd_no']) ?></td>
<td><?= e($row['uhid']) ?></td>
<td>
<b><?= e(trim(($row['title'] ?? '').' '.($row['first_name'] ?? '').' '.($row['last_name'] ?? ''))) ?></b>
<br>
<small><?= e($row['mobile']) ?></small>
</td>
<td><?= e($row['doctor_name'] ?? '-') ?></td>
<td><?= e($row['department_name'] ?? '-') ?></td>
<td><?= e($row['payment_mode'] ?? '-') ?></td>
<td align="right">₹ <?= number_format((float)($row['grand_total'] ?? 0), 2) ?></td>
<td align="right">₹ <?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
<td align="right">₹ <?= number_format((float)($row['balance_after_payment'] ?? 0), 2) ?></td>
<td>
<?php if((float)$row['balance_after_payment'] <= 0){ ?>
<span class="receipt-paid">Paid</span>
<?php }else{ ?>
<span class="receipt-partial">Partial</span>
<?php } ?>
</td>
</tr>
<?php } ?>
<?php }else{ ?>
<tr>
<td colspan="14" class="empty-row">No receipts found.</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>

</div>
</div>

<div id="billPopup">
<div class="bill-popup-inner">
<button type="button" class="bill-popup-close" onclick="closeBillPopup()">✖</button>
<iframe id="billFrame"></iframe>
</div>
</div>

<script>
document.querySelectorAll('.action-btn').forEach(function(btn){
btn.addEventListener('click', function(e){
e.stopPropagation();
document.querySelectorAll('.action-menu').forEach(function(menu){
menu.style.display='none';
});
this.nextElementSibling.style.display='block';
});
});

document.addEventListener('click', function(){
document.querySelectorAll('.action-menu').forEach(function(menu){
menu.style.display='none';
});
});

document.getElementById('jumpDate').addEventListener('change', function(){
let url='opd_receipts.php?date='+encodeURIComponent(this.value);
<?php if($search!=''){ ?>
url+='&search=<?= urlencode($search) ?>';
<?php } ?>
<?php if(isset($_GET['all'])){ ?>
url+='&all=1';
<?php } ?>
window.location.href=url;
});

function openReceivePaymentPopup(bill_id){
document.getElementById('billPopup').style.display='block';
document.getElementById('billFrame').src='/modules/billing/opbilling/receive_payment.php?bill_id='+encodeURIComponent(bill_id);
document.body.style.overflow='hidden';
}

function openPrintReceiptPopup(receipt_no){
document.getElementById('billPopup').style.display='block';
document.getElementById('billFrame').src='/modules/billing/opbilling/print_receipt.php?receipt_no='+encodeURIComponent(receipt_no);
document.body.style.overflow='hidden';
}

function closeBillPopup(){
document.getElementById('billPopup').style.display='none';
document.getElementById('billFrame').src='';
document.body.style.overflow='auto';
}
</script>

</body>
</html>