<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}
if($_SERVER['REQUEST_METHOD']!='POST'){
    die("Invalid Request");
}
$conn->begin_transaction();
try{
$user_id=(int)$_SESSION['user_id'];
$opd_id=(int)($_POST['opd_id'] ?? 0);
$patient_id=(int)($_POST['patient_id'] ?? 0);
$doctor_id=(int)($_POST['doctor_id'] ?? 0);
$department_id=(int)($_POST['department_id'] ?? 0);
$empanelment_id=(int)($_POST['empanelment_id'] ?? 0);
$remarks=trim($_POST['remarks'] ?? '');
$grand_total=(float)($_POST['grand_total'] ?? 0);
$net_payable=(float)($_POST['net_payable'] ?? 0);
$paid_amount=(float)($_POST['paid_amount'] ?? 0);
$balance_amount=(float)($_POST['balance_amount'] ?? 0);
if($paid_amount <= 0){
    $bill_status = "Pending";
}elseif($balance_amount > 0){
    $bill_status = "Partial";
}else{
    $bill_status = "Paid";
}
$discount_master_id=(int)($_POST['bill_discount_master'] ?? 0);
$discount_amount=(float)($_POST['bill_discount_amount'] ?? 0);
$payment_mode=$_POST['payment_mode'] ?? 'Cash';
$receipt_date = $_POST['receipt_date'] ?? date('Y-m-d H:i:s');
$create_receipt = (int)($_POST['create_receipt'] ?? 0);

$service_ids=$_POST['service_id'] ?? [];
$charges=$_POST['charges'] ?? [];
$qty=$_POST['unit'] ?? [];
$emergency=$_POST['emr_charge'] ?? [];
$row_discount_type=$_POST['discount_type'] ?? [];
$row_discount_percent=$_POST['discount_percent'] ?? [];
$row_discount_amount=$_POST['discount_amount'] ?? [];
$net_amount=$_POST['net_amount'] ?? [];
$balance_service=$_POST['balance_amount'] ?? [];
if($opd_id<=0){
    throw new Exception("Invalid OPD.");
}
if(count($service_ids)==0){
    throw new Exception("Please select at least one service.");
}
/*-----------------------------------------
GET USER HOSPITAL
-----------------------------------------*/
/*-----------------------------------------
GET USER HOSPITAL
-----------------------------------------*/

$stmt=$conn->prepare("
SELECT hospital_id
FROM user_hospitals
WHERE user_id=?
LIMIT 1
");

$stmt->bind_param("i",$user_id);
$stmt->execute();

$res=$stmt->get_result();

if($res->num_rows==0){
    throw new Exception("Hospital not assigned.");
}

$row=$res->fetch_assoc();

$hospital_id=(int)$row['hospital_id'];

$stmt->close();

/*-----------------------------------------
GET USER BRANCH
-----------------------------------------*/

$stmt=$conn->prepare("
SELECT branch_id
FROM user_branches
WHERE user_id=?
LIMIT 1
");

$stmt->bind_param("i",$user_id);
$stmt->execute();

$res=$stmt->get_result();

if($res->num_rows==0){
    throw new Exception("Branch not assigned.");
}

$row=$res->fetch_assoc();

$branch_id=(int)$row['branch_id'];

$stmt->close();

/*-----------------------------------------
GET OPD NUMBER
-----------------------------------------*/

$stmt=$conn->prepare("
SELECT opd_no
FROM appointments
WHERE id=?
LIMIT 1
");

$stmt->bind_param("i",$opd_id);
$stmt->execute();

$res=$stmt->get_result();

if($res->num_rows==0){
    throw new Exception("OPD not found.");
}

$row=$res->fetch_assoc();

$opd_no=$row['opd_no'];

$stmt->close();
/*-----------------------------------------
GET HOSPITAL CODE
-----------------------------------------*/
$stmt=$conn->prepare("
SELECT hospital_code
FROM hospital_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$hospital_id);
$stmt->execute();
$res=$stmt->get_result();
$hospital=$res->fetch_assoc();
$stmt->close();
$hospital_code=$hospital['hospital_code'];
/*-----------------------------------------
GET BRANCH CODE
-----------------------------------------*/
$stmt=$conn->prepare("
SELECT branch_code
FROM branch_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$res=$stmt->get_result();
$branch=$res->fetch_assoc();
$stmt->close();
$branch_code=$branch['branch_code'];
/*-----------------------------------------
GET DISCOUNT DETAILS
-----------------------------------------*/
$discount_name=NULL;
$discount_type=NULL;
$discount_value=0;
if($discount_master_id>0){
    $stmt=$conn->prepare("
        SELECT
            discount_name,
            receipt_type,
            receipt_value
        FROM discount_master
        WHERE id=?
        LIMIT 1
    ");
    $stmt->bind_param("i",$discount_master_id);
    $stmt->execute();
    $res=$stmt->get_result();
    if($res->num_rows>0){
        $discount=$res->fetch_assoc();
        $discount_name=$discount['discount_name'];
        $discount_type=$discount['receipt_type'];
        $discount_value=$discount['receipt_value'];
    }
    $stmt->close();
}
/*-----------------------------------------
GENERATE BILL NUMBER
FORMAT:
WSH-CHD-OPD-120726-000001
-----------------------------------------*/
$today = date("dmy", strtotime($receipt_date));
$stmt=$conn->prepare("
SELECT IFNULL(MAX(bill_sequence),0)+1 AS next_sequence
FROM opd_bills
WHERE hospital_id=?
AND branch_id=?
AND DATE(bill_date)=DATE(?)
FOR UPDATE
");
$stmt->bind_param(
"iis",
$hospital_id,
$branch_id,
$receipt_date
);
$stmt->execute();
$res=$stmt->get_result();
$row=$res->fetch_assoc();
$stmt->close();
$sequence=(int)$row['next_sequence'];
$bill_sequence=str_pad($sequence,6,"0",STR_PAD_LEFT);
$bill_no=
$hospital_code.
"-".
$branch_code.
"-OPD-".
$today.
"-".
$bill_sequence;
/*-----------------------------------------
INSERT BILL HEADER
-----------------------------------------*/
$stmt=$conn->prepare("
INSERT INTO opd_bills
(
bill_no,
bill_sequence,
bill_date,
hospital_id,
branch_id,
opd_id,
patient_id,
doctor_id,
department_id,
empanelment_id,
grand_total,
discount_master_id,
discount_name,
discount_type,
discount_value,
discount_amount,
net_payable,
paid_amount,
balance_amount,
remarks,
status,
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

$stmt->bind_param(
"sssiiiiiiidissdddddssi",
$bill_no,
$bill_sequence,
$receipt_date,
$hospital_id,
$branch_id,
$opd_id,
$patient_id,
$doctor_id,
$department_id,
$empanelment_id,
$grand_total,
$discount_master_id,
$discount_name,
$discount_type,
$discount_value,
$discount_amount,
$net_payable,
$paid_amount,
$balance_amount,
$remarks,
$bill_status,
$user_id
);

if(!$stmt->execute()){
    throw new Exception($stmt->error);
}

$bill_id = $conn->insert_id;

$stmt->close();
/*-----------------------------------------
GENERATE RECEIPT NUMBER
FORMAT:
WSH-CHD-RCP-120726-000001
-----------------------------------------*/
$receipt_no = NULL;
$receipt_sequence = 0;

if($create_receipt == 1 && $paid_amount > 0){

    $stmt = $conn->prepare("
    SELECT IFNULL(MAX(receipt_sequence),0)+1 AS next_sequence
    
    
    FROM opd_bill_payments
    WHERE DATE(payment_date)=DATE(?)
    FOR UPDATE
    ");


$stmt->bind_param(
"s",
$receipt_date
);

    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    $stmt->close();

    $receipt_sequence = (int)$row['next_sequence'];

    $receipt_no =
    $hospital_code.
    "-".
    $branch_code.
    "-RCP-".
    $today.
    "-".
    str_pad($receipt_sequence,6,"0",STR_PAD_LEFT);

}
/*-----------------------------------------
INSERT BILL DETAILS
-----------------------------------------*/

$stmt=$conn->prepare("
INSERT INTO opd_bill_details
(
bill_id,
service_id,

charge,
qty,
emergency_charge,

discount_type,
discount_percent,
discount_amount,

net_amount,
balance_amount,

status
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
'Active'
)
");
for($i=0;$i<count($service_ids);$i++){
    $service_id=(int)$service_ids[$i];
    $charge=(float)$charges[$i];
    $quantity=(int)$qty[$i];
    $emergency_charge=(float)$emergency[$i];
    $service_discount_type=$row_discount_type[$i];
    $service_discount_percent=(float)$row_discount_percent[$i];
    $service_discount_amount=(float)$row_discount_amount[$i];
    $service_net_amount=(float)$net_amount[$i];
    $service_balance_amount=(float)$balance_service[$i];
    $stmt->bind_param(
    "iidddsdddd",
    $bill_id,
    $service_id,
    $charge,
    $quantity,
    $emergency_charge,
    $service_discount_type,
    $service_discount_percent,
    $service_discount_amount,
    $service_net_amount,
    $service_balance_amount
    );
    if(!$stmt->execute()){
        throw new Exception($stmt->error);
    }
}
$stmt->close();

if($create_receipt == 1 && $paid_amount > 0){
    $balance_after_payment =
    max(
        0,
        $net_payable - $paid_amount
    );
/*-----------------------------------------
INSERT PAYMENT
-----------------------------------------*/
$bank_name = NULL;
$reference_no = NULL;
$transaction_id = NULL;
$upi_app = NULL;
$card_type = NULL;
$card_last4 = NULL;
$card_auth_code = NULL;
$cheque_no = NULL;
$cheque_date = NULL;
$credit_days = NULL;
$approved_by = NULL;

switch($payment_mode){
case "UPI":
    $upi_app = $_POST['upi_app'] ?? NULL;
    $transaction_id = $_POST['upi_txn_id'] ?? NULL;
    break;
case "Card":
    $card_type = $_POST['card_type'] ?? NULL;
    $bank_name = $_POST['card_bank_name'] ?? NULL;
    $card_last4 = $_POST['card_last4'] ?? NULL;
    $card_auth_code = $_POST['card_auth_code'] ?? NULL;
    break;
case "Cheque":
    $cheque_no = $_POST['cheque_no'] ?? NULL;
    $bank_name = $_POST['cheque_bank'] ?? NULL;
    $cheque_date = $_POST['cheque_date'] ?? NULL;
    break;
case "NEFT":
    $reference_no = $_POST['neft_ref'] ?? NULL;
    $bank_name = $_POST['neft_bank'] ?? NULL;
    break;
case "ECS":
    $reference_no = $_POST['ecs_reference_no'] ?? NULL;
    $bank_name = $_POST['ecs_bank_name'] ?? NULL;
    break;
case "Credit":
    $credit_days = (int)($_POST['credit_days'] ?? 0);
    $approved_by = $_POST['approved_by'] ?? NULL;
    break;
}

$stmt = $conn->prepare("
INSERT INTO opd_bill_payments
(
bill_id,
receipt_no,
receipt_sequence,
payment_date,
payment_mode,
amount,
balance_after_payment,
bank_name,
reference_no,
transaction_id,
upi_app,
card_type,
card_last4,
card_auth_code,
cheque_no,
cheque_date,
credit_days,
approved_by,
received_by,
status
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
?,
?,
?,
?,
?,
?,
?,
?,
'Success'
)
");

$stmt->bind_param(
"isissddsssssssssisi",
$bill_id,
$receipt_no,
$receipt_sequence,
$receipt_date,
$payment_mode,
$paid_amount,
$balance_after_payment,
$bank_name,
$reference_no,
$transaction_id,
$upi_app,
$card_type,
$card_last4,
$card_auth_code,
$cheque_no,
$cheque_date,
$credit_days,
$approved_by,
$user_id
);

if(!$stmt->execute()){
    throw new Exception($stmt->error);
}
$stmt->close();
}
/*-----------------------------------------
UPDATE PRESCRIBED SERVICES
-----------------------------------------*/
$stmt=$conn->prepare("
UPDATE prescribed_services
SET
billing_status='Billed',
billing_id=?
WHERE
appointment_id=?
AND billing_status='Pending'
");
$stmt->bind_param(
"ii",
$bill_id,
$opd_id
);
if(!$stmt->execute()){
    throw new Exception($stmt->error);
}
$stmt->close();
/*-----------------------------------------
UPDATE APPOINTMENT
-----------------------------------------*/
/*
$stmt=$conn->prepare("
UPDATE appointments
SET
billing_status='Billed'
WHERE id=?
");
$stmt->bind_param("i",$opd_id);
if(!$stmt->execute()){
    throw new Exception($stmt->error);
}
$stmt->close();
*/ 
/*-----------------------------------------
AUDIT LOG
-----------------------------------------*/
if(function_exists('addAuditLog')){
    addAuditLog($conn,[
        'module'=>'OPD Billing',
        'module_id'=>$bill_id,
        'action'=>'Create',
        'description'=>"New OPD Bill Generated : ".$bill_no
    ]);
}
/*-----------------------------------------
COMMIT
-----------------------------------------*/
$conn->commit();
/*-----------------------------------------
PRINT PAGE
-----------------------------------------*/
header("Location:print_bill.php?bill_id=".$bill_id);
exit;
}
catch(Exception $e){
    $conn->rollback();
    echo "<h2 style='color:red'>Billing Failed</h2>";
    echo "<pre>";
    echo $e->getMessage();
    echo "</pre>";
}
?>