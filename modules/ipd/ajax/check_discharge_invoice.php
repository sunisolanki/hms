<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode([
        'success'=>false,
        'message'=>'Session expired. Please login again.'
    ]);
    exit;
}

$appointment_id=(int)($_GET['appointment_id'] ?? 0);

if($appointment_id<=0){
    echo json_encode([
        'success'=>false,
        'message'=>'Invalid appointment.'
    ]);
    exit;
}

/* =========================================
GET IPD ADMISSION
========================================= */

$stmt=$conn->prepare("
SELECT
    id,
    appointment_id,
    patient_id,
    ipd_no
FROM ipd_admissions
WHERE appointment_id=?
LIMIT 1
");

if(!$stmt){
    echo json_encode([
        'success'=>false,
        'message'=>'Unable to check IPD admission.'
    ]);
    exit;
}

$stmt->bind_param(
    "i",
    $appointment_id
);

$stmt->execute();

$result=$stmt->get_result();

$admission=$result->fetch_assoc();

$stmt->close();

if(!$admission){
    echo json_encode([
        'success'=>false,
        'message'=>'IPD admission not found.'
    ]);
    exit;
}

$ipd_admission_id=(int)$admission['id'];
$ipd_no=$admission['ipd_no'];

/* =========================================
UPDATE / SYNCHRONIZE IPD LEDGER
========================================= */

$ledgerFile=
    $_SERVER['DOCUMENT_ROOT'].
    '/modules/billing/ipdbilling/ipd_ledger/generate_ledger.php';

if(!file_exists($ledgerFile)){

    echo json_encode([
        'success'=>false,
        'message'=>'IPD Ledger module not found.'
    ]);

    exit;
}

/*
IMPORTANT:

generate_ledger.php is already the working Ledger engine.

We run it silently here so that:
- latest charges are collected
- latest payments are collected
- latest room charges are calculated
- pharmacy transactions are collected
- existing ledger rows are updated
- running balance is recalculated

Its HTML output is discarded because this file must return JSON.
*/

$originalGet=$_GET;

$_GET=[
    'ipd_admission_id'=>$ipd_admission_id
];

ob_start();

try{

    require $ledgerFile;

    $ledgerOutput=ob_get_clean();

}catch(Throwable $e){

    ob_end_clean();

    $_GET=$originalGet;

    echo json_encode([
        'success'=>false,
        'message'=>'Unable to update IPD Ledger: '.$e->getMessage()
    ]);

    exit;
}

$_GET=$originalGet;

/* =========================================
GET LATEST LEDGER BALANCE
========================================= */

$stmt=$conn->prepare("
SELECT
    id,
    balance_amount
FROM ipd_ledger
WHERE ipd_admission_id=?
AND is_reversal=0
ORDER BY
    entry_date DESC,
    entry_time DESC,
    id DESC
LIMIT 1
");

if(!$stmt){

    echo json_encode([
        'success'=>false,
        'message'=>'Unable to read latest Ledger balance.'
    ]);

    exit;
}

$stmt->bind_param(
    "i",
    $ipd_admission_id
);

$stmt->execute();

$result=$stmt->get_result();

$ledger=$result->fetch_assoc();

$stmt->close();

/* =========================================
NO LEDGER ENTRIES
========================================= */

if(!$ledger){

    /*
    No ledger entries means there is no financial
    transaction available to confirm clearance.

    We therefore do NOT automatically allow discharge.
    */

    echo json_encode([
        'success'=>false,
        'status'=>'ledger_not_generated',
        'ipd_no'=>$ipd_no,
        'balance_amount'=>0,
        'message'=>'IPD Ledger could not be generated. Please try again before discharging the patient.'
    ]);

    exit;
}

/* =========================================
LATEST BALANCE
========================================= */

$balance=(float)$ledger['balance_amount'];

/* =========================================
BALANCE PENDING
========================================= */

if($balance>0.005){

    echo json_encode([
        'success'=>false,
        'status'=>'payment_pending',
        'ipd_no'=>$ipd_no,
        'balance_amount'=>round($balance,2),
        'message'=>'Payment is pending. Please clear the outstanding balance of ₹'.number_format($balance,2).' before discharging the patient.'
    ]);

    exit;
}

/* =========================================
EXCESS ADVANCE
========================================= */

if($balance<-0.005){

    $advanceAmount=abs($balance);

    echo json_encode([
        'success'=>false,
        'status'=>'advance_balance',
        'ipd_no'=>$ipd_no,
        'balance_amount'=>round($balance,2),
        'advance_amount'=>round($advanceAmount,2),
        'message'=>'Patient has an advance balance of ₹'.number_format($advanceAmount,2).'. Please settle the advance before discharging the patient.'
    ]);

    exit;
}

/* =========================================
BALANCE CLEARED
========================================= */

echo json_encode([
    'success'=>true,
    'status'=>'paid',
    'ipd_no'=>$ipd_no,
    'balance_amount'=>0,
    'message'=>'All IPD payments are cleared. Patient can be discharged.'
]);