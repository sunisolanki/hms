<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    die("Session expired.");
}
$user_id=(int)$_SESSION['user_id'];
$ipd_admission_id=(int)($_GET['ipd_admission_id'] ?? 0);
$mode=trim($_GET['mode'] ?? 'advance');
$isBalanceMode=($mode==='balance');
if($ipd_admission_id<=0){
    die("Invalid IPD Admission.");
}
/*=========================================
GET IPD DETAILS
=========================================*/
$stmt=$conn->prepare("
SELECT
    ia.*,
    p.uhid,
    p.title,
    p.first_name,
    p.last_name,
    p.mobile,
    u.full_name AS doctor_name,
    d.department_name
FROM ipd_admissions ia
LEFT JOIN patient_master p
    ON p.id=ia.patient_id
LEFT JOIN users u
    ON u.id=ia.doctor_id
LEFT JOIN department_master d
    ON d.id=ia.department_id
WHERE ia.id=?
LIMIT 1
");

$stmt->bind_param(
    "i",
    $ipd_admission_id
);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows===0){
    die("IPD Admission not found.");
}

$ipd=$result->fetch_assoc();

$stmt->close();

/*=========================================
CURRENT ADVANCE
=========================================*/

$stmt=$conn->prepare("
SELECT
    COALESCE(SUM(payment_amount),0) AS total_advance
FROM ipd_payments
WHERE ipd_admission_id=?
AND ipd_bill_id IS NULL
");

$stmt->bind_param(
    "i",
    $ipd_admission_id
);

$stmt->execute();

$advanceResult=$stmt->get_result()->fetch_assoc();

$totalAdvance=(float)($advanceResult['total_advance'] ?? 0);
/*=========================================
CONSOLIDATED IPD BILLING
=========================================*/

/* IPD BILLS */

$stmt=$conn->prepare("
SELECT
COALESCE(SUM(net_amount),0) AS total_net_amount
FROM ipd_bills
WHERE ipd_admission_id=?
AND payment_status<>'Cancelled'
");

$stmt->bind_param(
"i",
$ipd_admission_id
);

$stmt->execute();

$billResult=$stmt->get_result()->fetch_assoc();

$stmt->close();

$total_net_amount=(float)($billResult['total_net_amount'] ?? 0);


/* DOCTOR VISIT CHARGES */

$stmt=$conn->prepare("
SELECT
COALESCE(SUM(charges),0) AS total_doctor_visit
FROM ipd_doctor_visits
WHERE ipd_admission_id=?
");

$stmt->bind_param(
"i",
$ipd_admission_id
);

$stmt->execute();

$doctorResult=$stmt->get_result()->fetch_assoc();

$stmt->close();

$total_doctor_visit_charges=
    (float)($doctorResult['total_doctor_visit'] ?? 0);


/* ROOM CHARGES */

$total_room_charges=0;

$roomStmt=$conn->prepare("
SELECT
ira.room_id,
ira.allocated_date,
ira.released_date,
rm.room_rent
FROM ipd_room_allocation ira
INNER JOIN room_master rm
ON rm.id=ira.room_id
WHERE ira.admission_id=?
ORDER BY ira.allocated_date ASC
");

$roomStmt->bind_param(
"i",
$ipd_admission_id
);

$roomStmt->execute();

$roomResult=$roomStmt->get_result();

while($room=$roomResult->fetch_assoc()){

    $startDate=new DateTime(
        $room['allocated_date']
    );

    $endDate=!empty($room['released_date'])
        ?new DateTime($room['released_date'])
        :new DateTime();

    $startBillingDate=clone $startDate;
    $startBillingDate->setTime(12,0,0);

    $endBillingDate=clone $endDate;
    $endBillingDate->setTime(12,0,0);

    if($startDate<$startBillingDate){
        $startBillingDate->modify('-1 day');
    }

    if($endDate<$endBillingDate){
        $endBillingDate->modify('-1 day');
    }

    $days=
        $startBillingDate
        ->diff($endBillingDate)
        ->days+1;

    if($days<1){
        $days=1;
    }

    $roomRent=(float)$room['room_rent'];

    $total_room_charges+=
        $roomRent*$days;

    /* MAPPED ROOM CHARGES */

    $chargeStmt=$conn->prepare("
        SELECT
            amount,
            frequency
        FROM room_charge_mapping
        WHERE room_id=?
        ORDER BY id ASC
    ");

    $chargeStmt->bind_param(
        "i",
        $room['room_id']
    );

    $chargeStmt->execute();

    $chargeResult=$chargeStmt->get_result();

    while($charge=$chargeResult->fetch_assoc()){

        $rate=(float)$charge['amount'];

        if($charge['frequency']==='Day Wise'){

            $total_room_charges+=
                $rate*$days;

        }else{

            $total_room_charges+=
                $rate;

        }

    }

    $chargeStmt->close();
}

$roomStmt->close();


/* PHARMACY */

$total_pharmacy_amount=0;
$total_pharmacy_received=0;

$pharmacyStmt=$conn->prepare("
SELECT
ps.id,
ps.grand_total,
ps.status,
COALESCE(
    (
        SELECT SUM(pr.received_amount)
        FROM pharmacy_receipts pr
        WHERE pr.sale_id=ps.id
    ),
    0
) AS received_amount
FROM pharmacy_sales ps
WHERE ps.appointment_id=?
AND ps.patient_type='IPD'
ORDER BY ps.id ASC
");

$pharmacyStmt->bind_param(
"i",
$ipd['appointment_id']
);

$pharmacyStmt->execute();

$pharmacyResult=$pharmacyStmt->get_result();

while($pharmacy=$pharmacyResult->fetch_assoc()){

    if($pharmacy['status']==='Cancelled'){
        continue;
    }

    $pharmacyAmount=
        (float)$pharmacy['grand_total'];

    $pharmacyReceived=
        (float)$pharmacy['received_amount'];

    $total_pharmacy_amount+=
        $pharmacyAmount;

    $total_pharmacy_received+=
        $pharmacyReceived;
}

$pharmacyStmt->close();


/* ALL IPD PAYMENTS */

$stmt=$conn->prepare("
SELECT
COALESCE(SUM(payment_amount),0) AS total_received
FROM ipd_payments
WHERE ipd_admission_id=?
");

$stmt->bind_param(
"i",
$ipd_admission_id
);

$stmt->execute();

$paymentResult=$stmt->get_result()->fetch_assoc();

$stmt->close();

$total_ipd_received=
    (float)($paymentResult['total_received'] ?? 0);


/*=========================================
FINAL CONSOLIDATED BALANCE
=========================================*/

$grand_patient_total=
    $total_net_amount+
    $total_doctor_visit_charges+
    $total_room_charges+
    $total_pharmacy_amount;

$total_received=
    $total_ipd_received+
    $total_pharmacy_received;

$pendingAmount=
    $grand_patient_total-
    $total_received;

if($pendingAmount<0){
    $pendingAmount=0;
}

$patientName=trim(
    ($ipd['title'] ?? '').' '.
    ($ipd['first_name'] ?? '').' '.
    ($ipd['last_name'] ?? '')
);

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<title>Receive IPD Advance</title>

<link
rel="icon"
href="/assets/img/hms_logo.png">

<style>

*{
box-sizing:border-box;
}

body{
margin:0;
padding:20px;
font-family:Arial,Helvetica,sans-serif;
background:#eef2f7;
color:#1e293b;
}

.card{
background:#fff;
border-radius:12px;
box-shadow:0 8px 25px rgba(0,0,0,.08);
overflow:hidden;
}

.header{
background:#1976d2;
color:#fff;
padding:15px 20px;
font-size:18px;
font-weight:bold;
}

.body{
padding:20px;
}

.patient{
display:grid;
grid-template-columns:120px 1fr 120px 1fr;
gap:8px;
margin-bottom:18px;
}

.label{
font-weight:bold;
color:#64748b;
}

.value{
font-weight:600;
}

.form-group{
margin-bottom:14px;
}

label{
display:block;
font-size:12px;
font-weight:bold;
margin-bottom:5px;
}

input,
select,
textarea{
width:100%;
padding:9px 10px;
border:1px solid #d1d5db;
border-radius:6px;
font-size:13px;
}

input:focus,
select:focus,
textarea:focus{
outline:none;
border-color:#1976d2;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}

.advance-box{
background:#f0f7ff;
border:1px solid #bfdbfe;
border-radius:8px;
padding:12px;
margin-bottom:18px;
}

.advance-box strong{
color:#1565c0;
}

.paymentFields{
display:none;
}

.grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:12px;
}

.full{
grid-column:1/-1;
}

.save{
width:100%;
padding:11px;
border:none;
border-radius:7px;
background:#198754;
color:#fff;
font-weight:bold;
font-size:14px;
cursor:pointer;
margin-top:8px;
}

.save:hover{
background:#146c43;
}

</style>

</head>

<body>

<div class="card">

<div class="header">

<?= $isBalanceMode ? '💳 Pay IPD Balance' : '💰 Receive IPD Advance' ?>

</div>

<div class="body">

<div class="patient">

<div class="label">
IPD No.
</div>

<div class="value">
<?= htmlspecialchars($ipd['ipd_no'] ?? '') ?>
</div>

<div class="label">
UHID
</div>

<div class="value">
<?= htmlspecialchars($ipd['uhid'] ?? '') ?>
</div>

<div class="label">
Patient
</div>

<div class="value">
<?= htmlspecialchars($patientName) ?>
</div>

<div class="label">
Doctor
</div>

<div class="value">
<?= htmlspecialchars($ipd['doctor_name'] ?? '') ?>
</div>

</div>

<div class="advance-box">

Current Advance:

<strong>
₹ <?= number_format($totalAdvance,2) ?>
</strong>

</div>
<?php if($isBalanceMode){ ?>

<div class="advance-box">

Total Pending Amount:

<strong style="color:#dc3545;">

₹ <?= number_format($pendingAmount,2) ?>

</strong>

</div>

<?php } ?>
<form
method="post"
action="advance_save.php"
id="advanceForm">

<input
type="hidden"
name="ipd_admission_id"
value="<?= $ipd_admission_id ?>">

<input
type="hidden"
name="patient_id"
value="<?= (int)$ipd['patient_id'] ?>">

<input
type="hidden"
name="ipd_no"
value="<?= htmlspecialchars($ipd['ipd_no']) ?>">

<input
type="hidden"
name="created_by"
value="<?= $user_id ?>">

<div class="grid">

<div class="form-group full">

<label>
<?= $isBalanceMode ? 'Payment Amount' : 'Advance Amount' ?>
</label>

<input
type="number"
name="payment_amount"
id="payment_amount"
step="0.01"
min="0.01"
required
value="<?= $isBalanceMode && $pendingAmount>0 ? number_format($pendingAmount,2,'.','') : '' ?>"
max="<?= $isBalanceMode && $pendingAmount>0 ? number_format($pendingAmount,2,'.','') : '' ?>"
placeholder="<?= $isBalanceMode ? 'Enter payment amount' : 'Enter advance amount' ?>">

</div>

<div class="form-group">

<label>
Payment Date
</label>

<input
type="datetime-local"
name="payment_date"
required
value="<?= date('Y-m-d\TH:i') ?>">

</div>

<div class="form-group">

<label>
Payment Mode
</label>

<select
name="payment_mode"
id="payment_mode"
required
onchange="togglePaymentFields()">

<option value="Cash">
Cash
</option>

<option value="UPI">
UPI
</option>

<option value="Card">
Card
</option>

<option value="Cheque">
Cheque
</option>

<option value="NEFT">
NEFT
</option>


<option value="ECS">
ECS
</option>

</select>

</div>

<!-- CASH -->

<div
id="cashFields"
class="paymentFields full">

<label>
Remarks
</label>

<input
type="text"
name="cash_remarks"
placeholder="Cash remarks">

</div>

<!-- UPI -->

<div
id="upiFields"
class="paymentFields">

<label>
UPI App
</label>

<select
name="upi_app">

<option value="">
Select
</option>

<option>
PhonePe
</option>

<option>
Google Pay
</option>

<option>
Paytm
</option>

<option>
BHIM
</option>

<option>
Other
</option>

</select>

</div>

<div
id="upiTxnFields"
class="paymentFields">

<label>
Transaction ID
</label>

<input
type="text"
name="upi_txn_id">

</div>

<!-- CARD -->

<div
id="cardFields"
class="paymentFields full">

<label>
Card Type
</label>

<select
name="card_type">

<option value="">
Select Card Type
</option>

<option>
Credit Card
</option>

<option>
Debit Card
</option>

</select>

<label style="margin-top:10px;">
Bank Name
</label>

<input
type="text"
name="card_bank_name">

<label style="margin-top:10px;">
Last 4 Digits
</label>

<input
type="text"
name="card_last4"
maxlength="4"
inputmode="numeric">

<label style="margin-top:10px;">
Approval / Auth Code
</label>

<input
type="text"
name="card_auth_code">

</div>

<!-- CHEQUE -->

<div
id="chequeFields"
class="paymentFields full">

<label>
Cheque No.
</label>

<input
type="text"
name="cheque_no">

<label style="margin-top:10px;">
Bank Name
</label>

<input
type="text"
name="cheque_bank">

<label style="margin-top:10px;">
Cheque Date
</label>

<input
type="date"
name="cheque_date">

</div>

<!-- NEFT -->

<div
id="neftFields"
class="paymentFields">

<label>
Reference No.
</label>

<input
type="text"
name="neft_ref">

</div>

<div
id="neftBankFields"
class="paymentFields">

<label>
Bank Name
</label>

<input
type="text"
name="neft_bank">

</div>

<!-- CREDIT -->

<div
id="creditFields"
class="paymentFields full">

<label>
Credit Days
</label>

<input
type="number"
name="credit_days"
min="0">

<label style="margin-top:10px;">
Approval By
</label>

<input
type="text"
name="approved_by">

</div>

<!-- ECS -->

<div
id="ecsFields"
class="paymentFields full">

<label>
Bank Name
</label>

<input
type="text"
name="ecs_bank_name">

<label style="margin-top:10px;">
Reference No.
</label>

<input
type="text"
name="ecs_reference_no">

<label style="margin-top:10px;">
Transaction Date
</label>

<input
type="date"
name="ecs_transaction_date">

</div>

<div class="form-group full">

<label>
Remarks
</label>

<textarea
name="remarks"
rows="3"
placeholder="Advance payment remarks"></textarea>

</div>

</div>

<button
type="submit"
class="save">

<?= $isBalanceMode ? '💾 Receive Payment' : '💾 Receive Advance' ?>

</button>

</form>

</div>

</div>

<script>

function togglePaymentFields(){

document
.querySelectorAll('.paymentFields')
.forEach(function(el){

el.style.display='none';

});

let mode=
document.getElementById('payment_mode').value;

if(mode==='Cash'){

document
.getElementById('cashFields')
.style
.display='block';

}

if(mode==='UPI'){

document
.getElementById('upiFields')
.style
.display='block';

document
.getElementById('upiTxnFields')
.style
.display='block';

}

if(mode==='Card'){

document
.getElementById('cardFields')
.style
.display='block';

}

if(mode==='Cheque'){

document
.getElementById('chequeFields')
.style
.display='block';

}

if(mode==='NEFT'){

document
.getElementById('neftFields')
.style
.display='block';

document
.getElementById('neftBankFields')
.style
.display='block';

}

if(mode==='Credit'){

document
.getElementById('creditFields')
.style
.display='block';

}

if(mode==='ECS'){

document
.getElementById('ecsFields')
.style
.display='block';

}

}

togglePaymentFields();

</script>

</body>
</html>