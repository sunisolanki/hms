<!--public_html/modules/pharmacy/pharmacy_sales.php  -->
<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])){
    header('Location: /index.php');
    exit;
}

if(!checkPermission('pharmacy_sales','view')){
    header('Location: /access_denied.php');
    exit;
}

if(empty($_SESSION['csrf'])){
    $_SESSION['csrf']=bin2hex(random_bytes(32));
}

$branches=$conn->query("
    SELECT
        id,
        branch_name
    FROM branch_master
    WHERE status=1
    ORDER BY branch_name ASC
");
$selected_date=$_GET['date']??date('Y-m-d');
$search=trim($_GET['search']??'');
$branch_id=(int)($_GET['branch_id']??0);
$where=[];
$params=[];
$types='';
$where[]="ps.status IN ('Active','Cancelled')";
$where[]="DATE(ps.sale_date)=?";
$params[]=$selected_date;
$types.='s';
if($branch_id>0){
    $where[]="ps.branch_id=?";
    $params[]=$branch_id;
    $types.='i';
}
if($search!==''){
    $where[]="
        (
            ps.sale_no LIKE ?
            OR ps.patient_name LIKE ?
            OR ps.uhid_op_no LIKE ?
            OR pr.receipt_no LIKE ?
            OR pi.invoice_no LIKE ?
            OR ps.mobile LIKE ?
        )
    ";

    $searchValue='%'.$search.'%';

    $params[]=$searchValue;
    $params[]=$searchValue;
    $params[]=$searchValue;
    $params[]=$searchValue;
    $params[]=$searchValue;
    $params[]=$searchValue;

    $types.='ssssss';

}

$whereSql=implode(' AND ',$where);

$sql="
SELECT
    ps.id,
    ps.sale_no,
    ps.sale_date,
    ps.patient_type,
    ps.sale_against,
    ps.patient_id,
    ps.appointment_id,
    ps.prescription_id,
    ps.patient_name,
    ps.mobile,
    ps.uhid_op_no,
    ps.branch_id,
    ps.store_id,
    ps.sub_total,
    ps.total_discount,
    ps.total_gst,
    ps.round_off,
    ps.grand_total,
    ps.status,

    pi.invoice_no,

    COALESCE(
        SUM(pr.received_amount),
        0
    ) AS received_amount,

    GREATEST(
        ps.grand_total-
        COALESCE(SUM(pr.received_amount),0),
        0
    ) AS balance_amount,

    CASE
        WHEN ps.grand_total-
             COALESCE(SUM(pr.received_amount),0)<=0
            THEN 'Paid'

        WHEN COALESCE(SUM(pr.received_amount),0)>0
            THEN 'Partial'

        ELSE 'Pending'
    END AS payment_status,

    GROUP_CONCAT(
        DISTINCT pr.payment_mode
        ORDER BY pr.id
        SEPARATOR ', '
    ) AS payment_mode

FROM pharmacy_sales ps

LEFT JOIN pharmacy_invoices pi
    ON pi.id=ps.invoice_id

LEFT JOIN pharmacy_receipts pr
    ON pr.sale_id=ps.id

WHERE $whereSql

GROUP BY
    ps.id,
    ps.sale_no,
    ps.sale_date,
    ps.patient_type,
    ps.sale_against,
    ps.patient_id,
    ps.appointment_id,
    ps.prescription_id,
    ps.patient_name,
    ps.mobile,
    ps.uhid_op_no,
    ps.branch_id,
    ps.store_id,
    ps.sub_total,
    ps.total_discount,
    ps.total_gst,
    ps.round_off,
    ps.grand_total,
    ps.status,
    pi.invoice_no

ORDER BY
    ps.id DESC
";

$stmt=$conn->prepare($sql);

if(!$stmt){

    die(
        'Unable to load pharmacy sales: '.
        htmlspecialchars($conn->error)
    );

}

if(!empty($params)){

    $stmt->bind_param(
        $types,
        ...$params
    );

}

$stmt->execute();

$result=$stmt->get_result();

/*
 * IMPORTANT:
 * Load all records into an array first.
 * This prevents the result set from being
 * accidentally consumed before the HTML loop.
 */

$sales=$result->fetch_all(MYSQLI_ASSOC);

function e($value){

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );

}

$currentDate=new DateTime($selected_date);

$previousDate=(clone $currentDate)
    ->modify('-1 day')
    ->format('Y-m-d');

$nextDate=(clone $currentDate)
    ->modify('+1 day')
    ->format('Y-m-d');

$today=date('Y-m-d');

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<title>Pharmacy Sales</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<link
    rel="stylesheet"
    href="/assets/css/style.css"
>

<link
    rel="icon"
    href="/assets/img/hms_logo.png"
>

<style>

.main-content{
    width:auto;
    overflow:hidden;
}

.page-container{
    padding:8px;
}

*{
    box-sizing:border-box;
}

body{
    overflow-x:hidden;
}

.sales-header{
    display:flex;
    align-items:center;
    gap:10px;
}
.sales-title{
    flex:1;
}
.sales-date-navigation{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    flex:1;
}

.sales-date-navigation button{
    border:none;
    background:none;
    color:#0000dd;
    font-size:38px;
    font-weight:bold;
    cursor:pointer;
    line-height:1;
    padding:0 4px;
}

.sales-date-box{
    min-width:160px;
    height:30px;
    border-radius:7px;
    background:#f1f3f7;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    font-size:18px;
    font-weight:700;
    color:#111;
}
.sales-search-area{
    flex:1;
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:8px;
}

.sales-search-form{
    display:flex;
    align-items:center;
    gap:8px;
}

.table-wrap{
    background:#fff;
    border-radius:10px;
    padding:1px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    overflow:auto;
    width:100%;
    max-width:100%;
    height:calc(100vh - 155px);
}

th {
    background: #1976d2;
    color: #fff;
    position: sticky;
    top: 0;
    z-index: 2;
}

.action-menu{
    position:relative;
    display:inline-block;
}
.action-dropdown{
    display:none;
    position:absolute;
    left:0;
    top:40px;
    min-width:170px;
    background:#fff;
    border:1px solid #ddd;
    border-radius:5px;
    box-shadow:0 5px 15px rgba(0,0,0,.18);
    z-index:99999;
    overflow:hidden;
}

.action-dropdown button{
    display:block;
    width:100%;
    padding:9px 12px;
    border:none;
    background:#fff;
    color:#111;
    text-align:left;
    font-size:13px;
    cursor:pointer;
}

.action-dropdown button:hover{
    background:#f5f5f5;
}

.action-dropdown .cancel-action{
    color:#dc2626;
}

.sale-no{
    color:#1769c7;
    font-weight:700;
}
.h2
{
    text-align: left;
}
.patient-name{
    font-weight:700;
}

.patient-mobile{
    margin-top:2px;
    color:#777;
    font-size:12px;
}

.type-badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:15px;
    background:#e9eef4;
    color:#333;
    font-size:12px;
    font-weight:700;
}

.status{
    display:inline-block;
    padding:5px 11px;
    border-radius:18px;
    font-size:12px;
    font-weight:700;
}

.status-paid{
    background:#d4edda;
    color:#176b2c;
}

.status-partial{
    background:#fff3cd;
    color:#856404;
}
.status-cancelled{
    background:#e5e7eb;
    color:#991b1b;
}
.status-pending{
    background:#f8d7da;
    color:#842029;
}

.amount{
    font-weight:600;
}

.empty-row{
    height:100px !important;
    text-align:center !important;
    color:#777 !important;
}
.sale-row-active{
    background:#FFFFFF;
}

.sale-row-cancelled{
    background:#FFF1F2;
}

.sale-row-cancelled td{
    color:#7F1D1D;
}

.sale-row-cancelled .sale-no{
    color:#B91C1C;
}
.sales-count{
    padding:8px 4px;
    color:#777;
    font-size:13px;
}

@media(max-width:1500px){

    .sales-title h2{
        font-size:27px;
    }

    .sales-date-box{
        min-width:180px;
    }

    .sales-search-input{
        width:270px;
    }

}

@media(max-width:1100px){

    .sales-header{
        flex-wrap:wrap;
    }

    .sales-title{
        flex:1 1 100%;
    }

    .sales-date-navigation{
        flex:1;
    }

    .sales-search-area{
        flex:1;
    }

}

</style>

</head>

<body>

<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php';
?>

<div
    class="main-content"
    id="mainContent"
>
<div class="page-container">
<div class="sales-header">
<div>
<h2>💊 Pharmacy Sales</h2>
</div>
<div class="sales-date-navigation">
<button
    type="button"
    onclick="goToDate('<?=e($previousDate)?>')"
>
‹
</button>
<div class="sales-date-box">
<span>
<?=e($currentDate->format('d M Y'))?>
</span>

<span class="sales-date-icon">
📅
</span>
</div>
<button
    type="button"
    onclick="goToDate('<?=e($nextDate)?>')"
>
›
</button>

</div>

<div class="sales-search-area">

<form
    method="GET"
    class="sales-search-form"
>

<input
    type="hidden"
    name="date"
    value="<?=e($selected_date)?>"
>

<input
    type="text"
    name="search"
    value="<?=e($search)?>"
    class="sales-search-input"
    placeholder="Search Sale No. / UHID / Patient..."
>

<button
    type="submit"
    class="sales-search-button"
    title="Search"
>
🔍
</button>

</form>

<button
    type="button"
    class="sales-today-button"
    onclick="goToDate('<?=e($today)?>')"
>
📅 Today
</button>
<?php if(checkPermission('pharmacy_sales','add')){ ?>
<button
    type="button"
    onclick="location.href='sale_bill.php'"
    style="
        height:30px;
        padding:0 16px;
        border:none;
        border-radius:6px;
        background:#1976d2;
        color:#fff;
        font-size:14px;
        font-weight:700;
        cursor:pointer;
    "
>
+ Add Sale
</button>
<?php } ?>

</div>

</div>

<div class="table-wrap">

<table class="sales-table">

<thead>

<tr>

<th>Action</th>

<th>S.No</th>

<th>Sale No.</th>

<th>Sale Date</th>

<th>Patient</th>

<th>UHID / OPD No.</th>

<th>Patient Type</th>

<th>Sale Against</th>

<th>Invoice No.</th>

<th>Sub Total</th>

<th>Discount</th>

<th>GST</th>

<th>Grand Total</th>

<th>Received</th>

<th>Balance</th>

<th>Status</th>

</tr>

</thead>

<tbody>

<?php

if(empty($sales)){

?>

<tr>

<td
    colspan="16"
    class="empty-row"
>
No Pharmacy Sales Found
</td>

</tr>

<?php

}else{

$serial=1;

foreach($sales as $row){

if($row['status']==='Cancelled'){

    $paymentStatus='Cancelled';
    $statusClass='status-cancelled';

}else{

    $paymentStatus=$row['payment_status'];

    if($paymentStatus==='Paid'){

        $statusClass='status-paid';

    }elseif($paymentStatus==='Partial'){

        $statusClass='status-partial';

    }else{

        $statusClass='status-pending';

    }

}
?>

<tr class="<?= $row['status']==='Cancelled' ? 'sale-row-cancelled' : 'sale-row-active' ?>">

<td class="action-cell">

<div class="action-menu">

<button
    type="button"
    class="action-btn"
    onclick="toggleActionMenu(this)"
>
⋮
</button>

<div class="action-dropdown">

<button
    type="button"
    onclick="printReceipt(
        <?= (int)$row['id'] ?>
    )"
>
🖨 Print Receipt
</button>
<?php if($row['status']==='Active' && checkPermission('pharmacy_sales','edit')){ ?>
<button
    type="button"
    class="cancel-action"
    onclick="cancelSale(
        <?= (int)$row['id'] ?>,
        '<?=e($row['sale_no'])?>'
    )"
>
✖ Cancel Sale
</button>
<?php } ?>
</div>

</div>

</td>

<td class="text-center">

<?= $serial++ ?>

</td>

<td class="sale-no">

<?=e($row['sale_no'])?>

</td>

<td>

<?=e(
    date(
        'd-m-Y H:i',
        strtotime($row['sale_date'])
    )
)?>

</td>

<td class="patient-name">

<?=e($row['patient_name'])?>

<?php if(!empty($row['mobile'])){ ?>

<div class="patient-mobile">

<?=e($row['mobile'])?>

</div>

<?php } ?>

</td>

<td>

<?=e(
    $row['uhid_op_no']
    ?: '-'
)?>

</td>

<td>

<span class="type-badge">

<?=e($row['patient_type'])?>

</span>

</td>

<td>

<?=e($row['sale_against'])?>

</td>

<td>

<?=e(
    $row['invoice_no']
    ?: '-'
)?>

</td>

<td class="text-right">

₹<?=number_format(
    (float)$row['sub_total'],
    2
)?>

</td>

<td class="text-right">

₹<?=number_format(
    (float)$row['total_discount'],
    2
)?>

</td>

<td class="text-right">

₹<?=number_format(
    (float)$row['total_gst'],
    2
)?>

</td>

<td class="text-right amount">

₹<?=number_format(
    (float)$row['grand_total'],
    2
)?>

</td>

<td class="text-right amount">

₹<?=number_format(
    (float)$row['received_amount'],
    2
)?>

</td>

<td class="text-right amount">

₹<?=number_format(
    (float)$row['balance_amount'],
    2
)?>

</td>

<td>

<span class="status <?=$statusClass?>">

<?=e($paymentStatus)?>

</span>

</td>

</tr>

<?php

}

}

?>

</tbody>

</table>

</div>

<?php if(!empty($sales)){ ?>

<div class="sales-count">

Showing
<strong><?=count($sales)?></strong>
sale(s)

</div>

<?php } ?>

</div>

</div>

<script>

function goToDate(date)
{

    const search=
        <?=json_encode($search)?>;

    let url=
        '?date='+
        encodeURIComponent(date);

    if(search){

        url+=
            '&search='+
            encodeURIComponent(search);

    }

    window.location.href=url;

}

function toggleActionMenu(btn)
{

    document
    .querySelectorAll('.action-dropdown')
    .forEach(function(menu){

        if(
            menu !==
            btn.nextElementSibling
        ){

            menu.style.display='none';

        }

    });

    const menu=
        btn.nextElementSibling;

    if(
        menu.style.display ===
        'block'
    ){

        menu.style.display='none';

    }else{

        menu.style.display='block';

    }

}

document.addEventListener(
    'click',
    function(e){

        if(
            !e.target.closest(
                '.action-menu'
            )
        ){

            document
            .querySelectorAll(
                '.action-dropdown'
            )
            .forEach(function(menu){

                menu.style.display='none';

            });

        }

    }
);

function printReceipt(saleId)
{

    window.open(
        'pharmacy_receipt.php?sale_id='+
        encodeURIComponent(saleId),
        '_blank'
    );

}

function cancelSale(
    saleId,
    saleNo
){

    fetch(
        'ajax/get_pharmacy_sale_payment.php?sale_id='+
        encodeURIComponent(saleId)
    )
    .then(function(response){

        return response.json();

    })
    .then(function(data){

        if(!data.status){

            throw new Error(
                data.message||
                'Unable to load payment details.'
            );

        }

        document
        .getElementById('cancelSaleId')
        .value=data.sale_id;

        document
        .getElementById('cancelSaleNo')
        .textContent=data.sale_no;

        document
        .getElementById('receivedAmount')
        .value=parseFloat(
            data.received_amount||0
        ).toFixed(2);

        document
        .getElementById('returnAmount')
        .value=parseFloat(
            data.received_amount||0
        ).toFixed(2);

        document
        .getElementById('cancelReason')
        .value='';

        document
        .getElementById('returnPaymentMode')
        .value='';

        document
        .getElementById('cancelSaleModal')
        .style.display='flex';

    })
    .catch(function(error){

        console.error(error);

        alert(
            error.message||
            'Unable to load sale payment details.'
        );

    });

}
function closeCancelSaleModal()
{

    document
    .getElementById('cancelSaleModal')
    .style.display='none';

}
function submitCancelSale()
{

    const saleId=
        document
        .getElementById('cancelSaleId')
        .value;

    const saleNo=
        document
        .getElementById('cancelSaleNo')
        .textContent;

    const reason=
        document
        .getElementById('cancelReason')
        .value
        .trim();

    const returnMode=
        document
        .getElementById('returnPaymentMode')
        .value;

    const receivedAmount=
        parseFloat(
            document
            .getElementById('receivedAmount')
            .value||0
        );

    const returnAmount=
        parseFloat(
            document
            .getElementById('returnAmount')
            .value||0
        );

    if(!reason){

        alert(
            'Please enter the cancellation reason.'
        );

        return;

    }

    if(!returnMode){

        alert(
            'Please select Return Mode of Payment.'
        );

        return;

    }

    if(returnAmount<0){

        alert(
            'Return amount cannot be negative.'
        );

        return;

    }


    if(
        returnAmount>
        receivedAmount
    ){

        alert(
            'Return amount cannot be greater than received amount.'
        );

        return;

    }

    if(
        !confirm(
            'Cancel Sale '+saleNo+'?\n\n'+
            'The sale will be cancelled and stock will be restored.'
        )
    ){

        return;

    }

    const formData=
        new URLSearchParams();

    formData.append(
        'sale_id',
        saleId
    );

    formData.append(
        'cancellation_reason',
        reason
    );

    formData.append(
        'return_payment_mode',
        returnMode
    );

    formData.append(
        'return_amount',
        returnAmount.toFixed(2)
    );

    formData.append(
        'csrf',
        document.getElementById('cancelSaleCsrf').value
    );

    fetch(
        'ajax/cancel_pharmacy_sale.php',
        {
            method:'POST',

            headers:{
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },

            body:formData.toString()
        }
    )
    .then(function(response){

        return response.json();

    })
    .then(function(data){

        if(!data.status){

            throw new Error(
                data.message||
                'Unable to cancel sale.'
            );

        }

        alert(
            data.message||
            'Sale cancelled successfully.'
        );

        closeCancelSaleModal();

        window.location.reload();

    })
    .catch(function(error){

        console.error(error);

        alert(
            error.message||
            'Unable to cancel sale.'
        );

    });

}
function updateCancelAmounts()
{

    const receivedAmount=
        parseFloat(
            document
            .getElementById('receivedAmount')
            .value||0
        );

    const returnMode=
        document
        .getElementById('returnPaymentMode')
        .value;

    const returnAmountField=
        document
        .getElementById('returnAmount');

    const creditField=
        document
        .getElementById('creditExpectedAmount');

    if(returnMode==='Credit'){

        returnAmountField.value=
            '0.00';

        creditField.value=
            receivedAmount>0
            ? '0.00'
            : creditField.value;

        return;

    }

    returnAmountField.value=
        receivedAmount.toFixed(2);

}
</script>
<div
    id="cancelSaleModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.55);
        z-index:999999;
        align-items:center;
        justify-content:center;
    "
>

<div
    style="
        width:480px;
        max-width:95%;
        background:#fff;
        border-radius:10px;
        box-shadow:0 15px 40px rgba(0,0,0,.3);
        overflow:hidden;
    "
>

<div
    style="
        background:#1976d2;
        color:#fff;
        padding:14px 18px;
        font-size:18px;
        font-weight:700;
        display:flex;
        justify-content:space-between;
        align-items:center;
    "
>

<span>
Cancel Sale
</span>

<button
    type="button"
    onclick="closeCancelSaleModal()"
    style="
        border:none;
        background:transparent;
        color:#fff;
        font-size:25px;
        cursor:pointer;
    "
>
×
</button>

</div>

<div style="padding:18px;">

<input
    type="hidden"
    id="cancelSaleId"
>

<input
    type="hidden"
    id="cancelSaleCsrf"
    value="<?= htmlspecialchars((string)($_SESSION['csrf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
>

<div style="margin-bottom:12px;">

<strong>
Sale No:
</strong>

<span id="cancelSaleNo"></span>

</div>

<div style="margin-bottom:14px;">

<label
    for="cancelReason"
    style="display:block;margin-bottom:5px;font-weight:600;"
>
Cancellation Reason / Comment
<span style="color:red;">*</span>
</label>

<textarea
    id="cancelReason"
    rows="3"
    style="
        width:100%;
        padding:9px;
        border:1px solid #ccc;
        border-radius:6px;
        resize:vertical;
    "
    placeholder="Enter reason for cancelling this sale..."
></textarea>

</div>

<div style="margin-bottom:14px;">

<label
    for="returnPaymentMode"
    style="display:block;margin-bottom:5px;font-weight:600;"
>
Return Mode of Payment
<span style="color:red;">*</span>
</label>

<select
    id="returnPaymentMode"
    onchange="updateCancelAmounts()"
    style="
        width:100%;
        height:40px;
        padding:5px 9px;
        border:1px solid #ccc;
        border-radius:6px;
    "
>

<option value="">
Select Return Mode
</option>

<option value="Cash">
Cash
</option>

<option value="UPI">
UPI
</option>

<option value="Card">
Card
</option>

<option value="Cheque">
Cheque
</option>

<option value="NEFT">
NEFT
</option>

<option value="Credit">
Credit
</option>

</select>

</div>
<div style="margin-bottom:14px;">

<label
    for="receivedAmount"
    style="display:block;margin-bottom:5px;font-weight:600;"
>
Received Amount
</label>

<input
    type="number"
    id="receivedAmount"
    value="0.00"
    readonly
    style="
        width:100%;
        height:40px;
        padding:8px 10px;
        border:1px solid #ccc;
        border-radius:6px;
        background:#f5f5f5;
        font-weight:600;
    "
>

</div>
<div style="margin-bottom:14px;">

<label
    for="returnAmount"
    style="display:block;margin-bottom:5px;font-weight:600;"
>
Return Amount
</label>

<input
    type="number"
    id="returnAmount"
    min="0"
    step="0.01"
    value="0.00"
    readonly
    style="
        width:100%;
        height:40px;
        padding:8px 10px;
        border:1px solid #ccc;
        border-radius:6px;
        background:#f5f5f5;
        font-weight:600;
    "
>

</div>
<div
    style="
        display:flex;
        justify-content:flex-end;
        gap:8px;
        margin-top:18px;
    "
>

<button
    type="button"
    onclick="closeCancelSaleModal()"
    style="
        padding:9px 16px;
        border:1px solid #ccc;
        border-radius:6px;
        background:#fff;
        cursor:pointer;
    "
>
Close
</button>

<button
    type="button"
    onclick="submitCancelSale()"
    style="
        padding:9px 16px;
        border:none;
        border-radius:6px;
        background:#dc3545;
        color:#fff;
        font-weight:600;
        cursor:pointer;
    "
>
Cancel Sale
</button>

</div>

</div>

</div>

</div>
</body>

</html>