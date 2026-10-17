<?php


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) { 
    header('Location:/index.php'); 
    exit; 
}
// Get filter parameters
$selected_hospital = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
$selected_branch = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$selected_building = isset($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$selected_floor = isset($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;

// Get all hospitals
$hospitals = $conn->query("SELECT id, hospital_name FROM hospital_master ORDER BY hospital_name");
// Get branches based on selected hospital
if ($selected_hospital > 0) {

    $branches = $conn->query("
        SELECT id, branch_name 
        FROM branch_master 
        WHERE hospital_id = $selected_hospital
        ORDER BY branch_name
    ");

} else {

    $branches = $conn->query("
        SELECT id, branch_name 
        FROM branch_master 
        ORDER BY branch_name
    ");
}
// Get buildings based on selected hospital
if ($selected_branch > 0) {

    $buildings = $conn->query("
        SELECT id, building_name
        FROM building_master
        WHERE branch_id = $selected_branch
        ORDER BY building_name
    ");

}
elseif ($selected_hospital > 0) {

    $buildings = $conn->query("
        SELECT id, building_name
        FROM building_master
        WHERE hospital_id = $selected_hospital
        ORDER BY building_name
    ");

}
else {

    $buildings = $conn->query("
        SELECT id, building_name
        FROM building_master
        ORDER BY building_name
    ");
}

// Get floors based on selected building
if ($selected_building > 0) {
    $floors = $conn->query("SELECT id, floor_name FROM floor_master WHERE building_id = $selected_building ORDER BY floor_name");
} else {
    $floors = $conn->query("SELECT id, floor_name FROM floor_master ORDER BY floor_name");
}


// Build the main query
$query = "
SELECT 
    h.id as hospital_id,
    h.hospital_name,
    br.branch_name,
    br.id as branch_id,
    b.id as building_id,
    b.building_name,
    f.id as floor_id,
    f.floor_name,
    r.id as room_id,
    r.room_number,
    r.room_name,
    bed.id as bed_id,
    bed.bed_number,
    bed.status,
    bed.bed_type
FROM bed_master bed
LEFT JOIN room_master r ON r.id = bed.room_id
LEFT JOIN floor_master f ON f.id = r.floor_id
LEFT JOIN building_master b ON b.id = f.building_id
LEFT JOIN branch_master br ON br.id = b.branch_id
LEFT JOIN hospital_master h ON h.id = b.hospital_id
WHERE 1=1
";

if ($selected_hospital > 0) {
    $query .= " AND h.id = $selected_hospital";
}
if ($selected_branch > 0) {
    $query .= " AND br.id = $selected_branch";
}
if ($selected_building > 0) {
    $query .= " AND b.id = $selected_building";
}

if ($selected_floor > 0) {
    $query .= " AND f.id = $selected_floor";
}

$query .= " ORDER BY h.hospital_name, b.building_name, f.floor_name, r.room_number, bed.bed_number";

$beds = $conn->query($query);

// Organize beds by hierarchy
$organized = [];
while ($bed = $beds->fetch_assoc()) {
    $hospital_id = $bed['hospital_id'];
    $branch_id = $bed['branch_id'];
    $building_id = $bed['building_id'];
    $floor_id = $bed['floor_id'];
    $room_id = $bed['room_id'];
    
    if (!isset($organized[$hospital_id])) {
        $organized[$hospital_id] = [
            'name' => $bed['hospital_name'],
            'buildings' => []
        ];
    }
    if (!isset($organized[$hospital_id]['branches'][$branch_id])) {

    $organized[$hospital_id]['branches'][$branch_id] = [
        'name' => $bed['branch_name'],
        'buildings' => []
    ];
}
    if (!isset($organized[$hospital_id]['branches'][$branch_id]['buildings'][$building_id])) {
        $organized[$hospital_id]['branches'][$branch_id]['buildings'][$building_id] = [
            'name' => $bed['building_name'],
            'floors' => []
        ];
    }
    
    if (!isset($organized[$hospital_id]['branches'][$branch_id]['buildings'][$building_id]['floors'][$floor_id])) {
        $organized[$hospital_id]['branches'][$branch_id]['buildings'][$building_id]['floors'][$floor_id] = [
            'name' => $bed['floor_name'],
            'rooms' => []
        ];
    }
    
    if (!isset($organized[$hospital_id]['branches'][$branch_id]['buildings'][$building_id]['floors'][$floor_id]['rooms'][$room_id])) {
        $organized[$hospital_id]['branches'][$branch_id]['buildings'][$building_id]['floors'][$floor_id]['rooms'][$room_id] = [
            'number' => $bed['room_number'],
            'name' => $bed['room_name'],
            'beds' => []
        ];
    }
    
    $organized[$hospital_id]['branches'][$branch_id]['buildings'][$building_id]['floors'][$floor_id]['rooms'][$room_id]['beds'][] = $bed;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Bed View - Room Master</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f5f7fb;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .container {
            margin: 0 auto;
            padding: 10px;
        }
        .main-content {
    margin-left: 240px; /* match sidebar width */
    max-width: stretch;
}
        
        /* Legend */
        .legend {
            
            padding: 2px 20px;
                  }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 3px;
            font-size: 14px;
        }
        
        .legend-color {
            width: 18px;
            height: 18px;
            border-radius: 12px;
        }
        
        .legend-color.available { background: #28a745; }
        .legend-color.occupied { background: #dc3545; }
        .legend-color.maintenance { background: #ffc107; }
        
        /* Stats */
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 5px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: #1976d2;
            border-radius: 12px;
            padding: 1px 1px;
            display: flex;
            flex-direction: row;
            align-items: center;
            flex: 1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            justify-content: center
        }
        
        /* AVAILABLE */
.stat-card.available{
    background: #28a745;
    border: 1px solid #c3e6cb;
}
        .stat-number {
            font-size: 20px;
            font-weight: 600;
            margin: 10px;
        }
        
        /* OCCUPIED */
.stat-card.occupied{
    background: #dc3545;
    border: 1px solid #f5c6cb;
}

/* MAINTENANCE */
.stat-card.maintenance{
    background: #ffc107;
    border: 1px solid #ffeeba;
}
.stat-card:hover{
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}

      
        
        /* Hospital Section */
        .hospital-section {
            margin-bottom: 32px;
        }
        
        .hospital-header {
            background: linear-gradient(135deg, #a6c5e3 0%, #a6c5e3 100%);
            color: black;
            padding: 6px 10px;
            border-radius: 12px;
            margin-bottom: 5px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        
        .hospital-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .hospital-name {
            font-size: 20px;
            font-weight: 600;
        }
        
        .hospital-stats {
            display: flex;
            gap: 15px;
            font-size: 14px;
        }
        
        .hospital-stats span {
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        /* Building Section */
        .building-section {
            margin-left: 20px;
            margin-bottom: 24px;
        }
        
        .building-header {
            background: #adc4eb;
            padding: 7px 16px;
            border-radius: 10px;
            margin-bottom: 7px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        
        .building-header:hover {
            background: #6a93d8;
        }
        
        
        /* Floor Section */
        .floor-section {
            margin-left: 20px;
            margin-bottom: 20px;
        }
        
        .floor-header {
            background: #d4e1ef;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #2c7be5;
        }
        /* Room Section */
        .room-section {
            margin-left: 20px;
            margin-bottom: 20px;
        }
        
        .room-card {
            background: white;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        
        .room-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .room-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .room-title small {
            font-size: 12px;
            font-weight: normal;
            color: #666;
        }
        
        /* Beds Grid */
        .beds-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .bed-icon {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 70px;
            padding: 10px 5px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            position: relative;
        }
        /* Branch Section */
.branch-section{
    margin-left: 15px;
    margin-bottom: 20px;
}

.branch-header{
    background: #bfd3f2;
    padding: 8px 15px;
    border-radius: 10px;
    margin-bottom: 8px;
    cursor: pointer;
    display:flex;
    justify-content:space-between;
    align-items:center;
    transition:0.3s;
}

.branch-header:hover{
    background:#9fbce8;
}

.branch-name{
    font-size:17px;
    font-weight:600;
}
        .bed-icon:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .bed-icon.available {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .bed-icon.occupied {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        
        .bed-icon.maintenance {
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: #333;
        }
        
        .bed-number {
            font-size: 11px;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .bed-icon i {
            font-size: 24px;
        }
        
        /* Font Awesome fallback */
        .bed-icon .bed-icon-svg {
            font-size: 24px;
            font-style: normal;
        }
        
        .status-badge {
            font-size: 9px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-top: 4px;
            background: rgba(0,0,0,0.1);
        }
        
        /* Toggle icons */
        .toggle-icon {
            font-size: 18px;
            transition: transform 0.3s;
        }
        
        .collapsed .toggle-icon {
            transform: rotate(-90deg);
        }
        
        .collapsible-content {
            transition: all 0.3s ease;
        }
        
        .collapsed .collapsible-content {
            display: none;
        }
        
        /* No data */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            color: #999;
        }
        
        @media (max-width: 768px) {
            .bed-icon {
                width: 55px;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .hospital-stats {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<body>
<div class="main-content" id="mainContent">
<div class="container">
    <!-- 🏥Header -->
    
    <div class="top-bar">

    <!-- LEFT : BREADCRUMB -->
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Bed Dashboard</a>
    </div>

    <!-- CENTER : TITLE -->
    <h2 style="margin:0 auto;">Bed Dashboard</h2>

    <!-- RIGHT : FILTERS + ACTIONS -->
    <div style="display:flex; gap:10px; align-items:center;">

        <!-- HOSPITAL -->
        <select onchange="applyFilter()" id="hospital_filter">
            <option value="0">Hospital</option>
            <?php 
            $hospitals->data_seek(0);
            while($h = $hospitals->fetch_assoc()): ?>
                <option value="<?= $h['id'] ?>" <?= $selected_hospital==$h['id']?'selected':'' ?>>
                    <?= htmlspecialchars($h['hospital_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
<!-- BRANCH -->
<select onchange="applyFilter()" id="branch_filter">
    <option value="0">Branch</option>

    <?php 
    $branches->data_seek(0);
    while($br = $branches->fetch_assoc()): ?>
        <option value="<?= $br['id'] ?>" <?= $selected_branch==$br['id']?'selected':'' ?>>
            <?= htmlspecialchars($br['branch_name']) ?>
        </option>
    <?php endwhile; ?>
</select>
        <!-- BUILDING -->
        <select onchange="applyFilter()" id="building_filter">
            <option value="0">Building</option>
            <?php 
            $buildings->data_seek(0);
            while($b = $buildings->fetch_assoc()): ?>
                <option value="<?= $b['id'] ?>" <?= $selected_building==$b['id']?'selected':'' ?>>
                    <?= htmlspecialchars($b['building_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <!-- FLOOR -->
        <select onchange="applyFilter()" id="floor_filter">
            <option value="0">Floor</option>
            <?php 
            $floors->data_seek(0);
            while($f = $floors->fetch_assoc()): ?>
                <option value="<?= $f['id'] ?>" <?= $selected_floor==$f['id']?'selected':'' ?>>
                    <?= htmlspecialchars($f['floor_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <button class="btn btn-reset" onclick="resetFilters()">Reset</button>
        <!-- ACTION BUTTONS -->
        <button class="btn" onclick="openPrint()">Print</button>
        </div>
</div>
    <?php
    // Calculate statistics
    $stats_query = "SELECT 
        SUM(CASE WHEN bed.status = 'Available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN bed.status = 'Occupied' THEN 1 ELSE 0 END) as occupied,
        SUM(CASE WHEN bed.status = 'Maintenance' THEN 1 ELSE 0 END) as maintenance,
        COUNT(*) as total
    FROM bed_master bed";
    
    if ($selected_hospital > 0) {
        $stats_query .= " LEFT JOIN room_master r ON r.id = bed.room_id
                          LEFT JOIN floor_master f ON f.id = r.floor_id
                          LEFT JOIN building_master b ON b.id = f.building_id
                          WHERE b.hospital_id = $selected_hospital";
    }
    
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch_assoc();
    ?>
    <!-- Statistics -->
    <div class="stats">
         <div class="stat-card">
            <div class="stat-label">Total Beds : </div>
            <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
            
        </div>
        <div class="stat-card available">
            <div class="stat-label">Available:</div>
            <div class="stat-number"><?= $stats['available'] ?? 0 ?></div>
            
        </div>
        <div class="stat-card occupied">
            <div class="stat-label">Occupied:</div>
            <div class="stat-number"><?= $stats['occupied'] ?? 0 ?></div>
            
        </div>
        <div class="stat-card maintenance">
            <div class="stat-label">Maintenance:</div>
            <div class="stat-number"><?= $stats['maintenance'] ?? 0 ?></div>
            
        </div>
    </div>
    
  
    
    <!-- Bed View Hierarchy -->
    <?php if (empty($organized)): ?>
        <div class="no-data">
            <h3>No beds found</h3>
            <p>Please add rooms and beds to see them here.</p>
            </div>
    <?php else: ?>
        <?php foreach ($organized as $hospital_id => $hospital): ?>
            <div class="hospital-section" data-hospital-id="<?= $hospital_id ?>">
                <div class="hospital-header" onclick="toggleCollapse(this)">
                    <div class="hospital-name">🏥 Hospital : <?= htmlspecialchars($hospital['name']) ?></div>
                    <div class="hospital-stats">
                        <?php
                        $h_available = 0;
                        $h_occupied = 0;
                        $h_maintenance = 0;
                        foreach ($hospital['branches'] as $branch) {
                          foreach ($branch['buildings'] as $building) {
                            foreach ($building['floors'] as $floor) {
                                foreach ($floor['rooms'] as $room) {
                                    foreach ($room['beds'] as $bed) {
                                        if ($bed['status'] == 'Available') $h_available++;
                                        elseif ($bed['status'] == 'Occupied') $h_occupied++;
                                        elseif ($bed['status'] == 'Maintenance') $h_maintenance++;
                                    }
                                }
                            }
                        }
                        }
                        
                        ?>
                        <span>🟢 <?= $h_available ?></span>
                        <span>🔴 <?= $h_occupied ?></span>
                        <span>🟡 <?= $h_maintenance ?></span>
                        <span class="toggle-icon">▼</span>
                    </div>
                </div>
                
                <div class="collapsible-content">
                   <?php foreach ($hospital['branches'] as $branch_id => $branch): ?>

<div class="branch-section">
    <?php
$br_available = 0;
$br_occupied = 0;
$br_maintenance = 0;

foreach ($branch['buildings'] as $building) {
    foreach ($building['floors'] as $floor) {
        foreach ($floor['rooms'] as $room) {
            foreach ($room['beds'] as $bed) {

                if ($bed['status'] == 'Available') {
                    $br_available++;
                }
                elseif ($bed['status'] == 'Occupied') {
                    $br_occupied++;
                }
                elseif ($bed['status'] == 'Maintenance') {
                    $br_maintenance++;
                }

            }
        }
    }
}
?>

<div class="branch-header" onclick="toggleCollapse(this)">
    <div class="branch-name">
        🏢 Branch : <?= htmlspecialchars($branch['name']) ?>
    </div>

    <div style="display:flex; gap:10px;">
        <span>🟢 <?= $br_available ?></span>
        <span>🔴 <?= $br_occupied ?></span>
        <span>🟡 <?= $br_maintenance ?></span>
        <span class="toggle-icon">▼</span>
    </div>
</div>

    <div class="collapsible-content">

<?php foreach ($branch['buildings'] as $building_id => $building): ?>
<div class="building-section">
<?php
$b_available = 0;
$b_occupied = 0;
$b_maintenance = 0;

foreach ($building['floors'] as $floor) {
    foreach ($floor['rooms'] as $room) {
        foreach ($room['beds'] as $bed) {

            if ($bed['status'] == 'Available') {
                $b_available++;
            }
            elseif ($bed['status'] == 'Occupied') {
                $b_occupied++;
            }
            elseif ($bed['status'] == 'Maintenance') {
                $b_maintenance++;
            }

        }
    }
}
?>


<div class="building-header" onclick="toggleCollapse(this)">
    <div class="building-name">
        🏢 Building : <?= htmlspecialchars($building['name']) ?>
    </div>

    <div style="display:flex; gap:10px;">
        <span>🟢 <?= $b_available ?></span>
        <span>🔴 <?= $b_occupied ?></span>
        <span>🟡 <?= $b_maintenance ?></span>
        <span class="toggle-icon">▼</span>
    </div>
</div>
                            
                            <div class="collapsible-content">
                                <?php foreach ($building['floors'] as $floor_id => $floor): ?>
                                    <div class="floor-section">
                                        <?php
$f_available = 0;
$f_occupied = 0;
$f_maintenance = 0;

foreach ($floor['rooms'] as $room) {
    foreach ($room['beds'] as $bed) {
        if ($bed['status'] == 'Available') $f_available++;
        elseif ($bed['status'] == 'Occupied') $f_occupied++;
        elseif ($bed['status'] == 'Maintenance') $f_maintenance++;
    }
}
?>

<div class="floor-header" onclick="toggleCollapse(this)">
    <div class="floor-name">
        📊 Floor : <?= htmlspecialchars($floor['name']) ?>
    </div>

    <div style="display:flex; gap:10px;">
        <span>🟢 <?= $f_available ?></span>
        <span>🔴 <?= $f_occupied ?></span>
        <span>🟡 <?= $f_maintenance ?></span>
        <span class="toggle-icon">▼</span>
    </div>
</div>
                                        
                                        <div class="collapsible-content">
                                            <?php foreach ($floor['rooms'] as $room_id => $room): ?>
                                                <div class="room-section">
                                                    <div class="room-card">
                                                        <?php
$r_available = 0;
$r_occupied = 0;
$r_maintenance = 0;

foreach ($room['beds'] as $bed) {
    if ($bed['status'] == 'Available') $r_available++;
    elseif ($bed['status'] == 'Occupied') $r_occupied++;
    elseif ($bed['status'] == 'Maintenance') $r_maintenance++;
}
?>

<div class="room-header">
    <div class="room-title">
        🛏 Room <?= htmlspecialchars($room['number']) ?>
        <?php if (!empty($room['name'])): ?>
            <small>(<?= htmlspecialchars($room['name']) ?>)</small>
        <?php endif; ?>
    </div>

    <div style="display:flex; gap:10px;">
        <span>🟢 <?= $r_available ?></span>
        <span>🔴 <?= $r_occupied ?></span>
        <span>🟡 <?= $r_maintenance ?></span>
    </div>
</div>
                                                        
                                                        <div class="beds-grid">
                                                            <?php foreach ($room['beds'] as $bed): ?>
                                                                <div class="bed-icon <?= strtolower($bed['status']) ?>" 
                                                                     title="Bed <?= htmlspecialchars($bed['bed_number']) ?> - <?= $bed['status'] ?>">
                                                                    <div class="bed-icon-svg">🛏️</div>
                                                                    <div class="bed-number"><?= htmlspecialchars($bed['bed_number']) ?></div>
                                                                    <div class="status-badge"><?= $bed['status'] ?></div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        </div>
                    <?php endforeach; ?> <!-- building loop -->

    </div>
</div>

<?php endforeach; ?> <!-- branch loop -->

                </div>
            </div>
        <?php endforeach; ?> <!-- hospital loop -->
    <?php endif; ?>
</div>
</div>
</div>
</div> <!-- container -->
</div> <!-- main-content -->

<script>
function toggleCollapse(element) {
    const parent = element.parentElement;
    parent.classList.toggle('collapsed');
}

// Auto-expand if filtered
<?php if ($selected_hospital > 0 || $selected_building > 0 || $selected_floor > 0): ?>
document.querySelectorAll('.hospital-section, .building-section, .floor-section').forEach(section => {
    section.classList.remove('collapsed');
});
<?php endif; ?>

function applyFilter(){

    let h = document.getElementById('hospital_filter').value;
    let br = document.getElementById('branch_filter').value;
    let b = document.getElementById('building_filter').value;
    let f = document.getElementById('floor_filter').value;

    let url =
"?hospital_id="+h+
"&branch_id="+br+
"&building_id="+b+
"&floor_id="+f;

    window.location = "bed_dashboard.php"+url;
}
function resetFilters(){
    window.location = "bed_dashboard.php";
}
function openPrint(){
    window.open("bed_dashboard_print.php", "_blank");
}

</script>

</body>
</html>