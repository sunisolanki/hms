<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    exit;
}

$department = isset($_GET['dept']) ? $_GET['dept'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$subtype = isset($_GET['subtype']) ? $_GET['subtype'] : '';

$sql = "SELECT id, full_name FROM users WHERE status='Active'";
$params = [];
$types = "";

if (!empty($department)) {
    $sql .= " AND department_id = ?";
    $params[] = $department;
    $types .= "i";
}

if (!empty($type)) {
    $sql .= " AND employee_type = ?";
    $params[] = $type;
    $types .= "i";
}

if (!empty($subtype)) {
    $sql .= " AND employee_subtype = ?";
    $params[] = $subtype;
    $types .= "i";
}

$sql .= " ORDER BY full_name";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while($user = $result->fetch_assoc()) {
    echo '<label>';
    echo '<input type="checkbox" name="user_ids[]" value="' . $user['id'] . '" onchange="updateSelectedUsers()">';
    echo htmlspecialchars($user['full_name']);
    echo '</label>';
}
?>