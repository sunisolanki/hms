<?php
require_once __DIR__ . '/../../includes/db.php';

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

/* OPTIONAL selected value (for Edit mode) */
$selected = isset($_GET['selected']) ? (int)$_GET['selected'] : 0;

if ($type == 'state') {
    $res = mysqli_query($conn,"SELECT id,name FROM states WHERE country_id=$id ORDER BY name");
    echo '<option value="">Select State</option>';
}
elseif ($type == 'district') {
    $res = mysqli_query($conn,"SELECT id,name FROM districts WHERE state_id=$id ORDER BY name");
    echo '<option value="">Select District</option>';
}
elseif ($type == 'city') {
    $res = mysqli_query($conn,"SELECT id,name FROM cities WHERE district_id=$id ORDER BY name");
    echo '<option value="">Select City</option>';
}
else {
    exit;
}

/* OUTPUT OPTIONS */
while ($r = mysqli_fetch_assoc($res)) {

    /* SELECT ONLY IF selected param is provided */
    $sel = ($selected > 0 && $r['id'] == $selected) ? ' selected' : '';

    echo '<option value="'.$r['id'].'"'.$sel.'>'
        . htmlspecialchars($r['name'])
        . '</option>';
}
