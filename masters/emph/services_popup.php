<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

/* LOGIN SAFETY */
if (!isset($_SESSION['user_id'])) {
    echo 'Session expired'; exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Select Services</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="/assets/css/style.css">

<!-- FAVICON -->
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<style>
body{background:#f5f5f5}
.container{max-width:95%;margin:auto;padding:15px}
.header-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#f1f1f1}
input[type="text"]{padding:6px;width:250px;border:1px solid #ccc;border-radius:4px}
.btn{padding:6px 12px;font-size:13px;border-radius:4px;cursor:pointer;border:none}
.btn-success{background:#28a745;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.checkbox{text-align:center}
</style>
</head>

<body>

<div class="container">

<div class="header-bar">
    <h3>Select Services</h3>

    <div>
        <input type="text" id="search" placeholder="Search service..."
               onkeyup="loadServices()">
    </div>
</div>

<table>
<thead>
<tr>
    <th style="width:40px">✔</th>
    <th style="width:120px">Service Code</th>
    <th>Service Name</th>
    <th style="width:120px">Amount</th>
</tr>
</thead>
<tbody id="serviceBody">
<tr>
    <td colspan="4" align="center">Loading...</td>
</tr>
</tbody>
</table>

<br>

<div style="text-align:right">
    <button class="btn btn-success" onclick="addServices()">Add Selected</button>
    <button class="btn btn-danger" onclick="window.close()">Cancel</button>
</div>

</div>

<script>
function loadServices(){
    let q = document.getElementById('search').value;

    fetch('fetch_services.php?search=' + encodeURIComponent(q))
        .then(res => res.json())
        .then(res => {

            let tbody = document.getElementById('serviceBody');
            tbody.innerHTML = '';

            if (!res.data || res.data.length === 0) {
                tbody.innerHTML =
                    '<tr><td colspan="4" align="center">No services found</td></tr>';
                return;
            }

            res.data.forEach(s => {
                let tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="checkbox">
                        <input type="checkbox" class="svc"
                            data-id="${s.id}"
                            data-code="${s.service_code}"
                            data-name="${s.service_name}"
                            data-amount="${s.amount}">
                    </td>
                    <td>${s.service_code}</td>
                    <td>${s.service_name}</td>
                    <td>${s.amount}</td>
                `;
                tbody.appendChild(tr);
            });
        });
}

function addServices(){
    let rows = [];
    document.querySelectorAll('.svc:checked').forEach(cb => {
        rows.push(cb.dataset);
    });

    if (rows.length === 0) {
        alert('Please select at least one service');
        return;
    }

    window.opener.addServicesToTable(rows);
    window.close();
}

loadServices();
</script>

</body>
</html>
