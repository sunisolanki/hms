<!-- /public_html/includes/ipd_room_charges.php  -->
<?php
function calculateIPDRoomCharges($conn,$admission_id,$as_of_datetime=null){
    $admission_id=(int)$admission_id;
    if($admission_id<=0){
        return 0;
    }

    if($as_of_datetime===null || $as_of_datetime===''){
        $as_of_datetime=date('Y-m-d H:i:s');
    }

    $asOfDate=new DateTime($as_of_datetime);
    $totalRoomCharges=0;

    $roomStmt=$conn->prepare("
        SELECT
            ira.id AS allocation_id,
            ira.room_id,
            ira.allocated_date,
            ira.released_date,
            ira.status,
            rm.room_rent
        FROM ipd_room_allocation ira
        INNER JOIN room_master rm
            ON rm.id=ira.room_id
        WHERE ira.admission_id=?
        AND ira.allocated_date<=?
        ORDER BY ira.allocated_date ASC
    ");

    if(!$roomStmt){
        return 0;
    }

    $asOfString=$asOfDate->format('Y-m-d H:i:s');

    $roomStmt->bind_param(
        "is",
        $admission_id,
        $asOfString
    );

    $roomStmt->execute();

    $roomResult=$roomStmt->get_result();

    while($room=$roomResult->fetch_assoc()){

        $startDate=new DateTime($room['allocated_date']);

        if(!empty($room['released_date'])){

            $releaseDate=new DateTime($room['released_date']);

            if($releaseDate<$asOfDate){
                $endDate=$releaseDate;
            }else{
                $endDate=$asOfDate;
            }

        }else{

            $endDate=$asOfDate;
        }

        if($endDate<$startDate){
            continue;
        }

        $startBillingDate=clone $startDate;
        $startBillingDate->setTime(12,0,0);

        $endBillingDate=clone $endDate;
        $endBillingDate->setTime(12,0,0);

        if($startDate<$startBillingDate){
            $startBillingDate->modify('-1 day');
        }

        if($endDate<$endBillingDate){
            $endBillingDate->modify('-1 day');
        }

        $days=$startBillingDate->diff($endBillingDate)->days+1;

        if($days<1){
            $days=1;
        }

        $roomRent=(float)$room['room_rent'];

        $totalRoomCharges+=($roomRent*$days);

        $chargeStmt=$conn->prepare("
            SELECT
                rcm.amount,
                rcm.frequency
            FROM room_charge_mapping rcm
            WHERE rcm.room_id=?
            ORDER BY rcm.id ASC
        ");

        if(!$chargeStmt){
            continue;
        }

        $roomId=(int)$room['room_id'];

        $chargeStmt->bind_param(
            "i",
            $roomId
        );

        $chargeStmt->execute();

        $chargeResult=$chargeStmt->get_result();

        while($charge=$chargeResult->fetch_assoc()){

            $rate=(float)$charge['amount'];

            if($charge['frequency']==='Day Wise'){
                $totalRoomCharges+=($rate*$days);
            }else{
                $totalRoomCharges+=$rate;
            }
        }

        $chargeStmt->close();
    }

    $roomStmt->close();

    return $totalRoomCharges;
}