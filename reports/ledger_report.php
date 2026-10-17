<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');

if(!isset($_SESSION['user_id'])){
    header("Location:/login/login.php");
    exit;
}

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

function e($value){
    return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}

function validDate($value,$default){
    return preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)?$value:$default;
}

function money($value){
    return (float)($value ?? 0);
}

/* ===============================
   FILTERS
================================ */

$from=validDate($_GET['from'] ?? date('Y-m-d'),date('Y-m-d'));
$to=validDate($_GET['to'] ?? date('Y-m-d'),date('Y-m-d'));

if($from>$to){
    $tmp=$from;
    $from=$to;
    $to=$tmp;
}

$type=strtoupper(trim($_GET['type'] ?? 'ALL'));

if(!in_array($type,['ALL','OPD','IPD'],true)){
    $type='ALL';
}

$user_id=(int)($_GET['user_id'] ?? 0);
$payment_mode=trim($_GET['payment_mode'] ?? '');
$search=trim($_GET['search'] ?? '');

/* ===============================
   USER LIST
================================ */

$users=[];

$userStmt=$conn->prepare("
    SELECT id,full_name
    FROM users
    WHERE full_name IS NOT NULL
    AND full_name<>''
    ORDER BY full_name ASC
");

if($userStmt){
    $userStmt->execute();
    $userResult=$userStmt->get_result();

    while($userRow=$userResult->fetch_assoc()){
        $users[]=$userRow;
    }

    $userStmt->close();
}

/* ===============================
   PAYMENT MODE LIST
================================ */

$paymentModes=[];

$modeSql="
    SELECT payment_mode COLLATE utf8mb4_unicode_ci AS payment_mode
    FROM appointments
    WHERE payment_mode IS NOT NULL
    AND payment_mode<>''
    UNION
    SELECT payment_mode COLLATE utf8mb4_unicode_ci AS payment_mode
    FROM ipd_payments
    WHERE payment_mode IS NOT NULL
    AND payment_mode<>''
    UNION
    SELECT payment_mode COLLATE utf8mb4_unicode_ci AS payment_mode
    FROM opd_bill_payments
    WHERE payment_mode IS NOT NULL
    AND payment_mode<>''
    ORDER BY payment_mode ASC
";

$modeResult=mysqli_query($conn,$modeSql);

if($modeResult){
    while($modeRow=mysqli_fetch_assoc($modeResult)){
        $paymentModes[]=$modeRow['payment_mode'];
    }
}

/* ===============================
   COMMON ROW STRUCTURE
================================ */

$ledgerRows=[];

/*
 * Used only for KPI calculations.
 * This prevents a bill's Payable/Discount/Receivable
 * from being counted multiple times when it has
 * multiple receipts.
 */
$opdBillKpiSeen=[];
$ipdChargeKpiSeen=[];

/* ===============================
   OPD BILL PAYMENTS
================================ */

if($type==='ALL' || $type==='OPD'){

    $conditions=[];
    $params=[];
    $paramTypes='';

    $conditions[]="
        pay.payment_date BETWEEN CONCAT(?,' 00:00:00')
        AND CONCAT(?,' 23:59:59')
    ";

    $params[]=$from;
    $params[]=$to;
    $paramTypes.='ss';

    $conditions[]="
        pay.status<>'Cancelled'
    ";

    $conditions[]="
        b.status<>'Cancelled'
    ";

    if($user_id>0){
        $conditions[]="pay.received_by=?";
        $params[]=$user_id;
        $paramTypes.='i';
    }

    if($payment_mode!==''){
        $conditions[]="pay.payment_mode=?";
        $params[]=$payment_mode;
        $paramTypes.='s';
    }

    if($search!==''){
        $like='%'.$search.'%';

        $conditions[]="
            (
                b.bill_no LIKE ?
                OR a.opd_no LIKE ?
                OR a.appointment_code LIKE ?
                OR p.uhid LIKE ?
                OR p.mobile LIKE ?
                OR CONCAT_WS(' ',p.title,p.first_name,p.last_name) LIKE ?
            )
        ";

        for($i=0;$i<6;$i++){
            $params[]=$like;
        }

        $paramTypes.='ssssss';
    }

    $where=implode(' AND ',$conditions);

    $sql="
        SELECT
            pay.id AS payment_id,
            pay.receipt_no,
            pay.payment_date,
            pay.transaction_type,
            pay.payment_mode,
            pay.amount,
            pay.balance_after_payment,
            pay.reference_no,
            pay.transaction_id,
            pay.received_by,

            b.id AS bill_id,
            b.bill_no,
            b.grand_total,
            b.discount_amount,
            b.net_payable,
            b.paid_amount AS bill_paid_amount,
            b.balance_amount AS bill_balance_amount,

            a.id AS appointment_id,
            a.opd_no,
            a.appointment_code,
            a.ipd_no AS appointment_ipd_no,

            p.uhid,
            CONCAT_WS(
                ' ',
                p.title,
                p.first_name,
                p.last_name
            ) AS patient_name,

            u.full_name AS receiver_name

        FROM opd_bill_payments pay

        INNER JOIN opd_bills b
            ON b.id=pay.bill_id

        LEFT JOIN appointments a
            ON a.id=b.opd_id

        LEFT JOIN patient_master p
            ON p.id=b.patient_id

        LEFT JOIN users u
            ON u.id=pay.received_by

        WHERE $where

        ORDER BY pay.id ASC
    ";

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        die('OPD payment query failed: '.$conn->error);
    }

    if(!empty($params)){
        $stmt->bind_param($paramTypes,...$params);
    }

    $stmt->execute();

    $result=$stmt->get_result();

    while($r=$result->fetch_assoc()){

        $isRefund=(
            strtoupper((string)$r['transaction_type'])==='REFUND'
        );

        $row=[
            'entry_date'=>date('Y-m-d',strtotime($r['payment_date'])),
            'entry_time'=>date('H:i:s',strtotime($r['payment_date'])),
            'ledger_type'=>'OPD',
            'patient_name'=>$r['patient_name'],
            'uhid'=>$r['uhid'],
            'ipd_no'=>$r['appointment_ipd_no'],
            'description'=>$isRefund
                ?'OPD Refund - '.$r['bill_no']
                :'OPD Receipt - '.$r['bill_no'],
            'reference_no'=>$isRefund
                ?'-'
                :$r['receipt_no'],
            'receiver_name'=>$r['receiver_name'],
            'resolved_payment_mode'=>$r['payment_mode'],
            'transaction_no'=>$r['transaction_id']
                ?:($r['reference_no'] ?: '-'),
            'calculation_details'=>'',
            'payable_amount'=>0,
            'discount_amount'=>0,
            'receivable_amount'=>0,
            'received_amount'=>0,
            'refund_amount'=>0,
            'pending_amount'=>0,
            'balance_amount'=>0,
            'source_table'=>'opd_bill_payments',
            'source_id'=>$r['payment_id'],
            'kpi_bill_id'=>(int)$r['bill_id']
        ];

        if($isRefund){

            /*
             * Refund is always a separate row.
             * It must NOT reduce the historical Received value.
             */
            $row['refund_amount']=money($r['amount']);

            $row['calculation_details']=
    'Refund against bill '.
    $r['bill_no'].
    ' | Mode: '.
    ($r['payment_mode'] ?: '-');

        }else{

    $billId=(int)$r['bill_id'];

    /*
     * Actual amount received in THIS payment.
     */
    $row['received_amount']=money($r['amount']);

    /*
     * Pending after THIS payment.
     */
    $row['pending_amount']=money($r['balance_after_payment']);

    /*
     * Show Payable, Discount and Receivable only
     * on the first payment row of this bill.
     */
    if(!isset($opdBillKpiSeen[$billId])){

        $opdBillKpiSeen[$billId]=true;

        $row['payable_amount']=money($r['grand_total']);
        $row['discount_amount']=money($r['discount_amount']);
        $row['receivable_amount']=money($r['net_payable']);

        $row['calculation_details']=
            'OPD Receipt';

        /*
         * KPI counts the bill only once.
         */
        $row['kpi_payable_amount']=$row['payable_amount'];
        $row['kpi_discount_amount']=$row['discount_amount'];
        $row['kpi_receivable_amount']=$row['receivable_amount'];

    }else{

        /*
         * Subsequent payments show ONLY the actual
         * payment amount.
         */
        $row['payable_amount']=0;
        $row['discount_amount']=0;
        $row['receivable_amount']=0;

        $row['calculation_details']=
            'OPD Receipt';

        /*
         * Do not count billing figures again in KPI.
         */
        $row['kpi_payable_amount']=0;
        $row['kpi_discount_amount']=0;
        $row['kpi_receivable_amount']=0;
    }
}
        $ledgerRows[]=$row;
    }

    $stmt->close();
}

/* ===============================
   OPD APPOINTMENT PAYMENTS
================================ */
if($type==='ALL' || $type==='OPD'){
    $conditions=[];
    $params=[];
    $paramTypes='';
    $conditions[]="DATE(a.created_at) BETWEEN ? AND ?";
    $params[]=$from;
    $params[]=$to;
    $paramTypes.='ss';
    $conditions[]="a.paid_amount>0";
    if($user_id>0){
        $conditions[]="a.created_by=?";
        $params[]=$user_id;
        $paramTypes.='i';
    }
    if($payment_mode!==''){
        $conditions[]="a.payment_mode=?";
        $params[]=$payment_mode;
        $paramTypes.='s';
    }
    if($search!==''){
        $like='%'.$search.'%';
        $conditions[]="(
            a.opd_no LIKE ?
            OR a.appointment_code LIKE ?
            OR p.uhid LIKE ?
            OR CONCAT_WS(
                ' ',
                p.title,
                p.first_name,
                p.last_name
            ) LIKE ?
        )";
        for($i=0;$i<4;$i++){
            $params[]=$like;
        }
        $paramTypes.='ssss';
    }
    $where=implode(' AND ',$conditions);
    $sql="
        SELECT
            a.id,
            a.patient_id,
            a.appointment_date,
            a.created_at,
            a.appointment_code,
            a.opd_no,
            a.ipd_no,
            a.consultation_fee,
            a.discount_amount,
            a.net_payable,
            a.paid_amount,
            a.balance_amount,
            a.payment_mode,
            a.payment_txnid,
            a.created_by,
            p.uhid AS patient_uhid,
            CONCAT_WS(
                ' ',
                p.title,
                p.first_name,
                p.last_name
            ) AS master_patient_name,
            CONCAT_WS(
                ' ',
                a.title,
                a.first_name,
                a.last_name
            ) AS appointment_patient_name,
            u.full_name AS receiver_name
        FROM appointments a
        LEFT JOIN patient_master p
            ON p.id=a.patient_id
        LEFT JOIN users u
            ON u.id=a.created_by
        WHERE $where
        ORDER BY a.created_at ASC,a.id ASC
    ";
    $stmt=$conn->prepare($sql);
    if(!$stmt){
        die('OPD appointment query failed: '.$conn->error);
    }
    if(!empty($params)){
        $stmt->bind_param($paramTypes,...$params);
    }
    $stmt->execute();
    $result=$stmt->get_result();
    while($r=$result->fetch_assoc()){
        $payable=money($r['consultation_fee']);
        $discount=money($r['discount_amount']);
        $receivable=money($r['net_payable']);
        if($receivable<=0 && $payable>0){
            $receivable=max(0,$payable-$discount);
        }
        $received=money($r['paid_amount']);
        $pending=max(0,$receivable-$received);
        $ledgerRows[]=[
            'entry_date'=>date('Y-m-d',strtotime($r['created_at'])),
            'entry_time'=>date('H:i:s',strtotime($r['created_at'])),
            'ledger_type'=>'OPD',
            'patient_name'=>trim($r['master_patient_name'] ?: $r['appointment_patient_name'] ?: 'Unknown Patient'),
            'uhid'=>$r['patient_uhid'],
            'ipd_no'=>$r['ipd_no'],
            'description'=>'OPD Consultation - '.$r['appointment_code'],
            'reference_no'=>'-',
            'receiver_name'=>$r['receiver_name'],
            'resolved_payment_mode'=>$r['payment_mode'] ?: '-',
            'transaction_no'=>$r['payment_txnid'] ?: '-',
            'calculation_details'=>'OPD Consultation Fee',
            'payable_amount'=>$payable,
            'discount_amount'=>$discount,
            'receivable_amount'=>$receivable,
            'received_amount'=>$received,
            'refund_amount'=>0,
            'pending_amount'=>$pending,
            'balance_amount'=>0,
            'source_table'=>'appointments',
            'source_id'=>(int)$r['id'],
            'kpi_payable_amount'=>$payable,
            'kpi_discount_amount'=>$discount,
            'kpi_receivable_amount'=>$receivable
        ];
    }
    $stmt->close();
}
/* ===============================
   OPD APPOINTMENT REFUNDS
================================ */

if($type==='ALL' || $type==='OPD'){

    $conditions=[];
    $params=[];
    $paramTypes='';

    $conditions[]="
        a.refund_datetime BETWEEN CONCAT(?,' 00:00:00')
        AND CONCAT(?,' 23:59:59')
    ";

    $params[]=$from;
    $params[]=$to;
    $paramTypes.='ss';

    $conditions[]="
        a.refund_amount>0
    ";

    if($user_id>0){
        $conditions[]="a.refund_by=?";
        $params[]=$user_id;
        $paramTypes.='i';
    }

    if($payment_mode!==''){
        $conditions[]="a.refund_mode=?";
        $params[]=$payment_mode;
        $paramTypes.='s';
    }

    if($search!==''){
        $like='%'.$search.'%';

        $conditions[]="
            (
                a.opd_no LIKE ?
                OR a.appointment_code LIKE ?
                OR a.uhid LIKE ?
                OR a.mobile LIKE ?
                OR CONCAT_WS(
                    ' ',
                    a.first_name,
                    a.last_name
                ) LIKE ?
            )
        ";

        for($i=0;$i<5;$i++){
            $params[]=$like;
        }

        $paramTypes.='sssss';
    }

    $where=implode(' AND ',$conditions);

    $sql="
        SELECT
            a.id,
            a.status,
            a.refund_datetime,
            a.refund_amount,
            a.refund_mode,
            a.payment_txnid,
            a.opd_no,
            a.appointment_code,
            a.ipd_no,
            a.patient_id,

            p.uhid,

            CONCAT_WS(
                ' ',
                p.title,
                p.first_name,
                p.last_name
            ) AS patient_name,

            u.full_name AS receiver_name

        FROM appointments a

        LEFT JOIN patient_master p
            ON p.id=a.patient_id

        LEFT JOIN users u
            ON u.id=a.refund_by

        WHERE $where

        ORDER BY a.refund_datetime ASC,a.id ASC
    ";

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        die('OPD refund query failed: '.$conn->error);
    }

    if(!empty($params)){
        $stmt->bind_param($paramTypes,...$params);
    }

    $stmt->execute();

    $result=$stmt->get_result();

    while($r=$result->fetch_assoc()){

        $refund=money($r['refund_amount']);

        $ledgerRows[]=[
            'entry_date'=>date('Y-m-d',strtotime($r['refund_datetime'])),
            'entry_time'=>date('H:i:s',strtotime($r['refund_datetime'])),
            'ledger_type'=>'OPD',
            'transaction_status'=>$r['status'],
            'patient_name'=>$r['patient_name'],
            'uhid'=>$r['uhid'],
            'ipd_no'=>$r['ipd_no'],
            'description'=>($r['status']==='Cancel'?'OPD Cancel - ':'OPD Refund - ').$r['appointment_code'],
            'reference_no'=>'-',
            'receiver_name'=>$r['receiver_name'],
            'resolved_payment_mode'=>$r['refund_mode'],
            'transaction_no'=>$r['payment_txnid'] ?: '-',
            'calculation_details'=>'Separate refund transaction',
            'payable_amount'=>0,
            'discount_amount'=>0,
            'receivable_amount'=>0,
            'received_amount'=>0,
            'refund_amount'=>$refund,
            'pending_amount'=>0,
            'balance_amount'=>0,
            'source_table'=>'appointments_refund',
            'source_id'=>(int)$r['id'],
            'kpi_payable_amount'=>0,
            'kpi_discount_amount'=>0,
            'kpi_receivable_amount'=>0
        ];
    }

    $stmt->close();
}

/* ===============================
   IPD LEDGER CHARGES
================================ */

if($type==='ALL' || $type==='IPD'){

    $conditions=[];
    $params=[];
    $paramTypes='';

    $conditions[]="
        l.entry_date BETWEEN ? AND ?
    ";

    $params[]=$from;
    $params[]=$to;
    $paramTypes.='ss';

    $conditions[]="
        l.is_reversal=0
    ";

    /*
     * Only actual charge/debit rows.
     * Payment rows are taken from ipd_payments.
     */
    $conditions[]="
        l.debit_amount>0
    ";

    if($user_id>0){
        $conditions[]="l.created_by=?";
        $params[]=$user_id;
        $paramTypes.='i';
    }

    if($search!==''){
        $like='%'.$search.'%';

        $conditions[]="
            (
                l.reference_no LIKE ?
                OR l.description LIKE ?
                OR l.calculation_details LIKE ?
                OR p.uhid LIKE ?
                OR CONCAT_WS(
                    ' ',
                    p.title,
                    p.first_name,
                    p.last_name
                ) LIKE ?
                OR ia.ipd_no LIKE ?
            )
        ";

        for($i=0;$i<6;$i++){
            $params[]=$like;
        }

        $paramTypes.='ssssss';
    }

    $where=implode(' AND ',$conditions);

    $sql="
        SELECT
            l.id,
            l.ipd_admission_id,
            l.patient_id,
            l.entry_date,
            l.entry_time,
            l.entry_type,
            l.reference_id,
            l.reference_no,
            l.description,
            l.calculation_details,
            l.debit_amount,
            l.created_by,

            p.uhid,

            CONCAT_WS(
                ' ',
                p.title,
                p.first_name,
                p.last_name
            ) AS patient_name,

            ia.ipd_no,

            u.full_name AS receiver_name

        FROM ipd_ledger l

        LEFT JOIN patient_master p
            ON p.id=l.patient_id

        LEFT JOIN ipd_admissions ia
            ON ia.id=l.ipd_admission_id

        LEFT JOIN users u
            ON u.id=l.created_by

        WHERE $where

        ORDER BY l.entry_date ASC,l.entry_time ASC,l.id ASC
    ";

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        die('IPD ledger query failed: '.$conn->error);
    }

    if(!empty($params)){
        $stmt->bind_param($paramTypes,...$params);
    }

    $stmt->execute();

    $result=$stmt->get_result();

    while($r=$result->fetch_assoc()){

        $payable=money($r['debit_amount']);

        /*
         * ipd_ledger has no discount column.
         * Therefore IPD discount is zero here.
         */
        $discount=0;

        $receivable=$payable;

        $ledgerRows[]=[
            'entry_date'=>$r['entry_date'],
            'entry_time'=>$r['entry_time'],
            'ledger_type'=>'IPD',
            'patient_name'=>$r['patient_name'],
            'uhid'=>$r['uhid'],
            'ipd_no'=>$r['ipd_no'],
            'description'=>$r['description'] ?: $r['entry_type'],
            'reference_no'=>$r['reference_no'] ?: '-',
            'receiver_name'=>$r['receiver_name'],
            'resolved_payment_mode'=>'-',
            'transaction_no'=>'-',
            'calculation_details'=>
                ($r['calculation_details'] ?: 'IPD charge').
                ' | Payable ₹'.
                number_format($payable,2),
            'payable_amount'=>$payable,
            'discount_amount'=>$discount,
            'receivable_amount'=>$receivable,
            'received_amount'=>0,
            'refund_amount'=>0,
            'pending_amount'=>$receivable,
            'balance_amount'=>0,
            'source_table'=>'ipd_ledger',
            'source_id'=>(int)$r['id'],
            'ipd_admission_id'=>(int)$r['ipd_admission_id'],
            'kpi_payable_amount'=>$payable,
            'kpi_discount_amount'=>0,
            'kpi_receivable_amount'=>$receivable
        ];
    }

    $stmt->close();
}

/* ===============================
   IPD PAYMENTS
================================ */

if($type==='ALL' || $type==='IPD'){

    $conditions=[];
    $params=[];
    $paramTypes='';

    $conditions[]="
    pmt.payment_date BETWEEN CONCAT(?,' 00:00:00')
    AND CONCAT(?,' 23:59:59')
";

    $params[]=$from;
    $params[]=$to;
    $paramTypes.='ss';

    if($user_id>0){
        $conditions[]="pmt.created_by=?";
        $params[]=$user_id;
        $paramTypes.='i';
    }

    if($payment_mode!==''){
        $conditions[]="pmt.payment_mode=?";
        $params[]=$payment_mode;
        $paramTypes.='s';
    }

    if($search!==''){
        $like='%'.$search.'%';

        $conditions[]="
            (
                pmt.payment_no LIKE ?
                OR pmt.transaction_no LIKE ?
                OR pmt.ipd_no LIKE ?
                OR pm.uhid LIKE ?
                OR CONCAT_WS(
                    ' ',
                    pm.title,
                    pm.first_name,
                    pm.last_name
                ) LIKE ?
            )
        ";

        for($i=0;$i<5;$i++){
            $params[]=$like;
        }

        $paramTypes.='sssss';
    }

    $where=implode(' AND ',$conditions);

    $sql="
        SELECT
            pmt.id,
            pmt.ipd_admission_id,
            pmt.patient_id,
            pmt.ipd_no,
            pmt.payment_no,
            pmt.payment_date,
            pmt.created_at,
            pmt.payment_amount,
            pmt.payment_type,
            pmt.payment_mode,
            pmt.transaction_no,
            pmt.payment_details,
            pmt.created_by,

            pm.uhid,

            CONCAT_WS(
                ' ',
                pm.title,
                pm.first_name,
                pm.last_name
            ) AS patient_name,

            u.full_name AS receiver_name

        FROM ipd_payments pmt

        LEFT JOIN patient_master pm
            ON pm.id=pmt.patient_id

        LEFT JOIN users u
            ON u.id=pmt.created_by

        WHERE $where

        ORDER BY pmt.payment_date ASC,pmt.id ASC
    ";

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        die('IPD payment query failed: '.$conn->error);
    }

    if(!empty($params)){
        $stmt->bind_param($paramTypes,...$params);
    }

    $stmt->execute();

    $result=$stmt->get_result();

    while($r=$result->fetch_assoc()){

        $received=money($r['payment_amount']);

        $paymentDateTime=new DateTime($r['created_at']);

$ledgerRows[]=[
    'entry_date'=>$paymentDateTime->format('Y-m-d'),
    'entry_time'=>$paymentDateTime->format('H:i:s'),
    'ledger_type'=>'IPD',
            'patient_name'=>$r['patient_name'],
            'uhid'=>$r['uhid'],
            'ipd_no'=>$r['ipd_no'],
            'description'=>'IPD Payment - '.$r['payment_no'],
            'reference_no'=>$r['payment_no'],
            'receiver_name'=>$r['receiver_name'],
            'resolved_payment_mode'=>$r['payment_mode'],
            'transaction_no'=>$r['transaction_no'] ?: '-',
            'calculation_details'=>
                'Payment Type: '.
                ($r['payment_type'] ?: '-').
                ' | Received ₹'.
                number_format($received,2).
                ($r['payment_details']
                    ?' | '.$r['payment_details']
                    :''),
            'payable_amount'=>0,
            'discount_amount'=>0,
            'receivable_amount'=>0,
            'received_amount'=>$received,
            'refund_amount'=>0,
            'pending_amount'=>0,
            'balance_amount'=>0,
            'source_table'=>'ipd_payments',
            'source_id'=>(int)$r['id'],
            'ipd_admission_id'=>(int)$r['ipd_admission_id'],
            'kpi_payable_amount'=>0,
            'kpi_discount_amount'=>0,
            'kpi_receivable_amount'=>0
        ];
    }

    $stmt->close();
}

/* ===============================
   SORT ALL TRANSACTIONS
================================ */

usort(
    $ledgerRows,
    function($a,$b){

        $dateA=$a['entry_date'].' '.$a['entry_time'];
        $dateB=$b['entry_date'].' '.$b['entry_time'];

        if($dateA===$dateB){
            return ((int)$a['source_id'])<=>((int)$b['source_id']);
        }

        return strcmp($dateA,$dateB);
    }
);

/* ===============================
   RUNNING COLLECTION BALANCE
================================ */

$runningBalance=0;

$totalEntries=0;
$totalPayable=0;
$totalDiscount=0;
$totalReceivable=0;
$totalReceived=0;
$totalRefund=0;
$totalPending=0;
$totalPaymentEntries=0;

foreach($ledgerRows as &$row){

    $received=money($row['received_amount']);
    $refund=money($row['refund_amount']);

    /*
     * Overall collection balance:
     * Received increases cash.
     * Refund decreases cash.
     */
    $runningBalance+=$received;
    $runningBalance-=$refund;

    $row['balance_amount']=$runningBalance;

    /*
     * KPI amounts.
     * Billing figures are counted once per bill/appointment.
     */
    $totalPayable+=money(
        $row['kpi_payable_amount']??$row['payable_amount']
    );

    $totalDiscount+=money(
        $row['kpi_discount_amount']??$row['discount_amount']
    );

    $totalReceivable+=money(
        $row['kpi_receivable_amount']??$row['receivable_amount']
    );

    $totalReceived+=$received;
    $totalRefund+=$refund;

    $totalEntries++;

    if($received>0 || $refund>0){
        $totalPaymentEntries++;
    }
}

unset($row);

/*
 * Pending KPI should represent current outstanding
 * from the visible billing records, not cumulative row
 * duplicates.
 *
 * For OPD, the row already contains the current bill
 * pending after payment.
 */
$pendingBySource=[];

foreach($ledgerRows as $row){

    if(
        $row['source_table']==='opd_bill_payments'
        && money($row['refund_amount'])<=0
    ){

        $billId=(int)($row['kpi_bill_id']??0);

        if($billId>0){
            $pendingBySource['OPD-'.$billId]=money(
                $row['pending_amount']
            );
        }
    }

    if($row['source_table']==='appointments'){
        $key='APT-'.$row['source_id'];

        $pendingBySource[$key]=money(
            $row['pending_amount']
        );
    }
}

$totalPending=array_sum($pendingBySource);

/* ===============================
   DISPLAY DATE/TIME SAFETY
================================ */

foreach($ledgerRows as &$row){

    if(empty($row['entry_date'])){
        $row['entry_date']=date('Y-m-d');
    }

    if(empty($row['entry_time'])){
        $row['entry_time']='00:00:00';
    }
}

unset($row);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ledger Report</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
.report-container{
    padding:10px;
}
.report-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
    flex-wrap:wrap;
    gap:10px;
}
.report-header h2{
    margin:0;
}
.report-header p{
    margin:5px 0 0;
    color:#666;
    font-size:13px;
}
.filter-card{
    background:#fff;
    padding:15px;
    border-radius:8px;
    box-shadow:0 5px 15px rgba(0,0,0,.08);
    margin-bottom:20px;
}
.filter-grid{
    display:grid;
    grid-template-columns:repeat(6,minmax(130px,1fr));
    gap:12px;
    align-items:end;
}
.filter-field{
    display:flex;
    flex-direction:column;
    gap:5px;
}
.filter-field label{
    font-size:12px;
    font-weight:600;
    color:#555;
}
.filter-field input,
.filter-field select{
    width:100%;
    padding:9px 10px;
    border:1px solid #d6dbe1;
    border-radius:5px;
    background:#fff;
}
.filter-actions{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}
.btn{
    display:inline-block;
    border:none;
    border-radius:5px;
    padding:9px 15px;
    cursor:pointer;
    text-decoration:none;
    font-size:13px;
}
.btn-primary{
    background:#1976d2;
    color:#fff;
}
.btn-secondary{
    background:#6c757d;
    color:#fff;
}
.kpi-cards{
    display:flex;
    gap:15px;
    flex-wrap:wrap;
    margin-bottom:25px;
}
.card{
    flex:1;
    min-width:180px;
    background:#fff;
    padding:15px;
    border-radius:8px;
    box-shadow:0 5px 15px rgba(0,0,0,.08);
    text-align:center;
}
.card p{
    margin:0 0 6px;
    color:#555;
}
.card h3{
    margin:0;
    font-size:21px;
}
.kpi-total{
    background:#e3f2fd;
}
.kpi-payable{
    background:#fff3e0;
}
.kpi-discount{
    background:#f3e5f5;
}
.kpi-receivable{
    background:#e8f5e9;
}
.kpi-received{
    background:#e0f7fa;
}
.kpi-refund{
    background:#ffebee;
}
.kpi-pending{
    background:#fff8e1;
}
.kpi-balance{
    background:#e8eaf6;
}
.table-card{
    background:#fff;
    border-radius:8px;
    box-shadow:0 5px 15px rgba(0,0,0,.08);
    overflow:hidden;
}
.table-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:15px;
    gap:10px;
    flex-wrap:wrap;
}
.table-title h3{
    margin:0;
}
.table-wrap{
    overflow:auto;
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:12px;
    min-width:1900px;
}
th,
td{
    border:1px solid #ddd;
    padding:8px;
    vertical-align:top;
}
th{
    background:#1976d2;
    color:#fff;
    white-space:nowrap;
}
td{
    background:#fff;
}
.text-right{
    text-align:right;
}
.money-payable{
    color:#b45309;
    font-weight:700;
}
.money-discount{
    color:#7e22ce;
    font-weight:700;
}
.money-receivable{
    color:#166534;
    font-weight:700;
}
.money-received{
    color:#047857;
    font-weight:700;
}
.money-refund{
    color:#dc2626;
    font-weight:700;
}
.money-pending{
    color:#ca8a04;
    font-weight:700;
}
.balance{
    font-weight:700;
    color:#1e3a8a;
}
.badge{
    display:inline-block;
    padding:3px 8px;
    border-radius:12px;
    font-size:11px;
    font-weight:700;
}
.badge-opd{
    background:#e0f2fe;
    color:#0369a1;
}
.badge-ipd{
    background:#ede9fe;
    color:#6d28d9;
}
.badge-refund{
    background:#fee2e2;
    color:#b91c1c;
}
.empty{
    text-align:center;
    padding:30px;
    color:#777;
}
tfoot td{
    background:#f5f5f5;
    font-weight:700;
}
.report-note{
    margin-top:10px;
    color:#666;
    font-size:12px;
}
@media(max-width:1300px){
    .filter-grid{
        grid-template-columns:repeat(3,minmax(150px,1fr));
    }
}
@media(max-width:700px){
    .filter-grid{
        grid-template-columns:1fr;
    }
}
@media print{
    .filter-card,
    .actions,
    .report-header .back-button{
        display:none !important;
    }
    .report-container{
        padding:0;
    }
    .table-card,
    .card{
        box-shadow:none;
    }
    body{
        background:#fff;
    }
}
</style>
</head>
<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" style="width:auto;">
<div class="report-container">

<div class="report-header">
<div>
<h2>📒 Ledger Report</h2>
<p>
Collection and outstanding report for OPD and IPD
</p>
</div>
<div class="actions">
<button type="button" class="btn btn-secondary" onclick="window.print()">
Print
</button>
</div>
</div>

<!-- FILTERS -->

<div class="filter-card">
<form method="GET">
<div class="filter-grid">

<div class="filter-field">
<label>From Date</label>
<input type="date" name="from" value="<?= e($from) ?>">
</div>

<div class="filter-field">
<label>To Date</label>
<input type="date" name="to" value="<?= e($to) ?>">
</div>

<div class="filter-field">
<label>Type</label>
<select name="type">
<option value="ALL" <?= $type==='ALL'?'selected':'' ?>>All</option>
<option value="OPD" <?= $type==='OPD'?'selected':'' ?>>OPD</option>
<option value="IPD" <?= $type==='IPD'?'selected':'' ?>>IPD</option>
</select>
</div>

<div class="filter-field">
<label>User / Receiver</label>
<select name="user_id">
<option value="0">All Users</option>
<?php foreach($users as $user){ ?>
<option value="<?= (int)$user['id'] ?>" <?= $user_id==(int)$user['id']?'selected':'' ?>>
<?= e($user['full_name']) ?>
</option>
<?php } ?>
</select>
</div>

<div class="filter-field">
<label>Payment Mode</label>
<select name="payment_mode">
<option value="">All Modes</option>
<?php foreach($paymentModes as $mode){ ?>
<option value="<?= e($mode) ?>" <?= $payment_mode===$mode?'selected':'' ?>>
<?= e($mode) ?>
</option>
<?php } ?>
</select>
</div>

<div class="filter-field">
<label>Search</label>
<input type="text" name="search" value="<?= e($search) ?>" placeholder="Patient / UHID / Receipt / IPD No.">
</div>

<div class="filter-actions">
<button type="submit" class="btn btn-primary">
Apply Filter
</button>
<a href="/reports/ledger_report.php" class="btn btn-secondary">
Reset
</a>
</div>

</div>
</form>
</div>

<!-- KPI -->

<div class="kpi-cards">

<div class="card kpi-total">
<p>Total Entries</p>
<h3><?= number_format($totalEntries) ?></h3>
</div>

<div class="card kpi-payable">
<p>Total Payable</p>
<h3>₹ <?= number_format($totalPayable,2) ?></h3>
</div>

<div class="card kpi-discount">
<p>Total Discount</p>
<h3>₹ <?= number_format($totalDiscount,2) ?></h3>
</div>

<div class="card kpi-receivable">
<p>Total Receivable</p>
<h3>₹ <?= number_format($totalReceivable,2) ?></h3>
</div>

<div class="card kpi-received">
<p>Total Received</p>
<h3>₹ <?= number_format($totalReceived,2) ?></h3>
</div>

<div class="card kpi-refund">
<p>Total Cancel / Refund</p>
<h3>₹ <?= number_format($totalRefund,2) ?></h3>
</div>

<div class="card kpi-pending">
<p>Total Pending</p>
<h3>₹ <?= number_format($totalPending,2) ?></h3>
</div>

<div class="card kpi-balance">
<p>Current Balance</p>
<h3>₹ <?= number_format($runningBalance,2) ?></h3>
</div>

</div>

<!-- REPORT -->

<div class="table-card">

<div class="table-title">

<div>
<h3>Complete Collection Ledger</h3>
<div class="report-note">
<?= e(date('d-m-Y',strtotime($from))) ?>
to
<?= e(date('d-m-Y',strtotime($to))) ?>
</div>
</div>

<div>
<strong>
Payment Entries:
<?= number_format($totalPaymentEntries) ?>
</strong>
</div>

</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Date</th>
<th>Time</th>
<th>Type</th>
<th>Patient</th>
<th>UHID</th>
<th>IPD No.</th>
<th>Particular / Bill</th>
<th>Receipt No.</th>
<th>By / Receiver</th>
<th>Payment Mode</th>
<th>Transaction No.</th>
<th>Calculation / Details</th>
<th>Payable</th>
<th>Discount</th>
<th>Receivable</th>
<th>Received</th>
<th>Cancel / Refund</th>
<th>Pending</th>
<th>Balance</th>
</tr>
</thead>

<tbody>

<?php if(empty($ledgerRows)){ ?>

<tr>
<td colspan="19" class="empty">
No collection entries found for the selected filters.
</td>
</tr>

<?php }else{ ?>

<?php foreach($ledgerRows as $row){ ?>

<?php
$isRefund=money($row['refund_amount'])>0;
?>

<tr>

<td>
<?= e(date('d-m-Y',strtotime($row['entry_date']))) ?>
</td>

<td>
<?= e(date('h:i A',strtotime($row['entry_time']))) ?>
</td>

<td>
<?php if($isRefund){ ?>

<?php if(($row['transaction_status'] ?? '')==='Cancel'){ ?>

<span class="badge badge-refund">
OPD CANCEL
</span>

<?php }else{ ?>

<span class="badge badge-refund">
OPD REFUND
</span>

<?php } ?>

<?php }elseif($row['ledger_type']==='OPD'){ ?>
<span class="badge badge-opd">OPD</span>

<?php }else{ ?>

<span class="badge badge-ipd">
IPD
</span>

<?php } ?>

</td>

<td>
<?= e($row['patient_name'] ?: '-') ?>
</td>

<td>
<?= e($row['uhid'] ?: '-') ?>
</td>

<td>
<?= e($row['ipd_no'] ?: '-') ?>
</td>

<td>
<strong>
<?= e($row['description'] ?: '-') ?>
</strong>
</td>

<td>
<?= e($row['reference_no'] ?: '-') ?>
</td>

<td>
<?= e($row['receiver_name'] ?: '-') ?>
</td>

<td>
<?= e($row['resolved_payment_mode'] ?: '-') ?>
</td>

<td>
<?= e($row['transaction_no'] ?: '-') ?>
</td>

<td>
<?= e($row['calculation_details'] ?: '-') ?>
</td>

<td class="text-right money-payable">
<?php if(money($row['payable_amount'])>0){ ?>
₹<?= number_format(money($row['payable_amount']),2) ?>
<?php }else{ ?>
-
<?php } ?>
</td>

<td class="text-right money-discount">
<?php if(money($row['discount_amount'])>0){ ?>
₹<?= number_format(money($row['discount_amount']),2) ?>
<?php }else{ ?>
-
<?php } ?>
</td>

<td class="text-right money-receivable">
<?php if(money($row['receivable_amount'])>0){ ?>
₹<?= number_format(money($row['receivable_amount']),2) ?>
<?php }else{ ?>
-
<?php } ?>
</td>

<td class="text-right money-received">
<?php if(money($row['received_amount'])>0){ ?>
₹<?= number_format(money($row['received_amount']),2) ?>
<?php }else{ ?>
-
<?php } ?>
</td>

<td class="text-right money-refund">
<?php if(money($row['refund_amount'])>0){ ?>
₹<?= number_format(money($row['refund_amount']),2) ?>
<?php }else{ ?>
-
<?php } ?>
</td>

<td class="text-right money-pending">
<?php if(!$isRefund && money($row['pending_amount'])>0){ ?>
₹<?= number_format(money($row['pending_amount']),2) ?>
<?php }else{ ?>
-
<?php } ?>
</td>

<td class="text-right balance">
₹<?= number_format(money($row['balance_amount']),2) ?>
</td>

</tr>

<?php } ?>

<?php } ?>

</tbody>

<tfoot>

<tr>

<td colspan="12" class="text-right">
<strong>Total</strong>
</td>

<td class="text-right">
₹<?= number_format($totalPayable,2) ?>
</td>

<td class="text-right">
₹<?= number_format($totalDiscount,2) ?>
</td>

<td class="text-right">
₹<?= number_format($totalReceivable,2) ?>
</td>

<td class="text-right">
₹<?= number_format($totalReceived,2) ?>
</td>

<td class="text-right">
₹<?= number_format($totalRefund,2) ?>
</td>

<td class="text-right">
₹<?= number_format($totalPending,2) ?>
</td>

<td class="text-right">
₹<?= number_format($runningBalance,2) ?>
</td>

</tr>

</tfoot>

</table>

</div>
</div>

</div>
</div>
</body>
</html>