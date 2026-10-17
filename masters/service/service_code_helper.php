<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

function generateServiceCode($category_type, $conn)
{
    $prefixMap = [
        'Laboratory'         => 'LAB',
        'Radiology'          => 'RAD',
        'Hospital Services'  => 'HOS',
        'Ambulance'          => 'AMB',
        'Procedure'          => 'PRO',
        'Minor Procedure'    => 'MIN',
        'Physiotherapy'      => 'PHY',
        'Misc'               => 'MSC',
        'Other Services'     => 'OTH'
    ];

    if (!isset($prefixMap[$category_type])) {
        return null;
    }

    $prefix = $prefixMap[$category_type];

    $sql = "
        SELECT service_code
        FROM service_master
        WHERE service_code LIKE '{$prefix}-%'
        ORDER BY id DESC
        LIMIT 1
    ";

    $result = mysqli_query($conn, $sql);

    if ($row = mysqli_fetch_assoc($result)) {
        $lastNumber = (int) substr($row['service_code'], -4);
        $nextNumber = $lastNumber + 1;
    } else {
        $nextNumber = 1;
    }

    return $prefix . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}
