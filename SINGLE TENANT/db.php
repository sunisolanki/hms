<?php
date_default_timezone_set('Asia/Kolkata');

$databaseHost = "localhost";
$databaseName = "u336643111_hms";
$databaseUser = "u336643111_hms";
$databasePassword = "Rohtha@751";

$conn = new mysqli(
    $databaseHost,
    $databaseUser,
    $databasePassword,
    $databaseName
);

if ($conn->connect_error) {
    die("Database connection failed.");
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+05:30'");