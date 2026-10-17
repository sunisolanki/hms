<?php

function addAuditLog(
    $module,
    $action,
    $description = '',
    $record_id = null,
    $old_values = null,
    $new_values = null,
    $status = 'SUCCESS'
){
    global $conn;

    $user_id       = $_SESSION['user_id'] ?? null;
    $username      = $_SESSION['username'] ?? '';
    $employee_name = $_SESSION['name'] ?? '';
    $role_name     = $_SESSION['role_name'] ?? '';
    $branch_id     = $_SESSION['branch_id'] ?? null;

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $city = '';
$state = '';
$country = '';

$location = @json_decode(
    @file_get_contents("http://ip-api.com/json/" . $ip_address),
    true
);

if (!empty($location) && $location['status'] == 'success') {
    $city    = $location['city'] ?? '';
    $state   = $location['regionName'] ?? '';
    $country = $location['country'] ?? '';
}
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$browser = 'Unknown';

if (stripos($userAgent, 'Edg') !== false) {
    $browser = 'Microsoft Edge';
} elseif (stripos($userAgent, 'Chrome') !== false) {
    $browser = 'Google Chrome';
} elseif (stripos($userAgent, 'Firefox') !== false) {
    $browser = 'Mozilla Firefox';
} elseif (stripos($userAgent, 'Safari') !== false) {
    $browser = 'Safari';
} elseif (stripos($userAgent, 'Opera') !== false || stripos($userAgent, 'OPR') !== false) {
    $browser = 'Opera';
}
    $session_id = session_id();

    $os = 'Unknown';

    if (stripos($userAgent, 'Windows') !== false) {
    $os = 'Windows';
} elseif (stripos($userAgent, 'Android') !== false) {
    $os = 'Android';
} elseif (stripos($userAgent, 'iPhone') !== false) {
    $os = 'iPhone';
} elseif (stripos($userAgent, 'iPad') !== false) {
    $os = 'iPad';
} elseif (stripos($userAgent, 'Mac') !== false) {
    $os = 'MacOS';
} elseif (stripos($userAgent, 'Linux') !== false) {
    $os = 'Linux';
}

    $device_type = 'Desktop';

    if (preg_match('/mobile/i', $userAgent)) {
        $device_type = 'Mobile';
    } elseif (preg_match('/tablet|ipad/i', $userAgent)) {
        $device_type = 'Tablet';
    }

    $stmt = $conn->prepare("
        INSERT INTO audit_logs
        (
            log_time,
            user_id,
            username,
            employee_name,
            role_name,
            branch_id,
            module_name,
            action_type,
            record_id,
            description,
            old_values,
            new_values,
            ip_address,
            city,
            state,
            country,
            browser,
            os,
            device_type,
            session_id,
            status
        )
        VALUES
(
    NOW(),
    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
)
    ");

   $stmt->bind_param(
    "isssisssssssssssssss",
    $user_id,
    $username,
    $employee_name,
    $role_name,
    $branch_id,
    $module,
    $action,
    $record_id,
    $description,
    $old_values,
    $new_values,
    $ip_address,
    $city,
    $state,
    $country,
    $browser,
    $os,
    $device_type,
    $session_id,
    $status
);

    $stmt->execute();
    $stmt->close();
}
?>