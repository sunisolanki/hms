<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| PUBLIC TENANT
|--------------------------------------------------------------------------
| If tenant_id is supplied in the public URL, use it.
| Otherwise keep the existing logged-in tenant session.
|--------------------------------------------------------------------------
*/

$publicTenantId = trim((string)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? ''));

if ($publicTenantId !== '') {

    if (!preg_match('/^HMS[0-9]+$/', $publicTenantId)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'message' => 'Invalid tenant ID.'
        ]);

        exit;
    }

    $_SESSION['tenant_id'] = $publicTenantId;
}

/*
|--------------------------------------------------------------------------
| If a logged-in user already has tenant_id, leave it untouched.
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['tenant_id'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Tenant ID is required.'
    ]);

    exit;
}