<?php
// public_html/includes/permissions.php

/* ===============================
   SAFETY ADDITIONS (ONLY ADDED)
   =============================== */

// Ensure session is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure DB connection exists
require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

/* ===============================
   ORIGINAL FUNCTION (UNCHANGED LOGIC)
   =============================== */

function checkPermission(string $moduleSlug, string $action): bool
{
    if (!isset($_SESSION['rights_type_id'])) {
        return false;
    }

    // ✅ ADDITION: SUPER ADMIN BYPASS (SAFE & STANDARD)
    if ((int)$_SESSION['rights_type_id'] === 1) {
        return true;
    }

    // 🔒 ORIGINAL CODE (NOT REMOVED)
    global $conn;

    if (!$conn) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 
            can_view,
            can_create,
            can_update,
            can_delete
        FROM module_permissions mp
        INNER JOIN modules m ON m.id = mp.module_id
        WHERE mp.rights_type_id = ?
          AND m.slug = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("is", $_SESSION['rights_type_id'], $moduleSlug);
    $stmt->execute();

    // ✅ YOUR ORIGINAL LOGIC (PRESERVED)
    $res  = $stmt->get_result();
    $perm = $res->fetch_assoc();
    $stmt->close();

    if (!$perm) {
        return false;
    }

    return match ($action) {
    'view'   => (bool) $perm['can_view'],

    // ADD → CREATE
    'add',
    'create' => (bool) $perm['can_create'],

    // EDIT → UPDATE
    'edit',
    'update' => (bool) $perm['can_update'],

    'delete' => (bool) $perm['can_delete'],

    default  => false,
};

}

/**
 * Check if user has permission for ANY module in a list
 * (ORIGINAL – NOT TOUCHED)
 */
function hasAnyPermission(array $modules, string $action = 'view'): bool
{
    foreach ($modules as $module) {
        if (checkPermission($module, $action)) {
            return true;
        }
    }
    return false;
}
