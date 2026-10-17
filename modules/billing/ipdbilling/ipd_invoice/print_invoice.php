<?php

session_start();

date_default_timezone_set('Asia/Kolkata');

ini_set('display_errors',1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

function e($value){
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

if(!isset($_SESSION['user_id'])){
    die("Session expired.");
}

$ipd_bill_id=(int)(
    $_GET['ipd_bill_id'] ?? 0
);

$ipd_admission_id=(int)(
    $_GET['ipd_admission_id'] ?? 0
);

if(
    $ipd_bill_id<=0 &&
    $ipd_admission_id<=0
){
    die("Invalid IPD Bill.");
}

/*=========================================
GET IPD BILL / PATIENT / HOSPITAL
=========================================*/

$stmt=$conn->prepare("
SELECT
    ib.*,

    ia.ipd_no AS admission_ipd_no,
    ia.appointment_id,
    ia.doctor_id,
    ia.department_id,

    p.uhid,
    p.title,
    p.first_name,
    p.last_name,
    p.mobile,

    u.full_name AS doctor_name,

    d.department_name,

    b.branch_name,
    b.address AS branch_address,

    h.hospital_name,
    h.address AS hospital_address,
    h.phone AS hospital_phone,
    h.mobile AS hospital_mobile,
    h.email AS hospital_email,
    h.logo AS hospital_logo

FROM ipd_bills ib

LEFT JOIN ipd_admissions ia
    ON ia.id=ib.ipd_admission_id

LEFT JOIN patient_master p
    ON p.id=ib.patient_id

LEFT JOIN users u
    ON u.id=ia.doctor_id

LEFT JOIN department_master d
    ON d.id=ia.department_id

LEFT JOIN branch_master b
    ON b.id=ia.branch_id

LEFT JOIN hospital_master h
    ON h.id=b.hospital_id

WHERE
(
    ib.id=?
    OR
    (
        ?=0
        AND ib.ipd_admission_id=?
    )
)

ORDER BY ib.id DESC

LIMIT 1
");

if(!$stmt){
    die("Unable to prepare IPD bill query.");
}

$stmt->bind_param(
    "iii",
    $ipd_bill_id,
    $ipd_bill_id,
    $ipd_admission_id
);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows===0){

    $stmt->close();

    die("IPD Bill not found.");
}

$row=$result->fetch_assoc();

$stmt->close();

/*
 * IMPORTANT:
 * Always use the actual IPD bill ID returned
 * from the database for all following queries.
 */

$ipd_bill_id=(int)$row['id'];

/*=========================================
GET BILL ITEMS
=========================================*/

$stmt=$conn->prepare("
SELECT
    item_type,
    item_id,
    item_name,
    description,
    quantity,
    rate,
    amount,
    discount_amount,
    net_amount,
    service_date

FROM ipd_bill_items

WHERE ipd_bill_id=?

ORDER BY id ASC
");

if(!$stmt){
    die("Unable to prepare bill items query.");
}

$stmt->bind_param(
    "i",
    $ipd_bill_id
);

$stmt->execute();

$items=
    $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/*=========================================
GET PAYMENTS AGAINST IPD BILL
=========================================*/

$stmt=$conn->prepare("
SELECT
    payment_no,
    payment_date,
    payment_amount,
    payment_mode,
    transaction_no

FROM ipd_payments

WHERE ipd_bill_id=?

ORDER BY id ASC
");

if(!$stmt){
    die("Unable to prepare payment query.");
}

$stmt->bind_param(
    "i",
    $ipd_bill_id
);

$stmt->execute();

$payments=
    $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/*=========================================
GET PHARMACY BILLS AGAINST IPD
=========================================*/

$pharmacy_sales=[];

$stmt=$conn->prepare("
SELECT
    ps.id,
    ps.sale_no,
    ps.sale_date,
    ps.grand_total,
    ps.status,
    ps.return_payment_mode,
    ps.return_amount,

    pi.invoice_no,

    COALESCE(
        (
            SELECT
                SUM(pr.received_amount)

            FROM pharmacy_receipts pr

            WHERE pr.sale_id=ps.id
        ),
        0
    ) AS received_amount

FROM pharmacy_sales ps

LEFT JOIN pharmacy_invoices pi
    ON pi.id=ps.invoice_id

WHERE ps.appointment_id=?

AND ps.patient_type='IPD'

ORDER BY
    ps.sale_date ASC,
    ps.id ASC
");

if(!$stmt){
    die("Unable to prepare pharmacy bill query.");
}

$stmt->bind_param(
    "i",
    $row['appointment_id']
);

$stmt->execute();

$pharmacy_sales=
    $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/*=========================================
PATIENT NAME
=========================================*/

$patient_name=trim(
    ($row['title'] ?? '').' '.
    ($row['first_name'] ?? '').' '.
    ($row['last_name'] ?? '')
);

if($patient_name===''){
    $patient_name='-';
}

/*=========================================
IPD NUMBER
=========================================*/

$ipd_no=
    $row['ipd_no']
    ??
    $row['admission_ipd_no']
    ??
    '-';

/*=========================================
LOGO
=========================================*/

$logo_src='/assets/images/logo.png';

if(
    !empty($row['hospital_logo'])
    &&
    file_exists(
        $_SERVER['DOCUMENT_ROOT'].
        '/uploads/hospital/'.
        $row['hospital_logo']
    )
){

    $logo_src=
        '/uploads/hospital/'.
        $row['hospital_logo'];
}

/*=========================================
IPD BILL AMOUNTS
=========================================*/

$total_amount=(float)(
    $row['total_amount'] ?? 0
);

$discount_amount=(float)(
    $row['discount_amount'] ?? 0
);

$net_amount=(float)(
    $row['net_amount'] ?? 0
);

$paid_amount=(float)(
    $row['paid_amount'] ?? 0
);

$balance_amount=(float)(
    $row['balance_amount'] ?? 0
);

/*=========================================
PHARMACY TOTALS
=========================================*/

$pharmacy_total=0;

$pharmacy_received=0;

$pharmacy_balance=0;

foreach($pharmacy_sales as $pharmacy){

    /*
     * Cancelled pharmacy bills are kept
     * for history but are not included
     * in the active invoice totals.
     */

    if(
        $pharmacy['status']==='Cancelled'
    ){
        continue;
    }

    $pharmacy_amount=(float)(
        $pharmacy['grand_total']
    );

    $pharmacy_received_amount=(float)(
        $pharmacy['received_amount']
    );

    $pharmacy_total+=
        $pharmacy_amount;

    $pharmacy_received+=
        $pharmacy_received_amount;

    $pharmacy_balance+=
        max(
            $pharmacy_amount-
            $pharmacy_received_amount,
            0
        );
}

/*=========================================
FINAL CONSOLIDATED TOTALS
=========================================*/

$final_net_amount=
    $net_amount+
    $pharmacy_total;

$final_paid_amount=
    $paid_amount+
    $pharmacy_received;

$final_balance_amount=
    max(
        $final_net_amount-
        $final_paid_amount,
        0
    );

/*=========================================
FINAL PAYMENT STATUS
=========================================*/

if(
    $final_net_amount<=0 &&
    $final_paid_amount>0
){

    $final_payment_status=
        'Advance Received';

}elseif(
    $final_net_amount>0 &&
    $final_balance_amount<=0
){

    $final_payment_status=
        'Paid';

}elseif(
    $final_paid_amount>0
){

    $final_payment_status=
        'Partial';

}else{

    $final_payment_status=
        'Pending';
}

?>
<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>
IPD Invoice - <?= e($row['bill_no']) ?>
</title>

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    padding:20px;
    font-family:Arial,Helvetica,sans-serif;
    background:#f5f5f5;
    color:#000;
}

.invoice{
    width:820px;
    margin:auto;
    background:#fff;
    border:2px solid #000;
    padding:20px;
}

.header{
    display:flex;
    align-items:flex-start;
    border-bottom:2px solid #000;
    padding-bottom:15px;
    margin-bottom:12px;
}

.logo-box{
    width:110px;
}

.logo{
    max-width:90px;
    max-height:90px;
    object-fit:contain;
}

.hospital{
    flex:1;
    text-align:center;
    padding-right:90px;
}

.hospital h2{
    margin:0;
    font-size:24px;
}

.hospital p{
    margin:4px 0;
    font-size:13px;
}

.title{
    text-align:center;
    font-size:21px;
    font-weight:bold;
    text-decoration:underline;
    margin:15px 0;
}

.info{
    width:100%;
    border-collapse:collapse;
    margin-bottom:15px;
}

.info td{
    padding:5px 4px;
    font-size:13px;
    vertical-align:top;
}

.info .label{
    font-weight:bold;
    width:15%;
    white-space:nowrap;
}

.item-table{
    width:100%;
    border-collapse:collapse;
    margin-top:15px;
}

.item-table th,
.item-table td{
    border:1px solid #000;
    padding:7px;
    font-size:13px;
}

.item-table th{
    background:#efefef;
    text-align:center;
}

.text-right{
    text-align:right;
}

.text-center{
    text-align:center;
}

.summary{
    width:420px;
    margin-left:auto;
    margin-top:15px;
    border-collapse:collapse;
}

.summary td{
    border:1px solid #000;
    padding:8px;
    font-size:13px;
}

.summary td:first-child{
    font-weight:bold;
}

.summary .grand-total td{
    font-size:14px;
    font-weight:bold;
}

.summary .balance-row td{
    font-weight:bold;
}

.payment-table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}

.payment-table th,
.payment-table td{
    border:1px solid #000;
    padding:7px;
    font-size:13px;
}

.payment-table th{
    background:#efefef;
}

.pharmacy-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
}

.pharmacy-table th,
.pharmacy-table td{
    border:1px solid #000;
    padding:7px;
    font-size:12px;
}

.pharmacy-table th{
    background:#efefef;
    text-align:center;
}

.pharmacy-cancelled{
    background:#fff1f2;
    color:#991b1b;
}

.pharmacy-total-row{
    font-weight:bold;
    background:#f5f5f5;
}

.status-paid{
    color:#166534;
    font-weight:bold;
}

.status-partial{
    color:#92400e;
    font-weight:bold;
}

.status-pending{
    color:#9a3412;
    font-weight:bold;
}

.status-cancelled{
    color:#991b1b;
    font-weight:bold;
}

.status-advance{
    color:#1d4ed8;
    font-weight:bold;
}

.footer{
    margin-top:60px;
    display:flex;
    justify-content:space-between;
}

.signature{
    width:220px;
    text-align:center;
    font-size:13px;
}

.signature hr{
    margin-top:45px;
    border:none;
    border-top:1px solid #000;
}

.note{
    margin-top:35px;
    text-align:center;
    font-size:12px;
    font-style:italic;
}

.actions{
    text-align:center;
    margin-top:25px;
}

.actions button{
    padding:10px 25px;
    margin:5px;
    font-size:14px;
    border:1px solid #777;
    border-radius:5px;
    cursor:pointer;
    background:#fff;
}

@media print{

    @page{
        size:A4;
        margin:10mm;
    }

    body{
        background:#fff;
        padding:0;
        margin:0;
    }

    .invoice{
        width:100%;
        border:none;
        padding:0;
        margin:0;
    }

    .actions{
        display:none;
    }

}

</style>

</head>

<body>

<div class="invoice">

<!-- =========================================
HOSPITAL HEADER
========================================= -->

<div class="header">

<div class="logo-box">

<img
    src="<?= e($logo_src) ?>"
    class="logo"
    alt="Hospital Logo"
>

</div>

<div class="hospital">

<h2>
<?= e($row['hospital_name'] ?? '') ?>
</h2>

<?php if(!empty($row['hospital_address'])){ ?>

<p>
<?= e($row['hospital_address']) ?>
</p>

<?php } ?>

<p>

<?php if(!empty($row['hospital_phone'])){ ?>

Phone:
<?= e($row['hospital_phone']) ?>

<?php } ?>

<?php if(!empty($row['hospital_mobile'])){ ?>

&nbsp;&nbsp; | &nbsp;&nbsp;

Mobile:
<?= e($row['hospital_mobile']) ?>

<?php } ?>

<?php if(!empty($row['hospital_email'])){ ?>

&nbsp;&nbsp; | &nbsp;&nbsp;

Email:
<?= e($row['hospital_email']) ?>

<?php } ?>

</p>

<?php if(!empty($row['branch_name'])){ ?>

<p>

Branch:
<?= e($row['branch_name']) ?>

</p>

<?php } ?>

</div>

</div>

<!-- =========================================
TITLE
========================================= -->

<div class="title">

IPD INVOICE

</div>

<!-- =========================================
PATIENT INFORMATION
========================================= -->

<table class="info">

<tr>

<td class="label">
Invoice No.
</td>

<td>
<?= e($row['bill_no']) ?>
</td>

<td class="label">
Invoice Date
</td>

<td>

<?= !empty($row['bill_date'])
    ? date(
        'd-m-Y',
        strtotime($row['bill_date'])
    )
    : '-' ?>

</td>

</tr>

<tr>

<td class="label">
UHID
</td>

<td>
<?= e($row['uhid']) ?>
</td>

<td class="label">
IPD No.
</td>

<td>
<?= e($ipd_no) ?>
</td>

</tr>

<tr>

<td class="label">
Patient
</td>

<td>
<?= e($patient_name) ?>
</td>

<td class="label">
Mobile
</td>

<td>
<?= e($row['mobile']) ?>
</td>

</tr>

<tr>

<td class="label">
Doctor
</td>

<td>
<?= e($row['doctor_name']) ?>
</td>

<td class="label">
Department
</td>

<td>
<?= e($row['department_name']) ?>
</td>

</tr>

<tr>

<td class="label">
Payment Status
</td>

<td
    colspan="3"
    class="
    <?php
    if($final_payment_status==='Paid'){
        echo 'status-paid';
    }elseif(
        $final_payment_status==='Partial'
    ){
        echo 'status-partial';
    }elseif(
        $final_payment_status==='Advance Received'
    ){
        echo 'status-advance';
    }else{
        echo 'status-pending';
    }
    ?>
    "
>

<?= e($final_payment_status) ?>

</td>

</tr>

</table>

<!-- =========================================
IPD BILL ITEMS
========================================= -->

<table class="item-table">

<thead>

<tr>

<th style="width:6%;">
S.No.
</th>

<th>
Particulars
</th>

<th style="width:10%;">
Qty
</th>

<th style="width:13%;">
Rate
</th>

<th style="width:13%;">
Amount
</th>

<th style="width:13%;">
Discount
</th>

<th style="width:14%;">
Net Amount
</th>

</tr>

</thead>

<tbody>

<?php if(empty($items)){ ?>

<tr>

<td
    colspan="7"
    class="text-center"
>

No bill items found.

</td>

</tr>

<?php }else{ ?>

<?php foreach(
    $items
    as $index=>$item
){ ?>

<tr>

<td class="text-center">
<?= $index+1 ?>
</td>

<td>

<strong>
<?= e($item['item_name']) ?>
</strong>

<?php if(
    !empty($item['description'])
){ ?>

<br>

<small>
<?= e($item['description']) ?>
</small>

<?php } ?>

<?php if(
    !empty($item['service_date'])
){ ?>

<br>

<small>

Service Date:

<?= date(
    'd-m-Y',
    strtotime(
        $item['service_date']
    )
) ?>

</small>

<?php } ?>

</td>

<td class="text-center">

<?= number_format(
    (float)$item['quantity'],
    2
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    (float)$item['rate'],
    2
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    (float)$item['amount'],
    2
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    (float)$item['discount_amount'],
    2
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    (float)$item['net_amount'],
    2
) ?>

</td>

</tr>

<?php } ?>

<?php } ?>

</tbody>

</table>

<!-- =========================================
PHARMACY BILL
========================================= -->

<h3
    style="
        margin-top:25px;
        margin-bottom:8px;
        font-size:15px;
    "
>

Pharmacy Bill

</h3>

<table class="pharmacy-table">

<thead>

<tr>

<th>
S.No.
</th>

<th>
Sale No.
</th>

<th>
Invoice No.
</th>

<th>
Date
</th>

<th>
Bill Amount
</th>

<th>
Received
</th>

<th>
Balance
</th>

<th>
Status
</th>

</tr>

</thead>

<tbody>

<?php if(
    empty($pharmacy_sales)
){ ?>

<tr>

<td
    colspan="8"
    class="text-center"
>

No pharmacy bills found.

</td>

</tr>

<?php }else{ ?>

<?php foreach(
    $pharmacy_sales
    as $index=>$pharmacy
){ ?>

<?php

$pharmacy_amount=
    (float)$pharmacy['grand_total'];

$pharmacy_received_amount=
    (float)$pharmacy['received_amount'];

if(
    $pharmacy['status']==='Cancelled'
){

    $pharmacy_balance=0;

    $pharmacy_status=
        'Cancelled';

}else{

    $pharmacy_balance=
        max(
            $pharmacy_amount-
            $pharmacy_received_amount,
            0
        );

    if(
        $pharmacy_balance<=0
    ){

        $pharmacy_status='Paid';

    }elseif(
        $pharmacy_received_amount>0
    ){

        $pharmacy_status='Partial';

    }else{

        $pharmacy_status='Pending';

    }

}

?>

<tr
    class="
    <?php
    if(
        $pharmacy['status']==='Cancelled'
    ){
        echo 'pharmacy-cancelled';
    }
    ?>
    "
>

<td class="text-center">

<?= $index+1 ?>

</td>

<td>

<strong>

<?= e(
    $pharmacy['sale_no']
) ?>

</strong>

</td>

<td>

<?= e(
    $pharmacy['invoice_no']
    ?: '-'
) ?>

</td>

<td>

<?= !empty(
    $pharmacy['sale_date']
)

? date(
    'd-m-Y h:i A',
    strtotime(
        $pharmacy['sale_date']
    )
)

: '-' ?>

</td>

<td class="text-right">

₹ <?= number_format(
    $pharmacy_amount,
    2
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    $pharmacy_received_amount,
    2
) ?>

</td>

<td class="text-right">

<?php if(
    $pharmacy['status']==='Cancelled'
){ ?>

-

<?php }else{ ?>

₹ <?= number_format(
    $pharmacy_balance,
    2
) ?>

<?php } ?>

</td>

<td>

<strong>

<?= e(
    $pharmacy_status
) ?>

</strong>

<?php if(
    $pharmacy['status']==='Cancelled'
){ ?>

<br>

<small>

Refund:

₹ <?= number_format(
    (float)$pharmacy['return_amount'],
    2
) ?>

<?php if(
    !empty(
        $pharmacy['return_payment_mode']
    )
){ ?>

<br>

Mode:

<?= e(
    $pharmacy['return_payment_mode']
) ?>

<?php } ?>

</small>

<?php } ?>

</td>

</tr>

<?php } ?>

<!-- =========================================
PHARMACY TOTAL
========================================= -->

<tr class="pharmacy-total-row">

<td colspan="4">

Active Pharmacy Total

</td>

<td class="text-right">

₹ <?= number_format(
    $pharmacy_total,
    2
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    $pharmacy_received,
    2
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    $pharmacy_balance,
    2
) ?>

</td>

<td></td>

</tr>

<?php } ?>

</tbody>

</table>

<!-- =========================================
CONSOLIDATED SUMMARY
========================================= -->

<table class="summary">

<tr>

<td>
IPD Gross Amount
</td>

<td class="text-right">

₹ <?= number_format(
    $total_amount,
    2
) ?>

</td>

</tr>

<tr>

<td>
IPD Discount
</td>

<td class="text-right">

₹ <?= number_format(
    $discount_amount,
    2
) ?>

</td>

</tr>

<tr>

<td>
IPD Net Amount
</td>

<td class="text-right">

₹ <?= number_format(
    $net_amount,
    2
) ?>

</td>

</tr>

<tr>

<td>
Pharmacy Bills
</td>

<td class="text-right">

₹ <?= number_format(
    $pharmacy_total,
    2
) ?>

</td>

</tr>

<tr class="grand-total">

<td>
Total Payable Amount
</td>

<td class="text-right">

₹ <?= number_format(
    $final_net_amount,
    2
) ?>

</td>

</tr>

<tr>

<td>
IPD Paid Amount
</td>

<td class="text-right">

₹ <?= number_format(
    $paid_amount,
    2
) ?>

</td>

</tr>

<tr>

<td>
Pharmacy Received
</td>

<td class="text-right">

₹ <?= number_format(
    $pharmacy_received,
    2
) ?>

</td>

</tr>

<tr>

<td>
Total Received
</td>

<td class="text-right">

₹ <?= number_format(
    $final_paid_amount,
    2
) ?>

</td>

</tr>

<tr class="balance-row">

<td>
Balance Amount
</td>

<td class="text-right">

₹ <?= number_format(
    $final_balance_amount,
    2
) ?>

</td>

</tr>

</table>

<!-- =========================================
IPD PAYMENT HISTORY
========================================= -->

<?php if(
    !empty($payments)
){ ?>

<h3
    style="
        margin-top:25px;
        font-size:15px;
    "
>

IPD Payment History

</h3>

<table class="payment-table">

<tr>

<th>
Payment No.
</th>

<th>
Date
</th>

<th>
Mode
</th>

<th>
Transaction No.
</th>

<th>
Amount
</th>

</tr>

<?php foreach(
    $payments
    as $payment
){ ?>

<tr>

<td>

<?= e(
    $payment['payment_no']
) ?>

</td>

<td>

<?= !empty(
    $payment['payment_date']
)

? date(
    'd-m-Y',
    strtotime(
        $payment['payment_date']
    )
)

: '-' ?>

</td>

<td>

<?= e(
    $payment['payment_mode']
) ?>

</td>

<td>

<?= e(
    $payment['transaction_no']
) ?>

</td>

<td class="text-right">

₹ <?= number_format(
    (float)$payment['payment_amount'],
    2
) ?>

</td>

</tr>

<?php } ?>

</table>

<?php } ?>

<!-- =========================================
FOOTER
========================================= -->

<div class="footer">

<div class="signature">

<hr>

Prepared By

</div>

<div class="signature">

<hr>

Authorized Signatory

</div>

</div>

<div class="note">

This is a computer-generated IPD invoice.

</div>

<div class="actions">

<button
    onclick="window.print()"
>

Print Invoice

</button>

<button
    onclick="window.history.back()"
>

Back

</button>

</div>

</div>

</body>

</html>