<?php
/**
 * require_role.php — Role-based access guard helper.
 *
 * Usage: require_role([ROLE_RECEIVER, ROLE_ADMIN, ROLE_SUPERADMIN]);
 *
 * Checks $_SESSION['role'] against $allowed_roles. Redirects on failure.
 * Call this at the top of every procurement pipeline page.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Abort with a redirect if the current session role is not in $allowed_roles.
 *
 * @param string[] $allowed_roles  Constants from config/constants.php
 * @param string   $redirect       URL to redirect to on failure (default: login page)
 */
function require_role(array $allowed_roles, string $redirect = ''): void {
    $role = strtolower($_SESSION['role'] ?? '');

    if (in_array($role, $allowed_roles, true)) {
        return; // allowed — continue
    }

    if ($redirect === '') {
        // Pick a sensible default based on whether the user is logged in at all
        if (empty($_SESSION['user_id'])) {
            $redirect = '/project/auth/login.php';
        } elseif (in_array($role, ['admin', 'owner', 'superadmin'])) {
            $redirect = '/project/staff/dashboard.php?error=' . urlencode('Access denied.');
        } else {
            $redirect = '/project/auth/login.php?error=' . urlencode('Access denied.');
        }
    }

    header("Location: $redirect");
    exit();
}
