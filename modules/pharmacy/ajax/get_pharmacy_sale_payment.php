<!--public_html/modules/pharmacy/ajax/get_pharmacy_sale_payment.php  -->
<?php

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])){

    echo json_encode([
        'status'=>false,
        'message'=>'Session expired.'
    ]);

    exit;
}

require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!checkPermission('pharmacy_sales','view')){

    echo json_encode([
        'status'=>false,
        'message'=>'Access denied.'
    ]);

    exit;
}

$sale_id=(int)(
    $_GET['sale_id']??0
);

if($sale_id<=0){

    echo json_encode([
        'status'=>false,
        'message'=>'Invalid Sale ID.'
    ]);

    exit;
}

try{

    /*
     * Get sale total
     */

    $stmt=$conn->prepare("
        SELECT
            id,
            sale_no,
            grand_total,
            status
        FROM pharmacy_sales
        WHERE id=?
        LIMIT 1
    ");

    if(!$stmt){

        throw new Exception(
            'Unable to prepare sale query.'
        );
    }

    $stmt->bind_param(
        "i",
        $sale_id
    );

    $stmt->execute();

    $result=$stmt->get_result();

    if($result->num_rows===0){

        $stmt->close();

        throw new Exception(
            'Sale not found.'
        );
    }

    $sale=$result->fetch_assoc();

    $stmt->close();

    /*
     * Get actual received amount
     * from pharmacy_receipts
     */

    $stmt=$conn->prepare("
        SELECT
    COALESCE(
        SUM(received_amount),
        0
    ) AS received_amount
FROM pharmacy_receipts
WHERE sale_id=?
AND payment_status IN ('Pending','Partial','Paid')
    ");

    if(!$stmt){

        throw new Exception(
            'Unable to prepare payment query.'
        );
    }

    $stmt->bind_param(
        "i",
        $sale_id
    );

    $stmt->execute();

    $payment=
        $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    $grand_total=(float)$sale['grand_total'];

    $received_amount=(float)(
        $payment['received_amount']??0
    );

    $balance_amount=max(
        $grand_total-
        $received_amount,
        0
    );

    echo json_encode([
        'status'=>true,
        'sale_id'=>(int)$sale['id'],
        'sale_no'=>$sale['sale_no'],
        'grand_total'=>$grand_total,
        'received_amount'=>$received_amount,
        'balance_amount'=>$balance_amount,
        'sale_status'=>$sale['status']
    ]);

    exit;

}catch(Throwable $e){

    error_log(
        'Pharmacy Sale Payment Lookup Error: '.
        $e->getMessage()
    );

    echo json_encode([
        'status'=>false,
        'message'=>$e->getMessage()
    ]);

    exit;
}