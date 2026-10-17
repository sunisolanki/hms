<!--/public_html/modules/pharmacy/ajax/save_pharmacy_sale.php  -->
<?php

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode([
        'status'=>false,
        'message'=>'Session expired.'
    ]);
    exit;
}

$user_id=(int)$_SESSION['user_id'];

require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!checkPermission('pharmacy_sales','create')){

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

$conn->begin_transaction();

try{

    $patient_type=trim($_POST['patient_type']??'');
    $sale_against=trim($_POST['sale_against']??'');

    $patient_id=(int)($_POST['patient_id']??0);
    $appointment_id=(int)($_POST['appointment_id']??0);
    $prescription_id=(int)($_POST['prescription_id']??0);
    $branch_id=(int)($_POST['branch_id']??0);
    $store_id=(int)($_POST['store_id']??0);

    $title=trim($_POST['title']??'');
    $patient_name=trim($_POST['patient_name']??'');
    $mobile=trim($_POST['mobile']??'');
    $uhid_op_no=trim($_POST['uhid_op_no']??'');
    $aadhar_no=trim($_POST['aadhar_no']??'');
    $location=trim($_POST['location']??'');

    $referral_type=trim($_POST['referral_type']??'');

    $internal_referral_id=(int)(
        $_POST['internal_referral_id']??0
    );

    $external_referral_id=(int)(
        $_POST['external_referral_id']??0
    );

    $empanelment_id=(int)(
        $_POST['empanelment_id']??0
    );

    $remarks=trim($_POST['remarks']??'');

    $receipt_date=trim(
        $_POST['receipt_date']??date('Y-m-d H:i:s')
    );



    $sub_total=(float)(
        $_POST['sub_total']??0
    );

    $total_discount=(float)(
        $_POST['total_discount']??0
    );

    $total_gst=(float)(
        $_POST['total_gst']??0
    );

    $round_off=(float)(
        $_POST['round_off']??0
    );

    $grand_total=(float)(
        $_POST['grand_total']??0
    );

$payment_mode=trim(
    $_POST['payment_mode']??'Cash'
);

$admission_id=(int)(
    $_POST['admission_id']??0
);

if($patient_type==='IPD' && $admission_id>0){
    $stmt=$conn->prepare("
        SELECT payment_type
        FROM ipd_admissions
        WHERE id=?
        LIMIT 1
    ");

    if(!$stmt){
        throw new Exception(
            'Unable to verify IPD admission payment type.'
        );
    }

    $stmt->bind_param(
        "i",
        $admission_id
    );

    $stmt->execute();

    $ipd_payment=$stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if($ipd_payment){
        $payment_mode=
            $ipd_payment['payment_type']??'Cash';
    }
    else{
    throw new Exception(
        'Invalid IPD admission. Please select the IPD patient again.'
    );
}
}
$received_amount=isset($_POST['received_amount'])
    ? (float)$_POST['received_amount']
    : 0;

if($received_amount<0){
    $received_amount=0;
}

if($received_amount>$grand_total){
    throw new Exception(
        'Received amount cannot be greater than Grand Total.'
    );
}

if($payment_mode==='Credit'){
    $received_amount=0;
}

$balance_amount=
    $grand_total-$received_amount;

if($balance_amount<0){
    $balance_amount=0;
}

if($balance_amount<=0){
    $payment_status='Paid';
}elseif($received_amount>0){
    $payment_status='Partial';
}else{
    $payment_status='Pending';
}
    /*
     * Basic validation
     */

    if($patient_type===''){
        throw new Exception(
            'Patient Type is required.'
        );
    }

    if(
        $patient_type!=='OPD' &&
        $patient_type!=='IPD' &&
        $patient_type!=='Direct Visit'
    ){
        throw new Exception(
            'Invalid Patient Type.'
        );
    }

    if($sale_against===''){
        throw new Exception(
            'Sale Against is required.'
        );
    }

    if(
        $sale_against!=='Direct' &&
        $sale_against!=='Prescription'
    ){
        throw new Exception(
            'Invalid Sale Against.'
        );
    }

    if($patient_name===''){
        throw new Exception(
            'Patient Name is required.'
        );
    }

    if($branch_id<=0){
        throw new Exception(
            'Branch is required.'
        );
    }

    if($store_id<=0){
        throw new Exception(
            'Store is required.'
        );
    }

    /*
     * Tenant-side branch/store validation.
     */
    $branch_check_stmt=$conn->prepare("
        SELECT id
        FROM branch_master
        WHERE id=?
          AND status=1
        LIMIT 1
    ");
    if(!$branch_check_stmt){
        throw new Exception(
            'Unable to validate branch.'
        );
    }
    $branch_check_stmt->bind_param("i",$branch_id);
    $branch_check_stmt->execute();
    $branch_ok=$branch_check_stmt->get_result()->fetch_assoc();
    $branch_check_stmt->close();
    if(!$branch_ok){
        throw new Exception('Invalid branch selected.');
    }

    $store_check_stmt=$conn->prepare("
        SELECT id, branch_id
        FROM stores
        WHERE id=?
          AND status='Active'
        LIMIT 1
    ");
    if(!$store_check_stmt){
        throw new Exception('Unable to validate store.');
    }
    $store_check_stmt->bind_param("i",$store_id);
    $store_check_stmt->execute();
    $store_row=$store_check_stmt->get_result()->fetch_assoc();
    $store_check_stmt->close();
    if(
        !$store_row
        || (int)$store_row['branch_id'] !== $branch_id
    ){
        throw new Exception('Invalid store selected for branch.');
    }

   
    /*
     * Item arrays
     */

    $items=$_POST['item_id']??[];
    $batch_ids=$_POST['batch_no']??[];
    $qtys=$_POST['qty']??[];
    $mrps=$_POST['mrp']??[];
    $sale_rates=$_POST['sale_rate']??[];
    $discount_percents=
        $_POST['discount_percent']??[];
    $discount_values=
        $_POST['discount_value']??[];
    $amounts=$_POST['amount']??[];
    $gst_percents=
        $_POST['gst_percent']??[];
    $gst_values=
        $_POST['gst_value']??[];
    $net_amounts=
        $_POST['net_amount']??[];
    $exp_dates=$_POST['exp_date']??[];
    $mrn_nos=$_POST['mrn']??[];

    $valid_items=[];

    foreach($items as $i=>$item_id){

        $item_id=(int)$item_id;

        $batch_id=(int)(
            $batch_ids[$i]??0
        );

        $qty=(float)(
            $qtys[$i]??0
        );

        if(
            $item_id<=0 ||
            $batch_id<=0 ||
            $qty<=0
        ){
            continue;
        }

        $valid_items[]=[
            'item_id'=>$item_id,
            'batch_id'=>$batch_id,
            'qty'=>$qty,
            'mrp'=>(float)(
                $mrps[$i]??0
            ),
            'sale_rate'=>(float)(
                $sale_rates[$i]??0
            ),
            'discount_percent'=>(float)(
                $discount_percents[$i]??0
            ),
            'discount_value'=>(float)(
                $discount_values[$i]??0
            ),
            'amount'=>(float)(
                $amounts[$i]??0
            ),
            'gst_percent'=>(float)(
                $gst_percents[$i]??0
            ),
            'gst_value'=>(float)(
                $gst_values[$i]??0
            ),
            'net_amount'=>(float)(
                $net_amounts[$i]??0
            ),
            'exp_date'=>trim(
                $exp_dates[$i]??''
            ),
            'mrn_no'=>trim(
                $mrn_nos[$i]??''
            )
        ];
    }

    if(empty($valid_items)){
        throw new Exception(
            'Please add at least one medicine.'
        );
    }

    /*
     * Validate all selected batches and stock.
     * FOR UPDATE locks the stock rows until commit.
     */

    foreach($valid_items as $item){

        $stmt=$conn->prepare("
            SELECT
                id,
                item_id,
                store_id,
                batch_no,
                exp_date,
                qty_available,
                mrp,
                mrn_no,
                gst_percent
            FROM inventory_batches
            WHERE id=?
            AND store_id=?
            AND item_id=?
            AND status='Active'
            FOR UPDATE
        ");

        if(!$stmt){
            throw new Exception(
                'Unable to prepare stock validation.'
            );
        }

        $stmt->bind_param(
            "iii",
            $item['batch_id'],
            $store_id,
            $item['item_id']
        );

        $stmt->execute();

        $result=$stmt->get_result();

        if($result->num_rows===0){

            $stmt->close();

            throw new Exception(
                'Selected batch is no longer available.'
            );
        }

        $batch=$result->fetch_assoc();

        if(
            (float)$batch['qty_available']
            <
            $item['qty']
        ){

            $batch_no=$batch['batch_no'];

            $stmt->close();

            throw new Exception(
                'Insufficient stock for batch '.
                $batch_no.'.'
            );
        }

        $stmt->close();
    }

    /*
     * Current year
     */

    $timestamp=strtotime($receipt_date);

    if($timestamp===false){
        $receipt_date=date(
            'Y-m-d H:i:s'
        );
    }

    $year=(int)date(
        'Y',
        strtotime($receipt_date)
    );


    /*
     * Generate Sale Number
     *
     * Store + Year
     *
     * Example:
     * SALE/5/2026/00001
     */

    $stmt=$conn->prepare("
        SELECT
            COUNT(*)+1 AS next_no
        FROM pharmacy_sales
        WHERE store_id=?
        AND YEAR(sale_date)=?
        FOR UPDATE
    ");

    if(!$stmt){
        throw new Exception(
            'Unable to generate sale number.'
        );
    }

    $stmt->bind_param(
        "ii",
        $store_id,
        $year
    );

    $stmt->execute();

    $next=$stmt->get_result()->fetch_assoc();

    $stmt->close();

    $sale_no=
        'SALE/'.
        $store_id.'/'.
        $year.'/'.
        str_pad(
            (int)$next['next_no'],
            5,
            '0',
            STR_PAD_LEFT
        );

    /*
     * Invoice handling
     *
     * OPD:
     * One invoice per appointment.
     *
     * IPD:
     * One invoice per appointment.
     *
     * Direct Visit:
     * No invoice.
     */

    $invoice_id=0;
    $invoice_no=null;

    if(
        (
            $patient_type==='OPD' ||
            $patient_type==='IPD'
        )
        &&
        $appointment_id>0
    ){

        $stmt=$conn->prepare("
            SELECT
                id,
                invoice_no
            FROM pharmacy_invoices
            WHERE invoice_type=?
            AND appointment_id=?
            LIMIT 1
            FOR UPDATE
        ");

        if(!$stmt){
            throw new Exception(
                'Unable to check invoice.'
            );
        }

        $stmt->bind_param(
            "si",
            $patient_type,
            $appointment_id
        );

        $stmt->execute();

        $result=$stmt->get_result();

        if($result->num_rows>0){

            $invoice=$result->fetch_assoc();

            $invoice_id=(int)$invoice['id'];

            $invoice_no=$invoice['invoice_no'];
        }

        $stmt->close();

        /*
         * Create invoice only if this is
         * the first pharmacy sale for
         * this OPD/IPD appointment.
         */

        if($invoice_id<=0){

            $stmt=$conn->prepare("
                SELECT
                    COUNT(*)+1 AS next_no
                FROM pharmacy_invoices
                WHERE branch_id=?
                AND invoice_type=?
                AND YEAR(invoice_date)=?
                FOR UPDATE
            ");

            if(!$stmt){
                throw new Exception(
                    'Unable to generate invoice number.'
                );
            }

            $stmt->bind_param(
                "isi",
                $branch_id,
                $patient_type,
                $year
            );

            $stmt->execute();

            $next=$stmt->get_result()->fetch_assoc();

            $stmt->close();

            $invoice_no=
                'INV/'.
                $patient_type.'/'.
                $branch_id.'/'.
                $year.'/'.
                str_pad(
                    (int)$next['next_no'],
                    5,
                    '0',
                    STR_PAD_LEFT
                );

            $stmt=$conn->prepare("
                INSERT INTO pharmacy_invoices
                (
                    invoice_no,
                    invoice_type,
                    patient_id,
                    appointment_id,
                    branch_id,
                    invoice_date,
                    created_by
                )
                VALUES
                (
                    ?,
                    ?,
                    NULLIF(?,0),
                    NULLIF(?,0),
                    NULLIF(?,0),
                    ?,
                    ?
                )
            ");

            if(!$stmt){
                throw new Exception(
                    'Unable to create invoice.'
                );
            }

            $stmt->bind_param(
                "ssiissi",
                $invoice_no,
                $patient_type,
                $patient_id,
                $appointment_id,
                $branch_id,
                $receipt_date,
                $user_id
            );

            $stmt->execute();

            $invoice_id=
                $stmt->insert_id;

            $stmt->close();
        }
    }

    /*
     * Save Pharmacy Sale
     */

    $stmt=$conn->prepare("
        INSERT INTO pharmacy_sales
        (
            sale_no,
            invoice_id,
            patient_type,
            sale_against,
            patient_id,
            appointment_id,
            prescription_id,
            branch_id,
            store_id,
            patient_name,
            title,
            mobile,
            uhid_op_no,
            aadhar_no,
            location,
            referral_type,
            internal_referral_id,
            external_referral_id,
            empanelment_id,
            remarks,
            sale_date,
            sub_total,
            total_discount,
            total_gst,
            round_off,
            grand_total,
            created_by
        )
        VALUES
        (
            ?,
            NULLIF(?,0),
            ?,
            ?,
            NULLIF(?,0),
            NULLIF(?,0),
            NULLIF(?,0),
            NULLIF(?,0),
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NULLIF(?,0),
            NULLIF(?,0),
            NULLIF(?,0),
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
        throw new Exception(
            'Unable to prepare sale save.'
        );
    }

    /*
     * 27 values
     *
     * s
     * i
     * s
     * s
     * i
     * i
     * i
     * i
     * i
     * s
     * s
     * s
     * s
     * s
     * s
     * s
     * i
     * i
     * i
     * s
     * s
     * d
     * d
     * d
     * d
     * d
     * i
     */

    $stmt->bind_param(
        "sissiiiiisssssssiiissdddddi",
        $sale_no,
        $invoice_id,
        $patient_type,
        $sale_against,
        $patient_id,
        $appointment_id,
        $prescription_id,
        $branch_id,
        $store_id,
        $patient_name,
        $title,
        $mobile,
        $uhid_op_no,
        $aadhar_no,
        $location,
        $referral_type,
        $internal_referral_id,
        $external_referral_id,
        $empanelment_id,
        $remarks,
        $receipt_date,
        $sub_total,
        $total_discount,
        $total_gst,
        $round_off,
        $grand_total,
        $user_id
    );

    $stmt->execute();

    $sale_id=$stmt->insert_id;

    $stmt->close();

    /*
     * Save Sale Items
     */

    $item_stmt=$conn->prepare("
        INSERT INTO pharmacy_sale_items
        (
            sale_id,
            item_id,
            batch_id,
            batch_no,
            exp_date,
            stock_qty_before,
            qty,
            mrp,
            sale_rate,
            discount_percent,
            discount_value,
            amount,
            gst_percent,
            gst_value,
            net_amount,
            mrn_no
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            NULLIF(?, ''),
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

    if(!$item_stmt){
        throw new Exception(
            'Unable to prepare sale items.'
        );
    }

    /*
     * Reduce inventory stock.
     */

    $stock_stmt=$conn->prepare("
        UPDATE inventory_batches
        SET
            qty_available=qty_available-?
        WHERE id=?
        AND store_id=?
        AND item_id=?
        AND status='Active'
        AND qty_available>=?
    ");

    if(!$stock_stmt){
        throw new Exception(
            'Unable to prepare stock update.'
        );
    }

    foreach($valid_items as $item){

        /*
         * Get current locked batch data.
         */

        $stmt=$conn->prepare("
            SELECT
                batch_no,
                exp_date,
                qty_available,
                mrn_no,
                gst_percent
            FROM inventory_batches
            WHERE id=?
            AND store_id=?
            AND item_id=?
            FOR UPDATE
        ");

        if(!$stmt){
            throw new Exception(
                'Unable to read batch.'
            );
        }

        $stmt->bind_param(
            "iii",
            $item['batch_id'],
            $store_id,
            $item['item_id']
        );

        $stmt->execute();

        $batch=$stmt->get_result()->fetch_assoc();

        $stmt->close();

        if(!$batch){
            throw new Exception(
                'Batch not found.'
            );
        }

        $stock_before=
            (float)$batch['qty_available'];

        if(
            $stock_before<
            $item['qty']
        ){
            throw new Exception(
                'Insufficient stock for batch '.
                $batch['batch_no'].'.'
            );
        }

        $batch_no=
            $batch['batch_no'];

        $exp_date=
            $batch['exp_date'];

        $mrn_no=
            $batch['mrn_no'];

        $gst_percent=
            $item['gst_percent'];

        /*
         * Insert sale item.
         */

        $item_stmt->bind_param(
            "iiissdddddddddds",
            $sale_id,
            $item['item_id'],
            $item['batch_id'],
            $batch_no,
            $exp_date,
            $stock_before,
            $item['qty'],
            $item['mrp'],
            $item['sale_rate'],
            $item['discount_percent'],
            $item['discount_value'],
            $item['amount'],
            $gst_percent,
            $item['gst_value'],
            $item['net_amount'],
            $mrn_no
        );

        $item_stmt->execute();

        /*
         * Deduct stock.
         */

        $stock_stmt->bind_param(
            "diiid",
            $item['qty'],
            $item['batch_id'],
            $store_id,
            $item['item_id'],
            $item['qty']
        );

        $stock_stmt->execute();

        if($stock_stmt->affected_rows!==1){

            throw new Exception(
                'Unable to update stock for batch '.
                $batch_no.'.'
            );
        }
    }

    $item_stmt->close();
    $stock_stmt->close();

    /*
     * Always generate Receipt.
     */

    $stmt=$conn->prepare("
        SELECT
            COUNT(*)+1 AS next_no
        FROM pharmacy_receipts pr
        INNER JOIN pharmacy_sales ps
            ON ps.id=pr.sale_id
        WHERE ps.store_id=?
        AND YEAR(ps.sale_date)=?
        FOR UPDATE
    ");

    if(!$stmt){
        throw new Exception(
            'Unable to generate receipt number.'
        );
    }

    $stmt->bind_param(
        "ii",
        $store_id,
        $year
    );

    $stmt->execute();

    $next=$stmt->get_result()->fetch_assoc();

    $stmt->close();

    $receipt_no=
        'RCPT/'.
        $store_id.'/'.
        $year.'/'.
        str_pad(
            (int)$next['next_no'],
            5,
            '0',
            STR_PAD_LEFT
        );

    /*
     * Payment-specific fields
     */

    $cash_remarks=trim(
        $_POST['cash_remarks']??''
    );

    $upi_app=trim(
        $_POST['upi_app']??''
    );

    $upi_txn_id=trim(
        $_POST['upi_txn_id']??''
    );

    $card_type=trim(
        $_POST['card_type']??''
    );

    $card_bank_name=trim(
        $_POST['card_bank_name']??''
    );

    $card_last4=trim(
        $_POST['card_last4']??''
    );

    $card_auth_code=trim(
        $_POST['card_auth_code']??''
    );

    $cheque_no=trim(
        $_POST['cheque_no']??''
    );

    $cheque_bank=trim(
        $_POST['cheque_bank']??''
    );

    $cheque_date=trim(
        $_POST['cheque_date']??''
    );

    $neft_ref=trim(
        $_POST['neft_ref']??''
    );

    $neft_bank=trim(
        $_POST['neft_bank']??''
    );

    $ecs_bank_name=trim(
        $_POST['ecs_bank_name']??''
    );

    $ecs_reference_no=trim(
        $_POST['ecs_reference_no']??''
    );

    $ecs_transaction_date=trim(
        $_POST['ecs_transaction_date']??''
    );

    /*
     * Save Receipt
     */

    $stmt=$conn->prepare("
        INSERT INTO pharmacy_receipts
        (
            receipt_no,
            sale_id,
            invoice_id,
            patient_id,
            appointment_id,
            receipt_date,
            payment_mode,
            received_amount,
            balance_amount,
            payment_status,
            cash_remarks,
            upi_app,
            upi_txn_id,
            card_type,
            card_bank_name,
            card_last4,
            card_auth_code,
            cheque_no,
            cheque_bank,
            cheque_date,
            neft_ref,
            neft_bank,
            ecs_bank_name,
            ecs_reference_no,
            ecs_transaction_date,
            created_by
        )
        VALUES
        (
            ?,
            ?,
            NULLIF(?,0),
            NULLIF(?,0),
            NULLIF(?,0),
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
            ?,
            NULLIF(?, ''),
            ?,
            ?,
            ?,
            ?,
            NULLIF(?, ''),
            ?
        )
    ");

    if(!$stmt){
        throw new Exception(
            'Unable to prepare receipt save.'
        );
    }

    $stmt->bind_param(
        "siiiissddssssssssssssssssi",
        $receipt_no,
        $sale_id,
        $invoice_id,
        $patient_id,
        $appointment_id,
        $receipt_date,
        $payment_mode,
        $received_amount,
        $balance_amount,
        $payment_status,
        $cash_remarks,
        $upi_app,
        $upi_txn_id,
        $card_type,
        $card_bank_name,
        $card_last4,
        $card_auth_code,
        $cheque_no,
        $cheque_bank,
        $cheque_date,
        $neft_ref,
        $neft_bank,
        $ecs_bank_name,
        $ecs_reference_no,
        $ecs_transaction_date,
        $user_id
    );

    $stmt->execute();

    $receipt_id=$stmt->insert_id;

    $stmt->close();

    /*
     * Update OPD/IPD Invoice
     */

    if($invoice_id>0){

        $stmt=$conn->prepare("
            SELECT
                COALESCE(
                    SUM(grand_total),
                    0
                ) AS total_amount
            FROM pharmacy_sales
            WHERE invoice_id=?
        ");

        if(!$stmt){
            throw new Exception(
                'Unable to calculate invoice total.'
            );
        }

        $stmt->bind_param(
            "i",
            $invoice_id
        );

        $stmt->execute();

        $invoiceTotals=
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        $invoice_total=
            (float)$invoiceTotals['total_amount'];

        $stmt=$conn->prepare("
            SELECT
                COALESCE(
                    SUM(received_amount),
                    0
                ) AS total_received
            FROM pharmacy_receipts
            WHERE invoice_id=?
        ");

        if(!$stmt){
            throw new Exception(
                'Unable to calculate received amount.'
            );
        }

        $stmt->bind_param(
            "i",
            $invoice_id
        );

        $stmt->execute();

        $receivedTotals=
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        $invoice_received=
            (float)$receivedTotals[
                'total_received'
            ];

        $invoice_balance=
            $invoice_total-
            $invoice_received;

        if($invoice_balance<0){
            $invoice_balance=0;
        }

        if($invoice_balance<=0){
            $invoice_status='Paid';
        }elseif($invoice_received>0){
            $invoice_status='Partial';
        }else{
            $invoice_status='Pending';
        }

        $stmt=$conn->prepare("
            UPDATE pharmacy_invoices
            SET
                gross_amount=?,
                discount_amount=?,
                gst_amount=?,
                net_amount=?,
                received_amount=?,
                balance_amount=?,
                status=?
            WHERE id=?
        ");

        if(!$stmt){
            throw new Exception(
                'Unable to update invoice.'
            );
        }

        $stmt->bind_param(
            "ddddddsi",
            $invoice_total,
            $total_discount,
            $total_gst,
            $invoice_total,
            $invoice_received,
            $invoice_balance,
            $invoice_status,
            $invoice_id
        );

        $stmt->execute();

        $stmt->close();
    }

    /*
     * Everything successful.
     */

    $conn->commit();

    echo json_encode([
        'status'=>true,
        'message'=>'Sale saved successfully.',
        'sale_id'=>$sale_id,
        'sale_no'=>$sale_no,
        'receipt_id'=>$receipt_id,
        'receipt_no'=>$receipt_no,
        'invoice_id'=>$invoice_id,
        'invoice_no'=>$invoice_no,
        'received_amount'=>$received_amount,
        'balance_amount'=>$balance_amount,
        'payment_status'=>$payment_status
    ]);

}catch(Throwable $e){

    $conn->rollback();

    error_log(
        'Pharmacy Sale Error: '.
        $e->getMessage()
    );

    echo json_encode([
        'status'=>false,
        'message'=>$e->getMessage()
    ]);
}