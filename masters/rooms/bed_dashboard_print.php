<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$query = $conn->query("
SELECT 
h.hospital_name,
br.branch_name,
b.building_name,
f.floor_name,
r.room_number,
bed.bed_number,
bed.status

FROM bed_master bed
LEFT JOIN room_master r ON r.id = bed.room_id
LEFT JOIN floor_master f ON f.id = r.floor_id
LEFT JOIN building_master b ON b.id = f.building_id
LEFT JOIN branch_master br ON br.id = b.branch_id
LEFT JOIN hospital_master h ON h.id = b.hospital_id

ORDER BY h.hospital_name, b.building_name, f.floor_name, r.room_number, bed.bed_number
");

/* ORGANIZE DATA */
$data = [];

$allBeds = [];
while($row = $query->fetch_assoc()){

    $h = $row['hospital_name'];
    $br = $row['branch_name'];
    $b = $row['building_name'];
    $f = $row['floor_name'];
    $r = $row['room_number'];

    $data[$h][$br][$b][$f][$r][] = $row;
    
    $allBeds[] = $row;
}

/* FUNCTION FOR STATS */
function getStats($beds){
    $total = count($beds);
    $available = 0;
    $occupied = 0;
    $maintenance = 0;

    foreach($beds as $bed){
        if($bed['status']=='Available') $available++;
        elseif($bed['status']=='Occupied') $occupied++;
        elseif($bed['status']=='Maintenance') $maintenance++;
    }

    return [$total,$available,$occupied,$maintenance];
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Bed Dashboard Report</title>
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<style>
body{ font-family:Arial; font-size:12px; }

h2{ text-align:center; }

.section{
    margin-top:15px;
}

.heading{
    font-weight:bold;
    padding:6px;
    background:#eee;
    border:1px solid #000;
}

.stats{
    font-size:11px;
    margin-bottom:5px;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-bottom:10px;
}

th, td{
    border:1px solid #000;
    padding:5px;
}

th{ background:#f0f0f0; }

.status-Available{ background:#d4edda; }
.status-Occupied{ background:#f8d7da; }
.status-Maintenance{ background:#fff3cd; }

@media print{
    .no-print{ display:none; }
}
</style>
</head>

<body>

<h2>Bed Dashboard Report</h2>


<div style="text-align:center;">Date: <?= date('d-M-Y h:i A') ?></div>

<div class="no-print" style="margin:10px;">
    <button onclick="window.print()">Print</button>  To Download as PDF, Click on Print and in opened popup select "Save as PDF" and "Save"
</div>

<?php
list($t,$a,$o,$m) = getStats($allBeds);
?>

<div style="margin:10px 0; padding:10px; background:#e3f2fd; border:1px solid #90caf9; font-weight:bold;">
    🌐 All Hospitals Summary →
    Total: <?= $t ?> |
    Available: <?= $a ?> |
    Occupied: <?= $o ?> |
    Maintenance: <?= $m ?>
</div>

<?php foreach($data as $hospital => $buildings): ?>
<?php
$hospitalBeds = [];

foreach($buildings as $branchData){
    foreach($branchData as $floors){
        foreach($floors as $rooms){
            foreach($rooms as $beds){
                $hospitalBeds = array_merge($hospitalBeds, $beds);
            }
        }
    }
}

list($t,$a,$o,$m) = getStats($hospitalBeds);
?>

<div class="section">
    <div class="heading">
        Hospital: <?= $hospital ?> |
        Total: <?= $t ?> |
        Available: <?= $a ?> |
        Occupied: <?= $o ?> |
        Maintenance: <?= $m ?>
    </div>
<?php foreach($buildings as $branch => $branchBuildings): ?>
<?php
$branchBeds = [];

foreach($branchBuildings as $floors){
    foreach($floors as $rooms){
        foreach($rooms as $beds){
            $branchBeds = array_merge($branchBeds, $beds);
        }
    }
}

list($bt,$ba,$bo,$bm) = getStats($branchBeds);
?>

<div class="section" style="margin-left:10px;">
    <div class="heading">
        Branch: <?= $branch ?> |
        Total: <?= $bt ?> |
        Available: <?= $ba ?> |
        Occupied: <?= $bo ?> |
        Maintenance: <?= $bm ?>
    </div>

       <?php foreach($branchBuildings as $building => $floors): ?>
            <?php
            $buildingBeds = [];
            foreach($floors as $rooms){
                foreach($rooms as $beds){
                    $buildingBeds = array_merge($buildingBeds, $beds);
                }
            }
            list($t,$a,$o,$m) = getStats($buildingBeds);
            ?>

            <div class="section" style="margin-left:10px;">
                <div class="heading">Building: <?= $building ?> |  Total: <?= $t ?> | Available: <?= $a ?> | Occupied: <?= $o ?> | Maintenance: <?= $m ?>
                </div>

               <?php foreach($floors as $floor => $rooms): ?>

<?php
// 🔥 CALCULATE FLOOR STATS
$floorBeds = [];
foreach($rooms as $beds){
    $floorBeds = array_merge($floorBeds, $beds);
}
list($t,$a,$o,$m) = getStats($floorBeds);
?>

<div class="section" style="margin-left:10px;">
    <div class="heading">
        Floor: <?= $floor ?> |
        Total: <?= $t ?> |
        Available: <?= $a ?> |
        Occupied: <?= $o ?> |
        Maintenance: <?= $m ?>
    </div>

                        <?php foreach($rooms as $room => $beds): ?>

                            <?php list($t,$a,$o,$m) = getStats($beds); ?>

                            <div class="section" style="margin-left:10px;">
                                <div class="heading">
                                    Room: <?= $room ?> | 
                                    Total:<?= $t ?> | Available:<?= $a ?> | Occupied:<?= $o ?> | Maintenance:<?= $m ?>
                                </div>

                                <table>
                                    <tr>
                                        <th>Hospital</th>
                                        <th>Branch</th>
                                        <th>Building</th>
                                        <th>Floor</th>
                                        <th>Room</th>
                                        <th>Bed</th>
                                        <th>Status</th>
                                    </tr>

                                    <?php foreach($beds as $bed): ?>
                                    <tr class="status-<?= $bed['status'] ?>">
                                        <td><?= $hospital ?></td>
                                        <td><?= $branch ?></td>
                                        <td><?= $building ?></td>
                                        <td><?= $floor ?></td>
                                        <td><?= $room ?></td>
                                        <td><?= $bed['bed_number'] ?></td>
                                        <td><?= $bed['status'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                    <?php endforeach; ?> <!-- room loop -->

                    </div>
                </div>

                <?php endforeach; ?> <!-- floor loop -->

            </div>
        </div>

        <?php endforeach; ?> <!-- building loop -->

    </div>

<?php endforeach; ?> <!-- branch loop -->

</div>

<?php endforeach; ?> <!-- hospital loop -->
</div>
</body>
</html>