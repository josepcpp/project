<?php
/**
 * CSRF protection helpers.
 *
 * Usage:
 *   Forms  — echo csrf_field() inside any <form>
 *   Processors — csrf_verify() at the top; redirects back on failure
 */

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Return (or generate) the session CSRF token.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Return an HTML hidden input carrying the CSRF token.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Validate the CSRF token from $_POST.
 * On failure, redirect to $redirect_on_fail with an error parameter and exit.
 *
 * @param string $redirect_on_fail  URL to redirect to on token mismatch
 */
function csrf_verify(string $redirect_on_fail = ''): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if (!$expected || !hash_equals($expected, $submitted)) {
        // Rotate the token so replaying the old one keeps failing
        unset($_SESSION['csrf_token']);

        if ($redirect_on_fail !== '') {
            $sep = strpos($redirect_on_fail, '?') !== false ? '&' : '?';
            header("Location: {$redirect_on_fail}{$sep}error=" . urlencode("Security token mismatch. Please try again."));
        } else {
            http_response_code(403);
            echo "403 Forbidden — CSRF token mismatch.";
        }
        exit();
    }
}
