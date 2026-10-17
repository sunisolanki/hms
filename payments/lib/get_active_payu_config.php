<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

function getActivePayU($conn) {

    $stmt = $conn->prepare("
        SELECT *
        FROM payment_gateway_config
        WHERE gateway = 'PAYU'
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc();
}
