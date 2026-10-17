
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    exit('Login Required');
}
$appointment_id = (int)($_GET['id'] ?? 0);
if(!$appointment_id){
    exit('Invalid Appointment');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Barcode Preview</title>
<style>
body{
    font-family:Arial,sans-serif;
    background:#f5f5f5;
    margin:0;
    padding:20px;
}
.card{
    background:#fff;
    max-width:800px;
    margin:auto;
    padding:20px;
    border-radius:10px;
    box-shadow:0 2px 10px rgba(0,0,0,.1);
}
.btn{
    display:inline-block;
    padding:10px 15px;
    text-decoration:none;
    border-radius:5px;
    color:#fff;
    margin-right:10px;
}
.print-btn{
    background:#28a745;
}
.download-btn{
    background:#1976d2;
}
.preview-box{
    margin-top:20px;
    border:1px dashed #ccc;
    padding:20px;
    text-align:center;
}
</style>
</head>
<body>
<div class="card">
<h2>🏷 Barcode Preview</h2>
<a
href="barcode_print.php?id=<?= $appointment_id ?>"
target="_blank"
class="btn print-btn">
🖨 Print Barcode
</a>
<a
href="barcode_print.php?id=<?= $appointment_id ?>&download=1"
target="_blank"
class="btn download-btn">
⬇ Download PDF
</a>
<div class="preview-box">
<p>
Preview will be generated from
barcode_print.php
</p>
<p>
Appointment ID :
<strong><?= $appointment_id ?></strong>
</p>
</div>
</div>
</body>
</html>