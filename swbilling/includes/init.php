<?php
session_start();

require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}