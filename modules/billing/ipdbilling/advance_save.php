<!--public_html/modules/billing/ipdbilling/advance_save.php  -->
<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    die("Session expired. Please login again.");
}
$user_id=(int)$_SESSION['user_id'];
$ipd_admission_id=(int)($_POST['ipd_admission_id'] ?? 0);
$patient_id=(int)($_POST['patient_id'] ?? 0);
$ipd_no=trim($_POST['ipd_no'] ?? '');
$payment_type=trim($_POST['payment_type'] ?? 'advance');
if($payment_type!=='balance'){
$payment_type='advance';
}
$payment_amount=(float)($_POST['payment_amount'] ?? 0);
$payment_mode=trim($_POST['payment_mode'] ?? 'Cash');
$payment_date_raw=trim($_POST['payment_date'] ?? '');
$remarks=trim($_POST['remarks'] ?? '');
if($ipd_admission_id<=0){
    die("Invalid IPD Admission.");
}
if($patient_id<=0){
    die("Invalid Patient.");
}
if($ipd_no===''){
    die("IPD No. is missing.");
}
if($payment_amount<=0){
    die("Advance amount must be greater than 0.");
}
$allowed_modes=[
    'Cash',
    'UPI',
    'Card',
    'Cheque',
    'NEFT',
    'Credit',
    'ECS'
];
if(!in_array($payment_mode,$allowed_modes,true)){
    die("Invalid payment mode.");
}
/*=========================================
GET IPD ADMISSION
=========================================*/
$stmt=$conn->prepare("
SELECT
    id,
    patient_id,
    ipd_no,
    status
FROM ipd_admissions
WHERE id=?
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
$admission=$result->fetch_assoc();
$stmt->close();
/*=========================================
VALIDATE PATIENT / IPD NO.
=========================================*/
if((int)$admission['patient_id']!==$patient_id){
    die("Patient mismatch.");
}
if($admission['ipd_no']!==$ipd_no){
    die("IPD No. mismatch.");
}
if($admission['status']==='Cancelled'){
    die("Cancelled IPD admission cannot receive advance.");
}
/*=========================================
GET PENDING IPD BILL FOR BALANCE PAYMENT
=========================================*/
$ipd_bill_id=null;
$bill_balance=0;
if($payment_type==='balance'){
$stmt=$conn->prepare("
SELECT
id,
balance_amount
FROM ipd_bills
WHERE ipd_admission_id=?
AND payment_status IN('Pending','Partial')
AND balance_amount>0
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param(
"i",
$ipd_admission_id
);
$stmt->execute();
$billResult=$stmt->get_result();
if($billResult->num_rows===0){
die("No pending IPD bill found.");
}
$bill=$billResult->fetch_assoc();
$stmt->close();
$ipd_bill_id=(int)$bill['id'];
$bill_balance=(float)$bill['balance_amount'];
if($payment_amount>$bill_balance){
die("Payment amount cannot be greater than pending balance.");
}
}
/*=========================================
PAYMENT DATE
=========================================*/
if($payment_date_raw!==''){
    $timestamp=strtotime($payment_date_raw);
    if($timestamp===false){
        die("Invalid payment date.");
    }
    $payment_date=date(
        'Y-m-d',
        $timestamp
    );
}else{
    $payment_date=date('Y-m-d');
}
/*=========================================
PAYMENT DETAILS
=========================================*/
$payment_details=[];
switch($payment_mode){
    case 'Cash':
        $payment_details=[
            'cash_remarks'=>trim($_POST['cash_remarks'] ?? '')
        ];
        break;
    case 'UPI':
        $payment_details=[
            'upi_app'=>trim($_POST['upi_app'] ?? ''),
            'upi_txn_id'=>trim($_POST['upi_txn_id'] ?? '')
        ];
        break;
    case 'Card':
        $payment_details=[
            'card_type'=>trim($_POST['card_type'] ?? ''),
            'card_bank_name'=>trim($_POST['card_bank_name'] ?? ''),
            'card_last4'=>trim($_POST['card_last4'] ?? ''),
            'card_auth_code'=>trim($_POST['card_auth_code'] ?? '')
        ];
        break;
    case 'Cheque':
        $payment_details=[
            'cheque_no'=>trim($_POST['cheque_no'] ?? ''),
            'cheque_bank'=>trim($_POST['cheque_bank'] ?? ''),
            'cheque_date'=>trim($_POST['cheque_date'] ?? '')
        ];
        break;
    case 'NEFT':
        $payment_details=[
            'neft_ref'=>trim($_POST['neft_ref'] ?? ''),
            'neft_bank'=>trim($_POST['neft_bank'] ?? '')
        ];
        break;
    case 'Credit':
        $payment_details=[
            'credit_days'=>trim($_POST['credit_days'] ?? ''),
            'approved_by'=>trim($_POST['approved_by'] ?? '')
        ];
        break;
    case 'ECS':
        $payment_details=[
            'ecs_bank_name'=>trim($_POST['ecs_bank_name'] ?? ''),
            'ecs_reference_no'=>trim($_POST['ecs_reference_no'] ?? ''),
            'ecs_transaction_date'=>trim($_POST['ecs_transaction_date'] ?? '')
        ];
        break;
}
$payment_details_json=json_encode(
    $payment_details,
    JSON_UNESCAPED_UNICODE
);
/*=========================================
GENERATE PAYMENT NUMBER - DATE WISE
=========================================*/
$payment_no = '';
$stmt = $conn->prepare("
    SELECT payment_no
    FROM ipd_payments
    WHERE payment_date = ?
      AND payment_no LIKE 'IPD-ADV-%'
    ORDER BY id DESC
    LIMIT 1
");
$stmt->bind_param(
    "s",
    $payment_date
);
$stmt->execute();
$result = $stmt->get_result();
$next_number = 1;
if ($last = $result->fetch_assoc()) {
    if (
        preg_match(
            '/IPD-ADV-(\d+)$/',
            $last['payment_no'],
            $matches
        )
    ) {
        $next_number = (int)$matches[1] + 1;
    }
}
$stmt->close();
$payment_no = 'IPD-ADV-' . str_pad(
    $next_number,
    6,
    '0',
    STR_PAD_LEFT
);
/*=========================================
SAVE PAYMENT
=========================================*/
$conn->begin_transaction();
try{
    $stmt=$conn->prepare("
INSERT INTO ipd_payments
(
ipd_bill_id,
ipd_admission_id,
patient_id,
ipd_no,
payment_no,
payment_date,
payment_amount,
payment_mode,
transaction_no,
payment_details,
remarks,
created_by
)
VALUES
(
?,
?,
?,
?,
?,
?,
?,
?,
?,
?,
?,
?
)
");
    $transaction_no='';
    if($payment_mode==='UPI'){
        $transaction_no=trim(
            $_POST['upi_txn_id'] ?? ''
        );
    }elseif($payment_mode==='NEFT'){
        $transaction_no=trim(
            $_POST['neft_ref'] ?? ''
        );
    }elseif($payment_mode==='ECS'){
        $transaction_no=trim(
            $_POST['ecs_reference_no'] ?? ''
        );
    }
    $stmt->bind_param(
"iiisssdssssi",
$ipd_bill_id,
$ipd_admission_id,
$patient_id,
$ipd_no,
$payment_no,
$payment_date,
$payment_amount,
$payment_mode,
$transaction_no,
$payment_details_json,
$remarks,
$user_id
);
    if(!$stmt->execute()){
        throw new Exception(
            $stmt->error
        );
    }
   $stmt->close();
/*=========================================
UPDATE IPD BILL AFTER BALANCE PAYMENT
=========================================*/
if($payment_type==='balance' && $ipd_bill_id>0){
$stmt=$conn->prepare("
UPDATE ipd_bills
SET
paid_amount=paid_amount+?,
balance_amount=GREATEST(0,balance_amount-?),
payment_status=CASE
WHEN balance_amount-?<=0 THEN 'Paid'
WHEN paid_amount+?>0 THEN 'Partial'
ELSE 'Pending'
END
WHERE id=?
AND ipd_admission_id=?
");
$stmt->bind_param(
"ddddii",
$payment_amount,
$payment_amount,
$payment_amount,
$payment_amount,
$ipd_bill_id,
$ipd_admission_id
);
if(!$stmt->execute()){
throw new Exception(
$stmt->error
);
}
$stmt->close();
}
$conn->commit();
}catch(Throwable $e){

    $conn->rollback();
    die(
        "Advance payment failed: ".
        htmlspecialchars($e->getMessage())
    );
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Advance Saved</title>
<style>
body{
margin:0;
font-family:Arial,Helvetica,sans-serif;
background:#eef2f7;
display:flex;
align-items:center;
justify-content:center;
height:100vh;
}
.box{
background:#fff;
padding:30px;
border-radius:12px;
box-shadow:0 10px 30px rgba(0,0,0,.12);
text-align:center;
width:400px;
max-width:90%;
}
.success{
font-size:42px;
margin-bottom:10px;
}
h2{
margin:5px 0 10px;
color:#198754;
}
.amount{
font-size:22px;
font-weight:bold;
color:#1565c0;
margin:10px 0;
}
.payment{
font-size:14px;
color:#475569;
}
button{
margin-top:20px;
padding:10px 25px;
border:none;
border-radius:7px;
background:#1976d2;
color:#fff;
font-weight:bold;
cursor:pointer;
}
</style>
</head>
<body>
<div class="box">
<div class="success"> ✅ </div>
<h2> <?= $payment_type==='balance' ? 'Payment Received Successfully' : 'Advance Received Successfully' ?> </h2>
<div class="amount"> ₹ <?= number_format($payment_amount,2) ?> </div>
<div class="payment"> Payment No.: <?= htmlspecialchars($payment_no) ?> </div>
<div class="payment"> Mode: <?= htmlspecialchars($payment_mode) ?> </div>
<button onclick="closePopup()">Close</button>
</div>
<script>
function closePopup(){
if(
window.parent &&
window.parent!==window
){
if(
typeof window.parent.closeAdvancePopup==='function'
){
window.parent.closeAdvancePopup();
}
window.parent.location.reload();
}else{
window.location.href='ipd_bill.php';
}
}
setTimeout(function(){
closePopup();
},2500);
</script>
</body>
</html>