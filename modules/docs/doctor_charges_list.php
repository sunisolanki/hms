<?php

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location:/index.php");
    exit;
}

/* ==============================
FILTERS
==============================*/

$doctor_id = $_GET['doctor_id'] ?? '';
$visit_type = $_GET['visit_type'] ?? '';

$where = "WHERE 1=1";

if($doctor_id){
    $where .= " AND dc.doctor_id=".intval($doctor_id);
}

if($visit_type){
    $where .= " AND dc.visit_type='".trim(mysqli_real_escape_string($conn,$visit_type))."'";
}

/* ==============================
DOCTOR CHARGES LIST
==============================*/

$res = mysqli_query($conn,"

SELECT

dc.*,

u.full_name,

dm.license_no,

CASE
    WHEN dm.is_surgeon='1'
    THEN 'Yes'
    ELSE 'No'
END AS surgeon_status

FROM doctor_charges dc

LEFT JOIN doctor_master dm
ON dm.id = dc.doctor_id

LEFT JOIN users u
ON u.id = dm.user_id

$where

ORDER BY
u.full_name,
dc.visit_type

");

/* ==============================
DOCTORS
==============================*/

$doctors = mysqli_query($conn,"

SELECT

dm.id,
u.full_name

FROM doctor_master dm

LEFT JOIN users u
ON u.id = dm.user_id

ORDER BY u.full_name

");

/* ==============================
PDF DOWNLOAD
==============================*/

if(isset($_GET['download_pdf'])){

require_once $_SERVER['DOCUMENT_ROOT'].'/vendor/tcpdf/TCPDF-main/tcpdf.php';

$pdf = new TCPDF('L','mm','A4',true,'UTF-8',false);

$pdf->SetCreator('HMS');
$pdf->SetAuthor('WebSplend HMS');
$pdf->SetTitle('Doctor Charges List');

$pdf->SetMargins(10,10,10);

$pdf->AddPage();

$html = '

<h2 style="text-align:center;">
Doctor Charges List
</h2>

<table border="1" cellpadding="6">

<tr style="background-color:#f1f1f1;">

<th><b>Doctor</b></th>
<th><b>License No</b></th>
<th><b>Surgeon</b></th>
<th><b>Visit Type</b></th>
<th><b>Charge Amount</b></th>
<th><b>Valid From</b></th>
<th><b>Valid To</b></th>

</tr>

';

mysqli_data_seek($res,0);

while($row=mysqli_fetch_assoc($res)){

$html .= '

<tr>

<td>'.$row['full_name'].'</td>

<td>'.$row['license_no'].'</td>

<td>'.$row['surgeon_status'].'</td>

<td>'.$row['visit_type'].'</td>

<td>'.number_format($row['charge_amount'],2).'</td>

<td>'.$row['valid_from_day'].' Days</td>

<td>'.$row['valid_to_day'].' Days</td>

</tr>

';
}

$html .= '</table>';

$pdf->writeHTML($html,true,false,true,false,'');

$pdf->Output('doctor_charges_list.pdf','I');

exit;
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Doctor Charges List</title>

<meta name="viewport"
content="width=device-width, initial-scale=1">

<link rel="stylesheet"
href="/assets/css/style.css">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="icon"
type="image/png"
href="/assets/img/hms_logo.png">

<style>

.main-content{
    margin-left:240px;
    max-width: stretch;
}

.table-wrap{
    background:#fff;
    border-radius:10px;
    padding:1px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    overflow:auto;
    height:570px;
}

.filter-bar{
    display:flex;
    gap:6px;
    align-items:center;
    flex-wrap:nowrap;
    overflow-x:auto;
}

.amount-box{
    font-weight:700;
    color:#198754;
}

.visit-box{
    font-weight:600;
    color:#0d6efd;
}

.day-box{
    color:#dc3545;
    font-weight:600;
}

</style>

</head>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<body>

<div class="main-content">

<div class="page-container">

<div class="page-header">

<h2>💰 Doctor Charges List</h2>

</div>

<!-- FILTERS -->

<form method="get" class="filter-bar">

<select name="doctor_id">

<option value="">Doctor</option>

<?php while($d=mysqli_fetch_assoc($doctors)){ ?>

<option value="<?= $d['id'] ?>"
<?= $doctor_id==$d['id']?'selected':'' ?>>

<?= $d['full_name'] ?>

</option>

<?php } ?>

</select>

<select name="visit_type">

<option value="">Visit Type</option>

<option value="NORMAL"
<?= $visit_type=='NORMAL'?'selected':'' ?>>

NORMAL

</option>

<option value="REVISIT"
<?= $visit_type=='REVISIT'?'selected':'' ?>>

REVISIT

</option>

<option value="EMERGENCY"
<?= $visit_type=='EMERGENCY'?'selected':'' ?>>

EMERGENCY

</option>
</select>
<button type="submit" class="btn btn-primary">
<i class="fa fa-filter"></i>
</button>
<a href="doctor_charges_list.php"
class="btn btn-secondary"
style="text-decoration:none;">

<i class="fa fa-rotate-right"></i>

</a>

<a class="btn btn-success"
href="?download_pdf=1&doctor_id=<?= $doctor_id ?>&visit_type=<?= $visit_type ?>"
target="_blank">

<i class="fa fa-file-pdf"></i>

</a>

<div id="exportButtons"></div>

<div id="tableSearch"></div>

</form>

<!-- TABLE -->

<div class="table-wrap">

<table id="chargesTable"
class="display nowrap"
style="width:100%">

<thead>

<tr>

<th>Doctor</th>
<th>License No</th>
<th>Surgeon</th>
<th>Visit Type</th>
<th>Charge Amount</th>
<th>Valid From Day</th>
<th>Valid To Day</th>

</tr>

</thead>

<tbody>

<?php while($row=mysqli_fetch_assoc($res)){ ?>

<tr>

<td><?= $row['full_name'] ?></td>

<td><?= $row['license_no'] ?></td>

<td><?= $row['surgeon_status'] ?></td>

<td class="visit-box">
<?= $row['visit_type'] ?>
</td>

<td class="amount-box">
₹ <?= number_format($row['charge_amount'],2) ?>
</td>

<td class="day-box">
<?= $row['valid_from_day'] ?> Days
</td>

<td class="day-box">
<?= $row['valid_to_day'] ?> Days
</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<link rel="stylesheet"
href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<script>

$(document).ready(function(){

    var table = $('#chargesTable').DataTable({

        pageLength:25,

        responsive:false,
scrollX:true,
autoWidth:false,

        dom:'<"top"f>t<"bottom"ip>',

        buttons:[

            {
                extend:'excel',
                text:'<i class="fa fa-file-excel"></i>'
            },

            {
                extend:'print',
                text:'<i class="fa fa-print"></i>'
            }
        ]
    });

    table.buttons().container().appendTo('#exportButtons');

    $('.dataTables_filter').appendTo('#tableSearch');

});

</script>

</body>
</html>