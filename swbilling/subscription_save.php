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

$user_id=(int)$_SESSION['user_id'];
$isSuperAdmin=((int)$_SESSION['rights_type_id']===1);

if($_SERVER['REQUEST_METHOD']!="POST"){
    header("Location:subscriptions.php");
    exit;
}

$subscription_id=(int)($_POST['subscription_id'] ?? 0);
$isNewSubscription = ($subscription_id == 0);

if(
    ($subscription_id>0 && !checkPermission('swbilling','edit')) ||
    ($subscription_id==0 && !checkPermission('swbilling','add'))
){
    header("Location:/access_denied.php");
    exit;
}

/*----------------------------------------
GET POST VALUES
----------------------------------------*/

$hospital_id=(int)($_POST['hospital_id'] ?? 0);

$plan_name=trim($_POST['plan_name'] ?? '');

$billing_type=trim($_POST['billing_type'] ?? '');

$start_date=$_POST['start_date'] ?? '';

$end_date=$_POST['end_date'] ?? '';

$renewal_date=$_POST['renewal_date'] ?? '';

$amount=(float)($_POST['amount'] ?? 0);

$gst_percent=(float)($_POST['gst_percent'] ?? 0);

$gst_amount=(float)($_POST['gst_amount'] ?? 0);

$discount=(float)($_POST['discount'] ?? 0);

$total_amount=(float)($_POST['total_amount'] ?? 0);

$status=$_POST['status'] ?? 'Pending';

$remarks=trim($_POST['remarks'] ?? '');

$is_auto_renew=0;

$grace_days=0;


/*----------------------------------------
SERVER VALIDATION
----------------------------------------*/

if($hospital_id<=0){
    die("Invalid Hospital.");
}

if($plan_name==''){
    die("Plan Name is required.");
}

$billingTypes=[
    'Monthly',
    'Annual',
    'One Time',
    'AMC'
];

if(!in_array($billing_type,$billingTypes,true)){
    die("Invalid Billing Type.");
}

if($amount<=0){
    die("Invalid Amount.");
}

if($gst_percent<0 || $gst_percent>100){
    die("Invalid GST Percentage.");
}

if($start_date=='' || $end_date==''){
    die("Start Date and End Date are required.");
}

if($end_date<$start_date){
    die("End Date cannot be earlier than Start Date.");
}

if($renewal_date!='' && $renewal_date<$start_date){
    die("Renewal Date cannot be earlier than Start Date.");
}


/*----------------------------------------
CHECK ACTIVE HOSPITAL
----------------------------------------*/

$stmt=$conn->prepare("
SELECT
    id
FROM hospital_master
WHERE id=?
AND status=1
LIMIT 1
");

$stmt->bind_param("i",$hospital_id);

$stmt->execute();

if(!$stmt->get_result()->fetch_assoc()){
    die("Hospital not found or inactive.");
}

$stmt->close();


/*----------------------------------------
CHECK USER HOSPITAL
----------------------------------------*/

if(!$isSuperAdmin){

    $stmt=$conn->prepare("
    SELECT hospital_id
    FROM user_hospitals
    WHERE user_id=?
    AND hospital_id=?
    LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $user_id,
        $hospital_id
    );

    $stmt->execute();

    if(!$stmt->get_result()->fetch_assoc()){

        die("You are not authorised for this hospital.");

    }

    $stmt->close();

}


/*----------------------------------------
CHECK DUPLICATE
----------------------------------------*/

$stmt=$conn->prepare("
SELECT
    id
FROM software_subscriptions
WHERE hospital_id=?
AND id<>?
LIMIT 1
");

$stmt->bind_param(
    "ii",
    $hospital_id,
    $subscription_id
);

$stmt->execute();

if($stmt->get_result()->fetch_assoc()){

    die("Subscription already exists for this hospital.");

}

$stmt->close();


/*----------------------------------------
LOAD OLD RECORD
----------------------------------------*/

$oldData=[];

$changedFields=[];

if($subscription_id>0){

    $stmt=$conn->prepare("
    SELECT *
    FROM software_subscriptions
    WHERE id=?
    LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $subscription_id
    );

    $stmt->execute();

    $oldData=$stmt->get_result()->fetch_assoc();

    $stmt->close();

}
/*----------------------------------------
COMPARE CHANGED FIELDS
----------------------------------------*/

if(!empty($oldData)){

    $fields=[

        'hospital_id'=>'Hospital',

        'plan_name'=>'Plan Name',

        'billing_type'=>'Billing Type',

        'start_date'=>'Start Date',

        'end_date'=>'End Date',

        'renewal_date'=>'Renewal Date',

        'amount'=>'Amount',

        'gst_percent'=>'GST %',

        'gst_amount'=>'GST Amount',

        'discount'=>'Discount',

        'total_amount'=>'Total Amount',

        'status'=>'Status',

        'remarks'=>'Remarks',

        'is_auto_renew'=>'Auto Renew',

        'grace_days'=>'Grace Days'

    ];

    foreach($fields as $dbField=>$label){

        $oldValue=(string)($oldData[$dbField] ?? '');

        $newValue=(string)($$dbField ?? '');

        if($oldValue!==$newValue){

            $changedFields[$label]=[
                'old'=>$oldValue,
                'new'=>$newValue
            ];

        }

    }

}


/*----------------------------------------
START TRANSACTION
----------------------------------------*/

$conn->begin_transaction();

try{


/*----------------------------------------
INSERT
----------------------------------------*/

if($subscription_id==0){

    $stmt=$conn->prepare("

    INSERT INTO software_subscriptions(

        hospital_id,

        plan_name,

        billing_type,

        start_date,

        end_date,

        renewal_date,

        is_auto_renew,

        grace_days,

        amount,

        gst_percent,

        gst_amount,

        discount,

        total_amount,

        status,

        remarks,

        created_by

    )

    VALUES(

        ?,?,?,?,?,?,?,

        ?,?,?,?,?,?,?,?,?

    )

    ");

    $stmt->bind_param(

        "isssssiidddddssi",

        $hospital_id,

        $plan_name,

        $billing_type,

        $start_date,

        $end_date,

        $renewal_date,

        $is_auto_renew,

        $grace_days,

        $amount,

        $gst_percent,

        $gst_amount,

        $discount,

        $total_amount,

        $status,

        $remarks,

        $user_id

    );

}


/*----------------------------------------
UPDATE
----------------------------------------*/

else{

    $stmt=$conn->prepare("

    UPDATE software_subscriptions

    SET

        hospital_id=?,

        plan_name=?,

        billing_type=?,

        start_date=?,

        end_date=?,

        renewal_date=?,

        is_auto_renew=?,

        grace_days=?,

        amount=?,

        gst_percent=?,

        gst_amount=?,

        discount=?,

        total_amount=?,

        status=?,

        remarks=?

    WHERE id=?

    ");

    $stmt->bind_param(

        "isssssiidddddssi",

        $hospital_id,

        $plan_name,

        $billing_type,

        $start_date,

        $end_date,

        $renewal_date,

        $is_auto_renew,

        $grace_days,

        $amount,

        $gst_percent,

        $gst_amount,

        $discount,

        $total_amount,

        $status,

        $remarks,

        $subscription_id

    );

}


/*----------------------------------------
EXECUTE
----------------------------------------*/

if(!$stmt->execute()){

    throw new Exception($stmt->error);

}

$stmt->close();


if($subscription_id==0){

    $subscription_id=$conn->insert_id;

}
/*----------------------------------------
GENERATE FIRST INVOICE
----------------------------------------*/

$invoice_id = 0;

if($isNewSubscription){

    // Get Next Invoice Sequence
$result = $conn->query("
SELECT IFNULL(MAX(invoice_sequence),0)+1 AS next_sequence
FROM software_invoices
");

if(!$result){
    throw new Exception($conn->error);
}
    $row = $result->fetch_assoc();

    $invoice_sequence = (int)$row['next_sequence'];

    // Invoice Number

    $invoice_no = 'SW-'.date('Ymd').'-'.str_pad($invoice_sequence,6,'0',STR_PAD_LEFT);

    // Invoice Date

    $invoice_date = date('Y-m-d');

    $paid_amount = 0.00;

    $balance_amount = round($total_amount,2);

    $payment_status = 'Pending';

    $stmt = $conn->prepare("

        INSERT INTO software_invoices(

            subscription_id,

            invoice_no,

            invoice_sequence,

            invoice_date,

            billing_from,

            billing_to,

            billing_type,

            amount,

            gst_percent,

            gst_amount,

            discount,

            total_amount,

            paid_amount,

            balance_amount,

            payment_status,

            remarks,

            created_by

        )

        VALUES(

            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?

        )

    ");

    $stmt->bind_param(

        "isissssddddddsssi",

        $subscription_id,

        $invoice_no,

        $invoice_sequence,

        $invoice_date,

        $start_date,

        $end_date,

        $billing_type,

        $amount,

        $gst_percent,

        $gst_amount,

        $discount,

        $total_amount,

        $paid_amount,

        $balance_amount,

        $payment_status,

        $remarks,

        $user_id

    );

    if(!$stmt->execute()){

        throw new Exception($stmt->error);

    }

    $invoice_id = $conn->insert_id;

    $stmt->close();

}
/*----------------------------------------
AUDIT LOG
----------------------------------------*/

if(empty($oldData)){

    $newValues=[
        'Hospital ID'=>$hospital_id,
        'Plan Name'=>$plan_name,
        'Billing Type'=>$billing_type,
        'Start Date'=>$start_date,
        'End Date'=>$end_date,
        'Renewal Date'=>$renewal_date,
        'Auto Renew'=>$is_auto_renew,
        'Grace Days'=>$grace_days,
        'Amount'=>$amount,
        'GST %'=>$gst_percent,
        'GST Amount'=>$gst_amount,
        'Discount'=>$discount,
        'Total Amount'=>$total_amount,
        'Status'=>$status,
        'Remarks'=>$remarks
    ];

    addAuditLog(
        'Software Subscription',
        'Create',
        'Subscription Created : '.$plan_name,
        $subscription_id,
        '',
        json_encode($newValues),
        'SUCCESS'
    );

}else{

    if(!empty($changedFields)){

        $oldValues=[];

        $newValues=[];

        foreach($changedFields as $field=>$values){

            $oldValues[$field]=$values['old'];

            $newValues[$field]=$values['new'];

        }

        addAuditLog(
            'Software Subscription',
            'Update',
            'Subscription Updated : '.$plan_name,
            $subscription_id,
            json_encode($oldValues),
            json_encode($newValues),
            'SUCCESS'
        );

    }

}


/*----------------------------------------
COMMIT
----------------------------------------*/

$conn->commit();

$_SESSION['success_message']="Subscription saved successfully.";

if($isNewSubscription){

    header("Location:payments.php?invoice_id=".$invoice_id);

}else{

    header("Location:index.php");

}

exit;

}catch(Exception $e){

    $conn->rollback();

    addAuditLog(
        'Software Subscription',
        'Error',
        $e->getMessage(),
        $subscription_id,
        '',
        '',
        'FAILED'
    );

    $_SESSION['error_message']=$e->getMessage();

    header("Location:subscriptions.php");

    exit;

}

$conn->close();

?>