<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);
header('Content-Type: application/json');

$period = $_GET['period'] ?? 'day';

switch ($period) {

    // ── TODAY vs YESTERDAY ────────────────────────────────────────────────────
    case 'day':
        $labels  = array_map(fn($h) => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00', range(0, 23));
        $current  = array_fill(0, 24, 0);
        $previous = array_fill(0, 24, 0);

        $r = $conn->query("SELECT HOUR(created_at) AS h, SUM(total) AS rev FROM sales WHERE DATE(created_at) = CURDATE() GROUP BY h");
        if ($r) while ($row = $r->fetch_assoc()) $current[(int)$row['h']] = round(floatval($row['rev']), 2);

        $r = $conn->query("SELECT HOUR(created_at) AS h, SUM(total) AS rev FROM sales WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY GROUP BY h");
        if ($r) while ($row = $r->fetch_assoc()) $previous[(int)$row['h']] = round(floatval($row['rev']), 2);

        $curr_label = 'Today';
        $prev_label = 'Yesterday';
        break;

    // ── THIS WEEK vs LAST WEEK ────────────────────────────────────────────────
    case 'week':
        $day_names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $labels    = $day_names;
        $current   = array_fill(0, 7, 0);
        $previous  = array_fill(0, 7, 0);

        // MySQL WEEKDAY: 0=Mon … 6=Sun
        $r = $conn->query("SELECT WEEKDAY(created_at) AS wd, SUM(total) AS rev FROM sales WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) GROUP BY wd");
        if ($r) while ($row = $r->fetch_assoc()) $current[(int)$row['wd']] = round(floatval($row['rev']), 2);

        $r = $conn->query("SELECT WEEKDAY(created_at) AS wd, SUM(total) AS rev FROM sales WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1) GROUP BY wd");
        if ($r) while ($row = $r->fetch_assoc()) $previous[(int)$row['wd']] = round(floatval($row['rev']), 2);

        $curr_label = 'This Week';
        $prev_label = 'Last Week';
        break;

    // ── THIS MONTH vs LAST MONTH ──────────────────────────────────────────────
    case 'month':
        $days_in_month = (int)date('t');
        $labels        = array_map(fn($d) => (string)$d, range(1, $days_in_month));
        $current       = array_fill(0, $days_in_month, 0);
        $previous      = array_fill(0, $days_in_month, 0);

        $r = $conn->query("SELECT DAY(created_at) AS d, SUM(total) AS rev FROM sales WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) GROUP BY d");
        if ($r) while ($row = $r->fetch_assoc()) { $idx = (int)$row['d'] - 1; if ($idx < $days_in_month) $current[$idx] = round(floatval($row['rev']), 2); }

        $r = $conn->query("SELECT DAY(created_at) AS d, SUM(total) AS rev FROM sales WHERE YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) GROUP BY d");
        if ($r) while ($row = $r->fetch_assoc()) { $idx = (int)$row['d'] - 1; if ($idx < $days_in_month) $previous[$idx] = round(floatval($row['rev']), 2); }

        $curr_label = 'This Month (' . date('M Y') . ')';
        $prev_label = 'Last Month (' . date('M Y', strtotime('first day of last month')) . ')';
        break;

    // ── THIS YEAR vs LAST YEAR ────────────────────────────────────────────────
    default:
        $labels   = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $current  = array_fill(0, 12, 0);
        $previous = array_fill(0, 12, 0);

        $r = $conn->query("SELECT MONTH(created_at) AS m, SUM(total) AS rev FROM sales WHERE YEAR(created_at) = YEAR(CURDATE()) GROUP BY m");
        if ($r) while ($row = $r->fetch_assoc()) $current[(int)$row['m'] - 1] = round(floatval($row['rev']), 2);

        $r = $conn->query("SELECT MONTH(created_at) AS m, SUM(total) AS rev FROM sales WHERE YEAR(created_at) = YEAR(CURDATE()) - 1 GROUP BY m");
        if ($r) while ($row = $r->fetch_assoc()) $previous[(int)$row['m'] - 1] = round(floatval($row['rev']), 2);

        $curr_label = 'This Year (' . date('Y') . ')';
        $prev_label = 'Last Year (' . (date('Y') - 1) . ')';
        break;
}

echo json_encode([
    'labels'     => $labels,
    'current'    => $current,
    'previous'   => $previous,
    'curr_label' => $curr_label,
    'prev_label' => $prev_label,
    'period'     => $period,
]);
