<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Access Denied - WebSplend HMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

    <style>
        .denied-wrapper {
            padding-top: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f4f8fb;
        }
        .denied-card {
            background: #fff;
            padding: 4px;
            border-radius: 14px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0,0,0,0.12);
            max-width: 720px;
            width: 100%;
        }
        .denied-card img {
            max-width: 820px;
            margin-bottom: 2px;
        }
        .denied-card h2 {
            color: #d32f2f;
            margin-bottom: 2px;
        }
        .denied-card p {
            color: #555;
            font-size: 15px;
            margin-bottom: 5px;
        }
        .btn-dashboard {
            display: inline-block;
            background: #1976d2;
            color: #fff;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 16px;
                    }
        .btn-dashboard:hover {
            background: #125ea8;
        }
    </style>
</head>
<body>

<?php
/* COMMON HEADER */
require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>
<div class="denied-wrapper">
    <div class="denied-card">

        <!-- CARTOON IMAGE -->
        <img src="/assets/img/access_denied.png" alt="Access Denied">

        <h2>Access Denied</h2>
        <p>
            You do not have permission to access this page.<br>
            Please contact the administrator if you believe this is a mistake.
        </p>

        <a href="/dashboard/index.php" class="btn-dashboard">
            Go to Dashboard
        </a>

    </div>
</div>

</body>
</html>
