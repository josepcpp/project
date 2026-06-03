<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: /project/auth/login.php");
    exit();
}

// F-12: IP restriction check — only runs when feature is enabled
(function() {
    // Requires db connection — db.php must be included before auth_check.php, or include it here.
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return; // silently skip if no DB yet

    $enabled_q = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='ip_restriction_enabled' LIMIT 1");
    if (!$enabled_q) return;
    $enabled_row = $enabled_q->fetch_assoc();
    if (!$enabled_row || $enabled_row['setting_value'] !== '1') return; // feature off

    // Get client IP — GAP-09: only trust X-Forwarded-For / HTTP_CLIENT_IP when the
    // direct connection (REMOTE_ADDR) comes from a trusted private/loopback address,
    // preventing a remote client from spoofing their IP via a crafted header.
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $is_trusted_proxy = (
        $remote_addr === '127.0.0.1' ||
        $remote_addr === '::1' ||
        substr($remote_addr, 0, 4) === '10.' ||
        substr($remote_addr, 0, 8) === '192.168.' ||
        (substr($remote_addr, 0, 7) === '172.' && intval(explode('.', $remote_addr)[1] ?? 0) >= 16
            && intval(explode('.', $remote_addr)[1] ?? 0) <= 31)
    );
    $client_ip = $remote_addr;
    if ($is_trusted_proxy) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'] as $k) {
            if (!empty($_SERVER[$k])) {
                $client_ip = trim(explode(',', $_SERVER[$k])[0]);
                break;
            }
        }
    }

    // Fetch all active whitelist entries
    $wl_q = $conn->query("SELECT ip_cidr FROM ip_restrictions WHERE is_active = 1");
    if (!$wl_q) return; // fail-open if table query fails

    $allowed = false;
    while ($row = $wl_q->fetch_assoc()) {
        $cidr = trim($row['ip_cidr']);
        // Exact match
        if ($cidr === $client_ip) { $allowed = true; break; }
        // CIDR range check (e.g. 192.168.1.0/24)
        if (strpos($cidr, '/') !== false) {
            list($subnet, $bits) = explode('/', $cidr, 2);
            $bits = intval($bits);
            if ($bits >= 0 && $bits <= 32) {
                $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
                if ((ip2long($client_ip) & $mask) === (ip2long($subnet) & $mask)) {
                    $allowed = true; break;
                }
            }
        }
    }

    if (!$allowed) {
        session_unset();
        session_destroy();
        header("Location: /project/auth/login.php?error=" . urlencode("Access denied: your IP ($client_ip) is not whitelisted."));
        exit();
    }
})();

// ── FORCE LOGOUT CHECK ────────────────────────────────────────────────────────
// An admin/superadmin can sign another account out. The marker (force_logout_at)
// only invalidates sessions that started BEFORE it — a fresh login is never kicked.
// The poll endpoint defines SUPPRESS_FORCE_LOGOUT_REDIRECT so it can report as JSON
// instead of triggering this hard redirect.
(function() {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return;
    if (!isset($_SESSION['user_id'])) return;

    // Grandfather sessions created before this feature existed — never kick them
    // retroactively; just anchor them from now on.
    if (!isset($_SESSION['login_at'])) { $_SESSION['login_at'] = time(); return; }

    $uid = intval($_SESSION['user_id']);
    $q = $conn->prepare("SELECT force_logout_at, force_logout_by, force_logout_by_role FROM users WHERE id = ? LIMIT 1");
    if (!$q) return;
    $q->bind_param("i", $uid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if (!$row || empty($row['force_logout_at'])) return;

    $forced_ts = strtotime($row['force_logout_at']);
    if ($forced_ts === false || $forced_ts <= intval($_SESSION['login_at'])) return; // stale / pre-login marker

    if (defined('SUPPRESS_FORCE_LOGOUT_REDIRECT')) return; // poll endpoint handles it as JSON

    $by   = $row['force_logout_by']      ?? '';
    $role = $row['force_logout_by_role'] ?? '';
    session_unset();
    session_destroy();
    header("Location: /project/auth/login.php?forced=1&by=" . urlencode($by) . "&by_role=" . urlencode($role));
    exit();
})();
