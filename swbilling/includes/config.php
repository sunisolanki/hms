<?php

if (!defined('SWBILLING_PATH')) {
    define('SWBILLING_PATH', $_SERVER['DOCUMENT_ROOT'].'/swbilling/');
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'].'/');
}

require_once ROOT_PATH.'includes/db.php';
require_once ROOT_PATH.'includes/permissions.php';
require_once ROOT_PATH.'includes/audit_log.php';

date_default_timezone_set('Asia/Kolkata');