<?php
session_start();

/* LOGIN CHECK */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - WebSplend HMS</title>

    <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">

    <style>
        body {
            margin: 0;
            background: #f4f8fb;
            font-family: Arial, sans-serif;
        }

        .layout { display: flex; }

        .main-content {
            margin-left: 240px;
            padding: 10px;
            width: 100%;
            transition: .3s;
        }

        .sidebar.collapsed + .main-content {
            margin-left: 70px;
        }

        /* FILTER BAR */
        .filter-bar {
            background: #fff;
            padding: 10px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,.08);
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .filter-bar select,
        .filter-bar input {
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 13px;
            margin-right: 30px;
        }

        /* STATS CARDS */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
        }

        .stat-card {
            background: #fff;
            padding: 10px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,.08);
            text-align: center;
        }

        .stat-card h4 {
            margin: 0;
            font-size: 13px;
            color: #666;
        }

        .stat-card p {
            margin: 5px 0 0;
            font-size: 22px;
            font-weight: bold;
        }

        .blue { color: #2f80ed; }
        .green { color: #27ae60; }
        .red { color: #e74c3c; }
        .dark { color: #34495e; }
.stat-card hr {
    border: none;
    border-top: 1px solid #eee;
    margin: 5px 0;
}

.announcement-bar{
    margin:10px;
}

.announcement-card{
    display:flex;
    align-items:flex-start;
    gap:10px;
    background:#fff3cd;
    border-left:4px solid #ffc107;
    padding:10px;
    margin-bottom:8px;
    border-radius:6px;
    position:relative;
    box-shadow:0 2px 6px rgba(0,0,0,0.05);
}

.ann-icon{
    font-size:18px;
}

.ann-content{
    flex:1;
    font-size:13px;
}

.ann-content p{
    margin:3px 0;
}

.ann-content a{
    margin-right:10px;
    font-size:12px;
    color:#007bff;
}

.ann-close{
    cursor:pointer;
    font-size:16px;
    color:#666;
}


        @media(max-width: 900px) {
            .filter-bar { grid-template-columns: repeat(2, 1fr); }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>


<div class="layout">

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" id="mainContent">


<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$user_id = $_SESSION['user_id'];

// FETCH ACTIVE ANNOUNCEMENTS
$sql = "
SELECT a.*
FROM announcements a
LEFT JOIN announcement_users au 
    ON a.id = au.announcement_id
WHERE 
    a.is_active = 1
    AND NOW() BETWEEN a.start_date AND a.end_date
    AND (
        a.is_global = 1
        OR au.user_id = '$user_id'
    )
ORDER BY a.id DESC
";

$resA = $conn->query($sql);

if($resA->num_rows > 0):
?>

<div class="announcement-bar">

<?php while($a = $resA->fetch_assoc()): ?>

<div class="announcement-card">

    <div class="ann-icon">📢</div>

    <div class="ann-content">
        <strong><?= htmlspecialchars($a['title']) ?></strong>
        <p><?= htmlspecialchars($a['description']) ?></p>

        <?php if(!empty($a['url'])): ?>
            <?php
$url = $a['url'];

if(!empty($url)){
    if(!preg_match("~^(?:f|ht)tps?://~i", $url)){
        $url = "https://" . $url;
    }
}
?>

<?php if(!empty($a['url'])): ?>
    <a href="<?= $url ?>" target="_blank" rel="noopener noreferrer">View</a>
<?php endif; ?>
        <?php endif; ?>

        <?php if(!empty($a['file_path'])): ?>
            <a href="<?= $a['file_path'] ?>" target="_blank">Download</a>
        <?php endif; ?>
    </div>

    <span class="ann-close" onclick="this.parentElement.remove()">×</span>

</div>

<?php endwhile; ?>

</div>

<?php endif; ?>



    <!-- TITLE -->
    <h2>Dashboard</h2>

    <!-- FILTER BAR -->
    <div class="filter-bar">
        <!-- Source -->
        <select id="source" style="width:110px;">
            <option value="All">All Sources</option>
            <option value="Website">Website</option>
            <option value="Direct">Direct</option>
        </select>

        <!-- Date -->
        <select id="date_filter" style="width:140px;">
            
        <!--     <option value="all">All</option> -->
            <option value="all">Appointment Date</option>
            <option value="current_month">Current Month</option>
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="current_week">Current Week</option>
            <option value="last_month">Last Month</option>
            <option value="last_3_months">Last 3 Months</option>
            <option value="last_6_months">Last 6 Months</option>
            <option value="current_year">Current Year</option>
            <option value="last_year">Last Year</option>
            </select>

<!-- Created Date -->
<select id="created_date_filter" style="width:120px;">
    <option value="all">Created Date</option>
    <option value="current_month">Current Month</option>
    <option value="today">Today</option>
    <option value="yesterday">Yesterday</option>
    <option value="current_week">Current Week</option>
    <option value="last_month">Last Month</option>
    <option value="last_3_months">Last 3 Months</option>
    <option value="last_6_months">Last 6 Months</option>
    <option value="current_year">Current Year</option>
    <option value="last_year">Last Year</option>
</select>



        <!-- Doctor -->
       <select id="doctor" style="width:180px;">
    <option value="">All Doctors</option>
</select>
<!-- Departments -->
<select id="department"style="width:140px;">
    <option value="">All Departments</option>
</select>


        <!-- Branch -->
        <select id="branch"style="width:140px;">
            <option value="">All Branches</option>
        </select>

        <!-- Hospital -->
        <select id="hospital" style="width:140px;">
            <option value="">All Hospitals</option>
        </select>
    </div>

    <!-- STATS -->
    <div class="stats-row">

<!-- CARD: Total Appointments -->
<div class="stat-card">
    <h4>Total Appointments</h4>
    <p class="dark" id="total_appointments">0</p>
    <hr>
    <h4>Total Fee</h4>
    <p class="green" id="total_fee">₹0</p>
</div>

<!-- CARD: Pending Payment 
<div class="stat-card">
    <h4>Pending Payment</h4>
    <p class="red" id="pending_payment_count">0</p>
    <hr>
    <h4>Pending Amount</h4>
    <p class="red" id="pending_payment_amount">₹0</p>
</div>
    ---------------------------------------------------------------->
<!-- CARD: Confirmed Payment -->
    <div class="stat-card">
        <h4>Confirmed Appointments</h4>
        <p class="blue" id="appointments">0</p>
        <hr>
        <h4>Confirmed Amount</h4>
        <p class="green" id="revenue">₹0</p>
    </div>

    <!-- CARD 2 -->
    <div class="stat-card">
        <h4>Refunds</h4>
        <p class="blue" id="refund_count">0</p>
        <hr>
        <h4>Refund Amount</h4>
        <p class="red"><span id="refund">0</span></p>
    </div>
<!-- CARD 3 -->
    <div class="stat-card">
    <h4>Cancelled</h4>
    <p class="red" id="cancel_count">0</p>
    <hr>
    <h4>Cancelled Amount</h4>
    <p class="red" id="cancel_amount">₹0</p>
</div>

    <!-- CARD 3 -->
<div class="stat-card">
    <h4>Done</h4>
    <p class="blue" id="done_count">0</p>
    <hr>
    <h4>Done Amount</h4>
    <p class="green" id="done_amount">₹0</p>
</div>

    <!-- CARD 3 -->
<div class="stat-card">
    <h4>Prescription</h4>
    <p class="blue" id="prescription_count">0</p>
    <hr>
    <h4>Prescription Amount</h4>
    <p class="blue" id="prescription_amount">₹0</p>
</div>

    <!-- CARD 3 -->
<div class="stat-card">
    <h4>Registered</h4>
    <p class="blue" id="remaining_appointments">0</p>
    <hr>
    <h4>Registered Amount</h4>
    <p class="dark" id="net_revenue">₹0</p>
</div>
<!-- ADMIT ADVICE -->
<div class="stat-card">
    <h4>Admit Advice</h4>
    <p class="blue" id="admit_advice">0</p>
    <hr>
    <h4>Total Fee:<h4>
        <p class="blue" id="admit_advice_amount">₹0</p>
</div>
<!-- OPD -> IPD -->
<div class="stat-card">
    <h4>OPD → IPD</h4>
    <p class="blue" id="opd_to_ipd_count">0</p>
    <hr>
    <h4>Total Amount</h4>
    <p class="green" id="opd_to_ipd_amount">₹0</p>
</div>

<!-- DIRECT IPD -->
<div class="stat-card">
    <h4>Direct IPD</h4>
    <p class="blue" id="direct_ipd_count">0</p>
    <hr>
    <h4>Total Amount</h4>
    <p class="green" id="direct_ipd_amount">₹0</p>
</div>
<!-- CARD: All Appointments -->
<div class="stat-card">
    <h4>All Patients</h4>
    <p class="blue" id="all_appointments">0</p>
    <hr>
    <h4>Total Amount</h4>
    <p class="green" id="all_appointments_amount">₹0</p>
</div>
</div>
<br>
<hr>
<br>
<!-- IPD STATS -->
<div class="stats-row">

    <!-- CARD 1: ADMITTED PATIENTS -->
    <div class="stat-card">
        <h4>No. Admitted Patients</h4>
        <p class="blue" id="admitted_patients">0</p>
    </div>
    <!-- CARD 2: VACANT BEDS -->
<div class="stat-card">
    <h4>No. of Vacant Beds</h4>
    <p class="blue" id="vacant_beds">0</p>
</div>

</div>
<script>
/* =========================
   ELEMENT REFERENCES
========================= */

const source = document.getElementById('source');
const date_filter = document.getElementById('date_filter');
const created_date_filter = document.getElementById('created_date_filter');
const doctor = document.getElementById('doctor');
const department = document.getElementById('department');
const branch = document.getElementById('branch');
const hospital = document.getElementById('hospital');

/* =========================
   SET DASHBOARD VALUE
========================= */

function setDashboardValue(id, value) {

    const element = document.getElementById(id);

    if (!element) {
        console.error('Dashboard element not found:', id);
        return;
    }

    element.textContent = value;
}

/* =========================
   LOAD FILTER DROPDOWNS
========================= */

function loadFilters() {

    return fetch('/dashboard/ajax/dashboard_filters.php')
        .then(response => {

            if (!response.ok) {
                throw new Error(
                    'Filter request failed: HTTP ' +
                    response.status
                );
            }

            return response.json();

        })
        .then(data => {

            /* DOCTORS */

            doctor.innerHTML =
                '<option value="">All Doctors</option>';

            if (
                data.doctors &&
                Array.isArray(data.doctors)
            ) {

                data.doctors.forEach(d => {

                    doctor.innerHTML +=
                        `<option value="${d.doctor_id}">
                            ${d.doctor_name}
                        </option>`;

                });

            }

            /* DEPARTMENTS */

            department.innerHTML =
                '<option value="">All Departments</option>';

            if (
                data.departments &&
                Array.isArray(data.departments)
            ) {

                data.departments.forEach(dep => {

                    department.innerHTML +=
                        `<option value="${dep.department_id}">
                            ${dep.department_name}
                        </option>`;

                });

            }

            /* BRANCHES */

            branch.innerHTML =
                '<option value="">All Branches</option>';

            if (
                data.branches &&
                Array.isArray(data.branches)
            ) {

                data.branches.forEach(b => {

                    branch.innerHTML +=
                        `<option value="${b.branch_id}">
                            ${b.branch_name}
                        </option>`;

                });

            }

            /* HOSPITALS */

            hospital.innerHTML =
                '<option value="">All Hospitals</option>';

            if (
                data.hospitals &&
                Array.isArray(data.hospitals)
            ) {

                data.hospitals.forEach(h => {

                    hospital.innerHTML +=
                        `<option value="${h.hospital_id}">
                            ${h.hospital_name}
                        </option>`;

                });

            }

        })
        .catch(error => {

            console.error(
                'Filter load error:',
                error
            );

        });
}

/* =========================
   RENDER DASHBOARD
========================= */

function renderDashboard(data) {

    console.log(
        'Rendering Dashboard:',
        data
    );

    setDashboardValue(
        'total_appointments',
        data.total_appointments ?? 0
    );

    setDashboardValue(
        'total_fee',
        '₹' + (data.total_fee ?? '0.00')
    );

    setDashboardValue(
        'pending_payment_count',
        data.pending_payment_count ?? 0
    );

    setDashboardValue(
        'pending_payment_amount',
        '₹' + (data.pending_payment_amount ?? '0.00')
    );

    setDashboardValue(
        'appointments',
        data.appointments ?? 0
    );

    setDashboardValue(
        'revenue',
        '₹' + (data.revenue ?? '0.00')
    );

    setDashboardValue(
        'refund_count',
        data.refund_count ?? 0
    );

    setDashboardValue(
        'refund',
        '₹' + (data.refund ?? '0.00')
    );

    setDashboardValue(
        'cancel_count',
        data.cancel_count ?? 0
    );

    setDashboardValue(
        'cancel_amount',
        '₹' + (data.cancel_amount ?? '0.00')
    );

    setDashboardValue(
        'done_count',
        data.done_count ?? 0
    );

    setDashboardValue(
        'done_amount',
        '₹' + (data.done_amount ?? '0.00')
    );

    setDashboardValue(
        'prescription_count',
        data.prescription_count ?? 0
    );

    setDashboardValue(
        'prescription_amount',
        '₹' + (data.prescription_amount ?? '0.00')
    );

    setDashboardValue(
        'remaining_appointments',
        data.registered_appointments ?? 0
    );

    setDashboardValue(
        'net_revenue',
        '₹' + (data.registered_amount ?? '0.00')
    );
    

setDashboardValue(
    'admit_advice',
    data.admit_advice_count ?? 0
);

setDashboardValue(
    'admit_advice_amount',
    data.admit_advice_amount ?? '0.00'
);

setDashboardValue(
    'opd_to_ipd_count',
    data.opd_to_ipd_count ?? 0
);

setDashboardValue(
    'direct_ipd_count',
    data.direct_ipd_count ?? 0
);
setDashboardValue(
    'opd_to_ipd_amount',
    '₹' + (data.opd_to_ipd_amount ?? '0.00')
);

setDashboardValue(
    'direct_ipd_amount',
    '₹' + (data.direct_ipd_amount ?? '0.00')
);
    setDashboardValue(
        'all_appointments',
        data.all_appointments ?? 0
    );

    setDashboardValue(
        'all_appointments_amount',
        '₹' + (data.all_appointments_amount ?? '0.00')
    );

    /*
    Force browser repaint after values are updated.
    */

    requestAnimationFrame(() => {

        setDashboardValue(
            'total_appointments',
            data.total_appointments ?? 0
        );

        setDashboardValue(
            'total_fee',
            '₹' + (data.total_fee ?? '0.00')
        );

        setDashboardValue(
            'appointments',
            data.appointments ?? 0
        );

        setDashboardValue(
            'revenue',
            '₹' + (data.revenue ?? '0.00')
        );

        setDashboardValue(
            'remaining_appointments',
            data.registered_appointments ?? 0
        );

        setDashboardValue(
            'all_appointments',
            data.all_appointments ?? 0
        );

        setDashboardValue(
            'all_appointments_amount',
            '₹' + (data.all_appointments_amount ?? '0.00')
        );

    });

}

/* =========================
   LOAD STATS
========================= */

function loadStats() {

    const formData = new FormData();

    formData.append(
        'source',
        source ? source.value : 'All'
    );

    formData.append(
        'date_filter',
        date_filter ? date_filter.value : 'all'
    );

    formData.append(
        'created_date_filter',
        created_date_filter
            ? created_date_filter.value
            : 'all'
    );

    formData.append(
        'doctor',
        doctor ? doctor.value : ''
    );

    formData.append(
        'department',
        department ? department.value : ''
    );

    formData.append(
        'branch',
        branch ? branch.value : ''
    );

    formData.append(
        'hospital',
        hospital ? hospital.value : ''
    );

    console.log(
        'Loading dashboard stats...'
    );

    fetch(
        '/dashboard/ajax/dashboard_stats.php',
        {
            method: 'POST',
            body: formData,
            cache: 'no-store'
        }
    )
    .then(response => {

        if (!response.ok) {

            throw new Error(
                'Dashboard request failed: HTTP ' +
                response.status
            );

        }

        return response.json();

    })
    .then(data => {

        console.log(
            'Dashboard Stats:',
            data
        );

        renderDashboard(data);

    })
    .catch(error => {

        console.error(
            'Dashboard error:',
            error
        );

    });
}

/* =========================
   FILTER CHANGE
========================= */

[
    source,
    date_filter,
    created_date_filter,
    doctor,
    department,
    branch,
    hospital
].forEach(element => {

    if (!element) {
        return;
    }

    element.addEventListener(
        'change',
        function () {

            loadStats();

        }
    );

});
/* =========================
   INITIAL LOAD
========================= */
loadFilters()
    .then(() => {

        loadStats();
        loadIPDStats();

    })
    .catch(error => {
        console.error(
            'Dashboard initialization error:',
            error
        );

        loadStats();
    });
    function loadIPDStats() {

    fetch('/dashboard/ajax/ipd_stats.php', {
        method: 'GET',
        cache: 'no-store'
    })
    .then(response => {

        if (!response.ok) {
            throw new Error(
                'IPD request failed: HTTP ' +
                response.status
            );
        }

        return response.json();

    })
    .then(data => {

        console.log('IPD Stats:', data);

        setDashboardValue(
            'admitted_patients',
            data.admitted_patients ?? 0
        );
         setDashboardValue(
        'vacant_beds',
        data.vacant_beds ?? 0
    );

    })
    .catch(error => {

        console.error(
            'IPD dashboard error:',
            error
        );

    });
}
</script>



</body>
</html>
