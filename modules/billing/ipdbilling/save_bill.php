<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if($_SERVER['REQUEST_METHOD']!=='POST'){
    die('Invalid request');
}

$conn->begin_transaction();

try{

    /*========================================================
      POST DATA
    ========================================================*/

    $ipd_admission_id=(int)($_POST['ipd_admission_id'] ?? 0);
    $remarks=trim($_POST['remarks'] ?? '');

    $receipt_date=$_POST['receipt_date'] ?? date('Y-m-d H:i:s');

    if($receipt_date===''){
        $receipt_date=date('Y-m-d H:i:s');
    }

    $payment_date=date('Y-m-d',strtotime($receipt_date));

    $grand_total=(float)($_POST['grand_total'] ?? 0);
    $bill_discount_amount=(float)($_POST['bill_discount_amount'] ?? 0);
    $net_payable=(float)($_POST['net_payable'] ?? 0);
    $paid_amount=(float)($_POST['received_amount'] ?? 0);
    $balance_amount=max(0,$net_payable-$paid_amount);

    $service_ids=$_POST['service_id'] ?? [];
    $charges=$_POST['charges'] ?? [];
    $qty=$_POST['unit'] ?? [];
    $emergency=$_POST['emr_charge'] ?? [];
    $row_discount_type=$_POST['discount_type'] ?? [];
    $row_discount_percent=$_POST['discount_percent'] ?? [];
    $row_discount_amount=$_POST['discount_amount'] ?? [];
    $net_amount=$_POST['net_amount'] ?? [];

    $user_id=(int)($_SESSION['user_id'] ?? 0);

    if($ipd_admission_id<=0){
        throw new Exception('Invalid IPD admission.');
    }

    /*========================================================
      GET IPD ADMISSION DETAILS
    ========================================================*/
$stmt=$conn->prepare("
    SELECT
        id,
        patient_id,
        appointment_id,
        ipd_no,
        hospital_id,
        branch_id,
        doctor_id,
        department_id,
        payment_type
    FROM ipd_admissions
    WHERE id=?
    LIMIT 1
");
    $stmt->bind_param("i",$ipd_admission_id);
    $stmt->execute();

    $res=$stmt->get_result();

    if($res->num_rows===0){
        throw new Exception('IPD admission not found.');
    }

    $ipd=$res->fetch_assoc();
    $stmt->close();

    $patient_id=(int)$ipd['patient_id'];
    $appointment_id=(int)$ipd['appointment_id'];
    $ipd_no=$ipd['ipd_no'];
    $hospital_id=(int)$ipd['hospital_id'];
    $branch_id=(int)$ipd['branch_id'];
    $payment_mode=$ipd['payment_type'] ?? 'Cash';

    /*========================================================
      GET HOSPITAL CODE
    ========================================================*/

    $stmt=$conn->prepare("
        SELECT hospital_code
        FROM hospital_master
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param("i",$hospital_id);
    $stmt->execute();

    $hospital=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $hospital_code=$hospital['hospital_code'] ?? 'WSH';

    /*========================================================
      GET BRANCH CODE
    ========================================================*/

    $stmt=$conn->prepare("
        SELECT branch_code
        FROM branch_master
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param("i",$branch_id);
    $stmt->execute();

    $branch=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $branch_code=$branch['branch_code'] ?? 'MAIN';

    /*========================================================
      CHECK EXISTING ACTIVE IPD BILL
    ========================================================*/

    $stmt=$conn->prepare("
        SELECT id,bill_no
        FROM ipd_bills
        WHERE ipd_admission_id=?
        AND payment_status<>'Cancelled'
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->bind_param("i",$ipd_admission_id);
    $stmt->execute();

    $existing_result=$stmt->get_result();
    $existing_bill=$existing_result->fetch_assoc();
    $stmt->close();

    $bill_id=0;
    $bill_no='';

    /*========================================================
      CREATE NEW IPD BILL
    ========================================================*/

    if(!$existing_bill){

        $today=date('dmy',strtotime($receipt_date));

        /*
        Generate:
        WSH-CHD-IPD-230826-000001
        */

        $stmt=$conn->prepare("
            SELECT COUNT(*)+1 AS next_sequence
            FROM ipd_bills
            WHERE DATE(bill_date)=?
        ");

        $stmt->bind_param("s",$payment_date);
        $stmt->execute();

        $sequence_data=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        $sequence=(int)$sequence_data['next_sequence'];

        $sequence=str_pad(
            $sequence,
            6,
            "0",
            STR_PAD_LEFT
        );

        $bill_no=
            $hospital_code.
            "-".
            $branch_code.
            "-IPD-".
            $today.
            "-".
            $sequence;

        if($paid_amount<=0){
            $payment_status='Pending';
        }elseif($paid_amount>=$net_payable){
            $payment_status='Paid';
        }else{
            $payment_status='Partial';
        }

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

       $stmt->bind_param(
    "iiisssdddddssi",
    $ipd_admission_id,
    $appointment_id,
    $patient_id,
    $ipd_no,
    $bill_no,
    $payment_date,
    $grand_total,
    $bill_discount_amount,
    $net_payable,
    $paid_amount,
    $balance_amount,
    $payment_status,
    $remarks,
    $user_id
);

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $bill_id=$conn->insert_id;
        $stmt->close();

    }else{

        /*
        Existing bill:
        add new items and update totals
        */

        $bill_id=(int)$existing_bill['id'];
        $bill_no=$existing_bill['bill_no'];

        $stmt=$conn->prepare("
            SELECT
                total_amount,
                discount_amount,
                net_amount,
                paid_amount
            FROM ipd_bills
            WHERE id=?
            LIMIT 1
        ");

        $stmt->bind_param("i",$bill_id);
        $stmt->execute();

        $old_bill=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        $grand_total=
            (float)$old_bill['total_amount']
            +
            $grand_total;

        $bill_discount_amount=
            (float)$old_bill['discount_amount']
            +
            $bill_discount_amount;

        $net_payable=
            (float)$old_bill['net_amount']
            +
            $net_payable;

        $paid_amount=
            (float)$old_bill['paid_amount']
            +
            $paid_amount;

        $balance_amount=
            max(
                0,
                $net_payable-$paid_amount
            );

        if($paid_amount<=0){
            $payment_status='Pending';
        }elseif($paid_amount>=$net_payable){
            $payment_status='Paid';
        }else{
            $payment_status='Partial';
        }

        $stmt=$conn->prepare("
            UPDATE ipd_bills
            SET
                total_amount=?,
                discount_amount=?,
                net_amount=?,
                paid_amount=?,
                balance_amount=?,
                payment_status=?,
                remarks=?
            WHERE id=?
        ");

        $stmt->bind_param(
            "dddddssi",
            $grand_total,
            $bill_discount_amount,
            $net_payable,
            $paid_amount,
            $balance_amount,
            $payment_status,
            $remarks,
            $bill_id
        );

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $stmt->close();
    }

    /*========================================================
      INSERT IPD BILL ITEMS
    ========================================================*/

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
            'Service',
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
$last_ipd_bill_item_id=0;
$ipd_bill_item_ids=[];
    for($i=0;$i<count($service_ids);$i++){

        $service_id=(int)($service_ids[$i] ?? 0);

        if($service_id<=0){
            continue;
        }

        $rate=(float)($charges[$i] ?? 0);
        $quantity=(float)($qty[$i] ?? 1);
        $emergency_charge=(float)($emergency[$i] ?? 0);

        $service_discount_amount=
            (float)($row_discount_amount[$i] ?? 0);

        $service_net_amount=
            (float)($net_amount[$i] ?? 0);

        $amount=
            ($rate*$quantity)
            +
            $emergency_charge;

        $stmtService=$conn->prepare("
            SELECT service_name
            FROM service_master
            WHERE id=?
            LIMIT 1
        ");

        $stmtService->bind_param(
            "i",
            $service_id
        );

        $stmtService->execute();

        $serviceData=
            $stmtService
            ->get_result()
            ->fetch_assoc();

        $stmtService->close();

        $item_name=
            $serviceData['service_name']
            ??
            'Service';

        $description='';

        $service_date=$payment_date;

    $stmt->bind_param(
    "iissdddddsi",
    $bill_id,
    $service_id,
    $item_name,
    $description,
    $quantity,
    $rate,
    $amount,
    $service_discount_amount,
    $service_net_amount,
    $service_date,
    $user_id
);
        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }
        $last_ipd_bill_item_id=$conn->insert_id;
$ipd_bill_item_ids[]=$last_ipd_bill_item_id;
    }

    $stmt->close();

    /*========================================================
      UPDATE PRESCRIBED SERVICES
    ========================================================*/

    if($appointment_id>0){

        $stmt=$conn->prepare("
            UPDATE prescribed_services
            SET billing_status='Billed'
            WHERE appointment_id=?
            AND billing_status='Pending'
        ");

        $stmt->bind_param(
            "i",
            $appointment_id
        );

        if(!$stmt->execute()){
            throw new Exception($stmt->error);
        }

        $stmt->close();
    }

    /*========================================================
      CREATE PAYMENT + RECEIPT
    ========================================================*/

   $current_payment=(float)($_POST['received_amount'] ?? 0);
$payment_type='Service';

$payment_id=0;

if($last_ipd_bill_item_id>0){

        /*
        Payment Number:
        WSH-CHD-RCP-230826-000001
        */

        $today=date('dmy',strtotime($receipt_date));

        $stmt=$conn->prepare("
            SELECT COUNT(*)+1 AS next_sequence
            FROM ipd_payments
            WHERE DATE(payment_date)=?
        ");

        $stmt->bind_param(
            "s",
            $payment_date
        );

        $stmt->execute();

        $payment_sequence=
            $stmt
            ->get_result()
            ->fetch_assoc()['next_sequence'];
        $stmt->close();
        $payment_sequence=str_pad(
            $payment_sequence,
            6,
            "0",
            STR_PAD_LEFT
        );
        $payment_no=
            $hospital_code.
            "-".
            $branch_code.
            "-RCP-".
            $today.
            "-".
            $payment_sequence;
        /*
        Transaction number/details
        */
        $transaction_no=
            $_POST['upi_txn_id']
            ??
            $_POST['neft_ref']
            ??
            $_POST['ecs_reference_no']
            ??
            $_POST['cheque_no']
            ??
            NULL;
        $payment_details=
            $_POST['payment_details']
            ??
            NULL;
        $payment_remarks=
            $_POST['remarks']
            ??
            NULL;
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

        $stmt->bind_param(
            "iiiisssdsssssi",
            $bill_id,
            $last_ipd_bill_item_id,
            $ipd_admission_id,
            $patient_id,
            $ipd_no,
            $payment_no,
            $payment_date,
            $current_payment,
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

$payment_id=$conn->insert_id;

$stmt->close();

/* ========================================================
INSERT ALL BILL ITEMS LINKED TO THIS PAYMENT
======================================================== */

if(!empty($ipd_bill_item_ids)){

    $itemStmt=$conn->prepare("
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

    if(!$itemStmt){
        throw new Exception($conn->error);
    }

    foreach($ipd_bill_item_ids as $ipd_bill_item_id){

        $itemStmt->bind_param(
            "ii",
            $payment_id,
            $ipd_bill_item_id
        );

        if(!$itemStmt->execute()){
            throw new Exception($itemStmt->error);
        }
    }

    $itemStmt->close();
}
    }
    /*========================================================
      COMMIT
    ========================================================*/
    $conn->commit();
    /*
    Temporary redirect.
    We will create the IPD bill print page next.
    */
    if($payment_id>0){
    header(
        "Location:ipd_print_service_receipt.php?payment_id=".$payment_id
    );
    exit;
}else{
    header(
        "Location:ipd_bill.php?success=1&bill_id=".$bill_id
    );
    exit;
}
}catch(Exception $e){
    $conn->rollback();
    die("Billing Failed: ".$e->getMessage());
}
?>