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
$hospital_id = $_GET['hospital_id'] ?? '';
$branch_id   = $_GET['branch_id'] ?? '';
$doctor_id   = $_GET['doctor_id'] ?? '';
$day         = $_GET['day'] ?? '';
$where = "WHERE 1=1";
if($doctor_id){
    $where .= " AND dm.id='".intval($doctor_id)."'";
}
if($day){
    $where .= " AND dwd.day_of_week='".mysqli_real_escape_string($conn,$day)."'";
}
/* ==============================
DOCTOR SCHEDULE LIST
==============================*/
$res = mysqli_query($conn,"
SELECT
dm.id AS doctor_id,
u.full_name,
dwd.day_of_week,
dds.start_time,
dds.end_time,
GROUP_CONCAT(
    CONCAT(
        dsb.break_start,
        ' - ',
        dsb.break_end
    )
    SEPARATOR '<br>'
) AS breaks
FROM doctor_master dm
LEFT JOIN users u
ON u.id = dm.user_id
LEFT JOIN doctor_working_days dwd
ON dwd.doctor_id = dm.id
LEFT JOIN doctor_day_schedules dds
ON dds.working_day_id = dwd.id
LEFT JOIN doctor_schedule_breaks dsb
ON dsb.schedule_id = dds.id
$where
GROUP BY dds.id
ORDER BY
u.full_name,
FIELD(
    dwd.day_of_week,
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday'
)
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
$pdf->SetTitle('Doctor Schedule List');
$pdf->SetMargins(10,10,10);
$pdf->AddPage();
$html = '
<h2 style="text-align:center;">
Doctor Schedule List
</h2>
<table border="1" cellpadding="6">
<tr style="background-color:#f1f1f1;">
<th><b>Doctor</b></th>
<th><b>Day</b></th>
<th><b>Start Time</b></th>
<th><b>End Time</b></th>
<th><b>Breaks</b></th>
</tr>
';
mysqli_data_seek($res,0);
while($row=mysqli_fetch_assoc($res)){
$html .= '
<tr>
<td>'.$row['full_name'].'</td>
<td>'.$row['day_of_week'].'</td>
<td>'.date('h:i A',strtotime($row['start_time'])).'</td>
<td>'.date('h:i A',strtotime($row['end_time'])).'</td>
<td>'.$row['breaks'].'</td>
</tr>
';
}
$html .= '</table>';
$pdf->writeHTML($html,true,false,true,false,'');
$pdf->Output('doctor_schedule_list.pdf','I');
exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Doctor Schedule List</title>
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
.schedule-time{
    font-weight:600;
    color:#0d6efd;
}
.break-box{
    color:#dc3545;
    font-size:13px;
    line-height:18px;
}
</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<body>
<div class="main-content">
<div class="page-container">
<div class="page-header">
<h2>🩺 Doctor Schedule List</h2>
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
<select name="day">
<option value="">Day</option>
<?php
$days = [
'Monday',
'Tuesday',
'Wednesday',
'Thursday',
'Friday',
'Saturday',
'Sunday'
];
foreach($days as $d){
?>
<option value="<?= $d ?>"
<?= $day==$d?'selected':'' ?>>
<?= $d ?>
</option>
<?php } ?>
</select>
<button class="btn btn-primary">
<i class="fa fa-filter"></i>
</button>
<a href="doctor_schedule_list.php"
class="btn btn-secondary">
<i class="fa fa-rotate-right"></i>
</a>
<a class="btn btn-success"
href="?download_pdf=1&doctor_id=<?= $doctor_id ?>&day=<?= $day ?>"
target="_blank">
<i class="fa fa-file-pdf"></i>
</a>
<div id="exportButtons"></div>
<div id="tableSearch"></div>
</form>
<!-- TABLE -->
<div class="table-wrap">
<table id="scheduleTable">
<thead>
<tr>
<th>Doctor</th>
<th>Day</th>
<th>Start Time</th>
<th>End Time</th>
<th>Breaks</th>
</tr>
</thead>
<tbody>
<?php while($row=mysqli_fetch_assoc($res)){ ?>
<tr>
<td><?= $row['full_name'] ?></td>
<td><?= $row['day_of_week'] ?></td>
<td class="schedule-time">
<?= date('h:i A',strtotime($row['start_time'])) ?>
</td>
<td class="schedule-time">
<?= date('h:i A',strtotime($row['end_time'])) ?>
</td>
<td class="break-box">
<?= $row['breaks'] ?: '-' ?>
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
    var table = $('#scheduleTable').DataTable({
        pageLength:25,
        responsive:true,
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