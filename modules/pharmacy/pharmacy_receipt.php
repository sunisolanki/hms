<!--public_html/modules/pharmacy/pharmacy_receipt.php  -->
<?php

session_start();

date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

ini_set('display_errors',1);
error_reporting(E_ALL);

if(!isset($_SESSION['user_id'])){

    die('Unauthorized');

}

$sale_id=(int)($_GET['sale_id']??0);

if($sale_id<=0){

    die('Invalid Sale ID.');

}

/* =========================================================
   SALE DETAILS
   ========================================================= */

$stmt=$conn->prepare("
SELECT
    ps.id AS sale_id,
    ps.sale_no,
    ps.invoice_id,
    ps.patient_type,
    ps.sale_against,
    ps.patient_id,
    ps.appointment_id,
    ps.prescription_id,
    ps.branch_id,
    ps.store_id,
    ps.patient_name,
    ps.title,
    ps.mobile,
    ps.uhid_op_no,
    ps.aadhar_no,
    ps.location,
    ps.referral_type,
    ps.remarks,
    ps.sale_date,
    ps.sub_total,
    ps.total_discount,
    ps.total_gst,
    ps.round_off,
    ps.grand_total,
ps.status,
ps.cancellation_reason,
ps.cancelled_by,
ps.cancelled_at,
ps.return_payment_mode,
ps.return_amount,

    pi.invoice_no,
    pi.invoice_type,
    pi.invoice_date,

    b.branch_name,
b.address AS branch_address,
b.city AS branch_city,
b.state AS branch_state,
b.mobile AS branch_mobile,
b.email AS branch_email,
b.website AS branch_website,
b.logo AS branch_logo

FROM pharmacy_sales ps

LEFT JOIN pharmacy_invoices pi
    ON pi.id=ps.invoice_id

LEFT JOIN branch_master b
    ON b.id=ps.branch_id

WHERE ps.id=?

LIMIT 1
");

$stmt->bind_param(
    "i",
    $sale_id
);

$stmt->execute();

$result=$stmt->get_result();

if(!$sale=$result->fetch_assoc()){

    die('Pharmacy Sale not found.');

}

$stmt->close();

/* =========================================================
   SALE ITEMS
   ========================================================= */

$stmt=$conn->prepare("
SELECT
    psi.id,
    psi.item_id,
    psi.batch_id,
    psi.batch_no,
    psi.exp_date,
    psi.stock_qty_before,
    psi.qty,
    psi.mrp,
    psi.sale_rate,
    psi.discount_percent,
    psi.discount_value,
    psi.amount,
    psi.gst_percent,
    psi.gst_value,
    psi.net_amount,
    psi.mrn_no,

    im.item_name

FROM pharmacy_sale_items psi

LEFT JOIN item_master im
    ON im.id=psi.item_id

WHERE psi.sale_id=?

ORDER BY psi.id ASC
");

$stmt->bind_param(
    "i",
    $sale_id
);

$stmt->execute();

$result=$stmt->get_result();

$items=$result->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   PAYMENT DETAILS
   ========================================================= */

$stmt=$conn->prepare("
SELECT
    id,
    receipt_no,
    receipt_date,
    payment_mode,
    received_amount,
    balance_amount,
    payment_status,
    cash_remarks,
    upi_app,
    upi_txn_id,
    card_type,
    card_bank_name,
    card_last4,
    card_auth_code,
    cheque_no,
    cheque_bank,
    cheque_date,
    neft_ref,
    neft_bank,
    ecs_bank_name,
    ecs_reference_no,
    ecs_transaction_date

FROM pharmacy_receipts

WHERE sale_id=?

ORDER BY id ASC
");

$stmt->bind_param(
    "i",
    $sale_id
);

$stmt->execute();

$result=$stmt->get_result();

$receipts=$result->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   TOTAL RECEIVED
   ========================================================= */

$total_received=0;

foreach($receipts as $receipt){

    $total_received+=
        (float)$receipt['received_amount'];

}

$total_balance=
    max(
        (float)$sale['grand_total']-
        $total_received,
        0
    );

/* =========================================================
   PAYMENT STATUS
   ========================================================= */
if($sale['status']==='Cancelled'){

    $payment_status='Cancelled';

}elseif($total_balance<=0){

    $payment_status='Paid';

}elseif($total_received>0){

    $payment_status='Partial';

}else{

    $payment_status='Pending';

}
/* =========================================================
   LOGO
   ========================================================= */

$logo='';

if(!empty($sale['branch_logo'])){

    $logo=$sale['branch_logo'];

}

$logo_path='';

if($logo!==''){

    $logo_path=
        $_SERVER['DOCUMENT_ROOT'].
        '/'.
        ltrim($logo,'/');

}

/* =========================================================
   HELPER
   ========================================================= */

function e($value){

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="utf-8">

<title>
Pharmacy Receipt - <?=e($sale['sale_no'])?>
</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<style>

*{
    box-sizing:border-box;
}

body{
    font-family:Arial,Helvetica,sans-serif;
    margin:0;
    padding:0;
    background:#fff;
    color:#111;
}

.page{
    width:210mm;
    min-height:297mm;
    margin:auto;
    padding:8mm;
}

.receipt{
    border:1px solid #000;
    padding:10px;
}

/* =========================================================
   HEADER
   ========================================================= */

.header{
    display:flex;
    align-items:center;
    border-bottom:2px solid #000;
    padding-bottom:8px;
    margin-bottom:10px;
}

.logo{
    width:85px;
    min-width:85px;
    text-align:center;
}

.logo img{
    max-width:72px;
    max-height:72px;
    object-fit:contain;
}

.header-info{
    flex:1;
    text-align:center;
}

.header-info h1{
    margin:0;
    font-size:23px;
    font-weight:700;
}

.header-info h2{
    margin:3px 0;
    font-size:16px;
    font-weight:600;
}

.header-info p{
    margin:2px 0;
    font-size:11px;
}

/* =========================================================
   TITLE
   ========================================================= */

.title{
    text-align:center;
    font-size:18px;
    font-weight:bold;
    margin:10px 0;
    padding:6px;
    border:1px solid #000;
    background:#f5f5f5;
}

/* =========================================================
   STATUS
   ========================================================= */

.status-banner{
    text-align:center;
    padding:7px;
    margin:8px 0;
    border:1px solid #000;
    font-weight:bold;
    font-size:13px;
}

.status-paid{
    background:#d9ead3;
}

.status-partial{
    background:#fff2cc;
}

.status-pending{
    background:#f4cccc;
}
.status-cancelled{
    background:#f4cccc;
    color:#9c0006;
    border:2px solid #9c0006;
}
/* =========================================================
   SECTIONS
   ========================================================= */

.section{
    margin-top:10px;
    border:1px solid #000;
}

.section-title{
    background:#efefef;
    padding:6px;
    font-weight:bold;
    border-bottom:1px solid #000;
    font-size:13px;
}

/* =========================================================
   GENERAL TABLE
   ========================================================= */

.info-table{
    width:100%;
    border-collapse:collapse;
    font-size:12px;
}

.info-table td{
    padding:5px;
    vertical-align:top;
}

.label{
    font-weight:bold;
    width:120px;
}

/* =========================================================
   ITEMS TABLE
   ========================================================= */

.items-table{
    width:100%;
    border-collapse:collapse;
    font-size:11px;
}

.items-table th,
.items-table td{
    border:1px solid #000;
    padding:5px;
}

.items-table th{
    background:#efefef;
    font-weight:bold;
    text-align:center;
}

.items-table td{
    vertical-align:top;
}

.text-center{
    text-align:center;
}

.text-right{
    text-align:right;
}

.item-name{
    font-weight:bold;
}

.amount{
    text-align:right;
    white-space:nowrap;
}

/* =========================================================
   TOTALS
   ========================================================= */

.total-table{
    width:100%;
    border-collapse:collapse;
    font-size:12px;
}

.total-table td{
    padding:5px;
}

.total-label{
    text-align:right;
    font-weight:bold;
    width:75%;
}

.total-value{
    text-align:right;
    width:25%;
}

.grand-total{
    font-size:15px;
    font-weight:bold;
    border-top:2px solid #000;
}

/* =========================================================
   PAYMENT
   ========================================================= */

.payment-table{
    width:100%;
    border-collapse:collapse;
    font-size:12px;
}

.payment-table th,
.payment-table td{
    border:1px solid #000;
    padding:5px;
}

.payment-table th{
    background:#efefef;
    font-weight:bold;
}

.payment-summary{
    margin-top:8px;
    border:1px solid #000;
}

.payment-summary td{
    padding:6px;
    font-size:13px;
}

.payment-summary .label{
    font-weight:bold;
}

/* =========================================================
   FOOTER
   ========================================================= */

.signature-table{
    width:100%;
    margin-top:45px;
    border-collapse:collapse;
}

.signature-table td{
    text-align:center;
    padding:5px;
    font-size:12px;
}

.footer-message{
    margin-top:20px;
    text-align:center;
    font-size:12px;
}

.print-area{
    text-align:center;
    margin:15px;
}

.print-button{
    padding:9px 20px;
    border:0;
    border-radius:5px;
    background:#1976d2;
    color:#fff;
    font-size:14px;
    cursor:pointer;
}

.print-button:hover{
    background:#1565c0;
}

@page{
    size:A4 portrait;
    margin:5mm;
}

@media print{

    body{
        background:#fff;
    }

    .page{
        width:100%;
        min-height:auto;
        padding:0;
    }

    .print-area{
        display:none;
    }

}

</style>

</head>

<body>

<div class="page">

<div class="receipt">

<!-- =====================================================
     HEADER
     ===================================================== -->

<div class="header">

<div class="logo">

<?php

if(
    $logo_path!==''
    &&
    file_exists($logo_path)
){

?>

<img
    src="/<?=e(ltrim($logo,'/'))?>"
    alt="Logo"
>

<?php

}

?>

</div>

<div class="header-info">

<h1>
<?=e($sale['branch_name']??'')?>
</h1>

<h2>
WebSplend EHR
</h2>

<p>

<?=e($sale['branch_address']??'')?>

<?php if(!empty($sale['branch_city'])){ ?>

, <?=e($sale['branch_city'])?>

<?php } ?>

<?php if(!empty($sale['branch_state'])){ ?>

, <?=e($sale['branch_state'])?>

<?php } ?>

<?php if(!empty($sale['branch_pincode'])){ ?>

- <?=e($sale['branch_pincode'])?>

<?php } ?>

</p>

<?php if(!empty($sale['branch_mobile'])){ ?>

<p>
☎ <?=e($sale['branch_mobile'])?>
</p>

<?php } ?>

<?php if(!empty($sale['branch_email'])){ ?>

<p>
<?=e($sale['branch_email'])?>
</p>

<?php } ?>

</div>

</div>

<!-- =====================================================
     TITLE
     ===================================================== -->

<div class="title">

PHARMACY SALE RECEIPT

</div>

<!-- =====================================================
     PAYMENT STATUS
     ===================================================== -->

<?php
$status_class='status-pending';

if($payment_status==='Cancelled'){

    $status_class='status-cancelled';

}elseif($payment_status==='Paid'){

    $status_class='status-paid';

}elseif($payment_status==='Partial'){

    $status_class='status-partial';

}
?>

<div class="status-banner <?=$status_class?>">

Payment Status:
<?=e($payment_status)?>

</div>

<!-- =====================================================
     SALE DETAILS
     ===================================================== -->

<div class="section">

<div class="section-title">

Sale Details

</div>

<table class="info-table">

<tr>

<td class="label">
Sale No.
</td>

<td>
<?=e($sale['sale_no'])?>
</td>

<td class="label">
Invoice No.
</td>

<td>
<?=e($sale['invoice_no']??'-')?>
</td>

</tr>

<tr>

<td class="label">
Sale Date
</td>

<td>

<?=e(
    date(
        'd-m-Y h:i A',
        strtotime($sale['sale_date'])
    )
)?>

</td>

<td class="label">
Invoice Date
</td>

<td>

<?php

if(!empty($sale['invoice_date'])){

    echo e(
        date(
            'd-m-Y',
            strtotime($sale['invoice_date'])
        )
    );

}else{

    echo '-';

}

?>

</td>

</tr>

<tr>

<td class="label">
Patient Type
</td>

<td>
<?=e($sale['patient_type'])?>
</td>

<td class="label">
Sale Against
</td>

<td>
<?=e($sale['sale_against'])?>
</td>

</tr>

<tr>

<td class="label">
Prescription ID
</td>

<td>
<?=e(
    $sale['prescription_id']
    ?: '-'
)?>
</td>

<td class="label">
Appointment ID
</td>

<td>
<?=e(
    $sale['appointment_id']
    ?: '-'
)?>
</td>

</tr>

</table>

</div>

<!-- =====================================================
     PATIENT DETAILS
     ===================================================== -->

<div class="section">

<div class="section-title">

Patient Details

</div>

<table class="info-table">

<tr>

<td class="label">
Patient Name
</td>

<td>

<?=e(
    trim(
        ($sale['title']??'').' '.
        ($sale['patient_name']??'')
    )
)?>

</td>

<td class="label">
UHID / OPD No.
</td>

<td>
<?=e(
    $sale['uhid_op_no']
    ?: '-'
)?>
</td>

</tr>

<tr>

<td class="label">
Mobile
</td>

<td>
<?=e(
    $sale['mobile']
    ?: '-'
)?>
</td>

<td class="label">
Aadhaar
</td>

<td>
<?=e(
    $sale['aadhar_no']
    ?: '-'
)?>
</td>

</tr>

<?php if(!empty($sale['location'])){ ?>

<tr>

<td class="label">
Location
</td>

<td colspan="3">

<?=e($sale['location'])?>

</td>

</tr>

<?php } ?>

</table>

</div>

<!-- =====================================================
     ITEMS
     ===================================================== -->

<div class="section">

<div class="section-title">

Medicine / Item Details

</div>

<table class="items-table">

<thead>

<tr>

<th width="4%">
S.No.
</th>

<th width="20%">
Item Name
</th>

<th width="10%">
Batch
</th>

<th width="10%">
Exp. Date
</th>

<th width="8%">
Qty
</th>

<th width="10%">
MRP
</th>

<th width="10%">
Sale Rate
</th>

<th width="8%">
Dis.(%)
</th>

<th width="10%">
GST
</th>

<th width="12%">
Net Amount
</th>

</tr>

</thead>

<tbody>

<?php

$serial=1;

foreach($items as $item){

?>

<tr>

<td class="text-center">

<?=$serial++?>

</td>

<td class="item-name">

<?=e(
    $item['item_name']
    ?: 'Unknown Item'
)?>

</td>

<td class="text-center">

<?=e(
    $item['batch_no']
    ?: '-'
)?>

</td>

<td class="text-center">

<?php

if(!empty($item['exp_date'])){

    echo e(
        date(
            'd-m-Y',
            strtotime($item['exp_date'])
        )
    );

}else{

    echo '-';

}

?>

</td>

<td class="text-center">

<?=number_format(
    (float)$item['qty'],
    2
)?>

</td>

<td class="amount">

₹<?=number_format(
    (float)$item['mrp'],
    2
)?>

</td>

<td class="amount">

₹<?=number_format(
    (float)$item['sale_rate'],
    2
)?>

</td>

<td class="text-center">

<?=number_format(
    (float)$item['discount_percent'],
    2
)?>%

</td>

<td class="amount">

₹<?=number_format(
    (float)$item['gst_value'],
    2
)?>

<br>

<small>
<?=number_format(
    (float)$item['gst_percent'],
    2
)?>%
</small>

</td>

<td class="amount">

₹<?=number_format(
    (float)$item['net_amount'],
    2
)?>

</td>

</tr>

<?php

}

if(empty($items)){

?>

<tr>

<td
    colspan="10"
    class="text-center"
>

No items found.

</td>

</tr>

<?php

}

?>

</tbody>

</table>

</div>

<!-- =====================================================
     SALE TOTALS
     ===================================================== -->

<div class="section">

<div class="section-title">

Bill Summary

</div>

<table class="total-table">

<tr>

<td class="total-label">
Sub Total
</td>

<td class="total-value">

₹<?=number_format(
    (float)$sale['sub_total'],
    2
)?>

</td>

</tr>

<tr>

<td class="total-label">
Total Discount
</td>

<td class="total-value">

₹<?=number_format(
    (float)$sale['total_discount'],
    2
)?>

</td>

</tr>

<tr>

<td class="total-label">
Total GST
</td>

<td class="total-value">

₹<?=number_format(
    (float)$sale['total_gst'],
    2
)?>

</td>

</tr>

<tr>

<td class="total-label">
Round Off
</td>

<td class="total-value">

₹<?=number_format(
    (float)$sale['round_off'],
    2
)?>

</td>

</tr>

<tr class="grand-total">

<td class="total-label">
Grand Total
</td>

<td class="total-value">

₹<?=number_format(
    (float)$sale['grand_total'],
    2
)?>

</td>

</tr>

</table>

</div>

<!-- =====================================================
     PAYMENT DETAILS
     ===================================================== -->

<div class="section">

<div class="section-title">

Payment Details

</div>

<?php if(!empty($receipts)){ ?>

<table class="payment-table">

<thead>

<tr>

<th>
Receipt No.
</th>

<th>
Date
</th>

<th>
Payment Mode
</th>

<th>
Received
</th>

<th>
Status
</th>

</tr>

</thead>

<tbody>

<?php foreach($receipts as $receipt){ ?>

<tr>

<td>

<?=e($receipt['receipt_no'])?>

</td>

<td>

<?=e(
    date(
        'd-m-Y h:i A',
        strtotime($receipt['receipt_date'])
    )
)?>

</td>

<td>

<?=e(
    $receipt['payment_mode']
    ?: '-'
)?>

</td>

<td class="text-right">

₹<?=number_format(
    (float)$receipt['received_amount'],
    2
)?>

</td>

<td class="text-center">

<?=e(
    $receipt['payment_status']
    ?: '-'
)?>

</td>

</tr>

<?php } ?>

</tbody>

</table>

<?php }else{ ?>

<table class="info-table">

<tr>

<td class="label">
Payment
</td>

<td>
No payment received.
</td>

</tr>

</table>

<?php } ?>

<table
    class="payment-summary"
    style="width:100%;border-collapse:collapse;margin-top:8px;"
>

<tr>

<td class="label">
Total Bill
</td>

<td class="text-right">

₹<?=number_format(
    (float)$sale['grand_total'],
    2
)?>

</td>

<td class="label">
Total Received
</td>

<td class="text-right">

₹<?=number_format(
    $total_received,
    2
)?>

</td>

<td class="label">
Balance
</td>

<td class="text-right">

₹<?=number_format(
    $total_balance,
    2
)?>

</td>

</tr>

</table>

</div>




<?php if($sale['status']==='Cancelled'){ ?>

<div class="section">

<div class="section-title">

Cancellation / Refund Details

</div>

<table class="info-table">

<tr>

<td class="label">
Cancellation Reason
</td>

<td colspan="3">

<?=nl2br(
    e(
        $sale['cancellation_reason']
        ?: '-'
    )
)?>

</td>

</tr>

<tr>

<td class="label">
Cancelled At
</td>

<td>

<?php

if(!empty($sale['cancelled_at'])){

    echo e(
        date(
            'd-m-Y h:i A',
            strtotime(
                $sale['cancelled_at']
            )
        )
    );

}else{

    echo '-';

}

?>

</td>

<td class="label">
Refund Mode
</td>

<td>

<?=e(
    $sale['return_payment_mode']
    ?: '-'
)?>

</td>

</tr>

<tr>

<td class="label">
Refund Amount
</td>

<td>

₹<?=number_format(
    (float)$sale['return_amount'],
    2
)?>

</td>

<td class="label">
Sale Status
</td>

<td>

<strong>
Cancelled
</strong>

</td>

</tr>

</table>

</div>

<?php } ?>
<!-- =====================================================
     REMARKS
     ===================================================== -->

<?php if(!empty($sale['remarks'])){ ?>

<div class="section">

<div class="section-title">

Remarks

</div>

<div style="padding:7px;font-size:12px;">

<?=nl2br(e($sale['remarks']))?>

</div>

</div>

<?php } ?>


<!-- =====================================================
     SIGNATURES
     ===================================================== -->

<table class="signature-table">

<tr>

<td>

________________________

<br>

Patient / Attendant

</td>

<td>

________________________

<br>

Pharmacy Staff

</td>

</tr>

</table>

<div class="footer-message">

<b>
Thank You
</b>

<br>

Please retain this receipt for your records.

</div>

</div>

</div>

<div class="print-area">

<button
    type="button"
    class="print-button"
    onclick="window.print()"
>
🖨 Print Receipt
</button>

</div>

<script>

window.onload=function(){

    window.print();

};

</script>

</body>

</html>