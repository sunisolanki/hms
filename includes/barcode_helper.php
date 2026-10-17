<?php

function generateBarcode(
    mysqli $conn,
    $hospital_id,
    $branch_id,
    $store_id,
    $module_name,
    $record_id
){

    $hospital_id = (int)$hospital_id;
    $branch_id   = $branch_id ?: null;
    $store_id    = $store_id ?: null;

    $sql = "
    SELECT *
    FROM barcode_settings
    WHERE hospital_id = ?
    AND module_name = ?
    AND status='Active'
    LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "is",
        $hospital_id,
        $module_name
    );

    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();

    if(!$setting){
        return false;
    }

    $prefix      = trim($setting['barcode_prefix']);
    $format_type = $setting['format_type'];
    $sequence    = (int)$setting['next_sequence'];

    $barcode = '';

    switch($format_type){

        case 'PREFIX_SEQ':

            $barcode =
                $prefix .
                str_pad($sequence,6,'0',STR_PAD_LEFT);

        break;

        case 'PREFIX_YEAR_SEQ':

            $barcode =
                $prefix .
                date('Y') .
                str_pad($sequence,6,'0',STR_PAD_LEFT);

        break;

        case 'PREFIX_HOSPITAL_SEQ':

            $barcode =
                $prefix .
                '-H'.$hospital_id.
                '-'.str_pad($sequence,6,'0',STR_PAD_LEFT);

        break;

        case 'PREFIX_HOSPITAL_BRANCH_SEQ':

            $barcode =
                $prefix .
                '-H'.$hospital_id.
                '-B'.$branch_id.
                '-'.str_pad($sequence,6,'0',STR_PAD_LEFT);

        break;

        case 'PREFIX_HOSPITAL_BRANCH_STORE_SEQ':

            $barcode =
                $prefix .
                '-H'.$hospital_id.
                '-B'.$branch_id.
                '-S'.$store_id.
                '-'.str_pad($sequence,6,'0',STR_PAD_LEFT);

        break;

        default:

            $barcode =
                $prefix .
                str_pad($sequence,6,'0',STR_PAD_LEFT);

    }

    $stmtInsert = $conn->prepare("
        INSERT INTO barcode_registry
        (
            barcode_no,
            module_name,
            record_id,
            hospital_id,
            branch_id,
            store_id,
            barcode_type
        )
        VALUES
        (?,?,?,?,?,?,?)
    ");

    $stmtInsert->bind_param(
        "ssiiiis",
        $barcode,
        $module_name,
        $record_id,
        $hospital_id,
        $branch_id,
        $store_id,
        $setting['barcode_type']
    );

    if(!$stmtInsert->execute()){
    return false;
}

    $stmtUpdate = $conn->prepare("
        UPDATE barcode_settings
        SET next_sequence = next_sequence + 1
        WHERE id = ?
    ");

    $stmtUpdate->bind_param(
        "i",
        $setting['id']
    );

    $stmtUpdate->execute();

    return $barcode;
}