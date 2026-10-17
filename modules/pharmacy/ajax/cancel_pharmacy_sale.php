<!--public_html/modules/pharmacy/ajax/cancel_pharmacy_sale.php  -->
<?php

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])){

    echo json_encode([
        'status'=>false,
        'message'=>'Session expired.'
    ]);

    exit;
}

/*
 * Authorization: cancellation is represented by the existing
 * pharmacy_sales 'edit' action, the same action pharmacy_sales.php
 * uses before showing the cancel control. No permission slug is
 * invented; this only brings the endpoint into step with current UI.
 */
if(!checkPermission('pharmacy_sales','edit')){

    echo json_encode([
        'status'=>false,
        'message'=>'Access denied.'
    ]);
    exit;
}

if(
    empty($_SESSION['csrf'])
    || !isset($_POST['csrf'])
    || !is_string($_POST['csrf'])
    || !hash_equals($_SESSION['csrf'], $_POST['csrf'])
){

    echo json_encode([
        'status'=>false,
        'message'=>'Invalid or missing CSRF token.'
    ]);
    exit;
}

$user_id=(int)$_SESSION['user_id'];

$sale_id=(int)(
    $_POST['sale_id']??0
);

$cancellation_reason=trim(
    $_POST['cancellation_reason']??''
);

$return_payment_mode=trim(
    $_POST['return_payment_mode']??''
);

$return_amount=(float)(
    $_POST['return_amount']??0
);


if($sale_id<=0){

    echo json_encode([
        'status'=>false,
        'message'=>'Invalid Sale ID.'
    ]);

    exit;
}

if($cancellation_reason===''){

    echo json_encode([
        'status'=>false,
        'message'=>'Cancellation reason is required.'
    ]);

    exit;
}

if($return_amount<0){

    echo json_encode([
        'status'=>false,
        'message'=>'Return amount cannot be negative.'
    ]);

    exit;
}

$conn->begin_transaction();

try{

    /*
     * Get and lock sale
     */

    $stmt=$conn->prepare("
        SELECT
            id,
            sale_no,
            invoice_id,
            store_id,
            grand_total,
            status
        FROM pharmacy_sales
        WHERE id=?
        FOR UPDATE
    ");

    if(!$stmt){

        throw new Exception(
            'Unable to prepare sale lookup.'
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

    if($sale['status']==='Cancelled'){

        throw new Exception(
            'This sale is already cancelled.'
        );
    }

    if($sale['status']!=='Active'){

        throw new Exception(
            'Only Active sales can be cancelled.'
        );
    }

    /*
     * Calculate actual received amount
     */

    $stmt=$conn->prepare("
        SELECT
            COALESCE(
                SUM(received_amount),
                0
            ) AS total_received
        FROM pharmacy_receipts
        WHERE sale_id=?
    ");

    if(!$stmt){

        throw new Exception(
            'Unable to calculate received amount.'
        );
    }

    $stmt->bind_param(
        "i",
        $sale_id
    );

    $stmt->execute();

    $payment=$stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    $total_received=(float)(
        $payment['total_received']??0
    );

    $grand_total=(float)$sale['grand_total'];

    /*
     * Tenant validation: the sale's store and branch must still be
     * active and internally consistent before cancellation.
     */
    $cancel_branch_ok=false;
    $cancel_store_ok=false;

    $cb_stmt=$conn->prepare("
        SELECT status
        FROM branch_master
        WHERE id=?
        LIMIT 1
    ");
    if($cb_stmt){
        $cb_stmt->bind_param('i',(int)$sale['branch_id']);
        $cb_stmt->execute();
        $branch_row=$cb_stmt->get_result()->fetch_assoc();
        $cb_stmt->close();
        $cancel_branch_ok=($branch_row && $branch_row['status']==='1');
    }

    $cs_stmt=$conn->prepare("
        SELECT id
        FROM stores
        WHERE id=?
          AND branch_id=?
          AND status='Active'
        LIMIT 1
    ");
    if($cs_stmt){
        $cs_stmt->bind_param('ii',(int)$sale['store_id'],(int)$sale['branch_id']);
        $cs_stmt->execute();
        $cancel_store_ok=($cs_stmt->get_result()->fetch_assoc() !== null);
        $cs_stmt->close();
    }

    if(!$cancel_branch_ok || !$cancel_store_ok){
        throw new Exception('Invalid sale scope.');
    }
    $actual_balance=max(
        $grand_total-$total_received,
        0
    );

    /*
     * Validate return amount
     *
     * Cannot return more than actually received.
     */

    if(
        $return_amount>
        $total_received
    ){

        throw new Exception(
            'Return amount cannot be greater than the amount received.'
        );
    }

    /*
     * Return mode is required when
     * money is actually being returned.
     */

    if(
        $return_amount>0 &&
        $return_payment_mode===''
    ){

        throw new Exception(
            'Please select Return Mode of Payment.'
        );
    }

    /*
     * If no money was received, there is
     * nothing to return.
     */

    if(
        $total_received<=0
    ){

        $return_amount=0;
    }

    /*
     * Get all sale items.
     */

    $stmt=$conn->prepare("
        SELECT
            id,
            item_id,
            batch_id,
            qty
        FROM pharmacy_sale_items
        WHERE sale_id=?
        ORDER BY id ASC
        FOR UPDATE
    ");

    if(!$stmt){

        throw new Exception(
            'Unable to prepare sale items lookup.'
        );
    }

    $stmt->bind_param(
        "i",
        $sale_id
    );

    $stmt->execute();

    $result=$stmt->get_result();

    $items=$result->fetch_all(
        MYSQLI_ASSOC
    );

    $stmt->close();

    if(empty($items)){

        throw new Exception(
            'No sale items found for this sale.'
        );
    }

    /*
     * Restore stock
     */

    foreach($items as $item){

        $item_id=(int)$item['item_id'];

        $batch_id=(int)$item['batch_id'];

        $qty=(float)$item['qty'];

        if(
            $item_id<=0 ||
            $batch_id<=0 ||
            $qty<=0
        ){

            throw new Exception(
                'Invalid sale item data.'
            );
        }

        /*
         * Lock inventory batch
         */

        $stmt=$conn->prepare("
            SELECT
                id,
                item_id,
                store_id,
                status,
                qty_available
            FROM inventory_batches
            WHERE id=?
            FOR UPDATE
        ");

        if(!$stmt){

            throw new Exception(
                'Unable to prepare batch lookup.'
            );
        }

        $stmt->bind_param(
            "i",
            $batch_id
        );

        $stmt->execute();

        $result=$stmt->get_result();

        if($result->num_rows===0){

            $stmt->close();

            throw new Exception(
                'Inventory batch not found.'
            );
        }

        $batch=$result->fetch_assoc();

        $stmt->close();

        if(
            (int)$batch['item_id']!==
            $item_id
        ){

            throw new Exception(
                'Inventory batch does not match sale item.'
            );
        }

        /*
         * Restore quantity
         */

        $stmt=$conn->prepare("
            UPDATE inventory_batches
            SET
                qty_available=
                    qty_available+?
            WHERE id=?
        ");

        if(!$stmt){

            throw new Exception(
                'Unable to prepare stock restoration.'
            );
        }

        $stmt->bind_param(
            "di",
            $qty,
            $batch_id
        );

        $stmt->execute();

        if($stmt->affected_rows!==1){

            $stmt->close();

            throw new Exception(
                'Unable to restore stock.'
            );
        }

        $stmt->close();
    }

    /*
     * Cancel sale and save cancellation details
     */

   $stmt=$conn->prepare("
    UPDATE pharmacy_sales
    SET
        status='Cancelled',
        cancellation_reason=?,
        cancelled_by=?,
        cancelled_at=NOW(),
        return_payment_mode=?,
        return_amount=?
    WHERE id=?
    AND status='Active'
");

    if(!$stmt){

        throw new Exception(
            'Unable to prepare sale cancellation.'
        );
    }

   $stmt->bind_param(
    "sisdi",
    $cancellation_reason,
    $user_id,
    $return_payment_mode,
    $return_amount,
    $sale_id
);

    $stmt->execute();

    if($stmt->affected_rows!==1){

        $stmt->close();

        throw new Exception(
            'Sale could not be cancelled.'
        );
    }

    $stmt->close();

    /*
     * Cancel related invoice
     */

    $invoice_id=(int)$sale['invoice_id'];

    if($invoice_id>0){

        $stmt=$conn->prepare("
            SELECT
                COUNT(*) AS active_sales
            FROM pharmacy_sales
            WHERE invoice_id=?
            AND status='Active'
            AND id<>?
        ");

        if(!$stmt){

            throw new Exception(
                'Unable to check invoice usage.'
            );
        }

        $stmt->bind_param(
            "ii",
            $invoice_id,
            $sale_id
        );

        $stmt->execute();

        $invoice_usage=
            $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if(
            (int)$invoice_usage['active_sales']===0
        ){

            $stmt=$conn->prepare("
                UPDATE pharmacy_invoices
                SET
                    status='Cancelled'
                WHERE id=?
            ");

            if(!$stmt){

                throw new Exception(
                    'Unable to cancel invoice.'
                );
            }

            $stmt->bind_param(
                "i",
                $invoice_id
            );

            $stmt->execute();

            $stmt->close();
        }
    }

    /*
     * Keep pharmacy_receipts unchanged.
     *
     * The original receipt remains as an
     * accounting/audit record.
     */

    $conn->commit();

    echo json_encode([
        'status'=>true,
        'message'=>
            'Sale '.
            $sale['sale_no'].
            ' cancelled successfully. Stock has been restored.',
        'sale_id'=>$sale_id,
        'sale_no'=>$sale['sale_no'],
        'received_amount'=>$total_received,
        'return_amount'=>$return_amount,
        'balance_amount'=>$actual_balance
    ]);

    exit;

}catch(Throwable $e){

    $conn->rollback();

    error_log(
        'Pharmacy Sale Cancellation Error: '.
        $e->getMessage()
    );

    echo json_encode([
        'status'=>false,
        'message'=>$e->getMessage()
    ]);

    exit;
}