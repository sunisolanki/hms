<?php
/*
 * PHARMACY SALE PATIENT LIST - backs the patient picker on sale_bill.php.
 *
 * WHY THIS FILE WAS HARDENED
 * -------------------------
 * No session check and no permission check. Unauthenticated it CRASHED rather
 * than refusing: includes/db.php returns $conn = null without
 * $_SESSION['tenant_id'], and display_errors was on, so the fatal - including
 * the absolute filesystem path - was returned in the HTTP body. A crash is not
 * an access control.
 *
 * Added: an authenticated staff session and the pharmacy_sales 'view'
 * permission, checked before any database access.
 *
 * WHY pharmacy_sales 'view' IS THE CORRECT PERMISSION
 * ----------------------------------------------------
 * The single caller is modules/pharmacy/sale_bill.php:1351, the pharmacy SALE
 * bill. The module catalogue holds 'pharmacy_sales' (granted to
 * rights_type_id 2; rights_type_id 1 is bypassed in permissions.php:27), which
 * is the module for this workflow. 'pharmacy' also exists but carries
 * can_create = 0 and is the wrong module for a sale.
 *
 * UNCHANGED CONTRACT: HTTP method stays GET; the parameters are still
 * ?patient_type= and ?search=; the envelope is still {status:true,
 * patients:[...]}. The SELECT column lists are unchanged and explicit - no
 * SELECT *. Note that sale_bill.php:1398 serialises the WHOLE patient object
 * into a data-patient attribute and reads it back at :1440, so every returned
 * key is consumed client-side and no column may be dropped.
 * No CSRF token: two SELECTs, writes nothing.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Emit a refusal and stop. The reason is deliberately coarse so a caller
 * cannot use it to probe for valid sessions, roles or tenants.
 */
function salePatientsRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

/* 1. Authenticated staff session */
if (empty($_SESSION['user_id'])) {
    salePatientsRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    salePatientsRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - read operation supporting the pharmacy sale workflow. */
if (!checkPermission('pharmacy_sales', 'view')) {
    salePatientsRefuse(403, 'Access denied.');
}

header('Content-Type: application/json');

$patient_type=$_GET['patient_type']??'';
$search=trim($_GET['search']??'');
$patients=[];

if($patient_type==='OPD'){
    $sql="
    SELECT
pm.id AS patient_id,
pm.title,
pm.first_name,
pm.last_name,
pm.mobile,
pm.uhid,
pm.aadhaar,
MAX(ob.opd_id) AS appointment_id,
MAX(ob.opd_id) AS opd_id,
MAX(a.opd_no) AS opd_no,
MAX(ob.empanelment_id) AS empanelment_id
FROM opd_bills ob
INNER JOIN patient_master pm
ON pm.id=ob.patient_id
LEFT JOIN appointments a
ON a.id=ob.opd_id
WHERE 1=1
    ";

    $params=[];
    $types="";

    if($search!==''){
        $sql.=" AND (
            CONCAT(pm.first_name,' ',pm.last_name) LIKE ?
            OR pm.mobile LIKE ?
            OR pm.uhid LIKE ?
            OR a.opd_no LIKE ?
        )";

        $like="%".$search."%";
        $params=[$like,$like,$like,$like];
        $types="ssss";
    }

    $sql.=" GROUP BY
    pm.id,
    pm.title,
    pm.first_name,
    pm.last_name,
    pm.mobile,
    pm.uhid,
    pm.aadhaar
    ORDER BY MAX(ob.id) DESC
    LIMIT 100";

    $stmt=$conn->prepare($sql);

    if($types!==""){
        $stmt->bind_param($types,...$params);
    }

    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $row['patient_name']=trim(
            $row['first_name']." ".$row['last_name']
        );
        $row['location']="";
        $row['credit_balance']="0.00";
        $patients[]=$row;
    }

}elseif($patient_type==='IPD'){

    $sql="
    SELECT
        ia.id AS admission_id,
        ia.appointment_id,
        ia.patient_id,
        ia.ipd_no,
        ia.credit_limit,
        ia.payment_type,
        ia.status AS admission_status,
        pm.title,
        pm.first_name,
        pm.last_name,
        pm.mobile,
        pm.uhid,
        pm.aadhaar,
        bm.bed_number,
        COALESCE(
            SUM(ib.balance_amount),
            0
        ) AS outstanding_amount
    FROM ipd_admissions ia
    INNER JOIN patient_master pm
        ON pm.id=ia.patient_id
    LEFT JOIN ipd_room_allocation ira
        ON ira.admission_id=ia.id
        AND ira.status='Occupied'
    LEFT JOIN bed_master bm
        ON bm.id=ira.bed_id
    LEFT JOIN ipd_bills ib
        ON ib.ipd_admission_id=ia.id
        AND ib.payment_status!='Cancelled'
    WHERE ia.status IN ('Admitted','Discharged')
    ";

    $params=[];
    $types="";

    if($search!==''){
        $sql.=" AND (
            CONCAT(pm.first_name,' ',pm.last_name) LIKE ?
            OR pm.mobile LIKE ?
            OR pm.uhid LIKE ?
            OR ia.ipd_no LIKE ?
        )";

        $like="%".$search."%";
        $params=[$like,$like,$like,$like];
        $types="ssss";
    }

    $sql.="
    GROUP BY
        ia.id,
        ia.appointment_id,
        ia.patient_id,
        ia.ipd_no,
        ia.credit_limit,
        ia.payment_type,
        ia.status,
        pm.title,
        pm.first_name,
        pm.last_name,
        pm.mobile,
        pm.uhid,
        pm.aadhaar,
        bm.bed_number
    ORDER BY
        CASE
            WHEN ia.status='Admitted' THEN 0
            ELSE 1
        END,
        ia.admission_date DESC,
        ia.id DESC
    LIMIT 100
    ";

    $stmt=$conn->prepare($sql);

    if($types!==""){
        $stmt->bind_param($types,...$params);
    }

    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $row['patient_name']=trim(
            $row['first_name']." ".$row['last_name']
        );

        $row['location']=$row['bed_number']??'';

        $row['credit_balance']=number_format(
            (float)$row['credit_limit']
            -(float)$row['outstanding_amount'],
            2,
            '.',
            ''
        );

        $patients[]=$row;
    }
}
echo json_encode([
    'status'=>true,
    'patients'=>$patients
]);