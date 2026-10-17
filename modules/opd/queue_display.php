<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$today = date('Y-m-d');

$query = "
SELECT
    a.id,
    a.token_no,
    a.appointment_time,
    a.status,
    a.first_name,
    a.last_name,
    a.is_emergency,
    a.appointment_date,
    u.full_name AS doctor_name,
    dm.time_slot_minutes
FROM appointments a
LEFT JOIN users u
    ON a.entity_id = u.id
LEFT JOIN doctor_master dm
    ON dm.user_id = u.id
WHERE a.type='doctor'
AND a.appointment_date='$today'
AND a.status IN ('Registered','Confirmed','Done','Prescription')
ORDER BY
    u.full_name,
    a.token_no ASC
";

$result = mysqli_query($conn,$query);

/* =========================
   GROUP APPOINTMENTS DOCTOR WISE
========================= */

$queue = [];

while($row = mysqli_fetch_assoc($result)){

    $doctor = $row['doctor_name'] ?: 'Unknown Doctor';

    $queue[$doctor][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<title>WebSplend - OPD Queue</title>

<link rel="icon" href="/assets/img/hms_logo.png">

<meta name="viewport" content="width=device-width, initial-scale=1">

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial,Helvetica,sans-serif;
    background:#0f172a;
    color:#fff;
}

/* =========================
   HEADER
========================= */

.header{
    background:#1e293b;
    padding:18px 30px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:1px solid #334155;
}

.title{
    font-size:28px;
    font-weight:700;
}

.date{
    font-size:15px;
    color:#cbd5e1;
    margin-top:4px;
}

.clock{
    font-size:22px;
    font-weight:600;
}

/* =========================
   MAIN CONTAINER
========================= */

.container{
    padding:25px;
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(500px,1fr));
    gap:25px;
}

/* =========================
   DOCTOR CARD
========================= */

.doctor-card{
    background:#1e293b;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 8px 25px rgba(0,0,0,.35);
    border:1px solid #334155;
}

.doctor-header{
    padding:18px 20px;
    background:#273449;
    border-bottom:1px solid #475569;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.doctor-name{
    font-size:22px;
    font-weight:700;
}

.queue-count{
    background:#334155;
    color:#e2e8f0;
    padding:5px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
}

/* =========================
   QUEUE ITEM
========================= */

.queue-item{
    display:grid;
    grid-template-columns:80px 1fr 100px 120px;
    gap:12px;
    align-items:center;
    padding:15px 18px;
    border-bottom:1px solid #334155;
    min-height:72px;
    transition:.2s;
}

.queue-item:last-child{
    border-bottom:none;
}

.queue-item:hover{
    background:#263449;
}

/* =========================
   TOKEN
========================= */

.token-box{
    text-align:center;
}

.token-label{
    font-size:10px;
    color:#94a3b8;
    text-transform:uppercase;
}

.token-number{
    font-size:25px;
    font-weight:800;
}

/* =========================
   PATIENT
========================= */

.patient-name{
    font-size:18px;
    font-weight:600;
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.patient-time{
    font-size:12px;
    color:#94a3b8;
    margin-top:4px;
}

/* =========================
   EMERGENCY
========================= */

.emergency-tag{
    display:inline-flex;
    align-items:center;
    gap:4px;
    background:#dc2626;
    color:#fff;
    padding:4px 8px;
    border-radius:5px;
    font-size:10px;
    font-weight:800;
    letter-spacing:.3px;
}

/* =========================
   STATUS
========================= */

.status-box{
    text-align:center;
}

.status{
    display:inline-block;
    padding:7px 10px;
    border-radius:6px;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
}

.status-current{
    background:#2563eb;
    color:#fff;
}

.status-waiting{
    background:#facc15;
    color:#422006;
}

.status-done{
    background:#16a34a;
    color:#fff;
}

/* =========================
   WAITING TIME
========================= */

.wait-box{
    text-align:right;
}

.wait-label{
    font-size:10px;
    color:#94a3b8;
    text-transform:uppercase;
}

.wait-time{
    font-size:18px;
    font-weight:800;
    color:#facc15;
}

.turn-time{
    font-size:11px;
    color:#94a3b8;
    margin-top:3px;
}

/* =========================
   NOW SERVING
========================= */

.queue-item.current{
    background:#1d4ed8;
    border-left:6px solid #60a5fa;
}

.queue-item.current .patient-name{
    font-size:20px;
}

.queue-item.current .wait-time{
    color:#fff;
}

.now-serving{
    background:#fff;
    color:#1d4ed8;
    padding:5px 8px;
    border-radius:5px;
    font-size:10px;
    font-weight:800;
}

/* =========================
   COMPLETED
========================= */

.queue-item.completed{
    opacity:.65;
}

.queue-item.completed .token-number{
    color:#86efac;
}

.queue-item.completed .patient-name{
    color:#cbd5e1;
}

/* =========================
   EMPTY
========================= */

.empty{
    grid-column:1/-1;
    font-size:28px;
    text-align:center;
    padding:100px 20px;
    color:#94a3b8;
}

/* =========================
   RESPONSIVE
========================= */

@media(max-width:800px){

    .container{
        grid-template-columns:1fr;
        padding:15px;
    }

    .header{
        padding:15px;
    }

    .title{
        font-size:20px;
    }

    .clock{
        font-size:16px;
    }

    .queue-item{
        grid-template-columns:65px 1fr;
        gap:10px;
    }

    .status-box,
    .wait-box{
        grid-column:2;
        text-align:left;
    }

}
@media(max-width:800px){
    .container{
        grid-template-columns:1fr;
    }
}
</style>

</head>

<body>

<div class="header">

    <div>

        <div class="title">
            🩺 OPD Queue
        </div>

        <div class="date">
            <?= date('l, d M Y') ?>
        </div>

    </div>

    <div class="clock" id="clock"></div>

</div>

<div class="container">

<?php if(empty($queue)): ?>

    <div class="empty">
        No Appointments Today
    </div>

<?php else: ?>

<?php foreach($queue as $doctor=>$appointments): ?>

<?php

/*
==================================================
QUEUE CALCULATION
==================================================
*/

$servingFound = false;

/*
 * Find last completed appointment.
 */
$lastDoneIndex = -1;

foreach($appointments as $index=>$appointment){

    if($appointment['status'] === 'Done'){
        $lastDoneIndex = $index;
    }
}

/*
 * Determine estimated end time of last completed patient.
 */

$lastDoneEndTimestamp = time();

if($lastDoneIndex >= 0){

    $lastDone = $appointments[$lastDoneIndex];

    $parts = explode(' - ', $lastDone['appointment_time']);

    $startTimestamp = strtotime(
        $today.' '.trim($parts[0])
    );

    $slotMinutes =
        (int)($lastDone['time_slot_minutes'] ?: 5);

    $lastDoneEndTimestamp =
        $startTimestamp + ($slotMinutes * 60);
}

/*
 * Find current time.
 */

$currentTimestamp = time();

/*
 * Remaining time from last completed patient's
 * scheduled slot.
 */

$remainingAfterLastDone =
    max(
        0,
        $lastDoneEndTimestamp - $currentTimestamp
    );

/*
 * Count waiting patients after last Done.
 */

$waitingPosition = 0;

?>

<div class="doctor-card">

    <div class="doctor-header">

        <div class="doctor-name">
            👨‍⚕️ <?= htmlspecialchars($doctor) ?>
        </div>

        <div class="queue-count">
            <?= count($appointments) ?> Patients
        </div>

    </div>

<?php foreach($appointments as $index=>$a): ?>

<?php

$patient = trim(
    $a['first_name'].' '.$a['last_name']
);

$slotMinutes =
    (int)($a['time_slot_minutes'] ?: 5);

$isEmergency =
    ((int)$a['is_emergency'] === 1);

$startParts =
    explode(' - ', $a['appointment_time']);

$startTime =
    strtotime(
        $today.' '.trim($startParts[0])
    );

/*
==================================================
STATUS
==================================================
*/

if($a['status'] === 'Done'){

    $statusText = 'Completed';

    $statusClass = 'completed';

    $waitMinutes = 0;

    $turnTimestamp = $startTime;

}
elseif(!$servingFound){

    /*
     * First patient who is not Done
     * becomes Now Serving.
     */

    $statusText = 'Now Serving';

    $statusClass = 'current';

    $servingFound = true;

    $waitMinutes = 0;

    $turnTimestamp = time();

}
else{

    /*
     * Patient is waiting.
     */

    $waitingPosition++;

    /*
     * Calculate total waiting time:
     *
     * Remaining time from last completed patient
     * +
     * slots of patients ahead.
     */

    $waitSeconds =
        $remainingAfterLastDone +
        (($waitingPosition - 1) * $slotMinutes * 60);

    /*
     * If there is a current serving patient,
     * include that patient's slot.
     */

    if($servingFound){

        $waitSeconds +=
            $slotMinutes * 60;
    }

    $waitSeconds =
        max(0,$waitSeconds);

   $waitSeconds = max(0, $waitSeconds);
$turnTimestamp = time() + $waitSeconds;

    $statusText = 'Waiting';

    $statusClass = '';
}

/*
==================================================
DISPLAY WAITING TIME
==================================================
*/

if($statusText === 'Waiting'){

    if($waitMinutes < 1){

        $waitDisplay = 'Less than 1 min';

    }
    else{

        $hours =
            floor($waitMinutes / 60);

        $minutes =
            $waitMinutes % 60;

        if($hours > 0){

            $waitDisplay =
                $hours.'h '.$minutes.'m';

        }
        else{

            $waitDisplay =
                $minutes.' min';
        }
    }

    $turnDisplay =
        date('h:i A',$turnTimestamp);

}
else{

    $waitDisplay = '';

    $turnDisplay = '';
}

?>

<div class="queue-item <?= $statusClass ?>">

    <!-- TOKEN -->

    <div class="token-box">

        <div class="token-label">
            Token
        </div>

        <div class="token-number">
            <?= htmlspecialchars($a['token_no']) ?>
        </div>

    </div>

    <!-- PATIENT -->

    <div>

        <div class="patient-name">

            <?= htmlspecialchars($patient) ?>

            <?php if($isEmergency): ?>

                <span class="emergency-tag">
                    🚨 EMERGENCY
                </span>

            <?php endif; ?>

        </div>

        <div class="patient-time">

            Appointment:
            <?= date('h:i A',$startTime) ?>

        </div>

    </div>

    <!-- STATUS -->

    <div class="status-box">

        <?php if($statusText === 'Now Serving'): ?>

            <span class="status status-current">
                Now Serving
            </span>

        <?php elseif($statusText === 'Completed'): ?>

            <span class="status status-done">
                Completed
            </span>

        <?php else: ?>

            <span class="status status-waiting">
                Waiting
            </span>

        <?php endif; ?>

    </div>

    <!-- WAIT TIME -->

    <div class="wait-box">

        <?php if($statusText === 'Waiting'): ?>

            <div class="wait-label">
                Approx. Turn
            </div>

            <div class="wait-time countdown"
     data-seconds="<?= (int)$waitSeconds ?>">
    <?= gmdate('H:i:s', $waitSeconds) ?>
</div>

            <div class="turn-time">
                Around <?= $turnDisplay ?>
            </div>

        <?php elseif($statusText === 'Now Serving'): ?>

            <span class="now-serving">
                PLEASE PROCEED
            </span>

        <?php else: ?>

            <span class="done">
                ✓ Done
            </span>

        <?php endif; ?>

    </div>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<script>

/* =========================
   CLOCK
========================= */

function updateClock(){

    const now = new Date();

    document.getElementById("clock").innerHTML =
        now.toLocaleTimeString(
            'en-IN',
            {
                hour:'2-digit',
                minute:'2-digit',
                second:'2-digit',
                hour12:true
            }
        );
}

setInterval(updateClock,1000);

updateClock();

/*
==================================================
AUTO REFRESH
==================================================

Refresh every 30 seconds so that:
- completed patients disappear/change status
- waiting times remain current
- emergency status remains current
*/

setTimeout(function(){

    location.reload();

},30000);
function formatCountdown(seconds)
{
    seconds = Math.max(0, seconds);

    const hours = Math.floor(seconds / 3600);

    const minutes =
        Math.floor((seconds % 3600) / 60);

    const secs =
        seconds % 60;

    return String(hours).padStart(2,'0') + ':' +
           String(minutes).padStart(2,'0') + ':' +
           String(secs).padStart(2,'0');
}

function updateCountdowns()
{
    document.querySelectorAll('.countdown').forEach(function(element){

        let seconds =
            parseInt(element.dataset.seconds || '0');

        seconds--;

        if(seconds < 0){
            seconds = 0;
        }

        element.dataset.seconds = seconds;

        element.textContent =
            formatCountdown(seconds);

        if(seconds === 0){
            element.textContent = '00:00:00';
        }

    });
}

setInterval(updateCountdowns,1000);
</script>

</body>

</html>