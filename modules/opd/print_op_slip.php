<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);


date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$id = (int)($_GET['id'] ?? 0);
$current_user_id = $_SESSION['user_id'];

/* ==============================
   FETCH COMPLETE DATA
============================== */

$stmt = $conn->prepare("
    SELECT 
        a.*,
        p.uhid,
        d.full_name AS doctor_name,
        cu.full_name AS created_by_name,
        ru.full_name AS refund_by_name,
        h.hospital_name,
        h.address,
        h.city,
        h.state,
        h.pincode,
        h.mobile AS hospital_mobile,
        h.logo,

        -- Internal referral
        iu.full_name AS internal_referral_name,

        -- External referral
        rm.name AS external_referral_name,

        -- Empanelment
        em.empanelment_name,

        -- Discount
        dm.discount_name

    FROM appointments a
    LEFT JOIN patient_master p ON a.patient_id = p.id
    LEFT JOIN users d ON a.entity_id = d.id
    LEFT JOIN users cu ON a.created_by = cu.id
    LEFT JOIN users ru ON a.refund_by = ru.id
    LEFT JOIN user_hospitals uh ON uh.user_id = d.id
    LEFT JOIN hospital_master h ON h.id = uh.hospital_id

    LEFT JOIN users iu ON a.internal_referral_id = iu.id
    LEFT JOIN referral_master rm ON a.external_referral_id = rm.id
    LEFT JOIN empanelment_master em ON a.empanelment_id = em.id
    LEFT JOIN discount_master dm ON a.discount_type = dm.id

    WHERE a.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if (!$row = $result->fetch_assoc()) {
    die("Record not found");
}

$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
<title>OP Slip</title>

<style>

body{
    font-family: Arial, sans-serif;
    margin:0;
    padding:0;
}

.page{
    width: 148mm;   /* A5 width */
    height: 210mm;  /* A5 height */
    margin:auto;
    padding:15px;
    box-sizing:border-box;
}

.slip{
    border:1px solid #000;
    padding:15px;
   
}

.center{
    text-align:center;
}

.bold{
    font-weight:bold;
}

hr{
    border:none;
    border-top:1px solid #000;
    margin:10px 0;
}

table{
    width:100%;
    border-collapse:collapse;
}

td{
    padding:4px 0;
    font-size:14px;
}

.section-title{
    font-weight:bold;
    margin-top:10px;
    margin-bottom:5px;
}
.hospital-header{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:8px;
}

.hospital-logo{
    flex:0 0 auto;
}

.hospital-logo img{
    max-height:60px;
    display:block;
}

.hospital-info{
    flex:1;
}

.hospital-name{
    font-size:18px;
    font-weight:bold;
}

.hospital-address,
.hospital-phone{
    font-size:12px;
    line-height:1.3;
}
@media print {
    @page {
        size: A5 portrait;
        margin:5mm;
    }
    button{
        display:none;
    }
}

</style>
</head>
<body>

<div class="page">
<div class="slip">

<!-- ================= HOSPITAL HEADER ================= -->
<div class="hospital-header">
    <?php if(!empty($row['logo'])): ?>
        <div class="hospital-logo">
            <img src="/<?= $row['logo'] ?>" height="60">
        </div>
    <?php endif; ?>
    <div class="hospital-info">
        <div class="hospital-name">
            <?= $row['hospital_name'] ?>
        </div>
        <div class="hospital-address">
            <?= $row['address'] ?>,
            <?= $row['city'] ?>,
            <?= $row['state'] ?> -
            <?= $row['pincode'] ?>
        </div>
        <div class="hospital-phone">
            Phone: <?= $row['hospital_mobile'] ?>
        </div>
    </div>
</div>
<hr>

<hr>

<!-- ================= BASIC DETAILS ================= -->

<table>
<tr>
    <td><span class="bold">Appointment Code:</span> <?= $row['appointment_code'] ?></td>
    <td align="right"><span class="bold">OPD No:</span> <?= $row['opd_no'] ?></td>
</tr>

<tr>
    <td><span class="bold">Token:</span> <?= $row['token_no'] ?></td>
    <td align="right"><span class="bold">Date:</span> <?= date('d-m-Y', strtotime($row['appointment_date'])) ?></td>
</tr>

<tr>
    <td><span class="bold">Time:</span> <?= $row['appointment_time'] ?></td>
    <td align="right"><span class="bold">Created On:</span> <?= date('d-m-Y H:i', strtotime($row['created_at'])) ?></td>
</tr>

<tr>
    <td colspan="2"><span class="bold">Created By:</span> <?= $row['created_by_name'] ?></td>
</tr>
</table>

<hr>

<!-- ================= PATIENT DETAILS ================= -->

<div class="section-title">Patient Details</div>

<table>
<tr>
    <td><span class="bold">UHID:</span> <?= $row['uhid'] ?></td>
    <td align="right"><span class="bold">Gender:</span> <?= $row['sex'] ?></td>
</tr>

<tr>
    <td colspan="2">
        <span class="bold">Name:</span>
        <?= $row['title'].' '.$row['first_name'].' '.$row['last_name'] ?>
    </td>
</tr>

<tr>
    <td>
        <span class="bold">Age:</span>
        <?= $row['age_y'] ?>Y <?= $row['age_m'] ?>M
    </td>
    <td align="right">
        <span class="bold">Mobile:</span>
        <?= $row['mobile'] ?>
    </td>
</tr>
</table>

<hr>

<!-- ================= DOCTOR ================= -->

<div>
    <span class="bold">Doctor:</span>
     <?= $row['doctor_name'] ?>
</div>

<hr>
<div class="section-title">Referral & Discount Details</div>

<table>

<tr>
    <td><span class="bold">Referral Type:</span></td>
    <td align="right"><?= $row['referral_type'] ?? '-' ?></td>
</tr>

<tr>
    <td><span class="bold">Referred By:</span></td>
    <td align="right">
        <?php
        if ($row['referral_type'] === 'Internal') {
            echo $row['internal_referral_name'] ?? '-';
        } elseif ($row['referral_type'] === 'External') {
            echo $row['external_referral_name'] ?? '-';
        } else {
            echo '-';
        }
        ?>
    </td>
</tr>

<tr>
    <td><span class="bold">Empanelment:</span></td>
    <td align="right"><?= $row['empanelment_name'] ?? '-' ?></td>
</tr>

<tr>
    <td><span class="bold">Discount:</span></td>
    <td align="right"><?= $row['discount_name'] ?? '-' ?></td>
</tr>

<tr>
    <td><span class="bold">Discount %:</span></td>
    <td align="right"><?= $row['discount_percent'] ?? '0' ?> %</td>
</tr>

<tr>
    <td><span class="bold">Discount Amount:</span></td>
    <td align="right">₹ <?= number_format((float)($row['discount_amount'] ?? 0), 2) ?></td>
</tr>

<tr>
    <td><span class="bold">Camp (Free Consultation):</span></td>
    <td align="right"><?= $row['camp'] ? 'Yes' : 'No' ?></td>
</tr>

</table>

<!-- ================= PAYMENT DETAILS ================= -->

<div class="section-title">Payment Details</div>

<table>
<tr>
    <td><span class="bold">Consultation Fee:</span></td>
    <td align="right">₹ <?= number_format($row['consultation_fee'],2) ?></td>
</tr>
<tr>
    <td><span class="bold">Net Payable:</span></td>
    <td align="right">₹ <?= number_format((float)($row['net_payable'] ?? 0), 2) ?></td>
</tr>
<tr>
    <td><span class="bold">Paid Amount:</span></td>
    <td align="right">₹ <?= number_format($row['paid_amount'],2) ?></td>
</tr>

<tr>
    <td><span class="bold">Payment Mode:</span></td>
    <td align="right"><?= $row['payment_mode'] ?></td>
</tr>

<tr>
    <td><span class="bold">Status:</span></td>
    <td align="right"><?= $row['status'] ?></td>
</tr>
</table>

<hr>
<?php
if(
    !empty($row['refund_amount']) ||
    !empty($row['refund_mode']) ||
    !empty($row['refund_datetime'])
){
?>

<hr>

<div class="section-title">Refund Details</div>

<table>

<tr>
    <td><span class="bold">Refund Amount:</span></td>
    <td align="right">
        ₹ <?= number_format((float)$row['refund_amount'],2) ?>
    </td>
</tr>

<tr>
    <td><span class="bold">Refund Mode:</span></td>
    <td align="right">
        <?= htmlspecialchars($row['refund_mode'] ?: '-') ?>
    </td>
</tr>

<tr>
    <td><span class="bold">Refund By:</span></td>
    <td align="right">
        <?= htmlspecialchars($row['refund_by_name'] ?: '-') ?>
    </td>
</tr>

<tr>
    <td><span class="bold">Refund Date:</span></td>
    <td align="right">
        <?= !empty($row['refund_datetime']) ? date('d-m-Y H:i', strtotime($row['refund_datetime'])) : '-' ?>
    </td>
</tr>

</table>

<?php
}
?>
<div class="center" style="font-size:12px; margin-top:15px;">
    Thank You. Get Well Soon.
</div>

</div>
</div>

<div class="center" style="margin:15px;">
    <button onclick="window.print()">Print</button>
</div>

<script>
window.onload = function(){
    window.print();
}
</script>

</body>
</html>