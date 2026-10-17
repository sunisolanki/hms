<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$action = $_GET['action'] ?? '';

/*=========================================================
  LOAD FLOORS
=========================================================*/
if($action=='floors'){
    $building_id=(int)($_GET['building_id'] ?? 0);

    header('Content-Type:text/html; charset=UTF-8');

    echo '<option value="">Select Floor</option>';

    $q=mysqli_query($conn,"
        SELECT id,floor_name
        FROM floor_master
        WHERE building_id='$building_id'
        AND status='Active'
        ORDER BY floor_name
    ");

    while($r=mysqli_fetch_assoc($q)){
        echo '<option value="'.$r['id'].'">'.htmlspecialchars($r['floor_name']).'</option>';
    }
    exit;
}

/*=========================================================
  LOAD ROOMS
=========================================================*/
if($action=='rooms'){
    $floor_id=(int)($_GET['floor_id'] ?? 0);

    header('Content-Type:text/html; charset=UTF-8');

    echo '<option value="">Select Room</option>';

    $q=mysqli_query($conn,"
        SELECT id,room_number
        FROM room_master
        WHERE floor_id='$floor_id'
        AND status='Active'
        ORDER BY room_number
    ");

    while($r=mysqli_fetch_assoc($q)){
        echo '<option value="'.$r['id'].'">'.htmlspecialchars($r['room_number']).'</option>';
    }
    exit;
}

/*=========================================================
  LOAD AVAILABLE BEDS
=========================================================*/
if($action=='beds'){
    $room_id=(int)($_GET['room_id'] ?? 0);

    header('Content-Type:text/html; charset=UTF-8');

    echo '<option value="">Select Bed</option>';

    $q=mysqli_query($conn,"
        SELECT id,bed_number
        FROM bed_master
        WHERE room_id='$room_id'
        AND status='Available'
        ORDER BY bed_number
    ");

    while($r=mysqli_fetch_assoc($q)){
        echo '<option value="'.$r['id'].'">'.htmlspecialchars($r['bed_number']).'</option>';
    }
    exit;
}

/*=========================================================
  EXISTING ROOM DETAILS LOGIC (UNCHANGED)
=========================================================*/

header('Content-Type: application/json');

$id=(int)($_GET['id'] ?? 0);

$q=$conn->query("
SELECT
r.*,
f.id AS floor_id,
b.id AS building_id,
br.id AS branch_id,
h.id AS hospital_id

FROM room_master r
LEFT JOIN floor_master f ON f.id=r.floor_id
LEFT JOIN building_master b ON b.id=f.building_id
LEFT JOIN branch_master br ON br.id=b.branch_id
LEFT JOIN hospital_master h ON h.id=b.hospital_id

WHERE r.id=$id
");

if($q && $q->num_rows){

    $data=$q->fetch_assoc();


    /* LOAD ROOM CHARGES ONLY WHEN REQUESTED FROM ROOM MASTER EDIT */

    if(
        isset($_GET['load_charges']) 
        &&
        $_GET['load_charges']==1
    ){

        $data['room_charges']=[];


        $chargeQuery=$conn->query("
            SELECT
                charge_id,
                amount
            FROM room_charge_mapping
            WHERE room_id=$id
        ");


        while($charge=mysqli_fetch_assoc($chargeQuery)){

            $data['room_charges'][]=$charge;

        }

    }


    $data['success']=true;

    echo json_encode($data);

}else{
    echo json_encode([
        'success'=>false,
        'message'=>'Room not found'
    ]);
}