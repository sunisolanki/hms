<!--public_html/modules/billing/ipdbilling/ipd_invoice/generate_invoice.php -->
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
$ipd_admission_id=(int)($_GET['ipd_admission_id'] ?? 0);
if($ipd_admission_id<=0){
die("Invalid IPD Admission.");
}
function e($value){
return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}
$stmt=$conn->prepare("
SELECT
ia.id,
ia.ipd_no,
ia.admission_date,
ia.patient_id,
ia.appointment_id,
ia.doctor_id,
ia.department_id,
p.uhid,
CONCAT_WS(' ',p.title,p.first_name,p.last_name) AS patient_name,
p.mobile,
u.full_name AS doctor_name,
d.department_name
FROM ipd_admissions ia
LEFT JOIN patient_master p ON p.id=ia.patient_id
LEFT JOIN users u ON u.id=ia.doctor_id
LEFT JOIN department_master d ON d.id=ia.department_id
WHERE ia.id=?
LIMIT 1
");
$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();
$ipd=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$ipd){
die("IPD Admission not found.");
}
$stmt=$conn->prepare("
SELECT
id,
bill_no,
bill_date,
total_amount,
discount_amount,
net_amount,
paid_amount,
balance_amount,
payment_status
FROM ipd_bills
WHERE ipd_admission_id=?
AND payment_status<>'Cancelled'
ORDER BY bill_date ASC,id ASC
");
$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();
$result=$stmt->get_result();
$bills=[];
$total_amount=0;
$total_discount=0;
$total_net_amount=0;
$total_paid=0;
$total_balance=0;
while($bill=$result->fetch_assoc()){
$bills[]=$bill;
$total_amount+=(float)$bill['total_amount'];
$total_discount+=(float)$bill['discount_amount'];
$total_net_amount+=(float)$bill['net_amount'];
$total_paid+=(float)$bill['paid_amount'];
$total_balance+=(float)$bill['balance_amount'];
}
$stmt->close();
$stmt=$conn->prepare("
SELECT
p.id,
p.payment_no,
p.payment_date,
p.created_at,
p.payment_amount,
p.payment_mode,
p.transaction_no,
p.remarks,
p.ipd_bill_id,
p.payment_type,
bi.item_type,
COALESCE(
(
SELECT GROUP_CONCAT(
ibi.item_name
ORDER BY pmi.id ASC
SEPARATOR ', '
)
FROM ipd_payment_items pmi
INNER JOIN ipd_bill_items ibi
ON ibi.id=pmi.ipd_bill_item_id
WHERE pmi.payment_id=p.id
),
bi.item_name
) AS item_name,
COALESCE(
(
SELECT SUM(ibi.amount)
FROM ipd_payment_items pmi
INNER JOIN ipd_bill_items ibi
ON ibi.id=pmi.ipd_bill_item_id
WHERE pmi.payment_id=p.id
),
bi.amount
) AS amount,
COALESCE(
(
SELECT SUM(ibi.discount_amount)
FROM ipd_payment_items pmi
INNER JOIN ipd_bill_items ibi
ON ibi.id=pmi.ipd_bill_item_id
WHERE pmi.payment_id=p.id
),
bi.discount_amount
) AS discount_amount,
COALESCE(
(
SELECT SUM(ibi.net_amount)
FROM ipd_payment_items pmi
INNER JOIN ipd_bill_items ibi
ON ibi.id=pmi.ipd_bill_item_id
WHERE pmi.payment_id=p.id
),
bi.net_amount
) AS net_amount
FROM ipd_payments p
LEFT JOIN ipd_bill_items bi
ON bi.id=p.ipd_bill_item_id
WHERE p.ipd_admission_id=?
ORDER BY p.payment_date ASC,p.id ASC
");
$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();
$result=$stmt->get_result();
$advances=[];
$service_receipts=[];
$charge_receipts=[];
$total_advance=0;
$total_bill_receipts=0;
$total_received=0;
while($payment=$result->fetch_assoc()){
$payment_amount=(float)$payment['payment_amount'];
$total_received+=$payment_amount;
if($payment['payment_type']=='Advance'){
$advances[]=$payment;
$total_advance+=$payment_amount;
}
elseif($payment['payment_type']=='Service' && $payment['item_type']!='Charge'){
$service_receipts[]=$payment;
$total_bill_receipts+=$payment_amount;
}
}
$stmt->close();
$stmt=$conn->prepare("
SELECT
p.id,
p.payment_no,
p.created_at,
p.payment_amount,
p.payment_mode,
p.transaction_no,
p.remarks,
bi.item_name,
bi.amount,
bi.discount_amount,
bi.net_amount
FROM ipd_payments p
INNER JOIN ipd_payment_items pi
ON pi.payment_id=p.id
INNER JOIN ipd_bill_items bi
ON bi.id=pi.ipd_bill_item_id
WHERE p.ipd_admission_id=?
AND bi.item_type='Charge'
ORDER BY p.id ASC
");
$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();
$charge_result=$stmt->get_result();
$charge_receipts=[];
$added_charge_payments=[];
while($charge=$charge_result->fetch_assoc()){
$charge_receipts[]=$charge;
if(!in_array($charge['id'],$added_charge_payments)){
$total_bill_receipts+=(float)$charge['payment_amount'];
$added_charge_payments[]=$charge['id'];
}
}
$stmt->close();
/* ===============================
DOCTOR VISIT CHARGES
================================ */
$stmt=$conn->prepare("
SELECT
dv.id,
dv.doctor_id,
dv.visit_date,
dv.visit_time,
dv.charge_type,
dv.charges,
dv.comment,
u.full_name AS doctor_name
FROM ipd_doctor_visits dv
LEFT JOIN doctor_master dm
ON dm.id=dv.doctor_id

LEFT JOIN users u
ON u.id=dm.user_id
WHERE dv.ipd_admission_id=?
ORDER BY dv.visit_date ASC,dv.visit_time ASC,dv.id ASC
");
$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();
$doctorVisitResult=$stmt->get_result();
$doctor_visits=[];
$total_doctor_visit_charges=0;

while($visit=$doctorVisitResult->fetch_assoc()){
$doctor_visits[]=$visit;
$total_doctor_visit_charges+=(float)$visit['charges'];
}
$stmt->close();
/* ===============================
PHARMACY BILLS
================================ */
$pharmacy_sales=[];
$total_pharmacy_amount=0;
$total_pharmacy_received=0;
$total_pharmacy_balance=0;
$pharmacyStmt=$conn->prepare("
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
                SELECT SUM(pr.received_amount)
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
    ORDER BY ps.sale_date ASC,ps.id ASC
");
if(!$pharmacyStmt){
    die(
        'Unable to prepare pharmacy bill query.'
    );
}
$pharmacyStmt->bind_param(
    "i",
    $ipd['appointment_id']
);
$pharmacyStmt->execute();
$pharmacyResult=$pharmacyStmt->get_result();
while($pharmacy=$pharmacyResult->fetch_assoc()){
    $pharmacyGrandTotal=(float)$pharmacy['grand_total'];
    $pharmacyReceived=(float)$pharmacy['received_amount'];
    if($pharmacy['status']==='Cancelled'){
        $pharmacyBalance=0;
    }else{
        $pharmacyBalance=
            max(
                $pharmacyGrandTotal-
                $pharmacyReceived,
                0
            );
        $total_pharmacy_amount+=
            $pharmacyGrandTotal;
        $total_pharmacy_received+=
            $pharmacyReceived;
        $total_pharmacy_balance+=
            $pharmacyBalance;
    }
    $pharmacy['received_amount']=
        $pharmacyReceived;
    $pharmacy['balance_amount']=
        $pharmacyBalance;
    $pharmacy_sales[]=$pharmacy;
}
$pharmacyStmt->close();
/* ===============================
OPD CONSULTATION FEE / PAYMENT
================================ */

$opd_fee=0;
$opd_paid=0;
$opd_payment_mode='-';
$opd_payment_no='';
$opd_date='';
$opd_time='';

$appointmentId=(int)$ipd['appointment_id'];

if($appointmentId>0){

    $opdStmt=$conn->prepare("
        SELECT
            id,
            appointment_code,
            opd_no,
            appointment_date,
            appointment_time,
            consultation_fee,
            net_payable,
            paid_amount,
            payment_mode
        FROM appointments
        WHERE id=?
        LIMIT 1
    ");

    if($opdStmt){

        $opdStmt->bind_param("i",$appointmentId);
        $opdStmt->execute();

        $opd=$opdStmt->get_result()->fetch_assoc();

        if($opd){

            $opd_fee=(float)$opd['net_payable'];

            if($opd_fee<=0){
                $opd_fee=(float)$opd['consultation_fee'];
            }

            $opd_paid=(float)$opd['paid_amount'];

            $opd_payment_mode=
                !empty($opd['payment_mode'])
                ?$opd['payment_mode']
                :'-';

            $opd_payment_no=
                !empty($opd['opd_no'])
                ?$opd['opd_no']
                :(
                    !empty($opd['appointment_code'])
                    ?$opd['appointment_code']
                    :'OPD-'.$appointmentId
                );

            $opd_date=$opd['appointment_date'];
            $opd_time=$opd['appointment_time'];
        }

        $opdStmt->close();
    }
}
$grand_patient_total=
    $total_net_amount+
    $total_doctor_visit_charges+
    $opd_fee;
$adjusted_pending=$grand_patient_total-$total_received;
if($adjusted_pending<0){
$adjusted_pending=0;
}
$excess_advance=$total_received-$grand_patient_total;
if($excess_advance<0){
$excess_advance=0;
}
/* ===============================
ROOM CHARGES
================================ */
$room_stays=[];
$total_room_charges=0;
$roomStmt=$conn->prepare("
    SELECT
        ira.id AS allocation_id,
        ira.room_id,
        ira.allocated_date,
        ira.released_date,
        ira.status,
        rm.room_number,
        rm.room_name,
        rm.room_rent
    FROM ipd_room_allocation ira
    INNER JOIN room_master rm
        ON rm.id=ira.room_id
    WHERE ira.admission_id=?
    ORDER BY ira.allocated_date ASC
");
$roomStmt->bind_param("i",$ipd_admission_id);
$roomStmt->execute();
$roomResult=$roomStmt->get_result();
while($room=$roomResult->fetch_assoc()){
  $startDate=new DateTime($room['allocated_date']);
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
$days=$startBillingDate->diff($endBillingDate)->days+1;
if($days<1){
    $days=1;
}
    $roomRent=(float)$room['room_rent'];
    $roomRentTotal=$roomRent*$days;
    $roomTotal=$roomRentTotal;
    $charges=[];
    $charges[]=[
        'charge_name'=>'Room Rent',
        'frequency'=>'Daily',
        'rate'=>$roomRent,
        'calculation'=>'₹'.number_format($roomRent,2).' × '.$days,
        'amount'=>$roomRentTotal
    ];
    $chargeStmt=$conn->prepare("
        SELECT
            cm.charges_name,
            rcm.amount,
            rcm.frequency
        FROM room_charge_mapping rcm
        INNER JOIN charges_master cm
            ON cm.id=rcm.charge_id
        WHERE rcm.room_id=?
        ORDER BY rcm.id ASC
    ");
    $chargeStmt->bind_param("i",$room['room_id']);
    $chargeStmt->execute();
    $chargeResult=$chargeStmt->get_result();
    while($charge=$chargeResult->fetch_assoc()){
        $rate=(float)$charge['amount'];
        if($charge['frequency']=='Day Wise'){
            $chargeAmount=$rate*$days;
            $calculation='₹'.number_format($rate,2).' × '.$days;
        }else{
            $chargeAmount=$rate;
            $calculation='₹'.number_format($rate,2).' × 1';
        }
        $roomTotal+=$chargeAmount;
        $charges[]=[
            'charge_name'=>$charge['charges_name'],
            'frequency'=>$charge['frequency'],
            'rate'=>$rate,
            'calculation'=>$calculation,
            'amount'=>$chargeAmount
        ];
    }
    $chargeStmt->close();
    $room['days']=$days;
    $room['room_total']=$roomTotal;
    $room['charges']=$charges;
    $room_stays[]=$room;
  $total_room_charges+=$roomTotal;
}
$roomStmt->close();
$grand_patient_total=
    $total_net_amount+
    $total_doctor_visit_charges+
    $total_room_charges+
    $total_pharmacy_amount+
    $opd_fee;
$total_received_with_pharmacy=
    $total_received+
    $total_pharmacy_received+
    $opd_paid;
$adjusted_pending=
    $grand_patient_total-
    $total_received_with_pharmacy;

if($adjusted_pending<0){
$adjusted_pending=0;
}
$excess_advance=
    $total_received_with_pharmacy-
    $grand_patient_total;
if($excess_advance<0){
$excess_advance=0;
}
if(
    $grand_patient_total<=0 &&
    $total_received_with_pharmacy>0
){
    $financial_status='Advance Received';
    $status_class='status-advance';
}elseif(
    $grand_patient_total>0 &&
    $excess_advance>0
){
    $financial_status='Excess Advance';
    $status_class='status-advance';
}elseif(
    $grand_patient_total>0 &&
    $adjusted_pending<=0
){
    $financial_status='Paid';
    $status_class='status-paid';
}elseif(
    $grand_patient_total>0 &&
    $adjusted_pending>0
){
    $financial_status='Pending';
    $status_class='status-pending';
}else{
    $financial_status='No Bill';
    $status_class='status-nobill';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generate IPD Invoice</title>
<style>
*{
box-sizing:border-box;
}
body{
margin:0;
padding:20px;
font-family:Arial,Helvetica,sans-serif;
background:#f4f6f9;
color:#333;
}
.invoice-card{
max-width:1200px;
margin:auto;
background:#fff;
border-radius:10px;
box-shadow:0 3px 15px rgba(0,0,0,.1);
padding:20px;
}
.invoice-header{
display:flex;
justify-content:space-between;
align-items:flex-start;
border-bottom:2px solid #0b5ed7;
padding-bottom:15px;
margin-bottom:20px;
}
.invoice-header h2{
margin:0;
color:#0b5ed7;
}
.invoice-header p{
margin:5px 0 0;
font-size:13px;
color:#666;
}
.doctor-visit-total-row td:last-child{
text-align:right !important;
}
.status{
padding:7px 15px;
border-radius:20px;
font-size:12px;
font-weight:bold;
display:inline-block;
}
.status-paid{
background:#d1e7dd;
color:#0f5132;
}
.status-pending{
background:#f8d7da;
color:#842029;
}
.status-advance{
background:#fff3cd;
color:#856404;
}
.status-nobill{
background:#e2e8f0;
color:#334155;
}
.info-grid{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:12px;
margin-bottom:20px;
}
.info-box{
border:1px solid #ddd;
border-radius:6px;
padding:10px;
}
.info-label{
font-size:11px;
color:#777;
margin-bottom:4px;
}
.info-value{
font-size:14px;
font-weight:bold;
}
.section-title{
margin:25px 0 10px;
font-size:16px;
font-weight:bold;
color:#0b5ed7;
border-left:4px solid #0b5ed7;
padding-left:8px;
}
.table-wrap{
overflow-x:auto;
}
table{
width:100%;
border-collapse:collapse;
min-width:850px;
}
th{
background:#0b5ed7;
color:#fff;
padding:10px;
font-size:13px;
text-align:left;
}
td{
padding:10px;
border-bottom:1px solid #e5e5e5;
font-size:13px;
}
.text-right{
text-align:right;
}
.paid{
color:#198754;
font-weight:bold;
}
.pending{
color:#dc3545;
font-weight:bold;
}
.service-balance{
color:#f97316 !important;
font-weight:700 !important;
}
.advance{
color:#b45309;
font-weight:bold;
}
.summary{
width:500px;
margin:25px 0 0 auto;
border-collapse:collapse;
min-width:auto;
}
.summary td{
border:1px solid #ddd;
padding:10px;
}
.summary .total-row{
font-size:15px;
font-weight:bold;
background:#f5f5f5;
}
.summary .balance-row{
font-size:17px;
font-weight:bold;
background:#fff3cd;
}
.empty{
text-align:center;
padding:25px;
color:#777;
}
.actions{
margin-top:25px;
display:flex;
justify-content:center;
gap:10px;
flex-wrap:wrap;
}
.btn{
border:none;
border-radius:6px;
padding:11px 18px;
font-size:14px;
cursor:pointer;
}
.btn-primary{
background:#0b5ed7;
color:#fff;
}
.btn-warning{
background:#ffc107;
color:#222;
}
.btn-success{
background:#198754;
color:#fff;
}
.btn-secondary{
background:#6c757d;
color:#fff;
}
#billing-loader{
    position:fixed;
    inset:0;
    z-index:99999;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:
        radial-gradient(circle at center,#ffffff 0%,#f3f7fc 55%,#e7eef8 100%);
    transition:opacity .7s ease,visibility .7s ease;
}
.advance-payment-total-row td:nth-child(2){
text-align:right !important;
}
#billing-loader.hide{
    opacity:0;
    visibility:hidden;
}

.billing-engine-box{
    position:relative;
    width:700px;
    max-width:92%;
    text-align:center;
}
.engine-visual{
    position:relative;
    width:420px;
    height:420px;
    margin:0 auto 30px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.engine-ring{
    position:absolute;
    width:390px;
    height:390px;
    border:2px solid rgba(25,118,210,.28);
    border-radius:50%;
    animation:spin 8s linear infinite;
}
.engine-ring:before,
.engine-ring:after{
    content:"";
    position:absolute;
    width:16px;
    height:16px;
    border-radius:50%;
    background:#1976d2;
    box-shadow:0 0 20px #1976d2;
}
.engine-ring:before{
    top:-8px;
    left:50%;
    transform:translateX(-50%);
}
.engine-ring:after{
    bottom:-8px;
    left:50%;
    transform:translateX(-50%);
}
.engine-ring.ring-2{
    width:320px;
    height:320px;
    border-style:dashed;
    animation:spinReverse 6s linear infinite;
}
.engine-image{
    position:relative;
    z-index:5;
    width:600px;
    max-width:100%;
    animation:engineFloat 2.2s ease-in-out infinite;
    filter:drop-shadow(0 18px 25px rgba(25,118,210,.25));
}
.engine-glow{
    position:absolute;
    width:280px;
    height:280px;
    border-radius:50%;
    background:rgba(25,118,210,.12);
    filter:blur(35px);
    animation:pulseGlow 1.8s ease-in-out infinite;
}
.engine-title{
    color:#174f88;
    font-size:24px;
    font-weight:700;
    letter-spacing:.5px;
}
.engine-status{
    margin-top:10px;
    min-height:24px;
    color:#64748b;
    font-size:15px;
    transition:all .3s ease;
}
.progress-track{
    width:100%;
    height:8px;
    margin-top:25px;
    overflow:hidden;
    border-radius:20px;
    background:#dbe5f1;
}
.progress-bar{
    width:0%;
    height:100%;
    border-radius:20px;
    background:linear-gradient(90deg,#1976d2,#42a5f5,#1976d2);
    background-size:200% 100%;
    animation:progressShine 1.5s linear infinite;
    transition:width .5s ease;
}
.engine-percent{
    margin-top:10px;
    color:#1976d2;
    font-size:13px;
    font-weight:bold;
}
.room-header{
display:flex;
align-items:center;
gap:10px;
padding:6px 0;
white-space:nowrap;
font-size:16px;
}
.room-header strong{
color:#263b52;
}
.room-header-separator{
color:#b0b8c2;
font-weight:400;
}
.stay-arrow{
margin:0 4px;
color:#2862a7;
font-weight:700;
}
.room-days-badge{
background:#edf5ff;
border:1px solid #c5dbf3;
color:#245b98;
padding:3px 10px;
border-radius:14px;
font-size:13px;
font-weight:700;
}
.room-total-row td{
background:#e9ecef !important;
border-top:1px solid #ced4da !important;
border-bottom:1px solid #ced4da !important;
padding:4px 14px !important;
color:#343a40;
font-weight:700;
}
.room-total-row td:first-child{
border-left:1px solid #ced4da;
}
.room-total-row td:last-child{
border-right:1px solid #ced4da;
text-align:right;
font-size:16px;
}
@keyframes spin{
    from{transform:rotate(0deg);}
    to{transform:rotate(360deg);}
}
@keyframes spinReverse{
    from{transform:rotate(360deg);}
    to{transform:rotate(0deg);}
}
@keyframes engineFloat{
    0%,100%{
        transform:translateY(0) rotate(-2deg);
    }
    50%{
        transform:translateY(-12px) rotate(2deg);
    }
}
@keyframes pulseGlow{
    0%,100%{
        transform:scale(.8);
        opacity:.45;
    }
    50%{
        transform:scale(1.2);
        opacity:1;
    }
}
@keyframes progressShine{
    from{
        background-position:0 0;
    }
    to{
        background-position:200% 0;
    }
}
@media(max-width:800px){
body{
padding:10px;
}
.info-grid{
grid-template-columns:repeat(2,1fr);
}
.summary{
width:100%;
}
}
</style>
</head>
<body>
    <div id="billing-loader">
    <div class="billing-engine-box">
        <div class="engine-visual">
            <div class="engine-glow"></div>
            <div class="engine-ring"></div>
            <div class="engine-ring ring-2"></div>
            <img
                src="/assets/img/billing-engine.png"
                class="engine-image"
                alt="Billing Engine"
            >
        </div>
        <div class="engine-title">
            HMS Billing Engine
        </div>
        <div class="engine-status" id="engine-status">
            Starting billing engine...
        </div>
        <div class="progress-track">
            <div
                class="progress-bar"
                id="billing-progress"
            ></div>
        </div>
        <div
            class="engine-percent"
            id="engine-percent"
        >
            0%
        </div>
    </div>
</div>
<div class="invoice-card">
<div class="invoice-header">
<div>
<h2>IPD Invoice</h2>
<p>Consolidated billing and advance payment summary</p>
</div>
<div>
<span class="status <?= $status_class ?>">
<?= e($financial_status) ?>
</span>
</div>
</div>
<div class="info-grid">
<div class="info-box">
<div class="info-label">IPD No.</div>
<div class="info-value"><?= e($ipd['ipd_no']) ?></div>
</div>
<div class="info-box">
<div class="info-label">UHID</div>
<div class="info-value"><?= e($ipd['uhid'] ?: '-') ?></div>
</div>
<div class="info-box">
<div class="info-label">Patient</div>
<div class="info-value"><?= e($ipd['patient_name'] ?: '-') ?></div>
</div>
<div class="info-box">
<div class="info-label">Mobile</div>
<div class="info-value"><?= e($ipd['mobile'] ?: '-') ?></div>
</div>
<div class="info-box">
<div class="info-label">Doctor</div>
<div class="info-value"><?= e($ipd['doctor_name'] ?: '-') ?></div>
</div>
<div class="info-box">
<div class="info-label">Department</div>
<div class="info-value"><?= e($ipd['department_name'] ?: '-') ?></div>
</div>
<div class="info-box">
<div class="info-label">Admission Date</div>
<div class="info-value"><?= e(date('d-m-Y',strtotime($ipd['admission_date']))) ?></div>
</div>
<div class="info-box">
<div class="info-label">    </div>
<div class="info-value">    </div>
</div>
</div>
<div class="section-title">
IPD Bills
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>#</th>
<th>Bill No.</th>
<th>Bill Date</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php if(empty($bills)){ ?>
<tr>
<td colspan="9" class="empty">
No IPD bills have been generated yet.
</td>
</tr>
<?php }else{ ?>
<?php foreach($bills as $index=>$bill){ ?>
<tr>
<td><?= $index+1 ?></td>
<td><b><?= e($bill['bill_no']) ?></b></td>
<td><?= e(date('d-m-Y',strtotime($bill['bill_date']))) ?></td>
<td><?= e($bill['payment_status']) ?></td>
</tr>
<?php } ?>
<?php } ?>
</tbody>
</table>
</div>
<div class="section-title">
Advance Payments / Receipts
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>#</th>
<th>Receipt No.</th>
<th>Receipt Date</th>
<th>Payment Mode</th>
<th>Transaction No.</th>
<th>Remarks</th>
<th class="text-right">Advance Amount</th>
</tr>
</thead>
<tbody>
<?php if(empty($advances)){ ?>
<tr>
<td colspan="7" class="empty">
No advance payment has been received.
</td>
</tr>
<?php }else{ ?>
<?php
$total_advance_payments=0;
?>
<?php foreach($advances as $index=>$payment){ ?>
<?php
$total_advance_payments+=(float)$payment['payment_amount'];
?>
<tr>
<td><?= $index+1 ?></td>
<td><b><?= e($payment['payment_no']) ?></b></td>
<td><?= e(date('d-m-Y h:i A',strtotime($payment['created_at']))) ?></td>
<td><?= e($payment['payment_mode']) ?></td>
<td><?= e($payment['transaction_no'] ?: '-') ?></td>
<td><?= e($payment['remarks'] ?: '-') ?></td>
<td class="text-right advance">
₹<?= number_format((float)$payment['payment_amount'],2) ?>
</td>
</tr>
<?php } ?>
<?php } ?>
<tr class="room-total-row advance-payment-total-row">
    <td colspan="6">
        <strong>Total Advance Received</strong>
    </td>
    <td class="text-end">
        <strong>
            <?php
$total_advance_payments=0;

if(!empty($advances)){
    foreach($advances as $payment){
        $total_advance_payments+=(float)$payment['payment_amount'];
    }
}
?>
            ₹<?= number_format($total_advance_payments,2) ?></strong>
    </td>
</tr>
</tbody>
</table>
</div>
<!-- OPD PAYMENT -->
<?php if($opd_fee>0){ ?>

<div class="card" style="margin-top:20px;">

<div class="section-title">
OPD Consultation
</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>OPD No.</th>
<th>Date & Time</th>
<th>Consultation Fee</th>
<th>Paid</th>
<th>Payment Mode</th>
<th>Balance</th>
</tr>
</thead>

<tbody>

<tr>

<td>
<?= e($opd_payment_no) ?>
</td>

<td>
<?= e(
    date(
        'd-m-Y h:i A',
        strtotime($opd_date.' '.$opd_time)
    )
) ?>
</td>

<td class="text-right">
₹<?= number_format($opd_fee,2) ?>
</td>

<td class="text-right paid">
₹<?= number_format($opd_paid,2) ?>
</td>

<td>
<?= e($opd_payment_mode) ?>
</td>

<td class="text-right <?= ($opd_fee-$opd_paid)>0?'pending':'paid' ?>">
₹<?= number_format(
    max($opd_fee-$opd_paid,0),
    2
) ?>
</td>

</tr>

<tr class="room-total-row">

<td colspan="2">
<strong>OPD Total</strong>
</td>

<td class="text-right">
<strong>
₹<?= number_format($opd_fee,2) ?>
</strong>
</td>

<td class="text-right paid">
<strong>
₹<?= number_format($opd_paid,2) ?>
</strong>
</td>

<td></td>

<td class="text-right">
<strong>
₹<?= number_format(
    max($opd_fee-$opd_paid,0),
    2
) ?>
</strong>
</td>

</tr>

</tbody>

</table>

</div>

</div>

<?php } ?>
<?php

$total_service_amount=0;
$total_service_discount=0;
$total_service_receipts=0;
$total_service_balance=0;

?>
<!-- SERVICE RECEIPTS -->
<div class="card">
<div class="section-title">Service Receipts</div>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>Receipt No.</th>
<th>Receipt Date & Time</th>
<th>Service</th>
<th>Amount</th>
<th>Discount</th>
<th>Received</th>
<th>Balance</th>
<th>Payment Mode</th>
<th>Transaction No.</th>
<th>Remarks</th>
</tr>
</thead>
<tbody>
<?php if(!empty($service_receipts)){ ?>

<?php foreach($service_receipts as $payment){ ?>
<?php
$service_amount=(float)$payment['amount'];
$service_discount=(float)$payment['discount_amount'];
$service_received=(float)$payment['payment_amount'];
$service_balance=$service_amount-$service_discount-$service_received;

$total_service_amount+=$service_amount;
$total_service_discount+=$service_discount;
$total_service_receipts+=$service_received;
$total_service_balance+=$service_balance;
?>
<tr>
<td>
<?= e($payment['payment_no']) ?>
</td>

<td>
<?= e(date('d-m-Y h:i A',strtotime($payment['created_at']))) ?>
</td>

<td>
<?= e($payment['item_name'] ?: '-') ?>
</td>

<td class="text-right">
₹<?= number_format((float)$payment['amount'],2) ?>
</td>

<td class="text-right">
₹<?= number_format((float)$payment['discount_amount'],2) ?>
</td>

<td class="text-right paid">
₹<?= number_format((float)$payment['payment_amount'],2) ?>
</td>

<td class="text-right service-balance">
₹<?= number_format(
(float)$payment['amount']
-(float)$payment['discount_amount']
-(float)$payment['payment_amount'],
2
) ?>
</td>

<td>
<?= e($payment['payment_mode']) ?>
</td>

<td>
<?= e($payment['transaction_no'] ?: '-') ?>
</td>

<td>
<?= e($payment['remarks'] ?: '-') ?>
</td>
</tr>
<?php } ?>
<?php }else{ ?>
<tr>
<td colspan="10" class="text-center">No service receipts found.</td>
</tr>
<?php } ?>
<tr class="room-total-row service-receipt-total-row">
    <td colspan="3">
        <strong>Total Service Receipts</strong>
    </td>

    <td class="text-end">
        <strong>₹<?= number_format($total_service_amount,2) ?></strong>
    </td>

    <td class="text-end">
        <strong>₹<?= number_format($total_service_discount,2) ?></strong>
    </td>

    <td class="text-end">
        <strong>₹<?= number_format($total_service_receipts,2) ?></strong>
    </td>

    <td class="text-end service-balance">
        <strong>₹<?= number_format($total_service_balance,2) ?></strong>
    </td>

    <td colspan="3"></td>
</tr>
</tbody>
</table>
</div>
</div>
<?php
$total_charge_amount=0;
$total_charge_discount=0;
$total_charge_receipts=0;
$total_charge_balance=0;
?>
<!-- CHARGES RECEIPTS -->
<div class="card" style="margin-top:20px;">

<div class="section-title">Charges Receipts</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Receipt No.</th>
<th>Receipt Date & Time</th>
<th>Charge</th>
<th>Amount</th>
<th>Discount</th>
<th>Received</th>
<th>Balance</th>
<th>Payment Mode</th>
<th>Transaction No.</th>
<th>Remarks</th>
</tr>
</thead>

<tbody>
<?php if(!empty($charge_receipts)){ ?>

<?php
$shown_charge_payments=[];
?>

<?php foreach($charge_receipts as $payment){ ?>
<?php
$charge_amount=(float)$payment['amount'];
$charge_discount=(float)$payment['discount_amount'];
$total_charge_amount+=$charge_amount;
$total_charge_discount+=$charge_discount;
?>
<tr>

<td>
<?= e($payment['payment_no']) ?>
</td>

<td>
<?= e(date('d-m-Y h:i A',strtotime($payment['created_at']))) ?>
</td>

<td>
<?= e($payment['item_name'] ?: '-') ?>
</td>

<td class="text-right">
₹<?= number_format((float)$payment['amount'],2) ?>
</td>

<td class="text-right">
₹<?= number_format((float)$payment['discount_amount'],2) ?>
</td>
<td class="text-right paid">
<?php if(!in_array($payment['id'],$shown_charge_payments)){ ?>
₹<?= number_format((float)$payment['payment_amount'],2) ?>
<?php
$shown_charge_payments[]=$payment['id'];
$charge_received=(float)$payment['payment_amount'];
$total_charge_receipts+=$charge_received;
$charge_balance=$charge_amount-$charge_discount-$charge_received;
$total_charge_balance+=$charge_balance;
?>
<?php }else{ ?>
-
<?php
$charge_received=0;
$charge_balance=$charge_amount-$charge_discount;
$total_charge_balance+=$charge_balance;
?>
<?php } ?>
</td>

<td class="text-right service-balance">
₹<?= number_format($charge_balance,2) ?>
</td>

<td>
<?= e($payment['payment_mode']) ?>
</td>

<td>
<?= e($payment['transaction_no'] ?: '-') ?>
</td>

<td>
<?= e($payment['remarks'] ?: '-') ?>
</td>

</tr>
<?php } ?>
<tr class="room-total-row charge-receipt-total-row">
    <td colspan="3">
        <strong>Total Charges Receipts</strong>
    </td>

    <td class="text-end">
        <strong>₹<?= number_format($total_charge_amount,2) ?></strong>
    </td>

    <td class="text-end">
        <strong>₹<?= number_format($total_charge_discount,2) ?></strong>
    </td>

    <td class="text-end">
        <strong>₹<?= number_format((float)($total_charge_receipts ?? 0),2) ?></strong>
    </td>

    <td class="text-end service-balance">
        <strong>₹<?= number_format($total_charge_balance,2) ?></strong>
    </td>

    <td colspan="3"></td>
</tr>
<?php }else{ ?>
<tr>
<td colspan="10" class="empty">
No charge receipts found.
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
<!-- DOCTOR VISIT CHARGES -->
<div class="card" style="margin-top:20px;">

<div class="section-title">Doctor Visit Charges</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>#</th>
<th>Doctor</th>
<th>Visit Date</th>
<th>Visit Time</th>
<th>Visit Type</th>
<th>Charges</th>
<th>Comment</th>
</tr>
</thead>

<tbody>

<?php if(!empty($doctor_visits)){ ?>

<?php foreach($doctor_visits as $index=>$visit){ ?>

<tr>

<td>
<?= $index+1 ?>
</td>

<td>
<?= e($visit['doctor_name'] ?: '-') ?>
</td>

<td>
<?= e(date('d-m-Y',strtotime($visit['visit_date']))) ?>
</td>

<td>
<?= e(date('h:i A',strtotime($visit['visit_time']))) ?>
</td>

<td>
<?= e($visit['charge_type'] ?: '-') ?>
</td>

<td class="text-right paid">
₹<?= number_format((float)$visit['charges'],2) ?>
</td>
<td><?= htmlspecialchars($visit['comment'] ?? '') ?></td>
</tr>

<?php } ?>

<?php }else{ ?>

<tr>
<td colspan="7" class="empty">
No doctor visits found.
</td>
</tr>

<?php } ?>
<tr class="room-total-row doctor-visit-total-row">
    <td colspan="5">
        <strong>Total Doctor Visit Charges</strong>
    </td>
    <td class="text-right">
        <strong>₹<?= number_format($total_doctor_visit_charges,2) ?></strong>
    </td>
    <td></td>
</tr>
</tbody>

</table>

</div>
</div>
<?php if(!empty($room_stays)){ ?>

<div class="section-title">
    Room Charges
</div>

<?php foreach($room_stays as $index=>$room){ ?>

<div class="room-charge-block">

    <div class="room-header">
    <strong>
        Room <?= $index+1 ?>:
        <?= htmlspecialchars($room['room_name'].' ('.$room['room_number'].')') ?>
    </strong>

    <span class="room-header-separator">|</span>

    <span>
        <strong>Stay:</strong>
        <?= date('d M Y, h:i A',strtotime($room['allocated_date'])) ?>
        <span class="stay-arrow">→</span>
        <?= !empty($room['released_date'])
            ?date('d M Y, h:i A',strtotime($room['released_date']))
            :'Current' ?>
    </span>

    <span class="room-header-separator">|</span>

    <span class="room-days-badge">
        <?= $room['days'] ?> Day<?= $room['days']!=1?'s':'' ?>
    </span>
</div>

    <table class="room-charge-table">

        <thead>
            <tr>
                <th>Charge</th>
                <th>Type</th>
                <th>Rate</th>
                <th>Calculation</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($room['charges'] as $charge){ ?>
            <tr>
                <td><?= htmlspecialchars($charge['charge_name']) ?></td>
                <td><?= htmlspecialchars($charge['frequency']) ?></td>
                <td>₹<?= number_format($charge['rate'],2) ?></td>
                <td><?= htmlspecialchars($charge['calculation']) ?></td>
                <td>₹<?= number_format($charge['amount'],2) ?></td>
            </tr>
        <?php } ?>
            <tr class="room-total-row">
                <td colspan="4">
                    <strong>Room <?= $index+1 ?> Total</strong>
                </td>
                <td>
                    <strong>₹<?= number_format($room['room_total'],2) ?></strong>
                </td>
            </tr>
        </tbody>
    </table>
</div>
<?php } ?>
<div class="total-room-charges">
    <strong>Total Room Charges: ₹<?= number_format($total_room_charges,2) ?></strong>
</div>
<?php } ?>
<div class="section-title">
Pharmacy Bill
</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>#</th>
<th>Sale No.</th>
<th>Invoice No.</th>
<th>Sale Date</th>
<th class="text-right">Bill Amount</th>
<th class="text-right">Received</th>
<th class="text-right">Balance</th>
<th>Status</th>
</tr>
</thead>

<tbody>

<?php if(empty($pharmacy_sales)){ ?>

<tr>
<td colspan="8" class="empty">
No pharmacy bills found.
</td>
</tr>

<?php }else{ ?>

<?php foreach($pharmacy_sales as $index=>$pharmacy){ ?>

<?php

if($pharmacy['status']==='Cancelled'){

    $pharmacyStatus='Cancelled';

    $pharmacyStatusStyle=
        'background:#fff1f2;color:#b91c1c;font-weight:700;';

}else{

    $pharmacyReceived=
        (float)$pharmacy['received_amount'];

    $pharmacyGrandTotal=
        (float)$pharmacy['grand_total'];

    $pharmacyBalance=
        (float)$pharmacy['balance_amount'];

    if($pharmacyBalance<=0){

        $pharmacyStatus='Paid';

    }elseif($pharmacyReceived>0){

        $pharmacyStatus='Partial';

    }else{

        $pharmacyStatus='Pending';

    }

    $pharmacyStatusStyle='font-weight:700;';
}

?>

<tr
<?php if($pharmacy['status']==='Cancelled'){ ?>
style="background:#fff1f2;"
<?php } ?>
>

<td>
<?= $index+1 ?>
</td>

<td>
<b>
<?= e($pharmacy['sale_no']) ?>
</b>
</td>

<td>
<?= e($pharmacy['invoice_no'] ?: '-') ?>
</td>

<td>
<?= e(
    date(
        'd-m-Y h:i A',
        strtotime($pharmacy['sale_date'])
    )
) ?>
</td>

<td class="text-right">

₹<?= number_format(
    (float)$pharmacy['grand_total'],
    2
) ?>

</td>

<td class="text-right paid">

<?php if($pharmacy['status']==='Cancelled'){ ?>

₹<?= number_format(
    (float)$pharmacy['received_amount'],
    2
) ?>

<?php }else{ ?>

₹<?= number_format(
    (float)$pharmacy['received_amount'],
    2
) ?>

<?php } ?>

</td>

<td class="text-right">

<?php if($pharmacy['status']==='Cancelled'){ ?>

-

<?php }else{ ?>

₹<?= number_format(
    (float)$pharmacy['balance_amount'],
    2
) ?>

<?php } ?>

</td>

<td>

<span style="<?= $pharmacyStatusStyle ?>">

<?= e($pharmacyStatus) ?>

</span>

<?php if($pharmacy['status']==='Cancelled'){ ?>

<br>

<small style="color:#b91c1c;">

Refund:
₹<?= number_format(
    (float)$pharmacy['return_amount'],
    2
) ?>

<?php if(!empty($pharmacy['return_payment_mode'])){ ?>

(<?= e(
    $pharmacy['return_payment_mode']
) ?>)

<?php } ?>

</small>

<?php } ?>

</td>

</tr>

<?php } ?>

<tr class="room-total-row">

<td colspan="4">

<strong>
Total Active Pharmacy Bills
</strong>

</td>

<td class="text-right">

<strong>
₹<?= number_format(
    $total_pharmacy_amount,
    2
) ?>
</strong>

</td>

<td class="text-right paid">

<strong>
₹<?= number_format(
    $total_pharmacy_received,
    2
) ?>
</strong>

</td>

<td class="text-right pending">

<strong>
₹<?= number_format(
    $total_pharmacy_balance,
    2
) ?>
</strong>

</td>

<td></td>

</tr>

<?php } ?>

</tbody>

</table>

</div>
<table class="summary">

<tr class="total-row">
<td>Summary</td>
<td class="text-right">
</td>
</tr>


<tr>
<td>Service Receipts</td>
<td class="text-right">
₹<?= number_format($total_service_amount,2) ?>
</td>
</tr>

<tr>
<td>Charges Receipts</td>
<td class="text-right">
₹<?= number_format($total_charge_amount,2) ?>
</td>
</tr>

<tr>
<td>Doctor Visit Charges</td>
<td class="text-right">
₹<?= number_format($total_doctor_visit_charges,2) ?>
</td>
</tr>

<tr>
<td>Room Charges</td>
<td class="text-right">
₹<?= number_format($total_room_charges,2) ?>
</td>
</tr>

<tr>
<td>Pharmacy Bills</td>
<td class="text-right">
₹<?= number_format($total_pharmacy_amount,2) ?>
</td>
</tr>

<tr>
<td>OPD Consultation</td>
<td class="text-right">
₹<?= number_format($opd_fee,2) ?>
</td>
</tr>

<tr class="total-row">
<td>Total Payable Amount</td>
<td class="text-right">
₹<?= number_format($grand_patient_total,2) ?>
</td>
</tr>
<tr>
    <td>Service Receipts</td>
    <td class="text-right paid">
        ₹<?= number_format($total_service_receipts,2) ?>
    </td>
</tr>

<tr>
    <td>Charges Receipts</td>
    <td class="text-right paid">
        <?php
$total_charge_receipts=$total_charge_receipts ?? 0;
?>
        ₹<?= number_format((float)($total_charge_receipts ?? 0),2) ?>
    </td>
</tr>
<tr>
<td>OPD Received</td>
<td class="text-right paid">
₹<?= number_format($opd_paid,2) ?>
</td>
</tr>
<tr>
<td>Advance Received</td>
<td class="text-right advance">
₹<?= number_format($total_advance,2) ?>
</td>
</tr>
<tr class="total-row">
<td>Total Received</td>
<td class="text-right paid">
₹<?= number_format(
    $total_received_with_pharmacy,
    2
) ?>
</td>
</tr>
<tr class="balance-row">

<td>
Pending Balance
</td>

<td class="text-right <?= $adjusted_pending>0?'pending':'paid' ?>">

₹<?= number_format(
    $adjusted_pending,
    2
) ?>

</td>

</tr>

<tr>

<td>
Excess Advance
</td>

<td class="text-right advance">

₹<?= number_format(
    $excess_advance,
    2
) ?>

</td>

</tr>
</table>
<div class="actions">
<?php if($adjusted_pending>0){ ?>
<button
type="button"
class="btn btn-warning"
onclick="openPayBalance()">
Pay Balance ₹<?= number_format($adjusted_pending,2) ?>
</button>
<?php } ?>
<?php if($grand_patient_total>0){ ?>
<button
type="button"
class="btn btn-success"
onclick="printInvoice()">
Print Invoice
</button>
<?php } ?>
<button
type="button"
class="btn btn-secondary"
onclick="closePopup()">
Close
</button>
</div>
</div>
<script>
const ipdAdmissionId=<?= (int)$ipd_admission_id ?>;
function openPayBalance(){
window.location.href='/modules/billing/ipdbilling/advance.php?ipd_admission_id='+ipdAdmissionId+'&mode=balance';
}
function printInvoice(){
if(window.parent && window.parent!==window){
window.parent.openPrintInvoice(ipdAdmissionId);
}else{
window.location.href='print_invoice.php?ipd_admission_id='+ipdAdmissionId;
}
}
function closePopup(){
if(window.parent && window.parent!==window){
window.parent.closeInvoiceModal();
}else{
history.back();
}
}
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){

    const steps=[
        {
            text:'Initializing billing engine...',
            progress:10,
            delay:500
        },
        {
            text:'Collecting advance payments...',
            progress:25,
            delay:700
        },
        {
            text:'Processing service receipts...',
            progress:40,
            delay:700
        },
        {
            text:'Calculating additional charges...',
            progress:55,
            delay:700
        },
        {
            text:'Calculating doctor visit charges...',
            progress:70,
            delay:700
        },
       {
    text:'Processing room stay and room charges...',
    progress:75,
    delay:900
},
{
    text:'Processing pharmacy bills...',
    progress:90,
    delay:700
},
{
    text:'Generating final IPD invoice...',
    progress:100,
    delay:900
}
    ];
    const loader=document.getElementById('billing-loader');
    const status=document.getElementById('engine-status');
    const progress=document.getElementById('billing-progress');
    const percent=document.getElementById('engine-percent');
    let currentStep=0;
    function runStep(){
        if(currentStep>=steps.length){
            setTimeout(function(){
                loader.classList.add('hide');
            },600);
            return;
        }
        const step=steps[currentStep];
        status.textContent=step.text;
        progress.style.width=step.progress+'%';
        percent.textContent=step.progress+'%';
        currentStep++;
        setTimeout(
            runStep,
            step.delay
        );
    }
    runStep();
});
</script>
</body>
</html>