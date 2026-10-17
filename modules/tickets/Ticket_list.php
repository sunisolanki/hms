<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/*
|--------------------------------------------------------------------------
| PAGE ACCESS
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Tickets List</title>
<meta name="viewport"
      content="width=device-width, initial-scale=1">
<!-- FAVICON -->
<link rel="icon"
      type="image/png"
      href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon"
      href="/assets/img/hms_logo.png">
<link rel="stylesheet"
      href="/assets/css/style.css">
<style>
/* POPUP */

.support-modal{
    display:none;
    position:fixed;
    z-index:999999;
    left:0;
    top:0;
    width:100%;
    height:100vh;
    overflow:auto;
    background:rgba(0,0,0,0.55);
    backdrop-filter:blur(3px);
}

.support-box{
    background:#fff;
    border-radius:20px;
    overflow:hidden;
    position:relative;
    animation:supportPopup 0.22s ease;
    box-shadow:0 20px 60px rgba(0,0,0,0.25);
}

.support-close{
    position:absolute;
    right:18px;
    top:12px;
    font-size:30px;
    cursor:pointer;
    color:red;
    z-index:9999;
    font-weight:bold;
}

@keyframes supportPopup{

    from{
        opacity:0;
        transform:translateY(-20px) scale(0.96);
    }

    to{
        opacity:1;
        transform:translateY(0) scale(1);
    }

}
.ticket-id{
    font-family:monospace;
    font-weight:700;
    color:#2563eb;
}
.ticket-title{
    font-weight:600;
    color:#111827;
}
.ticket-desc{
    max-width:250px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.type-badge{
    padding:5px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    display:inline-block;
}
.type-issue{
    background:#fee2e2;
    color:#dc2626;
}
.type-req{
    background:#dcfce7;
    color:#16a34a;
}
.status-open{
    background:#dbeafe;
    color:#2563eb;
}
.status-progress{
    background:#fef3c7;
    color:#d97706;
}
.status-closed{
    background:#dcfce7;
    color:#16a34a;
}
.attachment-link{
    color:#2563eb;
    text-decoration:none;
    font-weight:600;
}
.attachment-link:hover{
    text-decoration:underline;
}
.ticket-status-dropdown{
    padding:7px 10px;
    border-radius:10px;
    border:1px solid #d1d5db;
    background:#fff;
    font-size:13px;
    color:#111827 !important;
    border:none !important;
    font-weight:600;
    cursor:pointer;
    outline:none;
    min-width:110px;
}
.ticket-status-dropdown:focus{
    border-color:#2563eb;
}
.status-open-bg{

    background:#dbeafe !important;
    color:#1d4ed8 !important;
    border:1px solid #bfdbfe !important;
}

.status-ongoing-bg{

    background:#fef3c7 !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

.status-reject-bg{

    background:#fee2e2 !important;
    color:#b91c1c !important;
    border:1px solid #fecaca !important;
}

.status-closed-bg{

    background:#dcfce7 !important;
    color:#15803d !important;
    border:1px solid #bbf7d0 !important;
}
/* STATUS TABS */
.ticket-tabs{
    display:flex;
    gap:12px;
    margin:18px;
    flex-wrap:wrap;
}
.ticket-tab{
    border:none;
    border-radius:12px;
    cursor:pointer;
    font-size:14px;
    transition:0.2s;
    background:#f3f4f6;
    color:#374151;
}
.ticket-tab:hover{
    transform:translateY(-1px);
}

.ticket-tab.active{

    background:#2563eb;
    color:#fff;
}

.open-tab{

    background:#dbeafe;
    color:#1d4ed8;
}

.ongoing-tab{

    background:#fef3c7;
    color:#b45309;
}

.reject-tab{

    background:#fee2e2;
    color:#b91c1c;
}

.closed-tab{

    background:#dcfce7;
    color:#15803d;
}
</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<body>
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">
            Home
        </a>
        <span>&gt;</span>
        <a>
            Tickets
        </a>
    </div>
    <h2 style="margin:0;">
        Support Tickets
    </h2>
    <button class="btn"
        onclick="openTicketPopup()">
    <i class="fas fa-plus-circle"></i>
    Add Ticket
</button>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>#</th>
    <th>Ticket ID</th>
    <th>Hospital</th>
    <th>Branch</th>
    <th>Module</th>
    <th>Type</th>
    <th>Title</th>
    <th>Description</th>
    <th>Issue Faced By</th>
    <th>Created By</th>
    <th>Created Date</th>
    <th>Attachment</th>
    <th>Status</th>
</tr>
</thead>
<tbody>
<?php
$sql = "
SELECT
    t.*,
    hm.hospital_name,
    bm.branch_name,
    u1.full_name AS issue_user,
    u2.full_name AS created_user
FROM tickets t
LEFT JOIN hospital_master hm
    ON hm.id = t.hospital_id
LEFT JOIN branch_master bm
    ON bm.id = t.branch_id
LEFT JOIN users u1
    ON u1.id = t.issue_faced_by
LEFT JOIN users u2
    ON u2.id = t.created_by
ORDER BY t.id DESC
";
$statusCounts = [
    'All'      => 0,
    'Open'     => 0,
    'Ongoing'  => 0,
    'Reject'   => 0,
    'Closed'   => 0

];
$countQuery = mysqli_query($conn, "
    SELECT status, COUNT(*) as total
    FROM tickets
    GROUP BY status
");
while($countRow = mysqli_fetch_assoc($countQuery)){
    $statusCounts[$countRow['status']] =
        $countRow['total'];
    $statusCounts['All'] +=
        $countRow['total'];
}
$res = mysqli_query($conn, $sql);

?>
<!-- STATUS FILTER TABS -->
<div class="ticket-tabs">
    <button class="ticket-tab active"
            data-status="All">
        All
        (<?= $statusCounts['All'] ?>)
    </button>
    <button class="ticket-tab open-tab"
            data-status="Open">
        Open
        (<?= $statusCounts['Open'] ?>)
    </button>
    <button class="ticket-tab ongoing-tab"
            data-status="Ongoing">
        Ongoing
        (<?= $statusCounts['Ongoing'] ?>)
    </button>
    <button class="ticket-tab reject-tab"
            data-status="Reject">
        Reject
        (<?= $statusCounts['Reject'] ?>)
    </button>
    <button class="ticket-tab closed-tab"
            data-status="Closed">
        Closed
        (<?= $statusCounts['Closed'] ?>)
    </button>
</div>
<?php
$sn = 1;
while($row = mysqli_fetch_assoc($res)){
    /*
    |--------------------------------------------------------------------------
    | TICKET NUMBER
    |--------------------------------------------------------------------------
    */
    $type_code = ($row['ticket_type'] == 'Requirement')
        ? 'R'
        : 'I';
    $ticket_no =
        'HS' .
        $row['hospital_id'] .
        '-' .
        $type_code .
        '-' .
        $row['id'];
    /*
    |--------------------------------------------------------------------------
    | STATUS CLASS
    |--------------------------------------------------------------------------
    */
    $status_class = 'status-open';
    if($row['status'] == 'In Progress'){
        $status_class = 'status-progress';
    }
    if($row['status'] == 'Closed'){

        $status_class = 'status-closed';
    }
?>
<tr data-status="<?= $row['status'] ?>">
    <!-- SN -->
    <td>
        <?= $sn++ ?>
    </td>
    <!-- TICKET ID -->
    <td class="ticket-id">
        <?= $ticket_no ?>
    </td>
    <!-- HOSPITAL -->
    <td>
        <?= htmlspecialchars($row['hospital_name']) ?>
    </td>
    <!-- BRANCH -->
    <td>
        <?= htmlspecialchars($row['branch_name']) ?>
    </td>
    <!-- MODULE -->
    <td>
        <?= htmlspecialchars($row['module_name']) ?>
    </td>
    <!-- TYPE -->
    <td>
        <span class="type-badge
            <?= ($row['ticket_type'] == 'Issue')
                ? 'type-issue'
                : 'type-req'
            ?>">
            <?= htmlspecialchars($row['ticket_type']) ?>
        </span>
    </td>
    <!-- TITLE -->
    <td class="ticket-title">
        <?= htmlspecialchars($row['title']) ?>
    </td>
    <!-- DESCRIPTION -->
    <td class="ticket-desc"
        title="<?= htmlspecialchars($row['description']) ?>">
        <?= htmlspecialchars($row['description']) ?>
    </td>
    <!-- ISSUE FACED BY -->
    <td>
        <?= htmlspecialchars($row['issue_user']) ?>
    </td>
    <!-- CREATED BY -->
    <td>
        <?= htmlspecialchars($row['created_user']) ?>
    </td>
    <!-- CREATED DATE -->
    <td>

        <?= date(
            'd-m-Y h:i A',
            strtotime($row['created_date'])
        ) ?>
    </td>
    <!-- ATTACHMENT -->
    <td>
        <?php if($row['attachment'] != ''){ ?>
            <a href="/uploads/tickets/<?= $row['attachment'] ?>"
               target="_blank"
               class="attachment-link">
                View
            </a>
        <?php } else { ?>
            —
        <?php } ?>
    </td>
    <!-- STATUS -->
<td>
    <select class="ticket-status-dropdown
<?php
if($row['status'] == 'Open'){

    echo ' status-open-bg';
}
elseif($row['status'] == 'Ongoing'){
    echo ' status-ongoing-bg';
}
elseif($row['status'] == 'Reject'){
    echo ' status-reject-bg';
}
elseif($row['status'] == 'Closed'){
    echo ' status-closed-bg';
}
?>
"
            onchange="updateTicketStatus(
    <?= $row['id'] ?>,
    this.value,
    this
            )">
        <option value="Open"
            <?= ($row['status'] == 'Open') ? 'selected' : '' ?>>
            Open
        </option>
        <option value="Ongoing"

    <?= ($row['status'] == 'Ongoing') ? 'selected' : '' ?>

    <?= ($row['status'] != 'Ongoing') ? 'disabled' : '' ?>>

    Ongoing

</option>
        <option value="Reject"
            <?= ($row['status'] == 'Reject') ? 'selected' : '' ?>>  
            Reject
        </option>
        <option value="Closed"
            <?= ($row['status'] == 'Closed') ? 'selected' : '' ?>>
            Closed
        </option>
    </select>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<!-- TICKET POPUP -->
<div class="support-modal"
     id="ticketModal">
    <div class="support-box"
         style="
            width:950px;
            max-width:95%;
            height:92vh;
            margin:20px auto;
         ">
        <!-- CLOSE -->
        <span class="support-close"
              onclick="closeTicketPopup()">
            &times;
        </span>
        <!-- BODY -->
        <div style="
            height:calc(92vh - 20px);
            overflow:auto;
        ">
            <iframe
                src="/modules/tickets/add_ticket.php"
                width="100%"
                height="100%"
                frameborder="0"
                style="
                    border:none;
                    display:block;
                    background:#fff;
                ">
            </iframe>
        </div>
    </div>
</div>
<script>
function openTicketPopup(){
    document.getElementById(
        'ticketModal'
    ).style.display = 'block';
}
function closeTicketPopup(){
    document.getElementById(
        'ticketModal'
    ).style.display = 'none';
    location.reload();
}
/* OUTSIDE CLICK */
window.onclick = function(event){
    const modal =
        document.getElementById(
            'ticketModal'
        );
    if(event.target == modal){
        closeTicketPopup();
    }
}
function updateTicketStatus(ticket_id, status, element){
    fetch(
        '/modules/tickets/update_ticket_status.php',
        {
            method:'POST',
            headers:{
                'Content-Type':
                'application/x-www-form-urlencoded'
            },
            body:
                'ticket_id=' + ticket_id +
                '&status=' + encodeURIComponent(status)
        }
    )
    .then(response => response.text())
    .then(data => {
        element.classList.remove(
            'status-open-bg',
            'status-ongoing-bg',
            'status-reject-bg',
            'status-closed-bg'
        );
        if(status == 'Open'){
            element.classList.add('status-open-bg');
        }
        else if(status == 'Ongoing'){
            element.classList.add('status-ongoing-bg');
        }
        else if(status == 'Reject'){
            element.classList.add('status-reject-bg');
        }
        else if(status == 'Closed'){
            element.classList.add('status-closed-bg');
        }
    });
}
/* STATUS FILTER */

document.querySelectorAll('.ticket-tab')
.forEach(tab => {

    tab.addEventListener('click', function(){

        document.querySelectorAll('.ticket-tab')
        .forEach(t => {

            t.classList.remove('active');

        });

        this.classList.add('active');

        let status =
            this.getAttribute('data-status');

        document.querySelectorAll(
            'tbody tr'
        )
        .forEach(row => {

            if(
                status == 'All' ||
                row.getAttribute('data-status')
                == status
            ){

                row.style.display = '';

            }
            else{

                row.style.display = 'none';

            }

        });

    });

});
</script>
</body>
</html>