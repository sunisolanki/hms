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
$category    = $_GET['category'] ?? '';
$main_service_id = $_GET['main_service_id'] ?? '';

$where = "WHERE s.status='Active'";

if($hospital_id){
    $where .= " AND s.hospital_id='".intval($hospital_id)."'";
}

if($branch_id){
    $where .= " AND s.branch_id='".intval($branch_id)."'";
}

if($category){
    $where .= " AND s.category_type='".mysqli_real_escape_string($conn,$category)."'";
}

if($main_service_id){
    $where .= " AND s.main_service_id='".intval($main_service_id)."'";
}

/* ==============================
   SERVICE LIST
==============================*/

$res = mysqli_query($conn,"
SELECT 
s.id,
s.service_name,
s.amount,
s.category_type,
h.hospital_name,
b.branch_name,
p.service_name AS main_service
FROM service_master s
LEFT JOIN hospital_master h ON h.id=s.hospital_id
LEFT JOIN branch_master b ON b.id=s.branch_id
LEFT JOIN service_master p ON p.id=s.main_service_id
$where
ORDER BY h.hospital_name,b.branch_name,s.service_name
");

/* ==============================
   DROPDOWNS
==============================*/

$hospitals = mysqli_query($conn,"
SELECT id,hospital_name 
FROM hospital_master 
WHERE status='1'
ORDER BY hospital_name
");

$branches = mysqli_query($conn,"
SELECT id,branch_name 
FROM branch_master
ORDER BY branch_name
");

$main_services = mysqli_query($conn,"
SELECT id,service_name 
FROM service_master
WHERE service_type='Main Service'
ORDER BY service_name
");

/* ==============================
   PDF DOWNLOAD
==============================*/
if(isset($_GET['download_pdf'])){

require_once $_SERVER['DOCUMENT_ROOT'].'/vendor/tcpdf/TCPDF-main/tcpdf.php';

$pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);

$pdf->SetCreator('HMS');
$pdf->SetAuthor('WebSplend HMS');
$pdf->SetTitle('Service List');

$pdf->SetMargins(10,10,10);
$pdf->AddPage();

$html = '<h2 style="text-align:center;">Service List</h2>';

$html .= '<table border="1" cellpadding="6">
<tr style="background-color:#f1f1f1;">
<th><b>Hospital</b></th>
<th><b>Branch</b></th>
<th><b>Category</b></th>
<th><b>Main Service</b></th>
<th><b>Name</b></th>
<th><b>Amount</b></th>
</tr>';

mysqli_data_seek($res,0);

while($row=mysqli_fetch_assoc($res)){

$html .= '<tr>
<td>'.$row['hospital_name'].'</td>
<td>'.$row['branch_name'].'</td>
<td>'.$row['category_type'].'</td>
<td>'.$row['main_service'].'</td>
<td>'.$row['service_name'].'</td>
<td>'.number_format($row['amount'],2).'</td>
</tr>';

}

$html .= '</table>';

$pdf->writeHTML($html,true,false,true,false,'');

$pdf->Output('service_list.pdf','I');

exit;
}

/* ==============================
   SEND EMAIL
==============================*/
if(isset($_POST['send_email'])){

$email = $_POST['email'];

/* GET HOSPITAL NAME */
$hospital_name = "Hospital";

if($hospital_id){
    $hres = mysqli_query($conn,"SELECT hospital_name FROM hospital_master WHERE id='$hospital_id'");
    if($hrow = mysqli_fetch_assoc($hres)){
        $hospital_name = $hrow['hospital_name'];
    }
}

/* RE-RUN SERVICE QUERY FOR EMAIL */
$email_res = mysqli_query($conn,"
SELECT 
s.service_name,
s.amount,
s.category_type,
h.hospital_name,
b.branch_name,
p.service_name AS main_service
FROM service_master s
LEFT JOIN hospital_master h ON h.id=s.hospital_id
LEFT JOIN branch_master b ON b.id=s.branch_id
LEFT JOIN service_master p ON p.id=s.main_service_id
$where
ORDER BY h.hospital_name,b.branch_name,s.service_name
");

/* EMAIL MESSAGE */

$message = "
<p>Dear Client,</p>

<p>Please find attached the latest <b>Service List</b> from <b>$hospital_name</b> for your reference.</p>

<p>If you need any additional information or assistance, please feel free to contact us.</p>

<br>

<table border='1' cellpadding='6' cellspacing='0'>
<tr style='background:#f1f1f1'>
<th>Hospital</th>
<th>Branch</th>
<th>Category</th>
<th>Main Service</th>
<th>Name</th>
<th>Amount</th>
</tr>
";

while($row=mysqli_fetch_assoc($email_res)){

$message .= "
<tr>
<td>{$row['hospital_name']}</td>
<td>{$row['branch_name']}</td>
<td>{$row['category_type']}</td>
<td>{$row['main_service']}</td>
<td>{$row['service_name']}</td>
<td>".number_format($row['amount'],2)."</td>
</tr>
";

}

$message .= "</table>

<br>

<p>Best Regards,<br>
$hospital_name</p>
";

/* EMAIL HEADERS */

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type:text/html;charset=UTF-8\r\n";
$headers .= "From: noreply@hms.websplend.com\r\n";

/* SUBJECT */

$subject = "Service List from $hospital_name";

/* SEND EMAIL */

mail($email,$subject,$message,$headers);

$msg="Email sent successfully!";

}
?>

<!DOCTYPE html>
<html>
<head>

<title>Service List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
 .container {
            margin: 0 auto;
            padding: 10px;
        }
        .main-content {
    margin-left: 240px; /* match sidebar width */
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
    flex-wrap: nowrap;     /* 🔥 FORCE ONE LINE */
    overflow-x: auto;      /* 🔥 scroll if needed */
}


input[name="email"]{
    width: 150px;        /* adjust as needed */
    min-width: 200px;
}

</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<body>
<div class="main-content">
    <div class="page-container">

<div class="page-header">
    <h2>📋 Service List</h2>
</div>

<?php if(!empty($msg)) echo "<p style='color:green'>$msg</p>"; ?>

<!-- FILTERS -->

<form method="get" class="filter-bar">
<select name="hospital_id">
<option value="">Hospital</option>
<?php while($h=mysqli_fetch_assoc($hospitals)){ ?>
<option value="<?= $h['id'] ?>" <?= $hospital_id==$h['id']?'selected':'' ?>>
<?= $h['hospital_name'] ?>
</option>
<?php } ?>
</select>


<select name="branch_id">
<option value="">Branch</option>
<?php while($b=mysqli_fetch_assoc($branches)){ ?>
<option value="<?= $b['id'] ?>" <?= $branch_id==$b['id']?'selected':'' ?>>
<?= $b['branch_name'] ?>
</option>
<?php } ?>
</select>


<select name="category">
<option value="">Category</option>
<option <?= $category=='Laboratory'?'selected':'' ?>>Laboratory</option>
<option <?= $category=='Radiology'?'selected':'' ?>>Radiology</option>
<option <?= $category=='Procedure'?'selected':'' ?>>Procedure</option>
<option <?= $category=='Physiotherapy'?'selected':'' ?>>Physiotherapy</option>
</select>


<select name="main_service_id">
<option value="">Main Service</option>
<?php while($m=mysqli_fetch_assoc($main_services)){ ?>
<option value="<?= $m['id'] ?>" <?= $main_service_id==$m['id']?'selected':'' ?>>
<?= $m['service_name'] ?>
</option>
<?php } ?>
</select>

<button class="btn btn-primary" title="Filter">
    <i class="fa fa-filter"></i>
</button>

<a class="btn btn-secondary" href="?" title="Reset">
    <i class="fa fa-rotate-right"></i>
</a>

<a class="btn btn-success"
href="?download_pdf=1&hospital_id=<?= $hospital_id ?>&branch_id=<?= $branch_id ?>&category=<?= $category ?>&main_service_id=<?= $main_service_id ?>"
target="_blank">
<i class="fa fa-file-pdf"></i>
</a>
<div id="exportButtons"></div>
<!-- EMAIL -->
<input type="email" name="email" placeholder="Enter Email">
<button class="btn btn-primary" name="send_email" formmethod="post">
<i class="fa fa-paper-plane"></i>
</button>
<!-- SEARCH -->
<div id="tableSearch"></div>
</form>
<!-- TABLE -->
<div class="table-wrap">
<table id="serviceTable">
<thead>
<tr>
<th>Hospital</th>
<th>Branch</th>
<th>Category</th>
<th>Main Service</th>
<th>Name</th>
<th>Amount</th>
</tr>
</thead>
<tbody>
<?php while($row=mysqli_fetch_assoc($res)){ ?>
<tr>
<td><?= $row['hospital_name'] ?></td>
<td><?= $row['branch_name'] ?></td>
<td><?= $row['category_type'] ?></td>
<td><?= $row['main_service'] ?></td>
<td><?= $row['service_name'] ?></td>
<td><?= number_format($row['amount'],2) ?></td>
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
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script>
$(document).ready(function(){

    var table = $('#serviceTable').DataTable({
        pageLength: 25,
        responsive: true,
        dom: '<"top"f>t<"bottom"ip>',
        buttons: [
            { extend: 'excel', text: '<i class="fa fa-file-excel"></i>' },
            { extend: 'print', text: '<i class="fa fa-print"></i>' }
        ]
    });

    // move export buttons
    table.buttons().container().appendTo('#exportButtons');

    // move search box
    $('.dataTables_filter').appendTo('#tableSearch');

});
</script>
</body>
</html>