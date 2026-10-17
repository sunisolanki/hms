    <?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
if(empty($_POST['rows'])){

    die("No rows received");
}

$data = $_POST['rows'];
/* =========================
MASTER ID FUNCTIONS
========================= */
function getId($conn,$table,$field,$value){
    if(trim($value)==''){
        return null;
    }
    $stmt = $conn->prepare("
    SELECT id
    FROM $table
    WHERE LOWER($field)=LOWER(?)
    LIMIT 1
    ");
    $stmt->bind_param("s",$value);
    $stmt->execute();
    $res = $stmt->get_result();
    if($row = $res->fetch_assoc()){
        return $row['id'];
    }
    return null;
}
/* =========================
SAVE COUNTS
========================= */
$success = 0;
$failed = 0;
$messages = [];
/* =========================
LOOP
========================= */
foreach($data as $k => $rowData){
    $row = $rowData;
    $item_name       = trim($row['item_name'] ?? '');
$category        = trim($row['category'] ?? '');
$sub_category    = trim($row['sub_category'] ?? '');
$item_group      = trim($row['item_group'] ?? '');
$item_type       = trim($row['item_type'] ?? '');
$store           = trim($row['store'] ?? '');
$packing         = trim($row['packing'] ?? '');
$max_level       = trim($row['max'] ?? '');
$min_level       = trim($row['min'] ?? '');
$reorder_level   = trim($row['reorder'] ?? '');
$salt            = trim($row['salt'] ?? '');
$manufacturer    = trim($row['manufacturer'] ?? '');
$vendor          = trim($row['vendor'] ?? '');
$hsn_code        = trim($row['hsn_code'] ?? '');
$risk_type       = trim($row['risk'] ?? '');
$high_risk       = trim($row['high_risk'] ?? '');
$discount        = trim($row['discount'] ?? '');
$discount_date   = trim($row['discount_date'] ?? '');
$status          = trim($row['status'] ?? '');
    /* =========================
    DUPLICATE CHECK
    ========================= */
    $check = $conn->prepare("
    SELECT id
    FROM item_master
    WHERE LOWER(item_name)=LOWER(?)
    ");
    $check->bind_param("s",$item_name);
    $check->execute();
    $dup = $check->get_result();
    if($dup->num_rows > 0){
        $failed++;
        $messages[] = [

            'status' => 'failed',
            'message' => 'Duplicate Item: '.$item_name
        ];
        continue;
    }
    /* =========================
    IDS
    ========================= */
    $category_id = getId(
        $conn,
        'category_master',
        'category_name',
        $category
    );
    $sub_category_id = getId(
        $conn,
        'category_master',
        'category_name',
        $sub_category
    );
    $manufacturer_id = getId(
        $conn,
        'party_master',
        'party_name',
        $manufacturer
    );
    $vendor_id = getId(
        $conn,
        'party_master',
        'party_name',
        $vendor
    );
    $hsn_id = getId(
        $conn,
        'hsn_master',
        'hsn_code',
        $hsn_code
    );
    /* =========================
    INSERT ITEM
    ========================= */
   $stmt = $conn->prepare("
INSERT INTO item_master(

item_name,
category_id,
sub_category_id,
item_group,
item_type,
packing,
max_level,
min_level,
reorder_level,
manufacturer_id,
vendor_id,
hsn_id,
risk_type,
high_risk,
discount_percent,
discount_wef_date,
status

) VALUES (

?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?

)
");
    $stmt->bind_param(
"siissiiiiiiissdss",
$item_name,
$category_id,
$sub_category_id,
$item_group,
$item_type,
$packing,
$max_level,
$min_level,
$reorder_level,
$manufacturer_id,
$vendor_id,
$hsn_id,
$risk_type,
$high_risk,
$discount,
$discount_date,
$status
);
    if($stmt->execute()){
        $itemId = $conn->insert_id;
        /* =========================
        STORE MAP
        ========================= */
        if($store!=''){
            $store_id = getId(
    $conn,
    'stores',
    'store_name',
    $store
);
            if($store_id){
                $m = $conn->prepare("
                INSERT INTO item_store_mapping(
                    item_id,
                    store_id
                )
                VALUES(?,?)
                ");
                $m->bind_param(
                    "ii",
                    $itemId,
                    $store_id
                );
                $m->execute();
            }
        }
        /* =========================
        SALT MAP
        ========================= */
        if($salt!=''){
            $salt_id = getId(
                $conn,
                'salts_master',
                'salt_name',
                $salt
            );
            if($salt_id){
                $m = $conn->prepare("
                INSERT INTO item_salt_mapping(
    item_id,
    salt_id
)
VALUES(?,?)
                ");

               $m->bind_param(
    "ii",
    $itemId,
    $salt_id
);
                $m->execute();
            }
        }
        $success++;
        $messages[] = [
            'status' => 'success',
            'message' => 'Imported: '.$item_name
        ];
    }else{
        $failed++;
        $messages[] = [
            'status' => 'failed',
            'message' => 'Failed: '.$item_name
        ];
    }
}
?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<!DOCTYPE html>
<html>
<head>
<title>Import Result</title>
<link rel="stylesheet"
href="/assets/css/style.css">
<style>
.result-box{
    max-width:900px;
    margin:30px auto;
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
}
.success{
    color:green;
}
.failed{
    color:red;
}
.summary{
    display:flex;
    gap:20px;
    margin-bottom:20px;
}
.summary-card{
    flex:1;
    padding:20px;
    border-radius:10px;
    text-align:center;
    color:#fff;
    font-size:20px;
    font-weight:bold;
}
.green{
    background:#28a745;
}
.red{
    background:#dc3545;
}
.log-box{
    max-height:450px;
    overflow:auto;

    border:1px solid #ddd;

    padding:15px;
    border-radius:8px;
}
.log-item{
    padding:8px 0;
    border-bottom:1px solid #eee;
}
</style>
</head>
<body>
<div class="result-box">
<h2>Item Import Result</h2>
<div class="summary">
<div class="summary-card green">
<?= $success ?>
<br>
Success
</div>
<div class="summary-card red">
<?= $failed ?>
<br>
Failed
</div>
</div>
<div class="log-box">
<?php foreach($messages as $m){ ?>
<div class="log-item <?= $m['status'] ?>">
<?= $m['message'] ?>
</div>
<?php } ?>
</div>
<br>
<a href="/masters/inventory/item_master.php"
class="btn btn-primary">
Back To Item Master
</a>
</div>
</body>
</html>