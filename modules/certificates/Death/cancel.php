<?php
session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

checkPermission('death_certificate_add','add');


/* ===========================================================
   CERTIFICATE ID
=========================================================== */

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id <= 0){
    die('Invalid Death Certificate ID.');
}


/* ===========================================================
   SESSION
=========================================================== */

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

if($user_id <= 0){
    die('Invalid User Session.');
}

if($branch_id <= 0){
    die('Branch not found in session.');
}


/* ===========================================================
   GET HOSPITAL FROM BRANCH
=========================================================== */

$stmt = $conn->prepare("
    SELECT hospital_id
    FROM branch_master
    WHERE id=?
    LIMIT 1
");

$stmt->bind_param("i", $branch_id);
$stmt->execute();

$result = $stmt->get_result();
$branch = $result->fetch_assoc();

$stmt->close();

if(!$branch || (int)$branch['hospital_id'] <= 0){
    die('Hospital not found for this branch.');
}

$hospital_id = (int)$branch['hospital_id'];


/* ===========================================================
   CHECK CERTIFICATE
=========================================================== */

$stmt = $conn->prepare("
    SELECT
        id,
        status,
        certificate_no
    FROM death_certificates
    WHERE id=?
      AND hospital_id=?
      AND branch_id=?
    LIMIT 1
");

$stmt->bind_param(
    "iii",
    $id,
    $hospital_id,
    $branch_id
);

$stmt->execute();

$result = $stmt->get_result();
$certificate = $result->fetch_assoc();

$stmt->close();


if(!$certificate){

    die(
        'Death Certificate not found.<br><br>'.
        'Certificate ID: '.htmlspecialchars((string)$id).'<br>'.
        'Hospital ID: '.htmlspecialchars((string)$hospital_id).'<br>'.
        'Branch ID: '.htmlspecialchars((string)$branch_id)
    );
}


/* ===========================================================
   CHECK STATUS
=========================================================== */

if($certificate['status'] === 'Cancelled'){

    $_SESSION['error_message'] =
        'Death Certificate is already cancelled.';

    header('Location: death_certificate.php');
    exit;
}


if($certificate['status'] !== 'Done'){

    die(
        'Cannot cancel Death Certificate. Current status: '.
        htmlspecialchars((string)$certificate['status'])
    );
}


/* ===========================================================
   CANCEL CERTIFICATE
=========================================================== */

try{

    $conn->begin_transaction();

    $status = 'Cancelled';

    $stmt = $conn->prepare("
        UPDATE death_certificates
        SET
            status=?,
            updated_by=?
        WHERE id=?
          AND hospital_id=?
          AND branch_id=?
          AND status='Done'
    ");

    $stmt->bind_param(
        "siiii",
        $status,
        $user_id,
        $id,
        $hospital_id,
        $branch_id
    );

    $stmt->execute();

    $affected = $stmt->affected_rows;

    $stmt->close();


    if($affected !== 1){
        throw new Exception(
            'Death Certificate could not be cancelled.'
        );
    }


    $conn->commit();


    /* =======================================================
       SUCCESS
    ======================================================= */

    $_SESSION['success_message'] =
        'Death Certificate cancelled successfully.';


    header('Location: death_certificate.php');
    exit;


}
catch(mysqli_sql_exception $e){

    try{
        $conn->rollback();
    }catch(Throwable $rollbackError){
    }

    die(
        '<h3>Database Error</h3>'.
        '<pre>'.
        htmlspecialchars($e->getMessage()).
        '</pre>'
    );

}
catch(Throwable $e){

    try{
        $conn->rollback();
    }catch(Throwable $rollbackError){
    }

    die(
        '<h3>PHP Error</h3>'.
        '<pre>'.
        htmlspecialchars($e->getMessage()).
        '</pre>'
    );

}
?>