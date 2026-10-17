<?php
require_once __DIR__ . '/../../includes/db.php';

$hospital_id = (int)($_GET['hospital_id'] ?? 0);

$res = mysqli_query($conn,"
    SELECT id, branch_name
    FROM branch_master
    WHERE hospital_id = $hospital_id
      AND status = 1
");

echo '<option value="">Select Branch</option>';
while ($r = mysqli_fetch_assoc($res)) {
    echo "<option value='{$r['id']}'>".htmlspecialchars($r['branch_name'])."</option>";
}
