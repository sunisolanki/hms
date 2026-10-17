<!-- /public_html/modules/billing/ipdbilling/ipd_ledger/generate_ledger.php -->
<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/ipd_room_charges.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}

$ipdAdmissionId=(int)($_GET['ipd_admission_id'] ?? $_POST['ipd_admission_id'] ?? 0);

if($ipdAdmissionId<=0){
    http_response_code(400);
    die('Invalid IPD Admission ID.');
}

function e($value){
    return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}

function getUserName($conn,$userId){
    $userId=(int)$userId;

    if($userId<=0){
        return 'System';
    }

    $stmt=$conn->prepare("
        SELECT full_name
        FROM users
        WHERE id=?
        LIMIT 1
    ");

    if(!$stmt){
        return 'System';
    }

    $stmt->bind_param("i",$userId);
    $stmt->execute();

    $result=$stmt->get_result();
    $row=$result->fetch_assoc();

    $stmt->close();

    return !empty($row['full_name'])?$row['full_name']:'System';
}

function getRoomAllocationCharge($conn,$allocationId,$asOfDateTime){
    $allocationId=(int)$allocationId;

    if($allocationId<=0){
        return [
            'days'=>0,
            'room_rent'=>0,
            'mapped_charges'=>0,
            'total'=>0,
            'calculation'=>''
        ];
    }

    $stmt=$conn->prepare("
        SELECT
            ira.id AS allocation_id,
            ira.room_id,
            ira.allocated_date,
            ira.released_date,
            rm.room_rent,
            rm.room_name
        FROM ipd_room_allocation ira
        INNER JOIN room_master rm
            ON rm.id=ira.room_id
        WHERE ira.id=?
        LIMIT 1
    ");

    if(!$stmt){
        return [
            'days'=>0,
            'room_rent'=>0,
            'mapped_charges'=>0,
            'total'=>0,
            'calculation'=>''
        ];
    }

    $stmt->bind_param("i",$allocationId);
    $stmt->execute();

    $result=$stmt->get_result();
    $room=$result->fetch_assoc();

    $stmt->close();

    if(!$room){
        return [
            'days'=>0,
            'room_rent'=>0,
            'mapped_charges'=>0,
            'total'=>0,
            'calculation'=>''
        ];
    }

    $asOfDate=new DateTime($asOfDateTime);
    $startDate=new DateTime($room['allocated_date']);

    if(!empty($room['released_date'])){
        $releaseDate=new DateTime($room['released_date']);

        if($releaseDate<$asOfDate){
            $endDate=$releaseDate;
        }else{
            $endDate=$asOfDate;
        }
    }else{
        $endDate=$asOfDate;
    }

    if($endDate<$startDate){
        return [
            'days'=>0,
            'room_rent'=>0,
            'mapped_charges'=>0,
            'total'=>0,
            'calculation'=>''
        ];
    }

    /*
     * IPD ROOM BILLING CUTOFF
     * Every billing day changes at 12:00 PM.
     */

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

    $mappedChargesTotal=0;
    $mappedChargeDetails=[];

    $chargeStmt=$conn->prepare("
        SELECT
            amount,
            frequency
        FROM room_charge_mapping
        WHERE room_id=?
        ORDER BY id ASC
    ");

    if($chargeStmt){

        $roomId=(int)$room['room_id'];

        $chargeStmt->bind_param("i",$roomId);
        $chargeStmt->execute();

        $chargeResult=$chargeStmt->get_result();

        while($charge=$chargeResult->fetch_assoc()){

            $rate=(float)$charge['amount'];

            if($charge['frequency']==='Day Wise'){

                $chargeAmount=$rate*$days;

                $mappedChargeDetails[]=
                    ($charge['frequency'] ?: 'Charge').
                    ': ₹'.
                    number_format($rate,2).
                    ' × '.
                    $days.
                    ' Day(s) = ₹'.
                    number_format($chargeAmount,2);

            }else{

                $chargeAmount=$rate;

                $mappedChargeDetails[]=
                    ($charge['frequency'] ?: 'Charge').
                    ': ₹'.
                    number_format($chargeAmount,2);
            }

            $mappedChargesTotal+=$chargeAmount;
        }

        $chargeStmt->close();
    }

    $total=$roomRentTotal+$mappedChargesTotal;

    $calculation=
        'Room: '.
        ($room['room_name'] ?: 'Room').
        ' | ₹'.
        number_format($roomRent,2).
        ' × '.
        $days.
        ' Day(s) = ₹'.
        number_format($roomRentTotal,2);

    if(!empty($mappedChargeDetails)){
        $calculation.=' | '.implode(' | ',$mappedChargeDetails);
    }

    $calculation.=' | Total ₹'.number_format($total,2);

    return [
        'days'=>$days,
        'room_rent'=>$roomRentTotal,
        'mapped_charges'=>$mappedChargesTotal,
        'total'=>$total,
        'calculation'=>$calculation
    ];
}

function upsertLedgerEntry(
    $conn,
    $admissionId,
    $patientId,
    $entryDate,
    $entryTime,
    $entryType,
    $referenceId,
    $referenceNo,
    $description,
    $calculationDetails,
    $debit,
    $credit,
    $sourceTable,
    $sourceId,
    $createdBy
){

    /*
     * source_table + source_id + entry_type
     * identifies the generated ledger transaction.
     *
     * This prevents duplicate entries when Generate Ledger
     * is clicked repeatedly.
     */

    $stmt=$conn->prepare("
        SELECT id
        FROM ipd_ledger
        WHERE ipd_admission_id=?
        AND source_table=?
        AND source_id=?
        AND entry_type=?
        AND is_reversal=0
        LIMIT 1
    ");

    if(!$stmt){
        throw new Exception('Ledger lookup failed: '.$conn->error);
    }

    $stmt->bind_param(
        "isis",
        $admissionId,
        $sourceTable,
        $sourceId,
        $entryType
    );

    $stmt->execute();

    $result=$stmt->get_result();
    $existing=$result->fetch_assoc();

    $stmt->close();

    if($existing){

        $ledgerId=(int)$existing['id'];

        $update=$conn->prepare("
            UPDATE ipd_ledger
            SET
                entry_date=?,
                entry_time=?,
                reference_id=?,
                reference_no=?,
                description=?,
                calculation_details=?,
                debit_amount=?,
                credit_amount=?,
                created_by=?
            WHERE id=?
        ");

        if(!$update){
            throw new Exception('Ledger update failed: '.$conn->error);
        }

        $update->bind_param(
            "ssisssddii",
            $entryDate,
            $entryTime,
            $referenceId,
            $referenceNo,
            $description,
            $calculationDetails,
            $debit,
            $credit,
            $createdBy,
            $ledgerId
        );

        if(!$update->execute()){
            throw new Exception('Ledger update failed: '.$update->error);
        }

        $update->close();

        return $ledgerId;
    }

    $insert=$conn->prepare("
        INSERT INTO ipd_ledger(
            ipd_admission_id,
            patient_id,
            entry_date,
            entry_time,
            entry_type,
            reference_id,
            reference_no,
            description,
            calculation_details,
            debit_amount,
            credit_amount,
            balance_amount,
            source_table,
            source_id,
            is_reversal,
            created_by
        )
        VALUES(
            ?,?,?,?,?,?,?,?,?,?,?,0,?, ?,0,?
        )
    ");

    if(!$insert){
        throw new Exception('Ledger insert failed: '.$conn->error);
    }

   $insert->bind_param(
    "iisssisssddsii",
    $admissionId,
    $patientId,
    $entryDate,
    $entryTime,
    $entryType,
    $referenceId,
    $referenceNo,
    $description,
    $calculationDetails,
    $debit,
    $credit,
    $sourceTable,
    $sourceId,
    $createdBy
);

    if(!$insert->execute()){
        throw new Exception('Ledger insert failed: '.$insert->error);
    }

    $ledgerId=$insert->insert_id;

    $insert->close();

    return $ledgerId;
}

/*
 * ---------------------------------------------------------
 * LOAD IPD ADMISSION
 * ---------------------------------------------------------
 */

$admissionStmt=$conn->prepare("
    SELECT
        ia.id,
        ia.ipd_no,
        ia.patient_id,
        ia.appointment_id,
        CONCAT_WS(
            ' ',
            p.title,
            p.first_name,
            p.last_name
        ) AS patient_name,
        p.uhid
    FROM ipd_admissions ia
    INNER JOIN patient_master p
        ON p.id=ia.patient_id
    WHERE ia.id=?
    LIMIT 1
");

if(!$admissionStmt){
    die('Admission query failed: '.$conn->error);
}

$admissionStmt->bind_param("i",$ipdAdmissionId);
$admissionStmt->execute();

$admissionResult=$admissionStmt->get_result();
$admission=$admissionResult->fetch_assoc();

$admissionStmt->close();

if(!$admission){
    die('IPD Admission not found.');
}

$patientId=(int)$admission['patient_id'];

/*
 * ---------------------------------------------------------
 * GENERATE / SYNCHRONIZE LEDGER
 * ---------------------------------------------------------
 */

try{

    $conn->begin_transaction();

    $generatedCount=0;
    $updatedCount=0;

    /*
     * -----------------------------------------------------
     * 1. IPD BILL ITEMS
     * -----------------------------------------------------
     */

    $billStmt=$conn->prepare("
        SELECT
            bi.id,
            bi.ipd_bill_id,
            ib.bill_no,
            ib.bill_date,
            bi.item_type,
            bi.item_name,
            bi.description,
            bi.quantity,
            bi.rate,
            bi.amount,
            bi.discount_amount,
            bi.net_amount,
            bi.service_date,
            bi.created_by,
            bi.created_at
        FROM ipd_bill_items bi
        INNER JOIN ipd_bills ib
            ON ib.id=bi.ipd_bill_id
        WHERE ib.ipd_admission_id=?
        AND ib.payment_status<>'Cancelled'
        ORDER BY
            COALESCE(bi.service_date,ib.bill_date) ASC,
            bi.created_at ASC,
            bi.id ASC
    ");

    if(!$billStmt){
        throw new Exception('Bill item query failed: '.$conn->error);
    }

    $billStmt->bind_param("i",$ipdAdmissionId);
    $billStmt->execute();

    $billResult=$billStmt->get_result();

    while($item=$billResult->fetch_assoc()){

        $netAmount=(float)$item['net_amount'];

        if($netAmount<=0){
            continue;
        }

        $createdAt=
            !empty($item['created_at'])
            ?new DateTime($item['created_at'])
            :new DateTime();

        $entryDate=
            !empty($item['service_date'])
            ?$item['service_date']
            :$item['bill_date'];

        $entryTime=$createdAt->format('H:i:s');

        $entryType='IPD '.$item['item_type'];

        $description=$item['item_name'];

        if(!empty($item['description'])){
            $description.=' - '.$item['description'];
        }

        $calculationDetails=
            number_format((float)$item['quantity'],2).
            ' × ₹'.
            number_format((float)$item['rate'],2).
            ' = ₹'.
            number_format((float)$item['amount'],2);

        if((float)$item['discount_amount']>0){
            $calculationDetails.=
                ' | Discount ₹'.
                number_format((float)$item['discount_amount'],2).
                ' | Net ₹'.
                number_format($netAmount,2);
        }else{
            $calculationDetails.=
                ' | Net ₹'.
                number_format($netAmount,2);
        }

        $createdBy=(int)$item['created_by'];

        upsertLedgerEntry(
            $conn,
            $ipdAdmissionId,
            $patientId,
            $entryDate,
            $entryTime,
            $entryType,
            (int)$item['id'],
            $item['bill_no'],
            $description,
            $calculationDetails,
            $netAmount,
            0,
            'ipd_bill_items',
            (int)$item['id'],
            $createdBy
        );

        $generatedCount++;
    }

    $billStmt->close();

    /*
     * -----------------------------------------------------
     * 2. DOCTOR VISITS
     * -----------------------------------------------------
     */

   $doctorStmt=$conn->prepare("
    SELECT
        dv.id,
        dv.prescription_id,
        dv.doctor_id,
        dv.visit_date,
        dv.visit_time,
        dv.charge_type,
        dv.charges,
        dv.comment,
        dv.created_by,
        dv.created_at,
        u.full_name AS doctor_name
    FROM ipd_doctor_visits dv
    LEFT JOIN doctor_master dm
        ON dm.id=dv.doctor_id
    LEFT JOIN users u
        ON u.id=dm.user_id
    WHERE dv.ipd_admission_id=?
    ORDER BY dv.visit_date ASC,dv.visit_time ASC,dv.id ASC
");

    if(!$doctorStmt){
        throw new Exception('Doctor visit query failed: '.$conn->error);
    }

    $doctorStmt->bind_param("i",$ipdAdmissionId);
    $doctorStmt->execute();

    $doctorResult=$doctorStmt->get_result();

    while($visit=$doctorResult->fetch_assoc()){

        $charge=(float)$visit['charges'];

        if($charge<=0){
            continue;
        }

        $description='Doctor Visit';

        if(!empty($visit['doctor_name'])){
            $description.=' - Dr. '.$visit['doctor_name'];
        }

        if(!empty($visit['charge_type'])){
            $description.=' - '.$visit['charge_type'];
        }

        if(!empty($visit['comment'])){
            $description.=' - '.$visit['comment'];
        }

        $calculationDetails=
            'Doctor Visit';

        if(!empty($visit['charge_type'])){
            $calculationDetails.=
                ' | Type: '.$visit['charge_type'];
        }

        $calculationDetails.=
            ' | Charge ₹'.
            number_format($charge,2);

        if(!empty($visit['prescription_id'])){
            $calculationDetails.=
                ' | Prescription #'.
                (int)$visit['prescription_id'];
        }

        $createdBy=(int)$visit['created_by'];

        upsertLedgerEntry(
            $conn,
            $ipdAdmissionId,
            $patientId,
            $visit['visit_date'],
            $visit['visit_time'],
            'Doctor Visit',
            (int)$visit['id'],
            'VISIT-'.$visit['id'],
            $description,
            $calculationDetails,
            $charge,
            0,
            'ipd_doctor_visits',
            (int)$visit['id'],
            $createdBy
        );

        $generatedCount++;
    }

    $doctorStmt->close();

    /*
     * -----------------------------------------------------
     * 3. ROOM CHARGES
     * -----------------------------------------------------
     */

    $roomStmt=$conn->prepare("
        SELECT
            ira.id,
            ira.room_id,
            ira.allocated_date,
            ira.released_date,
            rm.room_name,
            rm.room_rent
        FROM ipd_room_allocation ira
        INNER JOIN room_master rm
            ON rm.id=ira.room_id
        WHERE ira.admission_id=?
        AND ira.allocated_date<=?
        ORDER BY ira.allocated_date ASC,ira.id ASC
    ");

    if(!$roomStmt){
        throw new Exception('Room allocation query failed: '.$conn->error);
    }

    $roomAsOf=date('Y-m-d H:i:s');

    $roomStmt->bind_param(
        "is",
        $ipdAdmissionId,
        $roomAsOf
    );

    $roomStmt->execute();

    $roomResult=$roomStmt->get_result();

    while($room=$roomResult->fetch_assoc()){

        $roomCalculation=getRoomAllocationCharge(
            $conn,
            (int)$room['id'],
            $roomAsOf
        );

        $roomTotal=(float)$roomCalculation['total'];

        if($roomTotal<=0){
            continue;
        }

        $entryDate=date('Y-m-d');
        $entryTime='12:00:00';

        $referenceNo=
            'ROOM-'.$room['id'];

        $description=
            'Room Charges - '.
            ($room['room_name'] ?: 'Room');

        $calculationDetails=
            $roomCalculation['calculation'];

        upsertLedgerEntry(
            $conn,
            $ipdAdmissionId,
            $patientId,
            $entryDate,
            $entryTime,
            'Room Charges',
            (int)$room['id'],
            $referenceNo,
            $description,
            $calculationDetails,
            $roomTotal,
            0,
            'ipd_room_allocation',
            (int)$room['id'],
            0
        );

        $generatedCount++;
    }

    $roomStmt->close();

    /*
     * -----------------------------------------------------
     * 4. IPD PAYMENTS / ADVANCES
     * -----------------------------------------------------
     */

    $paymentStmt=$conn->prepare("
        SELECT
            p.id,
            p.payment_no,
            p.payment_date,
            p.payment_amount,
            p.payment_type,
            p.payment_mode,
            p.transaction_no,
            p.payment_details,
            p.remarks,
            p.ipd_bill_id,
            p.ipd_bill_item_id,
            p.created_by,
            p.created_at,
            u.full_name AS receiver_name
        FROM ipd_payments p
        LEFT JOIN users u
            ON u.id=p.created_by
        WHERE p.ipd_admission_id=?
        AND p.payment_amount>0
        ORDER BY p.payment_date ASC,p.created_at ASC,p.id ASC
    ");

    if(!$paymentStmt){
        throw new Exception('Payment query failed: '.$conn->error);
    }

    $paymentStmt->bind_param("i",$ipdAdmissionId);
    $paymentStmt->execute();

    $paymentResult=$paymentStmt->get_result();

    while($payment=$paymentResult->fetch_assoc()){

        $amount=(float)$payment['payment_amount'];

        if($amount<=0){
            continue;
        }

        $createdAt=
            !empty($payment['created_at'])
            ?new DateTime($payment['created_at'])
            :new DateTime();

        $entryDate=$payment['payment_date'];
        $entryTime=$createdAt->format('H:i:s');

       $description=
    $payment['payment_type']==='Advance'
    ?'Advance Received'
    :'Payment Received';

if(!empty($payment['payment_details']) && is_string($payment['payment_details'])){

    $paymentDetails=trim($payment['payment_details']);

    $decodedDetails=json_decode($paymentDetails,true);

    $hasUsefulDetails=false;

    if(json_last_error()===JSON_ERROR_NONE && is_array($decodedDetails)){

        foreach($decodedDetails as $detailValue){

            if(is_array($detailValue)){
                foreach($detailValue as $nestedValue){
                    if(trim((string)$nestedValue)!==''){
                        $hasUsefulDetails=true;
                        break;
                    }
                }
            }else{

                if(trim((string)$detailValue)!==''){
                    $hasUsefulDetails=true;
                }
            }

            if($hasUsefulDetails){
                break;
            }
        }

    }else{

        if(
            $paymentDetails!=='{}' &&
            $paymentDetails!=='[]'
        ){
            $hasUsefulDetails=true;
        }
    }

    if($hasUsefulDetails){
        $description.=' - '.$paymentDetails;
    }
}

if(!empty($payment['remarks'])){

    $paymentRemarks=trim($payment['remarks']);

    if($paymentRemarks!==''){
        $description.=' - '.$paymentRemarks;
    }
}

        $calculationDetails=
            'Payment Type: '.
            $payment['payment_type'].
            ' | Mode: '.
            $payment['payment_mode'].
            ' | Amount ₹'.
            number_format($amount,2);

        if(!empty($payment['transaction_no'])){
            $calculationDetails.=
                ' | Transaction: '.
                $payment['transaction_no'];
        }

        if(!empty($payment['remarks'])){
            $calculationDetails.=
                ' | Remarks: '.
                $payment['remarks'];
        }

        $createdBy=(int)$payment['created_by'];

        upsertLedgerEntry(
            $conn,
            $ipdAdmissionId,
            $patientId,
            $entryDate,
            $entryTime,
            $payment['payment_type']==='Advance'
                ?'Advance'
                :'IPD Payment',
            (int)$payment['id'],
            $payment['payment_no'],
            $description,
            $calculationDetails,
            0,
            $amount,
            'ipd_payments',
            (int)$payment['id'],
            $createdBy
        );

        $generatedCount++;
    }

    $paymentStmt->close();

    /*
     * -----------------------------------------------------
     * 5. PHARMACY SALES
     * -----------------------------------------------------
     */

    $pharmacySaleStmt=$conn->prepare("
        SELECT
            ps.id,
            ps.sale_no,
            ps.invoice_id,
            ps.sale_date,
            ps.grand_total,
            ps.status,
            ps.patient_id,
            ps.created_by,
            ps.remarks,
            ps.created_at,
            u.full_name AS user_name
        FROM pharmacy_sales ps
        LEFT JOIN users u
            ON u.id=ps.created_by
        WHERE ps.patient_id=?
        AND ps.appointment_id=(
            SELECT appointment_id
            FROM ipd_admissions
            WHERE id=?
            LIMIT 1
        )
        AND ps.patient_type='IPD'
        AND ps.status IN('Active','Cancelled')
        ORDER BY ps.sale_date ASC,ps.id ASC
    ");

    if(!$pharmacySaleStmt){
        throw new Exception('Pharmacy sale query failed: '.$conn->error);
    }

    $pharmacySaleStmt->bind_param(
        "ii",
        $patientId,
        $ipdAdmissionId
    );

    $pharmacySaleStmt->execute();

    $pharmacySaleResult=$pharmacySaleStmt->get_result();

    while($sale=$pharmacySaleResult->fetch_assoc()){

        $grandTotal=(float)$sale['grand_total'];

        if($grandTotal<=0){
            continue;
        }

        $saleDateTime=new DateTime($sale['sale_date']);

        if($sale['status']==='Cancelled'){

            $entryType='Pharmacy Sale Reversal';

            $debit=0;
            $credit=$grandTotal;

            $description=
                'Pharmacy Sale Cancelled - '.
                $sale['sale_no'];

        }else{

            $entryType='Pharmacy Sale';

            $debit=$grandTotal;
            $credit=0;

            $description=
                'Pharmacy Sale - '.
                $sale['sale_no'];
        }

        $calculationDetails=
            'Pharmacy Invoice '.
            $sale['sale_no'].
            ' | Grand Total ₹'.
            number_format($grandTotal,2);

        if(!empty($sale['remarks'])){
            $calculationDetails.=
                ' | Remarks: '.
                $sale['remarks'];
        }

        upsertLedgerEntry(
            $conn,
            $ipdAdmissionId,
            $patientId,
            $saleDateTime->format('Y-m-d'),
            $saleDateTime->format('H:i:s'),
            $entryType,
            (int)$sale['id'],
            $sale['sale_no'],
            $description,
            $calculationDetails,
            $debit,
            $credit,
            'pharmacy_sales',
            (int)$sale['id'],
            (int)$sale['created_by']
        );

        $generatedCount++;
    }

    $pharmacySaleStmt->close();

    /*
     * -----------------------------------------------------
     * 6. PHARMACY RECEIPTS
     * -----------------------------------------------------
     */

    $pharmacyReceiptStmt=$conn->prepare("
        SELECT
            pr.id,
            pr.receipt_no,
            pr.sale_id,
            pr.invoice_id,
            pr.receipt_date,
            pr.payment_mode,
            pr.received_amount,
            pr.balance_amount,
            pr.payment_status,
            pr.cash_remarks,
            pr.upi_app,
            pr.upi_txn_id,
            pr.card_type,
            pr.card_bank_name,
            pr.card_last4,
            pr.card_auth_code,
            pr.cheque_no,
            pr.cheque_bank,
            pr.cheque_date,
            pr.neft_ref,
            pr.neft_bank,
            pr.ecs_bank_name,
            pr.ecs_reference_no,
            pr.created_by,
            pr.created_at,
            u.full_name AS receiver_name
        FROM pharmacy_receipts pr
        INNER JOIN pharmacy_sales ps
            ON ps.id=pr.sale_id
        LEFT JOIN users u
            ON u.id=pr.created_by
        WHERE ps.patient_id=?
        AND ps.appointment_id=(
            SELECT appointment_id
            FROM ipd_admissions
            WHERE id=?
            LIMIT 1
        )
        AND ps.patient_type='IPD'
        AND pr.received_amount>0
        ORDER BY pr.receipt_date ASC,pr.id ASC
    ");

    if(!$pharmacyReceiptStmt){
        throw new Exception('Pharmacy receipt query failed: '.$conn->error);
    }

    $pharmacyReceiptStmt->bind_param(
        "ii",
        $patientId,
        $ipdAdmissionId
    );

    $pharmacyReceiptStmt->execute();

    $pharmacyReceiptResult=$pharmacyReceiptStmt->get_result();

    while($receipt=$pharmacyReceiptResult->fetch_assoc()){

        $amount=(float)$receipt['received_amount'];

        if($amount<=0){
            continue;
        }

        $receiptDateTime=new DateTime($receipt['receipt_date']);

        $calculationDetails=
            'Pharmacy Receipt | Mode: '.
            $receipt['payment_mode'].
            ' | Received ₹'.
            number_format($amount,2);

        $transactionNo='';

        if(!empty($receipt['upi_txn_id'])){
            $transactionNo=$receipt['upi_txn_id'];
        }elseif(!empty($receipt['card_auth_code'])){
            $transactionNo=$receipt['card_auth_code'];
        }elseif(!empty($receipt['cheque_no'])){
            $transactionNo=$receipt['cheque_no'];
        }elseif(!empty($receipt['neft_ref'])){
            $transactionNo=$receipt['neft_ref'];
        }elseif(!empty($receipt['ecs_reference_no'])){
            $transactionNo=$receipt['ecs_reference_no'];
        }

        if($transactionNo!==''){
            $calculationDetails.=
                ' | Transaction: '.
                $transactionNo;
        }

        if(!empty($receipt['cash_remarks'])){
            $calculationDetails.=
                ' | Remarks: '.
                $receipt['cash_remarks'];
        }

        upsertLedgerEntry(
            $conn,
            $ipdAdmissionId,
            $patientId,
            $receiptDateTime->format('Y-m-d'),
            $receiptDateTime->format('H:i:s'),
            'Pharmacy Receipt',
            (int)$receipt['id'],
            $receipt['receipt_no'],
            'Pharmacy Receipt - '.$receipt['receipt_no'],
            $calculationDetails,
            0,
            $amount,
            'pharmacy_receipts',
            (int)$receipt['id'],
            (int)$receipt['created_by']
        );

        $generatedCount++;
    }

    $pharmacyReceiptStmt->close();
/*
 * -----------------------------------------------------
 * 7. OPD CONSULTATION FEE / PAYMENT
 * -----------------------------------------------------
 */

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
        payment_mode,
        created_by,
        created_at
    FROM appointments
    WHERE id=?
    LIMIT 1
");

if(!$opdStmt){
    throw new Exception('OPD appointment query failed: '.$conn->error);
}

$appointmentId=(int)$admission['appointment_id'];

if($appointmentId>0){

    $opdStmt->bind_param("i",$appointmentId);
    $opdStmt->execute();

    $opdResult=$opdStmt->get_result();
    $opd=$opdResult->fetch_assoc();

    if($opd){

        $opdFee=(float)$opd['net_payable'];

        if($opdFee<=0){
            $opdFee=(float)$opd['consultation_fee'];
        }

        $opdPaid=(float)$opd['paid_amount'];

        $createdAt=
            !empty($opd['created_at'])
            ?new DateTime($opd['created_at'])
            :new DateTime();

        $entryDate=
            !empty($opd['appointment_date'])
            ?$opd['appointment_date']
            :$createdAt->format('Y-m-d');

        $entryTime=
            !empty($opd['appointment_time'])
            ?$opd['appointment_time']
            :$createdAt->format('H:i:s');

        $referenceNo=
            !empty($opd['opd_no'])
            ?$opd['opd_no']
            :(
                !empty($opd['appointment_code'])
                ?$opd['appointment_code']
                :'OPD-'.$appointmentId
            );

        if($opdFee>0){

            $calculationDetails=
                'OPD Consultation Fee ₹'.
                number_format($opdFee,2).
                ' | Paid ₹'.
                number_format($opdPaid,2).
                ' | Mode: '.
                ($opd['payment_mode'] ?: '-');

            upsertLedgerEntry(
                $conn,
                $ipdAdmissionId,
                $patientId,
                $entryDate,
                $entryTime,
                'OPD Consultation Fee',
                $appointmentId,
                $referenceNo,
                'OPD Consultation Fee',
                $calculationDetails,
                $opdFee,
                $opdPaid,
                'appointments',
                $appointmentId,
                (int)$opd['created_by']
            );

            $generatedCount++;
        }
    }
}

$opdStmt->close();
    /*
     * -----------------------------------------------------
     * 8. RECALCULATE RUNNING BALANCE
     * -----------------------------------------------------
     *
     * Debit  = Patient owes hospital
     * Credit = Patient has paid / advance
     *
     * Balance = Previous Balance + Debit - Credit
     */

    $ledgerStmt=$conn->prepare("
        SELECT
            id,
            debit_amount,
            credit_amount
        FROM ipd_ledger
        WHERE ipd_admission_id=?
        AND is_reversal=0
        ORDER BY
            entry_date ASC,
            entry_time ASC,
            id ASC
        FOR UPDATE
    ");

    if(!$ledgerStmt){
        throw new Exception('Ledger balance query failed: '.$conn->error);
    }

    $ledgerStmt->bind_param("i",$ipdAdmissionId);
    $ledgerStmt->execute();

    $ledgerResult=$ledgerStmt->get_result();

    $runningBalance=0;

    while($ledgerRow=$ledgerResult->fetch_assoc()){

        $runningBalance+=
            (float)$ledgerRow['debit_amount']-
            (float)$ledgerRow['credit_amount'];

        $updateBalance=$conn->prepare("
            UPDATE ipd_ledger
            SET balance_amount=?
            WHERE id=?
        ");

        if(!$updateBalance){
            throw new Exception('Balance update failed: '.$conn->error);
        }

        $ledgerId=(int)$ledgerRow['id'];

        $updateBalance->bind_param(
            "di",
            $runningBalance,
            $ledgerId
        );

        if(!$updateBalance->execute()){
            throw new Exception(
                'Balance update failed: '.
                $updateBalance->error
            );
        }

        $updateBalance->close();
    }

    $ledgerStmt->close();

    $conn->commit();

    /*
     * -----------------------------------------------------
     * 8. LOAD FINAL LEDGER
     * -----------------------------------------------------
     */

    $finalStmt=$conn->prepare("
        SELECT
            l.*,
            u.full_name AS user_name
        FROM ipd_ledger l
        LEFT JOIN users u
            ON u.id=l.created_by
        WHERE l.ipd_admission_id=?
        ORDER BY
            l.entry_date ASC,
            l.entry_time ASC,
            l.id ASC
    ");

    if(!$finalStmt){
        throw new Exception('Final ledger query failed: '.$conn->error);
    }

    $finalStmt->bind_param("i",$ipdAdmissionId);
    $finalStmt->execute();

    $finalResult=$finalStmt->get_result();

    $ledgerRows=[];

    while($ledgerRow=$finalResult->fetch_assoc()){
        $ledgerRows[]=$ledgerRow;
    }

    $finalStmt->close();

    $finalBalance=0;

    if(!empty($ledgerRows)){
        $lastRow=end($ledgerRows);
        $finalBalance=(float)$lastRow['balance_amount'];
        reset($ledgerRows);
    }

}catch(Throwable $e){

    try{
        $conn->rollback();
    }catch(Throwable $rollbackError){
    }

    die(
        '<div style="font-family:Arial;padding:20px;color:#b91c1c;">'.
        '<h3>Ledger Generation Failed</h3>'.
        '<div>'.e($e->getMessage()).'</div>'.
        '</div>'
    );
}

$balanceLabel='Pending';

if($finalBalance<0){
    $balanceLabel='Advance Available';
}elseif(abs($finalBalance)<0.005){
    $balanceLabel='Settled';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IPD Ledger - <?= e($admission['ipd_no']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>
body{
margin:0;
background:#f8fafc;
font-family:Arial,sans-serif;
color:#1e293b;
}
.ledger-container{
padding:20px;
}
.ledger-header{
background:#fff;
border-radius:10px;
padding:18px;
margin-bottom:15px;
box-shadow:0 4px 15px rgba(0,0,0,.07);
}
.ledger-header h2{
margin:0 0 8px;
}
.patient-info{
display:flex;
gap:25px;
flex-wrap:wrap;
font-size:13px;
color:#475569;
}
.final-box{
margin-top:15px;
padding:15px;
border-radius:8px;
background:#f1f5f9;
display:flex;
justify-content:space-between;
align-items:center;
gap:15px;
}
.final-label{
font-size:13px;
color:#64748b;
}
.final-value{
font-size:25px;
font-weight:bold;
}
.final-pending{
color:#dc3545;
}
.final-advance{
color:#b45309;
}
.final-settled{
color:#198754;
}
.table-wrap{
width:100%;
overflow:auto;
background:#fff;
border-radius:10px;
box-shadow:0 4px 15px rgba(0,0,0,.07);
}
table{
width:100%;
min-width:1500px;
border-collapse:collapse;
font-size:12px;
}
th,
td{
padding:9px 10px;
border:1px solid #e5e7eb;
text-align:left;
vertical-align:top;
}
th{
background:#0b5ed7;
color:#fff;
position:sticky;
top:0;
z-index:2;
}
.text-right{
text-align:right;
}
.debit{
color:#dc3545;
font-weight:bold;
}
.credit{
color:#198754;
font-weight:bold;
}
.balance{
font-weight:bold;
}
.system{
color:#64748b;
}
.small{
font-size:11px;
color:#64748b;
}
.calculation{
white-space:normal;
min-width:280px;
}
.toolbar{
display:flex;
gap:8px;
margin-bottom:15px;
}
.btn{
display:inline-block;
padding:8px 14px;
border:none;
border-radius:5px;
cursor:pointer;
text-decoration:none;
font-size:13px;
}
.btn-primary{
background:#0b5ed7;
color:#fff;
}
.btn-secondary{
background:#6b7280;
color:#fff;
}
@media(max-width:700px){
.ledger-container{
padding:10px;
}
.final-box{
display:block;
}
}
</style>
</head>
<body>

<div class="ledger-container">

<div class="toolbar">
<button
type="button"
class="btn btn-primary"
onclick="window.location.reload()">
↻ Refresh Ledger
</button>

<button
type="button"
class="btn btn-secondary"
onclick="window.print()">
Print
</button>
</div>

<div class="ledger-header">

<h2>IPD Patient Ledger</h2>

<div class="patient-info">

<div>
<b>IPD No.:</b>
<?= e($admission['ipd_no']) ?>
</div>

<div>
<b>UHID:</b>
<?= e($admission['uhid'] ?: '-') ?>
</div>

<div>
<b>Patient:</b>
<?= e($admission['patient_name']) ?>
</div>

</div>

<div class="final-box">

<div>
<div class="final-label">Final Balance</div>

<div class="final-value
<?= $finalBalance>0
    ?'final-pending'
    :($finalBalance<0
        ?'final-advance'
        :'final-settled') ?>">

₹<?= number_format(abs($finalBalance),2) ?>

</div>
</div>

<div>

<b>
<?php if($finalBalance>0){ ?>
₹<?= number_format($finalBalance,2) ?> Pending
<?php }elseif($finalBalance<0){ ?>
₹<?= number_format(abs($finalBalance),2) ?> Advance Available
<?php }else{ ?>
Settled
<?php } ?>
</b>

</div>

</div>

</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>#</th>
<th>Date</th>
<th>Time</th>
<th>Particular</th>
<th>Bill / Receipt No.</th>
<th>By / Receiver</th>
<th>Payment Mode</th>
<th>Transaction No.</th>
<th>Calculation / Details</th>
<th class="text-right">Debit</th>
<th class="text-right">Credit</th>
<th class="text-right">Balance</th>
</tr>
</thead>

<tbody>

<?php if(empty($ledgerRows)){ ?>

<tr>
<td colspan="12" style="text-align:center;padding:30px;">
No ledger entries found.
</td>
</tr>

<?php } ?>

<?php foreach($ledgerRows as $index=>$row){ ?>

<?php
$receiverName=
    !empty($row['user_name'])
    ?$row['user_name']
    :'System';

$paymentMode='-';
$transactionNo='-';

if($row['source_table']==='ipd_payments'){

    $paymentInfoStmt=$conn->prepare("
        SELECT
            payment_mode,
            transaction_no
        FROM ipd_payments
        WHERE id=?
        LIMIT 1
    ");

    if($paymentInfoStmt){

        $sourceId=(int)$row['source_id'];

        $paymentInfoStmt->bind_param(
            "i",
            $sourceId
        );

        $paymentInfoStmt->execute();

        $paymentInfoResult=$paymentInfoStmt->get_result();
        $paymentInfo=$paymentInfoResult->fetch_assoc();

        if($paymentInfo){
            $paymentMode=$paymentInfo['payment_mode'] ?: '-';
            $transactionNo=$paymentInfo['transaction_no'] ?: '-';
        }

        $paymentInfoStmt->close();
    }

}elseif($row['source_table']==='pharmacy_receipts'){

    $receiptInfoStmt=$conn->prepare("
        SELECT
            payment_mode,
            upi_txn_id,
            card_auth_code,
            cheque_no,
            neft_ref,
            ecs_reference_no
        FROM pharmacy_receipts
        WHERE id=?
        LIMIT 1
    ");

    if($receiptInfoStmt){

        $sourceId=(int)$row['source_id'];

        $receiptInfoStmt->bind_param(
            "i",
            $sourceId
        );

        $receiptInfoStmt->execute();

        $receiptInfoResult=$receiptInfoStmt->get_result();
        $receiptInfo=$receiptInfoResult->fetch_assoc();

        if($receiptInfo){

            $paymentMode=
                $receiptInfo['payment_mode']
                ?: '-';

            $transactionNo=
                $receiptInfo['upi_txn_id']
                ?:(
                    $receiptInfo['card_auth_code']
                    ?:(
                        $receiptInfo['cheque_no']
                        ?:(
                            $receiptInfo['neft_ref']
                            ?:(
                                $receiptInfo['ecs_reference_no']
                                ?: '-'
                            )
                        )
                    )
                );
        }

               $receiptInfoStmt->close();
    }

}elseif($row['source_table']==='appointments'){

    $opdInfoStmt=$conn->prepare("
        SELECT
            payment_mode
        FROM appointments
        WHERE id=?
        LIMIT 1
    ");

    if($opdInfoStmt){

        $sourceId=(int)$row['source_id'];

        $opdInfoStmt->bind_param(
            "i",
            $sourceId
        );

        $opdInfoStmt->execute();

        $opdInfoResult=$opdInfoStmt->get_result();
        $opdInfo=$opdInfoResult->fetch_assoc();

        if($opdInfo){
            $paymentMode=$opdInfo['payment_mode'] ?: '-';
        }

        $opdInfoStmt->close();
    }
}
?>

<tr>

<td><?= $index+1 ?></td>

<td><?= e($row['entry_date']) ?></td>

<td><?= e(date('h:i A',strtotime($row['entry_time']))) ?></td>

<td>
<b><?= e($row['description'] ?: $row['entry_type']) ?></b>

<?php if($row['entry_type']!==$row['description'] && !empty($row['entry_type'])){ ?>

<div class="small">
<?= e($row['entry_type']) ?>
</div>

<?php } ?>

</td>

<td>
<?= e($row['reference_no'] ?: '-') ?>
</td>

<td class="<?= $receiverName==='System'?'system':'' ?>">
<?= e($receiverName) ?>
</td>

<td><?= e($paymentMode) ?></td>

<td><?= e($transactionNo) ?></td>

<td class="calculation">
<?= e($row['calculation_details'] ?: '-') ?>
</td>

<td class="text-right debit">

<?php if((float)$row['debit_amount']>0){ ?>

₹<?= number_format((float)$row['debit_amount'],2) ?>

<?php }else{ ?>
-
<?php } ?>

</td>

<td class="text-right credit">

<?php if((float)$row['credit_amount']>0){ ?>

₹<?= number_format((float)$row['credit_amount'],2) ?>

<?php }else{ ?>
-
<?php } ?>

</td>

<td class="text-right balance">

₹<?= number_format((float)$row['balance_amount'],2) ?>

</td>

</tr>

<?php } ?>

</tbody>

<?php if(!empty($ledgerRows)){ ?>

<tfoot>

<tr>

<th colspan="9" style="text-align:right;">
FINAL BALANCE
</th>

<th class="text-right">
₹<?= number_format(
    array_sum(
        array_column($ledgerRows,'debit_amount')
    ),
    2
) ?>
</th>

<th class="text-right">
₹<?= number_format(
    array_sum(
        array_column($ledgerRows,'credit_amount')
    ),
    2
) ?>
</th>

<th class="text-right">
₹<?= number_format($finalBalance,2) ?>
</th>

</tr>

</tfoot>

<?php } ?>

</table>

</div>

</div>

</body>
</html>