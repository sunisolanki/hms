    <?php

header('Content-Type: application/json');

ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success'=>[],
        'failed'=>[],
        'saved_count'=>0,
        'failed_count'=>1
    ]);
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
$successRows = [];
$failedRows = [];
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

/* REQUIRED VALIDATION */
if($item_name==''){
    $failedRows[$k] = 'Item Name Required';
    continue;
}
if($category==''){
    $failedRows[$k] = 'Category Required';
    continue;
}
if($store==''){
    $failedRows[$k] = 'Store Required';
    continue;
}
if($packing==''){
    $failedRows[$k] = 'Packing Required';
    continue;
}
if($vendor==''){
    $failedRows[$k] = 'Vendor Required';
    continue;
}
if($hsn_code==''){
    $failedRows[$k] = 'HSN Code Required';
    continue;
}
if($status==''){
    $failedRows[$k] = 'Status Required';
    continue;
}
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
        $failedRows[$k] =
'Duplicate Item: '.$item_name;
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
        $successRows[] = $k;
            }else{
        $failedRows[$k] = 'Failed to save';
            }
}
echo json_encode([

    'success' => $successRows,

    'failed' => $failedRows,

    'saved_count' => count($successRows),

    'failed_count' => count($failedRows)

]);
?>
