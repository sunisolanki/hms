<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
session_start();
mysqli_begin_transaction($conn);
try {
    $isUpdate = !empty($_POST['empanelment_id']);
    $oldEmpanelment = [];
$oldServices = [];
$changedFields = [];

if ($isUpdate) {

    $emp_id = (int)$_POST['empanelment_id'];

    $q = mysqli_query($conn,"
        SELECT *
        FROM empanelment_master
        WHERE id = $emp_id
    ");

    $oldEmpanelment = mysqli_fetch_assoc($q);

    $q = mysqli_query($conn,"
        SELECT
            es.*,
            sm.service_name
        FROM empanelment_services es
        LEFT JOIN service_master sm
            ON sm.id = es.service_id
        WHERE es.empanelment_id = $emp_id
    ");

    while($r = mysqli_fetch_assoc($q)){
        $oldServices[$r['service_id']] = $r;
    }
}
if ($isUpdate) {

    $fields = [
        'empanelment_name'            => 'Empanelment Name',
        'start_date'                  => 'Start Date',
        'end_date'                    => 'End Date',
        'payment_mode'                => 'Payment Mode',
        'consultation_discount_type'  => 'Consultation Discount Type',
        'consultation_discount_value' => 'Consultation Discount Value',
        'status'                      => 'Status'
    ];

    foreach ($fields as $dbField => $label) {

        $oldValue = (string)($oldEmpanelment[$dbField] ?? '');
        $newValue = (string)($_POST[$dbField] ?? '');

        if ($oldValue !== $newValue) {

            $changedFields[$label] = [
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }
}
    $status   = isset($_POST['status']) ? (int)$_POST['status'] : 1;
    if ($isUpdate) {
        $emp_id = (int)$_POST['empanelment_id'];
        mysqli_query($conn,"
            UPDATE empanelment_master SET
                empanelment_name='".mysqli_real_escape_string($conn,$_POST['empanelment_name'])."',
                start_date='{$_POST['start_date']}',
                end_date='{$_POST['end_date']}',
                payment_mode='{$_POST['payment_mode']}',
                consultation_discount_type='{$_POST['consultation_discount_type']}',
                consultation_discount_value='{$_POST['consultation_discount_value']}',
                status='$status'
            WHERE id=$emp_id
        ");
        mysqli_query($conn,"DELETE FROM empanelment_services WHERE empanelment_id=$emp_id");
    } else {
        mysqli_query($conn,"
            INSERT INTO empanelment_master
            SET
                empanelment_name='".mysqli_real_escape_string($conn,$_POST['empanelment_name'])."',
                start_date='{$_POST['start_date']}',
                end_date='{$_POST['end_date']}',
                payment_mode='{$_POST['payment_mode']}',
                consultation_discount_type='{$_POST['consultation_discount_type']}',
                consultation_discount_value='{$_POST['consultation_discount_value']}',
                status='$status',
                created_by='{$_SESSION['user_id']}'
        ");
        $emp_id = mysqli_insert_id($conn);
    }
    foreach ($_POST['service_id'] as $k => $sid) {
        $sid = (int)$sid;
        $sa  = (float)$_POST['service_amount'][$k];
        $ea  = (float)$_POST['emp_amount'][$k];

        if ($ea > $sa) {
            throw new Exception('Emp Amount cannot exceed Service Amount');
        }
        mysqli_query($conn,"
            INSERT INTO empanelment_services
            (empanelment_id, service_id, service_amount, emp_amount)
            VALUES ($emp_id, $sid, $sa, $ea)
        ");
    }
   /* ================= SERVICE ADDED AUDIT ================= */

if ($isUpdate) {

    $newServices = [];

    if (!empty($_POST['service_id'])) {
        foreach ($_POST['service_id'] as $k => $sid) {
            $sid = (int)$sid;
            $newServices[$sid] = true;
            if (!isset($oldServices[$sid])) {
                $q = mysqli_query($conn,"
                    SELECT service_name
                    FROM service_master
                    WHERE id = $sid
                ");
                $service = mysqli_fetch_assoc($q);
                $changedFields["Service Added : ".$service['service_name']] = [
                    'old' => '',
                    'new' => 'Added'
                ];
            }
        }
    }
}
/* ================= SERVICE REMOVED AUDIT ================= */
if ($isUpdate) {
    foreach ($oldServices as $serviceId => $service) {
        if (!isset($newServices[$serviceId])) {
            $changedFields["Service Removed : ".$service['service_name']] = [
                'old' => 'Available',
                'new' => 'Removed'
            ];
        }
    }
}
/* ================= SERVICE AMOUNT CHANGED AUDIT ================= */
if ($isUpdate && !empty($_POST['service_id'])) {
    foreach ($_POST['service_id'] as $k => $sid) {
        $sid = (int)$sid;
        if (isset($oldServices[$sid])) {
            $oldEmpAmount = (float)$oldServices[$sid]['emp_amount'];
            $newEmpAmount = (float)$_POST['emp_amount'][$k];
            if (round($oldEmpAmount,2) != round($newEmpAmount,2)) {
                $changedFields["Emp Amount : ".$oldServices[$sid]['service_name']] = [
                    'old' => $oldEmpAmount,
                    'new' => $newEmpAmount
                ];
            }
        }
    }
}
    /* ================= AUDIT LOG ================= */
if ($isUpdate) {
    if (!empty($changedFields)) {
        $oldChangedFields = [];
        $newChangedFields = [];
        foreach ($changedFields as $field => $values) {
            $oldChangedFields[$field] = $values['old'];
            $newChangedFields[$field] = $values['new'];
        }
        addAuditLog(
            'Empanelment Master',
            'Update',
            "Empanelment Updated : ".$_POST['empanelment_name'],
            $emp_id,
            json_encode($oldChangedFields),
            json_encode($newChangedFields),
            'SUCCESS'
        );
    }
} else {
    $newValues = [
        'Empanelment Name' => $_POST['empanelment_name'],
        'Start Date' => $_POST['start_date'],
        'End Date' => $_POST['end_date'],
        'Payment Mode' => $_POST['payment_mode'],
        'Consultation Discount Type' => $_POST['consultation_discount_type'],
        'Consultation Discount Value' => $_POST['consultation_discount_value'],
        'Status' => $status
    ];
    addAuditLog(
        'Empanelment Master',
        'Create',
        "Empanelment Created : ".$_POST['empanelment_name'],
        $emp_id,
        '',
        json_encode($newValues),
        'SUCCESS'
    );
}
    mysqli_commit($conn);
    header('Location: index.php');
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo 'Error: '.$e->getMessage();
}