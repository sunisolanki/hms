<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$payment_id=(int)($_GET['payment_id'] ?? 0);

if($payment_id<=0){
    die('Invalid receipt ID.');
}

$stmt=$conn->prepare("
    SELECT
        p.id AS payment_id,
        p.payment_no,
        p.payment_date,
        p.payment_amount,
        p.payment_type,
        p.payment_mode,
        p.transaction_no,
        p.payment_details,
        p.remarks,

        ib.id AS bill_id,
        ib.bill_no,

        ibi.id AS bill_item_id,
        ibi.item_name AS service_name,
        ibi.description,
        ibi.quantity,
        ibi.rate,
        ibi.amount,
        ibi.discount_amount,
        ibi.net_amount AS service_net_amount,
        ibi.service_date,

        ia.ipd_no,

        pm.id AS patient_id,
        CONCAT_WS(' ',pm.first_name,pm.last_name) AS patient_name,
        pm.mobile,

        hm.hospital_name,
        hm.address AS hospital_address,
        hm.mobile AS hospital_mobile,
        hm.email AS hospital_email,

        bm.branch_name

    FROM ipd_payments p

    LEFT JOIN ipd_bills ib
        ON ib.id=p.ipd_bill_id

    LEFT JOIN ipd_bill_items ibi
        ON ibi.id=p.ipd_bill_item_id

    LEFT JOIN ipd_admissions ia
        ON ia.id=p.ipd_admission_id

    LEFT JOIN patient_master pm
        ON pm.id=p.patient_id

    LEFT JOIN hospital_master hm
        ON hm.id=ia.hospital_id

    LEFT JOIN branch_master bm
        ON bm.id=ia.branch_id

    WHERE p.id=?
    AND p.payment_type='Service'

    LIMIT 1
");

$stmt->bind_param("i",$payment_id);
$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows===0){
    die('Service receipt not found.');
}

$row=$result->fetch_assoc();

$stmt->close();
/* ===============================
GET ALL SERVICES LINKED TO PAYMENT
================================ */
$itemStmt=$conn->prepare("
    SELECT
        ibi.id AS bill_item_id,
        ibi.item_name AS service_name,
        ibi.description,
        ibi.quantity,
        ibi.rate,
        ibi.amount,
        ibi.discount_amount,
        ibi.net_amount AS service_net_amount,
        ibi.service_date
    FROM ipd_payment_items pmi
    INNER JOIN ipd_bill_items ibi
        ON ibi.id=pmi.ipd_bill_item_id
    WHERE pmi.payment_id=?
    ORDER BY pmi.id ASC
");

if(!$itemStmt){
    die('Unable to load receipt services: '.$conn->error);
}

$itemStmt->bind_param("i",$payment_id);
$itemStmt->execute();

$itemsResult=$itemStmt->get_result();

$receiptItems=[];

while($item=$itemsResult->fetch_assoc()){
    $receiptItems[]=$item;
}

$itemStmt->close();
/* SERVICE CALCULATIONS */

$service_amount=0;

foreach($receiptItems as $item){
    $service_amount+=(float)($item['service_net_amount'] ?? 0);
}
$received_amount=(float)($row['payment_amount'] ?? 0);

$service_balance=max(
    0,
    $service_amount-$received_amount
);

function money($amount){
    return number_format((float)$amount,2);
}

function safe($value){
    return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>IPD Service Receipt</title>

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    padding:20px;
    background:#f3f4f6;
    font-family:Arial,sans-serif;
    color:#222;
}

.receipt{
    width:850px;
    max-width:100%;
    margin:auto;
    background:#fff;
    border:1px solid #ddd;
}

.header{
    padding:20px;
    border-bottom:2px solid #1976d2;
    text-align:center;
}

.hospital-name{
    font-size:26px;
    font-weight:bold;
    color:#1976d2;
}

.hospital-address{
    margin-top:6px;
    font-size:13px;
    color:#555;
}

.receipt-title{
    background:#1976d2;
    color:#fff;
    text-align:center;
    padding:12px;
    font-size:20px;
    font-weight:bold;
}

.info-section{
    padding:20px;
}

.info-table{
    width:100%;
    border-collapse:collapse;
}

.info-table td{
    padding:8px;
    border-bottom:1px solid #eee;
    font-size:14px;
}

.label{
    width:180px;
    font-weight:bold;
    color:#555;
}

.service-box{
    margin-top:20px;
    border:1px solid #ddd;
}

.service-box table{
    width:100%;
    border-collapse:collapse;
}

.service-box th{
    background:#f1f5f9;
    padding:10px;
    text-align:left;
    border-bottom:1px solid #ddd;
}

.service-box td{
    padding:10px;
    border-bottom:1px solid #eee;
}

.amount-section{
    margin:20px;
    margin-left:auto;
    width:350px;
}

.amount-row{
    display:flex;
    justify-content:space-between;
    padding:10px;
    border-bottom:1px solid #eee;
}

.amount-row.total{
    background:#f8fbff;
    font-size:17px;
    font-weight:bold;
}

.amount-row.received{
    color:#15803d;
    font-weight:bold;
}

.amount-row.balance{
    color:#dc2626;
    font-weight:bold;
}

.footer{
    padding:20px;
    border-top:1px solid #ddd;
    display:flex;
    justify-content:space-between;
    font-size:13px;
}

.print-btn{
    margin:20px auto;
    display:block;
    padding:10px 25px;
    border:none;
    background:#1976d2;
    color:#fff;
    border-radius:5px;
    cursor:pointer;
}

@media print{
    body{
        padding:0;
        background:#fff;
    }

    .receipt{
        width:100%;
        border:none;
    }

    .print-btn{
        display:none;
    }
}
</style>
</head>

<body>

<div class="receipt">

    <div class="header">

        <div class="hospital-name">
            <?= safe($row['hospital_name']) ?>
        </div>

        <div class="hospital-address">
            <?= safe($row['hospital_address']) ?>
            <?php if(!empty($row['hospital_mobile'])){ ?>
                | Mobile: <?= safe($row['hospital_mobile']) ?>
            <?php } ?>
        </div>

    </div>

    <div class="receipt-title">
        IPD SERVICE RECEIPT
    </div>

    <div class="info-section">

        <table class="info-table">

            <tr>
                <td class="label">Receipt No.</td>
                <td><?= safe($row['payment_no']) ?></td>

                <td class="label">Receipt Date</td>
                <td><?= safe(date('d-m-Y',strtotime($row['payment_date']))) ?></td>
            </tr>

            <tr>
                <td class="label">IPD No.</td>
                <td><?= safe($row['ipd_no']) ?></td>

                <td class="label">Bill No.</td>
                <td><?= safe($row['bill_no']) ?></td>
            </tr>

            <tr>
                <td class="label">Patient Name</td>
                <td><?= safe($row['patient_name']) ?></td>

                <td class="label">Mobile</td>
                <td><?= safe($row['mobile']) ?></td>
            </tr>

            <tr>
                <td class="label">Branch</td>
                <td><?= safe($row['branch_name']) ?></td>

                <td class="label">Payment Mode</td>
                <td><?= safe($row['payment_mode']) ?></td>
            </tr>

        </table>

        <div class="service-box">

            <table>

                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Qty</th>
                        <th>Rate</th>
                        <th>Amount</th>
                        <th>Discount</th>
                        <th>Net Amount</th>
                    </tr>
                </thead>

                <tbody>

<?php if(!empty($receiptItems)){ ?>

    <?php foreach($receiptItems as $item){ ?>

    <tr>
        <td><?= safe($item['service_name']) ?></td>
        <td><?= safe($item['quantity']) ?></td>
        <td>₹<?= money($item['rate']) ?></td>
        <td>₹<?= money($item['amount']) ?></td>
        <td>₹<?= money($item['discount_amount']) ?></td>
        <td>₹<?= money($item['service_net_amount']) ?></td>
    </tr>

    <?php } ?>

<?php }else{ ?>

    <tr>
        <td colspan="6" style="text-align:center;">
            No services found.
        </td>
    </tr>

<?php } ?>

</tbody>

            </table>

        </div>

    </div>

    <div class="amount-section">

        <div class="amount-row total">
            <span>Service Charges</span>
            <span>₹<?= money($service_amount) ?></span>
        </div>

        <div class="amount-row received">
            <span>Received Amount</span>
            <span>₹<?= money($received_amount) ?></span>
        </div>

        <div class="amount-row balance">
            <span>Service Balance</span>
            <span>₹<?= money($service_balance) ?></span>
        </div>

    </div>

    <?php if(!empty($row['transaction_no'])){ ?>
    <div class="info-section">
        <strong>Transaction No.:</strong>
        <?= safe($row['transaction_no']) ?>
    </div>
    <?php } ?>

    <?php if(!empty($row['remarks'])){ ?>
    <div class="info-section">
        <strong>Remarks:</strong>
        <?= safe($row['remarks']) ?>
    </div>
    <?php } ?>

    <div class="footer">

        <div>
            This is a computer generated receipt.
        </div>

        <div>
            Authorized Signature
        </div>

    </div>

</div>

<button type="button" class="print-btn" onclick="window.print()">
    Print Receipt
</button>

</body>
</html>