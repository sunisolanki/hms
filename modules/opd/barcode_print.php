<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();


require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/barcode_field_mapper.php';

require_once $_SERVER['DOCUMENT_ROOT'].'/vendor/autoload.php';

require_once $_SERVER['DOCUMENT_ROOT'].'/vendor/tcpdf/TCPDF-main/tcpdf.php';

$appointment_id = (int)($_GET['id'] ?? 0);

if(!$appointment_id){
    exit('Invalid Appointment');
}

$sql = "
SELECT
a.*,
p.uhid,

u.full_name AS doctor_name,

d.department_name,
d.hospital_id,
d.branch_id,

h.hospital_name,
h.logo,

b.branch_name

FROM appointments a

LEFT JOIN patient_master p
ON p.id = a.patient_id

LEFT JOIN users u
ON u.id = a.entity_id

LEFT JOIN department_master d
ON d.id = a.department_id

LEFT JOIN hospital_master h
ON h.id = d.hospital_id

LEFT JOIN branch_master b
ON b.id = d.branch_id

WHERE a.id = ?
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i",$appointment_id);
$stmt->execute();

$data = $stmt->get_result()->fetch_assoc();

if(!$data){
    exit('Appointment Not Found');
}

$settingQry = $conn->prepare("
SELECT *
FROM barcode_settings
WHERE hospital_id=?
AND branch_id=?
AND module_name='OPD'
AND status='Active'
LIMIT 1
");

$settingQry->bind_param(
    "ii",
    $data['hospital_id'],
    $data['branch_id']
);

$settingQry->execute();

$setting = $settingQry
->get_result()
->fetch_assoc();

if(!$setting){
    exit('Barcode Setup Not Found');
}

$fields = json_decode(
    $setting['fields_to_show'],
    true
);

if(!is_array($fields)){
    $fields = [];
}
echo '<pre>';

echo "Appointment Data\n";
print_r($data);

echo "\n\nBarcode Setting\n";
print_r($setting);

echo "\n\nSelected Fields\n";
print_r($fields);

echo '</pre>';

exit;