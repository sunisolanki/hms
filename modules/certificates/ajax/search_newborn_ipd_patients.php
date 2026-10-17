<?php
session_start();
ini_set('display_errors',0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
echo json_encode([
'results'=>[]
]);
exit;
}
$branch_id=(int)($_SESSION['branch_id']??0);
if($branch_id<=0){
echo json_encode([
'results'=>[]
]);
exit;
}
/* ===========================================================
   GET HOSPITAL FROM BRANCH
=========================================================== */
$stmt=$conn->prepare("
SELECT hospital_id
FROM branch_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$branch_result=$stmt->get_result();
$branch=$branch_result->fetch_assoc();
$stmt->close();
if(!$branch || (int)$branch['hospital_id']<=0){
echo json_encode([
'results'=>[]
]);
exit;
}
$hospital_id=(int)$branch['hospital_id'];
/* ===========================================================
   SEARCH
=========================================================== */
$q=trim((string)($_GET['q']??''));
$search='%'.$q.'%';
$sql="
SELECT
ia.id AS ipd_admission_id,
ia.ipd_no,
ia.patient_id,
ia.admission_date,
ia.admission_time,
pm.uhid,
pm.title,
pm.first_name,
pm.last_name,
pm.mobile
FROM ipd_admissions ia
INNER JOIN patient_master pm
ON pm.id=ia.patient_id
WHERE ia.hospital_id=?
AND ia.branch_id=?
AND ia.status='Admitted'
AND LOWER(TRIM(pm.sex))='female'
AND (
ia.ipd_no LIKE ?
OR CAST(pm.uhid AS CHAR) LIKE ?
OR pm.first_name LIKE ?
OR pm.last_name LIKE ?
OR CONCAT_WS(' ',pm.first_name,pm.last_name) LIKE ?
OR pm.mobile LIKE ?
)
ORDER BY ia.admission_date DESC,ia.admission_time DESC,ia.id DESC
LIMIT 30
";
$stmt=$conn->prepare($sql);
if(!$stmt){
echo json_encode([
'results'=>[]
]);
exit;
}
$stmt->bind_param(
"iissssss",
$hospital_id,
$branch_id,
$search,
$search,
$search,
$search,
$search,
$search
);
$stmt->execute();
$result=$stmt->get_result();
$results=[];
while($row=$result->fetch_assoc()){
$name=trim(
(string)($row['title']??'').' '.
(string)($row['first_name']??'').' '.
(string)($row['last_name']??'')
);
$text=$name;
if(!empty($row['ipd_no'])){
$text.=' | IP: '.$row['ipd_no'];
}
if(!empty($row['uhid'])){
$text.=' | UHID: '.$row['uhid'];
}
if(!empty($row['mobile'])){
$text.=' | '.$row['mobile'];
}
$results[]=[
'id'=>(int)$row['ipd_admission_id'],
'text'=>$text
];
}
$stmt->close();
echo json_encode([
'results'=>$results
],JSON_UNESCAPED_UNICODE);
exit;
?>