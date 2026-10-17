
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();


require_once $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

if (
    !checkPermission('barcode_setup','add')
    && !checkPermission('barcode_setup','edit')
) {
    header('Location: /access_denied.php');
    exit;
}

$id                = (int)($_POST['id'] ?? 0);
$hospital_id       = (int)$_POST['hospital_id'];
$branch_id         = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : 'NULL';
$store_id          = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : 'NULL';
$module_id         = (int)$_POST['module_id'];

$module_name = '';

$moduleQry = mysqli_query(
    $conn,
    "SELECT name FROM modules WHERE id='$module_id' LIMIT 1"
);

if($moduleQry && mysqli_num_rows($moduleQry)){
    $moduleData = mysqli_fetch_assoc($moduleQry);
    $module_name = mysqli_real_escape_string(
        $conn,
        $moduleData['name']
    );
}

$barcode_enabled  = (int)$_POST['barcode_enabled'];

$barcode_type = mysqli_real_escape_string(
    $conn,
    $_POST['barcode_type']
);

$barcode_prefix = mysqli_real_escape_string(
    $conn,
    trim($_POST['barcode_prefix'])
);

$format_type = mysqli_real_escape_string(
    $conn,
    $_POST['format_type']
);

$generation_scope = mysqli_real_escape_string(
    $conn,
    $_POST['generation_scope']
);

$label_width_mm  = (int)$_POST['label_width_mm'];
$label_height_mm = (int)$_POST['label_height_mm'];
$next_sequence   = (int)$_POST['next_sequence'];

$status = mysqli_real_escape_string(
    $conn,
    $_POST['status']
);

$fields_to_show = json_encode(
    $_POST['fields_to_show'] ?? []
);

$fields_to_show = mysqli_real_escape_string(
    $conn,
    $fields_to_show
);

$user_id = (int)$_SESSION['user_id'];

if($id > 0){

    $sql = "
    UPDATE barcode_settings SET

        hospital_id       = '$hospital_id',
        branch_id         = ".($branch_id==='NULL' ? 'NULL' : "'$branch_id'").",
        store_id          = ".($store_id==='NULL' ? 'NULL' : "'$store_id'").",
        module_id         = '$module_id',
        module_name       = '$module_name',
        barcode_enabled   = '$barcode_enabled',
        barcode_type      = '$barcode_type',
        barcode_prefix    = '$barcode_prefix',
        format_type       = '$format_type',
        generation_scope  = '$generation_scope',
        fields_to_show    = '$fields_to_show',
        label_width_mm    = '$label_width_mm',
        label_height_mm   = '$label_height_mm',
        next_sequence     = '$next_sequence',
        status            = '$status',
        updated_at        = NOW()

    WHERE id='$id'
    ";

}else{

    $sql = "
    INSERT INTO barcode_settings
    (
        hospital_id,
        branch_id,
        store_id,
        module_id,
        module_name,
        barcode_enabled,
        barcode_type,
        barcode_prefix,
        format_type,
        generation_scope,
        fields_to_show,
        label_width_mm,
        label_height_mm,
        next_sequence,
        status,
        created_by,
        created_at
    )
    VALUES
    (
        '$hospital_id',
        ".($branch_id==='NULL' ? 'NULL' : "'$branch_id'").",
        ".($store_id==='NULL' ? 'NULL' : "'$store_id'").",
        '$module_id',
        '$module_name',
        '$barcode_enabled',
        '$barcode_type',
        '$barcode_prefix',
        '$format_type',
        '$generation_scope',
        '$fields_to_show',
        '$label_width_mm',
        '$label_height_mm',
        '$next_sequence',
        '$status',
        '$user_id',
        NOW()
    )
    ";
}

mysqli_query($conn,$sql);

header('Location: barcode_setup.php');
exit;
?>