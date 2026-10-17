<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

/* =========================
   INPUT (users.id)
========================= */
$user_id = (int)($_GET['doctor_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode([]);
    exit;
}

/* =========================
   STEP 1: doctor_master mapping
========================= */
$sql = "
    SELECT id AS doctor_id, time_slot_minutes
    FROM doctor_master
    WHERE user_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc || (int)$doc['time_slot_minutes'] <= 0) {
    echo json_encode([]);
    exit;
}

$doctor_id   = (int)$doc['doctor_id'];
$slotMinutes = (int)$doc['time_slot_minutes'];

/* =========================
   STEP 2: fetch all schedules for doctor
========================= */
$sql = "
    SELECT
        dwd.day_of_week,
        dds.id AS schedule_id,
        dds.start_time,
        dds.end_time
    FROM doctor_working_days dwd
    JOIN doctor_day_schedules dds
        ON dds.working_day_id = dwd.id
    WHERE dwd.doctor_id = ?
      AND dwd.is_active = 1
      AND dds.is_active = 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$res = $stmt->get_result();

$schedules = [];
while ($row = $res->fetch_assoc()) {
    $schedules[strtoupper($row['day_of_week'])] = $row;
}
$stmt->close();

if (empty($schedules)) {
    echo json_encode([]);
    exit;
}

/* =========================
   STEP 3: fetch all breaks
========================= */
$sql = "
    SELECT schedule_id, break_start, break_end
    FROM doctor_schedule_breaks
";
$res = mysqli_query($conn, $sql);

$breaks = [];
while ($b = mysqli_fetch_assoc($res)) {
    if ($b['break_end'] > $b['break_start']) {
        $breaks[$b['schedule_id']][] = $b;
    }
}

/* =========================
   STEP 4: generate slots (7 days)
========================= */
$output = [];

for ($i = 0; $i < 7; $i++) {

    $date = date('Y-m-d', strtotime("+$i days"));
    $day  = strtoupper(date('l', strtotime($date)));

    if (!isset($schedules[$day])) {
        $output[$date] = [];
        continue;
    }

    $sch = $schedules[$day];

    $start = strtotime($date . ' ' . $sch['start_time']);
    $end   = strtotime($date . ' ' . $sch['end_time']);

    /* Night duty support */
    if ($end <= $start) {
        $end = strtotime('+1 day', $end);
    }

    $dayBreaks = $breaks[$sch['schedule_id']] ?? [];
    $slots = [];

    for ($t = $start; $t < $end; $t += ($slotMinutes * 60)) {

        $slotStart = date('H:i', $t);
        $slotEndTs = min($t + ($slotMinutes * 60), $end);
        $slotEnd   = date('H:i', $slotEndTs);

        /* Break check */
      $inBreak = false;

$slotStartTs = strtotime($date . ' ' . $slotStart);
$slotEndTs   = strtotime($date . ' ' . $slotEnd);

foreach ($dayBreaks as $b) {

    $breakStartTs = strtotime($date . ' ' . $b['break_start']);
    $breakEndTs   = strtotime($date . ' ' . $b['break_end']);

    // ✅ OVERLAP CHECK
    if ($slotStartTs < $breakEndTs && $slotEndTs > $breakStartTs) {
        $inBreak = true;
        break;
    }
}


if ($inBreak) {
    $slots[] = [
        'start'  => $slotStart,
        'end'    => $slotEnd,
        'status' => 'break'
    ];
} else {
    $slots[] = [
        'start'  => $slotStart,
        'end'    => $slotEnd,
        'status' => 'available'
    ];

}

    }

    $output[$date] = $slots;
}

/* =========================
   OUTPUT
========================= */
echo json_encode($output);
exit;
