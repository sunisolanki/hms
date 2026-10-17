<?php
header("Content-Type: application/json");

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if(!isset($_GET['department_id'])){
    echo json_encode([]);
    exit;
}

$department_id=(int)$_GET['department_id'];

$sql="
SELECT 
    dm.id AS doctor_id,
    u.full_name
FROM doctor_master dm
INNER JOIN users u
    ON u.id=dm.user_id
INNER JOIN user_departments ud
    ON ud.user_id=u.id
WHERE
    ud.department_id=?
    AND u.employee_type_id=2
    AND u.status=1
ORDER BY u.full_name
";

$stmt=$conn->prepare($sql);
$stmt->bind_param("i",$department_id);
$stmt->execute();

$result=$stmt->get_result();

$data=[];

while($row=$result->fetch_assoc()){

    $data[]=[
        'id'=>$row['doctor_id'],
        'name'=>$row['full_name']
    ];

}

echo json_encode($data);
exit;