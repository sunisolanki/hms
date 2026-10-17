<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$data = [];

/* =========================
   DOCTOR DROPDOWN
========================= */
$doctors = [];

$sql = "
SELECT DISTINCT
    u.id AS doctor_id,
    u.full_name AS doctor_name
FROM users u
INNER JOIN doctor_master dm
    ON dm.user_id = u.id
WHERE u.employee_type_id = 2
  AND u.status = 1
ORDER BY u.full_name
";

$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $doctors[] = $row;
}
$data['doctors'] = $doctors;


/* =========================
   DEPARTMENT DROPDOWN
========================= */
$departments = [];

$sql = "
SELECT DISTINCT
    d.id   AS department_id,
    d.department_name
FROM appointments a
JOIN users u
    ON u.id = a.entity_id
JOIN user_departments ud
    ON ud.user_id = u.id
JOIN department_master d
    ON d.id = ud.department_id
WHERE a.type = 'doctor'
ORDER BY d.department_name
";

$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $departments[] = $row;
}
$data['departments'] = $departments;


/* =========================
   BRANCH DROPDOWN
========================= */
$branches = [];

$sql = "
SELECT DISTINCT
    b.id   AS branch_id,
    b.branch_name
FROM appointments a
JOIN users u
    ON u.id = a.entity_id
JOIN user_branches ub
    ON ub.user_id = u.id
JOIN branch_master b
    ON b.id = ub.branch_id
WHERE a.type = 'doctor'
ORDER BY b.branch_name
";

$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $branches[] = $row;
}
$data['branches'] = $branches;

/* =========================
   HOSPITAL DROPDOWN (ALL)
========================= */
$hospitals = [];

$sql = "
SELECT
    h.id AS hospital_id,
    h.hospital_name
FROM hospital_master h
ORDER BY h.hospital_name
";

$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $hospitals[] = $row;
}
$data['hospitals'] = $hospitals;


/* =========================
   FINAL OUTPUT (ONLY ONCE)
========================= */
echo json_encode($data);
exit;
