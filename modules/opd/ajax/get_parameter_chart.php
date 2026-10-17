<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$appointment_id=(int)($_GET['appointment_id'] ?? 0);
$parameter_id=(int)($_GET['parameter_id'] ?? 0);
$response=[
'latest'=>'--',
'highest'=>'--',
'lowest'=>'--',
'average'=>'--',
'chart'=>[],
'history'=>[]
];
$sql=mysqli_query($conn,"
SELECT
pr.reading,
pr.reading_date,
pr.reading_time,
u.full_name
FROM parameter_readings pr
LEFT JOIN users u
ON u.id=pr.taken_by
WHERE pr.appointment_id='$appointment_id'
AND pr.parameter_id='$parameter_id'
ORDER BY pr.reading_date,pr.reading_time
");
$values=[];
while($row=mysqli_fetch_assoc($sql)){
$value=(float)$row['reading'];
$values[]=$value;
$response['chart'][]=[
'label'=>date(
'd-M H:i',
strtotime($row['reading_date'].' '.$row['reading_time'])
),
'value'=>$value
];
$response['history'][]=[
'reading'=>$row['reading'],
'date'=>date(
'd-M-Y',
strtotime($row['reading_date'])
),
'time'=>date(
'h:i A',
strtotime($row['reading_time'])
),
'by'=>$row['full_name']
];
}
if(count($values)>0){
$response['latest']=end($values);
$response['highest']=max($values);
$response['lowest']=min($values);
$response['average']=round(
array_sum($values)/count($values),
2
);
}
header('Content-Type: application/json');
echo json_encode($response);
exit;