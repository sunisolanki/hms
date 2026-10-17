<?php

header("Content-Type: application/json");

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$doctor_id = (int)($_GET['doctor_id'] ?? 0);

if($doctor_id<=0){

    echo json_encode([]);

    exit;
}


$stmt=$conn->prepare("
SELECT
    visit_type,
    charge_amount
FROM doctor_charges
WHERE doctor_id=?
AND is_active=1
ORDER BY id
");


$stmt->bind_param(
    "i",
    $doctor_id
);


$stmt->execute();


$result=$stmt->get_result();


$data=[];


while($row=$result->fetch_assoc()){

    $data[]=[

        'type'=>$row['visit_type'],

        'amount'=>(float)$row['charge_amount']

    ];

}


echo json_encode($data);

exit;