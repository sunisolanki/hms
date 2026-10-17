<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

$data=json_decode(file_get_contents('php://input'),true);

if(!$data || empty($data['charges']) || !is_array($data['charges'])){
    echo json_encode([
        'success'=>false,
        'message'=>'No charges received.'
    ]);
    exit;
}

$charges=$data['charges'];
$user_id=(int)($_SESSION['user_id'] ?? 0);

/*
PAYMENT DATA FROM POPUP
*/
$payment_amount=(float)($data['payment_amount'] ?? 0);
$payment_mode=trim($data['payment_mode'] ?? 'Cash');
$transaction_no=trim($data['transaction_no'] ?? '');
$payment_details=trim($data['payment_details'] ?? '');
$payment_remarks=trim($data['remarks'] ?? '');

$allowed_payment_modes=[
    'Cash',
    'UPI',
    'Card',
    'Cheque',
    'NEFT',
    'Credit',
    'ECS'
];

if(!in_array($payment_mode,$allowed_payment_modes,true)){
    $payment_mode='Cash';
}

$conn->begin_transaction();

try{

    $first_charge=$charges[0];

    $ipd_admission_id=(int)($first_charge['ipd_admission_id'] ?? 0);

    if($ipd_admission_id<=0){
        throw new Exception('Invalid IPD admission.');
    }

    /*
    GET IPD ADMISSION DETAILS
    */
    $stmt=$conn->prepare("
        SELECT
            id,
            patient_id,
            appointment_id,
            ipd_no,
            hospital_id,
            branch_id
        FROM ipd_admissions
        WHERE id=?
        LIMIT 1
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i",$ipd_admission_id);
    $stmt->execute();
    $ipd=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$ipd){
        throw new Exception('IPD admission not found.');
    }

    $patient_id=(int)$ipd['patient_id'];
    $appointment_id=(int)$ipd['appointment_id'];
    $ipd_no=$ipd['ipd_no'];
    $hospital_id=(int)$ipd['hospital_id'];
    $branch_id=(int)$ipd['branch_id'];

    /*
    GET HOSPITAL CODE
    */
    $stmt=$conn->prepare("
        SELECT hospital_code
        FROM hospital_master
        WHERE id=?
        LIMIT 1
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i",$hospital_id);
    $stmt->execute();
    $hospital=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $hospital_code=$hospital['hospital_code'] ?? 'WSH';

    /*
    GET BRANCH CODE
    */
    $stmt=$conn->prepare("
        SELECT branch_code
        FROM branch_master
        WHERE id=?
        LIMIT 1
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i",$branch_id);
    $stmt->execute();
    $branch=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $branch_code=$branch['branch_code'] ?? 'MAIN';

    /*
    CHECK EXISTING ACTIVE IPD BILL
    */
    $stmt=$conn->prepare("
        SELECT id,bill_no
        FROM ipd_bills
        WHERE ipd_admission_id=?
        AND payment_status<>'Cancelled'
        ORDER BY id DESC
        LIMIT 1
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i",$ipd_admission_id);
    $stmt->execute();
    $existing_bill=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $today=date('Y-m-d');
    $bill_id=0;

    /*
    CREATE IPD BILL IF NOT EXISTS
    */
    if(!$existing_bill){

        $bill_date=$today;
        $bill_date_format=date('dmy');

        $stmt=$conn->prepare("
            SELECT COUNT(*)+1 AS next_sequence
            FROM ipd_bills
            WHERE bill_date=?
        ");

        if(!$stmt){
            throw new Exception($conn->error);
        }

        $stmt->bind_param("s",$bill_date);
        $stmt->execute();
        $sequence_data=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        $sequence=str_pad(
            (int)$sequence_data['next_sequence'],
            6,
            "0",
            STR_PAD_LEFT
        );

        $bill_no=
            $hospital_code.
            "-".
            $branch_code.
            "-IPD-".
            $bill_date_format.
            "-".
            $sequence;

        $payment_status='Pending';
        $zero=0.00;
        $remarks='';

        $stmt=$conn->prepare("
            INSERT INTO ipd_bills
            (
                ipd_admission_id,
                appointment_id,
                patient_id,
                ipd_no,
                bill_no,
                bill_date,
                total_amount,
                discount_amount,
                net_amount,
                paid_amount,
                balance_amount,
                payment_status,
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
                ?,
                ?,
                ?
            )
        ");

        if(!$stmt){
            throw new Exception($conn->error);
        }

        $stmt->bind_param(
            "iiisssdddddssi",
            $ipd_admission_id,
            $appointment_id,
            $patient_id,
            $ipd_no,
            $bill_no,
            $bill_date,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            $payment_status,
            $remarks,
            $user_id
        );

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $bill_id=(int)$conn->insert_id;
        $stmt->close();

    }else{

        $bill_id=(int)$existing_bill['id'];

    }

    /*
    INSERT SELECTED CHARGES
    AND STORE THEIR BILL ITEM IDs
    */
    $selected_bill_item_ids=[];

    $stmt=$conn->prepare("
        INSERT INTO ipd_bill_items
        (
            ipd_bill_id,
            item_type,
            item_id,
            item_name,
            description,
            quantity,
            rate,
            amount,
            discount_amount,
            net_amount,
            service_date,
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

    if(!$stmt){
        throw new Exception($conn->error);
    }

    foreach($charges as $charge){

        $charge_id=(int)($charge['id'] ?? 0);
        $charge_name=trim($charge['name'] ?? '');
        $amount=(float)($charge['amount'] ?? 0);

        if($charge_id<=0 || $charge_name===''){
            continue;
        }

        $item_type='Charge';
        $description='';
        $quantity=1.00;
        $rate=$amount;
        $discount_amount=0.00;
        $net_amount=$amount;
        $service_date=$today;

        $stmt->bind_param(
            "isissddddssi",
            $bill_id,
            $item_type,
            $charge_id,
            $charge_name,
            $description,
            $quantity,
            $rate,
            $amount,
            $discount_amount,
            $net_amount,
            $service_date,
            $user_id
        );

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $selected_bill_item_ids[]=(int)$conn->insert_id;
    }

    $stmt->close();

    if(empty($selected_bill_item_ids)){
        throw new Exception('No valid charges were saved.');
    }

    /*
    RECALCULATE BILL TOTALS
    */
    $stmt=$conn->prepare("
        SELECT
            COALESCE(SUM(amount),0) AS total_amount,
            COALESCE(SUM(discount_amount),0) AS discount_amount,
            COALESCE(SUM(net_amount),0) AS net_amount
        FROM ipd_bill_items
        WHERE ipd_bill_id=?
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i",$bill_id);
    $stmt->execute();
    $totals=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total_amount=(float)$totals['total_amount'];
    $discount_amount=(float)$totals['discount_amount'];
    $net_amount=(float)$totals['net_amount'];

    /*
    GET EXISTING PAID AMOUNT
    */
    $stmt=$conn->prepare("
        SELECT COALESCE(SUM(payment_amount),0) AS paid_amount
        FROM ipd_payments
        WHERE ipd_bill_id=?
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i",$bill_id);
    $stmt->execute();
    $payment_data=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $existing_paid_amount=(float)$payment_data['paid_amount'];

    $current_balance=max(
        0,
        $net_amount-$existing_paid_amount
    );

    if($payment_amount>$current_balance && $payment_amount>0){
        throw new Exception(
            'Received amount cannot be greater than the pending balance of ₹'.
            number_format($current_balance,2)
        );
    }

    /*
    CREATE PAYMENT NUMBER
    */
    $receipt_date_format=date('dmy');

    $stmt=$conn->prepare("
        SELECT COUNT(*)+1 AS next_sequence
        FROM ipd_payments
        WHERE payment_date=?
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("s",$today);
    $stmt->execute();
    $sequence_data=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $receipt_sequence=str_pad(
        (int)$sequence_data['next_sequence'],
        6,
        "0",
        STR_PAD_LEFT
    );

    $payment_no=
        $hospital_code.
        "-".
        $branch_code.
        "-IPR-".
        $receipt_date_format.
        "-".
        $receipt_sequence;

    /*
    PRIMARY BILL ITEM ID
    IMPORTANT:
    DO NOT SAVE NULL HERE
    */
    $payment_type='Service';
    $ipd_bill_item_id=(int)$selected_bill_item_ids[0];

    /*
    SAVE PAYMENT
    */
    $stmt=$conn->prepare("
        INSERT INTO ipd_payments
        (
            ipd_bill_id,
            ipd_bill_item_id,
            ipd_admission_id,
            patient_id,
            ipd_no,
            payment_no,
            payment_date,
            payment_amount,
            payment_type,
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
            ?,
            ?,
            ?
        )
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        "iiiisssdsssssi",
        $bill_id,
        $ipd_bill_item_id,
        $ipd_admission_id,
        $patient_id,
        $ipd_no,
        $payment_no,
        $today,
        $payment_amount,
        $payment_type,
        $payment_mode,
        $transaction_no,
        $payment_details,
        $payment_remarks,
        $user_id
    );

    if(!$stmt->execute()){
        throw new Exception($stmt->error);
    }

    $payment_id=(int)$conn->insert_id;
    $stmt->close();

    /*
    LINK ALL SELECTED SERVICES / CHARGES
    WITH THIS PAYMENT RECEIPT
    */
    $mapStmt=$conn->prepare("
        INSERT INTO ipd_payment_items
        (
            payment_id,
            ipd_bill_item_id
        )
        VALUES
        (
            ?,
            ?
        )
    ");

    if(!$mapStmt){
        throw new Exception($conn->error);
    }

    foreach($selected_bill_item_ids as $selected_bill_item_id){

        $mapStmt->bind_param(
            "ii",
            $payment_id,
            $selected_bill_item_id
        );

        if(!$mapStmt->execute()){
            throw new Exception($mapStmt->error);
        }
    }

    $mapStmt->close();

    /*
    GET FINAL PAID AMOUNT
    */
    $stmt=$conn->prepare("
        SELECT COALESCE(SUM(payment_amount),0) AS paid_amount
        FROM ipd_payments
        WHERE ipd_bill_id=?
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i",$bill_id);
    $stmt->execute();
    $payment_data=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $paid_amount=(float)$payment_data['paid_amount'];

    $balance_amount=max(
        0,
        $net_amount-$paid_amount
    );

    if($paid_amount<=0){
        $payment_status='Pending';
    }elseif($paid_amount>=$net_amount){
        $payment_status='Paid';
    }else{
        $payment_status='Partial';
    }

    /*
    UPDATE IPD BILL
    */
    $stmt=$conn->prepare("
        UPDATE ipd_bills
        SET
            total_amount=?,
            discount_amount=?,
            net_amount=?,
            paid_amount=?,
            balance_amount=?,
            payment_status=?
        WHERE id=?
    ");

    if(!$stmt){
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        "dddddsi",
        $total_amount,
        $discount_amount,
        $net_amount,
        $paid_amount,
        $balance_amount,
        $payment_status,
        $bill_id
    );

    if(!$stmt->execute()){
        throw new Exception($stmt->error);
    }

    $stmt->close();

    $conn->commit();

    $receipt_url=
        "/modules/ipd/ajax/receipt_print.php?payment_id=".
        $payment_id;

    echo json_encode([
        'success'=>true,
        'message'=>'Charges and payment saved successfully.',
        'ipd_bill_id'=>$bill_id,
        'payment_id'=>$payment_id,
        'payment_no'=>$payment_no,
        'receipt_url'=>$receipt_url,
        'total_amount'=>$total_amount,
        'paid_amount'=>$paid_amount,
        'balance_amount'=>$balance_amount,
        'payment_status'=>$payment_status
    ]);

}catch(Exception $e){

    $conn->rollback();

    echo json_encode([
        'success'=>false,
        'message'=>$e->getMessage()
    ]);
}
?>