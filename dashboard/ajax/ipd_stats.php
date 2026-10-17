<?php
ini_set('display_errors',0);
ini_set('display_startup_errors',0);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
header('Content-Type: application/json');
if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}
$sql="
SELECT
    COUNT(*) AS total_beds,
    COALESCE(SUM(CASE WHEN status='Occupied' THEN 1 ELSE 0 END),0) AS occupied_beds,
    COALESCE(SUM(CASE WHEN status='Available' THEN 1 ELSE 0 END),0) AS available_beds,
    COALESCE(SUM(CASE WHEN status='Maintenance' THEN 1 ELSE 0 END),0) AS maintenance_beds
FROM bed_master
";
$result=$conn->query($sql);
if(!$result){
    http_response_code(500);
    echo json_encode(['error'=>$conn->error]);
    exit;
}
$bed=$result->fetch_assoc();
$admittedSql="
SELECT COUNT(*) AS admitted_patients
FROM ipd_admissions
WHERE status='Admitted'
";
$admittedResult=$conn->query($admittedSql);
$admittedPatients=$admittedResult
    ?(int)($admittedResult->fetch_assoc()['admitted_patients']??0)
    :0;
$totalBeds=(int)($bed['total_beds']??0);
$occupiedBeds=(int)($bed['occupied_beds']??0);
$availableBeds=(int)($bed['available_beds']??0);
$maintenanceBeds=(int)($bed['maintenance_beds']??0);
$occupancyPercent=$totalBeds>0
    ?round(($occupiedBeds/$totalBeds)*100)
    :0;
echo json_encode([
    'total_beds'=>$totalBeds,
    'occupied_beds'=>$occupiedBeds,
    'available_beds'=>$availableBeds,
    'maintenance_beds'=>$maintenanceBeds,
    'admitted_patients'=>$admittedPatients,
    'vacant_beds'=>$availableBeds,
    'occupancy_percent'=>$occupancyPercent
]);
exit;