<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Month and Year navigation
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Handle user schedule request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_schedule'])) {
    $title = trim($_POST['title'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $room = trim($_POST['room'] ?? '');
    $equipment = trim($_POST['equipment'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $scheduled_time = trim($_POST['scheduled_time'] ?? '');
    $details = trim($_POST['details'] ?? '');

    if (!empty($event_date)) {
        $event_ts = strtotime($event_date);
        if ($event_ts) {
            $month = intval(date('m', $event_ts));
            $year = intval(date('Y', $event_ts));
        }
    }

    // Get user's fullname or username for created_by
    $u_res = $conn->query("SELECT fullname FROM users WHERE id = {$user_id}");
    $created_by = 'User Request';
    if ($u_res && $u_row = $u_res->fetch_assoc()) {
        $created_by = $u_row['fullname'];
    }

    $msg = '';
    $success = false;

    if (!empty($title) && !empty($event_date)) {
        $stmt_req = $conn->prepare("INSERT INTO calendar_schedules (user_id, title, department, room, equipment, event_date, scheduled_time, details, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
        $stmt_req->bind_param("issssssss", $user_id, $title, $department, $room, $equipment, $event_date, $scheduled_time, $details, $created_by);
        if ($stmt_req->execute()) {
            $msg = "Matagumpay na naisumite ang iyong schedule request! Hinihintay ang pag-aprubah ng Admin.";
            $success = true;
            $_SESSION['schedule_msg'] = $msg;
            $_SESSION['schedule_msg_type'] = "success";
        } else {
            $msg = "Nabigong isumite ang schedule request.";
            $_SESSION['schedule_msg'] = $msg;
            $_SESSION['schedule_msg_type'] = "danger";
        }
    } else {
        $msg = "Paki-punan ang Pamagat (Title) at Petsa (Date).";
        $_SESSION['schedule_msg'] = $msg;
        $_SESSION['schedule_msg_type'] = "warning";
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $msg, 'month' => $month, 'year' => $year]);
        exit;
    } else {
        header("Location: user_schedule.php?month={$month}&year={$year}");
        exit;
    }
}

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$first_day_timestamp = strtotime("$year-$month-01");
$days_in_month = date('t', $first_day_timestamp);
// 1 (for Monday) through 7 (for Sunday)
$first_day_of_week = date('N', $first_day_timestamp);

$month_name = date('F', $first_day_timestamp);

// Previous and Next Month Links
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

// Fetch Admin Calendar Schedules for this month
$start_date_str = sprintf('%04d-%02d-01', $year, $month);
$end_date_str = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

$admin_schedules = [];
$cal_stmt = $conn->prepare("SELECT * FROM calendar_schedules WHERE event_date BETWEEN ? AND ? ORDER BY id ASC");
if ($cal_stmt) {
    $cal_stmt->bind_param("ss", $start_date_str, $end_date_str);
    $cal_stmt->execute();
    $res = $cal_stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $status = $row['status'] ?? 'Approved';
        $row_user_id = intval($row['user_id'] ?? 0);

        // Show Approved schedules to everyone, or Pending/Rejected if owned by current user
        if ($status === 'Approved' || ($row_user_id === $user_id)) {
            $day_num = intval(date('j', strtotime($row['event_date'])));
            $admin_schedules[$day_num][] = $row;
        }
    }
}

// Fetch user's own requests/orders schedules for list view and calendar display
$user_schedules = [];

// 1. Document printing schedules (all users)
$prt_res = $conn->query("
    SELECT request_group_id, requisitioner_name, department, date_needed, scheduled_time, paper_size, print_color, total_price, status
    FROM document_printing_requests
    WHERE date_needed IS NOT NULL
");
if ($prt_res) {
    while ($r = $prt_res->fetch_assoc()) {
        $user_schedules[] = [
            'date' => $r['date_needed'],
            'time' => $r['scheduled_time'] ?? '09:00 AM - 10:00 AM',
            'type' => 'Document Printing Pickup',
            'badge' => 'bg-info text-white',
            'id' => $r['request_group_id'],
            'requisitioner' => $r['requisitioner_name'] . ' (' . $r['department'] . ')',
            'items' => 'Doc Print (' . $r['paper_size'] . ', ' . $r['print_color'] . ') - ₱' . number_format($r['total_price'], 2),
            'status' => $r['status']
        ];
    }
}


// Organize user personal schedules by day number for current month
$user_month_schedules = [];
foreach ($user_schedules as $us) {
    $us_time = strtotime($us['date']);
    if (date('Y', $us_time) == $year && date('m', $us_time) == $month) {
        $day_num = intval(date('j', $us_time));
        $user_month_schedules[$day_num][] = $us;
    }
}

// Combine all day events into a consolidated structure for breakdown and modal preview
$day_events_map = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $day_events_map[$d] = [];
    if (isset($admin_schedules[$d])) {
        foreach ($admin_schedules[$d] as $as) {
            $dept_room_eq = trim(($as['department'] ? $as['department'] : '') . ($as['room'] ? ' (' . $as['room'] . ')' : '') . ($as['equipment'] ? ' [' . $as['equipment'] . ']' : ''));
            $st = $as['status'] ?? 'Approved';
            $category_title = ($st === 'Approved') ? 'Whiteboard Schedule' : "Schedule ($st)";
            $day_events_map[$d][] = [
                'category' => $category_title,
                'title' => ($dept_room_eq ? '[' . $dept_room_eq . '] ' : '') . $as['title'],
                'time' => $as['scheduled_time'] ?? 'All Day',
                'details' => $as['details'] ?? '',
                'requisitioner' => $dept_room_eq ?: 'Schedule',
                'room' => $as['room'] ?? '',
                'equipment' => $as['equipment'] ?? '',
                'status' => $st,
                'badge' => ($st === 'Approved') ? 'bg-danger text-white' : (($st === 'Rejected') ? 'bg-secondary text-white' : 'bg-warning text-dark'),
                'is_admin' => true
            ];
        }
    }
    if (isset($user_month_schedules[$d])) {
        foreach ($user_month_schedules[$d] as $us) {
            $day_events_map[$d][] = [
                'category' => $us['type'],
                'title' => $us['items'],
                'time' => $us['time'],
                'details' => 'Order #' . $us['id'] . ' (' . $us['status'] . ')',
                'requisitioner' => $us['requisitioner'] ?? 'N/A',
                'badge' => 'bg-primary text-white',
                'is_admin' => false
            ];
        }
    }
}

// Sort user schedules for list view
usort($user_schedules, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Live JSON endpoint for background polling
if (isset($_GET['fetch_live_data']) && $_GET['fetch_live_data'] == '1') {
    header('Content-Type: application/json');

    $html_schedules_table = '';
    if (!empty($user_schedules)) {
        foreach ($user_schedules as $sched) {
            $st = $sched['status'];
            $stClass = ($st == 'Approved' || $st == 'Returned' || $st == 'Completed') ? 'bg-success text-white' : (($st == 'Rejected') ? 'bg-danger text-white' : 'bg-warning text-dark');
            $html_schedules_table .= '<tr>';
            $html_schedules_table .= '<td class="fw-bold text-primary text-nowrap"><i class="bi bi-calendar-check me-1"></i>' . htmlspecialchars($sched['date']) . '</td>';
            $html_schedules_table .= '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($sched['time']) . '</span></td>';
            $html_schedules_table .= '<td><span class="badge ' . $sched['badge'] . ' fw-bold">' . htmlspecialchars($sched['type']) . '</span></td>';
            $html_schedules_table .= '<td class="fw-bold text-logo-blue">#' . htmlspecialchars($sched['id']) . '</td>';
            $html_schedules_table .= '<td><span class="badge bg-light text-dark border fw-semibold">' . htmlspecialchars($sched['requisitioner'] ?? 'N/A') . '</span></td>';
            $html_schedules_table .= '<td class="small text-dark fw-semibold">' . htmlspecialchars($sched['items']) . '</td>';
            $html_schedules_table .= '<td><span class="badge rounded-pill px-3 py-1 ' . $stClass . '">' . $st . '</span></td>';
            $html_schedules_table .= '</tr>';
        }
    } else {
        $html_schedules_table = '<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-calendar-x fs-2 d-block mb-1 text-secondary"></i>Walang nakaiskedyul na mga gawain sa kasalukuyan.</td></tr>';
    }

    echo json_encode([
        'month' => $month,
        'year' => $year,
        'first_day_of_week' => $first_day_of_week,
        'days_in_month' => $days_in_month,
        'day_events_map' => $day_events_map,
        'table_html' => $html_schedules_table,
        'total_schedules' => count($user_schedules)
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Calendar - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Permanent+Marker&family=Caveat:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/request_history.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <style>
        /* Whiteboard Calendar Styling */
        .whiteboard-frame {
            background: #d8dee8;
            border: 12px solid #a3b1c6;
            border-radius: 12px;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.2), 0 10px 25px rgba(0,0,0,0.25);
            padding: 15px;
            position: relative;
        }

        .whiteboard-surface {
            background-color: #fdfcf7;
            background-image:
                radial-gradient(#e2decb 0.5px, transparent 0.5px),
                linear-gradient(to bottom, rgba(255,255,255,0.8), rgba(240,235,220,0.6));
            background-size: 10px 10px, 100% 100%;
            border: 3px solid #4a5568;
            border-radius: 4px;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.05);
            padding: 10px;
            font-family: 'Arial', sans-serif;
        }

        .whiteboard-header-title {
            font-family: 'Permanent Marker', cursive;
            color: #b91c1c;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 1.8rem;
            text-shadow: 1px 1px 0px rgba(0,0,0,0.1);
        }

        .whiteboard-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border: 2px solid #333;
            background-color: #333;
            gap: 2px;
        }

        .whiteboard-day-header {
            background: #eae5d9;
            color: #111;
            font-family: 'Permanent Marker', cursive;
            font-size: 1.1rem;
            text-align: center;
            padding: 8px 2px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .whiteboard-cell {
            background: #fcfbfa;
            min-height: 125px;
            padding: 6px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            overflow: hidden;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.02);
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .whiteboard-cell:hover {
            background-color: #f7f4e9;
        }

        .whiteboard-cell.empty {
            background: #f3efe6;
            opacity: 0.6;
            cursor: default;
        }

        .whiteboard-date-num {
            font-family: 'Permanent Marker', cursive;
            color: #dc2626; /* Marker red */
            font-size: 1.35rem;
            line-height: 1;
            margin-bottom: 6px;
        }

        .marker-badge-red {
            font-family: 'Permanent Marker', 'Caveat', cursive;
            color: #b91c1c;
            background: rgba(220, 38, 38, 0.08);
            border-left: 3px solid #dc2626;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 0.88rem;
            line-height: 1.2;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .marker-badge-blue {
            font-family: 'Permanent Marker', 'Caveat', cursive;
            color: #1d4ed8;
            background: rgba(29, 78, 216, 0.08);
            border-left: 3px solid #2563eb;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 0.85rem;
            line-height: 1.2;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .more-schedules-badge {
            font-size: 0.72rem;
            font-weight: 700;
            color: #1b4f9c;
            background: #e0e7ff;
            border-radius: 12px;
            padding: 2px 8px;
            display: inline-block;
            margin-top: auto;
            text-align: center;
            align-self: flex-start;
        }

        .whiteboard-cell .slash-mark {
            font-family: 'Permanent Marker', cursive;
            color: #c51d1d;
            font-size: 1.6rem;
            text-align: center;
            line-height: 1;
            margin-top: 10px;
            opacity: 0.85;
        }

        @media (max-width: 768px) {
            .whiteboard-cell {
                min-height: 90px;
                padding: 3px;
            }
            .whiteboard-date-num {
                font-size: 1rem;
            }
            .marker-badge-red, .marker-badge-blue {
                font-size: 0.75rem;
                padding: 2px 4px;
            }
            .whiteboard-day-header {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-history sticky-top shadow-sm" style="background-color: #1b4f9c;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="<?= (strtolower($_SESSION['role'] ?? '') === 'admin') ? 'admin_dashboard.php' : 'user_dashboard.php' ?>">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white me-2" style="width: 38px;">
            <div class="lh-1">
                <span class="fs-5 d-block">SIBTECH SCHEDULE & CALENDAR</span>
                <small class="fw-light text-white-50" style="font-size: 0.72rem;">Timeline & System Schedule</small>
            </div>
        </a>
        <div class="d-flex align-items-center">
            <?php if (strtolower($_SESSION['role'] ?? '') === 'admin'): ?>
                <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-speedometer2 me-1"></i> Admin Dashboard
                </a>
            <?php else: ?>
                <a href="user_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-grid-fill me-1"></i> Supply Store
                </a>
                <a href="borrow_items.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-hand-holding-box me-1"></i> Borrow Items
                </a>
                <a href="request_history.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-bag-check-fill me-1"></i> My Requests
                </a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 1100px;">
    <?php if (isset($_SESSION['schedule_msg'])): ?>
        <div class="alert alert-<?= $_SESSION['schedule_msg_type'] ?? 'info' ?> alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <?= htmlspecialchars($_SESSION['schedule_msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['schedule_msg'], $_SESSION['schedule_msg_type']); ?>
    <?php endif; ?>

    <!-- User Schedule Request Form Card -->
    <div class="card p-4 border-0 shadow-sm rounded-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-calendar-plus-fill text-primary me-2"></i>Magdagdag ng Bagong Whiteboard Schedule (Request)</h5>
            <span class="badge bg-light text-primary border rounded-pill px-3 py-2 fw-semibold"><i class="bi bi-info-circle me-1"></i>Para sa pag-apruba ng Admin</span>
        </div>
        <form method="POST" action="" class="ajax-schedule-form bg-light p-3 rounded-3 border">
            <input type="hidden" name="action_request_schedule" value="1">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary">Department / Subject Code</label>
                    <input type="text" name="department" class="form-control form-control-sm" placeholder="e.g., CRIM, HM, CBA, SAD">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary">Room / Venue</label>
                    <input type="text" name="room" class="form-control form-control-sm" placeholder="e.g., Room 101, Lab A, Gym">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary">Equipment / Gamit</label>
                    <input type="text" name="equipment" class="form-control form-control-sm" placeholder="e.g., Projector, Mic, Extension">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary">Title / Activity *</label>
                    <input type="text" name="title" class="form-control form-control-sm" placeholder="e.g., Class Schedule" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary">Event Date *</label>
                    <input type="date" name="event_date" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary">Time Slot</label>
                    <input type="text" name="scheduled_time" class="form-control form-control-sm" placeholder="e.g., 7:00 AM - 12:00 PM">
                </div>
                <div class="col-md-12 d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-sm btn-logo-primary rounded-pill px-4 fw-bold">
                        <i class="bi bi-plus-circle me-1"></i> Add Schedule
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Navigation Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-calendar3 text-primary me-2"></i>Iskedyul at Kalendaryo (Schedule & Calendar)</h4>
            <p class="text-muted small mb-0">Tingnan ang opisyal na whiteboard schedule mula sa Admin pati na rin ang iyong nakaiskedyul na mga pickup at hiram na gamit. I-click ang petsa para sa buong breakdown.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-chevron-left me-1"></i> Prev
            </a>
            <span class="fw-bold fs-5 px-2 text-primary"><?= $month_name ?> <?= $year ?></span>
            <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                Next <i class="bi bi-chevron-right ms-1"></i>
            </a>
        </div>
    </div>

    <!-- Whiteboard Schedule Calendar Frame -->
    <div class="whiteboard-frame mb-5">
        <div class="whiteboard-surface">
            <!-- Whiteboard Title Banner -->
            <div class="d-flex justify-content-between align-items-center mb-3 px-2">
                <div class="whiteboard-header-title">
                    <i class="bi bi-pen-fill me-2"></i>SCHEDULE BOARD - <?= strtoupper($month_name) ?> <?= $year ?>
                </div>
                <div class="text-end">
                    <span class="badge bg-danger text-white rounded-pill px-3 py-1 me-1"><i class="bi bi-circle-fill me-1 fs-6"></i> Admin Postings</span>
                    <span class="badge bg-primary text-white rounded-pill px-3 py-1"><i class="bi bi-circle-fill me-1 fs-6"></i> My Scheduled Orders</span>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="whiteboard-grid">
                <!-- Day Columns -->
                <div class="whiteboard-day-header">MONDAY</div>
                <div class="whiteboard-day-header">Tuesday</div>
                <div class="whiteboard-day-header">Wednesday</div>
                <div class="whiteboard-day-header">Thursday</div>
                <div class="whiteboard-day-header">Friday</div>
                <div class="whiteboard-day-header">Saturday</div>
                <div class="whiteboard-day-header">Sunday</div>

                <?php
                // Empty cells before first day of month
                for ($i = 1; $i < $first_day_of_week; $i++) {
                    echo '<div class="whiteboard-cell empty"></div>';
                }

                // Days of current month
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $day_events = $day_events_map[$day] ?? [];
                    $total_events = count($day_events);
                    $date_formatted = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    ?>
                    <div class="whiteboard-cell" onclick="openDayBreakdown('<?= $date_formatted ?>', <?= htmlspecialchars(json_encode($day_events), ENT_QUOTES) ?>)">
                        <div class="whiteboard-date-num"><?= $day ?></div>

                        <?php
                        $display_limit = 2;
                        $count = 0;
                        foreach ($day_events as $ev) {
                            if ($count >= $display_limit) break;
                            if ($ev['is_admin']) {
                                echo '<div class="marker-badge-red" title="' . htmlspecialchars($ev['title']) . '">';
                                echo '<i class="bi bi-pin-fill me-1"></i>' . htmlspecialchars($ev['title']);
                                echo '</div>';
                            } else {
                                echo '<div class="marker-badge-blue" title="' . htmlspecialchars($ev['title']) . '">';
                                echo '<i class="bi bi-clock me-1"></i>' . htmlspecialchars($ev['category']) . ': ' . htmlspecialchars($ev['time']);
                                echo '</div>';
                            }
                            $count++;
                        }

                        if ($total_events > $display_limit) {
                            $more_count = $total_events - $display_limit;
                            echo '<span class="more-schedules-badge">+ ' . $more_count . ' higit pa</span>';
                        }

                        $current_col = ($first_day_of_week + $day - 2) % 7 + 1; // 1=Mon, 7=Sun
                        if ($total_events == 0 && ($current_col == 7)) {
                            echo '<div class="slash-mark">///</div>';
                        }
                        ?>
                    </div>
                <?php } ?>

                <?php
                // Fill trailing empty cells to complete the grid week
                $total_cells = ($first_day_of_week - 1) + $days_in_month;
                $remaining_cells = (7 - ($total_cells % 7)) % 7;
                for ($i = 0; $i < $remaining_cells; $i++) {
                    echo '<div class="whiteboard-cell empty"></div>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- All Scheduled Events Table Breakdown -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark d-flex align-items-center"><i class="bi bi-clock-history me-2 text-primary"></i>Buong Listahan ng Lahat ng Nakaiskedyul na Pickup, Event & Deadlines</h6>
            <span class="badge bg-secondary" id="total-schedules-badge"><?= count($user_schedules) ?> Total Items</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Scheduled Date</th>
                            <th>Time Slot</th>
                            <th>Event / Request Type</th>
                            <th>Order ID</th>
                            <th>Requisitioner & Dept</th>
                            <th>Details / Items</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="user-schedules-tbody">
                        <?php if (!empty($user_schedules)): ?>
                            <?php foreach ($user_schedules as $sched): ?>
                                <tr>
                                    <td class="fw-bold text-primary text-nowrap"><i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars($sched['date']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($sched['time']) ?></span></td>
                                    <td><span class="badge <?= $sched['badge'] ?> fw-bold"><?= htmlspecialchars($sched['type']) ?></span></td>
                                    <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($sched['id']) ?></td>
                                    <td><span class="badge bg-light text-dark border fw-semibold"><?= htmlspecialchars($sched['requisitioner'] ?? 'N/A') ?></span></td>
                                    <td class="small text-dark fw-semibold"><?= htmlspecialchars($sched['items']) ?></td>
                                    <td>
                                        <?php
                                        $st = $sched['status'];
                                        $stClass = ($st == 'Approved' || $st == 'Returned' || $st == 'Completed') ? 'bg-success text-white' : (($st == 'Rejected') ? 'bg-danger text-white' : 'bg-warning text-dark');
                                        ?>
                                        <span class="badge rounded-pill px-3 py-1 <?= $stClass ?>"><?= $st ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-x fs-2 d-block mb-1 text-secondary"></i>
                                    Walang nakaiskedyul na mga gawain sa kasalukuyan.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Daily Schedule Breakdown -->
<div class="modal fade" id="dayBreakdownModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white" style="background-color: #1b4f9c;">
                <h5 class="modal-title fw-bold" id="modalDateTitle"><i class="bi bi-calendar-event me-2"></i>Daily Schedule Breakdown</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="modalEventsContent">
                <!-- Populated via JavaScript -->
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill btn-sm px-4 fw-bold" data-bs-dismiss="modal">Isara</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentMonth = <?= $month ?>;
let currentYear = <?= $year ?>;
let firstDayOfWeek = <?= $first_day_of_week ?>;
let daysInMonth = <?= $days_in_month ?>;
let dayEventsMap = <?= json_encode($day_events_map) ?>;

function renderWhiteboardGrid(map, fdow, dim, month, year) {
    let grid = $('.whiteboard-grid');
    grid.find('.whiteboard-cell').remove();

    // Empty cells before first day of month
    for (let i = 1; i < fdow; i++) {
        grid.append('<div class="whiteboard-cell empty"></div>');
    }

    for (let day = 1; day <= dim; day++) {
        let events = map[day] || [];
        let totalEvents = events.length;
        let dateFormatted = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');

        let cell = $('<div class="whiteboard-cell"></div>');
        cell.attr('onclick', `openDayBreakdown('${dateFormatted}', ${JSON.stringify(events).replace(/"/g, '&quot;')})`);

        cell.append(`<div class="whiteboard-date-num">${day}</div>`);

        let displayLimit = 2;
        let count = 0;
        events.forEach(function(ev) {
            if (count >= displayLimit) return;
            if (ev.is_admin) {
                let badge = $('<div class="marker-badge-red"></div>')
                    .attr('title', ev.title)
                    .html(`<i class="bi bi-pin-fill me-1"></i>${escapeHtml(ev.title)}`);
                cell.append(badge);
            } else {
                let badge = $('<div class="marker-badge-blue"></div>')
                    .attr('title', ev.title)
                    .html(`<i class="bi bi-clock me-1"></i>${escapeHtml(ev.category)}: ${escapeHtml(ev.time)}`);
                cell.append(badge);
            }
            count++;
        });

        if (totalEvents > displayLimit) {
            let moreCount = totalEvents - displayLimit;
            cell.append(`<span class="more-schedules-badge">+ ${moreCount} higit pa</span>`);
        }

        let currentCol = (fdow + day - 2) % 7 + 1;
        if (totalEvents == 0 && currentCol == 7) {
            cell.append('<div class="slash-mark">///</div>');
        }

        grid.append(cell);
    }

    let totalCells = (fdow - 1) + dim;
    let remainingCells = (7 - (totalCells % 7)) % 7;
    for (let i = 0; i < remainingCells; i++) {
        grid.append('<div class="whiteboard-cell empty"></div>');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function pollUserSchedule() {
    $.ajax({
        url: window.location.pathname + `?fetch_live_data=1&month=${currentMonth}&year=${currentYear}`,
        type: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        success: function(data) {
            if (data && data.day_events_map) {
                dayEventsMap = data.day_events_map;
                renderWhiteboardGrid(data.day_events_map, data.first_day_of_week, data.days_in_month, data.month, data.year);
                if (data.table_html) {
                    $('#user-schedules-tbody').html(data.table_html);
                }
                if (typeof data.total_schedules !== 'undefined') {
                    $('#total-schedules-badge').text(data.total_schedules + ' Total Items');
                }
            }
        }
    });
}

$(document).on('submit', '.ajax-schedule-form', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: formData,
        dataType: 'json',
        processData: false,
        contentType: false,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        success: function(response) {
            if (response && response.success) {
                form.reset();
                if (response.month && response.year) {
                    currentMonth = response.month;
                    currentYear = response.year;
                }
                alert(response.message || 'Schedule request submitted successfully!');
                pollUserSchedule();
            } else {
                alert(response.message || 'Failed to submit schedule request.');
            }
        },
        error: function() {
            alert('An error occurred while submitting schedule request.');
        }
    });
});

setInterval(pollUserSchedule, 3000);

function openDayBreakdown(dateStr, events) {
    document.getElementById('modalDateTitle').innerHTML = '<i class="bi bi-calendar-event me-2"></i>Schedule Breakdown para sa ' + dateStr;
    var container = document.getElementById('modalEventsContent');

    if (!events || events.length === 0) {
        container.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-calendar-x fs-1 text-secondary d-block mb-2"></i>
                <p class="mb-0 fw-semibold">Walang nakaiskedyul na admin posting o order sa petsang ito.</p>
            </div>
        `;
    } else {
        var html = '<div class="list-group list-group-flush border-0">';
        events.forEach(function(ev) {
            var iconClass = ev.is_admin ? 'bi-pin-angle-fill text-danger' : 'bi-clock-history text-primary';
            var bgBadge = ev.is_admin ? 'bg-danger' : 'bg-primary';
            var roomInfo = ev.room ? `<span class="badge bg-info text-white ms-2"><i class="bi bi-door-open-fill me-1"></i>${ev.room}</span>` : '';
            var equipInfo = ev.equipment ? `<span class="badge bg-warning text-dark ms-2"><i class="bi bi-tools me-1"></i>${ev.equipment}</span>` : '';

            html += `
                <div class="list-group-item p-3 mb-2 rounded-3 border bg-light shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <span class="badge ${bgBadge} rounded-pill px-3 py-1 fw-bold">
                                <i class="bi ${iconClass} text-white me-1"></i>${ev.category}
                            </span>
                            ${roomInfo}
                            ${equipInfo}
                        </div>
                        <span class="badge bg-white text-dark border px-3 py-1 fw-bold"><i class="bi bi-clock me-1 text-primary"></i>${ev.time}</span>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">${ev.title}</h6>
                    <p class="small text-secondary mb-1"><strong>Requisitioner / Dept / Room:</strong> ${ev.requisitioner}</p>
                    ${ev.details ? `<p class="small text-muted mb-0"><strong>Details:</strong> ${ev.details}</p>` : ''}
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    var modal = new bootstrap.Modal(document.getElementById('dayBreakdownModal'));
    modal.show();
}
</script>
</body>
</html>