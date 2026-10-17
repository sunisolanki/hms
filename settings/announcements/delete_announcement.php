<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_GET['id'])){
    echo "ID missing";
    exit;
}

$id = (int)$_GET['id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->begin_transaction();

try {

    // delete user mappings
    $stmt1 = $conn->prepare("DELETE FROM announcement_users WHERE announcement_id = ?");
    $stmt1->bind_param("i", $id);
    $stmt1->execute();

    // ❌ REMOVE THIS (table doesn't exist)
    // $stmt2 = $conn->prepare("DELETE FROM announcement_departments WHERE announcement_id = ?");
    // $stmt2->bind_param("i", $id);
    // $stmt2->execute();

    // delete main record
    $stmt3 = $conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt3->bind_param("i", $id);
    $stmt3->execute();

    if ($stmt3->affected_rows === 0) {
        throw new Exception("Announcement not found or already deleted");
    }

    $conn->commit();

} catch (Exception $e) {

    $conn->rollback();
    die("Delete failed: " . $e->getMessage());
}

header("Location: /settings/announcements/announcement_list.php");
exit;