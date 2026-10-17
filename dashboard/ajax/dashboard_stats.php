<?php
ob_start();

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

function wsDashboardJson($data,$status=200){
    http_response_code($status);
    while(ob_get_level()>0){
        ob_end_clean();
    }
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function wsDashboardErrorHandler($severity,$message,$file,$line){
    if(!(error_reporting()&$severity)){
        return false;
    }

    wsDashboardJson([
        'error'=>'Dashboard server error',
        'message'=>$message
    ],500);
}

set_error_handler('wsDashboardErrorHandler');

register_shutdown_function(function(){
    $error=error_get_last();

    if($error!==null){
        $fatalTypes=[
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR
        ];

        if(in_array($error['type'],$fatalTypes,true)){
            while(ob_get_level()>0){
                ob_end_clean();
            }

            http_response_code(500);

            echo json_encode([
                'error'=>'Dashboard fatal error',
                'message'=>$error['message']
            ]);
        }
    }
});

if(!isset($_SESSION['user_id'])){
    wsDashboardJson([
        'error'=>'Unauthorized'
    ],401);
}

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if(!isset($conn)||!$conn){
    wsDashboardJson([
        'error'=>'Database connection unavailable'
    ],500);
}

$source=strtolower(
    trim($_POST['source']??'all')
);

$dateFilter=strtolower(
    trim($_POST['date_filter']??'today')
);

$createdDateFilter=strtolower(
    trim($_POST['created_date_filter']??'all')
);

$doctor=(int)(
    $_POST['doctor']??0
);

$department=(int)(
    $_POST['department']??0
);

$branch=(int)(
    $_POST['branch']??0
);

$hospital=(int)(
    $_POST['hospital']??0
);

$today=date('Y-m-d');

$yesterday=date(
    'Y-m-d',
    strtotime('-1 day')
);

/* =========================================================
   DATE RANGE
   ========================================================= */

function getDateRange(
    $filter,
    $today
){
    switch($filter){

        case 'today':
            return[
                $today,
                $today
            ];

        case 'yesterday':
            $d=date(
                'Y-m-d',
                strtotime('-1 day')
            );

            return[
                $d,
                $d
            ];

        case 'tomorrow':
            $d=date(
                'Y-m-d',
                strtotime('+1 day')
            );

            return[
                $d,
                $d
            ];

        case 'current_week':
            return[
                date(
                    'Y-m-d',
                    strtotime('monday this week')
                ),
                date(
                    'Y-m-d',
                    strtotime('sunday this week')
                )
            ];

        case 'current_month':
            return[
                date('Y-m-01'),
                date('Y-m-t')
            ];

        case 'last_month':
            return[
                date(
                    'Y-m-01',
                    strtotime('first day of last month')
                ),
                date(
                    'Y-m-t',
                    strtotime('last month')
                )
            ];

        case 'last_3_months':
            return[
                date(
                    'Y-m-d',
                    strtotime('-3 months')
                ),
                $today
            ];

        case 'last_6_months':
            return[
                date(
                    'Y-m-d',
                    strtotime('-6 months')
                ),
                $today
            ];

        case 'current_year':
            return[
                date('Y-01-01'),
                date('Y-12-31')
            ];

        case 'last_year':
            return[
                date(
                    'Y-01-01',
                    strtotime('-1 year')
                ),
                date(
                    'Y-12-31',
                    strtotime('-1 year')
                )
            ];

        case 'all':
        default:
            return[
                '2000-01-01',
                '9999-12-31'
            ];
    }
}

[
    $from,
    $to
]=getDateRange(
    $dateFilter,
    $today
);

$useCreatedFilter=
    $createdDateFilter!=='all';

$createdFrom='';
$createdTo='';

if($useCreatedFilter){

    [
        $createdFrom,
        $createdTo
    ]=getDateRange(
        $createdDateFilter,
        $today
    );
}

/* =========================================================
   DYNAMIC BIND
   ========================================================= */

function bindDynamic(
    $stmt,
    $types,
    $params
){
    if($types!==''){
        $stmt->bind_param(
            $types,
            ...$params
        );
    }
}

/* =========================================================
   APPOINTMENT WHERE
   ========================================================= */

function appointmentWhere(
    $from,
    $to,
    $source,
    $doctor,
    $department,
    $branch,
    $hospital,
    $createdFilter=false,
    $createdFrom='',
    $createdTo=''
){

    $where=
        " WHERE a.appointment_date BETWEEN ? AND ? ";

    $params=[
        $from,
        $to
    ];

    $types='ss';

    if($createdFilter){

        $where.=
            " AND DATE(a.created_at) BETWEEN ? AND ? ";

        $params[]=$createdFrom;
        $params[]=$createdTo;

        $types.='ss';
    }

    if($source==='website'){

        $where.=
            " AND a.source='WEBSITE' ";

    }elseif($source==='direct'){

        $where.=
            " AND a.source='INTERNAL' ";
    }

    if($doctor>0){

        $where.=
            " AND a.type='doctor'
              AND a.entity_id=? ";

        $params[]=$doctor;
        $types.='i';
    }

    if($department>0){

        $where.=
            " AND (
                a.department_id=?
                OR EXISTS(
                    SELECT 1
                    FROM user_departments ud
                    WHERE ud.user_id=a.entity_id
                    AND ud.department_id=?
                )
            ) ";

        $params[]=$department;
        $params[]=$department;

        $types.='ii';
    }

    if($branch>0){

        $where.=
            " AND EXISTS(
                SELECT 1
                FROM user_branches ub
                WHERE ub.user_id=a.entity_id
                AND ub.branch_id=?
            ) ";

        $params[]=$branch;
        $types.='i';
    }

    if($hospital>0){

        $where.=
            " AND EXISTS(
                SELECT 1
                FROM user_hospitals uh
                WHERE uh.user_id=a.entity_id
                AND uh.hospital_id=?
            ) ";

        $params[]=$hospital;
        $types.='i';
    }

    return[
        $where,
        $types,
        $params
    ];
}

/* =========================================================
   MAIN APPOINTMENT STATISTICS
   ========================================================= */

[
    $where,
    $types,
    $params
]=appointmentWhere(
    $from,
    $to,
    $source,
    $doctor,
    $department,
    $branch,
    $hospital,
    $useCreatedFilter,
    $createdFrom,
    $createdTo
);

$sql="
SELECT
    COUNT(a.id) AS total_appointments,

    COALESCE(
        SUM(a.consultation_fee),
        0
    ) AS total_fee,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Payment Pending'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS pending_payment_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Payment Pending'
                THEN COALESCE(a.balance_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS pending_payment_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Confirmed'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS appointments,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Confirmed'
                THEN COALESCE(a.paid_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS revenue,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Registered'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS registered_appointments,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Registered'
                THEN COALESCE(a.paid_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS registered_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Admit Advice'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS admit_advice_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Admit Advice'
                THEN COALESCE(a.consultation_fee,0)
                ELSE 0
            END
        ),
        0
    ) AS admit_advice_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Admitted'
                AND TRIM(COALESCE(a.opd_no,''))<>''
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS opd_to_ipd_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Admitted'
                AND TRIM(COALESCE(a.opd_no,''))<>''
                THEN COALESCE(a.paid_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS opd_to_ipd_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Admitted'
                AND TRIM(COALESCE(a.opd_no,''))=''
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS direct_ipd_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Admitted'
                AND TRIM(COALESCE(a.opd_no,''))=''
                THEN COALESCE(a.paid_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS direct_ipd_amount,

    COUNT(a.id) AS all_appointments,

    COALESCE(
        SUM(
            CASE
                WHEN a.status IN(
                    'Confirmed',
                    'Registered',
                    'Refund',
                    'Done',
                    'Prescription',
                    'Admit Advice',
                    'Admitted'
                )
                THEN COALESCE(a.paid_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS all_appointments_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Refund'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS refund_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Refund'
                THEN COALESCE(a.refund_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS refund,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Cancel'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS cancel_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Cancel'
                THEN COALESCE(a.refund_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS cancel_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Done'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS done_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Done'
                THEN COALESCE(a.paid_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS done_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Prescription'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS prescription_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.status='Prescription'
                THEN COALESCE(a.paid_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS prescription_amount,

    COALESCE(
        SUM(
            CASE
                WHEN a.is_emergency=1
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS emergency_count,

    COALESCE(
        SUM(
            CASE
                WHEN a.type='doctor'
                AND a.status NOT IN(
                    'Cancel',
                    'Refund',
                    'Admitted'
                )
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS opd_count

FROM appointments a
$where
";

$stmt=$conn->prepare($sql);

if(!$stmt){

    wsDashboardJson([
        'error'=>'Dashboard SQL prepare failed',
        'message'=>$conn->error
    ],500);
}

bindDynamic(
    $stmt,
    $types,
    $params
);

if(!$stmt->execute()){

    wsDashboardJson([
        'error'=>'Dashboard SQL execute failed',
        'message'=>$stmt->error
    ],500);
}

$result=$stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if(!$result){
    $result=[];
}

/* =========================================================
   DAY STATISTICS
   ========================================================= */

function getAppointmentDayStats(
    $conn,
    $date,
    $source,
    $doctor,
    $department,
    $branch,
    $hospital
){

    [
        $where,
        $types,
        $params
    ]=appointmentWhere(
        $date,
        $date,
        $source,
        $doctor,
        $department,
        $branch,
        $hospital,
        false
    );

    $sql="
    SELECT

        COUNT(a.id) AS total,

        COALESCE(
            SUM(
                COALESCE(a.paid_amount,0)
            ),
            0
        ) AS revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN a.type='doctor'
                    AND a.status NOT IN(
                        'Cancel',
                        'Refund',
                        'Admitted'
                    )
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS opd,

        COALESCE(
            SUM(
                CASE
                    WHEN a.status='Admitted'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS ipd,

        COALESCE(
            SUM(
                CASE
                    WHEN a.is_emergency=1
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS emergency

    FROM appointments a
    $where
    ";

    $stmt=$conn->prepare($sql);

    if(!$stmt){

        return[
            'total'=>0,
            'revenue'=>0,
            'opd'=>0,
            'ipd'=>0,
            'emergency'=>0
        ];
    }

    bindDynamic(
        $stmt,
        $types,
        $params
    );

    if(!$stmt->execute()){

        $stmt->close();

        return[
            'total'=>0,
            'revenue'=>0,
            'opd'=>0,
            'ipd'=>0,
            'emergency'=>0
        ];
    }

    $row=
        $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return $row?:[
        'total'=>0,
        'revenue'=>0,
        'opd'=>0,
        'ipd'=>0,
        'emergency'=>0
    ];
}

$todayStats=
    getAppointmentDayStats(
        $conn,
        $today,
        $source,
        $doctor,
        $department,
        $branch,
        $hospital
    );

$yesterdayStats=
    getAppointmentDayStats(
        $conn,
        $yesterday,
        $source,
        $doctor,
        $department,
        $branch,
        $hospital
    );

/* =========================================================
   PHARMACY
   ========================================================= */

$pharmacySql="
SELECT

    COALESCE(
        SUM(
            CASE
                WHEN status='Active'
                THEN COALESCE(grand_total,0)
                ELSE 0
            END
        ),
        0
    ) AS pharmacy_sales,

    COALESCE(
        SUM(
            CASE
                WHEN status='Active'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS pharmacy_bills,

    COALESCE(
        SUM(
            CASE
                WHEN status='Cancelled'
                THEN COALESCE(return_amount,0)
                ELSE 0
            END
        ),
        0
    ) AS pharmacy_returns

FROM pharmacy_sales

WHERE DATE(sale_date)=?
";

$pharmacyParams=[
    $today
];

$pharmacyTypes='s';

if($branch>0){

    $pharmacySql.=
        " AND branch_id=? ";

    $pharmacyParams[]=$branch;
    $pharmacyTypes.='i';
}

$stmt=$conn->prepare(
    $pharmacySql
);

if(!$stmt){

    wsDashboardJson([
        'error'=>'Pharmacy SQL prepare failed',
        'message'=>$conn->error
    ],500);
}

bindDynamic(
    $stmt,
    $pharmacyTypes,
    $pharmacyParams
);

if(!$stmt->execute()){

    wsDashboardJson([
        'error'=>'Pharmacy SQL execute failed',
        'message'=>$stmt->error
    ],500);
}

$pharmacy=
    $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

/* =========================================================
   REGISTRATIONS
   ========================================================= */

$registrationSql="
SELECT COUNT(*) AS registrations
FROM patient_master
WHERE DATE(created_at)=?
";

$stmt=$conn->prepare(
    $registrationSql
);

if(!$stmt){

    wsDashboardJson([
        'error'=>'Registration SQL prepare failed',
        'message'=>$conn->error
    ],500);
}

$stmt->bind_param(
    's',
    $today
);

$stmt->execute();

$registrationRow=
    $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

$registrations=(int)(
    $registrationRow['registrations']??0
);

/* =========================================================
   BEDS
   ========================================================= */

$bedSql="
SELECT

    COUNT(*) AS total_beds,

    COALESCE(
        SUM(
            CASE
                WHEN status='Available'
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS vacant_beds

FROM bed_master
";

$bedResult=$conn->query(
    $bedSql
);

if($bedResult){

    $bedRow=
        $bedResult->fetch_assoc();

}else{

    $bedRow=[
        'total_beds'=>0,
        'vacant_beds'=>0
    ];
}

$totalBeds=(int)(
    $bedRow['total_beds']??0
);

$vacantBeds=(int)(
    $bedRow['vacant_beds']??0
);

/* =========================================================
   OCCUPIED BEDS
   ========================================================= */

$occupiedBeds=0;

$occupiedSql="
SELECT COUNT(*) AS occupied_beds
FROM ipd_room_allocation
WHERE status='Occupied'
";

$occupiedResult=
    $conn->query(
        $occupiedSql
    );

if($occupiedResult){

    $occupiedBeds=(int)(
        $occupiedResult
        ->fetch_assoc()
        ['occupied_beds']??0
    );
}

/* =========================================================
   ADMITTED PATIENTS
   ========================================================= */

$admittedSql="
SELECT COUNT(*) AS admitted_patients
FROM ipd_admissions
WHERE status='Admitted'
";

$admittedResult=
    $conn->query(
        $admittedSql
    );

$admittedPatients=0;

if($admittedResult){

    $admittedPatients=(int)(
        $admittedResult
        ->fetch_assoc()
        ['admitted_patients']??0
    );
}

/* =========================================================
   7 DAY DATA
   ========================================================= */

$revenue7=[];
$opdIpd7=[];

for($i=6;$i>=0;$i--){

    $day=date(
        'Y-m-d',
        strtotime("-$i days")
    );

    $dayStats=
        getAppointmentDayStats(
            $conn,
            $day,
            $source,
            $doctor,
            $department,
            $branch,
            $hospital
        );

    $revenue7[]=[
        'date'=>$day,
        'label'=>date(
            'd M',
            strtotime($day)
        ),
        'revenue'=>
            (float)(
                $dayStats['revenue']??0
            )
    ];

    $opdIpd7[]=[
        'date'=>$day,
        'label'=>date(
            'd M',
            strtotime($day)
        ),
        'opd'=>
            (int)(
                $dayStats['opd']??0
            ),
        'ipd'=>
            (int)(
                $dayStats['ipd']??0
            )
    ];
}

/* =========================================================
   RECENT IPD ADMISSIONS
   ========================================================= */

$recentAdmissions=[];

$admissionSql="
SELECT

    ia.ipd_no,
    ia.admission_date,
    ia.admission_time,
    ia.admission_type,
    ia.is_emergency,
    ia.status,

    pm.first_name,
    pm.last_name,

    u.full_name AS doctor_name

FROM ipd_admissions ia

LEFT JOIN patient_master pm
    ON pm.id=ia.patient_id

LEFT JOIN users u
    ON u.id=ia.doctor_id

WHERE ia.status='Admitted'
";

$admissionParams=[];
$admissionTypes='';

if($hospital>0){

    $admissionSql.=
        " AND ia.hospital_id=? ";

    $admissionParams[]=$hospital;
    $admissionTypes.='i';
}

if($branch>0){

    $admissionSql.=
        " AND ia.branch_id=? ";

    $admissionParams[]=$branch;
    $admissionTypes.='i';
}

if($doctor>0){

    $admissionSql.=
        " AND ia.doctor_id=? ";

    $admissionParams[]=$doctor;
    $admissionTypes.='i';
}

if($department>0){

    $admissionSql.=
        " AND ia.department_id=? ";

    $admissionParams[]=$department;
    $admissionTypes.='i';
}

$admissionSql.="
ORDER BY
    ia.admission_date DESC,
    ia.admission_time DESC,
    ia.id DESC
LIMIT 8
";

$stmt=$conn->prepare(
    $admissionSql
);

if($stmt){

    bindDynamic(
        $stmt,
        $admissionTypes,
        $admissionParams
    );

    if($stmt->execute()){

        $res=
            $stmt
            ->get_result();

        while(
            $row=$res->fetch_assoc()
        ){

            $patientName=trim(
                ($row['first_name']??'').
                ' '.
                ($row['last_name']??'')
            );

            $recentAdmissions[]=[
                'ipd_no'=>
                    $row['ipd_no']??'',

                'patient_name'=>
                    $patientName!==''?
                    $patientName:
                    'Unknown',

                'admission_type'=>
                    $row['admission_type']??'',

                'doctor_name'=>
                    $row['doctor_name']??'',

                'admission_date'=>
                    $row['admission_date']??'',

                'is_emergency'=>
                    (int)(
                        $row['is_emergency']??0
                    ),

                'status'=>
                    $row['status']??''
            ];
        }
    }

    $stmt->close();
}

/* =========================================================
   OCCUPANCY
   ========================================================= */

$occupancyPercent=
    $totalBeds>0
        ?round(
            ($occupiedBeds/$totalBeds)*100
        )
        :0;

/* =========================================================
   FINAL JSON
   ========================================================= */

wsDashboardJson([

    'total_appointments'=>
        (int)(
            $result['total_appointments']??0
        ),

    'total_fee'=>
        number_format(
            (float)(
                $result['total_fee']??0
            ),
            2,
            '.',
            ''
        ),

    'pending_payment_count'=>
        (int)(
            $result['pending_payment_count']??0
        ),

    'pending_payment_amount'=>
        number_format(
            (float)(
                $result['pending_payment_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'appointments'=>
        (int)(
            $result['appointments']??0
        ),

    'revenue'=>
        number_format(
            (float)(
                $result['revenue']??0
            ),
            2,
            '.',
            ''
        ),

    'registered_appointments'=>
        (int)(
            $result['registered_appointments']??0
        ),

    'registered_amount'=>
        number_format(
            (float)(
                $result['registered_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'admit_advice_count'=>
        (int)(
            $result['admit_advice_count']??0
        ),

    'admit_advice_amount'=>
        number_format(
            (float)(
                $result['admit_advice_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'opd_to_ipd_count'=>
        (int)(
            $result['opd_to_ipd_count']??0
        ),

    'opd_to_ipd_amount'=>
        number_format(
            (float)(
                $result['opd_to_ipd_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'direct_ipd_count'=>
        (int)(
            $result['direct_ipd_count']??0
        ),

    'direct_ipd_amount'=>
        number_format(
            (float)(
                $result['direct_ipd_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'all_appointments'=>
        (int)(
            $result['all_appointments']??0
        ),

    'all_appointments_amount'=>
        number_format(
            (float)(
                $result['all_appointments_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'refund_count'=>
        (int)(
            $result['refund_count']??0
        ),

    'refund'=>
        number_format(
            (float)(
                $result['refund']??0
            ),
            2,
            '.',
            ''
        ),

    'cancel_count'=>
        (int)(
            $result['cancel_count']??0
        ),

    'cancel_amount'=>
        number_format(
            (float)(
                $result['cancel_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'done_count'=>
        (int)(
            $result['done_count']??0
        ),

    'done_amount'=>
        number_format(
            (float)(
                $result['done_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'prescription_count'=>
        (int)(
            $result['prescription_count']??0
        ),

    'prescription_amount'=>
        number_format(
            (float)(
                $result['prescription_amount']??0
            ),
            2,
            '.',
            ''
        ),

    'emergency_count'=>
        (int)(
            $result['emergency_count']??0
        ),

    'opd_count'=>
        (int)(
            $result['opd_count']??0
        ),

    'today'=>[
        'appointments'=>
            (int)(
                $todayStats['total']??0
            ),

        'revenue'=>
            (float)(
                $todayStats['revenue']??0
            ),

        'opd'=>
            (int)(
                $todayStats['opd']??0
            ),

        'ipd'=>
            (int)(
                $todayStats['ipd']??0
            ),

        'emergency'=>
            (int)(
                $todayStats['emergency']??0
            )
    ],

    'yesterday'=>[
        'appointments'=>
            (int)(
                $yesterdayStats['total']??0
            ),

        'revenue'=>
            (float)(
                $yesterdayStats['revenue']??0
            ),

        'opd'=>
            (int)(
                $yesterdayStats['opd']??0
            ),

        'ipd'=>
            (int)(
                $yesterdayStats['ipd']??0
            ),

        'emergency'=>
            (int)(
                $yesterdayStats['emergency']??0
            )
    ],

    'pharmacy'=>[
        'sales'=>
            (float)(
                $pharmacy['pharmacy_sales']??0
            ),

        'bills'=>
            (int)(
                $pharmacy['pharmacy_bills']??0
            ),

        'returns'=>
            (float)(
                $pharmacy['pharmacy_returns']??0
            )
    ],

    'registrations'=>$registrations,

    'beds'=>[
        'total'=>$totalBeds,
        'occupied'=>$occupiedBeds,
        'vacant'=>$vacantBeds,
        'occupancy_percent'=>$occupancyPercent,
        'admitted_patients'=>$admittedPatients
    ],

    'revenue_7_days'=>$revenue7,

    'opd_ipd_7_days'=>$opdIpd7,

    'recent_admissions'=>$recentAdmissions

]);